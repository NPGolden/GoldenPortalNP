<?php
/**
 * Sales pipeline for offers — hybrid model (GAS-CP-R75-001).
 *   Stages  = the offer statuses with behaviour "open" (built-in: sent → received → evaluation → negotiation → verbal_ok; expired closes),
 *             each with a base probability (Settings › Offer statuses, offer_status_prob).
 *   Signals = a short list of facts set on the offer that move the probability (± points): budget confirmed, decision maker met,
 *             competitor in play, no reply for 14 days (automatic), validity expired (automatic). Table offer_signals.
 *   Success % = clamp(base + Σ signals, 0, 95) while open; 100 once approved; 0 when lost.
 *   Follow-up = next action (type, date, owner) kept on the offer, mandatory while a stage is open; the default cadence is set on send;
 *             the daily house-keeping reminds the owner, copies the sales managers when 3 days overdue, auto-expires on validity.
 */

const PP_SIGNALS = ['budget_confirmed' => 10, 'decision_maker_met' => 10, 'competitor' => -15, 'no_reply' => -10, 'validity_expired' => -20];
const PP_AUTO_SIGNALS = ['no_reply', 'validity_expired'];
const PP_ACTIONS = ['confirm', 'call', 'email', 'meeting', 'visit', 'revision', 'closing'];
const PP_CADENCE = [['confirm', 2], ['call', 7], ['meeting', 14], ['closing', 21]];   // [type, days after sent]
const PP_STAGE_DAYS = ['sent' => 7, 'received' => 10, 'evaluation' => 14, 'negotiation' => 14, 'verbal_ok' => 10];   // expected days in stage before it turns red

function pp_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM offers LIKE 'next_action_at'") && (bool) scalar("SHOW TABLES LIKE 'offer_signals'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pp_can(): bool { return pp_has() && is_staff() && function_exists('of_visible') && of_visible(); }
/** Open = a stage before the decision (behaviour "open", not draft). */
function pp_is_open(string $status): bool { return $status !== 'draft' && of_status_behaves($status) === 'open'; }
function pp_open_statuses(): array { return array_values(array_filter(array_keys(of_status_defs(true)), fn($k) => pp_is_open($k))); }

/* ------------------------------------------------------------------ signals + probability */
function pp_signals(int $offerId): array { if (!pp_has()) return []; $out = []; foreach (all('SELECT * FROM offer_signals WHERE offer_id = ?', [$offerId]) as $r) $out[$r['skey']] = $r; return $out; }
function pp_signal_set(int $offerId, string $key, bool $on, ?string $note = null, bool $auto = false): void {
    if (!isset(PP_SIGNALS[$key])) throw new RuntimeException(t('err.notfound'));
    $u = current_user(); $has = (bool) scalar('SELECT COUNT(*) FROM offer_signals WHERE offer_id = ? AND skey = ?', [$offerId, $key]);
    if ($on && !$has) { q('INSERT INTO offer_signals (offer_id, skey, delta, set_by, set_name, note, is_auto) VALUES (?,?,?,?,?,?,?)', [$offerId, $key, PP_SIGNALS[$key], (int)($u['id'] ?? 0) ?: null, $auto ? 'portal' : ($u['full_name'] ?? null), $note, $auto ? 1 : 0]); of_event($offerId, 'signal_on', t('pp.sig_' . $key) . ($note ? ' — ' . $note : '')); }
    elseif (!$on && $has) { q('DELETE FROM offer_signals WHERE offer_id = ? AND skey = ?', [$offerId, $key]); of_event($offerId, 'signal_off', t('pp.sig_' . $key)); }
}
/** Base % of the stage (Settings › Offer statuses) with the built-in defaults of the hybrid model. */
function pp_base(string $status): int {
    $p = function_exists('sl_prob') ? sl_prob() : []; if (isset($p[$status])) return (int)$p[$status];
    $def = ['draft' => 5, 'sent' => 15, 'received' => 30, 'evaluation' => 45, 'negotiation' => 60, 'verbal_ok' => 85, 'expired' => 0];
    if (isset($def[$status])) return $def[$status];
    $b = of_status_behaves($status); return $b === 'open' ? 30 : (in_array($b, ['approved', 'closed_won'], true) ? 100 : 0);
}
/** Success % of one offer = base of the stage ± signals (open), 100 approved, 0 lost. $signals: preloaded map or null. */
function pp_prob(array $o, ?array $signals = null): int {
    $b = of_status_behaves((string)$o['status']);
    if (in_array($b, ['approved', 'closed_won'], true)) return 100; if ($b === 'closed_lost') return 0;
    $base = pp_base((string)$o['status']); if (!pp_has() || $o['status'] === 'draft') return $base;
    $sig = $signals ?? pp_signals((int)$o['id']); $sum = 0; foreach ($sig as $k => $s) $sum += (int)($s['delta'] ?? (PP_SIGNALS[$k] ?? 0));
    return max(0, min(95, $base + $sum));
}
function pp_prob_pill(int $pct): string { return pill($pct >= 70 ? 'green' : ($pct >= 40 ? 'gold' : 'mute'), $pct . ' %'); }
/** Signals of many offers in one query: [offer_id => [skey => row]]. */
function pp_signals_bulk(array $ids): array { $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids || !pp_has()) return []; $out = []; foreach (all('SELECT * FROM offer_signals WHERE offer_id IN (' . implode(',', $ids) . ')') as $r) $out[(int)$r['offer_id']][$r['skey']] = $r; return $out; }

/* ------------------------------------------------------------------ next action */
function pp_action_label(string $k): string { return in_array($k, PP_ACTIONS, true) ? t('pp.act_' . $k) : $k; }
function pp_set_action(int $offerId, ?string $type, ?string $date, ?int $userId, ?string $note = null, bool $silent = false): void {
    if (!pp_has()) return; $type = in_array((string)$type, PP_ACTIONS, true) ? $type : null; $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date) ? $date : null;
    if ($type && !$date) throw new RuntimeException(t('pp.need_date'));
    q('UPDATE offers SET next_action_type = ?, next_action_at = ?, next_action_user_id = ?, next_action_note = ? WHERE id = ?', [$type, $date, $userId ?: null, $note ? mb_substr($note, 0, 300) : null, $offerId]);
    if (!$silent && $type) of_event($offerId, 'followup_set', pp_action_label($type) . ' · ' . fmt_date($date) . ($note ? ' — ' . $note : ''));
}
/** Mark the current action done (event with the outcome) and clear it; the caller sets the next one. */
function pp_action_done(int $offerId, string $outcome): void {
    $o = one('SELECT next_action_type, next_action_at FROM offers WHERE id = ?', [$offerId]); if (!$o) throw new RuntimeException(t('err.notfound'));
    of_event($offerId, 'followup_done', ($o['next_action_type'] ? pp_action_label($o['next_action_type']) . ' · ' : '') . mb_substr(trim($outcome), 0, 1000));
    q('UPDATE offers SET next_action_type = NULL, next_action_at = NULL, next_action_note = NULL, last_contact_at = CURDATE() WHERE id = ?', [$offerId]);
}
/** Default cadence when an offer is sent: the first step (confirm receipt, D+2) becomes the next action; the rest is the plan shown on the offer. */
function pp_cadence_start(int $offerId, int $ownerId): void { if (!pp_has()) return; [$t, $d] = PP_CADENCE[0]; pp_set_action($offerId, $t, date('Y-m-d', strtotime("+{$d} days")), $ownerId, null, true); }
function pp_cadence_plan(array $o): array { $base = $o['sent_at'] ?: $o['status_at'] ?: date('Y-m-d'); $out = []; foreach (PP_CADENCE as [$t, $d]) $out[] = ['type' => $t, 'date' => date('Y-m-d', strtotime($base . " +{$d} days"))]; if (!empty($o['valid_until'])) $out[] = ['type' => 'closing', 'date' => date('Y-m-d', strtotime($o['valid_until'] . ' -7 days')), 'validity' => true]; return $out; }
function pp_overdue_days(array $o): ?int { if (empty($o['next_action_at'])) return null; return (int) floor((strtotime(date('Y-m-d')) - strtotime($o['next_action_at'])) / 86400); }
/** Who is responsible for the follow-up: the next-action owner, else the offer's creator. */
function pp_owner(array $o): ?int { return (int)($o['next_action_user_id'] ?? 0) ?: ((int)($o['created_by'] ?? 0) ?: null); }
/** Sales managers (copied on overdue follow-ups): administrators who see money + the Owner / CEO roles. */
function pp_managers(): array { try { return array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu LEFT JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (cu.role = 'admin' OR r.scope IN ('owner','full') OR LOWER(r.name) = 'ceo')"), 'id')); } catch (Throwable $e) { return []; } }

/* ------------------------------------------------------------------ pipeline read model */
/** Open offers with vessel / customer / owner / signals / success %, days in stage, overdue follow-up. */
function pp_open(array $f = []): array {
    if (!pp_has()) return [];
    [$scope, $args] = scope_sql('p'); $w = ["o.status IN ('" . implode("','", array_map(fn($s) => str_replace("'", '', $s), pp_open_statuses())) . "')", $scope];
    if (!empty($f['owner'])) { $w[] = 'COALESCE(o.next_action_user_id, o.created_by) = ?'; $args[] = (int)$f['owner']; }
    if (!empty($f['vessel'])) { $w[] = 'o.vessel_id = ?'; $args[] = (int)$f['vessel']; }
    $rows = all("SELECT o.*, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name, cu.full_name AS owner_name, cr.full_name AS creator_name
                 FROM offers o JOIN projects p ON p.id = o.project_id LEFT JOIN vessels v ON v.id = o.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
                 LEFT JOIN customer_users cu ON cu.id = o.next_action_user_id LEFT JOIN customer_users cr ON cr.id = o.created_by WHERE " . implode(' AND ', $w) . ' ORDER BY o.next_action_at IS NULL, o.next_action_at, o.sent_at', $args);
    $sig = pp_signals_bulk(array_column($rows, 'id')); $today = date('Y-m-d');
    foreach ($rows as &$o) { $o['signals'] = $sig[(int)$o['id']] ?? []; $o['prob'] = pp_prob($o, $o['signals']); $o['weighted'] = round((float)$o['amount'] * $o['prob'] / 100, 2);
        $o['days_in_stage'] = (int) floor((strtotime($today) - strtotime($o['status_at'] ?: $o['sent_at'] ?: $today)) / 86400); $o['stage_late'] = isset(PP_STAGE_DAYS[$o['status']]) && $o['days_in_stage'] > PP_STAGE_DAYS[$o['status']];
        $o['overdue'] = pp_overdue_days($o); $o['owner_label'] = (string)($o['owner_name'] ?: $o['creator_name'] ?: ''); $o['expiring'] = !empty($o['valid_until']) && $o['valid_until'] >= $today && (strtotime($o['valid_until']) - strtotime($today)) / 86400 <= 10; }
    unset($o); return $rows;
}
/** Stage-to-stage funnel for a year: how many offers (and value) reached each stage at least once (events status_<stage>, sent_at, decisions). */
function pp_funnel(int $year): array {
    $stages = pp_open_statuses(); $out = []; [$scope, $args] = scope_sql('p');
    $ids = all("SELECT o.id, o.amount, o.status FROM offers o JOIN projects p ON p.id = o.project_id WHERE YEAR(COALESCE(o.sent_at, o.created_at)) = ? AND o.status <> 'draft' AND {$scope}", array_merge([$year], $args));
    $byId = []; foreach ($ids as $r) $byId[(int)$r['id']] = $r; if (!$byId) return ['stages' => [], 'won' => ['n' => 0, 'amount' => 0], 'lost' => ['n' => 0, 'amount' => 0]];
    $reached = []; foreach ($stages as $s) $reached[$s] = [];
    foreach (all('SELECT offer_id, event FROM offer_events WHERE offer_id IN (' . implode(',', array_keys($byId)) . ") AND event LIKE 'status_%'") as $e) { $s = substr($e['event'], 7); if (isset($reached[$s])) $reached[$s][(int)$e['offer_id']] = true; }
    foreach ($byId as $id => $r) { if (isset($reached['sent'])) $reached['sent'][$id] = true; if (isset($reached[$r['status']])) $reached[$r['status']][$id] = true; }
    foreach ($stages as $s) { $n = 0; $a = 0.0; foreach (array_keys($reached[$s]) as $id) { $n++; $a += (float)$byId[$id]['amount']; } $out[] = ['stage' => $s, 'label' => of_status_label($s), 'n' => $n, 'amount' => $a]; }
    $won = ['n' => 0, 'amount' => 0.0]; $lost = ['n' => 0, 'amount' => 0.0];
    foreach ($byId as $r) { $b = of_status_behaves((string)$r['status']); if (in_array($b, ['approved', 'closed_won'], true)) { $won['n']++; $won['amount'] += (float)$r['amount']; } elseif ($b === 'closed_lost') { $lost['n']++; $lost['amount'] += (float)$r['amount']; } }
    return ['stages' => $out, 'won' => $won, 'lost' => $lost, 'total' => count($byId)];
}

/* ------------------------------------------------------------------ daily house-keeping (called from hk_tick) */
function pp_daily(): string {
    if (!pp_has()) return 'pipeline off';
    $today = date('Y-m-d'); $n = ['expired' => 0, 'no_reply' => 0, 'reminders' => 0, 'escalated' => 0];
    $open = all("SELECT o.*, p.project_code FROM offers o JOIN projects p ON p.id = o.project_id WHERE o.status IN ('" . implode("','", pp_open_statuses()) . "')");
    foreach ($open as $o) { $id = (int)$o['id']; $sig = pp_signals($id);
        // validity passed → stage "expired" (closed-lost), unless a verbal OK is on record (then only the signal)
        if (!empty($o['valid_until']) && $o['valid_until'] < $today) {
            if ($o['status'] !== 'verbal_ok' && isset(of_status_defs(true)['expired'])) { q("UPDATE offers SET status = 'expired', status_at = CURDATE() WHERE id = ?", [$id]); of_event($id, 'status_expired', t('pp.auto_expired')); $n['expired']++; continue; }
            if (!isset($sig['validity_expired'])) { pp_signal_set($id, 'validity_expired', true, null, true); $n['no_reply']++; }
        }
        // no reply: nothing recorded on the offer for 14 days while waiting for the customer
        $last = (string) scalar('SELECT MAX(created_at) FROM offer_events WHERE offer_id = ?', [$id]); $lastD = max($last ? substr($last, 0, 10) : '', (string)($o['last_contact_at'] ?? ''), (string)($o['sent_at'] ?? ''));
        $quiet = $lastD !== '' && (strtotime($today) - strtotime($lastD)) / 86400 >= 14;
        if ($quiet && !isset($sig['no_reply'])) { pp_signal_set($id, 'no_reply', true, null, true); $n['no_reply']++; } elseif (!$quiet && isset($sig['no_reply']) && !empty($sig['no_reply']['is_auto'])) pp_signal_set($id, 'no_reply', false);
        // reminders: action due today / overdue → owner; overdue ≥ 3 days → managers too
        if (!empty($o['next_action_at']) && $o['next_action_at'] <= $today && function_exists('nf_push')) {
            $late = pp_overdue_days($o); $owner = pp_owner($o); $to = $owner ? [$owner] : []; if ($late >= 3) $to = array_values(array_unique(array_merge($to, pp_managers())));
            if ($to) { nf_push($to, 'of:followup', 'ofu:' . $id . ':' . $today, ($late > 0 ? t('pp.nf_overdue', ['d' => $late]) : t('pp.nf_due')) . ' — ' . $o['offer_no'] . ' ' . $o['revision'], pp_action_label((string)$o['next_action_type']) . ' · ' . $o['title'] . ($o['next_action_note'] ? ' — ' . $o['next_action_note'] : ''), url('offer', ['id' => $id]) . '#followup', null, null); $n['reminders']++; if ($late >= 3) $n['escalated']++; }
        }
        // validity in 7 days and no action planned → ask the owner to extend or close
        if (!empty($o['valid_until']) && empty($o['next_action_at']) && (strtotime($o['valid_until']) - strtotime($today)) / 86400 <= 7 && function_exists('nf_push')) { $owner = pp_owner($o); if ($owner) nf_push([$owner], 'of:followup', 'ofx:' . $id . ':' . $today, t('pp.nf_expiring', ['d' => fmt_date($o['valid_until'])]) . ' — ' . $o['offer_no'], (string)$o['title'], url('offer', ['id' => $id]) . '#followup', null, null); }
    }
    return 'pipeline expired ' . $n['expired'] . ' signals ' . $n['no_reply'] . ' reminders ' . $n['reminders'] . ' escalated ' . $n['escalated'];
}

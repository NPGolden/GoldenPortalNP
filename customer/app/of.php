<?php
/**
 * Offers — what Golden A/S offered the owner per project / sub-project:
 * status, customer amount, aging, online approval by the owner.   GAS-CP-R15-001
 * Statuses: sent → approved | rejected | cancelled; approved → in_progress → to_be_invoiced → finished.
 */

function of_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) db()->query("SHOW TABLES LIKE 'offers'")->fetch(); } catch (Throwable $e) { $has = false; } }
    return $has;
}
/** R31-006: may the signed-in person open Offers at all? Owners always; Golden A/S staff only when their role shows financial data. */
function of_visible(): bool { return !is_staff() || fin_visible(); }
/** Stops a page or action for people who may not see offers (flash + back to the dashboard). */
function of_require(): void { if (!of_visible()) { flash('bad', t('of.no_access')); redirect('dashboard'); } }
/** Drops Golden A/S users whose role hides financial data from a list of notification recipients (owners stay). */
function of_users(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids)))); if (!$ids) return [];
    try {
        if (!(bool) db()->query("SHOW COLUMNS FROM staff_roles LIKE 'see_financials'")->fetch()) return $ids;
        $in = implode(',', array_fill(0, count($ids), '?'));
        return array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu LEFT JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.id IN ($in) AND (cu.customer_id IS NOT NULL OR r.see_financials IS NULL OR r.see_financials = 1)", $ids), 'id'));
    } catch (Throwable $e) { return $ids; }
}
/* ---------------------------------------------- status registry (R17-008): the seven built-ins + the administrators' own */
function of_status_table(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) one("SHOW TABLES LIKE 'offer_statuses'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** All statuses, keyed by slug: label, tone, behaves (open | approved | closed_won | closed_lost), sort_order, is_builtin, is_active. */
function of_status_defs(bool $activeOnly = false): array {
    static $all = null;
    if ($all === null) {
        $all = [];
        $builtin = [['draft', 'mute', 'draft', 5], ['sent', 'gold', 'open', 10], ['rejected', 'bad', 'closed_lost', 20], ['cancelled', 'mute', 'closed_lost', 30], ['approved', 'green', 'approved', 40], ['in_progress', 'blue', 'approved', 50], ['to_be_invoiced', 'navy', 'approved', 60], ['finished', 'mute', 'closed_won', 70]];
        foreach ($builtin as [$k, $tone, $b, $o]) $all[$k] = ['skey' => $k, 'label' => t('of.st_' . $k), 'tone' => $tone, 'behaves' => $b, 'sort_order' => $o, 'is_builtin' => 1, 'is_active' => 1];
        if (of_status_table()) foreach (all('SELECT * FROM offer_statuses ORDER BY sort_order, label') as $r) {
            if (isset($all[$r['skey']])) { $all[$r['skey']]['sort_order'] = (int)$r['sort_order']; $all[$r['skey']]['is_active'] = (int)$r['is_active']; $all[$r['skey']]['tone'] = $r['tone'] ?: $all[$r['skey']]['tone']; }
            else $all[$r['skey']] = ['skey' => $r['skey'], 'label' => $r['label'], 'tone' => $r['tone'] ?: 'mute', 'behaves' => $r['behaves'], 'sort_order' => (int)$r['sort_order'], 'is_builtin' => 0, 'is_active' => (int)$r['is_active']];
        }
        uasort($all, fn($a, $b) => [$a['sort_order'], $a['label']] <=> [$b['sort_order'], $b['label']]);
    }
    return $activeOnly ? array_filter($all, fn($d) => $d['is_active']) : $all;
}
function of_statuses(): array { return array_keys(of_status_defs(true)); }
function of_status_label(string $s): string { $d = of_status_defs()[$s] ?? null; return $d ? $d['label'] : $s; }
function of_status_tone(string $s): string { return of_status_defs()[$s]['tone'] ?? 'mute'; }
function of_status_behaves(string $s): string { return of_status_defs()[$s]['behaves'] ?? 'open'; }
function of_statuses_where(array $behaves): array { return array_keys(array_filter(of_status_defs(), fn($d) => in_array($d['behaves'], $behaves, true))); }
/** Statuses that count as "open" (awaiting or in hand). */
function of_open_statuses(): array { return of_statuses_where(['open', 'approved']); }
function of_status_pill(string $s): string { return pill(of_status_tone($s), of_status_label($s)); }
/** Where an offer may go next from its current status (staff). Built-in flow as before; the administrators' own statuses are reachable from anywhere and lead anywhere. */
function of_next_statuses(array $o): array {
    $cur = $o['status'];
    $flow = ['draft' => ['cancelled'], 'sent' => ['cancelled'], 'approved' => ['in_progress', 'to_be_invoiced', 'cancelled'], 'in_progress' => ['to_be_invoiced', 'finished', 'cancelled'], 'to_be_invoiced' => ['finished', 'in_progress'], 'rejected' => ['sent'], 'cancelled' => ['sent'], 'finished' => ['to_be_invoiced']];
    $defs = of_status_defs(true); $isBuiltin = !empty($defs[$cur]['is_builtin']);
    $next = $isBuiltin ? ($flow[$cur] ?? []) : array_keys(array_filter($defs, fn($d) => $d['is_builtin'] && !in_array($d['skey'], ['approved', 'rejected'], true)));
    foreach ($defs as $k => $d) if (!$d['is_builtin'] && $k !== $cur) $next[] = $k;
    return array_values(array_unique(array_filter($next, fn($k) => isset($defs[$k]))));
}
function of_money(float $v, string $cur = 'EUR'): string { if (!fin_visible()) return fin_masked(); return number_format($v, 2, ',', '.') . ' ' . $cur; }
/** Days the offer has sat in its current status (for "sent": since it was sent). */
function of_age(array $o): ?int {
    $d = $o['status'] === 'sent' ? ($o['sent_at'] ?: $o['status_at']) : ($o['status_at'] ?: $o['sent_at']);
    if (!$d) $d = substr((string)$o['created_at'], 0, 10);
    return $d ? (int) floor((time() - strtotime($d)) / 86400) : null;
}
function of_age_label(array $o): string { $a = of_age($o); return $a === null ? '—' : ($a . ' ' . t('tk.d')); }
function of_age_tone(array $o): string { $a = of_age($o) ?? 0; if (!in_array($o['status'], ['sent', 'to_be_invoiced'], true) && of_status_behaves($o['status']) !== 'open') return 'mute'; return $a > 30 ? 'bad' : ($a > 14 ? 'gold' : 'green'); }
/** Default identity = the sub-project's: "<project code> <sub code>", else the project code. */
function of_default_no(array $p, ?array $s): string {
    $br = function_exists('br_offer_no') ? br_offer_no((int)$p['id'], $s ? (int)$s['id'] : null) : '';                          // R40-001 · portal series for every branch (GAS-OF-YYYY-NNN / GIT-OF-YYYY-NNN)
    return $br !== '' ? $br : trim(($p['project_code'] ?: $p['name']) . ($s ? ' ' . $s['code'] : ''));
}
/** Does the offers table carry the origin column (R40-001)? */
function of_has_origin(): bool { static $h = null; if ($h === null) { try { $h = of_has() && (bool) scalar("SHOW COLUMNS FROM offers LIKE 'origin'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** "ERP" pill for offers that came from the ERP import (their number is the SoftOne number); '' for portal offers. */
function of_origin_pill(array $o): string { return of_has_origin() && ($o['origin'] ?? 'portal') === 'erp' ? '<span class="pill pill--mute" title="' . e(t('of.erp_hint')) . '">ERP</span>' : ''; }

function of_event(int $id, string $event, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO offer_events (offer_id, event, user_id, user_name, note, ip) VALUES (?,?,?,?,?,?)', [$id, $event, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $note, tk_ip()]);
}
function of_load(int $id): ?array {
    if (!of_has()) return null;
    [$scope, $args] = scope_sql('p'); array_unshift($args, $id);
    $ob = function_exists('ob_has') && ob_has();
    $o = one("SELECT o.*, p.project_code, p.name AS project_name, p.customer_id, " . ($ob ? "COALESCE(o.vessel_id, p.vessel_id)" : "p.vessel_id") . " AS vessel_id, v.name AS vessel, c.name AS customer_name, s.code AS sub_code, s.name AS sub_name,
                     f.original_name, f.mime_type AS file_mime, f.size_bytes AS file_size" . ($ob ? ", tk.ticket_no" : ", NULL AS ticket_no") . "
              FROM offers o JOIN projects p ON p.id = o.project_id LEFT JOIN vessels v ON v.id = " . ($ob ? "COALESCE(o.vessel_id, p.vessel_id)" : "p.vessel_id") . " LEFT JOIN customers c ON c.id = p.customer_id
              LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN sp_files f ON f.id = o.file_id" . ($ob ? " LEFT JOIN tickets tk ON tk.id = o.ticket_id" : "") . " WHERE o.id = ? AND {$scope}", $args);
    if ($o && !is_staff() && $o['status'] === 'draft') return null;
    if ($o) $o['events'] = all('SELECT * FROM offer_events WHERE offer_id = ? ORDER BY id', [$id]);
    return $o;
}
function of_list(array $f = []): array {
    if (!of_has()) return [];
    [$scope, $args] = scope_sql('p'); $w = [$scope]; $ob = function_exists('ob_has') && ob_has(); $vcol = $ob ? 'COALESCE(o.vessel_id, p.vessel_id)' : 'p.vessel_id';
    foreach (['project' => 'o.project_id', 'sub' => 'o.subproject_id', 'vessel' => $vcol, 'customer' => 'p.customer_id'] + ($ob ? ['ticket' => 'o.ticket_id'] : []) as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $args[] = (int)$f[$k]; }
    if (!is_staff()) $w[] = "o.status <> 'draft'";
    if (!empty($f['status'])) { if ($f['status'] === 'open') { $ks = of_open_statuses(); $w[] = 'o.status IN (' . implode(',', array_fill(0, count($ks), '?')) . ')'; array_push($args, ...$ks); } elseif (isset(of_status_defs()[$f['status']])) { $w[] = 'o.status = ?'; $args[] = $f['status']; } }
    if (!empty($f['q'])) { $sq = function_exists('num_has') && num_has(); $w[] = '(o.offer_no LIKE ? OR o.title LIKE ? OR p.project_code LIKE ? OR v.name LIKE ?' . ($sq ? ' OR o.sq_no LIKE ?' : '') . ')'; $l = '%' . $f['q'] . '%'; array_push($args, $l, $l, $l, $l); if ($sq) $args[] = $l; }
    return all("SELECT o.*, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name, s.code AS sub_code, s.name AS sub_name
                FROM offers o JOIN projects p ON p.id = o.project_id LEFT JOIN vessels v ON v.id = {$vcol} LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN subprojects s ON s.id = o.subproject_id
                WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(o.status,'draft','sent','approved','in_progress','to_be_invoiced','rejected','cancelled','finished'), o.sent_at DESC, o.id DESC", $args);
}
/** Offers awaiting the owner's answer (customers: theirs; staff: all). */
function of_pending(): array { return of_list(['status' => 'sent']); }
/** Per-project roll-up for the project tree and the weekly report. */
function of_project_summary(int $pid): array {
    $s = ['n' => 0, 'sent' => 0, 'sent_amount' => 0.0, 'approved' => 0, 'approved_amount' => 0.0, 'oldest_sent' => 0, 'rows' => []];
    if (!of_has()) return $s;
    foreach (all('SELECT * FROM offers WHERE project_id = ? ORDER BY id', [$pid]) as $o) {
        $s['n']++; $s['rows'][] = $o;
        $b = of_status_behaves($o['status']);
        if ($b === 'open') { $s['sent']++; $s['sent_amount'] += (float)$o['amount']; $s['oldest_sent'] = max($s['oldest_sent'], of_age($o) ?? 0); }
        elseif (in_array($b, ['approved', 'closed_won'], true)) { $s['approved']++; $s['approved_amount'] += (float)$o['amount']; }
    }
    return $s;
}
function of_by_sub(int $pid): array { $m = []; if (of_has()) foreach (all('SELECT * FROM offers WHERE project_id = ? ORDER BY id', [$pid]) as $o) $m[(int)($o['subproject_id'] ?? 0)][] = $o; return $m; }
/** Compact pills for a sub-project row in the tree. */
function of_sub_pills(array $offers): string {
    $h = '';
    foreach ($offers as $o) $h .= '<span class="ofpill ofpill--' . e($o['status']) . ' ofpill--t-' . e(of_status_tone($o['status'])) . '" title="' . e($o['offer_no'] . ' ' . $o['revision'] . ' · ' . of_status_label($o['status']) . ' · ' . of_age_label($o)) . '">' . e(of_status_label($o['status'])) . ' · ' . e(of_money((float)$o['amount'], $o['currency'])) . (of_status_behaves($o['status']) === 'open' ? ' · ' . e(of_age_label($o)) : '') . '</span>';
    return $h;
}

/** "12.450,00" → 12450.00 · "12,450.00" → 12450.00 · "8900" → 8900 · "1.250" → 1250 · "1.25" → 1.25 */
function of_parse_amount(string $s): float {
    $s = preg_replace('/[^\d.,-]/', '', trim($s));
    $lastDot = strrpos($s, '.'); $lastComma = strrpos($s, ',');
    if ($lastDot !== false && $lastComma !== false) { $dec = $lastDot > $lastComma ? '.' : ','; $s = str_replace($dec === '.' ? ',' : '.', '', $s); $s = str_replace(',', '.', $s); }
    elseif ($lastComma !== false) { $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s); }   // one comma = decimal (European)
    elseif ($lastDot !== false) { if (substr_count($s, '.') > 1 || strlen($s) - $lastDot - 1 === 3) $s = str_replace('.', '', $s); }
    return round((float)$s, 2);
}
function of_save_from_post(int $id): int {
    require_staff(); $u = current_user();
    $pid = (int)($_POST['project_id'] ?? 0); $ob = function_exists('ob_has') && ob_has();
    $vid = $ob ? ((int)($_POST['vessel_id'] ?? 0) ?: null) : null;
    if (!$pid && $vid && $ob) $pid = ob_general_project($vid);                                                    // R33: "vessel in general" → the vessel's general project
    $p = one('SELECT * FROM projects WHERE id = ?', [$pid]); if (!$p) throw new RuntimeException(t('err.notfound'));
    if ($ob && !$vid) $vid = (int)($p['vessel_id'] ?? 0) ?: null;
    $tid = $ob ? ((int)($_POST['ticket_id'] ?? 0) ?: null) : null; if ($tid && !scalar('SELECT COUNT(*) FROM tickets WHERE id = ?', [$tid])) $tid = null;
    $layout = $ob && in_array($_POST['layout'] ?? '', OB_LAYOUTS, true) ? $_POST['layout'] : 'service';
    $vat = $ob ? max(0, min(100, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? '0')))) : 0; $vdays = $ob ? max(1, min(365, (int)($_POST['validity_days'] ?? 30))) : 30;
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    $sub = $sid ? one('SELECT * FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid]) : null; if ($sid && !$sub) $sid = null;
    $str = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $no = $str('offer_no', 60) ?: of_default_no($p, $sub);
    if ($ob && $str('offer_no', 60) === '' && (str_starts_with((string)$p['project_code'], 'GEN-') || !empty($_POST['as_draft']))) { $brNo = function_exists('br_offer_no') ? br_offer_no($pid, $sid) : ''; $no = $brNo !== '' ? $brNo : ob_offer_no($pid, $sid); }
    $title = $str('title', 200); if ($title === '') throw new RuntimeException(t('of.title_req'));
    $amount = fin_visible() ? of_parse_amount((string)($_POST['amount'] ?? '0')) : (float)($id ? (scalar('SELECT amount FROM offers WHERE id = ?', [$id]) ?? 0) : 0);
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR';
    $date = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $sent = $date('sent_at') ?: date('Y-m-d'); $valid = $date('valid_until');
    $rev = $str('revision', 8) ?: 'R0'; $notes = $str('notes', 4000) ?: null;
    $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], $pid, $sid, 'offer', 'external', $no . ' ' . $rev, null, null, ['doc_no' => $no, 'revision' => $rev, 'label' => (string)($_POST['title'] ?? '')]) : null;
    if ($id) {
        $o = of_load($id); if (!$o) throw new RuntimeException(t('err.notfound'));
        $hasLines = $ob && (int) scalar('SELECT COUNT(*) FROM offer_lines WHERE offer_id = ? AND kind <> "text"', [$id]);
        if ($hasLines) $amount = (float)$o['amount'];                                                                 // the lines own the amount
        q('UPDATE offers SET project_id = ?, subproject_id = ?, offer_no = ?, revision = ?, title = ?, amount = ?, currency = ?, sent_at = ?, valid_until = ?, notes = ?' . ($fileId ? ', file_id = ?' : '') . ($ob ? ', vessel_id = ?, ticket_id = ?, layout = ?, vat_pct = ?, validity_days = ?' : '') . ' WHERE id = ?',
          array_merge([$pid, $sid, $no, $rev, $title, $amount, $cur, $sent, $valid, $notes], $fileId ? [$fileId] : [], $ob ? [$vid, $tid, $layout, $vat, $vdays] : [], [$id]));
        if (function_exists('sl_has') && sl_has() && array_key_exists('requested_at', $_POST)) q('UPDATE offers SET requested_at = ? WHERE id = ?', [preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['requested_at']) ? $_POST['requested_at'] : null, $id]);   // R39-002
        if ($ob) ob_recalc($id);
        of_event($id, 'edited', $fileId ? t('of.file_replaced') : null);
        return $id;
    }
    $draft = $ob && !empty($_POST['as_draft']);
    q('INSERT INTO offers (project_id, subproject_id, offer_no, revision, title, amount, currency, status, sent_at, valid_until, status_at, file_id, notes, created_by' . ($ob ? ', vessel_id, ticket_id, layout, vat_pct, validity_days' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?' . ($ob ? ',?,?,?,?,?' : '') . ')',
      array_merge([$pid, $sid, $no, $rev, $title, $amount, $cur, $draft ? 'draft' : 'sent', $sent, $valid, $sent, $fileId, $notes, (int)$u['id']], $ob ? [$vid, $tid, $layout, $vat, $vdays] : []));
    $id = (int) db()->lastInsertId();
    if (function_exists('num_sq') && num_has() && ($sq = num_sq($pid, $sid))) q('UPDATE offers SET sq_no = ? WHERE id = ?', [$sq, $id]);   // R43-001: SQ number = project.sub.SQ.id
    if (function_exists('sl_has') && sl_has()) q('UPDATE offers SET requested_at = ? WHERE id = ?', [preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['requested_at'] ?? '')) ? $_POST['requested_at'] : ($tid ? (scalar('SELECT DATE(created_at) FROM tickets WHERE id = ?', [$tid]) ?: null) : null), $id]);   // R39-002: quote turnaround
    if ($ob && !empty($o = of_load($id)) && empty($o['rate_list_id']) && function_exists('rl_current')) { $b = function_exists('br_of') ? br_of($pid, $sid) : []; $rl = rl_current((int)($b['id'] ?? 0) ?: null, (string)($b['code'] ?? '')); if ($rl) q('UPDATE offers SET rate_list_id = ? WHERE id = ?', [(int)$rl['id'], $id]); }
    of_event($id, $draft ? 'draft' : 'sent', $draft ? null : of_money($amount, $cur));
    return $id;
}
/** The owner's decision on a sent offer (or an administrator recording it on the owner's behalf). */
function of_decide(int $id, string $decision, string $note): void {
    $o = of_load($id); $u = current_user();
    if (!$o || $o['status'] !== 'sent' || !in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException(t('of.not_pending'));
    if (is_staff() && !is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    $note = mb_substr(trim($note), 0, 4000);
    if ($decision === 'rejected' && $note === '') throw new RuntimeException(t('of.reject_note_req'));
    q('UPDATE offers SET status = ?, status_at = CURDATE(), decided_by = ?, decided_name = ?, decided_at = NOW(), decided_ip = ?, decision_note = ? WHERE id = ?',
      [$decision, (int)$u['id'], $u['full_name'] . (is_staff() ? ' (Golden A/S)' : ''), tk_ip(), $note ?: null, $id]);
    if ($decision === 'rejected' && function_exists('sl_has') && sl_has()) { $why = in_array($_POST['lost_reason'] ?? '', SL_LOST, true) ? $_POST['lost_reason'] : (is_staff() ? null : 'other'); if ($why) q('UPDATE offers SET lost_reason = ? WHERE id = ?', [$why, $id]); }   // R39-002
    of_event($id, $decision, $note ?: null);
    of_sync_project((int)$o['project_id']);                                                                           // R57-001
}
/** Golden A/S moves the offer along: cancelled, in_progress, to_be_invoiced, finished, or back to sent (a new revision). */
function of_set_status(int $id, string $status, string $note = ''): void {
    require_staff();
    $o = of_load($id); if (!$o || !in_array($status, of_statuses(), true)) throw new RuntimeException(t('err.notfound'));
    $allowed = of_next_statuses($o); if ($o['status'] === 'sent') array_push($allowed, 'approved', 'rejected');
    if (!in_array($status, $allowed, true)) throw new RuntimeException(t('of.bad_transition'));
    if (in_array($status, ['approved', 'rejected'], true)) { of_decide($id, $status, $note); return; }
    $sets = ['status = ?', 'status_at = CURDATE()']; $vals = [$status];
    if ($status === 'sent') { $sets[] = 'sent_at = CURDATE()'; $sets[] = 'decided_at = NULL'; $sets[] = 'decided_name = NULL'; $sets[] = 'decision_note = NULL'; $sets[] = 'revision = ?'; $vals[] = oa_next_rev($o['revision']); }
    $vals[] = $id;
    q('UPDATE offers SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    of_event($id, 'status_' . $status, mb_substr(trim($note), 0, 1000) ?: null);
    of_sync_project((int)$o['project_id']);                                                                           // R57-001
}
function of_delete(int $id): void { require_cp_admin(); $o = of_load($id); if (!$o) throw new RuntimeException(t('err.notfound')); q('DELETE FROM offers WHERE id = ?', [$id]); if (!empty($o['file_id'])) cp_delete_file((int)$o['file_id']); of_sync_project((int)$o['project_id']); }

/* ---------------------------------------------------- R15: deadlines */
/** Save the deadline posted with a project / sub-project form (column exists from R15). */
function pm_save_deadline(string $table, int $id): void {
    if (!of_has() || !array_key_exists('deadline', $_POST)) return;
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_POST['deadline']) ? $_POST['deadline'] : null;
    q("UPDATE {$table} SET deadline = ? WHERE id = ?", [$d, $id]);
}
/** Keep a dated snapshot of the stage percentages every time progress is saved,
 *  so the weekly report can show "since last week" without anyone remembering to tick a box. */
function pm_auto_snapshot(int $subId): void {
    $s = one('SELECT * FROM subprojects WHERE id = ?', [$subId]); if (!$s) return;
    $cols = array_column(stage_defs(), 'col');
    q('INSERT INTO sp_progress_snapshots (subproject_id, snap_date, ' . implode(',', $cols) . ') VALUES (?, CURDATE(), ' . implode(',', array_fill(0, count($cols), '?')) . ')
       ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", $cols)), array_merge([$subId], array_map(fn($c) => (int)$s[$c], $cols)));
}

/* ------------------------------------------------- R15: Excel import */
/** Normalise a status cell in any language / spelling to the ENUM value, or null. */
/* ---------------------------------------------- GAS-CP-R57-001: a project is active through its offers */
/** Does the schema carry the "quoted" status (R57 migration)? */
function of_quoted_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM projects LIKE 'activated_at'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Statuses that make a project active: approved and everything after approval (in progress, to be invoiced, finished) — built-in and the administrators' own. */
function of_activating_statuses(): array { return of_statuses_where(['approved', 'closed_won']); }
/**
 * Re-derive the status of a project and its sub-projects from its offers:
 *   quoted → active  when at least one offer is approved (or further on);
 *   active → quoted  when none is any more (approval reverted, cancelled) — only for projects that have offers;
 *   on hold / completed are set by hand and are never touched; vessel "general" projects (GEN-) stay active.
 * A project-level offer (no sub-project) activates the project and the quoted sub-projects that have no offer of their own.
 */
function of_sync_project(int $pid): void {
    if (!of_has() || !of_quoted_has()) return;
    $p = one('SELECT id, status, project_code FROM projects WHERE id = ?', [$pid]); if (!$p || str_starts_with((string)$p['project_code'], 'GEN-')) return;
    $act = of_activating_statuses(); if (!$act) return;
    $in = implode(',', array_fill(0, count($act), '?'));
    $first = fn(?int $sid) => one("SELECT o.id, o.subproject_id, o.status_at FROM offers o WHERE o.project_id = ? AND o.status IN ($in)" . ($sid === null ? '' : ' AND o.subproject_id = ?') . " ORDER BY o.status_at, o.id LIMIT 1", array_merge([$pid], $act, $sid === null ? [] : [$sid]));
    $nOffers = (int) scalar('SELECT COUNT(*) FROM offers WHERE project_id = ?', [$pid]);
    $o = $first(null);
    if ($o && $p['status'] === 'quoted') { q("UPDATE projects SET status = 'active', activated_at = ?, activated_offer_id = ? WHERE id = ?", [$o['status_at'] ?: date('Y-m-d'), (int)$o['id'], $pid]); of_event((int)$o['id'], 'project_active', null); }
    elseif (!$o && $p['status'] === 'active' && $nOffers) { q("UPDATE projects SET status = 'quoted', activated_at = NULL, activated_offer_id = NULL WHERE id = ?", [$pid]); }
    $projectLevel = (bool) scalar("SELECT COUNT(*) FROM offers WHERE project_id = ? AND subproject_id IS NULL AND status IN ($in)", array_merge([$pid], $act));
    foreach (all('SELECT id, status FROM subprojects WHERE project_id = ?', [$pid]) as $s) {
        $own = (int) scalar('SELECT COUNT(*) FROM offers WHERE subproject_id = ?', [(int)$s['id']]);
        $so = $own ? $first((int)$s['id']) : null;
        $should = $so || (!$own && $projectLevel);
        if ($should && $s['status'] === 'quoted') q("UPDATE subprojects SET status = 'active', activated_at = ?, activated_offer_id = ? WHERE id = ?", [$so['status_at'] ?? ($o['status_at'] ?? date('Y-m-d')), (int)($so['id'] ?? $o['id'] ?? 0) ?: null, (int)$s['id']]);
        elseif (!$should && $s['status'] === 'active' && ($own || $nOffers)) q("UPDATE subprojects SET status = 'quoted', activated_at = NULL, activated_offer_id = NULL WHERE id = ?", [(int)$s['id']]);
    }
}

function of_norm_status(string $v): ?string {
    $k = strtolower(preg_replace('/[^a-z]+/', '', strtolower(trim($v))));
    if ($k === '') return null;
    $m = ['sent' => 'sent', 'send' => 'sent', 'offered' => 'sent', 'pending' => 'sent', 'open' => 'sent',
          'rejected' => 'rejected', 'reject' => 'rejected', 'declined' => 'rejected',
          'cancelled' => 'cancelled', 'canceled' => 'cancelled', 'cancel' => 'cancelled', 'withdrawn' => 'cancelled',
          'approved' => 'approved', 'approve' => 'approved', 'accepted' => 'approved', 'ordered' => 'approved',
          'inprogress' => 'in_progress', 'progress' => 'in_progress', 'ongoing' => 'in_progress', 'started' => 'in_progress',
          'tobeinvoiced' => 'to_be_invoiced', 'invoice' => 'to_be_invoiced', 'toinvoice' => 'to_be_invoiced', 'readyforinvoice' => 'to_be_invoiced',
          'finished' => 'finished', 'finish' => 'finished', 'done' => 'finished', 'completed' => 'finished', 'closed' => 'finished', 'invoiced' => 'finished'];
    if (isset($m[$k])) return $m[$k];
    foreach (of_status_defs(true) as $s => $d) { $lbl = strtolower(preg_replace('/[^a-z0-9]+/', '', mb_strtolower($d['label']))); if ($lbl !== '' && $lbl === $k) return $s; }   // the current language's label / the administrators' own labels
    return null;
}
/**
 * Import offers from Excel: Project · Sub-project · Offer No · Revision · Subject · Amount · Currency · Status · Sent · Valid until · Notes.
 * An offer is matched by Offer No + Revision (Offer No defaults to "<project code> <sub code>"); filled cells overwrite, blank cells keep.
 */
function of_import(string $path, bool $dry): array {
    if (!of_has()) throw new RuntimeException(t('of.need_r15'));
    $rows = xlsx_rows($path);
    $res  = ['rows' => 0, 'offers_new' => 0, 'offers_upd' => 0, 'skipped' => []];
    $hdr  = pm_import_header($rows, ['project' => ['Project', 'Project Code', 'Project No', 'Code', 'ERP Code'], 'title' => ['Subject', 'Title', 'Description', 'Offer']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Project, Subject, Amount']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['sub' => ['Sub-project', 'Subproject', 'Sub Project', 'Sub', 'Y'], 'no' => ['Offer No', 'Offer Number', 'Offer', 'No', 'No.', 'Reference'], 'rev' => ['Revision', 'Rev'],
                                         'amount' => ['Amount', 'Customer Amount', 'Price', 'Value', 'Total'], 'currency' => ['Currency', 'Cur'], 'status' => ['Status', 'State'],
                                         'sent' => ['Sent', 'Sent At', 'Date', 'Offer Date', 'Date Sent'], 'valid' => ['Valid Until', 'Validity', 'Valid'], 'status_at' => ['Status Date', 'Decided', 'Decision Date'], 'notes' => ['Notes', 'Remarks', 'Comment', 'Comments']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $u = current_user();
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $u) {
        $touched = [];
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $pcode = $g($r, 'project'); $scode = $g($r, 'sub');
            if ($pcode === '') { $res['skipped'][] = [$line, t('of.im_noproject')]; continue; }
            [$main, $subPart] = function_exists('split_project_code') ? split_project_code($pcode) : [$pcode, ''];
            if ($scode === '' && ($subPart ?? '') !== '') $scode = (string)$subPart;                                        // "GY-85-02.Y.01" in one cell
            $p = one('SELECT * FROM projects WHERE project_code = ? OR erp_code = ? OR project_code = ? ORDER BY project_code = ? DESC LIMIT 1', [$pcode, $pcode, $main, $pcode]);
            if (!$p) { $res['skipped'][] = [$line, t('of.im_badproject', ['v' => $pcode])]; continue; }
            $sub = null;
            if ($scode !== '') { $sub = one('SELECT * FROM subprojects WHERE project_id = ? AND (code = ? OR code = ?) LIMIT 1', [$p['id'], $scode, 'Y.' . ltrim($scode, 'Y.')]); if (!$sub) { $res['skipped'][] = [$line, t('of.im_badsub', ['v' => $scode, 'p' => $p['project_code'] ?: $p['name']])]; continue; } }
            $no = mb_substr($g($r, 'no'), 0, 60) ?: of_default_no($p, $sub);
            $rev = mb_substr($g($r, 'rev'), 0, 8); if ($rev !== '' && is_numeric($rev)) $rev = 'R' . (int)$rev; $rev = $rev ?: 'R0';
            $title = mb_substr($g($r, 'title'), 0, 200);
            $amountRaw = $g($r, 'amount'); $amount = $amountRaw === '' ? null : of_parse_amount($amountRaw);
            $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $g($r, 'currency')), 0, 3)) ?: null;
            $statusRaw = $g($r, 'status'); $status = $statusRaw === '' ? null : of_norm_status($statusRaw);
            if ($statusRaw !== '' && $status === null) { $res['skipped'][] = [$line, t('of.im_badstatus', ['v' => $statusRaw])]; continue; }
            $sent = xlsx_date($g($r, 'sent')); $valid = xlsx_date($g($r, 'valid')); $statusAt = xlsx_date($g($r, 'status_at'));
            $notes = mb_substr($g($r, 'notes'), 0, 4000) ?: null;
            $o = one('SELECT * FROM offers WHERE offer_no = ? AND revision = ? LIMIT 1', [$no, $rev]);
            if ($o) {
                $sets = ['project_id = ?', 'subproject_id = ?']; $vals = [$p['id'], $sub['id'] ?? null];
                if ($title !== '') { $sets[] = 'title = ?'; $vals[] = $title; }
                if ($amount !== null) { $sets[] = 'amount = ?'; $vals[] = $amount; }
                if ($cur) { $sets[] = 'currency = ?'; $vals[] = $cur; }
                if ($sent) { $sets[] = 'sent_at = ?'; $vals[] = $sent; }
                if ($valid) { $sets[] = 'valid_until = ?'; $vals[] = $valid; }
                if ($notes) { $sets[] = 'notes = ?'; $vals[] = $notes; }
                if ($status && $status !== $o['status']) { $sets[] = 'status = ?'; $vals[] = $status; $sets[] = 'status_at = ?'; $vals[] = $statusAt ?: date('Y-m-d'); }
                elseif ($statusAt) { $sets[] = 'status_at = ?'; $vals[] = $statusAt; }
                $vals[] = $o['id'];
                q('UPDATE offers SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
                of_event((int)$o['id'], 'imported', ($status && $status !== $o['status']) ? of_status_label($status) : null);
                $res['offers_upd']++;
            } else {
                if ($title === '') { $res['skipped'][] = [$line, t('of.im_notitle')]; continue; }
                $st = $status ?: 'sent'; $sentD = $sent ?: date('Y-m-d');
                q('INSERT INTO offers (project_id, subproject_id, offer_no, revision, title, amount, currency, status, sent_at, valid_until, status_at, notes, created_by' . (of_has_origin() ? ', origin' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?' . (of_has_origin() ? ',?' : '') . ')',
                  array_merge([$p['id'], $sub['id'] ?? null, $no, $rev, $title, $amount ?? 0, $cur ?: 'EUR', $st, $sentD, $valid, $statusAt ?: ($st === 'sent' ? $sentD : date('Y-m-d')), $notes, (int)$u['id']], of_has_origin() ? ['erp'] : []));   // R40-001: ERP origin
                of_event((int) db()->lastInsertId(), 'imported', of_money((float)($amount ?? 0), $cur ?: 'EUR') . ' · ' . of_status_label($st));
                $res['offers_new']++;
            }
            $touched[(int)$p['id']] = true;                                                                            // R57-001
        }
        foreach (array_keys($touched) as $tp) of_sync_project($tp);                                                      // R57-001: projects follow their offers
        return $res;
    });
}

/* ------------------------------------------------ administrators: own statuses (R17-008) */
function of_status_save(): void {
    require_cp_admin();
    if (!of_status_table()) throw new RuntimeException(t('ofs.need_db'));
    $key = (string)($_POST['skey'] ?? ''); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
    $tone = in_array($_POST['tone'] ?? '', ['gold', 'green', 'blue', 'navy', 'mute', 'bad'], true) ? $_POST['tone'] : 'mute';
    $behaves = in_array($_POST['behaves'] ?? '', ['open', 'approved', 'closed_won', 'closed_lost'], true) ? $_POST['behaves'] : 'open';
    $order = (int)($_POST['sort_order'] ?? 50); $active = (int)!empty($_POST['is_active']);
    $defs = of_status_defs();
    if ($key !== '' && isset($defs[$key])) {
        if ($defs[$key]['is_builtin']) { q('INSERT INTO offer_statuses (skey, label, tone, behaves, sort_order, is_builtin, is_active) VALUES (?,?,?,?,?,1,?) ON DUPLICATE KEY UPDATE tone = VALUES(tone), sort_order = VALUES(sort_order), is_active = VALUES(is_active)', [$key, t('of.st_' . $key), $tone, $defs[$key]['behaves'], $order, $key === 'sent' ? 1 : $active]); }
        else { if ($label === '') throw new RuntimeException(t('ofs.need_label')); q('UPDATE offer_statuses SET label = ?, tone = ?, behaves = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$label, $tone, $behaves, $order, $active, $key]); }
        return;
    }
    if ($label === '') throw new RuntimeException(t('ofs.need_label'));
    $key = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label)), 0, 30); $key = trim($key, '_') ?: 'st_' . time();
    if (isset($defs[$key])) $key = substr($key, 0, 26) . '_' . random_int(10, 99);
    q('INSERT INTO offer_statuses (skey, label, tone, behaves, sort_order, is_builtin, is_active) VALUES (?,?,?,?,?,0,1)', [$key, $label, $tone, $behaves, $order]);
}
function of_status_delete(string $key): void {
    require_cp_admin();
    $d = of_status_defs()[$key] ?? null; if (!$d || $d['is_builtin']) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM offers WHERE status = ?', [$key])) throw new RuntimeException(t('ofs.in_use'));
    q('DELETE FROM offer_statuses WHERE skey = ?', [$key]);
}

/* ------------------------------------------------------------ internal notes (R28-001) — Golden A/S only, never shown to the owner */
function ofn_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = of_has() && (bool) scalar("SHOW TABLES LIKE 'offer_notes'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function ofn_list(int $offerId): array {
    return ofn_has() && is_staff() ? all('SELECT n.*, u.full_name AS author FROM offer_notes n LEFT JOIN customer_users u ON u.id = n.user_id WHERE n.offer_id = ? ORDER BY n.id', [$offerId]) : [];
}
function ofn_count(int $offerId): int { return ofn_has() ? (int) scalar('SELECT COUNT(*) FROM offer_notes WHERE offer_id = ?', [$offerId]) : 0; }
function ofn_can_edit(array $n): bool { return is_cp_admin() || (int)$n['user_id'] === (int)(current_user()['id'] ?? 0); }
/** Add (or edit, with $noteId) a note; a new note notifies the project team — PM and administrators — except the author. */
function ofn_save(int $offerId, string $body, int $noteId = 0): int {
    $u = require_staff(); if (!ofn_has()) throw new RuntimeException(t('ofn.need_db'));
    $o = of_load($offerId); if (!$o) throw new RuntimeException(t('err.notfound'));
    $body = trim($body); if ($body === '') throw new RuntimeException(t('ofn.need_text'));
    $body = mb_substr($body, 0, 8000);
    if ($noteId) {
        $n = one('SELECT * FROM offer_notes WHERE id = ? AND offer_id = ?', [$noteId, $offerId]); if (!$n) throw new RuntimeException(t('err.notfound'));
        if (!ofn_can_edit($n)) throw new RuntimeException(t('err.noaccess'));
        q('UPDATE offer_notes SET body = ?, updated_at = NOW() WHERE id = ?', [$body, $noteId]); return $noteId;
    }
    q('INSERT INTO offer_notes (offer_id, user_id, user_name, body) VALUES (?,?,?,?)', [$offerId, (int)$u['id'], $u['full_name'], $body]);
    $id = (int) db()->lastInsertId();
    if (function_exists('mt_notify')) mt_notify($body, $o['offer_no'] . ' ' . $o['revision'], url('offer', ['id' => $offerId]) . '#notes', 'ofn:' . $id, of_users(array_column(mt_staff(), 'id')));
    if (function_exists('nf_push')) {
        $to = of_users(array_values(array_diff(nf_project_staff((int)$o['project_id']), [(int)$u['id']])));
        if ($to) nf_push($to, 'of:note', 'ofn:' . $id, t('ofn.nf_title', ['no' => $o['offer_no'], 't' => $o['title']]), $body, url('offer', ['id' => $offerId]) . '#notes', (int)$u['id'], $u['full_name']);
    }
    return $id;
}
function ofn_delete(int $noteId): int {
    require_staff(); $n = one('SELECT * FROM offer_notes WHERE id = ?', [$noteId]); if (!$n) throw new RuntimeException(t('err.notfound'));
    if (!ofn_can_edit($n)) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM offer_notes WHERE id = ?', [$noteId]); return (int)$n['offer_id'];
}
/** Tasks of an offer (R28-001). */
function of_tasks(int $offerId): array {
    if (!ofn_has() || !is_staff()) return [];
    return all("SELECT t.id, t.title, t.due_at, t.status, t.priority, a.full_name AS assignee FROM tasks t LEFT JOIN customer_users a ON a.id = t.assignee_id WHERE t.offer_id = ? ORDER BY t.status = 'open' DESC, t.due_at", [$offerId]);
}
/** Offers of the projects listed in the task form — for the Offer picker (narrowed per project by the page script). */
function of_for_task_form(array $projectIds, int $keepId = 0): array {
    if (!ofn_has()) return [];
    $ids = array_values(array_filter(array_map('intval', $projectIds))); if (!$ids && !$keepId) return [];
    return all('SELECT id, project_id, offer_no, revision, title, status FROM offers WHERE ' . ($ids ? 'project_id IN (' . implode(',', $ids) . ')' : '0') . ($keepId ? ' OR id = ' . $keepId : '') . ' ORDER BY offer_no DESC', []);
}

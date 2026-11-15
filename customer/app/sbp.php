<?php
/**
 * System Budgets — procurement step.                                                    GAS-CP-R97-049
 *
 * Between "working" and "finished" the budget goes to procurement: the engineer presses "Send to procurement", procurement fills for every
 * part line the cost price, the delivery time (weeks), the supplier, a remark and — when there is one — an alternative part with its own
 * price and delivery, and returns the budget. Only then can it be finished (rule on by default, management may switch it off).
 *
 *   sb_budgets.proc_state   NULL (not sent) · requested (at procurement) · done (returned)
 *   sb_parts                lead_weeks, proc_cost (the price procurement gave), proc_supplier, proc_note, alt_descr, alt_cost, alt_lead_weeks, proc_by, proc_at
 *
 * Procurement accounts (can_procurement) that are not budget users get their own two pages (queue and pricing form). They see the part
 * lines and their cost only — never labour, sale prices, margins or totals of the budget.
 */
declare(strict_types=1);

const SBP_PART_COLS = ['lead_weeks', 'proc_cost', 'proc_supplier', 'proc_note', 'alt_descr', 'alt_cost', 'alt_lead_weeks', 'proc_by', 'proc_at'];

function sbp_has(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW COLUMNS FROM sb_budgets LIKE 'proc_state'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** May price budgets: procurement accounts and management. */
function sbp_can(): bool { static $v = null; if ($v === null) $v = function_exists('is_staff') && is_staff() && sbp_has() && ((function_exists('pr_admin') && pr_admin()) || (function_exists('mgmt_can') && mgmt_can()) || is_cp_admin()); return $v; }
function sbp_require(): array { $u = require_staff(); if (!sbp_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function sbp_required(): bool { return (string)(cp_setting('sb_proc_required', '1') ?? '1') === '1'; }
function sbp_state(array $B): string { $s = (string)($B['proc_state'] ?? ''); return in_array($s, ['requested', 'done'], true) ? $s : ''; }
function sbp_pill(array $B): string { $s = sbp_state($B); return $s === '' ? '' : pill($s === 'requested' ? 'gold' : 'green', t('sbp.st_' . $s)); }
/** A part line procurement has to price: a quantity and not an exclusion. */
function sbp_counts(array $p): bool { return (float)$p['qty'] > 0 && !sb_part_excluded($p); }
/** Part lines still without a procurement price. */
function sbp_open_lines(array $B): array { return array_values(array_filter($B['parts'], fn($p) => sbp_counts($p) && empty($p['proc_at']))); }
function sbp_lead_max(array $B): ?float { $m = null; foreach ($B['parts'] as $p) if (sbp_counts($p) && ($p['lead_weeks'] ?? null) !== null && (float)$p['lead_weeks'] > 0) $m = max((float)$m, (float)$p['lead_weeks']); return $m; }
function sbp_weeks(?float $w): string { return $w === null ? '' : rtrim(rtrim(number_format($w, 1, ',', ''), '0'), ','); }
/** Procurement accounts to notify. */
function sbp_people(): array { try { return array_map('intval', array_column(all('SELECT id FROM customer_users WHERE is_active = 1 AND customer_id IS NULL AND can_procurement = 1'), 'id')); } catch (Throwable $e) { return []; } }
/** What stops Finish because of this step. */
function sbp_blockers(array $B): array {
    if (!sbp_has() || !empty($B['is_template'])) return []; $x = [];
    if (sbp_state($B) === 'requested') { $x[] = t('sbp.blk_wait'); return $x; }
    if (sbp_required()) { $n = count(sbp_open_lines($B)); if ($n) $x[] = t(sbp_state($B) === 'done' ? 'sbp.blk_new_lines' : 'sbp.blk_send', ['n' => $n]); }
    return $x;
}
/** Engineer: send the budget to procurement. */
function sbp_request(int $id, string $note = ''): void {
    $B = sb_load_editable($id); if (!sbp_has() || !empty($B['is_template'])) throw new RuntimeException(t('err.noaccess'));
    if (!array_filter($B['parts'], 'sbp_counts')) throw new RuntimeException(t('sbp.no_parts')); if (sbp_state($B) === 'requested') throw new RuntimeException(t('sbp.already'));
    $u = current_user(); $note = mb_substr(trim((string) preg_replace('/\s+/', ' ', $note)), 0, 300);
    q("UPDATE sb_budgets SET proc_state = 'requested', proc_note = ?, proc_req_by = ?, proc_req_at = NOW(), proc_done_by = NULL, proc_done_at = NULL, status = IF(status = 'draft', 'working', status) WHERE id = ?", [$note !== '' ? $note : null, (int)($u['id'] ?? 0) ?: null, $id]);
    sb_event($id, 'proc_request', $note !== '' ? $note : null);
    try { if (function_exists('nf_push')) nf_push(sbp_people(), 'sb:proc', 'sbp:' . $id, t('sbp.nf_request', ['n' => sb_label($B)]), mb_substr((string)$B['title'] . ($note !== '' ? ' — ' . $note : ''), 0, 300), url('system_budget_proc', ['id' => $id]), (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
/** Engineer or management: take the budget back before procurement has returned it. */
function sbp_recall(int $id): void {
    $B = sb_load_editable($id); if (sbp_state($B) !== 'requested') throw new RuntimeException(t('sbp.not_there'));
    q('UPDATE sb_budgets SET proc_state = NULL WHERE id = ?', [$id]); sb_event($id, 'proc_recall');
}
/** The budget as procurement may see it: identity and part lines with the inventory price beside them. No labour, no sale, no totals. */
function sbp_get(int $id): ?array {
    if (!sbp_has() || $id <= 0) return null;
    $B = one('SELECT b.id, b.sb_no, b.revision, b.title, b.status, b.priority, b.target_date, b.currency, b.proc_state, b.proc_note, b.proc_req_at, b.proc_done_at, b.is_template, p.project_code, p.name AS project_name, v.name AS vessel_name, ru.full_name AS req_name, du.full_name AS done_name, ou.full_name AS owner_name
              FROM sb_budgets b LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customer_users ru ON ru.id = b.proc_req_by LEFT JOIN customer_users du ON du.id = b.proc_done_by LEFT JOIN customer_users ou ON ou.id = b.owner_user_id WHERE b.id = ?', [$id]);
    if (!$B || !empty($B['is_template'])) return null;
    $B['parts'] = all('SELECT x.id, x.sort_order, x.code, x.item_id, x.descr, x.qty, x.unit, x.unit_cost, x.cost_origin, x.remarks, x.lead_weeks, x.proc_cost, x.proc_supplier, x.proc_note, x.alt_descr, x.alt_cost, x.alt_lead_weeks, x.proc_at, i.code AS item_code, i.maker, i.factory_code, i.last_price, i.last_price_at, pu.full_name AS proc_name
                       FROM sb_parts x LEFT JOIN inv_items i ON i.id = x.item_id LEFT JOIN customer_users pu ON pu.id = x.proc_by WHERE x.budget_id = ? ORDER BY x.sort_order, x.id', [$id]);
    return $B;
}
/** Procurement: store prices, delivery times, suppliers, remarks and alternatives. Returns the number of lines priced. */
function sbp_save(int $id, bool $confirmAll = false): int {
    if (!sbp_can()) throw new RuntimeException(t('err.noaccess')); $B = sbp_get($id); if (!$B) throw new RuntimeException(t('err.notfound'));
    if (sbp_state($B) !== 'requested' || !in_array($B['status'], ['draft', 'working'], true)) throw new RuntimeException(t('sbp.not_there'));
    $rows = is_array($_POST['p'] ?? null) ? $_POST['p'] : []; $own = array_column($B['parts'], null, 'id'); $u = current_user(); $uid = (int)($u['id'] ?? 0); $n = 0;
    $num = fn($v) => trim((string)$v) === '' ? null : max(0.0, sb_num((string)$v)); $txt = fn($v, int $len) => (($s = mb_substr(trim((string) preg_replace('/\s+/', ' ', (string)$v)), 0, $len)) !== '' ? $s : null);
    foreach ($rows as $key => $r) {
        if (!is_array($r) || !ctype_digit((string)$key) || !isset($own[(int)$key])) continue; $pid = (int)$key; $cur = $own[$pid];
        $cost = $num($r['unit_cost'] ?? ''); $lead = $num($r['lead_weeks'] ?? ''); if ($lead !== null) $lead = min(520.0, $lead); $al = $num($r['alt_lead_weeks'] ?? ''); if ($al !== null) $al = min(520.0, $al);
        $sup = $txt($r['proc_supplier'] ?? '', 120); $note = $txt($r['proc_note'] ?? '', 300); $ad = $txt($r['alt_descr'] ?? '', 300); $ac = $num($r['alt_cost'] ?? '');
        q('UPDATE sb_parts SET lead_weeks = ?, proc_supplier = ?, proc_note = ?, alt_descr = ?, alt_cost = ?, alt_lead_weeks = ? WHERE id = ? AND budget_id = ?', [$lead, $sup, $note, $ad, $ad !== null ? $ac : null, $ad !== null ? $al : null, $pid, $id]);
        // a price procurement typed, or one it worked on (delivery, supplier, remark given), is confirmed at once; a price it did not touch is confirmed
        // only when the budget is returned — so saving half-way does not mark the whole list as priced
        $touched = $cost !== null && (abs($cost - (float)$cur['unit_cost']) > 0.0049 || $lead !== null || $sup !== null || $note !== null || !empty($cur['proc_at']));
        if ($cost !== null && $cost > 0 && ($confirmAll || $touched)) {
            $org = mb_substr(t('sbp.origin', ['n' => (string)($u['full_name'] ?? ''), 'd' => date('d/m/Y')]) . ($sup !== null ? ' · ' . $sup : ''), 0, 120);
            q('UPDATE sb_parts SET unit_cost = ?, proc_cost = ?, cost_origin = ?, proc_by = ?, proc_at = NOW() WHERE id = ? AND budget_id = ?', [$cost, $cost, $org, $uid ?: null, $pid, $id]); $n++;
        }
    }
    sb_recalc($id); sb_event($id, 'proc_price', (string)$n); return $n;
}
/** Procurement: return the budget to the engineer. */
function sbp_return(int $id, string $note = ''): void {
    if (!sbp_can()) throw new RuntimeException(t('err.noaccess')); $B = sb_get($id); if (!$B || sbp_state($B) !== 'requested') throw new RuntimeException(t('sbp.not_there'));
    $u = current_user(); $note = mb_substr(trim((string) preg_replace('/\s+/', ' ', $note)), 0, 300); $open = count(sbp_open_lines($B));
    q("UPDATE sb_budgets SET proc_state = 'done', proc_done_by = ?, proc_done_at = NOW() WHERE id = ?", [(int)($u['id'] ?? 0) ?: null, $id]);
    sb_event($id, 'proc_return', trim(($open ? t('sbp.ev_open', ['n' => $open]) . ' ' : '') . $note) ?: null);
    $to = array_unique(array_filter([(int)($B['proc_req_by'] ?? 0), (int)($B['owner_user_id'] ?? 0), (int)($B['created_by'] ?? 0)]));
    try { if (function_exists('nf_push') && $to) nf_push($to, 'sb:proc', 'sbp:' . $id, t('sbp.nf_return', ['n' => sb_label($B)]), mb_substr((string)$B['title'] . ($open ? ' — ' . t('sbp.ev_open', ['n' => $open]) : '') . ($note !== '' ? ' — ' . $note : ''), 0, 300), url('system_budget', ['id' => $id]) . '#procurement', (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
/** Engineer: take the alternative of a line — the alternative becomes the part, the former part becomes the alternative. */
function sbp_use_alt(int $id, int $partId): void {
    $B = sb_load_editable($id); if (sbp_state($B) === 'requested') throw new RuntimeException(t('sbp.locked')); $p = null; foreach ($B['parts'] as $x) if ((int)$x['id'] === $partId) $p = $x;
    if (!$p || trim((string)($p['alt_descr'] ?? '')) === '') throw new RuntimeException(t('err.notfound'));
    $ac = ($p['alt_cost'] ?? null) !== null ? (float)$p['alt_cost'] : 0.0; $u = current_user();
    q('UPDATE sb_parts SET descr = ?, code = NULL, item_id = NULL, unit_cost = ?, proc_cost = ?, lead_weeks = ?, alt_descr = ?, alt_cost = ?, alt_lead_weeks = ?, cost_origin = ?, proc_at = IF(? > 0, proc_at, NULL) WHERE id = ? AND budget_id = ?',
      [mb_substr((string)$p['alt_descr'], 0, 400), $ac, $ac > 0 ? $ac : null, $p['alt_lead_weeks'] ?? null, mb_substr(trim((string)($p['code'] ?? '') . ' ' . (string)$p['descr']), 0, 300), (float)$p['unit_cost'], $p['lead_weeks'] ?? null, mb_substr(t('sbp.origin_alt', ['n' => (string)($u['full_name'] ?? ''), 'd' => date('d/m/Y')]), 0, 120), $ac, $partId, $id]);
    sb_recalc($id); sb_event($id, 'proc_alt', mb_substr((string)$p['alt_descr'], 0, 200));
}
/** Budgets at procurement (and the ones returned in the last 30 days) for the queue page. */
function sbp_queue(): array {
    if (!sbp_has()) return [];
    try { return all("SELECT b.id, b.sb_no, b.revision, b.title, b.priority, b.target_date, b.proc_state, b.proc_note, b.proc_req_at, b.proc_done_at, p.project_code, v.name AS vessel_name, ru.full_name AS req_name,
                             (SELECT COUNT(*) FROM sb_parts x WHERE x.budget_id = b.id AND x.qty > 0) AS n_parts, (SELECT COUNT(*) FROM sb_parts x WHERE x.budget_id = b.id AND x.qty > 0 AND x.proc_at IS NOT NULL) AS n_done
                      FROM sb_budgets b LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customer_users ru ON ru.id = b.proc_req_by
                      WHERE b.is_template = 0 AND (b.proc_state = 'requested' OR (b.proc_state = 'done' AND b.proc_done_at >= NOW() - INTERVAL 30 DAY))
                      ORDER BY (b.proc_state = 'requested') DESC, FIELD(b.priority, 'urgent', 'high', 'normal', 'low'), b.target_date IS NULL, b.target_date, b.proc_req_at LIMIT 200"); } catch (Throwable $e) { return []; }
}
function sbp_waiting(): int { static $n = null; if ($n === null) { try { $n = sbp_has() ? (int) scalar("SELECT COUNT(*) FROM sb_budgets WHERE proc_state = 'requested' AND status IN ('draft','working')") : 0; } catch (Throwable $e) { $n = 0; } } return $n; }

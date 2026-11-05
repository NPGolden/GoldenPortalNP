<?php
/**
 * Purchase requisitions.                                     GAS-CP-R80-001
 * Staff of the enabled disciplines (and PMs, procurement, management) ask for parts
 * against a project / sub-project, stock or service. Procurement approves against the
 * parts budget of the sub-project; over budget (or no budget) the request goes to
 * management. On approval the stock on hand is reserved and the rest is marked "to buy"
 * (RFQ / PO in R80-003). The warehouse issues reserved lines (stock out + parts cost).
 * Tables: pr_requests · pr_lines · pr_events.  Number: <project>.<sub>.PR.<n> (doc_sequences 'PR').
 */
declare(strict_types=1);

const PRQ_STATES  = ['draft', 'submitted', 'mgmt', 'approved', 'rejected', 'closed', 'cancelled'];
const PRQ_TONE    = ['draft' => 'mute', 'submitted' => 'gold', 'mgmt' => 'warn', 'approved' => 'ok', 'rejected' => 'bad', 'closed' => 'navy', 'cancelled' => 'mute'];
const PRQ_L_TONE  = ['open' => 'mute', 'reserved' => 'ok', 'to_buy' => 'gold', 'ordered' => 'navy', 'received' => 'ok', 'issued' => 'navy', 'cancelled' => 'mute'];
const PRQ_PURPOSE = ['project', 'stock', 'service'];

function prq_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'pr_requests'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** Sub-project PM, project PM or the user's discipline manager scope: anyone who runs a project may raise a requisition for it. */
function prq_is_pm(?int $pid = null): bool {
    $u = current_user(); if (!$u || !is_staff()) return false; static $c = [];
    $k = (int)$pid; if (!isset($c[$k])) { try { $c[$k] = $pid ? (bool) scalar('SELECT 1 FROM projects WHERE id = ? AND pm_user_id = ?', [$pid, (int)$u['id']]) : (bool) scalar('SELECT 1 FROM projects WHERE pm_user_id = ? LIMIT 1', [(int)$u['id']]); } catch (Throwable $e) { $c[$k] = false; } }
    return $c[$k];
}
/** Who may raise a requisition: procurement / warehouse / management, a PM, or staff whose discipline is enabled in Settings › Procurement. */
function prq_can(): bool {
    static $v = null; if ($v !== null) return $v;
    $u = current_user(); if (!$u || !is_staff() || !prq_has() || !stk_has()) return $v = false;
    if (pr_can() || is_cp_admin() || prq_is_pm()) return $v = true;
    $d = (int)($u['discipline_id'] ?? 0); return $v = $d > 0 && in_array($d, stk_enabled_disciplines(), true);
}
function require_prq(): array { $u = require_staff(); if (!prq_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
/** Everything a requester may see: own requests; procurement / management: all; a PM: the requests of their projects. */
function prq_can_view(array $r): bool {
    $u = current_user(); if (!$u || !is_staff()) return false;
    if (pr_can() || is_cp_admin()) return true;
    if ((int)$r['requester_id'] === (int)$u['id']) return true;
    return prq_is_pm((int)$r['project_id']);
}
function prq_can_edit(array $r): bool { $u = current_user(); return $u && in_array($r['status'], ['draft'], true) && ((int)$r['requester_id'] === (int)$u['id'] || pr_admin()); }
function prq_mgmt_ids(): array { try { return array_values(array_unique(array_merge(function_exists('hr_owner_ids') ? hr_owner_ids() : [], array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND LOWER(TRIM(r.name)) IN ('general manager','managing director')"), 'id'))))); } catch (Throwable $e) { return []; } }
function prq_proc_ids(): array { try { return array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role IN ('staff','admin') AND can_procurement = 1"), 'id')); } catch (Throwable $e) { return []; } }
function prq_pill(string $s): string { return pill(PRQ_TONE[$s] ?? 'mute', t('prq.s_' . $s)); }
function prq_line_pill(string $s): string { return pill(PRQ_L_TONE[$s] ?? 'mute', t('prq.l_' . $s)); }
function prq_money(?float $v, string $cur = 'EUR'): string { return $v === null ? '—' : number_format($v, 2, ',', '.') . ' ' . $cur; }

/* ------------------------------------------------------------------ read */
const PRQ_SELECT = "SELECT r.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, d.name AS discipline,
                    (SELECT COUNT(*) FROM pr_lines l WHERE l.request_id = r.id AND l.status <> 'cancelled') AS n_lines,
                    (SELECT COUNT(*) FROM pr_lines l WHERE l.request_id = r.id AND l.status = 'to_buy') AS n_to_buy
                    FROM pr_requests r JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id LEFT JOIN disciplines d ON d.id = r.discipline_id";
function prq_load(int $id): ?array { return prq_has() && $id ? one(PRQ_SELECT . ' WHERE r.id = ?', [$id]) ?: null : null; }
function prq_list(array $f = []): array {
    if (!prq_has()) return []; $u = current_user(); $w = []; $a = [];
    if (!(pr_can() || is_cp_admin())) { $w[] = '(r.requester_id = ? OR p.pm_user_id = ?)'; $a[] = (int)$u['id']; $a[] = (int)$u['id']; }
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "r.status IN ('submitted','mgmt','approved')"; elseif ($f['status'] === 'pending') $w[] = "r.status IN ('submitted','mgmt')"; elseif (in_array($f['status'], PRQ_STATES, true)) { $w[] = 'r.status = ?'; $a[] = $f['status']; } }
    if (!empty($f['mine'])) { $w[] = 'r.requester_id = ?'; $a[] = (int)$u['id']; }
    if (!empty($f['project_id'])) { $w[] = 'r.project_id = ?'; $a[] = (int)$f['project_id']; }
    if (!empty($f['subproject_id'])) { $w[] = 'r.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['q'])) { $l = '%' . trim((string)$f['q']) . '%'; $w[] = '(r.pr_no LIKE ? OR r.title LIKE ? OR p.name LIKE ? OR p.project_code LIKE ? OR r.requester_name LIKE ? OR EXISTS (SELECT 1 FROM pr_lines l WHERE l.request_id = r.id AND (l.description LIKE ? OR l.part_no LIKE ?)))'; array_push($a, $l, $l, $l, $l, $l, $l, $l); }
    return all(PRQ_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.id DESC', $a);
}
function prq_lines(int $rid): array { return all('SELECT l.*, i.code AS item_code, i.description AS item_desc, i.unit AS item_unit, i.last_price, i.currency AS item_cur, (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS on_hand, (SELECT COALESCE(SUM(s.qty_reserved),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS reserved_all FROM pr_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY l.seq, l.id', [$rid]); }
function prq_events(int $rid): array { return all('SELECT * FROM pr_events WHERE request_id = ? ORDER BY id DESC', [$rid]); }
function prq_event(int $rid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO pr_events (request_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)', [$rid, $ev, $u['id'] ?? null, $u['full_name'] ?? null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $note !== null ? mb_substr($note, 0, 600) : null]); }
function prq_counts(): array {
    if (!prq_has() || !prq_can()) return ['pending' => 0, 'mgmt' => 0, 'approved' => 0, 'mine' => 0];
    $u = current_user(); $c = ['pending' => 0, 'mgmt' => 0, 'approved' => 0, 'mine' => 0];
    try {
        $c['approved'] = (int) scalar("SELECT COUNT(*) FROM pr_requests r JOIN projects p ON p.id = r.project_id WHERE r.status = 'approved'" . (pr_can() || is_cp_admin() ? '' : ' AND (r.requester_id = ' . (int)$u['id'] . ' OR p.pm_user_id = ' . (int)$u['id'] . ')'));
        if (pr_admin()) $c['pending'] = (int) scalar("SELECT COUNT(*) FROM pr_requests WHERE status = 'submitted'");
        if (mgmt_can()) $c['mgmt'] = (int) scalar("SELECT COUNT(*) FROM pr_requests WHERE status = 'mgmt'");
        $c['mine'] = (int) scalar("SELECT COUNT(*) FROM pr_requests WHERE requester_id = ? AND status IN ('draft','submitted','mgmt')", [(int)$u['id']]);
    } catch (Throwable $e) {}
    return $c;
}

/* ------------------------------------------------------------------ budget */
/** Parts budget of the sub-project vs. what is committed by open / approved requisitions and what is already spent (sp_costs kind 'parts'). */
function prq_budget(?int $sid, int $excludeReq = 0): array {
    $out = ['parts_budget' => null, 'committed' => 0.0, 'actual' => 0.0, 'available' => null];
    if (!$sid) return $out;
    $b = function_exists('bg_current') ? bg_current($sid) : null;
    if ($b && isset($b['parts_budget'])) $out['parts_budget'] = (float)$b['parts_budget'];
    $out['committed'] = round((float) scalar("SELECT COALESCE(SUM(GREATEST(0, l.qty - l.qty_issued) * COALESCE(l.est_price, 0)),0) FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id WHERE r.subproject_id = ? AND r.status IN ('submitted','mgmt','approved') AND r.id <> ? AND l.status NOT IN ('cancelled','issued')", [$sid, $excludeReq]), 2);   // what is still to be issued or bought — issued quantities are already in the parts costs
    try { $out['actual'] = (float) scalar("SELECT COALESCE(SUM(amount),0) FROM sp_costs WHERE subproject_id = ? AND kind = 'parts'", [$sid]); } catch (Throwable $e) {}
    if ($out['parts_budget'] !== null) $out['available'] = $out['parts_budget'] - $out['committed'] - $out['actual'];
    return $out;
}
/** Estimate of a line: the pricing rule of Settings › Procurement on the item's price history, else the last price. Free-text lines carry what the requester typed. */
function prq_line_estimate(array $l): array {
    if (!empty($l['item_id'])) { $it = stk_item((int)$l['item_id']); if ($it) { $p = stk_price_proposal($it); if ($p['price'] !== null) return ['price' => $p['price'], 'origin' => mb_substr($p['origin'], 0, 160)]; if ($it['last_price'] !== null) return ['price' => (float)$it['last_price'], 'origin' => t('stk.rule_last')]; } }
    return ['price' => $l['est_price'] !== null ? (float)$l['est_price'] : null, 'origin' => $l['est_price'] !== null ? t('prq.est_typed') : null];
}
function prq_recalc(int $rid): float {
    $t = 0.0;
    foreach (all("SELECT * FROM pr_lines WHERE request_id = ? AND status <> 'cancelled'", [$rid]) as $l) {
        $e = prq_line_estimate($l);
        q('UPDATE pr_lines SET est_price = ?, price_origin = ? WHERE id = ?', [$e['price'], $e['origin'], (int)$l['id']]);
        if ($e['price'] !== null) $t += (float)$l['qty'] * $e['price'];
    }
    q('UPDATE pr_requests SET est_total = ? WHERE id = ?', [round($t, 2), $rid]);
    return round($t, 2);
}

/* ------------------------------------------------------------------ write */
function prq_number(int $pid, ?int $sid): string {
    $stem = function_exists('num_stem') ? num_stem($pid, $sid) : '';
    $n = num_unique('PR');
    return ($stem !== '' ? $stem : 'GEN.') . 'PR.' . $n;
}
function prq_save_from_post(): int {
    $u = require_prq(); $id = (int)($_POST['id'] ?? 0);
    $pid = (int)($_POST['project_id'] ?? 0); $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if (!$pid) throw new RuntimeException(t('prq.need_project'));
    if ($sid && !scalar('SELECT 1 FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    $purpose = in_array($_POST['purpose'] ?? '', PRQ_PURPOSE, true) ? $_POST['purpose'] : 'project';
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160) ?: null;
    $need = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['need_date'] ?? '')) ? $_POST['need_date'] : null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    if ($id) {
        $r = prq_load($id); if (!$r || !prq_can_edit($r)) throw new RuntimeException(t('err.noaccess'));
        q('UPDATE pr_requests SET project_id = ?, subproject_id = ?, purpose = ?, title = ?, need_date = ?, note = ? WHERE id = ?', [$pid, $sid, $purpose, $title, $need, $note, $id]);
        prq_event($id, 'edit');
    } else {
        $br = null; try { $br = (int) scalar('SELECT branch_id FROM projects WHERE id = ?', [$pid]) ?: null; } catch (Throwable $e) {}
        q('INSERT INTO pr_requests (pr_no, branch_id, project_id, subproject_id, purpose, title, need_date, note, requester_id, requester_name, discipline_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
          [prq_number($pid, $sid), $br, $pid, $sid, $purpose, $title, $need, $note, (int)$u['id'], $u['full_name'], (int)($u['discipline_id'] ?? 0) ?: null]);
        $id = (int) db()->lastInsertId(); prq_event($id, 'create');
    }
    return $id;
}
function prq_line_save_from_post(): int {
    require_prq(); $rid = (int)($_POST['request_id'] ?? 0); $r = prq_load($rid); if (!$r || !prq_can_edit($r)) throw new RuntimeException(t('err.noaccess'));
    $lid = (int)($_POST['line_id'] ?? 0);
    $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '0')); if ($qty <= 0) throw new RuntimeException(t('prq.need_qty'));
    $itemId = (int)($_POST['item_id'] ?? 0) ?: null; $it = $itemId ? stk_item($itemId) : null; if ($itemId && !$it) throw new RuntimeException(t('err.notfound'));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255); if ($it) $desc = $desc ?: (string)$it['description'];
    if ($desc === '') throw new RuntimeException(t('prq.need_desc'));
    $unit = $it ? (string)$it['unit'] : (in_array($_POST['unit'] ?? '', STK_UNITS, true) ? $_POST['unit'] : 'pcs');
    $maker = $it ? ($it['maker'] ?? null) : (mb_substr(trim((string)($_POST['maker'] ?? '')), 0, 80) ?: null);
    $pn = $it ? ($it['factory_code'] ?? null) : (mb_substr(trim((string)($_POST['part_no'] ?? '')), 0, 80) ?: null);
    $est = ($_POST['est_price'] ?? '') !== '' ? round((float) str_replace(',', '.', (string)$_POST['est_price']), 4) : null;
    $need = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['need_date'] ?? '')) ? $_POST['need_date'] : null;
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    if ($lid) {
        if (!scalar('SELECT 1 FROM pr_lines WHERE id = ? AND request_id = ?', [$lid, $rid])) throw new RuntimeException(t('err.notfound'));
        q('UPDATE pr_lines SET item_id = ?, description = ?, maker = ?, part_no = ?, unit = ?, qty = ?, est_price = ?, need_date = ?, note = ? WHERE id = ?', [$itemId, $desc, $maker, $pn, $unit, $qty, $est, $need, $note, $lid]);
    } else {
        $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM pr_lines WHERE request_id = ?', [$rid]);
        q('INSERT INTO pr_lines (request_id, seq, item_id, description, maker, part_no, unit, qty, est_price, need_date, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$rid, $seq, $itemId, $desc, $maker, $pn, $unit, $qty, $est, $need, $note]);
        $lid = (int) db()->lastInsertId();
    }
    prq_recalc($rid);
    return $lid;
}
function prq_line_delete(int $rid, int $lid): void {
    require_prq(); $r = prq_load($rid); if (!$r || !prq_can_edit($r)) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM pr_lines WHERE id = ? AND request_id = ?', [$lid, $rid]); prq_recalc($rid);
}
function prq_notify(array $ids, int $rid, string $kind, string $title, ?string $body = null): void {
    if (!function_exists('nf_push') || !$ids) return; $u = current_user();
    try { nf_push($ids, 'work:' . $kind, 'prq:' . $rid . ':' . $kind, $title, $body, url('requisition', ['id' => $rid]), (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null); } catch (Throwable $e) {}
}
/** Requester submits: estimate refreshed, budget snapshot stored, procurement notified. */
function prq_submit(int $rid): void {
    require_prq(); $r = prq_load($rid); $u = current_user();
    if (!$r || $r['status'] !== 'draft' || !((int)$r['requester_id'] === (int)$u['id'] || pr_admin())) throw new RuntimeException(t('err.noaccess'));
    if (!(int) scalar("SELECT COUNT(*) FROM pr_lines WHERE request_id = ? AND status <> 'cancelled'", [$rid])) throw new RuntimeException(t('prq.need_lines'));
    $est = prq_recalc($rid); $b = prq_budget($r['subproject_id'] ? (int)$r['subproject_id'] : null, $rid);
    $over = $r['purpose'] === 'project' && ($b['parts_budget'] === null || $b['parts_budget'] <= 0 || $est > $b['available']) ? 1 : 0;
    q('UPDATE pr_requests SET status = ?, submitted_at = NOW(), budget_parts = ?, budget_committed = ?, budget_actual = ?, over_budget = ? WHERE id = ?', ['submitted', $b['parts_budget'], $b['committed'], $b['actual'], $over, $rid]);
    prq_event($rid, 'submit', $over ? t('prq.ev_over') : null);
    prq_notify(prq_proc_ids(), $rid, 'prq_submitted', t('prq.nf_submitted', ['no' => $r['pr_no']]), ($r['title'] ?: '') . ' · ' . $r['project_name'] . ' · ' . prq_money($est));
    cache_bump_later('inv');
}
/** Procurement decides. Within budget → approved (stock reserved). Over budget → to management with the reason. */
function prq_decide(int $rid, string $decision, string $note): void {
    require_pr_admin(); $r = prq_load($rid); if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('err.noaccess'));
    $note = mb_substr(trim($note), 0, 400); $u = current_user();
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q('UPDATE pr_requests SET status = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ?', ['rejected', $u['id'], $u['full_name'], $note, $rid]); prq_event($rid, 'reject', $note); prq_notify([(int)$r['requester_id']], $rid, 'prq_rejected', t('prq.nf_rejected', ['no' => $r['pr_no']]), $note); cache_bump_later('inv'); return; }
    $est = prq_recalc($rid); $b = prq_budget($r['subproject_id'] ? (int)$r['subproject_id'] : null, $rid);
    $over = $r['purpose'] === 'project' && ($b['parts_budget'] === null || $b['parts_budget'] <= 0 || $est > $b['available']);
    q('UPDATE pr_requests SET approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ?, budget_parts = ?, budget_committed = ?, budget_actual = ?, over_budget = ? WHERE id = ?', [$u['id'], $u['full_name'], $note ?: null, $b['parts_budget'], $b['committed'], $b['actual'], $over ? 1 : 0, $rid]);
    if ($over) {
        if ($note === '') throw new RuntimeException(t('prq.need_reason'));
        q('UPDATE pr_requests SET status = ? WHERE id = ?', ['mgmt', $rid]); prq_event($rid, 'to_mgmt', $note);
        prq_notify(prq_mgmt_ids(), $rid, 'prq_mgmt', t('prq.nf_mgmt', ['no' => $r['pr_no']]), t('prq.over_by', ['v' => prq_money(max(0.0, $est - (float)($b['available'] ?? 0)))]) . ' · ' . $note);
    } else { prq_approve_apply($rid, 'approve', $note); }
    cache_bump_later('inv');
}
/** Management decides an over-budget request. */
function prq_mgmt_decide(int $rid, string $decision, string $note): void {
    require_mgmt(); $r = prq_load($rid); if (!$r || $r['status'] !== 'mgmt') throw new RuntimeException(t('err.noaccess'));
    $note = mb_substr(trim($note), 0, 400); $u = current_user();
    q('UPDATE pr_requests SET mgmt_by = ?, mgmt_name = ?, mgmt_at = NOW(), mgmt_note = ? WHERE id = ?', [$u['id'], $u['full_name'], $note ?: null, $rid]);
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q('UPDATE pr_requests SET status = ? WHERE id = ?', ['rejected', $rid]); prq_event($rid, 'mgmt_reject', $note); prq_notify(array_unique([(int)$r['requester_id'], (int)$r['approved_by']]), $rid, 'prq_rejected', t('prq.nf_rejected', ['no' => $r['pr_no']]), $note); }
    else prq_approve_apply($rid, 'mgmt_approve', $note);
    cache_bump_later('inv');
}
/** Approval: reserve what is on hand (warehouse with the most free stock), the rest becomes "to buy". */
function prq_approve_apply(int $rid, string $ev, string $note): void {
    $r = prq_load($rid);
    foreach (all("SELECT * FROM pr_lines WHERE request_id = ? AND status = 'open'", [$rid]) as $l) {
        $res = 0.0; $wh = null;
        if (!empty($l['item_id'])) {
            $row = one('SELECT warehouse_id, qty_on_hand - qty_reserved AS free FROM inv_stock WHERE item_id = ? AND qty_on_hand - qty_reserved > 0 ORDER BY free DESC LIMIT 1', [(int)$l['item_id']]);
            if ($row) { $res = min((float)$l['qty'], (float)$row['free']); $wh = (int)$row['warehouse_id']; q('UPDATE inv_stock SET qty_reserved = qty_reserved + ? WHERE item_id = ? AND warehouse_id = ?', [$res, (int)$l['item_id'], $wh]); }
        }
        $st = $res >= (float)$l['qty'] - 0.0005 ? 'reserved' : 'to_buy';
        q('UPDATE pr_lines SET qty_reserved = ?, warehouse_id = ?, status = ? WHERE id = ?', [$res, $wh, $st, (int)$l['id']]);
    }
    q('UPDATE pr_requests SET status = ? WHERE id = ?', ['approved', $rid]); prq_event($rid, $ev, $note ?: null);
    $to = [(int)$r['requester_id']]; if ($ev === 'mgmt_approve' && $r['approved_by']) $to[] = (int)$r['approved_by']; $to = array_merge($to, prq_proc_ids());
    prq_notify(array_values(array_unique($to)), $rid, 'prq_approved', t('prq.nf_approved', ['no' => $r['pr_no']]), $note ?: null);
}
/** Warehouse issues a reserved line (part or whole): stock out, reservation released, parts cost on the sub-project. */
function prq_issue(int $rid, int $lid, float $qty, string $note = ''): void {
    require_pr_wh(); $r = prq_load($rid); $l = one('SELECT * FROM pr_lines WHERE id = ? AND request_id = ?', [$lid, $rid]);
    if (!$r || !$l || $r['status'] !== 'approved' || !in_array($l['status'], ['reserved', 'to_buy', 'received'], true) || empty($l['item_id'])) throw new RuntimeException(t('err.noaccess'));
    $left = (float)$l['qty_reserved']; if ($qty <= 0 || $qty > $left + 0.0005) throw new RuntimeException(t('prq.issue_max', ['q' => stk_qty(max(0.0, $left))]));
    $wh = (int)($l['warehouse_id'] ?: stk_default_wh()); $it = stk_item((int)$l['item_id']); $u = current_user();
    q('UPDATE inv_stock SET qty_reserved = GREATEST(0, qty_reserved - ?) WHERE item_id = ? AND warehouse_id = ?', [min($qty, (float)$l['qty_reserved']), (int)$l['item_id'], $wh]);
    stk_move((int)$l['item_id'], $wh, 'out', $qty, ['unit_price' => $it['last_price'], 'note' => $r['pr_no'] . ($note !== '' ? ' · ' . $note : ''), 'project_id' => (int)$r['project_id'], 'subproject_id' => $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'reason' => 'issue', 'ref_kind' => 'pr', 'ref_id' => $rid]);
    if ($r['subproject_id'] && function_exists('bg_has') && bg_has() && $it['last_price']) { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, created_by, created_name) VALUES (?,CURDATE(),?,?,?,?,?,?,?,?)', [(int)$r['subproject_id'], 'parts', t('stk.stock'), mb_substr($it['code'] . ' · ' . $it['description'] . ' × ' . stk_qty($qty), 0, 255), mb_substr($r['pr_no'], 0, 60), round($qty * (float)$it['last_price'], 2), $it['currency'], $u['id'], $u['full_name']]); if (function_exists('eng_rows_reset')) eng_rows_reset(); }
    $issued = (float)$l['qty_issued'] + $qty; $done = $issued >= (float)$l['qty'] - 0.0005;
    q('UPDATE pr_lines SET qty_issued = ?, qty_reserved = GREATEST(0, qty_reserved - ?), status = ? WHERE id = ?', [$issued, $qty, $done ? 'issued' : $l['status'], $lid]);
    prq_event($rid, 'issue', ($it['code'] ?? '') . ' × ' . stk_qty($qty) . ($note !== '' ? ' · ' . $note : ''));
    if (!(int) scalar("SELECT COUNT(*) FROM pr_lines WHERE request_id = ? AND status NOT IN ('issued','cancelled')", [$rid])) { q('UPDATE pr_requests SET status = ? WHERE id = ?', ['closed', $rid]); prq_event($rid, 'close'); prq_notify([(int)$r['requester_id']], $rid, 'prq_closed', t('prq.nf_closed', ['no' => $r['pr_no']])); }
    cache_bump_later('inv'); cache_bump_later('eng');
}
/** Cancel (requester on draft/submitted, procurement any open): reservations released. */
function prq_cancel(int $rid, string $note): void {
    require_prq(); $r = prq_load($rid); $u = current_user();
    $own = (int)$r['requester_id'] === (int)$u['id'] && in_array($r['status'], ['draft', 'submitted'], true);
    if (!$r || !($own || (pr_admin() && in_array($r['status'], ['draft', 'submitted', 'mgmt', 'approved'], true)))) throw new RuntimeException(t('err.noaccess'));
    foreach (all("SELECT * FROM pr_lines WHERE request_id = ? AND qty_reserved > 0", [$rid]) as $l) q('UPDATE inv_stock SET qty_reserved = GREATEST(0, qty_reserved - ?) WHERE item_id = ? AND warehouse_id = ?', [(float)$l['qty_reserved'], (int)$l['item_id'], (int)$l['warehouse_id']]);
    q("UPDATE pr_lines SET qty_reserved = 0, status = 'cancelled' WHERE request_id = ? AND status NOT IN ('issued')", [$rid]);
    q('UPDATE pr_requests SET status = ? WHERE id = ?', ['cancelled', $rid]); prq_event($rid, 'cancel', $note ?: null);
    if (!$own) prq_notify([(int)$r['requester_id']], $rid, 'prq_cancelled', t('prq.nf_cancelled', ['no' => $r['pr_no']]), $note ?: null);
    cache_bump_later('inv');
}
/** Sub-project summary for the Procurement tab and the financial views: open requisitions, committed estimate, to-buy lines. */
function prq_sub_summary(int $sid): array {
    if (!prq_has()) return ['n' => 0, 'open' => 0, 'committed' => 0.0, 'to_buy' => 0];
    return ['n' => (int) scalar('SELECT COUNT(*) FROM pr_requests WHERE subproject_id = ?', [$sid]),
            'open' => (int) scalar("SELECT COUNT(*) FROM pr_requests WHERE subproject_id = ? AND status IN ('submitted','mgmt','approved')", [$sid]),
            'committed' => prq_budget($sid)['committed'],
            'to_buy' => (int) scalar("SELECT COUNT(*) FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id WHERE r.subproject_id = ? AND l.status = 'to_buy'", [$sid])];
}
/** Item picker (JSON): code · description · unit · on hand · free · last price. */
function prq_item_search(string $q, int $limit = 15): array {
    $rows = stk_items(['q' => $q, 'status' => 'active'], $limit); $out = [];
    foreach ($rows as $r) $out[] = ['id' => (int)$r['id'], 'code' => $r['code'], 'desc' => $r['description'], 'maker' => $r['maker'], 'pn' => $r['factory_code'], 'unit' => $r['unit'], 'on_hand' => (float)$r['on_hand'], 'free' => (float)$r['on_hand'] - (float)$r['reserved'], 'price' => stk_price_visible() && $r['last_price'] !== null ? (float)$r['last_price'] : null, 'cur' => $r['currency']];
    return $out;
}

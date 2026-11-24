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

function prq_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'pr_requests'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Sub-project PM, project PM or the user's discipline manager scope: anyone who runs a project may raise a requisition for it. */
/* ------------------------------------------------------------------ R97-084 — purchases of the IT department
 * People of the IT department (the staff roles and disciplines ticked in Settings › Procurement; by name until saved) who have no procurement
 * right raise requisitions, RFQs and POs — only for the sub-projects marked "Open for IT purchases" (subprojects.it_purchase). The requisition
 * is approved by procurement / management as any other; an order of the IT department takes its lines from approved requisitions only. */
const IT_BUY_RX = '/\b(it|ict|information technology|informatics)\b|πληροφορ/u';
function it_buy_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM subprojects LIKE 'it_purchase'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** ['roles' => [ids], 'disc' => [ids], 'saved' => bool] */
function it_buy_positions(): array {
    static $A = null; if ($A !== null) return $A; $s = json_decode((string) cp_setting('it_buy_pos', ''), true);
    if (is_array($s) && isset($s['roles'], $s['disc'])) return $A = ['roles' => array_map('intval', (array)$s['roles']), 'disc' => array_map('intval', (array)$s['disc']), 'saved' => true];
    $A = ['roles' => [], 'disc' => [], 'saved' => false];
    try { foreach (all('SELECT id, name FROM staff_roles WHERE is_active = 1') as $r) if (preg_match(IT_BUY_RX, mb_strtolower((string)$r['name']))) $A['roles'][] = (int)$r['id'];
          foreach (all('SELECT id, name FROM disciplines WHERE is_active = 1') as $r) if (preg_match(IT_BUY_RX, mb_strtolower((string)$r['name']))) $A['disc'][] = (int)$r['id']; } catch (Throwable $e) {}
    return $A;
}
/** A person of the IT department without a procurement or warehouse right: works inside the sub-projects opened for IT only. */
function it_buyer(): bool {
    static $v = null; if ($v !== null) return $v; $u = current_user();
    if (!$u || !is_staff() || !it_buy_has() || !prq_has() || !stk_has() || pr_can()) return $v = false; $A = it_buy_positions();
    return $v = (!empty($u['staff_role_id']) && in_array((int)$u['staff_role_id'], $A['roles'], true)) || (!empty($u['discipline_id']) && in_array((int)$u['discipline_id'], $A['disc'], true));
}
function it_sub_ok(?int $sid): bool { return $sid && it_buy_has() && (bool) scalar('SELECT 1 FROM subprojects WHERE id = ? AND it_purchase = 1', [$sid]); }
function prq_is_pm(?int $pid = null): bool {
    $u = current_user(); if (!$u || !is_staff()) return false; static $c = [];
    $k = (int)$pid; if (!isset($c[$k])) { try { $c[$k] = $pid ? (bool) scalar('SELECT 1 FROM projects WHERE id = ? AND pm_user_id = ?', [$pid, (int)$u['id']]) : (bool) scalar('SELECT 1 FROM projects WHERE pm_user_id = ? LIMIT 1', [(int)$u['id']]); } catch (Throwable $e) { $c[$k] = false; } }
    return $c[$k];
}
/** Who may raise a requisition: procurement / warehouse / management, a PM, or staff whose discipline is enabled in Settings › Procurement. */
function prq_can(): bool {
    static $v = null; if ($v !== null) return $v;
    $u = current_user(); if (!$u || !is_staff() || !prq_has() || !stk_has()) return $v = false;
    if (pr_can() || is_cp_admin() || prq_is_pm() || it_buyer()) return $v = true;
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
/* ---- R97-239: priority of a requisition (the same four steps as payment requests) and the vessel of its project ---- */
const PRQ_PRIORITIES = ['urgent', 'high', 'normal', 'low'];
const PRQ_PRIO_ORDER = "FIELD(r.priority, 'urgent', 'high', 'normal', 'low')";
function prq_prio_has(): bool { static $h = null; if ($h === null) { try { $h = prq_has() && (bool) scalar("SHOW COLUMNS FROM pr_requests LIKE 'priority'"); } catch (Throwable $e) { $h = false; } } return $h; }
function prq_prio_pill(?string $p): string { $p = in_array($p, PRQ_PRIORITIES, true) ? $p : 'normal'; return pill(['urgent' => 'bad', 'high' => 'warn', 'normal' => 'mute', 'low' => 'mute'][$p], t('si.prio_' . $p)); }
/** Vessel · project › sub-project of a requisition row (PRQ_SELECT), one line. */
function prq_scope_text(array $r): string { return implode(' · ', array_filter([trim((string)($r['vessel'] ?? '')), trim((string)($r['project_code'] ?? '') . ' ' . (string)($r['project_name'] ?? '')) . (!empty($r['subproject_id']) ? ' › ' . trim((string)($r['sub_code'] ?? '') . ' ' . (string)($r['sub_name'] ?? '')) : '')], 'strlen')); }
/** The highest priority among the requisitions a purchase order buys for ('' when it buys for none or the column is missing). */
function prq_po_priority(int $orderId): string { if (!prq_prio_has()) return ''; try { return (string) (scalar("SELECT r.priority FROM po_lines pl JOIN pr_lines l ON l.id = pl.pr_line_id JOIN pr_requests r ON r.id = l.request_id WHERE pl.order_id = ? ORDER BY " . PRQ_PRIO_ORDER . " LIMIT 1", [$orderId]) ?: ''); } catch (Throwable $e) { return ''; } }
/** Change the priority of a requisition that is not finished: the requester, Procurement or Management. */
function prq_priority_set(int $rid, string $prio): void {
    require_prq(); $r = prq_load($rid); $u = current_user(); if (!$r || !prq_prio_has() || !in_array($prio, PRQ_PRIORITIES, true) || !in_array($r['status'], ['draft', 'submitted', 'mgmt', 'approved'], true)) throw new RuntimeException(t('err.noaccess'));
    if (!((int)$r['requester_id'] === (int)$u['id'] || pr_admin() || (function_exists('mgmt_can') && mgmt_can()))) throw new RuntimeException(t('err.noaccess'));
    if ($prio === (string)$r['priority']) return; q('UPDATE pr_requests SET priority = ? WHERE id = ?', [$prio, $rid]); prq_event($rid, 'priority', t('si.prio_' . $r['priority']) . ' → ' . t('si.prio_' . $prio));
}
const PRQ_SELECT = "SELECT r.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, d.name AS discipline, (SELECT v.name FROM vessels v WHERE v.id = p.vessel_id) AS vessel,
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
    if (!empty($f['priority']) && in_array($f['priority'], PRQ_PRIORITIES, true) && prq_prio_has()) { $w[] = 'r.priority = ?'; $a[] = $f['priority']; }
    return all(PRQ_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY ' . (prq_prio_has() && in_array($f['status'] ?? '', ['open', 'pending', 'submitted', 'mgmt', 'approved'], true) ? PRQ_PRIO_ORDER . ', ' : '') . 'r.id DESC', $a);
}
function prq_lines(int $rid): array { return all('SELECT l.*, i.code AS item_code, i.description AS item_desc, i.unit AS item_unit, i.last_price, i.currency AS item_cur, (SELECT COALESCE(SUM(s.qty_on_hand),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS on_hand, (SELECT COALESCE(SUM(s.qty_reserved),0) FROM inv_stock s WHERE s.item_id = l.item_id) AS reserved_all FROM pr_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY l.seq, l.id', [$rid]); }
function prq_events(int $rid): array { return all('SELECT * FROM pr_events WHERE request_id = ? ORDER BY id DESC', [$rid]); }
function prq_event(int $rid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO pr_events (request_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)', [$rid, $ev, $u['id'] ?? null, $u['full_name'] ?? null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $note !== null ? mb_substr($note, 0, 600) : null]);  if (function_exists('proc_feed_later')) { try { proc_feed_later((int) scalar('SELECT subproject_id FROM pr_requests WHERE id = ?', [$rid]) ?: null); } catch (Throwable $e) {} } }
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
function prq_line_estimate(array $l, string $src = 'last'): array {
    $bp = isset($l['bom_price']) && $l['bom_price'] !== null ? (float)$l['bom_price'] : null;   // R97-137: the unit price of the BOM file
    if ($src === 'bom' && $bp !== null) return ['price' => $bp, 'origin' => t('prq.est_bom')];
    if (!empty($l['item_id'])) { $it = stk_item((int)$l['item_id']); if ($it) { $p = stk_price_proposal($it); if ($p['price'] !== null) return ['price' => $p['price'], 'origin' => mb_substr($p['origin'], 0, 160)]; if ($it['last_price'] !== null) return ['price' => (float)$it['last_price'], 'origin' => t('stk.rule_last')]; } }
    if ($bp !== null) return ['price' => $bp, 'origin' => t('prq.est_bom')];   // nothing bought yet: the BOM price
    return ['price' => $l['est_price'] !== null ? (float)$l['est_price'] : null, 'origin' => $l['est_price'] !== null ? t('prq.est_typed') : null];
}
function prq_recalc(int $rid): float {
    $t = 0.0; $src = function_exists('bom_price_has') && bom_price_has() ? (string) scalar('SELECT price_src FROM pr_requests WHERE id = ?', [$rid]) : 'last';
    foreach (all("SELECT * FROM pr_lines WHERE request_id = ? AND status <> 'cancelled'", [$rid]) as $l) {
        $e = prq_line_estimate($l, $src);
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
    if (it_buyer() && !is_cp_admin() && !prq_is_pm($pid) && !it_sub_ok($sid)) throw new RuntimeException(t('it.e_sub'));   // R97-084
    $purpose = in_array($_POST['purpose'] ?? '', PRQ_PURPOSE, true) ? $_POST['purpose'] : 'project';
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160) ?: null;
    $need = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['need_date'] ?? '')) ? $_POST['need_date'] : null;
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    $prio = in_array($_POST['priority'] ?? '', PRQ_PRIORITIES, true) ? (string)$_POST['priority'] : ''; if ($prio === '') { if (array_key_exists('priority', $_POST) && prq_prio_has()) throw new RuntimeException(t('prq.need_priority')); $prio = 'normal'; }   /* R97-239: the portal form asks for it; requisitions made by the Service app or from a stock count are Normal */
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
    if (prq_prio_has() && (array_key_exists('priority', $_POST) || !isset($r))) q('UPDATE pr_requests SET priority = ? WHERE id = ?', [$prio, $id]);   // an edit that does not send the field keeps the priority
    return $id;
}
function prq_line_save_from_post(): int {
    require_prq(); $rid = (int)($_POST['request_id'] ?? 0); $r = prq_load($rid); if (!$r || !prq_can_edit($r)) throw new RuntimeException(t('err.noaccess'));
    $lid = (int)($_POST['line_id'] ?? 0);
    $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '0')); if ($qty <= 0) throw new RuntimeException(t('prq.need_qty'));
    if (!empty($_POST['new_item']) && pr_admin() && function_exists('stk_item_quick_create')) $_POST['item_id'] = stk_item_quick_create(['code' => $_POST['new_code'] ?? '', 'description' => $_POST['description'] ?? '', 'maker' => $_POST['maker'] ?? '', 'part_no' => $_POST['part_no'] ?? '', 'unit' => $_POST['unit'] ?? 'pcs', 'category_id' => $_POST['new_category_id'] ?? 0]);   // R86-003: "+ New item" from the requisition line (Procurement)
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
    prq_notify(prq_proc_ids(), $rid, 'prq_submitted', t('prq.nf_submitted', ['no' => $r['pr_no']]), (prq_prio_has() && ($r['priority'] ?? 'normal') !== 'normal' ? '[' . mb_strtoupper(t('si.prio_' . $r['priority'])) . '] ' : '') . ($r['title'] ? $r['title'] . ' · ' : '') . prq_scope_text($r) . ' · ' . prq_money($est));   // R97-239: priority, vessel, project and sub-project
    cache_bump_later('inv');
}
/** Procurement decides. Within budget → approved (stock reserved). Over budget → to management with the reason. */
function prq_decide(int $rid, string $decision, string $note): void { stk_tx(fn() => prq_decide_x($rid, $decision, $note)); }
function prq_decide_x(int $rid, string $decision, string $note): void {
    require_pr_admin(); $r = prq_load($rid); if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('err.noaccess'));
    $note = mb_substr(trim($note), 0, 400); $u = current_user();
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q('UPDATE pr_requests SET status = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ?', ['rejected', $u['id'], $u['full_name'], $note, $rid]); prq_event($rid, 'reject', $note); prq_notify([(int)$r['requester_id']], $rid, 'prq_rejected', t('prq.nf_rejected', ['no' => $r['pr_no']]), $note); cache_bump_later('inv'); return; }
    $est = prq_recalc($rid); $b = prq_budget($r['subproject_id'] ? (int)$r['subproject_id'] : null, $rid);
    $over = $r['purpose'] === 'project' && ($b['parts_budget'] === null || $b['parts_budget'] <= 0 || $est > $b['available']);
    q('UPDATE pr_requests SET approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ?, budget_parts = ?, budget_committed = ?, budget_actual = ?, over_budget = ? WHERE id = ?', [$u['id'], $u['full_name'], $note ?: null, $b['parts_budget'], $b['committed'], $b['actual'], $over ? 1 : 0, $rid]);
    if ($over) {
        if ($note === '') throw new RuntimeException(t('prq.need_note_over'));
        q('UPDATE pr_requests SET status = ? WHERE id = ?', ['mgmt', $rid]); prq_event($rid, 'to_mgmt', $note);
        prq_notify(prq_mgmt_ids(), $rid, 'prq_mgmt', t('prq.nf_mgmt', ['no' => $r['pr_no']]), t('prq.over_by', ['v' => prq_money(max(0.0, $est - (float)($b['available'] ?? 0)))]) . ' · ' . $note);
    } else { prq_approve_apply($rid, 'approve', $note); }
    cache_bump_later('inv');
}
/** Management decides an over-budget request. */
function prq_mgmt_decide(int $rid, string $decision, string $note): void { stk_tx(fn() => prq_mgmt_decide_x($rid, $decision, $note)); }
function prq_mgmt_decide_x(int $rid, string $decision, string $note): void {
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
            if ($row) { $res = min((float)$l['qty'], (float)$row['free']); $wh = (int)$row['warehouse_id']; }
        }
        $st = $res >= (float)$l['qty'] - 0.0005 ? 'reserved' : 'to_buy';
        q('UPDATE pr_lines SET qty_reserved = 0, warehouse_id = ?, status = ? WHERE id = ?', [$wh, $st, (int)$l['id']]);
        if ($res > 0.0005) prq_rsv_add((int)$l['id'], (int)$l['item_id'], (int)$wh, $res);   // R97-215
    }
    q('UPDATE pr_requests SET status = ? WHERE id = ?', ['approved', $rid]); prq_event($rid, $ev, $note ?: null);
    $to = [(int)$r['requester_id']]; if ($ev === 'mgmt_approve' && $r['approved_by']) $to[] = (int)$r['approved_by']; $to = array_merge($to, prq_proc_ids());
    prq_notify(array_values(array_unique($to)), $rid, 'prq_approved', t('prq.nf_approved', ['no' => $r['pr_no']]), $note ?: null);
}
/** Warehouse issues a reserved line (part or whole): stock out, reservation released, parts cost on the sub-project. */
function prq_issue(int $rid, int $lid, float $qty, string $note = '', array $o = []): ?int { return stk_tx(fn() => prq_issue_x($rid, $lid, $qty, $note, $o)); }
function prq_issue_x(int $rid, int $lid, float $qty, string $note = '', array $o = []): ?int {   // R88-001: $o = ref_no / ref_kind / ref_id of a delivery note; returns the movement id
    require_pr_wh(); $r = prq_load($rid); $l = one('SELECT * FROM pr_lines WHERE id = ? AND request_id = ? FOR UPDATE', [$lid, $rid]);
    if (!$r || !$l || $r['status'] !== 'approved' || !in_array($l['status'], ['reserved', 'to_buy', 'received'], true) || empty($l['item_id'])) throw new RuntimeException(t('err.noaccess'));
    $left = (float)$l['qty_reserved']; if ($qty <= 0 || $qty > $left + 0.0005) throw new RuntimeException(t('prq.issue_max', ['q' => stk_qty(max(0.0, $left))]));
    $it = stk_item((int)$l['item_id']); $u = current_user();
    /* R97-215: the goods leave the warehouse that holds the reservation. A delivery note takes them from one warehouse (its own first); an issue from the requisition page may take from several. */
    $taken = prq_rsv_take($l, $qty, isset($o['warehouse_id']) ? (int)$o['warehouse_id'] : null, !empty($o['single']));
    if (abs(array_sum($taken) - $qty) > 0.0005) throw new RuntimeException(t('prq.issue_split'));
    $mid = null; foreach ($taken as $wh => $tq) $mid = stk_move((int)$l['item_id'], (int)$wh, 'out', $tq, ['unit_price' => $it['last_price'], 'note' => $r['pr_no'] . ($note !== '' ? ' · ' . $note : ''), 'project_id' => (int)$r['project_id'], 'subproject_id' => $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'reason' => 'issue', 'ref_kind' => $o['ref_kind'] ?? 'pr', 'ref_id' => $o['ref_id'] ?? $rid, 'ref_no' => $o['ref_no'] ?? null, 'company_id' => $o['company_id'] ?? null, 'item_consumable' => !empty($it['is_consumable'])]);
    if ($r['subproject_id'] && function_exists('bg_has') && bg_has() && $it['last_price'] && (float)$l['qty_received'] <= 0.0005) { /* goods bought on a PO for this sub-project already carry their cost on the PO row (R80-004) */ q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, created_by, created_name) VALUES (?,CURDATE(),?,?,?,?,?,?,?,?)', [(int)$r['subproject_id'], 'parts', t('stk.stock'), mb_substr($it['code'] . ' · ' . $it['description'] . ' × ' . stk_qty($qty), 0, 255), mb_substr($r['pr_no'], 0, 60), round($qty * (float)$it['last_price'], 2), $it['currency'], $u['id'], $u['full_name']]); if (function_exists('eng_rows_reset')) eng_rows_reset(); }
    $issued = (float)$l['qty_issued'] + $qty; $done = $issued >= (float)$l['qty'] - 0.0005;
    q('UPDATE pr_lines SET qty_issued = ?, status = ? WHERE id = ?', [$issued, $done ? 'issued' : $l['status'], $lid]);
    if ($done) { $l2 = one('SELECT * FROM pr_lines WHERE id = ?', [$lid]); if ((float)$l2['qty_reserved'] > 0.0005) prq_rsv_take($l2, (float)$l2['qty_reserved']); }   // a finished line holds nothing: what was received above the need becomes free stock
    prq_event($rid, 'issue', ($it['code'] ?? '') . ' × ' . stk_qty($qty) . ($note !== '' ? ' · ' . $note : ''));
    if (!(int) scalar("SELECT COUNT(*) FROM pr_lines WHERE request_id = ? AND status NOT IN ('issued','cancelled')", [$rid])) { q('UPDATE pr_requests SET status = ? WHERE id = ?', ['closed', $rid]); prq_event($rid, 'close'); prq_notify([(int)$r['requester_id']], $rid, 'prq_closed', t('prq.nf_closed', ['no' => $r['pr_no']])); }
    cache_bump_later('inv'); cache_bump_later('eng'); return $mid;
}
/** Issue a requisition line against a delivery note (R88-001): the request id comes from the line. */
function prq_issue_dn(int $lid, float $qty, string $dnNo, int $dnId, ?int $companyId = null, ?int $whId = null): ?int { $rid = (int) scalar('SELECT request_id FROM pr_lines WHERE id = ?', [$lid]); if (!$rid) throw new RuntimeException(t('err.notfound')); return prq_issue($rid, $lid, $qty, $dnNo, ['ref_kind' => 'dn', 'ref_id' => $dnId, 'ref_no' => $dnNo, 'company_id' => $companyId, 'warehouse_id' => $whId, 'single' => true]); }
/* ------------------------------------------------------------------ reservations per warehouse (GAS-CP-R97-215) */
/** A line may hold stock in more than one warehouse: reserved from stock in one, bought goods received in another. Table pr_reservations keeps the split; without it (migration not applied) everything counts in the warehouse of the line, as before. */
function prq_rsv_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'pr_reservations'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** [warehouse id => quantity] held by a line (needs id, qty_reserved, warehouse_id). */
function prq_rsv_of(array $l): array {
    $out = [];
    if (prq_rsv_has()) foreach (all('SELECT warehouse_id, qty FROM pr_reservations WHERE pr_line_id = ? AND qty > 0.0005 ORDER BY qty DESC', [(int)$l['id']]) as $x) $out[(int)$x['warehouse_id']] = (float)$x['qty'];
    $rest = round((float)$l['qty_reserved'] - array_sum($out), 3);
    if ($rest < -0.0005) { $cap = (float)$l['qty_reserved']; foreach ($out as $w => $h) { $out[$w] = max(0.0, min($h, $cap)); $cap -= $out[$w]; if ($out[$w] <= 0.0005) unset($out[$w]); } }   // the split can never say more than the line holds
    if ($rest > 0.0005) { $wh = (int)($l['warehouse_id'] ?: stk_default_wh()); $out[$wh] = ($out[$wh] ?? 0.0) + $rest; }   // reserved before the table existed
    return $out;
}
/** Reserve: stock total, line total and the split move together. */
function prq_rsv_add(int $lid, int $itemId, int $whId, float $qty): void {
    if ($qty <= 0.0005 || !$itemId || !$whId) return;
    q('INSERT INTO inv_stock (item_id, warehouse_id, qty_on_hand) VALUES (?,?,0) ON DUPLICATE KEY UPDATE item_id = item_id', [$itemId, $whId]);
    q('UPDATE inv_stock SET qty_reserved = qty_reserved + ? WHERE item_id = ? AND warehouse_id = ?', [$qty, $itemId, $whId]);
    q('UPDATE pr_lines SET qty_reserved = qty_reserved + ?, warehouse_id = COALESCE(warehouse_id, ?) WHERE id = ?', [$qty, $whId, $lid]);
    if (prq_rsv_has()) q('INSERT INTO pr_reservations (pr_line_id, item_id, warehouse_id, qty) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)', [$lid, $itemId, $whId, $qty]);
}
/** Take a quantity off the reservation of a line. $prefer: that warehouse first. $single: all of it from one warehouse, or nothing. Returns [warehouse id => quantity taken]. */
function prq_rsv_take(array $l, float $qty, ?int $prefer = null, bool $single = false): array {
    if ($qty <= 0.0005 || empty($l['item_id'])) return []; $held = prq_rsv_of($l);
    if ($prefer && isset($held[$prefer])) $held = [$prefer => $held[$prefer]] + $held;
    if ($single) { $pick = null; foreach ($held as $w => $h) if ($h >= $qty - 0.0005) { $pick = $w; break; } if ($pick === null) return []; $held = [$pick => $held[$pick]]; }
    $taken = []; $left = $qty;
    foreach ($held as $w => $h) { if ($left <= 0.0005) break; $tq = min($h, $left);
        q('UPDATE inv_stock SET qty_reserved = GREATEST(0, qty_reserved - ?) WHERE item_id = ? AND warehouse_id = ?', [$tq, (int)$l['item_id'], (int)$w]);
        if (prq_rsv_has()) { q('UPDATE pr_reservations SET qty = GREATEST(0, qty - ?) WHERE pr_line_id = ? AND warehouse_id = ?', [$tq, (int)$l['id'], (int)$w]); q('DELETE FROM pr_reservations WHERE pr_line_id = ? AND qty <= 0.0005', [(int)$l['id']]); }
        $taken[(int)$w] = $tq; $left -= $tq; }
    if ($taken) q('UPDATE pr_lines SET qty_reserved = GREATEST(0, qty_reserved - ?) WHERE id = ?', [array_sum($taken), (int)$l['id']]);
    return $taken;
}

/* ------------------------------------------------------------------ reservations: release, list, check and repair (GAS-CP-R97-213) */
/** Procurement gives the reserved quantity of a line back to free stock (the project no longer needs it). The line ends: issued when something was issued, cancelled otherwise; open "to buy" balance is dropped. */
function prq_line_release(int $rid, int $lid, string $note): float {
    $r = prq_load($rid); $l = one('SELECT * FROM pr_lines WHERE id = ? AND request_id = ?', [$lid, $rid]);
    if (!$r || !$l || !pr_admin() || $r['status'] !== 'approved' || (float)$l['qty_reserved'] <= 0.0005) throw new RuntimeException(t('err.noaccess'));
    $note = mb_substr(trim($note), 0, 200); if ($note === '') throw new RuntimeException(t('prq.need_reason'));
    if ((float)$l['qty_ordered'] - (float)$l['qty_received'] > 0.0005) throw new RuntimeException(t('prq.rel_on_order'));   // still coming on a purchase order: cancel the order line first
    $qty = (float)$l['qty_reserved']; $it = $l['item_id'] ? stk_item((int)$l['item_id']) : null; $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        prq_rsv_take($l, $qty);
        q('UPDATE pr_lines SET qty_reserved = 0, status = ? WHERE id = ?', [(float)$l['qty_issued'] > 0.0005 ? 'issued' : 'cancelled', $lid]);
        prq_event($rid, 'release', ($it['code'] ?? $l['description']) . ' × ' . stk_qty($qty) . ' · ' . $note);
        if (!(int) scalar("SELECT COUNT(*) FROM pr_lines WHERE request_id = ? AND status NOT IN ('issued','cancelled')", [$rid])) { q('UPDATE pr_requests SET status = ? WHERE id = ?', ['closed', $rid]); prq_event($rid, 'close'); }
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own) $pdo->rollBack(); throw $e; }
    cache_bump_later('inv'); cache_bump_later('eng'); return $qty;
}
/** Every reservation that is held now: which requisition holds how much of which item in which warehouse. */
function prq_reservations(?int $pmUserId = null): array {   // R97-214: with a user id, only the projects that user manages
    $rows = all("SELECT l.id AS line_id, l.request_id, l.item_id, l.warehouse_id, l.qty, l.qty_reserved, l.qty_issued, l.qty_received, l.status, r.pr_no, r.status AS r_status, r.title, r.requester_name, r.created_at, i.code AS item_code, i.description AS item_desc, i.unit, w.code AS wh_code, p.project_code, s.code AS sub_code
                FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id LEFT JOIN inv_items i ON i.id = l.item_id LEFT JOIN inv_warehouses w ON w.id = l.warehouse_id LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id
                WHERE l.qty_reserved > 0.0005" . ($pmUserId ? ' AND p.pm_user_id = ' . (int)$pmUserId : '') . " ORDER BY i.code, r.pr_no LIMIT 2000");
    $out = []; foreach ($rows as $l) { $held = $l['item_id'] ? prq_rsv_of(['id' => $l['line_id'], 'qty_reserved' => $l['qty_reserved'], 'warehouse_id' => $l['warehouse_id']]) : []; if (count($held) <= 1 && (!$held || (int)array_key_first($held) === (int)$l['warehouse_id'])) { $out[] = $l; continue; }
        foreach ($held as $w => $hq) { $x = $l; $x['warehouse_id'] = $w; $x['qty_reserved'] = $hq; $x['wh_code'] = (string) scalar('SELECT code FROM inv_warehouses WHERE id = ?', [$w]); $out[] = $x; } }
    return $out;
}
/** Compares the reserved quantity of the stock (inv_stock) with what the requisition lines hold. Returns the rows that disagree and the lines that hold a reservation on a requisition that is not approved. */
function prq_reserve_audit(): array {
    $want = []; foreach (all("SELECT l.id, l.item_id, l.warehouse_id, l.qty_reserved FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id WHERE l.qty_reserved > 0.0005 AND r.status = 'approved' AND l.item_id IS NOT NULL") as $x) foreach (prq_rsv_of($x) as $w => $hq) { $k = (int)$x['item_id'] . ':' . (int)$w; $want[$k] = ($want[$k] ?? 0.0) + $hq; }   // R97-215: per warehouse
    $diff = []; $seen = [];
    foreach (all('SELECT s.item_id, s.warehouse_id, s.qty_on_hand, s.qty_reserved, i.code, i.description, i.unit, w.code AS wh_code FROM inv_stock s JOIN inv_items i ON i.id = s.item_id JOIN inv_warehouses w ON w.id = s.warehouse_id') as $s) {
        $k = (int)$s['item_id'] . ':' . (int)$s['warehouse_id']; $seen[$k] = 1; $w = $want[$k] ?? 0.0; $h = (float)$s['qty_reserved'];
        if (abs($w - $h) > 0.0005 || $h > (float)$s['qty_on_hand'] + 0.0005) $diff[] = ['item_id' => (int)$s['item_id'], 'warehouse_id' => (int)$s['warehouse_id'], 'code' => $s['code'], 'description' => $s['description'], 'unit' => $s['unit'], 'wh_code' => $s['wh_code'], 'on_hand' => (float)$s['qty_on_hand'], 'stock' => $h, 'lines' => $w, 'short' => $w > (float)$s['qty_on_hand'] + 0.0005];
    }
    foreach ($want as $k => $w) if (empty($seen[$k])) { [$i, $wh] = array_map('intval', explode(':', $k)); $it = one('SELECT code, description, unit FROM inv_items WHERE id = ?', [$i]) ?: ['code' => '#' . $i, 'description' => '', 'unit' => '']; $diff[] = ['item_id' => $i, 'warehouse_id' => $wh, 'code' => $it['code'], 'description' => $it['description'], 'unit' => $it['unit'], 'wh_code' => (string) scalar('SELECT code FROM inv_warehouses WHERE id = ?', [$wh]), 'on_hand' => 0.0, 'stock' => 0.0, 'lines' => $w, 'short' => true]; }
    $stale = all("SELECT l.id AS line_id, l.request_id, l.qty_reserved, r.pr_no, r.status AS r_status, i.code FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.qty_reserved > 0.0005 AND (r.status <> 'approved' OR l.item_id IS NULL OR l.status IN ('issued','cancelled'))");
    /* R97-215: quantity on hand against the movements — every change of stock is a movement, so the two must agree */
    $ledger = all('SELECT s.item_id, s.warehouse_id, s.qty_on_hand, COALESCE(m.q, 0) AS moves, i.code, i.description, i.unit, w.code AS wh_code FROM inv_stock s JOIN inv_items i ON i.id = s.item_id JOIN inv_warehouses w ON w.id = s.warehouse_id LEFT JOIN (SELECT item_id, warehouse_id, SUM(qty) AS q FROM inv_moves GROUP BY item_id, warehouse_id) m ON m.item_id = s.item_id AND m.warehouse_id = s.warehouse_id WHERE ABS(s.qty_on_hand - COALESCE(m.q, 0)) > 0.0005 OR s.qty_on_hand < -0.0005 ORDER BY i.code LIMIT 500');
    $split = prq_rsv_has() ? all('SELECT l.id AS line_id, l.request_id, l.qty_reserved, x.q AS rows_q FROM pr_lines l JOIN (SELECT pr_line_id, SUM(qty) AS q FROM pr_reservations GROUP BY pr_line_id) x ON x.pr_line_id = l.id WHERE x.q - l.qty_reserved > 0.0005') : [];
    return ['diff' => $diff, 'stale' => $stale, 'ledger' => $ledger, 'split' => $split];
}
/** Repair: reservations of requisitions that are not approved are cleared, and the reserved quantity of the stock is set to what the approved requisition lines hold. Quantities on hand are never changed. */
function prq_reserve_repair(): array {
    if (!pr_admin()) throw new RuntimeException(t('err.noaccess')); $a = prq_reserve_audit(); $n = ['stock' => 0, 'lines' => 0]; if (!$a['diff'] && !$a['stale'] && !$a['split']) return $n;
    $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ($a['stale'] as $x) { q('UPDATE pr_lines SET qty_reserved = 0 WHERE id = ?', [(int)$x['line_id']]); if (prq_rsv_has()) q('DELETE FROM pr_reservations WHERE pr_line_id = ?', [(int)$x['line_id']]); prq_event((int)$x['request_id'], 'release', t('prq.rsv_repair_ev', ['q' => stk_qty((float)$x['qty_reserved'])])); $n['lines']++; }
        foreach ($a['split'] as $x) { $l = one('SELECT id, item_id, qty_reserved, warehouse_id FROM pr_lines WHERE id = ?', [(int)$x['line_id']]); $held = prq_rsv_of($l); q('DELETE FROM pr_reservations WHERE pr_line_id = ?', [(int)$l['id']]); foreach ($held as $w => $hq) q('INSERT INTO pr_reservations (pr_line_id, item_id, warehouse_id, qty) VALUES (?,?,?,?)', [(int)$l['id'], (int)$l['item_id'], (int)$w, $hq]); $n['lines']++; }
        foreach (prq_reserve_audit()['diff'] as $d) { if (abs($d['lines'] - $d['stock']) <= 0.0005) continue;
            q('INSERT INTO inv_stock (item_id, warehouse_id, qty_on_hand) VALUES (?,?,0) ON DUPLICATE KEY UPDATE item_id = item_id', [$d['item_id'], $d['warehouse_id']]);
            q('UPDATE inv_stock SET qty_reserved = ? WHERE item_id = ? AND warehouse_id = ?', [$d['lines'], $d['item_id'], $d['warehouse_id']]); $n['stock']++; }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $u = current_user(); error_log('[prq] reservations repaired by user #' . (int)($u['id'] ?? 0) . ': stock rows ' . $n['stock'] . ', lines ' . $n['lines']);
    cache_bump_later('inv'); return $n;
}

/** R97-214: once a day (from 07:00, clock job) the check runs by itself; Procurement is told only when something does not agree. Returns null when it is not due. */
function prq_reserve_daily(): ?int {
    if (!prq_has() || !function_exists('cp_setting')) return null; $today = date('Y-m-d');
    if ((int) date('G') < 7 || str_starts_with((string) cp_setting('prq_rsv_last', ''), $today)) return null;
    $a = prq_reserve_audit(); $n = count($a['diff']) + count($a['stale']) + count($a['ledger']) + count($a['split']); cp_setting_set('prq_rsv_last', $today . ' ' . date('H:i') . '|' . $n);
    if ($n && function_exists('nf_push') && ($ids = prq_proc_ids())) {
        $codes = array_slice(array_values(array_unique(array_merge(array_column($a['ledger'], 'code'), array_column($a['diff'], 'code'), array_column($a['stale'], 'code')))), 0, 8);
        try { nf_push($ids, 'work:prq_rsv', 'prq:rsv:' . $today, t('prq.rsv_nf', ['n' => $n]), implode(', ', array_filter(array_map('strval', $codes))) ?: null, url('stock_reservations'), null, null); } catch (Throwable $e) {}
    }
    return $n;
}

/** Cancel (requester on draft/submitted, procurement any open): reservations released. */
function prq_cancel(int $rid, string $note): void { stk_tx(fn() => prq_cancel_x($rid, $note)); }
function prq_cancel_x(int $rid, string $note): void {
    require_prq(); $r = prq_load($rid); $u = current_user();
    $own = (int)$r['requester_id'] === (int)$u['id'] && in_array($r['status'], ['draft', 'submitted'], true);
    if (!$r || !($own || (pr_admin() && in_array($r['status'], ['draft', 'submitted', 'mgmt', 'approved'], true)))) throw new RuntimeException(t('err.noaccess'));
    if ((float) scalar('SELECT COALESCE(SUM(qty_issued), 0) + COALESCE(SUM(qty_received), 0) FROM pr_lines WHERE request_id = ?', [$rid]) > 0.0005) throw new RuntimeException(t('prq.cannot_cancel_issued'));   // R86-001: goods already issued or received — close it instead
    foreach (all("SELECT * FROM pr_lines WHERE request_id = ? AND qty_reserved > 0", [$rid]) as $l) prq_rsv_take($l, (float)$l['qty_reserved']);   // R97-215: from every warehouse that holds it
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
/** Items most recently requested or moved (the drop-down of the requisition line when nothing is typed).   R86-003 */
function prq_recent_items(int $limit = 20): array {
    $ids = array_map('intval', array_column(all('SELECT item_id, MAX(t) AS t FROM (SELECT item_id, MAX(id) AS t FROM pr_lines WHERE item_id IS NOT NULL GROUP BY item_id UNION ALL SELECT item_id, MAX(id) FROM inv_moves GROUP BY item_id) x GROUP BY item_id ORDER BY t DESC LIMIT ' . (int)$limit), 'item_id'));
    $rows = $ids ? stk_items(['status' => 'active', 'ids' => $ids], $limit) : []; if (count($rows) < $limit) $rows = array_merge($rows, stk_items(['status' => 'active', 'stock' => 'in'], $limit));
    $seen = []; return array_values(array_filter($rows, function ($r) use (&$seen) { if (isset($seen[$r['id']])) return false; return $seen[$r['id']] = true; }));
}
function prq_item_search(string $q, int $limit = 15, int $supplier = 0, bool $recent = false): array {
    $rows = $q === '' && $recent && !$supplier ? prq_recent_items($limit) : stk_items(array_filter(['q' => $q, 'status' => 'active', 'supplier' => $supplier ?: null]), $limit); $out = [];
    if ($q === '' && $supplier && count($rows) < $limit) $rows = array_merge($rows, array_slice(stk_items(['status' => 'active'], $limit * 2), 0, $limit - count($rows)));   // R86-002: dropdown on focus — the supplier's items first, then the rest
    if ($q !== '') {   // R97-115: the supplier only orders the list — an item of the inventory is always found: exact code or part number first, the supplier's items next, the rest of the inventory after
        $ex = all(STK_SELECT . " WHERE i.status <> 'blocked' AND (i.code = ? OR i.factory_code = ?) ORDER BY (i.code = ?) DESC, (i.status = 'active') DESC LIMIT 5", [$q, $q, $q]);
        if ($supplier && count($rows) < $limit) $rows = array_merge($rows, stk_items(['q' => $q, 'status' => 'active'], $limit));
        $rows = array_slice(array_merge($ex, $rows), 0, $limit + count($ex));
    }
    $seen = []; $rows = array_values(array_filter($rows, function ($r) use (&$seen) { if (isset($seen[$r['id']])) return false; return $seen[$r['id']] = true; }));
    $withAlts = !empty($_GET['alts']) && function_exists('stk_alternatives_of');
    foreach ($rows as $r) { $alts = [];
        if ($withAlts) foreach (stk_alternatives_of((int)$r['id']) as $a) { if ($a['status'] !== 'approved') continue; $ai = $a['alt_item_id'] ? stk_item((int)$a['alt_item_id']) : null; $alts[] = ['id' => $ai ? (int)$ai['id'] : 0, 'code' => $ai ? $ai['code'] : null, 'desc' => $ai ? $ai['description'] : ($a['description'] ?: trim(($a['maker'] ?? '') . ' ' . ($a['part_no'] ?? ''))), 'maker' => $ai ? $ai['maker'] : $a['maker'], 'pn' => $ai ? $ai['factory_code'] : $a['part_no'], 'unit' => $ai ? $ai['unit'] : $r['unit'], 'free' => $ai ? (float)$ai['on_hand'] - (float)$ai['reserved'] : 0, 'note' => $a['note']]; }
        $codes = array_values(array_filter([['k' => 'pn', 'v' => (string)($r['factory_code'] ?? '')], ['k' => 'barcode', 'v' => (string)($r['barcode'] ?? '')], ['k' => 'code', 'v' => (string)$r['code']]], fn($c) => $c['v'] !== ''));
        $out[] = ['codes' => $codes, 'alts' => $alts, 'id' => (int)$r['id'], 'code' => $r['code'], 'desc' => $r['description'], 'maker' => $r['maker'], 'pn' => $r['factory_code'], 'unit' => $r['unit'], 'on_hand' => (float)$r['on_hand'], 'free' => (float)$r['on_hand'] - (float)$r['reserved'], 'price' => stk_price_visible() && $r['last_price'] !== null ? (float)$r['last_price'] : null, 'cur' => $r['currency']]; }
    return $out;
}

/* ------------------------------------------------------------ R97-096 — the Procurement stage of a sub-project is fed from the procurement process */
/** Earned progress of the procurement of a sub-project, or null when it has no requisition and no purchase order (then the value of the PM stands).
 *  A requisition line counts 50 % when it is covered (ordered from a supplier or reserved from stock) and 100 % when it is in the warehouse (received, reserved from stock or issued).
 *  Lines of purchase orders raised without a requisition count the same way. Lines weigh by their value; when a value is missing, every line weighs the same.
 *  Requisitions still waiting for approval count with 0 %; drafts, rejected and cancelled ones do not count. */
function proc_sub_pct(int $sid): ?array {
    static $c = []; if (array_key_exists($sid, $c)) return $c[$sid]; if (!$sid) return $c[$sid] = null;
    $with = sub_ids_with('proc', ['SELECT DISTINCT subproject_id FROM pr_requests WHERE subproject_id IS NOT NULL', "SELECT DISTINCT subproject_id FROM po_orders WHERE subproject_id IS NOT NULL AND kind = 'po'"]); if ($with !== null && !isset($with[$sid])) return null;   // R97-104 (not kept in \$c: a later write in the same request must be seen)
    $L = []; $nPr = 0; $nPo = 0;
    try {
        if (function_exists('pr_has') ? pr_has() : true) foreach (all("SELECT l.qty, l.qty_reserved, l.qty_ordered, l.qty_received, l.qty_issued, l.est_price, l.status, r.status AS rst, r.id AS rid FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id
                      WHERE r.subproject_id = ? AND r.status IN ('submitted','mgmt','approved','closed') AND l.status <> 'cancelled' AND l.qty > 0", [$sid]) as $l) {
            $q = (float)$l['qty']; $prs[(int)$l['rid']] = 1;
            if (in_array($l['rst'], ['submitted', 'mgmt'], true)) { $d = 0.0; $cov = 0.0; }
            else { $d = in_array($l['status'], ['received', 'issued'], true) ? 1.0 : min(1.0, max((float)$l['qty_received'] + (float)$l['qty_reserved'], (float)$l['qty_issued']) / $q);
                   $cov = $l['status'] === 'ordered' && (float)$l['qty_ordered'] <= 0 ? 1.0 : min(1.0, ((float)$l['qty_ordered'] + (float)$l['qty_reserved']) / $q); }
            $L[] = [$d + 0.5 * max(0.0, $cov - $d), $q * (float)$l['est_price']];
        }
        $nPr = isset($prs) ? count($prs) : 0;
        foreach (all("SELECT l.qty, l.qty_received, l.qty_accepted, l.line_total, l.status, o.id AS oid FROM po_lines l JOIN po_orders o ON o.id = l.order_id
                      WHERE o.kind = 'po' AND o.subproject_id = ? AND o.status NOT IN ('draft','cancelled') AND l.pr_line_id IS NULL AND l.status <> 'cancelled' AND l.qty > 0", [$sid]) as $l) {
            $q = (float)$l['qty']; $pos[(int)$l['oid']] = 1; $d = $l['status'] === 'received' ? 1.0 : min(1.0, max((float)$l['qty_received'], (float)$l['qty_accepted']) / $q);
            $L[] = [$d + 0.5 * (1.0 - $d), (float)$l['line_total']];
        }
        $nPo = isset($pos) ? count($pos) : 0;
    } catch (Throwable $e) { return $c[$sid] = null; }
    if (!$L) return $c[$sid] = null;
    $byValue = !array_filter($L, fn($x) => $x[1] <= 0); $sum = 0.0; $w = 0.0; foreach ($L as [$p, $v]) { $k = $byValue ? $v : 1.0; $sum += $p * $k; $w += $k; }
    return $c[$sid] = ['pct' => (int) round($sum / $w * 100), 'lines' => count($L), 'pr' => $nPr, 'po' => $nPo];
}
/** After a requisition or a purchase order changed: write the stage of its sub-project at the end of the request, when all quantities are in. */
function proc_feed_later(?int $sid): void {
    static $set = null; if (!$sid || !function_exists('pd_feed_subproject')) return;
    if ($set === null) { $set = []; register_shutdown_function(function () use (&$set) { foreach (array_keys($set) as $s) { try { pd_feed_subproject((int)$s); } catch (Throwable $e) {} } }); }
    $set[$sid] = 1;
}
/** Once after the update: the stage of every sub-project that already has requisitions or purchase orders. */
function proc_feed_all(): int {
    $n = 0; try { foreach (all("SELECT DISTINCT subproject_id FROM pr_requests WHERE subproject_id IS NOT NULL UNION SELECT DISTINCT subproject_id FROM po_orders WHERE subproject_id IS NOT NULL AND kind = 'po'") as $r) { pd_feed_subproject((int)$r['subproject_id']); $n++; } } catch (Throwable $e) {}
    return $n;
}

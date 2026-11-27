<?php
/** Delivery notes — GAS-CP-R88-001
 *  A delivery note (DN) documents goods leaving the warehouse: to a project (site / vessel), to a customer organisation, to production,
 *  or returned to a supplier. Every line with an inventory item books the stock movement (reason by kind) with the DN number as
 *  reference; a line from a requisition is issued against it. Goods receipt lines can be routed per line "to stock" or
 *  "to the project" — the latter books the stock in and straight out again on a DN created with the receipt.
 *  Tables: inv_delivery_notes · inv_dn_lines · po_receipt_lines.dest / dn_id.   Numbers: <prefix>-DN-<yy>-<nnnn> (doc_sequences).
 */
declare(strict_types=1);

const DN_KINDS = ['project', 'customer', 'production', 'return', 'transfer'];   // R97-279: transfer = between two warehouses, for a project or as free stock
const DN_DELIVERY = ['open', 'sent', 'accepted', 'disputed', 'transit', 'received'];
const DN_REASON = ['project' => 'issue', 'customer' => 'delivery_customer', 'production' => 'delivery_production', 'return' => 'manual'];

function dn_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) scalar("SHOW TABLES LIKE 'inv_delivery_notes'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** R97-279: acceptance, transfers and the log need the migration. Before it the module works as it did. */
function dn_x_has(): bool { static $h = null; if ($h === null) { try { $h = dn_has() && (bool) scalar("SHOW COLUMNS FROM inv_delivery_notes LIKE 'delivery'") && (bool) scalar("SHOW TABLES LIKE 'inv_dn_events'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** R97-280: a note can name the member of staff who receives the goods (the engineer on board); that person accepts it in the Service app. */
function dn_x2_has(): bool { static $h = null; if ($h === null) { try { $h = dn_x_has() && (bool) scalar("SHOW COLUMNS FROM inv_delivery_notes LIKE 'to_user_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** R97-281: a line remembers the line of the transfer note it came from, so a note can be sent on in parts. */
function dn_x3_has(): bool { static $h = null; if ($h === null) { try { $h = dn_x_has() && (bool) scalar("SHOW COLUMNS FROM inv_dn_lines LIKE 'src_line_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function dn_kinds(): array { return dn_x_has() ? DN_KINDS : array_values(array_diff(DN_KINDS, ['transfer'])); }
function dn_can(): bool { return dn_has() && function_exists('pr_can') && pr_can(); }
function dn_wh(): bool { return dn_has() && function_exists('pr_wh') && pr_wh(); }
const DN_SELECT = "SELECT d.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, co.name AS company, v.name AS vessel, w.code AS wh_code, w.name AS warehouse, r.receipt_no, o.po_no,
                   (SELECT COUNT(*) FROM inv_dn_lines l WHERE l.dn_id = d.id) AS n_lines
                   FROM inv_delivery_notes d LEFT JOIN projects p ON p.id = d.project_id LEFT JOIN subprojects s ON s.id = d.subproject_id LEFT JOIN companies co ON co.id = d.company_id LEFT JOIN vessels v ON v.id = d.vessel_id
                   LEFT JOIN inv_warehouses w ON w.id = d.warehouse_id LEFT JOIN po_receipts r ON r.id = d.receipt_id LEFT JOIN po_orders o ON o.id = d.order_id";
/** The same list with the destination warehouse of a transfer and the kind of both warehouses. */
function dn_select(): string {
    if (!dn_x_has()) return DN_SELECT;
    $x2 = dn_x2_has();
    return str_replace([' AS n_lines', 'LEFT JOIN po_receipts r'], [' AS n_lines,' . ($x2 ? ' tu.full_name AS to_user_name,' : '') . ' w.kind AS wh_kind, w2.code AS to_wh_code, w2.name AS to_warehouse, w2.kind AS to_wh_kind, w2.holder_id AS to_holder_id, w2.vehicle AS to_vehicle, w.holder_id AS wh_holder_id, fd.dn_no AS from_dn_no', 'LEFT JOIN inv_warehouses w2 ON w2.id = d.to_warehouse_id LEFT JOIN inv_delivery_notes fd ON fd.id = d.from_dn_id ' . ($x2 ? 'LEFT JOIN customer_users tu ON tu.id = d.to_user_id ' : '') . 'LEFT JOIN po_receipts r'], DN_SELECT);
}
function dn_load(int $id): ?array { return dn_has() && $id ? one(dn_select() . ' WHERE d.id = ?', [$id]) ?: null : null; }
/** Who may open a note: procurement, warehouse, management — and the holder of a warehouse of a transfer, for that note only. */
function dn_can_view(?array $d): bool { if (dn_can()) return true; $u = current_user(); if (!$d || !$u || !is_staff() || !dn_x_has()) return false; if ((int)($d['to_user_id'] ?? 0) === (int)$u['id']) return true; return $d['kind'] === 'transfer' && in_array((int)$u['id'], [(int)($d['to_holder_id'] ?? 0), (int)($d['wh_holder_id'] ?? 0)], true); }
function dn_can_receive(array $d): bool { $u = current_user(); return dn_x_has() && $u && $d['kind'] === 'transfer' && $d['status'] === 'issued' && in_array((string)($d['delivery'] ?? ''), ['transit', 'disputed'], true) && (dn_wh() || (is_staff() && dn_is_for($d, (int)$u['id']))); }
/** Is this person the receiver of the note? The named member of staff, or the holder of the destination warehouse of a transfer. */
function dn_is_for(array $d, int $uid): bool { return $uid > 0 && ((int)($d['to_user_id'] ?? 0) === $uid || ($d['kind'] === 'transfer' && (int)($d['to_holder_id'] ?? 0) === $uid)); }
/** Does the note still wait for its receiver? */
function dn_waits(array $d): bool { return $d['status'] === 'issued' && in_array((string)($d['delivery'] ?? 'open'), $d['kind'] === 'transfer' ? ['transit', 'disputed'] : ['open', 'sent', 'disputed'], true); }
function dn_lines(int $id): array { return all('SELECT l.*, i.code AS item_code, i.maker, i.factory_code FROM inv_dn_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.dn_id = ? ORDER BY l.seq, l.id', [$id]); }
function dn_list(array $f = []): array {
    if (!dn_has()) return []; $w = ['1=1']; $a = [];
    if (!empty($f['kind']) && in_array($f['kind'], DN_KINDS, true)) { $w[] = 'd.kind = ?'; $a[] = $f['kind']; }
    if (($f['status'] ?? '') !== '' && in_array($f['status'], ['issued', 'cancelled'], true)) { $w[] = 'd.status = ?'; $a[] = $f['status']; }
    if (!empty($f['delivery']) && in_array($f['delivery'], DN_DELIVERY, true) && dn_x_has()) { $w[] = "d.delivery = ? AND d.status = 'issued'"; $a[] = $f['delivery']; }
    if (!empty($f['project_id'])) { $w[] = 'd.project_id = ?'; $a[] = (int)$f['project_id']; }
    if (!empty($f['subproject_id'])) { $w[] = 'd.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['company_id'])) { $w[] = 'd.company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['item_id'])) { $w[] = 'EXISTS (SELECT 1 FROM inv_dn_lines l WHERE l.dn_id = d.id AND l.item_id = ?)'; $a[] = (int)$f['item_id']; }
    if (!empty($f['q'])) { $l = '%' . $f['q'] . '%'; $w[] = '(d.dn_no LIKE ? OR d.ref_no LIKE ? OR p.project_code LIKE ? OR p.name LIKE ? OR co.name LIKE ? OR v.name LIKE ? OR EXISTS (SELECT 1 FROM inv_dn_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.dn_id = d.id AND (l.description LIKE ? OR i.code LIKE ?)))'; array_push($a, $l, $l, $l, $l, $l, $l, $l, $l); }
    return all(dn_select() . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY d.issued_at DESC, d.id DESC LIMIT ' . (int)($f['limit'] ?? 500), $a);
}
function dn_number(?int $branchId): string {
    $b = null; if (function_exists('br_all') && $branchId) foreach (br_all() as $bb) if ((int)$bb['id'] === $branchId) $b = $bb;
    if (!$b && function_exists('br_default')) $b = br_default();
    $prefix = (string)(($b['doc_prefix'] ?? '') ?: 'GAS'); $yy = date('y'); $fam = 'DN:' . $prefix . ':' . $yy;
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['portal', $fam]);
    return $prefix . '-DN-' . $yy . '-' . str_pad((string)(int) db()->lastInsertId(), 4, '0', STR_PAD_LEFT);
}
function dn_pill(string $s): string { return pill($s === 'issued' ? 'ok' : 'mute', t('dn.s_' . $s)); }
function dn_kind_icon(string $k): string { return ['project' => '🏗', 'customer' => '🚚', 'production' => '🏭', 'return' => '↩', 'transfer' => '🔁'][$k] ?? '📦'; }
/** Where the note stands with its recipient (R97-279): a transfer is on the way or received; every other note is open, sent for acceptance, accepted or not accepted. */
function dn_delivery_pill(array $d): string { if (!dn_x_has() || $d['status'] !== 'issued') return ''; $s = (string)($d['delivery'] ?? 'open'); if ($s === 'open' && $d['kind'] !== 'transfer') return ''; return pill(['sent' => 'gold', 'accepted' => 'green', 'disputed' => 'bad', 'transit' => 'gold', 'received' => 'green'][$s] ?? 'mute', t('dn.d_' . $s)); }
function dn_wh_label(?string $code, ?string $name, ?string $kind = null): string { return ($kind === 'mobile' ? '🚐 ' : '') . trim((string)$code . ' — ' . (string)$name, ' —'); }
function dn_event(int $id, string $event, string $note = '', ?string $name = null, ?string $ip = null): void {
    if (!dn_x_has()) return; $u = $name === null ? current_user() : null;
    q('INSERT INTO inv_dn_events (dn_id, event, user_id, user_name, note, ip) VALUES (?,?,?,?,?,?)', [$id, mb_substr($event, 0, 20), $u ? (int)$u['id'] : null, mb_substr((string)($name ?? ($u['full_name'] ?? '')), 0, 120) ?: null, mb_substr($note, 0, 600) ?: null, $ip]);
}
function dn_events(int $id): array { return dn_x_has() ? all('SELECT * FROM inv_dn_events WHERE dn_id = ? ORDER BY id', [$id]) : []; }

/** Create a delivery note and book the movements.
 *  $h: kind, warehouse_id, project_id, subproject_id, company_id, vessel_id, receipt_id, order_id, issued_at, address, attention, carrier, ref_no, note, allow_negative
 *  $L: [['item_id', 'description', 'unit', 'qty', 'po_line_id', 'pr_line_id', 'note', 'skip_move' (bool: the stock was never booked in, e.g. a free-text receipt line)], …] */
function dn_create(array $h, array $L): int { return stk_tx(fn() => dn_create_x($h, $L)); }
function dn_create_x(array $h, array $L): int {
    require_pr_wh(); if (!dn_has()) throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($h['kind'] ?? '', DN_KINDS, true) ? $h['kind'] : 'project'; $u = current_user();
    $pid = (int)($h['project_id'] ?? 0) ?: null; $sid = (int)($h['subproject_id'] ?? 0) ?: null;
    if ($sid) { $pp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp) $sid = null; elseif (!$pid) $pid = $pp; elseif ($pp !== $pid) $sid = null; }
    $cid = (int)($h['company_id'] ?? 0) ?: null; $vid = (int)($h['vessel_id'] ?? 0) ?: null;
    if ($kind === 'transfer' && !dn_x_has()) throw new RuntimeException(t('err.noaccess'));
    if ($kind === 'project' && !$pid) throw new RuntimeException(t('dn.need_project')); if ($kind === 'customer' && !$cid) throw new RuntimeException(t('dn.need_company'));
    if ($pid && !$cid && $kind === 'project') { try { $cid = (int) scalar('SELECT co.id FROM projects p JOIN companies co ON co.customer_id = p.customer_id WHERE p.id = ? LIMIT 1', [$pid]) ?: null; if (!$vid) $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]) ?: null; } catch (Throwable $e) {} }
    $wh = (int)($h['warehouse_id'] ?? 0) ?: stk_default_wh(); $to = null; $from = null;
    if ($kind === 'transfer') { $to = (int)($h['to_warehouse_id'] ?? 0); if (!$to || $to === $wh || !scalar('SELECT id FROM inv_warehouses WHERE id = ? AND is_active = 1', [$to])) throw new RuntimeException(t('stk.need_to_wh')); $cid = null; if ($pid && !$vid) $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]) ?: null; }
    $toU = dn_x2_has() ? ((int)($h['to_user_id'] ?? 0) ?: null) : null; if ($toU && !scalar("SELECT id FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$toU])) $toU = null;
    if (dn_x_has() && (int)($h['from_dn_id'] ?? 0)) { $fd = one("SELECT id, kind, to_warehouse_id FROM inv_delivery_notes WHERE id = ? AND status = 'issued'", [(int)$h['from_dn_id']]); if ($fd && $fd['kind'] === 'transfer' && (int)$fd['to_warehouse_id'] === $wh) $from = (int)$fd['id']; }   // the note that brought the goods to this warehouse
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['issued_at'] ?? '')) ? $h['issued_at'] : date('Y-m-d');
    $lines = []; foreach ($L as $l) { $qty = (float) str_replace(',', '.', (string)($l['qty'] ?? '0')); if ($qty <= 0) continue; $desc = mb_substr(trim((string)($l['description'] ?? '')), 0, 255); $iid = (int)($l['item_id'] ?? 0) ?: null; if ($iid && $desc === '') { $it = stk_item($iid); $desc = $it ? $it['description'] : ''; } if ($desc === '') throw new RuntimeException(t('dn.need_desc')); $lines[] = ['item_id' => $iid, 'description' => $desc, 'unit' => stk_unit_norm((string)($l['unit'] ?? 'pcs')), 'qty' => $qty, 'po_line_id' => (int)($l['po_line_id'] ?? 0) ?: null, 'pr_line_id' => (int)($l['pr_line_id'] ?? 0) ?: null, 'note' => mb_substr(trim((string)($l['note'] ?? '')), 0, 255) ?: null, 'skip_move' => !empty($l['skip_move']), 'src_line_id' => (int)($l['src_line_id'] ?? 0) ?: null]; }
    if (!$lines) throw new RuntimeException(t('dn.need_lines'));
    $br = null; try { $br = $pid ? ((int) scalar('SELECT branch_id FROM projects WHERE id = ?', [$pid]) ?: null) : (function_exists('br_default') ? (int)br_default()['id'] : null); } catch (Throwable $e) {}
    $no = dn_number($br);
    q('INSERT INTO inv_delivery_notes (dn_no, kind, branch_id, warehouse_id, project_id, subproject_id, company_id, vessel_id, receipt_id, order_id, issued_at, address, attention, carrier, ref_no, note, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$no, $kind, $br, $wh, $pid, $sid, $cid, $vid, (int)($h['receipt_id'] ?? 0) ?: null, (int)($h['order_id'] ?? 0) ?: null, $date, mb_substr(trim((string)($h['address'] ?? '')), 0, 240) ?: null, mb_substr(trim((string)($h['attention'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($h['carrier'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($h['ref_no'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($h['note'] ?? '')), 0, 600) ?: null, (int)$u['id'], $u['full_name']]);
    $dnId = (int) db()->lastInsertId(); $seq = 0;
    if (dn_x_has()) { q('UPDATE inv_delivery_notes SET to_warehouse_id = ?, from_dn_id = ?, delivery = ? WHERE id = ?', [$to, $from, $kind === 'transfer' ? 'transit' : 'open', $dnId]); dn_event($dnId, 'issued', $kind === 'transfer' ? (string) scalar("SELECT CONCAT(a.code, ' → ', b.code) FROM inv_warehouses a, inv_warehouses b WHERE a.id = ? AND b.id = ?", [$wh, $to]) : ''); }
    foreach ($lines as $l) { $seq++; $mid = null;
        if ($kind === 'transfer') {   // R97-279: out of one warehouse and into the other in the same action; what is reserved for the project travels with the goods
            $min = null; $moved = [];
            if ($l['item_id'] && !$l['skip_move']) {
                $moved = dn_xfer_rsv_take((int)$l['item_id'], $wh, (float)$l['qty'], $pid, $sid, !empty($h['all_rsv']));
                $snAll = !empty($h['auto_serials']) && function_exists('sn_tracked') && sn_tracked((int)$l['item_id']) && abs((float) scalar('SELECT qty_on_hand FROM inv_stock WHERE item_id = ? AND warehouse_id = ?', [(int)$l['item_id'], $wh]) - (float)$l['qty']) < 0.0005 ? array_column(sn_in_stock((int)$l['item_id'], $wh), 'serial_no') : [];   // R97-282: when everything of a numbered item leaves the warehouse, its numbers go with it
                $mo = ['project_id' => $pid, 'subproject_id' => $sid, 'ref_kind' => 'dn', 'ref_id' => $dnId, 'ref_no' => $no, 'note' => $l['description']];
                $mid = stk_move((int)$l['item_id'], $wh, 'xfer_out', (float)$l['qty'], $mo); $min = stk_move((int)$l['item_id'], (int)$to, 'xfer_in', (float)$l['qty'], $mo);
                foreach ($moved as [$plid, $mq]) dn_xfer_rsv_put($plid, (int)$l['item_id'], (int)$to, $mq, $wh);
                if ($snAll && count($snAll) <= (int) round((float)$l['qty'])) sn_transfer($mid, (int)$to, $snAll);
            }
            q('INSERT INTO inv_dn_lines (dn_id, seq, item_id, po_line_id, pr_line_id, description, unit, qty, move_id, move_in_id, note) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$dnId, $seq, $l['item_id'], null, null, $l['description'], $l['unit'], $l['qty'], $mid, $min, $l['note']]);
            $dlid = (int) db()->lastInsertId(); foreach ($moved as [$plid, $mq]) q('INSERT INTO inv_dn_rsv (dn_id, dn_line_id, pr_line_id, qty) VALUES (?,?,?,?)', [$dnId, $dlid, $plid, $mq]);
            continue;
        }
        if ($l['item_id'] && !$l['skip_move']) {
            $it = stk_item($l['item_id']);
            if ($l['pr_line_id'] && function_exists('prq_issue_dn')) { $mid = prq_issue_dn((int)$l['pr_line_id'], $l['qty'], $no, $dnId, $cid, $wh); }
            else { $mid = stk_move($l['item_id'], $wh, 'out', $l['qty'], ['unit_price' => $it['last_price'] ?? null, 'project_id' => $pid, 'subproject_id' => $sid, 'reason' => DN_REASON[$kind], 'ref_kind' => 'dn', 'ref_id' => $dnId, 'ref_no' => $no, 'company_id' => $cid, 'note' => $l['description'], 'allow_negative' => !empty($h['allow_negative']), 'item_consumable' => !empty($it['is_consumable'])]); }
        }
        q('INSERT INTO inv_dn_lines (dn_id, seq, item_id, po_line_id, pr_line_id, description, unit, qty, move_id, note) VALUES (?,?,?,?,?,?,?,?,?,?)', [$dnId, $seq, $l['item_id'], $l['po_line_id'], $l['pr_line_id'], $l['description'], $l['unit'], $l['qty'], $mid, $l['note']]);
        if ($l['src_line_id'] && dn_x3_has()) q('UPDATE inv_dn_lines SET src_line_id = ? WHERE id = ?', [$l['src_line_id'], (int) db()->lastInsertId()]);   // R97-281
    }
    if ($toU) { q('UPDATE inv_delivery_notes SET to_user_id = ?' . ($kind === 'transfer' ? '' : ", delivery = 'sent'") . ' WHERE id = ?', [$toU, $dnId]); dn_event($dnId, 'sent', '📱 ' . (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$toU])); dn_notify_to($dnId, $toU); }
    if ($kind === 'transfer') { try { $hid = (int) scalar('SELECT holder_id FROM inv_warehouses WHERE id = ?', [$to]); if ($hid && $hid !== (int)$u['id'] && $hid !== (int)$toU && function_exists('nf_push')) nf_push([$hid], 'dn:xfer', 'dn:' . $dnId, t('dn.nf_xfer', ['no' => $no]), (string) scalar("SELECT CONCAT(a.code, ' → ', b.code, ' · ', b.name) FROM inv_warehouses a, inv_warehouses b WHERE a.id = ? AND b.id = ?", [$wh, $to]), url('delivery_note', ['id' => $dnId]), (int)$u['id'], (string)$u['full_name']); } catch (Throwable $e) { error_log('[dn] notify: ' . $e->getMessage()); } }
    cache_bump_later('inv'); return $dnId;
}
/** R97-279: what requisitions of the project hold of this item in the source warehouse leaves it with the transfer (oldest line first). Returns [[requisition line, quantity], …]. */
function dn_xfer_rsv_take(int $itemId, int $wh, float $qty, ?int $pid, ?int $sid, bool $all = false): array {
    if ($all) {   // R97-282: stock of a warehouse sent on as it stands — free stock first; what is needed beyond it takes its reservations along, whatever the project
        if (!function_exists('prq_rsv_has') || !prq_rsv_has()) return []; $st = one('SELECT qty_on_hand, qty_reserved FROM inv_stock WHERE item_id = ? AND warehouse_id = ? FOR UPDATE', [$itemId, $wh]) ?: ['qty_on_hand' => 0, 'qty_reserved' => 0];
        $left = $qty - max(0.0, (float)$st['qty_on_hand'] - (float)$st['qty_reserved']); $out = []; if ($left <= 0.0005) return [];
        foreach (all('SELECT l.* FROM pr_lines l JOIN pr_reservations v ON v.pr_line_id = l.id AND v.warehouse_id = ? AND v.qty > 0.0005 WHERE l.item_id = ? ORDER BY l.id FOR UPDATE', [$wh, $itemId]) as $l) {
            if ($left <= 0.0005) break; $tq = min((float)(prq_rsv_of($l)[$wh] ?? 0.0), $left); if ($tq <= 0.0005) continue;
            $t = prq_rsv_take($l, $tq, $wh, true); if (abs((float)($t[$wh] ?? 0.0) - $tq) > 0.0005) throw new RuntimeException(t('prq.issue_split'));
            $out[] = [(int)$l['id'], $tq]; $left -= $tq;
        }
        return $out;
    }
    if (!$pid || !function_exists('prq_rsv_has') || !prq_rsv_has()) return []; $out = []; $left = $qty; $a = [$wh, $itemId, $pid]; if ($sid) $a[] = $sid;
    foreach (all("SELECT l.* FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id JOIN pr_reservations v ON v.pr_line_id = l.id AND v.warehouse_id = ? AND v.qty > 0.0005
                  WHERE l.item_id = ? AND r.project_id = ? AND r.status = 'approved' AND l.status IN ('reserved','to_buy','received')" . ($sid ? ' AND r.subproject_id = ?' : '') . ' ORDER BY l.id FOR UPDATE', $a) as $l) {
        if ($left <= 0.0005) break; $tq = min((float)(prq_rsv_of($l)[$wh] ?? 0.0), $left); if ($tq <= 0.0005) continue;
        $t = prq_rsv_take($l, $tq, $wh, true); if (abs((float)($t[$wh] ?? 0.0) - $tq) > 0.0005) throw new RuntimeException(t('prq.issue_split'));
        $out[] = [(int)$l['id'], $tq]; $left -= $tq;
    }
    return $out;
}
/** The reservation arrives in the other warehouse; a line that holds nothing in the old one any more now belongs to the new one, so "Received per project" issues it from there. */
function dn_xfer_rsv_put(int $plid, int $itemId, int $toWh, float $qty, int $fromWh): void {
    prq_rsv_add($plid, $itemId, $toWh, $qty); $l = one('SELECT * FROM pr_lines WHERE id = ?', [$plid]);
    if ($l && !isset(prq_rsv_of($l)[$fromWh])) q('UPDATE pr_lines SET warehouse_id = ? WHERE id = ?', [$toWh, $plid]);
}
/** Serial numbers that travelled with a transfer go back with it. */
function dn_sn_back(int $moveOutId, int $fromWh, int $toWh, string $ref): void {
    if (!function_exists('sn_has') || !sn_has()) return;
    foreach (all("SELECT l.id AS lid, s.* FROM inv_serial_log l JOIN inv_serials s ON s.id = l.serial_id WHERE l.move_id = ? AND l.event = 'xfer'", [$moveOutId]) as $s) {
        if ($s['status'] !== 'in_stock' || (int)$s['warehouse_id'] !== $fromWh) throw new RuntimeException(t('sn.other_wh', ['s' => (string)$s['serial_no']]));
        q('UPDATE inv_serials SET warehouse_id = ?, last_ref = ? WHERE id = ?', [$toWh, mb_substr($ref, 0, 160), (int)$s['id']]); q("UPDATE inv_serial_log SET event = 'undone' WHERE id = ?", [(int)$s['lid']]); sn_log((int)$s['id'], 'undo', ['move_id' => $moveOutId, 'warehouse_id' => $toWh, 'ref' => $ref]);
    }
}
/** Manual delivery note from the Inventory › Delivery notes page (lines item[]/desc[]/qty[]/unit[]). */
function dn_create_from_post(): int {
    $L = []; foreach ((array)($_POST['qty'] ?? []) as $i => $q) $L[] = ['item_id' => (int)($_POST['item'][$i] ?? 0), 'description' => (string)($_POST['desc'][$i] ?? ''), 'unit' => (string)($_POST['unit'][$i] ?? 'pcs'), 'qty' => (string)$q, 'note' => (string)($_POST['lnote'][$i] ?? '')];
    return dn_create(['kind' => (string)($_POST['kind'] ?? 'project'), 'warehouse_id' => (int)($_POST['warehouse_id'] ?? 0), 'project_id' => (int)($_POST['project_id'] ?? 0), 'subproject_id' => (int)($_POST['subproject_id'] ?? 0), 'company_id' => (int)($_POST['company_id'] ?? 0), 'vessel_id' => (int)($_POST['vessel_id'] ?? 0), 'to_user_id' => (int)($_POST['to_user_id'] ?? 0), 'to_warehouse_id' => (int)($_POST['to_warehouse_id'] ?? 0), 'from_dn_id' => (int)($_POST['from_dn_id'] ?? 0), 'issued_at' => (string)($_POST['issued_at'] ?? ''), 'address' => (string)($_POST['address'] ?? ''), 'attention' => (string)($_POST['attention'] ?? ''), 'carrier' => (string)($_POST['carrier'] ?? ''), 'ref_no' => (string)($_POST['ref_no'] ?? ''), 'note' => (string)($_POST['note'] ?? ''), 'allow_negative' => !empty($_POST['allow_negative']) && ($_POST['kind'] ?? '') !== 'transfer'], $L);
}
/** Cancel: the movements are reversed (goods back in the warehouse), requisition issues undone. */
function dn_cancel(int $id, string $note): void { stk_tx(fn() => dn_cancel_x($id, $note)); }
function dn_cancel_x(int $id, string $note): void {
    require_pr_wh(); $d = dn_load($id); if (!$d || $d['status'] !== 'issued') throw new RuntimeException(t('err.noaccess'));
    if ($d['kind'] === 'transfer') {   // R97-279: the goods go back to the warehouse they left — only what is still in the destination warehouse can go back
        $to = (int)$d['to_warehouse_id']; $why = t('dn.cancelled_note', ['no' => $d['dn_no']]);
        foreach (dn_lines($id) as $l) { if (!$l['item_id'] || !$l['move_id']) continue; $mv = one('SELECT * FROM inv_moves WHERE id = ?', [(int)$l['move_id']]); if (!$mv) continue; $qty = abs((float)$mv['qty']); $fromWh = (int)$mv['warehouse_id']; $back = [];
            foreach (all('SELECT * FROM inv_dn_rsv WHERE dn_line_id = ?', [(int)$l['id']]) as $x) { $pl = one('SELECT * FROM pr_lines WHERE id = ? FOR UPDATE', [(int)$x['pr_line_id']]); $t = $pl ? prq_rsv_take($pl, (float)$x['qty'], $to, true) : []; if (abs((float)($t[$to] ?? 0.0) - (float)$x['qty']) > 0.0005) throw new RuntimeException(t('dn.xf_gone', ['c' => (string)($l['item_code'] ?: $l['description'])])); $back[] = [(int)$x['pr_line_id'], (float)$x['qty']]; }
            $mo = ['project_id' => $mv['project_id'], 'subproject_id' => $mv['subproject_id'], 'ref_kind' => 'dn', 'ref_id' => $id, 'ref_no' => $d['dn_no'], 'note' => $why];
            try { stk_move((int)$l['item_id'], $to, 'xfer_out', $qty, $mo); } catch (RuntimeException $e) { throw new RuntimeException(t('dn.xf_gone', ['c' => (string)($l['item_code'] ?: $l['description'])]) . ' ' . $e->getMessage()); }
            stk_move((int)$l['item_id'], $fromWh, 'xfer_in', $qty, $mo);
            foreach ($back as [$plid, $bq]) dn_xfer_rsv_put($plid, (int)$l['item_id'], $fromWh, $bq, $to);
            dn_sn_back((int)$l['move_id'], $to, $fromWh, $why);
        }
        q('DELETE FROM inv_dn_rsv WHERE dn_id = ?', [$id]);
        $u = current_user(); q('UPDATE inv_delivery_notes SET status = ?, note = CONCAT(COALESCE(note, \'\'), ?) WHERE id = ?', ['cancelled', "\n[" . date('Y-m-d') . ' ' . $u['full_name'] . '] ' . t('dn.cancelled') . ($note !== '' ? ': ' . mb_substr($note, 0, 300) : ''), $id]);
        dn_event($id, 'cancelled', $note); cache_bump_later('inv'); return;
    }
    foreach (dn_lines($id) as $l) { if (!$l['item_id'] || !$l['move_id']) continue; $mv = one('SELECT * FROM inv_moves WHERE id = ?', [(int)$l['move_id']]); if (!$mv) continue;
        if (function_exists('sn_undo_move')) sn_undo_move((int)$l['move_id'], t('dn.cancelled_note', ['no' => $d['dn_no']]));   // R97-252: the numbers that left on the note are in the warehouse again
        stk_move((int)$l['item_id'], (int)$mv['warehouse_id'], 'in', abs((float)$mv['qty']), ['unit_price' => $mv['unit_price'], 'project_id' => $mv['project_id'], 'subproject_id' => $mv['subproject_id'], 'reason' => $d['kind'] === 'customer' ? 'return_customer' : 'return_project', 'ref_kind' => 'dn', 'ref_id' => $id, 'ref_no' => $d['dn_no'], 'company_id' => $mv['company_id'], 'note' => t('dn.cancelled_note', ['no' => $d['dn_no']])]);
        if ($l['pr_line_id']) q('UPDATE pr_lines SET qty_issued = GREATEST(0, qty_issued - ?), status = IF(status = \'issued\', \'received\', status) WHERE id = ?', [abs((float)$mv['qty']), (int)$l['pr_line_id']]);
        if ($l['pr_line_id']) {   // R97-109: the goods are back in the warehouse for the same requisition — reserved again, so they can go on another note
            prq_rsv_add((int)$l['pr_line_id'], (int)$l['item_id'], (int)$mv['warehouse_id'], abs((float)$mv['qty']));   // R97-215: reserved in the warehouse the goods came back to
            q("UPDATE pr_requests r JOIN pr_lines pl ON pl.request_id = r.id SET r.status = 'approved' WHERE pl.id = ? AND r.status = 'closed'", [(int)$l['pr_line_id']]);
        }
    }
    $u = current_user(); q('UPDATE inv_delivery_notes SET status = ?, note = CONCAT(COALESCE(note, \'\'), ?) WHERE id = ?', ['cancelled', "\n[" . date('Y-m-d') . ' ' . $u['full_name'] . '] ' . t('dn.cancelled') . ($note !== '' ? ': ' . mb_substr($note, 0, 300) : ''), $id]);
    dn_event($id, 'cancelled', $note); cache_bump_later('inv');
}
function dn_of_receipt(int $receiptId): array { return dn_has() ? all(dn_select() . ' WHERE d.receipt_id = ? ORDER BY d.id', [$receiptId]) : []; }
function dn_em_attachment(array $p): ?array { if (!dn_can()) return null; $d = dn_load((int)($p[1] ?? 0)); if (!$d) return null; return [dn_pdf($d), $d['dn_no'] . '.pdf', 'application/pdf']; }
/** Address block of the recipient. */
function dn_recipient(array $d): array {
    $out = [];
    if ($d['kind'] === 'transfer') {   // R97-279: the other warehouse, its vehicle and holder, and the project the goods are for
        $out[] = dn_wh_label((string)($d['to_wh_code'] ?? ''), (string)($d['to_warehouse'] ?? ''));
        if (!empty($d['to_vehicle'])) $out[] = (string)$d['to_vehicle'];
        if (!empty($d['to_holder_id'])) { $hn = (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)$d['to_holder_id']]); if ($hn !== '') $out[] = 'Attn. ' . $hn; }
        if ($d['project_code']) $out[] = trim($d['project_code'] . ' · ' . $d['project_name'] . ($d['sub_code'] ? ' › ' . $d['sub_code'] . ' ' . $d['sub_name'] : ''));
        if ($d['vessel']) $out[] = 'M/Y ' . $d['vessel'];
        return $out;
    }
    if ($d['company_id']) { $co = one('SELECT * FROM companies WHERE id = ?', [(int)$d['company_id']]) ?: []; $out = array_values(array_filter([(string)($co['name'] ?? ''), (string)($co['address'] ?? ''), trim((string)($co['postal_code'] ?? '') . ' ' . (string)($co['city'] ?? '')), (string)($co['country'] ?? '')])); }
    if ($d['vessel']) $out[] = 'M/Y ' . $d['vessel'];
    if ($d['project_code']) $out[] = trim($d['project_code'] . ' · ' . $d['project_name'] . ($d['sub_code'] ? ' › ' . $d['sub_code'] . ' ' . $d['sub_name'] : ''));
    if ($d['address']) $out[] = (string)$d['address']; if ($d['attention']) $out[] = 'Attn. ' . $d['attention'];
    return $out ?: [t('dn.k_' . $d['kind'])];
}
/** R97-278: the quantities of a delivery note added up, one figure per unit of measure (pcs, m, kg … are never added together), in the order the units first appear. */
function dn_qty_totals(array $L): array {
    $t = [];
    foreach ($L as $l) { $u = trim((string)($l['unit'] ?? '')); $t[$u] = ($t[$u] ?? 0.0) + (float)$l['qty']; }
    return $t;
}
function dn_pdf(array $d): string {
    require_once __DIR__ . '/pdf.php'; $L = dn_lines((int)$d['id']); $loc = 'en'; $T = fn(string $k, array $v = []) => t_in($k, $loc, $v);
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $Lm = 15.0; $R = 195.0; $W = $R - $Lm; $y = 0.0;
    $bd = function_exists('br_doc') ? br_doc($d['project_id'] ? (int)$d['project_id'] : null, null, $d['branch_id'] && function_exists('br_all') ? (array_values(array_filter(br_all(), fn($b) => (int)$b['id'] === (int)$d['branch_id']))[0] ?? null) : null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $title = $T('dn.pdf_title');
    $header = function () use ($pdf, &$y, $navy, $gold, $Lm, $R, $d, $bd, $title, $T) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($Lm, 20, mb_strtoupper($title)); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($Lm, 26, $d['dn_no'] . ' · ' . fmt_date($d['issued_at']) . ($d['status'] === 'cancelled' ? ' · ' . mb_strtoupper($T('dn.s_cancelled')) : '')); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($Lm, 30, $R, 30); $y = 37.0; };
    $footer = function () use ($pdf, $Lm, $R, $d, $bd) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($Lm, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $d['dn_no']); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 272) { $footer(); $header(); } };
    $header();
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y, mb_strtoupper($T('pi.from'))); $pdf->text(110, $y, mb_strtoupper($T('dn.pdf_to'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap($ln0, 88) as $ln) { $pdf->text($Lm, $yl, $ln); $yl += 4; }
    $yr = $y; foreach (dn_recipient($d) as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    $kv = [[$T('dn.kind'), $T('dn.k_' . $d['kind'])], [$T($d['kind'] === 'transfer' ? 'dn.from_wh' : 'stk.warehouse'), $d['kind'] === 'transfer' ? trim((string)($d['wh_code'] ?? '') . ' — ' . (string)($d['warehouse'] ?? ''), ' —') : (string)($d['warehouse'] ?? '')], [$T('stk.to_wh'), $d['kind'] === 'transfer' ? trim((string)($d['to_wh_code'] ?? '') . ' — ' . (string)($d['to_warehouse'] ?? ''), ' —') : ''], [$T('po.ref'), (string)($d['ref_no'] ?? '')], [$T('po.carrier'), (string)($d['carrier'] ?? '')], [$T('po.receipt_no'), (string)($d['receipt_no'] ?? '') . ($d['po_no'] ? ' · ' . $d['po_no'] : '')]];
    $kv = array_values(array_filter($kv, fn($r) => trim($r[1]) !== '')); $need(count($kv) * 4.5 + 6);
    $pdf->setColor($head); $pdf->rect($Lm, $y, $W, count($kv) * 4.5 + 3, 'F'); $yy = $y + 4;
    foreach ($kv as [$k, $v]) { $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm + 2, $yy, $k); $pdf->setFont('R', 8); $pdf->setColor($ink); $pdf->text($Lm + 40, $yy, mb_substr($v, 0, 110)); $yy += 4.5; }
    $y = $yy + 2;
    $cols = [['#', 8, 'L'], [$T('stk.code'), 32, 'L'], [$T('prq.desc'), 110, 'L'], [$T('prq.qty'), 30, 'R']];
    $hrow = function () use ($pdf, $cols, $navy, $head, $Lm, &$y) { $pdf->setFont('B', 7.5); $pdf->setColor($head); $pdf->rect($Lm, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $Lm; foreach ($cols as [$h, $w, $al]) { $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 4.2, $h, $al); $x += $w; } $y += 6; };
    $need(20); $hrow(); $pdf->setFont('R', 8); $pdf->setColor($ink);
    foreach ($L as $l) {
        $desc = $l['description'] . (($l['maker'] || $l['factory_code']) ? ' — ' . trim(($l['maker'] ?? '') . ' ' . ($l['factory_code'] ?? '')) : '') . ($l['note'] ? ' · ' . $l['note'] : '');
        if (!empty($l['move_id']) && function_exists('sn_of_move') && ($snL = sn_of_move((int)$l['move_id']))) $desc .= ' — S/N: ' . implode(', ', $snL);   // R97-252
        $pdf->setFont('R', 8); $lines = $pdf->wrap($desc, $cols[2][1] - 3); $h = max(5.5, count($lines) * 3.8 + 1.8);
        if ($y + $h + 2 > 272) { $need($h + 8); $hrow(); }   // R97-149: column headers again on a new page
        $pdf->setFont('R', 8); $pdf->setColor($ink);
        $x = $Lm; $cells = [(string)$l['seq'], (string)($l['item_code'] ?: ''), null, stk_qty((float)$l['qty']) . ' ' . $l['unit']];
        foreach ($cols as $i => [$hh, $w, $al]) { if ($cells[$i] === null) { $yy = $y + 3.8; foreach ($lines as $ln) { $pdf->text($x + 1.5, $yy, $ln); $yy += 3.8; } } else $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 3.8, mb_substr($cells[$i], 0, 34), $al); $x += $w; }
        $y += $h; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($Lm, $y, $Lm + array_sum(array_column($cols, 1)), $y); $pdf->setColor($ink);
    }
    if ($L) {                                                                                 // R97-278: total of the quantities at the end of the lines, per unit
        $tot = dn_qty_totals($L); $tw = array_sum(array_column($cols, 1)); $need(count($tot) * 5.5 + 4);
        $first = true;
        foreach ($tot as $u => $q) {
            $pdf->setColor($head); $pdf->rect($Lm, $y, $tw, 5.5, 'F'); $pdf->setFont('B', 8); $pdf->setColor($navy);
            if ($first) $pdf->text($Lm + $tw - $cols[3][1] - 1.5, $y + 3.9, $T('dn.total_qty') . ' (' . $T('dn.total_lines', ['n' => count($L)]) . ')', 'R');
            $pdf->text($Lm + $tw - 1.5, $y + 3.9, trim(stk_qty($q) . ' ' . $u), 'R'); $y += 5.5; $first = false;
        }
        $pdf->setColor($navy); $pdf->setLineWidth(0.3); $pdf->line($Lm, $y, $Lm + $tw, $y); $pdf->setFont('R', 8); $pdf->setColor($ink);
    }
    if ($d['note']) { $need(16); $y += 3; $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y + 3, $T('po.pdf_notes')); $y += 5; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, (string)$d['note'], 3.9) + 2; }
    $need(30); $y += 6; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, $T('dn.pdf_foot'), 3.9) + 6;
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y, $T('dn.pdf_issued_by') . ' ' . $bd['name']); $pdf->text(110, $y, $T('dn.pdf_received_by'));
    $acc = dn_x_has() && $d['status'] === 'issued' && in_array((string)($d['delivery'] ?? ''), ['accepted', 'received'], true);   // R97-279: who received the goods, when and how — with the signature drawn on the acceptance page
    if ($acc) { $sg = scalar('SELECT png FROM inv_dn_signs WHERE dn_id = ?', [(int)$d['id']]); if ($sg) $pdf->image((string)$sg, 110, $y + 1.5, 60, 11.5); }
    $pdf->setFont('R', 8); $pdf->setColor($ink); $pdf->text($Lm, $y + 12.5, mb_substr((string)($d['user_name'] ?? ''), 0, 48));
    $y += 14; $pdf->setColor($line); $pdf->line($Lm, $y, $Lm + 70, $y); $pdf->line(110, $y, 180, $y);
    if ($acc) { $pdf->setFont('R', 8); $pdf->setColor($ink); $yy = $y + 4;
        foreach (array_filter([mb_substr(trim((string)$d['accepted_name'] . ($d['accepted_role'] ? ', ' . $d['accepted_role'] : '')), 0, 60), fmt_datetime((string)$d['accepted_at']), $T('dn.via_' . ($d['accepted_via'] ?: 'portal')) . ($d['accepted_via'] === 'email' && $d['accepted_email'] ? ' · ' . $d['accepted_email'] : '')]) as $ln) { $pdf->text(110, $yy, mb_substr($ln, 0, 64)); $yy += 3.9; }
        if (trim((string)$d['accepted_remark']) !== '') foreach (array_slice($pdf->wrap((string)$d['accepted_remark'], 84), 0, 4) as $ln) { $pdf->text(110, $yy, $ln); $yy += 3.9; }
    }
    $footer(); return $pdf->bytes();
}

/* ------------------------------------------------------------------ R97-279: acceptance by the recipient, receipt of a transfer, the log */
/** A link for acceptance went out (called by the acceptance-by-link module). */
function dn_link_sent(int $id, string $email): void { if (!dn_x_has()) return; q("UPDATE inv_delivery_notes SET delivery = 'sent' WHERE id = ? AND status = 'issued' AND delivery IN ('open','disputed')", [$id]); dn_event($id, 'sent', $email); }
function dn_sig_png(string $dataUrl): ?string {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', $dataUrl, $m)) return null; $png = base64_decode($m[1], true);
    if ($png === false || strlen($png) < 200 || strlen($png) > 400000 || !@getimagesizefromstring($png)) return null; return $png;
}
/** The recipient's answer through the personal link: accepted (name, capacity, drawn signature) or not accepted (with the reason). Returns 1 when stored. */
function dn_decided(int $id, string $decision, string $name, string $role, string $email, string $remark, string $sig, ?string $ip, bool $read): int {
    if (!dn_x_has() || !in_array($decision, ['accepted', 'disputed'], true)) throw new RuntimeException(t('err.notfound')); $png = null;
    if ($decision === 'disputed') { if ($remark === '') throw new RuntimeException(t('dl.remark_needed')); }
    else { if (!$read) throw new RuntimeException(t('sgn.read_needed')); $png = dn_sig_png($sig); if ($png === null) throw new RuntimeException(t('sgn.sign_needed')); }
    $n = q("UPDATE inv_delivery_notes SET delivery = ?, accepted_name = ?, accepted_role = ?, accepted_email = ?, accepted_via = 'email', accepted_at = NOW(), accepted_ip = ?, accepted_remark = ? WHERE id = ? AND status = 'issued' AND kind <> 'transfer' AND delivery IN ('open','sent','disputed')",
           [$decision, $name, $role ?: null, mb_substr($email, 0, 190), $ip, $remark ?: null, $id])->rowCount();
    if ($n) { if ($png !== null) q('REPLACE INTO inv_dn_signs (dn_id, png) VALUES (?,?)', [$id, $png]); dn_event($id, $decision, trim($name . ($role !== '' ? ', ' . $role : '') . ' · ' . $email . ($remark !== '' ? ' — ' . $remark : '')), $name, $ip); }
    return $n;
}
/** The signed paper copy came back: a member of staff records who received the goods. */
function dn_mark_paper(int $id, string $name, string $role, string $note): void {
    if (!dn_x_has() || !dn_can()) throw new RuntimeException(t('err.noaccess')); $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 90); $role = mb_substr(trim($role), 0, 80); $note = mb_substr(trim($note), 0, 600);
    if (mb_strlen($name) < 3) throw new RuntimeException(t('sgn.name_needed'));
    $n = q("UPDATE inv_delivery_notes SET delivery = 'accepted', accepted_name = ?, accepted_role = ?, accepted_email = NULL, accepted_via = 'paper', accepted_at = NOW(), accepted_ip = NULL, accepted_remark = ? WHERE id = ? AND status = 'issued' AND kind <> 'transfer' AND delivery IN ('open','sent','disputed')", [$name, $role ?: null, $note ?: null, $id])->rowCount();
    if (!$n) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM inv_dn_signs WHERE dn_id = ?', [$id]); dn_event($id, 'paper', trim($name . ($role !== '' ? ', ' . $role : '') . ($note !== '' ? ' — ' . $note : '')));
}
/** The goods of a transfer arrived: confirmed by the warehouse or by the holder of the destination warehouse. */
function dn_receive(int $id, string $note): void {
    $d = dn_load($id); if (!$d || !dn_can_receive($d)) throw new RuntimeException(t('err.noaccess')); $u = current_user(); $note = mb_substr(trim($note), 0, 600);
    $n = q("UPDATE inv_delivery_notes SET delivery = 'received', accepted_name = ?, accepted_via = 'portal', accepted_at = NOW(), accepted_remark = ? WHERE id = ? AND status = 'issued' AND kind = 'transfer' AND delivery IN ('transit','disputed')", [mb_substr((string)$u['full_name'], 0, 90), $note ?: null, $id])->rowCount();
    if (!$n) throw new RuntimeException(t('err.noaccess'));
    dn_event($id, 'received', $note);
    try { if ((int)$d['user_id'] && (int)$d['user_id'] !== (int)$u['id'] && function_exists('nf_push')) nf_push([(int)$d['user_id']], 'dn:recv', 'dn:' . $id, t('dn.nf_recv', ['no' => $d['dn_no']]), dn_wh_label((string)$d['to_wh_code'], (string)$d['to_warehouse']) . ($note !== '' ? ' — ' . $note : ''), url('delivery_note', ['id' => $id]), (int)$u['id'], (string)$u['full_name']); } catch (Throwable $e) { error_log('[dn] notify: ' . $e->getMessage()); }
}
/* ---- R97-282: stock per warehouse, and the stock of a warehouse sent to another one ---- */
const DN_XFER_NOTE = 400;    // lines on one transfer note
const DN_XFER_MAX = 1200;    // lines in one action
/** One row per warehouse: items in stock, lines with a reservation, value at the last purchase price (only for those who may see prices). */
function stk_wh_summary(): array {
    if (!stk_has()) return []; $val = stk_price_visible(); $o = [];
    foreach (all('SELECT s.warehouse_id, SUM(s.qty_on_hand > 0.0005) AS n_items, SUM(s.qty_reserved > 0.0005) AS n_reserved, SUM(s.qty_on_hand < -0.0005) AS n_negative' . ($val ? ', SUM(IF(s.qty_on_hand > 0, s.qty_on_hand * COALESCE(i.last_price, 0), 0)) AS value' : '') . ' FROM inv_stock s JOIN inv_items i ON i.id = s.item_id GROUP BY s.warehouse_id') as $r) $o[(int)$r['warehouse_id']] = $r;
    return $o;
}
/** What a warehouse holds. $f: q, cat, show (instock | all | reserved | negative). Returns [rows, total]. */
function stk_wh_stock(int $whId, array $f = [], int $limit = 200, int $offset = 0): array {
    if (!stk_has() || !$whId) return [[], 0]; $w = ['s.warehouse_id = ?']; $a = [$whId]; $show = (string)($f['show'] ?? 'instock');
    $w[] = ['all' => '(ABS(s.qty_on_hand) > 0.0005 OR s.qty_reserved > 0.0005)', 'reserved' => 's.qty_reserved > 0.0005', 'negative' => 's.qty_on_hand < -0.0005'][$show] ?? 's.qty_on_hand > 0.0005';
    if (!empty($f['cat'])) { $w[] = '(i.category_id = ? OR i.category_id IN (SELECT id FROM inv_categories WHERE parent_id = ?))'; $a[] = (int)$f['cat']; $a[] = (int)$f['cat']; }
    if (trim((string)($f['q'] ?? '')) !== '') { $l = '%' . trim((string)$f['q']) . '%'; $w[] = '(i.code LIKE ? OR i.description LIKE ? OR i.factory_code LIKE ? OR i.maker LIKE ? OR s.location LIKE ?)'; array_push($a, $l, $l, $l, $l, $l); }
    $from = ' FROM inv_stock s JOIN inv_items i ON i.id = s.item_id LEFT JOIN inv_categories c ON c.id = i.category_id WHERE ' . implode(' AND ', $w);
    $total = (int) scalar('SELECT COUNT(*)' . $from, $a);
    $rows = all('SELECT s.item_id, s.qty_on_hand, s.qty_reserved, s.location, i.code, i.description, i.unit, i.maker, i.factory_code, i.last_price, i.currency, c.name AS category' . $from . ' ORDER BY i.code LIMIT ' . max(1, min(5000, $limit)) . ' OFFSET ' . max(0, $offset), $a);
    return [$rows, $total];
}
/** May this person look at the stock of the warehouse? Procurement, warehouse, management — and the holder of that warehouse. */
function stk_wh_can_view(?array $w): bool { if (function_exists('pr_can') && pr_can()) return true; $u = current_user(); return $w && $u && is_staff() && stk_wh_x_has() && (int)($w['holder_id'] ?? 0) === (int)$u['id']; }
/** The stock of a warehouse goes to another one with transfer notes. $pick null = everything in stock; otherwise [item id => quantity]. Reservations and serial numbers travel with the goods. Returns [note ids, lines moved, lines still in the warehouse]. */
function dn_xfer_stock(int $from, int $to, array $h, ?array $pick): array { @set_time_limit(180); return stk_tx(fn() => dn_xfer_stock_x($from, $to, $h, $pick)); }
function dn_xfer_stock_x(int $from, int $to, array $h, ?array $pick): array {
    require_pr_wh(); if (!dn_x_has()) throw new RuntimeException(t('err.noaccess'));
    if (!$from || !$to || $from === $to || (int) scalar('SELECT COUNT(*) FROM inv_warehouses WHERE id IN (?, ?) AND is_active = 1', [$from, $to]) !== 2) throw new RuntimeException(t('stk.need_to_wh'));
    $lines = []; $rest = 0;
    if ($pick === null) { [$rows, $total] = stk_wh_stock($from, ['show' => 'instock'], DN_XFER_MAX, 0); $rest = max(0, $total - count($rows)); foreach ($rows as $r) $lines[] = ['item_id' => (int)$r['item_id'], 'description' => (string)$r['description'], 'unit' => (string)$r['unit'], 'qty' => (float)$r['qty_on_hand']]; }
    else { $want = []; foreach ($pick as $iid => $q) { $q = (float) str_replace(',', '.', (string)$q); if ((int)$iid > 0 && $q > 0.0005) $want[(int)$iid] = $q; } if (count($want) > DN_XFER_MAX) throw new RuntimeException(t('whs.too_many', ['n' => DN_XFER_MAX]));
        if ($want) foreach (all('SELECT s.item_id, s.qty_on_hand, i.code, i.description, i.unit FROM inv_stock s JOIN inv_items i ON i.id = s.item_id WHERE s.warehouse_id = ? AND s.item_id IN (' . implode(',', array_keys($want)) . ') ORDER BY i.code', [$from]) as $r) { $q = $want[(int)$r['item_id']]; if ($q > (float)$r['qty_on_hand'] + 0.0005) throw new RuntimeException(t('whs.too_much', ['c' => (string)$r['code'], 'q' => stk_qty((float)$r['qty_on_hand'])])); $lines[] = ['item_id' => (int)$r['item_id'], 'description' => (string)$r['description'], 'unit' => (string)$r['unit'], 'qty' => $q]; } }
    if (!$lines) throw new RuntimeException(t('dn.need_lines'));
    $head = ['kind' => 'transfer', 'warehouse_id' => $from, 'to_warehouse_id' => $to, 'all_rsv' => true, 'auto_serials' => true, 'allow_negative' => false, 'project_id' => (int)($h['project_id'] ?? 0), 'subproject_id' => (int)($h['subproject_id'] ?? 0), 'to_user_id' => (int)($h['to_user_id'] ?? 0), 'issued_at' => (string)($h['issued_at'] ?? ''), 'carrier' => (string)($h['carrier'] ?? ''), 'ref_no' => (string)($h['ref_no'] ?? ''), 'note' => (string)($h['note'] ?? '')];
    $ids = []; foreach (array_chunk($lines, DN_XFER_NOTE) as $chunk) $ids[] = dn_create_x($head, $chunk);
    return [$ids, count($lines), $rest];
}
function dn_xfer_stock_from_post(): array {
    $all = ($_POST['mode'] ?? '') === 'all'; $pick = null;
    if (!$all) { $pick = []; foreach ((array)($_POST['pick'] ?? []) as $iid => $on) if ($on) $pick[(int)$iid] = (string)($_POST['xq'][$iid] ?? '0'); }
    return dn_xfer_stock((int)($_POST['from_wh'] ?? 0), (int)($_POST['to_warehouse_id'] ?? 0), ['project_id' => (int)($_POST['project_id'] ?? 0), 'to_user_id' => (int)($_POST['to_user_id'] ?? 0), 'issued_at' => (string)($_POST['issued_at'] ?? ''), 'carrier' => (string)($_POST['carrier'] ?? ''), 'ref_no' => (string)($_POST['ref_no'] ?? ''), 'note' => (string)($_POST['note'] ?? '')], $pick);
}

/* ---- R97-281: a new note assembled from the goods that transfer notes brought to a warehouse — a whole note, some lines, or part of a quantity ---- */
/** The lines of issued transfer notes with what has already been sent on and what is still there. */
function dn_fwd_lines(array $dnIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $dnIds)))); if (!$ids || !dn_x3_has()) return [];
    $rows = all("SELECT l.*, i.code AS item_code, i.maker, i.factory_code, d.dn_no, d.to_warehouse_id, d.project_id AS src_project_id, d.subproject_id AS src_subproject_id, d.issued_at,
                 (SELECT COALESCE(SUM(c.qty), 0) FROM inv_dn_lines c JOIN inv_delivery_notes n ON n.id = c.dn_id WHERE c.src_line_id = l.id AND n.status = 'issued') AS sent,
                 (SELECT COALESCE(SUM(r.qty), 0) FROM inv_dn_rsv r WHERE r.dn_line_id = l.id) AS travelled_rsv
                 FROM inv_dn_lines l JOIN inv_delivery_notes d ON d.id = l.dn_id LEFT JOIN inv_items i ON i.id = l.item_id
                 WHERE d.id IN (" . implode(',', $ids) . ") AND d.kind = 'transfer' AND d.status = 'issued' ORDER BY d.id, l.seq, l.id");
    foreach ($rows as &$r) { $r['qty'] = (float)$r['qty']; $r['sent'] = (float)$r['sent']; $r['left'] = max(0.0, round($r['qty'] - $r['sent'], 3)); } unset($r);
    return $rows;
}
/** Other transfer notes that still have goods in the same warehouse. */
function dn_fwd_sources(int $whId, array $except = []): array {
    if (!$whId || !dn_x3_has()) return []; $ex = array_filter(array_map('intval', $except));
    return all("SELECT d.id, d.dn_no, d.issued_at, p.project_code FROM inv_delivery_notes d LEFT JOIN projects p ON p.id = d.project_id WHERE d.kind = 'transfer' AND d.status = 'issued' AND d.to_warehouse_id = ?" . ($ex ? ' AND d.id NOT IN (' . implode(',', $ex) . ')' : '') . "
                AND EXISTS (SELECT 1 FROM inv_dn_lines l WHERE l.dn_id = d.id AND l.qty - (SELECT COALESCE(SUM(c.qty), 0) FROM inv_dn_lines c JOIN inv_delivery_notes n ON n.id = c.dn_id WHERE c.src_line_id = l.id AND n.status = 'issued') > 0.0005) ORDER BY d.id DESC LIMIT 60", [$whId]);
}
/** What was sent on from each line of a note: [line id => quantity]. */
function dn_fwd_sent(int $dnId): array { $o = []; if (!dn_x3_has()) return $o; foreach (all("SELECT c.src_line_id, SUM(c.qty) AS q FROM inv_dn_lines c JOIN inv_delivery_notes n ON n.id = c.dn_id JOIN inv_dn_lines l ON l.id = c.src_line_id WHERE l.dn_id = ? AND n.status = 'issued' GROUP BY c.src_line_id", [$dnId]) as $r) $o[(int)$r['src_line_id']] = (float)$r['q']; return $o; }
/** One new note from the picked quantities ($pick: [transfer line id => quantity]). Goods that travelled as a reservation of the same project are issued against their requisition line; the rest leaves as free stock. */
function dn_forward(array $h, array $pick): int { return stk_tx(fn() => dn_forward_x($h, $pick)); }
function dn_forward_x(array $h, array $pick): int {
    require_pr_wh(); if (!dn_x3_has()) throw new RuntimeException(t('err.noaccess'));
    $want = []; foreach ($pick as $lid => $q) { $q = (float) str_replace(',', '.', (string)$q); if ($q > 0.0005 && (int)$lid > 0) $want[(int)$lid] = $q; }
    if (!$want) throw new RuntimeException(t('dn.need_lines'));
    $ids = array_column(all('SELECT DISTINCT dn_id FROM inv_dn_lines WHERE id IN (' . implode(',', array_keys($want)) . ')'), 'dn_id');
    $rows = array_values(array_filter(dn_fwd_lines($ids), fn($r) => isset($want[(int)$r['id']]))); if (count($rows) !== count($want)) throw new RuntimeException(t('err.notfound'));
    q('SELECT id FROM inv_delivery_notes WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') FOR UPDATE');   // two people cannot send the same goods on at the same moment
    $rows = array_values(array_filter(dn_fwd_lines($ids), fn($r) => isset($want[(int)$r['id']])));
    $kind = in_array($h['kind'] ?? '', ['project', 'customer', 'production'], true) ? $h['kind'] : 'project'; $pid = (int)($h['project_id'] ?? 0); $wh = 0; $lines = [];
    foreach ($rows as $r) {
        if ($wh && $wh !== (int)$r['to_warehouse_id']) throw new RuntimeException(t('dn.fw_one_wh')); $wh = (int)$r['to_warehouse_id'];
        $q = $want[(int)$r['id']]; $c = (string)($r['item_code'] ?: $r['description']); if ($q > $r['left'] + 0.0005) throw new RuntimeException(t('dn.fw_too_much', ['c' => $c, 'q' => stk_qty($r['left'])]));
        $base = ['item_id' => (int)$r['item_id'], 'description' => (string)$r['description'], 'unit' => (string)$r['unit'], 'note' => (string)$r['dn_no'], 'src_line_id' => (int)$r['id'], 'skip_move' => !(int)$r['item_id']]; $rest = $q;
        if ((int)$r['item_id'] && $kind === 'project' && $pid && function_exists('prq_rsv_of')) foreach (all("SELECT pl.*, rq.project_id AS rq_project, rq.status AS rq_status FROM inv_dn_rsv x JOIN pr_lines pl ON pl.id = x.pr_line_id JOIN pr_requests rq ON rq.id = pl.request_id WHERE x.dn_line_id = ? ORDER BY pl.id", [(int)$r['id']]) as $pl) {
            if ($rest <= 0.0005) break; if ((int)$pl['rq_project'] !== $pid || $pl['rq_status'] !== 'approved' || !in_array($pl['status'], ['reserved', 'to_buy', 'received'], true)) continue;
            $tq = min((float)(prq_rsv_of($pl)[$wh] ?? 0.0), $rest); if ($tq <= 0.0005) continue;
            $lines[] = $base + ['qty' => $tq, 'pr_line_id' => (int)$pl['id']]; $rest = round($rest - $tq, 3);
        }
        if ($rest > 0.0005) $lines[] = $base + ['qty' => $rest];
    }
    return dn_create_x(['kind' => $kind, 'warehouse_id' => $wh, 'from_dn_id' => (int)$rows[0]['dn_id'], 'allow_negative' => false] + $h, $lines);
}
function dn_forward_from_post(): int {
    $pick = []; foreach ((array)($_POST['pick'] ?? []) as $lid => $on) if ($on) $pick[(int)$lid] = (string)($_POST['fq'][$lid] ?? '0');
    return dn_forward(['kind' => (string)($_POST['kind'] ?? 'project'), 'project_id' => (int)($_POST['project_id'] ?? 0), 'subproject_id' => (int)($_POST['subproject_id'] ?? 0), 'company_id' => (int)($_POST['company_id'] ?? 0), 'vessel_id' => (int)($_POST['vessel_id'] ?? 0), 'to_user_id' => (int)($_POST['to_user_id'] ?? 0), 'issued_at' => (string)($_POST['issued_at'] ?? ''), 'address' => (string)($_POST['address'] ?? ''), 'attention' => (string)($_POST['attention'] ?? ''), 'carrier' => (string)($_POST['carrier'] ?? ''), 'ref_no' => (string)($_POST['ref_no'] ?? ''), 'note' => (string)($_POST['note'] ?? '')], $pick);
}

/* ---- R97-280: the receiving member of staff (Service app and portal) ---- */
/** Tell the receiver: portal notification and a push that opens the note in the Service app. */
function dn_notify_to(int $id, int $uid): void {
    try { $d = dn_load($id); $me = current_user(); if (!$d || !function_exists('nf_push')) return;
        $body = $d['kind'] === 'transfer' ? trim((string)$d['wh_code'] . ' → ' . (string)($d['to_wh_code'] ?? '') . ' ' . (string)($d['to_warehouse'] ?? '')) : trim((string)($d['project_code'] ?? '') . ' ' . (string)($d['project_name'] ?? '') . ($d['vessel'] ? ' · ' . $d['vessel'] : ''));
        nf_push([$uid], 'dn:to', 'dn:' . $id, t('dn.nf_to', ['no' => $d['dn_no']]), $body, url('delivery_note', ['id' => $id]), $me ? (int)$me['id'] : null, $me ? (string)$me['full_name'] : null);
    } catch (Throwable $e) { error_log('[dn] notify: ' . $e->getMessage()); }
}
/** Name or change the receiver of an issued note that still waits. 0 = nobody. */
function dn_set_to_user(int $id, int $uid): void {
    if (!dn_x2_has() || !dn_can()) throw new RuntimeException(t('err.noaccess')); $d = dn_load($id); if (!$d || !dn_waits($d)) throw new RuntimeException(t('err.noaccess'));
    if ($uid && !scalar("SELECT id FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$uid])) throw new RuntimeException(t('err.notfound'));
    if ($uid === (int)($d['to_user_id'] ?? 0)) return;
    q('UPDATE inv_delivery_notes SET to_user_id = ?' . ($uid && $d['kind'] !== 'transfer' ? ", delivery = IF(delivery = 'open', 'sent', delivery)" : '') . ' WHERE id = ?', [$uid ?: null, $id]);
    if ($uid) { dn_event($id, 'sent', '📱 ' . (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$uid])); dn_notify_to($id, $uid); }
}
/** The notes of a person: those that wait for the acceptance, and the last ones answered. */
function dn_for_user(int $uid): array {
    if (!dn_x2_has() || $uid <= 0) return ['waiting' => [], 'recent' => []];
    $rows = all(dn_select() . " WHERE d.status = 'issued' AND (d.to_user_id = ? OR (d.kind = 'transfer' AND w2.holder_id = ?)) AND d.issued_at > CURDATE() - INTERVAL 180 DAY ORDER BY d.id DESC LIMIT 80", [$uid, $uid]);
    $w = []; $r = []; foreach ($rows as $d) { if (dn_waits($d) && $d['delivery'] !== 'disputed') $w[] = $d; elseif (count($r) < 25) $r[] = $d; }
    return ['waiting' => $w, 'recent' => $r];
}
/** The receiver answers: accepted (in the app with a drawn signature) or not received as listed (remark obligatory). A transfer becomes "received at the warehouse". */
function dn_user_decide(int $id, string $decision, string $remark, string $sig, string $via): string {
    $u = current_user(); $d = dn_load($id); if (!dn_x2_has() || !$u || !is_staff() || !$d || !dn_is_for($d, (int)$u['id'])) throw new RuntimeException(t('err.notfound'));
    if (!dn_waits($d)) throw new RuntimeException(t('dl.closed')); if (!in_array($decision, ['accepted', 'disputed'], true)) throw new RuntimeException(t('err.notfound'));
    $remark = mb_substr(trim($remark), 0, 2000); $via = $via === 'app' ? 'app' : 'portal'; $png = null; $ip = function_exists('sgn_ip') ? (sgn_ip() ?: null) : null;
    if ($decision === 'disputed') { if ($remark === '') throw new RuntimeException(t('dl.remark_needed')); }
    elseif ($via === 'app') { $png = dn_sig_png($sig); if ($png === null) throw new RuntimeException(t('sgn.sign_needed')); }
    $isX = $d['kind'] === 'transfer'; $state = $decision === 'disputed' ? 'disputed' : ($isX ? 'received' : 'accepted');
    $n = q("UPDATE inv_delivery_notes SET delivery = ?, accepted_name = ?, accepted_role = NULL, accepted_email = NULL, accepted_via = ?, accepted_at = NOW(), accepted_ip = ?, accepted_remark = ? WHERE id = ? AND status = 'issued' AND delivery IN (" . ($isX ? "'transit','disputed'" : "'open','sent','disputed'") . ')',
           [$state, mb_substr((string)$u['full_name'], 0, 90), $via, $ip, $remark ?: null, $id])->rowCount();
    if (!$n) throw new RuntimeException(t('dl.closed'));
    if ($png !== null) q('REPLACE INTO inv_dn_signs (dn_id, png) VALUES (?,?)', [$id, $png]); elseif ($decision === 'accepted') q('DELETE FROM inv_dn_signs WHERE dn_id = ?', [$id]);
    dn_event($id, $state, trim(t('dn.via_' . $via) . ($remark !== '' ? ' — ' . $remark : '')), null, $ip);
    try { if ((int)$d['user_id'] && (int)$d['user_id'] !== (int)$u['id'] && function_exists('nf_push')) nf_push([(int)$d['user_id']], $decision === 'disputed' ? 'dn:disp' : 'dn:recv', 'dn:' . $id, t($decision === 'disputed' ? 'dn.nf_disp' : ($isX ? 'dn.nf_recv' : 'dn.nf_acc'), ['no' => $d['dn_no']]), (string)$u['full_name'] . ($remark !== '' ? ' — ' . $remark : ''), url('delivery_note', ['id' => $id]), (int)$u['id'], (string)$u['full_name']); } catch (Throwable $e) { error_log('[dn] notify: ' . $e->getMessage()); }
    return $state;
}
/** Serial numbers of a tracked item follow a transfer to the other warehouse. */
function dn_sn_xfer_from_post(): int {
    require_pr_wh(); $d = dn_load((int)($_POST['dn_id'] ?? 0)); $mid = (int)($_POST['move_id'] ?? 0);
    if (!$d || $d['kind'] !== 'transfer' || $d['status'] !== 'issued' || !scalar('SELECT id FROM inv_dn_lines WHERE dn_id = ? AND move_id = ?', [(int)$d['id'], $mid])) throw new RuntimeException(t('err.notfound'));
    $list = array_merge(array_map('strval', (array)($_POST['pick'] ?? [])), sn_parse((string)($_POST['serials'] ?? '')));
    return (int) stk_tx(fn() => sn_transfer($mid, (int)$d['to_warehouse_id'], implode("\n", $list)));
}
/** Notes that carried goods onwards from the destination of a transfer. */
function dn_onward(int $id): array { return dn_x_has() ? all("SELECT id, dn_no, issued_at, status FROM inv_delivery_notes WHERE from_dn_id = ? ORDER BY id", [$id]) : []; }

/* ------------------------------------------------------------------ R97-037: parts of delivery notes → accounting
 * Every line of an issued delivery note to a project or a customer carries an invoicing state:
 *   open     procurement has not decided yet
 *   invoice  to be invoiced — waits at accounting (with the unit sale price procurement proposes)
 *   offer    included in the offer — nothing to invoice separately
 *   invoiced accounting registered the invoice number and date
 * Procurement (pr_admin) decides, accounting (acc_is_accounting) registers the invoice or sends a line back. */
const DNI_STATES = ['open', 'invoice', 'offer', 'invoiced'];
function dni_has(): bool { static $h = null; if ($h === null) { try { $h = dn_has() && (bool) scalar("SHOW COLUMNS FROM inv_dn_lines LIKE 'inv_state'"); } catch (Throwable $e) { $h = false; } } return $h; }
function dni_can_decide(): bool { return dni_has() && function_exists('pr_admin') && pr_admin(); }
function dni_can_invoice(): bool { return dni_has() && function_exists('acc_is_accounting') && acc_is_accounting(); }
function dni_visible(): bool { return dni_has() && is_staff() && (dni_can_decide() || dni_can_invoice() || (function_exists('pr_can') && pr_can())); }
function dni_markup(): float { return function_exists('sb_min_profit') && sb_min_profit() > 0 ? sb_min_profit() : 40.0; }
function dni_pill(string $s): string { return pill(['open' => 'gold', 'invoice' => 'navy', 'offer' => 'mute', 'invoiced' => 'green'][$s] ?? 'mute', t('dni.s_' . $s)); }
const DNI_FROM = "FROM inv_dn_lines l JOIN inv_delivery_notes d ON d.id = l.dn_id LEFT JOIN projects p ON p.id = d.project_id LEFT JOIN subprojects s ON s.id = d.subproject_id
                  LEFT JOIN vessels v ON v.id = COALESCE(d.vessel_id, p.vessel_id) LEFT JOIN companies co ON co.id = d.company_id LEFT JOIN inv_items i ON i.id = l.item_id LEFT JOIN inv_moves m ON m.id = l.move_id
                  WHERE d.status = 'issued' AND d.kind IN ('project','customer')";
/** Lines in one state (optionally of one vessel), oldest delivery first. cost = unit cost of the stock movement, else the item's last price. */
function dni_rows(string $state, int $vesselId = 0, int $limit = 600): array {
    if (!dni_has() || !in_array($state, DNI_STATES, true)) return [];
    $w = ' AND l.inv_state = ?'; $a = [$state]; if ($vesselId) { $w .= ' AND COALESCE(d.vessel_id, p.vessel_id) = ?'; $a[] = $vesselId; }
    return all("SELECT l.id, l.dn_id, l.description, l.unit, l.qty, l.inv_state, l.inv_price, l.inv_ref, l.inv_date, l.inv_name, l.dec_name, l.dec_at, d.dn_no, d.issued_at, d.kind, d.project_id, d.subproject_id,
                       p.project_code, p.name AS project_name, s.code AS sub_code, v.id AS vessel_id, v.name AS vessel, co.name AS company, i.code AS item_code, COALESCE(NULLIF(m.unit_price, 0), i.last_price) AS cost, COALESCE(i.currency, 'EUR') AS currency
                " . DNI_FROM . $w . ' ORDER BY ' . ($state === 'invoiced' ? 'l.inv_date DESC, l.id DESC' : 'd.issued_at, d.id, l.seq, l.id') . ' LIMIT ' . (int)$limit, $a);
}
function dni_counts(): array { $c = array_fill_keys(DNI_STATES, 0); if (!dni_has()) return $c; try { foreach (all("SELECT l.inv_state st, COUNT(*) n " . DNI_FROM . " GROUP BY l.inv_state") as $r) if (isset($c[$r['st']])) $c[$r['st']] = (int)$r['n']; } catch (Throwable $e) {} return $c; }
function dni_num(string $s): ?float { $s = trim(str_replace(["\xC2\xA0", ' '], '', $s)); if ($s === '') return null; if (str_contains($s, ',')) $s = str_replace(',', '.', str_replace('.', '', $s)); return is_numeric($s) ? max(0.0, min(9999999.0, round((float)$s, 2))) : null; }
/** Procurement: decide the lines posted (d[lineId] = invoice | offer | open, price[lineId] = unit sale price). Invoiced lines are never touched. */
function dni_decide_from_post(): array {
    if (!dni_can_decide()) throw new RuntimeException(t('err.noaccess')); $u = current_user(); $n = ['invoice' => 0, 'offer' => 0, 'open' => 0];
    foreach ((array)($_POST['d'] ?? []) as $lid => $st) {
        $lid = (int)$lid; $st = (string)$st; if (!$lid || !in_array($st, ['invoice', 'offer', 'open'], true)) continue;
        $price = $st === 'invoice' ? dni_num((string)($_POST['price'][$lid] ?? '')) : null;
        $c = q("UPDATE inv_dn_lines l JOIN inv_delivery_notes d ON d.id = l.dn_id SET l.inv_state = ?, l.inv_price = ?, l.dec_by = ?, l.dec_name = ?, l.dec_at = NOW() WHERE l.id = ? AND l.inv_state <> 'invoiced' AND d.status = 'issued' AND d.kind IN ('project','customer') AND (l.inv_state <> ? OR NOT (l.inv_price <=> ?))",
               [$st, $price, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $lid, $st, $price]);
        if ($c && method_exists($c, 'rowCount') && $c->rowCount()) $n[$st]++;
    }
    if ($n['invoice'] && function_exists('nf_push')) { try { $to = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role IN ('staff','admin') AND is_accounting = 1"), 'id')); $to = array_values(array_diff($to, [(int)($u['id'] ?? 0)]));
        if ($to) nf_push($to, 'dni:invoice', 'dni:' . date('Ymd'), t('dni.nf_title', ['n' => $n['invoice']]), (string)($u['full_name'] ?? ''), url('dn_invoicing', ['state' => 'invoice']), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')); } catch (Throwable $e) {} }
    return $n;
}
/** Accounting: register the invoice on the ticked lines that wait to be invoiced. */
function dni_invoice_from_post(): int {
    if (!dni_can_invoice()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))))); if (!$ids) throw new RuntimeException(t('dni.none_ticked'));
    $ref = mb_substr(trim((string)($_POST['invoice_ref'] ?? '')), 0, 120); if ($ref === '') throw new RuntimeException(t('ts.ref_req'));
    $date = (string)($_POST['invoice_date'] ?? ''); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) throw new RuntimeException(t('acc.cor_bad_date'));
    $u = current_user(); $c = q("UPDATE inv_dn_lines SET inv_state = 'invoiced', inv_ref = ?, inv_date = ?, inv_by = ?, inv_name = ?, inv_at = NOW() WHERE inv_state = 'invoice' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$ref, $date, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')], $ids));
    return $c && method_exists($c, 'rowCount') ? $c->rowCount() : count($ids);
}
/** Accounting: send ticked lines back — waiting lines return to procurement (open); invoiced lines return to "to be invoiced" (a wrong entry). */
function dni_back_from_post(): int {
    if (!dni_can_invoice()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? []))))); if (!$ids) throw new RuntimeException(t('dni.none_ticked')); $in = implode(',', $ids);
    $c1 = q("UPDATE inv_dn_lines SET inv_state = 'invoice', inv_ref = NULL, inv_date = NULL, inv_by = NULL, inv_name = NULL, inv_at = NULL WHERE inv_state = 'invoiced' AND id IN ($in) AND ? = 'invoiced'", [(string)($_POST['from'] ?? '')]);
    $c2 = q("UPDATE inv_dn_lines SET inv_state = 'open' WHERE inv_state = 'invoice' AND id IN ($in) AND ? = 'invoice'", [(string)($_POST['from'] ?? '')]);
    return ($c1 && method_exists($c1, 'rowCount') ? $c1->rowCount() : 0) + ($c2 && method_exists($c2, 'rowCount') ? $c2->rowCount() : 0);
}

/* ------------------------------------------------------------------ R97-109: what the warehouse holds per vessel, project and sub-project — and delivery notes made from a selection */
/** Lines of approved project requisitions (goods bought for the project are reserved at receipt; stock covering a requisition is reserved at approval) and lines of
 *  project purchase orders that did not come from a requisition. Each row: key, src (pr|po), ids, texts, qty, received, ready (in the warehouse for the project), sent, to come, origin. */
function whr_rows(array $f = []): array {
    if (!dn_has()) return []; $w = ''; $a = [];
    if (!empty($f['project_id'])) { $w .= ' AND p.id = ?'; $a[] = (int)$f['project_id']; }
    if (!empty($f['subproject_id'])) { $w .= ' AND s.id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['vessel_id'])) { $w .= ' AND p.vessel_id = ?'; $a[] = (int)$f['vessel_id']; }
    if (!empty($f['ids']) && is_array($f['ids'])) { foreach (['pr' => 'l.id', 'po' => 'pl.id'] as $k => $col) { $ids = array_map('intval', $f['ids'][$k] ?? []); $f['_in'][$k] = ' AND ' . $col . ' IN (' . ($ids ? implode(',', $ids) : '0') . ')'; } }
    $head = "p.id AS project_id, p.project_code, p.name AS project_name, p.vessel_id, v.name AS vessel, s.id AS subproject_id, s.code AS sub_code, s.name AS sub_name";
    $rows = [];
    try {
        foreach (all("SELECT 'pr' AS src, l.id AS line_id, r.id AS doc_id, r.pr_no AS doc_no, $head, l.item_id, i.code AS item_code, l.description, l.unit, l.qty, l.qty_received AS received, l.qty_reserved AS ready, l.qty_issued AS sent, l.qty_ordered AS ordered, COALESCE(l.warehouse_id, 0) AS warehouse_id
                      FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN inv_items i ON i.id = l.item_id
                      WHERE r.status IN ('approved','closed') AND l.status <> 'cancelled' AND l.item_id IS NOT NULL" . $w . ($f['_in']['pr'] ?? '') . " ORDER BY v.name, p.project_code, s.code, r.id, l.seq LIMIT 3000", $a) as $x) $rows[] = $x;
        if (function_exists('po_has') && po_has()) foreach (all("SELECT 'po' AS src, pl.id AS line_id, o.id AS doc_id, o.po_no AS doc_no, $head, pl.item_id, i.code AS item_code, pl.description, pl.unit, pl.qty, pl.qty_accepted AS received, 0 AS ready,
                      (SELECT COALESCE(SUM(d.qty), 0) FROM inv_dn_lines d JOIN inv_delivery_notes n ON n.id = d.dn_id WHERE d.po_line_id = pl.id AND n.status = 'issued') AS sent, pl.qty AS ordered, COALESCE(o.warehouse_id, 0) AS warehouse_id
                      FROM po_lines pl JOIN po_orders o ON o.id = pl.order_id JOIN projects p ON p.id = o.project_id LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN inv_items i ON i.id = pl.item_id
                      WHERE o.kind = 'po' AND o.status NOT IN ('draft','cancelled') AND pl.status <> 'cancelled' AND (pl.pr_line_id IS NULL OR pl.item_id IS NULL)" . $w . ($f['_in']['po'] ?? '') . " ORDER BY v.name, p.project_code, s.code, o.id, pl.seq LIMIT 3000", $a) as $x) $rows[] = $x;
    } catch (Throwable $e) { return []; }
    $out = []; $show = (string)($f['show'] ?? 'ready');
    foreach ($rows as $x) {
        $x['qty'] = (float)$x['qty']; $x['received'] = (float)$x['received']; $x['sent'] = (float)$x['sent'];
        if ($x['src'] === 'po') { $x['ready'] = max(0.0, $x['received'] - $x['sent']); $x['origin'] = 'order'; $x['come'] = max(0.0, $x['qty'] - $x['received']); }
        else { $x['ready'] = (float)$x['ready']; $x['origin'] = $x['received'] > 0.0005 ? 'order' : 'stock'; $x['come'] = max(0.0, $x['qty'] - $x['ready'] - $x['sent']); }
        $x['key'] = $x['src'] . '-' . (int)$x['line_id'];
        if ($show === 'ready' && $x['ready'] <= 0.0005) continue; if ($show === 'open' && $x['ready'] <= 0.0005 && $x['come'] <= 0.0005) continue;
        $out[] = $x;
    }
    usort($out, fn($a, $b) => [(string)$a['vessel'], (string)$a['project_code'], (string)$a['sub_code'], $a['src'], (int)$a['doc_id'], (int)$a['line_id']] <=> [(string)$b['vessel'], (string)$b['project_code'], (string)$b['sub_code'], $b['src'], (int)$b['doc_id'], (int)$b['line_id']]);
    return $out;
}
/** Delivery notes from the ticked lines: one per sub-project (or one per project) and warehouse. Quantities are checked again against what is in the warehouse now. Returns the ids. */
function whr_create_from_post(): array {
    require_pr_wh(); $pick = (array)($_POST['pick'] ?? []); $qty = (array)($_POST['qty'] ?? []); $ids = ['pr' => [], 'po' => []];
    foreach ($pick as $k => $on) { if (!$on || !preg_match('/^(pr|po)-(\d+)$/', (string)$k, $m)) continue; $ids[$m[1]][] = (int)$m[2]; }
    if (!$ids['pr'] && !$ids['po']) throw new RuntimeException(t('whr.need_pick'));
    $perSub = ($_POST['split'] ?? 'sub') !== 'project'; $G = [];
    foreach (whr_rows(['ids' => $ids, 'show' => 'all']) as $x) {
        $q = (float) str_replace(',', '.', (string)($qty[$x['key']] ?? '')); if ($q <= 0) continue;
        if ($q > $x['ready'] + 0.0005) throw new RuntimeException(t('whr.too_much', ['c' => (string)($x['item_code'] ?: $x['description']), 'q' => stk_qty($x['ready'])]));
        $wh = (int)$x['warehouse_id'] ?: stk_default_wh(); $gk = (int)$x['project_id'] . '|' . ($perSub ? (int)$x['subproject_id'] : 0) . '|' . $wh;
        $G[$gk] = $G[$gk] ?? ['project_id' => (int)$x['project_id'], 'subproject_id' => $perSub ? (int)$x['subproject_id'] : 0, 'warehouse_id' => $wh, 'lines' => []];
        $G[$gk]['lines'][] = ['item_id' => (int)$x['item_id'], 'description' => (string)$x['description'], 'unit' => (string)$x['unit'], 'qty' => $q, 'pr_line_id' => $x['src'] === 'pr' ? (int)$x['line_id'] : 0, 'po_line_id' => $x['src'] === 'po' ? (int)$x['line_id'] : 0, 'skip_move' => !(int)$x['item_id'], 'note' => (string)$x['doc_no']];
    }
    if (!$G) throw new RuntimeException(t('whr.need_pick'));
    $h = ['kind' => 'project', 'issued_at' => (string)($_POST['issued_at'] ?? ''), 'address' => (string)($_POST['address'] ?? ''), 'attention' => (string)($_POST['attention'] ?? ''), 'carrier' => (string)($_POST['carrier'] ?? ''), 'ref_no' => (string)($_POST['ref_no'] ?? ''), 'note' => (string)($_POST['note'] ?? '')];
    $out = []; $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try { foreach ($G as $g) $out[] = dn_create($h + ['project_id' => $g['project_id'], 'subproject_id' => $g['subproject_id'], 'warehouse_id' => $g['warehouse_id']], $g['lines']); if ($own) $pdo->commit(); }
    catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $out;
}


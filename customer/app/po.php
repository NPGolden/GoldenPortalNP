<?php
/**
 * RFQs and purchase orders.                                   GAS-CP-R80-003
 * Procurement groups the "to buy" lines of approved requisitions (and BOM requisitions) per
 * supplier into an RFQ (prices asked) or straight into a purchase order; RFQ quotes are typed per
 * line and awarded into a PO. A PO has its own number series per branch (<prefix>-PO-<yy>-<nnnn>),
 * a PDF, the ✉ card to the supplier's people, and states draft → sent → confirmed → partial →
 * received → closed (goods receipt per line is R80-004). Ordered quantities flow back to the
 * requisition lines; approved alternatives of an item are offered when a line is added.
 * Tables: po_orders · po_lines · po_events.
 */
declare(strict_types=1);

const PO_STATES   = ['draft', 'sent', 'quoted', 'awarded', 'confirmed', 'partial', 'received', 'closed', 'cancelled'];
const PO_TONE     = ['draft' => 'mute', 'sent' => 'gold', 'quoted' => 'navy', 'awarded' => 'ok', 'confirmed' => 'ok', 'partial' => 'gold', 'received' => 'ok', 'closed' => 'navy', 'cancelled' => 'mute'];
const PO_OPEN     = ['sent', 'confirmed', 'partial'];
const PO_INCOTERMS = ['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP', 'FOB', 'CFR', 'CIF'];

function po_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'po_orders'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function po_can(): bool { return po_has() && function_exists('pr_can') && pr_can(); }
function po_admin(): bool { return po_has() && function_exists('pr_admin') && pr_admin(); }
function require_po_admin(): array { $u = require_staff(); if (!po_admin()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function po_pill(string $s): string { return pill(PO_TONE[$s] ?? 'mute', t('po.s_' . $s)); }
function po_money(?float $v, string $cur = 'EUR'): string { return $v === null ? '—' : number_format($v, 2, ',', '.') . ' ' . $cur; }

/* ------------------------------------------------------------------ read */
const PO_SELECT = "SELECT o.*, co.name AS supplier, co.email AS supplier_co_email, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, w.name AS warehouse,
                   (SELECT COUNT(*) FROM po_lines l WHERE l.order_id = o.id AND l.status <> 'cancelled') AS n_lines,
                   (SELECT COUNT(*) FROM po_lines l WHERE l.order_id = o.id AND l.status IN ('open','partial')) AS n_open
                   FROM po_orders o JOIN companies co ON co.id = o.supplier_id LEFT JOIN projects p ON p.id = o.project_id LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN inv_warehouses w ON w.id = o.warehouse_id";
function po_load(int $id): ?array { return po_has() && $id ? one(PO_SELECT . ' WHERE o.id = ?', [$id]) ?: null : null; }
function po_list(array $f = []): array {
    if (!po_has()) return []; $w = []; $a = [];
    $w[] = 'o.kind = ?'; $a[] = ($f['kind'] ?? 'po') === 'rfq' ? 'rfq' : 'po';
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "o.status IN ('draft','sent','quoted','awarded','confirmed','partial')"; elseif (in_array($f['status'], PO_STATES, true)) { $w[] = 'o.status = ?'; $a[] = $f['status']; } }
    if (!empty($f['supplier_id'])) { $w[] = 'o.supplier_id = ?'; $a[] = (int)$f['supplier_id']; }
    if (!empty($f['subproject_id'])) { $w[] = 'o.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['project_id'])) { $w[] = 'o.project_id = ?'; $a[] = (int)$f['project_id']; }
    if (!empty($f['q'])) { $l = '%' . trim((string)$f['q']) . '%'; $w[] = '(o.po_no LIKE ? OR o.title LIKE ? OR co.name LIKE ? OR o.supplier_ref LIKE ? OR p.project_code LIKE ? OR EXISTS (SELECT 1 FROM po_lines l WHERE l.order_id = o.id AND (l.description LIKE ? OR l.part_no LIKE ?)))'; array_push($a, $l, $l, $l, $l, $l, $l, $l); }
    return all(PO_SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY o.id DESC', $a);
}
function po_lines(int $oid): array { return all('SELECT l.*, i.code AS item_code, i.unit AS item_unit, i.currency AS item_cur, a.code AS alt_of_code, pl.request_id AS pr_id, pr.pr_no FROM po_lines l LEFT JOIN inv_items i ON i.id = l.item_id LEFT JOIN inv_items a ON a.id = l.alt_of_item_id LEFT JOIN pr_lines pl ON pl.id = l.pr_line_id LEFT JOIN pr_requests pr ON pr.id = pl.request_id WHERE l.order_id = ? ORDER BY l.seq, l.id', [$oid]); }
function po_events(int $oid): array { return all('SELECT * FROM po_events WHERE order_id = ? ORDER BY id DESC', [$oid]); }
function po_event(int $oid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO po_events (order_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)', [$oid, $ev, $u['id'] ?? null, $u['full_name'] ?? null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $note !== null ? mb_substr($note, 0, 600) : null]); }
function po_counts(): array {
    if (!po_can()) return ['draft' => 0, 'open' => 0, 'rfq' => 0, 'late' => 0];
    try { return ['draft' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'po' AND status = 'draft'"), 'open' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'po' AND status IN ('sent','confirmed','partial')"), 'rfq' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'rfq' AND status IN ('draft','sent','quoted')"), 'late' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'po' AND status IN ('sent','confirmed','partial') AND COALESCE(expected_date, delivery_date) IS NOT NULL AND COALESCE(expected_date, delivery_date) < CURDATE()")]; } catch (Throwable $e) { return ['draft' => 0, 'open' => 0, 'rfq' => 0, 'late' => 0]; }
}
/** Approved requisition lines still to buy (qty − reserved − ordered), optionally of one sub-project — the input of a new RFQ / PO. */
function po_to_buy(array $f = []): array {
    $w = ["r.status = 'approved'", "l.status IN ('to_buy','ordered')", 'l.qty - l.qty_reserved - l.qty_ordered > 0.0005']; $a = [];
    if (!empty($f['subproject_id'])) { $w[] = 'r.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['project_id'])) { $w[] = 'r.project_id = ?'; $a[] = (int)$f['project_id']; }
    $rows = all('SELECT l.*, r.pr_no, r.project_id, r.subproject_id, r.need_date AS r_need, p.project_code, s.code AS sub_code, i.code AS item_code, i.maker AS item_maker, i.preferred_supplier_id, i.last_supplier_id, ps.name AS preferred_supplier, ls.name AS last_supplier, l.qty - l.qty_reserved - l.qty_ordered AS open_qty
                 FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id LEFT JOIN inv_items i ON i.id = l.item_id LEFT JOIN companies ps ON ps.id = i.preferred_supplier_id LEFT JOIN companies ls ON ls.id = i.last_supplier_id
                 WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(i.preferred_supplier_id, i.last_supplier_id, 0), r.id, l.seq', $a);
    if (function_exists('sup_for_maker')) foreach ($rows as &$r) { if (!$r['preferred_supplier_id'] && !$r['last_supplier_id']) { $mk = sup_for_maker($r['item_maker'] ?: $r['maker']); if ($mk) { $r['maker_supplier_id'] = (int)$mk[0]['id']; $r['maker_supplier'] = $mk[0]['name']; } } } unset($r);   // R82-002: the supplier of the maker when nothing was bought yet
    return $rows;
}

/* ------------------------------------------------------------------ numbering */
function po_number(string $kind, ?int $branchId): string {
    $b = null; if (function_exists('br_all') && $branchId) foreach (br_all() as $bb) if ((int)$bb['id'] === $branchId) $b = $bb;
    if (!$b && function_exists('br_default')) $b = br_default();
    $prefix = (string)(($b['doc_prefix'] ?? '') ?: 'GAS'); $yy = date('y'); $fam = ($kind === 'rfq' ? 'RFQ' : 'PO') . ':' . $prefix . ':' . $yy;
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['portal', $fam]);
    return $prefix . '-' . ($kind === 'rfq' ? 'RFQ' : 'PO') . '-' . $yy . '-' . str_pad((string)(int) db()->lastInsertId(), 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ write */
function po_recalc(int $oid): void {
    $o = one('SELECT vat_pct FROM po_orders WHERE id = ?', [$oid]); $sub = 0.0;
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled'", [$oid]) as $l) { $lt = $l['unit_price'] === null ? null : round((float)$l['qty'] * (float)$l['unit_price'] * (1 - (float)$l['discount_pct'] / 100), 2); q('UPDATE po_lines SET line_total = ? WHERE id = ?', [$lt, (int)$l['id']]); $sub += (float)$lt; }
    $vat = round($sub * (float)($o['vat_pct'] ?? 0) / 100, 2);
    q('UPDATE po_orders SET subtotal = ?, vat_total = ?, gross_total = ? WHERE id = ?', [round($sub, 2), $vat, round($sub + $vat, 2), $oid]);
}
function po_editable(array $o): bool { return po_admin() && in_array($o['status'], ['draft', 'sent', 'quoted'], true) && ($o['kind'] === 'rfq' || $o['status'] === 'draft'); }
/** Header create / update. */
function po_save_from_post(): int {
    require_po_admin(); $id = (int)($_POST['id'] ?? 0); $u = current_user();
    $sup = (int)($_POST['supplier_id'] ?? 0); $S = $sup ? sup_load($sup) : null; if (!$S || !($S['kind'] === 'supplier' || !empty($S['is_supplier']))) throw new RuntimeException(t('po.need_supplier'));
    $pid = (int)($_POST['project_id'] ?? 0) ?: null; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if ($sid) { $pp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp) $sid = null; elseif (!$pid) $pid = $pp; elseif ($pp !== $pid) $sid = null; }
    $v = ['supplier_id' => $sup, 'project_id' => $pid, 'subproject_id' => $sid, 'title' => mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160) ?: null,
          'currency' => preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : (string)(($S['sup_currency'] ?? '') ?: 'EUR'),
          'incoterms' => in_array($_POST['incoterms'] ?? '', PO_INCOTERMS, true) ? $_POST['incoterms'] : ($S['incoterms'] ?? null),
          'terms_days' => ($_POST['terms_days'] ?? '') !== '' ? max(0, min(365, (int)$_POST['terms_days'])) : ($S['terms_days'] ?? null),
          'advance_pct' => ($_POST['advance_pct'] ?? '') !== '' ? max(0.0, min(100.0, (float) str_replace(',', '.', (string)$_POST['advance_pct']))) : ($S['advance_pct'] ?? null),
          'delivery_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['delivery_date'] ?? '')) ? $_POST['delivery_date'] : null,
          'warehouse_id' => (int)($_POST['warehouse_id'] ?? 0) ?: stk_default_wh(),
          'vat_pct' => max(0.0, min(30.0, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? '0')))),
          'supplier_contact' => mb_substr(trim((string)($_POST['supplier_contact'] ?? '')), 0, 120) ?: null,
          'supplier_email' => filter_var(trim((string)($_POST['supplier_email'] ?? '')), FILTER_VALIDATE_EMAIL) ? strtolower(trim((string)$_POST['supplier_email'])) : ($S['email'] ?? null),
          'supplier_ref' => mb_substr(trim((string)($_POST['supplier_ref'] ?? '')), 0, 80) ?: null,
          'note' => trim((string)($_POST['note'] ?? '')) ?: null, 'internal_note' => trim((string)($_POST['internal_note'] ?? '')) ?: null];
    if ($id) {
        $o = po_load($id); if (!$o || !po_editable($o)) throw new RuntimeException(t('err.noaccess'));
        if ($o['status'] !== 'draft') unset($v['supplier_id']);   // the supplier of a sent RFQ cannot change
        q('UPDATE po_orders SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ' WHERE id = ?', array_merge(array_values($v), [$id])); po_recalc($id); po_event($id, 'edit');
    } else {
        $kind = ($_POST['kind'] ?? 'po') === 'rfq' ? 'rfq' : 'po'; $br = null; try { $br = $pid ? ((int) scalar('SELECT branch_id FROM projects WHERE id = ?', [$pid]) ?: null) : (function_exists('br_default') ? (int)br_default()['id'] : null); } catch (Throwable $e) {}
        $v = ['kind' => $kind, 'po_no' => po_number($kind, $br), 'branch_id' => $br] + $v + ['created_by' => (int)$u['id'], 'created_name' => $u['full_name']];
        q('INSERT INTO po_orders (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
        $id = (int) db()->lastInsertId(); po_event($id, 'create', $v['po_no']);
        foreach (array_map('intval', (array)($_POST['pr_line'] ?? [])) as $plid) if ($plid) po_line_from_pr($id, $plid);
        po_recalc($id);
    }
    return $id;
}
/** Add a line from a requisition line (open quantity), priced by the pricing rule for this supplier. */
function po_line_from_pr(int $oid, int $plid): ?int {
    $o = po_load($oid); $l = one("SELECT l.*, r.status AS r_status FROM pr_lines l JOIN pr_requests r ON r.id = l.request_id WHERE l.id = ?", [$plid]);
    if (!$o || !$l || $l['r_status'] !== 'approved') return null;
    $open = (float)$l['qty'] - (float)$l['qty_reserved'] - (float)$l['qty_ordered']; if ($open <= 0.0005) return null;
    if (scalar('SELECT 1 FROM po_lines WHERE order_id = ? AND pr_line_id = ?', [$oid, $plid])) return null;
    $it = $l['item_id'] ? stk_item((int)$l['item_id']) : null; $price = null; $origin = null;
    if ($it && $o['kind'] === 'po') { $p = stk_price_proposal($it, (int)$o['supplier_id']); if ($p['price'] !== null) { $price = $p['price']; $origin = mb_substr($p['origin'], 0, 160); } }
    elseif (!$it && $o['kind'] === 'po' && $l['est_price'] !== null) { $price = (float)$l['est_price']; $origin = t('prq.est_typed'); }
    $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM po_lines WHERE order_id = ?', [$oid]);
    q('INSERT INTO po_lines (order_id, seq, item_id, description, maker, part_no, unit, qty, unit_price, price_origin, need_date, pr_line_id, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$oid, $seq, $l['item_id'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], round($open, 3), $price, $origin, $l['need_date'], $plid, $l['note']]);
    return (int) db()->lastInsertId();
}
function po_line_save_from_post(): int {
    $oid = (int)($_POST['order_id'] ?? 0); $o = po_load($oid); if (!$o || !po_editable($o)) throw new RuntimeException(t('err.noaccess'));
    $lid = (int)($_POST['line_id'] ?? 0); $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '0')); if ($qty <= 0) throw new RuntimeException(t('prq.need_qty'));
    if (!empty($_POST['new_item']) && function_exists('stk_item_quick_create')) $_POST['item_id'] = stk_item_quick_create(['code' => $_POST['new_code'] ?? '', 'description' => $_POST['description'] ?? '', 'maker' => $_POST['maker'] ?? '', 'part_no' => $_POST['part_no'] ?? '', 'unit' => $_POST['unit'] ?? 'pcs', 'category_id' => $_POST['new_category_id'] ?? 0, 'supplier_id' => $o['supplier_id']]);   // R86-002: "+ New item" from the line form
    $itemId = (int)($_POST['item_id'] ?? 0) ?: null; $it = $itemId ? stk_item($itemId) : null; if ($itemId && !$it) throw new RuntimeException(t('err.notfound'));
    $altOf = (int)($_POST['alt_of_item_id'] ?? 0) ?: null;
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255); if ($it) $desc = $desc ?: (string)$it['description']; if ($desc === '') throw new RuntimeException(t('prq.need_desc'));
    $unit = $it ? (string)$it['unit'] : (in_array($_POST['unit'] ?? '', STK_UNITS, true) ? $_POST['unit'] : 'pcs');
    $maker = $it ? ($it['maker'] ?? null) : (mb_substr(trim((string)($_POST['maker'] ?? '')), 0, 80) ?: null); $pn = $it ? ($it['factory_code'] ?? null) : (mb_substr(trim((string)($_POST['part_no'] ?? '')), 0, 80) ?: null);
    $price = ($_POST['unit_price'] ?? '') !== '' ? round((float) str_replace(',', '.', (string)$_POST['unit_price']), 4) : null; $origin = $price !== null ? t('po.price_typed') : null;
    if ($price === null && $it && $o['kind'] === 'po') { $p = stk_price_proposal($it, (int)$o['supplier_id']); if ($p['price'] !== null) { $price = $p['price']; $origin = mb_substr($p['origin'], 0, 160); } }
    $disc = max(0.0, min(100.0, (float) str_replace(',', '.', (string)($_POST['discount_pct'] ?? '0')))); $lead = ($_POST['lead_days'] ?? '') !== '' ? max(0, min(999, (int)$_POST['lead_days'])) : null;
    $need = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['need_date'] ?? '')) ? $_POST['need_date'] : null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    if ($lid) { if (!scalar('SELECT 1 FROM po_lines WHERE id = ? AND order_id = ?', [$lid, $oid])) throw new RuntimeException(t('err.notfound')); q('UPDATE po_lines SET item_id = ?, alt_of_item_id = ?, description = ?, maker = ?, part_no = ?, unit = ?, qty = ?, unit_price = ?, price_origin = ?, discount_pct = ?, lead_days = ?, need_date = ?, note = ? WHERE id = ?', [$itemId, $altOf, $desc, $maker, $pn, $unit, $qty, $price, $origin, $disc, $lead, $need, $note, $lid]); }
    else { $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM po_lines WHERE order_id = ?', [$oid]); q('INSERT INTO po_lines (order_id, seq, item_id, alt_of_item_id, description, maker, part_no, unit, qty, unit_price, price_origin, discount_pct, lead_days, need_date, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$oid, $seq, $itemId, $altOf, $desc, $maker, $pn, $unit, $qty, $price, $origin, $disc, $lead, $need, $note]); $lid = (int) db()->lastInsertId(); }
    po_recalc($oid); return $lid;
}
function po_line_delete(int $oid, int $lid): void { $o = po_load($oid); if (!$o || !po_editable($o)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM po_lines WHERE id = ? AND order_id = ?', [$lid, $oid]); po_recalc($oid); }
/** Quote typed on a sent RFQ: price, lead time per line + supplier reference. */
function po_quote_from_post(): void {
    $oid = (int)($_POST['id'] ?? 0); $o = po_load($oid); if (!$o || $o['kind'] !== 'rfq' || !in_array($o['status'], ['sent', 'quoted'], true) || !po_admin()) throw new RuntimeException(t('err.noaccess'));
    foreach ((array)($_POST['price'] ?? []) as $lid => $pv) { $lid = (int)$lid; $price = trim((string)$pv) !== '' ? round((float) str_replace(',', '.', (string)$pv), 4) : null; $lead = trim((string)($_POST['lead'][$lid] ?? '')) !== '' ? max(0, min(999, (int)$_POST['lead'][$lid])) : null; q('UPDATE po_lines SET unit_price = ?, price_origin = ?, lead_days = ? WHERE id = ? AND order_id = ?', [$price, $price !== null ? t('po.price_quoted') : null, $lead, $lid, $oid]); }
    q('UPDATE po_orders SET status = ?, supplier_ref = ?, delivery_date = ? WHERE id = ?', ['quoted', mb_substr(trim((string)($_POST['supplier_ref'] ?? '')), 0, 80) ?: $o['supplier_ref'], preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['delivery_date'] ?? '')) ? $_POST['delivery_date'] : $o['delivery_date'], $oid]);
    po_recalc($oid); po_event($oid, 'quote', trim((string)($_POST['note'] ?? '')) ?: null);
}
/** Award an RFQ: a PO is created from its lines (quoted prices become price records on receipt, R80-004). */
function po_award(int $rfqId): int {
    $r = po_load($rfqId); if (!$r || $r['kind'] !== 'rfq' || $r['status'] !== 'quoted' || !po_admin()) throw new RuntimeException(t('err.noaccess')); $u = current_user();
    $no = po_number('po', $r['branch_id'] ? (int)$r['branch_id'] : null);
    q('INSERT INTO po_orders (kind, po_no, branch_id, supplier_id, project_id, subproject_id, rfq_id, title, currency, incoterms, terms_days, advance_pct, delivery_date, warehouse_id, supplier_ref, supplier_contact, supplier_email, vat_pct, note, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      ['po', $no, $r['branch_id'], $r['supplier_id'], $r['project_id'], $r['subproject_id'], $rfqId, $r['title'], $r['currency'], $r['incoterms'], $r['terms_days'], $r['advance_pct'], $r['delivery_date'], $r['warehouse_id'], $r['supplier_ref'], $r['supplier_contact'], $r['supplier_email'], $r['vat_pct'], $r['note'], (int)$u['id'], $u['full_name']]);
    $oid = (int) db()->lastInsertId();
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled' ORDER BY seq", [$rfqId]) as $l) q('INSERT INTO po_lines (order_id, seq, item_id, alt_of_item_id, description, maker, part_no, unit, qty, unit_price, price_origin, discount_pct, lead_days, need_date, pr_line_id, bom_line_id, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [$oid, $l['seq'], $l['item_id'], $l['alt_of_item_id'], $l['description'], $l['maker'], $l['part_no'], $l['unit'], $l['qty'], $l['unit_price'], $l['unit_price'] !== null ? t('po.price_quoted') . ' · ' . $r['po_no'] : null, $l['discount_pct'], $l['lead_days'], $l['need_date'], $l['pr_line_id'], $l['bom_line_id'], $l['note']]);
    po_recalc($oid); q('UPDATE po_orders SET status = ? WHERE id = ?', ['awarded', $rfqId]); po_event($rfqId, 'award', $no); po_event($oid, 'create', t('po.from_rfq', ['no' => $r['po_no']]));
    return $oid;
}
/** Send: the PO / RFQ is issued (PDF stored on the project files when a project is set), requisition lines marked ordered; recipients recorded. */
function po_mark_sent(int $oid, array $to = []): void {
    $o = po_load($oid); if (!$o || !po_admin() || !in_array($o['status'], ['draft', 'sent', 'quoted'], true)) throw new RuntimeException(t('err.noaccess'));
    if (!(int) scalar("SELECT COUNT(*) FROM po_lines WHERE order_id = ? AND status <> 'cancelled'", [$oid])) throw new RuntimeException(t('prq.need_lines'));
    if ($o['kind'] === 'po' && (int) scalar("SELECT COUNT(*) FROM po_lines WHERE order_id = ? AND status <> 'cancelled' AND unit_price IS NULL", [$oid])) throw new RuntimeException(t('po.need_prices'));
    $first = $o['status'] === 'draft';
    if ($first && $o['kind'] === 'po') {
        foreach (all('SELECT * FROM po_lines WHERE order_id = ? AND pr_line_id IS NOT NULL', [$oid]) as $l) { q('UPDATE pr_lines SET qty_ordered = qty_ordered + ?, status = ? WHERE id = ?', [(float)$l['qty'], 'ordered', (int)$l['pr_line_id']]); }
        po_recalc($oid);
    }
    q('UPDATE po_orders SET status = ?, sent_at = COALESCE(sent_at, NOW()), sent_to = ? WHERE id = ?', [$first || $o['status'] === 'sent' ? 'sent' : $o['status'], $to ? mb_substr(implode(', ', $to), 0, 400) : $o['sent_to'], $oid]);
    po_event($oid, $first ? 'send' : 'resend', $to ? implode(', ', $to) : null);
    if (function_exists('po_sp_cost_sync')) { po_sp_cost_sync($oid); if (function_exists('po_expected_update')) po_expected_update($oid); }
    if ($first && $o['subproject_id'] && function_exists('eng_rows_reset')) eng_rows_reset();
    cache_bump_later('inv'); cache_bump_later('eng');
}
function po_confirm(int $oid, string $ref, ?string $date, string $note): void {
    $o = po_load($oid); if (!$o || $o['kind'] !== 'po' || $o['status'] !== 'sent' || !po_admin()) throw new RuntimeException(t('err.noaccess'));
    q('UPDATE po_orders SET status = ?, confirmed_at = NOW(), supplier_ref = ?, delivery_date = COALESCE(?, delivery_date) WHERE id = ?', ['confirmed', mb_substr(trim($ref), 0, 80) ?: $o['supplier_ref'], $date, $oid]); po_event($oid, 'confirm', trim($ref . ' ' . $note) ?: null);
    if (function_exists('po_promised_from_post')) po_promised_from_post($oid);
}
function po_cancel(int $oid, string $note): void {
    $o = po_load($oid); if (!$o || !po_admin() || in_array($o['status'], ['received', 'closed', 'cancelled'], true)) throw new RuntimeException(t('err.noaccess'));
    if ($note === '') throw new RuntimeException(t('prq.need_reason'));
    if ($o['kind'] === 'po' && $o['status'] !== 'draft') foreach (all('SELECT * FROM po_lines WHERE order_id = ? AND pr_line_id IS NOT NULL', [$oid]) as $l) { $rem = (float)$l['qty'] - (float)$l['qty_received']; q('UPDATE pr_lines SET qty_ordered = GREATEST(0, qty_ordered - ?), status = IF(qty_ordered - ? <= 0.0005 AND qty_received <= 0.0005, \'to_buy\', status) WHERE id = ?', [$rem, $rem, (int)$l['pr_line_id']]); }
    $received = (float) scalar('SELECT COALESCE(SUM(qty_accepted), 0) FROM po_lines WHERE order_id = ?', [$oid]);
    if ($received > 0.0005) {   // R86-001: goods already accepted — only the open balance is cancelled; the order stays as received (and can be closed), its receipts and invoices untouched
        q("UPDATE po_lines SET qty = qty_accepted, status = 'received' WHERE order_id = ? AND status IN ('open','partial') AND qty_accepted > 0.0005", [$oid]);
        q("UPDATE po_lines SET status = 'cancelled' WHERE order_id = ? AND status IN ('open','partial')", [$oid]);
        q('UPDATE po_orders SET status = ? WHERE id = ?', ['received', $oid]); po_event($oid, 'cancel', t('po.cancel_balance') . ' · ' . $note); po_recalc($oid);
    } else {
        q("UPDATE po_lines SET status = 'cancelled' WHERE order_id = ? AND status IN ('open','partial')", [$oid]);
        q('UPDATE po_orders SET status = ? WHERE id = ?', ['cancelled', $oid]); po_event($oid, 'cancel', $note);
    }
    if (function_exists('po_sp_cost_sync')) po_sp_cost_sync($oid); cache_bump_later('inv'); cache_bump_later('eng');
}
function po_close(int $oid, string $note): void { $o = po_load($oid); if (!$o || !po_admin() || !in_array($o['status'], ['received', 'partial', 'confirmed', 'sent'], true)) throw new RuntimeException(t('err.noaccess')); q('UPDATE po_orders SET status = ? WHERE id = ?', ['closed', $oid]); po_event($oid, 'close', $note ?: null); if (function_exists('po_progress_apply')) po_progress_apply($o['subproject_id'] ? (int)$o['subproject_id'] : null); cache_bump_later('eng'); }

/* ------------------------------------------------------------------ people / e-mail */
function po_people(array $o): array {
    $out = []; $seen = [];
    $add = function (string $n, ?string $e, string $r, bool $on) use (&$out, &$seen) { $e = strtolower(trim((string)$e)); if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($seen[$e])) return; $seen[$e] = true; $out[] = ['name' => $n ?: $e, 'email' => $e, 'role' => $r, 'on' => $on]; };
    if ($o['supplier_email']) $add((string)($o['supplier_contact'] ?: $o['supplier']), $o['supplier_email'], t('po.supplier'), true);
    try { foreach (function_exists('co_people') ? co_people((int)$o['supplier_id']) : all('SELECT full_name, email, position FROM contacts WHERE company_id = ? AND is_active = 1 ORDER BY full_name', [(int)$o['supplier_id']]) as $c) if ($c['email']) $add((string)$c['full_name'], $c['email'], (string)($c['position'] ?? ''), !$out); } catch (Throwable $e) {}
    if ($o['supplier_co_email']) $add((string)$o['supplier'], $o['supplier_co_email'], t('po.supplier'), !$out);
    return $out;
}
function po_em_attachment(array $p): ?array { if (!po_can()) return null; $o = po_load((int)($p[1] ?? 0)); if (!$o) return null; return [po_pdf($o), $o['po_no'] . '.pdf', 'application/pdf']; }

/* ------------------------------------------------------------------ PDF */
function po_pdf(array $o): string {
    require_once __DIR__ . '/pdf.php'; $L = po_lines((int)$o['id']); $cur = $o['currency']; $isRfq = $o['kind'] === 'rfq';
    $loc = 'en'; $T = fn(string $k, array $v = []) => t_in($k, $loc, $v); $m = fn(?float $v) => $v === null ? '' : number_format($v, 2, ',', '.');
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $Lm = 15.0; $R = 195.0; $W = $R - $Lm; $y = 0.0;
    $bd = function_exists('br_doc') ? br_doc($o['project_id'] ? (int)$o['project_id'] : null, null, $o['branch_id'] && function_exists('br_all') ? (array_values(array_filter(br_all(), fn($b) => (int)$b['id'] === (int)$o['branch_id']))[0] ?? null) : null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $title = $isRfq ? $T('po.pdf_rfq') : $T('po.pdf_po');
    $header = function () use ($pdf, &$y, $navy, $gold, $Lm, $R, $o, $bd, $title) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($Lm, 20, mb_strtoupper($title)); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($Lm, 26, $o['po_no'] . ' · ' . fmt_date($o['sent_at'] ? substr($o['sent_at'], 0, 10) : date('Y-m-d'))); $pdf->setLineWidth(0.6); $pdf->line($Lm, 30, $R, 30); $y = 36; };
    $footer = function () use ($pdf, $Lm, $R, $o, $bd) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($Lm, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $o['po_no']); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 272) { $footer(); $header(); } };
    $header();
    // from / to
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y, mb_strtoupper($T('pi.from'))); $pdf->text(110, $y, mb_strtoupper($T('po.pdf_to'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap($ln0, 88) as $ln) { $pdf->text($Lm, $yl, $ln); $yl += 4; }
    $S = sup_load((int)$o['supplier_id']) ?: []; $addr = array_values(array_filter([(string)$o['supplier'], (string)($S['address'] ?? ''), trim((string)($S['postal_code'] ?? '') . ' ' . (string)($S['city'] ?? '')), (string)($S['country'] ?? ''), $S['vat_no'] ?? null ? 'VAT ' . $S['vat_no'] : '', $o['supplier_contact'] ? $T('po.attn') . ' ' . $o['supplier_contact'] : '', (string)($o['supplier_email'] ?? '')]));
    $yr = $y; foreach ($addr as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    // details box
    $kv = [[$T('po.pdf_ref'), (string)($o['title'] ?: '')], [$T('prq.project'), trim((string)($o['project_code'] ?? '') . ($o['sub_code'] ? ' · ' . $o['sub_code'] . ' ' . $o['sub_name'] : ''))], [$T('po.delivery'), $o['delivery_date'] ? fmt_date($o['delivery_date']) : ($isRfq ? $T('po.pdf_quote_lead') : '')], [$T('po.incoterms'), (string)($o['incoterms'] ?? '')], [$T('po.terms'), $o['terms_days'] !== null ? $T('po.terms_days_v', ['n' => (int)$o['terms_days']]) . ((float)$o['advance_pct'] > 0 ? ' · ' . $T('po.advance_v', ['p' => rtrim(rtrim(number_format((float)$o['advance_pct'], 2, '.', ''), '0'), '.')]) : '') : ''], [$T('po.ship_to'), (string)($o['warehouse'] ?? '')], [$T('po.supplier_ref'), (string)($o['supplier_ref'] ?? '')]];
    $kv = array_values(array_filter($kv, fn($r) => $r[1] !== '')); $need(count($kv) * 4.5 + 6);
    $pdf->setColor($head); $pdf->rect($Lm, $y, $W, count($kv) * 4.5 + 3, 'F'); $yy = $y + 4;
    foreach ($kv as [$k, $v]) { $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm + 2, $yy, $k); $pdf->setFont('R', 8); $pdf->setColor($ink); $pdf->text($Lm + 40, $yy, mb_substr($v, 0, 110)); $yy += 4.5; }
    $y = $yy + 2;
    // lines
    $cols = $isRfq ? [['#', 8, 'L'], [$T('stk.code'), 30, 'L'], [$T('prq.desc'), 96, 'L'], [$T('prq.qty'), 22, 'R'], [$T('po.pdf_price'), 24, 'R']] : [['#', 8, 'L'], [$T('stk.code'), 28, 'L'], [$T('prq.desc'), 78, 'L'], [$T('prq.qty'), 20, 'R'], [$T('po.unit_price'), 22, 'R'], [$T('po.pdf_total'), 24, 'R']];
    $hrow = function () use ($pdf, $cols, $navy, $head, $Lm, &$y) { $pdf->setFont('B', 7.5); $pdf->setColor($head); $pdf->rect($Lm, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $Lm; foreach ($cols as [$h, $w, $al]) { $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 4.2, $h, $al); $x += $w; } $y += 6; };
    $need(20); $hrow(); $pdf->setFont('R', 8); $pdf->setColor($ink);
    foreach ($L as $l) {
        if ($l['status'] === 'cancelled') continue;
        $desc = $l['description'] . (($l['maker'] || $l['part_no']) ? ' — ' . trim(($l['maker'] ?? '') . ' ' . ($l['part_no'] ?? '')) : '') . ($l['alt_of_code'] ? ' (' . $T('po.pdf_alt_of', ['c' => $l['alt_of_code']]) . ')' : '') . ($l['note'] ? ' · ' . $l['note'] : '');
        $dw = $cols[2][1] - 3; $lines = $pdf->wrap($desc, $dw); $h = max(5.5, count($lines) * 3.8 + 1.8); $need($h + 2);
        $x = $Lm; $cells = $isRfq ? [(string)$l['seq'], (string)($l['item_code'] ?: ($l['part_no'] ?: '')), null, stk_qty((float)$l['qty']) . ' ' . $l['unit'], ''] : [(string)$l['seq'], (string)($l['item_code'] ?: ''), null, stk_qty((float)$l['qty']) . ' ' . $l['unit'], $m($l['unit_price'] !== null ? (float)$l['unit_price'] : null) . ((float)$l['discount_pct'] > 0 ? ' −' . rtrim(rtrim(number_format((float)$l['discount_pct'], 2, '.', ''), '0'), '.') . '%' : ''), $m($l['line_total'] !== null ? (float)$l['line_total'] : null)];
        foreach ($cols as $i => [$hh, $w, $al]) { if ($cells[$i] === null) { $yy = $y + 3.8; foreach ($lines as $ln) { $pdf->text($x + 1.5, $yy, $ln); $yy += 3.8; } } else $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 3.8, mb_substr($cells[$i], 0, 30), $al); $x += $w; }
        $y += $h; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($Lm, $y, $Lm + array_sum(array_column($cols, 1)), $y); $pdf->setColor($ink);
    }
    if (!$isRfq) { $need(20); $y += 3; $tx = $Lm + array_sum(array_column($cols, 1)); $pdf->setFont('R', 8.5); $pdf->text($tx - 30, $y + 4, $T('po.subtotal'), 'R'); $pdf->text($tx - 1.5, $y + 4, $m((float)$o['subtotal']) . ' ' . $cur, 'R'); $y += 5; if ((float)$o['vat_pct'] > 0) { $pdf->text($tx - 30, $y + 4, $T('po.vat') . ' ' . rtrim(rtrim(number_format((float)$o['vat_pct'], 2, '.', ''), '0'), '.') . '%', 'R'); $pdf->text($tx - 1.5, $y + 4, $m((float)$o['vat_total']) . ' ' . $cur, 'R'); $y += 5; } $pdf->setFont('B', 9.5); $pdf->setColor($navy); $pdf->text($tx - 30, $y + 4.5, $T('po.total'), 'R'); $pdf->text($tx - 1.5, $y + 4.5, $m((float)$o['gross_total']) . ' ' . $cur, 'R'); $y += 8; }
    if ($o['note']) { $need(16); $y += 3; $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y + 3, $T('po.pdf_notes')); $y += 5; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, (string)$o['note'], 3.9) + 2; }
    $need(18); $y += 4; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, $isRfq ? $T('po.pdf_rfq_foot') : $T('po.pdf_po_foot', ['no' => $o['po_no']]), 3.9) + 6;
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y, $T('po.pdf_for') . ' ' . $bd['name']); $pdf->text(110, $y, $T('po.pdf_ack')); $y += 14; $pdf->setColor($line); $pdf->line($Lm, $y, $Lm + 70, $y); $pdf->line(110, $y, 180, $y);
    $footer(); return $pdf->bytes();
}
/** Sub-project summary for the Procurement tab and the financial views: open POs, ordered value (net), received value. */
function po_sub_summary(int $sid): array {
    if (!po_has()) return ['n' => 0, 'open' => 0, 'ordered' => 0.0, 'received' => 0.0];
    return ['n' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'po' AND subproject_id = ? AND status <> 'cancelled'", [$sid]), 'open' => (int) scalar("SELECT COUNT(*) FROM po_orders WHERE kind = 'po' AND subproject_id = ? AND status IN ('sent','confirmed','partial')", [$sid]),
            'ordered' => (float) scalar("SELECT COALESCE(SUM(subtotal),0) FROM po_orders WHERE kind = 'po' AND subproject_id = ? AND status IN ('sent','confirmed','partial','received','closed')", [$sid]),
            'received' => (float) scalar("SELECT COALESCE(SUM(l.qty_accepted * COALESCE(l.unit_price,0) * (1 - l.discount_pct/100)),0) FROM po_lines l JOIN po_orders o ON o.id = l.order_id WHERE o.kind = 'po' AND o.subproject_id = ? AND o.status <> 'cancelled'", [$sid])];
}

/* ================================================================== R80-004: delivery timeline · goods receipts · logistic costs */
const PO_MILESTONES = ['confirmed', 'ready', 'shipped', 'export', 'arrived', 'customs', 'delivered'];
const PO_COST_KINDS = ['freight', 'insurance', 'customs', 'duties', 'import_vat', 'handling', 'other'];
const PO_NCR_ACTIONS = ['none', 'return', 'replace', 'credit'];

function po_r4(): bool { static $h = null; if ($h === null) { try { $h = po_has() && (bool) db()->query("SHOW TABLES LIKE 'po_receipts'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** Expected delivery of a line: promised date, else need date, else (confirmed / sent) + lead days. */
function po_line_expected(array $o, array $l): ?string {
    if (!empty($l['promised_date'])) return $l['promised_date'];
    $base = $o['confirmed_at'] ?: $o['sent_at']; if ($l['lead_days'] !== null && $base) return date('Y-m-d', strtotime(substr((string)$base, 0, 10) . ' +' . (int)$l['lead_days'] . ' days'));
    return $o['delivery_date'] ?: null;
}
/** Expected delivery of the order = the latest line expectation (or the header date); stored for the lists. */
function po_expected_update(int $oid): ?string {
    $o = po_load($oid); if (!$o) return null; $max = $o['delivery_date'];
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status IN ('open','partial')", [$oid]) as $l) { $e = po_line_expected($o, $l); if ($e && (!$max || $e > $max)) $max = $e; }
    q('UPDATE po_orders SET expected_date = ? WHERE id = ?', [$max, $oid]); return $max;
}
function po_milestones(int $oid): array { $rows = []; foreach (all('SELECT * FROM po_milestones WHERE order_id = ?', [$oid]) as $m) $rows[$m['kind']] = $m; return $rows; }
function po_milestone_save_from_post(): void {
    require_po_admin(); $oid = (int)($_POST['id'] ?? 0); $o = po_load($oid); if (!$o || $o['kind'] !== 'po' || in_array($o['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($_POST['kind'] ?? '', PO_MILESTONES, true) ? $_POST['kind'] : null; if (!$kind) throw new RuntimeException(t('err.notfound'));
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null; $u = current_user();
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $o['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null, 'file', 'internal', $o['po_no'] . ' · ' . t('po.m_' . $kind));
    $v = [$oid, $kind, $d('planned_date'), $d('actual_date'), mb_substr(trim((string)($_POST['carrier'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($_POST['tracking'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 120) ?: null, ($_POST['packages'] ?? '') !== '' ? max(0, (int)$_POST['packages']) : null, ($_POST['weight_kg'] ?? '') !== '' ? round((float) str_replace(',', '.', (string)$_POST['weight_kg']), 2) : null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 400) ?: null, $fileId, (int)$u['id'], $u['full_name']];
    q('INSERT INTO po_milestones (order_id, kind, planned_date, actual_date, carrier, tracking, ref, packages, weight_kg, note, file_id, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE planned_date = VALUES(planned_date), actual_date = VALUES(actual_date), carrier = VALUES(carrier), tracking = VALUES(tracking), ref = VALUES(ref), packages = VALUES(packages), weight_kg = VALUES(weight_kg), note = VALUES(note), file_id = COALESCE(VALUES(file_id), file_id), user_id = VALUES(user_id), user_name = VALUES(user_name)', $v);
    if ($kind === 'confirmed' && $v[3] && $o['status'] === 'sent') { q('UPDATE po_orders SET status = ?, confirmed_at = ? WHERE id = ?', ['confirmed', $v[3] . ' 00:00:00', $oid]); }
    if ($kind === 'delivered' && $v[3]) q('UPDATE po_orders SET delivery_date = COALESCE(delivery_date, ?) WHERE id = ?', [$v[3], $oid]);
    po_event($oid, 'milestone', t('po.m_' . $kind) . ($v[3] ? ' · ' . fmt_date($v[3]) : ($v[2] ? ' · ' . t('po.planned') . ' ' . fmt_date($v[2]) : '')) . ($v[5] ? ' · ' . $v[5] : ''));
    po_expected_update($oid);
}
/** Per-line promised dates typed with the confirmation. */
function po_promised_from_post(int $oid): void { foreach ((array)($_POST['promised'] ?? []) as $lid => $dv) { $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$dv) ? $dv : null; q('UPDATE po_lines SET promised_date = ? WHERE id = ? AND order_id = ?', [$d, (int)$lid, $oid]); } po_expected_update($oid); }
/** The timeline rows for the view: kind, planned, actual, late, data. */
function po_timeline(array $o): array {
    $M = po_milestones((int)$o['id']); $out = []; $today = date('Y-m-d');
    $out[] = ['kind' => 'sent', 'planned' => null, 'actual' => $o['sent_at'] ? substr($o['sent_at'], 0, 10) : null, 'late' => false, 'm' => null, 'fixed' => true];
    foreach (PO_MILESTONES as $k) { $m = $M[$k] ?? null; $planned = $m['planned_date'] ?? ($k === 'delivered' ? ($o['expected_date'] ?: $o['delivery_date']) : ($k === 'confirmed' && $o['confirmed_at'] ? substr($o['confirmed_at'], 0, 10) : null)); $actual = $m['actual_date'] ?? ($k === 'confirmed' && $o['confirmed_at'] ? substr($o['confirmed_at'], 0, 10) : null); $out[] = ['kind' => $k, 'planned' => $planned, 'actual' => $actual, 'late' => !$actual && $planned && $planned < $today && in_array($o['status'], PO_OPEN, true), 'm' => $m, 'fixed' => false]; }
    $rc = (int) scalar('SELECT COUNT(*) FROM po_receipts WHERE order_id = ?', [(int)$o['id']]); $last = $rc ? scalar('SELECT MAX(received_at) FROM po_receipts WHERE order_id = ?', [(int)$o['id']]) : null;
    $out[] = ['kind' => 'received', 'planned' => null, 'actual' => $last, 'late' => false, 'm' => null, 'fixed' => true, 'n' => $rc];
    return $out;
}

/* ---- logistic costs */
function po_costs(int $oid): array { return all('SELECT c.*, f.original_name FROM po_costs c LEFT JOIN sp_files f ON f.id = c.file_id WHERE c.order_id = ? ORDER BY c.cost_date, c.id', [$oid]); }
function po_cost_save_from_post(): int {
    require_po_admin(); $oid = (int)($_POST['id'] ?? 0); $o = po_load($oid); if (!$o || $o['kind'] !== 'po' || in_array($o['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($_POST['kind'] ?? '', PO_COST_KINDS, true) ? $_POST['kind'] : 'freight'; $amt = round((float) str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2); if ($amt == 0.0) throw new RuntimeException(t('po.need_amount'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['cost_date'] ?? '')) ? $_POST['cost_date'] : date('Y-m-d'); $cur = preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : $o['currency'];
    $prov = mb_substr(trim((string)($_POST['provider'] ?? '')), 0, 120) ?: null; $ref = mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80) ?: null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null; $u = current_user();
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $o['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null, 'file', 'internal', $o['po_no'] . ' · ' . t('po.c_' . $kind));
    $spc = null;
    if ($o['subproject_id'] && function_exists('bg_has') && bg_has()) { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, file_id, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [(int)$o['subproject_id'], $date, 'logistics', $prov ?: $o['supplier'], mb_substr(t('po.c_' . $kind) . ' · ' . $o['po_no'] . ($note ? ' · ' . $note : ''), 0, 255), mb_substr($ref ?: $o['po_no'], 0, 60), $amt, $cur, $fileId, (int)$u['id'], $u['full_name']]); $spc = (int) db()->lastInsertId(); if (function_exists('eng_rows_reset')) eng_rows_reset(); }
    q('INSERT INTO po_costs (order_id, kind, cost_date, provider, ref, amount, currency, note, file_id, sp_cost_id, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$oid, $kind, $date, $prov, $ref, $amt, $cur, $note, $fileId, $spc, (int)$u['id'], $u['full_name']]);
    $cid = (int) db()->lastInsertId(); po_logistics_recalc($oid); po_event($oid, 'cost', t('po.c_' . $kind) . ' · ' . po_money($amt, $cur) . ($prov ? ' · ' . $prov : ''));
    cache_bump_later('eng'); return $cid;
}
function po_cost_delete(int $oid, int $cid): void { require_po_admin(); $c = one('SELECT * FROM po_costs WHERE id = ? AND order_id = ?', [$cid, $oid]); if (!$c) throw new RuntimeException(t('err.notfound')); if ($c['sp_cost_id']) q('DELETE FROM sp_costs WHERE id = ?', [(int)$c['sp_cost_id']]); q('DELETE FROM po_costs WHERE id = ?', [$cid]); po_logistics_recalc($oid); po_event($oid, 'cost_del', t('po.c_' . $c['kind']) . ' · ' . po_money((float)$c['amount'], $c['currency'])); if (function_exists('eng_rows_reset')) eng_rows_reset(); cache_bump_later('eng'); }
function po_logistics_recalc(int $oid): void { q('UPDATE po_orders SET logistics_total = (SELECT COALESCE(SUM(amount),0) FROM po_costs WHERE order_id = ?) WHERE id = ?', [$oid, $oid]); }
/** The committed cost of a PO on the sub-project (sp_costs kind 'order', one row per PO): written on send, refreshed on change, removed on cancel. */
function po_sp_cost_sync(int $oid): void {
    $o = po_load($oid); if (!$o || !function_exists('bg_has') || !bg_has()) return;
    $u = current_user(); $want = $o['kind'] === 'po' && $o['subproject_id'] && !in_array($o['status'], ['draft', 'cancelled'], true);
    if (!$want) { if ($o['cost_id']) { q('DELETE FROM sp_costs WHERE id = ?', [(int)$o['cost_id']]); q('UPDATE po_orders SET cost_id = NULL WHERE id = ?', [$oid]); } }
    elseif ($o['cost_id'] && scalar('SELECT 1 FROM sp_costs WHERE id = ?', [(int)$o['cost_id']])) q('UPDATE sp_costs SET subproject_id = ?, supplier = ?, description = ?, ref_no = ?, amount = ?, currency = ? WHERE id = ?', [(int)$o['subproject_id'], $o['supplier'], mb_substr(t('po.sp_cost_desc', ['no' => $o['po_no'], 't' => $o['title'] ?: '']), 0, 255), mb_substr($o['po_no'], 0, 60), (float)$o['subtotal'], $o['currency'], (int)$o['cost_id']]);
    else { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?)', [(int)$o['subproject_id'], substr((string)($o['sent_at'] ?: date('Y-m-d')), 0, 10), 'order', $o['supplier'], mb_substr(t('po.sp_cost_desc', ['no' => $o['po_no'], 't' => $o['title'] ?: '']), 0, 255), mb_substr($o['po_no'], 0, 60), (float)$o['subtotal'], $o['currency'], (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null]); q('UPDATE po_orders SET cost_id = ? WHERE id = ?', [(int) db()->lastInsertId(), $oid]); }
    if (function_exists('eng_rows_reset')) eng_rows_reset(); cache_bump_later('eng');
}

/* ---- goods receipt */
function po_receipts(int $oid): array { return all('SELECT r.*, (SELECT COUNT(*) FROM po_receipt_lines l WHERE l.receipt_id = r.id) AS n_lines, (SELECT COUNT(*) FROM po_receipt_lines l WHERE l.receipt_id = r.id AND l.ncr_status = \'open\') AS n_ncr FROM po_receipts r WHERE r.order_id = ? ORDER BY r.id DESC', [$oid]); }
function po_receipt_lines(int $rid): array { return all('SELECT rl.*, l.seq, l.description, l.unit, l.item_id, i.code AS item_code FROM po_receipt_lines rl JOIN po_lines l ON l.id = rl.po_line_id LEFT JOIN inv_items i ON i.id = l.item_id WHERE rl.receipt_id = ? ORDER BY l.seq', [$rid]); }
function po_ncr_open(int $oid): array { return all('SELECT rl.*, r.receipt_no, r.received_at, l.seq, l.description, l.unit, i.code AS item_code FROM po_receipt_lines rl JOIN po_receipts r ON r.id = rl.receipt_id JOIN po_lines l ON l.id = rl.po_line_id LEFT JOIN inv_items i ON i.id = l.item_id WHERE r.order_id = ? AND rl.ncr_status = \'open\' ORDER BY r.id, l.seq', [$oid]); }
/** Receipt per line: received / accepted / rejected (+ reason and action). Accepted goods go into stock at the PO price (price record), reserved for the requisition they were bought for; the PO and its lines move to partial / received. */
function po_receive_from_post(): int {
    require_pr_wh(); $oid = (int)($_POST['id'] ?? 0); $o = po_load($oid); if (!$o || $o['kind'] !== 'po' || !in_array($o['status'], ['sent', 'confirmed', 'partial'], true)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['received_at'] ?? '')) ? $_POST['received_at'] : date('Y-m-d');
    $wh = (int)($_POST['warehouse_id'] ?? 0) ?: (int)($o['warehouse_id'] ?: stk_default_wh()); $dn = mb_substr(trim((string)($_POST['delivery_note'] ?? '')), 0, 80) ?: null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 400) ?: null;
    $lines = po_lines($oid); $todo = [];
    foreach ($lines as $l) { $lid = (int)$l['id']; $qr = (float) str_replace(',', '.', (string)($_POST['recv'][$lid] ?? '0')); if ($qr <= 0 || $l['status'] === 'cancelled') continue; $qrej = max(0.0, min($qr, (float) str_replace(',', '.', (string)($_POST['rej'][$lid] ?? '0')))); $qa = $qr - $qrej;
        $open = (float)$l['qty'] - (float)$l['qty_accepted'];   // R86-001: what is still owed — rejected goods (NCR replacement / repair) can be received again
        if ($qr > $open + 0.0005 && empty($_POST['allow_over'])) throw new RuntimeException(t('po.over_receipt', ['n' => (int)$l['seq'], 'q' => stk_qty(max(0.0, $open))]));
        $reason = mb_substr(trim((string)($_POST['reason'][$lid] ?? '')), 0, 400); $action = in_array($_POST['ncr'][$lid] ?? '', PO_NCR_ACTIONS, true) ? $_POST['ncr'][$lid] : 'none'; if ($qrej > 0 && $reason === '') throw new RuntimeException(t('po.need_ncr_reason', ['n' => (int)$l['seq']])); if ($qrej > 0 && $action === 'none') $action = 'return';
        $todo[] = [$l, $qr, $qa, $qrej, $reason ?: null, $action, mb_substr(trim((string)($_POST['loc'][$lid] ?? '')), 0, 40) ?: null]; }
    if (!$todo) throw new RuntimeException(t('po.need_receipt_qty'));
    $n = (int) scalar('SELECT COUNT(*) + 1 FROM po_receipts WHERE order_id = ?', [$oid]); $no = $o['po_no'] . '-GR' . $n;
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $o['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null, 'file', 'internal', $no . ($dn ? ' · ' . $dn : ''));
    q('INSERT INTO po_receipts (order_id, receipt_no, received_at, delivery_note, warehouse_id, note, file_id, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?)', [$oid, $no, $date, $dn, $wh, $note, $fileId, (int)$u['id'], $u['full_name']]); $rid = (int) db()->lastInsertId();
    $ncr = 0;
    foreach ($todo as [$l, $qr, $qa, $qrej, $reason, $action, $loc]) {
        q('INSERT INTO po_receipt_lines (receipt_id, po_line_id, qty_received, qty_accepted, qty_rejected, ncr_reason, ncr_action, ncr_status, location) VALUES (?,?,?,?,?,?,?,?,?)', [$rid, (int)$l['id'], $qr, $qa, $qrej, $reason, $qrej > 0 ? $action : 'none', $qrej > 0 ? 'open' : 'none', $loc]); if ($qrej > 0) $ncr++;
        $recvTot = (float)$l['qty_received'] + $qr; $accTot = (float)$l['qty_accepted'] + $qa; $st = $accTot >= (float)$l['qty'] - 0.0005 ? 'received' : 'partial';
        q('UPDATE po_lines SET qty_received = ?, qty_accepted = ?, qty_rejected = qty_rejected + ?, status = ? WHERE id = ?', [$recvTot, $accTot, $qrej, $st, (int)$l['id']]);
        if ($qa > 0 && $l['item_id']) {
            $price = $l['unit_price'] !== null ? round((float)$l['unit_price'] * (1 - (float)$l['discount_pct'] / 100), 4) : null;
            stk_move((int)$l['item_id'], $wh, 'in', $qa, ['unit_price' => $price, 'project_id' => $o['project_id'] ? (int)$o['project_id'] : null, 'subproject_id' => $o['subproject_id'] ? (int)$o['subproject_id'] : null, 'ref_kind' => 'po', 'ref_id' => $oid, 'reason' => 'receipt', 'note' => $no . ($dn ? ' · ' . $dn : ''), 'location' => $loc]);
            if ($price !== null && $price > 0) stk_price_add((int)$l['item_id'], $price, ['priced_at' => $date, 'supplier_id' => (int)$o['supplier_id'], 'po_line_id' => (int)$l['id'], 'currency' => $o['currency'], 'qty' => $qa, 'source' => 'receipt', 'note' => $o['po_no']]);
            if ($l['pr_line_id']) { q('UPDATE inv_stock SET qty_reserved = qty_reserved + ? WHERE item_id = ? AND warehouse_id = ?', [$qa, (int)$l['item_id'], $wh]); q("UPDATE pr_lines SET qty_received = qty_received + ?, qty_reserved = qty_reserved + ?, warehouse_id = COALESCE(warehouse_id, ?), status = IF(status IN ('to_buy','ordered'), 'received', status) WHERE id = ?", [$qa, $qa, $wh, (int)$l['pr_line_id']]); }
        } elseif ($qa > 0 && $l['pr_line_id']) { q("UPDATE pr_lines SET qty_received = qty_received + ?, status = IF(status IN ('to_buy','ordered'), 'received', status) WHERE id = ?", [$qa, (int)$l['pr_line_id']]); }
    }
    $open = (int) scalar("SELECT COUNT(*) FROM po_lines WHERE order_id = ? AND status IN ('open','partial')", [$oid]);
    q('UPDATE po_orders SET status = ? WHERE id = ?', [$open ? 'partial' : 'received', $oid]); po_event($oid, 'receive', $no . ' · ' . count($todo) . ' ' . t('prq.lines') . ($ncr ? ' · ' . $ncr . ' NCR' : '') . ($dn ? ' · ' . $dn : ''));
    po_expected_update($oid); po_progress_apply($o['subproject_id'] ? (int)$o['subproject_id'] : null);
    // notify the requesters whose lines arrived
    $reqs = array_values(array_unique(array_map('intval', array_column(all('SELECT DISTINCT r.requester_id FROM po_lines l JOIN pr_lines pl ON pl.id = l.pr_line_id JOIN pr_requests r ON r.id = pl.request_id WHERE l.order_id = ?', [$oid]), 'requester_id'))));
    if ($reqs && function_exists('nf_push')) { try { nf_push($reqs, 'work:po_received', 'po:' . $oid . ':gr:' . $rid, t('po.nf_received', ['no' => $o['po_no']]), $o['supplier'] . ($o['sub_code'] ? ' · ' . $o['sub_code'] : ''), url('purchase_order', ['id' => $oid]), (int)$u['id'], $u['full_name']); } catch (Throwable $e) {} }
    cache_bump_later('inv'); cache_bump_later('eng'); return $rid;
}
/** NCR follow-up: closed with the outcome (returned / replaced — replacement received as a new receipt — / credited). */
function po_ncr_close(int $oid, int $rlid, string $note): void {
    require_po_admin(); $rl = one('SELECT rl.* FROM po_receipt_lines rl JOIN po_receipts r ON r.id = rl.receipt_id WHERE rl.id = ? AND r.order_id = ?', [$rlid, $oid]); if (!$rl || $rl['ncr_status'] !== 'open') throw new RuntimeException(t('err.notfound'));
    q('UPDATE po_receipt_lines SET ncr_status = ?, ncr_closed_at = CURDATE(), ncr_closed_note = ? WHERE id = ?', ['closed', mb_substr(trim($note), 0, 400) ?: null, $rlid]);
    if ($rl['ncr_action'] === 'credit') { /* the rejected quantity will not come: the line is complete at the accepted quantity */ q("UPDATE po_lines SET qty = qty_accepted, status = 'received' WHERE id = ? AND qty_accepted > 0", [(int)$rl['po_line_id']]); po_recalc($oid); po_sp_cost_sync($oid); }
    po_event($oid, 'ncr_close', t('po.ncr_' . $rl['ncr_action']) . ' · ' . stk_qty((float)$rl['qty_rejected']) . ($note !== '' ? ' · ' . $note : ''));
    $open = (int) scalar("SELECT COUNT(*) FROM po_lines WHERE order_id = ? AND status IN ('open','partial')", [$oid]); if (!$open) q("UPDATE po_orders SET status = 'received' WHERE id = ? AND status = 'partial'", [$oid]);
}
/** Procurement progress of a sub-project: received value / ordered value of its POs (0–100). Written to the process line when higher than the current value and the stage is not N/A. */
function po_progress(int $sid): ?int {
    $S = po_sub_summary($sid); if ($S['ordered'] <= 0) return null;
    return (int) max(0, min(100, round($S['received'] / $S['ordered'] * 100)));
}
function po_progress_apply(?int $sid): void {
    if (!$sid) return; $p = po_progress($sid); if ($p === null) return;
    try { $cur = scalar('SELECT pct_procurement FROM subprojects WHERE id = ?', [$sid]); if ($cur === null || (int)$cur === PCT_NA || (int)$cur >= $p) return; q('UPDATE subprojects SET pct_procurement = ? WHERE id = ?', [$p, $sid]); } catch (Throwable $e) {}
}

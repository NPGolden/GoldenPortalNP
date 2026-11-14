<?php
/** Delivery notes — GAS-CP-R88-001
 *  A delivery note (DN) documents goods leaving the warehouse: to a project (site / vessel), to a customer organisation, to production,
 *  or returned to a supplier. Every line with an inventory item books the stock movement (reason by kind) with the DN number as
 *  reference; a line from a requisition is issued against it. Goods receipt lines can be routed per line "to stock" or
 *  "to the project" — the latter books the stock in and straight out again on a DN created with the receipt.
 *  Tables: inv_delivery_notes · inv_dn_lines · po_receipt_lines.dest / dn_id.   Numbers: <prefix>-DN-<yy>-<nnnn> (doc_sequences).
 */
declare(strict_types=1);

const DN_KINDS = ['project', 'customer', 'production', 'return'];
const DN_REASON = ['project' => 'issue', 'customer' => 'delivery_customer', 'production' => 'delivery_production', 'return' => 'manual'];

function dn_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('stk_has') && stk_has() && (bool) db()->query("SHOW TABLES LIKE 'inv_delivery_notes'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function dn_can(): bool { return dn_has() && function_exists('pr_can') && pr_can(); }
function dn_wh(): bool { return dn_has() && function_exists('pr_wh') && pr_wh(); }
const DN_SELECT = "SELECT d.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, co.name AS company, v.name AS vessel, w.code AS wh_code, w.name AS warehouse, r.receipt_no, o.po_no,
                   (SELECT COUNT(*) FROM inv_dn_lines l WHERE l.dn_id = d.id) AS n_lines
                   FROM inv_delivery_notes d LEFT JOIN projects p ON p.id = d.project_id LEFT JOIN subprojects s ON s.id = d.subproject_id LEFT JOIN companies co ON co.id = d.company_id LEFT JOIN vessels v ON v.id = d.vessel_id
                   LEFT JOIN inv_warehouses w ON w.id = d.warehouse_id LEFT JOIN po_receipts r ON r.id = d.receipt_id LEFT JOIN po_orders o ON o.id = d.order_id";
function dn_load(int $id): ?array { return dn_has() && $id ? one(DN_SELECT . ' WHERE d.id = ?', [$id]) ?: null : null; }
function dn_lines(int $id): array { return all('SELECT l.*, i.code AS item_code, i.maker, i.factory_code FROM inv_dn_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.dn_id = ? ORDER BY l.seq, l.id', [$id]); }
function dn_list(array $f = []): array {
    if (!dn_has()) return []; $w = ['1=1']; $a = [];
    if (!empty($f['kind']) && in_array($f['kind'], DN_KINDS, true)) { $w[] = 'd.kind = ?'; $a[] = $f['kind']; }
    if (($f['status'] ?? '') !== '' && in_array($f['status'], ['issued', 'cancelled'], true)) { $w[] = 'd.status = ?'; $a[] = $f['status']; }
    if (!empty($f['project_id'])) { $w[] = 'd.project_id = ?'; $a[] = (int)$f['project_id']; }
    if (!empty($f['subproject_id'])) { $w[] = 'd.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['company_id'])) { $w[] = 'd.company_id = ?'; $a[] = (int)$f['company_id']; }
    if (!empty($f['item_id'])) { $w[] = 'EXISTS (SELECT 1 FROM inv_dn_lines l WHERE l.dn_id = d.id AND l.item_id = ?)'; $a[] = (int)$f['item_id']; }
    if (!empty($f['q'])) { $l = '%' . $f['q'] . '%'; $w[] = '(d.dn_no LIKE ? OR d.ref_no LIKE ? OR p.project_code LIKE ? OR p.name LIKE ? OR co.name LIKE ? OR v.name LIKE ? OR EXISTS (SELECT 1 FROM inv_dn_lines l LEFT JOIN inv_items i ON i.id = l.item_id WHERE l.dn_id = d.id AND (l.description LIKE ? OR i.code LIKE ?)))'; array_push($a, $l, $l, $l, $l, $l, $l, $l, $l); }
    return all(DN_SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY d.issued_at DESC, d.id DESC LIMIT ' . (int)($f['limit'] ?? 500), $a);
}
function dn_number(?int $branchId): string {
    $b = null; if (function_exists('br_all') && $branchId) foreach (br_all() as $bb) if ((int)$bb['id'] === $branchId) $b = $bb;
    if (!$b && function_exists('br_default')) $b = br_default();
    $prefix = (string)(($b['doc_prefix'] ?? '') ?: 'GAS'); $yy = date('y'); $fam = 'DN:' . $prefix . ':' . $yy;
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['portal', $fam]);
    return $prefix . '-DN-' . $yy . '-' . str_pad((string)(int) db()->lastInsertId(), 4, '0', STR_PAD_LEFT);
}
function dn_pill(string $s): string { return pill($s === 'issued' ? 'ok' : 'mute', t('dn.s_' . $s)); }
function dn_kind_icon(string $k): string { return ['project' => '🏗', 'customer' => '🚚', 'production' => '🏭', 'return' => '↩'][$k] ?? '📦'; }

/** Create a delivery note and book the movements.
 *  $h: kind, warehouse_id, project_id, subproject_id, company_id, vessel_id, receipt_id, order_id, issued_at, address, attention, carrier, ref_no, note, allow_negative
 *  $L: [['item_id', 'description', 'unit', 'qty', 'po_line_id', 'pr_line_id', 'note', 'skip_move' (bool: the stock was never booked in, e.g. a free-text receipt line)], …] */
function dn_create(array $h, array $L): int {
    require_pr_wh(); if (!dn_has()) throw new RuntimeException(t('err.noaccess'));
    $kind = in_array($h['kind'] ?? '', DN_KINDS, true) ? $h['kind'] : 'project'; $u = current_user();
    $pid = (int)($h['project_id'] ?? 0) ?: null; $sid = (int)($h['subproject_id'] ?? 0) ?: null;
    if ($sid) { $pp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp) $sid = null; elseif (!$pid) $pid = $pp; elseif ($pp !== $pid) $sid = null; }
    $cid = (int)($h['company_id'] ?? 0) ?: null; $vid = (int)($h['vessel_id'] ?? 0) ?: null;
    if ($kind === 'project' && !$pid) throw new RuntimeException(t('dn.need_project')); if ($kind === 'customer' && !$cid) throw new RuntimeException(t('dn.need_company'));
    if ($pid && !$cid && $kind === 'project') { try { $cid = (int) scalar('SELECT co.id FROM projects p JOIN companies co ON co.customer_id = p.customer_id WHERE p.id = ? LIMIT 1', [$pid]) ?: null; if (!$vid) $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]) ?: null; } catch (Throwable $e) {} }
    $wh = (int)($h['warehouse_id'] ?? 0) ?: stk_default_wh(); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($h['issued_at'] ?? '')) ? $h['issued_at'] : date('Y-m-d');
    $lines = []; foreach ($L as $l) { $qty = (float) str_replace(',', '.', (string)($l['qty'] ?? '0')); if ($qty <= 0) continue; $desc = mb_substr(trim((string)($l['description'] ?? '')), 0, 255); $iid = (int)($l['item_id'] ?? 0) ?: null; if ($iid && $desc === '') { $it = stk_item($iid); $desc = $it ? $it['description'] : ''; } if ($desc === '') throw new RuntimeException(t('dn.need_desc')); $lines[] = ['item_id' => $iid, 'description' => $desc, 'unit' => stk_unit_norm((string)($l['unit'] ?? 'pcs')), 'qty' => $qty, 'po_line_id' => (int)($l['po_line_id'] ?? 0) ?: null, 'pr_line_id' => (int)($l['pr_line_id'] ?? 0) ?: null, 'note' => mb_substr(trim((string)($l['note'] ?? '')), 0, 255) ?: null, 'skip_move' => !empty($l['skip_move'])]; }
    if (!$lines) throw new RuntimeException(t('dn.need_lines'));
    $br = null; try { $br = $pid ? ((int) scalar('SELECT branch_id FROM projects WHERE id = ?', [$pid]) ?: null) : (function_exists('br_default') ? (int)br_default()['id'] : null); } catch (Throwable $e) {}
    $no = dn_number($br);
    q('INSERT INTO inv_delivery_notes (dn_no, kind, branch_id, warehouse_id, project_id, subproject_id, company_id, vessel_id, receipt_id, order_id, issued_at, address, attention, carrier, ref_no, note, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$no, $kind, $br, $wh, $pid, $sid, $cid, $vid, (int)($h['receipt_id'] ?? 0) ?: null, (int)($h['order_id'] ?? 0) ?: null, $date, mb_substr(trim((string)($h['address'] ?? '')), 0, 240) ?: null, mb_substr(trim((string)($h['attention'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($h['carrier'] ?? '')), 0, 120) ?: null, mb_substr(trim((string)($h['ref_no'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($h['note'] ?? '')), 0, 600) ?: null, (int)$u['id'], $u['full_name']]);
    $dnId = (int) db()->lastInsertId(); $seq = 0;
    foreach ($lines as $l) { $seq++; $mid = null;
        if ($l['item_id'] && !$l['skip_move']) {
            $it = stk_item($l['item_id']);
            if ($l['pr_line_id'] && function_exists('prq_issue_dn')) { $mid = prq_issue_dn((int)$l['pr_line_id'], $l['qty'], $no, $dnId, $cid); }
            else { $mid = stk_move($l['item_id'], $wh, 'out', $l['qty'], ['unit_price' => $it['last_price'] ?? null, 'project_id' => $pid, 'subproject_id' => $sid, 'reason' => DN_REASON[$kind], 'ref_kind' => 'dn', 'ref_id' => $dnId, 'ref_no' => $no, 'company_id' => $cid, 'note' => $l['description'], 'allow_negative' => !empty($h['allow_negative']), 'item_consumable' => !empty($it['is_consumable'])]); }
        }
        q('INSERT INTO inv_dn_lines (dn_id, seq, item_id, po_line_id, pr_line_id, description, unit, qty, move_id, note) VALUES (?,?,?,?,?,?,?,?,?,?)', [$dnId, $seq, $l['item_id'], $l['po_line_id'], $l['pr_line_id'], $l['description'], $l['unit'], $l['qty'], $mid, $l['note']]);
    }
    cache_bump_later('inv'); return $dnId;
}
/** Manual delivery note from the Inventory › Delivery notes page (lines item[]/desc[]/qty[]/unit[]). */
function dn_create_from_post(): int {
    $L = []; foreach ((array)($_POST['qty'] ?? []) as $i => $q) $L[] = ['item_id' => (int)($_POST['item'][$i] ?? 0), 'description' => (string)($_POST['desc'][$i] ?? ''), 'unit' => (string)($_POST['unit'][$i] ?? 'pcs'), 'qty' => (string)$q, 'note' => (string)($_POST['lnote'][$i] ?? '')];
    return dn_create(['kind' => (string)($_POST['kind'] ?? 'project'), 'warehouse_id' => (int)($_POST['warehouse_id'] ?? 0), 'project_id' => (int)($_POST['project_id'] ?? 0), 'subproject_id' => (int)($_POST['subproject_id'] ?? 0), 'company_id' => (int)($_POST['company_id'] ?? 0), 'vessel_id' => (int)($_POST['vessel_id'] ?? 0), 'issued_at' => (string)($_POST['issued_at'] ?? ''), 'address' => (string)($_POST['address'] ?? ''), 'attention' => (string)($_POST['attention'] ?? ''), 'carrier' => (string)($_POST['carrier'] ?? ''), 'ref_no' => (string)($_POST['ref_no'] ?? ''), 'note' => (string)($_POST['note'] ?? ''), 'allow_negative' => !empty($_POST['allow_negative'])], $L);
}
/** Cancel: the movements are reversed (goods back in the warehouse), requisition issues undone. */
function dn_cancel(int $id, string $note): void {
    require_pr_wh(); $d = dn_load($id); if (!$d || $d['status'] !== 'issued') throw new RuntimeException(t('err.noaccess'));
    foreach (dn_lines($id) as $l) { if (!$l['item_id'] || !$l['move_id']) continue; $mv = one('SELECT * FROM inv_moves WHERE id = ?', [(int)$l['move_id']]); if (!$mv) continue;
        stk_move((int)$l['item_id'], (int)$mv['warehouse_id'], 'in', abs((float)$mv['qty']), ['unit_price' => $mv['unit_price'], 'project_id' => $mv['project_id'], 'subproject_id' => $mv['subproject_id'], 'reason' => $d['kind'] === 'customer' ? 'return_customer' : 'return_project', 'ref_kind' => 'dn', 'ref_id' => $id, 'ref_no' => $d['dn_no'], 'company_id' => $mv['company_id'], 'note' => t('dn.cancelled_note', ['no' => $d['dn_no']])]);
        if ($l['pr_line_id']) q('UPDATE pr_lines SET qty_issued = GREATEST(0, qty_issued - ?), status = IF(status = \'issued\', \'received\', status) WHERE id = ?', [(float)$l['qty'], (int)$l['pr_line_id']]);
    }
    $u = current_user(); q('UPDATE inv_delivery_notes SET status = ?, note = CONCAT(COALESCE(note, \'\'), ?) WHERE id = ?', ['cancelled', "\n[" . date('Y-m-d') . ' ' . $u['full_name'] . '] ' . t('dn.cancelled') . ($note !== '' ? ': ' . mb_substr($note, 0, 300) : ''), $id]);
    cache_bump_later('inv');
}
function dn_of_receipt(int $receiptId): array { return dn_has() ? all(DN_SELECT . ' WHERE d.receipt_id = ? ORDER BY d.id', [$receiptId]) : []; }
function dn_em_attachment(array $p): ?array { if (!dn_can()) return null; $d = dn_load((int)($p[1] ?? 0)); if (!$d) return null; return [dn_pdf($d), $d['dn_no'] . '.pdf', 'application/pdf']; }
/** Address block of the recipient. */
function dn_recipient(array $d): array {
    $out = [];
    if ($d['company_id']) { $co = one('SELECT * FROM companies WHERE id = ?', [(int)$d['company_id']]) ?: []; $out = array_values(array_filter([(string)($co['name'] ?? ''), (string)($co['address'] ?? ''), trim((string)($co['postal_code'] ?? '') . ' ' . (string)($co['city'] ?? '')), (string)($co['country'] ?? '')])); }
    if ($d['vessel']) $out[] = 'M/Y ' . $d['vessel'];
    if ($d['project_code']) $out[] = trim($d['project_code'] . ' · ' . $d['project_name'] . ($d['sub_code'] ? ' › ' . $d['sub_code'] . ' ' . $d['sub_name'] : ''));
    if ($d['address']) $out[] = (string)$d['address']; if ($d['attention']) $out[] = 'Attn. ' . $d['attention'];
    return $out ?: [t('dn.k_' . $d['kind'])];
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
    $kv = [[$T('dn.kind'), $T('dn.k_' . $d['kind'])], [$T('stk.warehouse'), (string)($d['warehouse'] ?? '')], [$T('po.ref'), (string)($d['ref_no'] ?? '')], [$T('po.carrier'), (string)($d['carrier'] ?? '')], [$T('po.receipt_no'), (string)($d['receipt_no'] ?? '') . ($d['po_no'] ? ' · ' . $d['po_no'] : '')]];
    $kv = array_values(array_filter($kv, fn($r) => trim($r[1]) !== '')); $need(count($kv) * 4.5 + 6);
    $pdf->setColor($head); $pdf->rect($Lm, $y, $W, count($kv) * 4.5 + 3, 'F'); $yy = $y + 4;
    foreach ($kv as [$k, $v]) { $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm + 2, $yy, $k); $pdf->setFont('R', 8); $pdf->setColor($ink); $pdf->text($Lm + 40, $yy, mb_substr($v, 0, 110)); $yy += 4.5; }
    $y = $yy + 2;
    $cols = [['#', 8, 'L'], [$T('stk.code'), 32, 'L'], [$T('prq.desc'), 110, 'L'], [$T('prq.qty'), 30, 'R']];
    $hrow = function () use ($pdf, $cols, $navy, $head, $Lm, &$y) { $pdf->setFont('B', 7.5); $pdf->setColor($head); $pdf->rect($Lm, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $Lm; foreach ($cols as [$h, $w, $al]) { $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 4.2, $h, $al); $x += $w; } $y += 6; };
    $need(20); $hrow(); $pdf->setFont('R', 8); $pdf->setColor($ink);
    foreach ($L as $l) {
        $desc = $l['description'] . (($l['maker'] || $l['factory_code']) ? ' — ' . trim(($l['maker'] ?? '') . ' ' . ($l['factory_code'] ?? '')) : '') . ($l['note'] ? ' · ' . $l['note'] : '');
        $lines = $pdf->wrap($desc, $cols[2][1] - 3); $h = max(5.5, count($lines) * 3.8 + 1.8); $need($h + 2);
        $x = $Lm; $cells = [(string)$l['seq'], (string)($l['item_code'] ?: ''), null, stk_qty((float)$l['qty']) . ' ' . $l['unit']];
        foreach ($cols as $i => [$hh, $w, $al]) { if ($cells[$i] === null) { $yy = $y + 3.8; foreach ($lines as $ln) { $pdf->text($x + 1.5, $yy, $ln); $yy += 3.8; } } else $pdf->text($al === 'R' ? $x + $w - 1.5 : $x + 1.5, $y + 3.8, mb_substr($cells[$i], 0, 34), $al); $x += $w; }
        $y += $h; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($Lm, $y, $Lm + array_sum(array_column($cols, 1)), $y); $pdf->setColor($ink);
    }
    if ($d['note']) { $need(16); $y += 3; $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y + 3, $T('po.pdf_notes')); $y += 5; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, (string)$d['note'], 3.9) + 2; }
    $need(30); $y += 6; $pdf->setFont('R', 8); $pdf->setColor($ink); $y += $pdf->paragraph($Lm, $y, $W, $T('dn.pdf_foot'), 3.9) + 6;
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($Lm, $y, $T('dn.pdf_issued_by') . ' ' . $bd['name']); $pdf->text(110, $y, $T('dn.pdf_received_by')); $y += 14; $pdf->setColor($line); $pdf->line($Lm, $y, $Lm + 70, $y); $pdf->line(110, $y, 180, $y);
    $footer(); return $pdf->bytes();
}

/* ------------------------------------------------------------------ R97-037: parts of delivery notes → accounting
 * Every line of an issued delivery note to a project or a customer carries an invoicing state:
 *   open     procurement has not decided yet
 *   invoice  to be invoiced — waits at accounting (with the unit sale price procurement proposes)
 *   offer    included in the offer — nothing to invoice separately
 *   invoiced accounting registered the invoice number and date
 * Procurement (pr_admin) decides, accounting (acc_is_accounting) registers the invoice or sends a line back. */
const DNI_STATES = ['open', 'invoice', 'offer', 'invoiced'];
function dni_has(): bool { static $h = null; if ($h === null) { try { $h = dn_has() && (bool) db()->query("SHOW COLUMNS FROM inv_dn_lines LIKE 'inv_state'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
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

<?php
/**
 * Payables: supplier invoices, credit notes, payment requests, payments.   GAS-CP-R81-001
 * Accounting (is_accounting) and Procurement register a supplier invoice against a purchase order;
 * the three-way check compares every invoice line with the PO line (price) and the goods accepted
 * and not yet invoiced (quantity). Checked invoices go into a payment request per supplier; over the
 * threshold of Settings › Procurement (proc_pay_threshold) the request needs Management; Accounting
 * records the payments (partial allowed), which close the invoice and feed the cash flow. Supplier
 * statement (invoices · credits · payments · balance) and payables aging on Accounting › Payables.
 * Tables: si_invoices · si_lines · si_requests · si_request_items · si_payments · si_events.
 */
declare(strict_types=1);

const SI_STATES = ['draft', 'checked', 'disputed', 'requested', 'approved', 'paid', 'cancelled'];
const SI_TONE   = ['draft' => 'mute', 'checked' => 'ok', 'disputed' => 'bad', 'requested' => 'gold', 'approved' => 'ok', 'paid' => 'navy', 'cancelled' => 'mute'];
const SI_R_TONE = ['submitted' => 'gold', 'mgmt' => 'warn', 'approved' => 'ok', 'rejected' => 'bad', 'paid' => 'navy', 'cancelled' => 'mute'];
const SI_METHODS = ['transfer', 'card', 'cash', 'cheque', 'other'];
const SI_TOL_PCT = 2.0;   // price tolerance of the three-way check

function si_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'si_invoices'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** Accounting, Procurement and Management see and register; payments are recorded by Accounting / Management. */
function si_can(): bool { static $v = null; if ($v === null) $v = is_staff() && si_has() && ((function_exists('mgmt_can') && mgmt_can()) || (function_exists('col_flag') && col_flag('is_accounting')) || (function_exists('pr_admin') && pr_admin())); return $v; }
function si_pay_can(): bool { static $v = null; if ($v === null) $v = is_staff() && si_has() && ((function_exists('mgmt_can') && mgmt_can()) || (function_exists('col_flag') && col_flag('is_accounting'))); return $v; }
function require_si(): array { $u = require_staff(); if (!si_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function require_si_pay(): array { $u = require_staff(); if (!si_pay_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function si_pill(string $s): string { return pill(SI_TONE[$s] ?? 'mute', t('si.s_' . $s)); }
function si_r_pill(string $s): string { return pill(SI_R_TONE[$s] ?? 'mute', t('si.r_' . $s)); }
function si_m_pill(string $s): string { return $s === 'none' ? '' : pill($s === 'ok' ? 'ok' : 'bad', t('si.m_' . $s)); }
function si_money(?float $v, string $cur = 'EUR'): string { return $v === null ? '—' : number_format($v, 2, ',', '.') . ' ' . $cur; }
function si_threshold(): float { return (float) cp_setting('proc_pay_threshold', '0'); }
function si_number(string $fam, ?int $branchId): string {
    $b = null; if (function_exists('br_all') && $branchId) foreach (br_all() as $bb) if ((int)$bb['id'] === $branchId) $b = $bb; if (!$b && function_exists('br_default')) $b = br_default();
    $prefix = (string)(($b['doc_prefix'] ?? '') ?: 'GAS'); $yy = date('y');
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', ['portal', $fam . ':' . $prefix . ':' . $yy]);
    return $prefix . '-' . $fam . '-' . $yy . '-' . str_pad((string)(int) db()->lastInsertId(), 4, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ read */
const SI_SELECT = "SELECT i.*, co.name AS supplier, o.po_no, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, (i.gross - i.paid) AS open_amount,
                   (SELECT COUNT(*) FROM si_lines l WHERE l.invoice_id = i.id) AS n_lines
                   FROM si_invoices i JOIN companies co ON co.id = i.supplier_id LEFT JOIN po_orders o ON o.id = i.order_id LEFT JOIN projects p ON p.id = i.project_id LEFT JOIN subprojects s ON s.id = i.subproject_id";
function si_load(int $id): ?array { return si_has() && $id ? one(SI_SELECT . ' WHERE i.id = ?', [$id]) ?: null : null; }
function si_list(array $f = []): array {
    if (!si_has()) return []; $w = []; $a = [];
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "i.status IN ('draft','checked','disputed','requested','approved')"; elseif ($f['status'] === 'overdue') $w[] = "i.status IN ('checked','disputed','requested','approved') AND i.due_date < CURDATE()"; elseif (in_array($f['status'], SI_STATES, true)) { $w[] = 'i.status = ?'; $a[] = $f['status']; } }
    if (!empty($f['supplier_id'])) { $w[] = 'i.supplier_id = ?'; $a[] = (int)$f['supplier_id']; }
    if (!empty($f['order_id'])) { $w[] = 'i.order_id = ?'; $a[] = (int)$f['order_id']; }
    if (!empty($f['subproject_id'])) { $w[] = 'i.subproject_id = ?'; $a[] = (int)$f['subproject_id']; }
    if (!empty($f['q'])) { $l = '%' . trim((string)$f['q']) . '%'; $w[] = '(i.si_no LIKE ? OR i.inv_no LIKE ? OR co.name LIKE ? OR o.po_no LIKE ? OR p.project_code LIKE ?)'; array_push($a, $l, $l, $l, $l, $l); }
    return all(SI_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY i.id DESC', $a);
}
function si_lines(int $iid): array { return all('SELECT l.*, pl.seq AS po_seq, pl.description AS po_desc, pl.qty AS po_qty, pl.qty_accepted, pl.qty_invoiced, pl.unit_price AS po_unit_price, pl.discount_pct FROM si_lines l LEFT JOIN po_lines pl ON pl.id = l.po_line_id WHERE l.invoice_id = ? ORDER BY l.seq, l.id', [$iid]); }
function si_events(int $iid = 0, int $rid = 0): array { return $iid ? all('SELECT * FROM si_events WHERE invoice_id = ? ORDER BY id DESC', [$iid]) : all('SELECT * FROM si_events WHERE request_id = ? ORDER BY id DESC', [$rid]); }
function si_event(?int $iid, ?int $rid, string $ev, ?string $note = null): void { $u = current_user(); q('INSERT INTO si_events (invoice_id, request_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?,?)', [$iid, $rid, $ev, $u['id'] ?? null, $u['full_name'] ?? null, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64), $note !== null ? mb_substr($note, 0, 600) : null]); }
function si_payments(int $iid): array { return all('SELECT * FROM si_payments WHERE invoice_id = ? ORDER BY pay_date, id', [$iid]); }
function si_counts(): array {
    if (!si_can()) return ['open' => 0, 'overdue' => 0, 'requests' => 0, 'mgmt' => 0, 'open_sum' => 0.0];
    try { return ['open' => (int) scalar("SELECT COUNT(*) FROM si_invoices WHERE status IN ('draft','checked','disputed','requested','approved')"), 'overdue' => (int) scalar("SELECT COUNT(*) FROM si_invoices WHERE status IN ('checked','disputed','requested','approved') AND due_date < CURDATE()"), 'requests' => (int) scalar("SELECT COUNT(*) FROM si_requests WHERE status IN ('submitted','mgmt','approved')"), 'mgmt' => (int) scalar("SELECT COUNT(*) FROM si_requests WHERE status = 'mgmt'"), 'open_sum' => (float) scalar("SELECT COALESCE(SUM(IF(kind = 'credit', -(gross - paid), gross - paid)),0) FROM si_invoices WHERE status IN ('checked','disputed','requested','approved')")]; } catch (Throwable $e) { return ['open' => 0, 'overdue' => 0, 'requests' => 0, 'mgmt' => 0, 'open_sum' => 0.0]; }
}

/* ------------------------------------------------------------------ invoice */
function si_recalc(int $iid): void {
    $i = one('SELECT vat_pct FROM si_invoices WHERE id = ?', [$iid]); $net = 0.0;
    foreach (all('SELECT * FROM si_lines WHERE invoice_id = ?', [$iid]) as $l) { $lt = round((float)$l['qty'] * (float)$l['unit_price'], 2); q('UPDATE si_lines SET line_total = ? WHERE id = ?', [$lt, (int)$l['id']]); $net += $lt; }
    $vat = round($net * (float)($i['vat_pct'] ?? 0) / 100, 2);
    q('UPDATE si_invoices SET net = ?, vat = ?, gross = ? WHERE id = ?', [round($net, 2), $vat, round($net + $vat, 2), $iid]);
}
function si_editable(array $i): bool { return si_can() && in_array($i['status'], ['draft', 'disputed'], true); }
function si_save_from_post(): int {
    require_si(); $id = (int)($_POST['id'] ?? 0); $u = current_user();
    $kind = ($_POST['kind'] ?? 'invoice') === 'credit' ? 'credit' : 'invoice';
    $sup = (int)($_POST['supplier_id'] ?? 0); $oid = (int)($_POST['order_id'] ?? 0) ?: null; $O = $oid ? po_load($oid) : null;
    if ($O) { if ($O['kind'] !== 'po') throw new RuntimeException(t('err.notfound')); $sup = (int)$O['supplier_id']; }
    $S = $sup ? sup_load($sup) : null; if (!$S) throw new RuntimeException(t('po.need_supplier'));
    $invNo = mb_substr(trim((string)($_POST['inv_no'] ?? '')), 0, 80); if ($invNo === '') throw new RuntimeException(t('si.need_inv_no'));
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $issue = $d('issue_date') ?: date('Y-m-d'); $terms = (int)($S['terms_days'] ?? 0); $due = $d('due_date') ?: date('Y-m-d', strtotime($issue . ' +' . max(0, $terms) . ' days'));
    $creditOf = $kind === 'credit' ? ((int)($_POST['credit_of'] ?? 0) ?: null) : null; if ($creditOf && !scalar('SELECT 1 FROM si_invoices WHERE id = ? AND supplier_id = ? AND kind = ?', [$creditOf, $sup, 'invoice'])) $creditOf = null;
    $pid = $O ? ($O['project_id'] ? (int)$O['project_id'] : null) : ((int)($_POST['project_id'] ?? 0) ?: null); $sid = $O ? ($O['subproject_id'] ? (int)$O['subproject_id'] : null) : ((int)($_POST['subproject_id'] ?? 0) ?: null);
    if ($sid && !$O) { $pp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp) $sid = null; elseif (!$pid) $pid = $pp; }
    $v = ['supplier_id' => $sup, 'inv_no' => $invNo, 'order_id' => $oid, 'credit_of' => $creditOf, 'project_id' => $pid, 'subproject_id' => $sid, 'issue_date' => $issue, 'due_date' => $due, 'received_at' => $d('received_at') ?: date('Y-m-d'),
          'currency' => preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : ($O['currency'] ?? (($S['sup_currency'] ?? '') ?: 'EUR')),
          'vat_pct' => max(0.0, min(30.0, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? ($O['vat_pct'] ?? '0'))))), 'note' => trim((string)($_POST['note'] ?? '')) ?: null];
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $pid) $fileId = cp_store_file($_FILES['file'], $pid, $sid, 'file', 'internal', t('si.file_title', ['s' => $S['name'], 'n' => $invNo]));
    if ($fileId) $v['file_id'] = $fileId;
    if ($id) {
        $i = si_load($id); if (!$i || !si_editable($i)) throw new RuntimeException(t('err.noaccess'));
        if (scalar('SELECT 1 FROM si_invoices WHERE supplier_id = ? AND inv_no = ? AND id <> ?', [$sup, $invNo, $id])) throw new RuntimeException(t('si.dup_inv_no'));
        q('UPDATE si_invoices SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ' WHERE id = ?', array_merge(array_values($v), [$id])); si_recalc($id); si_event($id, null, 'edit');
    } else {
        if (scalar('SELECT 1 FROM si_invoices WHERE supplier_id = ? AND inv_no = ?', [$sup, $invNo])) throw new RuntimeException(t('si.dup_inv_no'));
        $br = $O ? ($O['branch_id'] ? (int)$O['branch_id'] : null) : (function_exists('br_default') ? (int)br_default()['id'] : null);
        $v = ['kind' => $kind, 'si_no' => si_number($kind === 'credit' ? 'SCN' : 'SI', $br), 'branch_id' => $br] + $v + ['created_by' => (int)$u['id'], 'created_name' => $u['full_name']];
        q('INSERT INTO si_invoices (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
        $id = (int) db()->lastInsertId(); si_event($id, null, 'create', $v['si_no']);
        if ($O && !empty($_POST['pull_lines'])) si_lines_from_po($id, (int)$O['id']);
        si_recalc($id);
    }
    return $id;
}
/** Pre-fill the invoice lines from the PO: every line with accepted goods not yet invoiced, at the PO price. */
function si_lines_from_po(int $iid, int $oid): int {
    $n = 0; $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) FROM si_lines WHERE invoice_id = ?', [$iid]);
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled' ORDER BY seq", [$oid]) as $l) {
        $open = (float)$l['qty_accepted'] - (float)$l['qty_invoiced']; if ($open <= 0.0005) continue;
        if (scalar('SELECT 1 FROM si_lines WHERE invoice_id = ? AND po_line_id = ?', [$iid, (int)$l['id']])) continue;
        $price = round((float)($l['unit_price'] ?? 0) * (1 - (float)$l['discount_pct'] / 100), 4);
        q('INSERT INTO si_lines (invoice_id, seq, po_line_id, description, qty, unit_price, line_total) VALUES (?,?,?,?,?,?,?)', [$iid, ++$seq, (int)$l['id'], $l['description'], round($open, 3), $price, round($open * $price, 2)]); $n++;
    }
    return $n;
}
function si_line_save_from_post(): int {
    require_si(); $iid = (int)($_POST['invoice_id'] ?? 0); $i = si_load($iid); if (!$i || !si_editable($i)) throw new RuntimeException(t('err.noaccess'));
    $lid = (int)($_POST['line_id'] ?? 0); $qty = (float) str_replace(',', '.', (string)($_POST['qty'] ?? '1')); if ($qty <= 0) throw new RuntimeException(t('prq.need_qty'));
    $price = round((float) str_replace(',', '.', (string)($_POST['unit_price'] ?? '0')), 4); $pol = (int)($_POST['po_line_id'] ?? 0) ?: null;
    if ($pol && (!$i['order_id'] || !scalar('SELECT 1 FROM po_lines WHERE id = ? AND order_id = ?', [$pol, (int)$i['order_id']]))) $pol = null;
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255); if ($desc === '' && $pol) $desc = (string) scalar('SELECT description FROM po_lines WHERE id = ?', [$pol]); if ($desc === '') throw new RuntimeException(t('prq.need_desc'));
    if ($lid) { if (!scalar('SELECT 1 FROM si_lines WHERE id = ? AND invoice_id = ?', [$lid, $iid])) throw new RuntimeException(t('err.notfound')); q('UPDATE si_lines SET po_line_id = ?, description = ?, qty = ?, unit_price = ? WHERE id = ?', [$pol, $desc, $qty, $price, $lid]); }
    else { $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) + 1 FROM si_lines WHERE invoice_id = ?', [$iid]); q('INSERT INTO si_lines (invoice_id, seq, po_line_id, description, qty, unit_price) VALUES (?,?,?,?,?,?)', [$iid, $seq, $pol, $desc, $qty, $price]); $lid = (int) db()->lastInsertId(); }
    si_recalc($iid); return $lid;
}
function si_line_delete(int $iid, int $lid): void { $i = si_load($iid); if (!$i || !si_editable($i)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM si_lines WHERE id = ? AND invoice_id = ?', [$lid, $iid]); si_recalc($iid); }
/** Three-way check: per line with a PO line — price within tolerance of the PO price, quantity within the accepted and not yet invoiced quantity. */
function si_match(int $iid): string {
    $i = si_load($iid); $worst = 'none'; $rank = ['none' => 0, 'ok' => 1, 'qty' => 2, 'price' => 2, 'both' => 3];
    foreach (si_lines($iid) as $l) {
        if (!$l['po_line_id']) { q("UPDATE si_lines SET match_status = 'none', po_qty_open = NULL, po_price = NULL WHERE id = ?", [(int)$l['id']]); continue; }
        $open = (float)$l['qty_accepted'] - (float)$l['qty_invoiced']; $pp = round((float)($l['po_unit_price'] ?? 0) * (1 - (float)$l['discount_pct'] / 100), 4);
        $qOk = (float)$l['qty'] <= $open + 0.0005; $pOk = $pp > 0 ? abs((float)$l['unit_price'] - $pp) / $pp * 100 <= SI_TOL_PCT : true;
        $st = $qOk && $pOk ? 'ok' : (!$qOk && !$pOk ? 'both' : ($qOk ? 'price' : 'qty'));
        q('UPDATE si_lines SET match_status = ?, po_qty_open = ?, po_price = ? WHERE id = ?', [$st, round($open, 3), $pp, (int)$l['id']]);
        if ($rank[$st] > $rank[$worst]) $worst = $st;
    }
    q('UPDATE si_invoices SET match_status = ? WHERE id = ?', [$worst, $iid]); return $worst;
}
/** Accounting / Procurement checks the invoice: a clean match → checked (PO lines' invoiced quantity booked, cost on the sub-project); mismatch → disputed unless forced with a reason. */
function si_check(int $iid, bool $force, string $note): void {
    require_si(); $i = si_load($iid); if (!$i || !in_array($i['status'], ['draft', 'disputed'], true)) throw new RuntimeException(t('err.noaccess'));
    if (!(int) scalar('SELECT COUNT(*) FROM si_lines WHERE invoice_id = ?', [$iid])) throw new RuntimeException(t('prq.need_lines'));
    $m = si_match($iid); $u = current_user(); $note = mb_substr(trim($note), 0, 400);
    if (in_array($m, ['qty', 'price', 'both'], true) && !$force) { q('UPDATE si_invoices SET status = ? WHERE id = ?', ['disputed', $iid]); si_event($iid, null, 'dispute', t('si.m_' . $m) . ($note !== '' ? ' · ' . $note : '')); return; }
    if ($m !== 'ok' && $m !== 'none' && $note === '') throw new RuntimeException(t('si.need_force_reason'));
    q('UPDATE si_invoices SET status = ?, checked_by = ?, checked_name = ?, checked_at = NOW() WHERE id = ?', ['checked', (int)$u['id'], $u['full_name'], $iid]);
    foreach (si_lines($iid) as $l) if ($l['po_line_id']) q('UPDATE po_lines SET qty_invoiced = qty_invoiced + ? WHERE id = ?', [(float)$l['qty'] * ($i['kind'] === 'credit' ? -1 : 1), (int)$l['po_line_id']]);
    si_sp_cost_sync($iid);
    si_event($iid, null, 'check', ($m !== 'ok' && $m !== 'none' ? t('si.forced') . ' · ' : '') . $note);
    cache_bump_later('eng');
}
/** The invoice on the sub-project costs (kind invoice, negative for a credit note) — the cash flow treats it as an actual outflow; the PO "order" row stays as the commitment. */
function si_sp_cost_sync(int $iid): void {
    $i = si_load($iid); if (!$i || !function_exists('bg_has') || !bg_has()) return; $u = current_user();
    $want = $i['subproject_id'] && in_array($i['status'], ['checked', 'requested', 'approved', 'paid'], true); $amt = ($i['kind'] === 'credit' ? -1 : 1) * (float)$i['net'];
    if (!$want) { if ($i['sp_cost_id']) { q('DELETE FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']]); q('UPDATE si_invoices SET sp_cost_id = NULL WHERE id = ?', [$iid]); } }
    elseif ($i['sp_cost_id'] && scalar('SELECT 1 FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']])) q('UPDATE sp_costs SET cost_date = ?, supplier = ?, description = ?, ref_no = ?, amount = ?, currency = ? WHERE id = ?', [$i['issue_date'], $i['supplier'], mb_substr(t($i['kind'] === 'credit' ? 'si.sp_cost_credit' : 'si.sp_cost_desc', ['n' => $i['inv_no'], 'po' => $i['po_no'] ?: '']), 0, 255), mb_substr($i['inv_no'], 0, 60), $amt, $i['currency'], (int)$i['sp_cost_id']]);
    else { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, file_id, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [(int)$i['subproject_id'], $i['issue_date'], 'invoice', $i['supplier'], mb_substr(t($i['kind'] === 'credit' ? 'si.sp_cost_credit' : 'si.sp_cost_desc', ['n' => $i['inv_no'], 'po' => $i['po_no'] ?: '']), 0, 255), mb_substr($i['inv_no'], 0, 60), $amt, $i['currency'], $i['file_id'], (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null]); q('UPDATE si_invoices SET sp_cost_id = ? WHERE id = ?', [(int) db()->lastInsertId(), $iid]); }
    // the PO commitment is reduced by what is invoiced, so Budget & actual does not count the order twice
    if ($i['order_id'] && function_exists('po_load')) { $o = po_load((int)$i['order_id']); if ($o && $o['cost_id']) { $inv = (float) scalar("SELECT COALESCE(SUM(IF(kind = 'credit', -net, net)),0) FROM si_invoices WHERE order_id = ? AND status IN ('checked','requested','approved','paid')", [(int)$o['id']]); q('UPDATE sp_costs SET amount = ? WHERE id = ?', [max(0.0, (float)$o['subtotal'] - $inv), (int)$o['cost_id']]); } }
    if (function_exists('eng_rows_reset')) eng_rows_reset();
}
function si_cancel(int $iid, string $note): void {
    require_si(); $i = si_load($iid); if (!$i || in_array($i['status'], ['paid', 'cancelled'], true) || (float)$i['paid'] > 0) throw new RuntimeException(t('err.noaccess')); if ($note === '') throw new RuntimeException(t('prq.need_reason'));
    if (in_array($i['status'], ['checked', 'requested', 'approved'], true)) foreach (si_lines($iid) as $l) if ($l['po_line_id']) q('UPDATE po_lines SET qty_invoiced = GREATEST(0, qty_invoiced - ?) WHERE id = ?', [(float)$l['qty'] * ($i['kind'] === 'credit' ? -1 : 1), (int)$l['po_line_id']]);
    q("DELETE FROM si_request_items WHERE invoice_id = ? AND request_id IN (SELECT id FROM si_requests WHERE status IN ('submitted','mgmt'))", [$iid]);
    q('UPDATE si_invoices SET status = ? WHERE id = ?', ['cancelled', $iid]); si_sp_cost_sync($iid); si_event($iid, null, 'cancel', $note); cache_bump_later('eng');
}
function si_reopen(int $iid): void { require_si(); $i = si_load($iid); if (!$i || $i['status'] !== 'checked' || (float)$i['paid'] > 0 || scalar("SELECT 1 FROM si_request_items ri JOIN si_requests r ON r.id = ri.request_id WHERE ri.invoice_id = ? AND r.status IN ('submitted','mgmt','approved')", [$iid])) throw new RuntimeException(t('err.noaccess')); foreach (si_lines($iid) as $l) if ($l['po_line_id']) q('UPDATE po_lines SET qty_invoiced = GREATEST(0, qty_invoiced - ?) WHERE id = ?', [(float)$l['qty'] * ($i['kind'] === 'credit' ? -1 : 1), (int)$l['po_line_id']]); q('UPDATE si_invoices SET status = ? WHERE id = ?', ['draft', $iid]); si_sp_cost_sync($iid); si_event($iid, null, 'reopen'); }

/* ------------------------------------------------------------------ payment requests */
const SI_R_SELECT = "SELECT r.*, co.name AS supplier, (SELECT COUNT(*) FROM si_request_items ri WHERE ri.request_id = r.id) AS n_inv FROM si_requests r JOIN companies co ON co.id = r.supplier_id";
function si_request_load(int $id): ?array { return si_has() && $id ? one(SI_R_SELECT . ' WHERE r.id = ?', [$id]) ?: null : null; }
function si_requests(array $f = []): array { $w = []; $a = []; if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "r.status IN ('submitted','mgmt','approved')"; else { $w[] = 'r.status = ?'; $a[] = $f['status']; } } if (!empty($f['supplier_id'])) { $w[] = 'r.supplier_id = ?'; $a[] = (int)$f['supplier_id']; } return all(SI_R_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.id DESC', $a); }
function si_request_items(int $rid): array { return all('SELECT ri.*, i.si_no, i.inv_no, i.kind, i.gross, i.paid, i.due_date, i.status AS inv_status, i.currency, o.po_no FROM si_request_items ri JOIN si_invoices i ON i.id = ri.invoice_id LEFT JOIN po_orders o ON o.id = i.order_id WHERE ri.request_id = ? ORDER BY i.due_date, i.id', [$rid]); }
/** Payment request: checked invoices (and credit notes) of one supplier → amount = Σ open; over the threshold → management. */
function si_request_create(array $invoiceIds, ?string $payBy, string $note): int {
    require_si(); $u = current_user(); $ids = array_values(array_unique(array_map('intval', $invoiceIds))); if (!$ids) throw new RuntimeException(t('si.need_invoices'));
    $rows = all('SELECT * FROM si_invoices WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ") AND status = 'checked'", $ids); if (count($rows) !== count($ids)) throw new RuntimeException(t('si.need_checked'));
    $sup = (int)$rows[0]['supplier_id']; $cur = $rows[0]['currency']; $amt = 0.0;
    foreach ($rows as $r) { if ((int)$r['supplier_id'] !== $sup || $r['currency'] !== $cur) throw new RuntimeException(t('si.one_supplier')); $amt += ($r['kind'] === 'credit' ? -1 : 1) * ((float)$r['gross'] - (float)$r['paid']); }
    if ($amt <= 0) throw new RuntimeException(t('si.need_positive'));
    $thr = si_threshold(); $over = $thr > 0 && $amt > $thr; $br = $rows[0]['branch_id'] ? (int)$rows[0]['branch_id'] : null;
    q('INSERT INTO si_requests (req_no, supplier_id, amount, currency, pay_by, status, over_threshold, note, requested_by, requested_name) VALUES (?,?,?,?,?,?,?,?,?,?)', [si_number('PRQ', $br), $sup, round($amt, 2), $cur, $payBy, $over ? 'mgmt' : ($u && function_exists('mgmt_can') && mgmt_can() ? 'approved' : 'submitted'), $over ? 1 : 0, mb_substr($note, 0, 600) ?: null, (int)$u['id'], $u['full_name']]);
    $rid = (int) db()->lastInsertId();
    foreach ($rows as $r) { q('INSERT INTO si_request_items (request_id, invoice_id, amount) VALUES (?,?,?)', [$rid, (int)$r['id'], ($r['kind'] === 'credit' ? -1 : 1) * ((float)$r['gross'] - (float)$r['paid'])]); q('UPDATE si_invoices SET status = ? WHERE id = ?', ['requested', (int)$r['id']]); si_event((int)$r['id'], $rid, 'request'); }
    $R = si_request_load($rid); si_event(null, $rid, 'create', $R['req_no'] . ' · ' . si_money($amt, $cur) . ($over ? ' · ' . t('si.over_threshold') : ''));
    if ($R['status'] === 'approved') { si_request_mark_approved($rid); }
    elseif ($over && function_exists('nf_push')) { try { nf_push(prq_mgmt_ids(), 'work:si_mgmt', 'sir:' . $rid, t('si.nf_mgmt', ['no' => $R['req_no']]), $R['supplier'] . ' · ' . si_money($amt, $cur), url('payables', ['tab' => 'requests', 'id' => $rid]), (int)$u['id'], $u['full_name']); } catch (Throwable $e) {} }
    elseif (function_exists('nf_push')) { try { nf_push(si_accounting_ids(), 'work:si_request', 'sir:' . $rid, t('si.nf_request', ['no' => $R['req_no']]), $R['supplier'] . ' · ' . si_money($amt, $cur), url('payables', ['tab' => 'requests', 'id' => $rid]), (int)$u['id'], $u['full_name']); } catch (Throwable $e) {} }
    return $rid;
}
function si_accounting_ids(): array { try { return array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role IN ('staff','admin') AND is_accounting = 1"), 'id')); } catch (Throwable $e) { return []; } }
function si_request_mark_approved(int $rid): void { foreach (si_request_items($rid) as $it) q('UPDATE si_invoices SET status = ? WHERE id = ? AND status = ?', ['approved', (int)$it['invoice_id'], 'requested']); }
/** Accounting approves a request under the threshold; Management decides one over it. */
function si_request_decide(int $rid, string $decision, string $note): void {
    $R = si_request_load($rid); if (!$R) throw new RuntimeException(t('err.notfound')); $u = current_user(); $note = mb_substr(trim($note), 0, 400);
    if ($R['status'] === 'mgmt') { if (!function_exists('mgmt_can') || !mgmt_can()) throw new RuntimeException(t('err.noaccess')); }
    elseif ($R['status'] === 'submitted') { if (!si_pay_can()) throw new RuntimeException(t('err.noaccess')); }
    else throw new RuntimeException(t('err.noaccess'));
    if ($decision === 'reject') { if ($note === '') throw new RuntimeException(t('prq.need_reason')); q('UPDATE si_requests SET status = ?, decided_by = ?, decided_name = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', ['rejected', (int)$u['id'], $u['full_name'], $note, $rid]); foreach (si_request_items($rid) as $it) q('UPDATE si_invoices SET status = ? WHERE id = ? AND status = ?', ['checked', (int)$it['invoice_id'], 'requested']); si_event(null, $rid, 'reject', $note); }
    else { q('UPDATE si_requests SET status = ?, decided_by = ?, decided_name = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', ['approved', (int)$u['id'], $u['full_name'], $note ?: null, $rid]); si_request_mark_approved($rid); si_event(null, $rid, 'approve', $note ?: null); }
    if (function_exists('nf_push')) { try { nf_push(array_unique(array_merge([(int)$R['requested_by']], si_accounting_ids())), 'work:si_decided', 'sir:' . $rid . ':' . $decision, t($decision === 'reject' ? 'si.nf_rejected' : 'si.nf_approved', ['no' => $R['req_no']]), $R['supplier'] . ' · ' . si_money((float)$R['amount'], $R['currency']) . ($note !== '' ? ' · ' . $note : ''), url('payables', ['tab' => 'requests', 'id' => $rid]), (int)$u['id'], $u['full_name']); } catch (Throwable $e) {} }
}
function si_request_cancel(int $rid, string $note): void { require_si(); $R = si_request_load($rid); if (!$R || !in_array($R['status'], ['submitted', 'mgmt', 'approved'], true)) throw new RuntimeException(t('err.noaccess')); foreach (si_request_items($rid) as $it) q('UPDATE si_invoices SET status = ? WHERE id = ? AND status IN (?,?)', ['checked', (int)$it['invoice_id'], 'requested', 'approved']); q('UPDATE si_requests SET status = ? WHERE id = ?', ['cancelled', $rid]); si_event(null, $rid, 'cancel', $note ?: null); }

/* ------------------------------------------------------------------ payments */
/** A payment on an invoice (approved request, or any checked invoice for Management / Accounting); partial allowed; the invoice is paid when the open amount reaches zero; the request is paid when all its invoices are. */
function si_pay_from_post(): int {
    require_si_pay(); $iid = (int)($_POST['invoice_id'] ?? 0); $i = si_load($iid); if (!$i || !in_array($i['status'], ['approved', 'checked', 'requested'], true)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $amt = round((float) str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2); $open = round((float)$i['gross'] - (float)$i['paid'], 2); if ($amt <= 0 || $amt > $open + 0.005) throw new RuntimeException(t('si.pay_max', ['v' => si_money($open, $i['currency'])]));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['pay_date'] ?? '')) ? $_POST['pay_date'] : date('Y-m-d'); $method = in_array($_POST['method'] ?? '', SI_METHODS, true) ? $_POST['method'] : 'transfer';
    $rid = (int) scalar("SELECT r.id FROM si_requests r JOIN si_request_items ri ON ri.request_id = r.id WHERE ri.invoice_id = ? AND r.status = 'approved' ORDER BY r.id DESC LIMIT 1", [$iid]) ?: null;
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $i['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$i['project_id'], $i['subproject_id'] ? (int)$i['subproject_id'] : null, 'file', 'internal', t('si.pay_file', ['n' => $i['inv_no']]));
    q('INSERT INTO si_payments (invoice_id, request_id, pay_date, amount, currency, method, ref, note, file_id, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$iid, $rid, $date, $amt, $i['currency'], $method, mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $fileId, (int)$u['id'], $u['full_name']]);
    $pid = (int) db()->lastInsertId(); $paid = (float)$i['paid'] + $amt; $done = $paid >= (float)$i['gross'] - 0.005;
    q('UPDATE si_invoices SET paid = ?, status = ? WHERE id = ?', [round($paid, 2), $done ? 'paid' : $i['status'], $iid]); si_event($iid, $rid, 'pay', si_money($amt, $i['currency']) . ' · ' . t('si.pm_' . $method) . (($_POST['ref'] ?? '') !== '' ? ' · ' . trim((string)$_POST['ref']) : ''));
    if ($rid && !(int) scalar("SELECT COUNT(*) FROM si_request_items ri JOIN si_invoices i ON i.id = ri.invoice_id WHERE ri.request_id = ? AND i.status <> 'paid'", [$rid])) { q("UPDATE si_requests SET status = 'paid' WHERE id = ?", [$rid]); si_event(null, $rid, 'paid'); }
    // a credit note is "paid" by being deducted: Management / Accounting record it with the payment it offsets, so the statement balances
    if ($done && function_exists('nf_push')) { try { $to = array_values(array_unique(array_filter([(int)$i['created_by']]))); if ($to) nf_push($to, 'work:si_paid', 'si:' . $iid . ':paid', t('si.nf_paid', ['n' => $i['inv_no']]), $i['supplier'] . ' · ' . si_money((float)$i['gross'], $i['currency']), url('payable', ['id' => $iid]), (int)$u['id'], $u['full_name']); } catch (Throwable $e) {} }
    cache_bump_later('eng'); return $pid;
}
function si_payment_delete(int $iid, int $pid): void { require_si_pay(); $p = one('SELECT * FROM si_payments WHERE id = ? AND invoice_id = ?', [$pid, $iid]); if (!$p) throw new RuntimeException(t('err.notfound')); $i = si_load($iid); q('DELETE FROM si_payments WHERE id = ?', [$pid]); $paid = max(0.0, (float)$i['paid'] - (float)$p['amount']); q('UPDATE si_invoices SET paid = ?, status = IF(status = ?, ?, status) WHERE id = ?', [round($paid, 2), 'paid', $p['request_id'] ? 'approved' : 'checked', $iid]); if ($p['request_id']) q("UPDATE si_requests SET status = 'approved' WHERE id = ? AND status = 'paid'", [(int)$p['request_id']]); si_event($iid, $p['request_id'] ? (int)$p['request_id'] : null, 'unpay', si_money((float)$p['amount'], $p['currency'])); cache_bump_later('eng'); }

/* ------------------------------------------------------------------ statement · aging */
/** Supplier statement: invoices (+), credit notes (−), payments (−) in date order with the running balance. */
function si_statement(int $supplierId, ?string $from = null, ?string $to = null): array {
    $rows = []; $to = $to ?: date('Y-m-d');
    foreach (all("SELECT i.id, i.kind, i.si_no, i.inv_no, i.issue_date AS d, i.due_date, i.gross, i.currency, i.status, o.po_no FROM si_invoices i LEFT JOIN po_orders o ON o.id = i.order_id WHERE i.supplier_id = ? AND i.status NOT IN ('draft','cancelled') AND i.issue_date <= ?", [$supplierId, $to]) as $r) $rows[] = ['d' => $r['d'], 'kind' => $r['kind'], 'ref' => $r['inv_no'], 'si_no' => $r['si_no'], 'id' => (int)$r['id'], 'po' => $r['po_no'], 'due' => $r['due_date'], 'debit' => $r['kind'] === 'credit' ? 0.0 : (float)$r['gross'], 'credit' => $r['kind'] === 'credit' ? (float)$r['gross'] : 0.0, 'cur' => $r['currency'], 'status' => $r['status']];
    foreach (all("SELECT p.*, i.inv_no FROM si_payments p JOIN si_invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ? AND p.pay_date <= ?", [$supplierId, $to]) as $p) $rows[] = ['d' => $p['pay_date'], 'kind' => 'payment', 'ref' => $p['ref'] ?: t('si.pm_' . $p['method']), 'si_no' => null, 'id' => (int)$p['invoice_id'], 'po' => null, 'due' => null, 'debit' => 0.0, 'credit' => (float)$p['amount'], 'cur' => $p['currency'], 'status' => null, 'inv_no' => $p['inv_no']];
    usort($rows, fn($a, $b) => [$a['d'], $a['kind'] === 'payment' ? 1 : 0] <=> [$b['d'], $b['kind'] === 'payment' ? 1 : 0]);
    $bal = 0.0; $open = 0.0; $out = [];
    foreach ($rows as $r) { $bal += $r['debit'] - $r['credit']; $r['balance'] = $bal; if ($from && $r['d'] < $from) { $open = $bal; continue; } $out[] = $r; }
    return ['rows' => $out, 'opening' => $open, 'balance' => $bal, 'from' => $from, 'to' => $to];
}
/** Payables aging per supplier: open invoice amounts by days past due (not due · 1–30 · 31–60 · 61–90 · 90+), credit notes deducted from "not due". */
function si_aging(): array {
    $B = []; $T = ['not_due' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
    foreach (all("SELECT i.supplier_id, co.name AS supplier, i.kind, i.gross - i.paid AS open_amt, i.due_date, i.currency FROM si_invoices i JOIN companies co ON co.id = i.supplier_id WHERE i.status IN ('checked','disputed','requested','approved') AND i.gross - i.paid > 0.005") as $r) {
        $k = (int)$r['supplier_id']; if (!isset($B[$k])) $B[$k] = ['supplier' => $r['supplier'], 'cur' => $r['currency'], 'not_due' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd90' => 0.0, 'd90p' => 0.0, 'total' => 0.0];
        $amt = ($r['kind'] === 'credit' ? -1 : 1) * (float)$r['open_amt']; $days = $r['due_date'] ? (int) floor((time() - strtotime($r['due_date'])) / 86400) : 0;
        $col = $r['kind'] === 'credit' || $days <= 0 ? 'not_due' : ($days <= 30 ? 'd30' : ($days <= 60 ? 'd60' : ($days <= 90 ? 'd90' : 'd90p')));
        $B[$k][$col] += $amt; $B[$k]['total'] += $amt; $T[$col] += $amt; $T['total'] += $amt;
    }
    uasort($B, fn($a, $b) => $b['total'] <=> $a['total']); return ['rows' => $B, 'totals' => $T];
}
/** Open PO lines that can still be invoiced (accepted − invoiced) for the invoice line picker. */
function si_po_open_lines(int $oid): array { return all("SELECT l.*, l.qty_accepted - l.qty_invoiced AS open_qty FROM po_lines l WHERE l.order_id = ? AND l.status <> 'cancelled' ORDER BY l.seq", [$oid]); }

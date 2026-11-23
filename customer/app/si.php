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

/** R97-118: the proforma columns are there (0 = invoice, 1 = proforma waiting for the supplier's invoice, 2 = proforma with the invoice assigned). */
function si_pf_has(): bool { static $h = null; if ($h === null) { try { $h = si_has() && (bool) scalar("SHOW COLUMNS FROM si_invoices LIKE 'is_proforma'"); } catch (Throwable $e) { $h = false; } } return $h; }
function si_is_pf(?array $i): bool { return $i && (int)($i['is_proforma'] ?? 0) === 1; }
/** Prepaid basis: the document is, or started as, a proforma — it is checked against what was ordered, not what was received. */
function si_prepaid(?array $i): bool { return $i && (int)($i['is_proforma'] ?? 0) > 0; }
function si_pf_pill(?array $i): string { $v = (int)($i['is_proforma'] ?? 0); return $v === 1 ? ' ' . pill('warn', t('si.pf')) : ($v === 2 ? ' ' . pill('mute', t('si.pf_done')) : ''); }
function si_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'si_invoices'"); } catch (Throwable $e) { $h = false; } } return $h; }
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
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "i.status IN ('draft','checked','disputed','requested','approved')"; elseif ($f['status'] === 'overdue') $w[] = "i.status IN ('checked','disputed','requested','approved') AND i.due_date < CURDATE()"; elseif ($f['status'] === 'proforma') $w[] = si_pf_has() ? "i.is_proforma = 1 AND i.status <> 'cancelled'" : '0'; elseif (in_array($f['status'], SI_STATES, true)) { $w[] = 'i.status = ?'; $a[] = $f['status']; } }
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
    $sup = (int)($_POST['supplier_id'] ?? 0); $oid = (int)($_POST['order_id'] ?? 0) ?: null;
    if ($id && !$oid) $oid = (int) scalar('SELECT order_id FROM si_invoices WHERE id = ?', [$id]) ?: null;   // R97-135: the order of a saved invoice is shown locked and is not posted — it was lost on every edit
    $O = $oid ? po_load($oid) : null;
    if ($O) { if ($O['kind'] !== 'po') throw new RuntimeException(t('err.notfound')); $sup = (int)$O['supplier_id']; }
    $S = $sup ? sup_load($sup) : null; if (!$S) throw new RuntimeException(t('po.need_supplier'));
    $invNo = mb_substr(trim((string)($_POST['inv_no'] ?? '')), 0, 80); if ($invNo === '') throw new RuntimeException(t('si.need_inv_no'));
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $issue = $d('issue_date') ?: date('Y-m-d'); $terms = (int)($S['terms_days'] ?? 0); $due = $d('due_date') ?: date('Y-m-d', strtotime($issue . ' +' . max(0, $terms) . ' days'));
    $creditOf = $kind === 'credit' ? ((int)($_POST['credit_of'] ?? 0) ?: null) : null; if ($creditOf && !scalar('SELECT 1 FROM si_invoices WHERE id = ? AND supplier_id = ? AND kind = ?', [$creditOf, $sup, 'invoice'])) $creditOf = null;
    [$pid, $sid] = si_project_pick($O, $id ?: null);   // R97-135: of the order (or of its requisitions); only without those, what was chosen on the form
    $v = ['supplier_id' => $sup, 'inv_no' => $invNo, 'order_id' => $oid, 'credit_of' => $creditOf, 'project_id' => $pid, 'subproject_id' => $sid, 'issue_date' => $issue, 'due_date' => $due, 'received_at' => $d('received_at') ?: date('Y-m-d'),
          'currency' => preg_match('/^[A-Z]{3}$/', (string)($_POST['currency'] ?? '')) ? $_POST['currency'] : ($O['currency'] ?? (($S['sup_currency'] ?? '') ?: 'EUR')),
          'vat_pct' => max(0.0, min(30.0, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? ($O['vat_pct'] ?? '0'))))), 'note' => trim((string)($_POST['note'] ?? '')) ?: null];
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $pid) $fileId = cp_store_file($_FILES['file'], $pid, $sid, 'file', 'internal', t('si.file_title', ['s' => $S['name'], 'n' => $invNo]));
    if ($fileId) $v['file_id'] = $fileId;
    if ($id) {
        $i = si_load($id); if (!$i || !si_editable($i)) throw new RuntimeException(t('err.noaccess'));
        if ($i['kind'] !== 'credit' && isset($_POST['is_proforma']) && si_pf_has() && (int)$i['is_proforma'] < 2) $v['is_proforma'] = $_POST['is_proforma'] === '1' ? 1 : 0;   // R97-118: invoice or proforma, while it can still be edited
        if (scalar('SELECT 1 FROM si_invoices WHERE supplier_id = ? AND inv_no = ? AND id <> ?', [$sup, $invNo, $id])) throw new RuntimeException(t('si.dup_inv_no'));
        q('UPDATE si_invoices SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($v))) . ' WHERE id = ?', array_merge(array_values($v), [$id])); si_recalc($id); si_event($id, null, 'edit');
        if (!$fileId && !empty($_FILES['file']['tmp_name'])) { try { si_doc_store($_FILES['file'], $id, null, 'invoice', t('si.file_title', ['s' => $S['name'], 'n' => $invNo])); } catch (Throwable $e) { flash('bad', $e->getMessage()); } }   // R97-134
    } else {
        if (scalar('SELECT 1 FROM si_invoices WHERE supplier_id = ? AND inv_no = ?', [$sup, $invNo])) throw new RuntimeException(t('si.dup_inv_no'));
        $br = $O ? ($O['branch_id'] ? (int)$O['branch_id'] : null) : (function_exists('br_default') ? (int)br_default()['id'] : null);
        $v = ['kind' => $kind, 'si_no' => si_number($kind === 'credit' ? 'SCN' : 'SI', $br), 'branch_id' => $br] + $v + ['created_by' => (int)$u['id'], 'created_name' => $u['full_name']];
        if ($kind !== 'credit' && ($_POST['is_proforma'] ?? '') === '1' && si_pf_has()) $v['is_proforma'] = 1;   // R97-118

        q('INSERT INTO si_invoices (' . implode(', ', array_keys($v)) . ') VALUES (' . implode(', ', array_fill(0, count($v), '?')) . ')', array_values($v));
        $id = (int) db()->lastInsertId(); si_event($id, null, 'create', $v['si_no']);
        if (!$fileId && !empty($_FILES['file']['tmp_name'])) { try { si_doc_store($_FILES['file'], $id, null, 'invoice', t('si.file_title', ['s' => $S['name'], 'n' => $invNo])); } catch (Throwable $e) { flash('bad', $e->getMessage()); } }   // R97-134
        if ($O && !empty($_POST['pull_lines'])) { si_lines_from_po($id, (int)$O['id']); si_costs_from_po($id, (int)$O['id']); }   // R97-071: and the logistic costs of the supplier
        si_recalc($id);
    }
    return $id;
}
/** R97-135: project and sub-project of a purchase order — its own; else the ones of the requisitions its lines came from, when they all agree. */
function si_po_project(array $O): array {
    $pid = !empty($O['project_id']) ? (int)$O['project_id'] : null; $sid = !empty($O['subproject_id']) ? (int)$O['subproject_id'] : null; if ($pid && $sid) return [$pid, $sid];
    try {
        $rows = all('SELECT DISTINCT r.project_id, r.subproject_id FROM po_lines l JOIN pr_lines pl ON pl.id = l.pr_line_id JOIN pr_requests r ON r.id = pl.request_id WHERE l.order_id = ? AND r.project_id IS NOT NULL' . ($pid ? ' AND r.project_id = ' . $pid : ''), [(int)$O['id']]);
        $ps = array_values(array_unique(array_map(fn($r) => (int)$r['project_id'], $rows))); $ss = array_values(array_unique(array_map(fn($r) => (int)$r['subproject_id'], $rows)));
        if (!$pid && count($ps) === 1) $pid = $ps[0];
        if ($pid && !$sid && count($ps) === 1 && count($ss) === 1 && $ss[0]) $sid = $ss[0];
    } catch (Throwable $e) {}
    return [$pid, $sid];
}
/** R97-135: project and sub-project of an invoice — of its order when the order has them, else as posted (a sub-project brings its project). */
function si_project_pick(?array $O, ?int $iid = null): array {
    [$pid, $sid] = $O ? si_po_project($O) : [null, null];
    $has = array_key_exists('project_id', $_POST) || array_key_exists('subproject_id', $_POST);
    if (!$has && $iid) { $x = one('SELECT project_id, subproject_id FROM si_invoices WHERE id = ?', [$iid]); $xp = $x && $x['project_id'] ? (int)$x['project_id'] : null; $xs = $x && $x['subproject_id'] ? (int)$x['subproject_id'] : null; if (!$pid) return [$xp, $xs]; return [$pid, $sid ?: ($xp === $pid ? $xs : null)]; }   // nothing posted: what the invoice has stays
    if (!$pid) { $pid = (int)($_POST['project_id'] ?? 0) ?: null; $sid = null; }
    if (!$sid) { $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; if ($sid) { $pp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp || ($pid && $pp !== $pid)) $sid = null; elseif (!$pid) $pid = $pp; } }
    if ($pid && !scalar('SELECT 1 FROM projects WHERE id = ?', [$pid])) { $pid = null; $sid = null; }
    return [$pid, $sid];
}
/** R97-135: whether the order of an invoice may still be changed — no line of it is matched to a line of an order. */
function si_order_free(int $iid): bool { return !scalar('SELECT 1 FROM si_lines WHERE invoice_id = ? AND po_line_id IS NOT NULL LIMIT 1', [$iid]); }
/** R97-135: link a saved invoice / proforma (also checked, requested, paid) to its purchase order, project and sub-project. */
function si_link_from_post(): int {
    require_si(); $iid = (int)($_POST['id'] ?? 0); $i = si_load($iid); if (!$i || $i['status'] === 'cancelled') throw new RuntimeException(t('err.noaccess'));
    $oid = $i['order_id'] ? (int)$i['order_id'] : null;
    if (array_key_exists('order_id', $_POST) && si_order_free($iid)) {
        $new = (int)$_POST['order_id'] ?: null;
        if ($new && $new !== $oid) { $N = po_load($new); if (!$N || $N['kind'] !== 'po' || $N['status'] === 'draft' || $N['status'] === 'cancelled') throw new RuntimeException(t('err.notfound')); if ((int)$N['supplier_id'] !== (int)$i['supplier_id']) throw new RuntimeException(t('si.link_sup')); }
        $oid = $new;
    }
    $O = $oid ? po_load($oid) : null; [$pid, $sid] = si_project_pick($O, $iid);
    $was = [(int)$i['order_id'], (int)$i['project_id'], (int)$i['subproject_id']]; if ($was === [(int)$oid, (int)$pid, (int)$sid]) return $iid;
    si_link_apply($iid, $oid, $pid, $sid);
    $n = si_load($iid); si_event($iid, null, 'link', trim(($n['po_no'] ?: '—') . ' · ' . ($n['project_code'] ?: '—') . ($n['sub_code'] ? ' › ' . $n['sub_code'] : '')));
    return $iid;
}
function si_link_apply(int $iid, ?int $oid, ?int $pid, ?int $sid): void {
    $i = one('SELECT order_id, subproject_id, sp_cost_id FROM si_invoices WHERE id = ?', [$iid]); if (!$i) return;
    if ($i['sp_cost_id'] && (int)$i['subproject_id'] !== (int)$sid) { q('DELETE FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']]); q('UPDATE si_invoices SET sp_cost_id = NULL WHERE id = ?', [$iid]); }   // the cost follows the invoice to the other sub-project
    q('UPDATE si_invoices SET order_id = ?, project_id = ?, subproject_id = ? WHERE id = ?', [$oid, $pid, $sid, $iid]);
    si_recalc($iid); try { si_sp_cost_sync($iid); } catch (Throwable $e) {}
    if ((int)$i['order_id'] && (int)$i['order_id'] !== (int)$oid) { $x = (int) scalar('SELECT id FROM si_invoices WHERE order_id = ? ORDER BY id LIMIT 1', [(int)$i['order_id']]); if ($x) { try { si_sp_cost_sync($x); } catch (Throwable $e) {} } }
    if (function_exists('cache_bump_later')) cache_bump_later('eng');
}
/** R97-135: once — give back the order to invoices that lost it on an edit (their lines still point to its lines), and the project of the order to invoices of an order without one. */
function si_link_repair(): int {
    if (!si_has() || cp_setting('si_link_fix', '') === '1') return 0; $n = 0;
    try {
        foreach (all('SELECT i.id, MIN(pl.order_id) AS oid FROM si_invoices i JOIN si_lines l ON l.invoice_id = i.id JOIN po_lines pl ON pl.id = l.po_line_id WHERE i.order_id IS NULL GROUP BY i.id HAVING COUNT(DISTINCT pl.order_id) = 1') as $r) { $O = po_load((int)$r['oid']); if (!$O) continue; [$pid, $sid] = si_po_project($O); $x = one('SELECT project_id, subproject_id FROM si_invoices WHERE id = ?', [(int)$r['id']]); si_link_apply((int)$r['id'], (int)$O['id'], $pid ?: ((int)$x['project_id'] ?: null), $pid ? $sid : ((int)$x['subproject_id'] ?: null)); q('INSERT INTO si_events (invoice_id, event, user_name, note) VALUES (?,?,?,?)', [(int)$r['id'], 'link', 'System', $O['po_no']]); $n++; }
        foreach (all('SELECT i.id, i.order_id, i.project_id FROM si_invoices i WHERE i.order_id IS NOT NULL AND (i.project_id IS NULL OR i.subproject_id IS NULL)') as $r) { $O = po_load((int)$r['order_id']); if (!$O) continue; [$pid, $sid] = si_po_project($O); if (!$pid || ($r['project_id'] && (int)$r['project_id'] !== $pid) || ($r['project_id'] && !$sid)) continue; si_link_apply((int)$r['id'], (int)$O['id'], $pid, $sid); q('INSERT INTO si_events (invoice_id, event, user_name, note) VALUES (?,?,?,?)', [(int)$r['id'], 'link', 'System', $O['po_no']]); $n++; }
        cp_setting_set('si_link_fix', '1');
    } catch (Throwable $e) { error_log('si_link_repair: ' . $e->getMessage()); }
    return $n;
}
/** Pre-fill the invoice lines from the PO: every line with accepted goods not yet invoiced, at the PO price. */
function si_lines_from_po(int $iid, int $oid): int {
    $n = 0; $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) FROM si_lines WHERE invoice_id = ?', [$iid]); $pre = si_prepaid(si_load($iid));   // R97-118: a proforma takes what was ordered — the goods are not in yet
    foreach (all("SELECT * FROM po_lines WHERE order_id = ? AND status <> 'cancelled' ORDER BY seq", [$oid]) as $l) {
        $open = (float)$l[$pre ? 'qty' : 'qty_accepted'] - (float)$l['qty_invoiced']; if ($open <= 0.0005) continue;
        if (scalar('SELECT 1 FROM si_lines WHERE invoice_id = ? AND po_line_id = ?', [$iid, (int)$l['id']])) continue;
        $price = round((float)($l['unit_price'] ?? 0) * (1 - (float)$l['discount_pct'] / 100), 4);
        q('INSERT INTO si_lines (invoice_id, seq, po_line_id, description, qty, unit_price, line_total) VALUES (?,?,?,?,?,?,?)', [$iid, ++$seq, (int)$l['id'], $l['description'], round($open, 3), $price, round($open * $price, 2)]); $n++;
    }
    return $n;
}
/* ---- R97-071: logistic costs of the purchase order on the supplier invoice */
function si_lc_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM si_lines LIKE 'po_cost_id'") && (bool) scalar("SHOW TABLES LIKE 'po_costs'"); } catch (Throwable $e) { $h = false; } } return $h; }
function si_lc_norm(string $s): string { return preg_replace('/[^a-z0-9α-ω]/u', '', mb_strtolower($s)); }
/** Is the provider typed on a logistic cost the supplier of the invoice? Empty provider = the supplier of the order. */
function si_lc_same(?string $provider, string $supplier): bool { $p = si_lc_norm((string)$provider); $s = si_lc_norm($supplier); return $p === '' || ($s !== '' && mb_strlen($p) >= 3 && (str_contains($s, $p) || str_contains($p, $s))); }
/** Logistic costs of the order that are not yet on a supplier invoice (cancelled invoices do not count). */
function si_lc_open(int $oid): array {
    return si_lc_has() ? all("SELECT c.* FROM po_costs c WHERE c.order_id = ? AND NOT EXISTS (SELECT 1 FROM si_lines sl JOIN si_invoices x ON x.id = sl.invoice_id WHERE sl.po_cost_id = c.id AND x.status <> 'cancelled') ORDER BY c.cost_date, c.id", [$oid]) : [];
}
/** Pull the open logistic costs charged by the supplier of the invoice, one line each. Costs of another provider or in another currency stay out and are reported.
 *  Returns ['n' => pulled, 'other' => [provider names], 'cur' => n in another currency]. */
function si_costs_from_po(int $iid, int $oid, ?int $onlyCost = null): array {   // $onlyCost (R97-072): that one cost, whoever its provider is — the user chose it
    $out = ['n' => 0, 'other' => [], 'cur' => 0]; $i = si_load($iid); if (!$i || !si_lc_has() || $i['kind'] === 'credit') return $out;
    $po = (string) scalar('SELECT po_no FROM po_orders WHERE id = ?', [$oid]); $seq = (int) scalar('SELECT COALESCE(MAX(seq),0) FROM si_lines WHERE invoice_id = ?', [$iid]);
    foreach (si_lc_open($oid) as $c) {
        if ($onlyCost !== null && (int)$c['id'] !== $onlyCost) continue;
        if ($onlyCost === null && !si_lc_same($c['provider'], (string)$i['supplier'])) { $out['other'][(string)$c['provider']] = 1; continue; }
        if ((string)$c['currency'] !== (string)$i['currency']) { $out['cur']++; continue; }
        $amt = round((float)$c['amount'], 2); $desc = mb_substr(t('po.c_' . $c['kind']) . ($onlyCost !== null && $c['provider'] ? ' · ' . $c['provider'] : '') . ' · ' . $po . ($c['ref'] ? ' · ' . $c['ref'] : '') . ($c['note'] ? ' · ' . $c['note'] : ''), 0, 255);
        q('INSERT INTO si_lines (invoice_id, seq, po_line_id, po_cost_id, description, qty, unit_price, line_total) VALUES (?,?,NULL,?,?,1,?,?)', [$iid, ++$seq, (int)$c['id'], $desc, $amt, $amt]); $out['n']++;
    }
    $out['other'] = array_keys($out['other']); return $out;
}
/** Net of the logistic-cost lines of an invoice — already on the sub-project as cost of kind Logistics, so it is left out of the invoice cost. */
function si_lc_net(int $iid): float { return si_lc_has() ? (float) scalar('SELECT COALESCE(SUM(line_total),0) FROM si_lines WHERE invoice_id = ? AND po_cost_id IS NOT NULL', [$iid]) : 0.0; }
/** The supplier invoice that carries a logistic cost: [id, si_no] or null. */
function si_lc_invoice(int $costId): ?array { return si_lc_has() ? (one("SELECT x.id, x.si_no FROM si_lines sl JOIN si_invoices x ON x.id = sl.invoice_id WHERE sl.po_cost_id = ? AND x.status <> 'cancelled' LIMIT 1", [$costId]) ?: null) : null; }

/** R97-070: why "Pull received PO lines" brought nothing — the lines of the order by reason. ['total','on_invoice','not_received','invoiced'] */
function si_pull_why(int $iid, int $oid): array {
    $w = ['total' => 0, 'on_invoice' => 0, 'not_received' => 0, 'invoiced' => 0];
    foreach (all("SELECT l.id, l.qty, l.qty_accepted, l.qty_invoiced, (SELECT COUNT(*) FROM si_lines s WHERE s.invoice_id = ? AND s.po_line_id = l.id) AS here FROM po_lines l WHERE l.order_id = ? AND l.status <> 'cancelled'", [$iid, $oid]) as $l) {
        $w['total']++;
        if ((int)$l['here']) $w['on_invoice']++;
        elseif ((float)$l['qty_accepted'] - (float)$l['qty_invoiced'] > 0.0005) continue;
        elseif ((float)$l['qty_accepted'] + 0.0005 < (float)$l['qty']) $w['not_received']++;
        else $w['invoiced']++;
    }
    return $w;
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
        $open = (float)$l[si_prepaid($i) ? 'po_qty' : 'qty_accepted'] - (float)$l['qty_invoiced']; $pp = round((float)($l['po_unit_price'] ?? 0) * (1 - (float)$l['discount_pct'] / 100), 4);
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
    $want = $i['subproject_id'] && in_array($i['status'], ['checked', 'requested', 'approved', 'paid'], true); $amt = ($i['kind'] === 'credit' ? -1 : 1) * ((float)$i['net'] - si_lc_net($iid));   /* R97-071: logistic-cost lines are already a cost of kind Logistics — not twice */
    if (!$want) { if ($i['sp_cost_id']) { q('DELETE FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']]); q('UPDATE si_invoices SET sp_cost_id = NULL WHERE id = ?', [$iid]); } }
    elseif ($i['sp_cost_id'] && scalar('SELECT 1 FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']])) q('UPDATE sp_costs SET cost_date = ?, supplier = ?, description = ?, ref_no = ?, amount = ?, currency = ? WHERE id = ?', [$i['issue_date'], $i['supplier'], mb_substr(t($i['kind'] === 'credit' ? 'si.sp_cost_credit' : 'si.sp_cost_desc', ['n' => $i['inv_no'], 'po' => $i['po_no'] ?: '']), 0, 255), mb_substr($i['inv_no'], 0, 60), $amt, $i['currency'], (int)$i['sp_cost_id']]);
    else { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, currency, file_id, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [(int)$i['subproject_id'], $i['issue_date'], 'invoice', $i['supplier'], mb_substr(t($i['kind'] === 'credit' ? 'si.sp_cost_credit' : 'si.sp_cost_desc', ['n' => $i['inv_no'], 'po' => $i['po_no'] ?: '']), 0, 255), mb_substr($i['inv_no'], 0, 60), $amt, $i['currency'], $i['file_id'], (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null]); q('UPDATE si_invoices SET sp_cost_id = ? WHERE id = ?', [(int) db()->lastInsertId(), $iid]); }
    // the PO commitment is reduced by what is invoiced, so Budget & actual does not count the order twice
    if ($i['order_id'] && function_exists('po_load')) { $o = po_load((int)$i['order_id']); if ($o && $o['cost_id']) { $inv = (float) scalar("SELECT COALESCE(SUM(IF(kind = 'credit', -net, net)),0) FROM si_invoices WHERE order_id = ? AND status IN ('checked','requested','approved','paid')", [(int)$o['id']]); if (si_lc_has()) $inv -= (float) scalar("SELECT COALESCE(SUM(sl.line_total),0) FROM si_lines sl JOIN si_invoices x ON x.id = sl.invoice_id WHERE x.order_id = ? AND x.kind <> 'credit' AND x.status IN ('checked','requested','approved','paid') AND sl.po_cost_id IS NOT NULL", [(int)$o['id']]);   /* the commitment is for the parts only */ q('UPDATE sp_costs SET amount = ? WHERE id = ?', [max(0.0, (float)$o['subtotal'] - $inv), (int)$o['cost_id']]); } }
    if (function_exists('eng_rows_reset')) eng_rows_reset();
}
/** R97-206: who may delete a cancelled invoice for good — administrators and management (CEO, General Manager, Managing Director). */
function si_del_can(): bool { return is_staff() && si_has() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())); }
/** Delete a CANCELLED supplier invoice, proforma or credit note that was never paid and is on no payment request. Lines and history go; its documents go to the
 *  recycle bin; one line stays in the register of deleted invoices (who, when, number, supplier, amount, reason). The portal number is never given again. */
function si_delete(int $iid, string $reason): string {
    if (!si_del_can()) throw new RuntimeException(t('err.noaccess')); $i = si_load($iid); if (!$i) throw new RuntimeException(t('err.notfound'));
    if ($i['status'] !== 'cancelled') throw new RuntimeException(t('si.del_only_cancelled')); $reason = trim($reason); if (mb_strlen($reason) < 5) throw new RuntimeException(t('si.del_reason'));
    if ((float)$i['paid'] > 0.004 || (int) scalar('SELECT COUNT(*) FROM si_payments WHERE invoice_id = ?', [$iid]) || (int) scalar('SELECT COUNT(*) FROM si_request_items WHERE invoice_id = ?', [$iid]) || (int) scalar("SELECT COUNT(*) FROM si_invoices WHERE credit_of = ? AND status <> 'cancelled'", [$iid])) throw new RuntimeException(t('si.del_has_pay'));
    q('UPDATE si_invoices SET credit_of = NULL WHERE credit_of = ?', [$iid]);   // a cancelled credit note of it stays, without the link
    $no = (string)$i['si_no']; $what = $no . ' · ' . (string)($i['supplier'] ?? '') . ' · ' . trim((string)($i['inv_no'] ?? '') . ' ' . (string)($i['proforma_no'] ?? '')) . ' · ' . si_money((float)$i['gross'], (string)$i['currency']) . (!empty($i['po_no']) ? ' · ' . $i['po_no'] : '');
    $pdo = db(); $pdo->beginTransaction();
    try {
        if (si_docs_has()) { $T = function_exists('ft_types') ? (ft_types()['si_docs'] ?? null) : null;
            foreach (si_docs_of($iid) as $d) { $abs = cp_storage() . '/' . $d['stored_name'];
                if ($T && function_exists('ft_trash_row')) ft_trash_row('si_docs', $d, $T[0]($d), $T[1]($d), $no . ' — ' . t('si.del_log'), true, $reason);
                else { if (str_starts_with((string)$d['stored_name'], 'si/') && !str_contains((string)$d['stored_name'], '..') && is_file($abs)) @unlink($abs); q('DELETE FROM si_docs WHERE id = ?', [(int)$d['id']]); } } }
        if (!empty($i['sp_cost_id'])) q('DELETE FROM sp_costs WHERE id = ?', [(int)$i['sp_cost_id']]);
        q('DELETE FROM si_lines WHERE invoice_id = ?', [$iid]); q('DELETE FROM si_events WHERE invoice_id = ?', [$iid]); q('DELETE FROM si_invoices WHERE id = ? AND status = ?', [$iid, 'cancelled']);
        si_event(null, null, 'delete', mb_substr($what . ' — ' . $reason, 0, 600));
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    cache_bump_later('eng'); return $no;
}
/** The register of deleted invoices, newest first. */
function si_deleted_log(int $limit = 100): array { return si_has() ? all("SELECT created_at, user_name, note FROM si_events WHERE event = 'delete' AND invoice_id IS NULL AND request_id IS NULL ORDER BY id DESC LIMIT " . max(1, min(500, $limit))) : []; }
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
function si_requests(array $f = []): array { $w = []; $a = []; if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "r.status IN ('submitted','mgmt','approved')"; elseif ($f['status'] === 'mine') { $ms = si_my_statuses(); $w[] = $ms ? "r.status IN ('" . implode("','", $ms) . "')" : '1 = 0'; } else { $w[] = 'r.status = ?'; $a[] = $f['status']; } } if (!empty($f['supplier_id'])) { $w[] = 'r.supplier_id = ?'; $a[] = (int)$f['supplier_id']; } return all(SI_R_SELECT . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY r.id DESC', $a); }
/** R97-155: the request statuses the signed-in user may decide — «mgmt» for Management, «submitted» for Accounting and Management (the same rule as si_request_decide). */
function si_my_statuses(): array { $s = []; if (!si_has() || !is_staff()) return $s; if (si_pay_can()) $s[] = 'submitted'; if (function_exists('mgmt_can') && mgmt_can()) $s[] = 'mgmt'; return $s; }
/** Payment requests waiting for a decision of the signed-in user, oldest first. */
function si_my_pending(): array { static $v = null; if ($v !== null) return $v; $ms = si_my_statuses(); if (!$ms) return $v = []; try { return $v = all(SI_R_SELECT . " WHERE r.status IN ('" . implode("','", $ms) . "') ORDER BY r.created_at, r.id"); } catch (Throwable $e) { return $v = []; } }
function si_request_items(int $rid): array { return all('SELECT ri.*, i.si_no, i.inv_no, i.kind, i.gross, i.paid, i.due_date, i.status AS inv_status, i.currency, i.order_id, i.project_id, i.subproject_id, o.po_no, p.project_code, s.code AS sub_code FROM si_request_items ri JOIN si_invoices i ON i.id = ri.invoice_id LEFT JOIN po_orders o ON o.id = i.order_id LEFT JOIN projects p ON p.id = i.project_id LEFT JOIN subprojects s ON s.id = i.subproject_id WHERE ri.request_id = ? ORDER BY i.due_date, i.id', [$rid]); }
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
/* ------------------------------------------------------------------ R97-134: documents of an invoice without a project */
/** A file of a project is filed in the project (sp_files). An invoice without a project — stock, IT, overheads — had nowhere to put its PDF or the
 *  proof of a payment, so the file was dropped. Such files are kept here, with the invoice, and are read through the invoice by Accounting only. */
function si_docs_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'si_docs'"); } catch (Throwable $e) { $h = false; } } return $h; }
const SI_DOC_EXT = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
function si_doc_store(array $file, int $iid, ?int $payId, string $kind, string $title): ?int {
    if (!si_docs_has() || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return null;
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)); $mime = (string)(mime_content_type($file['tmp_name']) ?: '');
    if (!isset(SI_DOC_EXT[$ext]) || (int)$file['size'] > 15 * 1024 * 1024) throw new RuntimeException(t('si.doc_type'));
    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp'], true) && $mime !== SI_DOC_EXT[$ext]) throw new RuntimeException(t('si.doc_type'));   // the content must be what the name says
    $rel = 'si/' . date('Y/m'); $dir = cp_storage() . '/' . $rel; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = $rel . '/' . bin2hex(random_bytes(12)) . '.' . $ext; if (!move_uploaded_file($file['tmp_name'], cp_storage() . '/' . $stored)) throw new RuntimeException(t('err.upload'));
    $u = current_user();
    q('INSERT INTO si_docs (invoice_id, payment_id, kind, title, stored_name, original_name, mime_type, size_bytes, uploaded_by, uploaded_name) VALUES (?,?,?,?,?,?,?,?,?,?)', [$iid, $payId, $kind === 'invoice' ? 'invoice' : 'proof', mb_substr($title, 0, 200), $stored, mb_substr((string)$file['name'], 0, 255), SI_DOC_EXT[$ext], (int)$file['size'], (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
    return (int) db()->lastInsertId();
}
function si_docs_of(int $iid): array { return si_docs_has() ? all('SELECT * FROM si_docs WHERE invoice_id = ? ORDER BY id', [$iid]) : []; }
function si_doc_path(array $d): ?string { $p = cp_storage() . '/' . $d['stored_name']; return str_starts_with((string)$d['stored_name'], 'si/') && !str_contains((string)$d['stored_name'], '..') && is_file($p) ? $p : null; }
/** Stream a document of an invoice — Accounting / management only, and only through its invoice. */
function si_doc_stream(int $iid, int $docId): void {
    if (!si_can()) { http_response_code(403); exit(t('err.noaccess')); }
    $d = si_docs_has() ? one('SELECT * FROM si_docs WHERE id = ? AND invoice_id = ?', [$docId, $iid]) : null; $p = $d ? si_doc_path($d) : null; if (!$p) { http_response_code(404); exit('Not found'); }
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . $d['mime_type']); header('Content-Length: ' . filesize($p)); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string)$d['original_name']) . '"'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); readfile($p); exit;
}
/** e-mail attachment 'sd:<doc id>' (the remittance e-mail). */
function si_doc_em_attachment(array $p): ?array { if (!si_can() || !si_docs_has()) return null; $d = one('SELECT * FROM si_docs WHERE id = ?', [(int)($p[1] ?? 0)]); $f = $d ? si_doc_path($d) : null; return $f ? [(string) file_get_contents($f), (string)$d['original_name'], (string)$d['mime_type']] : null; }
/** Attach the proof to a payment recorded without one (the file could not be kept before, or came later). */
function si_pay_doc_from_post(): int {
    require_si_pay(); $iid = (int)($_POST['id'] ?? 0); $i = si_load($iid); $p = $i ? one('SELECT * FROM si_payments WHERE id = ? AND invoice_id = ?', [(int)($_POST['pay_id'] ?? 0), $iid]) : null; if (!$p) throw new RuntimeException(t('err.notfound'));
    if (empty($_FILES['file']['tmp_name'])) throw new RuntimeException(t('im.file')); $title = t('si.pay_file', ['n' => $i['inv_no']]);
    if ($i['project_id']) { $fid = cp_store_file($_FILES['file'], (int)$i['project_id'], $i['subproject_id'] ? (int)$i['subproject_id'] : null, 'file', 'internal', $title); if (!$fid) throw new RuntimeException(t('err.upload')); q('UPDATE si_payments SET file_id = ? WHERE id = ?', [$fid, (int)$p['id']]); }
    elseif (!si_doc_store($_FILES['file'], $iid, (int)$p['id'], 'proof', $title)) throw new RuntimeException(t('err.upload'));
    si_event($iid, $p['request_id'] ? (int)$p['request_id'] : null, 'doc', t('si.pay_doc_ev', ['d' => fmt_date($p['pay_date'])])); return $iid;
}
function si_pay_from_post(): int {
    require_si_pay(); $iid = (int)($_POST['invoice_id'] ?? 0); $i = si_load($iid); if (!$i || !in_array($i['status'], ['approved', 'checked', 'requested'], true)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $amt = round((float) str_replace(',', '.', (string)($_POST['amount'] ?? '0')), 2); $open = round((float)$i['gross'] - (float)$i['paid'], 2); if ($amt <= 0 || $amt > $open + 0.005) throw new RuntimeException(t('si.pay_max', ['v' => si_money($open, $i['currency'])]));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['pay_date'] ?? '')) ? $_POST['pay_date'] : date('Y-m-d'); $method = in_array($_POST['method'] ?? '', SI_METHODS, true) ? $_POST['method'] : 'transfer';
    $rid = (int) scalar("SELECT r.id FROM si_requests r JOIN si_request_items ri ON ri.request_id = r.id WHERE ri.invoice_id = ? AND r.status = 'approved' ORDER BY r.id DESC LIMIT 1", [$iid]) ?: null;
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $i['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$i['project_id'], $i['subproject_id'] ? (int)$i['subproject_id'] : null, 'file', 'internal', t('si.pay_file', ['n' => $i['inv_no']]));
    q('INSERT INTO si_payments (invoice_id, request_id, pay_date, amount, currency, method, ref, note, file_id, user_id, user_name) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$iid, $rid, $date, $amt, $i['currency'], $method, mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80) ?: null, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null, $fileId, (int)$u['id'], $u['full_name']]);
    $pid = (int) db()->lastInsertId(); $paid = (float)$i['paid'] + $amt; $done = $paid >= (float)$i['gross'] - 0.005;
    if (!$fileId && !empty($_FILES['file']['tmp_name'])) { try { si_doc_store($_FILES['file'], $iid, $pid, 'proof', t('si.pay_file', ['n' => $i['inv_no']])); } catch (Throwable $e) { flash('bad', $e->getMessage()); } }   // R97-134: no project → kept with the invoice
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
    foreach (all("SELECT i.*, i.issue_date AS d, o.po_no FROM si_invoices i LEFT JOIN po_orders o ON o.id = i.order_id WHERE i.supplier_id = ? AND i.status NOT IN ('draft','cancelled') AND i.issue_date <= ?", [$supplierId, $to]) as $r) $rows[] = ['d' => $r['d'], 'kind' => si_is_pf($r) ? 'proforma' : $r['kind'], 'ref' => $r['inv_no'], 'si_no' => $r['si_no'], 'id' => (int)$r['id'], 'po' => $r['po_no'], 'due' => $r['due_date'], 'debit' => $r['kind'] === 'credit' ? 0.0 : (float)$r['gross'], 'credit' => $r['kind'] === 'credit' ? (float)$r['gross'] : 0.0, 'cur' => $r['currency'], 'status' => $r['status'], 'pf_no' => $r['proforma_no'] ?? null];
    foreach (all("SELECT p.*, i.inv_no FROM si_payments p JOIN si_invoices i ON i.id = p.invoice_id WHERE i.supplier_id = ? AND p.pay_date <= ?", [$supplierId, $to]) as $p) $rows[] = ['d' => $p['pay_date'], 'kind' => 'payment', 'ref' => $p['ref'] ?: t('si.pm_' . $p['method']), 'si_no' => null, 'id' => (int)$p['invoice_id'], 'po' => null, 'due' => null, 'debit' => 0.0, 'credit' => (float)$p['amount'], 'cur' => $p['currency'], 'status' => null, 'inv_no' => $p['inv_no']];
    usort($rows, fn($a, $b) => [$a['d'], $a['kind'] === 'payment' ? 1 : 0] <=> [$b['d'], $b['kind'] === 'payment' ? 1 : 0]);
    $bal = 0.0; $open = 0.0; $out = [];
    foreach ($rows as $r) { $bal += $r['debit'] - $r['credit']; $r['balance'] = $bal; if ($from && $r['d'] < $from) { $open = $bal; continue; } $out[] = $r; }
    return ['rows' => $out, 'opening' => $open, 'balance' => $bal, 'from' => $from, 'to' => $to];
}
/* ------------------------------------------------------------------ R97-118: proforma → the supplier's invoice · statement as PDF / e-mail */
/** The supplier's invoice arrived for a proforma: its number and date take the place of the proforma's, which is kept in proforma_no. Amounts, payments and the request stay. */
function si_final_from_post(): int {
    require_si(); $iid = (int)($_POST['id'] ?? 0); $i = si_load($iid); if (!$i || !si_pf_has() || !si_is_pf($i) || $i['status'] === 'cancelled') throw new RuntimeException(t('err.noaccess'));
    $no = mb_substr(trim((string)($_POST['inv_no'] ?? '')), 0, 80); if ($no === '') throw new RuntimeException(t('si.need_inv_no'));
    if (scalar('SELECT 1 FROM si_invoices WHERE supplier_id = ? AND inv_no = ? AND id <> ?', [(int)$i['supplier_id'], $no, $iid])) throw new RuntimeException(t('si.dup_inv_no'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['issue_date'] ?? '')) ? $_POST['issue_date'] : date('Y-m-d'); $u = current_user();
    $fileId = null; if (!empty($_FILES['file']['tmp_name']) && $i['project_id']) $fileId = cp_store_file($_FILES['file'], (int)$i['project_id'], $i['subproject_id'] ? (int)$i['subproject_id'] : null, 'file', 'internal', t('si.file_title', ['s' => $i['supplier'], 'n' => $no]));
    q('UPDATE si_invoices SET is_proforma = 2, proforma_no = ?, inv_no = ?, issue_date = ?, final_at = NOW(), final_name = ?' . ($fileId ? ', file_id = ' . (int)$fileId : '') . ' WHERE id = ?', [$i['inv_no'], $no, $date, $u['full_name'], $iid]);
    if (!$fileId && !empty($_FILES['file']['tmp_name'])) { try { si_doc_store($_FILES['file'], $iid, null, 'invoice', t('si.file_title', ['s' => $i['supplier'], 'n' => $no])); } catch (Throwable $e) { flash('bad', $e->getMessage()); } }   // R97-134
    si_sp_cost_sync($iid); si_event($iid, null, 'final', $i['inv_no'] . ' → ' . $no); cache_bump_later('eng'); return $iid;
}
/** Statement of a supplier as a document: number, rows, totals. */
function si_statement_doc(int $supplierId, ?string $from, ?string $to): ?array {
    if (!si_can()) return null; $S = sup_load($supplierId); if (!$S) return null; $ST = si_statement($supplierId, $from, $to);
    $deb = 0.0; $cre = 0.0; foreach ($ST['rows'] as $r) { $deb += $r['debit']; $cre += $r['credit']; }
    return ['S' => $S, 'ST' => $ST, 'cur' => ($S['sup_currency'] ?? '') ?: 'EUR', 'debit' => $deb, 'credit' => $cre, 'doc_no' => 'SUP-STMT-' . $supplierId . '-' . str_replace('-', '', $ST['to'])];
}
function si_statement_sheets(array $D): array {
    $ST = $D['ST']; $rows = []; if ($ST['from']) $rows[] = [$ST['from'], t('si.opening'), '', '', '', '', null, null, (float)$ST['opening']];
    foreach ($ST['rows'] as $r) $rows[] = [$r['d'], t('si.st_' . $r['kind']), $r['ref'], $r['si_no'] ?? ($r['inv_no'] ?? ''), $r['po'] ?? '', $r['due'] ?? '', $r['debit'] ?: null, $r['credit'] ?: null, (float)$r['balance']];
    $rows[] = ['', t('si.balance_due'), '', '', '', '', round($D['debit'], 2), round($D['credit'], 2), (float)$ST['balance']];
    return [['name' => 'Statement', 'first' => true, 'header' => [t('si.date'), t('si.kind'), t('si.ref'), t('si.no'), t('po.no'), t('si.due'), t('si.debit'), t('si.credit_col'), t('si.balance')], 'rows' => $rows]];
}
function si_statement_pdf(array $D): string {
    require_once __DIR__ . '/pdf.php';
    $S = $D['S']; $ST = $D['ST']; $cur = $D['cur']; $n = fn(float $v) => number_format($v, 2, ',', '.'); $m = fn(float $v) => $n($v) . ' ' . $cur;
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $green = '#2E7D32';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0;
    $bd = function_exists('br_doc') ? br_doc(null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $cols = [[t('si.date'), 19, 'L'], [t('si.kind'), 24, 'L'], [t('si.ref'), 39, 'L'], [t('po.no'), 26, 'L'], [t('si.due'), 19, 'L'], [t('si.debit'), 18, 'R'], [t('si.credit_col'), 17, 'R'], [t('si.balance'), 18, 'R']];
    $hrow = function () use ($pdf, $cols, $navy, $head, $L, &$y) { $pdf->setFont('B', 7.2); $pdf->setColor($head); $pdf->rect($L, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $L; foreach ($cols as [$lab, $cw, $al]) { $pdf->text($x + 1.5, $y + 4.2, $lab, $al, $cw - 3); $x += $cw; } $y += 6; };
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $D, $bd, $ST) {
        $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], $L, 10, 45, 16);
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($R, 18, mb_strtoupper(t('si.statement')), 'R');
        $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($R, 24, $D['doc_no'] . ' · ' . ($ST['from'] ? fmt_date($ST['from']) . ' – ' : '') . fmt_date($ST['to']), 'R');
        $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36;
    };
    $footer = function () use ($pdf, $L, $R, $D, $bd) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $D['doc_no']); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer, $hrow) { if ($y + $hh > 276) { $footer(); $header(); $hrow(); } };
    $header(); $footer();
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($L, $y, mb_strtoupper(t('pi.from'))); $pdf->text(110, $y, mb_strtoupper(t('po.supplier'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap((string)$ln0, 88) as $ln) { $pdf->text($L, $yl, $ln); $yl += 4; }
    $addr = array_values(array_filter([(string)$S['name'], (string)($S['address'] ?? ''), trim(($S['postal_code'] ?? '') . ' ' . ($S['city'] ?? '') . ' ' . ($S['country'] ?? '')), !empty($S['vat_no']) ? 'VAT ' . $S['vat_no'] : '']));
    $yr = $y; foreach ($addr as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    $y += $pdf->paragraph($L, $y, $W, t('si.st_intro', ['d' => fmt_date($ST['to'])]), 4.2) + 3;
    $hrow();
    if ($ST['from']) { $pdf->setFont('R', 7.6); $pdf->setColor($ink); $pdf->text($L + 1.5, $y + 3.8, fmt_date($ST['from'])); $pdf->text($L + 20.5, $y + 3.8, t('si.opening')); $pdf->text($R - 1.5, $y + 3.8, $n((float)$ST['opening']), 'R'); $y += 5.6; }
    if (!$ST['rows'] && !$ST['from']) { $pdf->setFont('R', 8.5); $pdf->setColor($ink); $pdf->text($L + 1.5, $y + 4, t('si.none')); $y += 8; }
    foreach ($ST['rows'] as $r) {
        $pdf->setFont('R', 7.4); $ref = (string)$r['ref'] . ($r['kind'] === 'payment' && !empty($r['inv_no']) ? ' · ' . $r['inv_no'] : '') . (!empty($r['pf_no']) ? ' (' . t('si.pf') . ' ' . $r['pf_no'] . ')' : '');
        $refL = $pdf->wrap($ref, 36); $poL = $pdf->wrap((string)($r['po'] ?? ''), 23); $hh = max(5.4, max(count($refL), count($poL)) * 3.6 + 1.8); $need($hh + 2);
        $pdf->setColor($r['kind'] === 'payment' ? $green : $ink); $x = $L;
        $cells = [fmt_date($r['d']), t('si.st_' . $r['kind']), $refL, $poL, $r['due'] ? fmt_date($r['due']) : '', $r['debit'] ? $n($r['debit']) : '', $r['credit'] ? $n($r['credit']) : '', $n((float)$r['balance'])];
        foreach ($cells as $k => $val) { if (is_array($val)) foreach ($val as $j => $ln) $pdf->text($x + 1.5, $y + 3.8 + $j * 3.6, $ln); else $pdf->text($x + 1.5, $y + 3.8, (string)$val, $cols[$k][2], $cols[$k][1] - 3); $x += $cols[$k][1]; }
        $y += $hh; $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($L, $y, $R, $y);
    }
    $need(16); $y += 2; $pdf->setFont('B', 8.4); $pdf->setColor($navy); $pdf->text($L + 1.5, $y + 4.5, t('ob.c_total'));
    $x = $L; foreach ($cols as $k => $c) { if ($k === 5) $pdf->text($x + 1.5, $y + 4.5, $n($D['debit']), 'R', $c[1] - 3); if ($k === 6) $pdf->text($x + 1.5, $y + 4.5, $n($D['credit']), 'R', $c[1] - 3); $x += $c[1]; } $y += 8;
    $pdf->setColor($gold); $pdf->setLineWidth(0.4); $pdf->line($L, $y, $R, $y); $y += 2;
    $pdf->setFont('B', 10); $pdf->setColor($navy); $pdf->text($L + 1.5, $y + 5, t('si.balance_due')); $pdf->text($R - 1.5, $y + 5, $m((float)$ST['balance']), 'R'); $y += 11;
    $need(18); $pdf->setFont('R', 8.5); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $W, t('si.st_closing'), 4.2) + 4;
    $u = current_user(); $pdf->setFont('R', 8); $pdf->setColor('#6a7179'); $pdf->text($L, $y + 3, t('ob.prepared_by') . ': ' . (string)($u['full_name'] ?? '') . ' · ' . $bd['name'] . ' · ' . fmt_date(date('Y-m-d')));
    return $pdf->bytes($D['doc_no']);
}
/** E-mail attachment key  ss:<supplier id>:<from or ->:<to>:pdf|xlsx */
function si_em_attachment(array $p): ?array {
    $d = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$v) ? (string)$v : null;
    $D = si_statement_doc((int)($p[1] ?? 0), $d($p[2] ?? ''), $d($p[3] ?? '')); if (!$D) return null;
    if (($p[4] ?? 'pdf') === 'xlsx') { require_once __DIR__ . '/xlsxw.php'; return [xlsx_bytes(si_statement_sheets($D)), $D['doc_no'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']; }
    return [si_statement_pdf($D), $D['doc_no'] . '.pdf', 'application/pdf'];
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
function si_po_open_lines(int $oid, bool $ordered = false): array { return all('SELECT l.*, ' . ($ordered ? 'l.qty' : 'l.qty_accepted') . " - l.qty_invoiced AS open_qty FROM po_lines l WHERE l.order_id = ? AND l.status <> 'cancelled' ORDER BY l.seq", [$oid]); }

<?php
/**
 * Release D2 (GAS-CP-R44): budgetary offers and AACE 18R-97 classes, payment terms, proforma invoices (PI),
 * accounting invoice links and the financial status of a project.
 *
 *  R44-001  offers.kind (firm | budgetary) + offers.class_id (Settings › Offer classes, AACE 18R-97 classes 5…1);
 *           a budgetary offer lives on the main project (no sub-project) and is visible to owners like any offer;
 *           "Convert to firm" copies it (lines, texts) to a firm offer on a chosen sub-project and links both.
 *  R44-002  offer_terms: the payment terms of an offer (n, % or amount, trigger, due days) — the PI slots once approved;
 *           a default scheme per branch (Settings › Offer classes › Payment terms).
 *  R44-003  proformas: issued by hand by Sales users from a slot: number PRJ….PI.<id>.n, invoicing identity of the
 *           sub-project / vessel, VAT from it, PDF generated (or the ERP's PDF uploaded), e-mail card, statuses
 *           draft → issued → sent → partly_paid → paid (cancelled); proforma_invoices: the accounting invoices that
 *           settle a PI (number, date, amount, paid) — the PI's status follows what is paid.
 *  R44-004  financial status: offered / invoiced / collected / outstanding / overdue per sub-project and project,
 *           on the project tree, the sub-project head and Commercial › Proformas.
 */
declare(strict_types=1);

const PI_STATUSES = ['draft', 'issued', 'sent', 'partly_paid', 'paid', 'cancelled'];
const PI_TONE = ['draft' => 'mute', 'issued' => 'navy', 'sent' => 'gold', 'partly_paid' => 'blue', 'paid' => 'green', 'cancelled' => 'bad'];
const OF_KINDS = ['firm', 'budgetary'];

function pi_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'proformas'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Sales permission: issue / edit proformas and payment terms (administrators always). Accounting users may link invoices. */
function pi_is_sales(): bool { static $v = null; if ($v === null) { $u = current_user(); $v = is_cp_admin() || (is_staff() && pi_has() && (bool) scalar('SELECT can_sales FROM customer_users WHERE id = ?', [(int)($u['id'] ?? 0)])); } return $v; }
function pi_can_view(): bool { return is_staff() && pi_has() && fin_visible(); }
function pi_can_invoices(): bool { return pi_is_sales() || (function_exists('acc_is_accounting') && acc_is_accounting()); }
function pi_money(float $v, string $cur = 'EUR'): string { if (!fin_visible()) return fin_masked(); return number_format($v, 2, ',', '.') . ' ' . $cur; }
function pi_status_pill(string $s): string { return pill(PI_TONE[$s] ?? 'mute', t('pi.s_' . $s)); }

/* ------------------------------------------------------------------ R44-001 classes and kinds */
function oc_all(bool $activeOnly = false): array { static $c = null; if ($c === null) { try { $c = pi_has() ? all('SELECT * FROM offer_classes ORDER BY sort_order, code') : []; } catch (Throwable $e) { $c = []; } } return $activeOnly ? array_values(array_filter($c, fn($r) => (int)$r['is_active'])) : $c; }
function oc_get(?int $id): ?array { if (!$id) return null; foreach (oc_all() as $r) if ((int)$r['id'] === $id) return $r; return null; }
function oc_pill(array $o): string {
    $k = (string)($o['kind'] ?? 'firm'); $c = oc_get((int)($o['class_id'] ?? 0));
    $h = $k === 'budgetary' ? pill('gold', t('oc.budgetary')) : '';
    if ($c) $h .= ' <span class="pill pill--mute" title="' . e(trim(($c['definition'] ? t('oc.definition') . ' ' . $c['definition'] . ' · ' : '') . ($c['accuracy'] ?: ''))) . '">' . e(t('oc.class_short', ['c' => $c['code']])) . '</span>';
    return $h;
}
function oc_save_from_post(): void {
    require_cp_admin(); $id = (int)($_POST['id'] ?? 0);
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $code = $s('code', 12); $name = $s('name', 80); if ($code === '' || $name === '') throw new RuntimeException(t('oc.need_fields'));
    $v = [$code, $name, $s('definition', 40) ?: null, $s('accuracy', 80) ?: null, $s('purpose', 120) ?: null, (int)!empty($_POST['is_firm']), (int)($_POST['sort_order'] ?? 0), (int)!empty($_POST['is_active'])];
    if ($id) q('UPDATE offer_classes SET code=?, name=?, definition=?, accuracy=?, purpose=?, is_firm=?, sort_order=?, is_active=? WHERE id=?', [...$v, $id]);
    else q('INSERT INTO offer_classes (code, name, definition, accuracy, purpose, is_firm, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)', $v);
}
function oc_delete(int $id): void { require_cp_admin(); if (scalar('SELECT COUNT(*) FROM offers WHERE class_id = ?', [$id])) throw new RuntimeException(t('oc.in_use')); q('DELETE FROM offer_classes WHERE id = ?', [$id]); }
/** Save kind / class from the offer form (called by of_save after the insert / update). */
function oc_apply_from_post(int $offerId): void {
    if (!pi_has() || !array_key_exists('kind', $_POST)) return;
    $kind = in_array($_POST['kind'] ?? '', OF_KINDS, true) ? $_POST['kind'] : 'firm';
    $cid = (int)($_POST['class_id'] ?? 0) ?: null; if ($cid && !oc_get($cid)) $cid = null;
    if ($kind === 'budgetary' && !$cid) { foreach (oc_all(true) as $c) if (!(int)$c['is_firm']) { $cid = (int)$c['id']; break; } }
    q('UPDATE offers SET kind = ?, class_id = ? WHERE id = ?', [$kind, $cid, $offerId]);
}
/** Convert an approved budgetary offer into a firm offer on a sub-project: copies header, lines and texts; links both. */
function oc_convert(int $offerId, int $sid): int {
    $o = of_load($offerId); if (!$o || !pi_is_sales()) throw new RuntimeException(t('err.noaccess'));
    if (($o['kind'] ?? 'firm') !== 'budgetary' || $o['converted_to']) throw new RuntimeException(t('oc.not_convertible'));
    $sub = one('SELECT * FROM subprojects WHERE id = ? AND project_id = ?', [$sid, (int)$o['project_id']]); if (!$sub) throw new RuntimeException(t('err.notfound'));
    $u = current_user(); $no = function_exists('br_offer_no') ? br_offer_no((int)$o['project_id'], $sid) : ''; if ($no === '') $no = $o['offer_no'] . '-F';
    $firmClass = null; foreach (oc_all(true) as $c) if ((int)$c['is_firm']) { $firmClass = (int)$c['id']; break; }
    $ob = function_exists('ob_has') && ob_has();
    q('INSERT INTO offers (project_id, subproject_id, offer_no, revision, title, amount, currency, status, sent_at, valid_until, status_at, notes, created_by, kind, class_id, converted_from' . ($ob ? ', vessel_id, ticket_id, layout, vat_pct, validity_days, rate_list_id' : '') . ') VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?' . ($ob ? ',?,?,?,?,?,?' : '') . ')',
      array_merge([(int)$o['project_id'], $sid, $no, 'R0', $o['title'], (float)$o['amount'], $o['currency'], $ob ? 'draft' : 'sent', date('Y-m-d'), null, date('Y-m-d'), $o['notes'], (int)$u['id'], 'firm', $firmClass, $offerId], $ob ? [$o['vessel_id'], $o['ticket_id'], $o['layout'], $o['vat_pct'], $o['validity_days'], $o['rate_list_id']] : []));
    $nid = (int) db()->lastInsertId();
    if ($ob) {
        foreach (ob_lines($offerId) as $l) q('INSERT INTO offer_lines (offer_id, seq, kind, rate_item_id, erp_code, description, unit, zone, qty, unit_price, discount_pct, line_total, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [$nid, $l['seq'], $l['kind'], $l['rate_item_id'], $l['erp_code'], $l['description'], $l['unit'], $l['zone'], $l['qty'], $l['unit_price'], $l['discount_pct'], $l['line_total'], $l['notes']]);
        try { foreach (all('SELECT * FROM offer_blocks WHERE offer_id = ?', [$offerId]) as $b) q('INSERT INTO offer_blocks (offer_id, block_key, seq, title, body) VALUES (?,?,?,?,?)', [$nid, $b['block_key'], $b['seq'], $b['title'], $b['body']]); } catch (Throwable $e) {}
        try { ob_recalc($nid); } catch (Throwable $e) {}
    }
    foreach (pt_of($offerId) as $tm) q('INSERT INTO offer_terms (offer_id, n, pct, amount, trigger_text, due_days) VALUES (?,?,?,?,?,?)', [$nid, $tm['n'], $tm['pct'], $tm['amount'], $tm['trigger_text'], $tm['due_days']]);
    if (function_exists('num_sq') && num_has() && ($sq = num_sq((int)$o['project_id'], $sid))) q('UPDATE offers SET sq_no = ? WHERE id = ?', [$sq, $nid]);
    q('UPDATE offers SET converted_to = ? WHERE id = ?', [$nid, $offerId]);
    of_event($offerId, 'converted', $no); of_event($nid, 'draft', t('oc.from_budgetary', ['no' => $o['offer_no']]));
    return $nid;
}

/* ------------------------------------------------------------------ R44-002 payment terms */
function pt_of(int $offerId): array { return pi_has() ? all('SELECT * FROM offer_terms WHERE offer_id = ? ORDER BY n', [$offerId]) : []; }
/** Default scheme per branch: "30|with order|0;40|before FAT|0;30|on delivery|30" (Settings). */
function pt_default(?int $branchId): array {
    $raw = function_exists('cp_setting') ? (string)(cp_setting('pt_default_' . (int)$branchId, '') ?: cp_setting('pt_default_0', '')) : '';
    if ($raw === '') $raw = '30|' . t('pt.d_order') . '|0;40|' . t('pt.d_fat') . '|0;30|' . t('pt.d_delivery') . '|30';
    $out = []; $n = 0; foreach (explode(';', $raw) as $part) { $p = array_map('trim', explode('|', $part)); if (count($p) < 2) continue; $n++; $out[] = ['n' => $n, 'pct' => (float)$p[0], 'amount' => null, 'trigger_text' => $p[1], 'due_days' => isset($p[2]) ? (int)$p[2] : null]; }
    return $out;
}
function pt_default_save_from_post(): void {
    require_cp_admin(); $bid = (int)($_POST['branch_id'] ?? 0);
    $rows = []; foreach ((array)($_POST['pct'] ?? []) as $i => $pct) { $tr = trim((string)($_POST['trigger'][$i] ?? '')); if ($tr === '' && trim((string)$pct) === '') continue; $rows[] = (float) str_replace(',', '.', (string)$pct) . '|' . str_replace(['|', ';'], ' ', mb_substr($tr, 0, 160)) . '|' . (int)($_POST['due'][$i] ?? 0); }
    cp_setting_set('pt_default_' . $bid, $rows ? implode(';', $rows) : null);
}
/** Amount of a term for an offer (pct of the net amount, or the fixed amount). */
function pt_amount(array $t, array $o): float { return $t['amount'] !== null ? (float)$t['amount'] : round((float)$o['amount'] * (float)$t['pct'] / 100, 2); }
function pt_save_from_post(int $offerId): void {
    $o = of_load($offerId); if (!$o || !pi_is_sales()) throw new RuntimeException(t('err.noaccess'));
    $rows = []; $n = 0; $sum = 0.0;
    foreach ((array)($_POST['pct'] ?? []) as $i => $pct) {
        $tr = mb_substr(trim((string)($_POST['trigger'][$i] ?? '')), 0, 160); $pv = trim((string)$pct); $av = trim((string)($_POST['amount'][$i] ?? ''));
        if ($tr === '' && $pv === '' && $av === '') continue; $n++;
        $p = $pv === '' ? null : max(0, min(100, (float) str_replace(',', '.', $pv))); $a = $av === '' ? null : (function_exists('of_parse_amount') ? of_parse_amount($av) : (float) str_replace(',', '.', $av));
        if ($p === null && $a === null) $p = 0.0; $sum += $p ?? 0;
        $rows[] = [$n, $p, $a, $tr ?: t('pt.instalment', ['n' => $n]), trim((string)($_POST['due'][$i] ?? '')) === '' ? null : (int)$_POST['due'][$i]];
    }
    q('DELETE FROM offer_terms WHERE offer_id = ? AND n NOT IN (SELECT term_n FROM proformas WHERE offer_id = ? AND term_n IS NOT NULL AND status <> "cancelled")', [$offerId, $offerId]);
    foreach ($rows as $r) q('INSERT INTO offer_terms (offer_id, n, pct, amount, trigger_text, due_days) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE pct = VALUES(pct), amount = VALUES(amount), trigger_text = VALUES(trigger_text), due_days = VALUES(due_days)', [$offerId, ...$r]);
    of_event($offerId, 'terms', count($rows) . ' × · ' . rtrim(rtrim(number_format($sum, 2, '.', ''), '0'), '.') . ' %');
}
/** Seed the default scheme on an offer that has none (first view of the Proformas card by a sales user). */
function pt_seed(int $offerId, array $o): void { if (pt_of($offerId)) return; $b = function_exists('br_of') ? br_of((int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null) : []; foreach (pt_default((int)($b['id'] ?? 0)) as $t) q('INSERT IGNORE INTO offer_terms (offer_id, n, pct, amount, trigger_text, due_days) VALUES (?,?,?,?,?,?)', [$offerId, $t['n'], $t['pct'], null, $t['trigger_text'], $t['due_days']]); }

/* ------------------------------------------------------------------ R44-003 proformas */
const PI_SELECT = "SELECT pi.*, o.offer_no, o.revision, o.sq_no, o.title AS offer_title, o.status AS offer_status, p.project_code, p.name AS project_name, p.pm_user_id, s.code AS sub_code, s.name AS sub_name, v.name AS vessel, c.name AS customer_name, p.customer_id,
                   iv.label AS iv_label, iv.legal_name AS iv_legal, f.original_name, cu.full_name AS created_name,
                   (SELECT COALESCE(SUM(x.paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id) AS paid_sum, (SELECT COUNT(*) FROM proforma_invoices x WHERE x.proforma_id = pi.id) AS n_inv
            FROM proformas pi JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN subprojects s ON s.id = pi.subproject_id LEFT JOIN vessels v ON v.id = pi.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
            LEFT JOIN invoicing_details iv ON iv.id = pi.invoicing_id LEFT JOIN sp_files f ON f.id = pi.file_id LEFT JOIN customer_users cu ON cu.id = pi.created_by";
function pi_get(int $id): ?array {
    if (!pi_can_view() || !$id) return null;
    [$sc, $sa] = scope_sql('p'); $r = one(PI_SELECT . " WHERE pi.id = ? AND {$sc}", array_merge([$id], $sa)); if (!$r) return null;
    $r['invoices'] = all('SELECT x.*, cu.full_name AS by_name FROM proforma_invoices x LEFT JOIN customer_users cu ON cu.id = x.created_by WHERE x.proforma_id = ? ORDER BY x.invoice_date, x.id', [$id]);
    $r['iv'] = $r['invoicing_id'] ? iv_load((int)$r['invoicing_id']) : null;
    return $r;
}
function pi_of_offer(int $offerId): array { return pi_has() && fin_visible() ? all(PI_SELECT . ' WHERE pi.offer_id = ? ORDER BY pi.term_n, pi.id', [$offerId]) : []; }
/** $f: status (open|overdue|<status>|all), vessel, project, pm, q */
function pi_list(array $f = []): array {
    if (!pi_can_view()) return [];
    $w = []; $a = []; $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $w[] = "pi.status IN ('draft','issued','sent','partly_paid')"; elseif ($st === 'overdue') $w[] = "pi.status IN ('issued','sent','partly_paid') AND pi.due_date < CURDATE()"; elseif (in_array($st, PI_STATUSES, true)) { $w[] = 'pi.status = ?'; $a[] = $st; }
    foreach (['vessel' => 'pi.vessel_id', 'project' => 'pi.project_id', 'pm' => 'p.pm_user_id', 'subproject' => 'pi.subproject_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $a[] = (int)$f[$k]; }
    if (!empty($f['q'])) { $w[] = '(pi.pi_no LIKE ? OR pi.title LIKE ? OR o.offer_no LIKE ? OR o.sq_no LIKE ? OR p.project_code LIKE ? OR v.name LIKE ? OR EXISTS (SELECT 1 FROM proforma_invoices x WHERE x.proforma_id = pi.id AND x.invoice_no LIKE ?))'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l, $l, $l, $l); }
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    return all(PI_SELECT . ' WHERE ' . implode(' AND ', $w) . " ORDER BY FIELD(pi.status, 'issued','sent','partly_paid','draft','paid','cancelled'), pi.due_date IS NULL, pi.due_date, pi.id DESC", $a);
}
function pi_overdue(array $r): bool { return in_array($r['status'], ['issued', 'sent', 'partly_paid'], true) && $r['due_date'] && $r['due_date'] < date('Y-m-d'); }
/** Create a draft PI from a term slot (or free) — number given at once. */
function pi_create(int $offerId, ?int $termN): int {
    $o = of_load($offerId); if (!$o || !pi_is_sales()) throw new RuntimeException(t('err.noaccess'));
    if (of_status_behaves($o['status']) !== 'approved' && !in_array($o['status'], ['finished', 'to_be_invoiced', 'in_progress', 'approved'], true)) throw new RuntimeException(t('pi.need_approved'));
    if ($termN && scalar("SELECT COUNT(*) FROM proformas WHERE offer_id = ? AND term_n = ? AND status <> 'cancelled'", [$offerId, $termN])) throw new RuntimeException(t('pi.slot_taken'));
    $terms = pt_of($offerId); $t = null; foreach ($terms as $x) if ((int)$x['n'] === $termN) $t = $x;
    $n = $termN ?: ((int) scalar("SELECT COALESCE(MAX(term_n),0) FROM proformas WHERE offer_id = ?", [$offerId]) + 1);
    $sid = $o['subproject_id'] ? (int)$o['subproject_id'] : null; $pid = (int)$o['project_id'];
    $no = function_exists('num_pi') ? num_pi($pid, $sid, $n) : null; if (!$no) $no = ($o['sq_no'] ?: $o['offer_no']) . '.PI.' . date('ymdHis') . '.' . $n;
    $sp = $sid ? load_subproject($sid) : null; $iv = $sp && function_exists('iv_for_sub') ? iv_for_sub($sp) : (function_exists('iv_vessel_has') && iv_vessel_has() && $o['vessel_id'] ? (iv_default('vessel', (int)$o['vessel_id']) ?: ($o['customer_id'] ? iv_default('customer', (int)$o['customer_id']) : null)) : null);
    $net = $t ? pt_amount($t, $o) : (float)$o['amount']; $vat = $iv && $iv['vat_pct'] !== null ? (float)$iv['vat_pct'] : (float)($o['vat_pct'] ?? 0);
    $due = $t && $t['due_days'] !== null ? date('Y-m-d', strtotime('+' . (int)$t['due_days'] . ' days')) : null;
    $title = $t ? $t['trigger_text'] : $o['title'];
    q('INSERT INTO proformas (pi_no, offer_id, term_n, project_id, subproject_id, vessel_id, invoicing_id, title, issue_date, due_date, amount_net, vat_pct, vat_amount, gross, currency, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$no, $offerId, $n, $pid, $sid, $o['vessel_id'] ?: null, $iv ? (int)$iv['id'] : null, mb_substr((string)$title, 0, 200), date('Y-m-d'), $due, $net, $vat, round($net * $vat / 100, 2), round($net * (1 + $vat / 100), 2), $o['currency'] ?: 'EUR', 'draft', (int)(current_user()['id'] ?? 0)]);
    $id = (int) db()->lastInsertId(); of_event($offerId, 'proforma', $no);
    return $id;
}
function pi_save_from_post(int $id): void {
    $r = pi_get($id); if (!$r || !pi_is_sales()) throw new RuntimeException(t('err.noaccess'));
    if (in_array($r['status'], ['paid', 'cancelled'], true)) throw new RuntimeException(t('pi.locked'));
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $net = function_exists('of_parse_amount') ? of_parse_amount((string)($_POST['amount_net'] ?? '0')) : (float) str_replace(',', '.', (string)($_POST['amount_net'] ?? '0'));
    $vat = max(0, min(100, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? '0'))));
    $iv = (int)($_POST['invoicing_id'] ?? 0) ?: null; if ($iv && !iv_load($iv)) $iv = null;
    $cur = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['currency'] ?? $r['currency'])), 0, 3)) ?: 'EUR';
    $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'other', 'internal', $r['pi_no'], null, null, ['doc_no' => $r['pi_no'], 'label' => $r['pi_no']]) : null;
    q('UPDATE proformas SET title=?, issue_date=?, due_date=?, amount_net=?, vat_pct=?, vat_amount=?, gross=?, currency=?, invoicing_id=?, notes=?' . ($fileId ? ', file_id=?' : '') . ' WHERE id=?',
      array_merge([$s('title', 200) ?: $r['title'], $d('issue_date') ?: $r['issue_date'], $d('due_date'), $net, $vat, round($net * $vat / 100, 2), round($net * (1 + $vat / 100), 2), $cur, $iv, $s('notes', 4000) ?: null], $fileId ? [$fileId] : [], [$id]));
    pi_refresh_status($id);
}
/** Issue: status issued, PDF generated and stored unless an uploaded file exists. */
function pi_issue(int $id): void {
    $r = pi_get($id); if (!$r || !pi_is_sales()) throw new RuntimeException(t('err.noaccess')); if ($r['status'] !== 'draft') return;
    if (!$r['file_id']) { $bytes = pi_render_pdf($r); $fid = cp_store_bytes($bytes, $r['pi_no'] . '.pdf', 'application/pdf', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'other', 'external', null, $r['pi_no'], ['doc_no' => $r['pi_no'], 'label' => $r['pi_no']]); q('UPDATE proformas SET file_id = ? WHERE id = ?', [$fid, $id]); }
    q("UPDATE proformas SET status = 'issued', issue_date = COALESCE(issue_date, CURDATE()) WHERE id = ?", [$id]);
    of_event((int)$r['offer_id'], 'proforma_issued', $r['pi_no']); pi_notify($r, 'issued');
}
function pi_set_status(int $id, string $st): void {
    $r = pi_get($id); if (!$r || !pi_is_sales() || !in_array($st, PI_STATUSES, true)) throw new RuntimeException(t('err.noaccess'));
    if ($st === 'issued') { pi_issue($id); return; }
    if ($st === 'draft' && $r['status'] !== 'issued') throw new RuntimeException(t('pi.locked'));
    q('UPDATE proformas SET status = ?, sent_at = CASE WHEN ? = "sent" THEN COALESCE(sent_at, NOW()) ELSE sent_at END, paid_at = CASE WHEN ? = "paid" THEN COALESCE(paid_at, CURDATE()) ELSE paid_at END WHERE id = ?', [$st, $st, $st, $id]);
    of_event((int)$r['offer_id'], 'proforma_' . $st, $r['pi_no']);
    if (in_array($st, ['paid', 'cancelled'], true)) pi_notify($r, $st);
}
/** Mark sent when the e-mail card is used (hidden field proforma=<id>). */
function pi_mark_sent(int $id): void { $r = pi_get($id); if ($r && in_array($r['status'], ['issued'], true)) { q("UPDATE proformas SET status = 'sent', sent_at = NOW() WHERE id = ?", [$id]); of_event((int)$r['offer_id'], 'proforma_sent', $r['pi_no']); } }
/** Status from what is paid: paid when Σ paid ≥ gross, partly_paid when > 0, else the issued/sent state. */
function pi_refresh_status(int $id): void {
    $r = one("SELECT pi.*, (SELECT COALESCE(SUM(paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id) AS paid_sum FROM proformas pi WHERE pi.id = ?", [$id]); if (!$r || in_array($r['status'], ['draft', 'cancelled'], true)) return;
    $paid = (float)$r['paid_sum']; $gross = (float)$r['gross'];
    $st = $paid > 0 && $paid + 0.005 >= $gross ? 'paid' : ($paid > 0 ? 'partly_paid' : ($r['sent_at'] ? 'sent' : 'issued'));
    if ($st !== $r['status']) { q('UPDATE proformas SET status = ?, paid_at = CASE WHEN ? = "paid" THEN COALESCE(paid_at, CURDATE()) ELSE NULL END WHERE id = ?', [$st, $st, $id]); if ($st === 'paid') { $rr = pi_get($id); if ($rr) pi_notify($rr, 'paid'); } }
}
function pinv_save_from_post(int $piId): void {
    $r = pi_get($piId); if (!$r || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['inv_id'] ?? 0); $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $no = $s('invoice_no', 60); if ($no === '') throw new RuntimeException(t('pi.need_inv_no'));
    $amt = function_exists('of_parse_amount') ? of_parse_amount((string)($_POST['inv_amount'] ?? '0')) : (float)($_POST['inv_amount'] ?? 0);
    $paidIn = trim((string)($_POST['paid_amount'] ?? '')); $paid = $paidIn === '' ? ($d('paid_at') ? $amt : 0.0) : (function_exists('of_parse_amount') ? of_parse_amount($paidIn) : (float)$paidIn);
    $v = [$no, $d('invoice_date'), $amt, $paid, $d('paid_at') ?: ($paid > 0 ? date('Y-m-d') : null), $s('inv_note', 255) ?: null];
    if ($id) q('UPDATE proforma_invoices SET invoice_no=?, invoice_date=?, amount=?, paid_amount=?, paid_at=?, note=? WHERE id=? AND proforma_id=?', [...$v, $id, $piId]);
    else q('INSERT INTO proforma_invoices (proforma_id, invoice_no, invoice_date, amount, paid_amount, paid_at, note, created_by) VALUES (?,?,?,?,?,?,?,?)', [$piId, ...$v, (int)(current_user()['id'] ?? 0)]);
    of_event((int)$r['offer_id'], 'invoice', $no . ' · ' . pi_money($amt, $r['currency']) . ($paid > 0 ? ' · ' . t('pi.paid_lbl') . ' ' . pi_money($paid, $r['currency']) : ''));
    pi_refresh_status($piId);
}
function pinv_delete(int $invId): int { $x = one('SELECT * FROM proforma_invoices WHERE id = ?', [$invId]); if (!$x || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM proforma_invoices WHERE id = ?', [$invId]); pi_refresh_status((int)$x['proforma_id']); return (int)$x['proforma_id']; }
function pi_delete(int $id): int { require_cp_admin(); $r = pi_get($id); if (!$r) throw new RuntimeException(t('err.notfound')); q('DELETE FROM proforma_invoices WHERE proforma_id = ?', [$id]); q('DELETE FROM proformas WHERE id = ?', [$id]); return (int)$r['offer_id']; }
function pi_notify(array $r, string $kind): void {
    if (!function_exists('nf_push')) return; $me = (int)(current_user()['id'] ?? 0);
    $ids = array_values(array_filter(array_unique([(int)($r['pm_user_id'] ?? 0), (int)($r['created_by'] ?? 0)]), fn($i) => $i && $i !== $me));
    if ($ids) nf_push($ids, 'pi:' . $kind, 'pi:' . $r['id'], $r['pi_no'] . ' · ' . $r['title'], pi_money((float)$r['gross'], $r['currency']), url('proforma', ['id' => (int)$r['id']]), $me ?: null, current_user()['full_name'] ?? null);
}

/* ------------------------------------------------------------------ R44-004 financial status */
/** Totals for a project or a sub-project: offered (approved offers, net), invoiced (PIs issued+, gross), collected (paid), outstanding, overdue. */
function fs_totals(int $pid, ?int $sid = null): array {
    $z = ['offered' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0, 'n_pi' => 0, 'n_overdue' => 0, 'cur' => 'EUR'];
    if (!pi_has() || !fin_visible()) return $z;
    $w = $sid ? 'o.subproject_id = ?' : 'o.project_id = ?'; $arg = [$sid ?: $pid];
    $defs = of_status_defs(); $appr = array_keys(array_filter($defs, fn($d) => in_array($d['behaves'], ['approved', 'closed_won'], true))); if (!$appr) $appr = ['approved'];
    $z['offered'] = (float) scalar("SELECT COALESCE(SUM(o.amount),0) FROM offers o WHERE {$w} AND o.status IN (" . implode(',', array_fill(0, count($appr), '?')) . ") AND (o.converted_to IS NULL)", array_merge($arg, $appr));
    $pw = $sid ? 'pi.subproject_id = ?' : 'pi.project_id = ?';
    $r = one("SELECT COUNT(*) n, COALESCE(SUM(pi.gross),0) g, COALESCE(SUM(LEAST(pi.gross, (SELECT COALESCE(SUM(x.paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id))),0) paid,
                     SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') AND pi.due_date < CURDATE() THEN 1 ELSE 0 END) n_od,
                     COALESCE(SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') AND pi.due_date < CURDATE() THEN pi.gross - (SELECT COALESCE(SUM(x.paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id) ELSE 0 END),0) od,
                     MAX(pi.currency) cur
              FROM proformas pi WHERE {$pw} AND pi.status IN ('issued','sent','partly_paid','paid')", $arg);
    $z['invoiced'] = (float)$r['g']; $z['collected'] = (float)$r['paid']; $z['outstanding'] = max(0, (float)$r['g'] - (float)$r['paid']); $z['overdue'] = (float)$r['od']; $z['n_pi'] = (int)$r['n']; $z['n_overdue'] = (int)$r['n_od']; if ($r['cur']) $z['cur'] = $r['cur'];
    return $z;
}
/** Compact pills: offered · invoiced · collected (· overdue). Empty when nothing. */
function fs_pills(int $pid, ?int $sid = null): string {
    $t = fs_totals($pid, $sid); if (!$t['offered'] && !$t['invoiced']) return '';
    $h = '<span class="fs-pills" title="' . e(t('fs.title')) . '">';
    $h .= '<span class="pill pill--mute">' . e(t('fs.offered')) . ' ' . e(pi_money($t['offered'], $t['cur'])) . '</span>';
    if ($t['invoiced'] > 0) $h .= ' <span class="pill pill--navy">' . e(t('fs.invoiced')) . ' ' . e(pi_money($t['invoiced'], $t['cur'])) . '</span> <span class="pill pill--green">' . e(t('fs.collected')) . ' ' . e(pi_money($t['collected'], $t['cur'])) . '</span>';
    if ($t['overdue'] > 0) $h .= ' <span class="pill pill--bad">' . e(t('fs.overdue')) . ' ' . e(pi_money($t['overdue'], $t['cur'])) . '</span>';
    return $h . '</span>';
}

/* ------------------------------------------------------------------ PI document (PDF) */
function pi_render_pdf(array $r): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $bd = br_doc((int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $L = 15.0; $R = 195.0; $W = $R - $L; $cur = (string)$r['currency'];
    $m = fn(float $v) => number_format($v, 2, ',', '.') . ' ' . $cur;
    $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16);
    $pdf->setColor($navy); $pdf->setFont('B', 16); $pdf->text($L, 20, mb_strtoupper(t('pi.doc_title')));
    $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $r['pi_no'] . ' · ' . fmt_date($r['issue_date'] ?: date('Y-m-d')));
    $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36.0;
    // issuer / bill to
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($L, $y, mb_strtoupper(t('pi.from'))); $pdf->text(110, $y, mb_strtoupper(t('pi.bill_to'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap($ln0, 88) as $ln) { $pdf->text($L, $yl, $ln); $yl += 4; }
    $iv = $r['iv'] ?? null; $yr = $y; $bill = $iv ? array_filter([(string)$iv['legal_name'], (string)($iv['address'] ?? ''), trim((string)($iv['postal_code'] ?? '') . ' ' . (string)($iv['city'] ?? '') . ' ' . (string)($iv['country'] ?? '')), $iv['vat_no'] ? 'VAT ' . $iv['vat_no'] : '', $iv['attn'] ? t('iv.attn') . ' ' . $iv['attn'] : '', (string)($iv['email'] ?? '')]) : [(string)($r['customer_name'] ?: '—')];
    foreach ($bill as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    $kv = [[t('pr.one'), trim(($r['project_code'] ?: '') . ' ' . $r['project_name']) . ($r['sub_code'] ? ' › ' . $r['sub_code'] . ' ' . $r['sub_name'] : '')], [t('v.one'), (string)($r['vessel'] ?: '—')], [t('of.one'), $r['offer_no'] . ' ' . $r['revision'] . ($r['sq_no'] ? ' · SQ ' . $r['sq_no'] : '')], [t('pi.due'), $r['due_date'] ? fmt_date($r['due_date']) : '—'], [t('pi.terms_ref'), $r['term_n'] ? t('pt.instalment', ['n' => (int)$r['term_n']]) : '—']];
    $pdf->setFont('R', 8.5); foreach ($kv as [$k, $v]) { $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y, $k); $pdf->setColor($ink); $pdf->setFont('R', 8.5); foreach ($pdf->wrap($v, $W - 40) as $i => $ln) $pdf->text($L + 40, $y + $i * 4.2, $ln); $y += 4.6 + (count($pdf->wrap($v, $W - 40)) - 1) * 4.2; }
    $y += 4;
    $cols = [[t('pi.description'), 110, 'L'], [t('pi.net'), 25, 'R'], [t('pi.vat'), 20, 'R'], [t('pi.gross'), 25, 'R']]; $x = $L; $pdf->setColor($head); $pdf->rect($L, $y, 180, 7, 'F'); $pdf->setFont('B', 8); $pdf->setColor($navy);
    foreach ($cols as [$lab, $cw, $al]) { $pdf->text($x + 1.5, $y + 4.8, $lab, $al, $cw - 3); $x += $cw; } $y += 7;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $lines = $pdf->wrap((string)$r['title'] . ($r['notes'] ? "\n" . rt_plain((string)$r['notes']) : ''), 105); $hh = max(8, count($lines) * 4.2 + 3);
    $x = $L; $pdf->setColor($line); foreach ($cols as [$lab, $cw, $al]) { $pdf->rect($x, $y, $cw, $hh); $x += $cw; } $pdf->setColor($ink);
    foreach ($lines as $i => $ln) $pdf->text($L + 1.5, $y + 4.5 + $i * 4.2, $ln);
    $pdf->text($L + 110 + 1.5, $y + 4.5, $m((float)$r['amount_net']), 'R', 22); $pdf->text($L + 135 + 1.5, $y + 4.5, rtrim(rtrim(number_format((float)$r['vat_pct'], 2, '.', ''), '0'), '.') . ' %', 'R', 17); $pdf->text($L + 155 + 1.5, $y + 4.5, $m((float)$r['gross']), 'R', 22);
    $y += $hh + 2;
    $pdf->setFont('B', 9.5); $pdf->setColor($navy); $pdf->text($L + 110, $y + 5, t('pi.total'), 'L', 40); $pdf->text($L + 155 + 1.5, $y + 5, $m((float)$r['gross']), 'R', 22); $y += 10;
    $pdf->setFont('R', 8); $pdf->setColor($ink); $pdf->text($L, $y, t('pi.vat_line', ['p' => rtrim(rtrim(number_format((float)$r['vat_pct'], 2, '.', ''), '0'), '.'), 'a' => $m((float)$r['vat_amount'])])); $y += 8;
    if ($iv && ($iv['bank'] || $iv['iban'])) { $pdf->setFont('B', 8.5); $pdf->setColor($navy); $pdf->text($L, $y, t('pi.payment_to')); $y += 4.5; $pdf->setFont('R', 8.5); $pdf->setColor($ink); foreach (array_filter([(string)$iv['bank'], $iv['iban'] ? 'IBAN ' . $iv['iban'] : '', $iv['swift'] ? 'SWIFT ' . $iv['swift'] : '', (string)($iv['payment_terms'] ?? '')]) as $ln) { $pdf->text($L, $y, $ln); $y += 4; } }
    $pdf->setFont('R', 7.5); $pdf->setColor('#6a7179'); $pdf->text($L, $y + 6, t('pi.footnote'));
    $pdf->setFont('R', 7); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $r['pi_no']); $pdf->text($L, 290, t('rp.page') . ' 1', 'R', $W);
    return $pdf->bytes($r['pi_no']);
}
function pi_pdf_download(int $id): void { $r = pi_get($id); if (!$r) { http_response_code(404); exit; } $b = pi_render_pdf($r); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $r['pi_no'] . '.pdf"'); header('Content-Length: ' . strlen($b)); echo $b; exit; }

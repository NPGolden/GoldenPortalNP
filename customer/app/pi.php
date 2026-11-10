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
/** R76-001: what is paid on a proforma = direct payments + the paid amount of invoices that settle no recorded payment (no double count). */
/* R95-004 ledger v2 — amounts of a ledger row:
 *   PI_ADV_SQL      Σ payments logged on the row itself (an invoice: its payments; a proforma: advances received before invoicing)
 *   PI_INV_SQL      Σ amounts of the invoices linked to a proforma (pi_links)
 *   PI_INVPAID_SQL  the part of those invoices' payments allocated to the proforma (pro rata of the link amount)
 *   PI_PAID_SQL     paid for status purposes: invoice → own payments; proforma → advances + allocated invoice payments
 *   PI_OPEN_SQL     open amount for collections: invoice → gross − paid; proforma → gross − invoiced − advances (the invoices carry the rest) */
const PI_ADV_SQL = "(SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp WHERE pp.proforma_id = pi.id)";
const PI_INV_SQL = "(SELECT COALESCE(SUM(l.amount),0) FROM pi_links l WHERE l.proforma_id = pi.id)";
const PI_INVPAID_SQL = "(SELECT COALESCE(SUM(LEAST(l.amount, l.amount * (SELECT COALESCE(SUM(pq.amount),0) FROM proforma_payments pq WHERE pq.proforma_id = l.invoice_id) / NULLIF(x.gross, 0))),0) FROM pi_links l JOIN proformas x ON x.id = l.invoice_id WHERE l.proforma_id = pi.id)";
const PI_PAID_SQL = "(" . PI_ADV_SQL . " + CASE WHEN pi.kind = 'invoice' THEN 0 ELSE " . PI_INVPAID_SQL . " END)";
const PI_OPEN_SQL = "(pi.gross - " . PI_ADV_SQL . " - CASE WHEN pi.kind = 'invoice' THEN 0 ELSE " . PI_INV_SQL . " END)";
const PI_SELECT = "SELECT pi.*, o.offer_no, o.revision, o.sq_no, o.title AS offer_title, o.status AS offer_status, p.project_code, p.name AS project_name, p.pm_user_id, s.code AS sub_code, s.name AS sub_name, v.name AS vessel, c.name AS customer_name, p.customer_id,
                   iv.label AS iv_label, iv.legal_name AS iv_legal, f.original_name, cu.full_name AS created_name,
                   " . PI_PAID_SQL . " AS paid_sum, " . PI_ADV_SQL . " AS adv_sum, " . PI_INV_SQL . " AS inv_sum, " . PI_OPEN_SQL . " AS open_sum,
                   (SELECT COUNT(*) FROM pi_links l WHERE l.proforma_id = pi.id) AS n_inv, (SELECT COUNT(*) FROM pi_links l WHERE l.invoice_id = pi.id) AS n_settles,
                   (SELECT GROUP_CONCAT(x.pi_no ORDER BY x.issue_date, x.id SEPARATOR ', ') FROM pi_links l JOIN proformas x ON x.id = l.invoice_id WHERE l.proforma_id = pi.id) AS invoice_nos,
                   (SELECT GROUP_CONCAT(x.pi_no ORDER BY x.id SEPARATOR ', ') FROM pi_links l JOIN proformas x ON x.id = l.proforma_id WHERE l.invoice_id = pi.id) AS settles_nos
            FROM proformas pi LEFT JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN subprojects s ON s.id = pi.subproject_id LEFT JOIN vessels v ON v.id = pi.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
            LEFT JOIN invoicing_details iv ON iv.id = pi.invoicing_id LEFT JOIN sp_files f ON f.id = pi.file_id LEFT JOIN customer_users cu ON cu.id = pi.created_by";
function pi_get(int $id): ?array {
    if (!pi_can_view() || !$id) return null;
    [$sc, $sa] = scope_sql('p'); $r = one(PI_SELECT . " WHERE pi.id = ? AND {$sc}", array_merge([$id], $sa)); if (!$r) return null;
    // R95-004: 'invoices' = the invoice rows linked to this proforma (amount allocated, invoice gross / paid / open); 'settles' = the proformas an invoice row settles
    $r['invoices'] = all("SELECT l.id AS link_id, l.amount, l.legacy_id, x.id, x.pi_no AS invoice_no, x.issue_date AS invoice_date, x.due_date, x.gross, x.status, x.notes AS note, x.currency, cu.full_name AS by_name,
                                 (SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp WHERE pp.proforma_id = x.id) AS paid_amount, (SELECT MAX(pp.paid_on) FROM proforma_payments pp WHERE pp.proforma_id = x.id) AS paid_at
                          FROM pi_links l JOIN proformas x ON x.id = l.invoice_id LEFT JOIN customer_users cu ON cu.id = x.created_by WHERE l.proforma_id = ? ORDER BY x.issue_date, x.id", [$id]);
    $r['settles'] = all("SELECT l.id AS link_id, l.amount, x.id, x.pi_no, x.title, x.gross, x.status, x.issue_date FROM pi_links l JOIN proformas x ON x.id = l.proforma_id WHERE l.invoice_id = ? ORDER BY x.issue_date, x.id", [$id]);
    $r['payments'] = ppay_list($id);
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
    pi_set_party($id);   // R95-003: party, customer and vessel of the ledger row
    return $id;
}
function pi_save_from_post(int $id): void { cache_bump_later('fs');
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
    pi_set_party($id, $iv);   // R95-003
    pi_refresh_status($id);
}
/** Issue: status issued, PDF generated and stored unless an uploaded file exists. */
function pi_issue(int $id): void { cache_bump_later('fs');
    $r = pi_get($id); if (!$r || !pi_is_sales()) throw new RuntimeException(t('err.noaccess')); if ($r['status'] !== 'draft') return;
    if (!$r['file_id']) { $bytes = pi_render_pdf($r); $fid = cp_store_bytes($bytes, $r['pi_no'] . '.pdf', 'application/pdf', (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'other', 'external', null, $r['pi_no'], ['doc_no' => $r['pi_no'], 'label' => $r['pi_no']]); q('UPDATE proformas SET file_id = ? WHERE id = ?', [$fid, $id]); }
    q("UPDATE proformas SET status = 'issued', issue_date = COALESCE(issue_date, CURDATE()) WHERE id = ?", [$id]);
    of_event((int)$r['offer_id'], 'proforma_issued', $r['pi_no']); pi_notify($r, 'issued');
}
function pi_set_status(int $id, string $st): void { cache_bump_later('fs');
    $r = pi_get($id); if (!$r || !pi_is_sales() || !in_array($st, PI_STATUSES, true)) throw new RuntimeException(t('err.noaccess'));
    if ($st === 'issued') { pi_issue($id); return; }
    if ($st === 'draft' && $r['status'] !== 'issued') throw new RuntimeException(t('pi.locked'));
    q('UPDATE proformas SET status = ?, sent_at = CASE WHEN ? = "sent" THEN COALESCE(sent_at, NOW()) ELSE sent_at END, paid_at = CASE WHEN ? = "paid" THEN COALESCE(paid_at, CURDATE()) ELSE paid_at END WHERE id = ?', [$st, $st, $st, $id]);
    of_event((int)$r['offer_id'], 'proforma_' . $st, $r['pi_no']);
    if (in_array($st, ['paid', 'cancelled'], true)) pi_notify($r, $st);
}
/** Mark sent when the e-mail card is used (hidden field proforma=<id>). */
function pi_mark_sent(int $id): void { cache_bump_later('fs'); $r = pi_get($id); if ($r && in_array($r['status'], ['issued'], true)) { q("UPDATE proformas SET status = 'sent', sent_at = NOW() WHERE id = ?", [$id]); of_event((int)$r['offer_id'], 'proforma_sent', $r['pi_no']); } }
/** Status from what is paid: paid when Σ paid ≥ gross, partly_paid when > 0, else the issued/sent state. */
/** R95-005: was this invoice row built by registering it on proformas (gross = Σ allocations), or is it a direct invoice typed by accounting (its own gross)? */
function pi_links_col(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM proformas LIKE 'from_links'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pi_from_links(array $inv): bool { return pi_links_col() ? (int)($inv['from_links'] ?? 0) === 1 : !empty($inv['legacy_inv_id']); }
function pi_refresh_status(int $id): void {
    $r = one("SELECT pi.*, " . PI_PAID_SQL . " AS paid_sum, " . PI_INV_SQL . " AS inv_sum FROM proformas pi WHERE pi.id = ?", [$id]); if (!$r || in_array($r['status'], ['draft', 'cancelled'], true)) return;
    $paid = (float)$r['paid_sum']; $gross = (float)$r['gross'];
    $st = $paid > 0 && $paid + 0.005 >= $gross ? 'paid' : ($paid > 0 ? 'partly_paid' : ($r['sent_at'] ? 'sent' : 'issued'));
    if ($st !== $r['status']) { q('UPDATE proformas SET status = ?, paid_at = CASE WHEN ? = "paid" THEN COALESCE(paid_at, CURDATE()) ELSE NULL END WHERE id = ?', [$st, $st, $id]); if ($st === 'paid') { $rr = pi_get($id); if ($rr) pi_notify($rr, 'paid'); } }
    if ($r['kind'] === 'invoice') foreach (all('SELECT proforma_id FROM pi_links WHERE invoice_id = ?', [$id]) as $l) pi_refresh_status((int)$l['proforma_id']);   // the proformas it settles follow
}
/** R95-004: register an accounting invoice against a proforma. The invoice is a ledger row (kind invoice) — created, or reused when the
 *  number already exists (one invoice may settle several proformas); the allocated amount goes to pi_links. Unlinked advances of the
 *  proforma move to the invoice up to its gross. Σ links ≠ proforma gross → warning only (decision 6). Returns [invoice id, warning|null]. */
function pi_register_invoice(int $piId, string $no, ?string $date, float $amount, ?string $note = null, ?string $paidOn = null, ?float $paidAmt = null): array {
    $r = pi_get($piId); if (!$r || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    if (pi_is_invoice($r)) throw new RuntimeException(t('pi.di_is_invoice'));
    if (in_array($r['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('pay.not_issued'));
    $no = mb_substr(trim($no), 0, 60); if ($no === '') throw new RuntimeException(t('pi.need_inv_no'));
    if ($amount <= 0) $amount = max(0.0, (float)$r['gross'] - (float)$r['inv_sum']); if ($amount <= 0) throw new RuntimeException(t('pi.di_need_amount'));
    $inv = one("SELECT * FROM proformas WHERE pi_no = ? AND kind = 'invoice'", [$no]);
    if (!$inv && scalar("SELECT COUNT(*) FROM proformas WHERE pi_no = ?", [$no])) throw new RuntimeException(t('pi.di_dup_no', ['n' => $no]));
    if ($inv && scalar('SELECT COUNT(*) FROM pi_links WHERE invoice_id = ? AND proforma_id = ?', [(int)$inv['id'], $piId])) throw new RuntimeException(t('pi.inv_already_linked', ['n' => $no]));
    $vat = (float)$r['vat_pct'];
    if (!$inv) {
        q('INSERT INTO proformas (kind, pi_no, offer_id, term_n, project_id, subproject_id, vessel_id, customer_id, billing_org_id, invoicing_id, title, issue_date, due_date, amount_net, vat_pct, vat_amount, gross, currency, status, notes, created_by, sent_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
          ['invoice', $no, null, 0, $r['project_id'], $r['subproject_id'], $r['vessel_id'], $r['customer_id'], $r['billing_org_id'] ?? null, $r['invoicing_id'], mb_substr((string)$r['title'], 0, 200), $date ?: date('Y-m-d'), $r['due_date'], round($amount / (1 + $vat / 100), 2), $vat, round($amount - $amount / (1 + $vat / 100), 2), $amount, $r['currency'], 'sent', $note ?: null, (int)(current_user()['id'] ?? 0)]);
        $invId = (int) db()->lastInsertId(); if (pi_links_col()) q('UPDATE proformas SET from_links = 1 WHERE id = ?', [$invId]);
    } else {   // an existing invoice settles one more proforma
        $invId = (int)$inv['id'];
        if (pi_from_links($inv)) q('UPDATE proformas SET gross = gross + ?, amount_net = amount_net + ?, vat_amount = gross - amount_net WHERE id = ?', [$amount, round($amount / (1 + $vat / 100), 2), $invId]);   // built from proformas: its gross grows by the allocation
        else {   // R95-005: a direct invoice keeps its own gross — only what is not allocated yet can settle a proforma
            $left = round((float)$inv['gross'] - (float) scalar('SELECT COALESCE(SUM(amount),0) FROM pi_links WHERE invoice_id = ?', [$invId]), 2);
            if ($amount > $left + 0.005) throw new RuntimeException(t('pi.inv_alloc_over', ['n' => $no, 'left' => pi_money(max(0.0, $left), (string)$inv['currency'])]));
        }
    }
    q('INSERT INTO pi_links (invoice_id, proforma_id, amount) VALUES (?,?,?)', [$invId, $piId, $amount]);
    // advances received on the proforma move to the invoice (oldest first, while they fit)
    $room = (float) scalar('SELECT gross - ' . PI_ADV_SQL . ' FROM proformas pi WHERE pi.id = ?', [$invId]);
    foreach (all('SELECT id, amount FROM proforma_payments WHERE proforma_id = ? ORDER BY paid_on, id', [$piId]) as $pp) { if ((float)$pp['amount'] <= $room + 0.005) { q('UPDATE proforma_payments SET proforma_id = ? WHERE id = ?', [$invId, (int)$pp['id']]); $room -= (float)$pp['amount']; } }
    if ($paidOn && ($paidAmt === null || $paidAmt > 0)) { $amt = $paidAmt ?? $amount; q('INSERT INTO proforma_payments (proforma_id, paid_on, amount, method, reference, note, created_by) VALUES (?,?,?,?,?,?,?)', [$invId, $paidOn, $amt, 'bank', null, null, (int)(current_user()['id'] ?? 0)]); }
    of_event((int)$r['offer_id'], 'invoice', $no . ' · ' . pi_money($amount, $r['currency']));
    pi_refresh_status($invId); pi_refresh_status($piId);
    $sum = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM pi_links WHERE proforma_id = ?', [$piId]);
    $warn = abs($sum - (float)$r['gross']) > 0.01 ? t('pi.inv_mismatch_warn', ['i' => pi_money($sum, $r['currency']), 'g' => pi_money((float)$r['gross'], $r['currency'])]) : null;
    cache_bump_later('fs'); return [$invId, $warn];
}
function pinv_save_from_post(int $piId): ?string { cache_bump_later('fs');
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $amt = function_exists('of_parse_amount') ? of_parse_amount((string)($_POST['inv_amount'] ?? '0')) : (float)($_POST['inv_amount'] ?? 0);
    $paidIn = trim((string)($_POST['paid_amount'] ?? '')); $paidAmt = $paidIn === '' ? null : (function_exists('of_parse_amount') ? of_parse_amount($paidIn) : (float)$paidIn);
    [, $warn] = pi_register_invoice($piId, $s('invoice_no', 60), $d('invoice_date'), $amt, $s('inv_note', 255) ?: null, $d('paid_at'), $paidAmt);
    return $warn;
}
/** Remove the link between an invoice and a proforma; an invoice row left with no links, no payments and no offer is deleted. Returns the proforma id. */
function pinv_delete(int $linkId): int { cache_bump_later('fs');
    $l = one('SELECT * FROM pi_links WHERE id = ?', [$linkId]); if (!$l || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    $invId = (int)$l['invoice_id']; $piId = (int)$l['proforma_id'];
    $paid = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM proforma_payments WHERE proforma_id = ?', [$invId]);
    q('DELETE FROM pi_links WHERE id = ?', [$linkId]);
    $left = (int) scalar('SELECT COUNT(*) FROM pi_links WHERE invoice_id = ?', [$invId]);
    $invRow = one('SELECT * FROM proformas WHERE id = ?', [$invId]);
    if (!$invRow || !pi_from_links($invRow)) { if ($invRow) pi_refresh_status($invId); pi_refresh_status($piId); return $piId; }   // R95-005: a direct invoice is never shrunk or deleted by an unlink — it keeps its gross and its payments
    if (!$left) { if ($paid > 0.005) q('UPDATE proforma_payments SET proforma_id = ? WHERE proforma_id = ?', [$piId, $invId]);   // its payments go back to the proforma as advances
                  q("DELETE FROM proformas WHERE id = ? AND kind = 'invoice' AND offer_id IS NULL", [$invId]); }
    else { $inv = one('SELECT vat_pct FROM proformas WHERE id = ?', [$invId]); q('UPDATE proformas SET gross = gross - ?, amount_net = amount_net - ?, vat_amount = gross - amount_net WHERE id = ?', [(float)$l['amount'], round((float)$l['amount'] / (1 + (float)($inv['vat_pct'] ?? 0) / 100), 2), $invId]); pi_refresh_status($invId); }
    pi_refresh_status($piId); return $piId;
}
function pi_delete(int $id): int { cache_bump_later('fs'); require_cp_admin(); $r = pi_get($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM pi_links WHERE proforma_id = ? OR invoice_id = ?', [$id, $id])) throw new RuntimeException(t('pi.del_has_links')); if (scalar('SELECT COUNT(*) FROM proforma_payments WHERE proforma_id = ?', [$id])) throw new RuntimeException(t('pi.del_has_payments'));
    q('DELETE FROM proformas WHERE id = ?', [$id]); return (int)$r['offer_id']; }
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
    if (function_exists('cache_remember')) return cache_remember('fs:' . cache_ver('fs') . ':' . $pid . ':' . (int)$sid, 180, fn() => fs_totals_build($pid, $sid, $z));   // R77-021
    return fs_totals_build($pid, $sid, $z);
}
function fs_totals_build(int $pid, ?int $sid, array $z): array {
    $w = $sid ? 'o.subproject_id = ?' : 'o.project_id = ?'; $arg = [$sid ?: $pid];
    $defs = of_status_defs(); $appr = array_keys(array_filter($defs, fn($d) => in_array($d['behaves'], ['approved', 'closed_won'], true))); if (!$appr) $appr = ['approved'];
    $z['offered'] = (float) scalar("SELECT COALESCE(SUM(o.amount),0) FROM offers o WHERE {$w} AND o.status IN (" . implode(',', array_fill(0, count($appr), '?')) . ") AND (o.converted_to IS NULL)", array_merge($arg, $appr));
    $pw = $sid ? 'pi.subproject_id = ?' : 'pi.project_id = ?';
    // R95-004: one ledger — invoices (direct or settling proformas) and not-yet-invoiced proforma remainders; paid = payments on each row (invoice payments counted once, on the invoice)
    $r = one("SELECT COUNT(*) n, COALESCE(SUM(CASE WHEN pi.kind = 'invoice' THEN pi.gross ELSE GREATEST(0, pi.gross - " . PI_INV_SQL . ") END),0) g, COALESCE(SUM(LEAST(pi.gross, " . PI_ADV_SQL . ")),0) paid,
                     SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') AND pi.due_date < CURDATE() AND " . PI_OPEN_SQL . " > 0.005 THEN 1 ELSE 0 END) n_od,
                     COALESCE(SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') AND pi.due_date < CURDATE() THEN GREATEST(0, " . PI_OPEN_SQL . ") ELSE 0 END),0) od,
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
    $pdf->setColor($navy); $pdf->setFont('B', 16); $pdf->text($L, 20, mb_strtoupper(pi_is_invoice($r) ? t('pi.di_doc_title') : t('pi.doc_title')));
    $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $r['pi_no'] . ' · ' . fmt_date($r['issue_date'] ?: date('Y-m-d')));
    $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36.0;
    // issuer / bill to
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($L, $y, mb_strtoupper(t('pi.from'))); $pdf->text(110, $y, mb_strtoupper(t('pi.bill_to'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap($ln0, 88) as $ln) { $pdf->text($L, $yl, $ln); $yl += 4; }
    $iv = $r['iv'] ?? null; $yr = $y; $bill = $iv ? array_filter([(string)$iv['legal_name'], (string)($iv['address'] ?? ''), trim((string)($iv['postal_code'] ?? '') . ' ' . (string)($iv['city'] ?? '') . ' ' . (string)($iv['country'] ?? '')), $iv['vat_no'] ? 'VAT ' . $iv['vat_no'] : '', $iv['attn'] ? t('iv.attn') . ' ' . $iv['attn'] : '', (string)($iv['email'] ?? '')]) : [(string)($r['customer_name'] ?: '—')];
    foreach ($bill as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    $kv = [[t('pr.one'), trim(($r['project_code'] ?: '') . ' ' . $r['project_name']) . ($r['sub_code'] ? ' › ' . $r['sub_code'] . ' ' . $r['sub_name'] : '')], [t('v.one'), (string)($r['vessel'] ?: '—')], [t('of.one'), $r['offer_id'] ? $r['offer_no'] . ' ' . $r['revision'] . ($r['sq_no'] ? ' · SQ ' . $r['sq_no'] : '') : '—'], [t('pi.due'), $r['due_date'] ? fmt_date($r['due_date']) : '—'], [t('pi.terms_ref'), $r['term_n'] ? t('pt.instalment', ['n' => (int)$r['term_n']]) : '—']];
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

/* ------------------------------------------------------------------ R76-001: advance payments on a proforma (no invoice needed; invoiced later) */
const PPAY_METHODS = ['bank', 'cash', 'card', 'cheque', 'other'];
function ppay_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'proforma_payments'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ppay_list(int $piId): array { return ppay_has() ? all('SELECT pp.*, cu.full_name AS by_name, NULL AS invoice_no FROM proforma_payments pp LEFT JOIN customer_users cu ON cu.id = pp.created_by WHERE pp.proforma_id = ? ORDER BY pp.paid_on, pp.id', [$piId]) : []; }
/** The cap: the gross total of the offer (net + VAT) — proformas are gross. */
function ppay_offer_cap(array $o): float { $g = (float)($o['gross_total'] ?? 0); if ($g > 0) return $g; $net = (float)($o['amount'] ?? 0); $vat = (float)($o['vat_pct'] ?? 0); return round($net * (1 + $vat / 100), 2); }
/** Σ paid on every proforma of the offer (cancelled ones excluded). */
function ppay_offer_paid(int $offerId): float { return (float) scalar('SELECT COALESCE(SUM(' . PI_PAID_SQL . "),0) FROM proformas pi WHERE pi.offer_id = ? AND pi.status <> 'cancelled'", [$offerId]); }
function ppay_save_from_post(int $piId): void { cache_bump_later('fs');
    $r = pi_get($piId); if (!$r || !pi_can_invoices() || !ppay_has()) throw new RuntimeException(t('err.noaccess'));
    if (in_array($r['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('pay.not_issued'));
    $id = (int)($_POST['pay_id'] ?? 0); $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] : date('Y-m-d');
    $amt = function_exists('of_parse_amount') ? of_parse_amount((string)($_POST['amount'] ?? '0')) : (float)($_POST['amount'] ?? 0); if ($amt <= 0) throw new RuntimeException(t('pay.need_amount'));
    $method = in_array($_POST['method'] ?? '', PPAY_METHODS, true) ? $_POST['method'] : 'bank'; $ref = mb_substr(trim((string)($_POST['reference'] ?? '')), 0, 80) ?: null; $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    // R95-004 (decision 7): the cap is the document itself — an invoice takes payments up to its gross; a proforma takes ADVANCES up to its not-yet-invoiced part
    $cap = pi_is_invoice($r) ? (float)$r['gross'] : max(0.0, (float)$r['gross'] - (float)$r['inv_sum']);
    $already = (float)$r['adv_sum']; if ($id) $already -= (float) (scalar('SELECT amount FROM proforma_payments WHERE id = ? AND proforma_id = ?', [$id, $piId]) ?: 0);
    if (!pi_is_invoice($r) && (float)$r['inv_sum'] > 0.005 && $cap <= 0.005) throw new RuntimeException(t('pay.on_invoice', ['n' => (string)$r['invoice_nos']]));
    if ($cap > 0 && $already + $amt > $cap + 0.005) throw new RuntimeException(t('pay.exceeds', ['paid' => pi_money($already, $r['currency']), 'cap' => pi_money($cap, $r['currency']), 'left' => pi_money(max(0, $cap - $already), $r['currency'])]));
    if ($id) q('UPDATE proforma_payments SET paid_on = ?, amount = ?, method = ?, reference = ?, note = ? WHERE id = ? AND proforma_id = ?', [$d, $amt, $method, $ref, $note, $id, $piId]);
    else q('INSERT INTO proforma_payments (proforma_id, paid_on, amount, method, reference, note, created_by) VALUES (?,?,?,?,?,?,?)', [$piId, $d, $amt, $method, $ref, $note, (int)(current_user()['id'] ?? 0)]);
    of_event((int)$r['offer_id'], 'payment', $r['pi_no'] . ' · ' . pi_money($amt, $r['currency']) . ' · ' . t('pay.m_' . $method) . ($ref ? ' · ' . $ref : ''));
    pi_refresh_status($piId);
}
function ppay_delete(int $payId): int { cache_bump_later('fs'); $x = one('SELECT pp.*, pi.offer_id, pi.pi_no, pi.currency FROM proforma_payments pp JOIN proformas pi ON pi.id = pp.proforma_id WHERE pp.id = ?', [$payId]); if (!$x || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM proforma_payments WHERE id = ?', [$payId]); of_event((int)$x['offer_id'], 'payment_removed', $x['pi_no'] . ' · ' . pi_money((float)$x['amount'], $x['currency'])); pi_refresh_status((int)$x['proforma_id']); return (int)$x['proforma_id']; }
/** An invoice settles payments: link the chosen uninvoiced payments of the proforma to it. */
function ppay_link(int $piId, int $invId, array $payIds): void { if (!ppay_has()) return; $ids = array_values(array_filter(array_map('intval', $payIds))); q('UPDATE proforma_payments SET invoice_id = NULL WHERE proforma_id = ? AND invoice_id = ?', [$piId, $invId]); if ($ids) q('UPDATE proforma_payments SET invoice_id = ? WHERE proforma_id = ? AND id IN (' . implode(',', $ids) . ')', [$invId, $piId]); }


/* ======================================================================================================================
 * GAS-CP-R94-001 — direct invoices: an accounting invoice issued for work that had no proforma. Same table (kind = invoice),
 * so collections, statements, aging, follow-ups and e-mails treat it exactly like a proforma; the row itself IS the invoice.
 * ==================================================================================================================== */
function pi_kind_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM proformas LIKE 'kind'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pi_is_invoice(array $r): bool { return (string)($r['kind'] ?? 'proforma') === 'invoice'; }
/** Label of the document for pills, PDF and statements. */
function pi_kind_label(array $r): string { return pi_is_invoice($r) ? t('pi.k_invoice') : t('pi.k_proforma'); }
/** Register a direct invoice from the form. Issued at once (the invoice exists in the books), so it enters collections immediately. */
function pi_direct_create_from_post(): int {
    if (!pi_kind_has() || !pi_can_invoices()) throw new RuntimeException(t('err.noaccess'));
    $s = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $no = $s('pi_no', 60); if ($no === '') throw new RuntimeException(t('pi.di_need_no'));
    if (scalar('SELECT COUNT(*) FROM proformas WHERE pi_no = ?', [$no])) throw new RuntimeException(t('pi.di_dup_no', ['n' => $no]));
    $pid = (int)($_POST['project_id'] ?? 0) ?: null; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    $p = $pid ? one('SELECT id, vessel_id, customer_id FROM projects WHERE id = ?', [$pid]) : null; if ($pid && !$p) throw new RuntimeException(t('err.notfound'));
    if ($sid && !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    $vid = (int)($_POST['vessel_id'] ?? 0) ?: ($p ? (int)$p['vessel_id'] : null) ?: null; $cid = (int)($_POST['customer_id'] ?? 0) ?: ($p ? (int)$p['customer_id'] : null) ?: null;
    if (!$pid && !$vid && !$cid) throw new RuntimeException(t('pi.di_need_target'));
    if (!$pid) { $pid = (int) scalar("SELECT id FROM projects WHERE vessel_id = ? AND project_code LIKE 'GEN-%' LIMIT 1", [$vid]) ?: (int) scalar('SELECT id FROM projects WHERE vessel_id = ? ORDER BY id DESC LIMIT 1', [$vid]); if (!$pid) throw new RuntimeException(t('pi.di_need_target')); }
    $issue = $d('issue_date') ?: date('Y-m-d'); $due = $d('due_date');
    $net = function_exists('of_parse_amount') ? of_parse_amount((string)($_POST['amount_net'] ?? '0')) : (float) str_replace(',', '.', (string)($_POST['amount_net'] ?? '0')); if ($net <= 0) throw new RuntimeException(t('pi.di_need_amount'));
    $vat = max(0, min(100, (float) str_replace(',', '.', (string)($_POST['vat_pct'] ?? '0')))); $cur = strtoupper($s('currency', 3)) ?: 'EUR';
    $iv = (int)($_POST['invoicing_id'] ?? 0) ?: null; if ($iv && !iv_load($iv)) $iv = null;
    $title = $s('title', 200) ?: t('pi.k_invoice') . ' ' . $no;
    q('INSERT INTO proformas (kind, pi_no, offer_id, term_n, project_id, subproject_id, vessel_id, customer_id, invoicing_id, title, issue_date, due_date, amount_net, vat_pct, vat_amount, gross, currency, status, notes, created_by, sent_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())',
      ['invoice', $no, null, 0, $pid, $sid, $vid, $cid, $iv, $title, $issue, $due, $net, $vat, round($net * $vat / 100, 2), round($net * (1 + $vat / 100), 2), $cur, 'sent', $s('notes', 2000) ?: null, (int)(current_user()['id'] ?? 0)]);
    $id = (int) db()->lastInsertId();
    pi_set_party($id, $iv);   // R95-003
    if (!empty($_FILES['file']['name']) && function_exists('cp_store_file')) { $fid = cp_store_file($_FILES['file'], $pid, $sid, 'issued', 'internal', $no); if ($fid) q('UPDATE proformas SET file_id = ? WHERE id = ?', [$fid, $id]); }
    if (function_exists('sp_log') && $sid) try { sp_log($sid, 'invoice_registered', $no . ' · ' . number_format($net, 2, ',', '.') . ' ' . $cur); } catch (Throwable $e) {}
    // R94-003: raised from an accounting report (GAS-AR) → its reported lines are invoiced under this number, as the accounting answer would do
    $arId = (int)($_POST['acc_report_id'] ?? 0);
    if ($arId && function_exists('acc_load') && function_exists('acc_is_accounting') && acc_is_accounting()) {
        $R = acc_load($arId);
        if ($R && in_array($R['status'], ['issued', 'sent', 'partly_invoiced'], true)) { try { acc_mark_invoiced($R, $no, $issue, t('pi.di_from_ar', ['no' => $R['report_no']]), null, $id); } catch (Throwable $e) {} }
    }
    cache_bump_later('fs'); return $id;
}
/** R95-003: store party (from the chosen identity, else sub-project / project), customer and vessel (from the project) on a ledger row. */
function pi_set_party(int $id, ?int $ivId = null): void {
    if (!function_exists('pty_has') || !pty_has()) return;
    $r = one('SELECT id, project_id, subproject_id, vessel_id, customer_id, invoicing_id FROM proformas WHERE id = ?', [$id]); if (!$r) return;
    $ivId = $ivId ?? ((int)$r['invoicing_id'] ?: null);
    $org = pty_for_doc((int)$r['project_id'] ?: null, (int)$r['subproject_id'] ?: null, $ivId);
    $P = $r['project_id'] ? pty_project((int)$r['project_id']) : ['customer_id' => null, 'vessel_id' => null];
    q('UPDATE proformas SET billing_org_id = ?, customer_id = COALESCE(customer_id, ?), vessel_id = COALESCE(vessel_id, ?) WHERE id = ?', [$org, $P['customer_id'], $P['vessel_id'], $id]);
}
/* ---- accounting checks (Collections › Checks) ---- */
/** Proformas whose registered invoices do not add up to the proforma gross (issued / sent / partly paid / paid; cancelled and drafts excluded). */
function pi_check_mismatch(): array {
    $rows = all(PI_SELECT . " WHERE pi.kind = 'proforma' AND pi.status NOT IN ('draft','cancelled') AND EXISTS (SELECT 1 FROM pi_links l WHERE l.proforma_id = pi.id)
                 AND ABS(" . PI_INV_SQL . " - pi.gross) > 0.01 ORDER BY pi.issue_date DESC");
    foreach ($rows as &$r) $r['invoices'] = all('SELECT x.pi_no AS invoice_no, l.amount FROM pi_links l JOIN proformas x ON x.id = l.invoice_id WHERE l.proforma_id = ? ORDER BY x.issue_date, x.id', [(int)$r['id']]); unset($r);
    return $rows;
}
/** Issued proformas older than $days with no accounting invoice registered yet. */
function pi_check_uninvoiced(int $days = 30): array {
    return all(PI_SELECT . " WHERE pi.kind = 'proforma' AND pi.status IN ('issued','sent','partly_paid','paid') AND pi.issue_date <= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                 AND NOT EXISTS (SELECT 1 FROM pi_links l WHERE l.proforma_id = pi.id) ORDER BY pi.issue_date", [$days]);
}
/** Sub-project invoicing coverage — all sub-projects (completed included) with contract value, invoiced, collected and the computed state. */
function pi_check_coverage(array $f = []): array {
    $w = ['1 = 1']; $a = [];
    if (!empty($f['project'])) { $w[] = 's.project_id = ?'; $a[] = (int)$f['project']; }
    if (!empty($f['status']) && $f['status'] !== 'all') { $w[] = 's.status = ?'; $a[] = (string)$f['status']; }
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    $rows = all("SELECT s.id, s.code, s.name, s.status, s.completed_at, s.invoicing_override, p.id AS project_id, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name,
                        (SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp JOIN proformas pi ON pi.id = pp.proforma_id WHERE pi.subproject_id = s.id AND pi.status <> 'cancelled') AS collected
                 FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
                 WHERE " . implode(' AND ', $w) . " ORDER BY p.project_code DESC, SUBSTRING_INDEX(s.code, '.', 1), CAST(SUBSTRING_INDEX(s.code, '.', -1) AS UNSIGNED), s.code", $a);
    inv_apply($rows);
    $st = (string)($f['inv'] ?? '');
    // "open": anything accounting should look at — not fully invoiced, or invoiced without an approved offer to measure against (contract unknown); idle quoted subs are left out
    if ($st === 'open') $rows = array_values(array_filter($rows, fn($r) => (($r['invoicing'] ?? 'none') !== 'full' || (float)($r['inv_contract'] ?? 0) <= 0.005) && ((float)($r['inv_contract'] ?? 0) > 0.005 || (float)($r['inv_invoiced'] ?? 0) > 0.005 || (float)$r['collected'] > 0.005 || $r['status'] === 'completed')));
    elseif ($st !== '' && $st !== 'all') $rows = array_values(array_filter($rows, fn($r) => ($r['invoicing'] ?? 'none') === $st));
    return $rows;
}

/* ---- R95-004: one-off conversion of proforma_invoices into ledger rows + links; runs once after the migration (flag ledger_v2) ---- */
function pi_ledger_convert(): int {
    if (!pi_has() || !(bool) scalar("SHOW TABLES LIKE 'pi_links'")) return 0;
    if (function_exists('cp_setting') && cp_setting('ledger_v2', '') === '1') return 0;
    $n = 0;
    try { if (!(int) scalar("SELECT GET_LOCK('cp_ledger_v2', 0)")) return 0; } catch (Throwable $e) {}   // one request converts; the others carry on
    if (!(bool) scalar("SHOW TABLES LIKE 'proforma_invoices'")) { if (function_exists('cp_setting_set')) cp_setting_set('ledger_v2', '1'); return 0; }
    $payInvCol = (bool) scalar("SHOW COLUMNS FROM proforma_payments LIKE 'invoice_id'");
    foreach (all("SELECT x.*, pi.title, pi.project_id, pi.subproject_id, pi.vessel_id, pi.customer_id, pi.billing_org_id, pi.invoicing_id, pi.issue_date AS pi_issue, pi.due_date AS pi_due, pi.vat_pct, pi.currency, pi.offer_id AS pi_offer, pi.kind AS pi_kind FROM proforma_invoices x JOIN proformas pi ON pi.id = x.proforma_id ORDER BY x.id") as $x) {
        if ((int) scalar('SELECT COUNT(*) FROM pi_links WHERE legacy_id = ?', [(int)$x['id']])) continue;   // already converted
        if ($x['pi_kind'] === 'invoice') continue;                                                            // never registered on an invoice row
        $no = trim((string)$x['invoice_no']); if ($no === '') $no = 'INV-' . $x['id'];
        $amt = (float)$x['amount']; $vat = (float)$x['vat_pct']; $inv = one("SELECT * FROM proformas WHERE pi_no = ?", [$no]);
        if ($inv && $inv['kind'] !== 'invoice') { $no = $no . ' (inv)'; $inv = one("SELECT * FROM proformas WHERE pi_no = ?", [$no]); }
        if (!$inv) {
            q('INSERT INTO proformas (kind, legacy_inv_id, pi_no, offer_id, term_n, project_id, subproject_id, vessel_id, customer_id, billing_org_id, invoicing_id, title, issue_date, due_date, amount_net, vat_pct, vat_amount, gross, currency, status, notes, created_by, sent_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              ['invoice', (int)$x['id'], $no, null, 0, $x['project_id'], $x['subproject_id'], $x['vessel_id'], $x['customer_id'], $x['billing_org_id'], $x['invoicing_id'], mb_substr((string)$x['title'], 0, 200), $x['invoice_date'] ?: $x['pi_issue'], $x['pi_due'], round($amt / (1 + $vat / 100), 2), $vat, round($amt - $amt / (1 + $vat / 100), 2), $amt, $x['currency'] ?: 'EUR', 'sent', $x['note'] ?: null, (int)$x['created_by'], $x['created_at'], $x['created_at']]);
            $invId = (int) db()->lastInsertId(); if (pi_links_col()) q('UPDATE proformas SET from_links = 1 WHERE id = ?', [$invId]);
        } else { $invId = (int)$inv['id']; if ($inv['legacy_inv_id']) q('UPDATE proformas SET gross = gross + ?, amount_net = amount_net + ?, vat_amount = gross - amount_net WHERE id = ?', [$amt, round($amt / (1 + $vat / 100), 2), $invId]); }
        q('INSERT IGNORE INTO pi_links (invoice_id, proforma_id, amount, legacy_id) VALUES (?,?,?,?)', [$invId, (int)$x['proforma_id'], $amt, (int)$x['id']]);
        // payments: those tied to the registered invoice move onto it; then the proforma's untied payments while they fit the allocation (the same money must not be counted twice);
        // only what the invoice's own "paid amount" says beyond that is added as a migrated payment
        $tied = 0.0;
        if ($payInvCol) { $tied = (float) scalar('SELECT COALESCE(SUM(amount),0) FROM proforma_payments WHERE invoice_id = ?', [(int)$x['id']]); q('UPDATE proforma_payments SET proforma_id = ? WHERE invoice_id = ?', [$invId, (int)$x['id']]); }
        foreach (all('SELECT id, amount FROM proforma_payments WHERE proforma_id = ?' . ($payInvCol ? ' AND invoice_id IS NULL' : '') . ' ORDER BY paid_on, id', [(int)$x['proforma_id']]) as $pp) { if ($tied + (float)$pp['amount'] > $amt + 0.005) continue; q('UPDATE proforma_payments SET proforma_id = ? WHERE id = ?', [$invId, (int)$pp['id']]); $tied += (float)$pp['amount']; }
        $x['paid_amount'] = max(0.0, (float)$x['paid_amount'] - $tied);
        if ((float)$x['paid_amount'] > 0.005) q('INSERT INTO proforma_payments (proforma_id, paid_on, amount, method, reference, note, created_by) VALUES (?,?,?,?,?,?,?)', [$invId, $x['paid_at'] ?: ($x['invoice_date'] ?: date('Y-m-d')), (float)$x['paid_amount'], 'bank', null, 'migrated: paid amount of the registered invoice', (int)$x['created_by']]);
        $n++;
    }
    try { q("UPDATE acc_lines a JOIN proformas pi ON pi.kind = 'invoice' AND pi.pi_no = a.invoice_ref SET a.invoice_id = pi.id WHERE a.invoice_id IS NULL AND a.invoice_ref IS NOT NULL AND a.invoice_ref <> ''"); } catch (Throwable $e) {}
    foreach (all("SELECT id FROM proformas WHERE status NOT IN ('draft','cancelled')") as $r) pi_refresh_status((int)$r['id']);
    if (function_exists('cp_setting_set')) cp_setting_set('ledger_v2', '1');
    try { q("SELECT RELEASE_LOCK('cp_ledger_v2')"); } catch (Throwable $e) {}
    cache_bump_later('fs'); return $n;
}

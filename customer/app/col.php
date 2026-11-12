<?php
/**
 * Accounting › Collections — the live follow-up of money owed to Golden A/S.                       GAS-CP-R78-001
 *
 *  What it shows: every open proforma (issued / sent / partly paid) with its age, bucket, promise and next
 *  follow-up, grouped vessel › project; the financial status of every project with money in play (offered,
 *  invoiced, collected, outstanding, overdue, still to invoice); the collections of a period (payment register);
 *  and the projected collection of the next 30 days — by week, from the promise date when accounting recorded
 *  one, else the due date of the proforma.
 *  Who sees it: the accounting department (Accounting flag), the sales department (Sales flag), the Owner, the
 *  CEO and the General Manager. Nobody else — not even plain administrators.
 *  Follow-ups are a history (pi_followups): who, when, next contact, promised date, note; the last one drives
 *  the list.
 */
declare(strict_types=1);

const COL_OPEN = ['issued', 'sent', 'partly_paid'];
const COL_BUCKETS = ['current', 'd30', 'd60', 'd90', 'd90p'];   // not yet due · 1–30 · 31–60 · 61–90 · > 90 days overdue

function col_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('pi_has') && pi_has() && (bool) db()->query("SHOW TABLES LIKE 'pi_followups'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
/** The Golden A/S role name of the signed-in account, lower-case ('' when none). */
function cp_role_name(): string { static $n = null; if ($n === null) { $u = current_user(); $n = ''; if ($u && !empty($u['staff_role_id'])) { try { $n = strtolower(trim((string) scalar('SELECT name FROM staff_roles WHERE id = ?', [(int)$u['staff_role_id']]))); } catch (Throwable $e) {} } } return $n; }
/** Management: the Owner scope, the CEO and the General Manager — the only ones who manage portal accounts (R78-001). */
function mgmt_can(): bool { static $v = null; if ($v === null) { $v = is_staff() && ((function_exists('hr_owner') && hr_owner()) || in_array(cp_role_name(), ['general manager', 'managing director'], true)); } return $v; }
function require_mgmt(): array { $u = require_login(); if (!mgmt_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function col_flag(string $col): bool { $u = current_user(); if (!$u || !is_staff()) return false; static $c = []; $k = $col . (int)$u['id']; if (!isset($c[$k])) { try { $c[$k] = (bool) scalar("SELECT {$col} FROM customer_users WHERE id = ?", [(int)$u['id']]); } catch (Throwable $e) { $c[$k] = false; } } return $c[$k]; }
/** Accounting · Sales · Owner · CEO · General Manager. */
function col_can(): bool { static $v = null; if ($v === null) $v = is_staff() && col_has() && (mgmt_can() || col_flag('is_accounting') || col_flag('can_sales')); return $v; }
function col_money(float $v, string $cur = 'EUR'): string { return number_format($v, 2, ',', '.') . ' ' . $cur; }
function col_bucket(int $age): string { return $age <= 0 ? 'current' : ($age <= 30 ? 'd30' : ($age <= 60 ? 'd60' : ($age <= 90 ? 'd90' : 'd90p'))); }
function col_bucket_pill(string $b): string { $m = ['current' => 'green', 'd30' => 'gold', 'd60' => 'gold', 'd90' => 'bad', 'd90p' => 'bad']; return pill($m[$b] ?? 'mute', t('col.b_' . $b)); }

require_once __DIR__ . '/pi.php';   // R97-030: COL_SELECT is built from the ledger amount constants of pi.php — never depend on the caller's load order
const COL_SELECT = "SELECT pi.id, pi.kind, pi.pi_no, pi.title, pi.term_n, pi.issue_date, pi.due_date, pi.gross, pi.currency, pi.status, pi.sent_at, pi.paid_at, pi.offer_id, pi.project_id, pi.subproject_id, pi.vessel_id,
                   o.offer_no, p.project_code, p.name AS project_name, p.pm_user_id, COALESCE(p.customer_id, pi.customer_id, v.customer_id) AS customer_id, s.code AS sub_code, v.name AS vessel, c.name AS customer_name, cu.full_name AS pm_name,
                   pi.billing_org_id, bo.name AS party_name,
                   " . PI_PAID_SQL . " AS paid_sum, " . PI_ADV_SQL . " AS adv_sum, " . PI_INV_SQL . " AS inv_sum, " . PI_OPEN_SQL . " AS open_sum,
                   (SELECT MAX(pp.paid_on) FROM proforma_payments pp WHERE pp.proforma_id = pi.id) AS last_paid_on,
                   (SELECT GROUP_CONCAT(x.pi_no ORDER BY x.issue_date, x.id SEPARATOR ', ') FROM pi_links l JOIN proformas x ON x.id = l.invoice_id WHERE l.proforma_id = pi.id) AS invoice_nos,
                   (SELECT GROUP_CONCAT(x.pi_no ORDER BY x.id SEPARATOR ', ') FROM pi_links l JOIN proformas x ON x.id = l.proforma_id WHERE l.invoice_id = pi.id) AS settles_nos,
                   fu.next_at AS fu_next, fu.promise_at AS fu_promise, fu.note AS fu_note, fu.user_name AS fu_by, fu.created_at AS fu_at
            FROM proformas pi LEFT JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN subprojects s ON s.id = pi.subproject_id LEFT JOIN vessels v ON v.id = pi.vessel_id
            LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_users cu ON cu.id = p.pm_user_id LEFT JOIN companies bo ON bo.id = pi.billing_org_id
            LEFT JOIN pi_followups fu ON fu.id = (SELECT f2.id FROM pi_followups f2 WHERE f2.proforma_id = pi.id ORDER BY f2.id DESC LIMIT 1)";

function col_where(array $f, string $alias = 'pi'): array {
    $w = []; $a = [];
    foreach (['vessel' => $alias . '.vessel_id', 'project' => $alias . '.project_id', 'customer' => 'p.customer_id', 'pm' => 'p.pm_user_id', 'party' => $alias . '.billing_org_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $a[] = (int)$f[$k]; }
    if (!empty($f['q'])) { $w[] = "({$alias}.pi_no LIKE ? OR {$alias}.title LIKE ? OR o.offer_no LIKE ? OR p.project_code LIKE ? OR p.name LIKE ? OR v.name LIKE ? OR c.name LIKE ?)"; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l, $l, $l, $l, $l); }
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    return [$w, $a];
}
/** Open proformas with age, bucket, expected date (promise > due > issue + 30) and the last follow-up. */
function col_open(array $f = []): array {
    [$w, $a] = col_where($f); $w[] = "pi.status IN ('" . implode("','", COL_OPEN) . "')";
    $rows = all(COL_SELECT . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY v.name, p.project_code, pi.due_date IS NULL, pi.due_date, pi.id', $a);
    $today = date('Y-m-d'); $t0 = strtotime($today); $out = [];
    foreach ($rows as $r) {
        $open = round((float)$r['open_sum'], 2); $isCr = $r['kind'] === 'credit';
        if ($isCr ? $open >= -0.005 : $open <= 0.005) continue;   // R95-004: invoice → gross − paid − credits; proforma → gross − invoiced − advances; R95-006: credit note → −(not applied, not refunded)
        $due = $r['due_date'] ?: null; $age = $due ? (int) floor(($t0 - strtotime($due)) / 86400) : (int) floor(($t0 - strtotime((string)$r['issue_date'])) / 86400) - 30;
        if ($isCr) $age = 0;   // a credit is never overdue
        $r['open'] = $open; $r['age'] = $age; $r['bucket'] = col_bucket($age); $r['overdue'] = $age > 0;
        $r['expected'] = $r['fu_promise'] ?: ($due ?: date('Y-m-d', strtotime((string)$r['issue_date'] . ' +30 days')));
        $r['promise_missed'] = $r['fu_promise'] && $r['fu_promise'] < $today;
        $r['fu_due'] = $r['fu_next'] && $r['fu_next'] <= $today;
        if (!empty($f['bucket']) && $f['bucket'] !== $r['bucket'] && !($f['bucket'] === 'overdue' && $r['overdue']) && !($f['bucket'] === 'followup' && ($r['fu_due'] || $r['promise_missed']))) continue;
        $out[] = $r;
    }
    return $out;
}
/** Payments received in a period (the payment register), newest first. */
function col_paid(string $from, string $to, array $f = []): array {
    [$w, $a] = col_where($f); $w[] = 'pp.paid_on BETWEEN ? AND ?'; array_push($a, $from, $to);
    return all("SELECT pp.*, pi.pi_no, pi.title, pi.currency, pi.gross, pi.status, pi.project_id, pi.vessel_id, o.offer_no, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name, x.invoice_no, cu.full_name AS by_name
                FROM proforma_payments pp JOIN proformas pi ON pi.id = pp.proforma_id LEFT JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN vessels v ON v.id = pi.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
                LEFT JOIN proforma_invoices x ON x.id = pp.invoice_id LEFT JOIN customer_users cu ON cu.id = pp.created_by WHERE " . implode(' AND ', $w) . ' ORDER BY pp.paid_on DESC, pp.id DESC', $a);
}
/** Headline figures + the week-by-week projection of the next $days days. */
function col_summary(array $open, int $days = 30, array $f = []): array {
    $S = ['outstanding' => 0.0, 'n_open' => count($open), 'overdue' => 0.0, 'n_overdue' => 0, 'expected' => 0.0, 'n_expected' => 0, 'promised' => 0.0, 'missed' => 0.0, 'n_missed' => 0, 'fu_due' => 0, 'collected' => 0.0, 'n_collected' => 0, 'cur' => 'EUR', 'buckets' => array_fill_keys(COL_BUCKETS, 0.0), 'weeks' => []];
    $today = date('Y-m-d'); $end = date('Y-m-d', strtotime("+{$days} days"));
    $wk = []; for ($i = 0; $i < $days; $i += 7) { $a = date('Y-m-d', strtotime("+{$i} days")); $b = date('Y-m-d', strtotime('+' . min($days, $i + 6) . ' days')); $wk[] = ['from' => $a, 'to' => $b, 'amount' => 0.0, 'n' => 0]; }
    $late = ['from' => null, 'to' => null, 'amount' => 0.0, 'n' => 0];
    foreach ($open as $r) {
        $S['cur'] = $r['currency']; $S['outstanding'] += $r['open']; $S['buckets'][$r['bucket']] += $r['open'];
        if ($r['overdue']) { $S['overdue'] += $r['open']; $S['n_overdue']++; }
        if ($r['fu_promise']) $S['promised'] += $r['open'];
        if ($r['promise_missed']) { $S['missed'] += $r['open']; $S['n_missed']++; }
        if ($r['fu_due']) $S['fu_due']++;
        $x = $r['expected'];
        if ($x < $today) { $late['amount'] += $r['open']; $late['n']++; }
        elseif ($x <= $end) { $S['expected'] += $r['open']; $S['n_expected']++; foreach ($wk as $i => $k) if ($x >= $k['from'] && $x <= $k['to']) { $wk[$i]['amount'] += $r['open']; $wk[$i]['n']++; break; } }
    }
    $S['late'] = $late; $S['weeks'] = $wk; $S['expected_total'] = $S['expected'] + $late['amount'];   // what accounting can reasonably chase within the window: due inside it + everything already late
    foreach (col_paid(date('Y-m-d', strtotime("-{$days} days")), $today, $f) as $p) { $S['collected'] += (float)$p['amount']; $S['n_collected']++; }
    return $S;
}
/** Financial status per project — every project with an approved offer or a proforma, grouped by vessel. */
function col_projects(array $f = []): array {
    [$sc, $sa] = scope_sql('p'); $w = [$sc]; $a = $sa;
    foreach (['vessel' => 'p.vessel_id', 'project' => 'p.id', 'customer' => 'p.customer_id', 'pm' => 'p.pm_user_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $a[] = (int)$f[$k]; }
    $where = implode(' AND ', $w);
    $defs = function_exists('of_status_defs') ? of_status_defs() : []; $appr = array_keys(array_filter($defs, fn($d) => in_array($d['behaves'], ['approved', 'closed_won'], true))) ?: ['approved'];
    $ph = implode(',', array_fill(0, count($appr), '?'));
    $P = [];
    foreach (all("SELECT p.id, p.project_code, p.name, p.status, p.vessel_id, v.name AS vessel, c.name AS customer_name, cu.full_name AS pm_name, COUNT(o.id) n_offers, COALESCE(SUM(o.amount),0) offered_net, COALESCE(SUM(COALESCE(NULLIF(o.gross_total, 0), o.amount * (1 + COALESCE(o.vat_pct,0)/100))),0) offered_gross, MAX(o.currency) cur
                  FROM projects p JOIN offers o ON o.project_id = p.id AND o.status IN ({$ph}) AND o.converted_to IS NULL LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_users cu ON cu.id = p.pm_user_id
                  WHERE {$where} GROUP BY p.id", array_merge($appr, $a)) as $r) $P[(int)$r['id']] = $r + ['n_pi' => 0, 'invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0, 'n_overdue' => 0];
    foreach (all("SELECT p.id, p.project_code, p.name, p.status, p.vessel_id, v.name AS vessel, c.name AS customer_name, cu.full_name AS pm_name, pi.id AS pi_id, pi.status AS pi_status, pi.gross, pi.due_date, pi.currency,
                         ((SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp WHERE pp.proforma_id = pi.id) + (SELECT COALESCE(SUM(x.paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id AND NOT EXISTS (SELECT 1 FROM proforma_payments pq WHERE pq.invoice_id = x.id))) AS paid_sum
                  FROM proformas pi JOIN projects p ON p.id = pi.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_users cu ON cu.id = p.pm_user_id
                  WHERE pi.status NOT IN ('draft','cancelled') AND {$where}", $a) as $r) {
        $id = (int)$r['id'];
        if (!isset($P[$id])) $P[$id] = ['id' => $id, 'project_code' => $r['project_code'], 'name' => $r['name'], 'status' => $r['status'], 'vessel_id' => $r['vessel_id'], 'vessel' => $r['vessel'], 'customer_name' => $r['customer_name'], 'pm_name' => $r['pm_name'], 'n_offers' => 0, 'offered_net' => 0.0, 'offered_gross' => 0.0, 'cur' => $r['currency'], 'n_pi' => 0, 'invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0, 'n_overdue' => 0];
        $g = (float)$r['gross']; $paid = min($g, (float)$r['paid_sum']); $open = max(0.0, $g - (float)$r['paid_sum']);
        $P[$id]['n_pi']++; $P[$id]['invoiced'] += $g; $P[$id]['collected'] += $paid; $P[$id]['outstanding'] += $open; if (!$P[$id]['cur']) $P[$id]['cur'] = $r['currency'];
        if ($open > 0.005 && in_array($r['pi_status'], COL_OPEN, true) && $r['due_date'] && $r['due_date'] < date('Y-m-d')) { $P[$id]['overdue'] += $open; $P[$id]['n_overdue']++; }
    }
    foreach ($P as &$p) { $p['to_invoice'] = max(0.0, (float)$p['offered_gross'] - $p['invoiced']); $p['cur'] = $p['cur'] ?: 'EUR'; } unset($p);
    usort($P, fn($x, $y) => [(string)$x['vessel'], (string)$x['project_code']] <=> [(string)$y['vessel'], (string)$y['project_code']]);
    return array_values($P);
}
/* ------------------------------------------------------------------ follow-ups */
function col_followups(int $piId): array { return col_has() ? all('SELECT * FROM pi_followups WHERE proforma_id = ? ORDER BY id DESC', [$piId]) : []; }
function col_followup_save(int $piId, ?string $next, ?string $promise, string $note): void {
    if (!col_can()) throw new RuntimeException(t('err.noaccess'));
    [$sc, $sa] = scope_sql('p'); $pi = one("SELECT pi.id, pi.pi_no FROM proformas pi JOIN projects p ON p.id = pi.project_id WHERE pi.id = ? AND {$sc}", array_merge([$piId], $sa)); if (!$pi) throw new RuntimeException(t('err.notfound'));
    $d = fn(?string $v) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : null;
    $next = $d($next); $promise = $d($promise); $note = trim(mb_substr($note, 0, 300));
    if (!$next && !$promise && $note === '') throw new RuntimeException(t('col.fu_empty'));
    $u = current_user();
    q('INSERT INTO pi_followups (proforma_id, next_at, promise_at, note, user_id, user_name) VALUES (?,?,?,?,?,?)', [$piId, $next, $promise, $note !== '' ? $note : null, (int)$u['id'], (string)$u['full_name']]);
    if (function_exists('of_event') && ($oid = (int) scalar('SELECT offer_id FROM proformas WHERE id = ?', [$piId]))) { try { of_event($oid, 'followup', $pi['pi_no'] . ' · ' . trim(($promise ? t('col.promise') . ' ' . fmt_date($promise) . ' · ' : '') . ($next ? t('col.next') . ' ' . fmt_date($next) . ' · ' : '') . $note, " ·")); } catch (Throwable $e) {} }   // the offer's trail keeps the follow-up too
}
/* ------------------------------------------------------------------ Excel */
function col_xlsx(array $open, array $projects, array $paid, array $S, string $from, string $to): void {
    require_once __DIR__ . '/xlsxw.php'; $cur = $S['cur'];
    $sum = [[t('fs.outstanding'), round($S['outstanding'], 2)], [t('fs.overdue'), round($S['overdue'], 2)], [t('col.expected_30'), round($S['expected'], 2)], [t('col.late_total'), round($S['late']['amount'], 2)], [t('col.collected_30'), round($S['collected'], 2)], [t('col.promised'), round($S['promised'], 2)], [t('col.promise_missed'), round($S['missed'], 2)]];
    foreach (COL_BUCKETS as $b) $sum[] = [t('col.b_' . $b), round($S['buckets'][$b], 2)];
    foreach ($S['weeks'] as $i => $w) $sum[] = [t('col.week_n', ['n' => $i + 1]) . ' ' . fmt_date($w['from']) . ' – ' . fmt_date($w['to']), round($w['amount'], 2)];
    $op = []; foreach ($open as $r) $op[] = [(string)$r['vessel'], (string)$r['project_code'], (string)$r['customer_name'], (string)$r['pi_no'], (string)$r['title'], (string)$r['offer_no'], (string)($r['invoice_nos'] ?? ''), (string)$r['issue_date'], (string)$r['due_date'], $r['age'], t('col.b_' . $r['bucket']), round((float)$r['gross'], 2), round((float)$r['paid_sum'], 2), round($r['open'], 2), (string)($r['fu_promise'] ?? ''), (string)($r['fu_next'] ?? ''), (string)($r['fu_note'] ?? ''), (string)($r['fu_by'] ?? ''), (string)($r['pm_name'] ?? ''), (string)($r['party_name'] ?? '')];
    $pr = []; foreach ($projects as $p) $pr[] = [(string)$p['vessel'], (string)$p['project_code'], (string)$p['name'], (string)$p['customer_name'], (string)$p['pm_name'], t('pr.' . $p['status']), $p['n_offers'], round((float)$p['offered_net'], 2), round((float)$p['offered_gross'], 2), $p['n_pi'], round($p['invoiced'], 2), round($p['collected'], 2), round($p['outstanding'], 2), round($p['overdue'], 2), round($p['to_invoice'], 2)];
    $pa = []; foreach ($paid as $r) $pa[] = [(string)$r['paid_on'], (string)$r['vessel'], (string)$r['project_code'], (string)$r['customer_name'], (string)$r['pi_no'], (string)($r['invoice_no'] ?? ''), round((float)$r['amount'], 2), t('pay.m_' . $r['method']), (string)($r['reference'] ?? ''), (string)($r['note'] ?? ''), (string)($r['by_name'] ?? '')];
    xlsx_download('GAS-COL-' . date('Y-m-d') . '.xlsx', [
        ['name' => 'Summary', 'first' => true, 'header' => [t('fr.item'), $cur], 'rows' => $sum],
        ['name' => 'Open items', 'header' => [t('th.vessel'), t('pr.one'), t('nav.customers'), t('pi.no'), t('pi.description'), t('of.no'), t('pi.invoice_no'), t('pi.issue_date'), t('pi.due'), t('col.age'), t('col.bucket'), t('fr.gross'), t('pi.paid_lbl'), t('fr.open'), t('col.promise'), t('col.next'), t('pd.note'), t('th.by'), 'PM', t('pty.party')], 'rows' => $op],
        ['name' => 'Projects', 'header' => [t('th.vessel'), t('pr.code'), t('pr.one'), t('nav.customers'), 'PM', t('th.status'), t('of.title'), t('fr.offered_net'), t('fr.offered_gross'), t('pi.title'), t('fs.invoiced'), t('fs.collected'), t('fs.outstanding'), t('fs.overdue'), t('fr.to_invoice')], 'rows' => $pr],
        ['name' => 'Collected ' . $from . ' ' . $to, 'header' => [t('th.date'), t('th.vessel'), t('pr.one'), t('nav.customers'), t('pi.no'), t('pi.invoice_no'), t('pt.amount'), t('pay.method'), t('pay.reference'), t('pd.note'), t('th.by')], 'rows' => $pa],
    ]);
}

/* ------------------------------------------------------------------ statement of account (R78-002) */
/** Vessels that have proformas (for the picker): id, name, customer, open amount, count. */
function col_statement_vessels(array $f = []): array {
    [$sc, $sa] = scope_sql('p');
    return all("SELECT v.id, v.name, c.name AS customer_name, COUNT(pi.id) n, COALESCE(SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') THEN pi.gross ELSE 0 END),0) gross_open
                FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN vessels v ON v.id = pi.vessel_id LEFT JOIN customers c ON c.id = v.customer_id WHERE pi.status NOT IN ('draft','cancelled') AND {$sc} GROUP BY v.id, v.name, c.name ORDER BY c.name, v.name", $sa);
}
/** R95-005: billing parties that have ledger rows (for the picker): id, name, documents, open amount. */
function col_statement_parties(): array {
    [$sc, $sa] = scope_sql('p');
    try { return all("SELECT co.id, co.name, co.vat_no, COUNT(pi.id) n, COUNT(DISTINCT pi.vessel_id) n_vessels, COALESCE(SUM(CASE WHEN pi.status IN ('issued','sent','partly_paid') THEN " . PI_OPEN_SQL . " ELSE 0 END),0) open_sum
                FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN companies co ON co.id = pi.billing_org_id WHERE pi.status NOT IN ('draft','cancelled') AND {$sc} GROUP BY co.id, co.name, co.vat_no ORDER BY co.name", $sa); } catch (Throwable $e) { return []; }
}
/** Address block of an organisation for the statement: its default invoicing identity when it has one, else the organisation record. */
function col_party(int $orgId): ?array {
    $o = one('SELECT id, name, address, postal_code, city, country, vat_no, email, customer_id FROM companies WHERE id = ?', [$orgId]); if (!$o) return null;
    $iv = null; if (function_exists('iv_list')) { try { foreach (iv_list('company', $orgId, true) as $r) { if (!$iv || !empty($r['is_default'])) $iv = $r; if (!empty($r['is_default'])) break; } } catch (Throwable $e) { $iv = null; } }
    return ['id' => (int)$o['id'], 'name' => (string)(($iv['legal_name'] ?? '') ?: $o['name']), 'org_name' => (string)$o['name'], 'address' => (string)(($iv['address'] ?? '') ?: ($o['address'] ?? '')), 'postal' => (string)(($iv['postal_code'] ?? '') ?: ($o['postal_code'] ?? '')), 'city' => (string)(($iv['city'] ?? '') ?: ($o['city'] ?? '')),
            'country' => (string)(($iv['country'] ?? '') ?: ($o['country'] ?? '')), 'vat' => (string)(($iv['vat_no'] ?? '') ?: ($o['vat_no'] ?? '')), 'email' => (string)(($iv['email'] ?? '') ?: ($o['email'] ?? '')), 'customer_id' => (int)($o['customer_id'] ?? 0), 'iv' => $iv];
}
/** The statement: one block per vessel — every ledger document (not draft / cancelled) up to $asOf with its payments, totals, aging; grand totals.
 *  R95-005: with $orgId it is the statement of a BILLING PARTY — every document billed to that organisation, vessels (and "no vessel") as sections,
 *  optionally narrowed to the ticked vessels. Without it, the vessel statement: addressed to the party when the documents have one party, to the owner of the period otherwise. */
function col_statement(array $vesselIds, ?string $asOf = null, ?string $from = null, ?int $orgId = null): ?array {
    foreach ($vesselIds as $x) if (is_string($x) && preg_match('/^o(\d+)$/', $x, $mm)) $orgId = (int)$mm[1];   // attachment keys carry the party as "o<id>"
    $vesselIds = array_values(array_unique(array_filter(array_map('intval', $vesselIds)))); $orgId = $orgId ?: null; if (!$vesselIds && !$orgId) return null;
    $asOf = ($asOf && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) ? $asOf : date('Y-m-d'); $from = ($from && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : null;
    [$sc, $sa] = scope_sql('p'); $in = implode(',', $vesselIds ?: [0]);
    $party = $orgId ? col_party($orgId) : null; if ($orgId && !$party) return null;
    $w = ($orgId ? 'pi.billing_org_id = ' . (int)$orgId . ($vesselIds ? " AND pi.vessel_id IN ({$in})" : '') : "pi.vessel_id IN ({$in})");
    $rows = all(COL_SELECT . " WHERE {$w} AND pi.status NOT IN ('draft','cancelled') AND pi.issue_date <= ? AND {$sc}" . ($from ? ' AND pi.issue_date >= ?' : '') . ' ORDER BY v.name, pi.vessel_id, p.project_code, pi.issue_date, pi.id', array_merge([$asOf], $sa, $from ? [$from] : []));
    $vin = $orgId ? implode(',', array_unique(array_merge([0], array_map(fn($r) => (int)$r['vessel_id'], $rows), $vesselIds))) : $in;
    $vs = all("SELECT v.*, c.name AS customer_name, c.address AS c_address, c.city AS c_city, c.postal_code AS c_postal, c.country AS c_country, c.vat_no AS c_vat, c.email AS c_email FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id IN ({$vin}) ORDER BY " . ($orgId ? '' : 'c.name, ') . 'v.name');
    if ($orgId && array_filter($rows, fn($r) => !(int)$r['vessel_id'])) $vs[] = ['id' => 0, 'name' => t('soa.no_vessel'), 'imo' => null, 'customer_id' => $party['customer_id'], 'customer_name' => $party['org_name'], 'c_address' => null, 'c_city' => null, 'c_postal' => null, 'c_country' => null, 'c_vat' => null, 'c_email' => null];
    if (!$vs) return null;
    $ids = array_map(fn($r) => (int)$r['id'], $rows); $pay = []; $inv = []; $crIn = []; $crOut = [];
    if ($ids) {   // R95-004: payments on each ledger row (invoice payments on the invoice, advances on the proforma); links = the invoices settling a proforma
        $pin = implode(',', $ids);
        foreach (all("SELECT pp.*, NULL AS invoice_no FROM proforma_payments pp WHERE pp.proforma_id IN ({$pin}) AND pp.paid_on <= ? ORDER BY pp.paid_on, pp.id", [$asOf]) as $p) $pay[(int)$p['proforma_id']][] = $p;
        // R95-006: credit notes applied to an invoice (dated by the credit note) reduce what is open on it; what a credit note applied reduces its own (negative) balance
        if (function_exists('cn_has') && cn_has()) foreach (all("SELECT k.credit_id, k.invoice_id, k.amount FROM pi_credits k JOIN proformas x ON x.id = k.credit_id WHERE (k.invoice_id IN ({$pin}) OR k.credit_id IN ({$pin})) AND x.issue_date <= ? AND x.status <> 'cancelled'", [$asOf]) as $k) { $crIn[(int)$k['invoice_id']] = ($crIn[(int)$k['invoice_id']] ?? 0.0) + (float)$k['amount']; $crOut[(int)$k['credit_id']] = ($crOut[(int)$k['credit_id']] ?? 0.0) + (float)$k['amount']; }
        foreach (all("SELECT l.proforma_id, l.amount, x.id, x.pi_no AS invoice_no, x.issue_date AS invoice_date, x.gross, (SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp WHERE pp.proforma_id = x.id AND pp.paid_on <= ?) AS paid_amount FROM pi_links l JOIN proformas x ON x.id = l.invoice_id WHERE l.proforma_id IN ({$pin}) ORDER BY x.issue_date, x.id", [$asOf]) as $x) $inv[(int)$x['proforma_id']][] = $x;
    }
    $t0 = strtotime($asOf); $G = ['invoiced' => 0.0, 'paid' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'n' => 0, 'n_open' => 0, 'buckets' => array_fill_keys(COL_BUCKETS, 0.0)]; $cur = 'EUR'; $out = [];
    foreach ($vs as $v) {
        $V = $v + ['items' => [], 'invoiced' => 0.0, 'paid' => 0.0, 'open' => 0.0, 'overdue' => 0.0, 'n' => 0, 'n_open' => 0, 'buckets' => array_fill_keys(COL_BUCKETS, 0.0), 'iv' => null, 'cur' => 'EUR'];
        foreach ($rows as $r) {
            if ((int)$r['vessel_id'] !== (int)$v['id']) continue;
            $ps = $pay[(int)$r['id']] ?? []; $paidTo = 0.0; foreach ($ps as $p) $paidTo += (float)$p['amount'];
            $links = $inv[(int)$r['id']] ?? []; $invTo = 0.0; foreach ($links as $x) if ((string)$x['invoice_date'] <= $asOf) $invTo += (float)$x['amount'];
            if ($r['kind'] !== 'invoice' && $links && $invTo + 0.005 >= (float)$r['gross'] && $paidTo <= 0.005) continue;   // fully invoiced proforma: its invoices are the statement lines
            $isCr = $r['kind'] === 'credit'; $crd = $isCr ? ($crOut[(int)$r['id']] ?? 0.0) : ($r['kind'] === 'invoice' ? ($crIn[(int)$r['id']] ?? 0.0) : 0.0);
            $gross = in_array($r['kind'], ['invoice', 'credit'], true) ? (float)$r['gross'] : max(0.0, (float)$r['gross'] - $invTo);   // a partly invoiced proforma shows its not-yet-invoiced remainder; a credit note is negative
            $open = $isCr ? min(0.0, round($gross + $crd - $paidTo, 2)) : max(0.0, round($gross - $paidTo - $crd, 2)); $age = $r['due_date'] && !$isCr ? (int) floor(($t0 - strtotime($r['due_date'])) / 86400) : 0; $od = $open > 0.005 && $r['due_date'] && $r['due_date'] < $asOf;
            $r['payments'] = $ps; $r['invoices'] = $links; $r['paid_to'] = $paidTo; $r['credited'] = $crd; $r['gross'] = $gross; $r['open'] = $open; $r['age'] = $age; $r['overdue'] = $od; $r['bucket'] = abs($open) > 0.005 ? col_bucket($age) : null;
            $V['items'][] = $r; $V['n']++; $V['invoiced'] += $gross; $V['paid'] += $isCr ? $paidTo : min($gross, $paidTo); $V['open'] += $open; if (abs($open) > 0.005) { $V['n_open']++; $V['buckets'][$r['bucket']] += $open; } if ($od) $V['overdue'] += $open; $V['cur'] = $r['currency']; $cur = $r['currency'];
            if (!$V['iv'] && $r['id']) { $ivId = (int) scalar('SELECT invoicing_id FROM proformas WHERE id = ?', [(int)$r['id']]); if ($ivId && function_exists('iv_load')) $V['iv'] = iv_load($ivId); }
        }
        foreach (['invoiced', 'paid', 'open', 'overdue', 'n', 'n_open'] as $k) $G[$k] += $V[$k]; foreach (COL_BUCKETS as $b) $G['buckets'][$b] += $V['buckets'][$b];
        $out[] = $V;
    }
    $pid = $rows ? (int)$rows[0]['project_id'] : null;
    // R95-005 addressee: the billing party. Vessel statement: the one party of its documents; none recorded → the owner of the period (ownership history), else the vessel's customer; several → say so
    $parties = []; foreach ($rows as $r) if ((int)($r['billing_org_id'] ?? 0)) $parties[(int)$r['billing_org_id']] = (string)$r['party_name'];
    $multiParty = !$orgId && count($parties) > 1; $ownerThen = null;
    if (!$orgId && count($parties) === 1) $party = col_party((int)array_key_first($parties));
    if (!$orgId && !$parties && count($vs) === 1 && function_exists('vo_at') && ($o = vo_at((int)$vs[0]['id'], $asOf)) && !empty($o['company_id'])) { $party = col_party((int)$o['company_id']); $ownerThen = (string)$o['owner_name']; }
    $slug = fn(string $x) => mb_substr(preg_replace('/[^A-Za-z0-9]+/', '', $x) ?: 'P', 0, 18);
    $no = (function_exists('br_prefix') && $pid ? br_prefix($pid) : 'GAS') . '-SOA-' . str_replace('-', '', $asOf) . '-' . ($orgId ? $slug($party['org_name']) . ($vesselIds && count($vs) === 1 ? '-' . $slug((string)$vs[0]['name']) : '') : (count($vs) === 1 ? preg_replace('/[^A-Za-z0-9]+/', '', (string)$vs[0]['name']) : count($vs) . 'V'));
    return ['vessels' => $out, 'totals' => $G, 'cur' => $cur, 'as_of' => $asOf, 'from' => $from, 'doc_no' => $no, 'project_id' => $pid, 'customer_id' => $party ? (int)$party['customer_id'] : (int)($vs[0]['customer_id'] ?? 0), 'customer_name' => $party ? (string)$party['org_name'] : (string)($vs[0]['customer_name'] ?? ''), 'single' => count($vs) === 1,
            'multi_customer' => !$party && count(array_unique(array_column($vs, 'customer_id'))) > 1, 'party' => $party, 'by_party' => (bool)$orgId, 'multi_party' => $multiParty, 'parties' => $parties, 'owner_then' => $ownerThen];
}
function col_statement_pdf(array $D): string {
    require_once __DIR__ . '/pdf.php';
    $cur = $D['cur']; $loc = function_exists('et_customer_locale') && $D['customer_id'] ? et_customer_locale($D['customer_id']) : locale(); $T = fn(string $k, array $v = []) => t_in($k, $loc, $v);
    $m = fn(float $v) => number_format($v, 2, ',', '.') . ' ' . $cur;
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $red = '#9B2C2C'; $green = '#2E7D32';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0;
    $bd = function_exists('br_doc') ? br_doc($D['project_id']) : ['logo' => null, 'name' => 'Golden Automation Systems', 'lines' => [], 'footer' => ''];
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $D, $bd, $T) {
        $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16);
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper($T('soa.doc_title')));
        $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $D['doc_no'] . ' · ' . $T('soa.as_of', ['d' => fmt_date($D['as_of'])]));
        $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36;
    };
    $footer = function () use ($pdf, $L, $R, $D, $bd) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $D['doc_no']); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 276) { $footer(); $header(); } };
    $h1 = function (string $s) use ($pdf, $navy, $gold, $L, $R, &$y, $need) { $y += 3; $need(20); $pdf->setColor($navy); $pdf->setFont('B', 10.5); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.4); $pdf->line($L, $y + 6.5, $R, $y + 6.5); $y += 10; };
    $header(); $footer();
    // issuer / addressee
    $v0 = $D['vessels'][0];
    $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($L, $y, mb_strtoupper($T('pi.from'))); $pdf->text(110, $y, mb_strtoupper($T('soa.to'))); $y += 4.5;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $yl = $y; foreach (array_merge([(string)$bd['name']], $bd['lines']) as $ln0) foreach ($pdf->wrap($ln0, 88) as $ln) { $pdf->text($L, $yl, $ln); $yl += 4; }
    $P = $D['party'] ?? null;
    $addr = !empty($D['multi_party']) ? [$T('soa.several_parties')] : ($P ? array_values(array_filter([(string)$P['name'], (string)$P['address'], trim($P['postal'] . ' ' . $P['city'] . ' ' . $P['country']), $P['vat'] ? 'VAT ' . $P['vat'] : '', $T('soa.attn_accounting')])) : ($D['multi_customer'] ? [$T('soa.several_owners')] : array_values(array_filter([(string)($v0['customer_name'] ?? ''), (string)($v0['c_address'] ?? ''), trim((string)($v0['c_postal'] ?? '') . ' ' . (string)($v0['c_city'] ?? '') . ' ' . (string)($v0['c_country'] ?? '')), $v0['c_vat'] ? 'VAT ' . $v0['c_vat'] : '', $T('soa.attn_accounting')]))));
    $yr = $y; foreach ($addr as $ln0) foreach ($pdf->wrap($ln0, 85) as $ln) { $pdf->text(110, $yr, $ln); $yr += 4; }
    $y = max($yl, $yr) + 4;
    $pdf->setFont('R', 8.5); $pdf->setColor($ink); $intro = $T(!empty($D['by_party']) ? 'soa.intro_party' : 'soa.intro', ['d' => fmt_date($D['as_of']), 'v' => implode(', ', array_map(fn($v) => (string)$v['name'], $D['vessels']))]) . ($D['from'] ? ' ' . $T('soa.from_note', ['d' => fmt_date($D['from'])]) : '');
    $y += $pdf->paragraph($L, $y, $W, $intro, 4.2) + 3;
    // summary when several vessels
    if (!$D['single']) {
        $h1($T('soa.summary'));
        $cols = [[$T('v.one'), 68, 'L'], [$T('fs.invoiced'), 28, 'R'], [$T('pi.paid_lbl'), 28, 'R'], [$T('fr.open'), 28, 'R'], [$T('fs.overdue'), 28, 'R']];
        $hrow = function () use ($pdf, $cols, $navy, $head, $L, &$y) { $pdf->setFont('B', 7.5); $pdf->setColor($head); $pdf->rect($L, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $L; foreach ($cols as [$lab, $cw, $al]) { $pdf->text($x + 1.5, $y + 4.2, $lab, $al, $cw - 3); $x += $cw; } $y += 6; };
        $need(14); $hrow();
        foreach ($D['vessels'] as $V) { $need(6); $pdf->setFont('R', 8); $pdf->setColor($ink); $x = $L; foreach ([(string)$V['name'], $m($V['invoiced']), $m($V['paid']), $m($V['open']), $V['overdue'] > 0 ? $m($V['overdue']) : '—'] as $i => $val) { if ($i === 4 && $V['overdue'] > 0) $pdf->setColor($red); $pdf->text($x + 1.5, $y + 4.2, $val, $cols[$i][2], $cols[$i][1] - 3); $pdf->setColor($ink); $x += $cols[$i][1]; } $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($L, $y + 5.6, $L + 180, $y + 5.6); $y += 5.8; }
        $need(8); $pdf->setFont('B', 8.5); $pdf->setColor($navy); $x = $L; foreach ([$T('ob.c_total'), $m($D['totals']['invoiced']), $m($D['totals']['paid']), $m($D['totals']['open']), $D['totals']['overdue'] > 0 ? $m($D['totals']['overdue']) : '—'] as $i => $val) { $pdf->text($x + 1.5, $y + 4.5, $val, $cols[$i][2], $cols[$i][1] - 3); $x += $cols[$i][1]; } $y += 8;
    }
    // per vessel
    foreach ($D['vessels'] as $V) {
        $h1(($D['single'] ? $T('soa.account_of') . ' ' : '') . $V['name'] . ($V['imo'] ? ' · IMO ' . $V['imo'] : ''));
        if (!$V['items']) { $pdf->setFont('R', 8.5); $pdf->setColor($ink); $pdf->text($L, $y + 3, $T('soa.nothing')); $y += 8; continue; }
        $cols = [[$T('th.date'), 17, 'L'], [$T('pi.no'), 30, 'L'], [$T('pi.description'), 48, 'L'], [$T('pi.due'), 17, 'L'], [$T('fr.gross'), 24, 'R'], [$T('pi.paid_lbl'), 22, 'R'], [$T('fr.open'), 22, 'R']]; $cx = []; $xx = $L; foreach ($cols as $c) { $cx[] = $xx; $xx += $c[1]; }
        $hrow = function () use ($pdf, $cols, $navy, $head, $L, &$y) { $pdf->setFont('B', 7.2); $pdf->setColor($head); $pdf->rect($L, $y, array_sum(array_column($cols, 1)), 6, 'F'); $pdf->setColor($navy); $x = $L; foreach ($cols as [$lab, $cw, $al]) { $pdf->text($x + 1.5, $y + 4.2, $lab, $al, $cw - 3); $x += $cw; } $y += 6; };
        $need(16); $hrow(); $proj = null;
        foreach ($V['items'] as $r) {
            if ($r['project_id'] !== $proj) { $proj = $r['project_id']; $need(12); $pdf->setFont('B', 7.5); $pdf->setColor($navy); $pdf->text($L + 1.5, $y + 4, trim(($r['project_code'] ?: '') . ' — ' . $r['project_name'])); $y += 5.5; }
            $desc = (($r['kind'] ?? '') === 'credit' ? $T('cn.one') . ' · ' : (function_exists('pi_is_invoice') && pi_is_invoice($r) ? $T('pi.k_invoice') . ' · ' : '')) . (string)$r['title'] . (($r['kind'] ?? '') === 'invoice' && ($r['credited'] ?? 0) > 0.005 ? ' · ' . $T('cn.credited_short', ['v' => col_money((float)$r['credited'], (string)$r['currency'])]) : '') . ($r['offer_no'] ? ' · ' . $r['offer_no'] : '') . (!empty($r['settles_nos']) ? ' · ' . $T('pi.settles_short', ['n' => $r['settles_nos']]) : '') . (!pi_is_invoice($r) && $r['invoice_nos'] ? ' · ' . $T('pi.invoice_no') . ' ' . $r['invoice_nos'] : '');   // R94-001: direct invoices carry their kind
            $pdf->setFont('R', 7.4); $lines = $pdf->wrap($desc, 44); $noLines = $pdf->wrap((string)$r['pi_no'], 26); $hh = max(5.2, max(count($lines), count($noLines) + ($r['overdue'] ? 1 : 0)) * 3.6 + 1.6); $need($hh + 4 * count($r['payments']) + 6);
            $x = $L; $cells = [fmt_date($r['issue_date']), (string)$r['pi_no'], null, $r['due_date'] ? fmt_date($r['due_date']) : '—', $m((float)$r['gross']), $m($r['paid_to']), abs($r['open']) > 0.005 ? $m($r['open']) : '—'];
            foreach ($cells as $i => $val) { $pdf->setColor($i === 3 && $r['overdue'] ? $red : $ink); if ($i === 2) foreach ($lines as $k => $ln) $pdf->text($x + 1.5, $y + 3.6 + $k * 3.6, $ln); elseif ($i === 1) foreach ($noLines as $k => $ln) $pdf->text($x + 1.5, $y + 3.6 + $k * 3.6, $ln); else $pdf->text($x + 1.5, $y + 3.6, (string)$val, $cols[$i][2], $cols[$i][1] - 3); $x += $cols[$i][1]; }
            if ($r['overdue']) { $pdf->setFont('B', 6.6); $pdf->setColor($red); $pdf->text($cx[1] + 1.5, $y + 3.6 + 3.6 * count($noLines), $T('soa.overdue_days', ['n' => $r['age']])); }
            $y += $hh;
            foreach ($r['payments'] as $p) { $pdf->setFont('R', 7.2); $pdf->setColor($green); $pdf->text($L + 1.5, $y + 3.2, fmt_date($p['paid_on'])); $pdf->text($cx[1] + 1.5, $y + 3.2, '» ' . $T('soa.payment') . ' · ' . $T('pay.m_' . $p['method']) . ($p['reference'] ? ' · ' . $p['reference'] : '') . ($p['invoice_no'] ? ' · ' . $T('pi.invoice_no') . ' ' . $p['invoice_no'] : '')); $pdf->text($cx[5] + 1.5, $y + 3.2, '−' . $m((float)$p['amount']), 'R', 17); $y += 4; }
            $pdf->setColor($line); $pdf->setLineWidth(0.2); $pdf->line($L, $y + 0.6, $L + 180, $y + 0.6); $y += 1.2;
        }
        $need(22); $pdf->setFont('B', 7.6); $pdf->setColor($navy);
        $pdf->text($L + 1.5, $y + 4.5, $T('soa.vessel_total', ['n' => $V['n']])); foreach ([4 => $m($V['invoiced']), 5 => $m($V['paid']), 6 => $m($V['open'])] as $i => $val) $pdf->text($cx[$i] + 1.5, $y + 4.5, $val, 'R', $cols[$i][1] - 3); $y += 7;
        $pdf->setFont('R', 7.4); $pdf->setColor($V['overdue'] > 0 ? $red : $ink); $pdf->text($L + 1.5, $y + 3.5, $T('soa.aging_line', ['o' => $m($V['overdue']), 'a' => $m($V['buckets']['current']), 'b' => $m($V['buckets']['d30']), 'c' => $m($V['buckets']['d60']), 'd' => $m($V['buckets']['d90'] + $V['buckets']['d90p'])])); $y += 7;
        if ($V['iv'] && (($V['iv']['bank'] ?? '') || ($V['iv']['iban'] ?? ''))) { $need(16); $pdf->setFont('B', 8); $pdf->setColor($navy); $pdf->text($L, $y + 3, $T('pi.payment_to')); $y += 7; $pdf->setFont('R', 8); $pdf->setColor($ink); foreach (array_filter([(string)($V['iv']['legal_name'] ?? ''), ($V['iv']['bank'] ?? '') ? $T('iv.bank') . ': ' . $V['iv']['bank'] : '', ($V['iv']['iban'] ?? '') ? 'IBAN ' . $V['iv']['iban'] . (($V['iv']['swift'] ?? '') ? ' · SWIFT ' . $V['iv']['swift'] : '') : '']) as $ln) { $pdf->text($L, $y + 3, $ln); $y += 4; } $y += 2; }
    }
    $need(24); $y += 2; $pdf->setFont('R', 8.5); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $W, $T('soa.closing', ['o' => $m($D['totals']['open'])]), 4.2) + 4;
    $u = current_user(); $pdf->setFont('R', 8); $pdf->setColor('#6a7179'); $pdf->text($L, $y + 3, $T('ob.prepared_by') . ': ' . (string)($u['full_name'] ?? '') . ' · ' . $T('soa.accounting_dept') . ' · ' . $bd['name'] . ' · ' . fmt_date(date('Y-m-d')));
    return $pdf->bytes($D['doc_no']);
}
function col_statement_sheets(array $D): array {
    $cur = $D['cur'];
    $sum = []; foreach ($D['vessels'] as $V) $sum[] = [(string)$V['name'], (string)(($D['party']['org_name'] ?? '') ?: ($V['customer_name'] ?? '')), $V['n'], round($V['invoiced'], 2), round($V['paid'], 2), round($V['open'], 2), round($V['overdue'], 2), round($V['buckets']['current'], 2), round($V['buckets']['d30'], 2), round($V['buckets']['d60'], 2), round($V['buckets']['d90'], 2), round($V['buckets']['d90p'], 2)];
    $sum[] = [t('ob.c_total'), '', $D['totals']['n'], round($D['totals']['invoiced'], 2), round($D['totals']['paid'], 2), round($D['totals']['open'], 2), round($D['totals']['overdue'], 2), round($D['totals']['buckets']['current'], 2), round($D['totals']['buckets']['d30'], 2), round($D['totals']['buckets']['d60'], 2), round($D['totals']['buckets']['d90'], 2), round($D['totals']['buckets']['d90p'], 2)];
    $it = []; $pa = [];
    foreach ($D['vessels'] as $V) foreach ($V['items'] as $r) { $it[] = [(string)$V['name'], (string)$r['project_code'], function_exists('pi_kind_label') ? pi_kind_label($r) : '', (string)$r['pi_no'], (string)$r['title'], (string)$r['offer_no'], (string)($r['invoice_nos'] ?? ''), (string)$r['issue_date'], (string)$r['due_date'], $r['overdue'] ? $r['age'] : 0, round((float)$r['gross'], 2), round($r['paid_to'], 2), round($r['open'], 2), t('pi.s_' . $r['status'])]; foreach ($r['payments'] as $p) $pa[] = [(string)$V['name'], (string)$r['pi_no'], (string)$p['paid_on'], round((float)$p['amount'], 2), t('pay.m_' . $p['method']), (string)($p['reference'] ?? ''), (string)($p['invoice_no'] ?? '')]; }
    return [
        ['name' => 'Summary', 'first' => true, 'header' => [t('v.one'), !empty($D['party']) ? t('pty.party') : t('rp.customer'), 'PI', t('fs.invoiced') . ' ' . $cur, t('pi.paid_lbl'), t('fr.open'), t('fs.overdue'), t('col.b_current'), t('col.b_d30'), t('col.b_d60'), t('col.b_d90'), t('col.b_d90p')], 'rows' => $sum],
        ['name' => 'Items', 'header' => [t('v.one'), t('pr.one'), t('pi.kind'), t('pi.no'), t('pi.description'), t('of.no'), t('pi.invoice_no'), t('pi.issue_date'), t('pi.due'), t('soa.days_overdue'), t('fr.gross'), t('pi.paid_lbl'), t('fr.open'), t('th.status')], 'rows' => $it],
        ['name' => 'Payments', 'header' => [t('v.one'), t('pi.no'), t('th.date'), t('pt.amount'), t('pay.method'), t('pay.reference'), t('pi.invoice_no')], 'rows' => $pa],
    ];
}
function col_statement_xlsx(array $D): void { require_once __DIR__ . '/xlsxw.php'; xlsx_download($D['doc_no'] . '.xlsx', col_statement_sheets($D)); }
/** E-mail attachment key st:<ids>:<pdf|xlsx>[:asof[:from]] — bytes + name + mime (R78-002). */
function col_em_attachment(array $p): ?array {
    if (!col_can()) return null;
    $D = col_statement(explode(',', (string)($p[1] ?? '')), $p[3] ?? null, $p[4] ?? null); if (!$D) return null;
    if (($p[2] ?? 'pdf') === 'xlsx') { require_once __DIR__ . '/xlsxw.php'; return [xlsx_bytes(col_statement_sheets($D)), $D['doc_no'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']; }
    return [col_statement_pdf($D), $D['doc_no'] . '.pdf', 'application/pdf'];
}

/* ---- R94-001 accounting checks → xlsx ---- */
function col_checks_xlsx(array $cov, array $mis, array $unv, int $days): void {
    require_once __DIR__ . '/xlsxw.php';
    $c = []; foreach ($cov as $r) $c[] = [(string)$r['project_code'], (string)$r['project_name'], (string)$r['code'], (string)$r['name'], (string)$r['vessel'], (string)$r['customer_name'], t($r['status'] === 'completed' ? 'pr.completed' : 'pr.active'), (string)($r['completed_at'] ? substr((string)$r['completed_at'], 0, 10) : ''), round((float)$r['inv_contract'], 2), round((float)$r['inv_invoiced'], 2), (int)($r['invoiced_pct'] ?? 0), round((float)$r['collected'], 2), t('inv.' . ($r['invoicing'] ?? 'none')), $r['invoicing_override'] ? t('inv.' . $r['invoicing_override']) : ''];
    $x = []; foreach ($mis as $r) { $it = 0.0; $nos = []; foreach ($r['invoices'] ?? [] as $i) { $it += (float)$i['amount']; $nos[] = $i['invoice_no']; } $x[] = [(string)$r['pi_no'], (string)$r['title'], (string)$r['project_code'], (string)$r['sub_code'], (string)$r['vessel'], (string)$r['issue_date'], round((float)$r['gross'], 2), round($it, 2), round($it - (float)$r['gross'], 2), implode(', ', $nos), t('pi.s_' . $r['status'])]; }
    $u = []; foreach ($unv as $r) $u[] = [(string)$r['pi_no'], (string)$r['title'], (string)$r['vessel'], (string)$r['project_code'], (string)$r['sub_code'], (string)$r['issue_date'], (int) floor((time() - strtotime($r['issue_date'])) / 86400), round((float)$r['gross'], 2), round((float)$r['paid_sum'], 2), t('pi.s_' . $r['status'])];
    xlsx_download('GAS-CHK-' . date('Ymd') . '.xlsx', [
        ['name' => 'Coverage', 'first' => true, 'header' => [t('pr.code'), t('pr.one'), t('sp.code'), t('sp.one'), t('v.one'), t('rp.customer'), t('th.status'), t('pr.completed'), t('chk.contract'), t('fs.invoiced'), '%', t('chk.collected'), t('inv.title'), t('chk.override')], 'rows' => $c],
        ['name' => 'Mismatch', 'header' => [t('pi.no'), t('pi.description'), t('pr.code'), t('sp.code'), t('v.one'), t('pi.issue_date'), t('pi.gross'), t('chk.inv_total'), t('chk.diff'), t('pi.invoice_no'), t('th.status')], 'rows' => $x],
        ['name' => 'Not invoiced ' . $days . 'd', 'header' => [t('pi.no'), t('pi.description'), t('v.one'), t('pr.code'), t('sp.code'), t('pi.issue_date'), t('chk.days'), t('pi.gross'), t('pi.paid_lbl'), t('th.status')], 'rows' => $u],
    ]);
}

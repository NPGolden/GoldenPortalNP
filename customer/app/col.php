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

const COL_SELECT = "SELECT pi.id, pi.pi_no, pi.title, pi.term_n, pi.issue_date, pi.due_date, pi.gross, pi.currency, pi.status, pi.sent_at, pi.paid_at, pi.offer_id, pi.project_id, pi.subproject_id, pi.vessel_id,
                   o.offer_no, p.project_code, p.name AS project_name, p.pm_user_id, p.customer_id, s.code AS sub_code, v.name AS vessel, c.name AS customer_name, cu.full_name AS pm_name,
                   ((SELECT COALESCE(SUM(pp.amount),0) FROM proforma_payments pp WHERE pp.proforma_id = pi.id) + (SELECT COALESCE(SUM(x.paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id AND NOT EXISTS (SELECT 1 FROM proforma_payments pq WHERE pq.invoice_id = x.id))) AS paid_sum,
                   (SELECT MAX(pp.paid_on) FROM proforma_payments pp WHERE pp.proforma_id = pi.id) AS last_paid_on,
                   (SELECT GROUP_CONCAT(x.invoice_no ORDER BY x.id SEPARATOR ', ') FROM proforma_invoices x WHERE x.proforma_id = pi.id) AS invoice_nos,
                   fu.next_at AS fu_next, fu.promise_at AS fu_promise, fu.note AS fu_note, fu.user_name AS fu_by, fu.created_at AS fu_at
            FROM proformas pi JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN subprojects s ON s.id = pi.subproject_id LEFT JOIN vessels v ON v.id = pi.vessel_id
            LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN customer_users cu ON cu.id = p.pm_user_id
            LEFT JOIN pi_followups fu ON fu.id = (SELECT f2.id FROM pi_followups f2 WHERE f2.proforma_id = pi.id ORDER BY f2.id DESC LIMIT 1)";

function col_where(array $f, string $alias = 'pi'): array {
    $w = []; $a = [];
    foreach (['vessel' => $alias . '.vessel_id', 'project' => $alias . '.project_id', 'customer' => 'p.customer_id', 'pm' => 'p.pm_user_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $a[] = (int)$f[$k]; }
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
        $open = round((float)$r['gross'] - (float)$r['paid_sum'], 2); if ($open <= 0.005) continue;
        $due = $r['due_date'] ?: null; $age = $due ? (int) floor(($t0 - strtotime($due)) / 86400) : (int) floor(($t0 - strtotime((string)$r['issue_date'])) / 86400) - 30;
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
                FROM proforma_payments pp JOIN proformas pi ON pi.id = pp.proforma_id JOIN offers o ON o.id = pi.offer_id JOIN projects p ON p.id = pi.project_id LEFT JOIN vessels v ON v.id = pi.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
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
    $op = []; foreach ($open as $r) $op[] = [(string)$r['vessel'], (string)$r['project_code'], (string)$r['customer_name'], (string)$r['pi_no'], (string)$r['title'], (string)$r['offer_no'], (string)($r['invoice_nos'] ?? ''), (string)$r['issue_date'], (string)$r['due_date'], $r['age'], t('col.b_' . $r['bucket']), round((float)$r['gross'], 2), round((float)$r['paid_sum'], 2), round($r['open'], 2), (string)($r['fu_promise'] ?? ''), (string)($r['fu_next'] ?? ''), (string)($r['fu_note'] ?? ''), (string)($r['fu_by'] ?? ''), (string)($r['pm_name'] ?? '')];
    $pr = []; foreach ($projects as $p) $pr[] = [(string)$p['vessel'], (string)$p['project_code'], (string)$p['name'], (string)$p['customer_name'], (string)$p['pm_name'], t('pr.' . $p['status']), $p['n_offers'], round((float)$p['offered_net'], 2), round((float)$p['offered_gross'], 2), $p['n_pi'], round($p['invoiced'], 2), round($p['collected'], 2), round($p['outstanding'], 2), round($p['overdue'], 2), round($p['to_invoice'], 2)];
    $pa = []; foreach ($paid as $r) $pa[] = [(string)$r['paid_on'], (string)$r['vessel'], (string)$r['project_code'], (string)$r['customer_name'], (string)$r['pi_no'], (string)($r['invoice_no'] ?? ''), round((float)$r['amount'], 2), t('pay.m_' . $r['method']), (string)($r['reference'] ?? ''), (string)($r['note'] ?? ''), (string)($r['by_name'] ?? '')];
    xlsx_download('GAS-COL-' . date('Y-m-d') . '.xlsx', [
        ['name' => 'Summary', 'first' => true, 'header' => [t('fr.item'), $cur], 'rows' => $sum],
        ['name' => 'Open items', 'header' => [t('th.vessel'), t('pr.one'), t('nav.customers'), t('pi.no'), t('pi.description'), t('of.no'), t('pi.invoice_no'), t('pi.issue_date'), t('pi.due'), t('col.age'), t('col.bucket'), t('fr.gross'), t('pi.paid_lbl'), t('fr.open'), t('col.promise'), t('col.next'), t('pd.note'), t('th.by'), 'PM'], 'rows' => $op],
        ['name' => 'Projects', 'header' => [t('th.vessel'), t('pr.code'), t('pr.one'), t('nav.customers'), 'PM', t('th.status'), t('of.title'), t('fr.offered_net'), t('fr.offered_gross'), t('pi.title'), t('fs.invoiced'), t('fs.collected'), t('fs.outstanding'), t('fs.overdue'), t('fr.to_invoice')], 'rows' => $pr],
        ['name' => 'Collected ' . $from . ' ' . $to, 'header' => [t('th.date'), t('th.vessel'), t('pr.one'), t('nav.customers'), t('pi.no'), t('pi.invoice_no'), t('pt.amount'), t('pay.method'), t('pay.reference'), t('pd.note'), t('th.by')], 'rows' => $pa],
    ]);
}

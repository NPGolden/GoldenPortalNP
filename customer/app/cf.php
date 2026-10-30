<?php
/**
 * Release D3 (GAS-CP-R45-001): cash flow per project and for the company.
 *
 * Period by month or by week (ISO). For every period:
 *   IN  actual    paid accounting invoices (proforma_invoices.paid_amount by paid_at)
 *   IN  forecast  the open part of issued / sent / partly-paid proformas by due date, plus the instalments of approved
 *                 offers not yet issued (placed at approval date + due days, never before today)
 *   OUT actual    manhour cost of approved / invoiced timesheet hours (hours × discipline / branch cost, by work date),
 *                 parts invoices (sp_costs kind invoice, by cost date), subcontracting lines delivered (amount by delivered date)
 *   OUT forecast  parts orders not yet invoiced (sp_costs kind order, by cost date), subcontracting lines with a PO not yet
 *                 delivered (amount by due date), remaining budgeted hours × average rate spread from today to the deadline
 * Past periods carry actuals (forecast is left out once a period is closed); future periods carry forecasts.
 * Visible to administrators and accounts with the "Cash flow" permission, with financial visibility.
 */
declare(strict_types=1);

function cf_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('pi_has') && pi_has() && (bool) scalar("SHOW COLUMNS FROM customer_users LIKE 'can_cashflow'"); } catch (Throwable $e) { $h = false; } } return $h; }
function cf_can(): bool { static $v = null; if ($v === null) { $u = current_user(); $v = is_staff() && cf_has() && fin_visible() && (is_cp_admin() || (bool) scalar('SELECT can_cashflow FROM customer_users WHERE id = ?', [(int)($u['id'] ?? 0)])); } return $v; }
function cf_money(float $v, string $cur = 'EUR'): string { return number_format($v, 0, ',', '.') . ' ' . $cur; }

/** Period key of a date: 2026-11 or 2026-W45. */
function cf_key(string $date, string $g): string { $t = strtotime($date); return $g === 'w' ? date('o-\WW', $t) : date('Y-m', $t); }
function cf_label(string $key, string $g): string { if ($g === 'w') { [$y, $w] = explode('-W', $key); $m = (new DateTime())->setISODate((int)$y, (int)$w); return 'W' . $w . ' · ' . $m->format('d M'); } return date('M Y', strtotime($key . '-01')); }
/** The list of period keys from $from to $to. */
function cf_periods(string $from, string $to, string $g): array {
    $out = []; $d = new DateTime($from); $end = new DateTime($to);
    if ($g === 'w') { $d->modify('monday this week'); while ($d <= $end && count($out) < 120) { $out[] = $d->format('o-\WW'); $d->modify('+1 week'); } }
    else { $d->modify('first day of this month'); while ($d <= $end && count($out) < 60) { $out[] = $d->format('Y-m'); $d->modify('+1 month'); } }
    return $out;
}
function cf_range(string $g, int $back = 3, int $fwd = 9): array { return $g === 'w' ? [date('Y-m-d', strtotime("-{$back} weeks")), date('Y-m-d', strtotime('+' . ($fwd * 7 + 6) . ' days'))] : [date('Y-m-01', strtotime("-{$back} months")), date('Y-m-t', strtotime("+{$fwd} months"))]; }

/** Scope SQL for the projects in the cash flow: one project, or filters (branch, vessel, pm). Returns [sql on p, args]. */
function cf_scope(array $f): array {
    $w = []; $a = [];
    if (!empty($f['project'])) { $w[] = 'p.id = ?'; $a[] = (int)$f['project']; }
    if (!empty($f['vessel'])) { $w[] = 'p.vessel_id = ?'; $a[] = (int)$f['vessel']; }
    if (!empty($f['pm'])) { $w[] = 'p.pm_user_id = ?'; $a[] = (int)$f['pm']; }
    if (!empty($f['branch']) && br_has()) { $w[] = '(p.branch_id = ? OR (p.branch_id IS NULL AND ? = ?))'; $d = (int)br_default()['id']; array_push($a, (int)$f['branch'], (int)$f['branch'], $d); }
    if (empty($f['completed'])) $w[] = "p.status <> 'completed'";
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    return [implode(' AND ', $w), $a];
}
/** The series: ['periods' => [...], 'rows' => [key => [in_a, in_f, out_a, out_f, net, cum, items => [...]]], 'totals', 'drivers'] */
function cf_series(array $f, string $g, ?string $from = null, ?string $to = null): array {
    [$df, $dt] = cf_range($g); $from = $from ?: $df; $to = $to ?: $dt; $today = date('Y-m-d'); $nowKey = cf_key($today, $g);
    $keys = cf_periods($from, $to, $g); $R = []; foreach ($keys as $k) $R[$k] = ['in_a' => 0.0, 'in_f' => 0.0, 'out_a' => 0.0, 'out_f' => 0.0, 'items' => []];
    [$sc, $sa] = cf_scope($f); $pids = array_map('intval', array_column(all("SELECT p.id FROM projects p WHERE {$sc}", $sa), 'id'));
    $D = ['in_paid' => 0.0, 'in_pi' => 0.0, 'in_terms' => 0.0, 'out_hours' => 0.0, 'out_parts_inv' => 0.0, 'out_parts_ord' => 0.0, 'out_subs_del' => 0.0, 'out_subs_due' => 0.0, 'out_remaining' => 0.0];
    if (!$pids) return ['periods' => $keys, 'rows' => $R, 'totals' => [], 'drivers' => $D, 'from' => $from, 'to' => $to, 'now' => $nowKey, 'n_projects' => 0];
    $in = implode(',', $pids);
    $put = function (string $date, string $col, float $amt, string $what) use (&$R, $g, $from, $to, &$D) { if ($amt == 0.0 || $date < $from || $date > $to) return; $k = cf_key($date, $g); if (!isset($R[$k])) return; $R[$k][$col] += $amt; $R[$k]['items'][] = [$col, $amt, $what]; };
    // IN actual: paid invoices
    foreach (all("SELECT x.paid_amount, x.paid_at, x.invoice_no, pi.pi_no FROM proforma_invoices x JOIN proformas pi ON pi.id = x.proforma_id WHERE pi.project_id IN ({$in}) AND x.paid_amount > 0 AND x.paid_at IS NOT NULL") as $r) { $put((string)$r['paid_at'], 'in_a', (float)$r['paid_amount'], $r['invoice_no'] . ' (' . $r['pi_no'] . ')'); $D['in_paid'] += (float)$r['paid_amount']; }
    // IN forecast: open proformas by due date (overdue → this period)
    foreach (all("SELECT pi.pi_no, pi.gross, pi.due_date, pi.issue_date, (SELECT COALESCE(SUM(paid_amount),0) FROM proforma_invoices x WHERE x.proforma_id = pi.id) AS paid FROM proformas pi WHERE pi.project_id IN ({$in}) AND pi.status IN ('issued','sent','partly_paid')") as $r) { $open = (float)$r['gross'] - (float)$r['paid']; if ($open <= 0) continue; $d = max((string)($r['due_date'] ?: $r['issue_date'] ?: $today), $today); $put($d, 'in_f', $open, $r['pi_no']); $D['in_pi'] += $open; }
    // IN forecast: instalments of approved offers not yet issued
    $defs = of_status_defs(); $appr = array_keys(array_filter($defs, fn($d) => in_array($d['behaves'], ['approved'], true))) ?: ['approved'];
    foreach (all("SELECT o.id, o.offer_no, o.amount, o.status_at, o.subproject_id FROM offers o WHERE o.project_id IN ({$in}) AND o.status IN (" . implode(',', array_map(fn($s) => db()->quote($s), $appr)) . ") AND o.converted_to IS NULL") as $o) {
        foreach (pt_of((int)$o['id']) as $t) { if (scalar("SELECT COUNT(*) FROM proformas WHERE offer_id = ? AND term_n = ? AND status <> 'cancelled'", [(int)$o['id'], (int)$t['n']])) continue; $amt = pt_amount($t, $o); $d = max($today, date('Y-m-d', strtotime(((string)$o['status_at'] ?: $today) . ' +' . (int)($t['due_days'] ?? 0) . ' days'))); $put($d, 'in_f', $amt, $o['offer_no'] . ' · ' . $t['trigger_text']); $D['in_terms'] += $amt; }
    }
    // OUT actual: manhour cost by work date
    foreach (all("SELECT l.work_date, l.hours, l.hour_type, t.discipline_id, s.project_id, s.id AS sid, d.rate_normal, d.rate_overtime, d.rate_holiday FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN subprojects s ON s.id = l.subproject_id LEFT JOIN disciplines d ON d.id = t.discipline_id WHERE s.project_id IN ({$in}) AND t.status IN ('approved','invoiced') AND l.work_date BETWEEN ? AND ?", [$from, $to]) as $r) { $c = (float)$r['hours'] * (function_exists('br_hour_cost') ? br_hour_cost($r, (string)$r['hour_type'], (int)$r['project_id'], (int)$r['sid']) : (float)($r['rate_normal'] ?? 35)); $put((string)$r['work_date'], 'out_a', $c, t('cf.d_hours')); $D['out_hours'] += $c; }
    // OUT: parts costs
    foreach (all("SELECT c.cost_date, c.kind, c.amount, c.supplier, c.ref_no FROM sp_costs c JOIN subprojects s ON s.id = c.subproject_id WHERE s.project_id IN ({$in})") as $r) { if ($r['kind'] === 'invoice') { $put((string)$r['cost_date'], 'out_a', (float)$r['amount'], trim((string)$r['supplier'] . ' ' . (string)$r['ref_no'])); $D['out_parts_inv'] += (float)$r['amount']; } else { $put(max((string)$r['cost_date'], $today), 'out_f', (float)$r['amount'], trim((string)$r['supplier'] . ' ' . (string)$r['ref_no']) . ' (' . t('bg.k_order') . ')'); $D['out_parts_ord'] += (float)$r['amount']; } }
    // OUT: subcontracting with a PO
    if (function_exists('ps_has') && ps_has()) foreach (all("SELECT ps.supplier, ps.scope, ps.amount, ps.due_at, ps.delivered_at, ps.status, ps.po_no FROM production_subs ps JOIN production_orders o ON o.id = ps.order_id WHERE o.project_id IN ({$in}) AND ps.amount IS NOT NULL AND ps.amount > 0 AND ps.po_no IS NOT NULL AND ps.status NOT IN ('planned','cancelled')") as $r) {
        if (in_array($r['status'], ['delivered', 'inspected', 'accepted'], true)) { $put((string)($r['delivered_at'] ?: $today), 'out_a', (float)$r['amount'], $r['supplier'] . ' · ' . $r['scope']); $D['out_subs_del'] += (float)$r['amount']; }
        else { $put(max((string)($r['due_at'] ?: $today), $today), 'out_f', (float)$r['amount'], $r['supplier'] . ' · ' . $r['scope'] . ' (PO ' . $r['po_no'] . ')'); $D['out_subs_due'] += (float)$r['amount']; }
    }
    // OUT forecast: remaining budgeted hours spread to the deadline
    if (function_exists('bg_calc') && bg_has()) foreach (all("SELECT s.id, s.deadline, s.status FROM subprojects s WHERE s.project_id IN ({$in}) AND s.status <> 'completed'") as $s) {
        $C = bg_calc((int)$s['id'], $s); $remH = (float)($C['remaining_h'] ?? 0); $avg = (float)($C['budget_h'] ?? 0) > 0 ? (float)$C['budget_cost'] / (float)$C['budget_h'] : 0.0; $rem = $remH * $avg; if ($rem <= 0) continue;
        $end = (string)($s['deadline'] ?: date('Y-m-d', strtotime('+3 months'))); if ($end < $today) $end = $today;
        $pk = cf_periods($today, $end, $g); if (!$pk) continue; $per = $rem / count($pk);
        foreach ($pk as $k) if (isset($R[$k])) { $R[$k]['out_f'] += $per; $R[$k]['items'][] = ['out_f', $per, t('cf.d_remaining')]; } $D['out_remaining'] += $rem;
    }
    $cum = 0.0; $T = ['in_a' => 0.0, 'in_f' => 0.0, 'out_a' => 0.0, 'out_f' => 0.0];
    foreach ($R as $k => &$row) { $row['in'] = $row['in_a'] + $row['in_f']; $row['out'] = $row['out_a'] + $row['out_f']; $row['net'] = $row['in'] - $row['out']; $cum += $row['net']; $row['cum'] = $cum; $row['past'] = $k < $nowKey; foreach ($T as $c => $v) $T[$c] += $row[$c]; } unset($row);
    return ['periods' => $keys, 'rows' => $R, 'totals' => $T, 'drivers' => $D, 'from' => $from, 'to' => $to, 'now' => $nowKey, 'n_projects' => count($pids)];
}
/** Inline SVG bar chart: in (green) above, out (red) below the axis, cumulative line (navy). */
function cf_chart(array $S): string {
    $rows = $S['rows']; $n = count($rows); if (!$n) return '';
    $max = 1.0; foreach ($rows as $r) $max = max($max, $r['in'], $r['out'], abs($r['cum']));
    $w = max(600, $n * 46); $h = 180; $mid = 90; $sc = ($mid - 12) / $max; $bw = max(10, ($w - 40) / $n * 0.6); $step = ($w - 40) / $n;
    $svg = '<svg class="cf-chart" viewBox="0 0 ' . $w . ' ' . ($h + 18) . '" preserveAspectRatio="none" role="img">';
    $svg .= '<line x1="20" y1="' . $mid . '" x2="' . ($w - 20) . '" y2="' . $mid . '" stroke="#9AA5B1" stroke-width="1"/>';
    $i = 0; $pts = [];
    foreach ($rows as $k => $r) { $x = 20 + $i * $step + ($step - $bw) / 2; $fa = $r['past'] ? '1' : '.55';
        if ($r['in'] > 0) $svg .= '<rect x="' . round($x, 1) . '" y="' . round($mid - $r['in'] * $sc, 1) . '" width="' . round($bw, 1) . '" height="' . round($r['in'] * $sc, 1) . '" fill="#2F7D5C" fill-opacity="' . $fa . '"><title>' . e(cf_label($k, $S['g'] ?? 'm')) . ' · +' . e(cf_money($r['in'])) . '</title></rect>';
        if ($r['out'] > 0) $svg .= '<rect x="' . round($x, 1) . '" y="' . $mid . '" width="' . round($bw, 1) . '" height="' . round($r['out'] * $sc, 1) . '" fill="#9B2C2C" fill-opacity="' . $fa . '"><title>' . e(cf_label($k, $S['g'] ?? 'm')) . ' · −' . e(cf_money($r['out'])) . '</title></rect>';
        $pts[] = round($x + $bw / 2, 1) . ',' . round($mid - $r['cum'] * $sc, 1);
        if ($k === $S['now']) $svg .= '<line x1="' . round($x - ($step - $bw) / 2, 1) . '" y1="6" x2="' . round($x - ($step - $bw) / 2, 1) . '" y2="' . ($h - 2) . '" stroke="#C89B3C" stroke-dasharray="3 3"/>';
        $svg .= '<text x="' . round($x + $bw / 2, 1) . '" y="' . ($h + 12) . '" font-size="9" text-anchor="middle" fill="#6a7179">' . e($S['g'] === 'w' ? substr($k, 5) : date('M', strtotime($k . '-01'))) . '</text>'; $i++; }
    $svg .= '<polyline points="' . implode(' ', $pts) . '" fill="none" stroke="#0B1E2D" stroke-width="2"/>';
    return $svg . '</svg>';
}

<?php
/** Monthly / period sales report — PDF (GAS-SR-YYYY-MM) or Excel (GAS-CP-R39-002). ?p=report_sales[&period=][&user=&customer=][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('sl_can') || !sl_can()) { http_response_code(403); exit(t('err.noaccess')); }
$per = in_array($_GET['period'] ?? 'last', ['m', 'last', 'q', 'ytd', '12'], true) ? (string)($_GET['period'] ?? 'last') : 'last'; [$from, $to] = sl_period($per);
$f = array_filter(['user' => (int)($_GET['user'] ?? 0), 'customer' => (int)($_GET['customer'] ?? 0)]); $K = sl_kpis($from, $to, $f);
$docNo = (function_exists('br_prefix') ? br_prefix(null) : 'GAS') . '-SR-' . date('Y-m', strtotime($to));
$pf = fn(?float $v) => $v === null ? '—' : number_format($v * 100, 0) . ' %'; $df = fn(?float $v) => $v === null ? '—' : number_format($v, 1, ',', '.') . ' d';
if (($_GET['format'] ?? 'pdf') === 'xlsx') {
    require_once __DIR__ . '/../app/xlsxw.php';
    $sum = [[t('sl.k_sent'), $K['sent'], round($K['sent_v'], 2)], [t('sl.won'), $K['won'], round($K['won_v'], 2)], [t('sl.lost'), $K['lost'], round($K['lost_v'], 2)], [t('sl.k_hit'), $pf($K['hit']), $pf($K['hit_v'])], [t('sl.k_pipe'), $K['pipe']['n'], round($K['pipe']['v'], 2)], [t('sl.k_forecast'), $K['forecast_n'], round($K['forecast'], 2)], [t('sl.k_decision'), $df($K['dec_avg']), $K['breach']], [t('sl.k_turn'), $df($K['turn_avg']), $K['turn_n']], [t('sl.k_aov'), '', $K['aov'] !== null ? round($K['aov'], 2) : ''], [t('sl.k_offered_inv'), round($K['offered_inv']['invoiced'], 2), round($K['offered_inv']['offered'], 2)], [t('sl.k_discount'), '', round($K['discount'], 2)], [t('sl.k_repeat'), $K['repeat'], $K['customers_won']], [t('sl.k_conv'), $K['conv']['offers'], $K['conv']['calls']]];
    $pp = []; foreach ($K['by_user'] as $x) $pp[] = [$x['name'], $x['sent'], round($x['sent_v'], 2), $x['won'], round($x['won_v'], 2), $x['lost'], $pf($x['hit']), $x['dec_avg'] !== null ? round($x['dec_avg'], 1) : '', $x['open'], round($x['open_v'], 2)];
    $cc = []; foreach ($K['by_customer'] as $x) $cc[] = [$x['name'], $x['sent'], $x['won'], round($x['won_v'], 2), $x['lost'], round($x['open_v'], 2), $x['won_12']];
    xlsx_download($docNo . '.xlsx', [['name' => 'Summary', 'first' => true, 'header' => ['KPI', 'n', 'EUR / n'], 'rows' => $sum, 'widths' => [36, 14, 18]], ['name' => t('sl.by_person'), 'header' => [t('sl.person'), t('sl.k_sent'), 'EUR', t('sl.won'), 'EUR', t('sl.lost'), t('sl.k_hit'), t('sl.k_decision'), t('sl.open'), 'EUR'], 'rows' => $pp, 'widths' => [26, 8, 14, 8, 14, 8, 10, 10, 8, 14]], ['name' => t('sl.by_customer'), 'header' => [t('v.customer'), t('sl.k_sent'), t('sl.won'), 'EUR', t('sl.lost'), t('sl.open_value'), t('sl.won_12')], 'rows' => $cc, 'widths' => [40, 8, 8, 14, 8, 14, 10]]]);
}
require_once __DIR__ . '/../app/pdf.php';
$pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0;
$bd = function_exists('br_doc') ? br_doc(null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'footer' => ''];
$header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $from, $to) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper(t('sl.report_title'))); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $docNo . ' · ' . fmt_date($from) . ' – ' . fmt_date($to) . ' · ' . t('bg.internal') . ' · ' . date('d.m.Y')); $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36; };
$footer = function () use ($pdf, $L, $R, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo . ' · ' . t('bg.internal')); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
$need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 276) { $footer(); $header(); } };
$h1 = function (string $s) use ($pdf, $navy, $gold, $L, $R, &$y, $need) { $y += 3; $need(20); $pdf->setColor($navy); $pdf->setFont('B', 10.5); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.4); $pdf->line($L, $y + 6.5, $R, $y + 6.5); $y += 10; };
$table = function (string $title, array $cols, array $rows) use ($pdf, $navy, $ink, $line, $head, $L, $W, &$y, &$tableNo, $need, $header, $footer) {
    $cols = function_exists('rc_fit_cols') ? rc_fit_cols($cols, $W) : $cols; $tableNo++; $y += 2; $need(18);
    $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.2;
    $hrow = function () use ($pdf, $cols, $navy, $line, $head, $L, &$y) { $pdf->setFont('B', 7.2); $x = $L; $hh = 6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4, $lab, $al, $cw - 3); $x += $cw; } $y += $hh; };
    $hrow();
    foreach ($rows as $r) { $pdf->setFont(!empty($r['b']) ? 'B' : 'R', 7.6); $lines = []; $n = 1; foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($r['v'][$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * 3.8 + 1.6; if ($y + $hh > 276) { $footer(); $header(); $hrow(); $pdf->setFont('R', 7.6); }
        $x = $L; foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $s) $pdf->text($x + 1.5, $y + 3.5 + 3.8 * $k, $s, $al, $cw - 3); $x += $cw; } $y += $hh; }
    $y += 2;
};
$header(); $footer();
$h1('1. ' . t('sl.sec_summary'));
$rows = [['v' => [t('sl.k_sent'), (string)$K['sent'], sl_money($K['sent_v'])]], ['v' => [t('sl.won'), (string)$K['won'], sl_money($K['won_v'])]], ['v' => [t('sl.lost'), (string)$K['lost'], sl_money($K['lost_v'])]], ['b' => true, 'v' => [t('sl.k_hit'), $pf($K['hit']), t('sl.by_value') . ' ' . $pf($K['hit_v'])]], ['v' => [t('sl.k_pipe'), (string)$K['pipe']['n'], sl_money($K['pipe']['v']) . ' (' . t('sl.a14p') . ' ' . sl_money($K['pipe']['a14p']) . ')']], ['v' => [t('sl.k_forecast'), (string)$K['forecast_n'], sl_money($K['forecast'])]], ['v' => [t('sl.k_decision'), $df($K['dec_avg']), t('sl.k_breach', ['n' => $K['breach']])]], ['v' => [t('sl.k_turn'), $df($K['turn_avg']), t('sl.k_turn_s', ['n' => $K['turn_n']])]], ['v' => [t('sl.k_aov'), '', $K['aov'] !== null ? sl_money($K['aov']) : '—']], ['v' => [t('sl.k_offered_inv'), $K['offered_inv']['offered'] > 0 ? number_format($K['offered_inv']['invoiced'] / $K['offered_inv']['offered'] * 100, 0) . ' %' : '—', sl_money($K['offered_inv']['invoiced']) . ' / ' . sl_money($K['offered_inv']['offered'])]], ['v' => [t('sl.k_discount'), '', sl_money($K['discount'])]], ['v' => [t('sl.k_repeat'), (string)$K['repeat'], t('sl.k_repeat_s', ['n' => $K['customers_won'], 'p' => $K['top5_share'] !== null ? number_format($K['top5_share'] * 100, 0) : '—'])]], ['v' => [t('sl.k_conv'), $K['conv']['calls'] ? number_format($K['conv']['offers'] / $K['conv']['calls'] * 100, 0) . ' %' : '—', t('sl.k_conv_s', ['o' => $K['conv']['offers'], 'c' => $K['conv']['calls']])]]];
$table(t('sl.sec_summary'), [['KPI', 60, 'L'], [t('acc.value'), 30, 'R'], ['', 90, 'L']], $rows);
$h1('2. ' . t('sl.by_person'));
$rows = []; foreach ($K['by_user'] as $x) if ($x['sent'] || $x['won'] || $x['open'] || $x['lost']) $rows[] = ['v' => [$x['name'], (string)$x['sent'], sl_money($x['sent_v']), $x['won'] . ' / ' . $x['lost'], sl_money($x['won_v']), $pf($x['hit']), $df($x['dec_avg']), $x['open'] . ' · ' . sl_money($x['open_v'])]];
$table(t('sl.by_person'), [[t('sl.person'), 36, 'L'], [t('sl.k_sent'), 12, 'R'], ['EUR', 24, 'R'], [t('sl.won') . ' / ' . t('sl.lost'), 18, 'R'], ['EUR', 24, 'R'], [t('sl.k_hit'), 14, 'R'], [t('sl.k_decision'), 16, 'R'], [t('sl.open'), 36, 'R']], $rows ?: [['v' => ['—']]]);
$h1('3. ' . t('sl.by_customer'));
$rows = []; $i = 0; foreach ($K['by_customer'] as $x) { if (!$x['sent'] && !$x['won'] && !$x['lost'] && !$x['open_v']) continue; if (++$i > 20) break; $rows[] = ['v' => [$x['name'], (string)$x['sent'], $x['won'] . ' / ' . $x['lost'], sl_money($x['won_v']), sl_money($x['open_v']), (string)$x['won_12']]]; }
$table(t('sl.by_customer'), [[t('v.customer'), 70, 'L'], [t('sl.k_sent'), 16, 'R'], [t('sl.won') . ' / ' . t('sl.lost'), 22, 'R'], ['EUR', 26, 'R'], [t('sl.open_value'), 26, 'R'], [t('sl.won_12'), 20, 'R']], $rows ?: [['v' => ['—']]]);
if ($K['lost_why']) { $h1('4. ' . t('sl.lost_why')); arsort($K['lost_why']); $rows = []; foreach ($K['lost_why'] as $why => $n) $rows[] = ['v' => [sl_lost_label((string)$why), (string)$n]]; $table(t('sl.lost_why'), [[t('acc.reason'), 120, 'L'], ['n', 20, 'R']], $rows); }
$footer();
$bytes = $pdf->bytes($docNo);
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $docNo . '.pdf"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;

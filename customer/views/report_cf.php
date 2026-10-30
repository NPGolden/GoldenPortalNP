<?php
/** Cash flow report — PDF GAS-CF-YYYY-MM or Excel (GAS-CP-R45-001). ?p=report_cf[&project=][&g=][&format=xlsx] + the cashflow filters */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('cf_can') || !cf_can()) { http_response_code(403); exit; }
$g = ($_GET['g'] ?? 'm') === 'w' ? 'w' : 'm'; $pid = (int)($_GET['project'] ?? 0);
$f = ['project' => $pid, 'branch' => (int)($_GET['branch'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'pm' => (int)($_GET['pm'] ?? 0), 'completed' => $pid ? 1 : (int)($_GET['completed'] ?? 0)];
$p = $pid ? one('SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ?', [$pid]) : null;
$S = cf_series($f, $g); $S['g'] = $g; $T = $S['totals']; $D = $S['drivers'];
$docNo = (function_exists('br_prefix') ? br_prefix($pid ?: null) : 'GAS') . '-CF-' . date('Y-m') . ($p ? '-' . ($p['project_code'] ?: $pid) : '');
$label = $p ? trim(($p['project_code'] ?: '') . ' ' . $p['name']) : t('cf.company');
if (($_GET['format'] ?? 'pdf') === 'xlsx') {
    require_once __DIR__ . '/../app/xlsxw.php';
    $rows = []; foreach ($S['rows'] as $k => $r) $rows[] = [cf_label($k, $g), round($r['in_a'], 2), round($r['in_f'], 2), round($r['out_a'], 2), round($r['out_f'], 2), round($r['net'], 2), round($r['cum'], 2)];
    $drv = []; foreach ($S['rows'] as $k => $r) foreach ($r['items'] as [$c, $a, $w]) $drv[] = [cf_label($k, $g), t('cf.' . $c), round($a, 2), $w];
    xlsx_download($docNo . '.xlsx', [['name' => 'Cash flow', 'first' => true, 'header' => [t('cf.period'), t('cf.in_actual'), t('cf.in_forecast'), t('cf.out_actual'), t('cf.out_forecast'), t('cf.net'), t('cf.cum')], 'rows' => $rows, 'widths' => [18, 16, 16, 16, 16, 16, 16]], ['name' => 'Drivers', 'header' => [t('cf.period'), t('cf.kind'), t('pt.amount'), t('cf.drivers')], 'rows' => $drv, 'widths' => [18, 18, 14, 60]]]);
}
require_once __DIR__ . '/../app/pdf.php';
$pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0;
$bd = function_exists('br_doc') ? br_doc($pid ?: null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'footer' => ''];
$header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $label, $g) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper(t('cf.title')) . ' — ' . $label); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $docNo . ' · ' . ($g === 'w' ? t('cf.by_week') : t('cf.by_month')) . ' · ' . date('d.m.Y')); $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36; };
$footer = function () use ($pdf, $L, $W, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo); $pdf->text($L, 290, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W); };
$hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols); $x = $L; $pdf->setColor($head); $pdf->rect($L, $y, array_sum(array_column($cols, 1)), 6.5, 'F'); $pdf->setFont('B', 7.5); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $y, $cw, 6.5); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4.5, $lab, $al, $cw - 3); $x += $cw; } $y += 6.5; };
$drow = function (array $cols, array $vals, bool $bold = false) use ($pdf, $ink, $line, $L, &$y, $header, $footer, $hrow) { $cols = rc_fit_cols($cols); $lh = 3.9; $lines = []; $n = 1; $pdf->setFont($bold ? 'B' : 'R', 7.8); foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($vals[$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); } $hh = $n * $lh + 1.6; if ($y + $hh > 276) { $footer(); $header(); $hrow($cols); $pdf->setFont($bold ? 'B' : 'R', 7.8); } $x = $L; foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.2 + $lh * ($k + 1) - 0.9, $ln, $al, $cw - 3); $x += $cw; } $y += $hh; };
$h2 = function (string $t) use ($pdf, $navy, $gold, $L, &$y) { $y += 3; $pdf->setColor($navy); $pdf->setFont('B', 10); $pdf->text($L, $y + 3.5, $t); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 5.2, $L + 60, $y + 5.2); $y += 9; };
$m = fn(float $v) => number_format($v, 0, ',', '.');
$header();
$pdf->setFont('R', 8.5); $pdf->setColor($ink); $pdf->text($L, $y, t('cf.range', ['f' => fmt_date($S['from']), 't' => fmt_date($S['to'])]) . ' · ' . t('cf.n_projects', ['n' => (int)$S['n_projects']])); $y += 7;
$cols = [[t('cf.period'), 30, 'L'], [t('cf.in_actual'), 25, 'R'], [t('cf.in_forecast'), 25, 'R'], [t('cf.out_actual'), 25, 'R'], [t('cf.out_forecast'), 25, 'R'], [t('cf.net'), 25, 'R'], [t('cf.cum'), 25, 'R']]; $hrow($cols);
foreach ($S['rows'] as $k => $r) $drow($cols, [cf_label($k, $g) . ($k === $S['now'] ? ' ◀' : ''), $r['in_a'] ? $m($r['in_a']) : '—', $r['in_f'] ? $m($r['in_f']) : '—', $r['out_a'] ? $m($r['out_a']) : '—', $r['out_f'] ? $m($r['out_f']) : '—', ($r['net'] < 0 ? '−' : '+') . $m(abs($r['net'])), ($r['cum'] < 0 ? '−' : '') . $m(abs($r['cum']))]);
$net = ($T['in_a'] ?? 0) + ($T['in_f'] ?? 0) - ($T['out_a'] ?? 0) - ($T['out_f'] ?? 0);
$drow($cols, [t('ts.total'), $m($T['in_a'] ?? 0), $m($T['in_f'] ?? 0), $m($T['out_a'] ?? 0), $m($T['out_f'] ?? 0), ($net < 0 ? '−' : '+') . $m(abs($net)), ''], true);
$h2(t('cf.sources'));
$cols = [[t('cf.kind'), 100, 'L'], [t('pt.amount') . ' (EUR)', 40, 'R']]; $hrow($cols);
foreach (['in_paid' => 's_paid', 'in_pi' => 's_pi', 'in_terms' => 's_terms', 'out_hours' => 's_hours', 'out_parts_inv' => 's_parts_inv', 'out_parts_ord' => 's_parts_ord', 'out_subs_del' => 's_subs_del', 'out_subs_due' => 's_subs_due', 'out_remaining' => 's_remaining'] as $k => $lk) $drow($cols, [t('cf.' . $lk), $m((float)$D[$k])]);
$y += 3; $pdf->setFont('R', 7.5); $pdf->setColor('#6a7179'); foreach ($pdf->wrap(t('cf.rules'), $W) as $ln) { $pdf->text($L, $y + 3, $ln); $y += 3.6; }
$footer();
$bytes = $pdf->bytes($docNo); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $docNo . '.pdf"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;

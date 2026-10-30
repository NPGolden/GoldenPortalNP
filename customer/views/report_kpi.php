<?php
/** KPI report — PDF (GAS-KPI-YYYY-MM) or Excel of the KPI page's selection (GAS-CP-R39-001). ?p=report_kpi[&period=&project=&pm=&vessel=&status=][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('kpi_can') || !kpi_can()) { http_response_code(403); exit(t('err.noaccess')); }
$per = in_array($_GET['period'] ?? 'm', ['m', 'last', 'q', 'ytd', '12'], true) ? (string)($_GET['period'] ?? 'm') : 'm'; [$from, $to] = kpi_period($per);
$f = ['pm' => (int)($_GET['pm'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'status' => in_array($_GET['status'] ?? '', pm_statuses(), true) ? $_GET['status'] : ''];
$one = (int)($_GET['project'] ?? 0);
$projects = $one ? array_values(array_filter([kpi_project($one, $from, $to)])) : kpi_projects($from, $to, $f);
$fin = fin_visible(); $keys = array_values(array_filter(KPI_KEYS, fn($k) => $fin || !in_array($k, ['bai_b', 'bai_o', 'roi'], true)));
$docNo = (function_exists('br_prefix') ? br_prefix($one ?: null) : 'GAS') . '-KPI-' . date('Y-m', strtotime($to)) . ($one && $projects ? '-' . ($projects[0]['p']['project_code'] ?: $one) : '');
if (($_GET['format'] ?? 'pdf') === 'xlsx') {
    require_once __DIR__ . '/../app/xlsxw.php';
    $rows = []; foreach ($projects as $P) { $r = [$P['p']['project_code'] ?: '', $P['p']['name'], (string)($P['p']['vessel'] ?? ''), (string)($P['p']['project_manager'] ?? ''), '']; foreach ($keys as $k) $r[] = $P['v'][$k] !== null ? round((float)$P['v'][$k], 3) : ''; $rows[] = $r;
        foreach ($P['subs'] as $K) { $r = [$P['p']['project_code'] ?: '', $P['p']['name'], '', '', trim($K['sub']['code'] . ' ' . $K['sub']['name'])]; foreach ($keys as $k) $r[] = $K['v'][$k] !== null ? round((float)$K['v'][$k], 3) : ''; $rows[] = $r; } }
    xlsx_download($docNo . '.xlsx', [['name' => 'KPIs', 'first' => true, 'header' => array_merge([t('pr.one'), t('pr.one'), t('v.one'), 'PM', t('sp.one')], array_map('kpi_label', $keys)), 'rows' => $rows, 'widths' => array_merge([14, 30, 20, 20, 30], array_fill(0, count($keys), 9))]]);
}
require_once __DIR__ . '/../app/pdf.php';
$pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6'; $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0;
$bd = function_exists('br_doc') ? br_doc($one ?: null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'footer' => ''];
$header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $from, $to) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 18, mb_strtoupper(t('kpi.report_title'))); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 24, $docNo . ' · ' . fmt_date($from) . ' – ' . fmt_date($to) . ' · ' . t('bg.internal') . ' · ' . date('d.m.Y')); $pdf->setLineWidth(0.6); $pdf->line($L, 28, $R, 28); $y = 34; };
$footer = function () use ($pdf, $L, $R, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo . ' · ' . t('bg.internal')); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
$need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 276) { $footer(); $header(); } };
$table = function (string $title, array $cols, array $rows) use ($pdf, $navy, $ink, $line, $head, $L, $W, &$y, &$tableNo, $need, $header, $footer) {
    $cols = function_exists('rc_fit_cols') ? rc_fit_cols($cols, $W) : $cols; $tableNo++; $y += 2; $need(18);
    $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.2;
    $hrow = function () use ($pdf, $cols, $navy, $line, $head, $L, &$y) { $pdf->setFont('B', 6.2); $x = $L; $hh = 6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4, $lab, $al, $cw - 3); $x += $cw; } $y += $hh; };
    $hrow();
    foreach ($rows as $r) {
        $pdf->setFont(!empty($r['b']) ? 'B' : 'R', 6.8); $lines = []; $n = 1; foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($r['v'][$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * 3.7 + 1.6; if ($y + $hh > 276) { $footer(); $header(); $hrow(); $pdf->setFont('R', 6.8); }
        $x = $L; if (!empty($r['tone']) && function_exists('rc_bar_pdf')) rc_bar_pdf($pdf, $r['tone'], $x, $y, $hh);
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $tone = $r['hl'][$i] ?? null; $pdf->setColor($tone === 'red' ? '#9B2C2C' : ($tone === 'amber' ? '#B07D1C' : ($tone === 'green' ? '#2F7D5C' : $ink))); foreach ($lines[$i] as $k => $s) $pdf->text($x + 1.5, $y + 3.4 + 3.7 * $k, $s, $al, $cw - 3); $x += $cw; }
        $y += $hh;
    }
    $y += 2;
};
$header(); $footer();
$cols = [[t('pr.one'), 38, 'L'], ['PM', 16, 'L']]; foreach ($keys as $k) $cols[] = [kpi_label($k), $k === 'roi' ? 15 : 11, 'R'];
$rows = [];
foreach ($projects as $P) { $v = [trim(($P['p']['project_code'] ?: '') . ' ' . $P['p']['name']) . ($P['p']['vessel'] ? "\n" . $P['p']['vessel'] : ''), (string)($P['p']['project_manager'] ?? '')]; $hl = []; foreach ($keys as $i => $k) { $v[] = kpi_fmt($k, $P['v'][$k]); if ($P['tone'][$k]) $hl[$i + 2] = $P['tone'][$k]; } $rows[] = ['v' => $v, 'hl' => $hl, 'tone' => $P['worst'] === 'green' ? null : $P['worst'], 'b' => true];
    foreach ($P['subs'] as $K) { $v = ['   ' . trim($K['sub']['code'] . ' ' . $K['sub']['name']), t('pr.' . $K['sub']['status'])]; $hl = []; foreach ($keys as $i => $k) { $v[] = kpi_fmt($k, $K['v'][$k]); if ($K['tone'][$k]) $hl[$i + 2] = $K['tone'][$k]; } $rows[] = ['v' => $v, 'hl' => $hl]; } }
$table(t('kpi.by_project'), $cols, $rows ?: [['v' => [t('kpi.none'), '']]]);
$y += 4; $need(40); $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 4, t('kpi.definitions')); $y += 7; $T = kpi_targets();
$drows = []; foreach (KPI_KEYS as $k) $drows[] = ['v' => [kpi_label($k), t('kpi.f_' . $k), kpi_fmt($k, (float)$T[$k]['target']), t('kpi.r_' . $k)]];
$table(t('kpi.definitions'), [['KPI', 16, 'L'], [t('kpi.formula'), 70, 'L'], [t('kpi.target'), 14, 'R'], [t('kpi.reading'), 80, 'L']], $drows);
$footer();
$bytes = $pdf->bytes($docNo);
header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $docNo . '.pdf"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit;

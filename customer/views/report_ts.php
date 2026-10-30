<?php
/**
 * Timesheet report — PDF (inline) or Word (download) for one project, one
 * vessel, or everything; same filters as the Manhours page.
 *   ?p=report_ts[&project=ID|&vessel=ID][&from=..&to=..][&st[]=..]&format=pdf|docx     GAS-CP-R11-002
 */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!ts_has()) { http_response_code(404); exit(t('ts.need_r11')); }
$pid  = (int)($_GET['project'] ?? 0) ?: null;
$vid  = $pid ? null : ((int)($_GET['vessel'] ?? 0) ?: null);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : null;
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? $_GET['to']   : null;
$st   = array_values(array_intersect((array)($_GET['st'] ?? ['approved', 'invoiced']), ts_statuses())) ?: ['approved', 'invoiced'];
$d = ts_report_data($pid, $vid, $st, $from, $to);
if (($_GET['format'] ?? 'pdf') === 'docx') {
    $bytes = ts_render_docx($d);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $d['doc_no'] . '.docx"');
} else {
    $bytes = ts_render_pdf($d);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $d['doc_no'] . '.pdf"');
}
header('Content-Length: ' . strlen($bytes)); header('Cache-Control: private, max-age=0');
echo $bytes; exit;

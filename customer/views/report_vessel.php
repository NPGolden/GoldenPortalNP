<?php
/** Vessel technical-history report — PDF (inline) or Word (download).   GAS-CP-R14-001
 *   ?p=report_vessel&id=ID&format=pdf|docx */
require __DIR__ . '/_layout.php';
require_login();
$h = vessel_history((int)($_GET['id'] ?? 0), (int)($_GET['project'] ?? 0) ?: null);
if (!$h) { http_response_code(404); exit(t('err.notfound')); }
$docNo = vh_doc_no($h['vessel'], $h['only_project'] ?? null);
if (($_GET['format'] ?? 'pdf') === 'docx') {
    $bytes = vh_render_docx($h);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $docNo . '.docx"');
} else {
    $bytes = vh_render_pdf($h);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $docNo . '.pdf"');
}
header('Content-Length: ' . strlen($bytes)); header('Cache-Control: private, max-age=0');
echo $bytes; exit;

<?php
/** Vessel technical-history report — PDF (inline) or Word (download).   GAS-CP-R14-001
 *   ?p=report_vessel&id=ID&format=pdf|docx */
require __DIR__ . '/_layout.php';
require_login();
$own = is_staff() && ($_GET['copy'] ?? '') === 'owner'; if ($own) wk_as_owner(true);   // R97-100: the owner's copy, built by staff
$h = vessel_history((int)($_GET['id'] ?? 0), (int)($_GET['project'] ?? 0) ?: null);
if (!$h) { http_response_code(404); exit(t('err.notfound')); }
$docNo = vh_doc_no($h['vessel'], $h['only_project'] ?? null) . (is_staff() && !$own ? '-INT' : '');
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

<?php
/** Weekly vessel report — PDF (inline) or Word (download).   GAS-CP-R15-001
 *   ?p=report_weekly&vessel=ID[&project=ID][&week=YYYY-MM-DD]&format=pdf|docx */
require __DIR__ . '/_layout.php';
require_login();
$W = weekly_data((int)($_GET['vessel'] ?? 0), (int)($_GET['project'] ?? 0) ?: null, (string)($_GET['week'] ?? date('Y-m-d')));
if (!$W) { http_response_code(404); exit(t('err.notfound')); }
$docNo = wk_doc_no($W['vessel'], $W['only_project'], $W['week_start']);
if (($_GET['format'] ?? 'pdf') === 'docx') {
    $bytes = wk_render_docx($W);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $docNo . '.docx"');
} else {
    $bytes = wk_render_pdf($W);
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $docNo . '.pdf"');
}
header('Content-Length: ' . strlen($bytes)); header('Cache-Control: private, max-age=0');
echo $bytes; exit;

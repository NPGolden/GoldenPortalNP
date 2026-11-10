<?php
/** Statement of account per billing party and / or vessel(s) — PDF / Excel download (GAS-CP-R78-002, R95-005).  ?p=statement[&party=<org>]&v[]=<vessel>…&asof=YYYY-MM-DD[&from=][&format=pdf|xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('col_can') || !col_can()) { http_response_code(403); exit(t('err.noaccess')); }
$D = col_statement((array)($_GET['v'] ?? []), (string)($_GET['asof'] ?? ''), (string)($_GET['from'] ?? ''), (int)($_GET['party'] ?? 0) ?: null);   // R95-005: &party=<organisation> → statement of the billing party
if (!$D) { http_response_code(404); exit(t('err.notfound')); }
if (($_GET['format'] ?? 'pdf') === 'xlsx') { col_statement_xlsx($D); exit; }
$b = col_statement_pdf($D);
header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($b)); header('Cache-Control: private, no-store');
header('Content-Disposition: ' . (isset($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . $D['doc_no'] . '.pdf"');
echo $b; exit;

<?php
/** Item picker for requisition lines — JSON (GAS-CP-R80-001).  ?p=prq_items&q=  */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = current_user(); if (!$u || !is_staff() || !function_exists('prq_can') || !prq_can()) { http_response_code(403); echo '[]'; return; }
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); if (mb_strlen($q) < 2) { echo '[]'; return; }
echo json_encode(prq_item_search($q, 15), JSON_UNESCAPED_UNICODE);

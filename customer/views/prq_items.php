<?php
/** Item picker for requisition lines — JSON (GAS-CP-R80-001).  ?p=prq_items&q=  */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = current_user(); if (!$u || !is_staff() || !((function_exists('prq_can') && prq_can()) || (function_exists('ob_can') && ob_can()))) { http_response_code(403); echo '[]'; return; }   // R82-001: the offer builder uses the picker too
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); $sup = (int)($_GET['supplier'] ?? 0);
if (mb_strlen($q) < 2 && !($q === '' && $sup)) { echo '[]'; return; }   // R86-002: an empty query with a supplier lists that supplier's items (dropdown on focus)
echo json_encode(prq_item_search(mb_strlen($q) < 2 ? '' : $q, 20, $sup), JSON_UNESCAPED_UNICODE);

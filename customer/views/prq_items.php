<?php
/** Item picker for requisition lines — JSON (GAS-CP-R80-001).  ?p=prq_items&q=  */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = current_user(); if (!$u || !is_staff() || !((function_exists('prq_can') && prq_can()) || (function_exists('ob_can') && ob_can()))) { http_response_code(403); echo '[]'; return; }   // R82-001: the offer builder uses the picker too
if (isset($_GET['price_of'])) {   // R97-116: the price the portal proposes for one item on an order to this supplier — only for those who see prices
    $it = stk_price_visible() ? stk_item((int)$_GET['price_of']) : null; if (!$it) { echo '{}'; return; }
    $pp = stk_price_proposal($it, (int)($_GET['supplier'] ?? 0) ?: null); echo json_encode(['price' => $pp['price'], 'origin' => $pp['origin'], 'cur' => $pp['currency'], 'age' => $pp['age']], JSON_UNESCAPED_UNICODE); return;
}
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); $sup = (int)($_GET['supplier'] ?? 0);
$recent = !empty($_GET['recent']);
if (mb_strlen($q) < 2 && !($q === '' && ($sup || $recent))) { echo '[]'; return; }   // R86-002/003: an empty query lists the supplier's items, or the recently used ones (dropdown on focus)
echo json_encode(prq_item_search(mb_strlen($q) < 2 ? '' : $q, 20, $sup, $recent), JSON_UNESCAPED_UNICODE);

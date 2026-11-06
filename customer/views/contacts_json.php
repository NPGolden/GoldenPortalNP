<?php
/** People of the Contacts module for the e-mail card — JSON (GAS-CP-R84-001).  ?p=contacts_json&q=&company=   Staff only; the company's people first. */
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
$u = current_user(); if (!$u || !is_staff() || !function_exists('ct_has') || !ct_has()) { http_response_code(403); echo '[]'; return; }
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); $co = (int)($_GET['company'] ?? 0); if (mb_strlen($q) < 2) { echo '[]'; return; }
$l = '%' . $q . '%';
$rows = all('SELECT ct.id, ct.full_name, ct.position, ct.email, ct.category, ' . (function_exists('co_has') && co_has() ? 'co.name' : 'ct.company') . ' AS company_name FROM contacts ct ' . (function_exists('co_has') && co_has() ? 'LEFT JOIN companies co ON co.id = ct.company_id ' : '')
          . 'WHERE ct.is_active = 1 AND ct.email IS NOT NULL AND ct.email <> \'\' AND (ct.full_name LIKE ? OR ct.email LIKE ? OR ' . (function_exists('co_has') && co_has() ? 'co.name' : 'ct.company') . ' LIKE ?) ORDER BY ' . ($co ? '(ct.company_id = ' . $co . ') DESC, ' : '') . 'ct.full_name LIMIT 12', [$l, $l, $l]);
echo json_encode(array_map(fn($r) => ['name' => (string)$r['full_name'], 'email' => (string)$r['email'], 'role' => trim((string)($r['position'] ?? '') . (($r['company_name'] ?? '') !== '' ? ' · ' . $r['company_name'] : '')), 'cat' => (string)($r['category'] ?? '')], $rows), JSON_UNESCAPED_UNICODE);

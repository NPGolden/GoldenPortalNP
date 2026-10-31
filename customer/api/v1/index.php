<?php
/** Front controller of the mobile API — GAS-CP-R52-001. Routes map 1:1 to Portal::* (lib/portal.php). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate'); header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff'); header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'); $path = preg_replace('#^.*?/api/v1#', '', $path) ?: '/';
$method = $_SERVER['REQUEST_METHOD']; $body = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
try {
    $min = api_setting('api_min_version_engineer', API_VERSION); $ver = $_SERVER['HTTP_X_APP_VERSION'] ?? '0.0.0';
    if (version_compare($ver, $min, '<')) Response::error(426, 'Update required', ['min_version' => $min]);
    if (($_SERVER['HTTP_X_APP'] ?? API_APP_ID) !== API_APP_ID) Response::error(403, 'Wrong application');
    if ($path === '/auth/login' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $u = Portal::login(trim((string)($body['email'] ?? '')), (string)($body['password'] ?? ''), isset($body['totp']) ? (string)$body['totp'] : null);
        $dev = (array)($body['device'] ?? []); $did = Jwt::registerDevice((int)$u['id'], $dev); $u = api_act_as((int)$u['id']);
        Response::json(['access_token' => Jwt::issue($u, $did), 'refresh_token' => Jwt::issueRefresh($did, $dev['platform'] ?? 'web'), 'user' => Portal::me($u)]); }
    if ($path === '/auth/refresh' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $r = Jwt::consumeRefresh((string)($body['refresh_token'] ?? '')); $u = api_act_as((int)$r['user_id']);
        Response::json(['access_token' => Jwt::issue($u, (int)$r['device_id']), 'refresh_token' => Jwt::issueRefresh((int)$r['device_id'], (string)$r['platform'], (int)$r['id'])]); }
    $u = Jwt::requireUser(); RateLimit::check('api', 'u' . $u['id'], 300);
    switch (true) {
        case $method === 'POST' && $path === '/auth/logout': q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$u['device_id']]); Response::json([], 204);
        case $method === 'GET' && $path === '/me': Response::json(Portal::me($u));
        case $method === 'GET' && $path === '/tickets': Response::json(['items' => Portal::tickets($u)]);
        case $method === 'GET' && $path === '/assignments': Response::json(['items' => Portal::assignments($u)]);
        case $method === 'GET' && $path === '/projects': Response::json(['items' => Portal::projects()]);
        case $method === 'GET' && $path === '/vessels': Response::json(['items' => Portal::vessels()]);
        case $method === 'GET' && $path === '/equipment': Response::json(['items' => Portal::equipment()]);
        case $method === 'GET' && $path === '/budgets': Response::json(['items' => Response::noMoney(Portal::budgets())]);
        case $method === 'GET' && $path === '/master': Response::json(Portal::master($u));
        case $method === 'GET' && $path === '/documents': Response::json(['items' => Portal::documents()]);
        case $method === 'GET' && $path === '/accounting/status': Response::json(['items' => Response::noMoney(Portal::accountingStatus($u))]);   // CS-02
        case $method === 'GET' && $path === '/fsr': Response::json(['items' => Response::noMoney(Portal::reports($u))]);
        case $method === 'GET' && $path === '/timesheets': Response::json(['items' => Response::noMoney(Portal::timesheets($u))]);
        case $method === 'GET' && $path === '/notes': Response::json(['items' => Portal::notes($u)]);
        case $method === 'POST' && $path === '/sync': RateLimit::check('sync', 'u' . $u['id'], 60); require __DIR__ . '/endpoints/sync.php'; sync_ops($u, $body);
        case $method === 'POST' && $path === '/files/photo': require __DIR__ . '/endpoints/files.php'; upload_photo($u);
        case $method === 'POST' && $path === '/assist': RateLimit::check('assist', 'u' . $u['id'], 30); Response::json(Portal::assist($u, $body));
        case $method === 'POST' && $path === '/devices/push-token': q('UPDATE mob_devices SET push_token = ?, push_service = ? WHERE id = ?', [mb_substr((string)($body['token'] ?? ''), 0, 255), in_array($body['service'] ?? '', ['apns', 'fcm', 'webpush'], true) ? $body['service'] : 'fcm', $u['device_id']]); Response::json([], 204);
        case $method === 'GET' && $path === '/hr': Response::json(Portal::hr($u, (int)($_GET['year'] ?? date('Y'))));
        case $method === 'POST' && $path === '/hr/request': RateLimit::check('sync', 'u' . $u['id'], 60); $in = $body ?: $_POST; Response::json(['status' => 'accepted'] + Portal::hrRequest($u, $in, isset($_FILES['doc']) && ($_FILES['doc']['error'] ?? 1) === UPLOAD_ERR_OK ? $_FILES['doc'] : null));
        case $method === 'GET' && preg_match('#^/hr/doc/(\d+)$#', $path, $m): Portal::hrDocStream($u, (int)$m[1]);
        case $method === 'GET' && preg_match('#^/documents/(\d+)$#', $path, $m): require __DIR__ . '/endpoints/files.php'; stream_document($u, (int)$m[1]);
        default: Response::error(404, 'Not found');
    }
} catch (ApiException $e) { Response::error($e->status, $e->getMessage(), $e->extra); }
  catch (Throwable $e) { error_log('[api/v1] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); Response::error(500, 'Server error' . (cfg('app.debug') ? ': ' . $e->getMessage() : '')); }

<?php
/** Front controller of the mobile API — GAS-CP-R52-001 (+ R66-001 Golden PM routes /pm/*, R68-001 customer app routes /cu/*). Routes map 1:1 to Portal::* / PortalPM::* / PortalCustomer::* (lib/). */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store, no-cache, must-revalidate'); header('Pragma: no-cache'); header('X-Content-Type-Options: nosniff'); header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$path = '/' . trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/'); $path = preg_replace('#^.*?/api/v1#', '', $path) ?: '/';
$method = $_SERVER['REQUEST_METHOD']; $body = [];
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) $body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
try {
    $app = api_app(); $isPm = $app === 'pm'; $isCu = $app === 'customer';                                                       // R66-001: 'engineer' (Service Engineer) or 'pm' (Golden PM); R68-001: 'customer'
    if (!isset(API_APPS[strtolower(trim((string)($_SERVER['HTTP_X_APP'] ?? API_APP_ID)))])) Response::error(403, 'Wrong application');
    $min = api_setting('api_min_version_' . $app, API_APPS[$app]); $ver = $_SERVER['HTTP_X_APP_VERSION'] ?? '0.0.0';
    if (version_compare($ver, $min, '<')) Response::error(426, 'Update required', ['min_version' => $min]);
    /** GET /me: the SE shape, plus the PM fields for app pm (§1). */
    $me = function (array $u) use ($isPm, $isCu): array { if ($isCu) return PortalCustomer::me($u); $m = Portal::me($u); return $isPm ? array_replace($m, PortalPM::meExtra($u)) : $m; };
    if ($path === '/auth/login' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $u = Portal::login(trim((string)($body['email'] ?? '')), (string)($body['password'] ?? ''), isset($body['totp']) ? (string)$body['totp'] : null);
        $dev = (array)($body['device'] ?? []); $u = api_act_as((int)$u['id']); if ($isPm) PortalPM::allowed($u); if ($isCu) PortalCustomer::allowed($u);   // §0: who may sign in to each app (before the device is enrolled)
        $did = Jwt::registerDevice((int)$u['id'], $dev); $u['device_id'] = $did;
        Response::json(['access_token' => Jwt::issue($u, $did), 'refresh_token' => Jwt::issueRefresh($did, $dev['platform'] ?? 'web'), 'user' => $me($u)]); }
    if ($path === '/auth/refresh' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $r = Jwt::consumeRefresh((string)($body['refresh_token'] ?? '')); $u = api_act_as((int)$r['user_id']); if ($isPm) PortalPM::allowed($u); if ($isCu) PortalCustomer::allowed($u);
        Response::json(['access_token' => Jwt::issue($u, (int)$r['device_id']), 'refresh_token' => Jwt::issueRefresh((int)$r['device_id'], (string)$r['platform'], (int)$r['id'])]); }
    $u = Jwt::requireUser(); RateLimit::check('api', 'u' . $u['id'], 300);
    if (str_starts_with($path, '/pm/') && !$isPm) Response::error(403, 'Golden PM app only');
    if (str_starts_with($path, '/cu/') && !$isCu) Response::error(403, 'Customer app only');
    if ($isCu) { PortalCustomer::allowed($u); if (!str_starts_with($path, '/cu/') && !in_array($path, ['/me', '/auth/logout', '/devices/push-token'], true)) Response::error(403, 'Not available in the customer app'); }   // CS-03: staff routes never serve a customer token
    if ($isCu) { $u['device_id'] = (int)$u['device_id']; require __DIR__ . '/endpoints/customer.php'; customer_routes($u, $method, $path, $body); }
    if ($isPm) PortalPM::allowed($u);                                                                                            // rights may change while a token lives
    switch (true) {
        case $method === 'POST' && $path === '/auth/logout': q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$u['device_id']]); Response::json([], 204);
        case $method === 'GET' && $path === '/me': Response::json($me($u));
        case $method === 'GET' && $path === '/pm/projects': Response::json(PortalPM::projects($u));
        case $method === 'GET' && preg_match('#^/pm/subproject/(\d+)$#', $path, $m): Response::json(PortalPM::subproject($u, (int)$m[1]));
        case $method === 'GET' && $path === '/pm/drawings': Response::json(['items' => PortalPM::drawings(isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null)]);
        case $method === 'GET' && $path === '/pm/submittals': Response::json(['items' => PortalPM::submittals(isset($_GET['project']) && $_GET['project'] !== '' ? (int)$_GET['project'] : null)]);
        case $method === 'GET' && $path === '/pm/hr/team': Response::json(PortalPM::hrTeam($u, (int)($_GET['year'] ?? date('Y'))));
        case $method === 'GET' && $path === '/pm/absences': Response::json(PortalPM::absencesForProject((int)($_GET['project'] ?? 0), (string)($_GET['from'] ?? date('Y-m-d')), (string)($_GET['to'] ?? date('Y-m-d', strtotime('+28 days')))));
        case $method === 'GET' && $path === '/pm/inbox': Response::json(['items' => PortalPM::inbox($u, (int)($_GET['since'] ?? 0))]);
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

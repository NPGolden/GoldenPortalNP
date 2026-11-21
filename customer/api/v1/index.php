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
    $app = api_app(); $isPm = $app === 'pm'; $isCu = $app === 'customer'; $isHr = $app === 'hr';   // R74-001: 'hr' (Golden HR)                                                       // R66-001: 'engineer' (Service Engineer) or 'pm' (Golden PM); R68-001: 'customer'
    if (!isset(API_APPS[strtolower(trim((string)($_SERVER['HTTP_X_APP'] ?? API_APP_ID)))])) Response::error(403, 'Wrong application');
    $min = api_setting('api_min_version_' . $app, API_APPS[$app]); $ver = $_SERVER['HTTP_X_APP_VERSION'] ?? '0.0.0';
    if (version_compare($ver, $min, '<')) Response::error(426, 'Update required', ['min_version' => $min]);
    /** GET /me: the SE shape, plus the PM fields for app pm (§1). */
    $me = function (array $u) use ($isPm, $isCu, $isHr): array { if (!empty($u['is_demo'])) return $isHr ? PortalHRDemo::me($u) : ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'email' => (string)$u['email'], 'role' => (string)$u['role'], 'demo' => true];   // R97-169: no real data for a demonstration account
        if ($isCu) return PortalCustomer::me($u); if ($isHr) return PortalHR::me($u); $m = Portal::me($u); return $isPm ? array_replace($m, PortalPM::meExtra($u)) : $m; };
    if ($path === '/auth/login' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $u = Portal::login(trim((string)($body['email'] ?? '')), (string)($body['password'] ?? ''), isset($body['totp']) ? (string)$body['totp'] : null);
        if (function_exists('pwc_must') && pwc_must($u)) {   // R97-167: the first sign-in sets the account's own password — no token before that
            $np = isset($body['new_password']) ? (string)$body['new_password'] : ''; if ($np === '') Response::error(403, 'Choose your own password to continue.', ['password_change' => true]);
            if (($pe = pwc_error($np, $u)) !== null) Response::error(422, $pe, ['password_change' => true]);
            pwc_store((int)$u['id'], $np); if (function_exists('login_log')) login_log((int)$u['id'], (string)$u['email'], 'pw_changed'); }
        $dev = (array)($body['device'] ?? []); $u = api_act_as((int)$u['id']); if ($isPm) PortalPM::allowed($u); if ($isCu) PortalCustomer::allowed($u); if ($isHr) PortalHR::allowed($u);   // §0: who may sign in to each app (before the device is enrolled)
        if ($isHr && !empty($u['is_demo'])) PortalHRDemo::reset($u);   // R97-169: the demonstration starts again at every sign-in
        $did = Jwt::registerDevice((int)$u['id'], $dev); $u['device_id'] = $did;
        Response::json(['access_token' => Jwt::issue($u, $did), 'refresh_token' => Jwt::issueRefresh($did, $dev['platform'] ?? 'web'), 'user' => $me($u)]); }
    /* R71-001: passkey sign-in — challenge kept server-side (5 min, single use); the assertion is verified in app/pk.php; a verified passkey satisfies the authenticator step */
    if ($path === '/auth/passkey/options' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10); if (!pk_enabled()) Response::error(403, 'Passkeys are switched off');
        $c = pk_challenge_new('auth', null, 'db'); Response::json(['challenge_id' => $c['id'], 'options' => pk_login_options($c['challenge'])]); }
    if ($path === '/auth/passkey/login' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10); if (!pk_enabled()) Response::error(403, 'Passkeys are switched off');
        $c = pk_challenge_take('auth', 'db', (string)($body['challenge_id'] ?? '')); if (!$c) Response::error(400, 'Challenge expired');
        try { $u = pk_assert_finish($c['challenge'], (array)($body['credential'] ?? [])); } catch (RuntimeException $e) { usleep(300000); Response::error(401, 'Passkey rejected (' . $e->getMessage() . ')'); }
        if ($isCu) { if (($u['role'] ?? 'customer') !== 'customer') Response::error(403, 'Customer account required'); } elseif (!in_array($u['role'], ['staff', 'admin'], true)) Response::error(403, 'Staff account required');
        if (function_exists('login_log')) login_log((int)$u['id'], (string)$u['email'], 'passkey');
        $dev = (array)($body['device'] ?? []); $u = api_act_as((int)$u['id']); if ($isPm) PortalPM::allowed($u); if ($isCu) PortalCustomer::allowed($u); if ($isHr) PortalHR::allowed($u);
        $did = Jwt::registerDevice((int)$u['id'], $dev); $u['device_id'] = $did;
        Response::json(['access_token' => Jwt::issue($u, $did), 'refresh_token' => Jwt::issueRefresh($did, $dev['platform'] ?? 'web'), 'user' => $me($u)]); }
    if ($path === '/auth/refresh' && $method === 'POST') { RateLimit::check('auth', RateLimit::ip(), 10);
        $r = Jwt::consumeRefresh((string)($body['refresh_token'] ?? '')); $u = api_act_as((int)$r['user_id']); if ($isPm) PortalPM::allowed($u); if ($isCu) PortalCustomer::allowed($u); if ($isHr) PortalHR::allowed($u);
        Response::json(['access_token' => Jwt::issue($u, (int)$r['device_id']), 'refresh_token' => Jwt::issueRefresh((int)$r['device_id'], (string)$r['platform'], (int)$r['id'])]); }
    $u = Jwt::requireUser(); RateLimit::check('api', 'u' . $u['id'], 300);
    if (str_starts_with($path, '/pm/') && !$isPm) Response::error(403, 'Golden PM app only');
    if (str_starts_with($path, '/cu/') && !$isCu) Response::error(403, 'Customer app only');
    if (str_starts_with($path, '/hr/app/') && !$isHr) Response::error(403, 'Golden HR app only');
    if ($app === 'employee' && !in_array($path, ['/me', '/auth/logout', '/devices/push-token', '/me/passkeys', '/me/passkeys/options', '/sync', '/hr', '/hr/request', '/hr/request/doc', '/inout', '/inout/punch', '/inout/consent'], true) && !preg_match('#^/me/passkeys/\d+$#', $path) && !preg_match('#^/hr/doc/\d+$#', $path)) Response::error(403, 'Not available in Golden A/S Employee');   // R97-180: the employee app reaches the account and its own HR record, nothing else
    if ($isHr) { PortalHR::allowed($u); if (!str_starts_with($path, '/hr/app/') && !str_starts_with($path, '/requisitions') && $path !== '/items' && !in_array($path, ['/me', '/auth/logout', '/devices/push-token', '/me/passkeys', '/me/passkeys/options', '/sync'], true) && !preg_match('#^/me/passkeys/\d+$#', $path)) Response::error(403, 'Not available in Golden HR'); }   // R82-003: part ordering is open to every staff app   // R74-001: the HR app sees only its own routes
    if (!empty($u['is_demo'])) {   // R97-169 (customer app too: R97-172): demonstration account — Golden HR is answered from the invented company; Service and PM show the data set built into the app and get nothing from the portal
        if ($isHr) PortalHRDemo::handle($method, $path, $body, $u);
        if ($path === '/me' && $method === 'GET') Response::json($me($u));
        if ($path !== '/auth/logout') Response::error(403, 'Demonstration account: the portal data is not available', ['demo' => true]);
    }
    if ($isCu) { PortalCustomer::allowed($u); if (!str_starts_with($path, '/cu/') && !in_array($path, ['/me', '/auth/logout', '/devices/push-token', '/me/passkeys', '/me/passkeys/options'], true) && !preg_match('#^/me/passkeys/\d+$#', $path)) Response::error(403, 'Not available in the customer app'); }   // CS-03: staff routes never serve a customer token
    if ($isCu && !str_starts_with($path, '/me/passkeys')) { $u['device_id'] = (int)$u['device_id']; require __DIR__ . '/endpoints/customer.php'; customer_routes($u, $method, $path, $body); }
    if ($isPm) PortalPM::allowed($u);                                                                                            // rights may change while a token lives
    switch (true) {
        case $method === 'POST' && $path === '/auth/logout': q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$u['device_id']]); Response::json([], 204);
        case $method === 'GET' && $path === '/me': Response::json($me($u));
        /* R82-003: part ordering from the apps — requisitions (app/prq.php rules) */
        case $method === 'GET' && $path === '/requisitions': Response::json(PortalParts::list((string)($_GET['status'] ?? 'open')));
        case $method === 'GET' && $path === '/requisitions/options': Response::json(PortalParts::options());
        case $method === 'GET' && preg_match('#^/requisitions/(\d+)$#', $path, $m): Response::json(PortalParts::one((int)$m[1]));
        case $method === 'POST' && $path === '/requisitions': RateLimit::check('api', 'prq' . $u['id'], 30); Response::json(PortalParts::create($body));
        case $method === 'POST' && preg_match('#^/requisitions/(\d+)/cancel$#', $path, $m): Response::json(PortalParts::cancel((int)$m[1], (string)($body['note'] ?? '')));
        case $method === 'POST' && preg_match('#^/requisitions/(\d+)/decide$#', $path, $m): Response::json(PortalParts::decide((int)$m[1], (string)($body['decision'] ?? 'approve'), (string)($body['note'] ?? '')));
        /* R82-001: inventory lookup for the parts of a service report — code / description / barcode search, no prices */
        case $method === 'GET' && $path === '/items': { if (!function_exists('stk_has') || !stk_has()) Response::json(['items' => []]); $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60); if (mb_strlen($q) < 2 && empty($_GET['recent'])) Response::json(['items' => []]);
            $rows = mb_strlen($q) < 2 ? (function_exists('prq_recent_items') ? prq_recent_items(12) : []) : stk_items(['q' => $q, 'status' => 'active'], 12);   // R86-003: recent items when the box is empty
            Response::json(['items' => array_map(fn($r) => ['id' => (int)$r['id'], 'code' => $r['code'], 'desc' => $r['description'], 'maker' => $r['maker'], 'pn' => $r['factory_code'], 'unit' => $r['unit'], 'barcode' => $r['barcode'], 'free' => (float)$r['on_hand'] - (float)$r['reserved']], $rows)]); }
        /* R71-001: passkeys of the signed-in account (enrolment needs a fresh authenticator code when the account has one) */
        case $method === 'GET' && $path === '/me/passkeys': Response::json(['enabled' => pk_enabled(), 'items' => pk_list((int)$u['id'])]);
        case $method === 'POST' && $path === '/me/passkeys/options': if (!pk_enabled()) Response::error(403, 'Passkeys are switched off'); if (!pk_stepup_ok($u, isset($body['totp']) ? (string)$body['totp'] : null)) Response::error(401, 'Authenticator code required', ['totp' => true]);
            $c = pk_challenge_new('reg', (int)$u['id'], 'db'); Response::json(['challenge_id' => $c['id'], 'options' => pk_registration_options($u, $c['challenge'])]);
        case $method === 'POST' && $path === '/me/passkeys': $c = pk_challenge_take('reg', 'db', (string)($body['challenge_id'] ?? '')); if (!$c || $c['uid'] !== (int)$u['id']) Response::error(400, 'Challenge expired');
            try { $id = pk_register_finish($u, $c['challenge'], (array)($body['credential'] ?? []), (string)($body['name'] ?? ''), (int)$u['device_id']); } catch (RuntimeException $e) { Response::error(400, 'Passkey not accepted (' . $e->getMessage() . ')'); } Response::json(['id' => $id], 201);
        case $method === 'DELETE' && preg_match('#^/me/passkeys/(\d+)$#', $path, $m): try { pk_remove((int)$u['id'], (int)$m[1]); } catch (RuntimeException $e) { Response::error(404, 'Not found'); } Response::json([], 204);
        /* R74-001 Golden HR */
        case $method === 'GET' && $path === '/hr/app/inbox': Response::json(PortalHR::inbox($u));
        case $method === 'GET' && $path === '/hr/app/history': Response::json(PortalHR::history((int)($_GET['year'] ?? date('Y'))));
        case $method === 'GET' && $path === '/hr/app/people': Response::json(PortalHR::people());
        case $method === 'GET' && $path === '/hr/app/away': Response::json(PortalHR::away((string)($_GET['from'] ?? date('Y-m-d')), (string)($_GET['to'] ?? date('Y-m-d', strtotime('+27 days')))));
        case $method === 'GET' && $path === '/hr/app/balances': Response::json(PortalHR::balances((int)($_GET['year'] ?? date('Y'))));
        case $method === 'GET' && $path === '/hr/app/threads': Response::json(PortalHR::threads());
        case $method === 'GET' && preg_match('#^/hr/app/thread/(\d+)$#', $path, $mm): Response::json(PortalHR::thread((int)$mm[1]));
        case $method === 'POST' && $path === '/hr/app/send': RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalHR::send($u, $body), 201);
        /* R97-156 Golden HR 1.1.0 — served live, nothing of it is kept on the phone */
        case $method === 'GET' && $path === '/hr/app/today': Response::json(PortalHRMore::today($u));
        case $method === 'GET' && $path === '/hr/app/recruiting': Response::json(PortalHRMore::recruiting());
        case $method === 'GET' && preg_match('#^/hr/app/application/(\d+)$#', $path, $mm): Response::json(PortalHRMore::application((int)$mm[1]));
        case $method === 'GET' && preg_match('#^/hr/app/cv/(\d+)$#', $path, $mm): RateLimit::check('api', 'cv' . $u['id'], 60); PortalHRMore::cv((int)$mm[1]);
        case $method === 'POST' && preg_match('#^/hr/app/application/(\d+)/status$#', $path, $mm): RateLimit::check('decide', 'u' . $u['id'], 30); Response::json(PortalHRMore::appStatus($u, (int)$mm[1], $body));
        case $method === 'POST' && preg_match('#^/hr/app/application/(\d+)/evaluation$#', $path, $mm): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalHRMore::appEvaluation($u, (int)$mm[1], $body), 201);
        case $method === 'POST' && preg_match('#^/hr/app/interview/(\d+)/status$#', $path, $mm): RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalHRMore::interviewStatus((int)$mm[1], $body));
        case $method === 'GET' && $path === '/hr/app/jds': Response::json(PortalHRMore::jds());
        case $method === 'POST' && preg_match('#^/hr/app/jd/(\d+)/decide$#', $path, $mm): RateLimit::check('decide', 'u' . $u['id'], 30); Response::json(PortalHRMore::jdDecide($u, (int)$mm[1], $body));
        case $method === 'GET' && $path === '/hr/app/year': Response::json(PortalHRMore::year());
        case $method === 'POST' && $path === '/hr/app/year/tick': RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(PortalHRMore::yearTick($body));
        case $method === 'GET' && $path === '/hr/app/expiring': Response::json(PortalHRMore::expiring((int)($_GET['days'] ?? 60)));
        case $method === 'GET' && $path === '/hr/app/equipment': Response::json(PortalHRMore::equipment());
        case $method === 'GET' && preg_match('#^/hr/app/equipment/doc/(\d+)$#', $path, $mm): Response::json(PortalHRMore::eqDoc((int)$mm[1]));
        case $method === 'POST' && preg_match('#^/hr/app/equipment/doc/(\d+)/receive$#', $path, $mm): RateLimit::check('decide', 'u' . $u['id'], 30); Response::json(PortalHRMore::eqReceive($u, (int)$mm[1], $body));
        case $method === 'POST' && preg_match('#^/hr/app/equipment/doc/(\d+)/paper$#', $path, $mm): RateLimit::check('decide', 'u' . $u['id'], 30); Response::json(PortalHRMore::eqPaper($u, (int)$mm[1], $body));
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
        /* R97-060: customer acceptance of a service report from the app */
        case $method === 'GET' && preg_match('#^/fsr/(\d+)/acceptance$#', $path, $m): Response::json(Portal::acceptance($u, (int)$m[1]));
        case $method === 'POST' && preg_match('#^/fsr/(\d+)/accept-link$#', $path, $m): RateLimit::check('api', 'dl' . $u['id'], 20); Response::json(Portal::acceptLink($u, (int)$m[1], $body), 201);
        case $method === 'POST' && preg_match('#^/fsr/(\d+)/accept-link/(\d+)/cancel$#', $path, $m): Response::json(Portal::acceptCancel($u, (int)$m[1], (int)$m[2]));
        case $method === 'POST' && preg_match('#^/fsr/(\d+)/sign-onsite$#', $path, $m): RateLimit::check('api', 'sgn' . $u['id'], 20); Response::json(Portal::signOnsite($u, (int)$m[1], $body));
        case $method === 'GET' && $path === '/timesheets': Response::json(['items' => Response::noMoney(Portal::timesheets($u))]);
        case $method === 'GET' && $path === '/notes': Response::json(['items' => Portal::notes($u)]);
        case $method === 'POST' && $path === '/sync': RateLimit::check('sync', 'u' . $u['id'], 60); require __DIR__ . '/endpoints/sync.php'; sync_ops($u, $body);
        case $method === 'POST' && $path === '/files/photo': require __DIR__ . '/endpoints/files.php'; upload_photo($u);
        case $method === 'POST' && $path === '/assist': RateLimit::check('assist', 'u' . $u['id'], 30); Response::json(Portal::assist($u, $body));
        case $method === 'POST' && $path === '/devices/push-token': q('UPDATE mob_devices SET push_token = ?, push_service = ? WHERE id = ?', [mb_substr((string)($body['token'] ?? ''), 0, 255), in_array($body['service'] ?? '', ['apns', 'fcm', 'webpush'], true) ? $body['service'] : 'fcm', $u['device_id']]); Response::json([], 204);
        /* R97-182 In / Out — every staff app (Employee, Service, PM, HR); never the customer app */
        case $method === 'GET' && $path === '/inout': if ($isCu) Response::error(403, 'Staff only'); Response::json(io_api_me((int)$u['id']));
        case $method === 'POST' && $path === '/inout/consent': if ($isCu) Response::error(403, 'Staff only'); io_consent_accept((int)$u['id'], $app, isset($body['at']) ? (string)$body['at'] : null); Response::json(['status' => 'accepted', 'consent' => io_api_me((int)$u['id'])['consent']]);   // R97-183
        case $method === 'POST' && $path === '/inout/punch': if ($isCu) Response::error(403, 'Staff only'); RateLimit::check('sync', 'u' . $u['id'], 60); if (!io_consent_ok((int)$u['id'])) Response::error(409, 'Accept the In / Out notice first', ['consent' => true]); { $r = io_punch((int)$u['id'], ['source' => $app, 'ip' => io_client_ip()] + (array)$body); $x = io_entries(['user' => (int)$u['id'], 'from' => $r['local_day'], 'to' => $r['local_day']], 50); $row = $r; foreach ($x as $e) if ((int)$e['id'] === (int)$r['id']) $row = $e; Response::json(['status' => 'accepted', 'entry' => io_row_api($row), 'state' => io_state((int)$u['id'])['state']]); }
        case $method === 'GET' && $path === '/hr': Response::json(Portal::hr($u, (int)($_GET['year'] ?? date('Y'))));
        case $method === 'POST' && $path === '/hr/request/doc': RateLimit::check('sync', 'u' . $u['id'], 60); Response::json(['status' => 'accepted'] + Portal::hrRequestDoc($u, (int)($_POST['request_id'] ?? 0), isset($_FILES['doc']) && ($_FILES['doc']['error'] ?? 1) === UPLOAD_ERR_OK ? $_FILES['doc'] : null));   // R77-007
        case $method === 'POST' && $path === '/hr/request': RateLimit::check('sync', 'u' . $u['id'], 60); $in = $body ?: $_POST; Response::json(['status' => 'accepted'] + Portal::hrRequest($u, $in, isset($_FILES['doc']) && ($_FILES['doc']['error'] ?? 1) === UPLOAD_ERR_OK ? $_FILES['doc'] : null));
        case $method === 'GET' && preg_match('#^/hr/doc/(\d+)$#', $path, $m): Portal::hrDocStream($u, (int)$m[1]);
        case $method === 'GET' && preg_match('#^/documents/(\d+)$#', $path, $m): require __DIR__ . '/endpoints/files.php'; stream_document($u, (int)$m[1]);
        default: Response::error(404, 'Not found');
    }
} catch (ApiException $e) { Response::error($e->status, $e->getMessage(), $e->extra); }
  catch (Throwable $e) { error_log('[api/v1] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()); Response::error(500, 'Server error' . (cfg('app.debug') ? ': ' . $e->getMessage() : '')); }

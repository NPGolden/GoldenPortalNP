<?php
/**
 * Website feed — GAS-CP-R90-001 / R90-002.   Header  X-Site-Key: <Settings › System › Website>  on every call.
 *   GET  web_feed.php              the four Fleet & activity counts (integers)
 *   POST web_feed.php?r=ticket     support request from goldenas.com.gr/support   → Website inbox (+ staff notification)
 *   POST web_feed.php?r=access     Customer Portal access request from /portal-access → Website inbox (administrators)
 *   POST web_feed.php?r=subscribe  newsletter / events sign-up from the website → pending subscriber + confirmation e-mail (R97-039)
 *   POST web_feed.php?r=unsubscribe  unsubscribe by address; the answer is the same whatever the state of the address (R97-050)
 * R97-050: two websites. The KEY decides the site — web_site_key = golden, web_site_key_kyma = kyma — never the body. A request that names
 * another site than its key (header X-Site or body "site") is refused. The KYMA key opens contact, subscribe and unsubscribe only.
 * Returns the four Fleet & activity counts of the dashboard as integers and nothing else. No session, no cookies, no CORS
 * (browsers are not meant to call it — the website's build pipeline does, once a week), key compared in constant time,
 * 10 requests per IP per minute, generic errors. Empty key = feed switched off (503).
 */
declare(strict_types=1);
define('CP_ROOT', __DIR__); ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
foreach (['version', 'db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'mailer', 'nf', 'tk', 'act', 'web', 'ct', 'org', 'nl'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
if (function_exists('tz_app')) date_default_timezone_set(tz_app());   // R92-008: event times carry the Athens offset, as in index.php
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex');
$out = function ($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; };
$m = $_SERVER['REQUEST_METHOD']; if ($m !== 'GET' && $m !== 'POST') { header('Allow: GET, POST'); $out(['error' => 'method not allowed'], 405); }
$keys = function_exists('web_has') && web_has() ? ['golden' => (string) cp_setting('web_site_key', ''), 'kyma' => function_exists('kym_has') && kym_has() ? (string) cp_setting('web_site_key_kyma', '') : ''] : ['golden' => '', 'kyma' => ''];
if ($keys['golden'] === '' && $keys['kyma'] === '') $out(['error' => 'feed disabled'], 503);
$ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
$dir = __DIR__ . '/storage/web/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json';
$hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
if (count($hits) >= ($m === 'GET' ? 10 : 20)) { header('Retry-After: 60'); $out(['error' => 'too many requests'], 429); } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
$given = (string) ($_SERVER['HTTP_X_SITE_KEY'] ?? ''); $site = '';
foreach ($keys as $code => $k) { $hit = $k !== '' && hash_equals($k, $given); if ($hit && $site === '') $site = $code; }   // every configured key is compared, in constant time; the first match fixes the site
if ($site === '') { usleep(300000); error_log('[web_feed] forbidden from ' . $ip); $out(['error' => 'forbidden'], 403); }
$deny = function (string $why) use ($out, $ip, $site): void { usleep(300000); error_log('[web_feed] ' . $site . ': ' . $why . ' from ' . $ip); $out(['error' => 'forbidden'], 403); };
$hs = strtolower(trim((string) ($_SERVER['HTTP_X_SITE'] ?? ''))); if ($hs !== '' && $hs !== $site) $deny('header names site ' . preg_replace('/[^a-z0-9]/', '', $hs));
try {
    if ($m === 'GET') { if ($site !== 'golden') $deny('counts not open to this site'); $c = web_counts(); web_mark_pull($ip); $out($c); }
    if (!web_inbox_has()) $out(['error' => 'inbox disabled'], 503);
    $r = (string) ($_GET['r'] ?? ''); $body = json_decode((string) file_get_contents('php://input'), true); if (!is_array($body)) $out(['error' => 'invalid body'], 400);
    $fwd = trim((string) ($body['ip'] ?? '')); $vip = preg_match('/^[0-9a-fA-F:.]{3,45}$/', $fwd) ? $fwd : $ip;   // the website passes the visitor's address
    $bs = strtolower(trim((string) ($body['site'] ?? ''))); if ($bs !== '' && $bs !== $site) $deny('body names site ' . preg_replace('/[^a-z0-9]/', '', $bs));
    if (in_array($r, ['ticket', 'access'], true) && $site !== 'golden') $deny('route ' . $r . ' not open to this site');
    if ($r === 'ticket') $out(web_pub_ticket($body, $vip), 201);
    if ($r === 'access') $out(web_pub_access($body, $vip), 201);
    if ($r === 'contact') $out(web_pub_contact($body, $vip, $site), 201);   // R93-002
    if ($r === 'subscribe' && function_exists('nl_pub_subscribe')) $out(nl_pub_subscribe($body, $vip, $site), 201);   // R97-039 newsletter / events sign-up (double opt-in)
    if ($r === 'unsubscribe' && function_exists('nl_pub_unsub_email')) $out(nl_pub_unsub_email((string) ($body['email'] ?? ''), $site), 200);   // R97-050
    $out(['error' => 'not found'], 404);
}
catch (RuntimeException $e) { $c = (int) $e->getCode(); if ($c >= 400 && $c < 600 && !($e instanceof PDOException)) $out(['error' => $e->getMessage()], $c); error_log('[web_feed] ' . $site . ': ' . $e->getMessage()); $out(['error' => 'server error'], 500); }
catch (Throwable $e) { error_log('[web_feed] ' . $site . ': ' . $e->getMessage()); $out(['error' => 'server error'], 500); }

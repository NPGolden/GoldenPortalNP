<?php
/**
 * Public recruiting feed for goldenas.com.gr/careers — GAS-CP-R89-001.
 *   GET  rcr_feed.php?r=vacancies&lang=en        GET rcr_feed.php?r=events        GET rcr_feed.php?r=set&code=ENG-STD-7
 *   POST rcr_feed.php?r=apply   (json)           POST rcr_feed.php?r=cv&app=APP-…  (multipart, Authorization: Bearer <token>)
 *   POST rcr_feed.php?r=register&event=EVT-…     POST rcr_feed.php?r=request
 * Reads need only the allowed Origin; writes need X-Site-Key = settings.rc_site_key (empty key = feed switched off).
 * Never returns option scores, join links, HR notes or anything about other candidates.
 */
declare(strict_types=1);
define('CP_ROOT', __DIR__); ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
foreach (['db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'nf', 'br', 'hr', 'hr2', 'rcr'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
$origin = (string) cp_setting('rc_site_origin', 'https://goldenas.com.gr'); $key = (string) cp_setting('rc_site_key', '');
header('Access-Control-Allow-Origin: ' . $origin); header('Vary: Origin'); header('Access-Control-Allow-Headers: Content-Type, X-Site-Key, Authorization'); header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
$out = function ($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; };
if ($key === '' || !rcr_has()) $out(['error' => 'feed disabled'], 503);
$r = (string) ($_GET['r'] ?? ''); $m = $_SERVER['REQUEST_METHOD']; $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
// minute rate limit per IP (file-based, like api/v1 RateLimit)
$dir = dirname(__DIR__) . '/storage/rc/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json'; $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
$lim = $m === 'GET' ? max(10, (int) cp_setting('rc_feed_rl_read', '60')) : max(3, (int) cp_setting('rc_feed_rl_write', '10'));   // per IP per minute
if (count($hits) >= $lim) { header('Retry-After: 60'); $out(['error' => 'too many requests'], 429); } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
try {
    if ($m === 'GET') {
        $lang = in_array((string)($_GET['lang'] ?? 'en'), ['en', 'el', 'it', 'nl'], true) ? (string) $_GET['lang'] : 'en';
        if ($r === 'vacancies') $out(['items' => rcr_pub_vacancies($lang)]);
        if ($r === 'events') $out(['items' => rcr_pub_events()]);
        if ($r === 'set') { $s = rcr_set_by_code((string) ($_GET['code'] ?? '')); $s ? $out(rcr_set_public($s, $lang)) : $out(['error' => 'not found'], 404); }
        $out(['error' => 'not found'], 404);
    }
    if (!hash_equals($key, (string) ($_SERVER['HTTP_X_SITE_KEY'] ?? ''))) $out(['error' => 'forbidden'], 403);
    $body = stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== false ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : $_POST;
    if ($r === 'apply') $out(rcr_pub_apply($body, $ip, (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 201);
    if ($r === 'cv') { rcr_pub_cv((string) ($_GET['app'] ?? ''), trim(str_ireplace('Bearer', '', (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''))), $_FILES['cv'] ?? [], $_POST); $out(['status' => 'received']); }
    if ($r === 'register') { rcr_pub_register((string) ($_GET['event'] ?? ''), $body, $ip); $out(['status' => 'registered'], 201); }
    if ($r === 'request') { rcr_pub_request($body); $out(['status' => 'received'], 201); }
    $out(['error' => 'not found'], 404);
} catch (RuntimeException $e) { $c = (int) $e->getCode(); if ($c >= 400 && $c < 600 && !($e instanceof PDOException)) $out(['error' => $e->getMessage()], $c); error_log('[rcr_feed] ' . $e->getMessage()); $out(['error' => 'server error'], 500); }
  catch (Throwable $e) { error_log('[rc_feed] ' . $e->getMessage()); $out(['error' => 'server error'], 500); }

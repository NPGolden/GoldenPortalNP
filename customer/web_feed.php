<?php
/**
 * Website feed — GAS-CP-R90-001 / R90-002.   Header  X-Site-Key: <Settings › System › Website>  on every call.
 *   GET  web_feed.php              the four Fleet & activity counts (integers)
 *   POST web_feed.php?r=ticket     support request from goldenas.com.gr/support   → Website inbox (+ staff notification)
 *   POST web_feed.php?r=access     Customer Portal access request from /portal-access → Website inbox (administrators)
 * Returns the four Fleet & activity counts of the dashboard as integers and nothing else. No session, no cookies, no CORS
 * (browsers are not meant to call it — the website's build pipeline does, once a week), key compared in constant time,
 * 10 requests per IP per minute, generic errors. Empty key = feed switched off (503).
 */
declare(strict_types=1);
define('CP_ROOT', __DIR__); ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
foreach (['version', 'db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'nf', 'tk', 'act', 'web'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex');
$out = function ($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; };
$m = $_SERVER['REQUEST_METHOD']; if ($m !== 'GET' && $m !== 'POST') { header('Allow: GET, POST'); $out(['error' => 'method not allowed'], 405); }
$key = function_exists('web_has') && web_has() ? (string) cp_setting('web_site_key', '') : '';
if ($key === '') $out(['error' => 'feed disabled'], 503);
$ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
$dir = dirname(__DIR__) . '/storage/web/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json';
$hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
if (count($hits) >= ($m === 'GET' ? 10 : 20)) { header('Retry-After: 60'); $out(['error' => 'too many requests'], 429); } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
if (!hash_equals($key, (string) ($_SERVER['HTTP_X_SITE_KEY'] ?? ''))) { usleep(300000); error_log('[web_feed] forbidden from ' . $ip); $out(['error' => 'forbidden'], 403); }
try {
    if ($m === 'GET') { $c = web_counts(); web_mark_pull($ip); $out($c); }
    if (!web_inbox_has()) $out(['error' => 'inbox disabled'], 503);
    $r = (string) ($_GET['r'] ?? ''); $body = json_decode((string) file_get_contents('php://input'), true); if (!is_array($body)) $out(['error' => 'invalid body'], 400);
    $fwd = trim((string) ($body['ip'] ?? '')); $vip = preg_match('/^[0-9a-fA-F:.]{3,45}$/', $fwd) ? $fwd : $ip;   // the website passes the visitor's address
    if ($r === 'ticket') $out(web_pub_ticket($body, $vip), 201);
    if ($r === 'access') $out(web_pub_access($body, $vip), 201);
    $out(['error' => 'not found'], 404);
}
catch (RuntimeException $e) { $c = (int) $e->getCode(); if ($c >= 400 && $c < 600 && !($e instanceof PDOException)) $out(['error' => $e->getMessage()], $c); error_log('[web_feed] ' . $e->getMessage()); $out(['error' => 'server error'], 500); }
catch (Throwable $e) { error_log('[web_feed] ' . $e->getMessage()); $out(['error' => 'server error'], 500); }

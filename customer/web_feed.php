<?php
/**
 * Website statistics feed — GAS-CP-R90-001.   GET web_feed.php   with header  X-Site-Key: <Settings › System › Website>
 * Returns the four Fleet & activity counts of the dashboard as integers and nothing else. No session, no cookies, no CORS
 * (browsers are not meant to call it — the website's build pipeline does, once a week), key compared in constant time,
 * 10 requests per IP per minute, generic errors. Empty key = feed switched off (503).
 */
declare(strict_types=1);
define('CP_ROOT', __DIR__); ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
foreach (['version', 'db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'act', 'web'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex');
$out = function ($d, int $c = 200): void { http_response_code($c); echo json_encode($d, JSON_UNESCAPED_SLASHES); exit; };
if ($_SERVER['REQUEST_METHOD'] !== 'GET') { header('Allow: GET'); $out(['error' => 'method not allowed'], 405); }
$key = function_exists('web_has') && web_has() ? (string) cp_setting('web_site_key', '') : '';
if ($key === '') $out(['error' => 'feed disabled'], 503);
$ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
$dir = dirname(__DIR__) . '/storage/web/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json';
$hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
if (count($hits) >= 10) { header('Retry-After: 60'); $out(['error' => 'too many requests'], 429); } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
if (!hash_equals($key, (string) ($_SERVER['HTTP_X_SITE_KEY'] ?? ''))) { usleep(300000); error_log('[web_feed] forbidden from ' . $ip); $out(['error' => 'forbidden'], 403); }
try { $c = web_counts(); web_mark_pull($ip); $out($c); }
catch (Throwable $e) { error_log('[web_feed] ' . $e->getMessage()); $out(['error' => 'server error'], 500); }

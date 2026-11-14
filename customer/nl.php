<?php
/**
 * Newsletter public endpoint — GAS-CP-R97-039. No session, no cookies, nothing personal in the answer.
 *   GET  nl.php?o=<token>         open pixel (1×1 GIF)
 *   GET  nl.php?c=<token>&l=<n>   counts the click and redirects to the stored address of link n of that issue (never to a given address)
 *   GET  nl.php?u=<token>         unsubscribe page;  POST the same → unsubscribed (one click from the page, or from the mail client)
 *   GET  nl.php?s=<token>         confirms a website subscription (double opt-in)
 *   GET  nl.php?i=<token>         a picture of an issue
 * Tokens are 24 random letters / digits. 240 requests per address per minute.
 */
declare(strict_types=1);
define('CP_ROOT', __DIR__); ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');
foreach (['version', 'db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'mailer', 'nl'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: no-referrer'); header('Cache-Control: no-store');
$home = 'https://www.goldenas.com.gr/'; $gif = function (): void { header('Content-Type: image/gif'); echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit; };
$tok = function (string $k): string { $v = (string)($_GET[$k] ?? ''); return preg_match('/^[A-Za-z0-9]{24}$/', $v) ? $v : ''; };
$page = function (string $title, string $html, int $code = 200): void { http_response_code($code); header('Content-Type: text/html; charset=utf-8'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . htmlspecialchars($title) . '</title><style>body{margin:0;background:#EEF1F5;font:16px/1.6 Aptos,Calibri,Arial,sans-serif;color:#243140}.box{max-width:520px;margin:8vh auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.08)}.top{background:#1F3A5F;border-bottom:3px solid #C9A227;padding:18px 24px}.top img{height:36px}.in{padding:24px;text-align:justify}h1{font-size:21px;color:#1F3A5F;margin:0 0 10px;text-align:center}button{background:#C9A227;color:#1F3A5F;border:0;border-radius:6px;padding:11px 26px;font:bold 15px Aptos,Calibri,Arial,sans-serif;cursor:pointer}.c{text-align:center;margin-top:16px}a{color:#1F3A5F}.ok{color:#2E7D32}</style></head><body><div class="box"><div class="top"><img src="../assets/golden-logo-white.png" alt="Golden A/S"></div><div class="in">' . $html . '<p class="c"><a href="https://www.goldenas.com.gr/">www.goldenas.com.gr</a></p></div></div></body></html>'; exit; };
try {
    if (!function_exists('nl_has') || !nl_has()) { if (isset($_GET['o'])) $gif(); header('Location: ' . $home, true, 302); exit; }
    $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
    $dir = __DIR__ . '/storage/nl/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json';
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
    if (count($hits) >= 240) { http_response_code(429); header('Retry-After: 60'); exit; } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
    if (random_int(1, 200) === 1) foreach (glob($dir . '/*.json') ?: [] as $g) if (filemtime($g) < time() - 3600) @unlink($g);

    if (isset($_GET['o'])) { if (($t = $tok('o')) !== '') nl_pub_open($t); $gif(); }
    if (isset($_GET['c'])) { $t = $tok('c'); $u = $t !== '' ? nl_pub_click($t, max(0, min(999, (int)($_GET['l'] ?? 0)))) : ''; header('Location: ' . ($u !== '' ? $u : $home), true, 302); exit; }
    if (isset($_GET['i'])) { $t = $tok('i'); $im = $t !== '' ? one('SELECT mime, file FROM nl_images WHERE token = ?', [$t]) : null; $p = $im ? nl_dir() . '/' . basename((string)$im['file']) : ''; if (!$im || !is_file($p)) { http_response_code(404); exit; }
        header('Content-Type: ' . $im['mime']); header('Content-Length: ' . filesize($p)); header('Cache-Control: public, max-age=2592000'); readfile($p); exit; }
    if (isset($_GET['u'])) { $t = $tok('u'); if ($t === '') $page('Golden A/S', '<h1>Link not valid</h1><p>This unsubscribe link is not valid any more.</p>', 404);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $ok = nl_pub_unsub($t); $page('Unsubscribed', $ok ? '<h1 class="ok">You are unsubscribed</h1><p>You will not receive newsletters from Golden A/S at this address any more. If this was a mistake, you can subscribe again on our website at any time.</p>' : '<h1>Link not valid</h1><p>This unsubscribe link is not valid any more.</p>', $ok ? 200 : 404); }
        $page('Unsubscribe', '<h1>Unsubscribe</h1><p>Press the button to stop receiving newsletters and event invitations from Golden A/S at this e-mail address.</p><form method="post" action="nl.php?u=' . $t . '" class="c"><button type="submit">Unsubscribe</button></form>'); }
    if (isset($_GET['s'])) { $t = $tok('s'); $ok = $t !== '' && nl_pub_confirm($t); $page($ok ? 'Subscription confirmed' : 'Golden A/S', $ok ? '<h1 class="ok">Subscription confirmed</h1><p>Thank you. You will receive the news of Golden A/S at this address. Every message has an unsubscribe link.</p>' : '<h1>Link not valid</h1><p>This confirmation link is not valid any more. Please subscribe again on our website.</p>', $ok ? 200 : 404); }
    header('Location: ' . $home, true, 302);
} catch (Throwable $e) { error_log('[nl.php] ' . $e->getMessage()); if (isset($_GET['o'])) $gif(); http_response_code(500); }

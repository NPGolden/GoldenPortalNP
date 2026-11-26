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
foreach (['version', 'db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'mailer', 'ct', 'org', 'nl'] as $m) { $f = __DIR__ . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
header('X-Content-Type-Options: nosniff'); header('X-Robots-Tag: noindex, nofollow'); header('Referrer-Policy: same-origin');   /* R97-257: the firewall refuses a POST without referrer (FBP007) — the referrer goes to this site only, never outside */  header('Cache-Control: no-store');
$home = 'https://www.goldenas.com.gr/'; $gif = function (): void { header('Content-Type: image/gif'); echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'); exit; };
$tok = function (string $k): string { $v = (string)($_GET[$k] ?? ''); return preg_match('/^[A-Za-z0-9]{24}$/', $v) ? $v : ''; };
/* R97-050: the page takes the identity of the website the token belongs to — KYMA pages carry the KYMA name, colours and link, and nothing of Golden A/S. */
$BR = ['site' => 'golden', 'name' => 'Golden A/S', 'home' => 'https://www.goldenas.com.gr/', 'host' => 'www.goldenas.com.gr', 'logo' => 'golden-logo-white.png', 'navy' => '#1F3A5F', 'gold' => '#C9A227'];
$brand = function (string $t) use (&$BR, &$home): void { if ($t !== '' && function_exists('nl_token_site') && function_exists('nl_brand')) { $BR = nl_brand(nl_token_site($t)); $home = $BR['home']; } };
$page = function (string $title, string $html, int $code = 200) use (&$BR): void { http_response_code($code); header('Content-Type: text/html; charset=utf-8'); header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES); $top = $BR['logo'] !== '' ? '<img src="../assets/' . $h($BR['logo']) . '" alt="' . $h($BR['name']) . '">' : '<span class="wm">KYMA</span> <span class="ws">MARINE POWER</span>';
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . $h($title) . '</title><style>body{margin:0;background:#EEF1F5;font:16px/1.6 Aptos,Calibri,Arial,sans-serif;color:#243140}.box{max-width:520px;margin:8vh auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.08)}.top{background:' . $BR['navy'] . ';border-bottom:3px solid ' . $BR['gold'] . ';padding:18px 24px}.top img{height:36px}.wm{font:bold 22px/36px Aptos,Calibri,Arial,sans-serif;letter-spacing:3px;color:#fff}.ws{font:12px/36px Aptos,Calibri,Arial,sans-serif;letter-spacing:2px;color:' . $BR['gold'] . '}.in{padding:24px;text-align:justify}h1{font-size:21px;color:' . $BR['navy'] . ';margin:0 0 10px;text-align:center}button{background:' . $BR['gold'] . ';color:' . $BR['navy'] . ';border:0;border-radius:6px;padding:11px 26px;font:bold 15px Aptos,Calibri,Arial,sans-serif;cursor:pointer}.c{text-align:center;margin-top:16px}a{color:' . $BR['navy'] . '}.ok{color:#2E7D32}</style></head><body><div class="box"><div class="top">' . $top . '</div><div class="in">' . $html . '<p class="c"><a href="' . $h($BR['home']) . '">' . ($BR['site'] === 'kyma' ? 'Back to kyma-marinepower.eu' : $h($BR['host'])) . '</a></p></div></div></body></html>'; exit; };
try {
    if (!function_exists('nl_has') || !nl_has()) { if (isset($_GET['o'])) $gif(); header('Location: ' . $home, true, 302); exit; }
    $ip = trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]);
    $dir = __DIR__ . '/storage/nl/rl'; if (!is_dir($dir)) @mkdir($dir, 0700, true); $f = $dir . '/' . md5($ip) . '.json';
    $hits = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : []; $hits = array_values(array_filter($hits, fn($t) => $t > time() - 60));
    if (count($hits) >= 240) { http_response_code(429); header('Retry-After: 60'); exit; } $hits[] = time(); @file_put_contents($f, json_encode($hits), LOCK_EX);
    if (random_int(1, 200) === 1) foreach (glob($dir . '/*.json') ?: [] as $g) if (filemtime($g) < time() - 3600) @unlink($g);

    if (isset($_GET['o'])) { if (($t = $tok('o')) !== '') nl_pub_open($t); $gif(); }
    if (isset($_GET['c'])) { $t = $tok('c'); $brand($t); $u = $t !== '' ? nl_pub_click($t, max(0, min(999, (int)($_GET['l'] ?? 0)))) : ''; header('Location: ' . ($u !== '' ? $u : $home), true, 302); exit; }
    if (isset($_GET['i'])) { $t = $tok('i'); $im = $t !== '' ? one('SELECT mime, file FROM nl_images WHERE token = ?', [$t]) : null; $p = $im ? nl_dir() . '/' . basename((string)$im['file']) : ''; if (!$im || !is_file($p)) { http_response_code(404); exit; }
        header('Content-Type: ' . $im['mime']); header('Content-Length: ' . filesize($p)); header('Cache-Control: public, max-age=2592000'); readfile($p); exit; }
    if (isset($_GET['u'])) { $t = $tok('u'); $brand($t); $nm = htmlspecialchars($BR['name'], ENT_QUOTES); if ($t === '') $page($BR['name'], '<h1>Link not valid</h1><p>This unsubscribe link is not valid any more.</p>', 404);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $ok = nl_pub_unsub($t); $page('Unsubscribed', $ok ? '<h1 class="ok">You are unsubscribed</h1><p>You will not receive newsletters from ' . $nm . ' at this address any more. If this was a mistake, you can subscribe again on our website at any time.</p>' : '<h1>Link not valid</h1><p>This unsubscribe link is not valid any more.</p>', $ok ? 200 : 404); }
        $page('Unsubscribe', '<h1>Unsubscribe</h1><p>Press the button to stop receiving newsletters and event invitations from ' . $nm . ' at this e-mail address.</p><form method="post" action="nl.php?u=' . $t . '" class="c"><button type="submit">Unsubscribe</button></form>'); }
    if (isset($_GET['s'])) { $t = $tok('s'); $brand($t); $nm = htmlspecialchars($BR['name'], ENT_QUOTES); $ok = $t !== '' && nl_pub_confirm($t); $page($ok ? 'Subscription confirmed' : $BR['name'], $ok ? '<h1 class="ok">Subscription confirmed</h1><p>Thank you. You will receive the news of ' . $nm . ' at this address. Every message has an unsubscribe link.</p>' : '<h1>Link not valid</h1><p>This confirmation link is not valid any more. Please subscribe again on our website.</p>', $ok ? 200 : 404); }
    header('Location: ' . $home, true, 302);
} catch (Throwable $e) { error_log('[nl.php] ' . $e->getMessage()); if (isset($_GET['o'])) $gif(); http_response_code(500); }

<?php
/**
 * One-shot flash-queue reset.                             GAS-DOC-FLASH-002
 *
 * Upload to  public_html/employee/install/  and open ONCE in the browser
 * that shows the stacked "session expired" banners:
 *   https://goldensas.com/employee/install/clear_flash.php
 *
 * It empties the leftover notice backlog held in THIS browser's session —
 * the debris from earlier login troubles that keeps replaying on the login
 * page. Works regardless of which patch files are uploaded, on any device
 * (including iPhone/iCloud). Then it sends you to a clean login page.
 *
 * Delete this file afterwards.
 */
declare(strict_types=1);
header('Cache-Control: no-store, max-age=0');

// Boot the SAME session the portal uses, so we clear the right queue.
$idle  = 240 * 60;
$store = dirname(__DIR__) . '/storage/sessions';
if (is_dir($store) && is_writable($store)) {
    session_save_path($store);
    ini_set('session.gc_maxlifetime', (string)($idle + 3600));
}
session_name('GASPOMS');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true, 'samesite' => 'Lax',
]);
session_start();

$had = isset($_SESSION['flash']) ? count((array)$_SESSION['flash']) : 0;

// Remove only the notice backlog; leave any real login progress intact.
unset($_SESSION['flash']);

header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cleared</title>
<style>
  body{font:16px/1.5 system-ui,Segoe UI,Arial,sans-serif;background:#0B1E2D;color:#fff;
       display:flex;min-height:100vh;margin:0;align-items:center;justify-content:center}
  .card{background:#fff;color:#0B1E2D;max-width:420px;padding:28px 30px;border-radius:14px;
        border-top:6px solid #C89B3C;box-shadow:0 12px 40px rgba(0,0,0,.35)}
  h1{font-size:20px;margin:0 0 10px}
  a.btn{display:inline-block;margin-top:16px;background:#C89B3C;color:#0B1E2D;font-weight:600;
        text-decoration:none;padding:11px 20px;border-radius:999px}
  code{background:#F3EEDF;padding:1px 6px;border-radius:5px}
</style></head><body>
<div class="card">
  <h1>Notice backlog cleared ✔</h1>
  <p>Removed <code><?= (int)$had ?></code> leftover message<?= $had === 1 ? '' : 's' ?>
     from this browser's session.</p>
  <p>The stacked "session expired" banners were old debris replaying from
     storage — not real login failures. They're gone now.</p>
  <a class="btn" href="../index.php?p=login">Go to a clean login page</a>
  <p style="margin-top:18px;font-size:13px;color:#5b6b78">
     You can delete <code>clear_flash.php</code> after this.</p>
</div>
</body></html>

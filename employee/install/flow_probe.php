<?php
/**
 * Application session flow probe.                         GAS-DOC-419-004
 *
 * Unlike the standalone probe, this one boots THE REAL PORTAL bootstrap —
 * your config.php, the hardened session code, everything — so what it
 * reports is exactly what a signing-in user experiences.
 *
 * Upload to public_html/employee/install/ and open:
 *   https://goldensas.com/employee/install/flow_probe.php
 * Then RELOAD once.
 *
 *   visits = 2 and "PERSISTED: yes"  -> sessions survive; login will work
 *   visits stuck at 1                -> the report lines below say exactly why
 *
 * Delete this file when done.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/i18n.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/auth.php';

session_boot();                                   // the portal's own boot

$_SESSION['flow_probe'] = (int)($_SESSION['flow_probe'] ?? 0) + 1;
$visits = $_SESSION['flow_probe'];
$sid    = session_id();

session_write_close();                            // force the write NOW
$path   = session_save_path() ?: sys_get_temp_dir();
$file   = rtrim($path, '/') . '/sess_' . $sid;
$onDisk = is_file($file);

$privateDir = dirname(__DIR__) . '/storage/sessions';
$isPrivate  = str_contains($path, '/storage/sessions');

echo "GAS application flow probe\n";
echo str_repeat('=', 56), "\n";
echo "visits (this browser)   : {$visits}   <- reload; must become 2\n";
echo "PERSISTED to disk       : ", $onDisk ? 'yes' : 'NO  <- this is the fault', "\n";
echo "store in use            : ", $isPrivate ? 'PRIVATE storage/sessions (protected)' : 'PHP default (fallback or pre-patch)', "\n";
echo "store path              : {$path}\n";
echo "private dir exists      : ", is_dir($privateDir) ? 'yes' : 'no', "\n";
echo "private dir writable    : ", is_dir($privateDir) && is_writable($privateDir) ? 'yes' : 'no', "\n";
echo "private dir perms       : ", is_dir($privateDir) ? substr(sprintf('%o', fileperms($privateDir)), -4) : '-', "\n";
echo "gc_maxlifetime          : ", ini_get('session.gc_maxlifetime'), " s\n";
echo "idle window (config)    : ", (int) cfg('app.idle_minutes', 0) ?: '(idle_minutes not set)', " min\n";
echo "cookie GASPOMS received : ", isset($_COOKIE['GASPOMS']) ? 'yes' : 'no (first visit or blocked)', "\n";
echo "php                     : ", PHP_VERSION, "\n";

echo "\nInterpretation\n", str_repeat('-', 56), "\n";
if ($visits >= 2 && $onDisk) {
    echo "Sessions persist. Sign-in will hold. If login still bounces,\n";
    echo "the browser is not returning the cookie (clear site data once).\n";
} elseif (!$onDisk) {
    echo "The session file was not written. If store is PRIVATE: check the\n";
    echo "perms line above (storage/ must be 775). If store is PHP default:\n";
    echo "the server's default session path is broken - send this page to\n";
    echo "GoDaddy support as-is.\n";
} else {
    echo "First visit recorded. Reload this page once and read again.\n";
}

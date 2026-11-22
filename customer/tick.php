<?php
/**
 * The clock job of the portal (GAS-CP-R97-196) — run every five minutes by the scheduler of the hosting:
 *     php /home/<account>/public_html/customer/tick.php
 * It reads the feeds into notifications, queues the In / Out reminders that are due and sends the waiting pushes.
 * Command line only: opened through the web server it answers 403 and does nothing.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
define('CP_TICK', 1);
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'goldensas.com'; $_SERVER['REQUEST_URI'] = '/customer/tick.php'; $_GET = []; $_POST = [];
chdir(__DIR__);
require __DIR__ . '/index.php';
$out = [];
try { if (function_exists('nf_sweep')) $out['nf'] = nf_sweep(60, true); } catch (Throwable $e) { $out['nf_err'] = substr($e->getMessage(), 0, 80); }
try { $out += pn_tick(); } catch (Throwable $e) { $out['pn_err'] = substr($e->getMessage(), 0, 80); }
echo date('c') . ' tick ' . json_encode($out) . "\n";

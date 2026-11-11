<?php
/** Portal daily jobs — run once a day:  0 3 * * *  php /path/to/customer/../tools/cron.php
 *  R89-001: recruiting GDPR retention (rc_retention_months, default 6). Add further daily jobs below. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
chdir(dirname(__DIR__) . '/customer');
define('CP_ROOT', dirname(__DIR__) . '/customer'); foreach (['db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'mailer', 'nf', 'hr', 'hr2', 'rcr'] as $m) { $f = CP_ROOT . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
if (function_exists('rcr_has') && rcr_has()) { $n = rcr_retention_run(); echo date('c') . ' rc_retention ' . json_encode($n) . "\n"; }
// R97-005: System Budgets housekeeping — review rows left unapplied for 30 days, uploaded workbooks older than a day
try { if ((bool) scalar("SHOW TABLES LIKE 'sb_import_rows'")) { $n = q('DELETE FROM sb_import_rows WHERE created_at < NOW() - INTERVAL 30 DAY')->rowCount(); $f = 0; foreach ((array) @glob(CP_ROOT . '/storage/uploads/sbimp/*.xlsx') as $old) if (@filemtime($old) < time() - 86400 && @unlink($old)) $f++; echo date('c') . ' sb_housekeeping ' . json_encode(['rows' => $n, 'files' => $f]) . "\n"; } } catch (Throwable $e) { echo date('c') . ' sb_housekeeping failed ' . $e->getMessage() . "\n"; }
if (function_exists('cp_storage_guard')) { $bad = cp_storage_guard(); echo date('c') . ' storage_guard ' . ($bad ? 'FAILED ' . implode(', ', $bad) : 'ok') . "\n"; }


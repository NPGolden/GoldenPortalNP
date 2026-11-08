<?php
/** Portal daily jobs — run once a day:  0 3 * * *  php /path/to/customer/../tools/cron.php
 *  R89-001: recruiting GDPR retention (rc_retention_months, default 6). Add further daily jobs below. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cli only'); }
chdir(dirname(__DIR__) . '/customer');
define('CP_ROOT', dirname(__DIR__) . '/customer'); foreach (['db', 'cache', 'i18n', 'helpers', 'auth', 'lic', 'mailer', 'nf', 'hr', 'hr2', 'rcr'] as $m) { $f = CP_ROOT . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
if (function_exists('rcr_has') && rcr_has()) { $n = rcr_retention_run(); echo date('c') . ' rc_retention ' . json_encode($n) . "\n"; }

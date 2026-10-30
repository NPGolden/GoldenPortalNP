<?php
/**
 * GAS-POMS — scheduled SharePoint push.
 *
 * Run from cron every 15 minutes, e.g. (crontab -e):
 *   0,15,30,45 * * * * /usr/bin/php /home/site/install/sync_sharepoint.php >> /home/site/storage/sync.log 2>&1
 *
 * Refuses to run over HTTP.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/sharepoint.php';

if (!sp_enabled()) { echo date('c') . " sync skipped: SharePoint is off\n"; exit; }

$r = sharepoint_push_open();
echo date('c') . " sync finished: {$r['ok']} sent, {$r['failed']} failed\n";

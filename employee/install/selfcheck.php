<?php
/**
 * GAS-POMS deployment self-check.                         GAS-DOC-DIAG-001
 *
 * Upload to  public_html/employee/install/  and open:
 *   https://goldensas.com/employee/install/selfcheck.php
 *
 * It inspects the ACTUAL files and database on the server and reports, in
 * plain language, which fixes are live and which are still missing — so
 * "the problem persists" becomes an exact list of what to upload.
 *
 * Read-only. Delete when done.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

$root = dirname(__DIR__);                       // employee/
function has(string $file, string $needle): bool {
    $p = $file;
    return is_file($p) && str_contains((string)@file_get_contents($p), $needle);
}

echo "GAS-POMS deployment self-check\n";
echo "generated: ", date('c'), "\n";
echo str_repeat('=', 60), "\n\n";

$checks = [
    // label => [file, marker, what it fixes if present]
    'Cache no-store headers' =>
        [$root.'/index.php', 'no-store', 'stale pages / stale login banners'],
    'Cache-proof redirects (redirect_fresh)' =>
        [$root.'/app/helpers.php', 'redirect_fresh', 'save message showing on the wrong page'],
    'Stateless gate tokens' =>
        [$root.'/app/helpers.php', 'gate_token_ok', 'login "session expired" loops'],
    'Flash de-duplication' =>
        [$root.'/app/helpers.php', 'Never queue the same notice', 'nine stacked login banners'],
    'In-app self-heal' =>
        [$root.'/app/helpers.php', 'REQUEST_URI', 'buttons "doing nothing" on stale tabs'],
    'Mail hardening + mail.log' =>
        [$root.'/app/mailer.php', 'mail_log', 'multi-second hangs, silent email failures'],
    'Presence heartbeat wired' =>
        [$root.'/index.php', 'last_seen_at', 'the "who is online" badge'],
    'Render marker r7' =>
        [$root.'/app/views/_layout.php', 'GAS-RENDERED', 'lets you spot a cached page via Ctrl+U'],
];

$missing = [];
foreach ($checks as $label => [$file, $marker, $fixes]) {
    $ok = has($file, $marker);
    printf("[%s] %-38s\n", $ok ? ' LIVE ' : 'MISSING', $label);
    if (!$ok) { echo "         -> still causing: {$fixes}\n"; $missing[] = $label; }
}

echo "\n", str_repeat('-', 60), "\n";
echo "Database\n", str_repeat('-', 60), "\n";

// DB checks — reuse the app's own config + connection
$dbColOK = false; $mail = '(unknown)';
try {
    require $root.'/app/db.php';
    $col = all("SHOW COLUMNS FROM users LIKE 'last_seen_at'");
    $dbColOK = (bool)$col;
    echo $dbColOK
        ? "[ LIVE ] users.last_seen_at column present (presence can work)\n"
        : "[MISSING] users.last_seen_at — run migrate_r2_presence.sql; the online badge stays hidden until then\n";

    $s = one("SELECT v FROM settings WHERE k='notify_email_enabled'");
    $mail = $s ? $s['v'] : '(row missing)';
    echo "         notify_email_enabled = {$mail}   (must be 1 for emails to send)\n";
} catch (Throwable $e) {
    echo "[ERROR] could not query database: ", $e->getMessage(), "\n";
}

echo "\n", str_repeat('=', 60), "\n";
if (!$missing && $dbColOK) {
    echo "ALL FIXES LIVE. If a page still looks stale, it is the browser:\n";
    echo "clear this site's data once (padlock -> site data -> remove).\n";
} else {
    echo "ACTION NEEDED — upload the files for each MISSING item above from\n";
    echo "the latest patch zips, then reload this page. Missing count: ",
         count($missing) + ($dbColOK ? 0 : 1), "\n";
}
echo "\nEmail note: 'LIVE' on mail hardening only means the safe code is in\n";
echo "place. Whether mail actually SENDS depends on Office 365 accepting it —\n";
echo "read employee/storage/mail.log after posting a test message.\n";

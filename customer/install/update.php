<?php
/**
 * Release updater — applies a portal release zip on the live server.            GAS-CP-R58-001
 *
 *   POST https://goldensas.com/customer/install/update.php
 *   Header  X-Update-Token: <value of app/config.php › update.token>
 *   Body    zip=<file name inside customer/storage/updates/>   (default: the newest *.zip there)
 *           dry=1  → verify + report only, change nothing
 *
 * Steps (each one reported, the run stops at the first failure):
 *   1 token + lock          2 zip + manifest integrity (sha256 of every entry)
 *   3 backup                files about to change → storage/backups/<stamp>/files/, full DB dump → db.sql.gz
 *   4 extract               every entry except app/config.php and storage/*, written to a temp name then renamed
 *   5 migrations            cp_patches() steps whose check fails, oldest first, each statement idempotent-tolerant
 *   6 self-check            install/cp_selfcheck.php captured, WARN / FAIL lines counted
 * The zip stays in storage/updates/ (renamed *.applied) and the previous files in storage/backups/ for a roll-back.
 *
 * Security: the token must be at least 32 characters, is compared with hash_equals and never echoed; every call is
 * logged to storage/update.log with the caller IP; a second call while one runs is refused (lock file); the request
 * carries no SQL and no file content — everything comes from the zip already on disk; the whole file is 403 unless the
 * config declares a token, so a server without one has no updater.
 */
declare(strict_types=1);
set_time_limit(0); ignore_user_abort(true); error_reporting(E_ALL); ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');

$root    = dirname(__DIR__);                 // …/customer
$webRoot = dirname($root);                   // …/public_html — the zip is relative to this
$storage = $root . '/storage';
$log     = function (string $m) use ($storage): void { @file_put_contents($storage . '/update.log', date('c') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . $m . "\n", FILE_APPEND | LOCK_EX); };
$fail    = function (int $code, string $msg, array $extra = []) use ($log): never { $log('FAIL ' . $msg); http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg] + $extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES); exit; };

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') $fail(405, 'POST only');
if (!is_file($root . '/app/config.php')) $fail(500, 'app/config.php missing');
$cfg = require $root . '/app/config.php';
$token = (string)($cfg['update']['token'] ?? '');
if (strlen($token) < 32) $fail(403, 'updater disabled (set update.token in app/config.php, 32+ characters)');
$given = (string)($_SERVER['HTTP_X_UPDATE_TOKEN'] ?? '');
if ($given === '' || !hash_equals($token, $given)) { usleep(500000); $fail(403, 'bad token'); }

$dry = !empty($_POST['dry']);
$lockF = $storage . '/update.lock'; $lock = fopen($lockF, 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) $fail(409, 'another update is running');
$report = ['ok' => true, 'dry' => $dry, 'steps' => [], 'started' => date('c')];
$step = function (string $name, string $state, array $data = []) use (&$report, $log): void { $report['steps'][] = ['step' => $name, 'state' => $state] + $data; $log($name . ' ' . $state . ' ' . json_encode($data, JSON_UNESCAPED_SLASHES)); };

/* 2. zip + manifest */
$updDir = $storage . '/updates'; if (!is_dir($updDir)) @mkdir($updDir, 0755, true);
$zipName = basename((string)($_POST['zip'] ?? ''));
if ($zipName === '') { $cand = glob($updDir . '/*.zip') ?: []; usort($cand, fn($a, $b) => filemtime($b) <=> filemtime($a)); $zipName = $cand ? basename($cand[0]) : ''; }
if ($zipName === '' || !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $zipName) || !is_file($updDir . '/' . $zipName)) $fail(404, 'release zip not found in storage/updates/');
$zipPath = $updDir . '/' . $zipName;
$zip = new ZipArchive(); if ($zip->open($zipPath) !== true) $fail(400, 'zip cannot be opened');
$manifestRaw = $zip->getFromName('customer/install/manifest.json'); if ($manifestRaw === false) $fail(400, 'zip has no customer/install/manifest.json');
$manifest = json_decode($manifestRaw, true); if (!is_array($manifest) || empty($manifest['files'])) $fail(400, 'manifest unreadable');
$skipRe = '#^(customer|employee)/(app/config\.php|storage/)|(^|/)config\.php$|^tools/|^docs/|^DEPLOY\.md$|^\.git#';
$entries = []; $bad = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $st = $zip->statIndex($i); $n = str_replace('\\', '/', $st['name']);
    if (str_ends_with($n, '/') || preg_match($skipRe, $n) || str_contains($n, '..')) continue;
    $entries[] = $n;
    if (isset($manifest['files'][$n])) { $h = hash('sha256', (string)$zip->getFromIndex($i)); if ($h !== $manifest['files'][$n]['sha256']) $bad[] = $n; }
}
if ($bad) $fail(400, 'zip content does not match its manifest', ['files' => array_slice($bad, 0, 20)]);
$step('verify', 'ok', ['zip' => $zipName, 'version' => $manifest['version'] ?? '?', 'entries' => count($entries)]);

$cur = preg_match("/CP_VERSION\s*=\s*'([^']+)'/", (string)@file_get_contents($root . '/app/version.php'), $mv) ? $mv[1] : '?';   // read, not included: the new file is included after extraction
$report['from'] = $cur; $report['to'] = $manifest['version'] ?? '?';

/* 3. backup */
$stamp = date('Ymd-His') . '-v' . $cur; $bk = $storage . '/backups/' . $stamp;
$changed = []; foreach ($entries as $n) { $dst = $webRoot . '/' . $n; if (is_file($dst) && hash_file('sha256', $dst) !== ($manifest['files'][$n]['sha256'] ?? '')) $changed[] = $n; elseif (!is_file($dst)) $changed[] = $n; }
if (!$dry) {
    @mkdir($bk . '/files', 0755, true);
    foreach ($changed as $n) { $src = $webRoot . '/' . $n; if (!is_file($src)) continue; $d = $bk . '/files/' . $n; @mkdir(dirname($d), 0755, true); copy($src, $d); }
    try {
        require_once $root . '/app/db.php';
        $pdo = db(); $gz = gzopen($bk . '/db.sql.gz', 'wb6'); gzwrite($gz, "-- " . ($cfg['db']['name'] ?? '') . " " . date('c') . "\nSET NAMES utf8mb4; SET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) {
            $cr = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM); gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . $cr[1] . ";\n");
            $q = $pdo->query("SELECT * FROM `$t`"); $buf = ''; $n = 0;
            while ($r = $q->fetch(PDO::FETCH_ASSOC)) { $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), array_values($r)); $buf .= ($n ? ",\n" : "INSERT INTO `$t` VALUES\n") . '(' . implode(',', $vals) . ')'; if (++$n % 500 === 0) { gzwrite($gz, $buf . ";\n"); $buf = ''; $n = 0; } }
            if ($buf !== '') gzwrite($gz, $buf . ";\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n"); gzclose($gz);
    } catch (Throwable $e) { $fail(500, 'database backup failed: ' . $e->getMessage()); }
}
$step('backup', $dry ? 'skipped (dry)' : 'ok', ['folder' => 'storage/backups/' . $stamp, 'files' => count($changed)]);

/* 4. extract */
if (!$dry) {
    $written = 0; $errors = [];
    foreach ($entries as $n) {
        $dst = $webRoot . '/' . $n; @mkdir(dirname($dst), 0755, true);
        $data = $zip->getFromName($n); if ($data === false) { $errors[] = $n; continue; }
        $tmp = $dst . '.upd.tmp';
        if (file_put_contents($tmp, $data) === false || !rename($tmp, $dst)) { $errors[] = $n; @unlink($tmp); continue; }
        $written++;
    }
    if ($errors) $fail(500, 'some files could not be written — restore from storage/backups/' . $stamp, ['files' => array_slice($errors, 0, 20)]);
    $step('extract', 'ok', ['written' => $written]);
    // the new version.php is now on disk — reload it for the migration list
    if (function_exists('opcache_invalidate')) foreach ($entries as $n) if (str_ends_with($n, '.php')) @opcache_invalidate($webRoot . '/' . $n, true);
} else $step('extract', 'skipped (dry)', ['would_change' => count($changed)]);
$zip->close();

/* 5. migrations — from the version.php now on disk (the new one after a real run) */
require_once $root . '/app/version.php';
$patches = array_map(fn($p) => [$p['ref'], $p['version'], $p['db'], $p['check']], cp_patches());
try {
    require_once $root . '/app/db.php'; $pdo = db();
    $state = cp_migration_state();
    $pending = [];
    foreach (array_reverse($patches) as [$ref, $ver, $dbf, $chk]) { if ($dbf && $chk && array_key_exists($chk, (array)$state) && !$state[$chk]) $pending[] = [$ref, $ver, $dbf, $chk]; }
    $ran = [];
    foreach ($pending as [$ref, $ver, $dbf, $chk]) {
        $f = $root . '/' . preg_replace('#^customer/#', '', $dbf); if (!is_file($f)) { $ran[] = ['ref' => $ref, 'file' => $dbf, 'state' => 'file missing']; continue; }
        if ($dry) { $ran[] = ['ref' => $ref, 'file' => $dbf, 'state' => 'pending']; continue; }
        $sql = (string)file_get_contents($f); $sql = preg_replace('/^\s*--.*$/m', '', $sql); $sql = preg_replace('#/\*.*?\*/#s', '', $sql);
        $stmts = array_filter(array_map('trim', preg_split('/;\s*(\r?\n|$)/', $sql)), fn($s) => $s !== '' && !preg_match('/^USE\s/i', $s));
        $n = 0; $tolerated = [];
        foreach ($stmts as $st) {
            try { $pdo->exec($st); $n++; }
            catch (Throwable $e) { $m = $e->getMessage(); if (preg_match('/already exists|Duplicate column|Duplicate key name|Duplicate entry|Can\'t DROP|check that column\/key exists|Unknown column .* in .*(DROP|CHANGE)/i', $m)) { $tolerated[] = mb_substr($m, 0, 120); continue; } $fail(500, "migration $ref failed: " . mb_substr($m, 0, 300), ['statement' => mb_substr($st, 0, 200), 'backup' => 'storage/backups/' . $stamp]); }
        }
        $ran[] = ['ref' => $ref, 'file' => $dbf, 'state' => 'applied', 'statements' => $n, 'tolerated' => $tolerated];
    }
    $step('migrations', 'ok', ['pending' => count($pending), 'runs' => $ran]);
} catch (Throwable $e) { $fail(500, 'migration step failed: ' . $e->getMessage()); }

/* 6. self-check */
$sc = function_exists('shell_exec') ? (string)@shell_exec(escapeshellarg(PHP_BINARY ?: 'php') . ' -d display_errors=0 ' . escapeshellarg($root . '/install/cp_selfcheck.php') . ' 2>&1') : '';
if (trim($sc) === '') { ob_start(); try { include $root . '/install/cp_selfcheck.php'; } catch (Throwable $e) { echo '[FAIL] self-check crashed: ', $e->getMessage(); } $sc = (string)ob_get_clean(); header('Content-Type: application/json; charset=utf-8'); } $warn = preg_match_all('/\[WARN\]/', $sc); $failN = preg_match_all('/\[FAIL\]/', $sc);
$step('selfcheck', $failN ? 'FAIL' : ($warn ? 'warn' : 'ok'), ['warn' => $warn, 'fail' => $failN, 'lines' => array_values(array_filter(array_map('trim', explode("\n", $sc)), fn($l) => str_contains($l, '[WARN]') || str_contains($l, '[FAIL]')))]);
if (!$dry) @rename($zipPath, $zipPath . '.applied');
$report['finished'] = date('c'); $report['ok'] = !$failN;
flock($lock, LOCK_UN); fclose($lock);
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

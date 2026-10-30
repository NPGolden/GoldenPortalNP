<?php
/**
 * GAS-POMS — preflight check.
 *
 * Open this in a browser straight after uploading over FTP, before running
 * setup.php. It tells you what the host is missing, without touching the
 * database or writing anything permanent.
 *
 * DELETE THIS FILE together with the rest of install/ once you are live.
 */

declare(strict_types=1);

$root    = dirname(__DIR__);
$results = [];

function check(string $label, bool $ok, string $detail = '', bool $fatal = true): array {
    return compact('label', 'ok', 'detail', 'fatal');
}

/* ---------------------------------------------------------- PHP itself */

$results[] = check(
    'PHP 8.1 or newer',
    version_compare(PHP_VERSION, '8.1.0', '>='),
    'Running PHP ' . PHP_VERSION
);

foreach (['pdo_mysql' => true, 'mbstring' => true, 'fileinfo' => true,
          'curl' => true, 'openssl' => true, 'json' => true] as $ext => $fatal) {
    $results[] = check("Extension: {$ext}", extension_loaded($ext),
        extension_loaded($ext) ? 'Loaded' : 'Ask your host to enable it', $fatal);
}

$results[] = check(
    'Extension: intl (optional)',
    extension_loaded('intl'),
    extension_loaded('intl') ? 'Loaded' : 'Not required — dates are formatted from the language files',
    false
);

/* -------------------------------------------------------------- upload */

$umax = ini_get('upload_max_filesize');
$pmax = ini_get('post_max_size');
$results[] = check("upload_max_filesize at least 16M", to_bytes($umax) >= 16 * 1024 * 1024,
    "Currently {$umax}", false);
$results[] = check("post_max_size at least 64M", to_bytes($pmax) >= 64 * 1024 * 1024,
    "Currently {$pmax}", false);

function to_bytes(string $v): int {
    $v = trim($v);
    $n = (int)$v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024; // fall through
        case 'm': $n *= 1024; // fall through
        case 'k': $n *= 1024;
    }
    return $n;
}

/* --------------------------------------------------------------- files */

$results[] = check('app/config.php exists',
    is_file($root . '/app/config.php'),
    'Copy app/config.sample.php to app/config.php and fill in the database details');

foreach (['en', 'el', 'bg', 'ru', 'it'] as $l) {
    $results[] = check("Language file: lang/{$l}.php", is_file("{$root}/lang/{$l}.php"),
        is_file("{$root}/lang/{$l}.php") ? 'Present' : 'Upload it in binary mode');
}

$results[] = check('assets/qrcode.min.js uploaded',
    is_file($root . '/assets/qrcode.min.js') && filesize($root . '/assets/qrcode.min.js') > 10000,
    'Needed for the authenticator QR code. Upload assets/ in binary mode.');

/* ------------------------------------------------------------- storage */

$storage = $root . '/storage/uploads';
if (!is_dir($storage)) @mkdir($storage, 0775, true);
$results[] = check('storage/uploads is writable', is_dir($storage) && is_writable($storage),
    'chmod 775 storage and storage/uploads in your FTP client');

$results[] = check('storage/.htaccess present', is_file($root . '/storage/.htaccess'),
    'Protects photos and voice notes from direct access. FTP clients often hide dot-files — turn that off.', false);
$results[] = check('app/.htaccess present', is_file($root . '/app/.htaccess'),
    'Protects app/config.php from being read over the web.', false);
$results[] = check('Root .htaccess present', is_file($root . '/.htaccess'),
    'Enforces HTTPS and blocks .sql files. Not used on IIS — web.config covers that.', false);

/* --------------------------------------------------------------- HTTPS */

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$results[] = check('Served over HTTPS', $https,
    'Two-factor sign-in over plain HTTP is not acceptable. Install a certificate before going live.');

/* ------------------------------------------------------------ database */

$dbOk = false; $dbMsg = 'app/config.php missing';
if (is_file($root . '/app/config.php')) {
    $cfg = require $root . '/app/config.php';
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $cfg['db']['host'], (int)$cfg['db']['port'], $cfg['db']['name'], $cfg['db']['charset']);
        $pdo = new PDO($dsn, $cfg['db']['user'], $cfg['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbOk = true; $dbMsg = 'Connected to ' . $ver;
    } catch (Throwable $e) {
        $dbMsg = $e->getMessage();
    }
}
$results[] = check('Database reachable', $dbOk, $dbMsg);

$fatalFails = array_filter($results, fn($r) => !$r['ok'] && $r['fatal']);
$warnings   = array_filter($results, fn($r) => !$r['ok'] && !$r['fatal']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preflight — Parts Orders</title>
<link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="sheet" style="max-width:820px;margin:0 auto;">
  <p class="eyebrow">Before you install</p>
  <h1>Preflight check</h1>
  <p class="lede">Run after uploading, before <code>setup.php</code>. Nothing here changes your data.</p>

  <?php if (!$fatalFails): ?>
    <div class="flash flash--good">
      Everything required is in place<?= $warnings ? ', with ' . count($warnings) . ' advisory item(s) below' : '' ?>.
      <a href="setup.php">Continue to the installer</a>.
    </div>
  <?php else: ?>
    <div class="flash flash--bad">
      <?= count($fatalFails) ?> item(s) must be fixed before the system will run.
    </div>
  <?php endif; ?>

  <div class="card">
    <table>
      <thead><tr><th style="width:34px;"></th><th>Check</th><th>Detail</th></tr></thead>
      <tbody>
      <?php foreach ($results as $r): ?>
        <tr>
          <td><?php if ($r['ok']): ?>
                <span class="pill pill--good">OK</span>
              <?php elseif ($r['fatal']): ?>
                <span class="pill pill--bad">Fix</span>
              <?php else: ?>
                <span class="pill pill--warn">Note</span>
              <?php endif; ?></td>
          <td><strong><?= htmlspecialchars($r['label']) ?></strong></td>
          <td class="hint"><?= htmlspecialchars($r['detail']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h2>Common FTP mistakes</h2>
    <ul style="color:var(--ink-2);font-size:14px;line-height:1.7;">
      <li><strong>Dot-files not uploaded.</strong> <code>.htaccess</code> starts with a dot. In FileZilla:
          Server → Force showing hidden files. In WinSCP: Options → Preferences → Panels → Show hidden files.</li>
      <li><strong>ASCII transfer mode.</strong> Set your client to <em>Binary</em> or <em>Auto</em>, or
          <code>qrcode.min.js</code> and the Greek, Bulgarian and Russian language files will arrive corrupted.</li>
      <li><strong>Wrong folder.</strong> <code>index.php</code> must sit in the folder your domain points at,
          usually <code>public_html</code>, <code>httpdocs</code> or <code>www</code>.</li>
      <li><strong>Permissions.</strong> Folders 755, files 644, and <code>storage/uploads</code> 775.</li>
    </ul>
  </div>
</div>
</body>
</html>

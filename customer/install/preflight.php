<?php
/** Customer Portal — post-FTP check. Open in a browser after upload;
 *  DELETE the install/ folder once you are live.                     */
declare(strict_types=1);
$root = dirname(__DIR__);
$rows = [];
$ok = fn(bool $b) => $b ? 'OK' : 'FIX';
$rows[] = ['PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>='), 'Running ' . PHP_VERSION];
foreach (['pdo_mysql','mbstring','json','openssl','curl'] as $ext)
    $rows[] = ["Extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'Loaded' : 'Enable it in the hosting panel (GoDaddy: cPanel → Select PHP Version → Extensions)'];
$rows[] = ['app/config.php exists', is_file("$root/app/config.php"), 'Copy app/config.sample.php and fill in the MySQL details'];
foreach (['en','el','it'] as $l)
    $rows[] = ["Language file: lang/$l.php", is_file("$root/lang/$l.php"), 'Upload in binary mode'];
$rows[] = ['storage/uploads writable', is_dir("$root/storage/uploads") && is_writable("$root/storage/uploads"), 'chmod 775'];
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$rows[] = ['Served over HTTPS', $https, 'Issue the free certificate in the GoDaddy panel'];
$dbOk = false; $msg = 'app/config.php missing';
if (is_file("$root/app/config.php")) {
    $c = require "$root/app/config.php";
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['db']['host'], (int)$c['db']['port'], $c['db']['name'], $c['db']['charset']),
            $c['db']['user'], $c['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $dbOk = true; $msg = 'Connected: ' . $pdo->query('SELECT VERSION()')->fetchColumn();
    } catch (Throwable $e) { $msg = $e->getMessage(); }
}
$rows[] = ['Database reachable', $dbOk, $msg];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Preflight — Customer Portal</title>
<link rel="stylesheet" href="../../assets/portal.css"></head>
<body><div class="sheet" style="max-width:760px;margin:0 auto;">
<p class="eyebrow">Before you go live</p><h1>Customer Portal preflight</h1>
<div class="card"><table><thead><tr><th></th><th>Check</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($rows as [$label, $pass, $detail]): ?>
<tr><td><span class="pill pill--<?= $pass ? 'green' : 'red' ?>"><?= $ok($pass) ?></span></td>
<td><b><?= htmlspecialchars($label) ?></b></td><td class="hint"><?= htmlspecialchars((string)$detail) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<p class="hint">Import install/schema.sql (and optionally seed_demo.sql) via phpMyAdmin, then delete the install/ folder.</p>
</div></body></html>

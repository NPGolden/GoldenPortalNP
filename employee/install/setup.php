<?php
/**
 * GAS-POMS — first-run installer.
 *
 * Creates the schema, loads reference data and sets up the first
 * administrator. DELETE THIS FILE once the system is live.
 */

declare(strict_types=1);
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/i18n.php';
require __DIR__ . '/../app/helpers.php';

session_start();
i18n_boot();

$done  = [];
$error = null;

/* Refuse to run twice. */
$installed = false;
try {
    $installed = (bool) scalar("SELECT COUNT(*) FROM users") > 0;
} catch (Throwable $e) { /* tables not there yet */ }

if ($installed && ($_GET['force'] ?? '') !== 'yes') {
    exit('This system is already installed. Delete install/setup.php from the server.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name  = trim((string)$_POST['full_name']);
        $email = trim((string)$_POST['email']);
        $pass  = (string)$_POST['password'];
        $loc   = locale_valid((string)($_POST['locale'] ?? '')) ? (string)$_POST['locale'] : DEFAULT_LOCALE;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a name and a valid email address.');
        if (strlen($pass) < 10) throw new RuntimeException('The password must be at least 10 characters.');

        $pdo = db();

        // 1. schema
        $sql = file_get_contents(__DIR__ . '/schema.sql');
        run_sql_script($pdo, $sql);
        $done[] = 'Tables created.';

        // 2. reference data
        $sql = file_get_contents(__DIR__ . '/seed.sql');
        run_sql_script($pdo, $sql);
        $done[] = 'Roles, settings and a starter vessel list loaded.';

        // 3. administrator
        $roleId = (int) scalar('SELECT id FROM roles WHERE code = "system_admin"');
        q('INSERT INTO users (full_name, email, password_hash, role_id, locale) VALUES (?, ?, ?, ?, ?)',
          [$name, $email, password_hash($pass, PASSWORD_DEFAULT), $roleId, $loc]);
        setting_put('default_locale', $loc);
        $done[] = 'Administrator account created for ' . htmlspecialchars($email) . '.';
        $done[] = 'Interface language set to ' . LOCALES[$loc]['native'] . '. Each person can change their own.';

        // 4. storage
        $dir = (string) cfg('app.storage_path');
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $done[] = is_writable($dir) ? 'Upload folder is writable.' : 'WARNING: ' . $dir . ' is not writable. Fix permissions before engineers attach photos.';

        $_SESSION['setup_done'] = $done;
        header('Location: setup.php?ok=1');
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/** Splits on ; but respects the DELIMITER $$ block used by the trigger. */
function run_sql_script(PDO $pdo, string $sql): void {
    $parts = preg_split('/DELIMITER\s+\$\$(.*?)DELIMITER\s+;/s', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
    foreach ($parts as $i => $chunk) {
        $chunk = trim($chunk);
        if ($chunk === '') continue;
        if ($i % 2 === 1) {                       // the DELIMITER block
            foreach (array_filter(array_map('trim', explode('$$', $chunk))) as $stmt) {
                $pdo->exec($stmt);
            }
        } else {
            $pdo->exec($chunk);
        }
    }
}

$ok = isset($_GET['ok']);
$doneList = $_SESSION['setup_done'] ?? [];
unset($_SESSION['setup_done']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install — Parts Orders</title>
<link rel="stylesheet" href="../assets/app.css">
</head>
<body>
<div class="gate">
  <div>
    <?php if ($ok): ?>
      <div class="gate-card">
        <p class="eyebrow">Installed</p>
        <h1>Ready to go</h1>
        <ul style="padding-left:18px;color:var(--ink-2);font-size:14px;">
          <?php foreach ($doneList as $d): ?><li><?= htmlspecialchars($d) ?></li><?php endforeach; ?>
        </ul>
        <div class="flash flash--warn" style="margin-top:18px;">
          Delete the <code>install/</code> folder from the server now. Then sign in and enrol your authenticator app.
        </div>
        <a class="btn btn--gold" href="../index.php">Go to sign-in</a>
      </div>
    <?php else: ?>
      <form class="gate-card" method="post">
        <p class="eyebrow">First run</p>
        <h1>Install Parts Orders</h1>
        <p class="lede">This creates the database tables and your administrator account. It only runs once.</p>

        <?php if ($error): ?><div class="flash flash--bad"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <div class="field">
          <label for="full_name">Your name</label>
          <input id="full_name" name="full_name" type="text" required autofocus>
        </div>
        <div class="field">
          <label for="email">Your work email</label>
          <input id="email" name="email" type="email" required>
        </div>
        <div class="field">
          <label for="password">Choose a password</label>
          <input id="password" name="password" type="password" required minlength="10">
          <p class="hint">At least 10 characters. You will add two-factor at first sign-in.</p>
        </div>
        <div class="field">
          <label for="locale">Interface language</label>
          <select id="locale" name="locale">
            <?php foreach (LOCALES as $c => $m): ?>
              <option value="<?= $c ?>"<?= $c === locale() ? ' selected' : '' ?>><?= $m['native'] ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint">This becomes the default for new accounts. English, Ελληνικά, Български, Русский and Italiano are available.</p>
        </div>

        <button class="btn btn--gold" type="submit">Install</button>
      </form>
      <p class="gate-foot">Connecting to <?= htmlspecialchars((string) cfg('db.name')) ?> on <?= htmlspecialchars((string) cfg('db.host')) ?></p>
    <?php endif; ?>
  </div>
</div>
</body>
</html>

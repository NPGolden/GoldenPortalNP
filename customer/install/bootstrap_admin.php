<?php
/**
 * First-admin bootstrap for the Customer Portal.             GAS-CP-R3-002
 *
 * Creates the first Golden A/S admin account — or promotes an existing
 * account to admin (and optionally resets its password). Needs NO existing
 * account. Protected by the softone.sync_key from app/config.php.
 *
 * Open in a browser:  install/bootstrap_admin.php   (it shows a form —
 * nothing goes in the URL, so web firewalls and logs never see secrets).
 * Delete this file afterwards.
 */
declare(strict_types=1);
header('Cache-Control: no-store, max-age=0');
require __DIR__ . '/../app/db.php';

$msg = ''; $ok = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key   = (string)($_POST['key'] ?? '');
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $name  = trim((string)($_POST['name'] ?? 'Administrator'));
    $pass  = (string)($_POST['password'] ?? '');
    if ($key === '' || !hash_equals(trim((string)cfg('softone.sync_key', '')), trim($key))) {
        $msg = 'Refused: the key must equal softone.sync_key in app/config.php.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $msg = 'Give a valid email.';
    } elseif (!all("SHOW COLUMNS FROM customer_users LIKE 'role'")) {
        $msg = 'The R3 migration has not been run (customer_users has no role column). Import install/migrate_cp_r3_pm_tool.sql in phpMyAdmin, then retry.';
    } else {
        $u = one('SELECT id, role FROM customer_users WHERE email = ?', [$email]);
        if ($u) {
            if ($pass !== '' && strlen($pass) < 10) { $msg = 'Password must be at least 10 characters.'; }
            else {
                q('UPDATE customer_users SET role = "admin", customer_id = NULL, is_active = 1, failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
                if ($pass !== '') q('UPDATE customer_users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                $msg = "OK — {$email} promoted to admin (was {$u['role']})" . ($pass !== '' ? ', password reset' : '') . ', unlocked.'; $ok = true;
            }
        } elseif (strlen($pass) < 10) {
            $msg = 'New account: the password must be at least 10 characters.';
        } else {
            q('INSERT INTO customer_users (customer_id, role, full_name, email, password_hash, locale, is_active) VALUES (NULL, "admin", ?, ?, ?, "en", 1)',
              [$name ?: 'Administrator', $email, password_hash($pass, PASSWORD_DEFAULT)]);
            $msg = "OK — admin account created: {$email}."; $ok = true;
        }
    }
}
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bootstrap admin</title>
<style>
 body{font:15px/1.5 Aptos,"Segoe UI",system-ui,sans-serif;background:#0B1E2D;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center;color:#0B1E2D}
 .card{background:#fff;border-top:6px solid #C89B3C;border-radius:14px;padding:28px 30px;width:min(460px,92vw);box-shadow:0 12px 40px rgba(0,0,0,.35)}
 h1{font-size:20px;margin:0 0 6px} p{margin:0 0 14px;color:#4A5A68}
 label{display:block;font-size:12px;letter-spacing:.04em;color:#4A5A68;margin:12px 0 4px}
 input{width:100%;box-sizing:border-box;padding:10px 12px;border:1px solid #D9DEE3;border-radius:8px;font:inherit}
 button{margin-top:18px;width:100%;background:#C89B3C;color:#0B1E2D;font-weight:700;border:0;border-radius:999px;padding:12px;cursor:pointer;font:inherit}
 .msg{padding:10px 14px;border-radius:8px;margin-bottom:12px;background:#FBE9E7;color:#8A2E1F}
 .msg.ok{background:#E6F2EC;color:#1F5C41}
 a{color:#C89B3C}
</style></head><body>
<div class="card">
  <h1>Customer portal — first admin</h1>
  <p>Creates the first Golden A/S admin, or promotes an existing account and resets its password.</p>
  <?php if ($msg): ?><div class="msg<?= $ok ? ' ok' : '' ?>"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($ok): ?>
    <p><a href="../index.php?p=login">Sign in now</a> — then <strong>delete install/bootstrap_admin.php</strong>.</p>
  <?php else: ?>
  <form method="post" autocomplete="off">
    <label>Key (softone.sync_key from app/config.php)</label><input name="key" type="password" required>
    <label>Email</label><input name="email" type="email" required value="np@goldenas.com.gr">
    <label>Full name</label><input name="name" type="text" value="Nikos Polykandriotis">
    <label>Password (10+ characters; leave empty to keep an existing one)</label><input name="password" type="password" minlength="10">
    <button type="submit">Create / promote admin</button>
  </form>
  <?php endif; ?>
</div>
</body></html>

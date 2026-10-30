<?php
/** GAS-POMS — sessions, two-factor login, role checks, access log. */

declare(strict_types=1);

/** True only if a byte can really be written, read back and deleted there. */
function session_store_proven(string $dir): bool {
    $probe = $dir . '/.probe_' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'ok') === false) return false;
    $ok = @file_get_contents($probe) === 'ok';
    @unlink($probe);
    return $ok;
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    /* Inactivity window (minutes). Rolling: every request refreshes it, so
     * an active person is NEVER logged out — only true inactivity expires.
     * Configure in config.php: 'idle_minutes'. Legacy 'idle_timeout'
     * (seconds) is honoured if a config still carries it. */
    $idle = 60 * max(5, (int) cfg('app.idle_minutes', 0));
    if ($idle < 600) $idle = max(600, (int) cfg('app.idle_timeout', 14400));   // default 4 h

    /* Private session store under storage/: on shared hosting the common
     * /tmp is garbage-collected on OTHER sites' schedules (often ~24 min),
     * which killed sessions mid-pause. Our own directory + our own
     * lifetime makes expiry OURS alone. storage/ is .htaccess-denied. */
    $store = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($store)) @mkdir($store, 0700, true);
    // Adopt the private store only after PROVING a real write works there —
    // is_writable() can lie on shared hosts (ACLs, quotas). On any doubt,
    // fall back to PHP's default path: slower GC, but never a dead session.
    if (is_dir($store) && session_store_proven($store)) {
        session_save_path($store);
        ini_set('session.gc_maxlifetime', (string) ($idle + 3600));   // GC well after idle
    }

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('GASPOMS');
    session_start();

    // Inactivity-only expiry (rolling)
    if (!empty($_SESSION['last_seen']) && (time() - $_SESSION['last_seen']) > $idle) {
        access_log('logout', $_SESSION['uid'] ?? null, null, 'Idle timeout');
        session_unset();
        session_destroy();
        session_start();
        flash('warn', t('login.idle'));
    }
    $_SESSION['last_seen'] = time();
}

/* ------------------------------------------------------------ access log */

function access_log(string $event, ?int $userId = null, ?string $emailTried = null, ?string $detail = null): void {
    try {
        q('INSERT INTO access_log (user_id, email_tried, event, detail, ip_address, user_agent)
           VALUES (?, ?, ?, ?, ?, ?)',
          [$userId, $emailTried, $event, $detail, client_ip(), user_agent()]);
    } catch (Throwable $e) {
        error_log('access_log failed: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------- current */

function current_user(): ?array {
    static $u = null;
    if ($u !== null) return $u;
    if (empty($_SESSION['uid']) || empty($_SESSION['2fa_ok'])) return null;

    $u = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.id = ? AND u.is_active = 1', [$_SESSION['uid']]);
    if ($u) $u['role_name'] = role_label($u['role_code']);
    return $u;
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login');
    return $u;
}

function role(): string {
    return current_user()['role_code'] ?? '';
}

function is_role(string ...$codes): bool {
    return in_array(role(), $codes, true);
}

function require_role(string ...$codes): array {
    $u = require_login();
    if (!in_array($u['role_code'], $codes, true)) {
        http_response_code(403);
        exit(t('err.noaccess'));
    }
    return $u;
}

function is_admin(): bool {
    return role() === 'system_admin';
}

/* ------------------------------------------------------------ login flow */

/**
 * Step 1 — verify email + password.
 * Returns ['ok'=>bool, 'error'=>string|null, 'user'=>array|null].
 */
function attempt_password(string $email, string $password): array {
    $u = one('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
              WHERE u.email = ?', [$email]);

    if (!$u) {
        access_log('login_failed', null, $email, 'Unknown email');
        return ['ok' => false, 'error' => t('login.bad'), 'user' => null];
    }
    if (!$u['is_active']) {
        access_log('login_failed', (int)$u['id'], $email, 'Account disabled');
        return ['ok' => false, 'error' => t('login.disabled'), 'user' => null];
    }
    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        $mins = (int) ceil((strtotime($u['locked_until']) - time()) / 60);
        access_log('login_failed', (int)$u['id'], $email, 'Locked out');
        return ['ok' => false, 'error' => t('login.locked', ['mins' => $mins]), 'user' => null];
    }
    if (!password_verify($password, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $max   = (int) cfg('app.max_failed_logins', 5);
        if ($fails >= $max) {
            $until = date('Y-m-d H:i:s', time() + 60 * (int) cfg('app.lockout_minutes', 15));
            q('UPDATE users SET failed_logins = 0, locked_until = ? WHERE id = ?', [$until, $u['id']]);
            access_log('lockout', (int)$u['id'], $email, "Locked after {$fails} failed attempts");
            return ['ok' => false, 'error' => t('login.lockednow'), 'user' => null];
        }
        q('UPDATE users SET failed_logins = ? WHERE id = ?', [$fails, $u['id']]);
        access_log('login_failed', (int)$u['id'], $email, "Bad password ({$fails})");
        return ['ok' => false, 'error' => t('login.bad'), 'user' => null];
    }

    q('UPDATE users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);
    access_log('login_success', (int)$u['id'], $email);
    return ['ok' => true, 'error' => null, 'user' => $u];
}

/** Step 2 — verify the six-digit code from the authenticator app. */
function attempt_totp(int $userId, string $code): bool {
    $u = one('SELECT id, email, totp_secret FROM users WHERE id = ?', [$userId]);
    if (!$u || !$u['totp_secret']) return false;

    if (totp_verify($u['totp_secret'], $code)) {
        access_log('twofa_success', $userId, $u['email']);
        return true;
    }
    access_log('twofa_failed', $userId, $u['email'], 'Bad code');
    return false;
}

function finish_login(int $userId): void {
    session_regenerate_id(true);
    $_SESSION['uid']    = $userId;
    $_SESSION['2fa_ok'] = true;
    q('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
}

function do_logout(): void {
    if (!empty($_SESSION['uid'])) access_log('logout', (int)$_SESSION['uid']);
    session_unset();
    session_destroy();
}

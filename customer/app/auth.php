<?php
/** Customer sign-in: email + password, throttled, sessions with idle timeout.
 *  Every query in the portal is scoped to the signed-in customer_id.       */
declare(strict_types=1);

function cp_store_proven(string $dir): bool {
    $p = $dir . '/.probe_' . bin2hex(random_bytes(4));
    if (@file_put_contents($p, 'ok') === false) return false;
    $ok = @file_get_contents($p) === 'ok';
    @unlink($p);
    return $ok;
}

function session_boot(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    /* Rolling inactivity window — active people are never logged out. R49-001: 30 min by default, set under Settings › Login history. */
    $idle = 60 * sec_cfg('sec_idle_minutes');

    /* Private session store: immune to the shared host's ~24-minute
     * garbage collection of the common /tmp. */
    $store = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($store)) @mkdir($store, 0700, true);
    if (is_dir($store) && cp_store_proven($store)) {      // proven by a real write
        session_save_path($store);
        ini_set('session.gc_maxlifetime', (string) ($idle + 3600));
    }

    session_name((string)cfg('app.session_name', 'GASCP'));
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')]);
    session_start();
    if (!empty($_SESSION['last_seen']) && time() - $_SESSION['last_seen'] > $idle) sec_end_session('expired', 'login.idle');
    /* R49-001: absolute lifetime, device binding and the per-user epoch ("sign out everywhere") are checked on every request. */
    if (!empty($_SESSION['cu_id'])) {
        if (!empty($_SESSION['started']) && time() - (int)$_SESSION['started'] > 3600 * sec_cfg('sec_max_hours')) sec_end_session('expired', 'login.maxage');
        elseif (sec_cfg('sec_bind_ua') && !empty($_SESSION['ua']) && $_SESSION['ua'] !== sec_ua_hash()) sec_end_session('device', 'login.device');
        elseif (sec_cfg('sec_bind_ip') && !empty($_SESSION['ipn']) && $_SESSION['ipn'] !== sec_ip_net()) sec_end_session('device', 'login.device');
    }
    if (($_GET['p'] ?? '') !== 'ping') $_SESSION['last_seen'] = time();        // the presence heartbeat never extends the idle time-out
}
/** Session policy (Settings › Login history), with safe defaults when the settings table is absent. R49-001 */
function sec_cfg(string $k): int {
    static $c = []; if (isset($c[$k])) return $c[$k];
    $def = ['sec_idle_minutes' => (int) cfg('app.idle_minutes', 30), 'sec_max_hours' => 10, 'sec_bind_ua' => 1, 'sec_bind_ip' => 0, 'sec_close_signout' => 1];
    $v = function_exists('cp_setting') ? cp_setting($k) : null; $v = $v === null || $v === '' ? ($def[$k] ?? 0) : (int)$v;
    if ($k === 'sec_idle_minutes') $v = max(5, min(480, $v)); if ($k === 'sec_max_hours') $v = max(1, min(72, $v));
    return $c[$k] = $v;
}
function sec_csp_mode(): string { $v = function_exists('cp_setting') ? (string) cp_setting('sec_csp', 'on') : 'on'; return in_array($v, ['on', 'report', 'off'], true) ? $v : 'on'; }   // R53-001
function sec_ua_hash(): string { return substr(hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 32); }
function sec_ip_net(): string { $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]); return str_contains($ip, ':') ? implode(':', array_slice(explode(':', $ip), 0, 4)) : implode('.', array_slice(explode('.', $ip), 0, 3)); }   // /24 or /64
/** End the current session for a policy reason: log it, mark the person offline, start a clean session with a message. */
function sec_end_session(string $outcome, string $msgKey): void {
    $uid = (int)($_SESSION['cu_id'] ?? 0);
    if ($uid) { try { $em = (string) scalar('SELECT email FROM customer_users WHERE id = ?', [$uid]); login_log($uid, $em, $outcome); } catch (Throwable $e) { try { login_log($uid, '', 'logout'); } catch (Throwable $e2) {} } try { q('UPDATE customer_users SET last_seen_at = NULL WHERE id = ?', [$uid]); } catch (Throwable $e) {} }
    session_unset(); session_destroy(); session_start(); session_regenerate_id(true);
    flash('warn', t($msgKey));
}
/** Mobile devices of a person (R52 tables), newest first; [] when the mobile module is not installed. R53-002 */
function mob_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'mob_devices'"); } catch (Throwable $e) { $h = false; } } return $h; }
function mob_devices(int $uid): array { return mob_has() ? all('SELECT * FROM mob_devices WHERE user_id = ? ORDER BY revoked_at IS NULL DESC, last_seen_at DESC, id DESC', [$uid]) : []; }
function mob_revoke(int $deviceId): void { require_cp_admin(); if (!mob_has()) return; q('UPDATE mob_devices SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$deviceId]); q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [$deviceId]); }
/** ?p=relock — the browser was closed and reopened (window marker lost): the page asks the server to drop the session. R49-001 */
function sec_relock(): void {
    header('Cache-Control: no-store'); if (empty($_SESSION['cu_id']) || !sec_cfg('sec_close_signout')) { http_response_code(204); exit; }
    sec_end_session('closed', 'login.closed'); http_response_code(204); exit;
}
/** Sign this person out of every browser: the sessions carry the epoch they were opened with. */
function sec_revoke_all(int $uid): void { q('UPDATE customer_users SET session_epoch = session_epoch + 1, last_seen_at = NULL WHERE id = ?', [$uid]); try { q('UPDATE mob_refresh_tokens t JOIN mob_devices d ON d.id = t.device_id SET t.revoked_at = NOW() WHERE d.user_id = ? AND t.revoked_at IS NULL', [$uid]); } catch (Throwable $e) {} }   // R52: app refresh tokens too

function current_user(): ?array {
    if (empty($_SESSION['cu_id'])) return null;
    static $u = false;
    if ($u === false) {
        // LEFT JOIN: staff/admin accounts belong to no customer.
        $u = one('SELECT cu.*, c.name AS customer_name FROM customer_users cu
                  LEFT JOIN customers c ON c.id = cu.customer_id
                  WHERE cu.id = ? AND cu.is_active = 1 AND (c.id IS NULL OR c.is_active = 1)', [$_SESSION['cu_id']]);
        if ($u && array_key_exists('session_epoch', $u) && isset($_SESSION['epoch']) && (int)$_SESSION['epoch'] !== (int)$u['session_epoch']) { $u = null; sec_end_session('revoked', 'login.revoked'); return null; }   // R49-001: signed out everywhere
        if ($u) presence_touch($u);
    }
    return $u ?: null;
}

/* ---------------------------------------------------------- HR scope (R51-001) */
const HR_PAGES = ['community', 'socphoto', 'hr_leaves', 'hr_training', 'hr_eval', 'hr_targets', 'planning', 'planning_export', 'admin_hr', 'hr_export', 'plan_items', 'hr_requests', 'my_leave', 'hr_doc', 'account', 'manual', 'notifications', 'nf_open', 'kb', 'kb_q', 'kbfile', 'avatar', 'logout', 'login', 'twofa', 'ping', 'relock', '404', 'hr_people_template', 'hr_person', 'hr_pdoc', 'hr_dashboard', 'hra', 'hra_flow', 'hra_doc'];
const HR_ACTIONS = ['pl_assign', 'pl_clear', 'pl_delete', 'pl_import', 'hr_type_save', 'hr_ent_save', 'hr_ent_copy', 'hr_req_new', 'hr_req_cancel', 'hr_req_decide', 'hr_person_save', 'hr_import_upload', 'hr_import_apply', 'hr_people_upload', 'hr_people_apply', 'hr_leave_upload', 'hr_leave_apply', 'hr_emp_event', 'hr_msg_send', 'hr_rules_save', 'hr_carry_transfer',  'hr_person_types_save', 'hr_off_save', 'hr_period_join', 'hr_period_save', 'hr_period_apply', 'hr_period_recount', 'soc_wish', 'soc_kudos', 'soc_post', 'soc_post_delete', 'soc_react', 'soc_comment', 'soc_vote', 'soc_winner', 'soc_onboard_start', 'soc_onboard_tick', 'hr_tr_save', 'hr_tr_delete', 'hr_tr_add', 'hr_trm_save', 'hr_tr_apply', 'hr_ev_save', 'hr_ev_ack', 'hr_tg_save', 'hr_tg_delete', 'hr_tg_progress', 'hr_eval_policy_save', 'hra_start', 'hra_consent', 'hra_step', 'hra_doc', 'hra_doc_del', 'hra_assist', 'hra_submit', 'hra_withdraw', 'hra_return', 'hra_field', 'hra_apply', 'hra_reject', 'hra_tpl_save', 'hr_ev_ai', 'hr_ev_ai_clear', 'hr_tg_ai', 'hr_period_undo', 'hr_period_delete', 'hr_leaves_ask', 'hr_ent_override', 'hr_ent_rollover', 'hr_import_batch_delete', 'hr_emp_delete', 'hr_doc_upload', 'hr_doc_delete', 'account_save', 'account_revoke', 'twofa_begin', 'twofa_confirm', 'twofa_off', 'theme_save', 'avatar_save', 'avatar_remove', 'logout', 'nf_seen', 'nf_seen_all', 'kb_ask', 'kb_answer', 'flt_reset'];
/** A staff account whose Golden A/S role is scoped "Planning only" (HR): sees the Planning board and its own account, nothing else. Administrators are never HR-only. */
/** R61-001: the Owner scope — full portal plus every HR page and record, without administrator rights. */
function hr_owner(): bool {
    static $v = null; if ($v !== null) return $v; $u = current_user();
    if (!$u || !is_staff() || empty($u['staff_role_id'])) return $v = false;
    try { return $v = (string) scalar('SELECT scope FROM staff_roles WHERE id = ?', [(int)$u['staff_role_id']]) === 'owner'; } catch (Throwable $e) { return $v = false; }
}
function hr_only(): bool {
    static $v = null; if ($v !== null) return $v; $u = current_user();
    if (!$u || !is_staff() || is_cp_admin() || empty($u['staff_role_id'])) return $v = false;
    try { return $v = (string) scalar('SELECT scope FROM staff_roles WHERE id = ?', [(int)$u['staff_role_id']]) === 'hr'; } catch (Throwable $e) { return $v = false; }
}
function hr_gate(string $page): void { if (hr_only() && !in_array($page, HR_PAGES, true)) { flash('warn', t('hr.only')); redirect(function_exists('hr2_has') && hr2_has() ? 'hr_dashboard' : 'planning'); } }

/* ---------------------------------------------------------- presence */

/** Stamp last_seen_at at most once a minute per person. "Online" means signed in and connected: the open
 *  portal page sends a heartbeat every minute (?p=ping), so a person stays online as long as the portal is
 *  open in a browser and drops out ~PRESENCE_MINUTES after closing it; signing out or the idle time-out
 *  clears the stamp at once (R24-002). Silent before the R6 migration. */
function presence_touch(array $u): void {
    if (!array_key_exists('last_seen_at', $u)) return;
    if ($u['last_seen_at'] && strtotime((string)$u['last_seen_at']) > time() - 60) return;
    try { q('UPDATE customer_users SET last_seen_at = NOW() WHERE id = ?', [$u['id']]); } catch (Throwable $e) {}
}
const PRESENCE_MINUTES = 3;
/** Heartbeat endpoint (?p=ping): refresh the stamp without touching the idle timer; 204 when signed in, 401 otherwise. */
function presence_ping(): void {
    $u = current_user();
    if (!$u) { http_response_code(401); exit; }
    try { q('UPDATE customer_users SET last_seen_at = NOW() WHERE id = ?', [(int)$u['id']]); } catch (Throwable $e) {}
    header('Cache-Control: no-store'); http_response_code(204); exit;
}
/** Signing out = offline immediately. */
function presence_leave(int $uid): void { try { q('UPDATE customer_users SET last_seen_at = NULL WHERE id = ?', [$uid]); } catch (Throwable $e) {} }
/** People seen in the last PRESENCE_MINUTES, most recent first. */
function online_users(): array {
    try {
        return all('SELECT cu.id, cu.full_name, cu.role, cu.last_seen_at, c.name AS customer_name
                    FROM customer_users cu LEFT JOIN customers c ON c.id = cu.customer_id
                    WHERE cu.last_seen_at >= NOW() - INTERVAL ' . PRESENCE_MINUTES . ' MINUTE
                    ORDER BY cu.last_seen_at DESC');
    } catch (Throwable $e) { return []; }
}

function require_login(): array {
    $u = current_user();
    if (!$u) redirect('login');
    return $u;
}

/** Record a sign-in event (success, failed, locked, disabled, logout) in
 *  login_log. Never breaks a sign-in: a missing table (migration not yet
 *  run) is silently ignored.                                 GAS-CP-R5-001 */
function login_log(?int $userId, string $email, string $outcome): void {
    try {
        $ip = (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');
        $ip = trim(explode(',', $ip)[0]);                 // first hop when proxied (Sucuri)
        q('INSERT INTO login_log (user_id, email, outcome, ip, user_agent) VALUES (?,?,?,?,?)',
          [$userId, mb_substr(strtolower(trim($email)), 0, 190), $outcome, mb_substr($ip, 0, 45) ?: null,
           mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null]);
    } catch (Throwable $e) { /* logging must never block access */ }
}

/** Tell every administrator that an account was locked after too many wrong passwords
 *  (bell notification, group Library; one open entry per account — a repeat lock refreshes it).
 *  Never blocks the sign-in flow.                                         GAS-CP-R36-001 */
function sec_notify_locked(array $u): void {
    try {
        if (!function_exists('nf_push') || !function_exists('nf_has') || !nf_has()) return;
        $admins = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"), 'id'));
        if (!$admins) return;
        $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
        $who = (string)$u['full_name'] . ' <' . (string)$u['email'] . '>';
        nf_push($admins, 'sec:locked', 'sec:locked:' . (int)$u['id'], t('nf.sec_locked_t', ['u' => $who]),
                t('nf.sec_locked_b', ['n' => 5, 'm' => 15, 'ip' => $ip !== '' ? $ip : '—']), url('admin_users', ['q' => (string)$u['email']]), null, null);
    } catch (Throwable $e) { /* notifying must never block access */ }
}

function attempt_login(string $email, string $pass): array {
    $email = strtolower(trim($email));
    $u = one('SELECT cu.*, c.is_active AS c_active FROM customer_users cu
              LEFT JOIN customers c ON c.id = cu.customer_id WHERE cu.email = ?', [$email]);
    // Staff/admin have no customer; a customer account needs an active customer.
    if (!$u || ($u['customer_id'] !== null && !$u['c_active'])) { login_log($u['id'] ?? null, $email, 'failed'); return ['ok' => false, 'error' => t('login.bad')]; }
    if (!$u['is_active'])       { login_log((int)$u['id'], $email, 'disabled'); return ['ok' => false, 'error' => t('login.disabled')]; }

    if ($u['locked_until'] && strtotime($u['locked_until']) > time()) {
        $mins = (int)ceil((strtotime($u['locked_until']) - time()) / 60);
        login_log((int)$u['id'], $email, 'locked');
        return ['ok' => false, 'error' => t('login.locked', ['mins' => $mins])];
    }
    if (!password_verify($pass, $u['password_hash'])) {
        $fails = (int)$u['failed_logins'] + 1;
        $lock  = $fails >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
        q('UPDATE customer_users SET failed_logins = ?, locked_until = ? WHERE id = ?', [$fails, $lock, $u['id']]);
        login_log((int)$u['id'], $email, $lock ? 'locked' : 'failed');
        if ($lock) sec_notify_locked($u);                                            // GAS-CP-R36-001: the administrators hear about it
        return ['ok' => false, 'error' => $lock ? t('login.lockednow') : t('login.bad')];
    }
    q('UPDATE customer_users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$u['id']]);

    /* Second factor: everyone whose account requires it (the default) or who
     * has enrolled an authenticator. Before the R6 migration the columns do
     * not exist and sign-in completes in one step. */
    $needs2fa = !empty($u['totp_enabled']) || !empty($u['twofa_required']);
    if ($needs2fa) {
        session_regenerate_id(true);
        $_SESSION['pending_uid'] = (int)$u['id'];
        $_SESSION['pending_at']  = time();
        $_SESSION['twofa_tries'] = 0;
        $_SESSION['enrol_secret'] = empty($u['totp_enabled']) ? totp_secret() : null;   // first time: enrol
        return ['ok' => true, 'twofa' => true];
    }
    finish_login((int)$u['id']);
    return ['ok' => true, 'twofa' => false];
}

/** Complete a sign-in (after the password, or after the second factor). */
function finish_login(int $userId): void {
    $u = one('SELECT * FROM customer_users WHERE id = ?', [$userId]);
    if (!$u) return;
    session_regenerate_id(true);
    unset($_SESSION['pending_uid'], $_SESSION['pending_at'], $_SESSION['twofa_tries'], $_SESSION['enrol_secret']);
    $_SESSION['cu_id'] = $userId;
    $_SESSION['started'] = time(); $_SESSION['ua'] = sec_ua_hash(); $_SESSION['ipn'] = sec_ip_net(); $_SESSION['epoch'] = (int)($u['session_epoch'] ?? 0);   // R49-001
    $_SESSION['wkey'] = bin2hex(random_bytes(12)); $_SESSION['wkey_new'] = true;                                                                 // window marker, handed to the first page
    try { q('UPDATE customer_users SET last_login_at = NOW(), last_seen_at = NOW() WHERE id = ?', [$userId]); }
    catch (Throwable $e) { q('UPDATE customer_users SET last_login_at = NOW() WHERE id = ?', [$userId]); }   // before R6
    if (locale_valid($u['locale'] ?? null)) { $_SESSION['locale'] = $u['locale']; locale($u['locale']); }
    login_log($userId, (string)$u['email'], 'success');
}

/** The account waiting for its second factor, or null (10-minute window). */
function pending_twofa_user(): ?array {
    $uid = (int)($_SESSION['pending_uid'] ?? 0);
    if (!$uid || time() - (int)($_SESSION['pending_at'] ?? 0) > 600) return null;
    return one('SELECT * FROM customer_users WHERE id = ? AND is_active = 1', [$uid]);
}

/** Step 2 — verify the six-digit code; enrol the authenticator on first use. */
function attempt_twofa(string $code): array {
    $u = pending_twofa_user();
    if (!$u) return ['ok' => false, 'error' => t('login.expired'), 'restart' => true];
    $secret = $_SESSION['enrol_secret'] ?? null;
    $enrolling = (bool)$secret;
    if (!$enrolling) $secret = (string)$u['totp_secret'];
    if ($secret !== '' && totp_verify($secret, $code)) {
        if ($enrolling) {
            q('UPDATE customer_users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $u['id']]);
            login_log((int)$u['id'], (string)$u['email'], 'twofa_enrolled');
        }
        finish_login((int)$u['id']);
        return ['ok' => true];
    }
    $_SESSION['twofa_tries'] = (int)($_SESSION['twofa_tries'] ?? 0) + 1;
    login_log((int)$u['id'], (string)$u['email'], 'twofa_failed');
    if ($_SESSION['twofa_tries'] >= 5) {
        unset($_SESSION['pending_uid'], $_SESSION['enrol_secret']);
        return ['ok' => false, 'error' => t('twofa.too_many'), 'restart' => true];
    }
    return ['ok' => false, 'error' => t('twofa.bad'), 'restart' => false];
}

<?php
declare(strict_types=1);

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function url(string $page, array $params = []): string {
    $params = array_merge(['p' => $page], $params);
    if (locale() !== DEFAULT_LOCALE && !isset($params['lang'])) $params['lang'] = locale();
    return 'index.php?' . http_build_query($params);
}
function redirect(string $page, array $params = [], string $hash = ''): void { header('Location: ' . url($page, $params) . $hash); exit; }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

/* ------------------------------------------------------------------
   Stateless gate tokens.                              GAS-SEC-GATE-001
   The sign-in and 2FA forms carry a cryptographically signed,
   time-limited token instead of a session-bound one, so submitting
   them can NEVER fail because a session file was lost, swept, or
   cached-stale between rendering the form and pressing the button.
   Every in-app action keeps the strict session-bound check.
   ------------------------------------------------------------------ */

/** Install-specific secret — derived, never stored client-side. */
function app_secret(): string {
    return hash('sha256', 'GAS|' . cfg('db.name') . '|' . cfg('db.user') . '|' . cfg('db.pass'));
}

function gate_token(): string {
    $ts = time();
    return $ts . '.' . hash_hmac('sha256', 'gate|' . $ts, app_secret());
}

function gate_token_ok(string $t): bool {
    $p = explode('.', $t, 2);
    if (count($p) !== 2 || $p[0] === '' || !ctype_digit($p[0])) return false;
    // 7-day validity: even a login page served days-stale by an edge cache
    // still signs in. Login-CSRF risk stays negligible — mandatory 2FA and
    // account lockout guard the gate; in-app actions keep strict tokens.
    if (abs(time() - (int)$p[0]) > 604800) return false;
    return hash_equals(hash_hmac('sha256', 'gate|' . $p[0], app_secret()), $p[1]);
}

function cp_is_gate(): bool { return in_array((string)($_GET['p'] ?? 'login'), ['login', 'twofa'], true); }

/** Per-request nonce for inline <script> tags (Content-Security-Policy, R53-001). */
function csp_nonce(): string { static $n = null; if ($n === null) $n = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '='); return $n; }
function csrf_field(): string {
    $t = cp_is_gate() ? gate_token() : csrf_token();
    return '<input type="hidden" name="_csrf" value="' . e($t) . '">';
}
/** One forensic line per gate rejection — storage/gate_debug.log.
 *  Turns "it bounced again" into an exact cause: token type, age, cookies. */
function gate_debug(string $sent): void {
    $dir = dirname(__DIR__) . '/storage';
    if (!is_dir($dir) || !is_writable($dir)) return;
    $looks = $sent === '' ? 'EMPTY'
           : (preg_match('/^\d{10}\./', $sent) ? 'gate' : (ctype_xdigit($sent) ? 'session-style' : 'other'));
    $age = '-';
    if ($looks === 'gate') { $ts = (int) strtok($sent, '.'); $age = (time() - $ts) . 's'; }
    $line = sprintf("%s | token=%s len=%d age=%s | gate_ok=%d | sess_csrf=%s sid=%s | cookies=%s | ref=%s | ua=%.60s\n",
        date('c'), $looks, strlen($sent), $age,
        $looks === 'gate' && function_exists('gate_token_ok') ? (int) gate_token_ok($sent) : 0,
        empty($_SESSION['csrf']) ? 'none' : 'set',
        substr(session_id() ?: '-', 0, 8),
        implode(',', array_keys($_COOKIE)) ?: 'NONE',
        substr((string)($_SERVER['HTTP_REFERER'] ?? '-'), 0, 60),
        (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    @file_put_contents($dir . '/gate_debug.log', $line, FILE_APPEND);
}

function csrf_check(): void {
    $s = $_POST['_csrf'] ?? '';
    if (is_string($s)) {
        if (cp_is_gate() && gate_token_ok($s)) return;      // stateless gate token
        if (hash_equals($_SESSION['csrf'] ?? '', $s)) return;
    }
    // Stale login page (cached tab, expired session): self-heal with a
    // fresh page + notice rather than a dead-end error.
    gate_debug(is_string($s) ? $s : '');
    flash('warn', t('login.expired'));
    if (cp_is_gate()) redirect((string)$_GET['p']);
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($back !== '' && $back[0] === '/' && !str_starts_with($back, '//')) { header('Location: ' . $back); exit; }
    redirect('dashboard');
}

function flash(string $type, string $msg): void {
    foreach ($_SESSION['flash'] ?? [] as $f) {
        if (($f['type'] ?? '') === $type && ($f['message'] ?? '') === $msg) return;
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $msg];
}
function flash_pull(): array {
    $f = $_SESSION['flash'] ?? [];
    $seen = []; $clean = [];
    foreach ($f as $x) {
        $k = ($x['type'] ?? '') . '|' . ($x['message'] ?? '');
        if (isset($seen[$k])) continue;
        $seen[$k] = true; $clean[] = $x;
    }
    unset($_SESSION['flash']);
    return $clean;
}

function fmt_date(?string $d): string {
    if (!$d) return '—';
    if (($dt = tz_local($d)) !== null) return $dt->format('j') . ' ' . t('month.' . (int)$dt->format('n')) . ' ' . $dt->format('Y');   // a date-time: the day in the company's zone
    $ts = strtotime($d);
    return date('j', $ts) . ' ' . t('month.' . (int)date('n', $ts)) . ' ' . date('Y', $ts);
}
/* ------------------------------------------------------------ time zones (GAS-CP-R46-002)
 * The database stores date-times in the server's zone (tz_db, default UTC on GoDaddy); the portal shows them in the
 * company's zone (tz_app, default Europe/Athens) with the zone's abbreviation (EET / EEST). Pure dates are never shifted. */
function tz_app(): string { static $z = null; if ($z === null) { $v = function_exists('cp_setting') && function_exists('lic_has') ? (string)(cp_setting('tz_app', '') ?? '') : ''; if ($v === '') $v = (string)(cfg('app.tz', 'Europe/Athens') ?: 'Europe/Athens'); $z = in_array($v, timezone_identifiers_list(), true) ? $v : 'Europe/Athens'; } return $z; }
function tz_db(): string { static $z = null; if ($z === null) { $v = function_exists('cp_setting') && function_exists('lic_has') ? (string)(cp_setting('tz_db', '') ?? '') : ''; if ($v === '') $v = (string)(cfg('app.db_tz', 'UTC') ?: 'UTC'); $z = in_array($v, timezone_identifiers_list(), true) ? $v : 'UTC'; } return $z; }
/** A stored date-time as a DateTime in the company's zone (null for an empty value or a pure date). */
function tz_local(?string $d): ?DateTime { if (!$d || !preg_match('/\d{1,2}:\d{2}/', $d)) return null; try { return (new DateTime($d, new DateTimeZone(tz_db())))->setTimezone(new DateTimeZone(tz_app())); } catch (Throwable $e) { return null; } }
/** Short zone label of a moment (EET / EEST / CET …). */
function tz_abbr(?DateTime $dt = null): string { $dt = $dt ?: new DateTime('now', new DateTimeZone(tz_app())); $a = $dt->format('T'); return preg_match('/^[+-]\d/', $a) ? 'UTC' . $a : $a; }
/** "27 Oct 2026 16:05 EET" — the stored moment in the company's zone, with the zone. */
function fmt_month(string $ym): string { $t = strtotime($ym . '-01'); return $t ? date('M Y', $t) : $ym; }   // R63-001
function fmt_datetime(?string $d, bool $zone = true): string { if (!$d) return '—'; $dt = tz_local($d); if (!$dt) return fmt_date($d); return $dt->format('j') . ' ' . t('month.' . (int)$dt->format('n')) . ' ' . $dt->format('Y H:i') . ($zone ? ' ' . tz_abbr($dt) : ''); }
/** "27.10.2026 16:05" in the company's zone — for documents (no zone label unless asked). */
function fmt_dt_doc(?string $d, bool $zone = false): string { if (!$d) return ''; $dt = tz_local($d); return $dt ? $dt->format('d.m.Y H:i') . ($zone ? ' ' . tz_abbr($dt) : '') : fmt_date($d); }
function initials(string $name): string {
    $p = preg_split('/\s+/', trim($name)); $i = '';
    foreach (array_slice($p, 0, 2) as $w) $i .= mb_strtoupper(mb_substr($w, 0, 1));
    return $i ?: '?';
}

/** Lifecycle stages, in order, with their palette class. */
function stages(): array {
    return [
        'engineering' => ['label' => t('stage.engineering'), 'cls' => 'eng'],
        'production'  => ['label' => t('stage.production'),  'cls' => 'prod'],
        'fat'         => ['label' => t('stage.fat'),          'cls' => 'fat'],
        'sathat'      => ['label' => t('stage.sathat'),       'cls' => 'sat'],
    ];
}

function doc_status_pill(string $s): string {
    $map = [
        'sent_for_signature' => ['gold',  t('doc.sent_for_signature')],
        'pm_review'          => ['blue',  t('doc.pm_review')],
        'approved'           => ['green', t('doc.approved')],
        'signed'             => ['green', t('doc.signed')],
        'expiring'           => ['red',   t('doc.expiring')],
        'restricted'         => ['mute',  t('doc.restricted')],
        'published'          => ['mute',  t('doc.published')],
    ];
    [$tone, $label] = $map[$s] ?? ['mute', $s];
    return '<span class="pill pill--' . $tone . '">' . e($label) . '</span>';
}

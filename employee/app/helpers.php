<?php
/** GAS-POMS — view helpers, CSRF, flash messages, formatting. */

declare(strict_types=1);

function e(?string $s): string {
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Links carry the active language so a shared URL opens in the same one. */
function url(string $page, array $params = []): string {
    $params = array_merge(['p' => $page], $params);
    if (function_exists('locale') && locale() !== DEFAULT_LOCALE && !isset($params['lang'])) {
        $params['lang'] = locale();
    }
    return 'index.php?' . http_build_query($params);
}

function redirect(string $page, array $params = []): void {
    header('Location: ' . url($page, $params));
    // Flush the redirect to the browser NOW, then let any queued email send
    // in the background so the user never waits on SMTP. On FastCGI this is
    // instant; otherwise we fall back to a normal flush.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        run_deferred();
    }
    exit;
}

/** Queue a callable to run after the response is delivered. */
function defer(callable $fn): void {
    $GLOBALS['__deferred'][] = $fn;
    // Always register a shutdown safety-net so a queued task can never be
    // dropped, even on a code path that exits without redirect(). run_deferred
    // is idempotent — it empties the queue, so a second call does nothing.
    static $hooked = false;
    if (!$hooked) { $hooked = true; register_shutdown_function('run_deferred'); }
}

function run_deferred(): void {
    $q = $GLOBALS['__deferred'] ?? [];
    if (!$q) return;
    $GLOBALS['__deferred'] = [];

    // If FastCGI already returned the response, we're detached and can just
    // run. Otherwise (plain PHP/Apache), push everything we have to the
    // client and close the connection first, so the user isn't kept waiting
    // while we talk to the mail server.
    if (!function_exists('fastcgi_finish_request') && !headers_sent()) {
        @ignore_user_abort(true);
        while (ob_get_level() > 0) { @ob_end_flush(); }
        $len = ob_get_length();
        header('Connection: close');
        if ($len !== false) header('Content-Length: ' . $len);
        @flush();
        if (session_id() !== '') @session_write_close();
    }
    foreach ($q as $fn) { try { $fn(); } catch (Throwable $e) { @error_log('deferred: ' . $e->getMessage()); } }
}

/* ------------------------------------------------------------------ CSRF */

/** Redirect to a URL carrying a unique freshness token. A URL that has
 *  never existed before cannot be served from ANY cache — browser, edge,
 *  or server — so the page after a save always shows the fresh state and
 *  consumes its flash message. */
function redirect_fresh(string $page, array $params = []): void {
    $params['_r'] = base_convert((string) (time() . random_int(10, 99)), 10, 36);
    redirect($page, $params);
}

/* ---- one-time form tokens (idempotency) --------------------------------
   Guards against a slow or double-clicked submit creating duplicate rows.
   form_token_field() prints a hidden token when rendering a form;
   form_token_claim() records it on first submit and returns true, or
   returns false (with the earlier result id) on any repeat. Degrades
   safely to "allow" if the table hasn't been migrated yet. */

function form_token_field(): string {
    if (empty($_SESSION['form_token']) ) {
        $_SESSION['form_token'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="_ftoken" value="' . e($_SESSION['form_token']) . '">';
}

/** Claim the submitted token for $purpose. Returns [claimed(bool), resultId(?int)].
 *  claimed=true  -> first use, caller should do the work then form_token_done().
 *  claimed=false -> duplicate; resultId is what the first submit produced. */
function form_token_claim(string $purpose): array {
    $tok = (string)($_POST['_ftoken'] ?? '');
    unset($_SESSION['form_token']);                 // rotate: this token is spent
    if ($tok === '' || !ctype_xdigit($tok) || strlen($tok) !== 32) {
        return [true, null];                        // no/garbled token -> don't block
    }
    try {
        // Atomic first-writer-wins: the INSERT succeeds once; a duplicate
        // submit hits the PRIMARY KEY and we fetch the earlier result.
        q('INSERT INTO form_tokens (token, user_id, purpose) VALUES (?, ?, ?)',
          [$tok, (int)($_SESSION['uid'] ?? 0) ?: null, $purpose]);
        $GLOBALS['__ftoken'] = $tok;
        return [true, null];
    } catch (Throwable $e) {
        $row = null;
        try { $row = one('SELECT result_id FROM form_tokens WHERE token = ?', [$tok]); }
        catch (Throwable $e2) { return [true, null]; }   // table missing -> don't block
        return [false, $row && $row['result_id'] !== null ? (int)$row['result_id'] : null];
    }
}

/** Record what the first submit produced, so a late duplicate can be
 *  redirected to it. */
function form_token_done(?int $resultId): void {
    $tok = $GLOBALS['__ftoken'] ?? null;
    if ($tok && $resultId) {
        try { q('UPDATE form_tokens SET result_id = ? WHERE token = ?', [$resultId, $tok]); }
        catch (Throwable $e) {}
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
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

function is_gate_page(): bool {
    $pg = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? ''));
    return in_array($pg, ['login', 'twofa'], true);
}

function csrf_field(): string {
    $t = is_gate_page() ? gate_token() : csrf_token();
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
    $sent = $_POST['_csrf'] ?? '';
    if (is_string($sent)) {
        // Gates accept the stateless signed token — immune to session loss.
        if (is_gate_page() && gate_token_ok($sent)) return;
        if (hash_equals($_SESSION['csrf'] ?? '', $sent)) return;
    }

    // A stale sign-in or 2FA page (cached tab, expired session) should
    // self-heal: bounce back to a FRESH copy of the same gate — new token,
    // friendly notice — instead of stranding the person on an error page.
    gate_debug(is_string($sent) ? $sent : '');
    flash('warn', t('login.expired'));

    $page = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? ''));
    if (in_array($page, ['login', 'twofa'], true)) {
        redirect($page);
    }
    // In-app stale token (e.g. a tab left open across a re-login): bounce
    // back to the SAME page — fresh token, notice shown, action safely NOT
    // executed — instead of stranding the person on an error wall.
    $back = (string)($_SERVER['REQUEST_URI'] ?? '');
    if ($back !== '' && $back[0] === '/' && !str_starts_with($back, '//')) {
        header('Location: ' . $back);
        exit;
    }
    redirect('dashboard');
}

/* ----------------------------------------------------------------- flash */

function flash(string $type, string $message): void {
    // Never queue the same notice twice — repeated bounces used to stack
    // identical banners down the whole page.
    foreach ($_SESSION['flash'] ?? [] as $f) {
        if (($f['type'] ?? '') === $type && ($f['message'] ?? '') === $message) return;
    }
    // Hard cap: a queue can never grow past 3 notices, so even a backlog
    // stored before de-dup existed can only ever show a few, never a wall.
    if (!empty($_SESSION['flash']) && count($_SESSION['flash']) >= 3) {
        $_SESSION['flash'] = array_slice($_SESSION['flash'], -2);
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_pull(): array {
    // Drain-time de-dup: a backlog queued before de-dup existed still shows
    // clean — identical notices collapse to one on their way out.
    if (!empty($_SESSION['flash'])) {
        $seen = []; $clean = [];
        foreach ($_SESSION['flash'] as $f) {
            $k = ($f['type'] ?? '') . '|' . ($f['message'] ?? '');
            if (isset($seen[$k])) continue;
            $seen[$k] = true; $clean[] = $f;
        }
        $_SESSION['flash'] = $clean;
    }
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

/* ------------------------------------------------------------ formatting */

/** Dates read "14 Μαρ 2026" or "14 mar 2026" depending on the language. */
function fmt_date(?string $d): string {
    if (!$d) return '—';
    $ts = strtotime($d);
    return date('j', $ts) . ' ' . locale_month((int)date('n', $ts)) . ' ' . date('Y', $ts);
}

function fmt_datetime(?string $d): string {
    if (!$d) return '—';
    return fmt_date($d) . ' ' . date('H:i', strtotime($d));
}

function fmt_money(?float $v, string $ccy = 'EUR'): string {
    return $v === null ? '—' : $ccy . ' ' . number_format($v, 2, '.', ',');
}

function fmt_qty(float $q): string {
    return rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function user_agent(): string {
    return substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

/* -------------------------------------------------------------- statuses */

function status_label(string $s, ?string $loc = null): string {
    return t('status.' . $s, [], $loc);
}

function status_tone(string $s): string {
    if (in_array($s, ['rejected_pm', 'rejected_tm', 'cancelled'], true))  return 'bad';
    if (in_array($s, ['delivered', 'closed'], true))                      return 'good';
    if (in_array($s, ['backordered', 'partially_fulfilled'], true))       return 'warn';
    if ($s === 'draft')                                                   return 'mute';
    return 'live';
}

function priority_label(string $p, ?string $loc = null): string {
    return t('priority.' . $p, [], $loc);
}

function item_status_label(string $s): string {
    return t('itemstatus.' . $s);
}

function role_label(string $code): string {
    return t('role.' . $code);
}

function event_label(string $ev): string {
    return t('event.' . $ev);
}

/** The berths an order passes through, in order. Used by the passage rail. */
function passage_legs(): array {
    return [
        'awaiting_pm'        => t('leg.pm'),
        'awaiting_tm'        => t('leg.tm'),
        'with_procurement'   => t('leg.procurement'),
        'ordered'            => t('leg.ordered'),
        'in_warehouse'       => t('leg.warehouse'),
        'ready_for_dispatch' => t('leg.dispatch'),
        'in_transit'         => t('leg.transit'),
        'delivered'          => t('leg.delivered'),
    ];
}

/** Statuses that never appear on the rail, offered as extra filter options. */
function offrail_statuses(): array {
    return ['draft','rejected_pm','rejected_tm','backordered','partially_fulfilled','closed','cancelled'];
}

function passage_index(string $status): int {
    $map = [
        'draft' => -1, 'awaiting_pm' => 0, 'awaiting_tm' => 1,
        'with_procurement' => 2, 'ordered' => 3, 'backordered' => 3,
        'in_warehouse' => 4, 'ready_for_dispatch' => 5, 'in_transit' => 6,
        'delivered' => 7, 'partially_fulfilled' => 7, 'closed' => 7,
        'rejected_pm' => 0, 'rejected_tm' => 1, 'cancelled' => -1,
    ];
    return $map[$status] ?? -1;
}

/* ------------------------------------------------- language picker */
/* Lives here (not in the layout) because the sign-in and 2FA gate
   pages render standalone, without _layout.php. */
/** Language selection as a dropdown; a <noscript> row of plain links keeps
 *  it usable without JavaScript. */
function language_picker(string $class = 'langsel'): void {
    echo '<select class="' . e($class) . '" data-langsel aria-label="' . tt('nav.language') . '">';
    foreach (LOCALES as $code => $meta) {
        printf('<option value="%s"%s>%s</option>',
            e(lang_switch_url($code)),
            $code === locale() ? ' selected' : '',
            e($meta['native']));
    }
    echo '</select><noscript><div class="langbar">';
    foreach (LOCALES as $code => $meta) {
        printf('<a href="%s" class="lang%s">%s</a>',
            e(lang_switch_url($code)), $code === locale() ? ' is-here' : '', e(strtoupper($code)));
    }
    echo '</div></noscript>';
}

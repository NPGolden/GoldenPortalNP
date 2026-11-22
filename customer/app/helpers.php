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

/** PHP upload limits in bytes: per file, per request, files per request.   GAS-CP-R76-003 */
function up_bytes(string $v): int { $v = trim($v); if ($v === '' || $v === '-1') return PHP_INT_MAX; $n = (float)$v; switch (strtolower(substr($v, -1))) { case 'g': $n *= 1024; case 'm': $n *= 1024; case 'k': $n *= 1024; } return (int)$n; }
function up_limits(): array { static $L = null; if ($L === null) { $f = up_bytes((string)ini_get('upload_max_filesize')); $p = up_bytes((string)ini_get('post_max_size')); $L = ['file' => min($f, $p), 'total' => $p, 'files' => max(1, (int)ini_get('max_file_uploads'))]; } return $L; }
function up_mb(int $b): string { return $b >= PHP_INT_MAX ? '∞' : (string)round($b / 1048576); }
/** A POST bigger than post_max_size reaches PHP with $_POST and $_FILES empty — say so instead of "session expired". */
function up_overflow_check(): void {
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0); if ($len <= 0 || $_POST || $_FILES) return;
    $L = up_limits(); if ($len <= $L['total']) return;
    flash('error', t('up.post_big', ['size' => up_mb($len), 'max' => up_mb($L['total']), 'n' => $L['files']]));
    $back = (string)($_SERVER['REQUEST_URI'] ?? ''); if ($back !== '' && $back[0] === '/' && !str_starts_with($back, '//')) { header('Location: ' . $back); exit; } redirect('dashboard');
}

/** The request token is valid only when the session has issued one and the posted one equals it — never two empty strings (R97-004; self-tested in Settings › About › Diagnostics). */
function csrf_ok(string $own, string $posted): bool { return $own !== '' && $posted !== '' && hash_equals($own, $posted); }
/**
 * R97-005: the storage folder (uploads, backups with the database dump, sessions, logs, cached tokens) must never be served by the web
 * server. Writes storage/.htaccess (deny all, Apache 2.4 and 2.2 syntax) into the portal's storage folder and into a configured upload
 * path when it is missing or was altered. Returns the folders that could not be protected.
 */
function cp_storage_guard(): array {
    $want = "# written by the portal — do not remove (GAS-CP-R97-005)\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n";
    $dirs = [dirname(__DIR__) . '/storage']; if (function_exists('cp_storage')) { $up = cp_storage(); if (!str_starts_with($up, $dirs[0] . '/') && $up !== $dirs[0]) $dirs[] = $up; }
    $bad = [];
    foreach ($dirs as $d) { if (!is_dir($d)) continue; $f = $d . '/.htaccess'; if (is_file($f) && strpos((string) @file_get_contents($f), 'Require all denied') !== false) continue; if (@file_put_contents($f, $want) === false) $bad[] = $d; else @chmod($f, 0644); }
    return $bad;
}
function csrf_check(): void {
    $s = $_POST['_csrf'] ?? '';
    if (is_string($s)) {
        if (cp_is_gate() && gate_token_ok($s)) return;      // stateless gate token
        if (csrf_ok((string)($_SESSION['csrf'] ?? ''), $s)) return;
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
/* ---- R97-050: the portal serves two websites (specification GAS-KYM-SPC-PORTAL-001). Site code 'golden' (goldenas.com.gr) or 'kyma' (kyma-marinepower.eu). ---- */
const KYM_SITES = ['golden' => 'Golden A/S', 'kyma' => 'KYMA Marine Power'];
const KYM_HOME = ['golden' => 'https://www.goldenas.com.gr/', 'kyma' => 'https://kyma-marinepower.eu/'];
const KYM_PRIVACY = ['golden' => 'https://www.goldenas.com.gr/privacy.html', 'kyma' => 'https://kyma-marinepower.eu/privacy.html'];
/** The migration of R97-050 has run (site / brands columns exist). Until then the portal behaves as a one-site portal. */
function kym_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM web_inquiries LIKE 'site'") && (bool) scalar("SHOW COLUMNS FROM contacts LIKE 'brands'") && (bool) scalar("SHOW COLUMNS FROM nl_subs LIKE 'site'"); } catch (Throwable $e) { $h = false; } } return $h; }
function kym_site($s): string { return (string)$s === 'kyma' ? 'kyma' : 'golden'; }
/** " AND <col> = ?" for a site (binds it) — nothing before the migration, and nothing found for KYMA then. */
function kym_w(string $col, string $site, array &$b): string { if (!kym_has()) return kym_site($site) === 'kyma' ? ' AND 0' : ''; $b[] = kym_site($site); return ' AND ' . $col . ' = ?'; }
function kym_pill(?string $site): string { $s = kym_site($site); return function_exists('pill') ? pill($s === 'kyma' ? 'blue' : 'gold', $s === 'kyma' ? 'KYMA' : 'Golden A/S') : ''; }
/** Brands of a contact / organisation row as pills (only KYMA is shown beside the default). */
function kym_brands(array $row): array { return array_values(array_intersect(array_keys(KYM_SITES), explode(',', (string)($row['brands'] ?? 'golden')))) ?: ['golden']; }
function kym_brand_pills(array $row): string { return in_array('kyma', kym_brands($row), true) ? ' ' . kym_pill('kyma') : ''; }
/** Add a brand to a contact or an organisation (table name is fixed here, never taken from input). */
function kym_brand_add(string $what, int $id, string $brand): void { if (!kym_has() || $id <= 0) return; $tb = $what === 'company' ? 'companies' : 'contacts'; $brand = kym_site($brand); q("UPDATE $tb SET brands = CONCAT_WS(',', NULLIF(brands, ''), ?) WHERE id = ? AND NOT FIND_IN_SET(?, brands)", [$brand, $id, $brand]); }
function kym_brand_set(string $what, int $id, array $brands): void { if (!kym_has() || $id <= 0) return; $tb = $what === 'company' ? 'companies' : 'contacts'; $set = array_values(array_intersect(array_keys(KYM_SITES), array_map('strval', $brands))) ?: ['golden']; q("UPDATE $tb SET brands = ? WHERE id = ?", [implode(',', $set), $id]); }
/* ---- R97-054: the KYMA mark follows the role — customers, yards, owners and engineering offices with an e-mail ---- */
const KYM_AUTO_ROLES = ['customer', 'yard', 'owner', 'consultant'];                       // organisation roles (consultant = consultant / designer / engineering office)
const KYM_AUTO_CATS  = ['owner', 'owner_rep', 'yard', 'yard_mgr', 'technical', 'designer'];   // contact categories (technical = engineering / technical office)
function kym_auto_on(): bool { return kym_has() && (string) cp_setting('kym_auto', '1') === '1'; }
/** SQL condition: the organisation (alias) carries one of the roles. */
function kym_auto_co_sql(string $a = 'co'): string { $p = ["$a.customer_id IS NOT NULL"]; foreach (KYM_AUTO_ROLES as $r) { $p[] = "FIND_IN_SET('$r', $a.roles)"; if ($r !== 'customer') $p[] = "$a.kind = '$r'"; } return '(' . implode(' OR ', $p) . ')'; }
/** The two conditions that pick who gets the mark; shared by the count and the update. */
function kym_auto_where(): array {
    $mail = "LIKE '%_@_%._%'";
    $co = "NOT FIND_IN_SET('kyma', co.brands) AND " . kym_auto_co_sql('co') . " AND (co.email $mail OR EXISTS (SELECT 1 FROM contacts x WHERE x.company_id = co.id AND x.email $mail))";
    $ct = "NOT FIND_IN_SET('kyma', ct.brands) AND ct.email $mail AND (ct.customer_id IS NOT NULL OR ct.category IN ('" . implode("','", KYM_AUTO_CATS) . "') OR (co.id IS NOT NULL AND " . kym_auto_co_sql('co') . "))";
    return ['co' => $co, 'ct' => $ct];
}
/** How many organisations and contacts the rule would mark now. Read-only. */
function kym_auto_count(): array { if (!kym_has()) return ['co' => 0, 'ct' => 0]; $w = kym_auto_where();
    return ['co' => (int) scalar("SELECT COUNT(*) FROM companies co WHERE {$w['co']}"), 'ct' => (int) scalar("SELECT COUNT(*) FROM contacts ct LEFT JOIN companies co ON co.id = ct.company_id WHERE {$w['ct']}")]; }
/** Add the KYMA mark where the rule applies. The Golden mark and everything else stay. Returns the rows changed. */
function kym_auto_mark(): array { if (!kym_has()) return ['co' => 0, 'ct' => 0]; $w = kym_auto_where();
    $ct = q("UPDATE contacts ct LEFT JOIN companies co ON co.id = ct.company_id SET ct.brands = CONCAT_WS(',', NULLIF(ct.brands, ''), 'kyma') WHERE {$w['ct']}")->rowCount();
    $co = q("UPDATE companies co SET co.brands = CONCAT_WS(',', NULLIF(co.brands, ''), 'kyma') WHERE {$w['co']}")->rowCount();
    if ($co || $ct) @file_put_contents(dirname(__DIR__) . '/storage/kyma-mark.log', date('Y-m-d H:i:s') . "\t" . (int)(current_user()['id'] ?? 0) . "\t$co organisations\t$ct contacts\n", FILE_APPEND | LOCK_EX);
    return ['co' => $co, 'ct' => $ct]; }
/** Administrators and management — the only ones who see career applications from the KYMA site. */
function kym_careers_can(): bool { return function_exists('is_staff') && is_staff() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())); }

/** R97-044: seconds since a moment stored by the database (NOW()). The database keeps its own zone (tz_db, usually UTC) while PHP runs in the company's
 *  zone, so strtotime() on a stored value is off by the zone difference — every "not more often than every n seconds" rule must use this instead. */
function tz_age(?string $at): int { if (!$at) return PHP_INT_MAX; try { return time() - (new DateTime($at, new DateTimeZone(tz_db())))->getTimestamp(); } catch (Throwable $e) { return PHP_INT_MAX; } }
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

/* ---------------------------------------------------------------- R75-002: group list rows per vessel */
/** Grouping on for this page? Cookie cp_vg_<page> = 0 switches it off (the toggle button sets it); default on. */
function vg_on(string $page): bool { return (($_COOKIE['cp_vg_' . $page] ?? '1') !== '0'); }
/** Sort rows by vessel name (empty last) keeping the original order inside a vessel; $key = column with the vessel name. */
function vg_sort(array $rows, string $key = 'vessel'): array { $i = 0; foreach ($rows as &$r) $r['_vgi'] = $i++; unset($r); usort($rows, fn($a, $b) => [((string)($a[$key] ?? '')) === '' ? 1 : 0, mb_strtolower((string)($a[$key] ?? '')), $a['_vgi']] <=> [((string)($b[$key] ?? '')) === '' ? 1 : 0, mb_strtolower((string)($b[$key] ?? '')), $b['_vgi']]); return $rows; }
/** R97-074: order of a report list. $ord: '' / 'new' as it comes (newest first) · 'old' oldest first · 'vaz' vessels A→Z · 'vza' vessels Z→A.
 *  Grouped by vessel ($vg) the groups are always by vessel name (Z→A with 'vza', rows without a vessel last); inside a group the list order is kept. */
function vg_order(array $rows, string $key, bool $vg, string $ord = ''): array {
    if ($ord === 'old') $rows = array_reverse($rows);
    if (!$vg && $ord !== 'vaz' && $ord !== 'vza') return $rows;
    $rows = vg_sort($rows, $key); if ($ord !== 'vza') return $rows;
    $g = []; foreach ($rows as $r) $g[(string)($r[$key] ?? '')][] = $r; $none = $g[''] ?? []; unset($g['']);
    $out = []; foreach (array_reverse($g, true) as $rs) foreach ($rs as $r) $out[] = $r; return array_merge($out, $none);
}
/** Group header row for a table: ▸ vessel (n). Rows that follow must carry data-vg="<slug>". */
function vg_row(string $vessel, int $n, int $cols): string { $slug = vg_slug($vessel); return '<tr class="vg-h" data-vg-h="' . e($slug) . '"><td colspan="' . $cols . '"><button type="button" class="vg-btn"><span class="vg-tw">▾</span> ' . ($vessel !== '' ? e($vessel) : tt('vg.no_vessel')) . ' <span class="hint">' . $n . '</span></button></td></tr>'; }
function vg_slug(string $vessel): string { return $vessel === '' ? '_none' : substr(md5($vessel), 0, 10); }
/** The toggle button (grouped ⇄ flat) for the page head. */
function vg_toggle(string $page): string { $on = vg_on($page); $h = '<span class="vg-tools">'; if ($on) $h .= '<button type="button" class="btn btn--ghost btn--sm vg-all" data-vg-all="1" title="' . tt('vg.expand_all') . '">⊞</button><button type="button" class="btn btn--ghost btn--sm vg-all" data-vg-all="0" title="' . tt('vg.collapse_all') . '">⊟</button>'; return $h . '<button type="button" class="btn btn--ghost btn--sm vg-toggle' . ($on ? ' is-on' : '') . '" data-vg-page="' . e($page) . '" title="' . tt('vg.toggle_hint') . '">' . ($on ? '▤ ' . tt('vg.grouped') : '☰ ' . tt('vg.flat')) . '</button></span>'; }

/** R97-103: a form marked data-pack sends its fields as one JSON list (_packed) so that tables with hundreds of lines pass PHP's max_input_vars.
 *  The list is put back into $_POST exactly as the fields would have arrived. Real fields (_csrf, action, id …) always win. */
function post_unpack(): void {
    if (!isset($_POST['_packed']) || !is_string($_POST['_packed'])) return; $raw = $_POST['_packed']; unset($_POST['_packed']);
    if (strlen($raw) > 8 * 1048576) throw new RuntimeException(t('err.form_too_big'));
    $pairs = json_decode($raw, true, 4); if (!is_array($pairs) || count($pairs) > 40000) throw new RuntimeException(t('err.form_too_big'));
    foreach ($pairs as $pr) {
        if (!is_array($pr) || count($pr) !== 2 || !is_string($pr[0]) || !is_string($pr[1])) continue;
        if (!preg_match('/^([A-Za-z][A-Za-z0-9_]{0,40})((?:\[[A-Za-z0-9_.-]{0,40}\]){0,3})$/', $pr[0], $m)) continue;
        $base = $m[1]; if ($base === '_packed' || $base === '_csrf') continue;
        if ($m[2] === '') { if (!array_key_exists($base, $_POST)) $_POST[$base] = $pr[1]; continue; }
        if (isset($_POST[$base]) && !is_array($_POST[$base])) continue; if (!isset($_POST[$base])) $_POST[$base] = [];
        preg_match_all('/\[([^\]]*)\]/', $m[2], $km); $ref = &$_POST[$base]; $ok = true;
        foreach ($km[1] as $i => $k) {
            $last = $i === count($km[1]) - 1;
            if ($k === '') { if (!$last) { $ok = false; break; } $ref[] = $pr[1]; $ok = false; break; }
            if ($last) { $ref[$k] = $pr[1]; break; }
            if (!isset($ref[$k]) || !is_array($ref[$k])) $ref[$k] = []; $ref = &$ref[$k];
        }
        unset($ref);
    }
}

/** R97-104: on a page that only reads (GET), the ids of the sub-projects that have any row in the given tables — one statement per table and request.
 *  Lists with many sub-projects ask it before running the per-sub-project calculations; null = not known (a POST, or a table that is missing), calculate as before. */
function sub_ids_with(string $key, array $sqls): ?array {
    static $c = []; if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return null; if (array_key_exists($key, $c)) return $c[$key];
    $set = []; try { foreach ($sqls as $q) foreach (all($q) as $r) { $v = (int) reset($r); if ($v) $set[$v] = true; } } catch (Throwable $e) { return $c[$key] = null; }
    return $c[$key] = $set;
}

/* ---------------------------------------------- store apps (R97-153) */
/** The Golden A/S mobile apps: store links, QR images and web apps. 'ios_live' turns on the App Store link once Apple has released the app. */
function cp_store_apps(): array {
    $play = 'https://play.google.com/store/apps/details?id=';
    $ios  = 'https://apps.apple.com/app/id';
    return [
        'customer' => ['name' => 'Golden A/S Customer', 'play' => $play . 'gr.goldenas.customer', 'ios' => $ios . '6829433786', 'ios_live' => true, 'web' => 'mobile-customer/'],
        'service'  => ['name' => 'Golden A/S Service',  'play' => $play . 'gr.goldenas.service',  'ios' => $ios . '6829433629', 'ios_live' => true, 'web' => 'mobile/'],
        'pm'       => ['name' => 'Golden PM',           'play' => $play . 'gr.goldenas.pm',       'ios' => $ios . '6829433663', 'ios_live' => true, 'web' => 'mobile-pm/'],
        'employee' => ['name' => 'Golden A/S Employee', 'play' => $play . 'gr.goldenas.employee', 'ios' => $ios . '6837805575', 'ios_live' => true, 'web' => 'mobile-employee/'],   // R97-187: on Google Play since 22/11/2026; ios_live turns true when Apple releases it
        'hr'       => ['name' => 'Golden HR',           'play' => null, 'ios' => $ios . '6837033425', 'ios_live' => true, 'web' => 'mobile-hr/'],
    ];
}
/** One card per app: icon, name, a QR tile for Google Play and one for the App Store (or the web app when the app is not in the stores). */
function store_apps_html(array $keys): string {
    $apps = cp_store_apps(); $o = '<div class="appgrid">';
    foreach ($keys as $k) {
        if (!isset($apps[$k])) continue; $a = $apps[$k];
        $o .= '<div class="appcard"><div class="appcard-h"><img src="../assets/apps/icon-' . $k . '.png" width="44" height="44" alt="" loading="lazy">'
            . '<div><b>' . e($a['name']) . '</b><small>' . tt('apps.d.' . $k) . '</small></div></div><div class="appcard-s">';
        if ($a['play'] || $a['ios']) {   /* R97-179: an app that is only in the App Store shows its web app in the first tile */
            if ($a['play'])
            $o .= '<a class="apptile" href="' . e($a['play']) . '" target="_blank" rel="noopener"><img src="../assets/apps/qr-' . $k . '-play.png" width="104" height="104" alt="' . tt('apps.qr_alt', ['app' => $a['name'], 'store' => 'Google Play']) . '" loading="lazy">'
                . '<span><small>Android</small><b>Google Play</b></span></a>';
            else $o .= '<a class="apptile" href="' . e($a['web']) . '" target="_blank" rel="noopener"><img src="../assets/apps/qr-' . $k . '-web.png" width="104" height="104" alt="' . tt('apps.qr_alt', ['app' => $a['name'], 'store' => t('apps.web')]) . '" loading="lazy">'
                . '<span><small>Android</small><b>' . tt('apps.web') . '</b></span></a>';
            $live = $a['ios_live'];
            $o .= ($live ? '<a class="apptile" href="' . e($a['ios']) . '" target="_blank" rel="noopener">' : '<span class="apptile is-soon">')
                . '<img src="../assets/apps/qr-' . $k . '-ios.png" width="104" height="104" alt="' . ($live ? tt('apps.qr_alt', ['app' => $a['name'], 'store' => 'App Store']) : '') . '" loading="lazy">'
                . '<span><small>' . ($live ? 'iPhone · iPad' : tt('apps.ios_soon')) . '</small><b>App Store</b></span>' . ($live ? '</a>' : '</span>');
        } else {
            $o .= '<a class="apptile apptile--wide" href="' . e($a['web']) . '" target="_blank" rel="noopener"><img src="../assets/apps/qr-' . $k . '-web.png" width="104" height="104" alt="' . tt('apps.qr_alt', ['app' => $a['name'], 'store' => t('apps.web')]) . '" loading="lazy">'
                . '<span><small>' . tt('apps.no_store') . '</small><b>' . tt('apps.web') . '</b></span></a>';
        }
        $o .= '</div><a class="appcard-w" href="' . e($a['web']) . '" target="_blank" rel="noopener">' . tt('apps.open_web') . ' →</a></div>';
    }
    return $o . '</div>';
}

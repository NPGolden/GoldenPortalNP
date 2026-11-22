<?php
/**
 * Push notifications to the phones (GAS-CP-R97-196).
 *
 * What is sent: (1) every new portal notification of a person (pn_from_nf, called by nf_push) — for customers their
 * approvals, offers, documents to sign, drawings and ticket answers; for staff their work notifications; (2) the
 * In / Out reminders at the start and the end of the working hours (pn_io_tick), never on a day off, a public holiday
 * or approved leave, and only for people who keep "In / Out reminders" on.
 *
 * How: rows wait in mob_push_queue; pn_flush() sends them — Android through Firebase Cloud Messaging (HTTP v1, with a
 * service-account key), iPhone / iPad directly through Apple (APNs, HTTP/2, with a .p8 key). Both keys are uploaded
 * by an administrator on Settings › Push notifications and live in storage/keys (never shown again, never in the
 * database, never in a log). customer/tick.php (a job every five minutes) runs the sweep, the reminders and the
 * sending; without the job the same work rides on the page views of the portal.
 */
const PN_APPS = ['customer' => 'gr.goldenas.customer', 'engineer' => 'gr.goldenas.service', 'pm' => 'gr.goldenas.pm', 'hr' => 'gr.goldenas.hr', 'employee' => 'gr.goldenas.employee'];
const PN_STAFF_ORDER = ['pm', 'engineer', 'employee', 'hr'];        // a member of staff with several apps gets one push, in the first of these he has
const PN_FRESH_SEC = 7200;                                          // older events are history, not news

function pn_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'mob_push_queue'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pn_dir(): string { $d = dirname(__DIR__) . '/storage/keys'; if (!is_dir($d)) { @mkdir($d, 0700, true); @file_put_contents($d . '/.htaccess', "Require all denied\nDeny from all\n"); @file_put_contents($d . '/index.html', ''); } return $d; }
function pn_fcm_file(): string { return pn_dir() . '/fcm-service-account.json'; }
function pn_apns_file(): string { return pn_dir() . '/apns-key.p8'; }
function pn_fcm_ready(): bool { return is_file(pn_fcm_file()); }
function pn_apns_ready(): bool { return is_file(pn_apns_file()) && preg_match('/^[A-Z0-9]{10}$/', (string) cp_setting('pn_apns_key_id', '')) && preg_match('/^[A-Z0-9]{10}$/', (string) cp_setting('pn_apns_team_id', '')); }
function pn_http2(): bool { return function_exists('curl_version') && defined('CURL_VERSION_HTTP2') && ((curl_version()['features'] ?? 0) & CURL_VERSION_HTTP2); }
function pn_cache(string $name, ?array $set = null): ?array { $f = pn_dir() . '/cache-' . $name . '.json'; if ($set !== null) { @file_put_contents($f, json_encode($set), LOCK_EX); @chmod($f, 0600); return $set; } $j = json_decode((string) @file_get_contents($f), true); return is_array($j) ? $j : null; }
function pn_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

/** A text in the language of the receiver (the job has no session and no current user). */
function pn_t(string $lang, string $key, array $p = []): string {
    static $L = []; $lang = preg_match('/^[a-z]{2}$/', $lang) && is_file(dirname(__DIR__) . "/lang/$lang.php") ? $lang : 'en';
    $L[$lang] ??= (array) require dirname(__DIR__) . "/lang/$lang.php"; if ($lang !== 'en') $L['en'] ??= (array) require dirname(__DIR__) . '/lang/en.php';
    $s = (string)($L[$lang][$key] ?? $L['en'][$key] ?? $key); foreach ($p as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s); return $s;
}

/* ------------------------------------------------------------------ queue */
/** The devices of a person that can receive a push, by app. */
function pn_devices(int $uid): array {
    $o = []; foreach (all("SELECT id, app_id, platform, push_token, push_service FROM mob_devices WHERE user_id = ? AND revoked_at IS NULL AND push_token IS NOT NULL AND push_token <> '' ORDER BY last_seen_at DESC", [$uid]) as $d) $o[(string)$d['app_id']][] = $d;
    return $o;
}
/** Which app of the person gets this push: a customer has one app; staff get it once, not in every app they have. $prefer puts an app first. */
function pn_pick_app(array $byApp, ?string $prefer = null): ?string {
    if (isset($byApp['customer'])) return 'customer';
    foreach (array_merge($prefer ? [$prefer] : [], PN_STAFF_ORDER) as $a) if (isset($byApp[$a])) return $a;
    return null;
}
/** Queue one push for a person. False when the person has no phone registered, has switched push off, or the same thing is already queued or was sent today. */
function pn_enqueue(int $uid, string $kind, string $refKey, string $title, ?string $body, ?string $route = null, ?string $prefer = null): bool {
    if (!pn_has() || $uid <= 0) return false; $app = pn_pick_app(pn_devices($uid), $prefer); if (!$app) return false;
    if (function_exists('wl_has') && wl_has() && !(wl_prefs($uid)['push'] ?? 1)) return false;
    $refKey = mb_substr($refKey, 0, 60);
    if (scalar('SELECT COUNT(*) FROM mob_push_queue WHERE user_id = ? AND ref_key = ? AND (sent_at IS NULL OR sent_at > NOW() - INTERVAL 10 MINUTE)', [$uid, $refKey])) return false;
    q('INSERT INTO mob_push_queue (user_id, app_id, kind, ref_key, title, body, route) VALUES (?,?,?,?,?,?,?)', [$uid, $app, mb_substr($kind, 0, 30), $refKey, mb_substr($title, 0, 200), $body !== null ? mb_substr($body, 0, 400) : null, $route !== null ? mb_substr($route, 0, 120) : null]);
    return true;
}
/** Where a tap on the push opens the app. */
function pn_route(string $kind, bool $customer): ?string {
    $k = explode(':', $kind, 2)[0];
    if ($customer) return ['of' => '#/offers', 'oa' => '#/approvals', 'fsr' => '#/sign', 'isr' => '#/sign', 'tk' => '#/tickets', 'drw' => '#/documents', 'doc' => '#/documents', 'cls' => '#/documents'][$k] ?? '#/home';
    return $k === 'io' ? '#/io' : null;
}
/** Called by nf_push() for every new or renewed notification. */
function pn_from_nf(int $uid, string $kind, string $refKey, string $title, ?string $body, string $at): void {
    try {
        if (!pn_has() || strtotime($at) < time() - PN_FRESH_SEC) return; static $role = [];
        $role[$uid] ??= (string) scalar('SELECT role FROM customer_users WHERE id = ?', [$uid]);
        pn_enqueue($uid, 'nf:' . $kind, $refKey, $title, $body, pn_route($kind, $role[$uid] === 'customer'));
    } catch (Throwable $e) { /* a push must never break the notification */ }
}

/* ------------------------------------------------------------------ In / Out reminders */
/** Queue the reminders that are due now. Window: the first $win minutes after the start (no In yet) and after the end (In still open). */
function pn_io_tick(int $win = 20): int {
    if (!pn_has() || !function_exists('io_has') || !io_has() || !function_exists('wl_today')) return 0; $n = 0; $now = (int) date('G') * 60 + (int) date('i'); $day = date('Y-m-d');
    $rows = all("SELECT DISTINCT u.id, u.locale FROM customer_users u JOIN mob_devices d ON d.user_id = u.id AND d.revoked_at IS NULL AND d.push_token IS NOT NULL AND d.push_token <> '' JOIN io_consents c ON c.user_id = u.id AND c.version = ? WHERE u.role <> 'customer' AND u.is_active = 1", [IO_CONSENT_VER]);
    foreach ($rows as $u) {
        $uid = (int)$u['id']; if (function_exists('wl_has') && wl_has() && !wl_prefs($uid)['io_remind']) continue;
        $t = wl_today($uid); if (!$t['workday'] || $t['leave'] || !$t['start'] || !$t['end']) continue;
        $s = (int) substr($t['start'], 0, 2) * 60 + (int) substr($t['start'], 3, 2); $e = (int) substr($t['end'], 0, 2) * 60 + (int) substr($t['end'], 3, 2); $lang = (string)($u['locale'] ?: 'en');
        if (!$t['in'] && $now >= $s && $now < $s + $win && !scalar('SELECT COUNT(*) FROM mob_push_queue WHERE user_id = ? AND ref_key = ?', [$uid, "io:in:$day"]))
            $n += (int) pn_enqueue($uid, 'io:in', "io:in:$day", pn_t($lang, 'wl.n_in_t'), pn_t($lang, 'wl.n_in', ['t' => $t['start']]), '#/io', 'employee');
        if ($t['open'] && $now >= $e && $now < $e + $win && !scalar('SELECT COUNT(*) FROM mob_push_queue WHERE user_id = ? AND ref_key = ?', [$uid, "io:out:$day"]))
            $n += (int) pn_enqueue($uid, 'io:out', "io:out:$day", pn_t($lang, 'wl.n_out_t'), pn_t($lang, 'wl.n_out', ['t' => $t['end']]), '#/io', 'employee');
    }
    return $n;
}

/* ------------------------------------------------------------------ planning notices (R97-199) */
const PN_PLAN_HOUR = 18;                                           // the evening notice: 18:00 of the day before (Sunday evening for Monday)
const PN_PLAN_SKIP = ['leave', 'sick', 'parental', 'strike'];      // absences are not announced — nobody is disturbed about a day off
/** The planning of one day, [user_id => ['lang' => .., 'rows' => [entries]]] — active staff only, absences left out. */
function pn_plan_rows(string $date, ?int $uid = null): array {
    if (!function_exists('pl_has') || !pl_has()) return []; $o = [];
    $skip = "'" . implode("','", PN_PLAN_SKIP) . "'";
    foreach (all("SELECT e.user_id, e.kind, e.note, u.locale, p.project_code, s.code AS sub_code, s.name AS sub_name, COALESCE(v.name, pv.name) AS vessel, COALESCE(v.yard, pv.yard) AS yard, c.name AS car
                  FROM plan_entries e JOIN customer_users u ON u.id = e.user_id AND u.is_active = 1 AND u.role IN ('staff','admin')
                  LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN subprojects s ON s.id = e.subproject_id LEFT JOIN vessels v ON v.id = e.vessel_id LEFT JOIN vessels pv ON pv.id = p.vessel_id LEFT JOIN plan_cars c ON c.id = e.car_id
                  WHERE e.plan_date = ? AND e.kind NOT IN ({$skip})" . ($uid ? ' AND e.user_id = ?' : '') . ' ORDER BY e.user_id, e.id', $uid ? [$date, $uid] : [$date]) as $r) {
        $o[(int)$r['user_id']]['lang'] = (string)($r['locale'] ?: 'en'); $o[(int)$r['user_id']]['rows'][] = $r;
    }
    return $o;
}
/** One line per assignment: "AMBROSIA Y.02 · Wheelhouse — Yard · car Van 2 · note" / "Training · note". */
function pn_plan_text(array $rows, string $lang): string {
    $L = [];
    foreach ($rows as $e) {
        $P = [];
        if ($e['kind'] === 'work') {
            $l = trim(((string)($e['vessel'] ?? '') !== '' ? (string)$e['vessel'] : (string)($e['project_code'] ?? '')) . ((string)($e['sub_code'] ?? '') !== '' ? ' ' . $e['sub_code'] : ''));
            $l = $l !== '' ? $l : pn_t($lang, 'pl.k_work');
            if ((string)($e['sub_name'] ?? '') !== '') $l .= ' · ' . $e['sub_name'];
            if ((string)($e['yard'] ?? '') !== '') $l .= ' — ' . $e['yard'];
            $P[] = $l;
        } elseif (!($e['kind'] === 'other' && (string)($e['note'] ?? '') !== '')) $P[] = pn_t($lang, 'pl.k_' . $e['kind']);
        if ((string)($e['note'] ?? '') !== '') $P[] = (string)$e['note'];
        if ((string)($e['car'] ?? '') !== '') $P[] = pn_t($lang, 'pn.pl_car', ['c' => $e['car']]);
        $l = implode(' · ', $P); if ($l !== '' && !in_array($l, $L, true)) $L[] = $l;
    }
    return mb_substr(implode("\n", $L), 0, 400);
}
function pn_plan_on(int $uid): bool { return !(function_exists('wl_has') && wl_has()) || !empty(wl_prefs($uid)['plan']); }
/** Called by the planning board when a planner changes somebody's day. Only today and tomorrow matter; the notice itself is written by the clock job, so several edits in a row give one push with the final state. */
function pn_plan_touch(int $uid, string $date): void {
    try {
        if (!pn_has() || $uid <= 0 || ($date !== date('Y-m-d') && $date !== date('Y-m-d', strtotime('+1 day')))) return;
        if ((int)(current_user()['id'] ?? 0) === $uid) return;     // one's own edit needs no notice
        q('INSERT INTO cp_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', ["pn_pd:$uid:$date", (string) time()]);
    } catch (Throwable $e) { /* a notice must never break the planning */ }
}
/** The evening notice for tomorrow (once a day, from PN_PLAN_HOUR) and the notices of late changes. */
function pn_plan_tick(): int {
    if (!pn_has() || !function_exists('pl_has') || !pl_has()) return 0; $n = 0;
    $today = date('Y-m-d'); $tom = date('Y-m-d', strtotime('+1 day')); $h = (int) date('G'); $d = fn(string $x) => date('d/m', strtotime($x));
    $sent = (string) scalar("SELECT v FROM cp_settings WHERE k = 'pn_plan_sent'");
    if ($h >= PN_PLAN_HOUR && $h < PN_PLAN_HOUR + 4 && $sent !== $tom) {
        cp_setting_set('pn_plan_sent', $tom); $sent = $tom;        // marked first: a failure half-way must not send the evening notice twice
        foreach (pn_plan_rows($tom) as $uid => $x) { if (!pn_plan_on($uid)) continue; $b = pn_plan_text($x['rows'], $x['lang']); if ($b === '') continue;
            $n += (int) pn_enqueue($uid, 'plan:day', "plan:$tom", pn_t($x['lang'], 'pn.pl_t', ['d' => $d($tom)]), $b); }
        q("DELETE FROM cp_settings WHERE k LIKE ?", ["pn\\_pd:%:$tom"]);   // changes made before the evening notice are in it
    }
    foreach (all("SELECT k, v FROM cp_settings WHERE k LIKE 'pn\\_pd:%'") as $r) {
        [, $uid, $date] = explode(':', (string)$r['k']) + [null, 0, '']; $uid = (int)$uid;
        if ($date >= $today && (int)$r['v'] > time() - 120) continue;          // the planner may still be editing
        q('DELETE FROM cp_settings WHERE k = ? AND v = ?', [$r['k'], $r['v']]);
        if ($date !== $today && !($date === $tom && $sent === $tom)) continue;  // past days; tomorrow before the evening notice
        if (!pn_plan_on($uid)) continue;
        $usr = one("SELECT locale FROM customer_users WHERE id = ? AND is_active = 1 AND role IN ('staff','admin')", [$uid]); if (!$usr) continue; $lang = (string)($usr['locale'] ?: 'en');
        $x = pn_plan_rows($date, $uid)[$uid] ?? null; $b = $x ? pn_plan_text($x['rows'], $lang) : '';
        if ($b === '' && scalar("SELECT COUNT(*) FROM plan_entries WHERE user_id = ? AND plan_date = ?", [$uid, $date])) continue;   // the day is now an absence — HR tells that in its own way
        $n += (int) pn_enqueue($uid, 'plan:chg', "planc:$date:" . (int)$r['v'], pn_t($lang, 'pn.plc_t', ['w' => pn_t($lang, $date === $today ? 'pn.pl_today' : 'pn.pl_tomorrow'), 'd' => $d($date)]), $b !== '' ? $b : pn_t($lang, 'pn.plc_none'));
    }
    return $n;
}

/* ------------------------------------------------------------------ sending */
function pn_curl(string $url, array $headers, string $body, bool $h2 = false): array {
    $c = curl_init($url); curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6]);
    if ($h2) curl_setopt($c, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); $err = curl_error($c); curl_close($c);
    return [$code, is_string($r) ? $r : '', $err];
}
/** A short-lived Google access token from the service-account key (cached 50 minutes). */
function pn_fcm_token(): array {
    $sa = json_decode((string) @file_get_contents(pn_fcm_file()), true); if (!is_array($sa) || empty($sa['private_key']) || empty($sa['client_email']) || empty($sa['project_id'])) throw new RuntimeException('fcm key unreadable');
    $c = pn_cache('fcm'); if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && ($c['p'] ?? '') === $sa['project_id']) return [$c['t'], $sa['project_id']];
    $now = time(); $h = pn_b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])); $p = pn_b64(json_encode(['iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/firebase.messaging', 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600]));
    if (!openssl_sign("$h.$p", $sig, $sa['private_key'], OPENSSL_ALGO_SHA256)) throw new RuntimeException('fcm key cannot sign');
    [$code, $body] = pn_curl('https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => "$h.$p." . pn_b64($sig)]));
    $j = json_decode($body, true); if ($code !== 200 || empty($j['access_token'])) throw new RuntimeException('fcm token ' . $code . ' ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? ''), 0, 120));
    pn_cache('fcm', ['t' => $j['access_token'], 'exp' => $now + 3000, 'p' => $sa['project_id']]);
    return [$j['access_token'], $sa['project_id']];
}
/** Apple's provider token (ES256, cached 50 minutes). openssl gives the signature as DER; the token wants the two numbers side by side. */
function pn_apns_jwt(): string {
    $kid = (string) cp_setting('pn_apns_key_id', ''); $team = (string) cp_setting('pn_apns_team_id', '');
    $c = pn_cache('apns'); if (is_array($c) && ($c['exp'] ?? 0) > time() && ($c['k'] ?? '') === $kid) return $c['t'];
    $key = openssl_pkey_get_private((string) @file_get_contents(pn_apns_file())); if (!$key) throw new RuntimeException('apns key unreadable');
    $h = pn_b64(json_encode(['alg' => 'ES256', 'kid' => $kid])); $p = pn_b64(json_encode(['iss' => $team, 'iat' => time()]));
    if (!openssl_sign("$h.$p", $der, $key, OPENSSL_ALGO_SHA256)) throw new RuntimeException('apns key cannot sign');
    $o = 2; if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7f; $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl; $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    $raw = str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT) . str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT); $t = "$h.$p." . pn_b64($raw);
    pn_cache('apns', ['t' => $t, 'exp' => time() + 3000, 'k' => $kid]); return $t;
}
/** Send one push to one device. Returns [ok, error text, token is dead]. */
function pn_send(array $dev, string $title, ?string $body, ?string $route): array {
    $tok = (string)$dev['push_token']; $data = $route ? ['route' => $route] : [];
    if (($dev['push_service'] ?? '') === 'apns' || $dev['platform'] === 'ios') {
        if (!pn_apns_ready()) return [false, 'no apple key', false]; if (!pn_http2()) return [false, 'server without HTTP/2', false]; if (!preg_match('/^[0-9a-fA-F]{64,200}$/', $tok)) return [false, 'bad token', true];
        $topic = PN_APPS[(string)$dev['app_id']] ?? null; if (!$topic) return [false, 'unknown app', false];
        [$code, $res, $err] = pn_curl('https://api.push.apple.com/3/device/' . $tok, ['authorization: bearer ' . pn_apns_jwt(), 'apns-topic: ' . $topic, 'apns-push-type: alert', 'apns-priority: 10', 'content-type: application/json'],
            json_encode(['aps' => ['alert' => ['title' => $title] + ($body ? ['body' => $body] : []), 'sound' => 'default']] + $data, JSON_UNESCAPED_UNICODE), true);
        if ($code === 200) return [true, '', false]; $why = (string)(json_decode($res, true)['reason'] ?? $err);
        if ($code === 403 && in_array($why, ['ExpiredProviderToken', 'InvalidProviderToken'], true)) pn_cache('apns', []);
        return [false, mb_substr("apns $code $why", 0, 200), $code === 410 || in_array($why, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)];
    }
    if (!pn_fcm_ready()) return [false, 'no firebase key', false];
    [$at, $proj] = pn_fcm_token();
    [$code, $res, $err] = pn_curl('https://fcm.googleapis.com/v1/projects/' . rawurlencode($proj) . '/messages:send', ['Authorization: Bearer ' . $at, 'Content-Type: application/json'],
        json_encode(['message' => ['token' => $tok, 'notification' => ['title' => $title] + ($body ? ['body' => $body] : []), 'android' => ['priority' => 'HIGH']] + ($data ? ['data' => $data] : [])], JSON_UNESCAPED_UNICODE));
    if ($code === 200) return [true, '', false]; $j = json_decode($res, true); $st = (string)($j['error']['status'] ?? ''); $ec = (string)($j['error']['details'][0]['errorCode'] ?? '');
    if ($code === 401) pn_cache('fcm', []);
    return [false, mb_substr("fcm $code $st $ec $err", 0, 200), $code === 404 || $ec === 'UNREGISTERED' || ($code === 400 && $ec === 'INVALID_ARGUMENT')];
}
/** Send what waits. One run at a time; a row is tried once (a failure is written beside it and it is not tried again). */
function pn_flush(int $max = 80): array {
    $out = ['sent' => 0, 'failed' => 0, 'skipped' => 0]; if (!pn_has() || !function_exists('curl_init') || (!pn_fcm_ready() && !pn_apns_ready())) return $out;
    if (!scalar("SELECT GET_LOCK('gas_pn_flush', 0)")) return $out;
    try {
        q("UPDATE mob_push_queue SET sent_at = NOW(), error = 'expired' WHERE sent_at IS NULL AND queued_at < NOW() - INTERVAL 6 HOUR");      // stale news is not sent
        foreach (all('SELECT * FROM mob_push_queue WHERE sent_at IS NULL ORDER BY id LIMIT ' . max(1, min(500, $max))) as $m) {
            $devs = all("SELECT id, app_id, platform, push_token, push_service FROM mob_devices WHERE user_id = ? AND app_id = ? AND revoked_at IS NULL AND push_token IS NOT NULL AND push_token <> ''", [(int)$m['user_id'], (string)$m['app_id']]);
            $ok = 0; $errs = [];
            foreach ($devs as $d) { try { [$good, $e, $dead] = pn_send($d, (string)$m['title'], $m['body'], $m['route']); } catch (Throwable $x) { [$good, $e, $dead] = [false, mb_substr($x->getMessage(), 0, 120), false]; }
                if ($good) $ok++; else $errs[] = $e; if ($dead) q('UPDATE mob_devices SET push_token = NULL WHERE id = ?', [(int)$d['id']]); }
            q('UPDATE mob_push_queue SET sent_at = NOW(), error = ? WHERE id = ?', [$ok ? null : mb_substr(implode('; ', $errs) ?: 'no device', 0, 255), (int)$m['id']]);
            if ($ok) $out['sent']++; elseif ($devs) $out['failed']++; else $out['skipped']++;
        }
    } finally { q("SELECT RELEASE_LOCK('gas_pn_flush')"); }
    return $out;
}
/** One round of the clock job (also called, at most once a minute, after a page view). */
function pn_tick(): array {
    if (!pn_has()) return ['off' => 1]; $o = ['io' => 0];
    try { $o['io'] = pn_io_tick(); } catch (Throwable $e) { $o['io_err'] = mb_substr($e->getMessage(), 0, 80); }
    try { $o['plan'] = pn_plan_tick(); } catch (Throwable $e) { $o['plan_err'] = mb_substr($e->getMessage(), 0, 80); }
    try { $o += pn_flush(); } catch (Throwable $e) { $o['flush_err'] = mb_substr($e->getMessage(), 0, 80); }
    cp_setting_set('pn_last_tick', date('Y-m-d H:i:s')); return $o;
}
/** After a page view: at most once a minute, and never while the visitor waits (called after the response is sent). */
function pn_tick_due(): bool { return pn_has() && (pn_fcm_ready() || pn_apns_ready()) && strtotime((string) cp_setting('pn_last_tick', '2000-01-01')) < time() - 60; }

/* ------------------------------------------------------------------ settings page */
function pn_stats(): array {
    $s = ['devices' => [], 'pending' => 0, 'sent24' => 0, 'fail24' => 0, 'last' => (string) cp_setting('pn_last_tick', ''), 'errors' => []]; if (!pn_has()) return $s;
    foreach (all("SELECT app_id, platform, COUNT(*) n, SUM(push_token IS NOT NULL AND push_token <> '') p FROM mob_devices WHERE revoked_at IS NULL GROUP BY app_id, platform ORDER BY app_id, platform") as $r) $s['devices'][] = $r;
    $s['pending'] = (int) scalar('SELECT COUNT(*) FROM mob_push_queue WHERE sent_at IS NULL');
    $s['sent24'] = (int) scalar('SELECT COUNT(*) FROM mob_push_queue WHERE sent_at > NOW() - INTERVAL 1 DAY AND error IS NULL');
    $s['fail24'] = (int) scalar('SELECT COUNT(*) FROM mob_push_queue WHERE sent_at > NOW() - INTERVAL 1 DAY AND error IS NOT NULL');
    $s['errors'] = all('SELECT error, COUNT(*) n, MAX(sent_at) at FROM mob_push_queue WHERE sent_at > NOW() - INTERVAL 7 DAY AND error IS NOT NULL GROUP BY error ORDER BY at DESC LIMIT 8');
    return $s;
}
/** Store the uploaded keys. Their content is checked for shape only and is never shown, logged or copied to the database. */
function pn_keys_save(): array {
    require_cp_admin(); $done = [];
    $f = $_FILES['fcm'] ?? null;
    if ($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
        if ($f['size'] > 20000) throw new RuntimeException(t('pn.e_fcm')); $j = json_decode((string) file_get_contents($f['tmp_name']), true);
        if (!is_array($j) || ($j['type'] ?? '') !== 'service_account' || empty($j['project_id']) || empty($j['client_email']) || !str_contains((string)($j['private_key'] ?? ''), 'PRIVATE KEY') || !openssl_pkey_get_private((string)$j['private_key'])) throw new RuntimeException(t('pn.e_fcm'));
        if (!move_uploaded_file($f['tmp_name'], pn_fcm_file())) throw new RuntimeException(t('pn.e_store')); @chmod(pn_fcm_file(), 0600); pn_cache('fcm', []); cp_setting_set('pn_fcm_project', mb_substr((string)$j['project_id'], 0, 80)); $done[] = 'Firebase';
    }
    $f = $_FILES['apns'] ?? null;
    if ($f && ($f['error'] ?? 4) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
        $k = (string) file_get_contents($f['tmp_name']); if ($f['size'] > 5000 || !str_contains($k, 'PRIVATE KEY') || !($pk = openssl_pkey_get_private($k)) || (openssl_pkey_get_details($pk)['type'] ?? -1) !== OPENSSL_KEYTYPE_EC) throw new RuntimeException(t('pn.e_apns'));
        if (!move_uploaded_file($f['tmp_name'], pn_apns_file())) throw new RuntimeException(t('pn.e_store')); @chmod(pn_apns_file(), 0600); pn_cache('apns', []); $done[] = 'Apple';
    }
    foreach (['pn_apns_key_id' => 'apns_key_id', 'pn_apns_team_id' => 'apns_team_id'] as $set => $in) { $v = strtoupper(trim((string)($_POST[$in] ?? ''))); if ($v !== '') { if (!preg_match('/^[A-Z0-9]{10}$/', $v)) throw new RuntimeException(t('pn.e_ids')); if ($v !== cp_setting($set, '')) { cp_setting_set($set, $v); pn_cache('apns', []); $done[] = $in; } } }
    return $done;
}
function pn_key_remove(string $which): void { require_cp_admin(); $f = $which === 'fcm' ? pn_fcm_file() : ($which === 'apns' ? pn_apns_file() : ''); if ($f !== '' && is_file($f)) @unlink($f); pn_cache($which === 'fcm' ? 'fcm' : 'apns', []); }
/** A test push to the phones of the administrator himself; sent at once and the answer of Apple / Google is returned. */
function pn_test(): array {
    $u = require_cp_admin(); $uid = (int)$u['id']; $by = pn_devices($uid); if (!$by) throw new RuntimeException(t('pn.e_nodev')); $o = [];
    foreach ($by as $app => $devs) foreach ($devs as $d) { try { [$ok, $e] = pn_send($d, t('pn.test_t'), t('pn.test_b', ['t' => date('H:i')]), null); } catch (Throwable $x) { [$ok, $e] = [false, mb_substr($x->getMessage(), 0, 160)]; } $o[] = ['app' => $app, 'platform' => $d['platform'], 'ok' => $ok, 'error' => $e]; }
    return $o;
}
function pn_handle_action(string $action): bool {
    if (!in_array($action, ['pn_keys', 'pn_key_remove', 'pn_test', 'pn_run'], true)) return false; require_cp_admin();
    try {
        if ($action === 'pn_keys') { $d = pn_keys_save(); flash($d ? 'good' : 'warn', $d ? t('pn.saved', ['n' => implode(', ', $d)]) : t('pn.nothing')); }
        elseif ($action === 'pn_key_remove') { pn_key_remove((string)($_POST['which'] ?? '')); flash('good', t('pn.removed')); }
        elseif ($action === 'pn_test') { $r = pn_test(); $ok = count(array_filter($r, fn($x) => $x['ok'])); flash($ok === count($r) ? 'good' : 'bad', t('pn.test_done', ['ok' => $ok, 'n' => count($r)]) . ($ok < count($r) ? ' — ' . implode(' · ', array_map(fn($x) => $x['app'] . '/' . $x['platform'] . ': ' . $x['error'], array_filter($r, fn($x) => !$x['ok']))) : '')); }
        elseif ($action === 'pn_run') { $r = pn_tick(); flash('good', t('pn.ran', ['s' => (int)($r['sent'] ?? 0), 'f' => (int)($r['failed'] ?? 0), 'i' => (int)($r['io'] ?? 0)])); }
    } catch (RuntimeException $e) { flash('bad', $e->getMessage()); }
    redirect('admin_push'); return true;
}

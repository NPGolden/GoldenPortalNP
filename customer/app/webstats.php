<?php
/** Website statistics — the portal's copy of goldenas.com.gr's first-party, cookieless visitor statistics.  GAS-CP-R95-001
 *
 *  One-way: this portal PULLS aggregated daily rows from the website (api/stats-feed.php?mode=rows) with a shared key
 *  (Settings › System › Website › Statistics feed key). The website never connects to the portal database. If the
 *  website is down or under attack the pull just fails and the last good figures stay.
 *
 *  Store: web_stats_day (d, dim, k, n, u, x) and web_stats_hour (d, h, n, u) — exactly the website's aggregates:
 *    dim total   k=all        n views, u visitors
 *    dim page    k=page       n views, u unique viewers of that page
 *    dim country / region (CC/Region) / device / os / browser / lang / ref / campaign   n views, u visitors
 *    dim search  k=term       n searches, x searches with no result
 *    dim engage  k=page       n samples, x sum of max scroll %     dim time k=page  n samples, x seconds
 *    dim video / outbound / download / form / lang  k=label  n events
 */
declare(strict_types=1);

function ws_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'web_stats_day'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ws_url(): string { return (string) (cp_setting('ws_feed_url', '') ?: 'https://www.goldenas.com.gr/api/stats-feed.php'); }
function ws_key(): string { return (string) cp_setting('ws_feed_key', ''); }
function ws_configured(): bool { return ws_has() && ws_key() !== ''; }

/** Fetch rows for a date range from the website and store them (REPLACE → idempotent). Returns [ok, message]. */
function ws_pull(string $from, string $to): array {
    if (!ws_configured()) return [false, 'not configured'];
    $url = ws_url() . (str_contains(ws_url(), '?') ? '&' : '?') . http_build_query(['mode' => 'rows', 'from' => $from, 'to' => $to]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['X-Stats-Key: ' . ws_key(), 'Accept: application/json', 'User-Agent: GoldenPortal/' . (defined('CP_VERSION') ? CP_VERSION : '1')],
        CURLOPT_MAXFILESIZE => 6 * 1024 * 1024]);
    $body = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Website statistics'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    if ($body === false || $code !== 200) { cp_setting_set('ws_last_error', gmdate('c') . ' HTTP ' . $code . ' ' . $err); return [false, 'HTTP ' . $code . ' ' . $err]; }
    if (strlen((string) $body) > 6 * 1024 * 1024) return [false, 'response too large'];
    $j = json_decode((string) $body, true);
    if (!is_array($j) || !isset($j['day']) || !is_array($j['day'])) { cp_setting_set('ws_last_error', gmdate('c') . ' bad payload'); return [false, 'bad payload']; }
    $db = db(); $db->beginTransaction();
    try {
        $sd = $db->prepare('REPLACE INTO web_stats_day (d, dim, k, n, u, x) VALUES (?,?,?,?,?,?)');
        foreach ($j['day'] as $r) { if (!is_array($r) || count($r) < 6) continue; [$d, $dim, $k, $n, $u, $x] = $r;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d) || !preg_match('/^[a-z]{1,12}$/', (string) $dim)) continue;
            $sd->execute([$d, $dim, mb_substr((string) $k, 0, 80), max(0, (int) $n), max(0, (int) $u), max(0, (int) $x)]); }
        $sh = $db->prepare('REPLACE INTO web_stats_hour (d, h, n, u) VALUES (?,?,?,?)');
        foreach ($j['hour'] ?? [] as $r) { if (!is_array($r) || count($r) < 4) continue; [$d, $h, $n, $u] = $r; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $d)) continue; $sh->execute([$d, max(0, min(23, (int) $h)), max(0, (int) $n), max(0, (int) $u)]); }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); cp_setting_set('ws_last_error', gmdate('c') . ' ' . $e->getMessage()); return [false, $e->getMessage()]; }
    cp_setting_set('ws_last_pull', gmdate('c')); cp_setting_set('ws_last_error', '');
    if (isset($j['live']) && is_array($j['live'])) cp_setting_set('ws_live', json_encode(['at' => gmdate('c')] + $j['live']));
    return [true, count($j['day']) . ' rows'];
}

/** Called from the notification sweep: every 15 minutes the last 3 days; once a day the last 45 days; on Mondays 400 days (fills history / corrections). */
function ws_sweep(): void {
    if (!ws_configured()) return;
    $last = (string) cp_setting('ws_last_pull', ''); $now = time();
    if ($last !== '' && strtotime($last) > $now - 15 * 60) return;
    $today = gmdate('Y-m-d'); $daily = (string) cp_setting('ws_daily_pull', '');
    if ($daily !== $today) { cp_setting_set('ws_daily_pull', $today); ws_pull(gmdate('Y-m-d', $now - (gmdate('N') === '1' ? 400 : 45) * 86400), $today); ws_digest_maybe(); return; }
    ws_pull(gmdate('Y-m-d', $now - 3 * 86400), $today);
}

/** Monday digest to administrators: last week vs the week before. */
function ws_digest_maybe(): void {
    if (gmdate('N') !== '1' || (string) cp_setting('ws_digest_week', '') === gmdate('o-W')) return;
    cp_setting_set('ws_digest_week', gmdate('o-W'));
    $to = gmdate('Y-m-d', strtotime('yesterday')); $from = gmdate('Y-m-d', strtotime($to . ' -6 days'));
    $p = ws_summary($from, $to); $q = ws_summary(gmdate('Y-m-d', strtotime($from . ' -7 days')), gmdate('Y-m-d', strtotime($from . ' -1 day')));
    $pct = fn($a, $b) => $b > 0 ? sprintf('%+d%%', round(($a - $b) / $b * 100)) : '';
    $top = ws_top('page', $from, $to, 1)[0]['k'] ?? '—';
    $body = t('ws.digest_body', ['visitors' => $p['visitors'], 'dv' => $pct($p['visitors'], $q['visitors']), 'views' => $p['views'], 'dp' => $pct($p['views'], $q['views']), 'top' => $top, 'forms' => $p['forms'], 'countries' => $p['countries']]);
    try { $ids = array_map(fn($r) => (int) $r['id'], all("SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1")); if (function_exists('nf_push')) nf_push($ids, 'tk', 'ws:' . gmdate('o-W'), t('ws.digest_title', ['from' => $from, 'to' => $to]), $body, url('web_stats', ['p' => 'custom', 'from' => $from, 'to' => $to]), null, 'Website'); } catch (Throwable $e) {}
}

/* ---------- queries for the page ---------- */
function ws_summary(string $from, string $to): array {
    $t = one('SELECT COALESCE(SUM(n),0) views, COALESCE(SUM(u),0) visitors FROM web_stats_day WHERE dim="total" AND d BETWEEN ? AND ?', [$from, $to]) ?: ['views' => 0, 'visitors' => 0];
    $e = one('SELECT COALESCE(SUM(x),0) sx, COALESCE(SUM(n),0) sn FROM web_stats_day WHERE dim="time" AND d BETWEEN ? AND ?', [$from, $to]);
    $s = one('SELECT COALESCE(SUM(x),0) sx, COALESCE(SUM(n),0) sn FROM web_stats_day WHERE dim="engage" AND d BETWEEN ? AND ?', [$from, $to]);
    $forms = (int) scalar('SELECT COALESCE(SUM(n),0) FROM web_stats_day WHERE dim="form" AND d BETWEEN ? AND ?', [$from, $to]);
    $countries = (int) scalar('SELECT COUNT(DISTINCT k) FROM web_stats_day WHERE dim="country" AND d BETWEEN ? AND ?', [$from, $to]);
    $searches = (int) scalar('SELECT COALESCE(SUM(n),0) FROM web_stats_day WHERE dim="search" AND d BETWEEN ? AND ?', [$from, $to]);
    $videos = (int) scalar('SELECT COALESCE(SUM(n),0) FROM web_stats_day WHERE dim="video" AND d BETWEEN ? AND ?', [$from, $to]);
    $portal = (int) scalar('SELECT COALESCE(SUM(n),0) FROM web_stats_day WHERE dim="outbound" AND k LIKE "goldensas.com%" AND d BETWEEN ? AND ?', [$from, $to]);
    $v = (int) $t['views']; $u = (int) $t['visitors'];
    return ['views' => $v, 'visitors' => $u, 'pages_per_visit' => $u ? round($v / $u, 1) : 0, 'avg_seconds' => (int) $e['sn'] ? (int) round($e['sx'] / $e['sn']) : 0, 'avg_scroll' => (int) $s['sn'] ? (int) round($s['sx'] / $s['sn']) : 0,
            'forms' => $forms, 'countries' => $countries, 'searches' => $searches, 'videos' => $videos, 'portal_clicks' => $portal,
            'days' => max(1, (int) ((strtotime($to) - strtotime($from)) / 86400) + 1)];
}
function ws_series(string $from, string $to): array { return all('SELECT d, n views, u visitors FROM web_stats_day WHERE dim="total" AND d BETWEEN ? AND ? ORDER BY d', [$from, $to]); }
function ws_monthly(string $from, string $to): array { return all('SELECT DATE_FORMAT(d, "%Y-%m") m, SUM(n) views, SUM(u) visitors FROM web_stats_day WHERE dim="total" AND d BETWEEN ? AND ? GROUP BY m ORDER BY m', [$from, $to]); }
function ws_top(string $dim, string $from, string $to, int $limit = 12): array { return all('SELECT k, SUM(n) n, SUM(u) u, SUM(x) x FROM web_stats_day WHERE dim = ? AND d BETWEEN ? AND ? GROUP BY k ORDER BY n DESC, k LIMIT ' . (int) $limit, [$dim, $from, $to]); }
function ws_hours(string $from, string $to): array { $r = array_fill(0, 24, ['n' => 0, 'u' => 0]); foreach (all('SELECT h, SUM(n) n, SUM(u) u FROM web_stats_hour WHERE d BETWEEN ? AND ? GROUP BY h', [$from, $to]) as $x) $r[(int) $x['h']] = ['n' => (int) $x['n'], 'u' => (int) $x['u']]; return $r; }
function ws_weekdays(string $from, string $to): array { $r = array_fill(0, 7, 0); foreach (all('SELECT WEEKDAY(d) wd, SUM(n) n FROM web_stats_day WHERE dim="total" AND d BETWEEN ? AND ? GROUP BY wd', [$from, $to]) as $x) $r[(int) $x['wd']] = (int) $x['n']; return $r; }
function ws_engagement(string $from, string $to): array {
    return all('SELECT p.k page, SUM(p.n) views, SUM(p.u) uniq, ROUND(COALESCE(e.sx / NULLIF(e.sn,0),0)) scroll, ROUND(COALESCE(t.sx / NULLIF(t.sn,0),0)) secs
                FROM web_stats_day p
                LEFT JOIN (SELECT k, SUM(x) sx, SUM(n) sn FROM web_stats_day WHERE dim="engage" AND d BETWEEN ? AND ? GROUP BY k) e ON e.k = p.k
                LEFT JOIN (SELECT k, SUM(x) sx, SUM(n) sn FROM web_stats_day WHERE dim="time" AND d BETWEEN ? AND ? GROUP BY k) t ON t.k = p.k
                WHERE p.dim="page" AND p.d BETWEEN ? AND ? GROUP BY p.k ORDER BY views DESC LIMIT 30', [$from, $to, $from, $to, $from, $to]);
}
/** Conversions from the portal's own inbox (the forms actually received), per kind, for the period. */
function ws_inquiries(string $from, string $to): array {
    try { return all('SELECT kind, COUNT(*) n FROM web_inquiries WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY kind', [$from, $to]); } catch (Throwable $e) { return []; }
}
/** Period presets → [from, to, previous_from, previous_to, label]. */
function ws_period(string $p, string $from = '', string $to = ''): array {
    $today = gmdate('Y-m-d'); $d = fn(string $s, int $days) => gmdate('Y-m-d', strtotime($s . ' ' . $days . ' days'));
    switch ($p) {
        case 'today': $f = $t = $today; break;
        case 'yesterday': $f = $t = $d($today, -1); break;
        case '7d': $t = $today; $f = $d($today, -6); break;
        case 'month': $f = gmdate('Y-m-01'); $t = $today; break;
        case 'lastmonth': $f = gmdate('Y-m-01', strtotime('first day of last month')); $t = gmdate('Y-m-t', strtotime('last day of last month')); break;
        case 'year': $f = gmdate('Y-01-01'); $t = $today; break;
        case 'lastyear': $f = (gmdate('Y') - 1) . '-01-01'; $t = (gmdate('Y') - 1) . '-12-31'; break;
        case '12m': $t = $today; $f = gmdate('Y-m-01', strtotime('-11 months')); break;
        case 'custom': $ok = fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s); $f = $ok($from) ? $from : $d($today, -29); $t = $ok($to) ? $to : $today; if ($f > $t) [$f, $t] = [$t, $f]; break;
        default: $p = '30d'; $t = $today; $f = $d($today, -29);
    }
    $len = (int) ((strtotime($t) - strtotime($f)) / 86400) + 1;
    return [$f, $t, $d($f, -$len), $d($f, -1), $p];
}
/** Human label for a page key. */
function ws_page_label(string $k): string {
    static $m = ['index' => 'Home', 'about' => 'About Us', 'services' => 'Solutions', 'capabilities' => 'Capabilities', 'project-management' => 'Project Mgmt', 'projects' => 'Projects', 'knowledge' => 'Knowledge', 'service' => 'GTT Service', 'careers' => 'Careers', 'contact' => 'Contact', 'support' => 'Support request', 'portal-access' => 'Portal access', 'privacy' => 'Privacy', 'gdpr' => 'GDPR', 'cookies' => 'Cookies', 'iacms' => 'IACMS', 'eswitchboard' => 'eSWITCHBOARD', 'integrated-bridges' => 'Integrated Bridges', 'shipyards' => 'For Shipyards', 'owners' => 'Owners & Captains', 'cyber-resilience' => 'Cyber resilience', 'other' => 'Other'];
    return $m[$k] ?? $k;
}
function ws_form_label(string $k): string { static $m = ['api/contact.php' => 'web.kind_contact', 'api/ticket.php' => 'web.kind_ticket', 'api/portal-access.php' => 'web.kind_access', 'careers' => 'ws.form_careers']; return $m[$k] ?? $k; }
function ws_country_name(string $cc): string { static $n = ['GR' => 'Greece', 'IT' => 'Italy', 'NL' => 'Netherlands', 'DE' => 'Germany', 'GB' => 'United Kingdom', 'US' => 'United States', 'FR' => 'France', 'ES' => 'Spain', 'MC' => 'Monaco', 'TR' => 'Türkiye', 'AE' => 'UAE', 'ME' => 'Montenegro', 'HR' => 'Croatia', 'CH' => 'Switzerland', 'BE' => 'Belgium', 'DK' => 'Denmark', 'NO' => 'Norway', 'SE' => 'Sweden', 'FI' => 'Finland', 'PL' => 'Poland', 'AT' => 'Austria', 'PT' => 'Portugal', 'MT' => 'Malta', 'CY' => 'Cyprus', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'SG' => 'Singapore', 'AU' => 'Australia', 'CA' => 'Canada', 'CN' => 'China', 'JP' => 'Japan', 'IN' => 'India', 'BR' => 'Brazil', 'RU' => 'Russia', 'UA' => 'Ukraine', 'BG' => 'Bulgaria', 'RO' => 'Romania', 'TW' => 'Taiwan', 'KR' => 'South Korea', 'IE' => 'Ireland', 'NZ' => 'New Zealand', 'ZA' => 'South Africa', 'EG' => 'Egypt', 'IL' => 'Israel', 'LB' => 'Lebanon']; return $n[$cc] ?? $cc; }

/* ---------- actions ---------- */
function ws_handle_action(string $action): bool {
    if ($action === 'ws_pull_now') { require_staff(); [$ok, $m] = ws_pull(gmdate('Y-m-d', strtotime('-45 days')), gmdate('Y-m-d')); flash($ok ? 'good' : 'bad', $ok ? t('ws.pulled', ['n' => $m]) : t('ws.pull_failed', ['e' => $m])); redirect('web_stats', ['_r' => uniqid()]); }
    if ($action === 'ws_settings_save') { require_cp_admin();
        $url = trim((string) ($_POST['ws_feed_url'] ?? '')); if ($url !== '' && !preg_match('#^https://[a-z0-9.-]+/[^\s]*$#i', $url)) $url = '';
        cp_setting_set('ws_feed_url', $url);
        $k = trim((string) ($_POST['ws_feed_key'] ?? '')); if ($k !== '' && preg_match('/^[A-Za-z0-9_\-]{16,128}$/', $k)) cp_setting_set('ws_feed_key', $k);
        if (!empty($_POST['ws_clear_key'])) cp_setting_set('ws_feed_key', '');
        flash('good', t('sys.saved')); redirect('settings', ['tab' => 'website', '_r' => uniqid()]); }
    if ($action === 'ws_export') { require_staff(); [$f, $t] = ws_period((string) ($_POST['p'] ?? '30d'), (string) ($_POST['from'] ?? ''), (string) ($_POST['to'] ?? ''));
        header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="goldenas-website-stats_' . $f . '_' . $t . '.csv"');
        $o = fopen('php://output', 'w'); fputs($o, "\xEF\xBB\xBF"); fputcsv($o, ['date', 'dimension', 'key', 'count', 'visitors', 'extra']);
        foreach (all('SELECT d, dim, k, n, u, x FROM web_stats_day WHERE d BETWEEN ? AND ? ORDER BY d, dim, n DESC', [$f, $t]) as $r) fputcsv($o, [$r['d'], $r['dim'], $r['k'], $r['n'], $r['u'], $r['x']]);
        fclose($o); exit; }
    return false;
}

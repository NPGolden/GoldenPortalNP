<?php
/**
 * GAS-CP-R97-293 — Wellness II: for the people in the field as much as for the people at a desk.
 *   quiet hours (a push waits until they end) · rest between shifts and days in a row (private) · travel reminder · heat / cold / wind notice for the planned
 *   place (forecast of MET Norway, CC BY 4.0) · leave nudge (private) · workload per team for planning (groups of five or more) · follow-up of the mood
 *   check-in for HR · company information and anonymous questions to HR · thank a colleague (the kudos of Community) · the same in the apps.
 * Nothing here is reported per person to a manager: private notices go to the person only, figures are shown per group.
 */
const WL_REST_MIN_H = 11;          // daily rest between two shifts below this is noticed
const WL_STREAK_DAYS = 7;          // days in a row with an In
const WL_LONG_DAY_H = 10;          // a long day for the workload figure
const WL_GROUP_MIN = 5;            // a group smaller than this shows no figure
const WL_HOT_C = 32; const WL_COLD_C = 3; const WL_WIND_MS = 14;   // thresholds of the weather notice (about 50 km/h)
const WL_LEAVE_DAYS = 120;         // no annual leave for this many days → a nudge
const WL_URGENT = ['nf:sec', 'plan:', 'nf:plan', 'io:'];   // pushes that do not wait for the quiet hours to end

function wl2_has(): bool { static $h = null; if ($h === null) { try { $h = wl_has() && (bool) scalar("SHOW TABLES LIKE 'wl_questions'"); } catch (Throwable $e) { $h = false; } } return $h; }
function wl_hr(): bool { return wl_can() && function_exists('hr_can') && hr_can(); }
function wl_now(): DateTime { return $GLOBALS['wl_now_fake'] ?? new DateTime('now', new DateTimeZone('Europe/Athens')); }
/** A private notice to one person (kind wl:*). */
function wl_note(int $uid, string $key, string $ref, string $title, string $body, ?string $url = null): int { return function_exists('nf_push') ? (int) nf_push([$uid], 'wl:' . $key, 'wl:' . $key . ':' . $ref, $title, $body, $url ?? url('wellness'), null, null) : 0; }
function wl_lang_of(int $uid): string { static $c = []; return $c[$uid] ??= (string)(scalar('SELECT locale FROM customer_users WHERE id = ?', [$uid]) ?: 'en'); }
function wl_t(int $uid, string $k, array $v = []): string { return function_exists('t_in') ? (string) t_in($k, wl_lang_of($uid), $v) : t($k, $v); }

/* ------------------------------------------------------------------ 7 · quiet hours */
/** Minutes a push to this person has to wait: 0 when the person has no quiet hours, is outside them, or the news is urgent. */
function wl_quiet_minutes(int $uid, string $kind): int {
    if (!wl_has()) return 0; foreach (WL_URGENT as $p) if (str_starts_with($kind, $p)) return 0;
    $P = wl_prefs($uid); if (empty($P['quiet'])) return 0; $from = (int)$P['quiet_from']; $to = (int)$P['quiet_to']; if ($from === $to) return 0;
    $n = wl_now(); $m = (int)$n->format('G') * 60 + (int)$n->format('i');
    $in = $from < $to ? ($m >= $from && $m < $to) : ($m >= $from || $m < $to); if (!$in) return 0;
    $left = $to - $m; if ($left <= 0) $left += 1440; return $left;
}
function wl_hm(int $min): string { return sprintf('%02d:%02d', intdiv($min, 60) % 24, $min % 60); }
function wl_hm_parse(string $s, int $def): int { return preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($s), $m) ? (int)$m[1] * 60 + (int)$m[2] : $def; }

/* ------------------------------------------------------------------ 3 · rest between shifts, days in a row */
/** Hourly: every new In is looked at once. The first In of a day less than WL_REST_MIN_H hours after the last Out, and the 7th (14th …) day in a row, are told to the person only. */
function wl_rest_sweep(): int {
    if (!wl_has() || !function_exists('io_has') || !io_has()) return 0; $last = (int) cp_setting('wl_rest_last', '0'); $max = (int) scalar('SELECT COALESCE(MAX(id), 0) FROM io_entries');
    if ($last <= 0) { cp_setting_set('wl_rest_last', (string)$max); return 0; }   // first run: start from now, no notices about the past
    $n = 0;
    foreach (all("SELECT * FROM io_entries WHERE id > ? AND kind = 'in' AND status <> 'rejected' AND at_utc > UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY id LIMIT 500", [$last]) as $e) { $uid = (int)$e['user_id'];
        if (empty(wl_prefs($uid)['rest'])) continue;
        if (scalar("SELECT COUNT(*) FROM io_entries WHERE user_id = ? AND local_day = ? AND kind = 'in' AND status <> 'rejected' AND (at_utc < ? OR (at_utc = ? AND id < ?))", [$uid, $e['local_day'], $e['at_utc'], $e['at_utc'], (int)$e['id']])) continue;   // not the first In of the day
        $out = (string) scalar("SELECT MAX(at_utc) FROM io_entries WHERE user_id = ? AND kind = 'out' AND status <> 'rejected' AND at_utc < ? AND local_day < ?", [$uid, $e['at_utc'], $e['local_day']]);
        if ($out !== '') { $gap = (strtotime($e['at_utc'] . ' UTC') - strtotime($out . ' UTC')) / 3600; if ($gap > 0 && $gap < WL_REST_MIN_H) $n += wl_note($uid, 'rest', (string)$e['local_day'], wl_t($uid, 'wl.n_rest_t', ['h' => number_format($gap, 1)]), wl_t($uid, 'wl.n_rest', ['h' => WL_REST_MIN_H])); }
        $days = array_column(all("SELECT DISTINCT local_day FROM io_entries WHERE user_id = ? AND kind = 'in' AND status <> 'rejected' AND local_day <= ? AND local_day > DATE_SUB(?, INTERVAL 40 DAY) ORDER BY local_day DESC", [$uid, $e['local_day'], $e['local_day']]), 'local_day');
        $streak = 0; $d = (string)$e['local_day']; foreach ($days as $x) { if ($x !== $d) break; $streak++; $d = date('Y-m-d', strtotime($d . ' -1 day')); }
        if ($streak >= WL_STREAK_DAYS && $streak % WL_STREAK_DAYS === 0) $n += wl_note($uid, 'streak', (string)$e['local_day'], wl_t($uid, 'wl.n_streak_t', ['n' => $streak]), wl_t($uid, 'wl.n_streak'));
    }
    cp_setting_set('wl_rest_last', (string)$max); return $n;
}
/* ------------------------------------------------------------------ 4 · travel */
/** From 18:00, once a day: who is planned to travel tomorrow gets the checklist. */
function wl_travel_sweep(): int {
    if (!wl_has() || !function_exists('pl_has') || !pl_has()) return 0; $now = wl_now(); $day = $now->format('Y-m-d'); if ((int)$now->format('G') < 18 || (string) cp_setting('wl_travel_day', '') === $day) return 0; cp_setting_set('wl_travel_day', $day);
    $tom = (clone $now)->modify('+1 day')->format('Y-m-d'); $n = 0;
    foreach (all("SELECT DISTINCT user_id FROM plan_entries WHERE plan_date = ? AND kind = 'travel'", [$tom]) as $r) { $uid = (int)$r['user_id']; if (empty(wl_prefs($uid)['travel'])) continue; $n += wl_note($uid, 'travel', $tom, wl_t($uid, 'wl.n_travel_t'), wl_t($uid, 'wl.n_travel')); }
    return $n;
}
/* ------------------------------------------------------------------ 2 · weather of the planned place */
function wl_places(bool $activeOnly = true): array { try { return wl2_has() ? all('SELECT * FROM wl_places' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name') : []; } catch (Throwable $e) { return []; } }
function wl_place_save_from_post(): void {
    if (!wl_trend_can() || !wl2_has()) throw new RuntimeException(t('err.noaccess')); $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['name'] ?? ''))), 0, 80); $match = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['match_text'] ?? ''))), 0, 255);
    $lat = str_replace(',', '.', trim((string)($_POST['lat'] ?? ''))); $lng = str_replace(',', '.', trim((string)($_POST['lng'] ?? '')));
    if ($name === '' || $match === '' || !is_numeric($lat) || !is_numeric($lng) || abs((float)$lat) > 90 || abs((float)$lng) > 180) throw new RuntimeException(t('wl.pl_bad'));
    if ((int) scalar('SELECT COUNT(*) FROM wl_places') >= 200) throw new RuntimeException(t('wl.pl_many'));
    q('INSERT INTO wl_places (name, match_text, lat, lng) VALUES (?,?,?,?)', [$name, $match, round((float)$lat, 4), round((float)$lng, 4)]);
}
function wl_place_delete(int $id): void { if (!wl_trend_can() || !wl2_has()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM wl_places WHERE id = ?', [$id]); }
/** The place of a planned day: the first place one of whose words is found in the vessel, its yard, the project or the kind of the day (office, warehouse). */
function wl_place_of(array $places, string $hay): ?array { $hay = mb_strtolower($hay); foreach ($places as $p) foreach (explode(',', mb_strtolower((string)$p['match_text'])) as $w) { $w = trim($w); if (mb_strlen($w) >= 3 && mb_strpos($hay, $w) !== false) return $p; } return null; }
/** Forecast of the day between 05:00 and 18:00 UTC: [max °C, min °C, max wind m/s] or null. One request per place and day, kept in the settings.
 *  Source: Locationforecast 2.0 of MET Norway (CC BY 4.0) — only the coordinates of the place are sent, never anything about a person. */
function wl_forecast(array $p, string $day): ?array {
    $key = 'wl_fc_' . (int)$p['id']; $c = json_decode((string) cp_setting($key, ''), true); if (is_array($c) && ($c['day'] ?? '') === $day) return $c['v'] ?? null;
    $ts = null;
    if (isset($GLOBALS['wl_weather_fake'])) $ts = ($GLOBALS['wl_weather_fake'])($p, $day);
    elseif (function_exists('curl_init')) { $ch = curl_init('https://api.met.no/weatherapi/locationforecast/2.0/compact?lat=' . number_format((float)$p['lat'], 4, '.', '') . '&lon=' . number_format((float)$p['lng'], 4, '.', ''));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'GoldenAS-Portal/' . (defined('CP_VERSION') ? CP_VERSION : '1') . ' https://www.goldenas.com.gr']);
        $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); if ($code === 200 && is_string($raw) && strlen($raw) < 600000) $ts = json_decode($raw, true)['properties']['timeseries'] ?? null; }
    $v = null;
    if (is_array($ts)) { $hi = null; $lo = null; $w = null; foreach ($ts as $x) { $t = (string)($x['time'] ?? ''); if (substr($t, 0, 10) !== $day) continue; $h = (int) substr($t, 11, 2); if ($h < 5 || $h > 18) continue; $d = $x['data']['instant']['details'] ?? []; if (!isset($d['air_temperature'])) continue;
            $a = (float)$d['air_temperature']; $hi = $hi === null ? $a : max($hi, $a); $lo = $lo === null ? $a : min($lo, $a); if (isset($d['wind_speed'])) $w = $w === null ? (float)$d['wind_speed'] : max($w, (float)$d['wind_speed']); }
        if ($hi !== null) $v = [round($hi), round($lo), round((float)$w)]; }
    cp_setting_set($key, json_encode(['day' => $day, 'v' => $v])); return $v;
}
/** From 06:00, once a day: people planned at a place with a hot, cold or windy day get a notice. */
function wl_weather_sweep(): int {
    if (!wl2_has() || !function_exists('pl_has') || !pl_has()) return 0; $now = wl_now(); $day = $now->format('Y-m-d'); if ((int)$now->format('G') < 6 || (int)$now->format('G') > 11 || (string) cp_setting('wl_weather_day', '') === $day) return 0; cp_setting_set('wl_weather_day', $day);
    $places = wl_places(); if (!$places) return 0; $n = 0; $done = [];
    foreach (all("SELECT e.user_id, e.kind, COALESCE(v.name, pv.name, '') AS vessel, COALESCE(v.yard, pv.yard, '') AS yard, COALESCE(p.project_code, '') AS code, COALESCE(p.name, '') AS pname FROM plan_entries e LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN vessels v ON v.id = e.vessel_id LEFT JOIN vessels pv ON pv.id = p.vessel_id WHERE e.plan_date = ? AND e.kind IN ('work','office','warehouse','training','travel')", [$day]) as $e) {
        $uid = (int)$e['user_id']; if (isset($done[$uid]) || empty(wl_prefs($uid)['weather'])) continue; $pl = wl_place_of($places, $e['vessel'] . ' ' . $e['yard'] . ' ' . $e['code'] . ' ' . $e['pname'] . ' ' . $e['kind']); if (!$pl) continue;
        $f = wl_forecast($pl, $day); if (!$f) continue; [$hi, $lo, $w] = $f; $k = $hi >= WL_HOT_C ? 'hot' : ($lo <= WL_COLD_C ? 'cold' : ($w >= WL_WIND_MS ? 'wind' : null)); if (!$k) continue;
        $done[$uid] = 1; $n += wl_note($uid, 'weather', $day, wl_t($uid, 'wl.n_w_' . $k . '_t', ['p' => (string)$pl['name'], 'hi' => $hi, 'lo' => $lo, 'w' => (int) round($w * 3.6)]), wl_t($uid, 'wl.n_w_' . $k) . ' ' . wl_t($uid, 'wl.n_w_src'));
    }
    return $n;
}
/* ------------------------------------------------------------------ 6 · leave nudge */
/** Once a month (from the 3rd): a person with annual leave left who took none for WL_LEAVE_DAYS days is reminded — the person only. */
function wl_leave_sweep(): int {
    if (!wl_has() || !function_exists('hr_annual_type_id') || !($tid = hr_annual_type_id())) return 0; $now = wl_now(); $ym = $now->format('Y-m'); if ((int)$now->format('j') < 3 || (int)$now->format('G') < 9 || (string) cp_setting('wl_leave_month', '') === $ym) return 0; cp_setting_set('wl_leave_month', $ym);
    $n = 0; $year = (int)$now->format('Y'); $lim = (clone $now)->modify('-' . WL_LEAVE_DAYS . ' days')->format('Y-m-d');
    foreach (all("SELECT p.* FROM hr_people p JOIN customer_users u ON u.id = p.user_id AND u.is_active = 1 WHERE p.is_active = 1 AND p.user_id IS NOT NULL") as $p) { $uid = (int)$p['user_id']; if (empty(wl_prefs($uid)['leave_nudge'])) continue;
        if ((string)($p['hire_date'] ?? '') > $lim) continue;   // new in the company
        if (scalar("SELECT COUNT(*) FROM hr_requests r JOIN hr_leave_types t ON t.id = r.type_id WHERE r.person_id = ? AND r.status IN ('approved','pending') AND t.unit = 'day' AND (t.rule_key = 'annual' OR t.consumes_annual = 1) AND r.date_to >= ?", [(int)$p['id'], $lim])) continue;
        try { $b = hr_balance($p, $tid, $year); } catch (Throwable $e) { continue; } $left = $b['available'] ?? null; if ($left === null || (float)$left < 1) continue;
        $n += wl_note($uid, 'leave', $ym, wl_t($uid, 'wl.n_leave_t'), wl_t($uid, 'wl.n_leave', ['d' => rtrim(rtrim(number_format((float)$left, 1), '0'), '.')]), url('my_leave'));
    }
    return $n;
}
/* ------------------------------------------------------------------ 5 · workload per team */
function wl_workload_can(): bool { return wl_trend_can() || (wl_can() && function_exists('pl_can') && pl_can()); }
/** Per week and team: people with In / Out, long days, weekend days worked. A team with fewer than WL_GROUP_MIN people shows no figures. */
function wl_workload(int $weeks = 6): array {
    if (!wl_workload_can() || !function_exists('io_has') || !io_has()) return []; $weeks = max(1, min(12, $weeks)); $out = [];
    $rows = all("SELECT x.user_id, x.local_day, TIMESTAMPDIFF(MINUTE, x.t_in, x.t_out) AS span, COALESCE(dp.name, '—') AS team
                 FROM (SELECT user_id, local_day, MIN(CASE WHEN kind = 'in' THEN at_utc END) AS t_in, MAX(CASE WHEN kind = 'out' THEN at_utc END) AS t_out FROM io_entries WHERE status <> 'rejected' AND local_day >= CURDATE() - INTERVAL ? DAY GROUP BY user_id, local_day) x
                 LEFT JOIN hr_people hp ON hp.user_id = x.user_id LEFT JOIN disciplines dp ON dp.id = hp.department_id", [$weeks * 7 + 7]);
    $agg = [];
    foreach ($rows as $r) { $wk = date('o-\WW', strtotime((string)$r['local_day'])); $mon = date('Y-m-d', strtotime((string)$r['local_day'] . ' -' . ((int) date('N', strtotime((string)$r['local_day'])) - 1) . ' days'));
        foreach ([(string)$r['team'], '*'] as $team) { $a = &$agg[$mon][$team]; $a ??= ['u' => [], 'long' => 0, 'wend' => 0, 'days' => 0]; $a['u'][(int)$r['user_id']] = 1; $a['days']++; if ((int)$r['span'] >= WL_LONG_DAY_H * 60) $a['long']++; if ((int) date('N', strtotime((string)$r['local_day'])) >= 6) $a['wend']++; unset($a); } }
    krsort($agg);
    foreach (array_slice($agg, 0, $weeks, true) as $mon => $teams) { ksort($teams); foreach ($teams as $team => $a) { $n = count($a['u']); $out[] = ['from' => $mon, 'team' => $team, 'n' => $n, 'days' => $n >= WL_GROUP_MIN ? $a['days'] : null, 'long' => $n >= WL_GROUP_MIN ? $a['long'] : null, 'wend' => $n >= WL_GROUP_MIN ? $a['wend'] : null]; } }
    return $out;
}
/* ------------------------------------------------------------------ 9 · follow-up of the mood check-in */
/** [last full week, the week before] as ['n', 'avg'] — avg null below WL_MOOD_MIN answers. */
function wl_mood_two_weeks(): array {
    $now = wl_now(); $mon = (clone $now)->modify('monday this week')->format('Y-m-d'); $o = [];
    foreach ([[date('Y-m-d', strtotime($mon . ' -7 days')), $mon], [date('Y-m-d', strtotime($mon . ' -14 days')), date('Y-m-d', strtotime($mon . ' -7 days'))]] as [$a, $b]) { $r = one('SELECT COUNT(*) n, AVG(mood) a FROM wl_mood WHERE day >= ? AND day < ?', [$a, $b]); $o[] = ['from' => $a, 'n' => (int)$r['n'], 'avg' => (int)$r['n'] >= WL_MOOD_MIN ? round((float)$r['a'], 1) : null]; }
    return $o;
}
function wl_mood_low(): ?array { [$w, $p] = wl_mood_two_weeks(); if ($w['avg'] === null) return null; $drop = $p['avg'] !== null ? round($p['avg'] - $w['avg'], 1) : 0.0; return ($w['avg'] < 3.0 || $drop >= 0.5) ? ['avg' => $w['avg'], 'prev' => $p['avg'], 'drop' => $drop, 'from' => $w['from']] : null; }
/** Mondays from 09:00, once: HR hears when last week was low or fell clearly. */
function wl_mood_sweep(): int {
    if (!wl_has()) return 0; $now = wl_now(); $day = $now->format('Y-m-d'); if ($now->format('N') !== '1' || (int)$now->format('G') < 9 || (string) cp_setting('wl_mood_week', '') === $day) return 0; cp_setting_set('wl_mood_week', $day);
    $low = wl_mood_low(); if (!$low || !function_exists('nf_push')) return 0; $to = function_exists('hre_hr_uids') ? hre_hr_uids() : []; $n = 0;
    foreach ($to as $uid) $n += (int) nf_push([(int)$uid], 'wl:mood', 'wl:mood:' . $low['from'], wl_t((int)$uid, 'wl.n_mood_t', ['a' => number_format($low['avg'], 1)]), wl_t((int)$uid, $low['prev'] !== null && $low['drop'] >= 0.5 ? 'wl.n_mood_drop' : 'wl.n_mood_low', ['p' => $low['prev'] !== null ? number_format($low['prev'], 1) : '—']), url('wellness') . '#trend', null, null);
    return $n;
}
/* ------------------------------------------------------------------ 10 · company information, anonymous questions */
const WL_INFO = ['doctor', 'insurance', 'incident', 'other'];
function wl_info(): array { $j = json_decode((string) cp_setting('wl_info', ''), true); $o = []; foreach (WL_INFO as $k) $o[$k] = is_array($j) ? (string)($j[$k] ?? '') : ''; return $o; }
function wl_info_save_from_post(): void { if (!wl_hr()) throw new RuntimeException(t('err.noaccess')); $o = []; foreach (WL_INFO as $k) $o[$k] = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string)($_POST['info'][$k] ?? ''))), 0, 800); cp_setting_set('wl_info', json_encode($o, JSON_UNESCAPED_UNICODE)); }
/** An anonymous question to HR: the text and the day are stored, not the person. At most two a day per person (the counter is emptied every night). */
function wl_question_ask(string $body): void {
    if (!wl_can() || !wl2_has()) throw new RuntimeException(t('err.noaccess')); $body = mb_substr(trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $body)), 0, 1000); if (mb_strlen($body) < 10) throw new RuntimeException(t('wl.q_short'));
    $uid = (int) current_user()['id']; if ((int) scalar('SELECT n FROM wl_q_seen WHERE user_id = ? AND day = CURDATE()', [$uid]) >= 2) throw new RuntimeException(t('wl.q_limit'));
    q('INSERT INTO wl_q_seen (user_id, day, n) VALUES (?, CURDATE(), 1) ON DUPLICATE KEY UPDATE n = n + 1', [$uid]); q('INSERT INTO wl_questions (day, body) VALUES (CURDATE(), ?)', [$body]); $id = (int) db()->lastInsertId();
    if (function_exists('nf_push') && function_exists('hre_hr_uids')) foreach (hre_hr_uids() as $h) { try { nf_push([(int)$h], 'wl:q', 'wl:q:' . $id, wl_t((int)$h, 'wl.n_q_t'), mb_substr($body, 0, 200), url('wellness') . '#questions', null, null); } catch (Throwable $e) {} }
}
function wl_question_answer_from_post(): void {
    if (!wl_hr() || !wl2_has()) throw new RuntimeException(t('err.noaccess')); $id = (int)($_POST['q_id'] ?? 0); $a = mb_substr(trim((string)($_POST['answer'] ?? '')), 0, 3000);
    if (!empty($_POST['q_delete'])) { q('DELETE FROM wl_questions WHERE id = ?', [$id]); return; }
    if (mb_strlen($a) < 2) throw new RuntimeException(t('wl.q_need_answer')); q('UPDATE wl_questions SET answer = ?, answered_at = NOW(), answered_name = ?, is_public = ? WHERE id = ?', [$a, mb_substr((string)(current_user()['full_name'] ?? ''), 0, 120), empty($_POST['is_public']) ? 0 : 1, $id]);
}
function wl_questions_public(int $n = 20): array { try { return wl2_has() ? all('SELECT id, day, body, answer, answered_at FROM wl_questions WHERE is_public = 1 AND answer IS NOT NULL ORDER BY answered_at DESC LIMIT ' . max(1, min(50, $n))) : []; } catch (Throwable $e) { return []; } }
function wl_questions_all(): array { return wl_hr() && wl2_has() ? all('SELECT * FROM wl_questions ORDER BY (answer IS NULL) DESC, id DESC LIMIT 200') : []; }

/** Hourly job: every sweep looks after its own time of day. */
function wl2_sweep(): int { $n = 0; foreach (['wl_rest_sweep', 'wl_travel_sweep', 'wl_weather_sweep', 'wl_leave_sweep', 'wl_mood_sweep'] as $f) { try { $n += (int)$f(); } catch (Throwable $e) { error_log('[wl2] ' . $f . ': ' . $e->getMessage()); } } return $n; }
function wl2_daily(): void { try { if (wl2_has()) q('DELETE FROM wl_q_seen WHERE day < CURDATE()'); } catch (Throwable $e) {} }

function wl2_handle_action(string $action): bool {
    if (!in_array($action, ['wl_place_add', 'wl_place_del', 'wl_info_save', 'wl_q_ask', 'wl_q_answer'], true)) return false; require_login();
    try { switch ($action) {
        case 'wl_place_add': wl_place_save_from_post(); flash('good', t('wl.saved')); redirect('wellness', ['_r' => uniqid()], '#places');
        case 'wl_place_del': wl_place_delete((int)($_POST['place_id'] ?? 0)); flash('good', t('wl.saved')); redirect('wellness', ['_r' => uniqid()], '#places');
        case 'wl_info_save': wl_info_save_from_post(); flash('good', t('wl.saved')); redirect('wellness', ['_r' => uniqid()], '#info');
        case 'wl_q_ask':     wl_question_ask((string)($_POST['body'] ?? '')); flash('good', t('wl.q_sent')); redirect('wellness', ['_r' => uniqid()], '#questions');
        case 'wl_q_answer':  wl_question_answer_from_post(); flash('good', t('wl.saved')); redirect('wellness', ['_r' => uniqid()], '#questions');
    } } catch (RuntimeException $e) { flash('bad', $e->getMessage()); redirect('wellness', ['_r' => uniqid()]); }
    return true;
}

<?php
/** In / Out (GAS-CP-R97-182, v1.214.0) — attendance punches of the staff.
 *  A punch = In or Out with the time, the position of the phone (latitude, longitude, accuracy) and the nearest known site.
 *  Punches inside a known site and inside the person's working time (work calendar, Settings › Calendars) are accepted
 *  automatically; the others wait for HR, management, the person's manager or a project manager. Overtime of a day is
 *  accepted or rejected separately and is shown beside the timesheet and the service reports of that day.
 *  The portal itself registers In / Out only from the network addresses of a site. Statistics: HR, CEO, General Manager. */
declare(strict_types=1);

const IO_FLAGS = ['outside', 'late', 'early', 'weakgps', 'nogps', 'noin', 'double', 'offday'];
const IO_EXC   = ['outside', 'late', 'early', 'weakgps', 'nogps', 'noin', 'double'];   // flags that make a punch wait for a decision (offday is overtime, decided there)

function io_has(): bool { static $v = null; if ($v === null) { try { $v = (bool) one("SHOW TABLES LIKE 'io_entries'"); } catch (Throwable $e) { $v = false; } } return $v; }
function io_set(string $k, int $def): int { $v = function_exists('cp_setting') ? (string)(cp_setting($k, '') ?? '') : ''; return $v !== '' && is_numeric($v) ? max(0, (int)$v) : $def; }
function io_tol(): int { return io_set('io_tolerance_min', 10); }      // minutes after the start / before the end that are still on time
function io_weak(): int { return io_set('io_weak_gps_m', 150); }       // a fix less accurate than this is flagged
function io_ot_min(): int { return io_set('io_ot_min', 30); }          // overtime below this is not raised
function io_diff_min(): int { return io_set('io_diff_min', 30); }      // difference to timesheet / service report that is shown as a mismatch

/** Everyone who may decide on punches and overtime: HR and management for all, a manager for his people, a project manager for all field punches. */
function io_can_all(): bool { return is_staff() && ((function_exists('hr_can') && hr_can()) || (function_exists('mgmt_can') && mgmt_can()) || is_cp_admin() || (function_exists('pm_is_pm') && pm_is_pm())); }
function io_can_decide(int $uid): bool {
    if (!is_staff()) return false; if (io_can_all()) return true;
    $me = (int)(current_user()['id'] ?? 0); if ($me === $uid) return false;
    $p = function_exists('hr_person_of_user') ? hr_person_of_user($uid) : null;
    return $p && function_exists('hr_manager_of') && hr_manager_of($p) === $me;
}
function io_team_ids(): ?array {   // null = everyone
    if (io_can_all()) return null; $me = (int)(current_user()['id'] ?? 0); $ids = [];
    if (function_exists('hr_people')) foreach (hr_people(true) as $p) if (!empty($p['user_id']) && function_exists('hr_manager_of') && hr_manager_of($p) === $me) $ids[] = (int)$p['user_id'];
    return $ids;
}
/** Statistics are for HR, the CEO and the General Manager only. */
function io_stats_can(): bool { return is_staff() && ((function_exists('hr_can') && hr_can()) || (function_exists('mgmt_can') && mgmt_can())); }
function io_sites_can(): bool { return is_staff() && ((function_exists('hr_can') && hr_can()) || (function_exists('mgmt_can') && mgmt_can()) || is_cp_admin()); }

function io_staff(): array { static $c = null; if ($c === null) { $demo = ''; try { if (one("SHOW COLUMNS FROM customer_users LIKE 'is_demo'")) $demo = ' AND is_demo = 0'; } catch (Throwable $e) {} $c = []; foreach (all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1$demo ORDER BY full_name") as $r) $c[(int)$r['id']] = (string)$r['full_name']; } return $c; }
function io_sites(bool $activeOnly = true): array { return io_has() ? all('SELECT * FROM io_sites' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY name') : []; }
function io_dist(float $la1, float $lo1, float $la2, float $lo2): int { $r = 6371000; $a = sin(deg2rad($la2 - $la1) / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin(deg2rad($lo2 - $lo1) / 2) ** 2; return (int) round(2 * $r * asin(min(1, sqrt($a)))); }
function io_nearest(?float $lat, ?float $lng): ?array {
    if ($lat === null || $lng === null) return null; $best = null;
    foreach (io_sites() as $s) { $d = io_dist($lat, $lng, (float)$s['lat'], (float)$s['lng']); if ($best === null || $d < $best['dist']) $best = ['site' => $s, 'dist' => $d]; }
    return $best;
}
function io_map_url(?string $lat, ?string $lng): string { return $lat === null || $lng === null || $lat === '' ? '' : 'https://www.google.com/maps?q=' . rawurlencode(rtrim(rtrim(number_format((float)$lat, 6, '.', ''), '0'), '.') . ',' . rtrim(rtrim(number_format((float)$lng, 6, '.', ''), '0'), '.')); }

/** The address the request really comes from. Behind the Sucuri firewall the visitor's address is in X-Sucuri-ClientIP. */
function io_client_ip(): string { foreach (['HTTP_X_SUCURI_CLIENTIP', 'HTTP_CF_CONNECTING_IP'] as $h) { $v = trim((string)($_SERVER[$h] ?? '')); if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v; } $v = trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]); return filter_var($v, FILTER_VALIDATE_IP) ? $v : ''; }
/** The site whose registered network addresses contain this address (exact, or a prefix written with a trailing *). */
function io_site_of_ip(string $ip): ?array {
    if ($ip === '') return null;
    foreach (io_sites() as $s) foreach (array_filter(array_map('trim', explode(',', (string)$s['ips']))) as $pat) {
        if ($pat === $ip) return $s;
        if (str_ends_with($pat, '*') && strlen($pat) > 4 && str_starts_with($ip, substr($pat, 0, -1))) return $s;
    }
    return null;
}

/* ---- acceptance of the notice (R97-183): asked once, the first time a person uses In / Out; a new version of the notice asks again ---- */
const IO_CONSENT_VER = '1';
function io_consent_has_table(): bool { static $v = null; if ($v === null) { try { $v = (bool) one("SHOW TABLES LIKE 'io_consents'"); } catch (Throwable $e) { $v = false; } } return $v; }
function io_consent(int $uid): ?array { return io_consent_has_table() ? one('SELECT * FROM io_consents WHERE user_id = ? AND version = ?', [$uid, IO_CONSENT_VER]) : null; }
/** True when the person may punch: accepted, or the acceptance table is not there yet (database update pending). */
function io_consent_ok(int $uid): bool { return !io_consent_has_table() || io_consent($uid) !== null; }
/** Store the acceptance and write it to the HR log of the person (Changes tab of the HR card). */
function io_consent_accept(int $uid, string $source, ?string $at = null): array {
    if (!io_consent_has_table()) throw new RuntimeException(t('io.need'));
    if ($c = io_consent($uid)) return $c;
    $ts = time(); if ($at && ($x = strtotime($at)) !== false && $x <= $ts + 300 && $x > $ts - 7 * 86400) $ts = $x;   // accepted on the phone without signal: the phone's time, up to a week back
    $ip = io_client_ip(); $dev = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160); $loc = function_exists('locale') ? (string) locale() : 'en';
    q('INSERT IGNORE INTO io_consents (user_id, version, locale, source, ip, device, accepted_at) VALUES (?,?,?,?,?,?,?)', [$uid, IO_CONSENT_VER, mb_substr($loc, 0, 5), mb_substr($source, 0, 12), $ip ?: null, $dev ?: null, gmdate('Y-m-d H:i:s', $ts)]);
    $p = function_exists('hr_person_of_user') ? hr_person_of_user($uid) : null;
    if ($p && function_exists('hr_audit')) { try { hr_audit((int)$p['id'], 'consent', t('io.title'), null, t('io.c_logged', ['v' => IO_CONSENT_VER]), t('io.src_' . (in_array($source, ['portal', 'employee', 'engineer', 'pm', 'hr'], true) ? $source : 'portal')) . ($ip !== '' ? ' · ' . $ip : '') . ' · ' . gmdate('Y-m-d H:i', $ts) . ' UTC'); } catch (Throwable $e) {} }
    return io_consent($uid) ?? [];
}
/** Who has accepted (HR view): [uid => row]. */
function io_consents_all(): array { $o = []; if (io_consent_has_table()) foreach (all('SELECT * FROM io_consents WHERE version = ? ORDER BY accepted_at', [IO_CONSENT_VER]) as $r) $o[(int)$r['user_id']] = $r; return $o; }

/** State of a person now: 'in' when the last accepted or waiting punch of the last 20 hours is an In. */
function io_state(int $uid, ?int $at = null): array {   // $at: the moment to look from (a punch sent later is judged at its own time)
    $ref = gmdate('Y-m-d H:i:s', $at ?? time());
    $l = io_has() ? one("SELECT * FROM io_entries WHERE user_id = ? AND status <> 'rejected' AND at_utc <= ? AND at_utc > ? - INTERVAL 20 HOUR ORDER BY at_utc DESC, id DESC LIMIT 1", [$uid, $ref, $ref]) : null;
    return ['state' => $l && $l['kind'] === 'in' ? 'in' : 'out', 'last' => $l];
}

/** Register a punch. $in: kind, at (ISO time of the phone, optional), tz_min, lat, lng, acc, uuid, note, source, ip. Returns the stored row. */
function io_punch(int $uid, array $in): array {
    if (!io_has()) throw new RuntimeException(t('io.need'));
    if (!io_consent_ok($uid)) throw new RuntimeException(t('io.c_need'));   // R97-183: nothing is recorded before the person has accepted the notice
    $kind = ($in['kind'] ?? '') === 'out' ? 'out' : 'in';
    $uuid = preg_match('/^[0-9a-fA-F-]{36}$/', (string)($in['uuid'] ?? '')) ? strtolower((string)$in['uuid']) : null;
    if ($uuid && ($x = one('SELECT * FROM io_entries WHERE uuid = ?', [$uuid]))) return $x;   // the same punch sent twice
    $now = time(); $ts = $now; $late = 0;
    if (!empty($in['at']) && ($c = strtotime((string)$in['at'])) !== false) { if ($c > $now + 300) $c = $now; if ($c < $now - 7 * 86400) $c = $now; $ts = $c; $late = ($now - $c) > 600 ? 1 : 0; }   // the phone's time is taken up to a week back (no signal), never from the future
    $tz = isset($in['tz_min']) && is_numeric($in['tz_min']) ? max(-720, min(840, (int)$in['tz_min'])) : (int) round((new DateTime('now', new DateTimeZone(tz_app())))->getOffset() / 60);
    $lts = $ts + $tz * 60; $day = gmdate('Y-m-d', $lts); $tm = gmdate('H:i:s', $lts);
    $lat = isset($in['lat']) && is_numeric($in['lat']) && abs((float)$in['lat']) <= 90 ? round((float)$in['lat'], 6) : null;
    $lng = isset($in['lng']) && is_numeric($in['lng']) && abs((float)$in['lng']) <= 180 ? round((float)$in['lng'], 6) : null;
    if ($lat === null || $lng === null) { $lat = $lng = null; }
    $acc = isset($in['acc']) && is_numeric($in['acc']) ? max(0, min(100000, (int) round((float)$in['acc']))) : null;
    $flags = []; $siteId = null; $dist = null;
    if ($lat === null) $flags[] = 'nogps';
    else { $n = io_nearest($lat, $lng); if ($n) { $siteId = (int)$n['site']['id']; $dist = $n['dist']; if ($dist > (int)$n['site']['radius_m'] + min((int)($acc ?? 0), 100)) $flags[] = 'outside'; }
           if ($acc !== null && $acc > io_weak()) $flags[] = 'weakgps'; }
    // working time of the person (work calendar)
    $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $d = function_exists('wc_day') ? wc_day($cal, $day) : ['workday' => true, 'rule' => null];
    $tol = io_tol() * 60; $sec = strtotime('1970-01-01 ' . $tm . ' UTC');
    if (!$d['workday']) $flags[] = 'offday';
    elseif (!empty($d['rule']['start_time']) && !empty($d['rule']['end_time'])) {
        $s = strtotime('1970-01-01 ' . $d['rule']['start_time'] . ' UTC'); $e = strtotime('1970-01-01 ' . $d['rule']['end_time'] . ' UTC');
        $first = !scalar("SELECT COUNT(*) FROM io_entries WHERE user_id = ? AND local_day = ? AND kind = 'in' AND status <> 'rejected'", [$uid, $day]);
        if ($kind === 'in' && $first && $sec > $s + $tol) $flags[] = 'late';
        if ($kind === 'out' && $sec < $e - $tol) $flags[] = 'early';
    }
    $st = io_state($uid, $ts);
    if ($kind === 'in' && $st['state'] === 'in') $flags[] = 'double';
    if ($kind === 'out' && $st['state'] !== 'in') $flags[] = 'noin';
    $status = array_intersect($flags, IO_EXC) ? 'pending' : 'auto';
    q('INSERT INTO io_entries (uuid, user_id, kind, at_utc, tz_min, local_day, local_time, lat, lng, acc_m, site_id, dist_m, source, ip, late_sync, flags, status, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$uuid, $uid, $kind, gmdate('Y-m-d H:i:s', $ts), $tz, $day, $tm, $lat, $lng, $acc, $siteId, $dist, mb_substr((string)($in['source'] ?? 'portal'), 0, 12), mb_substr((string)($in['ip'] ?? io_client_ip()), 0, 45) ?: null, $late, implode(',', $flags), $status, mb_substr(trim((string)($in['note'] ?? '')), 0, 300) ?: null]);
    return one('SELECT * FROM io_entries WHERE id = ?', [(int) db()->lastInsertId()]);
}
/** In / Out from the portal: only from a network address registered on a site; the position is the site's. */
function io_punch_portal(string $kind): array {
    $u = require_staff(); $ip = io_client_ip(); $s = io_site_of_ip($ip);
    if (!$s) throw new RuntimeException(t('io.ip_refused'));
    return io_punch((int)$u['id'], ['kind' => $kind, 'lat' => $s['lat'], 'lng' => $s['lng'], 'acc' => 0, 'source' => 'portal', 'ip' => $ip]);
}
function io_decide(int $id, string $status, string $note): void {
    $e = one('SELECT * FROM io_entries WHERE id = ?', [$id]); if (!$e || !io_can_decide((int)$e['user_id'])) throw new RuntimeException(t('err.noaccess'));
    if (!in_array($status, ['approved', 'rejected'], true)) throw new RuntimeException(t('err.noaccess'));
    q('UPDATE io_entries SET status = ?, decided_by = ?, decided_at = UTC_TIMESTAMP(), decide_note = ? WHERE id = ?', [$status, (int)current_user()['id'], mb_substr(trim($note), 0, 300) ?: null, $id]);
}
function io_ot_decide(int $uid, string $day, string $status, string $note): void {
    if (!io_can_decide($uid) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !in_array($status, ['approved', 'rejected'], true)) throw new RuntimeException(t('err.noaccess'));
    $s = io_day($uid, $day);
    q('INSERT INTO io_overtime (user_id, day, minutes, status, decided_by, decided_at, note) VALUES (?,?,?,?,?,UTC_TIMESTAMP(),?) ON DUPLICATE KEY UPDATE minutes = VALUES(minutes), status = VALUES(status), decided_by = VALUES(decided_by), decided_at = VALUES(decided_at), note = VALUES(note)',
        [$uid, $day, (int)$s['ot_min'], $status, (int)current_user()['id'], mb_substr(trim($note), 0, 300) ?: null]);
}
function io_site_save(array $in): void {
    if (!io_sites_can()) throw new RuntimeException(t('err.noaccess'));
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120); $pos = trim((string)($in['pos'] ?? ''));
    if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;\s]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $pos, $m)) { $lat = (float)$m[1]; $lng = (float)$m[2]; } else { $lat = (float)($in['lat'] ?? 999); $lng = (float)($in['lng'] ?? 999); }
    if ($name === '' || abs($lat) > 90 || abs($lng) > 180) throw new RuntimeException(t('io.site_bad'));
    $ips = implode(', ', array_filter(array_map(function ($p) { $p = trim($p); return $p !== '' && preg_match('/^[0-9a-fA-F:.]{3,45}\*?$/', $p) ? $p : null; }, explode(',', (string)($in['ips'] ?? '')))));
    $rad = max(30, min(20000, (int)($in['radius_m'] ?? 300))); $act = empty($in['is_active']) ? 0 : 1; $id = (int)($in['id'] ?? 0);
    if ($id) q('UPDATE io_sites SET name = ?, lat = ?, lng = ?, radius_m = ?, ips = ?, is_active = ? WHERE id = ?', [$name, $lat, $lng, $rad, $ips ?: null, $act, $id]);
    else q('INSERT INTO io_sites (name, lat, lng, radius_m, ips, is_active) VALUES (?,?,?,?,?,?)', [$name, $lat, $lng, $rad, $ips ?: null, $act]);
}
function io_settings_save(array $in): void {
    if (!io_sites_can()) throw new RuntimeException(t('err.noaccess'));
    foreach (['io_tolerance_min' => [0, 120], 'io_weak_gps_m' => [20, 5000], 'io_ot_min' => [0, 240], 'io_diff_min' => [0, 240]] as $k => [$lo, $hi]) if (isset($in[$k])) cp_setting_set($k, (string) max($lo, min($hi, (int)$in[$k])));
}

function io_entries(array $f, int $limit = 400): array {
    if (!io_has()) return []; $w = ['1=1']; $b = [];
    if (!empty($f['user'])) { $w[] = 'e.user_id = ?'; $b[] = (int)$f['user']; }
    if (!empty($f['users'])) { $w[] = 'e.user_id IN (' . implode(',', array_map('intval', $f['users'])) . ')'; }
    if (isset($f['users']) && !$f['users']) return [];
    if (!empty($f['from'])) { $w[] = 'e.local_day >= ?'; $b[] = $f['from']; }
    if (!empty($f['to'])) { $w[] = 'e.local_day <= ?'; $b[] = $f['to']; }
    if (!empty($f['status'])) { $w[] = 'e.status = ?'; $b[] = $f['status']; }
    return all('SELECT e.*, cu.full_name, s.name AS site_name, s.radius_m, d.full_name AS decided_name FROM io_entries e JOIN customer_users cu ON cu.id = e.user_id LEFT JOIN io_sites s ON s.id = e.site_id LEFT JOIN customer_users d ON d.id = e.decided_by WHERE ' . implode(' AND ', $w) . ' ORDER BY e.at_utc DESC, e.id DESC LIMIT ' . (int)$limit, $b);
}
/** Hours booked by the person for a day: timesheet (all, and overtime + holiday) and service reports (working time). */
function io_booked(int $uid, string $day): array {
    $ts = ['all' => null, 'ot' => 0.0]; $fsr = null;
    try { foreach (all('SELECT l.hour_type, SUM(l.hours) h FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE t.user_id = ? AND l.work_date = ? GROUP BY l.hour_type', [$uid, $day]) as $r) { $ts['all'] = ($ts['all'] ?? 0) + (float)$r['h']; if ($r['hour_type'] !== 'normal') $ts['ot'] += (float)$r['h']; } } catch (Throwable $e) {}
    try { $v = scalar("SELECT SUM(l.hours) FROM fsr_lines l JOIN fsr_reports r ON r.id = l.report_id WHERE r.created_by = ? AND r.report_date = ? AND l.time_type = 'WT'", [$uid, $day]); $fsr = $v === null ? null : (float)$v; } catch (Throwable $e) {}
    return ['ts' => $ts['all'], 'ts_ot' => $ts['ot'], 'fsr' => $fsr];
}
/** One day of one person: punches, worked minutes (pairs In → Out, rejected punches left out), schedule, overtime and the cross-check. */
function io_day(int $uid, string $day, ?array $rows = null): array {
    $rows = $rows ?? all("SELECT * FROM io_entries WHERE user_id = ? AND local_day = ? ORDER BY at_utc, id", [$uid, $day]);
    $worked = 0; $open = null; $first = null; $last = null; $exc = 0; $pend = 0;
    foreach ($rows as $r) {
        if ($r['status'] === 'pending') $pend++; if ($r['status'] === 'rejected') continue;
        if (array_intersect(explode(',', (string)$r['flags']), IO_EXC)) $exc++;
        $t = strtotime($r['at_utc'] . ' UTC');
        if ($r['kind'] === 'in') { if ($open === null) $open = $t; if ($first === null) $first = $r; }
        else { if ($open !== null) { $worked += max(0, $t - $open); $open = null; } $last = $r; }
    }
    $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $d = function_exists('wc_day') ? wc_day($cal, $day) : ['workday' => true, 'normal' => 8.0, 'rule' => null];
    $sched = (int) round(((float)($d['normal'] ?? 0)) * 60); $brk = (int)($d['rule']['break_min'] ?? 0); $min = (int) round($worked / 60);
    $net = $d['workday'] && $min >= 300 ? max(0, $min - $brk) : $min;   // the break is taken off a working day of five hours or more
    $ot = max(0, $net - ($d['workday'] ? $sched : 0)); if ($ot < io_ot_min()) $ot = 0;
    $bk = io_booked($uid, $day); $mm = [];
    if ($bk['ts'] !== null && abs($bk['ts'] * 60 - $net) > io_diff_min()) $mm[] = 'ts';
    if ($bk['fsr'] !== null && $bk['fsr'] * 60 - $net > io_diff_min()) $mm[] = 'fsr';   // more working time on service reports than the presence shows
    $otd = io_has() ? one('SELECT o.*, d.full_name AS decided_name FROM io_overtime o LEFT JOIN customer_users d ON d.id = o.decided_by WHERE o.user_id = ? AND o.day = ?', [$uid, $day]) : null;
    return ['day' => $day, 'rows' => $rows, 'first' => $first, 'last' => $last, 'open' => $open !== null, 'worked_min' => $min, 'net_min' => $net, 'sched_min' => $sched, 'workday' => (bool)$d['workday'], 'ot_min' => $ot, 'ot' => $otd, 'booked' => $bk, 'mismatch' => $mm, 'exceptions' => $exc, 'pending' => $pend];
}
/** Days with overtime in a period, newest first: [uid, name, day, summary]. */
function io_overtime_days(string $from, string $to, ?array $users = null): array {
    if (!io_has()) return []; if ($users !== null && !$users) return [];
    $w = $users === null ? '' : ' AND user_id IN (' . implode(',', array_map('intval', $users)) . ')'; $out = []; $names = io_staff();
    foreach (all("SELECT DISTINCT user_id, local_day FROM io_entries WHERE local_day BETWEEN ? AND ?$w ORDER BY local_day DESC, user_id LIMIT 1500", [$from, $to]) as $r) {
        $s = io_day((int)$r['user_id'], (string)$r['local_day']); if ($s['ot_min'] <= 0 && !$s['ot'] && !$s['mismatch']) continue;
        $out[] = ['uid' => (int)$r['user_id'], 'name' => $names[(int)$r['user_id']] ?? ('#' . $r['user_id']), 'day' => (string)$r['local_day'], 's' => $s];
    }
    return $out;
}
function io_pending_count(): int {
    static $n = null; if ($n !== null) return $n; if (!io_has() || !is_staff()) return $n = 0;
    $ids = io_team_ids(); if ($ids !== null && !$ids) return $n = 0;
    try { return $n = (int) scalar("SELECT COUNT(*) FROM io_entries WHERE status = 'pending'" . ($ids === null ? '' : ' AND user_id IN (' . implode(',', $ids) . ')')); } catch (Throwable $e) { return $n = 0; }
}
/** Today's board: every member of staff with the first In, the last Out and the state. */
function io_board(string $day): array {
    $by = []; foreach (all("SELECT e.*, s.name AS site_name FROM io_entries e LEFT JOIN io_sites s ON s.id = e.site_id WHERE e.local_day = ? AND e.status <> 'rejected' ORDER BY e.at_utc, e.id", [$day]) as $r) $by[(int)$r['user_id']][] = $r;
    $out = []; foreach (io_staff() as $uid => $name) { $rows = $by[$uid] ?? []; $in = null; $o = null; foreach ($rows as $r) { if ($r['kind'] === 'in' && !$in) $in = $r; if ($r['kind'] === 'out') $o = $r; }
        $lastK = $rows ? $rows[count($rows) - 1]['kind'] : null; $out[] = ['uid' => $uid, 'name' => $name, 'in' => $in, 'out' => $lastK === 'out' ? $o : null, 'state' => $lastK === 'in' ? 'in' : ($rows ? 'out' : 'none'), 'n' => count($rows)]; }
    return $out;
}
/** Statistics per person for a period (HR, CEO, General Manager). */
function io_stats(string $from, string $to): array {
    if (!io_stats_can()) throw new RuntimeException(t('err.noaccess'));
    $rows = all('SELECT * FROM io_entries WHERE local_day BETWEEN ? AND ? ORDER BY user_id, at_utc, id', [$from, $to]); $by = [];
    foreach ($rows as $r) $by[(int)$r['user_id']][(string)$r['local_day']][] = $r;
    $ot = []; foreach (all('SELECT user_id, status, SUM(minutes) m FROM io_overtime WHERE day BETWEEN ? AND ? GROUP BY user_id, status', [$from, $to]) as $r) $ot[(int)$r['user_id']][$r['status']] = (int)$r['m'];
    $today = date('Y-m-d'); $end = min($to, $today); $out = [];
    foreach (io_staff() as $uid => $name) {
        $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $wd = 0;
        for ($t = strtotime($from); $t <= strtotime($end); $t += 86400) { $d = date('Y-m-d', $t); if (function_exists('wc_day') ? wc_day($cal, $d)['workday'] : (date('N', $t) < 6)) $wd++; }
        $days = $by[$uid] ?? []; $present = 0; $complete = 0; $punch = 0; $clean = 0; $f = array_fill_keys(IO_FLAGS, 0); $lateMin = 0; $ins = 0; $ontime = 0; $rej = 0;
        foreach ($days as $d => $list) {
            $has = false; $open = false; $firstIn = true;
            foreach ($list as $r) { if ($r['status'] === 'rejected') { $rej++; continue; } $has = true; $punch++; $fl = array_filter(explode(',', (string)$r['flags'])); if (!array_intersect($fl, IO_EXC)) $clean++; foreach ($fl as $x) if (isset($f[$x])) $f[$x]++;
                if ($r['kind'] === 'in') { $open = true; if ($firstIn) { $firstIn = false; $ins++; if (!in_array('late', $fl, true)) $ontime++; else { $c = function_exists('wc_day') ? wc_day($cal, $d) : null; if ($c && !empty($c['rule']['start_time'])) $lateMin += max(0, (int) round((strtotime('1970-01-01 ' . $r['local_time']) - strtotime('1970-01-01 ' . $c['rule']['start_time'])) / 60)); } } }
                else $open = false; }
            if ($has) { $present++; if (!$open || $d === $today) $complete++; }
        }
        if (!$punch && !$wd) continue;
        $out[] = ['uid' => $uid, 'name' => $name, 'workdays' => $wd, 'present' => $present, 'complete' => $complete, 'punches' => $punch, 'clean' => $clean, 'flags' => $f, 'ins' => $ins, 'ontime' => $ontime, 'late_avg' => ($ins - $ontime) > 0 ? (int) round($lateMin / ($ins - $ontime)) : 0, 'rejected' => $rej,
            'consistency' => $wd ? (int) round(100 * min($complete, $wd) / $wd) : null, 'accuracy' => $punch ? (int) round(100 * $clean / $punch) : null, 'punctual' => $ins ? (int) round(100 * $ontime / $ins) : null, 'ot_ok' => $ot[$uid]['approved'] ?? 0, 'ot_no' => $ot[$uid]['rejected'] ?? 0];
    }
    return $out;
}
/** A stored UTC date-time shown in the company's time zone. */
function io_fmt_utc(?string $utc): string { if (!$utc) return '—'; try { return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(tz_app()))->format('d/m/Y H:i'); } catch (Throwable $e) { return (string)$utc; } }
function io_hm(int $min): string { return sprintf('%d:%02d', intdiv(max(0, $min), 60), max(0, $min) % 60); }
/** Shape of a punch for the apps. */
function io_row_api(array $r): array { return ['id' => (int)$r['id'], 'kind' => $r['kind'], 'at' => gmdate('c', strtotime($r['at_utc'] . ' UTC')), 'day' => $r['local_day'], 'time' => substr((string)$r['local_time'], 0, 5), 'lat' => $r['lat'] === null ? null : (float)$r['lat'], 'lng' => $r['lng'] === null ? null : (float)$r['lng'], 'acc' => $r['acc_m'] === null ? null : (int)$r['acc_m'],
    'site' => $r['site_name'] ?? null, 'dist' => $r['dist_m'] === null ? null : (int)$r['dist_m'], 'flags' => array_values(array_filter(explode(',', (string)$r['flags']))), 'status' => $r['status'], 'map' => io_map_url($r['lat'] === null ? null : (string)$r['lat'], $r['lng'] === null ? null : (string)$r['lng']), 'note' => $r['decide_note'] ?? null, 'late_sync' => (bool)$r['late_sync']]; }
/** What the apps show: state, today's working time, the punches of the last 14 days and the sites. */
function io_api_me(int $uid): array {
    $st = io_state($uid); $today = gmdate('Y-m-d', time() + (int) round((new DateTime('now', new DateTimeZone(tz_app())))->getOffset()));
    $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $d = function_exists('wc_day') ? wc_day($cal, $today) : ['workday' => true, 'rule' => null];
    $rows = io_entries(['user' => $uid, 'from' => date('Y-m-d', strtotime('-14 days'))], 120);
    $c = io_consent($uid);
    return ['enabled' => io_has(), 'consent' => ['accepted' => io_consent_ok($uid), 'version' => IO_CONSENT_VER, 'at' => $c ? gmdate('c', strtotime($c['accepted_at'] . ' UTC')) : null, 'title' => t('io.c_title'), 'text' => t('io.c_text'), 'accept' => t('io.c_accept')], 'state' => $st['state'], 'since' => $st['last'] ? gmdate('c', strtotime($st['last']['at_utc'] . ' UTC')) : null,
        'schedule' => ['workday' => (bool)$d['workday'], 'start' => !empty($d['rule']['start_time']) ? substr((string)$d['rule']['start_time'], 0, 5) : null, 'end' => !empty($d['rule']['end_time']) ? substr((string)$d['rule']['end_time'], 0, 5) : null, 'tolerance_min' => io_tol()],
        'sites' => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'lat' => (float)$s['lat'], 'lng' => (float)$s['lng'], 'radius_m' => (int)$s['radius_m']], io_sites()),
        'entries' => array_map('io_row_api', $rows)];
}

<?php
/**
 * Working calendars — normal working hours and national holidays per country.          GAS-CP-R23-001
 *
 * Settings › Working hours: one calendar per country (GR, IT, …) with the working
 * days, start / end / break per weekday and the holidays of each year (standard
 * list generated per country, Excel import, manual entries). Every staff account
 * follows one calendar (Portal accounts; default GR).
 *
 * Timesheets: when a sheet is saved, wc_classify() looks at every line:
 *   · a holiday or a non-working day → all hours become holiday (Sunday / public holiday)
 *     or overtime (Saturday), as the weekday rule says;
 *   · a working day → hours beyond the day's normal hours become overtime
 *     (the line is split; the engineer sees "auto" on the reclassified part).
 * Disciplines carry a cost rate per hour type; the Manhours report shows costs.
 */
declare(strict_types=1);

function wc_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'work_hours'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function wc_calendars(bool $activeOnly = false): array {
    if (!wc_has()) return [];
    static $c = null;
    if ($c === null) { $c = []; foreach (all('SELECT * FROM work_calendars ORDER BY sort_order, code') as $r) $c[$r['code']] = $r; }
    return $activeOnly ? array_filter($c, fn($r) => $r['is_active']) : $c;
}
/** Weekday rules 1..7 for a calendar; normal_hours per day computed. */
function wc_hours(string $code): array {
    static $c = [];
    if (!isset($c[$code])) {
        $c[$code] = [];
        foreach (all('SELECT * FROM work_hours WHERE calendar_code = ? ORDER BY dow', [$code]) as $r) {
            $n = 0.0;
            if ($r['is_workday'] && $r['start_time'] && $r['end_time']) $n = max(0, (strtotime('1970-01-01 ' . $r['end_time']) - strtotime('1970-01-01 ' . $r['start_time'])) / 3600 - (int)$r['break_min'] / 60);
            $r['normal_hours'] = round($n * 4) / 4; $c[$code][(int)$r['dow']] = $r;
        }
    }
    return $c[$code];
}
function wc_user_calendar(int $uid): string {
    $code = null;
    try { if (wc_has()) $code = scalar('SELECT work_calendar FROM customer_users WHERE id = ?', [$uid]); } catch (Throwable $e) {}
    $code = (string)$code; $cals = wc_calendars();
    if ($code === '' || !isset($cals[$code])) $code = isset($cals['GR']) ? 'GR' : (string) array_key_first($cals);
    return $code;
}
function wc_holiday(string $code, string $date): ?string {
    static $c = [];
    $y = substr($date, 0, 4);
    if (!isset($c[$code][$y])) { $c[$code][$y] = []; foreach (all('SELECT hdate, name FROM work_holidays WHERE calendar_code = ? AND YEAR(hdate) = ?', [$code, (int)$y]) as $r) $c[$code][$y][$r['hdate']] = $r['name'];
        if (!$c[$code][$y]) { $cal = wc_calendars()[$code] ?? null; $c[$code][$y] = wc_standard((string)($cal['country'] ?? $code), (int)$y); } }   // R62-003: a year nobody generated yet falls back to the country's public holidays
    return $c[$code][$y][$date] ?? null;
}
/** What a date is in a calendar: ['workday' => bool, 'normal' => float hours, 'off_type' => overtime|holiday, 'holiday' => name|null, 'rule' => row]. */
function wc_day(string $code, string $date): array {
    $dow = (int) date('N', strtotime($date)); $rule = wc_hours($code)[$dow] ?? null;
    $hol = wc_holiday($code, $date);
    if ($hol !== null) return ['workday' => false, 'normal' => 0.0, 'off_type' => 'holiday', 'holiday' => $hol, 'rule' => $rule];
    if (!$rule) return ['workday' => $dow < 6, 'normal' => $dow < 6 ? 8.0 : 0.0, 'off_type' => 'overtime', 'holiday' => null, 'rule' => null];   // R62-003: a calendar without weekday rules still has its weekend off
    return ['workday' => (bool)$rule['is_workday'], 'normal' => (float)$rule['normal_hours'], 'off_type' => $rule['off_type'], 'holiday' => null, 'rule' => $rule];
}
function wc_day_label(string $code, string $date): string {
    $d = wc_day($code, $date);
    if ($d['holiday']) return '🎉 ' . $d['holiday'];
    if (!$d['workday']) return t('wc.off_day') . ' → ' . t('ts.' . $d['off_type']);
    $r = $d['rule']; return ($r ? substr((string)$r['start_time'], 0, 5) . '–' . substr((string)$r['end_time'], 0, 5) . ' · ' : '') . t('wc.normal_h', ['n' => ts_fmt($d['normal'])]);
}

/** Reclassify timesheet lines [date, sub, type, hours, desc] by the calendar. Returns [$lines (with 6th element auto flag), $changedHours]. */
function wc_classify(string $code, array $lines): array {
    if (!wc_has()) return [array_map(fn($l) => $l + [5 => 0, 6 => null, 7 => null], $lines), 0.0];
    $out = []; $changed = 0.0; $used = [];                                           // normal hours already counted per date
    foreach ($lines as $l) {
        [$d, $sid, $ty, $h, $desc] = $l; $day = wc_day($code, $d);
        if (!$day['workday']) {                                                      // holiday / Sunday / Saturday: everything is off-day hours
            $want = $day['off_type'];
            if ($ty === 'normal' || ($want === 'holiday' && $ty === 'overtime')) { $changed += $h; $ty = $want; $out[] = [$d, $sid, $ty, $h, $desc, 1, $l[6] ?? null, $l[7] ?? null]; }
            else $out[] = [$d, $sid, $ty, $h, $desc, 0, $l[6] ?? null, $l[7] ?? null];
            continue;
        }
        if ($ty !== 'normal') { $out[] = [$d, $sid, $ty, $h, $desc, 0, $l[6] ?? null, $l[7] ?? null]; continue; }  // the engineer already marked it
        $left = max(0.0, $day['normal'] - ($used[$d] ?? 0.0));
        if ($h <= $left) { $used[$d] = ($used[$d] ?? 0.0) + $h; $out[] = [$d, $sid, 'normal', $h, $desc, 0, $l[6] ?? null, $l[7] ?? null]; continue; }
        if ($left > 0) { $out[] = [$d, $sid, 'normal', $left, $desc, 0, $l[6] ?? null, $l[7] ?? null]; $used[$d] = $day['normal']; }
        $ot = round(($h - $left) * 4) / 4; $changed += $ot;
        $out[] = [$d, $sid, 'overtime', $ot, $desc, 1, $l[6] ?? null, $l[7] ?? null];
    }
    return [$out, $changed];
}

/* ----------------------------------------------------------- holidays */

function wc_easter_gregorian(int $y): string {                      // Western Easter (Meeus / Jones / Butcher)
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mo = intdiv($h + $l - 7 * $m + 114, 31); $da = ($h + $l - 7 * $m + 114) % 31 + 1;
    return sprintf('%04d-%02d-%02d', $y, $mo, $da);
}
function wc_easter_orthodox(int $y): string {                       // Orthodox Easter (Meeus Julian) converted to Gregorian
    $a = $y % 4; $b = $y % 7; $c = $y % 19; $d = (19 * $c + 15) % 30; $e = (2 * $a + 4 * $b - $d + 34) % 7;
    $mo = intdiv($d + $e + 114, 31); $da = ($d + $e + 114) % 31 + 1;
    $julian = new DateTime(sprintf('%04d-%02d-%02d', $y, $mo, $da));
    $julian->modify('+13 days');                                    // Julian → Gregorian offset for 1900–2099
    return $julian->format('Y-m-d');
}
/** Standard public holidays of a country for a year: [date => name]. */
function wc_standard(string $country, int $y): array {
    $add = fn(string $base, int $days) => (new DateTime($base))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    switch (strtoupper($country)) {
        case 'GR': $e = wc_easter_orthodox($y);
            return ["$y-01-01" => 'New Year\'s Day', "$y-01-06" => 'Epiphany', $add($e, -48) => 'Clean Monday', "$y-03-25" => 'Independence Day', $add($e, -2) => 'Good Friday', $add($e, 1) => 'Easter Monday',
                    "$y-05-01" => 'Labour Day', $add($e, 50) => 'Whit Monday', "$y-08-15" => 'Dormition of the Theotokos', "$y-10-28" => 'Ochi Day', "$y-12-25" => 'Christmas Day', "$y-12-26" => 'Synaxis of the Theotokos'];
        case 'IT': $e = wc_easter_gregorian($y);
            return ["$y-01-01" => 'Capodanno', "$y-01-06" => 'Epifania', $add($e, 1) => 'Lunedì dell\'Angelo', "$y-04-25" => 'Festa della Liberazione', "$y-05-01" => 'Festa del Lavoro', "$y-06-02" => 'Festa della Repubblica',
                    "$y-08-15" => 'Ferragosto', "$y-11-01" => 'Ognissanti', "$y-12-08" => 'Immacolata Concezione', "$y-12-25" => 'Natale', "$y-12-26" => 'Santo Stefano'];
        case 'NL': $e = wc_easter_gregorian($y);
            return ["$y-01-01" => 'Nieuwjaarsdag', $add($e, -2) => 'Goede Vrijdag', $add($e, 1) => 'Tweede Paasdag', "$y-04-27" => 'Koningsdag', "$y-05-05" => 'Bevrijdingsdag', $add($e, 39) => 'Hemelvaartsdag', $add($e, 50) => 'Tweede Pinksterdag', "$y-12-25" => 'Eerste Kerstdag', "$y-12-26" => 'Tweede Kerstdag'];
        case 'DE': $e = wc_easter_gregorian($y);
            return ["$y-01-01" => 'Neujahr', $add($e, -2) => 'Karfreitag', $add($e, 1) => 'Ostermontag', "$y-05-01" => 'Tag der Arbeit', $add($e, 39) => 'Christi Himmelfahrt', $add($e, 50) => 'Pfingstmontag', "$y-10-03" => 'Tag der Deutschen Einheit', "$y-12-25" => '1. Weihnachtstag', "$y-12-26" => '2. Weihnachtstag'];
        default: return ["$y-01-01" => 'New Year\'s Day', "$y-12-25" => 'Christmas Day'];
    }
}
function wc_holidays(string $code, int $year): array { return all('SELECT * FROM work_holidays WHERE calendar_code = ? AND YEAR(hdate) = ? ORDER BY hdate', [$code, $year]); }
function wc_generate(string $code, int $year): int {
    require_cp_admin(); $cal = wc_calendars()[$code] ?? null; if (!$cal) throw new RuntimeException(t('err.notfound'));
    $n = 0;
    foreach (wc_standard((string)$cal['country'], $year) as $d => $name) { q('INSERT IGNORE INTO work_holidays (calendar_code, hdate, name, source) VALUES (?,?,?,?)', [$code, $d, $name, 'standard']); $n += (int) db()->query('SELECT ROW_COUNT()')->fetchColumn(); }
    return $n;
}
function wc_holiday_add(string $code, string $date, string $name): void {
    require_cp_admin(); if (!isset(wc_calendars()[$code])) throw new RuntimeException(t('err.notfound'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException(t('wc.need_date'));
    $name = mb_substr(trim($name), 0, 120); if ($name === '') throw new RuntimeException(t('wc.need_name'));
    q('INSERT INTO work_holidays (calendar_code, hdate, name, source) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), source = VALUES(source)', [$code, $date, $name, 'manual']);
}
function wc_holiday_delete(int $id): void { require_cp_admin(); q('DELETE FROM work_holidays WHERE id = ?', [$id]); }
function wc_hours_save(string $code): void {
    require_cp_admin(); if (!isset(wc_calendars()[$code])) throw new RuntimeException(t('err.notfound'));
    for ($d = 1; $d <= 7; $d++) {
        $on = !empty($_POST['wd'][$d]); $st = (string)($_POST['start'][$d] ?? ''); $en = (string)($_POST['end'][$d] ?? '');
        if ($on && (!preg_match('/^\d{2}:\d{2}$/', $st) || !preg_match('/^\d{2}:\d{2}$/', $en) || $en <= $st)) throw new RuntimeException(t('wc.bad_hours', ['d' => td_weekdays()[$d]]));
        $br = max(0, min(600, (int)($_POST['break'][$d] ?? 0))); $off = ($_POST['off'][$d] ?? '') === 'holiday' ? 'holiday' : 'overtime';
        q('INSERT INTO work_hours (calendar_code, dow, is_workday, start_time, end_time, break_min, off_type) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE is_workday = VALUES(is_workday), start_time = VALUES(start_time), end_time = VALUES(end_time), break_min = VALUES(break_min), off_type = VALUES(off_type)',
          [$code, $d, (int)$on, $on ? $st : null, $on ? $en : null, $on ? $br : 0, $off]);
    }
}
function wc_calendar_save(): void {
    require_cp_admin();
    $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['code'] ?? '')), 0, 8)); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $country = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['country'] ?? $code)), 0, 2)) ?: $code;
    if ($code === '' || $name === '') throw new RuntimeException(t('wc.need_code'));
    q('INSERT INTO work_calendars (code, name, country, sort_order) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), country = VALUES(country), is_active = 1', [$code, $name, $country, 10 + 10 * count(wc_calendars())]);
    if (!scalar('SELECT COUNT(*) FROM work_hours WHERE calendar_code = ?', [$code]))
        for ($d = 1; $d <= 7; $d++) q('INSERT INTO work_hours (calendar_code, dow, is_workday, start_time, end_time, break_min, off_type) VALUES (?,?,?,?,?,?,?)', [$code, $d, (int)($d <= 5), $d <= 5 ? '08:30' : null, $d <= 5 ? '17:00' : null, $d <= 5 ? 30 : 0, $d === 7 ? 'holiday' : 'overtime']);
}

/** Excel import of holidays: columns Calendar / Country (GR, IT), Date, Name. */
function wc_import(string $path, bool $dry): array {
    require_cp_admin();
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'added' => 0, 'updated' => 0, 'skipped' => [], 'warnings' => []];
    $cols = ['cal' => ['Calendar', 'Country', 'Code', 'Calendar code', 'Ημερολόγιο', 'Χώρα'], 'date' => ['Date', 'Holiday date', 'Ημερομηνία', 'Data'], 'name' => ['Name', 'Holiday', 'Holiday name', 'Description', 'Όνομα', 'Αργία', 'Festività']];
    $hdr = pm_import_header($rows, ['date' => $cols['date']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Date']));
    [$hi, $map] = $hdr; $map += xlsx_header_map($rows[$hi], array_diff_key($cols, ['date' => 1]));
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $cals = wc_calendars();
    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res, $cals) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $code = strtoupper($g($r, 'cal')) ?: 'GR'; $raw = $g($r, 'date'); $name = mb_substr($g($r, 'name'), 0, 120);
            if (!isset($cals[$code])) { $res['skipped'][] = [$line, t('wc.im_unknown_cal', ['v' => $code])]; continue; }
            $date = null;
            if (is_numeric($raw) && (float)$raw > 20000) $date = date('Y-m-d', (int)(((float)$raw - 25569) * 86400));            // Excel serial
            elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $raw, $m)) $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            elseif (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', $raw, $m)) $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            if (!$date || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) { $res['skipped'][] = [$line, t('wc.im_bad_date', ['v' => $raw])]; continue; }
            if ($name === '') $name = t('wc.holiday');
            $ex = scalar('SELECT COUNT(*) FROM work_holidays WHERE calendar_code = ? AND hdate = ?', [$code, $date]);
            q('INSERT INTO work_holidays (calendar_code, hdate, name, source) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE name = VALUES(name), source = VALUES(source)', [$code, $date, $name, 'import']);
            $ex ? $res['updated']++ : $res['added']++;
        }
        return $res;
    });
}

/* --------------------------------------------------------- disciplines */

/** Cost per hour type for a discipline name (from the disciplines table), or null. */
function wc_rates(): array {
    static $c = null;
    if ($c === null) { $c = []; try { foreach (all('SELECT name, rate_normal, rate_overtime, rate_holiday FROM disciplines') as $r) $c[$r['name']] = $r; } catch (Throwable $e) {} }
    return $c;
}
function wc_cost(string $discipline, float $n, float $o, float $h): ?float {
    $r = wc_rates()[$discipline] ?? null;
    if (function_exists('br_hour_cost')) return $n * br_hour_cost($r, 'normal') + $o * br_hour_cost($r, 'overtime') + $h * br_hour_cost($r, 'holiday');   // R40-002: branch mean cost when the discipline has none
    if (!$r || ($r['rate_normal'] === null && $r['rate_overtime'] === null && $r['rate_holiday'] === null)) return null;
    return $n * (float)$r['rate_normal'] + $o * (float)$r['rate_overtime'] + $h * (float)$r['rate_holiday'];
}

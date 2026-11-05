<?php
/** Utilization — hours on projects vs. without, per portal user and period.   GAS-CP-R83-001
 *  Capacity comes from the person's working calendar (wc_day), project hours from the timesheets (approved, invoiced and
 *  submitted), absences and internal work (office, warehouse, training, travel, other) from the planning board. The day is the
 *  unit: idle = capacity − absence − project − internal, never below zero, so overtime on one day never hides an empty one.
 *  Used by HR / management to evaluate the engineer and by the project managers to see how the people they plan are used. */
declare(strict_types=1);

const UT_ABSENT   = ['leave', 'sick', 'parental', 'strike'];
const UT_INTERNAL = ['office', 'warehouse', 'training', 'travel', 'other'];
const UT_TS_STATES = ['approved', 'invoiced', 'submitted'];

function ut_has(): bool { return function_exists('pl_has') && pl_has() && function_exists('wc_day'); }
/** HR, owner, management and administrators see everybody; a discipline / personal manager his people; everyone himself. */
function ut_can(): bool { return is_staff() && ut_has(); }
function ut_all(): bool { static $v = null; if ($v === null) $v = is_staff() && ((function_exists('hr_can') && hr_can()) || (function_exists('mgmt_can') && mgmt_can()) || is_cp_admin()); return $v; }
/** The user ids the current user may look at (null = everybody). */
function ut_scope(): ?array {
    if (ut_all()) return null;
    $me = (int)(current_user()['id'] ?? 0); $ids = [$me];
    if (function_exists('hr_is_manager') && hr_is_manager()) {
        foreach (all('SELECT id FROM customer_users WHERE is_active = 1 AND (manager_user_id = ? OR discipline_id IN (SELECT id FROM disciplines WHERE manager_user_id = ?))', [$me, $me]) as $r) $ids[] = (int)$r['id'];
    }
    return array_values(array_unique($ids));
}
function ut_can_view(int $uid): bool { $s = ut_scope(); return $s === null || in_array($uid, $s, true); }
/** People the current user may pick, in planning order. */
function ut_people(): array {
    $s = ut_scope(); $rows = pl_people(true);
    return $s === null ? $rows : array_values(array_filter($rows, fn($p) => in_array((int)$p['id'], $s, true)));
}

/** Timesheet hours per user / date / project (+ activity) in a period: [uid][date] => [[project_id, code, name, hours, activity, status], …] */
function ut_ts_hours(string $from, string $to, ?int $uid = null): array {
    $w = 'l.work_date BETWEEN ? AND ? AND t.status IN (' . implode(',', array_fill(0, count(UT_TS_STATES), '?')) . ')'; $a = array_merge([$from, $to], UT_TS_STATES);
    if ($uid) { $w .= ' AND t.user_id = ?'; $a[] = $uid; }
    $g = [];
    foreach (all("SELECT t.user_id, t.project_id, t.status, l.work_date, l.hours, l.hour_type, l.activity, p.project_code, p.name AS project_name, s.code AS sub_code
                  FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = l.subproject_id
                  WHERE {$w}", $a) as $r) $g[(int)$r['user_id']][$r['work_date']][] = $r;
    return $g;
}

/** The report of one user over a period: per day → months, projects, activities, totals. */
function ut_user(array $p, string $from, string $to, ?array $grid = null, ?array $ts = null): array {
    $uid = (int)$p['id']; $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR';
    $grid = $grid ?? pl_grid($from, $to, $uid); $ts = $ts ?? ut_ts_hours($from, $to, $uid);
    $hp = function_exists('hr_person_of_user') ? hr_person_of_user($uid) : null; $hire = $hp['hire_date'] ?? null; $left = $hp['left_date'] ?? null;   // capacity only while employed
    $z = fn() => ['cap' => 0.0, 'project' => 0.0, 'internal' => 0.0, 'absent' => 0.0, 'idle' => 0.0, 'overtime' => 0.0, 'days' => 0, 'idle_days' => 0, 'missing_days' => 0, 'pending' => 0.0];
    $R = ['id' => $uid, 'name' => (string)$p['full_name'], 'discipline' => (string)($p['discipline'] ?? ''), 'discipline_id' => (int)($p['discipline_id'] ?? 0), 'months' => [], 'projects' => [], 'activities' => [], 'internal' => [], 'absent' => [], 'tot' => $z()];
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $date = date('Y-m-d', $d); $m = substr($date, 0, 7); if (!isset($R['months'][$m])) $R['months'][$m] = $z();
        $day = wc_day($cal, $date); $cap = $day['workday'] && !($hire && $date < $hire) && !($left && $date > $left) ? (float)$day['normal'] : 0.0;
        $entries = $grid[$uid][$date] ?? []; $lines = $ts[$uid][$date] ?? [];
        $abs = 0.0; $int = 0.0; $plannedWork = false;
        $nWork = count(array_filter($entries, fn($x) => $x['kind'] === 'work')); $nOff = count($entries) - $nWork;
        foreach ($entries as $e) {
            $h = $e['hours'] !== null ? (float)$e['hours'] : ($cap > 0 ? $cap / max(1, $nOff ?: 1) : 0.0);
            if (in_array($e['kind'], UT_ABSENT, true)) { $abs += $h; $k = !empty($e['leave_type_id']) && function_exists('hr_type') && ($ht = hr_type((int)$e['leave_type_id'])) ? $ht['name'] : t('pl.k_' . $e['kind']); $R['absent'][$k] = ($R['absent'][$k] ?? 0.0) + $h; }
            elseif (in_array($e['kind'], UT_INTERNAL, true)) { $int += $h; $k = t('pl.k_' . $e['kind']); $R['internal'][$k] = ($R['internal'][$k] ?? 0.0) + $h; }
            elseif ($e['kind'] === 'work') $plannedWork = true;
        }
        $proj = 0.0; $pend = 0.0;
        foreach ($lines as $l) {
            $h = (float)$l['hours']; $proj += $h; if ($l['status'] === 'submitted') $pend += $h;
            $pk = (int)$l['project_id']; if (!isset($R['projects'][$pk])) $R['projects'][$pk] = ['id' => $pk, 'code' => (string)($l['project_code'] ?? ''), 'name' => (string)($l['project_name'] ?? ''), 'hours' => 0.0, 'days' => []];
            $R['projects'][$pk]['hours'] += $h; $R['projects'][$pk]['days'][$date] = true;
            $ak = (string)($l['activity'] ?: '-'); $R['activities'][$ak] = ($R['activities'][$ak] ?? 0.0) + $h;
        }
        $abs = min($abs, $cap); $int = min($int, max(0.0, $cap - $abs - $proj));   // the timesheet (actual) wins over a planned office / warehouse day
        $avail = max(0.0, $cap - $abs - $int); $idle = max(0.0, $avail - $proj); $ot = max(0.0, $proj - $avail);
        foreach ([&$R['months'][$m], &$R['tot']] as &$T) {
            $T['cap'] += $cap; $T['project'] += $proj; $T['internal'] += $int; $T['absent'] += $abs; $T['idle'] += $idle; $T['overtime'] += $ot; $T['pending'] += $pend;
            if ($cap > 0) { $T['days']++; if ($idle >= $cap - 0.01 && $abs <= 0 && $int <= 0) $T['idle_days']++; if ($plannedWork && $proj <= 0 && $abs < $cap) $T['missing_days']++; }
        } unset($T);
    }
    foreach ($R['projects'] as &$pr) { $pr['days'] = count($pr['days']); } unset($pr);
    uasort($R['projects'], fn($a, $b) => $b['hours'] <=> $a['hours']); arsort($R['activities']); arsort($R['internal']); arsort($R['absent']);
    return $R;
}
/** Utilization % of a bucket: project hours over the hours available for projects (capacity − absence − internal). */
function ut_pct(array $T): ?int { $av = $T['cap'] - $T['absent'] - $T['internal']; return $av > 0 ? (int) round(min($T['project'], $av) / $av * 100) : null; }
function ut_idle_pct(array $T): ?int { return $T['cap'] > 0 ? (int) round($T['idle'] / $T['cap'] * 100) : null; }
function ut_tone(?int $pct): string { if ($pct === null) return 'grey'; return $pct >= 80 ? 'green' : ($pct >= 60 ? 'amber' : 'red'); }

/** Team report: every visible person (optionally one discipline) over the period, with the totals only. */
function ut_team(string $from, string $to, ?int $did = null, bool $techOnly = false): array {
    $people = ut_people(); if ($did !== null) $people = array_values(array_filter($people, fn($p) => (int)($p['discipline_id'] ?? 0) === $did));
    if ($techOnly && function_exists('hr_technical_user_ids') && ($tech = hr_technical_user_ids()) !== null) $people = array_values(array_filter($people, fn($p) => in_array((int)$p['id'], $tech, true)));
    if (!$people) return [];
    $grid = pl_grid($from, $to); $ts = ut_ts_hours($from, $to);
    $out = []; foreach ($people as $p) { $r = ut_user($p, $from, $to, $grid, $ts); unset($r['months'], $r['activities'], $r['internal'], $r['absent']); $r['n_projects'] = count($r['projects']); unset($r['projects']); $out[] = $r; }
    return $out;
}
/** Period from the request: year (default this year), or from / to. */
function ut_period(): array {
    $y = (int)($_GET['year'] ?? 0); $f = (string)($_GET['from'] ?? ''); $t = (string)($_GET['to'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $t) && $t >= $f && strtotime($t) - strtotime($f) <= 400 * 86400) return [$f, $t, 0];
    if ($y < 2020 || $y > 2100) $y = (int) date('Y');
    return [$y . '-01-01', $y === (int) date('Y') ? date('Y-m-d') : $y . '-12-31', $y];
}

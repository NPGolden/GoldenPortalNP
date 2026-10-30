<?php
/**
 * Work packages (GAS-CP-R35-001) — the works of a sub-project assigned to engineers.
 *   A work package: W01 … per sub-project, title, discipline, working area (deck), planned hours, start / end,
 *   people (one lead), status Planned → Assigned → In progress → Done → Checked. Saving a package with dates and
 *   people writes the engineers' planning automatically (one plan entry per person and workday, hours split evenly);
 *   the planning grid, the weekly works forecast (Plan A areas of the week) and the timesheet (line → package) read
 *   from it, so actual hours land on the package and on the discipline budget.
 *   Workload: capacity per engineer from the working calendar, planned hours from the planning, open packages and
 *   the next free window — per discipline and per person (Work › Workload).
 */

const WP_STATUSES = ['planned', 'assigned', 'in_progress', 'done', 'checked', 'cancelled'];
const WP_OPEN = ['planned', 'assigned', 'in_progress'];

function wp_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'sp_work_people'")->fetch() && (bool) db()->query("SHOW COLUMNS FROM plan_entries LIKE 'work_id'")->fetch(); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function wp_status_label(string $s): string { return t('wp.s_' . $s); }
function wp_status_pill(string $s): string { return pill(['planned' => 'mute', 'assigned' => 'navy', 'in_progress' => 'gold', 'done' => 'green', 'checked' => 'green', 'cancelled' => 'bad'][$s] ?? 'mute', wp_status_label($s)); }
/** May the signed-in person create / assign / check packages of a project? Administrators, the project's PM, planning editors (managers). */
function wp_can_manage(int $pid): bool {
    if (!wp_has() || !is_staff()) return false;
    if (is_cp_admin()) return true;
    $u = current_user(); if ($pid && (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]) === (int)$u['id']) return true;
    if (wp_perm_has() && wp_user_can_works((int)$u['id'])) return true;                     // R36-005: the per-account flag
    return function_exists('pl_can_edit') && pl_can_edit();
}
/** customer_users.can_works exists (migration R36-005). */
function wp_perm_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW COLUMNS FROM customer_users LIKE 'can_works'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function wp_user_can_works(int $uid): bool { static $c = []; if (!isset($c[$uid])) $c[$uid] = (bool) scalar('SELECT can_works FROM customer_users WHERE id = ?', [$uid]); return $c[$uid]; }
function wp_people_options(): array { return all("SELECT cu.id, cu.full_name, cu.discipline_id, COALESCE(d.name, '') AS discipline FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 ORDER BY ISNULL(d.sort_order), d.sort_order, cu.full_name"); }

/* ------------------------------------------------------------------ read */
function wp_list(array $f = []): array {
    if (!wp_has()) return [];
    $w = ['1 = 1']; $b = [];
    if (!empty($f['id'])) { $w[] = 'w.id = ?'; $b[] = (int)$f['id']; }
    if (!empty($f['sub'])) { $w[] = 'w.subproject_id = ?'; $b[] = (int)$f['sub']; }
    if (!empty($f['project'])) { $w[] = 'w.project_id = ?'; $b[] = (int)$f['project']; }
    if (!empty($f['user'])) { $w[] = 'EXISTS (SELECT 1 FROM sp_work_people wp WHERE wp.work_id = w.id AND wp.user_id = ?)'; $b[] = (int)$f['user']; }
    if (!empty($f['discipline'])) { $w[] = 'w.discipline_id = ?'; $b[] = (int)$f['discipline']; }
    if (!empty($f['status'])) { if ($f['status'] === 'open') { $w[] = "w.status IN ('planned','assigned','in_progress')"; } elseif (in_array($f['status'], WP_STATUSES, true)) { $w[] = 'w.status = ?'; $b[] = $f['status']; } }
    if (!empty($f['from']) && !empty($f['to'])) { $w[] = '(w.start_date IS NULL OR w.start_date <= ?) AND (w.end_date IS NULL OR w.end_date >= ?)'; $b[] = $f['to']; $b[] = $f['from']; }
    $rows = all("SELECT w.*, s.code AS sub_code, s.name AS sub_name, p.project_code, p.name AS project_name, v.name AS vessel, d.name AS discipline, a.name AS area, lu.full_name AS lead_name,
                        (SELECT GROUP_CONCAT(cu.full_name ORDER BY cu.full_name SEPARATOR ', ') FROM sp_work_people wp JOIN customer_users cu ON cu.id = wp.user_id WHERE wp.work_id = w.id) AS people,
                        (SELECT COUNT(*) FROM sp_work_people wp WHERE wp.work_id = w.id) AS n_people,
                        (SELECT COALESCE(SUM(l.hours), 0) FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_id = w.id AND t.status IN ('approved','invoiced')) AS actual_h
                 FROM sp_works w JOIN subprojects s ON s.id = w.subproject_id JOIN projects p ON p.id = w.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN disciplines d ON d.id = w.discipline_id
                 LEFT JOIN vessel_areas a ON a.id = w.area_id LEFT JOIN customer_users lu ON lu.id = w.lead_user_id
                 WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(w.status,'in_progress','assigned','planned','done','checked','cancelled'), w.priority, w.start_date, w.wp_no", $b);
    return $rows;
}
function wp_get(int $id): ?array {
    if (!wp_has()) return null;
    $rows = wp_list(['id' => $id]); if (!$rows) return null;
    $x = $rows[0];
    $x['people_rows'] = all('SELECT wp.*, cu.full_name, d.name AS discipline, (SELECT COALESCE(SUM(l.hours),0) FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_id = wp.work_id AND t.user_id = wp.user_id AND t.status IN ("approved","invoiced")) AS actual_h FROM sp_work_people wp JOIN customer_users cu ON cu.id = wp.user_id LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE wp.work_id = ? ORDER BY cu.full_name', [$id]);
    $x['plan'] = all('SELECT e.plan_date, e.user_id, e.hours, cu.full_name FROM plan_entries e JOIN customer_users cu ON cu.id = e.user_id WHERE e.work_id = ? ORDER BY e.plan_date, cu.full_name', [$id]);
    $x['lines'] = all('SELECT l.work_date, l.hours, l.hour_type, l.description, t.user_name, t.status, t.id AS ts_id FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_id = ? ORDER BY l.work_date DESC', [$id]);
    return $x;
}
function wp_is_mine(array $w): bool { $u = current_user(); foreach ($w['people_rows'] ?? [] as $p) if ((int)$p['user_id'] === (int)($u['id'] ?? 0)) return true; return false; }
function wp_for_user(?int $uid = null, bool $openOnly = true): array { $uid = $uid ?: (int)(current_user()['id'] ?? 0); return wp_list(['user' => $uid] + ($openOnly ? ['status' => 'open'] : [])); }
/** Planned hours per discipline of a sub-project's open + done packages (for the budget tab). */
function wp_hours_by_discipline(int $sid): array {
    $o = []; if (!wp_has()) return $o;
    foreach (all("SELECT COALESCE(discipline_id, 0) d, SUM(planned_hours) h FROM sp_works WHERE subproject_id = ? AND status <> 'cancelled' GROUP BY d", [$sid]) as $r) $o[(int)$r['d']] = (float)$r['h'];
    return $o;
}

/* ------------------------------------------------------------------ write */
function wp_next_no(int $sid): string { return 'W' . sprintf('%02d', (int) scalar('SELECT COUNT(*) FROM sp_works WHERE subproject_id = ?', [$sid]) + 1); }
function wp_save_from_post(int $sid): int {
    $sp = load_subproject($sid); if (!$sp) throw new RuntimeException(t('err.notfound'));
    $pid = (int)$sp['project_id']; if (!wp_can_manage($pid)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $id = (int)($_POST['work_id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160); if ($title === '') throw new RuntimeException(t('wp.need_title'));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 4000) ?: null;
    $did = (int)($_POST['discipline_id'] ?? 0) ?: null; $aid = (int)($_POST['area_id'] ?? 0) ?: null;
    if ($aid && !scalar('SELECT COUNT(*) FROM vessel_areas WHERE id = ? AND vessel_id = ?', [$aid, (int)($sp['vessel_id'] ?? 0)])) $aid = null;
    $hours = max(0, (float) str_replace(',', '.', (string)($_POST['planned_hours'] ?? '0')));
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null;
    $from = $d('start_date'); $to = $d('end_date') ?: $from; if ($from && $to && $to < $from) $to = $from;
    $prio = in_array((int)($_POST['priority'] ?? 2), [1, 2, 3], true) ? (int)$_POST['priority'] : 2;
    $people = array_values(array_unique(array_map('intval', (array)($_POST['people'] ?? [])))); $people = array_values(array_filter($people));
    $lead = (int)($_POST['lead_user_id'] ?? 0); if ($lead && !in_array($lead, $people, true)) $people[] = $lead; if (!$lead && $people) $lead = $people[0];
    $auto = !empty($_POST['auto_plan']) ? 1 : 0;
    if ($id) {
        $old = one('SELECT * FROM sp_works WHERE id = ? AND subproject_id = ?', [$id, $sid]); if (!$old) throw new RuntimeException(t('err.notfound'));
        $status = $old['status']; if (in_array($status, ['planned', 'assigned'], true)) $status = $people ? 'assigned' : 'planned';
        q('UPDATE sp_works SET title=?, description=?, discipline_id=?, area_id=?, planned_hours=?, start_date=?, end_date=?, priority=?, lead_user_id=?, auto_plan=?, status=? WHERE id=?', [$title, $desc, $did, $aid, $hours, $from, $to, $prio, $lead ?: null, $auto, $status, $id]);
    } else {
        q('INSERT INTO sp_works (subproject_id, project_id, wp_no, title, description, discipline_id, area_id, planned_hours, start_date, end_date, status, priority, lead_user_id, auto_plan, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$sid, $pid, wp_next_no($sid), $title, $desc, $did, $aid, $hours, $from, $to, $people ? 'assigned' : 'planned', $prio, $lead ?: null, $auto, (int)$u['id']]);
        $id = (int) db()->lastInsertId(); $old = null;
    }
    $before = array_map('intval', array_column(all('SELECT user_id FROM sp_work_people WHERE work_id = ?', [$id]), 'user_id'));
    q('DELETE FROM sp_work_people WHERE work_id = ?' . ($people ? ' AND user_id NOT IN (' . implode(',', array_fill(0, count($people), '?')) . ')' : ''), array_merge([$id], $people));
    $per = $people ? round($hours / count($people), 2) : null;
    foreach ($people as $uid) q('INSERT INTO sp_work_people (work_id, user_id, hours_planned) VALUES (?,?,?) ON DUPLICATE KEY UPDATE hours_planned = VALUES(hours_planned)', [$id, $uid, $per]);
    foreach ($people as $uid) if (function_exists('tm_has') && tm_has()) q('INSERT IGNORE INTO sp_team (subproject_id, user_id, added_by) VALUES (?,?,?)', [$sid, $uid, (int)$u['id']]);
    wp_plan($id);
    if ($people && $from) { $none = []; foreach ($people as $uid) if (!scalar('SELECT COUNT(*) FROM plan_entries WHERE work_id = ? AND user_id = ?', [$id, $uid])) $none[] = (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$uid]); if ($none) flash('warn', t('wp.no_days', ['u' => implode(', ', $none)])); }
    $new = array_diff($people, $before);
    if ($new && function_exists('nf_push')) nf_push($new, 'wp:assigned', 'wp:' . $id, t('wp.nf_assigned', ['no' => wp_next_label($id), 't' => $title]), trim(($sp['project_code'] ?: '') . ' › ' . ($sp['code'] ?: '')) . ($from ? ' · ' . fmt_date($from) . ($to && $to !== $from ? ' – ' . fmt_date($to) : '') : '') . ' · ' . bg_h_safe($hours) . ' h', url('work', ['id' => $id]), (int)$u['id'], (string)$u['full_name']);
    return $id;
}
function wp_next_label(int $id): string { $r = one('SELECT w.wp_no, s.code FROM sp_works w JOIN subprojects s ON s.id = w.subproject_id WHERE w.id = ?', [$id]); return $r ? trim(($r['code'] ?: '') . ' ' . $r['wp_no']) : 'W'; }
function bg_h_safe(float $h): string { return function_exists('bg_h') ? bg_h($h) : rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.'); }
/** Write the planning of a package: one work entry per person and workday between start and end, hours split evenly. */
function wp_plan(int $id): int {
    $w = one('SELECT w.*, p.vessel_id FROM sp_works w JOIN projects p ON p.id = w.project_id WHERE w.id = ?', [$id]); if (!$w) return 0;
    q('DELETE FROM plan_entries WHERE work_id = ?', [$id]);
    if (!$w['auto_plan'] || !$w['start_date'] || in_array($w['status'], ['done', 'checked', 'cancelled'], true)) return 0;
    $people = all('SELECT user_id, hours_planned FROM sp_work_people WHERE work_id = ?', [$id]); if (!$people) return 0;
    $me = current_user(); $n = 0; $to = $w['end_date'] ?: $w['start_date'];
    foreach ($people as $pp) {
        $uid = (int)$pp['user_id']; $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $days = [];
        for ($d = strtotime($w['start_date']); $d <= strtotime($to); $d += 86400) { $date = date('Y-m-d', $d); if (function_exists('wc_day') && !wc_day($cal, $date)['workday']) continue; $days[] = $date; }
        if (!$days) $days = [$w['start_date']];
        $perDay = $pp['hours_planned'] !== null ? round((float)$pp['hours_planned'] / count($days) * 4) / 4 : null;
        foreach ($days as $date) {
            // a leave / sick / training day of the person stays as it is; the package is not planned on it
            if (scalar("SELECT COUNT(*) FROM plan_entries WHERE user_id = ? AND plan_date = ? AND kind <> 'work'", [$uid, $date])) continue;
            q('INSERT INTO plan_entries (user_id, plan_date, kind, project_id, subproject_id, work_id, vessel_id, hours, note, created_by) VALUES (?,?,\'work\',?,?,?,?,?,?,?)', [$uid, $date, (int)$w['project_id'], (int)$w['subproject_id'], $id, (int)($w['vessel_id'] ?? 0) ?: null, $perDay, null, (int)($me['id'] ?? 0) ?: null]);
            $n++;
        }
    }
    return $n;
}
function wp_set_status(int $id, string $st): void {
    $w = one('SELECT * FROM sp_works WHERE id = ?', [$id]); if (!$w || !in_array($st, WP_STATUSES, true)) throw new RuntimeException(t('err.notfound'));
    $u = current_user(); $mine = (bool) scalar('SELECT COUNT(*) FROM sp_work_people WHERE work_id = ? AND user_id = ?', [$id, (int)$u['id']]);
    $mgr = wp_can_manage((int)$w['project_id']);
    if (in_array($st, ['checked', 'cancelled', 'planned', 'assigned'], true) && !$mgr) throw new RuntimeException(t('err.noaccess'));
    if (in_array($st, ['in_progress', 'done'], true) && !$mgr && !$mine) throw new RuntimeException(t('err.noaccess'));
    $sets = ['status = ?']; $vals = [$st];
    if ($st === 'done') { $sets[] = 'done_at = NOW()'; }
    if ($st === 'checked') { $sets[] = 'checked_by = ?'; $vals[] = (int)$u['id']; $sets[] = 'checked_at = NOW()'; }
    $vals[] = $id; q('UPDATE sp_works SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    if (in_array($st, ['done', 'checked', 'cancelled'], true)) q('DELETE FROM plan_entries WHERE work_id = ? AND plan_date > CURDATE()', [$id]);   // free the remaining planned days
    elseif ($st === 'assigned' || $st === 'in_progress') wp_plan($id);
    if ($st === 'done' && function_exists('nf_push')) { $pm = (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [(int)$w['project_id']]); $to = array_values(array_unique(array_filter(array_merge(function_exists('nf_admins') ? nf_admins() : [], [$pm])))); nf_push($to, 'wp:done', 'wp:' . $id . ':done', t('wp.nf_done', ['no' => wp_next_label($id), 't' => $w['title'], 'u' => (string)$u['full_name']]), null, url('work', ['id' => $id]), (int)$u['id'], (string)$u['full_name']); }
}
function wp_ack(int $id): void { $u = current_user(); q('UPDATE sp_work_people SET acknowledged_at = NOW() WHERE work_id = ? AND user_id = ?', [$id, (int)$u['id']]); }
function wp_delete(int $id): void { $w = one('SELECT * FROM sp_works WHERE id = ?', [$id]); if (!$w || !wp_can_manage((int)$w['project_id'])) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM plan_entries WHERE work_id = ?', [$id]); q('DELETE FROM sp_work_people WHERE work_id = ?', [$id]); q('UPDATE timesheet_lines SET work_id = NULL WHERE work_id = ?', [$id]); q('DELETE FROM sp_works WHERE id = ?', [$id]); }

/* ------------------------------------------------------------------ workload */
/** Capacity (normal hours) and planned load of every engineer between two dates, per ISO week. */
function wp_workload(string $from, string $to, ?int $disciplineId = null): array {
    $people = function_exists('pl_people') ? pl_people(true) : wp_people_options();
    $weeks = []; for ($d = strtotime('monday this week', strtotime($from)); $d <= strtotime($to); $d += 7 * 86400) $weeks[] = date('o-\WW', $d);
    $out = []; $today = date('Y-m-d'); $horizonEnd = date('Y-m-d', strtotime($today . ' +56 days'));
    $grid = function_exists('pl_grid') ? pl_grid(min($from, $today), max($to, $horizonEnd)) : [];
    foreach ($people as $p) {
        $uid = (int)$p['id']; $did = (int)($p['discipline_id'] ?? 0);
        if ($disciplineId !== null && $did !== $disciplineId) continue;
        $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR';
        $R = ['id' => $uid, 'name' => (string)$p['full_name'], 'discipline' => (string)($p['discipline'] ?? ''), 'discipline_id' => $did, 'weeks' => [], 'cap' => 0.0, 'load' => 0.0, 'off' => 0.0, 'projects' => [], 'next_free' => null, 'free_days' => 0, 'open_works' => 0, 'open_hours' => 0.0];
        foreach ($weeks as $wk) $R['weeks'][$wk] = ['cap' => 0.0, 'load' => 0.0, 'off' => 0.0];
        for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
            $date = date('Y-m-d', $d); $day = function_exists('wc_day') ? wc_day($cal, $date) : ['workday' => (int) date('N', $d) < 6, 'normal' => 8.0];
            if (!$day['workday']) continue;
            $wk = date('o-\WW', $d); $cap = (float)$day['normal']; $R['weeks'][$wk]['cap'] += $cap; $R['cap'] += $cap;
            $entries = $grid[$uid][$date] ?? []; $load = 0.0; $off = 0.0;
            foreach ($entries as $e) { if ($e['kind'] === 'work') { $load += $e['hours'] !== null ? (float)$e['hours'] : $cap / max(1, count(array_filter($entries, fn($x) => $x['kind'] === 'work'))); $k = (int)($e['subproject_id'] ?? 0) ? 's' . (int)$e['subproject_id'] : 'p' . (int)($e['project_id'] ?? 0); if (!isset($R['projects'][$k])) $R['projects'][$k] = ['n' => 0, 'project_id' => (int)($e['project_id'] ?? 0), 'subproject_id' => (int)($e['subproject_id'] ?? 0) ?: null, 'label' => trim((string)($e['vessel'] ?: $e['project_code'] ?: '—') . ((string)($e['sub_code'] ?? '') !== '' ? ' ' . $e['sub_code'] : '')), 'title' => trim((string)($e['project_code'] ?? '') . ' ' . (string)($e['project_name'] ?? '') . ((string)($e['sub_name'] ?? '') !== '' ? ' › ' . $e['sub_name'] : ''))]; $R['projects'][$k]['n']++; } else $off += $cap; }
            $R['weeks'][$wk]['load'] += min($load, $cap * 1.5); $R['weeks'][$wk]['off'] += $off; $R['load'] += $load; $R['off'] += $off;
        }
        // next free window: first workday from today with no entry, and free days in the next 8 weeks
        $streak = 0; $first = null; $free = 0;
        for ($d = strtotime($today); $d <= strtotime($horizonEnd); $d += 86400) {
            $date = date('Y-m-d', $d); $day = function_exists('wc_day') ? wc_day($cal, $date) : ['workday' => (int) date('N', $d) < 6];
            if (!$day['workday']) continue;
            if (empty($grid[$uid][$date])) { $free++; if ($first === null) $first = $date; }
        }
        $R['next_free'] = $first; $R['free_days'] = $free;
        $R['pct'] = $R['cap'] > 0 ? (int) round($R['load'] / $R['cap'] * 100) : 0;
        uasort($R['projects'], fn($a, $b) => $b['n'] <=> $a['n']);
        $out[$uid] = $R;
    }
    foreach (all("SELECT wp.user_id, COUNT(*) n, COALESCE(SUM(GREATEST(COALESCE(wp.hours_planned, 0) - (SELECT COALESCE(SUM(l.hours),0) FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_id = wp.work_id AND t.user_id = wp.user_id AND t.status IN ('approved','invoiced')), 0)), 0) h FROM sp_work_people wp JOIN sp_works w ON w.id = wp.work_id WHERE w.status IN ('planned','assigned','in_progress') GROUP BY wp.user_id") as $r) if (isset($out[(int)$r['user_id']])) { $out[(int)$r['user_id']]['open_works'] = (int)$r['n']; $out[(int)$r['user_id']]['open_hours'] = (float)$r['h']; }
    return ['weeks' => $weeks, 'people' => $out];
}
/** Short availability hint for a person (used in the assignment form): "62 % · free from 03.11". */
function wp_avail_hint(array $R): string { return $R['pct'] . ' % · ' . ($R['next_free'] ? t('wp.free_from', ['d' => fmt_date($R['next_free'])]) : t('wp.no_free')); }

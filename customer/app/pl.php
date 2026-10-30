<?php
/**
 * Planning board — engineers × days (option 1 of the planning proposal).                        GAS-CP-R26-001
 *
 * One row per engineer and day and assignment: a sub-project (or a project, or only a vessel for cells
 * imported from the Excel matrix), or a status (leave, sick, parental, strike, office, warehouse, training,
 * travel, other). An engineer may carry several assignments on the same day (two vessels, two sub-projects).
 * A work assignment adds the engineer to the sub-project's team (R25-001) so the sub-project is offered in his
 * timesheet, FSR and ISR forms; a car and a note can travel with the assignment, and the editor shows the
 * open items of the previous service reports of the same sub-project.
 */
declare(strict_types=1);

const PL_KINDS = ['work', 'leave', 'sick', 'parental', 'strike', 'office', 'warehouse', 'training', 'travel', 'other'];
const PL_TONES = ['leave' => 'l', 'sick' => 's', 'parental' => 'l', 'strike' => 's', 'office' => 'o', 'warehouse' => 'o', 'training' => 'o', 'travel' => 'o', 'other' => 'o'];
/** Status codes understood by the Excel import (upper-cased, Greek and English). */
const PL_CODES = ['ADEIA' => 'leave', 'ΑΔΕΙΑ' => 'leave', 'LEAVE' => 'leave', 'HOLIDAY' => 'leave', 'VACATION' => 'leave', 'SICK' => 'sick', 'ΑΣΘΕΝΕΙΑ' => 'sick', 'PARENTAL' => 'parental', 'APERGIA' => 'strike', 'ΑΠΕΡΓΙΑ' => 'strike', 'STRIKE' => 'strike',
                  'OFFICE' => 'office', 'GOLDEN' => 'office', 'ΓΡΑΦΕΙΟ' => 'office', 'WAREHOUSE' => 'warehouse', 'ΑΠΟΘΗΚΗ' => 'warehouse', 'TRAINING' => 'training', 'TRAVEL' => 'travel', 'TAXIDI' => 'travel'];

function pl_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'plan_entries'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function pl_kinds(): array { $o = []; foreach (PL_KINDS as $k) $o[$k] = t('pl.k_' . $k); return $o; }
/** Who may plan: administrators, accounts with the "Project Manager" Golden A/S role, and the PM of at least one active project. */
function pl_can_edit(?array $u = null): bool {
    $u = $u ?? current_user(); if (!$u || !is_staff()) return false;
    if (function_exists('emp_only') && emp_only()) return false;   // R56-002: employee scope — read-only own program
    if (is_cp_admin() || (function_exists('hr_only') && hr_only())) return true;   // R51-001: HR edits the board (absences only, see pl_assign)
    static $c = [];
    $k = (int)$u['id'];
    if (!isset($c[$k])) $c[$k] = (bool) scalar("SELECT (SELECT COUNT(*) FROM projects WHERE pm_user_id = ? AND status = 'active') + (SELECT COUNT(*) FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.id = ? AND LOWER(r.name) LIKE '%project manager%')", [$k, $k]);
    return $c[$k];
}
function pl_cars(bool $activeOnly = true): array { return pl_has() ? all('SELECT * FROM plan_cars' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name') : []; }
/** Disciplines that take part in the planning (Settings › Disciplines): ids, plus 0 for "engineers without a discipline"; [] = everybody. */
function pl_disciplines(): array {
    $v = function_exists('cp_setting') ? (string) cp_setting('pl_disciplines', '') : '';
    return $v === '' ? [] : array_values(array_unique(array_map('intval', array_filter(explode(',', $v), fn($x) => $x !== ''))));
}
function pl_disciplines_save(array $ids): void { require_cp_admin(); cp_setting_set('pl_disciplines', implode(',', array_map('intval', $ids))); }
/** Engineers on the board, grouped by discipline (crew); $all ignores the discipline setting. */
function pl_people(bool $all = false): array {
    $sel = $all ? [] : pl_disciplines(); $w = ''; $a = [];
    if ($sel) { $in = array_values(array_filter($sel, fn($x) => $x > 0)); $parts = []; if ($in) { $parts[] = 'cu.discipline_id IN (' . implode(',', array_fill(0, count($in), '?')) . ')'; array_push($a, ...$in); } if (in_array(0, $sel, true)) $parts[] = 'cu.discipline_id IS NULL'; $w = ' AND (' . implode(' OR ', $parts) . ')'; }
    if (function_exists('emp_only') && emp_only()) { $w .= ' AND cu.id = ' . (int)(current_user()['id'] ?? 0); }   // R56-002: employee scope — only himself
    if (!$all && function_exists('hr_technical_user_ids') && !(function_exists('hr_can') && hr_can()) && ($tech = hr_technical_user_ids()) !== null) { $w .= ' AND cu.id IN (' . ($tech ? implode(',', array_map('intval', $tech)) : '0') . ')'; }   // R54-001: PMs see technical staff only; HR sees everybody
    return all("SELECT cu.id, cu.full_name, cu.role, cu.work_calendar, cu.discipline_id, COALESCE(d.name, '') AS discipline, d.sort_order AS dso FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id
                WHERE cu.role IN ('staff','admin') AND cu.is_active = 1{$w} ORDER BY ISNULL(d.sort_order), d.sort_order, d.name, cu.full_name", $a);
}
/** Entries of a date range, keyed [user_id][date] => [entries]. */
function pl_grid(string $from, string $to, ?int $userId = null): array {
    if (!pl_has()) return [];
    $w = 'e.plan_date BETWEEN ? AND ?'; $a = [$from, $to];
    if ($userId) { $w .= ' AND e.user_id = ?'; $a[] = $userId; }
    $g = [];
    $wpj = function_exists('wp_has') && wp_has() ? ', w.wp_no, w.title AS work_title' : ', NULL AS wp_no, NULL AS work_title'; $wpl = function_exists('wp_has') && wp_has() ? ' LEFT JOIN sp_works w ON w.id = e.work_id' : '';
    foreach (all("SELECT e.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, COALESCE(v.name, pv.name) AS vessel, c.name AS car{$wpj}
                  FROM plan_entries e LEFT JOIN projects p ON p.id = e.project_id LEFT JOIN subprojects s ON s.id = e.subproject_id LEFT JOIN vessels v ON v.id = e.vessel_id LEFT JOIN vessels pv ON pv.id = p.vessel_id LEFT JOIN plan_cars c ON c.id = e.car_id{$wpl}
                  WHERE {$w} ORDER BY e.plan_date, e.id", $a) as $r) $g[(int)$r['user_id']][$r['plan_date']][] = $r;
    return $g;
}
/** Short chip label of an entry: "AMBROSIA Y.02" / "Leave". */
function pl_label(array $e, bool $long = false): string {
    if ($e['kind'] !== 'work') { if (!empty($e['leave_type_id']) && function_exists('hr_type') && ($ht = hr_type((int)$e['leave_type_id'])) && !$ht['builtin']) return $long ? $ht['name'] : $ht['code']; return $e['kind'] === 'other' && !empty($e['note']) ? (string)$e['note'] : t('pl.k_' . $e['kind']); }   // R51-001
    $v = (string)($e['vessel'] ?? ''); $sub = (string)($e['sub_code'] ?? '');
    $l = trim(($v !== '' ? $v : (string)($e['project_code'] ?? '')) . ($sub !== '' ? ' ' . $sub : ''));
    if ($long) $l .= ($e['sub_name'] ?? '') ? ' · ' . $e['sub_name'] : (($e['project_name'] ?? '') && $v !== '' ? ' · ' . $e['project_code'] : '');
    if (!empty($e['wp_no'])) $l .= ' · ' . $e['wp_no'] . ($long && !empty($e['work_title']) ? ' ' . $e['work_title'] : '');
    return $l ?: t('pl.k_work');
}
/** Stable colour class per vessel/project so one vessel keeps one colour across the board. */
function pl_tone(array $e): string {
    if ($e['kind'] !== 'work') return PL_TONES[$e['kind']] ?? 'o';
    $key = (string)($e['vessel'] ?: $e['project_code'] ?: 'x');
    return 'v' . (crc32($key) % 8);
}

/* ------------------------------------------------------------ editing */

/** Create entries for a person over a date range (weekends / holidays skipped unless $all). Replaces the person's
 *  entries of the same kind+target on those days when $replace; returns the number written. */
function pl_assign(array $in): int {
    require_staff(); if (!pl_can_edit()) throw new RuntimeException(t('err.noaccess'));
    $uid = (int)$in['user_id']; if (!scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$uid])) throw new RuntimeException(t('err.notfound'));
    $from = $in['from']; $to = $in['to'] ?: $from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new RuntimeException(t('pl.bad_dates'));
    if ((strtotime($to) - strtotime($from)) / 86400 > 370) throw new RuntimeException(t('pl.bad_dates'));
    $kind = in_array($in['kind'] ?? '', PL_KINDS, true) ? $in['kind'] : 'work';
    if (hr_only() && $kind === 'work') throw new RuntimeException(t('hr.no_work'));   // R51-001: HR never assigns project work
    $pid = (int)($in['project_id'] ?? 0) ?: null; $sid = (int)($in['subproject_id'] ?? 0) ?: null; $vid = null;
    if ($kind === 'work') {
        if ($sid) { $row = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$row) throw new RuntimeException(t('err.notfound')); $pid = (int)$row['project_id']; }
        if (!$pid) throw new RuntimeException(t('pl.need_project'));
        $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]) ?: null;
    } else { $pid = null; $sid = null; }
    $car = (int)($in['car_id'] ?? 0) ?: null; if ($car && !scalar('SELECT COUNT(*) FROM plan_cars WHERE id = ?', [$car])) $car = null;
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 500) ?: null;
    [$ltype, $reason] = function_exists('hr_assign_check') ? hr_assign_check($kind, $in) : [null, null]; $hrCols = function_exists('hr_has') && hr_has();   // R51-001: leave type + reason
    $all = !empty($in['all_days']); $replace = !empty($in['replace']);
    $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR';
    $me = current_user(); $n = 0;
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) {
        $date = date('Y-m-d', $d);
        if (!$all && function_exists('wc_day') && !wc_day($cal, $date)['workday']) continue;
        if ($replace) q('DELETE FROM plan_entries WHERE user_id = ? AND plan_date = ?', [$uid, $date]);
        else q('DELETE FROM plan_entries WHERE user_id = ? AND plan_date = ? AND kind = ? AND COALESCE(project_id, 0) = ? AND COALESCE(subproject_id, 0) = ?', [$uid, $date, $kind, (int)$pid, (int)$sid]);
        if ($hrCols) q('INSERT INTO plan_entries (user_id, plan_date, kind, project_id, subproject_id, vessel_id, car_id, note, created_by, leave_type_id, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$uid, $date, $kind, $pid, $sid, $vid, $car, $note, (int)($me['id'] ?? 0) ?: null, $ltype, $reason]);
        else q('INSERT INTO plan_entries (user_id, plan_date, kind, project_id, subproject_id, vessel_id, car_id, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$uid, $date, $kind, $pid, $sid, $vid, $car, $note, (int)($me['id'] ?? 0) ?: null]);
        $n++;
    }
    if ($kind === 'work' && $sid && function_exists('tm_has') && tm_has()) q('INSERT IGNORE INTO sp_team (subproject_id, user_id, added_by) VALUES (?,?,?)', [$sid, $uid, (int)($me['id'] ?? 0) ?: null]);   // the plan feeds the team
    return $n;
}
function pl_delete(int $id): void {
    require_staff(); if (!pl_can_edit()) throw new RuntimeException(t('err.noaccess'));
    if (hr_only() && (string) scalar('SELECT kind FROM plan_entries WHERE id = ?', [$id]) === 'work') throw new RuntimeException(t('hr.no_work'));   // R51-001
    q('DELETE FROM plan_entries WHERE id = ?', [$id]);
}
/** R35-004: remove every planning entry of a work package (and stop the package from rewriting them). */
function pl_delete_work(int $workId): int {
    require_staff(); if (!pl_can_edit() || hr_only()) throw new RuntimeException(t('err.noaccess'));
    $n = (int) scalar('SELECT COUNT(*) FROM plan_entries WHERE work_id = ?', [$workId]);
    q('DELETE FROM plan_entries WHERE work_id = ?', [$workId]);
    try { q('UPDATE sp_works SET auto_plan = 0 WHERE id = ?', [$workId]); } catch (Throwable $e) {}
    return $n;
}
/** Clear a person's days in a range. */
function pl_clear(int $uid, string $from, string $to): int {
    require_staff(); if (!pl_can_edit()) throw new RuntimeException(t('err.noaccess'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new RuntimeException(t('pl.bad_dates'));
    $w = hr_only() ? " AND kind <> 'work'" : '';   // R51-001: HR clears absences only
    $n = (int) scalar('SELECT COUNT(*) FROM plan_entries WHERE user_id = ? AND plan_date BETWEEN ? AND ?' . $w, [$uid, $from, $to]);
    q('DELETE FROM plan_entries WHERE user_id = ? AND plan_date BETWEEN ? AND ?' . $w, [$uid, $from, $to]);
    return $n;
}
function pl_car_save(): void {
    require_cp_admin();
    $id = (int)($_POST['id'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60); if ($name === '') throw new RuntimeException(t('pl.car_need_name'));
    $v = [$name, mb_substr(trim((string)($_POST['plate'] ?? '')), 0, 20) ?: null, mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 200) ?: null, (int)!empty($_POST['is_active']), (int)($_POST['sort_order'] ?? 10)];
    if ($id) q('UPDATE plan_cars SET name=?, plate=?, notes=?, is_active=?, sort_order=? WHERE id = ?', [...$v, $id]);
    else q('INSERT INTO plan_cars (name, plate, notes, is_active, sort_order) VALUES (?,?,?,?,?)', $v);
}
function pl_car_delete(int $id): void { require_cp_admin(); q('UPDATE plan_entries SET car_id = NULL WHERE car_id = ?', [$id]); q('DELETE FROM plan_cars WHERE id = ?', [$id]); }

/* ------------------------------------------------------------ open items of previous reports */

/** Open issues of the FSR tasks and open corrective actions of the ISRs of a sub-project (or project). */
function pl_open_items(?int $pid, ?int $sid, int $limit = 12): array {
    $out = [];
    if (!$pid && !$sid) return $out;
    $w = $sid ? 'r.subproject_id = ?' : 'r.project_id = ?'; $a = [$sid ?: $pid];
    if (function_exists('iss_has') && iss_has()) {                                                     // R29-001: the register replaces the raw report text
        foreach (iss_list($pid, $sid, ['status' => 'open_all']) as $i) $out[] = ['kind' => $i['source'] === 'fsr' ? 'fsr' : 'iss', 'ref' => trim((string)($i['issue_no'] ?? '') . ' ' . ($i['source'] === 'fsr' ? (string)$i['report_no'] : '')) ?: t('iss.src_manual'), 'date' => $i['raised_on'], 'text' => $i['title'] . ($i['due_on'] ? ' (' . t('iss.due') . ' ' . fmt_date($i['due_on']) . ')' : ''), 'url' => url('subproject', ['id' => (int)$i['subproject_id'], 'tab' => 'issues'])];
    } else
    try {
        foreach (all("SELECT r.id, r.report_no, r.report_date, w.seq, w.open_issues FROM fsr_works w JOIN fsr_reports r ON r.id = w.report_id WHERE {$w} AND w.open_issues IS NOT NULL AND w.open_issues <> '' AND r.status <> 'draft' ORDER BY r.report_date DESC, w.seq LIMIT {$limit}", $a) as $x)
            $out[] = ['kind' => 'fsr', 'ref' => $x['report_no'], 'date' => $x['report_date'], 'text' => $x['open_issues'], 'url' => url('fsr', ['id' => $x['id']])];
    } catch (Throwable $e) {}
    try {
        if (function_exists('isr_has') && isr_has()) foreach (all("SELECT r.id, r.report_no, r.report_date, i.system_name, i.actions, i.risk_level FROM isr_items i JOIN isr_reports r ON r.id = i.report_id WHERE {$w} AND i.actions IS NOT NULL AND i.actions <> '' AND r.status = 'issued' ORDER BY r.report_date DESC, i.seq LIMIT {$limit}", $a) as $x)
            $out[] = ['kind' => 'isr', 'ref' => $x['report_no'] . ' · ' . $x['system_name'], 'date' => $x['report_date'], 'text' => $x['actions'], 'url' => url('isr', ['id' => $x['id']]), 'risk' => $x['risk_level']];
    } catch (Throwable $e) {}
    return array_slice($out, 0, $limit);
}

/* ------------------------------------------------------------ counters */

/** Per-person counters over a range: work days, leave, sick, other; vessels visited. */
function pl_counters(array $days): array {
    $c = ['work' => 0, 'leave' => 0, 'sick' => 0, 'other' => 0, 'vessels' => []];
    foreach ($days as $date => $es) {
        $kinds = array_unique(array_column($es, 'kind'));
        if (in_array('work', $kinds, true)) { $c['work']++; foreach ($es as $e) if ($e['kind'] === 'work' && $e['vessel']) $c['vessels'][$e['vessel']] = true; }
        elseif (in_array('leave', $kinds, true) || in_array('parental', $kinds, true)) $c['leave']++;
        elseif (in_array('sick', $kinds, true)) $c['sick']++;
        else $c['other']++;
    }
    $c['vessels'] = array_keys($c['vessels']);
    return $c;
}

/* ------------------------------------------------------------ Excel import / export */

/** Import the yearly matrix (names in column B, dates in row 3, cells = vessel / status code). Unknown names and vessels are reported. */
function pl_import(string $path, bool $dry, bool $replace): array {
    require_staff(); if (!pl_can_edit()) throw new RuntimeException(t('err.noaccess'));
    if (!pl_has()) throw new RuntimeException(t('pl.need_db'));
    require_once __DIR__ . '/xlsx.php';
    $rows = xlsx_rows($path);
    $res = ['rows' => 0, 'entries' => 0, 'people' => 0, 'skipped' => [], 'warnings' => [], 'unknown_names' => [], 'unknown_codes' => []];
    // the date row: first row with at least 20 date-like cells
    $dateRow = null; $dates = [];
    foreach ($rows as $i => $r) {
        $d = []; foreach ($r as $ci => $v) { $dt = pl_cell_date($v); if ($dt) $d[$ci] = $dt; }
        if (count($d) >= 20) { $dateRow = $i; $dates = $d; break; }
    }
    if ($dateRow === null) throw new RuntimeException(t('pl.im_no_dates'));
    $people = []; foreach (pl_people(true) as $p) $people[pl_norm($p['full_name'])] = (int)$p['id'];
    $onBoard = array_map('intval', array_column(pl_people(), 'id'));
    $vessels = []; foreach (all('SELECT id, name FROM vessels') as $v) $vessels[pl_norm($v['name'])] = (int)$v['id'];
    if (function_exists('vm_has') && vm_has()) foreach (all('SELECT vessel_id, alias FROM vessel_aliases') as $a) $vessels[pl_norm($a['alias'])] = $vessels[pl_norm($a['alias'])] ?? (int)$a['vessel_id'];
    $projects = []; foreach (all("SELECT id, project_code, vessel_id FROM projects WHERE project_code <> ''") as $p) $projects[pl_norm($p['project_code'])] = ['id' => (int)$p['id'], 'vessel_id' => (int)$p['vessel_id'] ?: null];   // R30-008: project codes from the template
    $codes = PL_CODES; foreach (['leave', 'sick', 'parental', 'strike', 'office', 'warehouse', 'training', 'travel'] as $k) $codes[mb_strtoupper(t('pl.k_' . $k))] = $k;   // the labels the template offers, in the user's language
    $cars = []; foreach (pl_cars(false) as $c) { $cars[pl_norm($c['name'])] = (int)$c['id']; if ($c['plate']) $cars[pl_norm($c['plate'])] = (int)$c['id']; $cars[pl_norm($c['name'] . ' ' . $c['plate'])] = (int)$c['id']; }
    $projOf = function (int $vid): ?array { static $c = []; if (!isset($c[$vid])) $c[$vid] = one("SELECT id FROM projects WHERE vessel_id = ? AND status = 'active' ORDER BY updated_at DESC LIMIT 1", [$vid]) ?: one('SELECT id FROM projects WHERE vessel_id = ? ORDER BY updated_at DESC LIMIT 1', [$vid]); return $c[$vid]; };
    $me = current_user();
    $hr = hr_only(); if ($hr) $res['skipped_work'] = 0;   // R51-001: HR imports absences only
    return pm_import_tx($dry, function () use ($rows, $dateRow, $dates, $people, $vessels, $projects, $cars, $codes, $projOf, $replace, &$res, $me, $onBoard, $hr) {
        $seen = [];
        foreach (array_slice($rows, $dateRow + 1, null, true) as $i => $r) {
            $name = trim((string)($r[1] ?? '')); if ($name === '') continue;
            $res['rows']++; $line = $i + 1;
            $uid = $people[pl_norm($name)] ?? pl_match_name($name, $people);
            if (!$uid) { $res['unknown_names'][$name] = ($res['unknown_names'][$name] ?? 0) + 1; $res['skipped'][] = [$line, t('pl.im_unknown_name', ['v' => $name])]; continue; }
            if (!in_array($uid, $onBoard, true)) { $res['warnings'][] = [$line, t('pl.im_not_on_board', ['v' => $name])]; continue; }
            if (!isset($seen[$uid])) { $seen[$uid] = true; $res['people']++; if ($replace && $dates) q('DELETE FROM plan_entries WHERE user_id = ? AND plan_date BETWEEN ? AND ?' . ($hr ? " AND kind <> 'work'" : ''), [$uid, min($dates), max($dates)]); }
            foreach ($dates as $ci => $date) {
                $cell = trim((string)($r[$ci] ?? '')); if ($cell === '' || $cell === '-' || is_numeric($cell)) continue;
                $carId = null; $firstWork = null;
                foreach (preg_split('/\s*[\/+]\s*/', $cell) as $v) {                                           // "MIZU / BASH IV" = two assignments that day; "MIZU + Car 1" = with a car
                    $v = trim($v); if ($v === '') continue;
                    $code = mb_strtoupper($v);
                    if (isset($cars[pl_norm($v)])) { $carId = $cars[pl_norm($v)]; continue; }
                    if (isset($codes[$code])) { q('INSERT INTO plan_entries (user_id, plan_date, kind, created_by) VALUES (?,?,?,?)', [$uid, $date, $codes[$code], (int)($me['id'] ?? 0) ?: null]); $res['entries']++; continue; }
                    if ($hr) { $res['skipped_work']++; continue; }   // R51-001: vessels / projects / cars are not HR's to plan
                    if (isset($projects[pl_norm($v)])) {                                                          // a project code from the template → its project and vessel
                        $pj = $projects[pl_norm($v)];
                        q('INSERT INTO plan_entries (user_id, plan_date, kind, project_id, vessel_id, created_by) VALUES (?,?,?,?,?,?)', [$uid, $date, 'work', $pj['id'], $pj['vessel_id'], (int)($me['id'] ?? 0) ?: null]); $res['entries']++;
                        $firstWork = $firstWork ?? (int) db()->lastInsertId(); continue;
                    }
                    $vid = $vessels[pl_norm($v)] ?? null;
                    if (!$vid) {                                                                                  // unknown vessel: kept as "other" with the text, so nothing is lost
                        $res['unknown_codes'][$v] = ($res['unknown_codes'][$v] ?? 0) + 1;
                        q('INSERT INTO plan_entries (user_id, plan_date, kind, note, created_by) VALUES (?,?,?,?,?)', [$uid, $date, 'other', mb_substr($v, 0, 500), (int)($me['id'] ?? 0) ?: null]); $res['entries']++; continue;
                    }
                    $p = $projOf($vid);
                    q('INSERT INTO plan_entries (user_id, plan_date, kind, project_id, vessel_id, created_by) VALUES (?,?,?,?,?,?)', [$uid, $date, 'work', $p ? (int)$p['id'] : null, $vid, (int)($me['id'] ?? 0) ?: null]); $res['entries']++;
                    $firstWork = $firstWork ?? (int) db()->lastInsertId();
                }
                if ($carId) { if ($firstWork) q('UPDATE plan_entries SET car_id = ? WHERE id = ?', [$carId, $firstWork]); else { q('INSERT INTO plan_entries (user_id, plan_date, kind, car_id, created_by) VALUES (?,?,?,?,?)', [$uid, $date, 'travel', $carId, (int)($me['id'] ?? 0) ?: null]); $res['entries']++; } $res['cars'] = ($res['cars'] ?? 0) + 1; }
            }
        }
        foreach ($res['unknown_codes'] as $c => $n) $res['warnings'][] = [0, t('pl.im_unknown_code', ['v' => $c, 'n' => $n])];
        return $res;
    });
}
function pl_cell_date($v): ?string {
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    if (is_string($v) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    if (is_string($v) && preg_match('/^(\d{1,2})[\/.](\d{1,2})[\/.](\d{4})$/', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    if (is_numeric($v) && $v > 40000 && $v < 60000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    return null;
}
/** Upper-case, accent-free, single-spaced key for matching names and vessels. */
function pl_norm(string $s): string {
    $s = mb_strtoupper(trim($s));
    $s = strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ']);
    return preg_replace('/\s+/', ' ', preg_replace('/[^\p{L}\p{N} ]/u', '', $s));
}
/** Surname-first Greek names vs "First Last" portal names: match when the two words appear in any order, or a transliteration matches. */
function pl_match_name(string $name, array $people): ?int {
    $parts = explode(' ', pl_norm($name)); if (count($parts) < 2) return null;
    $rev = pl_norm($parts[1] . ' ' . $parts[0]); if (isset($people[$rev])) return $people[$rev];
    $lat = function (string $s): string { $tr = ['Α' => 'A', 'Β' => 'V', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'I', 'Θ' => 'TH', 'Ι' => 'I', 'Κ' => 'K', 'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ξ' => 'X', 'Ο' => 'O', 'Π' => 'P', 'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T', 'Υ' => 'Y', 'Φ' => 'F', 'Χ' => 'CH', 'Ψ' => 'PS', 'Ω' => 'O', 'ΟΥ' => 'OU', 'ΑΙ' => 'AI', 'ΕΙ' => 'EI', 'ΜΠ' => 'B', 'ΝΤ' => 'D', 'ΓΚ' => 'G', 'ΤΣ' => 'TS', 'ΤΖ' => 'TZ']; return strtr($s, $tr); };
    $a = $lat(pl_norm($name)); $set = explode(' ', $a);
    foreach ($people as $k => $id) { $b = explode(' ', $lat($k)); if (count(array_intersect($set, $b)) >= 2) return $id; }
    // surname only, when unique
    $hits = []; foreach ($people as $k => $id) { $b = explode(' ', $lat($k)); if (in_array($set[0], $b, true)) $hits[] = $id; }
    return count(array_unique($hits)) === 1 ? $hits[0] : null;
}
/** Export the matrix of a year in the same layout as the Excel: names in rows, dates in columns, cells = vessel/sub-project or status. */
function pl_export(int $year): void {
    require_staff(); require_once __DIR__ . '/xlsxw.php';
    $from = "$year-01-01"; $to = "$year-12-31"; $grid = pl_grid($from, $to);
    $header = ['#', 'Engineer', 'Discipline']; $dates = [];
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) { $dates[] = date('Y-m-d', $d); $header[] = date('d.m.Y', $d); }   // full date so the file re-imports as the template (R30-008)
    $header[] = 'Work days'; $header[] = 'Leave'; $header[] = 'Sick'; $header[] = 'Vessels';
    $rows = []; $n = 0;
    foreach (pl_people() as $p) {
        $days = $grid[(int)$p['id']] ?? []; $c = pl_counters($days); $row = [++$n, $p['full_name'], $p['discipline']];
        foreach ($dates as $date) $row[] = isset($days[$date]) ? implode(' + ', array_map(fn($e) => $e['kind'] === 'work' ? pl_label($e) : mb_strtoupper(t('pl.k_' . $e['kind'])), $days[$date])) : '';
        $row[] = $c['work']; $row[] = $c['leave']; $row[] = $c['sick']; $row[] = implode(', ', $c['vessels']);
        $rows[] = $row;
    }
    $w = [4, 26, 16]; foreach ($dates as $x) $w[] = 11; array_push($w, 9, 7, 6, 30);
    // R30-008: the Lists sheet carries today's choices — statuses, active vessels, active project codes, cars — and every day cell gets the drop-down
    $choices = []; foreach (['leave', 'sick', 'parental', 'strike', 'office', 'warehouse', 'training', 'travel'] as $k) $choices[] = mb_strtoupper(t('pl.k_' . $k));
    foreach (all("SELECT DISTINCT v.name FROM vessels v WHERE v.is_active = 1 AND v.merged_into IS NULL AND EXISTS (SELECT 1 FROM projects p WHERE p.vessel_id = v.id AND p.status = 'active') ORDER BY v.name") as $v) $choices[] = $v['name'];
    foreach (all("SELECT project_code, name FROM projects WHERE status = 'active' AND project_code <> '' ORDER BY project_code") as $p) $choices[] = $p['project_code'];
    $cars = array_map(fn($c) => trim($c['name'] . ($c['plate'] ? ' (' . $c['plate'] . ')' : '')), pl_cars());
    $lrows = []; $max = max(count($choices), count($cars), 8);
    $how = [t('pl.tpl_how1'), t('pl.tpl_how2'), t('pl.tpl_how3'), t('pl.tpl_how4'), t('pl.tpl_how5'), t('pl.tpl_how6'), t('pl.tpl_how7', ['d' => fmt_date(date('Y-m-d'))])];
    for ($i = 0; $i < $max; $i++) $lrows[] = [$choices[$i] ?? '', $cars[$i] ?? '', $how[$i] ?? ''];
    $firstDay = 4; $lastDay = 3 + count($dates);
    $sheet = ['name' => 'Planning ' . $year, 'first' => true, 'header' => $header, 'rows' => $rows, 'widths' => $w,
              'listranges' => [['sqref' => xlsx_col($firstDay) . '2:' . xlsx_col($lastDay) . '400', 'ref' => 'Lists!$A$2:$A$' . (count($choices) + 1)]]];
    xlsx_download('GAS-planning-' . $year . '.xlsx', [$sheet, ['name' => 'Lists', 'header' => [t('pl.tpl_choices'), t('pl.tpl_cars'), t('pl.tpl_howto')], 'rows' => $lrows, 'widths' => [34, 28, 90]]]);
}

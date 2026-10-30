<?php
/**
 * Timesheets — Golden A/S engineers book their hours per main project and
 * sub-project; the manager of their discipline approves; approved sheets
 * are grouped into invoices. Hour types carry different costs, so they are
 * kept apart everywhere: normal / overtime / holiday.       GAS-CP-R11-001
 */

const TS_MAX_LINES = 80;

function ts_ip(): string {
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return mb_substr($ip, 0, 45);
}
/** True once migrate_cp_r11_timesheets.sql has run. */
function ts_has(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW TABLES LIKE 'timesheets'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}
function ts_types(): array    { return ['normal' => t('ts.normal'), 'overtime' => t('ts.overtime'), 'holiday' => t('ts.holiday')]; }
function ts_statuses(): array { return ['draft', 'submitted', 'approved', 'rejected', 'invoiced']; }
function ts_status_pill(string $s): string {
    $m = ['draft' => 'mute', 'submitted' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'invoiced' => 'navy'];
    return pill($m[$s] ?? 'mute', t('ts.st_' . $s));
}
function ts_fmt(float $h): string { return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.') ?: '0'; }

/* ----------------------------------------------------------- people */

/** Disciplines with their managers. */
function disciplines(bool $activeOnly = true): array {
    if (!ts_has()) return [];
    return all('SELECT d.*, m.full_name AS manager_name FROM disciplines d LEFT JOIN customer_users m ON m.id = d.manager_user_id '
             . ($activeOnly ? 'WHERE d.is_active = 1 ' : '') . 'ORDER BY d.is_active DESC, d.sort_order, d.name');
}
/** The user who approves this engineer's sheets: personal manager, else the discipline's manager. */
function ts_manager_for(array $user): ?array {
    if (!ts_has()) return null;
    $mid = (int)($user['manager_user_id'] ?? 0);
    if (!$mid && !empty($user['discipline_id'])) $mid = (int) scalar('SELECT manager_user_id FROM disciplines WHERE id = ?', [(int)$user['discipline_id']]);
    return $mid ? one('SELECT id, full_name, email FROM customer_users WHERE id = ? AND is_active = 1', [$mid]) : null;
}
/** May the current person approve / reject this sheet? */
function ts_can_approve(array $t): bool {
    $u = current_user();
    if (!is_staff()) return false;
    if ((int)$t['user_id'] === (int)$u['id'] && !is_cp_admin()) return false;        // never your own sheet
    return is_cp_admin() || ((int)$t['approver_id'] && (int)$t['approver_id'] === (int)$u['id']);
}
/** May the current person mark sheets as invoiced? Administrators and the project's PM. */
function ts_can_invoice(array $t): bool {
    $u = current_user();
    return is_cp_admin() || (is_staff() && (int)($t['pm_user_id'] ?? 0) === (int)$u['id']);
}

/* ------------------------------------------------------------ load */

function ts_event(int $id, string $event, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO ts_events (timesheet_id, event, user_id, user_name, note, ip) VALUES (?,?,?,?,?,?)',
      [$id, $event, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $note, ts_ip()]);
}
function ts_load(int $id): ?array {
    if (!ts_has()) return null;
    $t = one('SELECT t.*, p.project_code, p.name AS project_name, p.pm_user_id, p.project_manager, v.name AS vessel, c.name AS customer_name,
                     a.full_name AS approver_name
              FROM timesheets t JOIN projects p ON p.id = t.project_id
              LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
              LEFT JOIN customer_users a ON a.id = t.approver_id WHERE t.id = ?', [$id]);
    if (!$t) return null;
    $u = current_user();
    // visible to: the engineer, the approver, administrators, the project's PM
    if (!is_cp_admin() && (int)$t['user_id'] !== (int)$u['id'] && (int)$t['approver_id'] !== (int)$u['id'] && (int)$t['pm_user_id'] !== (int)$u['id']) return null;
    $t['lines']  = all('SELECT l.*, s.code AS sub_code, s.name AS sub_name FROM timesheet_lines l LEFT JOIN subprojects s ON s.id = l.subproject_id
                        WHERE l.timesheet_id = ? ORDER BY l.work_date, l.id', [$id]);
    $t['events'] = all('SELECT * FROM ts_events WHERE timesheet_id = ? ORDER BY id', [$id]);
    return $t;
}
/** Sheets the current person may see, with filters. $mine: only their own. */
function ts_list(array $f = []): array {
    if (!ts_has()) return [];
    $u = current_user(); $w = []; $args = [];
    if (!is_cp_admin()) { $w[] = '(t.user_id = ? OR t.approver_id = ? OR p.pm_user_id = ?)'; array_push($args, (int)$u['id'], (int)$u['id'], (int)$u['id']); }
    if (!empty($f['mine']))      { $w[] = 't.user_id = ?';     $args[] = (int)$u['id']; }
    if (!empty($f['toapprove'])) { $w[] = "t.status = 'submitted' AND " . (is_cp_admin() ? '1 = 1' : 't.approver_id = ?'); if (!is_cp_admin()) $args[] = (int)$u['id']; }
    if (!empty($f['project']))   { $w[] = 't.project_id = ?';  $args[] = (int)$f['project']; }
    if (!empty($f['vessel']))    { $w[] = 'p.vessel_id = ?';   $args[] = (int)$f['vessel']; }
    if (!empty($f['user']))      { $w[] = 't.user_id = ?';     $args[] = (int)$f['user']; }
    if (!empty($f['status']) && in_array($f['status'], ts_statuses(), true)) { $w[] = 't.status = ?'; $args[] = $f['status']; }
    if (!empty($f['from']))      { $w[] = 't.period_end >= ?';   $args[] = $f['from']; }
    if (!empty($f['to']))        { $w[] = 't.period_start <= ?'; $args[] = $f['to']; }
    if (!empty($f['customer']))  { $w[] = 'p.customer_id = ?'; $args[] = (int)$f['customer']; }
    if (!empty($f['discipline'])){ $w[] = 't.discipline_id = ?'; $args[] = (int)$f['discipline']; }
    if (!empty($f['approver']))  { $w[] = '(t.approver_id = ? OR t.approved_by = ?)'; array_push($args, (int)$f['approver'], (int)$f['approver']); }
    if (!empty($f['invoice']))   { $w[] = 't.invoice_ref LIKE ?'; $args[] = '%' . $f['invoice'] . '%'; }
    if (!empty($f['sub']))       { $w[] = 'EXISTS (SELECT 1 FROM timesheet_lines x WHERE x.timesheet_id = t.id AND x.subproject_id = ?)'; $args[] = (int)$f['sub']; }
    if (!empty($f['htype']) && in_array($f['htype'], ['overtime', 'holiday', 'main'], true)) {
        $w[] = $f['htype'] === 'main' ? 'EXISTS (SELECT 1 FROM timesheet_lines x WHERE x.timesheet_id = t.id AND x.subproject_id IS NULL)'
                                      : ($f['htype'] === 'overtime' ? 't.h_overtime > 0' : 't.h_holiday > 0');
    }
    if (!empty($f['minh']) && is_numeric($f['minh'])) { $w[] = '(t.h_normal + t.h_overtime + t.h_holiday) >= ?'; $args[] = (float)$f['minh']; }
    if (!empty($f['q']))         { $w[] = '(t.ts_no LIKE ? OR t.user_name LIKE ? OR p.project_code LIKE ? OR p.name LIKE ? OR t.invoice_ref LIKE ? OR t.note LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($args, $like, $like, $like, $like, $like, $like); }
    $order = ($f['group'] ?? '') === 'vessel' ? 'v.name IS NULL, v.name, p.project_code, ' : (($f['group'] ?? '') === 'project' ? 'p.project_code, ' : '');
    return all('SELECT t.*, p.project_code, p.name AS project_name, p.pm_user_id, p.vessel_id, v.name AS vessel, a.full_name AS approver_name
                FROM timesheets t JOIN projects p ON p.id = t.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customer_users a ON a.id = t.approver_id '
              . ($w ? 'WHERE ' . implode(' AND ', $w) . ' ' : '')
              . "ORDER BY {$order}FIELD(t.status,'submitted','rejected','draft','approved','invoiced'), t.period_start DESC, t.id DESC", $args);
}
/** Period presets for the filters: key => [from, to]. */
function ts_period_presets(): array {
    $mon = strtotime('monday this week'); $m0 = strtotime(date('Y-m-01'));
    $q = (int)floor((date('n') - 1) / 3) * 3 + 1; $q0 = mktime(0, 0, 0, $q, 1, (int)date('Y'));
    return [
        'week'      => [date('Y-m-d', $mon), date('Y-m-d', strtotime('+6 days', $mon))],
        'lastweek'  => [date('Y-m-d', strtotime('-7 days', $mon)), date('Y-m-d', strtotime('-1 day', $mon))],
        'month'     => [date('Y-m-01'), date('Y-m-t')],
        'lastmonth' => [date('Y-m-01', strtotime('-1 month', $m0)), date('Y-m-t', strtotime('-1 month', $m0))],
        'quarter'   => [date('Y-m-d', $q0), date('Y-m-t', strtotime('+2 months', $q0))],
        'year'      => [date('Y-01-01'), date('Y-12-31')],
    ];
}
/** Sheets waiting for the current manager (dashboard / badge). */
function ts_to_approve(): array { return is_staff() ? ts_list(['toapprove' => 1]) : []; }

/* ------------------------------------------------------------ save */

function ts_number(string $name): string {
    $base = 'TS-' . date('Y') . '-' . strtoupper(preg_replace('/[^A-Za-z]/', '', initials($name)) ?: 'XX');
    $n = (int) scalar('SELECT COUNT(*) FROM timesheets WHERE ts_no LIKE ?', [$base . '-%']) + 1;
    while (scalar('SELECT COUNT(*) FROM timesheets WHERE ts_no = ?', [sprintf('%s-%03d', $base, $n)])) $n++;
    return sprintf('%s-%03d', $base, $n);
}
/** Create or update a draft from the form. Returns the id. */
function ts_save_from_post(int $id, array $u): int {
    $pid = (int)($_POST['project_id'] ?? 0);
    $p = one('SELECT id FROM projects WHERE id = ?', [$pid]);
    if (!$p) throw new RuntimeException(t('err.notfound'));
    $tmIds = !$id && function_exists('tm_allowed_ids') ? tm_allowed_ids($pid) : null;                 // R25-001: lines only on assigned sub-projects
    if ($tmIds !== null && !$tmIds) throw new RuntimeException(t('tm.not_allowed'));
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['period_start'] ?? '')) ? $_POST['period_start'] : date('Y-m-d', strtotime('monday this week'));
    $end   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['period_end'] ?? ''))   ? $_POST['period_end']   : date('Y-m-d', strtotime($start . ' +6 days'));
    if ($end < $start) $end = $start;
    $note  = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000) ?: null;
    $me    = one('SELECT cu.*, d.name AS discipline_name FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.id = ?', [(int)$u['id']]);
    $mgr   = ts_manager_for($me);

    // lines: any index, in form order
    $lines = []; $tot = ['normal' => 0.0, 'overtime' => 0.0, 'holiday' => 0.0];
    $idx = array_slice(array_keys((array)($_POST['ln_date'] ?? [])), 0, TS_MAX_LINES);
    foreach ($idx as $i) {
        $d  = (string)($_POST['ln_date'][$i] ?? '');
        $h  = (float) str_replace(',', '.', (string)($_POST['ln_hours'][$i] ?? '0'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || $h <= 0) continue;
        $h  = min(24, round($h * 4) / 4);                                          // quarter hours
        $ty = in_array($_POST['ln_type'][$i] ?? '', ['normal', 'overtime', 'holiday'], true) ? $_POST['ln_type'][$i] : 'normal';
        $sid = (int)($_POST['ln_sub'][$i] ?? 0) ?: null;
        if ($sid && !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
        if ($tmIds !== null && (!$sid || !in_array($sid, $tmIds, true))) throw new RuntimeException(t('tm.not_allowed'));           // R25-001
        $wid = (int)($_POST['ln_work'][$i] ?? 0) ?: null;
        if ($wid && (!function_exists('wp_has') || !wp_has() || !scalar('SELECT COUNT(*) FROM sp_works WHERE id = ? AND subproject_id = ?', [$wid, (int)$sid]))) $wid = null;   // R35-001
        $act = function_exists('eng_has') && eng_has() ? (in_array($_POST['ln_act'][$i] ?? '', ENG_ACTIVITIES, true) ? $_POST['ln_act'][$i] : eng_default_activity((int)($me['discipline_id'] ?? 0) ?: null, $sid)) : null;   // R47-001
        $lines[] = [$d, $sid, $ty, $h, mb_substr(trim((string)($_POST['ln_desc'][$i] ?? '')), 0, 500) ?: null, 0, $wid, $act];
    }
    if (function_exists('eng_check_booking') && eng_has() && !empty($me['discipline_id'])) {   // R47-001: engineering hours beyond budget + approved extra are refused
        $engNew = []; foreach ($lines as $l) if (!empty($l[7]) && $l[7] !== 'site' && $l[1]) $engNew[(int)$l[1]][$l[7]] = ($engNew[(int)$l[1]][$l[7]] ?? 0) + (float)$l[3];   // R48-001: [sub => [stage => h]]
        if ($engNew && ($msg = eng_check_booking((int)$me['discipline_id'], $engNew, $id ?: null))) throw new RuntimeException($msg);
    }
    // R23-001: the working calendar decides — holidays / Sundays / Saturdays and hours beyond the daily normal are reclassified
    $reclass = 0.0;
    if (function_exists('wc_has') && wc_has()) { [$lines, $reclass] = wc_classify(wc_user_calendar((int)$u['id']), $lines); } else $lines = array_map(fn($l) => $l + [5 => 0, 6 => null, 7 => null], $lines);
    foreach ($lines as $l) $tot[$l[2]] += $l[3];
    $GLOBALS['ts_reclass'] = $reclass;
    if ($id) {
        $t = one('SELECT * FROM timesheets WHERE id = ?', [$id]);
        if (!$t || ((int)$t['user_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('err.notfound'));
        if (!in_array($t['status'], ['draft', 'rejected'], true)) throw new RuntimeException(t('ts.locked'));
        q('UPDATE timesheets SET project_id = ?, period_start = ?, period_end = ?, note = ?, h_normal = ?, h_overtime = ?, h_holiday = ?,
                                 discipline_id = ?, discipline_name = ?, approver_id = ? WHERE id = ?',
          [$pid, $start, $end, $note, $tot['normal'], $tot['overtime'], $tot['holiday'], $me['discipline_id'] ?? null, $me['discipline_name'] ?? null, $mgr['id'] ?? null, $id]);
        q('DELETE FROM timesheet_lines WHERE timesheet_id = ?', [$id]);
        ts_event($id, 'saved');
    } else {
        q('INSERT INTO timesheets (ts_no, user_id, user_name, discipline_id, discipline_name, project_id, period_start, period_end, note, h_normal, h_overtime, h_holiday, approver_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [ts_number($u['full_name']), (int)$u['id'], $u['full_name'], $me['discipline_id'] ?? null, $me['discipline_name'] ?? null, $pid, $start, $end, $note,
           $tot['normal'], $tot['overtime'], $tot['holiday'], $mgr['id'] ?? null]);
        $id = (int) db()->lastInsertId();
        ts_event($id, 'created');
    }
    $hasAuto = function_exists('wc_has') && wc_has();
    $hasWork = function_exists('wp_has') && wp_has();
    $hasAct = function_exists('eng_has') && eng_has();
    foreach ($lines as $ln) { $base = array_slice($ln, 0, 5); $wid = $ln[6] ?? null; $auto = (int)($ln[5] ?? 0);
        if ($hasAuto && $hasWork && $hasAct) q('INSERT INTO timesheet_lines (timesheet_id, work_date, subproject_id, hour_type, hours, description, auto_type, work_id, activity) VALUES (?,?,?,?,?,?,?,?,?)', array_merge([$id], $base, [$auto, $wid, $ln[7] ?? null]));
        elseif ($hasAuto && $hasWork) q('INSERT INTO timesheet_lines (timesheet_id, work_date, subproject_id, hour_type, hours, description, auto_type, work_id) VALUES (?,?,?,?,?,?,?,?)', array_merge([$id], $base, [$auto, $wid]));
        elseif ($hasAuto) q('INSERT INTO timesheet_lines (timesheet_id, work_date, subproject_id, hour_type, hours, description, auto_type) VALUES (?,?,?,?,?,?,?)', array_merge([$id], $base, [$auto]));
        else q('INSERT INTO timesheet_lines (timesheet_id, work_date, subproject_id, hour_type, hours, description) VALUES (?,?,?,?,?,?)', array_merge([$id], $base)); }
    return $id;
}

/* -------------------------------------------------------- workflow */

function ts_submit(int $id): void {
    $t = ts_load($id); $u = current_user();
    if (!$t || ((int)$t['user_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('err.notfound'));
    if (!in_array($t['status'], ['draft', 'rejected'], true)) throw new RuntimeException(t('ts.locked'));
    if (!$t['lines']) throw new RuntimeException(t('ts.no_lines'));
    // the approver is resolved at submission (manager may have changed since the draft)
    $me  = one('SELECT * FROM customer_users WHERE id = ?', [(int)$t['user_id']]);
    $mgr = ts_manager_for($me);
    if (!$mgr && !is_cp_admin()) throw new RuntimeException(t('ts.no_manager'));
    q('UPDATE timesheets SET status = "submitted", submitted_at = NOW(), approver_id = ?, approved_by = NULL, approved_name = NULL, approved_at = NULL, approval_note = NULL WHERE id = ?',
      [$mgr['id'] ?? null, $id]);
    ts_event($id, 'submitted', $mgr ? t('ts.ev_to', ['name' => $mgr['full_name']]) : null);
}
function ts_decide(int $id, bool $approve, string $note): void {
    $t = ts_load($id); $u = current_user();
    if (!$t) throw new RuntimeException(t('err.notfound'));
    if ($t['status'] !== 'submitted') throw new RuntimeException(t('ts.locked'));
    if (!ts_can_approve($t)) { http_response_code(403); exit(t('err.noaccess')); }
    $note = mb_substr(trim($note), 0, 1000);
    if (!$approve && $note === '') throw new RuntimeException(t('ts.note_req'));
    q('UPDATE timesheets SET status = ?, approved_by = ?, approved_name = ?, approved_at = NOW(), approval_note = ? WHERE id = ?',
      [$approve ? 'approved' : 'rejected', (int)$u['id'], $u['full_name'], $note ?: null, $id]);
    ts_event($id, $approve ? 'approved' : 'rejected', $note ?: null);
    if ($approve && function_exists('bg_check_alerts')) foreach (array_unique(array_map('intval', array_column(all('SELECT DISTINCT subproject_id FROM timesheet_lines WHERE timesheet_id = ? AND subproject_id IS NOT NULL', [$id]), 'subproject_id'))) as $sid) bg_check_alerts($sid);   // R34-001
}
/** Mark approved sheets as invoiced under one reference. Returns how many changed. */
function ts_invoice(array $ids, string $ref): int {
    $u = current_user(); $ref = mb_substr(trim($ref), 0, 60);
    if ($ref === '') throw new RuntimeException(t('ts.ref_req'));
    $n = 0;
    foreach ($ids as $id) {
        $t = ts_load((int)$id);
        if (!$t || $t['status'] !== 'approved' || !ts_can_invoice($t)) continue;
        q('UPDATE timesheets SET status = "invoiced", invoice_ref = ?, invoiced_by = ?, invoiced_name = ?, invoiced_at = NOW() WHERE id = ?', [$ref, (int)$u['id'], $u['full_name'], (int)$id]);
        ts_event((int)$id, 'invoiced', $ref); $n++;
    }
    return $n;
}
/** Undo an invoice mark (administrators) — back to approved. */
function ts_uninvoice(int $id): void {
    $t = ts_load($id);
    if (!$t || $t['status'] !== 'invoiced' || !is_cp_admin()) throw new RuntimeException(t('err.notfound'));
    q('UPDATE timesheets SET status = "approved", invoice_ref = NULL, invoiced_by = NULL, invoiced_name = NULL, invoiced_at = NULL WHERE id = ?', [$id]);
    ts_event($id, 'uninvoiced', $t['invoice_ref']);
}
function ts_withdraw(int $id): void {                  // engineer takes a submitted sheet back
    $t = ts_load($id); $u = current_user();
    if (!$t || $t['status'] !== 'submitted' || ((int)$t['user_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('err.notfound'));
    q('UPDATE timesheets SET status = "draft", submitted_at = NULL WHERE id = ?', [$id]);
    ts_event($id, 'withdrawn');
}
function ts_delete(int $id): void {
    $t = ts_load($id); $u = current_user();
    if (!$t) throw new RuntimeException(t('err.notfound'));
    if (!is_cp_admin() && (!in_array($t['status'], ['draft', 'rejected'], true) || (int)$t['user_id'] !== (int)$u['id'])) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM timesheets WHERE id = ?', [$id]);
}

/* -------------------------------------------------------- reporting */

/** Manhours for a project (or all), grouped. $statuses limits the sheets counted.
 *  Returns ['discipline' => rows, 'sub' => rows, 'engineer' => rows, 'total' => row, 'status' => rows]
 *  where every row carries normal / overtime / holiday / total. */
function ts_manhours(?int $pid, array $statuses, ?string $from = null, ?string $to = null, ?int $vid = null): array {
    $empty = ['normal' => 0, 'overtime' => 0, 'holiday' => 0, 'total' => 0, 'sheets' => 0];
    if (!ts_has()) return ['discipline' => [], 'sub' => [], 'engineer' => [], 'status' => [], 'project' => [], 'list' => [], 'total' => $empty];
    $w = ['1 = 1']; $args = [];
    if ($pid) { $w[] = 't.project_id = ?'; $args[] = $pid; }
    if ($vid) { $w[] = 'p.vessel_id = ?';  $args[] = $vid; }
    if ($statuses) { $w[] = 't.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')'; array_push($args, ...$statuses); }
    if ($from) { $w[] = 'l.work_date >= ?'; $args[] = $from; }
    if ($to)   { $w[] = 'l.work_date <= ?'; $args[] = $to; }
    $where = implode(' AND ', $w);
    $sum = "SUM(CASE WHEN l.hour_type = 'normal' THEN l.hours ELSE 0 END) AS normal,
            SUM(CASE WHEN l.hour_type = 'overtime' THEN l.hours ELSE 0 END) AS overtime,
            SUM(CASE WHEN l.hour_type = 'holiday' THEN l.hours ELSE 0 END) AS holiday,
            SUM(l.hours) AS total, COUNT(DISTINCT t.id) AS sheets";
    $base = "FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN projects p ON p.id = t.project_id WHERE {$where}";
    return [
        'discipline' => all("SELECT COALESCE(t.discipline_name, '—') AS label, {$sum} {$base} GROUP BY t.discipline_name ORDER BY total DESC", $args),
        'sub'        => all("SELECT p.project_code, p.name AS project_name, l.subproject_id, COALESCE(s.code, '') AS sub_code, COALESCE(s.name, '') AS sub_name, {$sum}
                             FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN projects p ON p.id = t.project_id
                             LEFT JOIN subprojects s ON s.id = l.subproject_id WHERE {$where}
                             GROUP BY p.id, p.project_code, p.name, l.subproject_id, s.code, s.name, s.sort_order ORDER BY p.project_code, l.subproject_id IS NOT NULL, s.sort_order, s.code", $args),
        'engineer'   => all("SELECT t.user_name AS label, COALESCE(t.discipline_name, '—') AS discipline, {$sum} {$base} GROUP BY t.user_id, t.user_name, t.discipline_name ORDER BY t.user_name", $args),
        'status'     => all("SELECT t.status AS label, {$sum} {$base} GROUP BY t.status ORDER BY FIELD(t.status,'draft','submitted','rejected','approved','invoiced')", $args),
        'project'    => all("SELECT p.id AS project_id, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name, {$sum}
                             FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN projects p ON p.id = t.project_id
                             LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE {$where}
                             GROUP BY p.id, p.project_code, p.name, v.name, c.name ORDER BY v.name, p.project_code", $args),
        'list'       => all("SELECT t.id, t.ts_no, t.user_name, t.discipline_name, t.period_start, t.period_end, t.status, t.invoice_ref, t.approved_name, t.approved_at,
                                    p.project_code, p.name AS project_name, v.name AS vessel,
                                    SUM(CASE WHEN l.hour_type = 'normal' THEN l.hours ELSE 0 END) AS normal,
                                    SUM(CASE WHEN l.hour_type = 'overtime' THEN l.hours ELSE 0 END) AS overtime,
                                    SUM(CASE WHEN l.hour_type = 'holiday' THEN l.hours ELSE 0 END) AS holiday, SUM(l.hours) AS total
                             FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN projects p ON p.id = t.project_id LEFT JOIN vessels v ON v.id = p.vessel_id
                             WHERE {$where} GROUP BY t.id ORDER BY v.name, p.project_code, t.period_start, t.user_name", $args),
        'branch'     => function_exists('br_has') && br_has() && br_multi()
                        ? all("SELECT COALESCE(s.branch_id, p.branch_id, 0) AS branch_id, COALESCE(u.branch_id, 0) AS home_branch_id, {$sum}
                               FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN projects p ON p.id = t.project_id
                               LEFT JOIN subprojects s ON s.id = l.subproject_id LEFT JOIN customer_users u ON u.id = t.user_id WHERE {$where}
                               GROUP BY COALESCE(s.branch_id, p.branch_id, 0), COALESCE(u.branch_id, 0) ORDER BY 1, 2", $args) : [],      // R24-003 branch of the work × home branch of the engineer
        'total'      => one("SELECT {$sum} {$base}", $args) ?: $empty,
    ];
}

/* ------------------------------------------------- report documents */

/** Everything the PDF / Word timesheet report needs for one project or one vessel (or all). */
function ts_report_data(?int $pid, ?int $vid, array $statuses, ?string $from, ?string $to): array {
    $d = ts_manhours($pid, $statuses, $from, $to, $vid);
    $d['project'] = $pid ? one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]) : null;
    $d['vessel']  = $vid ? one('SELECT v.*, c.name AS customer_name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ?', [$vid]) : null;
    $d['by_project'] = $d['project'] ? [] : ts_manhours(null, $statuses, $from, $to, $vid)['project'];
    $scope = $d['project'] ? ($d['project']['project_code'] ?: 'P' . $pid) : ($d['vessel'] ? preg_replace('/[^A-Za-z0-9]+/', '-', trim($d['vessel']['name'])) : 'ALL');
    $d['branch']   = $d['project'] ? br_of((int)$d['project']['id']) : ($d['vessel'] && function_exists('ar_vessel_project') ? br_of((int) ar_vessel_project((int)$d['vessel']['id'])) : br_user());
    $d['doc_no']   = $d['branch']['doc_prefix'] . '-TS-' . strtoupper(trim($scope, '-')) . '-' . date('Ymd');
    $d['scope_label'] = $d['project'] ? t('rp.ts_scope_project') . ': ' . ($d['project']['project_code'] ?: '') . ' ' . $d['project']['name'] . ($d['project']['vessel'] ? ' · ' . $d['project']['vessel'] : '')
                      : ($d['vessel'] ? t('rp.ts_scope_vessel') . ': ' . $d['vessel']['name'] . ($d['vessel']['customer_name'] ? ' · ' . $d['vessel']['customer_name'] : '') : t('mh.all_projects'));
    $d['period_label'] = ($from || $to) ? (($from ? fmt_date($from) : '…') . ' – ' . ($to ? fmt_date($to) : '…')) : t('rp.ts_all_dates');
    $d['status_label'] = implode(', ', array_map(fn($x) => t('ts.st_' . $x), $statuses));
    $d['statuses'] = $statuses; $d['from'] = $from; $d['to'] = $to;
    return $d;
}

/** The timesheet report as a PDF (A4, Golden A/S header, numbered tables). */
function ts_render_pdf(array $d): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter();
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $green = '#2F7D5C'; $red = '#9B2C2C';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0;
    $bd = br_doc(null, null, $d['branch'] ?? null); $logo = $bd['logo'];
    $f = fn($v) => ts_fmt((float)$v);
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $d, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('rp.ts_title')));
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $d['doc_no']);
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26);
        $y = 31.0;
    };
    $footer = function () use ($pdf, $ink, $L, $W, $d) {
        $pdf->setColor($ink); $pdf->setFont('R', 7);
        $pdf->text($L, 291, $bd['footer'] . ' · ' . $d['doc_no'] . ' · ' . date('d.m.Y H:i'));
        $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $W);
    };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $h) use (&$y, $newPage) { if ($y + $h > 276) $newPage(); };
    $caption = function (string $title) use ($pdf, $navy, $L, &$y, &$tableNo, $need) {
        $y += 3; $need(16); $tableNo++;
        $pdf->setColor($navy); $pdf->setFont('B', 9); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.5;
    };
    $hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols);
        $x = $L; $h = 5.6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $h, 'F');
        foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $h); $pdf->setColor($navy); $pdf->setFont('B', 7.5); $pdf->text($x + 1.5, $y + $h / 2 + 1.1, $lab, $al, $cw - 3); $x += $cw; }
        $y += $h;
    };
    $drow = function (array $cols, array $vals, bool $bold = false, ?string $fill = null) use ($pdf, $ink, $line, $L, &$y, $need, $hrow) { $cols = rc_fit_cols($cols);
        $h = 5.3; $need($h + 1);
        $x = $L; if ($fill) { $pdf->setColor($fill); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $h, 'F'); }
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $h); $pdf->setColor($ink); $pdf->setFont($bold ? 'B' : 'R', 8); $pdf->text($x + 1.5, $y + $h / 2 + 1.1, (string)($vals[$i] ?? ''), $al, $cw - 3); $x += $cw; }
        $y += $h;
    };
    $hoursCols = fn(float $first) => [[t('ts.normal'), 22, 'R'], [t('ts.overtime'), 22, 'R'], [t('ts.holiday'), 26, 'R'], [t('ts.total'), 22, 'R'], [t('mh.share'), 16, 'R'], [t('ts.sheets'), 16, 'R']];
    $hoursTable = function (string $title, string $firstLabel, array $rows, callable $label) use ($d, $caption, $hrow, $drow, $hoursCols, $f, $W, $head) {
        $cols = array_merge([[$firstLabel, $W - 124, 'L']], $hoursCols(0));
        $caption($title); $hrow($cols);
        if (!$rows) $drow($cols, [t('mh.none')]);
        foreach ($rows as $r) {
            $share = (float)$d['total']['total'] > 0 ? round((float)$r['total'] / (float)$d['total']['total'] * 100) . '%' : '';
            $drow($cols, [$label($r), $f($r['normal']), $f($r['overtime']), $f($r['holiday']), $f($r['total']), $share, (int)$r['sheets']]);
        }
        $t = $d['total']; $drow($cols, [t('ts.total'), $f($t['normal']), $f($t['overtime']), $f($t['holiday']), $f($t['total']), '', (int)$t['sheets']], true, $head);
    };

    $header(); $footer(); $pdf->setFont('R', 9);
    // scope block
    $kv = [[t('rp.ts_scope'), $d['scope_label']], [t('ts.period'), $d['period_label']], [t('mh.count_status'), $d['status_label']], [t('rp.issued'), date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? '')]];
    foreach ($kv as [$k, $v]) $y += $pdf->kvCell($L, $y, $W, 45, $k, $v, ['minH' => 6, 'line' => $line, 'navy' => $navy, 'ink' => $ink]);   // R59-002: wraps
    $y += 4;
    // summary tiles
    $tiles = [[t('ts.normal'), $f($d['total']['normal']), $navy], [t('ts.overtime'), $f($d['total']['overtime']), $gold], [t('ts.holiday'), $f($d['total']['holiday']), $red], [t('ts.total'), $f($d['total']['total']), $green]];
    $tw = ($W - 9) / 4; $x = $L;
    foreach ($tiles as [$lab, $val, $col]) { $pdf->setColor($head); $pdf->rect($x, $y, $tw, 16, 'F'); $pdf->setColor($col); $pdf->setFont('B', 14); $pdf->text($x, $y + 8.5, $val, 'C', $tw); $pdf->setColor($ink); $pdf->setFont('R', 7.5); $pdf->text($x, $y + 13.5, mb_strtoupper($lab), 'C', $tw); $x += $tw + 3; }
    $y += 20;
    $pdf->setColor($ink); $pdf->setFont('R', 7.5); $pdf->text($L, $y + 3, t('mh.cost_note')); $y += 7;

    if ($d['by_project']) $hoursTable(t('mh.by_project'), t('pr.one'), $d['by_project'], fn($r) => ($r['project_code'] ?: $r['project_name']) . ' ' . mb_substr($r['project_name'], 0, 40) . ($r['vessel'] && !$d['vessel'] ? ' · ' . $r['vessel'] : ''));
    $hoursTable(t('mh.by_discipline'), t('disc.one'), $d['discipline'], fn($r) => $r['label']);
    $hoursTable(t('mh.by_sub'), t('mh.project_sub'), $d['sub'], fn($r) => ($d['project'] ? '' : ($r['project_code'] ?: $r['project_name']) . ' › ') . ($r['subproject_id'] ? trim($r['sub_code'] . ' ' . $r['sub_name']) : t('ts.main_project')));
    $hoursTable(t('mh.by_engineer'), t('ts.engineer'), $d['engineer'], fn($r) => $r['label'] . ' (' . $r['discipline'] . ')');
    $hoursTable(t('mh.by_status'), t('th.status'), $d['status'], fn($r) => t('ts.st_' . $r['label']));

    // sheet list
    $cols = [[t('ts.no'), 25, 'L'], [t('ts.engineer'), 30, 'L'], [t('pr.one'), 22, 'L'], [t('ts.period'), 27, 'L'], [t('ts.normal'), 10, 'R'], ['OT', 9, 'R'], ['HOL', 9, 'R'], [t('ts.total'), 11, 'R'], [t('th.status'), 7, 'L']];
    $cols[8][1] = $W - array_sum(array_column(array_slice($cols, 0, 8), 1));
    $caption(t('rp.ts_sheets')); $hrow($cols);
    if (!$d['list']) $drow($cols, [t('ts.none')]);
    foreach ($d['list'] as $r) $drow($cols, [$r['ts_no'], $r['user_name'], $r['project_code'] ?: $r['project_name'], date('d.m.y', strtotime($r['period_start'])) . '–' . date('d.m.y', strtotime($r['period_end'])), $f($r['normal']), $f($r['overtime']), $f($r['holiday']), $f($r['total']), t('ts.st_' . $r['status']) . ($r['invoice_ref'] ? ' · ' . $r['invoice_ref'] : '')]);
    $t = $d['total']; $drow($cols, [t('ts.total'), '', '', count($d['list']) . ' ' . t('ts.sheets'), $f($t['normal']), $f($t['overtime']), $f($t['holiday']), $f($t['total']), ''], true, $head);
    return $pdf->bytes($d['doc_no']);
}

/** The same report as a Word document. */
function ts_render_docx(array $d): string {
    require_once __DIR__ . '/docx.php';
    $w = new DocxWriter();
    $bd = br_doc(null, null, $d['branch'] ?? null); $logo = (string) @file_get_contents($bd['logo']);
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4'; $f = fn($v) => ts_fmt((float)$v); $tableNo = 0;
    $w->grid([[['lines' => [mb_strtoupper(t('rp.ts_title')), $d['doc_no']], 'b' => true, 'sz' => 14, 'color' => $NAVY], ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    $kv = fn($l) => ['lines' => [$l], 'b' => true, 'sz' => 8]; $vv = fn($v) => ['text' => (string)$v, 'sz' => 9];
    $w->grid([[$kv(t('rp.ts_scope')), $vv($d['scope_label'])], [$kv(t('ts.period')), $vv($d['period_label'])], [$kv(t('mh.count_status')), $vv($d['status_label'])], [$kv(t('rp.issued')), $vv(date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? ''))]], [2400, 7240], ['border' => '9AA5B1']);
    $tile = fn($lab, $val, $col) => ['lines' => [[$val, ['sz' => 14]], [mb_strtoupper($lab), ['sz' => 7, 'b' => false, 'color' => '1F2937']]], 'b' => true, 'color' => $col, 'fill' => $HEAD, 'align' => 'center'];
    $w->grid([[$tile(t('ts.normal'), $f($d['total']['normal']), $NAVY), $tile(t('ts.overtime'), $f($d['total']['overtime']), 'C89B3C'), $tile(t('ts.holiday'), $f($d['total']['holiday']), '9B2C2C'), $tile(t('ts.total'), $f($d['total']['total']), '2F7D5C')]], [2410, 2410, 2410, 2410], ['border' => 'FFFFFF']);
    $w->para(t('mh.cost_note'), ['sz' => 9, 'i' => true]);
    $hdr = fn($t) => ['text' => $t, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center'];
    $c = fn($t, $al = 'left', $b = false) => ['text' => (string)$t, 'sz' => 9, 'align' => $al, 'b' => $b];
    $hoursTable = function (string $title, string $firstLabel, array $rows, callable $label) use ($w, $d, $hdr, $c, $f, $NAVY, &$tableNo) {
        $tableNo++;
        $w->para(t('rp.table') . ' ' . $tableNo . ' — ' . $title, ['b' => true, 'align' => 'left', 'color' => $NAVY]);
        $out = [[$hdr($firstLabel), $hdr(t('ts.normal')), $hdr(t('ts.overtime')), $hdr(t('ts.holiday')), $hdr(t('ts.total')), $hdr(t('mh.share')), $hdr(t('ts.sheets'))]];
        if (!$rows) $out[] = [$c(t('mh.none')), '', '', '', '', '', ''];
        foreach ($rows as $r) {
            $share = (float)$d['total']['total'] > 0 ? round((float)$r['total'] / (float)$d['total']['total'] * 100) . '%' : '';
            $out[] = [$c($label($r)), $c($f($r['normal']), 'right'), $c($f($r['overtime']), 'right'), $c($f($r['holiday']), 'right'), $c($f($r['total']), 'right', true), $c($share, 'right'), $c((int)$r['sheets'], 'right')];
        }
        $t = $d['total']; $out[] = [$c(t('ts.total'), 'left', true), $c($f($t['normal']), 'right', true), $c($f($t['overtime']), 'right', true), $c($f($t['holiday']), 'right', true), $c($f($t['total']), 'right', true), '', $c((int)$t['sheets'], 'right', true)];
        $w->grid($out, [3640, 1000, 1000, 1200, 1000, 900, 900], ['border' => '9AA5B1']);
    };
    if ($d['by_project']) $hoursTable(t('mh.by_project'), t('pr.one'), $d['by_project'], fn($r) => ($r['project_code'] ?: $r['project_name']) . ' ' . $r['project_name'] . ($r['vessel'] && !$d['vessel'] ? ' · ' . $r['vessel'] : ''));
    $hoursTable(t('mh.by_discipline'), t('disc.one'), $d['discipline'], fn($r) => $r['label']);
    $hoursTable(t('mh.by_sub'), t('mh.project_sub'), $d['sub'], fn($r) => ($d['project'] ? '' : ($r['project_code'] ?: $r['project_name']) . ' › ') . ($r['subproject_id'] ? trim($r['sub_code'] . ' ' . $r['sub_name']) : t('ts.main_project')));
    $hoursTable(t('mh.by_engineer'), t('ts.engineer'), $d['engineer'], fn($r) => $r['label'] . ' (' . $r['discipline'] . ')');
    $hoursTable(t('mh.by_status'), t('th.status'), $d['status'], fn($r) => t('ts.st_' . $r['label']));
    $tableNo++;
    $w->para(t('rp.table') . ' ' . $tableNo . ' — ' . t('rp.ts_sheets'), ['b' => true, 'align' => 'left', 'color' => $NAVY]);
    $out = [[$hdr(t('ts.no')), $hdr(t('ts.engineer')), $hdr(t('pr.one')), $hdr(t('ts.period')), $hdr(t('ts.normal')), $hdr('OT'), $hdr('HOL'), $hdr(t('ts.total')), $hdr(t('th.status'))]];
    foreach ($d['list'] as $r) $out[] = [$c($r['ts_no']), $c($r['user_name']), $c($r['project_code'] ?: $r['project_name']), $c(date('d.m.y', strtotime($r['period_start'])) . '–' . date('d.m.y', strtotime($r['period_end']))), $c($f($r['normal']), 'right'), $c($f($r['overtime']), 'right'), $c($f($r['holiday']), 'right'), $c($f($r['total']), 'right', true), $c(t('ts.st_' . $r['status']) . ($r['invoice_ref'] ? ' · ' . $r['invoice_ref'] : ''))];
    $t = $d['total']; $out[] = [$c(t('ts.total'), 'left', true), '', '', $c(count($d['list']) . ' ' . t('ts.sheets')), $c($f($t['normal']), 'right', true), $c($f($t['overtime']), 'right', true), $c($f($t['holiday']), 'right', true), $c($f($t['total']), 'right', true), ''];
    $w->grid($out, [1500, 1700, 1300, 1500, 600, 600, 600, 700, 1140], ['border' => '9AA5B1']);
    return $w->bytes($d['doc_no'], $bd['footer'] . ' · ' . $d['doc_no']);
}

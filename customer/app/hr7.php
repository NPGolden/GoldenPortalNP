<?php
/** R64-001 · personal data for HR / Owner only · Owner out of the rankings · who is online · evaluation auto-metrics (training, attendance)
 *  · evaluation policy · targets for departments and individuals.   GAS-CP-R64-001 */
declare(strict_types=1);

/* ------------------------------------------------------------------ access */
/** Personal and payroll data: HR (scope hr) and Owner (scope owner) only — plain administrators do not see them. */
function hr_personal_can(): bool { return is_staff() && ((function_exists('hr_only') && hr_only()) || (function_exists('hr_owner') && hr_owner())); }
/** Accounts with the Owner scope — kept out of the activity rankings, the most-active lists and the winner of the month. */
function hr_owner_ids(): array { static $c = null; if ($c === null) { try { $c = array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE r.scope = 'owner'"), 'id')); } catch (Throwable $e) { $c = []; } } return $c; }
function hr_targets_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_targets'"); } catch (Throwable $e) { $h = false; } } return $h; }

/* ------------------------------------------------------------------ who is online */
/** Online staff with the start of their current session (last successful sign-in) and the uptime. */
function online_detail(): array {
    $out = [];
    foreach (online_users() as $o) {
        $since = null; $up = null; try { $r = one("SELECT MAX(created_at) s, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) d FROM login_log WHERE user_id = ? AND outcome = 'success'", [(int)$o['id']]); $since = $r['s'] ?? null; $up = $r['d'] ?? null; } catch (Throwable $e) {}
        $o['since'] = $since; $o['uptime'] = $since ? max(0, (int)$up) : null; $out[] = $o;   // computed by the database clock, so the PHP / MySQL time-zone offset cannot creep in
    }
    return $out;
}
function fmt_uptime(?int $s): string { if ($s === null) return '—'; $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); return $h ? $h . ' h ' . sprintf('%02d', $m) . ' min' : $m . ' min'; }

/* ------------------------------------------------------------------ evaluation auto-metrics */
/** Training participation of a year, ranked among the active staff: ['score' => 1..5, 'attended', 'hours', 'rank', 'of']. */
function hr_eval_training_auto(int $pid, int $year): array {
    $out = ['score' => 3, 'attended' => 0, 'hours' => 0.0, 'rank' => null, 'of' => 0];
    if (!function_exists('hr_tr_has') || !hr_tr_has()) return $out;
    $rows = all('SELECT m.person_id, COUNT(*) n, COALESCE(SUM(tr.hours), 0) h FROM hr_training_members m JOIN hr_trainings tr ON tr.id = m.training_id JOIN hr_people p ON p.id = m.person_id WHERE m.status = "attended" AND p.is_active = 1 AND YEAR(COALESCE(tr.date_from, tr.created_at)) = ? GROUP BY m.person_id', [$year]);
    $active = count(array_filter(hr_people(false), fn($p) => $p['is_active'])); $out['of'] = $active;
    $pts = []; foreach ($rows as $r) $pts[(int)$r['person_id']] = (int)$r['n'] * 10 + (float)$r['h'];
    if (!$pts) return $out;                                                   // nobody trained this year — neutral
    $mine = $pts[$pid] ?? 0.0; $out['attended'] = (int)(array_column($rows, 'n', 'person_id')[$pid] ?? 0); $out['hours'] = (float)(array_column($rows, 'h', 'person_id')[$pid] ?? 0);
    if ($mine <= 0) { $out['score'] = 1; $out['rank'] = null; return $out; }
    $better = count(array_filter($pts, fn($v) => $v > $mine)); $out['rank'] = $better + 1; $pct = $active > 0 ? $better / $active : 0;
    $out['score'] = $pct < 0.2 ? 5 : ($pct < 0.4 ? 4 : ($pct < 0.7 ? 3 : 2));
    return $out;
}
/** Attendance consistency of a year: early leaves / late arrivals (hours), unplanned absences (days), sick days (shown, not scored), last-moment requests (asked less than 2 days ahead). */
function hr_eval_attendance_auto(int $pid, int $year): array {
    $out = ['score' => 5, 'early_h' => 0.0, 'absent_d' => 0.0, 'sick_d' => 0.0, 'late_req' => 0, 'req' => 0];
    if (!function_exists('hr2_has') || !hr2_has()) return $out; $y0 = "$year-01-01"; $y1 = "$year-12-31";
    $r = one('SELECT COALESCE(SUM(CASE WHEN t.code = "EARLY" THEN r.hours END), 0) eh, COALESCE(SUM(CASE WHEN t.code IN ("UNP","ABS") THEN r.days END), 0) ad, COALESCE(SUM(CASE WHEN t.kind = "sick" THEN r.days END), 0) sd FROM hr_requests r JOIN hr_leave_types t ON t.id = r.type_id WHERE r.person_id = ? AND r.status = "approved" AND r.date_from BETWEEN ? AND ?', [$pid, $y0, $y1]) ?: [];
    $out['early_h'] = (float)($r['eh'] ?? 0); $out['absent_d'] = (float)($r['ad'] ?? 0); $out['sick_d'] = (float)($r['sd'] ?? 0);
    $l = one('SELECT COUNT(*) n, SUM(DATEDIFF(r.date_from, DATE(r.created_at)) < 2) late FROM hr_requests r JOIN hr_leave_types t ON t.id = r.type_id WHERE r.person_id = ? AND r.status IN ("approved","pending_mgr","pending_hr") AND t.unit = "day" AND t.hr_only = 0 AND t.kind = "leave" AND r.period_id IS NULL AND r.created_by = COALESCE(r.user_id, -1) AND r.date_from BETWEEN ? AND ?', [$pid, $y0, $y1]) ?: [];
    $out['req'] = (int)($l['n'] ?? 0); $out['late_req'] = (int)($l['late'] ?? 0);
    $pen = (int) floor($out['early_h'] / 16) + (int) floor($out['absent_d'] / 3) + (int) floor($out['late_req'] / 3);
    $out['score'] = max(1, 5 - $pen);
    return $out;
}
/** Both auto criteria for the evaluation form and the employee's page. */
function hr_eval_auto(int $pid, int $year): array { return ['training_auto' => hr_eval_training_auto($pid, $year), 'attendance_auto' => hr_eval_attendance_auto($pid, $year)]; }
function hr_eval_auto_text(string $key, array $a): string {
    if ($key === 'training_auto') return t('hr.ea_training_txt', ['n' => $a['attended'], 'h' => rtrim(rtrim(number_format((float)$a['hours'], 1, '.', ''), '0'), '.'), 'r' => $a['rank'] !== null ? $a['rank'] . ' / ' . $a['of'] : '—']);
    return t('hr.ea_attendance_txt', ['e' => rtrim(rtrim(number_format((float)$a['early_h'], 1, '.', ''), '0'), '.'), 'a' => rtrim(rtrim(number_format((float)$a['absent_d'], 1, '.', ''), '0'), '.'), 's' => rtrim(rtrim(number_format((float)$a['sick_d'], 1, '.', ''), '0'), '.'), 'l' => $a['late_req'], 'n' => $a['req']]);
}
/** The policy note HR writes (shown to every employee under "How we evaluate you"). */
function hr_eval_policy(): string { return (string)(cp_setting('hr_eval_policy') ?? ''); }

/* ------------------------------------------------------------------ targets */
const HR_TG_STATUS = ['open', 'on_track', 'at_risk', 'achieved', 'missed', 'cancelled'];
function hr_tg_pill(string $s): string { return pill(['open' => 'mute', 'on_track' => 'green', 'at_risk' => 'gold', 'achieved' => 'navy', 'missed' => 'bad', 'cancelled' => 'mute'][$s] ?? 'mute', t('hr.tg_s_' . $s)); }
/** May the current user manage targets: HR / Owner / admin for everything; a manager for his discipline(s) and his people. */
function hr_tg_can_manage(?array $tg = null): bool {
    if (!is_staff() || !hr_targets_has()) return false; if (hr_can()) return true; $me = (int)(current_user()['id'] ?? 0); if (!function_exists('hr_is_manager') || !hr_is_manager()) return false;
    if ($tg === null) return true;
    if ($tg['scope'] === 'company') return false;
    if ($tg['scope'] === 'department') return (bool) scalar('SELECT COUNT(*) FROM disciplines WHERE id = ? AND manager_user_id = ?', [(int)$tg['department_id'], $me]);
    $p = hr_person((int)$tg['person_id']); return $p && (int)(hr_manager_of($p) ?? 0) === $me;
}
function hr_targets(array $f = []): array {
    if (!hr_targets_has()) return []; $w = ['1=1']; $b = [];
    if (!empty($f['year'])) { $w[] = 't.year = ?'; $b[] = (int)$f['year']; }
    if (!empty($f['scope'])) { $w[] = 't.scope = ?'; $b[] = $f['scope']; }
    if (!empty($f['department_id'])) { $w[] = 't.department_id = ?'; $b[] = (int)$f['department_id']; }
    if (!empty($f['person_id'])) { $w[] = 't.person_id = ?'; $b[] = (int)$f['person_id']; }
    if (!empty($f['status'])) { $w[] = 't.status = ?'; $b[] = $f['status']; }
    return all('SELECT t.*, d.name AS department, p.name_en AS person, cu.full_name AS creator FROM hr_targets t LEFT JOIN disciplines d ON d.id = t.department_id LEFT JOIN hr_people p ON p.id = t.person_id LEFT JOIN customer_users cu ON cu.id = t.created_by WHERE ' . implode(' AND ', $w) . ' ORDER BY t.year DESC, FIELD(t.scope,"company","department","person"), d.name, p.name_en, t.due_date, t.id', $b);
}
function hr_target(int $id): ?array { return hr_targets_has() ? one('SELECT * FROM hr_targets WHERE id = ?', [$id]) : null; }
/** Targets a person sees on My leave: company, his department, his own. */
function hr_targets_for(array $p, int $year): array {
    if (!hr_targets_has()) return []; return all('SELECT t.*, d.name AS department FROM hr_targets t LEFT JOIN disciplines d ON d.id = t.department_id WHERE t.year = ? AND t.status <> "cancelled" AND (t.scope = "company" OR (t.scope = "department" AND t.department_id = ?) OR (t.scope = "person" AND t.person_id = ?)) ORDER BY FIELD(t.scope,"person","department","company"), t.due_date, t.id', [$year, (int)($p['department_id'] ?? 0), (int)$p['id']]);
}
function hr_target_save_from_post(): int {
    $id = (int)($_POST['id'] ?? 0); $cur = $id ? hr_target($id) : null; if ($id && !$cur) throw new RuntimeException(t('err.notfound'));
    $scope = in_array($_POST['scope'] ?? '', ['company', 'department', 'person'], true) ? $_POST['scope'] : 'department';
    $dept = $scope === 'department' ? ((int)($_POST['department_id'] ?? 0) ?: null) : null; $pid = $scope === 'person' ? ((int)($_POST['person_id'] ?? 0) ?: null) : null;
    if ($scope === 'department' && !$dept) throw new RuntimeException(t('hr.tg_need_dept')); if ($scope === 'person' && !$pid) throw new RuntimeException(t('hr.tg_need_person'));
    $draft = ['scope' => $scope, 'department_id' => $dept, 'person_id' => $pid]; if (!hr_tg_can_manage($draft) || ($cur && !hr_tg_can_manage($cur))) throw new RuntimeException(t('err.noaccess'));
    $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null; $title = $s('title', 160); if (!$title) throw new RuntimeException(t('hr.tg_need_title'));
    $year = (int)($_POST['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null; $w = min(5, max(1, (int)($_POST['weight'] ?? 1)));
    $prog = min(100, max(0, (int)($_POST['progress'] ?? ($cur['progress'] ?? 0)))); $status = in_array($_POST['status'] ?? '', HR_TG_STATUS, true) ? $_POST['status'] : ($cur['status'] ?? 'open');
    $v = [$year, $scope, $dept, $pid, $title, $s('description', 4000), $s('measure', 160), $s('target_value', 60), $s('unit', 20), $w, $due, $prog, $status, $s('result_note', 4000)];
    if ($cur) { q('UPDATE hr_targets SET year = ?, scope = ?, department_id = ?, person_id = ?, title = ?, description = ?, measure = ?, target_value = ?, unit = ?, weight = ?, due_date = ?, progress = ?, status = ?, result_note = ? WHERE id = ?', [...$v, $id]); }
    else { q('INSERT INTO hr_targets (year, scope, department_id, person_id, title, description, measure, target_value, unit, weight, due_date, progress, status, result_note, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$v, (int)(current_user()['id'] ?? 0) ?: null]); $id = (int) db()->lastInsertId(); }
    if (!$cur || (int)$cur['progress'] !== $prog || $cur['status'] !== $status) q('INSERT INTO hr_target_log (target_id, user_id, progress, status, note) VALUES (?,?,?,?,?)', [$id, (int)(current_user()['id'] ?? 0) ?: null, $prog, $status, $cur ? null : t('hr.tg_created')]);
    // notify the people concerned when a target is created
    if (!$cur && function_exists('nf_push')) { $to = []; if ($scope === 'person') { $p = hr_person($pid); if ($p && !empty($p['user_id'])) $to[] = (int)$p['user_id']; } elseif ($scope === 'department') { $to = array_map(fn($p) => (int)$p['user_id'], array_filter(hr_people(false), fn($p) => $p['is_active'] && (int)($p['department_id'] ?? 0) === $dept && !empty($p['user_id']))); } else { $to = array_map(fn($p) => (int)$p['user_id'], array_filter(hr_people(false), fn($p) => $p['is_active'] && !empty($p['user_id']))); }
        if ($to) nf_push(array_values(array_unique($to)), 'hrtg:new', 'hrtg:' . $id, t('hr.nf_tg_new', ['t' => $title, 'y' => $year]), $s('description', 4000), url('my_leave') . '#hr-targets', (int)(current_user()['id'] ?? 0) ?: null, (string)(current_user()['full_name'] ?? '')); }
    return $id;
}
/** Progress update by the person himself (own targets only) or by whoever manages the target. */
function hr_target_progress(int $id): void {
    $tg = hr_target($id); if (!$tg) throw new RuntimeException(t('err.notfound')); $me = (int)(current_user()['id'] ?? 0);
    $own = $tg['scope'] === 'person' && ($p = hr_person((int)$tg['person_id'])) && (int)($p['user_id'] ?? 0) === $me;
    if (!$own && !hr_tg_can_manage($tg)) throw new RuntimeException(t('err.noaccess'));
    $prog = min(100, max(0, (int)($_POST['progress'] ?? $tg['progress']))); $status = in_array($_POST['status'] ?? '', HR_TG_STATUS, true) ? $_POST['status'] : $tg['status'];
    if ($own && !hr_tg_can_manage($tg) && !in_array($status, ['open', 'on_track', 'at_risk'], true)) $status = $tg['status'];   // achieved / missed is the manager's word
    q('UPDATE hr_targets SET progress = ?, status = ? WHERE id = ?', [$prog, $status, $id]);
    q('INSERT INTO hr_target_log (target_id, user_id, progress, status, note) VALUES (?,?,?,?,?)', [$id, $me ?: null, $prog, $status, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 600) ?: null]);
}
function hr_target_delete(int $id): void { $tg = hr_target($id); if (!$tg || !hr_tg_can_manage($tg)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM hr_target_log WHERE target_id = ?', [$id]); q('DELETE FROM hr_targets WHERE id = ?', [$id]); }
function hr_target_log(int $id): array { return hr_targets_has() ? all('SELECT l.*, cu.full_name FROM hr_target_log l LEFT JOIN customer_users cu ON cu.id = l.user_id WHERE l.target_id = ? ORDER BY l.id DESC', [$id]) : []; }
/** Achievement of a person's targets in a year (own + department), for the evaluation panel: ['n', 'achieved', 'avg_progress']. */
function hr_targets_summary(array $p, int $year): array { $t = hr_targets_for($p, $year); $n = count($t); return ['n' => $n, 'achieved' => count(array_filter($t, fn($x) => $x['status'] === 'achieved')), 'avg' => $n ? (int) round(array_sum(array_column($t, 'progress')) / $n) : 0, 'rows' => $t]; }
/** Progress bar HTML. */
function hr_tg_bar(int $pct, string $status): string { $c = $status === 'achieved' ? '#2E7D32' : ($status === 'missed' ? '#B23B3B' : ($status === 'at_risk' ? '#C9A227' : '#1F3A5F')); return '<div style="height:6px;background:rgba(127,127,127,.18);border-radius:3px;overflow:hidden;min-width:80px;"><div style="width:' . max(0, min(100, $pct)) . '%;height:100%;background:' . $c . ';"></div></div>'; }

<?php
/**
 * Tasks and reminders — Work › Tasks.                                        GAS-CP-R22-002
 *
 * Staff keep tasks per project / sub-project / vessel with a due date and time,
 * a recurrence (daily · weekly on chosen weekdays · monthly on a day · yearly,
 * every n) and a reminder (n minutes / hours / days before). Like Microsoft To Do,
 * a recurring task is ONE row: completing or skipping it moves due_at to the next
 * occurrence; task_log keeps every completion.
 *
 * Reminders ride the notification sweep (nf_sweep → td_remind): when remind_at
 * (= due_at − remind_before) has passed, one notification goes to the assignee
 * or, with no assignee, to the project team (project manager + administrators).
 * Overdue tasks are reminded again once a day.
 *
 * td_seed_reports() creates the standing report reminders every portal needs:
 * per active project a weekly report (Fri), a monthly report (1st), timesheets
 * (Mon) and the vessel history (quarterly); per sub-project a weekly progress
 * update (Thu). Existing ones are not duplicated.
 */
declare(strict_types=1);

function td_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'tasks'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
const TD_BUILTIN_KINDS = ['general' => 'mute', 'remote' => 'navy', 'weekly' => 'gold', 'monthly' => 'navy', 'progress' => 'green', 'timesheet' => 'blue', 'vessel' => 'navy', 'fsr' => 'green', 'offer' => 'gold', 'ticket' => 'blue', 'class' => 'mute', 'drawings' => 'mute'];
function td_kind_table(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'task_kinds'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** All task types (built-in with translated labels + the administrators' own), keyed by slug.   R22-006 */
function td_kind_defs(bool $activeOnly = false): array {
    static $c = null;
    if ($c === null || !empty($GLOBALS['td_kind_dirty'])) {
        $c = []; $GLOBALS['td_kind_dirty'] = false;
        if (td_kind_table()) {
            foreach (all('SELECT * FROM task_kinds ORDER BY sort_order, label') as $r) $c[$r['skey']] = ['label' => $r['is_builtin'] ? t('td.k_' . $r['skey']) : $r['label'], 'tone' => $r['tone'], 'sort_order' => (int)$r['sort_order'], 'is_builtin' => (bool)$r['is_builtin'], 'is_active' => (bool)$r['is_active']];
        }
        if (!$c) { $i = 0; foreach (TD_BUILTIN_KINDS as $k => $tone) $c[$k] = ['label' => t('td.k_' . $k), 'tone' => $tone, 'sort_order' => ++$i * 10, 'is_builtin' => true, 'is_active' => true]; }
    }
    return $activeOnly ? array_filter($c, fn($d) => $d['is_active']) : $c;
}
function td_kinds(bool $activeOnly = true): array { return array_map(fn($d) => $d['label'], td_kind_defs($activeOnly)); }
function td_kind_pill(string $k): string {
    $d = td_kind_defs()[$k] ?? null;
    return pill($d['tone'] ?? 'mute', $d['label'] ?? $k);
}
function td_kind_save(): string {
    require_cp_admin();
    if (!td_kind_table()) throw new RuntimeException(t('tdk.need_db'));
    $key = (string)($_POST['skey'] ?? ''); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
    $tone = in_array($_POST['tone'] ?? '', ['gold', 'green', 'blue', 'navy', 'mute', 'bad'], true) ? $_POST['tone'] : 'mute';
    $order = (int)($_POST['sort_order'] ?? 50); $active = (int)!empty($_POST['is_active']);
    $defs = td_kind_defs();
    if ($key !== '' && isset($defs[$key])) {
        if ($defs[$key]['is_builtin']) q('UPDATE task_kinds SET tone = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$tone, $order, $key === 'general' ? 1 : $active, $key]);
        else { if ($label === '') throw new RuntimeException(t('tdk.need_label')); q('UPDATE task_kinds SET label = ?, tone = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$label, $tone, $order, $active, $key]); }
        return $key;
    }
    if ($label === '') throw new RuntimeException(t('tdk.need_label'));
    $key = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label)), 0, 26); $key = trim($key, '_') ?: 'k' . time();
    $base = $key; $n = 1; while (isset($defs[$key])) $key = $base . '_' . (++$n);
    q('INSERT INTO task_kinds (skey, label, tone, sort_order, is_builtin, is_active) VALUES (?,?,?,?,0,1)', [$key, $label, $tone, $order]);
    $GLOBALS['td_kind_dirty'] = true;
    return $key;
}
function td_kind_delete(string $key): void {
    require_cp_admin();
    $d = td_kind_defs()[$key] ?? null; if (!$d || $d['is_builtin']) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM tasks WHERE kind = ?', [$key])) throw new RuntimeException(t('tdk.in_use'));
    q('DELETE FROM task_kinds WHERE skey = ?', [$key]);
}
function td_recurs(): array { return ['none' => t('td.r_none'), 'daily' => t('td.r_daily'), 'weekly' => t('td.r_weekly'), 'monthly' => t('td.r_monthly'), 'yearly' => t('td.r_yearly')]; }
function td_reminders(): array {
    return [0 => t('td.rem_0'), 15 => t('td.rem_15m'), 30 => t('td.rem_30m'), 60 => t('td.rem_1h'), 120 => t('td.rem_2h'), 1440 => t('td.rem_1d'), 2880 => t('td.rem_2d'), 10080 => t('td.rem_1w')];
}
function td_weekdays(): array { return [1 => t('wd.mon'), 2 => t('wd.tue'), 3 => t('wd.wed'), 4 => t('wd.thu'), 5 => t('wd.fri'), 6 => t('wd.sat'), 7 => t('wd.sun')]; }

/* ------------------------------------------------------------ recurrence */

/** Next occurrence strictly after $after for this task's rule, or null when the series has ended. */
function td_next(array $t, string $after): ?string {
    $base = new DateTime($t['due_at']); $every = max(1, (int)$t['recur_every']); $time = $base->format('H:i:s');
    $aft = new DateTime($after); $end = $t['recur_end'] ? new DateTime($t['recur_end'] . ' 23:59:59') : null;
    $cand = clone $base; $guard = 0;
    while ($cand <= $aft && $guard++ < 4000) {
        switch ($t['recur']) {
            case 'daily':   $cand->modify("+{$every} day"); break;
            case 'weekly': {
                $days = array_filter(array_map('intval', explode(',', (string)($t['recur_days'] ?: $base->format('N')))));
                sort($days); $dow = (int)$cand->format('N'); $moved = false;
                foreach ($days as $d) if ($d > $dow) { $cand->modify('+' . ($d - $dow) . ' day'); $moved = true; break; }
                if (!$moved) { $cand->modify('+' . (7 * $every - $dow + $days[0]) . ' day'); }
                break;
            }
            case 'monthly': {
                $dom = (int)($t['recur_dom'] ?: $base->format('j'));
                $m = new DateTime($cand->format('Y-m-01') . ' ' . $time); $m->modify("+{$every} month");
                $m->setDate((int)$m->format('Y'), (int)$m->format('n'), min($dom, (int)$m->format('t')));
                $cand = $m; break;
            }
            case 'yearly': {
                $y = clone $cand; $y->modify("+{$every} year");
                if ($base->format('m-d') === '02-29' && $y->format('m-d') !== '02-29') $y->setDate((int)$y->format('Y'), 2, 28);
                $cand = $y; break;
            }
            default: return null;
        }
    }
    if ($end && $cand > $end) return null;
    return $cand->format('Y-m-d H:i:s');
}
function td_recur_label(array $t): string {
    if ($t['recur'] === 'none') return '';
    $n = max(1, (int)$t['recur_every']);
    $s = $n === 1 ? t('td.r_' . $t['recur']) : t('td.r_every_' . $t['recur'], ['n' => $n]);
    if ($t['recur'] === 'weekly' && $t['recur_days']) { $wd = td_weekdays(); $s .= ' · ' . implode(' ', array_map(fn($d) => $wd[(int)$d] ?? '', explode(',', (string)$t['recur_days']))); }
    if ($t['recur'] === 'monthly' && $t['recur_dom']) $s .= ' · ' . ((int)$t['recur_dom'] === 31 ? t('td.last_day') : t('td.on_day', ['n' => (int)$t['recur_dom']]));
    if ($t['recur_end']) $s .= ' · ' . t('td.until', ['d' => fmt_date($t['recur_end'])]);
    return $s;
}

/* ------------------------------------------------------------------- CRUD */

/** tasks.offer_id present (R28-001)? */
function td_offer_col(): bool { static $h = null; if ($h === null) { try { $h = td_has() && (bool) scalar("SHOW COLUMNS FROM tasks LIKE 'offer_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function td_load(int $id): ?array {
    if (!td_has() || !$id) return null;
    $of = td_offer_col() ? ', ofr.offer_no, ofr.revision AS offer_rev, ofr.title AS offer_title' : ', NULL AS offer_no, NULL AS offer_rev, NULL AS offer_title'; $oj = td_offer_col() ? ' LEFT JOIN offers ofr ON ofr.id = t.offer_id' : '';
    return one("SELECT t.*, p.name AS project_name, p.erp_code, s.name AS subproject_name, v.name AS vessel_name, a.full_name AS assignee, o.full_name AS owner{$of}
                FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = t.subproject_id LEFT JOIN vessels v ON v.id = t.vessel_id
                LEFT JOIN customer_users a ON a.id = t.assignee_id LEFT JOIN customer_users o ON o.id = t.owner_id{$oj} WHERE t.id = ?", [$id]);
}
function td_save_from_post(): int {
    $u = require_staff();
    $id = (int)($_POST['id'] ?? 0);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if ($title === '') throw new RuntimeException(t('td.need_title'));
    $kind = (string)($_POST['kind'] ?? 'general');
    if ($kind === '__new' && is_cp_admin() && td_kind_table() && trim((string)($_POST['new_kind'] ?? '')) !== '') { $_POST['skey'] = ''; $_POST['label'] = $_POST['new_kind']; $_POST['tone'] = 'gold'; $_POST['sort_order'] = 120; $kind = td_kind_save(); }   // R22-006 quick-add type
    if (!isset(td_kinds(false)[$kind])) $kind = 'general';
    $pid = (int)($_POST['project_id'] ?? 0) ?: null; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if ($sid) { $sp = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) $sid = null; elseif (!$pid) $pid = (int)$sp['project_id']; elseif ((int)$sp['project_id'] !== $pid) $sid = null; }
    $vid = $pid ? (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]) ?: null : ((int)($_POST['vessel_id'] ?? 0) ?: null);
    $date = substr((string)($_POST['due_date'] ?? ''), 0, 10); $time = substr((string)($_POST['due_time'] ?? '09:00'), 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new RuntimeException(t('td.need_due'));
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) $time = '09:00';
    $due = $date . ' ' . $time . ':00';
    $recur = (string)($_POST['recur'] ?? 'none'); if (!isset(td_recurs()[$recur])) $recur = 'none';
    $every = max(1, min(99, (int)($_POST['recur_every'] ?? 1)));
    $days = null; if ($recur === 'weekly') { $d = array_filter(array_map('intval', (array)($_POST['recur_days'] ?? [])), fn($x) => $x >= 1 && $x <= 7); sort($d); $days = $d ? implode(',', array_unique($d)) : (string) date('N', strtotime($due)); }
    $dom = $recur === 'monthly' ? max(1, min(31, (int)($_POST['recur_dom'] ?? date('j', strtotime($due))))) : null;
    $rend = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['recur_end'] ?? '')) && $recur !== 'none' ? (string)$_POST['recur_end'] : null;
    $rem = (int)($_POST['remind_before'] ?? 0); if (!isset(td_reminders()[$rem])) $rem = 0;
    $prio = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';
    $ass = (int)($_POST['assignee_id'] ?? 0) ?: null;
    if ($ass && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$ass])) $ass = null;
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 4000) ?: null;
    // weekly rule: the first occurrence must fall on one of the chosen weekdays
    if ($recur === 'weekly' && $days && !in_array((int) date('N', strtotime($due)), array_map('intval', explode(',', $days)), true)) {
        $probe = ['due_at' => $due, 'recur' => 'weekly', 'recur_every' => 1, 'recur_days' => $days, 'recur_end' => null];
        $due = td_next($probe, $due) ?? $due;
    }
    $ofId = null;
    if (td_offer_col() && ($x = (int)($_POST['offer_id'] ?? 0))) { $of = one('SELECT id, project_id, subproject_id FROM offers WHERE id = ?', [$x]); if ($of) { $ofId = (int)$of['id']; if (!$pid) $pid = (int)$of['project_id']; if (!$sid && $of['subproject_id']) $sid = (int)$of['subproject_id']; if ($pid !== (int)$of['project_id']) $ofId = null; } }
    $args = [$title, $notes, $kind, $pid, $sid, $vid, $ass, $prio, $due, $recur, $every, $days, $dom, $rend, $rem];
    if ($id) {
        $old = td_load($id); if (!$old) throw new RuntimeException(t('err.notfound'));
        q('UPDATE tasks SET reminded_at = IF(due_at <> ?, NULL, reminded_at), title=?, notes=?, kind=?, project_id=?, subproject_id=?, vessel_id=?, assignee_id=?, priority=?, due_at=?, recur=?, recur_every=?, recur_days=?, recur_dom=?, recur_end=?, remind_before=?,
             status = IF(status = \'done\' AND recur <> \'none\', \'open\', status) WHERE id = ?', array_merge([$due], $args, [$id]));
        if (td_offer_col()) q('UPDATE tasks SET offer_id = ? WHERE id = ?', [$ofId, $id]);
        if (function_exists('rs_has') && rs_has() && isset($_POST['rs_contract_id'])) q('UPDATE tasks SET rs_contract_id = ? WHERE id = ?', [(int)$_POST['rs_contract_id'] ?: null, $id]);
        td_log($id, 'edited', $due, (int)$u['id']);
    } else {
        q('INSERT INTO tasks (title, notes, kind, project_id, subproject_id, vessel_id, assignee_id, priority, due_at, recur, recur_every, recur_days, recur_dom, recur_end, remind_before, owner_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($args, [(int)$u['id']]));
        $id = (int) db()->lastInsertId();
        if ($ofId) q('UPDATE tasks SET offer_id = ? WHERE id = ?', [$ofId, $id]);
        if (function_exists('rs_has') && rs_has() && (int)($_POST['rs_contract_id'] ?? 0)) q('UPDATE tasks SET rs_contract_id = ? WHERE id = ?', [(int)$_POST['rs_contract_id'], $id]);
        td_log($id, 'created', $due, (int)$u['id']);
    }
    if (function_exists('eq_task_link')) eq_task_link($id, (array)($_POST['eq_ids'] ?? []), $vid);   // R27-003
    return $id;
}
function td_log(int $id, string $ev, ?string $due, ?int $by, ?string $note = null): void {
    q('INSERT INTO task_log (task_id, event, due_at, by_id, note) VALUES (?,?,?,?,?)', [$id, $ev, $due, $by, $note]);
}
/** Complete the current occurrence: a recurring task moves on, a single one closes. */
function td_done(int $id, bool $skip = false): void {
    $u = require_staff(); $t = td_load($id); if (!$t) throw new RuntimeException(t('err.notfound'));
    td_log($id, $skip ? 'skipped' : 'done', $t['due_at'], (int)$u['id']);
    $next = $t['recur'] !== 'none' ? td_next($t, max($t['due_at'], date('Y-m-d H:i:s'))) : null;
    if ($next) q('UPDATE tasks SET due_at = ?, reminded_at = NULL, status = \'open\', done_at = NULL, done_by = NULL WHERE id = ?', [$next, $id]);
    else q('UPDATE tasks SET status = \'done\', done_at = NOW(), done_by = ? WHERE id = ?', [(int)$u['id'], $id]);
    if (function_exists('eq_task_done')) eq_task_done($id, $skip);   // R27-003: one service record per linked unit
}
function td_reopen(int $id): void {
    $u = require_staff(); if (!td_load($id)) throw new RuntimeException(t('err.notfound'));
    q('UPDATE tasks SET status = \'open\', done_at = NULL, done_by = NULL, reminded_at = NULL WHERE id = ?', [$id]);
    td_log($id, 'reopened', null, (int)$u['id']);
    if (function_exists('eq_task_reopen')) eq_task_reopen($id);
}
function td_delete(int $id): void {
    $u = require_staff(); $t = td_load($id); if (!$t) return;
    if (!is_cp_admin() && (int)$t['owner_id'] !== (int)$u['id']) throw new RuntimeException(t('td.only_owner'));
    q('DELETE FROM task_log WHERE task_id = ?', [$id]); q('DELETE FROM tasks WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------ lists */

function td_people(array $t): array {
    if (!empty($t['assignee_id'])) return [(int)$t['assignee_id']];
    return nf_project_staff((int)($t['project_id'] ?? 0));
}
function td_where(array $f, array &$b): string {
    $u = current_user(); $w = ['1 = 1'];
    $mine = '(t.assignee_id = ? OR (t.assignee_id IS NULL AND (t.owner_id = ? OR EXISTS (SELECT 1 FROM projects pp WHERE pp.id = t.project_id AND pp.pm_user_id = ?))' . (is_cp_admin() ? ' OR t.assignee_id IS NULL' : '') . '))';
    switch ($f['view'] ?? 'myday') {
        case 'myday':   $w[] = "t.status = 'open' AND t.due_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND $mine"; array_push($b, $u['id'], $u['id'], $u['id']); break;
        case 'mine':    $w[] = "t.status = 'open' AND $mine"; array_push($b, $u['id'], $u['id'], $u['id']); break;
        case 'planned': $w[] = "t.status = 'open'"; break;
        case 'done':    $w[] = "t.status = 'done'"; break;
        default:        break;                                           // all
    }
    if (!empty($f['project'])) { $w[] = 't.project_id = ?'; $b[] = (int)$f['project']; }
    if (!empty($f['sub'])) { $w[] = 't.subproject_id = ?'; $b[] = (int)$f['sub']; }
    if (!empty($f['kind']) && isset(td_kinds()[$f['kind']])) { $w[] = 't.kind = ?'; $b[] = $f['kind']; }
    if (!empty($f['who'])) { $w[] = 't.assignee_id = ?'; $b[] = (int)$f['who']; }
    if (!empty($f['q'])) { $w[] = '(t.title LIKE ? OR t.notes LIKE ? OR p.name LIKE ? OR s.name LIKE ?)'; $l = '%' . $f['q'] . '%'; array_push($b, $l, $l, $l, $l); }
    return implode(' AND ', $w);
}
function td_list(array $f, int $limit = 400): array {
    if (!td_has()) return [];
    $b = []; $w = td_where($f, $b);
    $order = ($f['view'] ?? '') === 'done' ? 't.done_at DESC' : "t.due_at, FIELD(t.priority, 'high', 'normal', 'low')";
    $of = td_offer_col() ? ', ofr.offer_no' : ', NULL AS offer_no'; $oj = td_offer_col() ? ' LEFT JOIN offers ofr ON ofr.id = t.offer_id' : '';
    return all("SELECT t.*, p.name AS project_name, p.erp_code, s.name AS subproject_name, v.name AS vessel_name, a.full_name AS assignee{$of}
                FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = t.subproject_id LEFT JOIN vessels v ON v.id = t.vessel_id LEFT JOIN customer_users a ON a.id = t.assignee_id{$oj}
                WHERE $w ORDER BY $order LIMIT $limit", $b);
}
/** Bucket label for the Planned view: overdue · today · tomorrow · this week · later. */
function td_bucket(string $due): string {
    $d = substr($due, 0, 10); $today = date('Y-m-d');
    if ($d < $today) return 'overdue';
    if ($d === $today) return 'today';
    if ($d === date('Y-m-d', strtotime('+1 day'))) return 'tomorrow';
    if ($d <= date('Y-m-d', strtotime('sunday this week'))) return 'week';
    if ($d <= date('Y-m-d', strtotime('+30 day'))) return 'month';
    return 'later';
}
/** Badge for the menu: my open tasks due today or overdue. */
function td_badge(): int {
    if (!td_has() || !is_staff()) return 0;
    $b = []; $w = td_where(['view' => 'myday'], $b);
    try { return (int) scalar("SELECT COUNT(*) FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = t.subproject_id WHERE $w", $b); } catch (Throwable $e) { return 0; }
}
function td_context(array $t): string {
    $parts = [];
    if (!empty($t['project_name'])) $parts[] = ($t['erp_code'] ? $t['erp_code'] . ' · ' : '') . $t['project_name'];
    if (!empty($t['subproject_name'])) $parts[] = $t['subproject_name'];
    if (!empty($t['offer_no'])) $parts[] = t('of.one') . ' ' . $t['offer_no'];
    if (empty($t['project_name']) && !empty($t['vessel_name'])) $parts[] = $t['vessel_name'];
    return implode(' › ', $parts);
}

/* -------------------------------------------------------------- reminders */

/** Called from nf_sweep. Reminds once when remind_at passes, then once a day while overdue. */
function td_remind(): int {
    if (!td_has() || !function_exists('nf_push')) return 0;
    $rows = all("SELECT t.*, p.name AS project_name, p.erp_code, s.name AS subproject_name, v.name AS vessel_name
                 FROM tasks t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = t.subproject_id LEFT JOIN vessels v ON v.id = t.vessel_id
                 WHERE t.status = 'open' AND DATE_SUB(t.due_at, INTERVAL t.remind_before MINUTE) <= NOW()
                   AND (t.reminded_at IS NULL OR (t.due_at < NOW() AND t.reminded_at < DATE_SUB(NOW(), INTERVAL 1 DAY))) LIMIT 200");
    $n = 0;
    foreach ($rows as $t) {
        $over = $t['due_at'] < date('Y-m-d H:i:s');
        $kind = $over ? 'td:overdue' : 'td:due';
        $ctx = td_context($t);
        $n += nf_push(td_people($t), $kind, 'td:' . $t['id'], $t['title'] . ($ctx ? ' — ' . $ctx : ''), t('td.due_on', ['d' => fmt_datetime($t['due_at'])]) . ($t['recur'] !== 'none' ? ' · ↻ ' . td_recur_label($t) : ''), url('tasks', ['view' => 'mine', 'hl' => $t['id']]), null, null);
        q('UPDATE tasks SET reminded_at = NOW() WHERE id = ?', [$t['id']]);
        td_log((int)$t['id'], 'reminded', $t['due_at'], null);
    }
    return $n;
}

/* ------------------------------------------------------------------ seeds */

/** Standing report reminders for every active project and sub-project; returns how many were created. */
function td_seed_reports(array $which, ?int $assignee = null): int {
    $u = require_staff(); $n = 0;
    $projects = all("SELECT p.id, p.name, p.vessel_id, p.pm_user_id FROM projects p WHERE p.status = 'active' ORDER BY p.name");
    $nextDow = function (int $dow, string $time): string { $d = new DateTime('today ' . $time); while ((int)$d->format('N') !== $dow || $d <= new DateTime()) $d->modify('+1 day'); return $d->format('Y-m-d H:i:s'); };
    $firstOfNext = fn(string $time) => (new DateTime('first day of next month ' . $time))->format('Y-m-d H:i:s');
    $add = function (array $row) use (&$n, $u, $assignee): void {
        $ex = scalar('SELECT COUNT(*) FROM tasks WHERE kind = ? AND project_id = ? AND subproject_id <=> ? AND status = \'open\' AND recur <> \'none\'', [$row['kind'], $row['project_id'], $row['subproject_id']]);
        if ($ex) return;
        q('INSERT INTO tasks (title, notes, kind, project_id, subproject_id, vessel_id, assignee_id, due_at, recur, recur_every, recur_days, recur_dom, remind_before, owner_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$row['title'], $row['notes'], $row['kind'], $row['project_id'], $row['subproject_id'], $row['vessel_id'], $assignee ?: $row['assignee'], $row['due_at'], $row['recur'], 1, $row['days'] ?? null, $row['dom'] ?? null, $row['remind'], (int)$u['id']]);
        td_log((int) db()->lastInsertId(), 'created', $row['due_at'], (int)$u['id'], 'seed');
        $n++;
    };
    foreach ($projects as $p) {
        $base = ['project_id' => (int)$p['id'], 'subproject_id' => null, 'vessel_id' => $p['vessel_id'] ? (int)$p['vessel_id'] : null, 'assignee' => $p['pm_user_id'] ? (int)$p['pm_user_id'] : null, 'notes' => null];
        if (in_array('weekly', $which, true))    $add($base + ['kind' => 'weekly',    'title' => t('td.seed_weekly', ['p' => $p['name']]),    'due_at' => $nextDow(5, '15:00'), 'recur' => 'weekly',  'days' => '5', 'remind' => 120]);
        if (in_array('monthly', $which, true))   $add($base + ['kind' => 'monthly',   'title' => t('td.seed_monthly', ['p' => $p['name']]),   'due_at' => $firstOfNext('10:00'), 'recur' => 'monthly', 'dom' => 1, 'remind' => 1440]);
        if (in_array('timesheet', $which, true)) $add($base + ['kind' => 'timesheet', 'title' => t('td.seed_timesheet', ['p' => $p['name']]), 'due_at' => $nextDow(1, '10:00'), 'recur' => 'weekly',  'days' => '1', 'remind' => 60]);
        if (in_array('vessel', $which, true) && $p['vessel_id']) $add($base + ['kind' => 'vessel', 'title' => t('td.seed_vessel', ['p' => $p['name']]), 'due_at' => $firstOfNext('11:00'), 'recur' => 'monthly', 'dom' => 1, 'remind' => 1440, 'notes' => t('td.seed_vessel_note')]);
        if (in_array('progress', $which, true)) {
            foreach (all('SELECT id, name FROM subprojects WHERE project_id = ? ORDER BY name', [$p['id']]) as $s)
                $add($base + ['kind' => 'progress', 'subproject_id' => (int)$s['id'], 'title' => t('td.seed_progress', ['p' => $p['name'], 's' => $s['name']]), 'due_at' => $nextDow(4, '12:00'), 'recur' => 'weekly', 'days' => '4', 'remind' => 120]);
        }
    }
    return $n;
}

/* ------------------------------------------------------- embedded card */

/** Compact open-task list for the project tree and the sub-project page (staff only).   R22-005 */
function td_card_html(array $f, ?string $newUrl, bool $showSub = true, int $limit = 8): string {
    if (!td_has() || !is_staff()) return '';
    $f['view'] = 'planned'; $rows = td_list($f, $limit + 1); $more = count($rows) > $limit; $rows = array_slice($rows, 0, $limit);
    $allUrl = url('tasks', array_filter(['view' => 'planned', 'project' => $f['project'] ?? 0, 'sub' => $f['sub'] ?? 0]));
    $h = '<div class="td-block"><div class="page-head" style="margin-bottom:6px;"><h3 class="ed-h" style="margin:0;"><a href="' . $allUrl . '" style="text-decoration:none;color:inherit;">' . tt('td.title') . '</a> <span>' . count($rows) . ($more ? '+' : '') . '</span></h3>'
       . ($newUrl ? '<a class="btn btn--ghost btn--sm" href="' . $newUrl . '">+ ' . tt('td.new') . '</a>' : '') . '</div>';
    if (!$rows) return $h . '<p class="hint" style="margin:0;">' . tt('td.none_here') . '</p></div>';
    $h .= '<ul class="td-mini">';
    foreach ($rows as $t) {
        $over = $t['due_at'] < date('Y-m-d H:i:s');
        $h .= '<li class="' . ($over ? 'is-over' : '') . '"><form method="post" action="' . url('tasks', ['view' => 'planned', 'project' => (int)($t['project_id'] ?? 0)]) . '" class="td-check">' . csrf_field() . '<input type="hidden" name="id" value="' . (int)$t['id'] . '"><button class="td-tick" type="submit" name="action" value="td_done" title="' . tt('td.done') . '" aria-label="' . tt('td.done') . '"><span></span></button></form>'
            . '<span class="td-mini-main"><a class="td-title" href="' . url('task_edit', ['id' => $t['id']]) . '">' . e($t['title']) . '</a><span class="td-meta">' . td_kind_pill($t['kind'])
            . ($showSub && !empty($t['subproject_name']) ? '<span class="td-ctx">' . e($t['subproject_name']) . '</span>' : '')
            . '<span class="td-due' . ($over ? ' is-over' : '') . '">⏰ ' . e(fmt_datetime($t['due_at'])) . '</span>' . ($t['recur'] !== 'none' ? '<span class="td-rec">↻ ' . e(td_recur_label($t)) . '</span>' : '') . '<span class="td-who">👤 ' . e($t['assignee'] ?: t('td.team')) . '</span></span></span></li>';
    }
    $h .= '</ul>' . ($more ? '<p class="hint" style="margin:6px 0 0;"><a href="' . $allUrl . '">' . tt('td.see_all') . ' ›</a></p>' : '') . '</div>';
    return $h;
}

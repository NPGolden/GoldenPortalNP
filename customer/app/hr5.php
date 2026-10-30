<?php
/** HR › Leaves — company-wide leave view per period, collective leave periods (groups), assistant questions.   GAS-CP-R61-001 */
declare(strict_types=1);

function hr_lv_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_leave_periods'"); } catch (Throwable $e) { $h = false; } } return $h; }

/** Everybody's absences in a date range: [person_id][date] => ['code','kind','status','hours'] from requests (approved + pending) and board-only entries. */
function hr_lv_matrix(string $from, string $to, array $people): array {
    $m = []; $ids = array_map(fn($p) => (int)$p['id'], $people); if (!$ids) return $m;
    $in = implode(',', $ids); $types = hr_types(false);
    foreach (all("SELECT r.person_id, r.type_id, r.date_from, r.date_to, r.status, r.hours FROM hr_requests r WHERE r.person_id IN ($in) AND r.status IN ('approved','pending_mgr','pending_hr') AND r.date_from <= ? AND r.date_to >= ?", [$to, $from]) as $r) {
        $t = $types[(int)$r['type_id']] ?? null; if (!$t) continue;
        for ($d = max(strtotime($r['date_from']), strtotime($from)); $d <= min(strtotime($r['date_to']), strtotime($to)); $d += 86400) { $k = date('Y-m-d', $d); $m[(int)$r['person_id']][$k] = ['code' => $t['code'], 'kind' => $t['kind'], 'status' => $r['status'], 'hours' => $r['hours'], 'name' => $t['name']]; }
    }
    $byUser = []; foreach ($people as $p) if ($p['user_id']) $byUser[(int)$p['user_id']] = (int)$p['id'];
    if ($byUser) foreach (all('SELECT e.user_id, e.plan_date, e.kind, e.hours, t.code, t.name FROM plan_entries e LEFT JOIN hr_leave_types t ON t.id = e.leave_type_id WHERE e.user_id IN (' . implode(',', array_keys($byUser)) . ") AND e.kind <> 'work' AND e.request_id IS NULL AND e.plan_date BETWEEN ? AND ?", [$from, $to]) as $e) {
        $pid = $byUser[(int)$e['user_id']]; if (isset($m[$pid][$e['plan_date']])) continue; $m[$pid][$e['plan_date']] = ['code' => $e['code'] ?: t('pl.k_' . $e['kind']), 'kind' => $e['kind'], 'status' => 'board', 'hours' => $e['hours'], 'name' => $e['name'] ?? ''];
    }
    return $m;
}
/** Totals per type code over the matrix (days; hour types in hours). */
function hr_lv_totals(array $m): array { $t = []; foreach ($m as $days) foreach ($days as $c) { $k = $c['code']; $t[$k] = ($t[$k] ?? 0) + ($c['hours'] ? (float)$c['hours'] : 1); } arsort($t); return $t; }
/** Plain-text summary of the period for the assistant: who is away when, per discipline, with balances. */
function hr_lv_summary(string $from, string $to, array $people, array $m, int $year): string {
    $s = "Leave overview " . fmt_date($from) . ' – ' . fmt_date($to) . " (" . count($people) . " people)\n";
    foreach ($people as $p) { $pid = (int)$p['id']; $days = $m[$pid] ?? []; $b = hr_annual_type_id() ? hr_balance($p, hr_annual_type_id(), $year) : null;
        $s .= '- ' . $p['name_en'] . ' (' . ($p['department'] ?: '—') . ')' . ($b && $b['available'] !== null ? ' · annual ' . $year . ': entitled ' . ($b['entitled'] + $b['carry']) . ', taken ' . ($b['taken'] + $b['opening']) . ', pending ' . $b['pending'] . ', available ' . $b['available'] : '');
        if ($days) { ksort($days); $runs = []; $prev = null; foreach ($days as $d => $c) { if ($prev && $prev['code'] === $c['code'] && $prev['status'] === $c['status'] && (strtotime($d) - strtotime($prev['to'])) <= 3 * 86400) { $prev['to'] = $d; $prev['n']++; } else { if ($prev) $runs[] = $prev; $prev = ['code' => $c['code'], 'status' => $c['status'], 'from' => $d, 'to' => $d, 'n' => 1]; } } if ($prev) $runs[] = $prev;
            $s .= ' · away: ' . implode('; ', array_map(fn($r) => $r['code'] . ' ' . fmt_date($r['from']) . ($r['to'] !== $r['from'] ? '–' . fmt_date($r['to']) : '') . ' (' . $r['n'] . 'd' . ($r['status'] !== 'approved' ? ', ' . $r['status'] : '') . ')', $runs)); }
        $s .= "\n"; }
    return $s;
}
/* ------------------------------------------------------------ collective leave periods */
function hr_periods(): array { return hr_lv_has() ? all('SELECT lp.*, t.code, cu.full_name AS by_name FROM hr_leave_periods lp JOIN hr_leave_types t ON t.id = lp.type_id LEFT JOIN customer_users cu ON cu.id = lp.created_by ORDER BY lp.date_from DESC, lp.id DESC') : []; }
function hr_period_people(array $lp): array {
    $all = array_values(array_filter(hr_people(false), fn($p) => $p['is_active'] && !(function_exists('hr_is_contractor') && hr_is_contractor($p))));
    $ids = array_map('intval', array_filter(explode(',', (string)($lp['target_ids'] ?? ''))));
    if ($lp['target'] === 'disciplines') return array_values(array_filter($all, fn($p) => in_array((int)($p['department_id'] ?? 0), $ids, true)));
    if ($lp['target'] === 'people') return array_values(array_filter($all, fn($p) => in_array((int)$p['id'], $ids, true)));
    return $all;
}
function hr_period_save_from_post(): int {
    if (!hr_can() || !hr_lv_has()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120); if ($name === '') throw new RuntimeException(t('hr.period_need_name'));
    $tid = (int)($_POST['type_id'] ?? 0); if (!hr_type($tid)) throw new RuntimeException(t('err.notfound'));
    $from = (string)($_POST['date_from'] ?? ''); $to = (string)($_POST['date_to'] ?? '') ?: $from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || (strtotime($to) - strtotime($from)) > 120 * 86400) throw new RuntimeException(t('pl.bad_dates'));
    $target = in_array($_POST['target'] ?? '', ['all', 'disciplines', 'people'], true) ? $_POST['target'] : 'all';
    $ids = $target === 'all' ? null : implode(',', array_unique(array_filter(array_map('intval', (array)($_POST[$target === 'disciplines' ? 'discipline_ids' : 'person_ids'] ?? [])))));
    if ($target !== 'all' && !$ids) throw new RuntimeException(t('hr.period_need_target'));
    $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null; $me = (int)(current_user()['id'] ?? 0);
    $max = (int)($_POST['max_people'] ?? 0) ?: null; $status = in_array($_POST['status'] ?? '', ['open', 'closed'], true) ? $_POST['status'] : 'open'; $mand = (int)!empty($_POST['mandatory']);
    if ($id) { if (scalar('SELECT status FROM hr_leave_periods WHERE id = ?', [$id]) === 'applied') throw new RuntimeException(t('hr.period_applied_lock')); q('UPDATE hr_leave_periods SET name = ?, type_id = ?, date_from = ?, date_to = ?, target = ?, target_ids = ?, note = ?, max_people = ?, status = ?, mandatory = ? WHERE id = ?', [$name, $tid, $from, $to, $target, $ids, $note, $max, $status, $mand, $id]); return $id; }
    q('INSERT INTO hr_leave_periods (name, type_id, date_from, date_to, target, target_ids, note, max_people, status, mandatory, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$name, $tid, $from, $to, $target, $ids, $note, $max, $status, $mand, $me]); return (int) db()->lastInsertId();
}
/** Apply: one HR-approved request per person (working days only, skipping people who already have a request overlapping the period for the same type); written to the board. */
function hr_period_apply(int $id): array {
    if (!hr_can() || !hr_lv_has()) throw new RuntimeException(t('err.noaccess')); $lp = one('SELECT * FROM hr_leave_periods WHERE id = ?', [$id]); if (!$lp) throw new RuntimeException(t('err.notfound'));
    if ($lp['status'] === 'applied') throw new RuntimeException(t('hr.period_applied_lock')); $t = hr_type((int)$lp['type_id']); $me = (int)(current_user()['id'] ?? 0); $n = 0; $skipped = [];
    db()->beginTransaction();
    try {
        foreach (hr_period_people($lp) as $p) {
            if (function_exists('hr_type_enabled') && !hr_type_enabled($p, $t)) { $skipped[] = $p['name_en']; continue; }
            if (scalar("SELECT COUNT(*) FROM hr_requests WHERE person_id = ? AND type_id = ? AND status IN ('pending_mgr','pending_hr','approved') AND date_from <= ? AND date_to >= ?", [(int)$p['id'], (int)$t['id'], $lp['date_to'], $lp['date_from']])) { $skipped[] = $p['name_en']; continue; }
            $days = $t['unit'] === 'day' ? (float) hr_working_days($p, $lp['date_from'], $lp['date_to']) : 0.0; if ($t['unit'] === 'day' && $days <= 0) { $skipped[] = $p['name_en']; continue; }
            q('INSERT INTO hr_requests (person_id, user_id, type_id, date_from, date_to, days, reason, status, hr_user_id, hr_at, hr_note, created_by, period_id) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,?,?)', [(int)$p['id'], (int)($p['user_id'] ?? 0) ?: null, (int)$t['id'], $lp['date_from'], $lp['date_to'], $days, $lp['name'], 'approved', $me, $lp['note'] ?: t('hr.period_note', ['n' => $lp['name']]), $me, $id]);
            hr_request_to_board((int) db()->lastInsertId()); $n++;
        }
        q("UPDATE hr_leave_periods SET status = 'applied', applied_n = ?, applied_at = NOW() WHERE id = ?", [$n, $id]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['applied' => $n, 'skipped' => $skipped];
}
/** Undo: remove the leave "Assign to all" created (not what employees joined themselves); the period goes back to closed. */
function hr_period_undo(int $id): int {
    if (!hr_can() || !hr_lv_has()) throw new RuntimeException(t('err.noaccess')); $n = 0;
    foreach (all('SELECT id FROM hr_requests WHERE period_id = ? AND created_by <> COALESCE(user_id, 0)', [$id]) as $r) { q('DELETE FROM plan_entries WHERE request_id = ?', [(int)$r['id']]); q('DELETE FROM hr_requests WHERE id = ?', [(int)$r['id']]); $n++; }
    q("UPDATE hr_leave_periods SET status = 'closed', applied_n = 0, applied_at = NULL WHERE id = ?", [$id]); return $n;
}
/** Participants of a period: the requests that carry its id (pending with the manager, approved, rejected). */
function hr_period_members(int $id): array { return hr_lv_has() ? all('SELECT r.id, r.person_id, r.date_from, r.date_to, r.days, r.status, p.name_en, p.department_id FROM hr_requests r JOIN hr_people p ON p.id = r.person_id WHERE r.period_id = ? ORDER BY r.status, p.name_en', [$id]) : []; }
/** Open periods a given person may join (target matches, dates in the future, not yet joined). */
function hr_periods_for(array $p): array {
    if (!hr_lv_has()) return []; $out = [];
    foreach (all("SELECT lp.*, t.code, t.name AS tname FROM hr_leave_periods lp JOIN hr_leave_types t ON t.id = lp.type_id WHERE lp.status IN ('open','applied') AND lp.date_to >= CURDATE() ORDER BY lp.date_from") as $lp) {
        if ($lp['status'] === 'applied' && empty($lp['mandatory'])) continue;                                          // assigned ordinary periods are not shown as joinable
        $ids = array_map('intval', array_filter(explode(',', (string)($lp['target_ids'] ?? ''))));
        if ($lp['target'] === 'disciplines' && !in_array((int)($p['department_id'] ?? 0), $ids, true)) continue;
        if ($lp['target'] === 'people' && !in_array((int)$p['id'], $ids, true)) continue;
        $lp['joined'] = one("SELECT id, status, date_from, date_to FROM hr_requests WHERE period_id = ? AND person_id = ? AND status <> 'cancelled' AND status <> 'rejected' ORDER BY id DESC LIMIT 1", [(int)$lp['id'], (int)$p['id']]);
        $lp['n_approved'] = (int) scalar("SELECT COUNT(*) FROM hr_requests WHERE period_id = ? AND status = 'approved'", [(int)$lp['id']]);
        $lp['full'] = $lp['max_people'] && $lp['n_approved'] >= (int)$lp['max_people'];
        $out[] = $lp;
    }
    return $out;
}
/** An employee joins a period with dates inside it: a normal request (balance, overlap, working days checked) that goes to the manager, whose approval is final. */
function hr_period_join(array $p, int $periodId, string $from, string $to): int {
    $lp = one('SELECT * FROM hr_leave_periods WHERE id = ?', [$periodId]); if (!$lp || $lp['status'] !== 'open') throw new RuntimeException(t('hr.period_not_open'));
    $ok = false; foreach (hr_periods_for($p) as $x) if ((int)$x['id'] === $periodId) { $ok = true; if ($x['joined']) throw new RuntimeException(t('hr.period_already')); if ($x['full']) throw new RuntimeException(t('hr.period_full')); }
    if (!$ok) throw new RuntimeException(t('err.noaccess'));
    $from = $from ?: $lp['date_from']; $to = $to ?: $lp['date_to'];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $from < $lp['date_from'] || $to > $lp['date_to'] || $to < $from) throw new RuntimeException(t('hr.period_dates'));
    $_POST = ['type_id' => (int)$lp['type_id'], 'date_from' => $from, 'date_to' => $to, 'reason' => $lp['name']] + $_POST;
    $id = hr_request_create_from_post(null); q('UPDATE hr_requests SET period_id = ? WHERE id = ?', [$periodId, $id]); return $id;
}
function hr_period_delete(int $id): void { if (!hr_can() || !hr_lv_has()) throw new RuntimeException(t('err.noaccess')); if (scalar('SELECT status FROM hr_leave_periods WHERE id = ?', [$id]) === 'applied') hr_period_undo($id); q('UPDATE hr_requests SET period_id = NULL WHERE period_id = ?', [$id]); q('DELETE FROM hr_leave_periods WHERE id = ?', [$id]); }   // joined leave stays as ordinary requests
/** Ask the assistant about the period shown (server-side: the summary is the page data, the question the input). */
function hr_leaves_ask(string $from, string $to, int $disc, string $q): array {
    if (!hr_can() || !function_exists('ai_run')) throw new RuntimeException(t('err.noaccess'));
    $people = hr_lv_people($disc); $m = hr_lv_matrix($from, $to, $people);
    return ai_run('ask', ['title' => t('hr.leaves'), 'headline' => hr_lv_summary($from, $to, $people, $m, (int) substr($from, 0, 4))], $q, null, 'hr_leaves');
}
function hr_lv_people(int $disc = 0): array { return array_values(array_filter(hr_people(false), fn($p) => $p['is_active'] && (!$disc || (int)($p['department_id'] ?? 0) === $disc))); }

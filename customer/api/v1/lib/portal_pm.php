<?php
/**
 * Portal adapter for the Golden PM app (app id 'pm') — GAS-CP-R66-001.
 * Second client of the mobile API: project read model (tree, stages, drawings, submittals, issues, comments, notes,
 * budgets as hours), team HR for managers (requests to decide, timesheets to approve, who is away) and the decisions
 * themselves. Every read goes through the portal's scope functions (scope_sql / project_tree / load_subproject /
 * iss_list / ts_to_approve / hr_requests_for), every write through the portal's own functions (hr_request_decide,
 * ts_decide, iss_set_status, pm_track_completed, pm_auto_snapshot, iv_sub_save). Money never leaves (CS-02),
 * personal / payroll columns of hr_people are never selected (CS-24), the absence matrix carries codes only.
 */
declare(strict_types=1);

final class PortalPM {
    const STAGES = ['engineering', 'procurement', 'production', 'fat', 'installation', 'sathat', 'commissioning'];
    const PROJECT_STATUSES = ['active', 'quoted', 'onhold'];
    const MATRIX_MAX_DAYS = 62;

    /* ------------------------------------------------------------------ gating (§0) */
    /** Staff / admin AND (PM, HR manager, timesheet approver or administrator) — else 403. */
    public static function allowed(array $u): void {
        if (!in_array($u['role'] ?? '', ['staff', 'admin'], true)) throw new ApiException(403, 'Staff account required');
        if (is_cp_admin() || pm_is_pm((int)$u['id']) || self::hrManager() || self::tsApprover((int)$u['id'])) return;
        throw new ApiException(403, 'This account is not a project manager');
    }
    private static function hrManager(): bool { return function_exists('hr_is_manager') && hr_is_manager(); }
    /** Sheets waiting for me, or I am the approver of somebody (account manager or discipline manager). */
    private static function tsApprover(int $me): bool {
        if (function_exists('ts_to_approve') && ts_to_approve()) return true;
        return (bool) scalar('SELECT (SELECT COUNT(*) FROM customer_users WHERE manager_user_id = ? AND is_active = 1) + (SELECT COUNT(*) FROM disciplines WHERE manager_user_id = ?)', [$me, $me]);
    }
    private static function tableHas(string $t): bool { static $c = []; if (!isset($c[$t])) { try { $c[$t] = (bool) scalar("SHOW TABLES LIKE '" . preg_replace('/[^a-z_]/', '', $t) . "'"); } catch (Throwable $e) { $c[$t] = false; } } return $c[$t]; }
    private static function num($v): ?float { return $v === null || $v === '' ? null : round((float)$v, 2); }
    private static function pct($v): int { $v = (int)$v; return $v < 0 ? PCT_NA : max(0, min(100, $v)); }
    private static function overdue(?string $date, bool $open): int { if (!$open || !$date || $date >= date('Y-m-d')) return 0; return (int) floor((strtotime(date('Y-m-d')) - strtotime($date)) / 86400); }

    /* ------------------------------------------------------------------ §1 /me additions */
    public static function meExtra(array $u): array {
        $me = (int)$u['id']; $hrm = self::hrManager(); $tsa = self::tsApprover($me);
        $hrPending = 0; if ($hrm && function_exists('hr_requests_for')) foreach (hr_requests_for('manager') as $r) if ($r['status'] === 'pending_mgr' && (int)$r['manager_user_id'] === $me) $hrPending++;
        $tsPending = function_exists('ts_to_approve') ? count(ts_to_approve()) : 0;
        $dr = count(array_filter(self::drawings(null), fn($d) => $d['overdue_days'] > 0)); $sb = count(array_filter(self::submittals(null), fn($s) => $s['open']));
        return ['pm' => pm_is_pm($me), 'hr_manager' => $hrm, 'ts_approver' => $tsa, 'admin' => is_cp_admin(), 'totp_enabled' => !empty($u['totp_enabled']), 'min_app_version' => api_setting('api_min_version_pm', '1.0.0'),
            'counts' => ['hr_pending' => $hrPending, 'ts_pending' => $tsPending, 'drawings_overdue' => $dr, 'submittals_open' => $sb, 'hr_unread' => function_exists('hr_messages_unread') ? hr_messages_unread() : 0]];
    }

    /* ------------------------------------------------------------------ §2 projects */
    /** project_tree() → scope_sql('p') (branch scope included); active / quoted / onhold projects with their sub-projects' stages. */
    private static function tree(?int $only = null): array { static $c = []; $k = (int)$only; if (!isset($c[$k])) $c[$k] = array_values(array_filter(project_tree($only), fn($p) => in_array((string)$p['status'], self::PROJECT_STATUSES, true))); return $c[$k]; }
    private static function treeIds(array $tree): array { return array_map(fn($p) => (int)$p['id'], $tree); }
    private static function parentCode(?string $code): ?string { return $code && preg_match('/^(.+\.\d+)\.\d+$/', trim($code), $m) ? $m[1] : null; }
    private static function shapeSub(array $s, array $p, int $issuesOpen, array $teamIdx): array {
        $defs = stage_defs(); $st = []; foreach ($defs as $k => $d) $st[$k] = self::pct($s[$d['col']] ?? 0);
        $fed = function_exists('pd_sub_fed') ? array_values(pd_sub_fed((int)$s['id'])) : [];
        return ['id' => (int)$s['id'], 'code' => (string)($s['code'] ?? ''), 'parent_code' => self::parentCode($s['code'] ?? null), 'name' => (string)$s['name'], 'system' => (string)($s['system_name'] ?? ''), 'status' => (string)($s['status'] ?? 'active'),
            'stages' => $st, 'fed' => $fed, 'overall' => overall_pct($s), 'team' => $teamIdx[(int)$s['id']] ?? [], 'pm_id' => (int)($p['pm_user_id'] ?? 0) ?: null, 'issues_open' => $issuesOpen, 'deadline' => $s['deadline'] ?? null];
    }
    /** Open issues per sub-project and per project (project-level entries have subproject_id NULL) — the staff visibility rule of iss_list. */
    private static function issueCounts(array $pids): array {
        $bySub = []; $byProj = []; if (!$pids || !function_exists('iss_has') || !iss_has()) return [$bySub, $byProj];
        foreach (all('SELECT i.project_id, i.subproject_id, COUNT(*) n FROM sp_issues i WHERE i.project_id IN (' . implode(',', $pids) . ") AND i.status <> 'closed' AND " . iss_vis_sql() . ' GROUP BY i.project_id, i.subproject_id') as $r) { $byProj[(int)$r['project_id']] = ($byProj[(int)$r['project_id']] ?? 0) + (int)$r['n']; if ($r['subproject_id']) $bySub[(int)$r['subproject_id']] = (int)$r['n']; }
        return [$bySub, $byProj];
    }
    private static function teamIndex(array $pids): array { $idx = []; if ($pids && function_exists('tm_has') && tm_has()) foreach (all('SELECT t.subproject_id, t.user_id FROM sp_team t JOIN subprojects s ON s.id = t.subproject_id WHERE s.project_id IN (' . implode(',', $pids) . ') ORDER BY t.user_id') as $r) $idx[(int)$r['subproject_id']][] = (int)$r['user_id']; return $idx; }
    public static function projects(array $u): array {
        $me = (int)$u['id']; $tree = self::tree(); $pids = self::treeIds($tree); [$issSub, $issProj] = self::issueCounts($pids); $team = self::teamIndex($pids);
        $drOver = []; foreach (self::drawings(null, $tree) as $d) if ($d['overdue_days'] > 0) $drOver[$d['project_id']] = ($drOver[$d['project_id']] ?? 0) + 1;
        $sbOpen = []; foreach (self::submittals(null, $tree) as $s) if ($s['open']) $sbOpen[$s['project_id']] = ($sbOpen[$s['project_id']] ?? 0) + 1;
        $items = [];
        foreach ($tree as $p) {
            $pid = (int)$p['id']; $subs = array_map(fn($s) => self::shapeSub($s, $p, $issSub[(int)$s['id']] ?? 0, $team), $p['subs']);
            $items[] = ['id' => $pid, 'code' => (string)($p['project_code'] ?: $p['erp_code'] ?: 'P' . $pid), 'name' => (string)$p['name'], 'vessel' => (string)($p['vessel'] ?? ''), 'vessel_id' => (int)($p['vessel_id'] ?? 0) ?: null,
                'yard' => (string)(($p['vessel_id'] ?? null) ? (scalar('SELECT yard FROM vessels WHERE id = ?', [(int)$p['vessel_id']]) ?? '') : ''), 'customer' => (string)($p['customer_name'] ?? ''), 'branch' => function_exists('br_of') ? (string)(br_of($pid)['code'] ?? 'GAS') : 'GAS',
                'status' => (string)$p['status'], 'pm' => (string)($p['project_manager'] ?? ''), 'pm_id' => (int)($p['pm_user_id'] ?? 0) ?: null, 'mine' => (int)($p['pm_user_id'] ?? 0) === $me, 'overall' => project_overall_pct($p['subs']),
                'counts' => ['subprojects' => count($subs), 'drawings_overdue' => $drOver[$pid] ?? 0, 'submittals_open' => $sbOpen[$pid] ?? 0, 'issues_open' => $issProj[$pid] ?? 0], 'subprojects' => $subs];
        }
        return ['items' => Response::noMoney($items), 'stages' => self::STAGES, 'staff' => self::staff()];
    }
    /** Active staff accounts with discipline and the linked person card id (hr_people: id + user_id only). */
    public static function staff(): array {
        $persons = []; if (function_exists('hr2_has') && hr2_has()) foreach (all('SELECT id, user_id FROM hr_people WHERE user_id IS NOT NULL AND is_active = 1') as $r) $persons[(int)$r['user_id']] = (int)$r['id'];
        return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => (string)$r['full_name'], 'discipline' => (string)$r['discipline'], 'discipline_id' => (int)($r['discipline_id'] ?? 0) ?: null, 'person_id' => $persons[(int)$r['id']] ?? null],
            all("SELECT cu.id, cu.full_name, cu.discipline_id, COALESCE(d.name, '') AS discipline FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 ORDER BY cu.full_name"));
    }

    /* ------------------------------------------------------------------ §3 sub-project detail */
    public static function subproject(array $u, int $id): array {
        $sp = load_subproject($id); if (!$sp) throw new ApiException(404, 'Sub-project not found');
        $pid = (int)$sp['project_id']; $tree = self::tree($pid); $p = $tree[0] ?? one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
        [$issSub] = self::issueCounts([$pid]); $team = self::teamIndex([$pid]);
        $sub = self::shapeSub($sp, $p, $issSub[$id] ?? 0, $team) + ['description' => (string)($sp['description'] ?? '')];
        $issues = array_map(fn($i) => ['id' => (int)$i['id'], 'issue_no' => $i['issue_no'] ?? null, 'title' => (string)$i['title'], 'body' => (string)($i['body'] ?? ''), 'raised_on' => $i['raised_on'], 'raised_by' => (string)($i['raised_by'] ?? ''), 'owner_side' => $i['owner_side'], 'assignee' => (string)($i['assignee'] ?? ''), 'assignee_id' => (int)($i['assignee_id'] ?? 0) ?: null, 'due_on' => $i['due_on'], 'priority' => $i['priority'], 'status' => $i['status'], 'visibility' => $i['visibility'] ?? 'external'],
            function_exists('iss_list') ? iss_list($pid, $id) : []);
        $delCol = function_exists('thread_has_replies') && thread_has_replies() ? ' AND c.deleted_at IS NULL' : '';
        $comments = array_map(fn($c) => ['id' => (int)$c['id'], 'author' => (string)($c['author'] ?? ''), 'author_id' => (int)$c['author_id'], 'visibility' => $c['visibility'], 'body' => (string)$c['body'], 'created_at' => (string)$c['created_at'], 'parent_id' => (int)($c['parent_id'] ?? 0) ?: null],
            all('SELECT c.*, cu.full_name AS author FROM sp_comments c LEFT JOIN customer_users cu ON cu.id = c.author_id WHERE c.subproject_id = ?' . $delCol . ' ORDER BY c.created_at DESC, c.id DESC LIMIT 100', [$id]));
        $notes = array_map(fn($n) => ['id' => 'N' . $n['id'], 'date' => $n['note_date'], 'iso_week' => $n['iso_week'], 'author' => (string)($n['author'] ?? ''), 'author_id' => (int)$n['author_id'], 'visibility' => $n['visibility'], 'text' => (string)$n['body'], 'photos' => (int)$n['photos']],
            all('SELECT n.*, cu.full_name AS author, (SELECT COUNT(*) FROM sp_files f WHERE f.note_id = n.id) AS photos FROM sp_notes n LEFT JOIN customer_users cu ON cu.id = n.author_id WHERE n.subproject_id = ? AND n.note_date >= DATE_SUB(CURDATE(), INTERVAL 84 DAY) ORDER BY n.note_date DESC, n.id DESC', [$id]));
        $teamRows = function_exists('tm_members_full') ? tm_members_full($id) : []; $persons = self::personsOfUsers(array_map(fn($t) => (int)$t['id'], $teamRows));
        $teamOut = array_map(fn($t) => ['id' => (int)$t['id'], 'name' => (string)$t['full_name'], 'discipline' => (string)($t['discipline'] ?? ''), 'person_id' => isset($persons[(int)$t['id']]) ? (int)$persons[(int)$t['id']]['id'] : null], $teamRows);
        return ['subproject' => $sub, 'project' => ['id' => $pid, 'code' => (string)($p['project_code'] ?: $p['erp_code'] ?: 'P' . $pid), 'name' => (string)$p['name'], 'vessel' => (string)($p['vessel'] ?? ''), 'customer' => (string)($p['customer_name'] ?? ''), 'branch' => function_exists('br_of') ? (string)(br_of($pid, $id)['code'] ?? 'GAS') : 'GAS'],
            'issues' => Response::noMoney($issues), 'comments' => $comments, 'notes' => $notes,
            'drawings' => array_values(array_filter(self::drawings($pid, $tree), fn($d) => $d['subproject_id'] === $id)), 'submittals' => array_values(array_filter(self::submittals($pid, $tree), fn($s) => $s['subproject_id'] === $id)),
            'budgets' => Response::noMoney(self::budgets($id)), 'team' => $teamOut, 'absences' => self::matrix(date('Y-m-d'), date('Y-m-d', strtotime('+28 days')), array_values($persons))];
    }
    /** Engineering sections of one sub-project — hours only (as SE /budgets). */
    private static function budgets(int $sid): array {
        if (!function_exists('eng_has2') || !eng_has2()) return []; $B = eng_budget_hours($sid); if ($B === null) return []; $A = eng_actual($sid); $secs = eng_sections(); $rows = [];
        foreach ($B as $secId => $h) { if ((float)$h <= 0) continue; $extra = (float) scalar("SELECT COALESCE(SUM(hours),0) FROM eng_requests WHERE subproject_id = ? AND section_id = ? AND status = 'approved'", [$sid, (int)$secId]);
            $rows[] = ['section_id' => (int)$secId, 'code' => (string)($secs[$secId]['code'] ?? ''), 'name' => (string)($secs[$secId]['name'] ?? ''), 'budget' => (float)$h, 'booked' => (float)($A[$secId] ?? 0), 'extra' => $extra]; }
        return $rows;
    }

    /* ------------------------------------------------------------------ §4 drawings, §5 submittals */
    /** drawing_requests of one project (scope-checked) or of every project in scope. */
    public static function drawings(?int $pid, ?array $tree = null): array {
        $tree = $tree ?? self::tree($pid); $pids = self::treeIds($tree); if ($pid !== null) $pids = array_values(array_intersect($pids, [$pid]));
        if (!$pids || !self::tableHas('drawing_requests')) return [];
        $today = date('Y-m-d');
        return array_map(fn($d) => ['id' => (int)$d['id'], 'project_id' => (int)$d['project_id'], 'subproject_id' => (int)($d['subproject_id'] ?? 0) ?: null, 'kind' => (string)$d['kind'], 'seq_no' => (int)($d['seq_no'] ?? 0) ?: null, 'title' => (string)$d['title'], 'system' => (string)($d['system'] ?? ''), 'location' => (string)($d['location'] ?? ''), 'location_status' => (string)$d['location_status'], 'responsible' => (string)$d['responsible'],
                'request_sent' => $d['request_sent'], 'expected_at' => $d['expected_at'], 'received_at' => $d['received_at'], 'status' => (string)$d['status'], 'overdue_days' => self::overdue($d['expected_at'], $d['status'] === 'pending'), 'remarks' => (string)($d['remarks'] ?? ''), 'file_id' => (int)($d['file_id'] ?? 0) ?: null],
            all('SELECT d.*, vs.name AS system FROM drawing_requests d LEFT JOIN vessel_systems vs ON vs.id = d.system_id WHERE d.project_id IN (' . implode(',', $pids) . ") ORDER BY FIELD(d.status,'pending','received','not_required'), d.expected_at IS NULL, d.expected_at, d.seq_no, d.id"));
    }
    /** owner_approvals (kind owner) + class_submittals (kind class); open = submitted | resubmitted. */
    public static function submittals(?int $pid, ?array $tree = null): array {
        $tree = $tree ?? self::tree($pid); $pids = self::treeIds($tree); if ($pid !== null) $pids = array_values(array_intersect($pids, [$pid]));
        if (!$pids) return []; $in = implode(',', $pids); $open = function_exists('oa_open_statuses') ? oa_open_statuses() : ['submitted', 'resubmitted']; $rows = [];
        $shape = function (array $r, string $kind) use ($open): array { $isOpen = in_array((string)$r['status'], $open, true);
            return ['id' => (int)$r['id'], 'kind' => $kind, 'project_id' => (int)$r['project_id'], 'subproject_id' => (int)($r['subproject_id'] ?? 0) ?: null, 'doc_no' => (string)$r['doc_no'], 'title' => (string)$r['title'], 'revision' => (string)$r['revision'], 'society' => $kind === 'class' ? (string)($r['class_society'] ?? '') : null, 'class_ref' => $kind === 'class' ? (string)($r['class_ref'] ?? '') : null,
                'submitted_at' => $r['submitted_at'], 'due_at' => $r['due_at'], 'decided_at' => $r['decided_at'], 'status' => (string)$r['status'], 'open' => $isOpen, 'overdue_days' => self::overdue($r['due_at'], $isOpen), 'comments' => (string)($r['comments'] ?? ''), 'file_id' => (int)($r['file_id'] ?? 0) ?: null]; };
        if (self::tableHas('owner_approvals')) foreach (all("SELECT * FROM owner_approvals WHERE project_id IN ($in) ORDER BY FIELD(status,'submitted','resubmitted','draft','approved_comments','approved','rejected'), due_at IS NULL, due_at, id") as $r) $rows[] = $shape($r, 'owner');
        if (self::tableHas('class_submittals')) foreach (all("SELECT * FROM class_submittals WHERE project_id IN ($in) ORDER BY FIELD(status,'submitted','resubmitted','draft','approved_comments','approved','rejected'), due_at IS NULL, due_at, id") as $r) $rows[] = $shape($r, 'class');
        return Response::noMoney($rows);
    }

    /* ------------------------------------------------------------------ §6 team HR, §7 absence matrix */
    /** Person rows with the public columns only (CS-24): id, user_id, name_en, department_id, hire_date + the approver chain columns hr_manager_of() reads. */
    private static function personRows(string $where = '', array $args = []): array {
        if (!function_exists('hr2_has') || !hr2_has()) return [];
        return all('SELECT p.id, p.user_id, p.name_en, p.department_id, p.hire_date, cu.manager_user_id AS account_manager_id, cu.discipline_id, dc.manager_user_id, dp.manager_user_id AS head_user_id, dp.name AS department, dc.name AS discipline
                    FROM hr_people p LEFT JOIN customer_users cu ON cu.id = p.user_id LEFT JOIN disciplines dp ON dp.id = p.department_id LEFT JOIN disciplines dc ON dc.id = cu.discipline_id WHERE p.is_active = 1' . ($where ? ' AND ' . $where : '') . ' ORDER BY dp.sort_order, p.name_en', $args);
    }
    private static function personsOfUsers(array $uids): array { $uids = array_values(array_unique(array_filter(array_map('intval', $uids)))); if (!$uids) return []; $out = []; foreach (self::personRows('p.user_id IN (' . implode(',', $uids) . ')') as $p) $out[(int)$p['user_id']] = $p; return $out; }
    /** The people whose approver is me — the same chain hr_requests_for('manager') draws from (account manager, discipline manager, department head). */
    private static function myPeople(int $me): array { return array_values(array_filter(self::personRows(), fn($p) => function_exists('hr_manager_of') && hr_manager_of($p) === $me)); }
    public static function hrTeam(array $u, int $year): array {
        if (!function_exists('hr2_has') || !hr2_has()) throw new ApiException(503, 'HR module not installed');
        $me = (int)$u['id']; if (!self::hrManager() && !self::tsApprover($me) && !is_cp_admin()) throw new ApiException(403, 'Not a manager');
        if ($year < 2000 || $year > 2100) $year = (int) date('Y');
        $people = self::myPeople($me); $today = date('Y-m-d'); $mx = self::matrix($today, date('Y-m-d', strtotime('+28 days')), $people); $away = []; foreach ($mx['rows'] as $r) $away[$r['person_id']] = $r['cells'][$today]['code'] ?? null;
        $ann = function_exists('hr_annual_type_id') ? hr_annual_type_id() : null; $out = [];
        foreach ($people as $p) { $bal = null;
            if ($ann && function_exists('hr_balance') && ($full = hr_person((int)$p['id']))) { $b = hr_balance($full, $ann, $year); if ($b['counts']) $bal = ['entitled' => self::num($b['total']), 'taken' => self::num($b['taken'] + $b['opening']), 'pending' => self::num($b['pending']), 'available' => self::num($b['available'])]; }   // the full row stays server-side: hr_balance needs the contract kind
            $out[] = ['person_id' => (int)$p['id'], 'user_id' => (int)($p['user_id'] ?? 0) ?: null, 'name' => (string)$p['name_en'], 'discipline' => (string)($p['discipline'] ?: $p['department'] ?: ''), 'annual' => $bal, 'away_today' => $away[(int)$p['id']] ?? null]; }
        $reqs = [];
        foreach (hr_requests_for('manager', $year) as $r) $reqs[] = ['id' => (int)$r['id'], 'person_id' => (int)$r['person_id'], 'person' => (string)$r['name_en'], 'type_id' => (int)$r['type_id'], 'code' => $r['code'], 'type' => hr_type((int)$r['type_id']) ? hr_type_name(hr_type((int)$r['type_id'])) : (string)$r['type_name'], 'unit' => $r['unit'], 'date_from' => $r['date_from'], 'date_to' => $r['date_to'], 'time_from' => $r['time_from'] ? substr((string)$r['time_from'], 0, 5) : null, 'time_to' => $r['time_to'] ? substr((string)$r['time_to'], 0, 5) : null,
            'hours' => self::num($r['hours']), 'days' => self::num($r['days']), 'reason' => (string)$r['reason'], 'status' => $r['status'], 'doc' => $r['doc_path'] ? ['name' => (string)$r['doc_name']] : null, 'created_at' => (string)$r['created_at'],
            'manager' => $r['manager_at'] ? ['name' => (string)$r['manager_name'], 'at' => (string)$r['manager_at'], 'note' => (string)$r['manager_note']] : null, 'hr' => $r['hr_at'] ? ['at' => (string)$r['hr_at'], 'note' => (string)$r['hr_note']] : null, 'decidable' => $r['status'] === 'pending_mgr' && (int)$r['manager_user_id'] === $me];
        $ts = [];
        if (function_exists('ts_to_approve')) foreach (ts_to_approve() as $t) { $full = ts_load((int)$t['id']); if (!$full) continue;
            $ts[] = ['id' => (int)$t['id'], 'ts_no' => (string)$t['ts_no'], 'user_id' => (int)$t['user_id'], 'user' => (string)($t['user_name'] ?? ''), 'project_id' => (int)$t['project_id'], 'project_code' => (string)($t['project_code'] ?? ''), 'project' => (string)($t['project_name'] ?? ''), 'period_start' => $t['period_start'], 'period_end' => $t['period_end'],
                'hours_normal' => (float)($t['h_normal'] ?? 0), 'hours_overtime' => (float)($t['h_overtime'] ?? 0), 'hours_holiday' => (float)($t['h_holiday'] ?? 0), 'hours_total' => (float)($t['h_normal'] ?? 0) + (float)($t['h_overtime'] ?? 0) + (float)($t['h_holiday'] ?? 0), 'submitted_at' => (string)($t['submitted_at'] ?? ''), 'status' => (string)$t['status'], 'note' => (string)($t['note'] ?? ''),
                'lines' => Response::noMoney(array_map(fn($l) => ['date' => $l['work_date'], 'subproject_code' => (string)($l['sub_code'] ?? ''), 'hour_type' => $l['hour_type'], 'hours' => (float)$l['hours'], 'activity' => $l['activity'] ?? null, 'description' => (string)($l['description'] ?? '')], $full['lines']))]; }
        return ['year' => $year, 'people' => $out, 'requests' => Response::noMoney($reqs), 'timesheets' => Response::noMoney($ts), 'absences' => $mx];
    }
    /** hr_lv_matrix → codes / statuses only, never reasons or hours. $people: rows with id, user_id, name_en. */
    public static function matrix(string $from, string $to, array $people): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new ApiException(422, 'from / to');
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MATRIX_MAX_DAYS) throw new ApiException(422, 'Range of max ' . self::MATRIX_MAX_DAYS . ' days');
        $days = []; for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $days[] = date('Y-m-d', $d);
        $m = $people && function_exists('hr_lv_matrix') ? hr_lv_matrix($from, $to, $people) : [];
        $rows = [];
        foreach ($people as $p) { $cells = []; foreach ($m[(int)$p['id']] ?? [] as $date => $c) $cells[$date] = ['code' => (string)$c['code'], 'status' => (string)$c['status']];
            $rows[] = ['person_id' => (int)$p['id'], 'user_id' => (int)($p['user_id'] ?? 0) ?: null, 'name' => (string)$p['name_en'], 'cells' => $cells]; }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'rows' => $rows];
    }
    /** Matrix for the union of a project's sub-project teams + its PM. */
    public static function absencesForProject(int $pid, string $from, string $to): array {
        $tree = self::tree($pid); if (!$tree) throw new ApiException(404, 'Project not found');
        $uids = []; foreach (self::teamIndex([$pid]) as $ids) foreach ($ids as $i) $uids[] = $i; if (!empty($tree[0]['pm_user_id'])) $uids[] = (int)$tree[0]['pm_user_id'];
        return self::matrix($from, $to, array_values(self::personsOfUsers($uids)));
    }

    /* ------------------------------------------------------------------ §9 inbox */
    public static function inbox(array $u, int $since): array {
        if (!function_exists('nf_list')) return [];
        $out = []; foreach (nf_list(null, false, 50) as $n) { if ($since && strtotime((string)$n['created_at']) <= $since) continue;
            $out[] = ['id' => (int)$n['id'], 'kind' => (string)$n['kind'], 'title' => (string)$n['title'], 'body' => (string)($n['body'] ?? ''), 'url' => (string)($n['url'] ?? ''), 'created_at' => (string)$n['created_at'], 'read' => $n['read_at'] !== null]; }
        return $out;
    }
    public static function markRead(array $u, array $ids): array { $ids = array_values(array_filter(array_map('intval', $ids))); if ($ids && function_exists('nf_read')) nf_read($ids); return ['id' => 'NF', 'read' => count($ids)]; }

    /* ------------------------------------------------------------------ §8 writes */
    /** Stage percentages: pct_* columns only; fed stages ignored; -1 = N/A; then the same follow-ups as the portal's sp_stages action. */
    public static function setStages(array $u, array $p): array {
        $sid = (int)($p['subproject_id'] ?? 0); $sp = load_subproject($sid); if (!$sp) throw new ApiException(404, 'Sub-project not found');
        if (!is_cp_admin() && !pm_is_pm((int)$u['id']) && (int)($sp['pm_user_id'] ?? 0) !== (int)$u['id']) throw new ApiException(403, 'Project managers only');
        $in = (array)($p['stages'] ?? []); if (!$in) throw new ApiException(422, 'stages');
        $defs = stage_defs(); $fed = function_exists('pd_sub_fed') ? pd_sub_fed($sid) : []; $sets = []; $vals = []; $ignored = [];
        foreach ($in as $k => $v) { if (!isset($defs[$k])) throw new ApiException(422, 'Unknown stage ' . $k); if (in_array($k, $fed, true)) { $ignored[] = $k; continue; } $sets[] = $defs[$k]['col'] . ' = ?'; $vals[] = self::pct($v); }
        if ($sets) { $vals[] = $sid; q('UPDATE subprojects SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            pm_track_completed($sid); if (function_exists('of_has') && of_has()) pm_auto_snapshot($sid); if (function_exists('iv_sub_save')) iv_sub_save($sid); }
        $s = one('SELECT * FROM subprojects WHERE id = ?', [$sid]) ?: $sp; $st = []; foreach ($defs as $k => $d) $st[$k] = self::pct($s[$d['col']] ?? 0);
        return ['id' => 'S' . $sid, 'stages' => $st, 'overall' => overall_pct($s), 'ignored' => $ignored];
    }
    /** Comment on a sub-project — as the portal's cm_post: dedupe 20 s, parent inside the thread, default internal (R57-003), mt_notify. */
    public static function comment(array $u, string $uuid, array $p): array {
        $sid = (int)($p['subproject_id'] ?? 0); $sp = load_subproject($sid); if (!$sp) throw new ApiException(404, 'Sub-project not found');
        $body = mb_substr(trim((string)($p['body'] ?? '')), 0, 4000); if ($body === '') throw new ApiException(422, 'body');
        $vis = ($p['visibility'] ?? 'internal') === 'external' ? 'external' : 'internal';
        $parent = (int)($p['parent_id'] ?? 0) ?: null; if ($parent && !scalar('SELECT COUNT(*) FROM sp_comments WHERE id = ? AND subproject_id = ?', [$parent, $sid])) $parent = null;
        $dup = one('SELECT id FROM sp_comments WHERE subproject_id = ? AND author_id = ? AND body = ? AND created_at >= NOW() - INTERVAL 20 SECOND ORDER BY id DESC LIMIT 1', [$sid, (int)$u['id'], $body]);
        if ($dup) return ['id' => 'C' . $dup['id'], 'subproject_id' => $sid];
        q('INSERT INTO sp_comments (subproject_id, author_id, visibility, parent_id, body) VALUES (?,?,?,?,?)', [$sid, (int)$u['id'], $vis, $parent, $body]); $id = (int) db()->lastInsertId();
        if (function_exists('mt_notify')) { try { mt_notify($body, trim(($sp['project_code'] ?: '') . ' › ' . ($sp['code'] ?: ''), ' ›'), url('subproject', ['id' => $sid, 'tab' => 'comments']), 'spc:' . $id); } catch (Throwable $e) {} }
        return ['id' => 'C' . $id, 'subproject_id' => $sid];
    }
    public static function issueStatus(array $u, array $p): array {
        if (!function_exists('iss_get')) throw new ApiException(503, 'Issues module not installed');
        $i = iss_get((int)($p['issue_id'] ?? 0)); if (!$i) throw new ApiException(404, 'Issue not found');
        $st = (string)($p['status'] ?? ''); if (!in_array($st, ['open', 'in_progress', 'closed'], true)) throw new ApiException(422, 'status');
        try { iss_set_status((int)$i['id'], $st, (string)($p['note'] ?? '')); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['id' => 'IS' . $i['id'], 'status' => $st];
    }
    /** Subset of the portal's dr_update: status, received_at (today when received), remarks. */
    public static function drawingUpdate(array $u, array $p): array {
        $d = self::tableHas('drawing_requests') ? one('SELECT * FROM drawing_requests WHERE id = ?', [(int)($p['drawing_id'] ?? 0)]) : null; if (!$d) throw new ApiException(404, 'Drawing request not found');
        if (!self::tree((int)$d['project_id'])) throw new ApiException(403, 'Not your project');
        $st = (string)($p['status'] ?? $d['status']); if (!in_array($st, ['pending', 'received', 'not_required'], true)) throw new ApiException(422, 'status');
        $recv = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['received_at'] ?? '')) ? (string)$p['received_at'] : null; if ($st === 'received' && !$recv) $recv = $d['received_at'] ?: date('Y-m-d'); if ($st === 'pending') $recv = null;
        $remarks = array_key_exists('remarks', $p) ? (mb_substr(trim((string)$p['remarks']), 0, 2000) ?: null) : $d['remarks'];
        q('UPDATE drawing_requests SET status = ?, received_at = ?, remarks = ? WHERE id = ?', [$st, $recv, $remarks, (int)$d['id']]);
        return ['id' => 'DR' . $d['id'], 'status' => $st, 'received_at' => $recv];
    }
    /** Step-up for decisions: an account with 2FA must send a valid authenticator code (CS-25). The code is never echoed or logged. */
    public static function stepUp(array $u, ?string $totp): void {
        if (empty($u['totp_enabled'])) return;
        $secret = (string)($u['totp_secret'] ?? ''); $code = preg_replace('/\D/', '', (string)($totp ?? ''));
        if ($secret === '' || strlen($code) !== 6 || !function_exists('totp_verify') || !totp_verify($secret, $code)) throw new ApiException(401, 'Authenticator code required', ['totp' => true]);
    }
    /** Manager-stage leave decision (pending_mgr, manager_user_id = me) through hr_request_decide + hr_audit; HR-stage decisions stay in the portal. */
    public static function hrDecide(array $u, array $p): array {
        if (!function_exists('hr2_has') || !hr2_has()) throw new ApiException(503, 'HR module not installed');
        self::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $id = (int)($p['request_id'] ?? 0); $r = one('SELECT id, person_id, status, type_id, date_from, manager_user_id FROM hr_requests WHERE id = ?', [$id]); if (!$r) throw new ApiException(404, 'Request not found');
        if ($r['status'] !== 'pending_mgr') throw new ApiException(409, 'Request is no longer pending');
        if ((int)$r['manager_user_id'] !== (int)$u['id']) throw new ApiException(403, 'Not the approver of this request');
        $approve = !empty($p['approve']); $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 280);
        try { hr_request_decide($id, $approve, $note); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        if (function_exists('hr_audit')) hr_audit((int)$r['person_id'], 'request', (hr_type((int)$r['type_id'])['code'] ?? '') . ' ' . $r['date_from'], (string)$r['status'], $approve ? 'approved' : 'rejected', trim($note . ' (mobile app)'));
        return ['id' => 'HR' . $id, 'status' => (string)(scalar('SELECT status FROM hr_requests WHERE id = ?', [$id]) ?: ($approve ? 'pending_hr' : 'rejected'))];
    }
    /** Timesheet decision through ts_decide (status submitted, ts_can_approve; a rejection needs a note). */
    public static function tsDecide(array $u, array $p): array {
        self::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $id = (int)($p['ts_id'] ?? 0); $t = ts_load($id); if (!$t) throw new ApiException(404, 'Timesheet not found');
        if ($t['status'] !== 'submitted') throw new ApiException(409, 'Timesheet is no longer submitted');
        if (!ts_can_approve($t)) throw new ApiException(403, 'Not the approver of this sheet');
        $approve = !empty($p['approve']); $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 980); if (!$approve && $note === '') throw new ApiException(422, 'A note is required to reject');
        try { ts_decide($id, $approve, trim($note . ' (mobile app)')); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['id' => 'TS' . $id, 'status' => $approve ? 'approved' : 'rejected'];
    }
}

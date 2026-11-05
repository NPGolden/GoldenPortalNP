<?php
/**
 * Portal adapter for the Golden HR app (app id 'hr') — GAS-CP-R74-001.
 * Fourth client of the mobile API, iOS. Who: accounts whose staff role has the HR scope, plus the Owner / CEO scope
 * (hr_can() is the portal's own rule — administrators without that role are NOT let in; the check runs on every
 * request). What: the HR inbox (leave / absence requests at the HR or manager stage, training applications,
 * timesheets the person approves), the people directory (work data only), who is away, leave balances, HR message
 * threads and the absence / holiday calendar. Every write goes through the portal's own functions (hr_request_decide,
 * hr_training_member_save, ts_decide, hr_message_send_from_post) so audit, notifications and board stay the same.
 *
 * Never selected (CS-24, CS-46): salary, payroll code, benefits, bank / IBAN, AMKA / AFM / DOY, id / passport numbers,
 * birth date, address, family, nationality, blood group, notes, evaluations, targets, documents. The directory carries
 * name, job title, department / discipline, branch, work phone / e-mail, manager, hire date, contract end, left date.
 */
declare(strict_types=1);

final class PortalHR {
    const MATRIX_MAX_DAYS = 62;
    const INBOX_MAX = 200;

    /* ------------------------------------------------------------------ gating */
    public static function allowed(array $u): void {
        if (!in_array($u['role'] ?? '', ['staff', 'admin'], true)) throw new ApiException(403, 'Staff account required');
        $r = null; try { $r = one('SELECT scope, name FROM staff_roles WHERE id = ?', [(int)($u['staff_role_id'] ?? 0)]); } catch (Throwable $e) {}
        $scope = (string)($r['scope'] ?? ''); $ceo = strtolower(trim((string)($r['name'] ?? ''))) === 'ceo';
        if (!in_array($scope, ['hr', 'owner'], true) && !$ceo) throw new ApiException(403, 'This account is not HR, Owner or CEO');
        if (!function_exists('hr_can') || !hr_can()) throw new ApiException(403, 'HR module not available for this account');
    }
    private static function num($v): ?float { return $v === null || $v === '' ? null : round((float)$v, 2); }
    private static function d(?string $v): ?string { return $v ? substr($v, 0, 10) : null; }

    public static function me(array $u): array {
        $m = Portal::me($u); $c = self::counts($u);
        return array_replace($m, ['hr' => true, 'totp_enabled' => !empty($u['totp_enabled']), 'min_app_version' => api_setting('api_min_version_hr', API_APPS['hr']), 'counts' => $c]);
    }
    public static function counts(array $u): array {
        $inbox = self::inbox($u);
        return ['inbox' => count($inbox['requests']) + count($inbox['trainings']) + count($inbox['timesheets']), 'requests' => count($inbox['requests']), 'trainings' => count($inbox['trainings']), 'timesheets' => count($inbox['timesheets']),
            'unread' => function_exists('hr_messages_unread') ? hr_messages_unread() : 0, 'away_today' => count(self::away(date('Y-m-d'), date('Y-m-d'))['rows'])];
    }

    /* ------------------------------------------------------------------ people (work data only) */
    private static function rows(bool $activeOnly = true): array {
        if (!function_exists('hr2_has') || !hr2_has()) return [];
        return all('SELECT p.id, p.user_id, p.emp_code, p.name_en, p.name_el, p.job_title, p.department_id, p.hire_date, p.contract_end, p.left_date, p.is_active, p.is_technical, p.part_time, p.contract_type, p.phone_work, p.extension, p.phone_mobile, p.jurisdiction,
                           cu.email, br.code AS home_branch, cu.discipline_id, cu.manager_user_id AS account_manager_id, cu.work_calendar, dp.name AS department, dp.manager_user_id AS head_user_id, dc.name AS discipline, dc.manager_user_id,
                           mg.full_name AS manager_name
                    FROM hr_people p LEFT JOIN customer_users cu ON cu.id = p.user_id LEFT JOIN disciplines dp ON dp.id = p.department_id LEFT JOIN disciplines dc ON dc.id = cu.discipline_id
                    LEFT JOIN customer_users mg ON mg.id = COALESCE(cu.manager_user_id, dc.manager_user_id, dp.manager_user_id) LEFT JOIN branches br ON br.id = cu.branch_id' . ($activeOnly ? ' WHERE p.is_active = 1' : '') . ' ORDER BY p.is_active DESC, dp.sort_order, p.name_en');
    }
    private static function shapePerson(array $p): array {
        return ['person_id' => (int)$p['id'], 'user_id' => (int)($p['user_id'] ?? 0) ?: null, 'code' => (string)($p['emp_code'] ?? ''), 'name' => (string)$p['name_en'], 'name_el' => (string)($p['name_el'] ?? ''), 'job_title' => (string)($p['job_title'] ?? ''),
            'department' => (string)($p['department'] ?? ''), 'discipline' => (string)($p['discipline'] ?? ''), 'branch' => (string)($p['home_branch'] ?? ''), 'jurisdiction' => (string)($p['jurisdiction'] ?? ''), 'calendar' => (string)($p['work_calendar'] ?? ''),
            'email' => (string)($p['email'] ?? ''), 'phone_work' => (string)($p['phone_work'] ?? ''), 'extension' => (string)($p['extension'] ?? ''), 'phone_mobile' => (string)($p['phone_mobile'] ?? ''),
            'manager' => (string)($p['manager_name'] ?? ''), 'hire_date' => self::d($p['hire_date'] ?? null), 'contract_end' => self::d($p['contract_end'] ?? null), 'contract_type' => (string)($p['contract_type'] ?? ''), 'part_time' => !empty($p['part_time']),
            'technical' => !empty($p['is_technical']), 'active' => !empty($p['is_active']), 'left_date' => self::d($p['left_date'] ?? null)];
    }
    public static function people(): array {
        $rows = self::rows(true); $today = date('Y-m-d'); $mx = self::matrixFor($today, $today, $rows); $away = []; foreach ($mx['rows'] as $r) $away[$r['person_id']] = $r['cells'][$today] ?? null;
        $out = []; foreach ($rows as $p) { $s = self::shapePerson($p); $s['away_today'] = $away[(int)$p['id']] ?? null; $out[] = $s; }
        $alerts = [];
        if (function_exists('hr_contract_alerts')) foreach (hr_contract_alerts(60) as $a) $alerts[] = ['kind' => 'contract', 'person_id' => (int)$a['id'], 'person' => (string)$a['name_en'], 'date' => self::d($a['contract_end']), 'days' => (int)$a['left_days'], 'title' => ''];
        if (function_exists('hr_cert_alerts')) foreach (hr_cert_alerts(90) as $a) $alerts[] = ['kind' => 'certificate', 'person_id' => (int)$a['id'], 'person' => (string)$a['name_en'], 'date' => self::d($a['cert_expiry']), 'days' => (int)($a['left_days'] ?? 0), 'title' => (string)($a['title'] ?? '')];
        return ['people' => $out, 'alerts' => $alerts, 'departments' => array_values(array_unique(array_filter(array_map(fn($p) => (string)($p['department'] ?? ''), $rows))))];
    }

    /* ------------------------------------------------------------------ away / calendar */
    private static function matrixFor(string $from, string $to, array $people): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new ApiException(422, 'from / to');
        if ((strtotime($to) - strtotime($from)) / 86400 > self::MATRIX_MAX_DAYS) throw new ApiException(422, 'Range of max ' . self::MATRIX_MAX_DAYS . ' days');
        $days = []; for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $days[] = date('Y-m-d', $d);
        $m = $people && function_exists('hr_lv_matrix') ? hr_lv_matrix($from, $to, $people) : [];
        $rows = [];
        foreach ($people as $p) { $cells = []; foreach ($m[(int)$p['id']] ?? [] as $date => $c) $cells[$date] = ['code' => (string)$c['code'], 'status' => (string)$c['status']]; if ($cells) $rows[] = ['person_id' => (int)$p['id'], 'name' => (string)$p['name_en'], 'department' => (string)($p['department'] ?? ''), 'cells' => $cells]; }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'rows' => $rows];
    }
    /** Absences (codes only) for every active person in the range + the public holidays of each calendar. */
    public static function away(string $from, string $to): array {
        $mx = self::matrixFor($from, $to, self::rows(true));
        $hol = [];
        if (function_exists('wc_calendars')) foreach (array_keys(wc_calendars()) as $code) { foreach ($mx['days'] as $d) { $h = wc_holiday((string)$code, $d); if ($h) $hol[] = ['calendar' => (string)$code, 'date' => $d, 'name' => (string)$h]; } }
        $mx['holidays'] = $hol; $mx['types'] = [];
        if (function_exists('hr_types')) foreach (hr_types(false) as $t) $mx['types'][] = ['id' => (int)$t['id'], 'code' => (string)$t['code'], 'name' => (string)(function_exists('hr_type_name') ? hr_type_name($t) : $t['name'])];
        return $mx;
    }

    /* ------------------------------------------------------------------ balances */
    public static function balances(int $year): array {
        if ($year < 2000 || $year > 2100) $year = (int) date('Y');
        $types = function_exists('hr_types') ? array_values(hr_types(true)) : []; $out = [];
        foreach (self::rows(true) as $p) { $full = hr_person((int)$p['id']); if (!$full) continue; $bal = [];
            foreach ($types as $t) { $b = hr_balance($full, (int)$t['id'], $year); if (empty($b['counts'])) continue; $bal[] = ['type_id' => (int)$t['id'], 'code' => (string)$t['code'], 'type' => (string)(function_exists('hr_type_name') ? hr_type_name($t) : $t['name']), 'unit' => (string)($t['unit'] ?? 'days'), 'by_law' => function_exists('hr_type_by_law') && hr_type_by_law($t), 'entitled' => self::num($b['total']), 'opening' => self::num($b['opening']), 'taken' => self::num($b['taken']), 'pending' => self::num($b['pending']), 'available' => self::num($b['available'])]; }
            $out[] = ['person_id' => (int)$p['id'], 'name' => (string)$p['name_en'], 'department' => (string)($p['department'] ?? ''), 'balances' => $bal]; }
        return ['year' => $year, 'people' => $out];
    }

    /* ------------------------------------------------------------------ inbox */
    private static function shapeRequest(array $r, int $me): array {
        $t = function_exists('hr_type') ? hr_type((int)$r['type_id']) : null;
        return ['id' => (int)$r['id'], 'person_id' => (int)$r['person_id'], 'person' => (string)$r['name_en'], 'type_id' => (int)$r['type_id'], 'code' => (string)$r['code'], 'type' => $t && function_exists('hr_type_name') ? hr_type_name($t) : (string)$r['type_name'], 'unit' => (string)($r['unit'] ?? 'days'),
            'from' => self::d($r['date_from']), 'to' => self::d($r['date_to']), 'reason_label' => !empty($r['reason_code']) && function_exists('hr_reason_label') ? hr_reason_label($r['reason_code']) : null, 'doc_due' => !$r['doc_path'] && !empty($r['doc_due']) ? self::d($r['doc_due']) : null, 'time_from' => $r['time_from'] ?? null, 'time_to' => $r['time_to'] ?? null, 'hours' => self::num($r['hours']), 'days' => self::num($r['days']), 'reason' => (string)($r['reason'] ?? ''), 'status' => (string)$r['status'], 'doc' => !empty($r['doc_path']) ? ['name' => (string)$r['doc_name']] : null, 'created_at' => (string)$r['created_at'],
            'manager' => $r['manager_at'] ? ['name' => (string)$r['manager_name'], 'at' => (string)$r['manager_at'], 'note' => (string)($r['manager_note'] ?? '')] : ['name' => (string)($r['manager_name'] ?? ''), 'at' => null, 'note' => ''], 'hr' => $r['hr_at'] ? ['name' => (string)($r['hr_name'] ?? ''), 'at' => (string)$r['hr_at'], 'note' => (string)($r['hr_note'] ?? '')] : null,
            'stage' => $r['status'] === 'pending_mgr' ? 'manager' : ($r['status'] === 'pending_hr' ? 'hr' : 'done'), 'decidable' => in_array($r['status'], ['pending_mgr', 'pending_hr'], true)];
    }
    public static function inbox(array $u): array {
        $me = (int)$u['id']; $reqs = []; $tr = []; $ts = [];
        if (function_exists('hr_requests_for')) foreach (hr_requests_for('hr') as $r) { if (!in_array($r['status'], ['pending_mgr', 'pending_hr'], true)) continue; $reqs[] = self::shapeRequest($r, $me); if (count($reqs) >= self::INBOX_MAX) break; }
        if (function_exists('hr_training_queue')) foreach (hr_training_queue() as $m) $tr[] = ['id' => (int)$m['id'], 'training_id' => (int)$m['training_id'], 'person_id' => (int)$m['person_id'], 'person' => (string)$m['name_en'], 'title' => (string)$m['title'], 'kind' => (string)($m['kind'] ?? ''), 'date' => self::d($m['date_from'] ?? null), 'note' => (string)($m['note'] ?? ''), 'applied_at' => (string)($m['applied_at'] ?? '')];
        if (function_exists('ts_to_approve')) foreach (ts_to_approve() as $t) $ts[] = ['id' => (int)$t['id'], 'ts_no' => (string)$t['ts_no'], 'user_id' => (int)$t['user_id'], 'user' => (string)($t['user_name'] ?? ''), 'project_code' => (string)($t['project_code'] ?? ''), 'project' => (string)($t['project_name'] ?? ''), 'period_start' => self::d($t['period_start'] ?? null), 'period_end' => self::d($t['period_end'] ?? null),
            'hours_normal' => (float)($t['h_normal'] ?? 0), 'hours_overtime' => (float)($t['h_overtime'] ?? 0), 'hours_holiday' => (float)($t['h_holiday'] ?? 0), 'submitted_at' => (string)($t['submitted_at'] ?? '')];
        return ['requests' => $reqs, 'trainings' => $tr, 'timesheets' => Response::noMoney($ts)];
    }
    /** Decided requests of the year (history), newest first, max 300. */
    public static function history(int $year): array {
        $out = []; if (function_exists('hr_requests_for')) foreach (hr_requests_for('hr', $year) as $r) { if (in_array($r['status'], ['pending_mgr', 'pending_hr'], true)) continue; $out[] = self::shapeRequest($r, 0); if (count($out) >= 300) break; }
        return ['year' => $year, 'requests' => $out];
    }

    /* ------------------------------------------------------------------ decisions (step-up when the account has an authenticator) */
    public static function decideRequest(array $u, array $p): array {
        PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $id = (int)($p['request_id'] ?? 0); $r = one('SELECT id, person_id, status, type_id, date_from FROM hr_requests WHERE id = ?', [$id]); if (!$r) throw new ApiException(404, 'Request not found');
        if (!in_array($r['status'], ['pending_mgr', 'pending_hr'], true)) throw new ApiException(409, 'Request is no longer pending');
        $approve = !empty($p['approve']); $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 300);
        try { hr_request_decide($id, $approve, $note); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        if (function_exists('hr_audit')) hr_audit((int)$r['person_id'], 'request', (hr_type((int)$r['type_id'])['code'] ?? '') . ' ' . $r['date_from'], (string)$r['status'], $approve ? 'approved' : 'rejected', trim($note . ' (Golden HR app)'));
        return ['id' => 'HR' . $id, 'status' => (string)(scalar('SELECT status FROM hr_requests WHERE id = ?', [$id]) ?: '')];
    }
    public static function decideTraining(array $u, array $p): array {
        PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $mid = (int)($p['member_id'] ?? 0); $m = one('SELECT id, status FROM hr_training_members WHERE id = ?', [$mid]); if (!$m) throw new ApiException(404, 'Application not found');
        if ($m['status'] !== 'applied') throw new ApiException(409, 'Application already decided');
        $_POST = ['status' => !empty($p['approve']) ? 'approved' : 'rejected', 'note' => mb_substr(trim((string)($p['note'] ?? '')), 0, 300)]; $_FILES = [];
        try { hr_training_member_save($mid); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['id' => 'TR' . $mid, 'status' => (string)$_POST['status']];
    }

    /* ------------------------------------------------------------------ messages */
    public static function threads(): array {
        if (!function_exists('hr_msg_has') || !hr_msg_has()) return ['threads' => []];
        $rows = all('SELECT m.person_id, p.name_en, MAX(m.created_at) AS last_at, SUM(CASE WHEN m.from_hr = 0 AND m.seen_at IS NULL THEN 1 ELSE 0 END) AS unread, COUNT(*) AS n FROM hr_messages m JOIN hr_people p ON p.id = m.person_id GROUP BY m.person_id, p.name_en ORDER BY last_at DESC LIMIT 200');
        $out = []; foreach ($rows as $r) { $last = one('SELECT body, from_hr FROM hr_messages WHERE person_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [(int)$r['person_id']]);
            $out[] = ['person_id' => (int)$r['person_id'], 'person' => (string)$r['name_en'], 'last_at' => (string)$r['last_at'], 'unread' => (int)$r['unread'], 'count' => (int)$r['n'], 'last' => mb_substr((string)($last['body'] ?? ''), 0, 120), 'last_from_hr' => !empty($last['from_hr'])]; }
        return ['threads' => $out];
    }
    public static function thread(int $pid): array {
        $p = hr_person($pid); if (!$p) throw new ApiException(404, 'Person not found');
        $items = []; foreach (hr_messages($pid) as $m) $items[] = ['id' => (int)$m['id'], 'from_hr' => !empty($m['from_hr']), 'from' => (string)($m['from_name'] ?? ''), 'body' => (string)$m['body'], 'at' => (string)$m['created_at'], 'seen' => $m['seen_at'] !== null];
        hr_messages_mark_seen($pid);
        return ['person_id' => $pid, 'person' => (string)$p['name_en'], 'items' => $items];
    }
    public static function send(array $u, array $p): array {
        $pid = (int)($p['person_id'] ?? 0); $body = mb_substr(trim((string)($p['body'] ?? '')), 0, 2000); if (!$pid || $body === '') throw new ApiException(422, 'person_id and body');
        $_POST = ['person_id' => $pid, 'body' => $body];
        try { $id = hr_message_send_from_post(); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['id' => $id];
    }
}

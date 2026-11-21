<?php
/**
 * Golden HR — demonstration data set (GAS-CP-R97-169).
 * An account marked «Demonstration account» (customer_users.is_demo = 1) never reads or writes the real HR module: every
 * route of the app is answered from the invented company below, in the same shapes as PortalHR / PortalHRMore. Used for
 * the store review, training and presentations. Decisions (approve, reject, evaluate, accept…) are kept per account in a
 * small file under the private storage and start again at every sign-in — nothing reaches the database, no e-mail is sent.
 * All people, candidates and figures are fictitious.
 */
declare(strict_types=1);

final class PortalHRDemo {
    private static function day(int $plus): string { return date('Y-m-d', strtotime(($plus >= 0 ? '+' : '') . $plus . ' days')); }
    private static function at(int $plusDays, string $time = '09:30:00'): string { return self::day($plusDays) . ' ' . $time; }

    /* ------------------------------------------------------------------ state of the account (private storage, reset at sign-in) */
    private static function file(array $u): string { $d = rtrim(cp_storage(), '/') . '/demo'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d . '/hr_' . (int)$u['id'] . '.json'; }
    private static function state(array $u): array { $f = self::file($u); $s = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null; return is_array($s) ? $s : []; }
    private static function save(array $u, array $s): void { @file_put_contents(self::file($u), json_encode($s), LOCK_EX); }
    public static function reset(array $u): void { @unlink(self::file($u)); }

    /* ------------------------------------------------------------------ the invented company */
    private static function people(): array {
        $p = [
            [101, 'Elena Markou',     'HR Manager',              'Human Resources', 'Alexis Vrettos', '2019-03-04', null],
            [102, 'Petros Vellis',    'Electrical Engineer',     'Engineering',     'Daphne Kouri',   '2021-09-13', null],
            [103, 'Sofia Rendi',      'Automation Engineer',     'Engineering',     'Daphne Kouri',   '2022-02-01', null],
            [104, 'Marco Bellini',    'Service Engineer',        'Service',         'Nikolas Aravos', '2020-06-15', null],
            [105, 'Ivan Dobrev',      'Electrical Installer',    'Production',      'Stelios Kantas', '2023-01-09', self::day(45)],
            [106, 'Oksana Melnyk',    'Electrical Installer',    'Production',      'Stelios Kantas', '2023-05-22', null],
            [107, 'Daphne Kouri',     'Engineering Manager',     'Engineering',     'Alexis Vrettos', '2017-11-06', null],
            [108, 'Luca Ferraro',     'Project Manager',         'Projects',        'Alexis Vrettos', '2018-04-16', null],
            [109, 'Maria Stavrou',    'Purchasing Officer',      'Purchasing',      'Alexis Vrettos', '2020-10-05', null],
            [110, 'Nikolas Aravos',   'Service Manager',         'Service',         'Alexis Vrettos', '2016-01-18', null],
            [111, 'Thanos Lykos',     'Junior Engineer',         'Engineering',     'Daphne Kouri',   self::day(-20), self::day(160)],
        ];
        $out = [];
        foreach ($p as $i => [$id, $name, $job, $dep, $mgr, $hire, $end]) $out[] = ['person_id' => $id, 'user_id' => 900 + $i, 'code' => 'D' . $id, 'name' => $name, 'name_el' => '', 'job_title' => $job, 'department' => $dep, 'discipline' => $dep === 'Engineering' ? 'Electrical' : '',
            'branch' => 'GR', 'jurisdiction' => 'GR', 'calendar' => 'GR', 'email' => strtolower(strtok($name, ' ')) . '@example.com', 'phone_work' => '+30 210 000 0' . $id, 'extension' => (string)$id, 'phone_mobile' => '', 'manager' => $mgr, 'hire_date' => $hire,
            'contract_end' => $end, 'contract_type' => $end ? 'fixed' : 'indefinite', 'part_time' => false, 'technical' => in_array($dep, ['Engineering', 'Service', 'Production'], true), 'active' => true, 'left_date' => null];
        return $out;
    }
    private static function nameOf(int $pid): string { foreach (self::people() as $p) if ($p['person_id'] === $pid) return $p['name']; return ''; }
    private static function types(): array { return [['id' => 1, 'code' => 'ANN', 'name' => 'Annual leave'], ['id' => 2, 'code' => 'TRN', 'name' => 'Training'], ['id' => 3, 'code' => 'REM', 'name' => 'Remote work'], ['id' => 4, 'code' => 'CMP', 'name' => 'Compensatory day']]; }
    private static function req(int $id, int $pid, int $type, int $from, int $to, float $days, string $status, string $reason = '', ?string $hrNote = null): array {
        $t = self::types()[$type - 1]; $mgr = ''; foreach (self::people() as $p) if ($p['person_id'] === $pid) $mgr = $p['manager'];
        return ['id' => $id, 'person_id' => $pid, 'person' => self::nameOf($pid), 'type_id' => $type, 'code' => $t['code'], 'type' => $t['name'], 'unit' => 'days', 'from' => self::day($from), 'to' => self::day($to), 'reason_label' => null, 'doc_due' => null, 'time_from' => null, 'time_to' => null,
            'hours' => null, 'days' => $days, 'reason' => $reason, 'status' => $status, 'doc' => null, 'created_at' => self::at(-3),
            'manager' => $status === 'pending_mgr' ? ['name' => $mgr, 'at' => null, 'note' => ''] : ['name' => $mgr, 'at' => self::at(-2, '11:10:00'), 'note' => ''],
            'hr' => in_array($status, ['approved', 'rejected'], true) ? ['name' => 'Elena Markou', 'at' => self::at(-1, '15:40:00'), 'note' => (string)$hrNote] : null,
            'stage' => $status === 'pending_mgr' ? 'manager' : ($status === 'pending_hr' ? 'hr' : 'done'), 'decidable' => in_array($status, ['pending_mgr', 'pending_hr'], true)];
    }
    private static function inboxAll(): array {
        return ['requests' => [self::req(501, 102, 1, 12, 16, 5, 'pending_hr', 'Family trip'), self::req(502, 106, 1, 20, 21, 2, 'pending_hr'), self::req(503, 104, 3, 5, 5, 1, 'pending_hr', 'Waiting for a delivery at home'), self::req(504, 111, 1, 30, 32, 3, 'pending_mgr')],
            'trainings' => [['id' => 601, 'training_id' => 61, 'person_id' => 103, 'person' => 'Sofia Rendi', 'title' => 'PLC programming — advanced course', 'kind' => 'external', 'date' => self::day(25), 'note' => 'Needed for the next automation project.', 'applied_at' => self::at(-2)]],
            'timesheets' => [['id' => 701, 'ts_no' => 'TS-DEMO-0042', 'user_id' => 903, 'user' => 'Marco Bellini', 'project_code' => 'PRJ.DEMO.01', 'project' => 'M/Y Demonstration — refit', 'period_start' => self::day(-9), 'period_end' => self::day(-3), 'hours_normal' => 40.0, 'hours_overtime' => 6.0, 'hours_holiday' => 0.0, 'submitted_at' => self::at(-1)]]];
    }
    public static function inbox(array $u): array {
        $s = self::state($u); $all = self::inboxAll();
        $all['requests'] = array_values(array_filter($all['requests'], fn($r) => empty($s['req'][$r['id']]))); $all['trainings'] = array_values(array_filter($all['trainings'], fn($r) => empty($s['trn'][$r['id']]))); $all['timesheets'] = array_values(array_filter($all['timesheets'], fn($r) => empty($s['ts'][$r['id']])));
        return $all;
    }
    public static function history(array $u, int $year): array {
        $rows = [self::req(491, 108, 1, -30, -26, 5, 'approved'), self::req(492, 109, 4, -14, -14, 1, 'approved'), self::req(493, 105, 1, -8, -7, 2, 'rejected', '', 'Site installation that week.'), self::req(494, 107, 3, -5, -5, 1, 'approved')];
        $s = self::state($u); foreach (self::inboxAll()['requests'] as $r) if (!empty($s['req'][$r['id']])) { $r['status'] = $s['req'][$r['id']]; $r['stage'] = 'done'; $r['decidable'] = false; $r['hr'] = ['name' => (string)$u['full_name'], 'at' => date('Y-m-d H:i:s'), 'note' => '']; array_unshift($rows, $r); }
        return ['year' => $year, 'requests' => $rows];
    }
    private static function cellsOf(int $pid): array {
        $plan = [108 => [[0, 2, 'ANN']], 103 => [[3, 3, 'TRN']], 109 => [[6, 8, 'ANN']], 104 => [[-2, -1, 'CMP']], 106 => [[14, 18, 'ANN']]]; $c = [];
        foreach ($plan[$pid] ?? [] as [$a, $b, $code]) for ($i = $a; $i <= $b; $i++) $c[self::day($i)] = ['code' => $code, 'status' => 'approved'];
        return $c;
    }
    public static function away(string $from, string $to): array {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from) throw new ApiException(422, 'from / to');
        $days = []; for ($d = strtotime($from), $n = 0; $d <= strtotime($to) && $n < 63; $d += 86400, $n++) $days[] = date('Y-m-d', $d);
        $rows = []; foreach (self::people() as $p) { $c = array_intersect_key(self::cellsOf($p['person_id']), array_flip($days)); if ($c) $rows[] = ['person_id' => $p['person_id'], 'name' => $p['name'], 'department' => $p['department'], 'cells' => $c]; }
        return ['from' => $from, 'to' => $to, 'days' => $days, 'rows' => $rows, 'holidays' => [], 'types' => self::types()];
    }
    public static function peopleList(): array {
        $today = date('Y-m-d'); $out = []; foreach (self::people() as $p) { $p['away_today'] = self::cellsOf($p['person_id'])[$today] ?? null; $out[] = $p; }
        return ['people' => $out, 'alerts' => [['kind' => 'contract', 'person_id' => 105, 'person' => 'Ivan Dobrev', 'date' => self::day(45), 'days' => 45, 'title' => ''], ['kind' => 'certificate', 'person_id' => 104, 'person' => 'Marco Bellini', 'date' => self::day(28), 'days' => 28, 'title' => 'Working at height']],
            'departments' => array_values(array_unique(array_column($out, 'department')))];
    }
    public static function balances(int $year): array {
        $out = []; foreach (self::people() as $i => $p) { $taken = [4, 9, 6, 12, 3, 7, 10, 8, 5, 11, 0][$i]; $ent = $i === 10 ? 2 : 25;
            $out[] = ['person_id' => $p['person_id'], 'name' => $p['name'], 'department' => $p['department'], 'balances' => [
                ['type_id' => 1, 'code' => 'ANN', 'type' => 'Annual leave', 'unit' => 'days', 'by_law' => true, 'entitled' => (float)$ent, 'opening' => 0.0, 'taken' => (float)min($taken, $ent), 'pending' => 0.0, 'available' => (float)max(0, $ent - $taken)],
                ['type_id' => 4, 'code' => 'CMP', 'type' => 'Compensatory day', 'unit' => 'days', 'by_law' => false, 'entitled' => 2.0, 'opening' => 0.0, 'taken' => (float)($i % 2), 'pending' => 0.0, 'available' => (float)(2 - $i % 2)]]]; }
        return ['year' => $year, 'people' => $out];
    }

    /* ------------------------------------------------------------------ messages */
    private static function msgs(array $u): array {
        $m = [102 => [['id' => 1, 'from_hr' => false, 'from' => 'Petros Vellis', 'body' => 'Good morning — can I move my leave one week later?', 'at' => self::at(-1, '10:05:00'), 'seen' => false]],
              105 => [['id' => 2, 'from_hr' => true, 'from' => 'Elena Markou', 'body' => 'Your contract ends next month; please pass by HR for the renewal.', 'at' => self::at(-4, '12:00:00'), 'seen' => true], ['id' => 3, 'from_hr' => false, 'from' => 'Ivan Dobrev', 'body' => 'Thank you, I will come on Thursday.', 'at' => self::at(-4, '12:40:00'), 'seen' => true]]];
        $s = self::state($u); foreach (($s['msg'] ?? []) as $pid => $list) foreach ($list as $x) $m[(int)$pid][] = $x;
        foreach (array_keys($s['seen'] ?? []) as $pid) foreach ($m[(int)$pid] ?? [] as $k => $x) $m[(int)$pid][$k]['seen'] = true;
        return $m;
    }
    public static function threads(array $u): array {
        $out = []; foreach (self::msgs($u) as $pid => $list) { $last = end($list); $out[] = ['person_id' => (int)$pid, 'person' => self::nameOf((int)$pid), 'last_at' => $last['at'], 'unread' => count(array_filter($list, fn($x) => !$x['from_hr'] && !$x['seen'])), 'count' => count($list), 'last' => mb_substr($last['body'], 0, 120), 'last_from_hr' => $last['from_hr']]; }
        usort($out, fn($a, $b) => strcmp($b['last_at'], $a['last_at'])); return ['threads' => $out];
    }
    public static function thread(array $u, int $pid): array {
        if (self::nameOf($pid) === '') throw new ApiException(404, 'Person not found'); $items = self::msgs($u)[$pid] ?? [];
        $s = self::state($u); $s['seen'][$pid] = 1; self::save($u, $s);
        return ['person_id' => $pid, 'person' => self::nameOf($pid), 'items' => $items];
    }
    public static function send(array $u, array $p): array {
        $pid = (int)($p['person_id'] ?? 0); $body = mb_substr(trim((string)($p['body'] ?? '')), 0, 2000); if (self::nameOf($pid) === '' || $body === '') throw new ApiException(422, 'person_id and body');
        $s = self::state($u); $id = 1000 + count($s['msg'][$pid] ?? []) + $pid; $s['msg'][$pid][] = ['id' => $id, 'from_hr' => true, 'from' => (string)$u['full_name'], 'body' => $body, 'at' => date('Y-m-d H:i:s'), 'seen' => true]; self::save($u, $s);
        return ['id' => $id];
    }

    /* ------------------------------------------------------------------ recruiting */
    private static function apps(array $u): array {
        $base = [
            [801, 'APP-DEMO-0007', 'Christina Valeri', 81, 'cv_received', 9, 10, null, 0, -1, 'Service Engineer — marine automation', 'VAC-DEMO-0002'],
            [802, 'APP-DEMO-0005', 'Dimitri Roussel',  81, 'shortlisted', 8, 10, 4.1, 1, -5, 'Service Engineer — marine automation', 'VAC-DEMO-0002'],
            [803, 'APP-DEMO-0003', 'Andrea Conti',     82, 'interview',   10, 10, 4.4, 2, -9, 'Project Manager — refit projects', 'VAC-DEMO-0001'],
            [804, 'GEN-DEMO-0001', 'Yana Kovalenko',   83, 'cv_received', null, null, null, 0, -2, 'General application', 'VAC-GENERAL'],
        ];
        $s = self::state($u); $lab = self::labels()['status']; $out = [];
        foreach ($base as [$id, $code, $name, $vid, $st, $sc, $mx, $avg, $n, $d, $vac, $vcode]) { $st = $s['app'][$id]['status'] ?? $st; $extra = count($s['app'][$id]['evals'] ?? []);
            $out[$id] = ['id' => $id, 'code' => $code, 'name' => $name, 'vacancy_id' => $vid, 'vacancy' => $vac, 'vacancy_code' => $vcode, 'general' => $vid === 83, 'status' => $st, 'status_label' => $lab[$st] ?? $st, 'passed' => $sc !== null, 'score' => $sc, 'max_score' => $mx,
                'eval_avg' => $avg, 'eval_count' => $n + $extra, 'rating' => null, 'has_cv' => true, 'cv_pdf' => true, 'cv_name' => 'CV ' . $name . '.pdf', 'applied' => self::day($d), 'since' => self::day($d), 'days' => -$d]; }
        return $out;
    }
    private static function labels(): array {
        return ['status' => ['screened' => 'Screened', 'cv_received' => 'CV received', 'shortlisted' => 'Shortlisted', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired', 'rejected' => 'Not selected', 'withdrawn' => 'Withdrawn'],
            'stage' => ['screening' => 'CV screening', 'phone' => 'Phone interview', 'interview' => 'Interview', 'technical' => 'Technical interview', 'final' => 'Final interview'],
            'reco' => ['strong_yes' => 'Strongly recommended', 'yes' => 'Recommended', 'hold' => 'Keep on hold', 'no' => 'Not recommended'], 'can_set' => ['shortlisted', 'interview', 'offer', 'rejected']];
    }
    private static function interviews(array $u): array {
        $s = self::state($u);
        return [['id' => 851, 'application_id' => 803, 'name' => 'Andrea Conti', 'vacancy' => 'Project Manager — refit projects', 'stage' => 'final', 'stage_label' => 'Final interview', 'at' => self::at(1, '11:00:00'), 'duration' => 60, 'mode' => 'onsite', 'mode_label' => 'At the office',
            'location' => 'Head office — meeting room 1', 'interviewers' => 'Alexis Vrettos, Luca Ferraro', 'status' => (string)($s['int'][851] ?? 'planned')]];
    }
    private static function rcCounts(array $u): array { $a = self::apps($u); return ['open' => 2, 'new' => count(array_filter($a, fn($x) => $x['status'] === 'cv_received')), 'active' => count(array_filter($a, fn($x) => in_array($x['status'], ['shortlisted', 'interview', 'offer'], true))), 'interviews_today' => 0]; }
    public static function recruiting(array $u): array {
        $a = self::apps($u); $st = fn(int $v) => array_count_values(array_column(array_filter($a, fn($x) => $x['vacancy_id'] === $v), 'status'));
        return ['vacancies' => [
                ['id' => 82, 'code' => 'VAC-DEMO-0001', 'title' => 'Project Manager — refit projects', 'department' => 'Projects', 'branch' => 'GR', 'hiring_manager' => 'Alexis Vrettos', 'published' => self::day(-21), 'closes' => self::day(9), 'n_apps' => 1, 'n_passed' => 1, 'n_active' => 1, 'stages' => $st(82)],
                ['id' => 81, 'code' => 'VAC-DEMO-0002', 'title' => 'Service Engineer — marine automation', 'department' => 'Service', 'branch' => 'GR', 'hiring_manager' => 'Nikolas Aravos', 'published' => self::day(-12), 'closes' => self::day(18), 'n_apps' => 2, 'n_passed' => 2, 'n_active' => 1, 'stages' => $st(81)]],
            'applications' => array_values(array_filter($a, fn($x) => in_array($x['status'], ['cv_received', 'shortlisted', 'interview', 'offer'], true))),
            'interviews' => array_values(array_filter(self::interviews($u), fn($i) => $i['status'] === 'planned')),
            'criteria' => [['key' => 'technical', 'label' => 'Technical knowledge', 'weight' => 40.0], ['key' => 'experience', 'label' => 'Relevant experience', 'weight' => 30.0], ['key' => 'communication', 'label' => 'Communication', 'weight' => 15.0], ['key' => 'fit', 'label' => 'Fit with the team', 'weight' => 15.0]],
            'labels' => self::labels(), 'counts' => self::rcCounts($u)];
    }
    public static function application(array $u, int $id): array {
        $a = self::apps($u)[$id] ?? null; if (!$a) throw new ApiException(404, 'Application not found'); $s = self::state($u); $L = self::labels();
        $o = $a + ['email' => strtolower(strtok($a['name'], ' ')) . '@example.com', 'phone' => '+30 690 000 0' . $id, 'cover_note' => 'I have worked for six years on yacht electrical and automation systems and I would like to join your team.', 'department_interest' => $a['general'] ? 'Engineering' : '',
            'hr_notes' => (string)($s['app'][$id]['notes'] ?? ''), 'source' => 'website', 'lang' => 'en'];
        $ev = $a['eval_count'] - count($s['app'][$id]['evals'] ?? []) > 0 ? [['id' => 1, 'stage' => 'screening', 'stage_label' => $L['stage']['screening'], 'by' => 'Elena Markou', 'total' => (float)($a['eval_avg'] ?? 4.0), 'reco' => 'yes', 'reco_label' => $L['reco']['yes'],
            'strengths' => 'Solid commissioning experience on board.', 'concerns' => 'Notice period of two months.', 'notes' => '', 'at' => self::at(-4)]] : [];
        foreach (($s['app'][$id]['evals'] ?? []) as $k => $e) $ev[] = ['id' => 100 + $k, 'stage' => $e['stage'], 'stage_label' => $L['stage'][$e['stage']] ?? $e['stage'], 'by' => (string)$u['full_name'], 'total' => (float)$e['total'], 'reco' => $e['reco'], 'reco_label' => $L['reco'][$e['reco']] ?? $e['reco'],
            'strengths' => $e['strengths'], 'concerns' => $e['concerns'], 'notes' => $e['notes'], 'at' => $e['at']];
        $o['evaluations'] = $ev; $o['interviews'] = array_values(array_filter(self::interviews($u), fn($i) => $i['application_id'] === $id));
        return $o;
    }
    public static function appStatus(array $u, int $id, array $p): array {
        $a = self::apps($u)[$id] ?? null; if (!$a) throw new ApiException(404, 'Application not found'); $st = (string)($p['status'] ?? '');
        if (!in_array($st, self::labels()['can_set'], true)) throw new ApiException(422, 'This status is set in the portal');
        $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 500); if ($st === 'rejected' && $note === '') throw new ApiException(422, 'A reason is required to reject');
        $s = self::state($u); $s['app'][$id]['status'] = $st; if ($note !== '') $s['app'][$id]['notes'] = trim(($s['app'][$id]['notes'] ?? '') . "\n[" . date('d/m/Y') . '] ' . $note); self::save($u, $s);
        return self::application($u, $id);
    }
    public static function appEvaluation(array $u, int $id, array $p): array {
        if (!isset(self::apps($u)[$id])) throw new ApiException(404, 'Application not found'); $sc = array_map('intval', (array)($p['score'] ?? [])); $L = self::labels();
        $stage = isset($L['stage'][(string)($p['stage'] ?? '')]) ? (string)$p['stage'] : 'interview'; $reco = isset($L['reco'][(string)($p['recommendation'] ?? '')]) ? (string)$p['recommendation'] : 'hold';
        $s = self::state($u); $s['app'][$id]['evals'][] = ['stage' => $stage, 'total' => $sc ? round(array_sum($sc) / count($sc), 1) : 0, 'reco' => $reco, 'strengths' => mb_substr((string)($p['strengths'] ?? ''), 0, 1000), 'concerns' => mb_substr((string)($p['concerns'] ?? ''), 0, 1000), 'notes' => mb_substr((string)($p['notes'] ?? ''), 0, 1000), 'at' => date('Y-m-d H:i:s')];
        self::save($u, $s); return self::application($u, $id);
    }
    public static function interviewStatus(array $u, int $id, array $p): array {
        $st = (string)($p['status'] ?? ''); if (!in_array($st, ['done', 'no_show', 'cancelled'], true)) throw new ApiException(422, 'status'); if ($id !== 851) throw new ApiException(404, 'Interview not found');
        $s = self::state($u); $s['int'][$id] = $st; self::save($u, $s); return ['id' => $id, 'status' => $st, 'application_id' => 803];
    }
    /** A one-page sample CV, built here — no file of a real candidate exists for this account. */
    public static function cv(array $u, int $id): void {
        $a = self::apps($u)[$id] ?? null; if (!$a) throw new ApiException(404, 'No CV');
        $lines = [[20, 'Curriculum vitae'], [15, $a['name']], [11, 'Sample document — demonstration data, not a real person'], [11, ''], [13, 'Profile'], [11, 'Engineer with six years of experience in yacht electrical and automation systems:'], [11, 'commissioning, fault finding on board and customer support.'], [11, ''],
            [13, 'Experience'], [11, '2021 - today   Service engineer, marine automation integrator'], [11, '2018 - 2021    Electrical technician, shipyard refit department'], [11, ''], [13, 'Education'], [11, 'Diploma in Electrical Engineering'], [11, ''], [13, 'Languages'], [11, 'English, Greek, Italian']];
        $txt = "BT\n"; $y = 790; foreach ($lines as [$sz, $l]) { $y -= $sz + 9; $txt .= "/F1 {$sz} Tf 1 0 0 1 60 {$y} Tm (" . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], preg_replace('/[^\x20-\x7E]/', '-', $l)) . ") Tj\n"; } $txt .= "ET";
        $obj = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>', '<< /Length ' . strlen($txt) . " >>\nstream\n" . $txt . "\nendstream", '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $pdf = "%PDF-1.4\n"; $off = []; foreach ($obj as $i => $o) { $off[] = strlen($pdf); $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n"; }
        $x = strlen($pdf); $pdf .= "xref\n0 6\n0000000000 65535 f \n"; foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o); $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$x}\n%%EOF";
        header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: no-store, private'); header('X-Content-Type-Options: nosniff'); echo $pdf; exit;
    }

    /* ------------------------------------------------------------------ job descriptions, HR year, expiring, equipment */
    public static function jds(array $u): array {
        if (!empty(self::state($u)['jd'][71])) return ['items' => []];
        $step = fn($k, $l, $st, $n, $at, $next, $mine) => ['key' => $k, 'label' => $l, 'state' => $st, 'name' => $n, 'at' => $at, 'next' => $next, 'mine' => $mine];
        return ['items' => [['id' => 71, 'code' => 'JD-DEMO-014', 'title' => 'Service Engineer', 'level' => 'Engineer', 'discipline' => 'Service', 'rev' => 'R2', 'rev_title' => 'Travel and on-call duties added', 'submitted_by' => 'Nikolas Aravos', 'submitted_at' => self::at(-2), 'days' => 2, 'people' => 3,
            'steps' => [$step('dm', 'Department manager', 'done', 'Nikolas Aravos', self::at(-2), false, false), $step('gm', 'General manager', 'wait', 'Elena Markou', null, true, true), $step('ceo', 'CEO', 'wait', 'Alexis Vrettos', null, false, false)], 'mine' => true,
            'reports_to' => 'Service Manager', 'purpose' => 'Commission, service and repair electrical and automation systems on board, and support the customer during and after delivery.',
            'sections' => [['key' => 'duties', 'lines' => ['Commissioning and fault finding of automation and electrical systems on board', 'Service reports after every visit', 'Support of the project team during sea trials', 'On-call duty by rotation']],
                           ['key' => 'qualifications', 'lines' => ['Degree or diploma in electrical / automation engineering', 'Three years of experience on vessels or in industry']], ['key' => 'skills', 'lines' => ['PLC and HMI diagnostics', 'English at working level', 'Driving licence']],
                           ['key' => 'conditions', 'lines' => ['Travel up to 40 % of the time', 'Work in shipyards and on board']]]]]];
    }
    public static function jdDecide(array $u, int $id, array $p): array { if ($id !== 71) throw new ApiException(404, 'Job description not found'); $s = self::state($u); $s['jd'][71] = !empty($p['approve']) ? 'approved' : 'draft'; self::save($u, $s); return ['id' => $id, 'status' => $s['jd'][71]]; }
    public static function year(array $u): array {
        $y = (int) date('Y'); $s = self::state($u); $m = (int) date('n');
        $def = [['entitlements', 'Entitlements of the year opened', 1], ['closures', 'Company closures assigned', 2], ['training_plan', 'Training plan approved', 3], ['mid_review', 'Mid-year reviews held', 6], ['summer_plan', 'Summer leave plan agreed', 5], ['evaluations', 'Annual evaluations completed', 11], ['carry_over', 'Leave carry-over decided', 12]];
        $steps = []; foreach ($def as [$k, $label, $mon]) { $ticked = $s['yr'][$k] ?? null; $done = $ticked !== 'undo' && ($ticked || $mon < $m - 1); $late = !$done && $mon < $m; $due = !$done && $mon === $m;
            $steps[] = ['key' => $k, 'year' => $y, 'label' => $label, 'month' => date('F', mktime(0, 0, 0, $mon, 1)), 'due' => sprintf('%04d-%02d-%02d', $y, $mon, (int) date('t', mktime(0, 0, 0, $mon, 1, $y))), 'state' => $done ? 'done' : ($late ? 'late' : ($due ? 'due' : 'later')), 'late_days' => $late ? 20 : 0,
                'figure' => $k === 'evaluations' ? '7 of 11' : '', 'auto' => false, 'tick' => $done ? ['by' => is_string($ticked) ? (string)$u['full_name'] : 'Elena Markou', 'at' => is_string($ticked) ? $ticked : sprintf('%04d-%02d-20 10:00:00', $y, $mon), 'note' => ''] : null]; }
        return ['years' => [['year' => $y, 'steps' => $steps]]];
    }
    public static function yearTick(array $u, array $p): array { $k = preg_replace('/[^a-z_]/', '', (string)($p['step'] ?? '')); if ($k === '') throw new ApiException(422, 'step'); $s = self::state($u); $s['yr'][$k] = !empty($p['undo']) ? 'undo' : date('Y-m-d H:i:s'); self::save($u, $s); return self::year($u); }
    public static function expiring(int $days): array {
        $days = max(30, min(120, $days)); $x = [['kind' => 'certificate', 'person_id' => 104, 'person' => 'Marco Bellini', 'title' => 'Working at height', 'date' => self::day(28), 'days' => 28], ['kind' => 'contract', 'person_id' => 105, 'person' => 'Ivan Dobrev', 'title' => '', 'date' => self::day(45), 'days' => 45],
            ['kind' => 'permit', 'person_id' => 106, 'person' => 'Oksana Melnyk', 'title' => 'Shipyard access pass · PRJ.DEMO.01', 'date' => self::day(12), 'days' => 12], ['kind' => 'equipment', 'person_id' => 102, 'person' => 'Petros Vellis', 'title' => 'Insulation tester — calibration', 'date' => self::day(55), 'days' => 55]];
        $x = array_values(array_filter($x, fn($i) => $i['days'] <= $days)); usort($x, fn($a, $b) => strcmp($a['date'], $b['date'])); return ['days' => $days, 'items' => $x];
    }
    private static function docs(array $u): array {
        $s = self::state($u); $d = fn($id, $kind, $no, $pid, $st, $days, $remark = '') => ['id' => $id, 'kind' => $kind, 'doc_no' => $no, 'date' => self::day(-$days), 'status' => (string)($s['eq'][$id] ?? $st), 'person_id' => $pid, 'person' => self::nameOf($pid), 'active' => true, 'days' => $days, 'note' => '', 'emp_at' => null, 'emp_name' => '', 'emp_remark' => $remark];
        return [951 => $d(951, 'return', 'EQR-DEMO-0003', 104, 'open', 1), 952 => $d(952, 'issue', 'EQI-DEMO-0011', 111, 'sent', 6)];
    }
    public static function equipment(array $u): array {
        $d = self::docs($u);
        return ['returns' => array_values(array_filter($d, fn($x) => $x['kind'] === 'return' && $x['status'] === 'open')), 'issues' => array_values(array_filter($d, fn($x) => $x['kind'] === 'issue' && in_array($x['status'], ['sent', 'disputed'], true))),
            'leavers' => [], 'new' => [['person_id' => 106, 'person' => 'Oksana Melnyk', 'hired' => '2023-05-22']], 'results' => ['ok' => 'Returned in order', 'damaged' => 'Damaged', 'missing' => 'Missing', 'writeoff' => 'Written off']];
    }
    public static function eqDoc(array $u, int $id): array {
        $o = self::docs($u)[$id] ?? null; if (!$o) throw new ApiException(404, 'List not found'); $it = fn($i, $cat, $icon, $desc, $sn, $qty) => ['id' => $i, 'category' => $cat, 'icon' => $icon, 'description' => $desc, 'serial_no' => $sn, 'asset_tag' => '', 'qty' => $qty, 'size' => '', 'cond_out' => 'Good', 'status' => $o['status'] === 'accepted' ? 'returned' : 'issued', 'note' => ''];
        $o['items'] = $id === 951 ? [$it(1, 'Tools', '🧰', 'Tool case — service set', 'DEMO-TC-014', '1'), $it(2, 'Instruments', '📟', 'Multimeter', 'DEMO-MM-220', '1'), $it(3, 'IT', '💻', 'Service laptop', 'DEMO-LT-031', '1')] : [$it(4, 'PPE', '🦺', 'Safety shoes', '', '1'), $it(5, 'PPE', '⛑', 'Helmet', '', '1'), $it(6, 'IT', '💻', 'Laptop', 'DEMO-LT-044', '1')];
        return $o;
    }
    public static function eqDone(array $u, int $id): array { if (!isset(self::docs($u)[$id])) throw new ApiException(404, 'List not found'); $s = self::state($u); $s['eq'][$id] = 'accepted'; self::save($u, $s); return self::eqDoc($u, $id); }

    /* ------------------------------------------------------------------ profile, today, sync */
    private static function counts(array $u): array { $ib = self::inbox($u); return ['inbox' => count($ib['requests']) + count($ib['trainings']) + count($ib['timesheets']), 'requests' => count($ib['requests']), 'trainings' => count($ib['trainings']), 'timesheets' => count($ib['timesheets']),
        'unread' => array_sum(array_column(self::threads($u)['threads'], 'unread')), 'away_today' => count(self::away(date('Y-m-d'), date('Y-m-d'))['rows'])]; }
    public static function today(array $u): array {
        $c = self::counts($u); $x = self::expiring(60)['items']; $e = self::equipment($u); $j = self::jds($u)['items']; $yr = self::year($u)['years'][0]['steps'];
        return ['inbox' => $c['inbox'], 'unread' => $c['unread'], 'away_today' => $c['away_today'], 'recruiting' => self::rcCounts($u), 'jd' => ['mine' => count($j), 'review' => count($j)],
            'year' => ['late' => count(array_filter($yr, fn($s) => $s['state'] === 'late')), 'due' => count(array_filter($yr, fn($s) => $s['state'] === 'due'))],
            'expiring' => ['d30' => count(array_filter($x, fn($i) => $i['days'] <= 30)), 'd60' => count($x), 'expired' => 0], 'equipment' => ['returns' => count($e['returns']), 'issues' => count($e['issues']), 'leavers' => 0, 'new' => count($e['new'])]];
    }
    public static function me(array $u): array {
        return ['id' => (int)$u['id'], 'name' => (string)$u['full_name'], 'email' => (string)$u['email'], 'role' => (string)$u['role'], 'discipline' => '', 'discipline_id' => 0, 'section_id' => 0, 'home_branch' => 'GR', 'branch_scope' => ['GR'], 'languages' => array_keys(LOCALES),
            'ai' => false, 'team_restricted' => false, 'calendar' => 'GR', 'hr' => true, 'hr_unread' => 0, 'totp_enabled' => false, 'min_app_version' => api_setting('api_min_version_hr', API_APPS['hr']), 'counts' => self::counts($u), 'today' => self::today($u), 'demo' => true];
    }
    /* R97-186: In / Out for the demonstration account. Two invented sites, two invented days; what the reviewer registers is kept in the demonstration file only. */
    private static function ioSites(): array { return [['id' => 1, 'name' => 'Perama yard', 'lat' => 37.965, 'lng' => 23.573, 'radius_m' => 300], ['id' => 2, 'name' => 'Head office Keratsini', 'lat' => 37.962, 'lng' => 23.619, 'radius_m' => 150]]; }
    private static function io(array $u): array {
        $st = self::state($u); $io = (array)($st['io'] ?? []); $mine = array_reverse((array)($io['entries'] ?? []));
        $mk = fn(int $id, string $kind, int $n, string $time, array $x = []) => $x + ['id' => $id, 'kind' => $kind, 'at' => self::day($n) . 'T' . $time . ':00Z', 'day' => self::day($n), 'time' => $time, 'lat' => 37.9651, 'lng' => 23.5732, 'acc' => 12, 'site' => 'Perama yard', 'dist' => 21, 'flags' => [], 'status' => 'auto', 'map' => 'https://www.google.com/maps?q=37.9651,23.5732', 'note' => null, 'late_sync' => false];
        $last = $mine[0] ?? null;
        return ['enabled' => true, 'consent' => ['accepted' => !empty($io['consent']), 'version' => '1', 'at' => $io['consent'] ?? null, 'title' => t('io.c_title'), 'text' => t('io.c_text'), 'accept' => t('io.c_accept')],
            'state' => $last ? ($last['kind'] === 'in' ? 'in' : 'out') : 'out', 'since' => $last['at'] ?? null, 'schedule' => ['workday' => true, 'start' => '08:30', 'end' => '17:00', 'tolerance_min' => 10], 'sites' => self::ioSites(),
            'entries' => array_merge($mine, [$mk(904, 'out', -1, '17:05'), $mk(903, 'in', -1, '08:27'), $mk(902, 'out', -2, '18:40'), $mk(901, 'in', -2, '09:02', ['flags' => ['late'], 'status' => 'approved', 'note' => 'Traffic on the bridge — agreed'])])];
    }
    private static function ioPunch(array $u, array $b): array {
        $st = self::state($u); if (empty($st['io']['consent'])) Response::error(409, 'Accept the In / Out notice first', ['consent' => true]);
        $kind = ($b['kind'] ?? '') === 'out' ? 'out' : 'in'; $ts = strtotime((string)($b['at'] ?? '')) ?: time(); $tz = max(-720, min(840, (int)($b['tz_min'] ?? 0))); $loc = $ts + $tz * 60;
        $lat = isset($b['lat']) && is_numeric($b['lat']) ? max(-90.0, min(90.0, (float)$b['lat'])) : null; $lng = isset($b['lng']) && is_numeric($b['lng']) ? max(-180.0, min(180.0, (float)$b['lng'])) : null; if ($lat === null || $lng === null) { $lat = $lng = null; }
        $best = null; if ($lat !== null) foreach (self::ioSites() as $site) { $m = (int) round(111320 * hypot($lat - $site['lat'], ($lng - $site['lng']) * cos(deg2rad($site['lat'])))); if (!$best || $m < $best['m']) $best = ['s' => $site, 'm' => $m]; }
        $fl = []; if ($lat === null) $fl[] = 'nogps'; elseif ($best && $best['m'] > $best['s']['radius_m']) $fl[] = 'outside';
        $n = count((array)($st['io']['entries'] ?? []));
        $e = ['id' => 950 + $n, 'kind' => $kind, 'at' => gmdate('c', $ts), 'day' => gmdate('Y-m-d', $loc), 'time' => gmdate('H:i', $loc), 'lat' => $lat, 'lng' => $lng, 'acc' => isset($b['acc']) ? max(0, min(100000, (int)$b['acc'])) : null,
            'site' => $best ? $best['s']['name'] : null, 'dist' => $best ? $best['m'] : null, 'flags' => $fl, 'status' => $fl ? 'pending' : 'auto', 'map' => $lat !== null ? 'https://www.google.com/maps?q=' . round($lat, 6) . ',' . round($lng, 6) : null, 'note' => null, 'late_sync' => false];
        if ($n < 40) { $st['io']['entries'][] = $e; self::save($u, $st); }
        return ['status' => 'accepted', 'state' => $kind, 'entry' => $e];
    }
    private static function sync(array $u, array $body): array {
        $s = self::state($u); $res = [];
        foreach (array_slice((array)($body['ops'] ?? []), 0, 50) as $op) { $pl = (array)($op['payload'] ?? []); $ok = true;
            switch ((string)($op['kind'] ?? '')) {
                case 'hr_app_decide': $s['req'][(int)($pl['request_id'] ?? 0)] = !empty($pl['approve']) ? 'approved' : 'rejected'; break;
                case 'hr_app_training': $s['trn'][(int)($pl['member_id'] ?? 0)] = 1; break;
                case 'hr_app_ts': $s['ts'][(int)($pl['ts_id'] ?? 0)] = 1; break;
                case 'io_consent': $s['io']['consent'] = gmdate('c'); break;   // R97-186
                case 'io_punch': break;
                default: $ok = false; }
            $res[] = $ok ? ['uuid' => (string)($op['id'] ?? ''), 'status' => 'accepted', 'id' => 'DEMO'] : ['uuid' => (string)($op['id'] ?? ''), 'status' => 'rejected', 'code' => 422, 'error' => 'Not available in the demonstration'];
        }
        self::save($u, $s); return ['results' => $res];
    }

    /** Answers every request of a demonstration account in Golden HR and ends the request. */
    public static function handle(string $method, string $path, array $body, array $u): void {
        $g = $method === 'GET'; $p = $method === 'POST'; $mm = [];
        if ($path === '/auth/logout') return;                                   // the real sign-out (device token) runs as usual
        if ($g && $path === '/me') Response::json(self::me($u));
        if ($p && $path === '/sync') Response::json(self::sync($u, $body));
        if ($g && $path === '/inout') Response::json(self::io($u));                                   // R97-186: In / Out of the demonstration — invented entries, nothing reaches the company records
        if ($p && $path === '/inout/consent') { $st = self::state($u); $st['io']['consent'] = gmdate('c'); self::save($u, $st); Response::json(['status' => 'accepted', 'consent' => self::io($u)['consent']]); }
        if ($p && $path === '/inout/punch') Response::json(self::ioPunch($u, $body), 201);
        if ($path === '/devices/push-token') Response::json([]);
        if ($g && $path === '/me/passkeys') Response::json(['items' => []]);
        if (str_starts_with($path, '/me/passkeys')) Response::error(403, 'Passkeys are not available for a demonstration account');
        if ($g && $path === '/items') Response::json(['items' => []]);
        if ($g && $path === '/requisitions') Response::json(['items' => [], 'can_approve' => false, 'can_create' => false]);
        if (str_starts_with($path, '/requisitions')) Response::error(403, 'Not available in the demonstration');
        if ($g && $path === '/hr/app/inbox') Response::json(self::inbox($u));
        if ($g && $path === '/hr/app/history') Response::json(self::history($u, (int)($_GET['year'] ?? date('Y'))));
        if ($g && $path === '/hr/app/people') Response::json(self::peopleList());
        if ($g && $path === '/hr/app/away') Response::json(self::away((string)($_GET['from'] ?? date('Y-m-d')), (string)($_GET['to'] ?? self::day(27))));
        if ($g && $path === '/hr/app/balances') Response::json(self::balances((int)($_GET['year'] ?? date('Y'))));
        if ($g && $path === '/hr/app/threads') Response::json(self::threads($u));
        if ($g && preg_match('#^/hr/app/thread/(\d+)$#', $path, $mm)) Response::json(self::thread($u, (int)$mm[1]));
        if ($p && $path === '/hr/app/send') Response::json(self::send($u, $body), 201);
        if ($g && $path === '/hr/app/today') Response::json(self::today($u));
        if ($g && $path === '/hr/app/recruiting') Response::json(self::recruiting($u));
        if ($g && preg_match('#^/hr/app/application/(\d+)$#', $path, $mm)) Response::json(self::application($u, (int)$mm[1]));
        if ($g && preg_match('#^/hr/app/cv/(\d+)$#', $path, $mm)) self::cv($u, (int)$mm[1]);
        if ($p && preg_match('#^/hr/app/application/(\d+)/status$#', $path, $mm)) Response::json(self::appStatus($u, (int)$mm[1], $body));
        if ($p && preg_match('#^/hr/app/application/(\d+)/evaluation$#', $path, $mm)) Response::json(self::appEvaluation($u, (int)$mm[1], $body), 201);
        if ($p && preg_match('#^/hr/app/interview/(\d+)/status$#', $path, $mm)) Response::json(self::interviewStatus($u, (int)$mm[1], $body));
        if ($g && $path === '/hr/app/jds') Response::json(self::jds($u));
        if ($p && preg_match('#^/hr/app/jd/(\d+)/decide$#', $path, $mm)) Response::json(self::jdDecide($u, (int)$mm[1], $body));
        if ($g && $path === '/hr/app/year') Response::json(self::year($u));
        if ($p && $path === '/hr/app/year/tick') Response::json(self::yearTick($u, $body));
        if ($g && $path === '/hr/app/expiring') Response::json(self::expiring((int)($_GET['days'] ?? 60)));
        if ($g && $path === '/hr/app/equipment') Response::json(self::equipment($u));
        if ($g && preg_match('#^/hr/app/equipment/doc/(\d+)$#', $path, $mm)) Response::json(self::eqDoc($u, (int)$mm[1]));
        if ($p && preg_match('#^/hr/app/equipment/doc/(\d+)/(receive|paper)$#', $path, $mm)) Response::json(self::eqDone($u, (int)$mm[1]));
        Response::error(404, 'Not available in the demonstration');
    }
}

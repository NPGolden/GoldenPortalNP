<?php
/**
 * My approvals — one register per signed-in member of staff over every approval process of the portal (GAS-CP-R97-161).
 *   apr_waiting()  what waits for MY decision now
 *   apr_mine()     what I asked for: waiting (and with whom), or decided in the last 30 days
 * Read-only: the decision itself is always taken on the page of the process, with its own rights check.
 * Every provider is guarded (module present + rights) and fails closed: an error in one process never empties the register.
 */

const APR_DAYS = 30;
/** The processes of the register: key => [icon, label key]. */
function apr_kinds(): array {
    return ['leave' => ['🏖', 'apr.k_leave'], 'timesheet' => ['⏱', 'apr.k_timesheet'], 'training' => ['🎓', 'apr.k_training'], 'requisition' => ['🛒', 'apr.k_requisition'],
            'payment' => ['💶', 'apr.k_payment'], 'eng' => ['📐', 'apr.k_eng'], 'bom' => ['🧾', 'apr.k_bom'], 'jd' => ['📄', 'apr.k_jd'], 'newsletter' => ['✉', 'apr.k_newsletter'],
            'equipment' => ['🧰', 'apr.k_equipment'], 'hra' => ['🤖', 'apr.k_hra'], 'change' => ['🛠', 'apr.k_change']];
}
function apr_me(): int { return (int)(current_user()['id'] ?? 0); }
function apr_age(?string $at): int { $ts = $at ? strtotime($at) : false; return $ts ? max(0, (int) floor((time() - $ts) / 86400)) : 0; }
function apr_row(string $kind, string $ref, string $title, ?string $who, ?string $at, string $url, array $x = []): array {
    return ['kind' => $kind, 'ref' => trim($ref), 'title' => trim($title), 'who' => trim((string)$who), 'at' => $at, 'age' => apr_age($at), 'url' => $url] + $x + ['state' => 'pending', 'with' => '', 'decided_at' => null];
}
/** Run one provider; nothing it throws reaches the page. */
function apr_try(array &$out, callable $fn): void { try { foreach ($fn() as $r) $out[] = $r; } catch (Throwable $e) {} }

/** Everything that waits for the decision of the signed-in user. */
function apr_waiting(): array {
    static $v = null; if ($v !== null) return $v;
    if (!is_staff() || !apr_me()) return $v = [];
    $me = apr_me(); $o = [];
    // Leave and absence requests — the manager's step, then HR
    if (function_exists('hr2_has') && hr2_has()) apr_try($o, function () {
        $r = []; $seen = [];
        if (function_exists('hr_is_manager') && hr_is_manager()) foreach (hr_requests_for('manager') as $x) if ($x['status'] === 'pending_mgr') { $seen[(int)$x['id']] = 1; $r[] = apr_row('leave', (string)$x['code'], $x['type_name'] . ' · ' . fmt_date($x['date_from']) . ' – ' . fmt_date($x['date_to']), $x['name_en'], $x['created_at'], url('hr_requests'), ['with' => t('apr.w_manager')]); }
        if (hr_can()) foreach (hr_requests_for('hr') as $x) if ($x['status'] === 'pending_hr' && empty($seen[(int)$x['id']])) $r[] = apr_row('leave', (string)$x['code'], $x['type_name'] . ' · ' . fmt_date($x['date_from']) . ' – ' . fmt_date($x['date_to']), $x['name_en'], $x['manager_at'] ?: $x['created_at'], url('hr_requests'), ['with' => t('apr.w_hr')]);
        return $r;
    });
    // Timesheets
    if (function_exists('ts_to_approve')) apr_try($o, fn() => array_map(fn($x) => apr_row('timesheet', (string)$x['ts_no'], fmt_date($x['period_start']) . ' – ' . fmt_date($x['period_end']) . (!empty($x['project_code']) ? ' · ' . $x['project_code'] : ''), $x['user_name'], $x['submitted_at'] ?? null, url('timesheet', ['id' => (int)$x['id']])), ts_to_approve()));
    // Training applications — HR, or the manager of the person
    if (function_exists('hr_training_queue') && function_exists('hr_can') && (hr_can() || (function_exists('hr_is_manager') && hr_is_manager()))) apr_try($o, fn() => array_map(fn($x) => apr_row('training', '', (string)$x['title'], $x['name_en'], $x['applied_at'], url('hr_training', ['id' => (int)$x['training_id']])), hr_training_queue()));
    // Purchase requisitions — Procurement, then Management when over budget
    if (function_exists('pr_admin') && function_exists('stk_has') && stk_has()) apr_try($o, function () {
        $st = []; if (pr_admin()) $st[] = 'submitted'; if (function_exists('mgmt_can') && mgmt_can()) $st[] = 'mgmt'; if (!$st) return [];
        return array_map(fn($x) => apr_row('requisition', (string)$x['pr_no'], (string)($x['title'] ?: $x['project_code']), $x['requester_name'], $x['status'] === 'mgmt' ? ($x['approved_at'] ?: $x['submitted_at']) : $x['submitted_at'], url('requisition', ['id' => (int)$x['id']]), ['with' => t($x['status'] === 'mgmt' ? 'apr.w_mgmt' : 'apr.w_proc'), 'prio' => (string)($x['priority'] ?? 'normal'), 'scope' => function_exists('prq_scope_text') ? prq_scope_text($x) : '']),   // R97-239
            all("SELECT r.id, r.pr_no, r.title, r.status, r.requester_name, r.submitted_at, r.approved_at, r.subproject_id, " . (function_exists('prq_prio_has') && prq_prio_has() ? 'r.priority' : "'normal' AS priority") . ", p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, (SELECT v.name FROM vessels v WHERE v.id = p.vessel_id) AS vessel FROM pr_requests r LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id WHERE r.status IN ('" . implode("','", $st) . "') ORDER BY " . (function_exists('prq_prio_has') && prq_prio_has() ? "FIELD(r.priority, 'urgent', 'high', 'normal', 'low'), " : '') . "r.submitted_at"));
    });
    // Supplier payment requests
    if (function_exists('si_my_pending')) apr_try($o, fn() => array_map(fn($x) => apr_row('payment', (string)$x['req_no'], $x['supplier'] . ' · ' . (function_exists('si_money') ? si_money((float)$x['amount'], $x['currency']) : number_format((float)$x['amount'], 2) . ' ' . $x['currency']), $x['requested_name'], $x['created_at'], url('payables', ['tab' => 'requests', 'id' => (int)$x['id']]), ['prio' => (string)($x['priority'] ?? 'normal'), 'scope' => function_exists('si_request_scope') ? si_request_scope((int)$x['id'])['text'] : '', 'with' => t($x['status'] === 'mgmt' ? 'apr.w_mgmt' : 'apr.w_acc'), 'late' => !empty($x['pay_by']) && $x['pay_by'] < date('Y-m-d')]), si_my_pending()));
    // Engineering extra hours
    if (function_exists('eng_requests') && function_exists('eng_can_decide')) apr_try($o, function () {
        $r = []; foreach (eng_requests(['status' => 'pending']) as $x) if (eng_can_decide(['project_id' => (int)$x['project_id']], (string)$x['stage'])) $r[] = apr_row('eng', trim(($x['project_code'] ?: '') . ' ' . ($x['sub_code'] ?: '')), $x['section'] . ' · +' . rtrim(rtrim(number_format((float)$x['hours'], 2, '.', ''), '0'), '.') . ' h', $x['requested_name'], $x['requested_at'], url('eng_requests'));
        return $r;
    });
    // Bills of materials issued by Engineering — Procurement approves
    if (function_exists('bom_pr_can') && bom_pr_can()) apr_try($o, fn() => array_map(fn($x) => apr_row('bom', trim(($x['project_code'] ?: '') . ' ' . ($x['sub_code'] ?: '')) . ' R' . (int)$x['revision'], (string)($x['title'] ?: $x['sub_name']), $x['issued_name'], $x['issued_at'], url('bom', ['id' => (int)$x['id']]), ['with' => t('apr.w_proc')]),
        all("SELECT b.id, b.revision, b.title, b.issued_name, b.issued_at, s.code AS sub_code, s.name AS sub_name, p.project_code FROM bom_revisions b JOIN subprojects s ON s.id = b.subproject_id JOIN projects p ON p.id = s.project_id WHERE b.status = 'issued' ORDER BY b.issued_at")));
    // Job descriptions — my step of DM › GM › CEO
    if (function_exists('jd_list') && function_exists('jd_steps')) apr_try($o, function () {
        $r = []; foreach (jd_list(['status' => 'review']) as $j) foreach (jd_steps($j) as $s) if ($s['mine']) $r[] = apr_row('jd', (string)$j['code'], (string)$j['title'], $j['submitted_name'] ?? '', $j['submitted_at'] ?? null, url('hr_jd', ['id' => (int)$j['id']]), ['with' => $s['label']]);
        return $r;
    });
    // Newsletters (Sales and HR) — approval before sending
    if (function_exists('nl_appr_has') && nl_appr_has() && nl_approver()) apr_try($o, fn() => array_map(fn($x) => apr_row('newsletter', '', (string)($x['title'] ?: $x['subject']), $x['appr_req_name'], $x['appr_req_at'], url('newsletter', ['id' => (int)$x['id']]) . '#approval', ['with' => t('apr.w_mgmt')]),
        all("SELECT id, title, subject, appr_req_name, appr_req_at FROM nl_issues WHERE appr_state = 'pending' ORDER BY appr_req_at")));
    // Equipment: hand-over lists and disputed issue lists wait for HR; an issue list waits for the employee
    if (function_exists('hre_has') && hre_has()) apr_try($o, function () use ($me) {
        $r = [];
        if (hre_can()) foreach (all("SELECT d.id, d.person_id, d.kind, d.doc_no, d.status, d.created_at, d.created_name, p.name_en FROM hr_eq_docs d JOIN hr_people p ON p.id = d.person_id WHERE (d.kind = 'return' AND d.status = 'open') OR (d.kind = 'issue' AND d.status = 'disputed') ORDER BY d.created_at") as $d)
            $r[] = apr_row('equipment', (string)$d['doc_no'], t($d['kind'] === 'return' ? 'apr.eq_return' : 'apr.eq_disputed'), $d['name_en'], $d['created_at'], url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']), ['with' => t('apr.w_hr')]);
        $p = function_exists('hr_person_of_user') ? hr_person_of_user($me) : null;
        if ($p) foreach (all("SELECT id, doc_no, created_at, created_name FROM hr_eq_docs WHERE person_id = ? AND kind = 'issue' AND status = 'sent' ORDER BY created_at", [(int)$p['id']]) as $d)
            $r[] = apr_row('equipment', (string)$d['doc_no'], t('apr.eq_issue'), $d['created_name'], $d['created_at'], url('my_equipment'));
        return $r;
    });
    // HR assistant flows (card changes, hiring, leaving) — whoever has to act now
    if (function_exists('hra_has') && hra_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('hra', (string)$x['code'], t('hra.k_' . $x['kind']), '', $x['submitted_at'], url('hra_flow', ['id' => (int)$x['id']]), ['with' => t('apr.w_' . ($x['status'] === 'ceo' ? 'ceo' : ($x['status'] === 'manager' ? 'manager' : 'hr')))]),
        all("SELECT id, code, kind, status, submitted_at FROM hr_flows WHERE status IN ('submitted','manager','hr','ceo') AND (assignee_id = ?" . (hra_can_manage() ? " OR status IN ('submitted','hr')" : '') . ') ORDER BY submitted_at', [$me])));
    // Portal change requests — the Developer
    if (function_exists('cr_has') && cr_has() && cr_is_dev()) apr_try($o, fn() => array_map(fn($x) => apr_row('change', '#' . (int)$x['id'], (string)$x['title'], $x['full_name'], $x['created_at'], url('request', ['id' => (int)$x['id']])),
        all("SELECT r.id, r.title, r.created_at, u.full_name FROM cp_requests r LEFT JOIN customer_users u ON u.id = r.user_id WHERE r.status = 'submitted' ORDER BY r.created_at")));
    usort($o, fn($a, $b) => [!empty($b['late']), $b['age']] <=> [!empty($a['late']), $a['age']]);
    return $v = $o;
}
function apr_waiting_count(): int { return count(apr_waiting()); }

/** What the signed-in user asked for: still waiting (with whom), or decided in the last APR_DAYS days. */
function apr_mine(): array {
    if (!is_staff() || !apr_me()) return [];
    $me = apr_me(); $o = []; $d = (int) APR_DAYS; $since = "DATE_SUB(NOW(), INTERVAL {$d} DAY)";
    $st = fn(string $s, array $ok, array $bad) => in_array($s, $ok, true) ? 'approved' : (in_array($s, $bad, true) ? 'rejected' : 'pending');
    if (function_exists('hr2_has') && hr2_has()) apr_try($o, function () use ($since, $me, $st) {
        return array_map(fn($x) => apr_row('leave', (string)$x['code'], $x['type_name'] . ' · ' . fmt_date($x['date_from']) . ' – ' . fmt_date($x['date_to']), '', $x['created_at'], url('my_leave'),
            ['state' => $st($x['status'], ['approved'], ['rejected']), 'with' => $x['status'] === 'pending_mgr' ? ($x['manager_name'] ?: t('apr.w_manager')) : ($x['status'] === 'pending_hr' ? t('apr.w_hr') : ''), 'decided_at' => $x['hr_at'] ?: $x['manager_at']]),
            all("SELECT r.id, r.status, r.date_from, r.date_to, r.created_at, r.manager_at, r.hr_at, t.code, t.name AS type_name, m.full_name AS manager_name FROM hr_requests r JOIN hr_leave_types t ON t.id = r.type_id LEFT JOIN customer_users m ON m.id = r.manager_user_id
                 WHERE r.user_id = ? AND (r.status IN ('pending_mgr','pending_hr') OR (r.status IN ('approved','rejected') AND COALESCE(r.hr_at, r.manager_at, r.created_at) >= {$since})) ORDER BY r.created_at DESC", [$me]));
    });
    if (function_exists('ts_has') && ts_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('timesheet', (string)$x['ts_no'], fmt_date($x['period_start']) . ' – ' . fmt_date($x['period_end']) . ($x['project_code'] ? ' · ' . $x['project_code'] : ''), '', $x['submitted_at'], url('timesheet', ['id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['approved'], ['rejected']), 'with' => $x['status'] === 'submitted' ? (string)$x['approver'] : '', 'decided_at' => $x['approved_at']]),
        all("SELECT t.id, t.ts_no, t.status, t.period_start, t.period_end, t.submitted_at, t.approved_at, p.project_code, a.full_name AS approver FROM timesheets t LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN customer_users a ON a.id = t.approver_id
             WHERE t.user_id = ? AND (t.status = 'submitted' OR (t.status IN ('approved','rejected') AND COALESCE(t.approved_at, t.submitted_at) >= {$since})) ORDER BY t.submitted_at DESC", [$me])));
    if (function_exists('hr_tr_has') && hr_tr_has() && function_exists('hr_person_of_user') && ($p = hr_person_of_user($me))) apr_try($o, fn() => array_map(fn($x) => apr_row('training', '', (string)$x['title'], '', $x['applied_at'], url('hr_training', ['id' => (int)$x['training_id']]),
            ['state' => $st($x['status'], ['approved', 'attended', 'absent'], ['rejected']), 'with' => $x['status'] === 'applied' ? t('apr.w_hr') : '', 'decided_at' => $x['decided_at']]),
        all("SELECT m.training_id, m.status, m.applied_at, m.decided_at, tr.title FROM hr_training_members m JOIN hr_trainings tr ON tr.id = m.training_id WHERE m.person_id = ? AND m.applied_at IS NOT NULL AND (m.status = 'applied' OR m.decided_at >= {$since}) ORDER BY m.applied_at DESC", [(int)$p['id']])));
    if (function_exists('stk_has') && stk_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('requisition', (string)$x['pr_no'], (string)($x['title'] ?: $x['project_code']), '', $x['submitted_at'], url('requisition', ['id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['approved', 'closed'], ['rejected']), 'with' => $x['status'] === 'submitted' ? t('apr.w_proc') : ($x['status'] === 'mgmt' ? t('apr.w_mgmt') : ''), 'decided_at' => $x['mgmt_at'] ?: $x['approved_at']]),
        all("SELECT r.id, r.pr_no, r.title, r.status, r.submitted_at, r.approved_at, r.mgmt_at, p.project_code FROM pr_requests r LEFT JOIN projects p ON p.id = r.project_id
             WHERE r.requester_id = ? AND (r.status IN ('submitted','mgmt') OR (r.status IN ('approved','rejected') AND COALESCE(r.mgmt_at, r.approved_at, r.submitted_at) >= {$since})) ORDER BY r.submitted_at DESC", [$me])));
    if (function_exists('si_has') && si_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('payment', (string)$x['req_no'], $x['supplier'] . ' · ' . (function_exists('si_money') ? si_money((float)$x['amount'], $x['currency']) : number_format((float)$x['amount'], 2) . ' ' . $x['currency']), '', $x['created_at'], url('payables', ['tab' => 'requests', 'id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['approved', 'paid'], ['rejected']), 'with' => $x['status'] === 'submitted' ? t('apr.w_acc') : ($x['status'] === 'mgmt' ? t('apr.w_mgmt') : ''), 'decided_at' => $x['decided_at']]),
        all(SI_R_SELECT . " WHERE r.requested_by = ? AND (r.status IN ('submitted','mgmt') OR (r.status IN ('approved','rejected','paid') AND COALESCE(r.decided_at, r.created_at) >= {$since})) ORDER BY r.created_at DESC", [$me])));
    if (function_exists('eng_has') && eng_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('eng', trim(($x['project_code'] ?: '') . ' ' . ($x['sub_code'] ?: '')), $x['section'] . ' · +' . rtrim(rtrim(number_format((float)$x['hours'], 2, '.', ''), '0'), '.') . ' h', '', $x['requested_at'], url('subproject', ['id' => (int)$x['subproject_id'], 'tab' => 'engineering']),
            ['state' => $st($x['status'], ['approved'], ['rejected']), 'with' => $x['status'] === 'pending' ? t('apr.w_mgmt') : '', 'decided_at' => $x['decided_at']]),
        all("SELECT r.subproject_id, r.hours, r.status, r.requested_at, r.decided_at, d.name AS section, s.code AS sub_code, p.project_code FROM eng_requests r JOIN eng_sections d ON d.id = r.section_id JOIN subprojects s ON s.id = r.subproject_id JOIN projects p ON p.id = s.project_id
             WHERE r.requested_by = ? AND (r.status = 'pending' OR (r.status IN ('approved','rejected') AND COALESCE(r.decided_at, r.requested_at) >= {$since})) ORDER BY r.requested_at DESC", [$me])));
    if (function_exists('bom_pr_can') && function_exists('stk_has') && stk_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('bom', trim(($x['project_code'] ?: '') . ' ' . ($x['sub_code'] ?: '')) . ' R' . (int)$x['revision'], (string)($x['title'] ?: $x['sub_name']), '', $x['issued_at'], url('bom', ['id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['approved', 'superseded'], ['rejected']), 'with' => $x['status'] === 'issued' ? t('apr.w_proc') : '', 'decided_at' => $x['approved_at']]),
        all("SELECT b.id, b.revision, b.title, b.status, b.issued_at, b.approved_at, s.code AS sub_code, s.name AS sub_name, p.project_code FROM bom_revisions b JOIN subprojects s ON s.id = b.subproject_id JOIN projects p ON p.id = s.project_id
             WHERE b.issued_by = ? AND (b.status = 'issued' OR (b.status IN ('approved','rejected') AND b.approved_at >= {$since})) ORDER BY b.issued_at DESC", [$me])));
    if (function_exists('jd_has') && jd_has()) apr_try($o, function () use ($me) {
        $r = []; foreach (all("SELECT j.*, d.manager_user_id AS dm_user_id FROM hr_jds j LEFT JOIN disciplines d ON d.id = j.discipline_id WHERE j.created_by = ? AND j.status = 'review' ORDER BY j.submitted_at DESC", [$me]) as $j) { $w = ''; try { foreach (jd_steps($j) as $s) if ($s['next']) $w = $s['label']; } catch (Throwable $e) {} $r[] = apr_row('jd', (string)$j['code'], (string)$j['title'], '', $j['submitted_at'], url('hr_jd', ['id' => (int)$j['id']]), ['with' => $w]); }
        return $r;
    });
    if (function_exists('nl_appr_has') && nl_appr_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('newsletter', '', (string)($x['title'] ?: $x['subject']), '', $x['appr_req_at'], url('newsletter', ['id' => (int)$x['id']]) . '#approval',
            ['state' => $st((string)$x['appr_state'], ['approved'], ['rejected', 'returned']), 'with' => $x['appr_state'] === 'pending' ? t('apr.w_mgmt') : '', 'decided_at' => $x['appr_at']]),
        all("SELECT id, title, subject, appr_state, appr_req_at, appr_at FROM nl_issues WHERE appr_req_by = ? AND (appr_state = 'pending' OR (appr_state IN ('approved','rejected','returned') AND appr_at >= {$since})) ORDER BY appr_req_at DESC", [$me])));
    if (function_exists('hre_has') && hre_has()) apr_try($o, function () use ($me, $since) {
        $r = [];
        if (hre_can()) foreach (all("SELECT d.id, d.person_id, d.doc_no, d.status, d.created_at, d.emp_at, p.name_en FROM hr_eq_docs d JOIN hr_people p ON p.id = d.person_id WHERE d.created_by = ? AND d.kind = 'issue' AND (d.status = 'sent' OR (d.status = 'accepted' AND d.emp_at >= {$since})) ORDER BY d.created_at DESC", [$me]) as $x)
            $r[] = apr_row('equipment', (string)$x['doc_no'], t('apr.eq_issue'), '', $x['created_at'], url('hr_person', ['id' => (int)$x['person_id'], 'tab' => 'eq']), ['state' => $x['status'] === 'accepted' ? 'approved' : 'pending', 'with' => $x['status'] === 'sent' ? (string)$x['name_en'] : '', 'decided_at' => $x['emp_at']]);
        $p = function_exists('hr_person_of_user') ? hr_person_of_user($me) : null;
        if ($p) foreach (all("SELECT id, doc_no, created_at FROM hr_eq_docs WHERE person_id = ? AND kind = 'return' AND status = 'open' ORDER BY created_at DESC", [(int)$p['id']]) as $x)
            $r[] = apr_row('equipment', (string)$x['doc_no'], t('apr.eq_return'), '', $x['created_at'], url('my_equipment'), ['with' => t('apr.w_hr')]);
        return $r;
    });
    if (function_exists('hra_has') && hra_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('hra', (string)$x['code'], t('hra.k_' . $x['kind']), '', $x['submitted_at'], url('hra_flow', ['id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['applied'], ['rejected']), 'with' => in_array($x['status'], ['applied', 'rejected'], true) ? '' : t('apr.w_' . ($x['status'] === 'ceo' ? 'ceo' : ($x['status'] === 'manager' ? 'manager' : 'hr'))), 'decided_at' => $x['decided_at']]),
        all("SELECT id, code, kind, status, submitted_at, decided_at FROM hr_flows WHERE user_id = ? AND confidential = 0 AND (status IN ('submitted','manager','hr','ceo') OR (status IN ('applied','rejected') AND decided_at >= {$since})) ORDER BY submitted_at DESC", [$me])));
    if (function_exists('cr_has') && cr_has()) apr_try($o, fn() => array_map(fn($x) => apr_row('change', '#' . (int)$x['id'], (string)$x['title'], '', $x['created_at'], url('request', ['id' => (int)$x['id']]),
            ['state' => $st($x['status'], ['accepted', 'in_progress', 'released'], ['rejected']), 'with' => $x['status'] === 'submitted' ? t('apr.w_dev') : '', 'decided_at' => $x['decided_at']]),
        all("SELECT id, title, status, created_at, decided_at FROM cp_requests WHERE user_id = ? AND (status = 'submitted' OR (status IN ('accepted','rejected','in_progress','released') AND decided_at >= {$since})) ORDER BY created_at DESC", [$me])));
    usort($o, fn($a, $b) => [$b['state'] === 'pending', (string)($b['decided_at'] ?: $b['at'])] <=> [$a['state'] === 'pending', (string)($a['decided_at'] ?: $a['at'])]);
    return $o;
}

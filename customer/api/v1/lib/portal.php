<?php
/**
 * Portal adapter for the Service Engineer app. Reads use the portal's scoped query functions; writes call the
 * portal's save functions by composing the same $_POST they expect from the browser form, so numbering, archive
 * filing (STO-001), calendar reclassification, engineering budget checks, equipment / issue sync, events and
 * notifications all happen exactly as in the portal. Monetary data never leaves (Response::noMoney).
 */
declare(strict_types=1);

final class Portal {
    /* ------------------------------------------------------------------ account */
    public static function me(array $u): array {
        $d = one('SELECT d.id, d.name, d.eng_section_id FROM disciplines d WHERE d.id = ?', [(int)($u['discipline_id'] ?? 0)]);
        $br = function_exists('br_user') ? br_user($u) : (function_exists('br_default') ? br_default() : ['code' => 'GAS']);
        $scope = array_values(array_unique(array_filter(array_map(fn($b) => $b['code'], function_exists('br_all') ? br_all(true) : []))));
        return ['id' => (int)$u['id'], 'name' => $u['full_name'], 'email' => $u['email'], 'role' => $u['role'], 'discipline' => $d['name'] ?? '', 'discipline_id' => (int)($d['id'] ?? 0),
            'section_id' => (int)(function_exists('eng_section_of') ? (eng_section_of((int)($d['id'] ?? 0)) ?? 0) : 0), 'home_branch' => $br['code'] ?? 'GAS', 'branch_scope' => $scope ?: [$br['code'] ?? 'GAS'],
            'languages' => array_keys(LOCALES), 'min_app_version' => api_setting('api_min_version_engineer', API_VERSION), 'ai' => function_exists('ai_can') && ai_can(), 'team_restricted' => function_exists('tm_restricted') && tm_restricted($u),
            'calendar' => function_exists('wc_user_calendar') ? wc_user_calendar((int)$u['id']) : null,
            'hr' => function_exists('hr2_has') && hr2_has() && hr_person_of_user((int)$u['id']) !== null, 'hr_unread' => function_exists('hr_messages_unread') ? hr_messages_unread() : 0];   // R65-001: My leave in the app
    }
    public static function login(string $email, string $pass, ?string $totp): array {
        $u = one('SELECT cu.* FROM customer_users cu WHERE cu.email = ? AND cu.is_active = 1', [$email]);
        if (!$u || !password_verify($pass, (string)$u['password_hash'])) { usleep(300000); throw new ApiException(401, 'Invalid credentials'); }
        if (api_app() === 'customer') { if (($u['role'] ?? 'customer') !== 'customer') throw new ApiException(403, 'Customer account required'); }   // R68-001
        elseif (!in_array($u['role'], ['staff', 'admin'], true)) throw new ApiException(403, 'Staff account required');
        if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) throw new ApiException(423, 'Account locked');
        if (!empty($u['totp_enabled']) && !empty($u['totp_secret'])) { if (!$totp || !totp_verify((string)$u['totp_secret'], $totp)) throw new ApiException(401, 'Authenticator code required', ['totp' => true]); }
        if (function_exists('login_log')) login_log((int)$u['id'], $email, 'success');
        return $u;
    }

    /* ------------------------------------------------------------------ read model */
    /** Sub-projects the engineer works on (team scope, branch scope) grouped by project; stage = first stage below 100 %. */
    public static function projects(): array {
        $out = []; $defs = stage_defs();
        foreach (tm_my_subs() as $s) {
            $pid = (int)$s['project_id'];
            if (!isset($out[$pid])) { $p = one('SELECT p.*, v.name AS vessel, v.id AS vid, c.name AS customer FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
                $out[$pid] = ['id' => $pid, 'code' => (string)($p['project_code'] ?: $p['erp_code'] ?: 'P' . $pid), 'name' => (string)$p['name'], 'vessel_id' => (int)($p['vessel_id'] ?? 0) ?: null, 'vessel' => $p['vessel'] ?? '', 'customer' => $p['customer'] ?? '', 'branch' => function_exists('br_of') ? (br_of($pid)['code'] ?? 'GAS') : 'GAS', 'pm' => $p['project_manager'] ?? '', 'subprojects' => []]; }
            $row = one('SELECT * FROM subprojects WHERE id = ?', [(int)$s['id']]); $stage = 'commissioning';
            foreach ($defs as $k => $d) { $v = (int)($row[$d['col']] ?? 0); if ($v >= 0 && $v < 100) { $stage = $k; break; } }
            $out[$pid]['subprojects'][] = ['id' => (int)$s['id'], 'code' => (string)$s['code'], 'name' => (string)$s['name'], 'stage' => $stage];
        }
        return array_values($out);
    }
    public static function vessels(): array {
        [$bs, $ba] = function_exists('br_scope_sql') ? br_scope_sql('p') : ['1 = 1', []];
        $rows = all("SELECT DISTINCT v.id, v.name, v.yard, c.name AS customer, c.id AS customer_id FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN projects p ON p.vessel_id = v.id WHERE v.is_active = 1 AND v.merged_into IS NULL AND ({$bs} OR p.id IS NULL) ORDER BY v.name", $ba);
        return array_map(fn($v) => ['id' => (int)$v['id'], 'name' => $v['name'], 'yard' => (string)$v['yard'], 'customer' => (string)$v['customer'], 'customer_id' => (int)$v['customer_id']], $rows);
    }
    public static function tickets(array $u): array {
        $mine = tk_list(['assigned' => (int)$u['id']]); $all = [];
        foreach ($mine as $t) $all[(int)$t['id']] = $t;
        foreach (array_unique(array_map(fn($t) => (int)$t['vessel_id'], $mine)) as $vid) if ($vid) foreach (tk_list(['vessel' => $vid, 'status' => 'open_all']) as $t) $all[(int)$t['id']] = $t;
        $out = [];
        foreach ($all as $t) {
            $full = tk_load((int)$t['id']); if (!$full) continue;
            $hist = [];
            foreach ($full['events'] as $e) $hist[] = ['at' => substr((string)$e['created_at'], 0, 10), 'text' => trim(($e['user_name'] ?: '') . ' — ' . str_replace('_', ' ', (string)$e['event']) . ($e['note'] ? ': ' . $e['note'] : ''), ' —')];
            foreach ($full['comments'] as $c) if (!$c['deleted_at']) $hist[] = ['at' => substr((string)$c['created_at'], 0, 10), 'text' => ($c['author'] ?: '') . ' (' . $c['visibility'] . '): ' . mb_substr((string)$c['body'], 0, 300)];
            usort($hist, fn($a, $b) => strcmp($a['at'], $b['at']));
            $issues = all("SELECT title FROM sp_issues WHERE status <> 'closed' AND (fsr_id IN (SELECT id FROM fsr_reports WHERE ticket_id = ?)) ORDER BY id", [(int)$t['id']]);
            $out[] = ['id' => (int)$t['id'], 'ticket_no' => $t['ticket_no'], 'vessel_id' => (int)$t['vessel_id'], 'vessel' => (string)$t['vessel'], 'customer' => (string)$t['customer_name'], 'system' => (string)($full['system_name'] ?? ''), 'system_id' => (int)($t['system_id'] ?? 0) ?: null,
                'title' => $t['title'], 'description' => (string)$t['description'], 'category' => $t['category'], 'priority' => $t['priority'], 'status' => $t['status'], 'due' => null, 'reported_by' => (string)$t['created_name'], 'reported_at' => substr((string)$t['created_at'], 0, 10),
                'assigned_to' => (int)($t['assigned_to'] ?? 0) ?: null, 'project_id' => (int)($t['project_id'] ?? 0) ?: null, 'subproject_id' => (int)($t['subproject_id'] ?? 0) ?: null, 'legacy' => $t['legacy_no'] ? 'Freshdesk #' . $t['legacy_no'] : null,
                'history' => $hist, 'open_issues' => array_column($issues, 'title'), 'fsrs' => array_map(fn($f) => $f['report_no'] . ' (' . $f['status'] . ')', $full['fsrs'])];
        }
        return $out;
    }
    /** Work assigned to me that I have not accepted yet: tickets assigned without a sub-project chosen + work packages not acknowledged. */
    public static function assignments(array $u): array {
        $out = [];
        foreach (tk_list(['assigned' => (int)$u['id'], 'status' => 'open_all']) as $t) {
            $acc = (bool) scalar("SELECT COUNT(*) FROM ticket_events WHERE ticket_id = ? AND user_id = ? AND event = 'accepted'", [(int)$t['id'], (int)$u['id']]);
            $out[] = ['id' => 'TK' . $t['id'], 'kind' => 'ticket', 'ref' => $t['ticket_no'], 'ref_id' => (int)$t['id'], 'title' => $t['title'], 'project_id' => (int)($t['project_id'] ?? 0) ?: null, 'subproject_id' => (int)($t['subproject_id'] ?? 0) ?: null, 'vessel_id' => (int)$t['vessel_id'], 'vessel' => (string)$t['vessel'],
                'date' => substr((string)$t['created_at'], 0, 10), 'status' => $acc ? 'accepted' : 'new', 'by' => (string)($t['assigned_name'] ? 'PM' : '')];
        }
        if (function_exists('wp_for_user')) foreach (wp_for_user((int)$u['id']) as $w) {
            $me = one('SELECT acknowledged_at FROM sp_work_people WHERE work_id = ? AND user_id = ?', [(int)$w['id'], (int)$u['id']]);
            $sp = load_subproject((int)$w['subproject_id']);
            $out[] = ['id' => 'WP' . $w['id'], 'kind' => 'work', 'ref' => trim(($sp['project_code'] ?? '') . ' ' . ($sp['code'] ?? '') . ' ' . $w['wp_no']), 'ref_id' => (int)$w['id'], 'title' => $w['title'], 'project_id' => (int)$w['project_id'], 'subproject_id' => (int)$w['subproject_id'], 'vessel_id' => (int)($sp['vessel_id'] ?? 0) ?: null, 'vessel' => (string)($sp['vessel'] ?? ''),
                'date' => (string)($w['start_date'] ?: substr((string)$w['created_at'], 0, 10)), 'status' => ($me && $me['acknowledged_at']) ? 'accepted' : 'new', 'by' => (string)(scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)($w['lead_user_id'] ?? 0)]) ?: 'PM'), 'planned_hours' => (float)($w['planned_hours'] ?? 0)];
        }
        return $out;
    }
    public static function equipment(): array {
        $vids = array_unique(array_filter(array_map(fn($p) => (int)$p['vessel_id'], self::projects())));
        foreach (tk_list(['assigned' => (int)current_user()['id']]) as $t) $vids[] = (int)$t['vessel_id'];
        $out = [];
        foreach (array_unique($vids) as $vid) foreach (eq_for_vessel($vid) as $e) {
            $hist = array_map(fn($h) => substr((string)$h['hist_date'], 0, 10) . ' — ' . $h['kind'] . ': ' . mb_substr((string)$h['body'], 0, 120), eq_history((int)$e['id']));
            $open = (int) scalar("SELECT COUNT(*) FROM tickets t JOIN task_equipment te ON 1=0 WHERE 1=0") ;   // tickets are not linked to units in this schema; status from open FSR issues below
            $out[] = ['id' => (int)$e['id'], 'vessel_id' => (int)$e['vessel_id'], 'tag' => (string)($e['set_code'] ?: $e['serial_no']), 'serial' => (string)$e['serial_no'], 'system' => (string)($e['family'] ?: $e['kind']), 'kind' => (string)$e['kind'], 'maker' => (string)$e['maker'], 'model' => (string)$e['model'], 'location' => (string)$e['site_name'],
                'installed' => (string)($e['date_sold'] ?: $e['first_attendance'] ?: ''), 'warranty' => $e['warranty_until'] ? ($e['warranty_until'] >= date('Y-m-d') ? $e['warranty_until'] : 'expired') : '—', 'status' => $e['retired'] ? 'retired' : 'ok', 'last_service' => $e['last_service'], 'history' => $hist, 'docs' => []];
        }
        return $out;
    }
    /** Engineering section budgets of my sub-projects: budget hours, actual (approved timesheets), approved extra hours. */
    public static function budgets(): array {
        if (!function_exists('eng_has2') || !eng_has2()) return [];
        $secs = eng_sections(); $out = [];
        foreach (tm_my_subs() as $s) {
            $sid = (int)$s['id']; $B = eng_budget_hours($sid); if ($B === null) continue; $A = eng_actual($sid); $rows = [];
            foreach ($B as $secId => $h) { if ((float)$h <= 0) continue; $extra = (float) scalar("SELECT COALESCE(SUM(hours),0) FROM eng_requests WHERE subproject_id = ? AND section_id = ? AND status = 'approved'", [$sid, (int)$secId]);
                $rows[] = ['id' => (int)$secId, 'code' => $secs[$secId]['code'] ?? '', 'name' => $secs[$secId]['name'] ?? '', 'budget' => (float)$h, 'booked' => (float)($A[$secId] ?? 0), 'extra' => $extra]; }
            if ($rows) $out[] = ['subproject_id' => $sid, 'sections' => $rows];
        }
        return $out;
    }
    public static function master(array $u): array {
        $engineers = all("SELECT cu.id, cu.full_name AS name, COALESCE(d.name, '') AS discipline FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 ORDER BY cu.full_name");
        $cal = []; if (function_exists('wc_has') && wc_has()) foreach (wc_calendars(true) as $code => $c) { $h = wc_hours($code); $cal[$code] = ['name' => $c['name'], 'start' => substr((string)($h[1]['start_time'] ?? '08:00'), 0, 5), 'end' => substr((string)($h[1]['end_time'] ?? '17:00'), 0, 5), 'holidays' => array_column(all('SELECT hdate FROM work_holidays WHERE calendar_code = ? AND hdate >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)', [$code]), 'hdate')]; }
        $secs = function_exists('eng_sections') ? array_values(array_map(fn($s) => ['id' => (int)$s['id'], 'code' => $s['code'], 'name' => $s['name']], eng_sections())) : [];
        $systems = function_exists('isr_system_options') ? isr_system_options() : [];
        $bank = function_exists('isr_bank_lc') ? isr_bank_lc() : [];
        return ['specialties' => fsr_specialties(), 'time_types' => array_keys(fsr_time_types()), 'part_types' => array_keys(fsr_part_types()), 'max_lines' => FSR_MAX_LINES, 'max_parts' => FSR_MAX_PARTS, 'max_photos' => FSR_MAX_PHOTOS,
            'sections' => $secs, 'engineers' => $engineers, 'stages' => array_keys(stage_defs()), 'calendars' => $cal, 'activities' => function_exists('eng_activity_choices') ? eng_activity_choices((int)($u['discipline_id'] ?? 0) ?: null) : ['site'],
            'reasons' => defined('ENG_REASONS') ? ENG_REASONS : ['misjudgement', 'miscalculation'], 'isr_systems' => $systems, 'isr_bank' => $bank, 'isr_risk_levels' => array_keys(isr_risk_levels()), 'ticket_statuses' => tk_statuses()];
    }
    public static function documents(): array {
        $out = []; $pids = array_map(fn($p) => (int)$p['id'], self::projects());
        foreach ($pids as $pid) foreach (all("SELECT f.id, f.project_id, f.subproject_id, f.kind, f.title, f.doc_no, f.revision, f.original_name, f.mime_type, f.size_bytes FROM sp_files f WHERE f.project_id = ? AND f.kind NOT IN ('offer','offer_doc','proforma','acc_doc','bg_doc') AND f.kind NOT LIKE 'offer%' ORDER BY f.kind, f.doc_no, f.id DESC", [$pid]) as $f)
            $out[] = ['id' => (int)$f['id'], 'project_id' => (int)$f['project_id'], 'subproject_id' => (int)($f['subproject_id'] ?? 0) ?: null, 'name' => trim(($f['doc_no'] ? $f['doc_no'] . ($f['revision'] ? '_' . $f['revision'] : '') . ' — ' : '') . ($f['title'] ?: $f['original_name'])), 'category' => (string)$f['kind'], 'size' => round((int)$f['size_bytes'] / 1048576, 1) . ' MB', 'mime' => $f['mime_type']];
        return $out;
    }
    /** Invoicing status of my own hours (accounting register states, never amounts). */
    public static function accountingStatus(array $u): array {
        if (!function_exists('acc_has') || !acc_has()) return [];
        return array_map(fn($r) => ['date' => $r['work_date'], 'subproject_id' => (int)($r['subproject_id'] ?? 0) ?: null, 'source' => $r['source'], 'doc_no' => $r['doc_no'], 'status' => $r['state']],
            all("SELECT work_date, subproject_id, source, doc_no, state FROM acc_lines WHERE user_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY) ORDER BY work_date DESC", [(int)$u['id']]));
    }
    public static function reports(array $u): array {
        $out = [];
        foreach (fsr_list() as $r) {
            $full = fsr_load((int)$r['id']); if (!$full) continue;
            $mine = (int)$full['created_by'] === (int)$u['id'] || (int)$full['submitted_by'] === (int)$u['id'] || array_filter($full['lines'], fn($l) => mb_strtolower(trim((string)$l['engineer'])) === mb_strtolower($u['full_name']));
            if (!$mine) continue;
            $out[] = self::shapeFsr($full);
        }
        foreach (isr_list() as $r) { $full = isr_load((int)$r['id']); if ($full && (int)$full['created_by'] === (int)$u['id']) $out[] = self::shapeIsr($full); }
        usort($out, fn($a, $b) => strcmp($b['date'], $a['date']));
        return $out;
    }
    public static function shapeFsr(array $r): array {
        return ['id' => 'F' . $r['id'], 'portal_id' => (int)$r['id'], 'kind' => 'service', 'number' => $r['report_no'], 'rev' => 'R' . (int)$r['version'], 'form' => $r['form_code'], 'type' => $r['kind'], 'status' => $r['status'], 'date' => $r['report_date'], 'project_id' => (int)$r['project_id'], 'subproject_id' => (int)($r['subproject_id'] ?? 0) ?: null, 'ticket_id' => (int)($r['ticket_id'] ?? 0) ?: null,
            'vessel' => (string)$r['vessel_name'], 'customer' => (string)$r['customer_name'], 'location' => (string)$r['location'], 'pic' => (string)$r['pic_name'], 'pic_position' => (string)$r['pic_position'], 'pic_mobile' => (string)$r['pic_mobile'], 'pic_email' => (string)$r['pic_email'],
            'tasks' => array_map(fn($w) => ['seq' => (int)$w['seq'], 'description' => (string)$w['description'], 'parts' => array_map(fn($p) => ['name' => $p['d'], 'type' => $p['t']], $w['parts']), 'issues' => array_values(array_filter(array_map('trim', explode("\n", (string)$w['open_issues'])))),
                'times' => array_map(fn($l) => ['engineer' => (string)$l['engineer'], 'specialty' => (string)$l['specialty'], 'start' => substr((string)$l['start_time'], 0, 5), 'end' => substr((string)$l['end_time'], 0, 5), 'type' => $l['time_type'], 'hours' => (float)$l['hours']], $w['lines'])], $r['works']),
            'summary' => $r['summary'], 'submitted_by' => (string)$r['submitted_name'], 'submitted_at' => (string)$r['submitted_at'], 'accepted_by' => (string)$r['accepted_name'], 'accepted_at' => (string)$r['accepted_at'], 'accepted_ip' => (string)$r['accepted_ip'], 'acceptance' => $r['customer_sign_id'] ? 'onsite' : 'portal',
            'invoicing' => $r['invoicing'], 'pdf_file_id' => (int)($r['pdf_file_id'] ?? 0) ?: null, 'photos' => count($r['photos'])];
    }
    public static function shapeIsr(array $r): array {
        return ['id' => 'I' . $r['id'], 'portal_id' => (int)$r['id'], 'kind' => 'inspection', 'number' => $r['report_no'], 'rev' => 'R' . (int)$r['version'], 'form' => $r['form_code'], 'type' => 'internal', 'status' => $r['status'], 'date' => $r['report_date'], 'project_id' => (int)$r['project_id'], 'subproject_id' => (int)($r['subproject_id'] ?? 0) ?: null,
            'vessel' => (string)$r['vessel_name'], 'customer' => (string)$r['customer_name'], 'location' => (string)$r['location'], 'pic' => (string)$r['pic_name'], 'subject' => (string)$r['subject'], 'class_flag' => (string)$r['class_flag'], 'builder_year' => (string)$r['builder_year'], 'hull_no' => (string)$r['hull_no'],
            'objective' => (string)$r['objective'], 'findings' => (string)$r['findings'], 'risks' => (string)$r['risks'], 'performed_name' => (string)$r['performed_name'],
            'items' => array_map(fn($i) => ['system_id' => (int)($i['system_id'] ?? 0) ?: null, 'system_name' => $i['system_name'], 'condition_found' => (string)$i['condition_found'], 'checklist' => $i['checklist'] ? (json_decode((string)$i['checklist'], true) ?: []) : [], 'class_regs' => (string)$i['class_regs'], 'risk_level' => $i['risk_level'], 'risk_analysis' => (string)$i['risk_analysis'], 'actions' => (string)$i['actions']], $r['items'] ?? []),
            'submitted_at' => (string)$r['submitted_at'], 'checked_by' => (string)$r['checked_name'], 'issued_at' => (string)$r['issued_at'], 'pdf_file_id' => (int)($r['pdf_file_id'] ?? 0) ?: null];
    }
    public static function timesheets(array $u): array {
        $out = [];
        foreach (ts_list(['user' => (int)$u['id']]) as $t) { $full = ts_load((int)$t['id']); if (!$full) continue;
            foreach ($full['lines'] as $l) $out[] = ['id' => 'TS' . $t['id'] . '-' . $l['id'], 'ts_id' => (int)$t['id'], 'ts_no' => $t['ts_no'], 'date' => $l['work_date'], 'project_id' => (int)$t['project_id'], 'subproject_id' => (int)($l['subproject_id'] ?? 0) ?: null, 'hour_type' => $l['hour_type'], 'hours' => (float)$l['hours'], 'activity' => $l['activity'] ?? null, 'description' => (string)$l['description'], 'auto_type' => (int)($l['auto_type'] ?? 0), 'approval' => $t['status'], 'invoicing' => $t['invoicing'] ?? 'none', 'period' => $t['period_start'] . '/' . $t['period_end']]; }
        return $out;
    }
    public static function notes(array $u): array {
        return array_map(fn($n) => ['id' => 'N' . $n['id'], 'date' => $n['note_date'], 'subproject_id' => (int)$n['subproject_id'], 'visibility' => $n['visibility'], 'text' => $n['body'], 'photos' => (int)$n['photos']],
            all('SELECT n.*, (SELECT COUNT(*) FROM sp_files f WHERE f.note_id = n.id) AS photos FROM sp_notes n WHERE n.author_id = ? AND n.note_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) ORDER BY n.note_date DESC, n.id DESC', [(int)$u['id']]));
    }

    /* ------------------------------------------------------------------ writes (through the portal's own save functions) */
    private static function post(array $p): void { $_POST = $p; $_FILES = []; }

    public static function acceptAssignment(array $u, array $p): string {
        $kind = $p['kind'] ?? (str_starts_with((string)$p['assignment_id'], 'WP') ? 'work' : 'ticket');
        if ($kind === 'work') { $wid = (int)($p['ref_id'] ?? substr((string)$p['assignment_id'], 2)); if (!scalar('SELECT COUNT(*) FROM sp_work_people WHERE work_id = ? AND user_id = ?', [$wid, (int)$u['id']])) throw new ApiException(422, 'Work package not assigned to you'); wp_ack($wid); return 'WP' . $wid; }
        $tid = (int)($p['ref_id'] ?? substr((string)$p['assignment_id'], 2)); return self::acceptTicket($u, ['ticket_id' => $tid, 'project_id' => $p['project_id'] ?? null, 'subproject_id' => $p['subproject_id'] ?? null]);
    }
    public static function acceptTicket(array $u, array $p): string {
        $t = tk_load((int)$p['ticket_id']); if (!$t) throw new ApiException(404, 'Ticket not found');
        $pid = (int)($p['project_id'] ?? $t['project_id'] ?? 0) ?: null; $sid = (int)($p['subproject_id'] ?? 0) ?: null;
        if ($sid && (!$pid || !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid]))) throw new ApiException(422, 'Sub-project does not belong to the project');
        if ($pid && ($tm = tm_allowed_ids($pid)) !== null && $sid && !in_array($sid, $tm, true)) throw new ApiException(403, 'Sub-project outside your team scope');
        try { if ($pid !== (int)($t['project_id'] ?? 0) || $sid !== (int)($t['subproject_id'] ?? 0)) tk_set_project((int)$t['id'], $pid, $sid); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        if ((int)($t['assigned_to'] ?? 0) !== (int)$u['id']) tk_assign((int)$t['id'], (int)$u['id']);
        if ($t['status'] === 'open') tk_set_status((int)$t['id'], 'in_progress', 'Accepted from the mobile app');
        tk_event((int)$t['id'], 'accepted', $sid ? 'Sub-project ' . (string)scalar('SELECT code FROM subprojects WHERE id = ?', [$sid]) : ($pid ? 'Project level' : 'Service call — no project'));
        return 'TK' . $t['id'];
    }
    public static function ticketNote(array $u, string $uuid, array $p): string {
        $t = tk_load((int)$p['ticket_id']); if (!$t) throw new ApiException(404, 'Ticket not found');
        try { $cid = tk_comment((int)$t['id'], (string)($p['text'] ?? ''), null, ($p['visibility'] ?? 'internal') === 'external' ? 'external' : 'internal'); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return 'TC' . $cid;
    }

    /** Field Service Report (SF-GLD-014): compose the portal form and call fsr_save_from_post, then the submit step of pm_actions 'fsr_save'. */
    public static function createFsr(array $u, string $uuid, array $p): array {
        $existing = one('SELECT id, report_no FROM fsr_reports WHERE created_by = ? AND report_no = ?', [(int)$u['id'], (string)($p['number'] ?? '')]);
        $pid = (int)($p['project_id'] ?? 0); $sid = (int)($p['subproject_id'] ?? 0) ?: null;
        if (!$pid) { $vid = (int)($p['vessel_id'] ?? 0); if (!$vid) throw new ApiException(422, 'Vessel required'); $pid = function_exists('fsr_project_for_vessel') ? fsr_project_for_vessel($vid) : 0; if (!$pid) throw new ApiException(422, 'No project for this vessel — the PM must create the service project first'); $sid = null; }
        $post = ['project_id' => $pid, 'subproject_id' => $sid, 'report_date' => (string)$p['date'], 'customer_name' => (string)($p['customer'] ?? ''), 'vessel_name' => (string)($p['vessel'] ?? ''), 'location' => (string)($p['location'] ?? ''),
            'pic_name' => (string)($p['pic'] ?? ''), 'pic_position' => (string)($p['pic_position'] ?? ''), 'pic_mobile' => (string)($p['pic_mobile'] ?? ''), 'pic_email' => (string)($p['pic_email'] ?? ''), 'wk_desc' => [], 'wk_issues' => [], 'wk_part' => [], 'wk_ptype' => [], 'wk_eng' => [], 'wk_spec' => [], 'wk_start' => [], 'wk_end' => [], 'wk_type' => [], 'eq_ids' => []];
        if (!empty($p['ticket_id'])) $post['ticket_id'] = (int)$p['ticket_id'];
        $tasks = array_slice((array)($p['tasks'] ?? []), 0, FSR_MAX_WORKS); if (!$tasks) throw new ApiException(422, 'At least one task');
        foreach ($tasks as $i => $tk) {
            $post['wk_desc'][$i] = (string)($tk['description'] ?? ''); $post['wk_issues'][$i] = implode("\n", array_filter(array_map('trim', (array)($tk['issues'] ?? []))));
            foreach (array_slice((array)($tk['parts'] ?? []), 0, FSR_MAX_PARTS) as $j => $part) { $post['wk_part'][$i][$j] = trim((string)($part['name'] ?? '')) . (!empty($part['qty']) && (float)$part['qty'] != 1 ? ' ×' . $part['qty'] : ''); $post['wk_ptype'][$i][$j] = in_array($part['type'] ?? 'I', ['W', 'G', 'I'], true) ? $part['type'] : 'I'; }
            $lines = array_slice((array)($tk['times'] ?? []), 0, FSR_MAX_LINES);
            foreach ($lines as $k => $l) { $name = (string)($l['engineer'] ?? ''); if (!empty($l['engineer_id'])) $name = (string)(scalar("SELECT full_name FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [(int)$l['engineer_id']]) ?: $name);
                if ($name === '') throw new ApiException(422, 'Time line without engineer'); $post['wk_eng'][$i][$k] = $name; $post['wk_spec'][$i][$k] = (string)($l['specialty'] ?: 'Service Engineer'); $post['wk_start'][$i][$k] = (string)$l['start']; $post['wk_end'][$i][$k] = (string)$l['end']; $post['wk_type'][$i][$k] = in_array($l['type'] ?? 'WT', ['WT', 'TT', 'NC'], true) ? $l['type'] : 'WT'; }
            if (!empty($tk['equipment_id'])) $post['eq_ids'][] = (int)$tk['equipment_id'];
        }
        self::post($post);
        try { $id = fsr_save_from_post($existing['id'] ?? null); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        $r = fsr_load($id); if (!$r['works'] || !$r['lines']) throw new ApiException(422, t('fsr.incomplete'));
        q('UPDATE fsr_reports SET status = "submitted", version = version + 1, submitted_by = ?, submitted_name = ?, submitted_at = NOW(), accepted_by = NULL, accepted_name = NULL, accepted_at = NULL, accepted_ip = NULL, customer_remark = NULL WHERE id = ?', [(int)$u['id'], $u['full_name'], $id]);
        fsr_event($id, 'submitted', 'Mobile app');
        // on-phone acceptance (decision 28/10/2026): the customer representative signed on the engineer's phone
        if (($p['acceptance'] ?? 'portal') === 'onsite' && !empty($p['signature_png']) && !empty($p['signer'])) {
            $sig = fsr_store_signature((string)$p['signature_png'], ['id' => $id, 'project_id' => $pid, 'subproject_id' => $sid], 'customer');
            if (!$sig) throw new ApiException(422, 'Signature image invalid');
            $name = mb_substr((string)$p['signer'] . (!empty($p['signer_role']) ? ', ' . $p['signer_role'] : '') . ' (signed on site)', 0, 120);
            q('UPDATE fsr_reports SET status = "accepted", accepted_by = NULL, accepted_name = ?, accepted_at = NOW(), accepted_ip = ?, customer_sign_id = ? WHERE id = ?', [$name, RateLimit::ip(), $sig, $id]);
            fsr_event($id, 'accepted', 'Signed on the engineer\'s phone — ' . $name);
        }
        fsr_generate_documents($id);
        if (function_exists('eq_fsr_sync')) eq_fsr_sync($id);
        if (function_exists('iss_fsr_sync')) iss_fsr_sync($id);
        if (function_exists('acc_sync')) { try { acc_sync(); } catch (Throwable $e) {} }
        $r = fsr_load($id);
        return ['id' => 'F' . $id, 'number' => $r['report_no'], 'status' => $r['status']];
    }
    /** Inspection report (SF-GLD-020): isr_save_from_post + isr_submit (→ checking; issued by a checker in the portal). */
    public static function createIsr(array $u, string $uuid, array $p): array {
        $pid = (int)($p['project_id'] ?? 0); $sid = (int)($p['subproject_id'] ?? 0) ?: null;
        if (!$pid) { $vid = (int)($p['vessel_id'] ?? 0); $pid = $vid && function_exists('fsr_project_for_vessel') ? fsr_project_for_vessel($vid) : 0; if (!$pid) throw new ApiException(422, 'Project required'); }
        $post = ['project_id' => $pid, 'subproject_id' => $sid, 'report_date' => (string)$p['date'], 'subject' => (string)($p['subject'] ?? ''), 'customer_name' => (string)($p['customer'] ?? ''), 'vessel_name' => (string)($p['vessel'] ?? ''), 'location' => (string)($p['location'] ?? ''), 'pic_name' => (string)($p['pic'] ?? ''),
            'class_flag' => (string)($p['class_flag'] ?? ''), 'builder_year' => (string)($p['builder_year'] ?? ''), 'hull_no' => (string)($p['hull_no'] ?? ''), 'objective' => (string)($p['objective'] ?? ''), 'findings' => (string)($p['findings'] ?? ''), 'risks' => (string)($p['risks'] ?? ''), 'performed_name' => $u['full_name'],
            'it_name' => [], 'it_sys' => [], 'it_cond' => [], 'it_regs' => [], 'it_risk' => [], 'it_act' => [], 'it_level' => [], 'it_q' => [], 'it_a' => [], 'eq_ids' => []];
        $items = array_slice((array)($p['items'] ?? []), 0, ISR_MAX_ITEMS); if (!$items) throw new ApiException(422, 'At least one inspected system');
        foreach ($items as $i => $it) { $post['it_name'][$i] = (string)($it['system_name'] ?? ''); $post['it_sys'][$i] = (int)($it['system_id'] ?? 0); $post['it_cond'][$i] = (string)($it['condition_found'] ?? ''); $post['it_regs'][$i] = (string)($it['class_regs'] ?? ''); $post['it_risk'][$i] = (string)($it['risk_analysis'] ?? ''); $post['it_act'][$i] = (string)($it['actions'] ?? ''); $post['it_level'][$i] = (string)($it['risk_level'] ?? 'none');
            foreach ((array)($it['checklist'] ?? []) as $j => $qa) { $post['it_q'][$i][$j] = (string)($qa['q'] ?? ''); $post['it_a'][$i][$j] = (string)($qa['a'] ?? ''); }
            if (!empty($it['equipment_id'])) $post['eq_ids'][] = (int)$it['equipment_id']; }
        self::post($post);
        try { $id = isr_save_from_post(null); isr_submit($id); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        $r = isr_load($id); return ['id' => 'I' . $id, 'number' => $r['report_no'], 'status' => $r['status']];
    }
    /** Day booking → the engineer's draft timesheet of that project / week (created if absent), re-saved with the new line through ts_save_from_post (calendar + engineering budget rules apply). */
    public static function bookHours(array $u, string $uuid, array $p): array {
        $pid = (int)($p['project_id'] ?? 0); if (!$pid) throw new ApiException(422, 'Project required (hours are booked per project; use a service project for service calls)');
        $date = (string)$p['date']; if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new ApiException(422, 'date');
        $monday = date('Y-m-d', strtotime($date) - ((int)date('N', strtotime($date)) - 1) * 86400); $sunday = date('Y-m-d', strtotime($monday . ' +6 days'));
        $t = one("SELECT * FROM timesheets WHERE user_id = ? AND project_id = ? AND period_start = ? AND status IN ('draft','rejected') ORDER BY id DESC LIMIT 1", [(int)$u['id'], $pid, $monday]);
        if (!$t && scalar("SELECT COUNT(*) FROM timesheets WHERE user_id = ? AND project_id = ? AND period_start = ? AND status IN ('submitted','approved','invoiced')", [(int)$u['id'], $pid, $monday])) throw new ApiException(423, 'Timesheet of this week is already submitted');
        $lines = $t ? all('SELECT * FROM timesheet_lines WHERE timesheet_id = ? ORDER BY id', [(int)$t['id']]) : [];
        $post = ['project_id' => $pid, 'period_start' => $monday, 'period_end' => $sunday, 'note' => (string)($t['note'] ?? ''), 'ln_date' => [], 'ln_hours' => [], 'ln_type' => [], 'ln_sub' => [], 'ln_work' => [], 'ln_desc' => [], 'ln_act' => []];
        $i = 0; foreach ($lines as $l) { $post['ln_date'][$i] = $l['work_date']; $post['ln_hours'][$i] = (string)$l['hours']; $post['ln_type'][$i] = $l['hour_type']; $post['ln_sub'][$i] = (int)($l['subproject_id'] ?? 0); $post['ln_work'][$i] = (int)($l['work_id'] ?? 0); $post['ln_desc'][$i] = (string)$l['description']; $post['ln_act'][$i] = (string)($l['activity'] ?? ''); $i++; }
        foreach ([['normal', (float)($p['normal'] ?? $p['hours'] ?? 0)], ['overtime', (float)($p['overtime'] ?? 0)], ['holiday', (float)($p['holiday'] ?? 0)]] as [$ty, $h]) { if ($h <= 0) continue;
            $post['ln_date'][$i] = $date; $post['ln_hours'][$i] = (string)$h; $post['ln_type'][$i] = $ty; $post['ln_sub'][$i] = (int)($p['subproject_id'] ?? 0); $post['ln_work'][$i] = (int)($p['work_id'] ?? 0); $post['ln_desc'][$i] = mb_substr((string)($p['note'] ?? ''), 0, 500); $post['ln_act'][$i] = (string)($p['activity'] ?? ''); $i++; }
        if ((float)($p['travel'] ?? 0) > 0) { $post['ln_date'][$i] = $date; $post['ln_hours'][$i] = (string)(float)$p['travel']; $post['ln_type'][$i] = 'normal'; $post['ln_sub'][$i] = (int)($p['subproject_id'] ?? 0); $post['ln_work'][$i] = 0; $post['ln_desc'][$i] = trim('Travel — ' . (string)($p['note'] ?? ''), ' —'); $post['ln_act'][$i] = 'site'; $i++; }
        if ($i === count($lines)) throw new ApiException(422, 'No hours');
        self::post($post);
        try { $id = ts_save_from_post((int)($t['id'] ?? 0), $u); } catch (RuntimeException $e) { $m = $e->getMessage(); throw new ApiException(str_contains($m, t('eng.blocked', ['s' => '', 'a' => '', 'b' => '', 'n' => ''])) || stripos($m, 'budget') !== false ? 409 : 422, $m); }
        $ts = ts_load($id); return ['id' => 'TS' . $id, 'ts_no' => $ts['ts_no'], 'reclassified' => (float)($GLOBALS['ts_reclass'] ?? 0), 'status' => $ts['status']];
    }
    public static function submitWeek(array $u, array $p): array { $id = (int)($p['ts_id'] ?? substr((string)($p['id'] ?? ''), 2)); try { ts_submit($id); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'TS' . $id, 'status' => 'submitted']; }
    public static function extraHours(array $u, string $uuid, array $p): array {
        $sid = (int)($p['subproject_id'] ?? 0); if (!$sid) throw new ApiException(422, 'Sub-project required');
        self::post(['section_id' => (int)($p['section_id'] ?? 0), 'hours' => (string)($p['hours'] ?? 0), 'reason_kind' => (string)($p['reason'] ?? 'misjudgement'), 'reason' => (string)($p['note'] ?? $p['reason'] ?? '')]);
        try { $id = eng_request_save_from_post($sid); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        return ['id' => 'ER' . $id, 'status' => 'pending'];
    }
    public static function note(array $u, string $uuid, array $p): array {
        $sid = (int)($p['subproject_id'] ?? 0); $sp = load_subproject($sid); if (!$sp) throw new ApiException(422, 'Sub-project required');
        if (($tm = tm_allowed_ids((int)$sp['project_id'])) !== null && !in_array($sid, $tm, true)) throw new ApiException(403, 'Sub-project outside your team scope');
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($p['date'] ?? '')) ? $p['date'] : date('Y-m-d'); $vis = ($p['visibility'] ?? 'external') === 'internal' ? 'internal' : 'external';
        q('INSERT INTO sp_notes (subproject_id, author_id, note_date, iso_week, visibility, body) VALUES (?,?,?,?,?,?)', [$sid, (int)$u['id'], $d, iso_week($d), $vis, mb_substr((string)($p['text'] ?? ''), 0, 8000)]);
        $id = (int) db()->lastInsertId();
        if (function_exists('mt_notify')) { try { mt_notify((string)$p['text'], trim(($sp['project_code'] ?: '') . ' › ' . ($sp['code'] ?: ''), ' ›'), url('subproject', ['id' => $sid, 'tab' => 'weekly']), 'spn:' . $id); } catch (Throwable $e) {} }
        return ['id' => 'N' . $id, 'project_id' => (int)$sp['project_id'], 'subproject_id' => $sid];
    }
    /** Nameplate / location correction → equipment history entry (manual) + notification to the PM; the register itself is edited in the portal. */
    public static function equipmentCorrection(array $u, string $uuid, array $p): array {
        $e = eq_get((int)($p['equipment_id'] ?? 0)); if (!$e) throw new ApiException(404, 'Equipment not found');
        $body = sprintf('Correction requested from the mobile app: %s "%s" → "%s"', (string)($p['field'] ?? ''), (string)($p['old_value'] ?? ''), (string)($p['new_value'] ?? ''));
        q("INSERT INTO equipment_history (equipment_id, hist_date, kind, source, engineer, body, created_by) VALUES (?, CURDATE(), 'maintenance', 'manual', ?, ?, ?)", [(int)$e['id'], $u['full_name'], $body, (int)$u['id']]);
        $id = (int) db()->lastInsertId();
        if (function_exists('nf_push')) { $pm = array_map('intval', array_column(all("SELECT DISTINCT pm_user_id FROM projects WHERE vessel_id = ? AND pm_user_id IS NOT NULL", [(int)$e['vessel_id']]), 'pm_user_id')); if ($pm) { try { nf_push($pm, 'eq_correction', 'eq:' . $e['id'] . ':' . $id, 'Equipment correction — ' . eq_label($e), $body, url('equipment', ['id' => (int)$e['id']]), (int)$u['id'], $u['full_name']); } catch (Throwable $e2) {} } }
        return ['id' => 'EH' . $id];
    }
    /** Photo for a report (FSR task or ISR item) or a note, stored by the portal's own file function (archive + sha256). */
    public static function storePhoto(array $u, string $ref, array $file, int $task, ?string $caption): array {
        if (preg_match('/^F(\d+)$/', $ref, $m)) { $r = one('SELECT id, project_id, subproject_id FROM fsr_reports WHERE id = ?', [(int)$m[1]]); if (!$r) throw new ApiException(404, 'Report not found');
            if ((int) scalar("SELECT COUNT(*) FROM sp_files WHERE fsr_id = ? AND kind = 'fsr_photo'", [(int)$r['id']]) >= FSR_MAX_PHOTOS) throw new ApiException(422, 'Photo limit of the report reached');
            $fid = cp_store_file($file, (int)$r['project_id'], (int)($r['subproject_id'] ?? 0) ?: null, 'fsr_photo', 'external', $caption, null, (int)$r['id']); if ($fid) q('UPDATE sp_files SET fsr_task = ? WHERE id = ?', [max(1, $task), $fid]);
            if ($fid) fsr_generate_documents((int)$r['id']); }
        elseif (preg_match('/^I(\d+)$/', $ref, $m)) { $r = one('SELECT id, project_id, subproject_id, report_no FROM isr_reports WHERE id = ?', [(int)$m[1]]); if (!$r) throw new ApiException(404, 'Report not found');
            $fid = cp_store_file($file, (int)$r['project_id'], (int)($r['subproject_id'] ?? 0) ?: null, 'isr_file', 'external', $caption, null, null, ['doc_no' => $r['report_no'], 'label' => 'sys' . max(1, $task), 'isr_id' => (int)$r['id']]); if ($fid) q('UPDATE sp_files SET isr_id = ?, isr_item = ? WHERE id = ?', [(int)$r['id'], max(1, $task), $fid]); }
        elseif (preg_match('/^N(\d+)$/', $ref, $m)) { $n = one('SELECT n.id, n.subproject_id, n.visibility, s.project_id FROM sp_notes n JOIN subprojects s ON s.id = n.subproject_id WHERE n.id = ?', [(int)$m[1]]); if (!$n) throw new ApiException(404, 'Note not found');
            $fid = cp_store_file($file, (int)$n['project_id'], (int)$n['subproject_id'], 'photo', $n['visibility'], $caption, (int)$n['id']); }
        elseif (preg_match('/^TC(\d+)$/', $ref, $m)) { $c = one('SELECT ticket_id FROM ticket_comments WHERE id = ?', [(int)$m[1]]); if (!$c) throw new ApiException(404, 'Comment not found'); $_FILES = ['files' => ['name' => [$file['name']], 'type' => [$file['type']], 'tmp_name' => [$file['tmp_name']], 'error' => [$file['error']], 'size' => [$file['size']]]]; $n = tk_store_uploads((int)$c['ticket_id'], (int)$m[1]); $fid = $n ? (int) db()->lastInsertId() : null; }
        else throw new ApiException(422, 'Unknown record');
        if (empty($fid)) throw new ApiException(422, 'Photo rejected');
        return ['file_id' => (int)$fid];
    }
    /** Claude assistance through the portal's own assistant (ai_run): kinds write / translate / ask / fill, page gating, cost cap, ai_log. */
    public static function assist(array $u, array $b): array {
        if (!function_exists('ai_run')) throw new ApiException(503, 'Assistant module not installed');
        $kind = in_array($b['kind'] ?? '', AI_KINDS, true) ? $b['kind'] : 'ask'; $page = (string)($b['page'] ?? 'fsr_edit');
        if (!ai_can($page)) throw new ApiException(403, ai_can() ? 'Assistant not enabled for this page in Settings › Assistant' : 'Assistant not enabled for this account');
        $fields = []; foreach (array_slice((array)($b['fields'] ?? []), 0, 60) as $f) $fields[] = ['name' => mb_substr((string)($f['name'] ?? ''), 0, 60), 'label' => mb_substr((string)($f['label'] ?? ''), 0, 80), 'type' => mb_substr((string)($f['type'] ?? 'text'), 0, 20), 'value' => mb_substr((string)($f['value'] ?? ''), 0, 2000), 'options' => array_slice((array)($f['options'] ?? []), 0, 40)];
        $banned = ['rate', 'price', 'amount', 'discount', 'total', 'cost', 'sale', 'value', 'budget', 'invoice'];
        $fields = array_values(array_filter($fields, fn($f) => !array_filter($banned, fn($b) => str_contains(strtolower($f['name'] . ' ' . $f['label']), $b))));   // CS-12: no monetary field reaches the model
        $ctx = ['title' => mb_substr((string)($b['title'] ?? ''), 0, 120), 'headline' => mb_substr((string)($b['headline'] ?? ''), 0, 200), 'fields' => $fields];
        $image = null; if (!empty($b['image_b64']) && in_array($b['image_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp'], true)) { $bytes = base64_decode((string)$b['image_b64'], true); if ($bytes !== false && strlen($bytes) <= AI_MAX_IMAGE) $image = ['type' => $b['image_type'], 'bytes' => $bytes]; }
        $res = ai_run($kind, $ctx, (string)($b['prompt'] ?? ''), $image, $page);
        if (empty($res['ok'])) throw new ApiException(502, (string)($res['error'] ?? 'Assistant failed'));
        return ['text' => (string)$res['text'], 'fields' => $res['fields'] ?? null, 'notes' => $res['notes'] ?? null, 'tokens' => (int)($res['tokens'] ?? 0)];
    }

    /* ------------------------------------------------------------------ HR — the employee's own page (portal "My leave", GAS-CP-R65-001) */
    private static function hrPerson(array $u): array { if (!function_exists('hr2_has') || !hr2_has()) throw new ApiException(503, 'HR module not installed'); $p = hr_person_of_user((int)$u['id']); if (!$p) throw new ApiException(404, t('hr.no_person')); return $p; }
    private static function num($v): ?float { return $v === null || $v === "" ? null : round((float)$v, 2); }
    /** Everything the portal's My leave page shows, as data: balances, open leave periods, request types, my requests, trainings, evaluations, HR messages. */
    public static function hr(array $u, int $year): array {
        $p = self::hrPerson($u); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
        $mgr = hr_manager_of($p); $contractor = function_exists('hr_is_contractor') && hr_is_contractor($p);
        $bal = []; foreach (hr_balances($p, $year) as $tid => $b) { if ($b['counts'] && !$b['via_annual'] && $b['total'] <= 0 && $b['taken'] <= 0 && $b['pending'] <= 0 && $b['opening'] <= 0) continue;
            $bal[] = ['type_id' => (int)$tid, 'code' => $b['type']['code'], 'name' => hr_type_name($b['type']), 'unit' => $b['unit'], 'consumes_annual' => (bool)$b['type']['consumes_annual'], 'entitled' => $b['counts'] && !$b['via_annual'] ? self::num($b['total']) : null, 'taken' => self::num($b['taken'] + $b['opening']), 'pending' => self::num($b['pending']), 'available' => self::num($b['available']), 'via_annual' => (bool)$b['via_annual']]; }
        $types = []; foreach ((function_exists('hr_types_for_person') ? hr_types_for_person($p) : hr_types()) as $tid => $t) $types[] = ['id' => (int)$tid, 'code' => $t['code'], 'name' => hr_type_name($t), 'unit' => $t['unit'], 'needs_reason' => (bool)$t['needs_reason'], 'needs_doc' => (bool)$t['needs_doc'], 'hr_only' => (bool)$t['hr_only']];
        $periods = []; if (function_exists('hr_lv_has') && hr_lv_has()) foreach (hr_periods_for($p) as $lp) $periods[] = ['id' => (int)$lp['id'], 'name' => $lp['name'], 'type_id' => (int)$lp['type_id'], 'code' => $lp['code'], 'type' => $lp['tname'], 'date_from' => $lp['date_from'], 'date_to' => $lp['date_to'], 'note' => (string)$lp['note'], 'mandatory' => !empty($lp['mandatory']), 'n_approved' => (int)$lp['n_approved'], 'max_people' => (int)($lp['max_people'] ?? 0) ?: null, 'full' => (bool)$lp['full'], 'joined' => $lp['joined'] ? ['id' => (int)$lp['joined']['id'], 'status' => $lp['joined']['status'], 'date_from' => $lp['joined']['date_from'], 'date_to' => $lp['joined']['date_to']] : null];
        $reqs = []; foreach (hr_requests_for('mine', $year) as $r) $reqs[] = ['id' => (int)$r['id'], 'type_id' => (int)$r['type_id'], 'code' => $r['code'], 'type' => hr_type((int)$r['type_id']) ? hr_type_name(hr_type((int)$r['type_id'])) : $r['type_name'], 'unit' => $r['unit'], 'date_from' => $r['date_from'], 'date_to' => $r['date_to'], 'time_from' => $r['time_from'] ? substr((string)$r['time_from'], 0, 5) : null, 'time_to' => $r['time_to'] ? substr((string)$r['time_to'], 0, 5) : null, 'hours' => self::num($r['hours']), 'days' => self::num($r['days']), 'reason' => (string)$r['reason'], 'status' => $r['status'], 'counted' => !isset($r['counted']) || (bool)$r['counted'], 'period_id' => (int)($r['period_id'] ?? 0) ?: null,
            'doc' => $r['doc_path'] ? ['name' => (string)$r['doc_name']] : null, 'manager' => $r['manager_at'] ? ['name' => (string)$r['manager_name'], 'at' => (string)$r['manager_at'], 'note' => (string)$r['manager_note']] : null, 'hr' => $r['hr_at'] ? ['at' => (string)$r['hr_at'], 'note' => (string)$r['hr_note']] : null, 'cancellable' => in_array($r['status'], ['pending_mgr', 'pending_hr'], true)];
        $trOpen = []; $trMine = []; $evs = [];
        if (function_exists('hr_tr_has') && hr_tr_has()) {
            foreach (hr_trainings_for($p) as $tr) $trOpen[] = ['id' => (int)$tr['id'], 'kind' => $tr['kind'], 'title' => $tr['title'], 'provider' => (string)$tr['provider'], 'location' => (string)$tr['location'], 'date_from' => $tr['date_from'], 'date_to' => $tr['date_to'], 'hours' => self::num($tr['hours']), 'description' => (string)$tr['description'], 'deadline' => $tr['deadline'], 'certificate' => (bool)$tr['certificate'], 'n_in' => (int)$tr['n_in'], 'capacity' => (int)($tr['capacity'] ?? 0) ?: null, 'mine' => $tr['mine']['status'] ?? null, 'full' => (bool)$tr['full'], 'late' => (bool)$tr['late']];
            foreach (hr_person_trainings((int)$p['id']) as $m) $trMine[] = ['id' => (int)$m['id'], 'training_id' => (int)$m['training_id'], 'kind' => $m['kind'], 'title' => $m['title'], 'provider' => (string)$m['provider'], 'date_from' => $m['date_from'], 'hours' => self::num($m['hours']), 'status' => $m['status'], 'score' => (string)($m['score'] ?? ''), 'cert_no' => (string)($m['cert_no'] ?? ''), 'cert_expiry' => $m['cert_expiry'] ?? null];
            foreach (hr_evaluations((int)$p['id']) as $e) { if ($e['status'] === 'draft') continue; $sc = json_decode((string)$e['scores'], true) ?: []; $evs[] = ['year' => (int)$e['year'], 'date' => $e['eval_date'], 'evaluator' => (string)$e['evaluator'], 'overall' => self::num($e['overall']), 'status' => $e['status'], 'scores' => array_map(fn($c) => ['criterion' => $c, 'label' => t('hr.ec_' . $c), 'score' => isset($sc[$c]) ? (int)$sc[$c] : null], defined('HR_EVAL_CRITERIA') ? HR_EVAL_CRITERIA : array_keys($sc)), 'strengths' => (string)$e['strengths'], 'improvements' => (string)$e['improvements'], 'goals' => (string)$e['goals'], 'training_needs' => (string)$e['training_needs'], 'comments' => (string)$e['comments'], 'employee_comment' => (string)$e['employee_comment'], 'acknowledged_at' => $e['acknowledged_at']]; }
        }
        $msgs = []; if (function_exists('hr_msg_has') && hr_msg_has()) foreach (hr_messages((int)$p['id']) as $m) $msgs[] = ['id' => (int)$m['id'], 'from_hr' => (bool)$m['from_hr'], 'from' => $m['from_hr'] ? 'HR' . ($m['from_name'] ? ' — ' . $m['from_name'] : '') : (string)$m['from_name'], 'body' => (string)$m['body'], 'at' => (string)$m['created_at'], 'seen' => $m['seen_at'] !== null];
        return ['year' => $year, 'years' => array_values(array_unique(array_merge(hr_years(), [$year]))), 'person' => ['id' => (int)$p['id'], 'name' => (string)$p['name_en'], 'department' => (string)(scalar('SELECT name FROM disciplines WHERE id = ?', [(int)($p['department_id'] ?? 0)]) ?: ''), 'hire_date' => $p['hire_date'] ?? null, 'service' => function_exists('hr_service') ? hr_fmt_months((int)(hr_service($p)['total'] ?? 0)) : '', 'contractor' => $contractor, 'jurisdiction' => function_exists('hr_jurisdiction') ? hr_jurisdiction($p) : 'GR'],
            'manager' => $mgr ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$mgr]) : '', 'balances' => $bal, 'types' => $types, 'periods' => $periods, 'requests' => $reqs, 'trainings' => ['open' => $trOpen, 'mine' => $trMine], 'evaluations' => $evs, 'messages' => $msgs, 'unread' => function_exists('hr_messages_unread') ? hr_messages_unread() : 0, 'hours_per_day' => defined('HR_HOURS_PER_DAY') ? HR_HOURS_PER_DAY : 8];
    }
    /** New leave request = the portal's own hr_request_create_from_post (balance, overlap, working days, document rules); $file = the uploaded document (multipart) or null. */
    public static function hrRequest(array $u, array $p, ?array $file): array {
        self::hrPerson($u);
        self::post(['type_id' => (int)($p['type_id'] ?? 0), 'date_from' => (string)($p['date_from'] ?? ''), 'date_to' => (string)($p['date_to'] ?? ''), 'reason' => (string)($p['reason'] ?? ''), 'hours' => (string)($p['hours'] ?? ''), 'time_from' => (string)($p['time_from'] ?? ''), 'time_to' => (string)($p['time_to'] ?? '')]);
        if ($file) $_FILES['doc'] = $file;
        try { $id = hr_request_create_from_post(null); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); }
        $r = one('SELECT status, days, hours FROM hr_requests WHERE id = ?', [$id]); return ['id' => 'HR' . $id, 'request_id' => $id, 'status' => $r['status'], 'days' => self::num($r['days']), 'hours' => self::num($r['hours'])];
    }
    public static function hrCancel(array $u, array $p): array { self::hrPerson($u); try { hr_request_cancel((int)($p['request_id'] ?? substr((string)($p['id'] ?? ''), 2))); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'HR' . (int)($p['request_id'] ?? substr((string)($p['id'] ?? ''), 2)), 'status' => 'cancelled']; }
    public static function hrPeriodJoin(array $u, array $p): array { $person = self::hrPerson($u); self::post([]); try { $id = hr_period_join($person, (int)($p['period_id'] ?? 0), (string)($p['date_from'] ?? ''), (string)($p['date_to'] ?? '')); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'HR' . $id, 'request_id' => $id, 'status' => 'pending_mgr']; }
    public static function hrTrainingApply(array $u, array $p): array { $person = self::hrPerson($u); self::post(['note' => (string)($p['note'] ?? '')]); try { hr_training_apply($person, (int)($p['training_id'] ?? 0)); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'TR' . (int)$p['training_id'], 'status' => 'applied']; }
    public static function hrEvalAck(array $u, array $p): array { $person = self::hrPerson($u); self::post(['employee_comment' => (string)($p['comment'] ?? '')]); try { hr_evaluation_ack($person, (int)($p['year'] ?? 0)); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'EV' . (int)$p['year'], 'status' => 'acknowledged']; }
    public static function hrMessage(array $u, array $p): array { self::hrPerson($u); self::post(['body' => (string)($p['text'] ?? $p['body'] ?? '')]); try { $id = hr_message_send_from_post(); } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } return ['id' => 'HM' . $id]; }
    public static function hrSeen(array $u): array { $p = self::hrPerson($u); hr_messages_mark_seen((int)$p['id']); return ['unread' => 0]; }
    /** The document attached to one of my requests (same rule as ?p=hr_doc). */
    public static function hrDocStream(array $u, int $reqId): void { self::hrPerson($u); $r = one('SELECT user_id FROM hr_requests WHERE id = ?', [$reqId]); if (!$r || (int)$r['user_id'] !== (int)$u['id']) throw new ApiException(404, 'Not found'); hr_doc_stream($reqId); }
}

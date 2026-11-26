<?php
/**
 * HR › Recruiting — GAS-CP-R89-001.
 * Vacancies, online selection events, screening sets, applications (with CV), event registrations and the talent pool,
 * published to goldenas.com.gr/careers through a public JSON feed (rc_feed.php, X-Site-Key) and followed by HR in the
 * Recruiting dashboard. Scoring of the website questionnaire happens HERE (option scores never leave the server).
 * Who: hr_can() (HR scope, Owner / CEO) read-write; hiring managers read their own vacancies. CS-24 / CS-46 apply: no
 * candidate data in the apps API beyond what the HR app route returns; CVs live under storage/rc (outside the web root).
 * GDPR: rcr_retention_run() deletes / anonymises after rc_retention_months (default 6) — call it from tools/cron.php daily.
 */
declare(strict_types=1);

const RCR_APP_STATUS = ['screened', 'cv_received', 'shortlisted', 'interview', 'offer', 'hired', 'rejected', 'withdrawn'];
const RCR_ACTIVE_STATUS = ['shortlisted', 'interview', 'offer'];               // retention clock paused while here
const RCR_EVT_KIND = ['assessment', 'webinar', 'qna'];
const RCR_EMP_TYPE = ['full_time', 'part_time', 'contract', 'internship'];
const RCR_SENIORITY = ['junior', 'mid', 'senior', 'lead'];
const RCR_EVAL_STAGE = ['cv_review', 'phone', 'technical', 'practical', 'final'];          // R91-001
const RCR_EVAL_RECO = ['strong_hire', 'hire', 'hold', 'no_hire'];
const RCR_INT_STAGE = ['phone', 'technical', 'practical', 'final'];

function rcr_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'rc_vacancies'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rcr_can(): bool { return rcr_has() && function_exists('hr_can') && hr_can(); }
function rcr_can_view(int $vacancyId = 0): bool {                                 // hiring manager of that vacancy may read it
    if (rcr_can()) return true; if (!rcr_has() || !is_staff() || !$vacancyId) return false;
    return (int) (one('SELECT hiring_manager_id FROM rc_vacancies WHERE id = ?', [$vacancyId])['hiring_manager_id'] ?? 0) === (int) current_user()['id'];
}
function rcr_dir(): string { $d = dirname(__DIR__) . '/storage/rc'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function rcr_code(string $prefix, string $table): string {                        // VAC-2026-0014 — per year, per table; MAX+1 (not COUNT: retention deletes rows)
    $y = date('Y'); $max = (int) scalar("SELECT MAX(CAST(SUBSTRING_INDEX(code, '-', -1) AS UNSIGNED)) FROM $table WHERE code LIKE ?", ["$prefix-$y-%"]);
    return sprintf('%s-%s-%04d', $prefix, $y, $max + 1);
}
function rcr_log(string $action, array $ref = [], ?string $detail = null): void {
    q('INSERT INTO rc_log (application_id, event_id, vacancy_id, action, detail, user_id) VALUES (?,?,?,?,?,?)',
      [$ref['application_id'] ?? null, $ref['event_id'] ?? null, $ref['vacancy_id'] ?? null, $action, $detail !== null ? mb_substr($detail, 0, 500) : null, current_user()['id'] ?? null]);
}
function rcr_notify_hr(string $title, ?string $body, string $url): void {
    if (!function_exists('nf_push') || !function_exists('hr_hr_user_ids')) return;
    try { nf_push(hr_hr_user_ids(), 'rc', 'rc:' . md5($title . $url . time()), $title, $body, $url, null, 'Website'); } catch (Throwable $e) {}
}
/** Outbound e-mail to a candidate. The portal otherwise sends nothing itself (✉ cards are Outlook drafts); this uses PHP mail() only
 *  when Settings › Recruiting has a From address — otherwise the message is kept in rc_log for HR to send by hand. */
function rcr_mail(string $to, string $subject, string $body): bool {
    $hr = trim((string) cp_setting('rc_mail_from', ''));
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || ($hr === '' && !(function_exists('cp_mail_configured') && cp_mail_configured()))) { rcr_log('mail_pending', [], "$to | $subject"); return false; }
    $ok = function_exists('cp_mail') ? cp_mail($to, $subject, $body, 'Golden A/S HR', $hr !== '' && cp_mail_configured() ? null : ($hr ?: null), $hr ?: null)
                                     : @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, "From: Golden A/S HR <$hr>\r\nReply-To: $hr\r\nContent-Type: text/plain; charset=UTF-8\r\nMIME-Version: 1.0");
    rcr_log($ok ? 'mail_sent' : 'mail_failed', [], "$to | $subject"); return $ok;
}

/* ------------------------------------------------------------------ screening sets */
function rcr_sets(bool $activeOnly = true): array { return rcr_has() ? all('SELECT * FROM rc_screening_sets' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY code') : []; }
function rcr_set(int $id): ?array { return one('SELECT * FROM rc_screening_sets WHERE id = ?', [$id]); }
function rcr_set_by_code(string $code): ?array { return one('SELECT * FROM rc_screening_sets WHERE code = ? AND is_active = 1', [$code]); }
/** Questions for the browser: scores stripped, text in the requested language when translated. */
function rcr_set_public(array $s, string $lang = 'en'): array {
    $qs = json_decode((string) $s['questions'], true) ?: []; $out = [];
    foreach ($qs as $q) { $o = []; foreach ((array) ($q['options'] ?? []) as $op) $o[] = ['id' => (string) $op['id'], 'text' => (string) ($op['text_' . $lang] ?? $op['text'])];
        $out[] = ['id' => (string) $q['id'], 'group' => (string) ($q['group'] ?? ''), 'text' => (string) ($q['text_' . $lang] ?? $q['text']), 'options' => $o]; }
    return ['set' => $s['code'], 'pass_threshold' => (int) $s['pass_threshold'], 'knockout_groups' => array_values(array_filter(array_map('trim', explode(',', (string) $s['knockout_groups'])))), 'questions' => $out];
}
/** Authoritative scoring. Returns [score, max, passed, knockout_ok]; throws when an answer is missing. */
function rcr_score(array $s, array $answers): array {
    $qs = json_decode((string) $s['questions'], true) ?: []; $ko = array_filter(array_map('trim', explode(',', (string) $s['knockout_groups']))); $score = 0; $koOk = true;
    foreach ($qs as $q) { $pick = $answers[$q['id']] ?? null; if ($pick === null || $pick === '') throw new RuntimeException('answer all questions'); $sc = 0;
        foreach ((array) $q['options'] as $op) if ((string) $op['id'] === (string) $pick) $sc = (int) ($op['score'] ?? 0);
        $score += $sc; if (in_array((string) ($q['group'] ?? ''), $ko, true) && $sc < 1) $koOk = false; }
    return ['score' => $score, 'max' => count($qs), 'passed' => $score >= (int) $s['pass_threshold'] && $koOk, 'knockout_ok' => $koOk];
}
function rcr_set_save(array $in): int {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int) ($in['id'] ?? 0); $qs = json_decode((string) ($in['questions'] ?? '[]'), true); if (!is_array($qs) || !$qs) throw new RuntimeException('Questions must be a JSON array');
    $p = [mb_substr(trim((string) $in['code']), 0, 40), mb_substr(trim((string) $in['title']), 0, 120), max(1, (int) $in['pass_threshold']), mb_substr(trim((string) ($in['knockout_groups'] ?? 'loyalty')), 0, 120), json_encode($qs, JSON_UNESCAPED_UNICODE), !empty($in['is_active']) ? 1 : 0, current_user()['id']];
    if ($id) { q('UPDATE rc_screening_sets SET code=?, title=?, pass_threshold=?, knockout_groups=?, questions=?, is_active=?, updated_by=? WHERE id=?', [...$p, $id]); return $id; }
    q('INSERT INTO rc_screening_sets (code, title, pass_threshold, knockout_groups, questions, is_active, updated_by) VALUES (?,?,?,?,?,?,?)', $p); return (int) db()->lastInsertId();
}

/* ------------------------------------------------------------------ vacancies */
function rcr_vacancies(array $f = []): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['status'])) { $w[] = 'v.status = ?'; $p[] = $f['status']; }
    if (!empty($f['department_id'])) { $w[] = 'v.department_id = ?'; $p[] = (int) $f['department_id']; }
    if (!rcr_can()) { $w[] = 'v.hiring_manager_id = ?'; $p[] = (int) current_user()['id']; }
    return all('SELECT v.*, d.name AS department, b.name AS branch, s.code AS set_code, cu.full_name AS hiring_manager,
        (SELECT COUNT(*) FROM rc_applications a WHERE a.vacancy_id = v.id) AS n_apps,
        (SELECT COUNT(*) FROM rc_applications a WHERE a.vacancy_id = v.id AND a.passed = 1) AS n_passed,
        (SELECT COUNT(*) FROM rc_applications a WHERE a.vacancy_id = v.id AND a.status IN ("shortlisted","interview","offer")) AS n_active
        FROM rc_vacancies v LEFT JOIN disciplines d ON d.id = v.department_id LEFT JOIN branches b ON b.id = v.branch_id
        JOIN rc_screening_sets s ON s.id = v.screening_set_id LEFT JOIN customer_users cu ON cu.id = v.hiring_manager_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(v.status,"open","draft","closed","filled"), v.published_at DESC, v.id DESC', $p);
}
function rcr_vacancy(int $id): ?array { return one('SELECT v.*, d.name AS department, b.name AS branch, s.code AS set_code FROM rc_vacancies v LEFT JOIN disciplines d ON d.id = v.department_id LEFT JOIN branches b ON b.id = v.branch_id JOIN rc_screening_sets s ON s.id = v.screening_set_id WHERE v.id = ?', [$id]); }
function rcr_vacancy_save(array $in): int {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int) ($in['id'] ?? 0); $title = mb_substr(trim((string) $in['title']), 0, 190); if ($title === '') throw new RuntimeException('Title is required');
    $req = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) ($in['requirements'] ?? ''))))); $tags = array_values(array_filter(array_map('trim', explode(',', (string) ($in['tags'] ?? '')))));
    $status = in_array($in['status'] ?? 'draft', ['draft', 'open', 'closed', 'filled'], true) ? $in['status'] : 'draft';
    $p = [$title, (int) $in['department_id'] ?: null, (int) ($in['branch_id'] ?? 0) ?: null, mb_substr(trim((string) ($in['location'] ?? '')), 0, 120) ?: null,
        in_array($in['emp_type'] ?? '', RCR_EMP_TYPE, true) ? $in['emp_type'] : 'full_time', in_array($in['seniority'] ?? '', RCR_SENIORITY, true) ? $in['seniority'] : 'mid',
        mb_substr(trim((string) ($in['summary'] ?? '')), 0, 600) ?: null, (string) ($in['description_html'] ?? '') ?: null, json_encode($req, JSON_UNESCAPED_UNICODE), json_encode($tags, JSON_UNESCAPED_UNICODE),
        (int) $in['screening_set_id'], $status, !empty($in['publish_web']) ? 1 : 0, ($in['closes_at'] ?? '') ?: null, (int) ($in['hiring_manager_id'] ?? 0) ?: null];
    if ($id) { $old = rcr_vacancy($id); q('UPDATE rc_vacancies SET title=?, department_id=?, branch_id=?, location=?, emp_type=?, seniority=?, summary=?, description_html=?, requirements=?, tags=?, screening_set_id=?, status=?, publish_web=?, closes_at=?, hiring_manager_id=?' . ($status === 'open' && ($old['status'] ?? '') !== 'open' ? ', published_at = CURDATE()' : '') . ' WHERE id=?', [...$p, $id]);
        rcr_log('vacancy_saved', ['vacancy_id' => $id], ($old['status'] ?? '') . ' → ' . $status); rcr_site_purge(); return $id; }
    q('INSERT INTO rc_vacancies (title, department_id, branch_id, location, emp_type, seniority, summary, description_html, requirements, tags, screening_set_id, status, publish_web, closes_at, hiring_manager_id, code, published_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [...$p, rcr_code('VAC', 'rc_vacancies'), $status === 'open' ? date('Y-m-d') : null, current_user()['id']]);
    $id = (int) db()->lastInsertId(); rcr_log('vacancy_created', ['vacancy_id' => $id]); rcr_site_purge(); return $id;
}

/* ------------------------------------------------------------------ events */
function rcr_events(array $f = []): array {
    $w = ['1=1']; $p = []; if (!empty($f['status'])) { $w[] = 'e.status = ?'; $p[] = $f['status']; }
    return all('SELECT e.*, (SELECT COUNT(*) FROM rc_event_registrations r WHERE r.event_id = e.id) AS n_reg, (SELECT COUNT(*) FROM rc_event_registrations r WHERE r.event_id = e.id AND r.attended = 1) AS n_att FROM rc_events e WHERE ' . implode(' AND ', $w) . ' ORDER BY e.starts_at DESC', $p);
}
function rcr_event(int $id): ?array { return one('SELECT e.*, (SELECT COUNT(*) FROM rc_event_registrations r WHERE r.event_id = e.id) AS n_reg FROM rc_events e WHERE e.id = ?', [$id]); }
function rcr_event_regs(int $id): array { return all('SELECT r.*, a.code AS app_code, a.status AS app_status, a.score, a.max_score, a.passed FROM rc_event_registrations r LEFT JOIN rc_applications a ON a.id = r.application_id WHERE r.event_id = ? ORDER BY r.created_at', [$id]); }
function rcr_event_save(array $in): int {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int) ($in['id'] ?? 0); $title = mb_substr(trim((string) $in['title']), 0, 190); if ($title === '' || empty($in['starts_at'])) throw new RuntimeException('Title and start are required');
    $fv = array_values(array_filter(array_map('intval', (array) ($in['for_vacancies'] ?? []))));
    $p = [$title, in_array($in['kind'] ?? '', RCR_EVT_KIND, true) ? $in['kind'] : 'assessment', str_replace('T', ' ', (string) $in['starts_at']), max(15, (int) ($in['duration_min'] ?? 90)), mb_substr(trim((string) ($in['platform'] ?? 'Microsoft Teams')), 0, 60), mb_substr(trim((string) ($in['join_url'] ?? '')), 0, 500) ?: null,
        (int) ($in['capacity'] ?? 0) ?: null, (string) ($in['description_html'] ?? '') ?: null, json_encode($fv), ($in['registration_closes_at'] ?? '') ? str_replace('T', ' ', (string) $in['registration_closes_at']) : null,
        in_array($in['status'] ?? 'draft', ['draft', 'upcoming', 'closed', 'done', 'cancelled'], true) ? $in['status'] : 'draft', !empty($in['publish_web']) ? 1 : 0];
    if ($id) { q('UPDATE rc_events SET title=?, kind=?, starts_at=?, duration_min=?, platform=?, join_url=?, capacity=?, description_html=?, for_vacancies=?, registration_closes_at=?, status=?, publish_web=? WHERE id=?', [...$p, $id]); rcr_log('event_saved', ['event_id' => $id]); rcr_site_purge(); return $id; }
    q('INSERT INTO rc_events (title, kind, starts_at, duration_min, platform, join_url, capacity, description_html, for_vacancies, registration_closes_at, status, publish_web, code, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$p, rcr_code('EVT', 'rc_events'), current_user()['id']]);
    $id = (int) db()->lastInsertId(); rcr_log('event_created', ['event_id' => $id]); rcr_site_purge(); return $id;
}
function rcr_event_reg_save(int $regId, array $in): void {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    q('UPDATE rc_event_registrations SET attended = ?, result = ?, result_note = ?, scored_by = ? WHERE id = ?', [isset($in['attended']) ? (int) (bool) $in['attended'] : null, in_array($in['result'] ?? '', ['strong', 'good', 'weak', 'no_show'], true) ? $in['result'] : null, mb_substr(trim((string) ($in['result_note'] ?? '')), 0, 500) ?: null, current_user()['id'], $regId]);
}

/* ------------------------------------------------------------------ applications */
function rcr_applications(array $f = []): array {
    $w = ['1=1']; $p = [];
    if (!empty($f['vacancy_id'])) { $w[] = 'a.vacancy_id = ?'; $p[] = (int) $f['vacancy_id']; }
    if (!empty($f['status'])) { $w[] = 'a.status = ?'; $p[] = $f['status']; }
    if (isset($f['passed']) && $f['passed'] !== '') { $w[] = 'a.passed = ?'; $p[] = (int) $f['passed']; }
    if (!empty($f['q'])) { $w[] = '(a.name LIKE ? OR a.email LIKE ? OR a.code LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($p, $like, $like, $like); }
    if (!rcr_can()) { $w[] = 'v.hiring_manager_id = ?'; $p[] = (int) current_user()['id']; }
    return all('SELECT a.*, v.title AS vacancy, v.code AS vacancy_code, cu.full_name AS owner FROM rc_applications a JOIN rc_vacancies v ON v.id = a.vacancy_id LEFT JOIN customer_users cu ON cu.id = a.owner_user_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.created_at DESC LIMIT 500', $p);
}
function rcr_application(int $id): ?array { return one('SELECT a.*, v.title AS vacancy, v.code AS vacancy_code, v.hiring_manager_id FROM rc_applications a JOIN rc_vacancies v ON v.id = a.vacancy_id WHERE a.id = ?', [$id]); }
/* R97-266: dated notes on an applicant — who wrote what and when; each one is also a line of the history */
function rcr_notes_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'rc_notes'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rcr_notes(int $appId): array { return rcr_notes_has() ? all('SELECT n.*, cu.full_name AS by_name FROM rc_notes n LEFT JOIN customer_users cu ON cu.id = n.user_id WHERE n.application_id = ? ORDER BY n.created_at DESC, n.id DESC', [$appId]) : []; }
function rcr_note_add(int $appId, string $body): void {
    $a = rcr_application($appId); if (!$a || !rcr_can_view((int) $a['vacancy_id']) || !rcr_notes_has()) throw new RuntimeException(t('err.noaccess'));
    $body = mb_substr(trim($body), 0, 4000); if ($body === '') throw new RuntimeException(t('rc.note_need'));
    q('INSERT INTO rc_notes (application_id, body, user_id) VALUES (?,?,?)', [$appId, $body, (int) (current_user()['id'] ?? 0) ?: null]);
    rcr_log('note', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], mb_strimwidth(preg_replace('/\s+/u', ' ', $body), 0, 300, '…'));
}
function rcr_application_events(int $id): array { return all('SELECT r.*, e.title, e.starts_at, e.kind FROM rc_event_registrations r JOIN rc_events e ON e.id = r.event_id WHERE r.application_id = ? ORDER BY e.starts_at', [$id]); }
function rcr_application_decide(int $id, array $in): void {
    $a = rcr_application($id); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess'));
    $st = in_array($in['status'] ?? '', RCR_APP_STATUS, true) ? $in['status'] : $a['status'];
    q('UPDATE rc_applications SET status = ?, rating = ?, hr_notes = ?, owner_user_id = ?, status_changed_at = IF(status <> ?, NOW(), status_changed_at) WHERE id = ?',
      [$st, (int) ($in['rating'] ?? 0) ?: null, mb_substr(trim((string) ($in['hr_notes'] ?? '')), 0, 4000) ?: null, (int) ($in['owner_user_id'] ?? 0) ?: null, $st, $id]);
    $ch = []; $nr = (int) ($in['rating'] ?? 0); $no = (int) ($in['owner_user_id'] ?? 0); $nn = mb_substr(trim((string) ($in['hr_notes'] ?? '')), 0, 4000);   // R97-266: every change of the decision card is written in the history
    if ($nr !== (int) $a['rating']) $ch[] = t('rc.rating') . ' ' . ((int) $a['rating'] ?: '—') . ' → ' . ($nr ?: '—');
    if ($no !== (int) $a['owner_user_id']) $ch[] = t('rc.owner') . ' → ' . ($no ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$no]) : '—');
    if ($nn !== trim((string) $a['hr_notes'])) $ch[] = t('hr.notes') . ': ' . ($nn === '' ? '—' : mb_strimwidth(preg_replace('/\s+/u', ' ', $nn), 0, 300, '…'));
    if ($ch) rcr_log('decision', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], mb_substr(implode(' · ', $ch), 0, 490));
    if ($st !== $a['status']) { rcr_log('status', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], $a['status'] . ' → ' . $st);
        if (!empty($in['notify_candidate']) && in_array($st, ['shortlisted', 'offer', 'rejected'], true)) rcr_mail((string) $a['email'], 'Golden A/S — ' . t('rc.st_' . $st) . ' · ' . $a['vacancy_code'], ($a['name'] ?: $a['email']) . ",\n\n" . t('rc.ev_mail_' . $st, ['vacancy' => $a['vacancy']]) . "\n\nGolden Automation Systems — HR"); }   // R91-001
    if ($st === 'hired' && empty($a['hired_person_id']) && function_exists('hr2_has') && hr2_has()) {   // onboarding handshake: a person card in HR › People
        q('INSERT INTO hr_people (name_en, is_technical, hire_date) VALUES (?, 1, CURDATE())', [(string) ($a['name'] ?: $a['email'])]); $pid = (int) db()->lastInsertId();
        q('UPDATE rc_applications SET hired_person_id = ? WHERE id = ?', [$pid, $id]); rcr_log('person_created', ['application_id' => $id], 'hr_people #' . $pid);
        rcr_cv_to_person($id);   // R91-004
    }
}
function rcr_cv_stream(int $id): void {
    $a = rcr_application($id); if (!$a || !rcr_can_view((int) $a['vacancy_id']) || !$a['cv_path'] || !is_file($a['cv_path'])) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($a['cv_path'], PATHINFO_EXTENSION)); header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream')); header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]+/', '_', (string) $a['cv_name']) . '"'); header('X-Content-Type-Options: nosniff'); readfile($a['cv_path']); exit;
}

/* ------------------------------------------------------------------ public website feed (called by rc_feed.php) */
function rcr_pub_vacancies(string $lang): array {
    $rows = all('SELECT v.code AS id, v.title, d.name AS department, v.location, b.name AS branch, v.emp_type, v.seniority, v.summary, v.description_html, v.requirements, v.tags, s.code AS screening_set, v.published_at, v.closes_at FROM rc_vacancies v LEFT JOIN disciplines d ON d.id = v.department_id LEFT JOIN branches b ON b.id = v.branch_id JOIN rc_screening_sets s ON s.id = v.screening_set_id WHERE v.status = "open" AND v.publish_web = 1 AND v.code <> "VAC-GENERAL" AND (v.closes_at IS NULL OR v.closes_at >= CURDATE()) ORDER BY v.published_at DESC');
    $type = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'internship' => 'Internship'];
    foreach ($rows as &$r) { $r['type'] = $type[$r['emp_type']] ?? $r['emp_type']; unset($r['emp_type']); $r['seniority'] = ucfirst((string) $r['seniority']); $r['requirements'] = json_decode((string) $r['requirements']) ?: []; $r['tags'] = json_decode((string) $r['tags']) ?: []; }
    return $rows;
}
function rcr_pub_events(): array {
    $rows = all('SELECT e.id AS iid, e.code AS id, e.title, e.kind, e.starts_at, e.duration_min, e.platform, e.capacity, e.description_html AS description, e.for_vacancies, e.registration_closes_at, (e.capacity - (SELECT COUNT(*) FROM rc_event_registrations r WHERE r.event_id = e.id)) AS seats_left FROM rc_events e WHERE e.status = "upcoming" AND e.publish_web = 1 AND e.starts_at > NOW() ORDER BY e.starts_at');
    foreach ($rows as &$r) { $ids = json_decode((string) $r['for_vacancies'], true) ?: []; $r['for_vacancies'] = $ids ? array_column(all('SELECT code FROM rc_vacancies WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')'), 'code') : []; $r['starts_at'] = date('c', strtotime((string) $r['starts_at'])); unset($r['iid']); if ($r['capacity'] === null) $r['seats_left'] = null; }
    return $rows;
}
/** Website step 1. Returns the created application (passed / score / cv token) or throws RuntimeException with an HTTP-like code in getCode(). */
function rcr_pub_apply(array $in, string $ip, string $ua): array {
    $email = strtolower(trim((string) ($in['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['consent_gdpr'])) throw new RuntimeException('validation', 422);
    $v = one('SELECT * FROM rc_vacancies WHERE code = ? AND status = "open" AND publish_web = 1', [(string) ($in['vacancy_id'] ?? '')]); if (!$v || $v['code'] === RCR_GENERAL_CODE) throw new RuntimeException('vacancy closed', 410);
    $s = rcr_set((int) $v['screening_set_id']); if (!$s) throw new RuntimeException('no screening set', 500);
    try { $r = rcr_score($s, (array) ($in['answers'] ?? [])); } catch (RuntimeException $e) { throw new RuntimeException($e->getMessage(), 422); }
    $tok = bin2hex(random_bytes(24)); $code = rcr_code('APP', 'rc_applications');
    try { q('INSERT INTO rc_applications (code, vacancy_id, email, name, phone, answers, score, max_score, passed, knockout_ok, cv_token_hash, lang, ip, user_agent, consent_version, consent_at, status_changed_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$code, (int) $v['id'], $email, mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null, json_encode($in['answers'], JSON_UNESCAPED_UNICODE), $r['score'], $r['max'], $r['passed'] ? 1 : 0, $r['knockout_ok'] ? 1 : 0, $r['passed'] ? password_hash($tok, PASSWORD_DEFAULT) : null, in_array((string) ($in['lang'] ?? 'en'), ['en', 'el', 'it', 'nl'], true) ? (string) $in['lang'] : 'en', $ip, mb_substr($ua, 0, 255), '2026-11']); }
    catch (PDOException $e) { if ($e->getCode() === '23000' && str_contains($e->getMessage(), 'uq_rca_vac_email')) throw new RuntimeException('already applied', 409); throw $e; }
    $id = (int) db()->lastInsertId(); rcr_log('applied', ['application_id' => $id, 'vacancy_id' => (int) $v['id']], ($r['passed'] ? 'PASS' : 'FAIL') . " {$r['score']}/{$r['max']}");
    rcr_notify_hr(($r['passed'] ? '✅ ' : '○ ') . 'New application — ' . $v['title'], "$code · " . ($in['name'] ?? $email) . " · {$r['score']}/{$r['max']}", url('rcr_application', ['id' => $id]));
    return ['application_id' => $code, 'passed' => $r['passed'], 'score' => $r['score'], 'max' => $r['max'], 'loyalty_ok' => $r['knockout_ok'], 'cv_upload_token' => $r['passed'] ? $tok : null];
}
/** Website step 2 — CV upload with the one-time token. */
function rcr_pub_cv(string $appCode, string $token, array $file, array $post): void {
    $a = one('SELECT * FROM rc_applications WHERE code = ?', [$appCode]); if (!$a || !$a['passed'] || !$a['cv_token_hash'] || !password_verify($token, (string) $a['cv_token_hash'])) throw new RuntimeException('forbidden', 403);
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || $file['size'] > (int) cp_setting('rc_cv_max_mb', '8') * 1048576) throw new RuntimeException('file', 422);
    $mime = (string) mime_content_type($file['tmp_name']); $ext = ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'][$mime] ?? null; if (!$ext) throw new RuntimeException('type', 415);
    $dest = rcr_dir() . '/' . $a['code'] . '_' . bin2hex(random_bytes(6)) . '.' . $ext; if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('store', 500);
    q('UPDATE rc_applications SET cv_path = ?, cv_name = ?, cover_note = ?, department_interest = ?, status = "cv_received", cv_token_hash = NULL, status_changed_at = NOW() WHERE id = ?', [$dest, mb_substr((string) $file['name'], 0, 190), mb_substr(trim((string) ($post['cover_note'] ?? '')), 0, 4000) ?: null, mb_substr(trim((string) ($post['department_interest'] ?? '')), 0, 120) ?: null, (int) $a['id']]);
    rcr_log('cv_received', ['application_id' => (int) $a['id'], 'vacancy_id' => (int) $a['vacancy_id']], (string) $file['name']);
}
/* ---- R97-158: general applications — a CV sent from the website without a vacancy ----
 * They are ordinary applicant records (card, CV, notes, evaluations, interviews, retention) numbered GEN-YYYY-NNNN and kept under one
 * internal vacancy (code VAC-GENERAL, never shown on the website) until HR assigns them to a real vacancy; the GEN number stays. */
const RCR_GENERAL_CODE = 'VAC-GENERAL';
function rcr_general_vacancy(bool $create = true): ?array {
    static $v = null; if ($v) return $v; if (!rcr_has()) return null;
    $v = one('SELECT * FROM rc_vacancies WHERE code = ?', [RCR_GENERAL_CODE]) ?: null; if ($v || !$create) return $v;
    $set = one('SELECT id FROM rc_screening_sets ORDER BY is_active DESC, id LIMIT 1'); if (!$set) return null;   // the table requires a screening set; general applications are not screened
    q('INSERT INTO rc_vacancies (code, title, summary, screening_set_id, status, publish_web, published_at) VALUES (?,?,?,?,?,0,CURDATE())', [RCR_GENERAL_CODE, 'General applications', 'CVs sent from the website without a vacancy. Assign a candidate to a vacancy from his / her card.', (int) $set['id'], 'open']);
    return $v = one('SELECT * FROM rc_vacancies WHERE code = ?', [RCR_GENERAL_CODE]) ?: null;
}
function rcr_is_general(array $a): bool { return str_starts_with((string) ($a['code'] ?? ''), 'GEN-'); }
/** Website: general application in one step — name, e-mail, consent and the CV are mandatory. Returns the GEN number. */
function rcr_pub_general(array $in, array $file, string $ip, string $ua): array {
    $email = strtolower(trim((string) ($in['email'] ?? ''))); $name = mb_substr(trim((string) ($in['name'] ?? '')), 0, 120);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '' || empty($in['consent_gdpr'])) throw new RuntimeException('validation', 422);
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || $file['size'] > (int) cp_setting('rc_cv_max_mb', '8') * 1048576) throw new RuntimeException('file', 422);
    $mime = (string) mime_content_type($file['tmp_name']); $ext = ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'][$mime] ?? null; if (!$ext) throw new RuntimeException('type', 415);
    $v = rcr_general_vacancy(); if (!$v) throw new RuntimeException('not available', 503);
    if (one('SELECT id FROM rc_applications WHERE vacancy_id = ? AND email = ?', [(int) $v['id'], $email])) throw new RuntimeException('already applied', 409);
    $code = rcr_code('GEN', 'rc_applications'); $dest = rcr_dir() . '/' . $code . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException('store', 500);
    try { q('INSERT INTO rc_applications (code, vacancy_id, email, name, phone, passed, knockout_ok, status, cv_path, cv_name, cover_note, department_interest, lang, ip, user_agent, consent_version, consent_at, status_changed_at) VALUES (?,?,?,?,?,1,1,"cv_received",?,?,?,?,?,?,?,?,NOW(),NOW())',
        [$code, (int) $v['id'], $email, $name, mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null, $dest, mb_substr((string) $file['name'], 0, 190), mb_substr(trim((string) ($in['cover_note'] ?? '')), 0, 4000) ?: null, mb_substr(trim((string) ($in['department_interest'] ?? '')), 0, 120) ?: null,
         in_array((string) ($in['lang'] ?? 'en'), ['en', 'el', 'it', 'nl'], true) ? (string) $in['lang'] : 'en', $ip, mb_substr($ua, 0, 255), '2026-11']); }
    catch (Throwable $e) { @unlink($dest); if ($e instanceof PDOException && $e->getCode() === '23000') throw new RuntimeException('already applied', 409); throw $e; }
    $id = (int) db()->lastInsertId(); rcr_log('applied', ['application_id' => $id, 'vacancy_id' => (int) $v['id']], 'General application · ' . (string) $file['name']);
    rcr_notify_hr('📄 General application — ' . $name, $code . (trim((string) ($in['department_interest'] ?? '')) !== '' ? ' · ' . mb_substr(trim((string) $in['department_interest']), 0, 120) : ''), url('rcr_application', ['id' => $id]));
    return ['application_id' => $code, 'status' => 'received'];
}
/** HR moves an applicant (a general application, or one sent to the wrong vacancy) into the pipeline of a vacancy. The number does not change. */
function rcr_assign_vacancy(int $appId, int $vacId): void {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $a = rcr_application($appId); $v = rcr_vacancy($vacId); if (!$a || !$v) throw new RuntimeException(t('err.notfound'));
    if ((int) $a['vacancy_id'] === $vacId) return;
    if (one('SELECT id FROM rc_applications WHERE vacancy_id = ? AND email = ? AND id <> ?', [$vacId, (string) $a['email'], $appId])) throw new RuntimeException(t('rc.assign_dup'));
    q('UPDATE rc_applications SET vacancy_id = ?, status_changed_at = NOW() WHERE id = ?', [$vacId, $appId]);
    rcr_log('assigned', ['application_id' => $appId, 'vacancy_id' => $vacId], $a['vacancy_code'] . ' → ' . $v['code']);
}
/** R97-161: HR files a CV by hand (received by e-mail, at a fair, from a school or a recruiting partner). It becomes a general application (GEN-…, with the CV, evaluation,
 *  interviews, "assign to a vacancy") and an entry of the talent pool, kept for the retention of the pool. HR confirms that the candidate was informed and agreed. */
function rcr_manual_add(array $in, array $file): int {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $email = strtolower(trim((string) ($in['email'] ?? ''))); $name = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? ''))), 0, 120);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $name === '') throw new RuntimeException(t('rc.man_err_fields'));
    if (empty($in['informed'])) throw new RuntimeException(t('rc.man_err_consent'));
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || $file['size'] > (int) cp_setting('rc_cv_max_mb', '8') * 1048576) throw new RuntimeException(t('rc.man_err_file'));
    $mime = (string) mime_content_type($file['tmp_name']); $ext = ['application/pdf' => 'pdf', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx'][$mime] ?? null; if (!$ext) throw new RuntimeException(t('rc.man_err_file'));
    $v = rcr_general_vacancy(); if (!$v) throw new RuntimeException(t('err.notfound'));
    if (one('SELECT id FROM rc_applications WHERE vacancy_id = ? AND email = ?', [(int) $v['id'], $email])) throw new RuntimeException(t('rc.man_err_dup'));
    $u = current_user(); $area = mb_substr(trim((string) ($in['area'] ?? '')), 0, 120); $src = mb_substr(trim((string) ($in['source'] ?? '')), 0, 120); $note = mb_substr(trim((string) ($in['note'] ?? '')), 0, 3000);
    $months = max(1, (int) cp_setting('rc_pool_retention_months', '24')); $cover = trim(t('rc.man_cover', ['u' => (string) ($u['full_name'] ?? ''), 's' => $src !== '' ? $src : '—']) . ($note !== '' ? "\n" . $note : ''));
    $code = rcr_code('GEN', 'rc_applications'); $dest = rcr_dir() . '/' . $code . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dest)) throw new RuntimeException(t('rc.man_err_file'));
    try { q('INSERT INTO rc_applications (code, vacancy_id, email, name, phone, passed, knockout_ok, status, cv_path, cv_name, cover_note, department_interest, lang, source, user_agent, consent_version, consent_at, status_changed_at, keep_until, keep_reason, keep_by, pool_consent_at) VALUES (?,?,?,?,?,1,1,"cv_received",?,?,?,?,?,"hr",?,?,NOW(),NOW(),DATE_ADD(CURDATE(), INTERVAL ? MONTH),?,?,NOW())',
        [$code, (int) $v['id'], $email, $name, mb_substr(trim((string) ($in['phone'] ?? '')), 0, 40) ?: null, $dest, mb_substr(basename((string) $file['name']), 0, 190), $cover, $area ?: null, 'en', 'HR entry', 'hr-manual', $months, mb_substr(t('rc.man_keep'), 0, 190), (int) ($u['id'] ?? 0)]); }
    catch (Throwable $e) { @unlink($dest); if ($e instanceof PDOException && $e->getCode() === '23000') throw new RuntimeException(t('rc.man_err_dup')); throw $e; }
    $id = (int) db()->lastInsertId();
    if (!one('SELECT id FROM rc_talent_pool WHERE email = ?', [$email])) q('INSERT INTO rc_talent_pool (email, name, area, message, source, consent_at) VALUES (?,?,?,?,?,NOW())', [$email, $name, $area ?: null, $src !== '' ? $src : null, 'hr']);
    rcr_log('manual_cv', ['application_id' => $id, 'vacancy_id' => (int) $v['id']], 'Added by HR · ' . ($src !== '' ? $src : '—'));
    return $id;
}
function rcr_pub_register(string $evtCode, array $in, string $ip): void {
    $email = strtolower(trim((string) ($in['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['consent_gdpr'])) throw new RuntimeException('validation', 422);
    $e = one('SELECT e.*, (e.capacity - (SELECT COUNT(*) FROM rc_event_registrations r WHERE r.event_id = e.id)) AS seats_left FROM rc_events e WHERE e.code = ? AND e.status = "upcoming" AND e.publish_web = 1', [$evtCode]);
    if (!$e) throw new RuntimeException('closed', 410);
    if (one('SELECT id FROM rc_event_registrations WHERE event_id = ? AND email = ?', [(int) $e['id'], $email])) throw new RuntimeException('already registered', 409);   // before the capacity check: a repeat click is not "full"
    if (($e['capacity'] !== null && $e['seats_left'] <= 0) || ($e['registration_closes_at'] && strtotime((string) $e['registration_closes_at']) < time())) throw new RuntimeException('closed', 410);
    $appId = null; if (!empty($in['application_id'])) $appId = (int) (one('SELECT id FROM rc_applications WHERE code = ? AND email = ?', [(string) $in['application_id'], $email])['id'] ?? 0) ?: null;
    try { q('INSERT INTO rc_event_registrations (event_id, email, name, application_id) VALUES (?,?,?,?)', [(int) $e['id'], $email, mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, $appId]); }
    catch (PDOException $ex) { if ($ex->getCode() === '23000' && str_contains($ex->getMessage(), 'uq_rcr_evt_email')) throw new RuntimeException('already registered', 409); throw $ex; }
    rcr_log('event_registered', ['event_id' => (int) $e['id'], 'application_id' => $appId], $email);
    rcr_mail($email, 'Your registration: ' . $e['title'], "Dear " . ($in['name'] ?? 'candidate') . ",\n\nYou are registered for \"{$e['title']}\" on " . date('d M Y H:i', strtotime((string) $e['starts_at'])) . " ({$e['platform']}).\n" . ($e['join_url'] ? "Join link: {$e['join_url']}\n" : '') . "\nGolden Automation Systems — HR");
}
function rcr_pub_request(array $in): void {
    $email = strtolower(trim((string) ($in['email'] ?? ''))); if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($in['consent_gdpr'])) throw new RuntimeException('validation', 422);
    q('INSERT INTO rc_talent_pool (email, name, area, message, consent_at) VALUES (?,?,?,?,NOW())', [$email, mb_substr(trim((string) ($in['name'] ?? '')), 0, 120) ?: null, mb_substr(trim((string) ($in['area'] ?? '')), 0, 160) ?: null, mb_substr(trim((string) ($in['message'] ?? '')), 0, 4000) ?: null]);
    rcr_notify_hr('Talent pool — ' . ($in['name'] ?? $email), (string) ($in['area'] ?? ''), url('rcr_dashboard', ['tab' => 'pool']));
}

/* ------------------------------------------------------------------ dashboard & retention */
function rcr_dashboard_counts(): array {
    return ['open' => (int) scalar('SELECT COUNT(*) FROM rc_vacancies WHERE status = "open"'), 'new' => (int) scalar('SELECT COUNT(*) FROM rc_applications WHERE status IN ("screened","cv_received") AND created_at > NOW() - INTERVAL 7 DAY'),
        'active' => (int) scalar('SELECT COUNT(*) FROM rc_applications WHERE status IN ("shortlisted","interview","offer")'), 'events' => (int) scalar('SELECT COUNT(*) FROM rc_events WHERE status = "upcoming" AND starts_at > NOW()'),
        'pool' => (int) scalar('SELECT COUNT(*) FROM rc_talent_pool'), 'pass_rate' => (float) (scalar('SELECT ROUND(100 * AVG(passed), 0) FROM rc_applications WHERE created_at > NOW() - INTERVAL 90 DAY') ?? 0)];
}
/** GDPR retention — run daily (tools/cron.php). Counts only are logged. */
function rcr_retention_run(): array {
    if (!rcr_has()) return []; $months = max(1, (int) cp_setting('rc_retention_months', '6')); $cut = date('Y-m-d H:i:s', strtotime("-$months months")); $n = ['apps_deleted' => 0, 'apps_anonymised' => 0, 'cvs_deleted' => 0, 'events_deleted' => 0, 'pool_deleted' => 0];
    db()->beginTransaction();
    try {
        $rows = all('SELECT id, cv_path FROM rc_applications WHERE status IN ("screened","cv_received","rejected","withdrawn") AND COALESCE(status_changed_at, created_at) < ? AND (keep_until IS NULL OR keep_until < CURDATE())', [$cut]);   // R91-004: kept applications are skipped until keep_until passes
        foreach ($rows as $r) { if ($r['cv_path'] && is_file($r['cv_path'])) { @unlink($r['cv_path']); $n['cvs_deleted']++; } }
        if ($rows) $n['apps_deleted'] = rcr_app_erase_rows(array_column($rows, 'id'));   // R97-209: scorecards and interviews go with the application
        foreach (all('SELECT id, cv_path, cv_name, hired_person_id FROM rc_applications WHERE status = "hired" AND COALESCE(status_changed_at, created_at) < ? AND email NOT LIKE "anon-%"', [$cut]) as $r) {
            if ($r['cv_path'] && is_file($r['cv_path'])) { if (!rcr_cv_to_person((int) $r['id'])) @unlink($r['cv_path']); $n['cvs_deleted']++; }   // R91-004: the CV moves to the employee file instead of being destroyed
            q('UPDATE rc_applications SET email = CONCAT("anon-", code, "@retired.local"), name = NULL, phone = NULL, answers = NULL, cv_path = NULL, cv_name = NULL, cover_note = NULL, hr_notes = NULL, ip = NULL, user_agent = NULL WHERE id = ?', [(int) $r['id']]); $n['apps_anonymised']++;
            if (rcr_offer_has()) q('UPDATE rc_offers SET subject = NULL, body = NULL, outcome_note = NULL WHERE application_id = ?', [(int) $r['id']]); }   // R97-231: the e-mail text names the person; the terms stay as the record of the hire
        $n['events_deleted'] = q('DELETE r FROM rc_event_registrations r JOIN rc_events e ON e.id = r.event_id WHERE e.starts_at < ?', [$cut])->rowCount();
        $poolCut = date('Y-m-d H:i:s', strtotime('-' . max(1, (int) cp_setting('rc_pool_retention_months', '24')) . ' months'));   // R91-004: the pool is consent-based and has its own retention
        $n['pool_deleted'] = q('DELETE FROM rc_talent_pool WHERE created_at < ?', [$poolCut])->rowCount();
        q('INSERT INTO rc_retention_log (cutoff, apps_deleted, apps_anonymised, cvs_deleted, events_deleted, pool_deleted) VALUES (?,?,?,?,?,?)', [$cut, $n['apps_deleted'], $n['apps_anonymised'], $n['cvs_deleted'], $n['events_deleted'], $n['pool_deleted']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $n;
}

/* ------------------------------------------------------------------ deleting applications, CVs and talent-pool entries by hand (GAS-CP-R97-209) */
/** Rows of the given applications in every recruiting table. The caller holds the transaction and removes the CV files after the commit. */
function rcr_app_erase_rows(array $ids): int {
    $ids = array_values(array_filter(array_map('intval', $ids))); if (!$ids) return 0; $in = implode(',', $ids);
    q("DELETE FROM rc_event_registrations WHERE application_id IN ($in)"); q("DELETE FROM rc_log WHERE application_id IN ($in)"); if (rcr_notes_has()) q("DELETE FROM rc_notes WHERE application_id IN ($in)");   /* R97-266: notes are personal data of the candidate and go with the application */
    if (rcr_eval_has()) { q("DELETE FROM rc_evaluations WHERE application_id IN ($in)"); q("DELETE FROM rc_interviews WHERE application_id IN ($in)"); }
    if (rcr_offer_has()) q("DELETE FROM rc_offers WHERE application_id IN ($in)");   // R97-231: the offer is personal data of the candidate and goes with the application
    return q("DELETE FROM rc_applications WHERE id IN ($in)")->rowCount();
}
/** A CV file may be removed only when it lies in the portal's storage folder. */
function rcr_cv_unlink(?string $path): bool {
    if (!$path || !is_file($path)) return false; $real = realpath($path); $root = realpath(rcr_dir());
    if (!$real || !$root || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) return false;
    return @unlink($real);
}
/** HR deletes applications for good: record, answers, notes, scorecards, interviews, event links, history and CV file. The register keeps number, vacancy and reason only. */
function rcr_app_delete(array $ids, string $reason, bool $pool = false): array {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 200); if (!$ids) throw new RuntimeException(t('rc.del_none'));
    $reason = mb_substr(trim($reason), 0, 200); if (mb_strlen($reason) < 3) throw new RuntimeException(t('rc.del_need_reason'));
    $rows = all('SELECT a.id, a.code, a.email, a.status, a.cv_path, a.vacancy_id, v.code AS vacancy_code FROM rc_applications a JOIN rc_vacancies v ON v.id = a.vacancy_id WHERE a.id IN (' . implode(',', $ids) . ')');
    $n = ['apps' => 0, 'cvs' => 0, 'pool' => 0, 'skipped' => 0]; $del = []; $files = [];
    foreach ($rows as $r) { if ($r['status'] === 'hired') { $n['skipped']++; continue; } $del[] = $r; }   // a hired application belongs to the employee file; the retention run anonymises it
    if (!$del) throw new RuntimeException(t($n['skipped'] ? 'rc.del_hired' : 'rc.del_none'));
    db()->beginTransaction();
    try {
        foreach ($del as $r) {
            if ($r['cv_path']) $files[] = (string) $r['cv_path'];
            if ($pool && $r['email'] && !one('SELECT id FROM rc_applications WHERE email = ? AND id NOT IN (' . implode(',', array_column($del, 'id')) . ') LIMIT 1', [$r['email']])) $n['pool'] += q('DELETE FROM rc_talent_pool WHERE email = ?', [$r['email']])->rowCount();
            rcr_log('app_delete', ['vacancy_id' => (int) $r['vacancy_id']], $r['code'] . ' · ' . $r['vacancy_code'] . ' · ' . $reason);
        }
        $n['apps'] = rcr_app_erase_rows(array_column($del, 'id'));
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    foreach ($files as $f) if (rcr_cv_unlink($f)) $n['cvs']++;
    return $n;
}
/** Only the CV file of an application; the application itself stays. */
function rcr_cv_delete(int $id, string $reason): void {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $a = rcr_application($id); if (!$a || !$a['cv_path']) throw new RuntimeException(t('rc.del_none'));
    $reason = mb_substr(trim($reason), 0, 200); if (mb_strlen($reason) < 3) throw new RuntimeException(t('rc.del_need_reason'));
    q('UPDATE rc_applications SET cv_path = NULL, cv_name = NULL, cv_token_hash = NULL WHERE id = ?', [$id]);
    rcr_cv_unlink((string) $a['cv_path']);
    rcr_log('cv_delete', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], $reason);
    rcr_log('cv_delete', ['vacancy_id' => (int) $a['vacancy_id']], $a['code'] . ' · ' . $a['vacancy_code'] . ' · ' . $reason);   // register line that outlives the application
}
/** One talent-pool entry. An application of the same person, if any, is left alone. */
function rcr_pool_delete(int $pid): void {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    if (!q('DELETE FROM rc_talent_pool WHERE id = ?', [$pid])->rowCount()) throw new RuntimeException(t('rc.del_none'));
    rcr_log('pool_delete', [], 'entry #' . $pid);
}
/** Register of what was deleted by hand: number, vacancy, reason, who and when. No name, no e-mail address. */
function rcr_deleted_log(int $limit = 200): array {
    return all('SELECT l.action, l.detail, l.created_at, cu.full_name AS by_name FROM rc_log l LEFT JOIN customer_users cu ON cu.id = l.user_id WHERE l.application_id IS NULL AND l.action IN ("app_delete","cv_delete","pool_delete") ORDER BY l.id DESC LIMIT ' . max(1, min(500, $limit)));
}

/* ------------------------------------------------------------------ applicant evaluation (GAS-CP-R91-001) */
function rcr_eval_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'rc_evaluations'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Weighted criteria from Settings › Recruiting (JSON [{key,label,weight}]); weights are normalised to 100. */
function rcr_eval_criteria(): array {
    $c = json_decode((string) cp_setting('rc_eval_criteria', '[]'), true) ?: [];
    $c = array_values(array_filter($c, fn($x) => !empty($x['key']) && !empty($x['label']) && (float) ($x['weight'] ?? 0) > 0));
    $sum = array_sum(array_map(fn($x) => (float) $x['weight'], $c)) ?: 1;
    foreach ($c as &$x) { $x['key'] = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $x['key'])); $x['weight'] = round((float) $x['weight'] * 100 / $sum, 2); } unset($x);
    return $c;
}
function rcr_eval_criteria_save(array $rows): void {
    $out = []; foreach ($rows as $r) { $l = trim((string) ($r['label'] ?? '')); $w = (float) ($r['weight'] ?? 0); if ($l === '' || $w <= 0) continue; $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($r['key'] ?? ''))) ?: 'c' . (count($out) + 1); $out[] = ['key' => $k, 'label' => mb_substr($l, 0, 120), 'weight' => $w]; }
    if (!$out) throw new RuntimeException(t('rc.ev_need_criteria'));
    cp_setting_set('rc_eval_criteria', json_encode($out, JSON_UNESCAPED_UNICODE));
}
/** Weighted total 0–100 from 1–5 scores. */
function rcr_eval_total(array $scores): float { $t = 0.0; foreach (rcr_eval_criteria() as $c) { $v = (int) ($scores[$c['key']] ?? 0); if ($v >= 1 && $v <= 5) $t += ($v / 5) * $c['weight']; } return round($t, 2); }
function rcr_evaluations(int $appId): array { return rcr_eval_has() ? all('SELECT e.*, cu.full_name AS by_name FROM rc_evaluations e LEFT JOIN customer_users cu ON cu.id = e.evaluator_id WHERE e.application_id = ? ORDER BY e.created_at DESC', [$appId]) : []; }
function rcr_eval_save(int $appId, array $in): int {
    $a = rcr_application($appId); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $scores = []; $missing = [];
    foreach (rcr_eval_criteria() as $c) { $v = (int) ($in['score'][$c['key']] ?? 0); if ($v < 1 || $v > 5) $missing[] = $c['label']; else $scores[$c['key']] = $v; }
    if ($missing) throw new RuntimeException(t('rc.ev_score_all'));
    $stage = in_array($in['stage'] ?? '', RCR_EVAL_STAGE, true) ? $in['stage'] : 'technical'; $reco = in_array($in['recommendation'] ?? '', RCR_EVAL_RECO, true) ? $in['recommendation'] : 'hold';
    q('INSERT INTO rc_evaluations (application_id, stage, evaluator_id, evaluator_name, scores, total, recommendation, strengths, concerns, notes) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$appId, $stage, (int) $u['id'], (string) $u['full_name'], json_encode($scores), rcr_eval_total($scores), $reco, mb_substr(trim((string) ($in['strengths'] ?? '')), 0, 2000) ?: null, mb_substr(trim((string) ($in['concerns'] ?? '')), 0, 2000) ?: null, mb_substr(trim((string) ($in['notes'] ?? '')), 0, 4000) ?: null]);
    $id = (int) db()->lastInsertId();
    rcr_eval_refresh($appId); rcr_log('evaluation', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], t('rc.ev_stage_' . $stage) . ' · ' . t('rc.ev_reco_' . $reco));
    return $id;
}
function rcr_eval_delete(int $evalId): void {
    $e = one('SELECT * FROM rc_evaluations WHERE id = ?', [$evalId]); if (!$e) return; $u = current_user();
    if ((int) $e['evaluator_id'] !== (int) $u['id'] && !is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM rc_evaluations WHERE id = ?', [$evalId]); rcr_eval_refresh((int) $e['application_id']); rcr_log('evaluation_deleted', ['application_id' => (int) $e['application_id']], (string) $e['stage']);
}
function rcr_eval_refresh(int $appId): void { $r = one('SELECT AVG(total) a, COUNT(*) n FROM rc_evaluations WHERE application_id = ?', [$appId]); q('UPDATE rc_applications SET eval_avg = ?, eval_count = ? WHERE id = ?', [$r && $r['n'] ? round((float) $r['a'], 2) : null, (int) ($r['n'] ?? 0), $appId]); }
/** Per-criterion averages and the recommendation mix for one application. */
function rcr_eval_summary(array $evals): array {
    $crit = rcr_eval_criteria(); $sum = []; $n = count($evals); $reco = array_fill_keys(RCR_EVAL_RECO, 0);
    foreach ($evals as $e) { $sc = json_decode((string) $e['scores'], true) ?: []; foreach ($crit as $c) $sum[$c['key']] = ($sum[$c['key']] ?? 0) + (int) ($sc[$c['key']] ?? 0); $reco[$e['recommendation']] = ($reco[$e['recommendation']] ?? 0) + 1; }
    $avg = []; foreach ($crit as $c) $avg[$c['key']] = $n ? round($sum[$c['key']] / $n, 1) : null;
    return ['n' => $n, 'avg' => $avg, 'total' => $n ? round(array_sum(array_map(fn($e) => (float) $e['total'], $evals)) / $n, 1) : null, 'reco' => $reco];
}
/** Candidates of a vacancy ranked: evaluation average first, then screening score. */
function rcr_vacancy_ranking(int $vacancyId): array {
    return all("SELECT a.id, a.code, a.name, a.email, a.status, a.score, a.max_score, a.passed, a.rating, a.eval_avg, a.eval_count, a.cv_path IS NOT NULL AS has_cv,
                       (SELECT GROUP_CONCAT(DISTINCT e.recommendation) FROM rc_evaluations e WHERE e.application_id = a.id) AS recos
                FROM rc_applications a WHERE a.vacancy_id = ? AND a.status NOT IN ('withdrawn') ORDER BY (a.eval_avg IS NULL), a.eval_avg DESC, a.passed DESC, a.score DESC, a.created_at", [$vacancyId]);
}
/* interviews */
function rcr_interviews(int $appId): array { return rcr_eval_has() ? all('SELECT * FROM rc_interviews WHERE application_id = ? ORDER BY scheduled_at DESC', [$appId]) : []; }
function rcr_interview_save(int $appId, array $in): int {
    $a = rcr_application($appId); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess'));
    $when = trim((string) ($in['scheduled_at'] ?? '')); if (!$when || !strtotime($when)) throw new RuntimeException(t('rc.ev_need_date'));
    $stage = in_array($in['stage'] ?? '', RCR_INT_STAGE, true) ? $in['stage'] : 'technical'; $mode = in_array($in['mode'] ?? '', ['online', 'onsite', 'phone'], true) ? $in['mode'] : 'online';
    q('INSERT INTO rc_interviews (application_id, stage, scheduled_at, duration_min, mode, location, interviewers, created_by) VALUES (?,?,?,?,?,?,?,?)',
      [$appId, $stage, date('Y-m-d H:i:s', strtotime($when)), max(15, min(480, (int) ($in['duration_min'] ?? 60))), $mode, mb_substr(trim((string) ($in['location'] ?? '')), 0, 200) ?: null, mb_substr(trim((string) ($in['interviewers'] ?? '')), 0, 300) ?: null, (int) current_user()['id']]);
    $id = (int) db()->lastInsertId();
    if ($a['status'] !== 'interview' && !in_array($a['status'], ['offer', 'hired', 'rejected', 'withdrawn'], true)) { q("UPDATE rc_applications SET status = 'interview', status_changed_at = NOW() WHERE id = ?", [$appId]); rcr_log('status', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], $a['status'] . ' → interview'); }
    rcr_log('interview_planned', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], t('rc.ev_stage_' . $stage) . ' · ' . date('d/m/Y H:i', strtotime($when)));
    if (!empty($in['notify'])) { $d = rcr_interview_draft($id); rcr_interview_send($id, $d['subject'], $d['body']); }   // R97-229: the invitation is the e-mail template «Recruiting — interview invitation»
    return $id;
}
function rcr_interview_status(int $id, string $st): void { if (!in_array($st, ['planned', 'done', 'no_show', 'cancelled'], true)) return; $i = one('SELECT * FROM rc_interviews WHERE id = ?', [$id]); if (!$i) return; $a = rcr_application((int) $i['application_id']); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess')); q('UPDATE rc_interviews SET status = ? WHERE id = ?', [$st, $id]); rcr_log('interview_' . $st, ['application_id' => (int) $i['application_id']], (string) $i['stage']); }

/* ---- R97-229: an interview can be changed while it is planned, and its invitation goes out through the portal from the e-mail template ---- */
/** The time of an interview as it was typed (Athens wall-clock time) — never shifted by the zone of the database. */
function rcr_wall(string $d): string { $ts = strtotime($d); return $ts ? fmt_date(date('Y-m-d', $ts)) . ' ' . date('H:i', $ts) : '—'; }
/** One interview with its application, for a person who may see the vacancy. */
function rcr_interview(int $id): ?array {
    if (!rcr_eval_has()) return null; $i = one('SELECT * FROM rc_interviews WHERE id = ?', [$id]); if (!$i) return null;
    $a = rcr_application((int) $i['application_id']); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) return null; $i['app'] = $a; return $i;
}
function rcr_interview_update(int $id, array $in): void {
    $i = rcr_interview($id); if (!$i) throw new RuntimeException(t('err.noaccess')); if ($i['status'] !== 'planned') throw new RuntimeException(t('rc.ev_only_planned'));
    $when = trim((string) ($in['scheduled_at'] ?? '')); if (!$when || !strtotime($when)) throw new RuntimeException(t('rc.ev_need_date')); $when = date('Y-m-d H:i:s', strtotime($when));
    $stage = in_array($in['stage'] ?? '', RCR_INT_STAGE, true) ? $in['stage'] : $i['stage']; $mode = in_array($in['mode'] ?? '', ['online', 'onsite', 'phone'], true) ? $in['mode'] : $i['mode'];
    $dur = max(15, min(480, (int) ($in['duration_min'] ?? $i['duration_min']))); $loc = mb_substr(trim((string) ($in['location'] ?? '')), 0, 200) ?: null; $who = mb_substr(trim((string) ($in['interviewers'] ?? '')), 0, 300) ?: null;
    $told = $when !== (string) $i['scheduled_at'] || $mode !== $i['mode'] || $loc !== $i['location'] || $dur !== (int) $i['duration_min'] || $stage !== $i['stage'];   // what the candidate was told has changed: the invitation must go again
    q('UPDATE rc_interviews SET stage = ?, scheduled_at = ?, duration_min = ?, mode = ?, location = ?, interviewers = ?' . ($told ? ', candidate_notified_at = NULL' : '') . ' WHERE id = ?', [$stage, $when, $dur, $mode, $loc, $who, $id]);
    $ch = []; if ($when !== (string) $i['scheduled_at']) $ch[] = date('d/m/Y H:i', strtotime((string) $i['scheduled_at'])) . ' → ' . date('d/m/Y H:i', strtotime($when)); if ($stage !== $i['stage']) $ch[] = t('rc.ev_stage_' . $stage); if ($mode !== $i['mode']) $ch[] = t('rc.ev_mode_' . $mode); if ($dur !== (int) $i['duration_min']) $ch[] = $dur . ' min';
    rcr_log('interview_changed', ['application_id' => (int) $i['application_id'], 'vacancy_id' => (int) $i['app']['vacancy_id']], mb_substr(implode(' · ', $ch) ?: t('rc.ev_interviewers'), 0, 250));
    if (!empty($in['notify'])) { $d = rcr_interview_draft($id); rcr_interview_send($id, $d['subject'], $d['body']); }
}
/** The invitation of an interview from the template «Recruiting — interview invitation», in the language of the candidate, with the interview filled in. */
function rcr_interview_draft(int $id): array {
    $i = rcr_interview($id); if (!$i) throw new RuntimeException(t('err.noaccess')); $a = $i['app']; $u = current_user();
    $lc = in_array((string) ($a['lang'] ?? ''), array_keys(LOCALES), true) ? (string) $a['lang'] : 'en'; $tr = fn(string $k) => t_in($k, $lc);
    $when = date('d/m/Y H:i', strtotime((string) $i['scheduled_at'])) . ' (' . $tr('rc.ev_athens') . ')'; $mode = $tr('rc.ev_mode_' . $i['mode']) . ($i['location'] ? ' · ' . $i['location'] : ''); $dur = (int) $i['duration_min'] . ' min';
    $det = $tr('rc.ev_stage') . ': ' . $tr('rc.ev_stage_' . $i['stage']) . "\n" . $tr('rc.ev_when') . ': ' . $when . "\n" . $tr('rc.ev_duration') . ': ' . (int) $i['duration_min'] . "\n" . $tr('rc.ev_mode') . ': ' . $mode . ($i['interviewers'] ? "\n" . $tr('rc.ev_interviewers') . ': ' . $i['interviewers'] : '');
    $branch = ''; try { if (!empty($u['branch_id']) && function_exists('br_get')) $branch = (string) (br_get((int) $u['branch_id'])['name'] ?? ''); } catch (Throwable $e) {}
    $r = function_exists('et_render') ? et_render('rc_interview', ['no' => (string) $a['code'], 'title' => (string) $a['vacancy'], 'doc' => (string) $a['vacancy_code'], 'name' => (string) ($a['name'] ?: $a['email']), 'email' => (string) $a['email'], 'user' => (string) $u['full_name'], 'branch' => $branch ?: 'Golden A/S',
            'when' => $when, 'duration' => $dur, 'stage' => $tr('rc.ev_stage_' . $i['stage']), 'mode' => $mode, 'interviewers' => (string) $i['interviewers'], 'locale' => $lc, 'branch_id' => $u['branch_id'] ?? null, '_details' => $det])
        : ['subject' => 'Golden A/S — ' . $tr('rc.ev_interview') . ' · ' . $a['vacancy_code'], 'body' => ($a['name'] ?: $a['email']) . ",\n\n" . $tr('rc.ev_mail_intro') . "\n\n" . $det . "\n\n" . $tr('rc.ev_mail_outro') . "\n\nGolden A/S — HR"];
    return ['to' => (string) $a['email'], 'subject' => (string) $r['subject'], 'body' => (string) $r['body'], 'locale' => $lc];
}
/** Sends the invitation (the text HR sees and may have changed) to the candidate through the portal. True when it left; false when no sender is set up — then it is kept in the recruiting log. */
function rcr_interview_send(int $id, string $subject, string $body): bool {
    $i = rcr_interview($id); if (!$i) throw new RuntimeException(t('err.noaccess')); if ($i['status'] !== 'planned') throw new RuntimeException(t('rc.ev_only_planned')); $a = $i['app'];
    $subject = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $subject)), 0, 200); $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 8000);
    if ($subject === '' || mb_strlen($body) < 20) throw new RuntimeException(t('rc.ev_need_text'));
    if (!filter_var((string) $a['email'], FILTER_VALIDATE_EMAIL) || str_starts_with((string) $a['email'], 'anon-')) throw new RuntimeException(t('rc.ev_no_email'));
    if ((int) scalar("SELECT COUNT(*) FROM rc_log WHERE action = 'interview_invited' AND application_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE", [(int) $i['application_id']]) >= 3) throw new RuntimeException(t('rc.ev_too_many'));
    $ok = rcr_mail((string) $a['email'], $subject, $body);
    if ($ok) q('UPDATE rc_interviews SET candidate_notified_at = NOW() WHERE id = ?', [$id]);
    rcr_log('interview_invited', ['application_id' => (int) $i['application_id'], 'vacancy_id' => (int) $a['vacancy_id']], ($ok ? '✉ ' : '… ') . date('d/m/Y H:i', strtotime((string) $i['scheduled_at'])));
    return $ok;
}

/** R97-247: the invitation left through the standard e-mail card — marked on the interview and in the log when the candidate is among the recipients. */
function rcr_interview_mailed(int $id, array $to, string $how): void {
    $i = rcr_interview($id); if (!$i || $i['status'] !== 'planned') return; $a = $i['app']; if (!in_array(strtolower(trim((string) $a['email'])), array_map('strtolower', $to), true)) return;
    q('UPDATE rc_interviews SET candidate_notified_at = NOW() WHERE id = ?', [$id]);
    rcr_log('interview_invited', ['application_id' => (int) $i['application_id'], 'vacancy_id' => (int) $a['vacancy_id']], '✉ ' . date('d/m/Y H:i', strtotime((string) $i['scheduled_at'])) . ' — ' . t('rc.of_how_' . (in_array($how, ['sent', 'outlook', 'file'], true) ? $how : 'sent')));
}

/* ---- R92-001: the assistant drafts a vacancy from a short brief (title, summary, description, requirements, tags, location, type, seniority, department, branch) ---- */
function rcr_vacancy_ai_fields(array $cur): array {
    $sel = fn(array $rows, string $k, string $lbl) => array_map(fn($r) => ['value' => (string)$r[$k], 'label' => (string)$r[$lbl]], $rows);
    $depts = function_exists('hr_departments') ? hr_departments() : []; $brs = function_exists('br_all') ? br_all(true) : [];
    $f = fn(string $n, string $label, string $type, string $val, array $opts = []) => ['name' => $n, 'label' => $label, 'type' => $type, 'value' => mb_substr($val, 0, 600), 'options' => $opts];
    return [
        $f('title', t('rc.job_title'), 'text', (string)($cur['title'] ?? '')),
        $f('department_id', t('hr.department'), 'select', (string)($cur['department_id'] ?? ''), $sel($depts, 'id', 'name')),
        $f('branch_id', t('rc.branch'), 'select', (string)($cur['branch_id'] ?? ''), $sel($brs, 'id', 'name')),
        $f('location', t('rc.location') . ' (city, country code)', 'text', (string)($cur['location'] ?? '')),
        $f('emp_type', t('rc.emp_type'), 'select', (string)($cur['emp_type'] ?? ''), array_map(fn($t) => ['value' => $t, 'label' => t('rc.' . $t)], RCR_EMP_TYPE)),
        $f('seniority', t('rc.seniority'), 'select', (string)($cur['seniority'] ?? ''), array_map(fn($t) => ['value' => $t, 'label' => t('rc.' . $t)], RCR_SENIORITY)),
        $f('summary', t('rc.summary') . ' (max 500 characters, one paragraph, shown on the careers page card)', 'textarea', (string)($cur['summary'] ?? '')),
        $f('description_html', t('rc.description') . ' (plain text, 3-5 short paragraphs: the role, the projects and systems, the team, what we offer; no HTML, no headings)', 'textarea', (string)($cur['description_html'] ?? '')),
        $f('requirements', t('rc.requirements') . ' (5-8 items, one per line, must-haves first)', 'textarea', (string)($cur['requirements'] ?? '')),
        $f('tags', t('rc.tags') . ' (4-8 short keywords, comma separated)', 'textarea', (string)($cur['tags'] ?? '')),
    ];
}
/** Run the draft; the suggestion is kept in the session and pre-fills the form until saved or discarded. Only the form's own fields leave the portal — no candidate or personal data. */
function rcr_vacancy_ai_draft(int $id, string $brief, array $form): array {
    if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
    $cur = array_intersect_key($form, array_flip(['title', 'department_id', 'branch_id', 'location', 'emp_type', 'seniority', 'summary', 'description_html', 'requirements', 'tags']));
    $prompt = ($brief !== '' ? $brief . "\n" : '') . "Draft this job vacancy for Golden A/S (marine electrical, automation and navigation systems integrator; superyacht newbuilding and refit; head office Keratsini / Piraeus, branches in Italy and the Netherlands; engineers work at shipyards across Europe). "
        . "Use the brief and the current form values; keep what is already filled unless the brief changes it. Write in British English, factual, no marketing superlatives, no emojis. The audience is engineers and technicians; mention the real systems of the trade (PLC/SCADA, IAS/AMS, switchboards, navigation and communication equipment, class rules) only when they fit the role. "
        . "Pick department_id, branch_id, emp_type and seniority from the listed options when the brief implies them; otherwise leave them as they are. Do not invent salary, dates or names.";
    $r = ai_run('fill', ['title' => t('rc.new_vacancy'), 'headline' => t('rc.ai_hint'), 'fields' => rcr_vacancy_ai_fields($cur)], $prompt, null, 'rcr_vacancy');
    if ($r['ok']) {
        $f = $r['fields']; if (isset($f['description_html'])) $f['description_html'] = strip_tags((string)$f['description_html']); if (isset($f['summary'])) $f['summary'] = mb_substr(strip_tags((string)$f['summary']), 0, 600);
        foreach (['department_id', 'branch_id'] as $k) if (isset($f[$k]) && !ctype_digit((string)$f[$k])) unset($f[$k]);
        if (isset($f['emp_type']) && !in_array($f['emp_type'], RCR_EMP_TYPE, true)) unset($f['emp_type']); if (isset($f['seniority']) && !in_array($f['seniority'], RCR_SENIORITY, true)) unset($f['seniority']);
        $_SESSION['rc_vac_ai'][$id] = ['fields' => $f, 'notes' => (string)($r['notes'] ?? ''), 'at' => time()];
    }
    return $r;
}

/* ------------------------------------------------------------------ CV retention controls (GAS-CP-R91-004) */
/** Keep an application (and its CV) beyond the standard retention: candidate consent to stay available, or an ongoing process. */
function rcr_keep(int $id, int $months, string $reason, bool $poolConsent): void {
    $a = rcr_application($id); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess'));
    $months = in_array($months, [0, 6, 12, 24, 36], true) ? $months : 12; $reason = mb_substr(trim($reason), 0, 200);
    if ($months > 0 && $reason === '') throw new RuntimeException(t('rc.keep_need_reason'));
    if ($months === 0) { q('UPDATE rc_applications SET keep_until = NULL, keep_reason = NULL, keep_by = NULL WHERE id = ?', [$id]); rcr_log('keep_cleared', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], ''); return; }
    q('UPDATE rc_applications SET keep_until = DATE_ADD(CURDATE(), INTERVAL ? MONTH), keep_reason = ?, keep_by = ?, pool_consent_at = IF(?, COALESCE(pool_consent_at, NOW()), pool_consent_at) WHERE id = ?', [$months, $reason, (int) current_user()['id'], $poolConsent ? 1 : 0, $id]);
    if ($poolConsent && $a['email'] && !one('SELECT id FROM rc_talent_pool WHERE email = ?', [$a['email']])) q('INSERT INTO rc_talent_pool (email, name, area, message, consent_at) VALUES (?,?,?,?,NOW())', [$a['email'], $a['name'], (string) ($a['department_interest'] ?? ''), 'From application ' . $a['code']]);
    rcr_log('keep', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], $months . ' months · ' . $reason . ($poolConsent ? ' · talent pool consent' : ''));
}
/** Copies the application CV into the hired person's HR documents (kind "hire") so it survives the recruiting retention. */
function rcr_cv_to_person(int $appId): bool {
    $a = rcr_application($appId); if (!$a || !$a['hired_person_id'] || !$a['cv_path'] || !is_file($a['cv_path'])) return false;
    try { if (!(bool) scalar("SHOW TABLES LIKE 'hr_docs'")) return false;
        if (one('SELECT id FROM hr_docs WHERE person_id = ? AND note = ?', [(int) $a['hired_person_id'], 'CV ' . $a['code']])) return true;
        $pid = (int) $a['hired_person_id']; $dir = dirname(__DIR__) . '/storage/hr/people/' . $pid; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $ext = strtolower(pathinfo($a['cv_path'], PATHINFO_EXTENSION)) ?: 'pdf'; $fn = bin2hex(random_bytes(12)) . '.' . $ext; if (!copy($a['cv_path'], $dir . '/' . $fn)) return false;
        q('INSERT INTO hr_docs (person_id, employment_id, kind, name, path, sha, size, note, uploaded_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, null, 'hire', mb_substr((string) ($a['cv_name'] ?: 'CV.' . $ext), 0, 160), 'people/' . $pid . '/' . $fn, hash_file('sha256', $dir . '/' . $fn), filesize($dir . '/' . $fn), 'CV ' . $a['code'], (int) (current_user()['id'] ?? 0) ?: null]);
        rcr_log('cv_to_person', ['application_id' => $appId], 'hr_docs for person #' . $pid); return true;
    } catch (Throwable $e) { error_log('[rcr] cv_to_person: ' . $e->getMessage()); return false; }
}

/* ---- R92-008: ask the careers website to drop its 5-minute read cache after a vacancy / event change. Same shared key as the feed (X-Site-Key); 2 s budget, never blocks or fails the save. ---- */
function rcr_site_purge(): void {
    $origin = rtrim((string) cp_setting('rc_site_origin', ''), '/'); $key = (string) cp_setting('rc_site_key', '');
    if ($origin === '' || $key === '' || !preg_match('~^https://[a-z0-9.-]+$|^http://127\.0\.0\.1(:[0-9]+)?$~i', $origin) || !function_exists('curl_init')) return;
    $code = 0;
    try {
        $post = function (string $base) use ($key): array {
            $ch = curl_init($base . '/api/hr.php?r=purge'); $loc = '';
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_HTTPHEADER => ['X-Site-Key: ' . $key, 'Content-Length: 0'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$loc) { if (stripos($h, 'Location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); }]);
            curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); if (function_exists('perf_out')) perf_out($ch, 'Website careers'); curl_close($ch);
            return [$code, $loc];
        };
        [$code, $loc] = $post($origin);
        /* R97-211: the website answers its bare address with a redirect to www (or back); the purge then never arrived. One retry, only to the same site over https. */
        if (in_array($code, [301, 302, 307, 308], true) && $loc !== '') {
            $h0 = strtolower((string) parse_url($origin, PHP_URL_HOST)); $h1 = strtolower((string) parse_url($loc, PHP_URL_HOST));
            if (parse_url($loc, PHP_URL_SCHEME) === 'https' && $h1 !== '' && ($h1 === 'www.' . $h0 || 'www.' . $h1 === $h0)) [$code] = $post('https://' . $h1);
        }
    } catch (Throwable $e) {}
    try { cp_setting_set('rc_site_purge_last', date('Y-m-d H:i:s') . '|' . $code); } catch (Throwable $e) {}
}

/* ================================================================== R97-231: the offer to a candidate
 * Financial proposal and terms with the job description, written by HR, kept as a record (rc_offers) and sent by the portal from the
 * e-mail template «Recruiting — offer». Only HR sees or writes offers: the net salary never appears in logs, notifications or to the hiring manager. */
const RCR_OFFER_STATUS = ['draft', 'sent', 'accepted', 'declined', 'withdrawn'];
const RCR_OFFER_CONTRACT = ['indefinite', 'fixed', 'project', 'internship'];
function rcr_offer_has(): bool { static $h = null; if ($h === null) { try { $h = rcr_has() && (bool) scalar("SHOW TABLES LIKE 'rc_offers'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rcr_offer_can(): bool { return rcr_offer_has() && rcr_can(); }
function rcr_offers(int $appId): array { return rcr_offer_can() ? all('SELECT * FROM rc_offers WHERE application_id = ? ORDER BY id DESC', [$appId]) : []; }
function rcr_offer(int $id): ?array { if (!rcr_offer_can()) return null; $o = one('SELECT * FROM rc_offers WHERE id = ?', [$id]); if (!$o) return null; $a = rcr_application((int) $o['application_id']); if (!$a) return null; $o['app'] = $a; return $o; }
/* ---- R97-247: second approval. An offer leaves (e-mail card, acceptance link) only after the CEO, the General Manager or the Managing Director approved its terms;
 *      the approver is never the person who asked. An offer prepared by the CEO needs no second person. Saving the terms again takes the approval away. ---- */
function rcr_offer_appr_has(): bool { static $h = null; if ($h === null) { try { $h = rcr_offer_has() && (bool) scalar("SHOW COLUMNS FROM rc_offers LIKE 'appr_status'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rcr_offer_approver(): bool { return rcr_offer_appr_has() && is_staff() && function_exists('mgmt_can') && mgmt_can(); }
function rcr_offer_approver_ids(): array { try { return array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role <> 'customer' AND (r.scope = 'owner' OR LOWER(TRIM(r.name)) IN ('ceo', 'general manager', 'managing director'))"), 'id')); } catch (Throwable $e) { return []; } }
/** R97-248: an approval holds until the validity date of the offer; after it the terms are approved again (with a new validity). */
function rcr_offer_valid_passed(array $o): bool { return !empty($o['valid_until']) && (string) $o['valid_until'] < date('Y-m-d'); }
function rcr_offer_appr_expired(array $o): bool { return rcr_offer_appr_has() && ($o['appr_status'] ?? null) === 'approved' && rcr_offer_valid_passed($o); }
function rcr_offer_approved(array $o): bool { return !rcr_offer_appr_has() || (($o['appr_status'] ?? null) === 'approved' && !rcr_offer_valid_passed($o)); }
/** HR asks for the approval of a draft with all its terms. */
function rcr_offer_ask(int $id): string {
    $o = rcr_offer($id); if (!$o || !rcr_offer_appr_has()) throw new RuntimeException(t('err.noaccess')); if ($o['status'] !== 'draft') throw new RuntimeException(t('rc.of_only_draft'));
    if ($m = rcr_offer_missing($o)) throw new RuntimeException(t('rc.of_missing', ['what' => implode(', ', $m)])); if (($o['appr_status'] ?? '') === 'pending') throw new RuntimeException(t('rc.ap_already'));
    if (rcr_offer_valid_passed($o)) throw new RuntimeException(t('rc.ap_valid_passed'));   // R97-248
    $u = current_user(); $me = (int) $u['id']; $ctx = ['application_id' => (int) $o['application_id'], 'vacancy_id' => (int) $o['app']['vacancy_id']];
    if (function_exists('hr_owner') && hr_owner()) {   // the CEO prepared it
        q("UPDATE rc_offers SET appr_status = 'approved', appr_req_by = ?, appr_req_name = ?, appr_req_at = NOW(), appr_by = ?, appr_name = ?, appr_at = NOW(), appr_note = NULL WHERE id = ?", [$me, (string) $u['full_name'], $me, (string) $u['full_name'], $id]);
        rcr_log('offer_approved', $ctx, (string) $o['offer_no']); return 'approved';
    }
    $to = array_values(array_filter(rcr_offer_approver_ids(), fn($x) => $x !== $me)); if (!$to) throw new RuntimeException(t('rc.ap_nobody'));
    q("UPDATE rc_offers SET appr_status = 'pending', appr_req_by = ?, appr_req_name = ?, appr_req_at = NOW(), appr_by = NULL, appr_name = NULL, appr_at = NULL, appr_note = NULL WHERE id = ?", [$me, (string) $u['full_name'], $id]);
    rcr_log('offer_approval_asked', $ctx, (string) $o['offer_no']);
    if (function_exists('nf_push')) nf_push($to, 'rc:offer_appr', 'rc:offer_appr:' . $id, t('rc.ap_nf_ask', ['no' => (string) $o['offer_no']]), (string) $o['position'], url('rcr_offer_review', ['id' => $id]), $me, (string) $u['full_name']);
    return 'pending';
}
/** The offer as the approver reads it — without the recruiting right: the terms, the candidate by name, nothing else of the application. */
function rcr_offer_for_approver(int $id): ?array {
    if (!rcr_offer_approver()) return null; $o = one('SELECT o.*, a.name AS cand_name, a.code AS app_code, a.lang AS cand_lang, v.title AS vacancy, v.code AS vacancy_code FROM rc_offers o JOIN rc_applications a ON a.id = o.application_id LEFT JOIN rc_vacancies v ON v.id = a.vacancy_id WHERE o.id = ?', [$id]);
    return $o && !empty($o['appr_status']) ? $o : null;
}
function rcr_offers_to_approve(): array {
    if (!rcr_offer_approver()) return []; $me = (int) (current_user()['id'] ?? 0);
    return all("SELECT o.id, o.offer_no, o.position, o.appr_req_name, o.appr_req_at, a.name AS cand_name FROM rc_offers o JOIN rc_applications a ON a.id = o.application_id WHERE o.status = 'draft' AND o.appr_status = 'pending' AND (o.appr_req_by IS NULL OR o.appr_req_by <> ?) ORDER BY o.appr_req_at", [$me]);
}
/** CEO / General Manager / Managing Director: approve, or send back with the reason. */
function rcr_offer_decide(int $id, bool $approve, string $note): void {
    $o = rcr_offer_for_approver($id); $u = current_user(); if (!$o || !$u) throw new RuntimeException(t('err.noaccess'));
    if ($o['status'] !== 'draft' || $o['appr_status'] !== 'pending') throw new RuntimeException(t('rc.ap_not_waiting'));
    if ((int) $o['appr_req_by'] === (int) $u['id']) throw new RuntimeException(t('rc.ap_not_own'));
    $note = mb_substr(trim($note), 0, 300); if (!$approve && mb_strlen($note) < 3) throw new RuntimeException(t('rc.ap_need_note'));
    if ($approve && rcr_offer_valid_passed($o)) throw new RuntimeException(t('rc.ap_valid_passed'));   // R97-248: nothing is approved after its validity; send it back instead
    if ($approve && function_exists('pk_stepup_ok') && !pk_stepup_ok($u, trim((string)($_POST['totp'] ?? '')))) throw new RuntimeException(t('po.pg_totp'));   // R97-248: the approver confirms with the authenticator code
    $n = q("UPDATE rc_offers SET appr_status = ?, appr_by = ?, appr_name = ?, appr_at = NOW(), appr_note = ? WHERE id = ? AND status = 'draft' AND appr_status = 'pending'", [$approve ? 'approved' : 'rejected', (int) $u['id'], (string) $u['full_name'], $note ?: null, $id])->rowCount();
    if (!$n) throw new RuntimeException(t('rc.ap_not_waiting'));
    q('INSERT INTO rc_log (application_id, vacancy_id, action, detail, user_id) VALUES (?,?,?,?,?)', [(int) $o['application_id'], null, $approve ? 'offer_approved' : 'offer_sent_back', mb_substr((string) $o['offer_no'] . ' — ' . (string) $u['full_name'] . ($note !== '' ? ' — ' . $note : ''), 0, 500), (int) $u['id']]);
    if (function_exists('nf_push') && (int) $o['appr_req_by']) nf_push([(int) $o['appr_req_by']], 'rc:offer_appr', 'rc:offer_appr:' . $id . ':d', t($approve ? 'rc.ap_nf_ok' : 'rc.ap_nf_back', ['no' => (string) $o['offer_no']]), $note ?: null, url('rcr_application', ['id' => (int) $o['application_id']]) . '#offer', (int) $u['id'], (string) $u['full_name']);
}
function rcr_offer_pill(string $s): string { return pill(['draft' => 'mute', 'sent' => 'gold', 'accepted' => 'green', 'declined' => 'bad', 'withdrawn' => 'mute'][$s] ?? 'mute', t('rc.of_st_' . $s)); }
/** "1.850,00 EUR net per month × 14 payments per year" in the given language. */
function rcr_offer_salary_text(array $o, string $lc): string {
    if ($o['net_salary'] === null) return ''; $tr = fn(string $k, array $v = []) => t_in($k, $lc, $v);
    $s = number_format((float) $o['net_salary'], 2, ',', '.') . ' ' . $o['currency'] . ' ' . $tr('rc.of_net_' . ($o['salary_per'] === 'year' ? 'year' : 'month'));
    if ($o['salary_per'] !== 'year' && (int) $o['payments_per_year'] > 0) $s .= ' × ' . $tr('rc.of_payments', ['n' => (int) $o['payments_per_year']]);
    return $s;
}
/** The job description as plain text for the offer: the approved job description linked to the vacancy, otherwise the text of the vacancy. */
function rcr_offer_jd_text(array $a, string $lc = 'en', ?int $pick = null): array {
    $v = one('SELECT * FROM rc_vacancies WHERE id = ?', [(int) $a['vacancy_id']]); $tr = fn(string $k) => t_in($k, $lc); $out = []; $jdId = null; $title = null;
    $use = $pick ?: ($v && !empty($v['jd_id']) ? (int) $v['jd_id'] : 0);   // R97-264: another approved job description can be chosen for the offer
    if ($use && function_exists('jd_has') && jd_has() && ($jd = jd_load($use)) && ($c = jd_current($jd))) {
        $jdId = (int) $jd['id']; $title = (string) $c['title']; $out[] = $c['title'] . ' (' . $c['code'] . ' R' . (int) $c['rev'] . ')'; if ($c['reports_to'] !== '') $out[] = $tr('jd.reports_to') . ': ' . $c['reports_to']; if ($c['purpose'] !== '') $out[] = $tr('jd.s_purpose') . ":\n" . $c['purpose'];
        foreach (JD_SECTIONS as $k) if (!empty($c[$k])) $out[] = $tr('jd.s_' . $k) . ":\n- " . implode("\n- ", $c[$k]);
    } elseif ($v) {
        $out[] = (string) $v['title']; if ((string) $v['summary'] !== '') $out[] = (string) $v['summary'];
        $d = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/li|/h\d)\b[^>]*>#i', "\n", (string) $v['description_html'])), ENT_QUOTES, 'UTF-8')); if ($d !== '') $out[] = preg_replace("/\n{3,}/", "\n\n", $d);
        $rq = array_values(array_filter(array_map('strval', (array) json_decode((string) ($v['requirements'] ?? '[]'), true)), 'strlen')); if ($rq) $out[] = "- " . implode("\n- ", $rq);
    }
    return ['text' => trim(implode("\n\n", $out)), 'jd_id' => $jdId, 'title' => $title];
}
/** Saves the draft offer of an application (one draft at a time; a sent offer is a record and is not changed — a revised offer is a new one). */
function rcr_offer_save(int $appId, array $in): int {
    if (!rcr_offer_can()) throw new RuntimeException(t('err.noaccess')); $a = rcr_application($appId); if (!$a || in_array($a['status'], ['withdrawn'], true) || str_starts_with((string) $a['email'], 'anon-')) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $txt = fn(string $k, int $max) => ($x = mb_substr(trim(str_replace("\r\n", "\n", (string) ($in[$k] ?? ''))), 0, $max)) !== '' ? $x : null; $dte = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in[$k] ?? '')) ? (string) $in[$k] : null;
    $pos = $txt('position', 190) ?: (string) $a['vacancy']; $raw = str_replace([' ', "\u{a0}"], '', (string) ($in['net_salary'] ?? ''));
    if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$/', $raw)) $raw = str_replace(['.', ','], ['', '.'], $raw); else $raw = str_replace(',', '.', $raw);   // 1.850,00 or 1850.00 or 1850,5
    $sal = $raw === '' ? null : (is_numeric($raw) && (float) $raw > 0 && (float) $raw < 10000000 ? round((float) $raw, 2) : false); if ($sal === false) throw new RuntimeException(t('rc.of_bad_salary'));
    $cur = preg_match('/^[A-Z]{3}$/', (string) ($in['currency'] ?? '')) ? (string) $in['currency'] : 'EUR'; $per = ($in['salary_per'] ?? '') === 'year' ? 'year' : 'month';
    $pay = (int) ($in['payments_per_year'] ?? 0); $pay = $pay >= 1 && $pay <= 16 ? $pay : null; $con = in_array($in['contract'] ?? '', RCR_OFFER_CONTRACT, true) ? (string) $in['contract'] : 'indefinite';
    $prob = (string) ($in['probation_months'] ?? '') === '' ? null : max(0, min(24, (int) $in['probation_months']));
    $vals = [$pos, $sal, $cur, $per, $pay, $con, $dte('start_date'), $txt('hours', 120), $txt('place', 160), $prob, $txt('benefits', 4000), $txt('terms', 6000), $dte('valid_until'), $txt('jd_text', 30000)];
    $d = one("SELECT id, jd_id FROM rc_offers WHERE application_id = ? AND status = 'draft' ORDER BY id DESC LIMIT 1", [$appId]);
    $jdPick = (int) ($in['jd_id'] ?? 0); if ($jdPick && !(function_exists('jd_has') && jd_has() && ($pj = jd_load($jdPick)) && jd_current($pj))) $jdPick = 0;   // R97-264: only an approved job description
    if ($d) { q('UPDATE rc_offers SET position=?, net_salary=?, currency=?, salary_per=?, payments_per_year=?, contract=?, start_date=?, hours=?, place=?, probation_months=?, benefits=?, terms=?, valid_until=?, jd_text=? WHERE id = ?', [...$vals, (int) $d['id']]);
        if (rcr_offer_appr_has() && (int) q('UPDATE rc_offers SET appr_status = NULL, appr_by = NULL, appr_name = NULL, appr_at = NULL, appr_note = NULL WHERE id = ? AND appr_status IS NOT NULL', [(int) $d['id']])->rowCount()) rcr_log('offer_approval_reset', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], t('rc.ap_reset_log'));   // R97-247: changed terms are approved again
        if ($jdPick && $jdPick !== (int) ($d['jd_id'] ?? 0)) { q('UPDATE rc_offers SET jd_id = ? WHERE id = ?', [$jdPick, (int) $d['id']]); rcr_log('offer_jd', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], (string) ($pj['code'] ?? '') . ' · ' . $pos); }
        return (int) $d['id']; }
    $n = (int) scalar('SELECT COUNT(*) + 1 FROM rc_offers WHERE application_id = ?', [$appId]); $jd = rcr_offer_jd_text($a); if ($jdPick) $jd['jd_id'] = $jdPick;
    q('INSERT INTO rc_offers (position, net_salary, currency, salary_per, payments_per_year, contract, start_date, hours, place, probation_months, benefits, terms, valid_until, jd_text, application_id, offer_no, jd_id, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [...$vals, $appId, $a['code'] . '-OF' . $n, $jd['jd_id'], (int) $u['id'], (string) $u['full_name']]);
    $id = (int) db()->lastInsertId(); rcr_log('offer_draft', ['application_id' => $appId, 'vacancy_id' => (int) $a['vacancy_id']], $a['code'] . '-OF' . $n); return $id;
}
/** The e-mail of an offer from the template «Recruiting — offer», in the language of the candidate, with the terms and the job description filled in. */
function rcr_offer_draft(int $id): array {
    $o = rcr_offer($id); if (!$o) throw new RuntimeException(t('err.noaccess')); $a = $o['app']; $u = current_user();
    $lc = in_array((string) ($a['lang'] ?? ''), array_keys(LOCALES), true) ? (string) $a['lang'] : 'en'; $tr = fn(string $k, array $v = []) => t_in($k, $lc, $v); $dt = fn(?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';
    $sal = rcr_offer_salary_text($o, $lc); $con = $tr('rc.of_c_' . $o['contract']); $prob = $o['probation_months'] !== null ? $tr('rc.of_months', ['n' => (int) $o['probation_months']]) : '';
    $rows = [[$tr('rc.of_position'), (string) $o['position']], [$tr('rc.of_salary'), $sal], [$tr('rc.of_contract'), $con], [$tr('rc.of_start'), $dt($o['start_date'])], [$tr('rc.of_hours'), (string) $o['hours']], [$tr('rc.of_place'), (string) $o['place']], [$tr('rc.of_probation'), $prob], [$tr('rc.of_benefits'), (string) $o['benefits']], [$tr('rc.of_terms'), (string) $o['terms']], [$tr('rc.of_valid'), $dt($o['valid_until'])]];
    $det = implode("\n", array_map(fn($r) => $r[0] . ': ' . (str_contains($r[1], "\n") ? "\n" . $r[1] : $r[1]), array_values(array_filter($rows, fn($r) => $r[1] !== ''))));
    $jd = trim((string) $o['jd_text']); if ($jd !== '') $det .= "\n\n" . $tr('rc.of_jd') . "\n" . str_repeat('-', 40) . "\n" . $jd;
    $branch = ''; try { if (!empty($u['branch_id']) && function_exists('br_get')) $branch = (string) (br_get((int) $u['branch_id'])['name'] ?? ''); } catch (Throwable $e) {}
    $nz = fn(string $v) => $v !== '' ? $v : "\u{1}";   // a term left empty is marked, and the line of the template that carries it is taken out below
    $r = function_exists('et_render') ? et_render('rc_offer', ['no' => (string) $o['offer_no'], 'title' => (string) $o['position'], 'doc' => (string) $a['vacancy_code'], 'name' => (string) ($a['name'] ?: $a['email']), 'email' => (string) $a['email'], 'user' => (string) $u['full_name'], 'branch' => $branch ?: 'Golden A/S',
            'salary' => $nz($sal), 'start' => $nz($dt($o['start_date'])), 'contract' => $con, 'hours' => $nz((string) $o['hours']), 'place' => $nz((string) $o['place']), 'probation' => $nz($prob), 'benefits' => $nz((string) $o['benefits']), 'terms' => $nz((string) $o['terms']), 'valid' => $nz($dt($o['valid_until'])), 'jd' => $jd,
            'locale' => $lc, 'branch_id' => $u['branch_id'] ?? null, '_details' => $det, '_details_if' => '{salary}'])
        : ['subject' => 'Golden A/S — ' . $tr('rc.st_offer') . ' · ' . $o['position'], 'body' => ($a['name'] ?: $a['email']) . ",\n\n" . $det . "\n\nGolden A/S — HR"];
    $body = str_replace("\u{1}", '', preg_replace('/^[^\n]*\x01[^\n]*(\n|$)/mu', '', (string) $r['body'])); $r['subject'] = str_replace("\u{1}", '', (string) $r['subject']);   // a term left empty leaves no line in the e-mail
    return ['to' => (string) $a['email'], 'subject' => (string) $r['subject'], 'body' => preg_replace("/\n{3,}/", "\n\n", $body), 'locale' => $lc];
}
/** What still stops an offer from being sent ([] = ready). */
function rcr_offer_missing(array $o): array { $m = []; if ($o['net_salary'] === null) $m[] = t('rc.of_salary'); if (!$o['start_date']) $m[] = t('rc.of_start'); if (trim((string) $o['jd_text']) === '') $m[] = t('rc.of_jd'); return $m; }
/** Sends the offer (the text HR sees and may have changed) through the portal. When it leaves, the offer becomes a record: status sent, the text kept, the application moved to «Offer». */
function rcr_offer_send(int $id, string $subject, string $body): bool {
    $o = rcr_offer($id); if (!$o) throw new RuntimeException(t('err.noaccess')); if ($o['status'] !== 'draft') throw new RuntimeException(t('rc.of_only_draft')); $a = $o['app']; $u = current_user();
    if ($m = rcr_offer_missing($o)) throw new RuntimeException(t('rc.of_missing', ['what' => implode(', ', $m)]));
    if (!rcr_offer_approved($o)) throw new RuntimeException(t('rc.ap_needed'));
    $subject = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $subject)), 0, 200); $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 60000); if ($subject === '' || mb_strlen($body) < 20) throw new RuntimeException(t('rc.ev_need_text'));
    if (!filter_var((string) $a['email'], FILTER_VALIDATE_EMAIL) || str_starts_with((string) $a['email'], 'anon-')) throw new RuntimeException(t('rc.ev_no_email'));
    if ((int) scalar("SELECT COUNT(*) FROM rc_log WHERE action IN ('offer_sent','offer_not_sent') AND application_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE", [(int) $o['application_id']]) >= 3) throw new RuntimeException(t('rc.ev_too_many'));
    $ok = rcr_mail((string) $a['email'], $subject, $body);
    rcr_log($ok ? 'offer_sent' : 'offer_not_sent', ['application_id' => (int) $o['application_id'], 'vacancy_id' => (int) $a['vacancy_id']], (string) $o['offer_no']);
    if (!$ok) return false;
    q("UPDATE rc_offers SET status = 'sent', subject = ?, body = ?, sent_at = NOW(), sent_by = ?, sent_name = ? WHERE id = ? AND status = 'draft'", [$subject, $body, (int) $u['id'], (string) $u['full_name'], $id]);
    if (!in_array($a['status'], ['offer', 'hired', 'rejected', 'withdrawn'], true)) { q("UPDATE rc_applications SET status = 'offer', status_changed_at = NOW() WHERE id = ?", [(int) $o['application_id']]); rcr_log('status', ['application_id' => (int) $o['application_id'], 'vacancy_id' => (int) $a['vacancy_id']], $a['status'] . ' → offer'); }
    return true;
}
/** R97-246: the offer left through the standard e-mail card (sent from the person's mailbox, or prepared as a draft in Outlook / as a message file).
 *  It becomes the record — subject and text as they were in the card — and the application moves to «Offer», when the candidate is among the recipients. */
function rcr_offer_mailed(int $id, array $to, string $subject, string $body, string $how): void {
    $o = rcr_offer($id); if (!$o || $o['status'] !== 'draft' || rcr_offer_missing($o)) return; $a = $o['app']; $u = current_user();
    $ctx = ['application_id' => (int) $o['application_id'], 'vacancy_id' => (int) $a['vacancy_id']];
    if (!rcr_offer_approved($o)) { rcr_log('offer_not_sent', $ctx, (string) $o['offer_no'] . ' — ' . t('rc.ap_needed')); return; }
    if (!in_array(strtolower(trim((string) $a['email'])), array_map('strtolower', $to), true)) { rcr_log('offer_not_sent', $ctx, (string) $o['offer_no'] . ' — ' . t('rc.of_not_to')); return; }
    $subject = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $subject)), 0, 200); $body = mb_substr(trim(str_replace("\r\n", "\n", $body)), 0, 60000);
    q("UPDATE rc_offers SET status = 'sent', subject = ?, body = ?, sent_at = NOW(), sent_by = ?, sent_name = ? WHERE id = ? AND status = 'draft'", [$subject, $body, (int) $u['id'], (string) $u['full_name'], $id]);
    rcr_log('offer_sent', $ctx, (string) $o['offer_no'] . ' — ' . t('rc.of_how_' . (in_array($how, ['sent', 'outlook', 'file'], true) ? $how : 'sent')));
    if (!in_array($a['status'], ['offer', 'hired', 'rejected', 'withdrawn'], true)) { q("UPDATE rc_applications SET status = 'offer', status_changed_at = NOW() WHERE id = ?", [(int) $o['application_id']]); rcr_log('status', $ctx, $a['status'] . ' → offer'); }
}
/** R97-246: the candidate answered through the personal e-mail link (mailbox confirmed with a code). The answer, the name, the address, the starting date
 *  that was accepted and the network address are kept on the offer and in the recruiting log. Returns 1 when the offer was still waiting. */
function rcr_offer_answered(int $id, string $st, string $name, string $email, string $remark, string $ip): int {
    $o = one('SELECT * FROM rc_offers WHERE id = ?', [$id]); if (!$o || !in_array($st, ['accepted', 'declined'], true)) return 0;
    $a = one('SELECT id, vacancy_id FROM rc_applications WHERE id = ?', [(int) $o['application_id']]) ?: ['id' => (int) $o['application_id'], 'vacancy_id' => null];
    $start = $o['start_date'] ? date('d/m/Y', strtotime((string) $o['start_date'])) : '';
    $note = t($st === 'accepted' ? 'rc.of_dl_note_ok' : 'rc.of_dl_note_no', ['n' => $name, 'e' => $email, 'd' => $start]) . ($remark !== '' ? ' — ' . $remark : '');
    $n = q("UPDATE rc_offers SET status = ?, outcome_at = NOW(), outcome_note = ? WHERE id = ? AND status = 'sent'", [$st, mb_substr($note, 0, 300), $id])->rowCount(); if (!$n) return 0;
    q('INSERT INTO rc_log (application_id, vacancy_id, action, detail) VALUES (?,?,?,?)', [(int) $a['id'], $a['vacancy_id'] ? (int) $a['vacancy_id'] : null, 'offer_' . $st, mb_substr((string) $o['offer_no'] . ' — ' . $note . ($ip !== '' ? ' · IP ' . $ip : ''), 0, 500)]);   // no portal user: the candidate answered
    return $n;
}
/** The answer of the candidate, or the offer taken back: accepted · declined · withdrawn. A draft can only be discarded (withdrawn). */
function rcr_offer_outcome(int $id, string $st, string $note = ''): void {
    $o = rcr_offer($id); if (!$o || !in_array($st, ['accepted', 'declined', 'withdrawn'], true)) throw new RuntimeException(t('err.noaccess'));
    if (!($o['status'] === 'sent' || ($o['status'] === 'draft' && $st === 'withdrawn'))) throw new RuntimeException(t('rc.of_only_sent'));
    q('UPDATE rc_offers SET status = ?, outcome_at = NOW(), outcome_note = ? WHERE id = ?', [$st, mb_substr(trim($note), 0, 300) ?: null, $id]);
    rcr_log('offer_' . $st, ['application_id' => (int) $o['application_id'], 'vacancy_id' => (int) $o['app']['vacancy_id']], (string) $o['offer_no']);
}


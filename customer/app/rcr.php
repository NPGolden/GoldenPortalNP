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
        rcr_log('vacancy_saved', ['vacancy_id' => $id], ($old['status'] ?? '') . ' → ' . $status); return $id; }
    q('INSERT INTO rc_vacancies (title, department_id, branch_id, location, emp_type, seniority, summary, description_html, requirements, tags, screening_set_id, status, publish_web, closes_at, hiring_manager_id, code, published_at, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [...$p, rcr_code('VAC', 'rc_vacancies'), $status === 'open' ? date('Y-m-d') : null, current_user()['id']]);
    $id = (int) db()->lastInsertId(); rcr_log('vacancy_created', ['vacancy_id' => $id]); return $id;
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
    if ($id) { q('UPDATE rc_events SET title=?, kind=?, starts_at=?, duration_min=?, platform=?, join_url=?, capacity=?, description_html=?, for_vacancies=?, registration_closes_at=?, status=?, publish_web=? WHERE id=?', [...$p, $id]); rcr_log('event_saved', ['event_id' => $id]); return $id; }
    q('INSERT INTO rc_events (title, kind, starts_at, duration_min, platform, join_url, capacity, description_html, for_vacancies, registration_closes_at, status, publish_web, code, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [...$p, rcr_code('EVT', 'rc_events'), current_user()['id']]);
    $id = (int) db()->lastInsertId(); rcr_log('event_created', ['event_id' => $id]); return $id;
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
function rcr_application_events(int $id): array { return all('SELECT r.*, e.title, e.starts_at, e.kind FROM rc_event_registrations r JOIN rc_events e ON e.id = r.event_id WHERE r.application_id = ? ORDER BY e.starts_at', [$id]); }
function rcr_application_decide(int $id, array $in): void {
    $a = rcr_application($id); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess'));
    $st = in_array($in['status'] ?? '', RCR_APP_STATUS, true) ? $in['status'] : $a['status'];
    q('UPDATE rc_applications SET status = ?, rating = ?, hr_notes = ?, owner_user_id = ?, status_changed_at = IF(status <> ?, NOW(), status_changed_at) WHERE id = ?',
      [$st, (int) ($in['rating'] ?? 0) ?: null, mb_substr(trim((string) ($in['hr_notes'] ?? '')), 0, 4000) ?: null, (int) ($in['owner_user_id'] ?? 0) ?: null, $st, $id]);
    if ($st !== $a['status']) { rcr_log('status', ['application_id' => $id, 'vacancy_id' => (int) $a['vacancy_id']], $a['status'] . ' → ' . $st);
        if (!empty($in['notify_candidate']) && in_array($st, ['shortlisted', 'offer', 'rejected'], true)) rcr_mail((string) $a['email'], 'Golden A/S — ' . t('rc.st_' . $st) . ' · ' . $a['vacancy_code'], ($a['name'] ?: $a['email']) . ",\n\n" . t('rc.ev_mail_' . $st, ['vacancy' => $a['vacancy']]) . "\n\nGolden Automation Systems — HR"); }   // R91-001
    if ($st === 'hired' && empty($a['hired_person_id']) && function_exists('hr2_has') && hr2_has()) {   // onboarding handshake: a person card in HR › People
        q('INSERT INTO hr_people (name_en, is_technical, hire_date) VALUES (?, 1, CURDATE())', [(string) ($a['name'] ?: $a['email'])]); $pid = (int) db()->lastInsertId();
        q('UPDATE rc_applications SET hired_person_id = ? WHERE id = ?', [$pid, $id]); rcr_log('person_created', ['application_id' => $id], 'hr_people #' . $pid);
    }
}
function rcr_cv_stream(int $id): void {
    $a = rcr_application($id); if (!$a || !rcr_can_view((int) $a['vacancy_id']) || !$a['cv_path'] || !is_file($a['cv_path'])) { http_response_code(404); exit; }
    $ext = strtolower(pathinfo($a['cv_path'], PATHINFO_EXTENSION)); header('Content-Type: ' . ($ext === 'pdf' ? 'application/pdf' : 'application/octet-stream')); header('Content-Disposition: inline; filename="' . preg_replace('/[^\w.\-]+/', '_', (string) $a['cv_name']) . '"'); header('X-Content-Type-Options: nosniff'); readfile($a['cv_path']); exit;
}

/* ------------------------------------------------------------------ public website feed (called by rc_feed.php) */
function rcr_pub_vacancies(string $lang): array {
    $rows = all('SELECT v.code AS id, v.title, d.name AS department, v.location, b.name AS branch, v.emp_type, v.seniority, v.summary, v.description_html, v.requirements, v.tags, s.code AS screening_set, v.published_at, v.closes_at FROM rc_vacancies v LEFT JOIN disciplines d ON d.id = v.department_id LEFT JOIN branches b ON b.id = v.branch_id JOIN rc_screening_sets s ON s.id = v.screening_set_id WHERE v.status = "open" AND v.publish_web = 1 AND (v.closes_at IS NULL OR v.closes_at >= CURDATE()) ORDER BY v.published_at DESC');
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
    $v = one('SELECT * FROM rc_vacancies WHERE code = ? AND status = "open" AND publish_web = 1', [(string) ($in['vacancy_id'] ?? '')]); if (!$v) throw new RuntimeException('vacancy closed', 410);
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
        $rows = all('SELECT id, cv_path FROM rc_applications WHERE status IN ("screened","cv_received","rejected","withdrawn") AND COALESCE(status_changed_at, created_at) < ?', [$cut]);
        foreach ($rows as $r) { if ($r['cv_path'] && is_file($r['cv_path'])) { @unlink($r['cv_path']); $n['cvs_deleted']++; } }
        if ($rows) { $ids = array_column($rows, 'id'); $in = implode(',', array_map('intval', $ids)); q("DELETE FROM rc_event_registrations WHERE application_id IN ($in)"); q("DELETE FROM rc_log WHERE application_id IN ($in)"); $n['apps_deleted'] = q("DELETE FROM rc_applications WHERE id IN ($in)")->rowCount(); }
        foreach (all('SELECT id, cv_path FROM rc_applications WHERE status = "hired" AND COALESCE(status_changed_at, created_at) < ? AND email NOT LIKE "anon-%"', [$cut]) as $r) {
            if ($r['cv_path'] && is_file($r['cv_path'])) { @unlink($r['cv_path']); $n['cvs_deleted']++; }
            q('UPDATE rc_applications SET email = CONCAT("anon-", code, "@retired.local"), name = NULL, phone = NULL, answers = NULL, cv_path = NULL, cv_name = NULL, cover_note = NULL, hr_notes = NULL, ip = NULL, user_agent = NULL WHERE id = ?', [(int) $r['id']]); $n['apps_anonymised']++; }
        $n['events_deleted'] = q('DELETE r FROM rc_event_registrations r JOIN rc_events e ON e.id = r.event_id WHERE e.starts_at < ?', [$cut])->rowCount();
        $n['pool_deleted'] = q('DELETE FROM rc_talent_pool WHERE created_at < ?', [$cut])->rowCount();
        q('INSERT INTO rc_retention_log (cutoff, apps_deleted, apps_anonymised, cvs_deleted, events_deleted, pool_deleted) VALUES (?,?,?,?,?,?)', [$cut, $n['apps_deleted'], $n['apps_anonymised'], $n['cvs_deleted'], $n['events_deleted'], $n['pool_deleted']]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return $n;
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
    if (!empty($in['notify'])) {
        $body = sprintf("%s,\n\n%s\n\n%s: %s\n%s: %s\n%s: %s\n\n%s\n\nGolden Automation Systems — HR", $a['name'] ?: $a['email'], t('rc.ev_mail_intro', ['vacancy' => $a['vacancy']]), t('rc.ev_stage'), t('rc.ev_stage_' . $stage), t('rc.ev_when'), date('d/m/Y H:i', strtotime($when)) . ' (Athens)', t('rc.ev_mode'), t('rc.ev_mode_' . $mode) . ($in['location'] ? ' · ' . trim((string) $in['location']) : ''), t('rc.ev_mail_outro'));
        if (rcr_mail((string) $a['email'], 'Golden A/S — ' . t('rc.ev_interview') . ' · ' . $a['vacancy_code'], $body)) q('UPDATE rc_interviews SET candidate_notified_at = NOW() WHERE id = ?', [$id]);
    }
    return $id;
}
function rcr_interview_status(int $id, string $st): void { if (!in_array($st, ['planned', 'done', 'no_show', 'cancelled'], true)) return; $i = one('SELECT * FROM rc_interviews WHERE id = ?', [$id]); if (!$i) return; $a = rcr_application((int) $i['application_id']); if (!$a || !rcr_can_view((int) $a['vacancy_id'])) throw new RuntimeException(t('err.noaccess')); q('UPDATE rc_interviews SET status = ? WHERE id = ?', [$st, $id]); rcr_log('interview_' . $st, ['application_id' => (int) $i['application_id']], (string) $i['stage']); }

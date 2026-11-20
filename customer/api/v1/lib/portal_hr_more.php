<?php
/**
 * Golden HR 1.1.0 — Today, recruiting, job-description approvals, the HR year, expiring documents and equipment
 * handovers for the HR app (GAS-CP-R97-156). Same gate as the rest of the app (PortalHR::allowed on every request:
 * HR role, Owner, CEO). Everything here is served live and is NOT kept on the phone: the app holds these lists in
 * memory only (candidates are personal data under the recruiting retention rules).
 *
 * Every write goes through the portal's own functions (rcr_application_decide, rcr_eval_save, rcr_interview_status,
 * jd_approve / jd_reject, hry_tick_from_post, hre_receive_from_post, hre_accept) so rights, logs, notifications and
 * the retention clock stay the same. Decisions that sign something (candidate status, job description, equipment)
 * carry the authenticator code when the account has one (PortalPM::stepUp).
 *
 * Never selected: screening answers, IP address / user agent of a candidate, the CV path, salary or payroll data.
 */
declare(strict_types=1);

final class PortalHRMore {
    const APP_MAX = 300;
    const STATUS_FROM_APP = ['shortlisted', 'interview', 'offer', 'rejected'];   // «hired» creates the person card: portal only

    private static function boot(): void {
        static $done = false; if ($done) return; $done = true;
        foreach (['mailer', 'pmt', 'rcr', 'jd', 'hry', 'hre'] as $m) { $f = CP_ROOT . '/app/' . $m . '.php'; if (is_file($f)) require_once $f; }
    }
    private static function d(?string $v): ?string { return $v ? substr($v, 0, 10) : null; }
    private static function days(?string $from): int { return $from ? max(0, (int) floor((time() - strtotime($from)) / 86400)) : 0; }
    private static function guard(callable $fn) { try { return $fn(); } catch (ApiException $e) { throw $e; } catch (RuntimeException $e) { throw new ApiException(422, $e->getMessage()); } }
    private static function safe(callable $fn, $default) { try { return $fn(); } catch (Throwable $e) { error_log('[api/v1 hr today] ' . $e->getMessage()); return $default; } }

    /* ------------------------------------------------------------------ today: counts only */
    public static function today(array $u, ?array $counts = null): array {
        self::boot(); $c = $counts ?? PortalHR::counts($u);
        return ['inbox' => (int)$c['inbox'], 'unread' => (int)$c['unread'], 'away_today' => (int)$c['away_today'],
            'recruiting' => self::safe(fn() => self::rcCounts(), null),
            'jd' => self::safe(fn() => self::jdHas() ? ['mine' => count(array_filter(self::jdRows(), fn($j) => $j['mine'])), 'review' => count(self::jdRows())] : null, null),
            'year' => self::safe(function () { if (!function_exists('hry_has') || !hry_has()) return null; $a = hry_attention(); return ['late' => count($a['late']), 'due' => count($a['due'])]; }, null),
            'expiring' => self::safe(function () { $x = self::expiring(60)['items']; return ['d30' => count(array_filter($x, fn($i) => $i['days'] <= 30)), 'd60' => count($x), 'expired' => count(array_filter($x, fn($i) => $i['days'] < 0))]; }, null),
            'equipment' => self::safe(function () { if (!function_exists('hre_can') || !hre_can()) return null; $w = hre_waiting(); return ['returns' => count($w['return']), 'issues' => count($w['issue']), 'leavers' => count($w['leavers']), 'new' => count($w['new'])]; }, null)];
    }

    /* ------------------------------------------------------------------ recruiting */
    private static function rcOn(): void { self::boot(); if (!function_exists('rcr_can') || !rcr_can()) throw new ApiException(403, 'Recruiting is not available for this account'); }
    private static function rcCounts(): ?array {
        if (!function_exists('rcr_can') || !rcr_can()) return null;
        return ['open' => (int) scalar('SELECT COUNT(*) FROM rc_vacancies WHERE status = "open"'),
            'new' => (int) scalar('SELECT COUNT(*) FROM rc_applications WHERE status = "cv_received"'),
            'active' => (int) scalar('SELECT COUNT(*) FROM rc_applications WHERE status IN ("shortlisted","interview","offer")'),
            'interviews_today' => rcr_eval_has() ? (int) scalar('SELECT COUNT(*) FROM rc_interviews WHERE status = "planned" AND DATE(scheduled_at) = CURDATE()') : 0];
    }
    private static function shapeApp(array $a): array {
        $ext = strtolower(pathinfo((string)($a['cv_name'] ?? ''), PATHINFO_EXTENSION));
        return ['id' => (int)$a['id'], 'code' => (string)$a['code'], 'name' => (string)($a['name'] ?: $a['email']), 'vacancy_id' => (int)$a['vacancy_id'], 'vacancy' => (string)($a['vacancy'] ?? ''), 'vacancy_code' => (string)($a['vacancy_code'] ?? ''),
            'status' => (string)$a['status'], 'status_label' => t('rc.st_' . $a['status']), 'passed' => !empty($a['passed']), 'score' => $a['score'] !== null ? (int)$a['score'] : null, 'max_score' => $a['max_score'] !== null ? (int)$a['max_score'] : null,
            'eval_avg' => isset($a['eval_avg']) && $a['eval_avg'] !== null ? round((float)$a['eval_avg'], 1) : null, 'eval_count' => (int)($a['eval_count'] ?? 0), 'rating' => $a['rating'] !== null ? (int)$a['rating'] : null,
            'has_cv' => !empty($a['cv_path']), 'cv_pdf' => !empty($a['cv_path']) && $ext === 'pdf', 'cv_name' => (string)($a['cv_name'] ?? ''), 'applied' => self::d((string)$a['created_at']), 'since' => self::d((string)($a['status_changed_at'] ?: $a['created_at'])), 'days' => self::days((string)($a['status_changed_at'] ?: $a['created_at']))];
    }
    private static function shapeInterview(array $i): array {
        return ['id' => (int)$i['id'], 'application_id' => (int)$i['application_id'], 'name' => (string)($i['name'] ?? ''), 'vacancy' => (string)($i['vacancy'] ?? ''), 'stage' => (string)$i['stage'], 'stage_label' => t('rc.ev_stage_' . $i['stage']),
            'at' => (string)$i['scheduled_at'], 'duration' => (int)$i['duration_min'], 'mode' => (string)$i['mode'], 'mode_label' => t('rc.ev_mode_' . $i['mode']), 'location' => (string)($i['location'] ?? ''), 'interviewers' => (string)($i['interviewers'] ?? ''), 'status' => (string)$i['status']];
    }
    private static function labels(): array {
        $st = []; foreach (RCR_APP_STATUS as $s) $st[$s] = t('rc.st_' . $s); $es = []; foreach (RCR_EVAL_STAGE as $s) $es[$s] = t('rc.ev_stage_' . $s); $rc = []; foreach (RCR_EVAL_RECO as $s) $rc[$s] = t('rc.ev_reco_' . $s);
        return ['status' => $st, 'stage' => $es, 'reco' => $rc, 'can_set' => self::STATUS_FROM_APP];
    }
    public static function recruiting(): array {
        self::rcOn();
        $by = []; foreach (all('SELECT vacancy_id, status, COUNT(*) AS n FROM rc_applications GROUP BY vacancy_id, status') as $r) $by[(int)$r['vacancy_id']][(string)$r['status']] = (int)$r['n'];
        $vac = []; foreach (rcr_vacancies(['status' => 'open']) as $v) $vac[] = ['id' => (int)$v['id'], 'code' => (string)$v['code'], 'title' => (string)$v['title'], 'department' => (string)($v['department'] ?? ''), 'branch' => (string)($v['branch'] ?? ''),
            'hiring_manager' => (string)($v['hiring_manager'] ?? ''), 'published' => self::d($v['published_at'] ?? null), 'closes' => self::d($v['closes_at'] ?? null), 'n_apps' => (int)$v['n_apps'], 'n_passed' => (int)$v['n_passed'], 'n_active' => (int)$v['n_active'], 'stages' => $by[(int)$v['id']] ?? []];
        $hasEval = rcr_eval_has();
        $rows = all('SELECT a.*, v.title AS vacancy, v.code AS vacancy_code FROM rc_applications a JOIN rc_vacancies v ON v.id = a.vacancy_id
                     WHERE a.status IN ("cv_received","shortlisted","interview","offer") OR (a.status = "screened" AND a.passed = 1 AND a.created_at > NOW() - INTERVAL 30 DAY)
                     ORDER BY FIELD(a.status,"offer","interview","shortlisted","cv_received","screened"), a.created_at DESC LIMIT ' . self::APP_MAX);
        $int = $hasEval ? all('SELECT i.*, COALESCE(NULLIF(a.name, ""), a.email) AS name, v.title AS vacancy FROM rc_interviews i JOIN rc_applications a ON a.id = i.application_id JOIN rc_vacancies v ON v.id = a.vacancy_id
                               WHERE i.status = "planned" AND i.scheduled_at >= CURDATE() - INTERVAL 7 DAY ORDER BY i.scheduled_at LIMIT 100') : [];
        return ['vacancies' => $vac, 'applications' => array_map([self::class, 'shapeApp'], $rows), 'interviews' => array_map([self::class, 'shapeInterview'], $int),
            'criteria' => $hasEval ? array_map(fn($c) => ['key' => (string)$c['key'], 'label' => (string)$c['label'], 'weight' => (float)$c['weight']], rcr_eval_criteria()) : [], 'labels' => self::labels(), 'counts' => self::rcCounts()];
    }
    public static function application(int $id): array {
        self::rcOn(); $a = rcr_application($id); if (!$a) throw new ApiException(404, 'Application not found');
        $o = self::shapeApp($a) + ['email' => (string)$a['email'], 'phone' => (string)($a['phone'] ?? ''), 'cover_note' => (string)($a['cover_note'] ?? ''), 'department_interest' => (string)($a['department_interest'] ?? ''),
            'hr_notes' => (string)($a['hr_notes'] ?? ''), 'source' => (string)($a['source'] ?? ''), 'lang' => (string)($a['lang'] ?? '')];
        $o['evaluations'] = array_map(fn($e) => ['id' => (int)$e['id'], 'stage' => (string)$e['stage'], 'stage_label' => t('rc.ev_stage_' . $e['stage']), 'by' => (string)($e['by_name'] ?: $e['evaluator_name']), 'total' => round((float)$e['total'], 1),
            'reco' => (string)$e['recommendation'], 'reco_label' => t('rc.ev_reco_' . $e['recommendation']), 'strengths' => (string)($e['strengths'] ?? ''), 'concerns' => (string)($e['concerns'] ?? ''), 'notes' => (string)($e['notes'] ?? ''), 'at' => (string)$e['created_at']], rcr_evaluations($id));
        $o['interviews'] = array_map(fn($i) => self::shapeInterview($i + ['name' => $o['name'], 'vacancy' => $o['vacancy']]), rcr_interviews($id));
        return $o;
    }
    /** The CV, streamed for the in-app reader (never stored on the phone). Every view is written to the recruiting log. */
    public static function cv(int $id): void {
        self::rcOn(); $a = rcr_application($id); if (!$a || !$a['cv_path'] || !is_file($a['cv_path'])) throw new ApiException(404, 'No CV');
        try { rcr_log('cv_view', ['application_id' => $id, 'vacancy_id' => (int)$a['vacancy_id']], 'Golden HR app'); } catch (Throwable $e) {}
        header('Cache-Control: no-store, private'); rcr_cv_stream($id);
    }
    public static function appStatus(array $u, int $id, array $p): array {
        self::rcOn(); PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $a = rcr_application($id); if (!$a) throw new ApiException(404, 'Application not found');
        $st = (string)($p['status'] ?? ''); if (!in_array($st, self::STATUS_FROM_APP, true)) throw new ApiException(422, 'This status is set in the portal');
        if (isset($p['from']) && (string)$p['from'] !== (string)$a['status']) throw new ApiException(409, 'Changed in the portal — now ' . t('rc.st_' . $a['status']));
        if (in_array($a['status'], ['hired', 'withdrawn'], true)) throw new ApiException(409, 'Closed: ' . t('rc.st_' . $a['status']));
        $note = mb_substr(trim((string)($p['note'] ?? '')), 0, 500); if ($st === 'rejected' && $note === '') throw new ApiException(422, 'A reason is required to reject');
        $notes = (string)($a['hr_notes'] ?? ''); if ($note !== '') $notes = trim($notes . "\n[" . date('d/m/Y') . ' ' . (string)($u['full_name'] ?? '') . ' — Golden HR] ' . t('rc.st_' . $st) . ': ' . $note);
        self::guard(fn() => rcr_application_decide($id, ['status' => $st, 'rating' => $a['rating'], 'hr_notes' => $notes, 'owner_user_id' => $a['owner_user_id'], 'notify_candidate' => !empty($p['notify'])]));
        return self::application($id);
    }
    public static function appEvaluation(array $u, int $id, array $p): array {
        self::rcOn(); if (!rcr_eval_has()) throw new ApiException(404, 'Evaluations are not installed');
        $in = ['stage' => (string)($p['stage'] ?? ''), 'score' => array_map('intval', (array)($p['score'] ?? [])), 'recommendation' => (string)($p['recommendation'] ?? ''), 'strengths' => (string)($p['strengths'] ?? ''), 'concerns' => (string)($p['concerns'] ?? ''), 'notes' => (string)($p['notes'] ?? '')];
        self::guard(fn() => rcr_eval_save($id, $in));
        return self::application($id);
    }
    public static function interviewStatus(int $id, array $p): array {
        self::rcOn(); $st = (string)($p['status'] ?? ''); if (!in_array($st, ['done', 'no_show', 'cancelled'], true)) throw new ApiException(422, 'status');
        $i = rcr_eval_has() ? one('SELECT id, application_id, status FROM rc_interviews WHERE id = ?', [$id]) : null; if (!$i) throw new ApiException(404, 'Interview not found');
        if ($i['status'] !== 'planned') throw new ApiException(409, 'Already ' . $i['status']);
        self::guard(fn() => rcr_interview_status($id, $st));
        return ['id' => $id, 'status' => $st, 'application_id' => (int)$i['application_id']];
    }

    /* ------------------------------------------------------------------ job descriptions in review */
    private static function jdHas(): bool { self::boot(); return function_exists('jd_has') && jd_has(); }
    private static function jdRows(): array {
        static $rows = null; if ($rows !== null) return $rows; $rows = [];
        foreach (jd_list(['status' => 'review']) as $j) { $steps = array_values(array_map(fn($s) => ['key' => $s['key'], 'label' => $s['label'], 'state' => $s['state'], 'name' => $s['name'], 'at' => $s['at'], 'next' => $s['next'], 'mine' => $s['mine']], jd_steps($j)));
            $rows[] = ['id' => (int)$j['id'], 'code' => (string)$j['code'], 'title' => (string)$j['title'], 'level' => jd_level_label((string)$j['level']), 'discipline' => (string)($j['discipline'] ?? ''), 'rev' => jd_rev($j), 'rev_title' => (string)($j['rev_title'] ?? ''),
                'submitted_by' => (string)($j['submitted_name'] ?? ''), 'submitted_at' => (string)($j['submitted_at'] ?? ''), 'days' => self::days($j['submitted_at'] ?? null), 'people' => (int)($j['people_n'] ?? 0), 'steps' => $steps, 'mine' => (bool) array_filter($steps, fn($s) => $s['mine']), 'row' => $j]; }
        return $rows;
    }
    public static function jds(): array {
        if (!self::jdHas()) return ['items' => []];
        return ['items' => array_map(function ($r) { $c = jd_content($r['row']); unset($r['row']); $r['reports_to'] = $c['reports_to']; $r['purpose'] = $c['purpose']; $r['sections'] = []; foreach (JD_SECTIONS as $k) if ($c[$k]) $r['sections'][] = ['key' => $k, 'lines' => $c[$k]]; return $r; }, self::jdRows())];
    }
    public static function jdDecide(array $u, int $id, array $p): array {
        if (!self::jdHas()) throw new ApiException(404, 'Job descriptions are not installed');
        PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $jd = jd_load($id); if (!$jd) throw new ApiException(404, 'Job description not found'); if ($jd['status'] !== 'review') throw new ApiException(409, 'No longer in review');
        if (!empty($p['approve'])) self::guard(fn() => jd_approve($id)); else self::guard(fn() => jd_reject($id, (string)($p['note'] ?? '')));
        return ['id' => $id, 'status' => (string)(jd_load($id)['status'] ?? '')];
    }

    /* ------------------------------------------------------------------ the HR year */
    public static function year(): array {
        self::boot(); if (!function_exists('hry_has') || !hry_has()) return ['years' => []];
        $y = (int) date('Y'); $years = [];
        foreach ([$y - 1, $y] as $yy) {
            if ($yy < $y && !hry_cycle_started($yy)) continue; $steps = [];
            foreach (hry_cycle($yy) as $s) { if ($yy < $y && $s['state'] === 'done') continue;
                $steps[] = ['key' => $s['key'], 'year' => $yy, 'label' => $s['label'], 'month' => hry_month_label((int)$s['month']), 'due' => $s['due'], 'state' => $s['state'], 'late_days' => (int)$s['late_days'], 'figure' => hry_figure($s), 'auto' => !empty($s['m']['auto']),
                    'tick' => $s['tick'] ? ['by' => (string)$s['tick']['done_name'], 'at' => (string)$s['tick']['done_at'], 'note' => (string)($s['tick']['note'] ?? '')] : null]; }
            if ($steps) $years[] = ['year' => $yy, 'steps' => $steps];
        }
        return ['years' => array_reverse($years)];
    }
    public static function yearTick(array $p): array {
        self::boot(); if (!function_exists('hry_has') || !hry_has()) throw new ApiException(404, 'The HR year is not installed');
        $_POST = ['year' => (int)($p['year'] ?? 0), 'step' => (string)($p['step'] ?? ''), 'note' => (string)($p['note'] ?? '')]; if (!empty($p['undo'])) $_POST['undo'] = 1;
        self::guard(fn() => hry_tick_from_post());
        return self::year();
    }

    /* ------------------------------------------------------------------ documents that expire */
    public static function expiring(int $days): array {
        self::boot(); $days = max(30, min(120, $days)); $x = [];
        if (function_exists('hr_contract_alerts')) foreach (hr_contract_alerts($days) as $a) $x[] = ['kind' => 'contract', 'person_id' => (int)$a['id'], 'person' => (string)$a['name_en'], 'title' => '', 'date' => self::d($a['contract_end']), 'days' => (int)$a['left_days']];
        if (function_exists('hr_cert_alerts')) foreach (hr_cert_alerts($days) as $a) $x[] = ['kind' => 'certificate', 'person_id' => (int)$a['id'], 'person' => (string)$a['name_en'], 'title' => (string)($a['title'] ?? ''), 'date' => self::d($a['cert_expiry']), 'days' => (int)($a['left_days'] ?? 0)];
        if (function_exists('pmt_has') && pmt_has()) foreach (pmt_list(['state' => 'open']) as $w) { $d = pmt_days($w); if ($d > $days) continue; $who = (string)($w['person_name'] ?: $w['holder_name']); $more = pmt_people_text($w, 3);
            $x[] = ['kind' => 'permit', 'person_id' => (int)($w['person_id'] ?? 0) ?: null, 'person' => $more !== '' ? $more : $who, 'title' => trim(t('pmt.t_' . $w['permit_type']) . ' · ' . (string)($w['project_code'] ?? '')), 'date' => self::d($w['expiry_date']), 'days' => $d]; }
        if (function_exists('hre_can') && hre_can()) foreach (hre_waiting()['exp'] as $i) $x[] = ['kind' => 'equipment', 'person_id' => (int)$i['person_id'], 'person' => (string)$i['name_en'], 'title' => (string)$i['description'], 'date' => self::d($i['expires_on']), 'days' => (int) floor((strtotime((string)$i['expires_on']) - strtotime(date('Y-m-d'))) / 86400)];
        usort($x, fn($a, $b) => [$a['date'], $a['person']] <=> [$b['date'], $b['person']]);
        return ['days' => $days, 'items' => array_slice($x, 0, 400)];
    }

    /* ------------------------------------------------------------------ equipment: lists that wait */
    private static function eqOn(): void { self::boot(); if (!function_exists('hre_can') || !hre_can()) throw new ApiException(403, 'Equipment is not available for this account'); }
    private static function shapeDoc(array $d): array {
        return ['id' => (int)$d['id'], 'kind' => (string)$d['kind'], 'doc_no' => (string)$d['doc_no'], 'date' => self::d((string)$d['doc_date']), 'status' => (string)$d['status'], 'person_id' => (int)$d['person_id'], 'person' => (string)($d['name_en'] ?? ''),
            'active' => !isset($d['is_active']) || !empty($d['is_active']), 'days' => self::days((string)$d['created_at']), 'note' => (string)($d['note'] ?? ''), 'emp_at' => $d['emp_at'] ?? null, 'emp_name' => (string)($d['emp_name'] ?? ''), 'emp_remark' => (string)($d['emp_remark'] ?? '')];
    }
    public static function equipment(): array {
        self::eqOn(); $w = hre_waiting();
        return ['returns' => array_map([self::class, 'shapeDoc'], $w['return']), 'issues' => array_map([self::class, 'shapeDoc'], $w['issue']),
            'leavers' => array_map(fn($p) => ['person_id' => (int)$p['id'], 'person' => (string)$p['name_en'], 'left' => self::d($p['left_date'] ?? null), 'n' => (int)$p['n']], $w['leavers']),
            'new' => array_map(fn($p) => ['person_id' => (int)$p['id'], 'person' => (string)$p['name_en'], 'hired' => self::d($p['hire_date'] ?? null)], $w['new']),
            'results' => array_combine(HRE_RESULTS, array_map(fn($r) => t('hre.r_' . $r), HRE_RESULTS))];
    }
    public static function eqDoc(int $id): array {
        self::eqOn(); $d = hre_doc($id); if (!$d) throw new ApiException(404, 'List not found'); $p = hr_person((int)$d['person_id']);
        $o = self::shapeDoc($d + ['name_en' => (string)($p['name_en'] ?? ''), 'is_active' => $p['is_active'] ?? 1]);
        $o['items'] = array_map(fn($i) => ['id' => (int)$i['id'], 'category' => hre_cat_label((string)$i['category']), 'icon' => hre_cat_icon((string)$i['category']), 'description' => (string)$i['description'], 'serial_no' => (string)($i['serial_no'] ?? ''), 'asset_tag' => (string)($i['asset_tag'] ?? ''),
            'qty' => hre_qty($i['qty']), 'size' => (string)($i['size'] ?? ''), 'cond_out' => t('hre.k_' . $i['cond_out']), 'status' => (string)$i['status'], 'note' => (string)($i['note'] ?? '')], hre_doc_items($d));
        return $o;
    }
    /** HR accepts a handover list: every line gets its result (ok · damaged · missing · writeoff). */
    public static function eqReceive(array $u, int $id, array $p): array {
        self::eqOn(); PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $res = []; foreach ((array)($p['res'] ?? []) as $k => $v) $res[(int)$k] = (string)$v; $notes = []; foreach ((array)($p['notes'] ?? []) as $k => $v) $notes[(int)$k] = (string)$v;
        $_POST = ['doc_id' => $id, 'res' => $res, 'rnote' => $notes, 'cond_in' => []];
        self::guard(fn() => hre_receive_from_post());
        return self::eqDoc($id);
    }
    /** An issue list signed on paper by the employee: HR records the acceptance with the name on the paper. */
    public static function eqPaper(array $u, int $id, array $p): array {
        self::eqOn(); PortalPM::stepUp($u, isset($p['totp']) ? (string)$p['totp'] : null);
        $d = hre_doc($id); if (!$d || $d['kind'] !== 'issue') throw new ApiException(404, 'Issue list not found');
        self::guard(fn() => hre_accept($id, (string)($p['name'] ?? '')));
        return self::eqDoc($id);
    }
}

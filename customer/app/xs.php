<?php
/**
 * Executive summary block of the weekly and monthly project reports — GAS-CP-R96-001.
 *
 *   1  Executive summary   one paragraph (AI draft the project manager edits and releases; generated sentences until then) and six tiles:
 *                          progress actual · progress planned · SPI · drawings approved · open risks · LTI of the period
 *   2  Progress S-curve    cumulative planned vs actual per ISO week
 *   3  Milestones          plan week · forecast week · status
 *
 *   Sources
 *     actual %    average of the sub-projects' overall % (snapshot in force on the date, live for today) — the "Project average" of the weekly report
 *     planned %   work packages (planned hours spread linearly between start and end), else linear from the start of the sub-project
 *                 to its deadline; sub-projects without either are left out of the planned line
 *     SPI         actual ÷ planned (progress based — the tile always agrees with the two figures beside it)
 *     drawings    owner approvals + class submittals approved ÷ submitted
 *     risks       risk log (sp_risks) open or occurred
 *     LTI         project safety log (pm_safety), kind lti inside the period
 *     milestones  pm_milestones — entered by the project manager, pre-filled from the sub-project deadlines
 *
 *   xs_data($pid, 'week'|'month', $ref)   everything the block shows
 *   xs_html($X)  xs_pdf(...)  xs_docx(...)   the three renderings (screen / print, weekly PDF, weekly Word)
 */
declare(strict_types=1);

const XS_SF_KINDS = ['lti', 'medical', 'first_aid', 'near_miss', 'observation'];

function xs_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'pm_milestones'") && (bool) scalar("SHOW TABLES LIKE 'pm_exec_summary'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Who maintains milestones, the safety log and the summary: administrators, management, the project's manager, project managers. */
function xs_can_edit(int $pid): bool {
    if (!xs_has() || !is_staff()) return false;
    if (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())) return true;
    $uid = (int)(current_user()['id'] ?? 0);
    return $uid && ((int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]) === $uid || (function_exists('pm_is_pm') && pm_is_pm()));
}
function xs_project(int $pid): ?array { [$sc, $sa] = scope_sql('p'); return one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$sc}", array_merge([$pid], $sa)) ?: null; }
function xs_week(string $d): string { $t = strtotime($d); return 'W' . date('W', $t); }

/* ------------------------------------------------------------------ period */

/** ['kind','key' (2026-W46 | 2026-11),'from','to','label'] — $ref is any date inside the week, or YYYY-MM for a month. */
function xs_period(string $kind, string $ref): array {
    if ($kind === 'month') { $m = preg_match('/^\d{4}-\d{2}/', $ref) ? substr($ref, 0, 7) : date('Y-m'); $from = $m . '-01'; return ['kind' => 'month', 'key' => $m, 'from' => $from, 'to' => date('Y-m-t', strtotime($from)), 'label' => date('M Y', strtotime($from))]; }
    $t = strtotime(preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref) ? $ref : date('Y-m-d')) ?: time(); $mon = date('Y-m-d', $t - ((int)date('N', $t) - 1) * 86400);
    return ['kind' => 'week', 'key' => date('o', strtotime($mon)) . '-W' . date('W', strtotime($mon)), 'from' => $mon, 'to' => date('Y-m-d', strtotime($mon) + 6 * 86400), 'label' => date('o', strtotime($mon)) . '-W' . date('W', strtotime($mon))];
}

/* ------------------------------------------------------------------ milestones */

function ms_list(int $pid): array {
    if (!xs_has()) return [];
    return all('SELECT m.*, s.code AS sub_code, s.name AS sub_name FROM pm_milestones m LEFT JOIN subprojects s ON s.id = m.subproject_id WHERE m.project_id = ? ORDER BY m.plan_date, m.sort_order, m.id', [$pid]);
}
/** Pre-fill: one milestone per sub-project that has a deadline ("<code> <name> completed"); existing ones keep their forecast and follow a changed deadline only while not achieved. */
function ms_seed(int $pid): int {
    if (!xs_has()) return 0; $n = 0;
    foreach (all("SELECT id, code, name, deadline, status, completed_at FROM subprojects WHERE project_id = ? AND deadline IS NOT NULL AND status <> 'quoted'", [$pid]) as $s) {
        $title = mb_substr(trim(($s['code'] ? $s['code'] . ' ' : '') . $s['name']) . ' — ' . t('ms.completed'), 0, 200);
        $done = $s['status'] === 'completed' && !empty($s['completed_at']) ? substr((string)$s['completed_at'], 0, 10) : null;
        $ex = one("SELECT id, achieved_date FROM pm_milestones WHERE project_id = ? AND subproject_id = ? AND source = 'deadline'", [$pid, (int)$s['id']]);
        if ($ex) { if ($done && !$ex['achieved_date']) q('UPDATE pm_milestones SET achieved_date = ? WHERE id = ?', [$done, (int)$ex['id']]); continue; }
        q("INSERT INTO pm_milestones (project_id, subproject_id, title, plan_date, achieved_date, source, created_by) VALUES (?,?,?,?,?,'deadline',?)", [$pid, (int)$s['id'], $title, $s['deadline'], $done, (int)(current_user()['id'] ?? 0) ?: null]); $n++;
    }
    return $n;
}
/** Status of a milestone on a reference date: ['key' => achieved|late|slip|on_track|planned, 'label', 'tone' => green|amber|red|blue, 'weeks'] */
function ms_status(array $m, ?string $ref = null): array {
    $ref = $ref ?: date('Y-m-d'); $plan = (string)$m['plan_date']; $fc = (string)($m['forecast_date'] ?: $plan);
    if (!empty($m['achieved_date']) && $m['achieved_date'] <= $ref) return ['key' => 'achieved', 'label' => t('ms.st_achieved'), 'tone' => 'green', 'weeks' => 0];
    $slip = (int) ceil((strtotime($fc) - strtotime($plan)) / (7 * 86400));
    if ($fc < $ref) { $late = max(1, (int) ceil((strtotime($ref) - strtotime($plan)) / (7 * 86400))); return ['key' => 'late', 'label' => t('ms.st_late', ['n' => $late]), 'tone' => 'red', 'weeks' => $late]; }
    if ($slip > 0) return ['key' => 'slip', 'label' => t($slip === 1 ? 'ms.st_slip1' : 'ms.st_slip', ['n' => $slip]), 'tone' => $slip >= 3 ? 'red' : 'amber', 'weeks' => $slip];
    if (strtotime($fc) - strtotime($ref) <= 42 * 86400) return ['key' => 'on_track', 'label' => t('ms.st_on_track'), 'tone' => 'green', 'weeks' => 0];
    return ['key' => 'planned', 'label' => t('ms.st_planned'), 'tone' => 'blue', 'weeks' => 0];
}
function ms_save_from_post(): int {
    $pid = (int)($_POST['project_id'] ?? 0); if (!xs_can_edit($pid) || !xs_project($pid)) throw new RuntimeException(t('err.noaccess'));
    $d = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) && strtotime((string)$_POST[$k]) ? (string)$_POST[$k] : null;
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); $plan = $d('plan_date'); if ($title === '' || !$plan) throw new RuntimeException(t('ms.need'));
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; if ($sid && !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    $id = (int)($_POST['id'] ?? 0); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255) ?: null;
    if ($id) { if (!scalar('SELECT COUNT(*) FROM pm_milestones WHERE id = ? AND project_id = ?', [$id, $pid])) throw new RuntimeException(t('err.notfound'));
        q('UPDATE pm_milestones SET title = ?, plan_date = ?, forecast_date = ?, achieved_date = ?, note = ? WHERE id = ?', [$title, $plan, $d('forecast_date'), $d('achieved_date'), $note, $id]); }
    else q("INSERT INTO pm_milestones (project_id, subproject_id, title, plan_date, forecast_date, achieved_date, note, source, created_by) VALUES (?,NULL,?,?,?,?,?,'manual',?)", [$pid, $title, $plan, $d('forecast_date'), $d('achieved_date'), $note, (int)(current_user()['id'] ?? 0) ?: null]);
    return $pid;
}
function ms_delete(int $id): int {
    $m = one('SELECT id, project_id FROM pm_milestones WHERE id = ?', [$id]); if (!$m || !xs_can_edit((int)$m['project_id'])) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM pm_milestones WHERE id = ?', [$id]); return (int)$m['project_id'];
}

/* ------------------------------------------------------------------ safety log */

function sf_kinds(): array { $o = []; foreach (XS_SF_KINDS as $k) $o[$k] = t('sf.k_' . $k); return $o; }
function sf_list(int $pid, ?string $from = null, ?string $to = null): array {
    if (!xs_has()) return [];
    return all('SELECT f.*, s.code AS sub_code FROM pm_safety f LEFT JOIN subprojects s ON s.id = f.subproject_id WHERE f.project_id = ?' . ($from ? ' AND f.event_date BETWEEN ? AND ?' : '') . ' ORDER BY f.event_date DESC, f.id DESC', $from ? [$pid, $from, $to] : [$pid]);
}
function sf_save_from_post(): int {
    $pid = (int)($_POST['project_id'] ?? 0); if (!xs_can_edit($pid) || !xs_project($pid)) throw new RuntimeException(t('err.noaccess'));
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['event_date'] ?? '')) && strtotime((string)$_POST['event_date']) ? (string)$_POST['event_date'] : null;
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if (!$date || $title === '' || $date > date('Y-m-d')) throw new RuntimeException(t('sf.need'));
    $kind = in_array($_POST['kind'] ?? '', XS_SF_KINDS, true) ? $_POST['kind'] : 'near_miss'; $u = current_user();
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; if ($sid && !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
    q('INSERT INTO pm_safety (project_id, subproject_id, event_date, kind, title, description, action_taken, lost_days, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$pid, $sid, $date, $kind, $title, mb_substr(trim((string)($_POST['description'] ?? '')), 0, 4000) ?: null, mb_substr(trim((string)($_POST['action_taken'] ?? '')), 0, 4000) ?: null, $kind === 'lti' ? max(0, min(999, (int)($_POST['lost_days'] ?? 0))) : 0, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
    return $pid;
}
function sf_delete(int $id): int {
    $m = one('SELECT id, project_id FROM pm_safety WHERE id = ?', [$id]); if (!$m || !xs_can_edit((int)$m['project_id'])) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM pm_safety WHERE id = ?', [$id]); return (int)$m['project_id'];
}

/* ------------------------------------------------------------------ progress: actual and planned */

/** The sub-projects of the project, equal weights — the same set and the same average as the "Project average" row of the weekly report, so the tile and the table agree. */
function xs_subs(int $pid): array {
    $subs = all('SELECT * FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
    foreach ($subs as &$s) $s['_w'] = 1.0; unset($s);
    return $subs;
}
/** A calculator over the project's sub-projects: ->actual($date) and ->planned($date) in % (null when unknown). Loads snapshots, work packages and start dates once. */
function xs_calc(int $pid): array {
    $subs = xs_subs($pid); $today = date('Y-m-d'); $cols = array_column(stage_defs(), 'col');
    $snaps = []; $works = []; $start = [];
    if ($subs) {
        $ids = implode(',', array_map(fn($s) => (int)$s['id'], $subs));
        foreach (all("SELECT * FROM sp_progress_snapshots WHERE subproject_id IN ({$ids}) ORDER BY snap_date") as $r) $snaps[(int)$r['subproject_id']][] = $r;
        try { foreach (all("SELECT subproject_id, planned_hours, start_date, end_date FROM sp_works WHERE subproject_id IN ({$ids}) AND status <> 'cancelled' AND planned_hours > 0 AND start_date IS NOT NULL AND end_date IS NOT NULL") as $r) $works[(int)$r['subproject_id']][] = $r; } catch (Throwable $e) {}
        try { foreach (all("SELECT subproject_id, MIN(work_date) d FROM timesheet_lines WHERE subproject_id IN ({$ids}) GROUP BY subproject_id") as $r) if ($r['d']) $start[(int)$r['subproject_id']] = (string)$r['d']; } catch (Throwable $e) {}
        $pc = substr((string) scalar('SELECT created_at FROM projects WHERE id = ?', [$pid]), 0, 10);
        foreach ($subs as $s) { $k = (int)$s['id']; $c = array_filter([$start[$k] ?? null, $snaps[$k][0]['snap_date'] ?? null, substr((string)($s['created_at'] ?? ''), 0, 10) ?: null, $pc ?: null]); $start[$k] = $c ? min($c) : $today; }
    }
    // $strict = history point of the curve: only what a snapshot recorded by that date (nothing recorded → no point);
    // otherwise the figure of the report: the snapshot in force, else today's values — exactly as the weekly report's progress table reads it
    $actual = function (string $d, bool $strict = false) use ($subs, $snaps, $today): ?float {
        if (!$subs) return null; $vals = []; $known = false;
        foreach ($subs as $s) { $k = (int)$s['id']; $row = null;
            if ($d >= $today) $row = $s; else { foreach ($snaps[$k] ?? [] as $r) { if ($r['snap_date'] <= $d) $row = $r; else break; } if (!$row && !$strict) $row = $s; }
            if ($row) $known = true;
            $vals[] = $row ? overall_pct($row) : 0; }
        return $known ? (float) pct_avg($vals) : null;
    };
    $planned = function (string $d) use ($subs, $works, $start): ?float {
        $sum = 0.0; $w = 0.0; $t = strtotime($d);
        foreach ($subs as $s) { $k = (int)$s['id']; $f = null;
            if (!empty($works[$k])) { $tot = 0.0; $done = 0.0; foreach ($works[$k] as $x) { $a = strtotime((string)$x['start_date']); $b = strtotime((string)$x['end_date']) + 86400; $h = (float)$x['planned_hours']; $tot += $h; $done += $h * ($b > $a ? max(0.0, min(1.0, ($t + 86400 - $a) / ($b - $a))) : ($t >= $a ? 1.0 : 0.0)); } if ($tot > 0) $f = $done / $tot; }
            if ($f === null && !empty($s['deadline'])) { $a = strtotime($start[$k]); $b = strtotime((string)$s['deadline']); $f = $b > $a ? max(0.0, min(1.0, ($t - $a) / ($b - $a))) : ($t >= $b ? 1.0 : 0.0); }
            if ($f === null && $s['status'] === 'completed') $f = (substr((string)($s['completed_at'] ?? ''), 0, 10) ?: '0000-00-00') <= $d ? 1.0 : null;   // completed without a plan: planned = done
            if ($f === null) continue;
            $sum += $s['_w'] * $f * 100; $w += $s['_w']; }
        return $w > 0 ? $sum / $w : null;
    };
    return ['subs' => $subs, 'actual' => $actual, 'planned' => $planned];
}

/* ------------------------------------------------------------------ summary text */

function xs_summary_get(int $pid, string $key): ?array { return xs_has() ? (one('SELECT * FROM pm_exec_summary WHERE project_id = ? AND period = ?', [$pid, $key]) ?: null) : null; }
function xs_summary_save(int $pid, string $key, string $body, string $status, bool $ai = false): void {
    if (!xs_can_edit($pid) || !preg_match('/^\d{4}-(W\d{2}|\d{2})$/', $key)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $body = mb_substr(trim($body), 0, 4000);
    if ($body === '') { q('DELETE FROM pm_exec_summary WHERE project_id = ? AND period = ?', [$pid, $key]); return; }
    q('INSERT INTO pm_exec_summary (project_id, period, body, status, ai, updated_by, updated_name) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE body = VALUES(body), status = VALUES(status), ai = VALUES(ai), updated_by = VALUES(updated_by), updated_name = VALUES(updated_name)',
      [$pid, $key, $body, $status === 'final' ? 'final' : 'draft', (int)$ai, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
}
/** The generated sentences: progress against plan, milestones of the period, risks, safety. Used until the project manager releases a text, and as the facts given to the assistant. */
function xs_auto_text(array $X): string {
    $p = []; $k = $X['kpi'];
    if ($k['actual'] !== null && $k['planned'] !== null) { $diff = (int) round($k['actual']) - (int) round($k['planned']); $p[] = t('xs.lead', ['a' => (int) round($k['actual']), 'p' => (int) round($k['planned']), 'd' => ($diff > 0 ? '+' : ($diff < 0 ? '−' : '')) . abs($diff), 'tol' => t($diff >= 0 ? 'xs.tol_ahead' : ($k['spi_tone'] === 'red' ? 'xs.tol_out' : 'xs.tol_in'))]); }
    elseif ($k['actual'] !== null) $p[] = t('xs.lead_noplan', ['a' => (int) round($k['actual'])]);
    $ach = array_filter($X['milestones'], fn($m) => !empty($m['achieved_date']) && $m['achieved_date'] >= $X['period']['from'] && $m['achieved_date'] <= $X['period']['to']);
    if ($ach) $p[] = t('xs.s_achieved', ['l' => implode('; ', array_map(fn($m) => (string)$m['title'], $ach))]);
    $slip = array_filter($X['milestones'], fn($m) => in_array($m['st']['key'], ['slip', 'late'], true));
    if ($slip) $p[] = t('xs.s_slipped', ['l' => implode('; ', array_map(fn($m) => $m['title'] . ' (' . $m['st']['label'] . ')', $slip))]);
    $p[] = $k['risks'] ? t('xs.s_risks', ['n' => $k['risks'], 'h' => $k['risks_high']]) : t('xs.s_norisks');
    $p[] = $k['lti'] ? t('xs.s_lti', ['n' => $k['lti']]) : ($X['safety'] ? t('xs.s_safety', ['n' => count($X['safety'])]) : t('xs.s_nosafety'));
    return implode(' ', $p);
}
/** Ask the assistant for the paragraph; saved as a DRAFT (customers keep seeing the generated sentences until the project manager saves it). Only project facts leave the portal — no personal or payroll data. */
function xs_ai_draft(int $pid, string $kind, string $ref): array {
    if (!xs_can_edit($pid)) throw new RuntimeException(t('err.noaccess'));
    $page = null; foreach (['weekly_vessel', 'monthly', 'weekly'] as $pg) if (function_exists('ai_can') && ai_can($pg)) { $page = $pg; break; }
    if (!$page) throw new RuntimeException(t('xs.ai_off'));
    $X = xs_data($pid, $kind, $ref, true); if (!$X) throw new RuntimeException(t('err.notfound')); $P = $X['period'];
    $L = ['Project: ' . trim(($X['project']['project_code'] ?: '') . ' ' . $X['project']['name']) . ($X['project']['vessel'] ? ' · vessel ' . $X['project']['vessel'] : ''), 'Report period: ' . ($kind === 'month' ? 'month ' : 'week ') . $P['label'] . ' (' . $P['from'] . ' to ' . $P['to'] . ')', 'Figures: ' . xs_auto_text($X)];
    $L[] = 'Tiles: progress actual ' . xs_fmt_pct($X['kpi']['actual']) . ', planned ' . xs_fmt_pct($X['kpi']['planned']) . ', SPI ' . ($X['kpi']['spi'] !== null ? number_format($X['kpi']['spi'], 2) : 'n/a') . ', drawings approved ' . xs_fmt_pct($X['kpi']['drawings']) . ', open risks ' . $X['kpi']['risks'] . ', LTI in the period ' . $X['kpi']['lti'] . '.';
    if ($X['milestones']) { $L[] = 'Milestones (title | plan | forecast | status):'; foreach ($X['milestones'] as $m) $L[] = '- ' . $m['title'] . ' | ' . xs_week((string)$m['plan_date']) . ' | ' . xs_week((string)($m['forecast_date'] ?: $m['plan_date'])) . ' | ' . $m['st']['label'] . ($m['note'] ? ' | ' . $m['note'] : ''); }
    $risks = function_exists('rk_has') && rk_has() ? all("SELECT risk_no, title, status, likelihood, impact, mitigation, identified_at FROM sp_risks WHERE project_id = ? AND status IN ('open','occurred') ORDER BY likelihood * impact DESC LIMIT 12", [$pid]) : [];
    if ($risks) { $L[] = 'Open risks (no | title | score | raised | mitigation):'; foreach ($risks as $r) $L[] = '- ' . $r['risk_no'] . ' | ' . $r['title'] . ' | ' . ((int)$r['likelihood'] * (int)$r['impact']) . ' | ' . $r['identified_at'] . ' | ' . mb_substr((string)$r['mitigation'], 0, 300); }
    if ($X['safety']) { $L[] = 'Safety events in the period:'; foreach ($X['safety'] as $f) $L[] = '- ' . $f['event_date'] . ' | ' . $f['kind'] . ' | ' . $f['title']; }
    $notes = all("SELECT n.note_date, n.body, s.code FROM sp_notes n JOIN subprojects s ON s.id = n.subproject_id WHERE s.project_id = ? AND n.note_date BETWEEN ? AND ? AND n.visibility = 'external' ORDER BY n.note_date LIMIT 40", [$pid, $P['from'], $P['to']]);
    if ($notes) { $L[] = 'Site notes of the period (customer-visible):'; foreach ($notes as $n) $L[] = '- ' . $n['note_date'] . ' ' . $n['code'] . ': ' . mb_substr(function_exists('rt_plain') ? rt_plain((string)$n['body']) : strip_tags((string)$n['body']), 0, 500); }
    try { foreach (all('SELECT week_start, delays, remarks FROM weekly_notes WHERE project_id = ? AND week_start BETWEEN ? AND ?', [$pid, date('Y-m-d', strtotime($P['from']) - 6 * 86400), $P['to']]) as $w) { if ($w['delays']) $L[] = 'PM — foreseen delays (week of ' . $w['week_start'] . '): ' . mb_substr((string)$w['delays'], 0, 800); if ($w['remarks']) $L[] = 'PM — remarks (week of ' . $w['week_start'] . '): ' . mb_substr((string)$w['remarks'], 0, 800); } } catch (Throwable $e) {}
    $prompt = "Write the EXECUTIVE SUMMARY paragraph of the " . ($kind === 'month' ? 'monthly' : 'weekly') . " project progress report that Golden A/S sends to the owner's representative and the yard. One paragraph, 70 to 120 words, plain text. "
        . "Order: (1) progress actual against planned with the deviation in points and whether it is within tolerance — copy the figures exactly; (2) what was achieved in the period, from the milestones and the site notes; (3) risks raised or changed and the recovery plan, if the data gives one; (4) safety — say \"No safety incidents.\" when none; (5) owner decisions overdue only if the data mentions them. "
        . "Use only the facts below; do not invent dates, quantities, names or causes; leave out a point the data does not cover. No headings, no bullet points, no greeting.\n\n" . implode("\n", $L);
    $r = ai_run('write', ['title' => t('xs.title'), 'headline' => trim(($X['project']['project_code'] ?: '') . ' ' . $P['label']), 'page' => $page], $prompt, null, $page);
    if (empty($r['ok'])) return $r;
    $text = mb_substr(trim(strip_tags((string)$r['text'])), 0, 4000); if ($text === '') return ['ok' => false, 'error' => t('ai.failed')];
    xs_summary_save($pid, $P['key'], $text, 'draft', true);
    return ['ok' => true, 'text' => $text];
}

/* ------------------------------------------------------------------ the block's data */

function xs_fmt_pct(?float $v): string { return $v === null ? '—' : (int) round($v) . ' %'; }
/**
 * Everything the block shows for a project and a period. $staff = internal view (drafts visible).
 * ['project','period','kpi' => [actual, planned, spi, spi_tone, drawings, risks, risks_high, lti], 'curve' => [['w','date','planned','actual'], …],
 *  'milestones' => rows + 'st', 'safety' => events of the period, 'summary' => ['text','source' => final|draft|auto,'row'], 'can_edit']
 */
function xs_data(int $pid, string $kind, string $ref, ?bool $staff = null): ?array {
    if (!xs_has()) return null; $p = xs_project($pid); if (!$p) return null;
    $staff = $staff ?? is_staff(); $P = xs_period($kind, $ref); $today = date('Y-m-d'); $asOf = min($P['to'], $today);
    $C = xs_calc($pid); $act = $C['actual']($asOf); $pln = $C['planned']($asOf);
    $spi = $act !== null && $pln !== null && $pln > 0.5 ? round($act) / max(1.0, round($pln)) : null;
    $spiTone = $spi === null ? null : (function_exists('kpi_tone') && function_exists('kpi_has') && kpi_has() ? kpi_tone('spi', $spi) : ($spi >= 1 ? 'green' : ($spi >= 0.9 ? 'amber' : 'red')));
    // drawings approved: owner approvals + class submittals, approved ÷ submitted (drafts excluded)
    $drw = null; try { $d = one("SELECT (SELECT COUNT(*) FROM owner_approvals WHERE project_id = ? AND status <> 'draft') + (SELECT COUNT(*) FROM class_submittals WHERE project_id = ? AND status <> 'draft') tot, (SELECT COUNT(*) FROM owner_approvals WHERE project_id = ? AND status LIKE 'approved%') + (SELECT COUNT(*) FROM class_submittals WHERE project_id = ? AND status LIKE 'approved%') ok", [$pid, $pid, $pid, $pid]); if ($d && (int)$d['tot'] > 0) $drw = (int)$d['ok'] / (int)$d['tot'] * 100; } catch (Throwable $e) {}
    $rk = ['n' => 0, 'h' => 0]; try { if (function_exists('rk_has') && rk_has()) { $r = one("SELECT COUNT(*) n, COALESCE(SUM(likelihood * impact >= 15),0) h FROM sp_risks WHERE project_id = ? AND status IN ('open','occurred')", [$pid]); $rk = ['n' => (int)$r['n'], 'h' => (int)$r['h']]; } } catch (Throwable $e) {}
    $safety = sf_list($pid, $P['from'], $P['to']); $lti = count(array_filter($safety, fn($f) => $f['kind'] === 'lti'));
    // S-curve: 12 ISO weeks — eight up to the period, four ahead; planned on every week, actual up to the report date
    $mon = strtotime(xs_period('week', $asOf)['from']); $curve = [];
    for ($i = -7; $i <= 4; $i++) { $ws = $mon + $i * 7 * 86400; $we = date('Y-m-d', $ws + 6 * 86400); $d = $i === 0 ? $asOf : $we;
        $curve[] = ['w' => 'W' . date('W', $ws), 'date' => $d, 'planned' => $C['planned']($d), 'actual' => $i <= 0 ? $C['actual']($d, $i < 0) : null]; }
    // milestones: open ones and those achieved in the last eight weeks (at most ten rows, by plan date)
    $ms = []; foreach (ms_list($pid) as $m) { $m['st'] = ms_status($m, $asOf); if ($m['st']['key'] === 'achieved' && $m['achieved_date'] < date('Y-m-d', strtotime($asOf) - 56 * 86400)) continue; $ms[] = $m; }
    $ms = array_slice($ms, 0, 10);
    $X = ['project' => $p, 'period' => $P, 'as_of' => $asOf, 'kind' => $kind, 'kpi' => ['actual' => $act, 'planned' => $pln, 'spi' => $spi, 'spi_tone' => $spiTone, 'drawings' => $drw, 'risks' => $rk['n'], 'risks_high' => $rk['h'], 'lti' => $lti],
          'curve' => $curve, 'milestones' => $ms, 'safety' => $safety, 'can_edit' => $staff && xs_can_edit($pid), 'n_subs' => count($C['subs'])];
    $row = xs_summary_get($pid, $P['key']); $auto = xs_auto_text($X);
    $X['summary'] = $row && $row['status'] === 'final' ? ['text' => (string)$row['body'], 'source' => 'final', 'row' => $row] : ['text' => $auto, 'source' => $row ? 'draft' : 'auto', 'row' => $row];
    $X['auto_text'] = $auto;
    return $X;
}
/** The six tiles: [label, value, tone]. */
function xs_tiles(array $X): array {
    $k = $X['kpi']; $per = $X['kind'] === 'month' ? t('xs.k_lti_month') : t('xs.k_lti_week');
    return [[t('xs.k_actual'), xs_fmt_pct($k['actual']), 'navy'], [t('xs.k_planned'), xs_fmt_pct($k['planned']), 'navy'], [t('kpi.spi'), $k['spi'] !== null ? number_format($k['spi'], 2, '.', '') : '—', $k['spi_tone'] ?: 'navy'],
            [t('xs.k_drawings'), xs_fmt_pct($k['drawings']), $k['drawings'] === null ? 'navy' : ($k['drawings'] >= 90 ? 'green' : ($k['drawings'] >= 70 ? 'amber' : 'red'))],
            [t('xs.k_risks'), (string)$k['risks'], $k['risks_high'] ? 'red' : ($k['risks'] ? 'amber' : 'green')], [$per, (string)$k['lti'], $k['lti'] ? 'red' : 'green']];
}
function xs_tone_hex(string $tone): string { return ['green' => '#2E7D32', 'amber' => '#B7791F', 'red' => '#9B2C2C', 'blue' => '#1F4E8C', 'navy' => '#1F3A5F'][$tone] ?? '#1F3A5F'; }

/* ------------------------------------------------------------------ rendering: screen / print */

/** The S-curve as inline SVG (symmetrical frame, orthogonal grid; planned dashed gold, actual solid navy). */
function xs_svg(array $X, int $w = 600, int $h = 250): string {
    $c = $X['curve']; $n = count($c); $l = 52; $r = 16; $t = 30; $b = 34; $pw = $w - $l - $r; $ph = $h - $t - $b;
    $x = fn(int $i) => $l + ($n > 1 ? $pw * $i / ($n - 1) : 0); $y = fn(float $v) => $t + $ph * (1 - max(0, min(100, $v)) / 100);
    $s = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" aria-label="' . e(t('xs.curve')) . '" style="display:block;max-width:100%;height:auto;font-family:Aptos,Calibri,Arial,sans-serif;">';
    foreach ([0, 25, 50, 75, 100] as $g) { $yy = round($y($g), 1); $s .= '<line x1="' . $l . '" y1="' . $yy . '" x2="' . ($w - $r) . '" y2="' . $yy . '" style="stroke:#D9DEE5;stroke-width:1"/><text x="' . ($l - 6) . '" y="' . ($yy + 4.5) . '" text-anchor="end" style="font-size:12.5px;fill:#6B7280">' . $g . '%</text>'; }
    foreach ($c as $i => $pt) $s .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 11) . '" text-anchor="middle" style="font-size:12.5px;fill:#6B7280">' . e($pt['w']) . '</text>';
    $path = function (string $key) use ($c, $x, $y): string { $d = ''; foreach ($c as $i => $pt) { if ($pt[$key] === null) continue; $d .= ($d === '' ? 'M' : ' L') . round($x($i), 1) . ' ' . round($y((float)$pt[$key]), 1); } return $d; };
    $pp = $path('planned'); $pa = $path('actual');
    if ($pp !== '') $s .= '<path d="' . $pp . '" style="fill:none;stroke:#C9A227;stroke-width:2.4;stroke-dasharray:6 5"/>';
    if ($pa !== '') $s .= '<path d="' . $pa . '" style="fill:none;stroke:#1F3A5F;stroke-width:2.8;stroke-linecap:round;stroke-linejoin:round"/>';
    $lx = $w - $r - 176; $s .= '<line x1="' . $lx . '" y1="12" x2="' . ($lx + 18) . '" y2="12" style="stroke:#1F3A5F;stroke-width:2.8"/><text x="' . ($lx + 23) . '" y="16.5" style="font-size:12.5px;fill:#374151">' . e(t('xs.actual')) . '</text>'
        . '<line x1="' . ($lx + 88) . '" y1="12" x2="' . ($lx + 106) . '" y2="12" style="stroke:#C9A227;stroke-width:2.4;stroke-dasharray:5 4"/><text x="' . ($lx + 111) . '" y="16.5" style="font-size:12.5px;fill:#374151">' . e(t('xs.planned')) . '</text>';
    return $s . '</svg>';
}
/** The block for the screen and the printed monthly report. $opt: 'fig' => figure number, 'head' => prefix for the titles (project code when a vessel report has several projects). */
function xs_html(array $X, array $opt = []): string {
    $fig = (int)($opt['fig'] ?? 1); $pfx = (string)($opt['head'] ?? ''); $c = $X['curve'];
    $o = '<div class="xs">';
    $o .= '<h2 class="xs-h">' . e($pfx . t('xs.title')) . '</h2><div class="xs-sum">' . (function_exists('rt_html') ? rt_html((string)$X['summary']['text']) : nl2br(e((string)$X['summary']['text']))) . '</div>';
    $o .= '<div class="xs-tiles">'; foreach (xs_tiles($X) as [$lab, $val, $tone]) $o .= '<div class="xs-tile"><b style="color:' . xs_tone_hex($tone) . '">' . e($val) . '</b><span>' . e($lab) . '</span></div>'; $o .= '</div>';
    $o .= '<div class="xs-two"><div><h2 class="xs-h">' . e(t('xs.curve')) . '</h2>';
    $o .= $X['kpi']['actual'] === null && $X['kpi']['planned'] === null ? '<p class="hint">' . e(t('xs.curve_none')) . '</p>' : xs_svg($X) . '<p class="xs-cap"><b>' . e(t('rp.figure')) . ' ' . $fig . '.</b> ' . e(t('xs.curve_cap', ['a' => $c[0]['w'], 'b' => $c[count($c) - 1]['w']])) . '</p>';
    $o .= '</div><div><h2 class="xs-h">' . e(t('ms.title')) . '</h2>';
    if (!$X['milestones']) $o .= '<p class="hint">' . e(t('ms.none')) . '</p>';
    else { $o .= '<table class="xs-ms" data-nosort><thead><tr><th>' . e(t('ms.one')) . '</th><th>' . e(t('ms.plan')) . '</th><th>' . e(t('ms.forecast')) . '</th><th>' . e(t('th.status')) . '</th></tr></thead><tbody>';
        foreach ($X['milestones'] as $m) $o .= '<tr><td>' . e((string)$m['title']) . '</td><td>' . e(xs_week((string)$m['plan_date'])) . '</td><td>' . e(xs_week((string)($m['achieved_date'] ?: ($m['forecast_date'] ?: $m['plan_date'])))) . '</td><td><span class="xs-pill xs-' . e($m['st']['tone']) . '">' . e($m['st']['label']) . '</span></td></tr>';
        $o .= '</tbody></table>'; }
    return $o . '</div></div></div>';
}
/** Staff only, below the block: the summary editor (AI draft, save / release, back to generated) and the links to milestones and the safety log. */
function xs_editor_html(array $X, string $back, array $ret = []): string {
    if (!$X['can_edit']) return '';
    $pid = (int)$X['project']['id']; $row = $X['summary']['row']; $src = $X['summary']['source']; $aiOn = false; foreach (['weekly_vessel', 'monthly', 'weekly'] as $pg) if (function_exists('ai_can') && ai_can($pg)) $aiOn = true;
    $hid = csrf_field() . '<input type="hidden" name="project_id" value="' . $pid . '"><input type="hidden" name="kind" value="' . e($X['kind']) . '"><input type="hidden" name="ref" value="' . e($X['kind'] === 'month' ? $X['period']['key'] : $X['period']['from']) . '"><input type="hidden" name="ret_vessel" value="' . (int)($ret['vessel'] ?? 0) . '"><input type="hidden" name="ret_only" value="' . (int)($ret['only'] ?? 0) . '">';
    $o = '<div class="card no-print xs-edit" id="xs-edit-' . $pid . '"><div class="card-head"><h2>✎ ' . e(t('xs.edit_h')) . ' <span class="hint">· ' . e(trim(($X['project']['project_code'] ?: '') . ' ' . $X['period']['label'])) . '</span></h2><span class="hint">'
       . ($src === 'final' ? pill('green', t('xs.src_final')) : ($src === 'draft' ? pill('gold', t(!empty($row['ai']) ? 'xs.src_ai' : 'xs.src_draft')) : pill('mute', t('xs.src_auto')))) . ($row ? ' · ' . e((string)$row['updated_name']) . ' · ' . e(fmt_datetime((string)$row['updated_at'])) : '') . '</span></div>';
    $o .= '<p class="hint">' . e(t('xs.edit_hint')) . '</p>';
    $o .= '<form method="post" action="' . e($back) . '" data-lock>' . $hid . '<input type="hidden" name="action" value="xs_save"><div class="field"><textarea name="body" rows="5" maxlength="4000" placeholder="' . e($X['auto_text']) . '">' . e((string)($row['body'] ?? '')) . '</textarea></div>'
       . '<div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="status" value="final">✔ ' . e(t('xs.release')) . '</button><button class="btn btn--ghost btn--sm" type="submit" name="status" value="draft">' . e(t('xs.save_draft')) . '</button>'
       . '<a class="btn btn--ghost btn--sm" href="' . url('project_ms', ['project' => $pid]) . '">🏁 ' . e(t('ms.title')) . ' · 🦺 ' . e(t('sf.title')) . '</a></div></form>';
    $o .= '<form method="post" action="' . e($back) . '" data-lock style="margin-top:8px;">' . $hid . '<input type="hidden" name="action" value="xs_ai"><div class="btn-row">' . ($aiOn ? '<button class="btn btn--sm" type="submit">✨ ' . e(t('xs.ai_btn')) . '</button><span class="hint">' . e(t('xs.ai_hint')) . '</span>' : '<span class="hint">' . e(t('xs.ai_off')) . '</span>') . '</div></form>';
    return $o . '</div>';
}

/* ------------------------------------------------------------------ rendering: weekly PDF and Word */

/** Draw the block into the weekly PDF at $y (mm); $need(mm) breaks the page. */
function xs_pdf($pdf, array $X, float $L, float $Wd, float &$y, callable $need, string $pfx = '', int $fig = 1): void {
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2937'; $grid = '#D9DEE5'; $head = '#EEF1F4'; $mute = '#6B7280';
    $h = function (string $s) use ($pdf, $navy, $gold, $L, &$y, $need) { $need(14); $pdf->setColor($gold); $pdf->rect($L, $y + 0.6, 0.9, 5.2, 'F'); $pdf->setColor($navy); $pdf->setFont('B', 10); $pdf->text($L + 3, $y + 4.8, mb_strtoupper($s)); $y += 8.5; };
    $h($pfx . t('xs.title'));
    $pdf->setFont('R', 8.4); $txt = function_exists('rt_plain') ? rt_plain((string)$X['summary']['text']) : (string)$X['summary']['text']; $need($pdf->paragraphHeight($Wd, $txt, 4.0) + 4); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $Wd, $txt, 4.0) + 3;
    // tiles
    $tiles = xs_tiles($X); $tw = ($Wd - 5 * 3) / 6; $need(20); $x = $L;
    foreach ($tiles as [$lab, $val, $tone]) { $pdf->setColor($grid); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $tw, 16); $pdf->setColor(xs_tone_hex($tone)); $pdf->setFont('B', 13); $pdf->text($x + 2.5, $y + 7.5, $val); $pdf->setColor($mute); $pdf->setFont('R', 6.2); $ll = $pdf->wrap(mb_strtoupper($lab), $tw - 4); foreach (array_slice($ll, 0, 2) as $i => $ln) $pdf->text($x + 2.5, $y + 11.6 + $i * 2.7, $ln); $x += $tw + 3; }
    $y += 20;
    // S-curve
    $h(t('xs.curve')); $c = $X['curve']; $n = count($c);
    if ($X['kpi']['actual'] === null && $X['kpi']['planned'] === null) { $pdf->setFont('R', 8); $pdf->setColor($mute); $pdf->text($L, $y + 3, t('xs.curve_none')); $y += 8; }
    else {
        $ch = 46.0; $need($ch + 16); $pl = $L + 11; $pw = $Wd - 15; $top = $y + 4; $xx = fn(int $i) => $pl + ($n > 1 ? $pw * $i / ($n - 1) : 0); $yy = fn(float $v) => $top + $ch * (1 - max(0, min(100, $v)) / 100);
        $pdf->setFont('R', 6.4);
        foreach ([0, 25, 50, 75, 100] as $g) { $pdf->setColor($grid); $pdf->setLineWidth(0.2); $pdf->line($pl, $yy($g), $pl + $pw, $yy($g)); $pdf->setColor($mute); $pdf->text($L, $yy($g) + 1, $g . '%', 'R', 9.5); }
        foreach ($c as $i => $pt) { $pdf->setColor($mute); $pdf->text($xx($i) - 6, $top + $ch + 4.5, $pt['w'], 'C', 12); }
        $prev = null; $pdf->setColor($gold); $pdf->setLineWidth(0.55);   // planned: dashed (drawn as short segments)
        foreach ($c as $i => $pt) { if ($pt['planned'] === null) { $prev = null; continue; } $cur = [$xx($i), $yy((float)$pt['planned'])]; if ($prev) { $len = hypot($cur[0] - $prev[0], $cur[1] - $prev[1]); $steps = max(1, (int) floor($len / 2.6)); for ($k = 0; $k < $steps; $k++) { $a = $k / $steps; $b = min(1.0, ($k + 0.55) / $steps); $pdf->line($prev[0] + ($cur[0] - $prev[0]) * $a, $prev[1] + ($cur[1] - $prev[1]) * $a, $prev[0] + ($cur[0] - $prev[0]) * $b, $prev[1] + ($cur[1] - $prev[1]) * $b); } } $prev = $cur; }
        $prev = null; $pdf->setColor($navy); $pdf->setLineWidth(0.8);
        foreach ($c as $i => $pt) { if ($pt['actual'] === null) continue; $cur = [$xx($i), $yy((float)$pt['actual'])]; if ($prev) $pdf->line($prev[0], $prev[1], $cur[0], $cur[1]); $prev = $cur; }
        $lx = $pl + $pw - 44; $pdf->setColor($navy); $pdf->setLineWidth(0.8); $pdf->line($lx, $y + 1, $lx + 6, $y + 1); $pdf->setColor($ink); $pdf->setFont('R', 6.6); $pdf->text($lx + 7.5, $y + 2, t('xs.actual'));
        $pdf->setColor($gold); $pdf->setLineWidth(0.55); $pdf->line($lx + 22, $y + 1, $lx + 24.2, $y + 1); $pdf->line($lx + 25.4, $y + 1, $lx + 28, $y + 1); $pdf->setColor($ink); $pdf->text($lx + 29.5, $y + 2, t('xs.planned'));
        $y = $top + $ch + 8; $pdf->setColor($ink); $pdf->setFont('B', 7.4); $cap = t('rp.figure') . ' ' . $fig . '. '; $pdf->text($L, $y + 2.5, $cap); $pdf->setFont('R', 7.4); $pdf->text($L + $pdf->width($cap, 'B', 7.4) + 0.5, $y + 2.5, t('xs.curve_cap', ['a' => $c[0]['w'], 'b' => $c[$n - 1]['w']])); $y += 7;
    }
    // milestones
    $h(t('ms.title'));
    if (!$X['milestones']) { $pdf->setFont('R', 8); $pdf->setColor($mute); $pdf->text($L, $y + 3, t('ms.none')); $y += 8; return; }
    $cw = [$Wd - 78, 20, 24, 34]; $labs = [t('ms.one'), t('ms.plan'), t('ms.forecast'), t('th.status')];
    $need(8 + 6); $pdf->setColor($navy); $pdf->rect($L, $y, $Wd, 6.4, 'F'); $pdf->setColor('#FFFFFF'); $pdf->setFont('B', 7.2); $x = $L; foreach ($labs as $i => $lab) { $pdf->text($x + 2, $y + 4.3, mb_strtoupper($lab)); $x += $cw[$i]; } $y += 6.4;
    foreach ($X['milestones'] as $m) {
        $pdf->setFont('R', 7.8); $lines = $pdf->wrap((string)$m['title'], $cw[0] - 4); $hh = max(6.0, count($lines) * 3.7 + 2.2); $need($hh + 1);
        $pdf->setColor($ink); foreach ($lines as $k => $ln) $pdf->text($L + 2, $y + 4.1 + $k * 3.7, $ln);
        $pdf->text($L + $cw[0] + 2, $y + 4.1, xs_week((string)$m['plan_date'])); $pdf->text($L + $cw[0] + $cw[1] + 2, $y + 4.1, xs_week((string)($m['achieved_date'] ?: ($m['forecast_date'] ?: $m['plan_date']))));
        $pdf->setColor(xs_tone_hex($m['st']['tone'])); $pdf->setFont('B', 7.6); $pdf->text($L + $cw[0] + $cw[1] + $cw[2] + 2, $y + 4.1, $m['st']['label']);
        $pdf->setColor($grid); $pdf->setLineWidth(0.2); $pdf->line($L, $y + $hh, $L + $Wd, $y + $hh); $y += $hh;
    }
    $y += 4;
}
/** The block in the weekly Word document: paragraph, tiles as a grid, the S-curve as a table of weeks (Word has no vector chart here), milestones. */
function xs_docx($w, array $X, string $pfx = ''): void {
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4';
    $w->heading($pfx . t('xs.title'), 2); $w->para((string)$X['summary']['text'], ['sz' => 9.5]);
    $tiles = xs_tiles($X); $w->grid([array_map(fn($t) => ['lines' => [$t[1], mb_strtoupper($t[0])], 'b' => true, 'sz' => 9, 'color' => ltrim(xs_tone_hex($t[2]), '#'), 'align' => 'center'], $tiles)], array_fill(0, 6, 1606), ['border' => 'D9DEE5']);
    $w->heading(t('xs.curve'), 2); $c = $X['curve'];
    $hdr = fn($x) => ['text' => $x, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center']; $cell = fn($x, $b = false) => ['text' => (string)$x, 'sz' => 8.5, 'align' => 'center', 'b' => $b];
    $w->grid([array_merge([$hdr('')], array_map(fn($p) => $hdr($p['w']), $c)), array_merge([$cell(t('xs.planned'), true)], array_map(fn($p) => $cell($p['planned'] === null ? '—' : (int) round($p['planned']) . '%'), $c)), array_merge([$cell(t('xs.actual'), true)], array_map(fn($p) => $cell($p['actual'] === null ? '' : (int) round($p['actual']) . '%'), $c))], array_merge([1240], array_fill(0, count($c), (int) floor(8400 / max(1, count($c))))), ['border' => '9AA5B1']);
    $w->para(t('rp.figure') . ' 1. ' . t('xs.curve_cap', ['a' => $c[0]['w'], 'b' => $c[count($c) - 1]['w']]), ['sz' => 8.5, 'i' => true]);
    $w->heading(t('ms.title'), 2);
    if (!$X['milestones']) { $w->para(t('ms.none'), ['sz' => 9, 'i' => true]); return; }
    $rows = [[$hdr(t('ms.one')), $hdr(t('ms.plan')), $hdr(t('ms.forecast')), $hdr(t('th.status'))]];
    foreach ($X['milestones'] as $m) $rows[] = [['text' => (string)$m['title'], 'sz' => 8.5], $cell(xs_week((string)$m['plan_date'])), $cell(xs_week((string)($m['achieved_date'] ?: ($m['forecast_date'] ?: $m['plan_date'])))), ['text' => $m['st']['label'], 'sz' => 8.5, 'b' => true, 'color' => ltrim(xs_tone_hex($m['st']['tone']), '#'), 'align' => 'center']];
    $w->grid($rows, [5640, 1200, 1300, 1500], ['border' => '9AA5B1']);
}
/** Where a summary action returns to: the report it was sent from (built from validated fields, never from a posted URL). */
function xs_return(int $pid, string $kind, string $ref): void {
    $P = xs_period($kind, $ref);
    if ($kind === 'month') redirect('monthly', ['project' => $pid, 'month' => $P['key'], '_r' => uniqid()], '#xs-edit-' . $pid);
    redirect('weekly_vessel', array_filter(['vessel' => (int)($_POST['ret_vessel'] ?? 0), 'project' => (int)($_POST['ret_only'] ?? 0) ?: (((int)($_POST['ret_vessel'] ?? 0)) ? null : $pid), 'week' => $P['from']]) + ['_r' => uniqid()], '#xs-edit-' . $pid);
}

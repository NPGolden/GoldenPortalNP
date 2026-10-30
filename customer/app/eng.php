<?php
/**
 * Engineering sections per sub-project (GAS-CP-R47-001 / R47-002) — option C, earned value.
 *
 *  Sections (eng_sections: Electrical, Automation, Mechanical, UI/UX, IT …) and a discipline → section mapping (Settings › Engineering sections).
 *  Per sub-project and section: budget = the budgeted hours of the mapped disciplines in the current Budget & actual revision (one source,
 *  agreed with the customer — approved extra hours never change it, they are shown over it), the deliverables (each weighted in hours),
 *  the actual hours (timesheet lines tagged activity = engineering, by the timesheet's discipline → section), the lead, the status.
 *    earned   = Σ weight of the deliverables set Done (no deliverables → min(actual, allowed))
 *    % done   = earned ÷ (budget + extra)   — the execution figure; 100 when the section is declared finished
 *    % used   = actual ÷ (budget + extra)   — consumption; ≥ 90 % warns, > 100 % = exceeded
 *  Extra hours: whoever works on the section asks (hours, reason kind, reason); management (can_management / admin)
 *  approves or rejects; only approved hours enter the denominator. An exceeded section blocks further engineering
 *  hours on timesheets until extra hours are approved — the section lead can instead declare it finished (early).
 *  The Engineering stage of the sub-project = Σ earned ÷ Σ (budget + extra) across its sections (R42 feed).
 */
declare(strict_types=1);

const ENG_REASONS = ['misjudgement', 'miscalculation', 'scope_change', 'rework', 'other'];
/** Timesheet line activities = the hour-budgeted stages of the process line (+ 'site' = other site / service work that feeds no section). R48-001 */
const ENG_ACTIVITIES = ['engineering', 'production', 'installation', 'sathat', 'commissioning', 'site'];
const ENG_STAGES = ['engineering', 'production', 'installation', 'sathat', 'commissioning'];
const ENG_WARN = 0.9;

function eng_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'eng_budgets'") && (bool) scalar("SHOW TABLES LIKE 'eng_sections'") && (bool) scalar("SHOW TABLES LIKE 'eng_section_disciplines'"); } catch (Throwable $e) { $h = false; } } return $h; }   // R47-001 + R47-002 both applied
function eng_is_management(): bool { static $v = null; if ($v === null) { $u = current_user(); $v = is_cp_admin() || (is_staff() && eng_has() && (bool) scalar('SELECT can_management FROM customer_users WHERE id = ?', [(int)($u['id'] ?? 0)])); } return $v; }
function eng_has2(): bool { return eng_has(); }
/** The engineering sections, keyed by id, each with 'disciplines' => [id => name] mapped to it. */
function eng_sections(): array {
    static $c = null; if ($c !== null) return $c; $c = []; if (!eng_has2()) return $c;
    foreach (all('SELECT * FROM eng_sections WHERE is_active = 1 ORDER BY sort_order, name') as $s) { if (!in_array($s['stage'], ENG_STAGES, true)) $s['stage'] = 'engineering'; $c[(int)$s['id']] = $s + ['disciplines' => []]; }
    foreach (all('SELECT m.section_id, d.id, d.name FROM eng_section_disciplines m JOIN disciplines d ON d.id = m.discipline_id WHERE d.is_active = 1 ORDER BY d.sort_order, d.name') as $d) if (isset($c[(int)$d['section_id']])) $c[(int)$d['section_id']]['disciplines'][(int)$d['id']] = (string)$d['name'];
    return $c;
}
/** Sections of one stage of the process line. */
function eng_stage_sections(string $stage): array { return array_filter(eng_sections(), fn($s) => $s['stage'] === $stage); }
/** All sections a discipline feeds: [section id => stage]. */
function eng_sections_of(?int $did): array { static $m = null; if ($m === null) { $m = []; foreach (eng_sections() as $sid => $s) foreach ($s['disciplines'] as $d => $n) $m[$d][$sid] = $s['stage']; } return $did ? ($m[$did] ?? []) : []; }
/** The stages (= timesheet activities) a discipline is mapped to. */
function eng_stages_of(?int $did): array { return array_values(array_unique(array_values(eng_sections_of($did)))); }
/** Section a discipline feeds for one stage (the first, when a discipline feeds several sections of the same stage). */
function eng_section_for(?int $did, string $stage): ?int { foreach (eng_sections_of($did) as $sid => $st) if ($st === $stage) return $sid; return null; }
/** Disciplines that feed more than one section — their budget hours are split per section in Budget & actual. */
function eng_split_disciplines(): array { $out = []; foreach (eng_sections() as $sid => $s) foreach ($s['disciplines'] as $d => $n) $out[$d][$sid] = $s['name']; return array_filter($out, fn($x) => count($x) > 1); }
function eng_all_sections(): array { return eng_has2() ? all('SELECT * FROM eng_sections ORDER BY is_active DESC, sort_order, name') : []; }
function eng_is_section(?int $sec): bool { return $sec && isset(eng_sections()[$sec]); }
/** Section of a discipline (null when the discipline is not an engineering one). */
function eng_section_of(?int $did): ?int { return eng_section_for($did, 'engineering'); }
function eng_is_eng_discipline(?int $did): bool { return eng_section_of($did) !== null; }
/** Budgeted hours per section from the current Budget & actual revision of the sub-project (null when there is no budget revision). */
function eng_budget_hours(int $sid): ?array {
    if (!function_exists('bg_current') || !bg_has()) return null; $B = bg_current($sid); if (!$B) return null;
    $out = array_fill_keys(array_keys(eng_sections()), 0.0);
    foreach ($B['hours'] as $did => $h) {
        $secs = eng_sections_of((int)$did); if (!$secs) continue;
        $split = $B['sections'][$did] ?? [];                                                   // per-section rows of this discipline (R48)
        if ($split) { foreach ($split as $sec => $hh) if (isset($out[$sec])) $out[$sec] += (float)$hh; }
        else { $n = count($secs); foreach ($secs as $sec => $st) if (isset($out[$sec])) $out[$sec] += (float)$h['hours'] / $n; }   // older revision without a split: shared equally
    }
    return $out;
}
/** May edit budgets / deliverables / declare done on this sub-project: PM, admin, management, works managers, team members of the sub-project (deliverables & done only). */
function eng_can_edit(array $sp, bool $budget = false): bool {
    if (!is_staff() || !eng_has()) return false; if (is_cp_admin() || eng_is_management()) return true;
    $me = (int)(current_user()['id'] ?? 0); if ($me && (int)($sp['pm_user_id'] ?? scalar('SELECT pm_user_id FROM projects WHERE id = ?', [(int)$sp['project_id']])) === $me) return true;
    if (function_exists('wp_can_manage') && wp_can_manage((int)$sp['project_id'])) return true;
    try { if ($me && scalar('SELECT COUNT(*) FROM eng_budgets WHERE subproject_id = ? AND lead_user_id = ?', [(int)$sp['id'], $me])) return true; } catch (Throwable $e) {}   // section lead
    if ($budget) return false;
    try { return (bool) scalar('SELECT COUNT(*) FROM sp_team WHERE subproject_id = ? AND user_id = ?', [(int)$sp['id'], $me]); } catch (Throwable $e) { return false; }
}
/** Actual engineering hours per section of a sub-project (tagged lines of submitted / approved / invoiced timesheets; untagged lines count while the stage is open). */
function eng_actual(int $sid, ?int $excludeTs = null): array {
    $out = [];
    foreach (all("SELECT t.discipline_id, l.activity, SUM(l.hours) h FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.subproject_id = ? AND t.status IN ('submitted','approved','invoiced') AND t.discipline_id IN (SELECT discipline_id FROM eng_section_disciplines)" . ($excludeTs ? ' AND t.id <> ?' : '') . " GROUP BY t.discipline_id, l.activity", $excludeTs ? [$sid, $excludeTs] : [$sid]) as $r) {
        $did = (int)$r['discipline_id']; $act = (string)($r['activity'] ?? '');
        if ($act === '' ) $act = eng_section_of($did) !== null ? 'engineering' : (eng_stages_of($did)[0] ?? '');   // untagged historic lines: engineering for engineering disciplines, else the discipline's first stage
        if ($act === '' || $act === 'site') continue; $sec = eng_section_for($did, $act); if ($sec !== null) $out[$sec] = ($out[$sec] ?? 0.0) + (float)$r['h'];
    }
    return $out;
}
/** The full picture of a sub-project: [did => row] for every section with a budget, deliverables or hours. */
function eng_rows(int $sid): array {
    if (!eng_has()) return [];
    if (!eng_has2()) return [];
    $sec = eng_sections(); $B = []; foreach (all('SELECT * FROM eng_budgets WHERE subproject_id = ?', [$sid]) as $b) $B[(int)$b['section_id']] = $b;
    $X = []; foreach (all("SELECT section_id, SUM(hours) h FROM eng_requests WHERE subproject_id = ? AND status = 'approved' GROUP BY section_id", [$sid]) as $r) $X[(int)$r['section_id']] = (float)$r['h'];
    $P = []; foreach (all("SELECT section_id, COUNT(*) n, SUM(hours) h FROM eng_requests WHERE subproject_id = ? AND status = 'pending' GROUP BY section_id", [$sid]) as $r) $P[(int)$r['section_id']] = ['n' => (int)$r['n'], 'h' => (float)$r['h']];
    $D = []; foreach (all('SELECT d.*, cu.full_name AS done_name FROM eng_deliverables d LEFT JOIN customer_users cu ON cu.id = d.done_by WHERE d.subproject_id = ? ORDER BY d.sort_order, d.id', [$sid]) as $d) $D[(int)$d['section_id']][] = $d;
    $A = eng_actual($sid); $BH = eng_budget_hours($sid); $out = [];
    foreach ($sec as $did => $d) {
        $budget = $BH !== null ? (float)($BH[$did] ?? 0) : (float)($B[$did]['hours'] ?? 0);   // from Budget & actual; manual eng_budgets.hours only when the budget module is absent
        if ($budget <= 0 && !isset($B[$did]) && empty($D[$did]) && empty($A[$did]) && !isset($X[$did]) && !isset($P[$did])) continue;
        $b = $B[$did] ?? null; $extra = $X[$did] ?? 0.0; $allowed = $budget + $extra; $actual = $A[$did] ?? 0.0; $dl = $D[$did] ?? [];
        $wTot = 0.0; $wDone = 0.0; foreach ($dl as $x) { $wTot += (float)$x['weight_h']; if ($x['status'] === 'done') $wDone += (float)$x['weight_h']; }
        $done = ($b['status'] ?? 'open') === 'done';
        $earned = $done ? $allowed : ($dl ? ($wTot > 0 ? $wDone / $wTot * $allowed : 0.0) : min($actual, $allowed));
        $pctDone = $done ? 100 : ($allowed > 0 ? (int) round(min(100, $earned / $allowed * 100)) : ($dl && $wTot > 0 ? (int) round($wDone / $wTot * 100) : 0));
        $ratio = $allowed > 0 ? $actual / $allowed : ($actual > 0 ? 9.99 : 0.0);                 // consumption of the allowed hours (budget + extra)
        $ratioB = $budget > 0 ? $actual / $budget : ($actual > 0 ? 9.99 : 0.0);                  // consumption of the agreed budget alone — > 1 is "over budget", shown over the bar
        $state = $done ? 'done' : (isset($P[$did]) ? 'pending' : ($allowed > 0 && $ratio > 1.0001 ? 'exceeded' : ($allowed > 0 && $ratio >= ENG_WARN ? 'warn' : ($extra > 0 ? 'extended' : ($allowed > 0 ? 'ok' : 'nobudget')))));   // extended = original budget exceeded, extra hours approved
        $out[$did] = ['did' => $did, 'name' => (string)$d['name'], 'code' => (string)$d['code'], 'stage' => (string)$d['stage'], 'disciplines' => $d['disciplines'], 'b' => $b, 'budget' => $budget, 'extra' => $extra, 'allowed' => $allowed, 'actual' => $actual, 'earned' => $earned, 'pct_done' => $pctDone, 'pct_used' => $allowed > 0 ? (int) round($ratio * 100) : null, 'pct_budget' => $budget > 0 ? (int) round($ratioB * 100) : null, 'over_h' => max(0.0, $actual - $budget), 'ratio' => $ratio, 'state' => $state, 'done' => $done, 'deliverables' => $dl, 'pending' => $P[$did] ?? null, 'lead_user_id' => (int)($b['lead_user_id'] ?? 0)];
    }
    return $out;
}
/** Engineering % of the sub-project (weighted by allowed hours); null when no section is budgeted. */
function eng_sub_pct(int $sid, string $stage = 'engineering'): ?int { $rows = eng_rows($sid); $num = 0.0; $den = 0.0; foreach ($rows as $r) { if ($r['stage'] !== $stage || $r['allowed'] <= 0) continue; $num += $r['earned']; $den += $r['allowed']; } return $den > 0 ? (int) round(min(100, $num / $den * 100)) : null; }
/** [stage => %] for every stage that has budgeted sections on this sub-project. */
function eng_stage_pcts(int $sid): array { $out = []; foreach (ENG_STAGES as $st) { $p = eng_sub_pct($sid, $st); if ($p !== null) $out[$st] = $p; } return $out; }
/** Worst state of a sub-project's sections for the ⚠ marks: exceeded > pending > warn > ok / none. */
function eng_sub_state(int $sid): string { $rank = ['exceeded' => 5, 'pending' => 4, 'warn' => 3, 'extended' => 2, 'ok' => 1, 'done' => 0, 'nobudget' => 0]; $w = 0; $s = 'none'; foreach (eng_rows($sid) as $r) if (($rank[$r['state']] ?? 0) > $w) { $w = $rank[$r['state']]; $s = $r['state']; } return $s; }
function eng_state_pill(string $st, ?string $extra = null): string { $m = ['exceeded' => ['bad', 'eng.st_exceeded'], 'pending' => ['gold', 'eng.st_pending'], 'warn' => ['gold', 'eng.st_warn'], 'extended' => ['gold', 'eng.st_extended'], 'ok' => ['green', 'eng.st_ok'], 'done' => ['green', 'eng.st_done'], 'nobudget' => ['mute', 'eng.st_nobudget']]; [$tone, $k] = $m[$st] ?? ['mute', 'eng.st_nobudget']; return pill($tone, t($k) . ($extra !== null ? ' ' . $extra : '')); }
/** Write the Engineering stage of the sub-project from the sections (unless the PM unlocked it). */
function eng_feed_subproject(int $sid): void {
    $man = function_exists('pd_sub_manual') ? pd_sub_manual($sid) : []; $defs = function_exists('stage_defs') ? stage_defs() : [];
    foreach (eng_stage_pcts($sid) as $st => $p) { if (in_array($st, $man, true) || !isset($defs[$st])) continue; try { q('UPDATE subprojects SET ' . $defs[$st]['col'] . ' = ? WHERE id = ?', [$p, $sid]); } catch (Throwable $e) {} }
}
/** Who decides an extra-hours request: management (and administrators) for every section; the PM of the project as well for the site stages (R48-001). */
function eng_can_decide(array $sp, string $stage): bool {
    if (eng_is_management()) return true; if ($stage === 'engineering') return false;
    $me = (int)(current_user()['id'] ?? 0); return $me > 0 && (int)($sp['pm_user_id'] ?? scalar('SELECT pm_user_id FROM projects WHERE id = ?', [(int)$sp['project_id']])) === $me;
}
function eng_is_pm(): bool { static $v = null; if ($v === null) { $me = (int)(current_user()['id'] ?? 0); $v = $me > 0 && is_staff() && (bool) scalar('SELECT COUNT(*) FROM projects WHERE pm_user_id = ?', [$me]); } return $v; }
/** Sub-projects with an engineering section exceeded or a request pending (for the project tree / dashboard). */
function eng_alerts(?int $pid = null): array {
    if (!eng_has2() || !is_staff()) return [];
    $sids = array_map('intval', array_column(all('SELECT DISTINCT b.subproject_id FROM eng_budgets b JOIN subprojects s ON s.id = b.subproject_id' . ($pid ? ' WHERE s.project_id = ?' : " WHERE s.status <> 'completed'") . ' UNION SELECT DISTINCT r.subproject_id FROM eng_requests r JOIN subprojects s ON s.id = r.subproject_id' . ($pid ? ' WHERE s.project_id = ?' : " WHERE s.status <> 'completed'"), $pid ? [$pid, $pid] : []), 'subproject_id'));
    $out = []; foreach ($sids as $sid) { $st = eng_sub_state($sid); if (in_array($st, ['exceeded', 'pending', 'warn', 'extended'], true)) $out[$sid] = $st; } return $out;
}

/* ------------------------------------------------------------------ writing */
/** Section leads (and, only when the budget module is absent, manual section hours). The hours otherwise come from Budget & actual. */
function eng_budget_save_from_post(int $sid): void {
    $sp = load_subproject($sid); if (!$sp || !eng_can_edit($sp, true)) throw new RuntimeException(t('err.noaccess'));
    $manual = !(function_exists('bg_has') && bg_has());
    foreach (eng_sections() as $did => $d) {
        $h = $manual ? min(10000, max(0, (float) str_replace(',', '.', (string)($_POST['h'][$did] ?? '0')))) : 0.0; $lead = (int)($_POST['lead'][$did] ?? 0) ?: null; if ($lead && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$lead])) $lead = null;
        $has = one('SELECT id, status FROM eng_budgets WHERE subproject_id = ? AND section_id = ?', [$sid, $did]);
        if ($h <= 0 && !$lead) { if ($has && $has['status'] !== 'done' && !scalar('SELECT COUNT(*) FROM eng_deliverables WHERE subproject_id = ? AND section_id = ?', [$sid, $did]) && !scalar('SELECT COUNT(*) FROM eng_requests WHERE subproject_id = ? AND section_id = ?', [$sid, $did])) q('DELETE FROM eng_budgets WHERE id = ?', [(int)$has['id']]); elseif ($has) q('UPDATE eng_budgets SET lead_user_id = NULL' . ($manual ? ', hours = 0' : '') . ' WHERE id = ?', [(int)$has['id']]); continue; }
        q('INSERT INTO eng_budgets (subproject_id, section_id, hours, lead_user_id) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE ' . ($manual ? 'hours = VALUES(hours), ' : '') . 'lead_user_id = VALUES(lead_user_id)', [$sid, $did, $h, $lead]);
    }
    eng_feed_subproject($sid);
}
/** Settings › Engineering sections: the sections list and the discipline → section mapping (administrators). */
function eng_sections_save_from_post(): void {
    require_cp_admin(); if (!eng_has2()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $code = strtoupper(mb_substr(trim((string)($_POST['code'] ?? '')), 0, 12)); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80); $ord = (int)($_POST['sort_order'] ?? 0);
    if (!empty($_POST['toggle']) && $id) { q('UPDATE eng_sections SET is_active = 1 - is_active WHERE id = ?', [$id]); return; }
    $stage = in_array($_POST['stage'] ?? '', ENG_STAGES, true) ? $_POST['stage'] : 'engineering';
    if ($name === '' || !preg_match('/^[A-Z0-9_-]{1,12}$/', $code)) throw new RuntimeException(t('eng.sec_need'));
    try { if ($id) q('UPDATE eng_sections SET code = ?, name = ?, stage = ?, sort_order = ? WHERE id = ?', [$code, $name, $stage, $ord, $id]); else q('INSERT INTO eng_sections (code, name, stage, sort_order) VALUES (?,?,?,?)', [$code, $name, $stage, $ord]); }
    catch (PDOException $e) { if ((int)$e->errorInfo[1] === 1062) throw new RuntimeException(t('sys.dup', ['v' => $code])); throw $e; }
}
function eng_mapping_save_from_post(): void {
    require_cp_admin(); if (!eng_has2()) throw new RuntimeException(t('err.noaccess'));
    $valid = array_map('intval', array_column(all('SELECT id FROM eng_sections'), 'id')); $M = $_POST['map'] ?? [];   // map[discipline][section] = 1 (matrix of checkboxes)
    foreach (all('SELECT id FROM disciplines') as $d) {
        $did = (int)$d['id']; $want = []; foreach ((array)($M[$did] ?? []) as $sec => $v) if ($v && in_array((int)$sec, $valid, true)) $want[] = (int)$sec;
        q('DELETE FROM eng_section_disciplines WHERE discipline_id = ?', [$did]); foreach ($want as $sec) q('INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) VALUES (?,?)', [$sec, $did]);
        q('UPDATE disciplines SET eng_section_id = ? WHERE id = ?', [eng_first_engineering($want), $did]);   // legacy single column kept in step
    }
}
function eng_first_engineering(array $secIds): ?int { $all = all('SELECT id, stage FROM eng_sections'); foreach ($all as $s) if ($s['stage'] === 'engineering' && in_array((int)$s['id'], $secIds, true)) return (int)$s['id']; return null; }
function eng_deliverable_save_from_post(int $sid): void {
    $sp = load_subproject($sid); if (!$sp || !eng_can_edit($sp)) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['dl_id'] ?? 0); $did = (int)($_POST['section_id'] ?? 0); if (!eng_is_section($did)) throw new RuntimeException(t('err.notfound'));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160); if ($title === '') throw new RuntimeException(t('eng.need_title'));
    $w = min(10000, max(0, (float) str_replace(',', '.', (string)($_POST['weight_h'] ?? '0'))));
    if ($id) q('UPDATE eng_deliverables SET title = ?, weight_h = ?, sort_order = ? WHERE id = ? AND subproject_id = ?', [$title, $w, (int)($_POST['sort_order'] ?? 0), $id, $sid]);
    else { q('INSERT INTO eng_deliverables (subproject_id, section_id, title, weight_h, sort_order, created_by) VALUES (?,?,?,?,?,?)', [$sid, $did, $title, $w, (int)($_POST['sort_order'] ?? 0), (int)(current_user()['id'] ?? 0)]); q('INSERT IGNORE INTO eng_budgets (subproject_id, section_id, hours) VALUES (?,?,0)', [$sid, $did]); }
    eng_feed_subproject($sid);
}
function eng_deliverable_toggle(int $sid, int $dlId): void {
    $sp = load_subproject($sid); if (!$sp || !eng_can_edit($sp)) throw new RuntimeException(t('err.noaccess'));
    $d = one('SELECT * FROM eng_deliverables WHERE id = ? AND subproject_id = ?', [$dlId, $sid]); if (!$d) throw new RuntimeException(t('err.notfound'));
    $done = $d['status'] !== 'done'; q('UPDATE eng_deliverables SET status = ?, done_at = ?, done_by = ? WHERE id = ?', [$done ? 'done' : 'pending', $done ? date('Y-m-d H:i:s') : null, $done ? (int)(current_user()['id'] ?? 0) : null, $dlId]);
    eng_feed_subproject($sid);
}
function eng_deliverable_delete(int $sid, int $dlId): void { $sp = load_subproject($sid); if (!$sp || !eng_can_edit($sp)) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM eng_deliverables WHERE id = ? AND subproject_id = ?', [$dlId, $sid]); eng_feed_subproject($sid); }
/** Declare a section finished (early or on time) — or reopen it. A note is required when deliverables are still pending. */
function eng_section_done(int $sid, int $did, bool $done, string $note): void {
    $sp = load_subproject($sid); if (!$sp || !eng_can_edit($sp) || !eng_is_section($did)) throw new RuntimeException(t('err.noaccess'));
    $rows = eng_rows($sid); $r = $rows[$did] ?? null; $note = mb_substr(trim($note), 0, 500);
    if ($done && $r && array_filter($r['deliverables'], fn($x) => $x['status'] !== 'done') && $note === '') throw new RuntimeException(t('eng.need_done_note'));
    q('INSERT INTO eng_budgets (subproject_id, section_id, hours, status, done_at, done_by, done_note) VALUES (?,?,0,?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), done_at = VALUES(done_at), done_by = VALUES(done_by), done_note = VALUES(done_note)',
      [$sid, $did, $done ? 'done' : 'open', $done ? date('Y-m-d H:i:s') : null, $done ? (int)(current_user()['id'] ?? 0) : null, $done ? ($note ?: null) : null]);
    eng_feed_subproject($sid);
    if ($done) eng_notify($sp, $did, 'done', t('eng.nf_done', ['s' => eng_sections()[$did]['name']]), $note ?: null, [(int)($sp['pm_user_id'] ?? 0)]);
}
function eng_request_save_from_post(int $sid): int {
    $sp = load_subproject($sid); if (!$sp || !is_staff() || !eng_has()) throw new RuntimeException(t('err.noaccess'));
    $did = (int)($_POST['section_id'] ?? 0); if (!eng_is_section($did)) throw new RuntimeException(t('err.notfound'));
    $h = (float) str_replace(',', '.', (string)($_POST['hours'] ?? '0')); if ($h <= 0 || $h > 10000 || !is_finite($h)) throw new RuntimeException(t('eng.need_hours'));
    $kind = in_array($_POST['reason_kind'] ?? '', ENG_REASONS, true) ? $_POST['reason_kind'] : 'misjudgement';
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 1000); if ($reason === '') throw new RuntimeException(t('eng.need_reason'));
    $u = current_user();
    q('INSERT INTO eng_requests (subproject_id, section_id, hours, reason_kind, reason, requested_by, requested_name) VALUES (?,?,?,?,?,?,?)', [$sid, $did, $h, $kind, $reason, (int)$u['id'], (string)$u['full_name']]);
    $id = (int) db()->lastInsertId(); q('INSERT IGNORE INTO eng_budgets (subproject_id, section_id, hours) VALUES (?,?,0)', [$sid, $did]);
    $mgmt = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role IN ('staff','admin') AND (role = 'admin' OR can_management = 1)"), 'id'));
    eng_notify($sp, $did, 'request', t('eng.nf_request', ['s' => eng_sections()[$did]['name'], 'h' => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.')]), t('eng.r_' . $kind) . ' — ' . $reason, array_merge($mgmt, [(int)($sp['pm_user_id'] ?? 0)]));
    return $id;
}
function eng_request_decide(int $reqId, bool $approve, string $note): void {
    $r = one('SELECT * FROM eng_requests WHERE id = ?', [$reqId]); if (!$r || $r['status'] !== 'pending') throw new RuntimeException(t('err.notfound'));
    $sp0 = load_subproject((int)$r['subproject_id']); if (!$sp0 || !eng_can_decide($sp0, (string)(eng_sections()[(int)$r['section_id']]['stage'] ?? 'engineering'))) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); q('UPDATE eng_requests SET status = ?, decided_by = ?, decided_name = ?, decided_at = NOW(), decision_note = ? WHERE id = ?', [$approve ? 'approved' : 'rejected', (int)$u['id'], (string)$u['full_name'], mb_substr(trim($note), 0, 500) ?: null, $reqId]);
    $sid = (int)$r['subproject_id']; $sp = load_subproject($sid); if ($approve) q('UPDATE eng_budgets SET exceeded_notified_at = NULL WHERE subproject_id = ? AND section_id = ?', [$sid, (int)$r['section_id']]);
    eng_feed_subproject($sid);
    if ($sp) eng_notify($sp, (int)$r['section_id'], $approve ? 'approved' : 'rejected', t($approve ? 'eng.nf_approved' : 'eng.nf_rejected', ['s' => eng_sections()[(int)$r['section_id']]['name'] ?? '', 'h' => rtrim(rtrim(number_format((float)$r['hours'], 2, '.', ''), '0'), '.')]), $note ?: null, [(int)$r['requested_by'], (int)($sp['pm_user_id'] ?? 0)]);
}
function eng_requests(array $f = []): array {
    if (!eng_has() || !is_staff()) return [];
    $w = ['1 = 1']; $a = []; $st = (string)($f['status'] ?? 'pending'); if ($st !== 'all') { $w[] = 'r.status = ?'; $a[] = $st; }
    if (!empty($f['subproject'])) { $w[] = 'r.subproject_id = ?'; $a[] = (int)$f['subproject']; }
    [$sc, $sa] = scope_sql('p'); $w[] = $sc; $a = array_merge($a, $sa);
    return all("SELECT r.*, d.name AS section, d.stage AS stage, s.code AS sub_code, s.name AS sub_name, p.project_code, p.name AS project_name, p.id AS project_id, v.name AS vessel FROM eng_requests r JOIN eng_sections d ON d.id = r.section_id JOIN subprojects s ON s.id = r.subproject_id JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id WHERE " . implode(' AND ', $w) . ' ORDER BY r.status = "pending" DESC, r.requested_at DESC', $a);
}
function eng_notify(array $sp, int $did, string $kind, string $title, ?string $body, array $ids): void {
    if (!function_exists('nf_push')) return; $me = (int)(current_user()['id'] ?? 0);
    $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), fn($i) => $i && $i !== $me)); if (!$ids) return;
    nf_push($ids, 'eng:' . $kind, 'eng:' . (int)$sp['id'] . ':' . $did, trim(($sp['project_code'] ?: '') . ' › ' . ($sp['code'] ?: '') . ' · ' . $title), $body, url('subproject', ['id' => (int)$sp['id'], 'tab' => 'engineering']), $me ?: null, current_user()['full_name'] ?? null);
}
/** Timesheet guard: engineering hours on an exceeded section are refused until extra hours are approved (or the section is declared finished).
 *  $new = [sid => hours] of engineering lines in the sheet being saved; returns the message to show, or null. */
function eng_check_booking(int $discId, array $new, ?int $excludeTs): ?string {
    if (!eng_has2() || !eng_sections_of($discId)) return null;
    foreach ($new as $sid => $byAct) foreach ((array)$byAct as $act => $h) {
        $did = eng_section_for($discId, (string)$act); if ($did === null) continue;
        $b = one('SELECT * FROM eng_budgets WHERE subproject_id = ? AND section_id = ?', [(int)$sid, $did]); if ($b && $b['status'] === 'done') continue;
        $BH = eng_budget_hours((int)$sid); $budget = $BH !== null ? (float)($BH[$did] ?? 0) : (float)($b['hours'] ?? 0); if ($budget <= 0) continue;
        $allowed = $budget + (float) scalar("SELECT COALESCE(SUM(hours),0) FROM eng_requests WHERE subproject_id = ? AND section_id = ? AND status = 'approved'", [(int)$sid, $did]);
        $used = eng_actual((int)$sid, $excludeTs)[$did] ?? 0.0;
        if ($used + (float)$h > $allowed + 0.001) {
            $sp = load_subproject((int)$sid); $sec = eng_sections()[$did]['name'] ?? '';
            if (!$b) { q('INSERT IGNORE INTO eng_budgets (subproject_id, section_id, hours) VALUES (?,?,0)', [(int)$sid, $did]); $b = one('SELECT * FROM eng_budgets WHERE subproject_id = ? AND section_id = ?', [(int)$sid, $did]); }
            if (empty($b['exceeded_notified_at'])) { q('UPDATE eng_budgets SET exceeded_notified_at = NOW() WHERE id = ?', [(int)$b['id']]); if ($sp) eng_notify($sp, $did, 'exceeded', t('eng.nf_exceeded', ['s' => $sec]), null, [(int)($sp['pm_user_id'] ?? 0)]); }
            $f = fn(float $x) => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.'); return t('eng.blocked', ['s' => $sec . ($sp ? ' (' . (string)($sp['code'] ?? '') . ')' : ''), 'a' => $f($used), 'b' => $f($allowed), 'n' => $f((float)$h)]);
        }
    }
    return null;
}
/** Default activity for a new timesheet line: engineering while the user's discipline is a section and the sub-project's Engineering stage is open. */
function eng_default_activity(?int $did, ?int $sid): string {
    if (!eng_has2()) return 'site'; $stages = eng_stages_of($did); if (!$stages) return 'site';
    if (!$sid) return $stages[0]; $defs = function_exists('stage_defs') ? stage_defs() : []; $row = one('SELECT * FROM subprojects WHERE id = ?', [$sid]);
    foreach ($stages as $st) { $col = $defs[$st]['col'] ?? null; if (!$col || (int)($row[$col] ?? 0) < 100) return $st; }   // first mapped stage of the discipline that is still open
    return 'site';
}
/** Activities an engineer may pick on a timesheet line: the stages of his discipline + site. */
function eng_activity_choices(?int $did): array { return array_values(array_unique(array_merge(eng_stages_of($did), ['site']))); }

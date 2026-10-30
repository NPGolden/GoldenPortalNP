<?php
/**
 * HR · Statutory annual-leave engine, HR override, change log, import batches (GAS-CP-R55-001).
 * Entitlement of the annual-leave type is computed from the person's status (hiring date, service at Golden A/S + prior service,
 * 5- or 6-day week) with the parametric rules in cp_settings.hr_rules; HR may unlock a person's year and fix the value by hand.
 * Every change on a person (card fields, entitlements, employment events, documents, request decisions) is written to hr_audit.
 */
declare(strict_types=1);

const HR_RULE_DEFAULTS = ['y1_5' => 20, 'y2_5' => 21, 'y3_5' => 22, 'y1_6' => 24, 'y2_6' => 25, 'y3_6' => 26, 'senior_same' => 10, 'senior_total' => 12, 'senior_5' => 25, 'senior_6' => 30, 'long_total' => 25, 'long_5' => 26, 'long_6' => 31, 'carry_max' => 5, 'round' => 0.5,
    'sch_days' => 4, 'sch_age_min' => 4, 'sch_age_max' => 16, 'par_days_child' => 88, 'par_age' => 8, 'car_days' => 5, 'fmj_days' => 2, 'mar_days' => 5, 'mar_days_6' => 6, 'pat_days' => 14, 'mat_days' => 119, 'study_days' => 30, 'mono_days' => 6, 'mono_days_3' => 8, 'chs_days' => 6, 'chs_days_2' => 8, 'chs_days_3' => 14, 'chs_age' => 16, 'sick_short' => 13, 'sick_long' => 26, 'sick_service' => 12, 'rol_hours' => 0, 'law104_days' => 0];
const HR_RULE_IT_DEFAULTS = ['y1_5' => 20, 'y2_5' => 20, 'y3_5' => 20, 'y1_6' => 26, 'y2_6' => 26, 'y3_6' => 26, 'senior_same' => 99, 'senior_total' => 99, 'senior_5' => 20, 'senior_6' => 26, 'long_total' => 99, 'long_5' => 20, 'long_6' => 26, 'carry_max' => 30, 'round' => 0.5,
    'sch_days' => 0, 'sch_age_min' => 0, 'sch_age_max' => 0, 'par_days_child' => 220, 'par_age' => 12, 'car_days' => 3, 'fmj_days' => 0, 'mar_days' => 15, 'mar_days_6' => 15, 'pat_days' => 10, 'mat_days' => 150, 'study_days' => 20, 'mono_days' => 0, 'mono_days_3' => 0, 'chs_days' => 5, 'chs_days_2' => 5, 'chs_days_3' => 5, 'chs_age' => 8, 'sick_short' => 180, 'sick_long' => 180, 'sick_service' => 12, 'rol_hours' => 104, 'law104_days' => 36];
const HR_JURISDICTIONS = ['GR', 'IT'];
/** Rule groups for the settings page: [group label key => [rule keys]] */
const HR_RULE_GROUPS = ['hr.rg_annual' => ['y1_5', 'y1_6', 'y2_5', 'y2_6', 'y3_5', 'y3_6', 'senior_same', 'senior_total', 'senior_5', 'senior_6', 'long_total', 'long_5', 'long_6', 'carry_max', 'round'],
    'hr.rg_family' => ['sch_days', 'sch_age_min', 'sch_age_max', 'par_days_child', 'par_age', 'mat_days', 'pat_days', 'mono_days', 'mono_days_3', 'chs_days', 'chs_days_2', 'chs_days_3', 'chs_age', 'car_days', 'law104_days'],
    'hr.rg_other' => ['mar_days', 'mar_days_6', 'fmj_days', 'study_days', 'sick_short', 'sick_long', 'sick_service', 'rol_hours']];
const HR_RULE_LABELS = ['y1_5' => 'hr.rl_y1', 'y2_5' => 'hr.rl_y2', 'y3_5' => 'hr.rl_y3', 'y1_6' => 'hr.rl_y1', 'y2_6' => 'hr.rl_y2', 'y3_6' => 'hr.rl_y3', 'senior_same' => 'hr.rl_senior_same', 'senior_total' => 'hr.rl_senior_total', 'senior_5' => 'hr.rl_senior', 'senior_6' => 'hr.rl_senior', 'long_total' => 'hr.rl_long_total', 'long_5' => 'hr.rl_long', 'long_6' => 'hr.rl_long', 'carry_max' => 'hr.rl_carry', 'round' => 'hr.rl_round',
    'sch_days' => 'hr.rl_sch_days', 'sch_age_min' => 'hr.rl_sch_age_min', 'sch_age_max' => 'hr.rl_sch_age_max', 'par_days_child' => 'hr.rl_par_days', 'par_age' => 'hr.rl_par_age', 'car_days' => 'hr.rl_car', 'fmj_days' => 'hr.rl_fmj', 'mar_days' => 'hr.rl_mar', 'mar_days_6' => 'hr.rl_mar6', 'pat_days' => 'hr.rl_pat', 'mat_days' => 'hr.rl_mat', 'study_days' => 'hr.rl_study', 'mono_days' => 'hr.rl_mono', 'mono_days_3' => 'hr.rl_mono3', 'chs_days' => 'hr.rl_chs', 'chs_days_2' => 'hr.rl_chs2', 'chs_days_3' => 'hr.rl_chs3', 'chs_age' => 'hr.rl_chs_age', 'sick_short' => 'hr.rl_sick_short', 'sick_long' => 'hr.rl_sick_long', 'sick_service' => 'hr.rl_sick_service', 'rol_hours' => 'hr.rl_rol', 'law104_days' => 'hr.rl_104'];

function hr4_has(): bool { static $h = null; if ($h === null) { try { $h = function_exists('hr3_has') && hr3_has() && (bool) scalar("SHOW TABLES LIKE 'hr_audit'") && (bool) scalar("SHOW COLUMNS FROM hr_entitlements LIKE 'auto'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_jurisdiction(?array $p): string { $j = strtoupper((string)($p['jurisdiction'] ?? 'GR')); return in_array($j, HR_JURISDICTIONS, true) ? $j : 'GR'; }
function hr_rules(string $jur = 'GR'): array { static $c = []; $jur = in_array($jur, HR_JURISDICTIONS, true) ? $jur : 'GR'; if (!isset($c[$jur])) { $def = $jur === 'IT' ? HR_RULE_IT_DEFAULTS : HR_RULE_DEFAULTS; $j = function_exists('cp_setting') ? (string) cp_setting($jur === 'GR' ? 'hr_rules' : 'hr_rules_' . $jur, '') : ''; $d = $j !== '' ? json_decode($j, true) : null; $c[$jur] = array_merge($def, is_array($d) ? array_intersect_key($d, $def) : []); } return $c[$jur]; }
function hr_rules_save_from_post(): void {
    if (!hr_can() || !hr4_has()) throw new RuntimeException(t('err.noaccess')); $jur = in_array($_POST['jur'] ?? '', HR_JURISDICTIONS, true) ? $_POST['jur'] : 'GR'; $r = hr_rules($jur); $old = $r;
    foreach (HR_RULE_DEFAULTS as $k => $d) { $v = str_replace(',', '.', trim((string)($_POST['r'][$k] ?? ''))); if ($v === '' || !is_numeric($v)) continue; $r[$k] = $k === 'round' ? max(0.1, min(1, (float)$v)) : max(0, min(400, (float)$v)); }
    cp_setting_set($jur === 'GR' ? 'hr_rules' : 'hr_rules_' . $jur, json_encode($r)); $me = (int)(current_user()['id'] ?? 0);
    foreach ($r as $k => $v) if ((float)$v !== (float)$old[$k]) q('INSERT INTO hr_audit (person_id, user_id, kind, field, old_value, new_value, note) VALUES (0,?,?,?,?,?,?)', [$me, 'rules', $k, (string)$old[$k], (string)$v, $jur]);
}
function hr_is_contractor(?array $p): bool { return ($p['employment_kind'] ?? 'employee') === 'contractor'; }
/** Children of the person aged between $min and $max (inclusive) on 31/12 of the year, from the birth years on the card; falls back to the count when no years are given. */
/** Children born in $year according to the birth years on the card (maternity / paternity are granted per birth). */
function hr_births_in(array $p, int $year): int { $n = 0; foreach (preg_split('/[^0-9]+/', (string)($p['children_years'] ?? '')) as $y) if ((int)$y === $year) $n++; return $n; }
function hr_children_aged(array $p, int $year, int $min, int $max): int { $ys = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($p['children_years'] ?? ''))))); if (!$ys) return $min <= 0 && $max >= 0 ? (int)($p['children'] ?? 0) : ((int)($p['children'] ?? 0) > 0 && $max >= 18 ? (int)$p['children'] : 0); $n = 0; foreach ($ys as $y) { $age = $year - $y; if ($age >= $min && $age <= $max) $n++; } return $n; }

/* ------------------------------------------------------------ the engine */
/** Statutory annual leave of a person for a calendar year: ['days','basis'(key),'vars'] — null when the person was not employed in that year. */
function hr_statutory(array $p, int $year): ?array {
    if (hr_is_contractor($p)) return null;   // external contractors: no statutory leave, the manager agrees each absence
    $r = hr_rules(hr_jurisdiction($p)); $six = (int)($p['week_days'] ?? 5) === 6; $yEnd = $year . '-12-31';
    $periods = function_exists('hr_employments') ? array_filter(hr_employments((int)$p['id']), fn($e) => in_array($e['kind'], ['hire', 'rehire'], true)) : [];
    if (!$periods && !empty($p['hire_date'])) $periods = [['date_from' => $p['hire_date'], 'date_to' => $p['left_date'] ?? null]];
    $first = null; $inYear = false; $firstInYear = null;
    foreach ($periods as $e) { $a = $e['date_from']; $b = $e['date_to'] ?: '9999-12-31'; if ($first === null || $a < $first) $first = $a; if ($a <= $yEnd && $b >= $year . '-01-01') { $inYear = true; if ($firstInYear === null || $a < $firstInYear) $firstInYear = $a; } }
    if (!$inYear || $first === null) return null;
    $companyM = function_exists('hr_company_months') ? hr_company_months((int)$p['id'], $yEnd) : hr_service_months($p, $yEnd) - (int)($p['prior_months'] ?? 0); $priorM = (int)($p['prior_months'] ?? 0); $totalM = $companyM + $priorM;
    $calYear = $year - (int) substr($first, 0, 4) + 1;   // 1 = calendar year of hiring, 2 = the next, 3+
    $round = (float)$r['round'] ?: 0.5; $rd = fn(float $v) => round($v / $round) * $round;
    if ($totalM >= (int)$r['long_total'] * 12) { $d = $six ? $r['long_6'] : $r['long_5']; $basis = 'long'; }
    elseif ($companyM >= (int)$r['senior_same'] * 12 || $totalM >= (int)$r['senior_total'] * 12) { $d = $six ? $r['senior_6'] : $r['senior_5']; $basis = 'senior'; }
    elseif ($calYear <= 1) { $start = max($firstInYear, $year . '-01-01'); $days = (int) ((strtotime($yEnd) - strtotime($start)) / 86400) + 1; $full = $six ? $r['y1_6'] : $r['y1_5']; $d = $rd($full * $days / (((int) date('L', mktime(0, 0, 0, 1, 1, $year))) ? 366 : 365)); $basis = 'y1'; return ['days' => $d, 'basis' => $basis, 'vars' => ['full' => $full, 'days' => $days, 'company' => $companyM, 'prior' => $priorM]]; }
    elseif ($calYear === 2) { $d = $six ? $r['y2_6'] : $r['y2_5']; $basis = 'y2'; }
    else { $d = $six ? $r['y3_6'] : $r['y3_5']; $basis = 'y3'; }
    // re-hired within the year: pro-rata of the period actually worked in the year
    if ($firstInYear > $year . '-01-01' && $calYear > 1) { $days = (int) ((strtotime($yEnd) - strtotime($firstInYear)) / 86400) + 1; $d = $rd((float)$d * $days / 365); $basis .= '_pr'; }
    return ['days' => (float)$d, 'basis' => $basis, 'vars' => ['company' => $companyM, 'prior' => $priorM, 'total' => $totalM, 'cal' => $calYear]];
}
/** Allowance of any statutory type for a person / year, in the type's unit: ['days','basis','vars'] or null (no rule, or not applicable). */
function hr_statutory_type(array $p, array $t, int $year): ?array {
    $rk = (string)($t['rule_key'] ?? ''); if ($rk === '' || hr_is_contractor($p)) return null; if (function_exists('hr_type_enabled') && !hr_type_enabled($p, $t)) return null; if ($rk === 'annual') return hr_statutory($p, $year);
    if (!hr_statutory($p, $year)) return null;   // not employed in that year
    $r = hr_rules(hr_jurisdiction($p)); $six = (int)($p['week_days'] ?? 5) === 6; $kids = (int)($p['children'] ?? 0); $svc = function_exists('hr_company_months') ? hr_company_months((int)$p['id'], $year . '-12-31') + (int)($p['prior_months'] ?? 0) : hr_service_months($p, $year . '-12-31');
    $v = ['kids' => $kids]; $d = null; $basis = $rk;
    switch ($rk) {
        case 'school_hours': $n = hr_children_aged($p, $year, (int)$r['sch_age_min'], (int)$r['sch_age_max']); $v['n'] = $n; $d = $n * (float)$r['sch_days'] * HR_HOURS_PER_DAY; break;
        case 'parental':     $n = hr_children_aged($p, $year, 0, (int)$r['par_age']); $v['n'] = $n; $d = $n * (float)$r['par_days_child']; break;
        case 'child_illness': $n = hr_children_aged($p, $year, 0, (int)$r['chs_age']); $v['n'] = $n; $d = $n === 0 ? 0 : ($n >= 3 ? (float)$r['chs_days_3'] : ($n === 2 ? (float)$r['chs_days_2'] : (float)$r['chs_days'])); break;
        case 'single_parent': $n = hr_children_aged($p, $year, 0, 18); $v['n'] = $n; $d = !empty($p['single_parent']) && $n > 0 ? ($n >= 3 ? (float)$r['mono_days_3'] : (float)$r['mono_days']) : 0; break;
        case 'carer':         $d = (float)$r['car_days']; break;
        case 'force_majeure': $d = (float)$r['fmj_days']; break;
        case 'marriage':      $d = $six ? (float)$r['mar_days_6'] : (float)$r['mar_days']; break;
        case 'paternity': case 'maternity':                                                                              // R60-001: once per birth, in the birth year; minus what was taken at a previous employer for that birth
            $n = hr_births_in($p, $year); $v['n'] = $n; $per = $rk === 'paternity' ? (float)$r['pat_days'] : (float)$r['mat_days'];
            $d = (($rk === 'paternity' && ($p['gender'] ?? '') === 'f') || ($rk === 'maternity' && ($p['gender'] ?? '') === 'm')) ? 0 : $n * $per;
            $o = function_exists('hr_person_type_overrides') ? (hr_person_type_overrides((int)$p['id'])[(int)$t['id']] ?? null) : null;
            if ($o && $o['used_before'] !== null && (int)$o['used_year'] === $year) { $v['used'] = (float)$o['used_before']; $d = max(0.0, $d - (float)$o['used_before']); $basis = $rk . '_used'; }
            break;
        case 'study':         $d = (float)$r['study_days']; break;
        case 'sick':          $v['svc'] = $svc; $d = $svc >= (int)$r['sick_service'] ? (float)$r['sick_long'] : (float)$r['sick_short']; $basis = $svc >= (int)$r['sick_service'] ? 'sick_long' : 'sick_short'; break;
        case 'rol_hours':     $d = (float)$r['rol_hours']; break;
        case 'law104':        $d = !empty($p['disability_care']) ? (float)$r['law104_days'] : 0; break;
        default: return null;
    }
    if ($d === null) return null; return ['days' => (float)$d, 'basis' => $basis, 'vars' => $v];
}
/** Effective annual-leave days of a person for a year: the engine unless HR fixed the value (auto = 0 with a row). Returns [days, 'auto'|'manual'|'none', statutory|null]. */
function hr_entitlement_effective(array $p, int $tid, int $year): array {
    $row = one('SELECT * FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) ORDER BY person_id IS NULL LIMIT 1', [$year, $tid, (int)$p['id'], (int)($p['user_id'] ?? 0), (int)($p['user_id'] ?? 0)]);
    $t = hr_type($tid); $isAnn = $t && ($t['rule_key'] ?? '') !== '' && hr4_has(); $st = $isAnn ? hr_statutory_type($p, $t, $year) : null;
    if ($row && !$isAnn) return [(float)$row['days'], 'manual', null];
    if ($row && (int)($row['auto'] ?? 0) === 0 && hr4_has()) return [(float)$row['days'], 'manual', $st];
    if ($st) return [(float)$st['days'], 'auto', $st];
    return [$row ? (float)$row['days'] : 0.0, $row ? 'manual' : 'none', null];
}
/** HR unlocks / locks a person-year: POST pid, tid, year, mode=auto|manual, days, note. */
function hr_entitlement_override_from_post(): void {
    if (!hr_can() || !hr4_has()) throw new RuntimeException(t('err.noaccess'));
    $pid = (int)($_POST['pid'] ?? 0); $tid = (int)($_POST['tid'] ?? 0); $year = (int)($_POST['year'] ?? 0); $p = hr_person($pid); if (!$p || !hr_type($tid) || $year < 2000) throw new RuntimeException(t('err.notfound'));
    $mode = ($_POST['mode'] ?? '') === 'auto' ? 'auto' : 'manual'; $days = min(2000, max(0, (float) str_replace(',', '.', (string)($_POST['days'] ?? '0')))); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200) ?: null; $me = (int)(current_user()['id'] ?? 0);
    [$oldDays, $oldMode] = hr_entitlement_effective($p, $tid, $year); $st = hr_statutory($p, $year); if ($mode === 'auto') $days = $st ? (float)$st['days'] : $oldDays;
    $row = one('SELECT id FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) LIMIT 1', [$year, $tid, $pid, (int)($p['user_id'] ?? 0), (int)($p['user_id'] ?? 0)]);
    if ($row) q('UPDATE hr_entitlements SET days = ?, auto = ?, override_note = ?, person_id = ?, updated_by = ? WHERE id = ?', [$days, $mode === 'auto' ? 1 : 0, $note, $pid, $me, (int)$row['id']]);
    else q('INSERT INTO hr_entitlements (user_id, person_id, year, type_id, days, carry, auto, override_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?)', [(int)($p['user_id'] ?? 0) ?: null, $pid, $year, $tid, $days, 0, $mode === 'auto' ? 1 : 0, $note, $me]);
    hr_audit($pid, 'entitlement', hr_type($tid)['code'] . ' ' . $year, $oldDays . ' (' . $oldMode . ')', $days . ' (' . $mode . ')', $note);
}
/** Year roll-over: one auto row per employed person for $year (annual type), carry = min(cap, remaining of the previous year); nothing overwritten. */
function hr_entitlements_rollover(int $year): int {
    if (!hr_can() || !hr4_has()) throw new RuntimeException(t('err.noaccess')); $ann = hr_annual_type_id(); if (!$ann) return 0; $cap = (float) hr_rules()['carry_max']; $me = (int)(current_user()['id'] ?? 0); $n = 0;
    foreach (hr_people() as $p) {
        if (scalar('SELECT COUNT(*) FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0))', [$year, $ann, (int)$p['id'], (int)($p['user_id'] ?? 0), (int)($p['user_id'] ?? 0)])) continue;
        $st = hr_statutory($p, $year); if (!$st) continue; $prev = hr_balance($p, $ann, $year - 1); $carry = $prev['available'] !== null ? max(0.0, min($cap, (float)$prev['available'])) : 0.0;
        q('INSERT INTO hr_entitlements (user_id, person_id, year, type_id, days, carry, auto, override_note, updated_by) VALUES (?,?,?,?,?,?,1,?,?)', [(int)($p['user_id'] ?? 0) ?: null, (int)$p['id'], $year, $ann, (float)$st['days'], $carry, 'roll-over ' . date('Y-m-d'), $me]); $n++;
        hr_audit((int)$p['id'], 'entitlement', hr_type($ann)['code'] . ' ' . $year, null, $st['days'] . ' (auto) · carry ' . $carry, 'roll-over');
    }
    return $n;
}
function hr_basis_text(?array $st): string { if (!$st) return ''; $v = $st['vars']; if (!in_array(preg_replace('/_pr$/', '', $st['basis']), ['y1', 'y2', 'y3', 'senior', 'long'], true)) return t('hr.basis_' . $st['basis'], ['n' => $v['n'] ?? '', 'u' => $v['used'] ?? '', 'k' => $v['kids'] ?? '', 's' => isset($v['svc']) && function_exists('hr_fmt_months') ? hr_fmt_months((int)$v['svc']) : '']); $m = fn(int $x) => function_exists('hr_fmt_months') ? hr_fmt_months($x) : $x . ' m'; return t('hr.basis_' . preg_replace('/_pr$/', '', $st['basis']), ['full' => $v['full'] ?? '', 'd' => $v['days'] ?? '', 'c' => $m((int)($v['company'] ?? 0)), 'p' => $m((int)($v['prior'] ?? 0)), 't' => $m((int)($v['total'] ?? 0))]) . (str_ends_with($st['basis'], '_pr') ? ' · ' . t('hr.basis_prorata') : ''); }

/* ------------------------------------------------------------ change log */
function hr_audit(int $pid, string $kind, ?string $field = null, ?string $old = null, ?string $new = null, ?string $note = null): void {
    if (!hr4_has()) return; try { q('INSERT INTO hr_audit (person_id, user_id, kind, field, old_value, new_value, note) VALUES (?,?,?,?,?,?,?)', [$pid, (int)(current_user()['id'] ?? 0) ?: null, mb_substr($kind, 0, 16), $field !== null ? mb_substr($field, 0, 40) : null, $old !== null ? mb_substr($old, 0, 300) : null, $new !== null ? mb_substr($new, 0, 300) : null, $note !== null ? mb_substr($note, 0, 300) : null]); } catch (Throwable $e) {}
}
function hr_audit_list(int $pid, int $limit = 300): array { return hr4_has() ? all('SELECT a.*, cu.full_name AS by_name FROM hr_audit a LEFT JOIN customer_users cu ON cu.id = a.user_id WHERE a.person_id = ? ORDER BY a.created_at DESC, a.id DESC LIMIT ' . (int)$limit, [$pid]) : []; }
/** Diff two card snapshots and log each changed field. */
function hr_audit_card(int $pid, array $before, array $after, ?string $note = null): void {
    foreach (['emp_code', 'name_en', 'name_el', 'user_id', 'department_id', 'job_title', 'contract_type', 'part_time', 'contract_end', 'hire_date', 'prior_months', 'week_days', 'marital', 'children', 'children_years', 'is_technical', 'is_active', 'left_date', 'notes', 'jurisdiction', 'employment_kind', 'single_parent', 'disability_care', 'gender', 'personal_email', 'phone_mobile', 'phone_home', 'address', 'birth_date', 'amka', 'afm', 'doy', 'id_number', 'iban', 'bank', 'emergency_name', 'emergency_phone'] as $f) {
        $a = (string)($before[$f] ?? ''); $b = (string)($after[$f] ?? ''); if ($a === $b) continue;
        if (defined('HR_PERSONAL_SECRET') && in_array($f, HR_PERSONAL_SECRET, true)) { $a = $a === '' ? '' : hr_mask($a); $b = $b === '' ? '' : hr_mask($b); }   // R60-007: never the full number in the log
        if ($f === 'user_id') { $a = $a ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)$a]) : ''; $b = $b ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)$b]) : ''; }
        if ($f === 'department_id') { $a = $a ? (string) scalar('SELECT name FROM disciplines WHERE id = ?', [(int)$a]) : ''; $b = $b ? (string) scalar('SELECT name FROM disciplines WHERE id = ?', [(int)$b]) : ''; }
        hr_audit($pid, 'card', $f, $a, $b, $note);
    }
}

/* ------------------------------------------------------------ import batches (undo) */
function hr_import_batches(): array { if (!hr2_has()) return []; return all("SELECT hr_note AS batch, COUNT(*) AS n, MIN(date_from) AS d1, MAX(date_from) AS d2, SUM(status = 'approved') AS appr FROM hr_requests WHERE hr_note LIKE 'Import %' GROUP BY hr_note ORDER BY MAX(id) DESC"); }
function hr_import_batch_delete(string $batch): int {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); if (!str_starts_with($batch, 'Import ')) throw new RuntimeException(t('err.notfound'));
    $ids = array_map('intval', array_column(all('SELECT id FROM hr_requests WHERE hr_note = ?', [$batch]), 'id')); if (!$ids) return 0; $in = implode(',', $ids);
    q("DELETE FROM plan_entries WHERE request_id IN ($in)"); q("DELETE FROM hr_requests WHERE id IN ($in)"); hr_audit(0, 'import', 'delete', $batch, (string) count($ids)); return count($ids);
}

/* ------------------------------------------------------------ gender and per-person leave types (GAS-CP-R55-004) */
function hr5_has(): bool { static $h = null; if ($h === null) { try { $h = hr4_has() && (bool) scalar("SHOW TABLES LIKE 'hr_person_types'") && (bool) scalar("SHOW COLUMNS FROM hr_people LIKE 'gender'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Default applicability of a type for a person before any switch on the card: gender for maternity / paternity, flags for single-parent / L.104, contractors get only non-statutory types. */
function hr_type_default_enabled(array $p, array $t): bool {
    $rk = (string)($t['rule_key'] ?? ''); $g = (string)($p['gender'] ?? '');
    if ($rk === 'maternity' && $g === 'm') return false; if ($rk === 'paternity' && $g === 'f') return false;
    if ($rk === 'single_parent' && empty($p['single_parent'])) return false; if ($rk === 'law104' && empty($p['disability_care'])) return false;
    if ($rk === 'rol_hours' && hr_jurisdiction($p) !== 'IT') return false;
    if (hr_is_contractor($p) && in_array($rk, ['annual', 'sick', 'maternity', 'paternity', 'parental', 'school_hours', 'child_illness', 'single_parent', 'carer', 'force_majeure', 'marriage', 'study', 'rol_hours', 'law104'], true)) return false;
    return true;
}
function hr6_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM hr_person_types LIKE 'used_before'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_person_type_overrides(int $pid): array { static $c = []; if (!isset($c[$pid])) { $c[$pid] = []; if (hr5_has()) foreach (all('SELECT * FROM hr_person_types WHERE person_id = ?', [$pid]) as $r) $c[$pid][(int)$r['type_id']] = $r; } return $c[$pid]; }
/** Is this type available to the person? Card switch wins, else the default. */
function hr_type_enabled(array $p, array $t): bool { if (!hr5_has()) return true; $o = hr_person_type_overrides((int)$p['id'])[(int)$t['id']] ?? null; return $o ? (bool)$o['enabled'] : hr_type_default_enabled($p, $t); }
/** Types the person may request (active, enabled for him). */
function hr_types_for_person(array $p): array { return array_filter(hr_types(), fn($t) => hr_type_enabled($p, $t)); }
/** POST pid, en[type_id]=1 for enabled ones, note[type_id]. Only differences from the default are stored. */
function hr_person_types_save_from_post(): void {
    if (!hr_can() || !hr5_has()) throw new RuntimeException(t('err.noaccess')); $pid = (int)($_POST['pid'] ?? 0); $p = hr_person($pid); if (!$p) throw new RuntimeException(t('err.notfound')); $me = (int)(current_user()['id'] ?? 0);
    foreach (hr_types(false) as $tid => $t) {
        $want = !empty($_POST['en'][$tid]); $note = mb_substr(trim((string)($_POST['note'][$tid] ?? '')), 0, 200) ?: null; $was = hr_type_enabled($p, $t); $def = hr_type_default_enabled($p, $t);
        $birth = in_array((string)($t['rule_key'] ?? ''), ['maternity', 'paternity'], true) && hr6_has();
        $ub = $birth && trim((string)($_POST['used_before'][$tid] ?? '')) !== '' ? max(0.0, min(999.0, (float) str_replace(',', '.', (string)$_POST['used_before'][$tid]))) : null;
        $uy = $birth && $ub !== null ? (int)($_POST['used_year'][$tid] ?? 0) ?: null : null; if ($ub !== null && !$uy) throw new RuntimeException(t('hr.need_used_year'));
        $old = hr_person_type_overrides($pid)[$tid] ?? null;
        if ($want === $def && $note === null && $ub === null) q('DELETE FROM hr_person_types WHERE person_id = ? AND type_id = ?', [$pid, $tid]);
        elseif (hr6_has()) q('INSERT INTO hr_person_types (person_id, type_id, enabled, note, used_before, used_year, updated_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), note = VALUES(note), used_before = VALUES(used_before), used_year = VALUES(used_year), updated_by = VALUES(updated_by)', [$pid, $tid, (int)$want, $note, $ub, $uy, $me]);
        else q('INSERT INTO hr_person_types (person_id, type_id, enabled, note, updated_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), note = VALUES(note), updated_by = VALUES(updated_by)', [$pid, $tid, (int)$want, $note, $me]);
        if ($want !== $was) hr_audit($pid, 'card', 'type ' . $t['code'], $was ? 'on' : 'off', $want ? 'on' : 'off', $note);
        if ($birth && ((float)($old['used_before'] ?? -1) !== (float)($ub ?? -1) || (int)($old['used_year'] ?? 0) !== (int)($uy ?? 0))) hr_audit($pid, 'card', 'type ' . $t['code'] . ' ' . t('hr.used_before'), $old && $old['used_before'] !== null ? $old['used_before'] . ' (' . $old['used_year'] . ')' : null, $ub !== null ? $ub . ' (' . $uy . ')' : null, $note);
    }
}

/* ------------------------------------------------------------ carry-over per person and status summary (GAS-CP-R55-004) */
/** Transfer the untaken annual leave of $year-1 into $year for one person (HR button on the card; uncapped unless $days given; logged). */
function hr_carry_transfer(int $pid, int $year, ?float $days = null, ?string $note = null): float {
    if (!hr_can() || !hr4_has()) throw new RuntimeException(t('err.noaccess')); $p = hr_person($pid); $ann = hr_annual_type_id(); if (!$p || !$ann) throw new RuntimeException(t('err.notfound'));
    $prev = hr_balance($p, $ann, $year - 1); $carry = $days !== null ? max(0.0, min(400.0, $days)) : max(0.0, (float)($prev['available'] ?? 0)); $me = (int)(current_user()['id'] ?? 0);
    $row = one('SELECT * FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) LIMIT 1', [$year, $ann, $pid, (int)($p['user_id'] ?? 0), (int)($p['user_id'] ?? 0)]);
    if ($row) q('UPDATE hr_entitlements SET carry = ?, person_id = ?, updated_by = ? WHERE id = ?', [$carry, $pid, $me, (int)$row['id']]);
    else { $st = hr_statutory($p, $year); q('INSERT INTO hr_entitlements (user_id, person_id, year, type_id, days, carry, auto, override_note, updated_by) VALUES (?,?,?,?,?,?,1,?,?)', [(int)($p['user_id'] ?? 0) ?: null, $pid, $year, $ann, $st ? (float)$st['days'] : 0, $carry, 'carry ' . date('Y-m-d'), $me]); }
    hr_audit($pid, 'entitlement', 'ANN ' . $year . ' ' . t('hr.carry'), (string)($row['carry'] ?? 0), (string)$carry, $note ?: t('hr.carry_from', ['y' => $year - 1]));
    return $carry;
}
/** Leave status of a person for the card strip: the annual balance and every other enabled type with something available, taken or pending. */
function hr_status_summary(array $p, int $year): array { $o = ['annual' => null, 'others' => []]; $ann = hr_annual_type_id(); foreach (hr_balances($p, $year) as $tid => $b) { if ($tid === $ann) { $o['annual'] = $b; continue; } if (($b['available'] !== null && $b['available'] > 0) || $b['taken'] > 0 || $b['pending'] > 0) $o['others'][$tid] = $b; } return $o; }

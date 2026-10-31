<?php
/**
 * HR — staff registry, departments, leave requests with manager → HR approval, balances with pending requests,
 * hour types (early leave consumes annual leave at 8 h = 1 day), opening balances imported from the accountant's Excel
 * with a one-time Greek ↔ Latin name matching table (GAS-CP-R54-001, Solution C step 1). The statutory engine comes with R55.
 */
declare(strict_types=1);

const HR_HOURS_PER_DAY = 8.0;
const HR_REQ_STATUS = ['pending_mgr', 'pending_hr', 'approved', 'rejected', 'cancelled'];

function hr2_has(): bool { static $h = null; if ($h === null) { try { $h = hr_has() && (bool) scalar("SHOW TABLES LIKE 'hr_requests'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Employee scope: a staff role with scope "employee" sees My leave and the account only. */
function emp_only(): bool { static $v = null; if ($v !== null) return $v; $u = current_user(); if (!$u || !is_staff() || is_cp_admin() || empty($u['staff_role_id'])) return $v = false; try { return $v = (string) scalar('SELECT scope FROM staff_roles WHERE id = ?', [(int)$u['staff_role_id']]) === 'employee'; } catch (Throwable $e) { return $v = false; } }
const EMP_PAGES = ['community', 'socphoto', 'my_leave', 'hr_doc', 'planning', 'hr_pdoc', 'account', 'manual', 'notifications', 'nf_open', 'kb', 'kb_q', 'kbfile', 'avatar', 'logout', 'login', 'twofa', 'ping', 'relock', '404', 'hra', 'hra_flow', 'hra_doc'];
const EMP_ACTIONS = ['hra_start', 'hra_consent', 'hra_step', 'hra_doc', 'hra_doc_del', 'hra_assist', 'hra_submit', 'hra_withdraw', 'soc_wish', 'soc_kudos', 'soc_post', 'soc_react', 'soc_comment', 'soc_vote', 'soc_onboard_tick', 'hr_req_new', 'hr_req_cancel', 'hr_period_join', 'hr_tr_apply', 'hr_ev_ack', 'hr_tg_progress', 'hr_msg_send', 'account_save', 'account_revoke', 'twofa_begin', 'twofa_confirm', 'twofa_off', 'theme_save', 'avatar_save', 'avatar_remove', 'logout', 'nf_seen', 'nf_seen_all', 'kb_ask', 'kb_answer', 'flt_reset'];
function emp_gate(string $page): void { if (emp_only() && !in_array($page, EMP_PAGES, true)) { flash('warn', t('hr.emp_only')); redirect('my_leave'); } }

/* ------------------------------------------------------------ registry */
/** R56-002: the organisation structure is the portal's Disciplines (Settings › Disciplines); kept under the old name for the callers. head_user_id = discipline manager. */
function hr_departments(bool $activeOnly = true): array { static $c = []; $k = (int)$activeOnly; if (!isset($c[$k])) { $c[$k] = []; try { $c[$k] = all('SELECT d.id, d.name, d.sort_order, d.is_active, d.manager_user_id AS head_user_id, cu.full_name AS head_name FROM disciplines d LEFT JOIN customer_users cu ON cu.id = d.manager_user_id' . ($activeOnly ? ' WHERE d.is_active = 1' : '') . ' ORDER BY d.is_active DESC, d.sort_order, d.name'); } catch (Throwable $e) { $c[$k] = []; } } return $c[$k]; }
function hr_department_save_from_post(): void { throw new RuntimeException(t('hr.depts_moved')); }
/** All people of the registry (active first) with their account and department. */
function hr_people(bool $activeOnly = true): array {
    if (!hr2_has()) return [];
    return all('SELECT p.*, cu.full_name AS account_name, cu.email, cu.is_active AS account_active, cu.discipline_id, cu.manager_user_id AS account_manager_id, dp.name AS department, dp.manager_user_id AS head_user_id, dc.name AS discipline, dc.manager_user_id
                FROM hr_people p LEFT JOIN customer_users cu ON cu.id = p.user_id LEFT JOIN disciplines dp ON dp.id = p.department_id LEFT JOIN disciplines dc ON dc.id = cu.discipline_id' . ($activeOnly ? ' WHERE p.is_active = 1' : '') . ' ORDER BY p.is_active DESC, p.is_technical DESC, dp.sort_order, p.name_en');
}
function hr_person(int $id): ?array { foreach (hr_people(false) as $p) if ((int)$p['id'] === $id) return $p; return null; }
function hr_person_of_user(?int $uid): ?array { if (!$uid || !hr2_has()) return null; foreach (hr_people(false) as $p) if ((int)$p['user_id'] === $uid) return $p; return null; }
/** Technical people's account ids (for the PMs' Planning board); null when the registry does not decide (no flags set). */
function hr_technical_user_ids(): ?array { if (!hr2_has()) return null; $ids = []; $any = false; foreach (hr_people() as $p) { $any = true; if ($p['is_technical'] && $p['user_id']) $ids[] = (int)$p['user_id']; } return $any ? $ids : null; }
function hr_person_save_from_post(): int {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $en = mb_substr(trim((string)($_POST['name_en'] ?? '')), 0, 120); if ($en === '') throw new RuntimeException(t('hr.person_need'));
    $el = mb_substr(trim((string)($_POST['name_el'] ?? '')), 0, 120) ?: null; $uid = (int)($_POST['user_id'] ?? 0) ?: null;
    if ($uid && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin')", [$uid])) $uid = null;
    if ($uid && scalar('SELECT COUNT(*) FROM hr_people WHERE user_id = ? AND id <> ?', [$uid, $id])) throw new RuntimeException(t('hr.account_taken'));
    $dept = (int)($_POST['department_id'] ?? 0) ?: null; $hire = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['hire_date'] ?? '')) ? $_POST['hire_date'] : null;
    $v = [$uid, $el, $en, $dept, (int)!empty($_POST['is_technical']), $hire, max(0, min(600, (int)($_POST['prior_months'] ?? 0))), in_array((int)($_POST['week_days'] ?? 5), [5, 6], true) ? (int)$_POST['week_days'] : 5, max(0, min(12, (int)($_POST['children'] ?? 0))), mb_substr(trim((string)($_POST['children_years'] ?? '')), 0, 60) ?: null, (int)!empty($_POST['is_active']), mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null];
    if (isset($_POST['prior_years'])) $v[6] = max(0, min(600, (int)$_POST['prior_years'] * 12 + (int)($_POST['prior_months'] ?? 0)));   // R56: entered as years + months
    if ($id) { q('UPDATE hr_people SET user_id = ?, name_el = ?, name_en = ?, department_id = ?, is_technical = ?, hire_date = ?, prior_months = ?, week_days = ?, children = ?, children_years = ?, is_active = ?, notes = ? WHERE id = ?', [...$v, $id]); }
    else { q('INSERT INTO hr_people (user_id, name_el, name_en, department_id, is_technical, hire_date, prior_months, week_days, children, children_years, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', $v); $id = (int) db()->lastInsertId(); }
    if (function_exists('hr3_has') && hr3_has()) {   // R56-001: card fields + the open employment period follows the hiring date
        $code = mb_substr(trim((string)($_POST['emp_code'] ?? '')), 0, 12) ?: null; if ($code && scalar('SELECT COUNT(*) FROM hr_people WHERE emp_code = ? AND id <> ?', [$code, $id])) throw new RuntimeException(t('sys.dup', ['v' => $code]));
        $ct = in_array($_POST['contract_type'] ?? '', HR_CONTRACTS, true) ? $_POST['contract_type'] : 'indefinite'; $ce = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['contract_end'] ?? '')) ? $_POST['contract_end'] : null;
        $mar = in_array($_POST['marital'] ?? '', HR_MARITAL, true) ? $_POST['marital'] : null;
        q('UPDATE hr_people SET emp_code = ?, job_title = ?, contract_type = ?, part_time = ?, contract_end = ?, marital = ? WHERE id = ?', [$code, mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 120) ?: null, $ct, (int)!empty($_POST['part_time']), $ce, $mar, $id]);
        if (function_exists('hr_jurisdiction') && isset($_POST['jurisdiction'])) q('UPDATE hr_people SET jurisdiction = ?, employment_kind = ?, single_parent = ?, disability_care = ? WHERE id = ?', [in_array($_POST['jurisdiction'], HR_JURISDICTIONS, true) ? $_POST['jurisdiction'] : 'GR', ($_POST['employment_kind'] ?? '') === 'contractor' ? 'contractor' : 'employee', (int)!empty($_POST['single_parent']), (int)!empty($_POST['disability_care']), $id]);   // R55-002
        if (function_exists('hr5_has') && hr5_has() && isset($_POST['gender'])) q('UPDATE hr_people SET gender = ? WHERE id = ?', [in_array($_POST['gender'], ['m', 'f'], true) ? $_POST['gender'] : null, $id]);   // R55-004
        if (function_exists('hr7_has') && hr7_has() && isset($_POST['personal']) && function_exists('hr_personal_can') && hr_personal_can()) hr_personal_save($id, $_POST);   // R60-007 / R64-001: HR and Owner only
        $open = hr_employment_open($id);
        if ($hire && !$open && !scalar('SELECT COUNT(*) FROM hr_employments WHERE person_id = ?', [$id])) q('INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, contract_end, job_title, created_by) VALUES (?,?,?,?,?,?,?,?)', [$id, 'hire', $hire, $ct, (int)!empty($_POST['part_time']), $ce, mb_substr(trim((string)($_POST['job_title'] ?? '')), 0, 120) ?: null, (int)(current_user()['id'] ?? 0)]);
        elseif ($hire && $open) q('UPDATE hr_employments SET date_from = ?, contract_type = ?, part_time = ?, contract_end = ? WHERE id = ?', [$hire, $ct, (int)!empty($_POST['part_time']), $ce, (int)$open['id']]);
    }
    return $id;
}
/* ------------------------------------------------------------ personal data (GAS-CP-R60-007) — HR only */
const HR_PERSONAL = ['personal_email', 'phone_mobile', 'phone_home', 'address', 'birth_date', 'amka', 'afm', 'doy', 'id_number', 'iban', 'bank', 'emergency_name', 'emergency_phone',
    'father_name', 'mother_name', 'spouse_name', 'nationality', 'birth_place', 'passport_no', 'blood_group', 'education', 'experience_level', 'transport', 'phone_work', 'extension', 'postcode', 'city', 'country', 'gross_salary', 'oaed_benefit', 'payroll_code'];   // R62-001: payroll-export fields
const HR_PERSONAL_SECRET = ['amka', 'afm', 'id_number', 'iban', 'passport_no', 'gross_salary'];
function hr8_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM hr_people LIKE 'gross_salary'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_personal_fields(): array { return hr8_has() ? HR_PERSONAL : array_slice(HR_PERSONAL, 0, 13); }                                                    // masked in the change log and in previews
function hr7_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM hr_people LIKE 'iban'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_mask(string $v): string { $v = trim($v); $n = mb_strlen($v); return $n <= 4 ? str_repeat('•', $n) : str_repeat('•', $n - 4) . mb_substr($v, -4); }
/** Validate and normalise the personal fields from a form or an import row; returns [field => value|null]. */
function hr_personal_from(array $in): array {
    $o = []; $s = fn(string $k, int $max) => mb_substr(trim((string)($in[$k] ?? '')), 0, $max) ?: null;
    $o['personal_email'] = ($e = $s('personal_email', 190)) !== null ? (filter_var($e, FILTER_VALIDATE_EMAIL) ? strtolower($e) : throw new RuntimeException(t('hr.bad_email'))) : null;
    foreach (['phone_mobile', 'phone_home', 'emergency_phone'] as $k) $o[$k] = ($v = $s($k, 40)) !== null ? preg_replace('/[^0-9+ ()\-]/', '', $v) : null;
    $o['address'] = $s('address', 200); $o['doy'] = $s('doy', 80); $o['bank'] = $s('bank', 80); $o['emergency_name'] = $s('emergency_name', 120); $o['id_number'] = $s('id_number', 30);
    $o['birth_date'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($in['birth_date'] ?? '')) ? $in['birth_date'] : null;
    $amka = preg_replace('/\D/', '', (string)($in['amka'] ?? '')); if ($amka !== '' && strlen($amka) !== 11) throw new RuntimeException(t('hr.bad_amka')); $o['amka'] = $amka ?: null;
    $afm = preg_replace('/\D/', '', (string)($in['afm'] ?? '')); if ($afm !== '' && strlen($afm) !== 9) throw new RuntimeException(t('hr.bad_afm')); $o['afm'] = $afm ?: null;
    $iban = strtoupper(preg_replace('/\s+/', '', (string)($in['iban'] ?? ''))); if ($iban !== '' && !hr_iban_ok($iban)) throw new RuntimeException(t('hr.bad_iban')); $o['iban'] = $iban ?: null;
    if (hr8_has()) {   // R62-001
        foreach (['father_name' => 80, 'mother_name' => 80, 'spouse_name' => 80, 'nationality' => 60, 'birth_place' => 80, 'passport_no' => 30, 'blood_group' => 5, 'education' => 120, 'experience_level' => 40, 'transport' => 60, 'extension' => 10, 'postcode' => 12, 'city' => 80, 'country' => 60, 'oaed_benefit' => 40, 'payroll_code' => 12] as $k => $max) $o[$k] = $s($k, $max);
        $o['phone_work'] = ($v = $s('phone_work', 40)) !== null ? preg_replace('/[^0-9+ ()\\-]/', '', $v) : null;
        $gs = trim((string)($in['gross_salary'] ?? '')); $o['gross_salary'] = $gs === '' ? null : max(0.0, (float) str_replace(',', '.', preg_replace('/[^0-9,.]/', '', $gs)));
    }
    return $o;
}
function hr_iban_ok(string $iban): bool { if (!preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) return false; $t = substr($iban, 4) . substr($iban, 0, 4); $n = ''; foreach (str_split($t) as $c) $n .= ctype_alpha($c) ? (string)(ord($c) - 55) : $c; $r = 0; foreach (str_split($n, 7) as $chunk) $r = (int)(($r . $chunk) % 97); return $r === 1; }
function hr_personal_save(int $pid, array $in): void { if (!hr7_has()) return; $o = hr_personal_from($in); $F = hr_personal_fields(); q('UPDATE hr_people SET ' . implode(', ', array_map(fn($k) => "$k = ?", $F)) . ' WHERE id = ?', [...array_map(fn($k) => $o[$k] ?? null, $F), $pid]); }
/** Service at a date: months at Golden A/S + prior months (for the R55 engine and the registry display). */
function hr_service_months(array $p, ?string $at = null): int { $m = (int)($p['prior_months'] ?? 0); if (!empty($p['hire_date'])) { $a = new DateTime($p['hire_date']); $b = new DateTime($at ?: date('Y-m-d')); if ($b > $a) { $d = $a->diff($b); $m += $d->y * 12 + $d->m; } } return $m; }

/* ------------------------------------------------------------ who approves */
/** Manager of a person: discipline manager for technical staff, department head otherwise; null = straight to HR. */
/** The approver of a person — the SAME chain the portal already uses for timesheets (ts_manager_for): the account's personal manager
 *  (Settings › Portal accounts), else the manager of the account's discipline, else the head of the HR department (people without an account). R54-003 */
function hr_manager_of(array $p): ?int {
    if (!empty($p['account_manager_id'])) return (int)$p['account_manager_id'];
    if (!empty($p['manager_user_id'])) return (int)$p['manager_user_id'];
    if (!empty($p['head_user_id'])) return (int)$p['head_user_id'];
    return null;
}
function hr_hr_user_ids(): array { try { return array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE r.scope IN ('hr', 'owner') AND cu.is_active = 1 UNION SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"), 'id')); } catch (Throwable $e) { return []; } }
/** Is the current user a manager of anyone (discipline manager or department head)? */
function hr_is_manager(): bool { static $v = null; if ($v === null) { $me = (int)(current_user()['id'] ?? 0); $v = $me > 0 && is_staff() && hr2_has() && ((bool) scalar('SELECT COUNT(*) FROM disciplines WHERE manager_user_id = ?', [$me]) || (bool) scalar('SELECT COUNT(*) FROM customer_users WHERE manager_user_id = ? AND is_active = 1', [$me])); } return $v; }

/* ------------------------------------------------------------ balances */
/** Working days between two dates for a person (calendar of the account; weekends / holidays skipped). */
function hr_working_days(array $p, string $from, string $to): int {
    $cal = !empty($p['user_id']) && function_exists('wc_user_calendar') ? wc_user_calendar((int)$p['user_id']) : 'GR'; $n = 0;
    for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) { $date = date('Y-m-d', $d); if (function_exists('wc_day')) { if (wc_day($cal, $date)['workday']) $n++; } elseif ((int) date('N', $d) < 6) $n++; }
    return $n;
}
/** Balance of one person / type / year in the type's unit: entitled, carry, opening, taken (approved), pending, available (null for non-counting types). */
function hr_cut_has(): bool { static $c = null; if ($c === null) { try { $c = (bool) scalar("SHOW COLUMNS FROM hr_entitlements LIKE 'counted_from'"); } catch (Throwable $e) { $c = false; } } return $c; }   // R60-004
function hr_counted_sql(): string { static $c = null; if ($c === null) { try { $c = (bool) scalar("SHOW COLUMNS FROM hr_requests LIKE 'counted'") ? ' AND counted = 1' : ''; } catch (Throwable $e) { $c = ''; } } return $c; }   // R56-003
/* ------------------------------------------------------------------ R69-002: bulk loaders for the balance engine (GET requests only)
   The planning board and the HR dashboard compute the balance of every person × every type; per-row queries made 5 000 queries a page.
   On a read-only request the year's entitlements, request sums and board days are loaded once and served from memory. */
function hr_bulk_ok(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'; }
/** The entitlement row of a person-year-type: the person's own row first, else the row attached to the account. */
function hr_ent_row(int $pid, int $uid, int $tid, int $year): ?array {
    if (!hr_bulk_ok()) return one('SELECT * FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) ORDER BY person_id IS NULL LIMIT 1', [$year, $tid, $pid, $uid, $uid]);
    static $Y = []; if (!isset($Y[$year])) { $Y[$year] = ['p' => [], 'u' => []]; foreach (all('SELECT * FROM hr_entitlements WHERE year = ? ORDER BY id', [$year]) as $r) { if ($r['person_id'] !== null && !isset($Y[$year]['p'][(int)$r['person_id'] . ':' . (int)$r['type_id']])) $Y[$year]['p'][(int)$r['person_id'] . ':' . (int)$r['type_id']] = $r; if ($r['user_id'] !== null && !isset($Y[$year]['u'][(int)$r['user_id'] . ':' . (int)$r['type_id']])) $Y[$year]['u'][(int)$r['user_id'] . ':' . (int)$r['type_id']] = $r; } }
    return $Y[$year]['p'][$pid . ':' . $tid] ?? ($uid > 0 ? ($Y[$year]['u'][$uid . ':' . $tid] ?? null) : null);
}
/** SUM(days|hours) of a person's requests of a type in a year — $group approved | pending; $counted = only counted rows; $cfR = the counted_from clause (forces a direct query). */
function hr_req_sum(int $pid, int $tid, int $year, string $group, string $field, bool $counted, string $cfR = ''): float {
    $st = $group === 'approved' ? "status = 'approved'" : "status IN ('pending_mgr','pending_hr')";
    if (!hr_bulk_ok() || $cfR !== '') return (float) scalar("SELECT COALESCE(SUM($field),0) FROM hr_requests WHERE person_id = ? AND type_id = ? AND YEAR(date_from) = ? AND $st" . ($counted ? hr_counted_sql() : '') . $cfR, [$pid, $tid, $year]);
    static $Y = []; if (!isset($Y[$year])) { $Y[$year] = []; $hasC = hr_counted_sql() !== '';
        foreach (all("SELECT person_id, type_id, status = 'approved' ap, " . ($hasC ? 'counted' : '1') . " c, COALESCE(SUM(days),0) d, COALESCE(SUM(hours),0) h FROM hr_requests WHERE YEAR(date_from) = ? AND status IN ('approved','pending_mgr','pending_hr') GROUP BY person_id, type_id, ap, c", [$year]) as $r) { $k = (int)$r['person_id'] . ':' . (int)$r['type_id'] . ':' . ((int)$r['ap'] ? 'approved' : 'pending'); $Y[$year][$k]['all_d'] = ($Y[$year][$k]['all_d'] ?? 0) + (float)$r['d']; $Y[$year][$k]['all_h'] = ($Y[$year][$k]['all_h'] ?? 0) + (float)$r['h']; if ((int)$r['c'] === 1) { $Y[$year][$k]['c_d'] = ($Y[$year][$k]['c_d'] ?? 0) + (float)$r['d']; $Y[$year][$k]['c_h'] = ($Y[$year][$k]['c_h'] ?? 0) + (float)$r['h']; } } }
    $k = $pid . ':' . $tid . ':' . $group; $f = ($counted && hr_counted_sql() !== '' ? 'c_' : 'all_') . ($field === 'hours' ? 'h' : 'd'); return (float)($Y[$year][$k][$f] ?? 0);
}
/** Board days typed outside the request flow (request_id IS NULL) for an account-year-type. */
function hr_board_days(int $uid, int $tid, int $year, string $cfP = ''): float {
    if (!hr_bulk_ok() || $cfP !== '') return (float) scalar('SELECT COUNT(DISTINCT plan_date) FROM plan_entries WHERE user_id = ? AND leave_type_id = ? AND YEAR(plan_date) = ? AND request_id IS NULL' . $cfP, [$uid, $tid, $year]);
    static $Y = []; if (!isset($Y[$year])) { $Y[$year] = []; try { foreach (all('SELECT user_id, leave_type_id, COUNT(DISTINCT plan_date) n FROM plan_entries WHERE YEAR(plan_date) = ? AND request_id IS NULL AND leave_type_id IS NOT NULL GROUP BY user_id, leave_type_id', [$year]) as $r) $Y[$year][(int)$r['user_id'] . ':' . (int)$r['leave_type_id']] = (float)$r['n']; } catch (Throwable $e) {} }
    return $Y[$year][$uid . ':' . $tid] ?? 0.0;
}
function hr_balance(array $p, int $tid, int $year): array {
    // R69-002: the board and the HR dashboard ask the same balance hundreds of times per page — remember it for this request (reads only; a POST recomputes)
    static $memo = []; $mk = (int)$p['id'] . ':' . $tid . ':' . $year; $ro = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
    if ($ro && isset($memo[$mk])) return $memo[$mk];
    $b = hr_balance_compute($p, $tid, $year); if ($ro) $memo[$mk] = $b; return $b;
}
function hr_balance_compute(array $p, int $tid, int $year): array {
    $t = hr_type($tid); $pid = (int)$p['id']; $uid = (int)($p['user_id'] ?? 0);
    $e = hr_ent_row($pid, $uid, $tid, $year);   // R69-002 bulk-loaded on reads
    $ent = (float)($e['days'] ?? 0); $carry = (float)($e['carry'] ?? 0); $open = (float)($e['opening_taken'] ?? 0); $mode = $e ? 'manual' : 'none'; $st = null;
    $cf = !empty($e['counted_from']) ? (string)$e['counted_from'] : null; $cfR = $cf ? " AND date_from > '" . $cf . "'" : ''; $cfP = $cf ? " AND plan_date > '" . $cf . "'" : '';   // R60-004: records up to the cut-over date are inside opening_taken
    if (function_exists('hr4_has') && hr4_has() && $t && ($t['rule_key'] ?? '') !== '' && !(function_exists('hr_is_contractor') && hr_is_contractor($p))) { [$ent, $mode, $st] = hr_entitlement_effective($p, $tid, $year); }   // R55-001/002: the engine unless HR fixed the value
    $taken = hr_req_sum($pid, $tid, $year, 'approved', 'days', true, $cfR);
    $pend = hr_req_sum($pid, $tid, $year, 'pending', 'days', true, $cfR);
    if ($t && $t['unit'] === 'hour') { $taken = hr_req_sum($pid, $tid, $year, 'approved', 'hours', true, $cfR); $pend = hr_req_sum($pid, $tid, $year, 'pending', 'hours', true); }
    // board entries written outside the request flow (HR typed them on the board) count as taken for day types
    if ($uid && (!$t || $t['unit'] === 'day')) $taken += hr_board_days($uid, $tid, $year, $cfP);
    // annual leave also carries the hours of annual-consuming hour types (8 h = 1 day)
    $accH = 0.0;   // R64-006: hour types that consume annual leave accumulate; only whole days (8 h) are offset against the annual balance, the remainder waits for the next hours
    if ($t && $t['rule_key'] === 'annual') { foreach (hr_types() as $x) if ($x['consumes_annual'] && $x['unit'] === 'hour') { $h = hr_req_sum($pid, (int)$x['id'], $year, 'approved', 'hours', true, $cfR); $taken += floor($h / HR_HOURS_PER_DAY); $accH += fmod($h, HR_HOURS_PER_DAY); $pend += hr_req_sum($pid, (int)$x['id'], $year, 'pending', 'hours', false) / HR_HOURS_PER_DAY; } }
    $cap = $t && $t['max_year'] !== null ? (float)$t['max_year'] : null; $counts = $t && ($t['counts'] || $cap !== null);
    if (function_exists('hr_is_contractor') && hr_is_contractor($p)) { $counts = false; $cap = null; }   // R55-002: contractors — agreed by the manager, no balances
    $total = $cap !== null && $ent <= 0 && $mode === 'none' ? $cap : $ent + $carry;
    $via = false; $avail = $counts ? round($total - $open - $taken - $pend, 2) : null;
    if ($t && $t['unit'] === 'hour' && $t['consumes_annual'] && ($ann = hr_annual_type_id()) && $ann !== $tid) { $ab = hr_balance($p, $ann, $year); $via = true; $counts = true; $total = 0.0; $avail = $ab['available'] === null ? null : round($ab['available'] * HR_HOURS_PER_DAY, 2); }   // early leave: the annual balance expressed in hours
    return ['type' => $t, 'entitled' => $ent, 'carry' => $carry, 'opening' => $open, 'taken' => $taken, 'pending' => $pend, 'counts' => $counts, 'total' => $total, 'available' => $avail, 'unit' => $t['unit'] ?? 'day', 'via_annual' => $via, 'mode' => $mode, 'statutory' => $st, 'acc_hours' => $accH];
}
function hr_balances(array $p, int $year): array { $o = []; foreach (hr_types() as $tid => $t) { if (function_exists('hr_type_enabled') && !hr_type_enabled($p, $t)) continue; $b = hr_balance($p, $tid, $year); if ($b['counts'] || $b['taken'] > 0 || $b['pending'] > 0) $o[$tid] = $b; } return $o; }

/* ------------------------------------------------------------ requests */
function hr_request_create_from_post(?array $forPerson = null): int {
    $u = current_user(); if (!$u || !is_staff() || !hr2_has()) throw new RuntimeException(t('err.noaccess'));
    $p = $forPerson ?: hr_person_of_user((int)$u['id']); if (!$p) throw new RuntimeException(t('hr.no_person'));
    if ($forPerson && !hr_can()) throw new RuntimeException(t('err.noaccess'));
    $t = hr_type((int)($_POST['type_id'] ?? 0)); if (!$t || !$t['is_active']) throw new RuntimeException(t('err.notfound')); if (function_exists('hr_type_enabled') && !hr_type_enabled($p, $t)) throw new RuntimeException(t('hr.type_off', ['t' => $t['name']]));
    $from = (string)($_POST['date_from'] ?? ''); $to = (string)($_POST['date_to'] ?? '') ?: $from;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) || $to < $from || (strtotime($to) - strtotime($from)) > 370 * 86400) throw new RuntimeException(t('pl.bad_dates'));
    $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300) ?: null; if ($t['needs_reason'] && $reason === null) throw new RuntimeException(t('hr.need_reason', ['t' => $t['name']]));
    $hours = null; $tf = null; $tt = null; $days = 0.0;
    if ($t['unit'] === 'hour') {
        $hours = round((float) str_replace(',', '.', (string)($_POST['hours'] ?? '0')), 2); if ($hours <= 0 || $hours > 12) throw new RuntimeException(t('hr.need_hours'));
        $tf = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['time_from'] ?? '')) ? $_POST['time_from'] . ':00' : null; $tt = preg_match('/^\d{2}:\d{2}$/', (string)($_POST['time_to'] ?? '')) ? $_POST['time_to'] . ':00' : null;
        $to = $from; $days = $t['consumes_annual'] ? round($hours / HR_HOURS_PER_DAY, 2) : 0.0;
    } else { $days = (float) hr_working_days($p, $from, $to); if ($days <= 0) throw new RuntimeException(t('hr.no_workdays')); }
    // availability: counting types (and annual leave for annual-consuming hour types)
    $year = (int) substr($from, 0, 4); $chk = $t['unit'] === 'hour' && $t['consumes_annual'] ? hr_annual_type_id() : (int)$t['id'];
    if ($chk) { $b = hr_balance($p, $chk, $year); $need = $t['unit'] === 'hour' && $t['consumes_annual'] ? $days : ($t['unit'] === 'hour' ? $hours : $days); if ($b['available'] !== null && $need > $b['available'] + 0.001) throw new RuntimeException(t('hr.not_enough', ['t' => hr_type($chk)['name'], 'a' => rtrim(rtrim(number_format($b['available'], 2, '.', ''), '0'), '.'), 'u' => $b['unit'] === 'hour' ? 'h' : t('hr.days')])); }
    // overlap with existing requests of the person
    if (scalar("SELECT COUNT(*) FROM hr_requests WHERE person_id = ? AND status IN ('pending_mgr','pending_hr','approved') AND date_from <= ? AND date_to >= ? AND type_id = ?", [(int)$p['id'], $to, $from, (int)$t['id']])) throw new RuntimeException(t('hr.overlap'));
    // document
    $doc = hr_store_doc($_FILES['doc'] ?? null); if ($t['needs_doc'] && !$doc && !hr_can()) throw new RuntimeException(t('hr.need_doc', ['t' => $t['name']]));
    $mgr = $t['hr_only'] && !(function_exists('hr_is_contractor') && hr_is_contractor($p)) ? null : hr_manager_of($p); $status = $mgr ? 'pending_mgr' : 'pending_hr';
    q('INSERT INTO hr_requests (person_id, user_id, type_id, date_from, date_to, time_from, time_to, hours, days, reason, doc_name, doc_path, doc_sha, status, manager_user_id, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [(int)$p['id'], (int)($p['user_id'] ?? 0) ?: null, (int)$t['id'], $from, $to, $tf, $tt, $hours, $days, $reason, $doc['name'] ?? null, $doc['path'] ?? null, $doc['sha'] ?? null, $status, $mgr, (int)$u['id']]);
    $id = (int) db()->lastInsertId();
    hr_notify($id, 'new'); return $id;
}
function hr_annual_type_id(): ?int { foreach (hr_types() as $id => $t) if ($t['rule_key'] === 'annual') return $id; return null; }
function hr_store_doc(?array $f): ?array {
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return null;
    if ((int)$f['size'] > 10 * 1024 * 1024) throw new RuntimeException(t('hr.doc_big'));
    $mime = (string)(mime_content_type($f['tmp_name']) ?: ''); $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null; if (!$ext) throw new RuntimeException(t('hr.doc_type'));
    $dir = dirname(__DIR__) . '/storage/hr/' . date('Y'); if (!is_dir($dir)) @mkdir($dir, 0700, true);
    $name = bin2hex(random_bytes(12)) . '.' . $ext; if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) throw new RuntimeException(t('hr.doc_fail'));
    return ['name' => mb_substr((string)$f['name'], 0, 160), 'path' => date('Y') . '/' . $name, 'sha' => hash_file('sha256', $dir . '/' . $name)];
}
/** ?p=hr_doc&id= — stream a request's document to the requester, the manager, HR. */
function hr_doc_stream(int $reqId): void {
    $r = one('SELECT * FROM hr_requests WHERE id = ?', [$reqId]); $u = current_user(); if (!$r || !$u || !$r['doc_path']) { http_response_code(404); exit; }
    if (!(hr_can() || (int)$r['user_id'] === (int)$u['id'] || (int)$r['manager_user_id'] === (int)$u['id'])) { http_response_code(403); exit; }
    $f = dirname(__DIR__) . '/storage/hr/' . $r['doc_path']; if (!is_file($f)) { http_response_code(404); exit; }
    $ct = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($f, PATHINFO_EXTENSION))] ?? 'application/octet-stream';   // by the extension we assigned at upload, never by sniffing the content
    header('Content-Type: ' . $ct); header('X-Content-Type-Options: nosniff'); header("Content-Security-Policy: default-src 'none'; sandbox"); header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', (string)$r['doc_name']) . '"'); header('Cache-Control: private, max-age=0'); header('Content-Length: ' . filesize($f)); readfile($f); exit;
}
/** Decide a request at its current stage; writes the board entries on final approval. */
function hr_request_decide(int $id, bool $approve, string $note): void {
    $r = one('SELECT * FROM hr_requests WHERE id = ?', [$id]); $u = current_user(); if (!$r || !$u) throw new RuntimeException(t('err.notfound'));
    $me = (int)$u['id']; $note = mb_substr(trim($note), 0, 300) ?: null;
    if ($r['status'] === 'pending_mgr') {
        if (!((int)$r['manager_user_id'] === $me || hr_can())) throw new RuntimeException(t('err.noaccess'));
        $contractor = (function_exists('hr_is_contractor') && hr_is_contractor(hr_person((int)$r['person_id']) ?? [])) || !empty($r['period_id']);   // R55-002: the manager's word is final for external contractors; R61-001: and for joining a leave period
        q('UPDATE hr_requests SET status = ?, manager_user_id = COALESCE(manager_user_id, ?), manager_at = NOW(), manager_note = ? WHERE id = ?', [$approve ? ($contractor ? 'approved' : 'pending_hr') : 'rejected', $me, $note, $id]);
        if ($approve && $contractor) hr_request_to_board($id);
        hr_notify($id, $approve ? ($contractor ? 'approved' : 'mgr_ok') : 'rejected'); return;
    }
    if ($r['status'] === 'pending_hr') {
        if (!hr_can()) throw new RuntimeException(t('err.noaccess'));
        q('UPDATE hr_requests SET status = ?, hr_user_id = ?, hr_at = NOW(), hr_note = ?, efka_note = ? WHERE id = ?', [$approve ? 'approved' : 'rejected', $me, $note, mb_substr(trim((string)($_POST['efka'] ?? '')), 0, 120) ?: null, $id]);
        if ($approve) hr_request_to_board($id); hr_notify($id, $approve ? 'approved' : 'rejected'); return;
    }
    throw new RuntimeException(t('hr.req_closed'));
}
function hr_request_cancel(int $id): void {
    $r = one('SELECT * FROM hr_requests WHERE id = ?', [$id]); $u = current_user(); if (!$r || !$u) throw new RuntimeException(t('err.notfound'));
    if (!((int)$r['user_id'] === (int)$u['id'] || hr_can())) throw new RuntimeException(t('err.noaccess'));
    if ($r['status'] === 'approved' && !hr_can()) throw new RuntimeException(t('hr.req_closed'));
    q("UPDATE hr_requests SET status = 'cancelled' WHERE id = ?", [$id]); q('DELETE FROM plan_entries WHERE request_id = ?', [$id]);
}
/** Approved request → board entries (absence kind of the type, one per working day; hour types one entry with hours). */
function hr_request_to_board(int $id): void {
    $r = one('SELECT r.*, p.user_id AS puid FROM hr_requests r JOIN hr_people p ON p.id = r.person_id WHERE r.id = ?', [$id]); $t = $r ? hr_type((int)$r['type_id']) : null; if (!$r || !$t || !$r['puid']) return;
    $uid = (int)$r['puid']; $p = hr_person((int)$r['person_id']); $cal = function_exists('wc_user_calendar') ? wc_user_calendar($uid) : 'GR'; $me = (int)(current_user()['id'] ?? 0);
    q('DELETE FROM plan_entries WHERE request_id = ?', [$id]);
    for ($d = strtotime($r['date_from']); $d <= strtotime($r['date_to']); $d += 86400) {
        $date = date('Y-m-d', $d); if ($t['unit'] === 'day' && function_exists('wc_day') && !wc_day($cal, $date)['workday']) continue;
        q('INSERT INTO plan_entries (user_id, plan_date, kind, note, created_by, leave_type_id, reason, hours, request_id) VALUES (?,?,?,?,?,?,?,?,?)', [$uid, $date, $t['kind'], $t['unit'] === 'hour' ? $t['code'] . ' ' . rtrim(rtrim(number_format((float)$r['hours'], 2, '.', ''), '0'), '.') . ' h' : null, $me ?: null, (int)$t['id'], $r['reason'], $t['unit'] === 'hour' ? (float)$r['hours'] : null, $id]);
    }
}
function hr_notify(int $id, string $ev): void {
    if (!function_exists('nf_push')) return; $r = one('SELECT r.*, p.name_en, t.name AS type_name, t.code FROM hr_requests r JOIN hr_people p ON p.id = r.person_id JOIN hr_leave_types t ON t.id = r.type_id WHERE r.id = ?', [$id]); if (!$r) return;
    $me = (int)(current_user()['id'] ?? 0); $title = $r['name_en'] . ' · ' . $r['code'] . ' ' . fmt_date($r['date_from']) . ($r['date_to'] !== $r['date_from'] ? ' – ' . fmt_date($r['date_to']) : '') . ($r['hours'] ? ' · ' . $r['hours'] . ' h' : '');
    $to = []; $url = url('hr_requests');
    if ($ev === 'new') { $to = $r['status'] === 'pending_mgr' ? [(int)$r['manager_user_id']] : hr_hr_user_ids(); }
    elseif ($ev === 'mgr_ok') { $to = hr_hr_user_ids(); }
    else { $to = [(int)$r['user_id']]; $url = url('my_leave'); }
    $to = array_values(array_filter(array_unique(array_map('intval', $to)), fn($i) => $i && $i !== $me)); if (!$to) return;
    nf_push($to, 'hrq:' . $ev, 'hrq:' . $id, t('hr.nf_' . $ev) . ' — ' . $title, $r['reason'] ?: null, $url, $me ?: null, current_user()['full_name'] ?? null);
}
/** Requests a user may see: own, as manager (pending_mgr for them), as HR (all). */
function hr_requests_for(string $scope, int $year = 0): array {
    $u = current_user(); $me = (int)($u['id'] ?? 0); $w = []; $a = [];
    if ($scope === 'mine') { $w[] = 'r.user_id = ?'; $a[] = $me; }
    elseif ($scope === 'manager') { $w[] = "(r.manager_user_id = ? OR (r.status = 'pending_mgr' AND p.department_id IN (SELECT id FROM disciplines WHERE manager_user_id = ?)))"; $a[] = $me; $a[] = $me; }
    elseif ($scope !== 'hr' || !hr_can()) return [];
    if ($year) { $w[] = 'YEAR(r.date_from) = ?'; $a[] = $year; }
    return all('SELECT r.*, p.name_en, p.name_el, t.code, t.name AS type_name, t.unit, m.full_name AS manager_name, h.full_name AS hr_name FROM hr_requests r JOIN hr_people p ON p.id = r.person_id JOIN hr_leave_types t ON t.id = r.type_id LEFT JOIN customer_users m ON m.id = r.manager_user_id LEFT JOIN customer_users h ON h.id = r.hr_user_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY FIELD(r.status, 'pending_mgr', 'pending_hr') DESC, r.date_from DESC, r.id DESC", $a);
}
function hr_status_pill(string $s): string { $m = ['pending_mgr' => 'gold', 'pending_hr' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'cancelled' => 'mute']; return pill($m[$s] ?? 'mute', t('hr.s_' . $s)); }

/* ------------------------------------------------------------ Excel import of opening balances + name matching */
function hr_translit(string $s): string {
    $map = ['ΑΙ' => 'E', 'ΕΙ' => 'I', 'ΟΙ' => 'I', 'ΟΥ' => 'OU', 'ΑΥ' => 'AV', 'ΕΥ' => 'EV', 'ΜΠ' => 'B', 'ΝΤ' => 'D', 'ΓΚ' => 'G', 'ΓΓ' => 'G', 'ΤΣ' => 'TS', 'ΤΖ' => 'TZ', 'Θ' => 'TH', 'Χ' => 'CH', 'Ψ' => 'PS', 'Ξ' => 'X', 'Φ' => 'F', 'Ω' => 'O', 'Η' => 'I', 'Υ' => 'Y', 'Α' => 'A', 'Β' => 'V', 'Γ' => 'G', 'Δ' => 'D', 'Ε' => 'E', 'Ζ' => 'Z', 'Ι' => 'I', 'Κ' => 'K', 'Λ' => 'L', 'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Π' => 'P', 'Ρ' => 'R', 'Σ' => 'S', 'Τ' => 'T'];
    $s = mb_strtoupper($s); $s = strtr($s, ['Ά' => 'Α', 'Έ' => 'Ε', 'Ή' => 'Η', 'Ί' => 'Ι', 'Ό' => 'Ο', 'Ύ' => 'Υ', 'Ώ' => 'Ω', 'Ϊ' => 'Ι', 'Ϋ' => 'Υ', 'ΐ' => 'Ι', 'ΰ' => 'Υ']);
    $out = strtr($s, $map); return preg_replace('/[^A-Z ]+/', ' ', trim($out));
}
/** Proposed matches for a Greek "SURNAME FIRSTNAME" row: [person_id => score 0..1]. Surname weighs 0.7, first name 0.3 (by transliteration + similar_text);
 *  ≥ 0.8 is pre-selected in the matching table, 0.6 – 0.8 is only suggested. */
function hr_match_name(string $el, array $people): array {
    $tr = hr_translit($el); $toks = array_values(array_filter(explode(' ', $tr))); if (!$toks) return [];
    $sim = function (string $a, string $b): float { similar_text($a, $b, $pc); $pc /= 100; if (strlen($a) >= 5 && strlen($b) >= 5 && substr($a, 0, 5) === substr($b, 0, 5)) $pc = max($pc, 0.8); return $pc; };
    $out = [];
    foreach ($people as $p) {
        $en = strtoupper((string)$p['name_en']); $en = preg_replace('/[^A-Z ]+/', ' ', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $en) ?: $en); $etoks = array_values(array_filter(explode(' ', $en))); if (!$etoks) continue;
        $best = function (string $a) use ($etoks, $sim): float { $m = 0.0; foreach ($etoks as $b) $m = max($m, $sim($a, $b)); return $m; };
        $score = count($toks) === 1 ? $best($toks[0]) : 0.7 * $best($toks[0]) + 0.3 * max(array_map($best, array_slice($toks, 1)));
        if (!empty($p['name_el']) && mb_strtoupper(trim((string)$p['name_el'])) === mb_strtoupper(trim($el))) $score = 1.0;
        if ($score >= 0.6) $out[(int)$p['id']] = round($score, 2);
    }
    arsort($out); return array_slice($out, 0, 3, true);
}
const HR_MATCH_AUTO = 0.8;
/** Parse the accountant's balance sheet: rows [name_el, carry, entitled, used, remaining]. */
function hr_import_parse(string $path): array {
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows($path); $out = [];
    foreach ($rows as $r) { $r = array_values($r); $name = trim((string)($r[0] ?? '')); if ($name === '' || !preg_match('/\p{L}{3,}/u', $name) || preg_match('/ΟΝΟΜΑ|ΥΠΟΛΟΙΠΟ|NAME/iu', $name)) continue;
        $num = fn($v) => $v === null || !is_numeric(str_replace(',', '.', trim((string)$v))) ? null : (float) str_replace(',', '.', trim((string)$v));
        $row = ['name_el' => preg_replace('/\s+/u', ' ', $name), 'carry' => $num($r[1] ?? null), 'entitled' => $num($r[2] ?? null), 'used' => $num($r[3] ?? null), 'remaining' => $num($r[4] ?? null)];
        if (($row['entitled'] === null && $row['remaining'] === null) || $row['entitled'] > 60 || $row['carry'] > 60) continue;   // header rows, notes, the wrong workbook (the weekly leave log holds Excel dates)
        $out[] = $row; }
    return $out;
}
/** Apply confirmed matches: map[name_el] = person_id | 'new' | '' (skip); writes the annual-leave entitlement of the year. */
function hr_import_apply(array $rows, array $map, int $year, string $asof = ''): array {
    $cut = function_exists('hr_cut_has') && hr_cut_has() && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asof) ? $asof : null;   // R60-004: cut-over date
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $ann = hr_annual_type_id(); if (!$ann) throw new RuntimeException(t('err.notfound'));
    $n = 0; $created = 0; $me = (int)(current_user()['id'] ?? 0); db()->beginTransaction();
    try {
    $seen = array_count_values(array_filter(array_map('strval', $map), fn($v) => $v !== '' && $v !== 'new')); foreach ($seen as $pid => $c) if ($c > 1) throw new RuntimeException(t('hr.dup_match', ['p' => hr_person((int)$pid)['name_en'] ?? $pid]));
    foreach ($rows as $r) {
        $sel = (string)($map[$r['name_el']] ?? ''); if ($sel === '') continue;
        if ($sel === 'new') { q('INSERT INTO hr_people (name_el, name_en, is_technical) VALUES (?,?,0)', [$r['name_el'], ucwords(strtolower(hr_translit($r['name_el'])))]); $pid = (int) db()->lastInsertId(); $created++; }
        else { $pid = (int)$sel; if (!hr_person($pid)) continue; q('UPDATE hr_people SET name_el = COALESCE(name_el, ?) WHERE id = ?', [$r['name_el'], $pid]); }
        q('INSERT INTO hr_name_map (name_el, person_id) VALUES (?,?) ON DUPLICATE KEY UPDATE person_id = VALUES(person_id)', [$r['name_el'], $pid]);
        $p = hr_person($pid); $uid = (int)($p['user_id'] ?? 0) ?: null;
        q('DELETE FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0))', [$year, $ann, $pid, $uid, $uid ?? 0]);
        if ($cut) q('INSERT INTO hr_entitlements (user_id, person_id, year, type_id, days, carry, opening_taken, counted_from, auto, override_note, updated_by) VALUES (?,?,?,?,?,?,?,?,0,?,?)', [$uid, $pid, $year, $ann, (float)($r['entitled'] ?? 0), (float)($r['carry'] ?? 0), (float)($r['used'] ?? 0), $cut, t('hr.import_asof_note', ['d' => fmt_date($cut)]), $me]);
        else q('INSERT INTO hr_entitlements (user_id, person_id, year, type_id, days, carry, opening_taken, override_note, updated_by) VALUES (?,?,?,?,?,?,?,?,?)', [$uid, $pid, $year, $ann, (float)($r['entitled'] ?? 0), (float)($r['carry'] ?? 0), (float)($r['used'] ?? 0), 'Excel ' . date('Y-m-d'), $me]);
        if (function_exists('hr_audit')) hr_audit($pid, 'entitlement', 'ANN ' . $year, null, ($r['entitled'] ?? 0) . ' · carry ' . ($r['carry'] ?? 0) . ' · taken ' . ($r['used'] ?? 0) . ($cut ? ' · ' . t('hr.counted_from') . ' ' . fmt_date($cut) : ''), t('hr.import_balances')); $n++;
    }
    db()->commit(); } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['written' => $n, 'created' => $created];
}

/* ------------------------------------------------------------ staff import from Excel (GAS-CP-R54-002) */
/** Template columns: header key => [aliases accepted in the header row]. Names in EN/EL headers both work. */
const HR_PEOPLE_COLS = ['name_en' => ['Name (Latin)', 'Name EN', 'Name', 'Ονοματεπώνυμο (Λατινικά)'], 'name_el' => ['Name (Greek)', 'Name EL', 'Ονοματεπώνυμο', 'Ονοματεπώνυμο (Ελληνικά)'], 'email' => ['Email', 'E-mail'],
    'department' => ['Department', 'Τμήμα'], 'technical' => ['Technical', 'Technical staff', 'Τεχνικός'], 'hire_date' => ['Hiring date', 'Hire date', 'Ημ. πρόσληψης', 'Ημερομηνία πρόσληψης'], 'prior_months' => ['Prior service (months)', 'Prior months', 'Προϋπηρεσία (μήνες)'],
    'week_days' => ['Working days / week', 'Week days', 'Ημέρες/εβδ.'], 'children' => ['Children', 'Παιδιά'], 'children_years' => ['Children birth years', 'Birth years', 'Έτη γέννησης παιδιών'], 'notes' => ['Notes', 'Σημειώσεις'],
    'emp_code' => ['Employee code', 'Code', 'Κωδικός εργαζομένου'], 'job_title' => ['Job title', 'Specialty', 'Ειδικότητα'], 'contract_type' => ['Contract type', 'Contract', 'Τύπος σύμβασης'], 'contract_end' => ['Contract end', 'Contract end date', 'Λήξη σύμβασης'], 'marital' => ['Marital status', 'Οικογενειακή κατάσταση'], 'prior_years' => ['Prior service (years)', 'Prior years', 'Προϋπηρεσία (έτη)'], 'left_date' => ['Left on', 'Termination date', 'Ημ. αποχώρησης'], 'jurisdiction' => ['Country', 'Jurisdiction', 'Χώρα'], 'employment_kind' => ['Kind', 'Employment kind', 'Σχέση'], 'single_parent' => ['Single parent', 'Μονογονεϊκή'], 'gender' => ['Gender', 'Sex', 'Φύλο'],
    'personal_email' => ['Personal email', 'Private email', 'Προσωπικό email'], 'phone_mobile' => ['Mobile', 'Mobile phone', 'Κινητό'], 'phone_home' => ['Home phone', 'Telephone', 'Σταθερό'], 'address' => ['Address', 'Διεύθυνση'], 'birth_date' => ['Birth date', 'Date of birth', 'Ημ. γέννησης'],
    'amka' => ['AMKA', 'ΑΜΚΑ'], 'afm' => ['VAT (AFM)', 'VAT', 'AFM', 'ΑΦΜ'], 'doy' => ['Tax office (DOY)', 'DOY', 'ΔΟΥ'], 'id_number' => ['ID number', 'ID', 'ΑΔΤ'], 'iban' => ['IBAN'], 'bank' => ['Bank', 'Τράπεζα'], 'emergency_name' => ['Emergency contact', 'Επείγουσα επαφή'], 'emergency_phone' => ['Emergency phone', 'Τηλ. επείγουσας επαφής'],
    'father_name' => ['Father\'s name', 'Όνομα πατρός'], 'mother_name' => ['Mother\'s name', 'Όνομα μητρός'], 'spouse_name' => ['Spouse', 'Όνομα συζύγου'], 'nationality' => ['Nationality', 'Υπηκοότητα', 'Εθνικότητα'], 'birth_place' => ['Birth place', 'Τόπος γέννησης'], 'passport_no' => ['Passport No', 'Αρ. διαβατηρίου'], 'blood_group' => ['Blood group', 'Ομάδα αίματος'], 'education' => ['Education', 'Μόρφωση'], 'experience_level' => ['Experience level', 'Βαθμός εμπειρίας'], 'transport' => ['Transport', 'Μεταφ. μέσο'], 'phone_work' => ['Work phone', 'Τηλ. εργασίας', 'Τηλ.1'], 'extension' => ['Extension', 'Εσωτερικό'], 'postcode' => ['Postcode', 'Τ.Κ.'], 'city' => ['City', 'Πόλη', 'Περιοχή'], 'country' => ['Country of residence', 'Χώρα κατοικίας'], 'gross_salary' => ['Gross salary', 'Μικτές αποδοχές'], 'oaed_benefit' => ['OAED benefit', 'Επίδομα ΟΑΕΔ'], 'payroll_code' => ['Payroll code', 'Κωδικός μισθοδοσίας']];
/** ?p=hr_people_template — the Excel template with the departments as a second sheet and the existing people pre-filled. */
function hr_people_template(): void {
    if (!hr_can()) { http_response_code(403); exit; } require_once __DIR__ . '/xlsxw.php';
    $hdr = ['Employee code', 'Name (Latin)', 'Name (Greek)', 'Email', 'Department', 'Job title', 'Technical', 'Contract type', 'Contract end', 'Hiring date', 'Prior service (years)', 'Prior service (months)', 'Working days / week', 'Marital status', 'Children', 'Children birth years', 'Left on', 'Notes', 'Country', 'Kind', 'Single parent', 'Gender', 'Personal email', 'Mobile', 'Home phone', 'Address', 'Birth date', 'AMKA', 'VAT (AFM)', 'Tax office (DOY)', 'ID number', 'IBAN', 'Bank', 'Emergency contact', 'Emergency phone', 'Father\'s name', 'Mother\'s name', 'Spouse', 'Nationality', 'Birth place', 'Passport No', 'Blood group', 'Education', 'Experience level', 'Transport', 'Work phone', 'Extension', 'Postcode', 'City', 'Country of residence', 'Gross salary', 'OAED benefit', 'Payroll code'];
    $rows = []; foreach (hr_people(false) as $p) $rows[] = [$p['emp_code'] ?? '', $p['name_en'], $p['name_el'] ?? '', $p['email'] ?? '', $p['department'] ?? '', $p['job_title'] ?? '', $p['is_technical'] ? 'yes' : 'no', ($p['contract_type'] ?? 'indefinite') . (!empty($p['part_time']) ? ' part-time' : ''), $p['contract_end'] ?? '', $p['hire_date'] ?? '', intdiv((int)$p['prior_months'], 12), (int)$p['prior_months'] % 12, (int)$p['week_days'], $p['marital'] ?? '', (int)$p['children'], $p['children_years'] ?? '', $p['left_date'] ?? '', $p['notes'] ?? '', $p['jurisdiction'] ?? 'GR', $p['employment_kind'] ?? 'employee', !empty($p['single_parent']) ? 'yes' : 'no', ($p['gender'] ?? '') === 'm' ? 'M' : (($p['gender'] ?? '') === 'f' ? 'F' : ''), $p['personal_email'] ?? '', $p['phone_mobile'] ?? '', $p['phone_home'] ?? '', $p['address'] ?? '', $p['birth_date'] ?? '', $p['amka'] ?? '', $p['afm'] ?? '', $p['doy'] ?? '', $p['id_number'] ?? '', $p['iban'] ?? '', $p['bank'] ?? '', $p['emergency_name'] ?? '', $p['emergency_phone'] ?? '', $p['father_name'] ?? '', $p['mother_name'] ?? '', $p['spouse_name'] ?? '', $p['nationality'] ?? '', $p['birth_place'] ?? '', $p['passport_no'] ?? '', $p['blood_group'] ?? '', $p['education'] ?? '', $p['experience_level'] ?? '', $p['transport'] ?? '', $p['phone_work'] ?? '', $p['extension'] ?? '', $p['postcode'] ?? '', $p['city'] ?? '', $p['country'] ?? '', $p['gross_salary'] ?? '', $p['oaed_benefit'] ?? '', $p['payroll_code'] ?? ''];
    if (function_exists('hr_personal_can') && !hr_personal_can()) { $keep = count($hdr) - count(HR_PERSONAL); $hdr = array_slice($hdr, 0, $keep); foreach ($rows as &$r) $r = array_slice($r, 0, $keep); unset($r); }   // R64-003: personal / payroll columns leave the portal only for HR and Owner
    if (!$rows) $rows[] = ['E001', 'Example Person', 'ΠΑΡΑΔΕΙΓΜΑ ΟΝΟΜΑ', 'person@goldenas.com.gr', 'Technical', 'Service engineer', 'yes', 'indefinite', '', '2024-03-01', 2, 0, 5, 'married', 2, '2015, 2019', '', ''];
    xlsx_download('GAS-HR-people-template.xlsx', [['name' => 'People', 'first' => true, 'header' => $hdr, 'rows' => $rows], ['name' => 'Departments', 'header' => ['Department', 'Head'], 'rows' => array_map(fn($d) => [$d['name'], $d['head_name'] ?? ''], hr_departments())]]);
}
/** Parse the staff workbook → rows ready for preview: each with the parsed fields, the matched existing person (by name_el, name_en or email) and warnings. */
function hr_people_import_parse(string $path): array {
    require_once __DIR__ . '/xlsx.php'; $raw = xlsx_rows($path); if (!$raw) return [];
    $hdrIdx = null; $map = [];
    foreach ($raw as $i => $r) { $m = xlsx_header_map($r, HR_PEOPLE_COLS); if ($m['name_en'] !== null || $m['name_el'] !== null) { $hdrIdx = $i; $map = $m; break; } if ($i > 10) break; }
    if ($hdrIdx === null) return [];
    $people = hr_people(false); $depts = []; foreach (hr_departments(false) as $d) { $depts[mb_strtolower($d['name'])] = (int)$d['id']; if (function_exists('disc_norm')) $depts['~' . disc_norm($d['name'])] = (int)$d['id']; }   // R64-007: loose match too
    $accounts = []; foreach (all("SELECT id, email, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1") as $a) $accounts[strtolower($a['email'])] = $a;
    $get = fn(array $r, string $k) => $map[$k] === null ? null : trim((string)($r[$map[$k]] ?? ''));   // rows are sparse, keyed by column index
    $yes = fn(?string $v) => in_array(mb_strtolower((string)$v), ['1', 'yes', 'y', 'true', 'x', 'ναι', 'ν', 'tech', 'technical', 'τεχνικός'], true);
    $date = function (?string $v): ?string { if ($v === null || trim($v) === '') return null; $d = xlsx_date($v); if (!$d && preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})~', $v, $m)) $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); return $d; };
    $out = [];
    foreach (array_slice($raw, $hdrIdx + 1, null, true) as $r) {
        $en = $get($r, 'name_en') ?: ''; $el = $get($r, 'name_el') ?: ''; if ($en === '' && $el === '') continue;
        if ($en === '') $en = ucwords(strtolower(hr_translit($el)));
        $email = strtolower((string)$get($r, 'email')); $hire = $get($r, 'hire_date'); if ($hire !== null && $hire !== '') { $hd = preg_match('/^\d{4}-\d{2}-\d{2}/', $hire) ? substr($hire, 0, 10) : (function_exists('xlsx_date') ? xlsx_date($hire) : null); if (!$hd && preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$~', $hire, $mm)) $hd = sprintf('%04d-%02d-%02d', $mm[3], $mm[2], $mm[1]); $hire = $hd ?: null; } else $hire = null;
        $dname = (string)$get($r, 'department'); $dept = $dname !== '' ? ($depts[mb_strtolower($dname)] ?? (function_exists('disc_norm') ? ($depts['~' . disc_norm($dname)] ?? null) : null)) : null;
        $warn = []; if ($dname !== '' && !$dept) $warn[] = t('hr.imp_w_newdept', ['d' => $dname]); if ($email !== '' && !isset($accounts[$email])) $warn[] = t('hr.imp_w_email', ['e' => $email]);
        $match = null; foreach ($people as $p) { if (($el !== '' && mb_strtoupper(trim((string)$p['name_el'])) === mb_strtoupper($el)) || strcasecmp(trim((string)$p['name_en']), $en) === 0 || ($email !== '' && strtolower((string)$p['email']) === $email)) { $match = (int)$p['id']; break; } }
        $ctRaw = $get($r, 'contract_type'); [$ct, $pt] = $ctRaw === null || $ctRaw === '' ? [null, null] : (function_exists('hr_contract_from_label') ? hr_contract_from_label((string)$ctRaw) : ['indefinite', 0]);   // R64-004: absent → keep $ce = $date($get($r, 'contract_end')); $left = $date($get($r, 'left_date'));
        $pyRaw = $get($r, 'prior_years'); $pmRaw = $get($r, 'prior_months'); $pm = ($pyRaw === null || $pyRaw === '') && ($pmRaw === null || $pmRaw === '') ? null : max(0, (int)$pyRaw) * 12 + max(0, (int)$pmRaw);   // R64-004: absent / empty → keep what the card has
        $jur = strtoupper(substr((string)$get($r, 'jurisdiction'), 0, 2)); $kindV = mb_strtolower((string)$get($r, 'employment_kind'));
        $gv = mb_strtolower((string)$get($r, 'gender')); $gender = $gv === '' ? null : (in_array($gv[0] ?? '', ['m', 'α', 'a'], true) || str_starts_with($gv, 'άν') || str_starts_with($gv, 'an') ? 'm' : (in_array($gv[0] ?? '', ['f', 'γ', 'θ', 'w'], true) || str_starts_with($gv, 'γυ') ? 'f' : null));
        $personal = []; foreach (hr_personal_fields() as $pk) { $pv = $get($r, $pk); if ($pv === null || $pv === '') continue; if ($pk === 'birth_date') $pv = $date($pv) ?? ''; if ($pv !== '') $personal[$pk] = $pv; }   // R64-004: a column absent from the file never touches the card
        $out[] = ['personal' => $personal, 'gender' => $gender, 'jurisdiction' => in_array($jur, ['GR', 'IT'], true) ? $jur : null, 'employment_kind' => str_contains($kindV, 'contract') || str_contains($kindV, 'invoice') || str_contains($kindV, 'εξωτ') || str_contains($kindV, 'τιμολ') ? 'contractor' : ($kindV !== '' ? 'employee' : null), 'single_parent' => (int)$yes($get($r, 'single_parent')),
                 'emp_code' => mb_substr((string)$get($r, 'emp_code'), 0, 12) ?: null, 'job_title' => mb_substr((string)$get($r, 'job_title'), 0, 120) ?: null, 'contract_type' => $ct, 'part_time' => $pt, 'contract_end' => $ce, 'marital' => function_exists('hr_marital_from_label') ? hr_marital_from_label((string)$get($r, 'marital')) : null, 'left_date' => $left,
                 'name_en' => mb_substr($en, 0, 120), 'name_el' => mb_substr($el, 0, 120) ?: null, 'email' => $email ?: null, 'user_id' => $email !== '' ? (int)($accounts[$email]['id'] ?? 0) ?: null : null, 'department' => $dname, 'department_id' => $dept,
                 'is_technical' => ($tv = $get($r, 'technical')) === null || $tv === '' ? null : (int)$yes($tv), 'hire_date' => $hire, 'prior_months' => $pm === null ? null : min(600, $pm), 'week_days' => ($wv = $get($r, 'week_days')) === null || $wv === '' ? null : ((int)$wv === 6 ? 6 : 5), 'children' => ($cv = $get($r, 'children')) === null || $cv === '' ? null : max(0, min(12, (int)$cv)),
                 'children_years' => mb_substr((string)$get($r, 'children_years'), 0, 60) ?: null, 'notes' => mb_substr((string)$get($r, 'notes'), 0, 500) ?: null, 'match' => $match, 'warn' => $warn];
    }
    return $out;
}
/** Apply the previewed rows: $sel[i] = 'new' | '<person id>' (update) | '' (skip). Accounts are linked only when the email matched and the account is free. */
function hr_people_import_apply(array $rows, array $sel): array {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $n = 0; $c = 0; $res = ['warn' => []]; db()->beginTransaction();
    try {
        $used = []; foreach ($sel as $i => $v) if ($v !== '' && $v !== 'new') { if (isset($used[$v])) throw new RuntimeException(t('hr.dup_match', ['p' => hr_person((int)$v)['name_en'] ?? $v])); $used[$v] = 1; }
        foreach ($rows as $i => $r) {
            $v = (string)($sel[$i] ?? ''); if ($v === '') continue;
            $uid = $r['user_id'] ?? null; if ($uid && scalar('SELECT COUNT(*) FROM hr_people WHERE user_id = ? AND id <> ?', [$uid, $v === 'new' ? 0 : (int)$v])) $uid = null;   // account already linked elsewhere → leave unlinked
            if (!$r['department_id'] && $r['department'] !== '' && !empty($_POST['create_depts'])) { $r['department_id'] = (int) (scalar('SELECT id FROM disciplines WHERE LOWER(name) = LOWER(?)', [$r['department']]) ?: 0); if (!$r['department_id']) { q('INSERT INTO disciplines (name, sort_order) VALUES (?, ?)', [mb_substr($r['department'], 0, 80), 900]);$r['department_id'] = (int) db()->lastInsertId(); } }   // R56-002 / R64-007: a new discipline is created on import only when HR ticked "create unknown departments"
            if ($v === 'new') { q('INSERT INTO hr_people (user_id, name_el, name_en, department_id, is_technical, hire_date, prior_months, week_days, children, children_years, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)', [$uid, $r['name_el'], $r['name_en'], $r['department_id'], $r['is_technical'] ?? 0, $r['hire_date'], $r['prior_months'] ?? 0, $r['week_days'] ?? 5, $r['children'] ?? 0, $r['children_years'], $r['notes']]); $pidNew = (int) db()->lastInsertId(); $c++; }
            else { $pid = (int)$v; if (!hr_person($pid)) continue;
                q('UPDATE hr_people SET name_el = COALESCE(?, name_el), name_en = ?, department_id = COALESCE(?, department_id), is_technical = COALESCE(?, is_technical), hire_date = COALESCE(?, hire_date), prior_months = COALESCE(?, prior_months), week_days = COALESCE(?, week_days), children = COALESCE(?, children), children_years = COALESCE(?, children_years), notes = COALESCE(?, notes)' . ($uid ? ', user_id = COALESCE(user_id, ?)' : '') . ' WHERE id = ?',
                  array_merge([$r['name_el'], $r['name_en'], $r['department_id'], $r['is_technical'], $r['hire_date'], $r['prior_months'], $r['week_days'], $r['children'], $r['children_years'], $r['notes']], $uid ? [$uid] : [], [$pid])); $n++; }
            if (function_exists('hr3_has') && hr3_has()) { $pid2 = $v === 'new' ? $pidNew : (int)$v; hr_people_import_card($pid2, $r); }
            if (!empty($r['personal']) && hr7_has() && function_exists('hr_personal_can') && hr_personal_can()) { $pid3 = $v === 'new' ? $pidNew : (int)$v; $cur = one('SELECT * FROM hr_people WHERE id = ?', [$pid3]) ?: []; $merged = array_merge(array_intersect_key($cur, array_flip(hr_personal_fields())), $r['personal']); try { hr_personal_save($pid3, $merged); } catch (RuntimeException $e) { $bad = []; foreach ($r['personal'] as $pk => $pv) { try { hr_personal_from([$pk => $pv]); } catch (RuntimeException $e2) { $bad[] = $pk . ' (' . $pv . ')'; unset($merged[$pk]); } } $res['warn'][] = $r['name_en'] . ': ' . $e->getMessage() . ($bad ? ' — ' . t('hr.imp_kept_rest', ['f' => implode(', ', $bad)]) : ''); try { hr_personal_save($pid3, $merged); } catch (RuntimeException $e3) { $res['warn'][] = $r['name_en'] . ': ' . $e3->getMessage(); } } }   // R60-007 / R62-001: an invalid value (e.g. a 10-digit AMKA in the payroll export) is skipped and reported, the rest of the row and the import go on
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['updated' => $n, 'created' => $c, 'warn' => $res['warn'] ?? []];
}

/* ------------------------------------------------------------ leave records import from Excel (GAS-CP-R54-004) */
const HR_LEAVE_COLS = ['email' => ['Email', 'E-mail'], 'name_en' => ['Name (Latin)', 'Name EN', 'Name'], 'name_el' => ['Name (Greek)', 'Name EL', 'Ονοματεπώνυμο'], 'code' => ['Type code', 'Code', 'Type', 'Κωδικός'],
    'from' => ['From', 'Date from', 'Από', 'Ημερομηνία Έναρξης'], 'to' => ['To', 'Date to', 'Έως', 'Ημερομηνία Λήξης'], 'days' => ['Days', 'Ημέρες'], 'hours' => ['Hours', 'Ώρες'], 'status' => ['Status', 'Κατάσταση'], 'reason' => ['Reason / comment', 'Reason', 'Comment', 'Αιτιολογία', 'Σχόλια'], 'action' => ['Action']];
/** Parse a leave-records workbook (sheet "Leaves" or the first sheet) → rows with the matched person, type and status, plus warnings. */
function hr_leave_import_parse(string $path): array {
    require_once __DIR__ . '/xlsx.php'; try { $raw = xlsx_rows($path, 'Leaves'); } catch (Throwable $e) { $raw = []; } if (!$raw) $raw = xlsx_rows($path); if (!$raw) return [];
    $hdrIdx = null; $map = []; foreach ($raw as $i => $r) { $m = xlsx_header_map($r, HR_LEAVE_COLS); if ($m['from'] !== null && $m['code'] !== null) { $hdrIdx = $i; $map = $m; break; } if ($i > 10) break; }
    if ($hdrIdx === null) return [];
    $people = hr_people(false); $byUser = []; $byEl = []; $byEn = []; foreach ($people as $p) { if ($p['user_id']) $byUser[(int)$p['user_id']] = (int)$p['id']; if ($p['name_el']) $byEl[mb_strtoupper(trim($p['name_el']))] = (int)$p['id']; $byEn[strtolower(trim($p['name_en']))] = (int)$p['id']; }
    foreach (all('SELECT name_el, person_id FROM hr_name_map') as $m) $byEl[mb_strtoupper(trim($m['name_el']))] = (int)$m['person_id'];
    $accounts = []; foreach (all("SELECT id, email FROM customer_users WHERE role IN ('staff','admin')") as $a) $accounts[strtolower($a['email'])] = (int)$a['id'];
    $types = []; foreach (hr_types(false) as $t) $types[strtoupper($t['code'])] = $t;
    $get = fn(array $r, string $k) => $map[$k] === null ? null : trim((string)($r[$map[$k]] ?? ''));
    $date = function (?string $v): ?string { if ($v === null || $v === '') return null; $d = xlsx_date($v); if (!$d && preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})~', $v, $m)) $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); return $d; };
    $seenBatch = []; $st = ['approved' => 'approved', 'εγκρίθηκε' => 'approved', 'ok' => 'approved', 'rejected' => 'rejected', 'απορρίφθηκε' => 'rejected', 'pending' => 'pending_hr', 'έχει ληφθεί' => 'pending_hr', 'cancelled' => 'cancelled'];
    $out = [];
    foreach (array_slice($raw, $hdrIdx + 1, null, true) as $r) {
        $from = $date($get($r, 'from')); $code = strtoupper((string)$get($r, 'code')); if ($from === null && $code === '') continue;
        $to = $date($get($r, 'to')) ?: $from; if ($to && $from && $to < $from) [$from, $to] = [$to, $from];
        $email = strtolower((string)$get($r, 'email')); $el = (string)$get($r, 'name_el'); $en = (string)$get($r, 'name_en');
        $pid = null; if ($email !== '' && isset($accounts[$email])) $pid = $byUser[$accounts[$email]] ?? null; if (!$pid && $el !== '') $pid = $byEl[mb_strtoupper($el)] ?? null; if (!$pid && $en !== '') $pid = $byEn[strtolower($en)] ?? null;
        $t = $types[$code] ?? null; $status = $st[mb_strtolower((string)$get($r, 'status'))] ?? 'approved'; $action = mb_strtolower((string)$get($r, 'action'));
        $hours = (float) str_replace(',', '.', (string)$get($r, 'hours')); $days = (float) str_replace(',', '.', (string)$get($r, 'days'));
        $warn = []; if (!$pid) $warn[] = t('hr.limp_w_person'); if (!$t) $warn[] = t('hr.limp_w_type', ['c' => $code]); if (!$from) $warn[] = t('hr.limp_w_date'); if ($t && $t['unit'] === 'hour' && $hours <= 0) $warn[] = t('hr.limp_w_hours');
        if ($action === 'skip' || $status === 'cancelled') $warn[] = t('hr.limp_w_skip');
        $dup = $pid && $t && $from ? (bool) scalar("SELECT COUNT(*) FROM hr_requests WHERE person_id = ? AND type_id = ? AND date_from = ? AND status <> 'cancelled'", [$pid, (int)$t['id'], $from]) : false; if ($dup) $warn[] = t('hr.limp_w_dup');
        $bk = $pid . '|' . ($t['id'] ?? '') . '|' . $from; if (!$dup && $pid && $t && $from) { if (isset($seenBatch[$bk])) { $dup = true; $warn[] = t('hr.limp_w_dup2'); } $seenBatch[$bk] = true; }   // R55-001: the same leave twice in one file
        $ok = $pid && $t && $from && $action !== 'skip' && $status !== 'cancelled' && !$dup && !($t['unit'] === 'hour' && $hours <= 0);
        $former = !$pid && $t && $from && $status !== 'cancelled' && !($t['unit'] === 'hour' && $hours <= 0) && ($en !== '' || $el !== '');
        $out[] = ['person_id' => $pid, 'person' => $pid ? (hr_person($pid)['name_en'] ?? '') : ($en ?: $el ?: $email), 'type_id' => $t ? (int)$t['id'] : null, 'code' => $code, 'from' => $from, 'to' => $to, 'days' => $days, 'hours' => $t && $t['unit'] === 'hour' ? $hours : null, 'status' => $status, 'reason' => mb_substr((string)$get($r, 'reason'), 0, 300) ?: null, 'warn' => $warn, 'ok' => $ok, 'former' => $former];
    }
    return $out;
}
/** Write the selected rows as requests decided by HR (approved rows also land on the board for people with an account). $sel[i] = 1 to import. */
function hr_leave_import_apply(array $rows, array $sel, bool $historyOnly = false, bool $createFormer = false): array {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $me = (int)(current_user()['id'] ?? 0); $n = 0; $b = 0; $c = 0; $hasCounted = hr_counted_sql() !== ''; db()->beginTransaction();
    try {
        $created = [];   // R55-005: former employees (not in the registry) created as left persons when asked
        foreach ($rows as $i => $r) {
            if ($createFormer && !$r['person_id'] && !empty($sel[$i]) && $r['type_id'] && $r['from'] && ($r['status'] ?? '') !== 'cancelled') {
                $key = trim(preg_replace('/\s*\((?:FORMER|ΠΡΩΗΝ)\)\s*$/iu', '', mb_strtoupper(trim((string)($r['person'] ?? ''))))); if ($key === '') continue;
                if (!isset($created[$key])) { $isEl = (bool) preg_match('/\p{Greek}/u', $key); q('INSERT INTO hr_people (name_el, name_en, is_technical, is_active, notes) VALUES (?,?,0,0,?)', [$isEl ? $key : null, $isEl ? ucwords(strtolower(hr_translit($key))) : ucwords(strtolower($key)), 'former employee · created by the leave import ' . date('Y-m-d')]); $created[$key] = (int) db()->lastInsertId(); $c++; if (function_exists('hr_audit')) hr_audit($created[$key], 'card', 'created', null, $key, 'former employee (leave import)'); }
                $r['person_id'] = $created[$key]; $r['ok'] = true;
            }
            if (empty($sel[$i]) || !$r['ok']) continue; $p = hr_person((int)$r['person_id']); $t = hr_type((int)$r['type_id']); if (!$p || !$t) continue;
            $days = $t['unit'] === 'hour' ? ($t['consumes_annual'] ? round((float)$r['hours'] / HR_HOURS_PER_DAY, 2) : 0.0) : (float) ($r['days'] > 0 ? $r['days'] : hr_working_days($p, $r['from'], $r['to']));
            $appr = $r['status'] === 'approved'; $note = 'Import ' . date('Y-m-d');
            q('INSERT INTO hr_requests (person_id, user_id, type_id, date_from, date_to, hours, days, reason, status, manager_user_id, hr_user_id, hr_at, hr_note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [(int)$p['id'], (int)($p['user_id'] ?? 0) ?: null, (int)$t['id'], $r['from'], $r['to'], $r['hours'], $days, $r['reason'], $r['status'], null, $r['status'] === 'pending_hr' ? null : $me, $r['status'] === 'pending_hr' ? null : date('Y-m-d H:i:s'), $r['status'] === 'pending_hr' ? null : $note, $me, $r['from'] . ' 08:00:00']);
            $id = (int) db()->lastInsertId(); $n++; if ($historyOnly && $hasCounted) q('UPDATE hr_requests SET counted = 0, hr_note = ? WHERE id = ?', [$note . ' · history only', $id]);
            if ($appr && !empty($p['user_id'])) { hr_request_to_board($id); $b++; }
        }
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return ['written' => $n, 'board' => $b, 'created' => $c];
}

/** R56: card fields of an imported row (code, title, contract, marital, left date) and the employment period that matches the hiring / leaving dates. */
function hr_people_import_card(int $pid, array $r): void {
    if ($r['emp_code'] && scalar('SELECT COUNT(*) FROM hr_people WHERE emp_code = ? AND id <> ?', [$r['emp_code'], $pid])) $r['emp_code'] = null;
    q('UPDATE hr_people SET emp_code = COALESCE(?, emp_code), job_title = COALESCE(?, job_title), contract_type = COALESCE(?, contract_type), part_time = COALESCE(?, part_time), contract_end = COALESCE(?, contract_end), marital = COALESCE(?, marital), left_date = ?, is_active = ? WHERE id = ?', [$r['emp_code'], $r['job_title'], $r['contract_type'], $r['part_time'], $r['contract_end'], $r['marital'], $r['left_date'], $r['left_date'] ? 0 : 1, $pid]);
    if (function_exists('hr_jurisdiction')) { try { q('UPDATE hr_people SET jurisdiction = COALESCE(?, jurisdiction), employment_kind = COALESCE(?, employment_kind), single_parent = GREATEST(single_parent, ?) WHERE id = ?', [$r['jurisdiction'] ?? null, $r['employment_kind'] ?? null, (int)($r['single_parent'] ?? 0), $pid]); } catch (Throwable $e) {} }
    if (function_exists('hr5_has') && hr5_has() && !empty($r['gender'])) q('UPDATE hr_people SET gender = ? WHERE id = ?', [$r['gender'], $pid]);
    $p = hr_person($pid); $open = hr_employment_open($pid); $me = (int)(current_user()['id'] ?? 0);
    if ($p['hire_date'] && !$open && !$r['left_date'] && !scalar('SELECT COUNT(*) FROM hr_employments WHERE person_id = ? AND date_from = ?', [$pid, $p['hire_date']]))
        q('INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, contract_end, job_title, note, created_by) VALUES (?,?,?,?,?,?,?,?,?)', [$pid, scalar('SELECT COUNT(*) FROM hr_employments WHERE person_id = ?', [$pid]) ? 'rehire' : 'hire', $p['hire_date'], $r['contract_type'] ?? 'indefinite', $r['part_time'] ?? 0, $r['contract_end'], $r['job_title'], 'Excel ' . date('Y-m-d'), $me]);
    elseif ($open) q('UPDATE hr_employments SET date_from = COALESCE(?, date_from), contract_type = COALESCE(?, contract_type), part_time = COALESCE(?, part_time), contract_end = COALESCE(?, contract_end), job_title = COALESCE(?, job_title)' . ($r['left_date'] ? ', date_to = ?, reason = \'other\'' : '') . ' WHERE id = ?', array_merge([$p['hire_date'], $r['contract_type'], $r['part_time'], $r['contract_end'], $r['job_title']], $r['left_date'] ? [$r['left_date']] : [], [(int)$open['id']]));
}

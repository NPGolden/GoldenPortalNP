<?php
/**
 * HR — leave types, yearly entitlements and the leave report (GAS-CP-R51-001).
 *
 *  Who: administrators and accounts whose Golden A/S role has scope "hr" (hr_only() in auth.php — those accounts see the
 *  Planning board and nothing else). Leave types ride on the board's absence kinds (leave / sick / parental / strike / other):
 *  each plan entry of an absence carries a leave_type_id and, when the type asks for it, a reason. Entitlements are days per
 *  person, year and type (+ carry-over); the report shows entitled / carried / taken / remaining per type and the absences.
 *  Taken = number of board days of that type in the year (weekends and holidays are never written to the board unless "all days").
 */
declare(strict_types=1);

const HR_KINDS = ['leave', 'sick', 'parental', 'strike', 'other'];

function hr_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'hr_leave_types'") && (bool) scalar("SHOW COLUMNS FROM plan_entries LIKE 'leave_type_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Administrators and HR-scoped accounts manage leave types, entitlements and see the report. */
function hr_has_names(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM hr_leave_types LIKE 'name_uk'"); } catch (Throwable $e) { $h = false; } } return $h; }
function hr_can(): bool { return hr_has() && is_staff() && (is_cp_admin() || (function_exists('hr_only') && hr_only())); }
function hr_types(bool $activeOnly = true): array { static $c = []; $k = (int)$activeOnly; if (!isset($c[$k])) { $c[$k] = []; if (hr_has()) foreach (all('SELECT * FROM hr_leave_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, sort_order, name') as $t) { $t['name_en'] = $t['name']; $t['name'] = hr_type_name($t); $c[$k][(int)$t['id']] = $t; } } return $c[$k]; }
/** The type's name in the current portal language (R54-002: name_el / name_it / name_bg / name_uk columns), English when not translated. */
function hr_type_name(array $t, ?string $lc = null): string { $lc = $lc ?: (function_exists('locale') ? locale() : 'en'); $v = $lc !== 'en' ? trim((string)($t['name_' . $lc] ?? '')) : ''; return $v !== '' ? $v : (string)($t['name_en'] ?? $t['name']); }
const HR_NAME_LANGS = ['el', 'it', 'bg', 'uk'];
function hr_type(?int $id): ?array { return $id ? (hr_types(false)[$id] ?? null) : null; }
/** Types usable for one board kind (the editor's second select). */
function hr_types_for_kind(string $kind): array { return array_filter(hr_types(), fn($t) => $t['kind'] === $kind); }
/** Default type of a kind: the built-in one, else the first active. */
function hr_default_type(string $kind): ?int { $b = null; foreach (hr_types() as $id => $t) if ($t['kind'] === $kind) { if ($t['builtin']) return $id; $b ??= $id; } return $b; }
function hr_staff(): array { return all("SELECT cu.id, cu.full_name, d.name AS discipline FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 ORDER BY d.sort_order, d.name, cu.full_name"); }
function hr_years(): array { $y = (int) date('Y'); $min = (int) (scalar('SELECT MIN(year) FROM hr_entitlements') ?: $y); $min = min($min, (int) (scalar('SELECT YEAR(MIN(plan_date)) FROM plan_entries') ?: $y)); $out = []; for ($i = max($min, $y - 5); $i <= $y + 1; $i++) $out[] = $i; return $out; }

/* ------------------------------------------------------------ entitlements */
/** [user_id][type_id] => ['days','carry','note'] for one year. */
function hr_entitlements(int $year, bool $byPerson = false): array { $o = []; foreach (all('SELECT * FROM hr_entitlements WHERE year = ?', [$year]) as $r) { $k = $byPerson ? (int)($r['person_id'] ?? 0) : (int)($r['user_id'] ?? 0); if ($k) $o[$k][(int)$r['type_id']] = $r; } return $o; }
function hr_entitlements_save_from_post(): void {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess'));
    $year = (int)($_POST['year'] ?? 0); if ($year < 2000 || $year > 2100) throw new RuntimeException(t('err.notfound'));
    $types = hr_types(); $me = (int)(current_user()['id'] ?? 0); $r54 = function_exists('hr2_has') && hr2_has();
    $staff = $r54 ? array_column(hr_people(), 'id') : array_column(hr_staff(), 'id');
    foreach ((array)($_POST['d'] ?? []) as $key => $byType) {
        $key = (int)$key; if (!in_array($key, $staff, false)) continue; $pid = $r54 ? $key : null; $uid = $r54 ? ((int)(hr_person($key)['user_id'] ?? 0) ?: null) : $key;
        foreach ((array)$byType as $tid => $v) { $tid = (int)$tid; if (!isset($types[$tid])) continue;
            $days = min(2000, max(0, (float) str_replace(',', '.', (string)$v))); $carry = min(2000, max(0, (float) str_replace(',', '.', (string)($_POST['c'][$key][$tid] ?? '0')))); $open = $r54 ? min(2000, max(0, (float) str_replace(',', '.', (string)($_POST['o'][$key][$tid] ?? '0')))) : 0.0;
            $cur = $r54 ? one('SELECT id FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) LIMIT 1', [$year, $tid, $pid, $uid, $uid ?? 0]) : one('SELECT id FROM hr_entitlements WHERE user_id = ? AND year = ? AND type_id = ?', [$uid, $year, $tid]);
            if ($days <= 0 && $carry <= 0 && $open <= 0) { if ($cur) q('DELETE FROM hr_entitlements WHERE id = ?', [(int)$cur['id']]); continue; }
            if ($cur && $r54 && function_exists('hr_audit')) { $o = one('SELECT days, carry, opening_taken, auto FROM hr_entitlements WHERE id = ?', [(int)$cur['id']]); if ($o && ((float)$o['days'] != $days || (float)$o['carry'] != $carry || (float)$o['opening_taken'] != $open)) hr_audit($pid, 'entitlement', $types[$tid]['code'] . ' ' . $year, $o['days'] . ' / ' . $o['carry'] . ' / ' . $o['opening_taken'], $days . ' / ' . $carry . ' / ' . $open, 'matrix'); if ($o && !empty($o['auto']) && (float)$o['days'] != $days) q('UPDATE hr_entitlements SET auto = 0 WHERE id = ?', [(int)$cur['id']]); }
            if ($cur) q('UPDATE hr_entitlements SET days = ?, carry = ?, ' . ($r54 ? 'opening_taken = ?, person_id = ?, ' : '') . 'user_id = ?, updated_by = ? WHERE id = ?', $r54 ? [$days, $carry, $open, $pid, $uid, $me, (int)$cur['id']] : [$days, $carry, $uid, $me, (int)$cur['id']]);
            else q('INSERT INTO hr_entitlements (user_id, year, type_id, days, carry, updated_by' . ($r54 ? ', opening_taken, person_id' : '') . ') VALUES (?,?,?,?,?,?' . ($r54 ? ',?,?' : '') . ')', $r54 ? [$uid, $year, $tid, $days, $carry, $me, $open, $pid] : [$uid, $year, $tid, $days, $carry, $me]);
        }
    }
}
/** Copy last year's entitlements into $year (only where the year has none); the carry-over = last year's remaining of counting types, capped at $cap days. */
function hr_entitlements_copy(int $year, float $cap = 5.0): int {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $prev = $year - 1; $n = 0; $me = (int)(current_user()['id'] ?? 0);
    foreach (hr_entitlements($prev) as $uid => $byType) foreach ($byType as $tid => $e) {
        if (scalar('SELECT COUNT(*) FROM hr_entitlements WHERE user_id = ? AND year = ? AND type_id = ?', [$uid, $year, $tid])) continue;
        $t = hr_type($tid); $rem = $t && $t['counts'] ? max(0.0, (float)$e['days'] + (float)$e['carry'] - (float)(hr_taken($uid, $prev)[$tid] ?? 0)) : 0.0;
        q('INSERT INTO hr_entitlements (user_id, year, type_id, days, carry, updated_by) VALUES (?,?,?,?,?,?)', [$uid, $year, $tid, (float)$e['days'], min($cap, $rem), $me]); $n++;
    }
    return $n;
}
/** Days taken per type in a year for one person: [type_id => days]. Entries without a type count for their kind's default type. */
function hr_taken(int $uid, int $year): array {
    $o = []; foreach (all("SELECT COALESCE(e.leave_type_id, 0) tid, e.kind, COUNT(DISTINCT e.plan_date) n FROM plan_entries e WHERE e.user_id = ? AND YEAR(e.plan_date) = ? AND e.kind <> 'work' GROUP BY COALESCE(e.leave_type_id, 0), e.kind", [$uid, $year]) as $r) {
        $tid = (int)$r['tid'] ?: (int)(hr_default_type((string)$r['kind']) ?? 0); if (!$tid) continue; $o[$tid] = ($o[$tid] ?? 0) + (int)$r['n'];
    }
    return $o;
}
/** Report rows for one person and year: per type entitled / carry / taken / remaining / state. */
function hr_report(int $uid, int $year): array {
    if (function_exists('hr2_has') && hr2_has() && ($pp = hr_person_of_user($uid))) {   // R54: balances per person incl. requests, hours, opening figures
        $out = []; foreach (hr_balances($pp, $year) as $tid => $b) { $rem = $b['available']; $out[$tid] = ['type' => $b['type'], 'entitled' => $b['entitled'], 'carry' => $b['carry'], 'taken' => $b['taken'] + $b['opening'], 'pending' => $b['pending'], 'remaining' => $rem, 'unit' => $b['unit'], 'state' => $rem === null ? 'na' : ($rem < 0 ? 'over' : ($rem < 3 ? 'low' : 'ok'))]; } return $out;
    }
    $E = hr_entitlements($year)[$uid] ?? []; $T = hr_taken($uid, $year); $out = [];
    foreach (hr_types() as $tid => $t) {
        $ent = (float)($E[$tid]['days'] ?? 0); $carry = (float)($E[$tid]['carry'] ?? 0); $taken = (float)($T[$tid] ?? 0); if ($ent <= 0 && $carry <= 0 && $taken <= 0) continue;
        $rem = $t['counts'] ? $ent + $carry - $taken : null;
        $out[$tid] = ['type' => $t, 'entitled' => $ent, 'carry' => $carry, 'taken' => $taken, 'remaining' => $rem, 'state' => $rem === null ? 'na' : ($rem < 0 ? 'over' : ($rem < 3 ? 'low' : 'ok'))];
    }
    return $out;
}
/** The absences of one person in a year, newest first, grouped into ranges of consecutive days of the same type. */
function hr_absences(int $uid, int $year): array {
    $rows = all("SELECT e.plan_date, e.kind, e.leave_type_id, e.reason, e.note FROM plan_entries e WHERE e.user_id = ? AND YEAR(e.plan_date) = ? AND e.kind <> 'work' ORDER BY e.plan_date, e.id", [$uid, $year]);
    $out = []; $cur = null; $seen = [];
    foreach ($rows as $r) {
        $tid = (int)$r['leave_type_id'] ?: (int)(hr_default_type((string)$r['kind']) ?? 0); $key = $tid . '|' . (string)$r['reason'];
        if (isset($seen[$r['plan_date'] . '|' . $tid])) continue; $seen[$r['plan_date'] . '|' . $tid] = true;   // one day counts once per type
        if ($cur && $cur['key'] === $key && (strtotime($r['plan_date']) - strtotime($cur['to'])) <= 4 * 86400) { $cur['to'] = $r['plan_date']; $cur['days']++; continue; }
        if ($cur) $out[] = $cur;
        $cur = ['key' => $key, 'type' => hr_type($tid), 'kind' => $r['kind'], 'from' => $r['plan_date'], 'to' => $r['plan_date'], 'days' => 1, 'reason' => (string)($r['reason'] ?? ''), 'note' => (string)($r['note'] ?? '')];
    }
    if ($cur) $out[] = $cur;
    return array_reverse($out);
}
/** Company summary for a year: per person, per type taken, remaining of counting types. */
function hr_summary(int $year): array {
    $o = [];
    if (function_exists('hr2_has') && hr2_has()) { foreach (hr_people() as $pp) { $rows = []; foreach (hr_balances($pp, $year) as $tid => $b) $rows[$tid] = ['type' => $b['type'], 'entitled' => $b['entitled'], 'carry' => $b['carry'], 'taken' => $b['taken'] + $b['opening'], 'pending' => $b['pending'], 'remaining' => $b['available'], 'unit' => $b['unit'], 'state' => $b['available'] === null ? 'na' : ($b['available'] < 0 ? 'over' : ($b['available'] < 3 ? 'low' : 'ok'))]; $o[(int)$pp['id']] = ['person' => ['id' => (int)($pp['user_id'] ?? 0), 'pid' => (int)$pp['id'], 'full_name' => $pp['name_en'], 'discipline' => $pp['department'] ?: ($pp['discipline'] ?? '')], 'rows' => $rows]; } return $o; }
    foreach (hr_staff() as $p) $o[(int)$p['id']] = ['person' => $p, 'rows' => hr_report((int)$p['id'], $year)]; return $o;
}

/* ------------------------------------------------------------ settings: types */
function hr_type_save_from_post(): void {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $cur = $id ? hr_type($id) : null; if ($id && !$cur) throw new RuntimeException(t('err.notfound'));
    if (!empty($_POST['toggle']) && $cur) { if ($cur['builtin'] && $cur['is_active']) throw new RuntimeException(t('hr.builtin_keep')); q('UPDATE hr_leave_types SET is_active = 1 - is_active WHERE id = ?', [$id]); return; }
    if (!empty($_POST['delete']) && $cur) { if ($cur['builtin']) throw new RuntimeException(t('hr.builtin_keep')); if (scalar('SELECT COUNT(*) FROM plan_entries WHERE leave_type_id = ?', [$id]) || scalar('SELECT COUNT(*) FROM hr_entitlements WHERE type_id = ?', [$id])) throw new RuntimeException(t('hr.type_in_use')); q('DELETE FROM hr_leave_types WHERE id = ?', [$id]); return; }
    $code = strtoupper(mb_substr(trim((string)($_POST['code'] ?? '')), 0, 12)); $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $kind = in_array($_POST['kind'] ?? '', HR_KINDS, true) ? $_POST['kind'] : 'leave';
    if ($name === '' || !preg_match('/^[A-Z0-9_-]{2,12}$/', $code)) throw new RuntimeException(t('hr.type_need'));
    $v = [$code, $name, $kind, (int)!empty($_POST['counts']), (int)!empty($_POST['needs_reason']), (int)($_POST['sort_order'] ?? 0)];
    $r54 = function_exists('hr2_has') && hr2_has();
    if ($r54) { $mx = trim((string)($_POST['max_year'] ?? '')); $rk = mb_substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string)($_POST['rule_key'] ?? ''))), 0, 24);
        $v = [...$v, ($_POST['unit'] ?? 'day') === 'hour' ? 'hour' : 'day', (int)!empty($_POST['hr_only']), (int)!empty($_POST['needs_doc']), (int)!empty($_POST['paid']), (int)!empty($_POST['consumes_annual']), $mx === '' ? null : (float) str_replace(',', '.', $mx), $rk === '' ? null : $rk]; }
    $ml = $r54 && hr_has_names(); $mlSet = ''; $mlCols = '';
    if ($ml) foreach (HR_NAME_LANGS as $lc) { $v[] = mb_substr(trim((string)($_POST['name_' . $lc] ?? '')), 0, 120) ?: null; $mlSet .= ", name_$lc = ?"; $mlCols .= ", name_$lc"; }
    try { if ($id) q('UPDATE hr_leave_types SET code = ?, name = ?, kind = ?, counts = ?, needs_reason = ?, sort_order = ?' . ($r54 ? ', unit = ?, hr_only = ?, needs_doc = ?, paid = ?, consumes_annual = ?, max_year = ?, rule_key = ?' : '') . $mlSet . ' WHERE id = ?', [...$v, $id]);
          else q('INSERT INTO hr_leave_types (code, name, kind, counts, needs_reason, sort_order' . ($r54 ? ', unit, hr_only, needs_doc, paid, consumes_annual, max_year, rule_key' : '') . $mlCols . ') VALUES (' . implode(',', array_fill(0, count($v), '?')) . ')', $v); }
    catch (PDOException $e) { if ((int)$e->errorInfo[1] === 1062) throw new RuntimeException(t('sys.dup', ['v' => $code])); throw $e; }
}
/** Validate the type / reason of a board assignment (called by pl_assign). Returns [type_id, reason]. */
function hr_assign_check(string $kind, array $in): array {
    if (!hr_has() || $kind === 'work' || !in_array($kind, HR_KINDS, true)) return [null, null];
    $tid = (int)($in['leave_type_id'] ?? 0) ?: hr_default_type($kind); $t = $tid ? (hr_types()[$tid] ?? null) : null;
    if ($t && $t['kind'] !== $kind) $t = null;
    $reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 300) ?: null;
    if ($t && $t['needs_reason'] && $reason === null) throw new RuntimeException(t('hr.need_reason', ['t' => $t['name']]));
    return [$t ? (int)$t['id'] : null, $reason];
}
/** Excel: GAS-HR-<year>.xlsx — Summary sheet + one Absences sheet. */
function hr_export(int $year): void {
    if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $types = hr_types();
    $hdr = [t('ll.who'), t('disc.name')]; foreach ($types as $t) { $hdr[] = $t['code'] . ' ' . t('hr.x_ent'); $hdr[] = $t['code'] . ' ' . t('hr.x_taken'); if ($t['counts']) $hdr[] = $t['code'] . ' ' . t('hr.x_rem'); }
    $rows = []; $abs = [];
    foreach (hr_summary($year) as $uid => $s) {
        $r = [$s['person']['full_name'], (string)($s['person']['discipline'] ?? '')];
        foreach ($types as $tid => $t) { $x = $s['rows'][$tid] ?? null; $r[] = $x ? $x['entitled'] + $x['carry'] : ''; $r[] = $x ? $x['taken'] : ''; if ($t['counts']) $r[] = $x && $x['remaining'] !== null ? $x['remaining'] : ''; }
        $rows[] = $r;
        foreach (hr_absences($uid, $year) as $a) $abs[] = [$s['person']['full_name'], $a['type']['code'] ?? $a['kind'], $a['type']['name'] ?? t('pl.k_' . $a['kind']), $a['from'], $a['to'], $a['days'], $a['reason'], $a['note']];
    }
    xlsx_download('GAS-HR-' . $year . '.xlsx', [['name' => t('hr.x_summary') . ' ' . $year, 'first' => true, 'header' => $hdr, 'rows' => $rows], ['name' => t('hr.x_absences'), 'header' => [t('ll.who'), t('hr.type_code'), t('hr.type'), t('pl.from'), t('pl.to'), t('hr.days'), t('hr.reason'), t('pl.note')], 'rows' => $abs]]);
}

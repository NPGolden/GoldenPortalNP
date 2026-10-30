<?php
/**
 * Budget vs actual per sub-project (GAS-CP-R34-001) — internal, for staff whose role shows financial data.
 *   Budget   = hours per discipline (rates snapshotted from Settings › Disciplines) + parts budget, in revisions R0, R1 …;
 *              the offer value is the sum of the approved offers of the sub-project unless typed on the budget.
 *   Actual   = approved / invoiced timesheet hours of the sub-project per discipline × the discipline rates per hour type,
 *              + the parts cost entries (orders = committed, invoices = invoiced to us).
 *   Result   = offer value − (hours cost + parts committed); forecast at completion adds the remaining budgeted hours at
 *              the budget rate and keeps the larger of parts budget / committed. Earned hours = budget × stage progress.
 * Shown as: the Budget & actual tab (A), the project cost-control board + cost report (B), the live strip on every
 * sub-project tab, the result pill in the project tree, the dashboard attention list and a notification when a
 * budget is exceeded (C).
 */

function bg_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'sp_budget_hours'")->fetch(); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function bg_has_sections(): bool { static $h = null; if ($h === null) { try { $h = bg_has() && (bool) scalar("SHOW COLUMNS FROM sp_budget_hours LIKE 'section_id'"); } catch (Throwable $e) { $h = false; } } return $h; }   // R48-001
function bg_can(): bool { return bg_has() && is_staff() && function_exists('fin_visible') && fin_visible(); }
function bg_can_edit(array $sp): bool {
    if (!bg_can()) return false;
    if (is_cp_admin()) return true;
    $u = current_user(); $pm = (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [(int)$sp['project_id']]);
    if ($pm && $pm === (int)$u['id']) return true;
    if (function_exists('pm_is_pm') && pm_is_pm((int)$u['id'])) return true;                                           // R57-002: every PM
    try { $role = scalar('SELECT r.name FROM staff_roles r JOIN customer_users cu ON cu.staff_role_id = r.id WHERE cu.id = ?', [(int)$u['id']]); if ($role && preg_match('/manager|director|ceo|sales/i', (string)$role)) return true; } catch (Throwable $e) {}
    return false;
}
function bg_disciplines(): array { static $c = null; if ($c === null) $c = all('SELECT * FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name'); return $c; }
function bg_money(float $v, string $cur = 'EUR'): string { return number_format($v, 0, ',', '.') . ' ' . $cur; }
function bg_h(float $h): string { return rtrim(rtrim(number_format($h, 1, '.', ''), '0'), '.') ?: '0'; }

/* ------------------------------------------------------------------ budget revisions */
function bg_current(int $sid): ?array {
    if (!bg_has()) return null;
    $b = one('SELECT * FROM sp_budgets WHERE subproject_id = ? ORDER BY revision DESC, id DESC LIMIT 1', [$sid]); if (!$b) return null;
    $b['hours'] = []; $b['sections'] = [];
    foreach (all('SELECT * FROM sp_budget_hours WHERE budget_id = ?', [(int)$b['id']]) as $h) {   // R48-001: a discipline may have one row per section; 'hours' stays the discipline total
        $k = (int)$h['discipline_id']; $b['hours'][$k] = ['hours' => (float)($b['hours'][$k]['hours'] ?? 0) + (float)$h['hours'], 'rate' => (float)$h['rate']];
        if (!empty($h['section_id'])) $b['sections'][$k][(int)$h['section_id']] = (float)($b['sections'][$k][(int)$h['section_id']] ?? 0) + (float)$h['hours'];
    }
    return $b;
}
function bg_history(int $sid): array {
    if (!bg_has()) return [];
    $rows = all('SELECT b.*, (SELECT SUM(hours) FROM sp_budget_hours h WHERE h.budget_id = b.id) AS total_hours, (SELECT SUM(hours * rate) FROM sp_budget_hours h WHERE h.budget_id = b.id) AS hours_cost FROM sp_budgets b WHERE b.subproject_id = ? ORDER BY b.revision DESC, b.id DESC', [$sid]);
    return $rows;
}
function bg_save_from_post(int $sid): int {
    $sp = load_subproject($sid); if (!$sp) throw new RuntimeException(t('err.notfound'));
    if (!bg_can_edit($sp)) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $rev = (int) scalar('SELECT COALESCE(MAX(revision), -1) + 1 FROM sp_budgets WHERE subproject_id = ?', [$sid]);
    $parts = of_parse_amount((string)($_POST['parts_budget'] ?? '0'));
    $ov = trim((string)($_POST['offer_value'] ?? '')); $ovv = $ov === '' ? null : of_parse_amount($ov);
    $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 500) ?: null;
    q('INSERT INTO sp_budgets (subproject_id, revision, parts_budget, offer_value, notes, created_by, created_name) VALUES (?,?,?,?,?,?,?)', [$sid, $rev, $parts, $ovv, $notes, (int)$u['id'], (string)$u['full_name']]);
    $bid = (int) db()->lastInsertId();
    $split = function_exists('eng_split_disciplines') && eng_has() ? eng_split_disciplines() : []; $hasSec = bg_has_sections();
    foreach (bg_disciplines() as $d) {
        $k = (int)$d['id']; $rate = (float) str_replace(',', '.', (string)($_POST['r'][$k] ?? '')); if ($rate <= 0) $rate = function_exists('br_hour_cost') ? br_hour_cost($d, 'normal', null, $sid) : (float)($d['rate_normal'] ?? 0);   // R40-002: branch mean cost fallback
        if ($hasSec && isset($split[$k])) {   // R48-001: one row per section for a discipline that feeds several sections
            foreach ($split[$k] as $sec => $nm) { $hs = max(0, (float) str_replace(',', '.', (string)($_POST['hs'][$k][$sec] ?? '0'))); if ($hs > 0) q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate, section_id) VALUES (?,?,?,?,?)', [$bid, $k, $hs, $rate, (int)$sec]); }
            continue;
        }
        $h = max(0, (float) str_replace(',', '.', (string)($_POST['h'][$k] ?? '0'))); if ($h <= 0) continue;
        q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate) VALUES (?,?,?,?)', [$bid, $k, $h, $rate]);
    }
    return $bid;
}
/** Pre-fill hours from the approved offers' rate lines (card letter → discipline by name). */
function bg_prefill_from_offers(int $sid): array {
    $map = ['A' => 'Service', 'B' => 'Service', 'C' => 'Service', 'D' => 'Automation', 'E' => 'Engineering', 'H' => 'Service', 'I' => 'Navigation', 'J' => 'Automation', 'K' => 'Automation', 'L' => 'Electrical', 'M' => 'Electrical', 'N' => 'Service'];
    $out = []; if (!function_exists('ob_has') || !ob_has()) return $out;
    $disc = bg_disciplines();
    foreach (all("SELECT l.qty, l.unit, i.card FROM offer_lines l JOIN offers o ON o.id = l.offer_id LEFT JOIN rate_items i ON i.id = l.rate_item_id WHERE o.subproject_id = ? AND l.kind = 'rate' AND l.unit IN ('hour','h','hours','day','days')", [$sid]) as $l) {
        $want = $map[(string)$l['card']] ?? 'Service'; $did = 0;
        foreach ($disc as $d) if (stripos((string)$d['name'], $want) !== false) { $did = (int)$d['id']; break; }
        if (!$did && $disc) $did = (int)$disc[0]['id'];
        $out[$did] = ($out[$did] ?? 0) + (float)$l['qty'] * (in_array($l['unit'], ['day', 'days'], true) ? 8 : 1);
    }
    return $out;
}

/* ------------------------------------------------------------------ actuals */
function bg_actual_hours(int $sid): array {
    $out = [];
    foreach (all("SELECT t.discipline_id, MAX(COALESCE(d.name, t.discipline_name)) AS name, l.hour_type, SUM(l.hours) AS h, MAX(d.rate_normal) AS rate_normal, MAX(d.rate_overtime) AS rate_overtime, MAX(d.rate_holiday) AS rate_holiday
                  FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id LEFT JOIN disciplines d ON d.id = t.discipline_id
                  WHERE l.subproject_id = ? AND t.status IN ('approved','invoiced') GROUP BY t.discipline_id, l.hour_type", [$sid]) as $r) {
        $k = (int)($r['discipline_id'] ?? 0); $out[$k] ??= ['name' => (string)($r['name'] ?: t('bg.no_discipline')), 'hours' => 0.0, 'cost' => 0.0, 'h_normal' => 0.0, 'h_overtime' => 0.0, 'h_holiday' => 0.0];
        $h = (float)$r['h']; $rate = function_exists('br_hour_cost') ? br_hour_cost($r, (string)$r['hour_type'], null, $sid) : ((float)($r['rate_' . $r['hour_type']] ?? 0) ?: (float)($r['rate_normal'] ?? 0));   // R40-002
        $out[$k]['hours'] += $h; $out[$k]['cost'] += $h * $rate; $out[$k]['h_' . $r['hour_type']] += $h;
    }
    return $out;
}
function bg_costs(int $sid): array { return bg_has() ? all('SELECT c.*, f.original_name FROM sp_costs c LEFT JOIN sp_files f ON f.id = c.file_id WHERE c.subproject_id = ? ORDER BY c.cost_date DESC, c.id DESC', [$sid]) : []; }
function bg_cost_save_from_post(int $sid): int {
    $sp = load_subproject($sid); if (!$sp || !bg_can()) throw new RuntimeException(t('err.noaccess'));
    $u = current_user(); $id = (int)($_POST['cost_id'] ?? 0);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['cost_date'] ?? '')) ? $_POST['cost_date'] : date('Y-m-d');
    $kind = in_array($_POST['kind'] ?? '', ['order', 'invoice', 'other'], true) ? $_POST['kind'] : 'order';
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 255); if ($desc === '') throw new RuntimeException(t('ob.need_desc'));
    $sup = mb_substr(trim((string)($_POST['supplier'] ?? '')), 0, 120) ?: null; $ref = mb_substr(trim((string)($_POST['ref_no'] ?? '')), 0, 60) ?: null;
    $amt = of_parse_amount((string)($_POST['amount'] ?? '0'));
    $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], (int)$sp['project_id'], $sid, 'cost', 'internal', $desc) : null;
    if ($id) q('UPDATE sp_costs SET cost_date=?, kind=?, supplier=?, description=?, ref_no=?, amount=?' . ($fileId ? ', file_id=?' : '') . ' WHERE id=? AND subproject_id=?', array_merge([$date, $kind, $sup, $desc, $ref, $amt], $fileId ? [$fileId] : [], [$id, $sid]));
    else { q('INSERT INTO sp_costs (subproject_id, cost_date, kind, supplier, description, ref_no, amount, file_id, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?)', [$sid, $date, $kind, $sup, $desc, $ref, $amt, $fileId, (int)$u['id'], (string)$u['full_name']]); $id = (int) db()->lastInsertId(); }
    bg_check_alerts($sid);
    return $id;
}
function bg_cost_delete(int $sid, int $id): void { if (!bg_can()) throw new RuntimeException(t('err.noaccess')); q('DELETE FROM sp_costs WHERE id = ? AND subproject_id = ?', [$id, $sid]); }

/* ------------------------------------------------------------------ the calculation */
function bg_offer_value(int $sid): float {
    if (!function_exists('of_has') || !of_has()) return 0.0;
    $ok = of_statuses_where(['approved', 'closed_won']); if (!$ok) return 0.0;
    return (float) scalar('SELECT COALESCE(SUM(amount), 0) FROM offers WHERE subproject_id = ? AND status IN (' . implode(',', array_fill(0, count($ok), '?')) . ')', array_merge([$sid], $ok));
}
/** Everything the screens need for one sub-project. $sub = the subproject row (stages) when at hand. */
function bg_calc(int $sid, ?array $sub = null): array {
    static $cache = [];
    if (isset($cache[$sid])) return $cache[$sid];
    $sub = $sub ?: one('SELECT * FROM subprojects WHERE id = ?', [$sid]);
    $B = bg_current($sid); $A = bg_actual_hours($sid); $disc = bg_disciplines();
    $rows = []; $bh = 0.0; $bc = 0.0; $ah = 0.0; $ac = 0.0;
    foreach ($disc as $d) {
        $k = (int)$d['id']; $h = (float)($B['hours'][$k]['hours'] ?? 0); $r = (float)($B['hours'][$k]['rate'] ?? 0) ?: (function_exists('br_hour_cost') ? br_hour_cost($d, 'normal', null, $sid) : (float)($d['rate_normal'] ?? 0));
        $a = $A[$k] ?? null; if ($h <= 0 && !$a) continue;
        $rows[$k] = ['name' => (string)$d['name'], 'budget_h' => $h, 'rate' => $r, 'budget_cost' => $h * $r, 'actual_h' => (float)($a['hours'] ?? 0), 'actual_cost' => (float)($a['cost'] ?? 0)];
        $bh += $h; $bc += $h * $r; $ah += (float)($a['hours'] ?? 0); $ac += (float)($a['cost'] ?? 0);
    }
    foreach ($A as $k => $a) if (!isset($rows[$k])) { $rows[$k] = ['name' => $a['name'], 'budget_h' => 0.0, 'rate' => 0.0, 'budget_cost' => 0.0, 'actual_h' => $a['hours'], 'actual_cost' => $a['cost']]; $ah += $a['hours']; $ac += $a['cost']; }
    foreach ($rows as &$r) { $r['remaining_h'] = $r['budget_h'] - $r['actual_h']; $r['pct'] = $r['budget_h'] > 0 ? (int) round($r['actual_h'] / $r['budget_h'] * 100) : ($r['actual_h'] > 0 ? 100 : 0); $r['tone'] = $r['budget_h'] <= 0 && $r['actual_h'] > 0 ? 'red' : ($r['pct'] > 100 ? 'red' : ($r['pct'] >= 90 ? 'amber' : 'green')); } unset($r);
    $costs = bg_costs($sid); $pc = 0.0; $pi = 0.0; foreach ($costs as $c) { if ($c['kind'] === 'invoice') $pi += (float)$c['amount']; $pc += (float)$c['amount']; }
    $pb = (float)($B['parts_budget'] ?? 0);
    $ov = $B && $B['offer_value'] !== null ? (float)$B['offer_value'] : bg_offer_value($sid);
    $prog = $sub ? overall_pct($sub) : 0; if (function_exists('pct_na') && pct_na($prog)) $prog = 0;
    $earned = $bh * $prog / 100;
    $avgRate = $bh > 0 ? $bc / $bh : 0.0; $remH = max(0, $bh - $ah);
    $costToDate = $ac + $pc; $forecast = $ac + $remH * $avgRate + max($pb, $pc);
    $C = ['sid' => $sid, 'budget' => $B, 'rows' => $rows, 'budget_h' => $bh, 'budget_cost' => $bc, 'actual_h' => $ah, 'actual_cost' => $ac, 'remaining_h' => $remH,
          'hours_pct' => $bh > 0 ? (int) round($ah / $bh * 100) : 0, 'progress' => $prog, 'earned_h' => $earned,
          'parts_budget' => $pb, 'parts_committed' => $pc, 'parts_invoiced' => $pi, 'parts_pct' => $pb > 0 ? (int) round($pc / $pb * 100) : 0, 'costs' => $costs,
          'offer_value' => $ov, 'cost_to_date' => $costToDate, 'forecast_cost' => $forecast, 'result' => $ov - $costToDate, 'forecast_result' => $ov - $forecast, 'budget_result' => $ov - $bc - $pb,
          'has_budget' => (bool)$B, 'updated_at' => bg_last_change($sid)];
    $C['flags'] = [];
    if ($bh > 0 && $ah > $bh) $C['flags'][] = 'hours_over'; elseif ($bh > 0 && $C['hours_pct'] > $prog + 15 && $prog < 100) $C['flags'][] = 'hours_ahead';
    if ($pb > 0 && $pc > $pb) $C['flags'][] = 'parts_over';
    if ($ov > 0 && $C['forecast_result'] < 0) $C['flags'][] = 'loss';
    $C['tone'] = in_array('loss', $C['flags'], true) || in_array('hours_over', $C['flags'], true) || in_array('parts_over', $C['flags'], true) ? 'red' : (in_array('hours_ahead', $C['flags'], true) ? 'amber' : 'green');
    return $cache[$sid] = $C;
}
function bg_last_change(int $sid): ?string {
    $a = scalar("SELECT MAX(t.approved_at) FROM timesheets t JOIN timesheet_lines l ON l.timesheet_id = t.id WHERE l.subproject_id = ? AND t.status IN ('approved','invoiced')", [$sid]);
    $b = bg_has() ? scalar('SELECT MAX(created_at) FROM sp_costs WHERE subproject_id = ?', [$sid]) : null;
    $c = bg_has() ? scalar('SELECT MAX(created_at) FROM sp_budgets WHERE subproject_id = ?', [$sid]) : null;
    $m = array_filter([$a, $b, $c]); return $m ? max($m) : null;
}
function bg_flag_label(string $f): string { return t('bg.f_' . $f); }
function bg_pct_tone(int $pct, int $progress = 0): string { return $pct > 100 ? 'red' : ($pct >= 90 ? 'amber' : 'green'); }

/** Project roll-up: per sub-project calc + totals. */
function bg_project(int $pid): array {
    $subs = all('SELECT * FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
    $P = ['subs' => [], 'offer_value' => 0.0, 'budget_h' => 0.0, 'budget_cost' => 0.0, 'actual_h' => 0.0, 'actual_cost' => 0.0, 'parts_budget' => 0.0, 'parts_committed' => 0.0, 'cost_to_date' => 0.0, 'forecast_cost' => 0.0, 'result' => 0.0, 'forecast_result' => 0.0, 'budget_result' => 0.0, 'earned_h' => 0.0, 'at_risk' => 0];
    foreach ($subs as $s) {
        $C = bg_calc((int)$s['id'], $s); $C['sub'] = $s; $P['subs'][] = $C;
        foreach (['offer_value', 'budget_h', 'budget_cost', 'actual_h', 'actual_cost', 'parts_budget', 'parts_committed', 'cost_to_date', 'forecast_cost', 'result', 'forecast_result', 'budget_result', 'earned_h'] as $k) $P[$k] += $C[$k];
        if ($C['tone'] === 'red') $P['at_risk']++;
    }
    $P['hours_pct'] = $P['budget_h'] > 0 ? (int) round($P['actual_h'] / $P['budget_h'] * 100) : 0; $P['parts_pct'] = $P['parts_budget'] > 0 ? (int) round($P['parts_committed'] / $P['parts_budget'] * 100) : 0;
    $P['progress'] = $P['budget_h'] > 0 ? (int) round($P['earned_h'] / $P['budget_h'] * 100) : 0;
    return $P;
}
/** Monthly cumulative curve (budget phased linearly over the project period, actuals by approval / cost date). */
function bg_curve(int $pid, array $P): array {
    $from = scalar('SELECT MIN(created_at) FROM projects WHERE id = ?', [$pid]); $to = scalar('SELECT COALESCE(MAX(deadline), CURDATE()) FROM subprojects WHERE project_id = ?', [$pid]);
    $start = new DateTime(substr((string)($from ?: date('Y-m-d')), 0, 7) . '-01'); $end = new DateTime(max(substr((string)$to, 0, 7), date('Y-m')) . '-01');
    $months = []; $d = clone $start; while ($d <= $end && count($months) < 24) { $months[] = $d->format('Y-m'); $d->modify('+1 month'); }
    $n = max(1, count($months)); $budgetTotal = $P['budget_cost'] + $P['parts_budget'];
    $actH = []; foreach (all("SELECT DATE_FORMAT(t.approved_at, '%Y-%m') m, SUM(l.hours * COALESCE(NULLIF(CASE l.hour_type WHEN 'overtime' THEN d.rate_overtime WHEN 'holiday' THEN d.rate_holiday ELSE d.rate_normal END, 0), NULLIF(d.rate_normal, 0), ?)) c FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id JOIN subprojects s ON s.id = l.subproject_id LEFT JOIN disciplines d ON d.id = t.discipline_id WHERE s.project_id = ? AND t.status IN ('approved','invoiced') GROUP BY m", [function_exists('br_manhour_cost') ? br_manhour_cost($pid) : 35.0, $pid]) as $r) $actH[$r['m']] = (float)$r['c'];
    $actP = []; if (bg_has()) foreach (all("SELECT DATE_FORMAT(c.cost_date, '%Y-%m') m, SUM(c.amount) a FROM sp_costs c JOIN subprojects s ON s.id = c.subproject_id WHERE s.project_id = ? GROUP BY m", [$pid]) as $r) $actP[$r['m']] = (float)$r['a'];
    $out = []; $cumA = 0.0; $now = date('Y-m');
    foreach ($months as $i => $m) { $cumA += ($actH[$m] ?? 0) + ($actP[$m] ?? 0); $out[] = ['m' => $m, 'budget' => $budgetTotal * ($i + 1) / $n, 'actual' => $m <= $now ? $cumA : null]; }
    return $out;
}

/* ------------------------------------------------------------------ alerts */
function bg_check_alerts(int $sid): void {
    if (!bg_has() || !function_exists('nf_push')) return;
    $sp = one('SELECT s.*, p.project_code, p.pm_user_id FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sid]); if (!$sp) return;
    $C = bg_calc($sid, $sp); if (!$C['has_budget']) return;
    $u = current_user(); $to = array_values(array_unique(array_filter(array_merge(nf_project_staff((int)$sp['project_id']), [(int)$sp['pm_user_id']]))));
    $to = function_exists('of_users') ? of_users($to) : $to;
    foreach ($C['flags'] as $f) { if (!in_array($f, ['hours_over', 'parts_over'], true)) continue;
        nf_push($to, 'bg:' . $f, 'bg:' . $sid . ':' . $f, trim(($sp['project_code'] ?: '') . ' › ' . ($sp['code'] ?: ''), ' ›') . ' — ' . bg_flag_label($f), t('bg.nf_body', ['h' => bg_h($C['actual_h']) . ' / ' . bg_h($C['budget_h']), 'p' => bg_money($C['parts_committed']) . ' / ' . bg_money($C['parts_budget'])]), url('subproject', ['id' => $sid, 'tab' => 'budget']), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''));
    }
}

/* ------------------------------------------------------------------ HTML pieces */
function bg_bar(float $pct, string $tone, ?int $tick = null, string $h = '10px'): string {
    $w = max(0, min(100, $pct)); $hex = ['green' => '#2F7D5C', 'amber' => '#C89B3C', 'red' => '#9B2C2C', 'navy' => '#1B4F8A', 'grey' => '#B9C3CF'][$tone] ?? '#1B4F8A';
    return '<div class="bg-bar" style="height:' . $h . ';"><div class="bg-fill" style="width:' . $w . '%;background:' . $hex . ';"></div>' . ($tick !== null ? '<div class="bg-tick" style="left:' . max(0, min(100, $tick)) . '%;" title="' . tt('bg.progress') . ' ' . $tick . ' %"></div>' : '') . '</div>';
}
/** The live strip under the sub-project title (every tab). */
function bg_strip_html(array $sp): string {
    if (!bg_can()) return '';
    $C = bg_calc((int)$sp['id'], $sp); $sid = (int)$sp['id'];
    if (!$C['has_budget'] && $C['actual_h'] <= 0 && !$C['costs']) return '<div class="bg-strip bg-strip--empty"><span>' . tt('bg.no_budget_yet') . '</span><a class="btn btn--sm btn--gold" href="' . url('subproject', ['id' => $sid, 'tab' => 'budget']) . '">' . tt('bg.set_budget') . '</a></div>';
    $rt = $C['result'] >= 0 ? 'bg-pos' : 'bg-neg'; $ft = $C['forecast_result'] >= 0 ? '' : ' bg-neg';
    $h = '<div class="bg-strip">';
    $h .= '<div class="bg-s-main"><div class="bg-s-k">' . tt('bg.live_result') . '</div><div class="bg-s-v ' . $rt . '">' . ($C['result'] >= 0 ? '+ ' : '− ') . e(bg_money(abs($C['result']))) . ' <small>' . tt('bg.to_date') . '</small></div><div class="bg-s-h">' . tt('bg.forecast') . ' <b class="' . trim($ft) . '">' . ($C['forecast_result'] >= 0 ? '+ ' : '− ') . e(bg_money(abs($C['forecast_result']))) . '</b> · ' . tt('bg.budgeted') . ' ' . ($C['budget_result'] >= 0 ? '+ ' : '− ') . e(bg_money(abs($C['budget_result']))) . '</div></div>';
    $h .= '<div class="bg-s-col"><div class="bg-s-r"><span>' . tt('bg.manhours') . '</span><b>' . e(bg_h($C['actual_h']) . ' / ' . bg_h($C['budget_h'])) . ' h</b></div>' . bg_bar($C['hours_pct'], bg_pct_tone($C['hours_pct']), $C['progress'], '8px') . '<div class="bg-s-h">' . ($C['budget_h'] > 0 ? e(t('bg.used_pct', ['p' => $C['hours_pct']])) : tt('bg.no_budget')) . ' · ' . tt('bg.progress') . ' ' . $C['progress'] . ' %</div></div>';
    $h .= '<div class="bg-s-col"><div class="bg-s-r"><span>' . tt('bg.parts') . '</span><b>' . e(bg_money($C['parts_committed']) . ' / ' . bg_money($C['parts_budget'])) . '</b></div>' . bg_bar($C['parts_pct'], bg_pct_tone($C['parts_pct']), null, '8px') . '<div class="bg-s-h' . (in_array('parts_over', $C['flags'], true) ? ' bg-neg' : '') . '">' . ($C['parts_budget'] > 0 ? e(t('bg.used_pct', ['p' => $C['parts_pct']])) : tt('bg.no_budget')) . (in_array('parts_over', $C['flags'], true) ? ' · + ' . e(bg_money($C['parts_committed'] - $C['parts_budget'])) . ' ' . tt('bg.over') : '') . '</div></div>';
    $h .= '<div class="bg-s-col"><div class="bg-s-r"><span>' . tt('bg.offer_value') . '</span><b>' . e(bg_money($C['offer_value'])) . '</b></div>' . bg_bar($C['offer_value'] > 0 ? $C['cost_to_date'] / $C['offer_value'] * 100 : 0, 'grey', null, '8px') . '<div class="bg-s-h">' . tt('bg.cost_to_date') . ' ' . e(bg_money($C['cost_to_date'])) . ($C['offer_value'] > 0 ? ' (' . (int) round($C['cost_to_date'] / $C['offer_value'] * 100) . ' %)' : '') . '</div></div>';
    $h .= '<div class="bg-s-act"><a class="btn btn--sm btn--gold" href="' . url('subproject', ['id' => $sid, 'tab' => 'budget']) . '">' . tt('bg.tab') . ' ›</a>';
    foreach ($C['flags'] as $f) $h .= '<span class="bg-flag bg-flag--' . ($f === 'hours_ahead' ? 'amber' : 'red') . '">' . e(bg_flag_label($f)) . '</span>';
    $h .= '<div class="bg-s-h">' . ($C['updated_at'] ? e(t('bg.updated', ['t' => fmt_datetime($C['updated_at'])])) : '') . '</div></div></div>';
    return $h;
}
/** The small result pill in the project tree. */
function bg_pill(array $sub): string {
    if (!bg_can()) return '';
    $C = bg_calc((int)$sub['id'], $sub); if (!$C['has_budget']) return '';
    $tone = $C['tone'] === 'red' ? 'bad' : ($C['tone'] === 'amber' ? 'gold' : 'green');
    return '<a class="bg-pill" href="' . url('subproject', ['id' => (int)$sub['id'], 'tab' => 'budget']) . '" title="' . e(t('bg.pill_title', ['h' => bg_h($C['actual_h']) . '/' . bg_h($C['budget_h']), 'p' => bg_money($C['parts_committed']) . '/' . bg_money($C['parts_budget'])])) . '">' . pill($tone, ($C['result'] >= 0 ? '+' : '−') . bg_money(abs($C['result']))) . '</a>';
}
/** Sub-projects over budget, for the dashboard attention list. */
function bg_attention(): array {
    if (!bg_can()) return [];
    $out = [];
    foreach (all('SELECT s.*, p.project_code FROM sp_budgets b JOIN subprojects s ON s.id = b.subproject_id JOIN projects p ON p.id = s.project_id WHERE p.status = "active" GROUP BY s.id') as $s) {
        $C = bg_calc((int)$s['id'], $s); if ($C['tone'] !== 'red') continue;
        $out[] = ['age' => 0, 'late' => true, 'tone' => 'bad', 'ref' => trim(($s['project_code'] ?: '') . ' ' . ($s['code'] ?: '')), 'text' => $s['name'] . ' — ' . implode(', ', array_map('bg_flag_label', $C['flags'])), 'url' => url('subproject', ['id' => (int)$s['id'], 'tab' => 'budget'])];
    }
    return $out;
}

/* ------------------------------------------------------------------ cost report (project, PDF) */
function bg_render_pdf(int $pid): string {
    require_once __DIR__ . '/pdf.php';
    $p = one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]); if (!$p) throw new RuntimeException(t('err.notfound'));
    $P = bg_project($pid); $curve = bg_curve($pid, $P);
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $L = 15.0; $R = 195.0; $W = $R - $L; $y = 0.0; $tableNo = 0;
    $bd = function_exists('br_doc') ? br_doc($pid) : ['logo' => null, 'name' => 'Golden Automation Systems', 'footer' => ''];
    $docNo = (function_exists('br_prefix') ? br_prefix($pid) : 'GAS') . '-CC-' . ($p['project_code'] ?: $pid) . '-' . date('Ymd');
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $R, $bd, $docNo, $p) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper(t('bg.report_title'))); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $docNo . ' · ' . ($p['project_code'] ?: '') . ' ' . $p['name'] . ' · ' . t('bg.internal') . ' · ' . date('d.m.Y')); $pdf->setLineWidth(0.6); $pdf->line($L, 30, $R, 30); $y = 36; };
    $footer = function () use ($pdf, $L, $R, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo . ' · ' . t('bg.internal')); $pdf->text($R, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 276) { $footer(); $header(); } };
    $h1 = function (string $s) use ($pdf, $navy, $gold, $L, $R, &$y, $need) { $y += 3; $need(20); $pdf->setColor($navy); $pdf->setFont('B', 10.5); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.4); $pdf->line($L, $y + 6.5, $R, $y + 6.5); $y += 10; };
    $table = function (string $title, array $cols, array $rows) use ($pdf, $navy, $ink, $line, $head, $L, $W, &$y, &$tableNo, $need, $header, $footer) {
        $cols = function_exists('rc_fit_cols') ? rc_fit_cols($cols, $W) : $cols; $tableNo++; $y += 2; $need(18);
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.2;
        $hrow = function () use ($pdf, $cols, $navy, $line, $head, $L, &$y) { $pdf->setFont('B', 7.2); $x = $L; $hh = 6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4, $lab, $al, $cw - 3); $x += $cw; } $y += $hh; };
        $hrow();
        foreach ($rows as $r) {
            $pdf->setFont(!empty($r['b']) ? 'B' : 'R', 7.6); $lines = []; $n = 1; foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($r['v'][$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
            $hh = $n * 3.8 + 1.6; if ($y + $hh > 276) { $footer(); $header(); $hrow(); $pdf->setFont('R', 7.6); }
            $x = $L; if (!empty($r['tone']) && function_exists('rc_bar_pdf')) rc_bar_pdf($pdf, $r['tone'], $x, $y, $hh);
            foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $s) $pdf->text($x + 1.5, $y + 3.5 + 3.8 * $k, $s, $al, $cw - 3); $x += $cw; }
            $y += $hh;
        }
        $y += 2;
    };
    $m = fn(float $v) => number_format($v, 0, ',', '.'); $sg = fn(float $v) => ($v >= 0 ? '+ ' : '− ') . number_format(abs($v), 0, ',', '.');
    $header(); $footer();
    $kv = [[t('pr.one'), trim(($p['project_code'] ?: '') . ' ' . $p['name'])], [t('v.one'), (string)($p['vessel'] ?: '—')], [t('rp.customer'), (string)($p['customer_name'] ?? '')], [t('bg.offer_value'), $m($P['offer_value']) . ' EUR'], [t('bg.result_to_date'), $sg($P['result']) . ' EUR'], [t('bg.forecast_result'), $sg($P['forecast_result']) . ' EUR (' . t('bg.budgeted') . ' ' . $sg($P['budget_result']) . ')']];
    foreach ($kv as [$k, $v]) $y += $pdf->kvCell($L, $y, $W, 47, $k, $v, ['labelW' => 44, 'fill' => $head, 'line' => $line, 'navy' => $navy, 'ink' => $ink]);   // R59-002: wraps
    $y += 4;
    $h1('1. ' . t('bg.sec_subs'));
    $rows = []; foreach ($P['subs'] as $C) $rows[] = ['tone' => $C['tone'], 'v' => [trim(($C['sub']['code'] ?: '') . ' ' . $C['sub']['name']), $C['progress'] . ' %', bg_h($C['actual_h']) . ' / ' . bg_h($C['budget_h']), $C['budget_h'] > 0 ? $C['hours_pct'] . ' %' : '—', $m($C['parts_committed']) . ' / ' . $m($C['parts_budget']), $C['parts_budget'] > 0 ? $C['parts_pct'] . ' %' : '—', $m($C['offer_value']), $sg($C['result']), $sg($C['forecast_result']), implode(', ', array_map('bg_flag_label', $C['flags']))]];
    $rows[] = ['b' => true, 'v' => [t('ob.c_total'), $P['progress'] . ' %', bg_h($P['actual_h']) . ' / ' . bg_h($P['budget_h']), $P['hours_pct'] . ' %', $m($P['parts_committed']) . ' / ' . $m($P['parts_budget']), $P['parts_pct'] . ' %', $m($P['offer_value']), $sg($P['result']), $sg($P['forecast_result']), '']];
    $table(t('bg.sec_subs'), [[t('sp.one'), 42, 'L'], [t('bg.s_prog'), 12, 'R'], [t('bg.s_hours'), 22, 'R'], ['%', 13, 'R'], [t('bg.s_parts'), 28, 'R'], ['%', 13, 'R'], [t('bg.s_offer'), 18, 'R'], [t('bg.s_result'), 18, 'R'], [t('bg.s_fcst'), 18, 'R'], [t('th.status'), 26, 'L']], $rows);
    $h1('2. ' . t('bg.sec_disc'));
    foreach ($P['subs'] as $C) { if (!$C['rows']) continue; $rows = []; foreach ($C['rows'] as $r) $rows[] = ['tone' => $r['tone'], 'v' => [$r['name'], bg_h($r['budget_h']), bg_h($r['actual_h']), bg_h($r['remaining_h']), $m($r['rate']), $m($r['budget_cost']), $m($r['actual_cost']), $sg($r['budget_cost'] - $r['actual_cost'])]];
        $table(trim(($C['sub']['code'] ?: '') . ' ' . $C['sub']['name']), [[t('bg.discipline'), 44, 'L'], [t('bg.budget_h'), 18, 'R'], [t('bg.actual_h'), 18, 'R'], [t('bg.remaining'), 18, 'R'], [t('bg.rate'), 16, 'R'], [t('bg.budget_eur'), 22, 'R'], [t('bg.actual_eur'), 22, 'R'], ['Δ', 22, 'R']], $rows); }
    $h1('3. ' . t('bg.sec_parts'));
    foreach ($P['subs'] as $C) { if (!$C['costs']) continue; $rows = []; foreach ($C['costs'] as $c) $rows[] = ['v' => [fmt_date($c['cost_date']), t('bg.k_' . $c['kind']), (string)($c['supplier'] ?? ''), (string)$c['description'], (string)($c['ref_no'] ?? ''), $m((float)$c['amount'])]];
        $rows[] = ['b' => true, 'v' => ['', '', '', t('ob.c_total') . ' — ' . t('bg.budgeted') . ' ' . $m($C['parts_budget']), '', $m($C['parts_committed'])]];
        $table(trim(($C['sub']['code'] ?: '') . ' ' . $C['sub']['name']), [[t('th.date'), 20, 'L'], [t('bg.kind'), 18, 'L'], [t('bg.supplier'), 34, 'L'], [t('ob.c_desc'), 62, 'L'], [t('bg.ref'), 24, 'L'], [t('bg.s_amount'), 22, 'R']], $rows); }
    $h1('4. ' . t('bg.sec_curve'));
    $rows = []; foreach ($curve as $pt) $rows[] = ['v' => [$pt['m'], $m($pt['budget']), $pt['actual'] === null ? '—' : $m($pt['actual']), $pt['actual'] === null ? '' : $sg($pt['budget'] - $pt['actual'])]];
    $table(t('bg.sec_curve'), [[t('bg.month'), 30, 'L'], [t('bg.budget_cum'), 40, 'R'], [t('bg.actual_cum'), 40, 'R'], ['Δ', 40, 'R']], $rows);
    $footer();
    return $pdf->bytes($docNo);
}

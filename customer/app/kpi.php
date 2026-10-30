<?php
/**
 * Project-management KPIs and the risk log.                           GAS-CP-R39-001
 *
 *   PCR   completed on time ÷ sub-projects active in the period          (project, period)
 *   PCRt  budgeted ÷ actual manhours                                      (sub-project / project)
 *   BAI-b budgeted cost (hours + parts) ÷ actual cost to date             BAI-o offer value ÷ actual cost to date
 *   RUIs  budgeted ÷ actual hours of service engineers (card A …)         RUIa  … of assistant engineers (card B)
 *   PSC   drawing-tracker revisions + re-submitted owner / class documents (lower is better)
 *   RMI   mitigated ÷ identified risks (risk log)
 *   ROI   result to date ÷ cost to date × 100 (%)
 *   CPI   earned hours ÷ actual hours          SPI  earned hours ÷ planned hours to date (work packages, else linear to the deadline)
 * Targets and tolerance per KPI in kpi_targets (Settings › KPIs); value ≥ target green, ≥ target − tolerance amber, else red;
 * PSC is lower-is-better (≤ target green, ≤ target + tolerance amber). Monthly snapshots on the first visit of a month; a KPI that
 * drops below its amber band between two snapshots notifies the project manager.
 */

const KPI_KEYS = ['pcr', 'pcrt', 'bai_b', 'bai_o', 'rui_s', 'rui_a', 'psc', 'rmi', 'roi', 'cpi', 'spi'];
const KPI_LOWER_BETTER = ['psc'];

function kpi_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'kpi_targets'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function kpi_can(): bool { return kpi_has() && is_staff() && (is_cp_admin() || fin_visible() || (function_exists('pl_can_edit') && pl_can_edit()) || (function_exists('bg_can') && bg_can())); }
function kpi_label(string $k): string { return t('kpi.' . $k); }
function kpi_targets(): array { static $c = null; if ($c === null) { $c = []; foreach (all('SELECT * FROM kpi_targets') as $r) $c[$r['kpi']] = $r; foreach (KPI_KEYS as $k) $c[$k] ??= ['kpi' => $k, 'target' => $k === 'psc' || $k === 'roi' ? 0 : 1, 'tolerance' => $k === 'psc' ? 1 : ($k === 'roi' ? 10 : 0.1), 'notify' => 1]; } return $c; }
function kpi_tone(string $k, ?float $v): ?string {
    if ($v === null) return null;
    $t = kpi_targets()[$k]; $target = (float)$t['target']; $tol = (float)$t['tolerance'];
    if (in_array($k, KPI_LOWER_BETTER, true)) return $v <= $target ? 'green' : ($v <= $target + $tol ? 'amber' : 'red');
    return $v >= $target ? 'green' : ($v >= $target - $tol ? 'amber' : 'red');
}
function kpi_fmt(string $k, ?float $v): string {
    if ($v === null) return '—';
    if ($k === 'roi') return ($v >= 0 ? '+' : '') . number_format($v, 1, ',', '.') . ' %';
    if ($k === 'psc') return (string)(int) round($v);
    return number_format($v, 2, ',', '.');
}
function kpi_pill(string $k, ?float $v): string { $tone = kpi_tone($k, $v); return '<b class="kpi-v kpi-' . ($tone ?: 'na') . '">' . e(kpi_fmt($k, $v)) . '</b>'; }
/** The rate card a person's hours sell under: the account's card, else the discipline's. */
function kpi_user_card(int $uid, ?int $disciplineId): string {
    static $c = [];
    if (!isset($c[$uid])) { $card = (string) scalar("SELECT rate_card FROM customer_users WHERE id = ?", [$uid]); $c[$uid] = trim($card) !== '' ? trim($card) : (function_exists('acc_card_of') ? acc_card_of($disciplineId) : 'A'); }
    return $c[$uid];
}
function kpi_card_kind(string $card): ?string { return $card === 'A' || $card === 'C' || $card === 'D' || $card === 'E' || $card === 'I' || $card === 'L' ? 's' : ($card === 'B' || $card === 'M' ? 'a' : null); }

/* ------------------------------------------------------------------ per sub-project */

/** All KPIs of one sub-project (to date). */
function kpi_sub(int $sid, ?array $sp = null): array {
    static $cache = [];
    if (isset($cache[$sid])) return $cache[$sid];
    $sp = $sp ?? load_subproject($sid); if (!$sp) return [];
    $C = function_exists('bg_calc') && bg_has() ? bg_calc($sid, $sp) : null;
    $K = ['sid' => $sid, 'sub' => $sp, 'v' => array_fill_keys(KPI_KEYS, null), 'detail' => []];
    if ($C) {
        $K['v']['pcrt'] = $C['actual_h'] > 0 ? $C['budget_h'] / $C['actual_h'] : null;
        $budgetCost = $C['budget_cost'] + $C['parts_budget'];
        $K['v']['bai_b'] = $C['cost_to_date'] > 0 ? $budgetCost / $C['cost_to_date'] : null;
        $K['v']['bai_o'] = $C['cost_to_date'] > 0 && $C['offer_value'] > 0 ? $C['offer_value'] / $C['cost_to_date'] : null;
        $K['v']['roi'] = $C['cost_to_date'] > 0 ? $C['result'] / $C['cost_to_date'] * 100 : null;
        $K['v']['cpi'] = $C['actual_h'] > 0 ? $C['earned_h'] / $C['actual_h'] : null;
        // planned hours to date: work packages ended by today, else linear between the start and the deadline
        $planned = null;
        if (function_exists('wp_has') && wp_has()) { $w = one("SELECT SUM(CASE WHEN end_date <= CURDATE() THEN planned_hours ELSE 0 END) done, SUM(planned_hours) total FROM sp_works WHERE subproject_id = ? AND status <> 'cancelled'", [$sid]); if ($w && (float)$w['total'] > 0 && (float)$w['done'] > 0) $planned = (float)$w['done'] * ($C['budget_h'] > 0 ? $C['budget_h'] / (float)$w['total'] : 1); }
        if ($planned === null && $C['budget_h'] > 0 && !empty($sp['deadline'])) {
            $start = scalar('SELECT MIN(d) FROM (SELECT MIN(snap_date) d FROM sp_progress_snapshots WHERE subproject_id = ? UNION SELECT MIN(l.work_date) FROM timesheet_lines l WHERE l.subproject_id = ? UNION SELECT DATE(p.created_at) FROM projects p WHERE p.id = ?) x', [$sid, $sid, (int)$sp['project_id']]);
            if ($start) { $s = strtotime((string)$start); $d = strtotime((string)$sp['deadline']); $frac = $d > $s ? max(0.0, min(1.0, (time() - $s) / ($d - $s))) : 1.0; $planned = $C['budget_h'] * $frac; }
        }
        $K['v']['spi'] = $planned !== null && $planned > 0 ? $C['earned_h'] / $planned : null;
        $K['detail']['planned_h'] = $planned; $K['detail']['earned_h'] = $C['earned_h']; $K['detail']['budget_h'] = $C['budget_h']; $K['detail']['actual_h'] = $C['actual_h']; $K['detail']['result'] = $C['result']; $K['detail']['cost'] = $C['cost_to_date']; $K['detail']['offer_value'] = $C['offer_value'];
        // RUI: budget per card kind vs actual per card kind
        $bud = ['s' => 0.0, 'a' => 0.0]; foreach ($C['rows'] as $did => $r) { $kind = kpi_card_kind(function_exists('acc_card_of') ? acc_card_of((int)$did ?: null) : 'A'); if ($kind) $bud[$kind] += (float)$r['budget_h']; }
        $act = ['s' => 0.0, 'a' => 0.0];
        foreach (all("SELECT t.user_id, t.discipline_id, SUM(l.hours) h FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.subproject_id = ? AND t.status IN ('approved','invoiced') GROUP BY t.user_id, t.discipline_id", [$sid]) as $r) { $kind = kpi_card_kind(kpi_user_card((int)$r['user_id'], $r['discipline_id'] !== null ? (int)$r['discipline_id'] : null)); if ($kind) $act[$kind] += (float)$r['h']; }
        $K['v']['rui_s'] = $act['s'] > 0 ? $bud['s'] / $act['s'] : null; $K['v']['rui_a'] = $act['a'] > 0 ? $bud['a'] / $act['a'] : null;
        $K['detail']['rui'] = ['bud' => $bud, 'act' => $act];
    }
    // PSC: re-submissions of this sub-project's documents (drawing revisions are counted at project level)
    $re = (int) scalar("SELECT (SELECT COUNT(*) FROM owner_approvals o WHERE o.subproject_id = ? AND o.status = 'resubmitted') + (SELECT COUNT(*) FROM class_submittals c WHERE c.subproject_id = ? AND c.status = 'resubmitted')", [$sid, $sid]);
    $K['v']['psc'] = (float)$re; $K['detail']['resubmitted'] = $re;
    // RMI
    $rk = kpi_has() ? one("SELECT COUNT(*) n, SUM(status IN ('mitigated','closed')) m FROM sp_risks WHERE subproject_id = ?", [$sid]) : null;
    $K['v']['rmi'] = $rk && (int)$rk['n'] > 0 ? (int)$rk['m'] / (int)$rk['n'] : null; $K['detail']['risks'] = ['n' => (int)($rk['n'] ?? 0), 'm' => (int)($rk['m'] ?? 0)];
    // PCR at sub-project level: on time? (completed)
    $K['v']['pcr'] = $sp['status'] === 'completed' && !empty($sp['completed_at']) ? (empty($sp['deadline']) || $sp['completed_at'] <= $sp['deadline'] ? 1.0 : 0.0) : null;
    foreach (KPI_KEYS as $k) $K['tone'][$k] = kpi_tone($k, $K['v'][$k]);
    return $cache[$sid] = $K;
}

/* ------------------------------------------------------------------ per project (period) */

/** KPIs of a project: the sub-project KPIs rolled up (hour-weighted where it makes sense), PCR and PSC for the period. */
function kpi_project(int $pid, string $from, string $to): array {
    $p = one('SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ?', [$pid]); if (!$p) return [];
    $subs = all('SELECT * FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
    $P = ['pid' => $pid, 'p' => $p, 'subs' => [], 'v' => array_fill_keys(KPI_KEYS, null), 'detail' => []];
    $sum = ['budget_h' => 0.0, 'actual_h' => 0.0, 'earned_h' => 0.0, 'planned_h' => 0.0, 'cost' => 0.0, 'budget_cost' => 0.0, 'offer' => 0.0, 'result' => 0.0, 'rui_bs' => 0.0, 'rui_as' => 0.0, 'rui_ba' => 0.0, 'rui_aa' => 0.0, 'has_planned' => false];
    foreach ($subs as $sp) {
        $K = kpi_sub((int)$sp['id'], $sp); $P['subs'][] = $K; $d = $K['detail'];
        $sum['budget_h'] += (float)($d['budget_h'] ?? 0); $sum['actual_h'] += (float)($d['actual_h'] ?? 0); $sum['earned_h'] += (float)($d['earned_h'] ?? 0); if (isset($d['planned_h'])) { $sum['planned_h'] += (float)$d['planned_h']; $sum['has_planned'] = true; }
        $sum['cost'] += (float)($d['cost'] ?? 0); $sum['offer'] += (float)($d['offer_value'] ?? 0); $sum['result'] += (float)($d['result'] ?? 0);
        if (isset($d['rui'])) { $sum['rui_bs'] += $d['rui']['bud']['s']; $sum['rui_as'] += $d['rui']['act']['s']; $sum['rui_ba'] += $d['rui']['bud']['a']; $sum['rui_aa'] += $d['rui']['act']['a']; }
        if (function_exists('bg_calc') && bg_has()) { $C = bg_calc((int)$sp['id'], $sp); $sum['budget_cost'] += $C['budget_cost'] + $C['parts_budget']; }
    }
    $P['v']['pcrt'] = $sum['actual_h'] > 0 ? $sum['budget_h'] / $sum['actual_h'] : null;
    $P['v']['bai_b'] = $sum['cost'] > 0 ? $sum['budget_cost'] / $sum['cost'] : null;
    $P['v']['bai_o'] = $sum['cost'] > 0 && $sum['offer'] > 0 ? $sum['offer'] / $sum['cost'] : null;
    $P['v']['roi'] = $sum['cost'] > 0 ? $sum['result'] / $sum['cost'] * 100 : null;
    $P['v']['cpi'] = $sum['actual_h'] > 0 ? $sum['earned_h'] / $sum['actual_h'] : null;
    $P['v']['spi'] = $sum['has_planned'] && $sum['planned_h'] > 0 ? $sum['earned_h'] / $sum['planned_h'] : null;
    $P['v']['rui_s'] = $sum['rui_as'] > 0 ? $sum['rui_bs'] / $sum['rui_as'] : null; $P['v']['rui_a'] = $sum['rui_aa'] > 0 ? $sum['rui_ba'] / $sum['rui_aa'] : null;
    // PCR for the period: sub-projects completed in the period on time ÷ sub-projects that were active in the period
    // due in the period = completed in the period, or still open with a deadline inside / before the period end (late)
    $active = 0; $done = 0; $onTime = 0;
    foreach ($subs as $sp) {
        $completedIn = $sp['status'] === 'completed' && !empty($sp['completed_at']) && $sp['completed_at'] >= $from && $sp['completed_at'] <= $to;
        $lateOpen = $sp['status'] !== 'completed' && !empty($sp['deadline']) && $sp['deadline'] <= $to;
        if ($completedIn || $lateOpen) $active++;
        if ($completedIn) { $done++; if (empty($sp['deadline']) || $sp['completed_at'] <= $sp['deadline']) $onTime++; }
    }
    $P['v']['pcr'] = $active > 0 ? $onTime / $active : null; $P['detail']['pcr'] = ['active' => $active, 'done' => $done, 'on_time' => $onTime];
    // PSC for the period: drawing-tracker revisions issued in the period (R0 excluded) + re-submissions
    $rev = (int) scalar('SELECT COUNT(*) FROM drawing_tracker_revisions WHERE project_id = ? AND issued_at BETWEEN ? AND ? AND rev_no <> ?', [$pid, $from, $to, 'R0']);
    $re = array_sum(array_map(fn($K) => (int)($K['detail']['resubmitted'] ?? 0), $P['subs']));
    $P['v']['psc'] = (float)($rev + $re); $P['detail']['psc'] = ['revisions' => $rev, 'resubmitted' => $re];
    // RMI: whole project (project-level risks + sub-project risks)
    $rk = kpi_has() ? one("SELECT COUNT(*) n, SUM(status IN ('mitigated','closed')) m FROM sp_risks WHERE project_id = ?", [$pid]) : null;
    $P['v']['rmi'] = $rk && (int)$rk['n'] > 0 ? (int)$rk['m'] / (int)$rk['n'] : null; $P['detail']['risks'] = ['n' => (int)($rk['n'] ?? 0), 'm' => (int)($rk['m'] ?? 0)];
    $P['detail'] += $sum;
    foreach (KPI_KEYS as $k) $P['tone'][$k] = kpi_tone($k, $P['v'][$k]);
    $P['worst'] = 'green'; foreach ($P['tone'] as $t) if ($t === 'red') { $P['worst'] = 'red'; break; } elseif ($t === 'amber') $P['worst'] = 'amber';
    return $P;
}
/** All projects in scope for the KPI page. */
function kpi_projects(string $from, string $to, array $f = []): array {
    [$ps, $pa] = scope_sql('p'); $w = [$ps]; $a = $pa;
    $w[] = "p.project_code NOT LIKE 'GEN-%'";
    if (!empty($f['status'])) { $w[] = 'p.status = ?'; $a[] = $f['status']; } else $w[] = "p.status <> 'completed' OR EXISTS (SELECT 1 FROM subprojects s WHERE s.project_id = p.id AND s.completed_at BETWEEN ? AND ?)";
    if (empty($f['status'])) { $a[] = $from; $a[] = $to; }
    if (!empty($f['pm'])) { $w[] = 'p.pm_user_id = ?'; $a[] = (int)$f['pm']; }
    if (!empty($f['vessel'])) { $w[] = 'p.vessel_id = ?'; $a[] = (int)$f['vessel']; }
    $out = [];
    foreach (all('SELECT p.id FROM projects p WHERE (' . implode(') AND (', $w) . ') ORDER BY p.project_code, p.name', $a) as $r) { $P = kpi_project((int)$r['id'], $from, $to); if ($P) $out[] = $P; }
    return $out;
}
function kpi_period(string $sel): array {
    $y = (int)date('Y'); $m = (int)date('m');
    return match ($sel) {
        'last' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'q' => [sprintf('%04d-%02d-01', $y, (int)(floor(($m - 1) / 3) * 3 + 1)), date('Y-m-d')],
        'ytd' => [$y . '-01-01', date('Y-m-d')],
        '12' => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')],
        default => [date('Y-m-01'), date('Y-m-d')],
    };
}

/* ------------------------------------------------------------------ snapshots, trend, notifications */

/** Store this month's snapshot once (called when the KPI page or the dashboard opens); notifies on a drop below the amber band. */
function kpi_snapshot_monthly(): void {
    if (!kpi_has()) return;
    $d = date('Y-m-01');
    if (scalar('SELECT COUNT(*) FROM kpi_snapshots WHERE snap_date = ?', [$d])) return;
    [$from, $to] = [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))];
    $prev = date('Y-m-01', strtotime('first day of last month'));
    foreach (all("SELECT id, pm_user_id, project_code, name FROM projects WHERE status <> 'completed' AND project_code NOT LIKE 'GEN-%'") as $pr) {
        $P = kpi_project((int)$pr['id'], $from, $to); if (!$P) continue;
        $drops = [];
        foreach (KPI_KEYS as $k) {
            q('INSERT IGNORE INTO kpi_snapshots (snap_date, project_id, subproject_id, kpi, value, tone) VALUES (?,?,NULL,?,?,?)', [$d, (int)$pr['id'], $k, $P['v'][$k], $P['tone'][$k]]);
            $old = one('SELECT tone FROM kpi_snapshots WHERE snap_date = ? AND project_id = ? AND subproject_id IS NULL AND kpi = ?', [$prev, (int)$pr['id'], $k]);
            if ($P['tone'][$k] === 'red' && $old && $old['tone'] !== 'red' && (int)(kpi_targets()[$k]['notify'] ?? 1)) $drops[] = kpi_label($k) . ' ' . kpi_fmt($k, $P['v'][$k]);
        }
        foreach ($P['subs'] as $K) foreach (KPI_KEYS as $k) q('INSERT IGNORE INTO kpi_snapshots (snap_date, project_id, subproject_id, kpi, value, tone) VALUES (?,?,?,?,?,?)', [$d, (int)$pr['id'], (int)$K['sid'], $k, $K['v'][$k], $K['tone'][$k]]);
        if ($drops && function_exists('nf_push')) { $to = array_values(array_filter([(int)$pr['pm_user_id']])); if ($to) nf_push($to, 'kpi:red', 'kpi:' . (int)$pr['id'] . ':' . $d, trim(($pr['project_code'] ?: '') . ' ' . $pr['name']), implode(' · ', $drops), url('kpis', ['project' => (int)$pr['id']]), null, null); }
    }
}
/** Trend of one KPI of a project (last 12 snapshots). */
function kpi_trend(int $pid, string $k, ?int $sid = null): array {
    if (!kpi_has()) return [];
    return all('SELECT snap_date, value, tone FROM kpi_snapshots WHERE project_id = ? AND kpi = ? AND subproject_id ' . ($sid ? '= ?' : 'IS NULL') . ' ORDER BY snap_date DESC LIMIT 12', $sid ? [$pid, $k, $sid] : [$pid, $k]);
}
/** Inline sparkline SVG of a trend (oldest → newest). */
function kpi_spark(array $trend, string $k): string {
    $pts = array_reverse(array_values(array_filter($trend, fn($r) => $r['value'] !== null)));
    if (count($pts) < 2) return '';
    $vals = array_map(fn($r) => (float)$r['value'], $pts); $min = min($vals); $max = max($vals); if ($max - $min < 1e-6) { $max += 0.5; $min -= 0.5; }
    $w = 80; $h = 22; $n = count($vals); $xy = [];
    foreach ($vals as $i => $v) $xy[] = round($i * ($w - 4) / ($n - 1) + 2, 1) . ',' . round($h - 2 - ($v - $min) / ($max - $min) * ($h - 4), 1);
    $tone = end($pts)['tone'] ?: 'grey'; $col = ['green' => '#2F7D5C', 'amber' => '#C89B3C', 'red' => '#9B2C2C'][$tone] ?? '#8A94A6';
    return '<svg class="kpi-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><polyline fill="none" stroke="' . $col . '" stroke-width="1.6" points="' . implode(' ', $xy) . '"/></svg>';
}
/** Worst projects for the dashboard cockpit (red first, then amber), by the number of red KPIs. */
function kpi_cockpit(int $limit = 5): array {
    if (!kpi_can()) return [];
    [$from, $to] = kpi_period('m'); $rows = [];
    foreach (kpi_projects($from, $to) as $P) { $red = count(array_filter($P['tone'], fn($t) => $t === 'red')); $amber = count(array_filter($P['tone'], fn($t) => $t === 'amber')); if ($red || $amber) $rows[] = ['P' => $P, 'red' => $red, 'amber' => $amber, 'reds' => array_keys(array_filter($P['tone'], fn($t) => $t === 'red'))]; }
    usort($rows, fn($a, $b) => [$b['red'], $b['amber']] <=> [$a['red'], $a['amber']]);
    return array_slice($rows, 0, $limit);
}
function kpi_targets_save_from_post(): void {
    require_cp_admin(); if (!kpi_has()) return;
    foreach (KPI_KEYS as $k) {
        $t = (float) str_replace(',', '.', (string)($_POST['target'][$k] ?? '1')); $tol = max(0.0, (float) str_replace(',', '.', (string)($_POST['tolerance'][$k] ?? '0.1')));
        q('INSERT INTO kpi_targets (kpi, target, tolerance, notify) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE target = VALUES(target), tolerance = VALUES(tolerance), notify = VALUES(notify)', [$k, $t, $tol, (int)!empty($_POST['notify'][$k])]);
    }
}

/* ------------------------------------------------------------------ risk log */

const RK_CATS = ['technical', 'schedule', 'cost', 'safety', 'class', 'supply', 'access', 'other'];
const RK_STATUSES = ['open', 'mitigated', 'accepted', 'occurred', 'closed'];
function rk_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'sp_risks'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function rk_list(int $pid, ?int $sid = null): array {
    if (!rk_has()) return [];
    return all('SELECT r.*, u.full_name AS owner_name, s.code AS sub_code, s.name AS sub_name FROM sp_risks r LEFT JOIN customer_users u ON u.id = r.owner_user_id LEFT JOIN subprojects s ON s.id = r.subproject_id WHERE r.project_id = ?' . ($sid ? ' AND r.subproject_id = ?' : '') . " ORDER BY FIELD(r.status,'open','occurred','accepted','mitigated','closed'), (r.likelihood * r.impact) DESC, r.id", $sid ? [$pid, $sid] : [$pid]);
}
function rk_score_pill(int $l, int $i): string { $s = $l * $i; return pill($s >= 15 ? 'bad' : ($s >= 8 ? 'gold' : 'green'), (string)$s); }
function rk_status_pill(string $s): string { return pill(['open' => 'gold', 'mitigated' => 'green', 'accepted' => 'navy', 'occurred' => 'bad', 'closed' => 'mute'][$s] ?? 'mute', t('rk.st_' . $s)); }
function rk_next_no(int $pid): string { $n = (int) scalar("SELECT MAX(CAST(SUBSTRING(risk_no, 4) AS UNSIGNED)) FROM sp_risks WHERE project_id = ?", [$pid]) + 1; return 'RK-' . sprintf('%03d', $n); }
function rk_save_from_post(): int {
    require_staff(); if (!rk_has()) throw new RuntimeException(t('rk.need_db'));
    $u = current_user(); $id = (int)($_POST['risk_id'] ?? 0); $pid = (int)($_POST['project_id'] ?? 0); $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if ($sid) { $sp = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) throw new RuntimeException(t('err.notfound')); $pid = (int)$sp['project_id']; }
    if (!$pid || !scalar('SELECT id FROM projects WHERE id = ?', [$pid])) throw new RuntimeException(t('err.notfound'));
    $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $title = $str('title', 200); if (!$title) throw new RuntimeException(t('rk.need_title'));
    $cat = in_array($_POST['category'] ?? '', RK_CATS, true) ? $_POST['category'] : 'other';
    $l = max(1, min(5, (int)($_POST['likelihood'] ?? 3))); $i = max(1, min(5, (int)($_POST['impact'] ?? 3)));
    $st = in_array($_POST['status'] ?? '', RK_STATUSES, true) ? $_POST['status'] : 'open';
    $owner = (int)($_POST['owner_user_id'] ?? 0) ?: null; $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null;
    $ident = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['identified_at'] ?? '')) ? $_POST['identified_at'] : date('Y-m-d');
    $mit = mb_substr(trim((string)($_POST['mitigation'] ?? '')), 0, 4000) ?: null; $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 4000) ?: null;
    if ($id) {
        $old = one('SELECT * FROM sp_risks WHERE id = ? AND project_id = ?', [$id, $pid]); if (!$old) throw new RuntimeException(t('err.notfound'));
        q('UPDATE sp_risks SET subproject_id = ?, title = ?, description = ?, category = ?, likelihood = ?, impact = ?, status = ?, owner_user_id = ?, due_date = ?, mitigation = ?, identified_at = ?, mitigated_at = ? WHERE id = ?',
          [$sid, $title, $desc, $cat, $l, $i, $st, $owner, $due, $mit, $ident, in_array($st, ['mitigated', 'closed'], true) ? ($old['mitigated_at'] ?: date('Y-m-d')) : null, $id]);
    } else {
        q('INSERT INTO sp_risks (project_id, subproject_id, risk_no, title, description, category, likelihood, impact, status, owner_user_id, due_date, mitigation, identified_at, mitigated_at, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$pid, $sid, rk_next_no($pid), $title, $desc, $cat, $l, $i, $st, $owner, $due, $mit, $ident, in_array($st, ['mitigated', 'closed'], true) ? date('Y-m-d') : null, (int)$u['id'], (string)$u['full_name']]);
        $id = (int) db()->lastInsertId();
    }
    return $id;
}
function rk_delete(int $id): void { require_staff(); if (rk_has()) q('DELETE FROM sp_risks WHERE id = ?', [$id]); }
/** Seed the log from issued inspection reports: every item with a risk level becomes a risk once (source inspection). Returns how many were added. */
function rk_seed_from_inspections(int $pid): int {
    if (!rk_has() || !function_exists('isr_has') || !isr_has()) return 0;
    $u = current_user(); $n = 0;
    foreach (all("SELECT i.*, r.report_date, r.subproject_id, r.report_no FROM isr_items i JOIN isr_reports r ON r.id = i.report_id WHERE r.project_id = ? AND r.status IN ('issued','checking') AND i.risk_level IN ('low','medium','high','critical') AND NOT EXISTS (SELECT 1 FROM sp_risks k WHERE k.isr_item_id = i.id) ORDER BY r.report_date, i.seq", [$pid]) as $it) {
        $li = ['low' => [2, 2], 'medium' => [3, 3], 'high' => [4, 4], 'critical' => [5, 5]][$it['risk_level']];
        q('INSERT INTO sp_risks (project_id, subproject_id, risk_no, title, description, category, likelihood, impact, status, mitigation, source, isr_item_id, identified_at, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$pid, $it['subproject_id'] ?: null, rk_next_no($pid), mb_substr(trim(($it['system_name'] ?: '') . ' — ' . mb_substr((string)($it['risk_analysis'] ?: $it['condition_found'] ?: 'Inspection item'), 0, 120), ' —'), 0, 200), trim((string)$it['condition_found'] . ($it['class_regs'] ? "\n" . $it['class_regs'] : '')) ?: null, 'technical', $li[0], $li[1], 'open', $it['actions'] ?: null, 'inspection', (int)$it['id'], (string)$it['report_date'], (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
        $n++;
    }
    return $n;
}

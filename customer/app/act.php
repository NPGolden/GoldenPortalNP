<?php
/** Dashboard activity — the fleet we work with, new vessels, and the most active colleagues (year / last month, top 10).   GAS-CP-R62-005 */
declare(strict_types=1);

function act_has(string $table): bool { static $c = []; if (!isset($c[$table])) { try { $c[$table] = (bool) scalar('SHOW TABLES LIKE ?', [$table]); } catch (Throwable $e) { $c[$table] = false; } } return $c[$table]; }
function act_col(string $table, string $col): bool { static $c = []; $k = "$table.$col"; if (!isset($c[$k])) { try { $c[$k] = act_has($table) && (bool) scalar("SHOW COLUMNS FROM `$table` LIKE ?", [$col]); } catch (Throwable $e) { $c[$k] = false; } } return $c[$k]; }

/** Vessel ids touched between two dates — through projects, tickets, timesheet lines (sub-project → project → vessel), planning, offers, production orders, service reports, accounting reports. */
function act_fleet(string $from, string $to): array {
    $ids = []; $add = function (string $sql, array $b = []) use (&$ids, $from, $to) { try { foreach (all($sql, array_merge([$from, $to . ' 23:59:59'], $b)) as $r) if ((int)$r['v']) $ids[(int)$r['v']] = true; } catch (Throwable $e) {} };
    $add('SELECT DISTINCT vessel_id v FROM projects WHERE vessel_id IS NOT NULL AND (created_at BETWEEN ? AND ? OR status = "active")');
    $add('SELECT DISTINCT vessel_id v FROM tickets WHERE vessel_id IS NOT NULL AND created_at BETWEEN ? AND ?');
    if (act_has('timesheet_lines')) $add('SELECT DISTINCT p.vessel_id v FROM timesheet_lines l JOIN subprojects s ON s.id = l.subproject_id JOIN projects p ON p.id = s.project_id WHERE p.vessel_id IS NOT NULL AND l.work_date BETWEEN ? AND ?');
    if (act_col('plan_entries', 'vessel_id')) $add('SELECT DISTINCT vessel_id v FROM plan_entries WHERE vessel_id IS NOT NULL AND plan_date BETWEEN ? AND ?');
    if (act_col('offers', 'vessel_id')) $add('SELECT DISTINCT vessel_id v FROM offers WHERE vessel_id IS NOT NULL AND created_at BETWEEN ? AND ?');
    if (act_col('production_orders', 'vessel_id')) $add('SELECT DISTINCT vessel_id v FROM production_orders WHERE vessel_id IS NOT NULL AND created_at BETWEEN ? AND ?');
    if (act_has('fsr_reports')) $add('SELECT DISTINCT p.vessel_id v FROM fsr_reports f JOIN projects p ON p.id = f.project_id WHERE p.vessel_id IS NOT NULL AND f.report_date BETWEEN ? AND ?');
    if (act_col('acc_reports', 'vessel_id')) $add('SELECT DISTINCT vessel_id v FROM acc_reports WHERE vessel_id IS NOT NULL AND created_at BETWEEN ? AND ?');
    return array_keys($ids);
}
/** Vessels first seen this year: created this year, or whose first project / ticket is this year. */
function act_new_vessels(int $year): array {
    $y0 = "$year-01-01"; $y1 = "$year-12-31 23:59:59";
    $first = []; foreach (all('SELECT id, created_at FROM vessels') as $v) $first[(int)$v['id']] = (string)$v['created_at'];
    foreach (all('SELECT vessel_id, MIN(created_at) m FROM projects WHERE vessel_id IS NOT NULL GROUP BY vessel_id') as $r) { $i = (int)$r['vessel_id']; if (isset($first[$i]) && (string)$r['m'] < $first[$i]) $first[$i] = (string)$r['m']; }
    foreach (all('SELECT vessel_id, MIN(created_at) m FROM tickets WHERE vessel_id IS NOT NULL GROUP BY vessel_id') as $r) { $i = (int)$r['vessel_id']; if (isset($first[$i]) && (string)$r['m'] < $first[$i]) $first[$i] = (string)$r['m']; }
    $fleet = array_flip(act_fleet($y0, substr($y1, 0, 10)));
    return array_values(array_filter(array_keys($first), fn($i) => $first[$i] >= $y0 && $first[$i] <= $y1 && isset($fleet[$i])));
}
/** Activity sources: [label key, sql returning (uid, n) between ? and ?]. Each record counts 1 — bulk sources (planning, equipment) count the days worked on them, so an import does not swamp the ranking; timesheet hours are shown separately. */
function act_sources(): array {
    $s = [
        ['act.tickets',  'SELECT created_by uid, COUNT(*) n FROM tickets WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.comments', 'SELECT user_id uid, COUNT(*) n FROM ticket_comments WHERE created_at BETWEEN ? AND ? GROUP BY user_id'],
        ['act.timesheets', 'SELECT t.user_id uid, COUNT(DISTINCT l.work_date) n FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_date BETWEEN ? AND ? GROUP BY t.user_id'],
        ['act.reports',  'SELECT created_by uid, COUNT(*) n FROM fsr_reports WHERE created_by IS NOT NULL AND report_date BETWEEN ? AND ? GROUP BY created_by'],
        ['act.reports',  'SELECT created_by uid, COUNT(*) n FROM isr_reports WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.offers',   'SELECT created_by uid, COUNT(*) n FROM offers WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.production', 'SELECT created_by uid, COUNT(*) n FROM production_orders WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.planning', 'SELECT created_by uid, COUNT(DISTINCT DATE(created_at)) n FROM plan_entries WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.tasks',    'SELECT by_id uid, COUNT(*) n FROM task_log WHERE by_id IS NOT NULL AND at BETWEEN ? AND ? GROUP BY by_id'],
        ['act.kb',       'SELECT author_id uid, COUNT(*) n FROM kb_answers WHERE created_at BETWEEN ? AND ? GROUP BY author_id'],
        ['act.kb',       'SELECT author_id uid, COUNT(*) n FROM kb_questions WHERE created_at BETWEEN ? AND ? GROUP BY author_id'],
        ['act.requests', 'SELECT user_id uid, COUNT(*) n FROM cp_requests WHERE created_at BETWEEN ? AND ? GROUP BY user_id'],
        ['act.requests', 'SELECT user_id uid, COUNT(*) n FROM cp_request_comments WHERE created_at BETWEEN ? AND ? GROUP BY user_id'],
        ['act.projects', 'SELECT author_id uid, COUNT(*) n FROM sp_comments WHERE created_at BETWEEN ? AND ? GROUP BY author_id'],
        ['act.projects', 'SELECT created_by uid, COUNT(*) n FROM sp_issues WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.projects', 'SELECT created_by uid, COUNT(*) n FROM eng_deliverables WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.projects', 'SELECT author_id uid, COUNT(*) n FROM weekly_notes WHERE updated_at BETWEEN ? AND ? GROUP BY author_id'],
        ['act.equipment', 'SELECT created_by uid, COUNT(DISTINCT DATE(created_at)) n FROM equipment WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.equipment', 'SELECT created_by uid, COUNT(DISTINCT DATE(created_at)) n FROM equipment_history WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
        ['act.contacts', 'SELECT author_id uid, COUNT(*) n FROM contact_notes WHERE created_at BETWEEN ? AND ? GROUP BY author_id'],
        ['act.accounting', 'SELECT created_by uid, COUNT(*) n FROM acc_reports WHERE created_by IS NOT NULL AND created_at BETWEEN ? AND ? GROUP BY created_by'],
    ];
    return $s;
}
/** Top $n Golden A/S accounts by activity between two dates: [['uid','name','score','parts' => [key => n],'hours'], …]. */
function act_top(string $from, string $to, int $n = 10): array {
    static $cache = []; $ck = "$from|$to|$n"; if (isset($cache[$ck])) return $cache[$ck];
    $staff = []; $skip = function_exists('hr_owner_ids') ? hr_owner_ids() : []; foreach (all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1") as $u) if (!in_array((int)$u['id'], $skip, true)) $staff[(int)$u['id']] = $u['full_name'];   // R64-001: the Owner is not ranked
    $score = []; $parts = []; $b = [$from, $to . ' 23:59:59'];
    foreach (act_sources() as [$key, $sql]) { try { foreach (all($sql, $b) as $r) { $uid = (int)$r['uid']; if (!isset($staff[$uid])) continue; $score[$uid] = ($score[$uid] ?? 0) + (int)$r['n']; $parts[$uid][$key] = ($parts[$uid][$key] ?? 0) + (int)$r['n']; } } catch (Throwable $e) {} }
    $hours = []; try { foreach (all('SELECT t.user_id uid, SUM(l.hours) h FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id WHERE l.work_date BETWEEN ? AND ? GROUP BY t.user_id', [$from, $to]) as $r) $hours[(int)$r['uid']] = (float)$r['h']; } catch (Throwable $e) {}
    arsort($score); $out = [];
    foreach (array_slice($score, 0, $n, true) as $uid => $sc) { $p = $parts[$uid] ?? []; arsort($p); $out[] = ['uid' => $uid, 'name' => $staff[$uid], 'score' => $sc, 'parts' => $p, 'hours' => $hours[$uid] ?? 0.0]; }
    return $cache[$ck] = $out;
}
/** The dashboard block: fleet KPIs + two top-10 lists. */
function act_card(): string { return function_exists('cache_remember') ? cache_remember('act_card:' . locale(), 600, 'act_card_compute') : act_card_compute(); }   // R69-002
function act_card_compute(): string {
    $y = (int) date('Y'); $yFrom = "$y-01-01"; $today = date('Y-m-d'); $mFrom = date('Y-m-d', strtotime('-30 days'));
    $fleetY = act_fleet($yFrom, $today); $fleetM = act_fleet($mFrom, $today); $new = act_new_vessels($y); $total = (int) scalar('SELECT COUNT(*) FROM vessels WHERE is_active = 1');
    $topY = act_top($yFrom, $today, 10); $topM = act_top($mFrom, $today, 10);
    $fh = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
    $list = function (array $rows) use ($fh): string {
        if (!$rows) return '<div class="empty">—</div>'; $max = max(1, (int)$rows[0]['score']); $h = '<ol class="act-list" style="margin:0;padding-left:0;list-style:none;display:grid;gap:4px;">';
        foreach ($rows as $i => $r) { $tip = implode(' · ', array_map(fn($k, $v) => t($k) . ' ' . $v, array_keys($r['parts']), $r['parts'])); $medal = ['🥇', '🥈', '🥉'][$i] ?? '<span class="hint" style="display:inline-block;width:1.4em;text-align:center;">' . ($i + 1) . '</span>';
            $h .= '<li title="' . e($tip) . '" style="display:grid;grid-template-columns:1.6em 1fr auto;gap:6px;align-items:center;">' . $medal . '<span>' . avatar_html((int)$r['uid'], (string)$r['name'], 18, 'ava--inline') . ' ' . e($r['name']) . '<div style="height:4px;background:#C9A227;border-radius:2px;width:' . (int) round($r['score'] / $max * 100) . '%;margin-top:2px;opacity:.7;"></div></span><span class="num"><b>' . (int)$r['score'] . '</b>' . ($r['hours'] > 0 ? ' <span class="hint">· ' . $fh($r['hours']) . ' h</span>' : '') . '</span></li>'; }
        return $h . '</ol>';
    };
    $h = '<div class="card" id="activity"><div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">🚢 ' . tt('act.title') . '</h2><a class="btn btn--ghost btn--sm" href="' . url('vessels') . '">' . tt('nav.vessels') . ' ›</a></div>';
    $h .= '<div class="kpi kpi--4" style="margin-bottom:12px;"><a class="stat navy" href="' . url('vessels') . '"><b>' . count($fleetY) . '</b><span class="s">' . e(t('act.fleet_year', ['y' => $y])) . '</span></a><a class="stat" href="' . url('vessels') . '"><b>' . count($fleetM) . '</b><span class="s">' . tt('act.fleet_month') . '</span></a><a class="stat green" href="' . url('vessels') . '"><b>' . count($new) . '</b><span class="s">' . e(t('act.new_vessels', ['y' => $y])) . '</span></a><a class="stat" href="' . url('vessels') . '"><b>' . $total . '</b><span class="s">' . tt('act.vessels_total') . '</span></a></div>';
    $h .= '<div class="grid grid-2"><div><h3 class="ac-h" style="margin-top:0;">🏆 ' . e(t('act.top_year', ['y' => $y])) . '</h3>' . $list($topY) . '</div><div><h3 class="ac-h" style="margin-top:0;">🏆 ' . tt('act.top_month') . '</h3>' . $list($topM) . '</div></div>';
    $h .= '<p class="hint" style="margin:8px 0 0;">' . tt('act.hint') . '</p></div>';
    return $h;
}

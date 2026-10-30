<?php
/**
 * Weekly vessel report — one ISO week, one vessel (all its projects or one).   GAS-CP-R15-001
 *   1 progress per stage since last week / since start   2 deadlines   3 foreseen delays
 *   4 open items: parts + offers pending   5 approvals owner / Golden   6 class submittals
 *   7 work orders   8 service reports + open items   9 tickets with photos + communication
 * Customers get the external view (no drafts of ours, no internal notes).
 */

function wk_monday(string $d): string { $t = strtotime($d) ?: time(); return date('Y-m-d', $t - ((int)date('N', $t) - 1) * 86400); }
function wk_doc_no(array $v, ?array $p, string $ws): string {
    $slug = fn($x) => trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', (string)$x)), '-');
    return br_prefix((int)($p['id'] ?? 0) ?: null) . '-WR-' . $slug($v['name']) . ($p ? '-' . $slug($p['project_code'] ?: $p['name']) : '') . '-' . date('o\WW', strtotime($ws));
}
function wk_days_left(?string $d, string $ref): ?int { return $d ? (int) floor((strtotime($d) - strtotime($ref)) / 86400) : null; }

function weekly_data(int $vid, ?int $onlyProject, string $weekStart): ?array {
    if (!$vid && $onlyProject) {                       // project-level call (the project's own "Weekly report" button): same report, scoped to that project
        [$ps, $pa] = scope_sql('p'); array_unshift($pa, $onlyProject);
        $pp = one("SELECT p.*, c.name AS customer_name FROM projects p LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ? AND {$ps}", $pa);
        if (!$pp) return null;
        $vid = (int)($pp['vessel_id'] ?? 0);
        if (!$vid) { $v = ['id' => 0, 'name' => $pp['name'], 'imo' => null, 'customer_name' => $pp['customer_name']]; }
    }
    if ($vid) {
        [$vs, $va] = tk_scope(); array_unshift($va, $vid);
        $v = one("SELECT v.*, c.name AS customer_name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ? AND {$vs}", $va);
        if (!$v) return null;
    }
    if (empty($v)) return null;
    $staff = is_staff(); $r15 = of_has();
    $ws = wk_monday($weekStart); $we = date('Y-m-d', strtotime($ws) + 6 * 86400); $prev = date('Y-m-d', strtotime($ws) - 86400);
    $weEnd = $we . ' 23:59:59'; $wsStart = $ws . ' 00:00:00';
    $inWeek = fn(?string $dt) => $dt && substr($dt, 0, 10) >= $ws && substr($dt, 0, 10) <= $we;
    $stages = stage_defs();
    $W = ['vessel' => $v, 'week_start' => $ws, 'week_end' => $we, 'prev' => $prev, 'iso' => date('o-\WW', strtotime($ws)), 'projects' => [], 'only_project' => null, 'staff' => $staff];
    [$ps, $pa] = scope_sql('p');
    $projects = $onlyProject ? all("SELECT p.* FROM projects p WHERE p.id = ? AND {$ps}", array_merge([$onlyProject], $pa))
                             : all("SELECT p.* FROM projects p WHERE p.vessel_id = ? AND {$ps} ORDER BY p.status = 'completed', p.project_code, p.name", array_merge([$vid], $pa));
    if ($onlyProject && $projects) $W['only_project'] = $projects[0];
    foreach ($projects as $p) {
        $pid = (int)$p['id']; $P = ['p' => $p];
        /* 1. progress */
        $subs = all('SELECT s.*, vs.abbr AS system_abbr, vs.name AS system_name FROM subprojects s LEFT JOIN vessel_systems vs ON vs.id = s.system_id WHERE s.project_id = ? ORDER BY s.sort_order, s.code', [$pid]);
        $tot = ['now' => array_fill_keys(array_keys($stages), 0), 'base' => array_fill_keys(array_keys($stages), 0), 'n' => array_fill_keys(array_keys($stages), 0)];
        foreach ($subs as &$s) {
            $base = one('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? AND snap_date <= ? ORDER BY snap_date DESC LIMIT 1', [$s['id'], $prev]);
            $cur  = one('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? AND snap_date <= ? ORDER BY snap_date DESC LIMIT 1', [$s['id'], $we]);
            $s['now'] = []; $s['base'] = []; $s['delta'] = [];
            foreach ($stages as $k => $d) {
                $n = $we >= date('Y-m-d') ? (int)$s[$d['col']] : (int)($cur[$d['col']] ?? $s[$d['col']]);   // a past week reads the snapshot of that week
                $b = $base ? (int)$base[$d['col']] : 0;
                if (pct_na($n)) { $b = PCT_NA; } elseif (pct_na($b)) { $b = 0; }                 // a stage that does not apply stays out of sums and deltas (R24-004)
                $s['now'][$k] = $n; $s['base'][$k] = $b; $s['delta'][$k] = pct_na($n) ? 0 : $n - $b;
                if (!pct_na($n)) { $tot['now'][$k] += $n; $tot['base'][$k] += $b; $tot['n'][$k]++; }
            }
            $s['fed'] = $staff && function_exists('pd_sub_fed') ? pd_sub_fed((int)$s['id']) : [];   // R42-001: stages fed from the production orders
            $s['overall_now'] = pct_avg($s['now']); $s['overall_base'] = pct_avg($s['base']);
            $s['has_base'] = (bool)$base; $s['base_date'] = $base['snap_date'] ?? null;
        }
        unset($s);
        $n = max(1, count($subs));
        $P['subs'] = $subs;
        $avgOf = fn(array $sum) => array_map(fn($k) => $tot['n'][$k] ? (int) round($sum[$k] / $tot['n'][$k]) : PCT_NA, array_keys($stages));   // per stage: the sub-projects where it applies
        $P['totals'] = ['now' => array_combine(array_keys($stages), $avgOf($tot['now'])), 'base' => array_combine(array_keys($stages), $avgOf($tot['base']))];
        $P['overall_now'] = $subs ? pct_avg(array_map(fn($x) => $x['overall_now'], $subs)) : 0; $P['overall_base'] = $subs ? pct_avg(array_map(fn($x) => $x['overall_base'], $subs)) : 0;
        /* 1b. weekly notes of the sub-projects (sp_notes) with their photos */
        $iso = date('o-\WW', strtotime($ws));
        $P['sp_notes'] = all("SELECT n.*, cu.full_name AS author, s.code AS sub_code, s.name AS sub_name FROM sp_notes n JOIN subprojects s ON s.id = n.subproject_id LEFT JOIN customer_users cu ON cu.id = n.author_id
                              WHERE s.project_id = ? AND n.iso_week = ? " . ($staff ? '' : "AND n.visibility = 'external' ") . "ORDER BY s.sort_order, s.code, n.note_date, n.id", [$pid, $iso]);
        foreach ($P['sp_notes'] as &$nn) $nn['photos'] = all("SELECT * FROM sp_files WHERE note_id = ? AND kind = 'photo' " . ($staff ? '' : "AND visibility = 'external' ") . 'ORDER BY id', [$nn['id']]); unset($nn);
        /* 2. deadlines */
        $P['deadlines'] = [];
        foreach ($subs as $s) $P['deadlines'][] = ['code' => $s['code'], 'name' => $s['name'], 'deadline' => $s['deadline'] ?? null, 'days' => wk_days_left($s['deadline'] ?? null, $we), 'overall' => $s['overall_now'], 'status' => $s['status']];
        $P['project_deadline'] = $p['deadline'] ?? null; $P['project_days'] = wk_days_left($p['deadline'] ?? null, $we);
        /* 3. foreseen delays: automatic flags + the PM's remarks */
        $flags = [];
        if ($P['project_deadline'] && $P['project_days'] < 0 && $p['status'] !== 'completed') $flags[] = t('wv.flag_project_overdue', ['d' => fmt_date($P['project_deadline']), 'n' => -$P['project_days']]);
        foreach ($P['deadlines'] as $d) {
            if ($d['status'] === 'completed' || $d['overall'] >= 100) continue;
            if ($d['deadline'] && $d['days'] < 0) $flags[] = t('wv.flag_overdue', ['s' => $d['code'] . ' ' . $d['name'], 'n' => -$d['days'], 'p' => $d['overall']]);
            elseif ($d['deadline'] && $d['days'] <= 21 && $d['overall'] < 80) $flags[] = t('wv.flag_at_risk', ['s' => $d['code'] . ' ' . $d['name'], 'n' => $d['days'], 'p' => $d['overall']]);
        }
        foreach ($subs as $s) if ($s['status'] === 'onhold') $flags[] = t('wv.flag_onhold', ['s' => $s['code'] . ' ' . $s['name']]);
        $P['flags'] = $flags;
        $P['notes'] = $r15 ? one('SELECT * FROM weekly_notes WHERE project_id = ? AND week_start = ?', [$pid, $ws]) : null;
        /* 4. open items: parts + offers */
        $fsrAll = all("SELECT f.id, f.report_no, f.report_date, f.status, f.submitted_at, f.accepted_at, f.submitted_name, f.accepted_name, f.customer_remark, f.ticket_id, f.created_by, s.code AS sub_code, s.name AS sub_name
                       FROM fsr_reports f LEFT JOIN subprojects s ON s.id = f.subproject_id WHERE f.project_id = ? " . ($staff ? '' : "AND f.status <> 'draft' ") . "ORDER BY f.report_date, f.id", [$pid]);
        $fids = array_map(fn($x) => (int)$x['id'], $fsrAll) ?: [0];
        $works = all('SELECT w.*, f.report_no, f.report_date, f.status FROM fsr_works w JOIN fsr_reports f ON f.id = w.report_id WHERE w.report_id IN (' . implode(',', $fids) . ') ORDER BY f.report_date, w.report_id, w.seq');
        $P['open_parts'] = array_values(array_filter(array_map(fn($w) => trim((string)$w['open_issues']) !== '' ? ['date' => $w['report_date'], 'ref' => $w['report_no'], 'task' => mb_substr((string)$w['description'], 0, 120), 'issue' => $w['open_issues']] : null, $works)));
        $P['parts_tickets'] = tk_has() ? all("SELECT t.* FROM tickets t WHERE t.project_id = ? AND t.category = 'spare_parts' AND t.status IN ('open','in_progress','waiting_customer') ORDER BY t.created_at", [$pid]) : [];
        $P['offers_pending'] = $r15 ? all("SELECT o.*, s.code AS sub_code FROM offers o LEFT JOIN subprojects s ON s.id = o.subproject_id WHERE o.project_id = ? AND o.status = 'sent' ORDER BY o.sent_at", [$pid]) : [];
        $P['offers_week']    = $r15 ? all("SELECT o.*, s.code AS sub_code FROM offers o LEFT JOIN subprojects s ON s.id = o.subproject_id WHERE o.project_id = ? AND ((o.sent_at BETWEEN ? AND ?) OR (o.status_at BETWEEN ? AND ?)) ORDER BY o.sent_at", [$pid, $ws, $we, $ws, $we]) : [];
        $P['offers_all']     = $r15 ? all("SELECT o.*, s.code AS sub_code FROM offers o LEFT JOIN subprojects s ON s.id = o.subproject_id WHERE o.project_id = ? ORDER BY FIELD(o.status,'sent','approved','in_progress','to_be_invoiced','finished','rejected','cancelled'), o.sent_at", [$pid]) : [];
        /* 5. approvals */
        $oa = all("SELECT o.*, s.code AS sub_code FROM owner_approvals o LEFT JOIN subprojects s ON s.id = o.subproject_id WHERE o.project_id = ? " . ($staff ? '' : "AND o.status <> 'draft' ") . "ORDER BY o.submitted_at, o.id", [$pid]);
        $P['oa_week'] = array_values(array_filter($oa, fn($o) => $inWeek($o['decided_time'] ?? null) || $inWeek($o['decided_at'] ?? null) || $inWeek($o['submitted_at'] ?? null)));
        $P['oa_pending'] = array_values(array_filter($oa, fn($o) => in_array($o['status'], ['submitted', 'resubmitted'], true)));
        $P['oa_rejected'] = array_values(array_filter($oa, fn($o) => $o['status'] === 'rejected'));
        $jos = all("SELECT j.* FROM job_orders j WHERE j.project_id = ? " . ($staff ? '' : "AND NOT (j.status = 'draft' AND j.requested_side <> 'customer') ") . "ORDER BY j.created_at", [$pid]);
        $P['golden_week'] = array_values(array_filter($jos, fn($j) => in_array($j['status'], ['approved', 'rejected', 'in_progress', 'done'], true) && $inWeek($j['approved_at'] ?? null)));
        $P['golden_pending'] = array_values(array_filter($jos, fn($j) => $j['status'] === 'pending_approval'));
        $P['fsr_decided_week'] = array_values(array_filter($fsrAll, fn($f) => in_array($f['status'], ['accepted', 'disputed'], true) && ($inWeek($f['accepted_at'] ?? null) || ($f['status'] === 'disputed' && $inWeek($f['submitted_at'] ?? null)))));
        /* 5b. drawing requests: everything still pending (owner / yard / supplier side) and what came in this week — R36-003 */
        $drw = all("SELECT d.*, vs.name AS system_name, s.code AS sub_code FROM drawing_requests d LEFT JOIN vessel_systems vs ON vs.id = d.system_id LEFT JOIN subprojects s ON s.id = d.subproject_id
                    WHERE d.project_id = ? AND (d.status = 'pending' OR (d.received_at BETWEEN ? AND ?)) ORDER BY d.status = 'pending' DESC, d.expected_at IS NULL, d.expected_at, d.kind DESC, d.seq_no", [$pid, $ws, $we]);
        $P['drw_pending'] = array_values(array_filter($drw, fn($d) => $d['status'] === 'pending'));
        $P['drw_week'] = array_values(array_filter($drw, fn($d) => $d['status'] !== 'pending'));
        /* 6. class */
        $cls = all("SELECT c.*, s.code AS sub_code FROM class_submittals c LEFT JOIN subprojects s ON s.id = c.subproject_id WHERE c.project_id = ? ORDER BY c.class_society, c.submitted_at, c.id", [$pid]);
        foreach ($cls as &$c) $c['changed'] = $inWeek($c['submitted_at']) || $inWeek($c['decided_at']) || $inWeek(substr((string)$c['updated_at'], 0, 10)); unset($c);
        $P['class'] = $cls;
        /* 7. work orders */
        $P['jos_week'] = array_values(array_filter($jos, fn($j) => $inWeek($j['created_at']) || $inWeek($j['approved_at'] ?? null) || $inWeek($j['started_at'] ?? null) || $inWeek($j['done_at'] ?? null) || ($inWeek($j['updated_at']) && $j['status'] !== 'draft')));
        $P['jos_draft'] = array_values(array_filter($jos, fn($j) => $j['status'] === 'draft'));
        $P['jos_open'] = array_values(array_filter($jos, fn($j) => in_array($j['status'], ['pending_approval', 'approved', 'in_progress'], true)));
        /* 8. service reports */
        $openBy = []; foreach ($works as $w) if (trim((string)$w['open_issues']) !== '') $openBy[(int)$w['report_id']][] = $w['open_issues'];
        foreach ($fsrAll as &$f) $f['open_items'] = $openBy[(int)$f['id']] ?? []; unset($f);
        $P['fsrs_week'] = array_values(array_filter($fsrAll, fn($f) => $inWeek($f['report_date']) || $inWeek($f['submitted_at'] ?? null) || $inWeek($f['accepted_at'] ?? null)));
        $P['fsrs_draft'] = array_values(array_filter($fsrAll, fn($f) => $f['status'] === 'draft'));
        $P['fsrs_awaiting'] = array_values(array_filter($fsrAll, fn($f) => $f['status'] === 'submitted'));
        /* 9. tickets */
        $P['tickets'] = wk_tickets('t.project_id = ?', [$pid], $ws, $we, $staff);
        $W['projects'][] = $P;
    }
    // tickets on the vessel without a project (generic support) — only in the whole-vessel view
    $W['vessel_tickets'] = ($onlyProject || !$vid) ? [] : wk_tickets('t.vessel_id = ? AND t.project_id IS NULL', [$vid], $ws, $we, $staff);
    return $W;
}
/** Tickets with what happened this week: events, comments (external only for owners), image attachments. */
function wk_tickets(string $where, array $args, string $ws, string $we, bool $staff): array {
    if (!tk_has()) return [];
    $rows = all("SELECT t.*, a.full_name AS assignee FROM tickets t LEFT JOIN customer_users a ON a.id = t.assigned_to WHERE {$where} AND (t.status IN ('open','in_progress','waiting_customer') OR t.updated_at >= ?) ORDER BY FIELD(t.status,'open','in_progress','waiting_customer','resolved','closed'), t.created_at", array_merge($args, [$ws . ' 00:00:00']));
    foreach ($rows as &$t) {
        $t['events_week'] = all('SELECT * FROM ticket_events WHERE ticket_id = ? AND created_at BETWEEN ? AND ? ORDER BY id', [$t['id'], $ws . ' 00:00:00', $we . ' 23:59:59']);
        $t['comments_week'] = all('SELECT * FROM ticket_comments WHERE ticket_id = ? AND deleted_at IS NULL AND created_at BETWEEN ? AND ?' . ($staff ? '' : " AND visibility = 'external'") . ' ORDER BY id', [$t['id'], $ws . ' 00:00:00', $we . ' 23:59:59']);
        $t['photos'] = all("SELECT * FROM ticket_files WHERE ticket_id = ? AND mime_type LIKE 'image/%' AND created_at BETWEEN ? AND ? ORDER BY id", [$t['id'], $ws . ' 00:00:00', $we . ' 23:59:59']);
        $t['n_comments'] = (int) scalar('SELECT COUNT(*) FROM ticket_comments WHERE ticket_id = ? AND deleted_at IS NULL' . ($staff ? '' : " AND visibility = 'external'"), [$t['id']]);
    }
    return $rows;
}
/** Stage abbreviations for compact tables. */
function wk_stage_abbr(): array { return ['engineering' => 'ENG', 'procurement' => 'PROC', 'production' => 'PROD', 'fat' => 'FAT', 'installation' => 'INST', 'sathat' => 'SAT/HAT', 'commissioning' => 'COMM']; }
function wk_pct_delta(int $now, int $base, bool $hasBase = true): string { if (pct_na($now)) return t('sp.na'); $d = $now - $base; return $now . ' %' . ($d > 0 ? ' (+' . $d . ')' : ($d < 0 ? ' (' . $d . ')' : '')); }

/* ================================================================ render */

/** Every table of the report as rows, shared by PDF and Word. Returns sections => [['title','cols','rows','note'], …] */
function wk_sections(array $W): array {
    $staff = $W['staff']; $S = []; $ab = wk_stage_abbr(); $stages = array_keys(stage_defs());
    $pfx = fn(array $P) => count($W['projects']) > 1 ? ($P['p']['project_code'] ?: $P['p']['name']) . ' · ' : '';
    $sec = function (int $no, string $title) use (&$S) { $S[$no] = ['title' => $no . '. ' . $title, 'tables' => [], 'text' => []]; };
    $tbl = function (int $no, string $title, array $cols, array $rows) use (&$S) { $S[$no]['tables'][] = ['title' => $title, 'cols' => $cols, 'rows' => $rows]; };
    $txt = function (int $no, string $t) use (&$S) { $S[$no]['text'][] = $t; };
    // 1
    $sec(1, t('wv.s1'));
    foreach ($W['projects'] as $P) {
        $cols = [[t('sp.one'), 44, 'L']]; foreach ($stages as $k) $cols[] = [$ab[$k], 15, 'R']; $cols[] = [t('wv.overall'), 21, 'R'];
        $rows = [];
        $anyFed = false; foreach ($P['subs'] as $s) { $v = [trim($s['code'] . ' ' . $s['name'])]; foreach ($stages as $k) { $v[] = wk_pct_delta($s['now'][$k], $s['base'][$k]) . (in_array($k, $s['fed'] ?? [], true) ? ' *' : ''); if (in_array($k, $s['fed'] ?? [], true)) $anyFed = true; } $v[] = wk_pct_delta($s['overall_now'], $s['overall_base']); $hl = []; foreach ($v as $ci => $cv) if ($ci > 0 && preg_match('/\(\s*[−-]\d/u', (string)$cv)) $hl[$ci] = 'red'; elseif ($ci > 0 && preg_match('/\(\s*\+\d/', (string)$cv)) $hl[$ci] = 'green'; $rows[] = ['v' => $v, 'hl' => $hl, 'tone' => $s['overall_now'] >= 100 ? 'green' : (($s['overall_now'] - $s['overall_base']) < 0 ? 'red' : (($s['overall_now'] - $s['overall_base']) > 0 ? 'navy' : null))]; }
        $v = [t('wv.project_avg')]; foreach ($stages as $k) $v[] = wk_pct_delta($P['totals']['now'][$k], $P['totals']['base'][$k]); $v[] = wk_pct_delta($P['overall_now'], $P['overall_base']); $rows[] = ['v' => $v, 'b' => true];
        $tbl(1, $pfx($P) . t('wv.t_progress'), $cols, $rows);
        if ($anyFed) $txt(1, '* ' . t('pd.fed_mark_long'));
    }
    $txt(1, t('wv.s1_note'));
    // 2 — weekly notes
    $sec(2, t('wv.s_notes'));
    foreach ($W['projects'] as $P) {
        $tbl(2, $pfx($P) . t('wv.t_notes'), [[t('th.date'), 22, 'L'], [t('sp.one'), 40, 'L'], [t('wk.note'), 96, 'L'], [t('wv.by'), 22, 'L']],
             array_map(fn($nn) => ['v' => [fmt_date($nn['note_date']), trim($nn['sub_code'] . ' ' . $nn['sub_name']), $nn['body'] . ($nn['photos'] ? ' [' . count($nn['photos']) . ' ' . t('wk.photos') . ']' : '') . ($nn['visibility'] === 'internal' ? ' (' . t('tk.internal') . ')' : ''), $nn['author'] ?: '']], $P['sp_notes']));
    }
    // 3
    $sec(3, t('wv.s2'));
    foreach ($W['projects'] as $P) {
        $rows = [['v' => [t('wv.whole_project'), $P['project_deadline'] ? fmt_date($P['project_deadline']) : '—', $P['project_days'] === null ? '—' : $P['project_days'], $P['overall_now'] . ' %', t('pr.' . $P['p']['status']) . ($P['p']['next_milestone'] ? ' · ' . $P['p']['next_milestone'] : '')], 'b' => true]];
        $rows[0]['tone'] = $P['p']['status'] === 'completed' ? 'green' : rc_days($P['project_days'] === null ? null : (int)$P['project_days']); if ($rows[0]['tone']) $rows[0]['hl'] = [1 => $rows[0]['tone'], 2 => $rows[0]['tone']];
        foreach ($P['deadlines'] as $d) { $tn = $d['status'] === 'completed' ? 'green' : rc_days($d['days'] === null ? null : (int)$d['days']); $rows[] = ['v' => [trim($d['code'] . ' ' . $d['name']), $d['deadline'] ? fmt_date($d['deadline']) : '—', $d['days'] === null ? '—' : (string)$d['days'], $d['overall'] . ' %', t('pr.' . $d['status'])], 'tone' => $tn, 'hl' => $tn && $tn !== 'green' ? [1 => $tn, 2 => $tn] : []]; }
        $tbl(3, $pfx($P) . t('wv.t_deadlines'), [[t('sp.one'), 70, 'L'], [t('wv.deadline'), 26, 'L'], [t('wv.days_left'), 22, 'R'], [t('vh.progress'), 22, 'R'], [t('th.status'), 40, 'L']], $rows);
    }
    // 3
    $sec(4, t('wv.s3'));
    foreach ($W['projects'] as $P) {
        $lines = $P['flags']; if (!empty($P['notes']['delays'])) $lines[] = t('wv.pm_says') . ': ' . $P['notes']['delays'];
        $tbl(4, $pfx($P) . t('wv.t_delays'), [[t('wv.item'), 180, 'L']], $lines ? array_map(fn($l) => ['v' => [$l], 'tone' => 'red'], $lines) : [['v' => [t('wv.no_delays')], 'tone' => 'green']]);
        if (!empty($P['notes']['remarks'])) $txt(4, $pfx($P) . t('wv.remarks') . ': ' . $P['notes']['remarks']);
    }
    // 4
    $sec(5, t('wv.s4'));
    foreach ($W['projects'] as $P) {
        if (function_exists('iss_has') && iss_has()) { foreach (iss_report_tables((int)$P['p']['id'], null, $W['week_start'], $W['week_end']) as $it) $tbl(5, $pfx($P) . $it['title'], $it['cols'], $it['rows']); $rows = []; }   // R29-001: the register
        else $rows = array_map(fn($x) => ['v' => [fmt_date($x['date']), $x['ref'], $x['task'], $x['issue']]], $P['open_parts']);
        foreach ($P['parts_tickets'] as $tk) $rows[] = ['v' => [fmt_date(substr($tk['created_at'], 0, 10)), $tk['ticket_no'], $tk['title'], t('tk.st_' . $tk['status'])], 'tone' => rc_ticket($tk)];
        if ($rows || !(function_exists('iss_has') && iss_has())) $tbl(5, $pfx($P) . t('wv.t_parts'), [[t('th.date'), 22, 'L'], [t('th.ref'), 34, 'L'], [t('wv.item'), 60, 'L'], [t('wv.open_item'), 64, 'L']], $rows);
        $tbl(5, $pfx($P) . t('wv.t_offers_pending'), [[t('of.no'), 32, 'L'], [t('of.title_f'), 64, 'L'], [t('of.amount'), 30, 'R'], [t('of.sent'), 24, 'L'], [t('of.aging'), 16, 'R'], [t('of.valid_until'), 14, 'L']],
             array_map(fn($o) => ['v' => [$o['offer_no'] . ' ' . $o['revision'], $o['title'], of_money((float)$o['amount'], $o['currency']), fmt_date($o['sent_at']), of_age_label($o), $o['valid_until'] ? fmt_date($o['valid_until']) : '—'], 'tone' => ($o['valid_until'] && $o['valid_until'] < date('Y-m-d')) || (of_age($o) ?? 0) > 14 ? 'red' : 'amber', 'hl' => $o['valid_until'] && $o['valid_until'] < date('Y-m-d') ? [5 => 'red'] : []], $P['offers_pending']));
        if ($P['offers_week']) $tbl(5, $pfx($P) . t('wv.t_offers_week'), [[t('of.no'), 32, 'L'], [t('of.title_f'), 64, 'L'], [t('of.amount'), 30, 'R'], [t('th.status'), 30, 'L'], [t('oa.decision'), 24, 'L']],
             array_map(fn($o) => ['v' => [$o['offer_no'] . ' ' . $o['revision'], $o['title'], of_money((float)$o['amount'], $o['currency']), of_status_label($o['status']), $o['decided_name'] ? $o['decided_name'] : '—'], 'tone' => rc_status($o['status']) ?? (of_status_behaves($o['status']) === 'approved' ? 'green' : null)], $P['offers_week']));
    }
    // 5
    $sec(6, t('wv.s5'));
    foreach ($W['projects'] as $P) {
        $oaRow = fn($o) => ['tone' => rc_status($o['status']), 'v' => [$o['doc_no'], $o['title'] . ' ' . $o['revision'], $o['sub_code'] ?: '—', fmt_date($o['submitted_at']), t('sub.' . $o['status']), ($o['decided_name'] ?? '') . ($o['decided_time'] ? ' · ' . fmt_date(substr($o['decided_time'], 0, 10)) : ($o['decided_at'] ? ' · ' . fmt_date($o['decided_at']) : ''))]];
        $oaCols = [[t('sub.docno'), 30, 'L'], [t('sub.doc'), 60, 'L'], [t('sp.one'), 16, 'L'], [t('sub.submitted'), 22, 'L'], [t('th.status'), 28, 'L'], [t('oa.decision'), 24, 'L']];
        $tbl(6, $pfx($P) . t('wv.t_oa_week'), $oaCols, array_map($oaRow, $P['oa_week']));
        $tbl(6, $pfx($P) . t('wv.t_oa_pending'), $oaCols, array_map($oaRow, $P['oa_pending']));
        if ($P['oa_rejected']) $tbl(6, $pfx($P) . t('wv.t_oa_rejected'), $oaCols, array_map($oaRow, $P['oa_rejected']));
        $gRows = array_map(fn($j) => ['tone' => rc_status($j['status']), 'v' => [$j['jo_no'], $j['title'], t('jo.one') . ' · ' . ($j['requested_side'] === 'customer' ? t('role.customer') : 'Golden A/S'), t('jo.st_' . $j['status']), ($j['approved_name'] ?? '') . ($j['approved_at'] ? ' · ' . fmt_date(substr($j['approved_at'], 0, 10)) : '')]], $P['golden_week']);
        foreach ($P['fsr_decided_week'] as $f) $gRows[] = ['tone' => rc_status($f['status']), 'v' => [$f['report_no'], ($f['sub_code'] ? $f['sub_code'] . ' ' : '') . fmt_date($f['report_date']), t('fsr.one'), t('fsr.st_' . $f['status']), ($f['accepted_name'] ?? '') . ($f['accepted_at'] ? ' · ' . fmt_date(substr($f['accepted_at'], 0, 10)) : '')]];
        foreach ($P['golden_pending'] as $j) $gRows[] = ['tone' => 'amber', 'v' => [$j['jo_no'], $j['title'], t('jo.one') . ' · ' . ($j['requested_side'] === 'customer' ? t('role.customer') : 'Golden A/S'), t('jo.st_' . $j['status']), '—']];
        $tbl(6, $pfx($P) . t('wv.t_golden'), [[t('th.ref'), 40, 'L'], [t('wv.item'), 60, 'L'], [t('vh.kind'), 30, 'L'], [t('th.status'), 26, 'L'], [t('oa.decision'), 24, 'L']], $gRows);
        // R36-003: drawing / information requests still open (every pending item, oldest expected first) and the ones received this week
        $dCols = [['#', 8, 'R'], [t('dr.item'), 62, 'L'], [t('sys.one'), 30, 'L'], [t('dr.responsible'), 24, 'L'], [t('dr.sent'), 20, 'L'], [t('dr.expected'), 20, 'L'], [t('wv.days_left'), 16, 'R']];
        $dRow = function ($d) use ($W) { $days = wk_days_left($d['expected_at'] ?? null, $W['week_end']); $tn = $d['status'] === 'pending' ? ($days === null ? 'amber' : ($days < 0 ? 'red' : ($days <= 7 ? 'amber' : null))) : 'green';
            return ['tone' => $tn, 'hl' => $tn === 'red' ? [5 => 'red', 6 => 'red'] : [], 'v' => [(string)(int)$d['seq_no'], $d['title'] . ($d['sub_code'] ? ' (' . $d['sub_code'] . ')' : '') . ($d['remarks'] ? "\n" . $d['remarks'] : ''), $d['system_name'] ?: ($d['kind'] === 'principal' ? t('dr.principal') : ''), $d['responsible'], fmt_date($d['request_sent']), $d['status'] === 'pending' ? ($d['expected_at'] ? fmt_date($d['expected_at']) : '—') : t('dr.received') . ' ' . fmt_date($d['received_at']), $d['status'] === 'pending' ? ($days === null ? '—' : (string)$days) : '']]; };
        $tbl(6, $pfx($P) . t('wv.t_drw_pending'), $dCols, array_map($dRow, $P['drw_pending']));
        if ($P['drw_week']) $tbl(6, $pfx($P) . t('wv.t_drw_week'), $dCols, array_map($dRow, $P['drw_week']));
    }
    // 6
    $sec(7, t('wv.s6'));
    foreach ($W['projects'] as $P) {
        $tbl(7, $pfx($P) . t('wv.t_class'), [[t('cs.society'), 20, 'L'], [t('sub.docno'), 30, 'L'], [t('sub.doc'), 56, 'L'], [t('sub.submitted'), 20, 'L'], [t('sub.due'), 20, 'L'], [t('th.status'), 24, 'L'], [t('wv.changed'), 10, 'L']],
             array_map(fn($c) => ['v' => [$c['class_society'], $c['doc_no'] . ($c['class_ref'] ? ' / ' . $c['class_ref'] : ''), $c['title'] . ' ' . $c['revision'], fmt_date($c['submitted_at']), $c['due_at'] ? fmt_date($c['due_at']) : '—', t('sub.' . $c['status']), $c['changed'] ? '*' : ''], 'b' => (bool)$c['changed'], 'tone' => rc_status($c['status']) === 'green' ? 'green' : (rc_due($c['due_at'] ?? null) ?? rc_status($c['status'])), 'hl' => rc_due($c['due_at'] ?? null) === 'red' && rc_status($c['status']) !== 'green' ? [4 => 'red'] : []], $P['class']));
    }
    // 7
    $sec(8, t('wv.s7'));
    foreach ($W['projects'] as $P) {
        $joRow = fn($j) => ['v' => [$j['jo_no'], $j['title'], $j['requested_side'] === 'customer' ? t('role.customer') : 'Golden A/S', fmt_date(substr($j['created_at'], 0, 10)), $j['requested_for'] ? fmt_date($j['requested_for']) : '—', t('jo.st_' . $j['status'])], 'tone' => rc_status($j['status']) === 'green' ? 'green' : (rc_due($j['requested_for'] ?? null) ?? rc_status($j['status'])), 'hl' => rc_due($j['requested_for'] ?? null) === 'red' && rc_status($j['status']) !== 'green' ? [4 => 'red'] : []];
        $joCols = [[t('jo.no'), 44, 'L'], [t('jo.title_f'), 62, 'L'], [t('tk.raised_by'), 22, 'L'], [t('th.date'), 18, 'L'], [t('jo.for'), 18, 'L'], [t('th.status'), 16, 'L']];
        $tbl(8, $pfx($P) . t('wv.t_jo_week'), $joCols, array_map($joRow, $P['jos_week']));
        $tbl(8, $pfx($P) . t('wv.t_jo_draft'), $joCols, array_map($joRow, $P['jos_draft']));
        if ($P['jos_open']) $tbl(8, $pfx($P) . t('wv.t_jo_open'), $joCols, array_map($joRow, $P['jos_open']));
    }
    // 8
    $sec(9, t('wv.s8'));
    foreach ($W['projects'] as $P) {
        $fRow = fn($f) => ['tone' => rc_status($f['status']) ?? ($f['open_items'] ? 'amber' : null), 'v' => [$f['report_no'], fmt_date($f['report_date']), $f['sub_code'] ? $f['sub_code'] . ' ' . $f['sub_name'] : t('fsr.whole_project'), t('fsr.st_' . $f['status']) . ($f['accepted_name'] ? ' · ' . $f['accepted_name'] : ''), $f['open_items'] ? implode("\n", $f['open_items']) : '—']];
        $fCols = [[t('fsr.one'), 30, 'L'], [t('th.date'), 20, 'L'], [t('sp.one'), 36, 'L'], [t('th.status'), 34, 'L'], [t('fsr.open_issues'), 60, 'L']];
        $tbl(9, $pfx($P) . t('wv.t_fsr_week'), $fCols, array_map($fRow, $P['fsrs_week']));
        if ($staff) $tbl(9, $pfx($P) . t('wv.t_fsr_draft'), $fCols, array_map($fRow, $P['fsrs_draft']));
        $tbl(9, $pfx($P) . t('wv.t_fsr_awaiting'), $fCols, array_map($fRow, $P['fsrs_awaiting']));
    }
    // 9
    $sec(10, t('wv.s9'));
    $tkCols = [[t('tk.no'), 24, 'L'], [t('tk.title_f'), 50, 'L'], [t('tk.category'), 22, 'L'], [t('th.status'), 24, 'L'], [t('tk.assignee'), 24, 'L'], [t('wv.this_week'), 36, 'L']];
    $tkRow = function ($t) { $ev = []; foreach ($t['events_week'] as $e) $ev[] = fmt_date(substr($e['created_at'], 0, 10)) . ' ' . wk_event_label($e['event']); if ($t['comments_week']) $ev[] = count($t['comments_week']) . ' ' . t('th.comments');
        return ['tone' => rc_ticket($t), 'v' => [$t['ticket_no'], $t['title'] . ($t['resolution'] ? "\n" . t('tk.resolution') . ': ' . $t['resolution'] : ''), tk_categories()[$t['category']] ?? $t['category'], t('tk.st_' . $t['status']), $t['assignee'] ?: t('tk.unassigned'), $ev ? implode("\n", $ev) : '—']]; };
    foreach ($W['projects'] as $P) $tbl(10, $pfx($P) . t('wv.t_tickets'), $tkCols, array_map($tkRow, $P['tickets']));
    if ($W['vessel_tickets']) $tbl(10, t('tk.generic') . ' · ' . t('wv.t_tickets'), $tkCols, array_map($tkRow, $W['vessel_tickets']));
    if (function_exists('wf_has') && wf_has() && !empty($W['vessel']['id'])) {                      // R31-001: next week's issued works forecast (Plan A / Plan B areas)
        $nextWeek = iso_week(date('Y-m-d', strtotime($W['week_start'] . ' +7 days')));
        $wfRows = wf_for_weekly((int)$W['vessel']['id'], $nextWeek);
        if ($wfRows) { $sec(11, t('wf.wk_section', ['w' => $nextWeek])); $tbl(11, t('wf.wk_table'), [[t('pr.one'), 22, 'L'], [t('wf.plan'), 12, 'L'], [t('wf.area'), 44, 'L'], [t('wf.subs'), 24, 'L'], [t('wf.works'), 56, 'L'], [t('wf.days'), 22, 'L']], $wfRows); }
    }
    ksort($S);
    return $S;
}
function wk_event_label(string $e): string {
    $m = ['created' => 'tk.ev_created', 'edited' => 'tk.ev_edited', 'assigned' => 'tk.ev_assigned', 'unassigned' => 'tk.ev_unassigned', 'customer_replied' => 'tk.ev_customer_replied', 'status_open' => 'tk.ev_reopened', 'status_in_progress' => 'tk.ev_started', 'status_waiting_customer' => 'tk.ev_waiting', 'status_resolved' => 'tk.ev_resolved', 'status_closed' => 'tk.ev_closed', 'jo_linked' => 'tk.ev_jo_linked', 'fsr_linked' => 'tk.ev_fsr_linked', 'project_set' => 'tk.ev_project_set', 'project_cleared' => 'tk.ev_project_cleared'];
    return isset($m[$e]) ? t($m[$e]) : $e;
}
/** Weekly notes that carry photos, for the photo appendix. */
function wk_note_photos(array $W): array { $out = []; foreach ($W['projects'] as $P) foreach ($P['sp_notes'] as $nn) if ($nn['photos']) $out[] = $nn; return $out; }
/** Ticket communication + photos of the week, for the appendix of section 9. */
function wk_ticket_detail(array $W): array {
    $out = []; $all = $W['vessel_tickets'];
    foreach ($W['projects'] as $P) $all = array_merge($all, $P['tickets']);
    foreach ($all as $t) if ($t['comments_week'] || $t['photos']) $out[] = $t;
    return $out;
}

function wk_render_pdf(array $W): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $v = $W['vessel']; $docNo = wk_doc_no($v, $W['only_project'], $W['week_start']); $bd = br_doc((int)($W['only_project']['id'] ?? 0) ?: null);
    $navy = '#0B1E2D'; $gold = '#C89B3C'; $line = '#9AA5B1'; $ink = '#1F2937'; $head = '#EEF1F4'; $green = '#2F7D5C';
    $L = 15.0; $R = 195.0; $Wd = $R - $L; $y = 0.0; $tableNo = 0;
    $logo = $bd['logo'];
    $header = function () use ($pdf, $navy, $gold, $L, $R, $logo, $docNo, $W, &$y) {
        $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 17, mb_strtoupper(t('wv.title')));
        $pdf->setColor($gold); $pdf->setFont('R', 8.5); $pdf->text($L, 22, $docNo . ' · ' . $W['iso']);
        $pdf->image($logo, $R - 42, 9, 42, 15);
        $pdf->setColor('#D9DEE5'); $pdf->setLineWidth(0.3); $pdf->line($L, 26, $R, 26); $y = 31.0; if ($pdf->pageNo() === 1) $y += rc_legend_pdf($pdf, $L, 27.2, $R - $L);
    };
    $footer = function () use ($pdf, $ink, $L, $Wd, $docNo, $bd) { $pdf->setColor($ink); $pdf->setFont('R', 7); $pdf->text($L, 291, $bd['footer'] . ' · ' . $docNo . ' · ' . date('d.m.Y H:i')); $pdf->text($L, 291, t('rp.page') . ' ' . $pdf->pageNo(), 'R', $Wd); };
    $newPage = function () use ($pdf, $header, $footer) { $pdf->addPage(); $header(); $footer(); };
    $need = function (float $hh) use (&$y, $newPage) { if ($y + $hh > 276) $newPage(); };
    $hrow = function (array $cols) use ($pdf, $navy, $line, $head, $L, &$y) { $cols = rc_fit_cols($cols);
        $lh = 3.6; $pdf->setFont('B', 7.2); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap($lab, $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 2.0; $x = $L; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F');
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.4 + $lh * ($k + 1) - 0.8, $ln, $al, $cw - 3); $x += $cw; }
        $y += $hh;
    };
    $drow = function (array $cols, array $vals, bool $bold = false, ?string $tone = null, array $hl = []) use ($pdf, $ink, $line, $head, $L, &$y, $hrow, $newPage) { $cols = rc_fit_cols($cols);
        $lh = 3.8; $pdf->setFont($bold ? 'B' : 'R', 7.6); $lines = []; $n = 1;
        foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($vals[$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
        $hh = $n * $lh + 1.6;
        if ($y + $hh > 276) { $newPage(); $hrow($cols); $pdf->setFont($bold ? 'B' : 'R', 7.6); }
        $x = $L; if ($bold) { $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); }
        rc_bar_pdf($pdf, $tone, $x, $y, $hh);
        foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $c = isset($hl[$i]) && rc_hex($hl[$i]) ? '#' . rc_hex($hl[$i]) : $ink; $pdf->setColor($c); if (isset($hl[$i])) $pdf->setFont('B', 7.6); foreach ($lines[$i] as $k => $ln) $pdf->text($x + 1.5, $y + 1.2 + $lh * ($k + 1) - 0.9, $ln, $al, $cw - 3); if (isset($hl[$i])) { $pdf->setFont($bold ? 'B' : 'R', 7.6); } $x += $cw; }
        $y += $hh;
    };
    $table = function (array $T) use (&$tableNo, &$y, $need, $pdf, $navy, $L, $hrow, $drow) {
        $y += 2.5; $need(18); $tableNo++;
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $T['title']); $y += 6.2;
        $hrow($T['cols']);
        if (!$T['rows']) { $drow($T['cols'], [t('wv.nothing')]); return; }
        foreach ($T['rows'] as $r) $drow($T['cols'], $r['v'], !empty($r['b']), $r['tone'] ?? null, $r['hl'] ?? []);
    };
    $para = function (string $s, float $size = 8) use ($pdf, $ink, $L, $Wd, &$y, $need) { $pdf->setFont('R', $size); $need($pdf->paragraphHeight($Wd, $s, 3.9) + 3); $pdf->setColor($ink); $y += $pdf->paragraph($L, $y, $Wd, $s, 3.9) + 2; };
    $h1 = function (string $s) use ($pdf, $navy, $gold, $L, $Wd, &$y, $need) { $y += 4; $need(34); $pdf->setColor($navy); $pdf->setFont('B', 11); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.5); $pdf->line($L, $y + 6.5, $L + $Wd, $y + 6.5); $y += 9; };

    $header(); $footer();
    $kv = [[t('v.one'), $v['name'] . ($v['imo'] ? ' · IMO ' . $v['imo'] : '')], [t('v.customer'), (string)($v['customer_name'] ?: '—')], [t('wk.week'), $W['iso'] . ' · ' . fmt_date($W['week_start']) . ' – ' . fmt_date($W['week_end'])],
           [t('vh.scope'), ($W['only_project'] ? t('pr.one') . ' ' . ($W['only_project']['project_code'] ?: $W['only_project']['name']) : t('vh.all_projects') . ' (' . count($W['projects']) . ')') . ' · ' . t($W['staff'] ? 'vh.scope_internal' : 'vh.scope_external')],
           [t('rp.issued'), date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? '')]];
    foreach ($kv as [$k, $val]) $y += $pdf->kvCell($L, $y, $Wd, 45, $k, $val, ['minH' => 6, 'line' => $line, 'navy' => $navy, 'ink' => $ink]);   // R59-002: wraps
    $y += 3;
    // headline tiles: overall progress now vs last week, open offers, pending approvals, open tickets
    $ov = 0; $ob = 0; $np = max(1, count($W['projects'])); $pend = 0; $pendAmt = 0.0; $oaP = 0; $tkOpen = 0; $flags = 0;
    foreach ($W['projects'] as $P) { $ov += $P['overall_now']; $ob += $P['overall_base']; $pend += count($P['offers_pending']); $pendAmt += array_sum(array_map(fn($o) => (float)$o['amount'], $P['offers_pending'])); $oaP += count($P['oa_pending']); $tkOpen += count(array_filter($P['tickets'], fn($t) => in_array($t['status'], tk_open_statuses(), true))); $flags += count($P['flags']); }
    $tkOpen += count(array_filter($W['vessel_tickets'], fn($t) => in_array($t['status'], tk_open_statuses(), true)));
    $tiles = [[t('wv.k_progress'), wk_pct_delta((int) round($ov / $np), (int) round($ob / $np)), $navy], [t('wv.k_delays'), (string)$flags, $flags ? '#9B2C2C' : $green], [t('wv.k_offers'), $pend . ($pend ? ' · ' . of_money($pendAmt) : ''), $gold], [t('wv.k_oa'), (string)$oaP, $navy], [t('wv.k_tickets'), (string)$tkOpen, $green]];
    $tw = ($Wd - 12) / 5; $x = $L;
    foreach ($tiles as [$lab, $val, $col]) { $pdf->setColor($head); $pdf->rect($x, $y, $tw, 16, 'F'); $pdf->setColor($col); $fs = 12; $pdf->setFont('B', $fs); while ($pdf->width($val) > $tw - 2 && $fs > 7) { $fs -= 0.5; $pdf->setFont('B', $fs); } $pdf->text($x, $y + 8.5, $val, 'C', $tw); $pdf->setColor($ink); $fs = 6.5; $pdf->setFont('R', $fs); while ($pdf->width(mb_strtoupper($lab)) > $tw - 2 && $fs > 4.5) { $fs -= 0.25; $pdf->setFont('R', $fs); } $pdf->text($x, $y + 13.5, mb_strtoupper($lab), 'C', $tw); $x += $tw + 3; }
    $y += 20;
    foreach (wk_sections($W) as $sec) { $h1($sec['title']); foreach ($sec['tables'] as $T) $table($T); foreach ($sec['text'] as $t) $para($t); }
    // appendix: weekly-note photos
    $np = wk_note_photos($W);
    if ($np) {
        $h1(t('wv.notes_photos'));
        foreach ($np as $nn) {
            $need(16); $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, trim($nn['sub_code'] . ' ' . $nn['sub_name']) . ' · ' . fmt_date($nn['note_date']) . ' · ' . ($nn['author'] ?: '')); $y += 6;
            $para((string)$nn['body'], 7.6);
            $files = array_values(array_filter(array_slice($nn['photos'], 0, 8), fn($f) => is_file(cp_storage() . '/' . $f['stored_name'])));
            $pw = ($Wd - 9) / 4; $ph = $pw * 0.75; $x = $L; $i = 0;
            foreach ($files as $f) {
                if ($i % 4 === 0) { $need($ph + 6); $x = $L; if ($i) $y += $ph + 5; }
                $pdf->setColor($line); $pdf->rect($x, $y, $pw, $ph); $pdf->image(cp_storage() . '/' . $f['stored_name'], $x + 0.5, $y + 0.5, $pw - 1, $ph - 1); $pdf->setColor($ink); $pdf->setFont('R', 6); $pdf->text($x, $y + $ph + 3, mb_substr($f['title'] ?: $f['original_name'], 0, 34), 'C', $pw);
                $x += $pw + 3; $i++;
            }
            if ($files) $y += $ph + 7;
        }
    }
    // appendix: ticket communication + photos
    $det = wk_ticket_detail($W);
    if ($det) {
        $h1(t('wv.s9_detail'));
        foreach ($det as $t) {
            $need(16); $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, $t['ticket_no'] . ' — ' . $t['title'] . ' · ' . t('tk.st_' . $t['status'])); $y += 6;
            foreach ($t['comments_week'] as $c) $para(fmt_date(substr($c['created_at'], 0, 10)) . ' · ' . ($c['author'] ?: ($c['side'] === 'golden' ? 'Golden A/S' : t('role.customer'))) . ($c['visibility'] === 'internal' ? ' (' . t('tk.internal') . ')' : '') . ': ' . (string)$c['body'], 7.6);
            $files = array_values(array_filter(array_slice($t['photos'], 0, 8), fn($f) => is_file(cp_storage() . '/' . $f['stored_name'])));
            if ($files) {
                $pw = ($Wd - 9) / 4; $ph = $pw * 0.75; $x = $L; $i = 0;
                foreach ($files as $f) {
                    if ($i % 4 === 0) { $need($ph + 6); $x = $L; if ($i) $y += $ph + 5; }
                    $pdf->setColor($line); $pdf->rect($x, $y, $pw, $ph); $pdf->image(cp_storage() . '/' . $f['stored_name'], $x + 0.5, $y + 0.5, $pw - 1, $ph - 1); $pdf->setColor($ink); $pdf->setFont('R', 6); $pdf->text($x, $y + $ph + 3, mb_substr($f['original_name'], 0, 34) . ' · ' . fmt_date(substr($f['created_at'], 0, 10)), 'C', $pw);
                    $x += $pw + 3; $i++;
                }
                $y += $ph + 7;
            }
        }
    }
    return $pdf->bytes($docNo);
}

function wk_render_docx(array $W): string {
    require_once __DIR__ . '/docx.php';
    $w = new DocxWriter(); $v = $W['vessel']; $docNo = wk_doc_no($v, $W['only_project'], $W['week_start']); $bd = br_doc((int)($W['only_project']['id'] ?? 0) ?: null);
    $logo = (string) @file_get_contents($bd['logo']);
    $NAVY = '0B1E2D'; $HEAD = 'EEF1F4'; $tableNo = 0;
    $w->grid([[['lines' => [mb_strtoupper(t('wv.title')), $docNo . ' · ' . $W['iso']], 'b' => true, 'sz' => 14, 'color' => $NAVY], ['img' => [$logo, 4.2, 1.6], 'align' => 'right']]], [6400, 3240], ['border' => 'FFFFFF', 'sz' => 0, 'tight' => true]);
    rc_legend_docx($w);
    $kv = fn($l) => ['lines' => [$l], 'b' => true, 'sz' => 8]; $vv = fn($x) => ['text' => (string)$x, 'sz' => 9];
    $w->grid([[$kv(t('v.one')), $vv($v['name'] . ($v['imo'] ? ' · IMO ' . $v['imo'] : ''))], [$kv(t('v.customer')), $vv($v['customer_name'] ?: '—')], [$kv(t('wk.week')), $vv($W['iso'] . ' · ' . fmt_date($W['week_start']) . ' – ' . fmt_date($W['week_end']))],
              [$kv(t('vh.scope')), $vv(($W['only_project'] ? t('pr.one') . ' ' . ($W['only_project']['project_code'] ?: $W['only_project']['name']) : t('vh.all_projects') . ' (' . count($W['projects']) . ')') . ' · ' . t($W['staff'] ? 'vh.scope_internal' : 'vh.scope_external'))],
              [$kv(t('rp.issued')), $vv(date('d.m.Y H:i') . ' · ' . (current_user()['full_name'] ?? ''))]], [2400, 7240], ['border' => '9AA5B1']);
    $hdr = fn($x) => ['text' => $x, 'b' => true, 'sz' => 8, 'fill' => $HEAD, 'align' => 'center'];
    $c = fn($x, $al = 'left', $b = false) => ['text' => (string)$x, 'sz' => 8.5, 'align' => $al, 'b' => $b];
    foreach (wk_sections($W) as $sec) {
        $w->heading($sec['title'], 2);
        foreach ($sec['tables'] as $T) {
            $tableNo++;
            $w->para(t('rp.table') . ' ' . $tableNo . ' — ' . $T['title'], ['b' => true, 'align' => 'left', 'color' => $NAVY]);
            $tot = array_sum(array_column($T['cols'], 1)); $widths = array_map(fn($col) => (int) round(9640 * $col[1] / $tot), $T['cols']);
            $out = [array_map(fn($col) => $hdr($col[0]), $T['cols'])];
            if (!$T['rows']) $out[] = array_merge([$c(t('wv.nothing'))], array_fill(0, count($T['cols']) - 1, ''));
            $hasTone = (bool) array_filter($T['rows'], fn($r) => !empty($r['tone']));
            if ($hasTone) { array_unshift($out[0], $hdr('')); $widths = array_map(fn($x) => (int) round($x * 9460 / 9640), $widths); array_unshift($widths, 180); if (!$T['rows']) array_unshift($out[1], ''); }
            foreach ($T['rows'] as $r) { $cells = array_map(fn($i) => array_merge($c($r['v'][$i] ?? '', $T['cols'][$i][2] === 'R' ? 'right' : 'left', !empty($r['b'])), isset($r['hl'][$i]) && rc_hex($r['hl'][$i]) ? ['color' => rc_hex($r['hl'][$i]), 'b' => true] : []), array_keys($T['cols'])); if ($hasTone) array_unshift($cells, ['text' => '', 'fill' => rc_hex($r['tone'] ?? null) ?: 'FFFFFF']); $out[] = $cells; }
            $w->grid($out, $widths, ['border' => '9AA5B1']);
        }
        foreach ($sec['text'] as $t) $w->para($t, ['sz' => 9, 'i' => true]);
    }
    $np = wk_note_photos($W);
    if ($np) {
        $w->heading(t('wv.notes_photos'), 2);
        foreach ($np as $nn) {
            $w->para(trim($nn['sub_code'] . ' ' . $nn['sub_name']) . ' · ' . fmt_date($nn['note_date']) . ' · ' . ($nn['author'] ?: ''), ['b' => true, 'color' => $NAVY]);
            $w->para((string)$nn['body'], ['sz' => 9]);
            foreach (array_slice($nn['photos'], 0, 8) as $f) { $path = cp_storage() . '/' . $f['stored_name']; if (is_file($path)) { $bytes = wk_photo_jpeg($path); if ($bytes) { $sz = @getimagesizefromstring($bytes); $cw = 7.5; $ch = $sz && $sz[0] ? round($cw * $sz[1] / $sz[0], 2) : 5.0; $w->image($bytes, $cw, $ch, 'left'); $w->para(($f['title'] ?: $f['original_name']), ['sz' => 7, 'i' => true]); } } }
        }
    }
    $det = wk_ticket_detail($W);
    if ($det) {
        $w->heading(t('wv.s9_detail'), 2);
        foreach ($det as $t) {
            $w->para($t['ticket_no'] . ' — ' . $t['title'] . ' · ' . t('tk.st_' . $t['status']), ['b' => true, 'color' => $NAVY]);
            foreach ($t['comments_week'] as $cm) $w->para(fmt_date(substr($cm['created_at'], 0, 10)) . ' · ' . ($cm['author'] ?: '') . ($cm['visibility'] === 'internal' ? ' (' . t('tk.internal') . ')' : '') . ': ' . (string)$cm['body'], ['sz' => 9]);
            foreach (array_slice($t['photos'], 0, 8) as $f) { $path = cp_storage() . '/' . $f['stored_name']; if (is_file($path)) { $bytes = wk_photo_jpeg($path); if ($bytes) { $sz = @getimagesizefromstring($bytes); $cw = 7.5; $ch = $sz && $sz[0] ? round($cw * $sz[1] / $sz[0], 2) : 5.0; $w->image($bytes, $cw, $ch, 'left'); $w->para($f['original_name'] . ' · ' . fmt_date(substr($f['created_at'], 0, 10)), ['sz' => 7, 'i' => true]); } } }
        }
    }
    return $w->bytes($docNo, $bd['footer'] . ' · ' . $docNo);
}
/** JPEG bytes (≤ 900 px) for Word; GD when available, else the original if it is already a JPEG. */
function wk_photo_jpeg(string $path): ?string {
    $bytes = (string) @file_get_contents($path); if ($bytes === '') return null;
    $info = @getimagesizefromstring($bytes); if (!$info) return null;
    if (!function_exists('imagecreatefromstring')) return $info[2] === IMAGETYPE_JPEG ? $bytes : null;
    $im = @imagecreatefromstring($bytes); if (!$im) return null;
    $wd = imagesx($im); $ht = imagesy($im); $r = min(1, 900 / max($wd, $ht)); $nw = max(1, (int) round($wd * $r)); $nh = max(1, (int) round($ht * $r));
    $out = imagecreatetruecolor($nw, $nh); $white = imagecolorallocate($out, 255, 255, 255); imagefill($out, 0, 0, $white); imagecopyresampled($out, $im, 0, 0, 0, 0, $nw, $nh, $wd, $ht);
    ob_start(); imagejpeg($out, null, 82); $jpg = (string) ob_get_clean(); imagedestroy($im); imagedestroy($out);
    return $jpg;
}

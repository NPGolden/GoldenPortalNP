<?php
/**
 * Accounting register and accounting reports.                        GAS-CP-R38-001
 *
 * acc_lines    — every billable hour line of the company, registered once from its source
 *                (timesheet line, service-report hour line) with an accounting state:
 *                open → draft (in a report being prepared) → reported (issued, with accounting)
 *                → invoiced; or excluded (the PM decided not to invoice it, with a reason).
 *                A reported or invoiced line can never enter another report — the double-billing guard.
 * acc_reports  — GAS-AR-YYYY-NNN R0/R1 …: scope (vessel / project / sub-project), period, zone,
 *                header discount, totals, PDF + Excel, status draft → issued → sent → invoiced /
 *                partly_invoiced / returned. Accounting answers in the portal; the source documents'
 *                invoicing state follows (timesheets.invoicing, fsr_reports.invoicing).
 * Pricing: discipline → rate card (disciplines.rate_card, default by name) → rate item by hour type
 *          (normal / overtime / holiday / travel) in the zone (local / overseas); cost = discipline rate.
 */

const ACC_TYPES = ['normal', 'overtime', 'holiday', 'travel'];

function acc_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW TABLES LIKE 'acc_reports'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function acc_user_flag(string $col): bool { $u = current_user(); if (!$u || !acc_has()) return false; static $c = []; $k = $col . (int)$u['id']; if (!isset($c[$k])) $c[$k] = (bool) scalar("SELECT {$col} FROM customer_users WHERE id = ?", [(int)$u['id']]); return $c[$k]; }
/** May prepare accounting reports: administrators, accounts ticked "May prepare accounting reports", the PM of the project. */
function acc_can_prepare(?int $pid = null): bool {
    if (!acc_has() || !is_staff()) return false;
    if (is_cp_admin() || acc_user_flag('can_accounting')) return true;
    if ($pid) { $u = current_user(); return (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]) === (int)$u['id']; }
    return (bool) scalar("SELECT COUNT(*) FROM projects WHERE pm_user_id = ? AND status <> 'completed'", [(int)current_user()['id']]);
}
/** Answers as accounting (invoiced / returned): administrators and accounts ticked "Accounting". */
function acc_is_accounting(): bool { return acc_has() && is_staff() && (is_cp_admin() || acc_user_flag('is_accounting')); }
/** Sees the register and the reports at all. */
function acc_visible(): bool { return acc_has() && is_staff() && (acc_can_prepare() || acc_is_accounting() || fin_visible()); }
function acc_require(): void { if (!acc_visible()) { http_response_code(403); throw new RuntimeException(t('err.noaccess')); } }

/* ------------------------------------------------------------------ pricing */

function acc_card_of(?int $disciplineId): string {
    static $c = [];
    $k = (int)$disciplineId;
    if (!isset($c[$k])) {
        $d = $k ? one('SELECT name, rate_card FROM disciplines WHERE id = ?', [$k]) : null;
        $card = trim((string)($d['rate_card'] ?? ''));
        if ($card === '') { $n = strtolower((string)($d['name'] ?? '')); $card = str_contains($n, 'automation') ? 'D' : (str_contains($n, 'electric') ? 'L' : (str_contains($n, 'engineer') ? 'E' : (str_contains($n, 'navig') ? 'I' : 'A'))); }
        $c[$k] = $card;
    }
    return $c[$k];
}
/** Classify a rate item of the hourly section by its description. */
function acc_item_type(array $i): ?string {
    if ((string)$i['unit'] !== 'hour') return null;
    $d = strtolower((string)$i['description']);
    if (str_contains($d, 'travel')) return 'travel';
    if (preg_match('~saturday|sunday|holiday~', $d)) return 'holiday';
    if (str_contains($d, 'overtime')) return 'overtime';
    return 'normal';
}
/** The rate item for a card + hour type + zone on a rate list (local rows carry price_local, overseas rows price_overseas). */
function acc_rate_item(int $listId, string $card, string $type, string $zone): ?array {
    static $cache = [];
    $k = $listId . '|' . $card;
    if (!isset($cache[$k])) $cache[$k] = function_exists('rl_items') ? rl_items($listId, 'hourly', $card) : [];
    $best = null;
    foreach ($cache[$k] as $i) {
        if (acc_item_type($i) !== $type) continue;
        $p = $zone === 'overseas' ? $i['price_overseas'] : $i['price_local'];
        if ($p !== null) return $i;
        if ($best === null) $best = $i;
    }
    return $best;                                        // the other zone's row when the card has no row for this zone
}
function acc_item_price(?array $i, string $zone): ?float {
    if (!$i) return null;
    $p = $zone === 'overseas' ? $i['price_overseas'] : $i['price_local'];
    if ($p === null) $p = $zone === 'overseas' ? $i['price_local'] : $i['price_overseas'];
    return $p === null ? null : (float)$p;
}
/** Price one line in the context of its report (zone, branch rate list). Returns [rate_item_id, unit_price, cost_rate]. */
function acc_price_line(array $l, array $R): array {
    $zone = (string)($l['zone'] ?: $R['zone']);
    $list = function_exists('rl_current') ? rl_current((int)($R['branch_id'] ?? 0) ?: null) : null;
    $item = null;
    if (!empty($l['rate_item_id']) && function_exists('rl_item')) $item = rl_item((int)$l['rate_item_id']);
    if (!$item && $list) $item = acc_rate_item((int)$list['id'], acc_card_of($l['discipline_id'] ? (int)$l['discipline_id'] : null), (string)$l['hour_type'], $zone);
    $d = $l['discipline_id'] ? one('SELECT rate_normal, rate_overtime, rate_holiday FROM disciplines WHERE id = ?', [(int)$l['discipline_id']]) : null;
    $cost = function_exists('br_hour_cost') ? br_hour_cost($d, $l['hour_type'] === 'travel' ? 'normal' : (string)$l['hour_type'], (int)($R['project_id'] ?? 0) ?: null, (int)($R['subproject_id'] ?? 0) ?: null) : ($d ? (float)(($d['rate_' . ($l['hour_type'] === 'travel' ? 'normal' : $l['hour_type'])] ?? null) ?: ($d['rate_normal'] ?? 0)) : null);   // R40-002
    return [$item ? (int)$item['id'] : null, acc_item_price($item, $zone), $cost];
}

/* ------------------------------------------------------------------ sync from the sources */

/** Register every billable line that is not registered yet; drop open lines whose source vanished or went back to draft. */
function acc_sync(): int {
    if (!acc_has()) return 0;
    $n = 0;
    // timesheets: approved (billable) and invoiced (legacy marking → registered as invoiced so they are never billed again)
    $rows = all("SELECT l.id AS line_id, l.work_date, l.subproject_id, l.hour_type, l.hours, l.description, t.id AS ts_id, t.ts_no, t.user_id, t.user_name, t.discipline_id, t.project_id, t.status, t.invoice_ref, t.invoiced_at, p.vessel_id
                 FROM timesheet_lines l JOIN timesheets t ON t.id = l.timesheet_id LEFT JOIN projects p ON p.id = t.project_id
                 WHERE t.status IN ('approved','invoiced') AND l.hours > 0 AND NOT EXISTS (SELECT 1 FROM acc_lines a WHERE a.source = 'ts' AND a.source_id = l.id)");
    foreach ($rows as $r) {
        $inv = $r['status'] === 'invoiced';
        q("INSERT IGNORE INTO acc_lines (source, source_id, doc_id, doc_no, vessel_id, project_id, subproject_id, work_date, user_id, user_name, discipline_id, hour_type, hours, description, state, invoice_ref, invoiced_at)
           VALUES ('ts',?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
          [(int)$r['line_id'], (int)$r['ts_id'], $r['ts_no'], $r['vessel_id'], $r['project_id'], $r['subproject_id'], $r['work_date'], $r['user_id'], $r['user_name'], $r['discipline_id'],
           in_array($r['hour_type'], ACC_TYPES, true) ? $r['hour_type'] : 'normal', (float)$r['hours'], mb_substr((string)$r['description'], 0, 500) ?: null, $inv ? 'invoiced' : 'open', $inv ? $r['invoice_ref'] : null, $inv ? $r['invoiced_at'] : null]);
        $n++;
    }
    // service reports: submitted or accepted; one registered line per engineer per day and hour type (TT = travel; WT split normal / overtime by the engineer's calendar)
    $rows = all("SELECT l.id AS line_id, l.engineer, l.specialty, l.time_type, l.hours, l.start_time, l.end_time, r.id AS fsr_id, r.report_no, r.report_date, r.project_id, r.subproject_id, p.vessel_id
                 FROM fsr_lines l JOIN fsr_reports r ON r.id = l.report_id LEFT JOIN projects p ON p.id = r.project_id
                 WHERE r.status IN ('submitted','accepted') AND l.hours > 0 AND l.time_type <> 'NC' AND NOT EXISTS (SELECT 1 FROM acc_lines a WHERE a.source = 'fsr' AND a.source_id = l.id)" . (function_exists('fsr_ext_has') && fsr_ext_has() ? " AND r.kind = 'internal'" : '') . "
                 ORDER BY r.id, l.start_time, l.id");
    $usedNormal = [];                                    // normal hours already counted per engineer per report date
    $users = []; foreach (all("SELECT id, full_name, discipline_id, work_calendar FROM customer_users WHERE role IN ('staff','admin')") as $u) $users[mb_strtolower(trim((string)$u['full_name']))] = $u;
    foreach ($rows as $r) {
        $u = $users[mb_strtolower(trim((string)$r['engineer']))] ?? null;
        $did = $u['discipline_id'] ?? null;
        if (!$did && $r['specialty']) { $d = one('SELECT id FROM disciplines WHERE LOWER(name) LIKE ? LIMIT 1', ['%' . mb_strtolower(mb_substr((string)$r['specialty'], 0, 6)) . '%']); $did = $d['id'] ?? null; }
        $h = (float)$r['hours']; $parts = [];
        if ($r['time_type'] === 'TT') $parts['travel'] = $h;
        else {
            $cal = (string)($u['work_calendar'] ?? 'GR'); $day = function_exists('wc_has') && wc_has() ? wc_day($cal, (string)$r['report_date']) : ['workday' => (int)date('N', strtotime((string)$r['report_date'])) < 6, 'normal' => 8.0, 'off_type' => 'overtime'];
            if (!$day['workday']) $parts[$day['off_type'] === 'holiday' ? 'holiday' : 'overtime'] = $h;
            else {
                $k = ($u['id'] ?? $r['engineer']) . '|' . $r['report_date']; $used = $usedNormal[$k] ?? (float) scalar("SELECT COALESCE(SUM(hours),0) FROM acc_lines WHERE source = 'fsr' AND hour_type = 'normal' AND work_date = ? AND " . ($u ? 'user_id = ?' : 'user_name = ?'), [$r['report_date'], $u ? (int)$u['id'] : (string)$r['engineer']]);
                $norm = max(0.0, min($h, (float)$day['normal'] - $used)); $usedNormal[$k] = $used + $norm;
                if ($norm > 0) $parts['normal'] = $norm; if ($h - $norm > 0.001) $parts['overtime'] = round($h - $norm, 2);
            }
        }
        foreach ($parts as $type => $hh) {
            q("INSERT IGNORE INTO acc_lines (source, source_id, doc_id, doc_no, vessel_id, project_id, subproject_id, work_date, user_id, user_name, discipline_id, hour_type, hours, description, state)
               VALUES ('fsr',?,?,?,?,?,?,?,?,?,?,?,?,?,'open')",
              [(int)$r['line_id'], (int)$r['fsr_id'], $r['report_no'], $r['vessel_id'], $r['project_id'], $r['subproject_id'], $r['report_date'], $u['id'] ?? null, (string)$r['engineer'], $did, $type, $hh, mb_substr(trim((string)$r['specialty'] . ' ' . ($r['start_time'] ? substr((string)$r['start_time'], 0, 5) . '–' . substr((string)$r['end_time'], 0, 5) : '')), 0, 500) ?: null]);
            $n++;
        }
    }
    // sources gone or no longer billable → open / draft lines disappear (reported and invoiced ones stay as history)
    q("DELETE a FROM acc_lines a LEFT JOIN timesheet_lines l ON l.id = a.source_id LEFT JOIN timesheets t ON t.id = l.timesheet_id WHERE a.source = 'ts' AND a.state IN ('open','draft') AND (l.id IS NULL OR t.status NOT IN ('approved','invoiced'))");
    q("DELETE a FROM acc_lines a LEFT JOIN fsr_lines l ON l.id = a.source_id LEFT JOIN fsr_reports r ON r.id = l.report_id WHERE a.source = 'fsr' AND a.state IN ('open','draft') AND (l.id IS NULL OR r.status NOT IN ('submitted','accepted'))");
    return $n;
}

/* ------------------------------------------------------------------ register */

function acc_filters_from_request(array $g): array {
    $f = [];
    foreach (['vessel', 'project', 'sub', 'discipline', 'user'] as $k) $f[$k] = (int)($g[$k] ?? 0) ?: null;
    $f['state'] = in_array($g['state'] ?? '', ['open', 'draft', 'reported', 'invoiced', 'excluded'], true) ? $g['state'] : '';
    $f['type'] = in_array($g['type'] ?? '', ACC_TYPES, true) ? $g['type'] : '';
    $f['from'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['from'] ?? '')) ? $g['from'] : '';
    $f['to'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($g['to'] ?? '')) ? $g['to'] : '';
    $f['q'] = mb_substr(trim((string)($g['q'] ?? '')), 0, 80);
    return $f;
}
function acc_register(array $f): array {
    $w = ['1 = 1']; $a = [];
    if ($f['vessel']) { $w[] = 'a.vessel_id = ?'; $a[] = $f['vessel']; }
    if ($f['project']) { $w[] = 'a.project_id = ?'; $a[] = $f['project']; }
    if ($f['sub']) { $w[] = 'a.subproject_id = ?'; $a[] = $f['sub']; }
    if ($f['discipline']) { $w[] = 'a.discipline_id = ?'; $a[] = $f['discipline']; }
    if ($f['user']) { $w[] = 'a.user_id = ?'; $a[] = $f['user']; }
    if ($f['state']) { $w[] = 'a.state = ?'; $a[] = $f['state']; }
    if ($f['type']) { $w[] = 'a.hour_type = ?'; $a[] = $f['type']; }
    if ($f['from']) { $w[] = 'a.work_date >= ?'; $a[] = $f['from']; }
    if ($f['to']) { $w[] = 'a.work_date <= ?'; $a[] = $f['to']; }
    if ($f['q'] !== '') { $w[] = '(a.doc_no LIKE ? OR a.user_name LIKE ? OR a.description LIKE ? OR a.invoice_ref LIKE ? OR r.report_no LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($a, $like, $like, $like, $like, $like); }
    if (!is_cp_admin() && !acc_user_flag('can_accounting') && !acc_is_accounting()) { $w[] = 'p.pm_user_id = ?'; $a[] = (int)current_user()['id']; }   // a PM sees his projects
    return all("SELECT a.*, v.name AS vessel, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, d.name AS discipline, r.report_no, r.revision AS report_rev, r.status AS report_status
                FROM acc_lines a LEFT JOIN vessels v ON v.id = a.vessel_id LEFT JOIN projects p ON p.id = a.project_id LEFT JOIN subprojects s ON s.id = a.subproject_id LEFT JOIN disciplines d ON d.id = a.discipline_id LEFT JOIN acc_reports r ON r.id = a.report_id
                WHERE " . implode(' AND ', $w) . ' ORDER BY a.work_date DESC, a.user_name, a.id DESC', $a);
}
function acc_state_pill(string $s): string { return pill(['open' => 'gold', 'draft' => 'navy', 'reported' => 'navy', 'invoiced' => 'green', 'excluded' => 'mute'][$s] ?? 'mute', t('acc.st_' . $s)); }
function acc_status_pill(string $s): string { return pill(['draft' => 'mute', 'issued' => 'gold', 'sent' => 'navy', 'returned' => 'bad', 'partly_invoiced' => 'gold', 'invoiced' => 'green'][$s] ?? 'mute', t('acc.rs_' . $s)); }
function acc_h(float $h): string { return rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.'); }
function acc_m(?float $v, string $cur = 'EUR'): string { return $v === null ? '—' : number_format($v, 2, ',', '.') . ' ' . $cur; }

/* ------------------------------------------------------------------ reports */

function acc_event(int $rid, string $event, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO acc_events (report_id, event, user_id, user_name, note) VALUES (?,?,?,?,?)', [$rid, $event, (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $note !== null ? mb_substr($note, 0, 1000) : null]);
}
function acc_number(?int $branchId): string {
    $pre = function_exists('br_get') && function_exists('br_has') && br_has() ? (string)(br_get($branchId)['doc_prefix'] ?? 'GAS') : 'GAS';
    $base = $pre . '-AR-' . date('Y') . '-';
    $n = (int) scalar("SELECT MAX(CAST(SUBSTRING(report_no, ?) AS UNSIGNED)) FROM acc_reports WHERE report_no LIKE ?", [strlen($base) + 1, $base . '%']) + 1;
    return $base . sprintf('%03d', $n);
}
function acc_reports(array $f = []): array {
    $w = ['1 = 1']; $a = [];
    if (!empty($f['vessel'])) { $w[] = 'r.vessel_id = ?'; $a[] = (int)$f['vessel']; }
    if (!empty($f['project'])) { $w[] = 'r.project_id = ?'; $a[] = (int)$f['project']; }
    if (!empty($f['status'])) { $w[] = 'r.status = ?'; $a[] = $f['status']; }
    if (!is_cp_admin() && !acc_user_flag('can_accounting') && !acc_is_accounting()) { $w[] = '(p.pm_user_id = ? OR r.created_by = ?)'; $uid = (int)current_user()['id']; array_push($a, $uid, $uid); }
    return all("SELECT r.*, v.name AS vessel, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, (SELECT COUNT(*) FROM acc_lines a WHERE a.report_id = r.id AND a.state <> 'excluded') AS n_lines
                FROM acc_reports r LEFT JOIN vessels v ON v.id = r.vessel_id LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN subprojects s ON s.id = r.subproject_id
                WHERE " . implode(' AND ', $w) . ' ORDER BY r.id DESC', $a);
}
function acc_load(int $id): ?array {
    if (!acc_has()) return null;
    $R = one('SELECT r.*, v.name AS vessel, p.project_code, p.name AS project_name, p.pm_user_id, p.customer_id, c.name AS customer_name, s.code AS sub_code, s.name AS sub_name
              FROM acc_reports r LEFT JOIN vessels v ON v.id = r.vessel_id LEFT JOIN projects p ON p.id = r.project_id LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN subprojects s ON s.id = r.subproject_id WHERE r.id = ?', [$id]);
    if (!$R) return null;
    if (!$R['customer_id'] && $R['vessel_id']) { $vv = one('SELECT v.customer_id, c.name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ?', [(int)$R['vessel_id']]); $R['customer_id'] = $vv['customer_id'] ?? null; $R['customer_name'] = $vv['name'] ?? null; }
    $R['lines'] = all('SELECT a.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, d.name AS discipline, i.description AS rate_desc, i.card AS rate_card
                       FROM acc_lines a LEFT JOIN projects p ON p.id = a.project_id LEFT JOIN subprojects s ON s.id = a.subproject_id LEFT JOIN disciplines d ON d.id = a.discipline_id LEFT JOIN rate_items i ON i.id = a.rate_item_id
                       WHERE a.report_id = ? ORDER BY p.project_code, s.sort_order, s.code, d.sort_order, a.work_date, a.user_name, FIELD(a.hour_type,"normal","overtime","holiday","travel")', [$id]);
    $R['events'] = all('SELECT * FROM acc_events WHERE report_id = ? ORDER BY id', [$id]);
    $R['can_edit'] = $R['status'] === 'draft' && acc_can_prepare((int)$R['project_id'] ?: null);
    return $R;
}
/** Create a draft report for a scope and period: every open line in scope joins it and is priced. */
function acc_create_from_post(): int {
    require_staff(); if (!acc_has()) throw new RuntimeException(t('acc.need_db'));
    $vid = (int)($_POST['vessel_id'] ?? 0) ?: null; $pid = (int)($_POST['project_id'] ?? 0) ?: null; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['from'] ?? '')) ? $_POST['from'] : null; $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['to'] ?? '')) ? $_POST['to'] : null;
    $zone = ($_POST['zone'] ?? '') === 'overseas' ? 'overseas' : 'local';
    acc_sync();
    return acc_create($vid, $pid, $sid, $from, $to, $zone, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000) ?: null);
}
/** Create a draft report for a scope and period: every open line in scope joins it and is priced. Returns the id (0 when nothing is open there). */
function acc_create(?int $vid, ?int $pid, ?int $sid, ?string $from, ?string $to, string $zone = 'local', ?string $note = null, bool $skipEmpty = false): int {
    if ($sid) { $sp = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) throw new RuntimeException(t('err.notfound')); $pid = (int)$sp['project_id']; }
    if ($pid) { $p = one('SELECT vessel_id FROM projects WHERE id = ?', [$pid]); if (!$p) throw new RuntimeException(t('err.notfound')); $vid = $vid ?: ((int)$p['vessel_id'] ?: null); }
    if (!$vid && !$pid) throw new RuntimeException(t('acc.need_scope'));
    if (!acc_can_prepare($pid)) throw new RuntimeException(t('err.noaccess'));
    $w = ["a.state = 'open'"]; $a = [];
    if ($sid) { $w[] = 'a.subproject_id = ?'; $a[] = $sid; } elseif ($pid) { $w[] = 'a.project_id = ?'; $a[] = $pid; } else { $w[] = 'a.vessel_id = ?'; $a[] = $vid; }
    if ($from) { $w[] = 'a.work_date >= ?'; $a[] = $from; } if ($to) { $w[] = 'a.work_date <= ?'; $a[] = $to; }
    if ($skipEmpty && !(int) scalar('SELECT COUNT(*) FROM acc_lines a WHERE ' . implode(' AND ', $w), $a)) return 0;
    $u = current_user(); $b = function_exists('br_of') ? br_of($pid) : [];
    q('INSERT INTO acc_reports (report_no, revision, branch_id, vessel_id, project_id, subproject_id, period_start, period_end, zone, created_by, created_name, note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
      ['DRAFT-' . bin2hex(random_bytes(3)), 'R0', (int)($b['id'] ?? 0) ?: null, $vid, $pid, $sid, $from, $to, $zone, (int)$u['id'], (string)$u['full_name'], $note]);
    $id = (int) db()->lastInsertId();
    q("UPDATE acc_lines a SET a.state = 'draft', a.report_id = ? WHERE " . implode(' AND ', $w), array_merge([$id], $a));
    acc_reprice($id, true);
    acc_event($id, 'created', ($vid ? 'V' . $vid : '') . ($pid ? ' P' . $pid : '') . ($sid ? ' S' . $sid : ''));
    return $id;
}
/** The weekly run: one draft report per vessel that has open lines (up to $to), for the vessels the person may prepare. Returns the ids. */
function acc_create_all(?string $to, string $zone = 'local'): array {
    acc_sync(); $ids = [];
    foreach (acc_open_by_vessel() as $v) {
        if (!$v['can']) continue;
        $id = acc_create((int)$v['vessel_id'] ?: null, $v['vessel_id'] ? null : ((int)$v['project_id'] ?: null), null, null, $to, $zone, t('acc.weekly_note', ['d' => fmt_date(date('Y-m-d'))]), true);
        if ($id) $ids[] = $id;
    }
    return $ids;
}
/** Open hours per vessel (lines without vessel grouped per project): what is waiting to be reported.   R38-002 */
function acc_open_by_vessel(): array {
    if (!acc_has()) return [];
    $rows = all("SELECT a.vessel_id, IF(a.vessel_id IS NULL, a.project_id, NULL) AS project_id, v.name AS vessel, MAX(p.project_code) AS project_code, MAX(p.name) AS project_name, COUNT(*) n, COALESCE(SUM(a.hours),0) h, MIN(a.work_date) oldest, MAX(a.work_date) newest,
                       SUM(a.source = 'ts') n_ts, SUM(a.source = 'fsr') n_fsr, COUNT(DISTINCT a.project_id) n_projects, GROUP_CONCAT(DISTINCT p.pm_user_id) pms
                  FROM acc_lines a LEFT JOIN vessels v ON v.id = a.vessel_id LEFT JOIN projects p ON p.id = a.project_id
                 WHERE a.state = 'open' GROUP BY a.vessel_id, IF(a.vessel_id IS NULL, a.project_id, NULL) ORDER BY oldest, v.name");
    $uid = (int)(current_user()['id'] ?? 0); $all = is_cp_admin() || acc_user_flag('can_accounting');
    foreach ($rows as &$r) { $r['can'] = $all || in_array((string)$uid, explode(',', (string)$r['pms']), true); $r['age'] = (int) floor((time() - strtotime((string)$r['oldest'])) / 86400); }
    unset($r);
    return $rows;
}
/** Price every line of a report (all lines, or only those without a price) and recompute the totals. */
function acc_reprice(int $id, bool $all = false): void {
    $R = one('SELECT * FROM acc_reports WHERE id = ?', [$id]); if (!$R) return;
    foreach (all('SELECT * FROM acc_lines WHERE report_id = ?' . ($all ? '' : ' AND unit_price IS NULL'), [$id]) as $l) {
        if (!$all && $l['unit_price'] !== null) continue;
        [$iid, $price, $cost] = acc_price_line($l, $R);
        q('UPDATE acc_lines SET rate_item_id = ?, unit_price = ?, cost_rate = ? WHERE id = ?', [$iid, $price, $cost, (int)$l['id']]);
    }
    acc_recalc($id);
}
function acc_recalc(int $id): void {
    $R = one('SELECT * FROM acc_reports WHERE id = ?', [$id]); if (!$R) return;
    $hd = (float)$R['header_discount_pct']; $hours = 0.0; $gross = 0.0; $net = 0.0;
    foreach (all("SELECT * FROM acc_lines WHERE report_id = ? AND state <> 'excluded'", [$id]) as $l) {
        $g = (float)$l['hours'] * (float)($l['unit_price'] ?? 0); $v = $g * (1 - (float)$l['discount_pct'] / 100);
        q('UPDATE acc_lines SET value = ? WHERE id = ?', [round($v, 2), (int)$l['id']]);
        $hours += (float)$l['hours']; $gross += $g; $net += $v;
    }
    $net *= (1 - $hd / 100);
    q('UPDATE acc_reports SET total_hours = ?, total_gross = ?, total_net = ? WHERE id = ?', [round($hours, 2), round($gross, 2), round($net, 2), $id]);
}
/** The PM's review: include / exclude, discounts, zone and rate overrides per line; header discount, zone, note on the report. */
function acc_lines_save_from_post(int $id): void {
    $R = acc_load($id); if (!$R || !$R['can_edit']) throw new RuntimeException(t('err.noaccess'));
    $zone = ($_POST['zone'] ?? $R['zone']) === 'overseas' ? 'overseas' : 'local';
    $hd = max(0.0, min(100.0, (float) str_replace(',', '.', (string)($_POST['header_discount_pct'] ?? '0'))));
    $zoneChanged = $zone !== $R['zone'];
    q('UPDATE acc_reports SET zone = ?, header_discount_pct = ?, note = ?, period_start = ?, period_end = ? WHERE id = ?',
      [$zone, $hd, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 1000) ?: null,
       preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['from'] ?? '')) ? $_POST['from'] : $R['period_start'], preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['to'] ?? '')) ? $_POST['to'] : $R['period_end'], $id]);
    $inc = (array)($_POST['inc'] ?? []); $disc = (array)($_POST['disc'] ?? []); $why = (array)($_POST['why'] ?? []); $lz = (array)($_POST['lz'] ?? []); $ri = (array)($_POST['ri'] ?? []);
    foreach ($R['lines'] as $l) {
        $lid = (int)$l['id']; if (!array_key_exists($lid, $inc) && !array_key_exists($lid, $disc)) continue;
        $include = (string)($inc[$lid] ?? '1') !== '0';
        $d = max(0.0, min(100.0, (float) str_replace(',', '.', (string)($disc[$lid] ?? '0'))));
        $z = ($lz[$lid] ?? '') === 'overseas' ? 'overseas' : (($lz[$lid] ?? '') === 'local' ? 'local' : null);
        $r = (int)($ri[$lid] ?? 0) ?: null;
        if (($zoneChanged || $z !== $l['zone']) && $r === ((int)$l['rate_item_id'] ?: null)) $r = null;   // zone changed and no new pick → price again automatically
        $reason = mb_substr(trim((string)($why[$lid] ?? '')), 0, 255) ?: null;
        $reprice = $zoneChanged || $z !== $l['zone'] || $r !== ((int)$l['rate_item_id'] ?: null);
        q('UPDATE acc_lines SET state = ?, discount_pct = ?, discount_reason = ?, exclude_reason = ?, zone = ?, rate_item_id = ?' . ($reprice ? ', unit_price = NULL' : '') . ' WHERE id = ? AND report_id = ?',
          [$include ? 'draft' : 'excluded', $include ? $d : 0, $include ? $reason : null, $include ? null : $reason, $z, $r ?: ($reprice ? null : $l['rate_item_id']), $lid, $id]);
    }
    acc_reprice($id, false);
    acc_event($id, 'saved');
}
/** Put a line back (excluded → draft) or release it from a draft report (→ open). */
function acc_line_release(int $id, int $lid): void {
    $R = acc_load($id); if (!$R || !$R['can_edit']) throw new RuntimeException(t('err.noaccess'));
    q("UPDATE acc_lines SET state = 'open', report_id = NULL, discount_pct = 0, discount_reason = NULL, exclude_reason = NULL, value = NULL WHERE id = ? AND report_id = ? AND state IN ('draft','excluded')", [$lid, $id]);
    acc_recalc($id);
}
function acc_delete_draft(int $id): void {
    $R = acc_load($id); if (!$R || $R['status'] !== 'draft' || !$R['can_edit']) throw new RuntimeException(t('err.noaccess'));
    q("UPDATE acc_lines SET state = 'open', report_id = NULL, discount_pct = 0, discount_reason = NULL, exclude_reason = NULL, value = NULL, unit_price = NULL, rate_item_id = NULL WHERE report_id = ?", [$id]);
    q('DELETE FROM acc_events WHERE report_id = ?', [$id]); q('DELETE FROM acc_reports WHERE id = ?', [$id]);
}
/** Budget vs invoiced per sub-project › discipline for the report's lines. */
function acc_budget_rows(array $R): array {
    if (!function_exists('bg_has') || !bg_has()) return [];
    $out = [];
    foreach ($R['lines'] as $l) { if ($l['state'] === 'excluded' || !$l['subproject_id']) continue; $k = (int)$l['subproject_id'] . '|' . (int)$l['discipline_id']; $out[$k] ??= ['sub' => trim($l['sub_code'] . ' ' . $l['sub_name']), 'subproject_id' => (int)$l['subproject_id'], 'discipline_id' => (int)$l['discipline_id'], 'discipline' => (string)($l['discipline'] ?: t('bg.no_discipline')), 'here' => 0.0, 'budget' => null, 'invoiced' => 0.0]; $out[$k]['here'] += (float)$l['hours']; }
    foreach ($out as $k => &$r) {
        $b = bg_current($r['subproject_id']); $r['budget'] = $b ? (float)($b['hours'][$r['discipline_id']]['hours'] ?? 0) : null;
        $r['invoiced'] = (float) scalar("SELECT COALESCE(SUM(hours),0) FROM acc_lines WHERE subproject_id = ? AND discipline_id <=> ? AND state = 'invoiced'", [$r['subproject_id'], $r['discipline_id'] ?: null]);
        $r['after'] = $r['invoiced'] + $r['here']; $r['over'] = $r['budget'] !== null && $r['budget'] > 0 && $r['after'] > $r['budget'];
    }
    unset($r);
    return array_values($out);
}
/** Issue: number (first time), revision, PDF + Excel archived, lines → reported, source documents → sent to accounting. */
function acc_issue(int $id): array {
    $R = acc_load($id); if (!$R || !acc_can_prepare((int)$R['project_id'] ?: null)) throw new RuntimeException(t('err.noaccess'));
    if (!in_array($R['status'], ['draft', 'returned'], true)) throw new RuntimeException(t('acc.not_draft'));
    $inc = array_values(array_filter($R['lines'], fn($l) => $l['state'] !== 'excluded'));
    if (!$inc) throw new RuntimeException(t('acc.nothing_to_issue'));
    foreach ($inc as $l) if ($l['unit_price'] === null) throw new RuntimeException(t('acc.unpriced', ['n' => $l['user_name'] . ' ' . fmt_date($l['work_date'])]));
    if (str_starts_with((string)$R['report_no'], 'DRAFT-')) { $R['report_no'] = acc_number((int)$R['branch_id'] ?: null); $R['revision'] = 'R0'; }
    elseif ($R['status'] === 'returned') $R['revision'] = 'R' . ((int) substr((string)$R['revision'], 1) + 1);
    acc_recalc($id); $R = array_merge($R, one('SELECT total_hours, total_gross, total_net FROM acc_reports WHERE id = ?', [$id]) ?: []);
    $pid = (int)$R['project_id'] ?: (function_exists('ob_general_project') && $R['vessel_id'] ? ob_general_project((int)$R['vessel_id']) : 0);
    $R['status'] = 'issued'; $pdf = acc_render_pdf($R); $xlsx = acc_render_xlsx($R);
    $pdfId = cp_store_bytes($pdf, $R['report_no'] . '_' . $R['revision'] . '.pdf', 'application/pdf', $pid, $R['subproject_id'] ? (int)$R['subproject_id'] : null, 'issued', 'internal', null, $R['report_no'] . ' ' . t('acc.report'), ['doc_no' => $R['report_no'], 'revision' => $R['revision']]);
    $xlsId = cp_store_bytes($xlsx, $R['report_no'] . '_' . $R['revision'] . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $pid, $R['subproject_id'] ? (int)$R['subproject_id'] : null, 'issued', 'internal', null, $R['report_no'] . ' ' . t('acc.report'), ['doc_no' => $R['report_no'], 'revision' => $R['revision']]);
    $u = current_user();
    q("UPDATE acc_reports SET report_no = ?, revision = ?, status = 'issued', pdf_file_id = ?, xlsx_file_id = ?, issued_at = NOW(), issued_by = ?, issued_name = ? WHERE id = ?", [$R['report_no'], $R['revision'], $pdfId, $xlsId, (int)$u['id'], (string)$u['full_name'], $id]);
    q("UPDATE acc_lines SET state = 'reported' WHERE report_id = ? AND state = 'draft'", [$id]);
    acc_cascade_docs($id);
    acc_event($id, 'issued', $R['report_no'] . ' ' . $R['revision'] . ' · ' . acc_m((float)$R['total_net'], $R['currency']));
    return ['report_no' => $R['report_no'], 'revision' => $R['revision'], 'pdf' => $pdfId, 'xlsx' => $xlsId];
}
function acc_mark_sent(int $id, string $to): void {
    if (!acc_has()) return;
    q("UPDATE acc_reports SET status = IF(status = 'issued', 'sent', status), sent_at = NOW(), sent_to = ? WHERE id = ?", [mb_substr($to, 0, 190), $id]);
    acc_event($id, 'sent', $to);
}
/** Accounting's answer: invoiced (all reported lines), partly (ticked lines), returned (lines go back to open). */
function acc_answer_from_post(int $id): void {
    if (!acc_is_accounting()) throw new RuntimeException(t('err.noaccess'));
    $R = acc_load($id); if (!$R || !in_array($R['status'], ['issued', 'sent', 'partly_invoiced'], true)) throw new RuntimeException(t('acc.not_answerable'));
    $kind = (string)($_POST['answer'] ?? ''); $ref = mb_substr(trim((string)($_POST['invoice_ref'] ?? '')), 0, 120); $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['invoice_date'] ?? '')) ? $_POST['invoice_date'] : date('Y-m-d');
    $note = mb_substr(trim((string)($_POST['answer_note'] ?? '')), 0, 1000) ?: null; $u = current_user();
    if ($kind === 'invoiced' || $kind === 'partly') {
        if ($ref === '') throw new RuntimeException(t('ts.ref_req'));
        $ids = $kind === 'partly' ? array_map('intval', (array)($_POST['inv'] ?? [])) : array_map(fn($l) => (int)$l['id'], array_filter($R['lines'], fn($l) => $l['state'] === 'reported'));
        if (!$ids) throw new RuntimeException(t('acc.no_lines_ticked'));
        q("UPDATE acc_lines SET state = 'invoiced', invoice_ref = ?, invoiced_at = ? WHERE report_id = ? AND state = 'reported' AND id IN (" . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([$ref, $date . ' 00:00:00', $id], $ids));
        $left = (int) scalar("SELECT COUNT(*) FROM acc_lines WHERE report_id = ? AND state = 'reported'", [$id]);
        $st = $left ? 'partly_invoiced' : 'invoiced';
        $refs = array_values(array_unique(array_filter(array_merge(explode(', ', (string)$R['invoice_ref']), [$ref]))));
        q('UPDATE acc_reports SET status = ?, invoice_ref = ?, invoice_date = ?, answered_at = NOW(), answered_by = ?, answered_name = ?, answer_note = ? WHERE id = ?', [$st, mb_substr(implode(', ', $refs), 0, 120), $date, (int)$u['id'], (string)$u['full_name'], $note, $id]);
        acc_event($id, $st, $ref . ($note ? ' · ' . $note : ''));
    } elseif ($kind === 'returned') {
        if ($note === null) throw new RuntimeException(t('acc.need_note'));
        q("UPDATE acc_lines SET state = 'draft' WHERE report_id = ? AND state = 'reported'", [$id]);    // back to the PM, same report, next revision
        q("UPDATE acc_reports SET status = 'returned', answered_at = NOW(), answered_by = ?, answered_name = ?, answer_note = ? WHERE id = ?", [(int)$u['id'], (string)$u['full_name'], $note, $id]);
        acc_event($id, 'returned', $note);
    } else throw new RuntimeException(t('err.notfound'));
    acc_cascade_docs($id);
    acc_notify($id, $kind);
}
/** The invoicing state of every timesheet / service report touched by a report follows its registered lines. */
function acc_cascade_docs(int $id): void {
    foreach (all('SELECT DISTINCT source, doc_id FROM acc_lines WHERE report_id = ?', [$id]) as $d) {
        $st = all("SELECT state, invoice_ref FROM acc_lines WHERE source = ? AND doc_id = ? AND state <> 'excluded'", [$d['source'], (int)$d['doc_id']]);
        $states = array_column($st, 'state'); $n = count($states); $inv = count(array_filter($states, fn($s) => $s === 'invoiced')); $rep = count(array_filter($states, fn($s) => $s === 'reported'));
        $v = $n && $inv === $n ? 'invoiced' : ($inv > 0 ? 'partly' : ($rep > 0 ? 'sent' : 'none'));
        $refs = implode(', ', array_unique(array_filter(array_column($st, 'invoice_ref'))));
        if ($d['source'] === 'ts') {
            q('UPDATE timesheets SET invoicing = ?, acc_report_id = ? WHERE id = ?', [$v, $v === 'none' ? null : $id, (int)$d['doc_id']]);
            if ($v === 'invoiced') { $u = current_user(); q("UPDATE timesheets SET status = 'invoiced', invoice_ref = ?, invoiced_by = ?, invoiced_name = ?, invoiced_at = COALESCE(invoiced_at, NOW()) WHERE id = ? AND status = 'approved'", [mb_substr($refs, 0, 60), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), (int)$d['doc_id']]); if (function_exists('ts_event')) try { ts_event((int)$d['doc_id'], 'invoiced', $refs); } catch (Throwable $e) {} }
        } else q('UPDATE fsr_reports SET invoicing = ?, acc_report_id = ? WHERE id = ?', [$v, $v === 'none' ? null : $id, (int)$d['doc_id']]);
    }
}
function acc_notify(int $id, string $kind): void {
    if (!function_exists('nf_push')) return;
    $R = one('SELECT r.*, p.pm_user_id FROM acc_reports r LEFT JOIN projects p ON p.id = r.project_id WHERE r.id = ?', [$id]); if (!$R) return;
    $to = array_values(array_unique(array_filter([(int)$R['created_by'], (int)($R['pm_user_id'] ?? 0), (int)($R['issued_by'] ?? 0)])));
    $u = current_user();
    nf_push($to, 'acc:' . $kind, 'acc:' . $id, $R['report_no'] . ' ' . $R['revision'], (string)($R['answer_note'] ?: $R['invoice_ref'] ?: ''), url('acc_report', ['id' => $id]), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''));
}
/** Open lines waiting in the register (for the dashboard / the reports list). */
function acc_open_summary(): array { return acc_has() ? (one("SELECT COUNT(*) n, COALESCE(SUM(hours),0) h FROM acc_lines WHERE state = 'open'") ?: ['n' => 0, 'h' => 0]) : ['n' => 0, 'h' => 0]; }
function acc_type_label(string $t): string { return t('acc.t_' . $t); }

/* ------------------------------------------------------------------ documents */

function acc_group_lines(array $R): array {
    $g = [];
    foreach ($R['lines'] as $l) { if ($l['state'] === 'excluded') continue; $k1 = trim(($l['project_code'] ?: '') . ' ' . ($l['project_name'] ?: '')) ?: t('tk.generic'); $k2 = trim(($l['sub_code'] ?: '') . ' ' . ($l['sub_name'] ?: '')) ?: t('fsr.whole_project'); $k3 = (string)($l['discipline'] ?: t('bg.no_discipline')); $g[$k1][$k2][$k3][] = $l; }
    return $g;
}
function acc_summary_rows(array $R): array {
    $out = [];
    foreach ($R['lines'] as $l) { if ($l['state'] === 'excluded') continue; $k = trim(($l['sub_code'] ?: '') . ' ' . ($l['sub_name'] ?: '')) . '|' . ($l['discipline'] ?: t('bg.no_discipline')); $out[$k] ??= ['sub' => trim(($l['sub_code'] ?: '') . ' ' . ($l['sub_name'] ?: '')) ?: t('fsr.whole_project'), 'discipline' => (string)($l['discipline'] ?: t('bg.no_discipline')), 'normal' => 0.0, 'overtime' => 0.0, 'holiday' => 0.0, 'travel' => 0.0, 'hours' => 0.0, 'value' => 0.0]; $out[$k][$l['hour_type']] += (float)$l['hours']; $out[$k]['hours'] += (float)$l['hours']; $out[$k]['value'] += (float)$l['value']; }
    return array_values($out);
}
function acc_render_pdf(array $R): string {
    require_once __DIR__ . '/pdf.php';
    $pdf = new PdfWriter(); $navy = '#0B1E2D'; $gold = '#C89B3C'; $ink = '#1F2933'; $line = '#C8CED6'; $head = '#EEF2F6';
    $L = 15.0; $Rr = 195.0; $W = $Rr - $L; $y = 0.0; $tableNo = 0; $cur = $R['currency'] ?: 'EUR';
    $bd = function_exists('br_doc') ? br_doc((int)$R['project_id'] ?: null, null, function_exists('br_get') && !empty($R['branch_id']) ? br_get((int)$R['branch_id']) : null) : ['logo' => null, 'name' => 'Golden Automation Systems', 'footer' => '', 'lines' => []];
    $docNo = $R['report_no'] . ' ' . $R['revision'];
    $scope = trim(($R['vessel'] ?: '') . ($R['project_code'] ? ' · ' . $R['project_code'] . ' ' . $R['project_name'] : '') . ($R['sub_code'] ? ' · ' . $R['sub_code'] . ' ' . $R['sub_name'] : ''), ' ·');
    $header = function () use ($pdf, &$y, $navy, $gold, $L, $Rr, $bd, $docNo, $scope) { $pdf->addPage(); if (!empty($bd['logo'])) $pdf->image($bd['logo'], 150, 12, 45, 16); $pdf->setColor($navy); $pdf->setFont('B', 15); $pdf->text($L, 20, mb_strtoupper(t('acc.report'))); $pdf->setFont('R', 8.5); $pdf->setColor($gold); $pdf->text($L, 26, $docNo . ' · ' . $scope . ' · ' . t('bg.internal') . ' · ' . date('d.m.Y')); $pdf->setLineWidth(0.6); $pdf->line($L, 30, $Rr, 30); $y = 36; };
    $footer = function () use ($pdf, $L, $Rr, $bd, $docNo) { $pdf->setFont('R', 7); $pdf->setColor('#6a7179'); $pdf->text($L, 290, ($bd['footer'] ?: $bd['name']) . ' · ' . $docNo . ' · ' . t('bg.internal')); $pdf->text($Rr, 290, (string)$pdf->pageNo(), 'R'); };
    $need = function (float $hh) use (&$y, $header, $footer) { if ($y + $hh > 276) { $footer(); $header(); } };
    $h1 = function (string $s) use ($pdf, $navy, $gold, $L, $Rr, &$y, $need) { $y += 3; $need(20); $pdf->setColor($navy); $pdf->setFont('B', 10.5); $pdf->text($L, $y + 4.5, $s); $pdf->setColor($gold); $pdf->setLineWidth(0.4); $pdf->line($L, $y + 6.5, $Rr, $y + 6.5); $y += 10; };
    $table = function (string $title, array $cols, array $rows) use ($pdf, $navy, $ink, $line, $head, $L, $W, &$y, &$tableNo, $need, $header, $footer) {
        $cols = function_exists('rc_fit_cols') ? rc_fit_cols($cols, $W) : $cols; $tableNo++; $y += 2; $need(18);
        $pdf->setColor($navy); $pdf->setFont('B', 8.5); $pdf->text($L, $y + 4, t('rp.table') . ' ' . $tableNo . ' — ' . $title); $y += 6.2;
        $hrow = function () use ($pdf, $cols, $navy, $line, $head, $L, &$y) { $pdf->setFont('B', 7.2); $x = $L; $hh = 6; $pdf->setColor($head); $pdf->rect($x, $y, array_sum(array_column($cols, 1)), $hh, 'F'); foreach ($cols as [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($navy); $pdf->text($x + 1.5, $y + 4, $lab, $al, $cw - 3); $x += $cw; } $y += $hh; };
        $hrow();
        foreach ($rows as $r) {
            $pdf->setFont(!empty($r['b']) ? 'B' : 'R', 7.4); $lines = []; $n = 1; foreach ($cols as $i => [$lab, $cw, $al]) { $lines[$i] = $pdf->wrap((string)($r['v'][$i] ?? ''), $cw - 3); $n = max($n, count($lines[$i])); }
            $hh = $n * 3.7 + 1.6; if ($y + $hh > 276) { $footer(); $header(); $hrow(); $pdf->setFont('R', 7.4); }
            $x = $L; if (!empty($r['tone']) && function_exists('rc_bar_pdf')) rc_bar_pdf($pdf, $r['tone'], $x, $y, $hh);
            foreach ($cols as $i => [$lab, $cw, $al]) { $pdf->setColor($line); $pdf->setLineWidth(0.25); $pdf->rect($x, $y, $cw, $hh); $pdf->setColor($ink); foreach ($lines[$i] as $k => $s) $pdf->text($x + 1.5, $y + 3.4 + 3.7 * $k, $s, $al, $cw - 3); $x += $cw; }
            $y += $hh;
        }
        $y += 2;
    };
    $m = fn(?float $v) => $v === null ? '—' : number_format($v, 2, ',', '.');
    $header(); $footer();
    $kv = [[t('acc.doc_no'), $docNo], [t('v.one'), (string)($R['vessel'] ?: '—')], [t('pr.one'), $R['project_code'] ? trim($R['project_code'] . ' ' . $R['project_name']) : '—'], [t('sp.one'), $R['sub_code'] ? trim($R['sub_code'] . ' ' . $R['sub_name']) : '—'], [t('rp.customer'), (string)($R['customer_name'] ?: '—')],
           [t('acc.period'), ($R['period_start'] ? fmt_date($R['period_start']) : '…') . ' – ' . ($R['period_end'] ? fmt_date($R['period_end']) : '…')], [t('acc.zone'), t('acc.z_' . $R['zone'])], [t('acc.prepared_by'), (string)$R['created_name'] . ' · ' . fmt_date(substr((string)$R['created_at'], 0, 10))], [t('th.status'), t('acc.rs_' . $R['status'])],
           [t('acc.total_hours'), acc_h((float)$R['total_hours']) . ' h'], [t('acc.total_net'), $m((float)$R['total_net']) . ' ' . $cur . ((float)$R['header_discount_pct'] > 0 ? ' (' . t('acc.header_discount') . ' ' . acc_h((float)$R['header_discount_pct']) . ' %)' : '')]];
    foreach ($kv as [$k, $v]) $y += $pdf->kvCell($L, $y, $W, 47, $k, $v, ['minH' => 6.5, 'labelW' => 44, 'fill' => $head, 'line' => $line, 'navy' => $navy, 'ink' => $ink]);   // R59-002: wraps
    $y += 4;
    $h1('1. ' . t('acc.sec_summary'));
    $rows = []; $tot = ['normal' => 0.0, 'overtime' => 0.0, 'holiday' => 0.0, 'travel' => 0.0, 'hours' => 0.0, 'value' => 0.0];
    foreach (acc_summary_rows($R) as $s) { $rows[] = ['v' => [$s['sub'], $s['discipline'], acc_h($s['normal']), acc_h($s['overtime']), acc_h($s['holiday']), acc_h($s['travel']), acc_h($s['hours']), $m($s['value'])]]; foreach ($tot as $k => $v) $tot[$k] += $s[$k]; }
    $rows[] = ['b' => true, 'v' => [t('ob.c_total'), '', acc_h($tot['normal']), acc_h($tot['overtime']), acc_h($tot['holiday']), acc_h($tot['travel']), acc_h($tot['hours']), $m($tot['value'])]];
    if ((float)$R['header_discount_pct'] > 0) $rows[] = ['b' => true, 'v' => [t('acc.header_discount') . ' ' . acc_h((float)$R['header_discount_pct']) . ' %', '', '', '', '', '', '', '− ' . $m($tot['value'] * (float)$R['header_discount_pct'] / 100)]];
    $rows[] = ['b' => true, 'tone' => 'green', 'v' => [t('acc.total_net'), '', '', '', '', '', '', $m((float)$R['total_net']) . ' ' . $cur]];
    $table(t('acc.sec_summary'), [[t('sp.one'), 40, 'L'], [t('bg.discipline'), 32, 'L'], [t('acc.t_normal'), 17, 'R'], [t('acc.t_overtime'), 17, 'R'], [t('acc.t_holiday_s'), 17, 'R'], [t('acc.t_travel'), 17, 'R'], [t('ts.hours'), 14, 'R'], [t('acc.value') . ' ' . $cur, 24, 'R']], $rows);
    $bud = acc_budget_rows($R);
    if ($bud) { $h1('2. ' . t('acc.sec_budget')); $rows = []; foreach ($bud as $b) $rows[] = ['tone' => $b['over'] ? 'red' : null, 'v' => [$b['sub'], $b['discipline'], $b['budget'] !== null ? acc_h($b['budget']) : '—', acc_h($b['invoiced']), acc_h($b['here']), $b['budget'] !== null ? acc_h($b['budget'] - $b['after']) : '—']];
        $table(t('acc.sec_budget'), [[t('sp.one'), 50, 'L'], [t('bg.discipline'), 40, 'L'], [t('bg.budget_h'), 22, 'R'], [t('acc.invoiced_h'), 22, 'R'], [t('acc.this_report'), 22, 'R'], [t('bg.remaining'), 24, 'R']], $rows); }
    $h1(($bud ? '3' : '2') . '. ' . t('acc.sec_detail'));
    foreach (acc_group_lines($R) as $pName => $subs) foreach ($subs as $sName => $discs) foreach ($discs as $dName => $ls) {
        $rows = []; $sh = 0.0; $sv = 0.0;
        foreach ($ls as $l) { $rows[] = ['v' => [fmt_date($l['work_date']), (string)$l['user_name'], ($l['source'] === 'ts' ? 'TS ' : 'SR ') . $l['doc_no'], acc_type_label($l['hour_type']), acc_h((float)$l['hours']), $m((float)$l['unit_price']), (float)$l['discount_pct'] > 0 ? acc_h((float)$l['discount_pct']) . ' %' : '', $m((float)$l['value']), (string)($l['description'] ?? '')]]; $sh += (float)$l['hours']; $sv += (float)$l['value']; }
        $rows[] = ['b' => true, 'v' => ['', '', '', t('ob.c_total'), acc_h($sh), '', '', $m($sv), '']];
        $table($pName . ' · ' . $sName . ' · ' . $dName, [[t('th.date'), 18, 'L'], [t('wv.by'), 30, 'L'], [t('th.ref'), 26, 'L'], [t('ts.type'), 16, 'L'], [t('ts.hours'), 12, 'R'], [t('acc.rate'), 16, 'R'], [t('acc.discount'), 12, 'R'], [t('acc.value'), 20, 'R'], [t('ts.what'), 30, 'L']], $rows);
    }
    $exc = array_values(array_filter($R['lines'], fn($l) => $l['state'] === 'excluded'));
    if ($exc) { $h1(($bud ? '4' : '3') . '. ' . t('acc.sec_excluded')); $rows = []; foreach ($exc as $l) $rows[] = ['tone' => 'grey', 'v' => [fmt_date($l['work_date']), (string)$l['user_name'], ($l['source'] === 'ts' ? 'TS ' : 'SR ') . $l['doc_no'], acc_type_label($l['hour_type']), acc_h((float)$l['hours']), (string)($l['exclude_reason'] ?? '')]];
        $table(t('acc.sec_excluded'), [[t('th.date'), 20, 'L'], [t('wv.by'), 34, 'L'], [t('th.ref'), 30, 'L'], [t('ts.type'), 18, 'L'], [t('ts.hours'), 14, 'R'], [t('acc.reason'), 64, 'L']], $rows); }
    $y += 6; $need(24);
    $pdf->setColor($ink); $pdf->setFont('R', 8); $pdf->text($L, $y + 4, t('acc.sign_pm') . ': ' . (string)$R['created_name']); $pdf->text($L + 95, $y + 4, t('acc.sign_acc') . ': ' . (string)($R['answered_name'] ?: '')); $pdf->setColor($line); $pdf->line($L, $y + 14, $L + 80, $y + 14); $pdf->line($L + 95, $y + 14, $L + 175, $y + 14);
    $footer();
    return $pdf->bytes($docNo);
}
function acc_render_xlsx(array $R): string {
    require_once __DIR__ . '/xlsxw.php';
    $cur = $R['currency'] ?: 'EUR';
    $rows = [];
    foreach ($R['lines'] as $l) $rows[] = [$R['report_no'] . ' ' . $R['revision'], (string)($R['vessel'] ?? ''), (string)($l['project_code'] ?? ''), trim(($l['sub_code'] ?? '') . ' ' . ($l['sub_name'] ?? '')), (string)($l['discipline'] ?? ''), $l['work_date'], (string)$l['user_name'], ($l['source'] === 'ts' ? 'TS ' : 'SR ') . $l['doc_no'], acc_type_label($l['hour_type']), (float)$l['hours'], (float)($l['unit_price'] ?? 0), (float)$l['discount_pct'], (float)($l['value'] ?? 0), $l['state'] === 'excluded' ? t('acc.st_excluded') : t('acc.included'), (string)($l['exclude_reason'] ?? $l['discount_reason'] ?? ''), (string)($l['description'] ?? ''), (string)($l['rate_card'] ?? ''), (string)($l['rate_desc'] ?? '')];
    $sum = []; foreach (acc_summary_rows($R) as $s) $sum[] = [$s['sub'], $s['discipline'], $s['normal'], $s['overtime'], $s['holiday'], $s['travel'], $s['hours'], round($s['value'], 2)];
    $sum[] = [t('ob.c_total'), '', '', '', '', '', (float)$R['total_hours'], (float)$R['total_gross']]; if ((float)$R['header_discount_pct'] > 0) $sum[] = [t('acc.header_discount'), (float)$R['header_discount_pct'] . ' %', '', '', '', '', '', '']; $sum[] = [t('acc.total_net'), '', '', '', '', '', '', (float)$R['total_net']];
    return xlsx_bytes([
        ['name' => t('acc.sec_summary'), 'first' => true, 'header' => [t('sp.one'), t('bg.discipline'), t('acc.t_normal'), t('acc.t_overtime'), t('acc.t_holiday'), t('acc.t_travel'), t('ts.hours'), t('acc.value') . ' ' . $cur], 'rows' => $sum, 'widths' => [34, 26, 10, 10, 10, 10, 10, 14]],
        ['name' => t('acc.sec_detail'), 'header' => [t('acc.doc_no'), t('v.one'), t('pr.one'), t('sp.one'), t('bg.discipline'), t('th.date'), t('wv.by'), t('th.ref'), t('ts.type'), t('ts.hours'), t('acc.rate'), t('acc.discount') . ' %', t('acc.value') . ' ' . $cur, t('th.status'), t('acc.reason'), t('ts.what'), t('rl.card'), t('rl.item')], 'rows' => $rows, 'widths' => [18, 20, 14, 30, 18, 11, 22, 18, 10, 8, 9, 9, 12, 10, 24, 36, 6, 40], 'wrap' => [15]],
    ]);
}

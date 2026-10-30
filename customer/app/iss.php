<?php
/**
 * Open-issues register per sub-project.                                                      GAS-CP-R29-001
 *
 * Every open point of a sub-project in one list: the "open issues" written in the task blocks of the field
 * service reports (one register entry per task block, created when the report is submitted and kept in step
 * while the report is edited) and the points the team or the owner's side add by hand. Each entry carries who
 * has to act (Golden A/S / owner / third party), an assignee, a due date, a priority and a status (open,
 * in progress, closed with a note). The weekly vessel report, the monthly sub-project report and the vessel
 * technical history print the open entries (and what was closed in the period); the planning board shows them
 * to the engineer who is assigned to the sub-project. Owners see external entries; internal ones stay inside.
 */
declare(strict_types=1);

function iss_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'sp_issues'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
/** issue_no column present (R29-003)? */
function iss_has_no(): bool { static $h = null; if ($h === null) { try { $h = iss_has() && (bool) scalar("SHOW COLUMNS FROM sp_issues LIKE 'issue_no'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Next number of a project: OI-<project code>-nnn (sequential per project, never reused). */
function iss_number(int $projectId): ?string {
    if (!iss_has_no()) return null;
    $code = strtoupper(preg_replace('/[^A-Za-z0-9.\-]+/', '-', (string) scalar('SELECT COALESCE(NULLIF(project_code, \'\'), CONCAT(\'P\', id)) FROM projects WHERE id = ?', [$projectId])));
    $base = 'OI-' . trim($code, '-') . '-'; $n = (int) scalar('SELECT COUNT(*) FROM sp_issues WHERE project_id = ? AND issue_no IS NOT NULL', [$projectId]) + 1;
    while (scalar('SELECT COUNT(*) FROM sp_issues WHERE issue_no = ?', [sprintf('%s%03d', $base, $n)])) $n++;
    return sprintf('%s%03d', $base, $n);
}
/** Entries created before R29-003 get their number in creation order. */
function iss_number_missing(): int {
    if (!iss_has_no()) return 0; $n = 0;
    foreach (all('SELECT id, project_id FROM sp_issues WHERE issue_no IS NULL ORDER BY project_id, id') as $r) { q('UPDATE sp_issues SET issue_no = ? WHERE id = ?', [iss_number((int)$r['project_id']), (int)$r['id']]); $n++; }
    return $n;
}
/** category column present (R29-004)? */
function iss_has_cat(): bool { static $h = null; if ($h === null) { try { $h = iss_has() && (bool) scalar("SHOW COLUMNS FROM sp_issues LIKE 'category'"); } catch (Throwable $e) { $h = false; } } return $h; }
function iss_statuses(): array { return ['open' => t('iss.st_open'), 'in_progress' => t('iss.st_in_progress'), 'closed' => t('iss.st_closed')]; }
function iss_sides(): array { return ['golden' => t('iss.side_golden'), 'customer' => t('iss.side_customer'), 'third' => t('iss.side_third')]; }
function iss_status_pill(string $s): string { return pill(['open' => 'bad', 'in_progress' => 'gold', 'closed' => 'green'][$s] ?? 'mute', iss_statuses()[$s] ?? $s); }
function iss_priority_pill(string $p): string { return pill(['high' => 'bad', 'normal' => 'mute', 'low' => 'blue'][$p] ?? 'mute', t('td.p_' . $p)); }
function iss_overdue(array $i): bool { return $i['status'] !== 'closed' && !empty($i['due_on']) && $i['due_on'] < date('Y-m-d'); }
function iss_vis_sql(string $a = 'i'): string { return is_staff() ? '1 = 1' : "{$a}.visibility = 'external'"; }

/** Issues of a sub-project or a whole project. $f: status (open_all | open | in_progress | closed | ''), side, assignee, q, from/to (raised), closed_from/closed_to. */
function iss_list(?int $projectId, ?int $subId, array $f = []): array {
    if (!iss_has()) return [];
    $w = [iss_vis_sql()]; $a = [];
    if ($subId) { $w[] = 'i.subproject_id = ?'; $a[] = $subId; } elseif ($projectId) { $w[] = 'i.project_id = ?'; $a[] = $projectId; } else return [];
    $st = $f['status'] ?? 'open_all';
    if ($st === 'open_all') $w[] = "i.status <> 'closed'"; elseif ($st !== '' && isset(iss_statuses()[$st])) { $w[] = 'i.status = ?'; $a[] = $st; }
    if (!empty($f['side'])) { $w[] = 'i.owner_side = ?'; $a[] = $f['side']; }
    if (!empty($f['category']) && iss_has_cat()) { $w[] = 'i.category = ?'; $a[] = $f['category']; }
    if (!empty($f['assignee'])) { $w[] = 'i.assignee_id = ?'; $a[] = (int)$f['assignee']; }
    if (!empty($f['q'])) { $w[] = '(i.title LIKE ? OR i.body LIKE ? OR i.closed_note LIKE ?' . (iss_has_no() ? ' OR i.issue_no LIKE ?' : '') . ')'; $l = '%' . $f['q'] . '%'; array_push($a, $l, $l, $l); if (iss_has_no()) $a[] = $l; }
    if (!empty($f['closed_from'])) { $w[] = 'i.closed_on >= ?'; $a[] = $f['closed_from']; }
    if (!empty($f['closed_to'])) { $w[] = 'i.closed_on <= ?'; $a[] = $f['closed_to']; }
    return all("SELECT i.*, s.code AS sub_code, s.name AS sub_name, p.project_code, r.report_no, r.status AS fsr_status, u.full_name AS assignee
                FROM sp_issues i JOIN projects p ON p.id = i.project_id LEFT JOIN subprojects s ON s.id = i.subproject_id LEFT JOIN fsr_reports r ON r.id = i.fsr_id LEFT JOIN customer_users u ON u.id = i.assignee_id
                WHERE " . implode(' AND ', $w) . ' ORDER BY ' . iss_order_sql((string)($f['sort'] ?? ''), (string)($f['dir'] ?? '')), $a);
}
/** ORDER BY for the register: any column, ascending or descending; the default keeps open first, then priority, due date, raised date. */
function iss_order_sql(string $sort, string $dir): string {
    $d = strtolower($dir) === 'desc' ? 'DESC' : 'ASC'; $nd = $d === 'ASC' ? 'DESC' : 'ASC';
    $cols = ['no' => 'i.issue_no', 'title' => 'i.title', 'category' => 'i.category', 'source' => "FIELD(i.source,'fsr','manual')", 'side' => "FIELD(i.owner_side,'golden','customer','third')", 'assignee' => 'u.full_name', 'raised' => 'i.raised_on', 'due' => "COALESCE(i.due_on, '9999-12-31')", 'status' => "FIELD(i.status,'open','in_progress','closed')", 'priority' => "FIELD(i.priority,'high','normal','low')", 'sub' => 's.sort_order, s.code'];
    if (!isset($cols[$sort]) || ($sort === 'no' && !iss_has_no()) || ($sort === 'category' && !iss_has_cat())) return "FIELD(i.status,'open','in_progress','closed'), FIELD(i.priority,'high','normal','low'), COALESCE(i.due_on, '9999-12-31'), i.raised_on, i.id";
    return $cols[$sort] . ' ' . $d . ($sort === 'sub' ? '' : '') . ', i.id ' . $d;
}
function iss_get(int $id): ?array { return iss_has() ? one("SELECT i.*, s.code AS sub_code, s.name AS sub_name, p.project_code, p.vessel_id, r.report_no FROM sp_issues i JOIN projects p ON p.id = i.project_id LEFT JOIN subprojects s ON s.id = i.subproject_id LEFT JOIN fsr_reports r ON r.id = i.fsr_id WHERE i.id = ? AND " . iss_vis_sql(), [$id]) : null; }
function iss_open_count(?int $projectId, ?int $subId): int {
    if (!iss_has()) return 0;
    return (int) scalar("SELECT COUNT(*) FROM sp_issues i WHERE i.status <> 'closed' AND " . iss_vis_sql() . ' AND ' . ($subId ? 'i.subproject_id = ?' : 'i.project_id = ?'), [$subId ?: $projectId]);
}
/** Manual entry: create or edit from the sub-project page form. */
function iss_save(int $projectId, ?int $subId, int $id = 0): int {
    $u = require_login(); if (!iss_has()) throw new RuntimeException(t('iss.need_db'));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if ($title === '') throw new RuntimeException(t('iss.need_title'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000) ?: null;
    $side = (string)($_POST['owner_side'] ?? 'golden'); if (!isset(iss_sides()[$side])) $side = 'golden';
    $prio = (string)($_POST['priority'] ?? 'normal'); if (!in_array($prio, ['low', 'normal', 'high'], true)) $prio = 'normal';
    $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_on'] ?? '')) ? $_POST['due_on'] : null;
    $raised = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['raised_on'] ?? '')) ? $_POST['raised_on'] : date('Y-m-d');
    $ass = is_staff() ? ((int)($_POST['assignee_id'] ?? 0) ?: null) : null;
    $vis = is_staff() && ($_POST['visibility'] ?? '') === 'internal' ? 'internal' : 'external';
    $cat = (string)($_POST['category'] ?? 'other'); if (!isset(iss_cat_defs()[$cat])) $cat = 'other';
    if ($id) {
        $i = iss_get($id); if (!$i) throw new RuntimeException(t('err.notfound'));
        if (!is_staff() && (int)$i['created_by'] !== (int)$u['id']) throw new RuntimeException(t('err.noaccess'));
        q('UPDATE sp_issues SET title=?, body=?, owner_side=?, priority=?, due_on=?, raised_on=?, assignee_id=?, visibility=? WHERE id=?', [$title, $body, $side, $prio, $due, $raised, is_staff() ? $ass : $i['assignee_id'], is_staff() ? $vis : $i['visibility'], $id]);
        if (iss_has_cat()) q('UPDATE sp_issues SET category = ? WHERE id = ?', [$cat, $id]);
        return $id;
    }
    q('INSERT INTO sp_issues (project_id, subproject_id, source, title, body, raised_on, raised_by, owner_side, assignee_id, due_on, priority, visibility, created_by) VALUES (?,?,\'manual\',?,?,?,?,?,?,?,?,?,?)',
      [$projectId, $subId, $title, $body, $raised, $u['full_name'], $side, $ass, $due, $prio, $vis, (int)$u['id']]);
    $id = (int) db()->lastInsertId(); if (iss_has_cat()) q('UPDATE sp_issues SET category = ? WHERE id = ?', [$cat, $id]); if (iss_has_no()) q('UPDATE sp_issues SET issue_no = ? WHERE id = ?', [iss_number($projectId), $id]);
    return $id;
}
function iss_set_status(int $id, string $status, string $note = ''): void {
    $u = require_login(); $i = iss_get($id); if (!$i) throw new RuntimeException(t('err.notfound'));
    if (!isset(iss_statuses()[$status])) throw new RuntimeException(t('err.notfound'));
    if (!is_staff() && $status !== 'closed' && $i['status'] !== 'closed') throw new RuntimeException(t('err.noaccess'));   // owners may close (confirm) and re-open, not "in progress"
    if ($status === 'closed') q('UPDATE sp_issues SET status = \'closed\', closed_on = CURDATE(), closed_by = ?, closed_note = ? WHERE id = ?', [$u['full_name'], mb_substr(trim($note), 0, 1000) ?: null, $id]);
    else q('UPDATE sp_issues SET status = ?, closed_on = NULL, closed_by = NULL, closed_note = NULL WHERE id = ?', [$status, $id]);
}
function iss_delete(int $id): void { require_cp_admin(); if (iss_has()) q('DELETE FROM sp_issues WHERE id = ?', [$id]); }

/** The register follows the field service report: one entry per task block that carries an "open issues" text.
 *  Submitting creates / updates them; a block whose text was removed drops its entry while it is still open; closed entries are never touched. */
function iss_fsr_sync(int $reportId): void {
    if (!iss_has()) return;
    $r = function_exists('fsr_load') ? fsr_load($reportId) : null; if (!$r) return;
    if ($r['status'] === 'draft') { q("DELETE FROM sp_issues WHERE fsr_id = ? AND status <> 'closed'", [$reportId]); return; }
    $seen = [];
    foreach ($r['works'] as $w) {
        $txt = trim((string)$w['open_issues']); if ($txt === '') continue;
        $seq = (int)$w['seq']; $seen[] = $seq;
        $title = mb_substr(preg_replace('/\s+/u', ' ', $txt), 0, 200);
        $body = $txt . (trim((string)$w['description']) !== '' ? "\n\n" . t('fsr.task_n', ['n' => $seq]) . ': ' . trim((string)$w['description']) : '');
        $x = one('SELECT id, status FROM sp_issues WHERE fsr_id = ? AND fsr_seq = ?', [$reportId, $seq]);
        if ($x) { if ($x['status'] !== 'closed') q('UPDATE sp_issues SET title = ?, body = ?, raised_on = ?, subproject_id = ?, project_id = ? WHERE id = ?', [$title, mb_substr($body, 0, 8000), $r['report_date'], $r['subproject_id'] ?: null, (int)$r['project_id'], $x['id']]); }
        else q("INSERT INTO sp_issues (project_id, subproject_id, source, fsr_id, fsr_seq, title, body, raised_on, raised_by, owner_side, priority, visibility, created_by) VALUES (?,?,'fsr',?,?,?,?,?,?,'golden','normal','external',?)",
               [(int)$r['project_id'], $r['subproject_id'] ?: null, $reportId, $seq, $title, mb_substr($body, 0, 8000), $r['report_date'], (string)($r['submitted_name'] ?: ''), (int)($r['submitted_by'] ?: 0) ?: null]);
        if (iss_has_no()) q('UPDATE sp_issues SET issue_no = ? WHERE id = ?', [iss_number((int)$r['project_id']), (int) db()->lastInsertId()]);
    }
    q("DELETE FROM sp_issues WHERE fsr_id = ? AND status <> 'closed'" . ($seen ? ' AND fsr_seq NOT IN (' . implode(',', $seen) . ')' : ''), [$reportId]);
}

/* ------------------------------------------------------------ rows for the reports (PDF / Word) */

/** Open entries of a project (or one sub-project) plus the ones closed between $from and $to. Rows: [sub, title, side, assignee, raised, due, status]. */
function iss_report_tables(int $projectId, ?int $subId, ?string $from = null, ?string $to = null): array {
    if (!iss_has()) return [];
    $row = fn($i) => ['tone' => rc_issue($i), 'hl' => rc_due($i['due_on'] ?? null) && $i['status'] !== 'closed' ? [5 => rc_due($i['due_on'])] : [], 'v' => [trim(($i['sub_code'] ?? '') . ' ' . ($i['sub_name'] ?? '')) ?: '—', (!empty($i['issue_no']) ? $i['issue_no'] . ' — ' : '') . (iss_has_cat() && ($i['category'] ?? 'other') !== 'other' ? '[' . (iss_cats(false)[$i['category']] ?? $i['category']) . '] ' : '') . $i['title'] . ($i['source'] === 'fsr' && $i['report_no'] ? ' (' . $i['report_no'] . ')' : ''), iss_sides()[$i['owner_side']] ?? $i['owner_side'], (string)($i['assignee'] ?: '—'), fmt_date($i['raised_on']), $i['due_on'] ? fmt_date($i['due_on']) . (iss_overdue($i) ? ' !' : '') : '—', iss_statuses()[$i['status']]]];
    $cols = [[t('sp.one'), 30, 'L'], [t('iss.one'), 58, 'L'], [t('iss.side'), 20, 'L'], [t('td.assignee'), 22, 'L'], [t('iss.raised'), 16, 'L'], [t('iss.due'), 16, 'L'], [t('th.status'), 18, 'L']];
    $T = [['title' => t('iss.t_open'), 'cols' => $cols, 'rows' => array_map($row, iss_list($projectId, $subId, ['status' => 'open_all']))]];
    if ($from) { $closed = array_map(fn($i) => ['tone' => 'green', 'v' => [trim(($i['sub_code'] ?? '') . ' ' . ($i['sub_name'] ?? '')) ?: '—', (!empty($i['issue_no']) ? $i['issue_no'] . ' — ' : '') . $i['title'], iss_sides()[$i['owner_side']] ?? '', (string)($i['closed_by'] ?: '—'), fmt_date($i['raised_on']), fmt_date($i['closed_on']), (string)($i['closed_note'] ?: '')]], iss_list($projectId, $subId, ['status' => 'closed', 'closed_from' => $from, 'closed_to' => $to ?: date('Y-m-d')]));
        if ($closed) $T[] = ['title' => t('iss.t_closed', ['f' => fmt_date($from), 't' => fmt_date($to ?: date('Y-m-d'))]), 'cols' => [[t('sp.one'), 30, 'L'], [t('iss.one'), 50, 'L'], [t('iss.side'), 18, 'L'], [t('iss.closed_by'), 22, 'L'], [t('iss.raised'), 16, 'L'], [t('iss.closed_on'), 16, 'L'], [t('iss.closed_note'), 28, 'L']], 'rows' => $closed]; }
    return $T;
}
/** One-time catch-up for reports submitted before the register existed: every non-draft report of the sub-project (or project) that has
 *  open-issue text but no register entry yet is synced. Called when the tab opens (staff). */
function iss_backfill(int $projectId, ?int $subId): int {
    if (!iss_has() || !is_staff()) return 0;
    $n = iss_number_missing();
    foreach (all("SELECT DISTINCT r.id FROM fsr_reports r JOIN fsr_works w ON w.report_id = r.id WHERE r.status <> 'draft' AND w.open_issues IS NOT NULL AND w.open_issues <> '' AND " . ($subId ? 'r.subproject_id = ?' : 'r.project_id = ?') . ' AND NOT EXISTS (SELECT 1 FROM sp_issues i WHERE i.fsr_id = r.id)', [$subId ?: $projectId]) as $r) { iss_fsr_sync((int)$r['id']); $n++; }
    return $n;
}

/* ------------------------------------------------------------ Excel import / export (R29-002) */

/** Register of a sub-project (or project) as Excel in the import layout. */
function iss_export(int $projectId, ?int $subId): void {
    require_login(); require_once __DIR__ . '/xlsxw.php';
    $rows = [];
    foreach (iss_list($projectId, $subId, ['status' => '']) as $i)
        $rows[] = [(string)($i['issue_no'] ?? ''), trim(($i['sub_code'] ?? '') . ' ' . ($i['sub_name'] ?? '')), $i['title'], iss_cats(false)[$i['category'] ?? 'other'] ?? ($i['category'] ?? ''), (string)($i['body'] ?? ''), iss_sides()[$i['owner_side']] ?? $i['owner_side'], t('td.p_' . $i['priority']), $i['raised_on'], $i['due_on'] ?? '', (string)($i['assignee'] ?? ''), iss_statuses()[$i['status']], $i['closed_on'] ?? '', (string)($i['closed_note'] ?? ''), $i['visibility'] === 'internal' ? t('vis.internal') : t('vis.external'), $i['source'] === 'fsr' ? (string)$i['report_no'] : t('iss.src_manual')];
    $name = 'GAS-open-issues-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string)($subId ? scalar('SELECT CONCAT(p.project_code, "-", s.code) FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$subId]) : scalar('SELECT project_code FROM projects WHERE id = ?', [$projectId]))) . '-' . date('Ymd') . '.xlsx';
    xlsx_download($name, [['name' => 'Open issues', 'listrefs' => iss_xlsx_listrefs($projectId), 'header' => ['No', 'Sub-project', 'Issue', 'Category', 'Details', 'To be done by', 'Priority', 'Raised', 'Due', 'Assigned to', 'Status', 'Closed on', 'Closing note', 'Visibility', 'Source'], 'rows' => $rows, 'widths' => [18, 22, 48, 18, 50, 16, 10, 12, 12, 22, 12, 12, 30, 12, 18], 'wrap' => [2, 4, 12]], iss_xlsx_lists_sheet($projectId)]);
}
function iss_import_date(string $v): ?string {
    $v = trim($v); if ($v === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $v, $m)) return sprintf('%04d-%02d-%02d', strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3], $m[2], $m[1]);
    if (is_numeric($v) && $v > 20000 && $v < 60000) return date('Y-m-d', (int)(((float)$v - 25569) * 86400));
    return null;
}
/** Import the PM's Excel list into the register of a sub-project (or of a project, with the Sub-project column choosing the sub-project).
 *  Columns: Issue (required) · Details · To be done by · Priority · Raised · Due · Assigned to · Status · Closed on · Closing note · Visibility · Sub-project.
 *  A row whose Issue text already exists in the same sub-project (case-insensitive) updates that entry; others are created as manual entries. */
function iss_import(string $path, int $projectId, ?int $subId, bool $dry): array {
    require_staff(); if (!iss_has()) throw new RuntimeException(t('iss.need_db'));
    require_once __DIR__ . '/xlsx.php';
    $rows = xlsx_rows($path);
    $hdr = pm_import_header($rows, ['title' => ['Issue', 'Open issue', 'Title', 'Item', 'Subject', 'Θέμα']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Issue']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['body' => ['Details', 'Description', 'Remarks', 'Notes'], 'category' => ['Category', 'Discipline', 'Type', 'Κατηγορία'], 'side' => ['To be done by', 'Responsible', 'Owner side', 'By'], 'priority' => ['Priority'], 'raised' => ['Raised', 'Raised on', 'Date', 'Opened'], 'due' => ['Due', 'Due on', 'Deadline', 'Due date'], 'assignee' => ['Assigned to', 'Assignee', 'Engineer'], 'status' => ['Status'], 'closed' => ['Closed on', 'Closed'], 'note' => ['Closing note', 'Close note', 'Resolution'], 'vis' => ['Visibility'], 'sub' => ['Sub-project', 'Subproject', 'Sub project'], 'no' => ['No', 'No.', 'Issue no', 'Number', 'ID']]);
    $g = fn(array $r, string $k) => isset($map[$k]) && $map[$k] !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $u = current_user(); $staff = staff_users(); $subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ?', [$projectId]);
    $res = ['rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => [], 'warnings' => []];
    $sideOf = function (string $v): string { $l = mb_strtolower($v); return match (true) { $l === '' => 'golden', str_contains($l, 'owner') || str_contains($l, 'crew') || str_contains($l, 'customer') || str_contains($l, 'πλοιο') || str_contains($l, 'armat') || str_contains($l, 'собствен') || str_contains($l, 'власник') => 'customer', str_contains($l, 'third') || str_contains($l, 'τρίτ') || str_contains($l, 'terz') || str_contains($l, 'трет') || str_contains($l, 'yard') || str_contains($l, 'supplier') => 'third', default => 'golden' }; };
    $statusOf = function (string $v): string { $l = mb_strtolower($v); return match (true) { $l === '' => 'open', str_contains($l, 'clos') || str_contains($l, 'done') || str_contains($l, 'κλει') || str_contains($l, 'chius') || str_contains($l, 'затвор') || str_contains($l, 'закрит') => 'closed', str_contains($l, 'progress') || str_contains($l, 'εξέλ') || str_contains($l, 'corso') || str_contains($l, 'wip') || str_contains($l, 'процес') || str_contains($l, 'роботі') => 'in_progress', default => 'open' }; };
    $prioOf = function (string $v): string { $l = mb_strtolower($v); return match (true) { str_contains($l, 'high') || str_contains($l, 'urgent') || str_contains($l, 'υψη') || str_contains($l, 'alta') || str_contains($l, 'висок') => 'high', str_contains($l, 'low') || str_contains($l, 'χαμη') || str_contains($l, 'bassa') || str_contains($l, 'нис') || str_contains($l, 'низьк') => 'low', default => 'normal' }; };
    return pm_import_tx($dry, function () use ($rows, $hi, $g, $map, &$res, $projectId, $subId, $u, $staff, $subs, $sideOf, $statusOf, $prioOf) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $title = mb_substr(preg_replace('/\s+/u', ' ', $g($r, 'title')), 0, 200); if ($title === '') { $res['skipped'][] = [$line, t('iss.need_title')]; continue; }
            $sid = $subId;
            if (($sv = $g($r, 'sub')) !== '') { $k = mb_strtolower(preg_replace('/\s+/', ' ', $sv)); foreach ($subs as $s) if (mb_strtolower($s['code']) === $k || mb_strtolower($s['code'] . ' ' . $s['name']) === $k || mb_strtolower($s['name']) === $k || str_starts_with($k, mb_strtolower($s['code']) . ' ')) { $sid = (int)$s['id']; break; } if (!$sid || ($subId && $sid === $subId && !in_array(mb_strtolower(preg_replace('/\s+/', ' ', $sv)), array_merge(...array_map(fn($s) => [mb_strtolower($s['code']), mb_strtolower($s['code'] . ' ' . $s['name']), mb_strtolower($s['name'])], array_filter($subs, fn($s) => (int)$s['id'] === $subId))), true))) { if ($subId) { $sid = $subId; $res['warnings'][] = [$line, t('iss.im_sub_fallback', ['v' => $sv])]; } else { $res['skipped'][] = [$line, t('iss.im_no_sub', ['v' => $sv])]; continue; } } }
            $ass = null; if (($an = $g($r, 'assignee')) !== '') { foreach ($staff as $p) if (strcasecmp(trim($p['full_name']), $an) === 0) { $ass = (int)$p['id']; break; } if ($ass === null) foreach ($staff as $p) { $pp = preg_split('/\s+/', mb_strtolower($p['full_name'])); $aa = preg_split('/\s+/', mb_strtolower($an)); if (end($pp) === end($aa) && mb_substr($pp[0], 0, 3) === mb_substr($aa[0], 0, 3)) { $ass = (int)$p['id']; break; } } if ($ass === null) $res['warnings'][] = [$line, t('iss.im_no_assignee', ['v' => $an])]; }
            $status = $statusOf($g($r, 'status')); $raised = iss_import_date($g($r, 'raised')) ?: date('Y-m-d'); $due = iss_import_date($g($r, 'due')); $closedOn = iss_import_date($g($r, 'closed'));
            $vis = str_contains(mb_strtolower($g($r, 'vis')), 'intern') || str_contains(mb_strtolower($g($r, 'vis')), 'εσωτ') ? 'internal' : 'external';
            $body = mb_substr($g($r, 'body'), 0, 8000) ?: null; $note = mb_substr($g($r, 'note'), 0, 1000) ?: null;
            $x = null; if (iss_has_no() && ($no = strtoupper($g($r, 'no'))) !== '') { $x = one('SELECT id FROM sp_issues WHERE issue_no = ? AND project_id = ?', [$no, $projectId]); if (!$x) { $res['skipped'][] = [$line, t('iss.im_no_number', ['v' => $no])]; continue; } }
            if (!$x) $x = one('SELECT id FROM sp_issues WHERE project_id = ? AND ' . ($sid ? 'subproject_id = ?' : 'subproject_id IS NULL') . ' AND LOWER(title) = LOWER(?)', $sid ? [$projectId, $sid, $title] : [$projectId, $title]);
            $vals = [$body, $sideOf($g($r, 'side')), $prioOf($g($r, 'priority')), $raised, $due, $ass, $status, $status === 'closed' ? ($closedOn ?: date('Y-m-d')) : null, $status === 'closed' ? ($u['full_name'] ?? null) : null, $status === 'closed' ? $note : null, $vis];
            if ($x) {                                                                                   // columns missing from the sheet keep their value
                $has = fn(string $k) => isset($map[$k]) && $map[$k] !== null; $set = []; $arg = [];
                if (iss_has_cat() && $has('category')) { $set[] = 'category = ?'; $arg[] = iss_cat_of($g($r, 'category')); }
                foreach ([['title', $has('no') && $g($r, 'no') !== '', $title], ['body', $has('body'), $vals[0]], ['owner_side', $has('side'), $vals[1]], ['priority', $has('priority'), $vals[2]], ['raised_on', $has('raised'), $vals[3]], ['due_on', $has('due'), $vals[4]], ['assignee_id', $has('assignee'), $vals[5]], ['status', $has('status'), $vals[6]], ['closed_on', $has('status'), $vals[7]], ['closed_note', $has('status') || $has('note'), $vals[9]], ['visibility', $has('vis'), $vals[10]]] as [$col, $on, $v]) if ($on) { $set[] = "$col = ?"; $arg[] = $v; }
                if ($has('status')) { $set[] = 'closed_by = ' . ($status === 'closed' ? 'COALESCE(closed_by, ?)' : 'NULL'); if ($status === 'closed') $arg[] = $vals[8]; }
                if ($set) q('UPDATE sp_issues SET ' . implode(', ', $set) . ' WHERE id = ?', array_merge($arg, [(int)$x['id']]));
                $res['updated']++;
            }
            else { q('INSERT INTO sp_issues (project_id, subproject_id, source, title, raised_by, created_by, body, owner_side, priority, raised_on, due_on, assignee_id, status, closed_on, closed_by, closed_note, visibility) VALUES (?,?,\'manual\',?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge([$projectId, $sid, $title, $u['full_name'] ?? null, (int)($u['id'] ?? 0) ?: null], $vals)); $nid = (int) db()->lastInsertId(); if (iss_has_no()) q('UPDATE sp_issues SET issue_no = ? WHERE id = ?', [iss_number($projectId), $nid]); if (iss_has_cat()) q('UPDATE sp_issues SET category = ? WHERE id = ?', [iss_cat_of($g($r, 'category')), $nid]); $res['created']++; }
        }
        return $res;
    });
}

/* ------------------------------------------------------------ numbers and categories (R29-003) */

const ISS_BUILTIN_CATS = ['mechanical' => 'navy', 'electrical' => 'gold', 'automation' => 'blue', 'navcom' => 'blue', 'software' => 'green', 'documentation' => 'mute', 'logistics' => 'gold', 'commercial' => 'mute', 'safety' => 'bad', 'other' => 'mute'];
function iss_cat_table(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'issue_categories'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Categories keyed by slug: built-in ones carry translated labels, the administrators' own their label. */
function iss_cat_defs(bool $activeOnly = false): array {
    static $c = null;
    if ($c === null || !empty($GLOBALS['iss_cat_dirty'])) {
        $c = []; $GLOBALS['iss_cat_dirty'] = false;
        if (iss_cat_table()) foreach (all('SELECT * FROM issue_categories ORDER BY sort_order, label') as $r) $c[$r['skey']] = ['label' => $r['is_builtin'] && t('iss.c_' . $r['skey']) !== 'iss.c_' . $r['skey'] ? t('iss.c_' . $r['skey']) : $r['label'], 'tone' => $r['tone'], 'sort_order' => (int)$r['sort_order'], 'is_builtin' => (bool)$r['is_builtin'], 'is_active' => (bool)$r['is_active']];
        if (!$c) { $i = 0; foreach (ISS_BUILTIN_CATS as $k => $tone) $c[$k] = ['label' => t('iss.c_' . $k), 'tone' => $tone, 'sort_order' => ++$i * 10, 'is_builtin' => true, 'is_active' => true]; }
    }
    return $activeOnly ? array_filter($c, fn($d) => $d['is_active']) : $c;
}
function iss_cats(bool $activeOnly = true): array { return array_map(fn($d) => $d['label'], iss_cat_defs($activeOnly)); }
function iss_cat_pill(string $k): string { $d = iss_cat_defs()[$k] ?? null; return pill($d['tone'] ?? 'mute', $d['label'] ?? $k); }
/** Slug for a category typed in Excel ("Electrical", "ELECTR", "Logistics / spare parts", Greek labels …). */
function iss_cat_of(string $v): string {
    $l = mb_strtolower(trim($v)); if ($l === '') return 'other';
    foreach (iss_cat_defs() as $k => $d) if ($l === $k || $l === mb_strtolower($d['label'])) return $k;
    foreach (iss_cat_defs() as $k => $d) if (str_starts_with(mb_strtolower($d['label']), $l) || str_starts_with($l, $k) || str_contains(mb_strtolower($d['label']), $l)) return $k;
    foreach (['mech' => 'mechanical', 'μηχαν' => 'mechanical', 'elec' => 'electrical', 'ηλεκτρ' => 'electrical', 'autom' => 'automation', 'αυτομ' => 'automation', 'plc' => 'automation', 'nav' => 'navcom', 'comm' => 'navcom', 'soft' => 'software', 'firmware' => 'software', 'doc' => 'documentation', 'class' => 'documentation', 'logist' => 'logistics', 'spare' => 'logistics', 'part' => 'logistics', 'commer' => 'commercial', 'offer' => 'commercial', 'safe' => 'safety'] as $needle => $k) if (str_contains($l, $needle) && isset(iss_cat_defs()[$k])) return $k;
    return 'other';
}
function iss_cat_save(): string {
    require_cp_admin(); if (!iss_cat_table()) throw new RuntimeException(t('isc.need_db'));
    $key = (string)($_POST['skey'] ?? ''); $label = mb_substr(trim((string)($_POST['label'] ?? '')), 0, 80);
    $tone = in_array($_POST['tone'] ?? '', ['gold', 'green', 'blue', 'navy', 'mute', 'bad'], true) ? $_POST['tone'] : 'mute';
    $order = (int)($_POST['sort_order'] ?? 50); $active = (int)!empty($_POST['is_active']);
    $defs = iss_cat_defs();
    if ($key !== '' && isset($defs[$key])) {
        if ($defs[$key]['is_builtin']) q('UPDATE issue_categories SET tone = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$tone, $order, $key === 'other' ? 1 : $active, $key]);
        else { if ($label === '') throw new RuntimeException(t('isc.need_label')); q('UPDATE issue_categories SET label = ?, tone = ?, sort_order = ?, is_active = ? WHERE skey = ?', [$label, $tone, $order, $active, $key]); }
        $GLOBALS['iss_cat_dirty'] = true; return $key;
    }
    if ($label === '') throw new RuntimeException(t('isc.need_label'));
    $key = substr(preg_replace('/[^a-z0-9]+/', '_', strtolower(@iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: $label)), 0, 26); $key = trim($key, '_') ?: 'c' . time();
    $base = $key; $n = 1; while (isset($defs[$key])) $key = $base . '_' . (++$n);
    q('INSERT INTO issue_categories (skey, label, tone, sort_order, is_builtin, is_active) VALUES (?,?,?,?,0,1)', [$key, $label, $tone, $order]);
    $GLOBALS['iss_cat_dirty'] = true; return $key;
}
function iss_cat_delete(string $key): void {
    require_cp_admin(); $d = iss_cat_defs()[$key] ?? null; if (!$d || $d['is_builtin']) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM sp_issues WHERE category = ?', [$key])) throw new RuntimeException(t('isc.in_use'));
    q('DELETE FROM issue_categories WHERE skey = ?', [$key]); $GLOBALS['iss_cat_dirty'] = true;
}

/** The "Lists" sheet behind the drop-downs of the issues workbook: categories (as configured), who acts, priority, status, visibility, staff names. */
function iss_subs_labels(int $projectId): array { return $projectId ? array_map(fn($s) => trim($s['code'] . ' ' . $s['name']), all('SELECT code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$projectId])) : []; }
function iss_xlsx_lists_sheet(int $projectId = 0): array {
    $cols = [array_values(iss_cats()), array_values(iss_sides()), [t('td.p_low'), t('td.p_normal'), t('td.p_high')], array_values(iss_statuses()), [t('vis.external'), t('vis.internal')], array_column(staff_users(), 'full_name'), iss_subs_labels($projectId)];
    $n = max(array_map('count', $cols)); $rows = [];
    for ($i = 0; $i < $n; $i++) { $row = []; foreach ($cols as $c) $row[] = $c[$i] ?? ''; $rows[] = $row; }
    return ['name' => 'Lists', 'header' => [t('iss.category'), t('iss.side'), t('tk.priority'), t('th.status'), t('vis.label'), t('td.assignee'), t('sp.one')], 'rows' => $rows, 'widths' => [30, 20, 12, 14, 18, 28, 34]];
}
/** Column → list reference for the issues workbook (header: No · Sub-project · Issue · Category · Details · To be done by · Priority · Raised · Due · Assigned to · Status · Closed on · Closing note · Visibility · …). */
function iss_xlsx_listrefs(int $projectId = 0): array {
    $n = fn(array $a) => max(2, count($a) + 1);
    $out = [3 => 'Lists!$A$2:$A$' . $n(iss_cats()), 5 => 'Lists!$B$2:$B$' . $n(iss_sides()), 6 => 'Lists!$C$2:$C$4', 10 => 'Lists!$D$2:$D$4', 13 => 'Lists!$E$2:$E$3', 9 => 'Lists!$F$2:$F$' . $n(staff_users())];
    if ($projectId && iss_subs_labels($projectId)) $out[1] = 'Lists!$G$2:$G$' . $n(iss_subs_labels($projectId));
    return $out;
}
/** The import template, generated live so the drop-downs carry the categories and staff of this portal. ?p=import&tpl=issues */
function iss_template_download(): void {
    require_staff(); require_once __DIR__ . '/xlsxw.php';
    $projectId = (int)($_GET['project'] ?? 0); if (!$projectId && ($sg = (int)($_GET['sub'] ?? 0))) $projectId = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sg]);
    $subs = iss_subs_labels($projectId); $sub0 = $subs[0] ?? ''; $sub1 = $subs[1] ?? $sub0;
    $cats = array_values(iss_cats()); $sides = iss_sides(); $st = iss_statuses();
    $rows = [
        ['', $sub0, 'Spare fan for shore converter to be delivered and fitted', $cats[1] ?? $cats[0], 'Fan ordered from ATLAS on 10 Oct; ETA week 44', $sides['golden'], t('td.p_high'), '2026-10-09', '2026-10-30', (staff_users()[0]['full_name'] ?? ''), $st['open'], '', '', t('vis.external')],
        ['', $sub0, 'Owner to confirm cable route on bridge', $cats[1] ?? $cats[0], 'Waiting for captain', $sides['customer'], t('td.p_normal'), '2026-10-10', '2026-10-24', '', $st['in_progress'], '', '', t('vis.external')],
        ['', $sub1, 'Yard to close the cable transit at frame 42', $cats[0], '', $sides['third'], t('td.p_normal'), '2026-10-05', '', '', $st['closed'], '2026-10-18', 'Closed by the yard on 18 Oct', t('vis.external')],
    ];
    $notes = [[t('iss.tpl_n0')], [''], [t('iss.tpl_n1')], [t('iss.tpl_n2')], [t('iss.tpl_n3')], [t('iss.tpl_n4')], [t('iss.tpl_n5')], [t('iss.tpl_n6')], [t('iss.tpl_n7')], [t('iss.tpl_n8')], [t('iss.tpl_n9')]];
    xlsx_download('GAS-issues-import-template.xlsx', [
        ['name' => 'Open issues', 'first' => true, 'listrefs' => iss_xlsx_listrefs($projectId), 'header' => ['No', 'Sub-project', 'Issue', 'Category', 'Details', 'To be done by', 'Priority', 'Raised', 'Due', 'Assigned to', 'Status', 'Closed on', 'Closing note', 'Visibility'], 'rows' => $rows, 'widths' => [18, 16, 48, 24, 40, 16, 10, 12, 12, 22, 12, 12, 30, 12], 'wrap' => [2, 4, 12]],
        iss_xlsx_lists_sheet($projectId),
        ['name' => 'Notes', 'header' => [t('iss.tpl_title')], 'rows' => $notes, 'widths' => [120], 'wrap' => [0]],
    ]);
}

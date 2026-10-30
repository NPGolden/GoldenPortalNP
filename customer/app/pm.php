<?php
/**
 * GAS Customer Portal — Project-management core.           GAS-CP-R3-001
 *
 * Roles, project tree, 7-stage process line, visibility rules, file
 * storage, and the small data helpers every PM view shares.
 */
declare(strict_types=1);

/* ------------------------------------------------------------ roles */

function is_staff(): bool {
    $u = current_user();
    return $u && in_array($u['role'] ?? 'customer', ['staff', 'admin'], true);
}
function is_cp_admin(): bool {
    $u = current_user();
    return $u && ($u['role'] ?? '') === 'admin';
}
function require_staff(): array {
    $u = require_login();
    if (!is_staff()) { http_response_code(403); exit(t('err.noaccess')); }
    return $u;
}
function require_cp_admin(): array {
    $u = require_login();
    if (!is_cp_admin()) { http_response_code(403); exit(t('err.noaccess')); }
    return $u;
}

/** SQL fragment restricting rows to what the signed-in person may see.
 *  Staff see everything; a customer sees only their own customer_id. */
function scope_sql(string $alias = 'p'): array {
    if (is_staff()) return function_exists('br_scope_sql') ? br_scope_sql($alias) : ['1 = 1', []];   // R24-003 branch scope (off by default)
    $u = current_user();
    return ["{$alias}.customer_id = ?", [(int)$u['customer_id']]];
}

/** Visibility filter for comments / notes / files: customers only ever
 *  see 'external'; staff see both. */
function vis_sql(string $col = 'visibility'): array {
    return is_staff() ? ['1 = 1', []] : ["{$col} = 'external'", []];
}

/** Breadcrumb link to a project on the project list (opened and scrolled to).   R24-002 */
function pr_link(int $pid, ?string $code, ?string $name = null): string {
    $label = (string)($code ?: $name ?: ('#' . $pid));
    return '<a href="' . url('projects', ['q' => $label, 'f' => 1, 'open' => $pid]) . '#pr' . $pid . '" title="' . e((string)($name ?? '')) . '">' . e($label) . '</a>';
}

/* ------------------------------------------------------- project tree */

/** The delimiter that separates a main project from its sub-projects in
 *  the ERP code. "GY-85-02.Y.01" -> main "GY-85-02", sub "Y.01". */
const PROJECT_SUB_DELIM = 'Y.';

/** Split an ERP project code into [mainCode, subCode|null]. */
function split_project_code(string $code): array {
    $code = trim($code);
    $pos  = strpos($code, PROJECT_SUB_DELIM);
    if ($pos === false) return [$code, null];
    $main = rtrim(substr($code, 0, $pos), '.-_ /');
    $sub  = substr($code, $pos);                      // keep the "Y.01" part
    return [$main !== '' ? $main : $code, $sub];
}

/** R57-002: is this staff member a project manager at all — PM of at least one project, or a staff role named "Project Manager"?
 *  Every PM may edit teams and budgets of every project, not only his own, so work is not held up by one person. */
function pm_is_pm(?int $uid = null): bool {
    static $c = [];
    $uid = $uid ?? (int)(current_user()['id'] ?? 0); if (!$uid || !is_staff()) return false;
    if (!isset($c[$uid])) $c[$uid] = (bool) scalar("SELECT (SELECT COUNT(*) FROM projects WHERE pm_user_id = ?) + (SELECT COUNT(*) FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.id = ? AND LOWER(r.name) LIKE '%project manager%')", [$uid, $uid]);
    return $c[$uid];
}

/** Load the tree the current person may see: main projects with their
 *  sub-projects (ordered), each sub carrying its 7 stage percentages. */
function project_tree(?int $onlyProject = null): array {
    [$scope, $args] = scope_sql('p');
    $w = "WHERE {$scope}";
    if ($onlyProject) { $w .= ' AND p.id = ?'; $args[] = $onlyProject; }
    $projects = all("SELECT p.*, v.name AS vessel, c.name AS customer_name
                     FROM projects p
                     LEFT JOIN vessels v ON v.id = p.vessel_id
                     LEFT JOIN customers c ON c.id = p.customer_id
                     {$w}
                     ORDER BY FIELD(p.status,'active','quoted','onhold','completed'), p.project_code, p.name", $args);
    if (!$projects) return [];
    $ids  = implode(',', array_map('intval', array_column($projects, 'id')));
    $subs = all("SELECT s.*, vs.name AS system_name
                 FROM subprojects s LEFT JOIN vessel_systems vs ON vs.id = s.system_id
                 WHERE s.project_id IN ({$ids})
                 ORDER BY s.sort_order, s.code, s.name");
    $by = [];
    foreach ($subs as $s) $by[(int)$s['project_id']][] = $s;
    foreach ($projects as &$p) $p['subs'] = $by[(int)$p['id']] ?? [];
    return $projects;
}

/** Filtered, paged variant of project_tree() for the Projects page.
 *  $f: status => [..], customer => id, vessel => id, pm => name, q => text.
 *  Returns ['rows' => main projects with subs, 'total' => count]. */
function project_tree_filtered(array $f, int $limit, int $offset): array {
    [$scope, $args] = scope_sql('p');
    $w = ["{$scope}"];
    if (!empty($f['status']))   { $w[] = 'p.status IN (' . implode(',', array_fill(0, count($f['status']), '?')) . ')'; array_push($args, ...$f['status']); }
    if (!empty($f['customer'])) { $w[] = 'p.customer_id = ?'; $args[] = (int)$f['customer']; }
    if (!empty($f['vessel']))   { $w[] = 'p.vessel_id = ?';   $args[] = (int)$f['vessel']; }
    if (!empty($f['pm']))       { $w[] = 'p.project_manager = ?'; $args[] = $f['pm']; }
    if (!empty($f['branch']) && function_exists('br_filter_sql')) { [$bs, $ba] = br_filter_sql('p', (int)$f['branch']); $w[] = $bs; array_push($args, ...$ba); }   // R24-003
    if (($f['q'] ?? '') !== '') {
        $like = '%' . $f['q'] . '%';
        $w[] = '(p.project_code LIKE ? OR p.erp_code LIKE ? OR p.name LIKE ? OR v.name LIKE ? OR c.name LIKE ? OR p.project_manager LIKE ?
                 OR EXISTS (SELECT 1 FROM subprojects sx LEFT JOIN vessel_systems vsx ON vsx.id = sx.system_id
                            WHERE sx.project_id = p.id AND (sx.code LIKE ? OR sx.name LIKE ? OR vsx.name LIKE ?)))';
        array_push($args, ...array_fill(0, 9, $like));
    }
    $from = 'FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE ' . implode(' AND ', $w);
    $total = (int) scalar("SELECT COUNT(*) {$from}", $args);
    $subsTotal = (int) scalar("SELECT COUNT(*) FROM subprojects s WHERE s.project_id IN (SELECT p.id {$from})", $args);
    $projects = all("SELECT p.*, v.name AS vessel, c.name AS customer_name {$from}
                     ORDER BY FIELD(p.status,'active','quoted','onhold','completed'), p.project_code, p.name
                     LIMIT " . (int)$limit . ' OFFSET ' . (int)$offset, $args);
    if ($projects) {
        $ids  = implode(',', array_map('intval', array_column($projects, 'id')));
        $subs = all("SELECT s.*, vs.name AS system_name FROM subprojects s LEFT JOIN vessel_systems vs ON vs.id = s.system_id
                     WHERE s.project_id IN ({$ids}) ORDER BY s.sort_order, s.code, s.name");
        $by = [];
        foreach ($subs as $s) $by[(int)$s['project_id']][] = $s;
        foreach ($projects as &$p) $p['subs'] = $by[(int)$p['id']] ?? [];
    }
    return ['rows' => $projects, 'total' => $total, 'subs_total' => $subsTotal];
}

/** Paging controls that keep every active filter. */
function pager_html(string $slug, int $page, int $total, int $per, array $keep, string $param = 'page'): string {
    $pages = max(1, (int) ceil($total / max(1, $per)));
    $a = $total ? ($page - 1) * $per + 1 : 0; $b = min($total, $page * $per);
    $h = '<div class="pager"><span class="hint">' . e(t('pg.showing', ['a' => $a, 'b' => $b, 'n' => $total])) . '</span>';
    if ($pages > 1) {
        $h .= '<span class="pager-nav">';
        $h .= $page > 1 ? '<a class="btn btn--ghost btn--sm" href="' . url($slug, $keep + [$param => $page - 1]) . '">&lsaquo;</a>' : '<span class="btn btn--ghost btn--sm is-off">&lsaquo;</span>';
        $h .= '<span class="hint">' . e(t('pg.page', ['n' => $page, 'total' => $pages])) . '</span>';
        $h .= $page < $pages ? '<a class="btn btn--ghost btn--sm" href="' . url($slug, $keep + [$param => $page + 1]) . '">&rsaquo;</a>' : '<span class="btn btn--ghost btn--sm is-off">&rsaquo;</span>';
        $h .= '</span>';
    }
    return $h . '</div>';
}

/** Page a list that is already loaded: the slice for the current page and a pager bar with the 25 / 50 / 100
 *  per-page chips (?page=N&per=N). Totals computed from the full list stay valid.   GAS-CP-R24-003 */
function page_list(array $rows, string $slug, array $keep = [], string $param = 'page'): array {
    $per = per_page(); $total = count($rows); $pages = max(1, (int) ceil($total / $per)); $page = min($pages, max(1, (int)($_GET[$param] ?? 1)));
    $keep = array_filter($keep, fn($v) => $v !== '' && $v !== null && $v !== [] && $v !== 0 && $v !== false); unset($keep[$param], $keep['per']);
    if ($param !== 'page') foreach ($_GET as $k => $v) if (!isset($keep[$k]) && preg_match('/^[a-z]+page$/', (string)$k) && $k !== $param) $keep[$k] = $v;   // other tables on the page keep their page
    $slice = array_slice($rows, ($page - 1) * $per, $per);
    if ($total <= 25 && $per === 25) return [$slice, ''];
    $html = pager_html($slug, $page, $total, $per, $keep + ['per' => $per], $param);
    $chips = '<span class="pager-per"><span class="hint">' . tt('pg.per') . '</span>';
    foreach ([25, 50, 100] as $n) $chips .= $n === $per ? '<span class="btn btn--sm btn--ghost is-on">' . $n . '</span>' : '<a class="btn btn--sm btn--ghost" href="' . url($slug, $keep + ['per' => $n]) . '">' . $n . '</a>';
    return [$slice, str_replace('</div>', $chips . '</span></div>', $html)];
}

/** Per-page selection (25 / 50 / 100) from the request. */
function per_page(): int {
    $per = (int)($_GET['per'] ?? 25);
    return in_array($per, [25, 50, 100], true) ? $per : 25;
}

/** Golden A/S staff accounts (active staff + admins) with their Golden
 *  role, Project Managers first — the pick-list for project managers. */
function staff_users(): array {
    return all("SELECT cu.id, cu.full_name, cu.email, cu.role, r.name AS role_name
                FROM customer_users cu LEFT JOIN staff_roles r ON r.id = cu.staff_role_id
                WHERE cu.role IN ('staff','admin') AND cu.is_active = 1
                ORDER BY (r.name = 'Project Manager') DESC, r.sort_order, cu.full_name");
}

/** Resolve the PM fields for a project from the posted pm_user_id:
 *  [pm_user_id|null, project_manager name|null]. 'keep' leaves a legacy
 *  free-text name in place. */
function pm_from_post(?string $current = null): array {
    $v = (string)($_POST['pm_user_id'] ?? '');
    if ($v === 'keep') return [null, $current];
    if ($v === '' || !ctype_digit($v)) return [null, null];
    $u = one("SELECT id, full_name FROM customer_users WHERE id = ? AND role IN ('staff','admin')", [(int)$v]);
    return $u ? [(int)$u['id'], $u['full_name']] : [null, null];
}

/** Find a staff account by full name or email (imports). */
function staff_by_name(string $name): ?array {
    $name = trim($name);
    if ($name === '') return null;
    return one("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND (LOWER(full_name) = LOWER(?) OR LOWER(email) = LOWER(?)) LIMIT 1", [$name, $name]);
}

/* ------------------------------------------------------ process line */

/** The seven stages, in order, with their column names. */
function stage_defs(): array {
    return [
        'engineering'   => ['col' => 'pct_engineering',   'key' => 'st.engineering'],
        'procurement'   => ['col' => 'pct_procurement',   'key' => 'st.procurement'],
        'production'    => ['col' => 'pct_production',    'key' => 'st.production'],
        'fat'           => ['col' => 'pct_fat',           'key' => 'st.fat'],
        'installation'  => ['col' => 'pct_installation',  'key' => 'st.installation'],
        'sathat'        => ['col' => 'pct_sathat',        'key' => 'st.sathat'],
        'commissioning' => ['col' => 'pct_commissioning', 'key' => 'st.commissioning'],
    ];
}

/** A stage marked "not applicable" is stored as -1 (R24-004): it is left out of every average and shown as N/A. */
const PCT_NA = -1;
function pct_na($v): bool { return (int)$v < 0; }
function pct_txt($v): string { return pct_na($v) ? t('sp.na') : ((int)$v . '%'); }
/** Average of the stage percentages that apply (0 when none applies). */
function pct_avg(array $vals): int { $vals = array_values(array_filter(array_map('intval', $vals), fn($v) => $v >= 0)); return $vals ? (int) round(array_sum($vals) / count($vals)) : 0; }
/** Overall % for a sub-project — the stages that apply, equal weights. */
function overall_pct(array $sub): int {
    $vals = []; foreach (stage_defs() as $d) $vals[] = (int)($sub[$d['col']] ?? 0);
    return pct_avg($vals);
}

/** Render the 7-stage process line as filled segments.
 *  Each stage is a rounded block that fills from the left in proportion to
 *  its %: green when complete, gold while in progress, outlined when not
 *  started. $compact = the tree-row version: a short stage abbreviation
 *  sits inside each bar (ENG, PROC, …) so the row reads without hovering;
 *  the tooltip still carries the full name and the percentage. */
function process_line(array $sub, bool $compact = false): string {
    $h = '<div class="stepper' . ($compact ? ' stepper--compact' : '') . '" role="list">';
    foreach (stage_defs() as $slug => $d) {
        $raw  = (int)($sub[$d['col']] ?? 0); $na = pct_na($raw);
        $pct  = $na ? 0 : max(0, min(100, $raw));
        $tone = $na ? 'na' : ($pct >= 100 ? 'done' : ($pct > 0 ? 'active' : 'todo'));
        $lbl  = t($d['key']); $ptxt = $na ? t('sp.na') : $pct . '%';
        $fed = in_array($slug, $sub['_fed'] ?? [], true);                                                   // R42-001: stage fed from the production orders
        $h .= '<div class="seg seg--' . $tone . ($fed ? ' seg--fed' : '') . '" role="listitem" title="' . e($lbl . ' · ' . ($na ? t('sp.na_long') : $pct . '%') . ($fed ? ' · 🏭 ' . t('pd.fed_mark') : '')) . '">'
            . '<i class="seg-fill" style="width:' . $pct . '%"></i>'
            . ($compact
                ? '<span class="seg-abbr"><b>' . e(t('st.ab.' . $slug)) . ($fed ? '<s class="seg-fed">🏭</s>' : '') . '</b><em>' . e($ptxt) . '</em></span>'
                : '<span class="seg-txt"><b>' . e($lbl) . ($fed ? ' <s class="seg-fed">🏭</s>' : '') . '</b><em>' . e($ptxt) . '</em></span>')
            . '</div>';
    }
    return $h . '</div>';
}

/** Overall progress as a single filled pill with the percentage inside. */
function overall_pill(array $sub): string {
    $pct  = overall_pct($sub);
    $tone = $pct >= 100 ? 'done' : ($pct > 0 ? 'active' : 'todo');
    return '<div class="opill opill--' . $tone . '" title="' . e(t('sp.overall')) . ' ' . $pct . '%">'
         . '<i style="--w:' . $pct . '%"></i><span>' . $pct . '%</span></div>';
}

/* ------------------------------------------------------------- files */

function cp_storage(): string {
    return rtrim((string) cfg('app.storage_path', dirname(__DIR__) . '/storage/uploads'), '/');
}

/** Store an uploaded file under storage/uploads/YYYY/MM and record it.
 *  Returns the sp_files id, or null when nothing valid was uploaded. */
function cp_store_file(array $file, int $projectId, ?int $subId, string $kind, string $visibility,
                       ?string $title = null, ?int $noteId = null, ?int $fsrId = null, array $meta = []): ?int {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return null;
    $allowed = ['image/jpeg','image/png','image/webp','image/gif','image/svg+xml',
                'application/pdf',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/msword','application/vnd.ms-excel','application/zip','application/octet-stream'];
    $mime = (string)(mime_content_type($file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
    $ext  = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($mime, $allowed, true) && !in_array($ext, ['dwg','dxf','xlsx','docx','pdf','png','jpg','jpeg','svg'], true)) {
        throw new RuntimeException(t('err.filetype'));
    }
    $dir = cp_storage() . '/' . date('Y/m');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = date('Y/m') . '/' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
    if (!move_uploaded_file($file['tmp_name'], cp_storage() . '/' . $stored)) {
        throw new RuntimeException(t('err.upload'));
    }
    $u = current_user();
    $vals = [$projectId, $subId, $kind, $title ?: null, $stored, mb_substr((string)$file['name'], 0, 255), $mime,
             (int)$file['size'], $visibility, $noteId, (int)($u['id'] ?? 0) ?: null];
    try {
        q('INSERT INTO sp_files (project_id, subproject_id, kind, title, stored_name, original_name, mime_type,
                                 size_bytes, visibility, note_id, uploaded_by, fsr_id)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', [...$vals, $fsrId]);
    } catch (Throwable $e) {                                   // before the R7 migration: no fsr_id column
        q('INSERT INTO sp_files (project_id, subproject_id, kind, title, stored_name, original_name, mime_type,
                                 size_bytes, visibility, note_id, uploaded_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $vals);
    }
    $newId = (int) db()->lastInsertId();
    if (function_exists('ar_file_new')) ar_file_new('sp_files', $newId, $meta);          // R20: file it in the archive
    return $newId;
}

/** Store generated bytes (a PDF, a .docx, a signature PNG) as an sp_files
 *  row under storage/uploads; returns the file id.               R7 */
function cp_store_bytes(string $bytes, string $originalName, string $mime, int $projectId, ?int $subId, string $kind,
                        string $visibility = 'external', ?int $fsrId = null, ?string $title = null, array $meta = []): int {
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $dir = cp_storage() . '/' . date('Y/m');
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $stored = date('Y/m') . '/' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
    if (file_put_contents(cp_storage() . '/' . $stored, $bytes) === false) throw new RuntimeException(t('err.upload'));
    $u = current_user();
    q('INSERT INTO sp_files (project_id, subproject_id, kind, title, stored_name, original_name, mime_type, size_bytes, visibility, fsr_id, uploaded_by)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$projectId, $subId, $kind, $title, $stored, mb_substr($originalName, 0, 255), $mime, strlen($bytes), $visibility, $fsrId, (int)($u['id'] ?? 0) ?: null]);
    $newId = (int) db()->lastInsertId();
    if (function_exists('ar_file_new')) ar_file_new('sp_files', $newId, $meta);          // R20
    return $newId;
}
/** Read a stored file's bytes (null when missing). */
function cp_file_bytes(?int $fileId): ?string {
    if (!$fileId) return null;
    $f = one('SELECT stored_name FROM sp_files WHERE id = ?', [$fileId]);
    $p = $f ? cp_storage() . '/' . $f['stored_name'] : '';
    return ($p && is_file($p)) ? (string) file_get_contents($p) : null;
}
/** Delete a stored file (row + bytes). */
function cp_delete_file(?int $fileId): void {
    if (!$fileId) return;
    $f = one('SELECT stored_name FROM sp_files WHERE id = ?', [$fileId]);
    if (!$f) return;
    $p = cp_storage() . '/' . $f['stored_name']; if (is_file($p)) @unlink($p);
    q('DELETE FROM sp_files WHERE id = ?', [$fileId]);
}

/** Normalise $_FILES['x'] (single or multiple) into a list of file arrays. */
function files_list(string $field): array {
    if (empty($_FILES[$field])) return [];
    $f = $_FILES[$field];
    if (!is_array($f['name'])) return [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i],
                  'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

/** Stream a stored file after an access check. */
function cp_stream_file(int $fileId): void {
    $f = one('SELECT f.*, p.customer_id FROM sp_files f JOIN projects p ON p.id = f.project_id WHERE f.id = ?', [$fileId]);
    if (!$f) { http_response_code(404); exit('Not found'); }
    if (!is_staff()) {
        $u = current_user();
        if ((int)$f['customer_id'] !== (int)$u['customer_id'] || $f['visibility'] !== 'external') {
            http_response_code(403); exit(t('err.noaccess'));
        }
    }
    $path = cp_storage() . '/' . $f['stored_name'];
    if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    header('Content-Type: ' . $f['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, max-age=600');
    $inline = str_starts_with($f['mime_type'], 'image/') || $f['mime_type'] === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode(function_exists('ar_download_name') ? ar_download_name($f) : $f['original_name']) . '"');
    readfile($path);
    exit;
}

/* ------------------------------------------------------------- misc */

function iso_week(string $date): string {
    $t = strtotime($date) ?: time();
    return date('o-\WW', $t);
}

function pill(string $tone, string $label): string {
    return '<span class="pill pill--' . e($tone) . '">' . e($label) . '</span>';
}

/** Status pills shared by owner approvals, class submittals, tests. */
function submittal_pill(string $s): string {
    $m = ['draft' => 'mute', 'submitted' => 'gold', 'approved' => 'green', 'approved_comments' => 'green',
          'rejected' => 'bad', 'resubmitted' => 'gold'];
    return pill($m[$s] ?? 'mute', t('sub.' . $s));
}
function test_pill(string $s): string {
    $m = ['planned' => 'mute', 'performed' => 'gold', 'passed' => 'green', 'punch_open' => 'gold',
          'failed' => 'bad', 'closed' => 'green'];
    return pill($m[$s] ?? 'mute', t('tst.' . $s));
}
function drawing_pill(string $s): string {
    $m = ['pending' => 'gold', 'received' => 'green', 'not_required' => 'mute'];
    return pill($m[$s] ?? 'mute', t('dr.' . $s));
}

/** Load a sub-project the current person may see, with its project. */
function load_subproject(int $id): ?array {
    [$scope, $args] = scope_sql('p');
    array_unshift($args, $id);
    return one("SELECT s.*, p.name AS project_name, p.project_code, p.customer_id, p.vessel_id, p.pm_user_id,
                       v.name AS vessel, vs.name AS system_name, c.name AS customer_name
                FROM subprojects s
                JOIN projects p ON p.id = s.project_id
                LEFT JOIN vessels v ON v.id = p.vessel_id
                LEFT JOIN vessel_systems vs ON vs.id = s.system_id
                LEFT JOIN customers c ON c.id = p.customer_id
                WHERE s.id = ? AND {$scope}", $args);
}

/* ======================================== vessel systems helpers (R9) */

/** True once migrate_cp_r9_systems.sql has added code / abbr columns. */
function sys_has_codes(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW COLUMNS FROM vessel_systems LIKE 'abbr'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}
/** Active systems for pick-lists, in drawing-code order. */
function sys_options(): array {
    return all('SELECT vs.* FROM vessel_systems vs WHERE vs.is_active = 1 ORDER BY vs.sort_order, vs.name');
}
/** "MSB — MAIN SWITCHBOARD (10)" when the row carries a code / abbreviation, else the plain name. */
function sys_label(array $s): string {
    $abbr = trim((string)($s['abbr'] ?? '')); $code = trim((string)($s['code'] ?? ''));
    $name = (string)($s['name'] ?? '');
    $out  = $abbr !== '' ? $abbr . ' — ' . $name : $name;
    return $code !== '' ? $out . ' (' . $code . ')' : $out;
}
/** <option> list for a system select. */
function sys_select_options($selected): string {
    $h = '';
    foreach (sys_options() as $s) {
        $h .= '<option value="' . (int)$s['id'] . '"' . ((int)$selected === (int)$s['id'] ? ' selected' : '') . '>' . e(sys_label($s)) . '</option>';
    }
    return $h;
}

/* ================================ colour code & invoicing (R10) */

/** True once migrate_cp_r10_approvals_invoicing.sql has run. */
/** R18: may the signed-in person see money (offer amounts, invoicing)? Owners always (it is their money), administrators always,
 *  Golden A/S staff according to the "Financial data visible" switch of their Golden A/S role (roles default to visible). */
function fin_visible(): bool {
    static $v = null;
    if ($v !== null) return $v;
    $u = current_user();
    if (!$u) return $v = false;
    if (!is_staff() || is_cp_admin()) return $v = true;
    try {
        if (!(bool) db()->query("SHOW COLUMNS FROM staff_roles LIKE 'see_financials'")->fetch()) return $v = true;
        if (empty($u['staff_role_id'])) return $v = true;
        $f = scalar('SELECT see_financials FROM staff_roles WHERE id = ?', [(int)$u['staff_role_id']]);
        return $v = ($f === null || (int)$f === 1);
    } catch (Throwable $e) { return $v = true; }
}
/** Replacement for a masked amount. */
function fin_masked(): string { return t('fin.hidden'); }
function inv_has(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW COLUMNS FROM subprojects LIKE 'invoicing'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has && fin_visible();
}
function inv_states(): array { return ['none' => t('inv.none'), 'partial' => t('inv.partial'), 'full' => t('inv.full')]; }
function inv_norm(string $v): ?string {
    $k = strtolower(preg_replace('/[^a-z0-9]/', '', strtolower($v)));
    $m = ['none' => 'none', 'no' => 'none', 'notinvoiced' => 'none', 'open' => 'none', '0' => 'none',
          'partial' => 'partial', 'partially' => 'partial', 'partiallyinvoiced' => 'partial', 'part' => 'partial',
          'full' => 'full', 'fully' => 'full', 'fullyinvoiced' => 'full', 'invoiced' => 'full', 'yes' => 'full', 'complete' => 'full', '100' => 'full'];
    return $m[$k] ?? null;
}
/** Invoicing pill: grey = not invoiced, gold = partial (with the %), green = fully invoiced. */
function inv_pill(array $sub, bool $short = false): string {
    $st  = (string)($sub['invoicing'] ?? 'none');
    $pct = (int)($sub['invoiced_pct'] ?? 0);
    $tone = ['none' => 'mute', 'partial' => 'gold', 'full' => 'green'][$st] ?? 'mute';
    $lbl = $st === 'partial' && $pct > 0 ? ($short ? t('inv.short_partial', ['n' => $pct]) : t('inv.partial_pct', ['n' => $pct])) : ($short ? t('inv.short_' . $st) : t('inv.' . $st));
    return '<span class="pill pill--' . $tone . ' pill--inv" title="' . e(t('inv.title') . ': ' . t('inv.' . $st) . ($pct ? ' ' . $pct . '%' : '')) . '">' . e($lbl) . '</span>';
}
/** Stage percentages of a main project = average of its sub-projects (completed ones count as 100 on every stage they reached). */
function project_stage_avg(array $subs): array {
    $out = [];
    foreach (stage_defs() as $d) {                       // per stage: only the sub-projects where the stage applies; N/A everywhere → N/A (R30-006)
        $sum = 0; $n = 0;
        foreach ($subs as $s) { $v = (int)($s[$d['col']] ?? 0); if (pct_na($v)) continue; $sum += max(0, min(100, $v)); $n++; }
        $out[$d['col']] = $n ? (int) round($sum / $n) : PCT_NA;
    }
    return $out;
}
/** Overall % of a main project = average of its sub-projects' overall % (each over its applicable stages). */
function project_overall_pct(array $subs): int { return $subs ? pct_avg(array_map('overall_pct', $subs)) : 0; }
/** Invoicing summary of a main project: [none, partial, full] counts. */
function project_inv_summary(array $subs): array {
    $c = ['none' => 0, 'partial' => 0, 'full' => 0];
    foreach ($subs as $s) $c[(string)($s['invoicing'] ?? 'none')] = ($c[(string)($s['invoicing'] ?? 'none')] ?? 0) + 1;
    return $c;
}
/** The legend printed above process lines: stage colours + invoicing colours. */
function process_legend(bool $withInvoicing = true): string {
    $h = '<div class="legend"><span class="legend-t">' . tt('lg.title') . '</span>'
       . '<span class="legend-i"><i class="sw sw--todo"></i>' . tt('lg.todo') . '</span>'
       . '<span class="legend-i"><i class="sw sw--active"></i>' . tt('lg.active') . '</span>'
       . '<span class="legend-i"><i class="sw sw--done"></i>' . tt('lg.done') . '</span>'
       . '<span class="legend-i"><i class="sw sw--na"></i>' . tt('lg.na') . '</span>';
    if ($withInvoicing && inv_has()) {
        $h .= '<span class="legend-sep"></span><span class="legend-t">' . tt('inv.title') . '</span>'
            . '<span class="legend-i"><i class="sw sw--inv-none"></i>' . tt('inv.none') . '</span>'
            . '<span class="legend-i"><i class="sw sw--inv-partial"></i>' . tt('inv.partial') . '</span>'
            . '<span class="legend-i"><i class="sw sw--inv-full"></i>' . tt('inv.full') . '</span>';
    }
    return $h . '</div>';
}

/** A project without vessel: the registered vessel its name points at (name, "(ex. …)" former name, alias), or null.   GAS-CP-R36-006 */
function pr_vessel_guess(array $p): ?array {
    if (!empty($p['vessel_id']) || !function_exists('vm_find')) return null;
    $name = (string)($p['name'] ?? ''); if (trim($name) === '') return null;
    $cands = [$name];
    foreach (preg_split('~\s+[—–-]\s+|\s*[/|]\s*~u', $name) as $part) if (trim($part) !== '') $cands[] = trim($part);
    $cands[] = preg_replace('~\(.*?\)~u', ' ', $name);
    if (($i = mb_strpos($name, '(')) !== false && $i > 0) $cands[] = mb_substr($name, 0, $i);           // "NAME (ex. OLD) — PRJ.0001" → "NAME"
    $noCode = trim(preg_replace('~[^\p{L}\p{N} /.\'-]+~u', ' ', preg_replace('~\b(PRJ|GY|GAS|MY)[.\-]?\d[\w.\-]*~iu', ' ', preg_replace('~\(.*?\)~u', ' ', $name))));   // project codes and stray symbols out
    if ($noCode !== '') $cands[] = $noCode;
    if (($x = vm_ex_name($name)) !== '') $cands[] = $x;
    foreach (array_unique(array_map('trim', $cands)) as $c) {
        if ($c === '' || mb_strlen($c) < 3) continue;
        $vid = vm_find($c, null, (int)($p['customer_id'] ?? 0) ?: null);
        if ($vid) { $v = one('SELECT id, name, customer_id FROM vessels WHERE id = ?', [$vid]); if ($v) return $v; }
    }
    return null;
}

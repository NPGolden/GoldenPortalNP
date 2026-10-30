<?php
/**
 * File archive — structured folders and document-numbered file names.      GAS-CP-R20-001
 * Design: GAS-2026-PORTAL-STO-001 (customer › project › sub-project › tab; <doc no>_<rev>_<title>.<ext>).
 *
 *   ar_has()                         migration applied (sp_files.sha256 + archive_moves)
 *   ar_target(string $tbl, array $row [, array $meta])   → ['dir' => rel dir, 'name' => file name, 'doc_no', 'revision', 'seq']
 *   ar_place(string $absSrc, string $relDir, string $name) → final relative path (collision-safe)
 *   ar_reorganise(bool $dry [, ?int $projectId])         move every registered file to its archive path
 *   ar_undo(string $runId)           reverse one reorganise run
 *   ar_verify(bool $full)            missing / mismatched / orphaned files
 *   ar_manifest()                    write storage/uploads/_manifest.csv
 *   ar_stats()                       size per customer and per family
 *   ar_issue(...)                    archive a portal-generated document with a revision
 *
 * Access is unchanged: files are still served only by id through cp_stream_file / tk_stream_file.
 */
declare(strict_types=1);

function ar_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) scalar("SHOW COLUMNS FROM sp_files LIKE 'sha256'") && (bool) scalar("SHOW TABLES LIKE 'archive_moves'"); } catch (Throwable $e) { $has = false; } }
    return $has;
}

/* ------------------------------------------------------------- names */

/** Greek → Latin, then everything non-ASCII dropped. */
function ar_translit(string $s): string {
    static $map = ['Α'=>'A','Β'=>'B','Γ'=>'G','Δ'=>'D','Ε'=>'E','Ζ'=>'Z','Η'=>'I','Θ'=>'TH','Ι'=>'I','Κ'=>'K','Λ'=>'L','Μ'=>'M','Ν'=>'N','Ξ'=>'X','Ο'=>'O','Π'=>'P','Ρ'=>'R','Σ'=>'S','Τ'=>'T','Υ'=>'Y','Φ'=>'F','Χ'=>'CH','Ψ'=>'PS','Ω'=>'O',
        'α'=>'a','β'=>'b','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
        'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y','Ά'=>'A','Έ'=>'E','Ή'=>'I','Ί'=>'I','Ό'=>'O','Ύ'=>'Y','Ώ'=>'O','Ϊ'=>'I','Ϋ'=>'Y',
        'ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss','Ä'=>'Ae','Ö'=>'Oe','Ü'=>'Ue','€'=>'EUR','&'=>'and'];
    $s = strtr($s, $map);
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return $t === false ? preg_replace('/[^\x20-\x7E]/', '', $s) : $t;
}
/** Title → slug: ASCII letters, digits, dot and hyphen; max $max characters. */
function ar_slug(string $s, int $max = 60): string {
    $s = ar_translit(trim($s));
    $s = preg_replace('/[^A-Za-z0-9.]+/', '-', $s);
    $s = trim(preg_replace('/-{2,}/', '-', $s), '-.');
    if (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), '-.');
    return $s;
}
/** One folder component: slug, no dots at the ends, max 80 characters, never empty. */
function ar_comp(string $s, string $fallback = 'x'): string {
    $c = ar_slug($s, 80);
    $c = trim($c, '.');
    return ($c === '' || $c === '.' || $c === '..') ? $fallback : $c;
}
/** A code that must be kept verbatim where possible (project / sub-project codes with dots and hyphens). */
function ar_code(string $s, string $fallback): string { $c = ar_comp($s, ''); return $c === '' ? $fallback : $c; }

function ar_customer_dir(?int $cid): string {
    static $c = [];
    if (!$cid) return '_unassigned';
    if (!isset($c[$cid])) {
        $r = one('SELECT id, name, erp_code FROM customers WHERE id = ?', [$cid]);
        $c[$cid] = $r ? ar_code((string)($r['erp_code'] ?: ''), 'C' . $cid) . '_' . ar_comp((string)$r['name'], 'customer') : '_unassigned';
    }
    return $c[$cid];
}
function ar_project(int $pid): ?array {
    static $c = [];
    if (!array_key_exists($pid, $c)) $c[$pid] = $pid ? one('SELECT p.id, p.customer_id, p.vessel_id, p.name, p.erp_code, p.project_code FROM projects p WHERE p.id = ?', [$pid]) : null;
    return $c[$pid];
}
function ar_sub(?int $sid): ?array {
    static $c = [];
    if (!$sid) return null;
    if (!array_key_exists($sid, $c)) $c[$sid] = one('SELECT id, project_id, code, name FROM subprojects WHERE id = ?', [$sid]);
    return $c[$sid];
}
function ar_project_dir(int $pid): string {
    $p = ar_project($pid);
    if (!$p) return '_unassigned/_project_' . $pid;
    $code = (string)($p['project_code'] ?: $p['erp_code'] ?: '');
    return 'customers/' . ar_customer_dir((int)$p['customer_id']) . '/' . ($code !== '' ? ar_code($code, 'P' . $pid) : 'P' . $pid . '_' . ar_comp((string)$p['name'], 'project'));
}
function ar_sub_dir(?int $sid): string {
    $s = ar_sub($sid);
    if (!$s) return '';
    return ($s['code'] ? ar_code((string)$s['code'], 'S' . $sid) : 'S' . $sid) . '_' . ar_comp((string)$s['name'], 'sub-project');
}
/** Short project / sub-project code used inside document numbers. */
function ar_pcode(int $pid): string { $p = ar_project($pid); return $p ? ar_code((string)($p['project_code'] ?: $p['erp_code'] ?: ''), 'P' . $pid) : 'P' . $pid; }
function ar_scode(?int $sid): string { $s = ar_sub($sid); return $s ? ($s['code'] ? ar_code((string)$s['code'], 'S' . $sid) : 'S' . $sid) : ''; }
function ar_vessel_dir(?int $vid): string {
    if (!$vid) return '_vessel_unknown';
    $v = one('SELECT name FROM vessels WHERE id = ?', [$vid]);
    return '_vessel_' . ar_comp((string)($v['name'] ?? ''), 'V' . $vid);
}

/** Next number of a sequence (atomic). */
function ar_seq(string $scope, string $family): int {
    if (!empty($GLOBALS['ar_dry'])) return (int) scalar('SELECT last_no FROM doc_sequences WHERE scope = ? AND family = ?', [$scope, $family]) + 1;   // check only: never consume a number
    q('INSERT INTO doc_sequences (scope, family, last_no) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)', [$scope, $family]);
    return (int) db()->lastInsertId();
}

const AR_TAB = ['drawing' => 'drawings', 'owner_doc' => 'owner-approvals', 'class_doc' => 'class', 'fat' => 'tests', 'hat' => 'tests', 'sat' => 'tests', 'photo' => 'weekly-notes',
                'weekly' => 'reports', 'monthly' => 'reports', 'issued' => 'reports', 'ga' => 'ga', 'other' => 'files', 'file' => 'files', 'comment' => 'files',
                'fsr_photo' => 'service-reports', 'fsr_sign' => 'service-reports', 'fsr_doc' => 'service-reports', 'offer' => 'offers',
                'isr_file' => 'inspections', 'isr_doc' => 'inspections'];
const AR_PROJECT_LEVEL = ['ga', 'offer'];

/** Where an sp_files row belongs and what it is called. Assigns a document number / sequence when the row has none
 *  (and persists it when the row has an id). $meta may carry doc_no, revision, seq, label from the caller. */
function ar_target(string $tbl, array $row, array $meta = []): array {
    if ($tbl === 'ticket_files') return ar_target_ticket($row, $meta);
    $pid = (int)$row['project_id']; $sid = (int)($row['subproject_id'] ?? 0) ?: null; $kind = (string)$row['kind'];
    $ext = strtolower(pathinfo((string)($row['original_name'] ?? $row['stored_name'] ?? ''), PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
    $tab = AR_TAB[$kind] ?? (function_exists('fk_tab') ? fk_tab($kind) : 'files');
    $docNo = (string)($meta['doc_no'] ?? $row['doc_no'] ?? ''); $rev = (string)($meta['revision'] ?? $row['revision'] ?? ''); $seq = (int)($meta['seq'] ?? $row['seq'] ?? 0);
    $pc = ar_pcode($pid); $sc = ar_scode($sid); $base = $pc . ($sc ? '-' . $sc : '');
    $scope = $sid ? 'sub:' . $sid : 'project:' . $pid;
    $title = (string)($meta['label'] ?? $row['title'] ?? '');
    $orig = pathinfo((string)($row['original_name'] ?? ''), PATHINFO_FILENAME);
    switch ($kind) {
        case 'drawing':  if ($docNo === '') $docNo = $base . '-DRW-' . sprintf('%03d', ar_seq($scope, 'DRW')); if ($rev === '') $rev = 'R0'; break;
        case 'fat': case 'hat': case 'sat': if ($docNo === '') $docNo = $base . '-' . strtoupper($kind) . '-' . sprintf('%03d', ar_seq($scope, strtoupper($kind))); if ($rev === '') $rev = 'R0'; break;
        case 'owner_doc': case 'class_doc':
            if ($docNo === '') { $rec = $row['id'] ?? 0 ? one(($kind === 'owner_doc' ? 'SELECT doc_no, revision FROM owner_approvals' : 'SELECT doc_no, revision FROM class_submittals') . ' WHERE file_id = ? LIMIT 1', [(int)$row['id']]) : null;
                                 $docNo = $rec ? (string)$rec['doc_no'] : $base . '-' . ($kind === 'owner_doc' ? 'OWN' : 'CLS') . '-' . sprintf('%03d', ar_seq($scope, $kind === 'owner_doc' ? 'OWN' : 'CLS')); if ($rev === '' && $rec) $rev = (string)$rec['revision']; }
            if ($rev === '') $rev = 'R0'; break;
        case 'photo':
            if ($docNo === '') { $wk = ($row['note_id'] ?? null) ? scalar('SELECT iso_week FROM sp_notes WHERE id = ?', [(int)$row['note_id']]) : null; $docNo = $base . '-WN-' . ($wk ? str_replace('-', '', (string)$wk) : date('o\WW', strtotime((string)($row['created_at'] ?? 'now')))); }
            if (!$seq) $seq = ar_seq($scope, 'WN-' . substr($docNo, -7)); $rev = ''; break;
        case 'fsr_photo': case 'fsr_sign': case 'fsr_doc':
            $f = ($row['fsr_id'] ?? null) ? one('SELECT report_no, version FROM fsr_reports WHERE id = ?', [(int)$row['fsr_id']]) : null;
            if ($docNo === '') $docNo = $f ? (string)$f['report_no'] : $base . '-FSR';
            if ($kind === 'fsr_doc') { if ($rev === '') $rev = 'R' . (int)($f['version'] ?? 0); $title = ''; $orig = ''; }
            elseif ($kind === 'fsr_sign') { $rev = ''; $title = 'SIG-' . ($meta['sign'] ?? (($row['title'] ?? '') ?: 'signature')); if (!$seq) $seq = ar_seq('fsr:' . (int)($row['fsr_id'] ?? 0), 'SIG'); }
            else { $rev = ''; if (!$seq) $seq = ar_seq('fsr:' . (int)($row['fsr_id'] ?? 0), 'P'); $title = $title ?: ('task' . (int)($row['fsr_task'] ?? 0)); }
            break;
        case 'isr_file': case 'isr_doc':                                                            // inspection reports (R23-003)
            $isrId = (int)($meta['isr_id'] ?? $row['isr_id'] ?? 0);
            $f = $isrId ? one('SELECT report_no, version FROM isr_reports WHERE id = ?', [$isrId]) : null;
            if ($docNo === '') $docNo = $f ? (string)$f['report_no'] : $base . '-ISR';
            if ($kind === 'isr_doc') { if ($rev === '') $rev = 'R' . (int)($f['version'] ?? 0); $title = ''; $orig = ''; }
            else { $rev = ''; if (!$seq) $seq = ar_seq('isr:' . $isrId, 'A'); $title = $title ?: ('sys' . (int)($row['isr_item'] ?? 0)); }
            break;
        case 'offer':
            $o = ($row['id'] ?? 0) ? one('SELECT offer_no, revision, title FROM offers WHERE file_id = ? LIMIT 1', [(int)$row['id']]) : null;
            if ($docNo === '') $docNo = $o ? (string)$o['offer_no'] : $base . '-OFF';
            if ($rev === '' && $o) $rev = (string)$o['revision'];
            if ($o && empty($meta['label'])) $title = (string)$o['title'];
            if ($rev === '') $rev = 'R0'; break;
        case 'ga': if ($docNo === '') $docNo = $pc . '-GA-' . sprintf('%02d', ar_seq('project:' . $pid, 'GA')); $rev = ''; break;
        case 'issued': if ($docNo === '') $docNo = $base . '-DOC'; if ($rev === '') $rev = 'R0'; $title = ''; $orig = ''; break;
        default:   { $code = function_exists('fk_doc_code') ? fk_doc_code($kind) : 'FILE'; if ($docNo === '') $docNo = $base . '-' . $code . '-' . sprintf('%03d', ar_seq($scope, $code)); if (!$seq) $seq = 1; $rev = ''; }
    }
    $slug = ar_slug($title !== '' ? $title : $orig, 60);
    $parts = [$docNo];
    if ($rev !== '') $parts[] = $rev;
    if ($seq) $parts[] = sprintf('%02d', $seq);
    if ($slug !== '' && $slug !== $docNo) $parts[] = $slug;
    $name = implode('_', array_map(fn($x) => ar_slug((string)$x, 60), $parts));
    if (strlen($name) > 110) $name = rtrim(substr($name, 0, 110), '-_.');
    $dir = ar_project_dir($pid);
    if (!in_array($kind, AR_PROJECT_LEVEL, true) && $sid && !($kind === 'issued' && empty($row['subproject_id']))) $dir .= '/' . ar_sub_dir($sid);
    $dir .= '/' . $tab;
    return ['dir' => $dir, 'name' => $name . '.' . $ext, 'doc_no' => $docNo, 'revision' => $rev ?: null, 'seq' => $seq ?: null];
}
function ar_target_ticket(array $row, array $meta = []): array {
    $t = one('SELECT t.ticket_no, t.project_id, t.vessel_id, t.customer_id, v.customer_id AS vcid FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id WHERE t.id = ?', [(int)$row['ticket_id']]);
    $ext = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo((string)($row['original_name'] ?? $row['stored_name'] ?? ''), PATHINFO_EXTENSION))) ?: 'bin';
    $no = $t ? (string)$t['ticket_no'] : 'TK-' . (int)$row['ticket_id'];
    $seq = (int)($meta['seq'] ?? $row['seq'] ?? 0) ?: ar_seq('ticket:' . (int)$row['ticket_id'], 'ATT');
    if ($t && $t['project_id']) $dir = ar_project_dir((int)$t['project_id']);
    else $dir = 'customers/' . ar_customer_dir((int)($t['customer_id'] ?? $t['vcid'] ?? 0)) . '/' . ar_vessel_dir($t ? (int)$t['vessel_id'] : null);
    $dir .= '/tickets/' . ar_code($no, 'TK');
    $slug = ar_slug(pathinfo((string)($row['original_name'] ?? ''), PATHINFO_FILENAME), 60);
    return ['dir' => $dir, 'name' => ar_slug($no, 60) . '_' . sprintf('%02d', $seq) . ($slug ? '_' . $slug : '') . '.' . $ext, 'doc_no' => $no, 'revision' => null, 'seq' => $seq];
}

/* ------------------------------------------------------------- disk */

/** Every path stays inside the storage root and uses safe components only. */
function ar_safe_rel(string $rel): string {
    $rel = str_replace('\\', '/', $rel);
    if ($rel === '' || strlen($rel) > 230 || preg_match('#(^|/)\.\.?(/|$)#', $rel) || !preg_match('#^[A-Za-z0-9._/-]+$#', $rel)) throw new RuntimeException('Unsafe archive path: ' . $rel);
    return ltrim($rel, '/');
}
/** Move $absSrc to <storage>/<relDir>/<name>; a name already taken gets -2, -3 …  Returns the relative path. */
function ar_place(string $absSrc, string $relDir, string $name): string {
    $root = cp_storage(); $relDir = ar_safe_rel($relDir);
    $dir = $root . '/' . $relDir; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir($dir)) throw new RuntimeException('Cannot create ' . $relDir);
    $base = pathinfo($name, PATHINFO_FILENAME); $ext = pathinfo($name, PATHINFO_EXTENSION);
    $try = $name; $n = 1;
    while (file_exists($dir . '/' . $try) && realpath($dir . '/' . $try) !== realpath($absSrc)) { $n++; $try = $base . '-' . $n . ($ext ? '.' . $ext : ''); }
    $rel = ar_safe_rel($relDir . '/' . $try);
    if (realpath($absSrc) === realpath($dir . '/' . $try)) return $rel;
    if (!@rename($absSrc, $dir . '/' . $try)) { if (!@copy($absSrc, $dir . '/' . $try)) throw new RuntimeException('Cannot move to ' . $rel); @unlink($absSrc); }
    return $rel;
}
function ar_hash(string $abs): ?string { return is_file($abs) ? hash_file('sha256', $abs) : null; }

/** Called by cp_store_file / cp_store_bytes / tk_store_uploads right after the row exists: file the new file. */
function ar_file_new(string $tbl, int $id, array $meta = []): void {
    if (!ar_has()) return;
    try {
        $row = one("SELECT * FROM {$tbl} WHERE id = ?", [$id]); if (!$row) return;
        $t = ar_target($tbl, $row, $meta);
        $abs = cp_storage() . '/' . $row['stored_name'];
        $rel = ar_place($abs, $t['dir'], $t['name']);
        if ($tbl === 'sp_files') q('UPDATE sp_files SET stored_name = ?, doc_no = ?, revision = ?, seq = ?, sha256 = ?, archived_at = NOW() WHERE id = ?', [$rel, $t['doc_no'], $t['revision'], $t['seq'], ar_hash(cp_storage() . '/' . $rel), $id]);
        else q('UPDATE ticket_files SET stored_name = ?, seq = ?, sha256 = ?, archived_at = NOW() WHERE id = ?', [$rel, $t['seq'], ar_hash(cp_storage() . '/' . $rel), $id]);
    } catch (Throwable $e) { error_log('archive: ' . $e->getMessage()); }   // the file stays where it was stored; Reorganise picks it up later
}
/** The download name a browser gets: the archive name for Golden A/S-issued documents, the original for uploads (OP-07). */
function ar_download_name(array $f): string {
    $issued = in_array((string)($f['kind'] ?? ''), ['fsr_doc', 'isr_doc', 'issued'], true);
    return $issued && ar_has() && !empty($f['archived_at']) ? basename((string)$f['stored_name']) : (string)$f['original_name'];
}

/* ------------------------------------------------------ reorganise */

/** Compute (dry) or apply the archive layout for every registered file; $projectId limits it to one project (Re-file). */
function ar_reorganise(bool $dry, ?int $projectId = null, int $limit = 5000): array {
    require_cp_admin();
    if (!ar_has()) throw new RuntimeException(t('ar.need_r20'));
    $res = ['run_id' => date('ymdHis') . substr(bin2hex(random_bytes(2)), 0, 3), 'checked' => 0, 'moved' => 0, 'same' => 0, 'missing' => 0, 'errors' => 0, 'rows' => [], 'dry' => $dry];
    $GLOBALS['ar_dry'] = $dry;
    $root = cp_storage();
    $sets = [['sp_files', 'SELECT f.* FROM sp_files f' . ($projectId ? ' WHERE f.project_id = ?' : '') . ' ORDER BY f.id', $projectId ? [$projectId] : []],
             ['ticket_files', 'SELECT f.* FROM ticket_files f JOIN tickets t ON t.id = f.ticket_id' . ($projectId ? ' WHERE t.project_id = ?' : '') . ' ORDER BY f.id', $projectId ? [$projectId] : []]];
    $pending = 0; if (!$dry) db()->beginTransaction();
    try {
        foreach ($sets as [$tbl, $sql, $args]) {
            foreach (all($sql, $args) as $row) {
                if ($res['checked'] >= $limit) break 2;
                $res['checked']++;
                $abs = $root . '/' . $row['stored_name'];
                if (!is_file($abs)) { $res['missing']++; $res['rows'][] = [$tbl, (int)$row['id'], $row['stored_name'], '', 'missing']; continue; }
                try {
                    $t = ar_target($tbl, $row);
                    $want = ar_safe_rel($t['dir'] . '/' . $t['name']);
                    if ($row['stored_name'] === $want || dirname($row['stored_name']) === $t['dir'] && preg_match('/^' . preg_quote(pathinfo($t['name'], PATHINFO_FILENAME), '/') . '(-\d+)?\.' . preg_quote(pathinfo($t['name'], PATHINFO_EXTENSION), '/') . '$/', basename($row['stored_name']))) {
                        $res['same']++;
                        if (!$dry && empty($row['sha256'])) q("UPDATE {$tbl} SET sha256 = ?, archived_at = COALESCE(archived_at, NOW()) WHERE id = ?", [ar_hash($abs), (int)$row['id']]);
                        continue;
                    }
                    if ($dry) { $res['moved']++; if (count($res['rows']) < 2000) $res['rows'][] = [$tbl, (int)$row['id'], $row['stored_name'], $want, 'move']; continue; }
                    $rel = ar_place($abs, $t['dir'], $t['name']);
                    q('INSERT INTO archive_moves (run_id, tbl, file_id, old_path, new_path) VALUES (?,?,?,?,?)', [$res['run_id'], $tbl, (int)$row['id'], $row['stored_name'], $rel]);
                    if ($tbl === 'sp_files') q('UPDATE sp_files SET stored_name = ?, doc_no = COALESCE(doc_no, ?), revision = COALESCE(revision, ?), seq = COALESCE(seq, ?), sha256 = COALESCE(sha256, ?), archived_at = NOW() WHERE id = ?', [$rel, $t['doc_no'], $t['revision'], $t['seq'], ar_hash($root . '/' . $rel), (int)$row['id']]);
                    else q('UPDATE ticket_files SET stored_name = ?, seq = COALESCE(seq, ?), sha256 = COALESCE(sha256, ?), archived_at = NOW() WHERE id = ?', [$rel, $t['seq'], ar_hash($root . '/' . $rel), (int)$row['id']]);
                    $res['moved']++; if (count($res['rows']) < 2000) $res['rows'][] = [$tbl, (int)$row['id'], $row['stored_name'], $rel, 'moved'];
                    if (++$pending >= 200) { db()->commit(); db()->beginTransaction(); $pending = 0; }
                } catch (Throwable $e) { $res['errors']++; $res['rows'][] = [$tbl, (int)$row['id'], $row['stored_name'], '', 'error: ' . $e->getMessage()]; }
            }
        }
        if (!$dry) db()->commit();
    } catch (Throwable $e) { if (!$dry && db()->inTransaction()) db()->rollBack(); throw $e; }
    $GLOBALS['ar_dry'] = false;
    if (!$dry) { ar_prune_empty_dirs(); ar_manifest(); }
    return $res;
}
/** Put the files of one run back where they were. */
function ar_undo(string $runId): array {
    require_cp_admin();
    $root = cp_storage(); $n = 0; $err = 0;
    foreach (all('SELECT * FROM archive_moves WHERE run_id = ? AND undone_at IS NULL ORDER BY id DESC', [$runId]) as $m) {
        $cur = $root . '/' . $m['new_path']; $old = $m['old_path'];
        if (!is_file($cur)) { $err++; continue; }
        $dir = dirname($root . '/' . $old); if (!is_dir($dir)) @mkdir($dir, 0755, true);
        if (file_exists($root . '/' . $old) || !@rename($cur, $root . '/' . $old)) { $err++; continue; }
        q("UPDATE {$m['tbl']} SET stored_name = ? WHERE id = ?", [$old, (int)$m['file_id']]);
        q('UPDATE archive_moves SET undone_at = NOW() WHERE id = ?', [(int)$m['id']]);
        $n++;
    }
    ar_prune_empty_dirs();
    return ['undone' => $n, 'errors' => $err];
}
function ar_runs(): array { return ar_has() ? all('SELECT run_id, COUNT(*) n, SUM(undone_at IS NOT NULL) undone, MIN(moved_at) at FROM archive_moves GROUP BY run_id ORDER BY MIN(moved_at) DESC LIMIT 10') : []; }
function ar_prune_empty_dirs(): void {
    $root = cp_storage();
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $p) if ($p->isDir() && !in_array($p->getFilename(), ['avatars', '_exports'], true)) @rmdir($p->getPathname());
}

/* ----------------------------------------------------------- verify */

function ar_verify(bool $full = false, int $sample = 50): array {
    require_cp_admin();
    $root = cp_storage(); $res = ['checked' => 0, 'missing' => [], 'mismatch' => [], 'orphans' => [], 'unhashed' => 0, 'full' => $full];
    $known = [];
    foreach ([['sp_files', 'SELECT id, stored_name, sha256, kind FROM sp_files'], ['ticket_files', 'SELECT id, stored_name, sha256, "ticket" AS kind FROM ticket_files']] as [$tbl, $sql]) {
        $rows = all($sql);
        if (!$full) { shuffle($rows); $rows = array_slice($rows, 0, $sample); } else foreach ($rows as $r) $known[$r['stored_name']] = true;
        foreach ($rows as $r) {
            $res['checked']++; $abs = $root . '/' . $r['stored_name'];
            if (!is_file($abs)) { $res['missing'][] = [$tbl, (int)$r['id'], $r['stored_name']]; continue; }
            if (empty($r['sha256'])) { $res['unhashed']++; continue; }
            if (hash_file('sha256', $abs) !== $r['sha256']) $res['mismatch'][] = [$tbl, (int)$r['id'], $r['stored_name']];
        }
    }
    if ($full) {
        foreach (all('SELECT stored_name FROM sp_files UNION SELECT stored_name FROM ticket_files') as $r) $known[$r['stored_name']] = true;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $p) {
            if (!$p->isFile()) continue;
            $rel = ltrim(str_replace('\\', '/', substr($p->getPathname(), strlen($root))), '/');
            if (str_starts_with($rel, 'avatars/') || str_starts_with($rel, '_exports/') || str_starts_with($rel, '_kb/') || str_starts_with($rel, '_manifest') || str_starts_with($rel, '.')) continue;
            if (!isset($known[$rel])) $res['orphans'][] = $rel;
            if (count($res['orphans']) > 500) break;
        }
    }
    return $res;
}
/** storage/uploads/_manifest.csv — path;size;sha256;table;id;doc_no;revision;created */
function ar_manifest(): int {
    $root = cp_storage(); $n = 0;
    $fh = fopen($root . '/_manifest.csv.tmp', 'w'); fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh, ['path', 'size', 'sha256', 'table', 'id', 'doc_no', 'revision', 'kind', 'created_at'], ';');
    foreach ([['sp_files', 'SELECT id, stored_name, size_bytes, sha256, doc_no, revision, kind, created_at FROM sp_files ORDER BY stored_name'], ['ticket_files', 'SELECT id, stored_name, size_bytes, sha256, NULL doc_no, NULL revision, "ticket" kind, created_at FROM ticket_files ORDER BY stored_name']] as [$tbl, $sql])
        foreach (all($sql) as $r) { fputcsv($fh, [$r['stored_name'], $r['size_bytes'], $r['sha256'], $tbl, $r['id'], $r['doc_no'], $r['revision'], $r['kind'], $r['created_at']], ';'); $n++; }
    fclose($fh); @rename($root . '/_manifest.csv.tmp', $root . '/_manifest.csv');
    return $n;
}
function ar_stats(): array {
    if (!ar_has()) return ['families' => [], 'customers' => [], 'total' => 0, 'n' => 0, 'archived' => 0, 'manifest_at' => null];
    $fam = all('SELECT kind, COUNT(*) n, COALESCE(SUM(size_bytes),0) bytes FROM sp_files GROUP BY kind UNION ALL SELECT "ticket", COUNT(*), COALESCE(SUM(size_bytes),0) FROM ticket_files ORDER BY bytes DESC');
    $cus = all('SELECT c.name, COUNT(f.id) n, COALESCE(SUM(f.size_bytes),0) bytes FROM sp_files f JOIN projects p ON p.id = f.project_id LEFT JOIN customers c ON c.id = p.customer_id GROUP BY c.id, c.name ORDER BY bytes DESC LIMIT 25');
    $tot = one('SELECT (SELECT COUNT(*) FROM sp_files) + (SELECT COUNT(*) FROM ticket_files) n, (SELECT COALESCE(SUM(size_bytes),0) FROM sp_files) + (SELECT COALESCE(SUM(size_bytes),0) FROM ticket_files) bytes, (SELECT COUNT(*) FROM sp_files WHERE archived_at IS NOT NULL) + (SELECT COUNT(*) FROM ticket_files WHERE archived_at IS NOT NULL) archived');
    $m = cp_storage() . '/_manifest.csv';
    return ['families' => $fam, 'customers' => $cus, 'total' => (int)$tot['bytes'], 'n' => (int)$tot['n'], 'archived' => (int)$tot['archived'], 'manifest_at' => is_file($m) ? date('Y-m-d H:i', filemtime($m)) : null];
}
function ar_fmt_bytes(int $b): string { if ($b >= 1073741824) return number_format($b / 1073741824, 2) . ' GB'; if ($b >= 1048576) return number_format($b / 1048576, 1) . ' MB'; if ($b >= 1024) return number_format($b / 1024) . ' kB'; return $b . ' B'; }

/* ------------------------------------------------------------ issue */

/** Archive a portal-generated document: the next revision of $docNo for this project / sub-project.
 *  $files = [[bytes, extension, mime], …]; returns ['revision' => 'Rn', 'ids' => [...]]. */
function ar_issue(string $docNo, int $pid, ?int $sid, array $files, string $visibility = 'external', ?string $title = null): array {
    $n = (int) scalar('SELECT COUNT(DISTINCT revision) FROM sp_files WHERE kind = ? AND doc_no = ? AND project_id = ?', ['issued', $docNo, $pid]);
    $rev = 'R' . $n; $ids = [];
    foreach ($files as [$bytes, $ext, $mime]) $ids[] = cp_store_bytes($bytes, $docNo . '_' . $rev . '.' . $ext, $mime, $pid, $sid, 'issued', $visibility, null, $title, ['doc_no' => $docNo, 'revision' => $rev]);
    return ['revision' => $rev, 'ids' => $ids];
}
/** Issued revisions of one document number (newest first), PDF and DOCX grouped per revision. */
function ar_issued(string $docNo, int $pid): array {
    if (!ar_has()) return [];
    $out = [];
    foreach (all('SELECT f.*, u.full_name AS issued_by FROM sp_files f LEFT JOIN customer_users u ON u.id = f.uploaded_by WHERE f.kind = ? AND f.doc_no = ? AND f.project_id = ? ORDER BY f.id DESC', ['issued', $docNo, $pid]) as $f) {
        $r = (string)$f['revision']; $out[$r] ??= ['revision' => $r, 'at' => $f['created_at'], 'by' => $f['issued_by'], 'files' => []];
        $out[$r]['files'][strtolower(pathinfo((string)$f['stored_name'], PATHINFO_EXTENSION))] = (int)$f['id'];
    }
    return array_values($out);
}
/** Small card listing issued revisions with download links, and the Issue button for staff. */
function ar_issued_html(string $docNo, int $pid, array $issueParams, string $back): string {
    if (!ar_has()) return '';
    $rows = ar_issued($docNo, $pid);
    $h = '<div class="card card--flat ar-issued"><div class="page-head" style="margin-bottom:6px;"><h3 class="ed-h" style="margin:0;">' . tt('ar.issued') . ' <span class="hint" style="display:inline;margin-left:6px;">' . e($docNo) . '</span></h3>';
    if (is_staff()) $h .= '<form method="post" action="' . e($back) . '" data-lock data-busy="' . tt('ar.issuing') . '">' . csrf_field() . '<input type="hidden" name="action" value="doc_issue">' . implode('', array_map(fn($k, $v) => '<input type="hidden" name="' . e((string)$k) . '" value="' . e((string)$v) . '">', array_keys($issueParams), $issueParams)) . '<button class="btn btn--gold btn--sm" type="submit">⎙ ' . tt('ar.issue') . ' ' . e('R' . count($rows)) . '</button></form>';
    $h .= '</div>';
    if (!$rows) $h .= '<p class="hint" style="margin:0;">' . tt('ar.none_issued') . '</p>';
    else { $h .= '<table class="ar-issued-t"><thead><tr><th>' . tt('sub.rev') . '</th><th>' . tt('th.date') . '</th><th>' . tt('ar.issued_by') . '</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) { $links = []; foreach ($r['files'] as $ext => $fid) $links[] = '<a class="btn btn--ghost btn--sm" href="' . url('file', ['id' => $fid]) . '"' . ($ext === 'pdf' ? ' target="_blank"' : '') . '>⤓ ' . strtoupper($ext) . '</a>';
            $h .= '<tr><td><b>' . e($r['revision']) . '</b></td><td>' . e(fmt_datetime($r['at'])) . '</td><td>' . e($r['by'] ?: '—') . '</td><td class="row-actions">' . implode(' ', $links) . '</td></tr>'; }
        $h .= '</tbody></table>'; }
    return $h . '</div>';
}

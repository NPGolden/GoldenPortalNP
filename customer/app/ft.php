<?php
/**
 * Recycle bin for uploaded files (GAS-CP-R97-190).
 *
 * A file removed by a user is not destroyed: its record is copied to file_trash and deleted from its table, and the
 * bytes stay where they are for FT_DAYS days. An administrator restores it under the same id (so every record that
 * points to it works again) or removes it for good; the daily job purges what is older than FT_DAYS days.
 *
 * Who may remove a file (ft_info): administrators always; otherwise the person who uploaded it and the project
 * manager of its project. Issued, sent and signed files are "protected": administrators only, with a written reason.
 * Modules with a rule of their own (HR documents, ISMS, onboarding) keep that rule and only pass through ft_trash_row().
 *
 * cp_delete_file() stays a hard delete for housekeeping (a regenerated PDF, a report deleted with its files).
 */
const FT_DAYS = 30;
const FT_PROTECTED_KINDS = ['issued', 'offer', 'fsr_doc', 'fsr_sign', 'isr_doc', 'ga'];

function ft_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'file_trash'"); } catch (Throwable $e) { $h = false; } } return $h; }
function ft_me(): int { return (int)(current_user()['id'] ?? 0); }
function ft_admin(): bool { return function_exists('is_cp_admin') && is_cp_admin(); }

/** The tables the generic buttons work on: [absolute path of the bytes, label]. */
function ft_types(): array {
    $up = fn(array $r) => cp_storage() . '/' . $r['stored_name'];
    return [
        'sp_files'         => [$up, fn(array $r) => (string)(($r['title'] ?? '') ?: $r['original_name'])],
        'sb_files'         => [$up, fn(array $r) => (string)(($r['title'] ?? '') ?: $r['original_name'])],
        'ticket_files'     => [$up, fn(array $r) => (string)$r['original_name']],
        'cp_request_files' => [$up, fn(array $r) => (string)$r['original_name']],
        'kb_files'         => [$up, fn(array $r) => (string)$r['original_name']],
        'si_docs'          => [$up, fn(array $r) => (string)(($r['title'] ?? '') ?: $r['original_name'])],
    ];
}
/** Every folder a trashed file may live in; nothing outside is ever unlinked. */
function ft_roots(): array { $o = []; foreach ([cp_storage(), dirname(__DIR__) . '/storage'] as $d) { $r = realpath($d); if ($r) $o[] = rtrim(str_replace('\\', '/', $r), '/') . '/'; } return array_values(array_unique($o)); }
function ft_inside(string $abs): bool { $r = realpath($abs); if (!$r || !is_file($r)) return false; $r = str_replace('\\', '/', $r); foreach (ft_roots() as $root) if (str_starts_with($r, $root)) return true; return false; }

/** What a file is, where it belongs and what the current user may do with it:
 *  ['label', 'context', 'protected' => bool, 'can' => bool, 'abs', 'log' => fn(string $label): void|null]. */
function ft_info(string $tbl, array $r): array {
    $T = ft_types()[$tbl] ?? null; if (!$T) throw new RuntimeException(t('err.notfound'));
    $me = ft_me(); $own = $me > 0 && (int)($r['uploaded_by'] ?? 0) === $me; $ctx = ''; $prot = false; $can = $own; $log = null;
    try {
        if ($tbl === 'sp_files') {
            static $PC = []; $pid = (int)$r['project_id']; $p = $PC[$pid] ??= (one('SELECT * FROM projects WHERE id = ?', [$pid]) ?: []);
            $kind = (string)$r['kind']; $ctx = trim((string)($p['project_code'] ?? ($p['code'] ?? '')) . ' · ' . (function_exists('fk_label') ? fk_label($kind) : $kind), ' ·');
            $can = $own || ($me > 0 && (int)($p['pm_user_id'] ?? 0) === $me);
            $prot = in_array($kind, FT_PROTECTED_KINDS, true);
            if ($kind === 'offer_att' && !empty($r['offer_id'])) { static $OC = []; $oid = (int)$r['offer_id']; $o = $OC[$oid] ??= (one('SELECT offer_no, revision, status, created_by FROM offers WHERE id = ?', [$oid]) ?: []); if ($o) { $ctx = trim($o['offer_no'] . ' ' . $o['revision']) . ' · ' . t('of.att'); $prot = $o['status'] !== 'draft'; if ($me > 0 && (int)$o['created_by'] === $me) $can = true; } }
            if ($kind === 'fsr_photo' && !empty($r['fsr_id'])) { $s = (string) scalar('SELECT status FROM fsr_reports WHERE id = ?', [(int)$r['fsr_id']]); $prot = $s !== '' && $s !== 'draft'; }
            if ($kind === 'isr_file' && !empty($r['isr_id'])) { $s = (string) scalar('SELECT status FROM isr_reports WHERE id = ?', [(int)$r['isr_id']]); $prot = $s !== '' && $s !== 'draft'; }
            if (!is_staff()) $can = false;                                    // customers never remove files
            $sid = (int)($r['subproject_id'] ?? 0); if ($sid && function_exists('sp_log')) $log = fn(string $l) => sp_log($sid, 'file_deleted', $l);
        } elseif ($tbl === 'sb_files') {
            static $BC = []; $b = $BC[(int)$r['budget_id']] ??= (one('SELECT sb_no, revision, created_by FROM sb_budgets WHERE id = ?', [(int)$r['budget_id']]) ?: []); $ctx = trim(($b['sb_no'] ?? '') . ' R' . (int)($b['revision'] ?? 0));
            $can = function_exists('sb_can') && sb_can() && ($own || (int)($b['created_by'] ?? 0) === $me || (function_exists('mgmt_can') && mgmt_can()));
            $bid = (int)$r['budget_id']; if (function_exists('sb_event')) $log = fn(string $l) => sb_event($bid, 'file_removed', $l);
        } elseif ($tbl === 'ticket_files') {
            $ctx = t('ft.c_ticket') . ' #' . (int)$r['ticket_id']; $can = $own && is_staff();
        } elseif ($tbl === 'cp_request_files') {
            $ctx = t('ft.c_request') . ' ' . (function_exists('cr_no') ? cr_no((int)$r['request_id']) : '#' . (int)$r['request_id']);
        } elseif ($tbl === 'kb_files') {
            $ctx = t('ft.c_kb') . ' Q' . (int)$r['question_id'];
        } elseif ($tbl === 'si_docs') {
            $ctx = t('ft.c_si') . ' #' . (int)$r['invoice_id']; $prot = ($r['kind'] ?? '') === 'invoice';
            $can = $own || (function_exists('si_can') && si_can());
        }
    } catch (Throwable $e) { /* a missing parent only costs the context text */ }
    if ($prot) $can = false; if (ft_admin()) $can = true;
    return ['label' => $T[1]($r), 'context' => $ctx, 'protected' => $prot, 'can' => $can && $me > 0, 'abs' => $T[0]($r), 'log' => $log];
}
function ft_row(string $tbl, int $id): ?array { if (!isset(ft_types()[$tbl]) || $id <= 0) return null; return one("SELECT * FROM `$tbl` WHERE id = ?", [$id]) ?: null; }

/** Records that point to $tbl.id through a foreign key: [[table, column, [ids]], …] — they are unlinked by the delete and linked again by a restore. */
function ft_refs(string $tbl, int $id): array {
    $out = [];
    try {
        foreach (all("SELECT TABLE_NAME t, COLUMN_NAME c FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? AND REFERENCED_COLUMN_NAME = 'id'", [$tbl]) as $k) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $k['t'] . $k['c'])) continue;
            try { $ids = array_map('intval', array_column(all("SELECT id FROM `{$k['t']}` WHERE `{$k['c']}` = ? LIMIT 200", [$id]), 'id')); } catch (Throwable $e) { $ids = []; }
            if ($ids) $out[] = [$k['t'], $k['c'], $ids];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Move one record to the recycle bin (no rights check — the caller has done it). Before the migration: the old hard delete. */
function ft_trash_row(string $tbl, array $row, string $abs, string $label, string $context = '', bool $protected = false, string $reason = ''): void {
    if (!preg_match('/^[a-z0-9_]+$/', $tbl)) throw new RuntimeException(t('err.notfound')); $id = (int)$row['id'];
    if (!ft_has()) { if (is_file($abs)) @unlink($abs); q("DELETE FROM `$tbl` WHERE id = ?", [$id]); return; }
    $u = current_user(); $refs = ft_refs($tbl, $id); $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        q('INSERT INTO file_trash (tbl, row_id, row_json, refs_json, path, label, context, size_bytes, protected, reason, deleted_by, deleted_name, purge_after) VALUES (?,?,?,?,?,?,?,?,?,?,?,?, CURDATE() + INTERVAL ' . FT_DAYS . ' DAY)',
          [$tbl, $id, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $refs ? json_encode($refs) : null, $abs, mb_substr($label, 0, 255), mb_substr($context, 0, 255) ?: null,
           is_file($abs) ? filesize($abs) : null, $protected ? 1 : 0, mb_substr(trim($reason), 0, 300) ?: null, (int)($u['id'] ?? 0) ?: null, mb_substr((string)($u['full_name'] ?? ''), 0, 160) ?: null]);
        q("DELETE FROM `$tbl` WHERE id = ?", [$id]);
        if ($own) $pdo->commit();
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && $e->getCode() === '23000') throw new RuntimeException(t('ft.e_in_use', ['name' => $label]));   // another record needs it (for example a GA plan with pins)
        throw $e;
    }
}
/** Remove a file of one of the generic tables for the current user. Returns the label. */
function ft_delete(string $tbl, int $id, string $reason = ''): string {
    $r = ft_row($tbl, $id); if (!$r) throw new RuntimeException(t('err.notfound')); $i = ft_info($tbl, $r);
    if (!$i['can']) throw new RuntimeException(t($i['protected'] ? 'ft.e_protected' : 'err.noaccess'));
    if ($i['protected'] && mb_strlen(trim($reason)) < 5) throw new RuntimeException(t('ft.e_reason'));
    ft_trash_row($tbl, $r, $i['abs'], $i['label'], $i['context'], $i['protected'], $reason);
    if ($i['log']) try { ($i['log'])($i['label']); } catch (Throwable $e) {}
    return $i['label'];
}
/** An sp_files record removed by a module that has checked the right itself (offer attachment, note photo, report photo). */
function cp_trash_file(?int $fileId, string $reason = ''): void {
    if (!$fileId) return; $r = one('SELECT * FROM sp_files WHERE id = ?', [$fileId]); if (!$r) return;
    if (!ft_has()) { cp_delete_file($fileId); return; }
    $i = ft_info('sp_files', $r); ft_trash_row('sp_files', $r, $i['abs'], $i['label'], $i['context'], $i['protected'], $reason);
}

/* ------------------------------------------------------------------ the bin (administrators) */
function ft_list(string $state = 'open', int $limit = 500): array {
    if (!ft_has()) return []; $w = ['open' => 'purged_at IS NULL AND restored_at IS NULL', 'restored' => 'restored_at IS NOT NULL', 'purged' => 'purged_at IS NOT NULL'][$state] ?? '1=1';
    return all("SELECT id, tbl, row_id, path, label, context, size_bytes, protected, reason, deleted_name, deleted_at, purge_after, restored_at, purged_at FROM file_trash WHERE $w ORDER BY id DESC LIMIT " . max(1, min(2000, $limit)));
}
function ft_open_count(): int { try { return ft_has() ? (int) scalar('SELECT COUNT(*) FROM file_trash WHERE purged_at IS NULL AND restored_at IS NULL') : 0; } catch (Throwable $e) { return 0; } }
function ft_restore(int $tid): string {
    require_cp_admin(); $x = one('SELECT * FROM file_trash WHERE id = ? AND purged_at IS NULL AND restored_at IS NULL', [$tid]); if (!$x) throw new RuntimeException(t('err.notfound'));
    $tbl = (string)$x['tbl']; if (!preg_match('/^[a-z0-9_]+$/', $tbl)) throw new RuntimeException(t('err.notfound'));
    if (!is_file((string)$x['path'])) throw new RuntimeException(t('ft.e_bytes'));
    $row = json_decode((string)$x['row_json'], true); if (!is_array($row)) throw new RuntimeException(t('err.notfound'));
    if (scalar("SELECT COUNT(*) FROM `$tbl` WHERE id = ?", [(int)$x['row_id']])) throw new RuntimeException(t('ft.e_exists'));
    $cols = array_column(all("SHOW COLUMNS FROM `$tbl`"), 'Field'); $row = array_intersect_key($row, array_flip($cols));   // a column dropped since is left out
    try { q("INSERT INTO `$tbl` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row)); }
    catch (PDOException $e) { throw new RuntimeException(t('ft.e_parent')); }   // the project, offer or ticket it belonged to no longer exists
    foreach ((array) json_decode((string)($x['refs_json'] ?? ''), true) as $ref) {
        [$t2, $c2, $ids] = $ref + [null, null, []]; if (!is_string($t2) || !is_string($c2) || !preg_match('/^[A-Za-z0-9_]+$/', $t2 . $c2)) continue; $ids = array_filter(array_map('intval', (array)$ids)); if (!$ids) continue;
        try { q("UPDATE `$t2` SET `$c2` = ? WHERE `$c2` IS NULL AND id IN (" . implode(',', $ids) . ')', [(int)$x['row_id']]); } catch (Throwable $e) {}
    }
    q('UPDATE file_trash SET restored_at = NOW(), restored_by = ? WHERE id = ?', [ft_me() ?: null, $tid]);
    return (string)$x['label'];
}
/** Destroy the bytes of one entry. The record of who removed what stays, without the copy of the row. */
function ft_purge_row(array $x): bool {
    $p = (string)$x['path']; if ($p !== '' && is_file($p)) { if (!ft_inside($p)) return false; @unlink($p); }
    q("UPDATE file_trash SET purged_at = NOW(), row_json = '{}', refs_json = NULL WHERE id = ?", [(int)$x['id']]); return true;
}
function ft_purge(int $tid): string { require_cp_admin(); $x = one('SELECT * FROM file_trash WHERE id = ? AND purged_at IS NULL AND restored_at IS NULL', [$tid]); if (!$x) throw new RuntimeException(t('err.notfound')); if (!ft_purge_row($x)) throw new RuntimeException(t('ft.e_path')); return (string)$x['label']; }
/** Daily: what has been in the bin longer than FT_DAYS days. */
function ft_daily(): string {
    if (!ft_has()) return 'bin —'; $n = 0;
    foreach (all('SELECT id, path FROM file_trash WHERE purged_at IS NULL AND restored_at IS NULL AND purge_after < CURDATE() ORDER BY id LIMIT 500') as $x) if (ft_purge_row($x)) $n++;
    return 'bin ' . $n;
}
function ft_size(?int $b): string { if ($b === null) return '—'; return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : number_format(max(1, $b / 1024), 0) . ' KB'; }

/* ------------------------------------------------------------------ buttons */
/** Where to return after the action: the page the button is on (same site only). */
function ft_back(): string { $q = (string)($_SERVER['QUERY_STRING'] ?? ''); return 'index.php' . ($q !== '' ? '?' . $q : ''); }
function ft_back_ok(string $b): string { return strlen($b) <= 800 && preg_match('~^index\.php(\?[^\s#"<>\\\\\x00-\x1f]*)?(#[A-Za-z0-9_\-]*)?$~', $b) ? $b : 'index.php'; }
/** The 🗑 of one file — nothing when the user may not remove it. A protected file leads the administrator to the page that asks for the reason. */
function ft_btn(string $tbl, array $row, string $hash = ''): string {
    if (!ft_has() || !isset(ft_types()[$tbl])) return ''; $i = ft_info($tbl, $row); if (!$i['can']) return '';
    if ($i['protected']) return '<a class="btn btn--ghost btn--sm btn--danger-ghost" title="' . e(t('ft.protected_tip')) . '" href="' . e(url('file_remove', ['t' => $tbl, 'id' => (int)$row['id'], 'back' => ft_back() . $hash])) . '">🔒🗑</a>';
    return '<form method="post" class="inline" style="display:inline" data-confirm="' . e(t('ft.confirm_one', ['name' => $i['label'], 'd' => FT_DAYS])) . '" data-confirm-title="' . e(t('dlg.title')) . '" data-confirm-ok="' . e(t('btn.delete')) . '" data-confirm-danger>' . csrf_field()
         . '<input type="hidden" name="action" value="ft_delete"><input type="hidden" name="t" value="' . e($tbl) . '"><input type="hidden" name="id" value="' . (int)$row['id'] . '"><input type="hidden" name="back" value="' . e(ft_back() . $hash) . '">'
         . '<button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit" title="' . e(t('btn.delete')) . '">🗑</button></form>';
}
/** "Delete all" for a list: the files of $rows the user may remove without a reason. Nothing when there are fewer than two. */
function ft_all_btn(string $tbl, array $rows, string $hash = ''): string {
    if (!ft_has() || !isset(ft_types()[$tbl])) return ''; $ids = []; $skip = 0;
    foreach ($rows as $r) { $i = ft_info($tbl, $r); if ($i['can'] && !$i['protected']) $ids[] = (int)$r['id']; else $skip++; }
    if (count($ids) < 2) return '';
    $h = '<form method="post" class="inline" style="display:inline" data-confirm="' . e(t('ft.confirm_all', ['n' => count($ids), 'd' => FT_DAYS]) . ($skip ? ' ' . t('ft.confirm_skip', ['n' => $skip]) : '')) . '" data-confirm-title="' . e(t('dlg.title')) . '" data-confirm-ok="' . e(t('ft.all_ok', ['n' => count($ids)])) . '" data-confirm-danger>' . csrf_field()
       . '<input type="hidden" name="action" value="ft_delete_all"><input type="hidden" name="t" value="' . e($tbl) . '"><input type="hidden" name="back" value="' . e(ft_back() . $hash) . '">';
    foreach ($ids as $id) $h .= '<input type="hidden" name="ids[]" value="' . $id . '">';
    return $h . '<button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 ' . e(t('ft.all', ['n' => count($ids)])) . '</button></form>';
}

/* ------------------------------------------------------------------ actions */
function ft_handle_action(string $action): bool {
    if (!in_array($action, ['ft_delete', 'ft_delete_all', 'ft_restore', 'ft_purge', 'ft_empty'], true)) return false;
    require_login(); $back = ft_back_ok((string)($_POST['back'] ?? ''));
    try {
        if (!ft_has()) throw new RuntimeException(t('ft.need'));
        if ($action === 'ft_delete') { $l = ft_delete((string)($_POST['t'] ?? ''), (int)($_POST['id'] ?? 0), (string)($_POST['reason'] ?? '')); flash('good', t('ft.done_one', ['name' => $l, 'd' => FT_DAYS])); }
        elseif ($action === 'ft_delete_all') {
            $tbl = (string)($_POST['t'] ?? ''); $n = 0; $no = 0;
            foreach (array_slice(array_unique(array_map('intval', (array)($_POST['ids'] ?? []))), 0, 500) as $id) { try { ft_delete($tbl, $id); $n++; } catch (RuntimeException $e) { $no++; } }
            flash($n ? 'good' : 'bad', t('ft.done_all', ['n' => $n, 'd' => FT_DAYS]) . ($no ? ' ' . t('ft.done_skip', ['n' => $no]) : ''));
        }
        elseif ($action === 'ft_restore') { flash('good', t('ft.restored', ['name' => ft_restore((int)($_POST['id'] ?? 0))])); $back = 'index.php?p=admin_trash'; }
        elseif ($action === 'ft_purge') { flash('good', t('ft.purged', ['name' => ft_purge((int)($_POST['id'] ?? 0))])); $back = 'index.php?p=admin_trash'; }
        elseif ($action === 'ft_empty') { require_cp_admin(); $n = 0; foreach (all('SELECT id, path FROM file_trash WHERE purged_at IS NULL AND restored_at IS NULL ORDER BY id LIMIT 2000') as $x) if (ft_purge_row($x)) $n++; flash('good', t('ft.emptied', ['n' => $n])); $back = 'index.php?p=admin_trash'; }
    } catch (RuntimeException $e) { flash('bad', $e->getMessage()); }
    header('Location: ' . $back); exit;
}

<?php
/**
 * Owner approvals — documents (drawings, studies) the owner decides on
 * online: approve, approve with comments, reject, and a comment thread
 * between the owner and Golden A/S on each document.         GAS-CP-R10-001
 */

function oa_ip(): string {
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return mb_substr($ip, 0, 45);
}

/** True once migrate_cp_r10_approvals_invoicing.sql has run. */
function oa_has_r10(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW COLUMNS FROM owner_approvals LIKE 'decided_time'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/** Statuses the owner can still decide on. */
function oa_open_statuses(): array { return ['submitted', 'resubmitted']; }

/** One document the current person may see (customers: their own projects only). */
function oa_load(int $id): ?array {
    [$scope, $args] = scope_sql('p');
    array_unshift($args, $id);
    $r = one("SELECT o.*, p.name AS project_name, p.project_code, p.customer_id, c.name AS customer_name, v.name AS vessel,
                     s.code AS sub_code, s.name AS sub_name, f.original_name, f.mime_type AS file_mime, f.size_bytes AS file_size
              FROM owner_approvals o JOIN projects p ON p.id = o.project_id
              LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN vessels v ON v.id = p.vessel_id
              LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN sp_files f ON f.id = o.file_id
              WHERE o.id = ? AND {$scope}", $args);
    if (!$r) return null;
    if (!is_staff() && $r['status'] === 'draft') return null;                 // Golden drafts stay internal
    $r['thread'] = oa_has_r10() ? all('SELECT * FROM oa_comments WHERE approval_id = ? ORDER BY id', [$id]) : [];
    return $r;
}

/** All documents in scope, optionally one project / one status group. */
function oa_list(?int $pid = null, string $status = '', string $q = ''): array {
    [$scope, $args] = scope_sql('p');
    $w = [$scope];
    if (!is_staff()) $w[] = "o.status <> 'draft'";
    if ($pid) { $w[] = 'o.project_id = ?'; $args[] = $pid; }
    if ($status === 'open')          $w[] = "o.status IN ('submitted','resubmitted')";
    elseif ($status === 'approved')  $w[] = "o.status IN ('approved','approved_comments')";
    elseif ($status !== '' && in_array($status, ['draft','submitted','approved','approved_comments','rejected','resubmitted'], true)) { $w[] = 'o.status = ?'; $args[] = $status; }
    if ($q !== '') { $w[] = '(o.doc_no LIKE ? OR o.title LIKE ? OR p.project_code LIKE ?)'; $like = '%' . $q . '%'; array_push($args, $like, $like, $like); }
    $nc = oa_has_r10() ? '(SELECT COUNT(*) FROM oa_comments x WHERE x.approval_id = o.id)' : '0';
    return all("SELECT o.*, p.name AS project_name, p.project_code, c.name AS customer_name, v.name AS vessel, s.code AS sub_code, s.name AS sub_name,
                       f.original_name, {$nc} AS n_comments
                FROM owner_approvals o JOIN projects p ON p.id = o.project_id
                LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN vessels v ON v.id = p.vessel_id
                LEFT JOIN subprojects s ON s.id = o.subproject_id LEFT JOIN sp_files f ON f.id = o.file_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY FIELD(o.status, 'submitted', 'resubmitted') DESC, o.due_at IS NULL, o.due_at, o.id DESC", $args);
}

/** Documents awaiting the owner's decision, in scope (dashboard / nav badge). */
function oa_pending(): array {
    return oa_list(null, 'open');
}

function oa_comment(int $id, string $kind, ?string $body, ?int $parentId = null): void {
    if (!oa_has_r10()) return;
    $u = current_user();
    if ($parentId && !scalar('SELECT COUNT(*) FROM oa_comments WHERE id = ? AND approval_id = ?', [$parentId, $id])) $parentId = null;
    if (thread_has_replies()) {
        q('INSERT INTO oa_comments (approval_id, parent_id, user_id, author, side, kind, body, ip) VALUES (?,?,?,?,?,?,?,?)',
          [$id, $parentId, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, is_staff() ? 'golden' : 'customer', $kind, $body !== null && $body !== '' ? $body : null, oa_ip()]);
    } else {
        q('INSERT INTO oa_comments (approval_id, user_id, author, side, kind, body, ip) VALUES (?,?,?,?,?,?,?)',
          [$id, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, is_staff() ? 'golden' : 'customer', $kind, $body !== null && $body !== '' ? $body : null, oa_ip()]);
    }
}
/** Delete one's own comment (soft: it keeps its place when replies hang under it). */
function oa_comment_delete(int $approvalId, int $cid): void {
    $u = current_user();
    $c = one('SELECT * FROM oa_comments WHERE id = ? AND approval_id = ?', [$cid, $approvalId]);
    if (!$c || $c['kind'] !== 'comment' || ((int)$c['user_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('cm.not_yours'));
    if (thread_has_replies() && scalar('SELECT COUNT(*) FROM oa_comments WHERE parent_id = ? AND deleted_at IS NULL', [$cid])) q('UPDATE oa_comments SET deleted_at = NOW(), body = NULL WHERE id = ?', [$cid]);
    else q('DELETE FROM oa_comments WHERE id = ?', [$cid]);
}

/* ------------------------------------------- comment threads (R11) */

/** True once migrate_cp_r11 added parent_id / deleted_at to the threads. */
function thread_has_replies(): bool {
    static $has = null;
    if ($has === null) {
        try { $has = (bool) db()->query("SHOW COLUMNS FROM oa_comments LIKE 'parent_id'")->fetch(); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}
/** Order a flat list of comments as a tree: parents first, replies right after, with a depth. */
function thread_tree(array $rows): array {
    $byParent = [];
    foreach ($rows as $r) $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
    $out = [];
    $walk = function (int $pid, int $depth) use (&$walk, &$byParent, &$out) {
        foreach ($byParent[$pid] ?? [] as $r) { $r['depth'] = $depth; $out[] = $r; $walk((int)$r['id'], min($depth + 1, 3)); }
    };
    $walk(0, 0);
    // orphans whose parent is gone
    $seen = array_column($out, 'id');
    foreach ($rows as $r) if (!in_array($r['id'], $seen, true)) { $r['depth'] = 0; $out[] = $r; }
    return $out;
}
/** The reply / delete buttons under a comment. $own = the viewer wrote it. */
function thread_tools(int $cid, string $author, bool $own, bool $deletable, string $action, array $hidden): string {
    $h = '<div class="msg-tools">';
    if (thread_has_replies()) $h .= '<button type="button" class="linkbtn js-reply" data-reply="' . $cid . '" data-author="' . e($author) . '">' . tt('cm.reply') . '</button>';
    if ($own && $deletable) {
        $h .= '<form method="post" class="inline" data-confirm="' . tt('cm.delete_confirm') . '" data-confirm-title="' . tt('dlg.title') . '" data-confirm-ok="' . tt('btn.delete') . '" data-confirm-cancel="' . tt('btn.cancel') . '" data-confirm-danger>'
            . csrf_field() . '<input type="hidden" name="action" value="' . e($action) . '"><input type="hidden" name="cid" value="' . $cid . '">';
        foreach ($hidden as $k => $v) $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string)$v) . '">';
        $h .= '<button type="submit" class="linkbtn linkbtn--danger">' . tt('btn.delete') . '</button></form>';
    }
    return $h . '</div>';
}

/** The owner's decision. $onBehalf = an administrator recording a decision received offline. */
function oa_decide(int $id, string $decision, string $note, bool $onBehalf = false): void {
    $u = current_user();
    if (!in_array($decision, ['approved', 'approved_comments', 'rejected'], true)) throw new RuntimeException(t('err.notfound'));
    $r = oa_load($id);
    if (!$r) throw new RuntimeException(t('err.notfound'));
    if (!in_array($r['status'], oa_open_statuses(), true)) throw new RuntimeException(t('oa.locked'));
    $note = mb_substr(trim($note), 0, 4000);
    if ($decision !== 'approved' && $note === '') throw new RuntimeException(t('oa.note_req'));
    if (oa_has_r10()) {
        q('UPDATE owner_approvals SET status = ?, decided_at = CURDATE(), decided_by = ?, decided_name = ?, decided_ip = ?, decided_time = NOW(),
                                      decision_note = ?, on_behalf = ?, comments = COALESCE(?, comments) WHERE id = ?',
          [$decision, (int)$u['id'], $u['full_name'], oa_ip(), $note ?: null, (int)$onBehalf, $note ?: null, $id]);
        oa_comment($id, $decision, $note ?: null);
    } else {
        q('UPDATE owner_approvals SET status = ?, decided_at = CURDATE(), comments = COALESCE(?, comments) WHERE id = ?', [$decision, $note ?: null, $id]);
    }
}

/** Golden A/S sends a new revision after a rejection (or comments). */
function oa_resubmit(int $id, string $revision, ?int $fileId, string $note): void {
    $r = oa_load($id);
    if (!$r) throw new RuntimeException(t('err.notfound'));
    if (oa_has_r10()) {
        q('UPDATE owner_approvals SET status = "resubmitted", revision = ?, file_id = COALESCE(?, file_id), submitted_at = CURDATE(), decided_at = NULL,
                                      decided_by = NULL, decided_name = NULL, decided_ip = NULL, decided_time = NULL, decision_note = NULL, on_behalf = 0 WHERE id = ?',
          [mb_substr(trim($revision), 0, 16) ?: $r['revision'], $fileId, $id]);
        oa_comment($id, 'resubmitted', mb_substr(trim($note), 0, 4000) ?: null);
    } else {
        q('UPDATE owner_approvals SET status = "resubmitted", revision = ?, file_id = COALESCE(?, file_id), submitted_at = CURDATE(), decided_at = NULL WHERE id = ?',
          [mb_substr(trim($revision), 0, 16) ?: $r['revision'], $fileId, $id]);
    }
}

/** The next revision label: R0 -> R1, A -> B, 3 -> 4. */
function oa_next_rev(string $rev): string {
    if (preg_match('/^(.*?)(\d+)$/', $rev, $m)) return $m[1] . ((int)$m[2] + 1);
    if (preg_match('/^[A-Y]$/', $rev)) return chr(ord($rev) + 1);
    return $rev;
}

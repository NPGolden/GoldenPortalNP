<?php
/** Knowledge base — questions and answers from the users of the portal.   GAS-CP-R21-001
 *  Anyone signed in may ask and answer; the author of a question (or Golden A/S staff) marks the answer that
 *  solved it; everybody can mark an answer helpful; administrators delete questions and answers.
 *  Visibility 'golden' keeps a question inside Golden A/S (staff only). */
declare(strict_types=1);

function kb_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'kb_questions'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function kb_categories(): array {
    return ['portal' => t('kb.c_portal'), 'systems' => t('kb.c_systems'), 'documents' => t('kb.c_documents'), 'service' => t('kb.c_service'), 'other' => t('kb.c_other')];
}
function kb_cat_pill(string $c): string {
    $tone = ['portal' => 'navy', 'systems' => 'gold', 'documents' => 'blue', 'service' => 'green', 'other' => 'mute'][$c] ?? 'mute';
    return pill($tone, kb_categories()[$c] ?? $c);
}
function kb_vis_sql(): string { return is_staff() ? '1 = 1' : "q.visibility = 'all'"; }

/** @return array{rows: array, total: int} */
function kb_list(array $f, int $per = 20, int $page = 1): array {
    if (!kb_has()) return ['rows' => [], 'total' => 0];
    $w = [kb_vis_sql()]; $b = [];
    if (!empty($f['q'])) { $w[] = '(q.title LIKE ? OR q.body LIKE ? OR EXISTS (SELECT 1 FROM kb_answers a WHERE a.question_id = q.id AND a.body LIKE ?))'; $l = '%' . $f['q'] . '%'; array_push($b, $l, $l, $l); }
    if (!empty($f['cat']) && isset(kb_categories()[$f['cat']])) { $w[] = 'q.category = ?'; $b[] = $f['cat']; }
    if (($f['st'] ?? '') === 'open') $w[] = 'q.accepted_id IS NULL';
    if (($f['st'] ?? '') === 'solved') $w[] = 'q.accepted_id IS NOT NULL';
    if (($f['st'] ?? '') === 'unanswered') $w[] = 'q.n_answers = 0';
    $sql = 'FROM kb_questions q LEFT JOIN customer_users u ON u.id = q.author_id WHERE ' . implode(' AND ', $w);
    $total = (int) scalar('SELECT COUNT(*) ' . $sql, $b);
    $order = ($f['sort'] ?? '') === 'views' ? 'q.views DESC, q.updated_at DESC' : 'q.updated_at DESC';
    $rows = all("SELECT q.*, u.full_name AS author, u.role AS author_role {$sql} ORDER BY {$order} LIMIT {$per} OFFSET " . (($page - 1) * $per), $b);
    return ['rows' => $rows, 'total' => $total];
}
function kb_load(int $id): ?array {
    if (!kb_has() || !$id) return null;
    $q = one('SELECT q.*, u.full_name AS author, u.role AS author_role FROM kb_questions q LEFT JOIN customer_users u ON u.id = q.author_id WHERE q.id = ? AND ' . kb_vis_sql(), [$id]);
    if (!$q) return null;
    $u = current_user();
    $q['answers'] = all('SELECT a.*, u.full_name AS author, u.role AS author_role, (SELECT COUNT(*) FROM kb_votes v WHERE v.answer_id = a.id AND v.user_id = ?) AS my_vote
                         FROM kb_answers a LEFT JOIN customer_users u ON u.id = a.author_id WHERE a.question_id = ? ORDER BY (a.id = ?) DESC, a.helpful DESC, a.id', [(int)$u['id'], $id, (int)($q['accepted_id'] ?? 0)]);
    return $q;
}
function kb_ask(): int {
    $u = require_login();
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if (mb_strlen($title) < 8) throw new RuntimeException(t('kb.need_title'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000) ?: null;
    $cat = (string)($_POST['category'] ?? 'portal'); if (!isset(kb_categories()[$cat])) $cat = 'other';
    $vis = (is_staff() && ($_POST['visibility'] ?? '') === 'golden') ? 'golden' : 'all';
    q('INSERT INTO kb_questions (author_id, title, body, category, visibility) VALUES (?,?,?,?,?)', [(int)$u['id'], $title, $body, $cat, $vis]);
    $qid = (int) db()->lastInsertId();
    kb_store_uploads($qid, null);
    return $qid;
}
function kb_answer(int $qid): int {
    $u = require_login();
    $q = kb_load($qid); if (!$q) throw new RuntimeException(t('err.notfound'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000); if (mb_strlen($body) < 3) throw new RuntimeException(t('kb.need_body'));
    q('INSERT INTO kb_answers (question_id, author_id, body) VALUES (?,?,?)', [$qid, (int)$u['id'], $body]);
    $id = (int) db()->lastInsertId();
    kb_store_uploads($qid, $id);
    q('UPDATE kb_questions SET n_answers = (SELECT COUNT(*) FROM kb_answers WHERE question_id = ?), updated_at = NOW() WHERE id = ?', [$qid, $qid]);
    return $id;
}
function kb_accept(int $qid, int $aid): void {
    $u = require_login();
    $q = kb_load($qid); if (!$q) throw new RuntimeException(t('err.notfound'));
    if ((int)$q['author_id'] !== (int)$u['id'] && !is_staff()) throw new RuntimeException(t('err.noaccess'));
    $ok = $aid ? scalar('SELECT COUNT(*) FROM kb_answers WHERE id = ? AND question_id = ?', [$aid, $qid]) : 1;
    if (!$ok) throw new RuntimeException(t('err.notfound'));
    q('UPDATE kb_questions SET accepted_id = ? WHERE id = ?', [$aid ?: null, $qid]);
}
function kb_vote(int $aid): void {
    $u = require_login();
    $a = one('SELECT a.*, q.visibility FROM kb_answers a JOIN kb_questions q ON q.id = a.question_id WHERE a.id = ?', [$aid]); if (!$a || ($a['visibility'] === 'golden' && !is_staff())) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM kb_votes WHERE answer_id = ? AND user_id = ?', [$aid, (int)$u['id']])) q('DELETE FROM kb_votes WHERE answer_id = ? AND user_id = ?', [$aid, (int)$u['id']]);
    else q('INSERT INTO kb_votes (answer_id, user_id) VALUES (?,?)', [$aid, (int)$u['id']]);
    q('UPDATE kb_answers SET helpful = (SELECT COUNT(*) FROM kb_votes WHERE answer_id = ?) WHERE id = ?', [$aid, $aid]);
}
function kb_delete_question(int $qid): void {
    require_cp_admin();
    kb_delete_files('question_id = ?', [$qid]); @rmdir(cp_storage() . '/_kb/Q' . $qid);
    q('DELETE v FROM kb_votes v JOIN kb_answers a ON a.id = v.answer_id WHERE a.question_id = ?', [$qid]);
    q('DELETE FROM kb_answers WHERE question_id = ?', [$qid]);
    q('DELETE FROM kb_questions WHERE id = ?', [$qid]);
}
function kb_delete_answer(int $aid): int {
    require_cp_admin();
    $a = one('SELECT question_id FROM kb_answers WHERE id = ?', [$aid]); if (!$a) throw new RuntimeException(t('err.notfound'));
    kb_delete_files('answer_id = ?', [$aid]);
    q('DELETE FROM kb_votes WHERE answer_id = ?', [$aid]); q('DELETE FROM kb_answers WHERE id = ?', [$aid]);
    q('UPDATE kb_questions SET accepted_id = IF(accepted_id = ?, NULL, accepted_id), n_answers = (SELECT COUNT(*) FROM kb_answers WHERE question_id = ?) WHERE id = ?', [$aid, (int)$a['question_id'], (int)$a['question_id']]);
    return (int)$a['question_id'];
}
/** Author label: Golden A/S people are flagged so an owner knows who is answering. */
function kb_who(?string $name, ?string $role): string {
    $n = e($name ?: '—');
    return in_array($role, ['staff', 'admin'], true) ? $n . ' <span class="pill pill--gold" style="font-size:10px;padding:0 6px;">Golden A/S</span>' : $n;
}
/** Short card for the Support page: newest solved questions + ask link. */
function kb_support_card(): string {
    if (!kb_has()) return '';
    $rows = kb_list(['sort' => 'views'], 6)['rows'];
    $h = '<div class="card"><div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">' . tt('kb.title') . '</h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="' . url('kb') . '">' . tt('kb.browse') . ' ›</a><a class="btn btn--gold btn--sm" href="' . url('kb', ['ask' => 1]) . '#ask">+ ' . tt('kb.ask') . '</a></div></div><p class="hint">' . tt('kb.support_hint') . '</p>';
    if (!$rows) $h .= '<div class="empty">' . tt('kb.none') . '</div>';
    else { $h .= '<ul class="kb-mini">'; foreach ($rows as $r) $h .= '<li>' . ($r['accepted_id'] ? '<span class="kb-ok" title="' . tt('kb.solved') . '">✓</span>' : '<span class="kb-open">?</span>') . '<a href="' . url('kb_q', ['id' => $r['id']]) . '">' . e($r['title']) . '</a><span class="hint">' . (int)$r['n_answers'] . ' · ' . kb_cat_pill($r['category']) . '</span></li>'; $h .= '</ul>'; }
    return $h . '</div>';
}

/* ------------------------------------------------------- attachments */

function kb_files_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'kb_files'"); } catch (Throwable $e) { $h = false; } } return $h; }
const KB_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'pptx', 'dwg', 'dxf', 'txt', 'csv', 'zip', 'mp4', 'mov'];
/** Store the files of the 'files' field for a question (answer null) or an answer. Max 5 per post, 20 MB each. */
function kb_store_uploads(int $qid, ?int $aid): int {
    if (!kb_files_has()) return 0;
    $u = current_user(); $n = 0; $f = $_FILES['files'] ?? null; if (!$f || !is_array($f['name'])) return 0;
    $dir = cp_storage() . '/_kb/Q' . $qid; if (!is_dir($dir)) @mkdir($dir, 0755, true);
    foreach (array_slice($f['name'], 0, 5, true) as $i => $name) {
        if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 20 * 1024 * 1024) continue;
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)); if (!in_array($ext, KB_EXT, true)) continue;
        $mime = (string)(function_exists('mime_content_type') ? @mime_content_type($f['tmp_name'][$i]) : '') ?: ($f['type'][$i] ?? 'application/octet-stream');
        $seq = (int) scalar('SELECT COUNT(*) FROM kb_files WHERE question_id = ?', [$qid]) + 1;
        $slug = function_exists('ar_slug') ? ar_slug(pathinfo((string)$name, PATHINFO_FILENAME), 50) : preg_replace('/[^A-Za-z0-9-]+/', '-', pathinfo((string)$name, PATHINFO_FILENAME));
        $stored = '_kb/Q' . $qid . '/KB-' . $qid . ($aid ? '-A' . $aid : '') . '_' . sprintf('%02d', $seq) . ($slug ? '_' . $slug : '') . '.' . $ext;
        $k = 1; while (file_exists(cp_storage() . '/' . $stored)) { $k++; $stored = preg_replace('/(-\d+)?\.' . $ext . '$/', '-' . $k . '.' . $ext, $stored); }
        if (!move_uploaded_file($f['tmp_name'][$i], cp_storage() . '/' . $stored)) continue;
        q('INSERT INTO kb_files (question_id, answer_id, stored_name, original_name, mime_type, size_bytes, sha256, uploaded_by) VALUES (?,?,?,?,?,?,?,?)',
          [$qid, $aid, $stored, mb_substr((string)$name, 0, 255), mb_substr($mime, 0, 120), (int)$f['size'][$i], hash_file('sha256', cp_storage() . '/' . $stored), (int)($u['id'] ?? 0) ?: null]);
        $n++;
    }
    return $n;
}
function kb_files(int $qid): array {
    if (!kb_files_has()) return [];
    $out = ['q' => [], 'a' => []];
    foreach (all('SELECT * FROM kb_files WHERE question_id = ? ORDER BY id', [$qid]) as $f) { if ($f['answer_id']) $out['a'][(int)$f['answer_id']][] = $f; else $out['q'][] = $f; }
    return $out;
}
function kb_files_html(array $files): string {
    if (!$files) return '';
    $h = '<div class="kb-files">';
    foreach ($files as $f) {
        $img = str_starts_with((string)$f['mime_type'], 'image/'); $url = url('kbfile', ['id' => $f['id']]);
        $h .= $img ? '<a class="kb-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . e($f['original_name']) . '" loading="lazy"></a>'
                   : '<a class="kb-file" href="' . $url . '">📎 ' . e($f['original_name']) . ' <span class="hint">' . e(ar_fmt_bytes((int)$f['size_bytes'])) . '</span></a>';
    }
    return $h . '</div>';
}
function kb_stream_file(int $id): void {
    require_login();
    $f = kb_files_has() ? one('SELECT f.*, q.visibility FROM kb_files f JOIN kb_questions q ON q.id = f.question_id WHERE f.id = ?', [$id]) : null;
    if (!$f || ($f['visibility'] === 'golden' && !is_staff())) { http_response_code(404); exit('Not found'); }
    $path = cp_storage() . '/' . $f['stored_name']; if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    $inline = str_starts_with((string)$f['mime_type'], 'image/') || $f['mime_type'] === 'application/pdf';
    header('Content-Type: ' . $f['mime_type']); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=600');
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($f['original_name']) . '"');
    readfile($path); exit;
}
function kb_delete_files(string $where, array $args): void {
    if (!kb_files_has()) return;
    foreach (all("SELECT id, stored_name FROM kb_files WHERE {$where}", $args) as $f) { $p = cp_storage() . '/' . $f['stored_name']; if (is_file($p)) @unlink($p); }
    q("DELETE FROM kb_files WHERE {$where}", $args);
}

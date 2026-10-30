<?php
/** Portal change requests — Support › Requests (Golden A/S staff).                         GAS-CP-R59-001
 *  A staff member proposes a bug fix, an improvement or a feature; colleagues can add "me too" votes and comments;
 *  the Developer (Settings › cr_dev_user_id, Nikos Polykandriotis) accepts or rejects with a cause, moves it to
 *  In development and finally Released with the revision reference (GAS-CP-Rnn-nnn) and version that delivered it.
 *  Every step is a line in the request's thread; the list keeps the whole history and a scoreboard of the people
 *  whose proposals were accepted and released. */
declare(strict_types=1);

const CR_KINDS = ['bug', 'improvement', 'feature'];
const CR_AREAS = ['projects', 'tickets', 'offers', 'reports', 'timesheets', 'hr', 'documents', 'equipment', 'planning', 'mobile', 'settings', 'other'];
const CR_STATUSES = ['submitted', 'accepted', 'in_progress', 'released', 'rejected', 'withdrawn'];
const CR_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'txt', 'mp4'];

function cr_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'cp_requests'"); } catch (Throwable $e) { $h = false; } } return $h; }
function cr_can(): bool { return cr_has() && is_staff(); }
/** The Developer: decides, plans, releases. Administrators may act in his place. */
function cr_dev_id(): int { return (int) (function_exists('cp_setting') ? cp_setting('cr_dev_user_id', '0') : 0); }
function cr_is_dev(): bool { $u = current_user(); return is_staff() && $u && ((int)$u['id'] === cr_dev_id() || is_cp_admin()); }
function cr_no(int $id): string { return 'CR-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT); }
function cr_kind_pill(string $k): string { return pill(['bug' => 'bad', 'improvement' => 'navy', 'feature' => 'gold'][$k] ?? 'mute', t('cr.k_' . $k)); }
function cr_status_pill(string $s): string { return pill(['submitted' => 'blue', 'accepted' => 'green', 'in_progress' => 'navy', 'released' => 'green', 'rejected' => 'bad', 'withdrawn' => 'mute'][$s] ?? 'mute', t('cr.s_' . $s)); }
function cr_prio_pill(string $p): string { return $p === 'normal' ? '' : pill($p === 'high' ? 'bad' : 'mute', t('cr.p_' . $p)); }

/** @return array{rows: array, total: int} */
function cr_list(array $f, int $per = 30, int $page = 1): array {
    if (!cr_has()) return ['rows' => [], 'total' => 0];
    $w = ['1 = 1']; $b = [];
    if (!empty($f['q'])) { $w[] = '(r.title LIKE ? OR r.body LIKE ? OR r.release_ref LIKE ?)'; $l = '%' . $f['q'] . '%'; array_push($b, $l, $l, $l); }
    if (!empty($f['kind']) && in_array($f['kind'], CR_KINDS, true)) { $w[] = 'r.kind = ?'; $b[] = $f['kind']; }
    if (!empty($f['area']) && in_array($f['area'], CR_AREAS, true)) { $w[] = 'r.area = ?'; $b[] = $f['area']; }
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "r.status IN ('submitted','accepted','in_progress')"; elseif (in_array($f['status'], CR_STATUSES, true)) { $w[] = 'r.status = ?'; $b[] = $f['status']; } }
    if (!empty($f['user'])) { $w[] = 'r.user_id = ?'; $b[] = (int)$f['user']; }
    $sql = 'FROM cp_requests r LEFT JOIN customer_users u ON u.id = r.user_id WHERE ' . implode(' AND ', $w);
    $total = (int) scalar('SELECT COUNT(*) ' . $sql, $b);
    $rows = all("SELECT r.*, u.full_name AS author {$sql} ORDER BY FIELD(r.status,'submitted','accepted','in_progress','released','rejected','withdrawn'), r.updated_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $b);
    return ['rows' => $rows, 'total' => $total];
}
function cr_load(int $id): ?array {
    if (!cr_has() || !$id) return null;
    $r = one('SELECT r.*, u.full_name AS author, d.full_name AS decider FROM cp_requests r LEFT JOIN customer_users u ON u.id = r.user_id LEFT JOIN customer_users d ON d.id = r.decided_by WHERE r.id = ?', [$id]);
    if (!$r) return null;
    $r['comments'] = all('SELECT c.* FROM cp_request_comments c WHERE c.request_id = ? ORDER BY c.id', [$id]);
    $r['files'] = all('SELECT * FROM cp_request_files WHERE request_id = ? ORDER BY id', [$id]);
    $u = current_user(); $r['my_vote'] = (bool) scalar('SELECT COUNT(*) FROM cp_request_votes WHERE request_id = ? AND user_id = ?', [$id, (int)$u['id']]);
    return $r;
}
function cr_counts(): array {
    if (!cr_has()) return [];
    $c = ['open' => 0, 'submitted' => 0, 'released' => 0, 'total' => 0];
    foreach (all('SELECT status, COUNT(*) n FROM cp_requests GROUP BY status') as $x) { $c[$x['status']] = (int)$x['n']; $c['total'] += (int)$x['n']; if (in_array($x['status'], ['submitted', 'accepted', 'in_progress'], true)) $c['open'] += (int)$x['n']; }
    return $c;
}
/** Scoreboard: people by released, accepted (incl. in development) and proposed counts. */
function cr_scoreboard(int $limit = 10): array {
    if (!cr_has()) return [];
    return all("SELECT u.id, u.full_name, COUNT(*) AS proposed,
                       SUM(r.status IN ('accepted','in_progress','released')) AS accepted, SUM(r.status = 'released') AS released, SUM(r.status = 'rejected') AS rejected
                FROM cp_requests r JOIN customer_users u ON u.id = r.user_id GROUP BY u.id, u.full_name
                ORDER BY released DESC, accepted DESC, proposed DESC, u.full_name LIMIT {$limit}");
}
function cr_line(int $id, string $kind, string $body): void {
    $u = current_user();
    q('INSERT INTO cp_request_comments (request_id, user_id, author, kind, body) VALUES (?,?,?,?,?)', [$id, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $kind, mb_substr($body, 0, 8000)]);
    q('UPDATE cp_requests SET n_comments = (SELECT COUNT(*) FROM cp_request_comments WHERE request_id = ? AND kind = "comment"), updated_at = NOW() WHERE id = ?', [$id, $id]);
}
function cr_notify(array $to, string $key, string $title, ?string $body, int $id): void {
    if (!function_exists('nf_push')) return;
    $u = current_user(); $to = array_values(array_unique(array_filter(array_map('intval', $to), fn($x) => $x && $x !== (int)($u['id'] ?? 0))));
    if ($to) nf_push($to, 'crq:' . $key, 'crq:' . $id . ':' . $key . ':' . time(), $title, $body, url('request', ['id' => $id]), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''));
}

function cr_create(): int {
    $u = require_staff(); if (!cr_has()) throw new RuntimeException(t('err.notfound'));
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if (mb_strlen($title) < 6) throw new RuntimeException(t('cr.need_title'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000); if (mb_strlen($body) < 10) throw new RuntimeException(t('cr.need_body'));
    $kind = in_array($_POST['kind'] ?? '', CR_KINDS, true) ? $_POST['kind'] : 'improvement';
    $area = in_array($_POST['area'] ?? '', CR_AREAS, true) ? $_POST['area'] : 'other';
    $prio = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high'], true) ? $_POST['priority'] : 'normal';
    q('INSERT INTO cp_requests (kind, area, title, body, priority, user_id) VALUES (?,?,?,?,?,?)', [$kind, $area, $title, $body, $prio, (int)$u['id']]);
    $id = (int) db()->lastInsertId();
    cr_store_uploads($id, null);
    cr_notify([cr_dev_id()], 'new', t('cr.nf_new', ['no' => cr_no($id), 't' => $title, 'u' => (string)$u['full_name']]), mb_substr($body, 0, 300), $id);
    return $id;
}
function cr_comment(int $id): void {
    $u = require_staff(); $r = cr_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000); if (mb_strlen($body) < 2) throw new RuntimeException(t('cr.need_body'));
    cr_line($id, 'comment', $body);
    $cid = (int) db()->lastInsertId(); cr_store_uploads($id, $cid);
    cr_notify([(int)$r['user_id'], cr_dev_id()], 'comment', t('cr.nf_comment', ['no' => cr_no($id), 'u' => (string)$u['full_name']]), mb_substr($body, 0, 300), $id);
}
function cr_vote(int $id): void {
    $u = require_staff(); if (!cr_load($id)) throw new RuntimeException(t('err.notfound'));
    if (scalar('SELECT COUNT(*) FROM cp_request_votes WHERE request_id = ? AND user_id = ?', [$id, (int)$u['id']])) q('DELETE FROM cp_request_votes WHERE request_id = ? AND user_id = ?', [$id, (int)$u['id']]);
    else q('INSERT INTO cp_request_votes (request_id, user_id) VALUES (?,?)', [$id, (int)$u['id']]);
    q('UPDATE cp_requests SET votes = (SELECT COUNT(*) FROM cp_request_votes WHERE request_id = ?) WHERE id = ?', [$id, $id]);
}
/** The Developer's decision / progress: accepted · rejected (cause required) · in_progress · released (reference + version) · back to submitted. The author may withdraw. */
function cr_decide(int $id): void {
    $u = require_staff(); $r = cr_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
    $st = (string)($_POST['status'] ?? ''); $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 4000);
    if ($st === 'withdrawn') { if ((int)$r['user_id'] !== (int)$u['id'] && !cr_is_dev()) throw new RuntimeException(t('err.noaccess')); if (!in_array($r['status'], ['submitted', 'accepted'], true)) throw new RuntimeException(t('cr.bad_transition')); q("UPDATE cp_requests SET status = 'withdrawn' WHERE id = ?", [$id]); cr_line($id, 'status', t('cr.s_withdrawn') . ($note !== '' ? ' — ' . $note : '')); return; }
    if (!cr_is_dev()) throw new RuntimeException(t('err.noaccess'));
    if (!in_array($st, ['accepted', 'rejected', 'in_progress', 'released', 'submitted'], true)) throw new RuntimeException(t('cr.bad_transition'));
    if (in_array($st, ['accepted', 'rejected'], true) && $note === '') throw new RuntimeException(t('cr.need_cause'));
    $sets = ['status = ?']; $vals = [$st];
    if (in_array($st, ['accepted', 'rejected'], true)) { array_push($sets, 'decided_by = ?', 'decided_at = NOW()', 'decision_note = ?'); array_push($vals, (int)$u['id'], $note); }
    if ($st === 'released') {
        $ref = strtoupper(mb_substr(trim((string)($_POST['release_ref'] ?? '')), 0, 40)); $ver = mb_substr(trim((string)($_POST['release_version'] ?? '')), 0, 20) ?: (defined('CP_VERSION') ? CP_VERSION : '');
        if ($ref === '') throw new RuntimeException(t('cr.need_ref'));
        array_push($sets, 'release_ref = ?', 'release_version = ?', 'released_at = CURDATE()'); array_push($vals, $ref, $ver);
    }
    if ($r['status'] === 'released' && $st !== 'released') array_push($sets, 'release_ref = NULL', 'release_version = NULL', 'released_at = NULL');
    $vals[] = $id; q('UPDATE cp_requests SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    $line = t('cr.s_' . $st) . ($st === 'released' ? ' — ' . $ref . ' · v' . $ver : '') . ($note !== '' ? ' — ' . $note : '');
    cr_line($id, 'status', $line);
    cr_notify([(int)$r['user_id']], $st, t('cr.nf_decision', ['no' => cr_no($id), 's' => t('cr.s_' . $st)]), $note ?: ($st === 'released' ? $ref . ' · v' . $ver : null), $id);
}
function cr_delete(int $id): void { require_cp_admin(); $r = cr_load($id); if (!$r) throw new RuntimeException(t('err.notfound')); foreach ($r['files'] as $f) @unlink(cp_storage() . '/' . $f['stored_name']); q('DELETE FROM cp_requests WHERE id = ?', [$id]); }

/* ---------------------------------------------------------------- attachments (screenshots etc.), max 5 per post, 15 MB each */
function cr_store_uploads(int $id, ?int $cid): int {
    $u = current_user(); $n = 0; $f = $_FILES['files'] ?? null; if (!$f || !is_array($f['name'])) return 0;
    $dir = cp_storage() . '/_cr/' . cr_no($id); if (!is_dir($dir)) @mkdir($dir, 0755, true);
    foreach (array_slice($f['name'], 0, 5, true) as $i => $name) {
        if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 15 * 1024 * 1024) continue;
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION)); if (!in_array($ext, CR_EXT, true)) continue;
        $mime = (string)(function_exists('mime_content_type') ? @mime_content_type($f['tmp_name'][$i]) : '') ?: 'application/octet-stream';
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) && !str_starts_with($mime, 'image/')) continue;
        $seq = (int) scalar('SELECT COUNT(*) FROM cp_request_files WHERE request_id = ?', [$id]) + 1;
        $stored = '_cr/' . cr_no($id) . '/' . cr_no($id) . '_' . sprintf('%02d', $seq) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'][$i], cp_storage() . '/' . $stored)) continue;
        q('INSERT INTO cp_request_files (request_id, comment_id, stored_name, original_name, mime_type, size_bytes, uploaded_by) VALUES (?,?,?,?,?,?,?)', [$id, $cid, $stored, mb_substr((string)$name, 0, 255), mb_substr($mime, 0, 120), (int)$f['size'][$i], (int)($u['id'] ?? 0) ?: null]);
        $n++;
    }
    return $n;
}
function cr_files_html(array $files): string {
    if (!$files) return '';
    $h = '<div class="kb-files">';
    foreach ($files as $f) { $img = str_starts_with((string)$f['mime_type'], 'image/'); $url = url('crfile', ['id' => $f['id']]);
        $h .= $img ? '<a class="kb-thumb" href="' . $url . '" target="_blank" rel="noopener"><img src="' . $url . '" alt="' . e($f['original_name']) . '" loading="lazy"></a>' : '<a class="kb-file" href="' . $url . '">📎 ' . e($f['original_name']) . '</a>'; }
    return $h . '</div>';
}
function cr_stream_file(int $id): void {
    require_staff(); $f = cr_has() ? one('SELECT * FROM cp_request_files WHERE id = ?', [$id]) : null;
    if (!$f) { http_response_code(404); exit('Not found'); }
    $path = cp_storage() . '/' . $f['stored_name']; if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    $inline = str_starts_with((string)$f['mime_type'], 'image/') || $f['mime_type'] === 'application/pdf';
    header('Content-Type: ' . $f['mime_type']); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=600');
    if ($f['mime_type'] === 'application/pdf') header("Content-Security-Policy: sandbox; default-src 'none'");
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($f['original_name']) . '"');
    readfile($path); exit;
}
/** Support page card for staff: counts, latest items, + New. */
function cr_support_card(): string {
    if (!cr_can()) return '';
    $c = cr_counts(); $rows = cr_list(['status' => 'open'], 6)['rows'];
    $h = '<div class="card" id="cr-card"><div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">💡 ' . tt('cr.title') . ' <span class="hint">' . (int)($c['open'] ?? 0) . ' ' . tt('cr.open') . ' · ' . (int)($c['released'] ?? 0) . ' ' . tt('cr.s_released') . '</span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="' . url('requests') . '">' . tt('cr.all') . ' ›</a><a class="btn btn--gold btn--sm" href="' . url('requests', ['new' => 1]) . '#cr-new">+ ' . tt('cr.new') . '</a></div></div><p class="hint">' . tt('cr.support_hint') . '</p>';
    if (!$rows) $h .= '<div class="empty">' . tt('cr.none') . '</div>';
    else { $h .= '<ul class="kb-mini">'; foreach ($rows as $r) $h .= '<li>' . cr_kind_pill($r['kind']) . ' <a href="' . url('request', ['id' => $r['id']]) . '"><b>' . e(cr_no((int)$r['id'])) . '</b> ' . e($r['title']) . '</a> <span class="hint">' . cr_status_pill($r['status']) . ' · ' . e((string)$r['author']) . '</span></li>'; $h .= '</ul>'; }
    return $h . '</div>';
}

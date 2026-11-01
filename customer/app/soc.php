<?php
/** Community — wishes on celebrations, kudos, milestones (anniversaries, welcome), noticeboard (posts / polls / photos),
 *  badges, winner of the month, onboarding checklist with a buddy, daily celebration nudge and Monday digest.   GAS-CP-R63-001 */
declare(strict_types=1);

const SOC_EMOJIS = ['🎉', '🎂', '🥳', '🎊', '🍾', '❤️', '👏', '🌟'];
const SOC_REACTIONS = ['👍', '❤️', '👏', '🎉', '😂', '🚢'];
const SOC_ONBOARD_ITEMS = ['photo', 'manual', 'profile', 'timesheet', 'planning', 'leave', 'training', 'buddy'];
const SOC_BADGES = [   // code => [icon, metric key, threshold]
    'first_ts'   => ['⏱', 'ts', 1],   'ts_100'   => ['⏱', 'ts', 100],   'ts_500'   => ['⏱', 'ts', 500],
    'tk_10'      => ['🎫', 'tk', 10],  'tk_50'    => ['🎫', 'tk', 50],    'tk_200'   => ['🎫', 'tk', 200],
    'rep_10'     => ['📋', 'rep', 10], 'rep_50'   => ['📋', 'rep', 50],
    'kb_1'       => ['💬', 'kb', 1],   'kb_10'    => ['💬', 'kb', 10],
    'tr_1'       => ['🎓', 'tr', 1],   'tr_5'     => ['🎓', 'tr', 5],
    'helper_10'  => ['🤝', 'kudos', 10], 'helper_50' => ['🤝', 'kudos', 50],
    'giver_20'   => ['🙏', 'given', 20],
    'cr_1'       => ['💡', 'cr', 1],   'cr_5'     => ['💡', 'cr', 5],
    'yr_1'       => ['🏅', 'years', 1], 'yr_5'    => ['🏅', 'years', 5], 'yr_10' => ['🏅', 'years', 10], 'yr_20' => ['🏅', 'years', 20],
];

function soc_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'soc_kudos'"); } catch (Throwable $e) { $h = false; } } return $h; }
function soc_me(): int { return (int)(current_user()['id'] ?? 0); }
function soc_staff(): array { static $c = null; if ($c === null) { $c = []; foreach (all("SELECT id, full_name, email, created_at FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name") as $u) $c[(int)$u['id']] = $u; } return $c; }
function soc_can_post(): bool { return is_cp_admin() || (function_exists('hr_can') && hr_can()) || (function_exists('hr_is_manager') && hr_is_manager()) || (function_exists('pm_is_pm') && pm_is_pm()); }
function soc_name(int $uid): string { return (string)(soc_staff()[$uid]['full_name'] ?? scalar('SELECT full_name FROM customer_users WHERE id = ?', [$uid]) ?? '—'); }
function soc_notify(array $to, string $kind, string $ref, string $title, ?string $body, string $url): void { $to = array_values(array_unique(array_filter(array_map('intval', $to), fn($i) => $i > 0 && $i !== soc_me()))); if ($to && function_exists('nf_push')) nf_push($to, $kind, $ref, $title, $body, $url, soc_me() ?: null, (string)(current_user()['full_name'] ?? '')); }

/* ------------------------------------------------------------------ milestones: anniversaries and welcomes (from the employee card) */
/** Upcoming milestones: ['date','kind' => anniversary|welcome,'person','years'] within $days. */
function soc_milestones(int $days = 14): array {
    if (!function_exists('hr_people')) return []; $today = date('Y-m-d'); $end = date('Y-m-d', strtotime("+$days days")); $out = [];
    foreach (hr_people(false) as $p) {
        if (empty($p['is_active']) || empty($p['hire_date']) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$p['hire_date'], $m)) continue;
        $hy = (int)$m[1];
        if ((string)$p['hire_date'] >= $today && (string)$p['hire_date'] <= $end) { $out[] = ['date' => (string)$p['hire_date'], 'kind' => 'welcome', 'person' => $p, 'years' => 0]; continue; }
        if ((string)$p['hire_date'] >= date('Y-m-d', strtotime('-14 days')) && (string)$p['hire_date'] < $today) { $out[] = ['date' => (string)$p['hire_date'], 'kind' => 'welcome', 'person' => $p, 'years' => 0]; continue; }   // joined in the last two weeks: still "new"
        foreach (array_unique([(int) substr($today, 0, 4), (int) substr($end, 0, 4)]) as $y) { $yrs = $y - $hy; if ($yrs < 1) continue; $d = sprintf('%04d-%s-%s', $y, $m[2], $m[3]); if ($m[2] === '02' && $m[3] === '29' && !checkdate(2, 29, $y)) $d = "$y-02-28"; if ($d >= $today && $d <= $end && in_array($yrs, [1, 2, 3, 5, 10, 15, 20, 25, 30], true)) $out[] = ['date' => $d, 'kind' => 'anniversary', 'person' => $p, 'years' => $yrs]; }
    }
    usort($out, fn($a, $b) => [$a['date'], $a['person']['name_en']] <=> [$b['date'], $b['person']['name_en']]); return $out;
}
/** Everything to wish for in the next $days: celebrations (cel_upcoming) + milestones, each with the wishes already sent. */
function soc_events(int $days = 14): array {
    $ev = []; foreach (function_exists('cel_upcoming') ? cel_upcoming($days, false) : [] as $c) $ev[] = ['date' => $c['date'], 'kind' => $c['kind'], 'person' => $c['person'], 'label' => $c['name'] ?? null, 'years' => null];
    foreach (soc_milestones($days) as $m) $ev[] = ['date' => $m['date'], 'kind' => $m['kind'], 'person' => $m['person'], 'label' => null, 'years' => $m['years']];
    usort($ev, fn($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
    if (soc_has()) { $me = soc_me(); foreach ($ev as &$e) { $uid = (int)($e['person']['user_id'] ?? 0); $e['uid'] = $uid; $e['wishes'] = $uid ? all('SELECT w.*, cu.full_name FROM soc_wishes w JOIN customer_users cu ON cu.id = w.from_user_id WHERE w.to_user_id = ? AND w.kind = ? AND w.on_date = ? ORDER BY w.created_at', [$uid, $e['kind'], $e['date']]) : []; $e['mine'] = (bool) array_filter($e['wishes'], fn($w) => (int)$w['from_user_id'] === $me); $e['can'] = $uid && $uid !== $me && $e['date'] <= date('Y-m-d', strtotime('+1 day')); } unset($e); }
    return $ev;
}
function soc_wish_send(): void {
    if (!soc_has()) throw new RuntimeException(t('err.notfound')); $to = (int)($_POST['to'] ?? 0); $kind = in_array($_POST['kind'] ?? '', ['birthday', 'nameday', 'anniversary', 'welcome'], true) ? $_POST['kind'] : 'birthday';
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['on_date'] ?? '')) ? $_POST['on_date'] : date('Y-m-d'); if (!isset(soc_staff()[$to]) || $to === soc_me()) throw new RuntimeException(t('err.noaccess'));
    $emoji = in_array($_POST['emoji'] ?? '', SOC_EMOJIS, true) ? $_POST['emoji'] : SOC_EMOJIS[0]; $msg = mb_substr(trim((string)($_POST['message'] ?? '')), 0, 300) ?: null;
    q('INSERT INTO soc_wishes (to_user_id, from_user_id, kind, on_date, emoji, message) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji), message = VALUES(message)', [$to, soc_me(), $kind, $d, $emoji, $msg]);
    soc_notify([$to], 'soc:wish', 'soc:wish:' . soc_me() . ':' . $to . ':' . $d, $emoji . ' ' . t('soc.nf_wish', ['u' => (string)(current_user()['full_name'] ?? ''), 'k' => t('soc.k_' . $kind)]), $msg, url('dashboard') . '#wishes');
}
/** Wishes received by me for celebrations of the last 3 days (dashboard wall). */
function soc_wishes_for_me(): array { return soc_has() ? all('SELECT w.*, cu.full_name FROM soc_wishes w JOIN customer_users cu ON cu.id = w.from_user_id WHERE w.to_user_id = ? AND w.on_date >= ? ORDER BY w.created_at DESC', [soc_me(), date('Y-m-d', strtotime('-3 days'))]) : []; }

/* ------------------------------------------------------------------ kudos */
function soc_kudos_give(): void {
    if (!soc_has()) throw new RuntimeException(t('err.notfound')); $to = (int)($_POST['to'] ?? 0); $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 300);
    if (!isset(soc_staff()[$to]) || $to === soc_me()) throw new RuntimeException(t('err.noaccess')); if (mb_strlen($reason) < 3) throw new RuntimeException(t('soc.kudos_need_reason'));
    if ((int) scalar('SELECT COUNT(*) FROM soc_kudos WHERE from_user_id = ? AND to_user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)', [soc_me(), $to]) >= 3) throw new RuntimeException(t('soc.kudos_limit'));
    q('INSERT INTO soc_kudos (to_user_id, from_user_id, reason) VALUES (?,?,?)', [$to, soc_me(), $reason]);
    soc_notify([$to], 'soc:kudos', 'soc:kudos:' . (int) db()->lastInsertId(), '👏 ' . t('soc.nf_kudos', ['u' => (string)(current_user()['full_name'] ?? '')]), $reason, url('community') . '#kudos');
    soc_badges_refresh($to); soc_badges_refresh(soc_me());
}
function soc_kudos_recent(int $n = 12): array { return soc_has() ? all('SELECT k.*, a.full_name AS to_name, b.full_name AS from_name FROM soc_kudos k JOIN customer_users a ON a.id = k.to_user_id JOIN customer_users b ON b.id = k.from_user_id ORDER BY k.created_at DESC LIMIT ' . (int)$n) : []; }
function soc_kudos_top(string $from, string $to, int $n = 5): array { return soc_has() ? all('SELECT k.to_user_id uid, cu.full_name, COUNT(*) n FROM soc_kudos k JOIN customer_users cu ON cu.id = k.to_user_id WHERE k.created_at BETWEEN ? AND ? GROUP BY k.to_user_id, cu.full_name ORDER BY n DESC, cu.full_name LIMIT ' . (int)$n, [$from, $to . ' 23:59:59']) : []; }

/* ------------------------------------------------------------------ noticeboard: posts, polls, photos */
function soc_post_create(): int {
    if (!soc_has()) throw new RuntimeException(t('err.notfound')); $kind = in_array($_POST['kind'] ?? '', ['post', 'poll', 'photo'], true) ? $_POST['kind'] : 'post';
    if (($kind !== 'photo') && !soc_can_post()) throw new RuntimeException(t('err.noaccess'));   // everybody may share a photo; posts and polls come from HR, managers, PMs and administrators
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if ($title === '') throw new RuntimeException(t('soc.need_title'));
    $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 8000) ?: null; $pin = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['pinned_until'] ?? '')) && soc_can_post() ? $_POST['pinned_until'] : null; $closes = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['closes_at'] ?? '')) ? $_POST['closes_at'] : null;
    $stored = null; $mime = null;
    $f = $_FILES['photo'] ?? null; $hasFile = $f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;   // R70-002: a photo may accompany any post; it is required only for the photo kind
    if ($hasFile && ($f['error'] ?? 1) === UPLOAD_ERR_INI_SIZE) throw new RuntimeException(t('soc.photo_too_big'));
    if ($kind === 'photo' || $hasFile) { if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 12 * 1024 * 1024) throw new RuntimeException(t('soc.need_photo'));
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)); if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) throw new RuntimeException(t('soc.need_photo'));
        $mime = (string)(function_exists('mime_content_type') ? @mime_content_type($f['tmp_name']) : ''); if (!str_starts_with($mime, 'image/')) throw new RuntimeException(t('soc.need_photo'));
        $dir = cp_storage() . '/_soc'; if (!is_dir($dir)) @mkdir($dir, 0755, true); $stored = '_soc/' . date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext; if (!move_uploaded_file($f['tmp_name'], cp_storage() . '/' . $stored)) throw new RuntimeException(t('soc.need_photo')); }
    q('INSERT INTO soc_posts (user_id, kind, title, body, stored_name, mime_type, pinned_until, closes_at) VALUES (?,?,?,?,?,?,?,?)', [soc_me(), $kind, $title, $body, $stored, $mime, $pin, $closes]); $id = (int) db()->lastInsertId();
    if ($kind === 'poll') { $opts = array_values(array_filter(array_map(fn($o) => mb_substr(trim((string)$o), 0, 120), (array)($_POST['options'] ?? [])), fn($o) => $o !== '')); if (count($opts) < 2) throw new RuntimeException(t('soc.need_options')); foreach (array_slice($opts, 0, 8) as $i => $o) q('INSERT INTO soc_post_options (post_id, idx, label) VALUES (?,?,?)', [$id, $i, $o]); }
    if ($kind !== 'photo' || !empty($_POST['notify'])) soc_notify(array_keys(soc_staff()), 'soc:post', 'soc:post:' . $id, ($kind === 'poll' ? '🗳 ' : ($kind === 'photo' ? '📷 ' : '📌 ')) . $title, $body, url('community') . '#post' . $id);
    return $id;
}
function soc_post_delete(int $id): void { $p = one('SELECT * FROM soc_posts WHERE id = ?', [$id]); if (!$p) return; if ((int)$p['user_id'] !== soc_me() && !is_cp_admin() && !(function_exists('hr_can') && hr_can())) throw new RuntimeException(t('err.noaccess')); if ($p['stored_name']) @unlink(cp_storage() . '/' . $p['stored_name']); foreach (['soc_post_options', 'soc_post_votes', 'soc_post_reactions', 'soc_post_comments'] as $tb) q("DELETE FROM $tb WHERE post_id = ?", [$id]); q('DELETE FROM soc_posts WHERE id = ?', [$id]); }
function soc_post_react(int $id, string $emoji): void { if (!in_array($emoji, SOC_REACTIONS, true)) $emoji = SOC_REACTIONS[0]; $cur = scalar('SELECT emoji FROM soc_post_reactions WHERE post_id = ? AND user_id = ?', [$id, soc_me()]); if ($cur === $emoji) q('DELETE FROM soc_post_reactions WHERE post_id = ? AND user_id = ?', [$id, soc_me()]); else q('INSERT INTO soc_post_reactions (post_id, user_id, emoji) VALUES (?,?,?) ON DUPLICATE KEY UPDATE emoji = VALUES(emoji)', [$id, soc_me(), $emoji]); }
function soc_post_comment(int $id): void { $b = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 1000); if ($b === '') return; $p = one('SELECT user_id, title FROM soc_posts WHERE id = ?', [$id]); if (!$p) return; q('INSERT INTO soc_post_comments (post_id, user_id, body) VALUES (?,?,?)', [$id, soc_me(), $b]); $cid = (int) db()->lastInsertId(); $mentioned = function_exists('mt_parse') ? mt_parse($b) : []; soc_notify(array_diff([(int)$p['user_id']], $mentioned), 'soc:comment', 'soc:comment:' . $cid, '💬 ' . t('soc.nf_comment', ['u' => (string)(current_user()['full_name'] ?? ''), 't' => (string)$p['title']]), $b, url('community') . '#post' . $id);
    if ($mentioned && function_exists('mt_notify')) mt_notify($b, t('soc.title') . ' · ' . (string)$p['title'], url('community') . '#post' . $id, 'soc:' . $cid); }   // R70-003: @mentions in post comments
function soc_poll_vote(int $id, int $idx): void { $p = one('SELECT * FROM soc_posts WHERE id = ? AND kind = "poll"', [$id]); if (!$p) return; if ($p['closes_at'] && $p['closes_at'] < date('Y-m-d')) throw new RuntimeException(t('soc.poll_closed')); if (!scalar('SELECT COUNT(*) FROM soc_post_options WHERE post_id = ? AND idx = ?', [$id, $idx])) return; q('INSERT INTO soc_post_votes (post_id, user_id, idx) VALUES (?,?,?) ON DUPLICATE KEY UPDATE idx = VALUES(idx), created_at = NOW()', [$id, soc_me(), $idx]); }
/** Feed: pinned first, then newest; each with options/votes, reactions, comments. */
function soc_feed(int $limit = 30, ?string $kind = null): array {
    if (!soc_has()) return []; $w = $kind ? ' WHERE p.kind = ' . db()->quote($kind) : '';
    $rows = all('SELECT p.*, cu.full_name FROM soc_posts p JOIN customer_users cu ON cu.id = p.user_id' . $w . ' ORDER BY (p.pinned_until IS NOT NULL AND p.pinned_until >= CURDATE()) DESC, p.created_at DESC LIMIT ' . (int)$limit); $me = soc_me();
    foreach ($rows as &$p) {
        $p['reactions'] = all('SELECT emoji, COUNT(*) n, SUM(user_id = ?) mine FROM soc_post_reactions WHERE post_id = ? GROUP BY emoji ORDER BY n DESC', [$me, (int)$p['id']]);
        $p['comments'] = all('SELECT c.*, cu.full_name FROM soc_post_comments c JOIN customer_users cu ON cu.id = c.user_id WHERE c.post_id = ? ORDER BY c.created_at', [(int)$p['id']]);
        if ($p['kind'] === 'poll') { $p['options'] = all('SELECT o.idx, o.label, (SELECT COUNT(*) FROM soc_post_votes v WHERE v.post_id = o.post_id AND v.idx = o.idx) n FROM soc_post_options o WHERE o.post_id = ? ORDER BY o.idx', [(int)$p['id']]); $p['votes'] = array_sum(array_column($p['options'], 'n')); $mv = scalar('SELECT idx FROM soc_post_votes WHERE post_id = ? AND user_id = ?', [(int)$p['id'], $me]); $p['my_vote'] = ($mv === null || $mv === false) ? null : (int)$mv; $p['closed'] = $p['closes_at'] && $p['closes_at'] < date('Y-m-d'); }
    } unset($p); return $rows;
}
function soc_photo_stream(int $id): void { require_staff(); $p = soc_has() ? one('SELECT stored_name, mime_type FROM soc_posts WHERE id = ?', [$id]) : null; $path = $p && $p['stored_name'] ? cp_storage() . '/' . $p['stored_name'] : ''; if (!$path || !is_file($path)) { http_response_code(404); exit; } header('Content-Type: ' . $p['mime_type']); header('X-Content-Type-Options: nosniff'); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=3600'); readfile($path); exit; }

/* ------------------------------------------------------------------ badges */
function soc_metrics(int $uid): array {
    $m = ['ts' => 0, 'tk' => 0, 'rep' => 0, 'kb' => 0, 'tr' => 0, 'kudos' => 0, 'given' => 0, 'cr' => 0, 'years' => 0];
    $g = function (string $sql, array $b) { try { return (int) scalar($sql, $b); } catch (Throwable $e) { return 0; } };
    $m['ts'] = $g('SELECT COUNT(*) FROM timesheets WHERE user_id = ? AND status IN ("submitted","approved","invoiced")', [$uid]);
    $m['tk'] = $g('SELECT COUNT(*) FROM tickets WHERE (assigned_to = ? OR created_by = ?) AND status IN ("resolved","closed")', [$uid, $uid]);
    $m['rep'] = $g('SELECT COUNT(*) FROM fsr_reports WHERE created_by = ? AND status <> "draft"', [$uid]) + $g('SELECT COUNT(*) FROM isr_reports WHERE created_by = ? AND status <> "draft"', [$uid]);
    $m['kb'] = $g('SELECT COUNT(*) FROM kb_answers WHERE author_id = ?', [$uid]);
    $m['tr'] = $g('SELECT COUNT(*) FROM hr_training_members m JOIN hr_people p ON p.id = m.person_id WHERE p.user_id = ? AND m.status = "attended"', [$uid]);
    $m['kudos'] = $g('SELECT COUNT(*) FROM soc_kudos WHERE to_user_id = ?', [$uid]); $m['given'] = $g('SELECT COUNT(*) FROM soc_kudos WHERE from_user_id = ?', [$uid]);
    $m['cr'] = $g('SELECT COUNT(*) FROM cp_requests WHERE user_id = ? AND status = "released"', [$uid]);
    $hire = function_exists('hr_person_of_user') ? (hr_person_of_user($uid)['hire_date'] ?? null) : null; if ($hire) $m['years'] = (int) floor((time() - strtotime((string)$hire)) / (365.25 * 86400));
    return $m;
}
function soc_badges_refresh(int $uid): array {
    if (!soc_has() || !$uid) return []; $m = soc_metrics($uid); $have = array_column(all('SELECT code FROM soc_badges WHERE user_id = ?', [$uid]), 'code'); $new = [];
    foreach (SOC_BADGES as $code => [$icon, $key, $th]) if (!in_array($code, $have, true) && ($m[$key] ?? 0) >= $th) { q('INSERT IGNORE INTO soc_badges (user_id, code) VALUES (?,?)', [$uid, $code]); $new[] = $code; }
    if ($new && $uid === soc_me() && function_exists('nf_push')) nf_push([$uid], 'soc:badge', 'soc:badge:' . $uid . ':' . implode(',', $new), '🏅 ' . t('soc.nf_badge', ['b' => implode(', ', array_map(fn($c) => t('soc.b_' . $c), $new))]), null, url('community') . '#badges', null, null);
    return $new;
}
function soc_badges(int $uid): array { return soc_has() ? array_column(all('SELECT code, earned_at FROM soc_badges WHERE user_id = ? ORDER BY earned_at', [$uid]), 'earned_at', 'code') : []; }
function soc_badges_html(int $uid, int $max = 6): string { $b = soc_badges($uid); if (!$b) return ''; $h = ''; $i = 0; foreach (array_reverse($b, true) as $code => $at) { if ($i++ >= $max) { $h .= '<span class="hint">+' . (count($b) - $max) . '</span>'; break; } $h .= '<span class="soc-badge" title="' . e(t('soc.b_' . $code)) . ' · ' . e(fmt_date($at)) . '">' . (SOC_BADGES[$code][0] ?? '🏅') . '</span>'; } return $h; }

/* ------------------------------------------------------------------ winner of the month */
function soc_winner(?string $month = null): ?array { $month = $month ?: date('Y-m', strtotime('first day of last month')); return soc_has() ? one('SELECT w.*, cu.full_name FROM soc_winners w JOIN customer_users cu ON cu.id = w.user_id WHERE w.month = ?', [$month]) : null; }
function soc_winner_set(): void { if (!is_cp_admin() && !(function_exists('hr_can') && hr_can())) throw new RuntimeException(t('err.noaccess')); $month = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['month'] ?? '')) ? $_POST['month'] : date('Y-m', strtotime('first day of last month')); $uid = (int)($_POST['user_id'] ?? 0); if (!$uid) { q('DELETE FROM soc_winners WHERE month = ?', [$month]); return; } if (!isset(soc_staff()[$uid])) throw new RuntimeException(t('err.notfound'));
    q('INSERT INTO soc_winners (month, user_id, prize, note, set_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), prize = VALUES(prize), note = VALUES(note), set_by = VALUES(set_by), set_at = NOW()', [$month, $uid, mb_substr(trim((string)($_POST['prize'] ?? '')), 0, 200) ?: cp_setting('soc_prize'), mb_substr(trim((string)($_POST['note'] ?? '')), 0, 300) ?: null, soc_me()]);
    soc_notify(array_keys(soc_staff()), 'soc:winner', 'soc:winner:' . $month, '🏆 ' . t('soc.nf_winner', ['u' => soc_name($uid), 'm' => $month]), (string)($_POST['note'] ?? ''), url('community') . '#winner'); }

/* ------------------------------------------------------------------ onboarding */
function soc_onboard_get(int $uid): ?array { return soc_has() ? one('SELECT o.*, cu.full_name AS buddy_name FROM soc_onboard o LEFT JOIN customer_users cu ON cu.id = o.buddy_user_id WHERE o.user_id = ?', [$uid]) : null; }
function soc_onboard_start(): void { if (!is_cp_admin() && !(function_exists('hr_can') && hr_can())) throw new RuntimeException(t('err.noaccess')); $uid = (int)($_POST['user_id'] ?? 0); $buddy = (int)($_POST['buddy_user_id'] ?? 0) ?: null; if (!isset(soc_staff()[$uid])) throw new RuntimeException(t('err.notfound'));
    q('INSERT INTO soc_onboard (user_id, buddy_user_id, started_at) VALUES (?,?,CURDATE()) ON DUPLICATE KEY UPDATE buddy_user_id = VALUES(buddy_user_id), finished_at = NULL', [$uid, $buddy]);
    soc_notify(array_filter([$uid, $buddy]), 'soc:onboard', 'soc:onboard:' . $uid, '👋 ' . t('soc.nf_onboard', ['u' => soc_name($uid)]), $buddy ? t('soc.buddy_is', ['b' => soc_name($buddy)]) : null, url('dashboard') . '#onboard');
    soc_notify(array_keys(soc_staff()), 'soc:welcome', 'soc:welcome:' . $uid, '👋 ' . t('soc.nf_welcome', ['u' => soc_name($uid)]), null, url('dashboard') . '#wishes'); }
function soc_onboard_tick(): void { $uid = (int)($_POST['user_id'] ?? 0); $o = soc_onboard_get($uid); if (!$o) return; if ($uid !== soc_me() && (int)$o['buddy_user_id'] !== soc_me() && !is_cp_admin() && !(function_exists('hr_can') && hr_can())) throw new RuntimeException(t('err.noaccess'));
    $done = array_values(array_intersect(SOC_ONBOARD_ITEMS, array_map('strval', (array)($_POST['done'] ?? [])))); $fin = count($done) === count(SOC_ONBOARD_ITEMS); q('UPDATE soc_onboard SET done = ?, finished_at = ' . ($fin ? 'COALESCE(finished_at, NOW())' : 'NULL') . ' WHERE user_id = ?', [implode(',', $done), $uid]); }
/** Checklists to show on my dashboard: mine, those of people I am buddy of, and (HR) every open one. */
function soc_onboard_mine(): array { if (!soc_has()) return []; $me = soc_me(); $hr = is_cp_admin() || (function_exists('hr_can') && hr_can()); return all('SELECT o.*, cu.full_name, b.full_name AS buddy_name FROM soc_onboard o JOIN customer_users cu ON cu.id = o.user_id LEFT JOIN customer_users b ON b.id = o.buddy_user_id WHERE o.finished_at IS NULL' . ($hr ? '' : ' AND (o.user_id = ? OR o.buddy_user_id = ?)') . ' ORDER BY o.started_at DESC', $hr ? [] : [$me, $me]); }

/* ------------------------------------------------------------------ nudges: morning celebrations, Monday digest (lazy, on the first staff page view) */
function soc_tick(): void {
    if (!soc_has() || !is_staff()) return; $today = date('Y-m-d');
    try {
        if (cp_setting('soc_cel_day') !== $today) { cp_setting_set('soc_cel_day', $today); $names = []; foreach (soc_events(0) as $e) if ($e['date'] === $today) $names[] = $e['person']['name_en'] . ' ' . ($e['kind'] === 'birthday' ? '🎂' : ($e['kind'] === 'nameday' ? '🎊' : ($e['kind'] === 'anniversary' ? '🏅 ' . $e['years'] . 'y' : '👋')));
            if ($names) nf_push(array_keys(soc_staff()), 'soc:cel', 'soc:cel:' . $today, '🎉 ' . t('soc.nf_today', ['n' => implode(' · ', $names)]), t('soc.nf_today_body'), url('dashboard') . '#celebrations', null, null); }
        $week = date('o-\WW'); if ((int) date('N') === 1 && cp_setting('soc_digest_week') !== $week) { cp_setting_set('soc_digest_week', $week); $ev = soc_events(7); $tr = function_exists('hr_trainings') ? count(hr_trainings(['status' => 'open'])) : 0; $posts = (int) scalar('SELECT COUNT(*) FROM soc_posts WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)'); $top = function_exists('act_top') ? act_top(date('Y-m-d', strtotime('-7 days')), $today, 3) : [];
            $body = t('soc.digest_body', ['c' => count($ev), 't' => $tr, 'p' => $posts, 'w' => implode(', ', array_map(fn($r) => $r['name'], $top))]);
            nf_push(array_keys(soc_staff()), 'soc:digest', 'soc:digest:' . $week, '📰 ' . t('soc.nf_digest', ['w' => substr($week, -2)]), $body, url('community'), null, null); }
    } catch (Throwable $e) { error_log('soc_tick: ' . $e->getMessage()); }
}

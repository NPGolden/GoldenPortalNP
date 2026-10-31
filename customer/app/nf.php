<?php
/**
 * Notifications — what changed that each user has not seen yet.        GAS-CP-R18-002
 *
 * Nothing in the portal writes notifications directly: a sweep (at most
 * once a minute, on any page load) reads the event tables the modules
 * already keep (ticket_events, jo_events, fsr_events, ts_events,
 * offer_events, oa_comments, …) past a per-source cursor and turns every
 * new row into one notification per person who should see it. The
 * person who caused the change never gets one. One unread entry per
 * object per person: a second event on the same ticket replaces the
 * earlier unread entry instead of adding to the pile.
 *
 * Groups follow the menu: fleet · work · commercial · library.
 *
 *   nf_has()                 tables present (after migrate_cp_r18_notifications.sql)
 *   nf_sweep()               derive new notifications (throttled, locked)
 *   nf_counts()              ['fleet' => n, …, 'total' => n] unread for the signed-in user
 *   nf_list($grp, $unread, $limit)   rows for the page / the bell
 *   nf_read($ids) / nf_read_all($grp)
 *   nf_label($row)           the event label in the user's language
 *   nf_bell_html($active)    top-bar bell with counter and drop-down
 */
declare(strict_types=1);

function nf_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) scalar("SHOW TABLES LIKE 'notifications'"); } catch (Throwable $e) { $has = false; } }
    return $has;
}
function nf_groups(): array {
    return ['fleet' => t('nav.g_fleet'), 'work' => t('nav.g_work'), 'commercial' => t('nav.g_commercial'), 'library' => t('nav.g_library')];
}
const NF_GROUP_OF = ['tk' => 'work', 'wf' => 'work', 'jo' => 'work', 'fsr' => 'work', 'isr' => 'work', 'ts' => 'work', 'of' => 'commercial', 'oa' => 'commercial', 'ctn' => 'commercial',
                     'cls' => 'fleet', 'drw' => 'fleet', 'spn' => 'fleet', 'spc' => 'fleet', 'doc' => 'library', 'td' => 'work', 'lic' => 'library', 'mt' => 'work', 'bg' => 'fleet', 'wp' => 'work', 'sec' => 'library', 'acc' => 'commercial', 'pd' => 'work', 'pi' => 'commercial', 'eng' => 'work', 'hrq' => 'work', 'hrm' => 'work', 'crq' => 'library', 'soc' => 'work', 'hrt' => 'work', 'hrev' => 'work', 'hra' => 'work'];

/* ------------------------------------------------------------ sweep */

/** Run the sweep if the last one is older than $every seconds. Safe to call on every page. */
function nf_sweep(int $every = 60, bool $force = false): int {
    if (!nf_has()) return 0;
    try {
        $run = one('SELECT run_at FROM nf_cursor WHERE source = ?', ['_meta']);
        if (!$force && $run && $run['run_at'] && strtotime((string)$run['run_at']) > time() - $every) return 0;
        if (!scalar("SELECT GET_LOCK('nf_sweep', 0)")) return 0;
        $n = 0;
        try {
            foreach (['ticket_events', 'ticket_comments', 'jo_events', 'fsr_events', 'isr_events', 'ts_events', 'offer_events', 'oa_comments', 'owner_approvals',
                      'class_submittals', 'drawing_requests', 'sp_notes', 'sp_comments', 'documents', 'contact_notes'] as $src) {
                $fn = 'nf_src_' . $src;
                try { if (function_exists($fn)) $n += nf_run_source($src, $fn); } catch (Throwable $e) { /* a module not installed on this portal: skip its source */ }
            }
            try { if (function_exists('td_remind')) $n += td_remind(); } catch (Throwable $e) {}        // R22-002 task reminders
            try { if (function_exists('lic_notify')) lic_notify(); } catch (Throwable $e) {}            // R22-001 licence expiry
            try { if (function_exists('hra_remind')) { $n += hra_remind(); hra_purge(); } } catch (Throwable $e) {}   // R67-001 HR Assistant reminders + purge of closed flows
            q('INSERT INTO nf_cursor (source, last_id, run_at) VALUES (?, 0, NOW()) ON DUPLICATE KEY UPDATE run_at = NOW()', ['_meta']);
            if (random_int(1, 40) === 1) nf_prune();                         // seen entries older than 90 days, now and then
        } finally { scalar("SELECT RELEASE_LOCK('nf_sweep')"); }
        return $n;
    } catch (Throwable $e) { return 0; }
}
/** Fetch rows past the cursor (first run on a source starts from "now"). */
function nf_run_source(string $src, callable $fn, int $batch = 400): int {
    $cur = one('SELECT last_id FROM nf_cursor WHERE source = ?', [$src]);
    if (!$cur) { q('INSERT IGNORE INTO nf_cursor (source, last_id) SELECT ?, COALESCE(MAX(id), 0) FROM ' . $src, [$src]); return 0; }
    $rows = all("SELECT * FROM {$src} WHERE id > ? ORDER BY id LIMIT {$batch}", [(int)$cur['last_id']]);
    if (!$rows) return 0;
    $n = $fn($rows);
    q('UPDATE nf_cursor SET last_id = ? WHERE source = ?', [(int) end($rows)['id'], $src]);
    return $n;
}

/* --------------------------------------------------------- recipients */

function nf_admins(): array {
    static $a = null;
    if ($a === null) $a = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"), 'id'));
    return $a;
}
/** Staff who follow a project: its PM and every administrator. */
function nf_project_staff(int $pid): array {
    static $c = [];
    if (!isset($c[$pid])) {
        $pm = $pid ? (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]) : 0;
        $c[$pid] = array_values(array_unique(array_filter(array_merge(nf_admins(), [$pm]))));
    }
    return $c[$pid];
}
/** Active customer users of one customer. */
function nf_customer_users(int $cid): array {
    static $c = [];
    if (!$cid) return [];
    if (!isset($c[$cid])) $c[$cid] = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role = 'customer' AND is_active = 1 AND customer_id = ?", [$cid]), 'id'));
    return $c[$cid];
}
function nf_project(int $pid): ?array {
    static $c = [];
    if (!array_key_exists($pid, $c)) $c[$pid] = $pid ? one('SELECT p.id, p.customer_id, p.pm_user_id, p.name, p.erp_code, p.project_code FROM projects p WHERE p.id = ?', [$pid]) : null;
    return $c[$pid];
}
function nf_subproject(int $sid): ?array {
    static $c = [];
    if (!array_key_exists($sid, $c)) $c[$sid] = $sid ? one('SELECT s.id, s.name, s.code, s.project_id, p.customer_id, p.project_code FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ?', [$sid]) : null;
    return $c[$sid];
}
/** Everybody around a project: staff always, the owner's users when the change is visible to them. */
function nf_people(int $pid, bool $external, array $extra = []): array {
    $p = nf_project($pid);
    $ids = array_merge(nf_project_staff($pid), $extra);
    if ($external && $p) $ids = array_merge($ids, nf_customer_users((int)$p['customer_id']));
    return array_values(array_unique(array_filter(array_map('intval', $ids))));
}
function nf_user_name(?int $uid): ?string {
    static $c = [];
    if (!$uid) return null;
    if (!array_key_exists($uid, $c)) $c[$uid] = scalar('SELECT full_name FROM customer_users WHERE id = ?', [$uid]) ?: null;
    return $c[$uid];
}

/* -------------------------------------------------------------- push */

/** One notification per recipient, never to the actor; replaces an unread one on the same object. */
function nf_push(array $userIds, string $kind, string $refKey, string $title, ?string $body, string $url, ?int $actorId, ?string $actorName, ?string $at = null): int {
    $grp = NF_GROUP_OF[explode(':', $kind, 2)[0]] ?? 'work';
    $title = mb_substr(trim($title), 0, 200); $body = $body !== null ? (mb_substr(trim(preg_replace('/\s+/u', ' ', $body)), 0, 400) ?: null) : null;
    $at = $at ?: date('Y-m-d H:i:s'); $n = 0;
    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        if (!$uid || ($actorId && $uid === (int)$actorId)) continue;
        $ex = one('SELECT id, created_at FROM notifications WHERE user_id = ? AND read_at IS NULL AND ref_key = ? LIMIT 1', [$uid, $refKey]);
        if ($ex) { if (strtotime((string)$ex['created_at']) <= strtotime($at)) q('UPDATE notifications SET grp = ?, kind = ?, title = ?, body = ?, url = ?, actor_id = ?, actor_name = ?, created_at = ? WHERE id = ?', [$grp, $kind, $title, $body, $url, $actorId ?: null, $actorName, $at, $ex['id']]); }   // the newest event wins, whichever source is swept first
        else q('INSERT INTO notifications (user_id, grp, kind, ref_key, title, body, url, actor_id, actor_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)', [$uid, $grp, $kind, $refKey, $title, $body, $url, $actorId ?: null, $actorName, $at]);
        $n++;
    }
    return $n;
}

/* ----------------------------------------------------------- sources */

const NF_TK_SKIP  = ['edited'];
const NF_JO_SKIP  = ['saved'];
const NF_FSR_SKIP = ['saved', 'created'];
const NF_TS_SKIP  = ['saved', 'created'];
const NF_OF_SKIP  = ['edited'];

function nf_ticket(int $id): ?array {
    static $c = [];
    if (!array_key_exists($id, $c)) $c[$id] = one('SELECT t.id, t.ticket_no, t.title, t.customer_id, t.vessel_id, t.project_id, t.created_by, t.assigned_to, v.customer_id AS v_customer FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id WHERE t.id = ?', [$id]);
    return $c[$id];
}
function nf_ticket_people(array $t, bool $external): array {
    $extra = [(int)$t['assigned_to'], (int)$t['created_by']];
    if ($t['project_id']) return nf_people((int)$t['project_id'], $external, $extra);
    $ids = array_merge(nf_admins(), $extra);
    if ($external) $ids = array_merge($ids, nf_customer_users((int)($t['customer_id'] ?: $t['v_customer'])));
    return array_values(array_unique(array_filter($ids)));
}
function nf_src_ticket_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_TK_SKIP, true)) continue;
        $t = nf_ticket((int)$r['ticket_id']); if (!$t) continue;
        $n += nf_push(nf_ticket_people($t, true), 'tk:' . $r['event'], 'tk:' . $t['id'], $t['ticket_no'] . ' · ' . $t['title'], $r['note'], url('ticket', ['id' => $t['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
function nf_src_ticket_comments(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if ($r['deleted_at']) continue;
        $t = nf_ticket((int)$r['ticket_id']); if (!$t) continue;
        $n += nf_push(nf_ticket_people($t, $r['visibility'] === 'external'), 'tk:comment', 'tk:' . $t['id'], $t['ticket_no'] . ' · ' . $t['title'], $r['body'], url('ticket', ['id' => $t['id']]) . '#comments', (int)$r['user_id'] ?: null, $r['author'], $r['created_at']);
    }
    return $n;
}
function nf_src_jo_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_JO_SKIP, true)) continue;
        $j = one('SELECT id, jo_no, title, project_id, requested_by, approved_by, status FROM job_orders WHERE id = ?', [(int)$r['job_order_id']]); if (!$j) continue;
        $external = $j['status'] !== 'draft';
        $n += nf_push(nf_people((int)$j['project_id'], $external, [(int)$j['requested_by'], (int)$j['approved_by']]), 'jo:' . $r['event'], 'jo:' . $j['id'], $j['jo_no'] . ' · ' . $j['title'], $r['note'], url('jo', ['id' => $j['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
function nf_src_fsr_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_FSR_SKIP, true)) continue;
        $f = one('SELECT id, report_no, vessel_name, project_id, created_by, submitted_by, status FROM fsr_reports WHERE id = ?', [(int)$r['report_id']]); if (!$f) continue;
        $n += nf_push(nf_people((int)$f['project_id'], $f['status'] !== 'draft', [(int)$f['created_by'], (int)$f['submitted_by']]), 'fsr:' . $r['event'], 'fsr:' . $f['id'], $f['report_no'] . ($f['vessel_name'] ? ' · ' . $f['vessel_name'] : ''), $r['note'], url('fsr', ['id' => $f['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
/** Inspection reports (R23-003): submitted / issued / reopened; the owner only hears of issued ones. */
function nf_src_isr_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_FSR_SKIP, true)) continue;
        $f = one('SELECT id, report_no, vessel_name, project_id, created_by, submitted_by, status FROM isr_reports WHERE id = ?', [(int)$r['report_id']]); if (!$f) continue;
        $n += nf_push(nf_people((int)$f['project_id'], $r['event'] === 'issued', [(int)$f['created_by'], (int)$f['submitted_by']]), 'isr:' . $r['event'], 'isr:' . $f['id'], $f['report_no'] . ($f['vessel_name'] ? ' · ' . $f['vessel_name'] : ''), $r['note'], url('isr', ['id' => $f['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
function nf_src_ts_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_TS_SKIP, true)) continue;
        $s = one('SELECT id, ts_no, user_id, user_name, approver_id, approved_by, project_id FROM timesheets WHERE id = ?', [(int)$r['timesheet_id']]); if (!$s) continue;
        $ids = array_merge(nf_admins(), [(int)$s['user_id'], (int)$s['approver_id'], (int)$s['approved_by']]);     // staff only — never the owner
        $n += nf_push(array_unique(array_filter($ids)), 'ts:' . $r['event'], 'ts:' . $s['id'], $s['ts_no'] . ' · ' . $s['user_name'], $r['note'], url('timesheet', ['id' => $s['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
function nf_src_offer_events(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if (in_array($r['event'], NF_OF_SKIP, true)) continue;
        $o = one('SELECT id, offer_no, revision, title, project_id, created_by, decided_by FROM offers WHERE id = ?', [(int)$r['offer_id']]); if (!$o) continue;
        $n += nf_push(of_users(nf_people((int)$o['project_id'], true, [(int)$o['created_by'], (int)$o['decided_by']])), 'of:' . $r['event'], 'of:' . $o['id'], $o['offer_no'] . ' ' . $o['revision'] . ' · ' . $o['title'], $r['note'], url('offer', ['id' => $o['id']]), (int)$r['user_id'] ?: null, $r['user_name'], $r['created_at']);
    }
    return $n;
}
function nf_approval(int $id): ?array {
    static $c = [];
    if (!array_key_exists($id, $c)) $c[$id] = one('SELECT id, doc_no, revision, title, project_id, subproject_id, status, decided_by FROM owner_approvals WHERE id = ?', [$id]);
    return $c[$id];
}
function nf_src_oa_comments(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if ($r['deleted_at']) continue;
        $a = nf_approval((int)$r['approval_id']); if (!$a) continue;
        $n += nf_push(nf_people((int)$a['project_id'], true, [(int)$a['decided_by']]), 'oa:' . $r['kind'], 'oa:' . $a['id'], $a['doc_no'] . ' ' . $a['revision'] . ' · ' . $a['title'], $r['body'], url('approval', ['id' => $a['id']]), (int)$r['user_id'] ?: null, $r['author'], $r['created_at']);
    }
    return $n;
}
function nf_src_owner_approvals(array $rows): int {
    $n = 0;
    foreach ($rows as $a) {
        $external = $a['status'] !== 'draft';
        $n += nf_push(nf_people((int)$a['project_id'], $external), 'oa:new', 'oa:' . $a['id'], $a['doc_no'] . ' ' . $a['revision'] . ' · ' . $a['title'], null, url('approval', ['id' => $a['id']]), null, null, $a['submitted_at'] ? $a['submitted_at'] . ' 00:00:00' : null);
    }
    return $n;
}
/** Rows that arrive in bulk (imports, drawing lists) are folded into one entry per sub-project per sweep. */
function nf_fold(array $rows, string $kind, string $tab, string $oneTitleCol, string $manyKey): int {
    $by = [];
    foreach ($rows as $r) $by[(int)($r['subproject_id'] ?: 0) . '|' . (int)$r['project_id']][] = $r;
    $n = 0;
    foreach ($by as $k => $grp) {
        [$sid, $pid] = array_map('intval', explode('|', $k));
        $sp = nf_subproject($sid);
        $where = $sp ? trim(($sp['project_code'] ?: '') . ' ' . ($sp['code'] ?: '') . ' · ' . $sp['name'], ' ·') : ((nf_project($pid)['name'] ?? '') ?: '—');
        $url = $sp ? url('subproject', ['id' => $sid, 'tab' => $tab]) : url('projects', ['project' => $pid]);
        $title = count($grp) === 1 ? $where : $where;
        $body = count($grp) === 1 ? (string)$grp[0][$oneTitleCol] : t($manyKey, ['n' => count($grp)]);
        $n += nf_push(nf_people($pid, true), $kind, $kind . ':' . $sid . ':' . $pid, $title, $body, $url, null, null, end($grp)['created_at'] ?? end($grp)['updated_at'] ?? null);
    }
    return $n;
}
function nf_src_class_submittals(array $rows): int { return nf_fold($rows, 'cls:new', 'class', 'title', 'nf.n_class'); }
function nf_src_drawing_requests(array $rows): int { return nf_fold($rows, 'drw:new', 'drawings', 'title', 'nf.n_drawings'); }
function nf_src_sp_notes(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        $sp = nf_subproject((int)$r['subproject_id']); if (!$sp) continue;
        $n += nf_push(nf_people((int)$sp['project_id'], $r['visibility'] === 'external', [(int)$r['author_id']]), 'spn:new', 'spn:' . $sp['id'], trim(($sp['project_code'] ?: '') . ' ' . ($sp['code'] ?: '') . ' · ' . $sp['name'], ' ·'), $r['body'], url('subproject', ['id' => $sp['id'], 'tab' => 'weekly']), (int)$r['author_id'] ?: null, nf_user_name((int)$r['author_id']), $r['created_at']);
    }
    return $n;
}
function nf_src_sp_comments(array $rows): int {
    $n = 0;
    foreach ($rows as $r) {
        if ($r['deleted_at']) continue;
        $sp = nf_subproject((int)$r['subproject_id']); if (!$sp) continue;
        $n += nf_push(nf_people((int)$sp['project_id'], $r['visibility'] === 'external', [(int)$r['author_id']]), 'spc:new', 'spc:' . $sp['id'], trim(($sp['project_code'] ?: '') . ' ' . ($sp['code'] ?: '') . ' · ' . $sp['name'], ' ·'), $r['body'], url('subproject', ['id' => $sp['id'], 'tab' => 'comments']), (int)$r['author_id'] ?: null, nf_user_name((int)$r['author_id']), $r['created_at']);
    }
    return $n;
}
function nf_src_documents(array $rows): int {
    $n = 0;
    foreach ($rows as $d) {
        $ids = array_merge(nf_admins(), nf_customer_users((int)$d['customer_id']), $d['project_id'] ? nf_project_staff((int)$d['project_id']) : []);
        $n += nf_push(array_unique(array_filter($ids)), 'doc:new', 'doc:' . $d['id'], (string)$d['title'], t('d.cat.' . $d['category']), url('documents'), null, null, $d['created_at']);
    }
    return $n;
}
function nf_src_contact_notes(array $rows): int {
    if (!function_exists('ct_has') || !ct_has()) return 0;
    $n = 0;
    foreach ($rows as $r) {
        $c = one('SELECT id, full_name FROM contacts WHERE id = ?', [(int)$r['contact_id']]); if (!$c) continue;
        $n += nf_push(nf_admins(), 'ctn:new', 'ctn:' . $c['id'], (string)$c['full_name'], $r['body'], url('contact', ['id' => $c['id']]), (int)$r['author_id'] ?: null, nf_user_name((int)$r['author_id']), $r['created_at']);
    }
    return $n;
}

/* ------------------------------------------------------------- read */

function nf_counts(): array {
    $z = ['fleet' => 0, 'work' => 0, 'commercial' => 0, 'library' => 0, 'total' => 0];
    $u = current_user(); if (!$u || !nf_has()) return $z;
    foreach (all('SELECT grp, COUNT(*) n FROM notifications WHERE user_id = ? AND read_at IS NULL GROUP BY grp', [(int)$u['id']]) as $r) { $z[$r['grp']] = (int)$r['n']; $z['total'] += (int)$r['n']; }
    return $z;
}
function nf_list(?string $grp = null, bool $unreadOnly = false, int $limit = 50, int $offset = 0): array {
    $u = current_user(); if (!$u || !nf_has()) return [];
    $w = ['user_id = ?']; $b = [(int)$u['id']];
    if ($grp && isset(nf_groups()[$grp])) { $w[] = 'grp = ?'; $b[] = $grp; }
    if ($unreadOnly) $w[] = 'read_at IS NULL';
    return all('SELECT * FROM notifications WHERE ' . implode(' AND ', $w) . " ORDER BY (read_at IS NULL) DESC, created_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}", $b);
}
function nf_count_all(?string $grp = null, bool $unreadOnly = false): int {
    $u = current_user(); if (!$u || !nf_has()) return 0;
    $w = ['user_id = ?']; $b = [(int)$u['id']];
    if ($grp && isset(nf_groups()[$grp])) { $w[] = 'grp = ?'; $b[] = $grp; }
    if ($unreadOnly) $w[] = 'read_at IS NULL';
    return (int) scalar('SELECT COUNT(*) FROM notifications WHERE ' . implode(' AND ', $w), $b);
}
function nf_read(array $ids): void {
    $u = current_user(); if (!$u || !nf_has() || !$ids) return;
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids) q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([(int)$u['id']], $ids));
}
/** Close an open entry for everybody by its ref key (e.g. an administrator unlocked the account).   GAS-CP-R36-001 */
function nf_mark_ref_read(string $refKey): void {
    if (!nf_has()) return;
    q('UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL AND ref_key = ?', [$refKey]);
}
function nf_read_all(?string $grp = null): void {
    $u = current_user(); if (!$u || !nf_has()) return;
    if ($grp && isset(nf_groups()[$grp])) q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL AND grp = ?', [(int)$u['id'], $grp]);
    else q('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [(int)$u['id']]);
}
/** Open one entry: mark it read and go where it points (?p=nf_open&id=). */
function nf_open(int $id): void {
    $u = current_user(); if (!$u) redirect('dashboard');
    $r = nf_has() ? one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, (int)$u['id']]) : null;
    if (!$r) redirect('notifications');
    nf_read([$id]);
    $url = (string)$r['url'];
    if (!preg_match('#^index\.php\?p=[a-z_]+#', $url)) $url = url('notifications');
    header('Location: ' . $url, true, 303); exit;
}
/** Older read entries are of no use after a while. */
function nf_prune(int $days = 90): void { if (nf_has()) q('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < DATE_SUB(NOW(), INTERVAL ? DAY)', [$days]); }

/* ------------------------------------------------------------ labels */

/** Event label in the user's language, from the keys the modules already have. */
function nf_label(array $r): string {
    [$src, $ev] = array_pad(explode(':', (string)$r['kind'], 2), 2, '');
    $tk = ['status_open' => 'tk.ev_reopened', 'status_in_progress' => 'tk.ev_started', 'status_waiting_customer' => 'tk.ev_waiting', 'status_resolved' => 'tk.ev_resolved', 'status_closed' => 'tk.ev_closed'];
    $try = [];
    switch ($src) {
        case 'tk':  $try = [$tk[$ev] ?? ('tk.ev_' . $ev), 'nf.k_comment']; if ($ev === 'comment') $try = ['nf.k_comment']; break;
        case 'jo':  $try = ['jo.ev_' . $ev]; break;
        case 'fsr': $try = ['fsr.ev_' . $ev]; break;
        case 'isr': $try = ['isr.ev_' . $ev]; break;
        case 'ts':  $try = ['ts.ev_' . $ev]; break;
        case 'of':  if (str_starts_with($ev, 'status_') && function_exists('of_status_label')) return t('nf.k_of_status', ['s' => of_status_label(substr($ev, 7))]);
                    $try = ['of.ev_' . $ev, 'of.st_' . $ev]; break;
        case 'oa':  $try = ['nf.k_oa_' . $ev]; break;
        default:    $try = ['nf.k_' . $src . '_' . $ev];
    }
    foreach ($try as $k) { $s = t($k); if ($s !== $k) return $s; }
    return t('nf.k_changed');
}
function nf_source_label(string $kind): string {
    $src = explode(':', $kind, 2)[0];
    $map = ['tk' => 'nav.tickets', 'jo' => 'nav.joborders', 'fsr' => 'nav.reports', 'isr' => 'isr.plural', 'ts' => 'nav.timesheets', 'of' => 'nav.offers', 'oa' => 'nav.approvals', 'ctn' => 'nav.contacts',
            'cls' => 'tab.class', 'drw' => 'tab.drawings', 'spn' => 'tab.weekly', 'spc' => 'tab.comments', 'doc' => 'nav.docs', 'td' => 'td.title', 'lic' => 'lic.title', 'mt' => 'mt.src', 'bg' => 'bg.tab', 'wp' => 'wp.title', 'sec' => 'au.title', 'acc' => 'acc.title', 'pd' => 'pd.title', 'pi' => 'pi.title', 'eng' => 'eng.title', 'hrq' => 'hr.requests', 'hrm' => 'hr.msg_title', 'crq' => 'cr.title', 'soc' => 'soc.title', 'hrt' => 'hr.training', 'hrev' => 'hr.evaluations', 'hra' => 'hra.title'];
    $k = $map[$src] ?? ''; $s = $k ? t($k) : ''; return $s !== $k ? $s : '';
}
/** Amounts in offer notes stay hidden from roles without financial visibility. */
function nf_body(array $r): string {
    $b = (string)($r['body'] ?? '');
    if ($b !== '' && str_starts_with((string)$r['kind'], 'of:') && function_exists('fin_visible') && !fin_visible())      // any figure in an offer note is an amount
        $b = preg_replace(['/\p{Sc}\s?\d[\d\s.,\']*|\d[\d\s.,\']*\s?(k?€|\p{Sc}|EUR|USD|GBP)\b/u', '/\d[\d.,\s\']*\d/u'], fin_masked(), $b);
    return $b;
}
function nf_ago(string $at): string {
    $d = time() - strtotime($at);
    if ($d < 60) return t('nf.ago_now');
    if ($d < 3600) return t('nf.ago_m', ['n' => intdiv($d, 60)]);
    if ($d < 86400) return t('nf.ago_h', ['n' => intdiv($d, 3600)]);
    if ($d < 7 * 86400) return t('nf.ago_d', ['n' => intdiv($d, 86400)]);
    return fmt_date($at);
}
function nf_grp_pill(string $grp): string {
    $tone = ['fleet' => 'navy', 'work' => 'gold', 'commercial' => 'green', 'library' => 'mute'][$grp] ?? 'mute';
    return '<span class="pill pill--' . $tone . ' nf-grp nf-grp--' . e($grp) . '">' . e(nf_groups()[$grp] ?? $grp) . '</span>';
}

/* -------------------------------------------------------------- bell */

function nf_bell_html(string $active): string {
    if (!nf_has()) return '';
    $c = nf_counts(); $n = $c['total'];
    $h = '<details class="nfdd"><summary class="nfbell' . ($n ? ' has-new' : '') . ($active === 'notifications' ? ' is-here' : '') . '" title="' . tt('nf.title') . '" aria-label="' . e(t('nf.bell_aria', ['n' => $n])) . '">'
       . '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>'
       . ($n ? '<span class="nfcount">' . ($n > 99 ? '99+' : $n) . '</span>' : '') . '</summary>';
    $h .= '<div class="nfpanel"><div class="nfpanel-head"><b>' . tt('nf.title') . '</b>';
    if ($n) $h .= '<form method="post" action="' . url($active ?: 'dashboard') . '" class="nfinline">' . csrf_field() . '<input type="hidden" name="action" value="nf_read_all"><button type="submit" class="linkbtn">' . tt('nf.mark_all') . '</button></form>';
    $h .= '</div>';
    $rows = nf_list(null, true, 8);
    if (!$rows) $h .= '<div class="nfempty">' . tt('nf.none_unread') . '</div>';
    else {
        $h .= '<ul class="nflist">';
        foreach ($rows as $r) $h .= '<li><a href="' . url('nf_open', ['id' => $r['id']]) . '"><span class="nf-l1">' . nf_grp_pill($r['grp']) . '<b>' . e(nf_label($r)) . '</b><time>' . e(nf_ago($r['created_at'])) . '</time></span><span class="nf-l2">' . e($r['title']) . '</span>' . ($r['actor_name'] ? '<span class="nf-l3">' . e($r['actor_name']) . '</span>' : '') . '</a></li>';
        $h .= '</ul>';
        if ($n > 8) $h .= '<div class="nfmore">' . e(t('nf.n_more', ['n' => $n - 8])) . '</div>';
    }
    $h .= '<a class="nfall" href="' . url('notifications') . '">' . tt('nf.open_all') . ' ›</a></div></details>';
    return $h;
}

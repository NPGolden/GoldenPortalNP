<?php
/** Website statistics feed — the four Fleet & activity counts for goldenas.com.gr, nothing else.   GAS-CP-R90-001
 *  The public website never talks to the portal: the website's build pipeline pulls web_feed.php once a week with
 *  the site key (Settings › System › Website), writes the numbers into a static JSON file and publishes that. */
declare(strict_types=1);

/** The feed exists when the activity module and the settings table are there. */
function web_has(): bool { return function_exists('act_fleet') && function_exists('cp_setting') && function_exists('lic_has') && lic_has(); }

/** Aggregate counts only — integers, no names, no ids, no dates of anything. Cached for an hour (the website asks weekly). */
function web_counts(): array {
    $fn = function (): array {
        $y = (int) date('Y'); $today = date('Y-m-d');
        $c = ['year' => $y,
              'vessels_year' => count(act_fleet("$y-01-01", $today)),
              'vessels_30d' => count(act_fleet(date('Y-m-d', strtotime('-30 days')), $today)),
              'new_vessels_year' => count(act_new_vessels($y)),
              'active_vessels' => (int) scalar('SELECT COUNT(*) FROM vessels WHERE is_active = 1'),
              'generated_at' => gmdate('c')];
        foreach ($c as $k => $v) if ($k !== 'generated_at') $c[$k] = max(0, (int) $v);
        return $c;
    };
    return function_exists('cache_remember') ? cache_remember('web_counts', 3600, $fn) : $fn();
}

/** Last successful pull by the website (ISO time · masked IP), for the settings page. */
function web_mark_pull(string $ip): void { $m = preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip) ? preg_replace('/\.\d+$/', '.x', $ip) : substr($ip, 0, 12) . '…'; cp_setting_set('web_last_pull', gmdate('c') . ' ' . $m); }

/* ---------- Website inbox (GAS-CP-R90-002): support requests and portal access requests posted by the website ---------- */
function web_inbox_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'web_inquiries'"); } catch (Throwable $e) { $h = false; } } return $h; }
function web_ref(): string { $base = 'WEB-' . date('Y') . '-'; $n = (int) scalar("SELECT COALESCE(MAX(CAST(SUBSTRING(ref, 10) AS UNSIGNED)), 0) FROM web_inquiries WHERE ref LIKE ?", [$base . '%']) + 1; return sprintf('%s%04d', $base, $n); }
const WEB_SYSTEMS = ['power', 'automation', 'navigation', 'communication', 'cyber', 'lighting', 'other'];
const WEB_PRIORITIES = ['low', 'normal', 'high', 'vessel_stopped'];
/** Staff who hear about a new website inquiry: works people for tickets, administrators for access requests. */
function web_notify_ids(string $kind): array {
    try { $sql = $kind === 'ticket' ? "SELECT id FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 AND (can_works = 1 OR role = 'admin')" : "SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"; return array_map(fn($r) => (int) $r['id'], all($sql)); } catch (Throwable $e) { return []; }
}
/** Public POST ?r=ticket — payload from api/ticket.php on the website. Returns ['ref' => …]. */
function web_pub_ticket(array $b, string $ip): array {
    $f = fn(string $k, int $max) => mb_substr(trim((string) ($b[$k] ?? '')), 0, $max);
    $name = $f('name', 120); $company = $f('company', 160); $email = $f('email', 190); $vessel = $f('vessel', 160); $subject = $f('subject', 160); $msg = $f('message', 6000);
    if ($name === '' || $email === '' || $vessel === '' || $subject === '' || $msg === '') throw new RuntimeException('missing fields', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('bad email', 422);
    $sys = in_array($b['system'] ?? '', WEB_SYSTEMS, true) ? (string) $b['system'] : 'other'; $pri = in_array($b['priority'] ?? '', WEB_PRIORITIES, true) ? (string) $b['priority'] : 'normal';
    // the same person sending the same subject twice within an hour is one request
    $dup = one('SELECT ref FROM web_inquiries WHERE kind = "ticket" AND email = ? AND subject = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', [$email, $subject]); if ($dup) return ['ref' => $dup['ref'], 'duplicate' => true];
    $ref = web_ref();
    q('INSERT INTO web_inquiries (ref, kind, name, company, email, phone, vessel, location, system_kind, priority, subject, message, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
      [$ref, 'ticket', $name, $company, $email, $f('phone', 40), $vessel, $f('location', 120), $sys, $pri, $subject, $msg, mb_substr($ip, 0, 64)]);
    $id = (int) db()->lastInsertId();
    if (function_exists('nf_push')) try { nf_push(web_notify_ids('ticket'), 'tk', 'web:' . $id, ($pri === 'vessel_stopped' ? '🚨 ' : '') . t('web.nf_ticket', ['vessel' => $vessel]), $subject . ' — ' . $company, url('web_inbox', ['id' => $id]), null, 'Website'); } catch (Throwable $e) {}
    return ['ref' => $ref];
}
/** Public POST ?r=access — payload from api/portal-access.php. */
function web_pub_access(array $b, string $ip): array {
    $f = fn(string $k, int $max) => mb_substr(trim((string) ($b[$k] ?? '')), 0, $max);
    $customer = $f('customer', 160); $contact = $f('contact', 120); $email = $f('email', 190);
    if ($customer === '' || $contact === '' || $email === '') throw new RuntimeException('missing fields', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('bad email', 422);
    $dup = one('SELECT ref FROM web_inquiries WHERE kind = "access" AND email = ? AND status IN ("new","in_progress")', [$email]); if ($dup) return ['ref' => $dup['ref'], 'duplicate' => true];
    $ref = web_ref();
    q('INSERT INTO web_inquiries (ref, kind, name, company, email, phone, role, vessels, message, ip) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$ref, 'access', $contact, $customer, $email, $f('phone', 40), $f('role', 80), $f('vessels', 400), $f('message', 2000), mb_substr($ip, 0, 64)]);
    $id = (int) db()->lastInsertId();
    if (function_exists('nf_push')) try { nf_push(web_notify_ids('access'), 'tk', 'web:' . $id, t('web.nf_access', ['customer' => $customer]), $contact . ' · ' . $email, url('web_inbox', ['id' => $id]), null, 'Website'); } catch (Throwable $e) {}
    return ['ref' => $ref];
}
function web_inquiries(array $f = []): array {
    $w = ['1=1']; $b = [];
    if (!empty($f['kind'])) { $w[] = 'i.kind = ?'; $b[] = $f['kind']; }
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "i.status IN ('new','in_progress')"; else { $w[] = 'i.status = ?'; $b[] = $f['status']; } }
    return all('SELECT i.*, u.full_name handled_name, t.ticket_no FROM web_inquiries i LEFT JOIN customer_users u ON u.id = i.handled_by LEFT JOIN tickets t ON t.id = i.ticket_id WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(i.status,"new","in_progress","done","spam"), i.created_at DESC LIMIT 500', $b);
}
function web_inquiry(int $id): ?array { return one('SELECT i.*, u.full_name handled_name, t.ticket_no FROM web_inquiries i LEFT JOIN customer_users u ON u.id = i.handled_by LEFT JOIN tickets t ON t.id = i.ticket_id WHERE i.id = ?', [$id]) ?: null; }
function web_inbox_count(): int { try { return web_inbox_has() ? (int) scalar("SELECT COUNT(*) FROM web_inquiries WHERE status = 'new'") : 0; } catch (Throwable $e) { return 0; } }
/** Turns a website support request into a portal ticket on the chosen vessel (staff). */
function web_make_ticket(int $id, int $vesselId): int {
    $i = web_inquiry($id); if (!$i || $i['kind'] !== 'ticket') throw new RuntimeException(t('err.notfound'));
    if ($i['ticket_id']) return (int) $i['ticket_id'];
    $v = one('SELECT id, customer_id FROM vessels WHERE id = ?', [$vesselId]); if (!$v) throw new RuntimeException(t('err.notfound'));
    $u = current_user();
    $pri = ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'vessel_stopped' => 'urgent'][$i['priority']] ?? 'normal';
    $desc = $i['message'] . "\n\n— " . t('web.from_site') . ' ' . $i['ref'] . ' · ' . $i['name'] . ' (' . $i['company'] . ') · ' . $i['email'] . ($i['phone'] !== '' ? ' · ' . $i['phone'] : '') . ($i['location'] !== '' ? ' · ' . $i['location'] : '') . ' · ' . t('web.system') . ': ' . $i['system_kind'];
    q('INSERT INTO tickets (ticket_no, customer_id, vessel_id, title, description, category, priority, created_side, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [tk_number(), $v['customer_id'], (int) $v['id'], mb_substr($i['subject'], 0, 160), $desc, 'fault', $pri, 'customer', (int) $u['id'], mb_substr($i['name'], 0, 120)]);
    $tid = (int) db()->lastInsertId();
    if (function_exists('tk_event')) tk_event($tid, 'created', t('web.from_site') . ' ' . $i['ref']);
    if (function_exists('rs_has') && rs_has() && function_exists('rs_cover_ticket')) rs_cover_ticket($tid, (int) $v['id']);
    q('UPDATE web_inquiries SET ticket_id = ?, status = "done", handled_by = ?, handled_at = NOW() WHERE id = ?', [$tid, (int) $u['id'], $id]);
    return $tid;
}

/** Settings › System › Website actions (administrators only) + inbox actions (staff). */
function web_handle_action(string $action): bool {
    if ($action === 'web_inq_status') { require_staff(); $id = (int) $_POST['id']; $st = in_array($_POST['status'] ?? '', ['new', 'in_progress', 'done', 'spam'], true) ? (string) $_POST['status'] : 'in_progress';
        q('UPDATE web_inquiries SET status = ?, note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?', [$st, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 400) ?: null, (int) current_user()['id'], $id]);
        flash('good', t('sys.saved')); redirect('web_inbox', ['id' => $id, '_r' => uniqid()]); }
    if ($action === 'web_make_ticket') { require_staff(); $tid = web_make_ticket((int) $_POST['id'], (int) $_POST['vessel_id']); flash('good', t('web.ticket_created')); redirect('ticket', ['id' => $tid, '_r' => uniqid()]); }
    if ($action !== 'web_settings_save') return false;
    require_cp_admin();
    if (!empty($_POST['web_rotate_key'])) { $nk = bin2hex(random_bytes(24)); cp_setting_set('web_site_key', $nk); $_SESSION['web_key_once'] = $nk; }   // shown in full once, on the next page view only
    if (!empty($_POST['web_disable'])) cp_setting_set('web_site_key', '');
    if (function_exists('cache_forget')) cache_forget('web_counts');
    flash('good', t('sys.saved')); redirect('settings', ['tab' => 'website', '_r' => uniqid()]);
    return true;
}

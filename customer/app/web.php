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
function web_ref(string $site = 'golden'): string { $base = (kym_site($site) === 'kyma' ? 'KYM-' : 'WEB-') . date('Y') . '-'; /* R97-050: KYMA enquiries are numbered on their own */ $n = (int) scalar("SELECT COALESCE(MAX(CAST(SUBSTRING(ref, 10) AS UNSIGNED)), 0) FROM web_inquiries WHERE ref LIKE ?", [$base . '%']) + 1; return sprintf('%s%04d', $base, $n); }
const WEB_SYSTEMS = ['power', 'automation', 'navigation', 'communication', 'cyber', 'lighting', 'other'];
const WEB_PRIORITIES = ['low', 'normal', 'high', 'vessel_stopped'];
/** Staff who hear about a new website inquiry: works people for tickets, administrators for access requests. */
function web_notify_ids(string $kind): array {
    try { $sql = $kind === 'ticket' ? "SELECT id FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 AND (can_works = 1 OR role = 'admin')" : ($kind === 'contact' ? "SELECT id FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 AND (can_sales = 1 OR role = 'admin')" : "SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"); return array_map(fn($r) => (int) $r['id'], all($sql)); } catch (Throwable $e) { return []; }
}
const WEB_TOPICS = ['general' => 'General', 'newbuild' => 'Newbuild', 'refit' => 'Refit', 'service' => 'Service', 'eswitchboard' => 'E-switchboard', 'bridge' => 'Bridge', 'cyber' => 'Cyber', 'zerogen' => 'ZeroGen', 'partnership' => 'Partnership',
                    'commercial' => 'Commercial', 'marina' => 'Marina', 'press' => 'Press', 'other' => 'Other', 'careers' => 'Careers'];   // R97-050: the last five come from the KYMA site
/** Administrators and management (Owner scope, CEO, General Manager, Managing Director) — who hears about a career application. */
function web_mgmt_ids(): array { try { return array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu LEFT JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (cu.role = 'admin' OR r.scope = 'owner' OR LOWER(TRIM(r.name)) IN ('ceo','general manager','managing director'))"), 'id')); } catch (Throwable $e) { return []; } }
/** SQL that hides career applications from everybody but administrators and management (R97-050). $a = table alias with the dot. */
function web_careers_sql(string $a = 'i.'): string { return function_exists('kym_careers_can') && kym_careers_can() ? '' : " AND NOT ({$a}kind = 'contact' AND {$a}system_kind = 'careers')"; }
/** Public POST ?r=contact — the general contact form (api/contact.php). Sales staff are notified; the e-mail to sales@ still goes out from the website. R93-002
 *  R97-050: $site comes from the key of the request. A KYMA enquiry gets a KYM- reference and its own inbox; a career application is announced to administrators and management only. */
function web_pub_contact(array $b, string $ip, string $site = 'golden'): array {
    $site = kym_site($site); $ks = kym_has(); if ($site === 'kyma' && !$ks) throw new RuntimeException('inbox disabled', 503);
    $f = fn(string $k, int $max) => mb_substr(trim((string) ($b[$k] ?? '')), 0, $max);
    $name = $f('name', 120); $email = $f('email', 190); $msg = $f('message', 4000); $topic = $f('topic', 40); $company = $f('company', 160);
    if ($name === '' || $email === '' || $msg === '') throw new RuntimeException('missing fields', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('bad email', 422);
    $db = [$email, $msg]; $dup = one('SELECT ref FROM web_inquiries WHERE kind = "contact" AND email = ? AND message = ?' . kym_w('site', $site, $db) . ' AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)', $db); if ($dup) return ['ref' => $dup['ref'], 'duplicate' => true];
    $ref = web_ref($site); $topics = WEB_TOPICS;
    $subject = ($topic !== '' ? ($topics[$topic] ?? ucfirst($topic)) . ' — ' : '') . $name . ($company !== '' ? ' (' . $company . ')' : '');
    // R97-060: the Integrated Bridge questionnaire hands over its structured answers and internal class (JSON, kept in `vessels`)
    $spec = ''; if ($topic === 'bridge' && is_string($b['spec'] ?? null) && strlen($b['spec']) <= 120000) { $sj = json_decode($b['spec'], true); if (is_array($sj) && isset($sj['answers'])) { $spec = $b['spec']; $cls = preg_replace('/[^A-C]/', '', (string) ($sj['class'] ?? '')); $vsl = trim((string) ($sj['answers']['vessel'] ?? '')); $loa = (string) ($sj['answers']['loa'] ?? ''); $subject = 'Bridge' . ($cls !== '' ? ' · Class ' . $cls : '') . ' — ' . ($vsl !== '' ? $vsl : $name) . ($loa !== '' ? ' · ' . $loa . ' m' : '') . ($vsl !== '' ? ' · ' . $name : '') . ($company !== '' ? ' (' . $company . ')' : ''); } }
    q('INSERT INTO web_inquiries (ref, kind, name, company, email, phone, system_kind, subject, message, vessels, ip) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$ref, 'contact', $name, $company, $email, $f('phone', 40), $topic, mb_substr($subject, 0, 190), $msg, $spec !== '' ? $spec : null, mb_substr($ip, 0, 64)]);
    $id = (int) db()->lastInsertId(); if ($ks) q('UPDATE web_inquiries SET site = ? WHERE id = ?', [$site, $id]);
    $careers = $topic === 'careers';
    if (function_exists('nf_push')) try { nf_push($careers ? web_mgmt_ids() : web_notify_ids('contact'), 'tk', 'web:' . $id, ($site === 'kyma' ? 'KYMA · ' : '') . t($careers ? 'web.nf_careers' : 'web.nf_contact', ['name' => $name]), $careers ? null : mb_substr($msg, 0, 140), url('web_inbox', ['site' => $site, 'id' => $id]), null, $site === 'kyma' ? 'KYMA website' : 'Website'); } catch (Throwable $e) {}
    if ($site === 'kyma' && !$careers) { try { web_inquiry_match($id); } catch (Throwable $e) { error_log('[web] inquiry match: ' . $e->getMessage()); } }   // a known e-mail address is linked and marked KYMA at once
    return ['ref' => $ref];
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
    $w = ['1=1' . web_careers_sql()]; $b = [];
    if (isset($f['site'])) { $x = []; $w[] = '1=1' . kym_w('i.site', (string)$f['site'], $x); $b = array_merge($b, $x); }   // R97-050
    if (!empty($f['topic'])) { $w[] = 'i.system_kind = ?'; $b[] = (string)$f['topic']; }
    if (!empty($f['kind'])) { $w[] = 'i.kind = ?'; $b[] = $f['kind']; }
    if (!empty($f['status'])) { if ($f['status'] === 'open') $w[] = "i.status IN ('new','in_progress')"; else { $w[] = 'i.status = ?'; $b[] = $f['status']; } }
    return all('SELECT i.*, u.full_name handled_name, t.ticket_no FROM web_inquiries i LEFT JOIN customer_users u ON u.id = i.handled_by LEFT JOIN tickets t ON t.id = i.ticket_id WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(i.status,"new","in_progress","done","spam"), i.created_at DESC LIMIT 500', $b);
}
function web_inquiry(int $id): ?array { $r = one('SELECT i.*, u.full_name handled_name, t.ticket_no FROM web_inquiries i LEFT JOIN customer_users u ON u.id = i.handled_by LEFT JOIN tickets t ON t.id = i.ticket_id WHERE i.id = ?' . web_careers_sql(), [$id]) ?: null; if ($r && !isset($r['site'])) $r['site'] = 'golden'; return $r; }
/** New enquiries: of one site, or of both (the sidebar badge) when $site is null. Career applications count only for those who may see them. */
function web_inbox_count(?string $site = null): int { try { if (!web_inbox_has()) return 0; $b = []; return (int) scalar("SELECT COUNT(*) FROM web_inquiries i WHERE i.status = 'new'" . web_careers_sql() . ($site !== null ? kym_w('i.site', $site, $b) : ''), $b); } catch (Throwable $e) { return 0; } }
function web_site_of(array $i): string { return kym_site($i['site'] ?? 'golden'); }

/* ---------- R97-050: a KYMA enquiry becomes (or is linked to) a contact and an organisation, both marked KYMA ---------- */
/** Organisation role proposed for a partnership enquiry, from the words of the questionnaire. */
function web_guess_role(array $i): string {
    $t = mb_strtolower((string)$i['message'] . ' ' . (string)$i['company']);
    foreach (['yard' => 'shipyard|\byard\b|refit yard|builder|cantiere|ναυπηγ', 'supplier' => 'supplier|manufactur|\boem\b|distribut|vendor|maker|fornitor|προμηθευτ', 'agent' => '\bagent|representative|reseller|dealer|αντιπρόσωπ', 'broker' => 'broker', 'class' => 'classification|class society', 'consultant' => 'consult|designer|naval architect|engineering office|integrator|μελετ', 'management' => 'yacht management|ship management|management company|fleet manag', 'owner' => '\bowner|captain|\bcrew\b|πλοιοκτ'] as $role => $re) if (preg_match('/' . $re . '/iu', $t)) return $role;
    return 'other';
}
/** On arrival: when a contact with the same e-mail exists, link the enquiry to it and mark the contact and its organisation with the brand of the site. Creates nothing. */
function web_inquiry_match(int $id): ?int {
    if (!kym_has()) return null; $i = one('SELECT id, email, site, system_kind FROM web_inquiries WHERE id = ?', [$id]); if (!$i || (string)$i['system_kind'] === 'careers') return null;
    $c = one('SELECT id, company_id FROM contacts WHERE LOWER(email) = ? ORDER BY is_active DESC, id LIMIT 1', [mb_strtolower((string)$i['email'])]); if (!$c) return null;
    q('UPDATE web_inquiries SET contact_id = ? WHERE id = ?', [(int)$c['id'], $id]); kym_brand_add('contact', (int)$c['id'], (string)$i['site']); if ((int)($c['company_id'] ?? 0)) kym_brand_add('company', (int)$c['company_id'], (string)$i['site']);
    return (int)$c['id'];
}
/** Staff: create or link the contact and the organisation of an enquiry (never for a career application). $role = organisation role for a new organisation. Returns the contact id. */
function web_inquiry_to_contact(int $id, string $role = '', bool $makeOrg = true): int {
    if (!kym_has() || !function_exists('ct_manage')) throw new RuntimeException(t('err.noaccess')); $i = web_inquiry($id); if (!$i) throw new RuntimeException(t('err.notfound'));
    if (!is_staff() || !(ct_manage() || web_can_convert())) throw new RuntimeException(t('err.noaccess'));
    if ($i['kind'] !== 'contact' || (string)$i['system_kind'] === 'careers') throw new RuntimeException(t('web.ct_not_careers'));
    $site = web_site_of($i); $email = mb_strtolower(trim((string)$i['email'])); $coName = trim((string)$i['company']); $coId = null; $cuId = null;
    if ($coName !== '') {
        if (function_exists('org_find')) { try { $m = org_find($coName); if ($m) { $coId = (int)($m['company_id'] ?? 0) ?: null; $cuId = (int)($m['customer_id'] ?? 0) ?: null; } } catch (Throwable $e) {} }
        if (!$coId && $makeOrg && function_exists('co_find')) { $kinds = function_exists('co_kinds') ? co_kinds() : []; $coId = co_find($coName, isset($kinds[$role]) ? $role : 'other', true) ?: null; }
    }
    $c = one('SELECT id, company_id FROM contacts WHERE LOWER(email) = ? ORDER BY is_active DESC, id LIMIT 1', [$email]);
    if ($c) { $cid = (int)$c['id']; if (!(int)($c['company_id'] ?? 0) && $coId && function_exists('co_has') && co_has()) q('UPDATE contacts SET company_id = ? WHERE id = ?', [$coId, $cid]); $coId = (int)($c['company_id'] ?? 0) ?: $coId; }
    else {
        $cat = function_exists('ct_cat_guess') ? (ct_cat_guess((string)$i['role'] . ' ' . (string)$i['subject']) ?: 'other') : 'other'; if (function_exists('ct_categories') && !isset(ct_categories()[$cat])) $cat = 'other';
        q('INSERT INTO contacts (customer_id, company, full_name, category, email, phone, is_active) VALUES (?,?,?,?,?,?,1)', [$cuId, $coName !== '' ? mb_substr($coName, 0, 160) : null, mb_substr((string)$i['name'], 0, 160), $cat, $email, (string)$i['phone'] !== '' ? mb_substr((string)$i['phone'], 0, 40) : null]); $cid = (int) db()->lastInsertId();
        if (function_exists('co_has') && co_has()) q('UPDATE contacts SET company_id = ?, channels = ? WHERE id = ?', [$coId, 'email', $cid]);
        if (function_exists('ct_origin_has') && ct_origin_has()) q('UPDATE contacts SET origin = ?, needs_review = 1 WHERE id = ?', [$site === 'kyma' ? 'kyma_website' : 'website', $cid]);
        if ($site === 'kyma') kym_brand_set('contact', $cid, ['kyma']);
    }
    if ($coId && function_exists('cc_has') && cc_has()) { try { q('INSERT IGNORE INTO contact_companies (contact_id, company_id) VALUES (?,?)', [$cid, $coId]); } catch (Throwable $e) {} }
    kym_brand_add('contact', $cid, $site); if ($coId) kym_brand_add('company', (int)$coId, $site);
    q('UPDATE web_inquiries SET contact_id = ? WHERE id = ?', [$cid, $id]);
    return $cid;
}
/** Administrators: delete the enquiries of one site older than $months months (retention), all of them or the career applications only. Returns the number deleted. */
function web_purge(string $site, int $months, bool $careersOnly): int {
    if (!is_cp_admin()) throw new RuntimeException(t('err.noaccess')); $months = max(1, min(240, $months)); $b = [];
    $w = "created_at < DATE_SUB(NOW(), INTERVAL $months MONTH)" . kym_w('site', $site, $b) . ($careersOnly ? " AND kind = 'contact' AND system_kind = 'careers'" : '');
    $ids = array_map('intval', array_column(all("SELECT id FROM web_inquiries WHERE $w AND ticket_id IS NULL LIMIT 5000", $b), 'id')); if (!$ids) return 0;
    foreach (array_chunk($ids, 500) as $ch) { $in = implode(',', $ch); q("DELETE FROM web_inquiries WHERE id IN ($in)"); q('DELETE FROM notifications WHERE ref_key IN (' . implode(',', array_map(fn($x) => "'web:$x'", $ch)) . ')'); }
    error_log('[web] purge ' . kym_site($site) . ' older than ' . $months . ' months' . ($careersOnly ? ' (careers)' : '') . ': ' . count($ids) . ' by user ' . (int)(current_user()['id'] ?? 0));
    return count($ids);
}
/** Turns a website support request into a portal ticket on the chosen vessel (staff). */
/** R94-005: who turns a website inquiry into a ticket — administrators, management, project managers, sales and the works team. */
function web_can_convert(): bool {
    static $v = null; if ($v !== null) return $v; if (!is_staff()) return $v = false;
    $v = is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('pm_is_pm') && pm_is_pm()) || (function_exists('pi_is_sales') && pi_is_sales())
       || (bool) scalar('SELECT can_works FROM customer_users WHERE id = ?', [(int)(current_user()['id'] ?? 0)]);
    return $v;
}
/** Default ticket category per inquiry kind. */
function web_default_category(string $kind): string { return ['ticket' => 'fault', 'contact' => 'question', 'access' => 'other'][$kind] ?? 'other'; }
/**
 * Turn any website inquiry (support request, contact form, portal-access request) into a ticket — R94-005 extends it from support requests only.
 * $vesselId 0 = no vessel yet (the ticket is internal until a vessel is set on it); $category / $priority from the form, defaults per kind.
 */
function web_make_ticket(int $id, int $vesselId, string $category = '', string $priority = ''): int {
    $i = web_inquiry($id); if (!$i) throw new RuntimeException(t('err.notfound'));
    if (!web_can_convert()) throw new RuntimeException(t('err.noaccess'));
    if ($i['ticket_id']) return (int) $i['ticket_id'];
    $v = $vesselId ? one('SELECT id, customer_id FROM vessels WHERE id = ?', [$vesselId]) : null; if ($vesselId && !$v) throw new RuntimeException(t('err.notfound'));
    $u = current_user();
    $cats = function_exists('tk_categories') ? tk_categories() : []; if (!array_key_exists($category, $cats)) $category = web_default_category((string)$i['kind']);
    $pri = array_key_exists($priority, function_exists('tk_priorities') ? tk_priorities() : []) ? $priority : (['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'vessel_stopped' => 'urgent'][(string)$i['priority']] ?? 'normal');
    $kindLabel = t('web.kind_' . $i['kind']); $title = trim((string)$i['subject']) !== '' ? (string)$i['subject'] : $kindLabel . ' — ' . $i['name'] . ($i['company'] ? ' (' . $i['company'] . ')' : '');
    $extra = $i['kind'] === 'access' ? ' · ' . t('web.role') . ': ' . (string)$i['role'] . ($i['vessels'] ? ' · ' . t('web.vessels') . ': ' . $i['vessels'] : '') : ($i['system_kind'] ? ' · ' . t('web.system') . ': ' . $i['system_kind'] : '');
    $desc = $i['message'] . "\n\n— " . t('web.from_site') . ' ' . $i['ref'] . ' (' . $kindLabel . ') · ' . $i['name'] . ($i['company'] ? ' (' . $i['company'] . ')' : '') . ' · ' . $i['email'] . ($i['phone'] !== '' ? ' · ' . $i['phone'] : '') . ((string)($i['location'] ?? '') !== '' ? ' · ' . $i['location'] : '') . $extra;
    q('INSERT INTO tickets (ticket_no, customer_id, vessel_id, title, description, category, priority, created_side, created_by, created_name) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [tk_number(), $v ? $v['customer_id'] : null, $v ? (int) $v['id'] : null, mb_substr($title, 0, 160), $desc, $category, $pri, $i['kind'] === 'ticket' && $v ? 'customer' : 'golden', (int) $u['id'], mb_substr($i['name'], 0, 120)]);
    $tid = (int) db()->lastInsertId();
    if (function_exists('tk_event')) tk_event($tid, 'created', t('web.from_site') . ' ' . $i['ref']);
    if ($v && function_exists('rs_has') && rs_has() && function_exists('rs_cover_ticket')) rs_cover_ticket($tid, (int) $v['id']);
    q('UPDATE web_inquiries SET ticket_id = ?, status = "done", handled_by = ?, handled_at = NOW() WHERE id = ?', [$tid, (int) $u['id'], $id]);
    return $tid;
}

/** Settings › System › Website actions (administrators only) + inbox actions (staff). */
function web_handle_action(string $action): bool {
    if ($action === 'web_inq_delete') {   // R93-003: administrators only; the related notifications go with it
        require_staff(); if (!is_cp_admin()) throw new RuntimeException(t('err.noaccess'));
        $id = (int) $_POST['id']; $i = web_inquiry($id); if (!$i) throw new RuntimeException(t('err.notfound'));
        q('DELETE FROM web_inquiries WHERE id = ?', [$id]); q("DELETE FROM notifications WHERE ref_key = ?", ['web:' . $id]);
        flash('good', t('web.deleted', ['ref' => $i['ref']])); redirect('web_inbox', ['site' => web_site_of($i)]);
    }
    if ($action === 'web_inq_contact') { require_staff(); $id = (int)($_POST['id'] ?? 0); $cid = web_inquiry_to_contact($id, (string)($_POST['role'] ?? ''), !empty($_POST['make_org'])); flash('good', t('web.ct_done')); redirect('contact', ['id' => $cid, '_r' => uniqid()]); }   // R97-050
    if ($action === 'web_purge') { require_cp_admin(); $st = kym_site($_POST['site'] ?? 'golden'); $n = web_purge($st, (int)($_POST['months'] ?? 24), !empty($_POST['careers'])); flash('good', t('web.purged', ['n' => $n])); redirect('web_inbox', ['site' => $st, '_r' => uniqid()]); }
    if ($action === 'kym_auto_save') {   // R97-054: the KYMA mark follows the role
        require_cp_admin(); if (!kym_has()) throw new RuntimeException(t('err.noaccess')); cp_setting_set('kym_auto', !empty($_POST['kym_auto']) ? '1' : '0');
        if (!empty($_POST['run'])) { $r = kym_auto_mark(); cp_setting_set('kym_auto_at', (string) time()); flash('good', t('kym.auto_done', ['o' => $r['co'], 'c' => $r['ct']])); } else flash('good', t('sys.saved'));
        redirect('admin_website', ['_r' => uniqid()], '#kyma'); }
    if ($action === 'web_kyma_key') {   // R97-050: the key the KYMA website sends — shown in full once
        require_cp_admin(); if (!kym_has()) throw new RuntimeException(t('err.noaccess'));
        if (!empty($_POST['rotate'])) { $nk = bin2hex(random_bytes(24)); cp_setting_set('web_site_key_kyma', $nk); $_SESSION['web_key_once_kyma'] = $nk; } if (!empty($_POST['disable'])) cp_setting_set('web_site_key_kyma', '');
        flash('good', t('sys.saved')); redirect('admin_website', ['_r' => uniqid()], '#kyma'); }
    if ($action === 'web_inq_status') { require_staff(); $id = (int) $_POST['id']; $st = in_array($_POST['status'] ?? '', ['new', 'in_progress', 'done', 'spam'], true) ? (string) $_POST['status'] : 'in_progress';
        q('UPDATE web_inquiries SET status = ?, note = ?, handled_by = ?, handled_at = NOW() WHERE id = ?', [$st, mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 400) ?: null, (int) current_user()['id'], $id]);
        $si = web_inquiry($id); flash('good', t('sys.saved')); redirect('web_inbox', ['site' => $si ? web_site_of($si) : 'golden', 'id' => $id, '_r' => uniqid()]); }
    if ($action === 'web_make_ticket') { require_staff(); $tid = web_make_ticket((int) $_POST['id'], (int) ($_POST['vessel_id'] ?? 0), (string) ($_POST['category'] ?? ''), (string) ($_POST['priority'] ?? '')); flash('good', t('web.ticket_created')); redirect('ticket', ['id' => $tid, '_r' => uniqid()]); }
    if ($action === 'web_mail_test') {   // R94-007: administrators send themselves a test message through the active transport
        require_cp_admin(); $u = current_user(); $to = (string)($u['email'] ?? '');
        $ok = function_exists('cp_mail') && cp_mail($to, '[Golden A/S Portal] ' . t('web.mail_test_subject'), str_replace('\\n', "\n", t('web.mail_test_body', ['t' => cp_mail_transport(), 'at' => date('Y-m-d H:i:s'), 'host' => (string) parse_url((string) cfg('app.base_url'), PHP_URL_HOST)])), 'Golden A/S Portal');
        $_SESSION['mail_test'] = ['ok' => $ok, 'to' => $to]; redirect('admin_website', ['_r' => uniqid()]);
    }
    if ($action === 'em_send_toggle') { require_cp_admin(); cp_setting_set('em_graph_send', !empty($_POST['on']) ? '1' : '0'); flash('good', t('sys.saved')); redirect('admin_website', ['_r' => uniqid()]); }   // R97-023
    if ($action !== 'web_settings_save') return false;
    require_cp_admin();
    if (!empty($_POST['web_rotate_key'])) { $nk = bin2hex(random_bytes(24)); cp_setting_set('web_site_key', $nk); $_SESSION['web_key_once'] = $nk; }   // shown in full once, on the next page view only
    if (!empty($_POST['web_disable'])) cp_setting_set('web_site_key', '');
    if (function_exists('cache_forget')) cache_forget('web_counts');
    flash('good', t('sys.saved')); redirect('settings', ['tab' => 'website', '_r' => uniqid()]);
    return true;
}
/** R97-060: the Integrated Bridge questionnaire kept with a contact enquiry (web_inquiries.vessels) — decoded, or null. */
function web_bridge_spec(array $row): ?array { if (($row['kind'] ?? '') !== 'contact' || ($row['system_kind'] ?? '') !== 'bridge' || empty($row['vessels'])) return null; $s = json_decode((string) $row['vessels'], true); return is_array($s) && isset($s['answers']) ? $s : null; }


<?php
/**
 * Web Sales › Newsletters — GAS-CP-R97-039.
 *
 *  An issue is a list of blocks (heading, text, image, button, highlight card, divider) in the Golden A/S template. Its audience is chosen
 *  from the organisations of the Contacts module by role (customer, yard, owner, management, supplier …) plus the people who subscribed on
 *  the website (nl_subs, double opt-in). Everybody who unsubscribed or bounced (nl_optout) is left out, always.
 *  Sending: through Microsoft Graph from the newsletter mailbox, one message per recipient, in small batches from the notification sweep
 *  (never inside a page request of a visitor), with a daily ceiling. Tracking: an open pixel and rewritten links (customer/nl.php), per
 *  recipient; one-click unsubscribe link in every message.
 *  Rights: nl_can() prepares (administrators, management, sales); nl_can_send() queues an issue (administrators, management).
 */
declare(strict_types=1);

const NL_BLOCKS = ['h', 'p', 'img', 'btn', 'card', 'hr'];
const NL_TOPICS = ['newsletter', 'events'];
const NL_MAX_BLOCKS = 40;
const NL_MAX_RCPT = 5000;

function nl_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'nl_issues'"); } catch (Throwable $e) { $h = false; } } return $h; }
function nl_can(): bool { return nl_has() && is_staff() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('pi_is_sales') && pi_is_sales())); }
function nl_can_send(): bool { return nl_has() && is_staff() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())); }
/* R97-050: two senders. The KYMA newsletter has its own mailbox (nl_from_kyma) and never falls back to the Golden A/S one. */
function nl_mailbox(string $site = 'golden'): string {
    if (kym_site($site) === 'kyma') { $m = trim((string) cp_setting('nl_from_kyma', '')); return $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) && kym_has() ? $m : ''; }
    $m = trim((string) cp_setting('nl_from', '')); return $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : (string) cfg('graph.from');
}
function nl_ready(string $site = 'golden'): bool { return function_exists('cp_graph_configured') && cp_graph_configured() && nl_mailbox($site) !== ''; }
function nl_site_of(array $I): string { return kym_site($I['site'] ?? 'golden'); }
/** Identity printed on messages and public pages of a site. The KYMA logo is used when the owner has put assets/kyma-logo-white.png in place; until then the name is printed. */
function nl_brand(string $site): array {
    if (kym_site($site) === 'kyma') { $logo = is_file(dirname(__DIR__, 2) . '/assets/kyma-logo-white.png') || is_file(dirname(__DIR__) . '/../assets/kyma-logo-white.png') ? 'kyma-logo-white.png' : '';
        return ['site' => 'kyma', 'name' => 'KYMA Marine Power', 'short' => 'KYMA', 'home' => KYM_HOME['kyma'], 'host' => 'kyma-marinepower.eu', 'privacy' => KYM_PRIVACY['kyma'], 'logo' => $logo, 'navy' => '#0B2545', 'gold' => '#C9A227', 'footer' => 'KYMA Marine Power']; }
    return ['site' => 'golden', 'name' => 'Golden A/S', 'short' => 'Golden A/S', 'home' => KYM_HOME['golden'], 'host' => 'www.goldenas.com.gr', 'privacy' => KYM_PRIVACY['golden'], 'logo' => 'golden-logo-white.png', 'navy' => '#1F3A5F', 'gold' => '#C9A227', 'footer' => 'Golden Automation Systems &middot; Kiou 5, Keratsini 18756, Greece'];
}
/** " AND <col> = ?" for the site of newsletter data. */
function nl_w(string $site, array &$b, string $col = 'site'): string { return kym_w($col, $site, $b); }
function nl_batch(): int { return max(1, min(50, (int) (cp_setting('nl_batch', '') ?: 15))); }
function nl_daily(): int { return max(10, min(5000, (int) (cp_setting('nl_daily', '') ?: 400))); }
function nl_token(): string { $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; $s = ''; foreach (str_split(random_bytes(24)) as $c) $s .= $a[ord($c) % 62]; return $s; }
function nl_base(): string { $b = trim((string) cfg('app.base_url', '')); if ($b === '' || str_contains($b, '127.0.0.1') || str_contains($b, 'localhost')) { $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'goldensas.com')); $dir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/customer/index.php')), '/'); $b = ($https ? 'https' : 'http') . '://' . $host . $dir; } return rtrim($b, '/'); }
function nl_dir(): string { $d = dirname(__DIR__) . '/storage/nl'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function nl_pill(string $s): string { return pill(['draft' => 'mute', 'sending' => 'gold', 'sent' => 'green', 'cancelled' => 'bad'][$s] ?? 'mute', t('nl.s_' . $s)); }
function nl_groups(): array { $g = []; foreach (['customer', 'yard', 'owner', 'management', 'supplier', 'class', 'consultant', 'broker', 'agent'] as $k) $g[$k] = $k === 'customer' ? t('co.role_customer') : t('co.kind_' . $k); return $g; }
function nl_url_ok(string $u): string { $u = trim($u); if ($u === '' || strlen($u) > 600 || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) return ''; return $u; }

/* ---------------------------------------------------------------- issues */
function nl_issue(int $id): ?array {
    $r = nl_has() && $id ? one('SELECT * FROM nl_issues WHERE id = ?', [$id]) : null; if (!$r) return null;
    $r['blocks'] = json_decode((string)($r['blocks'] ?? ''), true) ?: []; $r['audience'] = json_decode((string)($r['audience'] ?? ''), true) ?: [];
    return $r;
}
function nl_issues(): array {
    return nl_has() ? all("SELECT i.*, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id) n_rcpt, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.status = 'sent') n_sent,
        (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.opened_at IS NOT NULL) n_open, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.clicked_at IS NOT NULL) n_click,
        (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.unsub_at IS NOT NULL) n_unsub FROM nl_issues i ORDER BY i.id DESC LIMIT 200") : [];
}
function nl_create(string $title, string $site = 'golden'): int {
    $title = mb_substr(trim($title), 0, 200); if ($title === '') throw new RuntimeException(t('nl.need_title')); $u = current_user(); $site = kym_has() ? kym_site($site) : 'golden';
    $blocks = [['t' => 'h', 'text' => $title], ['t' => 'p', 'text' => ''], ['t' => 'btn', 'label' => t('nl.read_more'), 'url' => KYM_HOME[$site]]];
    q('INSERT INTO nl_issues (title, subject, lang, blocks, audience, created_by, created_name) VALUES (?,?,?,?,?,?,?)', [$title, $title, 'en', json_encode($blocks, JSON_UNESCAPED_UNICODE), json_encode(['groups' => [], 'topics' => []]), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
    $nid = (int) db()->lastInsertId(); if (kym_has()) q('UPDATE nl_issues SET site = ? WHERE id = ?', [$site, $nid]);   // R97-050: an issue belongs to one website
    return $nid;
}
function nl_editable(array $I): void { if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked')); if (($I['appr_state'] ?? '') === 'pending') throw new RuntimeException(t('nl.ap_locked')); }
/* ---- R97-051: approval by the CEO / General Manager before an issue is sent ---- */
function nl_appr_has(): bool { static $h = null; if ($h === null) { try { $h = nl_has() && (bool) one("SHOW COLUMNS FROM nl_issues LIKE 'appr_state'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Those who approve — and who need no approval themselves: Owner scope, CEO, General Manager. */
function nl_approver(): bool { return is_staff() && ((function_exists('mgmt_can') && mgmt_can()) || (function_exists('cp_role_name') && cp_role_name() === 'ceo')); }
function nl_appr_ids(): array { static $c = null; if ($c === null) { try { $c = array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (r.scope = 'owner' OR LOWER(TRIM(r.name)) IN ('ceo','general manager','managing director'))"), 'id')); } catch (Throwable $e) { $c = []; } } return $c; }
/** The rule is on by default; it rests while no approver account exists, so sending can never be locked out. */
function nl_appr_on(): bool { return nl_appr_has() && (string) cp_setting('nl_approval', '1') === '1' && nl_appr_ids(); }
/** Fingerprint of what the readers get and who gets it: an approval is valid for exactly this. */
function nl_hash(array $I): string { return sha1((string) json_encode([(string)$I['subject'], (string)($I['preheader'] ?? ''), (string)$I['lang'], $I['blocks'], $I['audience'], (string)($I['site'] ?? 'golden')], JSON_UNESCAPED_UNICODE)); }
function nl_appr_state(array $I): string { $s = (string)($I['appr_state'] ?? ''); if ($s === 'approved' && !hash_equals((string)($I['appr_hash'] ?? ''), nl_hash($I))) return ''; return in_array($s, ['pending', 'approved', 'returned'], true) ? $s : ''; }
function nl_appr_ok(array $I): bool { return !nl_appr_on() || nl_approver() || nl_appr_state($I) === 'approved'; }
/** May this account launch the issue? Administrators and management as before; with the rule on, also the author once the issue is approved. */
function nl_may_send(array $I): bool { if (nl_can_send()) return true; $u = current_user(); $me = (int)($u['id'] ?? 0); return nl_can() && nl_appr_on() && nl_appr_state($I) === 'approved' && $me && ($me === (int)($I['created_by'] ?? 0) || $me === (int)($I['appr_req_by'] ?? 0)); }
/** After a save: an approved issue that changed loses its approval. */
function nl_appr_recheck(int $id): bool { if (!nl_appr_has()) return false; $I = nl_issue($id); if (!$I || ($I['appr_state'] ?? '') !== 'approved' || hash_equals((string)$I['appr_hash'], nl_hash($I))) return false; q("UPDATE nl_issues SET appr_state = NULL, appr_hash = NULL WHERE id = ?", [$id]); return true; }
function nl_appr_pill(array $I): string { if (!nl_appr_has() || ($I['status'] ?? '') !== 'draft') return ''; $s = (string)($I['appr_state'] ?? ''); return $s === 'pending' ? ' ' . pill('gold', t('nl.ap_s_pending')) : ($s === 'approved' ? ' ' . pill('green', t('nl.ap_s_approved')) : ($s === 'returned' ? ' ' . pill('bad', t('nl.ap_s_returned')) : '')); }
function nl_appr_request(int $id, string $note): void {
    if (!nl_appr_on()) throw new RuntimeException(t('nl.ap_off')); if (nl_approver()) throw new RuntimeException(t('nl.ap_self')); $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I);
    if (!$I['blocks'] || trim((string)$I['subject']) === '') throw new RuntimeException(t('nl.empty')); $n = count(nl_audience($I['audience'], nl_site_of($I))); if (!$n) throw new RuntimeException(t('nl.no_rcpt')); $u = current_user();
    q("UPDATE nl_issues SET appr_state = 'pending', appr_req_by = ?, appr_req_name = ?, appr_req_at = NOW(), appr_note = ?, appr_by = NULL, appr_name = NULL, appr_at = NULL, appr_reply = NULL, appr_hash = NULL, appr_n = ? WHERE id = ?", [(int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), mb_substr(trim($note), 0, 300) ?: null, $n, $id]);
    try { if (function_exists('nf_push')) nf_push(nl_appr_ids(), 'nl:appr', 'nl:' . $id, t('nl.ap_nf_request', ['t' => (string)$I['title']]), mb_substr(t('nl.ap_nf_request_b', ['n' => $n, 'u' => (string)($u['full_name'] ?? '')]) . (trim($note) !== '' ? ' — ' . trim($note) : ''), 0, 300), url('newsletter', ['id' => $id]) . '#approval', (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
function nl_appr_withdraw(int $id): void { $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $u = current_user(); if (!nl_can_send() && (int)($u['id'] ?? 0) !== (int)($I['appr_req_by'] ?? 0)) throw new RuntimeException(t('err.noaccess')); q("UPDATE nl_issues SET appr_state = NULL WHERE id = ? AND appr_state = 'pending' AND status = 'draft'", [$id]); }
function nl_appr_decide(int $id, bool $ok, string $reply): void {
    if (!nl_approver()) throw new RuntimeException(t('nl.ap_who')); $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); if ($I['status'] !== 'draft' || ($I['appr_state'] ?? '') !== 'pending') throw new RuntimeException(t('nl.ap_not_pending'));
    $reply = mb_substr(trim($reply), 0, 500); if (!$ok && $reply === '') throw new RuntimeException(t('nl.ap_reply_req')); $u = current_user(); $n = count(nl_audience($I['audience'], nl_site_of($I)));
    q("UPDATE nl_issues SET appr_state = ?, appr_by = ?, appr_name = ?, appr_at = NOW(), appr_reply = ?, appr_hash = ?, appr_n = ? WHERE id = ? AND appr_state = 'pending'", [$ok ? 'approved' : 'returned', (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $reply ?: null, $ok ? nl_hash($I) : null, $n, $id]);
    try { $to = array_values(array_unique(array_filter([(int)($I['appr_req_by'] ?? 0), (int)($I['created_by'] ?? 0)]))); if (function_exists('nf_push') && $to) nf_push($to, 'nl:appr', 'nl:' . $id, t($ok ? 'nl.ap_nf_ok' : 'nl.ap_nf_back', ['t' => (string)$I['title']]), mb_substr((string)($u['full_name'] ?? '') . ($reply !== '' ? ' — ' . $reply : ''), 0, 300), url('newsletter', ['id' => $id]) . '#approval', (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
/** Save head, blocks and audience of a draft from the editor form. */
function nl_save_from_post(int $id): void {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I);
    $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $title = $s('title', 200) ?: $I['title']; $subject = preg_replace('/[\r\n]+/', ' ', $s('subject', 200)) ?: $title; $lang = in_array($_POST['lang'] ?? '', ['en', 'el', 'it'], true) ? (string)$_POST['lang'] : 'en';
    $blocks = [];
    foreach ((array)($_POST['b'] ?? []) as $i => $b) {
        if (count($blocks) >= NL_MAX_BLOCKS || !is_array($b)) break; $t = (string)($b['t'] ?? ''); if (!in_array($t, NL_BLOCKS, true) || !empty($b['del'])) continue;
        $x = ['t' => $t]; foreach (['text' => 6000, 'title' => 200, 'label' => 80] as $k => $max) if (isset($b[$k])) $x[$k] = mb_substr(trim(str_replace("\r", '', (string)$b[$k])), 0, $max);
        if (isset($b['url'])) $x['url'] = nl_url_ok((string)$b['url']);
        $x['img'] = preg_match('/^[A-Za-z0-9]{24}$/', (string)($b['img'] ?? '')) ? (string)$b['img'] : '';
        if (!empty($_FILES['bf']['tmp_name'][$i]) && is_uploaded_file($_FILES['bf']['tmp_name'][$i])) $x['img'] = nl_image_store($id, $_FILES['bf']['tmp_name'][$i], (int)$_FILES['bf']['size'][$i]);
        $x['pos'] = (int)($b['pos'] ?? ($i * 10)); $blocks[] = $x;
    }
    usort($blocks, fn($a, $b) => $a['pos'] <=> $b['pos']); foreach ($blocks as &$x) unset($x['pos']); unset($x);
    $add = (string)($_POST['add'] ?? ''); if (in_array($add, NL_BLOCKS, true) && count($blocks) < NL_MAX_BLOCKS) $blocks[] = ['t' => $add];
    $aud = ['lists' => array_values(array_filter(array_map('intval', (array)($_POST['lists'] ?? [])))), 'groups' => array_values(array_intersect(array_keys(nl_groups()), (array)($_POST['groups'] ?? []))), 'topics' => array_values(array_intersect(NL_TOPICS, (array)($_POST['topics'] ?? []))),
            'country' => $s('country', 80), 'extra' => $s('extra', 4000)];
    q('UPDATE nl_issues SET title = ?, subject = ?, preheader = ?, lang = ?, blocks = ?, audience = ? WHERE id = ?', [$title, $subject, $s('preheader', 200) ?: null, $lang, json_encode($blocks, JSON_UNESCAPED_UNICODE), json_encode($aud, JSON_UNESCAPED_UNICODE), $id]);
}
/** An uploaded picture: JPEG / PNG / GIF up to 2 MB, checked by its content, stored outside the web root, served by token. */
function nl_image_store(int $issueId, string $tmp, int $size): string {
    if ($size <= 0 || $size > 2 * 1024 * 1024) throw new RuntimeException(t('nl.img_big'));
    $info = @getimagesize($tmp); $ext = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_GIF => ['gif', 'image/gif']][$info[2] ?? 0] ?? null; if (!$ext) throw new RuntimeException(t('nl.img_type'));
    $tok = nl_token(); $file = $tok . '.' . $ext[0]; if (!@move_uploaded_file($tmp, nl_dir() . '/' . $file)) throw new RuntimeException(t('nl.img_fail'));
    q('INSERT INTO nl_images (issue_id, token, mime, file) VALUES (?,?,?,?)', [$issueId, $tok, $ext[1], $file]); return $tok;
}
function nl_delete(int $id): void { $I = nl_issue($id); if (!$I) return; if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked')); foreach (all('SELECT file FROM nl_images WHERE issue_id = ?', [$id]) as $f) @unlink(nl_dir() . '/' . basename((string)$f['file'])); q('DELETE FROM nl_images WHERE issue_id = ?', [$id]); q('DELETE FROM nl_issues WHERE id = ?', [$id]); }
function nl_copy(int $id): int { $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $u = current_user(); q('INSERT INTO nl_issues (title, subject, preheader, lang, blocks, audience, created_by, created_name) VALUES (?,?,?,?,?,?,?,?)', [mb_substr($I['title'] . ' (copy)', 0, 200), $I['subject'], $I['preheader'], $I['lang'], json_encode($I['blocks'], JSON_UNESCAPED_UNICODE), json_encode($I['audience'], JSON_UNESCAPED_UNICODE), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]); $nid = (int) db()->lastInsertId(); if (kym_has()) q('UPDATE nl_issues SET site = ? WHERE id = ?', [nl_site_of($I), $nid]); return $nid; }   // R97-050: a copy stays with its website

/* ---------------------------------------------------------------- audience */
/** The recipients an audience resolves to: email => [email, name, contact_id, company_id, company, grp]. Opt-outs removed, one row per address. */
function nl_audience(array $aud, string $site = 'golden'): array {
    /* R97-050: the audience of an issue is of its website only — its own opt-out list, its own subscribers, and contacts that carry its brand.
       A KYMA issue never reaches a Golden-only contact and the reverse; an address typed under "extra" is the only exception, because it is typed on purpose. */
    $site = kym_site($site); $ks = kym_has(); $brand = $ks ? ' AND FIND_IN_SET(' . db()->quote($site) . ', ct.brands)' : '';
    $out = []; $opt = []; $ob = []; foreach (all('SELECT LOWER(email) e FROM nl_optout WHERE 1=1' . nl_w($site, $ob), $ob) as $r) $opt[$r['e']] = 1;
    $put = function (string $email, string $name, ?int $cid, ?int $coid, string $co, string $grp) use (&$out, $opt) { $e = mb_strtolower(trim($email)); if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($opt[$e]) || isset($out[$e]) || count($out) >= NL_MAX_RCPT) return; $out[$e] = ['email' => $e, 'name' => mb_substr(trim($name), 0, 160), 'contact_id' => $cid, 'company_id' => $coid, 'company' => mb_substr($co, 0, 160), 'grp' => $grp]; };
    foreach (array_map('intval', (array)($aud['lists'] ?? [])) as $lid) { $L = nl_list($lid); if ($L) foreach (nl_list_members($L, $ks ? $site : null) as $r) $put($r['email'], $r['name'], $r['contact_id'], $r['company_id'], $r['company'], $r['grp']); }   // R97-040 saved lists
    $groups = array_values(array_intersect(array_keys(nl_groups()), (array)($aud['groups'] ?? []))); $country = trim((string)($aud['country'] ?? ''));
    if ($groups && function_exists('co_has') && co_has()) {
        $roles = co_roles_has(); $cc = function_exists('cc_has') && cc_has();
        foreach ($groups as $g) {
            $w = $roles ? "(FIND_IN_SET(?, co.roles) OR co.kind = ?" . ($g === 'supplier' ? ' OR co.is_supplier = 1' : '') . ($g === 'customer' ? ' OR co.customer_id IS NOT NULL' : '') . ')' : '(co.kind = ? OR ? = \'\')'; $a = [$g, $g];
            if ($country !== '') { $w .= ' AND co.country = ?'; $a[] = $country; }
            $sql = "SELECT ct.id, ct.full_name, ct.email, co.id AS coid, co.name AS coname FROM contacts ct JOIN companies co ON co.id = ct.company_id WHERE ct.is_active = 1 AND co.is_active = 1 AND ct.email IS NOT NULL AND ct.email <> ''$brand AND $w";
            foreach (all($sql . ' ORDER BY co.name, ct.full_name', $a) as $r) $put((string)$r['email'], (string)$r['full_name'], (int)$r['id'], (int)$r['coid'], (string)$r['coname'], $g);
            if ($cc) foreach (all("SELECT ct.id, ct.full_name, ct.email, co.id AS coid, co.name AS coname FROM contact_companies x JOIN contacts ct ON ct.id = x.contact_id JOIN companies co ON co.id = x.company_id WHERE ct.is_active = 1 AND co.is_active = 1 AND ct.email IS NOT NULL AND ct.email <> ''$brand AND $w ORDER BY co.name, ct.full_name", $a) as $r) $put((string)$r['email'], (string)$r['full_name'], (int)$r['id'], (int)$r['coid'], (string)$r['coname'], $g);
        }
    }
    foreach (array_intersect(NL_TOPICS, (array)($aud['topics'] ?? [])) as $tp) { $sb = [$tp]; foreach (all("SELECT email, name, company FROM nl_subs WHERE status = 'confirmed' AND FIND_IN_SET(?, topics)" . nl_w($site, $sb) . ' ORDER BY id', $sb) as $r) $put((string)$r['email'], (string)$r['name'], null, null, (string)$r['company'], 'web'); }
    foreach (preg_split('/[\s,;]+/', (string)($aud['extra'] ?? '')) ?: [] as $e) $put($e, '', null, null, '', 'extra');
    return $out;
}
function nl_audience_counts(array $rcpt): array { $c = []; foreach ($rcpt as $r) $c[$r['grp']] = ($c[$r['grp']] ?? 0) + 1; return $c; }
function nl_group_label(string $g): string { if (str_starts_with($g, 'c:')) return function_exists('ct_categories') ? (ct_categories()[substr($g, 2)] ?? substr($g, 2)) : substr($g, 2); return $g === 'web' ? t('nl.g_web') : ($g === 'extra' ? t('nl.g_extra') : (nl_groups()[$g] ?? $g)); }

/* ---------------------------------------------------------------- template */
/** Plain text of a block → safe HTML: escaped, paragraphs, **bold**, and http(s) addresses turned into links through $link(url). */
function nl_text_html(string $s, callable $link): string {
    $out = [];
    foreach (preg_split('/\n{2,}/', trim($s)) ?: [] as $p) { if (trim($p) === '') continue;
        $h = e(trim($p)); $h = preg_replace('/\*\*(.+?)\*\*/s', '<b>$1</b>', $h);
        $h = preg_replace_callback('#https?://[^\s<]+[^\s<.,;:!?)]#i', function ($m) use ($link) { $u = nl_url_ok(html_entity_decode($m[0], ENT_QUOTES)); return $u === '' ? $m[0] : '<a href="' . e($link($u)) . '" style="color:#1F3A5F;">' . $m[0] . '</a>'; }, $h);
        $out[] = '<p style="margin:0 0 14px;font:15px/1.6 Aptos,Calibri,Arial,sans-serif;color:#243140;text-align:justify;">' . nl2br($h, false) . '</p>'; }
    return implode('', $out);
}
/** The whole message as HTML. $link(url) returns the address to print for a link (tracked or plain); %%NAME%%, %%OPEN%%, %%UNSUB%% stay as markers. */
function nl_render(array $I, callable $link, bool $preview = false): string {
    $base = nl_base(); $img = fn(string $tok) => $base . '/nl.php?i=' . $tok; $T = fn(string $k, array $p = []) => function_exists('t_in') ? t_in($k, (string)$I['lang'], $p) : t($k, $p);
    $BR = nl_brand(nl_site_of($I)); $navy = $BR['navy']; $gold = $BR['gold']; $body = '';
    $btn = fn(string $label, string $url) => $url === '' || $label === '' ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:6px auto 18px;"><tr><td style="background:' . $gold . ';border-radius:6px;"><a href="' . e($link($url)) . '" style="display:inline-block;padding:11px 26px;font:bold 15px Aptos,Calibri,Arial,sans-serif;color:' . $navy . ';text-decoration:none;">' . e($label) . '</a></td></tr></table>';
    foreach ($I['blocks'] as $b) {
        $t = $b['t'] ?? ''; $text = (string)($b['text'] ?? '');
        if ($t === 'h' && $text !== '') $body .= '<h2 style="margin:8px 0 12px;font:bold 21px/1.3 Aptos,Calibri,Arial,sans-serif;color:' . $navy . ';text-align:center;">' . e($text) . '</h2>';
        elseif ($t === 'p' && $text !== '') $body .= nl_text_html($text, $link);
        elseif ($t === 'img' && !empty($b['img'])) { $im = '<img src="' . e($img($b['img'])) . '" alt="' . e((string)($b['title'] ?? '')) . '" width="552" style="display:block;width:100%;max-width:552px;height:auto;border:0;border-radius:6px;margin:0 auto 16px;">'; $u = (string)($b['url'] ?? ''); $body .= $u !== '' ? '<a href="' . e($link($u)) . '">' . $im . '</a>' : $im; }
        elseif ($t === 'btn') $body .= $btn((string)($b['label'] ?? ''), (string)($b['url'] ?? ''));
        elseif ($t === 'hr') $body .= '<div style="border-top:2px solid ' . $gold . ';margin:10px 0 20px;font-size:0;line-height:0;">&nbsp;</div>';
        elseif ($t === 'card' && ($text !== '' || !empty($b['title']))) $body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;border:1px solid #D9DEE5;border-top:3px solid ' . $gold . ';border-radius:6px;"><tr><td style="padding:16px 18px;">' . (!empty($b['img']) ? '<img src="' . e($img($b['img'])) . '" alt="" width="516" style="display:block;width:100%;max-width:516px;height:auto;border:0;border-radius:4px;margin:0 auto 12px;">' : '') . (!empty($b['title']) ? '<h3 style="margin:0 0 8px;font:bold 17px Aptos,Calibri,Arial,sans-serif;color:' . $navy . ';">' . e((string)$b['title']) . '</h3>' : '') . nl_text_html($text, $link) . $btn((string)($b['label'] ?? ''), (string)($b['url'] ?? '')) . '</td></tr></table>';
    }
    $pre = trim((string)($I['preheader'] ?? ''));
    return '<!doctype html><html lang="' . e((string)$I['lang']) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . e((string)$I['subject']) . '</title></head>'
         . '<body style="margin:0;padding:0;background:#EEF1F5;">' . ($pre !== '' ? '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . e($pre) . '</div>' : '')
         . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F5;"><tr><td align="center" style="padding:18px 8px;">'
         . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#FFFFFF;border-radius:8px;overflow:hidden;">'
         . '<tr><td style="background:' . $navy . ';padding:18px 24px;border-bottom:3px solid ' . $gold . ';">' . ($BR['logo'] !== '' ? '<img src="' . e($base . '/../assets/' . $BR['logo']) . '" alt="' . e($BR['name']) . '" height="38" style="display:block;height:38px;width:auto;border:0;">' : '<span style="font:bold 22px/38px Aptos,Calibri,Arial,sans-serif;letter-spacing:3px;color:#FFFFFF;">KYMA</span> <span style="font:12px/38px Aptos,Calibri,Arial,sans-serif;letter-spacing:2px;color:' . $gold . ';">MARINE POWER</span>') . '</td></tr>'
         . '<tr><td style="padding:26px 24px 10px;">' . ($preview ? '' : '<p style="margin:0 0 14px;font:15px/1.6 Aptos,Calibri,Arial,sans-serif;color:#243140;">%%NAME%%</p>') . $body . '</td></tr>'
         . '<tr><td style="background:#F6F7F9;border-top:1px solid #E3E7EC;padding:16px 24px;font:12px/1.5 Aptos,Calibri,Arial,sans-serif;color:#6A7179;text-align:center;">'
         . $BR['footer'] . ' &middot; <a href="' . e($BR['home']) . '" style="color:#6A7179;">' . e($BR['host']) . '</a><br>'
         . e($T($BR['site'] === 'kyma' ? 'nl.f_why_kyma' : 'nl.f_why')) . ' <a href="%%UNSUB%%" style="color:#1F3A5F;">' . e($T('nl.f_unsub')) . '</a> &middot; <a href="' . e($BR['privacy']) . '" style="color:#6A7179;">' . e($T('nl.f_privacy')) . '</a></td></tr>'
         . '</table></td></tr></table>' . ($preview ? '' : '<img src="%%OPEN%%" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">') . '</body></html>';
}
function nl_preview_html(array $I): string { return strtr(nl_render($I, fn($u) => $u, true), ['%%UNSUB%%' => '#']); }
function nl_greeting(array $I, string $name): string { $n = trim($name); $k = $n !== '' ? 'nl.hello_name' : 'nl.hello'; return function_exists('t_in') ? t_in($k, (string)$I['lang'], ['n' => $n]) : t($k, ['n' => $n]); }

/* ---------------------------------------------------------------- sending */
/** One message through Microsoft 365, from the mailbox of the site. Returns [ok, short error].
 *  R97-050: with $unsub (the personal unsubscribe address) the message is sent in MIME form so that it can carry List-Unsubscribe and
 *  List-Unsubscribe-Post — the JSON form of sendMail only accepts "x-" headers. In MIME form Microsoft 365 keeps a copy in Sent Items.
 *  If the MIME form is refused, the same message goes out the plain way (visible link only) and the refusal is remembered for an hour. */
function nl_graph_send(string $to, string $subject, string $html, string $site = 'golden', ?string $unsub = null): array {
    $box = nl_mailbox($site); if ($box === '') return [false, 'no mailbox'];
    if ($unsub !== null && nl_list_headers($site) && (int) cp_setting('nl_mime_bad_until', '0') < time()) {
        [$ok, $err] = nl_graph_send_mime($box, nl_brand($site)['name'], $to, $subject, $html, $unsub); if ($ok) return [true, ''];
        if (!str_starts_with($err, '429') && !str_starts_with($err, '0 ')) { cp_setting_set('nl_mime_bad_until', (string)(time() + 3600)); cp_setting_set('nl_mime_last_error', gmdate('c') . ' ' . $err); error_log('[nl] MIME send refused, falling back: ' . $err); } else return [false, $err];
    }
    $msg = ['subject' => preg_replace('/[\r\n]/', ' ', $subject), 'body' => ['contentType' => 'HTML', 'content' => $html], 'toRecipients' => [['emailAddress' => ['address' => $to]]]];
    [$code, $j] = cp_graph_call('POST', '/users/' . rawurlencode($box) . '/sendMail', ['message' => $msg, 'saveToSentItems' => false], 30);
    return [$code === 202, mb_substr(trim($code . ' ' . (string)($j['error']['code'] ?? '') . ' ' . (string)($j['error']['message'] ?? '')), 0, 180)];
}
/** List-Unsubscribe headers: always for KYMA (specification §7), optional for Golden A/S (setting nl_list_headers). */
function nl_list_headers(string $site): bool { return kym_site($site) === 'kyma' ? (string) cp_setting('nl_list_headers_kyma', '1') === '1' : (string) cp_setting('nl_list_headers', '0') === '1'; }
function nl_graph_send_mime(string $box, string $fromName, string $to, string $subject, string $html, string $unsub): array {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL) || !preg_match('#^https://[^\s<>]+$#', $unsub)) return [false, '400 bad address'];
    $enc = fn(string $x) => '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]/', ' ', $x)) . '?=';
    $mime = implode("\r\n", ['From: ' . $enc($fromName) . ' <' . $box . '>', 'To: <' . $to . '>', 'Subject: ' . $enc($subject), 'MIME-Version: 1.0', 'List-Unsubscribe: <' . $unsub . '>', 'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
        'Content-Type: text/html; charset=UTF-8', 'Content-Transfer-Encoding: base64', '', chunk_split(base64_encode($html), 76, "\r\n")]);
    $tok = function_exists('cp_graph_token') ? cp_graph_token() : null; if (!$tok) return [false, '0 token'];
    $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($box) . '/sendMail');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: text/plain'], CURLOPT_POSTFIELDS => base64_encode($mime)]);
    $res = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365 mail'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $j = is_string($res) && $res !== '' ? json_decode($res, true) : null;
    return [$code === 202, mb_substr(trim($code . ' ' . (string)($j['error']['code'] ?? '') . ' ' . (string)($j['error']['message'] ?? '')), 0, 180)];
}
/** One test message of a draft to the signed-in person — links are not tracked, nothing is recorded. */
function nl_send_test(int $id): string {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $site = nl_site_of($I); if (!nl_ready($site)) throw new RuntimeException(t($site === 'kyma' ? 'nl.no_mailer_kyma' : 'nl.no_mailer'));
    $u = current_user(); $to = (string)($u['email'] ?? ''); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('nl.no_mailer'));
    $html = strtr(nl_render($I, fn($x) => $x), ['%%NAME%%' => e(nl_greeting($I, (string)($u['full_name'] ?? ''))), '%%OPEN%%' => nl_base() . '/nl.php?o=test', '%%UNSUB%%' => KYM_HOME[$site]]);
    [$ok, $err] = nl_graph_send($to, '[TEST] ' . $I['subject'], $html, $site, nl_list_headers($site) ? nl_base() . '/nl.php?u=test' : null);   // the test uses the same way of sending as the real issue if (!$ok) throw new RuntimeException(t('nl.send_fail', ['e' => $err])); return $to;
}
/** Queue a draft: resolve the audience, register the links, create one row per recipient. From here the issue is locked. */
function nl_queue(int $id): int {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); if (!nl_may_send($I)) throw new RuntimeException(t(nl_can() && nl_appr_on() ? 'nl.ap_needed' : 'err.noaccess')); if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked'));
    if (!nl_appr_ok($I)) throw new RuntimeException(t('nl.ap_needed'));   /* R97-051 */
    $site = nl_site_of($I); if (!nl_ready($site)) throw new RuntimeException(t($site === 'kyma' ? 'nl.no_mailer_kyma' : 'nl.no_mailer')); if (!$I['blocks']) throw new RuntimeException(t('nl.empty'));
    $rc = nl_audience($I['audience'], $site); if (!$rc) throw new RuntimeException(t('nl.no_rcpt')); $u = current_user();
    db()->beginTransaction();
    try {
        q('DELETE FROM nl_links WHERE issue_id = ?', [$id]); $n = 0; $seen = [];
        nl_render($I, function (string $url) use (&$n, &$seen, $id) { if (!isset($seen[$url])) { $seen[$url] = ++$n; q('INSERT INTO nl_links (issue_id, n, url) VALUES (?,?,?)', [$id, $n, $url]); } return $url; });
        $ins = db()->prepare('INSERT IGNORE INTO nl_sends (issue_id, email, name, contact_id, company_id, company, grp, token) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($rc as $r) $ins->execute([$id, $r['email'], $r['name'] ?: null, $r['contact_id'], $r['company_id'], $r['company'] ?: null, $r['grp'], nl_token()]);
        q("UPDATE nl_issues SET status = 'sending', n_total = ?, queued_by = ?, queued_name = ?, queued_at = NOW() WHERE id = ?", [count($rc), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $id]);
        db()->commit();
    } catch (Throwable $e) { db()->rollBack(); throw $e; }
    return count($rc);
}
function nl_cancel(int $id): int { if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $c = q("UPDATE nl_sends SET status = 'skipped' WHERE issue_id = ? AND status = 'queued'", [$id])->rowCount(); q("UPDATE nl_issues SET status = IF((SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = nl_issues.id AND s.status = 'sent') > 0, 'sent', 'cancelled'), sent_at = NOW() WHERE id = ? AND status = 'sending'", [$id]); return $c; }
/** Send the next batch of the oldest issue in progress. Called by the notification sweep and by "Send next batch". Returns the number sent. */
function nl_send_batch(?int $max = null): int {
    if (!nl_has()) return 0; $I = null;
    foreach (all("SELECT id FROM nl_issues WHERE status = 'sending' ORDER BY queued_at, id LIMIT 10") as $cand) { $c = nl_issue((int)$cand['id']); if ($c && nl_ready(nl_site_of($c))) { $I = $c; break; } }   // R97-050: an issue of a site whose mailbox is not set does not hold the other site back
    if (!$I) return 0; $id = (int)$I['id']; $site = nl_site_of($I);
    $today = (int) scalar("SELECT COUNT(*) FROM nl_sends WHERE status = 'sent' AND sent_at >= CURDATE()"); $room = min($max ?? nl_batch(), nl_daily() - $today); if ($room <= 0) return 0;
    $links = []; foreach (all('SELECT n, url FROM nl_links WHERE issue_id = ?', [$id]) as $l) $links[(string)$l['url']] = (int)$l['n'];
    $tpl = nl_render($I, fn(string $u) => isset($links[$u]) ? '%%C' . $links[$u] . '%%' : $u); $base = nl_base(); $sent = 0;
    foreach (all("SELECT * FROM nl_sends WHERE issue_id = ? AND status = 'queued' ORDER BY id LIMIT " . (int)$room, [$id]) as $s) {
        if (!q("UPDATE nl_sends SET status = 'sending' WHERE id = ? AND status = 'queued'", [(int)$s['id']])->rowCount()) continue;   // claimed: a second sweep does not send the same row
        $ob = [$s['email']]; if (scalar('SELECT 1 FROM nl_optout WHERE email = ?' . nl_w($site, $ob), $ob)) { q("UPDATE nl_sends SET status = 'skipped', error = 'opt-out' WHERE id = ?", [(int)$s['id']]); continue; }
        $map = ['%%NAME%%' => e(nl_greeting($I, (string)$s['name'])), '%%OPEN%%' => $base . '/nl.php?o=' . $s['token'], '%%UNSUB%%' => $base . '/nl.php?u=' . $s['token']]; foreach ($links as $n) $map['%%C' . $n . '%%'] = $base . '/nl.php?c=' . $s['token'] . '&amp;l=' . $n;
        try { [$ok, $err] = nl_graph_send((string)$s['email'], (string)$I['subject'], strtr($tpl, $map), $site, $map['%%UNSUB%%']); } catch (Throwable $e) { $ok = false; $err = mb_substr($e->getMessage(), 0, 180); }
        if ($ok) { q("UPDATE nl_sends SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?", [(int)$s['id']]); $sent++; } else { q("UPDATE nl_sends SET status = 'failed', error = ? WHERE id = ?", [$err, (int)$s['id']]); if (str_starts_with($err, '429') || str_starts_with($err, '0 ')) break; }
    }
    if (!scalar("SELECT 1 FROM nl_sends WHERE issue_id = ? AND status IN ('queued','sending') LIMIT 1", [$id])) q("UPDATE nl_issues SET status = 'sent', sent_at = NOW() WHERE id = ?", [$id]);
    return $sent;
}
function nl_sweep(): void { try { nl_send_batch(); } catch (Throwable $e) { error_log('[nl] ' . $e->getMessage()); } }
function nl_retry_failed(int $id): int { if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $c = q("UPDATE nl_sends SET status = 'queued', error = NULL WHERE issue_id = ? AND status = 'failed'", [$id])->rowCount(); if ($c) q("UPDATE nl_issues SET status = 'sending' WHERE id = ?", [$id]); return $c; }

/* ---------------------------------------------------------------- statistics */
function nl_stats(int $id): array {
    $t = one("SELECT COUNT(*) n, SUM(status = 'sent') sent, SUM(status = 'queued' OR status = 'sending') queued, SUM(status = 'failed') failed, SUM(status = 'skipped') skipped, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked, SUM(unsub_at IS NOT NULL) unsub, COALESCE(SUM(clicks),0) clicks FROM nl_sends WHERE issue_id = ?", [$id]) ?: [];
    $ks = kym_has(); $st = $ks ? db()->quote(kym_site((string) scalar('SELECT site FROM nl_issues WHERE id = ?', [$id]))) : ''; $oj = $ks ? " AND o.site = $st" : '';
    $bounce = (int) scalar("SELECT COUNT(*) FROM nl_sends s JOIN nl_optout o ON o.email = s.email AND o.reason = 'bounce'$oj WHERE s.issue_id = ?", [$id]);
    return ['tot' => array_map('intval', $t) + ['bounce' => $bounce],
            'groups' => all("SELECT grp, COUNT(*) n, SUM(status = 'sent') sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked, SUM(unsub_at IS NOT NULL) unsub FROM nl_sends WHERE issue_id = ? GROUP BY grp ORDER BY n DESC", [$id]),
            'links' => all('SELECT l.n, l.url, COUNT(c.id) clicks, COUNT(DISTINCT c.send_id) people FROM nl_links l LEFT JOIN nl_clicks c ON c.link_id = l.id WHERE l.issue_id = ? GROUP BY l.id ORDER BY clicks DESC, l.n', [$id]),
            'orgs' => all("SELECT COALESCE(company, '—') company, company_id, COUNT(*) n, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked FROM nl_sends WHERE issue_id = ? AND status = 'sent' GROUP BY company, company_id HAVING opened > 0 OR clicked > 0 ORDER BY clicked DESC, opened DESC, company LIMIT 60", [$id]),
            'rows' => all("SELECT s.*, (SELECT o.reason FROM nl_optout o WHERE o.email = s.email$oj LIMIT 1) opt FROM nl_sends s WHERE s.issue_id = ? ORDER BY (s.clicked_at IS NULL), (s.opened_at IS NULL), s.company, s.name, s.email LIMIT 2000", [$id])];
}
function nl_pct(int $a, int $b): string { return $b > 0 ? number_format(100 * $a / $b, 1, ',', '') . ' %' : '—'; }
/** Mark an address as bounced (administrators / management read the bounce in the mailbox): it never receives a newsletter again. */
function nl_mark(string $email, string $reason, ?int $issueId = null, string $site = 'golden'): void {
    $e = mb_strtolower(trim($email)); if (!filter_var($e, FILTER_VALIDATE_EMAIL) || !in_array($reason, ['unsub', 'bounce', 'manual'], true)) return; $site = kym_site($site); $ks = kym_has();
    if ($ks) q('INSERT INTO nl_optout (site, email, reason, issue_id, at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE reason = IF(reason = \'bounce\', reason, VALUES(reason))', [$site, $e, $reason, $issueId]);
    else q('INSERT INTO nl_optout (email, reason, issue_id, at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE reason = IF(reason = \'bounce\', reason, VALUES(reason))', [$e, $reason, $issueId]);
    $sb = [$e]; q("UPDATE nl_subs SET status = 'unsubscribed', unsub_at = NOW() WHERE email = ? AND status <> 'unsubscribed'" . nl_w($site, $sb), $sb);
    // R97-050: leaving the KYMA list touches the KYMA list only — the Golden A/S ticks on the contact stay; leaving Golden A/S (or a bounce, which is the address itself) clears them as before
    if ($site === 'kyma' && $reason !== 'bounce') return;
    try { foreach (all('SELECT id, subscriptions FROM contacts WHERE LOWER(email) = ?', [$e]) as $c) { $set = array_diff(array_filter(explode(',', (string)$c['subscriptions'])), ['newsletter', 'campaigns', 'events']); q('UPDATE contacts SET subscriptions = ? WHERE id = ?', [$set ? implode(',', $set) : null, (int)$c['id']]); } } catch (Throwable $x) {}
}
function nl_optouts(?string $site = null): array { if (!nl_has()) return []; $b = []; return all('SELECT * FROM nl_optout WHERE 1=1' . ($site !== null ? nl_w($site, $b) : '') . ' ORDER BY at DESC LIMIT 500', $b); }
function nl_subs(?string $site = null): array { if (!nl_has()) return []; $b = []; return all('SELECT * FROM nl_subs WHERE 1=1' . ($site !== null ? nl_w($site, $b) : '') . ' ORDER BY id DESC LIMIT 500', $b); }
/** The website a public token belongs to: a send → its issue; a subscriber → its list. 'golden' when unknown. */
function nl_token_site(string $tok): string {
    if (!kym_has() || !preg_match('/^[A-Za-z0-9]{24}$/', $tok)) return 'golden';
    try { $s = scalar('SELECT i.site FROM nl_sends s JOIN nl_issues i ON i.id = s.issue_id WHERE s.token = ?', [$tok]); if ($s) return kym_site($s); $s = scalar('SELECT site FROM nl_subs WHERE token = ?', [$tok]); return kym_site($s ?: 'golden'); } catch (Throwable $e) { return 'golden'; }
}

/* ---------------------------------------------------------------- public side (customer/nl.php, web_feed.php) */
function nl_pub_open(string $tok): void { q('UPDATE nl_sends SET opens = opens + 1, opened_at = COALESCE(opened_at, NOW()) WHERE token = ?', [$tok]); }
function nl_pub_click(string $tok, int $n): string {
    $s = one('SELECT id, issue_id FROM nl_sends WHERE token = ?', [$tok]); if (!$s) return '';
    $l = one('SELECT id, url FROM nl_links WHERE issue_id = ? AND n = ?', [(int)$s['issue_id'], $n]); if (!$l) return '';
    q('UPDATE nl_sends SET clicks = clicks + 1, clicked_at = COALESCE(clicked_at, NOW()), opened_at = COALESCE(opened_at, NOW()), opens = GREATEST(opens, 1) WHERE id = ?', [(int)$s['id']]);
    if ((int) scalar('SELECT COUNT(*) FROM nl_clicks WHERE send_id = ?', [(int)$s['id']]) < 200) q('INSERT INTO nl_clicks (send_id, link_id, at) VALUES (?,?,NOW())', [(int)$s['id'], (int)$l['id']]);
    $u = nl_url_ok((string)$l['url']); if ($u === '') return ''; return $u . ((str_contains($u, 'goldenas.com.gr') || str_contains($u, 'kyma-marinepower.eu')) && !str_contains($u, 'utm_') ? (str_contains($u, '?') ? '&' : '?') . 'utm_source=newsletter&utm_campaign=nl' . (int)$s['issue_id'] : '');
}
function nl_pub_unsub(string $tok): bool { $site = nl_token_site($tok); $s = one('SELECT id, issue_id, email FROM nl_sends WHERE token = ?', [$tok]); if (!$s) { $b = one('SELECT email FROM nl_subs WHERE token = ?', [$tok]); if (!$b) return false; nl_mark((string)$b['email'], 'unsub', null, $site); return true; } q('UPDATE nl_sends SET unsub_at = COALESCE(unsub_at, NOW()) WHERE id = ?', [(int)$s['id']]); nl_mark((string)$s['email'], 'unsub', (int)$s['issue_id'], $site); return true; }
/** web_feed.php?r=unsubscribe (R97-050): unsubscribe an address from the list of one website. The answer and the time it takes are the same whether the
 *  address is subscribed, unknown or malformed, so the route can not be used to find out who is on the list. */
function nl_pub_unsub_email(string $email, string $site): array {
    $t0 = microtime(true); $e = mb_strtolower(mb_substr(trim($email), 0, 190)); $site = kym_site($site);
    try { if (nl_has() && filter_var($e, FILTER_VALIDATE_EMAIL)) { $b = [$e]; $sub = one('SELECT id FROM nl_subs WHERE email = ?' . nl_w($site, $b), $b); if ($sub) nl_mark($e, 'unsub', null, $site); } } catch (Throwable $x) { error_log('[nl] unsubscribe by address: ' . $x->getMessage()); }
    $left = 0.25 - (microtime(true) - $t0); if ($left > 0) usleep((int) ($left * 1e6));   // every answer takes a quarter of a second
    return ['status' => 'ok'];
}
function nl_pub_confirm(string $tok): bool {
    $b = one("SELECT * FROM nl_subs WHERE token = ?", [$tok]); if (!$b || $b['status'] === 'unsubscribed') return false;
    q("UPDATE nl_subs SET status = 'confirmed', confirmed_at = COALESCE(confirmed_at, NOW()) WHERE id = ?", [(int)$b['id']]); $db2 = [mb_strtolower((string)$b['email'])]; q('DELETE FROM nl_optout WHERE email = ? AND reason <> \'bounce\'' . nl_w(kym_site($b['site'] ?? 'golden'), $db2), $db2);
    try { nl_sub_to_contact((int)$b['id']); } catch (Throwable $x) { error_log('[nl] subscriber to contact: ' . $x->getMessage()); }
    return true;
}
/** Website sign-up (web_feed.php?r=subscribe): pending row + confirmation e-mail (double opt-in). Always answers the same, whatever the state of the address. */
function nl_pub_subscribe(array $b, string $ip, string $site = 'golden'): array {
    if (!nl_has()) throw new RuntimeException('newsletters disabled', 503); $site = kym_site($site); $ks = kym_has();
    if ($site === 'kyma' && (!$ks || !nl_ready('kyma'))) throw new RuntimeException('newsletter not open yet', 503);   // R97-050: no KYMA sign-up until the KYMA mailbox can send the confirmation
    $f = fn(string $k, int $max) => mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', (string)($b[$k] ?? ''))), 0, $max);
    $email = mb_strtolower($f('email', 190)); if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('bad email', 422);
    $topics = array_values(array_intersect(NL_TOPICS, array_map('strval', (array)($b['topics'] ?? ['newsletter'])))) ?: ['newsletter']; $lang = in_array($b['lang'] ?? '', $site === 'kyma' ? ['en', 'el', 'it', 'es', 'fr', 'de', 'ar', 'nl'] : ['en', 'el', 'it'], true) ? (string)$b['lang'] : 'en';
    $kind = in_array($b['kind'] ?? '', ['yard', 'owner', 'customer', 'supplier', 'management', 'other'], true) ? (string)$b['kind'] : null; $role = function_exists('ct_categories') && isset(ct_categories()[(string)($b['role'] ?? '')]) ? (string)$b['role'] : null;
    $cb = [$email]; $cur = one('SELECT * FROM nl_subs WHERE email = ?' . nl_w($site, $cb), $cb);
    if ($cur && $cur['status'] === 'confirmed') { $set = array_unique(array_merge(explode(',', (string)$cur['topics']), $topics)); q('UPDATE nl_subs SET topics = ? WHERE id = ?', [implode(',', array_intersect(NL_TOPICS, $set)), (int)$cur['id']]); return ['status' => 'ok']; }
    if ($cur && tz_age((string)$cur['created_at']) < 600 && $cur['status'] === 'pending') return ['status' => 'ok'];   // one confirmation e-mail per ten minutes
    $tok = nl_token();
    if ($cur) q("UPDATE nl_subs SET name = ?, company = ?, kind = ?, topics = ?, lang = ?, status = 'pending', token = ?, source = ?, ip = ?, created_at = NOW(), unsub_at = NULL WHERE id = ?", [$f('name', 160) ?: null, $f('company', 160) ?: null, $kind, implode(',', $topics), $lang, $tok, $f('source', 40) ?: null, mb_substr($ip, 0, 64), (int)$cur['id']]);
    elseif ($ks) q('INSERT INTO nl_subs (site, email, name, company, kind, topics, lang, token, source, ip) VALUES (?,?,?,?,?,?,?,?,?,?)', [$site, $email, $f('name', 160) ?: null, $f('company', 160) ?: null, $kind, implode(',', $topics), $lang, $tok, $f('source', 40) ?: null, mb_substr($ip, 0, 64)]);
    else q('INSERT INTO nl_subs (email, name, company, kind, topics, lang, token, source, ip) VALUES (?,?,?,?,?,?,?,?,?)', [$email, $f('name', 160) ?: null, $f('company', 160) ?: null, $kind, implode(',', $topics), $lang, $tok, $f('source', 40) ?: null, mb_substr($ip, 0, 64)]);
    try { if ($role !== null) { $rb = [$role, $email]; q('UPDATE nl_subs SET role = ? WHERE email = ?' . nl_w($site, $rb), $rb); } } catch (Throwable $x) {}
    $ml = in_array($lang, ['en', 'el', 'it'], true) ? $lang : 'en';   // the portal has texts in these three; the other KYMA languages get English
    $T = fn(string $k, array $p = []) => function_exists('t_in') ? t_in($k, $ml, $p) : t($k, $p); $link = nl_base() . '/nl.php?s=' . $tok;
    if ($site === 'kyma') {   // KYMA-branded confirmation, from the KYMA mailbox
        $BR = nl_brand('kyma'); $h = fn(string $x) => htmlspecialchars($x, ENT_QUOTES);
        $html = '<!doctype html><html lang="' . $h($ml) . '"><head><meta charset="utf-8"><title>' . $h($T('nl.cfk_subject')) . '</title></head><body style="margin:0;padding:0;background:#EEF1F5;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF1F5;"><tr><td align="center" style="padding:18px 8px;"><table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#FFFFFF;border-radius:8px;overflow:hidden;">'
              . '<tr><td style="background:' . $BR['navy'] . ';padding:18px 24px;border-bottom:3px solid ' . $BR['gold'] . ';">' . ($BR['logo'] !== '' ? '<img src="' . $h(nl_base() . '/../assets/' . $BR['logo']) . '" alt="KYMA Marine Power" height="38" style="display:block;height:38px;width:auto;border:0;">' : '<span style="font:bold 22px/38px Aptos,Calibri,Arial,sans-serif;letter-spacing:3px;color:#FFFFFF;">KYMA</span> <span style="font:12px/38px Aptos,Calibri,Arial,sans-serif;letter-spacing:2px;color:' . $BR['gold'] . ';">MARINE POWER</span>') . '</td></tr>'
              . '<tr><td style="padding:26px 24px 12px;font:15px/1.6 Aptos,Calibri,Arial,sans-serif;color:#243140;text-align:justify;"><h2 style="margin:0 0 12px;font:bold 21px/1.3 Aptos,Calibri,Arial,sans-serif;color:' . $BR['navy'] . ';text-align:center;">' . $h($T('nl.cfk_title')) . '</h2><p style="margin:0 0 16px;">' . $h($T('nl.cfk_text')) . '</p>'
              . '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:6px auto 18px;"><tr><td style="background:' . $BR['gold'] . ';border-radius:6px;"><a href="' . $h($link) . '" style="display:inline-block;padding:11px 26px;font:bold 15px Aptos,Calibri,Arial,sans-serif;color:' . $BR['navy'] . ';text-decoration:none;">' . $h($T('nl.cfk_btn')) . '</a></td></tr></table><p style="margin:0 0 8px;font-size:13px;color:#6A7179;">' . $h($T('nl.cfk_ignore')) . '</p></td></tr>'
              . '<tr><td style="background:#F6F7F9;border-top:1px solid #E3E7EC;padding:16px 24px;font:12px/1.5 Aptos,Calibri,Arial,sans-serif;color:#6A7179;text-align:center;">KYMA Marine Power &middot; <a href="' . $h($BR['home']) . '" style="color:#6A7179;">' . $h($BR['host']) . '</a> &middot; <a href="' . $h($BR['privacy']) . '" style="color:#6A7179;">' . $h($T('nl.f_privacy')) . '</a></td></tr></table></td></tr></table></body></html>';
        [$ok, $err] = nl_graph_send($email, $T('nl.cfk_subject'), $html, 'kyma'); if (!$ok) error_log('[nl] KYMA confirmation not sent: ' . $err);
    } elseif (function_exists('cp_mail')) cp_mail($email, $T('nl.cf_subject'), $T('nl.cf_body', ['u' => $link]), 'Golden A/S');
    return ['status' => 'ok'];
}

/* ---------------------------------------------------------------- R97-040: subscribers become contacts; saved lists */
/** A confirmed website subscriber joins the Contacts register: the contact with the same e-mail gets the subscriptions; otherwise a new contact is
 *  created (origin "website", to be reviewed) with the category of the role / kind chosen on the form. The organisation is linked only when the
 *  company name matches an existing one — never created here. */
function nl_sub_to_contact(int $subId): ?int {
    $b = one('SELECT * FROM nl_subs WHERE id = ?', [$subId]); if (!$b || $b['status'] !== 'confirmed') return null; $email = mb_strtolower((string)$b['email']); $topics = array_values(array_intersect(NL_TOPICS, explode(',', (string)$b['topics'])));
    $site = kym_site($b['site'] ?? 'golden'); $coId = null;
    $cs = all('SELECT id, subscriptions, company_id FROM contacts WHERE LOWER(email) = ? ORDER BY is_active DESC, id', [$email]);
    if ($cs) { foreach ($cs as $c) { $set = array_unique(array_merge(array_filter(explode(',', (string)$c['subscriptions'])), $topics)); q('UPDATE contacts SET subscriptions = ? WHERE id = ?', [implode(',', $set), (int)$c['id']]); } $cid = (int)$cs[0]['id']; $coId = (int)($cs[0]['company_id'] ?? 0) ?: null; }
    else {
        $cat = (string)($b['role'] ?? '') !== '' ? (string)$b['role'] : (['yard' => 'yard', 'owner' => 'owner', 'management' => 'management', 'supplier' => 'supplier'][(string)$b['kind']] ?? 'other'); if (function_exists('ct_categories') && !isset(ct_categories()[$cat])) $cat = 'other';
        $name = trim((string)$b['name']) !== '' ? (string)$b['name'] : ucwords(str_replace(['.', '_', '-'], ' ', (string) strstr($email, '@', true))); $coName = trim((string)$b['company']); $coId = null; $cuId = null;
        if ($coName !== '' && function_exists('org_find')) { try { $m = org_find($coName); if ($m) { $coId = $m['company_id'] ?: null; $cuId = $m['customer_id'] ?: null; } } catch (Throwable $x) {} }
        if (!$coId && $coName !== '' && $site === 'kyma' && function_exists('co_find')) { try { $coId = co_find($coName, 'other', true) ?: null; } catch (Throwable $x) {} }   // R97-050: a KYMA subscriber brings its organisation (marked KYMA, to be reviewed with the contact)
        q('INSERT INTO contacts (customer_id, company, full_name, category, email, is_active) VALUES (?,?,?,?,?,1)', [$cuId, $coName !== '' ? mb_substr($coName, 0, 160) : null, mb_substr($name, 0, 160), $cat, $email]); $cid = (int) db()->lastInsertId();
        if (function_exists('co_has') && co_has()) q('UPDATE contacts SET company_id = ?, channels = ?, subscriptions = ? WHERE id = ?', [$coId, 'email', implode(',', $topics) ?: null, $cid]);
        if (function_exists('ct_origin_has') && ct_origin_has()) q('UPDATE contacts SET origin = ?, needs_review = 1 WHERE id = ?', [$site === 'kyma' ? 'kyma_website' : 'website', $cid]);
        if ($site === 'kyma') kym_brand_set('contact', $cid, ['kyma']);
        if ($coId && function_exists('cc_has') && cc_has()) { try { q('INSERT IGNORE INTO contact_companies (contact_id, company_id) VALUES (?,?)', [$cid, $coId]); } catch (Throwable $x) {} }
    }
    try { q('UPDATE nl_subs SET contact_id = ? WHERE id = ?', [$cid, $subId]); } catch (Throwable $x) {}
    kym_brand_add('contact', (int)$cid, $site); if ($coId) kym_brand_add('company', (int)$coId, $site);   // R97-050
    return $cid;
}
function nl_lists_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'nl_lists'"); } catch (Throwable $e) { $h = false; } } return $h; }
function nl_list(int $id): ?array { $r = nl_lists_has() && $id ? one('SELECT * FROM nl_lists WHERE id = ?', [$id]) : null; if ($r) $r['filter'] = json_decode((string)($r['filter'] ?? ''), true) ?: []; return $r; }
function nl_lists(): array { $out = []; if (!nl_lists_has()) return $out; foreach (all('SELECT * FROM nl_lists ORDER BY name') as $r) { $r['filter'] = json_decode((string)($r['filter'] ?? ''), true) ?: []; $out[] = $r; } return $out; }
/** Members of a list: contacts matching its categories (organisation role AND person category AND country AND subscription, each only when set),
 *  plus the addresses added by hand, minus the addresses excluded by hand. Dynamic — computed every time. Opt-outs are removed later, by nl_audience(). */
/** R97-043: how organisation type and person category combine in a list — 'any' (union, default) or 'all' (both must fit). */
function nl_list_match(array $f): string { return ($f['match'] ?? 'any') === 'all' ? 'all' : 'any'; }
/** R97-050: $forceBrand = the website of the issue the list is used for — only contacts carrying that brand; a list may also name a brand itself (filter key "brand"). */
function nl_list_members(array $L, ?string $forceBrand = null): array {
    $f = $L['filter']; $br = kym_has() ? (string)($forceBrand ?? ($f['brand'] ?? '')) : ''; $br = in_array($br, ['golden', 'kyma'], true) ? $br : ''; $brSql = $br !== '' ? 'FIND_IN_SET(' . db()->quote($br) . ', ct.brands)' : ''; $roles = array_values(array_intersect(array_keys(nl_groups()), (array)($f['roles'] ?? []))); $cats = function_exists('ct_categories') ? array_values(array_intersect(array_keys(ct_categories()), (array)($f['cats'] ?? []))) : [];
    $subs = array_values(array_intersect(NL_TOPICS, (array)($f['subs'] ?? []))); $country = trim((string)($f['country'] ?? '')); $out = [];
    $skip = []; foreach (preg_split('/[\s,;]+/', mb_strtolower((string)($L['skip_emails'] ?? ''))) ?: [] as $e) if ($e !== '') $skip[$e] = 1;
    $put = function (array $r) use (&$out, $skip) { $e = mb_strtolower(trim((string)$r['email'])); if ($e === '' || isset($skip[$e]) || isset($out[$e])) return; $out[$e] = ['email' => $e, 'name' => (string)$r['full_name'], 'contact_id' => (int)$r['id'], 'company_id' => $r['coid'] ? (int)$r['coid'] : null, 'company' => (string)($r['coname'] ?? ''), 'grp' => 'c:' . ((string)$r['category'] ?: 'other')]; };
    $ctry = array_values(array_filter(array_map('trim', preg_split('/[,;]+/', $country) ?: []), fn($x) => $x !== ''));   // R97-043: several countries, separated by commas
    if (($roles || $cats || $subs || (($f['brand'] ?? '') !== '' && $br !== '')) && function_exists('co_has') && co_has()) {
        $sel = 'SELECT ct.id, ct.full_name, ct.email, ct.category, co.id AS coid, co.name AS coname FROM contacts ct ';
        $run = function (array $cats, array $roles) use ($subs, $ctry, $sel, $put, $brSql) {
            $w = ["ct.is_active = 1 AND ct.email IS NOT NULL AND ct.email <> ''"]; $a = []; if ($brSql !== '') $w[] = $brSql;
            if ($cats) { $w[] = 'ct.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')'; $a = array_merge($a, $cats); }
            if ($subs) { $w[] = '(' . implode(' OR ', array_fill(0, count($subs), 'FIND_IN_SET(?, ct.subscriptions)')) . ')'; $a = array_merge($a, $subs); }
            $rw = []; $ra = []; foreach ($roles as $g) { $rw[] = '(FIND_IN_SET(?, co.roles) OR co.kind = ?' . ($g === 'supplier' ? ' OR co.is_supplier = 1' : '') . ($g === 'customer' ? ' OR co.customer_id IS NOT NULL' : '') . ')'; array_push($ra, $g, $g); }
            $cw = 'co.is_active = 1' . ($rw ? ' AND (' . implode(' OR ', $rw) . ')' : '') . ($ctry ? ' AND co.country IN (' . implode(',', array_fill(0, count($ctry), '?')) . ')' : ''); $ca = array_merge($ra, $ctry);
            if ($roles || $ctry) {
                foreach (all($sel . 'JOIN companies co ON co.id = ct.company_id WHERE ' . implode(' AND ', $w) . ' AND ' . $cw . ' ORDER BY co.name, ct.full_name', array_merge($a, $ca)) as $r) $put($r);
                if (function_exists('cc_has') && cc_has()) foreach (all($sel . 'JOIN contact_companies x ON x.contact_id = ct.id JOIN companies co ON co.id = x.company_id WHERE ' . implode(' AND ', $w) . ' AND ' . $cw . ' ORDER BY co.name, ct.full_name', array_merge($a, $ca)) as $r) $put($r);
            } else foreach (all($sel . 'LEFT JOIN companies co ON co.id = ct.company_id WHERE ' . implode(' AND ', $w) . ' ORDER BY co.name, ct.full_name', $a) as $r) $put($r);
        };
        // R97-043: "any" (default) = the people of the ticked organisation types PLUS the people with the ticked categories; "all" = only those who are both.
        if ($roles && $cats && nl_list_match($f) === 'any') { $run([], $roles); $run($cats, []); } else $run($cats, $roles);
    }
    foreach (preg_split('/[\s,;]+/', (string)($L['add_emails'] ?? '')) ?: [] as $e) { $e = mb_strtolower(trim($e)); if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($skip[$e]) || isset($out[$e])) continue; $c = one('SELECT ct.id, ct.full_name, ct.email, ct.category, co.id AS coid, co.name AS coname FROM contacts ct LEFT JOIN companies co ON co.id = ct.company_id WHERE LOWER(ct.email) = ? LIMIT 1', [$e]); if ($c) $put($c); else $out[$e] = ['email' => $e, 'name' => '', 'contact_id' => null, 'company_id' => null, 'company' => '', 'grp' => 'extra']; }
    return $out;
}
function nl_list_save_from_post(int $id): int {
    $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max); $name = $s('name', 120); if ($name === '') throw new RuntimeException(t('nl.l_need_name'));
    $f = ['roles' => array_values(array_intersect(array_keys(nl_groups()), (array)($_POST['roles'] ?? []))), 'cats' => array_values(array_intersect(array_keys(ct_categories()), (array)($_POST['cats'] ?? []))), 'subs' => array_values(array_intersect(NL_TOPICS, (array)($_POST['subs'] ?? []))), 'country' => $s('country', 80), 'match' => ($_POST['match'] ?? 'any') === 'all' ? 'all' : 'any', 'brand' => in_array($_POST['brand'] ?? '', ['golden', 'kyma'], true) ? (string)$_POST['brand'] : ''];
    $u = current_user(); $v = [$name, $s('descr', 300) ?: null, json_encode($f, JSON_UNESCAPED_UNICODE), $s('add_emails', 20000) ?: null, $s('skip_emails', 20000) ?: null];
    if ($id) { q('UPDATE nl_lists SET name = ?, descr = ?, filter = ?, add_emails = ?, skip_emails = ? WHERE id = ?', array_merge($v, [$id])); return $id; }
    q('INSERT INTO nl_lists (name, descr, filter, add_emails, skip_emails, created_by, created_name) VALUES (?,?,?,?,?,?,?)', array_merge($v, [(int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')])); return (int) db()->lastInsertId();
}

/* ---------------------------------------------------------------- assistant (R97-039) */
function nl_ai_on(): bool { return function_exists('ai_can') && ai_can('newsletter'); }
/** First draft of an issue by the assistant, from the brief the user types (and, when asked, the recent Marine intelligence headlines — public news).
 *  Nothing about contacts, recipients, prices or customers leaves the portal: the prompt holds the brief, the audience groups and the headlines only. */
function nl_ai_draft(int $id, string $brief, bool $news, bool $replace): int {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I); if (!nl_ai_on()) throw new RuntimeException(t('nl.ai_off'));
    $brief = mb_substr(trim($brief), 0, 3000); if ($brief === '' && !$news) throw new RuntimeException(t('nl.ai_need'));
    $langs = ['en' => 'British English', 'el' => 'Greek', 'it' => 'Italian']; $grp = implode(', ', array_map('nl_group_label', (array)($I['audience']['groups'] ?? []))) ?: 'customers, yards, owners and suppliers of the marine industry';
    $L = ['Newsletter title: ' . $I['title'], 'Readers: ' . $grp, 'Language of the newsletter: ' . ($langs[$I['lang']] ?? 'English')]; if ($brief !== '') $L[] = "Brief from the author (the facts to use):\n" . $brief;
    if ($news && function_exists('mi_has') && mi_has()) { try { $rows = all('SELECT title, source, url, grp FROM mi_items WHERE hidden = 0 AND published_at >= (UTC_TIMESTAMP() - INTERVAL 21 DAY) ORDER BY published_at DESC LIMIT 25'); if ($rows) { $L[] = 'Recent industry headlines (public news; pick at most three that matter to the readers, name the source, link to the address given):'; foreach ($rows as $r) $L[] = '- [' . $r['grp'] . '] ' . $r['title'] . ' — ' . $r['source'] . ' — ' . $r['url']; } } catch (Throwable $e) {} }
    $prompt = "Draft a company newsletter of Golden A/S (marine electrical, automation and navigation systems integrator, Piraeus) for the readers named below. Tone: professional, factual, warm, no superlatives, no invented facts, figures, names, dates or customers — use only the brief and the headlines. "
            . "Answer with JSON only, in this shape: {\"subject\": \"up to 70 characters\", \"preheader\": \"up to 110 characters\", \"blocks\": [{\"t\":\"h\",\"text\":\"…\"}, {\"t\":\"p\",\"text\":\"…\"}, {\"t\":\"card\",\"title\":\"…\",\"text\":\"…\",\"label\":\"…\",\"url\":\"https://…\"}, {\"t\":\"btn\",\"label\":\"…\",\"url\":\"https://…\"}, {\"t\":\"hr\"}]}. "
            . "Block types: h heading, p paragraph (plain text, **bold** allowed, blank line between paragraphs), card highlighted item with optional button, btn button, hr divider. 5 to 10 blocks, at most 350 words in all. Use a url only when it is given in the brief or the headlines, or https://www.goldenas.com.gr/ for our own website. Do not write a greeting line or a sign-off with a person's name.\n\n" . implode("\n", $L);
    $res = ai_run('write', ['title' => t('nl.title'), 'headline' => (string)$I['title']], $prompt, null, 'newsletter'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
    $j = ai_json((string)$res['text']); if (!$j || empty($j['blocks']) || !is_array($j['blocks'])) throw new RuntimeException(t('ai.failed'));
    $new = []; foreach ($j['blocks'] as $b) { if (!is_array($b) || !in_array($b['t'] ?? '', ['h', 'p', 'card', 'btn', 'hr'], true) || count($new) >= 14) continue; $x = ['t' => (string)$b['t']]; foreach (['text' => 6000, 'title' => 200, 'label' => 80] as $k => $max) if (isset($b[$k]) && is_string($b[$k])) $x[$k] = mb_substr(trim($b[$k]), 0, $max); if (isset($b['url']) && is_string($b['url'])) $x['url'] = nl_url_ok($b['url']); $new[] = $x; }
    if (!$new) throw new RuntimeException(t('ai.failed'));
    $blocks = array_slice($replace ? $new : array_merge($I['blocks'], $new), 0, NL_MAX_BLOCKS); $sub = is_string($j['subject'] ?? null) ? mb_substr(trim(preg_replace('/[\r\n]+/', ' ', $j['subject'])), 0, 200) : ''; $pre = is_string($j['preheader'] ?? null) ? mb_substr(trim($j['preheader']), 0, 200) : '';
    q('UPDATE nl_issues SET blocks = ?, subject = IF(? <> \'\' AND ?, ?, subject), preheader = IF(? <> \'\' AND ?, ?, preheader) WHERE id = ?', [json_encode($blocks, JSON_UNESCAPED_UNICODE), $sub, (int)$replace, $sub, $pre, (int)$replace, $pre, $id]);
    return count($new);
}

/* ---------------------------------------------------------------- actions */
function nl_handle_action(string $action): bool {
    if (!str_starts_with($action, 'nl_')) return false; require_staff(); if (!nl_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $back = fn(string $a = '') => redirect('newsletter', ['id' => $id, '_r' => uniqid()], $a);
    /* R97-051: an issue waiting for approval is locked — its form is not saved again; a change to an approved issue cancels the approval. */
    $sv = function () use ($id): void { $I = nl_issue($id); if ($I && ($I['appr_state'] ?? '') === 'pending') return; nl_save_from_post($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); };
    switch ($action) {
        case 'nl_appr_request': $sv(); nl_appr_request($id, (string)($_POST['appr_note'] ?? '')); flash('good', t('nl.ap_requested')); $back('#approval');
        case 'nl_appr_withdraw': nl_appr_withdraw($id); flash('good', t('nl.ap_withdrawn')); $back('#approval');
        case 'nl_appr_approve': nl_appr_decide($id, true, (string)($_POST['appr_reply'] ?? '')); flash('good', t('nl.ap_approved')); $back('#approval');
        case 'nl_appr_approve_send': nl_appr_decide($id, true, (string)($_POST['appr_reply'] ?? '')); $n = nl_queue($id); flash('good', t('nl.queued', ['n' => $n])); $back('#stats');
        case 'nl_appr_return': nl_appr_decide($id, false, (string)($_POST['appr_reply'] ?? '')); flash('good', t('nl.ap_returned')); $back('#approval');
        case 'nl_create': $id = nl_create((string)($_POST['title'] ?? ''), (string)($_POST['site'] ?? 'golden')); flash('good', t('nl.created')); redirect('newsletter', ['id' => $id]);
        case 'nl_save': nl_save_from_post($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); flash('good', t('nl.saved')); $back(!empty($_POST['add']) ? '#blocks' : '');
        case 'nl_copy': $id = nl_copy($id); flash('good', t('nl.copied')); redirect('newsletter', ['id' => $id]);
        case 'nl_delete': nl_delete($id); flash('good', t('nl.deleted')); redirect('newsletters', ['_r' => uniqid()]);
        case 'nl_ai': $sv(); $n = nl_ai_draft($id, (string)($_POST['brief'] ?? ''), !empty($_POST['news']), !empty($_POST['replace'])); flash('good', t('nl.ai_done', ['n' => $n])); $back('#blocks');
        case 'nl_test': $sv(); $to = nl_send_test($id); flash('good', t('nl.test_sent', ['e' => $to])); $back();
        case 'nl_queue': $sv(); $n = nl_queue($id); flash('good', t('nl.queued', ['n' => $n])); $back('#stats');
        case 'nl_batch': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $n = nl_send_batch(min(30, nl_batch() * 2)); flash('good', t('nl.batch_sent', ['n' => $n])); $back('#stats');
        case 'nl_cancel': $n = nl_cancel($id); flash('good', t('nl.cancelled', ['n' => $n])); $back('#stats');
        case 'nl_retry': $n = nl_retry_failed($id); flash('good', t('nl.retried', ['n' => $n])); $back('#stats');
        case 'nl_bounce': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $bs = kym_site($_POST['site'] ?? ($id ? (nl_issue($id)['site'] ?? 'golden') : 'golden')); nl_mark((string)($_POST['email'] ?? ''), 'bounce', $id ?: null, $bs); flash('good', t('nl.marked')); if ($id) $back('#stats'); redirect('newsletters', ['tab' => 'optout', 'site' => $bs, '_r' => uniqid()]);
        case 'nl_optin': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $os = kym_site($_POST['site'] ?? 'golden'); $ob = [mb_strtolower(trim((string)($_POST['email'] ?? '')))]; q('DELETE FROM nl_optout WHERE email = ?' . nl_w($os, $ob), $ob); flash('good', t('nl.restored')); redirect('newsletters', ['tab' => 'optout', 'site' => $os, '_r' => uniqid()]);
        case 'nl_list_save': $lid = nl_list_save_from_post((int)($_POST['list_id'] ?? 0)); flash('good', t('nl.l_saved')); redirect('newsletters', ['tab' => 'lists', 'edit' => $lid, '_r' => uniqid()]);
        case 'nl_list_delete': q('DELETE FROM nl_lists WHERE id = ?', [(int)($_POST['list_id'] ?? 0)]); flash('good', t('nl.l_deleted')); redirect('newsletters', ['tab' => 'lists', '_r' => uniqid()]);
        case 'nl_suball': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $n = ct_subscribe_all(); flash('good', t('nl.suball_done', ['n' => $n])); redirect('newsletters', ['tab' => 'lists', '_r' => uniqid()]);
        case 'nl_autocat': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $n = ct_autocat(); flash('good', t('nl.autocat_done', ['n' => $n])); redirect('newsletters', ['tab' => 'lists', '_r' => uniqid()]);
        case 'nl_settings': if (!nl_can_send()) throw new RuntimeException(t('err.noaccess')); $m = trim((string)($_POST['nl_from'] ?? '')); cp_setting_set('nl_from', $m !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) ? $m : ''); cp_setting_set('nl_batch', (string) max(1, min(50, (int)($_POST['nl_batch'] ?? 15)))); cp_setting_set('nl_daily', (string) max(10, min(5000, (int)($_POST['nl_daily'] ?? 400)))); if (nl_appr_has() && nl_approver()) cp_setting_set('nl_approval', !empty($_POST['nl_approval']) ? '1' : '0');
            if (kym_has()) { $mk = trim((string)($_POST['nl_from_kyma'] ?? '')); cp_setting_set('nl_from_kyma', $mk !== '' && filter_var($mk, FILTER_VALIDATE_EMAIL) ? $mk : ''); cp_setting_set('nl_list_headers_kyma', !empty($_POST['nl_list_headers_kyma']) ? '1' : '0'); cp_setting_set('nl_list_headers', !empty($_POST['nl_list_headers']) ? '1' : '0'); cp_setting_set('nl_mime_bad_until', '0'); }   // R97-050
            flash('good', t('nl.settings_saved')); redirect('newsletters', ['tab' => 'settings', '_r' => uniqid()]);
    }
    return false;
}

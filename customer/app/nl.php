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
/** R97-203: the languages a newsletter can be written in. One of them is the language of the issue itself; the others are versions of it. */
const NL_LANGS = ['en' => 'English', 'el' => 'Ελληνικά', 'it' => 'Italiano', 'ru' => 'Русский', 'uk' => 'Українська', 'de' => 'Deutsch', 'nl' => 'Nederlands'];
const NL_LANG_EN = ['en' => 'British English', 'el' => 'Greek', 'it' => 'Italian', 'ru' => 'Russian', 'uk' => 'Ukrainian', 'de' => 'German', 'nl' => 'Dutch'];
/** Greeting and footer of the message in the languages the portal itself is not written in (and Ukrainian, whose portal texts of the newsletter were never translated). */
const NL_FIXED = [
    'ru' => ['nl.hello' => 'Уважаемые дамы и господа,', 'nl.hello_name' => 'Уважаемый(ая) {n},', 'nl.f_unsub' => 'Отписаться', 'nl.f_privacy' => 'Политика конфиденциальности',
             'nl.f_why' => 'Вы получили это сообщение, потому что являетесь деловым контактом Golden A/S или подписались на нашем сайте.', 'nl.f_why_kyma' => 'Вы получили это сообщение, потому что являетесь деловым контактом KYMA Marine Power или подписались на kyma-marinepower.eu.'],
    'uk' => ['nl.hello' => 'Шановні пані та панове,', 'nl.hello_name' => 'Шановний(а) {n},', 'nl.f_unsub' => 'Відписатися', 'nl.f_privacy' => 'Політика конфіденційності',
             'nl.f_why' => 'Ви отримали це повідомлення, оскільки є діловим контактом Golden A/S або підписалися на нашому сайті.', 'nl.f_why_kyma' => 'Ви отримали це повідомлення, оскільки є діловим контактом KYMA Marine Power або підписалися на kyma-marinepower.eu.'],
    'de' => ['nl.hello' => 'Sehr geehrte Damen und Herren,', 'nl.hello_name' => 'Guten Tag {n},', 'nl.f_unsub' => 'Abmelden', 'nl.f_privacy' => 'Datenschutzerklärung',
             'nl.f_why' => 'Sie erhalten diese Nachricht, weil Sie ein Geschäftskontakt von Golden A/S sind oder sich auf unserer Website angemeldet haben.', 'nl.f_why_kyma' => 'Sie erhalten diese Nachricht, weil Sie ein Geschäftskontakt von KYMA Marine Power sind oder sich auf kyma-marinepower.eu angemeldet haben.'],
    'nl' => ['nl.hello' => 'Geachte heer, mevrouw,', 'nl.hello_name' => 'Beste {n},', 'nl.f_unsub' => 'Uitschrijven', 'nl.f_privacy' => 'Privacybeleid',
             'nl.f_why' => 'U ontvangt dit bericht omdat u een zakelijk contact van Golden A/S bent of u zich op onze website heeft aangemeld.', 'nl.f_why_kyma' => 'U ontvangt dit bericht omdat u een zakelijk contact van KYMA Marine Power bent of u zich op kyma-marinepower.eu heeft aangemeld.'],
];
function nl_lang_ok(?string $l): string { return isset(NL_LANGS[(string)$l]) ? (string)$l : 'en'; }
function nl_tr_has(): bool { static $h = null; if ($h === null) { try { $h = nl_has() && (bool) scalar("SHOW COLUMNS FROM nl_issues LIKE 'tr'") && (bool) scalar("SHOW COLUMNS FROM nl_sends LIKE 'lang'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** A fixed text of the message in the language of the version. */
function nl_t(string $k, string $lang, array $p = []): string {
    if (isset(NL_FIXED[$lang][$k])) { $s = NL_FIXED[$lang][$k]; foreach ($p as $a => $v) $s = str_replace('{' . $a . '}', (string)$v, $s); return $s; }
    return function_exists('t_in') ? t_in($k, isset(NL_FIXED[$lang]) ? 'en' : $lang, $p) : t($k, $p);
}

function nl_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'nl_issues'"); } catch (Throwable $e) { $h = false; } } return $h; }
function nl_can(): bool { return nl_has() && is_staff() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('pi_is_sales') && pi_is_sales())); }
/* ---- R97-160: HR newsletters (schools and recruiting partners) — the same engine, mailbox and approval; an issue belongs to a department.
 * Sales issues are prepared by sales, HR issues by HR; administrators and management see both. HR issues reach only the HR organisation types
 * (and addresses typed by hand): never the customer lists or the website subscribers. */
const NL_HR_GROUPS = ['school', 'rp_agency', 'rp_public', 'rp_assoc', 'rp_training'];
function nl_dept_has(): bool { static $h = null; if ($h === null) { try { $h = nl_has() && (bool) scalar("SHOW COLUMNS FROM nl_issues LIKE 'dept'"); } catch (Throwable $e) { $h = false; } } return $h; }
function nl_dept(?array $I): string { return ($I['dept'] ?? 'sales') === 'hr' ? 'hr' : 'sales'; }
function nl_hr_can(): bool { return nl_has() && nl_dept_has() && is_staff() && function_exists('hr_can') && hr_can(); }
function nl_can_dept(string $d): bool { if (!nl_has() || !is_staff()) return false; if (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())) return true; return $d === 'hr' ? nl_hr_can() : (function_exists('pi_is_sales') && pi_is_sales()); }
function nl_can_issue(array $I): bool { return nl_can_dept(nl_dept($I)); }
function nl_list_url(?array $I = null): string { return url(nl_dept($I) === 'hr' ? 'hr_newsletters' : 'newsletters'); }
/** Organisations of the HR types with their contact persons that have an e-mail — the audience of the HR newsletters. */
function nl_hr_partners(): array {
    if (!function_exists('co_has') || !co_has()) return []; $in = "'" . implode("','", NL_HR_GROUPS) . "'"; $roles = co_roles_has(); $out = [];
    $w = 'co.kind IN (' . $in . ')'; if ($roles) foreach (NL_HR_GROUPS as $k) $w .= " OR FIND_IN_SET('" . $k . "', co.roles)";
    foreach (all("SELECT co.id, co.name, co.kind, co.country, co.is_active FROM companies co WHERE ($w) ORDER BY co.kind, co.name LIMIT 1000") as $c) { $c['contacts'] = []; $out[(int)$c['id']] = $c; }
    if ($out) foreach (all('SELECT ct.id, ct.company_id, ct.full_name, ct.position, ct.email FROM contacts ct WHERE ct.is_active = 1 AND ct.company_id IN (' . implode(',', array_keys($out)) . ') ORDER BY ct.full_name') as $p) $out[(int)$p['company_id']]['contacts'][] = $p;
    return array_values($out);
}
/** HR adds a school / recruiting partner and its contact person without opening the contacts register. */
function nl_hr_partner_add_from_post(): void {
    if (!nl_hr_can() && !nl_can_dept('hr')) throw new RuntimeException(t('err.noaccess')); if (!function_exists('co_find') || !co_has()) throw new RuntimeException(t('err.noaccess'));
    $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $org = $s('org', 160); $kind = in_array($_POST['kind'] ?? '', NL_HR_GROUPS, true) ? (string)$_POST['kind'] : ''; $email = mb_strtolower($s('email', 190)); $name = $s('contact', 160);
    if ($org === '' || $kind === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('nl.hr_p_need'));
    $cid = co_find($org, $kind, true); if (!$cid) throw new RuntimeException(t('nl.hr_p_need'));
    $co = one('SELECT kind, country' . (co_roles_has() ? ', roles' : '') . ' FROM companies WHERE id = ?', [$cid]);
    if ($co && !in_array((string)$co['kind'], NL_HR_GROUPS, true) && co_roles_has() && !in_array($kind, explode(',', (string)($co['roles'] ?? '')), true)) q('UPDATE companies SET roles = TRIM(BOTH \',\' FROM CONCAT(COALESCE(roles, \'\'), \',\', ?)) WHERE id = ?', [$kind, $cid]);   // an existing organisation (a customer that is also a school) gets the role, its type stays
    if ($co && trim((string)($co['country'] ?? '')) === '' && $s('country', 80) !== '') q('UPDATE companies SET country = ? WHERE id = ?', [$s('country', 80), $cid]);
    if (one('SELECT id FROM contacts WHERE company_id = ? AND LOWER(email) = ?', [$cid, $email])) throw new RuntimeException(t('nl.hr_p_dup'));
    $u = current_user();
    q('INSERT INTO contacts (company_id, company, full_name, position, email, is_active, created_by) VALUES (?,?,?,?,?,1,?)', [$cid, $org, $name !== '' ? $name : $org, $s('position', 120) ?: null, $email, (int)($u['id'] ?? 0) ?: null]);
}
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
function nl_groups(string $dept = 'sales'): array { $g = []; if ($dept === 'hr') { foreach (NL_HR_GROUPS as $k) $g[$k] = t('co.kind_' . $k); return $g; } foreach (['customer', 'yard', 'owner', 'management', 'supplier', 'class', 'consultant', 'broker', 'agent'] as $k) $g[$k] = $k === 'customer' ? t('co.role_customer') : t('co.kind_' . $k); return $g; }
function nl_url_ok(string $u): string { $u = trim($u); if ($u === '' || strlen($u) > 600 || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) return ''; return $u; }

/* ---------------------------------------------------------------- issues */
function nl_issue(int $id): ?array {
    $r = nl_has() && $id ? one('SELECT * FROM nl_issues WHERE id = ?', [$id]) : null; if (!$r) return null;
    $r['blocks'] = json_decode((string)($r['blocks'] ?? ''), true) ?: []; $r['audience'] = json_decode((string)($r['audience'] ?? ''), true) ?: [];
    $tr = json_decode((string)($r['tr'] ?? ''), true); $r['tr'] = is_array($tr) ? array_intersect_key($tr, NL_LANGS) : [];   // R97-203
    return $r;
}
function nl_issues(?string $dept = null): array {
    $w = $dept !== null && nl_dept_has() ? ' WHERE i.dept = ' . db()->quote($dept === 'hr' ? 'hr' : 'sales') : ($dept === 'hr' ? ' WHERE 1 = 0' : '');   // R97-160
    return nl_has() ? all("SELECT i.*, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id) n_rcpt, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.status = 'sent') n_sent,
        (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.opened_at IS NOT NULL) n_open, (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.clicked_at IS NOT NULL) n_click,
        (SELECT COUNT(*) FROM nl_sends s WHERE s.issue_id = i.id AND s.unsub_at IS NOT NULL) n_unsub FROM nl_issues i" . $w . " ORDER BY i.id DESC LIMIT 200") : [];
}
function nl_create(string $title, string $site = 'golden', string $dept = 'sales'): int {
    $title = mb_substr(trim($title), 0, 200); if ($title === '') throw new RuntimeException(t('nl.need_title')); $u = current_user(); $site = kym_has() ? kym_site($site) : 'golden';
    $dept = $dept === 'hr' && nl_dept_has() ? 'hr' : 'sales'; if ($dept === 'hr') $site = 'golden';
    $blocks = [['t' => 'h', 'text' => $title], ['t' => 'p', 'text' => ''], ['t' => 'btn', 'label' => t($dept === 'hr' ? 'nl.hr_btn' : 'nl.read_more'), 'url' => $dept === 'hr' ? 'https://www.goldenas.com.gr/careers.html' : KYM_HOME[$site]]];
    q('INSERT INTO nl_issues (title, subject, lang, blocks, audience, created_by, created_name) VALUES (?,?,?,?,?,?,?)', [$title, $title, 'en', json_encode($blocks, JSON_UNESCAPED_UNICODE), json_encode(['groups' => [], 'topics' => []]), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]);
    $nid = (int) db()->lastInsertId(); if (kym_has()) q('UPDATE nl_issues SET site = ? WHERE id = ?', [$site, $nid]);   // R97-050: an issue belongs to one website
    if ($dept === 'hr') q("UPDATE nl_issues SET dept = 'hr' WHERE id = ?", [$nid]);
    return $nid;
}
function nl_editable(array $I): void { if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked')); if (($I['appr_state'] ?? '') === 'pending') throw new RuntimeException(t('nl.ap_locked')); }
/* ---- R97-051: approval by the CEO / General Manager before an issue is sent ---- */
function nl_appr_has(): bool { static $h = null; if ($h === null) { try { $h = nl_has() && (bool) scalar("SHOW COLUMNS FROM nl_issues LIKE 'appr_state'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Those who approve — and who need no approval themselves: Owner scope, CEO, General Manager. */
function nl_approver(): bool { return is_staff() && ((function_exists('mgmt_can') && mgmt_can()) || (function_exists('cp_role_name') && cp_role_name() === 'ceo')); }
function nl_appr_ids(): array { static $c = null; if ($c === null) { try { $c = array_map('intval', array_column(all("SELECT cu.id FROM customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.role IN ('staff','admin') AND (r.scope = 'owner' OR LOWER(TRIM(r.name)) IN ('ceo','general manager','managing director'))"), 'id')); } catch (Throwable $e) { $c = []; } } return $c; }
/** The rule is on by default; it rests while no approver account exists, so sending can never be locked out. */
function nl_appr_on(): bool { return nl_appr_has() && (string) cp_setting('nl_approval', '1') === '1' && nl_appr_ids(); }
/** Fingerprint of what the readers get and who gets it: an approval is valid for exactly this. */
function nl_hash(array $I): string { $a = [(string)$I['subject'], (string)($I['preheader'] ?? ''), (string)$I['lang'], $I['blocks'], $I['audience'], (string)($I['site'] ?? 'golden')]; if (!empty($I['tr'])) $a[] = $I['tr'];   /* R97-203: a changed language version needs approval again */ if (!empty($I['web_publish'])) $a[] = 'web:' . (string)($I['web_slug'] ?? '');   /* R97-176: publishing on the website is part of what is approved; issues without it keep their fingerprint */ return sha1((string) json_encode($a, JSON_UNESCAPED_UNICODE)); }
function nl_appr_state(array $I): string { $s = (string)($I['appr_state'] ?? ''); if ($s === 'approved' && !hash_equals((string)($I['appr_hash'] ?? ''), nl_hash($I))) return ''; return in_array($s, ['pending', 'approved', 'returned'], true) ? $s : ''; }
function nl_appr_ok(array $I): bool { return !nl_appr_on() || nl_approver() || nl_appr_state($I) === 'approved'; }
/** May this account launch the issue? Administrators and management as before; with the rule on, also the author once the issue is approved. */
function nl_may_send(array $I): bool { if (nl_can_send()) return true; $u = current_user(); $me = (int)($u['id'] ?? 0); return nl_can_issue($I) && nl_appr_on() && nl_appr_state($I) === 'approved' && $me && ($me === (int)($I['created_by'] ?? 0) || $me === (int)($I['appr_req_by'] ?? 0)); }
/** After a save: an approved issue that changed loses its approval. */
function nl_appr_recheck(int $id): bool { if (!nl_appr_has()) return false; $I = nl_issue($id); if (!$I || ($I['appr_state'] ?? '') !== 'approved' || hash_equals((string)$I['appr_hash'], nl_hash($I))) return false; q("UPDATE nl_issues SET appr_state = NULL, appr_hash = NULL WHERE id = ?", [$id]); return true; }
function nl_appr_pill(array $I): string { if (!nl_appr_has() || ($I['status'] ?? '') !== 'draft') return ''; $s = (string)($I['appr_state'] ?? ''); return $s === 'pending' ? ' ' . pill('gold', t('nl.ap_s_pending')) : ($s === 'approved' ? ' ' . pill('green', t('nl.ap_s_approved')) : ($s === 'returned' ? ' ' . pill('bad', t('nl.ap_s_returned')) : '')); }
function nl_appr_request(int $id, string $note): void {
    if (!nl_appr_on()) throw new RuntimeException(t('nl.ap_off')); if (nl_approver()) throw new RuntimeException(t('nl.ap_self')); $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I);
    if (!$I['blocks'] || trim((string)$I['subject']) === '') throw new RuntimeException(t('nl.empty')); $n = count(nl_audience($I['audience'], nl_site_of($I), nl_dept($I))); if (!$n) throw new RuntimeException(t('nl.no_rcpt')); $u = current_user();
    q("UPDATE nl_issues SET appr_state = 'pending', appr_req_by = ?, appr_req_name = ?, appr_req_at = NOW(), appr_note = ?, appr_by = NULL, appr_name = NULL, appr_at = NULL, appr_reply = NULL, appr_hash = NULL, appr_n = ? WHERE id = ?", [(int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), mb_substr(trim($note), 0, 300) ?: null, $n, $id]);
    try { if (function_exists('nf_push')) nf_push(nl_appr_ids(), 'nl:appr', 'nl:' . $id, t('nl.ap_nf_request', ['t' => (string)$I['title']]), mb_substr(t('nl.ap_nf_request_b', ['n' => $n, 'u' => (string)($u['full_name'] ?? '')]) . (trim($note) !== '' ? ' — ' . trim($note) : ''), 0, 300), url('newsletter', ['id' => $id]) . '#approval', (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
function nl_appr_withdraw(int $id): void { $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $u = current_user(); if (!nl_can_send() && (int)($u['id'] ?? 0) !== (int)($I['appr_req_by'] ?? 0)) throw new RuntimeException(t('err.noaccess')); q("UPDATE nl_issues SET appr_state = NULL WHERE id = ? AND appr_state = 'pending' AND status = 'draft'", [$id]); }
function nl_appr_decide(int $id, bool $ok, string $reply): void {
    if (!nl_approver()) throw new RuntimeException(t('nl.ap_who')); $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); if ($I['status'] !== 'draft' || ($I['appr_state'] ?? '') !== 'pending') throw new RuntimeException(t('nl.ap_not_pending'));
    $reply = mb_substr(trim($reply), 0, 500); if (!$ok && $reply === '') throw new RuntimeException(t('nl.ap_reply_req')); $u = current_user(); $n = count(nl_audience($I['audience'], nl_site_of($I), nl_dept($I)));
    q("UPDATE nl_issues SET appr_state = ?, appr_by = ?, appr_name = ?, appr_at = NOW(), appr_reply = ?, appr_hash = ?, appr_n = ? WHERE id = ? AND appr_state = 'pending'", [$ok ? 'approved' : 'returned', (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $reply ?: null, $ok ? nl_hash($I) : null, $n, $id]);
    try { $to = array_values(array_unique(array_filter([(int)($I['appr_req_by'] ?? 0), (int)($I['created_by'] ?? 0)]))); if (function_exists('nf_push') && $to) nf_push($to, 'nl:appr', 'nl:' . $id, t($ok ? 'nl.ap_nf_ok' : 'nl.ap_nf_back', ['t' => (string)$I['title']]), mb_substr((string)($u['full_name'] ?? '') . ($reply !== '' ? ' — ' . $reply : ''), 0, 300), url('newsletter', ['id' => $id]) . '#approval', (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')); } catch (Throwable $e) {}
}
/** Save head, blocks and audience of a draft from the editor form. */
function nl_save_from_post(int $id): void {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I);
    $s = fn(string $k, int $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $title = $s('title', 200) ?: $I['title']; $subject = preg_replace('/[\r\n]+/', ' ', $s('subject', 200)) ?: $title; $lang = nl_lang_ok((string)($_POST['lang'] ?? ''));
    $blocks = [];
    foreach ((array)($_POST['b'] ?? []) as $i => $b) {
        if (count($blocks) >= NL_MAX_BLOCKS || !is_array($b)) break; $t = (string)($b['t'] ?? ''); if (!in_array($t, NL_BLOCKS, true) || !empty($b['del'])) continue;
        $x = ['t' => $t]; foreach (['text' => 6000, 'title' => 200, 'label' => 80] as $k => $max) if (isset($b[$k])) $x[$k] = mb_substr(trim(str_replace("\r", '', (string)$b[$k])), 0, $max);
        if (isset($b['url'])) $x['url'] = nl_url_ok((string)$b['url']);
        $x['img'] = preg_match('/^[A-Za-z0-9]{24}$/', (string)($b['img'] ?? '')) ? (string)$b['img'] : '';
        if (!empty($_FILES['bf']['tmp_name'][$i]) && is_uploaded_file($_FILES['bf']['tmp_name'][$i])) $x['img'] = nl_image_store($id, $_FILES['bf']['tmp_name'][$i], (int)$_FILES['bf']['size'][$i]);
        $x['k'] = preg_match('/^[a-z0-9]{6}$/', (string)($b['k'] ?? '')) ? (string)$b['k'] : nl_bkey();   // R97-203: the language versions hold on to a block by this key
        $x['pos'] = (int)($b['pos'] ?? ($i * 10)); $blocks[] = $x;
    }
    usort($blocks, fn($a, $b) => $a['pos'] <=> $b['pos']); foreach ($blocks as &$x) unset($x['pos']); unset($x);
    $add = (string)($_POST['add'] ?? ''); if (in_array($add, NL_BLOCKS, true) && count($blocks) < NL_MAX_BLOCKS) $blocks[] = ['t' => $add, 'k' => nl_bkey()];
    $dp = nl_dept($I);   // R97-160: an HR issue has no lists and no website subscribers
    $aud = ['lists' => $dp === 'hr' ? [] : array_values(array_filter(array_map('intval', (array)($_POST['lists'] ?? [])))), 'groups' => array_values(array_intersect(array_keys(nl_groups($dp)), (array)($_POST['groups'] ?? []))), 'topics' => $dp === 'hr' ? [] : array_values(array_intersect(NL_TOPICS, (array)($_POST['topics'] ?? []))),
            'country' => $s('country', 80), 'extra' => $s('extra', 4000)];
    q('UPDATE nl_issues SET title = ?, subject = ?, preheader = ?, lang = ?, blocks = ?, audience = ? WHERE id = ?', [$title, $subject, $s('preheader', 200) ?: null, $lang, json_encode($blocks, JSON_UNESCAPED_UNICODE), json_encode($aud, JSON_UNESCAPED_UNICODE), $id]);
    if (nl_tr_has() && !empty($I['tr'][$lang])) { $tr = $I['tr']; unset($tr[$lang]); q('UPDATE nl_issues SET tr = ? WHERE id = ?', [$tr ? json_encode($tr, JSON_UNESCAPED_UNICODE) : null, $id]); }   // the language of the issue itself has no version
    if (nl_web_has() && $dp !== 'hr') { $wp = !empty($_POST['web_publish']) && nl_site_of($I) === 'golden' ? 1 : 0; $raw = $s('web_slug', 80); $slug = $raw !== '' || $wp ? nl_slug_free($raw !== '' ? $raw : $title, $id) : ''; q('UPDATE nl_issues SET web_publish = ?, web_slug = ? WHERE id = ?', [$wp, $slug !== '' ? $slug : null, $id]); }   // R97-176
}
/** An uploaded picture: JPEG / PNG / GIF up to 2 MB, checked by its content, stored outside the web root, served by token. */
function nl_image_store(int $issueId, string $tmp, int $size): string {
    if ($size <= 0 || $size > 2 * 1024 * 1024) throw new RuntimeException(t('nl.img_big'));
    $info = @getimagesize($tmp); $ext = [IMAGETYPE_JPEG => ['jpg', 'image/jpeg'], IMAGETYPE_PNG => ['png', 'image/png'], IMAGETYPE_GIF => ['gif', 'image/gif']][$info[2] ?? 0] ?? null; if (!$ext) throw new RuntimeException(t('nl.img_type'));
    $tok = nl_token(); $file = $tok . '.' . $ext[0]; if (!@move_uploaded_file($tmp, nl_dir() . '/' . $file)) throw new RuntimeException(t('nl.img_fail'));
    q('INSERT INTO nl_images (issue_id, token, mime, file) VALUES (?,?,?,?)', [$issueId, $tok, $ext[1], $file]); return $tok;
}
function nl_delete(int $id): void { $I = nl_issue($id); if (!$I) return; if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked')); foreach (all('SELECT file FROM nl_images WHERE issue_id = ?', [$id]) as $f) @unlink(nl_dir() . '/' . basename((string)$f['file'])); q('DELETE FROM nl_images WHERE issue_id = ?', [$id]); q('DELETE FROM nl_issues WHERE id = ?', [$id]); }
function nl_copy(int $id): int { $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $u = current_user(); q('INSERT INTO nl_issues (title, subject, preheader, lang, blocks, audience, created_by, created_name) VALUES (?,?,?,?,?,?,?,?)', [mb_substr($I['title'] . ' (copy)', 0, 200), $I['subject'], $I['preheader'], $I['lang'], json_encode($I['blocks'], JSON_UNESCAPED_UNICODE), json_encode($I['audience'], JSON_UNESCAPED_UNICODE), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? '')]); $nid = (int) db()->lastInsertId(); if (kym_has()) q('UPDATE nl_issues SET site = ? WHERE id = ?', [nl_site_of($I), $nid]); if (nl_dept($I) === 'hr' && nl_dept_has()) q("UPDATE nl_issues SET dept = 'hr' WHERE id = ?", [$nid]); if (nl_tr_has() && !empty($I['tr'])) q('UPDATE nl_issues SET tr = ? WHERE id = ?', [json_encode($I['tr'], JSON_UNESCAPED_UNICODE), $nid]); return $nid; }   // R97-050: a copy stays with its website

/* ---------------------------------------------------------------- audience */
/** The recipients an audience resolves to: email => [email, name, contact_id, company_id, company, grp]. Opt-outs removed, one row per address. */
function nl_audience(array $aud, string $site = 'golden', string $dept = 'sales'): array {
    if ($dept === 'hr') { $aud['lists'] = []; $aud['topics'] = []; }   // R97-160
    /* R97-050: the audience of an issue is of its website only — its own opt-out list, its own subscribers, and contacts that carry its brand.
       A KYMA issue never reaches a Golden-only contact and the reverse; an address typed under "extra" is the only exception, because it is typed on purpose. */
    $site = kym_site($site); $ks = kym_has(); $brand = $ks ? ' AND FIND_IN_SET(' . db()->quote($site) . ', ct.brands)' : '';
    $out = []; $opt = []; $ob = []; foreach (all('SELECT LOWER(email) e FROM nl_optout WHERE 1=1' . nl_w($site, $ob), $ob) as $r) $opt[$r['e']] = 1;
    $put = function (string $email, string $name, ?int $cid, ?int $coid, string $co, string $grp) use (&$out, $opt) { $e = mb_strtolower(trim($email)); if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($opt[$e]) || isset($out[$e]) || count($out) >= NL_MAX_RCPT) return; $out[$e] = ['email' => $e, 'name' => mb_substr(trim($name), 0, 160), 'contact_id' => $cid, 'company_id' => $coid, 'company' => mb_substr($co, 0, 160), 'grp' => $grp]; };
    foreach (array_map('intval', (array)($aud['lists'] ?? [])) as $lid) { $L = nl_list($lid); if ($L) foreach (nl_list_members($L, $ks ? $site : null) as $r) $put($r['email'], $r['name'], $r['contact_id'], $r['company_id'], $r['company'], $r['grp']); }   // R97-040 saved lists
    $groups = array_values(array_intersect(array_keys(nl_groups($dept)), (array)($aud['groups'] ?? []))); $country = trim((string)($aud['country'] ?? ''));
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
function nl_group_label(string $g): string { if (str_starts_with($g, 'c:')) return function_exists('ct_categories') ? (ct_categories()[substr($g, 2)] ?? substr($g, 2)) : substr($g, 2); return $g === 'web' ? t('nl.g_web') : ($g === 'extra' ? t('nl.g_extra') : (nl_groups()[$g] ?? (nl_groups('hr')[$g] ?? $g))); }

/* ---------------------------------------------------------------- publishing on the website (GAS-CP-R97-176) */
function nl_web_has(): bool { static $h = null; if ($h === null) { try { $h = nl_has() && (bool) scalar("SHOW COLUMNS FROM nl_issues LIKE 'web_slug'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** The part of the article address after news.html?a= : small letters, digits and hyphens. */
function nl_slug(string $s): string { $s = strtolower(trim($s)); $s = (string) preg_replace('/[^a-z0-9]+/', '-', $s); return trim(substr($s, 0, 80), '-'); }
/** A slug no other issue uses; a title without Latin letters or digits gives issue-<id>. */
function nl_slug_free(string $raw, int $id): string { $s = nl_slug($raw); if ($s === '') $s = 'issue-' . $id; if (one('SELECT id FROM nl_issues WHERE web_slug = ? AND id <> ?', [$s, $id])) $s = trim(substr($s, 0, 80 - strlen((string)$id) - 1), '-') . '-' . $id; return $s; }
/** An issue that is being sent or was sent is put on, or taken off, the News page of the website (management / administrators). */
function nl_web_toggle(int $id): bool {
    if (!nl_can_send() || !nl_web_has()) throw new RuntimeException(t('err.noaccess')); $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound'));
    if (nl_dept($I) === 'hr' || nl_site_of($I) !== 'golden' || !in_array($I['status'], ['sending', 'sent'], true)) throw new RuntimeException(t('nl.locked'));
    if (!empty($I['web_publish'])) { q('UPDATE nl_issues SET web_publish = 0 WHERE id = ?', [$id]); return false; }
    q('UPDATE nl_issues SET web_publish = 1, web_slug = ?, web_at = COALESCE(web_at, NOW()) WHERE id = ?', [nl_slug_free((string)($I['web_slug'] ?? '') !== '' ? (string)$I['web_slug'] : (string)$I['title'], $id), $id]); return true;
}
/** What the website may read through web_feed.php?r=news (site key): the issues published on the website — the list, or one issue with its blocks.
 *  Only the content already sent to the readers: subject, preview line, date, blocks, addresses of the pictures. Nothing about recipients or results. */
function nl_pub_news(string $site, string $slug = '', string $lang = ''): array {
    if (!nl_web_has() || kym_site($site) !== 'golden') return $slug === '' ? ['items' => []] : throw new RuntimeException('not found', 404);
    $w = "web_publish = 1 AND web_at IS NOT NULL AND web_slug IS NOT NULL AND status IN ('sending','sent')" . (nl_dept_has() ? " AND dept = 'sales'" : '') . (kym_has() ? " AND site = 'golden'" : '');
    $base = nl_base(); $img = fn($t): string => is_string($t) && preg_match('/^[A-Za-z0-9]{24}$/', $t) ? $base . '/nl.php?i=' . $t : '';
    /* R97-203: an article is offered in the language asked for when that version is ready; "langs" names the languages it can be read in */
    $lang = isset(NL_LANGS[$lang]) ? $lang : ''; $trc = nl_tr_has();
    $ver = function (array $r) use ($lang, $trc): array { $r['blocks'] = json_decode((string)$r['blocks'], true) ?: []; $tr = $trc ? json_decode((string)($r['tr'] ?? ''), true) : null; $r['tr'] = is_array($tr) ? array_intersect_key($tr, NL_LANGS) : [];
        $r['langs'] = array_values(array_unique(array_merge([(string)$r['lang']], nl_tr_ready($r)))); $web = $r['web_slug']; $at = $r['web_at']; if ($lang !== '') $r = nl_in_lang($r, $lang) + ['langs' => $r['langs']]; $r['web_slug'] = $web; $r['web_at'] = $at; return $r; };
    $head = function (array $r) use ($img, $ver): array { $r = $ver($r); $first = ''; foreach ($r['blocks'] as $b) if (is_array($b) && ($first = $img($b['img'] ?? '')) !== '') break;
        return ['slug' => (string)$r['web_slug'], 'title' => (string)$r['subject'], 'summary' => (string)($r['preheader'] ?? ''), 'date' => substr((string)$r['web_at'], 0, 10), 'lang' => (string)$r['lang'], 'langs' => $r['langs'], 'img' => $first]; };
    $cols = 'web_slug, subject, preheader, web_at, lang, blocks' . ($trc ? ', tr' : '');
    if ($slug !== '') {
        $r = preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug) ? one("SELECT $cols FROM nl_issues WHERE $w AND web_slug = ?", [$slug]) : null; if (!$r) throw new RuntimeException('not found', 404);
        $o = $head($r); $o['blocks'] = [];
        foreach ($ver($r)['blocks'] as $b) { if (!is_array($b) || !in_array($b['t'] ?? '', NL_BLOCKS, true)) continue; $x = ['t' => (string)$b['t']];
            foreach (['text', 'title', 'label'] as $k) if (isset($b[$k]) && is_string($b[$k]) && $b[$k] !== '') $x[$k] = $b[$k];
            if (!empty($b['url']) && is_string($b['url']) && nl_url_ok($b['url']) !== '') $x['url'] = $b['url']; if (($u = $img($b['img'] ?? '')) !== '') $x['img'] = $u; $o['blocks'][] = $x; }
        return ['item' => $o];
    }
    return ['items' => array_map($head, all("SELECT $cols FROM nl_issues WHERE $w ORDER BY web_at DESC, id DESC LIMIT 50"))];
}

/* ---------------------------------------------------------------- import from a block sheet (GAS-CP-R97-175) */
const NL_IMPORT_MAX_MD = 200000;   // bytes of the block sheet
const NL_IMPORT_MAX_PICS = 20;
/** A block sheet (.md, UTF-8) → head and blocks of an issue. Nothing is stored here.
 *  Head between two lines of ---: title, subject, preheader, lang (en / el / it / ru / uk / de / nl — the language the sheet is written in; the other languages are added in the portal), and for the website web (yes / no) and slug (R97-176).
 *  Body: # heading · text paragraphs (blank line between them, **bold**, lines starting with - as a list) · ![description](picture.jpg) ·
 *  a line that is only [label](https://…) = button · --- = divider · lines starting with > = one highlight (### title, text, picture, button).
 *  A picture is named by its file name; the folder part of the path is ignored. Returns head, blocks (a picture block carries 'file'), files. */
function nl_md_parse(string $md): array {
    if (!mb_check_encoding($md, 'UTF-8')) throw new RuntimeException(t('nl.imp_utf8'));
    $md = str_replace(["\r\n", "\r"], "\n", (string) preg_replace('/^\xEF\xBB\xBF/', '', $md)); $L = explode("\n", $md); $n = count($L); $i = 0;
    $head = ['title' => '', 'subject' => '', 'preheader' => '', 'lang' => 'en', 'web' => '', 'slug' => ''];
    while ($i < $n && trim($L[$i]) === '') $i++;
    if ($i < $n && trim($L[$i]) === '---') {
        for ($i++; $i < $n && trim($L[$i]) !== '---'; $i++) if (preg_match('/^\s*([A-Za-z_ ]+?)\s*:\s*(.*)$/', $L[$i], $m)) {
            $k = strtolower(trim($m[1])); $k = ['preview' => 'preheader', 'preview line' => 'preheader', 'language' => 'lang', 'name' => 'title'][$k] ?? $k; $v = trim($m[2]);
            if (strlen($v) > 1 && ($v[0] === '"' || $v[0] === "'") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
            if (isset($head[$k])) $head[$k] = trim($v);
        }
        $i++;
    }
    $reImg = '/^(?:\[\s*)?!\[([^\]]*)\]\(\s*([^)\s]+)\s*\)(?:\s*\]\(\s*(https?:\/\/[^)\s]+)\s*\))?$/'; $reBtn = '/^\[([^\]]+)\]\(\s*(https?:\/\/[^)\s]+)\s*\)$/';
    $fkey = fn(string $p): string => mb_strtolower(basename(str_replace('\\', '/', rawurldecode($p))));
    $isLi = fn(string $s): bool => (bool) preg_match('/^(?:[-*•]|\d{1,2}[.)])\s+/u', $s); $li = fn(string $s): string => (string) preg_replace('/^[-*]\s+/', '• ', $s);
    /* lines of running text → paragraphs: wrapped lines are joined, list lines keep their own line */
    $text = function (array $lines) use ($isLi, $li): string { $out = []; $cur = '';
        foreach ($lines as $s) { $s = trim($s); if ($s === '') { if ($cur !== '') { $out[] = $cur; $cur = ''; } continue; } $cur = $cur === '' ? $li($s) : $cur . ($isLi($s) ? "\n" . $li($s) : ' ' . $s); }
        if ($cur !== '') $out[] = $cur; return implode("\n\n", $out); };
    $blocks = []; $files = []; $para = []; $card = null;
    $cut = fn(string $s, int $max): string => mb_substr(trim($s), 0, $max);
    $flushP = function () use (&$para, &$blocks, $text, $cut): void { $x = $text($para); $para = []; if ($x !== '') $blocks[] = ['t' => 'p', 'text' => $cut($x, 6000)]; };
    $flushC = function () use (&$card, &$blocks, &$files, $text, $cut, $reImg, $reBtn, $fkey): void { if ($card === null) return; $b = ['t' => 'card']; $tx = [];
        foreach ($card as $s) { $s2 = trim($s);
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $s2, $m) && !isset($b['title'])) $b['title'] = $cut($m[1], 200);
            elseif (preg_match($reImg, $s2, $m) && !isset($b['file'])) { $b['file'] = $fkey($m[2]); $files[$b['file']] = 1; }
            elseif (preg_match($reBtn, $s2, $m) && !isset($b['label'])) { $b['label'] = $cut($m[1], 80); $b['url'] = nl_url_ok($m[2]); }
            else $tx[] = $s; }
        $b['text'] = $cut($text($tx), 6000); $card = null; if ($b['text'] !== '' || !empty($b['title'])) $blocks[] = $b; };
    for (; $i < $n; $i++) { $raw = rtrim($L[$i]); $s = trim($raw);
        if (preg_match('/^>\s?(.*)$/', $s, $m)) { $flushP(); $card ??= []; $card[] = $m[1]; continue; }
        $flushC();
        if ($s === '') { $para[] = ''; continue; }
        if (preg_match('/^<!--.*-->$/', $s)) continue;   // a comment line of the author is not part of the issue
        if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $s, $m)) { $flushP(); $blocks[] = ['t' => 'h', 'text' => $cut($m[1], 6000)]; continue; }
        if (preg_match('/^(?:-{3,}|\*{3,}|_{3,})$/', $s)) { $flushP(); $blocks[] = ['t' => 'hr']; continue; }
        if (preg_match($reImg, $s, $m)) { $flushP(); $k = $fkey($m[2]); $files[$k] = 1; $blocks[] = ['t' => 'img', 'title' => $cut($m[1], 200), 'url' => nl_url_ok((string)($m[3] ?? '')), 'file' => $k]; continue; }
        if (preg_match($reBtn, $s, $m)) { $flushP(); $blocks[] = ['t' => 'btn', 'label' => $cut($m[1], 80), 'url' => nl_url_ok($m[2])]; continue; }
        $para[] = $s;
    }
    $flushC(); $flushP();
    return ['head' => $head, 'blocks' => $blocks, 'files' => array_keys($files)];
}
/** The block sheet and its pictures, as uploaded, become a new draft issue. Everything is checked before anything is stored; a failure
 *  while storing removes the half-made draft. The draft then follows the usual way: preview, audience, approval, sending. Returns [id, blocks, pictures]. */
function nl_import_from_post(string $dept, string $site): array {
    $f = $_FILES['md'] ?? null; if (!is_array($f) || is_array($f['name'] ?? null) || (int)($f['error'] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$f['tmp_name'])) throw new RuntimeException(t('nl.imp_need'));
    if ((int)$f['size'] <= 0 || (int)$f['size'] > NL_IMPORT_MAX_MD || !preg_match('/\.(md|markdown|txt)$/i', (string)$f['name'])) throw new RuntimeException(t('nl.imp_md_bad'));
    $P = nl_md_parse((string) file_get_contents((string)$f['tmp_name'])); $H = $P['head'];
    if (!$P['blocks']) throw new RuntimeException(t('nl.imp_empty'));
    if (count($P['blocks']) > NL_MAX_BLOCKS) throw new RuntimeException(t('nl.imp_many', ['n' => count($P['blocks']), 'm' => NL_MAX_BLOCKS]));
    if (count($P['files']) > NL_IMPORT_MAX_PICS) throw new RuntimeException(t('nl.imp_pic_many', ['m' => NL_IMPORT_MAX_PICS]));
    $have = []; $show = fn(string $s): string => mb_substr((string) preg_replace('/[^\p{L}\p{N}._\- ]/u', '?', $s), 0, 80);
    foreach (['picdir', 'pics'] as $fld) { $F = $_FILES[$fld] ?? null; if (!is_array($F) || !is_array($F['name'] ?? null)) continue;
        foreach ($F['name'] as $k => $nm) { if ((int)($F['error'][$k] ?? 4) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$F['tmp_name'][$k])) continue; $key = mb_strtolower(basename(str_replace('\\', '/', (string)$nm))); if (!isset($have[$key])) $have[$key] = [(string)$F['tmp_name'][$k], (int)$F['size'][$k]]; } }
    $miss = array_values(array_diff($P['files'], array_keys($have))); if ($miss) throw new RuntimeException(t('nl.imp_missing', ['f' => implode(', ', array_map($show, array_slice($miss, 0, 10)))]));
    foreach ($P['files'] as $k) { [$tmp, $size] = $have[$k]; $info = @getimagesize($tmp); if ($size <= 0 || $size > 2 * 1024 * 1024 || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) throw new RuntimeException(t('nl.imp_pic_bad', ['f' => $show($k)])); }
    $subject = mb_substr(trim((string) preg_replace('/[\r\n]+/', ' ', $H['subject'])), 0, 200); $title = mb_substr($H['title'] !== '' ? $H['title'] : ($subject !== '' ? $subject : (string) preg_replace('/\.[A-Za-z]+$/', '', basename((string)$f['name']))), 0, 200);
    $lang = nl_lang_ok(['gr' => 'el', 'ua' => 'uk'][strtolower($H['lang'])] ?? strtolower($H['lang']));
    $id = nl_create($title, $site, $dept);
    try {
        $tok = []; foreach ($P['files'] as $k) $tok[$k] = nl_image_store($id, $have[$k][0], $have[$k][1]);
        $blocks = []; foreach ($P['blocks'] as $b) { if (isset($b['file'])) { $b['img'] = $tok[$b['file']] ?? ''; unset($b['file']); } $blocks[] = $b; }
        q('UPDATE nl_issues SET subject = ?, preheader = ?, lang = ?, blocks = ? WHERE id = ?', [$subject !== '' ? $subject : $title, mb_substr($H['preheader'], 0, 200) ?: null, $lang, json_encode($blocks, JSON_UNESCAPED_UNICODE), $id]);
        if (nl_web_has() && $dept !== 'hr' && ($H['slug'] !== '' || in_array(strtolower($H['web']), ['yes', 'true', '1', 'on'], true))) q('UPDATE nl_issues SET web_publish = ?, web_slug = ? WHERE id = ?', [in_array(strtolower($H['web']), ['yes', 'true', '1', 'on'], true) ? 1 : 0, nl_slug_free($H['slug'] !== '' ? $H['slug'] : $title, $id), $id]);   // R97-176
    } catch (Throwable $e) { try { nl_delete($id); } catch (Throwable $x) {} throw $e; }
    return [$id, count($P['blocks']), count($P['files'])];
}
/** The example block sheet offered on the import card (plain text, no data of the portal). */
function nl_md_sample(): string {
    return "---\ntitle: 2026-12 Example issue\nsubject: The subject line of the e-mail, under 60 characters\npreheader: The preview line shown after the subject in the inbox, 80 to 110 characters long.\nlang: en\nweb: no\nslug: 2026-12-example-issue\n---\n\n"
         . "# The headline of the issue\n\nThe lead: who the issue is for and what it gives them. A blank line starts a new paragraph. **Bold** is kept.\n\n"
         . "[Label of the main button](https://www.goldenas.com.gr/)\n\n![Description of the picture](01-picture.jpg)\n\n---\n\n# A second section\n\nText of the section.\n\n- A list line\n- Another list line\n\n"
         . "> ### Title of a highlight\n> Text of the highlight, in a framed box.\n> [Button of the highlight](https://www.goldenas.com.gr/contact.html)\n\n[Read the full article](https://www.goldenas.com.gr/newsletter.html)\n";
}
function nl_md_sample_send(): void { header('Content-Type: text/markdown; charset=utf-8'); header('Content-Disposition: attachment; filename="GAS-newsletter-block-sheet-example.md"'); header('X-Content-Type-Options: nosniff'); echo nl_md_sample(); exit; }

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
    $base = nl_base(); $img = fn(string $tok) => $base . '/nl.php?i=' . $tok; $T = fn(string $k, array $p = []) => nl_t($k, (string)$I['lang'], $p);
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
function nl_greeting(array $I, string $name): string { $n = trim($name); return nl_t($n !== '' ? 'nl.hello_name' : 'nl.hello', (string)$I['lang'], ['n' => $n]); }

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
function nl_send_test(int $id, ?string $lang = null): string {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); if ($lang !== null) $I = nl_in_lang($I, $lang, true); $site = nl_site_of($I); if (!nl_ready($site)) throw new RuntimeException(t($site === 'kyma' ? 'nl.no_mailer_kyma' : 'nl.no_mailer'));
    $u = current_user(); $to = (string)($u['email'] ?? ''); if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException(t('nl.no_mailer'));
    $html = strtr(nl_render($I, fn($x) => $x), ['%%NAME%%' => e(nl_greeting($I, (string)($u['full_name'] ?? ''))), '%%OPEN%%' => nl_base() . '/nl.php?o=test', '%%UNSUB%%' => KYM_HOME[$site]]);
    [$ok, $err] = nl_graph_send($to, '[TEST' . ($lang !== null ? ' ' . strtoupper((string)$I['lang']) : '') . '] ' . $I['subject'], $html, $site, nl_list_headers($site) ? nl_base() . '/nl.php?u=test' : null);   // the test uses the same way of sending as the real issue if (!$ok) throw new RuntimeException(t('nl.send_fail', ['e' => $err])); return $to;
}
/** Queue a draft: resolve the audience, register the links, create one row per recipient. From here the issue is locked. */
function nl_queue(int $id): int {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); if (!nl_may_send($I)) throw new RuntimeException(t(nl_can_issue($I) && nl_appr_on() ? 'nl.ap_needed' : 'err.noaccess')); if ($I['status'] !== 'draft') throw new RuntimeException(t('nl.locked'));
    if (!nl_appr_ok($I)) throw new RuntimeException(t('nl.ap_needed'));   /* R97-051 */
    $site = nl_site_of($I); if (!nl_ready($site)) throw new RuntimeException(t($site === 'kyma' ? 'nl.no_mailer_kyma' : 'nl.no_mailer')); if (!$I['blocks']) throw new RuntimeException(t('nl.empty'));
    $rc = nl_audience($I['audience'], $site, nl_dept($I)); if (!$rc) throw new RuntimeException(t('nl.no_rcpt')); $u = current_user();
    db()->beginTransaction();
    try {
        q('DELETE FROM nl_links WHERE issue_id = ?', [$id]); $n = 0; $seen = [];
        $reg = function (string $url) use (&$n, &$seen, $id) { if (!isset($seen[$url])) { $seen[$url] = ++$n; q('INSERT INTO nl_links (issue_id, n, url) VALUES (?,?,?)', [$id, $n, $url]); } return $url; };
        nl_render($I, $reg); foreach (nl_tr_ready($I) as $tl) nl_render(nl_in_lang($I, $tl), $reg);   // R97-203: a link written only in a language version is counted too
        $rl = nl_tr_has() ? nl_rcpt_langs($rc, $site) : [];
        $ins = db()->prepare('INSERT IGNORE INTO nl_sends (issue_id, email, name, contact_id, company_id, company, grp, token) VALUES (?,?,?,?,?,?,?,?)');
        foreach ($rc as $r) $ins->execute([$id, $r['email'], $r['name'] ?: null, $r['contact_id'], $r['company_id'], $r['company'] ?: null, $r['grp'], nl_token()]);
        if ($rl) { $up = db()->prepare('UPDATE nl_sends SET lang = ? WHERE issue_id = ? AND email = ?'); foreach ($rl as $em => $l) $up->execute([$l, $id, $em]); }
        q("UPDATE nl_issues SET status = 'sending', n_total = ?, queued_by = ?, queued_name = ?, queued_at = NOW() WHERE id = ?", [count($rc), (int)($u['id'] ?? 0) ?: null, (string)($u['full_name'] ?? ''), $id]);
        if (nl_web_has()) q('UPDATE nl_issues SET web_at = COALESCE(web_at, NOW()) WHERE id = ? AND web_publish = 1 AND web_slug IS NOT NULL', [$id]);   // R97-176: the article appears on the website when sending starts
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
    $lk = fn(string $u) => isset($links[$u]) ? '%%C' . $links[$u] . '%%' : $u; $V = [(string)$I['lang'] => [$I, nl_render($I, $lk)]]; $base = nl_base(); $sent = 0;
    $ver = function (?string $l) use (&$V, $I, $lk): array { $l = (string)$l; if ($l === '' || !isset(NL_LANGS[$l])) $l = 'en'; if (!isset($V[$l])) { $x = nl_in_lang($I, $l); $V[$l] = $x['lang'] === $I['lang'] ? $V[(string)$I['lang']] : [$x, nl_render($x, $lk)]; } return $V[$l]; };   // R97-203: the reader's language when that version is ready, else the issue as written
    foreach (all("SELECT * FROM nl_sends WHERE issue_id = ? AND status = 'queued' ORDER BY id LIMIT " . (int)$room, [$id]) as $s) {
        if (!q("UPDATE nl_sends SET status = 'sending' WHERE id = ? AND status = 'queued'", [(int)$s['id']])->rowCount()) continue;   // claimed: a second sweep does not send the same row
        $ob = [$s['email']]; if (scalar('SELECT 1 FROM nl_optout WHERE email = ?' . nl_w($site, $ob), $ob)) { q("UPDATE nl_sends SET status = 'skipped', error = 'opt-out' WHERE id = ?", [(int)$s['id']]); continue; }
        [$Iv, $tpl] = $ver($s['lang'] ?? null);
        $map = ['%%NAME%%' => e(nl_greeting($Iv, (string)$s['name'])), '%%OPEN%%' => $base . '/nl.php?o=' . $s['token'], '%%UNSUB%%' => $base . '/nl.php?u=' . $s['token']]; foreach ($links as $n) $map['%%C' . $n . '%%'] = $base . '/nl.php?c=' . $s['token'] . '&amp;l=' . $n;
        try { [$ok, $err] = nl_graph_send((string)$s['email'], (string)$Iv['subject'], strtr($tpl, $map), $site, $map['%%UNSUB%%']); } catch (Throwable $e) { $ok = false; $err = mb_substr($e->getMessage(), 0, 180); }
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
            'langs' => nl_tr_has() ? all("SELECT COALESCE(lang, '') lang, COUNT(*) n, SUM(status = 'sent') sent, SUM(opened_at IS NOT NULL) opened, SUM(clicked_at IS NOT NULL) clicked FROM nl_sends WHERE issue_id = ? GROUP BY COALESCE(lang, '') ORDER BY n DESC", [$id]) : [],
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
    $topics = array_values(array_intersect(NL_TOPICS, array_map('strval', (array)($b['topics'] ?? ['newsletter'])))) ?: ['newsletter']; $lang = in_array($b['lang'] ?? '', $site === 'kyma' ? ['en', 'el', 'it', 'es', 'fr', 'de', 'ar', 'nl'] : array_keys(NL_LANGS), true) ? (string)$b['lang'] : 'en';
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
    $langs = NL_LANG_EN; $grp = implode(', ', array_map('nl_group_label', (array)($I['audience']['groups'] ?? []))) ?: 'customers, yards, owners and suppliers of the marine industry';
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

/* ---------------------------------------------------------------- language versions (R97-203) */
function nl_bkey(): string { return substr(strtolower(nl_token()), 0, 6); }
/** Every block carries a key; issues written before R97-203, imported or drafted by the assistant get theirs here. */
function nl_keys_ensure(int $id): array {
    $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); $ch = false; $seen = [];
    foreach ($I['blocks'] as &$b) { if (!preg_match('/^[a-z0-9]{6}$/', (string)($b['k'] ?? '')) || isset($seen[$b['k']])) { $b['k'] = nl_bkey(); $ch = true; } $seen[$b['k']] = 1; } unset($b);
    if ($ch) q('UPDATE nl_issues SET blocks = ? WHERE id = ?', [json_encode($I['blocks'], JSON_UNESCAPED_UNICODE), $id]);
    return $I;
}
/** What of a block is language: its text, title and label (pictures, links and the order belong to the issue). */
function nl_block_words(array $b): array { $o = []; foreach (['title', 'text', 'label'] as $f) if (isset($b[$f]) && is_string($b[$f]) && trim($b[$f]) !== '') $o[$f] = $b[$f]; return $o; }
function nl_block_sig(array $b): string { return substr(md5(json_encode(nl_block_words($b))), 0, 10); }
/** The versions that go out: marked ready and holding at least a subject or one translated block. */
function nl_tr_ready(array $I): array { $o = []; foreach ((array)($I['tr'] ?? []) as $l => $v) if ($l !== $I['lang'] && isset(NL_LANGS[$l]) && !empty($v['ok']) && (trim((string)($v['subject'] ?? '')) !== '' || !empty($v['b']))) $o[] = $l; return $o; }
/** State of a version for the list on the issue: words to translate, translated, and those whose original changed since. */
function nl_tr_state(array $I, string $l): array {
    $v = (array)($I['tr'][$l] ?? []); $n = 0; $done = 0; $stale = 0;
    foreach ($I['blocks'] as $b) { if (!nl_block_words($b)) continue; $n++; $x = (array)($v['b'][(string)($b['k'] ?? '')] ?? []); if (!nl_block_words($x)) continue; $done++; if (($x['h'] ?? '') !== nl_block_sig($b)) $stale++; }
    return ['exists' => (bool)$v, 'ok' => !empty($v['ok']), 'n' => $n, 'done' => $done, 'stale' => $stale, 'subject' => trim((string)($v['subject'] ?? '')) !== ''];
}
/** The issue as it reads in a language: the version when it is ready ($force: also a draft, for the preview and the test), else the issue as written. */
function nl_in_lang(array $I, ?string $lang, bool $force = false): array {
    $lang = (string)$lang; if ($lang === '' || $lang === $I['lang'] || !isset(NL_LANGS[$lang])) return $I;
    $v = (array)($I['tr'][$lang] ?? []); if (!$v || (!$force && !in_array($lang, nl_tr_ready($I), true))) return $I;
    $o = $I; $o['lang'] = $lang; if (trim((string)($v['subject'] ?? '')) !== '') $o['subject'] = (string)$v['subject']; if (trim((string)($v['preheader'] ?? '')) !== '') $o['preheader'] = (string)$v['preheader'];
    foreach ($o['blocks'] as &$b) { $x = (array)($v['b'][(string)($b['k'] ?? '')] ?? []); foreach (['text', 'title', 'label'] as $f) if (isset($x[$f]) && is_string($x[$f]) && trim($x[$f]) !== '' && isset($b[$f])) $b[$f] = $x[$f]; } unset($b);
    return $o;
}
function nl_tr_store(int $id, array $tr): void { $tr = array_intersect_key($tr, NL_LANGS); q('UPDATE nl_issues SET tr = ? WHERE id = ?', [$tr ? json_encode($tr, JSON_UNESCAPED_UNICODE) : null, $id]); }
function nl_tr_lang_post(array $I): string { $l = (string)($_POST['tl'] ?? ($_GET['tl'] ?? '')); if (!isset(NL_LANGS[$l]) || $l === $I['lang']) throw new RuntimeException(t('nl.tr_bad')); return $l; }
/** Save the translator's screen: subject, preview line, the words of every block, and "ready to send". */
function nl_tr_save_from_post(int $id): string {
    if (!nl_tr_has()) throw new RuntimeException(t('nl.tr_need')); $I = nl_keys_ensure($id); nl_editable($I); $l = nl_tr_lang_post($I);
    $s = fn(string $k, int $max) => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $max); $in = (array)($_POST['w'] ?? []); $B = [];
    foreach ($I['blocks'] as $b) { $k = (string)$b['k']; $src = nl_block_words($b); if (!$src || !is_array($in[$k] ?? null)) continue; $x = [];
        foreach (['text' => 6000, 'title' => 200, 'label' => 80] as $f => $max) if (isset($src[$f]) && isset($in[$k][$f])) { $val = mb_substr(trim(str_replace("\r", '', (string)$in[$k][$f])), 0, $max); if ($val !== '') $x[$f] = $val; }
        if ($x) { $prev = (array)($I['tr'][$l]['b'][$k] ?? []); $ph = (string)($prev['h'] ?? ''); unset($prev['h']);   // the original it was made from: renewed when the translator changed the words or ticked «still right»
            $x['h'] = $ph === '' || !empty($in[$k]['seen']) || $prev != $x ? nl_block_sig($b) : $ph; $B[$k] = $x; } }
    $tr = $I['tr']; $tr[$l] = ['subject' => preg_replace('/[\r\n]+/', ' ', $s('subject', 200)), 'preheader' => $s('preheader', 200), 'ok' => empty($_POST['ok']) ? 0 : 1, 'b' => $B];
    nl_tr_store($id, $tr); return $l;
}
function nl_tr_remove(int $id): string { $I = nl_issue($id); if (!$I) throw new RuntimeException(t('err.notfound')); nl_editable($I); $l = nl_tr_lang_post($I); $tr = $I['tr']; unset($tr[$l]); nl_tr_store($id, $tr); return $l; }
/** A first translation by the assistant, for a person to read before it is marked ready. Only the words of the newsletter leave the portal — no reader, no address. */
function nl_tr_ai(int $id): array {
    if (!nl_tr_has()) throw new RuntimeException(t('nl.tr_need')); $I = nl_keys_ensure($id); nl_editable($I); if (!nl_ai_on()) throw new RuntimeException(t('nl.ai_off')); $l = nl_tr_lang_post($I);
    $src = ['subject' => (string)$I['subject'], 'preheader' => (string)($I['preheader'] ?? ''), 'blocks' => []];
    foreach ($I['blocks'] as $b) if ($w = nl_block_words($b)) $src['blocks'][] = ['k' => (string)$b['k']] + $w;
    if (!$src['blocks'] && trim($src['subject']) === '') throw new RuntimeException(t('nl.empty'));
    $prompt = 'Translate this company newsletter of Golden A/S (marine electrical, automation and navigation systems integrator) from ' . (NL_LANG_EN[$I['lang']] ?? 'English') . ' into ' . NL_LANG_EN[$l] . ', for business readers of the marine industry. '
            . 'Professional, natural wording of a native speaker; the formal form of address. Keep unchanged: company, vessel, product and person names, numbers, dates, units, e-mail addresses, every https:// address, and the ** marks around bold words. Keep blank lines between paragraphs. Add nothing and leave nothing out. '
            . 'The subject stays under 70 characters and the preheader under 110 where the language allows. Answer with JSON only, in exactly the shape of the input, with the same "k" values: {"subject":"…","preheader":"…","blocks":[{"k":"…","text":"…","title":"…","label":"…"}]} — give for each block only the fields it has in the input.' . "\n\n" . json_encode($src, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $res = ai_run('write', ['title' => t('nl.title'), 'headline' => (string)$I['title'] . ' → ' . strtoupper($l)], $prompt, null, 'newsletter'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
    $j = ai_json((string)$res['text']); if (!$j || !is_array($j['blocks'] ?? null)) throw new RuntimeException(t('ai.failed'));
    $by = []; foreach ($j['blocks'] as $x) if (is_array($x) && isset($x['k']) && is_string($x['k'])) $by[$x['k']] = $x;
    $B = []; foreach ($I['blocks'] as $b) { $k = (string)$b['k']; $w = nl_block_words($b); if (!$w || !isset($by[$k])) continue; $x = [];
        foreach (['text' => 6000, 'title' => 200, 'label' => 80] as $f => $max) if (isset($w[$f]) && isset($by[$k][$f]) && is_string($by[$k][$f]) && trim($by[$k][$f]) !== '') $x[$f] = mb_substr(trim(str_replace("\r", '', $by[$k][$f])), 0, $max);
        if ($x) { $x['h'] = nl_block_sig($b); $B[$k] = $x; } }
    if (!$B) throw new RuntimeException(t('ai.failed'));
    $one = fn($v, int $max): string => is_string($v) ? mb_substr(trim((string) preg_replace('/[\r\n]+/', ' ', $v)), 0, $max) : '';
    $tr = $I['tr']; $tr[$l] = ['subject' => $one($j['subject'] ?? '', 200), 'preheader' => $one($j['preheader'] ?? '', 200), 'ok' => 0, 'b' => $B, 'ai' => 1];   // never ready by itself: a person reads it first
    nl_tr_store($id, $tr); return [$l, count($B)];
}
/** The language of every reader: the contact's own, else the one chosen at the website sign-up, else English. [email => lang] */
function nl_rcpt_langs(array $rc, string $site): array {
    $o = []; $ids = []; foreach ($rc as $r) { $o[$r['email']] = 'en'; if (!empty($r['contact_id'])) $ids[(int)$r['contact_id']] = $r['email']; }
    try { $sb = []; foreach (all("SELECT LOWER(email) e, lang FROM nl_subs WHERE status = 'confirmed' AND lang IS NOT NULL AND lang <> ''" . nl_w($site, $sb), $sb) as $x) if (isset($o[$x['e']]) && isset(NL_LANGS[(string)$x['lang']])) $o[$x['e']] = (string)$x['lang']; } catch (Throwable $e) {}
    try { foreach (array_chunk(array_keys($ids), 500) as $ch) foreach (all('SELECT id, nl_lang FROM contacts WHERE nl_lang IS NOT NULL AND nl_lang <> \'\' AND id IN (' . implode(',', array_map('intval', $ch)) . ')') as $x) if (isset(NL_LANGS[(string)$x['nl_lang']])) $o[$ids[(int)$x['id']]] = (string)$x['nl_lang']; } catch (Throwable $e) {}
    return $o;
}
/** How many readers of the audience would get each language as things stand now. [lang => n] */
function nl_lang_counts(array $I, array $rc): array {
    if (!nl_tr_has()) return []; $ready = array_flip(nl_tr_ready($I)); $c = [];
    foreach (nl_rcpt_langs($rc, nl_site_of($I)) as $l) { $l = isset($ready[$l]) ? $l : (string)$I['lang']; $c[$l] = ($c[$l] ?? 0) + 1; }
    arsort($c); return $c;
}

/* ---------------------------------------------------------------- actions */
function nl_handle_action(string $action): bool {
    if (!str_starts_with($action, 'nl_')) return false; require_staff(); if (!nl_can() && !nl_hr_can()) throw new RuntimeException(t('err.noaccess'));
    $id = (int)($_POST['id'] ?? 0); $back = fn(string $a = '') => redirect('newsletter', ['id' => $id, '_r' => uniqid()], $a);
    /* R97-160: an issue is touched only by those of its department; lists, subscribers and sending settings stay with sales / management */
    $I0 = $id ? nl_issue($id) : null; if ($I0 && !nl_can_issue($I0)) throw new RuntimeException(t('err.noaccess'));
    if (in_array($action, ['nl_list_save', 'nl_list_delete', 'nl_suball', 'nl_autocat', 'nl_settings', 'nl_optin'], true) && !nl_can()) throw new RuntimeException(t('err.noaccess'));
    if (!in_array($action, ['nl_create', 'nl_import', 'nl_hr_partner'], true) && !$I0 && !nl_can()) throw new RuntimeException(t('err.noaccess'));
    /* R97-051: an issue waiting for approval is locked — its form is not saved again; a change to an approved issue cancels the approval. */
    $sv = function () use ($id): void { $I = nl_issue($id); if ($I && ($I['appr_state'] ?? '') === 'pending') return; nl_save_from_post($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); };
    switch ($action) {
        case 'nl_appr_request': $sv(); nl_appr_request($id, (string)($_POST['appr_note'] ?? '')); flash('good', t('nl.ap_requested')); $back('#approval');
        case 'nl_appr_withdraw': nl_appr_withdraw($id); flash('good', t('nl.ap_withdrawn')); $back('#approval');
        case 'nl_appr_approve': nl_appr_decide($id, true, (string)($_POST['appr_reply'] ?? '')); flash('good', t('nl.ap_approved')); $back('#approval');
        case 'nl_appr_approve_send': nl_appr_decide($id, true, (string)($_POST['appr_reply'] ?? '')); $n = nl_queue($id); flash('good', t('nl.queued', ['n' => $n])); $back('#stats');
        case 'nl_appr_return': nl_appr_decide($id, false, (string)($_POST['appr_reply'] ?? '')); flash('good', t('nl.ap_returned')); $back('#approval');
        case 'nl_create': $nd = ($_POST['dept'] ?? '') === 'hr' ? 'hr' : 'sales'; if (!nl_can_dept($nd)) throw new RuntimeException(t('err.noaccess')); $id = nl_create((string)($_POST['title'] ?? ''), (string)($_POST['site'] ?? 'golden'), $nd); flash('good', t('nl.created')); redirect('newsletter', ['id' => $id]);
        case 'nl_import': $nd = ($_POST['dept'] ?? '') === 'hr' ? 'hr' : 'sales'; if (!nl_can_dept($nd)) throw new RuntimeException(t('err.noaccess')); [$id, $nb, $np] = nl_import_from_post($nd, (string)($_POST['site'] ?? 'golden')); flash('good', t('nl.imp_done', ['b' => $nb, 'p' => $np])); redirect('newsletter', ['id' => $id]);   // R97-175
        case 'nl_web': $on = nl_web_toggle($id); flash('good', t($on ? 'nl.web_done_on' : 'nl.web_done_off')); $back();   // R97-176
        case 'nl_save': nl_save_from_post($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); flash('good', t('nl.saved')); $back(!empty($_POST['add']) ? '#blocks' : '');
        case 'nl_hr_partner': nl_hr_partner_add_from_post(); flash('good', t('nl.hr_p_added')); redirect('hr_newsletters', ['_r' => uniqid()], '#partners');
        case 'nl_copy': $id = nl_copy($id); flash('good', t('nl.copied')); redirect('newsletter', ['id' => $id]);
        case 'nl_delete': nl_delete($id); flash('good', t('nl.deleted')); redirect(nl_dept($I0) === 'hr' ? 'hr_newsletters' : 'newsletters', ['_r' => uniqid()]);
        case 'nl_ai': $sv(); $n = nl_ai_draft($id, (string)($_POST['brief'] ?? ''), !empty($_POST['news']), !empty($_POST['replace'])); flash('good', t('nl.ai_done', ['n' => $n])); $back('#blocks');
        case 'nl_test': $sv(); $to = nl_send_test($id); flash('good', t('nl.test_sent', ['e' => $to])); $back();
        /* R97-203: language versions */
        case 'nl_tr_save': $l = nl_tr_save_from_post($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); flash('good', t('nl.saved')); redirect('newsletter', ['id' => $id, 'tl' => $l, '_r' => uniqid()]);
        case 'nl_tr_test': $l = nl_tr_save_from_post($id); $to = nl_send_test($id, $l); flash('good', t('nl.test_sent', ['e' => $to])); redirect('newsletter', ['id' => $id, 'tl' => $l, '_r' => uniqid()]);
        case 'nl_tr_ai': if (empty($_POST['w']) && !empty($_POST['title'])) $sv(); [$l, $n] = nl_tr_ai($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); flash('good', t('nl.tr_ai_done', ['n' => $n, 'l' => NL_LANGS[$l]])); redirect('newsletter', ['id' => $id, 'tl' => $l, '_r' => uniqid()]);
        case 'nl_tr_remove': $l = nl_tr_remove($id); if (nl_appr_recheck($id)) flash('info', t('nl.ap_reset')); flash('good', t('nl.tr_removed', ['l' => NL_LANGS[$l]])); $back('#langs');
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

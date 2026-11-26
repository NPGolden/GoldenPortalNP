<?php
/**
 * Acceptance by e-mail link (GAS-CP-R97-058) — for customers who do not use the portal.
 *
 * A member of staff sends a document for acceptance to one e-mail address. The recipient gets one personal link
 * (random token, only its SHA-256 is stored), opens it on customer/sign.php without an account, confirms the mailbox
 * with a 6-digit code and accepts or sends the document back — with name, capacity and, for reports, a drawn signature.
 *
 * Documents: fsr (service report), fc (function check), offer, oa (owner approval — drawings and other documents).
 * Validity: service report 24 hours; offer until its validity date, otherwise 14 days; everything else 14 days.
 * The link stops working when it is used, cancelled, expired, replaced by a reminder, or the document is decided elsewhere.
 */
declare(strict_types=1);

const DL_DOCS = ['fsr', 'fc', 'offer', 'oa', 'rcoffer'];   // R97-246: rcoffer = the employment offer to a candidate (recruiting)
const DL_CODE_MIN = 15;          // minutes a code is valid
const DL_CODE_TRIES = 5;         // wrong attempts per code
const DL_CODE_SENDS = 6;         // codes per link
const DL_FAILS = 15;             // wrong attempts per link, then the link is closed
const DL_PASS_MIN = 60;          // minutes the confirmed mailbox stays confirmed

function dl_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'doc_links'") && function_exists('sgn_has') && sgn_has(); } catch (Throwable $e) { $h = false; } } return $h; }
function dl_hash(string $s): string { return hash('sha256', $s); }
function dl_token(): string { $a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz'; $s = ''; foreach (str_split(random_bytes(40)) as $c) $s .= $a[ord($c) % 62]; return $s; }
function dl_ip(): string { return function_exists('sgn_ip') ? sgn_ip() : ''; }
function dl_mask(string $email): string { [$l, $d] = array_pad(explode('@', $email, 2), 2, ''); return mb_substr($l, 0, 1) . str_repeat('•', max(2, min(6, mb_strlen($l) - 1))) . '@' . $d; }
/** Address of the customer/ folder — also when the call comes from the mobile API (…/customer/api/v1).   R97-060 */
function dl_base(): string {
    $b = trim((string) cfg('app.base_url', ''));
    if ($b === '' || str_contains($b, '127.0.0.1') || str_contains($b, 'localhost')) { $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'); $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'goldensas.com'));
        $dir = preg_replace('#/api/v1$#', '', rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/customer/index.php')), '/')); $b = ($https ? 'https' : 'http') . '://' . $host . $dir; }
    return rtrim($b, '/');
}
function dl_url(string $token): string { return dl_base() . '/sign.php?t=' . $token; }
/* ------------------------------------------------------------------ R97-258: self-test of the public pages through the website firewall */
/** One request the way a phone browser sends it. [status, headers (lower case), body start] */
function sec_pub_req(string $url, bool $post, ?string $referer, array $hdr = [], ?string $body = null): array {
    if (!function_exists('curl_init')) return [0, '', '']; $ch = curl_init($url); $h = array_merge(['Accept: text/html,application/xhtml+xml,application/json', 'Accept-Language: en'], $hdr); if ($referer) $h[] = 'Referer: ' . $referer;
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HTTPHEADER => $h,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1']);
    if ($post) { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? 'a=selftest'); }
    $raw = (string) curl_exec($ch); $st = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $hs = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$st, strtolower(substr($raw, 0, $hs)), substr($raw, $hs, 6000)];
}
/** The public doors of the portal, asked through the public address the way a browser or app does — with nothing that can be written:
 *  the acceptance page and the unsubscribe page (a link that does not exist), the sign-in address of the apps (no app version: answered «update required» before any sign-in),
 *  the careers and website forms of goldenas.com.gr (no site key: answered «forbidden» by the portal), and the five app pages.
 *  'ok' false = somebody would meet «Access Denied» or a page sends no referrer. 'oneclick' = whether a POST without referrer (the one-click unsubscribe of mail providers) passes. 'n' = doors tested. */
function sec_pubtest(): array {
    $base = dl_base(); $o = ['at' => date('Y-m-d H:i'), 'ok' => true, 'issues' => [], 'oneclick' => null, 'n' => 0];
    if (!str_starts_with($base, 'https://') || !function_exists('curl_init')) { $o['ok'] = null; $o['issues'][] = 'not run'; return $o; }
    $fw = fn(array $r) => $r[0] === 403 && (stripos($r[2], 'Website Firewall') !== false || stripos($r[2], 'sucuri') !== false || preg_match('/Block ID|Access Denied/i', $r[2])); $id = fn(array $r) => preg_match('/\b([A-Z]{2,6}\d{2,4})\b/', strip_tags($r[2]), $m) ? $m[1] : '403';
    $noref = fn(array $r) => (bool) preg_match('/^referrer-policy:\s*no-referrer\s*$/m', $r[1]) || (bool) preg_match('/<meta[^>]+name=["\']referrer["\'][^>]+content=["\']no-referrer["\']/i', $r[2]);
    $bad = function (string $what) use (&$o) { $o['ok'] = false; $o['issues'][] = $what; }; $dead = function (string $what) use (&$o) { if ($o['ok'] !== false) $o['ok'] = null; $o['issues'][] = $what . ': no answer'; };
    foreach (['sign.php?t=' . str_repeat('a', 40), 'nl.php'] as $path) { $u = $base . '/' . $path; $name = strtok($path, '?'); $o['n']++;
        $g = sec_pub_req($u, false, null); if ($g[0] === 0) { $dead($name); continue; }
        if ($fw($g)) { $bad($name . ': GET ' . $id($g)); continue; }
        if ($noref($g)) $bad($name . ': Referrer-Policy no-referrer');
        $p = sec_pub_req($u, true, $u); if ($fw($p)) $bad($name . ': POST ' . $id($p));
        if ($name === 'nl.php') { $q = sec_pub_req($u, true, null); $o['oneclick'] = $q[0] === 0 ? null : !$fw($q); }
    }
    // R97-259: the wider test — app sign-in, forms of the website, app pages
    $site = rtrim((string) (function_exists('cp_setting') ? cp_setting('rc_site_origin', 'https://goldenas.com.gr') : 'https://goldenas.com.gr'), '/'); if (!preg_match('#^https://[a-z0-9.\-]+$#i', $site)) $site = 'https://goldenas.com.gr';
    $json = ['Content-Type: application/json'];
    foreach ([['app sign-in', $base . '/api/v1/auth/login', $base . '/mobile-pm/', array_merge($json, ['X-App: pm'])],
              ['careers form', $base . '/rcr_feed.php?r=apply', $site . '/', array_merge($json, ['Origin: ' . $site])],
              ['website form', $base . '/web_feed.php?r=ticket', $site . '/', array_merge($json, ['Origin: ' . $site])]] as [$name, $u, $ref, $h]) { $o['n']++;
        $p = sec_pub_req($u, true, $ref, $h, '{}'); if ($p[0] === 0) { $dead($name); continue; } if ($fw($p)) $bad($name . ': POST ' . $id($p)); elseif ($p[0] >= 500) $bad($name . ': ' . $p[0]); }
    foreach (['mobile' => 'Service', 'mobile-pm' => 'Golden PM', 'mobile-hr' => 'Golden HR', 'mobile-employee' => 'Employee', 'mobile-customer' => 'Customer'] as $dir => $name) { if (!is_dir(dirname(__DIR__) . '/' . $dir)) continue; $o['n']++;
        $g = sec_pub_req($base . '/' . $dir . '/', false, null); if ($g[0] === 0) { $dead($name . ' app'); continue; }
        if ($fw($g)) $bad($name . ' app: GET ' . $id($g)); elseif ($g[0] >= 400) $bad($name . ' app: ' . $g[0]); elseif ($noref($g)) $bad($name . ' app: no-referrer'); }
    return $o;
}
/** Once a day from the scheduler (after 07:00): the result is kept for Diagnostics, and the administrators hear when a customer would be refused. */
function sec_pubtest_daily(): ?string {
    if (!function_exists('cp_setting')) return null; $today = date('Y-m-d'); $last = (array) json_decode((string) cp_setting('sec_pubtest', ''), true);
    if ((int) date('G') < 7 || str_starts_with((string)($last['at'] ?? ''), $today)) return null;
    $r = sec_pubtest(); cp_setting_set('sec_pubtest', json_encode($r));
    if ($r['ok'] === false && function_exists('nf_push') && function_exists('nf_has') && nf_has()) { $admins = array_map('intval', array_column(all("SELECT id FROM customer_users WHERE role = 'admin' AND is_active = 1"), 'id'));
        if ($admins) nf_push($admins, 'sec:pubtest', 'sec:pubtest:' . $today, t('nf.sec_pub_t'), t('nf.sec_pub_b', ['i' => implode(' · ', $r['issues'])]), url('admin_about', ['diag' => 1]), null, null); }
    return $r['ok'] === null ? 'skipped' : ($r['ok'] ? 'ok' : 'refused');
}
/** A signed-in member of staff — without the redirect of require_staff(), so the mobile API can call the same functions. */
function dl_staff(): array { $u = current_user(); if (!$u || !is_staff()) throw new RuntimeException(t('err.noaccess')); return $u; }

/** The document as the current account sees it (staff, or the sender the public page runs as), in one shape for every kind. */
function dl_info(string $doc, int $id): ?array {
    if (!in_array($doc, DL_DOCS, true) || $id <= 0 || !is_staff()) return null;
    if ($doc === 'fsr') { $r = fsr_load($id); if (!$r || ($r['kind'] ?? 'internal') === 'external') return null;
        return ['doc' => $doc, 'id' => $id, 'r' => $r, 'no' => (string)$r['report_no'], 'title' => t('fsr.title'), 'open' => $r['status'] === 'submitted', 'file' => (int)($r['pdf_file_id'] ?? 0), 'customer_id' => (int)$r['customer_id'], 'customer' => (string)(($r['customer_name'] ?? '') ?: ($r['customer_db_name'] ?? '')), 'vessel' => (string)($r['vessel_name'] ?? ''), 'valid_until' => null, 'back' => url('fsr', ['id' => $id])]; }
    if ($doc === 'fc') { if (!function_exists('fc_has') || !fc_has()) return null; $r = fc_load($id); if (!$r) return null;
        return ['doc' => $doc, 'id' => $id, 'r' => $r, 'no' => (string)$r['report_no'], 'title' => (string)(($r['tpl']['title'] ?? '') ?: t('fc.title')), 'open' => $r['status'] === 'submitted', 'file' => (int)($r['pdf_file_id'] ?? 0), 'customer_id' => (int)$r['customer_id'], 'customer' => (string)(($r['customer_name'] ?? '') ?: ($r['customer_db_name'] ?? '')), 'vessel' => (string)($r['vessel_name'] ?? ''), 'valid_until' => null, 'back' => url('fc', ['id' => $id])]; }
    if ($doc === 'offer') { if (!function_exists('of_has') || !of_has()) return null; $r = of_load($id); if (!$r) return null;
        return ['doc' => $doc, 'id' => $id, 'r' => $r, 'no' => trim((string)$r['offer_no'] . ' ' . (string)$r['revision']), 'title' => t('of.one'), 'open' => of_is_pending($r), 'file' => (int)($r['file_id'] ?? 0), 'customer_id' => (int)$r['customer_id'], 'customer' => (string)($r['customer_name'] ?? ''), 'vessel' => (string)($r['vessel'] ?? ''), 'valid_until' => $r['valid_until'] ?: null, 'back' => url('offer', ['id' => $id])]; }
    if ($doc === 'rcoffer') { if (!function_exists('rcr_offer')) return null; $r = rcr_offer($id); if (!$r) return null; $a = $r['app']; $lc = in_array((string)($a['lang'] ?? ''), ['en', 'el', 'it'], true) ? (string)$a['lang'] : 'en';
        return ['doc' => $doc, 'id' => $id, 'r' => $r, 'no' => (string)$r['offer_no'], 'title' => t_in('rc.of_doc', $lc), 'open' => $r['status'] === 'sent', 'file' => 0, 'customer_id' => 0, 'customer' => '', 'vessel' => '', 'valid_until' => $r['valid_until'] ?: null, 'back' => url('rcr_application', ['id' => (int)$r['application_id']]), 'lang' => $lc, 'to_email' => strtolower(trim((string)$a['email'])), 'to_name' => (string)($a['name'] ?? '')]; }
    $r = oa_load($id); if (!$r) return null;
    return ['doc' => $doc, 'id' => $id, 'r' => $r, 'no' => trim((string)$r['doc_no'] . ' ' . (string)$r['revision']), 'title' => t('oa.one'), 'open' => in_array($r['status'], oa_open_statuses(), true), 'file' => (int)($r['file_id'] ?? 0), 'customer_id' => (int)$r['customer_id'], 'customer' => (string)($r['customer_name'] ?? ''), 'vessel' => (string)($r['vessel'] ?? ''), 'valid_until' => null, 'back' => url('approval', ['id' => $id])];
}
/** When the link ends — as a number of seconds from now. */
function dl_ttl(array $I): int {
    if ($I['doc'] === 'fsr') return 24 * 3600;
    if (in_array($I['doc'], ['offer', 'rcoffer'], true) && $I['valid_until']) { $left = strtotime($I['valid_until'] . ' 23:59:59') - time(); if ($left > 3600) return $left; }
    return 14 * 86400;
}
/** Plain-text mail with optional attachments, from the portal's mailbox, replies to the sender of the document. */
function dl_mail(string $to, string $subject, string $body, ?string $replyTo = null, array $atts = []): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    try {
        if (function_exists('cp_graph_configured') && cp_graph_configured()) return cp_graph_send((string) cfg('graph.from'), 'Golden A/S', $to, $subject, $body, $replyTo, $atts);
        return cp_mail($to, $subject, $body, 'Golden A/S', null, $replyTo);
    } catch (Throwable $e) { error_log('[dl] mail: ' . $e->getMessage()); return false; }
}
function dl_att(int $fileId, string $fallback): array { if (!$fileId) return []; $b = cp_file_bytes($fileId); if ($b === null || strlen($b) > 3 * 1024 * 1024) return []; $f = one('SELECT original_name, mime_type FROM sp_files WHERE id = ?', [$fileId]); return [['name' => (string)(($f['original_name'] ?? '') ?: $fallback), 'bytes' => $b, 'mime' => (string)(($f['mime_type'] ?? '') ?: 'application/pdf')]]; }
function dl_valid_text(array $L, string $lang): string { return fmt_datetime((string)$L['expires_at']); }
function dl_mail_link(array $L, string $token, bool $reminder = false, array $atts = []): bool {
    $lg = (string)$L['lang']; $v = ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no'], 'u' => (string)$L['created_name'], 'n' => (string)($L['name'] ?: ''), 'url' => dl_url($token), 'd' => fmt_datetime((string)$L['expires_at'])];
    $reply = (string) scalar('SELECT email FROM customer_users WHERE id = ?', [(int)$L['created_by']]);
    return dl_mail((string)$L['email'], t_in($reminder ? 'dl.m_remind_s' : 'dl.m_link_s', $lg, $v), t_in($v['n'] !== '' ? 'dl.m_hello' : 'dl.m_hello0', $lg, $v) . "\n\n" . t_in($reminder ? 'dl.m_remind_b' : 'dl.m_link_b', $lg, $v) . "\n\n" . $v['url'] . "\n\n" . t_in('dl.m_link_f', $lg, $v) . "\n\n" . t_in('dl.m_sign', $lg, $v), $reply ?: null, $atts);
}

/* ---- R97-059: delivery problems are kept on the link, shown in the card and told to the sender ---- */
function dl_err_has(): bool { static $h = null; if ($h === null) { try { $h = dl_has() && (bool) one("SHOW COLUMNS FROM doc_links LIKE 'mail_error'"); } catch (Throwable $e) { $h = false; } } return $h; }
function dl_mail_reason(): string { $r = trim((string)($GLOBALS['cp_graph_last_error'] ?? '')); return $r !== '' ? $r : 'the mail server did not accept the message'; }
/** A mail of this link went out ($ok) or not: the problem is stored with its step (link, code, reminder, confirmation) and the sender is told once. */
function dl_mail_result(array $L, string $step, bool $ok): void {
    if (!dl_err_has()) return;
    if ($ok) { if ($step !== 'confirmation') q('UPDATE doc_links SET mail_error = NULL, mail_error_at = NULL WHERE id = ? AND mail_error LIKE ?', [(int)$L['id'], $step . ':%']); return; }
    $first = !scalar('SELECT COUNT(*) FROM doc_links WHERE id = ? AND mail_error LIKE ?', [(int)$L['id'], $step . ':%']);
    q('UPDATE doc_links SET mail_error = ?, mail_error_at = NOW() WHERE id = ?', [mb_substr($step . ': ' . dl_mail_reason(), 0, 255), (int)$L['id']]);
    if ($first) dl_tell($L, t('dl.nf_mail', ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']]), t('dl.st_' . $step) . ' → ' . $L['email'] . ' — ' . dl_mail_reason());
}
/** Notification to the member of staff who sent the link. */
function dl_tell(array $L, string $title, string $body): void {
    if (!function_exists('nf_push') || !(int)$L['created_by']) return; $page = ['fsr' => 'fsr', 'fc' => 'fc', 'offer' => 'offer', 'oa' => 'approval'][(string)$L['doc']] ?? 'dashboard';
    try { nf_push([(int)$L['created_by']], 'dl:problem', 'dl:' . $L['doc'] . ':' . $L['doc_id'] . ':' . $L['id'], $title, $body, url($page, ['id' => (int)$L['doc_id']]) . '#dl', null, 'Golden A/S'); } catch (Throwable $e) {}
}
/** What staff should know about a link: ['tone', 'label', 'detail', 'do'] or null when all is well. */
function dl_problem(array $L): ?array {
    if (!dl_err_has() || $L['used_at']) { return dl_err_has() && $L['used_at'] && str_starts_with((string)($L['mail_error'] ?? ''), 'confirmation:') ? ['tone' => 'gold', 'label' => t('dl.pr_mail'), 'detail' => t('dl.st_confirmation') . ' — ' . substr((string)$L['mail_error'], 14), 'do' => t('dl.do_confirm')] : null; }
    if (!empty($L['superseded'])) return null;   // a newer link went to the same address — the old trouble is dealt with
    if ($L['cancelled_at'] && ($L['cancel_note'] ?? '') !== 'locked') return null;   // replaced, cancelled by staff, decided another way: nothing to do
    if (!empty($L['bounced_at'])) return ['tone' => 'bad', 'label' => t('dl.pr_bounced'), 'detail' => (string)$L['bounce_reason'], 'do' => t('dl.do_address')];
    if (!empty($L['mail_error'])) { [$step, $why] = array_pad(explode(': ', (string)$L['mail_error'], 2), 2, ''); return ['tone' => 'bad', 'label' => t('dl.pr_mail'), 'detail' => t('dl.st_' . $step) . ' — ' . $why . ' · ' . fmt_datetime((string)$L['mail_error_at']), 'do' => t($step === 'confirmation' ? 'dl.do_confirm' : 'dl.do_resend')]; }
    if (($L['cancel_note'] ?? '') === 'locked') return ['tone' => 'bad', 'label' => t('dl.pr_locked'), 'detail' => t('dl.cn_locked'), 'do' => t('dl.do_resend')];
    if ((int)($L['is_expired'] ?? 0) && !$L['cancelled_at']) return ['tone' => 'gold', 'label' => t('dl.pr_expired'), 'detail' => '', 'do' => t('dl.do_resend')];
    return null;
}
/** Messages returned by the recipient's mail server (wrong address, full mailbox, refused): read from the inbox of the portal's mailbox.
 *  Needs the Microsoft 365 permission the portal already uses for drafts; without it the check is skipped silently. */
function dl_bounce_check(): int {
    if (!dl_err_has() || !function_exists('cp_graph_configured') || !cp_graph_configured() || !function_exists('cp_graph_call')) return 0;
    $open = all("SELECT * FROM doc_links WHERE bounced_at IS NULL AND used_at IS NULL AND cancelled_at IS NULL AND created_at > NOW() - INTERVAL 3 DAY ORDER BY id DESC LIMIT 40"); if (!$open) return 0;
    $since = gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400);
    try { [$code, $j] = cp_graph_call('GET', '/users/' . rawurlencode((string) cfg('graph.from')) . '/mailFolders/inbox/messages?$top=50&$orderby=' . rawurlencode('receivedDateTime desc') . '&$filter=' . rawurlencode('receivedDateTime ge ' . $since) . '&$select=subject,bodyPreview,from,receivedDateTime', null, 20); } catch (Throwable $e) { return 0; }
    if ($code !== 200 || empty($j['value'])) return 0; $n = 0;
    foreach (dl_bounce_match((array)$j['value'], $open) as $lid => $why) { $L = null; foreach ($open as $x) if ((int)$x['id'] === $lid) $L = $x; if (!$L) continue;
        q('UPDATE doc_links SET bounced_at = NOW(), bounce_reason = ? WHERE id = ? AND bounced_at IS NULL', [$why, $lid]);
        dl_tell($L, t('dl.nf_bounce', ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']]), $L['email'] . ' — ' . $why); $n++; }
    return $n;
}
/** Which open links do these inbox messages speak of? Only messages from a mail system count (postmaster, mailer-daemon, Microsoft Exchange),
 *  and the message must name the recipient's address. Returns [link id => reason]. */
function dl_bounce_match(array $messages, array $open): array {
    $out = [];
    foreach ($messages as $m) {
        $from = strtolower((string)($m['from']['emailAddress']['address'] ?? '') . ' ' . (string)($m['from']['emailAddress']['name'] ?? '')); $subj = (string)($m['subject'] ?? ''); $text = strtolower($subj . ' ' . (string)($m['bodyPreview'] ?? ''));
        if (!preg_match('/postmaster|mailer-daemon|microsoftexchange|microsoft outlook|mail delivery/', $from)) continue;
        foreach ($open as $L) { $lid = (int)$L['id']; if (isset($out[$lid]) || !str_contains($text, strtolower((string)$L['email']))) continue;
            $out[$lid] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($m['bodyPreview'] ?? '') ?: $subj)), 0, 250); break; }
    }
    return $out;
}
/** Links that ran out without an answer: the sender is told once. */
function dl_expiry_tell(): int {
    if (!dl_err_has()) return 0; $n = 0;
    foreach (all("SELECT * FROM doc_links WHERE used_at IS NULL AND cancelled_at IS NULL AND expiry_told = 0 AND expires_at < NOW() AND expires_at > NOW() - INTERVAL 7 DAY ORDER BY id LIMIT 20") as $L) {
        q('UPDATE doc_links SET expiry_told = 1 WHERE id = ?', [(int)$L['id']]); dl_tell($L, t('dl.nf_expired', ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']]), $L['email'] . ' — ' . t('dl.do_resend')); $n++; }
    return $n;
}

/** Send a document for acceptance. Returns [link id, token] — the token is never stored and is not shown to staff. */
function dl_create(string $doc, int $id, string $email, string $name, bool $send = true): array {
    $u = dl_staff(); if (!dl_has()) throw new RuntimeException(t('dl.not_ready'));
    $I = dl_info($doc, $id); if (!$I) throw new RuntimeException(t('err.notfound')); if (!$I['open']) throw new RuntimeException(t('dl.not_open'));
    $email = strtolower(trim($email)); if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new RuntimeException(t('dl.bad_email'));
    if ($send && !(function_exists('cp_mail_configured') && cp_mail_configured())) throw new RuntimeException(t('dl.no_mail'));
    if (!$I['file'] && $doc !== 'rcoffer') throw new RuntimeException(t('dl.no_file'));   // the recipient must be able to read what is accepted (the employment offer is shown as its text)
    if ($doc === 'rcoffer' && $email !== (string)$I['to_email']) throw new RuntimeException(t('rc.of_dl_only'));   // an employment offer is accepted by the candidate, at the address of the application
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 120);
    if ((int) scalar('SELECT COUNT(*) FROM doc_links WHERE doc = ? AND doc_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$doc, $id]) >= 10) throw new RuntimeException(t('dl.too_many'));
    q("UPDATE doc_links SET cancelled_at = NOW(), cancel_note = 'replaced' WHERE doc = ? AND doc_id = ? AND email = ? AND used_at IS NULL AND cancelled_at IS NULL", [$doc, $id, $email]);
    $token = dl_token();
    q('INSERT INTO doc_links (doc, doc_id, doc_no, doc_title, token_hash, email, name, lang, created_by, created_name, expires_at) VALUES (?,?,?,?,?,?,?,?,?,?, NOW() + INTERVAL ? SECOND)',
      [$doc, $id, mb_substr($I['no'], 0, 80), mb_substr($I['title'], 0, 160), dl_hash($token), $email, $name ?: null, $I['lang'] ?? (in_array(locale(), ['en', 'el', 'it'], true) ? locale() : 'en'), (int)$u['id'], (string)$u['full_name'], dl_ttl($I)]);
    $lid = (int) db()->lastInsertId(); $L = one('SELECT * FROM doc_links WHERE id = ?', [$lid]);
    if ($send && !dl_mail_link($L, $token, false, dl_att($I['file'], $I['no'] . '.pdf'))) { q('DELETE FROM doc_links WHERE id = ?', [$lid]); throw new RuntimeException(t('dl.mail_failed') . ' (' . dl_mail_reason() . ')'); }
    return [$lid, $token];
}
function dl_list(string $doc, int $id): array { return dl_has() ? all("SELECT l.*, (l.expires_at < NOW()) AS is_expired, EXISTS (SELECT 1 FROM doc_links n WHERE n.doc = l.doc AND n.doc_id = l.doc_id AND n.email = l.email AND n.id > l.id) AS superseded FROM doc_links l WHERE l.doc = ? AND l.doc_id = ? ORDER BY l.id DESC LIMIT 20", [$doc, $id]) : []; }
/** R97-059: 'problem' when the confirmation could not be sent is a note, not a state — see dl_problem(). */
function dl_state_of(array $L): string { return $L['used_at'] ? 'used' : ($L['cancelled_at'] ? 'cancelled' : ((int)($L['is_expired'] ?? 0) ? 'expired' : ($L['verified_at'] ? 'verified' : ($L['opened_at'] ? 'opened' : 'sent')))); }
function dl_cancel(int $lid): void { dl_staff(); $L = one('SELECT * FROM doc_links WHERE id = ?', [$lid]); if (!$L || !dl_info((string)$L['doc'], (int)$L['doc_id'])) throw new RuntimeException(t('err.notfound')); q("UPDATE doc_links SET cancelled_at = NOW(), cancel_note = 'staff' WHERE id = ? AND used_at IS NULL AND cancelled_at IS NULL", [$lid]); }

/* ---------------------------------------------------------------- public side (customer/sign.php) */

/** The link behind a token, with its state judged on the database clock. */
function dl_find(string $token): ?array {
    if (!dl_has() || !preg_match('/^[A-Za-z0-9]{40}$/', $token)) return null;
    return one("SELECT l.*, (l.expires_at < NOW()) AS is_expired, (l.code_hash IS NOT NULL AND l.code_expires > NOW()) AS code_live, IFNULL(TIMESTAMPDIFF(SECOND, l.code_at, NOW()), 99999) AS code_age,
                       (l.verified_at IS NOT NULL AND l.verified_at > NOW() - INTERVAL " . DL_PASS_MIN . " MINUTE) AS is_fresh FROM doc_links l WHERE l.token_hash = ?", [dl_hash($token)]);
}
/** The public page reads and regenerates the document as the member of staff who sent the link — in memory only, no session, no cookie. */
function dl_run_as(array $L): bool { $GLOBALS['cp_no_presence'] = true; $_SESSION['cu_id'] = (int)$L['created_by']; unset($_SESSION['epoch']); return (bool) current_user() && is_staff(); }
/** A new code for the link; null when the limits are reached (too soon, too many). */
function dl_code_issue(array $L): ?string {
    if ((int)$L['code_sent'] >= DL_CODE_SENDS || (int)$L['code_age'] < 45) return null;
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    q('UPDATE doc_links SET code_hash = ?, code_expires = NOW() + INTERVAL ' . DL_CODE_MIN . ' MINUTE, code_at = NOW(), code_sent = code_sent + 1, code_tries = 0, opened_at = COALESCE(opened_at, NOW()) WHERE id = ?', [dl_hash($code . '|' . $L['token_hash']), (int)$L['id']]);
    return $code;
}
function dl_code_mail(array $L, string $code): bool { $lg = (string)$L['lang']; $v = ['c' => $code, 't' => (string)$L['doc_title'], 'no' => (string)$L['doc_no'], 'm' => DL_CODE_MIN];
    $ok = dl_mail((string)$L['email'], t_in('dl.m_code_s', $lg, $v), t_in('dl.m_code_b', $lg, $v) . "\n\n" . t_in('dl.m_sign', $lg, $v)); dl_mail_result($L, 'code', $ok); return $ok; }
/** Check a typed code. On success the mailbox counts as confirmed and a pass for the browser is returned. */
function dl_code_check(array $L, string $code): ?string {
    if (!(int)$L['code_live'] || (int)$L['code_tries'] >= DL_CODE_TRIES || !preg_match('/^\d{6}$/', $code)) { if (preg_match('/^\d{6}$/', $code)) dl_fail($L); return null; }
    if (!hash_equals((string)$L['code_hash'], dl_hash($code . '|' . $L['token_hash']))) { q('UPDATE doc_links SET code_tries = code_tries + 1 WHERE id = ?', [(int)$L['id']]); dl_fail($L); return null; }
    $pass = bin2hex(random_bytes(24));
    q('UPDATE doc_links SET verified_at = NOW(), pass_hash = ?, code_hash = NULL, code_expires = NULL WHERE id = ?', [dl_hash($pass), (int)$L['id']]);
    return $pass;
}
function dl_fail(array $L): void { q('UPDATE doc_links SET fails = fails + 1 WHERE id = ?', [(int)$L['id']]); $n = q("UPDATE doc_links SET cancelled_at = NOW(), cancel_note = 'locked' WHERE id = ? AND fails >= " . DL_FAILS . " AND cancelled_at IS NULL AND used_at IS NULL", [(int)$L['id']])->rowCount(); if ($n && function_exists('dl_tell')) dl_tell($L, t('dl.nf_locked', ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']]), $L['email'] . ' — ' . t('dl.cn_locked')); }
function dl_pass_ok(array $L, string $pass): bool { return (int)$L['is_fresh'] === 1 && $pass !== '' && !empty($L['pass_hash']) && hash_equals((string)$L['pass_hash'], dl_hash($pass)); }

/** The recipient's decision. $I = dl_info() read as the sender. Returns the decision stored. */
function dl_decide(array $L, array $I, string $decision, string $name, string $role, string $remark, string $sig, bool $read): string {
    if (!$I['open']) throw new RuntimeException(t('dl.closed'));
    $doc = (string)$L['doc']; $id = (int)$L['doc_id']; $r = $I['r']; $ip = dl_ip() ?: null;
    $name = mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 90); $role = mb_substr(trim(preg_replace('/\s+/u', ' ', $role)), 0, 80); $remark = mb_substr(trim($remark), 0, 2000);
    if (mb_strlen($name) < 3) throw new RuntimeException(t('sgn.name_needed'));
    $who = $name . ($role !== '' ? ', ' . $role : ''); $by = t('dl.ev_by', ['n' => $who, 'e' => (string)$L['email']]); $note = trim($by . ($remark !== '' ? ' — ' . $remark : ''));
    $ev = function (string $table, string $event, string $text) use ($id, $name, $ip) { q("INSERT INTO {$table} (" . ($table === 'offer_events' ? 'offer_id' : 'report_id') . ", event, user_id, user_name, note, ip) VALUES (?,?,NULL,?,?,?)", [$id, $event, $name, $text, $ip]); };
    $n = 0;
    if ($doc === 'fsr' || $doc === 'fc') {
        $tbl = $doc === 'fsr' ? 'fsr_reports' : 'fc_reports'; $evt = $doc === 'fsr' ? 'fsr_events' : 'fc_events';
        if ($decision === 'disputed') { if ($remark === '') throw new RuntimeException(t('fsr.remark_needed')); $n = q("UPDATE {$tbl} SET status = 'disputed', customer_remark = ? WHERE id = ? AND status = 'submitted'", [$remark, $id])->rowCount(); if ($n) $ev($evt, 'disputed', $remark . ' — ' . $by); }
        elseif ($decision === 'accepted') {
            if (!$read) throw new RuntimeException(t('sgn.read_needed'));
            $signId = $doc === 'fsr' ? fsr_store_signature($sig, $r, 'customer') : fc_store_signature($sig, $r, 'customer'); if (!$signId) throw new RuntimeException(t('sgn.sign_needed'));
            $n = q("UPDATE {$tbl} SET status = 'accepted', accepted_by = NULL, accepted_name = ?, accepted_role = ?, accepted_via = 'email', accepted_at = NOW(), accepted_ip = ?, customer_remark = ?, customer_sign_id = ?" . ($doc === 'fc' ? ', customer_name = ' . db()->quote($name) : '') . " WHERE id = ? AND status = 'submitted'", [$name, $role ?: null, $ip, $remark ?: null, $signId, $id])->rowCount();
            if ($n) $ev($evt, 'accepted', $note);
        } else throw new RuntimeException(t('err.notfound'));
        if ($n) { if ($doc === 'fsr') fsr_generate_documents($id); else fc_generate_documents($id); }
    } elseif ($doc === 'rcoffer') {
        if (!in_array($decision, ['accepted', 'declined'], true)) throw new RuntimeException(t('err.notfound'));
        if ($decision === 'declined' && $remark === '') throw new RuntimeException(t('dl.remark_needed'));
        if ($decision === 'accepted' && !$read) throw new RuntimeException(t('sgn.read_needed'));
        $n = rcr_offer_answered($id, $decision, $name, (string)$L['email'], $remark, (string)$ip);
    } elseif ($doc === 'offer') {
        if (!in_array($decision, ['approved', 'rejected'], true)) throw new RuntimeException(t('err.notfound'));
        if ($decision === 'rejected' && $remark === '') throw new RuntimeException(t('of.reject_note_req'));
        if ($decision === 'approved' && !$read) throw new RuntimeException(t('sgn.read_needed'));
        if (function_exists('cache_bump_later')) cache_bump_later('fs');
        $n = q('UPDATE offers SET status = ?, status_at = CURDATE(), decided_by = NULL, decided_name = ?, decided_at = NOW(), decided_ip = ?, decision_note = ? WHERE id = ? AND status = ?', [$decision, mb_substr($who . ' · ' . $L['email'], 0, 120), $ip, $remark ?: null, $id, (string)$r['status']])->rowCount();
        if ($n) { if ($decision === 'rejected' && function_exists('sl_has') && sl_has()) q("UPDATE offers SET lost_reason = COALESCE(lost_reason, 'other') WHERE id = ?", [$id]); $ev('offer_events', $decision, $note); of_sync_project((int)$r['project_id']); }
    } else {
        if (!in_array($decision, ['approved', 'approved_comments', 'rejected'], true)) throw new RuntimeException(t('err.notfound'));
        if ($decision !== 'approved' && $remark === '') throw new RuntimeException(t('oa.note_req'));
        if ($decision === 'approved' && !$read) throw new RuntimeException(t('sgn.read_needed'));
        $open = "status IN ('" . implode("','", oa_open_statuses()) . "')";
        if (oa_has_r10()) {
            $n = q("UPDATE owner_approvals SET status = ?, decided_at = CURDATE(), decided_by = NULL, decided_name = ?, decided_ip = ?, decided_time = NOW(), decision_note = ?, on_behalf = 0, comments = COALESCE(?, comments) WHERE id = ? AND {$open}", [$decision, mb_substr($who . ' · ' . $L['email'], 0, 120), $ip, $remark ?: null, $remark ?: null, $id])->rowCount();
            if ($n) q('INSERT INTO oa_comments (approval_id, user_id, author, side, kind, body, ip) VALUES (?, NULL, ?, ?, ?, ?, ?)', [$id, mb_substr($who, 0, 120), 'customer', $decision, $note, $ip]);
        } else $n = q("UPDATE owner_approvals SET status = ?, decided_at = CURDATE(), comments = COALESCE(?, comments) WHERE id = ? AND {$open}", [$decision, $note, $id])->rowCount();
    }
    if (!$n) throw new RuntimeException(t('dl.closed'));
    q('UPDATE doc_links SET used_at = NOW(), used_ip = ?, decision = ?, signer_name = ?, signer_role = ?, pass_hash = NULL WHERE id = ?', [$ip, $decision, $name, $role ?: null, (int)$L['id']]);
    q("UPDATE doc_links SET cancelled_at = NOW(), cancel_note = 'decided' WHERE doc = ? AND doc_id = ? AND id <> ? AND used_at IS NULL AND cancelled_at IS NULL", [$doc, $id, (int)$L['id']]);
    try { dl_after($L, $decision, $who, $remark); } catch (Throwable $e) { error_log('[dl] after: ' . $e->getMessage()); }
    return $decision;
}
/** After a decision: tell the sender (notification and mail) and send the recipient a confirmation, with the signed report attached. */
function dl_after(array $L, string $decision, string $who, string $remark): void {
    $doc = (string)$L['doc']; $id = (int)$L['doc_id']; $ok = in_array($decision, ['accepted', 'approved', 'approved_comments'], true); $I = dl_info($doc, $id);
    $ttl = t($ok ? 'dl.nf_ok' : 'dl.nf_back', ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']]); $body = $who . ' · ' . $L['email'] . ($remark !== '' ? ' — ' . $remark : '');
    if (function_exists('nf_push') && (int)$L['created_by']) nf_push([(int)$L['created_by']], 'dl:done', 'dl:' . $doc . ':' . $id, $ttl, $body, $I ? $I['back'] : url('dashboard'), null, mb_substr($who, 0, 120));
    $staffMail = (string) scalar('SELECT email FROM customer_users WHERE id = ?', [(int)$L['created_by']]);
    if ($staffMail !== '') dl_mail($staffMail, $ttl, $body . "\n\n" . dl_base() . '/' . ($I ? $I['back'] : ''));
    $lg = (string)$L['lang']; $v = ['t' => (string)$L['doc_title'], 'no' => (string)$L['doc_no']];
    $sent = dl_mail((string)$L['email'], t_in($ok ? 'dl.m_done_s' : 'dl.m_back_s', $lg, $v), t_in($ok ? 'dl.m_done_b' : 'dl.m_back_b', $lg, $v) . "\n\n" . t_in('dl.m_sign', $lg, $v), $staffMail ?: null, $ok && $I && in_array($doc, ['fsr', 'fc'], true) ? dl_att($I['file'], $I['no'] . '.pdf') : []);
    if (!$sent && dl_err_has()) { q('UPDATE doc_links SET mail_error = ?, mail_error_at = NOW() WHERE id = ?', [mb_substr('confirmation: ' . dl_mail_reason(), 0, 255), (int)$L['id']]); dl_tell($L, t('dl.nf_mail', $v), t('dl.st_confirmation') . ' → ' . $L['email'] . ' — ' . dl_mail_reason()); }
}

/** Reminders (background sweep, every 10 minutes at most): a service report once after 8 hours; other documents after 3 and 7 days.
 *  A reminder carries a new link — the token of the earlier mail stops working. */
function dl_sweep(): int {
    if (!dl_has() || time() - (int) cp_setting('dl_sweep_at', '0') < 600) return 0; cp_setting_set('dl_sweep_at', (string) time()); $n = 0;
    $rows = all("SELECT * FROM doc_links WHERE used_at IS NULL AND cancelled_at IS NULL AND ((doc = 'fsr' AND remind_n = 0 AND created_at < NOW() - INTERVAL 8 HOUR AND expires_at > NOW() + INTERVAL 2 HOUR)
                  OR (doc <> 'fsr' AND expires_at > NOW() + INTERVAL 12 HOUR AND ((remind_n = 0 AND created_at < NOW() - INTERVAL 3 DAY) OR (remind_n = 1 AND created_at < NOW() - INTERVAL 7 DAY)))) ORDER BY id LIMIT 10");
    foreach ($rows as $L) { $token = dl_token();
        q('UPDATE doc_links SET token_hash = ?, remind_n = remind_n + 1, last_remind_at = NOW(), code_hash = NULL, pass_hash = NULL, verified_at = NULL WHERE id = ?', [dl_hash($token), (int)$L['id']]);
        $ok = dl_mail_link($L, $token, true); dl_mail_result($L, 'reminder', $ok); if ($ok) $n++; }
    try { $n += dl_bounce_check(); $n += dl_expiry_tell(); } catch (Throwable $e) { error_log('[dl] checks: ' . $e->getMessage()); }
    return $n;
}

function dl_handle_action(string $action): bool {
    if ($action !== 'dl_send' && $action !== 'dl_cancel') return false; require_staff();
    $doc = (string)($_POST['doc'] ?? ''); $id = (int)($_POST['id'] ?? 0); if ($doc !== 'rcoffer' && function_exists('hr_only') && hr_only()) throw new RuntimeException(t('err.noaccess'));   // R97-246: the HR role sends links for employment offers only
    $I = dl_info($doc, $id); if (!$I) throw new RuntimeException(t('err.notfound'));
    $page = ['fsr' => 'fsr', 'fc' => 'fc', 'offer' => 'offer', 'oa' => 'approval', 'rcoffer' => 'rcr_application'][$doc]; $backId = $doc === 'rcoffer' ? (int)$I['r']['application_id'] : $id;
    try {
        if ($action === 'dl_send') { dl_create($doc, $id, (string)($_POST['email'] ?? ''), (string)($_POST['name'] ?? '')); flash('good', t('dl.sent', ['e' => strtolower(trim((string)$_POST['email']))])); }
        else { dl_cancel((int)($_POST['link'] ?? 0)); flash('good', t('dl.cancelled')); }
    } catch (RuntimeException $e) { flash('bad', $e->getMessage()); }
    redirect($page, ['id' => $backId, '_r' => uniqid()], '#dl');
    return true;
}

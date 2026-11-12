<?php
/**
 * E-mail a document to the owner's people (GAS-CP-R31-008).
 * The portal does not send mail itself: the "✉ E-mail" card on an offer, a weekly vessel report, a service /
 * installation report and a works forecast prepares the message — recipients in To (vessel contacts, the
 * customer's portal accounts, anyone typed), subject, text and the document(s) attached — and hands it to the
 * user's mail program in two ways:
 *   · Outlook draft (.eml): a downloaded message with the attachments inside and the header X-Unsent: 1,
 *     which Outlook opens as a new, editable message ready to send;
 *   · Mail app (mailto:): the same recipients, subject and text, without attachments (the browser cannot
 *     attach files through mailto), for people whose mail program does not open .eml drafts.
 * Attachment keys:  f:<sp_files id>  ·  wk:<vessel>:<project|0>:<week-start>:<pdf|docx>  ·  wf:<forecast id>:<pdf|docx>
 */

function em_has(): bool { return function_exists('ct_has') && is_staff(); }

/** People of a vessel / customer with an e-mail: [['name','email','role','on' => bool]] — vessel contacts first (primary ones ticked), then the customer's portal accounts, then extras. */
function em_people(?int $vesselId, ?int $customerId, array $extra = [], array $preferred = []): array {
    $out = []; $seen = []; $pref = array_map('strtolower', $preferred);
    $add = function (string $name, ?string $email, string $role, bool $on) use (&$out, &$seen) {
        $email = strtolower(trim((string)$email)); if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) return;
        $seen[$email] = true; $out[] = ['name' => trim($name) ?: $email, 'email' => $email, 'role' => $role, 'on' => $on];
    };
    if ($vesselId && function_exists('ct_for_vessel')) {
        foreach (ct_for_vessel($vesselId) as $c) if (!empty($c['is_active'])) $add((string)$c['full_name'], $c['email'] ?? null, trim(($c['vessel_role'] ?? '') ?: ($c['position'] ?? '')), true);
    }
    if ($customerId) {
        foreach (all("SELECT full_name, email FROM customer_users WHERE role = 'customer' AND is_active = 1 AND customer_id = ? ORDER BY full_name", [$customerId]) as $u) $add((string)$u['full_name'], $u['email'], t('em.portal_user'), !$out);
    }
    foreach ($extra as [$n, $e, $r]) $add((string)$n, $e, (string)$r, true);
    if ($pref) { $ex = array_map(fn($x) => strtolower(trim((string)$x[1])), $extra); foreach ($out as &$p) $p['on'] = in_array($p['email'], $pref, true) || in_array($p['email'], $ex, true); unset($p); }   // R43-003: the sub-project's contacts are the ones ticked
    return $out;
}

/** Subject + body for a document kind from the e-mail templates (Settings), or the built-in texts.   R32-001
 *  $ctx: no, title, vessel, customer, project, week, doc (the document phrase), customer_id, branch_id; the language is the customer's. */
function em_text(string $kind, array $ctx): array {
    $u = current_user(); $cid = (int)($ctx['customer_id'] ?? 0) ?: null; $pid = (int)($ctx['project_id'] ?? 0) ?: null;
    $b = (function_exists('br_of') && $pid) ? br_of($pid) : [];
    $ctx += ['user' => (string)($u['full_name'] ?? ''), 'branch' => (string)(($b['legal_name'] ?? '') ?: ($b['name'] ?? 'Golden A/S')), 'branch_id' => (int)($b['id'] ?? 0) ?: null, 'locale' => function_exists('et_customer_locale') ? et_customer_locale($cid) : locale()];
    if (function_exists('et_render')) return et_render($kind, $ctx);
    return ['subject' => (string)($ctx['subject'] ?? ''), 'body' => t('em.body', ['d' => (string)($ctx['doc'] ?? ''), 'u' => $ctx['user'], 'c' => $ctx['branch']]), 'template_id' => null];
}
/** mailto: for an "E-mail all" button (contacts kind): addresses in To, templated subject and text. */
function em_mailto(array $emails, array $ctx): string {
    $t = em_text('contacts', $ctx);
    return 'mailto:' . implode(',', $emails) . '?subject=' . rawurlencode($t['subject']) . '&body=' . rawurlencode($t['body']);
}

/** The card. $o: kind, ctx (see em_text), people (em_people), atts [[key, label]], back (form action url). */
function em_card_html(array $o): string {
    if (!em_has()) return '';
    $u = current_user(); $tx = em_text($o['kind'], $o['ctx']); $id = 'em' . substr(md5($o['kind'] . $tx['subject']), 0, 6);
    $kinds = $o['kinds'] ?? [];   // R84-001: several templates in one card — [kind => label]; the choice swaps subject and text
    $txs = []; foreach ($kinds as $k => $label) $txs[$k] = $k === $o['kind'] ? ['s' => $tx['subject'], 'b' => $tx['body']] : (function () use ($k, $o) { $t = em_text($k, $o['ctx']); return ['s' => $t['subject'], 'b' => $t['body']]; })();
    $dl = $_SESSION['em_odraft'] ?? null; if ($dl !== null) unset($_SESSION['em_odraft']);   // R97-024: the draft just created in the person's Outlook
    $h = ($dl !== null ? '<p class="flash flash--good" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><span style="flex:1 1 320px;">' . tt('em.odraft_ok') . '</span>' . ($dl !== '' ? '<a class="btn btn--sm" href="' . e($dl) . '" target="_blank" rel="noopener noreferrer">' . tt('em.odraft_open') . ' ↗</a>' : '') . '</p>' : '')
       . '<details class="card em-card" id="' . $id . '"><summary class="btn btn--ghost btn--sm">✉ ' . tt('em.title') . '</summary>';
    $h .= '<form method="post" action="' . e($o['back']) . '" class="em-form"' . ($txs ? ' data-em-tx="' . e(json_encode($txs, JSON_UNESCAPED_UNICODE)) . '"' : '') . '>' . csrf_field() . '<input type="hidden" name="action" value="em_draft">' . (string)($o['hidden'] ?? '');
    if (count($kinds) > 1) { $h .= '<div class="field" style="margin-bottom:8px;"><label>' . tt('em.template') . '</label><select name="em_kind" class="js-em-kind" data-nosearch>'; foreach ($kinds as $k => $label) $h .= '<option value="' . e($k) . '"' . ($k === $o['kind'] ? ' selected' : '') . '>' . e($label) . '</option>'; $h .= '</select></div>'; }
    $h .= '<div class="em-people"><label class="em-l">' . tt('em.to') . '</label>';
    if (!$o['people']) $h .= '<p class="hint" style="margin:0;">' . tt('em.no_people') . '</p>';
    foreach ($o['people'] as $p) $h .= '<label class="chk em-p"><input type="checkbox" name="to[]" value="' . e($p['email']) . '"' . ($p['on'] ? ' checked' : '') . '> <b>' . e($p['name']) . '</b> <span class="hint">' . e($p['email']) . ($p['role'] !== '' ? ' · ' . e($p['role']) : '') . '</span></label>';
    $h .= '<div class="em-added"></div>';
    if (function_exists('ct_has') && ct_has() && is_staff()) $h .= '<div class="field em-find-wrap" style="margin:6px 0 0; position:relative;"><input class="em-find" autocomplete="off" placeholder="' . tt('em.find_ph') . '" data-src="' . e(url('contacts_json', ['company' => (int)($o['company_id'] ?? 0) ?: null])) . '"><div class="em-find-list prq-list" hidden></div></div>';   // R84-001: pick any person of the Contacts module
    $h .= '<div class="field" style="margin:6px 0 0;"><input name="to_more" placeholder="' . tt('em.more_ph') . '"></div></div>';
    $h .= '<div class="grid grid-2"><div class="field"><label>' . tt('em.cc') . '</label><input name="cc" value="' . e((string)($u['email'] ?? '')) . '"></div>';
    $h .= '<div class="field"><label>' . tt('em.subject') . '</label><input name="subject" required value="' . e($tx['subject']) . '"></div></div>';
    $h .= '<div class="field"><label>' . tt('em.text') . '</label><textarea name="body" rows="8">' . e($tx['body']) . '</textarea></div>';
    if ($o['atts']) {
        $h .= '<div class="em-atts"><label class="em-l">' . tt('em.atts') . '</label>';
        foreach ($o['atts'] as $i => [$k, $label]) $h .= '<label class="chk em-a"><input type="checkbox" name="att[]" value="' . e($k) . '"' . ($i === 0 ? ' checked' : '') . '> 📎 ' . e($label) . '</label>';
        $h .= '</div>';
    }
    $snd = em_can_send();   // R97-023: send straight from the sender's own Microsoft 365 mailbox
    $h .= '<div class="btn-row" style="margin-top:10px;">' . ($snd ? '<button class="btn btn--gold btn--sm" type="submit" name="action" value="em_send" data-confirm="' . e(t('em.send_confirm', ['m' => (string)($u['email'] ?? '')])) . '" data-confirm-title="' . tt('dlg.title') . '">➤ ' . tt('em.send_now') . '</button><button class="btn btn--sm" type="submit" name="action" value="em_odraft">✎ ' . tt('em.odraft') . '</button>' : '') . '<button class="btn ' . ($snd ? 'btn--ghost' : 'btn--gold') . ' btn--sm" type="submit">⤓ ' . tt('em.eml') . '</button><button class="btn btn--ghost btn--sm js-em-mailto" type="button">' . tt('em.mailto') . '</button></div>';
    $h .= '<p class="hint" style="margin:8px 0 0;">' . ($snd ? e(t('em.send_hint', ['m' => (string)($u['email'] ?? '')])) . ' ' : '') . tt('em.hint') . ($tx['template_id'] && is_cp_admin() ? ' <a href="' . url('admin_email_templates', ['id' => $tx['template_id']]) . '#form">' . tt('em.edit_template') . '</a>' : (is_cp_admin() ? ' <a href="' . url('admin_email_templates', ['kind' => $o['kind']]) . '#form">' . tt('em.edit_template') . '</a>' : '')) . '</p></form></details>';
    return $h;
}

/** Parse "a@b, c@d; e@f" into valid, unique addresses. */
function em_addresses(string $s): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower($s)) ?: [] as $a) if ($a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL)) $out[$a] = true;
    return array_keys($out);
}

/** Bytes + filename + mime for one attachment key, after the access checks; null when not allowed / unknown. */
function em_attachment(string $key): ?array {
    $p = explode(':', $key);
    switch ($p[0]) {
        case 'f':
            $f = one('SELECT f.*, p.customer_id FROM sp_files f JOIN projects p ON p.id = f.project_id WHERE f.id = ?', [(int)($p[1] ?? 0)]); if (!$f) return null;
            $b = cp_file_bytes((int)$f['id']); if ($b === null) return null;
            return [$b, function_exists('ar_download_name') ? ar_download_name($f) : (string)$f['original_name'], (string)$f['mime_type']];
        case 'wk':
            if (!function_exists('weekly_data')) return null;
            $W = weekly_data((int)($p[1] ?? 0), (int)($p[2] ?? 0) ?: null, (string)($p[3] ?? date('Y-m-d'))); if (empty($W['vessel'])) return null;
            $docx = ($p[4] ?? 'pdf') === 'docx'; $no = wk_doc_no($W['vessel'], $W['only_project'] ?? null, (string)$W['week_start']);
            return [$docx ? wk_render_docx($W) : wk_render_pdf($W), $no . ($docx ? '.docx' : '.pdf'), $docx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'];
        case 'wf':
            if (!function_exists('wf_get')) return null;
            $f = wf_get((int)($p[1] ?? 0)); if (!$f) return null;
            $docx = ($p[2] ?? 'pdf') === 'docx';
            return [$docx ? wf_render_docx($f) : wf_render_pdf($f), $f['doc_no'] . ($docx ? '.docx' : '.pdf'), $docx ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' : 'application/pdf'];
        case 'st':   // R78-002: statement of account (collections)
            return function_exists('col_em_attachment') ? col_em_attachment($p) : null;
        case 'po':   // R80-003: purchase order / RFQ PDF (R88-001: po:<id>:<supplier_id> addresses the RFQ to one invited supplier)
            return function_exists('po_em_attachment') ? po_em_attachment($p) : null;
        case 'dn':   // R88-001: delivery note PDF
            return function_exists('dn_em_attachment') ? dn_em_attachment($p) : null;
    }
    return null;
}

/** Build the RFC 5322 message (multipart/mixed, X-Unsent: 1 so Outlook opens it as a draft). */
function em_build(array $to, array $cc, string $subject, string $body, array $atts): string {
    $u = current_user(); $from = (string)($u['email'] ?? ''); $name = (string)($u['full_name'] ?? '');
    $enc = fn(string $s) => preg_match('/[^\x20-\x7e]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    $bnd = 'GAS-' . bin2hex(random_bytes(12)); $nl = "\r\n";
    $h  = 'X-Unsent: 1' . $nl;
    if ($from !== '') $h .= 'From: ' . ($name !== '' ? $enc($name) . ' <' . $from . '>' : $from) . $nl;
    if ($to) $h .= 'To: ' . implode(', ', $to) . $nl;
    if ($cc) $h .= 'Cc: ' . implode(', ', $cc) . $nl;
    $h .= 'Subject: ' . $enc($subject) . $nl . 'Date: ' . date(DATE_RFC2822) . $nl . 'MIME-Version: 1.0' . $nl;
    $h .= 'Content-Type: multipart/mixed; boundary="' . $bnd . '"' . $nl . $nl;
    $alt = 'GASA-' . bin2hex(random_bytes(12));   // R97-023: the text travels as HTML too (multipart/alternative) — Outlook opens the draft in HTML format
    $m  = $h . '--' . $bnd . $nl . 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . $nl . $nl
        . '--' . $alt . $nl . 'Content-Type: text/plain; charset=UTF-8' . $nl . 'Content-Transfer-Encoding: base64' . $nl . $nl . chunk_split(base64_encode(str_replace("\r\n", "\n", $body))) . $nl
        . '--' . $alt . $nl . 'Content-Type: text/html; charset=UTF-8' . $nl . 'Content-Transfer-Encoding: base64' . $nl . $nl . chunk_split(base64_encode(em_html($body))) . $nl
        . '--' . $alt . '--' . $nl;
    foreach ($atts as [$bytes, $fname, $mime]) {
        $m .= '--' . $bnd . $nl . 'Content-Type: ' . $mime . '; name="' . $enc($fname) . '"' . $nl . 'Content-Transfer-Encoding: base64' . $nl
            . 'Content-Disposition: attachment; filename="' . $enc($fname) . '"' . $nl . $nl . chunk_split(base64_encode($bytes)) . $nl;
    }
    return $m . '--' . $bnd . '--' . $nl;
}

/** The message text as an HTML document: paragraphs, line breaks, links and **bold**; Aptos like Outlook's own default. */
function em_html(string $body): string {
    $body = trim(str_replace(["\r\n", "\r"], "\n", $body)); $out = '';
    foreach (preg_split('/\n{2,}/', $body) ?: [] as $par) {
        $p = htmlspecialchars($par, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $p = preg_replace('/\*\*(.+?)\*\*/su', '<b>$1</b>', $p);
        $p = preg_replace('~(?<![\w"=/>])(https?://[^\s<]+[^\s<.,;:!?)\]])~u', '<a href="$1">$1</a>', $p);
        $out .= '<p style="margin:0 0 12px 0;">' . nl2br($p, false) . '</p>';
    }
    return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body><div style="font-family:Aptos,Calibri,Arial,Helvetica,sans-serif;font-size:11pt;color:#1f2937;line-height:1.4;">' . $out . '</div></body></html>';
}
/** R97-023: sending from the portal is possible — Graph configured, not switched off, and the signed-in person's address is a mailbox of the same domain. */
function em_send_on(): bool { return function_exists('cp_graph_configured') && cp_graph_configured() && (!function_exists('cp_setting') || cp_setting('em_graph_send', '1') !== '0'); }
function em_can_send(): bool {
    if (!is_staff() || !em_send_on()) return false; $m = strtolower(trim((string)(current_user()['email'] ?? ''))); $d = strtolower((string) substr((string) strrchr((string) cfg('graph.from'), '@'), 1));
    return $d !== '' && filter_var($m, FILTER_VALIDATE_EMAIL) && str_ends_with($m, '@' . $d);
}
function em_sent_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'em_sent'"); } catch (Throwable $e) { $h = false; } } return $h; }
const EM_SEND_MAX = 2900000;   // bytes of attachments in one direct send (Graph accepts 4 MB per request, base64 included)
const EM_SEND_PER_HOUR = 40;   // messages one person may send through the portal per hour
/** The posted message: [to, cc, subject, body, attachments]. */
function em_post_parts(): array {
    $to = array_values(array_unique(array_merge(em_addresses(implode(',', (array)($_POST['to'] ?? []))), em_addresses((string)($_POST['to_more'] ?? '')))));
    $cc = array_values(array_diff(em_addresses((string)($_POST['cc'] ?? '')), $to));
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200) ?: 'Golden A/S';
    $body = mb_substr((string)($_POST['body'] ?? ''), 0, 20000);
    if (!$to) throw new RuntimeException(t('em.need_to'));
    $atts = []; foreach ((array)($_POST['att'] ?? []) as $k) { $a = em_attachment((string)$k); if ($a) $atts[] = $a; }
    return [$to, $cc, $subject, $body, $atts];
}
/** What a prepared / sent message marks on its document (proforma sent, PO sent, recruiting log, accounting report). */
function em_after(array $to, string $subject): void {
    if (!empty($_POST['proforma']) && function_exists('pi_mark_sent')) pi_mark_sent((int)$_POST['proforma']);   // R44-003
    if (!empty($_POST['po_id']) && !empty($_POST['rfq_sup']) && function_exists('rfo_mark_sent') && po_admin()) { try { rfo_mark_sent((int)$_POST['po_id'], (int)$_POST['rfq_sup'], $to); } catch (Throwable $e) {} }   // R88-001: RFO to this invited supplier
    elseif (!empty($_POST['po_id']) && function_exists('po_mark_sent') && po_admin()) { try { po_mark_sent((int)$_POST['po_id'], $to); } catch (Throwable $e) {} }   // R80-003: the PO / RFQ counts as sent once the draft is prepared
    if (!empty($_POST['rc_app']) && function_exists('rcr_log') && function_exists('rcr_application')) { $ra = rcr_application((int)$_POST['rc_app']); if ($ra && rcr_can_view((int)$ra['vacancy_id'])) rcr_log('email_drafted', ['application_id' => (int)$ra['id']], implode(', ', $to) . ' — ' . $subject); }   // R92-005
    if (!empty($_POST['acc_report']) && function_exists('acc_mark_sent')) acc_mark_sent((int)$_POST['acc_report'], implode(', ', $to));   // R38-001: the accounting report is now with accounting
}
/** POST em_send → the message leaves through Microsoft Graph from the signed-in person's own mailbox and lands in its Sent Items (R97-023). Returns the recipients. */
function em_send_from_post(): array {
    $u = require_staff(); if (!em_can_send()) throw new RuntimeException(t('em.send_off'));
    [$to, $cc, $subject, $body, $atts] = em_post_parts(); $mailbox = strtolower(trim((string)$u['email'])); $uid = (int)$u['id'];
    if (count($to) + count($cc) > 30) throw new RuntimeException(t('em.send_many'));
    $bytes = 0; foreach ($atts as $a) $bytes += strlen((string)$a[0]);
    if ($bytes > EM_SEND_MAX) throw new RuntimeException(t('em.send_big', ['mb' => number_format($bytes / 1048576, 1, ',', '')]));
    if (em_sent_has() && (int) scalar('SELECT COUNT(*) FROM em_sent WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$uid]) >= EM_SEND_PER_HOUR) throw new RuntimeException(t('em.send_rate'));
    [$ok, $code, $err] = cp_graph_send_message($mailbox, (string)$u['full_name'], $to, $cc, $subject, em_html($body), $atts);
    if (em_sent_has()) { try { q('INSERT INTO em_sent (user_id, mailbox, to_list, cc_list, subject, n_atts, bytes, ok, note, ip) VALUES (?,?,?,?,?,?,?,?,?,?)', [$uid, $mailbox, mb_substr(implode(', ', $to), 0, 2000), $cc ? mb_substr(implode(', ', $cc), 0, 1000) : null, $subject, count($atts), $bytes, $ok ? 1 : 0, $ok ? null : mb_substr($code . ' ' . $err, 0, 200), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]); } catch (Throwable $e) {} }
    if (!$ok) throw new RuntimeException(t($code === 403 || $code === 404 ? 'em.send_denied' : 'em.send_fail', ['m' => $mailbox]));
    em_after($to, $subject);
    return $to;
}
/** POST em_odraft → a draft in the Drafts folder of the signed-in person's own mailbox, to finish and send from Outlook (R97-024). Returns the web link ('' when none). */
function em_odraft_from_post(): string {
    $u = require_staff(); if (!em_can_send()) throw new RuntimeException(t('em.send_off'));
    [$to, $cc, $subject, $body, $atts] = em_post_parts(); $mailbox = strtolower(trim((string)$u['email'])); $uid = (int)$u['id'];
    $bytes = 0; foreach ($atts as $a) $bytes += strlen((string)$a[0]);
    if ($bytes > 25 * 1024 * 1024) throw new RuntimeException(t('em.odraft_big', ['mb' => number_format($bytes / 1048576, 1, ',', '')]));
    if (em_sent_has() && (int) scalar('SELECT COUNT(*) FROM em_sent WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)', [$uid]) >= EM_SEND_PER_HOUR) throw new RuntimeException(t('em.send_rate'));
    [$ok, $code, $err, $link] = cp_graph_create_draft($mailbox, $to, $cc, $subject, em_html($body), $atts);
    if (em_sent_has()) { try { q('INSERT INTO em_sent (user_id, mailbox, to_list, cc_list, subject, n_atts, bytes, ok, note, ip) VALUES (?,?,?,?,?,?,?,?,?,?)', [$uid, $mailbox, mb_substr(implode(', ', $to), 0, 2000), $cc ? mb_substr(implode(', ', $cc), 0, 1000) : null, $subject, count($atts), $bytes, $ok ? 1 : 0, mb_substr($ok ? 'draft' : 'draft ' . $code . ' ' . $err, 0, 200), mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]); } catch (Throwable $e) {} }
    if (!$ok) throw new RuntimeException(t($code === 403 ? 'em.odraft_denied' : ($code === 404 ? 'em.send_denied' : 'em.odraft_fail'), ['m' => $mailbox]));
    em_after($to, $subject);
    return $link;
}
/** POST em_draft → the .eml download. */
function em_draft_from_post(): void {
    require_staff();
    [$to, $cc, $subject, $body, $atts] = em_post_parts(); $eml = em_build($to, $cc, $subject, $body, $atts); em_after($to, $subject);
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', $subject); $fn = trim(mb_substr($fn, 0, 80), '_') ?: 'message';
    header('Content-Type: message/rfc822'); header('Content-Length: ' . strlen($eml)); header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $fn . '.eml"');
    echo $eml; exit;
}

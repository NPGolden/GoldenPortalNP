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
    $h = '<details class="card em-card" id="' . $id . '"><summary class="btn btn--ghost btn--sm">✉ ' . tt('em.title') . '</summary>';
    $h .= '<form method="post" action="' . e($o['back']) . '" class="em-form">' . csrf_field() . '<input type="hidden" name="action" value="em_draft">' . (string)($o['hidden'] ?? '');
    $h .= '<div class="em-people"><label class="em-l">' . tt('em.to') . '</label>';
    if (!$o['people']) $h .= '<p class="hint" style="margin:0;">' . tt('em.no_people') . '</p>';
    foreach ($o['people'] as $p) $h .= '<label class="chk em-p"><input type="checkbox" name="to[]" value="' . e($p['email']) . '"' . ($p['on'] ? ' checked' : '') . '> <b>' . e($p['name']) . '</b> <span class="hint">' . e($p['email']) . ($p['role'] !== '' ? ' · ' . e($p['role']) : '') . '</span></label>';
    $h .= '<div class="field" style="margin:6px 0 0;"><input name="to_more" placeholder="' . tt('em.more_ph') . '"></div></div>';
    $h .= '<div class="grid grid-2"><div class="field"><label>' . tt('em.cc') . '</label><input name="cc" value="' . e((string)($u['email'] ?? '')) . '"></div>';
    $h .= '<div class="field"><label>' . tt('em.subject') . '</label><input name="subject" required value="' . e($tx['subject']) . '"></div></div>';
    $h .= '<div class="field"><label>' . tt('em.text') . '</label><textarea name="body" rows="8">' . e($tx['body']) . '</textarea></div>';
    if ($o['atts']) {
        $h .= '<div class="em-atts"><label class="em-l">' . tt('em.atts') . '</label>';
        foreach ($o['atts'] as $i => [$k, $label]) $h .= '<label class="chk em-a"><input type="checkbox" name="att[]" value="' . e($k) . '"' . ($i === 0 ? ' checked' : '') . '> 📎 ' . e($label) . '</label>';
        $h .= '</div>';
    }
    $h .= '<div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold btn--sm" type="submit">⤓ ' . tt('em.eml') . '</button><button class="btn btn--ghost btn--sm js-em-mailto" type="button">' . tt('em.mailto') . '</button></div>';
    $h .= '<p class="hint" style="margin:8px 0 0;">' . tt('em.hint') . ($tx['template_id'] && is_cp_admin() ? ' <a href="' . url('admin_email_templates', ['id' => $tx['template_id']]) . '#form">' . tt('em.edit_template') . '</a>' : (is_cp_admin() ? ' <a href="' . url('admin_email_templates', ['kind' => $o['kind']]) . '#form">' . tt('em.edit_template') . '</a>' : '')) . '</p></form></details>';
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
    $m  = $h . '--' . $bnd . $nl . 'Content-Type: text/plain; charset=UTF-8' . $nl . 'Content-Transfer-Encoding: base64' . $nl . $nl . chunk_split(base64_encode(str_replace("\r\n", "\n", $body))) . $nl;
    foreach ($atts as [$bytes, $fname, $mime]) {
        $m .= '--' . $bnd . $nl . 'Content-Type: ' . $mime . '; name="' . $enc($fname) . '"' . $nl . 'Content-Transfer-Encoding: base64' . $nl
            . 'Content-Disposition: attachment; filename="' . $enc($fname) . '"' . $nl . $nl . chunk_split(base64_encode($bytes)) . $nl;
    }
    return $m . '--' . $bnd . '--' . $nl;
}

/** POST em_draft → the .eml download. */
function em_draft_from_post(): void {
    require_staff();
    $to = array_values(array_unique(array_merge(em_addresses(implode(',', (array)($_POST['to'] ?? []))), em_addresses((string)($_POST['to_more'] ?? '')))));
    $cc = array_diff(em_addresses((string)($_POST['cc'] ?? '')), $to);
    $subject = mb_substr(trim((string)($_POST['subject'] ?? '')), 0, 200) ?: 'Golden A/S';
    $body = mb_substr((string)($_POST['body'] ?? ''), 0, 20000);
    if (!$to) throw new RuntimeException(t('em.need_to'));
    $atts = [];
    foreach ((array)($_POST['att'] ?? []) as $k) { $a = em_attachment((string)$k); if ($a) $atts[] = $a; }
    $eml = em_build($to, array_values($cc), $subject, $body, $atts);
    if (!empty($_POST['proforma']) && function_exists('pi_mark_sent')) pi_mark_sent((int)$_POST['proforma']);   // R44-003
    if (!empty($_POST['acc_report']) && function_exists('acc_mark_sent')) acc_mark_sent((int)$_POST['acc_report'], implode(', ', $to));   // R38-001: the accounting report is now with accounting
    $fn = preg_replace('/[^A-Za-z0-9._-]+/', '_', $subject); $fn = trim(mb_substr($fn, 0, 80), '_') ?: 'message';
    header('Content-Type: message/rfc822'); header('Content-Length: ' . strlen($eml)); header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="' . $fn . '.eml"');
    echo $eml; exit;
}

<?php
/** Outgoing e-mail for the portal — GAS-CP-R91-003.
 *  goldenas.com.gr mail lives on Microsoft 365 and the domain's SPF allows only Microsoft (-all), so PHP mail() from the
 *  web host is junked or rejected. cp_mail() sends through authenticated SMTP when config.php has a 'smtp' block
 *  (smtp.office365.com:587, STARTTLS, AUTH LOGIN — a licensed mailbox with "Authenticated SMTP" enabled, e.g.
 *  website@goldenas.com.gr) and falls back to mail() otherwise. Candidate, HR and website-inbox messages all use it. */
declare(strict_types=1);

/* R94-007 — Microsoft Graph with OAuth2 client credentials (preferred): config.php 'graph' => ['tenant', 'client_id', 'client_secret', 'from'].
 * The app registration needs the application permission Mail.Send (admin consent) and should be limited to the sending mailbox with an
 * Exchange application access policy. No mailbox password anywhere; the token (1 h) is cached in a 0600 file outside the web root. */
function cp_graph_configured(): bool { return (bool) (cfg('graph.tenant') && cfg('graph.client_id') && cfg('graph.client_secret') && cfg('graph.from')); }
function cp_mail_configured(): bool { return cp_graph_configured() || (bool) (cfg('smtp.host') && cfg('smtp.user') && cfg('smtp.pass')); }
/** Human label of the active transport for the settings card. */
function cp_mail_transport(): string { return cp_graph_configured() ? 'graph' : (cfg('smtp.host') && cfg('smtp.user') && cfg('smtp.pass') ? 'smtp' : 'mail'); }
/** The mailbox messages are sent from. */
function cp_mail_from(): string { return (string) (cfg('graph.from') ?: cfg('smtp.from') ?: cfg('smtp.user') ?: 'noreply@goldenas.com.gr'); }

function cp_graph_token(bool $fresh = false): ?string {
    $tenant = (string) cfg('graph.tenant'); $cid = (string) cfg('graph.client_id'); $sec = (string) cfg('graph.client_secret');
    $cache = rtrim(sys_get_temp_dir(), '/') . '/gascp-graph-' . substr(hash('sha256', $tenant . '|' . $cid . '|' . $sec), 0, 24) . '.json';
    if (!$fresh && is_file($cache)) { $c = json_decode((string) @file_get_contents($cache), true); if (is_array($c) && ($c['exp'] ?? 0) > time() + 60 && !empty($c['t'])) return (string) $c['t']; }
    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query(['client_id' => $cid, 'client_secret' => $sec, 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials'])]);
    $res = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365 token'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    $j = is_string($res) ? json_decode($res, true) : null;
    if ($code !== 200 || empty($j['access_token'])) { error_log('[cp_mail] Graph token failed (' . $code . ') ' . ($err ?: (string) ($j['error'] ?? '')) . ' ' . preg_replace('/\s+/', ' ', mb_substr((string) ($j['error_description'] ?? ''), 0, 160))); return null; }
    @file_put_contents($cache, json_encode(['t' => $j['access_token'], 'exp' => time() + (int) ($j['expires_in'] ?? 3600)]), LOCK_EX); @chmod($cache, 0600);
    return (string) $j['access_token'];
}
/** Send through Graph: POST /users/{from}/sendMail. Plain text; optional Reply-To; saved to the mailbox's Sent Items. */
function cp_graph_send(string $from, string $fromName, string $to, string $subject, string $body, ?string $replyTo = null, array $attachments = []): bool {
    $tok = cp_graph_token(); if (!$tok) return false;
    $msg = ['subject' => preg_replace('/[\r\n]/', ' ', $subject), 'body' => ['contentType' => 'Text', 'content' => $body],
            'toRecipients' => [['emailAddress' => ['address' => $to]]], 'from' => ['emailAddress' => ['address' => $from, 'name' => preg_replace('/[\r\n"]/', '', $fromName)]]];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $msg['replyTo'] = [['emailAddress' => ['address' => $replyTo]]];
    foreach ($attachments as $a) if (!empty($a['name']) && isset($a['bytes']) && strlen($a['bytes']) <= 3 * 1024 * 1024) $msg['attachments'][] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string) $a['name'], 'contentType' => (string) ($a['mime'] ?? 'application/octet-stream'), 'contentBytes' => base64_encode($a['bytes'])];
    $post = function (string $tok) use ($from, $msg): array {
        $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($from) . '/sendMail');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['message' => $msg, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE)]);
        $res = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365 mail'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, is_string($res) ? $res : ''];
    };
    [$code, $res] = $post($tok);
    if ($code === 401) { $tok = cp_graph_token(true); if ($tok) [$code, $res] = $post($tok); }   // expired / revoked token → one retry
    if ($code === 202) return true;
    $j = json_decode($res, true); error_log('[cp_mail] Graph sendMail → ' . $code . ' ' . preg_replace('/\s+/', ' ', mb_substr((string) ($j['error']['code'] ?? '') . ' ' . (string) ($j['error']['message'] ?? ''), 0, 200)));
    return false;
}

/**
 * R97-023 — send a complete message through Graph from a given mailbox of the tenant: several To / Cc, HTML body, attachments; saved to
 * that mailbox's Sent Items. $atts = [[bytes, name, mime], …]. Returns [ok, http code, short error]. The whole request must stay under
 * Graph's 4 MB limit — the caller checks the attachment size first.
 */
function cp_graph_send_message(string $mailbox, string $fromName, array $to, array $cc, string $subject, string $html, array $atts = []): array {
    $tok = cp_graph_token(); if (!$tok) return [false, 0, 'token'];
    $rcp = fn(array $l) => array_map(fn($a) => ['emailAddress' => ['address' => (string)$a]], array_values($l));
    $msg = ['subject' => preg_replace('/[\r\n]/', ' ', $subject), 'body' => ['contentType' => 'HTML', 'content' => $html], 'toRecipients' => $rcp($to)];
    if ($cc) $msg['ccRecipients'] = $rcp($cc);
    foreach ($atts as [$bytes, $name, $mime]) $msg['attachments'][] = ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string)$name, 'contentType' => (string)($mime ?: 'application/octet-stream'), 'contentBytes' => base64_encode((string)$bytes)];
    $post = function (string $tok) use ($mailbox, $msg): array {
        $ch = curl_init('https://graph.microsoft.com/v1.0/users/' . rawurlencode($mailbox) . '/sendMail');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['message' => $msg, 'saveToSentItems' => true], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $res = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365 mail'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$code, is_string($res) ? $res : ''];
    };
    [$code, $res] = $post($tok);
    if ($code === 401) { $tok = cp_graph_token(true); if ($tok) [$code, $res] = $post($tok); }
    if ($code === 202) return [true, 202, ''];
    $j = json_decode($res, true); $err = preg_replace('/\s+/', ' ', mb_substr((string)($j['error']['code'] ?? '') . ' ' . (string)($j['error']['message'] ?? ''), 0, 180));
    error_log('[cp_mail] Graph sendMail (own mailbox) → ' . $code . ' ' . $err);
    return [false, $code, trim($err)];
}

/** R97-024 — one Graph call with the application token: [http code, decoded body]. A 401 or 403 is retried once with a fresh token (a permission granted in Entra reaches only new tokens). */
function cp_graph_call(string $method, string $path, ?array $json = null, int $timeout = 40): array {
    $tok = cp_graph_token(); if (!$tok) return [0, ['error' => ['code' => 'token']]];
    $do = function (string $tok) use ($method, $path, $json, $timeout): array {
        $ch = curl_init('https://graph.microsoft.com/v1.0' . $path);
        $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tok, 'Content-Type: application/json']];
        if ($json !== null) $opt[CURLOPT_POSTFIELDS] = json_encode($json, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        curl_setopt_array($ch, $opt); $res = curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365'); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        $j = is_string($res) && $res !== '' ? json_decode($res, true) : null; return [$code, is_array($j) ? $j : []];
    };
    [$code, $j] = $do($tok);
    if ($code === 401 || $code === 403) { $t2 = cp_graph_token(true); if ($t2 && $t2 !== $tok) [$code, $j] = $do($t2); }
    return [$code, $j];
}
/**
 * R97-024 — create a draft in a mailbox of the tenant (Drafts folder; visible in the new Outlook, Outlook on the web, classic Outlook and
 * the phone): HTML body, To / Cc, attachments ($atts = [[bytes, name, mime], …]; up to 3 MB each in one call, larger ones through an upload
 * session). Needs the application permission Mail.ReadWrite. Returns [ok, http code, short error, webLink].
 */
function cp_graph_create_draft(string $mailbox, array $to, array $cc, string $subject, string $html, array $atts = []): array {
    $rcp = fn(array $l) => array_map(fn($a) => ['emailAddress' => ['address' => (string)$a]], array_values($l)); $base = '/users/' . rawurlencode($mailbox);
    $msg = ['subject' => preg_replace('/[\r\n]/', ' ', $subject), 'body' => ['contentType' => 'HTML', 'content' => $html], 'toRecipients' => $rcp($to)]; if ($cc) $msg['ccRecipients'] = $rcp($cc);
    $fail = function (int $code, array $j, string $at): array { $err = trim(preg_replace('/\s+/', ' ', mb_substr((string)($j['error']['code'] ?? '') . ' ' . (string)($j['error']['message'] ?? ''), 0, 160))); error_log('[cp_mail] Graph draft (' . $at . ') → ' . $code . ' ' . $err); return [false, $code, $err, '']; };
    [$code, $j] = cp_graph_call('POST', $base . '/messages', $msg);
    if ($code !== 201 || empty($j['id'])) return $fail($code, $j, 'create');
    $mid = (string)$j['id']; $link = (string)($j['webLink'] ?? ''); $mp = $base . '/messages/' . rawurlencode($mid);
    foreach ($atts as [$bytes, $name, $mime]) {
        $bytes = (string)$bytes; $size = strlen($bytes);
        if ($size <= 2900000) {
            [$c, $r] = cp_graph_call('POST', $mp . '/attachments', ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => (string)$name, 'contentType' => (string)($mime ?: 'application/octet-stream'), 'contentBytes' => base64_encode($bytes)], 60);
            if ($c !== 201) { cp_graph_call('DELETE', $mp); return $fail($c, $r, 'attachment'); }
            continue;
        }
        [$c, $r] = cp_graph_call('POST', $mp . '/attachments/createUploadSession', ['AttachmentItem' => ['attachmentType' => 'file', 'name' => (string)$name, 'size' => $size, 'contentType' => (string)($mime ?: 'application/octet-stream')]]);
        $up = (string)($r['uploadUrl'] ?? ''); if ($c !== 201 || !preg_match('~^https://[a-z0-9.-]+\.(office\.com|office365\.com|microsoft\.com)/~i', $up)) { cp_graph_call('DELETE', $mp); return $fail($c, $r, 'upload session'); }
        $chunk = 3 * 1024 * 1024;   // the upload URL carries its own authorisation — no bearer token is sent to it
        for ($o = 0; $o < $size; $o += $chunk) {
            $part = substr($bytes, $o, $chunk); $end = $o + strlen($part) - 1; $ch = curl_init($up);
            curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_POSTFIELDS => $part,
                CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream', 'Content-Length: ' . strlen($part), 'Content-Range: bytes ' . $o . '-' . $end . '/' . $size]]);
            curl_exec($ch); if (function_exists('perf_out')) perf_out($ch, 'Microsoft 365 upload'); $pc = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
            if (!in_array($pc, [200, 201, 202], true)) { cp_graph_call('DELETE', $mp); return $fail($pc, [], 'upload'); }
        }
    }
    return [true, 201, '', preg_match('~^https://outlook\.(office\.com|office365\.com|live\.com)/~i', $link) ? $link : ''];
}

/** Plain-text mail. $fromName/$from default to the SMTP mailbox; Reply-To optional. */
function cp_mail(string $to, string $subject, string $body, string $fromName = 'Golden A/S', ?string $from = null, ?string $replyTo = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = $from ?: cp_mail_from();
    if (cp_graph_configured()) { if (cp_graph_send((string) cfg('graph.from'), $fromName, $to, $subject, $body, $replyTo)) return true; error_log('[cp_mail] Graph failed for ' . $to . ' — trying SMTP / mail()'); }   // Graph sends as the configured mailbox; any other $from becomes Reply-To-less but logged
    $domain = substr(strrchr($from, '@') ?: '@goldenas.com.gr', 1);
    $hdr = ['From: ' . preg_replace('/[\r\n"]/', '', $fromName) . ' <' . $from . '>', 'To: <' . $to . '>', 'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', 'X-Mailer: Golden A/S Customer Portal'];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $hdr[] = 'Reply-To: <' . $replyTo . '>';
    $subj = '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]/', ' ', $subject)) . '?=';
    if (cfg('smtp.host') && cfg('smtp.user') && cfg('smtp.pass')) {
        if (cp_smtp_send($from, $to, "Subject: $subj\r\n" . implode("\r\n", $hdr) . "\r\n\r\n" . preg_replace('/(?<!\r)\n/', "\r\n", $body))) return true;
        error_log('[cp_mail] SMTP failed for ' . $to . ' — falling back to mail()');
    }
    $h = array_values(array_filter($hdr, fn($l) => !str_starts_with($l, 'To:') && !str_starts_with($l, 'Date:')));
    return @mail($to, $subj, $body, implode("\r\n", $h), '-f' . $from);
}

/** Minimal SMTP client: STARTTLS (587) or implicit TLS (465), AUTH LOGIN. */
function cp_smtp_send(string $from, string $to, string $data): bool {
    $host = (string) cfg('smtp.host'); $port = (int) (cfg('smtp.port') ?: 587); $user = (string) cfg('smtp.user'); $pass = (string) cfg('smtp.pass');
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
    $fp = @stream_socket_client(($port === 465 ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) { error_log("[cp_mail] SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): array { $code = 0; $lines = []; while (($l = fgets($fp, 2048)) !== false) { $lines[] = rtrim($l); $code = (int) substr($l, 0, 3); if (isset($l[3]) && $l[3] === ' ') break; } return [$code, implode(' | ', $lines)]; };
    $cmd = function (string $c, array $ok) use ($fp, $read): bool { fwrite($fp, $c . "\r\n"); [$code, $msg] = $read(); if (!in_array($code, $ok, true)) { error_log('[cp_mail] SMTP ' . (preg_match('/^[A-Za-z0-9+\/=]{16,}$/', $c) ? '(credential)' : $c) . ' → ' . $msg); return false; } return true; };
    [$code] = $read(); if ($code !== 220) { fclose($fp); return false; }
    $ehlo = 'EHLO ' . (parse_url((string) cfg('app.base_url'), PHP_URL_HOST) ?: 'goldensas.com');
    if (!$cmd($ehlo, [250])) { fclose($fp); return false; }
    if ($port !== 465) { if (!$cmd('STARTTLS', [220]) || !stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return false; } if (!$cmd($ehlo, [250])) { fclose($fp); return false; } }
    $ok = $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode($user), [334]) && $cmd(base64_encode($pass), [235])
       && $cmd('MAIL FROM:<' . $from . '>', [250]) && $cmd('RCPT TO:<' . $to . '>', [250, 251]) && $cmd('DATA', [354]);
    if ($ok) { fwrite($fp, preg_replace('/^\./m', '..', $data) . "\r\n.\r\n"); [$code, $msg] = $read(); $ok = $code === 250; if (!$ok) error_log('[cp_mail] SMTP DATA → ' . $msg); }
    fwrite($fp, "QUIT\r\n"); fclose($fp);
    return $ok;
}

<?php
/** Outgoing e-mail for the portal — GAS-CP-R91-003.
 *  goldenas.com.gr mail lives on Microsoft 365 and the domain's SPF allows only Microsoft (-all), so PHP mail() from the
 *  web host is junked or rejected. cp_mail() sends through authenticated SMTP when config.php has a 'smtp' block
 *  (smtp.office365.com:587, STARTTLS, AUTH LOGIN — a licensed mailbox with "Authenticated SMTP" enabled, e.g.
 *  website@goldenas.com.gr) and falls back to mail() otherwise. Candidate, HR and website-inbox messages all use it. */
declare(strict_types=1);

function cp_mail_configured(): bool { return (bool) (cfg('smtp.host') && cfg('smtp.user') && cfg('smtp.pass')); }

/** Plain-text mail. $fromName/$from default to the SMTP mailbox; Reply-To optional. */
function cp_mail(string $to, string $subject, string $body, string $fromName = 'Golden A/S', ?string $from = null, ?string $replyTo = null): bool {
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
    $from = $from ?: (string) (cfg('smtp.from') ?: cfg('smtp.user') ?: 'noreply@goldenas.com.gr');
    $domain = substr(strrchr($from, '@') ?: '@goldenas.com.gr', 1);
    $hdr = ['From: ' . preg_replace('/[\r\n"]/', '', $fromName) . ' <' . $from . '>', 'To: <' . $to . '>', 'Date: ' . date('r'), 'Message-ID: <' . bin2hex(random_bytes(8)) . '@' . $domain . '>', 'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit', 'X-Mailer: Golden A/S Customer Portal'];
    if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $hdr[] = 'Reply-To: <' . $replyTo . '>';
    $subj = '=?UTF-8?B?' . base64_encode(preg_replace('/[\r\n]/', ' ', $subject)) . '?=';
    if (cp_mail_configured()) {
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

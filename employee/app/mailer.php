<?php
/** GAS-POMS — outbound mail. Drivers: mail | smtp | log. */

declare(strict_types=1);

function send_mail(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    $driver = cfg('mail.driver', 'log');
    $from   = cfg('mail.from_email', 'noreply@localhost');
    $fromNm = cfg('mail.from_name', 'Golden A/S Parts');

    if ($driver === 'log') {
        $line = sprintf("[%s] To: %s <%s>\nSubject: %s\n%s\n%s\n",
            date('c'), $toName, $toEmail, $subject, str_repeat('-', 60), strip_tags($htmlBody));
        @file_put_contents(dirname(cfg('app.storage_path')) . '/mail.log', $line, FILE_APPEND);
        return true;
    }

    if ($driver === 'smtp') {
        return smtp_send($toEmail, $toName, $subject, $htmlBody);
    }

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . mb_encode_mimeheader($fromNm) . " <{$from}>",
        'Reply-To: ' . $from,
    ];
    return @mail($toEmail, '=?UTF-8?B?' . base64_encode($subject) . '?=', $htmlBody, implode("\r\n", $headers));
}

/** One line per mail event -> storage/mail.log (visible in File Manager). */
function mail_log(string $to, string $stage, string $detail): void {
    $f = dirname(__DIR__) . '/storage/mail.log';
    @file_put_contents($f, sprintf("%s | %-28s | %-10s | %s\n",
        date('c'), $to, $stage, preg_replace('/\s+/', ' ', trim($detail))), FILE_APPEND);
}

/** Minimal SMTP client with STARTTLS and AUTH LOGIN. Sufficient for M365.
 *  Hardened: short timeouts, and after one hard failure every further send
 *  in the SAME request is skipped instantly — a broken mail server can slow
 *  an action by a few seconds at most, and can never break it. */
function smtp_send(string $toEmail, string $toName, string $subject, string $htmlBody): bool {
    static $smtpDead = false;
    if ($smtpDead) { mail_log($toEmail, 'skipped', 'previous SMTP failure in this request'); return false; }

    $h    = cfg('mail.smtp.host');
    $port = (int) cfg('mail.smtp.port', 587);
    $user = cfg('mail.smtp.user');
    $pass = cfg('mail.smtp.pass');
    $from = cfg('mail.from_email');
    $fnm  = cfg('mail.from_name');

    $fp = @stream_socket_client("tcp://{$h}:{$port}", $errno, $errstr, 6);
    if (!$fp) { $smtpDead = true; mail_log($toEmail, 'connect', "FAILED: {$errstr} ({$errno})"); return false; }
    stream_set_timeout($fp, 8);

    $read = function () use ($fp) {
        $out = '';
        while ($line = fgets($fp, 512)) {
            $out .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') break;
        }
        return $out;
    };
    $say = function (string $cmd) use ($fp, $read) { fwrite($fp, $cmd . "\r\n"); return $read(); };

    $read();
    $say('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $say('STARTTLS');
    if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
        fclose($fp); $smtpDead = true; mail_log($toEmail, 'starttls', 'FAILED'); return false;
    }
    $say('EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
    $say('AUTH LOGIN');
    $say(base64_encode($user));
    $r = $say(base64_encode($pass));
    if (strpos($r, '235') !== 0) {
        fclose($fp); $smtpDead = true;
        mail_log($toEmail, 'auth', 'REFUSED by server: ' . substr($r, 0, 160));
        return false;
    }

    $say("MAIL FROM:<{$from}>");
    $say("RCPT TO:<{$toEmail}>");
    $say('DATA');

    $msg = "From: {$fnm} <{$from}>\r\n"
         . "To: {$toName} <{$toEmail}>\r\n"
         . 'Subject: =?UTF-8?B?' . base64_encode($subject) . "?=\r\n"
         . "MIME-Version: 1.0\r\n"
         . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
         . $htmlBody . "\r\n.";
    $r = $say($msg);
    $say('QUIT');
    fclose($fp);

    $ok = strpos($r, '250') === 0;
    mail_log($toEmail, $ok ? 'sent' : 'data', $ok ? 'OK' : 'server said: ' . substr($r, 0, 160));
    return $ok;
}

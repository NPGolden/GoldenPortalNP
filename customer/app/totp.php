<?php
/**
 * GAS-POMS — RFC 6238 time-based one-time passwords.
 * Pure PHP, no extensions beyond hash(). Compatible with Microsoft
 * Authenticator, Google Authenticator, 1Password, Authy.
 */

declare(strict_types=1);

const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function totp_secret(int $bytes = 20): string {
    $raw = random_bytes($bytes);
    return totp_base32_encode($raw);
}

function totp_base32_encode(string $raw): string {
    $bits = '';
    foreach (str_split($raw) as $ch) {
        $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
        $out  .= TOTP_ALPHABET[bindec($chunk)];
    }
    return $out;
}

function totp_base32_decode(string $b32): string {
    $b32  = strtoupper(rtrim($b32, '='));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $pos = strpos(TOTP_ALPHABET, $b32[$i]);
        if ($pos === false) continue;
        $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function totp_code(string $secret, ?int $timeSlice = null, int $digits = 6, int $period = 30): string {
    $timeSlice = $timeSlice ?? (int) floor(time() / $period);
    $key       = totp_base32_decode($secret);
    $binSlice  = pack('N*', 0) . pack('N*', $timeSlice);
    $hash      = hash_hmac('sha1', $binSlice, $key, true);
    $offset    = ord(substr($hash, -1)) & 0x0F;
    $part      = substr($hash, $offset, 4);
    $value     = unpack('N', $part)[1] & 0x7FFFFFFF;
    return str_pad((string)($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/** Accepts the current code plus one step either side, for clock drift. */
function totp_verify(string $secret, string $code, int $window = 1): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $now = (int) floor(time() / 30);
    for ($i = -$window; $i <= $window; $i++) {
        if (hash_equals(totp_code($secret, $now + $i), $code)) return true;
    }
    return false;
}

/** otpauth:// URI for QR provisioning. */
function totp_uri(string $secret, string $email, string $issuer): string {
    return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
        rawurlencode($issuer), rawurlencode($email), $secret, rawurlencode($issuer));
}

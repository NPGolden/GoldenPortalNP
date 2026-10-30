<?php
/** CS-09: sliding-minute limits in storage/private (the portal's ai_rate_ok covers the assistant separately). */
declare(strict_types=1);
final class RateLimit {
    public static function ip(): string { return trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'))[0]); }
    public static function check(string $bucket, string $key, int $perMinute): void {
        $dir = api_private_dir() . '/ratelimit'; if (!is_dir($dir)) @mkdir($dir, 0700, true);
        $f = $dir . '/' . $bucket . '_' . md5($key) . '.json'; $now = time();
        $hits = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
        $hits = array_values(array_filter($hits, fn($t) => $t > $now - 60));
        if (count($hits) >= $perMinute) { header('Retry-After: 60'); throw new ApiException(429, 'Too many requests'); }
        $hits[] = $now; @file_put_contents($f, json_encode($hits), LOCK_EX);
    }
}

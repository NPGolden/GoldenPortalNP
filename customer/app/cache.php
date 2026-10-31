<?php
/** Small cache for computed page blocks (menu badges, online list, dashboard cards) — APCu when the host has it, else files under storage/cache.
 *  Values are short-lived (seconds to minutes); nothing here is a source of truth.   GAS-CP-R69-002 */
declare(strict_types=1);

function cache_backend(): string { static $b = null; if ($b === null) $b = (function_exists('apcu_enabled') && apcu_enabled()) ? 'apcu' : 'file'; return $b; }
function cache_dir(): string { static $d = null; if ($d === null) { $d = dirname(__DIR__) . '/storage/cache'; if (!is_dir($d)) @mkdir($d, 0750, true); } return $d; }
function cache_key(string $k): string { return 'gascp:' . (defined('CP_VERSION') ? CP_VERSION : '0') . ':' . $k; }   // a release invalidates everything
function cache_get(string $k) {
    $key = cache_key($k);
    if (cache_backend() === 'apcu') { $ok = false; $v = apcu_fetch($key, $ok); return $ok ? $v : null; }
    $f = cache_dir() . '/' . sha1($key) . '.cache'; if (!is_file($f)) return null;
    $raw = @file_get_contents($f); if ($raw === false) return null; $d = @unserialize($raw, ['allowed_classes' => false]); if (!is_array($d) || ($d['exp'] ?? 0) < time()) { @unlink($f); return null; }
    return $d['v'];
}
function cache_set(string $k, $v, int $ttl): void {
    $key = cache_key($k); $ttl = max(1, $ttl);
    if (cache_backend() === 'apcu') { apcu_store($key, $v, $ttl); return; }
    $f = cache_dir() . '/' . sha1($key) . '.cache'; $tmp = $f . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, serialize(['exp' => time() + $ttl, 'v' => $v]), LOCK_EX) !== false) @rename($tmp, $f);
}
function cache_forget(string $k): void { $key = cache_key($k); if (cache_backend() === 'apcu') { apcu_delete($key); return; } @unlink(cache_dir() . '/' . sha1($key) . '.cache'); }
/** Compute once, keep for $ttl seconds. */
function cache_remember(string $k, int $ttl, callable $fn) { $v = cache_get($k); if ($v !== null) return $v; $v = $fn(); cache_set($k, $v, $ttl); return $v; }
/** Drop everything (admin button, after imports). */
function cache_flush(): int { if (cache_backend() === 'apcu') { apcu_clear_cache(); return 0; } $n = 0; foreach ((array) @glob(cache_dir() . '/*.cache') as $f) if (@unlink($f)) $n++; return $n; }
/** Remove expired files (house-keeping). */
function cache_sweep(): int { if (cache_backend() === 'apcu') return 0; $n = 0; foreach ((array) @glob(cache_dir() . '/*.cache') as $f) { $raw = @file_get_contents($f); $d = $raw !== false ? @unserialize($raw, ['allowed_classes' => false]) : null; if (!is_array($d) || ($d['exp'] ?? 0) < time()) { @unlink($f); $n++; } } return $n; }

/* ------------------------------------------------------------------ query meter */
$GLOBALS['cp_q'] = ['n' => 0, 'ms' => 0.0, 't0' => microtime(true), 'slow' => []];
function db_meter(string $sql, float $ms): void { $m = &$GLOBALS['cp_q']; $m['n']++; $m['ms'] += $ms; if ($ms > 300 && count($m['slow']) < 5) $m['slow'][] = [round($ms), mb_substr(preg_replace('/\s+/', ' ', $sql), 0, 160)]; }
function db_stats(): array { $m = $GLOBALS['cp_q']; return ['queries' => (int)$m['n'], 'db_ms' => (int) round($m['ms']), 'page_ms' => (int) round((microtime(true) - $m['t0']) * 1000), 'slow' => $m['slow']]; }
/** Called at the end of a page: a slow page (> 2 s or > 150 queries) is logged for Diagnostics. */
function db_meter_finish(string $page): void {
    $s = db_stats(); if ($s['page_ms'] < 2000 && $s['queries'] < 150) return;
    $f = dirname(__DIR__) . '/storage/slow-pages.log'; $u = function_exists('current_user') ? current_user() : null;
    @file_put_contents($f, date('Y-m-d H:i:s') . "\t" . $page . "\t" . $s['page_ms'] . " ms\t" . $s['queries'] . " q\t" . $s['db_ms'] . " db ms\t" . (string)($u['email'] ?? '-') . ($s['slow'] ? "\t" . implode(' | ', array_map(fn($x) => $x[0] . ' ms ' . $x[1], $s['slow'])) : '') . "\n", FILE_APPEND | LOCK_EX);
}
/** Slowest pages for Diagnostics: last 200 lines, sorted by ms. */
function db_slow_pages(int $n = 8): array {
    $f = dirname(__DIR__) . '/storage/slow-pages.log'; if (!is_file($f)) return [];
    $lines = array_slice(array_filter(explode("\n", (string) @file_get_contents($f))), -200); $rows = [];
    foreach ($lines as $l) { $p = explode("\t", $l); if (count($p) < 5) continue; $rows[] = ['at' => $p[0], 'page' => $p[1], 'ms' => (int)$p[2], 'q' => (int)$p[3], 'db' => (int)$p[4], 'who' => $p[5] ?? '', 'slow' => $p[6] ?? '']; }
    usort($rows, fn($a, $b) => $b['ms'] <=> $a['ms']); return array_slice($rows, 0, $n);
}

/* ------------------------------------------------------------------ house-keeping, once a day on the first visit */
function hk_tick(): void {
    try {
        $day = date('Y-m-d'); if (cp_setting('hk_day') === $day) return; cp_setting_set('hk_day', $day);
        $log = []; $storage = dirname(__DIR__) . '/storage';
        try { $log[] = 'notifications ' . q('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < NOW() - INTERVAL 90 DAY LIMIT 5000')->rowCount(); } catch (Throwable $e) {}
        try { $log[] = 'login_log ' . q('DELETE FROM login_log WHERE created_at < NOW() - INTERVAL 365 DAY LIMIT 5000')->rowCount(); } catch (Throwable $e) {}
        try { $log[] = 'ai_log ' . q('DELETE FROM ai_log WHERE created_at < NOW() - INTERVAL 180 DAY LIMIT 5000')->rowCount(); } catch (Throwable $e) {}
        try { $log[] = 'push_queue ' . q('DELETE FROM mob_push_queue WHERE sent_at IS NOT NULL AND sent_at < NOW() - INTERVAL 30 DAY LIMIT 5000')->rowCount(); } catch (Throwable $e) {}
        $n = 0; $cut = time() - 7 * 86400; foreach ((array) @glob($storage . '/sessions/sess_*') as $f) if (@filemtime($f) < $cut && @unlink($f)) $n++; $log[] = 'sessions ' . $n;
        $log[] = 'cache ' . cache_sweep();
        $dirs = (array) @glob($storage . '/backups/*', GLOB_ONLYDIR); rsort($dirs); $n = 0; foreach (array_slice($dirs, 3) as $d) { foreach ((array) @glob($d . '/*') as $f) @unlink($f); if (@rmdir($d)) $n++; } $log[] = 'backups ' . $n;
        foreach (['/slow-pages.log' => 2, '/php-errors.log' => 20, '/gate_debug.log' => 2] as $f => $mb) { $p = $storage . $f; if (is_file($p) && filesize($p) > $mb * 1048576) { $tail = (string) @file_get_contents($p, false, null, max(0, filesize($p) - 512 * 1024)); @file_put_contents($p, $tail); $log[] = basename($f) . ' trimmed'; } }
        cp_setting_set('hk_last', $day . ' ' . implode(' · ', $log));
    } catch (Throwable $e) { error_log('hk_tick: ' . $e->getMessage()); }
}

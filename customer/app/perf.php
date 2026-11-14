<?php
/**
 * Advanced diagnostics — GAS-CP-R97-046.  Loaded by cache.php, so every entry point has it.
 *
 *   1. timing by phase of a page      perf_mark(), perf_phases()          → slow-pages.log (8th field)
 *   2. calls to other servers         perf_out($ch, $what)                → storage/perf/outbound.log
 *   3. background jobs                nf_job(), perf_jobs_save()          → storage/perf/jobs.json
 *   4. slow statements                perf_slow_query()                   → storage/perf/slow-queries.log, EXPLAIN on demand
 *   5. PHP errors grouped             perf_err_handler(), perf_errors()   ← storage/php-errors.log (page and version added to each line)
 *   6. trend                          perf_finish(), perf_rollup()        → storage/perf/<day>.log → days.json (90 days)
 *
 * Everything is kept in small files under storage/perf — no database step, and a page pays one short file append.
 * Nothing personal is written: no user, no parameter values, no query strings (only the host of a call, statements with their literals removed).
 */
declare(strict_types=1);

const PERF_SLOW_SQL_MS = 100;      // a statement slower than this is logged
const PERF_KEEP_DAYS   = 90;

$GLOBALS['cp_perf'] = ['marks' => [], 'out_ms' => 0.0, 'out_n' => 0, 'sql_n' => 0, 'err_n' => 0];

function perf_dir(): string { static $d = null; if ($d === null) { $d = dirname(__DIR__) . '/storage/perf'; if (!is_dir($d)) @mkdir($d, 0750, true); } return $d; }
function perf_page(): string { $p = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? '')); if ($p !== '') return substr($p, 0, 40); $s = basename((string)($_SERVER['SCRIPT_NAME'] ?? '')); return $s === 'index.php' || $s === '' ? 'dashboard' : substr(preg_replace('/[^a-z0-9_.]/i', '', $s), 0, 40); }
function perf_append(string $file, string $line, int $maxBytes = 3000000): void { $f = perf_dir() . '/' . $file; if (is_file($f) && filesize($f) > $maxBytes) { $tail = (string) @file_get_contents($f, false, null, (int)(filesize($f) - $maxBytes / 2)); @file_put_contents($f, substr($tail, (int) strpos($tail, "\n") + 1), LOCK_EX); } @file_put_contents($f, $line . "\n", FILE_APPEND | LOCK_EX); }
function perf_tail(string $file, int $maxBytes = 1500000): array { $f = perf_dir() . '/' . $file; if (!is_file($f)) return []; $size = (int) filesize($f); $fh = @fopen($f, 'rb'); if (!$fh) return []; if ($size > $maxBytes) { fseek($fh, $size - $maxBytes); fgets($fh); } $raw = (string) stream_get_contents($fh); fclose($fh); return array_values(array_filter(explode("\n", $raw), fn($l) => $l !== '')); }

/* ------------------------------------------------------------------ 1. phases */
function perf_mark(string $k): void { if (!isset($GLOBALS['cp_perf']['marks'][$k])) $GLOBALS['cp_perf']['marks'][$k] = microtime(true); }
/** boot = loading the portal code and the session; prep = the page collecting its data; render = writing the HTML. db and out are parts of all three. */
function perf_phases(): array {
    $m = $GLOBALS['cp_perf']['marks']; $t0 = (float)($GLOBALS['cp_q']['t0'] ?? microtime(true)); $now = microtime(true); $route = $m['route'] ?? $t0; $head = $m['head'] ?? $route;
    return ['boot' => (int) round(($route - $t0) * 1000), 'prep' => (int) round(($head - $route) * 1000), 'render' => (int) round(($now - $head) * 1000), 'out' => (int) round($GLOBALS['cp_perf']['out_ms']), 'outn' => (int) $GLOBALS['cp_perf']['out_n']];
}
function perf_phase_text(string $s): array { $o = []; foreach (explode(';', $s) as $kv) { $p = explode('=', $kv, 2); if (count($p) === 2) $o[$p[0]] = (int)$p[1]; } return $o; }

/* ------------------------------------------------------------------ 2. calls to other servers */
/** Call right after curl_exec(), before curl_close(). Only the host is kept — never the path or the query. */
function perf_out($ch, string $what): void {
    try {
        $i = curl_getinfo($ch); $ms = (float)($i['total_time'] ?? 0) * 1000; $host = (string) (parse_url((string)($i['url'] ?? ''), PHP_URL_HOST) ?: '?'); $code = (int)($i['http_code'] ?? 0); $errno = (int) curl_errno($ch);
        $GLOBALS['cp_perf']['out_ms'] += $ms; $GLOBALS['cp_perf']['out_n']++;
        perf_append('outbound.log', date('Y-m-d H:i:s') . "\t" . $host . "\t" . preg_replace('/[^a-z0-9_ .-]/i', '', $what) . "\t" . (int) round($ms) . "\t" . $code . "\t" . $errno . "\t" . perf_page(), 1500000);
    } catch (Throwable $e) {}
}
/** Per host and purpose, last $hours: calls, failures, average, slowest, last failure. */
function perf_out_summary(int $hours = 24): array {
    $min = time() - $hours * 3600; $o = [];
    foreach (perf_tail('outbound.log') as $l) { $p = explode("\t", $l); if (count($p) < 6 || strtotime($p[0]) < $min) continue; $k = $p[1] . "\t" . $p[2]; $b = &$o[$k]; $b = $b ?? ['host' => $p[1], 'what' => $p[2], 'n' => 0, 'fail' => 0, 'sum' => 0, 'max' => 0, 'last' => '', 'last_fail' => '', 'pages' => []];
        $ms = (int)$p[3]; $code = (int)$p[4]; $bad = (int)$p[5] !== 0 || $code === 0 || $code >= 400; $b['n']++; $b['sum'] += $ms; $b['max'] = max($b['max'], $ms); $b['last'] = $p[0]; if ($bad) { $b['fail']++; $b['last_fail'] = $p[0] . ' · ' . ((int)$p[5] ? 'network error ' . (int)$p[5] : 'HTTP ' . $code); } $b['pages'][$p[6] ?? ''] = 1; unset($b); }
    uasort($o, fn($a, $b) => $b['sum'] <=> $a['sum']); return array_values($o);
}

/* ------------------------------------------------------------------ 3. background jobs */
/** Run one job of the sweep, timed and guarded. Returns what the job returned when it is a number. */
function nf_job(string $name, callable $fn): int {
    $t = microtime(true); $ok = true; $err = ''; $n = 0;
    try { $r = $fn(); if (is_int($r)) $n = $r; } catch (Throwable $e) { $ok = false; $err = mb_substr($e->getMessage(), 0, 200); }
    $GLOBALS['cp_jobs'][$name] = ['ms' => (int) round((microtime(true) - $t) * 1000), 'ok' => $ok, 'err' => $err, 'n' => $n];
    return $n;
}
function perf_jobs(): array { $f = perf_dir() . '/jobs.json'; $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null; return is_array($d) ? $d : []; }
/** Merge this sweep's jobs into jobs.json. A job that did nothing in under 2 ms keeps its last real run, only "checked" moves. */
function perf_jobs_save(float $sweepMs, string $mode): void {
    $J = $GLOBALS['cp_jobs'] ?? []; if (!$J) return; $d = perf_jobs(); $now = date('Y-m-d H:i:s');
    foreach ($J as $k => $j) { $b = $d[$k] ?? ['runs' => 0, 'fails' => 0, 'max' => 0, 'at' => '', 'ms' => 0, 'n' => 0, 'err' => '', 'err_at' => ''];
        $b['checked'] = $now; if ($j['ms'] >= 2 || $j['n'] || !$j['ok'] || $b['at'] === '') { $b['at'] = $now; $b['ms'] = $j['ms']; $b['n'] = $j['n']; $b['runs']++; $b['max'] = max((int)$b['max'], $j['ms']); }
        $b['ok'] = $j['ok']; if (!$j['ok']) { $b['fails']++; $b['err'] = $j['err']; $b['err_at'] = $now; } $d[$k] = $b; }
    $d['_sweep'] = ['at' => $now, 'ms' => (int) round($sweepMs), 'mode' => $mode, 'max' => max((int)($d['_sweep']['max'] ?? 0), (int) round($sweepMs)), 'runs' => (int)($d['_sweep']['runs'] ?? 0) + 1];
    @file_put_contents(perf_dir() . '/jobs.json', json_encode($d, JSON_UNESCAPED_UNICODE), LOCK_EX); $GLOBALS['cp_jobs'] = [];
}

/* ------------------------------------------------------------------ 4. slow statements */
/** Literals out (no personal data in the log), white space collapsed. Placeholders stay, so the statement can be explained later. */
function perf_sql_clean(string $sql): string { $s = (string) preg_replace('/\s+/', ' ', trim($sql)); $s = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "'?'", $s); return mb_substr($s, 0, 900); }
function perf_sql_key(string $clean): string { $s = (string) preg_replace('/\b\d+\b/', 'N', $clean); $s = (string) preg_replace('/\((?:\s*(?:\?|N|\'\?\')\s*,)+\s*(?:\?|N|\'\?\')\s*\)/', '(…)', $s); return $s; }
function perf_slow_query(string $sql, float $ms): void { if (++$GLOBALS['cp_perf']['sql_n'] > 10) return; perf_append('slow-queries.log', date('Y-m-d H:i:s') . "\t" . (int) round($ms) . "\t" . perf_page() . "\t" . perf_sql_clean($sql), 2000000); }
/** Statements grouped: times, total, slowest, pages, one sample. Ordered by total time. */
function perf_sql_digest(int $days = 7): array {
    $min = time() - $days * 86400; $o = [];
    foreach (perf_tail('slow-queries.log') as $l) { $p = explode("\t", $l, 4); if (count($p) < 4 || strtotime($p[0]) < $min) continue; $k = perf_sql_key($p[3]); $h = substr(sha1($k), 0, 12); $b = &$o[$h]; $b = $b ?? ['h' => $h, 'sql' => $k, 'sample' => $p[3], 'n' => 0, 'sum' => 0, 'max' => 0, 'last' => '', 'pages' => []];
        $b['n']++; $b['sum'] += (int)$p[1]; $b['max'] = max($b['max'], (int)$p[1]); $b['last'] = $p[0]; $b['pages'][$p[2]] = ($b['pages'][$p[2]] ?? 0) + 1; unset($b); }
    uasort($o, fn($a, $b) => $b['sum'] <=> $a['sum']); return $o;
}
/** EXPLAIN of a logged statement (never of text coming from the browser): placeholders become neutral values, so the plan shows which index
 *  is available and whether a table is read in full; the row estimates are approximate. SELECT only. */
function perf_sql_explain(string $hash): array {
    $d = perf_sql_digest(30); if (!isset($d[$hash])) return ['err' => 'not found']; $s = $d[$hash]['sample'];
    if (!preg_match('/^\s*SELECT\b/i', $s) || mb_strlen($s) >= 900 || str_contains($s, ';')) return ['err' => 'select'];
    $run = str_replace("'?'", "''", $s); $run = (string) preg_replace('/\?/', '0', $run);
    try { $rows = all('EXPLAIN ' . $run); } catch (Throwable $e) { return ['err' => mb_substr($e->getMessage(), 0, 240)]; }
    $hints = []; foreach ($rows as $r) { $tbl = (string)($r['table'] ?? ''); $type = (string)($r['type'] ?? ''); $n = (int)($r['rows'] ?? 0);
        if ($type === 'ALL' && $n > 500) $hints[] = ['bad', $tbl, 'full', $n]; elseif ($type === 'index' && $n > 2000) $hints[] = ['warn', $tbl, 'index_scan', $n];
        if (str_contains((string)($r['Extra'] ?? ''), 'Using filesort') && $n > 2000) $hints[] = ['warn', $tbl, 'filesort', $n];
        if (str_contains((string)($r['Extra'] ?? ''), 'Using temporary') && $n > 2000) $hints[] = ['warn', $tbl, 'temporary', $n]; }
    return ['rows' => $rows, 'hints' => $hints, 'sql' => $d[$hash]['sql']];
}

/* ------------------------------------------------------------------ 5. PHP errors with page and version */
/** Warnings and notices are written by the portal itself, in PHP's own wording plus {p=page v=version}; at most 40 per request, so one
 *  broken loop can not fill the log. Anything else (fatal errors) is left to PHP. */
function perf_err_handler(int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    $lab = [E_WARNING => 'Warning', E_NOTICE => 'Notice', E_DEPRECATED => 'Deprecated', E_USER_WARNING => 'Warning', E_USER_NOTICE => 'Notice', E_USER_DEPRECATED => 'Deprecated'][$no] ?? null; if ($lab === null) return false;
    if (++$GLOBALS['cp_perf']['err_n'] > 40) return true;
    error_log('PHP ' . $lab . ':  ' . $msg . ' in ' . $file . ' on line ' . $line . ' {p=' . perf_page() . ' v=' . (defined('CP_VERSION') ? CP_VERSION : '?') . '}');
    return true;
}
function perf_err_shutdown(): void { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) error_log('PHP Fatal ctx {p=' . perf_page() . ' v=' . (defined('CP_VERSION') ? CP_VERSION : '?') . '}'); }
function perf_errors_install(): void { set_error_handler('perf_err_handler'); register_shutdown_function('perf_err_shutdown'); }
/** The moment this version was first seen running (kept in cp_settings as perf_ver / perf_ver_at) — "new since the last release" is measured from it. */
function perf_release_at(): int { $at = function_exists('cp_setting') ? (string) cp_setting('perf_ver_at', '') : ''; return $at !== '' ? (int) strtotime($at) : 0; }
function perf_release_note(): void { if (!function_exists('cp_setting') || !defined('CP_VERSION')) return; if ((string) cp_setting('perf_ver', '') !== CP_VERSION) { cp_setting_set('perf_ver', CP_VERSION); cp_setting_set('perf_ver_at', date('Y-m-d H:i:s')); } }
/** Error log grouped by message: count, first and last time, level, pages, versions, new since the release. */
function perf_errors(int $maxBytes = 2000000): array {
    $f = dirname(__DIR__) . '/storage/php-errors.log'; if (!is_file($f)) return []; $size = (int) filesize($f); $fh = @fopen($f, 'rb'); if (!$fh) return []; if ($size > $maxBytes) { fseek($fh, $size - $maxBytes); fgets($fh); } $raw = (string) stream_get_contents($fh); fclose($fh);
    $root = dirname(__DIR__, 2); $rel = perf_release_at(); $o = []; $lastFatal = null;
    foreach (explode("\n", $raw) as $l) {
        if (!preg_match('/^\[(\d{1,2}-[A-Za-z]{3}-\d{4} \d\d:\d\d:\d\d)[^\]]*\]\s*(.*)$/', $l, $m)) continue; $ts = (int) strtotime($m[1]); $msg = $m[2]; $pg = ''; $ver = '';
        if (preg_match('/^PHP Fatal ctx \{p=([a-z0-9_.]*) v=([0-9.?]*)\}/', $msg, $c)) { if ($lastFatal !== null && isset($o[$lastFatal])) { if ($c[1] !== '') $o[$lastFatal]['pages'][$c[1]] = 1; if ($c[2] !== '') $o[$lastFatal]['vers'][$c[2]] = 1; } continue; }
        if (preg_match('/\s*\{p=([a-z0-9_.]*) v=([0-9.?]*)\}\s*$/', $msg, $c)) { $pg = $c[1]; $ver = $c[2]; $msg = (string) substr($msg, 0, -strlen($c[0])); }
        $msg = str_replace([$root . '/customer', $root], '', $msg); $key = mb_substr((string) preg_replace('/\b\d+\b/', 'N', $msg), 0, 220); $h = substr(sha1($key), 0, 12);
        $lvl = preg_match('/^PHP (Fatal|Parse)/', $msg) ? 'fatal' : (preg_match('/^PHP Warning/', $msg) ? 'warning' : (preg_match('/^PHP (Notice|Deprecated)/', $msg) ? 'notice' : 'other'));
        $b = &$o[$h]; $b = $b ?? ['h' => $h, 'msg' => $key, 'sample' => mb_substr($msg, 0, 300), 'lvl' => $lvl, 'n' => 0, 'first' => $ts, 'last' => $ts, 'pages' => [], 'vers' => []];
        $b['n']++; $b['first'] = min($b['first'], $ts); $b['last'] = max($b['last'], $ts); $b['sample'] = mb_substr($msg, 0, 300); if ($pg !== '') $b['pages'][$pg] = 1; if ($ver !== '') $b['vers'][$ver] = 1; unset($b);
        if ($lvl === 'fatal') $lastFatal = $h;
    }
    foreach ($o as &$b) { $b['new'] = $rel > 0 && $b['first'] >= $rel; $b['active'] = $rel > 0 ? $b['last'] >= $rel : true; } unset($b);
    uasort($o, fn($a, $b) => [$b['lvl'] === 'fatal', $b['new'], $b['last']] <=> [$a['lvl'] === 'fatal', $a['new'], $a['last']]); return array_values($o);
}

/* ------------------------------------------------------------------ 6. trend */
/** One line per finished page: time, page, total ms, statements, database ms, ms spent calling other servers. */
function perf_finish(string $page, array $s): void {
    if (in_array($page, ['ping', 'bg', 'csp_report'], true)) return;
    @file_put_contents(perf_dir() . '/' . date('Y-m-d') . '.log', date('H:i') . "\t" . $page . "\t" . (int)$s['page_ms'] . "\t" . (int)$s['queries'] . "\t" . (int)$s['db_ms'] . "\t" . (int) round($GLOBALS['cp_perf']['out_ms']) . "\n", FILE_APPEND | LOCK_EX);
}
function perf_pct(array $sorted, float $p): int { $n = count($sorted); if (!$n) return 0; return (int) $sorted[(int) min($n - 1, max(0, ceil($p * $n) - 1))]; }
/** Figures of one day file: all pages together and per page. */
function perf_day_stats(string $file): array {
    $all = []; $pg = []; $q = 0; $db = 0; $out = 0;
    foreach ((array) @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $p = explode("\t", (string)$l); if (count($p) < 5) continue; $ms = (int)$p[2]; $all[] = $ms; $q += (int)$p[3]; $db += (int)$p[4]; $out += (int)($p[5] ?? 0); $b = &$pg[$p[1]]; $b = $b ?? ['ms' => [], 'q' => 0, 'db' => 0]; $b['ms'][] = $ms; $b['q'] += (int)$p[3]; $b['db'] += (int)$p[4]; unset($b); }
    $n = count($all); if (!$n) return ['all' => ['n' => 0, 'p50' => 0, 'p95' => 0, 'max' => 0, 'q' => 0, 'db' => 0, 'out' => 0], 'pages' => []]; sort($all); $pages = [];
    foreach ($pg as $k => $b) { sort($b['ms']); $c = count($b['ms']); $pages[$k] = ['n' => $c, 'p50' => perf_pct($b['ms'], 0.5), 'p95' => perf_pct($b['ms'], 0.95), 'max' => (int) end($b['ms']), 'q' => (int) round($b['q'] / $c), 'db' => (int) round($b['db'] / $c)]; }
    uasort($pages, fn($a, $b) => $b['n'] * $b['p50'] <=> $a['n'] * $a['p50']);
    return ['all' => ['n' => $n, 'p50' => perf_pct($all, 0.5), 'p95' => perf_pct($all, 0.95), 'max' => (int) end($all), 'q' => (int) round($q / $n), 'db' => (int) round($db / $n), 'out' => (int) round($out / $n)], 'pages' => array_slice($pages, 0, 60, true)];
}
function perf_days(): array { $f = perf_dir() . '/days.json'; $d = is_file($f) ? json_decode((string) @file_get_contents($f), true) : null; return is_array($d) ? $d : []; }
/** Fold finished days into days.json and drop their raw files; keeps PERF_KEEP_DAYS days. Cheap when there is nothing to do. */
function perf_rollup(): int {
    $today = date('Y-m-d'); $files = array_filter((array) @glob(perf_dir() . '/????-??-??.log'), fn($f) => basename((string)$f, '.log') < $today); if (!$files) return 0;
    $d = perf_days(); foreach ($files as $f) { $day = basename((string)$f, '.log'); $d[$day] = perf_day_stats((string)$f); @unlink((string)$f); }
    ksort($d); $d = array_slice($d, -PERF_KEEP_DAYS, null, true); @file_put_contents(perf_dir() . '/days.json', json_encode($d), LOCK_EX); return count($files);
}
/** [day => stats] of the last $n days, today included (computed live). */
function perf_trend(int $n = 30): array { perf_rollup(); $d = perf_days(); $t = perf_dir() . '/' . date('Y-m-d') . '.log'; if (is_file($t)) $d[date('Y-m-d')] = perf_day_stats($t); ksort($d); return array_slice($d, -$n, null, true); }
function perf_clear(string $what): void { $map = ['out' => ['outbound.log'], 'sql' => ['slow-queries.log'], 'jobs' => ['jobs.json'], 'trend' => ['days.json']]; foreach ($map[$what] ?? [] as $f) @unlink(perf_dir() . '/' . $f); if ($what === 'trend') foreach ((array) @glob(perf_dir() . '/????-??-??.log') as $f) @unlink((string)$f); }

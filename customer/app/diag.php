<?php
/** Diagnostics — database and application checks that catch what makes the portal hang or fail, run on demand from Settings › About.   GAS-CP-R69-001
 *  Every check is read-only, bounded in time, wrapped in try/catch; a check that cannot run reports WARN with the reason instead of breaking the page. */
declare(strict_types=1);

const DIAG_CORE_TABLES = ['customer_users', 'customers', 'vessels', 'projects', 'subprojects', 'tickets', 'ticket_comments', 'timesheets', 'timesheet_lines', 'plan_entries', 'hr_people', 'hr_requests', 'hr_entitlements', 'notifications', 'disciplines', 'cp_settings'];

/** One result row. */
function diag_row(string $group, string $name, string $state, string $detail = '', string $fix = ''): array { return ['group' => $group, 'name' => $name, 'state' => $state, 'detail' => $detail, 'fix' => $fix]; }
function diag_fmt_bytes(int $b): string { return $b >= 1073741824 ? round($b / 1073741824, 2) . ' GB' : ($b >= 1048576 ? round($b / 1048576, 1) . ' MB' : round($b / 1024) . ' kB'); }
function diag_error_log_path(): string { return dirname(__DIR__) . '/storage/php-errors.log'; }

/** Run everything; returns ['rows' => [...], 'ms' => total time, 'at' => datetime]. */
function diag_run(): array {
    $t0 = microtime(true); $R = []; $storage = dirname(__DIR__) . '/storage';
    $try = function (callable $f, string $group, string $name) use (&$R) { try { $t = microtime(true); $f(); } catch (Throwable $e) { $R[] = diag_row($group, $name, 'warn', t('dg.check_failed') . ': ' . mb_substr($e->getMessage(), 0, 200)); } };

    /* ---------------------------------------------------------------- database */
    $try(function () use (&$R) {
        $v = (string) scalar('SELECT VERSION()'); $st = []; foreach (all("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Uptime','Aborted_connects','Slow_queries','Innodb_row_lock_waits','Innodb_row_lock_time_max')") as $r) $st[$r['Variable_name']] = $r['Value'];
        $vars = []; foreach (all("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('max_connections','wait_timeout','max_allowed_packet','innodb_lock_wait_timeout','long_query_time','sql_mode')") as $r) $vars[$r['Variable_name']] = $r['Value'];
        $conn = (int)($st['Threads_connected'] ?? 0); $max = (int)($vars['max_connections'] ?? 0); $pct = $max ? (int) round($conn / $max * 100) : 0;
        $R[] = diag_row('db', t('dg.db_server'), 'ok', 'MySQL ' . $v . ' · ' . t('dg.uptime') . ' ' . (int) round(((int)($st['Uptime'] ?? 0)) / 3600) . ' h · ' . t('dg.connections') . ' ' . $conn . ' / ' . $max . ' (' . t('dg.running') . ' ' . (int)($st['Threads_running'] ?? 0) . ')');
        if ($pct >= 80) $R[] = diag_row('db', t('dg.connections'), 'fail', $conn . ' / ' . $max . ' (' . $pct . ' %)', t('dg.fix_connections'));
        if ((int)($vars['max_allowed_packet'] ?? 0) < 16 * 1048576) $R[] = diag_row('db', 'max_allowed_packet', 'warn', diag_fmt_bytes((int)$vars['max_allowed_packet']), t('dg.fix_packet'));
        if ((int)($st['Innodb_row_lock_waits'] ?? 0) > 0) $R[] = diag_row('db', t('dg.lock_waits'), (int)($st['Innodb_row_lock_time_max'] ?? 0) > 10000 ? 'warn' : 'ok', t('dg.lock_waits_detail', ['n' => (int)$st['Innodb_row_lock_waits'], 'ms' => (int)($st['Innodb_row_lock_time_max'] ?? 0)]));
        if ((int)($st['Aborted_connects'] ?? 0) > 100) $R[] = diag_row('db', t('dg.aborted'), 'warn', (string)$st['Aborted_connects'], t('dg.fix_aborted'));
        if (str_contains((string)($vars['sql_mode'] ?? ''), 'ONLY_FULL_GROUP_BY')) $R[] = diag_row('db', 'sql_mode', 'ok', 'ONLY_FULL_GROUP_BY ' . t('dg.on'));
    }, 'db', t('dg.db_server'));

    // migrations (the About page already lists them; here only the missing ones)
    $try(function () use (&$R) {
        $state = cp_migration_state(); $missing = [];
        foreach (cp_patches() as $p) if (!empty($p['check']) && isset($state[$p['check']]) && !$state[$p['check']]) $missing[] = $p['ref'] . ' (' . ($p['db'] ?? '') . ')';
        $R[] = $missing ? diag_row('db', t('dg.migrations'), 'fail', implode(', ', $missing), t('dg.fix_migrations')) : diag_row('db', t('dg.migrations'), 'ok', count(array_filter($state)) . ' / ' . count($state));
    }, 'db', t('dg.migrations'));

    // what is running now — the usual cause of a "hung" portal
    $try(function () use (&$R) {
        $long = all("SELECT id, user, time, state, LEFT(info, 160) AS info FROM information_schema.processlist WHERE command <> 'Sleep' AND time > 15 AND id <> CONNECTION_ID() ORDER BY time DESC LIMIT 8");
        $R[] = $long ? diag_row('db', t('dg.long_queries'), 'fail', implode(' | ', array_map(fn($q) => $q['time'] . ' s · ' . ($q['state'] ?: '—') . ' · ' . ($q['info'] ?: '—'), $long)), t('dg.fix_long')) : diag_row('db', t('dg.long_queries'), 'ok', t('dg.none'));
        $sleep = (int) scalar("SELECT COUNT(*) FROM information_schema.processlist WHERE command = 'Sleep' AND time > 300");
        if ($sleep > 20) $R[] = diag_row('db', t('dg.idle_conn'), 'warn', (string)$sleep, t('dg.fix_idle'));
        $trx = []; try { $trx = all("SELECT trx_id, trx_state, TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS secs, trx_rows_locked, LEFT(trx_query, 120) AS q FROM information_schema.innodb_trx WHERE TIMESTAMPDIFF(SECOND, trx_started, NOW()) > 20 ORDER BY trx_started LIMIT 5"); } catch (Throwable $e) {}
        if ($trx) $R[] = diag_row('db', t('dg.open_trx'), 'fail', implode(' | ', array_map(fn($x) => $x['secs'] . ' s · ' . $x['trx_state'] . ' · ' . t('dg.rows_locked') . ' ' . $x['trx_rows_locked'] . ' · ' . ($x['q'] ?: '—'), $trx)), t('dg.fix_trx'));
        $waits = 0; try { $waits = (int) scalar('SELECT COUNT(*) FROM performance_schema.data_lock_waits'); } catch (Throwable $e) { try { $waits = (int) scalar('SELECT COUNT(*) FROM information_schema.innodb_lock_waits'); } catch (Throwable $e2) {} }
        if ($waits) $R[] = diag_row('db', t('dg.lock_now'), 'fail', (string)$waits, t('dg.fix_trx'));
    }, 'db', t('dg.long_queries'));

    // table health
    $try(function () use (&$R) {
        $bad = []; $n = 0;
        foreach (DIAG_CORE_TABLES as $t) { try { foreach (all("CHECK TABLE `$t` QUICK") as $r) { $n++; if (strtolower((string)$r['Msg_type']) !== 'status' || strtolower((string)$r['Msg_text']) !== 'ok') $bad[] = $t . ': ' . $r['Msg_text']; } } catch (Throwable $e) { $bad[] = $t . ': ' . mb_substr($e->getMessage(), 0, 80); } }
        $R[] = $bad ? diag_row('db', t('dg.tables'), 'fail', implode(' | ', $bad), t('dg.fix_tables')) : diag_row('db', t('dg.tables'), 'ok', t('dg.tables_ok', ['n' => $n]));
        $sizes = all("SELECT table_name AS t, table_rows AS r, data_length + index_length AS bytes FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY bytes DESC LIMIT 6");
        $R[] = diag_row('db', t('dg.sizes'), 'ok', implode(' · ', array_map(fn($s) => $s['t'] . ' ' . diag_fmt_bytes((int)$s['bytes']) . ' (' . number_format((int)$s['r']) . ')', $sizes)));
        foreach (['notifications' => 300000, 'login_log' => 300000, 'ai_log' => 100000, 'hr_audit' => 300000, 'plan_entries' => 500000] as $t => $lim) { try { $c = (int) scalar("SELECT COUNT(*) FROM `$t`"); if ($c > $lim) $R[] = diag_row('db', $t, 'warn', number_format($c) . ' ' . t('dg.rows'), t('dg.fix_bigtable')); } catch (Throwable $e) {} }
    }, 'db', t('dg.tables'));

    // orphans and duplicates — the things that make pages throw or show half the data
    $try(function () use (&$R) {
        $orph = [];
        $q = [
            'hr_people → disciplines' => 'SELECT COUNT(*) FROM hr_people p LEFT JOIN disciplines d ON d.id = p.department_id WHERE p.department_id IS NOT NULL AND d.id IS NULL',
            'customer_users → disciplines' => 'SELECT COUNT(*) FROM customer_users u LEFT JOIN disciplines d ON d.id = u.discipline_id WHERE u.discipline_id IS NOT NULL AND d.id IS NULL',
            'customer_users → manager' => 'SELECT COUNT(*) FROM customer_users u LEFT JOIN customer_users m ON m.id = u.manager_user_id WHERE u.manager_user_id IS NOT NULL AND m.id IS NULL',
            'hr_people → customer_users' => 'SELECT COUNT(*) FROM hr_people p LEFT JOIN customer_users u ON u.id = p.user_id WHERE p.user_id IS NOT NULL AND u.id IS NULL',
            'hr_requests → hr_people' => 'SELECT COUNT(*) FROM hr_requests r LEFT JOIN hr_people p ON p.id = r.person_id WHERE p.id IS NULL',
            'hr_requests → hr_leave_types' => 'SELECT COUNT(*) FROM hr_requests r LEFT JOIN hr_leave_types t ON t.id = r.type_id WHERE t.id IS NULL',
            'plan_entries → customer_users' => 'SELECT COUNT(*) FROM plan_entries e LEFT JOIN customer_users u ON u.id = e.user_id WHERE u.id IS NULL',
            'plan_entries → hr_requests' => 'SELECT COUNT(*) FROM plan_entries e LEFT JOIN hr_requests r ON r.id = e.request_id WHERE e.request_id IS NOT NULL AND r.id IS NULL',
            'tickets → vessels' => 'SELECT COUNT(*) FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id WHERE t.vessel_id IS NOT NULL AND v.id IS NULL',
            'subprojects → projects' => 'SELECT COUNT(*) FROM subprojects s LEFT JOIN projects p ON p.id = s.project_id WHERE p.id IS NULL',
            'projects → vessels' => 'SELECT COUNT(*) FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.vessel_id IS NOT NULL AND v.id IS NULL',
            'timesheet_lines → timesheets' => 'SELECT COUNT(*) FROM timesheet_lines l LEFT JOIN timesheets t ON t.id = l.timesheet_id WHERE t.id IS NULL',
            'hr_targets → disciplines / people' => 'SELECT (SELECT COUNT(*) FROM hr_targets t LEFT JOIN disciplines d ON d.id = t.department_id WHERE t.scope = "department" AND d.id IS NULL) + (SELECT COUNT(*) FROM hr_targets t LEFT JOIN hr_people p ON p.id = t.person_id WHERE t.scope = "person" AND p.id IS NULL)',
            'hr_training_members → trainings' => 'SELECT COUNT(*) FROM hr_training_members m LEFT JOIN hr_trainings tr ON tr.id = m.training_id WHERE tr.id IS NULL',
        ];
        foreach ($q as $k => $sql) { try { $n = (int) scalar($sql); if ($n) $orph[] = $k . ' ' . $n; } catch (Throwable $e) {} }
        $R[] = $orph ? diag_row('db', t('dg.orphans'), 'warn', implode(' · ', $orph), t('dg.fix_orphans')) : diag_row('db', t('dg.orphans'), 'ok', t('dg.none'));
        $dup = [];
        try { $names = []; foreach (all('SELECT id, name FROM disciplines') as $d) { $k = function_exists('disc_norm') ? disc_norm($d['name']) : mb_strtolower($d['name']); $names[$k][] = $d['name']; } foreach ($names as $g) if (count($g) > 1) $dup[] = t('dg.disciplines') . ': ' . implode(' = ', $g); } catch (Throwable $e) {}
        try { foreach (all('SELECT name_en, COUNT(*) n FROM hr_people WHERE is_active = 1 GROUP BY name_en HAVING n > 1') as $d) $dup[] = t('dg.people') . ': ' . $d['name_en'] . ' ×' . $d['n']; } catch (Throwable $e) {}
        try { foreach (all('SELECT LOWER(email) e, COUNT(*) n FROM customer_users GROUP BY LOWER(email) HAVING n > 1') as $d) $dup[] = t('dg.accounts') . ': ' . $d['e'] . ' ×' . $d['n']; } catch (Throwable $e) {}
        try { foreach (all('SELECT name, COUNT(*) n FROM vessels GROUP BY name HAVING n > 1 LIMIT 5') as $d) $dup[] = t('nav.vessels') . ': ' . $d['name'] . ' ×' . $d['n']; } catch (Throwable $e) {}
        $R[] = $dup ? diag_row('db', t('dg.duplicates'), 'warn', implode(' · ', array_slice($dup, 0, 12)), t('dg.fix_duplicates')) : diag_row('db', t('dg.duplicates'), 'ok', t('dg.none'));
    }, 'db', t('dg.orphans'));

    // backlog that grows silently
    $try(function () use (&$R) {
        $n = (int) scalar('SELECT COUNT(*) FROM notifications WHERE read_at IS NULL'); if ($n > 20000) $R[] = diag_row('db', t('dg.unread_nf'), 'warn', number_format($n), t('dg.fix_bigtable'));
        try { $q = (int) scalar("SELECT COUNT(*) FROM mob_push_queue WHERE sent_at IS NULL"); if ($q > 500) $R[] = diag_row('db', t('dg.push_queue'), 'warn', number_format($q), t('dg.fix_push')); } catch (Throwable $e) {}
        try { $f = (int) scalar("SELECT COUNT(*) FROM login_log WHERE outcome IN ('failed','locked') AND created_at > NOW() - INTERVAL 1 DAY"); if ($f > 50) $R[] = diag_row('db', t('dg.failed_logins'), 'warn', number_format($f) . ' / 24 h', t('dg.fix_logins')); } catch (Throwable $e) {}
        try { $a = (int) scalar("SELECT COUNT(*) FROM ai_log WHERE ok = 0 AND created_at > NOW() - INTERVAL 1 DAY"); if ($a > 5) $R[] = diag_row('db', t('dg.ai_errors'), 'warn', (string)$a . ' / 24 h · ' . (string)(scalar("SELECT error FROM ai_log WHERE ok = 0 ORDER BY id DESC LIMIT 1") ?? ''), t('dg.fix_ai')); } catch (Throwable $e) {}
    }, 'db', t('dg.unread_nf'));

    // timing of the heaviest page pieces — a slow dashboard feels like a hang
    $try(function () use (&$R) {
        $samples = []; $y = (int) date('Y');
        if (function_exists('act_top')) { $t = microtime(true); act_top("$y-01-01", date('Y-m-d'), 10); $samples['Fleet & activity'] = (int) round((microtime(true) - $t) * 1000); }
        if (function_exists('hr_people')) { $t = microtime(true); $pp = hr_people(false); $samples['HR people'] = (int) round((microtime(true) - $t) * 1000); if (function_exists('hr_balance') && function_exists('hr_annual_type_id') && $pp && ($ann = hr_annual_type_id())) { $t = microtime(true); $k = 0; foreach (array_slice($pp, 0, 10) as $p) { hr_balance($p, $ann, $y); $k++; } $samples['HR balances ×' . $k] = (int) round((microtime(true) - $t) * 1000); } }
        if (function_exists('cel_upcoming')) { $t = microtime(true); cel_upcoming(14); $samples['Celebrations'] = (int) round((microtime(true) - $t) * 1000); }
        $t = microtime(true); scalar('SELECT COUNT(*) FROM tickets'); $samples['tickets COUNT'] = (int) round((microtime(true) - $t) * 1000);
        $slow = array_filter($samples, fn($ms) => $ms > 1500); $state = $slow ? 'warn' : 'ok';
        $R[] = diag_row('app', t('dg.timings'), $state, implode(' · ', array_map(fn($k, $v) => $k . ' ' . $v . ' ms', array_keys($samples), $samples)), $slow ? t('dg.fix_slow') : '');
    }, 'app', t('dg.timings'));

    /* ---------------------------------------------------------------- application */
    $try(function () use (&$R, $storage) {
        $R[] = diag_row('app', 'PHP', version_compare(PHP_VERSION, '8.1.0', '>=') ? 'ok' : 'fail', PHP_VERSION . ' · memory_limit ' . ini_get('memory_limit') . ' · max_execution_time ' . ini_get('max_execution_time') . ' s · upload ' . ini_get('upload_max_filesize') . ' / post ' . ini_get('post_max_size') . ' · opcache ' . (function_exists('opcache_get_status') && @opcache_get_status(false) ? t('dg.on') : t('dg.off')), version_compare(PHP_VERSION, '8.1.0', '>=') ? '' : t('dg.fix_php'));
        $ext = []; foreach (['pdo_mysql', 'mbstring', 'curl', 'sodium', 'zip', 'json', 'openssl', 'gd', 'fileinfo', 'intl'] as $e) if (!extension_loaded($e)) $ext[] = $e;
        $R[] = $ext ? diag_row('app', t('dg.extensions'), in_array('pdo_mysql', $ext, true) || in_array('mbstring', $ext, true) ? 'fail' : 'warn', t('dg.missing') . ': ' . implode(', ', $ext), t('dg.fix_ext')) : diag_row('app', t('dg.extensions'), 'ok', t('dg.all_present'));
        $mem = (int) ini_get('memory_limit'); if ($mem > 0 && $mem < 128) $R[] = diag_row('app', 'memory_limit', 'warn', ini_get('memory_limit'), t('dg.fix_memory'));
        $met = (int) ini_get('max_execution_time'); if ($met > 0 && $met < 60) $R[] = diag_row('app', 'max_execution_time', 'warn', $met . ' s', t('dg.fix_exec'));
        if (ini_get('display_errors') === '1') $R[] = diag_row('app', 'display_errors', 'warn', t('dg.on'), t('dg.fix_display'));
    }, 'app', 'PHP');

    $try(function () use (&$R, $storage) {
        $bad = []; foreach (['', '/uploads', '/hr', '/updates', '/backups', '/sessions', '/private', '/import'] as $d) { $p = $storage . $d; if (!is_dir($p)) @mkdir($p, 0750, true); if (!is_dir($p) || !is_writable($p)) $bad[] = 'storage' . $d; }
        $R[] = $bad ? diag_row('app', t('dg.storage'), 'fail', t('dg.not_writable') . ': ' . implode(', ', $bad), t('dg.fix_storage')) : diag_row('app', t('dg.storage'), 'ok', t('dg.all_writable'));
        $free = @disk_free_space($storage); if ($free !== false) $R[] = diag_row('app', t('dg.disk'), $free < 300 * 1048576 ? 'fail' : ($free < 1024 * 1048576 ? 'warn' : 'ok'), diag_fmt_bytes((int)$free) . ' ' . t('dg.free'), $free < 1024 * 1048576 ? t('dg.fix_disk') : '');
        $n = 0; $old = 0; $cut = time() - 7 * 86400; foreach ((array) @glob($storage . '/sessions/sess_*') as $f) { $n++; if (@filemtime($f) < $cut) $old++; }
        $R[] = diag_row('app', t('dg.sessions'), $old > 2000 ? 'warn' : 'ok', t('dg.sessions_detail', ['n' => $n, 'o' => $old]), $old > 2000 ? t('dg.fix_sessions') : '');
        $bk = 0; $bkB = 0; foreach ((array) @glob($storage . '/backups/*', GLOB_ONLYDIR) as $d) { $bk++; foreach ((array) @glob($d . '/*') as $f) $bkB += (int) @filesize($f); }
        if ($bk > 15 || $bkB > 2 * 1073741824) $R[] = diag_row('app', t('dg.backups'), 'warn', $bk . ' · ' . diag_fmt_bytes($bkB), t('dg.fix_backups'));
        $lockF = $storage . '/update.lock'; if (is_file($lockF)) { $h = @fopen($lockF, 'c'); $locked = $h && !flock($h, LOCK_EX | LOCK_NB); if ($h) { @flock($h, LOCK_UN); fclose($h); } if ($locked) $R[] = diag_row('app', t('dg.update_lock'), 'fail', t('dg.update_running'), t('dg.fix_lock')); }
    }, 'app', t('dg.storage'));

    $try(function () use (&$R) {
        $tok = (string) cfg('update.token', ''); $https = str_starts_with((string) cfg('app.base_url', ''), 'https://');
        $R[] = diag_row('app', t('dg.config'), (strlen($tok) >= 32 && $https) ? 'ok' : 'warn', ($https ? 'https' : 'http') . ' · ' . t('dg.update_token') . ' ' . (strlen($tok) >= 32 ? t('dg.set') : t('dg.weak')) . ' · ' . t('dg.cookie_secure') . ' ' . (ini_get('session.cookie_secure') ? t('dg.on') : t('dg.off')), (strlen($tok) >= 32 && $https) ? '' : t('dg.fix_config'));
        if (function_exists('ai_can') && function_exists('ai_setting') && ai_setting('ai_enabled', '0') === '1') { $t = microtime(true); $ch = curl_init(rtrim((string) cfg('ai.base_url', 'https://api.anthropic.com'), '/') . '/v1/models'); curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_SSL_VERIFYPEER => true]); curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE); $err = curl_error($ch); curl_close($ch); $ms = (int) round((microtime(true) - $t) * 1000); $R[] = diag_row('app', t('dg.ai_reach'), $code > 0 ? 'ok' : 'warn', $code > 0 ? 'HTTP ' . $code . ' · ' . $ms . ' ms' : $err, $code > 0 ? '' : t('dg.fix_ai')); }
    }, 'app', t('dg.config'));

    // the error log the portal keeps since R69-001
    $try(function () use (&$R) {
        $f = diag_error_log_path(); if (!is_file($f)) { $R[] = diag_row('app', t('dg.errlog'), 'ok', t('dg.errlog_empty')); return; }
        $size = (int) filesize($f); $tail = ''; $h = fopen($f, 'r'); if ($h) { fseek($h, max(0, $size - 65536)); $tail = (string) stream_get_contents($h); fclose($h); }
        $lines = array_values(array_filter(explode("\n", $tail))); $recent = array_slice($lines, -12); $cut = date('d-M-Y', time() - 7 * 86400);
        $fatal = count(array_filter($lines, fn($l) => stripos($l, 'fatal') !== false || stripos($l, 'Uncaught') !== false)); $week = count(array_filter($lines, fn($l) => preg_match('/^\[(\d{2}-\w{3}-\d{4})/', $l, $m) && strtotime($m[1]) >= strtotime($cut)));
        $R[] = diag_row('app', t('dg.errlog'), $fatal ? 'fail' : ($week ? 'warn' : 'ok'), diag_fmt_bytes($size) . ' · ' . t('dg.errlog_detail', ['f' => $fatal, 'w' => $week]) . ($recent ? "\n" . implode("\n", array_map(fn($l) => mb_substr($l, 0, 220), $recent)) : ''), $fatal || $week ? t('dg.fix_errlog') : '');
        if ($size > 20 * 1048576) $R[] = diag_row('app', t('dg.errlog'), 'warn', diag_fmt_bytes($size), t('dg.fix_errlog_size'));
    }, 'app', t('dg.errlog'));

    return ['rows' => $R, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'at' => date('Y-m-d H:i:s')];
}
/** Plain-text report for download / e-mail to the developer. */
function diag_text(array $res): string {
    $o = 'Golden A/S Customer Portal — diagnostics ' . $res['at'] . ' · v' . CP_VERSION . ' · ' . $res['ms'] . " ms\n\n";
    foreach ($res['rows'] as $r) $o .= sprintf("[%s] %s › %s: %s%s\n", strtoupper($r['state']), $r['group'], $r['name'], str_replace("\n", "\n        ", $r['detail']), $r['fix'] !== '' ? "\n        → " . $r['fix'] : '');
    return $o;
}
/** Truncate the error log (admin). */
function diag_errlog_clear(): void { require_cp_admin(); $f = diag_error_log_path(); if (is_file($f)) @file_put_contents($f, ''); }

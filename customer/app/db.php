<?php
declare(strict_types=1);

function cfg(string $path, $default = null) {
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/config.php';
    $node = $c;
    foreach (explode('.', $path) as $k) {
        if (!is_array($node) || !array_key_exists($k, $node)) return $default;
        $node = $node[$k];
    }
    return $node;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        cfg('db.host'), (int)cfg('db.port'), cfg('db.name'), cfg('db.charset'));
    $pdo = new PDO($dsn, cfg('db.user'), cfg('db.pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    try {   // R46-002: NOW() / CURDATE() of this connection follow the configured database zone, so stored moments stay consistent
        $tz = (string)(cfg('app.db_tz', 'UTC') ?: 'UTC'); $tzv = function_exists('cache_get') ? cache_get('tz_db') : null;   // R97-079: kept a minute
        if (!is_string($tzv)) { $r = $pdo->query("SELECT v FROM cp_settings WHERE k = 'tz_db'")->fetch(); $tzv = $r && $r['v'] ? (string)$r['v'] : ''; if (function_exists('cache_set')) cache_set('tz_db', $tzv, 60); }
        if ($tzv !== '' && in_array($tzv, timezone_identifiers_list(), true)) $tz = $tzv;
        $off = (new DateTime('now', new DateTimeZone($tz)))->format('P'); $pdo->exec("SET time_zone = '" . $off . "'");
    } catch (Throwable $e) {}
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $t = microtime(true); $s = db()->prepare($sql); $s->execute($p); if (function_exists('db_meter')) db_meter($sql, (microtime(true) - $t) * 1000); return $s; }   // R69-002: query meter
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array {
    if (!$p && strncmp($sql, 'SHOW TABLES LIKE ', 17) === 0 && ($n = db_schema_answer($sql)) !== null) return ['t' => $n];   // R97-079
    $r = q($sql, $p)->fetch(); return $r === false ? null : $r;
}
function scalar(string $sql, array $p = []) {
    if (!$p && strncmp($sql, 'SHOW ', 5) === 0 && ($n = db_schema_answer($sql)) !== null) return $n;   // R97-079: "is this table / column there?" answered from the kept schema
    return q($sql, $p)->fetchColumn();
}

/* ------------------------------------------------------------------ R97-079: the schema, kept between requests
 * Every module asks on every page whether its tables and columns exist (SHOW TABLES LIKE …, SHOW COLUMNS FROM … LIKE …) — dozens of
 * statements per page that always give the same answer. The list of tables and, per table, of columns is read once and kept for five
 * minutes (APCu or storage/cache, and per release). Only a "yes" is answered from it; when the kept schema says "no" the real
 * statement runs, so a table or column added a moment ago is seen at once. */
function db_schema(): array {
    if (isset($GLOBALS['cp_schema'])) return $GLOBALS['cp_schema'];
    $S = function_exists('cache_get') ? cache_get('db_schema') : null;
    if (!is_array($S) || !isset($S['t'], $S['c'])) {
        $S = ['t' => [], 'c' => []];
        try { foreach (db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $n) $S['t'][(string)$n] = 1; if (function_exists('db_meter')) db_meter('SHOW TABLES', 0.0); } catch (Throwable $e) { return ['t' => [], 'c' => []]; }
        $GLOBALS['cp_schema_dirty'] = true;
    }
    if (!isset($GLOBALS['cp_schema_hook'])) { $GLOBALS['cp_schema_hook'] = true; register_shutdown_function(function () { if (!empty($GLOBALS['cp_schema_dirty']) && function_exists('cache_set') && isset($GLOBALS['cp_schema'])) cache_set('db_schema', $GLOBALS['cp_schema'], 300); }); }
    return $GLOBALS['cp_schema'] = $S;
}
function db_schema_forget(): void { unset($GLOBALS['cp_schema']); if (function_exists('cache_forget')) cache_forget('db_schema'); }
/** The answer the statement would give when it is a plain existence check and the kept schema says yes: the table / column name. Null = run the statement. */
function db_schema_answer(string $sql): ?string {
    if (preg_match("/^SHOW TABLES LIKE '([A-Za-z0-9_]+)'$/", $sql, $m)) { $S = db_schema(); return isset($S['t'][$m[1]]) ? $m[1] : null; }
    if (!preg_match("/^SHOW COLUMNS FROM `?([A-Za-z0-9_]+)`? LIKE '([A-Za-z0-9_]+)'$/", $sql, $m)) return null;
    $S = db_schema(); if (!isset($S['t'][$m[1]])) return null;
    if (!isset($S['c'][$m[1]])) {
        try { $cols = []; foreach (db()->query('SHOW COLUMNS FROM `' . $m[1] . '`')->fetchAll(PDO::FETCH_COLUMN) as $c) $cols[(string)$c] = 1; if (function_exists('db_meter')) db_meter('SHOW COLUMNS FROM ' . $m[1], 0.0); } catch (Throwable $e) { return null; }
        $GLOBALS['cp_schema']['c'][$m[1]] = $cols; $GLOBALS['cp_schema_dirty'] = true; $S = $GLOBALS['cp_schema'];
    }
    return isset($S['c'][$m[1]][$m[2]]) ? $m[2] : null;
}

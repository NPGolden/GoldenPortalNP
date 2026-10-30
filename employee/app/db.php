<?php
/** GAS-POMS — bootstrap, PDO connection, settings access. */

declare(strict_types=1);

if (!file_exists(__DIR__ . '/config.php')) {
    http_response_code(500);
    exit('Configuration missing. Copy app/config.sample.php to app/config.php and set your database details.');
}

$GLOBALS['CFG'] = require __DIR__ . '/config.php';

date_default_timezone_set($GLOBALS['CFG']['app']['timezone'] ?? 'Europe/Athens');
mb_internal_encoding('UTF-8');

function cfg(string $path, $default = null) {
    $node = $GLOBALS['CFG'];
    foreach (explode('.', $path) as $key) {
        if (!is_array($node) || !array_key_exists($key, $node)) return $default;
        $node = $node[$key];
    }
    return $node;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $d   = cfg('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $d['host'], (int)$d['port'], $d['name'], $d['charset']);

    try {
        $pdo = new PDO($dsn, $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        error_log('DB connect failed: ' . $e->getMessage());
        http_response_code(500);
        exit('The database is not reachable. Check app/config.php.');
    }
    return $pdo;
}

/** Run a prepared statement and return the statement. */
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}

function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}

function scalar(string $sql, array $params = []) {
    return q($sql, $params)->fetchColumn();
}

/** Settings table, cached per request. Config file wins when non-empty. */
function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all('SELECT k, v FROM settings') as $row) {
            $cache[$row['k']] = $row['v'];
        }
    }
    return $cache[$key] ?? $default;
}

function setting_put(string $key, ?string $value): void {
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
}

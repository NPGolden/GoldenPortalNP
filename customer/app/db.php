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
        $tz = (string)(cfg('app.db_tz', 'UTC') ?: 'UTC'); $r = $pdo->query("SELECT v FROM cp_settings WHERE k = 'tz_db'")->fetch(); if ($r && $r['v'] && in_array($r['v'], timezone_identifiers_list(), true)) $tz = $r['v'];
        $off = (new DateTime('now', new DateTimeZone($tz)))->format('P'); $pdo->exec("SET time_zone = '" . $off . "'");
    } catch (Throwable $e) {}
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function all(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function one(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r === false ? null : $r; }
function scalar(string $sql, array $p = []) { return q($sql, $p)->fetchColumn(); }

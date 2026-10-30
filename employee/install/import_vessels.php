<?php
/**
 * GAS-POMS — command-line vessel import.
 *   php install/import_vessels.php vessels.csv
 * Header row required. Columns: name (required), imo, hull_no, yard.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

require __DIR__ . '/../app/db.php';

$path = $argv[1] ?? '';
if (!$path || !is_file($path)) exit("Usage: php install/import_vessels.php <file.csv>\n");

$fh    = fopen($path, 'r');
$first = (string) fgets($fh);
if (str_starts_with($first, "\xEF\xBB\xBF")) $first = substr($first, 3);   // Excel BOM
$delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
$cols  = array_map(fn($h) => strtolower(trim((string)$h)), str_getcsv(rtrim($first, "\r\n"), $delim));
$idx  = fn(string $k) => array_search($k, $cols, true);
if ($idx('name') === false) exit("The CSV needs a 'name' column.\n");

$n = 0;
while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    $name = trim((string)($row[$idx('name')] ?? ''));
    if ($name === '') continue;
    q('INSERT INTO vessels (name, imo, hull_no, yard) VALUES (?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE imo = VALUES(imo), hull_no = VALUES(hull_no), yard = VALUES(yard), is_active = 1',
      [$name,
       $idx('imo')     !== false ? (trim((string)($row[$idx('imo')]     ?? '')) ?: null) : null,
       $idx('hull_no') !== false ? (trim((string)($row[$idx('hull_no')] ?? '')) ?: null) : null,
       $idx('yard')    !== false ? (trim((string)($row[$idx('yard')]    ?? '')) ?: null) : null]);
    $n++;
}
fclose($fh);
echo "{$n} vessels imported.\n";

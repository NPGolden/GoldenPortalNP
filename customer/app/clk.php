<?php
/** World clocks per user — UTC is always first; each user picks up to CLK_MAX other zones from the catalogue on My Account.   GAS-CP-R70-004 */
declare(strict_types=1);

const CLK_MAX = 7;   // the strip held UTC + 7 zones before this release; the width stays the same

/** code => [time zone, city, lat, lon] — the city gives the weather pop-over its place. */
function clk_catalogue(): array {
    return [
        'GR' => ['Europe/Athens',     'Athens',        37.98,  23.73],
        'AE' => ['Asia/Dubai',        'Dubai',         25.20,  55.27],
        'GB' => ['Europe/London',     'London',        51.51,  -0.13],
        'IT' => ['Europe/Rome',       'Rome',          41.90,  12.50],
        'NL' => ['Europe/Amsterdam',  'Amsterdam',     52.37,   4.90],
        'DE' => ['Europe/Berlin',     'Berlin',        52.52,  13.41],
        'ES' => ['Europe/Madrid',     'Barcelona',     41.39,   2.17],
        'FR' => ['Europe/Paris',      'Monaco',        43.74,   7.42],
        'HR' => ['Europe/Zagreb',     'Split',         43.51,  16.44],
        'ME' => ['Europe/Podgorica',  'Tivat',         42.43,  18.70],
        'TR' => ['Europe/Istanbul',   'Istanbul',      41.01,  28.98],
        'MT' => ['Europe/Malta',      'Valletta',      35.90,  14.51],
        'NO' => ['Europe/Oslo',       'Oslo',          59.91,  10.75],
        'FI' => ['Europe/Helsinki',   'Helsinki',      60.17,  24.94],
        'SA' => ['Asia/Riyadh',       'Jeddah',        21.49,  39.19],
        'QA' => ['Asia/Qatar',        'Doha',          25.29,  51.53],
        'IN' => ['Asia/Kolkata',      'Mumbai',        19.08,  72.88],
        'SG' => ['Asia/Singapore',    'Singapore',      1.29, 103.85],
        'HK' => ['Asia/Hong_Kong',    'Hong Kong',     22.32, 114.17],
        'CN' => ['Asia/Shanghai',     'Shanghai',      31.23, 121.47],
        'KR' => ['Asia/Seoul',        'Busan',         35.18, 129.08],
        'JP' => ['Asia/Tokyo',        'Tokyo',         35.68, 139.69],
        'AU' => ['Australia/Sydney',  'Sydney',       -33.87, 151.21],
        'NZ' => ['Pacific/Auckland',  'Auckland',     -36.85, 174.76],
        'ZA' => ['Africa/Johannesburg', 'Cape Town', -33.93,  18.42],
        'BR' => ['America/Sao_Paulo', 'Rio de Janeiro', -22.91, -43.17],
        'NY' => ['America/New_York',  'Fort Lauderdale', 26.12, -80.14],
        'US' => ['America/Chicago',   'Dallas',        32.78, -96.80],
        'LA' => ['America/Los_Angeles', 'Los Angeles', 34.05, -118.24],
        'MX' => ['America/Mexico_City', 'Mexico City', 19.43, -99.13],
        'CA' => ['America/Toronto',   'Toronto',       43.65, -79.38],
        'BM' => ['Atlantic/Bermuda',  'Hamilton',      32.29, -64.78],
        'KY' => ['America/Cayman',    'George Town',   19.29, -81.37],
        'VG' => ['America/Tortola',   'Tortola',       18.43, -64.62],
    ];
}
function clk_default(): array { return ['GR', 'AE', 'GB', 'IT', 'NL', 'DE', 'US']; }
/** Codes chosen by the signed-in user (default set when nothing saved or the column is missing). */
function clk_user_codes(): array {
    $u = function_exists('current_user') ? current_user() : null;
    $raw = $u && array_key_exists('clocks', $u) ? (string)($u['clocks'] ?? '') : '';
    if ($raw === '') return clk_default();
    $cat = clk_catalogue(); $out = [];
    foreach (explode(',', $raw) as $c) { $c = strtoupper(trim($c)); if (isset($cat[$c]) && !in_array($c, $out, true)) $out[] = $c; }
    return array_slice($out, 0, CLK_MAX);
}
/** Rows for the strip: [code, tz, city, lat, lon] — UTC first, then the user's zones. */
function clk_strip(): array {
    $cat = clk_catalogue(); $rows = [['UTC', 'UTC', 'UTC', null, null]];
    foreach (clk_user_codes() as $c) $rows[] = array_merge([$c], $cat[$c]);
    return $rows;
}
function clk_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM customer_users LIKE 'clocks'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** My Account › World clocks: POST clocks[] (codes, in the order ticked), empty = back to the default set. */
function clk_save_from_post(): void {
    $u = require_login(); if (!clk_has()) throw new RuntimeException(t('err.notfound'));
    $cat = clk_catalogue(); $out = [];
    foreach ((array)($_POST['clocks'] ?? []) as $c) { $c = strtoupper(trim((string)$c)); if (isset($cat[$c]) && !in_array($c, $out, true)) $out[] = $c; }
    if (count($out) > CLK_MAX) throw new RuntimeException(t('clk.too_many', ['n' => CLK_MAX]));
    q('UPDATE customer_users SET clocks = ? WHERE id = ?', [$out ? implode(',', $out) : null, (int)$u['id']]);
}

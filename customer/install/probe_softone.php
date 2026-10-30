<?php
/**
 * SoftOne service-name probe.                             GAS-ERP-CP-004
 *
 * Background: the Connect Line document is self-contradictory about the
 * projects service — §4.1.1's title and example say "getWorkingProjects",
 * its own field table says "getItems", and the examples mix key casings.
 * When SoftOne answers "Failed to process service!", the name (or casing)
 * we called is not what their side registered.
 *
 * This probe signs in once, then tries each candidate service name and
 * prints SoftOne's verdict for every attempt. When one succeeds, it tells
 * you the exact line to put in app/config.php.
 *
 * Run it:
 *   CLI        : php install/probe_softone.php
 *   over HTTPS : .../customer/install/probe_softone.php?key=<softone.sync_key>
 *
 * Delete it together with install/ once the integration is stable.
 */
declare(strict_types=1);

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/softone.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key      = (string)($_GET['key'] ?? '');
    $expected = (string) cfg('softone.sync_key', '');
    if ($expected === '' || $expected === 'CHANGE-THIS-LONG-RANDOM-STRING' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit("Forbidden. Call with ?key=<softone.sync_key from app/config.php>.\n");
    }
}

/* Candidate service names, most likely first. The configured value is
 * tried first so a value agreed with Connect Line always wins.          */
$candidates = array_values(array_unique(array_filter([
    (string) s1_cfg('projects_service', ''),
    'getWorkingProjects',
    'GetWorkingProjects',
    'getItems',
    'GetItems',
])));

echo "Signing in (login + authenticate)…\n";
try {
    $clientId = s1_client_id(true);          // force a fresh token
    echo "OK — authenticated clientID ", substr($clientId, 0, 8), "…\n\n";
} catch (Throwable $e) {
    exit('STOP — sign-in itself failed: ' . $e->getMessage() . "\n");
}

$api    = (string) s1_cfg('api_endpoint');
$winner = null;

foreach ($candidates as $svc) {
    echo str_pad($svc, 24), ' … ';
    $payload = [
        'service'  => $svc,
        'clientid' => $clientId,
        'clientID' => $clientId,
        'CLIENTID' => $clientId,
        'appId'    => (string) s1_cfg('appid'),
        'appid'    => (string) s1_cfg('appid'),
    ];
    try {
        [$http, $ctype, $body] = s1_post_raw($api, $payload);
        $j = s1_decode($svc, $http, $ctype, $body);
        if (!empty($j['success'])) {
            $n = $j['totalcount'] ?? (is_array($j['data'] ?? null) ? count($j['data']) : '?');
            echo "SUCCESS — totalcount {$n}\n";
            $winner = $svc;
            if (!empty($j['data'][0]) && is_array($j['data'][0])) {
                echo "  fields: ", implode(', ', array_keys($j['data'][0])), "\n";
            }
            break;
        }
        echo 'refused — ', ($j['error'] ?? 'no error text'),
             isset($j['errorcode']) ? " (errorcode {$j['errorcode']})" : '', "\n";
    } catch (Throwable $e) {
        echo 'FAILED — ', $e->getMessage(), "\n";
    }
}

echo "\n", str_repeat('=', 68), "\n";
if ($winner !== null) {
    echo "Working service name: {$winner}\n\n";
    echo "Set it permanently in customer/app/config.php:\n";
    echo "    'projects_service' => '{$winner}',\n\n";
    echo "…then run the sync again:\n";
    echo "    install/sync_softone.php?key=…\n";
} else {
    echo "No candidate was accepted. That means the projects service is not\n";
    echo "registered under any documented name on the Connect Line side, or it\n";
    echo "errors internally when it runs. Send Connect Line this exact evidence:\n\n";
    echo "  - Endpoint called : {$api}\n";
    echo "  - Names tried     : ", implode(', ', $candidates), "\n";
    echo "  - Their response  : \"Failed to process service!\" (login and\n";
    echo "    authenticate both succeed, so credentials and appId are fine)\n";
    echo "  - Ask them        : the exact registered service name for\n";
    echo "    \"Άντληση Στοιχείων Έργων\" (§4.1.1), and whether it is enabled\n";
    echo "    for appId ", (string) s1_cfg('appid'),
         " (their own example shows appId 1000, the parameters table 1001).\n";
}

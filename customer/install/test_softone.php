<?php
/**
 * SoftOne connection tester.                              GAS-ERP-CP-003
 *
 * Runs the three stages one by one — login, authenticate, getWorkingProjects —
 * and shows for each: HTTP status, Content-Type, the first bytes of the RAW
 * body, and whether it parsed. Use it when the sync reports an unparseable
 * or failing response, to see exactly which stage misbehaves and what the
 * server really sent.
 *
 * Run it:
 *   CLI        : php install/test_softone.php
 *   over HTTPS : .../customer/install/test_softone.php?key=<softone.sync_key>
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

function peek(string $body, int $n = 300): string {
    $hasBom = str_starts_with($body, "\xEF\xBB\xBF") ? ' [UTF-8 BOM PRESENT]' : '';
    $p = preg_replace('/\s+/', ' ', trim(mb_substr($body, 0, $n)));
    return $p . $hasBom;
}

function stage(string $name, string $url, array $payload): array {
    echo str_repeat('=', 68), "\nSTAGE: {$name}\nURL:   {$url}\n";
    [$http, $ctype, $body] = s1_post_raw($url, $payload);
    echo "HTTP:  {$http}\nCtype: ", ($ctype ?: '(none)'), "\nBytes: ", strlen($body), "\n";
    echo "Body:  ", peek($body), "\n";
    try {
        $j = s1_decode($name, $http, $ctype, $body);
        echo "Parse: OK — success=", var_export($j['success'] ?? null, true);
        if (!empty($j['error'])) echo "  error=", $j['error'];
        echo "\n";
        return $j;
    } catch (Throwable $e) {
        echo "Parse: FAILED — ", $e->getMessage(), "\n";
        exit(1);
    }
}

$endpoint = (string) s1_cfg('endpoint');
$api      = (string) s1_cfg('api_endpoint');

echo "Config check\n";
echo "  endpoint     : {$endpoint}\n";
echo "  api_endpoint : {$api}\n";
echo "  username     : ", (string) s1_cfg('username'), "\n";
echo "  appid        : ", (string) s1_cfg('appid'), "\n\n";

/* -------- 1. login ------------------------------------------------ */
$login = stage('login', $endpoint, [
    'service'  => 'login',
    'username' => (string) s1_cfg('username'),
    'password' => (string) s1_cfg('password'),
    'appId'    => (string) s1_cfg('appid'),
]);
if (empty($login['success'])) { echo "\nSTOP: login refused — check username/password/appid.\n"; exit(1); }
$inst = $login['objs'][0] ?? [];
echo "  clientID: ", substr((string)$login['clientID'], 0, 8), "…\n";
echo "  COMPANY={$inst['COMPANY']} BRANCH={$inst['BRANCH']} MODULE={$inst['MODULE']} REFID={$inst['REFID']}\n\n";

/* -------- 2. authenticate ----------------------------------------- */
$auth = stage('authenticate', $endpoint, [
    'service'  => 'authenticate',
    'clientID' => (string) $login['clientID'],
    'COMPANY'  => (string) (s1_cfg('company') ?: ($inst['COMPANY'] ?? '')),
    'BRANCH'   => (string) (s1_cfg('branch')  ?: ($inst['BRANCH']  ?? '')),
    'MODULE'   => (string) (s1_cfg('module')  ?: ($inst['MODULE']  ?? '')),
    'REFID'    => (string) (s1_cfg('refid')   ?: ($inst['REFID']   ?? '')),
]);
if (empty($auth['success'])) { echo "\nSTOP: authenticate refused.\n"; exit(1); }
echo "\n";

/* -------- 3. getWorkingProjects ----------------------------------- */
$svc = (string) s1_cfg('projects_service', 'getWorkingProjects');
$data = stage($svc, $api, [
    'service'  => $svc,
    'clientid' => (string) $auth['clientID'],
    'appId'    => (string) s1_cfg('appid'),
]);
$rows = is_array($data['data'] ?? null) ? $data['data'] : [];
echo "  totalcount: ", $data['totalcount'] ?? count($rows), "\n";
if ($rows) {
    echo "  first row fields: ", implode(', ', array_keys($rows[0])), "\n";
    echo "  first row CODE=", ($rows[0]['CODE'] ?? '?'),
         "  CUSTOMER_CODE=", ($rows[0]['CUSTOMER_CODE'] ?? '?'), "\n";
}

echo "\nALL THREE STAGES OK — the sync should now run. If it still fails,\n";
echo "the sync's own error message now includes the offending body snippet.\n";

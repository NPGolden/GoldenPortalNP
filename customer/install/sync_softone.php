<?php
/**
 * SoftOne -> Customer Portal sync.                       GAS-ERP-CP-002
 *
 * Pulls every active project from the ERP (getWorkingProjects) and
 * upserts vessels + projects into MySQL, scoped by customer.
 *
 * Matching rule: SoftOne CUSTOMER_CODE  ==  customers.erp_code.
 * Projects whose customer code has no portal account are counted as
 * "skipped" — nothing is ever shown to the wrong customer.
 *
 * Financial fields from the ERP (LCashOut / LCashIn) are deliberately
 * NOT stored: customers must not see payment figures in the portal.
 *
 * Run it:
 *   CLI / cron :  php install/sync_softone.php
 *   over HTTPS :  https://goldensas.com/customer/install/sync_softone.php?key=<softone.sync_key>
 *
 * Keep this file when you delete the rest of install/ — or move it one
 * level up; it only needs ../app/*.
 */
declare(strict_types=1);

require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/softone.php';

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $key = (string)($_GET['key'] ?? '');
    $expected = (string) cfg('softone.sync_key', '');
    if ($expected === '' || $expected === 'CHANGE-THIS-LONG-RANDOM-STRING' || !hash_equals($expected, $key)) {
        http_response_code(403);
        exit("Forbidden. Set softone.sync_key in app/config.php and call ?key=<that value>.\n");
    }
}

$out = function (string $line) { echo $line, "\n"; };

$fetched = $matched = $skipped = 0;
$unknownCustomers = [];

try {
    $rows = s1_get_working_projects();
    $fetched = count($rows);
    $out("Fetched {$fetched} projects from SoftOne.");

    // Portal customers keyed by their ERP code.
    $customers = [];
    foreach (all('SELECT id, erp_code FROM customers WHERE erp_code IS NOT NULL') as $c) {
        $customers[trim((string)$c['erp_code'])] = (int)$c['id'];
    }
    if (!$customers) $out('WARNING: no customer has an erp_code yet — everything will be skipped.');

    db()->beginTransaction();

    foreach ($rows as $r) {
        $code     = trim((string)($r['CODE'] ?? ''));
        $custCode = trim((string)($r['CUSTOMER_CODE'] ?? ''));
        if ($code === '') continue;

        if ($custCode === '' || !isset($customers[$custCode])) {
            $skipped++;
            if ($custCode !== '') $unknownCustomers[$custCode] = ($r['CUSTOMER_NAME'] ?? '');
            continue;                       // internal project or unmapped customer
        }
        $cid = $customers[$custCode];

        // ---- vessel (SHIPNAME), per customer -------------------------
        $vesselId = null;
        $ship = trim((string)($r['SHIPNAME'] ?? ''));
        if ($ship !== '') {
            $vesselId = scalar('SELECT id FROM vessels WHERE customer_id = ? AND name = ?', [$cid, $ship]);
            if (!$vesselId) {
                q('INSERT INTO vessels (customer_id, name) VALUES (?, ?)', [$cid, $ship]);
                $vesselId = (int) db()->lastInsertId();
            }
        }

        // ---- project, keyed by ERP CODE ------------------------------
        $state  = strtolower(trim((string)($r['STATE'] ?? 'Open')));
        $status = $state === 'open' ? 'active' : 'completed';
        $name   = trim((string)($r['DESCRIPTION'] ?? '')) ?: $code;
        $cat    = trim((string)($r['CATEGORY'] ?? '')) ?: null;
        $pm     = trim((string)($r['PROJECT_MANAGER'] ?? '')) ?: null;

        $existing = one('SELECT id FROM projects WHERE erp_code = ?', [$code]);
        if ($existing) {
            q('UPDATE projects SET customer_id=?, vessel_id=?, name=?, status=?, category=?,
               project_manager=?, source="erp" WHERE id=?',
              [$cid, $vesselId, mb_substr($name,0,160), $status, $cat, $pm, $existing['id']]);
            $pid = (int)$existing['id'];
        } else {
            q('INSERT INTO projects (customer_id, vessel_id, name, erp_code, source, status, category, project_manager)
               VALUES (?,?,?,?,"erp",?,?,?)',
              [$cid, $vesselId, mb_substr($name,0,160), $code, $status, $cat, $pm]);
            $pid = (int) db()->lastInsertId();
            // A fresh ERP project gets one default sub-project so it appears
            // on the lifecycle board; stage percentages are maintained by
            // the PM (the ERP does not carry Engineering/Production/FAT/SAT).
            q('INSERT INTO subprojects (project_id, name) VALUES (?, "General")', [$pid]);
        }
        $matched++;
    }

    db()->commit();

    $msg = "OK — matched {$matched}, skipped {$skipped}.";
    q('INSERT INTO erp_sync_log (ok, fetched, matched, skipped, message) VALUES (1,?,?,?,?)',
      [$fetched, $matched, $skipped, mb_substr($msg, 0, 500)]);

    $out($msg);
    if ($unknownCustomers) {
        $out('Customer codes seen in the ERP but not mapped in the portal (set customers.erp_code to include them):');
        foreach (array_slice($unknownCustomers, 0, 25, true) as $c => $n) $out("  {$c}  {$n}");
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    try { q('INSERT INTO erp_sync_log (ok, fetched, matched, skipped, message) VALUES (0,?,?,?,?)',
            [$fetched, $matched, $skipped, mb_substr($e->getMessage(), 0, 500)]); } catch (Throwable $e2) {}
    http_response_code(500);
    $out('FAILED: ' . $e->getMessage());
    exit(1);
}

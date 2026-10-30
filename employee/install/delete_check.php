<?php
/**
 * Order-deletion diagnostic.                              GAS-DOC-DELETE-002
 *
 * Upload to  public_html/employee/install/  and open (as admin, signed in):
 *   https://goldensas.com/employee/install/delete_check.php
 *
 * Answers two questions about the LIVE database:
 *   1. Are the orders you tried to delete actually still there?
 *   2. Does every child table cascade on delete — or would MySQL refuse?
 * Read-only. It changes nothing. Delete this file when done.
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

require __DIR__ . '/../app/db.php';
$dbName = (string) cfg('db.name');

echo "GAS order-deletion diagnostic\n";
echo "database: {$dbName}   generated: ", date('c'), "\n";
echo str_repeat('=', 64), "\n\n";

// ---- 1. Which orders exist right now (top 20 by ref) -----------------
echo "Orders currently in the database (newest 20):\n", str_repeat('-', 64), "\n";
foreach (all("SELECT id, order_ref, status FROM orders ORDER BY id DESC LIMIT 20") as $o) {
    printf("  #%-4d %-22s %s\n", $o['id'], $o['order_ref'], $o['status']);
}
if (!scalar("SELECT COUNT(*) FROM orders")) echo "  (none)\n";

// ---- 2. Foreign keys that point at orders — do they cascade? ---------
echo "\nChild tables referencing orders(id) and their ON DELETE rule:\n", str_repeat('-', 64), "\n";
$fks = all("SELECT rc.TABLE_NAME, rc.CONSTRAINT_NAME, rc.DELETE_RULE
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            WHERE rc.CONSTRAINT_SCHEMA = ? AND rc.REFERENCED_TABLE_NAME = 'orders'", [$dbName]);
$blocking = [];
foreach ($fks as $f) {
    $ok = strtoupper($f['DELETE_RULE']) === 'CASCADE';
    printf("  [%s] %-18s %-22s ON DELETE %s\n", $ok ? ' OK ' : 'BLOCK', $f['TABLE_NAME'], $f['CONSTRAINT_NAME'], $f['DELETE_RULE']);
    if (!$ok) $blocking[] = $f;
}
if (!$fks) echo "  (no foreign keys found — deletion cannot be blocked by FKs)\n";

// ---- 3. Verdict ------------------------------------------------------
echo "\n", str_repeat('=', 64), "\n";
if ($blocking) {
    echo "VERDICT: deletion is being BLOCKED by ", count($blocking), " child table(s) above.\n";
    echo "MySQL refuses to delete an order while rows in those tables point at it,\n";
    echo "and the portal rolls the delete back — so the orders stay exactly as they\n";
    echo "were. Fix: run migrate_r7_cascade_fix.sql (phpMyAdmin -> Import), then\n";
    echo "delete again. It only changes the ON DELETE rule; no data is touched.\n";
} else {
    echo "VERDICT: every child table cascades. A delete that ran would have removed\n";
    echo "the order. If the orders above are still listed, the delete never executed:\n";
    echo "  - check the red banner after pressing Delete (a mismatched reference\n";
    echo "    or a session bounce shows a message and deletes nothing), or\n";
    echo "  - the dashboard you looked at was a cached copy: press Ctrl+F5 there.\n";
}
echo "\nDelete this file when done.\n";

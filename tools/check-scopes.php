<?php
/** Scoped roles (HR, Employee, Assistant): every button on a page the role may open must be in the role's action list, or the
 *  person gets "You do not have access to this page" on pressing it (GAS-CP-R97-205).   php tools/check-scopes.php
 *  Lists the form actions found in the views of each role's pages that are missing from its list. $skip = left out on purpose. */
declare(strict_types=1);
$root = dirname(__DIR__) . '/customer'; $s = file_get_contents($root . '/app/auth.php'); $bad = 0;
$skip = ['login', 'twofa',                                                              // before sign-in
    'kb_delete_q', 'kb_delete_a', 'cr_delete', 'pl_car_save', 'pl_car_delete',          // administrators only (buttons not shown to the role)
    'support_send', 'support_status', 'pl_delete_work'];                                // customers only / refused to the HR role by design
$only = ['PA' => true];                                                                 // the Assistant list is curated by hand: reported, never a failure
foreach (['HR', 'EMP', 'PA'] as $R) {
    if (!preg_match("/const {$R}_ACTIONS = \[(.*?)\];/s", $s, $m) || !preg_match("/const {$R}_PAGES = \[(.*?)\];/s", $s, $pm)) continue;
    preg_match_all("/'([a-z0-9_]+)'/", $m[1], $a); preg_match_all("/'([a-z0-9_]+)'/", $pm[1], $p); $miss = [];
    foreach ($p[1] as $pg) { $f = "$root/views/$pg.php"; if (!is_file($f)) continue; $src = file_get_contents($f);
        preg_match_all('/_[a-z0-9_]+\.php/', $src, $inc); foreach (array_unique($inc[0]) as $i) if (is_file("$root/views/$i")) $src .= file_get_contents("$root/views/$i");
        preg_match_all('/name="action" value="([a-z0-9_]+)"/', $src, $x);
        foreach (array_unique($x[1]) as $ac) if (!in_array($ac, $a[1], true) && !in_array($ac, $skip, true)) $miss[$ac][] = $pg; }
    echo "$R: " . ($miss ? count($miss) . ' action(s) not in the list' . (isset($only[$R]) ? ' (report only)' : '') : 'ok') . "\n"; foreach ($miss as $k => $v) echo "  $k  <- " . implode(', ', $v) . "\n";
    if ($miss && !isset($only[$R])) $bad++;
}
exit($bad ? 1 : 0);

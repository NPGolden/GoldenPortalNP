<?php
/**
 * Weekly report — project level.                            GAS-CP-R3-001 → R15
 * Since R15 the project's weekly report and the weekly vessel report are the
 * same document: this entry point maps ?project=ID[&week=YYYY-Www] onto the
 * weekly vessel report scoped to that project, so both have one structure.
 */
$wkIso = (string)($_GET['week'] ?? '');
if (preg_match('/^(\d{4})-W(\d{2})$/', $wkIso, $m)) { $dt = new DateTime(); $dt->setISODate((int)$m[1], (int)$m[2]); $_GET['week'] = $dt->format('Y-m-d'); }
$_GET['vessel'] = (string)(int)($_GET['vessel'] ?? 0);
require __DIR__ . '/weekly_vessel.php';

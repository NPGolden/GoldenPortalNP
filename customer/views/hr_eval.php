<?php
/** Annual evaluation of one of my people — for the manager (HR uses the card tab).   GAS-CP-R62-001   ?p=hr_eval&pid=[&year=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $pid = (int)($_GET['pid'] ?? 0); $p = $pid ? hr_person($pid) : null;
$isMgr = $p && (int)(hr_manager_of($p) ?? 0) === (int)$u['id'];
if (!function_exists('hr_tr_has') || !hr_tr_has() || !$p || !($isMgr || hr_can())) { http_response_code(403); layout_head(t('hr.evaluations'), 'hr_requests'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
layout_head(t('hr.evaluations') . ' · ' . $p['name_en'], 'hr_requests');
?>
<p class="eyebrow"><a href="<?= url('hr_requests') ?>">‹ <?= tt('hr.requests') ?></a></p>
<div class="page-head"><div><h1>📈 <?= e($p['name_en']) ?></h1><p class="lede"><?= tt('hr.ev_mgr_lede') ?></p></div></div>
<?php $evMgr = true; $evUrl = fn(int $y) => url('hr_eval', ['pid' => $pid, 'year' => $y]); require __DIR__ . '/_hr_eval.php'; ?>
<?php layout_foot(); ?>

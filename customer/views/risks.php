<?php
/** Project-level risk log (GAS-CP-R39-001). ?p=risks&project=ID */
require __DIR__ . '/_layout.php';
$u = require_staff();
$pid = (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", $args);
if (!$p || !function_exists('rk_has') || !rk_has()) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$backTo = url('risks', ['project' => $pid]);
layout_head(t('rk.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('rk.title') ?></p>
<div class="page-head"><div><h1>⚠ <?= tt('rk.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) ?> · <?= e($p['vessel'] ?: '') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('kpis', ['project' => $pid]) ?>">🎯 <?= tt('kpi.title') ?></a></div></div>
<?php $sid = null; require __DIR__ . '/_risks.php'; ?>
<?php layout_foot(); ?>

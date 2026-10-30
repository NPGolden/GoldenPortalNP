<?php
/** Field service reports of one project (staff and customer).   GAS-CP-R7-001 */
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", $args);
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$rows = fsr_list($pid);
layout_head(t('fsr.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?></p>
<div class="page-head">
  <div><h1><?= tt('fsr.plural') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($p['name']) ?> · <?= e($p['vessel'] ?: '') ?></p></div>
  <?php if (is_staff()): ?><div class="btn-row"><a class="btn btn--gold" href="<?= url('fsr_edit', ['project' => $pid]) ?>">+ <?= tt('fsr.new') ?></a><?php if (function_exists('fsr_ext_has') && fsr_ext_has()): ?><a class="btn btn--ghost" href="<?= url('fsr_ext', ['project' => $pid]) ?>">📎 <?= tt('fsr.ext_new') ?></a><?php endif; ?></div><?php endif; ?>
</div>
<div class="card"><?php fsr_table($rows, false, 'fsr_list', ['project' => $pid]); ?></div>
<?php layout_foot(); ?>

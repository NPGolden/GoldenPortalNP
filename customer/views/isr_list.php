<?php
/** Inspection reports of one project (staff and customer).   GAS-CP-R23-003 */
require __DIR__ . '/_layout.php';
require __DIR__ . '/_isr_list.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
if (!$pid) redirect('reports');                                     // all reports live on the Reports page
[$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", $args);
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$rows = isr_list($pid);
layout_head(t('isr.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?></p>
<div class="page-head">
  <div><h1><?= tt('isr.plural') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($p['name']) ?> · <?= e($p['vessel'] ?: '') ?></p></div>
  <?php if (is_staff()): ?><a class="btn btn--gold" href="<?= url('isr_edit', ['project' => $pid]) ?>">+ <?= tt('isr.new') ?></a><?php endif; ?>
</div>
<div class="card"><?php isr_table($rows, false, 'isr_list', ['project' => $pid], 'page'); ?></div>
<?php layout_foot(); ?>

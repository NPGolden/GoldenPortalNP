<?php
/** HR › Assessments — campaigns imported from the Excel template; results over the years.   GAS-CP-R97-063   ?p=hr_tests[&template=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hrs_can_view') || !hrs_can_view()) { http_response_code(403); layout_head(t('hrs.title'), 'hr_tests'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$ro = !hrs_can();   // R97-254: read only for the General Manager
if (isset($_GET['template']) && !$ro) {
    $f = hrs_template_path(); if (!is_file($f)) { http_response_code(404); exit; }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="' . basename($f) . '"'); header('Content-Length: ' . filesize($f)); header('X-Content-Type-Options: nosniff'); readfile($f); exit;
}
$list = hrs_campaigns(); $imp = $_SESSION['hrs_import'] ?? null; unset($_SESSION['hrs_import']);
$fams = []; foreach ($list as $c) if ($c['status'] === 'closed') $fams[$c['family_code']] ??= (int) $c['id'];
layout_head(t('hrs.title'), 'hr_tests');
?>
<p class="eyebrow"><?php if (!$ro): ?><a href="<?= url('hr_dashboard') ?>"><?= tt('hr.dashboard') ?></a> › <?php endif; ?><?= tt('hrs.title') ?></p>
<div class="page-head"><div><h1>🧭 <?= tt('hrs.title') ?></h1><p class="lede"><?= tt('hrs.lede') ?></p></div></div>

<?php if ($imp): ?><div class="card" style="border-left:4px solid #b3261e;">
  <h2>⚠ <?= e(t('hrs.imp_failed', ['n' => count($imp['errors'])])) ?></h2><p class="hint"><?= e($imp['file']) ?> — <?= tt('hrs.imp_fix') ?></p>
  <ul style="margin:0;padding-left:18px;"><?php foreach ($imp['errors'] as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul>
</div><?php endif; ?>

<div class="cols" style="grid-template-columns: 2fr 1fr;">
<div>
<div class="card">
  <h2><?= tt('hrs.campaigns') ?></h2>
  <?php if (!$list): ?><div class="empty"><?= tt('hrs.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hrs.campaign') ?></th><th><?= tt('hrs.family') ?></th><th><?= tt('hrs.period') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('hrs.answered') ?></th></tr></thead>
    <tbody><?php foreach ($list as $c): ?><tr>
      <td><a href="<?= url('hr_test', ['id' => (int) $c['id']]) ?>"><b><?= e($c['title']) ?></b></a><br><span class="hint"><?= e((string) $c['created_name']) ?> · <?= e(fmt_date($c['created_at'])) ?></span></td>
      <td><?= e($c['family_code']) ?> <span class="hint">v<?= (int) $c['family_version'] ?></span></td>
      <td><?= $c['starts_on'] ? e(fmt_date($c['starts_on'])) . ' – ' . e(fmt_date($c['ends_on'])) : '<span class="hint">—</span>' ?></td>
      <td><?= hrs_status_pill($c['status']) ?></td>
      <td class="num"><?= (int) $c['n_done'] ?> / <?= (int) $c['n'] ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php foreach ($fams as $fam => $lastId): $lc = hrs_campaign($lastId); $F = $lc['F']; $min = (int) ($F['set']['min_group'] ?? 5); $yrs = hrs_family_years($fam, $min); ?>
<div class="card">
  <h2>📈 <?= e(t('hrs.years_title', ['f' => $fam])) ?></h2>
  <?php if (!$yrs): ?><p class="hint"><?= e(t('hrs.years_none', ['n' => $min])) ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hrs.dimension') ?></th><?php foreach ($yrs as $y => $_): ?><th class="num"><?= (int) $y ?></th><?php endforeach; ?></tr></thead>
    <tbody><?php foreach ($F['dims'] as $d => $dm): ?><tr><td><?= e(hrs_tx($dm['name'])) ?></td><?php foreach ($yrs as $y => $row): $x = $row[$d] ?? null; ?><td class="num"><?= $x ? '<b>' . (int) $x['avg'] . '</b> <span class="hint">(' . (int) $x['n'] . ')</span>' : '<span class="hint">—</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= e(t('hrs.years_hint', ['n' => $min])) ?></p>
  <?php endif; ?>
</div>
<?php endforeach; ?>
</div>

<div>
<?php if ($ro): ?><div class="flash flash--info">👁 <?= tt('hrs.ro_note') ?></div><?php endif; ?>
<div class="card"<?= $ro ? ' hidden' : '' ?>><?php if (!$ro): ?>
  <h2>⬆ <?= tt('hrs.imp_title') ?></h2>
  <p class="hint"><?= tt('hrs.imp_lede') ?></p>
  <form method="post" action="<?= url('hr_tests') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hrs_import">
    <div class="field"><label><?= tt('hrs.imp_file') ?></label><input type="file" name="file" accept=".xlsx" required></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('hrs.imp_btn') ?></button><a class="btn btn--ghost" href="<?= url('hr_tests', ['template' => 1]) ?>">⬇ <?= tt('hrs.imp_template') ?></a></div>
  </form>
<?php endif; ?></div>
<div class="card">
  <h2>ℹ <?= tt('hrs.how_title') ?></h2>
  <ol class="hint" style="padding-left:18px;margin:0;"><li><?= tt('hrs.how_1') ?></li><li><?= tt('hrs.how_2') ?></li><li><?= tt('hrs.how_3') ?></li><li><?= tt('hrs.how_4') ?></li><li><?= tt('hrs.how_5') ?></li></ol>
  <p class="hint" style="margin-top:8px;">🔒 <?= tt('hrs.privacy') ?></p>
</div>
</div>
</div>
<?php layout_foot(); ?>

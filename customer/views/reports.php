<?php
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
require __DIR__ . '/_isr_list.php';
require __DIR__ . '/_fc_list.php';   // R77-001
$u = require_login(); $cid = (int)$u['customer_id'];
$fsrs = fsr_list(null, null, is_staff() ? null : $cid);
$pending = array_values(array_filter($fsrs, fn($r) => $r['status'] === 'submitted'));
// R97-074: filter by vessel and order of the lists (kept through the pages of each table)
$isrs = isr_has() ? isr_list() : []; $fcs = function_exists('fc_has') && fc_has() ? fc_list(null, null, null) : [];
$vNames = []; foreach ([$fsrs, $isrs, $fcs] as $L) foreach ($L as $r) if ((string)($r['vessel'] ?? '') !== '') $vNames[(string)$r['vessel']] = 1; ksort($vNames, SORT_NATURAL | SORT_FLAG_CASE); $vNames = array_keys($vNames);
$fv = (string)($_GET['fv'] ?? ''); if (!in_array($fv, $vNames, true)) $fv = ''; $fo = in_array($_GET['fo'] ?? '', ['old', 'vaz', 'vza'], true) ? (string)$_GET['fo'] : '';
if ($fv !== '') { $byV = fn($r) => (string)($r['vessel'] ?? '') === $fv; $fsrs = array_values(array_filter($fsrs, $byV)); $isrs = array_values(array_filter($isrs, $byV)); $fcs = array_values(array_filter($fcs, $byV)); }
$keepF = array_filter(['fv' => $fv, 'fo' => $fo]);
$docs = all("SELECT d.*, v.name AS vessel FROM documents d LEFT JOIN vessels v ON v.id=d.vessel_id
             WHERE d.customer_id=? AND d.category IN ('fsr','workorder')
             ORDER BY d.updated_at DESC LIMIT 200", [$cid]);
layout_head(t('r.title'), 'reports');
?>
<div class="page-head"><h1><?= tt('r.title') ?></h1><?= vg_toggle('reports') ?></div>
<p class="lede"><?= tt('r.lede') ?></p>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr auto;"><input type="hidden" name="p" value="reports">
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="fv"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vNames as $vn): ?><option value="<?= e($vn) ?>"<?= $vn === $fv ? ' selected' : '' ?>><?= e($vn) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('r.order') ?></label><select name="fo" data-nosearch><?php foreach (['' => 'r.o_new', 'old' => 'r.o_old', 'vaz' => 'r.o_vaz', 'vza' => 'r.o_vza'] as $ok => $ol): ?><option value="<?= $ok ?>"<?= $ok === $fo ? ' selected' : '' ?>><?= tt($ol) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('reports') ?>"><?= tt('btn.clear') ?></a></div>
</form>
<?php if ($pending && !is_staff()): ?><div class="flash flash--warn"><?= e(t('fsr.pending_n', ['n' => count($pending)])) ?></div><?php endif; ?>
<div class="card">
  <h2><?= tt('fsr.plural') ?></h2>
  <?php fsr_table($fsrs, true, 'reports', $keepF); ?>
</div>
<?php if (isr_has()): ?>
<div class="card">
  <h2><?= tt('isr.plural') ?></h2>
  <?php isr_table($isrs, true, 'reports', $keepF); ?>
</div>
<?php endif; ?>
<?php if (function_exists('fc_has') && fc_has()): ?>
<div class="card">
  <div class="card-head"><h2>🧪 <?= tt('fc.plural') ?></h2><?php if (is_staff()): ?><div class="btn-row"><?php foreach (fc_forms() as $fk => $ft): ?><a class="btn btn--gold btn--sm" href="<?= url('fc_edit', ['form' => $fk]) ?>">+ <?= e($ft['short']) ?></a><?php endforeach; ?></div><?php endif; ?></div>
  <p class="hint"><?= tt('fc.lede') ?></p>
  <?php fc_table($fcs, true, 'reports', $keepF); ?>
</div>
<?php endif; ?>
<?php if ($docs): ?>
<div class="card">
  <h2><?= tt('r.legacy') ?></h2>
<?php foreach ($docs as $d): ?>
  <div class="rowitem">
    <span class="ico">F</span>
    <span class="tx"><b><?= e($d['title']) ?></b><span><?= e($d['vessel'] ?: '—') ?> · <span class="date"><?= e(fmt_date($d['updated_at'])) ?></span></span></span>
    <?= doc_status_pill($d['status']) ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

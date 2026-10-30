<?php
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
require __DIR__ . '/_isr_list.php';
$u = require_login(); $cid = (int)$u['customer_id'];
$fsrs = fsr_list(null, null, is_staff() ? null : $cid);
$pending = array_values(array_filter($fsrs, fn($r) => $r['status'] === 'submitted'));
$docs = all("SELECT d.*, v.name AS vessel FROM documents d LEFT JOIN vessels v ON v.id=d.vessel_id
             WHERE d.customer_id=? AND d.category IN ('fsr','workorder')
             ORDER BY d.updated_at DESC LIMIT 200", [$cid]);
layout_head(t('r.title'), 'reports');
?>
<h1><?= tt('r.title') ?></h1>
<p class="lede"><?= tt('r.lede') ?></p>
<?php if ($pending && !is_staff()): ?><div class="flash flash--warn"><?= e(t('fsr.pending_n', ['n' => count($pending)])) ?></div><?php endif; ?>
<div class="card">
  <h2><?= tt('fsr.plural') ?></h2>
  <?php fsr_table($fsrs, true); ?>
</div>
<?php if (isr_has()): ?>
<div class="card">
  <h2><?= tt('isr.plural') ?></h2>
  <?php isr_table(isr_list(), true); ?>
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

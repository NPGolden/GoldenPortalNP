<?php
/** Contacts › Organisations — one register: each customer linked to its company of the Contacts module; duplicates matched by VAT, ERP code or name (GAS-CP-R85-001).  ?p=organisations */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('org_can') || !org_can()) { http_response_code(403); layout_head(t('org.title'), 'contacts'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$M = org_matches(); $C = org_counts(); $dupes = org_dupes(); $self = url('organisations'); $kinds = co_kinds();
layout_head(t('org.title'), 'contacts');
?>
<p class="eyebrow"><a href="<?= url('contacts') ?>"><?= tt('ct.title') ?></a></p>
<div class="page-head"><div><h1>🏛 <?= tt('org.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('org.lede') ?></p></div>
  <div class="actions"><a class="btn btn--ghost btn--sm" href="<?= url('companies') ?>">🏢 <?= tt('co.title') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('suppliers') ?>">🏭 <?= tt('sup.title') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('admin_customers') ?>">★ <?= tt('nav.customers') ?></a></div></div>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat ok"><b><?= $C['linked'] ?></b><?= tt('org.k_linked') ?></div>
  <div class="stat<?= $C['match'] ? ' gold' : '' ?>"><b><?= $C['match'] ?></b><?= tt('org.k_match') ?></div>
  <div class="stat<?= $C['none'] ? ' red' : '' ?>"><b><?= $C['none'] ?></b><?= tt('org.k_none') ?></div>
  <div class="stat<?= $C['people_fix'] ? ' gold' : '' ?>"><b><?= $C['people_fix'] ?></b><?= tt('org.k_people') ?></div>
</div>
<div class="btn-row" style="margin-bottom:10px;">
  <?php if ($C['match']): ?><form method="post" action="<?= $self ?>" data-lock data-confirm="<?= e(t('org.link_all_q', ['n' => $C['match']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="org_link_all"><button class="btn btn--gold btn--sm" type="submit">🔗 <?= e(t('org.link_all', ['n' => $C['match']])) ?></button></form><?php endif; ?>
  <?php if ($C['people_fix']): ?><form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="org_derive_people"><button class="btn btn--sm" type="submit">👤 <?= e(t('org.derive', ['n' => $C['people_fix']])) ?></button></form><?php endif; ?>
</div>
<div class="card">
  <div class="card-head"><h2><?= tt('org.s_dupes') ?> <span class="count"><?= count($dupes) ?></span></h2><span class="hint"><?= tt('org.h_dupes') ?></span></div>
  <?php if (!$dupes): ?><p class="hint"><?= tt('org.empty') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('org.keep') ?></th><th><?= tt('org.drop') ?></th><th><?= tt('org.how') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($dupes as $d): $K = $d['keep']; $D = $d['drop']; $lab = fn($c) => '<a href="' . url('companies', ['edit' => (int)$c['id']]) . '"><b>' . e($c['name']) . '</b></a> ' . co_kind_pill($c['kind']) . (!empty($c['is_supplier']) && $c['kind'] !== 'supplier' ? ' ' . pill('gold', t('co.kind_supplier')) : '') . ($c['customer_id'] ? ' ' . pill('navy', '★ ' . t('rp.customer')) : '') . '<br><span class="hint">' . e((string)($c['vat_no'] ?? '')) . ($c['erp_code'] ? ' · ' . e($c['erp_code']) : '') . ' · ' . (int)$c['n_people'] . ' ' . t('ct.title') . '</span>'; ?>
    <tr><td><?= $lab($K) ?></td><td><?= $lab($D) ?></td><td><?= pill($d['how'] === 'name' ? 'mute' : 'gold', t('org.how_' . $d['how'])) ?></td>
      <td class="num"><form method="post" action="<?= $self ?>" data-lock data-confirm="<?= e(t('org.merge_q', ['a' => $D['name'], 'b' => $K['name']])) ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="org_merge"><input type="hidden" name="keep" value="<?= (int)$K['id'] ?>"><input type="hidden" name="drop" value="<?= (int)$D['id'] ?>"><button class="btn btn--sm" type="submit">⤵ <?= tt('org.merge') ?></button></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php foreach (['match' => 'gold', 'none' => 'red', 'linked' => 'ok'] as $sec => $tone): $rows = $M[$sec]; ?>
<div class="card">
  <div class="card-head"><h2><?= tt('org.s_' . $sec) ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= tt('org.h_' . $sec) ?></span></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('org.empty') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('rp.customer') ?></th><th><?= tt('org.vat_erp') ?></th><th><?= tt('co.one') ?></th><th><?= tt('org.how') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $cu = $r['customer']; $co = $r['company']; ?>
    <tr><td><a href="<?= url('admin_customers', ['edit' => (int)$cu['id']]) ?>"><b><?= e($cu['name']) ?></b></a><br><span class="hint"><?= e(trim(($cu['city'] ?? '') . ' ' . ($cu['country'] ?? ''))) ?></span></td>
      <td class="hint"><?= e((string)($cu['vat_no'] ?? '')) ?><?= $cu['erp_code'] ? ' · ' . e($cu['erp_code']) : '' ?></td>
      <td><?php if ($co): ?><a href="<?= url('companies', ['edit' => (int)$co['id']]) ?>"><?= e($co['name']) ?></a> <?= co_kind_pill($co['kind']) ?><?= !empty($co['is_supplier']) ? ' ' . pill('gold', t('co.kind_supplier')) : '' ?><br><span class="hint"><?= e((string)($co['vat_no'] ?? '')) ?><?= $co['erp_code'] ? ' · ' . e($co['erp_code']) : '' ?></span><?php else: ?><span class="hint">—</span><?php endif; ?></td>
      <td><?= $r['how'] ? pill($r['how'] === 'linked' ? 'ok' : ($r['how'] === 'name' ? 'mute' : 'gold'), t('org.how_' . $r['how'])) : '' ?></td>
      <td class="num">
        <?php if ($sec === 'match'): ?><form method="post" action="<?= $self ?>" data-lock style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="org_link"><input type="hidden" name="company_id" value="<?= (int)$co['id'] ?>"><input type="hidden" name="customer_id" value="<?= (int)$cu['id'] ?>"><button class="btn btn--gold btn--sm" type="submit">🔗 <?= tt('org.link') ?></button></form>
        <?php elseif ($sec === 'none'): ?><form method="post" action="<?= $self ?>" data-lock style="display:inline-flex; gap:4px; align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="org_create_co"><input type="hidden" name="customer_id" value="<?= (int)$cu['id'] ?>"><select name="kind" data-nosearch style="width:auto;"><?php foreach ($kinds as $k => $lab): if ($k === 'supplier') continue; ?><option value="<?= $k ?>"<?= $k === 'owner' ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select><button class="btn btn--sm" type="submit">+ <?= tt('org.create_co') ?></button></form>
        <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php layout_foot(); ?>

<?php
/** Procurement › Inventory — items with stock, filters, valuation and the reports (GAS-CP-R79-001).
 *   ?p=inventory[&q=&cat=&maker=&status=&stock=&slow=&loc=&sort=&format=xlsx][&tab=items|valuation|categories] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('pr_can') || !pr_can()) { http_response_code(403); layout_head(t('stk.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('stk.noaccess_hint') . '</p>'; layout_foot(); return; }
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'cat' => (int)($_GET['cat'] ?? 0), 'maker' => (string)($_GET['maker'] ?? ''), 'status' => in_array($_GET['status'] ?? '', ['active', 'obsolete', 'blocked', 'all'], true) ? $_GET['status'] : 'active', 'stock' => in_array($_GET['stock'] ?? '', ['instock', 'zero', 'below', 'reserved'], true) ? $_GET['stock'] : 'any', 'slow' => (int)($_GET['slow'] ?? 0), 'loc' => trim((string)($_GET['loc'] ?? '')), 'sort' => (string)($_GET['sort'] ?? '')];
$items = stk_items($f); $priced = stk_price_visible(); $admin = pr_admin();
if (($_GET['format'] ?? '') === 'xlsx') { stk_export_xlsx($items); exit; }
$tab = in_array($_GET['tab'] ?? '', ['items', 'valuation', 'categories', 'research'], true) ? $_GET['tab'] : 'items';
$cats = stk_categories(false); $makers = stk_makers(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $keep = array_filter($f);
$V = $tab === 'valuation' ? stk_valuation() : null; $cur = 'EUR'; $m = fn(?float $v) => stk_money($v, $cur);
$sumVal = 0.0; foreach ($items as $i) $sumVal += (float)$i['on_hand'] * (float)($i['last_price'] ?? 0);
layout_head(t('stk.title'), 'inventory');
?>
<div class="page-head">
  <div><h1>📦 <?= tt('stk.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('stk.lede') ?></p></div>
  <div class="btn-row">
    <?php if ($admin): ?><a class="btn btn--gold btn--sm" href="<?= url('inventory_item', ['id' => 0]) ?>">+ <?= tt('stk.new_item') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('inventory_import') ?>">⤒ <?= tt('stk.import') ?></a><?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('inventory', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a>
    <?php if ($admin): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_procurement') ?>">⚙ <?= tt('stk.settings') ?></a><?php endif; ?>
  </div>
</div>
<nav class="tabs" style="margin-bottom:12px;"><?php $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('inventory', array_filter($keep) + ['tab' => $k]) . '">' . $lbl . '</a>'; echo $T('items', '📦 ' . t('stk.items')) . $T('valuation', '📊 ' . t('stk.valuation')) . ($admin ? $T('categories', '🏷 ' . t('stk.categories')) : '') . ($admin && function_exists('stk_research_has') && stk_research_has() ? $T('research', '✨ ' . t('stk.ai_tab')) : ''); ?></nav>

<?php if ($tab === 'items'): ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr .8fr .9fr .7fr auto;">
  <input type="hidden" name="p" value="inventory">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('stk.search_ph') ?>"></div>
  <div class="field"><label><?= tt('stk.category') ?></label><select name="cat"><option value=""><?= tt('flt.any') ?></option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($c['id'], $f['cat']) ?>><?= e($c['name']) ?> (<?= (int)$c['n_items'] ?>)</option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('stk.maker') ?></label><select name="maker"><option value=""><?= tt('flt.any') ?></option><?php foreach ($makers as $mk): ?><option value="<?= e($mk) ?>"<?= $sel($mk, $f['maker']) ?>><?= e($mk) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (['active', 'obsolete', 'blocked', 'all'] as $s): ?><option value="<?= $s ?>"<?= $sel($s, $f['status']) ?>><?= e($s === 'all' ? t('flt.any') : t('stk.s_' . $s)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('stk.stock') ?></label><select name="stock" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (['instock', 'zero', 'below', 'reserved'] as $s): ?><option value="<?= $s ?>"<?= $sel($s, $f['stock']) ?>><?= e(t('stk.f_' . $s)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('stk.slow') ?></label><select name="slow" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach ([6, 12, 24] as $mo): ?><option value="<?= $mo ?>"<?= $sel($mo, $f['slow']) ?>><?= e(t('stk.slow_m', ['n' => $mo])) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('inventory') ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <h2><?= tt('stk.items') ?> <span class="count"><?= count($items) ?></span><?php if ($priced): ?> <span class="hint" style="font-weight:400;">· <?= tt('stk.value') ?> <?= e($m($sumVal)) ?></span><?php endif; ?></h2>
  <?php if (!$items): ?><p class="hint"><?= tt('stk.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl inv-list">
    <thead><tr><th><a href="<?= url('inventory', $keep + ['sort' => '']) ?>"><?= tt('stk.code') ?></a></th><th><a href="<?= url('inventory', $keep + ['sort' => 'desc']) ?>"><?= tt('stk.desc') ?></a></th><th><?= tt('stk.category') ?></th><th><?= tt('stk.maker') ?></th><th><?= tt('stk.location') ?></th><th class="num"><a href="<?= url('inventory', $keep + ['sort' => 'stock']) ?>"><?= tt('stk.on_hand') ?></a></th><th class="num"><?= tt('stk.reserved') ?></th><?php if ($priced): ?><th class="num"><?= tt('stk.last_price') ?></th><th class="num"><a href="<?= url('inventory', $keep + ['sort' => 'value']) ?>"><?= tt('stk.value') ?></a></th><?php endif; ?><th></th></tr></thead>
    <tbody><?php [$pageRows, $pager] = page_list($items, 'inventory', $keep); foreach ($pageRows as $i): $below = (float)$i['min_stock'] > 0 && (float)$i['on_hand'] < (float)$i['min_stock']; ?>
      <tr class="<?= $i['status'] !== 'active' ? 'is-retired' : '' ?>">
        <td class="mono"><a href="<?= url('inventory_item', ['id' => (int)$i['id']]) ?>"><b><?= e($i['code']) ?></b></a><?= $i['factory_code'] && $i['factory_code'] !== $i['code'] ? '<br><span class="hint">' . e($i['factory_code']) . '</span>' : '' ?></td>
        <td><?= e($i['description']) ?><?= $i['description_en'] ? '<br><span class="hint">' . e($i['description_en']) . '</span>' : '' ?><?= $i['status'] !== 'active' ? ' ' . pill('mute', t('stk.s_' . $i['status'])) : '' ?></td>
        <td class="hint"><?= e($i['category'] ?? '—') ?></td><td class="hint"><?= e($i['maker'] ?? '') ?></td><td class="mono hint"><?= e($i['location'] ?? '') ?></td>
        <td class="num<?= $below ? ' is-late' : '' ?>"><b><?= e(stk_qty((float)$i['on_hand'])) ?></b> <span class="hint"><?= e($i['unit']) ?></span><?= $below ? '<br>' . pill('bad', t('stk.below_min')) : '' ?></td>
        <td class="num hint"><?= (float)$i['reserved'] > 0 ? e(stk_qty((float)$i['reserved'])) : '—' ?></td>
        <?php if ($priced): ?><td class="num"><?= $i['last_price'] !== null ? e(stk_money((float)$i['last_price'], $i['currency'], 2)) : '<span class="hint">—</span>' ?><?= $i['last_price_at'] ? '<br><span class="hint">' . e(fmt_date($i['last_price_at'])) . '</span>' : '' ?></td><td class="num"><?= e($m((float)$i['on_hand'] * (float)($i['last_price'] ?? 0))) ?></td><?php endif; ?>
        <td><a class="btn btn--ghost btn--sm" href="<?= url('inventory_item', ['id' => (int)$i['id']]) ?>"><?= tt('btn.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?= $pager ?? '' ?>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'valuation'): ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= (int)$V['items'] ?></b><?= tt('stk.items') ?><span class="s"><?= e(t('stk.with_stock', ['n' => $V['with_stock']])) ?></span></div>
  <?php if ($priced): ?><div class="stat gold"><b><?= e($m($V['value_last'])) ?></b><?= tt('stk.value_last') ?><span class="s"><?= tt('stk.value_avg') ?> <?= e($m($V['value_avg'])) ?></span></div><?php endif; ?>
  <a class="stat<?= $V['below_min'] ? ' red' : '' ?>" href="<?= url('inventory', ['stock' => 'below']) ?>"><b><?= (int)$V['below_min'] ?></b><?= tt('stk.f_below') ?><span class="s"><?= tt('stk.below_hint') ?></span></a>
  <a class="stat" href="<?= url('inventory', ['slow' => 12]) ?>"><b><?= count(stk_items(['slow' => 12])) ?></b><?= tt('stk.slow_m', ['n' => 12]) ?><span class="s"><?= e(t('stk.no_price_n', ['n' => $V['no_price']])) ?></span></a>
</div>
<div class="card">
  <h2>🏷 <?= tt('stk.value_by_cat') ?></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('stk.category') ?></th><th class="num"><?= tt('stk.items') ?></th><?php if ($priced): ?><th class="num"><?= tt('stk.value_last') ?></th><th class="num">%</th><?php endif; ?></tr></thead>
  <tbody><?php foreach ($V['by_cat'] as $c): ?><tr><td><a href="<?= url('inventory', ['cat' => (int)$c['category_id'], 'stock' => 'instock']) ?>"><?= e($c['category']) ?></a></td><td class="num"><?= (int)$c['n'] ?></td><?php if ($priced): ?><td class="num"><?= e($m((float)$c['value_last'])) ?></td><td class="num hint"><?= $V['value_last'] > 0 ? round((float)$c['value_last'] / $V['value_last'] * 100, 1) : 0 ?> %</td><?php endif; ?></tr><?php endforeach; ?></tbody></table></div>
</div>

<?php elseif ($tab === 'categories' && $admin): $edit = (int)($_GET['edit'] ?? 0); $ec = $edit ? one('SELECT * FROM inv_categories WHERE id = ?', [$edit]) : null; ?>
<div class="cols">
<div class="card">
  <h2>🏷 <?= tt('stk.categories') ?> <span class="count"><?= count($cats) ?></span></h2>
  <p class="hint"><?= tt('stk.cat_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('stk.category') ?></th><th><?= tt('stk.parent') ?></th><th><?= tt('stk.code_prefix') ?></th><th class="num"><?= tt('stk.items') ?></th><th></th></tr></thead>
  <tbody><?php $byId = array_column($cats, null, 'id'); foreach ($cats as $c): ?><tr class="<?= $c['is_active'] ? '' : 'is-retired' ?>"><td><a href="<?= url('inventory', ['cat' => (int)$c['id']]) ?>"><?= e($c['name']) ?></a></td><td class="hint"><?= e($byId[$c['parent_id']]['name'] ?? '') ?></td><td class="mono hint"><?= e($c['code_prefix'] ?? '') ?></td><td class="num"><?= (int)$c['n_items'] ?></td>
    <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('inventory', ['tab' => 'categories', 'edit' => (int)$c['id']]) ?>#cat-form"><?= tt('btn.edit') ?></a></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<div>
  <div class="card" id="cat-form">
    <h2><?= $ec ? tt('btn.edit') . ' — ' . e($ec['name']) : tt('stk.new_cat') ?></h2>
    <form method="post" action="<?= url('inventory', ['tab' => 'categories']) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="inv_cat_save"><input type="hidden" name="id" value="<?= (int)($ec['id'] ?? 0) ?>">
      <div class="field"><label><?= tt('stk.category') ?> *</label><input name="name" required maxlength="120" value="<?= e($ec['name'] ?? '') ?>"></div>
      <div class="grid grid-2"><div class="field"><label><?= tt('stk.parent') ?></label><select name="parent_id"><option value="">—</option><?php foreach ($cats as $c): if ($ec && (int)$c['id'] === (int)$ec['id']) continue; ?><option value="<?= (int)$c['id'] ?>"<?= $sel($c['id'], $ec['parent_id'] ?? 0) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.code_prefix') ?></label><input name="code_prefix" maxlength="12" value="<?= e($ec['code_prefix'] ?? '') ?>" placeholder="e.g. REL"></div>
        <div class="field"><label><?= tt('stk.sort') ?></label><input name="sort_order" type="number" value="<?= (int)($ec['sort_order'] ?? 0) ?>"></div></div>
      <?php if ($ec): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $ec['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($ec): ?><a class="btn btn--ghost" href="<?= url('inventory', ['tab' => 'categories']) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
  </div>
  <?php if ($ec): ?><div class="card"><h2><?= tt('stk.cat_merge') ?></h2><p class="hint"><?= tt('stk.cat_merge_hint') ?></p>
    <form method="post" action="<?= url('inventory', ['tab' => 'categories']) ?>" data-confirm="<?= e(t('stk.cat_merge_confirm', ['a' => $ec['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('stk.cat_merge') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="inv_cat_merge"><input type="hidden" name="from" value="<?= (int)$ec['id'] ?>">
      <div class="field"><label><?= tt('stk.cat_into') ?></label><select name="into"><?php foreach ($cats as $c): if ((int)$c['id'] === (int)$ec['id']) continue; ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?> (<?= (int)$c['n_items'] ?>)</option><?php endforeach; ?></select></div>
      <button class="btn btn--ghost" type="submit"><?= tt('stk.cat_merge') ?></button></form></div><?php endif; ?>
</div>
</div>
<?php elseif ($tab === 'research' && $admin): $it = null; $back = url('inventory', ['tab' => 'research']); require __DIR__ . '/_stk_research.php'; endif; ?>
<?php layout_foot(); ?>

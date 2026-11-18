<?php
/** HR › Equipment to give — IT, QHSE and the warehouse prepare the lines of their own categories; HR issues the list (GAS-CP-R97-125).  ?p=eq_prepare[&person=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $cats = function_exists('hre_prep_cats') ? hre_prep_cats() : [];
if (!$cats) { http_response_code(403); layout_head(t('hre.prep_title'), 'eq_prepare'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
if (hre_can()) redirect('hr_equipment');
$people = array_values(array_filter(hr_people(true), fn($p) => $p['is_active'])); $pid = (int)($_GET['person'] ?? 0); $P = null; foreach ($people as $x) if ((int)$x['id'] === $pid) $P = $x; if (!$P) $pid = 0;
$self = url('eq_prepare', $pid ? ['person' => $pid] : []); $src = array_filter(hre_sources(), fn($x) => in_array($x['cat'], $cats, true));
$rows = $pid ? array_values(array_filter(hre_items($pid, ['draft', 'pending', 'held', 'missing']), fn($i) => in_array($i['category'], $cats, true))) : [];
layout_head(t('hre.prep_title'), 'eq_prepare');
?>
<div class="page-head"><div><h1>🧰 <?= tt('hre.prep_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e(t('hre.prep_lede', ['c' => implode(', ', array_map('hre_cat_label', $cats))])) ?></p></div></div>
<div class="card">
  <form method="get" action="index.php" class="btn-row"><input type="hidden" name="p" value="eq_prepare">
    <select name="person" required style="min-width:280px;"><option value=""><?= tt('hre.prep_pick') ?></option><?php foreach ($people as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $pid === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['name_en'] . ((string)($x['department'] ?? '') !== '' ? ' — ' . $x['department'] : '')) ?></option><?php endforeach; ?></select>
    <button class="btn btn--sm" type="submit"><?= tt('hre.prep_open') ?></button></form>
</div>
<?php if ($P): ?>
<div class="card" id="eq-lines">
  <h2><?= e($P['name_en']) ?> <span class="count"><?= count($rows) ?></span></h2><p class="hint"><?= tt('hre.prep_hint') ?></p>
  <?php if (!$rows): ?><p class="hint"><?= tt('hre.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?> / <?= tt('hre.tag') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.expires') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $i): ?><tr><td><?= e(hre_cat_icon((string)$i['category']) . ' ' . hre_cat_label((string)$i['category'])) ?></td><td><b><?= e($i['description']) ?></b><?= (string)$i['size'] !== '' ? ' <span class="hint">' . e(t('hre.size') . ' ' . $i['size']) . '</span>' : '' ?></td><td><?= e(trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /')) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= $i['expires_on'] ? e(fmt_date($i['expires_on'])) : '' ?></td><td><?= hre_item_pill($i) ?></td>
    <td class="row-actions"><?php if ($i['status'] === 'draft' && (int)$i['created_by'] === hre_me()): ?><form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hre_del"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<div class="card" id="eq-add">
  <h2>＋ <?= tt('hre.prep_add') ?></h2>
  <form method="post" action="<?= $self ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_add"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="field"><label><?= tt('hre.category') ?></label><select name="category" data-nosearch><?php foreach ($cats as $c): ?><option value="<?= $c ?>"><?= e(hre_cat_icon($c) . ' ' . hre_cat_label($c)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.description') ?></label><input name="description" maxlength="200" placeholder="<?= tt('hre.desc_ph') ?>"></div>
    <?php if ($src): ?><div class="field field--full"><label><?= tt('hre.from_reg') ?></label><select name="src_id"><option value="0">—</option><?php foreach ($src as $iid => $x): $hh = hre_holder_of((int)$iid); ?><option value="<?= (int)$iid ?>"<?= $hh ? ' disabled' : '' ?>><?= e($x['label'] . ($hh ? ' — ' . t('hre.held_by', ['n' => $hh]) : '')) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('hre.from_reg_hint') ?></p></div><?php endif; ?>
    <div class="field"><label><?= tt('hre.serial') ?></label><input name="serial_no" maxlength="80"></div>
    <div class="field"><label><?= tt('hre.tag') ?></label><input name="asset_tag" maxlength="40"></div>
    <div class="field"><label><?= tt('hre.qty') ?></label><input name="qty" value="1" inputmode="decimal" style="max-width:110px;"></div>
    <div class="field"><label><?= tt('hre.size') ?></label><input name="size" maxlength="20" placeholder="<?= tt('hre.size_ph') ?>" style="max-width:160px;"></div>
    <div class="field"><label><?= tt('hre.cond_out') ?></label><select name="cond_out" data-nosearch><?php foreach (HRE_CONDS as $c): ?><option value="<?= $c ?>"><?= tt('hre.k_' . $c) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.expires') ?></label><input type="date" name="expires_on" style="max-width:180px;"><p class="hint"><?= tt('hre.expires_hint') ?></p></div>
    <div class="field field--full"><label><?= tt('hr.notes') ?></label><input name="note" maxlength="300"></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">＋ <?= tt('hre.add_btn') ?></button></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

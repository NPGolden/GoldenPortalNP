<?php
/** Settings › Offer classes (AACE 18R-97) and default payment terms per branch (GAS-CP-R44-001/002). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); $ok = function_exists('pi_has') && pi_has();
$edit = (int)($_GET['edit'] ?? 0); $e = $edit ? oc_get($edit) : null; $isNew = isset($_GET['new']);
$bid = (int)($_GET['branch'] ?? 0); $branches = br_has() ? br_all(true) : [];
layout_head(t('oc.settings'), 'admin_offer_classes');
?>
<?php settings_tabs('admin_offer_classes'); ?>
<h2 class="settings-h"><?= tt('oc.settings') ?></h2>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('pi.need_db') ?></div><?php layout_foot(); return; endif; ?>
<div class="card">
  <div class="card-head"><h2>📐 <?= tt('oc.classes') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('admin_offer_classes', ['new' => 1]) ?>#oc-form">+ <?= tt('oc.new') ?></a></div>
  <p class="hint"><?= tt('oc.lede') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('oc.code') ?></th><th><?= tt('au.name') ?></th><th><?= tt('oc.definition') ?></th><th><?= tt('oc.accuracy') ?></th><th><?= tt('oc.purpose') ?></th><th><?= tt('oc.firm') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach (oc_all() as $c): ?><tr class="<?= $c['is_active'] ? '' : 'is-muted' ?>"><td class="mono"><b><?= e($c['code']) ?></b></td><td><?= e($c['name']) ?></td><td class="hint"><?= e((string)$c['definition']) ?></td><td class="hint"><?= e((string)$c['accuracy']) ?></td><td class="hint"><?= e((string)$c['purpose']) ?></td><td><?= $c['is_firm'] ? pill('green', t('oc.firm')) : pill('gold', t('oc.budgetary')) ?></td><td><?= $c['is_active'] ? pill('green', t('sys.active')) : pill('mute', t('sys.hidden')) ?></td>
    <td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('admin_offer_classes', ['edit' => (int)$c['id']]) ?>#oc-form">✎</a><form method="post" action="<?= url('admin_offer_classes') ?>" class="inline" data-lock data-confirm="<?= tt('oc.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="oc_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($e || $isNew): $v = fn(string $k, $d = '') => e((string)($e[$k] ?? $d)); ?>
  <form method="post" action="<?= url('admin_offer_classes') ?>" id="oc-form" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="oc_save"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <h3><?= $e ? tt('btn.edit') : tt('oc.new') ?></h3>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('oc.code') ?> *</label><input name="code" maxlength="12" required value="<?= $v('code') ?>"></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('au.name') ?> *</label><input name="name" maxlength="80" required value="<?= $v('name') ?>"></div>
      <div class="field"><label><?= tt('oc.definition') ?></label><input name="definition" maxlength="40" value="<?= $v('definition') ?>" placeholder="10 % – 40 %"></div>
      <div class="field"><label><?= tt('oc.accuracy') ?></label><input name="accuracy" maxlength="80" value="<?= $v('accuracy') ?>" placeholder="L: −10 % to −20 % · H: +10 % to +30 %"></div>
      <div class="field"><label><?= tt('oc.purpose') ?></label><input name="purpose" maxlength="120" value="<?= $v('purpose') ?>"></div>
      <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" value="<?= (int)($e['sort_order'] ?? 0) ?>"></div>
      <div class="field"><label class="chk"><input type="checkbox" name="is_firm" value="1"<?= !empty($e['is_firm']) ? ' checked' : '' ?>> <?= tt('oc.firm_long') ?></label></div>
      <div class="field"><label class="chk"><input type="checkbox" name="is_active" value="1"<?= !$e || $e['is_active'] ? ' checked' : '' ?>> <?= tt('sys.active') ?></label></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('admin_offer_classes') ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
  <?php endif; ?>
</div>
<div class="card">
  <h2>💶 <?= tt('pt.default_title') ?></h2>
  <p class="hint"><?= tt('pt.default_hint') ?></p>
  <?php if (count($branches) > 1): ?><nav class="tabs" style="margin:6px 0 12px;"><?php foreach ($branches as $b): ?><a class="<?= (int)$b['id'] === $bid || (!$bid && (int)$b['is_default']) ? 'is-on' : '' ?>" href="<?= url('admin_offer_classes', ['branch' => (int)$b['id']]) ?>"><?= e($b['code'] . ' · ' . $b['name']) ?></a><?php endforeach; ?></nav><?php $bid = $bid ?: (int)br_default()['id']; endif; ?>
  <form method="post" action="<?= url('admin_offer_classes', $bid ? ['branch' => $bid] : []) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pt_default"><input type="hidden" name="branch_id" value="<?= $bid ?>">
    <table class="tbl" style="max-width:720px;"><thead><tr><th>#</th><th class="num">%</th><th><?= tt('pt.trigger') ?></th><th class="num"><?= tt('pt.due_days') ?></th></tr></thead><tbody>
    <?php $rows = pt_default($bid); for ($i = 0; $i < max(count($rows) + 1, 5); $i++): $t = $rows[$i] ?? null; ?><tr><td class="mono"><?= $i + 1 ?></td><td class="num"><input name="pct[<?= $i ?>]" inputmode="decimal" value="<?= $t ? e(rtrim(rtrim(number_format((float)$t['pct'], 2, '.', ''), '0'), '.')) : '' ?>" style="width:70px;text-align:right;"></td><td><input name="trigger[<?= $i ?>]" maxlength="160" value="<?= e((string)($t['trigger_text'] ?? '')) ?>" placeholder="<?= tt('pt.trigger_ph') ?>"></td><td class="num"><input name="due[<?= $i ?>]" type="number" min="0" max="365" value="<?= $t && $t['due_days'] !== null ? (int)$t['due_days'] : '' ?>" style="width:70px;"></td></tr><?php endfor; ?>
    </tbody></table>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php layout_foot(); ?>

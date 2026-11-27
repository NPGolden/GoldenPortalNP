<?php
/** Procurement › Inventory › Equipment › one unit (GAS-CP-R97-290): details, place or holder, calibration (ISO 9001 measuring equipment register), giving it to a person, taking it out of use, history.  ?p=inv_equipment_unit&id=N */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $r = function_exists('eqp_can_view') && eqp_can_view() ? eqp_unit($id) : null;
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$edit = eqp_can_edit(); $qh = eqp_qh(); $c = eqp_cal($r); $d = json_decode((string)($r['ism_data'] ?? ''), true) ?: []; $st = eqp_state($r); $ro = $edit ? '' : ' disabled';
layout_head(eqp_no($r) . ' · ' . t('eqp.title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('inv_equipment') ?>"><?= tt('eqp.title') ?></a></p>
<div class="page-head"><div><h1>🧰 <span class="mono"><?= e(eqp_no($r)) ?></span> — <?= e($r['description']) ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= eqp_state_pill($r) ?> <?= e(eqp_where($r)) ?><?php if ($c): ?> · <?= tt('qh.cal') ?>: <?= pill($c[1], t('qh.cal_' . $c[0])) ?><?= $r['ism_until'] ? ' ' . e(fmt_date($r['ism_until'])) : '' ?><?php endif; ?></p></div>
  <div class="btn-row"><?php if (function_exists('pr_wh') && pr_wh()): ?><a class="btn btn--ghost btn--sm" href="<?= url('inventory_item', ['id' => (int)$r['item_id']]) ?>#sn">📦 <?= e($r['item_code']) ?></a><?php endif; ?></div></div>
<div class="cols">
<div>
  <div class="card">
    <h2><?= tt('eqp.h_details') ?></h2>
    <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eqp_save"><input type="hidden" name="unit_id" value="<?= $id ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('eqp.c_no') ?></label><input name="asset_tag" maxlength="40" value="<?= e((string)$r['asset_tag']) ?>"<?= $ro ?>></div>
        <div class="field"><label><?= tt('eqp.c_sn') ?></label><input value="<?= e($r['serial_no']) ?>" disabled></div>
        <div class="field"><label><?= tt('eqp.c_maker') ?> · <?= tt('eqp.c_model') ?></label><input value="<?= e(trim((string)$r['maker'] . ' ' . (string)$r['factory_code'])) ?>" disabled></div>
        <div class="field"><label><?= tt('stk.category') ?></label><input value="<?= e((string)$r['category']) ?>" disabled></div>
        <div class="field"><label><?= tt('eqp.c_area') ?></label><input name="location" maxlength="80" value="<?= e((string)$r['location']) ?>"<?= $ro ?>></div>
        <div class="field"><label><?= tt('eqp.c_supplier') ?></label><input name="supplier" maxlength="160" value="<?= e((string)$r['supplier']) ?>"<?= $ro ?>></div>
        <div class="field"><label><?= tt('eqp.c_purchased') ?></label><input type="date" name="purchased_on" value="<?= e((string)$r['purchased_on']) ?>"<?= $ro ?>></div>
        <div class="field"><label><?= tt('eqp.c_warranty') ?></label><input type="date" name="warranty_until" value="<?= e((string)$r['warranty_until']) ?>"<?= $ro ?>></div>
        <div class="field field--full"><label><?= tt('eqp.c_note') ?></label><input name="note" maxlength="255" value="<?= e((string)$r['note']) ?>"<?= $ro ?>></div>
      </div>
      <p class="hint"><?= tt('eqp.details_hint') ?></p>
      <?php if ($edit): ?><div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div><?php endif; ?>
    </form>
  </div>
  <div class="card" id="cal">
    <h2>📏 <?= tt('eqp.h_cal') ?></h2>
    <?php if ($c): ?>
      <p><?= tt('eqp.cal_rec') ?>: <?php if ($qh): ?><a class="mono" href="<?= url('isms_rec', ['id' => (int)$r['ism_eq_id']]) ?>"><b><?= e($r['ism_code']) ?></b></a><?php else: ?><b class="mono"><?= e($r['ism_code']) ?></b><?php endif; ?> · <?= pill($c[1], t('qh.cal_' . $c[0])) ?></p>
      <table class="tbl tbl--compact" data-nosort><tbody>
        <tr><td class="hint"><?= tt('ism.f_last_cal') ?></td><td><?= !empty($d['last_cal']) ? e(fmt_date($d['last_cal'])) : '—' ?><?= !empty($d['cert_no']) ? ' · ' . e($d['cert_no']) : '' ?><?= !empty($d['lab']) ? ' · ' . e($d['lab']) : '' ?></td></tr>
        <tr><td class="hint"><?= tt('ism.f_valid_until') ?></td><td><?= $r['ism_until'] ? '<b>' . e(fmt_date($r['ism_until'])) . '</b>' : '—' ?></td></tr>
        <tr><td class="hint"><?= tt('ism.f_cal_interval') ?></td><td><?= e((string)($d['cal_interval'] ?? '—')) ?></td></tr>
      </tbody></table>
      <p class="hint"><?= tt('eqp.cal_hint') ?></p>
      <?php if ($qh): ?><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', ['reg' => 'qcal', 'parent' => (int)$r['ism_eq_id']]) ?>">+ <?= tt('eqp.cal_new') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('isms_rec', ['id' => (int)$r['ism_eq_id']]) ?>"><?= tt('eqp.cal_open') ?></a>
        <form method="post" data-lock style="margin-left:auto;" data-confirm="<?= tt('eqp.unlink_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eqp_measuring"><input type="hidden" name="unit_id" value="<?= $id ?>"><input type="hidden" name="how" value="unlink"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('eqp.unlink') ?></button></form></div><?php endif; ?>
    <?php elseif ($st === 'retired'): ?><p class="hint"><?= tt('eqp.cal_no') ?></p>
    <?php elseif (!$qh): ?><p class="hint"><?= tt('eqp.cal_no') ?> <?= tt('eqp.cal_qh_only') ?></p>
    <?php else: $free = eqp_ism_free(); ?>
      <p class="hint"><?= tt('eqp.cal_no') ?> <?= tt('eqp.cal_make_hint') ?></p>
      <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eqp_measuring"><input type="hidden" name="unit_id" value="<?= $id ?>"><input type="hidden" name="how" value="new">
        <div class="btn-row"><label class="hint" style="margin:0;"><?= tt('ism.f_cal_interval') ?></label><input type="number" name="cal_interval" min="1" max="120" value="12" style="max-width:90px;"><button class="btn btn--gold btn--sm" type="submit">📏 <?= tt('eqp.cal_make') ?></button></div></form>
      <?php if ($free): ?><form method="post" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="eqp_measuring"><input type="hidden" name="unit_id" value="<?= $id ?>"><input type="hidden" name="how" value="link">
        <div class="btn-row"><select name="record_id" required style="flex:1;min-width:220px;"><option value="">— <?= tt('eqp.cal_pick') ?> —</option><?php foreach ($free as $fid => $fl): ?><option value="<?= (int)$fid ?>"><?= e($fl) ?></option><?php endforeach; ?></select><button class="btn btn--ghost btn--sm" type="submit"><?= tt('eqp.cal_link') ?></button></div></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<div>
  <div class="card" id="holder">
    <h2>👤 <?= tt('eqp.h_holder') ?></h2>
    <?php if ($st === 'person'): ?>
      <p><b><?= e((string)$r['person']) ?></b> <?= $r['eq_status'] ? pill(['draft' => 'mute', 'pending' => 'warn', 'held' => 'green', 'missing' => 'bad'][$r['eq_status']] ?? 'mute', t('hre.st_' . $r['eq_status'])) : '' ?></p>
      <?php $direct = in_array((string)$r['eq_cat'], EQP_DIRECT, true); ?><p class="hint"><?= tt($direct ? 'eqp.holder_hint_t' : ($r['eq_status'] === 'draft' ? 'eqp.holder_draft' : 'eqp.holder_hint')) ?></p>
      <?php if ($direct && eqp_can_stock()): ?><form method="post" class="btn-row" data-lock data-confirm="<?= tt('eqp.takeback_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eqp_take_back"><input type="hidden" name="unit_id" value="<?= $id ?>">
        <select name="result" data-nosearch style="max-width:140px;"><option value="ok"><?= tt('ptl.r_ok') ?></option><option value="damaged"><?= tt('ptl.r_damaged') ?></option></select><input name="note" maxlength="300" placeholder="<?= tt('ptl.note_ph') ?>" style="flex:1;min-width:140px;"><button class="btn btn--navy btn--sm" type="submit">↩ <?= tt('eqp.takeback_btn') ?></button></form><?php endif; ?>
      <?php if (function_exists('hre_can') && hre_can()): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('hr_person', ['id' => (int)$r['person_id'], 'tab' => 'eq']) ?>"><?= tt('eqp.holder_card') ?></a></div><?php endif; ?>
    <?php elseif ($st === 'wh' && eqp_can_give()): $mine = hre_prep_cats(); $can = $c ? in_array('instrument', $mine, true) : (bool) array_diff($mine, ['instrument']); ?>
      <?php if ($c && $c[0] === 'out'): ?><div class="flash flash--bad"><?= e(t('eqp.e_out_of_service', ['c' => (string)$r['ism_code']])) ?></div>
      <?php elseif (!$can): ?><p class="hint"><?= tt('hre.err_cat') ?></p>
      <?php else: ?>
      <?php if ($c && in_array($c[0], ['expired', 'none'], true)): ?><div class="flash flash--warn"><?= tt('eqp.give_expired') ?></div><?php endif; ?>
      <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eqp_give"><input type="hidden" name="unit_id" value="<?= $id ?>">
        <div class="field"><label><?= tt('eqp.give_to') ?></label><select name="person_id" required><option value="">—</option><?php foreach (hr_people(true) as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name_en']) ?></option><?php endforeach; ?></select></div>
        <div class="grid grid-2">
          <div class="field"><label><?= tt('hre.category') ?></label><?php if ($c): ?><input value="<?= e(hre_cat_label('instrument')) ?>" disabled><?php else: ?><select name="category" data-nosearch><?php foreach (array_diff($mine, ['instrument']) as $k): ?><option value="<?= $k ?>"<?= $k === 'tool' ? ' selected' : '' ?>><?= e(hre_cat_label($k)) ?></option><?php endforeach; ?></select><?php endif; ?></div>
          <div class="field"><label><?= tt('hre.cond_out') ?></label><select name="cond_out" data-nosearch><?php foreach (HRE_CONDS as $k): ?><option value="<?= $k ?>"<?= $k === 'good' ? ' selected' : '' ?>><?= tt('hre.k_' . $k) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label><?= tt('eqp.c_note') ?></label><input name="note" maxlength="300"></div>
        <p class="hint"><?= tt('eqp.give_hint_t') ?></p>
        <div class="btn-row"><button class="btn btn--gold" type="submit">👤 <?= tt('eqp.give_btn') ?></button></div>
      </form><?php endif; ?>
    <?php elseif ($st === 'project'): ?><p>📍 <b><?= e((string)$r['project_code']) ?></b> · <?= tt('ptl.supervisor') ?>: <?= e((string)$r['site_user']) ?><?= $r['site_since'] ? ' · ' . e(fmt_date($r['site_since'])) : '' ?></p><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('project_tools', ['project' => (int)$r['project_id']]) ?>#u<?= $id ?>"><?= tt('ptl.title') ?></a></div>
    <?php else: ?><p class="hint"><?= tt('eqp.holder_none') ?></p><?php endif; ?>
  </div>
  <?php if (eqp_can_stock() && in_array($st, ['wh', 'project'], true)): ?>
  <div class="card"><h2>🗑 <?= tt('eqp.h_retire') ?></h2>
    <form method="post" data-lock data-confirm="<?= tt('eqp.retire_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eqp_retire"><input type="hidden" name="unit_id" value="<?= $id ?>">
      <div class="btn-row"><input name="why" maxlength="120" required placeholder="<?= tt('eqp.retire_why') ?>" style="flex:1;min-width:200px;"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('eqp.retire_btn') ?></button></div><p class="hint"><?= tt('eqp.retire_hint') ?></p></form></div>
  <?php elseif ($st === 'retired'): ?><div class="card"><h2>🗑 <?= tt('eqp.s_retired') ?></h2><p class="hint"><?= $r['retired_on'] ? e(fmt_date($r['retired_on'])) . ' · ' : '' ?><?= e((string)$r['last_ref']) ?></p></div><?php endif; ?>
  <div class="card"><h2>🗒 <?= tt('eqp.h_history') ?></h2>
    <?php $hist = sn_history($id); if (!$hist): ?><p class="hint">—</p><?php else: ?><div class="table-scroll" style="max-height:320px;"><table class="tbl tbl--compact tbl--wrap" data-nosort><tbody>
    <?php foreach ($hist as $h): ?><tr><td class="hint" style="white-space:nowrap;"><?= e(fmt_datetime((string)$h['created_at'])) ?></td><td><?= e(trim((string)($h['ref'] ?? '') . ((string)($h['person'] ?? '') !== '' && strpos((string)$h['ref'], (string)$h['person']) === false ? ' · ' . $h['person'] : '') . ((string)($h['wh_code'] ?? '') !== '' ? ' · ' . $h['wh_code'] : ''))) ?: e($h['event']) ?><br><span class="hint"><?= e((string)$h['user_name']) ?></span></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?></div>
</div>
</div>
<?php layout_foot(); ?>

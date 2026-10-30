<?php
/** Create / edit a unit of the equipment register.   GAS-CP-R27-001   ?p=equipment_edit[&id=ID][&vessel=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $e = $id ? eq_get($id) : null;
if ($id && !$e) { http_response_code(404); exit(t('err.notfound')); }
$v = fn(string $k, $d = '') => e((string)($e[$k] ?? $d));
$vessels = all('SELECT v.id, v.name, c.name AS customer FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.is_active = 1 OR v.id = ? ORDER BY v.name', [(int)($e['vessel_id'] ?? 0)]);
$vid = (int)($e['vessel_id'] ?? ($_GET['vessel'] ?? 0));
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$title = $e ? t('eq.edit_unit') . ' ' . $e['serial_no'] : t('eq.new');
layout_head($title, 'equipment');
?>
<p class="eyebrow"><a href="<?= url('equipment') ?>"><?= tt('eq.title') ?></a> › <?= e($title) ?></p>
<div class="page-head"><div><h1><?= e($title) ?></h1></div></div>
<form method="post" action="<?= url('equipment_edit', $id ? ['id' => $id] : []) ?>" data-lock>
  <?= csrf_field() ?><input type="hidden" name="action" value="eq_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('eq.sn') ?> *</label><input name="serial_no" required maxlength="60" value="<?= $v('serial_no') ?>" placeholder="5433-001"></div>
      <div class="field"><label><?= tt('eq.set') ?></label><input name="set_code" maxlength="80" value="<?= $v('set_code') ?>" placeholder="5433-001/002/003/004"><p class="hint"><?= tt('eq.set_hint') ?></p></div>
      <div class="field"><label><?= tt('eq.kind') ?></label><select name="kind"><?php foreach (eq_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $e['kind'] ?? 'shore_converter') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('eq.maker') ?></label><input name="maker" list="eq-makers" maxlength="60" value="<?= $v('maker') ?>" placeholder="ATLAS"><datalist id="eq-makers"><?php foreach (eq_makers() as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?><option value="ATLAS"><option value="MAGNUS"></datalist></div>
      <div class="field"><label><?= tt('eq.family') ?></label><input name="family" list="eq-families" maxlength="60" value="<?= $v('family') ?>" placeholder="UHF · SPA II · SP200"><datalist id="eq-families"><?php foreach (eq_families() as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist></div>
      <div class="field"><label><?= tt('eq.model') ?></label><input name="model" maxlength="120" value="<?= $v('model') ?>" placeholder="UHF 75kVA"></div>
      <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel_id"><option value=""><?= tt('eq.no_vessel') ?></option><?php foreach ($vessels as $vs): ?><option value="<?= (int)$vs['id'] ?>"<?= $sel($vs['id'], $vid) ?>><?= e($vs['name'] . ($vs['customer'] ? ' · ' . $vs['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('eq.site') ?></label><input name="site_name" maxlength="120" value="<?= $v('site_name') ?>" placeholder="<?= tt('eq.site_ph') ?>"><p class="hint"><?= tt('eq.site_hint') ?></p></div>
      <div class="field"><label><?= tt('eq.first_attendance') ?></label><input type="date" name="first_attendance" value="<?= $v('first_attendance') ?>"></div>
      <div class="field"><label class="chk"><input type="checkbox" name="sold_by_us" value="1"<?= !empty($e['sold_by_us']) ? ' checked' : '' ?>> <?= tt('eq.sold_by_us') ?></label><input type="date" name="date_sold" value="<?= $v('date_sold') ?>" style="margin-top:6px;"></div>
      <div class="field"><label><?= tt('eq.warranty') ?></label><input type="date" name="warranty_until" value="<?= $v('warranty_until') ?>"></div>
      <div class="field"><label><?= tt('eq.warranty_ref') ?></label><input name="warranty_ref" maxlength="80" value="<?= $v('warranty_ref') ?>" placeholder="SF7606"></div>
      <div class="field"><label class="chk"><input type="checkbox" name="retired" value="1"<?= !empty($e['retired']) ? ' checked' : '' ?>> <?= tt('eq.retired') ?></label><input type="date" name="retired_at" value="<?= $v('retired_at') ?>" style="margin-top:6px;"></div>
    </div>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('eq.contacts') ?></label><textarea name="contacts" rows="3" placeholder="<?= tt('eq.contacts_ph') ?>"><?= $v('contacts') ?></textarea></div>
      <div class="field"><label><?= tt('eq.notes') ?> <span class="hint" style="display:inline;"><?= tt('tk.internal') ?></span></label><textarea name="notes" rows="3"><?= $v('notes') ?></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $id ? url('equipment_view', ['id' => $id]) : url('equipment') ?>"><?= tt('btn.cancel') ?></a></div>
  </div>
</form>
<?php layout_foot(); ?>

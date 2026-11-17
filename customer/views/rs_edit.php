<?php
/** Remote support contract — new / edit (GAS-CP-R30-004). */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0);
$c = $id ? rs_get($id) : null;
if ($id && !$c) { http_response_code(404); exit(t('err.notfound')); }
$vessels = all('SELECT v.id, v.name, c.name AS customer FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.is_active = 1 AND v.merged_into IS NULL' . ($c ? ' OR v.id = ' . (int)$c['vessel_id'] : '') . ' ORDER BY v.name');
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$curV = $c ? (int)$c['vessel_id'] : (int)($_GET['vessel'] ?? 0);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$v = fn(string $k, $d = '') => e((string)($c[$k] ?? $d));
$num = fn($x) => $x === null || $x === '' ? '' : rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
layout_head($c ? t('rs.edit') : t('rs.new'), 'cyber');
?>
<p class="eyebrow"><a href="<?= url('cyber') ?>"><?= tt('cy.title') ?></a> › <?= $c ? e($c['contract_no']) : tt('rs.new') ?></p>
<h1>🛡 <?= $c ? tt('rs.edit') : tt('rs.new') ?></h1>
<p class="lede"><?= tt('rs.form_lede') ?></p>
<div class="card">
  <form method="post" action="<?= url('rs_edit', $id ? ['id' => $id] : []) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="rs_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('v.one') ?> *</label><select name="vessel_id" required><option value=""><?= tt('vm.choose') ?></option><?php foreach ($vessels as $vv): ?><option value="<?= $vv['id'] ?>"<?= $sel($vv['id'], $curV) ?>><?= e($vv['name'] . ($vv['customer'] ? ' · ' . $vv['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rs.no') ?></label><input name="contract_no" maxlength="40" value="<?= $v('contract_no') ?>" placeholder="<?= tt('rs.no_auto') ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status"><?php foreach (rs_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $c['status'] ?? 'active') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:1 / span 2;"><label><?= tt('rs.title_f') ?></label><input name="title" maxlength="160" value="<?= $v('title') ?>" placeholder="<?= tt('rs.title_ph') ?>"></div>
      <div class="field"><label><?= tt('rs.contract_customer') ?></label><select name="customer_id"><option value=""><?= tt('rs.customer_of_vessel') ?></option><?php foreach ($customers as $cc): ?><option value="<?= $cc['id'] ?>"<?= $sel($cc['id'], $c['customer_id'] ?? '') ?>><?= e($cc['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rs.start') ?> *</label><input type="date" name="start_date" required value="<?= $v('start_date', date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('rs.end') ?></label><input type="date" name="end_date" value="<?= $v('end_date') ?>"><p class="hint"><?= tt('rs.end_hint') ?></p></div>
      <div class="field"><label><?= tt('rs.notice') ?></label><input type="number" name="renew_notice_days" min="0" max="365" value="<?= (int)($c['renew_notice_days'] ?? 60) ?>"><p class="hint"><?= tt('rs.notice_hint') ?></p></div>
      <?php if (rs_sub_has()): /* R97-091 */ ?>
      <div class="field"><label><?= tt('rs.product') ?></label><input name="product" maxlength="60" list="rs-products" value="<?= $v('product') ?>" placeholder="Mb Connect, FortiGate …"><datalist id="rs-products"><?php foreach (all("SELECT DISTINCT product FROM rs_contracts WHERE product IS NOT NULL AND product <> '' ORDER BY product") as $pp): ?><option value="<?= e($pp['product']) ?>"></option><?php endforeach; ?></datalist></div>
      <div class="field"><label><?= tt('rs.serial') ?></label><input name="serial_no" maxlength="60" value="<?= $v('serial_no') ?>"></div>
      <div class="field"><label><?= tt('rs.lic') ?></label><input type="date" name="license_expiry" value="<?= $v('license_expiry') ?>"><p class="hint"><?= tt('rs.lic_hint') ?></p></div>
      <div class="field"><label><?= tt('rs.period_m') ?></label><select name="period_months"><option value="">—</option><?php foreach ([1, 3, 6, 12, 24, 36, 48, 60] as $pm): ?><option value="<?= $pm ?>"<?= $sel($pm, $c['period_months'] ?? '') ?>><?= e(rs_period_label($pm)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rs.installed') ?></label><input type="date" name="installed_on" value="<?= $v('installed_on') ?>"></div>
      <div class="field"><label><?= tt('rs.pay') ?></label><select name="pay_status"><option value="">—</option><?php foreach (rs_pay_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $c['pay_status'] ?? '') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rs.sq') ?></label><input name="sq_ref" maxlength="60" value="<?= $v('sq_ref') ?>"><p class="hint"><?= tt('rs.sq_hint') ?></p></div>
      <?php endif; ?>
      <div class="field"><label><?= tt('rs.hours_included') ?></label><input type="number" step="0.5" min="0" name="hours_included" value="<?= $num($c['hours_included'] ?? null) ?>" placeholder="<?= tt('rs.unlimited') ?>"></div>
      <div class="field"><label><?= tt('rs.sla') ?></label><input type="number" min="0" name="response_hours" value="<?= $v('response_hours') ?>" placeholder="4"><p class="hint"><?= tt('rs.sla_hint') ?></p></div>
      <div class="field"><label><?= tt('rs.coverage') ?></label><select name="coverage"><?php foreach (rs_coverages() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $c['coverage'] ?? 'office') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:1 / span 3;"><label><?= tt('rs.access') ?></label><input name="access_method" maxlength="160" value="<?= $v('access_method') ?>" placeholder="<?= tt('rs.access_ph') ?>"></div>
      <div class="field"><label><?= tt('rs.fee') ?></label><input type="number" step="0.01" min="0" name="fee" value="<?= $num($c['fee'] ?? null) ?>"></div>
      <div class="field"><label><?= tt('rs.currency') ?></label><input name="currency" maxlength="3" value="<?= $v('currency', 'EUR') ?>"></div>
      <div class="field"><label><?= tt('rs.billing') ?></label><select name="billing"><?php foreach (rs_billings() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $c['billing'] ?? 'yearly') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rs.renewal') ?></label><select name="renewal"><option value="manual"<?= $sel('manual', $c['renewal'] ?? 'manual') ?>><?= tt('rs.renew_manual') ?></option><option value="auto"<?= $sel('auto', $c['renewal'] ?? '') ?>><?= tt('rs.renew_auto') ?></option></select></div>
      <div class="field"><label><?= tt('rs.visibility') ?></label><select name="visibility"><option value="external"<?= $sel('external', $c['visibility'] ?? 'external') ?>><?= tt('rs.vis_external') ?></option><option value="internal"<?= $sel('internal', $c['visibility'] ?? '') ?>><?= tt('rs.vis_internal') ?></option></select></div>
    </div>
    <div class="field"><label><?= tt('rs.scope') ?></label><textarea name="scope" rows="5" placeholder="<?= tt('rs.scope_ph') ?>"><?= $v('scope') ?></textarea></div>
    <div class="field"><label><?= tt('rs.notes_int') ?></label><textarea name="notes" rows="3"><?= $v('notes') ?></textarea></div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
      <a class="btn btn--ghost" href="<?= $id ? url('rs', ['id' => $id]) : url('cyber') ?>"><?= tt('btn.cancel') ?></a>
      <?php if ($id && is_cp_admin()): ?><button class="btn btn--ghost" type="submit" name="action" value="rs_delete" formnovalidate data-js-confirm="<?= e(t('rs.delete_confirm')) ?>"><?= tt('btn.delete') ?></button><?php endif; ?>
    </div>
  </form>
</div>
<?php layout_foot(); ?>

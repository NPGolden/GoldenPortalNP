<?php
/** Settings › KPIs — target and tolerance per KPI, notification on a drop (GAS-CP-R39-001). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$T = function_exists('kpi_has') && kpi_has() ? kpi_targets() : [];
layout_head(t('kpi.settings'), 'admin_kpis');
?>
<?php settings_tabs('admin_kpis'); ?>
<h2 class="settings-h"><?= tt('kpi.settings') ?></h2>
<div class="card">
  <h2>🎯 <?= tt('kpi.settings') ?></h2>
  <p class="hint"><?= tt('kpi.settings_hint') ?></p>
  <?php if (!$T): ?><div class="flash flash--warn"><?= tt('kpi.need_db') ?></div><?php else: ?>
  <form method="post" action="<?= url('admin_kpis') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="kpi_targets">
    <div class="table-scroll"><table class="tbl"><thead><tr><th>KPI</th><th><?= tt('kpi.formula') ?></th><th class="num"><?= tt('kpi.target') ?></th><th class="num"><?= tt('kpi.tolerance') ?></th><th><?= tt('kpi.notify') ?></th></tr></thead><tbody>
    <?php foreach (KPI_KEYS as $k): $t = $T[$k]; ?><tr><td><b><?= e(kpi_label($k)) ?></b><br><span class="hint"><?= in_array($k, KPI_LOWER_BETTER, true) ? tt('kpi.lower_better') : tt('kpi.higher_better') ?></span></td><td class="hint"><?= e(t('kpi.f_' . $k)) ?></td>
      <td class="num"><input name="target[<?= $k ?>]" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$t['target'], 4, '.', ''), '0'), '.')) ?>" style="width:90px;text-align:right;"></td>
      <td class="num"><input name="tolerance[<?= $k ?>]" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$t['tolerance'], 4, '.', ''), '0'), '.')) ?>" style="width:90px;text-align:right;"></td>
      <td><label class="chk" style="margin:0;"><input type="checkbox" name="notify[<?= $k ?>]" value="1"<?= (int)($t['notify'] ?? 1) ? ' checked' : '' ?>> <?= tt('kpi.notify_pm') ?></label></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

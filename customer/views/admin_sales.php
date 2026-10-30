<?php
/** Settings › Sales targets — monthly targets per sales person and company, win probability per offer status (GAS-CP-R39-002). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = function_exists('sl_has') && sl_has();
$y = max(2020, min(2100, (int)($_GET['year'] ?? date('Y')))); $m = max(1, min(12, (int)($_GET['month'] ?? date('m'))));
$T = $ok ? sl_targets($y, $m) : []; $people = $ok ? all("SELECT cu.id, cu.full_name FROM customer_users cu WHERE cu.role IN ('staff','admin') AND cu.is_active = 1 AND (EXISTS (SELECT 1 FROM offers o WHERE o.created_by = cu.id) OR EXISTS (SELECT 1 FROM sales_targets t WHERE t.user_id = cu.id)) ORDER BY cu.full_name") : [];
$defs = $ok && function_exists('of_status_defs') ? of_status_defs(true) : []; $prob = $ok ? sl_prob() : [];
layout_head(t('sl.settings'), 'admin_sales');
?>
<?php settings_tabs('admin_sales'); ?>
<h2 class="settings-h"><?= tt('sl.settings') ?></h2>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('kpi.need_db') ?></div><?php else: ?>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr auto;"><input type="hidden" name="p" value="admin_sales">
    <div class="field"><label><?= tt('bg.month') ?></label><select name="month" data-nosearch><?php for ($i = 1; $i <= 12; $i++): ?><option value="<?= $i ?>"<?= $m === $i ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $i, 1))) ?></option><?php endfor; ?></select></div>
    <div class="field"><label><?= tt('sl.year') ?></label><input name="year" type="number" min="2020" max="2100" value="<?= $y ?>"></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div></form>
  <form method="post" action="<?= url('admin_sales', ['year' => $y, 'month' => $m]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sl_targets"><input type="hidden" name="year" value="<?= $y ?>"><input type="hidden" name="month" value="<?= $m ?>">
    <h3 class="ed-h"><?= tt('sl.targets_for') ?> <?= e(date('F Y', mktime(0, 0, 0, $m, 1, $y))) ?></h3>
    <p class="hint"><?= tt('sl.targets_hint') ?></p>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sl.person') ?></th><th class="num"><?= tt('sl.t_sent') ?></th><th class="num"><?= tt('sl.t_value') ?></th></tr></thead><tbody>
    <?php $c = $T['0|0'] ?? null; ?><tr><td><b><?= tt('sl.company') ?></b></td><td class="num"><input name="t[0|0][sent]" type="number" min="0" value="<?= (int)($c['offers_sent'] ?? 0) ?>" style="width:90px;text-align:right;"></td><td class="num"><input name="t[0|0][value]" inputmode="decimal" value="<?= e(number_format((float)($c['value_approved'] ?? 0), 0, '.', '')) ?>" style="width:120px;text-align:right;"></td></tr>
    <?php foreach ($people as $pp): $t = $T[(int)$pp['id'] . '|0'] ?? null; ?><tr><td><?= e($pp['full_name']) ?></td><td class="num"><input name="t[<?= (int)$pp['id'] ?>|0][sent]" type="number" min="0" value="<?= (int)($t['offers_sent'] ?? 0) ?>" style="width:90px;text-align:right;"></td><td class="num"><input name="t[<?= (int)$pp['id'] ?>|0][value]" inputmode="decimal" value="<?= e(number_format((float)($t['value_approved'] ?? 0), 0, '.', '')) ?>" style="width:120px;text-align:right;"></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <h3 class="ed-h" style="margin-top:16px;"><?= tt('sl.prob_title') ?></h3>
    <p class="hint"><?= tt('sl.prob_hint') ?></p>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.status') ?></th><th class="num"><?= tt('sl.prob') ?> %</th></tr></thead><tbody>
    <?php foreach ($defs as $k => $d): ?><tr><td><?= of_status_pill($k) ?></td><td class="num"><input name="prob[<?= e($k) ?>]" type="number" min="0" max="100" value="<?= (int)($prob[$k] ?? sl_prob_of($k)) ?>" style="width:80px;text-align:right;"></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

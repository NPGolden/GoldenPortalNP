<?php
/** Card: the units of the equipment register in the name of a person, with the calibration of the measuring instruments (GAS-CP-R97-290). Needs $eqpPid. */
$eqpH = function_exists('ptl_with_person') ? ptl_with_person((int)$eqpPid) : []; $eqpMe = function_exists('hre_my_person') ? hre_my_person() : null; $eqpOwn = $eqpMe && (int)$eqpMe['id'] === (int)$eqpPid; if ($eqpH): ?>
<div class="card" id="eq-handed" style="border-left:4px solid var(--cp-gold,#C9A227);">
  <h2>📍 <?= tt('ptl.handed_h') ?> <span class="count"><?= count($eqpH) ?></span></h2>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('eqp.c_no') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('ptl.project') ?></th><th><?= tt('ptl.since') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($eqpH as $x): ?><tr><td class="mono"><b><?= e((string)($x['asset_tag'] ?: $x['serial_no'])) ?></b></td><td><?= e($x['description']) ?></td><td><?= e($x['project_code'] . ' ' . $x['project_name']) ?></td><td><?= e(fmt_datetime((string)$x['d_given_at'])) ?><br><span class="hint"><?= e((string)$x['d_given_by']) ?></span></td><td><?= ptl_flag_pill($x) ?><?php if (ptl_waits($x) && $eqpOwn): ?><form method="post" action="<?= url('my_equipment') ?>" class="btn-row" data-lock style="margin-top:4px;"><?= csrf_field() ?><input type="hidden" name="action" value="ptl_ack"><input type="hidden" name="daily_id" value="<?= (int)$x['daily_id'] ?>"><button class="btn btn--gold btn--sm" type="submit" name="answer" value="ok">✔ <?= tt('ptl.ack_btn') ?></button><input name="note" maxlength="200" placeholder="<?= tt('ptl.note_ph') ?>" style="max-width:130px;"><button class="btn btn--ghost btn--sm" type="submit" name="answer" value="no"><?= tt('ptl.ack_no_btn') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint"><?= tt('ptl.handed_hint') ?></p>
</div>
<?php endif;
$eqpU = function_exists('eqp_of_person') ? eqp_of_person((int)$eqpPid) : []; if ($eqpU): $eqpLink = function_exists('eqp_can_view') && eqp_can_view(); ?>
<div class="card" id="eq-units">
  <h2>📏 <?= tt('eqp.mine_h') ?> <span class="count"><?= count($eqpU) ?></span></h2>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('eqp.c_no') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('eqp.c_sn') ?></th><th><?= tt('qh.cal') ?></th><th><?= tt('ism.f_valid_until') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($eqpU as $x): $xc = eqp_cal($x); ?><tr>
    <td class="mono" style="white-space:nowrap;"><?php if ($eqpLink): ?><a href="<?= url('inv_equipment_unit', ['id' => (int)$x['id']]) ?>"><b><?= e(eqp_no($x)) ?></b></a><?php else: ?><b><?= e(eqp_no($x)) ?></b><?php endif; ?></td>
    <td><?= e($x['description']) ?><span class="hint"> <?= e(trim((string)$x['maker'] . ' ' . (string)$x['factory_code'])) ?></span></td>
    <td class="mono hint"><?= (string)$x['serial_no'] !== (string)$x['asset_tag'] ? e($x['serial_no']) : '—' ?></td>
    <td><?= $xc ? pill($xc[1], t('qh.cal_' . $xc[0])) : '<span class="hint">—</span>' ?></td>
    <td><?= $xc && $x['ism_until'] ? e(fmt_date($x['ism_until'])) : '' ?></td>
    <td><?= $x['eq_status'] ? pill(['draft' => 'mute', 'pending' => 'warn', 'held' => 'green', 'missing' => 'bad'][$x['eq_status']] ?? 'mute', t('hre.st_' . $x['eq_status'])) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint"><?= tt('eqp.mine_hint') ?></p>
</div>
<?php endif; ?>

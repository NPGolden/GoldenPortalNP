<?php
/** Card: the units of the equipment register in the name of a person, with the calibration of the measuring instruments (GAS-CP-R97-290). Needs $eqpPid. */
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

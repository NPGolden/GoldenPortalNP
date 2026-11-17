<?php
/** QHSE — overview of one tab (the integrated system or one standard): registers, what needs attention, calibration; on the first tab the four standards, access and team.   GAS-CP-R97-086   (included by isms.php) */
$S = qh_stats($std); $tot = ['n' => 0, 'open' => 0, 'due' => 0]; foreach ($S['regs'] as $x) foreach ($tot as $k_ => $_) $tot[$k_] += $x[$k_];
$cal = null; if ($std === '9001') { $cal = ['valid' => 0, 'soon' => 0, 'expired' => 0, 'none' => 0, 'out' => 0, 'na' => 0, 'rows' => []]; foreach (ism_list('qequip') as $e_) { [$cs, $ct] = ism_cal_state($e_); $cal[$cs]++; if (in_array($cs, ['soon', 'expired', 'none', 'out'], true)) $cal['rows'][] = $e_ + ['_cs' => $cs, '_ct' => $ct]; } usort($cal['rows'], fn($a, $b) => strcmp((string)($a['review_date'] ?: '0'), (string)($b['review_date'] ?: '0'))); }
?>
<div class="kpi kpi--<?= $cal ? 5 : 3 ?>" style="margin-bottom:10px;">
  <div class="stat"><b><?= $tot['n'] ?></b><?= tt('ism.n_records') ?><span class="s"><?= $std === 'ims' ? tt('qh.ims_s') : e(QH_STD[$std]) ?></span></div>
  <div class="stat<?= $tot['open'] ? ' gold' : ' green' ?>"><b><?= $tot['open'] ?></b><?= tt('ism.n_open') ?><span class="s"><?= tt('qh.open_s') ?></span></div>
  <div class="stat<?= $tot['due'] ? ' red' : ' green' ?>"><b><?= $tot['due'] ?></b><?= tt('ism.n_due') ?><span class="s"><?= tt('qh.due_s') ?></span></div>
  <?php if ($cal): ?><div class="stat<?= $cal['expired'] + $cal['none'] + $cal['out'] ? ' red' : ' green' ?>"><b><?= $cal['valid'] + $cal['soon'] ?> / <?= $cal['valid'] + $cal['soon'] + $cal['expired'] + $cal['none'] + $cal['out'] ?></b><?= tt('qh.k_cal') ?><span class="s"><?= e(t('qh.k_cal_s', ['e' => $cal['expired'] + $cal['none'], 'o' => $cal['out']])) ?></span></div>
  <div class="stat<?= $cal['soon'] ? ' gold' : '' ?>"><b><?= $cal['soon'] ?></b><?= tt('qh.k_soon') ?><span class="s"><?= tt('qh.k_soon_s') ?></span></div><?php endif; ?>
</div>
<div class="cols" style="grid-template-columns: 1.3fr 1fr;">
<div>
<div class="card">
  <h2>🗂 <?= tt('ism.registers') ?> — <?= $std === 'ims' ? tt('qh.ims') : e(QH_STD[$std]) ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('ism.register') ?></th><th><?= tt('ism.clause') ?></th><th class="num"><?= tt('ism.n_records') ?></th><th class="num"><?= tt('ism.n_open') ?></th><th class="num"><?= tt('ism.n_due') ?></th></tr></thead>
    <tbody><?php foreach ($regs as $k => $R): $x = $S['regs'][$k]; ?><tr><td><a href="<?= url('qhse', $PA + ['reg' => $k]) ?>"><?= $R['icon'] ?> <b><?= e(ism_reg_title($k)) ?></b></a><br><span class="hint"><?= tt('ism.h_' . $k) ?></span></td><td class="mono" style="white-space:nowrap;"><?= e($R['clause']) ?></td><td class="num"><?= $x['n'] ?></td><td class="num"><?= $x['open'] ? '<a href="' . url('qhse', $PA + ['reg' => $k, 'show' => 'open']) . '">' . $x['open'] . '</a>' : '0' ?></td><td class="num"><?= $x['due'] ? pill('bad', (string)$x['due']) : '0' ?></td></tr><?php endforeach; ?></tbody>
  </table>
</div>
<?php if ($cal): ?>
<div class="card" id="cal">
  <div class="card-head"><h2>📏 <?= tt('qh.cal_h') ?> <span class="count"><?= count($cal['rows']) ?></span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('isms_rec', ['reg' => 'qequip']) ?>">+ <?= tt('qh.eq_new') ?></a></div></div>
  <?php if (!$cal['rows']): ?><div class="empty"><?= tt('qh.cal_ok') ?></div><?php else: ?><table class="tbl tbl--wrap"><thead><tr><th><?= tt('ism.par_qequip') ?></th><th><?= tt('ism.f_serial') ?></th><th><?= tt('ism.owner') ?></th><th><?= tt('qh.cal') ?></th></tr></thead><tbody><?php foreach (array_slice($cal['rows'], 0, 40) as $e_): $ed = ism_data($e_); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$e_['id']]) ?>"><b class="mono"><?= e($e_['code']) ?></b> <?= e($e_['title']) ?></a></td><td class="mono"><?= e((string)($ed['serial'] ?? '')) ?></td><td><?= e((string)($e_['owner_name'] ?: '—')) ?></td><td style="white-space:nowrap;"><?= pill($e_['_ct'], t('qh.cal_' . $e_['_cs'])) ?><?= $e_['review_date'] ? ' ' . e(fmt_date($e_['review_date'])) : '' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <p class="hint" style="margin-bottom:0;"><?= tt('qh.cal_hint') ?></p>
</div>
<?php endif; ?>
<?php if ($std === 'ims'): ?>
<div class="card">
  <h2>📚 <?= tt('qh.stds_h') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('qh.std') ?></th><th><?= tt('qh.std_what') ?></th><th class="num"><?= tt('ism.n_records') ?></th><th class="num"><?= tt('ism.n_open') ?></th><th class="num"><?= tt('ism.n_due') ?></th></tr></thead><tbody>
  <?php foreach (['9001', '14001', '45001', '22301'] as $sk): $X = qh_stats($sk); $a_ = ['n' => 0, 'open' => 0, 'due' => 0]; foreach ($X['regs'] as $x) foreach ($a_ as $k_ => $_) $a_[$k_] += $x[$k_]; ?><tr><td><a href="<?= url('qhse', ['std' => $sk]) ?>"><b><?= e(QH_STD[$sk]) ?></b></a></td><td class="hint"><?= tt('qh.what_' . $sk) ?></td><td class="num"><?= $a_['n'] ?></td><td class="num"><?= $a_['open'] ?></td><td class="num"><?= $a_['due'] ? pill('bad', (string)$a_['due']) : '0' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="hint" style="margin-bottom:0;"><?= tt('qh.stds_hint') ?></p>
</div>
<?php endif; ?>
</div>
<div>
<div class="card">
  <h2>⏰ <?= tt('ism.due_h') ?> <span class="count"><?= count($S['due']) ?></span></h2>
  <?php if (!$S['due']): ?><div class="empty"><?= tt('ism.due_none') ?></div><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($S['due'] as $r): ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><b class="mono"><?= e($r['code']) ?></b> <?= e($r['title']) ?></a><br><span class="hint"><?= e(ism_reg_title((string)$r['reg'])) ?> · <?= e((string)($r['owner_name'] ?: t('ism.no_owner'))) ?></span></td><td style="white-space:nowrap;"><?= pill('bad', t($r['reg'] === 'qequip' ? 'qh.cal_' . ism_cal_state($r)[0] : ($r['_why'] === 'due' ? 'ism.w_due' : 'ism.w_review'))) ?><br><span class="hint"><?= e(fmt_date($r['_why'] === 'due' ? $r['due_date'] : $r['review_date'])) ?></span></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php if ($std === 'ims'): ?>
<?php if (ism_top()): $AC = qh_access(); $acR = all('SELECT r.id, r.name, (SELECT COUNT(*) FROM customer_users cu WHERE cu.staff_role_id = r.id AND cu.is_active = 1) AS n FROM staff_roles r WHERE r.is_active = 1 ORDER BY r.sort_order, r.name'); $acD = all('SELECT d.id, d.name, (SELECT COUNT(*) FROM customer_users cu WHERE cu.discipline_id = d.id AND cu.is_active = 1) AS n FROM disciplines d WHERE d.is_active = 1 ORDER BY d.sort_order, d.name'); ?>
<div class="card" id="access">
  <h2>🔑 <?= tt('ism.acc_h') ?></h2><p class="hint"><?= tt('qh.acc_hint') ?><?= $AC['saved'] ? '' : ' <b>' . tt('qh.acc_default') . '</b>' ?></p>
  <form method="post" action="<?= url('qhse') ?>#access" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="qh_access_save">
    <?php foreach ([['roles', $acR, 'ism.acc_roles'], ['disc', $acD, 'ism.acc_disc']] as [$nm, $list, $lab]): ?><p style="margin:8px 0 4px;"><b><?= tt($lab) ?></b></p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:4px 12px;"><?php foreach ($list as $x): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="<?= $nm ?>[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $AC[$nm], true) ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['name']) ?> <span class="hint">(<?= (int)$x['n'] ?>)</span></label><?php endforeach; ?></div><?php endforeach; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <p class="hint" style="margin-bottom:0;">🔒 <?= tt('qh.fin_note') ?></p>
</div>
<?php endif; ?>
<div class="card" id="team">
  <h2>👥 <?= tt('qh.team_h') ?></h2><p class="hint"><?= tt('qh.team_hint') ?></p>
  <?php $team = qh_team(); $users = ism_users(); if (ism_manage()): ?>
  <form method="post" action="<?= url('qhse') ?>#team" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="qh_team_save">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:4px 12px;max-height:220px;overflow:auto;"><?php foreach ($users as $x): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="team[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $team, true) ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['full_name']) ?></label><?php endforeach; ?></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <?php else: ?><p><?= e(implode(', ', array_map(fn($x) => $x['full_name'], array_filter($users, fn($x) => in_array((int)$x['id'], $team, true)))) ?: '—') ?></p><?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>

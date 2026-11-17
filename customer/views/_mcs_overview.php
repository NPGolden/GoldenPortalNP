<?php
/** Marine cyber safety — overview: every product with its approval, its ten UR E27 documents and its software.   GAS-CP-R97-081   (included by isms.php) */
$fv = (int)($_GET['vessel'] ?? 0); $P = ism_marine_products(array_filter(['vessel' => $fv])); $today = date('Y-m-d');
$nOk = count(array_filter($P, fn($p) => in_array($p['status'], ['approved', 'in_service'], true))); $dAll = array_sum(array_map(fn($p) => $p['_m']['docs'], $P)); $dOk = array_sum(array_map(fn($p) => $p['_m']['docs_ok'], $P));
$wait = ism_list('msw', ['status' => 'review']); $inwork = count(ism_list('msw', ['status' => 'development'])) + count(ism_list('msw', ['status' => 'testing'])); $toInstall = array_merge(ism_list('msw', ['status' => 'approved']), ism_list('msw', ['status' => 'released']));
$certs = array_values(array_filter($P, function ($p) use ($today) { $d = ism_data($p); return !empty($d['cert_valid']) && $d['cert_valid'] <= date('Y-m-d', strtotime('+90 days')) && $p['status'] !== 'retired'; }));
$vess = []; foreach (ism_marine_products() as $p) if ($p['x_vessel']) $vess[(int)$p['x_vessel']] = (string)$p['vessel_name']; asort($vess);
?>
<div class="kpi kpi--5" style="margin-bottom:10px;">
  <div class="stat<?= $P && $nOk === count($P) ? ' green' : ' gold' ?>"><b><?= $nOk ?> / <?= count($P) ?></b><?= tt('ism.mk_prod') ?><span class="s"><?= tt('ism.mk_prod_s') ?></span></div>
  <div class="stat<?= $dAll && $dOk === $dAll ? ' green' : '' ?>"><b><?= $dOk ?> / <?= $dAll ?></b><?= tt('ism.mk_docs') ?><span class="s"><?= tt('ism.mk_docs_s') ?></span></div>
  <div class="stat<?= $wait ? ' red' : '' ?>"><b><?= count($wait) ?></b><?= tt('ism.mk_wait') ?><span class="s"><?= tt('ism.mk_wait_s') ?></span></div>
  <div class="stat"><b><?= $inwork ?></b><?= tt('ism.mk_work') ?><span class="s"><?= tt('ism.mk_work_s') ?></span></div>
  <div class="stat<?= $toInstall ? ' gold' : '' ?>"><b><?= count($toInstall) ?></b><?= tt('ism.mk_inst') ?><span class="s"><?= tt('ism.mk_inst_s') ?></span></div>
</div>
<div class="card">
  <div class="card-head"><h2>🚢 <?= tt('ism.r_mprod') ?> <span class="count"><?= count($P) ?></span></h2>
    <div class="btn-row"><?php if ($vess): ?><form method="get" action="index.php" class="inline"><input type="hidden" name="p" value="mcs"><select name="vessel" data-nosearch><option value=""><?= tt('ism.all_vessels') ?></option><?php foreach ($vess as $vid => $vn): ?><option value="<?= $vid ?>"<?= $vid === $fv ? ' selected' : '' ?>><?= e($vn) ?></option><?php endforeach; ?></select><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.apply') ?></button></form><?php endif; ?><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', ['reg' => 'mprod']) ?>">+ <?= tt('ism.m_new') ?></a></div></div>
  <p class="hint"><?= tt('ism.h_mprod') ?></p>
  <?php if (!$P): ?><div class="empty"><?= tt('ism.m_none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap">
    <thead><tr><th><?= tt('ism.product') ?></th><th><?= tt('ism.vessel') ?></th><th><?= tt('ism.project') ?></th><th><?= tt('ism.f_class_society') ?></th><th><?= tt('th.status') ?></th><th><?= tt('ism.m_docs') ?></th><th><?= tt('ism.m_sw') ?></th><th><?= tt('ism.owner') ?></th></tr></thead>
    <tbody><?php foreach ($P as $p): $d = ism_data($p); $m = $p['_m']; $why = ism_due($p); ?><tr>
      <td><a href="<?= url('isms_rec', ['id' => (int)$p['id']]) ?>"><b class="mono"><?= e($p['code']) ?></b> <?= e($p['title']) ?></a><?= !empty($d['system']) ? '<br><span class="hint">' . e($d['system']) . (!empty($d['cbs_cat']) ? ' · ' . tt('ism.o_' . $d['cbs_cat']) : '') . '</span>' : '' ?></td>
      <td><?= $p['x_vessel'] ? '<a href="' . url('vessel', ['id' => (int)$p['x_vessel']]) . '">' . e((string)$p['vessel_name']) . '</a>' : '<span class="hint">—</span>' ?></td>
      <td><?= $p['x_sub'] ? '<a href="' . url('subproject', ['id' => (int)$p['x_sub']]) . '">' . e(trim((string)$p['sub_code'] . ' ' . (string)$p['sub_name'])) . '</a><br>' : '' ?><span class="hint"><?= e(trim((string)$p['project_code'] . ' ' . (string)$p['project_name'])) ?: '—' ?></span></td>
      <td><?= !empty($d['class_society']) ? tt('ism.o_' . $d['class_society']) : '<span class="hint">—</span>' ?><?= !empty($d['route']) ? '<br><span class="hint">' . tt('ism.o_' . $d['route']) . '</span>' : '' ?></td>
      <td><?= ism_status_pill('mprod', (string)$p['status']) ?><?= $why ? '<br>' . pill('bad', t($why === 'due' ? 'ism.w_due' : 'ism.w_review')) : '' ?></td>
      <td><a href="<?= url('mcs', ['reg' => 'mdoc', 'parent' => (int)$p['id']]) ?>"><?= pill($m['docs'] && $m['docs_ok'] === $m['docs'] ? 'green' : ($m['docs_ok'] ? 'gold' : 'bad'), $m['docs_ok'] . ' / ' . $m['docs']) ?></a></td>
      <td><?= $m['sw'] ? '<a class="mono" href="' . url('isms_rec', ['id' => $m['sw']['id']]) . '">' . e($m['sw']['v']) . '</a> <span class="hint">' . tt('ism.s_' . $m['sw']['status']) . '</span>' : '<span class="hint">—</span>' ?><?= $m['pending'] ? '<br><a href="' . url('mcs', ['reg' => 'msw', 'parent' => (int)$p['id'], 'show' => 'open']) . '">' . pill('gold', t('ism.m_pending_n', ['n' => $m['pending']])) . '</a>' : '' ?></td>
      <td><?= $p['owner_name'] ? e($p['owner_name']) : '<span class="hint">—</span>' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<div class="cols" style="grid-template-columns: 1fr 1fr;">
<div class="card">
  <h2>✍ <?= tt('ism.m_wait_h') ?> <span class="count"><?= count($wait) ?></span></h2>
  <?php if (!$wait): ?><div class="empty"><?= tt('ism.m_wait_none') ?></div><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($wait as $r): $d = ism_data($r); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><b class="mono"><?= e($r['code']) ?></b> <?= e($r['title']) ?></a> <span class="mono"><?= e((string)($d['version'] ?? '')) ?></span><br><span class="hint"><?= e(trim((string)$r['parent_code'] . ' ' . (string)$r['parent_title'])) ?><?= $r['vessel_name'] ? ' · ' . e($r['vessel_name']) : '' ?></span></td><td class="hint" style="white-space:nowrap;"><?= e((string)($r['owner_name'] ?: '—')) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <?php if ($toInstall): ?><h3 style="margin-top:14px;">⤓ <?= tt('ism.m_inst_h') ?></h3><table class="tbl tbl--wrap"><tbody><?php foreach ($toInstall as $r): $d = ism_data($r); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><b class="mono"><?= e($r['code']) ?></b> <?= e($r['title']) ?></a> <span class="mono"><?= e((string)($d['version'] ?? '')) ?></span><br><span class="hint"><?= e(trim((string)$r['parent_code'] . ' ' . (string)$r['parent_title'])) ?><?= $r['vessel_name'] ? ' · ' . e($r['vessel_name']) : '' ?></span></td><td><?= ism_status_pill('msw', (string)$r['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<div>
<?php if ($certs): ?><div class="card"><h2>📜 <?= tt('ism.m_cert_h') ?></h2><table class="tbl tbl--wrap"><tbody><?php foreach ($certs as $p): $d = ism_data($p); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$p['id']]) ?>"><b class="mono"><?= e($p['code']) ?></b> <?= e($p['title']) ?></a><br><span class="hint"><?= e((string)($d['cert_no'] ?? '')) ?></span></td><td style="white-space:nowrap;<?= $d['cert_valid'] < $today ? 'color:#b3261e;font-weight:600;' : '' ?>"><?= e(fmt_date($d['cert_valid'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<div class="card">
  <h2>👥 <?= tt('ism.mteam_h') ?></h2><p class="hint"><?= tt('ism.mteam_hint') ?></p>
  <?php $team = ism_mteam(); $users = ism_users(); if (ism_manage()): ?>
  <form method="post" action="<?= url('mcs') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_mteam_save">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 12px;max-height:220px;overflow:auto;"><?php foreach ($users as $x): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="team[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $team, true) ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['full_name']) ?></label><?php endforeach; ?></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <?php else: ?><p><?= e(implode(', ', array_map(fn($x) => $x['full_name'], array_filter($users, fn($x) => in_array((int)$x['id'], $team, true)))) ?: '—') ?></p><?php endif; ?>
</div>
<div class="card"><h2>ℹ <?= tt('ism.m_how_h') ?></h2><ol class="hint" style="margin:0;padding-left:18px;"><li><?= tt('ism.m_how_1') ?></li><li><?= tt('ism.m_how_2') ?></li><li><?= tt('ism.m_how_3') ?></li><li><?= tt('ism.m_how_4') ?></li></ol></div>
</div>
</div>

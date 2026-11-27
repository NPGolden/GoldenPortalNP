<?php
/** Fleet › Tools on projects (GAS-CP-R97-291): the company tools sent to projects — per project what is on site, who has each tool now, what was not taken back.
 *  The supervisor hands a tool out and takes it back; the warehouse books tools back in.   ?p=project_tools[&project=ID][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('ptl_can_view') || !ptl_can_view()) { http_response_code(403); layout_head(t('ptl.title'), 'project_tools'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$pid = (int)($_GET['project'] ?? 0); $G = ptl_projects(); $one = $pid ? ($G[$pid] ?? null) : null; $stock = eqp_can_stock();
if ($one && ($_GET['format'] ?? '') === 'xlsx') { require_once __DIR__ . '/../app/xlsxw.php'; while (ob_get_level()) ob_end_clean(); $x = [];
    foreach ($one['units'] as $r) $x[] = [(string)($r['asset_tag'] ?: $r['serial_no']), (string)$r['description'], trim((string)$r['maker'] . ' ' . (string)$r['factory_code']), (string)$r['serial_no'], t('ptl.f_' . ptl_flag($r)), (string)$r['d_person'], (string)$r['d_given_at'], (string)$r['site_user'], (string)$r['site_since'], (string)$r['site_due']];
    xlsx_download('GAS-tools-' . preg_replace('/[^A-Za-z0-9.-]/', '_', $one['p']['code']) . '-' . date('Ymd') . '.xlsx', [['name' => 'Tools', 'first' => true, 'header' => [t('eqp.c_no'), t('stk.desc'), t('eqp.c_maker') . ' · ' . t('eqp.c_model'), t('eqp.c_sn'), t('th.status'), t('ptl.with'), t('ptl.since'), t('ptl.supervisor'), t('ptl.on_site_since'), t('ptl.due')], 'rows' => $x, 'widths' => [12, 40, 24, 20, 16, 26, 18, 24, 14, 14]]]); exit; }
layout_head(t('ptl.title'), 'project_tools');
?>
<?php if ($pid): ?><p class="eyebrow"><a href="<?= url('project_tools') ?>"><?= tt('ptl.title') ?></a></p><?php endif; ?>
<div class="page-head"><div><h1>🧰 <?= tt('ptl.title') ?><?= $one ? ' — <span class="mono">' . e($one['p']['code']) . '</span>' : '' ?></h1><p class="lede" style="margin-bottom:0;"><?= $one ? e($one['p']['name']) : tt('ptl.lede') ?></p></div>
  <div class="btn-row"><?php if ($one): ?><a class="btn btn--ghost btn--sm" href="<?= url('project_tools', ['project' => $pid, 'format' => 'xlsx']) ?>">⤓ Excel</a><?php endif; ?><?php if (eqp_can_view()): ?><a class="btn btn--ghost btn--sm" href="<?= url('inv_equipment', ['state' => 'wh']) ?>">🧰 <?= tt('eqp.title') ?></a><?php endif; ?></div></div>
<?php if (!$pid): ?>
<div class="card">
  <?php if (!$G): ?><div class="empty"><?= tt('ptl.none') ?><?php if ($stock): ?> <?= tt('ptl.none_how') ?><?php endif; ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ptl.project') ?></th><th class="num"><?= tt('ptl.k_all') ?></th><th class="num"><?= tt('ptl.k_out') ?></th><th class="num"><?= tt('ptl.k_late') ?></th><th class="num"><?= tt('ptl.k_missing') ?></th><th><?= tt('ptl.supervisor') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($G as $gid => $g): $n = $g['n']; $sups = array_values(array_unique(array_filter(array_column($g['units'], 'site_user')))); ?><tr>
    <td><a href="<?= url('project_tools', ['project' => $gid]) ?>"><b class="mono"><?= e($g['p']['code']) ?></b></a> <?= e($g['p']['name']) ?><?= $g['p']['status'] === 'completed' ? ' ' . pill('bad', t('ptl.p_done')) : '' ?><?= $n['due'] ? ' ' . pill('gold', t('ptl.p_due', ['n' => $n['due']])) : '' ?></td>
    <td class="num"><b><?= (int)$n['all'] ?></b></td><td class="num"><?= $n['out'] ? pill('gold', (string)$n['out']) : '0' ?></td><td class="num"><?= $n['late'] ? pill('bad', (string)$n['late']) : '0' ?></td><td class="num"><?= $n['missing'] ? pill('bad', (string)$n['missing']) : '0' ?></td>
    <td class="hint"><?= e(implode(', ', $sups)) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('project_tools', ['project' => $gid]) ?>"><?= tt('btn.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <p class="hint"><?= tt('ptl.rule') ?></p>
</div>
<?php elseif (!$one): ?><div class="card"><div class="empty"><?= tt('ptl.none_project') ?></div></div>
<?php else: $n = $one['n']; $people = hr_people(true); $whs = $stock ? stk_warehouses() : []; ?>
<?php if ($one['p']['status'] === 'completed'): ?><div class="flash flash--bad"><?= tt('ptl.done_warn') ?></div><?php endif; ?>
<div class="kpi kpi--4" style="margin-bottom:12px;"><div class="stat"><b><?= (int)$n['all'] ?></b><?= tt('ptl.k_all') ?></div><div class="stat<?= $n['out'] ? ' gold' : '' ?>"><b><?= (int)$n['out'] ?></b><?= tt('ptl.k_out') ?></div><div class="stat<?= $n['late'] ? ' red' : '' ?>"><b><?= (int)$n['late'] ?></b><?= tt('ptl.k_late') ?></div><div class="stat<?= $n['missing'] ? ' red' : '' ?>"><b><?= (int)$n['missing'] ?></b><?= tt('ptl.k_missing') ?></div></div>
<div class="card">
  <h2><?= tt('ptl.h_units') ?> <span class="count"><?= count($one['units']) ?></span></h2>
  <div class="table-scroll"><table class="tbl tbl--wrap" data-nosort><thead><tr><th><?= tt('eqp.c_no') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('th.status') ?></th><th><?= tt('ptl.with') ?></th><th style="min-width:330px;"><?= tt('ptl.c_do') ?></th></tr></thead><tbody>
  <?php foreach ($one['units'] as $r): $f = ptl_flag($r); $can = ptl_can_site($r); ?><tr id="u<?= (int)$r['id'] ?>">
    <td class="mono" style="white-space:nowrap;"><?php if (eqp_can_view()): ?><a href="<?= url('inv_equipment_unit', ['id' => (int)$r['id']]) ?>"><b><?= e((string)($r['asset_tag'] ?: $r['serial_no'])) ?></b></a><?php else: ?><b><?= e((string)($r['asset_tag'] ?: $r['serial_no'])) ?></b><?php endif; ?></td>
    <td><?= e($r['description']) ?><span class="hint"> <?= e(trim((string)$r['maker'] . ' ' . (string)$r['factory_code'])) ?></span><br><span class="hint"><?= tt('ptl.supervisor') ?>: <?= e((string)$r['site_user']) ?><?= $r['site_due'] ? ' · ' . tt('ptl.due') . ' ' . e(fmt_date($r['site_due'])) : '' ?></span></td>
    <td><?= ptl_flag_pill($r) ?></td>
    <td><?php if ($r['daily_id']): ?><b><?= e((string)$r['d_person']) ?></b><br><span class="hint"><?= e(fmt_datetime((string)$r['d_given_at'])) ?></span><?php else: ?><span class="hint">—</span><?php endif; ?></td>
    <td><?php if (!$can): ?><span class="hint">—</span>
      <?php elseif ($r['daily_id']): ?><form method="post" class="btn-row" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ptl_back"><input type="hidden" name="unit_id" value="<?= (int)$r['id'] ?>">
        <select name="result" data-nosearch style="max-width:130px;"><?php foreach (PTL_RESULTS as $k): ?><option value="<?= $k ?>"><?= tt('ptl.r_' . $k) ?></option><?php endforeach; ?></select><input name="note" maxlength="255" placeholder="<?= tt('ptl.note_ph') ?>" style="max-width:150px;"><button class="btn btn--navy btn--sm" type="submit">↩ <?= tt('ptl.back_btn') ?></button></form>
      <?php elseif ($f === 'missing'): ?><form method="post" class="btn-row" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ptl_back"><input type="hidden" name="unit_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="result" value="ok"><input name="note" maxlength="255" placeholder="<?= tt('ptl.note_ph') ?>" style="max-width:170px;"><button class="btn btn--ghost btn--sm" type="submit">✔ <?= tt('ptl.found_btn') ?></button></form>
      <?php else: ?><form method="post" class="btn-row" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ptl_give"><input type="hidden" name="unit_id" value="<?= (int)$r['id'] ?>">
        <select name="person_id" style="max-width:170px;"><option value="0">— <?= tt('ptl.pick') ?> —</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name_en']) ?></option><?php endforeach; ?></select><input name="person_name" maxlength="120" placeholder="<?= tt('ptl.name_ph') ?>" style="max-width:130px;"><button class="btn btn--gold btn--sm" type="submit">👤 <?= tt('ptl.give_btn') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint"><?= tt('ptl.rule') ?></p>
</div>
<?php if ($stock): ?>
<div class="card" style="border-left:4px solid var(--cp-gold,#C9A227);">
  <h2>🏬 <?= tt('ptl.h_return') ?></h2>
  <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ptl_return"><input type="hidden" name="project_id" value="<?= $pid ?>">
    <div class="table-scroll" style="max-height:260px;"><table class="tbl tbl--compact" data-nosort><tbody><?php foreach ($one['units'] as $r): ?><tr><td style="width:26px;"><input type="checkbox" name="units[]" value="<?= (int)$r['id'] ?>"></td><td class="mono"><?= e((string)($r['asset_tag'] ?: $r['serial_no'])) ?></td><td><?= e($r['description']) ?></td><td><?= ptl_flag_pill($r) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><select name="warehouse_id" data-nosearch style="max-width:260px;"><option value="0"><?= tt('ptl.wh_same') ?></option><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select><button class="btn btn--gold" type="submit" data-confirm="<?= tt('ptl.return_confirm') ?>">🏬 <?= tt('ptl.return_btn') ?></button></div>
    <p class="hint"><?= tt('ptl.return_hint') ?></p>
  </form>
</div>
<?php endif; ?>
<?php $hist = ptl_history($pid, 150); if ($hist): ?>
<div class="card"><h2>🗒 <?= tt('ptl.h_log') ?></h2>
  <div class="table-scroll" style="max-height:360px;"><table class="tbl tbl--compact"><thead><tr><th><?= tt('eqp.c_no') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('ptl.with') ?></th><th><?= tt('ptl.since') ?></th><th><?= tt('ptl.c_back') ?></th><th><?= tt('ptl.c_result') ?></th></tr></thead><tbody>
  <?php foreach ($hist as $h): ?><tr><td class="mono"><?= e((string)($h['asset_tag'] ?: $h['serial_no'])) ?></td><td class="hint"><?= e($h['description']) ?></td><td><?= e((string)$h['person_name']) ?></td><td class="hint"><?= e(fmt_datetime((string)$h['given_at'])) ?><br><?= e((string)$h['given_name']) ?></td><td class="hint"><?= $h['returned_at'] ? e(fmt_datetime((string)$h['returned_at'])) . '<br>' . e((string)$h['returned_name']) : '—' ?></td><td><?= $h['result'] ? pill($h['result'] === 'ok' ? 'green' : 'bad', t('ptl.r_' . $h['result'])) : pill('gold', t('ptl.f_out')) ?><?= (string)$h['note'] !== '' ? '<br><span class="hint">' . e($h['note']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php layout_foot(); ?>

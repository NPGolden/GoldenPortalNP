<?php
/** IT & Cybersecurity › ISO 27001 — overview of the ISMS and its registers.   GAS-CP-R97-080   ?p=isms[&reg=][&q=][&status=][&owner=][&show=open|due][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
$marine = ($ISM_AREA ?? '') === 'marine'; $PG = $marine ? 'mcs' : 'isms'; $TT = t($marine ? 'ism.m_title' : 'ism.title');   // R97-081: the same page serves the marine cyber safety area (views/mcs.php)
if (!function_exists('ism_can') || !($marine ? ism_can_marine() : ism_can())) { http_response_code(403); layout_head($TT, $PG); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt($marine ? 'ism.m_noaccess_hint' : 'ism.noaccess_hint') . '</p>'; layout_foot(); return; }
if (!$marine) ism_seed();
if (($_GET['format'] ?? '') === 'xlsx') { $marine ? ism_marine_export() : ism_export(); exit; }
$regs = ism_regs($marine ? 'marine' : 'iso'); $reg = (string)($_GET['reg'] ?? ''); if (!isset($regs[$reg])) $reg = '';
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head($TT . ($reg ? ' · ' . ism_reg_title($reg) : ''), $PG);
?>
<div class="page-head"><div><h1><?= $marine ? '🚢' : '🛡' ?> <?= e($TT) ?></h1><p class="lede" style="margin-bottom:0;"><?= tt($marine ? 'ism.m_lede' : 'ism.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url($PG, ['format' => 'xlsx']) ?>">⤓ <?= tt($marine ? 'ism.m_export' : 'ism.export') ?></a><?php if (!$marine): ?><a class="btn btn--ghost" href="<?= url('isms_report') ?>">🚨 <?= tt('ism.report_btn') ?></a><?php endif; ?></div></div>
<nav class="tabs" style="margin-bottom:12px;"><a class="tab<?= $reg === '' ? ' is-on' : '' ?>" href="<?= url($PG) ?>">📊 <?= tt('ism.overview') ?></a><?php foreach ($regs as $k => $R): ?><a class="tab<?= $reg === $k ? ' is-on' : '' ?>" href="<?= url($PG, ['reg' => $k]) ?>"><?= $R['icon'] ?> <?= e(ism_reg_title($k)) ?></a><?php endforeach; ?></nav>

<?php if ($reg === '' && $marine): require __DIR__ . '/_mcs_overview.php'; ?>
<?php elseif ($reg === ''): $O = ism_overview(); $soa = $O['soa']; $pct = $soa['applicable'] ? (int) round(100 * $soa['done'] / $soa['applicable']) : 0; $docs = $O['regs']['doc']; ?>
<div class="kpi kpi--5" style="margin-bottom:10px;">
  <div class="stat<?= $docs['n'] && $docs['st']['approved'] === $docs['n'] ? ' green' : ' gold' ?>"><b><?= (int)$docs['st']['approved'] ?> / <?= (int)$docs['n'] ?></b><?= tt('ism.k_docs') ?><span class="s"><?= tt('ism.k_docs_s') ?></span></div>
  <div class="stat<?= $pct >= 100 ? ' green' : '' ?>"><b><?= $pct ?> %</b><?= tt('ism.k_soa') ?><span class="s"><?= e(t('ism.k_soa_s', ['d' => $soa['done'], 'a' => $soa['applicable'], 'n' => $soa['na']])) ?></span></div>
  <div class="stat<?= $O['risk']['high'] ? ' red' : '' ?>"><b><?= (int)$O['risk']['open'] ?></b><?= tt('ism.k_risks') ?><span class="s"><?= e(t('ism.k_risks_s', ['h' => $O['risk']['high'], 'm' => $O['risk']['medium'], 'l' => $O['risk']['low']])) ?></span></div>
  <div class="stat<?= $O['regs']['incident']['open'] ? ' red' : '' ?>"><b><?= (int)$O['regs']['incident']['open'] ?></b><?= tt('ism.k_inc') ?><span class="s"><?= e(t('ism.k_inc_s', ['n' => $O['regs']['incident']['n']])) ?></span></div>
  <div class="stat<?= $O['regs']['nc']['open'] ? ' gold' : '' ?>"><b><?= (int)$O['regs']['nc']['open'] ?></b><?= tt('ism.k_nc') ?><span class="s"><?= e(t('ism.k_nc_s', ['n' => $O['regs']['nc']['due']])) ?></span></div>
</div>
<div class="cols" style="grid-template-columns: 1.1fr 1fr;">
<div class="card">
  <h2><?= tt('ism.registers') ?></h2>
  <table class="tbl"><thead><tr><th><?= tt('ism.register') ?></th><th><?= tt('ism.clause') ?></th><th class="num"><?= tt('ism.n_records') ?></th><th class="num"><?= tt('ism.n_open') ?></th><th class="num"><?= tt('ism.n_due') ?></th></tr></thead><tbody>
  <?php foreach ($regs as $k => $R): $x = $O['regs'][$k]; ?><tr><td><a href="<?= url('isms', ['reg' => $k]) ?>"><?= $R['icon'] ?> <b><?= e(ism_reg_title($k)) ?></b></a></td><td class="hint"><?= e($R['clause']) ?></td><td class="num"><?= (int)$x['n'] ?></td><td class="num"><?= $x['open'] ? '<a href="' . url('isms', ['reg' => $k, 'show' => 'open']) . '">' . (int)$x['open'] . '</a>' : '<span class="hint">0</span>' ?></td><td class="num"><?= $x['due'] ? '<a href="' . url('isms', ['reg' => $k, 'show' => 'due']) . '" style="color:#b3261e;"><b>' . (int)$x['due'] . '</b></a>' : '<span class="hint">0</span>' ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="hint"><?= tt('ism.registers_hint') ?></p>
</div>
<div>
<div class="card">
  <h2>⏰ <?= tt('ism.due_h') ?> <span class="count"><?= count($O['due']) ?></span></h2>
  <?php if (!$O['due']): ?><div class="empty"><?= tt('ism.due_none') ?></div><?php else: ?>
  <table class="tbl tbl--wrap"><tbody><?php foreach ($O['due'] as $r): ?><tr><td><?= $regs[$r['reg']]['icon'] ?> <a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><b><?= e($r['code']) ?></b></a> <?= e(mb_substr((string)$r['title'], 0, 70)) ?><br><span class="hint"><?= e((string)($r['owner_name'] ?: t('ism.no_owner'))) ?></span></td><td style="white-space:nowrap;"><?= pill($r['_why'] === 'due' ? 'bad' : 'gold', t($r['_why'] === 'due' ? 'ism.w_due' : 'ism.w_review')) ?><br><span class="hint"><?= e(fmt_date($r['_why'] === 'due' ? $r['due_date'] : $r['review_date'])) ?></span></td></tr><?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>
<?php if ($O['next']): ?><div class="card"><h2>🔍 <?= tt('ism.next_h') ?></h2><table class="tbl"><tbody><?php foreach ($O['next'] as $r): $d = ism_data($r); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><b><?= e($r['code']) ?></b></a> <?= e($r['title']) ?><br><span class="hint"><?= !empty($d['kind']) ? tt('ism.o_' . $d['kind']) : '' ?></span></td><td style="white-space:nowrap;"><?= e(fmt_date($r['due_date'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (ism_manage()): $AC = ism_access(); $acR = all('SELECT r.id, r.name, (SELECT COUNT(*) FROM customer_users cu WHERE cu.staff_role_id = r.id AND cu.is_active = 1) AS n FROM staff_roles r WHERE r.is_active = 1 ORDER BY r.sort_order, r.name'); $acD = all('SELECT d.id, d.name, (SELECT COUNT(*) FROM customer_users cu WHERE cu.discipline_id = d.id AND cu.is_active = 1) AS n FROM disciplines d WHERE d.is_active = 1 ORDER BY d.sort_order, d.name'); ?>
<div class="card" id="access">
  <h2>🔑 <?= tt('ism.acc_h') ?></h2><p class="hint"><?= tt('ism.acc_hint') ?><?= $AC['saved'] ? '' : ' <b>' . tt('ism.acc_default') . '</b>' ?></p>
  <form method="post" action="<?= url('isms') ?>#access" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_access_save">
    <?php foreach ([['roles', $acR, 'ism.acc_roles'], ['disc', $acD, 'ism.acc_disc']] as [$nm, $list, $lab]): ?><p style="margin:8px 0 4px;"><b><?= tt($lab) ?></b></p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 12px;"><?php foreach ($list as $x): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="<?= $nm ?>[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $AC[$nm], true) ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['name']) ?> <span class="hint">(<?= (int)$x['n'] ?>)</span></label><?php endforeach; ?></div><?php endforeach; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php endif; ?>
<div class="card">
  <h2>👥 <?= tt('ism.team_h') ?></h2><p class="hint"><?= tt('ism.team_hint') ?></p>
  <?php $team = ism_team(); $users = ism_users(); if (ism_manage()): ?>
  <form method="post" action="<?= url('isms') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_team_save">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 12px;max-height:220px;overflow:auto;"><?php foreach ($users as $x): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="team[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $team, true) ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['full_name']) ?></label><?php endforeach; ?></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <?php else: ?><p><?= e(implode(', ', array_map(fn($x) => $x['full_name'], array_filter($users, fn($x) => in_array((int)$x['id'], $team, true)))) ?: '—') ?></p><?php endif; ?>
</div>
</div>
</div>

<?php else: $R = ism_reg($reg); $f = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80), 'status' => isset($R['st'][$_GET['status'] ?? '']) ? (string)$_GET['status'] : '', 'owner' => (int)($_GET['owner'] ?? 0), 'show' => in_array($_GET['show'] ?? '', ['open', 'due'], true) ? (string)$_GET['show'] : ''];
  if ($marine) { $f['vessel'] = (int)($_GET['vessel'] ?? 0); $f['project'] = (int)($_GET['project'] ?? 0); $f['parent'] = (int)($_GET['parent'] ?? 0); $opt = ['v' => [], 'p' => [], 'c' => []]; foreach (ism_list($reg) as $x) { if ($x['x_vessel']) $opt['v'][(int)$x['x_vessel']] = (string)$x['vessel_name']; if ($x['x_project']) $opt['p'][(int)$x['x_project']] = trim((string)$x['project_code'] . ' ' . (string)$x['project_name']); if ($x['parent_id']) $opt['c'][(int)$x['parent_id']] = trim((string)$x['parent_code'] . ' ' . (string)$x['parent_title']); } foreach ($opt as &$o_) asort($o_); unset($o_); }
  $rows = ism_list($reg, $f); $owners = all('SELECT DISTINCT owner_user_id AS id, owner_name AS name FROM ism_records WHERE reg = ? AND owner_user_id IS NOT NULL ORDER BY owner_name', [$reg]); [$rows, $pager] = page_list($rows, $PG, array_filter(['reg' => $reg] + $f)); ?>
<div class="card">
  <div class="card-head"><h2><?= $R['icon'] ?> <?= e(ism_reg_title($reg)) ?> <span class="hint" style="font-weight:400;"><?= e($R['clause']) ?></span></h2><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', array_filter(['reg' => $reg, 'parent' => $f['parent'] ?? 0])) ?>">+ <?= tt('ism.new') ?></a></div></div>
  <p class="hint"><?= tt('ism.h_' . $reg) ?></p>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr<?= $marine ? ' 1fr 1fr' . ($reg !== 'mprod' ? ' 1fr' : '') : '' ?> auto;"><input type="hidden" name="p" value="<?= $PG ?>"><input type="hidden" name="reg" value="<?= e($reg) ?>">
    <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" maxlength="80"></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach ($R['st'] as $sk => $_): ?><option value="<?= $sk ?>"<?= $sel($sk, $f['status']) ?>><?= tt('ism.s_' . $sk) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ism.owner') ?></label><select name="owner"><option value=""><?= tt('flt.any') ?></option><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"<?= $sel($o['id'], $f['owner']) ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('r.show') ?></label><select name="show" data-nosearch><option value=""><?= tt('r.show_all') ?></option><option value="open"<?= $sel('open', $f['show']) ?>><?= tt('ism.show_open') ?></option><option value="due"<?= $sel('due', $f['show']) ?>><?= tt('ism.show_due') ?></option></select></div>
    <?php if ($marine): ?>
    <?php if ($reg !== 'mprod'): ?><div class="field"><label><?= tt('ism.product') ?></label><select name="parent"><option value=""><?= tt('flt.any') ?></option><?php foreach ($opt['c'] as $k_ => $n_): ?><option value="<?= $k_ ?>"<?= $sel($k_, $f['parent']) ?>><?= e($n_) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label><?= tt('ism.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($opt['v'] as $k_ => $n_): ?><option value="<?= $k_ ?>"<?= $sel($k_, $f['vessel']) ?>><?= e($n_) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ism.project') ?></label><select name="project"><option value=""><?= tt('flt.any') ?></option><?php foreach ($opt['p'] as $k_ => $n_): ?><option value="<?= $k_ ?>"<?= $sel($k_, $f['project']) ?>><?= e($n_) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url($PG, ['reg' => $reg]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
  <?php if (!$rows): ?><div class="empty"><?= tt('ism.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap">
    <thead><tr><th><?= tt('ism.code') ?></th><th><?= tt('ism.title_f') ?></th><?php if ($reg === 'risk'): ?><th class="num"><?= tt('ism.score') ?></th><th class="num"><?= tt('ism.res_score') ?></th><?php elseif ($reg === 'incident'): ?><th><?= tt('ism.f_severity') ?></th><?php elseif ($reg === 'audit'): ?><th><?= tt('ism.f_kind') ?></th><?php elseif ($reg === 'asset'): ?><th><?= tt('ism.f_type') ?></th><?php elseif ($reg === 'msw'): ?><th><?= tt('ism.f_version') ?></th><?php endif; ?><?php if ($marine): ?><?php if ($reg !== 'mprod'): ?><th><?= tt('ism.product') ?></th><?php endif; ?><th><?= tt('ism.vessel') ?> · <?= tt('ism.project') ?></th><?php endif; ?><th><?= tt('th.status') ?></th><th><?= tt($reg === 'msw' ? 'ism.own_msw' : 'ism.owner') ?></th><th><?= tt('ism.dates') ?></th><th class="num">📎</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $d = ism_data($r); $why = ism_due($r); ?><tr>
      <td style="white-space:nowrap;"><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>" class="mono"><b><?= e($r['code']) ?></b></a></td>
      <td><a href="<?= url('isms_rec', ['id' => (int)$r['id']]) ?>"><?= e($r['title']) ?></a><?= $r['ref'] ? '<br><span class="hint">' . e($r['ref']) . '</span>' : '' ?></td>
      <?php if ($reg === 'risk'): $s1 = ism_score($d); $s2 = ism_score($d, 'res_'); [$t1, $l1] = ism_level($s1); [$t2, $l2] = ism_level($s2); ?><td class="num"><?= $s1 ? pill($t1, $s1 . ' · ' . $l1) : '<span class="hint">—</span>' ?></td><td class="num"><?= $s2 ? pill($t2, $s2 . ' · ' . $l2) : '<span class="hint">—</span>' ?></td>
      <?php elseif ($reg === 'incident'): ?><td><?= !empty($d['severity']) ? pill(['low' => 'green', 'medium' => 'gold', 'high' => 'bad', 'critical' => 'bad'][$d['severity']] ?? 'mute', t('ism.o_' . $d['severity'])) : '<span class="hint">—</span>' ?></td>
      <?php elseif ($reg === 'audit'): ?><td><?= !empty($d['kind']) ? tt('ism.o_' . $d['kind']) : '<span class="hint">—</span>' ?></td>
      <?php elseif ($reg === 'asset'): ?><td><?= !empty($d['type']) ? tt('ism.o_' . $d['type']) : '<span class="hint">—</span>' ?></td>
      <?php elseif ($reg === 'msw'): ?><td class="mono"><?= e((string)($d['version'] ?? '')) ?><?= !empty($d['component']) ? '<br><span class="hint">' . tt('ism.o_' . $d['component']) . '</span>' : '' ?></td><?php endif; ?>
      <?php if ($marine): ?><?php if ($reg !== 'mprod'): ?><td><?= $r['parent_id'] ? '<a href="' . url('isms_rec', ['id' => (int)$r['parent_id']]) . '"><span class="mono">' . e((string)$r['parent_code']) . '</span> ' . e((string)$r['parent_title']) . '</a>' : '<span class="hint">—</span>' ?></td><?php endif; ?>
      <td><?= $r['x_vessel'] ? '<a href="' . url('vessel', ['id' => (int)$r['x_vessel']]) . '">' . e((string)$r['vessel_name']) . '</a>' : '<span class="hint">—</span>' ?><br><span class="hint"><?= $r['x_sub'] ? '<a href="' . url('subproject', ['id' => (int)$r['x_sub']]) . '">' . e(trim((string)$r['sub_code'] . ' ' . (string)$r['sub_name'])) . '</a>' : e(trim((string)$r['project_code'] . ' ' . (string)$r['project_name'])) ?></span></td><?php endif; ?>
      <td><?= ism_status_pill($reg, (string)$r['status']) ?></td>
      <td><?= $r['owner_name'] ? e($r['owner_name']) : '<span class="hint">—</span>' ?></td>
      <td style="white-space:nowrap;"><?= $r['due_date'] ? '<span' . ($why === 'due' ? ' style="color:#b3261e;font-weight:600;"' : '') . '>' . e(fmt_date($r['due_date'])) . '</span>' : '' ?><?= $r['review_date'] ? ($r['due_date'] ? '<br>' : '') . '<span class="hint"' . ($why === 'review' ? ' style="color:#b3261e;font-weight:600;"' : '') . '>↻ ' . e(fmt_date($r['review_date'])) . '</span>' : '' ?><?= !$r['due_date'] && !$r['review_date'] ? '<span class="hint">—</span>' : '' ?></td>
      <td class="num"><?= (int)$r['n_files'] ?: '<span class="hint">0</span>' ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?= $pager ?>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

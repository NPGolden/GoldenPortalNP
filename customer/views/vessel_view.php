<?php
/**
 * Vessel page — the technical history: summary tiles, projects, tickets,
 * who attended (disciplines / technicians), services and parts in order,
 * and the full timeline. PDF / Word report buttons.          GAS-CP-R14-001
 *   ?p=vessel&id=ID[&tab=overview|history|services|parts|people|tickets]
 */
require __DIR__ . '/_layout.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$pid = (int)($_GET['project'] ?? 0) ?: null;
$h = vessel_history($id, $pid);
if ($h && $pid && empty($h['only_project'])) { $pid = null; $h = vessel_history($id); }
$allProjects = $h ? all('SELECT id, project_code, name FROM projects WHERE vessel_id = ? ORDER BY project_code, name', [$id]) : [];
if (!$h) { http_response_code(404); layout_head(t('err.notfound'), 'vessels'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$v = $h['vessel']; $s = $h['stats']; $staff = is_staff();
$tab = (string)($_GET['tab'] ?? 'overview');
if (!in_array($tab, ['overview', 'history', 'services', 'parts', 'people', 'tickets', 'equipment'], true)) $tab = 'overview';
$eqUnits = function_exists('eq_has') && eq_has() ? eq_list(['vessel' => $id, 'status' => 'all']) : [];
$tabs = [['overview', t('vh.tab_overview'), null], ['history', t('vh.tab_history'), count($h['timeline'])], ['services', t('vh.tab_services'), $s['services']], ['parts', t('vh.tab_parts'), $s['parts']], ['people', t('vh.tab_people'), $s['technicians']], ['tickets', t('tk.title'), $s['tickets']], ['equipment', t('eq.title'), count($eqUnits)]];
if ($staff && function_exists('iv_vessel_has') && iv_vessel_has() && fin_visible()) $tabs[] = ['invoicing', t('iv.title'), iv_count('vessel', $id)];   // R43-002
$tabUrl = fn($x) => url('vessel', array_filter(['id' => $id, 'tab' => $x, 'project' => $pid]));
$rq = array_filter(['id' => $id, 'project' => $pid]);
$kindIcon = ['project' => '📁', 'ticket' => '🎫', 'jo' => '🛠', 'fsr' => '📋', 'approval' => '✅'];
$kindUrl = function (array $e) use ($h) {
    switch ($e['kind']) {
        case 'ticket': foreach ($h['tickets'] as $t) if ($t['ticket_no'] === $e['ref']) return url('ticket', ['id' => $t['id']]); break;
        case 'jo': foreach ($h['jos'] as $j) if ($j['jo_no'] === $e['ref']) return url('jo', ['id' => $j['id']]); break;
        case 'fsr': foreach ($h['fsrs'] as $f) if ($f['report_no'] === $e['ref']) return url('fsr', ['id' => $f['id']]); break;
        case 'approval': foreach ($h['approvals'] as $o) if ($o['doc_no'] === $e['ref']) return url('approval', ['id' => $o['id']]); break;
        case 'project': foreach ($h['projects'] as $p) if (($p['project_code'] ?: $p['name']) === $e['ref']) return url('projects', ['project' => $p['id']]); break;
    }
    return '';
};
layout_head($v['name'], 'vessels');
?>
<p class="eyebrow"><a href="<?= url('vessels') ?>"><?= tt('nav.vessels') ?></a> › <?= e($v['name']) ?></p>
<div class="page-head">
  <div>
    <h1>⚓ <?= e($v['name']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= $v['imo'] ? 'IMO ' . e($v['imo']) . ' · ' : '' ?><?= $v['yard'] ? e($v['yard']) . ' · ' : '' ?><?= $staff ? e($v['customer_name'] ?: t('v.unassigned')) : tt('vh.lede') ?></p>
  </div>
  <div class="btn-row">
    <form method="get" action="index.php" class="inline" style="display:inline-flex;align-items:center;gap:6px;">
      <input type="hidden" name="p" value="vessel"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="<?= e($tab) ?>">
      <select name="project" class="js-autosubmit" data-js-submit="1" title="<?= tt('vh.per_project') ?>" style="min-width:200px;">
        <option value=""><?= tt('vh.all_projects') ?></option>
        <?php foreach ($allProjects as $pp): ?><option value="<?= $pp['id'] ?>"<?= $pid === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['project_code'] && !str_starts_with($pp['name'], $pp['project_code']) ? $pp['project_code'] . ' ' . $pp['name'] : $pp['name']) ?></option><?php endforeach; ?>
      </select>
    </form>
    <a class="btn btn--ghost btn--sm" href="<?= url('weekly_vessel', array_filter(['vessel' => $id, 'project' => $pid])) ?>">📅 <?= tt('wv.title') ?></a>
    <a class="btn btn--ghost btn--sm" href="<?= url('report_vessel', $rq) ?>" target="_blank">⤓ <?= tt('vh.report_pdf') ?></a>
    <a class="btn btn--ghost btn--sm" href="<?= url('report_vessel', $rq + ['format' => 'docx']) ?>">⤓ <?= tt('vh.report_docx') ?></a>
    <a class="btn btn--gold btn--sm" href="<?= url('ticket_edit', array_filter(['vessel' => $id, 'project' => $pid])) ?>">+ <?= tt('tk.new') ?></a>
    <?php if ($staff): ?><a class="btn btn--gold btn--sm" href="<?= url('fsr_edit', array_filter(['vessel' => $id, 'project' => $pid])) ?>" title="<?= tt('fsr.service_call_hint') ?>">+ <?= tt('fsr.one') ?></a><?php if (function_exists('fsr_ext_has') && fsr_ext_has()): ?><a class="btn btn--ghost btn--sm" href="<?= url('fsr_ext', array_filter(['vessel' => $id, 'project' => $pid])) ?>">📎 <?= tt('fsr.ext_new') ?></a><?php endif; ?><?php endif; ?>
    <?php if ($staff): ?><a class="btn btn--ghost btn--sm" href="<?= url('vessel_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?>
  </div>
</div>

<div class="kpi kpi--6" style="margin-bottom:16px;">
  <a class="stat" href="<?= url('projects', ['vessel' => $id]) ?>"><b><?= $s['projects'] ?><span class="sub">/ <?= $s['subs'] ?></span></b><?= tt('vh.k_projects') ?></a>
  <a class="stat" href="<?= $tabUrl('people') ?>"><b><?= $s['disciplines'] ?></b><?= tt('vh.k_disciplines') ?></a>
  <a class="stat" href="<?= $tabUrl('people') ?>"><b><?= $s['technicians'] ?></b><?= tt('vh.k_technicians') ?></a>
  <a class="stat" href="<?= $tabUrl('services') ?>"><b><?= $s['services'] ?></b><?= tt('vh.k_services') ?></a>
  <a class="stat" href="<?= $tabUrl('parts') ?>"><b><?= $s['parts'] ?></b><?= tt('vh.k_parts') ?></a>
  <a class="stat<?= $s['open_tickets'] ? ' gold' : '' ?>" href="<?= $tabUrl('tickets') ?>"><b><?= $s['tickets'] ?><?= $s['open_tickets'] ? '<span class="sub">' . $s['open_tickets'] . ' ' . tt('tk.st_open_all') . '</span>' : '' ?></b><?= tt('vh.k_tickets') ?></a>
</div>

<?php if ($pid): ?><div class="flash flash--info" style="margin:0 0 4px;"><?= e(t('vh.scoped', ['p' => $h['only_project']['project_code'] && !str_starts_with($h['only_project']['name'], $h['only_project']['project_code']) ? $h['only_project']['project_code'] . ' ' . $h['only_project']['name'] : $h['only_project']['name']])) ?> <a href="<?= url('vessel', ['id' => $id, 'tab' => $tab]) ?>"><?= tt('vh.all_projects') ?></a></div><?php endif; ?>
<nav class="tabs" aria-label="<?= e($v['name']) ?>">
  <?php foreach ($tabs as [$slug, $label, $n]): ?><a class="<?= $tab === $slug ? 'is-on' : '' ?>" href="<?= $tabUrl($slug) ?>"><?= e($label) ?><?= $n !== null ? ' <span class="n">' . (int)$n . '</span>' : '' ?></a><?php endforeach; ?>
</nav>

<?php if ($tab === 'overview'): ?>
<div class="cols vh-cols">
  <div class="card">
    <h2><?= tt('vh.t_projects') ?></h2>
    <?php if (!$h['projects']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('th.code') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('sys.one') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('vh.progress') ?></th></tr></thead>
      <tbody><?php foreach ($h['projects'] as $p): ?>
        <tr style="background:var(--cp-gold-dim);"><td class="ref"><a href="<?= url('projects', ['project' => $p['id']]) ?>"><?= e($p['project_code'] ?: '—') ?></a></td><td><b><?= e($p['name']) ?></b></td><td></td><td><?= pill(pm_status_tone($p['status']), t('pr.' . $p['status'])) ?></td><td></td></tr>
        <?php foreach ($h['subs'] as $sp): if ((int)$sp['project_id'] !== (int)$p['id']) continue; ?>
        <tr><td class="ref" style="padding-left:22px;"><a href="<?= url('subproject', ['id' => $sp['id']]) ?>"><?= e($sp['code'] ?: '—') ?></a></td><td><?= e($sp['name']) ?></td><td><?= e(trim(($sp['system_abbr'] ? $sp['system_abbr'] . ' ' : '') . ($sp['system_name'] ?? ''))) ?: '<span class="hint">—</span>' ?></td><td><?= pill(pm_status_tone($sp['status']), t('pr.' . $sp['status'])) ?></td><td class="num"><?= vh_sub_pct($sp) ?> %</td></tr>
        <?php endforeach; endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('vh.t_disciplines') ?></h2>
    <p class="hint"><?= tt('vh.people_hint') ?></p>
    <?php if (!$h['disciplines']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('disc.one') ?></th><th><?= tt('vh.technicians') ?></th><th class="num"><?= tt('vh.hours_fsr') ?></th></tr></thead>
      <tbody><?php foreach ($h['disciplines'] as $d): ?><tr><td><b><?= e($d['discipline']) ?></b></td><td><?= e(implode(', ', array_unique($d['people']))) ?></td><td class="num"><?= vh_fmt_h($d['hours']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <h2 style="margin-top:18px;"><?= tt('vh.recent') ?></h2>
    <?php $recent = array_slice(array_reverse($h['timeline']), 0, 8); if (!$recent): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: foreach ($recent as $e): $lnk = $kindUrl($e); ?>
      <div class="rowitem"><span class="ico"><?= $kindIcon[$e['kind']] ?? '•' ?></span><span class="tx"><b><?= $lnk ? '<a href="' . $lnk . '">' . e($e['ref']) . '</a>' : e($e['ref']) ?></b><span><?= e($e['text']) ?></span></span><span class="date hint"><?= e(fmt_date($e['date'])) ?></span></div>
    <?php endforeach; endif; ?>
  </div>
</div>
<?php if (function_exists('ob_for_vessel') && of_has() && of_visible()): $vOffers = ob_for_vessel($id); if ($vOffers || ($staff && function_exists('ob_can') && ob_can())): ?>
<div class="card" id="v-offers"><div class="card-head"><h2>€ <?= tt('of.title') ?> <span class="count"><?= count($vOffers) ?></span></h2><?php if ($staff && function_exists('ob_can') && ob_can()): ?><a class="btn btn--ghost btn--sm" href="<?= url('offer_edit', ['vessel' => $id]) ?>">+ <?= tt('of.new') ?></a><?php endif; ?></div>
  <?php if (!$vOffers): ?><p class="hint"><?= tt('ob.no_offers_vessel') ?></p><?php else: $otRows = array_slice($vOffers, 0, 8); $otShowSub = true; $otNewUrl = null; require __DIR__ . '/_offers_table.php'; endif; ?>
</div>
<?php endif; endif; ?>
<?php if ($staff && function_exists('ct_has') && ct_has()): ?>
<div class="card"><?php $cvVesselId = $id; require __DIR__ . '/_contacts_vessel.php'; ?></div>
<?php endif; ?>
<?php if (function_exists('wf_has') && wf_has()): $wfDecks = wf_decks($id); $wfAreas = wf_areas($id); ?>
<div class="card" id="wf-vessel">
  <div class="card-head"><h2>🗺 <?= tt('wf.decks_title') ?> <span class="count"><?= count($wfAreas) ?></span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('vessel_decks', ['id' => $id]) ?>"><?= $staff && wf_can_edit($id) ? '✎ ' . tt('wf.decks_edit') : tt('wf.decks_open') ?> →</a></div></div>
  <?php if (!$wfDecks && !$wfAreas): ?><p class="hint"><?= tt('wf.no_decks_vessel') ?></p><?php else: ?><p class="hint"><?= e(implode(' · ', array_map(fn($d) => $d['name'] . ' (' . count(array_filter($wfAreas, fn($a) => (int)$a['deck_id'] === (int)$d['id'])) . ')', $wfDecks))) ?><?= count(array_filter($wfAreas, fn($a) => !$a['deck_id'])) ? ' · ' . e(t('wf.n_list_only', ['n' => count(array_filter($wfAreas, fn($a) => !$a['deck_id']))])) : '' ?></p><?php endif; ?>
</div>
<?php endif; ?>
<?php if (function_exists('rs_has') && rs_has()): $rsAll = rs_list(['vessel' => $id]); $rsLive = array_values(array_filter($rsAll, fn($c) => in_array($c['status'], ['active', 'suspended'], true))); ?>
<div class="card" id="rs-vessel">
  <div class="card-head"><h2>🛡 <?= tt('rs.title') ?> <span class="count"><?= count($rsAll) ?></span></h2><div class="btn-row"><?php if ($staff): ?><a class="btn btn--ghost btn--sm" href="<?= url('rs_edit', ['vessel' => $id]) ?>">+ <?= tt('rs.new') ?></a><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('cyber', ['vessel' => $id]) ?>"><?= tt('cy.title') ?> →</a></div></div>
  <?php if (!$rsAll): ?><p class="hint"><?= tt('rs.none_vessel') ?></p><?php else: foreach (($rsLive ?: array_slice($rsAll, 0, 2)) as $c) { echo rs_card_html($c); } endif; ?>
</div>
<?php endif; ?>
<?php if ($staff && function_exists('vm_has') && vm_has()): $rel = vm_related($id); $als = vm_aliases($id); $allCust = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name'); ?>
<div class="card" id="vm-related">
  <div class="card-head"><h2><?= tt('vm.related') ?> <span class="count"><?= count($rel) ?></span></h2></div>
  <p class="hint"><?= tt('vm.related_hint') ?></p>
  <?php if ($rel): ?><table class="tbl" data-nosort><thead><tr><th><?= tt('vm.company') ?></th><th><?= tt('vm.role') ?></th><th><?= tt('th.remarks') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($rel as $r): ?><tr><td><a href="<?= url('admin_customers', ['q' => $r['name']]) ?>"><?= e($r['name']) ?></a></td><td><?= pill($r['role'] === 'owner' ? 'gold' : 'navy', vm_role_label($r['role'])) ?></td><td class="hint"><?= e($r['note'] ?? '') ?></td>
      <td style="text-align:right;"><form method="post" action="<?= url('vessel', ['id' => $id]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="vm_related_remove"><input type="hidden" name="id" value="<?= $r['id'] ?>"><input type="hidden" name="vessel_id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
  <form method="post" action="<?= url('vessel', ['id' => $id]) ?>" class="grid grid-4" style="align-items:end;margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="vm_related_set"><input type="hidden" name="vessel_id" value="<?= $id ?>">
    <div class="field" style="margin:0;"><label><?= tt('vm.company') ?></label><select name="customer_id" required><option value=""><?= tt('vm.choose') ?></option><?php foreach ($allCust as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;"><label><?= tt('vm.role') ?></label><select name="role"><?php foreach (VM_ROLES as $r): ?><option value="<?= $r ?>"<?= $r === 'manager' ? ' selected' : '' ?>><?= e(vm_role_label($r)) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;"><label><?= tt('th.remarks') ?></label><input name="note" maxlength="255"></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">+ <?= tt('vm.add') ?></button></div>
  </form>
  <?php if ($als || !empty($v['former_names'])): ?><p class="hint" style="margin-top:12px;"><?php if (!empty($v['former_names'])): ?><b><?= tt('vm.former_names') ?>:</b> <?= e($v['former_names']) ?> · <?php endif; ?><?php if ($als): ?><b><?= tt('vm.aliases') ?>:</b> <?= e(implode(' · ', array_column($als, 'alias'))) ?><?php endif; ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php elseif ($tab === 'history'): ?>
<div class="card">
  <h2><?= tt('vh.t_timeline') ?></h2><p class="hint"><?= tt('vh.timeline_hint') ?></p>
  <?php if (!$h['timeline']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('th.date') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('vh.kind') ?></th><th><?= tt('th.ref') ?></th><th><?= tt('tk.one') ?></th><th><?= tt('vh.what') ?></th></tr></thead>
    <tbody><?php foreach ($h['timeline'] as $e): $lnk = $kindUrl($e); ?><tr><td class="date"><?= e(fmt_date($e['date'])) ?></td><td class="ref"><?= e($e['project'] ?: '—') ?></td><td><?= $kindIcon[$e['kind']] ?? '' ?> <?= e(vh_kind_label($e['kind'])) ?></td><td class="ref"><?= $lnk ? '<a href="' . $lnk . '">' . e($e['ref']) . '</a>' : e($e['ref']) ?></td><td class="ref"><?= $e['ticket'] ? e($e['ticket']) : '<span class="hint">—</span>' ?></td><td><?= e($e['text']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'services'): ?>
<div class="card">
  <h2><?= tt('vh.t_services') ?></h2><p class="hint"><?= tt('vh.services_hint') ?></p>
  <?php if (!$h['services']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('th.date') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('fsr.one') ?></th><th><?= tt('tk.one') ?></th><th><?= tt('vh.service') ?></th><th><?= tt('fsr.open_issues') ?></th></tr></thead>
    <tbody><?php foreach ($h['services'] as $x): ?><tr><td class="date"><?= e(fmt_date($x['date'])) ?></td><td class="ref"><?= e(trim($x['project'] . ' ' . $x['sub'])) ?: '—' ?></td><td class="ref"><a href="<?= url('fsr', ['id' => $x['fsr_pk']]) ?>"><?= e($x['ref']) ?></a></td><td class="ref"><?= $x['ticket'] ? '<a href="' . url('ticket', ['id' => $x['ticket_pk']]) . '">' . e($x['ticket']) . '</a>' : '<span class="hint">—</span>' ?></td><td><?= rt_html($x['what']) ?></td><td><?= $x['issues'] ? rt_html($x['issues']) : '<span class="hint">—</span>' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'parts'): ?>
<div class="card">
  <h2><?= tt('vh.t_parts') ?></h2><p class="hint"><?= tt('vh.parts_hint') ?></p>
  <?php if (!$h['parts']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('th.date') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('fsr.one') ?></th><th><?= tt('tk.one') ?></th><th><?= tt('vh.part') ?></th><th><?= tt('fsr.pt') ?></th></tr></thead>
    <tbody><?php foreach ($h['parts'] as $x): ?><tr><td class="date"><?= e(fmt_date($x['date'])) ?></td><td class="ref"><?= e(trim($x['project'] . ' ' . $x['sub'])) ?: '—' ?></td><td class="ref"><a href="<?= url('fsr', ['id' => $x['fsr_pk']]) ?>"><?= e($x['ref']) ?></a></td><td class="ref"><?= $x['ticket'] ? '<a href="' . url('ticket', ['id' => $x['ticket_pk']]) . '">' . e($x['ticket']) . '</a>' : '<span class="hint">—</span>' ?></td><td><?= e($x['part']) ?></td><td><?= pill($x['type'] === 'W' ? 'green' : ($x['type'] === 'G' ? 'gold' : 'blue'), vh_part_type($x['type'])) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'people'): ?>
<div class="cols vh-cols">
  <div class="card">
    <h2><?= tt('vh.t_technicians') ?></h2><p class="hint"><?= tt('vh.people_hint') ?></p>
    <?php if (!$h['technicians']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('vh.technician') ?></th><th><?= tt('disc.one') ?></th><th class="num"><?= tt('vh.visits') ?></th><th class="num"><?= tt('vh.hours_fsr') ?></th><th><?= tt('vh.first') ?></th><th><?= tt('vh.last') ?></th></tr></thead>
      <tbody><?php foreach ($h['technicians'] as $x): ?><tr><td><b><?= e($x['name']) ?></b></td><td><?= e($x['discipline']) ?></td><td class="num"><?= (int)$x['visits'] ?></td><td class="num"><?= vh_fmt_h($x['hours']) ?></td><td class="date"><?= e(fmt_date($x['first'])) ?></td><td class="date"><?= e(fmt_date($x['last'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('vh.t_disciplines') ?></h2>
    <?php if (!$h['disciplines']): ?><div class="empty"><?= tt('vh.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('disc.one') ?></th><th><?= tt('vh.technicians') ?></th><th class="num"><?= tt('vh.hours_fsr') ?></th></tr></thead>
      <tbody><?php foreach ($h['disciplines'] as $d): ?><tr><td><b><?= e($d['discipline']) ?></b></td><td><?= e(implode(', ', array_unique($d['people']))) ?></td><td class="num"><?= vh_fmt_h($d['hours']) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
    <?php if ($staff && !empty($h['timesheets']['discipline'])): ?>
      <h2 style="margin-top:18px;"><?= tt('vh.t_timesheets') ?> <?= pill('navy', t('tk.internal')) ?></h2>
      <div class="table-scroll"><table>
        <thead><tr><th><?= tt('disc.one') ?></th><th class="num"><?= tt('ts.normal') ?></th><th class="num"><?= tt('ts.overtime') ?></th><th class="num"><?= tt('ts.holiday') ?></th><th class="num"><?= tt('ts.total') ?></th></tr></thead>
        <tbody><?php foreach ($h['timesheets']['discipline'] as $r): ?><tr><td><?= e($r['label']) ?></td><td class="num"><?= ts_fmt((float)$r['normal']) ?></td><td class="num"><?= ts_fmt((float)$r['overtime']) ?></td><td class="num"><?= ts_fmt((float)$r['holiday']) ?></td><td class="num"><b><?= ts_fmt((float)$r['total']) ?></b></td></tr><?php endforeach; ?></tbody>
      </table></div>
      <p class="hint"><a href="<?= url('manhours', ['vessel' => $id]) ?>"><?= tt('mh.title') ?> →</a></p>
    <?php endif; ?>
  </div>
</div>

<?php else: ?>
<div class="card">
  <div class="page-head" style="margin-bottom:8px;"><h2 style="margin:0;"><?= tt('tk.title') ?></h2><a class="btn btn--gold btn--sm" href="<?= url('ticket_edit', ['vessel' => $id]) ?>">+ <?= tt('tk.new') ?></a></div>
  <?php if (!$h['tickets']): ?><div class="empty"><?= tt('tk.empty') ?></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('tk.no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('tk.title_f') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('tk.category') ?></th><th><?= tt('th.status') ?></th><th><?= tt('tk.resolution') ?></th></tr></thead>
    <tbody><?php foreach (array_reverse($h['tickets']) as $t): ?><tr><td class="ref"><a href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['ticket_no']) ?></a></td><td class="date"><?= e(fmt_date(substr((string)$t['created_at'], 0, 10))) ?></td><td><b><?= e($t['title']) ?></b> <?= tk_priority_pill($t['priority']) ?></td><td class="ref"><?= e($t['project_code'] ?: '—') ?></td><td><?= e(tk_categories()[$t['category']] ?? '') ?></td><td><?= tk_status_pill($t['status']) ?></td><td><?= e(mb_substr((string)$t['resolution'], 0, 120)) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (ar_has() && $staff) { $arPid = (int)($h['only_project']['id'] ?? 0) ?: ar_vessel_project($id); if ($arPid) echo ar_issued_html(vh_doc_no($h['vessel'], $h['only_project'] ?? null), $arPid, ['kind' => 'vessel', 'id' => $id, 'project' => $pid], url('vessel', $rq)); } ?>
<?php if ($tab === 'invoicing' && $staff && function_exists('iv_vessel_has') && iv_vessel_has() && fin_visible()): ?>
<div class="card"><p class="hint" style="margin-top:0;"><?= tt('iv.vessel_lede') ?></p><?php iv_section('vessel', $id, url('vessel', ['id' => $id, 'tab' => 'invoicing']), (int)($_GET['iv'] ?? 0)); ?></div>
<?php endif; ?>
<?php if ($tab === 'equipment'): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:8px;"><div><h2 style="margin:0;"><?= tt('eq.title') ?></h2><p class="hint"><?= tt('eq.vessel_lede') ?></p></div><?php if ($staff): ?><a class="btn btn--gold btn--sm" href="<?= url('equipment_edit', ['vessel' => $id]) ?>">+ <?= tt('eq.new') ?></a><?php endif; ?></div>
  <?php if (!$eqUnits): ?><div class="empty"><?= tt('eq.none_vessel') ?></div><?php else: ?>
  <div class="table-scroll"><table><thead><tr><th><?= tt('eq.sn') ?></th><th><?= tt('eq.maker') ?></th><th><?= tt('eq.model') ?></th><th><?= tt('eq.warranty') ?></th><th><?= tt('eq.last_service') ?></th><th class="num"><?= tt('eq.records') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($eqUnits as $e): ?><tr><td class="ref"><a href="<?= url('equipment_view', ['id' => (int)$e['id']]) ?>"><b><?= e($e['serial_no']) ?></b></a></td><td><?= e(trim($e['maker'] . ' ' . ($e['family'] ?? ''))) ?></td><td><?= e($e['model'] ?: '—') ?></td><td class="date"><?= $e['warranty_until'] ? ($e['warranty_until'] >= date('Y-m-d') ? pill('gold', fmt_date($e['warranty_until'])) : e(fmt_date($e['warranty_until']))) : '—' ?></td><td class="date"><?= $e['last_service'] ? e(fmt_date($e['last_service'])) : '—' ?></td><td class="num"><?= (int)$e['n_hist'] ?></td><td><?= $e['retired'] ? pill('mute', t('eq.st_retired')) : pill('green', t('eq.st_active')) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('equipment_view', ['id' => (int)$e['id']]) ?>"><?= tt('fsr.open') ?></a></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

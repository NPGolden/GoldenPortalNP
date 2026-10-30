<?php
/** Commercial › Accounting — the accounting reports and the "new report" form (GAS-CP-R38-001). ?p=acc_reports[&status=&vessel=&project=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('acc_visible') || !acc_visible()) { http_response_code(403); layout_head(t('err.noaccess'), 'acc_reports'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
acc_sync();
$f = ['status' => in_array($_GET['status'] ?? '', ['draft', 'issued', 'sent', 'returned', 'partly_invoiced', 'invoiced'], true) ? $_GET['status'] : '', 'vessel' => (int)($_GET['vessel'] ?? 0), 'project' => (int)($_GET['project'] ?? 0)];
$rows = acc_reports($f); $open = acc_open_summary(); $canNew = acc_can_prepare();
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
[$ps, $pa] = scope_sql('p'); $projects = all("SELECT p.id, p.project_code, p.name, p.vessel_id FROM projects p WHERE p.status <> 'completed' AND {$ps} ORDER BY p.project_code, p.name", $pa);
$subs = all("SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE p.status <> 'completed' ORDER BY p.project_code, s.sort_order, s.code");
$counts = []; foreach (acc_reports([]) as $r) $counts[$r['status']] = ($counts[$r['status']] ?? 0) + 1;
layout_head(t('acc.title'), 'acc_reports');
?>
<div class="page-head"><div><h1>🧾 <?= tt('acc.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('acc.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('acc_register') ?>">☰ <?= tt('acc.register') ?> <span class="count"><?= (int)$open['n'] ?></span></a><?php if ($canNew): ?><a class="btn btn--gold btn--sm" href="#acc-new">+ <?= tt('acc.new') ?></a><?php endif; ?></div></div>

<div class="kpi" style="margin-bottom:14px;">
  <a class="stat gold" href="<?= url('acc_register', ['state' => 'open']) ?>"><b><?= acc_h((float)$open['h']) ?> h</b><?= tt('acc.open_hours') ?></a>
  <a class="stat" href="<?= url('acc_reports', ['status' => 'draft']) ?>"><b><?= (int)($counts['draft'] ?? 0) ?></b><?= tt('acc.rs_draft') ?></a>
  <a class="stat" href="<?= url('acc_reports', ['status' => 'sent']) ?>"><b><?= (int)($counts['issued'] ?? 0) + (int)($counts['sent'] ?? 0) ?></b><?= tt('acc.with_accounting') ?></a>
  <a class="stat<?= !empty($counts['returned']) ? ' red' : '' ?>" href="<?= url('acc_reports', ['status' => 'returned']) ?>"><b><?= (int)($counts['returned'] ?? 0) ?></b><?= tt('acc.rs_returned') ?></a>
  <a class="stat green" href="<?= url('acc_reports', ['status' => 'invoiced']) ?>"><b><?= (int)($counts['invoiced'] ?? 0) ?></b><?= tt('acc.rs_invoiced') ?></a>
</div>

<?php $ov = acc_open_by_vessel(); if ($ov): ?>
<div class="card" id="acc-open">
  <div class="card-head"><h2>⏳ <?= tt('acc.waiting') ?> <span class="count"><?= count($ov) ?></span></h2>
    <?php if ($canNew): ?><form method="post" action="<?= url('acc_reports') ?>" class="inline" data-lock data-busy="<?= tt('acc.collecting') ?>" data-confirm="<?= e(t('acc.run_confirm', ['n' => count(array_filter($ov, fn($x) => $x['can']))])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('acc.run') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_new_all"><input type="hidden" name="to" value="<?= date('Y-m-d') ?>"><button class="btn btn--gold btn--sm" type="submit">▶ <?= tt('acc.run') ?></button></form><?php endif; ?></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('acc.waiting_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('v.one') ?></th><th class="num"><?= tt('ts.hours') ?></th><th class="num"><?= tt('acc.lines') ?></th><th><?= tt('acc.sources') ?></th><th><?= tt('acc.oldest') ?></th><th><?= tt('acc.newest') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($ov as $v): ?>
    <tr class="<?= $v['age'] > 30 ? 'is-late' : '' ?>"><td><?php if ($v['vessel_id']): ?><a href="<?= url('vessel', ['id' => $v['vessel_id']]) ?>"><b><?= e($v['vessel']) ?></b></a><?= (int)$v['n_projects'] > 1 ? ' <span class="hint">' . e(t('acc.n_projects', ['n' => (int)$v['n_projects']])) . '</span>' : '' ?><?php else: ?><b><?= e(trim($v['project_code'] . ' ' . $v['project_name'])) ?></b> <span class="hint">(<?= tt('pr.novessel') ?>)</span><?php endif; ?></td>
      <td class="num"><b><?= e(acc_h((float)$v['h'])) ?></b></td><td class="num"><?= (int)$v['n'] ?></td><td class="hint"><?= (int)$v['n_ts'] ? (int)$v['n_ts'] . ' TS' : '' ?><?= (int)$v['n_ts'] && (int)$v['n_fsr'] ? ' · ' : '' ?><?= (int)$v['n_fsr'] ? (int)$v['n_fsr'] . ' SR' : '' ?></td>
      <td class="date<?= $v['age'] > 30 ? ' bg-neg' : '' ?>"><?= e(fmt_date($v['oldest'])) ?> <span class="hint"><?= e(t('acc.age_d', ['n' => $v['age']])) ?></span></td><td class="date"><?= e(fmt_date($v['newest'])) ?></td>
      <td class="ob-act"><?php if ($v['can']): ?><form method="post" action="<?= url('acc_reports') ?>" class="inline" data-lock data-busy="<?= tt('acc.collecting') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_new"><?php if ($v['vessel_id']): ?><input type="hidden" name="vessel_id" value="<?= (int)$v['vessel_id'] ?>"><?php else: ?><input type="hidden" name="project_id" value="<?= (int)$v['project_id'] ?>"><?php endif; ?><input type="hidden" name="to" value="<?= date('Y-m-d') ?>"><input type="hidden" name="zone" value="local"><button class="btn btn--ghost btn--sm" type="submit">+ <?= tt('acc.collect') ?></button></form><?php else: ?><span class="hint"><?= tt('acc.not_yours') ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php $xp = function_exists('fsr_ext_pending') ? fsr_ext_pending() : []; if ($xp): ?>
<div class="card">
  <h2>📎 <?= tt('fsr.ext_pending') ?> <span class="count"><?= count($xp) ?></span></h2>
  <p class="hint" style="margin:0 0 8px;"><?= tt('fsr.ext_pending_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('fsr.no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('v.one') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('fsr.ext_engineers') ?></th><th><?= tt('fsr.location') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($xp as $x): ?><tr><td class="mono"><a href="<?= url('fsr', ['id' => $x['id']]) ?>"><?= e($x['report_no']) ?></a></td><td class="date"><?= e(fmt_date($x['report_date'])) ?></td><td><?= e($x['vessel'] ?: '') ?></td><td class="hint"><?= e(str_starts_with((string)$x['project_code'], 'GEN-') ? t('fsr.service_call') : trim($x['project_code'] . ' ' . $x['project_name'])) ?></td><td><?= e($x['ext_engineers'] ?: '') ?></td><td class="hint"><?= e($x['location'] ?: '') ?></td><td class="ob-act"><a class="btn btn--gold btn--sm" href="<?= url('fsr_edit', ['from_ext' => $x['id']]) ?>">⇄ <?= tt('fsr.convert') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="acc_reports">
    <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (['draft', 'issued', 'sent', 'returned', 'partly_invoiced', 'invoiced'] as $st): ?><option value="<?= $st ?>"<?= $f['status'] === $st ? ' selected' : '' ?>><?= tt('acc.rs_' . $st) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $f['vessel'] === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('pr.one') ?></label><select name="project"><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $f['project'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim($p['project_code'] . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
</div>

<div class="card">
  <h2><?= tt('acc.reports') ?> <span class="count"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><p class="hint"><?= tt('acc.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('acc.doc_no') ?></th><th><?= tt('acc.scope') ?></th><th><?= tt('acc.period') ?></th><th class="num"><?= tt('ts.hours') ?></th><th class="num"><?= tt('acc.total_net') ?></th><th><?= tt('th.status') ?></th><th><?= tt('acc.invoice') ?></th><th><?= tt('acc.prepared_by') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr><td class="mono"><a href="<?= url('acc_report', ['id' => $r['id']]) ?>"><b><?= e(str_starts_with($r['report_no'], 'DRAFT-') ? t('acc.rs_draft') : $r['report_no']) ?></b> <?= e($r['revision']) ?></a></td>
      <td><?= e($r['vessel'] ?: '') ?><?= $r['project_code'] ? '<br><span class="hint">' . e(trim($r['project_code'] . ' ' . $r['project_name'])) . '</span>' : '' ?><?= $r['sub_code'] ? '<br><span class="hint">' . e(trim($r['sub_code'] . ' ' . $r['sub_name'])) . '</span>' : '' ?></td>
      <td class="hint"><?= $r['period_start'] ? e(fmt_date($r['period_start'])) : '…' ?> – <?= $r['period_end'] ? e(fmt_date($r['period_end'])) : '…' ?></td>
      <td class="num"><?= e(acc_h((float)$r['total_hours'])) ?> <span class="hint">(<?= (int)$r['n_lines'] ?>)</span></td>
      <td class="num"><?= fin_visible() ? e(acc_m((float)$r['total_net'], $r['currency'])) : fin_masked() ?></td>
      <td><?= acc_status_pill($r['status']) ?></td>
      <td class="hint"><?= e($r['invoice_ref'] ?: '—') ?><?= $r['invoice_date'] ? '<br>' . e(fmt_date($r['invoice_date'])) : '' ?></td>
      <td class="hint"><?= e($r['created_name'] ?: '') ?><br><?= e(fmt_date(substr((string)$r['created_at'], 0, 10))) ?></td>
      <td class="ob-act"><a class="btn btn--ghost btn--sm" href="<?= url('acc_report', ['id' => $r['id']]) ?>"><?= tt('fsr.open') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($canNew): ?>
<div class="card" id="acc-new">
  <h2><?= tt('acc.new') ?></h2>
  <p class="hint"><?= tt('acc.new_hint') ?></p>
  <form method="post" action="<?= url('acc_reports') ?>" data-lock data-busy="<?= tt('acc.collecting') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_new">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('v.one') ?></label><select name="vessel_id" id="acc-v"><option value=""><?= tt('acc.any_vessel') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pr.one') ?> <span class="hint"><?= tt('acc.optional') ?></span></label><select name="project_id" id="acc-p"><option value=""><?= tt('acc.whole_vessel') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-v="<?= (int)$p['vessel_id'] ?>"><?= e(trim($p['project_code'] . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sp.one') ?> <span class="hint"><?= tt('acc.optional') ?></span></label><select name="subproject_id" id="acc-s"><option value=""><?= tt('fsr.whole_project') ?></option><?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"><?= e(trim($s['code'] . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('acc.from') ?></label><input type="date" name="from"></div>
      <div class="field"><label><?= tt('acc.to') ?></label><input type="date" name="to" value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label><?= tt('acc.zone') ?></label><select name="zone" data-nosearch><option value="local"><?= tt('acc.z_local') ?></option><option value="overseas"><?= tt('acc.z_overseas') ?></option></select></div>
    </div>
    <div class="field"><label><?= tt('acc.note') ?></label><input name="note" maxlength="1000"></div>
    <button class="btn btn--gold" type="submit"><?= tt('acc.create') ?></button>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var v=document.getElementById('acc-v'),p=document.getElementById('acc-p'),s=document.getElementById('acc-s');function fp(){var vid=v.value;Array.from(p.options).forEach(function(o){if(!o.value)return;o.hidden=!!vid&&o.dataset.v!==vid;if(o.hidden&&o.selected)p.value='';});fs();}function fs(){var pid=p.value;Array.from(s.options).forEach(function(o){if(!o.value)return;o.hidden=!pid||o.dataset.p!==pid;if(o.hidden&&o.selected)s.value='';});}v.addEventListener('change',fp);p.addEventListener('change',function(){if(p.value){var o=p.options[p.selectedIndex];if(o.dataset.v&&o.dataset.v!=='0')v.value=o.dataset.v;}fs();});fp();})();</script>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

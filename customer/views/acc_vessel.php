<?php
/** Commercial › Accounting › To invoice per vessel — everything still to be invoiced for one vessel: open hours, reports with accounting,
 *  contracts not fully invoiced (final invoices), proformas without an invoice and materials of service reports (GAS-CP-R97-035). ?p=acc_vessel[&vessel=N] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('acc_visible') || !acc_visible() || !function_exists('acc_vessel_due')) { http_response_code(403); layout_head(t('err.noaccess'), 'acc_reports'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$vid = (int)($_GET['vessel'] ?? 0); $all = is_cp_admin() || acc_is_accounting() || acc_user_flag('can_accounting') || fin_visible();
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
if ($vid && !in_array($vid, array_map('intval', array_column($vessels, 'id')), true)) $vid = 0;
$D = $vid ? acc_vessel_due($vid) : null; $money = fin_visible() || acc_is_accounting(); $m = fn(float $v, string $c = 'EUR') => $money ? number_format($v, 2, ',', '.') . ' ' . $c : '•••';
$canNew = acc_can_prepare(); $canInv = function_exists('pi_kind_has') && pi_kind_has() && pi_can_invoices();
layout_head(t('acc.vd_title') . ($D && $D['vessel'] ? ' · ' . $D['vessel']['name'] : ''), 'acc_reports');
?>
<p class="eyebrow"><a href="<?= url('acc_reports') ?>"><?= tt('acc.title') ?></a> › <?= tt('acc.vd_title') ?></p>
<div class="page-head"><div><h1>⚓ <?= tt('acc.vd_title') ?><?= $D && $D['vessel'] ? ' — ' . e($D['vessel']['name']) : '' ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('acc.vd_lede') ?></p></div>
  <?php if ($D && $D['vessel']): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('vessel', ['id' => $vid]) ?>">⚓ <?= tt('v.one') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('acc_reports', ['vessel' => $vid]) ?>">🧾 <?= tt('acc.vd_reports_all') ?></a></div><?php endif; ?></div>
<form method="get" action="index.php" class="filters" style="margin-bottom:12px;"><input type="hidden" name="p" value="acc_vessel">
  <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('acc.vd_pick') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $vid === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('acc.vd_show') ?></button></div>
</form>
<?php if (!$D || !$D['vessel']): ?><div class="card"><p class="hint"><?= tt('acc.vd_pick_hint') ?></p></div>
<?php else: $T = $D['tot']; $nothing = !$D['hours'] && !$D['reports'] && !$D['contracts'] && !$D['proformas'] && !$D['materials'] && !(function_exists('dni_visible') && dni_visible() && (dni_rows('open', $vid, 1) || dni_rows('invoice', $vid, 1))); ?>
<div class="kpi" style="margin-bottom:14px;">
  <a class="stat<?= $D['hours'] ? ' gold' : '' ?>" href="#vd-hours"><b><?= e(acc_h($T['hours'])) ?> h</b><?= tt('acc.vd_k_hours') ?></a>
  <a class="stat<?= $D['reports'] ? ' gold' : '' ?>" href="#vd-reports"><b><?= count($D['reports']) ?></b><?= tt('acc.vd_k_reports') ?><span class="s"><?= e($m($T['reports'])) ?></span></a>
  <a class="stat<?= $T['final'] > 0.005 ? ' red' : ($D['contracts'] ? ' gold' : '') ?>" href="#vd-contracts"><b><?= e($m($T['contracts'])) ?></b><?= tt('acc.vd_k_contracts') ?><?php if ($T['final'] > 0.005): ?><span class="s"><?= e(t('acc.vd_k_final', ['v' => $m($T['final'])])) ?></span><?php endif; ?></a>
  <a class="stat<?= $D['proformas'] ? ' gold' : '' ?>" href="#vd-proformas"><b><?= e($m($T['proformas'])) ?></b><?= tt('acc.vd_k_proformas') ?></a>
  <a class="stat<?= $D['materials'] ? ' gold' : '' ?>" href="#vd-materials"><b><?= (int)$T['materials'] ?></b><?= tt('acc.vd_k_materials') ?></a>
</div>
<?php if ($nothing): ?><div class="card"><p class="flash flash--info" style="margin:0;"><?= tt('acc.vd_nothing') ?></p></div><?php endif; ?>

<?php if ($D['contracts']): ?>
<div class="card" id="vd-contracts">
  <div class="card-head"><h2>📑 <?= tt('acc.vd_s_contracts') ?> <span class="count"><?= count($D['contracts']) ?></span></h2><span class="hint"><?= tt('acc.vd_h_contracts') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pr.one') ?></th><th><?= tt('sp.one') ?></th><th></th><th class="num"><?= tt('acc.vd_contract') ?></th><th class="num"><?= tt('acc.vd_invoiced') ?></th><th class="num"><?= tt('acc.vd_remaining') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($D['contracts'] as $c): ?><tr<?= $c['final'] ? ' class="is-late"' : '' ?>>
    <td><?= e($c['project']) ?></td>
    <td><?php if ($c['subproject_id']): ?><a href="<?= url('subproject', ['id' => $c['subproject_id']]) ?>"><?= e($c['sub']) ?></a><?php else: ?><span class="hint"><?= tt('fsr.whole_project') ?></span><?php endif; ?></td>
    <td><?= $c['final'] ? pill('bad', t('acc.vd_final')) : pill('gold', t('acc.vd_running')) ?><?= $c['n_pi'] ? ' <span class="hint">' . e(t('acc.vd_n_pi', ['n' => $c['n_pi']])) . '</span>' : '' ?></td>
    <td class="num"><?= e($m($c['contract'])) ?></td><td class="num"><?= e($m($c['invoiced'])) ?> <span class="hint"><?= (int)$c['pct'] ?> %</span></td><td class="num"><b><?= e($m($c['remaining'])) ?></b></td>
    <td class="ob-act"><?php if ($canInv): ?><a class="btn btn--ghost btn--sm" href="<?= url('invoice_new', array_filter(['project' => $c['project_id'], 'sub' => $c['subproject_id']])) ?>">🧾 <?= tt('acc.vd_invoice') ?></a><?php endif; ?></td>
  </tr><?php endforeach; ?>
  <tr><td colspan="5"><b><?= tt('acc.vd_total') ?></b></td><td class="num"><b><?= e($m($T['contracts'])) ?></b></td><td></td></tr>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php if ($D['proformas']): ?>
<div class="card" id="vd-proformas">
  <div class="card-head"><h2>🧾 <?= tt('acc.vd_s_proformas') ?> <span class="count"><?= count($D['proformas']) ?></span></h2><span class="hint"><?= tt('acc.vd_h_proformas') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('acc.vd_pi') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('acc.vd_issued') ?></th><th class="num"><?= tt('acc.vd_amount') ?></th><th class="num"><?= tt('acc.vd_received') ?></th><th class="num"><?= tt('acc.vd_to_invoice') ?></th></tr></thead><tbody>
  <?php foreach ($D['proformas'] as $r): $rest = (float)$r['gross'] - (float)$r['inv_sum']; ?><tr<?= (float)$r['adv_sum'] > 0.005 ? ' class="is-late"' : '' ?>>
    <td><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>"><b class="mono"><?= e($r['pi_no']) ?></b></a><br><span class="hint"><?= e((string)$r['title']) ?></span></td>
    <td class="hint"><?= e(trim((string)$r['project_code'] . ' ' . (string)$r['sub_code'])) ?></td><td class="date"><?= $r['issue_date'] ? e(fmt_date($r['issue_date'])) : '—' ?></td>
    <td class="num"><?= e($m((float)$r['gross'], (string)$r['currency'])) ?></td><td class="num"><?= (float)$r['adv_sum'] > 0.005 ? e($m((float)$r['adv_sum'], (string)$r['currency'])) : '<span class="hint">—</span>' ?></td><td class="num"><b><?= e($m($rest, (string)$r['currency'])) ?></b></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php if ($D['hours']): ?>
<div class="card" id="vd-hours">
  <div class="card-head"><h2>⏳ <?= tt('acc.vd_s_hours') ?> <span class="count"><?= count($D['hours']) ?></span></h2>
    <?php if ($canNew): ?><form method="post" action="<?= url('acc_reports') ?>" class="inline" data-lock data-busy="<?= tt('acc.collecting') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_new"><input type="hidden" name="vessel_id" value="<?= $vid ?>"><input type="hidden" name="to" value="<?= date('Y-m-d') ?>"><input type="hidden" name="zone" value="local"><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('acc.collect') ?></button></form><?php endif; ?></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('acc.vd_h_hours') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pr.one') ?></th><th><?= tt('sp.one') ?></th><th class="num"><?= tt('ts.hours') ?></th><th class="num"><?= tt('acc.lines') ?></th><th><?= tt('acc.sources') ?></th><th><?= tt('acc.oldest') ?></th><th><?= tt('acc.newest') ?></th></tr></thead><tbody>
  <?php foreach ($D['hours'] as $h): ?><tr><td><?= e(trim((string)$h['project_code'] . ' ' . (string)$h['project_name'])) ?></td><td><?= $h['subproject_id'] ? '<a href="' . url('subproject', ['id' => (int)$h['subproject_id']]) . '">' . e(trim((string)$h['sub_code'] . ' ' . (string)$h['sub_name'])) . '</a>' : '<span class="hint">—</span>' ?></td>
    <td class="num"><b><?= e(acc_h((float)$h['h'])) ?></b></td><td class="num"><?= (int)$h['n'] ?></td><td class="hint"><?= (int)$h['n_ts'] ? (int)$h['n_ts'] . ' TS' : '' ?><?= (int)$h['n_ts'] && (int)$h['n_fsr'] ? ' · ' : '' ?><?= (int)$h['n_fsr'] ? (int)$h['n_fsr'] . ' SR' : '' ?></td>
    <td class="date"><?= e(fmt_date($h['oldest'])) ?></td><td class="date"><?= e(fmt_date($h['newest'])) ?></td></tr><?php endforeach; ?>
  <tr><td colspan="2"><b><?= tt('acc.vd_total') ?></b></td><td class="num"><b><?= e(acc_h($T['hours'])) ?></b></td><td colspan="4"></td></tr>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php if ($D['reports']): ?>
<div class="card" id="vd-reports">
  <div class="card-head"><h2>📨 <?= tt('acc.vd_s_reports') ?> <span class="count"><?= count($D['reports']) ?></span></h2><span class="hint"><?= tt('acc.vd_h_reports') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('acc.vd_report') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('ts.hours') ?></th><th class="num"><?= tt('acc.vd_amount') ?></th><th><?= tt('acc.invoice_ref') ?></th></tr></thead><tbody>
  <?php foreach ($D['reports'] as $r): ?><tr><td><a href="<?= url('acc_report', ['id' => (int)$r['id']]) ?>"><b class="mono"><?= e($r['report_no'] . ' ' . $r['revision']) ?></b></a><br><span class="hint"><?= e(fmt_date($r['period_start'])) ?> – <?= e(fmt_date($r['period_end'])) ?></span></td>
    <td class="hint"><?= e(trim((string)$r['project_code'] . ' ' . (string)$r['project_name'])) ?></td><td><?= acc_status_pill((string)$r['status']) ?></td>
    <td class="num"><?= e(acc_h((float)$r['total_hours'])) ?></td><td class="num"><b><?= e($m((float)$r['total_net'], (string)$r['currency'])) ?></b></td><td class="hint"><?= e((string)$r['invoice_ref']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php $dnO = function_exists('dni_visible') && dni_visible() ? dni_rows('open', $vid) : []; $dnI = function_exists('dni_visible') && dni_visible() ? dni_rows('invoice', $vid) : []; if ($dnO || $dnI): ?>
<div class="card" id="vd-dn">
  <div class="card-head"><h2>🚚 <?= tt('dni.vd_title') ?> <span class="count"><?= count($dnO) + count($dnI) ?></span></h2><a class="btn btn--ghost btn--sm" href="<?= url('dn_invoicing', ['state' => $dnI ? 'invoice' : 'open', 'vessel' => $vid]) ?>"><?= tt('dni.title') ?> ›</a></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('dni.vd_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('dni.c_dn') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.code') ?></th><th><?= tt('dni.c_part') ?></th><th class="num"><?= tt('dni.c_qty') ?></th><th class="num"><?= tt('dni.c_total') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach (array_merge($dnI, $dnO) as $x): ?><tr><td><a href="<?= url('delivery_note', ['id' => (int)$x['dn_id']]) ?>"><span class="mono"><?= e($x['dn_no']) ?></span></a><br><span class="hint"><?= e(fmt_date($x['issued_at'])) ?></span></td><td class="hint"><?= e(trim((string)$x['project_code'] . ' ' . (string)$x['sub_code'])) ?></td><td class="mono"><?= e((string)$x['item_code']) ?></td><td><?= e((string)$x['description']) ?></td><td class="num"><?= e(rtrim(rtrim(number_format((float)$x['qty'], 3, ',', '.'), '0'), ',')) ?></td><td class="num"><?= $x['inv_price'] !== null ? e($m((float)$x['inv_price'] * (float)$x['qty'])) : '<span class="hint">—</span>' ?></td><td><?= dni_pill((string)$x['inv_state']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php if ($D['materials']): ?>
<div class="card" id="vd-materials">
  <div class="card-head"><h2>📦 <?= tt('acc.vd_s_materials') ?> <span class="count"><?= count($D['materials']) ?></span></h2><span class="hint"><?= tt('acc.vd_h_materials') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('acc.vd_sr') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.code') ?></th><th><?= tt('acc.vd_part') ?></th><th class="num"><?= tt('acc.vd_qty') ?></th></tr></thead><tbody>
  <?php foreach ($D['materials'] as $x): ?><tr><td><a href="<?= url('fsr', ['id' => $x['report_id']]) ?>"><span class="mono"><?= e($x['report_no']) ?></span></a><br><span class="hint"><?= e(fmt_date($x['date'])) ?></span></td><td class="hint"><?= e($x['project']) ?></td><td class="mono"><?= e($x['code']) ?><?= $x['stock'] ? ' 📦' : '' ?></td><td><?= e($x['descr']) ?></td><td class="num"><?= e(rtrim(rtrim(number_format($x['qty'], 2, ',', ''), '0'), ',')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<p class="hint"><?= tt('acc.vd_foot') ?></p>
<?php endif; ?>
<?php layout_foot();

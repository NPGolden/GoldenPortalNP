<?php
/** Cash flow — one project (?project=) or the company (filters), by month or week (GAS-CP-R45-001). ?p=cashflow[&project=][&g=m|w][&branch=][&vessel=][&pm=][&completed=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('cf_can') || !cf_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'cashflow'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('cf.noaccess_hint') . '</p>'; layout_foot(); return; }
$g = ($_GET['g'] ?? 'm') === 'w' ? 'w' : 'm'; $pid = (int)($_GET['project'] ?? 0);
$f = ['project' => $pid, 'branch' => (int)($_GET['branch'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'pm' => (int)($_GET['pm'] ?? 0), 'completed' => (int)($_GET['completed'] ?? 0)];
$p = $pid ? one('SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ?', [$pid]) : null;
if ($pid && !$p) { http_response_code(404); layout_head(t('err.notfound'), 'cashflow'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if ($p) $f['completed'] = 1;
$S = cf_series($f, $g); $S['g'] = $g; $T = $S['totals']; $D = $S['drivers']; $cur = 'EUR';
$keep = array_filter($f + ['g' => $g]); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$pms = all("SELECT DISTINCT cu.id, cu.full_name FROM projects p JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name"); [$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
$fs = $p && function_exists('fs_totals') ? fs_totals($pid) : null;
layout_head(t('cf.title'), $p ? 'projects' : 'cashflow');
?>
<?php if ($p): ?><p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('cf.title') ?></p><?php endif; ?>
<div class="page-head">
  <div><h1>💰 <?= tt('cf.title') ?><?= $p ? ' — ' . e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) : '' ?></h1><p class="lede" style="margin-bottom:0;"><?= $p ? e($p['vessel'] ?: '') . ($p['project_manager'] ? ' · PM ' . e($p['project_manager']) : '') : tt('cf.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('report_cf', $keep) ?>" target="_blank">⤓ PDF</a><a class="btn btn--ghost btn--sm" href="<?= url('report_cf', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a><?php if (!$p): ?><a class="btn btn--ghost btn--sm" href="<?= url('proformas') ?>">💶 <?= tt('pi.title') ?></a><?php else: ?><a class="btn btn--ghost btn--sm" href="<?= url('cashflow', ['g' => $g]) ?>"><?= tt('cf.company') ?> ›</a><?php endif; ?></div>
</div>
<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: auto <?= $p ? '' : '1fr 1fr 1fr auto' ?> auto;">
    <input type="hidden" name="p" value="cashflow"><?php if ($p): ?><input type="hidden" name="project" value="<?= $pid ?>"><?php endif; ?>
    <div class="field"><label><?= tt('cf.granularity') ?></label><select name="g" data-nosearch><option value="m"<?= $sel('m', $g) ?>><?= tt('cf.by_month') ?></option><option value="w"<?= $sel('w', $g) ?>><?= tt('cf.by_week') ?></option></select></div>
    <?php if (!$p): ?>
    <?php if (br_has() && br_multi()): ?><div class="field"><label><?= tt('br.one') ?></label><select name="branch" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (br_all(true) as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $sel($b['id'], $f['branch']) ?>><?= e($b['code'] . ' · ' . $b['name']) ?></option><?php endforeach; ?></select></div><?php else: ?><div></div><?php endif; ?>
    <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label class="chk" style="margin-top:22px;"><input type="checkbox" name="completed" value="1"<?= $f['completed'] ? ' checked' : '' ?>> <?= tt('cf.incl_completed') ?></label></div>
    <?php endif; ?>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
</div>
<div class="kpi kpi--4">
  <span class="stat green"><b><?= e(cf_money($T['in_a'] ?? 0)) ?></b><span class="s"><?= tt('cf.in_actual') ?></span></span>
  <span class="stat"><b><?= e(cf_money($T['in_f'] ?? 0)) ?></b><span class="s"><?= tt('cf.in_forecast') ?></span></span>
  <span class="stat<?= ($T['out_a'] ?? 0) > ($T['in_a'] ?? 0) ? ' red' : '' ?>"><b><?= e(cf_money($T['out_a'] ?? 0)) ?></b><span class="s"><?= tt('cf.out_actual') ?></span></span>
  <span class="stat gold"><b><?= e(cf_money($T['out_f'] ?? 0)) ?></b><span class="s"><?= tt('cf.out_forecast') ?></span></span>
</div>
<?php if ($fs && ($fs['offered'] || $fs['invoiced'])): ?><p class="hint" style="margin:-6px 0 12px;"><?= tt('fs.title') ?>: <?= fs_pills($pid) ?><?= $fs['outstanding'] > 0 ? ' · ' . tt('fs.outstanding') . ' ' . e(pi_money($fs['outstanding'], $fs['cur'])) : '' ?></p><?php endif; ?>
<div class="card">
  <div class="card-head"><h2><?= tt('cf.chart') ?></h2><span class="hint"><?= e(t('cf.range', ['f' => fmt_date($S['from']), 't' => fmt_date($S['to'])])) ?> · <?= e(t('cf.n_projects', ['n' => (int)$S['n_projects']])) ?></span></div>
  <div class="cf-chart-wrap"><?= cf_chart($S) ?></div>
  <p class="hint" style="margin:6px 0 0;"><?= tt('cf.legend') ?></p>
</div>
<div class="card">
  <h2><?= tt('cf.table') ?></h2>
  <div class="table-scroll"><table class="tbl cf-tbl"><thead><tr><th><?= tt('cf.period') ?></th><th class="num"><?= tt('cf.in_actual') ?></th><th class="num"><?= tt('cf.in_forecast') ?></th><th class="num"><?= tt('cf.out_actual') ?></th><th class="num"><?= tt('cf.out_forecast') ?></th><th class="num"><?= tt('cf.net') ?></th><th class="num"><?= tt('cf.cum') ?></th><th><?= tt('cf.drivers') ?></th></tr></thead><tbody>
  <?php foreach ($S['rows'] as $k => $r): $top = $r['items']; usort($top, fn($a, $b) => abs($b[1]) <=> abs($a[1])); $top = array_slice($top, 0, 4); ?>
    <tr class="<?= $k === $S['now'] ? 'cf-now' : ($r['past'] ? 'is-muted' : '') ?>"><td><b><?= e(cf_label($k, $g)) ?></b><?= $k === $S['now'] ? ' ' . pill('gold', t('cf.now')) : '' ?></td>
      <td class="num"><?= $r['in_a'] ? e(cf_money($r['in_a'])) : '—' ?></td><td class="num hint"><?= $r['in_f'] ? e(cf_money($r['in_f'])) : '—' ?></td>
      <td class="num"><?= $r['out_a'] ? e(cf_money($r['out_a'])) : '—' ?></td><td class="num hint"><?= $r['out_f'] ? e(cf_money($r['out_f'])) : '—' ?></td>
      <td class="num <?= $r['net'] < 0 ? 'is-late' : '' ?>"><b><?= e(($r['net'] < 0 ? '−' : '+') . cf_money(abs($r['net']))) ?></b></td><td class="num <?= $r['cum'] < 0 ? 'is-late' : '' ?>"><?= e(($r['cum'] < 0 ? '−' : '') . cf_money(abs($r['cum']))) ?></td>
      <td class="hint cf-drv"><?php foreach ($top as [$c, $a, $w]): ?><span class="<?= str_starts_with($c, 'in') ? 'cf-in' : 'cf-out' ?>"><?= str_starts_with($c, 'in') ? '+' : '−' ?><?= e(cf_money(abs($a))) ?> <?= e(mb_substr($w, 0, 40)) ?></span><?php endforeach; ?></td></tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr><td><b><?= tt('ts.total') ?></b></td><td class="num"><b><?= e(cf_money($T['in_a'] ?? 0)) ?></b></td><td class="num"><b><?= e(cf_money($T['in_f'] ?? 0)) ?></b></td><td class="num"><b><?= e(cf_money($T['out_a'] ?? 0)) ?></b></td><td class="num"><b><?= e(cf_money($T['out_f'] ?? 0)) ?></b></td><?php $net = ($T['in_a'] ?? 0) + ($T['in_f'] ?? 0) - ($T['out_a'] ?? 0) - ($T['out_f'] ?? 0); ?><td class="num <?= $net < 0 ? 'is-late' : '' ?>"><b><?= e(($net < 0 ? '−' : '+') . cf_money(abs($net))) ?></b></td><td colspan="2"></td></tr></tfoot></table></div>
</div>
<div class="card">
  <h2><?= tt('cf.sources') ?></h2>
  <div class="grid grid-2">
    <table class="kv"><tbody>
      <tr><th><?= tt('cf.s_paid') ?></th><td class="num"><?= e(cf_money($D['in_paid'])) ?></td></tr>
      <tr><th><?= tt('cf.s_pi') ?></th><td class="num"><?= e(cf_money($D['in_pi'])) ?></td></tr>
      <tr><th><?= tt('cf.s_terms') ?></th><td class="num"><?= e(cf_money($D['in_terms'])) ?></td></tr>
    </tbody></table>
    <table class="kv"><tbody>
      <tr><th><?= tt('cf.s_hours') ?></th><td class="num"><?= e(cf_money($D['out_hours'])) ?></td></tr>
      <tr><th><?= tt('cf.s_parts_inv') ?></th><td class="num"><?= e(cf_money($D['out_parts_inv'])) ?></td></tr>
      <tr><th><?= tt('cf.s_parts_ord') ?></th><td class="num"><?= e(cf_money($D['out_parts_ord'])) ?></td></tr>
      <tr><th><?= tt('cf.s_subs_del') ?></th><td class="num"><?= e(cf_money($D['out_subs_del'])) ?></td></tr>
      <tr><th><?= tt('cf.s_subs_due') ?></th><td class="num"><?= e(cf_money($D['out_subs_due'])) ?></td></tr>
      <tr><th><?= tt('cf.s_remaining') ?></th><td class="num"><?= e(cf_money($D['out_remaining'])) ?></td></tr>
    </tbody></table>
  </div>
  <p class="hint"><?= tt('cf.rules') ?></p>
</div>
<?php layout_foot(); ?>

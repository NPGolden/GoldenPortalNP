<?php
/** Work › KPIs — the project-management KPIs per project and sub-project, period, trends, PDF / Excel (GAS-CP-R39-001). ?p=kpis[&period=m|last|q|ytd|12][&project=][&pm=][&vessel=][&status=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('kpi_can') || !kpi_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'kpis'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
kpi_snapshot_monthly();
$per = in_array($_GET['period'] ?? 'm', ['m', 'last', 'q', 'ytd', '12'], true) ? (string)($_GET['period'] ?? 'm') : 'm'; [$from, $to] = kpi_period($per);
$f = ['pm' => (int)($_GET['pm'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'status' => in_array($_GET['status'] ?? '', pm_statuses(), true) ? $_GET['status'] : ''];
$one = (int)($_GET['project'] ?? 0);
$projects = $one ? array_values(array_filter([kpi_project($one, $from, $to)])) : kpi_projects($from, $to, $f);
$fin = fin_visible(); $T = kpi_targets();
$keys = array_values(array_filter(KPI_KEYS, fn($k) => $fin || !in_array($k, ['bai_b', 'bai_o', 'roi'], true)));
$pms = all("SELECT DISTINCT cu.id, cu.full_name FROM projects p JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name");
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
$keep = array_filter(['period' => $per, 'project' => $one] + $f);
$tot = ['red' => 0, 'amber' => 0, 'green' => 0]; foreach ($projects as $P) $tot[$P['worst']]++;
layout_head(t('kpi.title'), 'kpis');
?>
<div class="page-head"><div><h1>🎯 <?= tt('kpi.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('kpi.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('report_kpi', $keep) ?>" target="_blank">⤓ PDF</a><a class="btn btn--ghost btn--sm" href="<?= url('report_kpi', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a><?php if (is_cp_admin()): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_kpis') ?>">⚙ <?= tt('kpi.settings') ?></a><?php endif; ?></div></div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="kpis">
    <div class="field"><label><?= tt('acc.period') ?></label><select name="period" data-nosearch><?php foreach (['m' => t('kpi.p_m'), 'last' => t('kpi.p_last'), 'q' => t('kpi.p_q'), 'ytd' => t('kpi.p_ytd'), '12' => t('kpi.p_12')] as $k => $l): ?><option value="<?= $k ?>"<?= $per === (string)$k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $pp): ?><option value="<?= $pp['id'] ?>"<?= $f['pm'] === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $f['vessel'] === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('kpi.active_or_period') ?></option><?php foreach (pm_statuses() as $st): ?><option value="<?= $st ?>"<?= $f['status'] === $st ? ' selected' : '' ?>><?= tt('pr.' . $st) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('kpis') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="kpi" style="margin-bottom:14px;">
  <span class="stat"><b><?= count($projects) ?></b><?= tt('pr.title') ?></span>
  <span class="stat<?= $tot['red'] ? ' red' : '' ?>"><b><?= $tot['red'] ?></b><?= tt('kpi.proj_red') ?></span>
  <span class="stat gold"><b><?= $tot['amber'] ?></b><?= tt('kpi.proj_amber') ?></span>
  <span class="stat green"><b><?= $tot['green'] ?></b><?= tt('kpi.proj_green') ?></span>
  <span class="stat"><b><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?></b><?= tt('acc.period') ?></span>
</div>

<div class="card">
  <div class="card-head"><h2><?= tt('kpi.by_project') ?></h2><span class="hint"><?= tt('kpi.legend') ?></span></div>
  <?php if (!$projects): ?><p class="hint"><?= tt('kpi.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl kpi-tbl"><thead><tr><th><?= tt('pr.one') ?></th><?php foreach ($keys as $k): ?><th class="num" title="<?= e(t('kpi.h_' . $k)) ?>"><?= e(kpi_label($k)) ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
  <?php foreach ($projects as $P): ?>
    <tr class="kpi-row kpi-row--<?= $P['worst'] ?>"><td><a href="<?= url('kpis', ['project' => $P['pid'], 'period' => $per]) ?>"><b><?= e($P['p']['project_code'] ?: $P['p']['name']) ?></b></a><br><span class="hint"><?= e($P['p']['vessel'] ?: '') ?><?= $P['p']['project_manager'] ? ' · ' . e($P['p']['project_manager']) : '' ?></span></td>
      <?php foreach ($keys as $k): ?><td class="num"><?= kpi_pill($k, $P['v'][$k]) ?><?= kpi_spark(kpi_trend($P['pid'], $k), $k) ?></td><?php endforeach; ?>
      <td class="ob-act"><a class="btn btn--ghost btn--sm" href="<?= url('projects', ['open' => $P['pid']]) ?>#pr<?= $P['pid'] ?>"><?= tt('fsr.open') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($one && $projects): $P = $projects[0]; ?>
<div class="card">
  <h2><?= e(trim(($P['p']['project_code'] ?: '') . ' ' . $P['p']['name'])) ?> — <?= tt('kpi.by_sub') ?></h2>
  <p class="hint" style="margin:0 0 8px;"><?= e(t('kpi.pcr_line', ['a' => $P['detail']['pcr']['active'], 'd' => $P['detail']['pcr']['done'], 'o' => $P['detail']['pcr']['on_time']])) ?> · <?= e(t('kpi.psc_line', ['r' => $P['detail']['psc']['revisions'], 'd' => $P['detail']['psc']['resubmitted']])) ?> · <?= e(t('kpi.rmi_line', ['m' => $P['detail']['risks']['m'], 'n' => $P['detail']['risks']['n']])) ?> <a href="<?= url('risks', ['project' => $P['pid']]) ?>"><?= tt('rk.title') ?> ›</a></p>
  <div class="table-scroll"><table class="tbl kpi-tbl"><thead><tr><th><?= tt('sp.one') ?></th><th><?= tt('th.status') ?></th><?php foreach ($keys as $k): ?><th class="num"><?= e(kpi_label($k)) ?></th><?php endforeach; ?><th class="num"><?= tt('kpi.hours_bea') ?></th></tr></thead><tbody>
  <?php foreach ($P['subs'] as $K): ?>
    <tr><td><a href="<?= url('subproject', ['id' => $K['sid'], 'tab' => 'budget']) ?>"><b><?= e(trim($K['sub']['code'] . ' ' . $K['sub']['name'])) ?></b></a><?= $K['sub']['deadline'] ? '<br><span class="hint">' . tt('wv.deadline') . ' ' . e(fmt_date($K['sub']['deadline'])) . ($K['sub']['completed_at'] ? ' · ' . tt('pr.completed') . ' ' . e(fmt_date($K['sub']['completed_at'])) : '') . '</span>' : '' ?></td><td><?= pill($K['sub']['status'] === 'completed' ? 'green' : ($K['sub']['status'] === 'onhold' ? 'gold' : ($K['sub']['status'] === 'quoted' ? 'mute' : 'navy')), t('pr.' . $K['sub']['status'])) ?></td>
      <?php foreach ($keys as $k): ?><td class="num"><?= kpi_pill($k, $K['v'][$k]) ?></td><?php endforeach; ?>
      <td class="num hint"><?= e(bg_h_safe((float)($K['detail']['budget_h'] ?? 0))) ?> / <?= e(bg_h_safe((float)($K['detail']['earned_h'] ?? 0))) ?> / <?= e(bg_h_safe((float)($K['detail']['actual_h'] ?? 0))) ?><?= isset($K['detail']['planned_h']) ? '<br>' . tt('kpi.planned_h') . ' ' . e(bg_h_safe((float)$K['detail']['planned_h'])) : '' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<div class="card">
  <h2><?= tt('kpi.trend') ?> <span class="hint"><?= tt('kpi.trend_hint') ?></span></h2>
  <div class="kpi-trends"><?php foreach ($keys as $k): $tr = kpi_trend($P['pid'], $k); ?><div class="kpi-trend"><b><?= e(kpi_label($k)) ?></b> <?= kpi_pill($k, $P['v'][$k]) ?><br><?= kpi_spark($tr, $k) ?: '<span class="hint">' . tt('kpi.no_trend') . '</span>' ?><?php if ($tr): ?><br><span class="hint"><?php foreach (array_reverse(array_slice($tr, 0, 6)) as $r): ?><?= e(date('M y', strtotime($r['snap_date']))) ?> <?= e(kpi_fmt($k, $r['value'] !== null ? (float)$r['value'] : null)) ?> · <?php endforeach; ?></span><?php endif; ?></div><?php endforeach; ?></div>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('kpi.definitions') ?></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th>KPI</th><th><?= tt('kpi.formula') ?></th><th class="num"><?= tt('kpi.target') ?></th><th class="num"><?= tt('kpi.tolerance') ?></th><th><?= tt('kpi.reading') ?></th></tr></thead><tbody>
  <?php foreach (KPI_KEYS as $k): ?><tr><td><b><?= e(kpi_label($k)) ?></b></td><td class="hint"><?= e(t('kpi.f_' . $k)) ?></td><td class="num"><?= e(kpi_fmt($k, (float)$T[$k]['target'])) ?></td><td class="num"><?= e(kpi_fmt($k === 'roi' ? 'roi' : ($k === 'psc' ? 'psc' : 'cpi'), (float)$T[$k]['tolerance'])) ?></td><td class="hint"><?= e(t('kpi.r_' . $k)) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php layout_foot(); ?>

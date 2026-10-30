<?php
/** Fleet › Equipment › Production — every production order in progress, for project and production managers (GAS-CP-R41-001).
 *   ?p=production[&status=open|closed|<status>][&pm=][&manager=][&vessel=][&q=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('pd_has') || !pd_has()) { layout_head(t('pd.title'), 'equipment'); echo '<div class="flash flash--warn">' . tt('pd.need_db') . '</div>'; layout_foot(); return; }
$view = ($_GET['view'] ?? '') === 'sub' && function_exists('ps_has') && ps_has() ? 'sub' : 'orders';
$f = ['status' => (string)($_GET['status'] ?? 'open'), 'pm' => (int)($_GET['pm'] ?? 0), 'manager' => (int)($_GET['manager'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = pd_list($f); $keep = array_filter($f); $counts = pd_counts();
$open = 0; foreach (PD_OPEN as $st) $open += $counts[$st] ?? 0;
$pms = all("SELECT DISTINCT cu.id, cu.full_name FROM production_orders o JOIN projects p ON p.id = o.project_id JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name");
$mgrs = all("SELECT DISTINCT cu.id, cu.full_name FROM production_orders o JOIN customer_users cu ON cu.id = o.manager_user_id ORDER BY cu.full_name");
$vessels = all("SELECT DISTINCT v.id, v.name FROM production_orders o JOIN vessels v ON v.id = o.vessel_id ORDER BY v.name");
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('pd.title'), 'equipment');
?>
<div class="page-head">
  <div><h1>🏭 <?= tt('pd.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pd.lede') ?></p></div>
  <div class="btn-row"><?php if (pd_can_edit()): ?><a class="btn btn--gold" href="<?= url('production_edit') ?>">+ <?= tt('pd.new') ?></a><?php endif; ?></div>
</div>
<nav class="tabs" aria-label="<?= tt('eq.title') ?>"><a href="<?= url('equipment') ?>">🔌 <?= tt('eq.register') ?></a><a class="<?= $view === 'orders' ? 'is-on' : '' ?>" href="<?= url('production') ?>">🏭 <?= tt('pd.title') ?> <span class="pill pill--gold"><?= $open ?></span></a><?php if (function_exists('ps_has') && ps_has()): $psc = ps_counts(); $psOpen = array_sum($psc) - (int)($psc['accepted'] ?? 0) - (int)($psc['cancelled'] ?? 0); ?><a class="<?= $view === 'sub' ? 'is-on' : '' ?>" href="<?= url('production', ['view' => 'sub']) ?>">🔗 <?= tt('ps.title') ?> <span class="pill pill--gold"><?= $psOpen ?></span></a><?php endif; ?></nav>
<?php if ($view === 'sub'): require __DIR__ . '/_production_sub.php'; layout_foot(); return; endif; ?>
<div class="kpi kpi--4">
  <a class="stat" href="<?= url('production', ['status' => 'open']) ?>"><b><?= $open ?></b><span class="s"><?= tt('pd.k_open') ?></span></a>
  <a class="stat gold" href="<?= url('production', ['status' => 'production']) ?>"><b><?= (int)($counts['production'] ?? 0) ?></b><span class="s"><?= tt('pd.s_production') ?></span></a>
  <a class="stat" href="<?= url('production', ['status' => 'fat']) ?>"><b><?= (int)($counts['fat'] ?? 0) + (int)($counts['hat'] ?? 0) + (int)($counts['sat'] ?? 0) ?></b><span class="s"><?= tt('pd.k_tests') ?></span></a>
  <a class="stat green" href="<?= url('production', ['status' => 'closed']) ?>"><b><?= (int)($counts['closed'] ?? 0) ?></b><span class="s"><?= tt('pd.s_closed') ?></span></a>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="production">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('pd.search_ph') ?>"></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= $sel('open', $f['status']) ?>><?= tt('pd.k_open') ?></option><?php foreach (PD_STATUSES as $st): ?><option value="<?= $st ?>"<?= $sel($st, $f['status']) ?>><?= e(pd_status_label($st)) ?></option><?php endforeach; ?><option value="all"<?= $sel('all', $f['status']) ?>><?= tt('flt.all') ?></option></select></div>
  <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pd.manager') ?></label><select name="manager"><option value=""><?= tt('flt.any') ?></option><?php foreach ($mgrs as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['manager']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['vessel']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('production') ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <?php if (!$rows): ?><p class="hint"><?= tt('pd.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl pd-tbl"><thead><tr><th><?= tt('pd.no') ?></th><th><?= tt('pd.order') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('pd.units') ?></th><?php if ($psOk = function_exists('ps_has') && ps_has()): ?><th><?= tt('ps.short') ?></th><?php endif; ?><th><?= tt('pd.manager') ?></th><th><?= tt('pd.planned') ?></th><th><?= tt('th.status') ?></th><th class="num">%</th><th><?= tt('pd.last') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $o): $late = $o['planned_end'] && in_array($o['status'], PD_OPEN, true) && $o['planned_end'] < date('Y-m-d'); ?>
    <tr class="<?= in_array($o['status'], ['closed', 'cancelled'], true) ? 'is-muted' : '' ?>">
      <td class="mono"><a href="<?= url('production_order', ['id' => (int)$o['id']]) ?>"><b><?= e($o['po_no']) ?></b></a></td>
      <td><a href="<?= url('production_order', ['id' => (int)$o['id']]) ?>"><?= e($o['title']) ?></a><?= $o['system_name'] ? '<br><span class="hint">' . e($o['system_name']) . '</span>' : '' ?><?= $o['wp_no'] ? ' <span class="hint">· ' . e($o['wp_no']) . '</span>' : '' ?></td>
      <td><?= e($o['vessel'] ?: '—') ?></td>
      <td><?= pr_link((int)$o['project_id'], $o['project_code'], $o['project_name']) ?><?= $o['sub_code'] ? '<br><span class="hint">' . e($o['sub_code']) . '</span>' : '' ?><?= $o['project_manager'] ? '<br><span class="hint">PM ' . e($o['project_manager']) . '</span>' : '' ?></td>
      <td class="num"><?= (int)$o['n_units'] ?><?= (int)$o['n_units'] ? ' <span class="hint">(' . (int)$o['n_accepted'] . ' ✓)</span>' : '' ?></td>
      <?php if ($psOk): $sx = all("SELECT supplier, status, COUNT(*) n FROM production_subs WHERE order_id = ? GROUP BY supplier, status", [(int)$o['id']]); ?><td class="hint"><?php if (!$sx) echo '—'; else foreach ($sx as $x) echo '<span class="pill pill--' . (PS_TONE[$x['status']] ?? 'mute') . '">' . e($x['supplier']) . ' · ' . e(t('ps.s_' . $x['status'])) . ((int)$x['n'] > 1 ? ' ×' . (int)$x['n'] : '') . '</span> '; ?></td><?php endif; ?>
      <td><?= e($o['manager'] ?: '—') ?></td>
      <td class="hint<?= $late ? ' is-late' : '' ?>"><?= $o['planned_start'] ? e(fmt_date($o['planned_start'])) : '…' ?> – <?= $o['planned_end'] ? e(fmt_date($o['planned_end'])) : '…' ?><?= $late ? ' ' . pill('bad', t('pd.late')) : '' ?></td>
      <td><?= pd_status_pill($o['status']) ?></td>
      <td class="num"><b><?= (int)$o['pct'] ?> %</b><?= pd_pct_bar((int)$o['pct']) ?></td>
      <td class="hint"><?= $o['last_event'] ? e(fmt_datetime($o['last_event'])) : '—' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

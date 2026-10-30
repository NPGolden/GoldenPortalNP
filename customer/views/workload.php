<?php
/** Work › Workload — capacity vs planned load per discipline and engineer, open packages, next free window (GAS-CP-R35-001). ?p=workload[&discipline=&weeks=&from=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!wp_has() || !(wp_can_manage(0) || (function_exists('bg_can') && bg_can()))) { http_response_code(403); layout_head(t('err.noaccess'), 'workload'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$nW = max(2, min(12, (int)($_GET['weeks'] ?? 6)));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('monday this week'));
$from = date('Y-m-d', strtotime('monday this week', strtotime($from))); $to = date('Y-m-d', strtotime($from . ' +' . ($nW * 7 - 1) . ' days'));
$did = isset($_GET['discipline']) && $_GET['discipline'] !== '' ? (int)$_GET['discipline'] : null;
$disc = all('SELECT * FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name');
$W = wp_workload($from, $to, $did); $people = $W['people']; $weeks = $W['weeks'];
$groups = []; foreach ($people as $R) $groups[$R['discipline'] ?: t('bg.no_discipline')][] = $R;
$tone = fn(int $p) => $p > 100 ? 'red' : ($p >= 85 ? 'amber' : ($p < 40 ? 'grey' : 'green'));
layout_head(t('wp.workload'), 'workload');
?>
<div class="page-head"><div><h1><?= tt('wp.workload') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('wp.workload_lede') ?></p></div></div>
<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.2fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="workload">
    <div class="field"><label><?= tt('bg.discipline') ?></label><select name="discipline" data-nosearch><option value=""><?= tt('wp.all_disciplines') ?></option><?php foreach ($disc as $d): ?><option value="<?= $d['id'] ?>"<?= $did === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?><option value="0"<?= $did === 0 ? ' selected' : '' ?>><?= tt('bg.no_discipline') ?></option></select></div>
    <div class="field"><label><?= tt('wp.from_week') ?></label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label><?= tt('wp.weeks') ?></label><select name="weeks" data-nosearch><?php foreach ([4, 6, 8, 12] as $n): ?><option value="<?= $n ?>"<?= $nW === $n ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
</div>

<?php foreach ($groups as $gname => $rows): $gc = array_sum(array_column($rows, 'cap')); $gl = array_sum(array_column($rows, 'load')); $gp = $gc > 0 ? (int) round($gl / $gc * 100) : 0; ?>
<div class="card">
  <div class="card-head"><h2><?= e($gname) ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= tt('wp.capacity') ?> <b><?= e(bg_h_safe($gc)) ?> h</b> · <?= tt('wp.planned') ?> <b><?= e(bg_h_safe($gl)) ?> h</b> · <b class="bg-<?= $tone($gp) ?>"><?= $gp ?> %</b></span></div>
  <div class="table-scroll"><table class="tbl wl-tbl"><thead><tr><th><?= tt('wv.by') ?></th><th class="num"><?= tt('wp.load') ?></th><?php foreach ($weeks as $wk): ?><th class="wl-w"><?= e(substr($wk, 5)) ?></th><?php endforeach; ?><th><?= tt('wp.projects') ?></th><th class="num"><?= tt('wp.open_works') ?></th><th><?= tt('wp.next_free') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $R): ?>
    <tr>
      <td><b><?= e($R['name']) ?></b><br><a class="hint" href="<?= url('planning', ['week' => $from]) ?>#u<?= $R['id'] ?>"><?= tt('pl.title') ?> ›</a></td>
      <td class="num"><b class="bg-<?= $tone($R['pct']) ?>"><?= $R['pct'] ?> %</b><br><span class="hint"><?= e(bg_h_safe($R['load'])) ?> / <?= e(bg_h_safe($R['cap'])) ?> h</span></td>
      <?php foreach ($weeks as $wk): $x = $R['weeks'][$wk]; $p = $x['cap'] > 0 ? (int) round($x['load'] / $x['cap'] * 100) : 0; $o = $x['cap'] > 0 ? (int) round($x['off'] / $x['cap'] * 100) : 0; ?>
        <td class="wl-w"><div class="wl-cell" title="<?= e(bg_h_safe($x['load'])) ?> / <?= e(bg_h_safe($x['cap'])) ?> h<?= $x['off'] > 0 ? ' · ' . tt('wp.off') . ' ' . e(bg_h_safe($x['off'])) . ' h' : '' ?>"><div class="wl-bar"><div class="wl-fill wl-<?= $tone($p) ?>" style="height:<?= min(100, $p) ?>%;"></div><?php if ($o > 0): ?><div class="wl-off" style="height:<?= min(100, $o) ?>%;"></div><?php endif; ?></div><span><?= $x['cap'] > 0 ? $p . '%' : '—' ?></span></div></td>
      <?php endforeach; ?>
      <td><?php foreach (array_slice($R['projects'], 0, 5, true) as $pr): ?><a class="wl-chip" href="<?= $pr['subproject_id'] ? url('subproject', ['id' => $pr['subproject_id'], 'tab' => 'works']) : url('projects', ['open' => $pr['project_id']]) . '#pr' . $pr['project_id'] ?>" title="<?= e($pr['title']) ?>"><?= e($pr['label']) ?> <small><?= (int)$pr['n'] ?>d</small></a> <?php endforeach; ?><?php if (count($R['projects']) > 5): ?><span class="hint">+<?= count($R['projects']) - 5 ?></span><?php endif; ?></td>
      <td class="num"><?= (int)$R['open_works'] ?><br><span class="hint"><?= e(bg_h_safe($R['open_hours'])) ?> h <?= tt('wp.remaining_l') ?></span></td>
      <td><?= $R['next_free'] ? '<b>' . e(fmt_date($R['next_free'])) . '</b>' : '<span class="bg-neg">' . tt('wp.no_free') . '</span>' ?><br><span class="hint"><?= e(t('wp.free_days', ['n' => $R['free_days']])) ?></span></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endforeach; ?>
<?php if (!$groups): ?><div class="card"><p class="hint"><?= tt('wp.nobody_board') ?></p></div><?php endif; ?>
<p class="hint"><?= tt('wp.workload_hint') ?></p>
<?php layout_foot(); ?>

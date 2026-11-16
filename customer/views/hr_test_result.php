<?php
/** Assessment result of one person — for HR and the CEO, and for the person after the campaign has closed.   GAS-CP-R97-063   ?p=hr_test_result&id=&pid= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int) ($_GET['id'] ?? 0); $pid = (int) ($_GET['pid'] ?? 0);
$c = function_exists('hrs_has') && hrs_has() ? hrs_campaign($id) : null; $t = $c ? hrs_target($id, $pid) : null; $isHr = function_exists('hrs_can') && hrs_can();
if (!$c || !$t || !hrs_may_see($c, $t)) { http_response_code(403); layout_head(t('hrs.title'), $isHr ? 'hr_tests' : 'my_leave'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$F = $c['F']; $p = hr_person($pid); $sc = json_decode((string) $t['scores'], true) ?: []; $fl = json_decode((string) $t['flags'], true) ?: []; $own = (int) $t['user_id'] === (int) $u['id'];
if (!$own && empty($_SESSION['hrs_seen'][$id . ':' . $pid])) { $_SESSION['hrs_seen'][$id . ':' . $pid] = 1; if (function_exists('hr_audit')) hr_audit($pid, 'assessment', mb_substr((string) $c['title'], 0, 120), null, null, t('hrs.au_viewed')); }
$hist = $c['status'] === 'closed' ? hrs_history($pid, (string) $c['family_code']) : []; $fh = fn($v) => $v === null ? '—' : (string) (int) $v;
layout_head(t('hrs.result') . ' · ' . ($p['name_en'] ?? ''), $isHr ? 'hr_tests' : 'my_leave');
?>
<p class="eyebrow"><?php if ($isHr): ?><a href="<?= url('hr_tests') ?>"><?= tt('hrs.title') ?></a> › <a href="<?= url('hr_test', ['id' => $id]) ?>"><?= e(hrs_tx($F['title'])) ?></a><?php else: ?><a href="<?= url('my_leave') ?>">‹ <?= tt('hr.my_leave') ?></a><?php endif; ?></p>
<div class="page-head"><div><h1>🧭 <?= e($p['name_en'] ?? '') ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= e(hrs_tx($F['title'])) ?> · <?= $c['starts_on'] ? e(fmt_date($c['starts_on'])) . ' – ' . e(fmt_date($c['ends_on'])) : '' ?> <?= hrs_status_pill($c['status']) ?><?= !$t['valid'] ? ' ' . pill('warn', '⚠ ' . t('hrs.low_conf')) : '' ?></p></div></div>

<?php if (!$t['valid']): ?><div class="flash flash--warn"><?= tt($own ? 'hrs.low_conf_own' : 'hrs.low_conf_hr') ?><?php if ($isHr): ?> <span class="hint" style="display:inline;">— <?= e(implode(' · ', array_filter([($fl['contra'] ?? 0) >= 2 ? t('hrs.fl_contra', ['n' => (int) $fl['contra']]) : '', !empty($fl['fast']) ? t('hrs.fl_fast') : '', !empty($fl['straight']) ? t('hrs.fl_straight') : '']))) ?></span><?php endif; ?></div><?php endif; ?>
<?php if ($c['status'] !== 'closed'): ?><div class="flash flash--warn"><?= tt('hrs.res_running') ?></div><?php endif; ?>

<div class="card">
  <h2>📊 <?= tt('hrs.result') ?><?= $t['overall'] !== null ? ' <span class="hint" style="display:inline;margin-left:8px;">' . tt('hrs.overall') . ' <b>' . e(rtrim(rtrim(number_format((float) $t['overall'], 1, '.', ''), '0'), '.')) . '</b> / 100</span>' : '' ?></h2>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hrs.dimension') ?></th><th class="num"><?= tt('hrs.self_form') ?></th><th class="num"><?= tt('hrs.mgr_form') ?></th><th><?= tt('hrs.combined') ?></th><th style="width:28%;"></th><th><?= tt('hrs.guidance') ?></th></tr></thead>
    <tbody><?php foreach ($F['dims'] as $d => $dm): $x = $sc[$d] ?? ['self' => null, 'mgr' => null, 'comb' => null, 'gap' => null]; $b = hrs_band($F, $x['comb'] !== null ? (int) $x['comb'] : null); $big = $x['gap'] !== null && abs((int) $x['gap']) > 20; ?><tr>
      <td><b><?= e(hrs_tx($dm['name'])) ?></b><?= $dm['desc'] !== '' ? '<br><span class="hint">' . e($dm['desc']) . '</span>' : '' ?></td>
      <td class="num"><?= $fh($x['self']) ?></td><td class="num"><?= $fh($x['mgr']) ?><?= $big ? '<br><span class="pill pill--warn" title="' . e(t('hrs.gap_hint')) . '">Δ ' . ((int) $x['gap'] > 0 ? '+' : '') . (int) $x['gap'] . '</span>' : '' ?></td>
      <td><?= hrs_band_pill($F, $x['comb'] !== null ? (int) $x['comb'] : null) ?></td>
      <td><?php if ($x['comb'] !== null): ?><div style="background:rgba(31,58,95,.12);border-radius:6px;height:12px;overflow:hidden;" role="img" aria-label="<?= (int) $x['comb'] ?> / 100"><div style="width:<?= (int) $x['comb'] ?>%;height:12px;background:<?= ($b['tone'] ?? '') === 'good' ? '#2E7D32' : (($b['tone'] ?? '') === 'warn' ? '#C9A227' : '#1F3A5F') ?>;"></div></div><?php endif; ?></td>
      <td><span class="hint"><?= e($b ? hrs_tx($b['g']) : '') ?></span></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= tt(!empty($F['set']['manager_form']) ? 'hrs.res_how_mgr' : 'hrs.res_how') ?> <?= tt('hrs.res_eval') ?></p>
  <?php if ($isHr): ?><p class="hint"><?= e(t('hrs.res_meta', ['s' => $t['self_submitted_at'] ? fmt_datetime($t['self_submitted_at']) : '—', 'm' => intdiv((int) $t['self_seconds'], 60), 'r' => $t['mgr_submitted_at'] ? fmt_datetime($t['mgr_submitted_at']) : '—'])) ?></p><?php endif; ?>
</div>

<?php if (count($hist) > 1): ?>
<div class="card">
  <h2>📈 <?= e(t('hrs.years_title', ['f' => $c['family_code']])) ?></h2>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hrs.dimension') ?></th><?php foreach ($hist as $y => $_): ?><th class="num"><?= (int) $y ?></th><?php endforeach; ?><th class="num"><?= tt('hrs.change') ?></th></tr></thead>
    <tbody><?php foreach ($F['dims'] as $d => $dm): $first = null; $last = null; ?><tr><td><?= e(hrs_tx($dm['name'])) ?></td>
      <?php foreach ($hist as $y => $row): $v = isset($row[$d]) && $row[$d]['combined'] !== null ? (int) $row[$d]['combined'] : null; if ($v !== null) { $first ??= $v; $last = $v; } ?><td class="num"><?= $v === null ? '<span class="hint">—</span>' : $v ?></td><?php endforeach; ?>
      <td class="num"><?= $first !== null && $last !== null && $first !== $last ? '<b>' . ($last > $first ? '▲ +' : '▼ ') . ($last - $first) . '</b>' : '<span class="hint">=</span>' ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= tt('hrs.hist_hint') ?></p>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

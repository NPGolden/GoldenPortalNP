<?php
/** Project › Cost control board — budget vs actual of every sub-project, discipline breakdown, cost curve (Option B of GAS-CP-R34-001). ?p=cost_control&project=ID[&sub=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!bg_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'projects'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$pid = (int)($_GET['project'] ?? 0);
$p = one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$P = bg_project($pid); $curve = bg_curve($pid, $P);
$selId = (int)($_GET['sub'] ?? 0); $sel = null; foreach ($P['subs'] as $C) if ((int)$C['sid'] === $selId || (!$selId && $C['tone'] !== 'green' && !$sel)) $sel = $C; if (!$sel && $P['subs']) $sel = $P['subs'][0];
$m = fn(float $v) => number_format($v, 0, ',', '.'); $sg = fn(float $v) => ($v >= 0 ? '+ ' : '− ') . number_format(abs($v), 0, ',', '.');
$mx = 1.0; foreach ($curve as $pt) $mx = max($mx, $pt['budget'], (float)($pt['actual'] ?? 0));
layout_head(t('bg.board_title') . ' · ' . ($p['project_code'] ?: $p['name']), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('bg.board_title') ?></p>
<div class="page-head">
  <div><div class="hint" style="text-transform:uppercase;letter-spacing:.08em;font-size:11px;"><?= tt('bg.board_eyebrow') ?></div><h1><?= e(trim(($p['project_code'] ?: '') . ' — ' . $p['name'], ' —')) ?> <span class="hint" style="display:inline;font-size:14px;">· <?= count($P['subs']) ?> <?= tt('pr.subs') ?> · <?= e($p['vessel'] ?: '') ?></span></h1></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('cost_report', ['project' => $pid]) ?>" target="_blank">⤓ <?= tt('bg.report_pdf') ?></a></div>
</div>

<div class="bg-tiles bg-tiles--5">
  <div class="bg-tile"><span><?= tt('bg.offer_value') ?></span><b><?= e($m($P['offer_value'])) ?> €</b></div>
  <div class="bg-tile"><span><?= tt('bg.hours_b_a') ?></span><b><?= e(bg_h($P['budget_h'])) ?> / <?= e(bg_h($P['actual_h'])) ?> h</b><small class="bg-<?= bg_pct_tone($P['hours_pct']) ?>"><?= $P['hours_pct'] ?> % · <?= tt('bg.progress') ?> <?= $P['progress'] ?> %</small></div>
  <div class="bg-tile"><span><?= tt('bg.parts_b_a') ?></span><b><?= e($m($P['parts_budget'])) ?> / <?= e($m($P['parts_committed'])) ?> €</b><small class="bg-<?= bg_pct_tone($P['parts_pct']) ?>"><?= $P['parts_pct'] ?> %</small></div>
  <div class="bg-tile"><span><?= tt('bg.result_to_date') ?></span><b class="<?= $P['result'] >= 0 ? 'bg-green' : 'bg-neg' ?>"><?= e($sg($P['result'])) ?> €</b><small><?= $P['offer_value'] > 0 ? number_format($P['result'] / $P['offer_value'] * 100, 1, ',', '.') . ' % ' . tt('bg.of_offer') : '' ?></small></div>
  <div class="bg-tile bg-tile--navy"><span><?= tt('bg.forecast_result') ?></span><b class="<?= $P['forecast_result'] >= 0 ? 'bg-gold' : 'bg-neg' ?>"><?= e($sg($P['forecast_result'])) ?> €</b><small><?= tt('bg.budgeted') ?> <?= e($sg($P['budget_result'])) ?> € · <?= e(t('bg.at_risk', ['n' => $P['at_risk']])) ?></small></div>
</div>

<div class="card">
  <h2><?= tt('bg.sec_subs') ?></h2>
  <p class="hint" style="margin-top:-4px;"><?= tt('bg.board_hint') ?></p>
  <div class="table-scroll"><table class="tbl bg-tbl"><thead><tr><th><?= tt('sp.one') ?></th><th style="width:22%;"><?= tt('bg.manhours') ?></th><th class="num">h</th><th style="width:22%;"><?= tt('bg.parts') ?></th><th class="num">€</th><th class="num"><?= tt('bg.result_to_date') ?></th><th class="num"><?= tt('bg.forecast') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($P['subs'] as $C): $s = $C['sub']; ?>
    <tr class="<?= $sel && $sel['sid'] === $C['sid'] ? 'is-on' : '' ?>">
      <td><a href="<?= url('cost_control', ['project' => $pid, 'sub' => $C['sid']]) ?>"><b><?= e($s['code'] ?: '—') ?></b> <?= e($s['name']) ?></a><br><span class="hint"><?= tt('bg.progress') ?> <?= $C['progress'] ?> % · <a href="<?= url('subproject', ['id' => $C['sid'], 'tab' => 'budget']) ?>"><?= tt('bg.tab') ?> ›</a></span></td>
      <td><div class="bg-twin"><?= bg_bar($C['budget_h'] > 0 ? 100 : 0, 'grey', null, '8px') ?><?= bg_bar($C['hours_pct'], bg_pct_tone($C['hours_pct']) === 'green' ? 'navy' : bg_pct_tone($C['hours_pct']), $C['progress'], '8px') ?></div></td>
      <td class="num"><?= e(bg_h($C['actual_h'])) ?> / <?= e(bg_h($C['budget_h'])) ?></td>
      <td><div class="bg-twin"><?= bg_bar($C['parts_budget'] > 0 ? 100 : 0, 'grey', null, '8px') ?><?= bg_bar($C['parts_pct'], in_array('parts_over', $C['flags'], true) ? 'red' : 'navy', null, '8px') ?></div></td>
      <td class="num"><?= e($m($C['parts_committed'])) ?> / <?= e($m($C['parts_budget'])) ?></td>
      <td class="num <?= $C['result'] >= 0 ? 'bg-green' : 'bg-neg' ?>"><b><?= e($sg($C['result'])) ?></b></td>
      <td class="num <?= $C['forecast_result'] >= 0 ? '' : 'bg-neg' ?>"><?= e($sg($C['forecast_result'])) ?></td>
      <td><?php if (!$C['has_budget']): ?><?= pill('mute', t('bg.no_budget')) ?><?php elseif (!$C['flags']): ?><?= pill($C['progress'] >= 100 ? 'green' : 'navy', t($C['progress'] >= 100 ? 'bg.f_closed' : 'bg.f_ok')) ?><?php else: foreach ($C['flags'] as $f): ?><?= pill($f === 'hours_ahead' ? 'gold' : 'bad', bg_flag_label($f)) ?> <?php endforeach; endif; ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$P['subs']): ?><tr><td colspan="8" class="hint"><?= tt('pr.nosubs') ?></td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="cols">
  <div class="card">
    <?php if ($sel): $s = $sel['sub']; ?>
    <div class="card-head"><h2><?= e(trim(($s['code'] ?: '') . ' — ' . $s['name'], ' —')) ?> <span class="hint" style="display:inline;margin-left:6px;"><?= tt('bg.sec_disc') ?></span></h2><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $sel['sid'], 'tab' => 'budget']) ?>">✎ <?= tt('bg.edit_budget') ?></a></div>
    <?php if (!$sel['rows']): ?><p class="hint"><?= tt('bg.no_rows') ?></p><?php else: ?>
    <div class="bg-rows bg-rows--3">
      <?php foreach ($sel['rows'] as $r): ?><div><span><?= e($r['name']) ?></span><?= bg_bar($r['pct'], $r['tone'], null, '10px') ?><b><?= e(bg_h($r['actual_h'])) ?> / <?= e(bg_h($r['budget_h'])) ?></b></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <p class="hint" style="margin:10px 0 0;"><?= tt('bg.disc_hint') ?> <a href="<?= url('timesheets', ['project' => $pid, 'view' => 'all']) ?>"><?= tt('nav.timesheets') ?> ›</a></p>
    <?php else: ?><p class="hint"><?= tt('pr.nosubs') ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('bg.sec_curve') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= tt('bg.curve_sub') ?></span></h2>
    <div class="bg-curve">
      <?php foreach ($curve as $pt): ?><div class="bg-cm" title="<?= e($pt['m']) ?>: <?= e($m($pt['budget'])) ?> / <?= $pt['actual'] === null ? '—' : e($m($pt['actual'])) ?>"><div class="bg-cb"><div class="bg-c1" style="height:<?= (int) round($pt['budget'] / $mx * 100) ?>%;"></div><div class="bg-c2<?= $pt['actual'] !== null && $pt['actual'] > $pt['budget'] ? ' is-over' : '' ?>" style="height:<?= $pt['actual'] === null ? 0 : (int) round($pt['actual'] / $mx * 100) ?>%;"></div></div><span><?= e(date('M', strtotime($pt['m'] . '-01'))) ?></span></div><?php endforeach; ?>
    </div>
    <p class="hint" style="margin:10px 0 0;"><?= tt('bg.curve_hint') ?></p>
  </div>
</div>
<?php layout_foot(); ?>

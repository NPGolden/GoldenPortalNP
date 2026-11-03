<?php
/** Commercial › Pipeline — the hybrid sales pipeline: board per stage, follow-ups due, funnel, per sales person.   GAS-CP-R75-001
 *   ?p=pipeline[&owner=&vessel=&view=board|list] */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
if (!$staff || !function_exists('pp_can') || !pp_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'pipeline'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = ['owner' => (int)($_GET['owner'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0)]; $view = ($_GET['view'] ?? 'board') === 'list' ? 'list' : 'board'; $year = (int)($_GET['year'] ?? date('Y'));
$rows = pp_open($f); $stages = pp_open_statuses(); $today = date('Y-m-d');
$byStage = []; foreach ($stages as $s) $byStage[$s] = []; foreach ($rows as $o) $byStage[$o['status']][] = $o;
$tot = ['n' => count($rows), 'amount' => 0.0, 'weighted' => 0.0, 'overdue' => 0, 'none' => 0, 'expiring' => 0, 'late' => 0];
foreach ($rows as $o) { $tot['amount'] += (float)$o['amount']; $tot['weighted'] += $o['weighted']; if ($o['overdue'] !== null && $o['overdue'] > 0) $tot['overdue']++; if (empty($o['next_action_at'])) $tot['none']++; if ($o['expiring']) $tot['expiring']++; if ($o['stage_late']) $tot['late']++; }
$due = array_values(array_filter($rows, fn($o) => !empty($o['next_action_at']) && $o['next_action_at'] <= $today)); usort($due, fn($a, $b) => $a['next_action_at'] <=> $b['next_action_at']);
$none = array_values(array_filter($rows, fn($o) => empty($o['next_action_at'])));
$funnel = pp_funnel($year);
$byOwner = []; foreach ($rows as $o) { $k = $o['owner_label'] ?: '—'; $byOwner[$k] = $byOwner[$k] ?? ['n' => 0, 'amount' => 0.0, 'weighted' => 0.0, 'overdue' => 0, 'none' => 0]; $byOwner[$k]['n']++; $byOwner[$k]['amount'] += (float)$o['amount']; $byOwner[$k]['weighted'] += $o['weighted']; if ($o['overdue'] > 0) $byOwner[$k]['overdue']++; if (empty($o['next_action_at'])) $byOwner[$k]['none']++; }
ksort($byOwner);
$staffList = function_exists('mt_staff') ? mt_staff() : []; [$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE {$vs} ORDER BY v.name", $va);
$keep = array_filter($f + ['view' => $view, 'year' => $year]);
$card = function (array $o) use ($today): string {
    $cls = $o['overdue'] > 0 || $o['stage_late'] ? 'is-late' : ($o['expiring'] ? 'is-warn' : '');
    $h = '<div class="pp-card ' . $cls . '"><div class="pp-top"><a href="' . url('offer', ['id' => (int)$o['id']]) . '#followup"><b>' . e($o['offer_no']) . '</b> <span class="hint">' . e($o['revision']) . '</span></a>' . pp_prob_pill((int)$o['prob']) . '</div>';
    $h .= '<div class="pp-title">' . e($o['title']) . '</div><div class="hint">' . e($o['vessel'] ?: $o['project_code']) . ($o['customer_name'] ? ' · ' . e($o['customer_name']) : '') . '</div>';
    $h .= '<div class="pp-row"><b>' . e(of_money((float)$o['amount'], $o['currency'])) . '</b><span class="hint">' . e(t('pp.weighted_short', ['v' => of_money($o['weighted'])])) . '</span></div>';
    $h .= '<div class="pp-row"><span class="hint">' . e(t('pp.in_stage', ['n' => $o['days_in_stage']])) . ($o['stage_late'] ? ' ' . pill('bad', t('pp.stage_late')) : '') . '</span>' . ($o['expiring'] ? pill('gold', t('pp.expiring_short', ['d' => fmt_date($o['valid_until'])])) : '') . '</div>';
    $h .= '<div class="pp-row">' . ($o['next_action_type'] ? '<span>' . e(pp_action_label($o['next_action_type'])) . ' · ' . ($o['overdue'] > 0 ? pill('bad', fmt_date($o['next_action_at']) . ' · ' . t('pp.overdue_d', ['d' => $o['overdue']])) : ($o['overdue'] === 0 ? pill('gold', t('pp.today')) : e(fmt_date($o['next_action_at'])))) . '</span>' : pill('bad', t('pp.none_short'))) . '<span class="hint">' . e($o['owner_label']) . '</span></div>';
    $sg = []; foreach ($o['signals'] as $k => $s) $sg[] = '<span class="pill pill--' . ($s['delta'] > 0 ? 'green' : 'red') . '" title="' . e(t('pp.sig_' . $k)) . '">' . ($s['delta'] > 0 ? '+' : '') . (int)$s['delta'] . '</span>';
    if ($sg) $h .= '<div class="pp-row">' . implode(' ', $sg) . '</div>';
    return $h . '</div>';
};
layout_head(t('pp.title'), 'pipeline');
?>
<div class="page-head">
  <div><h1>🎯 <?= tt('pp.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pp.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm<?= $view === 'board' ? ' is-on' : '' ?>" href="<?= url('pipeline', ['view' => 'board'] + array_filter($f)) ?>">▦ <?= tt('pp.board') ?></a><a class="btn btn--ghost btn--sm<?= $view === 'list' ? ' is-on' : '' ?>" href="<?= url('pipeline', ['view' => 'list'] + array_filter($f)) ?>">☰ <?= tt('pp.list') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('sales') ?>">📈 <?= tt('sl.title') ?></a></div>
</div>

<div class="kpi kpi--5" style="margin-bottom:16px;">
  <div class="stat gold"><b><?= $tot['n'] ?></b><?= tt('pp.k_open') ?><span class="s"><?= e(of_money($tot['amount'])) ?></span></div>
  <div class="stat green"><b><?= e(of_money($tot['weighted'])) ?></b><?= tt('pp.k_weighted') ?><span class="s"><?= $tot['amount'] > 0 ? round($tot['weighted'] / $tot['amount'] * 100) . ' %' : '' ?></span></div>
  <div class="stat<?= $tot['overdue'] ? ' red' : '' ?>"><b><?= $tot['overdue'] ?></b><?= tt('pp.k_overdue') ?><span class="s"><?= e(t('pp.k_none', ['n' => $tot['none']])) ?></span></div>
  <div class="stat<?= $tot['expiring'] ? ' gold' : '' ?>"><b><?= $tot['expiring'] ?></b><?= tt('pp.k_expiring') ?></div>
  <div class="stat<?= $tot['late'] ? ' red' : '' ?>"><b><?= $tot['late'] ?></b><?= tt('pp.k_stuck') ?></div>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr auto;">
    <input type="hidden" name="p" value="pipeline"><input type="hidden" name="view" value="<?= $view ?>">
    <div class="field"><label><?= tt('pp.owner') ?></label><select name="owner"><option value=""><?= tt('flt.any') ?></option><?php foreach ($staffList as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)$x['id'] === $f['owner'] ? ' selected' : '' ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $f['vessel'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('pipeline', ['view' => $view]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<?php if ($due || $none): ?>
<div class="card" id="pp-due">
  <h2>⏰ <?= tt('pp.due_title') ?> <span class="count"><?= count($due) + count($none) ?></span></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('of.no') ?></th><th><?= tt('of.title_f') ?></th><th><?= tt('v.one') ?></th><th><?= tt('pp.next_action') ?></th><th><?= tt('pp.owner') ?></th><th class="num"><?= tt('of.amount') ?></th><th></th></tr></thead><tbody>
  <?php foreach (array_merge($due, $none) as $o): ?>
    <tr class="<?= $o['overdue'] > 0 || empty($o['next_action_at']) ? 'is-late' : '' ?>"><td class="ref"><a href="<?= url('offer', ['id' => (int)$o['id']]) ?>#followup"><?= e($o['offer_no']) ?></a> <span class="hint"><?= e($o['revision']) ?></span></td><td><?= e($o['title']) ?><br><span class="hint"><?= e(of_status_label($o['status'])) ?> · <?= pp_prob_pill((int)$o['prob']) ?></span></td><td><?= e($o['vessel'] ?: $o['project_code']) ?></td>
      <td><?= $o['next_action_type'] ? e(pp_action_label($o['next_action_type'])) . ' · ' . ($o['overdue'] > 0 ? pill('bad', fmt_date($o['next_action_at']) . ' · ' . t('pp.overdue_d', ['d' => $o['overdue']])) : pill('gold', t('pp.today'))) . ($o['next_action_note'] ? '<br><span class="hint">' . e($o['next_action_note']) . '</span>' : '') : pill('bad', t('pp.none_short')) ?></td>
      <td><?= e($o['owner_label']) ?></td><td class="num"><?= e(of_money((float)$o['amount'], $o['currency'])) ?></td><td class="row-actions"><a class="btn btn--gold btn--sm" href="<?= url('offer', ['id' => (int)$o['id']]) ?>#followup"><?= tt('pp.follow_up') ?></a></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<?php if ($view === 'board'): ?>
<div class="pp-board">
  <?php foreach ($stages as $s): $list = $byStage[$s]; $sum = array_sum(array_map(fn($o) => (float)$o['amount'], $list)); $w = array_sum(array_map(fn($o) => $o['weighted'], $list)); ?>
  <div class="pp-col">
    <div class="pp-colhead"><?= of_status_pill($s) ?> <b><?= count($list) ?></b><span class="hint"><?= e(of_money($sum)) ?> · <?= e(t('pp.weighted_short', ['v' => of_money($w)])) ?> · <?= pp_base($s) ?> %</span></div>
    <?php foreach ($list as $o) echo $card($o); if (!$list) echo '<p class="hint" style="text-align:center;margin:12px 0;">—</p>'; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card">
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('of.no') ?></th><th><?= tt('of.title_f') ?></th><th><?= tt('v.one') ?></th><th><?= tt('th.status') ?></th><th class="num">%</th><th class="num"><?= tt('of.amount') ?></th><th class="num"><?= tt('pp.k_weighted') ?></th><th><?= tt('pp.in_stage_h') ?></th><th><?= tt('pp.next_action') ?></th><th><?= tt('pp.owner') ?></th><th><?= tt('pp.signals') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $o): ?>
    <tr class="<?= $o['overdue'] > 0 || $o['stage_late'] ? 'is-late' : '' ?>"><td class="ref"><a href="<?= url('offer', ['id' => (int)$o['id']]) ?>#followup"><?= e($o['offer_no']) ?></a> <span class="hint"><?= e($o['revision']) ?></span></td><td><?= e($o['title']) ?></td><td><?= e($o['vessel'] ?: $o['project_code']) ?><?= $o['customer_name'] ? '<br><span class="hint">' . e($o['customer_name']) . '</span>' : '' ?></td>
      <td><?= of_status_pill($o['status']) ?></td><td class="num"><?= pp_prob_pill((int)$o['prob']) ?></td><td class="num"><?= e(of_money((float)$o['amount'], $o['currency'])) ?></td><td class="num"><?= e(of_money($o['weighted'])) ?></td>
      <td><?= (int)$o['days_in_stage'] ?> <?= tt('tk.d') ?><?= $o['stage_late'] ? ' ' . pill('bad', t('pp.stage_late')) : '' ?><?= $o['expiring'] ? '<br>' . pill('gold', t('pp.expiring_short', ['d' => fmt_date($o['valid_until'])])) : '' ?></td>
      <td><?= $o['next_action_type'] ? e(pp_action_label($o['next_action_type'])) . '<br>' . ($o['overdue'] > 0 ? pill('bad', fmt_date($o['next_action_at'])) : e(fmt_date($o['next_action_at']))) : pill('bad', t('pp.none_short')) ?></td><td><?= e($o['owner_label']) ?></td>
      <td><?php foreach ($o['signals'] as $k => $sg): ?><span class="pill pill--<?= $sg['delta'] > 0 ? 'green' : 'red' ?>" title="<?= e(t('pp.sig_' . $k)) ?>"><?= $sg['delta'] > 0 ? '+' : '' ?><?= (int)$sg['delta'] ?></span> <?php endforeach; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card" id="pp-funnel">
    <div class="card-head"><h2>📉 <?= tt('pp.funnel') ?> <?= $year ?></h2><form method="get" action="index.php" class="inline"><input type="hidden" name="p" value="pipeline"><input type="hidden" name="view" value="<?= $view ?>"><select name="year" data-nosearch data-autosubmit><?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>"<?= $y === $year ? ' selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></form></div>
    <p class="hint" style="margin:0 0 8px;"><?= tt('pp.funnel_hint') ?></p>
    <?php $max = max(1, (int)($funnel['stages'][0]['n'] ?? 1)); $prev = null; foreach ($funnel['stages'] as $st): $pct = $max ? round($st['n'] / $max * 100) : 0; ?>
      <div class="pp-fun"><span class="pp-funlab"><?= e($st['label']) ?></span><span class="pp-funbar"><i style="width:<?= $pct ?>%"></i></span><span class="pp-funval"><b><?= (int)$st['n'] ?></b> · <?= e(of_money((float)$st['amount'])) ?><?= $prev !== null && $prev > 0 ? ' <span class="hint">(' . round($st['n'] / $prev * 100) . ' %)</span>' : '' ?></span></div>
    <?php $prev = $st['n']; endforeach; ?>
    <div class="pp-fun"><span class="pp-funlab"><?= tt('pp.won') ?></span><span class="pp-funbar pp-funbar--won"><i style="width:<?= $max ? round($funnel['won']['n'] / $max * 100) : 0 ?>%"></i></span><span class="pp-funval"><b><?= (int)$funnel['won']['n'] ?></b> · <?= e(of_money((float)$funnel['won']['amount'])) ?></span></div>
    <div class="pp-fun"><span class="pp-funlab"><?= tt('pp.lost') ?></span><span class="pp-funbar pp-funbar--lost"><i style="width:<?= $max ? round($funnel['lost']['n'] / $max * 100) : 0 ?>%"></i></span><span class="pp-funval"><b><?= (int)$funnel['lost']['n'] ?></b> · <?= e(of_money((float)$funnel['lost']['amount'])) ?></span></div>
  </div>
  <div class="card" id="pp-owners">
    <h2>👤 <?= tt('pp.by_owner') ?></h2>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pp.owner') ?></th><th class="num"><?= tt('pp.k_open') ?></th><th class="num"><?= tt('of.amount') ?></th><th class="num"><?= tt('pp.k_weighted') ?></th><th class="num"><?= tt('pp.k_overdue') ?></th><th class="num"><?= tt('pp.none_short') ?></th></tr></thead><tbody>
    <?php foreach ($byOwner as $name => $k): ?><tr class="<?= $k['overdue'] || $k['none'] ? 'is-late' : '' ?>"><td><?= e($name) ?></td><td class="num"><?= $k['n'] ?></td><td class="num"><?= e(of_money($k['amount'])) ?></td><td class="num"><?= e(of_money($k['weighted'])) ?></td><td class="num"><?= $k['overdue'] ?></td><td class="num"><?= $k['none'] ?></td></tr><?php endforeach; ?>
    <?php if (!$byOwner): ?><tr><td colspan="6" class="hint"><?= tt('pp.empty') ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <p class="hint" style="margin:8px 0 0;"><?= tt('pp.method') ?></p>
  </div>
</div>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-autosubmit]').forEach(function (s) { s.addEventListener('change', function () { s.form.submit(); }); });</script>
<?php layout_foot(); ?>

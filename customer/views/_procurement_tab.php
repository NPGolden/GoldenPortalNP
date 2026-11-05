<?php /** Sub-project › Procurement — parts budget line and the requisitions of this sub-project (GAS-CP-R80-001; POs and receipts follow in R80-003/004). Expects $id, $pid, $sp. */
$B = prq_budget($id); $reqs = prq_list(['subproject_id' => $id]); $priced = stk_price_visible(); $mon = fn(?float $v) => $v === null ? '—' : prq_money($v);
$S = prq_sub_summary($id);
?>
<?php if ($priced): ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= e($mon($B['parts_budget'])) ?></b><?= tt('prq.b_parts') ?><span class="s"><?= function_exists('bg_can') && bg_can() ? '<a href="' . url('subproject', ['id' => $id, 'tab' => 'budget']) . '">' . tt('tab.budget') . '</a>' : tt('prq.b_parts_hint') ?></span></div>
  <div class="stat gold"><b><?= e($mon($B['committed'])) ?></b><?= tt('prq.b_committed') ?><span class="s"><?= e(t('prq.b_committed_hint', ['n' => $S['open']])) ?></span></div>
  <div class="stat"><b><?= e($mon($B['actual'])) ?></b><?= tt('prq.b_actual') ?><span class="s"><?= tt('prq.b_actual_hint') ?></span></div>
  <div class="stat<?= $B['available'] !== null && $B['available'] < 0 ? ' red' : '' ?>"><b><?= e($mon($B['available'])) ?></b><?= tt('prq.b_avail') ?><span class="s"><?= $S['to_buy'] ? e(t('prq.to_buy_n', ['n' => $S['to_buy']])) : tt('prq.b_avail_hint') ?></span></div>
</div>
<?php endif; ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('prq.title') ?> <span class="hint" style="font-weight:400;">(<?= count($reqs) ?>)</span></h2><p class="hint"><?= tt('prq.tab_lede') ?></p></div>
    <div class="btn-row"><?php if (prq_can()): ?><a class="btn btn--gold btn--sm" href="<?= url('requisition', ['id' => 0, 'project_id' => $pid, 'subproject_id' => $id]) ?>">+ <?= tt('prq.new') ?></a><?php endif; ?></div></div>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('prq.no') ?></th><th><?= tt('prq.r_title') ?></th><th><?= tt('prq.requester') ?></th><th><?= tt('prq.need') ?></th><th class="num"><?= tt('prq.lines') ?></th><?php if ($priced): ?><th class="num"><?= tt('prq.estimate') ?></th><?php endif; ?><th><?= tt('prq.status') ?></th></tr></thead><tbody>
  <?php if (!$reqs): ?><tr><td colspan="7" class="hint"><?= tt('prq.none') ?></td></tr><?php endif; ?>
  <?php foreach ($reqs as $r): ?><tr><td><a href="<?= url('requisition', ['id' => (int)$r['id']]) ?>" style="font-family:var(--mono);"><?= e($r['pr_no']) ?></a></td><td><?= e($r['title'] ?: tt('prq.p_' . $r['purpose'])) ?></td><td><?= e($r['requester_name'] ?? '') ?></td><td><?= fmt_date($r['need_date']) ?></td><td class="num"><?= (int)$r['n_lines'] ?><?= (int)$r['n_to_buy'] ? ' <span class="pill pill--gold">' . (int)$r['n_to_buy'] . '</span>' : '' ?></td><?php if ($priced): ?><td class="num"><?= e($mon($r['est_total'] !== null ? (float)$r['est_total'] : null)) ?></td><?php endif; ?><td><?= prq_pill($r['status']) ?><?= (int)$r['over_budget'] && in_array($r['status'], ['submitted', 'mgmt', 'approved'], true) ? ' ' . pill('warn', '!') : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<div class="card"><h2><?= tt('prq.po_soon') ?></h2><p class="hint"><?= tt('prq.po_soon_hint') ?></p></div>

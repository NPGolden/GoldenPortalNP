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
<?php if (function_exists('bom_has') && bom_has()): $bomEng = bom_eng_can($id); $boms = bom_revisions($id); $bomCur = null; foreach ($boms as $bb) if ($bb['status'] === 'approved') { $bomCur = $bb; break; } ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;">📐 <?= tt('bom.title') ?> <span class="hint" style="font-weight:400;">(<?= count($boms) ?>)</span></h2><p class="hint"><?= tt('bom.tab_lede') ?></p></div>
    <div class="btn-row"><?php if ($bomEng): ?><button class="btn btn--gold btn--sm" type="button" onclick="document.getElementById('bom-new').classList.toggle('is-hidden')">+ <?= tt('bom.new') ?></button><?php endif; ?></div></div>
  <?php if ($bomEng): ?>
  <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'procurement']) ?>" id="bom-new" class="is-hidden" enctype="multipart/form-data" data-lock style="margin-bottom:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="bom_create"><input type="hidden" name="subproject_id" value="<?= $id ?>">
    <div class="form-grid">
      <div class="field field--full"><label><?= tt('prq.r_title') ?></label><input name="title" maxlength="160" placeholder="<?= tt('bom.title_ph') ?>"></div>
      <div class="field"><label><?= tt('bom.copy_from') ?></label><select name="copy_from"><option value="">—</option><?php foreach ($boms as $bb): ?><option value="<?= (int)$bb['id'] ?>">R<?= (int)$bb['revision'] ?> · <?= e($bb['title'] ?: '') ?> (<?= (int)$bb['n_lines'] ?>)</option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('bom.import') ?></label><input type="file" name="file" accept=".xlsx,.xls,.csv"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" maxlength="400"></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('bom.create') ?></button><span class="hint"><?= tt('bom.import_hint') ?></span></div>
  </form>
  <?php endif; ?>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('bom.rev') ?></th><th><?= tt('prq.r_title') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('bom.issued') ?></th><th class="num"><?= tt('prq.lines') ?></th><?php if ($priced): ?><th class="num"><?= tt('prq.estimate') ?></th><?php endif; ?><th><?= tt('prq.status') ?></th></tr></thead><tbody>
  <?php if (!$boms): ?><tr><td colspan="7" class="hint"><?= tt('bom.none') ?></td></tr><?php endif; ?>
  <?php foreach ($boms as $bb): ?><tr><td><a href="<?= url('bom', ['id' => (int)$bb['id']]) ?>" style="font-family:var(--mono);">R<?= (int)$bb['revision'] ?></a></td><td><?= e($bb['title'] ?: '') ?></td><td><?= e($bb['created_name'] ?? '') ?></td><td><?= fmt_date($bb['issued_at']) ?></td><td class="num"><?= (int)$bb['n_lines'] ?></td><?php if ($priced): ?><td class="num"><?= e($mon($bb['est_total'] !== null ? (float)$bb['est_total'] : null)) ?></td><?php endif; ?><td><?= bom_pill($bb['status']) ?><?= (int)$bb['n_pending'] ? ' ' . pill('gold', (int)$bb['n_pending'] . ' ' . t('bom.pending')) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
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

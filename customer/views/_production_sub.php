<?php
/** Partial: Fleet › Equipment › Production › Subcontracting — every external / subcontracted scope line (GAS-CP-R41-003). expects $f, $sel, $pms, $vessels */
$fs = ['status' => (string)($_GET['status'] ?? 'open'), 'supplier' => (string)($_GET['supplier'] ?? ''), 'pm' => (int)($_GET['pm'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = ps_list($fs); $keep = array_filter($fs) + ['view' => 'sub']; $psc = ps_counts(); $fin = fin_visible(); $tot = 0.0; $late = 0;
foreach ($rows as $r) { $tot += (float)($r['amount'] ?? 0); if ($r['due_at'] && !in_array($r['status'], ['delivered', 'inspected', 'accepted', 'cancelled'], true) && $r['due_at'] < date('Y-m-d')) $late++; }
?>
<p class="lede"><?= tt('ps.lede') ?></p>
<div class="kpi kpi--4">
  <a class="stat" href="<?= url('production', ['view' => 'sub', 'status' => 'open']) ?>"><b><?= array_sum($psc) - (int)($psc['accepted'] ?? 0) - (int)($psc['cancelled'] ?? 0) ?></b><span class="s"><?= tt('ps.k_open') ?></span></a>
  <a class="stat gold" href="<?= url('production', ['view' => 'sub', 'status' => 'in_production']) ?>"><b><?= (int)($psc['ordered'] ?? 0) + (int)($psc['in_production'] ?? 0) ?></b><span class="s"><?= tt('ps.k_out') ?></span></a>
  <a class="stat" href="<?= url('production', ['view' => 'sub', 'status' => 'delivered']) ?>"><b><?= (int)($psc['delivered'] ?? 0) + (int)($psc['inspected'] ?? 0) ?></b><span class="s"><?= tt('ps.k_in') ?></span></a>
  <a class="stat<?= $late ? ' red' : '' ?>" href="<?= url('production', ['view' => 'sub', 'status' => 'open']) ?>"><b><?= $late ?></b><span class="s"><?= tt('ps.k_late') ?></span></a>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="production"><input type="hidden" name="view" value="sub">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($fs['q']) ?>" placeholder="<?= tt('ps.search_ph') ?>"></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= $sel('open', $fs['status']) ?>><?= tt('ps.k_open') ?></option><?php foreach (PS_STATUSES as $st): ?><option value="<?= $st ?>"<?= $sel($st, $fs['status']) ?>><?= e(t('ps.s_' . $st)) ?></option><?php endforeach; ?><option value="closed"<?= $sel('closed', $fs['status']) ?>><?= tt('ps.f_closed') ?></option><option value="all"<?= $sel('all', $fs['status']) ?>><?= tt('flt.all') ?></option></select></div>
  <div class="field"><label><?= tt('ps.supplier') ?></label><select name="supplier"><option value=""><?= tt('flt.any') ?></option><?php foreach (ps_suppliers() as $sn): ?><option value="<?= e($sn) ?>"<?= $sel($sn, $fs['supplier']) ?>><?= e($sn) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $fs['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $fs['vessel']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('production', ['view' => 'sub']) ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <div class="card-head"><h2><?= tt('ps.title') ?> <span class="hint">(<?= count($rows) ?>)</span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('production_sub_pdf', array_diff_key($keep, ['view' => 1])) ?>" target="_blank">⤓ <?= tt('ps.report') ?> PDF</a></div></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('ps.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ps.supplier') ?></th><th><?= tt('pd.order') ?></th><th><?= tt('ps.scope') ?></th><th><?= tt('eq.sn') ?></th><th><?= tt('ps.po_no') ?></th><th><?= tt('ps.ordered') ?></th><th><?= tt('ps.due') ?></th><th><?= tt('ps.delivered') ?></th><th><?= tt('th.status') ?></th><th class="num">%</th><?php if ($fin): ?><th class="num"><?= tt('ps.amount') ?></th><?php endif; ?><th><?= tt('ps.inspection') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $isLate = $r['due_at'] && !in_array($r['status'], ['delivered', 'inspected', 'accepted', 'cancelled'], true) && $r['due_at'] < date('Y-m-d'); ?>
    <tr class="<?= in_array($r['status'], ['accepted', 'cancelled'], true) ? 'is-muted' : '' ?>">
      <td><b><?= e($r['supplier']) ?></b></td>
      <td><a href="<?= url('production_order', ['id' => (int)$r['order_id']]) ?>#subs" class="mono"><b><?= e($r['order_no']) ?></b></a><br><span class="hint"><?= e($r['order_title']) ?><?= $r['vessel'] ? ' · ' . e($r['vessel']) : '' ?></span></td>
      <td><?= e($r['scope']) ?><?= $r['whole'] ? ' ' . pill('gold', t('ps.whole')) : '' ?></td>
      <td class="mono"><?= $r['serial_no'] ? e($r['serial_no']) : '<span class="hint">' . tt('pd.all_units') . '</span>' ?></td>
      <td class="mono"><?= e($r['po_no'] ?: '—') ?></td>
      <td class="date"><?= $r['ordered_at'] ? e(fmt_date($r['ordered_at'])) : '—' ?></td>
      <td class="date<?= $isLate ? ' is-late' : '' ?>"><?= $r['due_at'] ? e(fmt_date($r['due_at'])) : '—' ?><?= $isLate ? ' ' . pill('bad', t('pd.late')) : '' ?></td>
      <td class="date"><?= $r['delivered_at'] ? e(fmt_date($r['delivered_at'])) : '—' ?></td>
      <td><?= ps_status_pill($r['status']) ?></td>
      <td class="num"><b><?= (int)$r['pct'] ?> %</b><?= pd_pct_bar((int)$r['pct']) ?></td>
      <?php if ($fin): ?><td class="num"><?= e(ps_money($r['amount'] !== null ? (float)$r['amount'] : null, $r['currency'])) ?></td><?php endif; ?>
      <td class="hint"><?= e((string)$r['inspection_note']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody><?php if ($fin): ?><tfoot><tr><td colspan="10"><b><?= tt('ps.total') ?></b></td><td class="num"><b><?= e(ps_money($tot)) ?></b></td><td></td></tr></tfoot><?php endif; ?></table></div>
  <?php endif; ?>
</div>

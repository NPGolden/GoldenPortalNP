<?php
/** Procurement › Budget pricing requests — system budgets waiting for prices and delivery times.   GAS-CP-R97-049 */
require __DIR__ . '/_layout.php';
$u = sbp_require(); $rows = sbp_queue(); $nW = count(array_filter($rows, fn($r) => $r['proc_state'] === 'requested'));
layout_head(t('sbp.q_title'), 'system_budgets_proc');
?>
<div class="page-head"><div><h1>💶 <?= tt('sbp.q_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sbp.q_lede') ?></p></div></div>
<div class="card"><div class="card-head"><h2><?= tt('sbp.q_list') ?> <span class="count"><?= count($rows) ?></span> <?= $nW ? pill('gold', $nW . ' ' . t('sbp.q_waiting')) : pill('green', t('sbp.q_nothing')) ?></h2></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('sbp.q_empty') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sb.no') ?></th><th><?= tt('sb.f_title') ?></th><th><?= tt('sb.project') ?></th><th><?= tt('sb.priority') ?></th><th><?= tt('sbp.q_target') ?></th><th><?= tt('sbp.q_from') ?></th><th class="num"><?= tt('sbp.q_lines') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $late = $r['target_date'] && $r['target_date'] < date('Y-m-d') && $r['proc_state'] === 'requested'; ?><tr>
    <td class="mono"><b><?= e($r['sb_no'] . ' R' . (int)$r['revision']) ?></b></td><td><?= e((string)$r['title']) ?><?= trim((string)($r['proc_note'] ?? '')) !== '' ? '<br><span class="hint">' . e((string)$r['proc_note']) . '</span>' : '' ?></td>
    <td><?= e(trim((string)$r['project_code'])) ?><?= $r['vessel_name'] ? '<br><span class="hint">' . e((string)$r['vessel_name']) . '</span>' : '' ?></td><td><?= function_exists('sb_prio_pill') ? sb_prio_pill((string)$r['priority']) : e((string)$r['priority']) ?></td>
    <td class="date"><?= $r['target_date'] ? ($late ? pill('bad', fmt_date((string)$r['target_date'])) : e(fmt_date((string)$r['target_date']))) : '—' ?></td><td class="hint"><?= e((string)($r['req_name'] ?? '')) ?><br><?= e(fmt_datetime((string)$r['proc_req_at'])) ?></td>
    <td class="num"><?= (int)$r['n_done'] ?> / <?= (int)$r['n_parts'] ?></td><td><?= sbp_pill($r) ?></td>
    <td><a class="btn btn--sm<?= $r['proc_state'] === 'requested' ? ' btn--gold' : ' btn--ghost' ?>" href="<?= url('system_budget_proc', ['id' => (int)$r['id']]) ?>"><?= tt($r['proc_state'] === 'requested' ? 'sbp.q_price' : 'sbp.q_view') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php layout_foot();

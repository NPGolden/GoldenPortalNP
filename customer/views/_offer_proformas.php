<?php
/** Partial: offer page › payment terms and proforma invoices (GAS-CP-R44-002/003). expects $o, $id, $staff */
if (!function_exists('pi_has') || !pi_has() || !fin_visible()) return;
$sales = pi_is_sales(); $approved = in_array(of_status_behaves($o['status']), ['approved', 'closed_won'], true);
if ($sales && !pt_of($id) && ($o['status'] !== 'draft')) pt_seed($id, $o);
$terms = pt_of($id); $pis = pi_of_offer($id); $byN = []; foreach ($pis as $p) if ($p['term_n'] && $p['status'] !== 'cancelled') $byN[(int)$p['term_n']] = $p;
$sumPct = 0.0; foreach ($terms as $t) $sumPct += (float)($t['pct'] ?? 0);
$backTo = url('offer', ['id' => $id]); $cur = (string)($o['currency'] ?: 'EUR');
$isBud = ($o['kind'] ?? 'firm') === 'budgetary';
?>
<div class="card" id="proformas">
  <div class="card-head"><h2>💶 <?= tt('pt.title') ?> · <?= tt('pi.title') ?> <span class="hint">(<?= count($pis) ?>)</span></h2><span class="hint"><?= tt('pt.hint') ?></span></div>
  <?php if ($isBud && $approved && !$o['converted_to'] && $sales): $subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [(int)$o['project_id']]); ?>
  <form method="post" action="<?= $backTo ?>" class="fltbar" style="grid-template-columns: 1fr auto; margin-bottom:10px;" data-lock data-confirm="<?= tt('oc.convert_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oc.convert') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="oc_convert"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('oc.convert_to') ?></label><select name="subproject_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($subs as $sb): ?><option value="<?= (int)$sb['id'] ?>"><?= e(trim($sb['code'] . ' ' . $sb['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--gold btn--sm" type="submit">⇄ <?= tt('oc.convert') ?></button></div>
  </form>
  <p class="hint" style="margin:0 0 10px;"><?= tt('oc.convert_hint') ?></p>
  <?php endif; ?>
  <?php if (!$terms && !$sales): ?><p class="hint"><?= tt('pt.none') ?></p><?php endif; ?>
  <?php if ($terms || $sales): ?>
  <form method="post" action="<?= $backTo ?>#proformas" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pt_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl pt-tbl"><thead><tr><th>#</th><th class="num">%</th><th class="num"><?= tt('pt.amount') ?></th><th><?= tt('pt.trigger') ?></th><th class="num"><?= tt('pt.due_days') ?></th><th><?= tt('pi.one') ?></th><th></th></tr></thead><tbody>
  <?php $rows = $terms; if ($sales) { $want = max(count($terms) + 1, 3); for ($k = count($terms); $k < $want; $k++) $rows[] = ['n' => $k + 1, 'pct' => null, 'amount' => null, 'trigger_text' => '', 'due_days' => null, 'new' => true]; }
  foreach ($rows as $i => $t): $n = (int)$t['n']; $p = $byN[$n] ?? null; $locked = $p && !in_array($p['status'], ['draft'], true); ?>
    <tr>
      <td class="mono"><?= $n ?></td>
      <?php if ($sales && !$locked): ?>
      <td class="num"><input name="pct[<?= $i ?>]" inputmode="decimal" value="<?= $t['pct'] !== null ? e(rtrim(rtrim(number_format((float)$t['pct'], 2, '.', ''), '0'), '.')) : '' ?>" style="width:64px;text-align:right;"></td>
      <td class="num"><input name="amount[<?= $i ?>]" inputmode="decimal" value="<?= $t['amount'] !== null ? e(number_format((float)$t['amount'], 2, ',', '.')) : '' ?>" placeholder="<?= $t['pct'] !== null ? e(number_format(pt_amount($t, $o), 2, ',', '.')) : '' ?>" style="width:110px;text-align:right;"></td>
      <td><input name="trigger[<?= $i ?>]" maxlength="160" value="<?= e((string)$t['trigger_text']) ?>" placeholder="<?= tt('pt.trigger_ph') ?>"></td>
      <td class="num"><input name="due[<?= $i ?>]" type="number" min="0" max="365" value="<?= $t['due_days'] !== null ? (int)$t['due_days'] : '' ?>" style="width:64px;"></td>
      <?php else: ?>
      <td class="num"><?= $t['pct'] !== null ? e(rtrim(rtrim(number_format((float)$t['pct'], 2, '.', ''), '0'), '.')) . ' %' : '—' ?></td><td class="num"><b><?= e(pi_money(pt_amount($t, $o), $cur)) ?></b></td><td><?= e((string)$t['trigger_text']) ?></td><td class="num"><?= $t['due_days'] !== null ? (int)$t['due_days'] . ' d' : '—' ?></td>
      <?php if ($sales): ?><input type="hidden" name="pct[<?= $i ?>]" value="<?= $t['pct'] !== null ? e((string)$t['pct']) : '' ?>"><input type="hidden" name="amount[<?= $i ?>]" value="<?= $t['amount'] !== null ? e((string)$t['amount']) : '' ?>"><input type="hidden" name="trigger[<?= $i ?>]" value="<?= e((string)$t['trigger_text']) ?>"><input type="hidden" name="due[<?= $i ?>]" value="<?= $t['due_days'] !== null ? (int)$t['due_days'] : '' ?>"><?php endif; ?>
      <?php endif; ?>
      <td><?php if ($p): ?><a href="<?= url('proforma', ['id' => (int)$p['id']]) ?>" class="mono"><b><?= e($p['pi_no']) ?></b></a> <?= pi_status_pill($p['status']) ?><?= pi_overdue($p) ? ' ' . pill('bad', t('pi.overdue')) : '' ?><br><span class="hint"><?= e(pi_money((float)$p['gross'], $p['currency'])) ?><?= (float)$p['paid_sum'] > 0 ? ' · ' . tt('pi.paid_lbl') . ' ' . e(pi_money((float)$p['paid_sum'], $p['currency'])) : '' ?><?= $p['due_date'] ? ' · ' . tt('pi.due') . ' ' . e(fmt_date($p['due_date'])) : '' ?></span><?php elseif (empty($t['new'])): ?><span class="hint"><?= tt('pi.not_issued') ?></span><?php endif; ?></td>
      <td class="row-actions"><?php if (!$p && $sales && $approved && empty($t['new'])): ?><button class="btn btn--gold btn--sm" type="submit" form="pi-new-<?= $n ?>">+ <?= tt('pi.issue_slot') ?></button><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr><td></td><td class="num<?= abs($sumPct - 100) > 0.01 && $terms ? ' is-late' : '' ?>"><b><?= e(rtrim(rtrim(number_format($sumPct, 2, '.', ''), '0'), '.')) ?> %</b></td><td class="num"><b><?= e(pi_money((float)$o['amount'], $cur)) ?></b></td><td colspan="4" class="hint"><?= $terms && abs($sumPct - 100) > 0.01 ? tt('pt.not_100') : '' ?></td></tr></tfoot></table></div>
  <?php if ($sales): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('pt.save') ?></button><?php if ($approved): ?><button class="btn btn--ghost btn--sm" type="submit" form="pi-new-0">+ <?= tt('pi.issue_free') ?></button><?php endif; ?><?= !$approved ? '<span class="hint">' . tt('pi.need_approved') . '</span>' : '' ?></div><?php endif; ?>
  </form>
  <?php if ($sales && $approved): foreach (array_merge([0], array_map(fn($t) => (int)$t['n'], $terms)) as $n): ?><form method="post" action="<?= $backTo ?>" id="pi-new-<?= $n ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pi_create"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="term_n" value="<?= $n ?>"></form><?php endforeach; endif; ?>
  <?php endif; ?>
  <?php $others = array_filter($pis, fn($p) => !$p['term_n'] || !isset($byN[(int)$p['term_n']]) || (int)$byN[(int)$p['term_n']]['id'] !== (int)$p['id']); if ($others): ?>
  <ul class="pd-files" style="margin-top:10px;"><?php foreach ($others as $p): ?><li><a href="<?= url('proforma', ['id' => (int)$p['id']]) ?>" class="mono"><b><?= e($p['pi_no']) ?></b></a> <?= e($p['title']) ?> · <?= e(pi_money((float)$p['gross'], $p['currency'])) ?> <?= pi_status_pill($p['status']) ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <?php $fs = fs_totals((int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null); if ($fs['invoiced'] > 0): ?><p class="hint" style="margin:8px 0 0;"><?= tt('fs.title') ?>: <?= fs_pills((int)$o['project_id'], $o['subproject_id'] ? (int)$o['subproject_id'] : null) ?></p><?php endif; ?>
</div>

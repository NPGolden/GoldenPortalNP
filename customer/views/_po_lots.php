<?php /* GAS-CP-R97-146 — delivery lots of a purchase order (included by purchase_order.php; needs $o, $id, $self, $lines, $admin) */
if (!function_exists('pol_has') || !pol_has() || !$o || $o['kind'] !== 'po' || $o['status'] === 'draft') return;
$lots = pol_of([$id])[$id] ?? []; $polCan = pol_can($o); if (!$lots && !$polCan) return;
$live = array_values(array_filter($lines, fn($l) => $l['status'] !== 'cancelled')); $noLot = count(array_filter($live, fn($l) => empty($l['lot_id'])));
$di = fn(string $n, ?string $v, string $f) => '<input type="date" name="' . $n . '" value="' . e((string)$v) . '" form="' . $f . '" style="width:9.6em;">';
?>
<div class="card" id="lots">
  <div class="split-head"><div><h2 style="margin:0;">📦 <?= tt('pol.title') ?> <span class="hint" style="font-weight:400;">(<?= count($lots) ?>)</span></h2><p class="hint"><?= tt('pol.lede') ?></p></div>
    <?php if ($polCan): ?><form method="post" action="<?= $self ?>#lots" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pol_auto"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('pol.auto_hint') ?>">⚙ <?= tt('pol.auto') ?></button></form><?php endif; ?></div>
  <?php if ($lots && $noLot): ?><p class="hint" style="color:var(--warn,#8a6d00);">＋ <?= e(t('pol.unassigned', ['n' => $noLot])) ?></p><?php endif; ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('pol.lot') ?></th><th><?= tt('po.planned') ?></th><th><?= tt('pol.s_shipped') ?></th><th><?= tt('pol.s_arrived') ?></th><th><?= tt('pol.s_delivered') ?></th><th><?= tt('po.carrier') ?> / <?= tt('po.tracking') ?></th><th><?= tt('prq.note') ?></th><th class="num"><?= tt('prq.lines') ?></th><th><?= tt('th.status') ?></th><?php if ($polCan): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php if (!$lots): ?><tr><td colspan="10" class="hint"><?= tt('pol.none') ?></td></tr><?php endif; ?>
  <?php foreach ($lots as $l): $f = 'lot-f-' . (int)$l['id']; ?>
    <tr>
      <td><b><?= e(t('pol.lot_n', ['n' => (int)$l['seq']])) ?></b></td>
      <?php if ($polCan): ?>
      <td><?= $di('planned_date', $l['planned_date'], $f) ?></td><td><?= $di('shipped_date', $l['shipped_date'], $f) ?></td><td><?= $di('arrived_date', $l['arrived_date'], $f) ?></td><td><?= $di('delivered_date', $l['delivered_date'], $f) ?><?= !$l['delivered_date'] && $l['state'] === 'delivered' && $l['last_receipt'] ? '<br><small class="hint">' . tt('pol.by_receipt') . ' ' . fmt_date($l['last_receipt']) . '</small>' : '' ?></td>
      <td><input name="carrier" form="<?= $f ?>" maxlength="120" value="<?= e((string)$l['carrier']) ?>" placeholder="DHL, DSV, …" style="width:8em;"> <input name="tracking" form="<?= $f ?>" maxlength="120" value="<?= e((string)$l['tracking']) ?>" placeholder="AWB / B/L" style="width:9em;"></td>
      <td><input name="note" form="<?= $f ?>" maxlength="255" value="<?= e((string)$l['note']) ?>" style="width:10em;"></td>
      <?php else: ?>
      <td><?= fmt_date($l['planned_date']) ?></td><td><?= fmt_date($l['shipped_date']) ?></td><td><?= fmt_date($l['arrived_date']) ?></td><td><?= fmt_date($l['done_date']) ?></td><td><?= e(trim(($l['carrier'] ?? '') . ' ' . ($l['tracking'] ?? ''))) ?: '—' ?></td><td><?= e((string)$l['note']) ?></td>
      <?php endif; ?>
      <td class="num"><?= (int)$l['n_recv'] ?> / <?= (int)$l['n'] ?></td><td><?= pol_state_html($l) ?></td>
      <?php if ($polCan): ?><td class="num" style="white-space:nowrap;">
        <form method="post" action="<?= $self ?>#lots" data-lock id="<?= $f ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="pol_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="lot_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--sm" type="submit" title="<?= tt('btn.save') ?>">💾</button></form>
        <form method="post" action="<?= $self ?>#lots" data-lock data-confirm="<?= e(t('pol.delete_q', ['n' => (int)$l['seq']])) ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="pol_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="lot_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('btn.delete') ?>">✕</button></form>
      </td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if ($polCan): $f = 'lot-f-new'; ?>
    <tr><td class="hint">＋ <?= tt('pol.new') ?></td><td><?= $di('planned_date', null, $f) ?></td><td><?= $di('shipped_date', null, $f) ?></td><td><?= $di('arrived_date', null, $f) ?></td><td><?= $di('delivered_date', null, $f) ?></td>
      <td><input name="carrier" form="<?= $f ?>" maxlength="120" placeholder="DHL, DSV, …" style="width:8em;"> <input name="tracking" form="<?= $f ?>" maxlength="120" placeholder="AWB / B/L" style="width:9em;"></td><td><input name="note" form="<?= $f ?>" maxlength="255" style="width:10em;"></td><td></td><td></td>
      <td class="num"><form method="post" action="<?= $self ?>#lots" data-lock id="<?= $f ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pol_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="lot_id" value="0"><button class="btn btn--gold btn--sm" type="submit">＋ <?= tt('pol.add') ?></button></form></td></tr>
  <?php endif; ?>
  </tbody></table></div>
  <?php if ($lots): ?>
  <details style="margin-top:10px;"<?= $noLot && $polCan ? ' open' : '' ?>><summary><b><?= tt('pol.lines_h') ?></b> <span class="hint"><?= tt('pol.lines_hint') ?></span></summary>
    <?php if ($polCan): ?><form method="post" action="<?= $self ?>#lots" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pol_lines"><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th>#</th><th><?= tt('stk.code') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th><?= tt('po.delivery') ?></th><th><?= tt('th.status') ?></th><th><?= tt('pol.lot') ?></th></tr></thead><tbody>
    <?php $bySeq = []; foreach ($lots as $l) $bySeq[(int)$l['id']] = $l; foreach ($live as $ln): $lx = function_exists('po_line_expected') ? po_line_expected($o, $ln) : null; ?>
      <tr><td><?= (int)$ln['seq'] ?></td><td style="font-family:var(--mono);"><?= e((string)($ln['item_code'] ?: $ln['part_no'])) ?></td><td><?= e(mb_strimwidth((string)$ln['description'], 0, 90, '…')) ?></td><td class="num"><?= stk_qty((float)$ln['qty']) ?> <?= e((string)$ln['unit']) ?></td><td><?= !empty($ln['promised_date']) ? fmt_date($ln['promised_date']) . ' ✓' : ($ln['lead_days'] !== null ? (int)$ln['lead_days'] . ' ' . tt('po.days') . ($lx ? ' <small class="hint">(' . fmt_date($lx) . ')</small>' : '') : '—') ?></td><td><?= $ln['status'] === 'received' ? pill('green', t('po.s_received')) : ($ln['status'] === 'partial' ? pill('gold', t('po.s_partial')) : '—') ?></td>
        <td><?php if ($polCan): ?><select name="lot[<?= (int)$ln['id'] ?>]" data-nosearch><option value="0">—</option><?php foreach ($lots as $l): ?><option value="<?= (int)$l['id'] ?>"<?= (int)$ln['lot_id'] === (int)$l['id'] ? ' selected' : '' ?>><?= e(t('pol.lot_n', ['n' => (int)$l['seq']]) . ($l['planned_date'] ? ' · ' . fmt_date($l['planned_date']) : '')) ?></option><?php endforeach; ?></select><?php else: ?><?= !empty($ln['lot_id']) && isset($bySeq[(int)$ln['lot_id']]) ? e(t('pol.lot_n', ['n' => (int)$bySeq[(int)$ln['lot_id']]['seq']])) : '—' ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php if ($polCan): ?><div class="btn-row"><button class="btn btn--sm" type="submit">💾 <?= tt('pol.lines_save') ?></button></div></form><?php endif; ?>
  </details>
  <?php endif; ?>
</div>

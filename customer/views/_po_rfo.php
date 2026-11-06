<?php
/** RFO block of an RFQ (GAS-CP-R88-001): invited suppliers, quotation entry for the selected supplier, comparison and award.
 *  Included by purchase_order.php with $o, $id, $lines, $admin, $priced, $self, $sups, $rsAll, $rsSel, $cmp, $sel. */
$openRfq = in_array($o['status'], ['draft', 'sent', 'quoted'], true); $Q = $cmp['quotes']; $act = $cmp['lines']; $quoted = array_filter($rsAll, fn($r) => in_array($r['status'], ['quoted', 'awarded', 'lost'], true) && (int)$r['n_quoted'] > 0);
$invitedIds = array_map(fn($r) => (int)$r['supplier_id'], $rsAll); $mq = fn(?float $v, string $c) => $v === null ? '—' : po_money($v, $c);
?>
<div class="card" id="rfo">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;">📨 <?= tt('rfo.title') ?> <span class="hint" style="font-weight:400;">(<?= count($rsAll) ?>)</span></h2><p class="hint" style="margin:2px 0 0;"><?= tt('rfo.lede') ?></p></div></div>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('po.supplier') ?></th><th><?= tt('prq.status') ?></th><th><?= tt('po.sent') ?></th><th class="num"><?= tt('rfo.quote_total') ?></th><th><?= tt('po.lead') ?></th><th><?= tt('po.delivery') ?></th><th><?= tt('po.supplier_ref') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rsAll as $r): $t = $cmp['totals'][(int)$r['id']] ?? null; $isSel = $rsSel && (int)$rsSel['id'] === (int)$r['id']; ?>
    <tr<?= $isSel ? ' style="background:var(--navy-soft,rgba(11,30,45,.05))"' : '' ?>>
      <td><a href="<?= url('suppliers', ['id' => (int)$r['supplier_id']]) ?>"><b><?= e($r['supplier']) ?></b></a><?= $cmp['cheapest'] === (int)$r['id'] ? ' ' . pill('ok', t('rfo.cheapest')) : '' ?><?= $cmp['fastest'] === (int)$r['id'] && $cmp['fastest'] !== $cmp['cheapest'] ? ' ' . pill('navy', t('rfo.fastest')) : '' ?><?= $r['note'] ? '<br><small class="hint">' . e($r['note']) . '</small>' : '' ?></td>
      <td><?= rfo_pill($r['status']) ?></td>
      <td class="hint"><?= $r['sent_at'] ? e(fmt_datetime($r['sent_at'])) . ($r['sent_to'] ? '<br><small>' . e($r['sent_to']) . '</small>' : '') : '—' ?></td>
      <td class="num"><?php if ($t && (int)$r['n_quoted'] > 0): ?><b><?= e($mq($t['total'], $r['currency'] ?: $o['currency'])) ?></b><?= $t['missing'] ? '<br><small class="hint" style="color:var(--red)">' . e(t('rfo.missing_n', ['n' => $t['missing']])) . '</small>' : '<br><small class="hint">' . e(t('rfo.wins_n', ['n' => $t['wins'], 'm' => count($act)])) . '</small>' ?><?php else: ?>—<?php endif; ?></td>
      <td><?= $t && $t['lead'] !== null ? (int)$t['lead'] . ' ' . tt('po.days') : '—' ?></td><td><?= fmt_date($r['delivery_date']) ?></td><td class="hint"><?= e($r['supplier_ref'] ?? '') ?></td>
      <td class="num" style="white-space:nowrap;">
        <?php if ($admin && $openRfq): ?>
          <?php if (!in_array($r['status'], ['declined'], true)): ?><a class="btn btn--ghost btn--sm" href="<?= url('purchase_order', ['id' => $id, 'sup' => (int)$r['id']]) ?>#send" title="<?= tt('em.send') ?>">✉</a>
          <form method="post" action="<?= $self ?>" style="display:inline" data-lock data-confirm="<?= e(t('po.send_rfq_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rfo_send"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rs_id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('po.mark_sent') ?>"<?= $lines ? '' : ' disabled' ?>>➤</button></form>
          <a class="btn btn--ghost btn--sm" href="<?= url('purchase_order', ['id' => $id, 'sup' => (int)$r['id']]) ?>#quote" title="<?= tt('rfo.type_quote') ?>">💶</a><?php endif; ?>
          <?php if ($r['status'] === 'quoted'): ?><form method="post" action="<?= $self ?>" style="display:inline" data-lock data-confirm="<?= e(t('rfo.award_q', ['n' => $r['supplier']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="po_award"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rs_id" value="<?= (int)$r['id'] ?>"><button class="btn btn--gold btn--sm" type="submit">🏆 <?= tt('po.award') ?></button></form><?php endif; ?>
          <?php if (!in_array($r['status'], ['declined', 'awarded', 'lost'], true)): ?><form method="post" action="<?= $self ?>" style="display:inline" data-lock data-prompt="<?= e(t('rfo.decline_q')) ?>" data-prompt-field="note"><?= csrf_field() ?><input type="hidden" name="action" value="rfo_decline"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rs_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('rfo.decline') ?>">⊘</button></form><?php endif; ?>
          <?php if (in_array($r['status'], ['invited', 'declined'], true) && count($rsAll) > 1): ?><form method="post" action="<?= $self ?>" style="display:inline" data-lock data-confirm="<?= e(t('rfo.remove_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rfo_uninvite"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rs_id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('btn.delete') ?>">🗑</button></form><?php endif; ?>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if ($admin && $openRfq): ?>
  <form method="post" action="<?= $self ?>#rfo" class="btn-row" style="margin-top:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rfo_invite"><input type="hidden" name="id" value="<?= $id ?>">
    <select name="supplier_id" required style="min-width:280px;"><option value=""><?= tt('rfo.pick_supplier') ?></option><?php foreach ($sups as $s): if (in_array((int)$s['id'], $invitedIds, true)) continue; ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select>
    <button class="btn btn--sm" type="submit">+ <?= tt('rfo.invite') ?></button><span class="hint"><?= tt('rfo.invite_hint') ?></span>
  </form>
  <?php endif; ?>
</div>

<?php if ($admin && $openRfq && $rsSel && !in_array($rsSel['status'], ['declined', 'awarded', 'lost'], true) && $act): $rsId = (int)$rsSel['id']; $cur = $rsSel['currency'] ?: $o['currency']; ?>
<div class="card" id="quote">
  <h2>💶 <?= tt('rfo.quote_of') ?> <?= e($rsSel['supplier']) ?></h2>
  <p class="hint"><?= tt('rfo.quote_hint') ?> <?= count($rsAll) > 1 ? '· ' . tt('rfo.switch') . ' ' . implode(' · ', array_map(fn($r) => '<a href="' . url('purchase_order', ['id' => $id, 'sup' => (int)$r['id']]) . '#quote">' . e($r['supplier']) . '</a>', array_filter($rsAll, fn($r) => (int)$r['id'] !== $rsId && !in_array($r['status'], ['declined', 'awarded', 'lost'], true)))) : '' ?></p>
  <form method="post" action="<?= $self ?>#rfo" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rfo_quote"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rs_id" value="<?= $rsId ?>">
    <table class="tbl tbl--wrap"><thead><tr><th>#</th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th class="num"><?= tt('po.unit_price') ?> (<?= e($cur) ?>)</th><th class="num"><?= tt('po.line_total') ?></th><th><?= tt('po.lead') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
    <?php foreach ($act as $l): $q = $Q[$rsId][(int)$l['id']] ?? null; ?>
      <tr><td><?= (int)$l['seq'] ?></td><td><?= e($l['item_code'] ? $l['item_code'] . ' · ' : '') . e($l['description']) ?><?= $l['maker'] || $l['part_no'] ? '<br><small class="hint">' . e(trim(($l['maker'] ?? '') . ' ' . ($l['part_no'] ?? ''))) . '</small>' : '' ?></td><td class="num"><?= stk_qty((float)$l['qty']) ?> <?= e($l['unit']) ?></td>
        <td class="num"><input name="price[<?= (int)$l['id'] ?>]" type="number" step="0.0001" min="0" value="<?= $q && $q['unit_price'] !== null ? e((string)(float)$q['unit_price']) : '' ?>" style="width:7.5em;"></td>
        <td class="num hint"><?= $q && $q['unit_price'] !== null ? e(po_money((float)$l['qty'] * (float)$q['unit_price'], $cur)) : '—' ?></td>
        <td><input name="lead[<?= (int)$l['id'] ?>]" type="number" min="0" max="999" value="<?= e((string)($q['lead_days'] ?? '')) ?>" style="width:4.5em;"> <?= tt('po.days') ?></td>
        <td><input name="qnote[<?= (int)$l['id'] ?>]" maxlength="160" value="<?= e($q['note'] ?? '') ?>" style="width:12em;"></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <div class="form-grid" style="margin-top:10px;"><div class="field"><label><?= tt('po.currency') ?></label><input name="currency" maxlength="3" value="<?= e($cur) ?>" pattern="[A-Z]{3}"></div><div class="field"><label><?= tt('po.supplier_ref') ?></label><input name="supplier_ref" maxlength="80" value="<?= e($rsSel['supplier_ref'] ?? '') ?>"></div><div class="field"><label><?= tt('po.delivery') ?></label><input name="delivery_date" type="date" value="<?= e($rsSel['delivery_date'] ?? '') ?>"></div><div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" maxlength="400" value="<?= e($rsSel['note'] ?? '') ?>"></div></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">💾 <?= tt('po.save_quote') ?></button><span class="hint"><?= tt('po.quote_hint') ?></span></div>
  </form>
</div>
<?php endif; ?>

<?php if (count($quoted) >= 1 && $act): ?>
<div class="card" id="compare">
  <h2>⚖ <?= tt('rfo.compare') ?></h2><p class="hint"><?= tt('rfo.compare_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th>#</th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><?php foreach ($quoted as $r): ?><th class="num"><?= e($r['supplier']) ?><br><small class="hint"><?= e($r['currency'] ?: $o['currency']) ?></small></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($act as $l): $lid = (int)$l['id']; $best = $cmp['best'][$lid]; ?>
    <tr><td><?= (int)$l['seq'] ?></td><td><?= e($l['item_code'] ? $l['item_code'] . ' · ' : '') . e(mb_substr($l['description'], 0, 60)) ?></td><td class="num"><?= stk_qty((float)$l['qty']) ?></td>
    <?php foreach ($quoted as $r): $q = $Q[(int)$r['id']][$lid] ?? null; $p = $q['unit_price'] ?? null; $win = $p !== null && $best !== null && abs((float)$p - $best) < 0.00005 && count($quoted) > 1; ?><td class="num<?= $win ? ' is-best' : '' ?>"<?= $win ? ' style="background:var(--ok-soft,rgba(46,125,50,.10));font-weight:600;"' : '' ?>><?= $p !== null ? e(number_format((float)$p, 2, ',', '.')) . ($q['lead_days'] !== null ? '<br><small class="hint">' . (int)$q['lead_days'] . ' ' . tt('po.days') . '</small>' : '') : '<span class="hint">—</span>' ?></td><?php endforeach; ?></tr>
  <?php endforeach; ?>
  <tr><td colspan="3" class="num"><b><?= tt('po.net') ?></b></td><?php foreach ($quoted as $r): $t = $cmp['totals'][(int)$r['id']]; ?><td class="num"<?= $cmp['cheapest'] === (int)$r['id'] ? ' style="background:var(--ok-soft,rgba(46,125,50,.10))"' : '' ?>><b><?= e($mq($t['total'], $r['currency'] ?: $o['currency'])) ?></b><?= $t['missing'] ? '<br><small style="color:var(--red)">' . e(t('rfo.missing_n', ['n' => $t['missing']])) . '</small>' : '' ?><?= $t['lead'] !== null ? '<br><small class="hint">' . tt('po.lead') . ' ' . (int)$t['lead'] . ' ' . tt('po.days') . '</small>' : '' ?></td><?php endforeach; ?></tr>
  </tbody></table></div>
</div>
<?php endif; ?>

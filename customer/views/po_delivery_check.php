<?php
/** Purchase order › Delivery check (GAS-CP-R97-227): the supplier's delivery note or invoice against the order.  ?p=po_delivery_check&id=<check>  or  &order=<purchase order> to start one. */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $oid = (int)($_GET['order'] ?? 0);
if (!function_exists('pdc_has') || !pdc_has() || !function_exists('pr_wh') || !pr_wh()) { http_response_code(403); layout_head(t('pdc.title'), 'purchase_orders'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$c = $id ? pdc_load($id) : null; if ($c) $oid = (int)$c['order_id'];
$o = $oid ? po_load($oid) : null;
if (!$o || ($id && !$c)) { http_response_code(404); layout_head(t('pdc.title'), 'purchase_orders'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$self = url('po_delivery_check', $c ? ['id' => $id] : ['order' => $oid]);
layout_head(t('pdc.title') . ' · ' . $o['po_no'], 'purchase_orders');
$whs = function_exists('stk_warehouses') ? stk_warehouses() : [];
?>
<p class="eyebrow"><a href="<?= url('purchase_order', ['id' => $oid]) ?>"><?= e($o['po_no']) ?></a> › <?= tt('pdc.title') ?></p>
<h1>📄 <?= tt('pdc.title') ?> <?php if ($c): ?><span style="color:var(--gold-deep);font-family:var(--mono);"><?= e($c['check_no']) ?></span> <?= pdc_pill($c['status']) ?><?php endif; ?></h1>
<p class="lede"><?= e($o['supplier']) ?> · <?= e($o['po_no']) ?><?= $o['sub_code'] ? ' · ' . e($o['sub_code']) : '' ?></p>

<?php if (!$c): ?>
<div class="card" style="max-width:820px;">
  <h2><?= tt('pdc.start') ?></h2><p class="hint"><?= tt('pdc.start_lede') ?></p>
  <?php if (!pdc_can_start($o)): ?><p><?= pill('mute', t('pdc.p_status')) ?></p>
  <?php elseif (!pdc_ai_ok()): ?><p><?= pill('gold', t('pdc.no_ai')) ?></p>
  <?php else: ?>
  <form method="post" action="<?= $self ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pdc_read"><input type="hidden" name="id" value="<?= $oid ?>">
    <div class="grid grid-2"><div class="field"><label><?= tt('pdc.file') ?></label><input type="file" name="file" accept=".pdf,image/jpeg,image/png,image/webp" required><span class="hint"><?= tt('pdc.file_hint') ?></span></div>
    <?php if (count($whs) > 1): ?><div class="field"><label><?= tt('stk.warehouse') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === (int)($o['warehouse_id'] ?: 0) ? ' selected' : '' ?>><?= e($w['code']) ?></option><?php endforeach; ?></select></div><?php endif; ?></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit">✨ <?= tt('pdc.read_btn') ?></button></div>
    <p class="hint"><?= tt('pdc.privacy') ?></p>
  </form>
  <?php endif; ?>
</div>
<?php $prev = pdc_list($oid); if ($prev): ?><div class="card" style="max-width:820px;"><h2><?= tt('pdc.earlier') ?></h2><table class="tbl"><tbody><?php foreach ($prev as $x): ?><tr><td><a href="<?= url('po_delivery_check', ['id' => (int)$x['id']]) ?>"><b><?= e($x['check_no']) ?></b></a></td><td><?= e((string)$x['doc_no']) ?></td><td><?= e(fmt_datetime($x['created_at'])) ?></td><td><?= e((string)$x['created_name']) ?></td><td><?= pdc_pill($x['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php layout_foot(); return; endif; ?>

<?php
$edit = $c['can_edit']; $cnt = ['same' => 0, 'code' => 0, 'component' => 0, 'extra' => 0]; foreach ($c['lines'] as $d) $cnt[$d['finding']] = ($cnt[$d['finding']] ?? 0) + 1;
$onDoc = []; foreach ($c['lines'] as $d) if ($d['po_line_id']) $onDoc[(int)$d['po_line_id']] = true;
$problems = in_array($c['status'], ['draft', 'pending'], true) ? pdc_problems($c) : [];
?>
<?php if ($c['status'] === 'draft' && $c['decided_note']): ?><div class="card" style="border-left:4px solid var(--red);"><b><?= tt('pdc.sent_back') ?></b> <?= e((string)$c['decided_name']) ?> · <?= e(fmt_datetime((string)$c['decided_at'])) ?> — <?= e((string)$c['decided_note']) ?></div><?php endif; ?>
<?php if ($c['status'] === 'booked'): ?><div class="card" style="border-left:4px solid var(--green);"><b><?= tt('pdc.booked_by') ?></b> <?= e((string)$c['decided_name']) ?> · <?= e(fmt_datetime((string)$c['decided_at'])) ?><?= $c['decided_note'] ? ' — ' . e((string)$c['decided_note']) : '' ?> · <a href="<?= url('purchase_order', ['id' => $oid]) ?>#receipts"><?= tt('po.receipts') ?></a></div><?php endif; ?>

<div class="card"><div class="card-head"><h2><?= tt('pdc.report') ?></h2><span class="hint"><?= tt('pdc.report_hint') ?></span></div>
  <p><?= pill('green', $cnt['same'] . ' ' . t('pdc.f_same')) ?> <?= pill('gold', $cnt['code'] . ' ' . t('pdc.f_code')) ?> <?= pill('navy', $cnt['component'] . ' ' . t('pdc.f_component')) ?> <?= pill('bad', $cnt['extra'] . ' ' . t('pdc.f_extra')) ?>
     <?php $miss = 0; foreach ($c['po_lines'] as $l) if (!isset($onDoc[(int)$l['id']]) && (float)$l['open_qty'] > 0.0005) $miss++; ?><?= pill('mute', $miss . ' ' . t('pdc.f_missing')) ?></p>
  <?php if ($c['ai_notes']): ?><p class="hint">✨ <?= e((string)$c['ai_notes']) ?></p><?php endif; ?>
  <?php if ($c['file_id']): ?><p><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => (int)$c['file_id']]) ?>" target="_blank">📎 <?= e((string)($c['file_name'] ?: t('pdc.doc'))) ?></a></p><?php endif; ?>
  <?php if (in_array($c['status'], ['draft', 'pending'], true)): ?>
    <p><?php if ($c['why']): ?><?= pill('gold', t('pdc.needs_proc')) ?> <span class="hint"><?= e(implode(' · ', array_map(fn($w) => t('pdc.w_' . $w), $c['why']))) ?></span><?php else: ?><?= pill('green', t('pdc.plain')) ?><?php endif; ?></p>
    <?php if ($problems): ?><ul class="hint" style="color:var(--red);"><?php foreach (array_slice($problems, 0, 12) as $p): ?><li><?= e($p) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php endif; ?>
</div>

<form method="post" action="<?= $self ?>" data-lock id="pdc-form"><?= csrf_field() ?><input type="hidden" name="action" value="pdc_save"><input type="hidden" name="id" value="<?= $id ?>">
<div class="card"><h2><?= tt('pdc.doc') ?></h2>
  <div class="grid grid-3">
    <div class="field"><label><?= tt('pdc.doc_no') ?></label><input name="doc_no" maxlength="80" value="<?= e((string)$c['doc_no']) ?>"<?= $edit ? '' : ' disabled' ?>></div>
    <div class="field"><label><?= tt('pdc.doc_date') ?></label><input name="doc_date" type="date" value="<?= e((string)$c['doc_date']) ?>"<?= $edit ? '' : ' disabled' ?>></div>
    <div class="field"><label><?= tt('stk.warehouse') ?></label><select name="warehouse_id" data-nosearch<?= $edit ? '' : ' disabled' ?>><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === (int)$c['warehouse_id'] ? ' selected' : '' ?>><?= e($w['code']) ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="field"><label><?= tt('pdc.note') ?></label><input name="note" maxlength="600" value="<?= e((string)$c['note']) ?>"<?= $edit ? '' : ' disabled' ?>></div>
</div>

<div class="card"><div class="card-head"><h2><?= tt('pdc.rows') ?> <span class="count"><?= count($c['lines']) ?></span></h2><span class="hint"><?= tt('pdc.rows_hint') ?></span></div>
<div class="table-scroll"><table class="tbl tbl--wrap" data-nosort><thead><tr><th>#</th><th><?= tt('pdc.c_doc') ?></th><th class="num"><?= tt('pdc.c_docqty') ?></th><th><?= tt('pdc.c_finding') ?></th><th><?= tt('pdc.c_line') ?></th><th><?= tt('pdc.c_action') ?></th><th><?= tt('pdc.c_item') ?></th><th class="num"><?= tt('pdc.c_qty') ?></th></tr></thead><tbody>
<?php foreach ($c['lines'] as $d): $lid = (int)$d['id']; $pl = $d['po_line_id'] ? ($c['po_by_id'][(int)$d['po_line_id']] ?? null) : null; ?>
<tr>
  <td><?= (int)$d['seq'] ?></td>
  <td><b style="font-family:var(--mono);"><?= e((string)$d['doc_code']) ?></b><br><small><?= e((string)$d['doc_desc']) ?></small><?= $d['doc_price'] !== null && function_exists('stk_price_visible') && stk_price_visible() ? '<br><small class="hint">' . e(number_format((float)$d['doc_price'], 2, ',', '.')) . '</small>' : '' ?></td>
  <td class="num"><?= e(stk_qty((float)$d['doc_qty'])) ?> <?= e((string)$d['doc_unit']) ?></td>
  <td><?= pdc_finding_pill((string)$d['finding']) ?><br><small class="hint"><?= e((string)$d['reason']) ?><?= (int)$d['conf'] ? ' · ' . (int)$d['conf'] . '%' : '' ?></small></td>
  <td><?php if ($edit): ?><select name="pl[<?= $lid ?>]" style="max-width:260px;"><option value="0">—</option><?php foreach ($c['po_lines'] as $l): ?><option value="<?= (int)$l['id'] ?>"<?= $pl && (int)$pl['id'] === (int)$l['id'] ? ' selected' : '' ?>>#<?= (int)$l['seq'] ?> <?= e(mb_substr(trim((string)$l['code'] . ' · ' . (string)$l['description']), 0, 60)) ?> (<?= e(stk_qty((float)$l['open_qty'])) ?>)</option><?php endforeach; ?></select>
      <?php elseif ($pl): ?>#<?= (int)$pl['seq'] ?> <?= e((string)$pl['code']) ?><br><small><?= e(mb_substr((string)$pl['description'], 0, 70)) ?></small><?php else: ?>—<?php endif; ?></td>
  <td><?php if ($edit): ?><select name="act[<?= $lid ?>]" data-nosearch><?php foreach (PDC_ACTIONS as $a): ?><option value="<?= $a ?>"<?= $d['action'] === $a ? ' selected' : '' ?>><?= tt('pdc.a_' . $a) ?></option><?php endforeach; ?></select>
        <br><label class="chk"><input type="checkbox" name="rem[<?= $lid ?>]" value="1"<?= $d['remember'] ? ' checked' : '' ?>> <small><?= tt('pdc.remember') ?></small></label>
      <?php else: ?><?= tt('pdc.a_' . $d['action']) ?><?= $d['remember'] ? '<br><small class="hint">' . tt('pdc.remember') . '</small>' : '' ?><?php endif; ?></td>
  <td><?php $ic = (string)($d['item_code'] ?: $d['new_code']); if ($edit): ?><input name="icode[<?= $lid ?>]" maxlength="60" value="<?= e($ic) ?>" placeholder="<?= e((string)$d['doc_code']) ?>" style="width:11em;font-family:var(--mono);"><br><small class="hint"><?= $d['item_id'] ? e(mb_substr((string)$d['item_desc'], 0, 50)) : ($d['new_code'] && in_array($d['action'], ['switch', 'add'], true) ? tt('pdc.new_item') : '') ?></small>
      <?php else: ?><?= $ic !== '' && in_array($d['action'], ['switch', 'add'], true) ? '<span style="font-family:var(--mono);">' . e($ic) . '</span>' . ($d['item_id'] ? '' : ' <small class="hint">' . tt('pdc.new_item') . '</small>') : '—' ?><?php endif; ?></td>
  <td class="num"><?php if ($edit): ?><input name="qty[<?= $lid ?>]" type="number" step="0.001" min="0" value="<?= e(stk_qty((float)$d['qty'])) ?>" style="width:6em;"><?php else: ?><b><?= e(stk_qty((float)$d['qty'])) ?></b><?php endif; ?></td>
</tr>
<?php endforeach; ?></tbody></table></div>
<p class="hint"><?= tt('pdc.actions_help') ?></p>
</div>

<div class="card"><div class="card-head"><h2><?= tt('pdc.order_lines') ?></h2><span class="hint"><?= tt('pdc.order_lines_hint') ?></span></div>
<div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th><?= tt('stk.code') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('po.open_qty') ?></th><th><?= tt('pdc.c_ondoc') ?></th><th><?= tt('pdc.c_close') ?></th></tr></thead><tbody>
<?php foreach ($c['po_lines'] as $l): if ((float)$l['open_qty'] <= 0.0005 && !in_array((int)$l['id'], $c['close'], true)) continue; $on = isset($onDoc[(int)$l['id']]); ?>
<tr><td><?= (int)$l['seq'] ?></td><td style="font-family:var(--mono);"><?= e((string)$l['code']) ?></td><td><?= e(mb_substr((string)$l['description'], 0, 90)) ?></td><td class="num"><?= e(stk_qty((float)$l['open_qty'])) ?> <?= e((string)$l['unit']) ?></td>
  <td><?= $on ? pill('green', t('pdc.on_doc')) : pill('mute', t('pdc.not_on_doc')) ?></td>
  <td><label class="chk"><input type="checkbox" name="close[]" value="<?= (int)$l['id'] ?>"<?= in_array((int)$l['id'], $c['close'], true) ? ' checked' : '' ?><?= $edit ? '' : ' disabled' ?>> <small><?= tt('pdc.close_lbl') ?></small></label></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php if ($edit): ?><div class="btn-row"><button class="btn btn--ghost" type="submit"><?= tt('pdc.save') ?></button><span class="hint"><?= tt('pdc.save_hint') ?></span></div><?php endif; ?>
</form>

<div class="btn-row" style="margin-top:14px;">
<?php if ($c['status'] === 'draft' && $edit): ?>
  <form method="post" action="<?= $self ?>" data-lock data-confirm="<?= e(t($c['why'] ? 'pdc.submit_q_proc' : 'pdc.submit_q_book')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pdc_submit"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold" type="submit"<?= $problems ? ' disabled' : '' ?>><?= $c['why'] ? '➜ ' . tt('pdc.submit_proc') : '📥 ' . tt('pdc.submit_book') ?></button></form>
<?php endif; ?>
<?php if ($c['can_decide']): ?>
  <form method="post" action="<?= $self ?>" data-lock data-confirm="<?= e(t('pdc.approve_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pdc_decide"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="decision" value="approve"><button class="btn btn--gold" type="submit"<?= $problems ? ' disabled' : '' ?>>✓ <?= tt('pdc.approve') ?></button></form>
  <form method="post" action="<?= $self ?>" data-lock data-prompt="<?= e(t('pdc.back_q')) ?>" data-prompt-field="note"><?= csrf_field() ?><input type="hidden" name="action" value="pdc_decide"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="decision" value="back"><input type="hidden" name="note" value=""><button class="btn btn--ghost" type="submit">↩ <?= tt('pdc.back') ?></button></form>
<?php endif; ?>
<?php if ($edit): ?><form method="post" action="<?= $self ?>" data-lock data-confirm="<?= e(t('pdc.cancel_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pdc_cancel"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost" type="submit" style="color:#b3261e;"><?= tt('pdc.cancel') ?></button></form><?php endif; ?>
  <a class="btn btn--ghost" href="<?= url('purchase_order', ['id' => $oid]) ?>"><?= e($o['po_no']) ?></a>
</div>
<?php if ($c['status'] === 'pending' && !$c['can_decide']): ?><p class="hint"><?= tt('pdc.waiting') ?></p><?php endif; ?>
<?php layout_foot();

<?php
/** Delivery note card — lines, movements, PDF, ✉ card, cancel (GAS-CP-R88-001); acceptance by e-mail link, signed copy, transfer between warehouses and the log (GAS-CP-R97-279).  ?p=delivery_note&id=N[&format=pdf] */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $d = function_exists('dn_load') ? dn_load($id) : null;
if (!function_exists('dn_can_view') || !dn_can_view($d)) { http_response_code(403); layout_head(t('dn.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }   // R97-279: also the holder of a warehouse of a transfer, for that note
if (!$d) { http_response_code(404); layout_head(t('err.notfound'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (($_GET['format'] ?? '') === 'pdf') { $pdf = dn_pdf($d); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $d['dn_no'] . '.pdf"'); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: no-store'); echo $pdf; exit; }
$L = dn_lines($id); $wh = dn_wh(); $self = url('delivery_note', ['id' => $id]); $full = dn_can(); $x = dn_x_has(); $isX = $d['kind'] === 'transfer'; $dv = (string)($d['delivery'] ?? 'open'); $live = $d['status'] === 'issued';
$ev = $x ? dn_events($id) : []; $onward = $isX ? dn_onward($id) : []; $rsvN = $isX && $x ? (int) scalar('SELECT COUNT(*) FROM inv_dn_rsv WHERE dn_id = ?', [$id]) : 0;
$people = []; if ($d['company_id'] && function_exists('co_people')) { foreach (co_people((int)$d['company_id']) as $c) if ($c['email']) $people[] = ['name' => (string)$c['full_name'], 'email' => (string)$c['email'], 'role' => (string)($c['position'] ?? ''), 'on' => false]; }
layout_head($d['dn_no'] . ' · ' . t('dn.title'), 'inventory');
?>
<p class="eyebrow"><?php if ($full): ?><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('delivery_notes') ?>"><?= tt('dn.title') ?></a> › <?php endif; ?><?= e($d['dn_no']) ?></p>
<div class="page-head">
  <div><h1><?= $isX ? '🔁' : '🚚' ?> <?= e($d['dn_no']) ?> <?= dn_pill($d['status']) ?> <?= dn_delivery_pill($d) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= dn_kind_icon($d['kind']) ?> <?= e(t('dn.k_' . $d['kind'])) ?> · <?= e(fmt_date($d['issued_at'])) ?><?= $d['project_code'] ? ' · ' . pr_link((int)$d['project_id'], $d['project_code'], $d['project_name']) : '' ?><?= $d['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$d['subproject_id'], 'tab' => 'procurement']) . '">' . e($d['sub_code'] . ' · ' . $d['sub_name']) . '</a>' : '' ?><?= $d['company'] ? ' · <a href="' . url('companies', ['id' => (int)$d['company_id']]) . '">' . e($d['company']) . '</a>' : '' ?><?= $d['vessel'] ? ' · ' . e($d['vessel']) : '' ?><?= $isX ? ' · <b>' . e(dn_wh_label((string)$d['wh_code'], (string)$d['warehouse'], (string)($d['wh_kind'] ?? ''))) . ' → ' . e(dn_wh_label((string)$d['to_wh_code'], (string)$d['to_warehouse'], (string)($d['to_wh_kind'] ?? ''))) . '</b>' : '' ?></p></div>
  <div class="btn-row">
    <a class="btn btn--ghost btn--sm" href="<?= url('delivery_note', ['id' => $id, 'format' => 'pdf']) ?>" target="_blank">PDF</a>
    <?php if ($full && !$isX && function_exists('em_card_html') && $d['status'] === 'issued'): ?><button class="btn btn--gold btn--sm" type="button" data-open-email>✉ <?= tt('em.send') ?></button><?php endif; ?>
    <?php if ($wh && $d['status'] === 'issued'): ?><form method="post" action="<?= $self ?>" data-lock data-prompt="<?= e(t('dn.cancel_q')) ?>" data-prompt-field="note"><?= csrf_field() ?><input type="hidden" name="action" value="dn_cancel"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('dn.cancel') ?></button></form><?php endif; ?>
  </div>
</div>
<div class="grid grid-2">
<div class="card">
  <h2><?= tt('dn.details') ?></h2>
  <div class="kv-grid">
    <div class="kv"><span><?= tt($isX ? 'dn.from_wh' : 'stk.warehouse') ?></span><b><?= e($x ? dn_wh_label((string)($d['wh_code'] ?? ''), (string)($d['warehouse'] ?? ''), (string)($d['wh_kind'] ?? '')) : ($d['warehouse'] ?: '—')) ?></b></div><?php if ($isX): ?><div class="kv"><span><?= tt('stk.to_wh') ?></span><b><?= e(dn_wh_label((string)$d['to_wh_code'], (string)$d['to_warehouse'], (string)($d['to_wh_kind'] ?? ''))) ?></b></div><?php endif; ?><div class="kv"><span><?= tt('po.ref') ?></span><b><?= e($d['ref_no'] ?: '—') ?></b></div>
    <div class="kv"><span><?= tt('po.carrier') ?></span><b><?= e($d['carrier'] ?: '—') ?></b></div><div class="kv"><span><?= tt('dn.attention') ?></span><b><?= e($d['attention'] ?: '—') ?></b></div>
    <div class="kv kv--full"><span><?= tt('dn.address') ?></span><b style="font-weight:400;"><?= implode('<br>', array_map('e', dn_recipient($d))) ?></b></div>
    <?php if ($d['receipt_no']): ?><div class="kv kv--full"><span><?= tt('po.receipt_no') ?></span><b><?= e($d['receipt_no']) ?><?= $d['po_no'] ? ' · <a href="' . url('purchase_order', ['id' => (int)$d['order_id']]) . '">' . e($d['po_no']) . '</a>' : '' ?></b></div><?php endif; ?>
    <?php if ($x && !empty($d['from_dn_no'])): ?><div class="kv kv--full"><span><?= tt('dn.from_dn') ?></span><b><a class="mono" href="<?= url('delivery_note', ['id' => (int)$d['from_dn_id']]) ?>"><?= e((string)$d['from_dn_no']) ?></a></b></div><?php endif; ?>
    <?php if ($onward): ?><div class="kv kv--full"><span><?= tt('dn.onward') ?></span><b><?php foreach ($onward as $o): ?><a class="mono" href="<?= url('delivery_note', ['id' => (int)$o['id']]) ?>"><?= e((string)$o['dn_no']) ?></a> <?= dn_pill((string)$o['status']) ?> <?php endforeach; ?></b></div><?php endif; ?>
    <?php if ($d['note']): ?><div class="kv kv--full"><span><?= tt('prq.note') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($d['note']) ?></b></div><?php endif; ?>
    <div class="kv"><span><?= tt('th.by') ?></span><b><?= e($d['user_name'] ?? '') ?></b></div><div class="kv"><span><?= tt('th.date') ?></span><b><?= e(fmt_datetime($d['created_at'])) ?></b></div>
  </div>
</div>
<div class="card">
  <?php if ($isX): /* R97-279: a transfer — on the way until the destination confirms, then onwards to the project */ ?>
  <h2>🔁 <?= tt('dn.xf_title') ?></h2><p class="hint"><?= tt('dn.xf_hint') ?></p>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('prq.status') ?></span><b><?= $live ? dn_delivery_pill($d) : dn_pill($d['status']) ?></b></div>
    <?php if ($dv === 'received'): ?><div class="kv"><span><?= tt('dn.recv_by') ?></span><b><?= e((string)$d['accepted_name']) ?> · <?= e(fmt_datetime((string)$d['accepted_at'])) ?></b></div><?php if ($d['accepted_remark']): ?><div class="kv kv--full"><span><?= tt('prq.note') ?></span><b style="font-weight:400;white-space:pre-wrap;"><?= e((string)$d['accepted_remark']) ?></b></div><?php endif; endif; ?>
    <?php if (!empty($d['to_holder_id'])): ?><div class="kv"><span><?= tt('stk.wh_holder') ?></span><b><?= e((string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)$d['to_holder_id']])) ?></b></div><?php endif; ?>
    <?php if (!empty($d['to_vehicle'])): ?><div class="kv"><span><?= tt('stk.wh_vehicle') ?></span><b><?= e((string)$d['to_vehicle']) ?></b></div><?php endif; ?>
  </div>
  <?php if (dn_can_receive($d)): ?><form method="post" action="<?= $self ?>" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="dn_receive"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label for="dn_rnote"><?= tt('dn.recv_note') ?></label><input id="dn_rnote" name="note" maxlength="600" placeholder="<?= tt('dn.recv_note_ph') ?>"></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('dn.recv_btn') ?></button></div></form><?php endif; ?>
  <?php if ($live && $wh && $d['project_id']): ?><h3 style="margin-top:14px;">🏗 <?= tt('dn.xf_next') ?></h3><p class="hint"><?= tt($rsvN ? 'dn.xf_next_rsv' : 'dn.xf_next_free') ?></p>
    <div class="btn-row"><?php if ($rsvN && function_exists('whr_rows')): ?><a class="btn btn--gold btn--sm" href="<?= url('warehouse_ready', ['project_id' => (int)$d['project_id']]) ?>">📦 <?= tt('whr.link') ?></a><?php endif; ?><a class="btn <?= $rsvN ? 'btn--ghost' : 'btn--gold' ?> btn--sm" href="<?= url('delivery_notes', ['new' => 1, 'from_dn' => $id]) ?>">🚚 <?= tt('dn.xf_next_btn') ?></a></div><?php endif; ?>
  <?php elseif (!$full): ?><h2><?= tt('prq.flow') ?></h2><p class="hint"><?= tt('dn.flow') ?></p>
  <?php elseif (function_exists('em_card_html') && $d['status'] === 'issued'): ?>
  <h2><?= tt('dn.send_title') ?></h2><p class="hint"><?= tt('dn.send_hint') ?></p>
  <?= em_card_html(['kind' => 'dn', 'kinds' => ['dn' => t('et.k_dn')], 'company_id' => (int)($d['company_id'] ?? 0), 'ctx' => ['no' => $d['dn_no'], 'title' => (string)($d['ref_no'] ?: ''), 'vessel' => (string)($d['vessel'] ?? ''), 'customer' => (string)($d['company'] ?? ''), 'project' => (string)($d['project_code'] ?? ''), 'doc' => t('em.w_dn', ['no' => $d['dn_no']]), 'project_id' => (int)($d['project_id'] ?? 0)], 'people' => $people, 'atts' => [['dn:' . $id, $d['dn_no'] . '.pdf']], 'back' => $self]) ?>
  <?php else: ?><h2><?= tt('prq.flow') ?></h2><p class="hint"><?= tt('dn.flow') ?></p><?php endif; ?>
</div>
</div>
<?php if ($x && !$isX && $full): /* R97-279: where the note stands with its recipient */ ?>
<div class="grid grid-2">
<div class="card" id="dnacc">
  <div class="card-head"><h2>📬 <?= tt('dn.acc_title') ?></h2><span class="hint"><?= tt('dn.acc_hint') ?></span></div>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('prq.status') ?></span><b><?= $live ? (dn_delivery_pill($d) ?: pill('mute', t('dn.d_open'))) : dn_pill($d['status']) ?></b></div>
    <?php if (in_array($dv, ['accepted', 'disputed'], true)): ?><div class="kv"><span><?= tt($dv === 'accepted' ? 'dn.recv_by' : 'dn.acc_answer_by') ?></span><b><?= e(trim((string)$d['accepted_name'] . ($d['accepted_role'] ? ', ' . $d['accepted_role'] : ''))) ?></b></div>
    <div class="kv"><span><?= tt('th.date') ?></span><b><?= e(fmt_datetime((string)$d['accepted_at'])) ?></b></div><div class="kv"><span><?= tt('dn.acc_how') ?></span><b><?= e(t('dn.via_' . ($d['accepted_via'] ?: 'portal'))) ?><?= $d['accepted_email'] ? ' · ' . e((string)$d['accepted_email']) : '' ?></b></div>
    <?php if ($d['accepted_remark']): ?><div class="kv kv--full"><span><?= tt('fsr.remark') ?></span><b style="font-weight:400;white-space:pre-wrap;"><?= e((string)$d['accepted_remark']) ?></b></div><?php endif; ?><?php endif; ?>
  </div>
  <?php if ($live && $dv !== 'accepted'): ?><details style="margin-top:10px;"><summary style="cursor:pointer;">🖊 <?= tt('dn.paper_title') ?></summary><p class="hint"><?= tt('dn.paper_hint') ?></p>
    <form method="post" action="<?= $self ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="dn_paper"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label for="dnp_name"><?= tt('sgn.name') ?> *</label><input id="dnp_name" name="name" required minlength="3" maxlength="90"></div>
      <div class="field"><label for="dnp_role"><?= tt('sgn.role') ?></label><input id="dnp_role" name="role" maxlength="80"></div>
      <div class="field field--full"><label for="dnp_note"><?= tt('prq.note') ?></label><input id="dnp_note" name="note" maxlength="600"></div>
      <div class="span2 btn-row"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt('dn.paper_q') ?>">✓ <?= tt('dn.paper_btn') ?></button></div>
    </form></details><?php endif; ?>
</div>
<?php $dlDoc = 'dn'; $dlId = $id; require __DIR__ . '/_dl_card.php'; ?>
</div>
<?php endif; ?>
<div class="card">
  <h2><?= tt('prq.lines') ?> <span class="count"><?= count($L) ?></span></h2>
  <table class="tbl tbl--wrap"><thead><tr><th>#</th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th><?= tt('stk.moves') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($L as $l): ?><tr><td><?= (int)$l['seq'] ?></td><td><?= $l['item_id'] ? ($full ? '<a href="' . url('inventory_item', ['id' => (int)$l['item_id']]) . '" class="mono">' . e($l['item_code']) . '</a>' : '<span class="mono">' . e($l['item_code']) . '</span>') : '<span class="hint">' . tt('prq.free_text') . '</span>' ?></td><td><?= e($l['description']) ?><?= $l['maker'] || $l['factory_code'] ? '<br><small class="hint">' . e(trim(($l['maker'] ?? '') . ' ' . ($l['factory_code'] ?? ''))) . '</small>' : '' ?></td><td class="num"><b><?= e(stk_qty((float)$l['qty'])) ?></b> <?= e($l['unit']) ?></td><td class="hint"><?= $l['move_id'] ? '↓ #' . (int)$l['move_id'] . (!empty($l['move_in_id']) ? ' · ↑ #' . (int)$l['move_in_id'] : '') : '—' ?><?= $l['pr_line_id'] ? ' · ' . 'PR #' . (int)$l['pr_line_id'] : '' ?><?php if ($l['item_id'] && $l['move_id'] && function_exists('sn_tracked') && sn_tracked((int)$l['item_id'])): $got = sn_of_move((int)$l['move_id']); $need = (int) round((float)$l['qty']) - count($got); $mvW = (int) scalar('SELECT warehouse_id FROM inv_moves WHERE id = ?', [(int)$l['move_id']]); $free = $need > 0 ? sn_in_stock((int)$l['item_id'], $mvW) : []; /* R97-252: the numbers that left on this note */ ?>
    <?= $got ? '<br><span class="mono" style="font-size:12px;color:inherit;">🔢 ' . e(implode(', ', $got)) . '</span>' : '' ?>
    <?php if ($need > 0 && $d['status'] === 'issued' && $full && function_exists('sn_can') && sn_can()): ?><details style="margin-top:4px;" open><summary style="cursor:pointer;color:#9A6B00;">🔢 <?= e(t('sn.to_write', ['n' => $need])) ?></summary><form method="post" action="<?= $self ?>" data-lock style="margin-top:4px;text-align:left;"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $isX ? 'dn_sn_xfer' : 'sn_attach' ?>"><input type="hidden" name="dn_id" value="<?= $id ?>"><input type="hidden" name="move_id" value="<?= (int)$l['move_id'] ?>">
      <?php if ($free): ?><div style="max-height:140px;overflow:auto;border:1px solid #E3E7EE;border-radius:6px;padding:4px 8px;margin-bottom:4px;"><?php foreach ($free as $fs): ?><label class="chk" style="display:block;"><input type="checkbox" name="pick[]" value="<?= e($fs['serial_no']) ?>"> <span class="mono"><?= e($fs['serial_no']) ?></span><?= $fs['asset_tag'] ? ' <span class="hint" style="display:inline;">' . e($fs['asset_tag']) . '</span>' : '' ?></label><?php endforeach; ?></div><?php endif; ?>
      <textarea name="serials" rows="2" data-plain placeholder="<?= tt('sn.numbers_ph') ?>" style="width:100%;min-width:200px;"></textarea><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form></details><?php endif; ?><?php endif; ?></td><td class="hint"><?= e($l['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody><?php if ($L): $dnTot = dn_qty_totals($L); $dnFirst = true; /* R97-278 */ ?><tfoot><?php foreach ($dnTot as $dnU => $dnQ): ?><tr><th colspan="3" style="text-align:right;"><?= $dnFirst ? tt('dn.total_qty') . ' <span class="hint" style="display:inline;font-weight:400;">(' . e(t('dn.total_lines', ['n' => count($L)])) . ')</span>' : '' ?></th><th class="num"><?= e(trim(stk_qty((float)$dnQ) . ' ' . $dnU)) ?></th><th colspan="2"></th></tr><?php $dnFirst = false; endforeach; ?></tfoot><?php endif; ?></table>
</div>
<?php if ($ev): /* R97-279: the log of the note */ $evTone = ['issued' => 'mute', 'sent' => 'gold', 'accepted' => 'green', 'paper' => 'green', 'received' => 'green', 'disputed' => 'bad', 'cancelled' => 'mute']; ?>
<div class="card" id="dnlog">
  <h2>🕓 <?= tt('dn.log') ?> <span class="count"><?= count($ev) ?></span></h2>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('prq.status') ?></th><th><?= tt('th.by') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
    <?php foreach ($ev as $x1): ?><tr><td class="date"><?= e(fmt_datetime((string)$x1['created_at'])) ?></td><td><?= pill($evTone[$x1['event']] ?? 'mute', t('dn.ev_' . $x1['event'])) ?></td><td><?= e((string)($x1['user_name'] ?? '')) ?><?= !$x1['user_id'] && in_array($x1['event'], ['accepted', 'disputed'], true) ? ' <span class="hint" style="display:inline;">(' . tt('dn.via_email') . ')</span>' : '' ?></td><td class="hint" style="white-space:pre-wrap;"><?= e((string)($x1['note'] ?? '')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

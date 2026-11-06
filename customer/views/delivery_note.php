<?php
/** Delivery note card — lines, movements, PDF, ✉ card, cancel (GAS-CP-R88-001).  ?p=delivery_note&id=N[&format=pdf] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('dn_can') || !dn_can()) { http_response_code(403); layout_head(t('dn.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $d = dn_load($id);
if (!$d) { http_response_code(404); layout_head(t('err.notfound'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (($_GET['format'] ?? '') === 'pdf') { $pdf = dn_pdf($d); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $d['dn_no'] . '.pdf"'); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: no-store'); echo $pdf; exit; }
$L = dn_lines($id); $wh = dn_wh(); $self = url('delivery_note', ['id' => $id]);
$people = []; if ($d['company_id'] && function_exists('co_people')) { foreach (co_people((int)$d['company_id']) as $c) if ($c['email']) $people[] = ['name' => (string)$c['full_name'], 'email' => (string)$c['email'], 'role' => (string)($c['position'] ?? ''), 'on' => false]; }
layout_head($d['dn_no'] . ' · ' . t('dn.title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('delivery_notes') ?>"><?= tt('dn.title') ?></a> › <?= e($d['dn_no']) ?></p>
<div class="page-head">
  <div><h1>🚚 <?= e($d['dn_no']) ?> <?= dn_pill($d['status']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= dn_kind_icon($d['kind']) ?> <?= e(t('dn.k_' . $d['kind'])) ?> · <?= e(fmt_date($d['issued_at'])) ?><?= $d['project_code'] ? ' · ' . pr_link((int)$d['project_id'], $d['project_code'], $d['project_name']) : '' ?><?= $d['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$d['subproject_id'], 'tab' => 'procurement']) . '">' . e($d['sub_code'] . ' · ' . $d['sub_name']) . '</a>' : '' ?><?= $d['company'] ? ' · <a href="' . url('companies', ['id' => (int)$d['company_id']]) . '">' . e($d['company']) . '</a>' : '' ?><?= $d['vessel'] ? ' · ' . e($d['vessel']) : '' ?></p></div>
  <div class="btn-row">
    <a class="btn btn--ghost btn--sm" href="<?= url('delivery_note', ['id' => $id, 'format' => 'pdf']) ?>" target="_blank">PDF</a>
    <?php if (function_exists('em_card_html') && $d['status'] === 'issued'): ?><button class="btn btn--gold btn--sm" type="button" data-open-email>✉ <?= tt('em.send') ?></button><?php endif; ?>
    <?php if ($wh && $d['status'] === 'issued'): ?><form method="post" action="<?= $self ?>" data-lock data-prompt="<?= e(t('dn.cancel_q')) ?>" data-prompt-field="note"><?= csrf_field() ?><input type="hidden" name="action" value="dn_cancel"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('dn.cancel') ?></button></form><?php endif; ?>
  </div>
</div>
<div class="grid grid-2">
<div class="card">
  <h2><?= tt('dn.details') ?></h2>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('stk.warehouse') ?></span><b><?= e($d['warehouse'] ?: '—') ?></b></div><div class="kv"><span><?= tt('po.ref') ?></span><b><?= e($d['ref_no'] ?: '—') ?></b></div>
    <div class="kv"><span><?= tt('po.carrier') ?></span><b><?= e($d['carrier'] ?: '—') ?></b></div><div class="kv"><span><?= tt('dn.attention') ?></span><b><?= e($d['attention'] ?: '—') ?></b></div>
    <div class="kv kv--full"><span><?= tt('dn.address') ?></span><b style="font-weight:400;"><?= implode('<br>', array_map('e', dn_recipient($d))) ?></b></div>
    <?php if ($d['receipt_no']): ?><div class="kv kv--full"><span><?= tt('po.receipt_no') ?></span><b><?= e($d['receipt_no']) ?><?= $d['po_no'] ? ' · <a href="' . url('purchase_order', ['id' => (int)$d['order_id']]) . '">' . e($d['po_no']) . '</a>' : '' ?></b></div><?php endif; ?>
    <?php if ($d['note']): ?><div class="kv kv--full"><span><?= tt('prq.note') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($d['note']) ?></b></div><?php endif; ?>
    <div class="kv"><span><?= tt('th.by') ?></span><b><?= e($d['user_name'] ?? '') ?></b></div><div class="kv"><span><?= tt('th.date') ?></span><b><?= e(fmt_datetime($d['created_at'])) ?></b></div>
  </div>
</div>
<div class="card">
  <?php if (function_exists('em_card_html') && $d['status'] === 'issued'): ?>
  <h2><?= tt('dn.send_title') ?></h2><p class="hint"><?= tt('dn.send_hint') ?></p>
  <?= em_card_html(['kind' => 'dn', 'kinds' => ['dn' => t('et.k_dn')], 'company_id' => (int)($d['company_id'] ?? 0), 'ctx' => ['no' => $d['dn_no'], 'title' => (string)($d['ref_no'] ?: ''), 'vessel' => (string)($d['vessel'] ?? ''), 'customer' => (string)($d['company'] ?? ''), 'project' => (string)($d['project_code'] ?? ''), 'doc' => t('em.w_dn', ['no' => $d['dn_no']]), 'project_id' => (int)($d['project_id'] ?? 0)], 'people' => $people, 'atts' => [['dn:' . $id, $d['dn_no'] . '.pdf']], 'back' => $self]) ?>
  <?php else: ?><h2><?= tt('prq.flow') ?></h2><p class="hint"><?= tt('dn.flow') ?></p><?php endif; ?>
</div>
</div>
<div class="card">
  <h2><?= tt('prq.lines') ?> <span class="count"><?= count($L) ?></span></h2>
  <table class="tbl tbl--wrap"><thead><tr><th>#</th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th><?= tt('stk.moves') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($L as $l): ?><tr><td><?= (int)$l['seq'] ?></td><td><?= $l['item_id'] ? '<a href="' . url('inventory_item', ['id' => (int)$l['item_id']]) . '" class="mono">' . e($l['item_code']) . '</a>' : '<span class="hint">' . tt('prq.free_text') . '</span>' ?></td><td><?= e($l['description']) ?><?= $l['maker'] || $l['factory_code'] ? '<br><small class="hint">' . e(trim(($l['maker'] ?? '') . ' ' . ($l['factory_code'] ?? ''))) . '</small>' : '' ?></td><td class="num"><b><?= e(stk_qty((float)$l['qty'])) ?></b> <?= e($l['unit']) ?></td><td class="hint"><?= $l['move_id'] ? '↓ #' . (int)$l['move_id'] : '—' ?><?= $l['pr_line_id'] ? ' · ' . 'PR #' . (int)$l['pr_line_id'] : '' ?></td><td class="hint"><?= e($l['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php layout_foot(); ?>

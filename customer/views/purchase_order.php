<?php
/** Procurement › Purchase order / RFQ — header, lines (inventory picker, approved alternatives), quote entry, PDF, ✉ card, states, log (GAS-CP-R80-003).  ?p=purchase_order&id=N[&kind=po|rfq][&format=pdf] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('po_can') || !po_can()) { http_response_code(403); layout_head(t('po.title'), 'purchase_orders'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $o = $id ? po_load($id) : null;
if ($id && !$o) { http_response_code(404); layout_head(t('err.notfound'), 'purchase_orders'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if ($o && ($_GET['format'] ?? '') === 'pdf') { $pdf = po_pdf($o); header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . $o['po_no'] . '.pdf"'); header('Content-Length: ' . strlen($pdf)); header('Cache-Control: no-store'); echo $pdf; exit; }
$kind = $o ? $o['kind'] : (($_GET['kind'] ?? 'po') === 'rfq' ? 'rfq' : 'po'); $isRfq = $kind === 'rfq'; $admin = po_admin(); $edit = !$o ? $admin : po_editable($o); $priced = stk_price_visible();
$lines = $o ? po_lines($id) : []; $events = $o ? po_events($id) : [];
$sups = sup_list(['status' => 'active']); $whs = stk_warehouses(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $cur = $o['currency'] ?? 'EUR'; $m = fn(?float $v) => po_money($v, $cur);
$projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') OR id = ? ORDER BY project_code DESC LIMIT 400", [(int)($o['project_id'] ?? 0)]);
$subs = all("SELECT sp.id, sp.project_id, sp.code, sp.name FROM subprojects sp WHERE sp.status IN ('active','quoted','onhold') OR sp.id = ? ORDER BY sp.sort_order, sp.code LIMIT 1500", [(int)($o['subproject_id'] ?? 0)]);
$preP = (int)($_GET['project_id'] ?? ($o['project_id'] ?? 0)); $preS = (int)($_GET['subproject_id'] ?? ($o['subproject_id'] ?? 0)); $preSup = (int)($_GET['supplier_id'] ?? ($o['supplier_id'] ?? 0));
$back = url('purchase_orders', ['tab' => $isRfq ? 'rfq' : 'po']); $self = url('purchase_order', ['id' => $id]);
$late = $o && $o['delivery_date'] && in_array($o['status'], PO_OPEN, true) && $o['delivery_date'] < date('Y-m-d');
layout_head(($o ? $o['po_no'] : ($isRfq ? t('po.new_rfq') : t('po.new_po'))) . ' · ' . t('po.title'), 'purchase_orders');
?>
<style>.prq-pick{position:relative}.prq-list{position:absolute;left:0;right:0;top:100%;z-index:20;background:var(--card,#fff);border:1px solid var(--line,#d9dee5);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow:auto}.prq-opt{padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--line,#eef1f5);font-size:.92em}.prq-opt:hover{background:var(--navy-soft,#eef2f8)}.prq-opt small{color:var(--muted,#6b7280);display:block}.alts{margin:6px 0 0;padding:6px 10px;border:1px dashed var(--gold,#c9a227);border-radius:8px;font-size:.9em}.alts button{margin:2px 4px 2px 0}</style>
<p class="eyebrow"><a href="<?= $back ?>"><?= tt('po.title') ?></a> › <?= $o ? e($o['po_no']) : ($isRfq ? tt('po.new_rfq') : tt('po.new_po')) ?></p>
<div class="page-head">
  <div><h1><?= $isRfq ? '📨' : '🛒' ?> <?= $o ? e($o['po_no']) : ($isRfq ? tt('po.new_rfq') : tt('po.new_po')) ?> <?= $o ? po_pill($o['status']) : '' ?><?= $late ? ' ' . pill('bad', t('po.late')) : '' ?></h1>
    <?php if ($o): ?><p class="lede" style="margin-bottom:0;"><a href="<?= url('suppliers', ['id' => (int)$o['supplier_id']]) ?>"><?= e($o['supplier']) ?></a><?= $o['project_id'] ? ' · ' . pr_link((int)$o['project_id'], $o['project_code'], $o['project_name']) : '' ?><?= $o['subproject_id'] ? ' › <a href="' . url('subproject', ['id' => (int)$o['subproject_id'], 'tab' => 'procurement']) . '">' . e($o['sub_code'] . ' · ' . $o['sub_name']) . '</a>' : '' ?><?= $o['title'] ? ' · ' . e($o['title']) : '' ?><?= $o['rfq_id'] ? ' · ' . tt('po.from_rfq_lbl') . ' <a href="' . url('purchase_order', ['id' => (int)$o['rfq_id']]) . '">' . e((string) scalar('SELECT po_no FROM po_orders WHERE id = ?', [(int)$o['rfq_id']])) . '</a>' : '' ?></p><?php endif; ?></div>
  <div class="btn-row">
    <?php if ($o): ?><a class="btn btn--ghost btn--sm" href="<?= url('purchase_order', ['id' => $id, 'format' => 'pdf']) ?>" target="_blank">PDF</a><?php endif; ?>
    <?php if ($o && $admin && in_array($o['status'], ['draft'], true)): ?><form method="post" action="<?= $self ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t($isRfq ? 'po.send_rfq_q' : 'po.send_po_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="po_send"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit"<?= $lines ? '' : ' disabled' ?>>➤ <?= tt('po.mark_sent') ?></button></form><?php endif; ?>
    <?php if ($o && $admin && $isRfq && $o['status'] === 'quoted'): ?><form method="post" action="<?= $self ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('po.award_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="po_award"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit">🏆 <?= tt('po.award') ?></button></form><?php endif; ?>
    <?php if ($o && $admin && in_array($o['status'], ['received', 'partial', 'confirmed', 'sent'], true) && !$isRfq): ?><form method="post" action="<?= $self ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('po.close_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="po_close"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">✓ <?= tt('po.close') ?></button></form><?php endif; ?>
    <?php if ($o && $admin && !in_array($o['status'], ['received', 'closed', 'cancelled'], true)): ?><form method="post" action="<?= $self ?>" data-lock onsubmit="var r=prompt(<?= e(json_encode(t('po.cancel_q'))) ?>);if(r===null||!r.trim())return false;this.note.value=r;return true;"><?= csrf_field() ?><input type="hidden" name="action" value="po_cancel"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('po.cancel') ?></button></form><?php endif; ?>
  </div>
</div>

<?php if ($o && $priced): ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat gold"><b><?= e($m((float)$o['subtotal'])) ?></b><?= tt('po.net') ?><span class="s"><?= (float)$o['vat_pct'] > 0 ? e(t('po.vat_line', ['p' => rtrim(rtrim(number_format((float)$o['vat_pct'], 2, '.', ''), '0'), '.'), 'v' => $m((float)$o['vat_total'])])) : tt('po.no_vat') ?></span></div>
  <div class="stat"><b><?= e($m((float)$o['gross_total'])) ?></b><?= tt('po.gross') ?><span class="s"><?= $o['terms_days'] !== null ? e(t('po.terms_days_v', ['n' => (int)$o['terms_days']])) . ((float)$o['advance_pct'] > 0 ? ' · ' . e(t('po.advance_v', ['p' => rtrim(rtrim(number_format((float)$o['advance_pct'], 2, '.', ''), '0'), '.')])) : '') : tt('po.terms_none') ?></span></div>
  <div class="stat<?= $late ? ' red' : '' ?>"><b><?= fmt_date($o['delivery_date']) ?></b><?= tt('po.delivery') ?><span class="s"><?= e($o['incoterms'] ?: '') ?><?= $o['warehouse'] ? ' · ' . e($o['warehouse']) : '' ?></span></div>
  <div class="stat"><b><?= count(array_filter($lines, fn($l) => $l['status'] !== 'cancelled')) ?></b><?= tt('prq.lines') ?><span class="s"><?= $isRfq ? tt('po.rfq_lines_hint') : e(t('po.received_n', ['n' => count(array_filter($lines, fn($l) => $l['status'] === 'received'))])) ?></span></div>
</div>
<?php endif; ?>

<div class="grid-2">
<div class="card">
  <h2><?= tt('po.header') ?></h2>
  <?php if ($edit): ?>
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="po_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
    <div class="form-grid">
      <div class="field field--full"><label><?= tt('po.supplier') ?> *</label><select name="supplier_id" id="po-sup" required<?= $o && $o['status'] !== 'draft' ? ' disabled' : '' ?>><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>" data-cur="<?= e($s['sup_currency'] ?: '') ?>" data-inc="<?= e($s['incoterms'] ?: '') ?>" data-terms="<?= e((string)($s['terms_days'] ?? '')) ?>" data-adv="<?= e((string)($s['advance_pct'] ?? '')) ?>" data-email="<?= e($s['email'] ?: '') ?>"<?= $sel($s['id'], $preSup) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('prq.project') ?></label><select name="project_id" id="prq-p"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $preP) ?>><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('prq.subproject') ?></label><select name="subproject_id" id="prq-s"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $sel($s['id'], $preS) ?>><?= e(($s['code'] ?: '') . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field field--full"><label><?= tt('prq.r_title') ?></label><input name="title" maxlength="160" value="<?= e($o['title'] ?? '') ?>" placeholder="<?= tt('po.title_ph') ?>"></div>
      <div class="field"><label><?= tt('po.currency') ?></label><input name="currency" id="po-cur" maxlength="3" value="<?= e($o['currency'] ?? 'EUR') ?>" pattern="[A-Z]{3}"></div>
      <div class="field"><label><?= tt('po.vat') ?> %</label><input name="vat_pct" type="number" step="0.01" min="0" max="30" value="<?= e((string)($o['vat_pct'] ?? '0')) ?>"></div>
      <div class="field"><label><?= tt('po.incoterms') ?></label><select name="incoterms" id="po-inc"><option value="">—</option><?php foreach (PO_INCOTERMS as $i): ?><option value="<?= $i ?>"<?= $sel($i, $o['incoterms'] ?? '') ?>><?= $i ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('po.ship_to') ?></label><select name="warehouse_id"><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $sel($w['id'], $o['warehouse_id'] ?? stk_default_wh()) ?>><?= e($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('po.terms') ?> (<?= tt('po.days') ?>)</label><input name="terms_days" id="po-terms" type="number" min="0" max="365" value="<?= e((string)($o['terms_days'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('po.advance') ?> %</label><input name="advance_pct" id="po-adv" type="number" step="0.01" min="0" max="100" value="<?= e((string)($o['advance_pct'] ?? '')) ?>"></div>
      <div class="field"><label><?= $isRfq ? tt('po.quote_by') : tt('po.delivery') ?></label><input name="delivery_date" type="date" value="<?= e($o['delivery_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('po.supplier_ref') ?></label><input name="supplier_ref" maxlength="80" value="<?= e($o['supplier_ref'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('po.contact') ?></label><input name="supplier_contact" maxlength="120" value="<?= e($o['supplier_contact'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('po.email') ?></label><input name="supplier_email" id="po-email" type="email" maxlength="190" value="<?= e($o['supplier_email'] ?? '') ?>"></div>
      <div class="field field--full"><label><?= tt('po.note_supplier') ?></label><textarea name="note" rows="2"><?= e($o['note'] ?? '') ?></textarea></div>
      <div class="field field--full"><label><?= tt('po.note_internal') ?></label><textarea name="internal_note" rows="2"><?= e($o['internal_note'] ?? '') ?></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= $o ? tt('prq.save') : tt('po.create') ?></button><?php if (!$o): ?><span class="hint"><?= tt('po.create_hint') ?></span><?php endif; ?></div>
  </form>
  <script>(function(){var p=document.getElementById('prq-p'),s=document.getElementById('prq-s');function f(){var v=p.value;Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;var on=!v||o.getAttribute('data-p')===v;o.hidden=!on;o.disabled=!on;});if(s.selectedOptions[0]&&s.selectedOptions[0].disabled)s.value='';}p.addEventListener('change',f);f();
  var sup=document.getElementById('po-sup');if(sup&&!<?= $o ? 'true' : 'false' ?>){sup.addEventListener('change',function(){var o=sup.selectedOptions[0];if(!o||!o.value)return;var g=function(id,v){var el=document.getElementById(id);if(el&&v)el.value=v;};g('po-cur',o.dataset.cur);g('po-inc',o.dataset.inc);g('po-terms',o.dataset.terms);g('po-adv',o.dataset.adv);g('po-email',o.dataset.email);});}})();</script>
  <?php else: ?>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('po.currency') ?></span><b><?= e($o['currency']) ?><?= (float)$o['vat_pct'] > 0 ? ' · ' . tt('po.vat') . ' ' . e(rtrim(rtrim(number_format((float)$o['vat_pct'], 2, '.', ''), '0'), '.')) . '%' : '' ?></b></div><div class="kv"><span><?= tt('po.incoterms') ?></span><b><?= e($o['incoterms'] ?: '—') ?></b></div>
    <div class="kv"><span><?= tt('po.terms') ?></span><b><?= $o['terms_days'] !== null ? e(t('po.terms_days_v', ['n' => (int)$o['terms_days']])) : '—' ?><?= (float)$o['advance_pct'] > 0 ? ' · ' . e(t('po.advance_v', ['p' => rtrim(rtrim(number_format((float)$o['advance_pct'], 2, '.', ''), '0'), '.')])) : '' ?></b></div><div class="kv"><span><?= tt('po.ship_to') ?></span><b><?= e($o['warehouse'] ?: '—') ?></b></div>
    <div class="kv"><span><?= tt('po.contact') ?></span><b><?= e($o['supplier_contact'] ?: '—') ?><?= $o['supplier_email'] ? ' · ' . e($o['supplier_email']) : '' ?></b></div><div class="kv"><span><?= tt('po.supplier_ref') ?></span><b><?= e($o['supplier_ref'] ?: '—') ?></b></div>
    <div class="kv"><span><?= tt('po.sent') ?></span><b><?= fmt_datetime($o['sent_at']) ?><?= $o['sent_to'] ? '<br><small class="hint">' . e($o['sent_to']) . '</small>' : '' ?></b></div><div class="kv"><span><?= tt('po.confirmed') ?></span><b><?= fmt_datetime($o['confirmed_at']) ?></b></div>
    <?php if ($o['note']): ?><div class="kv kv--full"><span><?= tt('po.note_supplier') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($o['note']) ?></b></div><?php endif; ?>
    <?php if ($o['internal_note']): ?><div class="kv kv--full"><span><?= tt('po.note_internal') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($o['internal_note']) ?></b></div><?php endif; ?>
  </div>
  <?php if ($admin && $o['status'] === 'sent' && !$isRfq): ?>
  <h3 style="margin-top:12px;"><?= tt('po.confirm_title') ?></h3>
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="po_confirm"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-grid"><div class="field"><label><?= tt('po.supplier_ref') ?></label><input name="supplier_ref" maxlength="80" value="<?= e($o['supplier_ref'] ?? '') ?>"></div><div class="field"><label><?= tt('po.delivery') ?></label><input name="delivery_date" type="date" value="<?= e($o['delivery_date'] ?? '') ?>"></div><div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" maxlength="400"></div></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">✓ <?= tt('po.confirm') ?></button></div>
  </form>
  <?php endif; ?>
  <?php endif; ?>
</div>

<div class="card">
  <?php if ($o && function_exists('em_card_html') && $admin && in_array($o['status'], ['draft', 'sent', 'quoted', 'confirmed', 'partial'], true)): ?>
    <h2><?= tt('po.send_title') ?></h2>
    <p class="hint"><?= $o['status'] === 'draft' ? tt('po.send_hint_draft') : tt('po.send_hint') ?></p>
    <?= em_card_html(['kind' => 'po', 'ctx' => ['no' => $o['po_no'], 'title' => (string)($o['title'] ?: ''), 'vessel' => '', 'customer' => (string)$o['supplier'], 'project' => (string)($o['project_code'] ?? ''), 'doc' => t($isRfq ? 'em.w_rfq' : 'em.w_po', ['no' => $o['po_no']]), 'project_id' => (int)($o['project_id'] ?? 0)], 'people' => po_people($o), 'atts' => [['po:' . $id, $o['po_no'] . '.pdf']], 'back' => $self, 'hidden' => '<input type="hidden" name="po_id" value="' . $id . '">']) ?>
  <?php else: ?>
    <h2><?= tt('prq.flow') ?></h2>
    <p class="hint"><?= $isRfq ? tt('po.flow_rfq') : tt('po.flow_po') ?></p>
  <?php endif; ?>
</div>
</div>

<?php if ($o): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('prq.lines') ?> <span class="hint" style="font-weight:400;">(<?= count($lines) ?>)</span></h2></div>
    <?php if ($edit && $o['status'] === 'draft'): $tb = po_to_buy($o['subproject_id'] ? ['subproject_id' => (int)$o['subproject_id']] : ($o['project_id'] ? ['project_id' => (int)$o['project_id']] : [])); $have = array_filter(array_column($lines, 'pr_line_id')); $tb = array_filter($tb, fn($r) => !in_array((int)$r['id'], array_map('intval', $have), true)); if ($tb): ?>
    <form method="post" action="<?= $self ?>" data-lock class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="po_pull"><input type="hidden" name="id" value="<?= $id ?>"><select name="pr_line_id"><?php foreach ($tb as $r): ?><option value="<?= (int)$r['id'] ?>"><?= e($r['pr_no'] . ' #' . $r['seq'] . ' · ' . mb_substr($r['description'], 0, 50) . ' × ' . stk_qty((float)$r['open_qty'])) ?></option><?php endforeach; ?></select><button class="btn btn--ghost btn--sm" type="submit">⤵ <?= tt('po.pull_line') ?></button></form>
    <?php endif; endif; ?></div>
  <?php if ($isRfq && $admin && in_array($o['status'], ['sent', 'quoted'], true)): ?><form method="post" action="<?= $self ?>" data-lock id="quote-form"><?= csrf_field() ?><input type="hidden" name="action" value="po_quote"><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <table class="tbl tbl--wrap">
    <thead><tr><th>#</th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><?php if ($priced || $isRfq): ?><th class="num"><?= tt('po.unit_price') ?></th><th class="num"><?= tt('po.disc') ?></th><th class="num"><?= tt('po.line_total') ?></th><?php endif; ?><th><?= tt('po.lead') ?></th><th><?= tt('prq.need') ?></th><?php if (!$isRfq): ?><th class="num"><?= tt('po.received') ?></th><?php endif; ?><th><?= tt('prq.status') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="13" class="hint"><?= tt('po.no_lines') ?></td></tr><?php endif; ?>
    <?php foreach ($lines as $l): $quoting = $isRfq && $admin && in_array($o['status'], ['sent', 'quoted'], true) && $l['status'] !== 'cancelled'; ?>
      <tr<?= $l['status'] === 'cancelled' ? ' style="opacity:.5"' : '' ?>>
        <td><?= (int)$l['seq'] ?></td>
        <td><?php if ($l['item_id']): ?><a href="<?= url('inventory_item', ['id' => (int)$l['item_id']]) ?>" style="font-family:var(--mono);"><?= e($l['item_code']) ?></a><?php else: ?><span class="hint"><?= tt('prq.free_text') ?></span><?php endif; ?><?= $l['alt_of_code'] ? '<br><small class="hint">' . e(t('po.alt_of', ['c' => $l['alt_of_code']])) . '</small>' : '' ?><?= $l['pr_no'] ? '<br><small><a href="' . url('requisition', ['id' => (int)$l['pr_id']]) . '">' . e($l['pr_no']) . '</a></small>' : '' ?></td>
        <td><?= e($l['description']) ?><?= $l['maker'] || $l['part_no'] ? '<br><small class="hint">' . e(trim(($l['maker'] ?? '') . ' ' . ($l['part_no'] ?? ''))) . '</small>' : '' ?><?= $l['note'] ? '<br><small class="hint">' . e($l['note']) . '</small>' : '' ?></td>
        <td class="num"><?= stk_qty((float)$l['qty']) ?> <?= e($l['unit']) ?></td>
        <?php if ($priced || $isRfq): ?>
        <td class="num"><?php if ($quoting): ?><input name="price[<?= (int)$l['id'] ?>]" type="number" step="0.0001" min="0" value="<?= e($l['unit_price'] !== null ? (string)(float)$l['unit_price'] : '') ?>" style="width:7em;"><?php else: ?><?= $l['unit_price'] !== null ? e(number_format((float)$l['unit_price'], 4, ',', '.')) : '—' ?><?= $l['price_origin'] ? '<br><small class="hint">' . e($l['price_origin']) . '</small>' : '' ?><?php endif; ?></td>
        <td class="num"><?= (float)$l['discount_pct'] > 0 ? e(rtrim(rtrim(number_format((float)$l['discount_pct'], 2, '.', ''), '0'), '.')) . '%' : '—' ?></td>
        <td class="num"><?= $l['line_total'] !== null ? e($m((float)$l['line_total'])) : '—' ?></td>
        <?php endif; ?>
        <td><?php if ($quoting): ?><input name="lead[<?= (int)$l['id'] ?>]" type="number" min="0" max="999" value="<?= e((string)($l['lead_days'] ?? '')) ?>" style="width:4.5em;"> <?= tt('po.days') ?><?php else: ?><?= $l['lead_days'] !== null ? (int)$l['lead_days'] . ' ' . tt('po.days') : '—' ?><?php endif; ?></td>
        <td><?= fmt_date($l['need_date']) ?></td>
        <?php if (!$isRfq): ?><td class="num"><?= (float)$l['qty_received'] > 0 ? stk_qty((float)$l['qty_received']) . ((float)$l['qty_accepted'] < (float)$l['qty_received'] ? ' <small class="hint">(' . stk_qty((float)$l['qty_accepted']) . ' ✓)</small>' : '') : '—' ?></td><?php endif; ?>
        <td><?= pill(match ($l['status']) { 'received' => 'ok', 'partial' => 'gold', 'cancelled' => 'mute', default => 'navy' }, t('po.l_' . $l['status'])) ?></td>
        <td class="num" style="white-space:nowrap;"><?php if ($edit): ?><button class="btn btn--ghost btn--sm" type="button" data-edit-line='<?= e(json_encode(['id' => (int)$l['id'], 'item_id' => (int)$l['item_id'], 'alt_of' => (int)$l['alt_of_item_id'], 'code' => $l['item_code'], 'description' => $l['description'], 'maker' => $l['maker'], 'part_no' => $l['part_no'], 'unit' => $l['unit'], 'qty' => (float)$l['qty'], 'unit_price' => $l['unit_price'], 'discount_pct' => (float)$l['discount_pct'], 'lead_days' => $l['lead_days'], 'need_date' => $l['need_date'], 'note' => $l['note']])) ?>'>✎</button><form method="post" action="<?= $self ?>" style="display:inline" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.del_line_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="po_line_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($isRfq && $admin && in_array($o['status'], ['sent', 'quoted'], true)): ?>
    <div class="form-grid" style="margin-top:10px;"><div class="field"><label><?= tt('po.supplier_ref') ?></label><input name="supplier_ref" maxlength="80" value="<?= e($o['supplier_ref'] ?? '') ?>"></div><div class="field"><label><?= tt('po.delivery') ?></label><input name="delivery_date" type="date" value="<?= e($o['delivery_date'] ?? '') ?>"></div><div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" maxlength="400"></div></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">💾 <?= tt('po.save_quote') ?></button><span class="hint"><?= tt('po.quote_hint') ?></span></div>
  </form>
  <?php endif; ?>

  <?php if ($edit): ?>
  <h3 id="line-form-title" style="margin-top:14px;"><?= tt('prq.add_line') ?></h3>
  <form method="post" action="<?= $self ?>" id="line-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="po_line_save"><input type="hidden" name="order_id" value="<?= $id ?>"><input type="hidden" name="line_id" id="lf-id" value="0"><input type="hidden" name="item_id" id="lf-item" value=""><input type="hidden" name="alt_of_item_id" id="lf-altof" value="">
    <div class="form-grid">
      <div class="field field--full prq-pick"><label><?= tt('prq.item') ?> <span class="hint"><?= tt('prq.item_hint') ?></span></label><input id="lf-q" autocomplete="off" placeholder="<?= tt('prq.item_ph') ?>"><div id="lf-list" class="prq-list is-hidden"></div><div id="lf-chosen" class="hint"></div><div id="lf-alts" class="alts is-hidden"></div></div>
      <div class="field field--full"><label><?= tt('prq.desc') ?> *</label><input name="description" id="lf-desc" maxlength="255" required></div>
      <div class="field"><label><?= tt('stk.maker') ?></label><input name="maker" id="lf-maker" maxlength="80"></div>
      <div class="field"><label><?= tt('stk.factory_code') ?></label><input name="part_no" id="lf-pn" maxlength="80"></div>
      <div class="field"><label><?= tt('prq.qty') ?> *</label><input name="qty" id="lf-qty" type="number" step="0.001" min="0.001" required></div>
      <div class="field"><label><?= tt('stk.unit') ?></label><select name="unit" id="lf-unit"><?php foreach (STK_UNITS as $un): ?><option value="<?= $un ?>"><?= $un ?></option><?php endforeach; ?></select></div>
      <?php if (!$isRfq): ?><div class="field"><label><?= tt('po.unit_price') ?> <span class="hint"><?= tt('po.price_hint') ?></span></label><input name="unit_price" id="lf-price" type="number" step="0.0001" min="0"></div>
      <div class="field"><label><?= tt('po.disc') ?> %</label><input name="discount_pct" id="lf-disc" type="number" step="0.01" min="0" max="100" value="0"></div><?php endif; ?>
      <div class="field"><label><?= tt('po.lead') ?> (<?= tt('po.days') ?>)</label><input name="lead_days" id="lf-lead" type="number" min="0" max="999"></div>
      <div class="field"><label><?= tt('prq.need') ?></label><input name="need_date" id="lf-need" type="date"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" id="lf-note" maxlength="255"></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit" id="lf-btn"><?= tt('prq.add_line') ?></button><button class="btn btn--ghost btn--sm" type="button" id="lf-reset"><?= tt('prq.clear') ?></button></div>
  </form>
  <script>
  (function(){
    var $=function(i){return document.getElementById(i);},q=$('lf-q'),list=$('lf-list'),ch=$('lf-chosen'),item=$('lf-item'),alt=$('lf-altof'),alts=$('lf-alts'),desc=$('lf-desc'),mk=$('lf-maker'),pn=$('lf-pn'),un=$('lf-unit'),pr=$('lf-price'),t=null,url=<?= json_encode(url('prq_items')) ?>,ALT=<?= json_encode(t('po.alts_lbl')) ?>,FREE=<?= json_encode(t('prq.free')) ?>;
    function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function choose(it,altOf){item.value=it.id;alt.value=altOf||'';desc.value=it.desc||'';mk.value=it.maker||'';pn.value=it.pn||'';if(un)un.value=it.unit||'pcs';if(pr)pr.value='';q.value=it.code;ch.textContent=it.code+' · '+(it.desc||'')+' · '+FREE+' '+it.free;list.classList.add('is-hidden');mk.readOnly=pn.readOnly=true;if(!altOf&&it.alts&&it.alts.length){alts.innerHTML='<b>'+ALT+'</b> ';it.alts.forEach(function(a){var b=document.createElement('button');b.type='button';b.className='btn btn--ghost btn--sm';b.textContent=(a.code||a.desc)+(a.maker?' · '+a.maker:'');b.title=a.note||'';b.addEventListener('click',function(){if(a.id){choose({id:a.id,code:a.code,desc:a.desc,maker:a.maker,pn:a.pn,unit:a.unit,free:a.free},it.id);}else{item.value='';alt.value=it.id;q.value='';desc.value=a.desc||'';mk.value=a.maker||'';pn.value=a.pn||'';mk.readOnly=pn.readOnly=false;ch.textContent=a.desc+' ('+ALT.toLowerCase()+' '+it.code+')';alts.classList.add('is-hidden');}});alts.appendChild(b);});alts.classList.remove('is-hidden');}else alts.classList.add('is-hidden');}
    function clear(){item.value='';alt.value='';ch.textContent='';alts.classList.add('is-hidden');mk.readOnly=pn.readOnly=false;}
    q.addEventListener('input',function(){clear();var v=q.value.trim();clearTimeout(t);if(v.length<2){list.classList.add('is-hidden');return;}t=setTimeout(function(){fetch(url+'&alts=1&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(rows){list.innerHTML='';if(!rows.length){list.classList.add('is-hidden');return;}rows.forEach(function(it){var d=document.createElement('div');d.className='prq-opt';d.innerHTML='<b>'+esc(it.code)+'</b> '+esc(it.desc)+' <small>'+esc(it.maker||'')+' '+esc(it.pn||'')+' · '+FREE+' '+it.free+' '+esc(it.unit)+(it.price!=null?' · '+it.price+' '+esc(it.cur):'')+(it.alts&&it.alts.length?' · '+ALT+' '+it.alts.length:'')+'</small>';d.addEventListener('click',function(){choose(it);});list.appendChild(d);});list.classList.remove('is-hidden');}).catch(function(){});},220);});
    document.addEventListener('click',function(e){if(!list.contains(e.target)&&e.target!==q)list.classList.add('is-hidden');});
    document.querySelectorAll('[data-edit-line]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.getAttribute('data-edit-line'));$('lf-id').value=d.id;item.value=d.item_id||'';alt.value=d.alt_of||'';q.value=d.code||'';desc.value=d.description||'';mk.value=d.maker||'';pn.value=d.part_no||'';if(un)un.value=d.unit||'pcs';$('lf-qty').value=d.qty;if(pr)pr.value=d.unit_price==null?'':d.unit_price;if($('lf-disc'))$('lf-disc').value=d.discount_pct||0;$('lf-lead').value=d.lead_days==null?'':d.lead_days;$('lf-need').value=d.need_date||'';$('lf-note').value=d.note||'';ch.textContent=d.code||'';mk.readOnly=pn.readOnly=!!d.item_id;$('lf-btn').textContent=<?= json_encode(t('prq.save_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.edit_line')) ?>+' #'+d.id;$('line-form').scrollIntoView({behavior:'smooth',block:'center'});});});
    $('lf-reset').addEventListener('click',function(){$('line-form').reset();$('lf-id').value='0';clear();q.value='';$('lf-btn').textContent=<?= json_encode(t('prq.add_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.add_line')) ?>;});
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('prq.log') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('prq.when') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('prq.event') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td><?= fmt_datetime($ev['created_at']) ?></td><td><?= e($ev['user_name'] ?? '') ?></td><td><?= tt('po.ev_' . $ev['event']) ?></td><td><?= e($ev['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php layout_foot();

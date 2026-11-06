<?php
/** Accounting › Supplier invoice / credit note — header, lines with the three-way check, check / dispute, payments, log (GAS-CP-R81-001).  ?p=payable&id=N[&kind=credit][&order_id=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('si_can') || !si_can()) { http_response_code(403); layout_head(t('si.title'), 'payables'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $i = $id ? si_load($id) : null;
if ($id && !$i) { http_response_code(404); layout_head(t('err.notfound'), 'payables'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$kind = $i ? $i['kind'] : (($_GET['kind'] ?? 'invoice') === 'credit' ? 'credit' : 'invoice'); $isCredit = $kind === 'credit'; $edit = $i ? si_editable($i) : true; $pay = si_pay_can();
$lines = $i ? si_lines($id) : []; $pays = $i ? si_payments($id) : []; $events = $i ? si_events($id) : [];
$sups = sup_list(['status' => 'active']); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $cur = $i['currency'] ?? 'EUR'; $m = fn(?float $v) => si_money($v, $cur);
$preO = (int)($_GET['order_id'] ?? ($i['order_id'] ?? 0)); $PO = $preO ? po_load($preO) : null;
$pos = all("SELECT o.id, o.po_no, o.supplier_id, co.name AS supplier, o.title FROM po_orders o JOIN companies co ON co.id = o.supplier_id WHERE o.kind = 'po' AND o.status IN ('sent','confirmed','partial','received','closed') ORDER BY o.id DESC LIMIT 400");
$self = url('payable', ['id' => $id]); $back = url('payables'); $open = $i ? round((float)$i['gross'] - (float)$i['paid'], 2) : 0.0;
$openLines = $i && $i['order_id'] && $edit ? si_po_open_lines((int)$i['order_id']) : [];
$credits = $isCredit && !$i ? all("SELECT id, inv_no, si_no, supplier_id FROM si_invoices WHERE kind = 'invoice' AND status <> 'cancelled' ORDER BY id DESC LIMIT 300") : [];
layout_head(($i ? $i['si_no'] : ($isCredit ? t('si.new_credit') : t('si.new'))) . ' · ' . t('si.title'), 'payables');
?>
<p class="eyebrow"><a href="<?= $back ?>"><?= tt('si.title') ?></a> › <?= $i ? e($i['si_no']) : ($isCredit ? tt('si.new_credit') : tt('si.new')) ?></p>
<div class="page-head">
  <div><h1>💸 <?= $i ? e($i['si_no']) : ($isCredit ? tt('si.new_credit') : tt('si.new')) ?> <?= $i ? si_pill($i['status']) : '' ?><?= $i ? ' ' . si_m_pill($i['match_status']) : '' ?><?= $isCredit ? ' ' . pill('gold', t('si.credit')) : '' ?></h1>
    <?php if ($i): ?><p class="lede" style="margin-bottom:0;"><a href="<?= url('suppliers', ['id' => (int)$i['supplier_id']]) ?>"><?= e($i['supplier']) ?></a> · <?= tt('si.inv_no') ?> <b><?= e($i['inv_no']) ?></b><?= $i['order_id'] ? ' · <a href="' . url('purchase_order', ['id' => (int)$i['order_id']]) . '">' . e($i['po_no']) . '</a>' : '' ?><?= $i['project_id'] ? ' · ' . pr_link((int)$i['project_id'], $i['project_code'], $i['project_name']) . ($i['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$i['subproject_id'], 'tab' => 'procurement']) . '">' . e($i['sub_code']) . '</a>' : '') : '' ?><?= $i['credit_of'] ? ' · ' . tt('si.credit_of') . ' <a href="' . url('payable', ['id' => (int)$i['credit_of']]) . '">' . e((string) scalar('SELECT inv_no FROM si_invoices WHERE id = ?', [(int)$i['credit_of']])) . '</a>' : '' ?><?= $i['file_id'] ? ' · <a href="' . url('file', ['id' => (int)$i['file_id']]) . '" target="_blank">📎 ' . tt('si.document') . '</a>' : '' ?></p><?php endif; ?></div>
  <div class="btn-row">
    <?php if ($i && in_array($i['status'], ['draft', 'disputed'], true)): ?><form method="post" action="<?= $self ?>" data-lock class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="si_check"><input type="hidden" name="id" value="<?= $id ?>"><?php if ($i['status'] === 'disputed'): ?><input name="note" maxlength="400" placeholder="<?= tt('si.force_reason') ?>" style="width:14em;"><label class="chk"><input type="checkbox" name="force" value="1"> <?= tt('si.force') ?></label><?php endif; ?><button class="btn btn--gold btn--sm" type="submit"<?= $lines ? '' : ' disabled' ?>>✓ <?= tt('si.check') ?></button></form><?php endif; ?>
    <?php if ($i && $i['status'] === 'checked'): ?><form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="si_reopen"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('si.reopen') ?></button></form><?php endif; ?>
    <?php if ($i && !in_array($i['status'], ['paid', 'cancelled'], true) && (float)$i['paid'] <= 0): ?><form method="post" action="<?= $self ?>" data-lock onsubmit="var r=prompt(<?= e(json_encode(t('po.cancel_q'))) ?>);if(r===null||!r.trim())return false;this.note.value=r;return true;"><?= csrf_field() ?><input type="hidden" name="action" value="si_cancel"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('po.cancel') ?></button></form><?php endif; ?>
  </div>
</div>

<?php if ($i): ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= e($m((float)$i['net'])) ?></b><?= tt('po.net') ?><span class="s"><?= (float)$i['vat_pct'] > 0 ? e(t('po.vat_line', ['p' => rtrim(rtrim(number_format((float)$i['vat_pct'], 2, '.', ''), '0'), '.'), 'v' => $m((float)$i['vat'])])) : tt('po.no_vat') ?></span></div>
  <div class="stat gold"><b><?= e($m((float)$i['gross'])) ?></b><?= tt('po.gross') ?><span class="s"><?= tt('si.issue') ?> <?= fmt_date($i['issue_date']) ?></span></div>
  <div class="stat<?= $open > 0.005 && $i['due_date'] < date('Y-m-d') && !in_array($i['status'], ['paid', 'cancelled', 'draft'], true) ? ' red' : '' ?>"><b><?= e($m($open)) ?></b><?= tt('si.open') ?><span class="s"><?= tt('si.due') ?> <?= fmt_date($i['due_date']) ?></span></div>
  <div class="stat<?= in_array($i['match_status'], ['qty', 'price', 'both'], true) ? ' red' : ($i['match_status'] === 'ok' ? ' ok' : '') ?>"><b><?= $i['match_status'] === 'none' ? '—' : tt('si.m_' . $i['match_status']) ?></b><?= tt('si.match') ?><span class="s"><?= $i['order_id'] ? e(t('si.match_hint', ['p' => SI_TOL_PCT])) : tt('si.no_po') ?></span></div>
</div>
<?php endif; ?>

<div class="grid grid-2">
<div class="card">
  <h2><?= tt('si.header') ?></h2>
  <?php if ($edit): ?>
  <form method="post" action="<?= $self ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="si_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="kind" value="<?= $kind ?>">
    <div class="form-grid">
      <div class="field field--full"><label><?= tt('po.one') ?> <span class="hint"><?= tt('si.po_hint') ?></span></label><select name="order_id" id="si-po"<?= $i && $i['order_id'] ? ' disabled' : '' ?>><option value="">—</option><?php foreach ($pos as $o): ?><option value="<?= (int)$o['id'] ?>" data-sup="<?= (int)$o['supplier_id'] ?>"<?= $sel($o['id'], $preO) ?>><?= e($o['po_no'] . ' · ' . $o['supplier'] . ($o['title'] ? ' · ' . $o['title'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field field--full"><label><?= tt('po.supplier') ?> *</label><select name="supplier_id" id="si-sup" required><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $PO ? $PO['supplier_id'] : ($i['supplier_id'] ?? 0)) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('si.inv_no') ?> *</label><input name="inv_no" maxlength="80" required value="<?= e($i['inv_no'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('si.issue') ?> *</label><input name="issue_date" type="date" required value="<?= e($i['issue_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('si.due') ?> <span class="hint"><?= tt('si.due_hint') ?></span></label><input name="due_date" type="date" value="<?= e($i['due_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('si.received_at') ?></label><input name="received_at" type="date" value="<?= e($i['received_at'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('po.currency') ?></label><input name="currency" maxlength="3" pattern="[A-Z]{3}" value="<?= e($i['currency'] ?? ($PO['currency'] ?? 'EUR')) ?>"></div>
      <div class="field"><label><?= tt('po.vat') ?> %</label><input name="vat_pct" type="number" step="0.01" min="0" max="30" value="<?= e((string)($i['vat_pct'] ?? ($PO['vat_pct'] ?? '24'))) ?>"></div>
      <?php if ($isCredit && !$i): ?><div class="field field--full"><label><?= tt('si.credit_of') ?></label><select name="credit_of"><option value="">—</option><?php foreach ($credits as $c): ?><option value="<?= (int)$c['id'] ?>" data-sup="<?= (int)$c['supplier_id'] ?>"><?= e($c['si_no'] . ' · ' . $c['inv_no']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field field--full"><label><?= tt('si.document') ?> <span class="hint">(PDF)</span></label><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2"><?= e($i['note'] ?? '') ?></textarea></div>
      <?php if (!$i): ?><div class="field field--full"><label class="chk"><input type="checkbox" name="pull_lines" value="1" checked> <?= tt('si.pull_lines') ?></label></div><?php endif; ?>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= $i ? tt('prq.save') : tt('si.register') ?></button></div>
  </form>
  <script>(function(){var po=document.getElementById('si-po'),sup=document.getElementById('si-sup');if(!po||!sup)return;function f(){var o=po.selectedOptions[0];if(o&&o.value){sup.value=o.dataset.sup;sup.readOnly=true;}}po.addEventListener('change',f);})();</script>
  <?php else: ?>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('si.received_at') ?></span><b><?= fmt_date($i['received_at']) ?></b></div><div class="kv"><span><?= tt('si.registered_by') ?></span><b><?= e($i['created_name'] ?? '') ?></b></div>
    <div class="kv"><span><?= tt('si.checked_by') ?></span><b><?= $i['checked_at'] ? e($i['checked_name']) . ' · ' . fmt_datetime($i['checked_at']) : '—' ?></b></div><div class="kv"><span><?= tt('po.currency') ?></span><b><?= e($i['currency']) ?></b></div>
    <?php if ($i['note']): ?><div class="kv kv--full"><span><?= tt('prq.note') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($i['note']) ?></b></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>💶 <?= tt('si.payments') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('si.date') ?></th><th class="num"><?= tt('po.amount') ?></th><th><?= tt('si.method') ?></th><th><?= tt('si.ref') ?></th><th><?= tt('prq.who') ?></th><th></th></tr></thead><tbody>
  <?php if (!$pays): ?><tr><td colspan="6" class="hint"><?= tt('si.no_payments') ?></td></tr><?php endif; ?>
  <?php foreach ($pays as $p): ?><tr><td><?= fmt_date($p['pay_date']) ?></td><td class="num"><?= e(si_money((float)$p['amount'], $p['currency'])) ?></td><td><?= tt('si.pm_' . $p['method']) ?></td><td><?= e($p['ref'] ?? '') ?><?= $p['file_id'] ? ' <a href="' . url('file', ['id' => (int)$p['file_id']]) . '" target="_blank">📎</a>' : '' ?><?= $p['note'] ? '<br><small class="hint">' . e($p['note']) . '</small>' : '' ?></td><td><?= e($p['user_name'] ?? '') ?></td><td class="num"><?php if ($pay): ?><form method="post" action="<?= $self ?>" style="display:inline" data-lock onsubmit="return confirm(<?= e(json_encode(t('si.unpay_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="si_unpay"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($i && (float)$i['paid'] > 0 && function_exists('em_card_html')): $sp = function_exists('po_people') ? po_people(['supplier_email' => null, 'supplier_contact' => null, 'supplier' => $i['supplier'], 'supplier_id' => (int)$i['supplier_id'], 'supplier_co_email' => scalar('SELECT email FROM companies WHERE id = ?', [(int)$i['supplier_id']])]) : []; ?>
  <div style="margin-top:10px;"><?= em_card_html(['kind' => 'remittance', 'company_id' => (int)$i['supplier_id'], 'ctx' => ['no' => $i['si_no'], 'title' => '', 'vessel' => '', 'customer' => (string)$i['supplier'], 'project' => (string)($i['project_code'] ?? ''), 'doc' => t('si.w_invoice', ['no' => (string)$i['inv_no']]), 'project_id' => (int)($i['project_id'] ?? 0)], 'people' => $sp, 'atts' => [], 'back' => $self]) ?></div>
  <?php endif; ?>
  <?php if ($i && $pay && in_array($i['status'], ['approved', 'checked', 'requested'], true) && $open > 0.005): ?>
  <p class="hint" style="margin-top:8px;"><?= $i['status'] === 'approved' ? tt('si.pay_hint_ok') : tt('si.pay_hint_direct') ?></p>
  <form method="post" action="<?= $self ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="si_pay"><input type="hidden" name="invoice_id" value="<?= $id ?>">
    <div class="form-grid">
      <div class="field"><label><?= tt('si.date') ?></label><input name="pay_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label><?= tt('po.amount') ?> (<?= e($cur) ?>)</label><input name="amount" type="number" step="0.01" min="0.01" max="<?= $open ?>" value="<?= $open ?>" required></div>
      <div class="field"><label><?= tt('si.method') ?></label><select name="method"><?php foreach (SI_METHODS as $pm): ?><option value="<?= $pm ?>"><?= tt('si.pm_' . $pm) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('si.ref') ?></label><input name="ref" maxlength="80" placeholder="<?= tt('si.ref_ph') ?>"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" maxlength="255"></div>
      <div class="field field--full"><label><?= tt('si.pay_doc') ?></label><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">💶 <?= tt('si.record_payment') ?></button></div>
  </form>
  <?php elseif ($i && in_array($i['status'], ['checked'], true)): ?><p class="hint" style="margin-top:8px;"><?= tt('si.request_first') ?> <a href="<?= url('payables', ['supplier_id' => (int)$i['supplier_id'], 'status' => 'checked']) ?>"><?= tt('si.invoices') ?> →</a></p><?php endif; ?>
</div>
</div>

<?php if ($i): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('prq.lines') ?> <span class="hint" style="font-weight:400;">(<?= count($lines) ?>)</span></h2><p class="hint"><?= tt('si.lines_lede') ?></p></div></div>
  <table class="tbl tbl--wrap">
    <thead><tr><th>#</th><th><?= tt('prq.desc') ?></th><th><?= tt('si.po_line') ?></th><th class="num"><?= tt('prq.qty') ?></th><th class="num"><?= tt('po.unit_price') ?></th><th class="num"><?= tt('po.line_total') ?></th><th class="num"><?= tt('si.po_open') ?></th><th class="num"><?= tt('si.po_price') ?></th><th><?= tt('si.match') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="10" class="hint"><?= tt('si.no_lines') ?></td></tr><?php endif; ?>
    <?php foreach ($lines as $l): ?>
      <tr><td><?= (int)$l['seq'] ?></td><td><?= e($l['description']) ?></td><td><?= $l['po_line_id'] ? '#' . (int)$l['po_seq'] . ' <small class="hint">' . e(mb_substr((string)$l['po_desc'], 0, 40)) . '</small>' : '<span class="hint">—</span>' ?></td>
        <td class="num"><?= stk_qty((float)$l['qty']) ?></td><td class="num"><?= e(number_format((float)$l['unit_price'], 4, ',', '.')) ?></td><td class="num"><?= e($m((float)$l['line_total'])) ?></td>
        <td class="num"><?= $l['po_qty_open'] !== null ? stk_qty((float)$l['po_qty_open']) : ($l['po_line_id'] ? stk_qty((float)$l['qty_accepted'] - (float)$l['qty_invoiced']) : '—') ?></td><td class="num"><?= $l['po_price'] !== null ? e(number_format((float)$l['po_price'], 4, ',', '.')) : ($l['po_line_id'] && $l['po_unit_price'] !== null ? e(number_format((float)$l['po_unit_price'] * (1 - (float)$l['discount_pct'] / 100), 4, ',', '.')) : '—') ?></td>
        <td><?= si_m_pill($l['match_status']) ?></td>
        <td class="num" style="white-space:nowrap;"><?php if ($edit): ?><button class="btn btn--ghost btn--sm" type="button" data-edit-line='<?= e(json_encode(['id' => (int)$l['id'], 'po_line_id' => (int)$l['po_line_id'], 'description' => $l['description'], 'qty' => (float)$l['qty'], 'unit_price' => (float)$l['unit_price']])) ?>'>✎</button><form method="post" action="<?= $self ?>" style="display:inline" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.del_line_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="si_line_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($edit): ?>
  <div class="btn-row" style="margin-top:8px;"><?php if ($i['order_id']): ?><form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="si_pull"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">⤵ <?= tt('si.pull_lines_btn') ?></button></form><?php endif; ?></div>
  <h3 id="line-form-title" style="margin-top:10px;"><?= tt('prq.add_line') ?></h3>
  <form method="post" action="<?= $self ?>" id="line-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="si_line_save"><input type="hidden" name="invoice_id" value="<?= $id ?>"><input type="hidden" name="line_id" id="lf-id" value="0">
    <div class="form-grid">
      <?php if ($openLines): ?><div class="field field--full"><label><?= tt('si.po_line') ?></label><select name="po_line_id" id="lf-pol"><option value="">— <?= tt('si.no_po_line') ?> —</option><?php foreach ($openLines as $ol): ?><option value="<?= (int)$ol['id'] ?>" data-desc="<?= e($ol['description']) ?>" data-qty="<?= (float)$ol['open_qty'] ?>" data-price="<?= round((float)($ol['unit_price'] ?? 0) * (1 - (float)$ol['discount_pct'] / 100), 4) ?>">#<?= (int)$ol['seq'] ?> · <?= e(mb_substr($ol['description'], 0, 50)) ?> · <?= tt('si.po_open') ?> <?= stk_qty((float)$ol['open_qty']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field field--full"><label><?= tt('prq.desc') ?> *</label><input name="description" id="lf-desc" maxlength="255" required></div>
      <div class="field"><label><?= tt('prq.qty') ?> *</label><input name="qty" id="lf-qty" type="number" step="0.001" min="0.001" value="1" required></div>
      <div class="field"><label><?= tt('po.unit_price') ?> *</label><input name="unit_price" id="lf-price" type="number" step="0.0001" min="0" required></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit" id="lf-btn"><?= tt('prq.add_line') ?></button><button class="btn btn--ghost btn--sm" type="button" id="lf-reset"><?= tt('prq.clear') ?></button></div>
  </form>
  <script>(function(){var $=function(i){return document.getElementById(i);},pol=$('lf-pol');if(pol)pol.addEventListener('change',function(){var o=pol.selectedOptions[0];if(!o||!o.value)return;$('lf-desc').value=o.dataset.desc;$('lf-qty').value=o.dataset.qty;$('lf-price').value=o.dataset.price;});
  document.querySelectorAll('[data-edit-line]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.getAttribute('data-edit-line'));$('lf-id').value=d.id;if(pol)pol.value=d.po_line_id||'';$('lf-desc').value=d.description;$('lf-qty').value=d.qty;$('lf-price').value=d.unit_price;$('lf-btn').textContent=<?= json_encode(t('prq.save_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.edit_line')) ?>+' #'+d.id;$('line-form').scrollIntoView({behavior:'smooth',block:'center'});});});
  $('lf-reset').addEventListener('click',function(){$('line-form').reset();$('lf-id').value='0';$('lf-btn').textContent=<?= json_encode(t('prq.add_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.add_line')) ?>;});})();</script>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('prq.log') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('prq.when') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('prq.event') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td><?= fmt_datetime($ev['created_at']) ?></td><td><?= e($ev['user_name'] ?? '') ?></td><td><?= tt('si.ev_' . $ev['event']) ?></td><td><?= e($ev['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php layout_foot();

<?php
/** Procurement › Purchase orders — POs, RFQs and the "to buy" basket of approved requisition lines (GAS-CP-R80-003).  ?p=purchase_orders[&tab=po|rfq|tobuy&status=&q=&supplier_id=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('po_can') || !po_can()) { http_response_code(403); layout_head(t('po.title'), 'purchase_orders'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$tab = in_array($_GET['tab'] ?? '', ['po', 'pipe', 'rfq', 'tobuy'], true) ? $_GET['tab'] : 'po'; $admin = po_admin(); $priced = stk_price_visible();
$f = ['kind' => $tab === 'rfq' ? 'rfq' : 'po', 'status' => (string)($_GET['status'] ?? (isset($_GET['status']) ? '' : 'open')), 'q' => trim((string)($_GET['q'] ?? '')), 'supplier_id' => (int)($_GET['supplier_id'] ?? 0), 'purpose' => (string)($_GET['purpose'] ?? ''), 'vessel_id' => (int)($_GET['vessel_id'] ?? 0)];
$cnt = po_counts(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$sups = sup_list(['status' => 'active']); $keep = array_filter(['tab' => $tab, 'status' => $f['status'], 'q' => $f['q'], 'supplier_id' => $f['supplier_id'], 'purpose' => $f['purpose'], 'vessel_id' => $f['vessel_id']]); $vessels = po_vessels();
layout_head(t('po.title'), 'purchase_orders');
?>
<div class="page-head">
  <div><h1>🛒 <?= tt('po.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('po.lede') ?></p></div>
  <div class="btn-row"><?php if ($admin): ?><a class="btn btn--gold btn--sm" href="<?= url('purchase_order', ['id' => 0, 'kind' => 'po']) ?>">+ <?= tt('po.new_po') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('purchase_order', ['id' => 0, 'kind' => 'rfq']) ?>">+ <?= tt('po.new_rfq') ?></a><?php endif; ?></div>
</div>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <a class="stat" href="<?= url('purchase_orders', ['tab' => 'tobuy']) ?>"><b><?= count(po_to_buy()) ?></b><?= tt('po.k_tobuy') ?><span class="s"><?= tt('po.k_tobuy_hint') ?></span></a>
  <a class="stat<?= $cnt['draft'] ? ' gold' : '' ?>" href="<?= url('purchase_orders', ['tab' => 'po', 'status' => 'draft']) ?>"><b><?= $cnt['draft'] ?></b><?= tt('po.k_draft') ?><span class="s"><?= tt('po.k_draft_hint') ?></span></a>
  <a class="stat" href="<?= url('purchase_orders', ['tab' => 'po', 'status' => 'open']) ?>"><b><?= $cnt['open'] ?></b><?= tt('po.k_open') ?><span class="s"><?= $cnt['late'] ? '<span style="color:var(--red)">' . e(t('po.k_late', ['n' => $cnt['late']])) . '</span>' : tt('po.k_open_hint') ?></span></a>
  <a class="stat" href="<?= url('purchase_orders', ['tab' => 'rfq']) ?>"><b><?= $cnt['rfq'] ?></b><?= tt('po.k_rfq') ?><span class="s"><?= tt('po.k_rfq_hint') ?></span></a>
</div>
<nav class="tabs" style="margin-bottom:12px;"><?php if (function_exists('po_expected_backfill')) po_expected_backfill();   /* R97-144: once */ $carry = array_filter(['supplier_id' => $f['supplier_id'], 'vessel_id' => $f['vessel_id'], 'purpose' => $f['purpose'], 'q' => $f['q']]); /* R97-141: supplier, vessel, purpose and search follow to the other tab */ $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('purchase_orders', ['tab' => $k] + ($k === 'tobuy' ? [] : $carry)) . '">' . $lbl . '</a>'; echo $T('po', '🛒 ' . t('po.pos')) . $T('pipe', '▦ ' . t('pof.pipe')) . $T('rfq', '📨 ' . t('po.rfqs')) . $T('tobuy', '🧺 ' . t('po.tobuy')); ?></nav>

<?php if ($tab === 'pipe'): /* R97-133: the orders by the stage they wait in */ $PF = array_filter(['supplier_id' => $f['supplier_id'], 'q' => $f['q'], 'purpose' => $f['purpose'], 'vessel_id' => $f['vessel_id']]); $PP = po_pipeline($PF); $nAll = array_sum(array_map('count', $PP['stages'])); ?>
<form method="get" action="index.php" class="btn-row" style="margin-bottom:10px;"><input type="hidden" name="p" value="purchase_orders"><input type="hidden" name="tab" value="pipe">
  <select name="supplier_id" data-search><option value=""><?= tt('po.supplier') ?> — <?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $f['supplier_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
  <select name="vessel_id" data-search><option value=""><?= tt('po.vessel') ?> — <?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $sel($v['id'], $f['vessel_id']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select>
  <input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('po.search_ph') ?>" style="min-width:220px;"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('purchase_orders', ['tab' => 'pipe', 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a>
  <span class="hint"><?= e(t('pof.pipe_sum', ['n' => $nAll])) ?></span>
  <?= $PP['late'] ? pill('bad', t('pof.f_late') . ' · ' . $PP['late']) : '' ?> <?= $PP['overdue'] ? pill('bad', t('pof.f_overdue') . ' · ' . $PP['overdue']) : '' ?> <?= $PP['waiting'] ? pill('warn', t('pof.f_waiting') . ' · ' . $PP['waiting']) : '' ?></form>
<div class="pp-board">
<?php foreach (PO_PIPE as $sk): $list = $PP['stages'][$sk]; $sum = array_sum(array_map(fn($r) => (float)$r['subtotal'], $list)); ?>
  <div class="pp-col">
    <div class="pp-colhead"><b><?= tt('pof.p_' . $sk) ?></b> <b><?= count($list) ?></b><span class="hint"><?= tt('pof.p_' . $sk . '_h') ?><?= $priced && $list ? ' · ' . e(po_money($sum, (string)($list[0]['currency'] ?? 'EUR'))) : '' ?></span></div>
    <?php if (!$list): ?><p class="hint" style="text-align:center;">—</p><?php endif; ?>
    <?php foreach ($list as $r): $exp = ($r['expected_date'] ?? null) ?: $r['delivery_date']; $bad = (bool) array_intersect($r['flags'], ['late', 'overdue']); ?>
    <div class="pp-card" style="<?= $bad ? 'border-left:4px solid #B3261E;' : (in_array('waiting', $r['flags'], true) ? 'border-left:4px solid #C9A227;' : '') ?>">
      <div class="pp-top"><a href="<?= url('purchase_order', ['id' => (int)$r['id']]) ?>"><b style="font-family:var(--mono);"><?= e($r['po_no']) ?></b></a><?= $priced ? '<span class="hint">' . e(po_money((float)$r['subtotal'], $r['currency'])) . '</span>' : '' ?></div>
      <div><?= e($r['supplier']) ?></div>
      <?php if (!empty($r['vessel_name'])): ?><div class="hint" style="font-weight:600;">⚓ <?= e($r['vessel_name']) ?></div><?php endif; ?>
      <div class="hint"><?= $r['project_id'] ? e((string)$r['project_code']) : (function_exists('po_purpose_pill') ? strip_tags(po_purpose_pill($r)) : '') ?><?= $r['title'] ? ' · ' . e(mb_strimwidth((string)$r['title'], 0, 40, '…')) : '' ?></div>
      <div class="hint"<?= in_array('late', $r['flags'], true) ? ' style="color:#B3261E;font-weight:600;"' : '' ?>><?= $exp ? '📅 ' . e(fmt_date($exp)) : '' ?></div>
      <?php if (!isset($polP)) $polP = function_exists('pol_of') ? pol_of(array_merge([], ...array_values(array_map(fn($x) => array_column($x, 'id'), $PP['stages'])))) : []; if (!empty($polP[(int)$r['id']])): ?><div class="hint"><?= pol_brief($polP[(int)$r['id']]) ?></div><?php endif; /* R97-146 */ ?>
      <div style="margin-top:6px;"><?= hrf_html($r['flow'], 'mini') ?></div>
      <?php if ($r['flags']): ?><div style="margin-top:4px;"><?php foreach ($r['flags'] as $fl): ?><?= pill(in_array($fl, ['late', 'overdue'], true) ? 'bad' : ($fl === 'prepaid' ? 'navy' : 'warn'), t('pof.f_' . $fl)) ?> <?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
</div>
<p class="hint" style="margin-top:8px;"><?= tt('pof.pipe_hint') ?> <?= tt('pof.legend') ?></p>
<?php elseif ($tab === 'tobuy'): $rows = po_to_buy(); ?>
<div class="card">
  <p class="hint"><?= tt('po.tobuy_lede') ?></p>
  <form method="post" action="<?= url('purchase_order', ['id' => 0]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="po_save"><input type="hidden" name="id" value="0">
  <table class="tbl tbl--wrap">
    <thead><tr><th></th><th><?= tt('prq.no') ?></th><th><?= tt('prq.project') ?></th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('po.open_qty') ?></th><th><?= tt('prq.need') ?></th><th><?= tt('po.suggested') ?></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="8" class="hint"><?= tt('po.tobuy_none') ?></td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $sug = $r['preferred_supplier_id'] ?: ($r['last_supplier_id'] ?: ($r['maker_supplier_id'] ?? 0)); ?>
      <tr><td><input type="checkbox" name="pr_line[]" value="<?= (int)$r['id'] ?>" data-sup="<?= (int)$sug ?>" data-p="<?= (int)$r['project_id'] ?>" data-s="<?= (int)$r['subproject_id'] ?>"></td>
        <td><a href="<?= url('requisition', ['id' => (int)$r['request_id']]) ?>" style="font-family:var(--mono);"><?= e($r['pr_no']) ?></a></td>
        <td><?= e($r['project_code'] . ($r['sub_code'] ? ' · ' . $r['sub_code'] : '')) ?><?= !empty($r['vessel_name']) ? '<br><small>⚓ ' . e($r['vessel_name']) . '</small>' : '' ?></td>
        <td><?= $r['item_id'] ? '<a href="' . url('inventory_item', ['id' => (int)$r['item_id']]) . '" style="font-family:var(--mono);">' . e($r['item_code']) . '</a>' : '<span class="hint">' . tt('prq.free_text') . '</span>' ?></td>
        <td><?= e($r['description']) ?><?= $r['maker'] || $r['part_no'] ? '<br><small class="hint">' . e(trim(($r['maker'] ?? '') . ' ' . ($r['part_no'] ?? ''))) . '</small>' : '' ?></td>
        <td class="num"><?= stk_qty((float)$r['open_qty']) ?> <?= e($r['unit']) ?><?= (float)$r['qty_ordered'] > 0 ? '<br><small class="hint">' . e(t('po.already_ordered', ['q' => stk_qty((float)$r['qty_ordered'])])) . '</small>' : '' ?></td>
        <td><?= fmt_date($r['need_date'] ?: $r['r_need']) ?></td>
        <td><?= e($r['preferred_supplier'] ?: $r['last_supplier'] ?: ($r['maker_supplier'] ?? '—')) ?><?= !$r['preferred_supplier'] && !$r['last_supplier'] && !empty($r['maker_supplier']) ? ' <small class="hint">(' . e($r['item_maker'] ?: $r['maker']) . ')</small>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <?php if ($rows && $admin): ?>
  <div class="form-grid" style="margin-top:10px;">
    <div class="field"><label><?= tt('po.kind') ?></label><select name="kind"><option value="po"><?= tt('po.one') ?></option><option value="rfq"><?= tt('po.rfq_one') ?></option></select></div>
    <div class="field"><label><?= tt('po.supplier') ?> *</label><select name="supplier_id" id="tb-sup" required><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('prq.project') ?></label><input type="hidden" name="project_id" id="tb-p"><input type="hidden" name="subproject_id" id="tb-s"><span class="hint" id="tb-hint"><?= tt('po.tobuy_hint') ?></span></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit" id="tb-btn" disabled>➤ <?= tt('po.create_from') ?></button></div>
  </div>
  <script nonce="<?= csp_nonce() ?>">(function(){var cbs=document.querySelectorAll('input[name="pr_line[]"]'),sup=document.getElementById('tb-sup'),btn=document.getElementById('tb-btn');function upd(){var on=[].filter.call(cbs,function(c){return c.checked;});btn.disabled=!on.length;if(!on.length)return;var ps={},ss={},sups={};on.forEach(function(c){ps[c.dataset.p]=1;ss[c.dataset.s]=1;if(c.dataset.sup!=='0')sups[c.dataset.sup]=1;});var pk=Object.keys(ps),sk=Object.keys(ss),sk2=Object.keys(sups);document.getElementById('tb-p').value=pk.length===1?pk[0]:'';document.getElementById('tb-s').value=(pk.length===1&&sk.length===1)?sk[0]:'';if(!sup.value&&sk2.length===1)sup.value=sk2[0];document.getElementById('tb-hint').textContent=pk.length===1?<?= json_encode(t('po.tobuy_one_project')) ?>:<?= json_encode(t('po.tobuy_many_projects')) ?>;}cbs.forEach(function(c){c.addEventListener('change',upd);});upd();})();</script>
  <?php endif; ?>
  </form>
</div>

<?php else: $rows = po_list($f); [$rows, $pg] = page_list($rows, 'purchase_orders', $keep); ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1.2fr 1.2fr 1.6fr auto;">
  <input type="hidden" name="p" value="purchase_orders"><input type="hidden" name="tab" value="<?= $tab ?>">
  <div class="field"><label><?= tt('prq.status') ?></label><select name="status"><option value=""<?= $sel($f['status'], '') ?>><?= tt('prq.all') ?></option><option value="open"<?= $sel($f['status'], 'open') ?>><?= tt('po.f_open') ?></option><?php foreach (PO_STATES as $s): if ($tab === 'po' && in_array($s, ['quoted', 'awarded'], true)) continue; if ($tab === 'rfq' && in_array($s, ['confirmed', 'partial', 'received', 'closed'], true)) continue; ?><option value="<?= $s ?>"<?= $sel($f['status'], $s) ?>><?= tt('po.s_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('po.purpose') ?></label><select name="purpose" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php if (function_exists('po_purpose_has') && po_purpose_has()) foreach (PO_PURPOSE as $pu): ?><option value="<?= $pu ?>"<?= $sel($pu, $f['purpose']) ?>><?= tt('po.pu_' . $pu) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('po.supplier') ?></label><select name="supplier_id" data-search><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $f['supplier_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('po.vessel') ?></label><select name="vessel_id" data-search><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $sel($v['id'], $f['vessel_id']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('po.search_ph') ?>"></div>
  <div class="field"><label>&nbsp;</label><div class="btn-row" style="flex-wrap:nowrap;"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('purchase_orders', ['tab' => $tab, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div></div>
</form>
<?= function_exists('flt_hint') ? flt_hint('purchase_orders', ['tab' => $tab]) : '' ?>
<div class="card">
<table class="tbl tbl--wrap">
  <thead><tr><th><?= tt('po.no') ?></th><th><?= tt('po.supplier') ?></th><th><?= tt('prq.project') ?></th><th><?= tt('po.delivery') ?></th><th class="num"><?= tt('prq.lines') ?></th><?php if ($priced): ?><th class="num"><?= tt('po.net') ?></th><?php endif; ?><th><?= tt('pof.col') ?></th><th><?= tt('prq.status') ?></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="hint"><?= tt('po.none') ?></td></tr><?php endif; ?>
  <?php $flowD = function_exists('po_flow_data') ? po_flow_data(array_column($rows, 'id')) : [];   /* R97-132 */ ?>
  <?php foreach ($rows as $r): $exp = $r['expected_date'] ?? $r['delivery_date']; $late = $exp && in_array($r['status'], PO_OPEN, true) && $exp < date('Y-m-d'); ?>
    <tr>
      <td><a href="<?= url('purchase_order', ['id' => (int)$r['id']]) ?>" style="font-family:var(--mono);"><?= e($r['po_no']) ?></a><?= $r['title'] ? '<br><small class="hint">' . e($r['title']) . '</small>' : '' ?></td>
      <td><a href="<?= url('suppliers', ['id' => (int)$r['supplier_id']]) ?>"><?= e($r['supplier']) ?></a><?php if ($tab === 'rfq' && function_exists('rfo_has') && rfo_has() && ($nS = (int) scalar('SELECT COUNT(*) FROM po_rfq_suppliers WHERE rfq_id = ?', [(int)$r['id']])) > 1) echo ' <span class="pill pill--navy" title="', e(t('rfo.title')), '">+', $nS - 1, '</span>'; ?><?= $r['supplier_ref'] ? '<br><small class="hint">' . e($r['supplier_ref']) . '</small>' : '' ?></td>
      <td><?= $r['project_id'] ? pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) : (function_exists('po_purpose_pill') && po_purpose_pill($r) !== '' ? po_purpose_pill($r) : '—') ?><?= $r['subproject_id'] ? '<br><small><a href="' . url('subproject', ['id' => (int)$r['subproject_id'], 'tab' => 'procurement']) . '">' . e($r['sub_code'] . ' · ' . $r['sub_name']) . '</a></small>' : '' ?><?= !empty($r['vessel_name']) ? '<br><small><a href="' . url('purchase_orders', ['tab' => $tab, 'status' => $f['status'], 'vessel_id' => (int)$r['vessel_id']]) . '" title="' . e(t('po.vessel_only')) . '" style="color:inherit;font-weight:600;">⚓ ' . e($r['vessel_name']) . '</a></small>' : '' ?></td>
      <?php if (!isset($polL)) $polL = function_exists('pol_of') ? pol_of(array_column($rows, 'id')) : []; if (!empty($polL[(int)$r['id']])): /* R97-146 */ ?><td><?= pol_cell($polL[(int)$r['id']], $exp, in_array($r['status'], PO_OPEN, true)) ?></td><?php else: ?><td<?= $late ? ' style="color:var(--red)"' : '' ?>><?= fmt_date($exp) ?></td><?php endif; ?>
      <td class="num"><?= (int)$r['n_lines'] ?><?= (int)$r['n_open'] && in_array($r['status'], ['partial'], true) ? ' <span class="pill pill--gold">' . (int)$r['n_open'] . '</span>' : '' ?></td>
      <?php if ($priced): ?><td class="num"><?= e(po_money((float)$r['subtotal'], $r['currency'])) ?><?php if ((float)($r['logistics_total'] ?? 0) != 0.0): /* R97-069 */ ?><br><small class="hint" title="<?= tt('po.pay_total_long') ?>">+ <?= e(po_money((float)$r['logistics_total'], $r['currency'])) ?> 🚚 · <?= e(po_money((float)$r['gross_total'] + (float)$r['logistics_total'], $r['currency'])) ?></small><?php endif; ?></td><?php endif; ?>
      <td><?= function_exists('po_flow') && function_exists('hrf_html') ? hrf_html(po_flow($r, $flowD[(int)$r['id']] ?? []), true) : '' ?></td>
      <td><?= po_pill($r['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php if (!empty($rows) && function_exists('po_flow')): ?><p class="hint" style="margin:8px 0 0;"><?= tt('pof.legend') ?></p><?php endif; ?>
<?= $pg ?>
</div>
<?php endif; ?>
<?php if (function_exists('procdel_card')) echo procdel_card(['po', 'rfq']);   // R97-105 ?>
<?php layout_foot();

<?php
/** Accounting › Payables — supplier invoices, payment requests, aging, supplier statement (GAS-CP-R81-001).  ?p=payables[&tab=invoices|requests|aging|statement&status=&supplier_id=&q=&id=&from=&to=&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('si_can') || !si_can()) { http_response_code(403); layout_head(t('si.title'), 'payables'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$tab = in_array($_GET['tab'] ?? '', ['invoices', 'requests', 'aging', 'statement'], true) ? $_GET['tab'] : 'invoices';
$cnt = si_counts(); $sups = sup_list(['status' => 'all']); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $pay = si_pay_can(); $mgmt = function_exists('mgmt_can') && mgmt_can();
$f = ['status' => (string)($_GET['status'] ?? (isset($_GET['status']) ? '' : 'open')), 'supplier_id' => (int)($_GET['supplier_id'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
layout_head(t('si.title'), 'payables');
?>
<div class="page-head">
  <div><h1>💸 <?= tt('si.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('si.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('payable', ['id' => 0]) ?>">+ <?= tt('si.new') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('payable', ['id' => 0, 'kind' => 'credit']) ?>">+ <?= tt('si.new_credit') ?></a></div>
</div>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <a class="stat gold" href="<?= url('payables', ['status' => 'open']) ?>"><b><?= e(si_money($cnt['open_sum'])) ?></b><?= tt('si.k_open') ?><span class="s"><?= e(t('si.k_open_n', ['n' => $cnt['open']])) ?></span></a>
  <a class="stat<?= $cnt['overdue'] ? ' red' : '' ?>" href="<?= url('payables', ['status' => 'overdue']) ?>"><b><?= $cnt['overdue'] ?></b><?= tt('si.k_overdue') ?><span class="s"><?= tt('si.k_overdue_hint') ?></span></a>
  <a class="stat<?= $cnt['mgmt'] ? ' red' : '' ?>" href="<?= url('payables', ['tab' => 'requests', 'status' => 'mgmt']) ?>"><b><?= $cnt['mgmt'] ?></b><?= tt('si.k_mgmt') ?><span class="s"><?= e(t('si.k_mgmt_hint', ['v' => si_money(si_threshold())])) ?></span></a>
  <a class="stat" href="<?= url('payables', ['tab' => 'requests']) ?>"><b><?= $cnt['requests'] ?></b><?= tt('si.k_requests') ?><span class="s"><?= tt('si.k_requests_hint') ?></span></a>
</div>
<nav class="tabs" style="margin-bottom:12px;"><?php $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('payables', ['tab' => $k]) . '">' . $lbl . '</a>'; echo $T('invoices', '🧾 ' . t('si.invoices')) . $T('requests', '✅ ' . t('si.requests')) . $T('aging', '📊 ' . t('si.aging')) . $T('statement', '📄 ' . t('si.statement')); ?></nav>

<?php if ($tab === 'invoices'): $rows = si_list($f); [$rows, $pg] = page_list($rows, 'payables', array_filter($f + ['tab' => 'invoices'])); ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1.2fr 1.6fr auto;">
  <input type="hidden" name="p" value="payables"><input type="hidden" name="tab" value="invoices">
  <div class="field"><label><?= tt('prq.status') ?></label><select name="status"><option value=""<?= $sel($f['status'], '') ?>><?= tt('prq.all') ?></option><option value="open"<?= $sel($f['status'], 'open') ?>><?= tt('si.f_open') ?></option><option value="overdue"<?= $sel($f['status'], 'overdue') ?>><?= tt('si.k_overdue') ?></option><?php foreach (SI_STATES as $s): ?><option value="<?= $s ?>"<?= $sel($f['status'], $s) ?>><?= tt('si.s_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('po.supplier') ?></label><select name="supplier_id"><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $f['supplier_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('si.search_ph') ?>"></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
</form>
<div class="card">
<form method="post" action="<?= url('payables', ['tab' => 'requests']) ?>" data-lock id="req-form"><?= csrf_field() ?><input type="hidden" name="action" value="si_request">
<table class="tbl tbl--wrap">
  <thead><tr><th></th><th><?= tt('si.no') ?></th><th><?= tt('po.supplier') ?></th><th><?= tt('si.inv_no') ?></th><th><?= tt('po.no') ?></th><th><?= tt('si.issue') ?></th><th><?= tt('si.due') ?></th><th class="num"><?= tt('po.gross') ?></th><th class="num"><?= tt('si.open') ?></th><th><?= tt('si.match') ?></th><th><?= tt('prq.status') ?></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="11" class="hint"><?= tt('si.none') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): $od = $r['due_date'] && in_array($r['status'], ['checked', 'disputed', 'requested', 'approved'], true) && $r['due_date'] < date('Y-m-d'); ?>
    <tr<?= $r['kind'] === 'credit' ? ' style="background:rgba(201,162,39,.06)"' : '' ?>>
      <td><?php if ($r['status'] === 'checked'): ?><input type="checkbox" name="inv[]" value="<?= (int)$r['id'] ?>" data-sup="<?= (int)$r['supplier_id'] ?>"><?php endif; ?></td>
      <td><a href="<?= url('payable', ['id' => (int)$r['id']]) ?>" style="font-family:var(--mono);"><?= e($r['si_no']) ?></a><?= $r['kind'] === 'credit' ? ' ' . pill('gold', t('si.credit')) : '' ?></td>
      <td><?= e($r['supplier']) ?></td><td><?= e($r['inv_no']) ?></td>
      <td><?= $r['order_id'] ? '<a href="' . url('purchase_order', ['id' => (int)$r['order_id']]) . '" style="font-family:var(--mono);">' . e($r['po_no']) . '</a>' : '—' ?><?= $r['sub_code'] ? '<br><small class="hint">' . e($r['project_code'] . ' · ' . $r['sub_code']) . '</small>' : '' ?></td>
      <td><?= fmt_date($r['issue_date']) ?></td><td<?= $od ? ' style="color:var(--red)"' : '' ?>><?= fmt_date($r['due_date']) ?></td>
      <td class="num"><?= e(si_money((float)$r['gross'], $r['currency'])) ?></td><td class="num"><?= (float)$r['open_amount'] > 0.005 && $r['status'] !== 'cancelled' ? e(si_money((float)$r['open_amount'], $r['currency'])) : '—' ?></td>
      <td><?= si_m_pill($r['match_status']) ?></td><td><?= si_pill($r['status']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pg ?>
<div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold btn--sm" type="submit" id="req-btn" disabled>✅ <?= tt('si.make_request') ?></button><input name="pay_by" type="date" title="<?= tt('si.pay_by') ?>"><input name="note" maxlength="600" placeholder="<?= tt('prq.note') ?>" style="min-width:16em;"><span class="hint"><?= e(t('si.request_hint', ['v' => si_money(si_threshold())])) ?></span></div>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){var cbs=document.querySelectorAll('input[name="inv[]"]'),btn=document.getElementById('req-btn');function upd(){var on=[].filter.call(cbs,function(c){return c.checked;});var sups={};on.forEach(function(c){sups[c.dataset.sup]=1;});btn.disabled=!on.length||Object.keys(sups).length>1;}cbs.forEach(function(c){c.addEventListener('change',upd);});})();</script>
</div>

<?php elseif ($tab === 'requests'): $rid = (int)($_GET['id'] ?? 0); $R = $rid ? si_request_load($rid) : null; $rf = ['status' => (string)($_GET['status'] ?? (isset($_GET['status']) ? '' : 'open')), 'supplier_id' => $f['supplier_id']]; $reqs = si_requests($rf); ?>
<?php if ($R): $items = si_request_items($rid); $ev = si_events(0, $rid); ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= e($R['req_no']) ?> <?= si_r_pill($R['status']) ?><?= (int)$R['over_threshold'] ? ' ' . pill('warn', t('si.over_threshold')) : '' ?></h2><p class="hint"><?= e($R['supplier']) ?> · <b><?= e(si_money((float)$R['amount'], $R['currency'])) ?></b><?= $R['pay_by'] ? ' · ' . tt('si.pay_by') . ' ' . fmt_date($R['pay_by']) : '' ?> · <?= e($R['requested_name'] ?? '') ?> <?= fmt_datetime($R['created_at']) ?><?= $R['decided_at'] ? ' · ' . tt('si.decided_by') . ' ' . e($R['decided_name']) . ' ' . fmt_datetime($R['decided_at']) . ($R['decision_note'] ? ' — ' . e($R['decision_note']) : '') : '' ?><?= $R['note'] ? '<br>' . e($R['note']) : '' ?></p></div>
    <div class="btn-row">
      <?php if (($R['status'] === 'mgmt' && $mgmt) || ($R['status'] === 'submitted' && $pay)): ?><form method="post" action="<?= url('payables', ['tab' => 'requests', 'id' => $rid]) ?>" data-lock class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="si_req_decide"><input type="hidden" name="id" value="<?= $rid ?>"><input name="note" maxlength="400" placeholder="<?= tt('prq.reason') ?>" style="width:14em;"><button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve">✓ <?= tt('si.approve_pay') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" data-confirm="<?= e(t('prq.reject_q')) ?>">✕ <?= tt('prq.reject') ?></button></form><?php endif; ?>
      <?php if (in_array($R['status'], ['submitted', 'mgmt', 'approved'], true)): ?><form method="post" action="<?= url('payables', ['tab' => 'requests', 'id' => $rid]) ?>" data-lock data-confirm="<?= e(t('si.req_cancel_q')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="si_req_cancel"><input type="hidden" name="id" value="<?= $rid ?>"><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('po.cancel') ?></button></form><?php endif; ?>
    </div></div>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('si.no') ?></th><th><?= tt('si.inv_no') ?></th><th><?= tt('po.no') ?></th><th><?= tt('si.due') ?></th><th class="num"><?= tt('si.amount_req') ?></th><th class="num"><?= tt('si.paid') ?></th><th><?= tt('prq.status') ?></th></tr></thead><tbody>
  <?php foreach ($items as $it): ?><tr><td><a href="<?= url('payable', ['id' => (int)$it['invoice_id']]) ?>" style="font-family:var(--mono);"><?= e($it['si_no']) ?></a><?= $it['kind'] === 'credit' ? ' ' . pill('gold', t('si.credit')) : '' ?></td><td><?= e($it['inv_no']) ?></td><td><?= e($it['po_no'] ?? '—') ?></td><td><?= fmt_date($it['due_date']) ?></td><td class="num"><?= e(si_money((float)$it['amount'], $it['currency'])) ?></td><td class="num"><?= e(si_money((float)$it['paid'], $it['currency'])) ?></td><td><?= si_pill($it['inv_status']) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($ev): ?><h3 style="margin-top:12px;"><?= tt('prq.log') ?></h3><table class="tbl tbl--wrap"><tbody><?php foreach ($ev as $e0): ?><tr><td><?= fmt_datetime($e0['created_at']) ?></td><td><?= e($e0['user_name'] ?? '') ?></td><td><?= tt('si.ev_' . $e0['event']) ?></td><td><?= e($e0['note'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1.2fr auto;">
  <input type="hidden" name="p" value="payables"><input type="hidden" name="tab" value="requests">
  <div class="field"><label><?= tt('prq.status') ?></label><select name="status"><option value=""<?= $sel($rf['status'], '') ?>><?= tt('prq.all') ?></option><option value="open"<?= $sel($rf['status'], 'open') ?>><?= tt('prq.open') ?></option><?php foreach (['submitted', 'mgmt', 'approved', 'rejected', 'paid', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= $sel($rf['status'], $s) ?>><?= tt('si.r_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('po.supplier') ?></label><select name="supplier_id"><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $rf['supplier_id']) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
</form>
<div class="card">
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('si.req_no') ?></th><th><?= tt('po.supplier') ?></th><th class="num"><?= tt('po.amount') ?></th><th><?= tt('si.pay_by') ?></th><th><?= tt('prq.requester') ?></th><th class="num"><?= tt('si.invoices') ?></th><th><?= tt('prq.status') ?></th></tr></thead><tbody>
  <?php if (!$reqs): ?><tr><td colspan="7" class="hint"><?= tt('si.none') ?></td></tr><?php endif; ?>
  <?php foreach ($reqs as $r): ?><tr><td><a href="<?= url('payables', ['tab' => 'requests', 'id' => (int)$r['id']]) ?>" style="font-family:var(--mono);"><?= e($r['req_no']) ?></a></td><td><?= e($r['supplier']) ?></td><td class="num"><?= e(si_money((float)$r['amount'], $r['currency'])) ?></td><td><?= fmt_date($r['pay_by']) ?></td><td><?= e($r['requested_name'] ?? '') ?></td><td class="num"><?= (int)$r['n_inv'] ?></td><td><?= si_r_pill($r['status']) ?><?= (int)$r['over_threshold'] ? ' ' . pill('warn', '!') : '' ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>

<?php elseif ($tab === 'aging'): $A = si_aging(); $m = fn(float $v) => $v == 0.0 ? '—' : number_format($v, 2, ',', '.'); ?>
<div class="card">
  <h2><?= tt('si.aging') ?></h2><p class="hint"><?= tt('si.aging_lede') ?></p>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('po.supplier') ?></th><th class="num"><?= tt('si.a_not_due') ?></th><th class="num">1–30</th><th class="num">31–60</th><th class="num">61–90</th><th class="num">90+</th><th class="num"><?= tt('po.total') ?></th></tr></thead><tbody>
  <?php if (!$A['rows']): ?><tr><td colspan="7" class="hint"><?= tt('si.none') ?></td></tr><?php endif; ?>
  <?php foreach ($A['rows'] as $sid => $r): ?><tr><td><a href="<?= url('payables', ['tab' => 'statement', 'supplier_id' => $sid]) ?>"><?= e($r['supplier']) ?></a> <small class="hint"><?= e($r['cur']) ?></small></td><td class="num"><?= $m($r['not_due']) ?></td><td class="num"><?= $m($r['d30']) ?></td><td class="num"<?= $r['d60'] > 0 ? ' style="color:var(--red)"' : '' ?>><?= $m($r['d60']) ?></td><td class="num"<?= $r['d90'] > 0 ? ' style="color:var(--red)"' : '' ?>><?= $m($r['d90']) ?></td><td class="num"<?= $r['d90p'] > 0 ? ' style="color:var(--red);font-weight:600"' : '' ?>><?= $m($r['d90p']) ?></td><td class="num"><b><?= $m($r['total']) ?></b></td></tr><?php endforeach; ?>
  <?php if ($A['rows']): $T = $A['totals']; ?><tr><td><b><?= tt('po.total') ?></b></td><td class="num"><b><?= $m($T['not_due']) ?></b></td><td class="num"><b><?= $m($T['d30']) ?></b></td><td class="num"><b><?= $m($T['d60']) ?></b></td><td class="num"><b><?= $m($T['d90']) ?></b></td><td class="num"><b><?= $m($T['d90p']) ?></b></td><td class="num"><b><?= $m($T['total']) ?></b></td></tr><?php endif; ?>
  </tbody></table>
</div>

<?php else: $sid = (int)($_GET['supplier_id'] ?? 0); $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : null; $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d'); $ST = $sid ? si_statement($sid, $from, $to) : null; $S = $sid ? sup_load($sid) : null;
if ($ST && ($_GET['format'] ?? '') === 'xlsx') { require_once __DIR__ . '/../app/xlsxw.php'; $rows = []; if ($from) $rows[] = [$from, t('si.opening'), '', '', '', '', '', (float)$ST['opening']]; foreach ($ST['rows'] as $r) $rows[] = [$r['d'], t('si.st_' . $r['kind']), $r['ref'], $r['si_no'] ?? ($r['inv_no'] ?? ''), $r['po'] ?? '', $r['due'] ?? '', $r['debit'] ?: null, $r['credit'] ?: null, (float)$r['balance']]; xlsx_download('Statement-' . preg_replace('/[^A-Za-z0-9]/', '_', $S['name']) . '-' . $to . '.xlsx', [['name' => 'Statement', 'first' => true, 'header' => [t('si.date'), t('si.kind'), t('si.ref'), t('si.no'), t('po.no'), t('si.due'), t('si.debit'), t('si.credit_col'), t('si.balance')], 'rows' => $rows]]); exit; } ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr auto auto;">
  <input type="hidden" name="p" value="payables"><input type="hidden" name="tab" value="statement">
  <div class="field"><label><?= tt('po.supplier') ?></label><select name="supplier_id" required><option value=""><?= tt('flt.any') ?></option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $sid) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('si.from') ?></label><input name="from" type="date" value="<?= e($from ?? '') ?>"></div>
  <div class="field"><label><?= tt('si.to') ?></label><input name="to" type="date" value="<?= e($to) ?>"></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  <div class="field"><label>&nbsp;</label><?php if ($ST): ?><a class="btn btn--ghost btn--sm" href="<?= url('payables', ['tab' => 'statement', 'supplier_id' => $sid, 'from' => $from ?? '', 'to' => $to, 'format' => 'xlsx']) ?>">⤓ Excel</a><?php endif; ?></div>
</form>
<div class="card">
  <?php if (!$ST): ?><p class="hint"><?= tt('si.st_pick') ?></p><?php else: $cur = $S['sup_currency'] ?: 'EUR'; ?>
  <h2><?= e($S['name']) ?> — <?= tt('si.statement') ?> <small class="hint"><?= $from ? fmt_date($from) . ' – ' : '' ?><?= fmt_date($to) ?></small></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('si.date') ?></th><th><?= tt('si.kind') ?></th><th><?= tt('si.ref') ?></th><th><?= tt('po.no') ?></th><th><?= tt('si.due') ?></th><th class="num"><?= tt('si.debit') ?></th><th class="num"><?= tt('si.credit_col') ?></th><th class="num"><?= tt('si.balance') ?></th></tr></thead><tbody>
  <?php if ($from): ?><tr><td><?= fmt_date($from) ?></td><td colspan="6"><i><?= tt('si.opening') ?></i></td><td class="num"><?= e(number_format((float)$ST['opening'], 2, ',', '.')) ?></td></tr><?php endif; ?>
  <?php foreach ($ST['rows'] as $r): ?><tr><td><?= fmt_date($r['d']) ?></td><td><?= tt('si.st_' . $r['kind']) ?></td><td><?= $r['kind'] === 'payment' ? e($r['ref']) . ' <small class="hint">' . e($r['inv_no']) . '</small>' : '<a href="' . url('payable', ['id' => $r['id']]) . '">' . e($r['ref']) . '</a>' ?></td><td><?= e($r['po'] ?? '') ?></td><td><?= fmt_date($r['due']) ?></td><td class="num"><?= $r['debit'] ? e(number_format($r['debit'], 2, ',', '.')) : '' ?></td><td class="num"><?= $r['credit'] ? e(number_format($r['credit'], 2, ',', '.')) : '' ?></td><td class="num"><?= e(number_format((float)$r['balance'], 2, ',', '.')) ?></td></tr><?php endforeach; ?>
  <tr><td colspan="7" class="num"><b><?= tt('si.balance_due') ?></b></td><td class="num"><b><?= e(si_money((float)$ST['balance'], $cur)) ?></b></td></tr>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();

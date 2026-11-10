<?php
/** One proforma invoice: edit (draft), issue, send, invoices from accounting, status (GAS-CP-R44-003). ?p=proforma&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0);
$r = function_exists('pi_get') ? pi_get($id) : null;
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'proformas'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$sales = pi_is_sales(); $canInv = pi_can_invoices(); $backTo = url('proforma', ['id' => $id]); $draft = $r['status'] === 'draft'; $cur = (string)$r['currency'];
$spRow = $r['subproject_id'] ? load_subproject((int)$r['subproject_id']) : null;
$ivCh = $spRow && function_exists('iv_choices_for_sub') ? iv_choices_for_sub($spRow) : (function_exists('iv_vessel_has') && iv_vessel_has() && $r['vessel_id'] ? array_column(array_map(fn($x) => $x + ['src' => 'vessel'], iv_list('vessel', (int)$r['vessel_id'], true)), null, 'id') + array_column(array_map(fn($x) => $x + ['src' => 'customer'], iv_list('customer', (int)$r['customer_id'], true)), null, 'id') : []);
$paid = (float)$r['paid_sum']; $open = max(0, (float)$r['gross'] - $paid); $isInv = function_exists('pi_is_invoice') && pi_is_invoice($r);   // R94-001 direct invoice
$invSum = (float)($r['inv_sum'] ?? 0); $advSum = (float)($r['adv_sum'] ?? 0); $openRow = max(0.0, (float)($r['open_sum'] ?? $open));   // R95-004: invoiced part / advances / open of this row
layout_head($r['pi_no'], 'proformas');
?>
<p class="eyebrow"><a href="<?= url('proformas') ?>"><?= tt('pi.title') ?></a> › <?= e($r['pi_no']) ?></p>
<div class="page-head">
  <div><h1><span class="mono" style="color:var(--gold-text);"><?= e($r['pi_no']) ?></span> <?= e($r['title']) ?> <?= $isInv ? pill('navy', t('pi.k_invoice')) . ' ' : '' ?><?= pi_status_pill($r['status']) ?><?= pi_overdue($r) ? ' ' . pill('bad', t('pi.overdue')) : '' ?></h1>
    <p class="lede" style="margin-bottom:0;"><?php if ($r['offer_id']): ?><a href="<?= url('offer', ['id' => (int)$r['offer_id']]) ?>"><?= e($r['offer_no'] . ' ' . $r['revision']) ?></a><?= $r['sq_no'] ? ' · <span class="mono">SQ ' . e($r['sq_no']) . '</span>' : '' ?><?php else: ?><span class="hint"><?= tt('pi.di_no_offer') ?></span><?php endif; ?> · <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?php if (!empty($r['billing_org_id']) && function_exists('pty_label')): ?> · <span title="<?= tt('pty.party') ?>">🏛 <?= e(pty_label((int)$r['billing_org_id'])) ?></span><?php endif; ?><?= $r['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$r['subproject_id']]) . '">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</a>' : '' ?><?= $r['vessel'] ? ' · ' . e($r['vessel']) : '' ?><?= $r['term_n'] ? ' · ' . e(t('pt.instalment', ['n' => (int)$r['term_n']])) : '' ?></p></div>
  <div class="btn-row">
    <?php if ($r['file_id']): ?><a class="btn btn--gold" href="<?= url('file', ['id' => (int)$r['file_id']]) ?>" target="_blank">⤓ PDF</a><?php else: ?><a class="btn btn--ghost" href="<?= url('proforma_pdf', ['id' => $id]) ?>" target="_blank">👁 <?= tt('pi.preview') ?></a><?php endif; ?>
    <?php if ($sales && $draft): ?><form method="post" action="<?= $backTo ?>" class="inline" data-lock data-confirm="<?= tt('pi.issue_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('pi.issue') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pi_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="status" value="issued"><button class="btn btn--gold" type="submit">✔ <?= tt('pi.issue') ?></button></form><?php endif; ?>
    <?php if ($r['file_id'] && !$draft && function_exists('em_card_html')): ?><button class="btn" type="button" data-open-email>✉ <?= tt('em.send') ?></button><?php endif; ?>
  </div>
</div>

<div class="grid grid-2">
  <div class="card">
    <?php if ($sales && !in_array($r['status'], ['paid', 'cancelled'], true)): ?>
    <h2><?= $draft ? tt('pi.edit_draft') : tt('pi.edit') ?></h2>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pi_save"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label><?= tt('pi.description') ?></label><input name="title" maxlength="200" value="<?= e($r['title']) ?>"></div>
      <div class="grid grid-3">
        <div class="field"><label><?= tt('pi.net') ?></label><input name="amount_net" inputmode="decimal" value="<?= e(number_format((float)$r['amount_net'], 2, ',', '.')) ?>"<?= $draft ? '' : ' readonly' ?>></div>
        <div class="field"><label><?= tt('pi.vat') ?> %</label><input name="vat_pct" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format((float)$r['vat_pct'], 2, '.', ''), '0'), '.')) ?>"<?= $draft ? '' : ' readonly' ?>></div>
        <div class="field"><label><?= tt('iv.currency') ?></label><input name="currency" maxlength="3" value="<?= e($cur) ?>"<?= $draft ? '' : ' readonly' ?>></div>
        <div class="field"><label><?= tt('pi.issue_date') ?></label><input type="date" name="issue_date" value="<?= e((string)$r['issue_date']) ?>"<?= $draft ? '' : ' readonly' ?>></div>
        <div class="field"><label><?= tt('pi.due') ?></label><input type="date" name="due_date" value="<?= e((string)$r['due_date']) ?>"></div>
        <div class="field"><label><?= tt('iv.title') ?></label><select name="invoicing_id"<?= $draft ? '' : ' disabled' ?>><option value="">—</option><?php foreach ($ivCh as $ivr): ?><option value="<?= (int)$ivr['id'] ?>"<?= (int)$r['invoicing_id'] === (int)$ivr['id'] ? ' selected' : '' ?>><?= e(function_exists('pty_identity_label') ? pty_identity_label(array_replace($ivr, ['src' => ($ivr['src'] ?? '') === 'customer' ? 'party' : ($ivr['src'] ?? 'other')])) : (($ivr['src'] === 'vessel' ? '⚓ ' : '🏢 ') . iv_label($ivr))) ?><?= $ivr['vat_pct'] !== null ? ' · ' . tt('iv.vat_pct') . ' ' . e((string)(float)$ivr['vat_pct']) : '' ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label><?= tt('pi.notes') ?></label><textarea name="notes" rows="3"><?= e((string)$r['notes']) ?></textarea></div>
      <?php if ($draft): ?><div class="field"><label><?= tt('pi.erp_file') ?></label><input type="file" name="file" accept=".pdf"><p class="hint"><?= tt('pi.erp_file_hint') ?></p></div><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
    </form>
    <?php else: ?>
    <h2><?= tt('pi.one') ?></h2>
    <table class="kv"><tbody>
      <tr><th><?= tt('pi.net') ?></th><td><?= e(pi_money((float)$r['amount_net'], $cur)) ?></td></tr>
      <tr><th><?= tt('pi.vat') ?></th><td><?= e((string)(float)$r['vat_pct']) ?> % · <?= e(pi_money((float)$r['vat_amount'], $cur)) ?></td></tr>
      <tr><th><?= tt('pi.gross') ?></th><td><b><?= e(pi_money((float)$r['gross'], $cur)) ?></b></td></tr>
      <tr><th><?= tt('pi.issue_date') ?> / <?= tt('pi.due') ?></th><td><?= $r['issue_date'] ? e(fmt_date($r['issue_date'])) : '—' ?> / <?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '—' ?></td></tr>
      <tr><th><?= tt('iv.title') ?></th><td><?= $r['iv'] ? e(iv_label($r['iv'])) : '—' ?></td></tr>
      <?php if ($r['notes']): ?><tr><th><?= tt('pi.notes') ?></th><td><?= rt_html($r['notes']) ?></td></tr><?php endif; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('pi.money') ?></h2>
    <div class="kpi kpi--3" style="margin-bottom:10px;">
      <span class="stat"><b><?= e(pi_money((float)$r['gross'], $cur)) ?></b><span class="s"><?= tt('pi.gross') ?></span></span>
      <span class="stat green"><b><?= e(pi_money($paid, $cur)) ?></b><span class="s"><?= tt('pi.paid_lbl') ?></span></span>
      <span class="stat<?= $open > 0 && pi_overdue($r) ? ' red' : '' ?>"><b><?= e(pi_money($open, $cur)) ?></b><span class="s"><?= tt('pi.open') ?></span></span>
    </div>
    <table class="kv"><tbody>
      <tr><th><?= tt('th.status') ?></th><td><?= pi_status_pill($r['status']) ?><?= $r['sent_at'] ? ' · ' . tt('pi.s_sent') . ' ' . e(fmt_datetime($r['sent_at'])) : '' ?><?= $r['paid_at'] ? ' · ' . tt('pi.s_paid') . ' ' . e(fmt_date($r['paid_at'])) : '' ?></td></tr>
      <tr><th><?= tt('th.by') ?></th><td><?= e($r['created_name'] ?: '—') ?> · <?= e(fmt_datetime($r['created_at'])) ?></td></tr>
      <?php if ($r['file_id']): ?><tr><th>PDF</th><td><a href="<?= url('file', ['id' => (int)$r['file_id']]) ?>" target="_blank"><?= e($r['original_name']) ?></a></td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($sales && !$draft): ?>
    <form method="post" action="<?= $backTo ?>" class="btn-row" style="margin-top:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pi_status"><input type="hidden" name="id" value="<?= $id ?>">
      <select name="status" data-nosearch><?php foreach (['sent', 'paid', 'cancelled', 'draft'] as $st): if ($st === 'draft' && $r['status'] !== 'issued') continue; ?><option value="<?= $st ?>"><?= e(t('pi.s_' . $st)) ?></option><?php endforeach; ?></select><button class="btn btn--ghost btn--sm" type="submit"><?= tt('pi.set_status') ?></button>
    </form>
    <p class="hint" style="margin:6px 0 0;"><?= tt('pi.status_hint') ?></p>
    <?php endif; ?>
  </div>
</div>

<?php if (function_exists('ppay_has') && ppay_has()): $pays = $r['payments'] ?? [];
  // R95-004: the cap is the document — an invoice: its gross; a proforma: its not-yet-invoiced part (advances); once fully invoiced, payments are logged on the invoices
  $cap = $isInv ? (float)$r['gross'] : max(0.0, (float)$r['gross'] - $invSum); $offerPaid = $advSum; $capLeft = max(0, $cap - $offerPaid); $ep = (int)($_GET['pay'] ?? 0); $exp = null; foreach ($pays as $x) if ((int)$x['id'] === $ep) $exp = $x; ?>
<div class="card" id="payments">
  <div class="card-head"><h2>💳 <?= tt('pay.title') ?> <span class="hint">(<?= count($pays) ?>)</span></h2><span class="hint"><?= tt('pay.hint') ?></span></div>
  <div class="kpi kpi--4" style="margin-bottom:10px;">
    <div class="stat green"><b><?= e(pi_money($paid, $cur)) ?></b><?= tt('pay.k_paid') ?><span class="s"><?= tt('pay.of') ?> <?= e(pi_money((float)$r['gross'], $cur)) ?></span></div>
    <div class="stat<?= $open > 0 ? ' gold' : '' ?>"><b><?= e(pi_money($open, $cur)) ?></b><?= tt('pay.k_open') ?></div>
    <div class="stat"><b><?= e(pi_money($offerPaid, $cur)) ?></b><?= $isInv ? tt('pay.k_on_invoice') : tt('pay.k_advances') ?><span class="s"><?= tt('pay.of') ?> <?= e(pi_money($cap, $cur)) ?><?= !$isInv && $invSum > 0.005 ? ' · ' . e(t('pay.invoiced_part', ['v' => pi_money($invSum, $cur)])) : '' ?></span></div>
    <div class="stat<?= $capLeft <= 0 ? ' red' : '' ?>"><b><?= e(pi_money($capLeft, $cur)) ?></b><?= tt('pay.k_cap_left') ?></div>
  </div>
  <?php if (!$pays): ?><p class="hint"><?= tt('pay.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th class="num"><?= tt('pt.amount') ?></th><th><?= tt('pay.method') ?></th><th><?= tt('pay.reference') ?></th><th><?= tt('pd.note') ?></th><th><?= tt('pay.invoiced') ?></th><th><?= tt('th.by') ?></th><?php if ($canInv): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($pays as $x): ?><tr><td class="date"><?= e(fmt_date($x['paid_on'])) ?></td><td class="num"><b><?= e(pi_money((float)$x['amount'], $cur)) ?></b></td><td><?= e(t('pay.m_' . $x['method'])) ?></td><td class="mono"><?= e((string)$x['reference']) ?></td><td class="hint"><?= e((string)$x['note']) ?></td>
    <td><?= $isInv ? pill('green', t('pi.k_invoice')) : pill('gold', t('pay.advance')) ?></td><td class="hint"><?= e((string)$x['by_name']) ?></td>
    <?php if ($canInv): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('proforma', ['id' => $id, 'pay' => (int)$x['id']]) ?>#pay-form">✎</a><form method="post" action="<?= $backTo ?>#payments" class="inline" data-lock data-confirm="<?= tt('pay.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="ppay_delete"><input type="hidden" name="pay_id" value="<?= (int)$x['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑</button></form></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if (!$isInv && $invSum > 0.005 && $cap <= 0.005): ?><p class="flash flash--info" style="margin:8px 0 0;"><?= e(t('pay.on_invoice', ['n' => (string)$r['invoice_nos']])) ?></p><?php endif; ?>
  <?php if ($canInv && !$draft && $r['status'] !== 'cancelled' && ($cap > 0.005 || $exp)): ?>
  <form method="post" action="<?= $backTo ?>#payments" id="pay-form" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="ppay_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pay_id" value="<?= $exp ? (int)$exp['id'] : 0 ?>">
    <h3 style="margin:0 0 6px;"><?= $exp ? tt('pay.edit') : tt('pay.add') ?></h3>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="paid_on" value="<?= e((string)($exp['paid_on'] ?? date('Y-m-d'))) ?>" required></div>
      <div class="field"><label><?= tt('pt.amount') ?> *</label><input name="amount" inputmode="decimal" required value="<?= $exp ? e(number_format((float)$exp['amount'], 2, ',', '.')) : e(number_format(min($open, $capLeft), 2, ',', '.')) ?>"><p class="hint" style="margin:4px 0 0;"><?= e(t('pay.max_hint', ['v' => pi_money($capLeft + ($exp ? (float)$exp['amount'] : 0), $cur)])) ?></p></div>
      <div class="field"><label><?= tt('pay.method') ?></label><select name="method" data-nosearch><?php foreach (PPAY_METHODS as $m): ?><option value="<?= $m ?>"<?= ($exp['method'] ?? 'bank') === $m ? ' selected' : '' ?>><?= e(t('pay.m_' . $m)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pay.reference') ?></label><input name="reference" maxlength="80" value="<?= e((string)($exp['reference'] ?? '')) ?>" placeholder="<?= tt('pay.reference_ph') ?>"></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('pd.note') ?></label><input name="note" maxlength="255" value="<?= e((string)($exp['note'] ?? '')) ?>"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= $exp ? tt('btn.save') : tt('pay.record') ?></button><?php if ($exp): ?><a class="btn btn--ghost btn--sm" href="<?= $backTo ?>#payments"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
  <?php elseif ($draft): ?><p class="hint"><?= tt('pay.not_issued') ?></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$isInv): ?>
<div class="card" id="invoices">
  <div class="card-head"><h2>🧾 <?= tt('pi.invoices') ?> <span class="hint">(<?= count($r['invoices']) ?>)</span></h2><span class="hint"><?= tt('pi.invoices_hint2') ?></span></div>
  <?php $invSum = 0.0; foreach ($r['invoices'] as $x) $invSum += (float)$x['amount']; ?>
  <?php if (!$r['invoices']): ?><p class="hint"><?= tt('pi.no_invoices') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.invoice_no') ?></th><th><?= tt('th.date') ?></th><th class="num"><?= tt('pi.inv_alloc') ?></th><th class="num"><?= tt('pi.gross') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th class="num"><?= tt('fr.open') ?></th><th><?= tt('th.status') ?></th><?php if ($canInv): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($r['invoices'] as $x): $xo = max(0.0, (float)$x['gross'] - (float)$x['paid_amount']); ?><tr><td class="mono"><a href="<?= url('proforma', ['id' => (int)$x['id']]) ?>"><b><?= e($x['invoice_no']) ?></b></a></td><td class="date"><?= $x['invoice_date'] ? e(fmt_date($x['invoice_date'])) : '—' ?></td><td class="num"><?= e(pi_money((float)$x['amount'], $cur)) ?></td><td class="num"><?= e(pi_money((float)$x['gross'], $cur)) ?></td><td class="num"><?= e(pi_money((float)$x['paid_amount'], $cur)) ?></td><td class="num"><?= $xo > 0.005 ? '<b>' . e(pi_money($xo, $cur)) . '</b>' : '—' ?></td><td><?= pi_status_pill($x['status']) ?></td>
    <?php if ($canInv): ?><td class="row-actions"><form method="post" action="<?= $backTo ?>#invoices" class="inline" data-lock data-confirm="<?= tt('pi.inv_unlink_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pinv_delete"><input type="hidden" name="inv_id" value="<?= (int)$x['link_id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('pi.inv_unlink') ?>">✕</button></form></td><?php endif; ?></tr><?php endforeach; ?>
  <tr class="is-total"><td colspan="2"><?= tt('pi.inv_total') ?></td><td class="num"><b><?= e(pi_money($invSum, $cur)) ?></b> <span class="hint">/ <?= e(pi_money((float)$r['gross'], $cur)) ?></span></td><td colspan="<?= $canInv ? 5 : 4 ?>"><?= abs($invSum - (float)$r['gross']) > 0.01 ? pill('gold', t('pi.inv_mismatch', ['d' => pi_money($invSum - (float)$r['gross'], $cur)])) : pill('green', t('pi.inv_matches')) ?></td></tr>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($canInv && !$draft && $r['status'] !== 'cancelled' && $invSum + 0.005 < (float)$r['gross']): ?>
  <form method="post" action="<?= $backTo ?>#invoices" id="inv-form" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="pinv_save"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 style="margin:0 0 6px;"><?= tt('pi.inv_add') ?></h3>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('pi.invoice_no') ?> *</label><input name="invoice_no" maxlength="60" required placeholder="TDA-2026-0123"><p class="hint" style="margin:4px 0 0;"><?= tt('pi.inv_no_hint') ?></p></div>
      <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label><?= tt('pi.inv_alloc') ?></label><input name="inv_amount" inputmode="decimal" value="<?= e(number_format(max(0.0, (float)$r['gross'] - $invSum), 2, ',', '.')) ?>"><p class="hint" style="margin:4px 0 0;"><?= tt('pi.inv_alloc_hint') ?></p></div>
      <div class="field"><label><?= tt('pi.paid_on') ?></label><input type="date" name="paid_at"><p class="hint" style="margin:4px 0 0;"><?= tt('pi.inv_paid_hint') ?></p></div>
      <div class="field"><label><?= tt('pi.paid_lbl') ?></label><input name="paid_amount" inputmode="decimal" placeholder="<?= tt('pi.paid_ph') ?>"></div>
      <div class="field"><label><?= tt('pd.note') ?></label><input name="inv_note" maxlength="255"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('pi.inv_register') ?></button></div>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($isInv && !empty($r['settles'])): ?>
<div class="card" id="settles">
  <div class="card-head"><h2>🔗 <?= tt('pi.settles') ?></h2><span class="hint"><?= tt('pi.settles_hint') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('pi.description') ?></th><th class="num"><?= tt('pi.gross') ?></th><th class="num"><?= tt('pi.inv_alloc') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($r['settles'] as $x): ?><tr><td class="mono"><a href="<?= url('proforma', ['id' => (int)$x['id']]) ?>"><b><?= e($x['pi_no']) ?></b></a></td><td><?= e($x['title']) ?></td><td class="num"><?= e(pi_money((float)$x['gross'], $cur)) ?></td><td class="num"><?= e(pi_money((float)$x['amount'], $cur)) ?></td><td><?= pi_status_pill($x['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php if ($r['file_id'] && !$draft && function_exists('em_card_html')):
  echo em_card_html(['kind' => 'proforma', 'ctx' => ['no' => $r['pi_no'], 'title' => (string)$r['title'], 'vessel' => (string)($r['vessel'] ?? ''), 'customer' => (string)($r['customer_name'] ?? ''), 'project' => (string)($r['project_code'] ?? ''), 'doc' => t('em.w_proforma', ['no' => $r['pi_no']]), 'project_id' => (int)$r['project_id'], 'customer_id' => (int)($r['customer_id'] ?? 0)],
    'people' => em_people((int)($r['vessel_id'] ?? 0) ?: null, (int)($r['customer_id'] ?? 0) ?: null, $r['iv'] && $r['iv']['email'] ? [[(string)($r['iv']['attn'] ?: $r['iv']['legal_name']), $r['iv']['email'], t('iv.title')]] : [], $r['subproject_id'] && function_exists('spc_emails') ? spc_emails((int)$r['subproject_id']) : []), 'atts' => [['f:' . (int)$r['file_id'], $r['pi_no'] . '.pdf']], 'back' => $backTo, 'hidden' => '<input type="hidden" name="proforma" value="' . $id . '">']);
endif; ?>
<?php if (is_cp_admin()): $adAction = 'pi_delete'; $adId = $id; $adBack = 'proformas'; $adConfirm = t('pi.delete_confirm', ['no' => $r['pi_no']]); $adNote = t('pi.delete_note'); require __DIR__ . '/_admin_delete.php'; endif; ?>
<?php layout_foot(); ?>

<?php
/** One proforma invoice: edit (draft), issue, send, invoices from accounting, status (GAS-CP-R44-003). ?p=proforma&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0);
$r = function_exists('pi_get') ? pi_get($id) : null;
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'proformas'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$sales = pi_is_sales(); $canInv = pi_can_invoices(); $backTo = url('proforma', ['id' => $id]); $draft = $r['status'] === 'draft'; $cur = (string)$r['currency'];
$spRow = $r['subproject_id'] ? load_subproject((int)$r['subproject_id']) : null;
$ivCh = $spRow && function_exists('iv_choices_for_sub') ? iv_choices_for_sub($spRow) : (function_exists('iv_vessel_has') && iv_vessel_has() && $r['vessel_id'] ? array_column(array_map(fn($x) => $x + ['src' => 'vessel'], iv_list('vessel', (int)$r['vessel_id'], true)), null, 'id') + array_column(array_map(fn($x) => $x + ['src' => 'customer'], iv_list('customer', (int)$r['customer_id'], true)), null, 'id') : []);
$paid = (float)$r['paid_sum']; $open = max(0, (float)$r['gross'] - $paid);
layout_head($r['pi_no'], 'proformas');
?>
<p class="eyebrow"><a href="<?= url('proformas') ?>"><?= tt('pi.title') ?></a> › <?= e($r['pi_no']) ?></p>
<div class="page-head">
  <div><h1><span class="mono" style="color:var(--gold-text);"><?= e($r['pi_no']) ?></span> <?= e($r['title']) ?> <?= pi_status_pill($r['status']) ?><?= pi_overdue($r) ? ' ' . pill('bad', t('pi.overdue')) : '' ?></h1>
    <p class="lede" style="margin-bottom:0;"><a href="<?= url('offer', ['id' => (int)$r['offer_id']]) ?>"><?= e($r['offer_no'] . ' ' . $r['revision']) ?></a><?= $r['sq_no'] ? ' · <span class="mono">SQ ' . e($r['sq_no']) . '</span>' : '' ?> · <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$r['subproject_id']]) . '">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</a>' : '' ?><?= $r['vessel'] ? ' · ' . e($r['vessel']) : '' ?><?= $r['term_n'] ? ' · ' . e(t('pt.instalment', ['n' => (int)$r['term_n']])) : '' ?></p></div>
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
        <div class="field"><label><?= tt('iv.title') ?></label><select name="invoicing_id"<?= $draft ? '' : ' disabled' ?>><option value="">—</option><?php foreach ($ivCh as $ivr): ?><option value="<?= (int)$ivr['id'] ?>"<?= (int)$r['invoicing_id'] === (int)$ivr['id'] ? ' selected' : '' ?>><?= e(($ivr['src'] === 'vessel' ? '⚓ ' : '🏢 ') . iv_label($ivr)) ?><?= $ivr['vat_pct'] !== null ? ' · ' . tt('iv.vat_pct') . ' ' . e((string)(float)$ivr['vat_pct']) : '' ?></option><?php endforeach; ?></select></div>
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

<div class="card" id="invoices">
  <div class="card-head"><h2>🧾 <?= tt('pi.invoices') ?> <span class="hint">(<?= count($r['invoices']) ?>)</span></h2><span class="hint"><?= tt('pi.invoices_hint') ?></span></div>
  <?php if (!$r['invoices']): ?><p class="hint"><?= tt('pi.no_invoices') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.invoice_no') ?></th><th><?= tt('th.date') ?></th><th class="num"><?= tt('pt.amount') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th><?= tt('pi.paid_on') ?></th><th><?= tt('pd.note') ?></th><?php if ($canInv): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($r['invoices'] as $x): ?><tr><td class="mono"><b><?= e($x['invoice_no']) ?></b></td><td class="date"><?= $x['invoice_date'] ? e(fmt_date($x['invoice_date'])) : '—' ?></td><td class="num"><?= e(pi_money((float)$x['amount'], $cur)) ?></td><td class="num"><?= e(pi_money((float)$x['paid_amount'], $cur)) ?></td><td class="date"><?= $x['paid_at'] ? e(fmt_date($x['paid_at'])) : '—' ?></td><td class="hint"><?= e((string)$x['note']) ?></td>
    <?php if ($canInv): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('proforma', ['id' => $id, 'inv' => (int)$x['id']]) ?>#inv-form">✎</a><form method="post" action="<?= $backTo ?>#invoices" class="inline" data-lock data-confirm="<?= tt('pi.inv_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pinv_delete"><input type="hidden" name="inv_id" value="<?= (int)$x['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($canInv && !$draft && $r['status'] !== 'cancelled'): $ei = (int)($_GET['inv'] ?? 0); $ex = null; foreach ($r['invoices'] as $x) if ((int)$x['id'] === $ei) $ex = $x; ?>
  <form method="post" action="<?= $backTo ?>#invoices" id="inv-form" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="pinv_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="inv_id" value="<?= (int)($ex['id'] ?? 0) ?>">
    <h3 style="margin:0 0 6px;"><?= $ex ? tt('pi.inv_edit') : tt('pi.inv_add') ?></h3>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('pi.invoice_no') ?> *</label><input name="invoice_no" maxlength="60" required value="<?= e((string)($ex['invoice_no'] ?? '')) ?>" placeholder="TDA-2026-0123"></div>
      <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="invoice_date" value="<?= e((string)($ex['invoice_date'] ?? date('Y-m-d'))) ?>"></div>
      <div class="field"><label><?= tt('pt.amount') ?></label><input name="inv_amount" inputmode="decimal" value="<?= $ex ? e(number_format((float)$ex['amount'], 2, ',', '.')) : e(number_format($open, 2, ',', '.')) ?>"></div>
      <div class="field"><label><?= tt('pi.paid_lbl') ?></label><input name="paid_amount" inputmode="decimal" value="<?= $ex ? e(number_format((float)$ex['paid_amount'], 2, ',', '.')) : '' ?>" placeholder="<?= tt('pi.paid_ph') ?>"></div>
      <div class="field"><label><?= tt('pi.paid_on') ?></label><input type="date" name="paid_at" value="<?= e((string)($ex['paid_at'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('pd.note') ?></label><input name="inv_note" maxlength="255" value="<?= e((string)($ex['note'] ?? '')) ?>"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($ex): ?><a class="btn btn--ghost btn--sm" href="<?= $backTo ?>#invoices"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
  <?php endif; ?>
</div>
<?php if ($r['file_id'] && !$draft && function_exists('em_card_html')):
  echo em_card_html(['kind' => 'proforma', 'ctx' => ['no' => $r['pi_no'], 'title' => (string)$r['title'], 'vessel' => (string)($r['vessel'] ?? ''), 'customer' => (string)($r['customer_name'] ?? ''), 'project' => (string)($r['project_code'] ?? ''), 'doc' => t('em.w_proforma', ['no' => $r['pi_no']]), 'project_id' => (int)$r['project_id'], 'customer_id' => (int)($r['customer_id'] ?? 0)],
    'people' => em_people((int)($r['vessel_id'] ?? 0) ?: null, (int)($r['customer_id'] ?? 0) ?: null, $r['iv'] && $r['iv']['email'] ? [[(string)($r['iv']['attn'] ?: $r['iv']['legal_name']), $r['iv']['email'], t('iv.title')]] : [], $r['subproject_id'] && function_exists('spc_emails') ? spc_emails((int)$r['subproject_id']) : []), 'atts' => [['f:' . (int)$r['file_id'], $r['pi_no'] . '.pdf']], 'back' => $backTo, 'hidden' => '<input type="hidden" name="proforma" value="' . $id . '">']);
endif; ?>
<?php if (is_cp_admin()): $adAction = 'pi_delete'; $adId = $id; $adBack = 'proformas'; $adConfirm = t('pi.delete_confirm', ['no' => $r['pi_no']]); $adNote = t('pi.delete_note'); require __DIR__ . '/_admin_delete.php'; endif; ?>
<?php layout_foot(); ?>

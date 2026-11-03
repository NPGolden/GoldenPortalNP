<?php
/** Financial statement of a project / sub-project — offers & terms, proformas, invoice register, payment register, totals.   GAS-CP-R76-002
 *   ?p=report_fin&project=[&sub=][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('fr_can') || !fr_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'projects'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$pid = (int)($_GET['project'] ?? 0); $sid = (int)($_GET['sub'] ?? 0) ?: null;
$D = $pid ? fr_data($pid, $sid) : null;
if (!$D) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (($_GET['format'] ?? '') === 'xlsx') { fr_xlsx($D); exit; }
$p = $D['project']; $s = $D['sub']; $T = $D['totals']; $cur = $D['cur']; $m = fn(float $v) => fr_money($v, $cur);
$label = trim(($p['project_code'] ?: '') . ' ' . $p['name']) . ($s ? ' › ' . trim($s['code'] . ' ' . $s['name']) : '');
layout_head(t('fr.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?><?= $s ? ' › <a href="' . url('subproject', ['id' => (int)$s['id']]) . '">' . e($s['code']) . '</a>' : '' ?></p>
<div class="page-head">
  <div><h1>💶 <?= tt('fr.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($label) ?> · <?= e($p['vessel'] ?: '') ?><?= $p['customer_name'] ? ' · ' . e($p['customer_name']) : '' ?><?= $p['pm_name'] ? ' · PM ' . e($p['pm_name']) : '' ?> · <span class="mono"><?= e($D['doc_no']) ?></span> · <?= e(fmt_date(date('Y-m-d'))) ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('report_fin', array_filter(['project' => $pid, 'sub' => $sid, 'format' => 'xlsx'])) ?>">⤓ Excel</a><button type="button" class="btn btn--ghost btn--sm" data-print>🖨 <?= tt('fr.print') ?></button><?php if ($s): ?><a class="btn btn--ghost btn--sm" href="<?= url('report_fin', ['project' => $pid]) ?>"><?= tt('fr.whole_project') ?></a><?php endif; ?><?php if (function_exists('cf_can') && cf_can()): ?><a class="btn btn--ghost btn--sm" href="<?= url('cashflow', ['project' => $pid]) ?>">💰 <?= tt('cf.title') ?></a><?php endif; ?></div>
</div>

<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= e($m($T['offered_gross'])) ?></b><?= tt('fr.offered_gross') ?><span class="s"><?= e(t('fr.net_n', ['v' => $m($T['offered_net']), 'n' => $T['n_offers']])) ?><?= $T['open_offers'] > 0 ? ' · ' . e(t('fr.open_offers_s', ['v' => $m($T['open_offers'])])) : '' ?></span></div>
  <div class="stat gold"><b><?= e($m($T['invoiced'])) ?></b><?= tt('fr.proformas_issued') ?><span class="s"><?= $T['n_pi'] ?> PI · <?= tt('fr.to_invoice') ?> <?= e($m($T['to_invoice'])) ?></span></div>
  <div class="stat green"><b><?= e($m($T['collected'])) ?></b><?= tt('fs.collected') ?><span class="s"><?= $T['n_pay'] ?> <?= tt('pay.title') ?> · <?= $T['n_inv'] ?> <?= tt('pi.invoices') ?> (<?= e($m($T['invoices'])) ?>)</span></div>
  <div class="stat<?= $T['overdue'] > 0 ? ' red' : '' ?>"><b><?= e($m($T['outstanding'])) ?></b><?= tt('fs.outstanding') ?><span class="s"><?= tt('fs.overdue') ?> <?= e($m($T['overdue'])) ?></span></div>
</div>

<div class="card" id="fr-offers">
  <h2>€ <?= tt('of.title') ?> <span class="count"><?= count($D['offers']) ?></span></h2>
  <?php if (!$D['offers']): ?><p class="hint"><?= tt('of.none_here') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('of.no') ?></th><th><?= tt('of.title_f') ?></th><?php if (!$s): ?><th><?= tt('sp.one') ?></th><?php endif; ?><th><?= tt('th.status') ?></th><th><?= tt('of.sent') ?></th><th class="num"><?= tt('fr.net') ?></th><th class="num">VAT</th><th class="num"><?= tt('fr.gross') ?></th><th><?= tt('fr.terms') ?></th></tr></thead><tbody>
  <?php foreach ($D['offers'] as $o): ?><tr class="<?= $o['behaves'] === 'closed_lost' ? 'is-muted' : '' ?>"><td class="ref"><a href="<?= url('offer', ['id' => (int)$o['id']]) ?>"><?= e($o['offer_no']) ?></a> <span class="hint"><?= e($o['revision']) ?></span></td><td><?= e($o['title']) ?></td><?php if (!$s): ?><td class="hint"><?= e((string)($o['sub_code'] ?? '')) ?></td><?php endif; ?><td><?= of_status_pill($o['status']) ?></td><td class="date"><?= e(fmt_date($o['sent_at'])) ?></td><td class="num"><?= e($m((float)$o['amount'])) ?></td><td class="num hint"><?= (float)($o['vat_pct'] ?? 0) ?> %</td><td class="num"><b><?= e($m($o['gross'])) ?></b></td>
    <td class="hint"><?= $o['terms'] ? implode('<br>', array_map(fn($tm) => e($tm['n'] . '. ' . ((float)$tm['pct'] > 0 ? $tm['pct'] . ' %' : fr_money((float)$tm['amount'], $cur)) . ' — ' . $tm['trigger_text'] . ' +' . $tm['due_days'] . ' d'), $o['terms'])) : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<div class="card" id="fr-pi">
  <h2>🧾 <?= tt('pi.title') ?> <span class="count"><?= count($D['proformas']) ?></span></h2>
  <?php if (!$D['proformas']): ?><p class="hint"><?= tt('pi.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('of.no') ?></th><th><?= tt('of.title_f') ?></th><th><?= tt('pi.issue_date') ?></th><th><?= tt('pi.due') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('fr.gross') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th class="num"><?= tt('fr.open') ?></th></tr></thead><tbody>
  <?php foreach ($D['proformas'] as $x): ?><tr class="<?= $x['overdue'] ? 'is-late' : '' ?>"><td class="mono"><a href="<?= url('proforma', ['id' => (int)$x['id']]) ?>"><?= e($x['pi_no']) ?></a></td><td class="hint"><?= e($x['offer_no']) ?></td><td><?= e($x['title']) ?></td><td class="date"><?= e(fmt_date($x['issue_date'])) ?></td><td class="date"><?= e(fmt_date($x['due_date'])) ?></td><td><?= pi_status_pill($x['status']) ?></td><td class="num"><?= e($m((float)$x['gross'])) ?></td><td class="num"><?= e($m($x['paid'])) ?></td><td class="num"><b><?= e($m($x['open'])) ?></b><?= $x['overdue'] ? ' ' . pill('bad', t('fs.overdue')) : '' ?></td></tr><?php endforeach; ?>
  <tr><td colspan="6"><b><?= tt('ob.c_total') ?></b></td><td class="num"><b><?= e($m($T['invoiced'])) ?></b></td><td class="num"><b><?= e($m($T['collected'])) ?></b></td><td class="num"><b><?= e($m($T['outstanding'])) ?></b></td></tr>
  </tbody></table></div><?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card" id="fr-pay">
    <h2>💳 <?= tt('fr.pay_register') ?> <span class="count"><?= count($D['payments']) ?></span></h2>
    <?php if (!$D['payments']): ?><p class="hint"><?= tt('pay.none') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('pi.no') ?></th><th class="num"><?= tt('pt.amount') ?></th><th><?= tt('pay.method') ?></th><th><?= tt('pay.reference') ?></th><th><?= tt('pay.invoiced') ?></th></tr></thead><tbody>
    <?php $sp = 0.0; foreach ($D['payments'] as $r): $sp += (float)$r['amount']; ?><tr><td class="date"><?= e(fmt_date($r['paid_on'])) ?></td><td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['proforma_id']]) ?>#payments"><?= e($r['pi_no']) ?></a></td><td class="num"><b><?= e($m((float)$r['amount'])) ?></b></td><td><?= e(t('pay.m_' . $r['method'])) ?></td><td class="mono hint"><?= e((string)$r['reference']) ?></td><td><?= $r['invoice_id'] ? pill('green', (string)$r['invoice_no']) : pill('gold', t('pay.not_invoiced')) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="2"><b><?= tt('ob.c_total') ?></b></td><td class="num"><b><?= e($m($sp)) ?></b></td><td colspan="3"></td></tr>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card" id="fr-inv">
    <h2>📄 <?= tt('fr.inv_register') ?> <span class="count"><?= count($D['invoices']) ?></span></h2>
    <?php if (!$D['invoices']): ?><p class="hint"><?= tt('pi.no_invoices') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.invoice_no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('pi.no') ?></th><th class="num"><?= tt('pt.amount') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th><?= tt('pi.paid_on') ?></th></tr></thead><tbody>
    <?php foreach ($D['invoices'] as $r): ?><tr><td class="mono"><b><?= e($r['invoice_no']) ?></b></td><td class="date"><?= e(fmt_date($r['invoice_date'])) ?></td><td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['proforma_id']]) ?>#invoices"><?= e($r['pi_no']) ?></a></td><td class="num"><?= e($m((float)$r['amount'])) ?></td><td class="num"><?= (float)$r['settled'] > 0 ? e($m((float)$r['settled'])) . ' <span class="hint">' . tt('pay.via_payments') . '</span>' : e($m((float)$r['paid_amount'])) ?></td><td class="date"><?= e(fmt_date($r['paid_at'])) ?></td></tr><?php endforeach; ?>
    <tr><td colspan="3"><b><?= tt('ob.c_total') ?></b></td><td class="num"><b><?= e($m($T['invoices'])) ?></b></td><td colspan="2"></td></tr>
    </tbody></table></div><?php endif; ?>
  </div>
</div>
<p class="hint"><?= tt('fr.method') ?></p>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
<?php layout_foot(); ?>

<?php
/** Parts to invoice — the lines of delivery notes on their way to accounting: procurement decides per line (to be invoiced / included in the
 *  offer), accounting registers the invoice (GAS-CP-R97-037). ?p=dn_invoicing[&state=open|invoice|offer|invoiced][&vessel=N] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('dni_visible') || !dni_visible()) { http_response_code(403); layout_head(t('dni.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$canD = dni_can_decide(); $canI = dni_can_invoice(); $C = dni_counts();
$state = in_array($_GET['state'] ?? '', DNI_STATES, true) ? (string)$_GET['state'] : ($canD && $C['open'] ? 'open' : ($canI ? 'invoice' : 'open')); $vid = (int)($_GET['vessel'] ?? 0);
$rows = dni_rows($state, $vid); $self = url('dn_invoicing', array_filter(['state' => $state, 'vessel' => $vid])); $mk = dni_markup();
$showCost = function_exists('pr_can') && pr_can(); $n2 = fn($v) => $v === null || $v === '' ? '' : number_format((float)$v, 2, ',', '.');
$vessels = []; foreach (dni_rows($state) as $r) if ($r['vessel_id']) $vessels[(int)$r['vessel_id']] = (string)$r['vessel']; asort($vessels);
$edit = $canD && in_array($state, ['open', 'invoice', 'offer'], true); $acct = $canI && in_array($state, ['invoice', 'invoiced'], true);
layout_head(t('dni.title'), $canD || !$canI ? 'inventory' : 'acc_reports');
?>
<p class="eyebrow"><a href="<?= url('delivery_notes') ?>"><?= tt('dn.title') ?></a> · <a href="<?= url('acc_reports') ?>"><?= tt('acc.title') ?></a></p>
<div class="page-head"><div><h1>📦 <?= tt('dni.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('dni.lede') ?></p></div></div>
<div class="tabs" style="margin:6px 0 12px;"><?php foreach (DNI_STATES as $st): ?><a href="<?= url('dn_invoicing', array_filter(['state' => $st, 'vessel' => $vid])) ?>"<?= $st === $state ? ' class="is-on"' : '' ?>><?= tt('dni.t_' . $st) ?><?= $C[$st] ? '<span class="n">' . (int)$C[$st] . '</span>' : '' ?></a><?php endforeach; ?></div>
<?php if ($vessels || $vid): ?><form method="get" action="index.php" class="filters" style="margin-bottom:10px;"><input type="hidden" name="p" value="dn_invoicing"><input type="hidden" name="state" value="<?= e($state) ?>">
  <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $k => $nm): ?><option value="<?= (int)$k ?>"<?= $vid === (int)$k ? ' selected' : '' ?>><?= e($nm) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('sb.filter') ?></button></div></form><?php endif; ?>
<div class="card">
  <p class="hint" style="margin:0 0 10px;"><?= e(t('dni.h_' . $state, ['m' => rtrim(rtrim(number_format($mk, 1, ',', ''), '0'), ',')])) ?></p>
  <?php if (!$rows): ?><p class="hint"><?= tt('dni.none') ?></p><?php else: ?>
  <form method="post" action="<?= $self ?>" data-lock id="dni-form"><?= csrf_field() ?><input type="hidden" name="state" value="<?= e($state) ?>"><input type="hidden" name="from" value="<?= e($state) ?>"><input type="hidden" name="vessel" value="<?= $vid ?>">
  <?php if ($edit): ?><div class="btn-row" style="margin-bottom:8px;"><span class="hint"><?= tt('dni.all') ?></span>
    <button class="btn btn--ghost btn--sm" type="button" data-dni-all="invoice"><?= tt('dni.s_invoice') ?></button><button class="btn btn--ghost btn--sm" type="button" data-dni-all="offer"><?= tt('dni.s_offer') ?></button><?php if ($state !== 'open'): ?><button class="btn btn--ghost btn--sm" type="button" data-dni-all="open"><?= tt('dni.s_open') ?></button><?php endif; ?></div><?php endif; ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><?php if ($acct): ?><th><input type="checkbox" id="dni-tick" title="<?= tt('dni.tick_all') ?>"></th><?php endif; ?><th><?= tt('dni.c_dn') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.code') ?></th><th style="min-width:240px;"><?= tt('dni.c_part') ?></th><th class="num"><?= tt('dni.c_qty') ?></th><?php if ($showCost): ?><th class="num"><?= tt('dni.c_cost') ?></th><?php endif; ?><th class="num"><?= tt('dni.c_price') ?></th><th class="num"><?= tt('dni.c_total') ?></th><?php if ($edit): ?><th><?= tt('dni.c_decision') ?></th><?php endif; ?><?php if ($state === 'invoiced'): ?><th><?= tt('acc.invoice_ref') ?></th><?php endif; ?><?php if ($state !== 'open'): ?><th><?= tt('dni.c_by') ?></th><?php endif; ?></tr></thead><tbody>
  <?php $lastV = null; $tot = 0.0; $ncol = 6 + ($acct ? 1 : 0) + ($showCost ? 1 : 0) + ($edit ? 1 : 0) + ($state === 'invoiced' ? 1 : 0) + ($state !== 'open' ? 1 : 0) + 1;
  foreach ($rows as $r): $vk = (string)($r['vessel'] ?: ($r['company'] ?: '—')); if ($vk !== $lastV): $lastV = $vk; ?><tr class="grp-row"><td colspan="<?= $ncol ?>">⚓ <b><?= e($vk) ?></b></td></tr><?php endif;
    $cost = $r['cost'] !== null ? (float)$r['cost'] : null; $price = $r['inv_price'] !== null ? (float)$r['inv_price'] : ($state === 'open' && $cost !== null ? round($cost * (1 + $mk / 100), 2) : null); $lt = $price !== null ? round($price * (float)$r['qty'], 2) : null; if ($lt !== null && $state !== 'offer') $tot += $lt; $lid = (int)$r['id']; ?>
  <tr>
    <?php if ($acct): ?><td><input type="checkbox" name="ids[]" value="<?= $lid ?>" class="dni-chk"></td><?php endif; ?>
    <td><a href="<?= url('delivery_note', ['id' => (int)$r['dn_id']]) ?>"><span class="mono"><?= e($r['dn_no']) ?></span></a><br><span class="hint"><?= e(fmt_date($r['issued_at'])) ?></span></td>
    <td class="hint"><?= e(trim((string)$r['project_code'] . ' ' . (string)$r['sub_code'])) ?: '—' ?><?= $r['kind'] === 'customer' && $r['company'] ? '<br>' . e($r['company']) : '' ?></td>
    <td class="mono"><?= e((string)$r['item_code']) ?></td><td><?= e((string)$r['description']) ?></td>
    <td class="num"><?= e(rtrim(rtrim(number_format((float)$r['qty'], 3, ',', '.'), '0'), ',')) ?> <span class="hint"><?= e((string)$r['unit']) ?></span></td>
    <?php if ($showCost): ?><td class="num hint"><?= $cost !== null ? e($n2($cost)) : '—' ?></td><?php endif; ?>
    <td class="num"><?php if ($edit): ?><input name="price[<?= $lid ?>]" inputmode="decimal" value="<?= e($n2($price)) ?>" style="width:92px;text-align:right;"><?php else: ?><?= $price !== null ? e($n2($price)) : '—' ?><?php endif; ?></td>
    <td class="num"><b><?= $lt !== null ? e($n2($lt)) : '—' ?></b></td>
    <?php if ($edit): ?><td><select name="d[<?= $lid ?>]" data-nosearch class="dni-sel"><?php if ($state === 'open'): ?><option value="">—</option><?php endif; ?><?php foreach (['invoice', 'offer', 'open'] as $st): if ($st === 'open' && $state === 'open') continue; ?><option value="<?= $st ?>"<?= $st === $state ? ' selected' : '' ?>><?= tt('dni.s_' . $st) ?></option><?php endforeach; ?></select></td><?php endif; ?>
    <?php if ($state === 'invoiced'): ?><td><b><?= e((string)$r['inv_ref']) ?></b><br><span class="hint"><?= $r['inv_date'] ? e(fmt_date($r['inv_date'])) : '' ?> · <?= e((string)$r['inv_name']) ?></span></td><?php endif; ?>
    <?php if ($state !== 'open'): ?><td class="hint"><?= e((string)$r['dec_name']) ?><?= $r['dec_at'] ? '<br>' . e(fmt_date(substr((string)$r['dec_at'], 0, 10))) : '' ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  <?php if ($state !== 'offer'): ?><tr><td colspan="<?= $ncol - 1 - ($edit ? 1 : 0) - ($state === 'invoiced' ? 1 : 0) - ($state !== 'open' ? 1 : 0) - 1 ?>"><b><?= tt('acc.vd_total') ?></b></td><td></td><td class="num"><b><?= e($n2($tot)) ?></b></td><?php for ($i = 0; $i < ($edit ? 1 : 0) + ($state === 'invoiced' ? 1 : 0) + ($state !== 'open' ? 1 : 0); $i++): ?><td></td><?php endfor; ?></tr><?php endif; ?>
  </tbody></table></div>
  <?php if ($edit): ?><div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold btn--sm" type="submit" name="action" value="dni_decide">✓ <?= tt('dni.save') ?></button><span class="hint"><?= tt('dni.save_hint') ?></span></div><?php endif; ?>
  <?php if ($acct && $state === 'invoice'): ?><div class="grid grid-4" style="margin-top:12px;">
    <div class="field"><label><?= tt('acc.invoice_ref') ?></label><input name="invoice_ref" maxlength="120" placeholder="INV-2026-…"></div>
    <div class="field"><label><?= tt('acc.invoice_date') ?></label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit" name="action" value="dni_invoice">🧾 <?= tt('dni.mark_invoiced') ?></button></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--ghost btn--sm" type="submit" name="action" value="dni_back" data-confirm="<?= tt('dni.back_q') ?>">↩ <?= tt('dni.back_proc') ?></button></div>
  </div><?php elseif ($acct && $state === 'invoiced'): ?><div class="btn-row" style="margin-top:10px;"><button class="btn btn--ghost btn--sm" type="submit" name="action" value="dni_back" data-confirm="<?= tt('dni.undo_q') ?>">↩ <?= tt('dni.undo') ?></button><span class="hint"><?= tt('dni.undo_hint') ?></span></div><?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){
  document.querySelectorAll('[data-dni-all]').forEach(function(b){ b.addEventListener('click', function(){ var v = b.getAttribute('data-dni-all'); document.querySelectorAll('select.dni-sel').forEach(function(s){ if ([].some.call(s.options, function(o){ return o.value === v; })) s.value = v; }); }); });
  var t = document.getElementById('dni-tick'); if (t) t.addEventListener('change', function(){ document.querySelectorAll('.dni-chk').forEach(function(c){ c.checked = t.checked; }); });
})();</script>
<?php layout_foot();

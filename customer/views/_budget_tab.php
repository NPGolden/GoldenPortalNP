<?php
/** Partial: Sub-project › Budget & actual tab (Option A of GAS-CP-R34-001). expects $sp, $id, $backTo */
if (!function_exists('bg_can') || !bg_can()) return;
$C = bg_calc($id, $sp); $canE = bg_can_edit($sp); $B = $C['budget']; $hist = bg_history($id); $disc = bg_disciplines();
$pre = !$B ? bg_prefill_from_offers($id) : [];
$m = fn(float $v) => number_format($v, 0, ',', '.'); $sg = fn(float $v) => ($v >= 0 ? '+ ' : '− ') . number_format(abs($v), 0, ',', '.');
$ov = $C['offer_value']; $cur = 'EUR';
$kindL = ['order' => t('bg.k_order'), 'invoice' => t('bg.k_invoice'), 'other' => t('bg.k_other')];
$bgSplit = function_exists('eng_split_disciplines') && eng_has() && bg_has_sections() ? eng_split_disciplines() : []; $bgSec = []; if (function_exists('eng_sections') && eng_has()) foreach (eng_sections() as $es) foreach ($es['disciplines'] as $dd => $dn) $bgSec[$dd] = isset($bgSec[$dd]) ? $bgSec[$dd] . ', ' . $es['name'] : $es['name'];   // R48-001
$editC = (int)($_GET['cost'] ?? 0); $ec = $editC ? one('SELECT * FROM sp_costs WHERE id = ? AND subproject_id = ?', [$editC, $id]) : null;
?>
<div class="bg-tiles">
  <div class="bg-tile"><span><?= tt('bg.budget_hours') ?></span><b><?= e(bg_h($C['budget_h'])) ?> h</b><small><?= $B ? e(t('bg.rev_by', ['r' => 'R' . (int)$B['revision'], 'u' => (string)$B['created_name'], 'd' => fmt_date($B['created_at'])])) : tt('bg.no_budget_yet') ?></small></div>
  <div class="bg-tile"><span><?= tt('bg.actual_hours') ?></span><b><?= e(bg_h($C['actual_h'])) ?> h <em class="bg-<?= bg_pct_tone($C['hours_pct']) ?>"><?= $C['budget_h'] > 0 ? $C['hours_pct'] . ' %' : '—' ?></em></b><small><?= tt('bg.progress') ?> <?= $C['progress'] ?> % · <?= tt('bg.earned') ?> <?= e(bg_h($C['earned_h'])) ?> h</small></div>
  <div class="bg-tile"><span><?= tt('bg.parts_b_a') ?></span><b><?= e($m($C['parts_budget'])) ?> / <em class="bg-<?= bg_pct_tone($C['parts_pct']) ?>"><?= e($m($C['parts_committed'])) ?> €</em></b><small><?= $C['parts_budget'] > 0 ? $C['parts_pct'] . ' %' : '—' ?><?= in_array('parts_over', $C['flags'], true) ? ' — ' . tt('bg.over_by') . ' ' . e($m($C['parts_committed'] - $C['parts_budget'])) . ' €' : '' ?> · <?= tt('bg.invoiced') ?> <?= e($m($C['parts_invoiced'])) ?> €</small></div>
  <div class="bg-tile bg-tile--navy"><span><?= tt('bg.live_result') ?></span><b class="<?= $C['result'] >= 0 ? 'bg-gold' : 'bg-neg' ?>"><?= e($sg($C['result'])) ?> €</b><small><?= tt('bg.offer_value') ?> <?= e($m($ov)) ?> € · <?= tt('bg.cost_to_date') ?> <?= e($m($C['cost_to_date'])) ?> € · <?= tt('bg.forecast') ?> <?= e($sg($C['forecast_result'])) ?> €</small></div>
</div>

<div class="cols">
  <div class="card">
    <div class="card-head"><h2><?= tt('bg.sec_disc') ?></h2><?php if ($canE): ?><a class="btn btn--ghost btn--sm" href="#bg-edit">✎ <?= tt('bg.edit_budget') ?></a><?php endif; ?></div>
    <table class="tbl bg-tbl"><thead><tr><th><?= tt('bg.discipline') ?></th><th class="num"><?= tt('bg.budget_h') ?></th><th class="num"><?= tt('bg.actual_h') ?></th><th class="num"><?= tt('bg.remaining') ?></th><th style="width:34%;"><?= tt('bg.used') ?></th></tr></thead><tbody>
    <?php $engR = function_exists('eng_has') && eng_has() ? eng_rows($id) : []; $engX = []; foreach ($engR as $er) if ($er['extra'] > 0) foreach ($er['disciplines'] as $dd => $dn) $engX[$dd] = $er;   // R47-002: approved extra engineering hours, shown over the agreed budget
    foreach ($C['rows'] as $k => $r): $ex = $engX[$k] ?? null; ?><tr><td><?= e($r['name']) ?><?php if ($ex): ?><br><a class="hint" style="white-space:nowrap;" href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering']) ?>" title="<?= e($ex['name']) ?> — <?= tt('eng.over_budget_s') ?>">📐 <b style="color:var(--gold-text);">+<?= e(bg_h($ex['extra'])) ?> h</b> <?= tt('eng.extra_s') ?></a><?php endif; ?></td><td class="num"><?= e(bg_h($r['budget_h'])) ?></td><td class="num"><?= e(bg_h($r['actual_h'])) ?></td><td class="num<?= $r['remaining_h'] < 0 ? ' bg-neg' : '' ?>"><?= e(bg_h($r['remaining_h'])) ?></td><td><?= bg_bar($r['pct'], $r['tone']) ?></td></tr><?php endforeach; ?>
    <?php if (!$C['rows']): ?><tr><td colspan="5" class="hint"><?= tt('bg.no_rows') ?></td></tr><?php endif; ?>
    </tbody><tfoot><tr><td><b><?= tt('ob.c_total') ?></b></td><td class="num"><b><?= e(bg_h($C['budget_h'])) ?></b></td><td class="num"><b><?= e(bg_h($C['actual_h'])) ?></b></td><td class="num"><b><?= e(bg_h($C['remaining_h'])) ?></b></td><td><?= bg_bar($C['hours_pct'], 'navy', $C['progress']) ?></td></tr></tfoot></table>
    <p class="hint" style="margin:10px 0 0;"><?= tt('bg.disc_hint') ?></p>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= tt('bg.sec_parts') ?></h2><a class="btn btn--ghost btn--sm" href="#bg-cost">+ <?= tt('bg.add_cost') ?></a></div>
    <div class="bg-mini"><div><span><?= tt('bg.budgeted') ?></span><b><?= e($m($C['parts_budget'])) ?> €</b></div><div><span><?= tt('bg.committed') ?></span><b><?= e($m($C['parts_committed'])) ?> €</b></div><div><span><?= tt('bg.invoiced') ?></span><b><?= e($m($C['parts_invoiced'])) ?> €</b></div></div>
    <?php if (!$C['costs']): ?><p class="hint"><?= tt('bg.no_costs') ?></p><?php else: ?>
    <table class="tbl bg-tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('bg.kind') ?></th><th><?= tt('bg.supplier') ?> · <?= tt('ob.c_desc') ?></th><th><?= tt('bg.ref') ?></th><th class="num"><?= tt('bg.s_amount') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($C['costs'] as $c): ?><tr><td><?= e(fmt_date($c['cost_date'])) ?></td><td><?= pill($c['kind'] === 'invoice' ? 'navy' : ($c['kind'] === 'other' ? 'mute' : 'gold'), $kindL[$c['kind']] ?? $c['kind']) ?></td><td><?= $c['supplier'] ? '<b>' . e($c['supplier']) . '</b> — ' : '' ?><?= e($c['description']) ?><?= $c['file_id'] ? ' <a href="' . url('file', ['id' => $c['file_id']]) . '" target="_blank">📎</a>' : '' ?></td><td class="hint"><?= e($c['ref_no'] ?? '') ?></td><td class="num"><?= e(number_format((float)$c['amount'], 2, ',', '.')) ?></td>
      <td class="ob-act"><a class="linkbtn" href="<?= url('subproject', ['id' => $id, 'tab' => 'budget', 'cost' => $c['id']]) ?>#bg-cost">✎</a> <form method="post" action="<?= $backTo ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="bg_cost_delete"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="cost_id" value="<?= $c['id'] ?>"><button class="linkbtn" type="submit" data-confirm="<?= tt('bg.cost_delete_confirm') ?>">×</button></form></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <form method="post" action="<?= $backTo ?>#bg-cost" id="bg-cost" enctype="multipart/form-data" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="bg_cost_save"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="cost_id" value="<?= (int)($ec['id'] ?? 0) ?>">
      <h3 class="ed-h"><?= $ec ? tt('bg.edit_cost') : tt('bg.add_cost') ?></h3>
      <div class="grid grid-4">
        <div class="field" style="margin:0;"><label><?= tt('th.date') ?></label><input type="date" name="cost_date" value="<?= e($ec['cost_date'] ?? date('Y-m-d')) ?>" required></div>
        <div class="field" style="margin:0;"><label><?= tt('bg.kind') ?></label><select name="kind" data-nosearch><?php foreach ($kindL as $k => $l): ?><option value="<?= $k ?>"<?= ($ec['kind'] ?? 'order') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="margin:0;"><label><?= tt('bg.supplier') ?></label><input name="supplier" maxlength="120" value="<?= e($ec['supplier'] ?? '') ?>"></div>
        <div class="field" style="margin:0;"><label><?= tt('bg.ref') ?></label><input name="ref_no" maxlength="60" value="<?= e($ec['ref_no'] ?? '') ?>" placeholder="PO / INV"></div>
      </div>
      <div class="grid grid-4" style="margin-top:8px;">
        <div class="field" style="margin:0;grid-column:span 2;"><label><?= tt('ob.c_desc') ?></label><input name="description" maxlength="255" required value="<?= e($ec['description'] ?? '') ?>"></div>
        <div class="field" style="margin:0;"><label><?= tt('bg.s_amount') ?> (<?= $cur ?>)</label><input name="amount" inputmode="decimal" required value="<?= $ec ? e(number_format((float)$ec['amount'], 2, '.', '')) : '' ?>"></div>
        <div class="field" style="margin:0;"><label><?= tt('of.file') ?></label><input type="file" name="file" accept=".pdf,.jpg,.png,.xlsx"></div>
      </div>
      <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold btn--sm" type="submit"><?= $ec ? tt('btn.save') : '+ ' . tt('bg.add') ?></button><?php if ($ec): ?><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'budget']) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
    <p class="hint" style="margin:10px 0 0;"><?= tt('bg.costs_hint') ?></p>
  </div>
</div>

<div class="cols">
  <div class="card">
    <h2><?= tt('bg.sec_result') ?></h2>
    <table class="bg-result">
      <tr><td><?= tt('bg.offer_value') ?> <span class="hint"><?= $B && $B['offer_value'] !== null ? tt('bg.ov_typed') : tt('bg.ov_offers') ?></span></td><td class="num"><b><?= e($m($ov)) ?> €</b></td></tr>
      <tr class="hint"><td><?= tt('bg.hours_cost_budget') ?> (<?= e(bg_h($C['budget_h'])) ?> h × <?= tt('bg.rate') ?>)</td><td class="num"><?= e($m($C['budget_cost'])) ?> €</td></tr>
      <tr><td><?= tt('bg.hours_cost_actual') ?> (<?= e(bg_h($C['actual_h'])) ?> h)</td><td class="num"><b><?= e($m($C['actual_cost'])) ?> €</b></td></tr>
      <tr class="hint"><td><?= tt('bg.parts') ?> — <?= tt('bg.budgeted') ?></td><td class="num"><?= e($m($C['parts_budget'])) ?> €</td></tr>
      <tr><td><?= tt('bg.parts') ?> — <?= tt('bg.committed') ?></td><td class="num"><b><?= e($m($C['parts_committed'])) ?> €</b></td></tr>
      <tr class="bg-sum"><td><?= tt('bg.cost_to_date') ?></td><td class="num"><b><?= e($m($C['cost_to_date'])) ?> €</b></td></tr>
      <tr><td><?= tt('bg.forecast_cost') ?> <span class="hint">(<?= e(bg_h($C['remaining_h'])) ?> h <?= tt('bg.remaining_l') ?> + <?= tt('bg.parts_max') ?>)</span></td><td class="num"><b><?= e($m($C['forecast_cost'])) ?> €</b></td></tr>
      <tr class="bg-sum bg-big"><td><?= tt('bg.margin_line') ?></td><td class="num"><span class="hint"><?= e($sg($C['budget_result'])) ?></span> → <b class="<?= $C['forecast_result'] >= 0 ? 'bg-green' : 'bg-neg' ?>"><?= e($sg($C['forecast_result'])) ?> €<?= $ov > 0 ? ' (' . (int) round($C['forecast_result'] / $ov * 100) . ' %)' : '' ?></b></td></tr>
    </table>
  </div>
  <div class="card">
    <h2><?= tt('bg.sec_chart') ?></h2>
    <?php $mx = max(1.0, $ov, $C['budget_cost'], $C['actual_cost'], $C['parts_budget'], $C['parts_committed']); ?>
    <div class="bg-rows">
      <div><span><?= tt('bg.hours_budget') ?></span><?= bg_bar($C['budget_cost'] / $mx * 100, 'grey', null, '16px') ?><b><?= e($m($C['budget_cost'])) ?></b></div>
      <div><span><?= tt('bg.hours_actual') ?></span><?= bg_bar($C['actual_cost'] / $mx * 100, $C['actual_cost'] > $C['budget_cost'] && $C['budget_cost'] > 0 ? 'red' : 'navy', null, '16px') ?><b><?= e($m($C['actual_cost'])) ?></b></div>
      <div><span><?= tt('bg.parts_budget') ?></span><?= bg_bar($C['parts_budget'] / $mx * 100, 'grey', null, '16px') ?><b><?= e($m($C['parts_budget'])) ?></b></div>
      <div><span><?= tt('bg.parts_actual') ?></span><?= bg_bar($C['parts_committed'] / $mx * 100, in_array('parts_over', $C['flags'], true) ? 'red' : 'navy', null, '16px') ?><b><?= e($m($C['parts_committed'])) ?></b></div>
      <div><span><?= tt('bg.offer_value') ?></span><?= bg_bar($ov / $mx * 100, 'amber', null, '16px') ?><b><?= e($m($ov)) ?></b></div>
    </div>
    <p class="hint" style="margin:10px 0 0;"><?= tt('bg.chart_hint') ?></p>
  </div>
</div>

<?php if ($canE): ?>
<div class="card" id="bg-edit">
  <div class="card-head"><h2><?= tt('bg.edit_budget') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= $B ? e(t('bg.next_rev', ['r' => 'R' . ((int)$B['revision'] + 1)])) : 'R0' ?></span></h2></div>
  <form method="post" action="<?= $backTo ?>#bg-edit" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bg_save"><input type="hidden" name="subproject_id" value="<?= $id ?>">
    <table class="tbl bg-tbl" style="max-width:720px;"><thead><tr><th><?= tt('bg.discipline') ?></th><th class="num"><?= tt('bg.hours') ?></th><th class="num"><?= tt('bg.rate') ?> (€/h)</th><th class="num"><?= tt('bg.budget_eur') ?></th></tr></thead><tbody>
    <?php foreach ($disc as $d): $k = (int)$d['id']; $h = (float)($B['hours'][$k]['hours'] ?? ($pre[$k] ?? 0)); $r = (float)($B['hours'][$k]['rate'] ?? 0) ?: (function_exists('br_hour_cost') ? br_hour_cost($d, 'normal', null, $id) : (float)($d['rate_normal'] ?? 0)); ?>
      <?php $spl = $bgSplit[$k] ?? null; if ($spl): ?><tr class="bg-split"><td><?= e($d['name']) ?><br><span class="hint" style="font-size:11px;"><?= tt('eng.split_hint') ?></span></td><td class="num"><?php $tot = 0.0; foreach ($spl as $sec => $nm): $hs = (float)($B['sections'][$k][$sec] ?? ($B && !isset($B['sections'][$k]) && $h > 0 ? $h / count($spl) : 0)); $tot += $hs; ?><label class="bg-split__l"><span><?= e($nm) ?></span><input name="hs[<?= $k ?>][<?= (int)$sec ?>]" value="<?= $hs > 0 ? e(bg_h($hs)) : '' ?>" inputmode="decimal" class="ob-in ob-in--w bg-hs"></label><?php endforeach; ?><input type="hidden" name="h[<?= $k ?>]" value="<?= e(bg_h($tot)) ?>" class="bg-h"></td>
      <?php else: ?><tr><td><?= e($d['name']) ?><?php if ($bgSec[$k] ?? null): ?><br><span class="hint" style="font-size:11px;">📐 <?= e($bgSec[$k]) ?></span><?php endif; ?></td><td class="num"><input name="h[<?= $k ?>]" value="<?= $h > 0 ? e(bg_h($h)) : '' ?>" inputmode="decimal" class="ob-in ob-in--w bg-h"></td><?php endif; ?><td class="num"><input name="r[<?= $k ?>]" value="<?= $r > 0 ? e(number_format($r, 2, '.', '')) : '' ?>" inputmode="decimal" class="ob-in ob-in--w bg-r"></td><td class="num bg-line"><?= $h > 0 ? e($m($h * $r)) : '—' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <div class="grid grid-3" style="margin-top:10px;max-width:720px;">
      <div class="field" style="margin:0;"><label><?= tt('bg.parts_budget') ?> (€)</label><input name="parts_budget" inputmode="decimal" value="<?= $B ? e(number_format((float)$B['parts_budget'], 2, '.', '')) : '' ?>"></div>
      <div class="field" style="margin:0;"><label><?= tt('bg.offer_value') ?> (€) <span class="hint"><?= tt('bg.ov_blank') ?></span></label><input name="offer_value" inputmode="decimal" value="<?= $B && $B['offer_value'] !== null ? e(number_format((float)$B['offer_value'], 2, '.', '')) : '' ?>" placeholder="<?= e($m(bg_offer_value($id))) ?>"></div>
      <div class="field" style="margin:0;"><label><?= tt('th.remarks') ?></label><input name="notes" maxlength="500" value=""></div>
    </div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= e(t('bg.save_rev', ['r' => $B ? 'R' . ((int)$B['revision'] + 1) : 'R0'])) ?></button><?php if (!$B && $pre): ?><span class="hint"><?= tt('bg.prefilled') ?></span><?php endif; ?></div>
  </form>
  <?php if ($hist): ?><h3 class="ed-h" style="margin-top:14px;"><?= tt('bg.history') ?></h3>
  <table class="tbl bg-tbl" style="max-width:720px;"><thead><tr><th><?= tt('sub.rev') ?></th><th><?= tt('th.date') ?></th><th><?= tt('wv.by') ?></th><th class="num"><?= tt('bg.hours') ?></th><th class="num"><?= tt('bg.budget_eur') ?></th><th class="num"><?= tt('bg.parts') ?></th><th><?= tt('th.remarks') ?></th></tr></thead><tbody>
  <?php foreach ($hist as $hh): ?><tr><td><b>R<?= (int)$hh['revision'] ?></b></td><td><?= e(fmt_date($hh['created_at'])) ?></td><td><?= e($hh['created_name'] ?? '') ?></td><td class="num"><?= e(bg_h((float)$hh['total_hours'])) ?></td><td class="num"><?= e($m((float)$hh['hours_cost'])) ?></td><td class="num"><?= e($m((float)$hh['parts_budget'])) ?></td><td class="hint"><?= e($hh['notes'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){ document.querySelectorAll('#bg-edit tr.bg-split').forEach(function(tr){ var hs = tr.querySelectorAll('.bg-hs'), h = tr.querySelector('.bg-h'); function sum(){ var t = 0; hs.forEach(function(i){ t += parseFloat(String(i.value).replace(',', '.')) || 0; }); h.value = t; h.dispatchEvent(new Event('input')); } hs.forEach(function(i){ i.addEventListener('input', sum); }); }); })();
(function(){ var rows = document.querySelectorAll('#bg-edit tbody tr'); rows.forEach(function(tr){ var h = tr.querySelector('.bg-h'), r = tr.querySelector('.bg-r'), o = tr.querySelector('.bg-line'); if (!h || !r || !o) return; function f(){ var v = (parseFloat((h.value||'0').replace(',','.'))||0) * (parseFloat((r.value||'0').replace(',','.'))||0); o.textContent = v > 0 ? v.toLocaleString('de-DE', {maximumFractionDigits:0}) : '—'; } h.addEventListener('input', f); r.addEventListener('input', f); }); })();
</script>
<?php endif; ?>

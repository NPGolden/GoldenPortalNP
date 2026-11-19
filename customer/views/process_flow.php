<?php
/** Support › Process flow — from the offer to the delivery: who does what, where quantities, stock and money are registered (GAS-CP-R97-131).  ?p=process_flow
 *  Portrait, one column of steps; quantities and stock on the left, money on the right. No figures of the company — the process only. */
require __DIR__ . '/_layout.php';
$u = require_staff();
$can = fn(string $pg) => true;
/* phase => [step key, icon, page to open, stock badges (in | res | out | none)] */
$FLOW = [
    'sales' => [['sb', '🧮', 'system_budgets', []], ['offer', '📄', 'offers', []], ['approved', '✅', 'offers', []]],
    'eng'   => [['bom', '🧾', 'projects', []]],
    'buy'   => [['req', '📝', 'requisitions', ['res']], ['rfq', '📨', 'purchase_orders', []], ['po', '🛒', 'purchase_orders', []]],
    'wh'    => [['receipt', '📥', 'purchase_orders', ['in', 'res']], ['issue', '📤', 'requisitions', ['out']], ['dn', '🚚', 'delivery_notes', ['out']]],
    'acc'   => [['sinv', '🧾', 'payables', []], ['cinv', '💶', 'proformas', []]],
];
layout_head(t('pf.title'), 'support');
?>
<style>
.pf { max-width: 980px; margin: 0 auto; }
.pf-legend { display: flex; flex-wrap: wrap; gap: 14px; justify-content: center; margin: 6px 0 14px; font-size: 13px; }
.pf-legend i { display: inline-block; width: 14px; height: 14px; border-radius: 3px; vertical-align: -2px; margin-right: 6px; }
.pf-phase { text-align: center; margin: 18px 0 10px; position: relative; }
.pf-phase span { display: inline-block; background: #1F3A5F; color: #fff; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; font-size: 12px; padding: 5px 16px; border-radius: 999px; position: relative; z-index: 1; }
.pf-phase::before { content: ""; position: absolute; left: 0; right: 0; top: 50%; border-top: 1px dashed #C8CED6; }
.pf-row { display: grid; grid-template-columns: 1fr 34px minmax(210px, 260px) 34px 1fr; align-items: center; }
.pf-side { border: 1px solid; border-radius: 10px; padding: 9px 12px; font-size: 13px; line-height: 1.4; min-height: 64px; }
.pf-q { border-color: #2E7D32; background: #EEF6EF; }
.pf-m { border-color: #C9A227; background: #FBF4DC; }
.pf-side b.h { display: block; font-size: 11px; letter-spacing: .07em; text-transform: uppercase; margin-bottom: 3px; }
.pf-q b.h { color: #2E7D32; } .pf-m b.h { color: #8a6d00; }
.pf-none { border-style: dashed; background: transparent; color: #8A94A0; }
.pf-link { height: 2px; }
.pf-link.q { background: #2E7D32; } .pf-link.m { background: #C9A227; } .pf-link.x { background: transparent; }
.pf-step { background: #1F3A5F; color: #fff; border-radius: 12px; padding: 10px 12px; text-align: center; text-decoration: none; display: block; box-shadow: 0 1px 2px rgba(0,0,0,.12); }
.pf-step:hover { background: #274a79; color: #fff; }
.pf-step .n { display: inline-flex; width: 22px; height: 22px; border-radius: 50%; background: #C9A227; color: #1F3A5F; font-weight: 700; font-size: 12px; align-items: center; justify-content: center; margin-right: 6px; }
.pf-step .t { font-weight: 700; font-size: 14px; }
.pf-step .w { display: block; font-size: 11.5px; opacity: .85; margin-top: 3px; }
.pf-down { display: grid; grid-template-columns: 1fr 34px minmax(210px, 260px) 34px 1fr; }
.pf-down div { grid-column: 3; height: 22px; position: relative; }
.pf-down div::before { content: ""; position: absolute; left: 50%; top: 0; bottom: 6px; width: 2px; margin-left: -1px; background: #1F3A5F; }
.pf-down div::after { content: ""; position: absolute; left: 50%; bottom: 0; margin-left: -5px; border-left: 5px solid transparent; border-right: 5px solid transparent; border-top: 7px solid #1F3A5F; }
.pf-stk { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 999px; padding: 1px 8px; margin: 4px 4px 0 0; border: 1px solid #2E7D32; color: #2E7D32; background: #fff; white-space: nowrap; }
.pf-stk.out { border-color: #b3261e; color: #b3261e; } .pf-stk.res { border-color: #1F3A5F; color: #1F3A5F; }
.pf-notes { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 20px; }
@media (max-width: 760px) { .pf-row { grid-template-columns: 1fr; gap: 6px; } .pf-link { display: none; } .pf-row .pf-step { order: -1; } .pf-down, .pf-down div { display: block; } .pf-down div { margin: 0 auto; width: 20px; } .pf-notes { grid-template-columns: 1fr; } }
@media print { .sidebar, .topbar, .mn-back, nav, header, footer, .btn-row { display: none !important; } .pf { max-width: none; } .pf-row, .pf-phase, .card { break-inside: avoid; } body { background: #fff; } }
</style>
<div class="pf">
<div class="page-head"><div><p class="eyebrow"><a href="<?= url('support') ?>">‹ <?= tt('nav.support') ?></a></p><h1>🧭 <?= tt('pf.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pf.lede') ?></p></div>
  <div class="btn-row"><button class="btn btn--ghost" type="button" data-print>🖨 <?= tt('pf.print') ?></button></div></div>
<div class="card">
  <div class="pf-legend"><span><i style="background:#1F3A5F;"></i><?= tt('pf.lg_step') ?></span><span><i style="background:#EEF6EF;border:1px solid #2E7D32;"></i><?= tt('pf.lg_qty') ?></span><span><i style="background:#FBF4DC;border:1px solid #C9A227;"></i><?= tt('pf.lg_money') ?></span>
    <span><b class="pf-stk">＋ <?= tt('pf.stk_in') ?></b><b class="pf-stk res">◼ <?= tt('pf.stk_res') ?></b><b class="pf-stk out">− <?= tt('pf.stk_out') ?></b></span></div>
  <?php $n = 0; $first = true; foreach ($FLOW as $ph => $steps): ?>
    <?php if (!$first): ?><div class="pf-down"><div></div></div><?php endif; $first = false; ?>
    <div class="pf-phase"><span><?= tt('pf.ph_' . $ph) ?></span></div>
    <?php foreach ($steps as $i => [$k, $ico, $pg, $stk]): $n++; $q = t('pf.' . $k . '_q'); $m = t('pf.' . $k . '_m'); ?>
      <?php if ($i): ?><div class="pf-down"><div></div></div><?php endif; ?>
      <div class="pf-row">
        <div class="pf-side pf-q<?= $q === '—' ? ' pf-none' : '' ?>"><b class="h"><?= tt('pf.h_qty') ?></b><?= e($q) ?><?php if ($stk): ?><br><?php foreach ($stk as $s): ?><b class="pf-stk <?= $s ?>"><?= $s === 'in' ? '＋' : ($s === 'out' ? '−' : '◼') ?> <?= tt('pf.stk_' . $s) ?></b><?php endforeach; endif; ?></div>
        <div class="pf-link <?= $q === '—' ? 'x' : 'q' ?>"></div>
        <a class="pf-step" href="<?= url($pg) ?>" title="<?= tt('pf.open') ?>"><span class="n"><?= $n ?></span><span class="t"><?= $ico ?> <?= tt('pf.' . $k) ?></span><span class="w"><?= tt('pf.' . $k . '_w') ?></span></a>
        <div class="pf-link <?= $m === '—' ? 'x' : 'm' ?>"></div>
        <div class="pf-side pf-m<?= $m === '—' ? ' pf-none' : '' ?>"><b class="h"><?= tt('pf.h_money') ?></b><?= e($m) ?></div>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
</div>
<div class="pf-notes">
  <div class="card"><h3 style="margin-top:0;">📦 <?= tt('pf.inv_title') ?></h3><ul style="margin:0;padding-left:18px;"><?php foreach (range(1, 6) as $i): ?><li><?= tt('pf.inv_' . $i) ?></li><?php endforeach; ?></ul></div>
  <div class="card"><h3 style="margin-top:0;">🚚 <?= tt('pf.dn_title') ?></h3><ul style="margin:0;padding-left:18px;"><?php foreach (range(1, 5) as $i): ?><li><?= tt('pf.dn_' . $i) ?></li><?php endforeach; ?></ul></div>
</div>
<p class="hint" style="text-align:center;margin-top:12px;"><?= tt('pf.foot') ?></p>
</div>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>
<?php layout_foot(); ?>

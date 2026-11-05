<?php
/** Procurement › Inventory › Import — the ERP warehouse file (Κωδικός · Περιγραφή · Υπόλοιπο · Μ.Μ · Τελ. τιμή · Θέση · Κατηγορία · Barcode · Κωδ. εργοστασίου · Κατασκευαστής), preview then apply (GAS-CP-R79-001). */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('pr_admin') || !pr_admin()) { http_response_code(403); exit(t('err.noaccess')); }
if (isset($_GET['discard'])) unset($_SESSION['inv_import']);
$prev = $_SESSION['inv_import'] ?? null; $res = $_SESSION['inv_import_result'] ?? null; unset($_SESSION['inv_import_result']);
$whs = stk_warehouses(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('stk.import'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a></p>
<div class="page-head"><div><h1>⤒ <?= tt('stk.import') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('stk.import_lede') ?></p></div></div>
<?php if ($res): ?><div class="flash flash--good"><b><?= tt('stk.imp_done') ?></b> — <?= e(t('stk.imp_done_s', $res)) ?></div><?php endif; ?>
<div class="cols">
<div>
  <div class="card">
    <h2>1 · <?= tt('stk.imp_upload') ?></h2>
    <form method="post" action="<?= url('inventory_import') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="stk_import_parse">
      <div class="field"><label><?= tt('im.file') ?> (.xlsx)</label><input type="file" name="file" accept=".xlsx" required></div>
      <p class="hint"><?= tt('stk.imp_cols') ?></p>
      <button class="btn btn--gold" type="submit"><?= tt('stk.imp_preview') ?></button>
    </form>
  </div>
  <?php if ($prev): ?>
  <div class="card">
    <h2>2 · <?= tt('stk.imp_check') ?></h2>
    <div class="kpi kpi--4" style="margin-bottom:10px;"><div class="stat"><b><?= count($prev['rows']) ?></b><?= tt('stk.imp_rows') ?></div><div class="stat green"><b><?= (int)$prev['n_new'] ?></b><?= tt('stk.imp_new') ?></div><div class="stat"><b><?= (int)$prev['n_upd'] ?></b><?= tt('stk.imp_upd') ?></div><div class="stat gold"><b><?= (int)$prev['n_price'] ?></b><?= tt('stk.imp_priced') ?><span class="s"><?= e(t('stk.imp_qty_n', ['n' => $prev['n_qty']])) ?></span></div></div>
    <p class="hint"><?= tt('stk.imp_mapped') ?>: <?php foreach ($prev['map'] as $k => $col): ?><code><?= e($k) ?></code>←<?= chr(65 + (int)$col) ?> <?php endforeach; ?></p>
    <?php if ($prev['warnings']): ?><details class="flash flash--warn"><summary><b><?= e(t('stk.imp_warn_n', ['n' => count($prev['warnings'])])) ?></b></summary><ul style="margin:6px 0 0 18px;"><?php foreach (array_slice($prev['warnings'], 0, 200) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <div class="table-scroll" style="max-height:360px;"><table class="tbl"><thead><tr><th>#</th><th><?= tt('stk.code') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('stk.category') ?></th><th class="num"><?= tt('stk.qty') ?></th><th><?= tt('stk.unit') ?></th><th class="num"><?= tt('stk.last_price') ?></th><th><?= tt('stk.location') ?></th><th><?= tt('stk.maker') ?></th><th></th></tr></thead><tbody>
      <?php foreach (array_slice($prev['rows'], 0, 60) as $r): ?><tr><td class="hint"><?= (int)$r['line'] ?></td><td class="mono"><?= e($r['code']) ?></td><td><?= e($r['description']) ?></td><td class="hint"><?= e($r['category'] ?? '') ?></td><td class="num"><?= $r['qty'] !== null ? e(stk_qty($r['qty'])) : '' ?></td><td class="hint"><?= e($r['unit']) ?><?= $r['pack_qty'] ? ' ×' . e(stk_qty($r['pack_qty'])) : '' ?></td><td class="num"><?= $r['price'] !== null ? e(number_format($r['price'], 2, ',', '.')) : '' ?></td><td class="mono hint"><?= e($r['location'] ?? '') ?></td><td class="hint"><?= e($r['maker'] ?? '') ?></td><td><?= $r['exists'] ? pill('mute', t('stk.imp_exists')) : pill('green', t('stk.imp_new1')) ?></td></tr><?php endforeach; ?>
      <?php if (count($prev['rows']) > 60): ?><tr><td colspan="10" class="hint"><?= e(t('stk.imp_more', ['n' => count($prev['rows']) - 60])) ?></td></tr><?php endif; ?></tbody></table></div>
  </div>
  <div class="card">
    <h2>3 · <?= tt('stk.imp_apply') ?></h2>
    <form method="post" action="<?= url('inventory_import') ?>" data-lock data-confirm="<?= e(t('stk.imp_confirm', ['n' => count($prev['rows'])])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('stk.imp_apply') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="stk_import_apply">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('stk.imp_mode') ?></label><select name="mode" data-nosearch><option value="full"><?= tt('stk.imp_m_full') ?></option><option value="items"><?= tt('stk.imp_m_items') ?></option><option value="stock"><?= tt('stk.imp_m_stock') ?></option><option value="prices"><?= tt('stk.imp_m_prices') ?></option></select></div>
        <div class="field"><label><?= tt('stk.warehouse') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $w['is_default'] ? ' selected' : '' ?>><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.imp_price_date') ?></label><input type="date" name="price_date" value="<?= date('Y-m-d') ?>"></div>
      </div>
      <p class="hint"><?= tt('stk.imp_mode_hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_apply') ?></button><a class="btn btn--ghost" href="<?= url('inventory_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<div><div class="card"><h2>ℹ <?= tt('stk.imp_how') ?></h2><ol class="hint" style="padding-left:18px;"><li><?= tt('stk.imp_how1') ?></li><li><?= tt('stk.imp_how2') ?></li><li><?= tt('stk.imp_how3') ?></li><li><?= tt('stk.imp_how4') ?></li></ol></div></div>
</div>
<?php layout_foot(); ?>

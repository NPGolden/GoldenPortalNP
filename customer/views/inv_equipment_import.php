<?php
/** Procurement › Inventory › Equipment › Import (GAS-CP-R97-290): an equipment list (.csv or .xlsx), one row per unit with its own number. Upload → preview → import; the file is not kept. */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('eqp_import_can') || !eqp_import_can()) { http_response_code(403); layout_head(t('eqp.imp_title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
if (isset($_GET['template'])) { while (ob_get_level()) ob_end_clean(); eqp_import_template(); exit; }
if (isset($_GET['discard'])) unset($_SESSION['eqp_import']);
$prev = $_SESSION['eqp_import'] ?? null; $res = $_SESSION['eqp_import_result'] ?? null; unset($_SESSION['eqp_import_result']); $whs = stk_warehouses(); $qh = eqp_qh();
layout_head(t('eqp.imp_title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('inv_equipment') ?>"><?= tt('eqp.title') ?></a></p>
<div class="page-head"><div><h1>⤒ <?= tt('eqp.imp_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('eqp.imp_lede') ?></p></div></div>
<?php if ($res): ?><div class="flash flash--good"><b><?= tt('stk.imp_done') ?></b> — <?= e(t('eqp.imp_done', ['u' => (int)$res['units'], 'r' => (int)$res['retired'], 'c' => (int)$res['cal'], 's' => (int)$res['skipped']])) ?> <a href="<?= url('inv_equipment') ?>"><?= tt('eqp.title') ?></a></div><?php endif; ?>
<div class="cols">
<div>
  <div class="card">
    <h2>1 · <?= tt('stk.imp_upload') ?></h2>
    <form method="post" action="<?= url('inv_equipment_import') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eqp_import_parse">
      <div class="grid grid-2"><div class="field"><label><?= tt('eqp.imp_file') ?></label><input type="file" name="file" accept=".xlsx,.csv" required></div>
        <div class="field"><label><?= tt('eqp.imp_order') ?></label><select name="order" data-nosearch><option value="auto"><?= tt('eqp.imp_o_auto') ?></option><option value="dmy"><?= tt('eqp.imp_o_dmy') ?></option><option value="mdy"><?= tt('eqp.imp_o_mdy') ?></option></select></div></div>
      <p class="hint"><?= tt('eqp.imp_cols_hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_preview') ?></button><a class="btn btn--ghost" href="<?= url('inv_equipment_import', ['template' => 1]) ?>">⤓ <?= tt('stk.imp_template') ?></a></div>
    </form>
  </div>
  <?php if ($prev): $n = $prev['n']; ?>
  <div class="card">
    <h2>2 · <?= tt('stk.imp_check') ?></h2>
    <div class="kpi kpi--4" style="margin-bottom:10px;"><div class="stat green"><b><?= (int)$n['ok'] ?></b><?= tt('sn.imp_k_units') ?></div><div class="stat"><b><?= (int)$n['cal'] ?></b><?= tt('eqp.k_measuring') ?></div><div class="stat"><b><?= (int)$n['retired'] ?></b><?= tt('eqp.s_retired') ?></div><div class="stat<?= $n['skip'] ? ' red' : '' ?>"><b><?= (int)$n['skip'] ?></b><?= tt('sn.imp_k_skip') ?></div></div>
    <div class="flash <?= $prev['sure'] ? 'flash--info' : 'flash--warn' ?>"><?= tt('eqp.imp_order') ?>: <b><?= tt('eqp.imp_o_' . $prev['order']) ?></b><?= $prev['sure'] ? '' : ' — ' . tt('eqp.imp_order_unsure') ?></div>
    <div class="table-scroll" style="max-height:440px;"><table class="tbl tbl--compact"><thead><tr><th>#</th><th></th><th><?= tt('eqp.c_no') ?></th><th><?= tt('eqp.c_sn') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('eqp.c_area') ?></th><th><?= tt('eqp.c_purchased') ?></th><th><?= tt('ism.f_last_cal') ?></th><th><?= tt('ism.f_valid_until') ?></th></tr></thead><tbody>
      <?php $ordered = array_merge(array_filter($prev['rows'], fn($r) => $r['skip']), array_filter($prev['rows'], fn($r) => !$r['skip'] && $r['warn']), array_filter($prev['rows'], fn($r) => !$r['skip'] && !$r['warn'] && $r['cal']), array_filter($prev['rows'], fn($r) => !$r['skip'] && !$r['warn'] && !$r['cal']));
      foreach (array_slice($ordered, 0, 250) as $r): ?><tr><td class="hint"><?= (int)$r['line'] ?></td>
        <td><?php if ($r['skip']): ?><?= pill('bad', t('sn.imp_p_skip')) ?><div class="hint"><?= e($r['skip']) ?></div><?php else: ?><?= $r['retired'] ? pill('mute', t('eqp.s_retired')) : ($r['cal'] ? pill('navy', '📏 ' . t('eqp.k_measuring')) : pill('green', t('stk.imp_new1'))) ?><?php foreach ($r['warn'] as $w): ?><div class="hint">⚠ <?= e($w) ?></div><?php endforeach; ?><?php endif; ?></td>
        <td class="mono" style="white-space:nowrap;"><?= e($r['code']) ?></td><td class="mono hint"><?= e($r['serial']) ?></td><td><?= e($r['description']) ?><span class="hint"> <?= e(trim($r['maker'] . ' ' . $r['model'])) ?></span></td><td class="hint"><?= e($r['area']) ?></td><td class="hint"><?= $r['purchased_on'] ? e(fmt_date($r['purchased_on'])) : '' ?></td><td class="hint"><?= $r['last_cal'] ? e(fmt_date($r['last_cal'])) : '' ?></td><td class="hint"><?= $r['cal_due'] ? e(fmt_date($r['cal_due'])) : '' ?></td></tr><?php endforeach; ?>
      <?php if (count($ordered) > 250): ?><tr><td colspan="9" class="hint"><?= e(t('stk.imp_more', ['n' => count($ordered) - 250])) ?></td></tr><?php endif; ?></tbody></table></div>
  </div>
  <?php if ($n['ok']): ?>
  <div class="card">
    <h2>3 · <?= tt('stk.imp_apply') ?></h2>
    <form method="post" action="<?= url('inv_equipment_import') ?>" data-lock data-confirm="<?= e(t('eqp.imp_confirm', ['n' => (int)$n['ok']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('stk.imp_apply') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eqp_import_apply">
      <div class="field"><label><?= tt('sn.imp_wh') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $w['is_default'] ? ' selected' : '' ?>><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
      <p class="hint"><?= tt('eqp.imp_apply_hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_apply') ?></button><a class="btn btn--ghost" href="<?= url('inv_equipment_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
  </div>
  <?php else: ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('inv_equipment_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div><?php endif; ?>
  <?php endif; ?>
</div>
<div><div class="card"><h2>ℹ <?= tt('stk.imp_how') ?></h2><ol class="hint" style="padding-left:18px;"><li><?= tt('eqp.imp_n1') ?></li><li><?= tt('eqp.imp_n2') ?></li><li><?= tt('eqp.imp_n3') ?></li><li><?= tt('eqp.imp_n4') ?></li><li><?= tt('eqp.imp_n5') ?></li></ol>
  <?php if (!$qh): ?><p class="hint">⚠ <?= tt('eqp.imp_w_qh') ?></p><?php endif; ?></div></div>
</div>
<?php layout_foot(); ?>

<?php
/** Procurement › Inventory › Import company assets (R97-253) — one row per unit: item, serial number, company number, warehouse and, optionally, the person who holds it. Template → preview → import; the file is not kept. */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('sn_import_can') || !sn_import_can()) { http_response_code(403); exit(t('err.noaccess')); }
if (isset($_GET['template'])) { while (ob_get_level()) ob_end_clean(); sn_import_template(); exit; }
if (isset($_GET['discard'])) unset($_SESSION['sn_import']);
$prev = $_SESSION['sn_import'] ?? null; $res = $_SESSION['sn_import_result'] ?? null; unset($_SESSION['sn_import_result']);
$whs = stk_warehouses(); $hr = function_exists('hre_can') && hre_can(); $admin = function_exists('pr_admin') && pr_admin();
layout_head(t('sn.imp_title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a></p>
<div class="page-head"><div><h1>⤒ <?= tt('sn.imp_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sn.imp_lede') ?></p></div></div>
<?php if ($res): ?><div class="flash flash--good"><b><?= tt('stk.imp_done') ?></b> — <?= e(t('sn.imp_done', ['u' => (int)$res['units'], 'i' => (int)$res['new_items'], 's' => (int)$res['switched'], 'h' => (int)$res['handed'], 'p' => (int)$res['people']])) ?><?php if ($res['handed']): ?> <?= tt('sn.imp_done_hr') ?><?php endif; ?></div><?php endif; ?>
<div class="cols">
<div>
  <div class="card">
    <h2>1 · <?= tt('stk.imp_upload') ?></h2>
    <form method="post" action="<?= url('assets_import') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sn_import_parse">
      <div class="field"><label><?= tt('im.file') ?></label><input type="file" name="file" accept=".xlsx" required></div>
      <p class="hint"><?= tt('sn.imp_cols_hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_preview') ?></button><a class="btn btn--ghost" href="<?= url('assets_import', ['template' => 1]) ?>">⤓ <?= tt('stk.imp_template') ?></a></div>
    </form>
  </div>
  <?php if ($prev): $n = $prev['n']; ?>
  <div class="card">
    <h2>2 · <?= tt('stk.imp_check') ?></h2>
    <div class="kpi kpi--4" style="margin-bottom:10px;"><div class="stat green"><b><?= (int)$n['ok'] ?></b><?= tt('sn.imp_k_units') ?></div><div class="stat"><b><?= (int)$n['new_items'] ?></b><?= tt('sn.imp_k_new') ?></div><div class="stat"><b><?= (int)$n['holders'] ?></b><?= tt('sn.imp_k_holders') ?></div><div class="stat<?= $n['skip'] ? ' red' : '' ?>"><b><?= (int)$n['skip'] ?></b><?= tt('sn.imp_k_skip') ?></div></div>
    <?php if ($n['switch']): ?><div class="flash flash--warn"><?= e(t('sn.imp_switch_n', ['n' => (int)$n['switch']])) ?></div><?php endif; ?>
    <div class="table-scroll" style="max-height:420px;"><table class="tbl tbl--compact"><thead><tr><th>#</th><th></th><th><?= tt('stk.code') ?></th><th><?= tt('sn.title') ?></th><th><?= tt('sn.imp_c_tag') ?></th><th><?= tt('stk.warehouse') ?></th><th><?= tt('sn.imp_c_holder') ?></th><th><?= tt('stk.desc') ?></th></tr></thead><tbody>
      <?php $whName = []; foreach ($whs as $w) $whName[(int)$w['id']] = (string)$w['code'];
      $ordered = array_merge(array_filter($prev['rows'], fn($r) => $r['skip']), array_filter($prev['rows'], fn($r) => !$r['skip'] && $r['warn']), array_filter($prev['rows'], fn($r) => !$r['skip'] && !$r['warn']));
      foreach (array_slice($ordered, 0, 200) as $r): ?><tr><td class="hint"><?= (int)$r['line'] ?></td>
        <td><?php if ($r['skip']): ?><?= pill('bad', t('sn.imp_p_skip')) ?><div class="hint"><?= e($r['skip']) ?></div><?php else: ?><?= $r['state'] === 'new' ? pill('green', t('stk.imp_new1')) : ($r['state'] === 'switch' ? pill('gold', t('sn.imp_p_switch')) : pill('mute', t('stk.imp_exists'))) ?><?php foreach ($r['warn'] as $w): ?><div class="hint">⚠ <?= e($w) ?></div><?php endforeach; ?><?php endif; ?></td>
        <td class="mono" style="white-space:nowrap;"><?= e($r['code']) ?></td><td class="mono" style="white-space:nowrap;"><?= e($r['serial']) ?></td><td class="mono hint"><?= e($r['tag']) ?></td><td class="hint"><?= e($r['wh'] ? ($whName[(int)$r['wh']] ?? '') : '') ?></td><td><?= e($r['holder']) ?></td><td class="hint"><?= e($r['description']) ?></td></tr><?php endforeach; ?>
      <?php if (count($ordered) > 200): ?><tr><td colspan="8" class="hint"><?= e(t('stk.imp_more', ['n' => count($ordered) - 200])) ?></td></tr><?php endif; ?></tbody></table></div>
  </div>
  <?php if ($n['ok']): ?>
  <div class="card">
    <h2>3 · <?= tt('stk.imp_apply') ?></h2>
    <form method="post" action="<?= url('assets_import') ?>" data-lock data-confirm="<?= e(t('sn.imp_confirm', ['n' => (int)$n['ok']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('stk.imp_apply') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sn_import_apply">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('sn.imp_wh') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $w['is_default'] ? ' selected' : '' ?>><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('sn.imp_mode') ?></label><select name="mode" data-nosearch><option value="add"><?= tt('sn.imp_m_add') ?></option><option value="number"><?= tt('sn.imp_m_number') ?></option></select></div>
      </div>
      <p class="hint"><?= tt('sn.imp_mode_hint') ?></p>
      <?php if ($n['switch'] && !empty($u['totp_enabled'])): ?><div class="field"><label><?= tt('po.pg_totp_label') ?></label><input name="totp" required inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" style="max-width:140px;"><p class="hint"><?= tt('sn.imp_totp_why') ?></p></div><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_apply') ?></button><a class="btn btn--ghost" href="<?= url('assets_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
  </div>
  <?php else: ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('assets_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div><?php endif; ?>
  <?php endif; ?>
</div>
<div><div class="card"><h2>ℹ <?= tt('stk.imp_how') ?></h2><ol class="hint" style="padding-left:18px;"><li><?= tt('sn.imp_how1') ?></li><li><?= tt('sn.imp_how2') ?></li><li><?= tt('sn.imp_how3') ?></li><li><?= tt('sn.imp_how4') ?></li></ol>
  <?php if (!$admin): ?><p class="hint">⚠ <?= tt('sn.imp_no_admin') ?></p><?php endif; ?><?php if (!$hr): ?><p class="hint">⚠ <?= tt('sn.imp_w_hr') ?></p><?php endif; ?></div></div>
</div>
<?php $ilog = function_exists('sn_import_log') ? sn_import_log(30) : []; ?>
<div class="card" id="register">
  <h2>🗒 <?= tt('sn.imp_reg') ?></h2>
  <?php if (!$ilog): ?><p class="hint"><?= tt('sn.imp_reg_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--compact" data-nosort><thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('ll.who') ?></th><th class="num"><?= tt('sn.imp_k_units') ?></th><th class="num"><?= tt('sn.imp_k_new') ?></th><th class="num"><?= tt('sn.imp_r_switched') ?></th><th class="num"><?= tt('sn.imp_k_holders') ?></th><th class="num"><?= tt('sn.imp_k_skip') ?></th><th><?= tt('stk.warehouse') ?></th><th><?= tt('sn.imp_mode') ?></th></tr></thead><tbody>
  <?php foreach ($ilog as $r): ?><tr><td><?= e(fmt_datetime((string)$r['created_at'])) ?></td><td><?= e((string)$r['user_name']) ?></td><td class="num"><b><?= (int)$r['units'] ?></b></td><td class="num"><?= (int)$r['new_items'] ?></td><td class="num"><?= (int)$r['switched'] ? pill('gold', (string)(int)$r['switched']) : '0' ?></td><td class="num"><?= (int)$r['handed'] ?></td><td class="num"><?= (int)$r['skipped'] ?></td><td class="hint"><?= e((string)($r['wh_code'] ?? '')) ?></td><td class="hint"><?= tt($r['mode'] === 'number' ? 'sn.imp_r_number' : 'sn.imp_r_add') ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php layout_foot(); ?>

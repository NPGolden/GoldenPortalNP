<?php
/** Settings › Procurement — pricing rule and overrides, enabled disciplines, payment threshold, warehouses (GAS-CP-R79-001). Procurement flag or management. */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('pr_admin') || !pr_admin()) { http_response_code(403); layout_head(t('stk.settings'), 'admin_procurement'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$R = stk_rule_setting(); $ov = stk_rules_override(); $cats = stk_categories(); $sups = sup_list(['status' => 'active']); $whs = stk_warehouses(false); $disc = function_exists('disciplines') ? disciplines() : []; $on = stk_enabled_disciplines();
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $ew = (int)($_GET['wh'] ?? 0); $ewr = $ew ? one('SELECT * FROM inv_warehouses WHERE id = ?', [$ew]) : null;
layout_head(t('stk.settings'), 'admin_procurement');
?>
<?php if (is_cp_admin()) settings_tabs('admin_procurement'); ?>
<div class="page-head"><div><h2 class="settings-h">📦 <?= tt('stk.settings') ?></h2><p class="lede" style="margin-bottom:0;"><?= tt('stk.settings_lede') ?></p></div></div>
<div class="cols">
<div>
  <div class="card">
    <h2>💶 <?= tt('stk.rule') ?></h2>
    <p class="hint"><?= tt('stk.rule_hint') ?></p>
    <form method="post" action="<?= url('admin_procurement') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="proc_settings">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('stk.rule_company') ?></label><select name="rule" data-nosearch><?php foreach (STK_RULES as $r): ?><option value="<?= $r ?>"<?= $sel($r, $R['rule']) ?>><?= e(t('stk.rule_' . $r)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.rule_n') ?></label><input name="n" type="number" min="2" max="10" value="<?= (int)$R['n'] ?>"></div>
        <div class="field"><label><?= tt('stk.rule_pct') ?></label><input name="pct" type="number" step="0.01" value="<?= e((string)$R['pct']) ?>"></div>
      </div>
      <h3 style="margin-top:12px;"><?= tt('stk.disciplines') ?></h3><p class="hint"><?= tt('stk.disciplines_hint') ?></p>
      <div class="pr-disc"><?php foreach ($disc as $d): ?><label><input type="checkbox" name="disciplines[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $on, true) ? ' checked' : '' ?>> <?= e($d['name']) ?></label><?php endforeach; ?></div>
      <div class="field" style="margin-top:12px;max-width:260px;"><label><?= tt('stk.offer_margin') ?> %</label><input name="offer_margin" type="number" step="0.01" min="-100" max="1000" value="<?= e(cp_setting('proc_offer_margin', '25') ?? '25') ?>"><p class="hint"><?= tt('stk.offer_margin_hint') ?></p></div>
      <div class="field" style="margin-top:12px;max-width:260px;"><label><?= tt('stk.pay_threshold') ?> (EUR)</label><input name="pay_threshold" type="number" step="0.01" min="0" value="<?= e(cp_setting('proc_pay_threshold', '5000') ?? '5000') ?>"><p class="hint"><?= tt('stk.pay_threshold_hint') ?></p></div>
      <button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button>
    </form>
  </div>
  <div class="card">
    <h2>🎯 <?= tt('stk.rule_overrides') ?> <span class="count"><?= count($ov) ?></span></h2>
    <p class="hint"><?= tt('stk.rule_overrides_hint') ?></p>
    <?php if ($ov): ?><table class="tbl"><thead><tr><th><?= tt('stk.scope') ?></th><th><?= tt('stk.ref') ?></th><th><?= tt('stk.rule') ?></th><th class="num">n</th><th class="num">%</th><th></th></tr></thead><tbody>
      <?php foreach ($ov as $o): ?><tr><td><?= pill($o['scope'] === 'category' ? 'navy' : 'gold', t('stk.scope_' . $o['scope'])) ?></td><td><?= e($o['ref_name'] ?? ('#' . $o['ref_id'])) ?></td><td><?= e(t('stk.rule_' . $o['rule'])) ?></td><td class="num"><?= (int)$o['n'] ?></td><td class="num"><?= e((string)$o['pct']) ?></td><td><form method="post" action="<?= url('admin_procurement') ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="stk_rule_delete"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <form method="post" action="<?= url('admin_procurement') ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="inv_rule_save">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('stk.scope') ?></label><select name="scope" data-nosearch id="ov-scope"><option value="category"><?= tt('stk.scope_category') ?></option><option value="supplier"><?= tt('stk.scope_supplier') ?></option></select></div>
        <div class="field ov-cat"><label><?= tt('stk.category') ?></label><select name="ref_id_cat"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field ov-sup" style="display:none;"><label><?= tt('stk.supplier') ?></label><select name="ref_id_sup"><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.rule') ?></label><select name="rule" data-nosearch><?php foreach (STK_RULES as $r): ?><option value="<?= $r ?>"><?= e(t('stk.rule_' . $r)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.rule_n') ?></label><input name="n" type="number" min="2" max="10" value="3"></div><div class="field"><label><?= tt('stk.rule_pct') ?></label><input name="pct" type="number" step="0.01" value="0"></div>
      </div>
      <button class="btn btn--ghost btn--sm" type="submit">+ <?= tt('stk.rule_override_add') ?></button>
    </form>
  </div>
</div>
<div>
  <div class="card" id="wh">
    <h2>🏬 <?= tt('stk.warehouses') ?></h2>
    <table class="tbl"><thead><tr><th><?= tt('stk.code') ?></th><th><?= tt('stk.warehouse') ?></th><th><?= tt('br.one') ?></th><th></th></tr></thead><tbody>
      <?php foreach ($whs as $w): ?><tr class="<?= $w['is_active'] ? '' : 'is-retired' ?>"><td class="mono"><b><?= e($w['code']) ?></b><?= $w['is_default'] ? ' ' . pill('gold', t('stk.default')) : '' ?></td><td><?= e($w['name']) ?></td><td class="hint"><?= e($w['branch_code'] ?? '') ?></td><td><a class="btn btn--ghost btn--sm" href="<?= url('admin_procurement', ['wh' => (int)$w['id']]) ?>#wh"><?= tt('btn.edit') ?></a></td></tr><?php endforeach; ?></tbody></table>
    <form method="post" action="<?= url('admin_procurement') ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="inv_wh_save"><input type="hidden" name="id" value="<?= (int)($ewr['id'] ?? 0) ?>">
      <h3><?= $ewr ? tt('btn.edit') . ' — ' . e($ewr['code']) : tt('stk.wh_new') ?></h3>
      <div class="grid grid-2"><div class="field"><label><?= tt('stk.code') ?> *</label><input name="code" required maxlength="12" value="<?= e($ewr['code'] ?? '') ?>"></div><div class="field"><label><?= tt('stk.warehouse') ?> *</label><input name="name" required maxlength="120" value="<?= e($ewr['name'] ?? '') ?>"></div>
        <div class="field"><label><?= tt('br.one') ?></label><select name="branch_id"><option value="">—</option><?php foreach (br_all() as $b): ?><option value="<?= (int)$b['id'] ?>"<?= $sel($b['id'], $ewr['branch_id'] ?? 0) ?>><?= e($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="align-self:end;"><label class="chk"><input type="checkbox" name="is_default" value="1"<?= !empty($ewr['is_default']) ? ' checked' : '' ?>> <?= tt('stk.default') ?></label><?php if ($ewr): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $ewr['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?></div></div>
      <div class="btn-row"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($ewr): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_procurement') ?>#wh"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
  </div>
  <div class="card"><h2>ℹ <?= tt('stk.rules_explained') ?></h2><table class="tbl"><tbody><?php foreach (STK_RULES as $r): ?><tr><td><b><?= e(t('stk.rule_' . $r)) ?></b></td><td class="hint"><?= e(t('stk.rule_x_' . $r)) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('ov-scope');if(!s)return;var f=function(){document.querySelector('.ov-cat').style.display=s.value==='category'?'':'none';document.querySelector('.ov-sup').style.display=s.value==='supplier'?'':'none';};s.addEventListener('change',f);f();})();</script>
<?php layout_foot(); ?>

<?php
/** Invoicing details card — several billing identities per customer / company / contact.   GAS-CP-R19-001
 *  Included by iv_section(); expects $ivType, $ivOwner, $ivBack (return URL), $ivEdit (row|null). */
$ivRows  = iv_list($ivType, $ivOwner);
$ivAdmin = is_cp_admin();
$ivOpen  = $ivAdmin && ($ivEdit || isset($_GET['iv']) || !$ivRows);
$ivV = fn(string $k, $d = '') => e((string)($ivEdit[$k] ?? $d));
?>
<div class="card iv-card" id="invoicing">
  <div class="page-head" style="margin-bottom:8px;">
    <h2 style="margin:0;"><?= tt('iv.title') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($ivRows) ?></span></h2>
    <?php if ($ivAdmin && !$ivOpen): ?><a class="btn btn--ghost btn--sm" href="<?= e($ivBack . (str_contains($ivBack, '?') ? '&' : '?') . 'iv=0') ?>#invoicing">+ <?= tt('iv.add') ?></a><?php endif; ?>
  </div>
  <p class="hint" style="margin:0 0 12px;"><?= tt('iv.lede') ?></p>
  <?php if (!$ivRows): ?><div class="empty"><?= tt('iv.none') ?></div><?php else: ?>
  <div class="iv-list">
    <?php foreach ($ivRows as $r): ?>
    <div class="iv-item<?= $r['is_default'] ? ' is-default' : '' ?><?= $r['is_active'] ? '' : ' is-retired' ?>">
      <div class="iv-head">
        <b><?= e($r['label']) ?></b>
        <?= $r['is_default'] ? pill('gold', t('iv.default')) : '' ?><?= $r['is_active'] ? '' : pill('mute', t('ct.inactive')) ?>
        <span class="hint"><?= e($r['currency']) ?><?= $r['payment_terms'] ? ' · ' . e($r['payment_terms']) : '' ?></span>
        <?php if ($ivAdmin): ?><span class="iv-act">
          <?php if (!$r['is_default'] && $r['is_active']): ?><form method="post" action="<?= e($ivBack) ?>#invoicing" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="iv_default"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="linkbtn" type="submit"><?= tt('iv.make_default') ?></button></form><?php endif; ?>
          <a class="btn btn--ghost btn--sm" href="<?= e($ivBack . (str_contains($ivBack, '?') ? '&' : '?') . 'iv=' . (int)$r['id']) ?>#invoicing"><?= tt('btn.edit') ?></a>
          <form method="post" action="<?= e($ivBack) ?>#invoicing" style="display:inline;" data-confirm="<?= e(t('iv.delete_confirm', ['name' => $r['label']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="iv_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form>
        </span><?php endif; ?>
      </div>
      <div class="iv-body">
        <address><?= implode('<br>', array_map('e', iv_address_lines($r))) ?></address>
        <div class="iv-facts">
          <?php if ($r['email']): ?><div><span><?= tt('iv.email') ?></span><b><a href="mailto:<?= e($r['email']) ?>"><?= e($r['email']) ?></a></b></div><?php endif; ?>
          <?php if ($r['phone']): ?><div><span><?= tt('ct.phone') ?></span><b><?= e($r['phone']) ?></b></div><?php endif; ?>
          <?php if ($r['bank'] || $r['iban']): ?><div><span><?= tt('iv.bank') ?></span><b><?= e(implode(' · ', array_filter([$r['bank'], $r['iban'], $r['swift']]))) ?></b></div><?php endif; ?>
          <?php if ($r['notes']): ?><div><span><?= tt('ct.notes') ?></span><b><?= rt_html($r['notes']) ?></b></div><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($ivOpen): ?>
  <form method="post" action="<?= e($ivBack) ?>#invoicing" class="iv-form" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="iv_save"><input type="hidden" name="id" value="<?= (int)($ivEdit['id'] ?? 0) ?>">
    <input type="hidden" name="owner_type" value="<?= e($ivType) ?>"><input type="hidden" name="owner_id" value="<?= (int)$ivOwner ?>">
    <h3 class="ed-h"><?= $ivEdit ? tt('iv.edit') . ' — ' . e($ivEdit['label']) : tt('iv.add') ?></h3>
    <div class="grid grid-2">
      <div class="field"><label for="iv_label"><?= tt('iv.label') ?></label><input id="iv_label" name="label" maxlength="80" value="<?= $ivV('label') ?>" placeholder="<?= tt('iv.label_ph') ?>"></div>
      <div class="field"><label for="iv_legal"><?= tt('iv.legal_name') ?> *</label><input id="iv_legal" name="legal_name" required maxlength="200" value="<?= $ivV('legal_name') ?>"></div>
      <div class="field"><label for="iv_vat"><?= tt('iv.vat_no') ?></label><input id="iv_vat" name="vat_no" maxlength="40" value="<?= $ivV('vat_no') ?>"></div>
      <div class="field"><label for="iv_tax"><?= tt('iv.tax_office') ?></label><input id="iv_tax" name="tax_office" maxlength="120" value="<?= $ivV('tax_office') ?>"></div>
      <div class="field"><label for="iv_reg"><?= tt('iv.reg_no') ?></label><input id="iv_reg" name="reg_no" maxlength="60" value="<?= $ivV('reg_no') ?>"></div>
      <div class="field"><label for="iv_attn"><?= tt('iv.attn_f') ?></label><input id="iv_attn" name="attn" maxlength="120" value="<?= $ivV('attn') ?>"></div>
    </div>
    <div class="field"><label for="iv_addr"><?= tt('iv.address') ?></label><input id="iv_addr" name="address" maxlength="200" value="<?= $ivV('address') ?>"></div>
    <div class="grid grid-3">
      <div class="field"><label for="iv_pc"><?= tt('iv.postal_code') ?></label><input id="iv_pc" name="postal_code" maxlength="20" value="<?= $ivV('postal_code') ?>"></div>
      <div class="field"><label for="iv_city"><?= tt('co.city') ?></label><input id="iv_city" name="city" maxlength="80" value="<?= $ivV('city') ?>"></div>
      <div class="field"><label for="iv_country"><?= tt('co.country') ?></label><input id="iv_country" name="country" maxlength="80" value="<?= $ivV('country') ?>"></div>
    </div>
    <div class="grid grid-2">
      <div class="field"><label for="iv_email"><?= tt('iv.email') ?></label><input id="iv_email" name="email" type="email" maxlength="190" value="<?= $ivV('email') ?>"></div>
      <div class="field"><label for="iv_phone"><?= tt('ct.phone') ?></label><input id="iv_phone" name="phone" maxlength="60" value="<?= $ivV('phone') ?>"></div>
      <div class="field"><label for="iv_bank"><?= tt('iv.bank') ?></label><input id="iv_bank" name="bank" maxlength="120" value="<?= $ivV('bank') ?>"></div>
      <div class="field"><label for="iv_iban"><?= tt('iv.iban') ?></label><input id="iv_iban" name="iban" maxlength="48" value="<?= $ivV('iban') ?>" style="font-family:var(--mono);"></div>
      <div class="field"><label for="iv_swift"><?= tt('iv.swift') ?></label><input id="iv_swift" name="swift" maxlength="16" value="<?= $ivV('swift') ?>" style="font-family:var(--mono);"></div>
      <div class="field"><label for="iv_cur"><?= tt('iv.currency') ?></label><input id="iv_cur" name="currency" maxlength="3" value="<?= $ivV('currency', 'EUR') ?>" style="text-transform:uppercase;"></div>
      <?php if (function_exists('iv_vessel_has') && iv_vessel_has()): ?><div class="field"><label for="iv_vat"><?= tt('iv.vat_pct') ?></label><input id="iv_vat" name="vat_pct" type="number" step="0.01" min="0" max="100" value="<?= $ivEdit && $ivEdit['vat_pct'] !== null ? e((string)$ivEdit['vat_pct']) : '' ?>" placeholder="24"><p class="hint"><?= tt('iv.vat_hint') ?></p></div><?php endif; ?>
      <div class="field"><label for="iv_terms"><?= tt('iv.payment_terms') ?></label><input id="iv_terms" name="payment_terms" maxlength="120" value="<?= $ivV('payment_terms') ?>" placeholder="<?= tt('iv.terms_ph') ?>"></div>
    </div>
    <div class="field"><label for="iv_notes"><?= tt('ct.notes') ?></label><textarea id="iv_notes" name="notes" rows="2"><?= $ivV('notes') ?></textarea></div>
    <div class="btn-row" style="align-items:center;gap:16px;">
      <label class="chk"><input type="checkbox" name="is_default" value="1"<?= ($ivEdit['is_default'] ?? 0) || !$ivRows ? ' checked' : '' ?>> <?= tt('iv.default') ?></label>
      <?php if ($ivEdit): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $ivEdit['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?>
      <span style="flex:1"></span>
      <button class="btn btn--gold" type="submit"><?= $ivEdit ? tt('btn.save') : tt('iv.create') ?></button>
      <a class="btn btn--ghost" href="<?= e($ivBack) ?>#invoicing"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
  <?php endif; ?>
</div>

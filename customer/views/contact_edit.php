<?php
/** New / edit contact.                                          GAS-CP-R17-001
 *   ?p=contact_edit[&customer=ID][&vessel=ID]   new      ?p=contact_edit&id=ID   edit */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!ct_has()) redirect('contacts');
$id = (int)($_GET['id'] ?? 0);
$c = $id ? ct_load($id) : null;
if ($id && !$c) { http_response_code(404); exit(t('err.notfound')); }
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$co = co_has(); $companies = $co ? co_all() : [];
$curCo = $c ? (int)($c['company_id'] ?? 0) : (int)($_GET['company'] ?? 0);
$curCh = $c ? array_filter(explode(',', (string)($c['channels'] ?? ''))) : ['email'];
$curSub = $c ? array_filter(explode(',', (string)($c['subscriptions'] ?? ''))) : [];
$vessels = all('SELECT v.id, v.name, v.customer_id, cu.name AS customer FROM vessels v LEFT JOIN customers cu ON cu.id = v.customer_id ORDER BY cu.name, v.name');
$people = all("SELECT id, full_name, email FROM customer_users WHERE role = 'customer' AND is_active = 1 ORDER BY full_name");
$links = []; foreach ($c['vessels'] ?? [] as $v) $links[(int)$v['vessel_id']] = $v;
if (!$c && ($pv = (int)($_GET['vessel'] ?? 0))) $links[$pv] = ['role' => '', 'is_primary' => 1];
$curCustomer = $c ? (int)$c['customer_id'] : (int)($_GET['customer'] ?? 0);
if (!$c && $curCustomer === 0 && $links) foreach ($vessels as $v) if (isset($links[(int)$v['id']])) { $curCustomer = (int)$v['customer_id']; break; }
$v = fn(string $k, $d = '') => e((string)($c[$k] ?? $d));
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$title = $c ? t('ct.edit') . ' — ' . $c['full_name'] : t('ct.new');
layout_head($title, 'contacts');
?>
<p class="eyebrow"><a href="<?= url('contacts') ?>"><?= tt('ct.title') ?></a></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= tt('ct.lede_form') ?></p>
<form method="post" action="<?= url('contact_edit', $id ? ['id' => $id] : []) ?>" data-lock>
  <?= csrf_field() ?><input type="hidden" name="action" value="ct_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="cols ct-cols">
  <div>
    <div class="card">
      <h2><?= tt('ct.details') ?></h2>
      <div class="grid grid-2">
        <div class="field"><label for="full_name"><?= tt('au.name') ?> *</label><input id="full_name" name="full_name" required maxlength="120" value="<?= $v('full_name') ?>"></div>
        <div class="field"><label for="position"><?= tt('ct.position') ?></label><input id="position" name="position" maxlength="120" value="<?= $v('position') ?>" placeholder="<?= tt('ct.position_ph') ?>"></div>
        <div class="field"><label for="customer_id"><?= tt('rp.customer') ?></label><select id="customer_id" name="customer_id"><option value=""><?= tt('ct.no_customer') ?></option><?php foreach ($customers as $cu): ?><option value="<?= $cu['id'] ?>"<?= $sel($cu['id'], $curCustomer) ?>><?= e($cu['name']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('ct.customer_hint') ?></p></div>
        <?php if ($co): ?><div class="field"><label for="company_id"><?= tt('co.one') ?></label><select id="company_id" name="company_id"><option value=""><?= tt('co.none_sel') ?></option><?php foreach ($companies as $cc): ?><option value="<?= $cc['id'] ?>" data-cid="<?= (int)$cc['customer_id'] ?>"<?= $sel($cc['id'], $curCo) ?>><?= e($cc['name'] . ' · ' . (co_kinds()[$cc['kind']] ?? $cc['kind'])) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('co.pick_hint') ?><?php if (is_cp_admin()): ?> <a href="<?= url('companies') ?>#form"><?= tt('co.new') ?> →</a><?php endif; ?></p></div>
        <?php else: ?><div class="field"><label for="company"><?= tt('ct.company') ?></label><input id="company" name="company" maxlength="160" value="<?= $v('company') ?>" placeholder="<?= tt('ct.company_ph') ?>"></div><?php endif; ?>
        <div class="field"><label for="category"><?= tt('ct.category') ?></label><select id="category" name="category"><?php foreach (ct_categories() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $c['category'] ?? 'other') ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="user_id"><?= tt('ct.portal_account') ?></label><select id="user_id" name="user_id"><option value="">—</option><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"<?= $sel($p['id'], $c['user_id'] ?? 0) ?>><?= e($p['full_name'] . ' · ' . $p['email']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('ct.account_hint') ?></p></div>
      </div>
      <div class="grid grid-3">
        <div class="field"><label for="email"><?= tt('au.email') ?></label><input id="email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
        <div class="field"><label for="mobile"><?= tt('ct.mobile') ?></label><input id="mobile" name="mobile" maxlength="60" value="<?= $v('mobile') ?>"></div>
        <div class="field"><label for="phone"><?= tt('ct.phone') ?></label><input id="phone" name="phone" maxlength="60" value="<?= $v('phone') ?>"></div>
      </div>
      <div class="grid grid-3">
        <div class="field"><label for="birthday"><?= tt('ct.birthday') ?></label><input id="birthday" name="birthday" type="date" value="<?= $c && ct_year_known($c['birthday']) ? $v('birthday') : '' ?>"><p class="hint"><?= tt('ct.birthday_hint') ?></p></div>
        <div class="field"><label for="birthday_md"><?= tt('ct.birthday_md') ?></label><input id="birthday_md" name="birthday_md" maxlength="12" value="<?= $c && $c['birthday'] && !ct_year_known($c['birthday']) ? e(substr($c['birthday'], 8, 2) . '.' . substr($c['birthday'], 5, 2)) : '' ?>" placeholder="15.03"></div>
        <div class="field"><label for="nameday"><?= tt('ct.nameday') ?></label><input id="nameday" name="nameday" maxlength="12" value="<?= $c && $c['nameday_md'] ? e(substr($c['nameday_md'], 3, 2) . '.' . substr($c['nameday_md'], 0, 2)) : '' ?>" placeholder="06.12"><p class="hint"><?= tt('ct.nameday_hint') ?></p></div>
      </div>
      <?php if ($co): ?>
      <div class="grid grid-2">
        <div class="field"><label><?= tt('ct.channels') ?></label><p class="hint" style="margin:0 0 6px;"><?= tt('ct.channels_hint') ?></p><div class="chips chips--wrap"><?php foreach (ct_channels() as $k => $lab): ?><label><input type="checkbox" name="channels[]" value="<?= $k ?>"<?= in_array($k, $curCh, true) ? ' checked' : '' ?>><span><?= e($lab) ?></span></label><?php endforeach; ?></div></div>
        <div class="field"><label><?= tt('ct.subscriptions') ?></label><p class="hint" style="margin:0 0 6px;"><?= tt('ct.subscriptions_hint') ?></p><div class="chips chips--wrap"><?php foreach (ct_subscriptions() as $k => $lab): ?><label><input type="checkbox" name="subscriptions[]" value="<?= $k ?>"<?= in_array($k, $curSub, true) ? ' checked' : '' ?>><span><?= e($lab) ?></span></label><?php endforeach; ?></div></div>
      </div>
      <?php endif; ?>
      <div class="field"><label for="interests"><?= tt('ct.interests') ?></label><textarea id="interests" name="interests" rows="3" placeholder="<?= tt('ct.interests_ph') ?>"><?= $v('interests') ?></textarea></div>
      <?php if ($c): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $c['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php else: ?><input type="hidden" name="is_active" value="1"><?php endif; ?>
    </div>
    <?php if (!$c): ?>
    <div class="card"><h2><?= tt('ct.first_note') ?></h2><div class="field"><textarea name="note" rows="3" placeholder="<?= tt('ct.note_ph') ?>"></textarea></div></div>
    <?php endif; ?>
  </div>
  <div>
    <div class="card">
      <h2><?= tt('nav.vessels') ?></h2>
      <p class="hint"><?= tt('ct.vessels_hint') ?></p>
      <input type="hidden" name="vessel[]" value="">
      <div class="field" style="margin-bottom:8px;"><input id="vfilter" placeholder="<?= tt('ct.vessel_filter') ?>" autocomplete="off"></div>
      <div class="ct-vlist" id="vlist">
        <?php $grp = null; foreach ($vessels as $vv): $vid = (int)$vv['id']; $on = isset($links[$vid]); ?>
          <?php if ($vv['customer'] !== $grp): $grp = $vv['customer']; ?><div class="ct-vgrp" data-cid="<?= (int)$vv['customer_id'] ?>"><?= e($grp ?: t('ct.no_customer')) ?></div><?php endif; ?>
          <div class="ct-vrow<?= $on ? ' is-on' : '' ?>" data-cid="<?= (int)$vv['customer_id'] ?>" data-name="<?= e(mb_strtolower($vv['name'] . ' ' . ($vv['customer'] ?? ''))) ?>">
            <label class="chk"><input type="checkbox" name="vessel[]" value="<?= $vid ?>"<?= $on ? ' checked' : '' ?>> <b><?= e($vv['name']) ?></b></label>
            <input name="vrole[<?= $vid ?>]" maxlength="80" value="<?= e($links[$vid]['role'] ?? '') ?>" placeholder="<?= tt('ct.role_ph') ?>">
            <label class="chk ct-prim" title="<?= tt('ct.primary') ?>"><input type="radio" name="primary_vessel" value="<?= $vid ?>"<?= !empty($links[$vid]['is_primary']) ? ' checked' : '' ?>> ★</label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $c ? tt('btn.save') : tt('ct.create') ?></button><a class="btn btn--ghost" href="<?= $id ? url('contact', ['id' => $id]) : url('contacts') ?>"><?= tt('btn.cancel') ?></a></div>
  </div>
  </div>
</form>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var f = document.getElementById('vfilter'), rows = [].slice.call(document.querySelectorAll('#vlist .ct-vrow')), grps = [].slice.call(document.querySelectorAll('#vlist .ct-vgrp')), cs = document.getElementById('customer_id');
  function apply(){ var q = f.value.trim().toLowerCase(), cid = cs.value; var seen = {};
    rows.forEach(function(r){ var ok = (!q || r.dataset.name.indexOf(q) >= 0) && (!cid || q || r.dataset.cid === cid || r.classList.contains('is-on')); r.style.display = ok ? '' : 'none'; if (ok) seen[r.dataset.cid] = 1; });
    grps.forEach(function(g){ g.style.display = seen[g.dataset.cid] ? '' : 'none'; }); }
  rows.forEach(function(r){ r.querySelector('input[type=checkbox]').addEventListener('change', function(){ r.classList.toggle('is-on', this.checked); }); });
  f.addEventListener('input', apply); cs.addEventListener('change', apply); apply();
  var co = document.getElementById('company_id'); if (co) co.addEventListener('change', function(){ var cid = co.selectedOptions[0] ? co.selectedOptions[0].dataset.cid : ''; if (cid && cid !== '0') { cs.value = cid; apply(); } });
})();
</script>
<?php layout_foot(); ?>

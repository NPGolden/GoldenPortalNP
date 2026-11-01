<?php
require __DIR__ . '/_layout.php';
$u = require_login();
layout_head(t('a.title'), 'account');
?>
<?php settings_tabs('account'); ?>
<h2 class="settings-h"><?= tt('a.title') ?></h2>
<p class="lede"><?= tt('a.lede') ?></p>

<div class="card" style="max-width:560px;">
  <h2><?= tt('av.title') ?></h2>
  <div class="ava-row">
    <?= avatar_html((int)$u['id'], (string)$u['full_name'], 96) ?>
    <form method="post" action="<?= url('account') ?>" enctype="multipart/form-data" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="avatar_save">
      <div class="field"><label for="avatar"><?= tt('av.pick') ?></label><input id="avatar" name="avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required></div>
      <p class="hint" style="margin:0 0 10px;"><?= tt('av.hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('av.upload') ?></button>
        <?php if (avatar_has((int)$u['id'])): ?><button class="btn btn--ghost btn--sm" type="submit" name="remove" value="1" formnovalidate data-confirm="<?= tt('av.confirm_remove') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('av.remove') ?></button><?php endif; ?></div>
    </form>
  </div>
</div>

<div class="card" style="max-width:560px;">
  <form method="post" action="<?= url('account') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="account_save">
    <div class="field"><label><?= tt('a.company') ?></label><input type="text" value="<?= e($u['customer_name']) ?>" disabled></div>
    <div class="field"><label><?= tt('a.name') ?></label><input type="text" value="<?= e($u['full_name']) ?>" disabled></div>
    <div class="field"><label><?= tt('a.email') ?></label><input type="email" value="<?= e($u['email']) ?>" disabled></div>
    <div class="field">
      <label for="locale"><?= tt('a.lang') ?></label>
      <select id="locale" name="locale">
        <?php foreach (LOCALES as $code => $meta): ?>
          <option value="<?= e($code) ?>"<?= $code === locale() ? ' selected' : '' ?>><?= e($meta['native']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="theme"><?= tt('ui.theme') ?></label>
      <select id="theme" name="theme">
        <option value="light"<?= theme_current() === 'light' ? ' selected' : '' ?>><?= tt('ui.light') ?></option>
        <option value="dark"<?= theme_current() === 'dark' ? ' selected' : '' ?>><?= tt('ui.dark') ?></option>
      </select>
      <p class="hint"><?= tt('ui.theme_hint') ?></p>
    </div>
    <div class="field">
      <label for="password"><?= tt('a.newpass') ?></label>
      <input id="password" name="password" type="password" minlength="10" autocomplete="new-password">
      <p class="hint"><?= tt('a.newpass_hint') ?></p>
    </div>
    <button class="btn btn--gold" type="submit"><?= tt('a.save') ?></button>
  </form>
</div>

<?php
/* ---- two-factor authentication ------------------------------- R6 */
$has2fa   = array_key_exists('totp_enabled', $u);            // migration applied?
$enabled  = !empty($u['totp_enabled']);
$required = !empty($u['twofa_required']);
$setup    = $has2fa && !empty($_GET['setup']) && !empty($_SESSION['acct_enrol_secret']);
if ($has2fa): ?>
<div class="card" style="max-width:560px;">
  <h2><?= tt('a.twofa_title') ?></h2>
  <p style="margin:6px 0 14px;"><?= $enabled ? pill('green', t('a.twofa_on')) : pill('gold', t('a.twofa_off')) ?>
     <?php if ($required): ?> <span class="hint" style="display:inline;margin-left:8px;"><?= tt('a.twofa_required_note') ?></span><?php endif; ?></p>
  <?php if ($setup): $secret = (string)$_SESSION['acct_enrol_secret']; ?>
    <p class="lede"><?= tt('twofa.setup_lede') ?></p>
    <div class="qr" id="qr" data-uri="<?= e(totp_uri($secret, $u['email'], 'Golden A/S Customer Portal')) ?>" data-fallback="<?= tt('twofa.qr_fail') ?>"></div>
    <p class="hint" style="text-align:center;margin-bottom:6px;"><?= tt('twofa.cannot_scan') ?></p>
    <p class="secret"><?= e(trim(chunk_split($secret, 4, ' '))) ?></p>
    <form method="post" action="<?= url('account') ?>" data-lock style="margin-top:14px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="twofa_confirm">
      <div class="field"><label for="code"><?= tt('twofa.code') ?></label>
        <input id="code" name="code" type="text" class="code-input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autocomplete="one-time-code"></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('a.twofa_confirm') ?></button>
        <a class="btn btn--ghost" href="<?= url('account') ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
    <script nonce="<?= csp_nonce() ?>" src="../assets/qrcode.min.js?v=<?= @filemtime(__DIR__ . '/../../assets/qrcode.min.js') ?: 1 ?>"></script>
    <script nonce="<?= csp_nonce() ?>">(function(){var el=document.getElementById('qr');function fail(){el.innerHTML='<p class="hint" style="text-align:center;">'+el.dataset.fallback+'</p>';}
      if(!window.QRCode||!window.QRCode.toString){fail();return;}QRCode.toString(el.dataset.uri,{type:'svg',margin:1,width:200,color:{dark:'#0B1E2D',light:'#FFFFFF'}},function(e,svg){if(e){fail();return;}el.innerHTML=svg;});})();</script>
  <?php else: ?>
    <p class="hint" style="margin-bottom:12px;"><?= tt('a.twofa_hint') ?></p>
    <div class="btn-row">
      <form method="post" action="<?= url('account') ?>" data-lock>
        <?= csrf_field() ?><input type="hidden" name="action" value="twofa_begin">
        <button class="btn<?= $enabled ? ' btn--ghost' : ' btn--gold' ?>" type="submit"><?= $enabled ? tt('a.twofa_resetup') : tt('a.twofa_setup') ?></button>
      </form>
      <?php if ($enabled && !$required): ?>
      <form method="post" action="<?= url('account') ?>" data-lock data-confirm="<?= tt('a.twofa_disable_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('a.twofa_disable') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger style="display:flex;gap:8px;align-items:flex-end;">
        <?= csrf_field() ?><input type="hidden" name="action" value="twofa_off">
        <div class="field" style="margin:0;"><label><?= tt('a.twofa_pass') ?></label><input name="password" type="password" required autocomplete="current-password"></div>
        <button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('a.twofa_disable') ?></button>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (function_exists('pk_has') && pk_has()): $pks = pk_list((int)$u['id']); $pkOn = pk_enabled(); ?>
<div class="card" id="passkeys">
  <h2>🔑 <?= tt('pk.title') ?></h2>
  <p class="hint"><?= tt('pk.lede') ?></p>
  <?php if ($pks): ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pk.name') ?></th><th><?= tt('pk.created') ?></th><th><?= tt('pk.last_used') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($pks as $k): ?><tr><td><b><?= e($k['name']) ?></b><?= $k['backup_eligible'] ? ' <span class="hint" title="' . tt('pk.synced_tip') . '">☁</span>' : '' ?></td><td class="hint"><?= e(fmt_datetime($k['created_at'])) ?></td><td class="hint"><?= $k['last_used_at'] ? e(fmt_datetime($k['last_used_at'])) : '—' ?></td>
      <td class="row-actions"><form method="post" action="<?= url('account') ?>" class="inline" data-lock data-confirm="<?= tt('pk.remove_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pk_remove"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="hint"><?= tt('pk.none') ?></p><?php endif; ?>
  <?php if ($pkOn): ?>
  <div class="grid grid-3" style="margin-top:10px;align-items:end;">
    <div class="field"><label><?= tt('pk.name') ?></label><input id="pk-name" maxlength="80" placeholder="<?= tt('pk.name_ph') ?>"></div>
    <div class="field" id="pk-code-row"<?= !empty($u['totp_enabled']) ? '' : ' hidden' ?>><label><?= tt('pk.code') ?></label><input id="pk-code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" placeholder="123456"></div>
    <div class="field"><button type="button" class="btn btn--gold" id="pk-add" data-wait="<?= tt('pk.wait') ?>" data-ok="<?= tt('pk.added') ?>" data-cancel="<?= tt('pk.cancelled') ?>" data-exists="<?= tt('pk.exists') ?>" data-unsupported="<?= tt('pk.unsupported') ?>">+ <?= tt('pk.add') ?></button></div>
  </div>
  <p id="pk-reg-msg" class="flash" hidden style="margin-top:8px;"></p>
  <p class="hint" style="margin-top:8px;"><?= tt('pk.how') ?></p>
  <script nonce="<?= csp_nonce() ?>" src="../assets/passkey.js?v=<?= @filemtime(__DIR__ . '/../../assets/passkey.js') ?: 1 ?>"></script>
  <?php else: ?><p class="hint"><?= tt('pk.off') ?></p><?php endif; ?>
</div>
<?php endif; ?>
<?php if (array_key_exists('session_epoch', $u ?? [])): ?>
<div class="card">
  <h2>⎋ <?= tt('sec.my_sessions') ?></h2>
  <p class="hint"><?= e(t('sec.my_sessions_hint', ['m' => sec_cfg('sec_idle_minutes'), 'h' => sec_cfg('sec_max_hours')])) ?></p>
  <form method="post" action="<?= url('account') ?>" data-lock data-confirm="<?= tt('sec.my_revoke_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('sec.my_revoke') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="account_revoke"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('sec.my_revoke') ?></button></form>
</div>
<?php endif; ?>
<?php if (function_exists('clk_has') && clk_has()): $myClk = clk_user_codes(); ?>
<div class="card" id="clocks" style="max-width:860px;">
  <h2>🕓 <?= tt('clk.my_title') ?></h2>
  <p class="hint"><?= e(t('clk.my_hint', ['n' => CLK_MAX])) ?></p>
  <form method="post" action="<?= url('account') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="clocks_save">
    <div class="clk-pick" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:4px 14px;">
      <?php foreach (clk_catalogue() as $code => [$tz, $city]): ?><label class="pl-person" style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="clocks[]" value="<?= e($code) ?>"<?= in_array($code, $myClk, true) ? ' checked' : '' ?>> <b style="width:2.2em;color:var(--gold);font-size:11px;letter-spacing:.06em;"><?= e($code) ?></b> <?= e($city) ?> <span class="hint">· <?= e($tz) ?></span></label><?php endforeach; ?>
    </div>
    <p class="hint" style="margin:8px 0 6px;"><span id="clk-count"><?= count($myClk) ?></span> / <?= CLK_MAX ?> · <?= tt('clk.utc_fixed') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button> <button class="btn btn--ghost" type="submit" name="clocks" value=""><?= tt('clk.reset') ?></button></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var b=document.querySelectorAll('#clocks input[type=checkbox]'),n=document.getElementById('clk-count'),max=<?= CLK_MAX ?>;function f(){var k=0;b.forEach(function(x){if(x.checked)k++;});n.textContent=k;b.forEach(function(x){x.disabled=!x.checked&&k>=max;});}b.forEach(function(x){x.addEventListener('change',f);});f();})();</script>
<?php endif; ?>
<?php if (is_staff() && function_exists('online_detail')): $od = online_detail(); ?>
<div class="card" style="max-width:560px;" id="online">
  <h2>🟢 <?= tt('nav.online_who') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($od) ?></span></h2>
  <p class="hint"><?= tt('nav.online_hint') ?></p>
  <?php if (!$od): ?><p class="hint">—</p><?php else: ?><table class="tbl"><thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('nav.online_since') ?></th><th><?= tt('nav.online_uptime') ?></th><th><?= tt('nav.online_seen') ?></th></tr></thead><tbody>
  <?php foreach ($od as $o): ?><tr><td><?= avatar_html((int)$o['id'], (string)$o['full_name'], 20, 'ava--inline') ?> <?= e($o['full_name']) ?><?= $o['customer_name'] ? ' <span class="hint">· ' . e($o['customer_name']) . '</span>' : '' ?></td><td class="hint"><?= $o['since'] ? e(fmt_datetime($o['since'])) : '—' ?></td><td><b><?= e(fmt_uptime($o['uptime'] ?? null)) ?></b></td><td class="hint"><?= e(fmt_datetime($o['last_seen_at'])) ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

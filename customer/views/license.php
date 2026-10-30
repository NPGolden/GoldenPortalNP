<?php
/** Licence — serial number, service contract, key installation.   GAS-CP-R22-001
 *  Reachable by everybody signed in (it is the page shown when the portal is locked); only administrators install a key. */
require __DIR__ . '/_layout.php';
$u = require_login();
$S = lic_status(); $L = $S['lic']; $admin = is_cp_admin();
$fmt = fn($d) => $d ? fmt_date((string)$d) : '—';
layout_head(t('lic.title'), 'license');
?>
<p class="eyebrow"><?= tt('nav.settings') ?> › <?= tt('lic.title') ?></p>
<div class="page-head">
  <div><h1><?= tt('lic.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('lic.lede') ?></p></div>
</div>

<?php if (!empty($S['locked'])): ?>
<div class="lic-lock">
  <div class="lic-lock-ico" aria-hidden="true">🔒</div>
  <div><h2><?= tt('lic.locked_title') ?></h2><p><?= e($S['why']) ?></p><p class="hint"><?= $admin ? tt('lic.locked_admin') : tt('lic.locked_user') ?></p></div>
</div>
<?php endif; ?>

<div class="cols lic-cols">
  <div class="card">
    <h2><?= tt('lic.contract') ?> <?= lic_state_pill($S['state']) ?></h2>
    <?php if ($S['state'] === 'nodb'): ?><div class="flash flash--warn"><?= tt('lic.b_nodb') ?></div><?php endif; ?>
    <dl class="lic-dl">
      <dt><?= tt('lic.serial') ?></dt><dd><b class="mono"><?= e($L['serial'] ?? '—') ?></b></dd>
      <dt><?= tt('lic.licensee') ?></dt><dd><?= e($L['licensee'] ?? '—') ?></dd>
      <dt><?= tt('lic.brand') ?></dt><dd><?= e(trim((string)($L['brand']['name'] ?? '')) ?: t('app.name')) ?></dd>
      <dt><?= tt('lic.domains') ?></dt><dd><?= $L && !empty($L['domains']) ? e(implode(', ', (array)$L['domains'])) : tt('lic.any_domain') ?></dd>
      <dt><?= tt('lic.contract_period') ?></dt><dd><?= e($fmt($L['contract_start'] ?? null)) ?> → <b><?= e($fmt($L['contract_end'] ?? null)) ?></b></dd>
      <dt><?= tt('lic.days_left') ?></dt><dd><?php if ($S['state'] === 'valid' || $S['state'] === 'expiring'): ?><?= (int)$S['days'] ?> <?= tt('lic.days') ?><?php elseif ($S['state'] === 'grace'): ?><?= e(t('lic.grace_left', ['n' => (int)$S['days']])) ?><?php elseif ($S['state'] === 'missing'): ?><?= e(t('lic.activation_left', ['n' => max(0, (int)$S['days'])])) ?><?php else: ?>—<?php endif; ?></dd>
      <dt><?= tt('lic.grace') ?></dt><dd><?= $L ? (int)($L['grace_days'] ?? 30) . ' ' . tt('lic.days') : '—' ?></dd>
      <dt><?= tt('lic.max_users') ?></dt><dd><?= $L ? ((int)($L['max_users'] ?? 0) ?: tt('lic.unlimited')) : '—' ?><?php if ($L && (int)($L['max_users'] ?? 0)): ?> · <?= e(t('lic.users_now', ['n' => (int) scalar('SELECT COUNT(*) FROM customer_users WHERE is_active = 1')])) ?><?php endif; ?></dd>
      <dt><?= tt('lic.issued') ?></dt><dd><?= e($fmt($L['issued'] ?? null)) ?></dd>
      <dt><?= tt('lic.installed') ?></dt><dd><?= cp_setting('lic_installed_at') ? e(fmt_datetime(cp_setting('lic_installed_at'))) . ' · ' . e((string) cp_setting('lic_installed_by')) : '—' ?></dd>
      <?php if ($L && !empty($L['notes'])): ?><dt><?= tt('lic.notes') ?></dt><dd><?= e($L['notes']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($L && !empty($L['vendor'])): $V = $L['vendor']; ?>
    <h3 class="ac-h"><?= tt('lic.vendor') ?></h3>
    <p style="margin:0;"><?= e($V['name'] ?? '') ?><?= !empty($V['email']) ? ' · <a href="mailto:' . e($V['email']) . '?subject=' . rawurlencode(t('lic.renew_subject', ['s' => $L['serial']])) . '">' . e($V['email']) . '</a>' : '' ?><?= !empty($V['phone']) ? ' · ' . e($V['phone']) : '' ?></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= tt('lic.this_install') ?></h2>
    <dl class="lic-dl">
      <dt><?= tt('lic.instance') ?></dt><dd><b class="mono"><?= e(lic_instance_id()) ?></b></dd>
      <dt><?= tt('lic.host') ?></dt><dd class="mono"><?= e(lic_host()) ?></dd>
      <dt><?= tt('ab.version') ?></dt><dd>v<?= e(CP_VERSION) ?> · <?= e(CP_BUILD) ?></dd>
    </dl>
    <p class="hint"><?= tt('lic.instance_hint') ?></p>
    <?php if ($admin): ?>
    <h3 class="ac-h"><?= tt('lic.install_key') ?></h3>
    <form method="post" action="<?= url('license') ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="lic_install">
      <div class="field"><label for="key"><?= tt('lic.key') ?></label><textarea id="key" name="key" rows="6" class="mono" required placeholder="GASL1.…" spellcheck="false"></textarea><p class="hint" style="margin:4px 0 0;"><?= tt('lic.key_hint') ?></p></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('lic.install') ?></button>
        <?php if (cp_setting('license')): ?><button class="btn btn--ghost" type="submit" name="action" value="lic_remove" data-js-confirm="<?= e(t('lic.remove_confirm')) ?>"><?= tt('lic.remove') ?></button><?php endif; ?></div>
    </form>
    <?php endif; ?>
  </div>
</div>
<?php layout_foot(); ?>

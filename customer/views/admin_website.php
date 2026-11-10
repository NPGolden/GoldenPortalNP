<?php
/** Settings › System › Website (GAS-CP-R90-001): the statistics feed key for goldenas.com.gr and a preview of the four numbers it serves. */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$key = (string)cp_setting('web_site_key', ''); $last = (string)cp_setting('web_last_pull', '');
$counts = function_exists('web_has') && web_has() ? web_counts() : [];
layout_head(t('web.title'), 'admin_website');
?>
<?php settings_tabs('admin_website'); ?>
<div class="card"><h2>✉ <?= tt('web.mail') ?></h2>
  <?php $tr = function_exists('cp_mail_transport') ? cp_mail_transport() : 'mail'; ?>
  <p><?= $tr === 'graph' ? pill('green', t('web.mail_graph', ['from' => (string)cfg('graph.from')])) : ($tr === 'smtp' ? pill('green', t('web.mail_smtp', ['host' => (string)cfg('smtp.host'), 'user' => (string)cfg('smtp.user')])) : pill('gold', t('web.mail_phpmail'))) ?></p>
  <p class="hint"><?= tt('web.mail_hint') ?></p>
  <?php if (isset($_SESSION['mail_test'])): $mt = $_SESSION['mail_test']; unset($_SESSION['mail_test']); ?><p class="flash flash--<?= $mt['ok'] ? 'good' : 'bad' ?>" style="margin:8px 0;"><?= e($mt['ok'] ? t('web.mail_test_ok', ['to' => $mt['to']]) : t('web.mail_test_fail')) ?></p><?php endif; ?>
  <form method="post" class="btn-row" style="margin-top:8px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_mail_test"><button class="btn btn--sm" type="submit">✉ <?= e(t('web.mail_test_btn', ['to' => (string)(current_user()['email'] ?? '')])) ?></button><span class="hint"><?= tt('web.mail_test_hint') ?></span></form>
</div>
<h2 class="settings-h"><?= tt('web.title') ?></h2>
<div class="card">
  <h2>🌐 <?= tt('web.feed') ?></h2>
  <p class="hint"><?= tt('web.feed_hint') ?></p>
  <?php if ($counts): ?>
  <div class="kpi kpi--4" style="margin:12px 0;">
    <div class="stat"><b><?= (int)$counts['vessels_year'] ?></b><span class="s"><?= e(t('act.fleet_year', ['y' => $counts['year']])) ?></span></div>
    <div class="stat"><b><?= (int)$counts['vessels_30d'] ?></b><span class="s"><?= tt('act.fleet_month') ?></span></div>
    <div class="stat"><b><?= (int)$counts['new_vessels_year'] ?></b><span class="s"><?= e(t('act.new_vessels', ['y' => $counts['year']])) ?></span></div>
    <div class="stat"><b><?= (int)$counts['active_vessels'] ?></b><span class="s"><?= tt('act.vessels_total') ?></span></div>
  </div>
  <?php endif; ?>
  <form method="post" action="<?= url('admin_website') ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_settings_save">
    <?php $once = (string)($_SESSION['web_key_once'] ?? ''); unset($_SESSION['web_key_once']); if ($once !== '' && hash_equals($key, $once)): ?><label class="span2"><?= tt('web.key') ?> — <b><?= tt('rc.key_once') ?></b><input value="<?= e($once) ?>" readonly class="mono" style="user-select:all"><span class="hint"><?= tt('rc.key_once_hint') ?></span></label>
    <?php else: ?><label><?= tt('web.key') ?><input value="<?= $key ? e(substr($key, 0, 6)) . '…' . e(substr($key, -4)) : tt('rc.key_none') ?>" readonly><span class="hint"><?= tt('web.key_hint') ?></span></label><?php endif; ?>
    <label><?= tt('web.last_pull') ?><input value="<?= $last ? e($last) : '—' ?>" readonly></label>
    <label class="check span2"><input type="checkbox" name="web_rotate_key" value="1"> <?= $key ? tt('web.key_rotate') : tt('web.key_create') ?></label>
    <?php if ($key): ?><label class="check span2"><input type="checkbox" name="web_disable" value="1"> <?= tt('web.disable') ?></label><?php endif; ?>
    <p class="hint span2"><?= tt('web.url') ?>: <code><?= e(rtrim((string)cfg('app.url', ''), '/')) ?>/customer/web_feed.php</code> · <code>X-Site-Key</code></p>
    <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<div class="card"><h2>📈 <?= tt('ws.title') ?></h2>
  <p class="hint"><?= tt('ws.settings_hint') ?></p>
  <?php $wsk = function_exists('ws_key') ? ws_key() : ''; $wsl = (string)cp_setting('ws_last_pull', ''); $wse = (string)cp_setting('ws_last_error', ''); ?>
  <form method="post" action="<?= url('admin_website') ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ws_settings_save">
    <label><?= tt('ws.feed_url') ?><input name="ws_feed_url" value="<?= e((string)cp_setting('ws_feed_url', '')) ?>" placeholder="https://www.goldenas.com.gr/api/stats-feed.php"></label>
    <label><?= tt('ws.feed_key') ?><input name="ws_feed_key" value="" placeholder="<?= $wsk ? e(substr($wsk, 0, 6)) . '…' . e(substr($wsk, -4)) : tt('rc.key_none') ?>" autocomplete="off"><span class="hint"><?= tt('ws.feed_key_hint') ?></span></label>
    <label><?= tt('ws.last_pull') ?><input value="<?= $wsl ? e(fmt_datetime($wsl)) : '—' ?>" readonly></label>
    <label><?= tt('ws.last_error') ?><input value="<?= $wse ? e($wse) : '—' ?>" readonly></label>
    <?php if ($wsk): ?><label class="check span2"><input type="checkbox" name="ws_clear_key" value="1"> <?= tt('ws.clear_key') ?></label><?php endif; ?>
    <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button><?php if ($wsk): ?><a class="btn" href="<?= url('web_stats') ?>"><?= tt('ws.open') ?> →</a><?php endif; ?></div>
  </form>
</div>
<?php layout_foot(); ?>

<?php
/** HR › Recruiting › Settings (GAS-CP-R89-001): website feed key / origin, retention, notifications, candidate e-mail.  ?p=rc_settings */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('rcr_has') || !rcr_has() || !rcr_can()) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$key = (string)cp_setting('rc_site_key', ''); $last = one('SELECT * FROM rc_retention_log ORDER BY id DESC LIMIT 1');
layout_head(t('rc.settings'), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <?= tt('rc.settings') ?></p><h1>⚙ <?= tt('rc.settings') ?></h1>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_settings_save">
  <h2 class="span2"><?= tt('rc.feed') ?></h2>
  <div class="field"><label><?= tt('rc.site_origin') ?></label><input name="rc_site_origin" value="<?= e((string)cp_setting('rc_site_origin', 'https://goldenas.com.gr')) ?>"></div>
  <?php $once = (string)($_SESSION['rc_key_once'] ?? ''); unset($_SESSION['rc_key_once']); if ($once !== '' && hash_equals($key, $once)): ?><div class="field span2"><label><?= tt('rc.site_key') ?> — <b><?= tt('rc.key_once') ?></b></label><input value="<?= e($once) ?>" readonly class="mono" style="user-select:all"><span class="hint"><?= tt('rc.key_once_hint') ?></span></div>
  <?php else: ?><div class="field"><label><?= tt('rc.site_key') ?></label><input value="<?= $key ? e(substr($key, 0, 6)) . '…' . e(substr($key, -4)) : tt('rc.key_none') ?>" readonly><span class="hint"><?= tt('rc.key_hint') ?></span></div><?php endif; ?>
  <label class="check span2"><input type="checkbox" name="rc_rotate_key" value="1"> <?= $key ? tt('rc.key_rotate') : tt('rc.key_create') ?></label>
  <p class="hint span2"><?= tt('rc.feed_url') ?>: <code><?= e(rtrim((string)cfg('app.url', ''), '/')) ?>/customer/rcr_feed.php</code></p>
  <h2 class="span2">GDPR</h2>
  <div class="field"><label><?= tt('rc.retention') ?></label><input type="number" name="rc_retention_months" min="1" max="36" value="<?= (int)cp_setting('rc_retention_months', '6') ?>"></div>
  <div class="field"><label><?= tt('rc.cv_max') ?></label><input type="number" name="rc_cv_max_mb" min="1" max="25" value="<?= (int)cp_setting('rc_cv_max_mb', '8') ?>"></div>
  <p class="hint span2"><?= tt('rc.retention_hint') ?> <?= $last ? '· ' . tt('rc.last_run') . ' ' . e(fmt_datetime($last['ran_at'])) . ' (' . (int)$last['apps_deleted'] . ' / ' . (int)$last['apps_anonymised'] . ' / ' . (int)$last['cvs_deleted'] . ' / ' . (int)$last['events_deleted'] . ' / ' . (int)$last['pool_deleted'] . ')' : '· ' . tt('rc.never_run') ?></p>
  <h2 class="span2"><?= tt('rc.notifications') ?></h2>
  <div class="field"><label><?= tt('rc.notify_email') ?></label><input name="rc_notify_email" value="<?= e((string)cp_setting('rc_notify_email', '')) ?>" placeholder="hr@goldenas.com.gr"></div>
  <div class="field"><label><?= tt('rc.mail_from') ?></label><input name="rc_mail_from" value="<?= e((string)cp_setting('rc_mail_from', '')) ?>" placeholder="hr@goldenas.com.gr"><span class="hint"><?= tt('rc.mail_from_hint') ?></span></div>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button></div>
</form>
<?php if (rcr_eval_has()): $crit = rcr_eval_criteria(); ?><form method="post" class="card" data-lock id="eval"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_eval_criteria_save">
  <h2>🧭 <?= tt('rc.ev_criteria') ?></h2><p class="hint"><?= tt('rc.ev_criteria_hint') ?></p>
  <table class="tbl"><thead><tr><th style="width:120px;"><?= tt('rc.code') ?></th><th><?= tt('rc.ev_criterion') ?></th><th style="width:120px;" class="num"><?= tt('rc.ev_weight') ?></th></tr></thead><tbody>
  <?php for ($i = 0; $i < 8; $i++): $c = $crit[$i] ?? ['key' => '', 'label' => '', 'weight' => '']; ?><tr><td><input name="crit[<?= $i ?>][key]" value="<?= e((string)$c['key']) ?>" maxlength="20" pattern="[a-z0-9_]*"></td><td><input name="crit[<?= $i ?>][label]" value="<?= e((string)$c['label']) ?>" maxlength="120"></td><td><input name="crit[<?= $i ?>][weight]" value="<?= e((string)$c['weight']) ?>" inputmode="decimal" style="text-align:right;"></td></tr><?php endfor; ?>
  </tbody></table><div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold"><?= tt('btn.save') ?></button></div></form><?php endif; ?>
<form method="post" class="card" data-lock data-confirm="<?= tt('rc.retention_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_retention_now"><button class="btn btn--ghost"><?= tt('rc.retention_now') ?></button></form>
<?php layout_foot();

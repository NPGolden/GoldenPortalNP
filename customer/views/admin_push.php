<?php
/** Settings › Push notifications (GAS-CP-R97-196): the keys of Google and Apple, the clock job, the registered phones and the queue.  ?p=admin_push */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$S = pn_stats(); $fcm = pn_fcm_ready(); $apns = pn_apns_ready(); $h2 = pn_http2(); $last = $S['last']; $lastOk = $last !== '' && strtotime($last) > time() - 900;
$yes = fn(bool $b, string $ok = '', string $no = '') => $b ? pill('good', $ok !== '' ? $ok : t('pn.yes')) : pill('bad', $no !== '' ? $no : t('pn.no'));
$APP = ['customer' => 'Golden A/S Customer', 'engineer' => 'Golden A/S Service', 'pm' => 'Golden PM', 'hr' => 'Golden HR', 'employee' => 'Golden A/S Employee'];
layout_head(t('pn.title'), 'admin_push');
?>
<?php settings_tabs('admin_push'); ?>
<div class="page-head"><div><h1>🔔 <?= tt('pn.title') ?></h1><p class="lede" style="margin-bottom:0;text-align:justify;"><?= tt('pn.lede') ?></p></div></div>
<?php if (!pn_has()): ?><div class="card"><p class="hint"><?= tt('pn.need') ?></p></div><?php layout_foot(); return; endif; ?>
<div class="grid grid-2" style="align-items:start;">
  <div class="card"><div class="card-head"><h2><?= tt('pn.s_state') ?></h2></div>
    <table class="tbl" data-nosort><tbody>
      <tr><td><?= tt('pn.c_fcm') ?><br><span class="hint"><?= e($fcm ? t('pn.c_fcm_p', ['p' => (string) cp_setting('pn_fcm_project', '—')]) : t('pn.c_fcm_d')) ?></span></td><td class="num"><?= $yes($fcm, t('pn.loaded'), t('pn.missing')) ?></td></tr>
      <tr><td><?= tt('pn.c_apns') ?><br><span class="hint"><?= e($apns ? t('pn.c_apns_p', ['k' => (string) cp_setting('pn_apns_key_id', ''), 't' => (string) cp_setting('pn_apns_team_id', '')]) : t('pn.c_apns_d')) ?></span></td><td class="num"><?= $yes($apns, t('pn.loaded'), t('pn.missing')) ?></td></tr>
      <tr><td><?= tt('pn.c_h2') ?><br><span class="hint"><?= tt('pn.c_h2_d') ?></span></td><td class="num"><?= $yes((bool)$h2) ?></td></tr>
      <tr><td><?= tt('pn.c_tick') ?><br><span class="hint"><?= e($last !== '' ? t('pn.c_tick_last', ['t' => fmt_datetime($last)]) : t('pn.c_tick_never')) ?></span></td><td class="num"><?= $yes($lastOk, t('pn.running'), t('pn.not_running')) ?></td></tr>
    </tbody></table>
    <p class="hint" style="margin:10px 0 4px;"><?= tt('pn.cron_d') ?></p>
    <pre style="white-space:pre-wrap;word-break:break-all;margin:0;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:12px;">*/5 * * * *  php <?= e((string) realpath(dirname(__DIR__) . '/tick.php')) ?> &gt;/dev/null 2&gt;&amp;1</pre>
    <div class="btn-row" style="margin-top:12px;">
      <form method="post" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pn_test"><button class="btn btn--sm" type="submit">📲 <?= tt('pn.test') ?></button></form>
      <form method="post" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pn_run"><button class="btn btn--ghost btn--sm" type="submit">▶ <?= tt('pn.run') ?></button></form>
    </div>
  </div>
  <div class="card"><div class="card-head"><h2>🔑 <?= tt('pn.s_keys') ?></h2></div><p class="hint" style="text-align:justify;"><?= tt('pn.keys_d') ?></p>
    <form method="post" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pn_keys">
      <div class="field"><label><?= tt('pn.f_fcm') ?></label><input type="file" name="fcm" accept=".json,application/json"></div>
      <div class="field"><label><?= tt('pn.f_apns') ?></label><input type="file" name="apns" accept=".p8"></div>
      <div class="grid grid-2"><div class="field"><label><?= tt('pn.f_kid') ?></label><input type="text" name="apns_key_id" maxlength="10" pattern="[A-Za-z0-9]{10}" value="<?= e((string) cp_setting('pn_apns_key_id', '')) ?>" autocomplete="off"></div>
        <div class="field"><label><?= tt('pn.f_team') ?></label><input type="text" name="apns_team_id" maxlength="10" pattern="[A-Za-z0-9]{10}" value="<?= e((string) cp_setting('pn_apns_team_id', '')) ?>" autocomplete="off"></div></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
    </form>
    <?php if ($fcm || is_file(pn_apns_file())): ?><div class="btn-row" style="margin-top:10px;">
      <?php foreach (['fcm' => $fcm, 'apns' => is_file(pn_apns_file())] as $w => $has): if (!$has) continue; ?><form method="post" class="inline" data-confirm="<?= tt('pn.rm_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="pn_key_remove"><input type="hidden" name="which" value="<?= $w ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 <?= tt('pn.rm_' . $w) ?></button></form><?php endforeach; ?>
    </div><?php endif; ?>
  </div>
</div>
<div class="grid grid-2" style="align-items:start;">
  <div class="card"><div class="card-head"><h2>📱 <?= tt('pn.s_dev') ?></h2></div>
    <?php if (!$S['devices']): ?><p class="hint"><?= tt('pn.dev_none') ?></p><?php else: ?>
    <table class="tbl" data-nosort><thead><tr><th><?= tt('pn.th_app') ?></th><th><?= tt('pn.th_plat') ?></th><th class="num"><?= tt('pn.th_dev') ?></th><th class="num"><?= tt('pn.th_push') ?></th></tr></thead><tbody>
    <?php foreach ($S['devices'] as $d): ?><tr><td><?= e($APP[$d['app_id']] ?? (string)$d['app_id']) ?></td><td><?= e(['ios' => 'iPhone / iPad', 'android' => 'Android', 'web' => 'Web'][$d['platform']] ?? (string)$d['platform']) ?></td><td class="num"><?= (int)$d['n'] ?></td><td class="num"><b><?= (int)$d['p'] ?></b></td></tr><?php endforeach; ?>
    </tbody></table><p class="hint" style="margin:8px 0 0;"><?= tt('pn.dev_d') ?></p><?php endif; ?>
  </div>
  <div class="card"><div class="card-head"><h2>📤 <?= tt('pn.s_queue') ?></h2></div>
    <p><?= e(t('pn.q_line', ['p' => $S['pending'], 's' => $S['sent24'], 'f' => $S['fail24']])) ?></p>
    <?php if ($S['errors']): ?><table class="tbl" data-nosort><thead><tr><th><?= tt('pn.th_err') ?></th><th class="num">#</th><th><?= tt('pn.th_last') ?></th></tr></thead><tbody>
    <?php foreach ($S['errors'] as $e2): ?><tr><td><?= e((string)$e2['error']) ?></td><td class="num"><?= (int)$e2['n'] ?></td><td class="date"><?= e(fmt_datetime((string)$e2['at'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  </div>
</div>
<div class="card"><h3 class="ac-h"><?= tt('pn.s_what') ?></h3><ul class="hint" style="padding-left:18px;margin:0;"><li><?= tt('pn.w_1') ?></li><li><?= tt('pn.w_2') ?></li><li><?= tt('pn.w_3') ?></li><li><?= tt('pn.w_4') ?></li></ul></div>
<?php layout_foot();

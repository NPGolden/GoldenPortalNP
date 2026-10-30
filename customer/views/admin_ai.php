<?php
/** Settings › Assistant — Claude inside the portal: switch, key (write-only, encrypted), model, monthly cap, prices, pages, usage and log (GAS-CP-R50-001). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
if (!function_exists('ai_has') || !ai_has()) { layout_head(t('ai.settings'), 'admin_ai'); settings_tabs('admin_ai'); echo '<div class="flash flash--warn">' . tt('ai.need_r50') . '</div>'; layout_foot(); return; }
$on = ai_setting('ai_enabled', '0') === '1'; $hint = ai_key_hint(); $model = ai_setting('ai_model', AI_MODELS[1]); $pages = ai_pages(); $month = ai_month_cost(); $cap = ai_cap();
$usage = ai_usage(30); $log = all('SELECT * FROM ai_log ORDER BY id DESC LIMIT 40'); $nStaff = (int) scalar("SELECT COUNT(*) FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 AND can_ai = 1");
$m = fn(float $v) => number_format($v, 2, ',', '.');
layout_head(t('ai.settings'), 'admin_ai');
?>
<?php settings_tabs('admin_ai'); ?>
<h2 class="settings-h">✨ <?= tt('ai.settings') ?></h2>
<p class="lede"><?= tt('ai.settings_lede') ?></p>
<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat<?= $on && $hint ? ' green' : '' ?>"><b><?= $on && $hint ? tt('ai.on') : tt('ai.off') ?></b><span class="s"><?= tt('ai.state') ?></span></span>
  <span class="stat<?= $cap > 0 && $month >= $cap ? ' red' : ($cap > 0 && $month >= $cap * 0.8 ? ' gold' : '') ?>"><b><?= e($m($month)) ?> €</b><span class="s"><?= tt('ai.month_cost') ?><?= $cap > 0 ? ' / ' . e($m($cap)) . ' €' : '' ?></span></span>
  <span class="stat"><b><?= (int) scalar("SELECT COUNT(*) FROM ai_log WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')") ?></b><span class="s"><?= tt('ai.month_calls') ?></span></span>
  <span class="stat"><b><?= $nStaff ?></b><span class="s"><?= tt('ai.staff_on') ?></span></span>
</div>

<div class="card">
  <form method="post" action="<?= url('admin_ai') ?>" data-lock autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="ai_settings_save">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('ai.enabled') ?></label><label class="chk"><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> <?= tt('ai.enabled_lbl') ?></label><p class="hint"><?= tt('ai.enabled_hint') ?></p></div>
      <div class="field"><label><?= tt('ai.key') ?></label><input name="key" type="password" autocomplete="new-password" placeholder="<?= $hint ? e($hint) : 'sk-ant-…' ?>"><label class="chk" style="margin-top:4px;font-size:12px;"><input type="checkbox" name="key_clear" value="1"> <?= tt('ai.key_clear') ?></label><p class="hint"><?= tt('ai.key_hint') ?></p></div>
      <div class="field"><label><?= tt('ai.workspace') ?></label><input name="workspace" value="<?= e(ai_setting('ai_workspace')) ?>" maxlength="80" placeholder="wrkspc_…"><p class="hint"><?= tt('ai.workspace_hint') ?></p></div>
      <div class="field"><label><?= tt('ai.model') ?></label><input name="model" list="ai-models" value="<?= e($model) ?>" maxlength="60"><datalist id="ai-models"><?php foreach (AI_MODELS as $mm): ?><option value="<?= $mm ?>"><?php endforeach; ?></datalist><p class="hint"><?= tt('ai.model_hint') ?></p></div>
      <div class="field"><label><?= tt('ai.cap') ?> (€)</label><input name="cap" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format($cap, 2, '.', ''), '0'), '.')) ?>"><p class="hint"><?= tt('ai.cap_hint') ?></p></div>
      <div class="field"><label><?= tt('ai.price_in') ?></label><input name="price_in" inputmode="decimal" value="<?= e(ai_setting('ai_price_in', '3')) ?>"></div>
      <div class="field"><label><?= tt('ai.price_out') ?></label><input name="price_out" inputmode="decimal" value="<?= e(ai_setting('ai_price_out', '15')) ?>"><p class="hint"><?= tt('ai.price_hint') ?></p></div>
    </div>
    <div class="field"><label><?= tt('ai.pages') ?></label><div class="ai-pages"><?php foreach (ai_all_pages() as $slug => $k): ?><label class="chk"><input type="checkbox" name="pages[]" value="<?= $slug ?>"<?= in_array($slug, $pages, true) ? ' checked' : '' ?>> <?= e(t($k)) ?> <span class="hint"><?= $slug ?></span></label><?php endforeach; ?></div><p class="hint"><?= tt('ai.pages_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('ai.data_hint') ?></span></div>
  </form>
</div>

<div class="card" id="ai-status">
  <div class="card-head"><h2><?= tt('ai.status') ?></h2><form method="post" action="<?= url('admin_ai') ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ai_test"><button class="btn btn--ghost btn--sm" type="submit">🔌 <?= tt('ai.test') ?></button></form></div>
  <ul class="ai-status"><?php foreach (ai_status() as [$k, $ok, $lbl]): ?><li class="<?= $ok ? 'is-ok' : 'is-bad' ?>"><?= $ok ? '✔' : '✖' ?> <?= tt($lbl) ?></li><?php endforeach; ?></ul>
  <p class="hint"><?= tt('ai.status_hint') ?></p>
</div>
<div class="cols">
<div class="card">
  <h2><?= tt('ai.usage') ?></h2>
  <?php if (!$usage): ?><p class="hint"><?= tt('ai.no_usage') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th class="num"><?= tt('ai.calls') ?></th><th class="num"><?= tt('ai.tokens_in') ?></th><th class="num"><?= tt('ai.tokens_out') ?></th><th class="num">€</th></tr></thead><tbody>
  <?php foreach ($usage as $r): ?><tr><td><?= e(fmt_date($r['d'])) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= number_format((int)$r['i'], 0, ',', '.') ?></td><td class="num"><?= number_format((int)$r['o'], 0, ',', '.') ?></td><td class="num"><?= e($m((float)$r['c'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="card">
  <h2><?= tt('ai.log') ?></h2>
  <?php if (!$log): ?><p class="hint"><?= tt('ai.no_usage') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('ai.page') ?></th><th><?= tt('ai.kind') ?></th><th class="num">ms</th><th class="num">€</th><th></th></tr></thead><tbody>
  <?php foreach ($log as $r): ?><tr><td class="hint"><?= e(fmt_datetime($r['created_at'])) ?></td><td><?= e($r['user_name'] ?? '') ?></td><td class="hint"><?= e($r['page']) ?></td><td><?= e(t('ai.' . $r['kind'])) ?></td><td class="num"><?= (int)$r['ms'] ?></td><td class="num"><?= e($m((float)$r['cost_eur'])) ?></td><td><?= $r['ok'] ? pill('green', 'OK') : pill('bad', e(mb_substr((string)$r['error'], 0, 60))) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

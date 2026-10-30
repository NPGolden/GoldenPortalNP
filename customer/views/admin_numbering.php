<?php
/** Settings › Numbering — automatic project / sub-project / SQ / PI numbers (GAS-CP-R43-001). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); $c = function_exists('num_has') && num_has() ? num_cfg() : null;
layout_head(t('num.title'), 'admin_numbering');
?>
<?php settings_tabs('admin_numbering'); ?>
<h2 class="settings-h"><?= tt('num.title') ?></h2>
<div class="card">
  <h2>🔢 <?= tt('num.title') ?></h2>
  <p class="hint"><?= tt('num.lede') ?></p>
  <?php if (!$c): ?><div class="flash flash--warn"><?= tt('num.need_db') ?></div><?php else: $np = num_next_project(); $sqN = (int) scalar("SELECT last_no FROM doc_sequences WHERE scope = 'portal' AND family = 'SQ'") + 1; $piN = (int) scalar("SELECT last_no FROM doc_sequences WHERE scope = 'portal' AND family = 'PI'") + 1; ?>
  <form method="post" action="<?= url('admin_numbering') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="num_save">
    <label class="chk" style="margin-bottom:10px;"><input type="checkbox" name="auto" value="1"<?= $c['auto'] ? ' checked' : '' ?>> <?= tt('num.auto') ?></label>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('num.p_prefix') ?></label><input name="p_prefix" maxlength="12" value="<?= e($c['p_prefix']) ?>"></div>
      <div class="field"><label><?= tt('num.p_digits') ?></label><input name="p_digits" type="number" min="3" max="8" value="<?= $c['p_digits'] ?>"></div>
      <div class="field"><label><?= tt('num.p_next') ?></label><input name="p_next" type="number" min="1" value="<?= $c['p_next'] ?>"><p class="hint"><?= tt('num.p_next_hint') ?></p></div>
      <div class="field"><label><?= tt('num.s_prefix') ?></label><input name="s_prefix" maxlength="12" value="<?= e($c['s_prefix']) ?>"></div>
      <div class="field"><label><?= tt('num.s_digits') ?></label><input name="s_digits" type="number" min="2" max="4" value="<?= $c['s_digits'] ?>"></div>
      <div></div>
      <div class="field"><label><?= tt('num.sq_digits') ?></label><input name="sq_digits" type="number" min="3" max="6" value="<?= $c['sq_digits'] ?>"></div>
      <div class="field"><label><?= tt('num.pi_digits') ?></label><input name="pi_digits" type="number" min="3" max="6" value="<?= $c['pi_digits'] ?>"></div>
    </div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <h3 style="margin-top:16px;"><?= tt('num.preview') ?></h3>
  <table class="kv"><tbody>
    <tr><th><?= tt('pr.one') ?></th><td class="mono"><?= e($np) ?></td></tr>
    <tr><th><?= tt('sp.one') ?></th><td class="mono"><?= e($np) ?> <?= e($c['s_prefix'] . str_pad('1', $c['s_digits'], '0', STR_PAD_LEFT)) ?></td></tr>
    <tr><th>SQ</th><td class="mono"><?= e($np . '.' . $c['s_prefix'] . str_pad('1', $c['s_digits'], '0', STR_PAD_LEFT) . '.SQ.' . str_pad((string)$sqN, $c['sq_digits'], '0', STR_PAD_LEFT)) ?></td></tr>
    <tr><th>PI</th><td class="mono"><?= e($np . '.' . $c['s_prefix'] . str_pad('1', $c['s_digits'], '0', STR_PAD_LEFT) . '.PI.' . str_pad((string)$piN, $c['pi_digits'], '0', STR_PAD_LEFT) . '.1') ?></td></tr>
  </tbody></table>
  <p class="hint"><?= tt('num.rules') ?></p>
  <?php endif; ?>
</div>
<div class="card">
  <h2>🕒 <?= tt('tz.title') ?></h2>
  <p class="hint"><?= tt('tz.lede') ?></p>
  <form method="post" action="<?= url('admin_numbering') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="num_save"><?php foreach (['p_prefix', 'p_digits', 'p_next', 's_prefix', 's_digits', 'sq_digits', 'pi_digits'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)($c[$k] ?? '')) ?>"><?php endforeach; ?><?php if ($c && $c['auto']): ?><input type="hidden" name="auto" value="1"><?php endif; ?>
    <?php $zones = timezone_identifiers_list(); $zsel = function (string $name, string $cur) use ($zones) { $h = '<select name="' . $name . '">'; foreach ($zones as $z) $h .= '<option value="' . e($z) . '"' . ($z === $cur ? ' selected' : '') . '>' . e($z) . '</option>'; return $h . '</select>'; }; ?>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('tz.app') ?></label><?= $zsel('tz_app', tz_app()) ?><p class="hint"><?= tt('tz.app_hint') ?></p></div>
      <div class="field"><label><?= tt('tz.db') ?></label><?= $zsel('tz_db', tz_db()) ?><p class="hint"><?= tt('tz.db_hint') ?></p></div>
    </div>
    <?php $dbNow = (string) scalar('SELECT NOW()'); $sysTz = (string) scalar('SELECT @@system_time_zone'); ?>
    <table class="kv" style="margin:8px 0;"><tbody>
      <tr><th><?= tt('tz.now_app') ?></th><td class="mono"><?= e((new DateTime('now', new DateTimeZone(tz_app())))->format('d.m.Y H:i')) ?> <?= e(tz_abbr()) ?></td></tr>
      <tr><th><?= tt('tz.now_db') ?></th><td class="mono"><?= e($dbNow) ?> <span class="hint">(<?= e(tz_db()) ?> · <?= tt('tz.server') ?> <?= e($sysTz) ?>)</span> → <?= e(fmt_datetime($dbNow)) ?></td></tr>
    </tbody></table>
    <p class="hint"><?= tt('tz.check') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php layout_foot(); ?>

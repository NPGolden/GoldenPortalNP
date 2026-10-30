<?php
/** Settings › Lists & categories › Offer texts — the text blocks of the offer document per layout / language / branch / customer.   GAS-CP-R33-001 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); of_require();
$ok = ob_has();
$rows = $ok ? ot_all() : [];
$eid = (int)($_GET['id'] ?? 0); $t = $eid ? ot_get($eid) : null;
$keyPre = in_array($_GET['key'] ?? '', OB_BLOCK_KEYS, true) ? $_GET['key'] : ($t['block_key'] ?? 'intro');
$layPre = in_array($_GET['layout'] ?? '', OB_LAYOUTS, true) ? $_GET['layout'] : ($t['layout'] ?? '');
$v = fn(string $k, $d = '') => e((string)($t[$k] ?? $d));
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$branches = function_exists('br_all') ? br_all(true) : [];
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$pvLocale = $t && $t['locale'] !== '' ? $t['locale'] : locale();
$builtin = ot_builtin($keyPre, $layPre ?: 'service', $pvLocale);
layout_head(t('ob.texts_title'), 'admin_offer_texts');
?>
<?php settings_tabs('admin_offer_texts'); ?>
<h2 class="settings-h"><?= tt('ob.texts_title') ?></h2>
<p class="lede"><?= tt('ob.texts_lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('ob.need_db') ?></div><?php else: ?>
<div class="card">
  <div class="card-head"><h2><?= count($rows) ?> <?= tt('et.list') ?></h2><div class="btn-row"><?php foreach (OB_BLOCK_KEYS as $k): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_offer_texts', ['key' => $k]) ?>#form">+ <?= e(ob_block_label($k)) ?></a><?php endforeach; ?></div></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('ob.texts_none') ?></p><?php else: ?>
  <table class="tbl"><thead><tr><th><?= tt('ob.block') ?></th><th><?= tt('et.name') ?></th><th><?= tt('ob.layout') ?></th><th><?= tt('et.locale') ?></th><th><?= tt('et.branch') ?></th><th><?= tt('et.customer') ?></th><th><?= tt('th.status') ?></th><th><?= tt('et.updated') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= (int)$r['id'] === $eid ? ' class="is-on"' : '' ?>>
      <td><?= pill('gold', ob_block_label($r['block_key'])) ?></td>
      <td><a href="<?= url('admin_offer_texts', ['id' => $r['id']]) ?>#form"><b><?= e($r['name']) ?></b></a></td>
      <td><?= $r['layout'] !== '' ? e(ob_layout_label($r['layout'])) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['locale'] !== '' ? e(LOCALES[$r['locale']]['native'] ?? $r['locale']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['branch_code'] ? e($r['branch_code']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['customer_name'] ? e($r['customer_name']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['is_active'] ? pill('green', t('v.active')) : pill('mute', t('v.inactive')) ?></td>
      <td class="hint"><?= e(fmt_date($r['updated_at'])) ?><?= $r['updated_name'] ? ' · ' . e($r['updated_name']) : '' ?></td>
      <td style="text-align:right;white-space:nowrap;">
        <form method="post" action="<?= url('admin_offer_texts') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ot_duplicate"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('et.duplicate') ?>">⧉</button></form>
        <form method="post" action="<?= url('admin_offer_texts') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ot_delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt('et.delete_confirm') ?>">×</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= tt('ob.texts_resolve') ?></p>
</div>

<div class="cols">
  <div class="card" id="form">
    <h2><?= $t ? tt('et.edit') : tt('et.add') ?><?= $t ? ' — ' . e($t['name']) : '' ?></h2>
    <form method="post" action="<?= url('admin_offer_texts') ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="ot_save"><input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('ob.block') ?></label><select name="block_key" data-nosearch><?php foreach (OB_BLOCK_KEYS as $k): ?><option value="<?= $k ?>"<?= $sel($k, $keyPre) ?>><?= e(ob_block_label($k)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.name') ?></label><input name="name" maxlength="120" value="<?= $v('name') ?>" placeholder="<?= tt('ob.text_name_ph') ?>"></div>
      </div>
      <div class="grid grid-4">
        <div class="field"><label><?= tt('ob.layout') ?></label><select name="layout" data-nosearch><option value=""><?= tt('ob.any_layout') ?></option><?php foreach (OB_LAYOUTS as $l): ?><option value="<?= $l ?>"<?= $sel($l, $layPre) ?>><?= e(ob_layout_label($l)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.locale') ?></label><select name="locale" data-nosearch><option value=""><?= tt('et.any_lang') ?></option><?php foreach (LOCALES as $c => $L): ?><option value="<?= $c ?>"<?= $sel($c, $t['locale'] ?? '') ?>><?= e($L['native']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.branch') ?></label><select name="branch_id" data-nosearch><option value=""><?= tt('et.any_branch') ?></option><?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= $sel($b['id'], $t['branch_id'] ?? '') ?>><?= e($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.customer') ?></label><select name="customer_id"><option value=""><?= tt('et.any_customer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $t['customer_id'] ?? '') ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field"><label><?= tt('ob.block_title') ?></label><input name="title" maxlength="120" value="<?= $v('title', $t ? '' : $builtin['title']) ?>"></div>
      <div class="field"><label><?= tt('et.body') ?></label><textarea name="body" rows="10" required><?= $v('body', $t ? '' : $builtin['body']) ?></textarea></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1"<?= !$t || $t['is_active'] ? ' checked' : '' ?>> <?= tt('v.active') ?></label>
      <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($t): ?><a class="btn btn--ghost" href="<?= url('admin_offer_texts') ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
    <p class="hint" style="margin:10px 0 0;"><?= tt('et.placeholders') ?> <code><?= implode('</code> <code>', array_map(fn($k) => '{' . $k . '}', OB_PLACEHOLDERS)) ?></code></p>
  </div>
  <div class="card">
    <h2><?= tt('ob.builtin_of') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(ob_block_label($keyPre)) ?> · <?= e(ob_layout_label($layPre ?: 'service')) ?> · <?= e(LOCALES[$pvLocale]['native'] ?? $pvLocale) ?></span></h2>
    <p style="margin:0 0 6px;"><b><?= e($builtin['title']) ?></b></p><pre class="et-pre"><?= e($builtin['body'] ?: t('ob.block_empty')) ?></pre>
    <p class="hint" style="margin:10px 0 0;"><?= tt('ob.texts_order') ?></p>
  </div>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

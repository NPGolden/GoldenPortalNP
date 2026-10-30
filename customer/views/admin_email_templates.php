<?php
/** Settings › Lists & categories › E-mail templates — subject + text per document kind, per language / branch / customer, with preview and test draft.   GAS-CP-R32-001 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = et_has();
$rows = $ok ? et_all() : [];
$eid = (int)($_GET['id'] ?? 0); $t = $eid ? et_get($eid) : null;
$kindPre = in_array($_GET['kind'] ?? '', ET_KINDS, true) ? $_GET['kind'] : ($t['kind'] ?? 'offer');
$v = fn(string $k, $d = '') => e((string)($t[$k] ?? $d));
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$branches = function_exists('br_all') ? br_all(true) : [];
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$pvLocale = $t && $t['locale'] !== '' ? $t['locale'] : locale();
$pv = $ok ? et_render($kindPre, et_sample($pvLocale) + ['branch_id' => $t['branch_id'] ?? null, 'customer_id' => $t['customer_id'] ?? null]) : null;
$builtin = et_builtin($kindPre, $pvLocale);
layout_head(t('et.title'), 'admin_email_templates');
?>
<?php settings_tabs('admin_email_templates'); ?>
<h2 class="settings-h"><?= tt('et.title') ?></h2>
<p class="lede"><?= tt('et.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('et.need_db') ?></div><?php else: ?>

<div class="card">
  <div class="card-head"><h2><?= count($rows) ?> <?= tt('et.list') ?></h2><div class="btn-row"><?php foreach (ET_KINDS as $k): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_email_templates', ['kind' => $k]) ?>#form">+ <?= e(et_kind_label($k)) ?></a><?php endforeach; ?></div></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('et.none') ?></p><?php else: ?>
  <table class="tbl"><thead><tr><th><?= tt('et.kind') ?></th><th><?= tt('et.name') ?></th><th><?= tt('et.locale') ?></th><th><?= tt('et.branch') ?></th><th><?= tt('et.customer') ?></th><th><?= tt('et.subject') ?></th><th><?= tt('th.status') ?></th><th><?= tt('et.updated') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr<?= (int)$r['id'] === $eid ? ' class="is-on"' : '' ?>>
      <td><?= pill($r['kind'] === 'signature' ? 'navy' : 'gold', et_kind_label($r['kind'])) ?></td>
      <td><a href="<?= url('admin_email_templates', ['id' => $r['id']]) ?>#form"><b><?= e($r['name']) ?></b></a></td>
      <td><?= $r['locale'] !== '' ? e(LOCALES[$r['locale']]['native'] ?? $r['locale']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['branch_code'] ? e($r['branch_code']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td><?= $r['customer_name'] ? e($r['customer_name']) : '<span class="hint">' . tt('et.any') . '</span>' ?></td>
      <td class="hint"><?= e(mb_substr((string)$r['subject'], 0, 60)) ?></td>
      <td><?= $r['is_active'] ? pill('green', t('v.active')) : pill('mute', t('v.inactive')) ?></td>
      <td class="hint"><?= e(fmt_date($r['updated_at'])) ?><?= $r['updated_name'] ? ' · ' . e($r['updated_name']) : '' ?></td>
      <td style="text-align:right;white-space:nowrap;">
        <form method="post" action="<?= url('admin_email_templates') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="et_duplicate"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('et.duplicate') ?>">⧉</button></form>
        <form method="post" action="<?= url('admin_email_templates') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="et_delete"><input type="hidden" name="id" value="<?= $r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt('et.delete_confirm') ?>">×</button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= tt('et.resolve_hint') ?></p>
</div>

<div class="cols">
  <div class="card" id="form">
    <h2><?= $t ? tt('et.edit') : tt('et.add') ?><?= $t ? ' — ' . e($t['name']) : '' ?></h2>
    <form method="post" action="<?= url('admin_email_templates') ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="et_save"><input type="hidden" name="id" value="<?= (int)($t['id'] ?? 0) ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('et.kind') ?></label><select name="kind" id="et-kind"><?php foreach (ET_KINDS as $k): ?><option value="<?= $k ?>"<?= $sel($k, $kindPre) ?>><?= e(et_kind_label($k)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.name') ?></label><input name="name" maxlength="120" value="<?= $v('name') ?>" placeholder="<?= tt('et.name_ph') ?>"></div>
      </div>
      <div class="grid grid-3">
        <div class="field"><label><?= tt('et.locale') ?></label><select name="locale" data-nosearch><option value=""><?= tt('et.any_lang') ?></option><?php foreach (LOCALES as $c => $L): ?><option value="<?= $c ?>"<?= $sel($c, $t['locale'] ?? '') ?>><?= e($L['native']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.branch') ?></label><select name="branch_id" data-nosearch><option value=""><?= tt('et.any_branch') ?></option><?php foreach ($branches as $b): ?><option value="<?= $b['id'] ?>"<?= $sel($b['id'], $t['branch_id'] ?? '') ?>><?= e($b['code'] . ' — ' . $b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('et.customer') ?></label><select name="customer_id"><option value=""><?= tt('et.any_customer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $t['customer_id'] ?? '') ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="field" id="et-subject-f"<?= $kindPre === 'signature' ? ' hidden' : '' ?>><label><?= tt('et.subject') ?></label><input name="subject" maxlength="255" value="<?= $v('subject', $t ? '' : $builtin['subject']) ?>"></div>
      <div class="field"><label><?= tt('et.body') ?></label><textarea name="body" rows="12" required data-plain><?= $v('body', $t ? '' : $builtin['body']) ?></textarea></div>
      <label class="chk"><input type="checkbox" name="is_active" value="1"<?= !$t || $t['is_active'] ? ' checked' : '' ?>> <?= tt('v.active') ?></label>
      <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($t): ?><a class="btn btn--ghost" href="<?= url('admin_email_templates') ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
    <p class="hint" style="margin:10px 0 0;"><?= tt('et.placeholders') ?> <code><?= implode('</code> <code>', array_map(fn($k) => '{' . $k . '}', ET_PLACEHOLDERS)) ?></code></p>
    <p class="hint" style="margin:4px 0 0;"><?= tt('et.placeholders_hint') ?></p>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= tt('et.preview') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(et_kind_label($kindPre)) ?> · <?= e(LOCALES[$pvLocale]['native'] ?? $pvLocale) ?></span></h2>
      <form method="post" action="<?= url('admin_email_templates') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="et_test"><input type="hidden" name="kind" value="<?= e($kindPre) ?>"><input type="hidden" name="locale" value="<?= e($pvLocale) ?>"><input type="hidden" name="branch_id" value="<?= (int)($t['branch_id'] ?? 0) ?>"><input type="hidden" name="customer_id" value="<?= (int)($t['customer_id'] ?? 0) ?>"><button class="btn btn--ghost btn--sm" type="submit">⤓ <?= tt('et.test') ?></button></form></div>
    <?php if ($pv): ?>
      <?php if ($kindPre !== 'signature'): ?><p class="hint" style="margin:0 0 4px;"><?= tt('et.subject') ?></p><p style="margin:0 0 12px;"><b><?= e($pv['subject']) ?></b></p><?php endif; ?>
      <p class="hint" style="margin:0 0 4px;"><?= tt('et.body') ?></p><pre class="et-pre"><?= e($pv['body']) ?></pre>
      <p class="hint" style="margin:8px 0 0;"><?= $pv['template_id'] ? e(t('et.pv_template', ['n' => (string) (array_values(array_filter($rows, fn($r) => (int)$r['id'] === $pv['template_id']))[0]['name'] ?? $pv['template_id'])])) : tt('et.pv_builtin') ?></p>
    <?php endif; ?>
    <details style="margin-top:12px;"><summary class="hint"><?= tt('et.builtin_show') ?></summary>
      <?php if ($kindPre !== 'signature'): ?><p style="margin:8px 0 4px;"><b><?= e($builtin['subject']) ?></b></p><?php endif; ?><pre class="et-pre"><?= e($builtin['body']) ?></pre></details>
  </div>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){ var k=document.getElementById('et-kind'), f=document.getElementById('et-subject-f'); if(!k||!f) return; k.addEventListener('change',function(){ f.hidden = k.value==='signature'; }); })();
</script>
<?php endif; ?>
<?php layout_foot(); ?>

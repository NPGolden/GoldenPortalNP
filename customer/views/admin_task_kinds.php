<?php
/** Settings › Task types — built-in types (order, colour, hide) and the administrators' own.   GAS-CP-R22-006 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = td_has() && td_kind_table();
$defs = $ok ? td_kind_defs() : [];
$inUse = []; if ($ok) foreach (all('SELECT kind, COUNT(*) n FROM tasks GROUP BY kind') as $r) $inUse[$r['kind']] = (int)$r['n'];
$tones = ['gold' => t('ofs.tone_gold'), 'green' => t('ofs.tone_green'), 'blue' => t('ofs.tone_blue'), 'navy' => t('ofs.tone_navy'), 'mute' => t('ofs.tone_mute'), 'bad' => t('ofs.tone_bad')];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('tdk.title'), 'admin_task_kinds');
?>
<?php settings_tabs('admin_task_kinds'); ?>
<h2 class="settings-h"><?= tt('tdk.title') ?></h2>
<p class="lede"><?= tt('tdk.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('tdk.need_db') ?></div><?php else: ?>
<div class="card">
  <h2><?= tt('tdk.add') ?></h2>
  <form method="post" action="<?= url('admin_task_kinds') ?>" class="fltbar" style="grid-template-columns: 2fr 1.2fr 90px auto;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="tdk_save"><input type="hidden" name="is_active" value="1">
    <div class="field"><label><?= tt('ofs.label') ?></label><input name="label" required maxlength="80" placeholder="<?= tt('tdk.label_ph') ?>"></div>
    <div class="field"><label><?= tt('ofs.tone') ?></label><select name="tone"><?php foreach ($tones as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" value="120" min="0"></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<div class="card">
  <h2><?= count($defs) ?> <?= tt('tdk.list') ?></h2>
  <div class="table-scroll"><table class="sys-table">
    <thead><tr><th><?= tt('sys.order') ?></th><th><?= tt('td.kind') ?></th><th><?= tt('ofs.label') ?></th><th><?= tt('ofs.tone') ?></th><th class="num"><?= tt('td.title') ?></th><th><?= tt('au.active') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($defs as $k => $d): $f = 'tdk' . md5($k); ?>
      <tr<?= $d['is_active'] ? '' : ' class="is-retired"' ?>>
        <td><input form="<?= $f ?>" name="sort_order" type="number" min="0" value="<?= (int)$d['sort_order'] ?>" style="width:74px;"></td>
        <td><?= td_kind_pill($k) ?><?= $d['is_builtin'] ? '<br><span class="hint">' . tt('ofs.builtin') . '</span>' : '' ?></td>
        <td><?php if ($d['is_builtin']): ?><?= e($d['label']) ?><?php else: ?><input form="<?= $f ?>" name="label" maxlength="80" value="<?= e($d['label']) ?>" required><?php endif; ?></td>
        <td><select form="<?= $f ?>" name="tone"><?php foreach ($tones as $tk => $tl): ?><option value="<?= $tk ?>"<?= $sel($tk, $d['tone']) ?>><?= e($tl) ?></option><?php endforeach; ?></select></td>
        <td class="num"><?= (int)($inUse[$k] ?? 0) ?></td>
        <td><?php if ($k === 'general'): ?><?= pill('green', t('au.active')) ?><?php else: ?><label class="chk"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1"<?= $d['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?></td>
        <td class="row-actions"><button form="<?= $f ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <?php if (!$d['is_builtin'] && empty($inUse[$k])): ?><form method="post" action="<?= url('admin_task_kinds') ?>" style="display:inline;" data-confirm="<?= e(t('tdk.delete_confirm', ['name' => $d['label']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="tdk_delete"><input type="hidden" name="skey" value="<?= e($k) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php foreach ($defs as $k => $d): ?><form method="post" action="<?= url('admin_task_kinds') ?>" id="tdk<?= md5($k) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="tdk_save"><input type="hidden" name="skey" value="<?= e($k) ?>"></form><?php endforeach; ?>
  <p class="hint" style="margin-top:10px;"><?= tt('tdk.list_hint') ?></p>
</div>
<style>.sys-table td input, .sys-table td select { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); }</style>
<?php endif; ?>
<?php layout_foot(); ?>

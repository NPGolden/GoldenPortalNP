<?php
/** Settings › File categories — built-in categories (order, hide) and the administrators' own (document code, archive folder).   GAS-CP-R22-007 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = fk_table();
$defs = $ok ? fk_defs() : [];
$inUse = []; if ($ok) foreach (all('SELECT kind, COUNT(*) n FROM sp_files GROUP BY kind') as $r) $inUse[$r['kind']] = (int)$r['n'];
$tabsA = array_combine(FK_TABS, array_map(fn($x) => t('fkc.tab_' . str_replace('-', '_', $x)), FK_TABS));
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('fkc.title'), 'admin_file_kinds');
?>
<?php settings_tabs('admin_file_kinds'); ?>
<h2 class="settings-h"><?= tt('fkc.title') ?></h2>
<p class="lede"><?= tt('fkc.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('fkc.need_db') ?></div><?php else: ?>
<div class="card">
  <h2><?= tt('fkc.add') ?></h2>
  <form method="post" action="<?= url('admin_file_kinds') ?>" class="fltbar" style="grid-template-columns: 2fr .8fr 1.4fr 90px auto;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="fkc_save"><input type="hidden" name="is_active" value="1">
    <div class="field"><label><?= tt('ofs.label') ?></label><input name="label" required maxlength="80" placeholder="<?= tt('fkc.label_ph') ?>"></div>
    <div class="field"><label><?= tt('fkc.doc_code') ?></label><input name="doc_code" maxlength="8" placeholder="FILE" style="text-transform:uppercase;"></div>
    <div class="field"><label><?= tt('fkc.archive_tab') ?></label><select name="archive_tab"><?php foreach ($tabsA as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" value="120" min="0"></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<div class="card">
  <h2><?= count($defs) ?> <?= tt('fkc.list') ?></h2>
  <div class="table-scroll"><table class="sys-table">
    <thead><tr><th><?= tt('sys.order') ?></th><th><?= tt('td.kind') ?></th><th><?= tt('ofs.label') ?></th><th><?= tt('fkc.doc_code') ?></th><th><?= tt('fkc.archive_tab') ?></th><th class="num"><?= tt('tab.files') ?></th><th><?= tt('au.active') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($defs as $k => $d): $f = 'fkc' . md5($k); ?>
      <tr<?= $d['is_active'] ? '' : ' class="is-retired"' ?>>
        <td><input form="<?= $f ?>" name="sort_order" type="number" min="0" value="<?= (int)$d['sort_order'] ?>" style="width:74px;"></td>
        <td><?= pill($d['is_system'] ? 'mute' : 'navy', $d['label']) ?><?= $d['is_builtin'] ? '<br><span class="hint">' . ($d['is_system'] ? tt('fkc.system') : tt('ofs.builtin')) . '</span>' : '' ?></td>
        <td><?php if ($d['is_builtin']): ?><?= e($d['label']) ?><?php else: ?><input form="<?= $f ?>" name="label" maxlength="80" value="<?= e($d['label']) ?>" required><?php endif; ?></td>
        <td><?php if ($d['is_builtin']): ?><span class="mono"><?= e($d['doc_code']) ?></span><?php else: ?><input form="<?= $f ?>" name="doc_code" maxlength="8" value="<?= e($d['doc_code']) ?>" style="text-transform:uppercase;"><?php endif; ?></td>
        <td><?php if ($d['is_builtin']): ?><?= e($tabsA[$d['archive_tab']] ?? $d['archive_tab']) ?><?php else: ?><select form="<?= $f ?>" name="archive_tab"><?php foreach ($tabsA as $tk => $tl): ?><option value="<?= $tk ?>"<?= $sel($tk, $d['archive_tab']) ?>><?= e($tl) ?></option><?php endforeach; ?></select><?php endif; ?></td>
        <td class="num"><?= (int)($inUse[$k] ?? 0) ?></td>
        <td><?php if ($k === 'other' || $d['is_system']): ?><?= pill('green', t('au.active')) ?><?php else: ?><label class="chk"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1"<?= $d['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?></td>
        <td class="row-actions"><button form="<?= $f ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <?php if (!$d['is_builtin'] && empty($inUse[$k])): ?><form method="post" action="<?= url('admin_file_kinds') ?>" style="display:inline;" data-confirm="<?= e(t('fkc.delete_confirm', ['name' => $d['label']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fkc_delete"><input type="hidden" name="skey" value="<?= e($k) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php foreach ($defs as $k => $d): ?><form method="post" action="<?= url('admin_file_kinds') ?>" id="fkc<?= md5($k) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="fkc_save"><input type="hidden" name="skey" value="<?= e($k) ?>"></form><?php endforeach; ?>
  <p class="hint" style="margin-top:10px;"><?= tt('fkc.list_hint') ?></p>
</div>
<style>.sys-table td input, .sys-table td select { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); }</style>
<?php endif; ?>
<?php layout_foot(); ?>

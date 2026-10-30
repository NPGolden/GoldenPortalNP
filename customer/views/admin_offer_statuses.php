<?php
/** Settings › Offer statuses — the seven built-in statuses (order, colour, hide) and the administrators' own.   GAS-CP-R17-008 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = of_has() && of_status_table();
$defs = $ok ? of_status_defs() : [];
$inUse = []; if ($ok) foreach (all('SELECT status, COUNT(*) n FROM offers GROUP BY status') as $r) $inUse[$r['status']] = (int)$r['n'];
$tones = ['gold' => t('ofs.tone_gold'), 'green' => t('ofs.tone_green'), 'blue' => t('ofs.tone_blue'), 'navy' => t('ofs.tone_navy'), 'mute' => t('ofs.tone_mute'), 'bad' => t('ofs.tone_bad')];
$behaves = ['open' => t('ofs.b_open'), 'approved' => t('ofs.b_approved'), 'closed_won' => t('ofs.b_closed_won'), 'closed_lost' => t('ofs.b_closed_lost')];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('ofs.title'), 'admin_offer_statuses');
?>
<?php settings_tabs('admin_offer_statuses'); ?>
<h2 class="settings-h"><?= tt('ofs.title') ?></h2>
<p class="lede"><?= tt('ofs.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('ofs.need_db') ?></div><?php else: ?>
<div class="card">
  <h2><?= tt('ofs.add') ?></h2>
  <form method="post" action="<?= url('admin_offer_statuses') ?>" class="fltbar" style="grid-template-columns: 2fr 1.2fr 1.6fr 90px auto;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="ofs_save"><input type="hidden" name="is_active" value="1">
    <div class="field"><label><?= tt('ofs.label') ?></label><input name="label" required maxlength="80" placeholder="<?= tt('ofs.label_ph') ?>"></div>
    <div class="field"><label><?= tt('ofs.tone') ?></label><select name="tone"><?php foreach ($tones as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ofs.behaves') ?></label><select name="behaves"><?php foreach ($behaves as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" value="55" min="0"></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <p class="hint" style="margin:8px 0 0;"><?= tt('ofs.behaves_hint') ?></p>
</div>
<div class="card">
  <h2><?= count($defs) ?> <?= tt('ofs.list') ?></h2>
  <div class="table-scroll"><table class="sys-table">
    <thead><tr><th><?= tt('sys.order') ?></th><th><?= tt('th.status') ?></th><th><?= tt('ofs.label') ?></th><th><?= tt('ofs.tone') ?></th><th><?= tt('ofs.behaves') ?></th><th class="num"><?= tt('of.title') ?></th><th><?= tt('au.active') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($defs as $k => $d): $f = 'ofs' . md5($k); ?>
      <tr<?= $d['is_active'] ? '' : ' class="is-retired"' ?>>
        <td><input form="<?= $f ?>" name="sort_order" type="number" min="0" value="<?= (int)$d['sort_order'] ?>" style="width:74px;"></td>
        <td><?= of_status_pill($k) ?><?= $d['is_builtin'] ? '<br><span class="hint">' . tt('ofs.builtin') . '</span>' : '' ?></td>
        <td><?php if ($d['is_builtin']): ?><?= e($d['label']) ?><?php else: ?><input form="<?= $f ?>" name="label" maxlength="80" value="<?= e($d['label']) ?>" required><?php endif; ?></td>
        <td><select form="<?= $f ?>" name="tone"><?php foreach ($tones as $tk => $tl): ?><option value="<?= $tk ?>"<?= $sel($tk, $d['tone']) ?>><?= e($tl) ?></option><?php endforeach; ?></select></td>
        <td><?php if ($d['is_builtin']): ?><?= e($behaves[$d['behaves']]) ?><?php else: ?><select form="<?= $f ?>" name="behaves"><?php foreach ($behaves as $bk => $bl): ?><option value="<?= $bk ?>"<?= $sel($bk, $d['behaves']) ?>><?= e($bl) ?></option><?php endforeach; ?></select><?php endif; ?></td>
        <td class="num"><?= (int)($inUse[$k] ?? 0) ?></td>
        <td><?php if ($k === 'sent'): ?><?= pill('green', t('au.active')) ?><?php else: ?><label class="chk"><input form="<?= $f ?>" type="checkbox" name="is_active" value="1"<?= $d['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?></td>
        <td class="row-actions"><button form="<?= $f ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <?php if (!$d['is_builtin'] && empty($inUse[$k])): ?><form method="post" action="<?= url('admin_offer_statuses') ?>" style="display:inline;" data-confirm="<?= e(t('ofs.delete_confirm', ['name' => $d['label']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ofs_delete"><input type="hidden" name="skey" value="<?= e($k) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php foreach ($defs as $k => $d): ?><form method="post" action="<?= url('admin_offer_statuses') ?>" id="ofs<?= md5($k) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ofs_save"><input type="hidden" name="skey" value="<?= e($k) ?>"></form><?php endforeach; ?>
  <p class="hint" style="margin-top:10px;"><?= tt('ofs.list_hint') ?></p>
</div>
<style>.sys-table td input, .sys-table td select { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); }</style>
<?php endif; ?>
<?php layout_foot(); ?>

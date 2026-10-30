<?php
/**
 * Settings › Disciplines — each discipline and the manager who approves
 * its engineers' timesheets; staff members are assigned a discipline (and
 * optionally a personal manager) on the Portal accounts tab.     GAS-CP-R11-001
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = ts_has();
$rows = $ok ? all('SELECT d.*, m.full_name AS manager_name,
                          (SELECT COUNT(*) FROM customer_users cu WHERE cu.discipline_id = d.id AND cu.is_active = 1) AS n_people
                   FROM disciplines d LEFT JOIN customer_users m ON m.id = d.manager_user_id ORDER BY d.is_active DESC, d.sort_order, d.name') : [];
$people = staff_users();
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('disc.title'), 'admin_disciplines');
?>
<?php settings_tabs('admin_disciplines'); ?>
<h2 class="settings-h"><?= tt('disc.title') ?></h2>
<p class="lede"><?= tt('disc.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('ts.need_r11') ?></div><?php endif; ?>

<div class="card">
  <h2><?= tt('disc.add') ?></h2>
  <form method="post" action="<?= url('admin_disciplines') ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="disc_save">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('disc.name') ?></label><input name="name" required maxlength="80" placeholder="Electrical"></div>
      <div class="field"><label><?= tt('disc.manager') ?></label>
        <select name="manager_user_id"><option value=""><?= tt('disc.no_manager') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"><?= e($pp['full_name'] . ($pp['role_name'] ? ' · ' . $pp['role_name'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="display:flex;gap:10px;align-items:flex-end;"><div style="flex:1;"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" value="<?= count($rows) + 1 ?>"></div><button class="btn" type="submit"<?= $ok ? '' : ' disabled' ?>><?= tt('btn.save') ?></button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('disc.count', ['n' => count($rows)])) ?></h2>
  <div class="table-scroll"><table class="sys-table">
    <thead><tr><th><?= tt('sys.order') ?></th><th><?= tt('disc.name') ?></th><th><?= tt('disc.manager') ?></th><th><?= tt('disc.people') ?></th><?php if ($rates = function_exists('wc_has') && wc_has()): ?><th class="num"><?= tt('wc.rate_normal') ?></th><th class="num"><?= tt('wc.rate_overtime') ?></th><th class="num"><?= tt('wc.rate_holiday') ?></th><?php endif; ?><?php if ($accCards = (function_exists('acc_has') && acc_has() && function_exists('rl_current') && ($rlc = rl_current()) ? rl_cards((int)$rlc['id']) : [])): ?><th><?= tt('rl.card') ?></th><?php endif; ?><th><?= tt('th.status') ?></th><th></th></tr></thead>
    <?php $engCol = function_exists('eng_has') && eng_has(); ?>
    <tbody><?php foreach ($rows as $d): $fid = 'disc' . (int)$d['id']; ?>
      <tr<?= $d['is_active'] ? '' : ' class="is-retired"' ?>>
        <td class="num"><input form="<?= $fid ?>" name="sort_order" type="number" min="0" value="<?= (int)$d['sort_order'] ?>" style="width:74px;"></td>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($d['name']) ?>" maxlength="80" required></td>
        <td><select form="<?= $fid ?>" name="manager_user_id"><option value=""><?= tt('disc.no_manager') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= $sel($pp['id'], $d['manager_user_id']) ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select>
          <?php if (!$d['manager_user_id']): ?><br><span class="hint is-late"><?= tt('disc.no_manager_warn') ?></span><?php endif; ?></td>
        <td class="hint"><?= (int)$d['n_people'] ?><?php if ($engCol): $esec = eng_section_of((int)$d['id']); ?><br><a class="hint" href="<?= url('admin_engineering') ?>" title="<?= tt('eng.map_hint') ?>">📐 <?= $esec ? e(eng_sections()[$esec]['name']) : '—' ?></a><?php endif; ?></td>
        <?php if ($rates): foreach (['rate_normal', 'rate_overtime', 'rate_holiday'] as $rk): ?><td class="num"><input form="<?= $fid ?>" name="<?= $rk ?>" type="number" step="0.01" min="0" value="<?= $d[$rk] !== null ? e((string)$d[$rk]) : '' ?>" placeholder="€/h" style="width:90px;"></td><?php endforeach; endif; ?>
        <?php if ($accCards): ?><td><select form="<?= $fid ?>" name="rate_card" data-nosearch title="<?= tt('acc.card_hint') ?>"><option value=""><?= e(t('acc.card_auto', ['c' => acc_card_of((int)$d['id'])])) ?></option><?php foreach ($accCards as $ck => $cc): if ($cc['section'] !== 'hourly') continue; ?><option value="<?= e($cc['card']) ?>"<?= (string)($d['rate_card'] ?? '') === (string)$cc['card'] ? ' selected' : '' ?>><?= e($cc['card'] . ' · ' . $cc['name']) ?></option><?php endforeach; ?></select></td><?php endif; ?>
        <td><?= $d['is_active'] ? pill('green', t('sys.active')) : pill('mute', t('sys.retired')) ?></td>
        <td class="row-actions">
          <form method="post" action="<?= url('admin_disciplines') ?>" id="<?= $fid ?>" style="display:contents;"><?= csrf_field() ?><input type="hidden" name="action" value="disc_save"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"></form>
          <button form="<?= $fid ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <button form="<?= $fid ?>" class="btn btn--ghost btn--sm" type="submit" name="toggle" value="1"><?= $d['is_active'] ? tt('sys.retire') : tt('sys.restore') ?></button>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($rates): ?><p class="hint" style="margin-top:10px;"><?= tt('wc.rates_hint') ?></p><?php endif; ?>
  <p class="hint" style="margin-top:12px;"><?= tt('disc.assign_hint') ?> <a href="<?= url('admin_users') ?>"><?= tt('nav.users') ?> →</a></p>
</div>
<style>.sys-table td input, .sys-table td select { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); } .sys-table tr.is-retired td { opacity:.62; }</style>
<?php if (function_exists('pl_has') && pl_has()): $plSel = pl_disciplines(); $allDisc = all('SELECT id, name, is_active, (SELECT COUNT(*) FROM customer_users cu WHERE cu.discipline_id = disciplines.id AND cu.is_active = 1) AS n FROM disciplines ORDER BY sort_order, name'); $nNone = (int) scalar("SELECT COUNT(*) FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 AND discipline_id IS NULL"); ?>
<div class="card" id="plan-discs">
  <h2><?= tt('pl.discs_title') ?></h2>
  <p class="hint"><?= tt('pl.discs_hint') ?></p>
  <form method="post" action="<?= url('admin_disciplines') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pl_discs_save">
    <div class="tm-grid">
      <?php foreach ($allDisc as $d): ?><label class="chk"><input type="checkbox" name="pl_disc[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $plSel, true) ? ' checked' : '' ?>> <?= e($d['name']) ?> <span class="hint">· <?= (int)$d['n'] ?></span><?= $d['is_active'] ? '' : ' ' . pill('mute', t('v.inactive')) ?></label><?php endforeach; ?>
      <label class="chk"><input type="checkbox" name="pl_disc[]" value="0"<?= in_array(0, $plSel, true) ? ' checked' : '' ?>> <?= tt('tm.no_discipline') ?> <span class="hint">· <?= $nNone ?></span></label>
    </div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= $plSel ? e(t('pl.discs_n', ['n' => count($plSel)])) : tt('pl.discs_all') ?></span></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

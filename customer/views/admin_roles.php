<?php
/**
 * Admin console — Golden A/S roles (CEO, Project Manager, …).   GAS-CP-R5-001
 * The job roles staff accounts carry; the Project Manager list on project
 * forms is built from them. Admins add, rename, reorder or retire roles.
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$roles = all('SELECT r.*, (SELECT COUNT(*) FROM customer_users cu WHERE cu.staff_role_id = r.id) AS n_users
              FROM staff_roles r ORDER BY r.is_active DESC, r.sort_order, r.name');
layout_head(t('sr.title'), 'admin_roles');
?>
<?php settings_tabs('admin_roles'); ?>
<h2 class="settings-h"><?= tt('sr.title') ?></h2>
<p class="lede"><?= tt('sr.lede') ?></p>

<div class="card">
  <h2><?= tt('sr.add') ?></h2>
  <form method="post" action="<?= url('admin_roles') ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="sr_save">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('sr.name') ?></label><input name="name" required maxlength="80" placeholder="e.g. Commissioning Engineer"></div>
      <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" value="<?= count($roles) + 1 ?>"></div>
      <div class="field"><label><?= tt('fin.role_label') ?></label><label class="chk" style="margin:8px 0 0;"><input type="checkbox" name="see_financials" value="1" checked> <?= tt('fin.role_yes') ?></label><label class="chk" style="margin:4px 0 0;"><input type="checkbox" name="can_offer" value="1" checked> <?= tt('ob.role_yes') ?></label></div>
      <div class="field" style="display:flex;align-items:flex-end;"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('sr.count', ['n' => count($roles)])) ?></h2>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('sys.order') ?></th><th><?= tt('sr.name') ?></th><th><?= tt('sr.used') ?></th><th><?= tt('fin.role_label') ?></th><?php if ($roles && array_key_exists('scope', $roles[0])): ?><th><?= tt('hr.scope') ?></th><?php endif; ?><th><?= tt('th.status') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($roles as $r): ?>
      <tr>
        <form method="post" action="<?= url('admin_roles') ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="sr_save"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <td class="num"><input name="sort_order" type="number" min="0" value="<?= (int)$r['sort_order'] ?>" style="width:70px;"></td>
          <td><input name="name" value="<?= e($r['name']) ?>" maxlength="80" required></td>
          <td class="hint"><?= e(t('sr.usage', ['n' => (int)$r['n_users']])) ?></td>
          <td><label class="chk" style="margin:0;"><input type="checkbox" name="see_financials" value="1"<?= !isset($r['see_financials']) || (int)$r['see_financials'] ? ' checked' : '' ?>> <?= tt('fin.role_yes') ?></label><label class="chk" style="margin:4px 0 0;"><input type="checkbox" name="can_offer" value="1"<?= !isset($r['can_offer']) || (int)$r['can_offer'] ? ' checked' : '' ?>> <?= tt('ob.role_yes') ?></label></td>
          <?php if (array_key_exists('scope', $r)): ?><td><select name="scope" data-nosearch title="<?= tt('hr.scope_hint') ?>"><option value="full"<?= ($r['scope'] ?? 'full') === 'full' ? ' selected' : '' ?>><?= tt('hr.scope_full') ?></option><option value="hr"<?= ($r['scope'] ?? '') === 'hr' ? ' selected' : '' ?>><?= tt('hr.scope_hr') ?></option><option value="employee"<?= ($r['scope'] ?? '') === 'employee' ? ' selected' : '' ?>><?= tt('hr.scope_employee') ?></option><option value="owner"<?= ($r['scope'] ?? '') === 'owner' ? ' selected' : '' ?>><?= tt('hr.scope_owner') ?></option></select></td><?php endif; ?>
          <td><?= $r['is_active'] ? pill('green', t('sys.active')) : pill('mute', t('sys.retired')) ?></td>
          <td class="row-actions">
            <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
            <button class="btn btn--ghost btn--sm" type="submit" name="toggle" value="1"><?= $r['is_active'] ? tt('sys.retire') : tt('sys.restore') ?></button>
          </td>
        </form>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php layout_foot(); ?>

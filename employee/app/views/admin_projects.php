<?php
require __DIR__ . '/_layout.php';
require_role('system_admin');

$pms = all('SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.code = "project_manager" AND u.is_active = 1 ORDER BY u.full_name');
$tms = all('SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id
            WHERE r.code = "technical_manager" AND u.is_active = 1 ORDER BY u.full_name');
$vessels  = all('SELECT id, name FROM vessels WHERE is_active = 1 ORDER BY name');
$projects = all('SELECT p.*, pm.full_name AS pm, tm.full_name AS tm, v.name AS vessel
                 FROM projects p JOIN users pm ON pm.id = p.pm_user_id
                 JOIN users tm ON tm.id = p.tm_user_id
                 LEFT JOIN vessels v ON v.id = p.vessel_id
                 ORDER BY p.project_number');

layout_head(t('ap.title'), 'admin_projects');
?>
<p class="eyebrow"><?= tt('nav.admin') ?></p>
<h1><?= tt('ap.title') ?></h1>
<p class="lede"><?= tt('ap.lede') ?></p>

<?php if (!$pms || !$tms): ?>
  <div class="flash flash--warn"><?= tt('ap.needroles') ?></div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('ap.add') ?></h2>
  <form method="post" action="<?= url('admin_projects') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="project_save">
    <input type="hidden" name="_return" value="admin_projects">
    <div class="grid grid-3">
      <div class="field">
        <label for="project_number"><?= tt('ap.number') ?></label>
        <input id="project_number" name="project_number" type="text" required maxlength="40" placeholder="<?= tt('ap.number_ph') ?>">
        <p class="hint"><?= tt('ap.number_hint') ?></p>
      </div>
      <div class="field">
        <label for="name"><?= tt('ap.name') ?></label>
        <input id="name" name="name" type="text" required maxlength="160">
      </div>
      <div class="field">
        <label for="vessel_id"><?= tt('ap.vessel') ?></label>
        <select id="vessel_id" name="vessel_id">
          <option value=""><?= tt('ap.vessel_none') ?></option>
          <?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="pm_user_id"><?= tt('ap.pm') ?></label>
        <select id="pm_user_id" name="pm_user_id" required>
          <?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['full_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="tm_user_id"><?= tt('ap.tm') ?></label>
        <select id="tm_user_id" name="tm_user_id" required>
          <?php foreach ($tms as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['full_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button class="btn btn--gold" type="submit"><?= tt('ap.save') ?></button>
  </form>
</div>

<div class="card">
  <h2><?= tt('ap.onbooks') ?></h2>
  <?php if (!$projects): ?>
    <div class="empty"><p><?= tt('ap.empty') ?></p></div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= tt('th.number') ?></th><th><?= tt('th.name') ?></th><th><?= tt('th.vessel') ?></th>
                 <th><?= tt('th.pm') ?></th><th><?= tt('th.tm') ?></th></tr></thead>
      <tbody>
      <?php foreach ($projects as $p): ?>
        <tr>
          <td class="ref"><?= e($p['project_number']) ?></td>
          <td><?= e($p['name']) ?></td>
          <td><?= e($p['vessel'] ?: '—') ?></td>
          <td><?= e($p['pm']) ?></td>
          <td><?= e($p['tm']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

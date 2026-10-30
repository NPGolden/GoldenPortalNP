<?php
require __DIR__ . '/_layout.php';
require_role('system_admin');

$roles = all('SELECT id, code FROM roles ORDER BY id');
$edit  = isset($_GET['edit']) ? one('SELECT * FROM users WHERE id = ?', [(int)$_GET['edit']]) : null;
$users = all('SELECT u.*, r.code AS role_code FROM users u JOIN roles r ON r.id = u.role_id
              ORDER BY r.id, u.full_name');
$defLoc = setting('default_locale', DEFAULT_LOCALE);

layout_head(t('au.title'), 'admin_users');
?>
<p class="eyebrow"><?= tt('nav.admin') ?></p>
<h1><?= tt('au.title') ?></h1>
<p class="lede"><?= tt('au.lede') ?></p>

<div class="card">
  <h2><?= $edit ? e(t('au.edit', ['name' => $edit['full_name']])) : tt('au.add') ?></h2>
  <form method="post" action="<?= url('admin_users') ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="user_save">
    <input type="hidden" name="_return" value="admin_users">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>

    <div class="grid grid-3">
      <div class="field">
        <label for="full_name"><?= tt('au.fullname') ?></label>
        <input id="full_name" name="full_name" type="text" required value="<?= e($edit['full_name'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="email"><?= tt('au.email') ?></label>
        <input id="email" name="email" type="email" required value="<?= e($edit['email'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="phone"><?= tt('au.phone') ?></label>
        <input id="phone" name="phone" type="text" value="<?= e($edit['phone'] ?? '') ?>">
      </div>
      <div class="field">
        <label for="role_id"><?= tt('au.role') ?></label>
        <select id="role_id" name="role_id" required>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int)$r['id'] ?>"<?= ($edit['role_id'] ?? 0) == $r['id'] ? ' selected' : '' ?>>
              <?= e(role_label($r['code'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="locale"><?= tt('au.language') ?></label>
        <select id="locale" name="locale">
          <?php foreach (LOCALES as $code => $meta): $sel = ($edit['locale'] ?? $defLoc) === $code; ?>
            <option value="<?= e($code) ?>"<?= $sel ? ' selected' : '' ?>><?= e($meta['native']) ?></option>
          <?php endforeach; ?>
        </select>
        <p class="hint"><?= tt('notif.lede') ?></p>
      </div>
      <div class="field">
        <label for="password"><?= $edit ? tt('au.password_edit') : tt('au.password') ?></label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="10"
               <?= $edit ? '' : 'required' ?>>
        <p class="hint"><?= tt('au.pass_hint') ?></p>
      </div>
    </div>

    <div class="actions">
      <button class="btn btn--gold" type="submit"><?= $edit ? tt('au.savechg') : tt('au.create') ?></button>
      <?php if ($edit): ?><a class="btn btn--ghost" href="<?= url('admin_users') ?>"><?= tt('btn.cancel') ?></a><?php endif; ?>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= tt('au.people') ?></h2>
  <div class="table-scroll">
  <table>
    <thead><tr><th><?= tt('th.name') ?></th><th><?= tt('th.email') ?></th><th><?= tt('th.role') ?></th>
               <th><?= tt('nav.language') ?></th><th><?= tt('th.twofa') ?></th>
               <th><?= tt('th.last_signin') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $x): ?>
      <tr>
        <td><strong><?= e($x['full_name']) ?></strong></td>
        <td><?= e($x['email']) ?></td>
        <td><?= e(role_label($x['role_code'])) ?></td>
        <td class="num"><?= e(strtoupper($x['locale'] ?? 'en')) ?></td>
        <td><?= $x['totp_enabled']
              ? '<span class="pill pill--good">' . tt('au.enrolled') . '</span>'
              : '<span class="pill pill--warn">' . tt('au.pending') . '</span>' ?></td>
        <td class="date"><?= e(fmt_datetime($x['last_login_at'])) ?></td>
        <td><?= $x['is_active']
              ? '<span class="pill pill--good">' . tt('au.active') . '</span>'
              : '<span class="pill pill--bad">' . tt('au.disabled') . '</span>' ?></td>
        <td class="row-actions">
          <a class="btn btn--ghost btn--sm" href="<?= url('admin_users', ['edit' => $x['id']]) ?>"><?= tt('btn.edit') ?></a>
          <form method="post" action="<?= url('admin_users') ?>" style="display:inline;"
                data-confirm="<?= e(t('au.confirm_reset', ['name' => $x['full_name']])) ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="user_reset_2fa">
            <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
            <button class="btn btn--ghost btn--sm" type="submit"><?= tt('au.reset2fa') ?></button>
          </form>
          <?php if ((int)$x['id'] !== (int)current_user()['id']): ?>
            <form method="post" action="<?= url('admin_users') ?>" style="display:inline;"
                  data-confirm="<?= e(t('au.confirm_toggle', ['name' => $x['full_name']])) ?>">
              <?= csrf_field() ?><input type="hidden" name="action" value="user_toggle">
              <input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
              <button class="btn btn--ghost btn--sm" type="submit"><?= $x['is_active'] ? tt('au.disable') : tt('au.enable') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php layout_foot(); ?>

<?php
require __DIR__ . '/_layout.php';
require_role('system_admin');

$recent = all('SELECT s.*, o.order_ref FROM sharepoint_sync s
               LEFT JOIN orders o ON o.id = s.order_id ORDER BY s.id DESC LIMIT 12');
$fileOverride = (string) cfg('sharepoint.client_id', '') !== '';
$example = setting('order_ref_prefix', 'GAS-PO') . '-' . date('Y') . '-0001';

layout_head(t('as.title'), 'admin_settings');
?>
<p class="eyebrow"><?= tt('nav.admin') ?></p>
<h1><?= tt('as.title') ?></h1>
<p class="lede"><?= tt('as.lede') ?></p>

<form method="post" action="<?= url('admin_settings') ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="settings_save">
  <input type="hidden" name="_return" value="admin_settings">

  <div class="card">
    <h2><?= tt('as.general') ?></h2>
    <div class="grid grid-2">
      <div class="field">
        <label for="company_name"><?= tt('as.company') ?></label>
        <input id="company_name" name="company_name" type="text" value="<?= e(setting('company_name')) ?>">
      </div>
      <div class="field">
        <label for="order_ref_prefix"><?= tt('as.prefix') ?></label>
        <input id="order_ref_prefix" name="order_ref_prefix" type="text" value="<?= e(setting('order_ref_prefix')) ?>">
        <p class="hint"><?= e(t('as.prefix_hint', ['example' => $example])) ?></p>
      </div>
      <div class="field">
        <label for="default_locale"><?= tt('as.deflang') ?></label>
        <select id="default_locale" name="default_locale">
          <?php foreach (LOCALES as $code => $meta): ?>
            <option value="<?= e($code) ?>"<?= setting('default_locale', 'en') === $code ? ' selected' : '' ?>>
              <?= e($meta['native']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="notify_email_enabled"><?= tt('as.emailcopy') ?></label>
        <select id="notify_email_enabled" name="notify_email_enabled">
          <option value="1"<?= setting('notify_email_enabled') === '1' ? ' selected' : '' ?>><?= tt('as.send') ?></option>
          <option value="0"<?= setting('notify_email_enabled') !== '1' ? ' selected' : '' ?>><?= tt('as.inapp') ?></option>
        </select>
      </div>
      <div class="field">
        <label class="chk">
          <input type="hidden" name="autoclose_on_delivery" value="0">
          <input type="checkbox" name="autoclose_on_delivery" value="1"
                 <?= setting('autoclose_on_delivery', '1') === '1' ? 'checked' : '' ?>>
          <?= tt('set.autoclose') ?>
        </label>
        <p class="hint"><?= tt('set.autoclose_hint') ?></p>
      </div>
    </div>
  </div>

  <div class="card">
    <h2><?= tt('as.sp') ?></h2>
    <p class="hint" style="margin-top:-6px;margin-bottom:16px;">
      <?= tt('as.sp_lede') ?>
      <?= $fileOverride ? '<br><strong>' . tt('as.sp_file') . '</strong>' : '' ?>
    </p>

    <div class="grid grid-2">
      <div class="field">
        <label for="sharepoint_enabled"><?= tt('as.sync') ?></label>
        <select id="sharepoint_enabled" name="sharepoint_enabled">
          <option value="1"<?= setting('sharepoint_enabled') === '1' ? ' selected' : '' ?>><?= tt('common.on') ?></option>
          <option value="0"<?= setting('sharepoint_enabled') !== '1' ? ' selected' : '' ?>><?= tt('common.off') ?></option>
        </select>
      </div>
      <div class="field">
        <label for="graph_tenant_id"><?= tt('as.tenant') ?></label>
        <input id="graph_tenant_id" name="graph_tenant_id" type="text" value="<?= e(setting('graph_tenant_id')) ?>">
      </div>
      <div class="field">
        <label for="graph_client_id"><?= tt('as.client') ?></label>
        <input id="graph_client_id" name="graph_client_id" type="text" value="<?= e(setting('graph_client_id')) ?>">
      </div>
      <div class="field">
        <label for="graph_client_secret"><?= tt('as.secret') ?></label>
        <input id="graph_client_secret" name="graph_client_secret" type="password" value="<?= e(setting('graph_client_secret')) ?>">
      </div>
      <div class="field">
        <label for="sharepoint_site_id"><?= tt('as.siteid') ?></label>
        <input id="sharepoint_site_id" name="sharepoint_site_id" type="text" value="<?= e(setting('sharepoint_site_id')) ?>"
               placeholder="contoso.sharepoint.com,GUID,GUID">
      </div>
      <div class="field">
        <label for="sharepoint_list_id"><?= tt('as.listid') ?></label>
        <input id="sharepoint_list_id" name="sharepoint_list_id" type="text" value="<?= e(setting('sharepoint_list_id')) ?>">
      </div>
    </div>

    <p class="hint"><?= tt('as.columns') ?>
      <code>Title</code>, <code>ProjectNumber</code>, <code>VesselName</code>,
      <code>RequestedBy</code>, <code>ProjectManager</code>, <code>TechnicalManager</code>, <code>Priority</code>,
      <code>OrderStatus</code>, <code>ApproxDelivery</code>, <code>ActualDelivery</code>, <code>LineCount</code>, <code>LastSync</code>.</p>
  </div>

  <div class="actions">
    <button class="btn btn--gold" type="submit"><?= tt('as.save') ?></button>
  </div>
</form>

<div class="card">
  <h2><?= tt('as.syncnow') ?></h2>
  <p class="hint" style="margin-top:-6px;"><?= tt('as.syncnow_hint') ?></p>
  <form method="post" action="<?= url('admin_settings') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="sharepoint_sync_now">
    <input type="hidden" name="_return" value="admin_settings">
    <button class="btn btn--ghost" type="submit"><?= tt('as.push') ?></button>
  </form>

  <?php if ($recent): ?>
    <h3 style="margin-top:24px;"><?= tt('as.recent') ?></h3>
    <table>
      <thead><tr><th><?= tt('th.when') ?></th><th><?= tt('th.order') ?></th><th><?= tt('th.direction') ?></th>
                 <th><?= tt('th.http') ?></th><th><?= tt('th.result') ?></th></tr></thead>
      <tbody>
      <?php foreach ($recent as $s): ?>
        <tr>
          <td class="date"><?= e(fmt_datetime($s['created_at'])) ?></td>
          <td class="ref"><?= e($s['order_ref'] ?: '—') ?></td>
          <td><?= e($s['direction']) ?></td>
          <td class="num"><?= e((string)$s['http_status']) ?></td>
          <td><?= $s['ok']
                ? '<span class="pill pill--good">' . tt('as.ok') . '</span>'
                : '<span class="pill pill--bad">' . tt('as.failed') . '</span>' ?>
              <span class="hint"><?= e(mb_substr((string)$s['message'], 0, 120)) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

<?php
/**
 * Staff: bulk import of vessels and project structures from Excel.  GAS-CP-R9-001
 * Three symmetric cards (vessels | project structures | vessel systems), each with a template
 * download, a file picker and a "check only" switch; the result of the last
 * run is shown underneath as a numbered summary plus the skipped rows.
 */
require __DIR__ . '/_layout.php';
$u = require_staff();

/* template download: ?p=import&tpl=projects|vessels */
$tpl = (string)($_GET['tpl'] ?? '');
if ($tpl === 'issues' && function_exists('iss_template_download')) iss_template_download();
if (in_array($tpl, ['projects', 'vessels', 'systems', 'offers', 'contacts', 'users', 'companies', 'customers', 'holidays', 'issues'], true)) {
    $f = __DIR__ . '/../install/templates/' . $tpl . '-template.xlsx';
    if (!is_file($f)) { http_response_code(404); exit('Template missing'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Length: ' . filesize($f));
    header('Content-Disposition: attachment; filename="GAS-' . $tpl . '-import-template.xlsx"');
    header('Cache-Control: private, max-age=0');
    readfile($f); exit;
}

$res = $_SESSION['cp_import_result'] ?? null;
unset($_SESSION['cp_import_result']);

$counts = function (array $r): array {
    $keys = ['rows', 'customers_new', 'customers_upd', 'invoicing_new', 'invoicing_upd', 'vessels_new', 'vessels_unassigned', 'vessels_upd', 'projects_new', 'projects_upd', 'subs_new', 'subs_upd', 'systems_new', 'systems_upd', 'offers_new', 'offers_upd', 'contacts_new', 'contacts_upd', 'companies_new', 'companies_upd', 'links_new', 'notes_new', 'users_new', 'users_upd'];
    if (!empty($r['warnings'])) $out['warnings'] = count($r['warnings']);
    $out = [];
    foreach ($keys as $k) if (array_key_exists($k, $r)) $out[$k] = (int)$r[$k];
    $out['skipped'] = count($r['skipped'] ?? []);
    return $out;
};

layout_head(t('im.title'), 'import');
?>
<?php settings_tabs('import'); ?>
<h2 class="settings-h"><?= tt('im.title') ?></h2>
<p class="lede"><?= tt('im.lede') ?></p>

<div class="grid grid-3 imp-grid">
  <div class="card imp-card">
    <h2><?= tt('im.vessels') ?></h2>
    <p class="hint"><?= tt('im.vessels_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'vessels']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="vessel_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>

  <div class="card imp-card">
    <h2><?= tt('im.projects') ?></h2>
    <p class="hint"><?= tt('im.projects_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'projects']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="proj_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>

  <div class="card imp-card" id="systems">
    <h2><?= tt('im.systems') ?></h2>
    <p class="hint"><?= tt('im.systems_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'systems']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="sys_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"<?= sys_has_codes() ? '' : ' disabled title="' . e(t('sys.need_r9')) . '"' ?>><?= tt('im.run') ?></button>
    </form>
  </div>

  <?php if (of_has() && of_visible()): ?>
  <div class="card imp-card" id="offers">
    <h2><?= tt('of.title') ?></h2>
    <p class="hint"><?= tt('of.im_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'offers']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="of_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (ct_has()): ?>
  <div class="card imp-card" id="contacts">
    <h2><?= tt('ct.title') ?></h2>
    <p class="hint"><?= tt('ct.im_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'contacts']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="ct_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (is_cp_admin() && co_has()): ?>
  <div class="card imp-card" id="companies">
    <h2><?= tt('co.title') ?></h2>
    <p class="hint"><?= tt('co.im_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'companies']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="co_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (is_cp_admin()): ?>
  <div class="card imp-card" id="customers">
    <h2><?= tt('cu.title') ?></h2>
    <p class="hint"><?= tt('cu.im_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'customers']) ?>">⤓ <?= tt('im.template') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('customers_export') ?>">⤓ <?= tt('cu.export_current') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="cu_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>
  <div class="card imp-card" id="users">
    <h2><?= tt('au.title') ?></h2>
    <p class="hint"><?= tt('au.im_hint') ?></p>
    <p><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'users']) ?>">⤓ <?= tt('im.template') ?></a></p>
    <form method="post" action="<?= url('import') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.running') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="au_import">
      <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
      <button class="btn" type="submit"><?= tt('im.run') ?></button>
    </form>
  </div>
  <?php endif; ?>
</div>

<?php if ($res): $c = $counts($res); ?>
<div class="card" id="result">
  <h2><?= tt('im.result') ?> — <?= ['offers' => t('of.title'), 'contacts' => t('ct.title'), 'users' => t('au.title'), 'companies' => t('co.title'), 'customers' => t('cu.title')][$res['kind'] ?? ''] ?? t('im.' . (in_array($res['kind'] ?? '', ['vessels', 'systems'], true) ? $res['kind'] : 'projects')) ?>
      <span class="hint" style="display:inline;margin-left:8px;"><?= e($res['file'] ?? '') ?></span></h2>
  <div class="flash flash--<?= $res['dry'] ? 'warn' : 'good' ?>" style="margin-top:10px;">
    <?= $res['dry'] ? tt('im.dry_result') : tt('im.applied') ?>
  </div>
  <?php $n = count($c); $cols = $n % 3 === 0 ? 3 : ($n <= 6 ? $n : 4); ?>
  <div class="imp-stats" style="--cols:<?= $cols ?>;">
    <?php foreach ($c as $k => $v): ?>
      <div class="stat<?= $k === 'skipped' && $v ? ' bad' : ($k === 'warnings' && $v ? ' gold' : ($v && $k !== 'rows' ? ' good' : '')) ?>">
        <b><?= $v ?></b><span><?= tt('im.' . $k) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($res['skipped'])): ?>
    <div class="table-scroll" style="margin-top:14px;"><table>
      <thead><tr><th style="width:90px;"><?= tt('im.skip_row') ?></th><th><?= tt('im.skip_reason') ?></th></tr></thead>
      <tbody><?php foreach (array_slice($res['skipped'], 0, 50) as [$row, $why]): ?>
        <tr><td class="num"><?= (int)$row ?></td><td><?= e($why) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?php if (!empty($res['warnings'])): ?>
    <h3 class="ed-h" style="margin-top:16px;"><?= tt('im.warnings') ?> <span><?= tt('im.warnings_hint') ?></span></h3>
    <div class="table-scroll"><table>
      <thead><tr><th style="width:90px;"><?= tt('im.skip_row') ?></th><th><?= tt('im.skip_reason') ?></th></tr></thead>
      <tbody><?php foreach (array_slice($res['warnings'], 0, 50) as [$row, $why]): ?>
        <tr><td class="num"><?= (int)$row ?></td><td><?= e($why) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <?php if (!empty($res['generated'])): ?>
    <div class="flash flash--warn" style="margin-top:16px;"><b><?= tt('au.im_generated') ?></b> <?= tt('au.im_generated_hint') ?></div>
    <div class="table-scroll"><table>
      <thead><tr><th style="width:90px;"><?= tt('im.skip_row') ?></th><th><?= tt('au.name') ?></th><th><?= tt('au.email') ?></th><th><?= tt('au.password') ?></th></tr></thead>
      <tbody><?php foreach ($res['generated'] as [$row, $name, $email, $pw]): ?>
        <tr><td class="num"><?= (int)$row ?></td><td><?= e($name) ?></td><td><?= e($email) ?></td><td><code class="ct-pw"><?= $res['dry'] ? '••••••••' : e($pw) ?></code></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
  <p style="margin:14px 0 0;"><?php if (($res['kind'] ?? '') === 'contacts'): ?><a class="btn btn--ghost btn--sm" href="<?= url('contacts') ?>"><?= tt('ct.title') ?> →</a><?php elseif (($res['kind'] ?? '') === 'customers'): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_customers') ?>"><?= tt('cu.title') ?> →</a><?php elseif (($res['kind'] ?? '') === 'companies'): ?><a class="btn btn--ghost btn--sm" href="<?= url('companies') ?>"><?= tt('co.title') ?> →</a><?php elseif (($res['kind'] ?? '') === 'users'): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_users') ?>"><?= tt('au.title') ?> →</a><?php elseif (($res['kind'] ?? '') === 'offers'): ?><a class="btn btn--ghost btn--sm" href="<?= url('offers') ?>"><?= tt('of.title') ?> →</a><?php elseif (($res['kind'] ?? '') === 'systems' && is_cp_admin()): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_systems') ?>"><?= tt('sys.title') ?> →</a><?php else: ?><a class="btn btn--ghost btn--sm" href="<?= url('projects') ?>"><?= tt('pr.title') ?> →</a><?php endif; ?></p>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

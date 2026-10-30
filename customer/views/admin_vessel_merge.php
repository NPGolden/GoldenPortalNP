<?php
/** Settings › Vessel duplicates — one record per hull (GAS-CP-R30-001). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$groups = vm_groups();
$live = all('SELECT id, name, imo, (SELECT name FROM customers c WHERE c.id = v.customer_id) AS customer FROM vessels v WHERE merged_into IS NULL ORDER BY name');
$retired = all('SELECT v.id, v.name, m.name AS master, m.id AS master_id FROM vessels v JOIN vessels m ON m.id = v.merged_into ORDER BY m.name, v.name');
$res = $_SESSION['vm_map_result'] ?? null; unset($_SESSION['vm_map_result']);
layout_head(t('vm.title'), 'settings');
$src = function (string $d): string { return match (substr($d, 0, 10)) { '2026-10-09' => t('vm.src_softone_ct'), '2026-10-19' => t('vm.src_softone_pr'), '2026-10-20' => t('vm.src_freshdesk'), default => t('vm.src_manual') }; };
?>
<p class="eyebrow"><a href="<?= url('settings') ?>"><?= tt('nav.settings') ?></a> › <?= tt('vm.title') ?></p>
<div class="page-head"><div><h1><?= tt('vm.title') ?></h1><p class="lede"><?= tt('vm.lede') ?></p></div></div>
<?php settings_tabs('admin_vessel_merge'); ?>

<div class="cols">
  <div class="card card--tight">
    <h2><?= tt('vm.map_title') ?></h2>
    <p class="hint"><?= tt('vm.map_hint') ?></p>
    <form method="post" action="<?= url('admin_vessel_merge') ?>" enctype="multipart/form-data" class="btn-row" style="align-items:center;">
      <?= csrf_field() ?><input type="hidden" name="action" value="vm_map_import">
      <input type="file" name="file" accept=".xlsx" required>
      <button class="btn btn--ghost" type="submit" name="dry" value="1"><?= tt('im.check') ?></button>
      <button class="btn" type="submit" name="dry" value="0" data-js-confirm="<?= e(t('vm.map_confirm')) ?>"><?= tt('vm.map_apply') ?></button>
    </form>
    <?php if ($res): ?>
      <div class="notice <?= $res['errors'] ? 'notice--bad' : 'notice--good' ?>" style="margin-top:10px;">
        <b><?= e(t('vm.map_result', ['r' => $res['rows'], 'g' => $res['groups'], 'm' => $res['merged']])) ?></b>
        <?php if (!empty($res['plan'])): ?><ul class="hint" style="margin:6px 0 0;"><?php foreach ($res['plan'] as $mid => $dups): ?><li><?= e((string) scalar('SELECT name FROM vessels WHERE id = ?', [$mid])) ?> ← <?= e(implode(', ', array_map(fn($d) => (string) scalar('SELECT name FROM vessels WHERE id = ?', [$d]), $dups))) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <?php foreach (['errors', 'skipped'] as $k): if (!empty($res[$k])): ?><ul class="hint" style="margin:6px 0 0;"><?php foreach ($res[$k] as [$ln, $msg]): ?><li><?= tt('im.row') ?> <?= $ln ?>: <?= e($msg) ?></li><?php endforeach; ?></ul><?php endif; endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
  <div class="card card--tight">
    <h2><?= tt('vm.manual_title') ?></h2>
    <p class="hint"><?= tt('vm.manual_hint') ?></p>
    <form method="post" action="<?= url('admin_vessel_merge') ?>" class="grid grid-2" style="align-items:end;">
      <?= csrf_field() ?><input type="hidden" name="action" value="vm_merge">
      <div class="field" style="margin:0;"><label><?= tt('vm.master') ?></label><select name="master_id" required><option value=""><?= tt('vm.choose') ?></option><?php foreach ($live as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name'] . ($v['customer'] ? ' · ' . $v['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0;"><label><?= tt('vm.duplicate') ?></label><select name="dup_ids[]" required><option value=""><?= tt('vm.choose') ?></option><?php foreach ($live as $v): ?><option value="<?= $v['id'] ?>"><?= e($v['name'] . ($v['customer'] ? ' · ' . $v['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="btn-row" style="grid-column:1 / -1;"><button class="btn" type="submit" data-js-confirm="<?= e(t('vm.confirm')) ?>"><?= tt('vm.merge') ?></button></div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-head"><h2><?= tt('vm.groups') ?> <span class="count"><?= count($groups) ?></span></h2></div>
  <p class="hint"><?= tt('vm.groups_hint') ?></p>
  <?php if (!$groups): ?><p class="empty"><?= tt('vm.no_groups') ?></p><?php endif; ?>
  <?php foreach ($groups as $gi => $g): ?>
  <form method="post" action="<?= url('admin_vessel_merge') ?>" class="vm-group" id="vmg<?= $gi ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="vm_merge">
    <table class="tbl" data-nosort>
      <thead><tr><th style="width:70px;"><?= tt('vm.master') ?></th><th style="width:70px;"><?= tt('vm.merge_col') ?></th><th><?= tt('v.one') ?></th><th><?= tt('v.imo') ?></th><th><?= tt('v.customer') ?></th><th><?= tt('vm.holds') ?></th><th><?= tt('vm.source') ?></th></tr></thead>
      <tbody>
      <?php foreach ($g['rows'] as $ri => $r): $c = $r['counts']; $holds = array_filter(['projects' => $c['projects'], 'tickets' => $c['tickets'], 'contacts' => $c['contacts'], 'equipment' => $c['equipment'], 'documents' => $c['documents']]); ?>
        <tr<?= $r['is_active'] ? '' : ' class="is-muted"' ?>>
          <td><input type="radio" name="master_id" value="<?= $r['id'] ?>"<?= $ri === 0 ? ' checked' : '' ?>></td>
          <td><input type="checkbox" name="dup_ids[]" value="<?= $r['id'] ?>"<?= $ri === 0 ? '' : ' checked' ?>></td>
          <td><a href="<?= url('vessel', ['id' => $r['id']]) ?>"><?= e($r['name']) ?></a><?= $r['is_active'] ? '' : ' ' . pill('mute', t('v.inactive')) ?></td>
          <td><?= e($r['imo'] ?: '—') ?></td>
          <td><?= e($r['customer'] ?: '—') ?></td>
          <td class="hint"><?= $holds ? e(implode(' · ', array_map(fn($k, $n) => $n . ' ' . t('vm.h_' . $k), array_keys($holds), $holds))) : '—' ?></td>
          <td class="hint"><?= e($src((string)$r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <div class="btn-row" style="align-items:center;margin-top:8px;">
      <label class="hint" style="display:inline-flex;align-items:center;gap:8px;"><?= tt('vm.rename_to') ?> <input name="rename" value="<?= e($g['canonical']) ?>" maxlength="120" style="min-width:260px;"></label>
      <button class="btn btn--sm" type="submit" data-js-confirm="<?= e(t('vm.confirm')) ?>"><?= tt('vm.merge') ?></button>
    </div>
  </form>
  <?php endforeach; ?>
</div>

<?php if ($retired): ?>
<div class="card">
  <div class="card-head"><h2><?= tt('vm.retired') ?> <span class="count"><?= count($retired) ?></span></h2></div>
  <p class="hint"><?= tt('vm.retired_hint') ?></p>
  <table class="tbl"><thead><tr><th><?= tt('vm.duplicate') ?></th><th><?= tt('vm.master') ?></th></tr></thead><tbody>
  <?php foreach ($retired as $r): ?><tr><td><?= e($r['name']) ?></td><td><a href="<?= url('vessel', ['id' => $r['master_id']]) ?>"><?= e($r['master']) ?></a></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<script nonce="<?= csp_nonce() ?>">
document.querySelectorAll('.vm-group').forEach(function (f) {   // the master is never also a duplicate
  f.querySelectorAll('input[type=radio]').forEach(function (r) { r.addEventListener('change', function () {
    f.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = c.value !== r.value; }); }); });
});
</script>
<?php layout_foot(); ?>

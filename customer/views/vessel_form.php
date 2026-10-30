<?php
/**
 * Staff: add or edit a vessel.                               GAS-CP-R4-003
 *   ?p=vessel_new      ?p=vessel_edit&id=ID
 */
require __DIR__ . '/_layout.php';
$u    = require_staff();
$edit = ($page ?? '') === 'vessel_edit';
$id   = (int)($_GET['id'] ?? 0);
$v    = [];
if ($edit) {
    $v = one('SELECT v.*, (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id) AS n_projects FROM vessels v WHERE v.id = ?', [$id]);
    if (!$v) { http_response_code(404); exit(t('err.notfound')); }
}
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
if (!empty($_GET['rm_alias']) && function_exists('vm_has') && vm_has() && hash_equals(csrf_token(), (string)($_GET['_csrf'] ?? ''))) { vm_alias_remove((int)$_GET['rm_alias']); redirect('vessel_edit', ['id' => (int)($_GET['id'] ?? 0)]); }
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$title = $edit ? t('v.edit') : t('v.new');
layout_head($title, 'vessels');
?>
<p class="eyebrow"><a href="<?= url('vessels') ?>"><?= tt('v.title') ?></a><?= $edit ? ' › ' . e($v['name']) : '' ?></p>
<h1><?= e($title) ?></h1>

<div class="card">
  <form method="post" action="<?= url($edit ? 'vessel_edit' : 'vessel_new', $edit ? ['id' => $id] : []) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="vessel_save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('nav.vessels') ?></label><input name="name" required maxlength="120" value="<?= e($v['name'] ?? '') ?>" placeholder="MY-GY-85-02"></div>
      <div class="field"><label><?= tt('v.customer') ?></label>
        <select name="customer_id"><option value=""><?= tt('v.unassigned') ?></option>
          <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $v['customer_id'] ?? '') ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select><p class="hint"><?= tt('v.customer_hint') ?></p></div>
      <div class="field"><label><?= tt('v.imo') ?></label><input name="imo" maxlength="16" value="<?= e($v['imo'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('v.yard') ?></label><input name="yard" maxlength="120" value="<?= e($v['yard'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label>
        <select name="is_active"><option value="1"<?= $sel(1, $v['is_active'] ?? 1) ?>><?= tt('v.active') ?></option><option value="0"<?= $sel(0, $v['is_active'] ?? 1) ?>><?= tt('v.inactive') ?></option></select></div>
    </div>
    <?php if (function_exists('vm_has') && vm_has()): $vid0 = (int)($v['id'] ?? 0); $als = $vid0 ? vm_aliases($vid0) : []; ?>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('vm.former_names') ?></label><input name="former_names" maxlength="255" value="<?= e($v['former_names'] ?? '') ?>" placeholder="ETHOS; RAHIL"><p class="hint"><?= tt('vm.former_hint') ?></p></div>
      <div class="field"><label><?= tt('vm.aliases') ?></label><textarea name="aliases" rows="3" placeholder="GIPTA&#10;M/Y GIPTA (OLD)"></textarea><p class="hint"><?= tt('vm.aliases_hint') ?></p>
        <?php if ($als): ?><p class="hint" style="margin-top:6px;"><?php foreach ($als as $a): ?><span class="pill pill--mute" style="margin:0 6px 4px 0;"><?= e($a['alias']) ?> <a href="<?= url('vessel_edit', ['id' => $vid0, 'rm_alias' => $a['id'], '_csrf' => csrf_token()]) ?>" title="<?= tt('vm.remove') ?>" style="text-decoration:none;">×</a></span><?php endforeach; ?></p><?php endif; ?></div>
    </div>
    <p class="hint"><?= tt('vm.name_rule') ?></p>
    <?php endif; ?>
    <div class="btn-row">
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
      <a class="btn btn--ghost" href="<?= url('vessels') ?>"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
</div>

<?php if ($edit && is_cp_admin()): ?>
<div class="danger-zone">
  <h2><?= tt('dz.title') ?></h2>
  <p><?= e(t('v.delete_hint', ['n' => (int)$v['n_projects']])) ?></p>
  <form method="post" action="<?= url('vessel_edit', ['id' => $id]) ?>" data-lock
        data-confirm="<?= e(t('v.delete_confirm', ['name' => $v['name'], 'n' => (int)$v['n_projects']])) ?>"
        data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
    <?= csrf_field() ?><input type="hidden" name="action" value="vessel_delete"><input type="hidden" name="id" value="<?= $id ?>">
    <button class="btn btn--danger" type="submit">🗑 <?= tt('v.delete') ?></button>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

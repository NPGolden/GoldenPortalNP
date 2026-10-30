<?php
/**
 * Staff: create or edit a main project, or a sub-project.    GAS-CP-R4-002
 * One file, four modes:
 *   ?p=project_new                    ?p=project_edit&id=ID
 *   ?p=subproject_new&project=ID      ?p=subproject_edit&id=ID
 */
require __DIR__ . '/_layout.php';
$u     = require_staff();
$mode  = (string)($page ?? 'project_new');
$isSub = in_array($mode, ['subproject_new', 'subproject_edit'], true);
$edit  = in_array($mode, ['project_edit', 'subproject_edit'], true);
$id    = (int)($_GET['id'] ?? 0);

$proj = null; $sub = null; $parent = null;
if ($mode === 'project_edit') {
    $proj = one('SELECT * FROM projects WHERE id = ?', [$id]);
    if (!$proj) { http_response_code(404); exit(t('err.notfound')); }
} elseif ($mode === 'subproject_edit') {
    $sub = one('SELECT * FROM subprojects WHERE id = ?', [$id]);
    if (!$sub) { http_response_code(404); exit(t('err.notfound')); }
    $parent = one('SELECT * FROM projects WHERE id = ?', [$sub['project_id']]);
} elseif ($mode === 'subproject_new') {
    $parent = one('SELECT * FROM projects WHERE id = ?', [(int)($_GET['project'] ?? 0)]);
    if (!$parent) { http_response_code(404); exit(t('err.notfound')); }
}
$pid = (int)($parent['id'] ?? 0);

$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$vessels   = all('SELECT id, name, customer_id FROM vessels WHERE is_active = 1 ORDER BY name');
$systems   = sys_options();

$title = $isSub ? ($edit ? t('sp.edit') : t('pr.addsub')) : ($edit ? t('pr.edit') : t('pr.new'));
$crumb = $parent ? ($parent['project_code'] ?: $parent['name']) : ($proj ? ($proj['project_code'] ?: $proj['name']) : '');
$sel   = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$statuses = []; foreach (pm_statuses() as $st) $statuses[$st] = t('pr.' . $st);

layout_head($title, 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a><?= $crumb !== '' ? ' › ' . e($crumb) : '' ?>
   <?= $sub ? ' › ' . e($sub['code'] ?: $sub['name']) : '' ?></p>
<h1><?= e($title) ?></h1>

<div class="card">
<?php if (!$isSub): $v = $proj ?? [];
  if (!$v && !empty($_GET['vessel'])) { $pv = one('SELECT id, customer_id FROM vessels WHERE id = ?', [(int)$_GET['vessel']]); if ($pv) $v = ['vessel_id' => $pv['id'], 'customer_id' => $pv['customer_id']]; } ?>
  <form method="post" action="<?= url($edit ? 'project_edit' : 'project_new', $edit ? ['id' => $id] : []) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'project_update' : 'project_save' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('pr.code') ?></label>
        <?php $numAuto = !$edit && function_exists('num_has') && num_has() && num_cfg()['auto']; ?>
        <input name="erp_code" <?= $numAuto ? '' : 'required ' ?>maxlength="40" placeholder="<?= $numAuto ? e(num_next_project()) : 'GY-85-02' ?>" value="<?= e($v['erp_code'] ?? $v['project_code'] ?? ($numAuto ? num_next_project() : '')) ?>">
        <p class="hint"><?= $numAuto ? '🔢 ' . e(t('num.p_hint', ['n' => num_next_project()])) : tt('pr.code_hint') ?></p></div>
      <div class="field"><label><?= tt('sp.name') ?></label><input name="name" required maxlength="160" value="<?= e($v['name'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('rp.customer') ?></label>
        <select name="customer_id" id="customer_id" required>
          <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $v['customer_id'] ?? '') ?>><?= e($c['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label><?= tt('th.vessel') ?></label>
        <select name="vessel_id" id="vessel_id"><option value=""><?= tt('pr.novessel') ?></option>
          <?php foreach ($vessels as $ve): ?><option value="<?= $ve['id'] ?>" data-customer="<?= (int)$ve['customer_id'] ?>"<?= $sel($ve['id'], $v['vessel_id'] ?? '') ?>><?= e($ve['name']) ?></option><?php endforeach; ?>
        </select><p class="hint"><?= tt('pr.vessel_hint') ?></p></div>
      <div class="field"><label><?= tt('pr.pm') ?></label>
        <?php $staffUsers = staff_users(); $legacy = !$edit ? '' : (string)($v['project_manager'] ?? '');
              $curPm = (int)($v['pm_user_id'] ?? 0) ?: ($edit ? (int)(staff_by_name($legacy)['id'] ?? 0) : (int)$u['id']);
              $pms = array_filter($staffUsers, fn($x) => $x['role_name'] === 'Project Manager'); $others = array_filter($staffUsers, fn($x) => $x['role_name'] !== 'Project Manager'); ?>
        <select name="pm_user_id">
          <option value=""><?= tt('pr.pm_none') ?></option>
          <?php if ($legacy !== '' && !$curPm): ?><option value="keep" selected><?= e(t('pr.pm_keep', ['name' => $legacy])) ?></option><?php endif; ?>
          <?php if ($pms): ?><optgroup label="<?= tt('au.pm_group') ?>"><?php foreach ($pms as $x): ?><option value="<?= $x['id'] ?>"<?= $sel($x['id'], $curPm) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
          <?php if ($others): ?><optgroup label="<?= tt('au.other_group') ?>"><?php foreach ($others as $x): ?><option value="<?= $x['id'] ?>"<?= $sel($x['id'], $curPm) ?>><?= e($x['full_name']) ?><?= $x['role_name'] ? ' — ' . e($x['role_name']) : '' ?></option><?php endforeach; ?></optgroup><?php endif; ?>
        </select><p class="hint"><?= tt('pr.pm_hint') ?></p></div>
      <div class="field"><label><?= tt('th.status') ?></label>
        <select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $v['status'] ?? pm_default_status()) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php if (of_quoted_has()): ?><p class="hint"><?= tt('pr.status_rule') ?></p><?php endif; ?></div>
      <div class="field"><label><?= tt('pr.category') ?></label><input name="category" maxlength="80" value="<?= e($v['category'] ?? '') ?>" placeholder="Newbuild / Refit / Service"></div>
      <?php if (br_has() && br_multi()): ?><div class="field"><label><?= tt('br.one') ?></label><select name="branch_id"><?= br_options((int)($v['branch_id'] ?? 0) ?: null, t('br.default_opt', ['b' => br_default()['code']])) ?></select><p class="hint"><?= tt('br.project_hint') ?></p></div><?php endif; ?>
      <div class="field"><label><?= tt('pr.milestone') ?></label><input name="next_milestone" maxlength="120" value="<?= e($v['next_milestone'] ?? '') ?>"></div>
      <?php if (of_has()): ?><div class="field"><label><?= tt('pr.deadline') ?></label><input name="deadline" type="date" value="<?= e($v['deadline'] ?? '') ?>"></div><?php endif; ?>
    </div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
      <a class="btn btn--ghost" href="<?= url('projects') ?>"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
  <script nonce="<?= csp_nonce() ?>">
  (function () {                                   // vessel list follows the chosen customer
    var c = document.getElementById('customer_id'), v = document.getElementById('vessel_id');
    if (!c || !v) return;
    function sync() {
      var cid = c.value, keep = v.value;
      Array.prototype.forEach.call(v.options, function (o) {
        if (!o.value) return;
        o.hidden = o.dataset.customer !== cid; if (o.hidden && o.value === keep) v.value = '';
      });
    }
    c.addEventListener('change', sync); sync();
  })();
  </script>
<?php else: $v = $sub ?? []; ?>
  <form method="post" action="<?= url($edit ? 'subproject_edit' : 'subproject_new', $edit ? ['id' => $id] : ['project' => $pid]) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'subproject_update' : 'subproject_save' ?>">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php else: ?><input type="hidden" name="project_id" value="<?= $pid ?>"><?php endif; ?>
    <div class="grid grid-2">
      <?php $numAuto = !$edit && function_exists('num_has') && num_has() && num_cfg()['auto']; ?>
      <div class="field"><label><?= tt('sp.code') ?></label><input name="code" <?= $numAuto ? '' : 'required ' ?>maxlength="40" placeholder="<?= $numAuto ? e(num_next_sub($pid)) : 'Y.01' ?>" value="<?= e($v['code'] ?? ($numAuto ? num_next_sub($pid) : '')) ?>"><p class="hint"><?= $numAuto ? '🔢 ' . e(t('num.s_hint', ['n' => num_next_sub($pid)])) : tt('sp.code_hint') ?></p></div>
      <div class="field"><label><?= tt('sp.name') ?></label><input name="name" required maxlength="120" placeholder="Main Switchboard" value="<?= e($v['name'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('sys.one') ?></label>
        <select name="system_id"><option value=""><?= tt('sys.none') ?></option>
          <?php foreach ($systems as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($s['id'], $v['system_id'] ?? '') ?>><?= e(sys_label($s)) ?></option><?php endforeach; ?>
        </select></div>
      <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" value="<?= (int)($v['sort_order'] ?? 0) ?>"></div>
      <?php if (of_has()): ?><div class="field"><label><?= tt('pr.deadline') ?></label><input name="deadline" type="date" value="<?= e($v['deadline'] ?? '') ?>"></div><?php endif; ?>
      <?php if (function_exists('iv_vessel_has') && iv_vessel_has() && fin_visible()): $ivSp = ($v ?? []) + ['vessel_id' => $parent['vessel_id'] ?? null, 'customer_id' => $parent['customer_id'] ?? null]; $ivCh = iv_choices_for_sub($ivSp); ?><div class="field"><label><?= tt('iv.title') ?></label><select name="invoicing_id"><option value=""><?= tt('iv.sub_default') ?></option><?php foreach ($ivCh as $ivr): ?><option value="<?= (int)$ivr['id'] ?>"<?= (int)($v['invoicing_id'] ?? 0) === (int)$ivr['id'] ? ' selected' : '' ?>><?= e(($ivr['src'] === 'vessel' ? '⚓ ' : '🏢 ') . iv_label($ivr)) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('iv.sub_hint') ?><?= !empty($parent['vessel_id']) ? ' <a href="' . url('vessel', ['id' => (int)$parent['vessel_id'], 'tab' => 'invoicing']) . '">' . tt('iv.vessel_tab') . ' ›</a>' : '' ?></p></div><?php endif; ?>
      <?php if (br_has() && br_multi()): $pb = br_of($pid); ?><div class="field"><label><?= tt('br.one') ?></label><select name="branch_id"><?= br_options((int)($v['branch_id'] ?? 0) ?: null, t('br.inherit_opt', ['b' => $pb['code']])) ?></select><p class="hint"><?= tt('br.sub_hint') ?></p></div><?php endif; ?>
      <?php if ($edit): ?>
      <div class="field"><label><?= tt('th.status') ?></label>
        <select name="status"><?php foreach ($statuses as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $v['status'] ?? pm_default_status()) ?>><?= e($l) ?></option><?php endforeach; ?></select><?php if (of_quoted_has()): ?><p class="hint"><?= tt('pr.status_rule') ?></p><?php endif; ?></div>
      <?php endif; ?>
      <?php if (inv_has()): ?>
      <div class="field"><label><?= tt('inv.title') ?></label>
        <select name="invoicing"><?php foreach (inv_states() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $v['invoicing'] ?? 'none') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('inv.pct') ?></label><input name="invoiced_pct" type="number" min="0" max="100" step="5" value="<?= (int)($v['invoiced_pct'] ?? 0) ?>"><p class="hint"><?= tt('inv.pct_hint') ?></p></div>
      <?php endif; ?>
    </div>
    <div class="field"><label><?= tt('sp.description') ?></label><textarea name="description"><?= e($v['description'] ?? '') ?></textarea></div>
    <div class="btn-row">
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
      <a class="btn btn--ghost" href="<?= $edit ? url('subproject', ['id' => $id]) : url('projects') ?>"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
<?php endif; ?>
</div>

<?php if ($edit && is_cp_admin()): ?>
<div class="danger-zone">
  <h2><?= tt('dz.title') ?></h2>
  <?php if (!$isSub): $fp = pm_project_footprint($id); $label = $proj['project_code'] ?: $proj['name']; ?>
    <p><?= e(t('pr.delete_hint', $fp)) ?></p>
    <form method="post" action="<?= url('project_edit', ['id' => $id]) ?>" data-lock
          data-confirm="<?= e(t('pr.delete_confirm', ['code' => $label] + $fp)) ?>"
          data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
      <?= csrf_field() ?><input type="hidden" name="action" value="project_delete"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn--danger" type="submit">🗑 <?= tt('pr.delete') ?></button>
    </form>
  <?php else: $label = $sub['code'] ?: $sub['name']; ?>
    <p><?= tt('sp.delete_hint') ?></p>
    <form method="post" action="<?= url('subproject_edit', ['id' => $id]) ?>" data-lock
          data-confirm="<?= e(t('sp.delete_confirm', ['code' => $label])) ?>"
          data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
      <?= csrf_field() ?><input type="hidden" name="action" value="subproject_delete"><input type="hidden" name="id" value="<?= $id ?>">
      <button class="btn btn--danger" type="submit">🗑 <?= tt('sp.delete') ?></button>
    </form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($mode === 'project_edit' && is_cp_admin() && ar_has()): $arRes = $_SESSION['cp_ar_result'] ?? null; unset($_SESSION['cp_ar_result']); ?>
<div class="card" id="refile">
  <h2><?= tt('ar.refile') ?></h2>
  <p class="hint"><?= tt('ar.refile_hint') ?></p>
  <div class="btn-row">
    <form method="post" action="<?= url('project_edit', ['id' => $id]) ?>" data-lock data-busy="<?= tt('ar.working') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_reorganise"><input type="hidden" name="project_id" value="<?= $id ?>"><input type="hidden" name="dry" value="1"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ar.dry') ?></button></form>
    <form method="post" action="<?= url('project_edit', ['id' => $id]) ?>" data-lock data-busy="<?= tt('ar.working') ?>" data-confirm="<?= tt('ar.refile_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ar.apply') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_reorganise"><input type="hidden" name="project_id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit"><?= tt('ar.refile_apply') ?></button></form>
  </div>
  <?php if ($arRes): ?><div class="flash flash--<?= $arRes['dry'] ? 'warn' : 'good' ?>" style="margin-top:10px;"><?= e(t($arRes['dry'] ? 'ar.dry_result' : 'ar.apply_result', ['c' => $arRes['checked'], 'm' => $arRes['moved'], 's' => $arRes['same'], 'x' => $arRes['missing'], 'e' => $arRes['errors']])) ?></div>
  <?php if ($arRes['rows']): ?><div class="table-scroll"><table class="ar-rows"><thead><tr><th><?= tt('ar.from') ?></th><th><?= tt('ar.to') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody><?php foreach (array_slice($arRes['rows'], 0, 200) as [$tbl, $fid, $from, $to, $st]): ?><tr><td class="mono"><?= e($from) ?></td><td class="mono"><?= e($to) ?></td><td><?= e($st) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

<?php
/** New / edit production order (GAS-CP-R41-001). ?p=production_edit[&id=][&work=][&subproject=][&project=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('pd_has') || !pd_has()) { layout_head(t('pd.title'), 'equipment'); echo '<div class="flash flash--warn">' . tt('pd.need_db') . '</div>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $o = $id ? pd_get($id) : null;
if ($id && (!$o || !pd_can_edit($o))) { http_response_code(404); layout_head(t('err.notfound'), 'equipment'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!$id && !pd_can_edit()) { http_response_code(403); layout_head(t('err.noaccess'), 'equipment'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$w = !$o && ($wid = (int)($_GET['work'] ?? 0)) && function_exists('wp_get') ? wp_get($wid) : null;
$curW = $o ? (int)$o['work_id'] : (int)($w['id'] ?? 0);
$curS = $o ? (int)$o['subproject_id'] : (int)($w['subproject_id'] ?? ($_GET['subproject'] ?? 0));
$curP = $o ? (int)$o['project_id'] : (int)($w['project_id'] ?? ($_GET['project'] ?? 0));
if ($curS && !$curP) $curP = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$curS]);
[$scope, $args] = scope_sql('p');
$projects = all("SELECT p.id, p.project_code, p.name, p.vessel_id, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE {$scope} AND (p.status <> 'completed' OR p.id = ?) ORDER BY p.status = 'active' DESC, p.project_code", array_merge($args, [$curP]));
$subs = all("SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE {$scope} ORDER BY s.project_id, s.code", $args);
$works = function_exists('wp_has') && wp_has() ? all("SELECT w.id, w.subproject_id, w.wp_no, w.title FROM sp_works w JOIN projects p ON p.id = w.project_id WHERE {$scope} AND w.status <> 'cancelled' ORDER BY w.subproject_id, w.wp_no", $args) : [];
$vessels = all("SELECT id, name FROM vessels WHERE is_active = 1 ORDER BY name");
$staff = staff_users();
$v = fn(string $k, $d = '') => e((string)($o[$k] ?? $d));
$title = $o ? $o['po_no'] . ' · ' . t('btn.edit') : t('pd.new');
layout_head($title, 'equipment');
?>
<p class="eyebrow"><a href="<?= url('equipment') ?>"><?= tt('eq.title') ?></a> › <a href="<?= url('production') ?>"><?= tt('pd.title') ?></a> › <?= e($title) ?></p>
<div class="page-head"><div><h1><?= e($title) ?></h1><p class="lede" style="margin-bottom:0;"><?= $w ? e(t('pd.from_work', ['no' => (string)$w['wp_no']]) . ' — ' . $w['title']) : tt('pd.form_lede') ?></p></div></div>
<div class="card">
  <form method="post" action="<?= url('production_edit', $id ? ['id' => $id] : []) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pd_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label for="project_id"><?= tt('pr.one') ?> *</label><select id="project_id" name="project_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" data-v="<?= (int)($p['vessel_id'] ?? 0) ?>"<?= (int)$p['id'] === $curP ? ' selected' : '' ?>><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) ?><?= $p['vessel'] ? ' · ' . e($p['vessel']) : '' ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id" data-nosearch><option value="">—</option></select></div>
      <div class="field"><label for="work_id"><?= tt('wp.work') ?></label><select id="work_id" name="work_id" data-nosearch><option value="">—</option></select><p class="hint"><?= tt('pd.work_hint') ?></p></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('pd.order') ?> *</label><input name="title" maxlength="160" required value="<?= $v('title', $w['title'] ?? '') ?>" placeholder="<?= tt('pd.title_ph') ?>"></div>
      <div class="field"><label><?= tt('pd.system') ?></label><select name="system_id"><option value="">—</option><?= sys_select_options((int)($o['system_id'] ?? 0)) ?></select></div>
      <div class="field"><label><?= tt('th.vessel') ?></label><select id="vessel_id" name="vessel_id"><option value="">—</option><?php foreach ($vessels as $ve): ?><option value="<?= (int)$ve['id'] ?>"<?= (int)($o['vessel_id'] ?? 0) === (int)$ve['id'] ? ' selected' : '' ?>><?= e($ve['name']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('pd.vessel_hint') ?></p></div>
      <div class="field"><label><?= tt('pd.manager') ?></label><select name="manager_user_id"><option value="">—</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)($o['manager_user_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?><?= $s['role_name'] ? ' · ' . e($s['role_name']) : '' ?></option><?php endforeach; ?></select><p class="hint"><?= tt('pd.manager_hint') ?></p></div>
      <div class="field"><label><?= tt('pd.planned') ?></label><div class="grid grid-2" style="gap:6px;"><input type="date" name="planned_start" value="<?= $v('planned_start', $w['start_date'] ?? '') ?>"><input type="date" name="planned_end" value="<?= $v('planned_end', $w['end_date'] ?? '') ?>"></div></div>
      <?php if ($o && !$o['units']): ?><div class="field"><label><?= tt('pd.progress') ?> %</label><input type="number" name="pct" min="0" max="100" value="<?= (int)$o['pct'] ?>"><p class="hint"><?= tt('pd.pct_manual') ?></p></div><?php endif; ?>
      <div class="field" style="grid-column: 1 / -1;"><label><?= tt('tk.description') ?></label><textarea name="description" rows="4"><?= $v('description', $w['description'] ?? '') ?></textarea></div>
    </div>
    <?php if (!$o): ?>
    <h3 style="margin:14px 0 6px;"><?= tt('pd.units') ?></h3>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('pd.new_serials') ?></label><textarea name="new_serials" rows="4" data-plain placeholder="<?= tt('pd.new_serials_ph') ?>"></textarea><p class="hint"><?= tt('pd.new_serials_hint') ?></p></div>
      <div class="grid grid-2" style="gap:8px;">
        <div class="field"><label><?= tt('eq.maker') ?></label><input name="new_maker" value="Golden A/S" maxlength="60"></div>
        <div class="field"><label><?= tt('eq.model') ?></label><input name="new_model" maxlength="120"></div>
        <div class="field"><label><?= tt('eq.kind') ?></label><select name="new_kind" data-nosearch><?php foreach (eq_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'switchboard' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('eq.set') ?></label><input name="new_set" maxlength="80" placeholder="<?= tt('pd.set_ph') ?>"></div>
      </div>
    </div>
    <?php endif; ?>
    <div class="btn-row" style="margin-top:12px;"><button class="btn btn--gold" type="submit"><?= $o ? tt('btn.save') : tt('pd.create') ?></button><a class="btn btn--ghost" href="<?= $o ? url('production_order', ['id' => $id]) : url('production') ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var S = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'p' => (int)$s['project_id'], 'l' => trim(($s['code'] ?: '') . ' ' . $s['name'])], $subs), JSON_UNESCAPED_UNICODE) ?>;
  var WK = <?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 's' => (int)$x['subproject_id'], 'l' => trim($x['wp_no'] . ' ' . $x['title'])], $works), JSON_UNESCAPED_UNICODE) ?>;
  var ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id'), ws = document.getElementById('work_id'), vs = document.getElementById('vessel_id'), curS = <?= $curS ?>, curW = <?= $curW ?>;
  function fs(){ var p = +ps.value; ss.innerHTML = '<option value="">—</option>'; S.filter(function(r){ return r.p === p; }).forEach(function(r){ var o = document.createElement('option'); o.value = r.id; o.textContent = r.l; if (r.id === curS) o.selected = true; ss.appendChild(o); }); fw(); }
  function fw(){ var s = +ss.value; ws.innerHTML = '<option value="">—</option>'; WK.filter(function(r){ return r.s === s; }).forEach(function(r){ var o = document.createElement('option'); o.value = r.id; o.textContent = r.l; if (r.id === curW) o.selected = true; ws.appendChild(o); }); }
  function fv(force){ var o = ps.selectedOptions[0], pv = o && o.dataset.v ? +o.dataset.v : 0;
    Array.prototype.forEach.call(vs.options, function(op){ op.hidden = !!(pv && op.value && +op.value !== pv); });   // R41-004: only the vessel of the project
    if (pv && (force || !vs.value || +vs.value !== pv)) vs.value = String(pv); }
  ps.addEventListener('change', function(){ curS = 0; curW = 0; fs(); fv(true); }); ss.addEventListener('change', function(){ curW = 0; fw(); });
  fs(); fv(false);
})();
</script>
<?php layout_foot(); ?>

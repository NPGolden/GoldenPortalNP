<?php
/** Raise or edit a ticket.   GAS-CP-R14-001
 *   ?p=ticket_edit[&vessel=ID][&project=ID]   new      ?p=ticket_edit&id=ID   edit */
require __DIR__ . '/_layout.php';
$u  = require_login();
if (!tk_has()) redirect('tickets');
$id = (int)($_GET['id'] ?? 0);
$t  = $id ? tk_load($id) : null;
if ($id && !$t) { http_response_code(404); exit(t('err.notfound')); }
if ($t && !is_staff() && ((int)$t['created_by'] !== (int)$u['id'] || !in_array($t['status'], tk_open_statuses(), true))) redirect('ticket', ['id' => $id]);
[$vscope, $vargs] = tk_scope();
$vessels = all("SELECT v.id, v.name, v.imo FROM vessels v WHERE {$vscope} AND (v.is_active = 1 OR v.id = ?) ORDER BY v.name", array_merge($vargs, [$t ? (int)$t['vessel_id'] : 0]));   // the ticket's own vessel stays selectable even when inactive (R29-010)
$vids = array_map(fn($v) => (int)$v['id'], $vessels) ?: [0];
$projects = all('SELECT id, vessel_id, project_code, name FROM projects WHERE vessel_id IN (' . implode(',', $vids) . ') ORDER BY project_code, name');
$subs = all('SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE p.vessel_id IN (' . implode(',', $vids) . ') ORDER BY s.sort_order, s.code');
$systems = all('SELECT id, abbr, name FROM vessel_systems WHERE is_active = 1 ORDER BY sort_order, name');
$curV = $t ? (int)$t['vessel_id'] : (int)($_GET['vessel'] ?? 0);
$curP = $t ? (int)$t['project_id'] : (int)($_GET['project'] ?? 0);
if ($curP && !$curV) foreach ($projects as $p) if ((int)$p['id'] === $curP) $curV = (int)$p['vessel_id'];
$curS = $t ? (int)$t['subproject_id'] : 0;
$v = fn(string $k, $d = '') => e((string)($t[$k] ?? $d));
$title = $t ? t('tk.edit') . ' ' . $t['ticket_no'] : t('tk.new');
layout_head($title, 'tickets');
?>
<p class="eyebrow"><a href="<?= url('tickets') ?>"><?= tt('tk.title') ?></a></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= is_staff() ? tt('tk.lede_form_staff') : tt('tk.lede_form') ?></p>

<div class="card" style="max-width:860px;">
  <form method="post" action="<?= url('ticket_edit', $id ? ['id' => $id] : []) ?>" enctype="multipart/form-data" data-lock id="tk-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="tk_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label for="vessel_id"><?= tt('v.one') ?> *</label>
        <select id="vessel_id" name="vessel_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($vessels as $x): ?><option value="<?= $x['id'] ?>"<?= $curV === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['name']) ?><?= $x['imo'] ? ' · ' . e($x['imo']) : '' ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="project_id"><?= tt('pr.one') ?></label><select id="project_id" name="project_id"><option value=""><?= tt('tk.generic') ?></option></select><p class="hint" style="margin:4px 0 0;" id="pr-hint"><?= tt('tk.generic_hint') ?></p><p class="hint" style="margin:4px 0 0;color:var(--red,#9B2C2C);" id="pr-none" hidden><?= tt('tk.form_no_projects') ?> <a href="<?= url('projects') ?>" target="_blank" rel="noopener"><?= tt('nav.projects') ?></a></p></div>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id"><option value="">—</option></select></div>
    </div>
    <div class="field"><label for="title"><?= tt('tk.title_f') ?> *</label><input id="title" name="title" required maxlength="160" value="<?= $v('title') ?>" placeholder="<?= tt('tk.title_ph') ?>"></div>
    <div class="grid grid-3">
      <div class="field"><label for="category"><?= tt('tk.category') ?></label><select id="category" name="category"><?php foreach (tk_categories() as $k => $l): ?><option value="<?= $k ?>"<?= ($t['category'] ?? 'fault') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="priority"><?= tt('tk.priority') ?></label><select id="priority" name="priority"><?php foreach (tk_priorities() as $k => $l): ?><option value="<?= $k ?>"<?= ($t['priority'] ?? 'normal') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="system_id"><?= tt('tk.system') ?></label><select id="system_id" name="system_id"><option value="">—</option><?php foreach ($systems as $s): ?><option value="<?= $s['id'] ?>"<?= (int)($t['system_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e(($s['abbr'] ? $s['abbr'] . ' · ' : '') . $s['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="description"><?= tt('tk.description') ?></label><textarea id="description" name="description" rows="8" placeholder="<?= tt('tk.description_ph') ?>"><?= $v('description') ?></textarea></div>
    <div class="field"><label for="files"><?= tt('tk.attachments') ?></label><input id="files" type="file" name="files[]" multiple><p class="hint" style="margin:4px 0 0;"><?= tt('tk.attachments_hint') ?></p></div>
    <div class="btn-row">
      <button class="btn btn--gold" type="submit"><?= $t ? tt('btn.save') : tt('tk.raise') ?></button>
      <a class="btn btn--ghost" href="<?= $id ? url('ticket', ['id' => $id]) : url('tickets') ?>"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var P = <?= json_encode(array_map(fn($p) => ['id' => (int)$p['id'], 'v' => (int)$p['vessel_id'], 'l' => trim(($p['project_code'] ?: '') . ' ' . $p['name'])], $projects), JSON_UNESCAPED_UNICODE) ?>;
  var S = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'p' => (int)$s['project_id'], 'l' => trim(($s['code'] ?: '') . ' ' . $s['name'])], $subs), JSON_UNESCAPED_UNICODE) ?>;
  var vs = document.getElementById('vessel_id'), ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id');
  function fill(sel, rows, cur){ sel.innerHTML = '<option value="">' + (sel === ps ? <?= json_encode(t('tk.generic'), JSON_UNESCAPED_UNICODE) ?> : '—') + '</option>'; rows.forEach(function(r){ var o = document.createElement('option'); o.value = r.id; o.textContent = r.l; if (r.id === cur) o.selected = true; sel.appendChild(o); }); sel.disabled = !rows.length; }
  function onV(cur){ var v = +vs.value; var rows = P.filter(function(r){ return r.v === v; }); fill(ps, rows, cur || 0); onP(0); var none = document.getElementById('pr-none'), gh = document.getElementById('pr-hint'); if (none) { none.hidden = !(v && !rows.length); gh.hidden = !none.hidden; } }
  function onP(cur){ var p = +ps.value; fill(ss, S.filter(function(r){ return r.p === p; }), cur || 0); }
  vs.addEventListener('change', function(){ onV(0); }); ps.addEventListener('change', function(){ onP(0); });
  onV(<?= $curP ?>); onP(<?= $curS ?>);
})();
</script>
<?php layout_foot(); ?>

<?php
/** New / edit task.   GAS-CP-R22-002     ?p=task_edit[&project=ID]   new      ?p=task_edit&id=ID   edit */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!td_has()) redirect('tasks');
$id = (int)($_GET['id'] ?? 0);
$t = $id ? td_load($id) : null;
if ($id && !$t) { http_response_code(404); exit(t('err.notfound')); }
$projects = all("SELECT id, project_code, name, vessel_id FROM projects WHERE status = 'active' OR id = ? OR id = (SELECT project_id FROM offers WHERE id = ?) ORDER BY project_code, name", [(int)($t['project_id'] ?? 0), (int)($_GET['offer'] ?? 0)]);
$subs = all('SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id ORDER BY p.project_code, p.name, s.sort_order, s.code');
$people = staff_users();
$curP = $t ? (int)$t['project_id'] : (int)($_GET['project'] ?? 0); $curS = $t ? (int)$t['subproject_id'] : (int)($_GET['sub'] ?? 0);
$due = $t ? $t['due_at'] : date('Y-m-d') . ' 09:00:00';
$ofOn = function_exists('td_offer_col') && td_offer_col() && of_visible(); $curO = $t ? (int)($t['offer_id'] ?? 0) : (int)($_GET['offer'] ?? 0); $offers = [];
if ($ofOn) { if ($curO && !$t) { $po = one('SELECT project_id, subproject_id FROM offers WHERE id = ?', [$curO]); if ($po) { $curP = $curP ?: (int)$po['project_id']; $curS = $curS ?: (int)($po['subproject_id'] ?? 0); } else $curO = 0; }
             $offers = of_for_task_form(array_column($projects, 'id'), $curO); }
$eqOn = function_exists('eq_links') && eq_links(); $eqAll = []; $eqSel = [];
if ($eqOn) {
    $vids = array_values(array_unique(array_filter(array_map('intval', array_column($projects, 'vessel_id')))));
    if ($vids) $eqAll = all('SELECT e.id, e.serial_no, e.maker, e.family, e.model, e.vessel_id FROM equipment e WHERE e.retired = 0 AND e.vessel_id IN (' . implode(',', $vids) . ') ORDER BY e.serial_no');
    $eqSel = $id ? array_map('intval', array_column(eq_task_units($id), 'id')) : [];
    if (!$id && ($pre = (int)($_GET['eq'] ?? 0)) && ($pe = eq_get($pre))) { $eqSel[] = $pre; if (!$curP && $pe['vessel_id']) { $pp = one("SELECT id FROM projects WHERE vessel_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [(int)$pe['vessel_id']]); if ($pp) $curP = (int)$pp['id']; } if (!in_array($pre, array_map('intval', array_column($eqAll, 'id')), true)) $eqAll[] = $pe + ['vessel_id' => (int)$pe['vessel_id']]; }
}
$days = array_map('intval', array_filter(explode(',', (string)($t['recur_days'] ?? ''))));
$log = $id ? all('SELECT l.*, u.full_name FROM task_log l LEFT JOIN customer_users u ON u.id = l.by_id WHERE l.task_id = ? ORDER BY l.at DESC, l.id DESC LIMIT 40', [$id]) : [];
$v = fn(string $k, $d = '') => e((string)($t[$k] ?? $d));
$title = $t ? t('td.edit') : t('td.new');
layout_head($title, 'tasks');
?>
<p class="eyebrow"><a href="<?= url('tasks') ?>"><?= tt('td.title') ?></a></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= tt('td.lede_form') ?></p>

<div class="cols td-cols">
<div class="card" style="max-width:900px;">
  <form method="post" action="<?= url('task_edit', $id ? ['id' => $id] : []) ?>" data-lock id="td-form">
    <?= csrf_field() ?><?php $rsCur = $t ? (int)($t['rs_contract_id'] ?? 0) : (int)($_GET['rs'] ?? 0); $rsC = $rsCur && function_exists('rs_get') ? rs_get($rsCur) : null; ?><?php if ($rsC): ?><input type="hidden" name="rs_contract_id" value="<?= $rsCur ?>"><p class="hint" style="margin:0 0 8px;">🛡 <?= tt('rs.task_linked') ?> <a href="<?= url('rs', ['id' => $rsCur]) ?>"><?= e($rsC['contract_no']) ?> · <?= e($rsC['vessel']) ?></a></p><?php endif; ?><input type="hidden" name="action" value="td_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label for="title"><?= tt('td.title_f') ?> *</label><input id="title" name="title" required maxlength="200" value="<?= $v('title') ?>" placeholder="<?= tt('td.title_ph') ?>"></div>
    <div class="grid grid-3">
      <div class="field"><label for="kind"><?= tt('td.kind') ?></label><select id="kind" name="kind"><?php foreach (td_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= ($t['kind'] ?? 'general') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?><?php if (is_cp_admin() && td_kind_table()): ?><option value="__new"><?= tt('tdk.new_opt') ?></option><?php endif; ?></select>
        <?php if (is_cp_admin() && td_kind_table()): ?><input id="new_kind" name="new_kind" maxlength="80" placeholder="<?= tt('tdk.label_ph') ?>" hidden style="margin-top:6px;"><p class="hint" style="margin:4px 0 0;"><a href="<?= url('admin_task_kinds') ?>"><?= tt('tdk.manage') ?> ›</a></p><?php endif; ?></div>
      <div class="field"><label for="project_id"><?= tt('pr.one') ?></label><select id="project_id" name="project_id"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $curP === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id"><option value="">—</option><?php $pn = array_column($projects, null, 'id'); $grp = null; foreach ($subs as $sx): if (!isset($pn[$sx['project_id']])) continue; if ($grp !== (int)$sx['project_id']): if ($grp !== null) echo '</optgroup>'; $grp = (int)$sx['project_id']; $pp = $pn[$grp]; echo '<optgroup label="' . e(trim(($pp['project_code'] ?: '') . ' ' . $pp['name'])) . '" data-p="' . $grp . '">'; endif; ?><option value="<?= $sx['id'] ?>" data-p="<?= (int)$sx['project_id'] ?>"<?= $curS === (int)$sx['id'] ? ' selected' : '' ?>><?= e(trim(($sx['code'] ?: '') . ' ' . $sx['name'])) ?></option><?php endforeach; if ($grp !== null) echo '</optgroup>'; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('td.sub_hint') ?></p></div>
    </div>
    <div class="grid grid-3">
      <div class="field"><label for="due_date"><?= tt('td.due_date') ?> *</label><input id="due_date" name="due_date" type="date" required value="<?= e(substr($due, 0, 10)) ?>"></div>
      <div class="field"><label for="due_time"><?= tt('td.due_time') ?></label><input id="due_time" name="due_time" type="time" value="<?= e(substr($due, 11, 5)) ?>"></div>
      <div class="field"><label for="remind_before"><?= tt('td.reminder') ?></label><select id="remind_before" name="remind_before"><?php foreach (td_reminders() as $m => $l): ?><option value="<?= $m ?>"<?= (int)($t['remind_before'] ?? 0) === $m ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <fieldset class="td-recur">
      <legend>↻ <?= tt('td.recurrence') ?></legend>
      <div class="grid grid-3">
        <div class="field"><label for="recur"><?= tt('td.repeat') ?></label><select id="recur" name="recur"><?php foreach (td_recurs() as $k => $l): ?><option value="<?= $k ?>"<?= ($t['recur'] ?? 'none') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field td-r td-r--not-none"><label for="recur_every"><?= tt('td.every') ?></label><div class="td-every"><input id="recur_every" name="recur_every" type="number" min="1" max="99" value="<?= (int)($t['recur_every'] ?? 1) ?>"><span id="every_unit"></span></div></div>
        <div class="field td-r td-r--not-none"><label for="recur_end"><?= tt('td.ends') ?></label><input id="recur_end" name="recur_end" type="date" value="<?= $v('recur_end') ?>"><p class="hint" style="margin:4px 0 0;"><?= tt('td.ends_hint') ?></p></div>
      </div>
      <div class="field td-r td-r--weekly"><label><?= tt('td.on_days') ?></label>
        <div class="td-days"><?php foreach (td_weekdays() as $n => $l): ?><label class="td-day"><input type="checkbox" name="recur_days[]" value="<?= $n ?>"<?= in_array($n, $days, true) ? ' checked' : '' ?>><span><?= e($l) ?></span></label><?php endforeach; ?></div>
      </div>
      <div class="field td-r td-r--monthly" style="max-width:260px;"><label for="recur_dom"><?= tt('td.day_of_month') ?></label><select id="recur_dom" name="recur_dom"><?php for ($d = 1; $d <= 31; $d++): ?><option value="<?= $d ?>"<?= (int)($t['recur_dom'] ?? (int) date('j', strtotime($due))) === $d ? ' selected' : '' ?>><?= $d === 31 ? e(t('td.last_day')) : $d ?></option><?php endfor; ?></select></div>
      <p class="hint td-r td-r--not-none" id="recur_preview"></p>
    </fieldset>
    <?php if ($ofOn && $offers): ?>
    <div class="field"><label for="offer_id"><?= tt('of.one') ?></label><select id="offer_id" name="offer_id"><option value="">—</option><?php foreach ($offers as $of): ?><option value="<?= (int)$of['id'] ?>" data-p="<?= (int)$of['project_id'] ?>"<?= (int)$of['id'] === $curO ? ' selected' : '' ?>><?= e($of['offer_no'] . ' ' . $of['revision'] . ' — ' . $of['title']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('ofn.task_offer_hint') ?></p></div>
    <?php endif; ?>
    <?php if ($eqOn && $eqAll): ?>
    <div class="field"><label><?= tt('eq.task_units') ?> <span class="hint" id="eq-vessel"></span></label>
      <p class="hint" id="eq-none" hidden><?= tt('eq.task_pick_project') ?></p>
      <div class="eq-pick" id="eq-pick"><?php foreach ($eqAll as $eu): ?><label class="chk" data-v="<?= (int)$eu['vessel_id'] ?>"><input type="checkbox" name="eq_ids[]" value="<?= (int)$eu['id'] ?>"<?= in_array((int)$eu['id'], $eqSel, true) ? ' checked' : '' ?>> <b><?= e($eu['serial_no']) ?></b> <span class="hint"><?= e(trim($eu['maker'] . ' ' . ($eu['family'] ?: $eu['model'] ?: ''))) ?></span></label><?php endforeach; ?></div>
      <p class="hint"><?= tt('eq.task_hint') ?></p></div>
    <?php endif; ?>
    <div class="grid grid-2">
      <div class="field"><label for="assignee_id"><?= tt('td.assignee') ?></label><select id="assignee_id" name="assignee_id"><option value=""><?= tt('td.team') ?></option><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"<?= (int)($t['assignee_id'] ?? 0) === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?><?= $p['role_name'] ? ' · ' . e($p['role_name']) : '' ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('td.team_hint') ?></p></div>
      <div class="field"><label for="priority"><?= tt('tk.priority') ?></label><select id="priority" name="priority"><?php foreach (['low' => t('td.p_low'), 'normal' => t('td.p_normal'), 'high' => t('td.p_high')] as $k => $l): ?><option value="<?= $k ?>"<?= ($t['priority'] ?? 'normal') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="notes"><?= tt('td.notes') ?></label><textarea id="notes" name="notes" rows="4" maxlength="4000" placeholder="<?= tt('td.notes_ph') ?>"><?= $v('notes') ?></textarea></div>
    <div class="btn-row">
      <button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button>
      <a class="btn btn--ghost" href="<?= url('tasks') ?>"><?= tt('btn.cancel') ?></a>
    </div>
  </form>
  <?php if ($t && (is_cp_admin() || (int)$t['owner_id'] === (int)$u['id'])): ?>
  <form method="post" action="<?= url('task_edit', ['id' => $id]) ?>" style="margin-top:14px;" data-js-confirm="<?= e(t('td.delete_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="td_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm btn--danger" type="submit">🗑 <?= tt('td.delete') ?></button></form>
  <?php endif; ?>
</div>
<?php if ($t): ?>
<div class="card card--tight">
  <h3 class="ac-h" style="margin-top:0;"><?= tt('td.history') ?></h3>
  <p class="hint" style="margin:0 0 8px;"><?= e(t('td.created_by', ['n' => $t['owner'] ?: '—', 'd' => fmt_datetime($t['created_at'])])) ?><?= $t['status'] === 'done' ? ' · ' . e(t('td.done_on', ['d' => fmt_datetime($t['done_at'])])) : '' ?></p>
  <?php if (!$log): ?><p class="hint"><?= tt('vh.none') ?></p><?php else: ?>
  <ul class="td-log"><?php foreach ($log as $l): ?><li><time><?= e(fmt_datetime($l['at'])) ?></time> <b><?= e(t('td.ev_' . $l['event'])) ?></b><?= $l['due_at'] ? ' · ' . e(fmt_datetime($l['due_at'])) : '' ?><?= $l['full_name'] ? ' · ' . e($l['full_name']) : '' ?></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id');
  // sub-project list is complete in the HTML (grouped per project); choosing a project narrows it, choosing a sub-project sets its project
  function narrow() { var p = ps.value; ss.querySelectorAll('optgroup').forEach(function (g) { g.hidden = !!p && g.getAttribute('data-p') !== p; }); var o = ss.selectedOptions[0]; if (o && o.value && p && o.getAttribute('data-p') !== p) ss.value = ''; }
  var PV = <?= json_encode(array_map('intval', array_column($projects, 'vessel_id', 'id'))) ?>, VN = <?= json_encode(array_column(all('SELECT id, name FROM vessels'), 'name', 'id'), JSON_UNESCAPED_UNICODE) ?>, ep = document.getElementById('eq-pick'), en = document.getElementById('eq-none'), ev = document.getElementById('eq-vessel');
  // the register lists the units of every active project's vessel; only the units of the chosen project's vessel are offered (ticked ones always stay visible)
  function eqNarrow() { if (!ep) return; var v = String(PV[ps.value] || 0), shown = 0;
    ep.querySelectorAll('label').forEach(function (l) { var on = l.querySelector('input').checked; l.hidden = !(on || (v !== '0' && l.getAttribute('data-v') === v)); if (!l.hidden) shown++; });
    if (en) en.hidden = shown > 0; if (ev) ev.textContent = v !== '0' && VN[v] ? '· ' + VN[v] : ''; }
  var os = document.getElementById('offer_id');
  function ofNarrow() { if (!os) return; os.querySelectorAll('option[data-p]').forEach(function (o) { o.hidden = !!ps.value && o.getAttribute('data-p') !== ps.value; }); var c = os.selectedOptions[0]; if (c && c.value && ps.value && c.getAttribute('data-p') !== ps.value) os.value = ''; }
  if (os) os.addEventListener('change', function () { var c = os.selectedOptions[0]; if (c && c.value && ps.value !== c.getAttribute('data-p')) { ps.value = c.getAttribute('data-p'); narrow(); eqNarrow(); } });
  ofNarrow();
  ps.addEventListener('change', function () { narrow(); eqNarrow(); ofNarrow(); }); ss.addEventListener('change', eqNarrow); window.addEventListener('pageshow', eqNarrow); eqNarrow();
  var kd = document.getElementById('kind'), nk = document.getElementById('new_kind'); if (nk) { kd.addEventListener('change', function () { nk.hidden = kd.value !== '__new'; if (!nk.hidden) nk.focus(); }); }
  ss.addEventListener('change', function () { var o = ss.selectedOptions[0]; if (o && o.value && ps.value !== o.getAttribute('data-p')) { ps.value = o.getAttribute('data-p'); narrow(); } });
  narrow();
  var rc = document.getElementById('recur'), units = <?= json_encode(['daily' => t('td.u_days'), 'weekly' => t('td.u_weeks'), 'monthly' => t('td.u_months'), 'yearly' => t('td.u_years')], JSON_UNESCAPED_UNICODE) ?>;
  var L = <?= json_encode(['none' => t('td.r_none'), 'daily' => t('td.r_daily'), 'weekly' => t('td.r_weekly'), 'monthly' => t('td.r_monthly'), 'yearly' => t('td.r_yearly'), 'every_daily' => t('td.r_every_daily', ['n' => '{n}']), 'every_weekly' => t('td.r_every_weekly', ['n' => '{n}']), 'every_monthly' => t('td.r_every_monthly', ['n' => '{n}']), 'every_yearly' => t('td.r_every_yearly', ['n' => '{n}']), 'on_day' => t('td.on_day', ['n' => '{n}']), 'last' => t('td.last_day'), 'until' => t('td.until', ['d' => '{d}']), 'preview' => t('td.preview')], JSON_UNESCAPED_UNICODE) ?>;
  var wd = <?= json_encode(array_values(td_weekdays()), JSON_UNESCAPED_UNICODE) ?>;
  function upd(){
    var r = rc.value; document.querySelectorAll('.td-r').forEach(function(el){ el.style.display = 'none'; });
    if (r !== 'none') document.querySelectorAll('.td-r--not-none').forEach(function(el){ el.style.display = ''; });
    document.querySelectorAll('.td-r--' + r).forEach(function(el){ el.style.display = ''; });
    document.getElementById('every_unit').textContent = units[r] || '';
    var n = +document.getElementById('recur_every').value || 1, s = '';
    if (r !== 'none') {
      s = n === 1 ? L[r] : L['every_' + r].replace('{n}', n);
      if (r === 'weekly') { var ds = []; document.querySelectorAll('input[name="recur_days[]"]:checked').forEach(function(c){ ds.push(wd[+c.value - 1]); }); if (ds.length) s += ' · ' + ds.join(' '); }
      if (r === 'monthly') { var d = +document.getElementById('recur_dom').value; s += ' · ' + (d === 31 ? L.last : L.on_day.replace('{n}', d)); }
      var e = document.getElementById('recur_end').value; if (e) s += ' · ' + L.until.replace('{d}', e);
    }
    document.getElementById('recur_preview').textContent = s ? L.preview + ': ' + s : '';
  }
  document.getElementById('td-form').addEventListener('change', upd); document.getElementById('recur_every').addEventListener('input', upd); upd();
})();
</script>
<?php layout_foot(); ?>

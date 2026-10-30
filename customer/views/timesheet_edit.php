<?php
/**
 * Timesheet form — one sheet per engineer, project and period; any number
 * of lines (date · sub-project or main project · type · hours · what).
 *   ?p=timesheet_edit[&project=ID]   new draft
 *   ?p=timesheet_edit&id=ID          edit a draft / a rejected sheet      GAS-CP-R11-001
 */
require __DIR__ . '/_layout.php';
$u  = require_staff();
$id = (int)($_GET['id'] ?? 0);
$t  = $id ? ts_load($id) : null;
if ($id && !$t) { http_response_code(404); exit(t('err.notfound')); }
if ($t && !in_array($t['status'], ['draft', 'rejected'], true)) redirect('timesheet', ['id' => $id]);
if ($t && (int)$t['user_id'] !== (int)$u['id'] && !is_cp_admin()) redirect('timesheet', ['id' => $id]);
$pid = $t ? (int)$t['project_id'] : (int)($_GET['project'] ?? 0);
$projects = all("SELECT p.id, p.project_code, p.name, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.status <> 'completed' OR p.id = ? ORDER BY p.project_code", [$pid]);
$subsAll = all("SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE p.status <> 'completed' OR p.id = ? ORDER BY s.project_id, s.sort_order, s.code", [$pid]);
if (tm_restricted() && !$t) {                                                   // R25-001: an engineer books only the active sub-projects he is assigned to
    $mySubs = tm_my_subs(); $myIds = array_map('intval', array_column($mySubs, 'id')); $myPids = array_unique(array_map('intval', array_column($mySubs, 'project_id')));
    $projects = array_values(array_filter($projects, fn($p) => in_array((int)$p['id'], $myPids, true)));
    $subsAll = array_values(array_filter($subsAll, fn($s) => in_array((int)$s['id'], $myIds, true)));
    if ($pid && !in_array($pid, $myPids, true)) $pid = 0;
}
$tmOnly = tm_restricted() && !$t;                                              // no "main project" line for a limited engineer
if (!$pid && $projects) $pid = (int)$projects[0]['id'];
$subsBy = [];
foreach ($subsAll as $s) $subsBy[(int)$s['project_id']][] = ['id' => (int)$s['id'], 'label' => trim($s['code'] . ' ' . $s['name'])];
$me  = one('SELECT cu.*, d.name AS discipline_name FROM customer_users cu LEFT JOIN disciplines d ON d.id = cu.discipline_id WHERE cu.id = ?', [(int)$u['id']]);
$mgr = ts_manager_for($me ?: $u);
$start = $t['period_start'] ?? date('Y-m-d', strtotime('monday this week'));
$end   = $t['period_end'] ?? date('Y-m-d', strtotime($start . ' +6 days'));
$lines = $t['lines'] ?? [];
$wpOn = function_exists('wp_has') && wp_has(); $worksBy = [];                       // R35-001: line → work package
if ($wpOn) foreach (all("SELECT w.id, w.subproject_id, w.wp_no, w.title FROM sp_works w JOIN subprojects s ON s.id = w.subproject_id JOIN projects p ON p.id = s.project_id WHERE (p.status <> 'completed' OR p.id = ?) AND (w.status IN ('planned','assigned','in_progress') OR EXISTS (SELECT 1 FROM timesheet_lines l WHERE l.work_id = w.id AND l.timesheet_id = ?)) ORDER BY w.wp_no", [$pid, $id]) as $wk) $worksBy[(int)$wk['subproject_id']][] = ['id' => (int)$wk['id'], 'label' => $wk['wp_no'] . ' · ' . $wk['title']];
if (!$t && !empty($_GET['from_plan']) && function_exists('pl_grid')) {               // pre-fill the week from the planning
    $ps = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['start'] ?? '')) ? $_GET['start'] : $start; $pe = date('Y-m-d', strtotime($ps . ' +6 days'));
    $cal = function_exists('wc_user_calendar') ? wc_user_calendar((int)$u['id']) : 'GR';
    foreach (pl_grid($ps, $pe, (int)$u['id'])[(int)$u['id']] ?? [] as $date => $es) foreach ($es as $e) { if ($e['kind'] !== 'work' || (int)$e['project_id'] !== $pid) continue;
        $h = $e['hours'] !== null ? (float)$e['hours'] : (function_exists('wc_day') ? (float)wc_day($cal, $date)['normal'] / max(1, count(array_filter($es, fn($x) => $x['kind'] === 'work'))) : 8.0);
        $lines[] = ['work_date' => $date, 'subproject_id' => (int)($e['subproject_id'] ?? 0), 'work_id' => (int)($e['work_id'] ?? 0), 'hour_type' => 'normal', 'hours' => $h, 'description' => (string)($e['work_title'] ?? $e['note'] ?? '')]; }
    $start = $ps; $end = $pe;
}
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$title = $t ? t('ts.edit') . ' ' . $t['ts_no'] : t('ts.new');
layout_head($title, 'timesheets');
?>
<p class="eyebrow"><a href="<?= url('timesheets') ?>"><?= tt('ts.title') ?></a></p>
<div class="page-head">
  <div><h1><?= e($title) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($u['full_name']) ?><?= !empty($me['discipline_name']) ? ' · ' . e($me['discipline_name']) : '' ?> · <?= $mgr ? e(t('ts.approver_is', ['name' => $mgr['full_name']])) : '<span class="is-late">' . tt('ts.no_manager_short') . '</span>' ?></p></div>
  <?php if ($t && $t['status'] === 'rejected'): ?><div class="flash flash--bad" style="margin:0;max-width:460px;"><?= e(t('ts.revising', ['note' => $t['approval_note'] ?? ''])) ?></div><?php endif; ?>
</div>
<?php if (!ts_has()): ?><div class="flash flash--warn"><?= tt('ts.need_r11') ?></div><?php endif; ?>
<?php if (tm_restricted() && !$projects): ?><div class="flash flash--warn"><?= tt('tm.none_assigned') ?></div><?php elseif (tm_restricted() && !$t): ?><p class="hint" style="margin:-6px 0 10px;"><?= tt('tm.form_hint') ?></p><?php endif; ?>

<form method="post" action="<?= url('timesheet_edit', $id ? ['id' => $id] : []) ?>" data-lock data-busy="<?= tt('fsr.saving') ?>" id="tsform">
  <?= csrf_field() ?><input type="hidden" name="action" value="ts_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="card">
    <div class="grid grid-3">
      <div class="field"><label for="project_id"><?= tt('pr.one') ?></label>
        <select id="project_id" name="project_id" required><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $sel($p['id'], $pid) ?>><?= e(($p['project_code'] ?: '') . ' ' . $p['name'] . ($p['vessel'] ? ' · ' . $p['vessel'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="period_start"><?= tt('ts.period_start') ?></label><input id="period_start" name="period_start" type="date" required value="<?= e($start) ?>"></div>
      <div class="field"><label for="period_end"><?= tt('ts.period_end') ?></label><input id="period_end" name="period_end" type="date" required value="<?= e($end) ?>"></div>
    </div>
    <div class="field"><label for="note"><?= tt('ts.note') ?></label><input id="note" name="note" maxlength="1000" value="<?= e($t['note'] ?? '') ?>" placeholder="<?= tt('ts.note_ph') ?>"></div>
  </div>

  <div class="card">
    <div class="page-head" style="margin-bottom:8px;">
      <div><h2 style="margin:0;"><?= tt('ts.lines') ?></h2><p class="hint" style="margin:4px 0 0;"><?= tt('ts.lines_hint') ?></p>
        <?php if (function_exists('wc_has') && wc_has()): $wcCode = wc_user_calendar((int)$u['id']); $wcCal = wc_calendars()[$wcCode] ?? null; $wcH = wc_hours($wcCode); $wcDays = array_filter($wcH, fn($r) => $r['is_workday']); $wcFirst = reset($wcDays); ?>
        <p class="hint ts-calnote" style="margin:4px 0 0;">🕒 <?= e(t('wc.ts_note', ['c' => $wcCal ? $wcCal['code'] . ' · ' . $wcCal['name'] : $wcCode, 'h' => $wcFirst ? substr((string)$wcFirst['start_time'], 0, 5) . '–' . substr((string)$wcFirst['end_time'], 0, 5) . ' (' . ts_fmt((float)$wcFirst['normal_hours']) . ' h)' : '—'])) ?></p>
        <?php endif; ?></div>
      <div class="ts-totals">
        <span><?= tt('ts.normal') ?> <b id="tot-normal">0</b></span><span class="ts-ot"><?= tt('ts.overtime') ?> <b id="tot-overtime">0</b></span><span class="ts-hol"><?= tt('ts.holiday') ?> <b id="tot-holiday">0</b></span><span class="ts-sum"><?= tt('ts.total') ?> <b id="tot-all">0</b></span>
      </div>
    </div>
    <div class="table-scroll"><table class="ts-lines" id="ts-lines">
      <thead><tr><th style="width:150px;"><?= tt('ts.date') ?></th><th><?= tt('ts.on') ?></th><th style="width:150px;"><?= tt('ts.type') ?></th><th style="width:90px;" class="num"><?= tt('ts.hours') ?></th><th><?= tt('ts.what') ?></th><th style="width:40px;"></th></tr></thead>
      <tbody id="ts-body">
        <?php
        $engOn = function_exists('eng_has') && eng_has();
        $lineRow = function (int $i, ?array $ln) use ($pid, $subsBy, $sel, $start, $wpOn, $worksBy, $tmOnly, $engOn, $me): string {
            $pSel = $ln ? (int)($ln['_pid'] ?? $pid) : $pid;
            $h = '<tr data-line="' . $i . '">'
               . '<td><input name="ln_date[' . $i . ']" type="date" required value="' . e($ln['work_date'] ?? $start) . '"></td>'
               . '<td><select name="ln_sub[' . $i . ']" class="js-sub">' . ($tmOnly ? '' : '<option value="0">' . tt('ts.main_project') . '</option>');
            foreach ($subsBy[$pSel] ?? [] as $s) $h .= '<option value="' . $s['id'] . '"' . $sel($s['id'], $ln['subproject_id'] ?? 0) . '>' . e($s['label']) . '</option>';
            $h .= '</select>';
            if ($wpOn) { $h .= '<select name="ln_work[' . $i . ']" class="js-work" data-nosearch style="margin-top:4px;"><option value="0">' . tt('wp.no_work') . '</option>'; foreach ($worksBy as $wsid => $ws) foreach ($ws as $wk) $h .= '<option value="' . $wk['id'] . '" data-sub="' . $wsid . '"' . $sel($wk['id'], $ln['work_id'] ?? 0) . ((int)$wsid !== (int)($ln['subproject_id'] ?? 0) ? ' hidden' : '') . '>' . e($wk['label']) . '</option>'; $h .= '</select>'; }
            $h .= '</td><td><select name="ln_type[' . $i . ']" class="js-type">';
            foreach (ts_types() as $k => $l) $h .= '<option value="' . $k . '"' . $sel($k, $ln['hour_type'] ?? 'normal') . '>' . e($l) . '</option>';
            $h .= '</select>';
            if ($engOn) { $cur = $ln['activity'] ?? eng_default_activity((int)($me['discipline_id'] ?? 0) ?: null, (int)($ln['subproject_id'] ?? 0) ?: null); $h .= '<select name="ln_act[' . $i . ']" class="js-act" data-nosearch style="margin-top:4px;" title="' . tt('eng.activity_hint') . '">'; foreach (eng_activity_choices((int)($me['discipline_id'] ?? 0) ?: null) as $ak) $h .= '<option value="' . $ak . '"' . $sel($ak, $cur) . '>' . e(t('eng.a_' . $ak)) . '</option>'; $h .= '</select>'; }   // R47-001 / R48-001: the stages of the engineer's discipline + site
            $h .= '</td>'
               . '<td class="num"><input name="ln_hours[' . $i . ']" type="number" min="0.25" max="24" step="0.25" class="js-h" required value="' . e($ln ? ts_fmt((float)$ln['hours']) : '') . '" placeholder="8"></td>'
               . '<td><input name="ln_desc[' . $i . ']" maxlength="500" value="' . e($ln['description'] ?? '') . '" placeholder="' . tt('ts.what_ph') . '">' . (!empty($ln['auto_type']) ? '<span class="pill pill--gold" style="margin-left:6px;" title="' . tt('wc.auto_hint') . '">' . tt('wc.auto') . '</span>' : '') . '</td>'
               . '<td><button type="button" class="btn btn--ghost btn--sm btn--danger-ghost js-del-line" title="' . tt('btn.delete') . '">✕</button></td></tr>';
            return $h;
        };
        if ($lines) foreach ($lines as $i => $ln) echo $lineRow($i, $ln);
        else echo $lineRow(0, null);
        ?>
      </tbody>
    </table></div>
    <div class="btn-row" style="margin-top:10px;">
      <button class="btn btn--ghost" type="button" id="js-add-line">+ <?= tt('ts.add_line') ?></button>
      <button class="btn btn--ghost" type="button" id="js-add-week"><?= tt('ts.add_week') ?></button>
      <?php if ($wpOn && !$t): ?><a class="btn btn--ghost" href="<?= url('timesheet_edit', ['project' => $pid, 'start' => $start, 'from_plan' => 1]) ?>">🗓 <?= tt('wp.from_plan') ?></a><?php endif; ?>
      <span class="hint" id="js-line-count"></span>
    </div>
    <template id="ts-line-tpl"><?= $lineRow(999999, null) ?></template>
    <script nonce="<?= csp_nonce() ?>">document.addEventListener('change', function (e) { var s = e.target; if (!s.classList || !s.classList.contains('js-sub')) return; var w = s.parentNode.querySelector('.js-work'); if (!w) return; var v = s.value; Array.prototype.forEach.call(w.options, function (o) { if (!o.dataset.sub) return; o.hidden = o.dataset.sub !== v; }); if (w.selectedOptions[0] && w.selectedOptions[0].hidden) w.value = '0'; });
window.__subsBy = <?= json_encode($subsBy) ?>; window.__mainLabel = <?= json_encode(t('ts.main_project')) ?>; window.__tmOnly = <?= $tmOnly ? 'true' : 'false' ?>;</script>
  </div>

  <div class="card">
    <div class="btn-row">
      <button class="btn btn--ghost" type="submit" name="mode" value="draft"><?= tt('ts.save_draft') ?></button>
      <button class="btn btn--gold" type="submit" name="mode" value="submit"
              data-confirm="<?= e($mgr ? t('ts.submit_confirm', ['name' => $mgr['full_name']]) : t('ts.submit_confirm_nomgr')) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('ts.submit') ?></button>
      <a class="btn btn--ghost" href="<?= $id ? url('timesheet', ['id' => $id]) : url('timesheets') ?>"><?= tt('btn.cancel') ?></a>
    </div>
    <p class="hint" style="margin-top:8px;"><?= tt('ts.submit_hint') ?></p>
  </div>
</form>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var form = document.getElementById('tsform'), body = document.getElementById('ts-body'), tpl = document.getElementById('ts-line-tpl'), max = <?= (int)TS_MAX_LINES ?>;
  var next = 0; body.querySelectorAll('tr[data-line]').forEach(function (tr) { next = Math.max(next, +tr.dataset.line + 1); });
  function totals() {
    var t = { normal: 0, overtime: 0, holiday: 0 };
    body.querySelectorAll('tr[data-line]').forEach(function (tr) { var h = parseFloat(tr.querySelector('.js-h').value) || 0; t[tr.querySelector('.js-type').value] += h; });
    var fmt = function (n) { return (Math.round(n * 100) / 100).toString(); };
    document.getElementById('tot-normal').textContent = fmt(t.normal); document.getElementById('tot-overtime').textContent = fmt(t.overtime);
    document.getElementById('tot-holiday').textContent = fmt(t.holiday); document.getElementById('tot-all').textContent = fmt(t.normal + t.overtime + t.holiday);
    var n = body.querySelectorAll('tr[data-line]').length; document.getElementById('js-line-count').textContent = <?= json_encode(t('ts.lines_n', ['n' => '#', 'm' => TS_MAX_LINES])) ?>.replace('#', n);
    body.querySelectorAll('.js-del-line').forEach(function (b) { b.style.visibility = n > 1 ? '' : 'hidden'; });
  }
  function addLine(date) {
    if (body.querySelectorAll('tr[data-line]').length >= max) return null;
    var w = document.createElement('tbody'); w.innerHTML = tpl.innerHTML.replace(/999999/g, next++);
    var tr = w.firstElementChild; body.appendChild(tr);
    var last = tr.previousElementSibling;
    if (last) { tr.querySelector('input[type=date]').value = date || last.querySelector('input[type=date]').value; tr.querySelector('.js-sub').value = last.querySelector('.js-sub').value; var la = last.querySelector('.js-act'), ta = tr.querySelector('.js-act'); if (la && ta) ta.value = la.value; }
    else if (date) tr.querySelector('input[type=date]').value = date;
    totals(); return tr;
  }
  document.getElementById('js-add-line').addEventListener('click', function () { var tr = addLine(); if (tr) tr.querySelector('.js-h').focus(); });
  document.getElementById('js-add-week').addEventListener('click', function () {       // Mon–Fri of the period, 8 h each, main project
    var s = document.getElementById('period_start').value; if (!s) return;
    var d = new Date(s + 'T00:00:00'), e = new Date((document.getElementById('period_end').value || s) + 'T00:00:00');
    var first = body.querySelector('tr[data-line]'); var firstEmpty = first && !first.querySelector('.js-h').value;
    for (var k = 0; k < 7 && d <= e; k++, d.setDate(d.getDate() + 1)) {
      if (d.getDay() === 0 || d.getDay() === 6) continue;
      var iso = d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2), tr = firstEmpty ? first : addLine(iso); firstEmpty = false; if (!tr) break;
      tr.querySelector('input[type=date]').value = iso; if (!tr.querySelector('.js-h').value) tr.querySelector('.js-h').value = 8;
    }
    totals();
  });
  body.addEventListener('click', function (e) { var b = e.target.closest('.js-del-line'); if (!b) return; b.closest('tr').remove(); totals(); });
  form.addEventListener('input', totals); form.addEventListener('change', totals);
  // project change -> sub-project lists follow
  document.getElementById('project_id').addEventListener('change', function () {
    var list = window.__subsBy[this.value] || [];
    body.querySelectorAll('.js-sub').forEach(function (sel) {
      sel.innerHTML = (window.__tmOnly ? '' : '<option value="0">' + window.__mainLabel + '</option>') + list.map(function (s) { return '<option value="' + s.id + '">' + s.label.replace(/</g, '&lt;') + '</option>'; }).join('');
    });
    var t = tpl.content.querySelector('.js-sub'); if (t) t.innerHTML = (window.__tmOnly ? '' : '<option value="0">' + window.__mainLabel + '</option>') + list.map(function (s) { return '<option value="' + s.id + '">' + s.label.replace(/</g, '&lt;') + '</option>'; }).join('');
  });
  totals();
})();
</script>
<?php layout_foot(); ?>

<?php
/**
 * Staff: fill in a Field Service Report (form SF-GLD-014).     GAS-CP-R9-002
 *   ?p=fsr_edit&project=ID[&sub=ID][&jo=ID]   new draft
 *   ?p=fsr_edit&id=ID                         edit a draft / revise a sent-back one
 * One block per task — as many as needed (+ Add task / ✕ Remove task):
 * description, spare parts with type of supply, open issues, the
 * engineers' hours, and the task's photos with captions.
 */
require __DIR__ . '/_layout.php';
$u  = require_staff();
$id = (int)($_GET['id'] ?? 0);
$r  = $id ? fsr_load($id) : null;
if ($id && !$r) { http_response_code(404); exit(t('err.notfound')); }
if ($r && !in_array($r['status'], ['draft', 'disputed'], true)) redirect('fsr', ['id' => $id]);

$ext = null;                                                                     // R38-003: a service call on a vessel (no project) and / or a conversion of an external report
if (!$r && fsr_ext_has() && (int)($_GET['from_ext'] ?? 0)) { $ext = one("SELECT * FROM fsr_reports WHERE id = ? AND kind = 'external' AND converted_id IS NULL", [(int)$_GET['from_ext']]); if (!$ext) { http_response_code(404); exit(t('err.notfound')); } }
$pid = $r ? (int)$r['project_id'] : ($ext ? (int)$ext['project_id'] : (int)($_GET['project'] ?? 0));
if (!$pid && !$r && (int)($_GET['vessel'] ?? 0)) $pid = fsr_project_for_vessel((int)$_GET['vessel']);
$sid = $r ? (int)($r['subproject_id'] ?? 0) : ($ext ? (int)($ext['subproject_id'] ?? 0) : (int)($_GET['sub'] ?? 0));
$joId = $r ? (int)($r['job_order_id'] ?? 0) : (int)($_GET['jo'] ?? 0);
$tkId = $r ? (int)($r['ticket_id'] ?? 0) : (int)($_GET['ticket'] ?? 0);
$tkOpts = tk_options_for_project($pid);
$p = one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
if (!$p) { http_response_code(404); exit(t('err.notfound')); }
$subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
$tmIds = $r ? null : tm_allowed_ids($pid);                                        // R25-001: only the assigned active sub-projects
if ($tmIds !== null) { $subs = array_values(array_filter($subs, fn($s) => in_array((int)$s['id'], $tmIds, true))); if (!$subs) { http_response_code(403); layout_head(t('err.noaccess'), 'projects'); echo '<div class="flash flash--warn">' . tt('tm.none_assigned_project') . '</div>'; layout_foot(); return; } }
$jos  = all("SELECT id, jo_no, title, status FROM job_orders WHERE project_id = ? AND status IN ('approved','in_progress','done') ORDER BY id DESC", [$pid]);
$engineers = staff_users();
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$v = fn(string $k, $d = '') => e((string)($r[$k] ?? ($ext[$k] ?? $d)));
$works = $r['works'] ?? [];
$title = $r ? t('fsr.edit') . ' ' . $r['report_no'] : t('fsr.new');
layout_head($title, 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('fsr.title') ?></p>
<div class="page-head">
  <div><h1><?= e($title) ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('fsr.form_lede') ?></p></div>
  <?php if ($r && $r['status'] === 'disputed'): ?><div class="flash flash--bad" style="margin:0;max-width:420px;"><?= e(t('fsr.revising', ['remark' => $r['customer_remark'] ?? ''])) ?></div><?php endif; ?>
</div>

<form method="post" action="<?= url('fsr_edit', $id ? ['id' => $id] : ['project' => $pid]) ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('fsr.saving') ?>" id="fsrform">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="fsr_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $pid ?>"><?php if ($ext): ?><input type="hidden" name="source_report_id" value="<?= (int)$ext['id'] ?>"><?php endif; ?>
  <?php if ($ext): ?><div class="flash flash--warn">📎 <?= e(t('fsr.converting', ['no' => $ext['report_no']])) ?><?= $ext['ext_file_id'] ? ' <a href="' . url('file', ['id' => $ext['ext_file_id']]) . '" target="_blank">⤓ ' . tt('fsr.ext_file') . '</a>' : '' ?><?= $ext['ext_summary'] ? '<br><span class="hint">' . e($ext['ext_summary']) . '</span>' : '' ?><?= $ext['ext_engineers'] ? '<br><span class="hint">' . tt('fsr.ext_engineers') . ': ' . e($ext['ext_engineers']) . '</span>' : '' ?></div><?php endif; ?>

  <div class="card">
    <div class="fsr-head">
      <div><h2 style="margin:0;"><?= tt('fsr.title') ?></h2><span class="hint" style="font-family:var(--mono);"><?= FSR_FORM_CODE ?><?= $r ? ' · ' . e($r['report_no']) : '' ?></span></div>
      <img src="../assets/golden-logo.png" alt="Golden Automation Systems" style="height:34px;">
    </div>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('fsr.date') ?></label><input name="report_date" type="date" required value="<?= $v('report_date', date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('fsr.pic') ?></label><input name="pic_name" maxlength="120" value="<?= $v('pic_name') ?>" placeholder="<?= tt('fsr.pic_ph') ?>"></div>
      <div class="field"><label><?= tt('fsr.customer') ?></label><input name="customer_name" maxlength="160" value="<?= $v('customer_name', $p['customer_name']) ?>"></div>
      <div class="field"><label><?= tt('fsr.position') ?></label><input name="pic_position" maxlength="120" value="<?= $v('pic_position') ?>"></div>
      <div class="field"><label><?= tt('fsr.vessel') ?></label><input name="vessel_name" maxlength="120" value="<?= $v('vessel_name', $p['vessel'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('fsr.mobile') ?></label><input name="pic_mobile" maxlength="40" value="<?= $v('pic_mobile') ?>"></div>
      <div class="field"><label><?= tt('fsr.location') ?></label><input name="location" maxlength="160" value="<?= $v('location') ?>" placeholder="e.g. HALKITIS SHIPYARD"></div>
      <div class="field"><label><?= tt('fsr.email') ?></label><input name="pic_email" type="email" maxlength="190" value="<?= $v('pic_email') ?>"></div>
      <div class="field"><label><?= tt('sp.one') ?></label>
        <select name="subproject_id"><?php if ($tmIds === null || $r): ?><option value=""><?= tt('fsr.whole_project') ?></option><?php endif; ?>
          <?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($s['id'], $sid) ?>><?= e($s['code'] . ' ' . $s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php if (function_exists('eq_has') && eq_has()): $eqUnits = eq_for_vessel((int)($p['vessel_id'] ?? 0)); $eqSel = $id ? array_map('intval', array_column(eq_fsr_units($id), 'id')) : []; ?>
      <div class="field" style="grid-column: 1 / -1;"><label><?= tt('eq.fsr_units') ?></label>
        <?php if ($eqUnits): ?><div class="eq-pick"><?php foreach ($eqUnits as $eu): ?><label class="chk"><input type="checkbox" name="eq_ids[]" value="<?= (int)$eu['id'] ?>"<?= in_array((int)$eu['id'], $eqSel, true) ? ' checked' : '' ?>> <b><?= e($eu['serial_no']) ?></b> <span class="hint"><?= e(trim($eu['maker'] . ' ' . ($eu['family'] ?: $eu['model'] ?: ''))) ?></span></label><?php endforeach; ?></div><?php endif; ?>
        <input name="eq_new" maxlength="300" placeholder="<?= tt('eq.fsr_new_ph') ?>" style="margin-top:6px;"><p class="hint"><?= tt('eq.fsr_hint') ?></p></div>
      <?php endif; ?>
      <div class="field"><label><?= tt('jo.one') ?></label>
        <select name="job_order_id"><option value=""><?= tt('jo.none') ?></option>
          <?php foreach ($jos as $j): ?><option value="<?= $j['id'] ?>"<?= $sel($j['id'], $joId) ?>><?= e($j['jo_no'] . ' — ' . $j['title']) ?></option><?php endforeach; ?>
        </select><p class="hint"><?= tt('jo.fsr_hint') ?></p></div>
    </div>
    <?php if ($tkOpts): ?><div class="field"><label><?= tt('tk.related') ?></label>
      <select name="ticket_id"><option value=""><?= tt('tk.none') ?></option><?php foreach ($tkOpts as $tk): ?><option value="<?= $tk['id'] ?>"<?= $sel($tk['id'], $tkId) ?>><?= e($tk['ticket_no'] . ' — ' . $tk['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
  </div>

  <div class="card">
    <h2><?= tt('fsr.works') ?></h2>
    <p class="hint"><?= tt('fsr.tasks_hint') ?></p>
    <datalist id="engineers"><?php foreach ($engineers as $en): ?><option value="<?= e($en['full_name']) ?>"><?php endforeach; ?></datalist>
    <datalist id="specialties"><?php foreach (fsr_specialties() as $sp): ?><option value="<?= e($sp) ?>"><?php endforeach; ?></datalist>
    <?php
    /* one task block; $i is the form index (any integer), $wk the stored task or null */
    $taskBlock = function (int $i, ?array $wk) use ($u, $sel): void {
        $parts = $wk['parts'] ?? []; $lines = $wk['lines'] ?? []; $photos = $wk['photos'] ?? []; ?>
      <details class="fsr-work" open data-task="<?= $i ?>">
        <summary><span class="fsr-task-no"><?= e(t('fsr.task_n', ['n' => '#'])) ?></span><?= $wk && $wk['description'] ? ' — <span class="hint">' . e(mb_substr((string)$wk['description'], 0, 60)) . '</span>' : '' ?>
          <button class="btn btn--ghost btn--sm btn--danger-ghost js-del-task" type="button" title="<?= tt('fsr.task_del') ?>">✕ <?= tt('fsr.task_del') ?></button></summary>
        <input type="hidden" name="wk_orig[<?= $i ?>]" value="<?= (int)($wk['seq'] ?? 0) ?>">
        <div class="grid grid-2">
          <div class="field"><label><?= tt('fsr.description') ?></label><textarea name="wk_desc[<?= $i ?>]" rows="7"><?= e($wk['description'] ?? '') ?></textarea></div>
          <div>
            <div class="field"><label><?= tt('fsr.parts') ?></label>
              <?php for ($j = 0; $j < FSR_MAX_PARTS; $j++): $pt = $parts[$j] ?? ['d' => '', 't' => 'I']; ?>
                <div class="fsr-part"><input name="wk_part[<?= $i ?>][<?= $j ?>]" maxlength="120" value="<?= e($pt['d']) ?>" placeholder="<?= tt('fsr.part_ph') ?>">
                  <select name="wk_ptype[<?= $i ?>][<?= $j ?>]" title="<?= tt('fsr.legend_w') ?>"><?php foreach (fsr_part_types() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $pt['t']) ?>><?= $l ?></option><?php endforeach; ?></select></div>
              <?php endfor; ?>
              <p class="hint"><?= tt('fsr.legend_w') ?></p></div>
            <div class="field"><label><?= tt('fsr.issues') ?></label><textarea name="wk_issues[<?= $i ?>]" rows="2"><?= e($wk['open_issues'] ?? '') ?></textarea></div>
          </div>
        </div>
        <div class="field"><label><?= tt('fsr.personnel') ?></label>
          <div class="table-scroll"><table class="fsr-lines">
            <thead><tr><th><?= tt('fsr.engineer') ?></th><th><?= tt('fsr.specialty') ?></th><th><?= tt('fsr.start') ?></th><th><?= tt('fsr.end') ?></th><th title="<?= tt('fsr.types_hint') ?>"><?= tt('fsr.type') ?> <span class="hint" style="font-weight:400;">ⓘ</span></th><th><?= tt('fsr.hours') ?></th></tr></thead>
            <tbody>
            <?php for ($k = 0; $k < FSR_MAX_LINES; $k++): $ln = $lines[$k] ?? null; ?>
              <tr>
                <td><input name="wk_eng[<?= $i ?>][<?= $k ?>]" list="engineers" maxlength="120" value="<?= e($ln['engineer'] ?? (($i === 0 && $k === 0 && !$wk) ? $u['full_name'] : '')) ?>" placeholder="<?= tt('fsr.engineer_ph') ?>"></td>
                <td><input name="wk_spec[<?= $i ?>][<?= $k ?>]" list="specialties" maxlength="80" value="<?= e($ln['specialty'] ?? 'Service Engineer') ?>"></td>
                <td><input name="wk_start[<?= $i ?>][<?= $k ?>]" type="time" class="js-t" value="<?= e(fsr_fmt_time($ln['start_time'] ?? null)) ?>"></td>
                <td><input name="wk_end[<?= $i ?>][<?= $k ?>]" type="time" class="js-t" value="<?= e(fsr_fmt_time($ln['end_time'] ?? null)) ?>"></td>
                <td><select name="wk_type[<?= $i ?>][<?= $k ?>]"><?php foreach (fsr_time_types() as $tk => $tl): ?><option value="<?= $tk ?>" title="<?= e(fsr_time_type_label($tk)) ?>"<?= $sel($tk, $ln['time_type'] ?? 'WT') ?>><?= $tl ?></option><?php endforeach; ?></select></td>
                <td class="num js-h"><?= $ln ? e(fsr_fmt_hours((float)$ln['hours'])) : '0:00' ?></td>
              </tr>
            <?php endfor; ?>
            </tbody>
          </table></div>
          <p class="hint"><?= tt('fsr.legend_tt') ?></p>
        </div>
        <div class="field"><label><?= tt('fsr.photos') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(t('fsr.photos_hint', ['n' => FSR_MAX_PHOTOS])) ?></span></label>
          <?php if ($photos): ?>
            <div class="fsr-photos">
              <?php foreach ($photos as $ph): ?>
                <figure><img src="<?= url('file', ['id' => $ph['id']]) ?>" alt="">
                  <figcaption><input name="photo_caption[<?= (int)$ph['id'] ?>]" maxlength="160" value="<?= e($ph['title'] ?? '') ?>" placeholder="<?= e(t('fsr.caption_ph', ['n' => $ph['figure']])) ?>">
                    <button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit" name="del_photo" value="<?= (int)$ph['id'] ?>" formnovalidate title="<?= tt('btn.delete') ?>">✕</button></figcaption></figure>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="fsr-newphotos" data-task="<?= $i ?>">
            <input name="photos[<?= $i ?>][]" type="file" accept="image/*" multiple class="js-photos">
            <div class="js-captions"></div>
          </div>
        </div>
      </details>
    <?php };
    $n = max(1, count($works));
    for ($i = 0; $i < $n; $i++) $taskBlock($i, $works[$i] ?? null);
    ?>
    <div id="fsr-tasks-end"></div>
    <div class="btn-row" style="margin-top:4px;">
      <button class="btn btn--ghost" type="button" id="js-add-task">+ <?= tt('fsr.task_add') ?></button>
      <span class="hint" id="js-task-count"></span>
    </div>
    <template id="fsr-task-tpl"><?php ob_start(); $taskBlock(999999, null); echo str_replace('999999', '__I__', ob_get_clean()); ?></template>
  </div>

  <div class="card">
    <div class="btn-row">
      <button class="btn btn--ghost" type="submit" name="mode" value="draft"><?= tt('fsr.save_draft') ?></button>
      <button class="btn btn--gold" type="submit" name="mode" value="submit"
              data-confirm="<?= tt('fsr.submit_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('fsr.submit') ?></button>
      <a class="btn btn--ghost" href="<?= $id ? url('fsr', ['id' => $id]) : url('fsr_list', ['project' => $pid]) ?>"><?= tt('btn.cancel') ?></a>
    </div>
    <p class="hint" style="margin-top:8px;"><?= tt('fsr.submit_hint') ?></p>
  </div>
</form>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var form = document.getElementById('fsrform'), addBtn = document.getElementById('js-add-task'), endMark = document.getElementById('fsr-tasks-end'),
      tpl = document.getElementById('fsr-task-tpl'), counter = document.getElementById('js-task-count'), maxTasks = <?= (int)FSR_MAX_WORKS ?>;
  var next = 0; form.querySelectorAll('details.fsr-work').forEach(function (d) { next = Math.max(next, +d.dataset.task + 1); });

  function hours(tr) {   // live hours per line
    var s = tr.querySelector('input[name^=wk_start]').value, e = tr.querySelector('input[name^=wk_end]').value, out = tr.querySelector('.js-h');
    if (!s || !e) { out.textContent = '0:00'; return; }
    var a = s.split(':'), b = e.split(':'), m = (+b[0] * 60 + +b[1]) - (+a[0] * 60 + +a[1]); if (m < 0) m += 1440;
    out.textContent = Math.floor(m / 60) + ':' + ('0' + (m % 60)).slice(-2);
  }
  form.addEventListener('input', function (e) {
    if (e.target.classList.contains('js-t')) hours(e.target.closest('tr'));
  });
  // a caption field for every photo chosen
  form.addEventListener('change', function (e) {
    if (!e.target.classList.contains('js-photos')) return;
    var inp = e.target, box = inp.closest('.fsr-newphotos'), caps = box.querySelector('.js-captions'), t = box.dataset.task;
    caps.innerHTML = '';
    Array.prototype.forEach.call(inp.files, function (f, n) {
      var d = document.createElement('div'); d.className = 'fsr-cap';
      d.innerHTML = '<span class="hint">' + f.name.replace(/</g, '&lt;') + '</span><input name="new_caption[' + t + '][' + n + ']" maxlength="160" placeholder="<?= e(t('fsr.caption_new')) ?>">';
      caps.appendChild(d);
    });
  });
  // task numbers follow the blocks on screen
  function renumber() {
    var blocks = form.querySelectorAll('details.fsr-work'), label = <?= json_encode(t('fsr.task_n', ['n' => '#'])) ?>;
    blocks.forEach(function (d, n) { d.querySelector('.fsr-task-no').textContent = label.replace('#', n + 1); d.querySelector('.js-del-task').style.visibility = blocks.length > 1 ? '' : 'hidden'; });
    counter.textContent = <?= json_encode(t('fsr.task_count', ['n' => '#', 'm' => FSR_MAX_WORKS])) ?>.replace('#', blocks.length);
    addBtn.disabled = blocks.length >= maxTasks;
  }
  addBtn.addEventListener('click', function () {
    if (form.querySelectorAll('details.fsr-work').length >= maxTasks) return;
    var html = tpl.innerHTML.replace(/__I__/g, next++), wrap = document.createElement('div'); wrap.innerHTML = html;
    var d = wrap.firstElementChild; endMark.parentNode.insertBefore(d, endMark); renumber();
    d.scrollIntoView({ behavior: 'smooth', block: 'start' }); var ta = d.querySelector('textarea'); if (ta) ta.focus();
  });
  // remove a task (the styled confirmation dialog; its photos go with it on save)
  form.addEventListener('click', function (e) {
    var b = e.target.closest('.js-del-task'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    var d = b.closest('details.fsr-work'), no = d.querySelector('.fsr-task-no').textContent;
    var filled = Array.prototype.some.call(d.querySelectorAll('textarea, input[type=text], input:not([type])'), function (i) { return i.value.trim() !== ''; }) || d.querySelector('.fsr-photos');
    var go = function () { d.remove(); renumber(); };
    if (!filled) { go(); return; }
    var msg = <?= json_encode(t('fsr.task_del_confirm')) ?>.replace('{task}', no);
    if (typeof window.gasConfirm === 'function') window.gasConfirm({ title: <?= json_encode(t('dlg.title')) ?>, message: msg, ok: <?= json_encode(t('btn.delete')) ?>, cancel: <?= json_encode(t('btn.cancel')) ?>, danger: true }, go);
    else if (window.confirm(msg)) go();
  });
  // the toggle arrow of <details> must not fire on the delete button
  form.querySelectorAll('summary').forEach(function (sm) { sm.addEventListener('keydown', function (e) { if (e.target.closest('.js-del-task') && (e.key === ' ' || e.key === 'Enter')) e.stopPropagation(); }); });
  renumber();
})();
</script>
<?php layout_foot(); ?>

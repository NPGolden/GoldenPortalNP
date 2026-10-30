<?php
/**
 * Staff: fill in an Inspection Report (form SF-GLD-020).          GAS-CP-R23-003
 *   ?p=isr_edit&project=ID[&sub=ID]   new draft
 *   ?p=isr_edit&id=ID                 edit a draft / one sent back for correction
 * Cover data, the three introductory texts and one block per inspected system
 * (+ Add system / ✕ Remove system): system from the list or "Other…" with a free
 * description, condition as found, checklist (pre-filled from the template's
 * question bank), class regulations, risk level + analysis, proposed corrective
 * actions and attachments with captions.
 */
require __DIR__ . '/_layout.php';
$u  = require_staff();
$id = (int)($_GET['id'] ?? 0);
$r  = $id ? isr_load($id) : null;
if ($id && !$r) { http_response_code(404); exit(t('err.notfound')); }
if ($r && $r['status'] === 'issued') redirect('isr', ['id' => $id]);

$pid = $r ? (int)$r['project_id'] : (int)($_GET['project'] ?? 0);
$sid = $r ? (int)($r['subproject_id'] ?? 0) : (int)($_GET['sub'] ?? 0);
$p = one('SELECT p.*, v.name AS vessel, v.imo, v.yard, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]);
if (!$p) { http_response_code(404); exit(t('err.notfound')); }
$subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
$tmIds = $r ? null : tm_allowed_ids($pid);                                        // R25-001: only the assigned active sub-projects
if ($tmIds !== null) { $subs = array_values(array_filter($subs, fn($s) => in_array((int)$s['id'], $tmIds, true))); if (!$subs) { http_response_code(403); layout_head(t('err.noaccess'), 'projects'); echo '<div class="flash flash--warn">' . tt('tm.none_assigned_project') . '</div>'; layout_foot(); return; } }
$systems = isr_system_options();
$levels = isr_risk_levels();
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$v = fn(string $k, $d = '') => e((string)($r[$k] ?? $d));
$items = $r['items'] ?? [];
$classFlag = ''; $builderYear = (string)($p['yard'] ?? '');
$title = $r ? t('isr.edit') . ' ' . $r['report_no'] : t('isr.new');
layout_head($title, 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('isr.title') ?></p>
<div class="page-head">
  <div><h1><?= e($title) ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('isr.form_lede') ?></p></div>
  <?php if ($r && $r['status'] === 'checking'): ?><div class="flash flash--warn" style="margin:0;max-width:420px;"><?= tt('isr.editing_checking') ?></div><?php endif; ?>
</div>

<form method="post" action="<?= url('isr_edit', $id ? ['id' => $id] : ['project' => $pid]) ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('fsr.saving') ?>" id="isrform">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="isr_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $pid ?>">

  <div class="card">
    <div class="fsr-head">
      <div><h2 style="margin:0;"><?= tt('isr.title') ?></h2><span class="hint" style="font-family:var(--mono);"><?= ISR_FORM_CODE ?><?= $r ? ' · ' . e($r['report_no']) : ' · ' . tt('isr.no_auto') ?></span></div>
      <img src="../assets/golden-logo.png" alt="Golden Automation Systems" style="height:34px;">
    </div>
    <div class="grid grid-2">
      <div class="field"><label><?= tt('fsr.date') ?></label><input name="report_date" type="date" required value="<?= $v('report_date', date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('isr.performed') ?></label><input name="performed_name" maxlength="120" value="<?= $v('performed_name', $u['full_name']) ?>"></div>
      <div class="field"><label><?= tt('fsr.customer') ?></label><input name="customer_name" maxlength="160" value="<?= $v('customer_name', $p['customer_name']) ?>"></div>
      <div class="field"><label><?= tt('fsr.pic') ?></label><input name="pic_name" maxlength="120" value="<?= $v('pic_name') ?>" placeholder="<?= tt('fsr.pic_ph') ?>"></div>
      <div class="field"><label><?= tt('fsr.vessel') ?></label><input name="vessel_name" maxlength="120" value="<?= $v('vessel_name', $p['vessel'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('fsr.location') ?></label><input name="location" maxlength="160" value="<?= $v('location') ?>" placeholder="e.g. Piraeus anchorage"></div>
      <div class="field"><label><?= tt('isr.class_flag') ?></label><input name="class_flag" maxlength="120" value="<?= $v('class_flag', $classFlag) ?>" placeholder="e.g. ABS / Liberia"></div>
      <div class="field"><label><?= tt('isr.builder_year') ?></label><input name="builder_year" maxlength="120" value="<?= $v('builder_year', $builderYear) ?>" placeholder="e.g. Hyundai Mipo / 2012"></div>
      <div class="field"><label><?= tt('isr.hull_no') ?></label><input name="hull_no" maxlength="60" value="<?= $v('hull_no') ?>"></div>
      <div class="field"><label><?= tt('sp.one') ?></label>
        <select name="subproject_id"><?php if ($tmIds === null || $r): ?><option value=""><?= tt('fsr.whole_project') ?></option><?php endif; ?>
          <?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($s['id'], $sid) ?>><?= e($s['code'] . ' ' . $s['name']) ?></option><?php endforeach; ?>
        </select></div>
      <?php if (function_exists('eq_links') && eq_links()): $eqUnits = eq_for_vessel((int)($p['vessel_id'] ?? 0)); $eqSel = $id ? array_map('intval', array_column(eq_isr_units($id), 'id')) : []; ?>
      <div class="field" style="grid-column: 1 / -1;"><label><?= tt('eq.isr_units') ?></label>
        <?php if ($eqUnits): ?><div class="eq-pick"><?php foreach ($eqUnits as $eu): ?><label class="chk"><input type="checkbox" name="eq_ids[]" value="<?= (int)$eu['id'] ?>"<?= in_array((int)$eu['id'], $eqSel, true) ? ' checked' : '' ?>> <b><?= e($eu['serial_no']) ?></b> <span class="hint"><?= e(trim($eu['maker'] . ' ' . ($eu['family'] ?: $eu['model'] ?: ''))) ?></span></label><?php endforeach; ?></div><?php endif; ?>
        <input name="eq_new" maxlength="300" placeholder="<?= tt('eq.fsr_new_ph') ?>" style="margin-top:6px;"><p class="hint"><?= tt('eq.isr_hint') ?></p></div>
      <?php endif; ?>
    </div>
    <div class="field"><label><?= tt('isr.subject') ?></label><input name="subject" maxlength="200" required value="<?= $v('subject') ?>" placeholder="<?= tt('isr.subject_ph') ?>"></div>
  </div>

  <div class="card">
    <h2><?= tt('isr.intro') ?></h2>
    <div class="field"><label><?= tt('isr.objective') ?></label><textarea name="objective" rows="4" placeholder="<?= tt('isr.objective_ph') ?>"><?= $v('objective') ?></textarea></div>
    <div class="field"><label><?= tt('isr.findings') ?></label><textarea name="findings" rows="5" placeholder="<?= tt('isr.findings_ph') ?>"><?= $v('findings') ?></textarea></div>
    <div class="field"><label><?= tt('isr.risks') ?></label><textarea name="risks" rows="4" placeholder="<?= tt('isr.risks_ph') ?>"><?= $v('risks') ?></textarea></div>
  </div>

  <div class="card">
    <h2><?= tt('isr.systems') ?></h2>
    <p class="hint"><?= tt('isr.systems_hint') ?></p>
    <?php
    /* one system block; $i is the form index (any integer), $it the stored item or null */
    $sysBlock = function (int $i, ?array $it) use ($systems, $levels, $sel): void {
        $files = $it['files'] ?? []; $chk = $it['checklist'] ?? [];
        $name = (string)($it['system_name'] ?? ''); $sysId = (int)($it['system_id'] ?? 0);
        $known = false; foreach ($systems as $s) if (($sysId && $s['id'] === $sysId) || (!$sysId && strcasecmp($s['name'], $name) === 0)) { $known = true; break; }
        $pick = $it ? ($known ? ($sysId ? 'id:' . $sysId : 'n:' . $name) : '__other') : ''; ?>
      <details class="fsr-work isr-sys" open data-task="<?= $i ?>">
        <summary><span class="fsr-task-no"><?= e(t('isr.system_n', ['n' => '#'])) ?></span><span class="hint js-sum"><?= $name !== '' ? ' — ' . e($name) : '' ?></span>
          <button class="btn btn--ghost btn--sm btn--danger-ghost js-del-task" type="button" title="<?= tt('isr.del_system') ?>">✕ <?= tt('isr.del_system') ?></button></summary>
        <input type="hidden" name="it_orig[<?= $i ?>]" value="<?= (int)($it['seq'] ?? 0) ?>">
        <div class="grid grid-2">
          <div class="field"><label><?= tt('isr.system') ?></label>
            <select class="js-sys" aria-label="<?= tt('isr.system') ?>">
              <option value=""><?= tt('isr.pick_system') ?></option>
              <?php foreach ($systems as $s): $val = $s['id'] ? 'id:' . $s['id'] : 'n:' . $s['name']; ?><option value="<?= e($val) ?>" data-id="<?= (int)$s['id'] ?>" data-name="<?= e($s['name']) ?>"<?= $sel($val, $pick) ?>><?= e($s['name']) ?><?= $s['abbr'] ? ' (' . e($s['abbr']) . ')' : '' ?></option><?php endforeach; ?>
              <option value="__other"<?= $sel('__other', $pick) ?>><?= tt('isr.other') ?>…</option>
            </select>
            <input type="hidden" name="it_sys[<?= $i ?>]" value="<?= $known ? $sysId : 0 ?>" class="js-sysid">
            <input name="it_name[<?= $i ?>]" maxlength="160" value="<?= e($name) ?>" placeholder="<?= tt('isr.other_ph') ?>" class="js-sysname" style="margin-top:6px;<?= $pick === '__other' ? '' : 'display:none;' ?>"></div>
          <div class="field"><label><?= tt('isr.risk_level') ?></label>
            <select name="it_level[<?= $i ?>]" class="js-level"><?php foreach ($levels as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $it['risk_level'] ?? 'none') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label><?= tt('isr.condition') ?></label><textarea name="it_cond[<?= $i ?>]" rows="4" placeholder="<?= tt('isr.condition_ph') ?>"><?= e($it['condition_found'] ?? '') ?></textarea></div>
        <div class="field"><label><?= tt('isr.checklist') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= tt('isr.checklist_hint') ?></span></label>
          <div class="isr-chk" data-task="<?= $i ?>">
            <?php foreach ($chk as $j => $qa): ?>
              <div class="isr-qa"><input name="it_q[<?= $i ?>][<?= $j ?>]" maxlength="1000" value="<?= e($qa['q'] ?? '') ?>" placeholder="<?= tt('isr.q_ph') ?>"><input name="it_a[<?= $i ?>][<?= $j ?>]" maxlength="1000" value="<?= e($qa['a'] ?? '') ?>" placeholder="<?= tt('isr.a_ph') ?>"><button class="btn btn--ghost btn--sm js-del-q" type="button" title="<?= tt('btn.delete') ?>">✕</button></div>
            <?php endforeach; ?>
          </div>
          <div class="btn-row" style="margin-top:6px;"><button class="btn btn--ghost btn--sm js-add-q" type="button">+ <?= tt('isr.add_q') ?></button><button class="btn btn--ghost btn--sm js-fill-q" type="button" title="<?= tt('isr.fill_hint') ?>">✦ <?= tt('isr.fill_q') ?></button></div>
        </div>
        <div class="grid grid-2">
          <div class="field"><label><?= tt('isr.class_regs') ?></label><textarea name="it_regs[<?= $i ?>]" rows="3" placeholder="<?= tt('isr.class_regs_ph') ?>"><?= e($it['class_regs'] ?? '') ?></textarea></div>
          <div class="field"><label><?= tt('isr.risk_analysis') ?></label><textarea name="it_risk[<?= $i ?>]" rows="3" placeholder="<?= tt('isr.risk_analysis_ph') ?>"><?= e($it['risk_analysis'] ?? '') ?></textarea></div>
        </div>
        <div class="field"><label><?= tt('isr.actions') ?></label><textarea name="it_act[<?= $i ?>]" rows="3" placeholder="<?= tt('isr.actions_ph') ?>"><?= e($it['actions'] ?? '') ?></textarea></div>
        <div class="field"><label><?= tt('isr.attachments') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= tt('isr.attachments_hint') ?></span></label>
          <?php if ($files): ?>
            <div class="fsr-photos">
              <?php foreach ($files as $f): ?>
                <figure><?php if ($f['is_image']): ?><img src="<?= url('file', ['id' => $f['id']]) ?>" alt=""><?php else: ?><div class="isr-doc">📄<br><span><?= e(mb_substr((string)$f['original_name'], 0, 28)) ?></span></div><?php endif; ?>
                  <figcaption><input name="file_caption[<?= (int)$f['id'] ?>]" maxlength="160" value="<?= e($f['title'] ?? '') ?>" placeholder="<?= $f['is_image'] ? e(t('fsr.caption_ph', ['n' => $f['figure']])) : tt('isr.caption_doc') ?>">
                    <button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit" name="del_file" value="<?= (int)$f['id'] ?>" formnovalidate title="<?= tt('btn.delete') ?>">✕</button></figcaption></figure>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="fsr-newphotos" data-task="<?= $i ?>">
            <input name="files[<?= $i ?>][]" type="file" accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.dwg,.dxf,.zip" multiple class="js-photos">
            <div class="js-captions"></div>
          </div>
        </div>
      </details>
    <?php };
    $n = max(1, count($items));
    for ($i = 0; $i < $n; $i++) $sysBlock($i, $items[$i] ?? null);
    ?>
    <div id="isr-sys-end"></div>
    <div class="btn-row" style="margin-top:4px;">
      <button class="btn btn--ghost" type="button" id="js-add-task">+ <?= tt('isr.add_system') ?></button>
      <span class="hint" id="js-task-count"></span>
    </div>
    <template id="isr-sys-tpl"><?php ob_start(); $sysBlock(999999, null); echo str_replace('999999', '__I__', ob_get_clean()); ?></template>
  </div>

  <div class="card">
    <div class="btn-row">
      <button class="btn btn--ghost" type="submit" name="mode" value="draft"><?= tt('fsr.save_draft') ?></button>
      <?php if (!$r || $r['status'] === 'draft'): ?><button class="btn btn--gold" type="submit" name="mode" value="submit"
              data-confirm="<?= tt('isr.submit_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('isr.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('isr.submit') ?></button><?php endif; ?>
      <a class="btn btn--ghost" href="<?= $id ? url('isr', ['id' => $id]) : url('isr_list', ['project' => $pid]) ?>"><?= tt('btn.cancel') ?></a>
    </div>
    <p class="hint" style="margin-top:8px;"><?= tt('isr.submit_hint') ?></p>
  </div>
</form>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var form = document.getElementById('isrform'), addBtn = document.getElementById('js-add-task'), endMark = document.getElementById('isr-sys-end'),
      tpl = document.getElementById('isr-sys-tpl'), counter = document.getElementById('js-task-count'), maxTasks = <?= (int)ISR_MAX_ITEMS ?>,
      bank = <?= json_encode(isr_bank_lc(), JSON_UNESCAPED_UNICODE) ?>, qPh = <?= json_encode(t('isr.q_ph')) ?>, aPh = <?= json_encode(t('isr.a_ph')) ?>;
  var next = 0; form.querySelectorAll('details.isr-sys').forEach(function (d) { next = Math.max(next, +d.dataset.task + 1); });

  function esc(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;'); }
  function qaRow(t, j, q, a) {
    var d = document.createElement('div'); d.className = 'isr-qa';
    d.innerHTML = '<input name="it_q[' + t + '][' + j + ']" maxlength="1000" value="' + esc(q) + '" placeholder="' + esc(qPh) + '"><input name="it_a[' + t + '][' + j + ']" maxlength="1000" value="' + esc(a) + '" placeholder="' + esc(aPh) + '"><button class="btn btn--ghost btn--sm js-del-q" type="button" title="<?= e(t('btn.delete')) ?>">✕</button>';
    return d;
  }
  function renumberQ(box) { var t = box.dataset.task; box.querySelectorAll('.isr-qa').forEach(function (row, j) { row.children[0].name = 'it_q[' + t + '][' + j + ']'; row.children[1].name = 'it_a[' + t + '][' + j + ']'; }); }
  function fill(d, replace) {   // pre-fill the checklist from the template's question bank for the chosen system
    var name = sysName(d).toLowerCase().trim(), qs = bank[name]; if (!qs) return false;
    var box = d.querySelector('.isr-chk'); if (replace) box.innerHTML = '';
    var have = {}; box.querySelectorAll('.isr-qa input:first-child').forEach(function (i) { have[i.value.trim().toLowerCase()] = 1; });
    var j = box.querySelectorAll('.isr-qa').length;
    qs.forEach(function (q) { if (!have[q.toLowerCase()]) box.appendChild(qaRow(d.dataset.task, j++, q, '')); });
    return true;
  }
  function sysName(d) { var s = d.querySelector('.js-sys'), o = s.options[s.selectedIndex]; return s.value === '__other' || !s.value ? d.querySelector('.js-sysname').value : (o.dataset.name || ''); }
  function sysChanged(d) {
    var s = d.querySelector('.js-sys'), o = s.options[s.selectedIndex], nm = d.querySelector('.js-sysname'), idf = d.querySelector('.js-sysid');
    if (s.value === '__other') { nm.style.display = ''; idf.value = 0; nm.focus(); }
    else { nm.style.display = 'none'; nm.value = s.value ? o.dataset.name : ''; idf.value = s.value ? o.dataset.id : 0; }
    d.querySelector('.js-sum').textContent = nm.value ? ' — ' + nm.value : '';
    if (s.value && s.value !== '__other' && !d.querySelector('.isr-qa')) fill(d, false);
  }
  form.addEventListener('change', function (e) {
    if (e.target.classList.contains('js-sys')) { sysChanged(e.target.closest('details')); return; }
    if (!e.target.classList.contains('js-photos')) return;
    var inp = e.target, box = inp.closest('.fsr-newphotos'), caps = box.querySelector('.js-captions'), t = box.dataset.task;
    caps.innerHTML = '';
    Array.prototype.forEach.call(inp.files, function (f, n) {
      var d = document.createElement('div'); d.className = 'fsr-cap';
      d.innerHTML = '<span class="hint">' + esc(f.name) + '</span><input name="new_caption[' + t + '][' + n + ']" maxlength="160" placeholder="<?= e(t('fsr.caption_new')) ?>">';
      caps.appendChild(d);
    });
  });
  form.addEventListener('input', function (e) { if (e.target.classList.contains('js-sysname')) { var d = e.target.closest('details'); d.querySelector('.js-sum').textContent = e.target.value ? ' — ' + e.target.value : ''; } });
  function renumber() {
    var blocks = form.querySelectorAll('details.isr-sys'), label = <?= json_encode(t('isr.system_n', ['n' => '#'])) ?>;
    blocks.forEach(function (d, n) { d.querySelector('.fsr-task-no').textContent = label.replace('#', n + 1); d.querySelector('.js-del-task').style.visibility = blocks.length > 1 ? '' : 'hidden'; });
    counter.textContent = <?= json_encode(t('isr.system_count', ['n' => '#', 'm' => ISR_MAX_ITEMS])) ?>.replace('#', blocks.length);
    addBtn.disabled = blocks.length >= maxTasks;
  }
  addBtn.addEventListener('click', function () {
    if (form.querySelectorAll('details.isr-sys').length >= maxTasks) return;
    var html = tpl.innerHTML.replace(/__I__/g, next++), wrap = document.createElement('div'); wrap.innerHTML = html;
    var d = wrap.firstElementChild; endMark.parentNode.insertBefore(d, endMark); renumber();
    d.scrollIntoView({ behavior: 'smooth', block: 'start' }); d.querySelector('.js-sys').focus();
  });
  form.addEventListener('click', function (e) {
    var b;
    if ((b = e.target.closest('.js-add-q'))) { var box = b.closest('details').querySelector('.isr-chk'); var row = qaRow(box.dataset.task, box.querySelectorAll('.isr-qa').length, '', ''); box.appendChild(row); row.children[0].focus(); return; }
    if ((b = e.target.closest('.js-fill-q'))) { var d0 = b.closest('details'); if (!fill(d0, false)) { if (typeof window.gasToast === 'function') window.gasToast(<?= json_encode(t('isr.no_bank')) ?>); else alert(<?= json_encode(t('isr.no_bank')) ?>); } return; }
    if ((b = e.target.closest('.js-del-q'))) { var box2 = b.closest('.isr-chk'); b.closest('.isr-qa').remove(); renumberQ(box2); return; }
    b = e.target.closest('.js-del-task'); if (!b) return;
    e.preventDefault(); e.stopPropagation();
    var d = b.closest('details.isr-sys'), no = d.querySelector('.fsr-task-no').textContent;
    var filled = Array.prototype.some.call(d.querySelectorAll('textarea, input[type=text], input:not([type])'), function (i) { return i.value.trim() !== ''; }) || d.querySelector('.fsr-photos');
    var go = function () { d.remove(); renumber(); };
    if (!filled) { go(); return; }
    var msg = <?= json_encode(t('isr.del_system_confirm')) ?>.replace('{n}', no);
    if (typeof window.gasConfirm === 'function') window.gasConfirm({ title: <?= json_encode(t('dlg.title')) ?>, message: msg, ok: <?= json_encode(t('btn.delete')) ?>, cancel: <?= json_encode(t('btn.cancel')) ?>, danger: true }, go);
    else if (window.confirm(msg)) go();
  });
  form.querySelectorAll('summary').forEach(function (sm) { sm.addEventListener('keydown', function (e) { if (e.target.closest('.js-del-task') && (e.key === ' ' || e.key === 'Enter')) e.stopPropagation(); }); });
  renumber();
})();
</script>
<?php layout_foot(); ?>

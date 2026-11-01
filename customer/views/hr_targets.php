<?php
/** HR › Targets — targets for the company, departments and individuals per year; progress and status; the evaluation policy note.   GAS-CP-R64-001
 *  ?p=hr_targets[&year=&scope=&department_id=&person_id=&edit=|new=1] — HR / Owner / administrators (everything), managers (their disciplines and people). */
require __DIR__ . '/_layout.php';
$u = require_staff(); $me = (int)$u['id'];
if (!function_exists('hr_targets_has') || !hr_targets_has() || !hr_tg_can_manage()) { http_response_code(403); layout_head(t('hr.targets'), 'hr_targets'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$year = (int)($_GET['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$f = ['year' => $year, 'scope' => (string)($_GET['scope'] ?? ''), 'department_id' => (int)($_GET['department_id'] ?? 0), 'person_id' => (int)($_GET['person_id'] ?? 0), 'status' => (string)($_GET['status'] ?? '')];
$rows = array_values(array_filter(hr_targets($f), fn($t) => hr_tg_can_manage($t)));
$edit = !empty($_GET['edit']) ? hr_target((int)$_GET['edit']) : null; if ($edit && !hr_tg_can_manage($edit)) $edit = null; $new = isset($_GET['new']) || $edit;
if (!$edit && isset($_GET['ai']) && !empty($_SESSION['hr_tg_ai']['rows'][(int)$_GET['ai']])) { $ar = $_SESSION['hr_tg_ai']; $edit = $ar['rows'][(int)$_GET['ai']] + ['id' => 0, 'year' => $ar['year'], 'scope' => $ar['scope'], 'department_id' => $ar['department_id'], 'person_id' => $ar['person_id'], 'progress' => 0, 'status' => 'open', 'result_note' => '']; $new = true; }   // R64-002: edit an assistant proposal before saving
$depts = array_values(array_filter(hr_departments(), fn($d) => hr_can() || (int)($d['head_user_id'] ?? 0) === $me)); $people = array_values(array_filter(hr_people(false), fn($p) => $p['is_active'] && (hr_can() || (int)(hr_manager_of($p) ?? 0) === $me)));
$keep = array_filter($f); $open = count(array_filter($rows, fn($t) => in_array($t['status'], ['open', 'on_track', 'at_risk'], true))); $ach = count(array_filter($rows, fn($t) => $t['status'] === 'achieved')); $risk = count(array_filter($rows, fn($t) => $t['status'] === 'at_risk'));
layout_head(t('hr.targets'), 'hr_targets');
?>
<p class="eyebrow"><a href="<?= url(hr_can() ? 'hr_dashboard' : 'hr_requests') ?>"><?= hr_can() ? tt('hr.dashboard') : tt('hr.requests') ?></a> › <?= tt('hr.targets') ?></p>
<div class="page-head">
  <div><h1>🎯 <?= tt('hr.targets') ?> <span class="hint"><?= $year ?></span></h1><p class="lede"><?= tt('hr.tg_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="<?= url('hr_targets', $keep + ['new' => 1]) ?>#tg-form">+ <?= tt('hr.tg_new') ?></a></div>
</div>
<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat"><b><?= count($rows) ?></b><span class="s"><?= tt('hr.tg_total') ?></span></span>
  <span class="stat navy"><b><?= $open ?></b><span class="s"><?= tt('hr.tg_open') ?></span></span>
  <span class="stat<?= $risk ? ' gold' : '' ?>"><b><?= $risk ?></b><span class="s"><?= tt('hr.tg_s_at_risk') ?></span></span>
  <span class="stat green"><b><?= $ach ?></b><span class="s"><?= tt('hr.tg_s_achieved') ?></span></span>
</div>

<?php if ($new): $e = $edit ?: []; ?>
<div class="card" id="tg-form">
  <h2><?= $edit && !empty($edit['id']) ? tt('btn.edit') . ' · ' . e($edit['title']) : tt('hr.tg_new') ?></h2>
  <form method="post" action="<?= url('hr_targets') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_save"><input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('hr.year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= (int)($e['year'] ?? $year) ?>"></div>
      <div class="field"><label><?= tt('hr.tg_scope') ?></label><select name="scope" id="tg_scope" data-nosearch><?php foreach (['company', 'department', 'person'] as $sc): if ($sc === 'company' && !hr_can()) continue; ?><option value="<?= $sc ?>"<?= ($e['scope'] ?? 'department') === $sc ? ' selected' : '' ?>><?= e(t('hr.tg_sc_' . $sc)) ?></option><?php endforeach; ?></select></div>
      <div class="field tg-dept"><label><?= tt('hr.department') ?></label><select name="department_id" data-nosearch><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($e['department_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field tg-person"><label><?= tt('hr.person') ?></label><select name="person_id"><option value="">—</option><?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= (int)($e['person_id'] ?? 0) === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['name_en'] . ($pp['department'] ? ' · ' . $pp['department'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.tg_title') ?> *</label><input name="title" required maxlength="160" value="<?= e($e['title'] ?? '') ?>" placeholder="<?= tt('hr.tg_title_ph') ?>"></div>
      <div class="field"><label><?= tt('hr.tg_measure') ?></label><input name="measure" maxlength="160" value="<?= e($e['measure'] ?? '') ?>" placeholder="<?= tt('hr.tg_measure_ph') ?>"></div>
      <div class="field"><label><?= tt('hr.tg_value') ?></label><div style="display:flex;gap:4px;"><input name="target_value" maxlength="60" value="<?= e($e['target_value'] ?? '') ?>" placeholder="95"><input name="unit" maxlength="20" value="<?= e($e['unit'] ?? '') ?>" placeholder="%" style="width:70px;"></div></div>
      <div class="field"><label><?= tt('hr.tg_due') ?></label><input type="date" name="due_date" value="<?= e($e['due_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.tg_weight') ?></label><select name="weight" data-nosearch><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)($e['weight'] ?? 1) === $i ? ' selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
      <div class="field"><label><?= tt('hr.tg_progress') ?> %</label><input type="number" name="progress" min="0" max="100" value="<?= (int)($e['progress'] ?? 0) ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (HR_TG_STATUS as $st): ?><option value="<?= $st ?>"<?= ($e['status'] ?? 'open') === $st ? ' selected' : '' ?>><?= e(t('hr.tg_s_' . $st)) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.tg_description') ?></label><textarea data-mt name="description" rows="3" maxlength="4000"><?= e($e['description'] ?? '') ?></textarea></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.tg_result') ?></label><textarea data-mt name="result_note" rows="2" maxlength="4000"><?= e($e['result_note'] ?? '') ?></textarea></div>
    </div>
    <p class="hint"><?= tt('hr.tg_form_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('hr_targets', $keep) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('tg_scope');if(!s)return;function f(){document.querySelector('.tg-dept').style.display=s.value==='department'?'':'none';document.querySelector('.tg-person').style.display=s.value==='person'?'':'none';}s.addEventListener('change',f);f();})();</script>
<?php endif; ?>

<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1.4fr 1.4fr 1fr auto;"><input type="hidden" name="p" value="hr_targets">
    <div class="field"><label><?= tt('hr.year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= $year ?>"></div>
    <div class="field"><label><?= tt('hr.tg_scope') ?></label><select name="scope" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (['company', 'department', 'person'] as $sc): ?><option value="<?= $sc ?>"<?= $f['scope'] === $sc ? ' selected' : '' ?>><?= e(t('hr.tg_sc_' . $sc)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hr.department') ?></label><select name="department_id" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $f['department_id'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hr.person') ?></label><select name="person_id"><option value=""><?= tt('flt.any') ?></option><?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= $f['person_id'] === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (HR_TG_STATUS as $st): ?><option value="<?= $st ?>"<?= $f['status'] === $st ? ' selected' : '' ?>><?= e(t('hr.tg_s_' . $st)) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('hr_targets') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>
<div class="card">
  <h2><?= tt('hr.tg_list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><div class="empty"><?= tt('hr.tg_none') ?></div><?php else: ?>
  <?php foreach ($rows as $tg): ?><form id="tgf<?= (int)$tg['id'] ?>" method="post" action="<?= url('hr_targets', $keep) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_progress"><input type="hidden" name="id" value="<?= (int)$tg['id'] ?>"><input type="hidden" name="year" value="<?= $year ?>"></form><?php endforeach; ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tg_scope') ?></th><th><?= tt('hr.tg_title') ?></th><th><?= tt('hr.tg_measure') ?></th><th><?= tt('hr.tg_due') ?></th><th>⚖</th><th><?= tt('hr.tg_progress') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $tg): $fid = 'tgf' . (int)$tg['id']; ?><tr id="tg<?= (int)$tg['id'] ?>">
    <td><?= $tg['scope'] === 'person' ? pill('gold', (string)$tg['person']) : ($tg['scope'] === 'department' ? pill('navy', (string)$tg['department']) : pill('mute', t('hr.tg_sc_company'))) ?></td>
    <td><a href="<?= url('hr_targets', $keep + ['edit' => (int)$tg['id']]) ?>#tg-form"><b><?= e($tg['title']) ?></b></a><?= $tg['description'] ? '<br><span class="hint">' . e(mb_substr($tg['description'], 0, 140)) . (mb_strlen($tg['description']) > 140 ? '…' : '') . '</span>' : '' ?><?php $log = hr_target_log((int)$tg['id']); if ($log): ?><details><summary class="hint"><?= tt('hr.tg_log') ?> (<?= count($log) ?>)</summary><ul class="hint" style="margin:4px 0 0;padding-left:16px;"><?php foreach (array_slice($log, 0, 8) as $l): ?><li><?= e(fmt_datetime($l['created_at'])) ?> · <?= e((string)$l['full_name']) ?> · <?= $l['progress'] !== null ? (int)$l['progress'] . ' %' : '' ?> <?= $l['status'] ? e(t('hr.tg_s_' . $l['status'])) : '' ?><?= $l['note'] ? ' — ' . e($l['note']) : '' ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
    <td class="hint"><?= e((string)$tg['measure']) ?><?= $tg['target_value'] ? ' → <b>' . e($tg['target_value']) . ($tg['unit'] ? ' ' . e($tg['unit']) : '') . '</b>' : '' ?></td>
    <td class="hint<?= $tg['due_date'] && $tg['due_date'] < date('Y-m-d') && !in_array($tg['status'], ['achieved', 'missed', 'cancelled'], true) ? ' bad' : '' ?>"><?= $tg['due_date'] ? e(fmt_date($tg['due_date'])) : '—' ?></td>
    <td class="num hint"><?= (int)$tg['weight'] ?></td>
    <td><?= hr_tg_bar((int)$tg['progress'], $tg['status']) ?><div style="display:flex;gap:4px;align-items:center;margin-top:3px;"><input form="<?= $fid ?>" type="number" name="progress" min="0" max="100" value="<?= (int)$tg['progress'] ?>" style="width:64px;"><span class="hint">%</span></div></td>
    <td><select form="<?= $fid ?>" name="status" data-nosearch style="min-width:120px;"><?php foreach (HR_TG_STATUS as $st): ?><option value="<?= $st ?>"<?= $tg['status'] === $st ? ' selected' : '' ?>><?= e(t('hr.tg_s_' . $st)) ?></option><?php endforeach; ?></select></td>
    <td class="row-actions"><input form="<?= $fid ?>" name="note" maxlength="600" placeholder="<?= tt('hr.notes') ?>" style="width:120px;"> <button class="btn btn--ghost btn--sm" type="submit" form="<?= $fid ?>"><?= tt('btn.save') ?></button> <form method="post" action="<?= url('hr_targets', $keep) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_delete"><input type="hidden" name="id" value="<?= (int)$tg['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('hr.tg_delete_confirm') ?>">🗑</button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php $tgAi = $_SESSION['hr_tg_ai'] ?? null; $aiOn = function_exists('ai_can') && ai_can('hr_targets'); ?>
<div class="card" id="tg-ai">
  <h2>✨ <?= tt('hr.tg_ai_title') ?></h2>
  <?php if (!$aiOn): ?><p class="hint"><?= tt('hr.ai_off') ?></p><?php else: ?>
  <p class="hint"><?= tt('hr.tg_ai_hint') ?></p>
  <form method="post" action="<?= url('hr_targets', $keep) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_ai"><input type="hidden" name="year" value="<?= $year ?>">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('hr.tg_scope') ?></label><select name="scope" id="tgai_scope" data-nosearch><?php foreach (['company', 'department', 'person'] as $sc): if ($sc === 'company' && !hr_can()) continue; ?><option value="<?= $sc ?>"<?= ($tgAi['scope'] ?? 'department') === $sc ? ' selected' : '' ?>><?= e(t('hr.tg_sc_' . $sc)) ?></option><?php endforeach; ?></select></div>
      <div class="field tgai-dept"><label><?= tt('hr.department') ?></label><select name="department_id" data-nosearch><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($tgAi['department_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field tgai-person"><label><?= tt('hr.person') ?></label><select name="person_id"><option value="">—</option><?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= (int)($tgAi['person_id'] ?? 0) === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['name_en']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column: span 1;"><label>&nbsp;</label><button class="btn btn--gold" type="submit">✨ <?= tt('hr.tg_ai_go') ?></button></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.tg_ai_brief') ?></label><input name="q" maxlength="2000" value="<?= e($tgAi['q'] ?? '') ?>" placeholder="<?= tt('hr.tg_ai_ph') ?>"></div>
    </div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('tgai_scope');if(!s)return;function f(){document.querySelector('.tgai-dept').style.display=s.value==='department'?'':'none';document.querySelector('.tgai-person').style.display=s.value==='person'?'':'none';}s.addEventListener('change',f);f();})();</script>
  <?php if ($tgAi && $tgAi['err'] !== ''): ?><div class="flash flash--bad" style="margin-top:10px;"><?= e($tgAi['err']) ?></div><?php elseif ($tgAi && $tgAi['text'] !== ''): ?><div class="card card--tight" style="margin-top:10px;white-space:pre-wrap;"><?= e($tgAi['text']) ?></div><?php elseif ($tgAi && $tgAi['rows']): ?>
  <h3 class="ac-h" style="margin-top:12px;"><?= e(t('hr.tg_ai_proposals', ['n' => count($tgAi['rows'])])) ?></h3>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tg_title') ?></th><th><?= tt('hr.tg_measure') ?></th><th><?= tt('hr.tg_due') ?></th><th>⚖</th><th></th></tr></thead><tbody>
  <?php foreach ($tgAi['rows'] as $i => $r): ?><tr><td><b><?= e($r['title']) ?></b><?= $r['description'] ? '<br><span class="hint">' . e($r['description']) . '</span>' : '' ?></td><td class="hint"><?= e($r['measure']) ?><?= $r['target_value'] !== '' ? ' → <b>' . e($r['target_value']) . ($r['unit'] !== '' ? ' ' . e($r['unit']) : '') . '</b>' : '' ?></td><td class="hint"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '—' ?></td><td class="num hint"><?= (int)$r['weight'] ?></td>
    <td class="row-actions"><form method="post" action="<?= url('hr_targets', $keep) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_save"><input type="hidden" name="year" value="<?= (int)$tgAi['year'] ?>"><input type="hidden" name="scope" value="<?= e($tgAi['scope']) ?>"><input type="hidden" name="department_id" value="<?= (int)$tgAi['department_id'] ?>"><input type="hidden" name="person_id" value="<?= (int)$tgAi['person_id'] ?>"><?php foreach (['title', 'measure', 'target_value', 'unit', 'due_date', 'weight', 'description'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$r[$k]) ?>"><?php endforeach; ?><input type="hidden" name="status" value="open"><button class="btn btn--gold btn--sm" type="submit">➕ <?= tt('hr.tg_ai_add') ?></button></form> <a class="btn btn--ghost btn--sm" href="<?= url('hr_targets', $keep + ['new' => 1, 'ai' => $i]) ?>#tg-form"><?= tt('btn.edit') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; endif; ?>
</div>
<?php if (hr_can()): ?>
<div class="card" id="policy">
  <h2>📋 <?= tt('hr.ev_how_title') ?></h2>
  <p class="hint"><?= tt('hr.ev_policy_hint') ?></p>
  <form method="post" action="<?= url('hr_targets') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_eval_policy_save">
    <div class="field"><textarea data-mt name="policy" rows="6" maxlength="8000" placeholder="<?= tt('hr.ev_policy_ph') ?>"><?= e(hr_eval_policy()) ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('my_leave') ?>#hr-how"><?= tt('hr.ev_policy_preview') ?> ›</a></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

<?php
/** HR › Recruiting › Vacancy (GAS-CP-R89-001): view / edit a vacancy and its applications.  ?p=rc_vacancy&id=  |  ?p=rc_vacancy&new=1 */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $v = $id ? rcr_vacancy($id) : null; $edit = isset($_GET['new']) || isset($_GET['edit']);
if ($id && !$v) { http_response_code(404); layout_head(t('err.notfound'), 'rcr_dashboard'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!$id && !$edit) { redirect('rcr_dashboard'); }
if (!function_exists('rcr_has') || !rcr_has() || ($id && !rcr_can_view($id)) || (!$id && !rcr_can()) || ($edit && !rcr_can())) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
layout_head(($v['title'] ?? t('rc.new_vacancy')), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <?= $v ? e($v['code']) : tt('rc.new_vacancy') ?></p>
<?php if ($edit): $depts = hr_departments(); $brs = function_exists('br_all') ? br_all(true) : []; $sets = rcr_sets(); $mgrs = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name"); ?>
<h1><?= $v ? tt('btn.edit') . ' — ' . e($v['code']) : '＋ ' . tt('rc.new_vacancy') ?></h1>
<?php /* R92-001: assistant draft — kept in the session, merged into the form values, nothing saved until Save */
$aiOn = function_exists('ai_can') && ai_can('rcr_vacancy'); $aiD = $aiOn ? ($_SESSION['rc_vac_ai'][$id] ?? null) : null; $aiM = $aiOn ? ($_SESSION['rc_vac_ai_msg'] ?? null) : null; unset($_SESSION['rc_vac_ai_msg']);
if ($aiD) { $v = (array)$v; foreach ($aiD['fields'] as $k => $val) { if ($k === 'requirements') $v[$k] = json_encode(array_values(array_filter(array_map('trim', explode("\n", (string)$val)), 'strlen'))); elseif ($k === 'tags') $v[$k] = json_encode(array_values(array_filter(array_map('trim', explode(',', (string)$val)), 'strlen'))); else $v[$k] = $val; } }
if ($aiOn): ?>
<div class="card" id="rc-ai"><h2>✨ <?= tt('rc.ai_title') ?></h2><p class="hint"><?= tt('rc.ai_hint') ?></p>
  <?php if ($aiM && $aiM['err'] !== ''): ?><p class="flash flash--bad"><?= e($aiM['err']) ?></p><?php elseif ($aiM && $aiM['a'] !== ''): ?><p class="flash flash--good"><?= e($aiM['a']) ?></p><?php endif; ?>
  <?php if ($aiD): ?><p class="flash flash--info"><?= tt('rc.ai_prefilled') ?></p><?php endif; ?>
  <form method="post" data-lock data-busy="…"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_vacancy_ai"><input type="hidden" name="id" value="<?= $id ?>">
    <?php foreach (['title', 'department_id', 'branch_id', 'location', 'emp_type', 'seniority', 'summary', 'description_html'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)($v[$k] ?? '')) ?>"><?php endforeach; ?>
    <input type="hidden" name="requirements" value="<?= e(implode("\n", json_decode((string)($v['requirements'] ?? '[]'), true) ?: [])) ?>"><input type="hidden" name="tags" value="<?= e(implode(', ', json_decode((string)($v['tags'] ?? '[]'), true) ?: [])) ?>">
    <div class="field"><textarea name="brief" rows="3" maxlength="2000" placeholder="<?= e(t('rc.ai_ph')) ?>"><?= e((string)($aiM['brief'] ?? '')) ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold"><?= tt('rc.ai_draft') ?></button></div>
  </form>
  <?php if ($aiD): ?><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_vacancy_ai_clear"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost"><?= tt('rc.ai_discard') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_vacancy_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field"><label><?= tt('rc.code') ?></label><input value="<?= e($v['code'] ?? rcr_code('VAC', 'rc_vacancies')) ?>" readonly class="mono" title="<?= e(t('rc.code_auto')) ?>"><p class="hint"><?= tt('rc.code_auto') ?></p></div>
  <div class="field"><label><?= tt('rc.job_title') ?> *</label><input name="title" required maxlength="190" value="<?= e((string)($v['title'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('hr.department') ?></label><select name="department_id"><option value=""></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($v['department_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.branch') ?></label><select name="branch_id"><option value=""></option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($v['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.location') ?></label><input name="location" maxlength="120" value="<?= e((string)($v['location'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('rc.emp_type') ?></label><select name="emp_type" data-nosearch><?php foreach (RCR_EMP_TYPE as $t): ?><option value="<?= $t ?>"<?= ($v['emp_type'] ?? 'full_time') === $t ? ' selected' : '' ?>><?= tt('rc.' . $t) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.seniority') ?></label><select name="seniority" data-nosearch><?php foreach (RCR_SENIORITY as $t): ?><option value="<?= $t ?>"<?= ($v['seniority'] ?? 'mid') === $t ? ' selected' : '' ?>><?= tt('rc.' . $t) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.screening_set') ?> *</label><select name="screening_set_id" data-nosearch required><?php foreach ($sets as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)($v['screening_set_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['code'] . ' — ' . $s['title']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.hiring_manager') ?></label><select name="hiring_manager_id"><option value=""></option><?php foreach ($mgrs as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)($v['hiring_manager_id'] ?? 0) === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field span2"><label><?= tt('rc.summary') ?></label><textarea name="summary" rows="2" maxlength="600"><?= e((string)($v['summary'] ?? '')) ?></textarea></div>
  <div class="field span2"><label><?= tt('rc.description') ?></label><textarea name="description_html" rows="6"><?= e((string)($v['description_html'] ?? '')) ?></textarea></div>
  <div class="field"><label><?= tt('rc.requirements') ?> <span class="hint"><?= tt('rc.one_per_line') ?></span></label><textarea name="requirements" rows="5"><?= e(implode("\n", json_decode((string)($v['requirements'] ?? '[]'), true) ?: [])) ?></textarea></div>
  <div class="field"><label><?= tt('rc.tags') ?> <span class="hint"><?= tt('rc.comma') ?></span></label><textarea name="tags" rows="5"><?= e(implode(', ', json_decode((string)($v['tags'] ?? '[]'), true) ?: [])) ?></textarea></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (['draft', 'open', 'closed', 'filled'] as $s): ?><option value="<?= $s ?>"<?= ($v['status'] ?? 'draft') === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.closes') ?></label><input type="date" name="closes_at" value="<?= e((string)($v['closes_at'] ?? '')) ?>"></div>
  <label class="check span2"><input type="checkbox" name="publish_web" value="1"<?= ($v['publish_web'] ?? 1) ? ' checked' : '' ?>> <?= tt('rc.publish_web') ?></label>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $id ? url('rcr_vacancy', ['id' => $id]) : url('rcr_dashboard') ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php else: $apps = rcr_applications(['vacancy_id' => $id]); $st = fn(string $s) => pill(in_array($s, ['open', 'hired', 'offer'], true) ? 'gold' : (in_array($s, ['rejected', 'closed', 'withdrawn'], true) ? 'grey' : 'navy'), t('rc.st_' . $s)); ?>
<div class="page-head"><div><h1><?= e($v['title']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($v['code']) ?> · <?= $st($v['status']) ?><?= $v['publish_web'] ? ' 🌐' : '' ?> · <?= e((string)$v['department']) ?> · <?= e((string)$v['branch']) ?> · <?= e((string)$v['location']) ?> · <?= tt('rc.' . $v['emp_type']) ?> · <?= tt('rc.' . $v['seniority']) ?><?= $v['closes_at'] ? ' · ' . tt('rc.closes') . ' ' . e(fmt_date($v['closes_at'])) : '' ?></p></div>
<?php if (rcr_can()): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('rcr_vacancy', ['id' => $id, 'edit' => 1]) ?>"><?= tt('btn.edit') ?></a></div><?php endif; ?></div>
<div class="cols" style="grid-template-columns:2fr 1fr;"><div>
<?php if (rcr_eval_has() && $apps): $rk = rcr_vacancy_ranking($id); ?><div class="card"><h2>🏁 <?= tt('rc.ev_ranking') ?></h2><p class="hint"><?= tt('rc.ev_ranking_hint') ?></p>
<div class="table-scroll"><table class="tbl"><thead><tr><th>#</th><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.screening') ?></th><th class="num"><?= tt('rc.ev_title') ?></th><th><?= tt('rc.ev_reco') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
<?php foreach ($rk as $i => $r): ?><tr><td><?= $i + 1 ?></td><td><a href="<?= url('rcr_application', ['id' => (int)$r['id']]) ?>"><b><?= e((string)($r['name'] ?: $r['email'])) ?></b></a> <span class="hint"><?= e($r['code']) ?><?= $r['has_cv'] ? ' · 📎' : '' ?></span></td><td><?= $r['passed'] ? pill('gold', (int)$r['score'] . '/' . (int)$r['max_score']) : pill('grey', (int)$r['score'] . '/' . (int)$r['max_score']) ?></td><td class="num"><?= $r['eval_avg'] !== null ? '<b>' . e((string)$r['eval_avg']) . '</b> <span class="hint">/ 100 · ' . (int)$r['eval_count'] . '</span>' : '<span class="hint">—</span>' ?></td><td><?php foreach (array_filter(explode(',', (string)$r['recos'])) as $rc): ?><?= pill($rc === 'strong_hire' || $rc === 'hire' ? 'green' : ($rc === 'hold' ? 'gold' : 'grey'), t('rc.ev_reco_' . $rc)) ?> <?php endforeach; ?></td><td><?= $st($r['status']) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div><?php endif; ?>
<div class="card"><h2><?= tt('rc.applications') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($apps) ?></span></h2>
<?php if (!$apps): ?><div class="empty"><?= tt('rc.none') ?></div><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.code') ?></th><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.screening') ?></th><th><?= tt('th.status') ?></th><th>CV</th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php foreach ($apps as $a): ?><tr><td><a href="<?= url('rcr_application', ['id' => (int)$a['id']]) ?>"><b><?= e($a['code']) ?></b></a></td><td><?= e((string)($a['name'] ?: $a['email'])) ?><br><span class="hint"><?= e($a['email']) ?></span></td><td><?= $a['passed'] ? pill('gold', '✓ ' . (int)$a['score'] . '/' . (int)$a['max_score']) : pill('grey', '✗ ' . (int)$a['score'] . '/' . (int)$a['max_score']) ?></td><td><?= $st($a['status']) ?></td><td><?= $a['cv_path'] ? '<a href="' . url('rcr_cv', ['id' => (int)$a['id']]) . '" target="_blank">📎</a>' : '—' ?></td><td><?= e(fmt_datetime($a['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div><div>
<div class="card"><h2><?= tt('rc.summary') ?></h2><p><?= e((string)$v['summary']) ?></p><?php $req = json_decode((string)$v['requirements'], true) ?: []; if ($req): ?><h3><?= tt('rc.requirements') ?></h3><ul><?php foreach ($req as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul><?php endif; ?><p class="hint"><?= tt('rc.screening_set') ?>: <b><?= e($v['set_code']) ?></b></p></div>
</div></div>
<?php endif; layout_foot();

<?php
/** HR › Recruiting › Vacancy (GAS-CP-R89-001): view / edit a vacancy and its applications.  ?p=rc_vacancy&id=  |  ?p=rc_vacancy&new=1 */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $v = $id ? rcr_vacancy($id) : null; $edit = isset($_GET['new']) || isset($_GET['edit']);
if (!function_exists('rcr_has') || !rcr_has() || ($id && !rcr_can_view($id)) || (!$id && !rcr_can()) || ($edit && !rcr_can())) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
layout_head(($v['title'] ?? t('rc.new_vacancy')), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <?= $v ? e($v['code']) : tt('rc.new_vacancy') ?></p>
<?php if ($edit): $depts = all('SELECT id, name FROM hr_departments WHERE is_active = 1 ORDER BY sort_order, name'); $brs = function_exists('br_all') ? br_all(true) : []; $sets = rcr_sets(); $mgrs = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name"); ?>
<h1><?= $v ? tt('btn.edit') . ' — ' . e($v['code']) : '＋ ' . tt('rc.new_vacancy') ?></h1>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_vacancy_save"><input type="hidden" name="id" value="<?= $id ?>">
  <label class="span2"><?= tt('rc.vacancy') ?> *<input name="title" required maxlength="190" value="<?= e((string)($v['title'] ?? '')) ?>"></label>
  <label><?= tt('hr.department') ?><select name="department_id"><option value=""></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($v['department_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.branch') ?><select name="branch_id"><option value=""></option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"<?= (int)($v['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '' ?>><?= e($b['name']) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.location') ?><input name="location" maxlength="120" value="<?= e((string)($v['location'] ?? '')) ?>"></label>
  <label><?= tt('rc.emp_type') ?><select name="emp_type" data-nosearch><?php foreach (RCR_EMP_TYPE as $t): ?><option value="<?= $t ?>"<?= ($v['emp_type'] ?? 'full_time') === $t ? ' selected' : '' ?>><?= tt('rc.' . $t) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.seniority') ?><select name="seniority" data-nosearch><?php foreach (RCR_SENIORITY as $t): ?><option value="<?= $t ?>"<?= ($v['seniority'] ?? 'mid') === $t ? ' selected' : '' ?>><?= tt('rc.' . $t) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.screening_set') ?> *<select name="screening_set_id" data-nosearch required><?php foreach ($sets as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)($v['screening_set_id'] ?? 0) === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['code'] . ' — ' . $s['title']) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.hiring_manager') ?><select name="hiring_manager_id"><option value=""></option><?php foreach ($mgrs as $m): ?><option value="<?= (int)$m['id'] ?>"<?= (int)($v['hiring_manager_id'] ?? 0) === (int)$m['id'] ? ' selected' : '' ?>><?= e($m['full_name']) ?></option><?php endforeach; ?></select></label>
  <label class="span2"><?= tt('rc.summary') ?><textarea name="summary" rows="2" maxlength="600"><?= e((string)($v['summary'] ?? '')) ?></textarea></label>
  <label class="span2"><?= tt('rc.description') ?><textarea name="description_html" rows="6"><?= e((string)($v['description_html'] ?? '')) ?></textarea></label>
  <label><?= tt('rc.requirements') ?> <span class="hint"><?= tt('rc.one_per_line') ?></span><textarea name="requirements" rows="5"><?= e(implode("\n", json_decode((string)($v['requirements'] ?? '[]'), true) ?: [])) ?></textarea></label>
  <label><?= tt('rc.tags') ?> <span class="hint"><?= tt('rc.comma') ?></span><textarea name="tags" rows="5"><?= e(implode(', ', json_decode((string)($v['tags'] ?? '[]'), true) ?: [])) ?></textarea></label>
  <label><?= tt('th.status') ?><select name="status" data-nosearch><?php foreach (['draft', 'open', 'closed', 'filled'] as $s): ?><option value="<?= $s ?>"<?= ($v['status'] ?? 'draft') === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.closes') ?><input type="date" name="closes_at" value="<?= e((string)($v['closes_at'] ?? '')) ?>"></label>
  <label class="check span2"><input type="checkbox" name="publish_web" value="1"<?= ($v['publish_web'] ?? 1) ? ' checked' : '' ?>> <?= tt('rc.publish_web') ?></label>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $id ? url('rcr_vacancy', ['id' => $id]) : url('rcr_dashboard') ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php else: $apps = rcr_applications(['vacancy_id' => $id]); $st = fn(string $s) => pill(in_array($s, ['open', 'hired', 'offer'], true) ? 'gold' : (in_array($s, ['rejected', 'closed', 'withdrawn'], true) ? 'grey' : 'navy'), t('rc.st_' . $s)); ?>
<div class="page-head"><div><h1><?= e($v['title']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($v['code']) ?> · <?= $st($v['status']) ?><?= $v['publish_web'] ? ' 🌐' : '' ?> · <?= e((string)$v['department']) ?> · <?= e((string)$v['branch']) ?> · <?= e((string)$v['location']) ?> · <?= tt('rc.' . $v['emp_type']) ?> · <?= tt('rc.' . $v['seniority']) ?><?= $v['closes_at'] ? ' · ' . tt('rc.closes') . ' ' . e(fmt_date($v['closes_at'])) : '' ?></p></div>
<?php if (rcr_can()): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('rcr_vacancy', ['id' => $id, 'edit' => 1]) ?>"><?= tt('btn.edit') ?></a></div><?php endif; ?></div>
<div class="cols" style="grid-template-columns:2fr 1fr;"><div>
<div class="card"><h2><?= tt('rc.applications') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($apps) ?></span></h2>
<?php if (!$apps): ?><div class="empty"><?= tt('rc.none') ?></div><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.code') ?></th><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.screening') ?></th><th><?= tt('th.status') ?></th><th>CV</th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php foreach ($apps as $a): ?><tr><td><a href="<?= url('rcr_application', ['id' => (int)$a['id']]) ?>"><b><?= e($a['code']) ?></b></a></td><td><?= e((string)($a['name'] ?: $a['email'])) ?><br><span class="hint"><?= e($a['email']) ?></span></td><td><?= $a['passed'] ? pill('gold', '✓ ' . (int)$a['score'] . '/' . (int)$a['max_score']) : pill('grey', '✗ ' . (int)$a['score'] . '/' . (int)$a['max_score']) ?></td><td><?= $st($a['status']) ?></td><td><?= $a['cv_path'] ? '<a href="' . url('rcr_cv', ['id' => (int)$a['id']]) . '" target="_blank">📎</a>' : '—' ?></td><td><?= e(fmt_datetime($a['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div><div>
<div class="card"><h2><?= tt('rc.summary') ?></h2><p><?= e((string)$v['summary']) ?></p><?php $req = json_decode((string)$v['requirements'], true) ?: []; if ($req): ?><h3><?= tt('rc.requirements') ?></h3><ul><?php foreach ($req as $r): ?><li><?= e($r) ?></li><?php endforeach; ?></ul><?php endif; ?><p class="hint"><?= tt('rc.screening_set') ?>: <b><?= e($v['set_code']) ?></b></p></div>
</div></div>
<?php endif; layout_foot();

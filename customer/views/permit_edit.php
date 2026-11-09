<?php
/** Work permit — new / edit.   GAS-CP-R93-001   ?p=permit_edit[&id=][&project=] */
require __DIR__ . '/_layout.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0); $p = $id ? pmt_load($id) : null;
if ($id && !$p) { http_response_code(404); layout_head(t('pmt.title'), 'projects'); echo '<h1>404</h1>'; layout_foot(); return; }
$pid = $p ? (int)$p['project_id'] : (int)($_GET['project'] ?? 0);
if (!pmt_can_view() || !pmt_can_edit($pid)) { http_response_code(403); layout_head(t('pmt.title'), 'projects'); echo '<h1>' . tt('pmt.title') . '</h1><p class="flash flash--bad">' . tt('err.noaccess') . '</p>'; layout_foot(); return; }
$projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold') OR id = ? ORDER BY project_code DESC, name", [$pid]);
$people = function_exists('hr_people') ? hr_people(true) : [];
$types = pmt_types(); $v = $p ?: ['project_id' => $pid, 'permit_type' => 'work_permit', 'status' => 'active'];
layout_head($p ? t('pmt.edit') : t('pmt.new'), 'projects');
?>
<p class="crumbs"><a href="<?= url('projects') ?>"><?= tt('nav.projects') ?></a> › <a href="<?= url('permits', $pid ? ['project' => $pid] : []) ?>"><?= tt('pmt.title') ?></a><?php if ($p): ?> › <?= e($p['holder_name']) ?><?php endif; ?></p>
<div class="page-head"><h1>🪪 <?= $p ? tt('pmt.edit') : tt('pmt.new') ?></h1><?php if ($p): ?><div class="btn-row"><?= pmt_pill($p) ?></div><?php endif; ?></div>
<form method="post" action="<?= url('permit_edit', $id ? ['id' => $id] : []) ?>" enctype="multipart/form-data" class="card form-grid" data-lock>
  <?= csrf_field() ?><input type="hidden" name="action" value="pmt_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field span2"><label><?= tt('pmt.project') ?> *</label><select name="project_id" required><option value=""></option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"<?= (int)$v['project_id'] === (int)$pr['id'] ? ' selected' : '' ?>><?= e(($pr['project_code'] ? $pr['project_code'] . ' — ' : '') . $pr['name']) ?></option><?php endforeach; ?></select></div>
  <?php if ($people): ?><div class="field"><label><?= tt('pmt.person') ?></label><select name="person_id"><option value=""><?= tt('pmt.person_none') ?></option><?php foreach ($people as $hp): ?><option value="<?= $hp['id'] ?>"<?= (int)($v['person_id'] ?? 0) === (int)$hp['id'] ? ' selected' : '' ?>><?= e($hp['name_en']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('pmt.person_hint') ?></p></div><?php endif; ?>
  <div class="field"><label><?= tt('pmt.holder') ?><?= $people ? '' : ' *' ?></label><input name="holder_name" maxlength="160" value="<?= e((string)($v['holder_name'] ?? '')) ?>"<?= $people ? '' : ' required' ?>></div>
  <div class="field"><label><?= tt('pmt.type') ?> *</label><select name="permit_type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"<?= $v['permit_type'] === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pmt.no') ?></label><input name="permit_no" maxlength="80" class="mono" value="<?= e((string)($v['permit_no'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('pmt.authority') ?></label><input name="authority" maxlength="160" value="<?= e((string)($v['authority'] ?? '')) ?>" placeholder="<?= tt('pmt.authority_ph') ?>"></div>
  <div class="field"><label><?= tt('pmt.country') ?></label><input name="country" maxlength="2" style="max-width:90px;text-transform:uppercase;" value="<?= e((string)($v['country'] ?? '')) ?>" placeholder="IT"></div>
  <div class="field"><label><?= tt('pmt.issue') ?></label><input type="date" name="issue_date" value="<?= e((string)($v['issue_date'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('pmt.expiry') ?> *</label><input type="date" name="expiry_date" required value="<?= e((string)($v['expiry_date'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('pr.status') ?></label><select name="status"><?php foreach (PMT_STATES as $s): ?><option value="<?= $s ?>"<?= $v['status'] === $s ? ' selected' : '' ?>><?= tt('pmt.st_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pmt.file') ?></label><input type="file" name="file" accept=".pdf,.jpg,.jpeg,.png,.webp"><?php if (!empty($v['stored_name'])): ?><p class="hint">📄 <a href="<?= url('permit_file', ['id' => $id]) ?>" target="_blank" rel="noopener"><?= e($v['original_name']) ?></a> · <?= number_format(((int)$v['size_bytes']) / 1024) ?> KB — <?= tt('pmt.file_replace') ?></p><?php else: ?><p class="hint"><?= tt('pmt.file_hint') ?></p><?php endif; ?></div>
  <div class="field span2"><label><?= tt('pmt.notes') ?></label><textarea name="notes" rows="3" maxlength="500"><?= e((string)($v['notes'] ?? '')) ?></textarea></div>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button> <a class="btn btn--ghost" href="<?= url('permits', $pid ? ['project' => $pid] : []) ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php if ($p): ?>
<form method="post" action="<?= url('permit_edit', ['id' => $id]) ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="pmt_delete"><input type="hidden" name="id" value="<?= $id ?>">
  <button class="btn btn--ghost btn--sm" data-confirm="<?= e(t('pmt.delete_confirm')) ?>" data-confirm-ok="<?= tt('btn.delete') ?>">🗑 <?= tt('btn.delete') ?></button></form>
<?php endif; ?>
<?php layout_foot(); ?>

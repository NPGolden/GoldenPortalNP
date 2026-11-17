<?php
/** ISO 27001 — one record of a register: fields, files, history.   GAS-CP-R97-080   ?p=isms_rec&id=   |   ?p=isms_rec&reg=  (new)   |   ?p=isms_rec&file= (download) */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('ism_can') || !(ism_can() || ism_can_marine() || qh_can())) { http_response_code(403); layout_head(t('ism.title'), 'isms'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
if (!empty($_GET['file'])) { ism_file_send((int)$_GET['file']); exit; }
$id = (int)($_GET['id'] ?? 0); $r = $id ? ism_get($id) : null; $reg = $r ? (string)$r['reg'] : (string)($_GET['reg'] ?? ''); $R = ism_reg($reg);
if ($R && !ism_can_reg($reg)) { http_response_code(403); layout_head(t('ism.title'), 'isms'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$PG = $R ? ism_page($reg) : 'isms'; $marine = $PG === 'mcs'; $qhse = $PG === 'qhse'; $PA = $qhse ? ['std' => (string)$R['std']] : [];
$par = null; if ($R && !empty($R['parent'])) { $par = ism_get((int)($r['parent_id'] ?? ($_GET['parent'] ?? 0))); if ($par && $par['reg'] !== $R['parent']) $par = null; }
if (!$R || ($id && !$r)) { http_response_code(404); layout_head(t('ism.title'), 'isms'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$d = $r ? ism_data($r) : []; $users = ism_users(); $self = url('isms_rec', $id ? ['id' => $id] : ['reg' => $reg]); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$files = $id ? ism_files($id) : []; $events = $id ? ism_events($id) : []; $why = $r ? ism_due($r) : null;
layout_head(($r ? $r['code'] . ' · ' : '') . ism_reg_title($reg), $PG);
?>
<p class="eyebrow"><a href="<?= url($PG) ?>"><?= tt($marine ? 'ism.m_title' : ($qhse ? 'qh.title' : 'ism.title')) ?></a><?= $qhse ? ' › <a href="' . url($PG, $PA) . '">' . ($R['std'] === 'ims' ? tt('qh.ims') : e(QH_STD[$R['std']])) . '</a>' : '' ?> › <a href="<?= url($PG, $PA + ['reg' => $reg]) ?>"><?= e(ism_reg_title($reg)) ?></a><?= $par ? ' › <a href="' . url('isms_rec', ['id' => (int)$par['id']]) . '">' . e($par['code'] . ' ' . $par['title']) . '</a>' : '' ?></p>
<div class="page-head"><div><h1><?= $R['icon'] ?> <?= $r ? '<span class="mono">' . e($r['code']) . '</span> ' . e($r['title']) : e(t('ism.new_in', ['r' => ism_reg_title($reg)])) ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= $r ? ism_status_pill($reg, (string)$r['status']) . ' ' : '' ?><span class="hint" style="display:inline;"><?= e($R['clause']) ?></span><?= $why ? ' ' . pill($why === 'due' ? 'bad' : 'gold', t($why === 'due' ? 'ism.w_due' : 'ism.w_review')) : '' ?></p></div>
  <?php if ($r && ism_manage()): ?><div class="btn-row"><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= e(t('ism.delete_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ism_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('btn.delete') ?></button></form></div><?php endif; ?></div>

<div class="cols" style="grid-template-columns: 1.6fr 1fr;">
<div class="card">
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_save"><input type="hidden" name="reg" value="<?= e($reg) ?>"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('ism.code') ?></label><?php $autoCode = $r ? '' : ism_next_code($reg, (int)($par['id'] ?? 0)); ?><input name="code" maxlength="40" value="<?= e((string)($r['code'] ?? $autoCode)) ?>"><?php if (!$r): ?><input type="hidden" name="code_auto" value="<?= e($autoCode) ?>"><span class="hint"><?= tt('ism.code_auto') ?></span><?php endif; ?></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('ism.title_f') ?><?= in_array($reg, ['supplier', 'supeval', 'qcal'], true) ? '' : ' *' ?></label><input name="title" maxlength="255"<?= in_array($reg, ['supplier', 'supeval', 'qcal'], true) ? ' placeholder="' . tt('ism.title_auto') . '"' : ' required' ?> value="<?= e((string)($r['title'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach ($R['st'] as $sk => $_): ?><option value="<?= $sk ?>"<?= $sel($sk, $r['status'] ?? array_key_first($R['st'])) ?>><?= tt('ism.s_' . $sk) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt($reg === 'msw' ? 'ism.own_msw' : 'ism.owner') ?></label><select name="owner_user_id"><option value="">—</option><?php foreach ($users as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $r['owner_user_id'] ?? '') ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
      <?php if (!empty($R['ref']) || $reg === 'soa'): ?><div class="field"><label><?= tt($reg === 'soa' ? 'ism.theme' : 'ism.ref') ?></label><input name="ref" maxlength="60" value="<?= e((string)($r['ref'] ?? '')) ?>" placeholder="<?= $reg === 'soa' ? '' : tt('ism.ref_ph') ?>"></div><?php endif; ?>
      <?php if (!empty($R['due'])): ?><div class="field"><label><?= tt('ism.due_' . $reg) === 'ism.due_' . $reg ? tt('ism.due_date') : tt('ism.due_' . $reg) ?></label><input type="date" name="due_date" value="<?= e((string)($r['due_date'] ?? '')) ?>"></div><?php endif; ?>
      <?php if (!empty($R['review'])): ?><div class="field"><label><?= tt($reg === 'qequip' ? 'ism.f_valid_until' : ($reg === 'supplier' ? 'ism.sup_next' : 'ism.review_date')) ?></label><input type="date" name="review_date" value="<?= e((string)($r['review_date'] ?? '')) ?>"></div><?php endif; ?>
    </div>
    <?php if ($reg === 'supplier' && ism_has_ext()): $supSel = (int)($r['supplier_id'] ?? ($_GET['supplier'] ?? 0)); $supOpt = ism_supplier_options(); ?>
    <div class="grid grid-2" style="margin-top:6px;"><div class="field" style="grid-column: 1 / -1;"><label><?= tt('ism.sup_pick') ?></label><select name="supplier_id"><option value="">—</option><?php foreach ($supOpt as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $supSel) ?>><?= e($x['name'] . ($x['city'] || $x['country'] ? ' · ' . trim($x['city'] . ' ' . $x['country']) : '') . ($x['is_active'] ? '' : ' (' . t('v.inactive') . ')')) ?></option><?php endforeach; ?></select>
      <span class="hint"><?= tt('ism.sup_pick_hint') ?><?= $supSel && function_exists('pr_can') && pr_can() ? ' · <a href="' . url('suppliers', ['id' => $supSel]) . '">' . tt('ism.sup_open') . ' ↗</a>' : '' ?></span></div></div>
    <?php endif; ?>
    <?php if (!empty($R['parent'])): $prods = ism_list($R['parent']); ?>
    <div class="grid grid-2" style="margin-top:6px;"><div class="field" style="grid-column: 1 / -1;"><label><?= tt('ism.par_' . $R['parent']) ?> *</label><select name="parent_id" required><option value="">—</option><?php foreach ($prods as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $par['id'] ?? '') ?>><?= e($x['code'] . ' — ' . $x['title'] . (!empty($x['vessel_name']) ? ' · ' . $x['vessel_name'] : '')) ?></option><?php endforeach; ?></select>
      <?php if ($par): ?><span class="hint"><?= $par['x_vessel'] ? '⚓ <a href="' . url('vessel', ['id' => (int)$par['x_vessel']]) . '">' . e((string)$par['vessel_name']) . '</a>' : '' ?><?= $par['x_sub'] ? ' · <a href="' . url('subproject', ['id' => (int)$par['x_sub']]) . '">' . e(trim((string)$par['sub_code'] . ' ' . (string)$par['sub_name'])) . '</a>' : '' ?><?= $par['x_project'] ? ' · ' . e(trim((string)$par['project_code'] . ' ' . (string)$par['project_name'])) : '' ?></span><?php endif; ?></div></div>
    <?php endif; ?>
    <?php if (!empty($R['links'])): $lp = (int)($r['project_id'] ?? ($_GET['project'] ?? 0)); $ls = (int)($r['subproject_id'] ?? ($_GET['sub'] ?? 0)); $lv = (int)($r['vessel_id'] ?? ($_GET['vessel'] ?? 0));
      if ($ls && !$lp) $lp = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$ls]); if ($lp && !$lv) $lv = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$lp]);
      $vsAll = all('SELECT id, name FROM vessels WHERE is_active = 1 OR id = ? ORDER BY name', [$lv]); $pjAll = all('SELECT p.id, p.name, p.project_code, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id ORDER BY p.project_code, p.name'); $sbAll = $lp ? all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY code, id', [$lp]) : []; ?>
    <div class="grid grid-3" style="margin-top:6px;">
      <div class="field"><label><?= tt('ism.vessel') ?></label><select name="vessel_id"><option value="">—</option><?php foreach ($vsAll as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $lv) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('ism.project') ?></label><select name="project_id"><option value="">—</option><?php foreach ($pjAll as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $lp) ?>><?= e(trim((string)$x['project_code'] . ' ' . $x['name']) . ($x['vessel'] ? ' · ' . $x['vessel'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('ism.subproject') ?></label><?php if ($sbAll): ?><select name="subproject_id"><option value="">—</option><?php foreach ($sbAll as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $ls) ?>><?= e(trim((string)$x['code'] . ' ' . $x['name'])) ?></option><?php endforeach; ?></select><?php else: ?><input disabled placeholder="<?= tt('ism.sub_after') ?>"><?php endif; ?></div>
    </div>
    <?php if ($r && ($r['x_vessel'] || $r['x_sub'])): ?><p class="hint" style="margin:4px 0 0;"><?= $r['x_vessel'] ? '⚓ <a href="' . url('vessel', ['id' => (int)$r['x_vessel']]) . '#mcs">' . e((string)$r['vessel_name']) . '</a>' : '' ?><?= $r['x_sub'] ? ' · 📁 <a href="' . url('subproject', ['id' => (int)$r['x_sub']]) . '#mcs">' . e(trim((string)$r['sub_code'] . ' ' . (string)$r['sub_name'])) . '</a>' : '' ?></p><?php endif; ?>
    <?php endif; ?>
    <div class="grid grid-2" style="margin-top:6px;">
      <?php foreach ($R['f'] as $fk => $type): $v = $d[$fk] ?? ''; $full = $type === 'area'; ?>
      <div class="field"<?= $full ? ' style="grid-column: 1 / -1;"' : '' ?>><label><?= tt('ism.f_' . $fk) ?></label>
        <?php if ($type === 'area'): ?><textarea name="d[<?= $fk ?>]" rows="3" maxlength="6000"><?= e((string)$v) ?></textarea>
        <?php elseif ($type === 'date'): ?><input type="date" name="d[<?= $fk ?>]" value="<?= e((string)$v) ?>">
        <?php elseif ($type === 'num'): ?><input type="number" step="any" name="d[<?= $fk ?>]" value="<?= e((string)$v) ?>">
        <?php elseif ($type === 'score'): ?><select name="d[<?= $fk ?>]" data-nosearch><option value="">—</option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= $sel($i, $v) ?>><?= $i ?> — <?= tt('ism.sc_' . $i) ?></option><?php endfor; ?></select>
        <?php elseif (str_starts_with($type, 'sel:')): ?><select name="d[<?= $fk ?>]" data-nosearch><option value="">—</option><?php foreach (ism_opts($type) as $o): ?><option value="<?= $o ?>"<?= $sel($o, $v) ?>><?= tt('ism.o_' . $o) ?></option><?php endforeach; ?></select>
        <?php else: ?><input name="d[<?= $fk ?>]" maxlength="255" value="<?= e((string)$v) ?>"><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($reg === 'qequip' && $r): [$cs, $ct] = ism_cal_state($r); ?><p class="hint"><?= tt('qh.cal') ?>: <?= pill($ct, t('qh.cal_' . $cs)) ?><?= !empty($d['last_cal']) ? ' · ' . tt('ism.f_last_cal') . ' ' . e(fmt_date($d['last_cal'])) . ' · ' . tt('ism.f_cert_no') . ' ' . e((string)($d['cert_no'] ?? '')) . (!empty($d['lab']) ? ' · ' . e($d['lab']) : '') . (!empty($d['cal_result']) ? ' · ' . tt('ism.o_' . $d['cal_result']) : '') : '' ?> — <?= tt('qh.cal_rule') ?></p><?php endif; ?>
    <?php if (!empty($R['score'])): $s1 = ism_score($d); $s2 = ism_score($d, 'res_'); [$t1, $l1] = ism_level($s1); [$t2, $l2] = ism_level($s2); ?><p class="hint"><?= tt('ism.score') ?>: <?= $s1 ? pill($t1, $s1 . ' · ' . $l1) : '—' ?> · <?= tt('ism.res_score') ?>: <?= $s2 ? pill($t2, $s2 . ' · ' . $l2) : '—' ?> — <?= tt('ism.score_hint') ?></p><?php endif; ?>
    <?php if ($reg === 'supeval'): ?><p class="hint"><?= isset($d['rating']) ? tt('ism.f_rating') . ': ' . pill((int)$d['rating'] >= 70 ? 'green' : ((int)$d['rating'] >= 50 ? 'gold' : 'bad'), (int)$d['rating'] . ' %') . ' — ' : '' ?><?= tt('ism.eval_rule') ?></p><?php endif; ?>
    <?php if ($reg === 'supplier' && isset($d['rating'])): ?><p class="hint"><?= tt('ism.f_rating') ?>: <?= pill((int)$d['rating'] >= 70 ? 'green' : ((int)$d['rating'] >= 50 ? 'gold' : 'bad'), (int)$d['rating'] . ' %') ?><?= !empty($d['rating_result']) ? ' ' . tt('ism.o_' . $d['rating_result']) : '' ?></p><?php endif; ?>
    <?php if ($reg === 'msw'): ?><p class="hint"><?= !empty($d['approved_by']) ? '✅ ' . e(t('ism.sw_approved', ['n' => $d['approved_by'], 'd' => fmt_date($d['approved_on'] ?? '')])) . ' — ' : '' ?><?= tt('ism.sw_rule') ?></p><?php endif; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $par ? url('isms_rec', ['id' => (int)$par['id']]) : url($PG, $PA + ['reg' => $reg]) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<div>
<?php if ($r && $reg === 'mprod'): $cd = ism_children($id, 'mdoc'); $cs = array_reverse(ism_children($id, 'msw')); ?>
<div class="card" id="docs">
  <div class="card-head"><h2>📑 <?= tt('ism.r_mdoc') ?> <span class="count"><?= count(array_filter($cd, fn($x) => $x['status'] === 'approved')) ?> / <?= count(array_filter($cd, fn($x) => $x['status'] !== 'na')) ?></span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('isms_rec', ['reg' => 'mdoc', 'parent' => $id]) ?>">+</a></div></div>
  <table class="tbl tbl--wrap"><tbody><?php foreach ($cd as $x): $xd = ism_data($x); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$x['id']]) ?>"><?= e($x['title']) ?></a><br><span class="hint"><?= e((string)$x['ref']) ?><?= !empty($xd['doc_no']) ? ' · ' . e($xd['doc_no']) . (!empty($xd['revision']) ? ' ' . e($xd['revision']) : '') : '' ?><?= (int)$x['n_files'] ? ' · 📎 ' . (int)$x['n_files'] : '' ?></span></td><td style="white-space:nowrap;"><?= ism_status_pill('mdoc', (string)$x['status']) ?></td></tr><?php endforeach; ?></tbody></table>
</div>
<div class="card" id="sw">
  <div class="card-head"><h2>💾 <?= tt('ism.r_msw') ?> <span class="count"><?= count($cs) ?></span></h2><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', ['reg' => 'msw', 'parent' => $id]) ?>">+ <?= tt('ism.sw_new') ?></a></div></div>
  <?php if (!$cs): ?><p class="hint"><?= tt('ism.sw_none') ?></p><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($cs as $x): $xd = ism_data($x); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$x['id']]) ?>"><b class="mono"><?= e((string)($xd['version'] ?? $x['code'])) ?></b> <?= e($x['title']) ?></a><br><span class="hint"><?= !empty($xd['component']) ? tt('ism.o_' . $xd['component']) . ' · ' : '' ?><?= !empty($xd['change_type']) ? tt('ism.o_' . $xd['change_type']) . ' · ' : '' ?><?= e((string)($x['owner_name'] ?: '—')) ?><?= !empty($xd['approved_by']) ? ' · ✅ ' . e($xd['approved_by']) : '' ?><?= !empty($xd['installed_on']) ? ' · ⤓ ' . e(fmt_date($xd['installed_on'])) : '' ?></span></td><td style="white-space:nowrap;"><?= ism_status_pill('msw', (string)$x['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($r && $reg === 'supplier'): $ce = array_reverse(ism_children($id, 'supeval')); ?>
<div class="card" id="evals">
  <div class="card-head"><h2>📝 <?= tt('ism.r_supeval') ?> <span class="count"><?= count($ce) ?></span></h2><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', ['reg' => 'supeval', 'parent' => $id]) ?>">+ <?= tt('ism.eval_new') ?></a></div></div>
  <?php if (!$ce): ?><p class="hint"><?= tt('ism.eval_none') ?></p><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($ce as $x): $xd = ism_data($x); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$x['id']]) ?>"><b class="mono"><?= e($x['code']) ?></b></a> <?= !empty($xd['eval_kind']) ? tt('ism.o_' . $xd['eval_kind']) : '' ?><br><span class="hint"><?= !empty($xd['evaluated_on']) ? e(fmt_date($xd['evaluated_on'])) : ($x['due_date'] ? e(fmt_date($x['due_date'])) : '') ?><?= !empty($xd['evaluator']) ? ' · ' . e($xd['evaluator']) : '' ?><?= !empty($xd['eval_result']) ? ' · ' . tt('ism.o_' . $xd['eval_result']) : '' ?></span></td><td style="white-space:nowrap;"><?= isset($xd['rating']) && $x['status'] === 'done' ? pill((int)$xd['rating'] >= 70 ? 'green' : ((int)$xd['rating'] >= 50 ? 'gold' : 'bad'), (int)$xd['rating'] . ' %') : ism_status_pill('supeval', (string)$x['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <p class="hint" style="margin-bottom:0;"><?= tt('ism.eval_hint') ?></p>
</div>
<?php endif; ?>
<?php if ($r && $reg === 'qequip'): $cc = array_reverse(ism_children($id, 'qcal')); ?>
<div class="card" id="cals">
  <div class="card-head"><h2>🧪 <?= tt('ism.r_qcal') ?> <span class="count"><?= count($cc) ?></span></h2><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('isms_rec', ['reg' => 'qcal', 'parent' => $id]) ?>">+ <?= tt('qh.cal_new') ?></a></div></div>
  <?php if (!$cc): ?><p class="hint"><?= tt('qh.cal_none_yet') ?></p><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($cc as $x): $xd = ism_data($x); ?><tr><td><a href="<?= url('isms_rec', ['id' => (int)$x['id']]) ?>"><b class="mono"><?= e($x['code']) ?></b></a> <?= !empty($xd['cal_date']) ? e(fmt_date($xd['cal_date'])) : ($x['due_date'] ? e(fmt_date($x['due_date'])) : '') ?><br><span class="hint"><?= e((string)($xd['lab'] ?? '')) ?><?= !empty($xd['cert_no']) ? ' · ' . e($xd['cert_no']) : '' ?><?= !empty($xd['valid_until']) ? ' · → ' . e(fmt_date($xd['valid_until'])) : '' ?><?= (int)$x['n_files'] ? ' · 📎 ' . (int)$x['n_files'] : '' ?></span></td><td style="white-space:nowrap;"><?= !empty($xd['cal_result']) && in_array($x['status'], ['done', 'failed'], true) ? pill(['cal_pass' => 'green', 'cal_adjusted' => 'green', 'cal_limited' => 'gold', 'cal_fail' => 'bad'][$xd['cal_result']] ?? 'mute', t('ism.o_' . $xd['cal_result'])) : ism_status_pill('qcal', (string)$x['status']) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <p class="hint" style="margin-bottom:0;"><?= tt('qh.cal_hint') ?></p>
</div>
<?php endif; ?>
<?php if ($r && !empty($R['hrtr']) && ism_has_ext() && function_exists('hr_tr_has') && hr_tr_has()): $HT = ism_tr($r); ?>
<div class="card" id="hrtr">
  <h2>🎓 <?= tt('ism.tr_h') ?></h2>
  <?php if (!$HT): $hrAll = all("SELECT id, title, date_from FROM hr_trainings WHERE status <> 'cancelled' ORDER BY COALESCE(date_from, created_at) DESC LIMIT 200"); ?>
  <p class="hint"><?= tt('ism.tr_none') ?></p>
  <form method="post" action="<?= $self ?>#hrtr" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_tr_create"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('ism.tr_create') ?></button></form>
  <?php if ($hrAll): ?><form method="post" action="<?= $self ?>#hrtr" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="ism_tr_link"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('ism.tr_link') ?></label><select name="hr_training_id" required><option value="">—</option><?php foreach ($hrAll as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['title'] . ($x['date_from'] ? ' · ' . fmt_date($x['date_from']) : '')) ?></option><?php endforeach; ?></select></div><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ism.tr_link_btn') ?></button></form><?php endif; ?>
  <?php else: $st = []; foreach ($HT['members'] as $m) $st[(int)$m['person_id']] = (string)$m['status']; $ppl = all('SELECT id, name_en FROM hr_people WHERE is_active = 1 ORDER BY name_en'); $nAtt = count(array_filter($st, fn($s) => $s === 'attended')); ?>
  <p><b><?= e($HT['title']) ?></b> <?= function_exists('hr_tr_status_pill') ? hr_tr_status_pill((string)$HT['status']) : '' ?><?= function_exists('hr_can') && hr_can() ? ' <a class="hint" href="' . url('hr_training', ['id' => (int)$HT['id']]) . '">HR › ' . tt('hr.training') . ' ↗</a>' : '' ?><br><span class="hint"><?= $HT['date_from'] ? e(fmt_date($HT['date_from'])) . ' · ' : '' ?><?= e(t('ism.tr_n', ['n' => $nAtt, 'a' => count($ppl)])) ?></span></p>
  <form method="post" action="<?= $self ?>#hrtr" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_tr_attend"><input type="hidden" name="id" value="<?= $id ?>">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:4px 12px;max-height:300px;overflow:auto;"><?php foreach ($ppl as $x): $ms = $st[(int)$x['id']] ?? ''; ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="checkbox" name="pid[]" value="<?= (int)$x['id'] ?>"<?= $ms === 'attended' ? ' checked' : '' ?> style="width:16px;height:16px;"> <?= e($x['name_en']) ?><?= $ms !== '' && $ms !== 'attended' ? ' <span class="hint">(' . tt('hr.tm_' . $ms) . ')</span>' : '' ?></label><?php endforeach; ?></div>
    <label style="display:flex;gap:6px;align-items:center;font-weight:400;margin-top:8px;"><input type="checkbox" name="close" value="1" style="width:16px;height:16px;"> <?= tt('ism.tr_close') ?></label>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('ism.tr_save') ?></button></div>
  </form>
  <p class="hint" style="margin-bottom:0;"><?= tt('ism.tr_hint') ?></p>
  <form method="post" action="<?= $self ?>#hrtr" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_tr_link"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="hr_training_id" value="0"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ism.tr_unlink') ?></button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($r): ?>
<div class="card" id="files">
  <h2>📎 <?= tt('ism.files') ?> <span class="count"><?= count($files) ?></span></h2>
  <?php if (!$files): ?><p class="hint"><?= tt('ism.files_none') ?></p><?php else: ?><table class="tbl tbl--wrap"><tbody><?php foreach ($files as $f): ?><tr><td><a href="<?= url('isms_rec', ['file' => (int)$f['id']]) ?>"><b><?= e($f['original_name']) ?></b></a><?= $f['note'] ? '<br><span class="hint">' . e($f['note']) . '</span>' : '' ?><br><span class="hint"><?= e((string)$f['uploaded_name']) ?> · <?= e(fmt_datetime($f['created_at'])) ?> · <?= number_format((int)$f['size_bytes'] / 1024, 0, ',', '.') ?> KB</span></td>
    <td class="num"><form method="post" action="<?= $self ?>#files" class="inline" data-lock data-confirm="<?= e(t('ism.file_del_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ism_file_del"><input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
  <form method="post" action="<?= $self ?>#files" enctype="multipart/form-data" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="ism_file_add"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><input type="file" name="file" required></div><div class="field"><input name="note" maxlength="255" placeholder="<?= tt('ism.file_note_ph') ?>"></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">⤒ <?= tt('ism.file_add') ?></button><span class="hint"><?= tt('ism.file_hint') ?></span></div>
  </form>
</div>
<div class="card">
  <h2>🕘 <?= tt('ism.history') ?></h2>
  <ul class="hint" style="margin:0;padding-left:18px;"><?php foreach ($events as $ev): ?><li><?= e(fmt_datetime($ev['created_at'])) ?> · <b><?= tt('ism.ev_' . $ev['event']) ?></b> · <?= e((string)$ev['user_name']) ?><?= $ev['note'] ? ' — ' . e($ev['note']) : '' ?></li><?php endforeach; ?></ul>
</div>
<?php else: ?><div class="card"><p class="hint"><?= tt('ism.h_' . $reg) ?></p><p class="hint"><?= tt('ism.new_hint') ?></p></div><?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

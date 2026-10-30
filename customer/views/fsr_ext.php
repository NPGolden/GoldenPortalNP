<?php
/** Staff: attach an external service report (written outside the portal) to a vessel / project (GAS-CP-R38-003). ?p=fsr_ext[&vessel=ID][&project=ID][&ticket=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!fsr_ext_has()) { layout_head(t('fsr.ext_new'), 'projects'); echo '<div class="flash flash--warn">' . tt('fsr.ext_need_db') . '</div>'; layout_foot(); return; }
$vid = (int)($_GET['vessel'] ?? 0); $pid = (int)($_GET['project'] ?? 0); $tkId = (int)($_GET['ticket'] ?? 0);
if ($pid && !$vid) $vid = (int) scalar('SELECT vessel_id FROM projects WHERE id = ?', [$pid]);
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
[$ps, $pa] = scope_sql('p'); $projects = all("SELECT p.id, p.project_code, p.name, p.vessel_id FROM projects p WHERE p.status <> 'completed' AND {$ps} ORDER BY p.project_code, p.name", $pa);
$subs = all("SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE p.status <> 'completed' ORDER BY p.project_code, s.sort_order, s.code");
$back = $pid ? url('fsr_list', ['project' => $pid]) : ($vid ? url('vessel', ['id' => $vid, 'tab' => 'services']) : url('reports'));
layout_head(t('fsr.ext_new'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('vessels') ?>"><?= tt('nav.vessels') ?></a> › <?= tt('fsr.ext_new') ?></p>
<div class="page-head"><div><h1>📎 <?= tt('fsr.ext_new') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('fsr.ext_lede') ?></p></div></div>
<div class="card">
  <form method="post" action="<?= url('fsr_ext') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('fsr.saving') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fsr_ext_save"><?php if ($tkId): ?><input type="hidden" name="ticket_id" value="<?= $tkId ?>"><?php endif; ?>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('v.one') ?> *</label><select name="vessel_id" id="x-v" required><option value=""><?= tt('acc.any_vessel') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $vid === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pr.one') ?> <span class="hint"><?= tt('acc.optional') ?></span></label><select name="project_id" id="x-p"><option value=""><?= tt('fsr.service_call') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-v="<?= (int)$p['vessel_id'] ?>"<?= $pid === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim($p['project_code'] . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sp.one') ?> <span class="hint"><?= tt('acc.optional') ?></span></label><select name="subproject_id" id="x-s"><option value=""><?= tt('fsr.whole_project') ?></option><?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"><?= e(trim($s['code'] . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('fsr.date') ?> *</label><input type="date" name="report_date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label><?= tt('fsr.location') ?></label><input name="location" maxlength="160"></div>
      <div class="field"><label><?= tt('fsr.ext_engineers') ?></label><input name="ext_engineers" maxlength="255" placeholder="<?= tt('fsr.ext_engineers_ph') ?>"></div>
      <div class="field"><label><?= tt('fsr.pic') ?></label><input name="pic_name" maxlength="120"></div>
      <div class="field"><label><?= tt('fsr.position') ?></label><input name="pic_position" maxlength="120"></div>
      <div class="field"><label><?= tt('fsr.email') ?></label><input name="pic_email" type="email" maxlength="190"></div>
    </div>
    <div class="field"><label><?= tt('fsr.ext_file') ?> *</label><input type="file" name="file" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"><p class="hint"><?= tt('fsr.ext_file_hint') ?></p></div>
    <div class="field"><label><?= tt('fsr.ext_summary') ?></label><textarea name="ext_summary" rows="4" placeholder="<?= tt('fsr.ext_summary_ph') ?>"></textarea></div>
    <label class="chk"><input type="checkbox" name="owner_visible" value="1" checked> <?= tt('fsr.ext_owner_visible') ?></label>
    <p class="hint" style="margin:8px 0 12px;"><?= tt('fsr.ext_process_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('fsr.ext_attach') ?></button><a class="btn btn--ghost" href="<?= $back ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var v=document.getElementById('x-v'),p=document.getElementById('x-p'),s=document.getElementById('x-s');function fp(){var vid=v.value;Array.from(p.options).forEach(function(o){if(!o.value)return;o.hidden=!!vid&&o.dataset.v!==vid;if(o.hidden&&o.selected)p.value='';});fs();}function fs(){var pid=p.value;Array.from(s.options).forEach(function(o){if(!o.value)return;o.hidden=!pid||o.dataset.p!==pid;if(o.hidden&&o.selected)s.value='';});}v.addEventListener('change',fp);p.addEventListener('change',function(){if(p.value){var o=p.options[p.selectedIndex];if(o.dataset.v&&o.dataset.v!=='0')v.value=o.dataset.v;}fs();});fp();})();</script>
</div>
<?php layout_foot(); ?>

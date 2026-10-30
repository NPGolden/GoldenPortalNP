<?php
/**
 * A field service report: status, files, acceptance, audit trail.  GAS-CP-R7-001
 * Customers accept (or dispute) here; staff submit, revise, reopen.
 */
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$r  = fsr_load($id);
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff = is_staff();
$isCustomer = !$staff && (int)$u['customer_id'] === (int)$r['customer_id'];
$canAccept = $r['status'] === 'submitted' && ($isCustomer || is_cp_admin());
$tot = 0.0; foreach ($r['lines'] as $ln) $tot += (float)$ln['hours'];
$tone = ['created' => 'mute', 'saved' => 'mute', 'submitted' => 'gold', 'accepted' => 'green', 'disputed' => 'bad', 'reopened' => 'gold'];
layout_head(t('fsr.title') . ' ' . $r['report_no'], $staff ? 'projects' : 'reports');
?>
<p class="eyebrow"><a href="<?= url($staff ? 'projects' : 'reports') ?>"><?= $staff ? tt('pr.title') : tt('r.title') ?></a> › <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' › <a href="' . url('subproject', ['id' => $r['subproject_id'], 'tab' => 'service']) . '">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</a>' : '' ?></p>
<div class="page-head">
  <div>
    <h1><?= tt('fsr.title') ?> <span style="font-family:var(--mono);color:var(--gold-text);"><?= e($r['report_no']) ?></span><?= ($r['kind'] ?? 'internal') === 'external' ? ' ' . pill('navy', t('fsr.external')) . (empty($r['converted_id']) ? ' ' . pill('gold', t('fsr.not_processed')) : '') : '' ?></h1>
    <?php if (!empty($r['source'])): ?><p class="hint" style="margin:0;">⇄ <?= e(t('fsr.from_ext', ['no' => $r['source']['report_no']])) ?> <a href="<?= url('fsr', ['id' => $r['source']['id']]) ?>">›</a><?= $r['source']['ext_file_id'] ? ' · <a href="' . url('file', ['id' => $r['source']['ext_file_id']]) . '" target="_blank">⤓ ' . tt('fsr.ext_file') . '</a>' : '' ?></p><?php endif; ?>
    <?php if (($r['kind'] ?? 'internal') === 'external'): ?><p class="hint" style="margin:0;"><?= e($r['ext_engineers'] ? t('fsr.ext_engineers') . ': ' . $r['ext_engineers'] : '') ?><?= $staff ? ' · ' . ($r['owner_visible'] ? tt('fsr.ext_owner_yes') : tt('fsr.ext_owner_no')) : '' ?></p><?php if ($r['ext_summary']): ?><div class="tk-desc" style="margin-top:8px;"><?= rt_html($r['ext_summary']) ?></div><?php endif; ?><?php endif; ?>
    <p class="lede" style="margin-bottom:0;"><?= e(fmt_date($r['report_date'])) ?> · <?= e($r['vessel_name'] ?: '') ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?> · <?= fsr_status_pill($r['status']) ?><?= $r['version'] > 1 ? ' <span class="hint">R' . ((int)$r['version'] - 1) . '</span>' : '' ?><?= !empty($r['jo']) ? ' · <a href="' . url('jo', ['id' => $r['jo']['id']]) . '">' . e($r['jo']['jo_no']) . '</a>' : '' ?></p>
    <?php if (is_staff() && array_key_exists('invoicing', $r) && ($r['invoicing'] ?? 'none') !== 'none' && function_exists('acc_has') && acc_has()): $ar = $r['acc_report_id'] ? one('SELECT id, report_no, revision FROM acc_reports WHERE id = ?', [(int)$r['acc_report_id']]) : null; ?><p class="hint" style="margin:4px 0 0;">🧾 <?= e(t('acc.inv_line', ['s' => t('acc.inv_' . $r['invoicing'])])) ?><?= $ar ? ' — <a href="' . url('acc_report', ['id' => $ar['id']]) . '">' . e($ar['report_no'] . ' ' . $ar['revision']) . '</a>' : '' ?></p><?php endif; ?>
  </div>
  <div class="actions">
    <?php if ($r['pdf_file_id']): ?><a class="btn btn--gold" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">⤓ PDF</a><?php endif; ?>
    <?php if ($staff && function_exists('em_card_html') && $r['status'] !== 'draft'): ?><button class="btn" type="button" data-open-email>✉ <?= tt('em.send') ?></button><?php endif; ?>
    <?php if ($r['docx_file_id']): ?><a class="btn" href="<?= url('file', ['id' => $r['docx_file_id']]) ?>">⤓ Word</a><?php endif; ?>
    <?php if ($staff && in_array($r['status'], ['draft', 'disputed'], true) && ($r['kind'] ?? 'internal') !== 'external'): ?><a class="btn btn--ghost" href="<?= url('fsr_edit', ['id' => $id]) ?>">✎ <?= tt('fsr.edit') ?></a><?php endif; ?>
    <?php if ($staff && ($r['kind'] ?? 'internal') === 'external'): ?><?php if ($r['ext_file_id'] && !$r['pdf_file_id']): ?><a class="btn btn--gold" href="<?= url('file', ['id' => $r['ext_file_id']]) ?>" target="_blank">⤓ <?= tt('fsr.ext_file') ?></a><?php endif; ?><?php if (empty($r['converted_id'])): ?><a class="btn btn--gold" href="<?= url('fsr_edit', ['from_ext' => $id]) ?>">⇄ <?= tt('fsr.convert') ?></a><?php else: ?><a class="btn btn--ghost" href="<?= url('fsr', ['id' => $r['converted_id']]) ?>">→ <?= e($r['converted']['report_no'] ?? '') ?></a><?php endif; ?><?php endif; ?>
  </div>
</div>

<?php if ($canAccept): ?>
<div class="card fsr-accept">
  <h2><?= tt('fsr.accept_title') ?></h2>
  <p><?= tt('fsr.accept_lede') ?></p>
  <form method="post" action="<?= url('fsr', ['id' => $id]) ?>" data-lock id="acceptform">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="fsr_accept">
    <div class="field"><label><?= tt('fsr.remark') ?></label><textarea name="remark" rows="4" placeholder="<?= tt('fsr.remark_ph') ?>"></textarea>
      <p class="hint"><?= tt('fsr.notes_hint') ?></p>
      <?php if (is_cp_admin() && !$isCustomer): ?><p class="hint"><?= tt('fsr.admin_accept_hint') ?></p><?php endif; ?></div>
    <div class="btn-row">
      <button class="btn btn--gold" type="submit" data-confirm="<?= e(t('fsr.accept_confirm', ['no' => $r['report_no']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.accept') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('fsr.accept') ?></button>
      <button class="btn btn--ghost btn--danger-ghost" type="submit" name="action" value="fsr_dispute" data-confirm="<?= tt('fsr.dispute_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.dispute') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= tt('fsr.dispute') ?></button>
    </div>
    <p class="hint" style="margin-top:8px;"><?= tt('fsr.accept_hint') ?></p>
  </form>
</div>
<?php elseif ($r['status'] === 'accepted'): ?>
<div class="flash flash--good"><strong><?= tt('fsr.accepted_by') ?>:</strong> <?= e($r['accepted_name']) ?> · <?= e(fmt_datetime($r['accepted_at'])) ?><?= $r['accepted_ip'] ? ' · IP ' . e($r['accepted_ip']) : '' ?><?= $r['customer_remark'] ? '<br><em>' . e($r['customer_remark']) . '</em>' : '' ?></div>
<?php elseif ($r['status'] === 'disputed'): ?>
<div class="flash flash--bad"><strong><?= tt('fsr.st_disputed') ?></strong><?= $r['customer_remark'] ? ' — ' . e($r['customer_remark']) : '' ?><?php if ($staff): ?><br><span class="hint"><?= tt('fsr.dispute_staff_hint') ?></span><?php endif; ?></div>
<?php elseif ($r['status'] === 'submitted' && $staff): ?>
<div class="flash flash--warn"><?= tt('fsr.awaiting') ?>
  <form method="post" action="<?= url('fsr', ['id' => $id]) ?>" style="display:inline;margin-left:10px;" data-lock data-confirm="<?= tt('fsr.reopen_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.reopen') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="fsr_reopen">
    <button class="btn btn--ghost btn--sm" type="submit"><?= tt('fsr.reopen') ?></button></form>
</div>
<?php elseif ($r['status'] === 'draft'): ?>
<div class="flash flash--warn"><?= tt('fsr.draft_hint') ?></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2><?= tt('fsr.details') ?></h2>
    <table class="kv"><tbody>
      <tr><th><?= tt('fsr.date') ?></th><td><?= e(fmt_date($r['report_date'])) ?></td><th><?= tt('fsr.pic') ?></th><td><?= e($r['pic_name'] ?: '—') ?></td></tr>
      <tr><th><?= tt('fsr.customer') ?></th><td><?= e($r['customer_name'] ?: $r['customer_db_name']) ?></td><th><?= tt('fsr.position') ?></th><td><?= e($r['pic_position'] ?: '—') ?></td></tr>
      <tr><th><?= tt('fsr.vessel') ?></th><td><?= e($r['vessel_name'] ?: '—') ?></td><th><?= tt('fsr.mobile') ?></th><td><?= e($r['pic_mobile'] ?: '—') ?></td></tr>
      <tr><th><?= tt('fsr.location') ?></th><td><?= e($r['location'] ?: '—') ?></td><th><?= tt('fsr.email') ?></th><td><?= e($r['pic_email'] ?: '—') ?></td></tr>
    </tbody></table>
  </div>
  <div class="card">
    <h2><?= tt('fsr.summary') ?></h2>
    <table><thead><tr><th><?= tt('fsr.engineer') ?></th><th><?= tt('fsr.specialty') ?></th><th title="<?= tt('fsr.tt_wt') ?>">WT</th><th title="<?= tt('fsr.tt_tt') ?>">TT</th><th title="<?= tt('fsr.tt_nc') ?>">NC</th><th><?= tt('fsr.total') ?></th></tr></thead>
      <tbody><?php foreach ($r['summary']['rows'] as $sr): ?><tr><td><?= e($sr['engineer']) ?></td><td><?= e($sr['specialty']) ?></td><td class="num"><?= e(fsr_fmt_hours((float)$sr['wt'])) ?></td><td class="num"><?= e(fsr_fmt_hours((float)$sr['tt'])) ?></td><td class="num hint"><?= e(fsr_fmt_hours((float)($sr['nc'] ?? 0))) ?></td><td class="num"><strong><?= e(fsr_fmt_hours((float)$sr['total'])) ?></strong></td></tr><?php endforeach; ?>
      <tr><td colspan="2"><strong><?= tt('fsr.total') ?></strong></td><td class="num"><strong><?= e(fsr_fmt_hours($r['summary']['wt'])) ?></strong></td><td class="num"><strong><?= e(fsr_fmt_hours($r['summary']['tt'])) ?></strong></td><td class="num hint"><strong><?= e(fsr_fmt_hours((float)($r['summary']['nc'] ?? 0))) ?></strong></td><td class="num"><strong><?= e(fsr_fmt_hours($r['summary']['total'])) ?></strong></td></tr></tbody></table>
    <?php if (($r['summary']['nc'] ?? 0) > 0): ?><p class="hint" style="margin:6px 0 0;"><?= e(t('fsr.nc_note', ['h' => fsr_fmt_hours((float)$r['summary']['nc']), 'c' => fsr_fmt_hours((float)$r['summary']['chargeable'])])) ?></p><?php endif; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt('fsr.works') ?></h2>
  <?php foreach ($r['works'] as $wk): ?>
    <div class="fsr-workview">
      <p><strong><?= e(t('fsr.task_n', ['n' => $wk['seq']])) ?></strong></p>
      <div class="grid grid-2">
        <div><?= $wk['description'] ? rt_html($wk['description']) : '<p>—</p>' ?></div>
        <div>
          <?php if ($wk['parts']): ?><p class="hint"><strong><?= tt('fsr.parts') ?>:</strong> <?= e(implode(' · ', array_map(fn($p) => $p['d'] . ' (' . $p['t'] . ')', $wk['parts']))) ?></p><?php endif; ?>
          <?php if ($wk['open_issues']): ?><p class="hint"><strong><?= tt('fsr.issues') ?>:</strong> <?= e($wk['open_issues']) ?></p><?php endif; ?>
        </div>
      </div>
      <?php if ($wk['lines']): ?>
      <table style="margin-top:6px;"><thead><tr><th><?= tt('fsr.engineer') ?></th><th><?= tt('fsr.specialty') ?></th><th><?= tt('fsr.start') ?></th><th><?= tt('fsr.end') ?></th><th><?= tt('fsr.type') ?></th><th><?= tt('fsr.hours') ?></th></tr></thead>
        <tbody><?php foreach ($wk['lines'] as $ln): ?><tr><td><?= e($ln['engineer'] ?: $ln['remarks']) ?></td><td><?= e($ln['specialty']) ?></td><td class="num"><?= e(fsr_fmt_time($ln['start_time'])) ?></td><td class="num"><?= e(fsr_fmt_time($ln['end_time'])) ?></td><td class="num"><?= e($ln['time_type']) ?></td><td class="num"><?= e(fsr_fmt_hours((float)$ln['hours'])) ?></td></tr><?php endforeach; ?></tbody></table>
      <?php endif; ?>
      <?php if ($wk['photos']): ?>
        <div class="fsr-photos" style="margin-top:10px;"><?php foreach ($wk['photos'] as $ph): ?><figure><a href="<?= url('file', ['id' => $ph['id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => $ph['id']]) ?>" alt=""></a><figcaption><strong>Figure <?= (int)$ph['figure'] ?></strong><?= $ph['title'] ? ' — ' . e($ph['title']) : '' ?></figcaption></figure><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; if (!$r['works']): ?><p class="hint">—</p><?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('fsr.trail') ?></h2>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('fsr.event') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('ll.ip') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
    <tbody><?php foreach (array_reverse($r['events']) as $ev): ?>
      <tr><td class="date"><?= e(fmt_datetime($ev['created_at'])) ?></td><td><?= pill($tone[$ev['event']] ?? 'mute', t('fsr.ev_' . $ev['event'])) ?></td><td><?= e($ev['user_name'] ?: '—') ?></td><td class="num"><?= e($ev['ip'] ?: '—') ?></td><td><?= e($ev['note'] ?: '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php if ($staff && ($r['kind'] ?? 'internal') === 'external' && function_exists('fsr_ext_relink')): [$rlScope, $rlArgs] = scope_sql('p'); $rlProjects = all("SELECT p.id, p.project_code, p.name, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE {$rlScope} AND (p.status <> 'completed' OR p.id = ?) ORDER BY p.vessel_id = ? DESC, p.status = 'active' DESC, p.project_code", array_merge($rlArgs, [(int)$r['project_id'], (int)(scalar('SELECT vessel_id FROM projects WHERE id = ?', [(int)$r['project_id']]) ?: 0)])); $rlSubs = all("SELECT s.id, s.project_id, s.code, s.name FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE {$rlScope} ORDER BY s.project_id, s.code", $rlArgs); ?>
<details class="card pd-add" id="relink"<?= str_starts_with((string)($r['project_code'] ?? ''), 'GEN-') ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm">🔗 <?= tt('fsr.relink') ?></summary>
  <form method="post" action="<?= url('fsr', ['id' => $id]) ?>" class="fltbar" style="grid-template-columns: 1.4fr 1fr auto; margin-top:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="fsr_ext_relink"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('pr.one') ?></label><select name="project_id" id="rl-project"><?php foreach ($rlProjects as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= (int)$pp['id'] === (int)$r['project_id'] ? ' selected' : '' ?>><?= e(trim(($pp['project_code'] ?: '') . ' ' . $pp['name'])) ?><?= $pp['vessel'] ? ' · ' . e($pp['vessel']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sp.one') ?></label><select name="subproject_id" id="rl-sub" data-nosearch><option value="">—</option></select></div>
    <div class="fltbar-actions"><button class="btn btn--gold btn--sm" type="submit"><?= tt('fsr.relink_btn') ?></button></div>
  </form>
  <p class="hint" style="margin:6px 0 0;"><?= tt('fsr.relink_hint') ?></p>
  <script nonce="<?= csp_nonce() ?>">(function(){var S=<?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'p' => (int)$x['project_id'], 'l' => trim($x['code'] . ' ' . $x['name'])], $rlSubs), JSON_UNESCAPED_UNICODE) ?>,cur=<?= (int)($r['subproject_id'] ?? 0) ?>,ps=document.getElementById('rl-project'),ss=document.getElementById('rl-sub');function f(){var p=+ps.value;ss.innerHTML='<option value="">—</option>';S.filter(function(r){return r.p===p;}).forEach(function(r){var o=document.createElement('option');o.value=r.id;o.textContent=r.l;if(r.id===cur)o.selected=true;ss.appendChild(o);});}ps.addEventListener('change',function(){cur=0;f();});f();})();</script>
</details>
<?php endif; ?>
<?php if ($staff && function_exists('em_card_html') && $r['status'] !== 'draft'): $emAtts = [];
  if ($r['pdf_file_id']) $emAtts[] = ['f:' . (int)$r['pdf_file_id'], $r['report_no'] . '.pdf']; if ($r['docx_file_id']) $emAtts[] = ['f:' . (int)$r['docx_file_id'], $r['report_no'] . '.docx'];
  $emVid = (int) (scalar('SELECT vessel_id FROM projects WHERE id = ?', [(int)$r['project_id']]) ?: 0);
  echo em_card_html(['kind' => 'fsr', 'ctx' => ['no' => (string)$r['report_no'], 'title' => (string)($r['subject'] ?? ''), 'vessel' => (string)$r['vessel_name'], 'customer' => (string)($r['customer_name'] ?: ($r['customer_db_name'] ?? '')), 'project' => (string)($r['project_code'] ?? ''), 'doc' => t('em.w_fsr', ['no' => $r['report_no']]), 'project_id' => (int)$r['project_id'], 'customer_id' => (int)($r['customer_id'] ?? 0)],
    'people' => em_people($emVid ?: null, (int)($r['customer_id'] ?? 0) ?: null, $r['pic_email'] ? [[(string)$r['pic_name'], $r['pic_email'], (string)$r['pic_position']]] : [], function_exists('spc_emails') && !empty($r['subproject_id']) ? spc_emails((int)$r['subproject_id']) : []), 'atts' => $emAtts, 'back' => url('fsr', ['id' => $id])]); endif; ?>
<?php $adAction = 'fsr_delete'; $adId = $id; $adBack = 'fsr_list'; $adConfirm = t('fsr.delete_confirm', ['no' => $r['report_no']]); $adNote = t('fsr.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php layout_foot(); ?>

<?php
/**
 * An inspection report (SF-GLD-020): cover, introduction, systems, files, trail.   GAS-CP-R23-003
 * Staff submit for check, check & issue, re-open; the owner sees issued reports.
 */
require __DIR__ . '/_layout.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$r  = isr_load($id);
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff = is_staff();
$own = (int)$r['created_by'] === (int)$u['id'];
$canIssue = $staff && $r['status'] !== 'issued' && (is_cp_admin() || !$own || $r['status'] === 'draft');
$tone = ['created' => 'mute', 'saved' => 'mute', 'submitted' => 'gold', 'issued' => 'green', 'reopened' => 'gold'];
$back = url('isr', ['id' => $id]);
layout_head(t('isr.title') . ' ' . $r['report_no'], $staff ? 'projects' : 'reports');
?>
<p class="eyebrow"><a href="<?= url($staff ? 'projects' : 'reports') ?>"><?= $staff ? tt('pr.title') : tt('r.title') ?></a> › <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' › <a href="' . url('subproject', ['id' => $r['subproject_id'], 'tab' => 'service']) . '">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</a>' : '' ?></p>
<div class="page-head">
  <div>
    <h1><?= tt('isr.title') ?> <span style="font-family:var(--mono);color:var(--gold-text);"><?= e($r['report_no']) ?></span></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(fmt_date($r['report_date'])) ?> · <?= e($r['vessel_name'] ?: '') ?><?= $r['location'] ? ' · ' . e($r['location']) : '' ?> · <?= isr_status_pill($r['status']) ?><?= $r['issued_at'] ? ' <span class="hint">R' . (int)$r['version'] . '</span>' : '' ?></p>
  </div>
  <div class="actions">
    <?php if ($r['pdf_file_id']): ?><a class="btn btn--gold" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">⤓ PDF</a><?php endif; ?>
    <?php if ($staff && function_exists('em_card_html') && in_array($r['status'], ['issued', 'checking'], true)): ?><button class="btn" type="button" data-open-email>✉ <?= tt('em.send') ?></button><?php endif; ?>
    <?php if ($r['docx_file_id']): ?><a class="btn" href="<?= url('file', ['id' => $r['docx_file_id']]) ?>">⤓ Word</a><?php endif; ?>
    <?php if ($staff && $r['status'] !== 'issued'): ?><a class="btn btn--ghost" href="<?= url('isr_edit', ['id' => $id]) ?>">✎ <?= tt('isr.edit') ?></a><?php endif; ?>
  </div>
</div>

<?php if ($staff && $r['status'] === 'draft'): ?>
<div class="flash flash--warn"><?= tt('isr.draft_hint') ?>
  <?php if ($r['items']): ?><form method="post" action="<?= $back ?>" style="display:inline;margin-left:10px;" data-lock data-confirm="<?= tt('isr.submit_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('isr.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="isr_submit"><button class="btn btn--gold btn--sm" type="submit"><?= tt('isr.submit') ?></button></form><?php endif; ?>
</div>
<?php elseif ($staff && $r['status'] === 'checking'): ?>
<div class="card isr-check">
  <h2><?= tt('isr.check_title') ?></h2>
  <p><?= tt('isr.check_lede') ?><?= $r['submitted_at'] ? ' <span class="hint">' . e(t('isr.submitted_on', ['who' => (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [(int)$r['submitted_by']]), 'when' => fmt_datetime($r['submitted_at'])])) . '</span>' : '' ?></p>
  <div class="btn-row">
    <?php if ($canIssue): ?><form method="post" action="<?= $back ?>" data-lock data-confirm="<?= e(t('isr.issue_confirm', ['no' => $r['report_no']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('isr.issue') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="isr_issue"><button class="btn btn--gold" type="submit">✓ <?= tt('isr.issue') ?></button></form>
    <?php else: ?><span class="hint"><?= tt('isr.self_check') ?></span><?php endif; ?>
    <form method="post" action="<?= $back ?>" data-lock data-confirm="<?= tt('isr.reopen_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('isr.reopen') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="isr_reopen"><button class="btn btn--ghost" type="submit"><?= tt('isr.reopen') ?></button></form>
  </div>
</div>
<?php elseif ($r['status'] === 'issued'): ?>
<div class="flash flash--good"><strong><?= tt('isr.issued_by') ?>:</strong> <?= e($r['checked_name'] ?: '—') ?> · <?= e(fmt_datetime($r['issued_at'])) ?> · R<?= (int)$r['version'] ?>
  <?php if ($staff && (is_cp_admin() || (int)$r['checked_by'] === (int)$u['id'])): ?><form method="post" action="<?= $back ?>" style="display:inline;margin-left:10px;" data-lock data-confirm="<?= tt('isr.reopen_issued_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('isr.reopen') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="isr_reopen"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('isr.reopen') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('fsr.details') ?></h2>
  <table class="kv"><tbody>
    <tr><th><?= tt('fsr.date') ?></th><td><?= e(fmt_date($r['report_date'])) ?></td><th><?= tt('isr.performed') ?></th><td><?= e($r['performed_name'] ?: '—') ?></td></tr>
    <tr><th><?= tt('fsr.customer') ?></th><td><?= e($r['customer_name'] ?: $r['customer_db_name']) ?></td><th><?= tt('fsr.pic') ?></th><td><?= e($r['pic_name'] ?: '—') ?></td></tr>
    <tr><th><?= tt('fsr.vessel') ?></th><td><?= e($r['vessel_name'] ?: '—') ?></td><th><?= tt('fsr.location') ?></th><td><?= e($r['location'] ?: '—') ?></td></tr>
    <tr><th><?= tt('isr.class_flag') ?></th><td><?= e($r['class_flag'] ?: '—') ?></td><th><?= tt('isr.builder_year') ?></th><td><?= e($r['builder_year'] ?: '—') ?></td></tr>
    <tr><th><?= tt('isr.hull_no') ?></th><td><?= e($r['hull_no'] ?: '—') ?></td><th><?= tt('isr.subject') ?></th><td><?= e($r['subject'] ?: '—') ?></td></tr>
  </tbody></table>
</div>

<div class="card">
  <h2><?= tt('isr.intro') ?></h2>
  <div class="grid grid-3 isr-intro">
    <div><h3 class="ac-h"><?= tt('isr.objective') ?></h3><?= $r['objective'] ? rt_html($r['objective']) : '<p>—</p>' ?></div>
    <div><h3 class="ac-h"><?= tt('isr.findings') ?></h3><?= $r['findings'] ? rt_html($r['findings']) : '<p>—</p>' ?></div>
    <div><h3 class="ac-h"><?= tt('isr.risks') ?></h3><?= $r['risks'] ? rt_html($r['risks']) : '<p>—</p>' ?></div>
  </div>
</div>

<div class="card">
  <h2><?= tt('isr.systems') ?> <span class="hint" style="font-weight:400;">(<?= count($r['items']) ?>)</span></h2>
  <?php if ($r['items']): ?>
  <div class="table-scroll" style="margin-bottom:14px;"><table class="tbl--compact">
    <thead><tr><th>#</th><th><?= tt('isr.system') ?></th><th><?= tt('isr.risk_level') ?></th><th><?= tt('isr.checklist') ?></th><th><?= tt('isr.attachments') ?></th></tr></thead>
    <tbody><?php foreach ($r['items'] as $it): ?><tr><td class="num"><a href="#sys<?= (int)$it['seq'] ?>"><?= (int)$it['seq'] ?></a></td><td><?= e($it['system_name']) ?></td><td><?= isr_risk_pill($it['risk_level']) ?></td><td class="num"><?= count($it['checklist']) ?></td><td class="num"><?= count($it['files']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php foreach ($r['items'] as $it): ?>
    <div class="fsr-workview isr-sysview" id="sys<?= (int)$it['seq'] ?>">
      <p><strong><?= e(t('isr.system_n', ['n' => $it['seq']])) ?> — <?= e($it['system_name']) ?></strong> <?= isr_risk_pill($it['risk_level']) ?></p>
      <div class="grid grid-2">
        <div><h3 class="ac-h"><?= tt('isr.condition') ?></h3><?= $it['condition_found'] ? rt_html($it['condition_found']) : '<p>—</p>' ?></div>
        <div><h3 class="ac-h"><?= tt('isr.class_regs') ?></h3><?= $it['class_regs'] ? rt_html($it['class_regs']) : '<p>—</p>' ?></div>
      </div>
      <?php if ($it['checklist']): ?>
      <h3 class="ac-h"><?= tt('isr.checklist') ?></h3>
      <div class="table-scroll"><table class="tbl--compact isr-qa-table"><thead><tr><th>#</th><th><?= tt('isr.question') ?></th><th><?= tt('isr.answer') ?></th></tr></thead>
        <tbody><?php foreach ($it['checklist'] as $j => $qa): ?><tr><td class="num"><?= $j + 1 ?></td><td><?= e($qa['q'] ?? '') ?></td><td><?= ($qa['a'] ?? '') !== '' ? e($qa['a']) : '<span class="hint">—</span>' ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php endif; ?>
      <div class="grid grid-2">
        <div><h3 class="ac-h"><?= tt('isr.risk_analysis') ?></h3><?= $it['risk_analysis'] ? rt_html($it['risk_analysis']) : '<p>—</p>' ?></div>
        <div><h3 class="ac-h"><?= tt('isr.actions') ?></h3><?= $it['actions'] ? rt_html($it['actions']) : '<p>—</p>' ?></div>
      </div>
      <?php if ($it['files']): ?>
        <div class="fsr-photos" style="margin-top:10px;"><?php foreach ($it['files'] as $f): ?><figure><a href="<?= url('file', ['id' => $f['id']]) ?>" target="_blank"><?php if ($f['is_image']): ?><img src="<?= url('file', ['id' => $f['id']]) ?>" alt=""><?php else: ?><div class="isr-doc">📄<br><span><?= e(mb_substr((string)$f['original_name'], 0, 28)) ?></span></div><?php endif; ?></a><figcaption><?= $f['is_image'] ? '<strong>Figure ' . (int)$f['figure'] . '</strong>' : '<strong>' . tt('isr.doc') . '</strong>' ?><?= $f['title'] ? ' — ' . e($f['title']) : '' ?></figcaption></figure><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; if (!$r['items']): ?><p class="hint"><?= tt('isr.no_systems') ?></p><?php endif; ?>
</div>

<?php if ($staff): ?>
<div class="card">
  <h2><?= tt('fsr.trail') ?></h2>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('fsr.event') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
    <tbody><?php foreach (array_reverse($r['events']) as $ev): ?>
      <tr><td class="date"><?= e(fmt_datetime($ev['at'] ?? $ev['created_at'] ?? null)) ?></td><td><?= pill($tone[$ev['event']] ?? 'mute', t('isr.ev_' . $ev['event'])) ?></td><td><?= e($ev['user_name'] ?: '—') ?></td><td><?= e($ev['note'] ?: '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php if ($staff && function_exists('em_card_html') && in_array($r['status'], ['issued', 'checking'], true)): $emAtts = [];
  if ($r['pdf_file_id']) $emAtts[] = ['f:' . (int)$r['pdf_file_id'], $r['report_no'] . '.pdf']; if ($r['docx_file_id']) $emAtts[] = ['f:' . (int)$r['docx_file_id'], $r['report_no'] . '.docx'];
  $emVid = (int) (scalar('SELECT vessel_id FROM projects WHERE id = ?', [(int)$r['project_id']]) ?: 0);
  echo em_card_html(['kind' => 'isr', 'ctx' => ['no' => (string)$r['report_no'], 'title' => (string)($r['subject'] ?? ''), 'vessel' => (string)$r['vessel_name'], 'customer' => (string)($r['customer_name'] ?: ($r['customer_db_name'] ?? '')), 'project' => (string)($r['project_code'] ?? ''), 'doc' => t('em.w_isr', ['no' => $r['report_no']]), 'project_id' => (int)$r['project_id'], 'customer_id' => (int)($r['customer_id'] ?? 0)],
    'people' => em_people($emVid ?: null, (int)($r['customer_id'] ?? 0) ?: null, [], function_exists('spc_emails') && !empty($r['subproject_id']) ? spc_emails((int)$r['subproject_id']) : []), 'atts' => $emAtts, 'back' => url('isr', ['id' => $id])]); endif; ?>
<?php $adAction = 'isr_delete'; $adId = $id; $adBack = 'isr_list'; $adConfirm = t('isr.delete_confirm', ['no' => $r['report_no']]); $adNote = t('isr.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php endif; ?>
<?php layout_foot(); ?>

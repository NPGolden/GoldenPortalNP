<?php
/** A work order: data, approval, progress, linked service reports, trail.   GAS-CP-R8-001 */
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$j  = jo_load($id);
if (!$j) { http_response_code(404); layout_head(t('err.notfound'), 'joborders'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff = is_staff();
$isCustomer = !$staff && (int)$u['customer_id'] === (int)$j['customer_id'];
$canApprove = $j['status'] === 'pending_approval' && ($isCustomer || is_cp_admin());
$tone = ['created' => 'mute', 'saved' => 'mute', 'submitted' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'started' => 'gold', 'done' => 'green', 'cancelled' => 'mute', 'reopened' => 'gold', 'note' => 'mute'];
$act = fn(string $action, string $label, string $cls, string $confirm, bool $danger = false) =>
    '<form method="post" action="' . url('jo', ['id' => $id]) . '" style="display:inline;" data-lock data-confirm="' . e($confirm) . '" data-confirm-title="' . tt('dlg.title') . '" data-confirm-ok="' . e($label) . '" data-confirm-cancel="' . tt('btn.cancel') . '"' . ($danger ? ' data-confirm-danger' : '') . '>'
    . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $action . '"><button class="btn ' . $cls . '" type="submit">' . e($label) . '</button></form>';
layout_head($j['jo_no'], $staff ? 'projects' : 'joborders');
?>
<p class="eyebrow"><a href="<?= url('jo_list') ?>"><?= tt('jo.plural') ?></a> › <a href="<?= url('jo_list', ['project' => $j['project_id']]) ?>"><?= e($j['project_code'] ?: $j['project_name']) ?></a></p>
<div class="page-head">
  <div>
    <h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($j['jo_no']) ?></span></h1>
    <p class="lede" style="margin-bottom:0;"><strong><?= e($j['title']) ?></strong> · <?= jo_status_pill($j['status']) ?><?= $j['priority'] === 'urgent' ? ' ' . pill('bad', t('jo.urgent')) : '' ?></p>
  </div>
  <div class="actions">
    <?php if ($j['status'] === 'draft' && ($staff || (int)$j['requested_by'] === (int)$u['id'])): ?><a class="btn btn--ghost" href="<?= url('jo_edit', ['id' => $id]) ?>">✎ <?= tt('jo.edit') ?></a><?php endif; ?>
    <?php if ($staff && in_array($j['status'], ['approved', 'in_progress'], true)): ?><a class="btn btn--gold" href="<?= url('fsr_edit', ['project' => $j['project_id'], 'jo' => $id]) ?>">+ <?= tt('fsr.new') ?></a><?php endif; ?>
  </div>
</div>

<?php if ($canApprove): ?>
<div class="card fsr-accept">
  <h2><?= tt('jo.approve_title') ?></h2>
  <p><?= tt('jo.approve_lede') ?></p>
  <form method="post" action="<?= url('jo', ['id' => $id]) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="jo_approve">
    <div class="field"><label><?= tt('jo.note') ?></label><textarea name="note" rows="3" placeholder="<?= tt('jo.note_ph') ?>"></textarea>
      <?php if (is_cp_admin() && !$isCustomer): ?><p class="hint"><?= tt('jo.admin_approve_hint') ?></p><?php endif; ?></div>
    <div class="btn-row">
      <button class="btn btn--gold" type="submit" data-confirm="<?= e(t('jo.approve_confirm', ['no' => $j['jo_no']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('jo.approve') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('jo.approve') ?></button>
      <button class="btn btn--ghost btn--danger-ghost" type="submit" name="action" value="jo_reject" data-confirm="<?= tt('jo.reject_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('jo.reject') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= tt('jo.reject') ?></button>
    </div>
  </form>
</div>
<?php elseif ($j['status'] === 'pending_approval'): ?>
<div class="flash flash--warn"><?= tt('jo.awaiting_long') ?> <?= $staff ? $act('jo_reopen', t('jo.withdraw'), 'btn--ghost btn--sm', t('jo.withdraw_confirm')) : '' ?></div>
<?php elseif ($j['status'] === 'approved'): ?>
<div class="flash flash--good"><strong><?= tt('jo.approved_by') ?>:</strong> <?= e($j['approved_name']) ?> · <?= e(fmt_datetime($j['approved_at'])) ?><?= $j['approved_ip'] ? ' · IP ' . e($j['approved_ip']) : '' ?><?= $j['approval_note'] ? '<br><em>' . e($j['approval_note']) . '</em>' : '' ?>
  <?php if ($staff): ?><span style="margin-left:10px;"><?= $act('jo_start', t('jo.start'), 'btn--gold btn--sm', t('jo.start_confirm')) ?> <?= $act('jo_cancel', t('jo.cancel'), 'btn--ghost btn--sm btn--danger-ghost', t('jo.cancel_confirm'), true) ?></span><?php endif; ?></div>
<?php elseif ($j['status'] === 'in_progress'): ?>
<div class="flash flash--warn"><?= e(t('jo.started_on', ['d' => fmt_datetime($j['started_at'])])) ?><?php if ($staff): ?> <span style="margin-left:10px;"><?= $act('jo_done', t('jo.done'), 'btn--gold btn--sm', t('jo.done_confirm')) ?></span><?php endif; ?></div>
<?php elseif ($j['status'] === 'rejected'): ?>
<div class="flash flash--bad"><strong><?= tt('jo.st_rejected') ?></strong> — <?= e($j['approved_name']) ?> · <?= e(fmt_datetime($j['approved_at'])) ?><?= $j['approval_note'] ? '<br><em>' . e($j['approval_note']) . '</em>' : '' ?><?php if ($staff): ?> <span style="margin-left:10px;"><?= $act('jo_reopen', t('jo.reopen'), 'btn--ghost btn--sm', t('jo.reopen_confirm')) ?></span><?php endif; ?></div>
<?php elseif ($j['status'] === 'done'): ?>
<div class="flash flash--good"><?= e(t('jo.done_on', ['d' => fmt_datetime($j['done_at'])])) ?></div>
<?php elseif ($j['status'] === 'draft'): ?>
<div class="flash flash--warn"><?= tt('jo.draft_hint') ?>
  <?php if ($staff || (int)$j['requested_by'] === (int)$u['id']): ?><span style="margin-left:10px;"><?= $act('jo_submit', t('jo.submit'), 'btn--gold btn--sm', $staff ? t('jo.submit_confirm_golden') : t('jo.submit_confirm_customer')) ?></span><?php endif; ?></div>
<?php endif; ?>

<div class="grid grid-2">
  <div class="card">
    <h2><?= tt('jo.details') ?></h2>
    <table class="kv"><tbody>
      <tr><th><?= tt('pr.one') ?></th><td><?= e(($j['project_code'] ?: '') . ' ' . $j['project_name']) ?></td><th><?= tt('th.vessel') ?></th><td><?= e($j['vessel'] ?: '—') ?></td></tr>
      <tr><th><?= tt('jo.raised_by') ?></th><td><?= e($j['requested_name'] ?: '—') ?> <span class="hint">(<?= $j['requested_side'] === 'customer' ? e($j['customer_name'] ?? tt('role.customer')) : 'Golden A/S' ?>)</span></td><th><?= tt('jo.raised_on') ?></th><td><?= e(fmt_datetime($j['created_at'])) ?></td></tr>
      <tr><th><?= tt('fsr.location') ?></th><td><?= e($j['location'] ?: '—') ?></td><th><?= tt('jo.for') ?></th><td><?= e(fmt_date($j['requested_for'])) ?></td></tr>
      <tr><th><?= tt('jo.priority') ?></th><td><?= e(t('jo.' . $j['priority'])) ?></td><th><?= tt('jo.approval') ?></th><td><?= $j['approved_at'] ? e($j['approved_name'] . ' · ' . fmt_datetime($j['approved_at'])) : '—' ?></td></tr>
    </tbody></table>
    <h3 style="margin-top:14px;"><?= tt('jo.description') ?></h3>
    <p style="white-space:pre-line;"><?= e($j['description'] ?: '—') ?></p>
  </div>
  <div class="card">
    <h2><?= tt('fsr.plural') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('jo.reports_hint')) ?></span></h2>
    <?php if (!$j['reports']): ?><p class="hint"><?= tt('fsr.none') ?></p>
    <?php else: ?>
      <table><thead><tr><th><?= tt('fsr.no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('fsr.engineer') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
        <tbody><?php foreach ($j['reports'] as $r): ?><tr><td class="num"><a href="<?= url('fsr', ['id' => $r['id']]) ?>"><?= e($r['report_no']) ?></a></td><td class="date"><?= e(fmt_date($r['report_date'])) ?></td><td><?= e($r['submitted_name'] ?: '—') ?></td><td><?= fsr_status_pill($r['status']) ?></td><td class="row-actions"><?php if ($r['pdf_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">PDF</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt('fsr.trail') ?></h2>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('fsr.event') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('ll.ip') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
    <tbody><?php foreach (array_reverse($j['events']) as $ev): ?>
      <tr><td class="date"><?= e(fmt_datetime($ev['created_at'])) ?></td><td><?= pill($tone[$ev['event']] ?? 'mute', t('jo.ev_' . $ev['event'])) ?></td><td><?= e($ev['user_name'] ?: '—') ?></td><td class="num"><?= e($ev['ip'] ?: '—') ?></td><td><?= e($ev['note'] ?: '') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<?php $adAction = 'jo_delete'; $adId = $id; $adBack = 'joborders'; $adConfirm = t('jo.delete_confirm', ['no' => $j['jo_no']]); $adNote = t('jo.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php layout_foot(); ?>

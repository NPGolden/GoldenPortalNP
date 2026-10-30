<?php
/** One timesheet: lines, totals by type, approval, invoicing, history.   GAS-CP-R11-001 */
require __DIR__ . '/_layout.php';
$u  = require_staff();
$id = (int)($_GET['id'] ?? 0);
$t  = ts_load($id);
if (!$t) { http_response_code(404); layout_head(t('err.notfound'), 'timesheets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$mine = (int)$t['user_id'] === (int)$u['id'];
$total = (float)$t['h_normal'] + (float)$t['h_overtime'] + (float)$t['h_holiday'];
// per sub-project × type
$bySub = [];
foreach ($t['lines'] as $ln) {
    $k = $ln['subproject_id'] ? trim($ln['sub_code'] . ' ' . $ln['sub_name']) : t('ts.main_project');
    $bySub[$k] = $bySub[$k] ?? ['normal' => 0, 'overtime' => 0, 'holiday' => 0];
    $bySub[$k][$ln['hour_type']] += (float)$ln['hours'];
}
$evLabel = fn(string $e) => t('ts.ev_' . $e);
layout_head($t['ts_no'], 'timesheets');
?>
<p class="eyebrow"><a href="<?= url('timesheets') ?>"><?= tt('ts.title') ?></a> › <?= pr_link((int)$t['project_id'], $t['project_code'], $t['project_name']) ?></p>
<div class="page-head">
  <div>
    <h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($t['ts_no']) ?></span> <?= e($t['user_name']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(($t['project_code'] ?: '') . ' ' . $t['project_name']) ?><?= $t['vessel'] ? ' · ' . e($t['vessel']) : '' ?><?= $t['discipline_name'] ? ' · ' . e($t['discipline_name']) : '' ?> · <?= e(fmt_date($t['period_start'])) ?> – <?= e(fmt_date($t['period_end'])) ?></p>
  </div>
  <div class="actions">
    <?= ts_status_pill($t['status']) ?>
    <?php if (in_array($t['status'], ['draft', 'rejected'], true) && ($mine || is_cp_admin())): ?><a class="btn btn--ghost" href="<?= url('timesheet_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?>
  </div>
</div>
<?php if (is_staff() && array_key_exists('invoicing', $t) && ($t['invoicing'] ?? 'none') !== 'none' && function_exists('acc_has') && acc_has()): $ar = $t['acc_report_id'] ? one('SELECT id, report_no, revision FROM acc_reports WHERE id = ?', [(int)$t['acc_report_id']]) : null; ?><div class="flash flash--<?= $t['invoicing'] === 'invoiced' ? 'good' : 'warn' ?>">🧾 <?= e(t('acc.inv_line', ['s' => t('acc.inv_' . $t['invoicing'])])) ?><?= $ar ? ' — <a href="' . url('acc_report', ['id' => $ar['id']]) . '">' . e($ar['report_no'] . ' ' . $ar['revision']) . '</a>' : '' ?><?= $t['invoice_ref'] ? ' · ' . e($t['invoice_ref']) : '' ?></div><?php endif; ?>
<?php if ($t['status'] === 'rejected' && $t['approval_note']): ?><div class="flash flash--bad"><?= e(t('ts.rejected_by', ['name' => $t['approved_name'], 'note' => $t['approval_note']])) ?></div><?php endif; ?>

<div class="kpi" style="margin-bottom:16px;">
  <div class="stat"><b><?= ts_fmt((float)$t['h_normal']) ?></b><?= tt('ts.normal') ?></div>
  <div class="stat gold"><b><?= ts_fmt((float)$t['h_overtime']) ?></b><?= tt('ts.overtime') ?></div>
  <div class="stat"><b class="ts-hol"><?= ts_fmt((float)$t['h_holiday']) ?></b><?= tt('ts.holiday') ?></div>
  <div class="stat green"><b><?= ts_fmt($total) ?></b><?= tt('ts.total') ?></div>
</div>

<div class="cols">
  <div>
    <div class="card">
      <h2><?= tt('ts.lines') ?></h2>
      <?php if ($t['note']): ?><p class="hint" style="margin:0 0 12px;"><?= e($t['note']) ?></p><?php endif; ?>
      <div class="table-scroll"><table>
        <thead><tr><th><?= tt('ts.date') ?></th><th><?= tt('ts.on') ?></th><th><?= tt('ts.type') ?></th><th class="num"><?= tt('ts.hours') ?></th><th><?= tt('ts.what') ?></th></tr></thead>
        <tbody><?php foreach ($t['lines'] as $ln): ?>
          <tr><td class="date"><?= e(fmt_date($ln['work_date'])) ?></td>
            <td><?= $ln['subproject_id'] ? '<a href="' . url('subproject', ['id' => $ln['subproject_id']]) . '">' . e(trim($ln['sub_code'] . ' ' . $ln['sub_name'])) . '</a>' : '<span class="hint">' . tt('ts.main_project') . '</span>' ?></td>
            <td><?= pill($ln['hour_type'] === 'normal' ? 'mute' : ($ln['hour_type'] === 'overtime' ? 'gold' : 'bad'), t('ts.' . $ln['hour_type'])) ?></td>
            <td class="num"><?= ts_fmt((float)$ln['hours']) ?></td><td><?= e($ln['description'] ?: '—') ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <h3 style="margin-top:18px;"><?= tt('ts.by_sub') ?></h3>
      <div class="table-scroll"><table>
        <thead><tr><th><?= tt('ts.on') ?></th><th class="num"><?= tt('ts.normal') ?></th><th class="num"><?= tt('ts.overtime') ?></th><th class="num"><?= tt('ts.holiday') ?></th><th class="num"><?= tt('ts.total') ?></th></tr></thead>
        <tbody><?php foreach ($bySub as $k => $v): ?>
          <tr><td><?= e($k) ?></td><td class="num"><?= ts_fmt($v['normal']) ?></td><td class="num ts-ot"><?= ts_fmt($v['overtime']) ?></td><td class="num ts-hol"><?= ts_fmt($v['holiday']) ?></td><td class="num"><b><?= ts_fmt(array_sum($v)) ?></b></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    </div>

    <?php if ($t['status'] === 'submitted' && ts_can_approve($t)): ?>
      <div class="card">
        <h2><?= tt('ts.approval') ?></h2>
        <p class="hint"><?= e(t('ts.approval_hint', ['name' => $t['user_name']])) ?></p>
        <form method="post" action="<?= url('timesheet', ['id' => $id]) ?>" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="ts_decide"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="field"><label for="note"><?= tt('ts.approval_note') ?></label><textarea id="note" name="note" rows="3" placeholder="<?= tt('ts.approval_note_ph') ?>"></textarea></div>
          <div class="btn-row">
            <button class="btn btn--gold" type="submit" name="decision" value="approve" data-confirm="<?= tt('ts.approve_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.approve') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('ts.approve') ?></button>
            <button class="btn btn--danger" type="submit" name="decision" value="reject" data-confirm="<?= tt('ts.reject_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.reject') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>✕ <?= tt('ts.reject') ?></button>
          </div>
          <p class="hint" style="margin-top:8px;"><?= tt('ts.reject_note_hint') ?></p>
        </form>
      </div>
    <?php elseif ($t['status'] === 'submitted'): ?>
      <div class="card"><p class="hint" style="margin:0;"><?= e(t('ts.awaiting', ['name' => $t['approver_name'] ?: '—'])) ?></p>
        <?php if ($mine || is_cp_admin()): ?>
          <form method="post" action="<?= url('timesheet', ['id' => $id]) ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="ts_withdraw"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn--ghost btn--sm" type="submit"><?= tt('ts.withdraw') ?></button></form>
        <?php endif; ?></div>
    <?php endif; ?>

    <?php if ($t['status'] === 'approved' || $t['status'] === 'invoiced'): ?>
      <div class="card oa-decision oa-decision--ok">
        <h2><?= tt('ts.approval') ?></h2>
        <p><strong><?= tt('ts.st_approved') ?></strong> — <?= e($t['approved_name']) ?> · <?= e(fmt_datetime($t['approved_at'])) ?></p>
        <?php if ($t['approval_note']): ?><blockquote class="oa-note"><?= rt_html($t['approval_note']) ?></blockquote><?php endif; ?>
        <?php if ($t['status'] === 'invoiced'): ?>
          <p style="margin-top:10px;"><strong><?= tt('ts.st_invoiced') ?></strong> — <?= tt('ts.invoice') ?> <b><?= e($t['invoice_ref']) ?></b> · <?= e($t['invoiced_name']) ?> · <?= e(fmt_datetime($t['invoiced_at'])) ?></p>
          <?php if (is_cp_admin()): ?><form method="post" action="<?= url('timesheet', ['id' => $id]) ?>" data-confirm="<?= tt('ts.uninvoice_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.uninvoice') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ts_uninvoice"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ts.uninvoice') ?></button></form><?php endif; ?>
        <?php elseif (ts_can_invoice($t)): ?>
          <form method="post" action="<?= url('timesheet', ['id' => $id]) ?>" class="btn-row" style="margin-top:10px;align-items:flex-end;" data-lock>
            <?= csrf_field() ?><input type="hidden" name="action" value="ts_invoice"><input type="hidden" name="ids[]" value="<?= $id ?>"><input type="hidden" name="back" value="timesheet">
            <div class="field" style="margin:0;"><label><?= tt('ts.invoice_ref') ?></label><input name="invoice_ref" maxlength="60" required placeholder="<?= tt('ts.invoice_ref_ph') ?>"></div>
            <button class="btn" type="submit"><?= tt('ts.mark_invoiced') ?></button>
          </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if (in_array($t['status'], ['draft', 'rejected'], true) && ($mine || is_cp_admin())): ?>
      <div class="card">
        <form method="post" action="<?= url('timesheet', ['id' => $id]) ?>" class="btn-row" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="ts_submit"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn--gold" type="submit" data-confirm="<?= e($t['approver_name'] ? t('ts.submit_confirm', ['name' => $t['approver_name']]) : t('ts.submit_confirm_nomgr')) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('ts.submit') ?></button>
        </form>
        <form method="post" action="<?= url('timesheets') ?>" style="margin-top:10px;" data-confirm="<?= tt('ts.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
          <?= csrf_field() ?><input type="hidden" name="action" value="ts_delete"><input type="hidden" name="id" value="<?= $id ?>">
          <button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 <?= tt('btn.delete') ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= tt('fsr.trail') ?></h2>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('fsr.event') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
      <tbody><?php $tone = ['created' => 'mute', 'saved' => 'mute', 'submitted' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'invoiced' => 'navy', 'uninvoiced' => 'mute', 'withdrawn' => 'mute'];
        foreach (array_reverse($t['events']) as $ev): ?>
        <tr><td class="date"><?= e(fmt_datetime($ev['created_at'])) ?></td><td><?= pill($tone[$ev['event']] ?? 'mute', $evLabel($ev['event'])) ?></td><td><?= e($ev['user_name'] ?: '—') ?><?= $ev['ip'] ? '<br><span class="hint">' . e($ev['ip']) . '</span>' : '' ?></td><td><?= e($ev['note'] ?: '') ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
</div>
<?php if (is_cp_admin() && !in_array($t['status'], ['draft', 'rejected'], true)): $adAction = 'ts_delete'; $adId = $id; $adBack = 'timesheets'; $adConfirm = t('ts.delete_confirm_admin', ['no' => $t['ts_no']]); $adNote = t('ts.delete_note'); require __DIR__ . '/_admin_delete.php'; endif; ?>
<?php layout_foot(); ?>

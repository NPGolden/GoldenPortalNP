<?php
/** One offer: facts, file, the owner's decision, Golden A/S status steps, trail.   GAS-CP-R15-001 */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$id = (int)($_GET['id'] ?? 0);
$o = of_has() ? of_load($id) : null;
if (!$o) { http_response_code(404); layout_head(t('err.notfound'), 'offers'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$pending = $o['status'] === 'sent';
$canDecide = $pending && (!$staff || is_cp_admin());
$next = of_next_statuses($o);
$ev = ['draft' => t('of.ev_draft'), 'sent' => t('of.ev_sent'), 'edited' => t('of.ev_edited'), 'approved' => t('of.st_approved'), 'rejected' => t('of.st_rejected'), 'status_cancelled' => t('of.st_cancelled'), 'status_in_progress' => t('of.st_in_progress'), 'status_to_be_invoiced' => t('of.st_to_be_invoiced'), 'status_finished' => t('of.st_finished'), 'status_sent' => t('of.ev_resent'), 'imported' => t('of.ev_imported'), 'project_active' => t('of.ev_project_active')];
layout_head($o['offer_no'] . ' · ' . $o['title'], 'offers');
?>
<p class="eyebrow"><a href="<?= url('offers') ?>"><?= tt('of.title') ?></a> › <?= pr_link((int)$o['project_id'], $o['project_code'], $o['project_name']) ?><?= $o['sub_code'] ? ' › <a href="' . url('subproject', ['id' => $o['subproject_id']]) . '">' . e($o['sub_code']) . '</a>' : '' ?></p>
<div class="page-head">
  <div>
    <h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($o['offer_no']) ?></span> <?= e($o['title']) ?> <span class="hint" style="display:inline;font-size:15px;">· <?= e($o['revision']) ?></span> <?= of_origin_pill($o) ?></h1>
    <?php if (!empty($o['sq_no']) || (function_exists('oc_pill') && pi_has())): ?><p class="hint" style="margin:0;"><?= !empty($o['sq_no']) ? '<span class="mono">SQ ' . e($o['sq_no']) . '</span> ' : '' ?><?= function_exists('oc_pill') && pi_has() ? oc_pill($o) : '' ?><?= !empty($o['converted_to']) ? ' → <a href="' . url('offer', ['id' => (int)$o['converted_to']]) . '">' . tt('oc.converted_to') . '</a>' : '' ?><?= !empty($o['converted_from']) ? ' ← <a href="' . url('offer', ['id' => (int)$o['converted_from']]) . '">' . tt('oc.converted_from') . '</a>' : '' ?></p><?php endif; ?>
    <p class="lede" style="margin-bottom:0;"><?= e($o['vessel'] ?: tt('pr.novessel')) ?><?= $o['sub_code'] ? ' · ' . e($o['sub_code'] . ' ' . $o['sub_name']) : '' ?><?= $staff && $o['customer_name'] ? ' · ' . e($o['customer_name']) : '' ?></p>
  </div>
  <div class="actions"><?= of_status_pill($o['status']) ?> <?= pill(of_age_tone($o), of_age_label($o)) ?><?php if ($staff): ?> <a class="btn btn--ghost btn--sm" href="<?= url('offer_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?></div>
</div>

<div class="cols">
  <div>
    <div class="card">
      <h2><?= tt('of.one') ?></h2>
      <div class="grid grid-3 oa-facts">
        <div><span class="k"><?= tt('of.amount') ?></span><b style="font-size:20px;"><?= e(of_money((float)$o['amount'], $o['currency'])) ?></b></div>
        <div><span class="k"><?= tt('of.sent') ?></span><b><?= e(fmt_date($o['sent_at'])) ?></b><?= $o['valid_until'] ? '<span class="hint">' . tt('of.valid_until') . ' ' . e(fmt_date($o['valid_until'])) . '</span>' : '' ?></div>
        <div><span class="k"><?= tt('of.aging') ?></span><b><?= e(of_age_label($o)) ?></b><span class="hint"><?= tt($pending ? 'of.aging_sent' : 'of.aging_status') ?></span></div>
      </div>
      <?php if ($o['file_id']): ?>
        <div class="rowitem" style="margin-top:14px;"><span class="ico">📄</span><span class="tx"><b><?= e($o['original_name']) ?></b><span><?= e($o['file_mime'] ?? '') ?><?= $o['file_size'] ? ' · ' . number_format((int)$o['file_size'] / 1024, 0) . ' KB' : '' ?></span></span>
          <a class="btn btn--gold btn--sm" href="<?= url('file', ['id' => $o['file_id']]) ?>" target="_blank"><?= tt('rd.view') ?></a></div>
      <?php else: ?><p class="hint" style="margin-top:12px;"><?= tt('oa.nofile') ?></p><?php endif; ?>
      <?php if ($o['notes']): ?><div class="tk-desc"><?= rt_html($o['notes']) ?></div><?php endif; ?>
    </div>

    <?php if ($o['decided_at']): ?>
      <div class="card oa-decision oa-decision--<?= in_array($o['status'], ['rejected'], true) ? 'bad' : 'ok' ?>">
        <h2><?= tt('oa.decision') ?></h2>
        <p><strong><?= e(in_array($o['status'], ['rejected', 'cancelled'], true) ? of_status_label($o['status']) : t('of.st_approved')) ?></strong> — <?= e($o['decided_name'] ?? '') ?> · <?= e(fmt_datetime($o['decided_at'])) ?><?= $o['decided_ip'] ? ' · IP ' . e($o['decided_ip']) : '' ?></p>
        <?php if ($o['decision_note']): ?><blockquote class="oa-note"><?= rt_html($o['decision_note']) ?></blockquote><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($canDecide): ?>
      <div class="card">
        <h2><?= tt($staff ? 'oa.decide_behalf' : 'of.your_decision') ?></h2>
        <p class="hint"><?= tt('of.decide_hint') ?></p>
        <form method="post" action="<?= url('offer', ['id' => $id]) ?>" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="of_decide"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="field"><label for="note"><?= tt('oa.note') ?></label><textarea id="note" name="note" rows="3" placeholder="<?= tt('of.note_ph') ?>"></textarea></div>
          <?php if ($staff && function_exists('sl_has') && sl_has()): ?><div class="field"><label><?= tt('sl.lost_reason') ?> <span class="hint"><?= tt('sl.lost_reason_hint') ?></span></label><select name="lost_reason" data-nosearch><option value="">—</option><?php foreach (SL_LOST as $lr): ?><option value="<?= $lr ?>"><?= e(t('sl.lost_' . $lr)) ?></option><?php endforeach; ?></select></div><?php endif; ?>
          <div class="btn-row">
            <button class="btn btn--gold" type="submit" name="decision" value="approved" data-confirm="<?= tt('of.confirm_approve') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oa.approve') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('of.approve') ?></button>
            <button class="btn btn--danger" type="submit" name="decision" value="rejected" data-confirm="<?= tt('of.confirm_reject') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oa.reject') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>✕ <?= tt('of.reject') ?></button>
          </div>
        </form>
      </div>
    <?php elseif ($pending && $staff): ?>
      <div class="card"><p class="hint" style="margin:0;"><?= tt('of.awaiting_owner') ?></p></div>
    <?php endif; ?>

    <?php if ($staff && $next): ?>
      <div class="card">
        <h2><?= tt('of.progress') ?></h2>
        <form method="post" action="<?= url('offer', ['id' => $id]) ?>" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="of_status"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="field"><label><?= tt('oa.note') ?></label><input name="note" maxlength="1000"></div>
          <div class="btn-row"><?php foreach ($next as $s): ?><button class="btn <?= $s === 'cancelled' ? 'btn--ghost' : '' ?>" type="submit" name="status" value="<?= $s ?>"<?= $s === 'cancelled' || $s === 'sent' ? ' data-confirm="' . tt('of.confirm_' . $s) . '" data-confirm-title="' . tt('dlg.title') . '" data-confirm-ok="' . tt('btn.ok') . '" data-confirm-cancel="' . tt('btn.cancel') . '"' : '' ?>><?= $s === 'sent' ? '↺ ' . tt('of.resend') : '→ ' . e(of_status_label($s)) ?></button><?php endforeach; ?></div>
          <p class="hint" style="margin-top:8px;"><?= tt('of.flow_hint') ?></p>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($staff && ofn_has()): $notes = ofn_list($id); $editN = (int)($_GET['note'] ?? 0); $en = $editN ? one('SELECT * FROM offer_notes WHERE id = ? AND offer_id = ?', [$editN, $id]) : null; if ($en && !ofn_can_edit($en)) $en = null; $tasks = of_tasks($id); ?>
  <div>
    <div class="card" id="notes">
      <h2><?= tt('ofn.title') ?> <span class="hint" style="font-weight:400;">(<?= count($notes) ?>)</span></h2>
      <p class="hint" style="margin-top:-4px;"><?= tt('ofn.lede') ?></p>
      <?php if (!$notes): ?><p class="hint"><?= tt('ofn.none') ?></p><?php else: ?>
      <ul class="ofn"><?php foreach ($notes as $n): ?>
        <li id="note-<?= (int)$n['id'] ?>"><div class="ofn-h"><b><?= e($n['author'] ?: $n['user_name'] ?: '—') ?></b> · <span class="hint"><?= e(fmt_datetime($n['created_at'])) ?><?= $n['updated_at'] ? ' · ' . tt('ofn.edited') : '' ?></span>
          <?php if (ofn_can_edit($n)): ?> · <a href="<?= url('offer', ['id' => $id, 'note' => (int)$n['id']]) ?>#note-form"><?= tt('btn.edit') ?></a>
            <form method="post" action="<?= url('offer', ['id' => $id]) ?>" style="display:inline;" data-lock data-confirm="<?= tt('ofn.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ofn_delete"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-text" type="submit">✕</button></form><?php endif; ?></div>
          <div class="ofn-b"><?= mt_html($n['body']) ?></div></li>
      <?php endforeach; ?></ul>
      <?php endif; ?>
      <form method="post" action="<?= url('offer', ['id' => $id]) ?>" id="note-form" data-lock>
        <?= csrf_field() ?><input type="hidden" name="action" value="ofn_save"><input type="hidden" name="offer" value="<?= $id ?>"><input type="hidden" name="note_id" value="<?= $en ? (int)$en['id'] : 0 ?>">
        <div class="field"><label for="ofn-body"><?= tt($en ? 'ofn.edit' : 'ofn.add') ?></label><textarea data-mt id="ofn-body" name="body" rows="3" maxlength="8000" required placeholder="<?= tt('ofn.ph') ?>"><?= e($en['body'] ?? '') ?></textarea></div>
        <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt($en ? 'btn.save' : 'ofn.post') ?></button><?php if ($en): ?><a class="btn btn--ghost" href="<?= url('offer', ['id' => $id]) ?>#notes"><?= tt('btn.cancel') ?></a><?php endif; ?><span class="hint"><?= tt('ofn.hint') ?></span></div>
      </form>
    </div>
    <div class="card">
      <h2><?= tt('td.title') ?> <span class="hint" style="font-weight:400;">(<?= count($tasks) ?>)</span> <a class="btn btn--ghost btn--sm" style="margin-left:8px;" href="<?= url('task_edit', ['offer' => $id]) ?>">+ <?= tt('td.new') ?></a></h2>
      <?php if (!$tasks): ?><p class="hint"><?= tt('ofn.no_tasks') ?></p><?php else: ?><ul class="eq-ct"><?php foreach ($tasks as $tk): ?><li><a href="<?= url('task_edit', ['id' => (int)$tk['id']]) ?>"><b><?= e($tk['title']) ?></b></a> · <?= e(fmt_date(substr($tk['due_at'], 0, 10))) ?><?= $tk['assignee'] ? ' · ' . e($tk['assignee']) : '' ?> <?= pill($tk['status'] === 'open' ? (substr($tk['due_at'], 0, 10) < date('Y-m-d') ? 'bad' : 'gold') : ($tk['status'] === 'done' ? 'green' : 'mute'), t('eq.ts_' . $tk['status'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="card">
    <h2><?= tt('fsr.trail') ?></h2>
    <ul class="trail"><?php foreach ($o['events'] as $x): ?>
      <li><b><?= e($ev[$x['event']] ?? $x['event']) ?></b> — <?= e($x['user_name'] ?: '') ?> · <?= e(fmt_datetime($x['created_at'])) ?><?= $x['ip'] ? ' · IP ' . e($x['ip']) : '' ?><?= $x['note'] ? '<br><span class="hint">' . e(fin_visible() ? mb_substr($x['note'], 0, 300) : preg_replace('/\d[\d.,]*\s?(EUR|USD|GBP|€|\$|£)/u', fin_masked(), mb_substr($x['note'], 0, 300))) . '</span>' : '' ?></li>
    <?php endforeach; ?></ul>
  </div>
</div>
<?php if ($staff && function_exists('pi_can_view') && pi_can_view()) require __DIR__ . '/_offer_proformas.php'; ?>
<?php require __DIR__ . '/_offer_builder.php'; ?>
<?php if ($staff && function_exists('em_card_html')): $emAtts = $o['file_id'] ? [['f:' . (int)$o['file_id'], (string)$o['original_name']]] : [];
  echo em_card_html(['kind' => 'offer', 'ctx' => ['no' => $o['offer_no'] . ' ' . $o['revision'], 'title' => (string)$o['title'], 'vessel' => (string)($o['vessel'] ?? ''), 'customer' => (string)($o['customer_name'] ?? ''), 'project' => (string)($o['project_code'] ?? ''), 'doc' => t('em.w_offer', ['no' => $o['offer_no'] . ' ' . $o['revision']]), 'project_id' => (int)$o['project_id'], 'customer_id' => (int)($o['customer_id'] ?? 0)],
    'people' => em_people((int)($o['vessel_id'] ?? 0) ?: null, (int)($o['customer_id'] ?? 0) ?: null), 'atts' => $emAtts, 'back' => url('offer', ['id' => $id])]); endif; ?>
<?php $adAction = 'of_delete'; $adId = $id; $adBack = 'offers'; $adConfirm = t('of.delete_confirm', ['no' => $o['offer_no'] . ' ' . $o['revision']]); $adNote = t('of.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php layout_foot(); ?>

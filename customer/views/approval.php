<?php
/** One document for owner approval: file, decision, comment thread.   GAS-CP-R10-001 */
require __DIR__ . '/_layout.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$r  = oa_load($id);
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'approvals'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff   = is_staff();
$isOpen  = in_array($r['status'], oa_open_statuses(), true);
$canDecide = $isOpen && (!$staff || is_cp_admin());
$r10     = oa_has_r10();
$kindLabel = ['comment' => '', 'approved' => t('sub.approved'), 'approved_comments' => t('sub.approved_comments'), 'rejected' => t('sub.rejected'), 'resubmitted' => t('sub.resubmitted')];
layout_head($r['doc_no'] . ' · ' . $r['title'], 'approvals');
?>
<p class="eyebrow"><a href="<?= url('approvals') ?>"><?= tt('oa.title') ?></a> › <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' › <a href="' . url('subproject', ['id' => $r['subproject_id'], 'tab' => 'owner']) . '">' . e($r['sub_code']) . '</a>' : '' ?></p>
<div class="page-head">
  <div>
    <h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($r['doc_no']) ?></span> <?= e($r['title']) ?> <span class="hint" style="display:inline;font-size:15px;">· <?= e($r['revision']) ?></span></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($r['vessel'] ?: tt('pr.novessel')) ?><?= $r['sub_code'] ? ' · ' . e($r['sub_code'] . ' ' . $r['sub_name']) : '' ?><?= $staff && $r['customer_name'] ? ' · ' . e($r['customer_name']) : '' ?></p>
  </div>
  <div class="actions"><?= submittal_pill($r['status']) ?></div>
</div>
<?php if (!$r10 && $canDecide): ?><div class="flash flash--warn"><?= tt('oa.need_r10') ?></div><?php endif; ?>

<div class="cols">
  <div>
    <div class="card">
      <h2><?= tt('sub.doc') ?></h2>
      <div class="grid grid-3 oa-facts">
        <div><span class="k"><?= tt('sub.submitted') ?></span><b><?= e(fmt_date($r['submitted_at'])) ?></b></div>
        <div><span class="k"><?= tt('sub.due') ?></span><b<?= $isOpen && $r['due_at'] && $r['due_at'] < date('Y-m-d') ? ' class="is-late"' : '' ?>><?= e(fmt_date($r['due_at'])) ?></b></div>
        <div><span class="k"><?= tt('sub.rev') ?></span><b><?= e($r['revision']) ?></b></div>
      </div>
      <?php if ($r['file_id']): ?>
        <div class="rowitem" style="margin-top:14px;">
          <span class="ico">📄</span>
          <span class="tx"><b><?= e($r['original_name']) ?></b><span><?= e($r['file_mime'] ?? '') ?><?= $r['file_size'] ? ' · ' . number_format((int)$r['file_size'] / 1024, 0) . ' KB' : '' ?></span></span>
          <a class="btn btn--gold btn--sm" href="<?= url('file', ['id' => $r['file_id']]) ?>" target="_blank"><?= tt('rd.view') ?></a>
        </div>
      <?php else: ?><p class="hint" style="margin-top:12px;"><?= tt('oa.nofile') ?></p><?php endif; ?>
      <?php if ($r['comments']): ?><p class="hint" style="margin:14px 0 0;"><?= rt_html($r['comments']) ?></p><?php endif; ?>
    </div>

    <?php if (!empty($r['decided_time']) || ($r['decided_at'] && !$isOpen)): ?>
      <div class="card oa-decision oa-decision--<?= str_starts_with($r['status'], 'approved') ? 'ok' : ($r['status'] === 'rejected' ? 'bad' : 'mute') ?>">
        <h2><?= tt('oa.decision') ?></h2>
        <p><strong><?= e(t('sub.' . $r['status'])) ?></strong>
          <?php if (!empty($r['decided_time'])): ?> — <?= e($r['decided_name'] ?? '') ?> · <?= e(fmt_datetime($r['decided_time'])) ?><?= $r['decided_ip'] ? ' · IP ' . e($r['decided_ip']) : '' ?><?= !empty($r['on_behalf']) ? ' · ' . tt('oa.on_behalf') : '' ?>
          <?php else: ?> — <?= e(fmt_date($r['decided_at'])) ?><?php endif; ?></p>
        <?php if (!empty($r['decision_note'])): ?><blockquote class="oa-note"><?= rt_html($r['decision_note']) ?></blockquote><?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($canDecide): ?>
      <div class="card">
        <h2><?= tt($staff ? 'oa.decide_behalf' : 'oa.your_decision') ?></h2>
        <p class="hint"><?= tt('oa.decide_hint') ?></p>
        <form method="post" action="<?= url('approval', ['id' => $id]) ?>" data-lock id="oa-decide">
          <?= csrf_field() ?><input type="hidden" name="action" value="oa_decide"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="field"><label for="note"><?= tt('oa.note') ?></label><textarea id="note" name="note" rows="4" placeholder="<?= tt('oa.note_ph') ?>"></textarea></div>
          <div class="btn-row">
            <button class="btn btn--gold" type="submit" name="decision" value="approved"
                    data-confirm="<?= tt('oa.confirm_approve') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oa.approve') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('oa.approve') ?></button>
            <button class="btn" type="submit" name="decision" value="approved_comments"
                    data-confirm="<?= tt('oa.confirm_approve_c') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oa.approve_c') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('oa.approve_c') ?></button>
            <button class="btn btn--danger" type="submit" name="decision" value="rejected"
                    data-confirm="<?= tt('oa.confirm_reject') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('oa.reject') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>✕ <?= tt('oa.reject') ?></button>
          </div>
          <p class="hint" style="margin-top:8px;"><?= tt('oa.note_hint') ?></p>
        </form>
      </div>
    <?php elseif ($isOpen && $staff): ?>
      <div class="card"><p class="hint" style="margin:0;"><?= tt('oa.awaiting_owner') ?></p></div>
    <?php endif; ?>

    <?php if ($staff && in_array($r['status'], ['rejected', 'approved_comments'], true)): ?>
      <div class="card">
        <h2><?= tt('oa.resubmit') ?></h2>
        <form method="post" action="<?= url('approval', ['id' => $id]) ?>" enctype="multipart/form-data" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="oa_resubmit"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="grid grid-3">
            <div class="field"><label><?= tt('oa.new_rev') ?></label><input name="revision" maxlength="16" value="<?= e(oa_next_rev($r['revision'])) ?>"></div>
            <div class="field" style="grid-column: span 2;"><label><?= tt('dr.file') ?></label><input name="file" type="file"></div>
          </div>
          <div class="field"><label><?= tt('oa.resubmit_note') ?></label><input name="note" maxlength="500" placeholder="<?= tt('oa.resubmit_ph') ?>"></div>
          <button class="btn" type="submit"><?= tt('oa.resubmit') ?></button>
        </form>
      </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2><?= tt('oa.thread') ?></h2>
    <?php if (!$r['thread']): ?><div class="empty"><?= tt('oa.thread_empty') ?></div>
    <?php else: ?>
      <div class="oa-thread">
        <?php foreach (thread_tree($r['thread']) as $c): $own = (int)($c['user_id'] ?? 0) === (int)$u['id']; $gone = !empty($c['deleted_at']); ?>
          <div class="msg msg--<?= $c['side'] ?><?= $c['kind'] !== 'comment' ? ' msg--event' : '' ?><?= $gone ? ' msg--gone' : '' ?> depth-<?= (int)$c['depth'] ?>" id="c<?= (int)$c['id'] ?>">
            <p class="who"><?= avatar_html((int)($c['user_id'] ?? 0), (string)($c['author'] ?: ''), 22, 'ava--inline') ?> <b><?= e($c['author'] ?: ($c['side'] === 'golden' ? 'Golden A/S' : t('role.customer'))) ?></b> · <?= e(fmt_datetime($c['created_at'])) ?><?= $c['kind'] !== 'comment' ? ' · <span class="oa-kind oa-kind--' . e($c['kind']) . '">' . e($kindLabel[$c['kind']] ?? $c['kind']) . '</span>' : '' ?><?= !empty($c['parent_id']) ? ' · <a class="hint" href="#c' . (int)$c['parent_id'] . '">↩ ' . tt('cm.in_reply') . '</a>' : '' ?></p>
            <?php if ($gone): ?><p class="hint"><?= tt('cm.deleted') ?></p>
            <?php elseif ($c['body']): ?><p><?= mt_html($c['body']) ?></p><?php endif; ?>
            <?php if (!$gone && $r10): ?><?= thread_tools((int)$c['id'], (string)$c['author'], $own, $c['kind'] === 'comment', 'oa_comment_delete', ['id' => $id]) ?><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($r10): ?>
      <form method="post" action="<?= url('approval', ['id' => $id]) ?>" data-lock style="margin-top:14px;">
        <?= csrf_field() ?><input type="hidden" name="action" value="oa_comment"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="parent_id" value="" id="parent_id">
        <div class="reply-to" id="reply-to" hidden><?= tt('cm.replying') ?> <b></b> <button type="button" class="linkbtn js-reply-cancel"><?= tt('btn.cancel') ?></button></div>
        <div class="field"><label for="body"><?= tt('oa.comment') ?></label><textarea data-mt id="body" name="body" rows="3" required placeholder="<?= tt('oa.comment_ph') ?>"></textarea></div>
        <button class="btn btn--ghost" type="submit"><?= tt('oa.post') ?></button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php layout_foot(); ?>

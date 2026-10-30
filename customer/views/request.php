<?php
/** One change request — thread, votes, the Developer's decision and release.   GAS-CP-R59-001   ?p=request&id= */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $r = cr_load($id);
if (!$r) { http_response_code(404); layout_head(t('cr.title'), 'requests'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$dev = cr_is_dev(); $mine = (int)$r['user_id'] === (int)$u['id']; $open = in_array($r['status'], ['submitted', 'accepted', 'in_progress'], true);
$filesBy = []; foreach ($r['files'] as $f) $filesBy[(int)($f['comment_id'] ?? 0)][] = $f;
layout_head(cr_no($id) . ' · ' . $r['title'], 'requests');
?>
<p class="eyebrow"><a href="<?= url('support') ?>"><?= tt('su.title') ?></a> › <a href="<?= url('requests') ?>"><?= tt('cr.title') ?></a> › <?= e(cr_no($id)) ?></p>
<div class="page-head">
  <div><h1><?= e(cr_no($id)) ?> · <?= e($r['title']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= cr_kind_pill($r['kind']) ?> <?= cr_prio_pill($r['priority']) ?> <?= cr_status_pill($r['status']) ?> · <?= e(t('cr.a_' . $r['area'])) ?> · <?= e((string)$r['author']) ?> · <?= e(fmt_datetime($r['created_at'])) ?></p></div>
  <div class="btn-row">
    <form method="post" action="<?= url('request', ['id' => $id]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cr_vote"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm<?= $r['my_vote'] ? ' is-on' : '' ?>" type="submit" title="<?= tt('cr.vote_hint') ?>">👍 <?= (int)$r['votes'] ?> <?= $r['my_vote'] ? tt('cr.voted') : tt('cr.vote') ?></button></form>
    <?php if (($mine || $dev) && in_array($r['status'], ['submitted', 'accepted'], true)): ?><form method="post" action="<?= url('request', ['id' => $id]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cr_decide"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="status" value="withdrawn"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('cr.withdraw_confirm') ?>"><?= tt('cr.withdraw') ?></button></form><?php endif; ?>
    <?php if (is_cp_admin()): ?><form method="post" action="<?= url('requests') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="cr_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('cr.delete_confirm') ?>">🗑</button></form><?php endif; ?>
  </div>
</div>

<div class="cols" style="grid-template-columns: 2fr 1fr;">
<div>
<div class="card">
  <div class="msg msg--golden"><p class="who"><?= avatar_html((int)($r['user_id'] ?? 0), (string)$r['author'], 22, 'ava--inline') ?> <b><?= e((string)$r['author']) ?></b> · <?= e(fmt_datetime($r['created_at'])) ?></p><div class="body"><?= nl2br(e((string)$r['body'])) ?></div><?= cr_files_html($filesBy[0] ?? []) ?></div>
  <?php foreach ($r['comments'] as $c): ?>
    <?php if ($c['kind'] === 'status'): ?><div class="timeline-line hint" style="margin:8px 0 8px 10px;">⟶ <b><?= e($c['body']) ?></b> · <?= e((string)$c['author']) ?> · <?= e(fmt_datetime($c['created_at'])) ?></div>
    <?php else: ?><div class="msg msg--golden depth-1" id="c<?= (int)$c['id'] ?>"><p class="who"><?= avatar_html((int)($c['user_id'] ?? 0), (string)$c['author'], 22, 'ava--inline') ?> <b><?= e((string)$c['author']) ?></b><?= (int)$c['user_id'] === cr_dev_id() ? ' ' . pill('gold', t('cr.dev')) : '' ?> · <?= e(fmt_datetime($c['created_at'])) ?></p><div class="body"><?= nl2br(e($c['body'])) ?></div><?= cr_files_html($filesBy[(int)$c['id']] ?? []) ?></div><?php endif; ?>
  <?php endforeach; ?>
  <form method="post" action="<?= url('request', ['id' => $id]) ?>" enctype="multipart/form-data" style="margin-top:14px;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="cr_comment"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label for="body"><?= tt('oa.comment') ?></label><textarea data-mt id="body" name="body" rows="3" required placeholder="<?= tt('cr.comment_ph') ?>"></textarea></div>
    <div class="field"><label><?= tt('cr.attachments') ?></label><input type="file" name="files[]" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx,.xlsx,.txt,.mp4"></div>
    <div class="btn-row"><button class="btn btn--ghost" type="submit"><?= tt('oa.post') ?></button></div>
  </form>
</div>
</div>
<div>
<div class="card">
  <h2><?= tt('cr.decision') ?></h2>
  <?php if ($r['decided_at']): ?><p><?= cr_status_pill(in_array($r['status'], ['rejected'], true) ? 'rejected' : 'accepted') ?> <span class="hint">· <?= e((string)$r['decider']) ?> · <?= e(fmt_datetime($r['decided_at'])) ?></span></p><blockquote class="hint" style="margin:6px 0 12px;border-left:3px solid var(--cp-gold);padding-left:10px;"><?= nl2br(e((string)$r['decision_note'])) ?></blockquote><?php endif; ?>
  <?php if ($r['release_ref']): ?><p><b><?= tt('cr.s_released') ?></b> · <?= e($r['release_ref']) ?> · v<?= e((string)$r['release_version']) ?> · <?= e(fmt_date($r['released_at'])) ?> <a class="hint" href="<?= url('manual', ['for' => 'golden']) ?>#whatsnew"><?= tt('su.whats_new') ?> ›</a></p><?php endif; ?>
  <?php if ($dev && $r['status'] !== 'withdrawn'): ?>
  <form method="post" action="<?= url('request', ['id' => $id]) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="cr_decide"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label for="status"><?= tt('cr.set_status') ?></label><select id="status" name="status" data-nosearch>
      <?php $next = ['submitted' => ['accepted', 'rejected'], 'accepted' => ['in_progress', 'released', 'rejected', 'submitted'], 'in_progress' => ['released', 'accepted', 'rejected'], 'released' => ['in_progress'], 'rejected' => ['submitted', 'accepted']][$r['status']] ?? []; foreach ($next as $s): ?><option value="<?= $s ?>"><?= e(t('cr.s_' . $s)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="note"><?= tt('cr.cause') ?></label><textarea data-mt id="note" name="note" rows="3" placeholder="<?= tt('cr.cause_ph') ?>"></textarea></div>
    <div class="grid grid-2"><div class="field"><label for="release_ref"><?= tt('cr.release_ref') ?></label><input id="release_ref" name="release_ref" value="<?= e((string)$r['release_ref']) ?>" placeholder="GAS-CP-R60-001"></div><div class="field"><label for="release_version"><?= tt('cr.release_version') ?></label><input id="release_version" name="release_version" value="<?= e((string)($r['release_version'] ?: CP_VERSION)) ?>"></div></div>
    <p class="hint"><?= tt('cr.decision_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <?php elseif (!$dev): ?><p class="hint"><?= e(t('cr.waiting_dev', ['u' => (string)(scalar('SELECT full_name FROM customer_users WHERE id = ?', [cr_dev_id()]) ?? '—')])) ?></p><?php endif; ?>
</div>
</div>
</div>
<?php layout_foot(); ?>

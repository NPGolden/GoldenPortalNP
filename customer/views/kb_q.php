<?php
/** One knowledge-base question with its answers.   GAS-CP-R21-001   ?p=kb_q&id=ID */
require __DIR__ . '/_layout.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$q = kb_has() ? kb_load($id) : null;
if (!$q) { http_response_code(404); layout_head(t('err.notfound'), 'kb'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (empty($_SESSION['kb_seen'][$id])) { q('UPDATE kb_questions SET views = views + 1 WHERE id = ?', [$id]); $_SESSION['kb_seen'][$id] = 1; }
$admin = is_cp_admin(); $mine = (int)$q['author_id'] === (int)$u['id'];
$back = url('kb_q', ['id' => $id]); $F = kb_files($id);
layout_head($q['title'], 'kb');
?>
<p class="eyebrow"><a href="<?= url('support') ?>"><?= tt('su.title') ?></a> › <a href="<?= url('kb') ?>"><?= tt('kb.title') ?></a></p>
<div class="page-head">
  <div><h1><?= $q['accepted_id'] ? '<span class="kb-ok">✓</span> ' : '' ?><?= e($q['title']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= kb_cat_pill($q['category']) ?><?= $q['visibility'] === 'golden' ? ' ' . pill('mute', t('kb.vis_golden')) : '' ?> · <?= tt('kb.asked_by') ?> <?= kb_who($q['author'], $q['author_role']) ?> · <?= e(fmt_datetime($q['created_at'])) ?> · <?= (int)$q['views'] ?> <?= tt('kb.views') ?></p></div>
  <?php if ($admin): ?><form method="post" action="<?= $back ?>" data-confirm="<?= tt('kb.del_q_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="kb_delete_q"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('kb.del_q') ?></button></form><?php endif; ?>
</div>

<div class="card kb-q">
  <?php if ($q['body']): ?><div class="kb-body"><?= rt_html($q['body']) ?></div><?php else: ?><p class="hint"><?= tt('kb.no_body') ?></p><?php endif; ?>
  <?= kb_files_html($F['q']) ?>
</div>

<div class="card">
  <h2><?= tt('kb.answers') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($q['answers']) ?></span></h2>
  <?php if (!$q['answers']): ?><div class="empty"><?= tt('kb.no_answers') ?></div><?php else: ?>
  <div class="kb-answers">
    <?php foreach ($q['answers'] as $a): $acc = (int)$q['accepted_id'] === (int)$a['id']; ?>
    <div class="kb-answer<?= $acc ? ' is-accepted' : '' ?>" id="a<?= (int)$a['id'] ?>">
      <div class="kb-vote">
        <form method="post" action="<?= $back ?>#a<?= (int)$a['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="kb_vote"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="kb-votebtn<?= $a['my_vote'] ? ' is-on' : '' ?>" type="submit" title="<?= tt('kb.helpful') ?>">👍<b><?= (int)$a['helpful'] ?></b></button></form>
        <?php if ($acc): ?><span class="kb-ok kb-ok--big" title="<?= tt('kb.solved') ?>">✓</span><?php endif; ?>
      </div>
      <div class="kb-amain">
        <div class="kb-body"><?= rt_html($a['body']) ?></div>
        <?= kb_files_html($F['a'][(int)$a['id']] ?? []) ?>
        <div class="kb-meta"><?= kb_who($a['author'], $a['author_role']) ?> · <?= e(fmt_datetime($a['created_at'])) ?><?= $acc ? ' · <b>' . tt('kb.accepted') . '</b>' : '' ?>
          <?php if (($mine || is_staff()) && !$acc): ?> · <form method="post" action="<?= $back ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="kb_accept"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="answer_id" value="<?= (int)$a['id'] ?>"><button class="linkbtn" type="submit">✓ <?= tt('kb.mark_solved') ?></button></form><?php endif; ?>
          <?php if (($mine || is_staff()) && $acc): ?> · <form method="post" action="<?= $back ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="kb_accept"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="answer_id" value="0"><button class="linkbtn" type="submit"><?= tt('kb.unmark') ?></button></form><?php endif; ?>
          <?php if ($admin): ?> · <form method="post" action="<?= $back ?>" style="display:inline;" data-confirm="<?= tt('kb.del_a_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="kb_delete_a"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="linkbtn linkbtn--danger" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <form method="post" action="<?= $back ?>" class="kb-reply" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="kb_answer"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 class="ed-h"><?= tt('kb.your_answer') ?></h3>
    <div class="field"><textarea name="body" rows="4" required minlength="3" maxlength="8000" placeholder="<?= tt('kb.a_ph') ?>"></textarea></div>
    <div class="field"><label for="afiles"><?= tt('kb.attachments') ?></label><input id="afiles" name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx,.xlsx,.pptx,.dwg,.dxf,.txt,.csv,.zip,.mp4,.mov"><p class="hint"><?= tt('kb.attach_hint') ?></p></div>
    <button class="btn btn--gold btn--sm" type="submit"><?= tt('kb.post_a') ?></button>
  </form>
</div>
<?php layout_foot(); ?>

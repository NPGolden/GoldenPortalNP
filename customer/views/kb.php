<?php
/** Knowledge base — list, search, ask.   GAS-CP-R21-001   ?p=kb[&q=&cat=&st=&sort=&page=&ask=1] */
require __DIR__ . '/_layout.php';
$u = require_login();
if (!kb_has()) { layout_head(t('kb.title'), 'kb'); echo '<h1>' . tt('kb.title') . '</h1><div class="flash flash--warn">' . tt('kb.need_r21') . '</div>'; layout_foot(); return; }
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'cat' => (string)($_GET['cat'] ?? ''), 'st' => (string)($_GET['st'] ?? ''), 'sort' => (string)($_GET['sort'] ?? '')];
$pg = max(1, (int)($_GET['page'] ?? 1)); $per = 20;
$L = kb_list($f, $per, $pg); $pages = max(1, (int) ceil($L['total'] / $per));
$ask = isset($_GET['ask']);
$keep = array_filter($f);
layout_head(t('kb.title'), 'kb');
?>
<p class="eyebrow"><a href="<?= url('support') ?>"><?= tt('su.title') ?></a> › <?= tt('kb.title') ?></p>
<div class="page-head">
  <div><h1><?= tt('kb.title') ?></h1><p class="lede"><?= tt('kb.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="<?= url('kb', $keep + ['ask' => 1]) ?>#ask">+ <?= tt('kb.ask') ?></a></div>
</div>

<?php if ($ask): ?>
<div class="card" id="ask">
  <h2><?= tt('kb.ask') ?></h2>
  <p class="hint"><?= tt('kb.ask_hint') ?></p>
  <form method="post" action="<?= url('kb', $keep) ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="kb_ask">
    <div class="field"><label for="title"><?= tt('kb.q_title') ?> *</label><input id="title" name="title" required minlength="8" maxlength="200" placeholder="<?= tt('kb.q_title_ph') ?>"></div>
    <div class="grid grid-2">
      <div class="field"><label for="category"><?= tt('kb.category') ?></label><select id="category" name="category"><?php foreach (kb_categories() as $k => $lab): ?><option value="<?= $k ?>"><?= e($lab) ?></option><?php endforeach; ?></select></div>
      <?php if (is_staff()): ?><div class="field"><label for="visibility"><?= tt('kb.visibility') ?></label><select id="visibility" name="visibility"><option value="all"><?= tt('kb.vis_all') ?></option><option value="golden"><?= tt('kb.vis_golden') ?></option></select></div><?php endif; ?>
    </div>
    <div class="field"><label for="body"><?= tt('kb.q_body') ?></label><textarea id="body" name="body" rows="5" maxlength="8000" placeholder="<?= tt('kb.q_body_ph') ?>"></textarea></div>
    <div class="field"><label for="files"><?= tt('kb.attachments') ?></label><input id="files" name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx,.xlsx,.pptx,.dwg,.dxf,.txt,.csv,.zip,.mp4,.mov"><p class="hint"><?= tt('kb.attach_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('kb.post_q') ?></button><a class="btn btn--ghost" href="<?= url('kb', $keep) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="kb">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('kb.search_ph') ?>"></div>
    <div class="field"><label for="cat"><?= tt('kb.category') ?></label><select id="cat" name="cat"><option value=""><?= tt('flt.any') ?></option><?php foreach (kb_categories() as $k => $lab): ?><option value="<?= $k ?>"<?= $f['cat'] === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="st"><?= tt('th.status') ?></label><select id="st" name="st"><option value=""><?= tt('flt.any') ?></option><?php foreach (['open' => t('kb.st_open'), 'solved' => t('kb.solved'), 'unanswered' => t('kb.st_unanswered')] as $k => $lab): ?><option value="<?= $k ?>"<?= $f['st'] === $k ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="sort"><?= tt('kb.sort') ?></label><select id="sort" name="sort"><option value=""><?= tt('kb.sort_recent') ?></option><option value="views"<?= $f['sort'] === 'views' ? ' selected' : '' ?>><?= tt('kb.sort_views') ?></option></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('kb', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
  <h2><?= tt('kb.questions') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= (int)$L['total'] ?></span></h2>
  <?php if (!$L['rows']): ?><div class="empty"><?= tt('kb.none') ?></div><?php else: ?>
  <ul class="kb-list">
    <?php foreach ($L['rows'] as $r): ?>
    <li class="kb-item<?= $r['accepted_id'] ? ' is-solved' : '' ?>">
      <div class="kb-stat"><b><?= (int)$r['n_answers'] ?></b><span><?= tt('kb.answers') ?></span></div>
      <div class="kb-main">
        <a class="kb-title" href="<?= url('kb_q', ['id' => $r['id']]) ?>"><?= $r['accepted_id'] ? '<span class="kb-ok">✓</span> ' : '' ?><?= e($r['title']) ?></a>
        <?php if ($r['body']): ?><div class="kb-excerpt"><?= e(mb_substr(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 180)) ?><?= mb_strlen((string)$r['body']) > 180 ? '…' : '' ?></div><?php endif; ?>
        <div class="kb-meta"><?= kb_cat_pill($r['category']) ?><?= $r['visibility'] === 'golden' ? ' ' . pill('mute', t('kb.vis_golden')) : '' ?> · <?= kb_who($r['author'], $r['author_role']) ?> · <?= e(fmt_date($r['updated_at'])) ?> · <?= (int)$r['views'] ?> <?= tt('kb.views') ?></div>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($pages > 1): $pu = fn(int $n) => url('kb', $keep + ['page' => $n]); ?>
  <nav class="pager" aria-label="pages"><?php if ($pg > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pu($pg - 1) ?>">‹ <?= tt('btn.prev') ?></a><?php endif; ?><?php for ($n = max(1, $pg - 3); $n <= min($pages, $pg + 3); $n++): ?><a class="btn btn--sm <?= $n === $pg ? 'btn--gold' : 'btn--ghost' ?>" href="<?= $pu($n) ?>"><?= $n ?></a><?php endfor; ?><?php if ($pg < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= $pu($pg + 1) ?>"><?= tt('btn.next') ?> ›</a><?php endif; ?></nav>
  <?php endif; endif; ?>
</div>
<?php layout_foot(); ?>

<?php
/** Support › Portal change requests — list, filters, new request, contributors' scoreboard.   GAS-CP-R59-001   ?p=requests[&q=&kind=&area=&status=&user=&page=&new=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!cr_has()) { layout_head(t('cr.title'), 'requests'); echo '<h1>' . tt('cr.title') . '</h1><div class="flash flash--warn">' . tt('cr.need_r59') . '</div>'; layout_foot(); return; }
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'kind' => (string)($_GET['kind'] ?? ''), 'area' => (string)($_GET['area'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'user' => (int)($_GET['user'] ?? 0)];
$pg = max(1, (int)($_GET['page'] ?? 1)); $per = 30;
$L = cr_list($f, $per, $pg); $pages = max(1, (int) ceil($L['total'] / $per));
$new = isset($_GET['new']); $keep = array_filter($f); $c = cr_counts(); $board = cr_scoreboard(10);
layout_head(t('cr.title'), 'requests');
?>
<p class="eyebrow"><a href="<?= url('support') ?>"><?= tt('su.title') ?></a> › <?= tt('cr.title') ?></p>
<div class="page-head">
  <div><h1>💡 <?= tt('cr.title') ?></h1><p class="lede"><?= tt('cr.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="<?= url('requests', $keep + ['new' => 1]) ?>#cr-new">+ <?= tt('cr.new') ?></a></div>
</div>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <a class="stat<?= ($c['submitted'] ?? 0) ? ' gold' : '' ?>" href="<?= url('requests', ['status' => 'submitted']) ?>"><b><?= (int)($c['submitted'] ?? 0) ?></b><span class="s"><?= tt('cr.s_submitted') ?></span></a>
  <a class="stat" href="<?= url('requests', ['status' => 'in_progress']) ?>"><b><?= (int)($c['in_progress'] ?? 0) ?></b><span class="s"><?= tt('cr.s_in_progress') ?></span></a>
  <a class="stat green" href="<?= url('requests', ['status' => 'released']) ?>"><b><?= (int)($c['released'] ?? 0) ?></b><span class="s"><?= tt('cr.s_released') ?></span></a>
  <a class="stat" href="<?= url('requests') ?>"><b><?= (int)($c['total'] ?? 0) ?></b><span class="s"><?= tt('cr.total') ?></span></a>
</div>

<?php if ($new): ?>
<div class="card" id="cr-new">
  <h2><?= tt('cr.new') ?></h2>
  <p class="hint"><?= tt('cr.new_hint') ?></p>
  <form method="post" action="<?= url('requests', $keep) ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="cr_create">
    <div class="grid grid-3">
      <div class="field"><label for="kind"><?= tt('cr.kind') ?></label><select id="kind" name="kind" data-nosearch><?php foreach (CR_KINDS as $k): ?><option value="<?= $k ?>"<?= $k === 'improvement' ? ' selected' : '' ?>><?= e(t('cr.k_' . $k)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="area"><?= tt('cr.area') ?></label><select id="area" name="area" data-nosearch><?php foreach (CR_AREAS as $a): ?><option value="<?= $a ?>"><?= e(t('cr.a_' . $a)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="priority"><?= tt('cr.priority') ?></label><select id="priority" name="priority" data-nosearch><?php foreach (['low', 'normal', 'high'] as $p): ?><option value="<?= $p ?>"<?= $p === 'normal' ? ' selected' : '' ?>><?= e(t('cr.p_' . $p)) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label for="title"><?= tt('cr.q_title') ?> *</label><input id="title" name="title" required minlength="6" maxlength="200" placeholder="<?= tt('cr.q_title_ph') ?>"></div>
    <div class="field"><label for="body"><?= tt('cr.q_body') ?> *</label><textarea data-mt id="body" name="body" rows="6" required minlength="10" maxlength="8000" placeholder="<?= tt('cr.q_body_ph') ?>"></textarea></div>
    <div class="field"><label for="files"><?= tt('cr.attachments') ?></label><input id="files" name="files[]" type="file" multiple accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.docx,.xlsx,.txt,.mp4"><p class="hint"><?= tt('cr.attachments_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('cr.submit') ?></button><a class="btn btn--ghost" href="<?= url('requests', $keep) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php endif; ?>

<div class="cols" style="grid-template-columns: 2fr 1fr;">
<div>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="requests">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('cr.search_ph') ?>"></div>
    <div class="field"><label for="kind"><?= tt('cr.kind') ?></label><select name="kind" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (CR_KINDS as $k): ?><option value="<?= $k ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= e(t('cr.k_' . $k)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="area"><?= tt('cr.area') ?></label><select name="area" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (CR_AREAS as $a): ?><option value="<?= $a ?>"<?= $f['area'] === $a ? ' selected' : '' ?>><?= e(t('cr.a_' . $a)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>><?= tt('cr.open') ?></option><?php foreach (CR_STATUSES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(t('cr.s_' . $s)) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('requests') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>
<div class="card">
  <h2><?= tt('cr.list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= (int)$L['total'] ?></span><?php if ($f['user']): ?> <span class="hint">· <?= e((string)(scalar('SELECT full_name FROM customer_users WHERE id = ?', [$f['user']]) ?? '')) ?></span><?php endif; ?></h2>
  <?php if (!$L['rows']): ?><div class="empty"><?= tt('cr.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th>#</th><th><?= tt('cr.kind') ?></th><th><?= tt('cr.q_title') ?></th><th><?= tt('cr.area') ?></th><th><?= tt('cr.by') ?></th><th class="num">👍</th><th><?= tt('th.status') ?></th><th><?= tt('cr.release') ?></th></tr></thead>
    <tbody><?php foreach ($L['rows'] as $r): ?>
      <tr><td class="ref"><a href="<?= url('request', ['id' => $r['id']]) ?>"><?= e(cr_no((int)$r['id'])) ?></a></td><td><?= cr_kind_pill($r['kind']) ?> <?= cr_prio_pill($r['priority']) ?></td>
        <td><a href="<?= url('request', ['id' => $r['id']]) ?>"><b><?= e($r['title']) ?></b></a><?= (int)$r['n_comments'] ? ' <span class="hint">💬 ' . (int)$r['n_comments'] . '</span>' : '' ?><br><span class="hint"><?= e(mb_substr(preg_replace('/\s+/u', ' ', (string)$r['body']), 0, 120)) ?><?= mb_strlen((string)$r['body']) > 120 ? '…' : '' ?></span></td>
        <td class="hint"><?= e(t('cr.a_' . $r['area'])) ?></td><td><a class="hint" href="<?= url('requests', ['user' => (int)$r['user_id']]) ?>"><?= e((string)$r['author']) ?></a><br><span class="hint"><?= e(fmt_date($r['created_at'])) ?></span></td>
        <td class="num"><?= (int)$r['votes'] ?: '' ?></td><td><?= cr_status_pill($r['status']) ?></td>
        <td class="hint"><?= $r['release_ref'] ? e($r['release_ref']) . '<br>v' . e((string)$r['release_version']) . ' · ' . e(fmt_date($r['released_at'])) : ($r['decided_at'] ? e(fmt_date($r['decided_at'])) : '—') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php if ($pages > 1): $pu = fn(int $n) => url('requests', $keep + ['page' => $n]); ?>
  <nav class="pager" aria-label="pages"><?php if ($pg > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pu($pg - 1) ?>">‹</a><?php endif; ?><span class="hint"><?= $pg ?> / <?= $pages ?></span><?php if ($pg < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= $pu($pg + 1) ?>">›</a><?php endif; ?></nav>
  <?php endif; endif; ?>
</div>
</div>
<div>
<div class="card" id="cr-board">
  <h2>🏆 <?= tt('cr.board') ?></h2>
  <p class="hint"><?= tt('cr.board_hint') ?></p>
  <?php if (!$board): ?><div class="empty"><?= tt('cr.none') ?></div><?php else: ?>
  <table class="tbl"><thead><tr><th><?= tt('cr.by') ?></th><th class="num"><?= tt('cr.s_released') ?></th><th class="num"><?= tt('cr.s_accepted') ?></th><th class="num"><?= tt('cr.proposed') ?></th></tr></thead><tbody>
  <?php foreach ($board as $i => $b): ?><tr><td><?= $i === 0 && (int)$b['released'] ? '🥇 ' : ($i === 1 && (int)$b['released'] ? '🥈 ' : ($i === 2 && (int)$b['released'] ? '🥉 ' : '')) ?><a href="<?= url('requests', ['user' => (int)$b['id']]) ?>"><?= e($b['full_name']) ?></a></td><td class="num"><b><?= (int)$b['released'] ?></b></td><td class="num"><?= (int)$b['accepted'] ?></td><td class="num hint"><?= (int)$b['proposed'] ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
  <p class="hint" style="margin-top:10px;"><?= e(t('cr.developer', ['u' => (string)(scalar('SELECT full_name FROM customer_users WHERE id = ?', [cr_dev_id()]) ?? '—')])) ?></p>
</div>
<div class="card">
  <h3 class="ac-h" style="margin-top:0;"><?= tt('cr.how_title') ?></h3>
  <ol class="hint" style="padding-left:18px;margin:0;"><li><?= tt('cr.how_1') ?></li><li><?= tt('cr.how_2') ?></li><li><?= tt('cr.how_3') ?></li><li><?= tt('cr.how_4') ?></li></ol>
</div>
</div>
</div>
<?php layout_foot(); ?>

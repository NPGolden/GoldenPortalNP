<?php
/** Notifications — everything that changed since the user last looked, grouped like the menu.   GAS-CP-R18-002
 *   ?p=notifications[&g=fleet|work|commercial|library][&show=unread|all][&page=n] */
require __DIR__ . '/_layout.php';
$u = require_login();
if (!nf_has()) { layout_head(t('nf.title'), 'notifications'); echo '<h1>' . tt('nf.title') . '</h1><div class="flash flash--warn">' . tt('nf.need_r18b') . '</div>'; layout_foot(); return; }
$g    = (string)($_GET['g'] ?? ''); if (!isset(nf_groups()[$g])) $g = '';
$show = (string)($_GET['show'] ?? 'all') === 'unread' ? 'unread' : 'all';
$per  = 40; $pg = max(1, (int)($_GET['page'] ?? 1));
$counts = nf_counts();
$total  = nf_count_all($g ?: null, $show === 'unread');
$pages  = max(1, (int) ceil($total / $per)); $pg = min($pg, $pages);
$rows   = nf_list($g ?: null, $show === 'unread', $per, ($pg - 1) * $per);
$self   = fn(array $o = []) => url('notifications', array_filter(['g' => $g, 'show' => $show === 'unread' ? 'unread' : null, 'page' => $pg > 1 ? $pg : null] + $o, fn($v) => $v !== null && $v !== ''));
layout_head(t('nf.title'), 'notifications');
?>
<div class="page-head">
  <div><h1><?= tt('nf.title') ?></h1><p class="lede"><?= tt('nf.lede') ?></p></div>
  <?php if ($counts['total']): ?>
  <form method="post" action="<?= $self() ?>" class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="nf_read_all"><?php if ($g): ?><input type="hidden" name="grp" value="<?= e($g) ?>"><?php endif; ?>
    <button class="btn btn--ghost" type="submit"><?= $g ? e(t('nf.mark_group', ['g' => nf_groups()[$g]])) : tt('nf.mark_all') ?></button></form>
  <?php endif; ?>
</div>

<nav class="tabs nf-tabs" aria-label="<?= tt('nf.title') ?>">
  <a class="tab<?= $g === '' ? ' is-on' : '' ?>" href="<?= url('notifications', array_filter(['show' => $show === 'unread' ? 'unread' : null])) ?>"><?= tt('nf.all_groups') ?><?= $counts['total'] ? ' <span class="badge">' . $counts['total'] . '</span>' : '' ?></a>
  <?php foreach (nf_groups() as $k => $lab): ?>
    <a class="tab<?= $g === $k ? ' is-on' : '' ?>" href="<?= url('notifications', array_filter(['g' => $k, 'show' => $show === 'unread' ? 'unread' : null])) ?>"><?= e($lab) ?><?= $counts[$k] ? ' <span class="badge">' . $counts[$k] . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<div class="card">
  <div class="nf-bar">
    <div class="nf-seg">
      <a class="<?= $show === 'all' ? 'is-on' : '' ?>" href="<?= url('notifications', array_filter(['g' => $g])) ?>"><?= tt('nf.show_all') ?></a>
      <a class="<?= $show === 'unread' ? 'is-on' : '' ?>" href="<?= url('notifications', array_filter(['g' => $g, 'show' => 'unread'])) ?>"><?= tt('nf.show_unread') ?><?= ($g ? $counts[$g] : $counts['total']) ? ' (' . ($g ? $counts[$g] : $counts['total']) . ')' : '' ?></a>
    </div>
    <span class="hint"><?= e(t('au.showing', ['from' => $total ? ($pg - 1) * $per + 1 : 0, 'to' => min($total, $pg * $per), 'n' => $total])) ?></span>
  </div>
  <?php if (!$rows): ?><div class="empty"><?= $show === 'unread' ? tt('nf.none_unread') : tt('nf.none') ?></div><?php else: ?>
  <ul class="nf-page">
    <?php foreach ($rows as $r): $unread = $r['read_at'] === null; $src = nf_source_label($r['kind']); ?>
    <li class="nf-item<?= $unread ? ' is-unread' : '' ?>">
      <span class="nf-mark" aria-hidden="true"></span>
      <div class="nf-main">
        <div class="nf-l1"><?= nf_grp_pill($r['grp']) ?><?= $src ? '<span class="nf-src">' . e($src) . '</span>' : '' ?><b><?= e(nf_label($r)) ?></b></div>
        <a class="nf-title" href="<?= url('nf_open', ['id' => $r['id']]) ?>"><?= e($r['title']) ?></a>
        <?php $body = nf_body($r); if ($body !== ''): ?><div class="nf-body"><?= e(mb_substr($body, 0, 220)) ?><?= mb_strlen($body) > 220 ? '…' : '' ?></div><?php endif; ?>
        <div class="nf-meta"><?= $r['actor_name'] ? e($r['actor_name']) . ' · ' : '' ?><time datetime="<?= e($r['created_at']) ?>" title="<?= e(fmt_datetime($r['created_at'])) ?>"><?= e(nf_ago($r['created_at'])) ?></time><?= !$unread ? ' · <span class="hint">' . e(t('nf.read_at', ['d' => fmt_datetime($r['read_at'])])) . '</span>' : '' ?></div>
      </div>
      <div class="nf-act">
        <a class="btn btn--ghost btn--sm" href="<?= url('nf_open', ['id' => $r['id']]) ?>"><?= tt('nf.open') ?></a>
        <?php if ($unread): ?><form method="post" action="<?= $self() ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nf_read"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('nf.mark_read') ?>">✓ <?= tt('nf.mark_read') ?></button></form><?php endif; ?>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($pages > 1): ?>
  <?php $pageUrl = fn(int $n) => url('notifications', array_filter(['g' => $g, 'show' => $show === 'unread' ? 'unread' : null, 'page' => $n > 1 ? $n : null])); ?>
  <nav class="pager" aria-label="pages">
    <?php if ($pg > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($pg - 1) ?>">‹ <?= tt('btn.prev') ?></a><?php endif; ?>
    <?php for ($n = max(1, $pg - 3); $n <= min($pages, $pg + 3); $n++): ?><a class="btn btn--sm <?= $n === $pg ? 'btn--gold' : 'btn--ghost' ?>" href="<?= $pageUrl($n) ?>"><?= $n ?></a><?php endfor; ?>
    <?php if ($pg < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($pg + 1) ?>"><?= tt('btn.next') ?> ›</a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

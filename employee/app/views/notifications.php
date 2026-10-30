<?php
require __DIR__ . '/_layout.php';
$u = require_login();

$rows = all('SELECT n.*, o.order_ref, o.status AS order_status FROM notifications n
             LEFT JOIN orders o ON o.id = n.order_id
             WHERE n.user_id = ? ORDER BY n.id DESC LIMIT 300', [$u['id']]);
$unread = unread_count((int)$u['id']);

// Group notifications under their order so a busy order is one collapsible
// block, newest first, instead of a flat scroll. Order-less notices sit
// together under a general heading.
$groups = [];
foreach ($rows as $n) {
    $key = $n['order_id'] ? 'o' . $n['order_id'] : 'general';
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'order_id'  => $n['order_id'],
            'ref'       => $n['order_ref'] ?: t('notif.general'),
            'status'    => $n['order_status'] ?? null,
            'items'     => [],
            'unread'    => 0,
            'latest'    => $n['created_at'],
        ];
    }
    $groups[$key]['items'][] = $n;
    if (!$n['is_read']) $groups[$key]['unread']++;
}

layout_head(t('nav.notifications'), 'notifications');
?>
<div class="page-head">
  <div>
    <p class="eyebrow"><?= $unread ? e(t('notif.unread', ['n' => $unread])) : tt('notif.caught') ?></p>
    <h1><?= tt('nav.notifications') ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= tt('notif.lede') ?></p>
  </div>
  <?php if ($unread): ?>
    <form method="post" action="<?= url('notifications') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="notif_read_all">
      <button class="btn btn--ghost" type="submit"><?= tt('notif.readall') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php if (!$rows): ?>
  <div class="card"><div class="empty"><p><?= tt('notif.empty') ?></p></div></div>
<?php else: ?>
  <?php foreach ($groups as $g):
        $done = in_array($g['status'], ['closed', 'delivered', 'cancelled'], true); ?>
    <details class="card notif-group" <?= $g['unread'] ? 'open' : '' ?>>
      <summary>
        <span class="ng-ref">
          <?php if ($g['order_id']): ?>
            <a href="<?= url('order', ['id' => $g['order_id']]) ?>"><?= e($g['ref']) ?></a>
          <?php else: ?>
            <?= e($g['ref']) ?>
          <?php endif; ?>
        </span>
        <?php if ($g['status']): ?><span class="ng-status"><?= status_pill($g['status']) ?></span><?php endif; ?>
        <?php if ($g['unread']): ?><span class="ng-unread"><?= (int)$g['unread'] ?></span><?php endif; ?>
        <span class="ng-count"><?= e(t('notif.inorder', ['n' => count($g['items'])])) ?></span>
      </summary>
      <ul class="timeline">
        <?php foreach ($g['items'] as $n): ?>
          <li style="<?= $n['is_read'] ? 'opacity:.62;' : '' ?>">
            <span class="when"><?= e(fmt_datetime($n['created_at'])) ?><?= $n['emailed_at'] ? ' · ' . tt('notif.emailed') : '' ?></span>
            <span class="what2"><strong><?= e($n['title']) ?></strong></span><br>
            <span class="note"><?= e($n['body']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </details>
  <?php endforeach; ?>
<?php endif; ?>
<?php layout_foot(); ?>

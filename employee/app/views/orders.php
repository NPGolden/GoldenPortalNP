<?php
require __DIR__ . '/_layout.php';
$u   = require_login();
$uid = (int)$u['id'];
$r   = $u['role_code'];

[$per, $days, $page] = list_prefs($u);

$where  = [];
$params = [];

if ($r === 'field_engineer')        { $where[] = 'o.requested_by = ?';  $params[] = $uid; }
elseif ($r === 'project_manager')   { $where[] = 'o.pm_user_id = ?';    $params[] = $uid; }
elseif ($r === 'technical_manager') { $where[] = 'o.tm_user_id = ?';    $params[] = $uid; }

/* multi-select status filter */
$allStatuses = array_keys(passage_legs()) ;
$allStatuses = array_merge($allStatuses, offrail_statuses());
$options = [];
foreach ($allStatuses as $st) $options[$st] = status_label($st);

$sel = array_values(array_intersect((array)($_GET['status'] ?? []), $allStatuses));
if ($sel) {
    $where[]  = 'o.status IN (' . implode(',', array_fill(0, count($sel), '?')) . ')';
    $params   = array_merge($params, $sel);
}

if ($days > 0) { $where[] = 'o.created_at >= NOW() - INTERVAL ? DAY'; $params[] = $days; }

// "Running orders only" — hide delivered/closed/cancelled/rejected.
if (!empty($_GET['running'])) {
    $fin = finished_statuses();
    $where[] = 'o.status NOT IN (' . implode(',', array_fill(0, count($fin), '?')) . ')';
    array_push($params, ...$fin);
}

$search = trim((string)($_GET['q'] ?? ''));
if ($search !== '') {
    $where[] = '(o.order_ref LIKE ? OR o.vessel_name LIKE ? OR o.project_number LIKE ?)';
    array_push($params, "%{$search}%", "%{$search}%", "%{$search}%");
}

$w     = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$total = (int) scalar("SELECT COUNT(*) FROM orders o{$w}", $params);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$rows = all("SELECT o.*, ru.full_name AS requester,
        (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS line_count
        FROM orders o JOIN users ru ON ru.id = o.requested_by
        {$w} ORDER BY o.created_at DESC LIMIT {$per} OFFSET " . (($page - 1) * $per), $params);

$keep = ['q' => $search ?: null, 'status' => $sel ?: null, 'days' => $days, 'per' => $per, 'running' => !empty($_GET['running']) ? 1 : null];
$keep = array_filter($keep, fn($v) => $v !== null);

layout_head(t('nav.orders'), 'orders');
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= $r === 'field_engineer' ? tt('orders.mine') : tt('orders.all') ?></p>
    <h1><?= tt('nav.orders') ?></h1>
    <p class="lede" style="margin-bottom:0;">
      <?= e(t('orders.count', ['n' => $total])) ?> · <?= e(period_label($days)) ?>
    </p>
  </div>
  <?php if (in_array($r, ['field_engineer','system_admin'], true)): ?>
    <a class="btn btn--gold" href="<?= url('order_new') ?>"><?= tt('nav.raise') ?></a>
  <?php endif; ?>
</div>

<?php filter_bar('orders', $options, $sel, $days, $per, $search); ?>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><p><?= tt('orders.empty') ?></p></div>
  <?php else: ?>
    <table>
      <thead>
        <tr><th><?= tt('th.reference') ?></th><th><?= tt('th.vessel_proj') ?></th><th><?= tt('th.raised_by') ?></th>
            <th><?= tt('th.lines') ?></th><th><?= tt('th.priority') ?></th><th><?= tt('th.status') ?></th>
            <th><?= tt('th.approx_short') ?></th><th><?= tt('th.actual') ?></th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td class="ref"><a href="<?= url('order', ['id' => $o['id']]) ?>"><?= e($o['order_ref']) ?></a></td>
          <td><strong><?= e($o['vessel_name'] ?: '—') ?></strong><br><span class="hint"><?= e($o['project_number']) ?></span></td>
          <td><?= e($o['requester']) ?></td>
          <td class="num"><?= (int)$o['line_count'] ?></td>
          <td class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></td>
          <td><?= status_pill($o['status']) ?></td>
          <td class="date"><?= e(fmt_date($o['approx_delivery_date'])) ?></td>
          <td class="date"><?= e(fmt_date($o['actual_delivery_date'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php pager('orders', $page, $pages, $keep); ?>

<?php layout_foot(); ?>

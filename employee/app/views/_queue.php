<?php
/**
 * GAS-POMS — a desk's work queue, with multi-status filter, per-user
 * period + page size, and pagination.
 *
 * Expects from the caller:
 *   $slug, $title, $eyebrow, $lede, $active, $emptyText
 *   $deskStatuses  statuses this desk may see (filter options)
 *   $baseCond      SQL condition WITHOUT status (role scoping etc.)
 *   $baseArgs      its parameters
 *   $orderBy       ORDER BY clause
 * Optional: $showAge (bool), $ageFrom (column for DATEDIFF, default updated_at)
 */
$u = current_user();
[$per, $days, $page] = list_prefs($u);

$options = [];
foreach ($deskStatuses as $st) $options[$st] = status_label($st);

$sel  = array_values(array_intersect((array)($_GET['status'] ?? []), $deskStatuses));
$use  = $sel ?: $deskStatuses;                       // nothing picked = whole desk

$cond = "({$baseCond}) AND o.status IN (" . implode(',', array_fill(0, count($use), '?')) . ')';
$args = array_merge($baseArgs, $use);
if ($days > 0) { $cond .= ' AND o.created_at >= NOW() - INTERVAL ? DAY'; $args[] = $days; }
if (!empty($_GET['running'])) {
    $fin = finished_statuses();
    $cond .= ' AND o.status NOT IN (' . implode(',', array_fill(0, count($fin), '?')) . ')';
    array_push($args, ...$fin);
}

$total = (int) scalar("SELECT COUNT(*) FROM orders o{$joins} WHERE {$cond}", $args);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);

$ageCol = $ageFrom ?? 'o.updated_at';
$rows = all("SELECT o.*, ru.full_name AS requester, ru.phone AS requester_phone,
        DATEDIFF(CURDATE(), DATE({$ageCol})) AS age_days,
        (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS line_count,
        (SELECT GROUP_CONCAT(DISTINCT i.supplier ORDER BY i.supplier SEPARATOR ', ')
           FROM order_items i WHERE i.order_id = o.id AND i.supplier IS NOT NULL AND i.supplier <> '') AS suppliers,
        (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id AND i.item_status IN ('ordered','backordered')) AS lines_ordered
        FROM orders o JOIN users ru ON ru.id = o.requested_by
        WHERE {$cond} ORDER BY {$orderBy} LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);

// Which desk is this? Drives the columns each role actually needs.
$desk = $slug; // 'approvals' | 'procurement' | 'warehouse' | 'driver'

$keep = array_filter(['status' => $sel ?: null, 'days' => $days, 'per' => $per, 'running' => !empty($_GET['running']) ? 1 : null], fn($v) => $v !== null);

layout_head($title, $active);
?>

<p class="eyebrow"><?= e($eyebrow) ?></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= e($lede) ?> · <?= e(t('orders.count', ['n' => $total])) ?> · <?= e(period_label($days)) ?></p>

<?php filter_bar($slug, $options, $sel, $days, $per); ?>

<div class="card">
  <?php if (!$rows): ?>
    <div class="empty"><p><?= e($emptyText) ?></p></div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th><?= tt('th.reference') ?></th><th><?= tt('th.vessel_proj') ?></th>
          <?php if ($desk === 'driver'): ?>
            <th><?= tt('th.deliver_to') ?></th><th><?= tt('th.contact') ?></th>
            <th><?= tt('th.lines') ?></th><th><?= tt('th.priority') ?></th><th><?= tt('th.status') ?></th>
            <th><?= tt('th.needed_by') ?></th>
          <?php elseif ($desk === 'procurement'): ?>
            <th><?= tt('th.raised_by') ?></th><th><?= tt('th.lines') ?></th>
            <th><?= tt('th.supplier') ?></th><th><?= tt('th.po_progress') ?></th>
            <th><?= tt('th.priority') ?></th><th><?= tt('th.status') ?></th>
            <?php if (!empty($showAge)): ?><th><?= tt('th.waiting') ?></th><?php endif; ?>
            <th><?= tt('th.approx') ?></th>
          <?php else: ?>
            <th><?= tt('th.raised_by') ?></th>
            <th><?= tt('th.lines') ?></th><th><?= tt('th.priority') ?></th><th><?= tt('th.status') ?></th>
            <?php if (!empty($showAge)): ?><th><?= tt('th.waiting') ?></th><?php endif; ?>
            <th><?= tt('th.approx') ?></th>
          <?php endif; ?>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td class="ref"><a href="<?= url('order', ['id' => $o['id']]) ?>"><?= e($o['order_ref']) ?></a></td>
          <td><strong><?= e($o['vessel_name'] ?: '—') ?></strong><br><span class="hint"><?= e($o['project_number']) ?></span></td>
          <?php if ($desk === 'driver'): ?>
            <td><?= $o['delivery_address'] ? e($o['delivery_address']) : '<span class="hint">' . tt('th.no_address') . '</span>' ?></td>
            <td><?= e($o['requester']) ?><?= $o['requester_phone'] ? '<br><span class="hint">' . e($o['requester_phone']) . '</span>' : '' ?></td>
            <td class="num"><?= (int)($o['line_count'] ?? 0) ?></td>
            <td class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></td>
            <td><?= status_pill($o['status']) ?></td>
            <td class="date"><?= e(fmt_date($o['needed_by'])) ?></td>
          <?php elseif ($desk === 'procurement'): ?>
            <td><?= e($o['requester']) ?></td>
            <td class="num"><?= (int)($o['line_count'] ?? 0) ?></td>
            <td><?= $o['suppliers'] ? e($o['suppliers']) : '<span class="hint">—</span>' ?></td>
            <td class="num"><?= (int)($o['lines_ordered'] ?? 0) ?> / <?= (int)($o['line_count'] ?? 0) ?></td>
            <td class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></td>
            <td><?= status_pill($o['status']) ?></td>
            <?php if (!empty($showAge)): ?><td class="num"><?= (int)($o['age_days'] ?? 0) ?> <?= tt('common.days') ?></td><?php endif; ?>
            <td class="date"><?= e(fmt_date($o['approx_delivery_date'] ?: $o['needed_by'])) ?></td>
          <?php else: ?>
            <td><?= e($o['requester']) ?></td>
            <td class="num"><?= (int)($o['line_count'] ?? 0) ?></td>
            <td class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></td>
            <td><?= status_pill($o['status']) ?></td>
            <?php if (!empty($showAge)): ?><td class="num"><?= (int)($o['age_days'] ?? 0) ?> <?= tt('common.days') ?></td><?php endif; ?>
            <td class="date"><?= e(fmt_date($o['approx_delivery_date'] ?: $o['needed_by'])) ?></td>
          <?php endif; ?>
          <td><a class="btn btn--ghost btn--sm" href="<?= url('order', ['id' => $o['id']]) ?>"><?= tt('btn.open') ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php pager($slug, $page, $pages, $keep); ?>

<?php layout_foot(); ?>

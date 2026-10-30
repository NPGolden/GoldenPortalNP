<?php
require __DIR__ . '/_layout.php';
$u   = current_user();
$uid = (int)$u['id'];
$r   = $u['role_code'];

/* What "my orders" means depends on where you sit in the chain. */
switch ($r) {
    case 'project_manager':   $scope = 'o.pm_user_id = :me'; break;
    case 'technical_manager': $scope = 'o.tm_user_id = :me'; break;
    case 'procurement':       $scope = "o.status IN ('with_procurement','ordered','backordered')"; break;
    case 'warehouse':         $scope = "o.status IN ('ordered','backordered','in_warehouse','ready_for_dispatch','partially_fulfilled')"; break;
    case 'driver':            $scope = "o.status IN ('ready_for_dispatch','in_transit')"; break;
    case 'system_admin':      $scope = '1 = 1'; break;
    default:                  $scope = 'o.requested_by = :me';
}
$bind = strpos($scope, ':me') !== false ? ['me' => $uid] : [];

$rows = all("SELECT o.*, ru.full_name AS requester
             FROM orders o JOIN users ru ON ru.id = o.requested_by
             WHERE {$scope} AND o.status NOT IN ('closed','cancelled')
             ORDER BY FIELD(o.priority,'vessel_down','urgent','routine'), o.created_at DESC
             LIMIT 25", $bind);

$openCount = (int) scalar("SELECT COUNT(*) FROM orders o WHERE {$scope} AND o.status NOT IN ('closed','cancelled')", $bind);
$lateCount = (int) scalar("SELECT COUNT(*) FROM orders o WHERE {$scope}
                           AND o.status NOT IN ('closed','cancelled','delivered')
                           AND o.needed_by IS NOT NULL AND o.needed_by < CURDATE()", $bind);

$waitOnMe = 0;
if ($r === 'project_manager')   $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE pm_user_id = ? AND status = 'awaiting_pm'", [$uid]);
if ($r === 'technical_manager') $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE tm_user_id = ? AND status = 'awaiting_tm'", [$uid]);
if ($r === 'procurement')       $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE status = 'with_procurement'");
if ($r === 'warehouse')         $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE status IN ('ordered','in_warehouse')");
if ($r === 'driver')            $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE status = 'ready_for_dispatch'");
if ($r === 'field_engineer')    $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE requested_by = ? AND status IN ('draft','delivered','partially_fulfilled')", [$uid]);
if ($r === 'system_admin')      $waitOnMe = (int) scalar("SELECT COUNT(*) FROM orders WHERE status IN ('awaiting_pm','awaiting_tm')");

$delivered30 = (int) scalar("SELECT COUNT(*) FROM orders o WHERE {$scope}
                             AND o.actual_delivery_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)", $bind);

$waitKey  = 'dash.wait.' . $r;
$waitText = t($waitKey) === $waitKey ? t('dash.wait.default') : t($waitKey);

$hour  = (int) date('H');
$greet = $hour < 12 ? 'dash.morning' : ($hour < 18 ? 'dash.afternoon' : 'dash.evening');

layout_head(t('nav.dashboard'), 'dashboard');
?>

<div class="page-head">
  <div>
    <p class="eyebrow"><?= e($u['role_name']) ?></p>
    <h1><?= e(t($greet, ['name' => explode(' ', $u['full_name'])[0]])) ?></h1>
    <p class="lede"><?= tt('dash.lede') ?></p>
  </div>
  <?php if (in_array($r, ['field_engineer', 'system_admin'], true)): ?>
    <a class="btn btn--gold" href="<?= url('order_new') ?>"><?= tt('nav.raise') ?></a>
  <?php endif; ?>
</div>

<div class="grid grid-4" style="margin-bottom:26px;">
  <div class="stat"><b><?= $openCount ?></b><span><?= tt('dash.stat_open') ?></span></div>
  <div class="stat stat--live"><b><?= $waitOnMe ?></b><span><?= e($waitText) ?></span></div>
  <div class="stat<?= $lateCount ? ' stat--alert' : '' ?>"><b><?= $lateCount ?></b><span><?= tt('dash.stat_late') ?></span></div>
  <div class="stat"><b><?= $delivered30 ?></b><span><?= tt('dash.stat_del30') ?></span></div>
</div>

<div class="card">
  <h2><?= tt('dash.open_orders') ?></h2>
  <?php if (!$rows): ?>
    <div class="empty">
      <p><?= tt('dash.empty') ?></p>
      <?php if (in_array($r, ['field_engineer','system_admin'], true)): ?>
        <a class="btn btn--ghost btn--sm" href="<?= url('order_new') ?>"><?= tt('dash.empty_cta') ?></a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th><?= tt('th.reference') ?></th><th><?= tt('th.vessel_proj') ?></th><th><?= tt('th.raised_by') ?></th>
          <th><?= tt('th.priority') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.expected') ?></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $o): ?>
        <tr>
          <td class="ref"><a href="<?= url('order', ['id' => $o['id']]) ?>"><?= e($o['order_ref']) ?></a></td>
          <td><strong><?= e($o['vessel_name'] ?: '—') ?></strong><br>
              <span class="hint"><?= e($o['project_number']) ?></span></td>
          <td><?= e($o['requester']) ?></td>
          <td class="flag-<?= e($o['priority']) ?>"><?= e(priority_label($o['priority'])) ?></td>
          <td><?= status_pill($o['status']) ?></td>
          <td class="date"><?= e(fmt_date($o['approx_delivery_date'] ?: $o['needed_by'])) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php layout_foot(); ?>

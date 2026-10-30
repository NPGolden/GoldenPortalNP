<?php
/** Work orders — all in scope, or one project's.              GAS-CP-R8-001 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
$st  = (string)($_GET['status'] ?? '');
$q   = trim((string)($_GET['q'] ?? ''));
$p   = null;
if ($pid) {
    [$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
    $p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", $args);
    if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'joborders'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
}
$rows = jo_list($pid ?: null, $st, $q);
$pending = count(array_filter($rows, fn($j) => $j['status'] === 'pending_approval'));
[$scope, $args] = scope_sql('p');
$projects = $pid ? [] : all("SELECT p.id, p.project_code, p.name FROM projects p WHERE {$scope} AND p.status <> 'completed' ORDER BY p.project_code", $args);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('jo.plural'), $pid ? 'projects' : 'joborders');
?>
<?php if ($p): ?><p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?></p><?php endif; ?>
<div class="page-head">
  <div><h1><?= tt('jo.plural') ?></h1><p class="lede" style="margin-bottom:0;"><?= $p ? e($p['name'] . ($p['vessel'] ? ' · ' . $p['vessel'] : '')) : tt('jo.lede') ?></p></div>
  <?php if ($p): ?>
    <a class="btn btn--gold" href="<?= url('jo_edit', ['project' => $pid]) ?>">+ <?= tt('jo.new') ?></a>
  <?php elseif ($projects): ?>
    <form method="get" action="index.php" class="btn-row" style="align-items:center;">
      <input type="hidden" name="p" value="jo_edit">
      <select name="project" style="padding:9px 12px;border:1px solid var(--line);border-radius:8px;"><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"><?= e(($pr['project_code'] ?: '') . ' ' . $pr['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn--gold" type="submit">+ <?= tt('jo.new') ?></button>
    </form>
  <?php endif; ?>
</div>
<?php if ($pending && !is_staff()): ?><div class="flash flash--warn"><?= e(t('jo.pending_n', ['n' => $pending])) ?></div><?php endif; ?>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr auto auto;">
    <input type="hidden" name="p" value="<?= $pid ? 'jo_list' : 'joborders' ?>"><?php if ($pid): ?><input type="hidden" name="project" value="<?= $pid ?>"><?php endif; ?>
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>" placeholder="<?= tt('jo.search_ph') ?>"></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label>
      <select id="status" name="status"><option value=""><?= tt('flt.any') ?></option><?php foreach (jo_statuses() as $s): ?><option value="<?= $s ?>"<?= $sel($s, $st) ?>><?= e(t('jo.st_' . $s)) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('jo_list', ['reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
<?php if (!$rows): ?><div class="empty"><?= tt('jo.none') ?></div>
<?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('jo.no') ?></th><th><?= tt('jo.title_f') ?></th><?php if (!$pid): ?><th><?= tt('pr.one') ?></th><?php endif; ?><th><?= tt('jo.raised_by') ?></th><th><?= tt('jo.for') ?></th><th><?= tt('th.status') ?></th><th><?= tt('jo.approval') ?></th><th><?= tt('fsr.plural') ?></th><th></th></tr></thead>
    <tbody><?php [$pageRows, $pager] = page_list($rows, 'jo_list', ['project' => $pid, 'status' => $st, 'q' => $q]); foreach ($pageRows as $j): ?>
      <tr>
        <td class="num"><a href="<?= url('jo', ['id' => $j['id']]) ?>"><?= e($j['jo_no']) ?></a><?= $j['priority'] === 'urgent' ? ' ' . pill('bad', t('jo.urgent')) : '' ?></td>
        <td><strong><?= e($j['title']) ?></strong></td>
        <?php if (!$pid): ?><td><?= e($j['project_code'] ?: $j['project_name']) ?><?= $j['vessel'] ? '<br><span class="hint">' . e($j['vessel']) . '</span>' : '' ?></td><?php endif; ?>
        <td><?= e($j['requested_name'] ?: '—') ?><br><span class="hint"><?= $j['requested_side'] === 'customer' ? e($j['customer_name'] ?? tt('role.customer')) : 'Golden A/S' ?></span></td>
        <td class="date"><?= e(fmt_date($j['requested_for'])) ?></td>
        <td><?= jo_status_pill($j['status']) ?></td>
        <td class="date"><?= $j['approved_at'] ? e($j['approved_name'] . ' · ' . fmt_datetime($j['approved_at'])) : ($j['status'] === 'pending_approval' ? '<span class="hint">' . tt('jo.awaiting') . '</span>' : '—') ?></td>
        <td class="num"><?= (int)$j['n_reports'] ?></td>
        <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('jo', ['id' => $j['id']]) ?>"><?= tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= $pager ?? '' ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

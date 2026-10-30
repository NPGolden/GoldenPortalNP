<?php
/** Owner approvals — every document in scope; the owner's queue first.  GAS-CP-R10-001 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
$st  = (string)($_GET['status'] ?? '');
$q   = trim((string)($_GET['q'] ?? ''));
$rows = oa_list($pid ?: null, $st, $q);
$open = count(array_filter($rows, fn($r) => in_array($r['status'], oa_open_statuses(), true)));
[$scope, $args] = scope_sql('p');
$projects = all("SELECT p.id, p.project_code, p.name FROM projects p WHERE {$scope} AND p.status <> 'completed' ORDER BY p.project_code", $args);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$kpi = ['open' => 0, 'approved' => 0, 'rejected' => 0];
foreach (oa_list($pid ?: null) as $r) { if (in_array($r['status'], oa_open_statuses(), true)) $kpi['open']++; elseif (str_starts_with($r['status'], 'approved')) $kpi['approved']++; elseif ($r['status'] === 'rejected') $kpi['rejected']++; }
layout_head(t('oa.title'), 'approvals');
?>
<div class="page-head">
  <div><h1><?= tt('oa.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt(is_staff() ? 'oa.lede_staff' : 'oa.lede') ?></p></div>
</div>
<?php if ($open && !is_staff()): ?><div class="flash flash--warn"><?= e(t('oa.pending_n', ['n' => $open])) ?></div><?php endif; ?>

<div class="kpi" style="margin-bottom:16px;">
  <a class="stat gold" href="<?= url('approvals', ['status' => 'open'] + ($pid ? ['project' => $pid] : [])) ?>"><b><?= $kpi['open'] ?></b><?= tt('sub.awaiting') ?></a>
  <a class="stat green" href="<?= url('approvals', ['status' => 'approved'] + ($pid ? ['project' => $pid] : [])) ?>"><b><?= $kpi['approved'] ?></b><?= tt('sub.approved') ?></a>
  <a class="stat" href="<?= url('approvals', ['status' => 'rejected'] + ($pid ? ['project' => $pid] : [])) ?>"><b><?= $kpi['rejected'] ?></b><?= tt('sub.rejected') ?></a>
  <a class="stat" href="<?= url('approvals', $pid ? ['project' => $pid] : []) ?>"><b><?= array_sum($kpi) ?></b><?= tt('sub.total') ?></a>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="approvals">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>" placeholder="<?= tt('oa.search_ph') ?>"></div>
    <div class="field"><label for="project"><?= tt('pr.one') ?></label>
      <select id="project" name="project"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"<?= $sel($pr['id'], $pid) ?>><?= e(($pr['project_code'] ?: '') . ' ' . $pr['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label>
      <select id="status" name="status"><option value=""><?= tt('flt.any') ?></option>
        <option value="open"<?= $sel('open', $st) ?>><?= tt('sub.awaiting') ?></option>
        <option value="approved"<?= $sel('approved', $st) ?>><?= tt('sub.approved') ?></option>
        <option value="rejected"<?= $sel('rejected', $st) ?>><?= tt('sub.rejected') ?></option>
        <?php if (is_staff()): ?><option value="draft"<?= $sel('draft', $st) ?>><?= tt('sub.draft') ?></option><?php endif; ?>
      </select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('approvals', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
<?php if (!$rows): ?><div class="empty"><?= tt('oa.none') ?></div>
<?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('sub.docno') ?></th><th><?= tt('sub.doc') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('sub.rev') ?></th><th><?= tt('sub.submitted') ?></th><th><?= tt('sub.due') ?></th><th><?= tt('th.status') ?></th><th><?= tt('oa.decision') ?></th><th><?= tt('th.comments') ?></th><th></th></tr></thead>
    <tbody><?php [$pageRows, $pager] = page_list($rows, 'approvals', ['project' => $pid, 'status' => $st, 'q' => $q]); foreach ($pageRows as $r): $openRow = in_array($r['status'], oa_open_statuses(), true); $late = $openRow && $r['due_at'] && $r['due_at'] < date('Y-m-d'); ?>
      <tr>
        <td class="ref"><a href="<?= url('approval', ['id' => $r['id']]) ?>"><?= e($r['doc_no']) ?></a></td>
        <td><strong><?= e($r['title']) ?></strong><?= $r['original_name'] ? '<br><span class="hint">' . e($r['original_name']) . '</span>' : '' ?></td>
        <td><?= e($r['project_code'] ?: $r['project_name']) ?><?= $r['sub_code'] ? '<br><span class="hint">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</span>' : '' ?><?= is_staff() && $r['customer_name'] ? '<br><span class="hint">' . e($r['customer_name']) . '</span>' : '' ?></td>
        <td class="num"><?= e($r['revision']) ?></td>
        <td class="date"><?= e(fmt_date($r['submitted_at'])) ?></td>
        <td class="date<?= $late ? ' is-late' : '' ?>"><?= e(fmt_date($r['due_at'])) ?><?= $late ? ' ' . pill('bad', t('oa.overdue')) : '' ?></td>
        <td><?= submittal_pill($r['status']) ?></td>
        <td class="date"><?= !empty($r['decided_time']) ? e(($r['decided_name'] ?? '') . ' · ' . fmt_datetime($r['decided_time'])) : ($r['decided_at'] ? e(fmt_date($r['decided_at'])) : '—') ?></td>
        <td class="num"><?= (int)$r['n_comments'] ?></td>
        <td class="row-actions"><a class="btn <?= $openRow && !is_staff() ? 'btn--gold' : 'btn--ghost' ?> btn--sm" href="<?= url('approval', ['id' => $r['id']]) ?>"><?= $openRow && !is_staff() ? tt('oa.review') : tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= $pager ?? '' ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

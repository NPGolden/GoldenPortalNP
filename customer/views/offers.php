<?php
/** Offers — per project / sub-project with status, customer amount and aging.   GAS-CP-R15-001
 *   ?p=offers[&project=&vessel=&status=&q=] */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
if (!of_has()) { layout_head(t('of.title'), 'offers'); echo '<h1>' . tt('of.title') . '</h1><div class="flash flash--warn">' . tt('of.need_r15') . '</div>'; layout_foot(); return; }
$f = ['project' => (int)($_GET['project'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = of_list($f);
$all = of_list(array_filter(['project' => $f['project'], 'vessel' => $f['vessel']]));
$k = ['sent' => 0, 'sent_amount' => 0.0, 'approved' => 0, 'approved_amount' => 0.0, 'tbi' => 0, 'tbi_amount' => 0.0, 'rejected' => 0, 'oldest' => 0, 'ages' => []];
foreach ($all as $o) {
    $a = (float)$o['amount'];
    if ($o['status'] === 'sent') { $k['sent']++; $k['sent_amount'] += $a; $age = of_age($o) ?? 0; $k['ages'][] = $age; $k['oldest'] = max($k['oldest'], $age); }
    elseif (in_array($o['status'], ['approved', 'in_progress', 'to_be_invoiced', 'finished'], true)) { $k['approved']++; $k['approved_amount'] += $a; if ($o['status'] === 'to_be_invoiced') { $k['tbi']++; $k['tbi_amount'] += $a; } }
    elseif ($o['status'] === 'rejected') $k['rejected']++;
}
$avgAge = $k['ages'] ? round(array_sum($k['ages']) / count($k['ages'])) : null;
[$scope, $args] = scope_sql('p');
$projects = all("SELECT p.id, p.project_code, p.name FROM projects p WHERE {$scope} ORDER BY p.project_code, p.name", $args);
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE {$vs} ORDER BY v.name", $va);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$keep = array_filter($f);
layout_head(t('of.title'), 'offers');
?>
<div class="page-head">
  <div><h1><?= tt('of.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt($staff ? 'of.lede_staff' : 'of.lede') ?></p></div>
  <?php if ($staff): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('import') ?>#offers">⤒ <?= tt('nav.import') ?></a><a class="btn btn--gold" href="<?= url('offer_edit', $f['project'] ? ['project' => $f['project']] : []) ?>">+ <?= tt('of.new') ?></a></div><?php endif; ?>
</div>
<?php if ($k['sent'] && !$staff): ?><div class="flash flash--warn"><?= e(t('of.pending_n', ['n' => $k['sent']])) ?></div><?php endif; ?>

<div class="kpi kpi--5" style="margin-bottom:16px;">
  <a class="stat gold" href="<?= url('offers', ['status' => 'sent'] + $keep) ?>"><b><?= $k['sent'] ?></b><?= tt('of.k_sent') ?><span class="s"><?= e(of_money($k['sent_amount'])) ?></span></a>
  <div class="stat<?= $k['oldest'] > 30 ? '' : ' green' ?>"><b><?= $k['oldest'] ? $k['oldest'] . ' ' . tt('tk.d') : '—' ?></b><?= tt('of.k_oldest') ?><span class="s"><?= $avgAge !== null ? e(t('of.k_avg_age', ['n' => $avgAge])) : '' ?></span></div>
  <a class="stat green" href="<?= url('offers', ['status' => 'approved'] + $keep) ?>"><b><?= $k['approved'] ?></b><?= tt('of.k_approved') ?><span class="s"><?= e(of_money($k['approved_amount'])) ?></span></a>
  <a class="stat" href="<?= url('offers', ['status' => 'to_be_invoiced'] + $keep) ?>"><b><?= $k['tbi'] ?></b><?= tt('of.st_to_be_invoiced') ?><span class="s"><?= e(of_money($k['tbi_amount'])) ?></span></a>
  <a class="stat" href="<?= url('offers', ['status' => 'rejected'] + $keep) ?>"><b><?= $k['rejected'] ?></b><?= tt('of.st_rejected') ?></a>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="offers">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('of.search_ph') ?>"></div>
    <div class="field"><label for="vessel"><?= tt('v.one') ?></label><select id="vessel" name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="project"><?= tt('pr.one') ?></label><select id="project" name="project"><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"<?= $sel($pr['id'], $f['project']) ?>><?= e(($pr['project_code'] ?: '') . ' ' . $pr['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label><select id="status" name="status"><option value=""><?= tt('flt.any') ?></option><option value="open"<?= $sel('open', $f['status']) ?>><?= tt('of.st_open') ?></option><?php foreach (of_statuses() as $s): ?><option value="<?= $s ?>"<?= $sel($s, $f['status']) ?>><?= e(of_status_label($s)) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('offers', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
<?php if (!$rows): ?><div class="empty"><?= tt('of.none') ?></div>
<?php else: $tot = 0.0; foreach ($rows as $o) $tot += (float)$o['amount']; [$pageRows, $pager] = page_list($rows, 'offers', $keep); ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('of.no') ?></th><th><?= tt('of.title_f') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('v.one') ?></th><th class="num"><?= tt('of.amount') ?></th><th><?= tt('of.sent') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('of.aging') ?></th><th><?= tt('oa.decision') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($pageRows as $o): ?>
      <tr>
        <td class="ref"><a href="<?= url('offer', ['id' => $o['id']]) ?>"><?= e($o['offer_no']) ?></a> <span class="hint"><?= e($o['revision']) ?></span> <?= of_origin_pill($o) ?><?= !empty($o['sq_no']) ? '<br><span class="hint mono" title="SQ">' . e($o['sq_no']) . '</span>' : '' ?></td>
        <td><strong><?= e($o['title']) ?></strong></td>
        <td><?= e($o['project_code'] ?: $o['project_name']) ?><?= $o['sub_code'] ? '<br><span class="hint">' . e($o['sub_code'] . ' ' . $o['sub_name']) . '</span>' : '' ?></td>
        <td><?= e($o['vessel'] ?: '—') ?><?= $staff && $o['customer_name'] ? '<br><span class="hint">' . e($o['customer_name']) . '</span>' : '' ?></td>
        <td class="num"><b><?= e(of_money((float)$o['amount'], $o['currency'])) ?></b></td>
        <td class="date"><?= e(fmt_date($o['sent_at'])) ?><?= $o['valid_until'] ? '<br><span class="hint">' . tt('of.valid_until') . ' ' . e(fmt_date($o['valid_until'])) . '</span>' : '' ?></td>
        <td><?= of_status_pill($o['status']) ?></td>
        <td class="num"><?= pill(of_age_tone($o), of_age_label($o)) ?></td>
        <td class="date"><?= $o['decided_at'] ? e(($o['decided_name'] ?? '') . ' · ' . fmt_datetime($o['decided_at'])) : '—' ?></td>
        <td class="row-actions"><a class="btn <?= $o['status'] === 'sent' && !$staff ? 'btn--gold' : 'btn--ghost' ?> btn--sm" href="<?= url('offer', ['id' => $o['id']]) ?>"><?= $o['status'] === 'sent' && !$staff ? tt('oa.review') : tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="4"><b><?= e(t('of.count', ['n' => count($rows)])) ?></b></td><td class="num"><b><?= e(of_money($tot)) ?></b></td><td colspan="5"></td></tr></tfoot>
  </table></div>
  <?= $pager ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

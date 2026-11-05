<?php
/** Procurement › Requisitions — list with filters (GAS-CP-R80-001).  ?p=requisitions[&status=&mine=1&q=&project_id=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('prq_can') || !prq_can()) { http_response_code(403); layout_head(t('prq.title'), 'requisitions'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('prq.noaccess_hint') . '</p>'; layout_foot(); return; }
$f = ['status' => (string)($_GET['status'] ?? ''), 'mine' => (int)($_GET['mine'] ?? 0), 'q' => trim((string)($_GET['q'] ?? '')), 'project_id' => (int)($_GET['project_id'] ?? 0)];
if ($f['status'] === '' && !isset($_GET['status'])) $f['status'] = 'open';
$rows = prq_list($f); [$rows, $pg] = page_list($rows, 'requisitions', array_filter($f));
$cnt = prq_counts(); $admin = pr_admin(); $priced = stk_price_visible(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('prq.title'), 'requisitions');
?>
<div class="page-head">
  <div><h1>🧾 <?= tt('prq.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('prq.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('requisition', ['id' => 0]) ?>">+ <?= tt('prq.new') ?></a></div>
</div>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <a class="stat<?= $cnt['pending'] ? ' gold' : '' ?>" href="<?= url('requisitions', ['status' => 'pending']) ?>"><b><?= $cnt['pending'] ?></b><?= tt('prq.k_pending') ?><span class="s"><?= tt('prq.k_pending_hint') ?></span></a>
  <a class="stat<?= $cnt['mgmt'] ? ' red' : '' ?>" href="<?= url('requisitions', ['status' => 'mgmt']) ?>"><b><?= $cnt['mgmt'] ?></b><?= tt('prq.k_mgmt') ?><span class="s"><?= tt('prq.k_mgmt_hint') ?></span></a>
  <a class="stat" href="<?= url('requisitions', ['status' => 'approved']) ?>"><b><?= $cnt['approved'] ?></b><?= tt('prq.k_approved') ?><span class="s"><?= tt('prq.k_approved_hint') ?></span></a>
  <a class="stat" href="<?= url('requisitions', ['mine' => 1, 'status' => '']) ?>"><b><?= $cnt['mine'] ?></b><?= tt('prq.k_mine') ?><span class="s"><?= tt('prq.k_mine_hint') ?></span></a>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr auto 1.6fr auto;">
  <input type="hidden" name="p" value="requisitions">
  <div class="field"><label><?= tt('prq.status') ?></label><select name="status"><option value=""<?= $sel($f['status'], '') ?>><?= tt('prq.all') ?></option><option value="open"<?= $sel($f['status'], 'open') ?>><?= tt('prq.open') ?></option><option value="pending"<?= $sel($f['status'], 'pending') ?>><?= tt('prq.k_pending') ?></option><?php foreach (PRQ_STATES as $s): ?><option value="<?= $s ?>"<?= $sel($f['status'], $s) ?>><?= tt('prq.s_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><label class="chk"><input type="checkbox" name="mine" value="1"<?= $f['mine'] ? ' checked' : '' ?>> <?= tt('prq.mine') ?></label></div>
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('prq.search_ph') ?>"></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
</form>
<div class="card">
<table class="tbl tbl--wrap">
  <thead><tr><th><?= tt('prq.no') ?></th><th><?= tt('prq.project') ?></th><th><?= tt('prq.purpose') ?></th><th><?= tt('prq.requester') ?></th><th><?= tt('prq.need') ?></th><th class="num"><?= tt('prq.lines') ?></th><?php if ($priced): ?><th class="num"><?= tt('prq.estimate') ?></th><?php endif; ?><th><?= tt('prq.status') ?></th></tr></thead>
  <tbody>
  <?php if (!$rows): ?><tr><td colspan="9" class="hint"><?= tt('prq.none') ?></td></tr><?php endif; ?>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><a href="<?= url('requisition', ['id' => (int)$r['id']]) ?>" style="font-family:var(--mono);"><?= e($r['pr_no']) ?></a><?php if ($r['title']): ?><br><small class="hint"><?= e($r['title']) ?></small><?php endif; ?></td>
      <td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?php if ($r['subproject_id']): ?><br><small><a href="<?= url('subproject', ['id' => (int)$r['subproject_id'], 'tab' => 'procurement']) ?>"><?= e($r['sub_code'] . ' · ' . $r['sub_name']) ?></a></small><?php endif; ?></td>
      <td><?= tt('prq.p_' . $r['purpose']) ?></td>
      <td><?= e($r['requester_name'] ?? '') ?><?php if ($r['discipline']): ?><br><small class="hint"><?= e($r['discipline']) ?></small><?php endif; ?></td>
      <td><?= fmt_date($r['need_date']) ?></td>
      <td class="num"><?= (int)$r['n_lines'] ?><?php if ((int)$r['n_to_buy']): ?> <span class="pill pill--gold" title="<?= tt('prq.l_to_buy') ?>"><?= (int)$r['n_to_buy'] ?></span><?php endif; ?></td>
      <?php if ($priced): ?><td class="num"><?= prq_money($r['est_total'] !== null ? (float)$r['est_total'] : null, $r['currency']) ?></td><?php endif; ?>
      <td><?= prq_pill($r['status']) ?><?php if ((int)$r['over_budget'] && in_array($r['status'], ['submitted', 'mgmt', 'approved'], true)): ?> <span class="pill pill--warn" title="<?= tt('prq.over') ?>">!</span><?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?= $pg ?>
</div>
<?php layout_foot();

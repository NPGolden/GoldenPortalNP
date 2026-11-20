<?php
/** My approvals (GAS-CP-R97-161): the register of every approval process for the signed-in member of staff.
 *  ?p=my_approvals[&tab=wait|mine&k=<kind>]  — read-only; each line opens the page where the decision is taken. */
require __DIR__ . '/_layout.php';
$u = require_staff();
$K = apr_kinds(); $wait = apr_waiting(); $mine = apr_mine();
$tab = (string)($_GET['tab'] ?? 'wait'); if (!in_array($tab, ['wait', 'mine'], true)) $tab = 'wait';
$k = (string)($_GET['k'] ?? ''); if (!isset($K[$k])) $k = '';
$open = array_values(array_filter($mine, fn($r) => $r['state'] === 'pending')); $done = array_values(array_filter($mine, fn($r) => $r['state'] !== 'pending'));
$set = $tab === 'wait' ? $wait : $mine; $cnt = []; foreach ($set as $r) $cnt[$r['kind']] = ($cnt[$r['kind']] ?? 0) + 1;
$rows = $k === '' ? $set : array_values(array_filter($set, fn($r) => $r['kind'] === $k));
$oldest = $wait ? max(array_column($wait, 'age')) : 0;
$ageP = fn(int $d) => pill($d >= 7 ? 'bad' : ($d >= 3 ? 'gold' : 'mute'), $d === 0 ? t('apr.today') : t('apr.days', ['n' => $d]));
layout_head(t('apr.title'), 'my_approvals');
?>
<div class="page-head"><div><h1>✅ <?= tt('apr.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('apr.lede') ?></p></div></div>
<div class="kpi kpi--4">
  <a class="stat" href="<?= url('my_approvals') ?>"><b><?= count($wait) ?></b><span class="s"><?= tt('apr.k_wait') ?></span></a>
  <span class="stat"><b><?= $wait ? $oldest : '—' ?></b><span class="s"><?= tt('apr.k_oldest') ?></span></span>
  <a class="stat" href="<?= url('my_approvals', ['tab' => 'mine']) ?>"><b><?= count($open) ?></b><span class="s"><?= tt('apr.k_open') ?></span></a>
  <a class="stat" href="<?= url('my_approvals', ['tab' => 'mine']) ?>"><b><?= count($done) ?></b><span class="s"><?= tt('apr.k_done', ['n' => APR_DAYS]) ?></span></a>
</div>
<div class="tabs"><a class="<?= $tab === 'wait' ? 'on' : '' ?>" href="<?= url('my_approvals') ?>"><?= tt('apr.t_wait') ?> <span class="n"><?= count($wait) ?></span></a><a class="<?= $tab === 'mine' ? 'on' : '' ?>" href="<?= url('my_approvals', ['tab' => 'mine']) ?>"><?= tt('apr.t_mine') ?> <span class="n"><?= count($mine) ?></span></a></div>
<?php if ($cnt): ?><div class="btn-row" style="margin:10px 0;flex-wrap:wrap;"><a class="btn btn--sm <?= $k === '' ? 'btn--gold' : 'btn--ghost' ?>" href="<?= url('my_approvals', $tab === 'mine' ? ['tab' => 'mine'] : []) ?>"><?= tt('apr.all') ?> <span class="n"><?= count($set) ?></span></a><?php foreach ($K as $kk => [$ic, $lk]): if (empty($cnt[$kk])) continue; ?><a class="btn btn--sm <?= $k === $kk ? 'btn--gold' : 'btn--ghost' ?>" href="<?= url('my_approvals', ($tab === 'mine' ? ['tab' => 'mine'] : []) + ['k' => $kk]) ?>"><?= $ic ?> <?= tt($lk) ?> <span class="n"><?= $cnt[$kk] ?></span></a><?php endforeach; ?></div><?php endif; ?>
<div class="card">
<?php if (!$rows): ?><p class="hint"><?= tt($tab === 'wait' ? 'apr.none_wait' : 'apr.none_mine') ?></p>
<?php elseif ($tab === 'wait'): ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('apr.th_kind') ?></th><th><?= tt('apr.th_ref') ?></th><th><?= tt('apr.th_what') ?></th><th><?= tt('apr.th_by') ?></th><th><?= tt('apr.th_step') ?></th><th><?= tt('apr.th_since') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): [$ic, $lk] = $K[$r['kind']]; ?><tr>
    <td><?= $ic ?> <?= tt($lk) ?></td><td style="font-family:var(--mono);"><a href="<?= e($r['url']) ?>"><?= e($r['ref'] !== '' ? $r['ref'] : '—') ?></a></td><td><?= e($r['title']) ?><?= !empty($r['late']) ? ' ' . pill('bad', t('apr.late')) : '' ?></td><td><?= e($r['who'] !== '' ? $r['who'] : '—') ?></td><td class="hint"><?= e($r['with'] !== '' ? $r['with'] : '—') ?></td>
    <td><?= $ageP((int)$r['age']) ?><br><span class="hint"><?= e(fmt_date($r['at'] ? substr((string)$r['at'], 0, 10) : null)) ?></span></td><td class="row-actions"><a class="btn btn--gold btn--sm" href="<?= e($r['url']) ?>"><?= tt('apr.open') ?></a></td>
  </tr><?php endforeach; ?></tbody></table></div>
<?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('apr.th_kind') ?></th><th><?= tt('apr.th_ref') ?></th><th><?= tt('apr.th_what') ?></th><th><?= tt('apr.th_sent') ?></th><th><?= tt('th.status') ?></th><th><?= tt('apr.th_with') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): [$ic, $lk] = $K[$r['kind']]; ?><tr>
    <td><?= $ic ?> <?= tt($lk) ?></td><td style="font-family:var(--mono);"><a href="<?= e($r['url']) ?>"><?= e($r['ref'] !== '' ? $r['ref'] : '—') ?></a></td><td><?= e($r['title']) ?></td><td class="hint"><?= e(fmt_date($r['at'] ? substr((string)$r['at'], 0, 10) : null)) ?></td>
    <td><?= pill($r['state'] === 'approved' ? 'green' : ($r['state'] === 'rejected' ? 'bad' : 'gold'), t('apr.s_' . $r['state'])) ?><?= $r['state'] === 'pending' ? ' ' . $ageP((int)$r['age']) : '' ?></td>
    <td class="hint"><?= $r['state'] === 'pending' ? e($r['with'] !== '' ? $r['with'] : '—') : e(fmt_date($r['decided_at'] ? substr((string)$r['decided_at'], 0, 10) : null)) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= e($r['url']) ?>"><?= tt('apr.open') ?></a></td>
  </tr><?php endforeach; ?></tbody></table></div>
<?php endif; ?>
</div>
<p class="hint" style="margin-top:10px;"><?= tt('apr.foot', ['n' => APR_DAYS]) ?></p>
<?php layout_foot();

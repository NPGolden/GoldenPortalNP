<?php
/** System Budget — complete the feed of the sub-project budget: labour lines of a converted budget that were never written get a section / discipline and are added as a new Budget & actual revision (GAS-CP-R97-007). ?p=system_budget_feed&id=N */
require __DIR__ . '/_layout.php';
$u = sb_require(); $id = (int)($_GET['id'] ?? 0); $B = sb_get($id);
if (!$B || !function_exists('sb_fed_col') || !sb_fed_col()) { http_response_code(404); layout_head(t('err.notfound'), 'system_budgets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$back = url('system_budget', ['id' => $id]); $ok = $B['status'] === 'converted' && (int)$B['subproject_id'] && function_exists('bg_has') && bg_has();
$FS = sb_feed_state($B); $secs = sb_sections(); $diss = sb_disciplines(); $bdef = sb_bucket_defaults(); $curB = $ok ? bg_current((int)$B['subproject_id']) : null; $curH = 0.0; if ($curB) foreach ($curB['hours'] as $h) $curH += (float)$h['hours'];
layout_head(t('sb.fd_title') . ' · ' . sb_label($B), 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <a href="<?= $back ?>"><?= e(sb_label($B)) ?></a> › <?= tt('sb.fd_title') ?></p>
<div class="page-head"><div><h1>€ <?= tt('sb.fd_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($B['title']) ?> · <?= e(trim((string)$B['project_code'] . ' › ' . (string)$B['sub_code'] . ' ' . (string)$B['sub_name'], ' ›')) ?></p></div>
  <?php if ((int)$B['subproject_id']): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => (int)$B['subproject_id'], 'tab' => 'budget']) ?>">€ <?= tt('bg.tab') ?> ›</a></div><?php endif; ?></div>
<?php if (!$ok): ?><div class="card"><p class="flash flash--info" style="margin:0 0 10px;"><?= tt('sb.fd_need_conv') ?></p><a class="btn btn--sm" href="<?= $back ?>">‹ <?= tt('sb.back') ?></a></div>
<?php else: ?>
<div class="kpi kpi--4">
  <div class="stat"><b><?= e(sb_h((float)$B['tot_hours'])) ?> h</b><?= tt('sb.fd_k_total') ?><span class="s"><?= e(t('sb.fd_k_travel', ['t' => sb_h($FS['travel'])])) ?></span></div>
  <div class="stat"><b><?= e(sb_h($FS['all'])) ?> h</b><?= tt('sb.fd_k_count') ?></div>
  <div class="stat green"><b><?= e(sb_h($FS['fed'])) ?> h</b><?= tt('sb.fd_k_fed') ?><span class="s"><?= $curB ? e(t('sb.fd_k_cur', ['r' => 'R' . (int)$curB['revision'], 'h' => sb_h($curH)])) : '' ?></span></div>
  <div class="stat<?= $FS['rest_hours'] > 0.005 ? ' red' : '' ?>"><b><?= e(sb_h($FS['rest_hours'])) ?> h</b><?= tt('sb.fd_k_rest') ?><span class="s"><?= count($FS['rest']) ?> <?= tt('sb.imp_lines') ?></span></div>
</div>
<div class="card">
  <div class="card-head"><h2><?= tt('sb.fd_rest') ?> <span class="count"><?= count($FS['rest']) ?></span></h2><span class="hint"><?= tt('sb.fd_rest_hint') ?></span></div>
  <?php if (!$FS['rest']): ?><p class="flash flash--good" style="margin:0;"><?= tt('sb.fd_all_in') ?></p><?php else: ?>
  <form method="post" action="<?= url('system_budget_feed', ['id' => $id]) ?>" data-lock data-confirm="<?= tt('sb.fd_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_feed_rest"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:1100px;"><thead><tr><th><?= tt('th.code') ?></th><th><?= tt('sb.l_descr') ?></th><th class="num"><?= tt('sb.hours') ?></th><th><?= tt('sb.section') ?></th><th><?= tt('sb.discipline') ?></th></tr></thead><tbody>
  <?php foreach ($FS['rest'] as $x): $ds = (int)($x['section_id'] ?: ($bdef[$x['bucket']] ?? 0)); ?><tr><td class="mono"><?= e($x['code']) ?></td><td><?= e($x['descr']) ?><?= $x['bucket'] !== '' ? ' <span class="hint">' . e($x['bucket']) . '</span>' : '' ?></td><td class="num"><b><?= e(sb_h($x['hours'])) ?></b></td>
    <td><select name="as[<?= (int)$x['id'] ?>][section_id]" data-nosearch><option value="0">—</option><?php foreach ($secs as $sk => $sv): ?><option value="<?= $sk ?>"<?= $ds === $sk ? ' selected' : '' ?>><?= e($sv['name']) ?></option><?php endforeach; ?></select></td>
    <td><select name="as[<?= (int)$x['id'] ?>][discipline_id]" data-nosearch><option value="0"><?= tt('sb.fd_by_section') ?></option><?php foreach ($diss as $dk => $dn): ?><option value="<?= $dk ?>"<?= (int)$x['discipline_id'] === $dk ? ' selected' : '' ?>><?= e($dn) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0;"><?= tt('sb.fd_assign_hint') ?></p>
  <div class="btn-row"><button class="btn btn--gold" type="submit">＋ <?= tt('sb.fd_go') ?></button><a class="btn btn--ghost" href="<?= $back ?>"><?= tt('btn.cancel') ?></a></div>
  </form><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

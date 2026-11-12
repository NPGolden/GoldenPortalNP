<?php
/** System Budget — convert a finished budget to a draft offer and feed the sub-project budget: preview and options (GAS-CP-R97-004). ?p=system_budget_convert&id=N[&labour=line|section|lump][&parts=line|lump][&commission=0|1] */
require __DIR__ . '/_layout.php';
$u = sb_require(); $id = (int)($_GET['id'] ?? 0); $B = sb_get($id);
if (!$B || !function_exists('sb_r4') || !sb_r4()) { http_response_code(404); layout_head(t('err.notfound'), 'system_budgets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$back = url('system_budget', ['id' => $id]); $cur = (string)$B['currency']; $can = sb_can_convert() && $B['status'] === 'finished' && empty($B['is_template']);
$opt = ['labour' => in_array($_GET['labour'] ?? '', ['line', 'section', 'lump'], true) ? $_GET['labour'] : 'line', 'parts' => in_array($_GET['parts'] ?? '', ['line', 'lump'], true) ? $_GET['parts'] : 'line', 'commission' => isset($_GET['commission']) ? (int)$_GET['commission'] === 1 : (float)$B['commission_pct'] > 0];
$P = sb_offer_lines($B, $opt); $F = sb_feed_plan($B); $T = sb_totals($B); $n2 = fn(float $v) => number_format($v, 2, ',', '.');
$bgOn = function_exists('bg_has') && bg_has(); $curB = $bgOn && (int)$B['subproject_id'] ? bg_current((int)$B['subproject_id']) : null; $curH = 0.0; if ($curB) foreach ($curB['hours'] as $h) $curH += (float)$h['hours'];
$scope = sb_scope_text($B); $cost = 0.0; foreach ($P['lines'] as $x) if ($x['kind'] !== 'text') $cost += (float)$x['qty'] * (float)($x['cost_price'] ?? 0);
layout_head(t('sb.cv_title') . ' · ' . sb_label($B), 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <a href="<?= $back ?>"><?= e(sb_label($B)) ?></a> › <?= tt('sb.cv_title') ?></p>
<div class="page-head"><div><h1>➜ <?= tt('sb.cv_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($B['title']) ?> · <?= e(trim((string)$B['project_code'] . ' › ' . (string)$B['sub_code'] . ' ' . (string)$B['sub_name'], ' ›')) ?></p></div></div>
<?php if (!$can): ?>
<div class="card"><p class="flash flash--info" style="margin:0 0 10px;"><?= tt($B['status'] !== 'finished' ? 'sb.cv_need_finished' : 'sb.cv_no_right') ?></p><a class="btn btn--sm" href="<?= $back ?>">‹ <?= tt('sb.back') ?></a></div>
<?php else: ?>
<div class="card">
  <h2>⚙ <?= tt('sb.cv_options') ?></h2>
  <form method="get" action="index.php"><input type="hidden" name="p" value="system_budget_convert"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('sb.cv_o_labour') ?></label><select name="labour" data-nosearch><?php foreach (['line', 'section', 'lump'] as $k): ?><option value="<?= $k ?>"<?= $opt['labour'] === $k ? ' selected' : '' ?>><?= tt('sb.cv_ol_' . $k) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sb.cv_o_parts') ?></label><select name="parts" data-nosearch><?php foreach (['line', 'lump'] as $k): ?><option value="<?= $k ?>"<?= $opt['parts'] === $k ? ' selected' : '' ?>><?= tt('sb.cv_op_' . $k) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sb.cv_o_comm') ?></label><select name="commission" data-nosearch<?= (float)$B['commission_pct'] > 0 ? '' : ' disabled' ?>><option value="1"<?= $opt['commission'] ? ' selected' : '' ?>><?= e(t('sb.cv_oc_yes', ['p' => rtrim(rtrim(number_format((float)$B['commission_pct'], 1, ',', ''), '0'), ',')])) ?></option><option value="0"<?= $opt['commission'] ? '' : ' selected' ?>><?= tt('sb.cv_oc_no') ?></option></select></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('sb.cv_refresh') ?></button><span class="hint"><?= tt('sb.cv_options_hint') ?></span></div>
  </form>
</div>

<div class="card">
  <div class="card-head"><h2>📄 <?= tt('sb.cv_offer') ?> <span class="count"><?= count($P['lines']) ?></span></h2><span class="hint"><?= tt('sb.cv_offer_hint') ?></span></div>
  <div class="kpi kpi--4" style="margin-bottom:10px;">
    <div class="stat green"><b><?= e(sb_money($P['net'], $cur)) ?></b><?= tt('sb.cv_k_net') ?></div>
    <div class="stat"><b><?= e(sb_money($T['sale'], $cur)) ?></b><?= tt('sb.cv_k_sb') ?></div>
    <div class="stat"><b><?= e(sb_money($cost, $cur)) ?></b><?= tt('sb.cv_k_cost') ?></div>
    <div class="stat"><b><?= $P['net'] > 0.005 ? e(number_format(100 * ($P['net'] - $cost) / $P['net'], 1, ',', '')) . ' %' : '—' ?></b><?= tt('sb.margin') ?></div>
  </div>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th style="min-width:300px;"><?= tt('sb.l_descr') ?></th><th class="num"><?= tt('sb.qty') ?></th><th><?= tt('sb.unit') ?></th><th class="num"><?= tt('sb.cv_price') ?></th><th class="num"><?= tt('sb.disc_pct') ?></th><th class="num"><?= tt('sb.total') ?></th><th class="num"><?= tt('sb.unit_cost') ?></th></tr></thead><tbody>
  <?php foreach ($P['lines'] as $i => $x): ?><tr<?= $x['kind'] === 'text' ? ' class="hint"' : '' ?>><td class="hint"><?= $i + 1 ?></td><td><?= $x['erp_code'] !== '' ? '<span class="mono hint">' . e($x['erp_code']) . '</span> ' : '' ?><?= e($x['description']) ?><?= $x['kind'] === 'item' ? ' 📦' : '' ?></td><?php if ($x['kind'] === 'text'): ?><td></td><td></td><td></td><td></td><td></td><td></td><?php else: ?><td class="num"><?= e(sb_h((float)$x['qty'])) ?></td><td><?= e($x['unit']) ?></td><td class="num"><?= e($n2((float)$x['unit_price'])) ?></td><td class="num"><?= ($x['discount_pct'] ?? 0) > 0 ? e($n2((float)$x['discount_pct'])) . ' %' : '' ?></td><td class="num"><b><?= e($n2(round($x['qty'] * $x['unit_price'] * (1 - ($x['discount_pct'] ?? 0) / 100), 2))) ?></b></td><td class="num hint"><?= $x['cost_price'] !== null ? e($n2((float)$x['cost_price'])) : '' ?></td><?php endif; ?></tr><?php endforeach; ?>
  <tr><td></td><td><b><?= tt('sb.total') ?></b></td><td></td><td></td><td></td><td></td><td class="num"><b><?= e($n2($P['net'])) ?></b></td><td></td></tr>
  </tbody></table></div>
  <?php if (abs($P['net'] - ($opt['commission'] ? $T['with_commission'] : $T['sale'])) > 1): ?><p class="hint" style="margin:8px 0 0;"><?= e(t('sb.cv_round', ['d' => sb_money($P['net'] - ($opt['commission'] ? $T['with_commission'] : $T['sale']), $cur)])) ?></p><?php endif; ?>
  <h3 style="margin:14px 0 6px;"><?= tt('sb.cv_scope') ?></h3>
  <?php if ($scope !== ''): ?><div class="rt"><?= rt_html($scope) ?></div><?php else: ?><p class="hint"><?= tt('sb.cv_scope_none') ?></p><?php endif; ?>
  <?php if ($P['excluded']): ?><h3 style="margin:14px 0 6px;"><?= tt('sb.cv_excl') ?></h3><ul><?php foreach ($P['excluded'] as $ex): ?><li><?= e($ex) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>

<form method="post" action="<?= url('system_budget_convert', ['id' => $id]) ?>" data-lock data-confirm="<?= tt('sb.cv_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_convert"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="labour" value="<?= e($opt['labour']) ?>"><input type="hidden" name="parts" value="<?= e($opt['parts']) ?>"><input type="hidden" name="commission" value="<?= $opt['commission'] ? 1 : 0 ?>">
<div class="card">
  <div class="card-head"><h2>€ <?= tt('sb.cv_bg') ?></h2><span class="hint"><?= tt('sb.cv_bg_hint') ?></span></div>
  <?php if (!$bgOn): ?><p class="hint"><?= tt('sb.cv_bg_off') ?></p><input type="hidden" name="budget" value="none"><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:800px;"><thead><tr><th><?= tt('sb.discipline') ?></th><th><?= tt('sb.section') ?></th><th class="num"><?= tt('sb.hours') ?></th></tr></thead><tbody>
  <?php foreach ($F['rows'] as $r): ?><tr><td><?= e($r['discipline']) ?></td><td><?= $r['section'] !== '' ? e($r['section']) : '<span class="hint">—</span>' ?></td><td class="num"><?= e(sb_h($r['hours'])) ?></td></tr><?php endforeach; ?>
  <tr><td><b><?= tt('sb.total') ?></b></td><td class="hint"><?= e(t('sb.cv_bg_parts', ['v' => sb_money($F['parts'], $cur)])) ?></td><td class="num"><b><?= e(sb_h($F['hours'])) ?></b></td></tr>
  </tbody></table></div>
  <?php if ($F['unmapped']): $secs = sb_sections(); $diss = sb_disciplines(); $bdef = sb_bucket_defaults(); ?>
  <p class="flash flash--warn" style="margin:10px 0 6px;"><?= e(t('sb.cv_unmapped2', ['n' => count($F['unmapped']), 'h' => sb_h(array_sum(array_column($F['unmapped'], 'hours')))])) ?></p>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:1000px;"><thead><tr><th><?= tt('th.code') ?></th><th><?= tt('sb.l_descr') ?></th><th class="num"><?= tt('sb.hours') ?></th><th><?= tt('sb.section') ?></th><th><?= tt('sb.discipline') ?></th></tr></thead><tbody>
  <?php foreach ($F['unmapped'] as $x): $ds = (int)($x['section_id'] ?: ($bdef[$x['bucket']] ?? 0)); ?><tr><td class="mono"><?= e($x['code']) ?></td><td><?= e($x['descr']) ?><?= $x['bucket'] !== '' ? ' <span class="hint">' . e($x['bucket']) . '</span>' : '' ?></td><td class="num"><?= e(sb_h($x['hours'])) ?></td>
    <td><select name="as[<?= (int)$x['id'] ?>][section_id]" data-nosearch><option value="0">—</option><?php foreach ($secs as $sk => $sv): ?><option value="<?= $sk ?>"<?= $ds === $sk ? ' selected' : '' ?>><?= e($sv['name']) ?></option><?php endforeach; ?></select></td>
    <td><select name="as[<?= (int)$x['id'] ?>][discipline_id]" data-nosearch><option value="0"><?= tt('sb.fd_by_section') ?></option><?php foreach ($diss as $dk => $dn): ?><option value="<?= $dk ?>"><?= e($dn) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:6px 0 0;"><?= tt('sb.fd_assign_hint') ?></p>
  <?php endif; ?>
  <p class="hint" style="margin:10px 0 6px;"><?= $curB ? e(t('sb.cv_bg_cur', ['r' => 'R' . (int)$curB['revision'], 'h' => sb_h($curH), 'p' => sb_money((float)$curB['parts_budget'], $cur)])) : tt('sb.cv_bg_nocur') ?></p>
  <div class="field"><label style="display:block;margin-bottom:4px;"><input type="radio" name="budget" value="replace" checked> <?= tt('sb.cv_bm_replace') ?></label><?php if ($curB): ?><label style="display:block;margin-bottom:4px;"><input type="radio" name="budget" value="add"> <?= tt('sb.cv_bm_add') ?></label><?php endif; ?><label style="display:block;"><input type="radio" name="budget" value="none"> <?= tt('sb.cv_bm_none') ?></label></div>
  <?php endif; ?>
</div>
<div class="btn-row"><button class="btn btn--gold" type="submit">➜ <?= tt('sb.cv_go') ?></button><a class="btn btn--ghost" href="<?= $back ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php endif; ?>
<?php layout_foot(); ?>

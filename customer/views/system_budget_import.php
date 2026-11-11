<?php
/** System Budgets — review of a GLD-BUD workbook before it fills a budget (GAS-CP-R97-001). ?p=system_budget_import&t=<token>[&into=<budget id>] */
require __DIR__ . '/_layout.php';
$u = sb_require(); $token = (string)($_GET['t'] ?? ''); $into = (int)($_GET['into'] ?? 0); $path = sb_imp_path($token); $X = null; $err = null;
$B = $into ? sb_get($into) : null; if ($into && (!$B || !sb_editable($B))) { $B = null; $into = 0; }
if (!$path) $err = t('sb.imp_expired'); else { require_once __DIR__ . '/../app/xlsx.php'; try { $X = sb_xlsx_parse($path); } catch (Throwable $e) { $err = $e->getMessage(); } }
$name = (string)($_SESSION['sb_imp'][$token] ?? ''); $sec = sb_sections(); $def = sb_bucket_defaults();
layout_head(t('sb.imp_title'), 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <?= tt('sb.imp_title') ?></p>
<div class="page-head"><div><h1>📥 <?= tt('sb.imp_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($name) ?><?= $B ? ' → ' . e(sb_label($B) . ' · ' . $B['title']) : '' ?></p></div></div>
<?php if ($err): ?>
<div class="card"><p class="flash flash--bad" style="margin:0 0 10px;"><?= e($err) ?></p><a class="btn btn--sm" href="<?= $B ? url('system_budget', ['id' => $into]) : url('system_budgets') ?>">‹ <?= tt('sb.back') ?></a></div>
<?php else: $h = $X['head']; $c = $X['calc']; $usedB = array_values(array_unique(array_filter(array_column($X['labour'], 'bucket')))); ?>
<?php if ($B): ?><p class="flash flash--warn"><?= e(t('sb.imp_replace_warn', ['n' => sb_label($B), 'l' => count($B['labour']), 'p' => count($B['parts']), 'x' => count($B['expenses'])])) ?></p><?php endif; ?>
<?php foreach ($X['warn'] as $w): ?><p class="flash flash--warn"><?= e($w) ?></p><?php endforeach; ?>

<div class="card">
  <h2><?= tt('sb.imp_doc') ?></h2>
  <div class="kpi kpi--4" style="margin-bottom:10px;">
    <div class="stat"><b><?= e(sb_h($c['mh'])) ?> h</b><?= tt('sb.imp_k_mh') ?><span class="s"><?= count($X['labour']) ?> <?= tt('sb.imp_lines') ?></span></div>
    <div class="stat"><b><?= e(sb_money($c['lco'])) ?></b><?= tt('sb.imp_k_lco') ?><span class="s"><?= count($X['parts']) ?> <?= tt('sb.imp_lines') ?> · × <?= e(number_format($c['markup'], 2, ',', '')) ?></span></div>
    <div class="stat"><b><?= e(sb_money($c['cost'])) ?></b><?= tt('sb.imp_k_cost') ?></div>
    <div class="stat green"><b><?= e(sb_money($c['total'])) ?></b><?= tt('sb.imp_k_total') ?><span class="s"><?= $h['commission'] > 0 ? e(t('sb.imp_k_comm', ['p' => rtrim(rtrim(number_format($h['commission'], 1, ',', ''), '0'), ',')])) : '' ?></span></div>
  </div>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:900px;"><tbody>
    <?php foreach ([['sb.f_title', $h['title']], ['sb.imp_d_ref', trim($h['doc_ref'] . ' ' . $h['revision'])], ['sb.imp_d_date', $h['date']], ['sb.imp_d_status', $h['status']], ['sb.imp_d_takeoff', $h['source']], ['sb.imp_d_customer', $h['customer']], ['sb.imp_d_class', $h['class']], ['sb.imp_d_prj', $h['prj']], ['sb.imp_d_est', $h['est_class']], ['sb.imp_d_validity', $h['validity']]] as [$k, $v]): if ($v === '') continue; ?><tr><td class="hint" style="width:220px;"><?= tt($k) ?></td><td><?= e($v) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0 0;"><?= tt('sb.imp_calc_hint') ?></p>
</div>

<form method="post" action="<?= url('system_budget_import', ['t' => $token] + ($into ? ['into' => $into] : [])) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_import_apply"><input type="hidden" name="t" value="<?= e($token) ?>"><input type="hidden" name="into" value="<?= $into ?>">
<div class="card">
  <div class="card-head"><h2>👷 <?= tt('sb.labour') ?> <span class="count"><?= count($X['labour']) ?></span></h2><span class="hint"><?= tt('sb.imp_lab_hint') ?></span></div>
  <?php if ($usedB && $sec): ?><div class="grid grid-3" style="margin-bottom:10px;"><?php foreach ($usedB as $b): if ($b === 'PM') continue; ?><div class="field"><label><?= e(t('sb.imp_bucket', ['b' => $b])) ?></label><select name="bs[<?= e($b) ?>]" data-nosearch><option value="0">—</option><?php foreach ($sec as $sid => $s): ?><option value="<?= $sid ?>"<?= (int)($def[$b] ?? 0) === $sid ? ' selected' : '' ?>><?= e($s['name'] . ' · ' . t('st.' . $s['stage'])) ?></option><?php endforeach; ?></select></div><?php endforeach; ?></div><?php endif; ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('th.code') ?></th><th><?= tt('sb.l_descr') ?></th><th><?= tt('sb.imp_key') ?></th><th><?= tt('sb.imp_bkt') ?></th><th class="num">MH</th><th class="num"><?= tt('sb.rate') ?></th><th class="num"><?= tt('sb.sale') ?></th><th><?= tt('th.remarks') ?></th></tr></thead><tbody>
  <?php foreach ($X['labour'] as $l): ?><tr<?= $l['mh'] <= 0 ? ' class="hint"' : '' ?>><td class="mono"><?= e($l['code']) ?></td><td><?= e($l['descr']) ?></td><td><?= e($l['key']) ?></td><td><?= e($l['bucket']) ?></td><td class="num"><?= e(sb_h($l['mh'])) ?></td><td class="num"><?= e(number_format($l['rate'], 2, ',', '.')) ?></td><td class="num"><?= e(sb_money($l['mh'] * $l['rate'])) ?></td><td class="hint"><?= e($l['remarks']) ?></td></tr><?php endforeach; ?>
  <tr><td></td><td><b><?= tt('sb.total') ?></b></td><td></td><td></td><td class="num"><b><?= e(sb_h($c['mh'])) ?></b></td><td></td><td class="num"><b><?= e(sb_money($c['lab_sell'])) ?></b></td><td class="hint"><?= e(t('sb.imp_cost_at', ['v' => sb_money($c['lab_cost']), 'r' => number_format($c['internal'], 2, ',', '.')])) ?></td></tr>
  </tbody></table></div>
</div>
<div class="card">
  <div class="card-head"><h2>🔩 <?= tt('sb.parts') ?> <span class="count"><?= count($X['parts']) ?></span></h2><span class="hint"><?= e(t('sb.imp_parts_hint', ['m' => rtrim(rtrim(number_format(($c['markup'] - 1) * 100, 1, ',', ''), '0'), ',')])) ?></span></div>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('th.code') ?></th><th><?= tt('sb.p_descr') ?></th><th class="num"><?= tt('sb.qty') ?></th><th><?= tt('sb.unit') ?></th><th class="num"><?= tt('sb.unit_cost') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th><?= tt('sb.origin') ?></th><th><?= tt('th.remarks') ?></th></tr></thead><tbody>
  <?php foreach ($X['parts'] as $p): ?><tr><td class="mono"><?= e($p['code']) ?></td><td><?= e($p['descr']) ?></td><td class="num"><?= e(sb_h($p['qty'])) ?></td><td><?= e($p['unit']) ?></td><td class="num"><?= e(number_format($p['lco'], 2, ',', '.')) ?></td><td class="num"><?= e(sb_money($p['qty'] * $p['lco'])) ?></td><td class="num"><?= e(sb_money($p['qty'] * $p['lco'] * $c['markup'])) ?></td><td class="hint"><?= e((string)$p['origin']) ?></td><td class="hint"><?= e($p['remarks']) ?></td></tr><?php endforeach; ?>
  <tr><td></td><td><b><?= tt('sb.total') ?></b></td><td></td><td></td><td></td><td class="num"><b><?= e(sb_money($c['lco'])) ?></b></td><td class="num"><b><?= e(sb_money($c['parts_sell'])) ?></b></td><td></td><td></td></tr>
  </tbody></table></div>
</div>
<?php if ($X['expenses']): ?><div class="card">
  <h2>🧾 <?= tt('sb.expenses') ?> <span class="count"><?= count($X['expenses']) ?></span></h2>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:900px;"><thead><tr><th><?= tt('sb.x_kind') ?></th><th><?= tt('sb.l_descr') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($X['expenses'] as $x): ?><tr><td><?= tt('sb.xk_' . $x['kind']) ?></td><td><?= e($x['descr']) ?></td><td class="num"><?= e(sb_money($x['cost'])) ?></td><td class="num"><?= e(sb_money($x['sale'])) ?></td><td class="hint"><?= $x['in_total'] ? '' : tt('sb.x_outside') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div><?php endif; ?>
<?php if ($X['notes'] || $X['revisions']): ?><div class="card"><h2>📝 <?= tt('sb.description') ?></h2><div class="rt"><?= rt_html(sb_imp_description($X)) ?></div></div><?php endif; ?>
<div class="btn-row"><button class="btn btn--gold" type="submit"><?= $B ? e(t('sb.imp_fill', ['n' => sb_label($B)])) : tt('sb.imp_create') ?></button><a class="btn btn--ghost" href="<?= $B ? url('system_budget', ['id' => $into]) : url('system_budgets') ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php endif; ?>
<?php layout_foot(); ?>

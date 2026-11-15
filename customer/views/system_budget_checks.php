<?php
/** System Budgets › Consistency overview — every open, finished and recently converted budget with its findings, worst first.   GAS-CP-R97-048 */
require __DIR__ . '/_layout.php';
$u = sb_require(); $rows = sbc_overview(); $nB = count(array_filter($rows, fn($r) => $r['s']['block'] > 0 && !$r['s']['ov'])); $nW = count(array_filter($rows, fn($r) => $r['s']['warn'] > 0));
layout_head(t('sbc.ov_title'), 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <?= tt('sbc.ov_title') ?></p>
<div class="page-head"><h1>🔍 <?= tt('sbc.ov_title') ?></h1><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('system_budgets') ?>">‹ <?= tt('sb.title') ?></a></div></div>
<p class="hint" style="margin:0 0 10px;"><?= tt('sbc.ov_hint') ?></p>
<div class="card"><div class="card-head"><h2><?= tt('sb.list') ?> <span class="count"><?= count($rows) ?></span> <?= $nB ? pill('bad', $nB . ' ' . t('sbc.sev_block')) : pill('green', t('sbc.ok_all')) ?><?= $nW ? ' ' . pill('gold', $nW . ' ' . t('sbc.ov_with_warn')) : '' ?></h2></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('sbc.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl tbl--compact"><thead><tr><th><?= tt('sb.no') ?></th><th><?= tt('sb.f_title') ?></th><th><?= tt('th.status') ?></th><th><?= tt('sb.owner') ?></th><th class="num"><?= tt('sbc.sev_block') ?></th><th class="num"><?= tt('sbc.sev_warn') ?></th><th class="num"><?= tt('sbc.sev_info') ?></th><th><?= tt('sbc.ov_first') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $s = $r['s']; ?><tr><td style="white-space:nowrap;"><a href="<?= url('system_budget', ['id' => (int)$r['id']]) ?>#checks"><b><?= e($r['sb_no'] . ' R' . (int)$r['revision']) ?></b></a></td><td><?= e(mb_substr((string)$r['title'], 0, 70)) ?><?= $r['project_code'] ? ' <span class="hint">' . e((string)$r['project_code']) . '</span>' : '' ?></td><td><?= sb_pill((string)$r['status']) ?></td><td class="hint"><?= e((string)($r['owner_name'] ?? '')) ?></td>
    <td class="num"><?= $s['block'] ? pill($s['ov'] ? 'gold' : 'bad', (string)$s['block']) . ($s['ov'] ? ' <span class="hint" title="' . tt('sbc.ov_accepted') . '">⚑</span>' : '') : '—' ?></td><td class="num"><?= $s['warn'] ? pill('gold', (string)$s['warn']) : '—' ?></td><td class="num"><?= $s['info'] ?: '—' ?></td><td class="hint" style="max-width:520px;"><?= e((string)$s['top']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php if (sb_can_reopen()): ?><div class="card" id="settings"><div class="card-head"><h2>⚙ <?= tt('sbc.set_title') ?></h2><span class="hint"><?= tt('sbc.set_hint') ?></span></div>
  <form method="post" action="<?= url('system_budget_checks') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sbc_settings">
    <div class="grid grid-3"><?php foreach (['price_tol', 'price_age', 'profit_drop', 'pm_share', 'ot_share', 'overrun'] as $k): ?><div class="field"><label><?= tt('sbc.set_' . $k) ?></label><input name="<?= $k ?>" inputmode="decimal" value="<?= e(rtrim(rtrim(number_format(sbc_set($k), 1, ',', ''), '0'), ',')) ?>" style="max-width:120px;"></div><?php endforeach; ?></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('btn.save') ?></button></div></form>
  <?php if (function_exists('sbp_has') && sbp_has()): ?><form method="post" action="<?= url('system_budget_checks') ?>" class="filters" style="margin-top:12px;border-top:1px solid var(--line);padding-top:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sbp_required"><label class="chk" style="margin:0;"><input type="checkbox" name="on" value="1"<?= sbp_required() ? ' checked' : '' ?>> <?= tt('sbp.required') ?></label><button class="btn btn--sm" type="submit">✓ <?= tt('btn.save') ?></button></form><?php endif; ?></div><?php endif; ?>
<?php layout_foot();

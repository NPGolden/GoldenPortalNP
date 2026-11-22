<?php
/** Settings › Recycle bin (GAS-CP-R97-190): files removed by users in the last 30 days — restore, or remove for good.  ?p=admin_trash[&tab=open|restored|purged] */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$tab = in_array($_GET['tab'] ?? '', ['open', 'restored', 'purged'], true) ? (string)$_GET['tab'] : 'open';
$rows = ft_has() ? ft_list($tab) : []; $nOpen = ft_open_count(); $sum = 0; foreach ($rows as $r) $sum += (int)$r['size_bytes'];
$TBL = fn(string $t) => t('ft.tb_' . $t) === 'ft.tb_' . $t ? $t : t('ft.tb_' . $t);
layout_head(t('ft.title'), 'admin_trash');
?>
<?php settings_tabs('admin_trash'); ?>
<div class="page-head"><div><h1>🗑 <?= tt('ft.title') ?></h1><p class="lede" style="margin-bottom:0;text-align:justify;"><?= e(t('ft.lede', ['d' => FT_DAYS])) ?></p></div></div>
<?php if (!ft_has()): ?><div class="card"><p class="hint"><?= tt('ft.need') ?></p></div><?php layout_foot(); return; endif; ?>
<div class="tabs"><?php foreach (['open' => 'ft.t_open', 'restored' => 'ft.t_restored', 'purged' => 'ft.t_purged'] as $k => $lk): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= url('admin_trash', ['tab' => $k]) ?>"><?= tt($lk) ?><?= $k === 'open' && $nOpen ? ' <span class="n">' . $nOpen . '</span>' : '' ?></a><?php endforeach; ?></div>
<div class="card">
  <div class="card-head"><h2><?= tt('ft.t_' . $tab) ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= $tab === 'open' ? e(t('ft.sum', ['s' => ft_size($sum)])) : '' ?></span>
    <?php if ($tab === 'open' && $rows): ?><form method="post" class="inline" data-confirm="<?= e(t('ft.empty_confirm', ['n' => count($rows)])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ft.empty') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="ft_empty"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 <?= tt('ft.empty') ?></button></form><?php endif; ?></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('ft.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ft.th_file') ?></th><th><?= tt('ft.th_from') ?></th><th class="num"><?= tt('th.size') ?></th><th><?= tt('ft.th_by') ?></th><th><?= tt('ft.th_when') ?></th><th><?= tt($tab === 'open' ? 'ft.th_until' : ($tab === 'restored' ? 'ft.th_restored' : 'ft.th_purged')) ?></th><?= $tab === 'open' ? '<th></th>' : '' ?></tr></thead><tbody>
  <?php foreach ($rows as $r): $gone = $tab === 'open' && !is_file((string)$r['path']); ?><tr>
    <td><?= (int)$r['protected'] ? '🔒 ' : '' ?><b><?= e((string)$r['label']) ?></b><?= $r['reason'] ? '<br><span class="hint">' . e(t('ft.reason')) . ': ' . e((string)$r['reason']) . '</span>' : '' ?><?= $gone ? '<br>' . pill('bad', t('ft.bytes_gone')) : '' ?></td>
    <td><?= e($TBL((string)$r['tbl'])) ?><?= $r['context'] ? '<br><span class="hint">' . e((string)$r['context']) . '</span>' : '' ?></td>
    <td class="num"><?= e(ft_size($r['size_bytes'] !== null ? (int)$r['size_bytes'] : null)) ?></td><td><?= e((string)($r['deleted_name'] ?? '—')) ?></td><td class="date"><?= e(fmt_datetime((string)$r['deleted_at'])) ?></td>
    <td class="date"><?= e($tab === 'open' ? fmt_date((string)$r['purge_after']) : fmt_datetime((string)($tab === 'restored' ? $r['restored_at'] : $r['purged_at']))) ?></td>
    <?php if ($tab === 'open'): ?><td class="num" style="white-space:nowrap;">
      <?php if (!$gone): ?><form method="post" class="inline" style="display:inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ft_restore"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--sm" type="submit">↩ <?= tt('ft.restore') ?></button></form><?php endif; ?>
      <form method="post" class="inline" style="display:inline" data-confirm="<?= e(t('ft.purge_confirm', ['name' => (string)$r['label']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ft.purge') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="ft_purge"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit"><?= tt('ft.purge') ?></button></form></td><?php endif; ?>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<div class="card"><h3 class="ac-h"><?= tt('ft.rules_h') ?></h3><ul class="hint" style="padding-left:18px;margin:0;"><li><?= e(t('ft.rule_1', ['d' => FT_DAYS])) ?></li><li><?= tt('ft.rule_2') ?></li><li><?= tt('ft.rule_3') ?></li><li><?= tt('ft.rule_4') ?></li></ul></div>
<?php layout_foot();

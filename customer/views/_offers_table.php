<?php
/** Partial: compact offers table (project tree, sub-project page).   GAS-CP-R17-009
 *  expects $otRows (offers with sub_code/original_name), optional $otShowSub (bool), $otNewUrl (string) */
$otShowSub = $otShowSub ?? true; $otNewUrl = $otNewUrl ?? null;
$otFiles = [];
if ($otRows) { $ids = array_values(array_filter(array_map(fn($o) => (int)($o['file_id'] ?? 0), $otRows))); if ($ids) foreach (all('SELECT id, original_name FROM sp_files WHERE id IN (' . implode(',', $ids) . ')') as $f) $otFiles[(int)$f['id']] = $f['original_name']; }
?>
<div class="ot-block">
  <div class="page-head" style="margin-bottom:6px;"><h3 class="ed-h" style="margin:0;"><?= tt('of.title') ?> <span><?= count($otRows) ?></span></h3>
    <?php if ($otNewUrl && is_staff()): ?><a class="btn btn--ghost btn--sm" href="<?= $otNewUrl ?>">+ <?= tt('of.new') ?></a><?php endif; ?></div>
  <?php if (!$otRows): ?><p class="hint" style="margin:0;"><?= tt('of.none_here') ?></p><?php else: ?>
  <div class="table-scroll"><table class="ot-table">
    <thead><tr><th><?= tt('of.no') ?></th><?php if ($otShowSub): ?><th><?= tt('sp.one') ?></th><?php endif; ?><th><?= tt('of.title_f') ?></th><th class="num"><?= tt('of.amount') ?></th><th><?= tt('th.status') ?></th><th><?= tt('of.aging') ?></th><th><?= tt('of.file') ?></th></tr></thead>
    <tbody><?php foreach ($otRows as $o): ?>
      <tr>
        <td class="ref"><a href="<?= url('offer', ['id' => $o['id']]) ?>"><b><?= e($o['offer_no']) ?></b> <span class="hint"><?= e($o['revision']) ?></span></a><br><span class="hint"><?= e(fmt_date($o['sent_at'])) ?></span></td>
        <?php if ($otShowSub): ?><td><?= $o['sub_code'] ? e($o['sub_code']) . ' <span class="hint">' . e($o['sub_name'] ?? '') . '</span>' : '<span class="hint">' . tt('of.whole_project') . '</span>' ?></td><?php endif; ?>
        <td><?= e($o['title']) ?></td>
        <td class="num"><?= e(of_money((float)$o['amount'], $o['currency'])) ?></td>
        <td><?= of_status_pill($o['status']) ?></td>
        <td><?= pill(of_age_tone($o), of_age_label($o)) ?></td>
        <td><?php if (!empty($o['file_id']) && isset($otFiles[(int)$o['file_id']])): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $o['file_id']]) ?>" target="_blank" title="<?= e($otFiles[(int)$o['file_id']]) ?>">⤓ <?= e(mb_strimwidth($otFiles[(int)$o['file_id']], 0, 28, '…')) ?></a><?php else: ?><span class="hint">—</span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

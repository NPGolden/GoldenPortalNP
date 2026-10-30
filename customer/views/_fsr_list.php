<?php
/** Shared table of field service reports.                     GAS-CP-R7-001 */
function fsr_status_pill(string $s): string {
    $m = ['draft' => 'mute', 'submitted' => 'gold', 'accepted' => 'green', 'disputed' => 'bad'];
    return pill($m[$s] ?? 'mute', t('fsr.st_' . $s));
}
function fsr_table(array $rows, bool $showProject = true, string $slug = 'reports', array $keep = [], string $param = 'page'): void {
    if (!$rows) { echo '<div class="empty">' . tt('fsr.none') . '</div>'; return; }
    [$rows, $pager] = page_list($rows, $slug, $keep, $param);
    ?>
    <div class="table-scroll"><table>
      <thead><tr>
        <th><?= tt('fsr.no') ?></th><th><?= tt('th.date') ?></th>
        <?php if ($showProject): ?><th><?= tt('pr.one') ?></th><?php endif; ?>
        <th><?= tt('th.vessel') ?></th><th><?= tt('fsr.engineer') ?></th><th><?= tt('fsr.hours') ?></th><th><?= tt('th.status') ?></th><th><?= tt('fsr.acceptance') ?></th><th></th>
      </tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td class="num"><a href="<?= url('fsr', ['id' => $r['id']]) ?>"><?= e($r['report_no']) ?></a><?= ($r['kind'] ?? 'internal') === 'external' ? '<br>' . pill('navy', t('fsr.external')) . (empty($r['converted_id']) && is_staff() ? ' ' . pill('gold', t('fsr.not_processed')) : '') : (!empty($r['source_report_id']) ? '<br><span class="hint">⇄ EXT</span>' : '') ?></td>
          <td class="date"><?= e(fmt_date($r['report_date'])) ?></td>
          <?php if ($showProject): ?><td><?= e($r['project_code'] ?: $r['project_name']) ?><?= $r['sub_code'] ? ' <span class="hint">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</span>' : '' ?></td><?php endif; ?>
          <td><?= e($r['vessel_name'] ?: ($r['vessel'] ?? '')) ?></td>
          <td><?= e($r['submitted_name'] ?: '—') ?></td>
          <td class="num"><?= e(fsr_fmt_hours((float) scalar('SELECT COALESCE(SUM(hours),0) FROM fsr_lines WHERE report_id = ?', [$r['id']]))) ?></td>
          <td><?= fsr_status_pill($r['status']) ?></td>
          <td class="date"><?= $r['accepted_at'] ? e($r['accepted_name'] . ' · ' . fmt_datetime($r['accepted_at'])) : ($r['status'] === 'submitted' ? '<span class="hint">' . tt('fsr.pending') . '</span>' : '—') ?></td>
          <td class="row-actions" style="white-space:nowrap;">
            <?php if ($r['pdf_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">PDF</a><?php elseif (!empty($r['ext_file_id'])): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['ext_file_id']]) ?>" target="_blank">⤓</a><?php endif; ?>
            <?php if ($r['docx_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['docx_file_id']]) ?>">Word</a><?php endif; ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('fsr', ['id' => $r['id']]) ?>"><?= tt('fsr.open') ?></a>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?= $pager ?>
    <?php
}

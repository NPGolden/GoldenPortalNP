<?php
/** Shared table of inspection reports (SF-GLD-020).            GAS-CP-R23-003 */
function isr_table(array $rows, bool $showProject = true, string $slug = 'reports', array $keep = [], string $param = 'ipage'): void {
    if (!$rows) { echo '<div class="empty">' . tt('isr.none') . '</div>'; return; }
    [$rows, $pager] = page_list($rows, $slug, $keep, $param);
    $lv = array_keys(isr_risk_levels());
    ?>
    <div class="table-scroll"><table>
      <thead><tr>
        <th><?= tt('fsr.no') ?></th><th><?= tt('th.date') ?></th>
        <?php if ($showProject): ?><th><?= tt('pr.one') ?></th><?php endif; ?>
        <th><?= tt('th.vessel') ?></th><th><?= tt('isr.subject') ?></th><th class="num"><?= tt('isr.systems') ?></th><th><?= tt('isr.max_risk') ?></th><th><?= tt('th.status') ?></th><th></th>
      </tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td class="num"><a href="<?= url('isr', ['id' => $r['id']]) ?>"><?= e($r['report_no']) ?></a><?= $r['version'] > 1 || $r['issued_at'] ? ' <span class="hint">R' . (int)$r['version'] . '</span>' : '' ?></td>
          <td class="date"><?= e(fmt_date($r['report_date'])) ?></td>
          <?php if ($showProject): ?><td><?= e($r['project_code'] ?: $r['project_name']) ?><?= $r['sub_code'] ? ' <span class="hint">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</span>' : '' ?></td><?php endif; ?>
          <td><?= e($r['vessel_name'] ?: ($r['vessel'] ?? '')) ?></td>
          <td><?= e(mb_substr((string)($r['subject'] ?? ''), 0, 60)) ?></td>
          <td class="num"><?= (int)$r['n_items'] ?></td>
          <td><?= $r['max_risk'] ? isr_risk_pill($lv[(int)$r['max_risk'] - 1] ?? 'none') : '—' ?></td>
          <td><?= isr_status_pill($r['status']) ?></td>
          <td class="row-actions" style="white-space:nowrap;">
            <?php if ($r['pdf_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">PDF</a><?php endif; ?>
            <?php if ($r['docx_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['docx_file_id']]) ?>">Word</a><?php endif; ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('isr', ['id' => $r['id']]) ?>"><?= tt('fsr.open') ?></a>
          </td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?= $pager ?>
    <?php
}

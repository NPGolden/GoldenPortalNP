<?php
/** Shared table of function checks / commissioning sheets.            GAS-CP-R77-001 */
function fc_table(array $rows, bool $showProject = true, string $slug = 'reports', array $keep = [], string $param = 'fpage'): void {
    if (!$rows) { echo '<div class="empty">' . tt('fc.none') . '</div>'; return; }
    $vg = $slug === 'reports' && vg_on('reports'); if ($vg) $rows = vg_sort($rows, 'vessel');
    [$rows, $pager] = page_list($rows, $slug, $keep, $param); $vgCounts = []; foreach ($rows as $r) { $vgCounts[(string)($r['vessel'] ?? '')] = ($vgCounts[(string)($r['vessel'] ?? '')] ?? 0) + 1; } $vgLast = null;
    ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('fsr.no') ?></th><th><?= tt('fc.form') ?></th><th><?= tt('th.date') ?></th><?php if ($showProject): ?><th><?= tt('pr.one') ?></th><?php endif; ?><th><?= tt('th.vessel') ?></th><th><?= tt('fc.engineer') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): $vgV = (string)($r['vessel'] ?? ''); if ($vg && $vgV !== $vgLast) { echo vg_row($vgV, $vgCounts[$vgV], 8); $vgLast = $vgV; } $tpl = fc_form((string)$r['form']); ?>
        <tr<?= $vg ? ' data-vg="' . e(vg_slug($vgV)) . '"' : '' ?>>
          <td class="num"><a href="<?= url('fc', ['id' => $r['id']]) ?>"><?= e($r['report_no']) ?></a><?= (int)$r['version'] > 1 ? ' <span class="hint">R' . (int)$r['version'] . '</span>' : '' ?></td>
          <td><?= e($tpl['short'] ?? $r['form']) ?> <span class="hint"><?= e($tpl['maker'] ?? '') ?></span></td>
          <td class="date"><?= e(fmt_date($r['report_date'])) ?></td>
          <?php if ($showProject): ?><td><?= e($r['project_code'] ?: $r['project_name']) ?><?= $r['sub_code'] ? ' <span class="hint">' . e($r['sub_code'] . ' ' . $r['sub_name']) . '</span>' : '' ?></td><?php endif; ?>
          <td><?= e($r['vessel'] ?? '') ?></td>
          <td><?= e($r['engineer_name'] ?? '') ?></td>
          <td><?= fc_status_pill($r['status']) ?></td>
          <td class="row-actions" style="white-space:nowrap;"><?php if ($r['pdf_file_id']): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">PDF</a> <?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('fc', ['id' => $r['id']]) ?>"><?= tt('fsr.open') ?></a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div><?= $pager ?>
    <?php
}

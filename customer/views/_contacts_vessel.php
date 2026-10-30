<?php
/** Partial: the contacts of one vessel, compact, with "e-mail all" — used by the vessel page, the project tree and the sub-project page (staff only).   GAS-CP-R17-002
 *  expects $cvVesselId (int), optional $cvCompact (bool) */
if (!function_exists('ct_has') || !ct_has() || !is_staff()) return;
$cvRows = ct_for_vessel((int)$cvVesselId);
$cvEmails = array_values(array_unique(array_filter(array_map(fn($r) => $r['email'], $cvRows))));
$cvCompact = $cvCompact ?? false;
?>
<div class="cv-block">
  <div class="page-head" style="margin-bottom:6px;"><h<?= $cvCompact ? 3 : 2 ?> style="margin:0;"><?= tt('ct.title') ?> <?= pill('navy', t('tk.internal')) ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($cvRows) ?></span></h<?= $cvCompact ? 3 : 2 ?>>
    <span class="btn-row"><?php if ($cvEmails): ?><a class="btn btn--ghost btn--sm" href="<?= e(function_exists('em_mailto') ? em_mailto($cvEmails, ['vessel' => (string)(scalar('SELECT name FROM vessels WHERE id = ?', [(int)$cvVesselId]) ?: ''), 'customer_id' => (int)(scalar('SELECT customer_id FROM vessels WHERE id = ?', [(int)$cvVesselId]) ?: 0)]) : 'mailto:' . implode(',', $cvEmails)) ?>">✉ <?= e(t('ct.email_all', ['n' => count($cvEmails)])) ?></a><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('contacts', ['vessel' => (int)$cvVesselId]) ?>"><?= tt('ct.title') ?> →</a><a class="btn btn--ghost btn--sm" href="<?= url('contact_edit', ['vessel' => (int)$cvVesselId]) ?>">+ <?= tt('ct.new') ?></a></span></div>
  <?php if (!$cvRows): ?><div class="empty"><?= tt('ct.none_vessel') ?></div><?php else: ?>
  <div class="table-scroll"><table class="cv-table">
    <thead><tr><th><?= tt('au.name') ?></th><th><?= tt('ct.role_on_vessel') ?></th><th><?= tt('co.one') ?></th><th><?= tt('ct.reach') ?></th><?php if (!$cvCompact): ?><th><?= tt('ct.channels') ?></th><th><?= tt('ct.birthday') ?></th><th><?= tt('ct.nameday') ?></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($cvRows as $c): ?><tr>
      <td><a href="<?= url('contact', ['id' => $c['id']]) ?>"><b><?= e($c['full_name']) ?></b></a> <?= $c['is_primary'] ? pill('gold', t('ct.primary')) : '' ?><?= $c['position'] ? '<br><span class="hint">' . e($c['position']) . '</span>' : '' ?></td>
      <td><?= e($c['vessel_role'] ?: '—') ?><br><?= ct_cat_pill($c['category']) ?></td>
      <td><?= e($c['company_name'] ?: '—') ?></td>
      <td class="ct-reach"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a><br>' : '' ?><?= $c['mobile'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $c['mobile'])) . '">' . e($c['mobile']) . '</a>' : '' ?><?= $c['mobile'] && $c['phone'] ? ' · ' : '' ?><?= $c['phone'] ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $c['phone'])) . '">' . e($c['phone']) . '</a>' : '' ?></td>
      <?php if (!$cvCompact): ?><td class="ct-sets"><?= co_has() ? ct_set_pills($c['channels'] ?? null, ct_channels(), 'mute') : '—' ?></td><td><?= e(ct_fmt_birthday($c['birthday'])) ?></td><td><?= e(ct_fmt_md($c['nameday_md'])) ?></td><?php endif; ?>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>

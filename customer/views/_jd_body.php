<?php /** The text of one issue of a job description ($jdC = jd_content / jd_current, $jdRevs = jd_revs).   GAS-CP-R97-121   (included by hr_jd.php, my_jd.php) */ ?>
<div class="card">
  <h2><?= e($jdC['title']) ?> <span class="hint" style="font-weight:400;"><?= e($jdC['code']) ?> · R<?= (int)$jdC['rev'] ?></span></h2>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('jd.discipline') ?></span><b><?= e($jdC['discipline'] ?: '—') ?></b></div><div class="kv"><span><?= tt('jd.level') ?></span><b><?= e(jd_level_label((string)$jdC['level'])) ?></b></div>
    <div class="kv"><span><?= tt('jd.reports_to') ?></span><b><?= e($jdC['reports_to'] ?: '—') ?></b></div><div class="kv"><span><?= tt('jd.f_rev') ?></span><b>R<?= (int)$jdC['rev'] ?><?= $jdC['rev_title'] !== '' ? ' — ' . e($jdC['rev_title']) : '' ?></b></div>
  </div>
  <?php if (trim((string)$jdC['purpose']) !== ''): ?><h3><?= tt('jd.s_purpose') ?></h3><p style="text-align:justify;"><?= nl2br(e((string)$jdC['purpose'])) ?></p><?php endif; ?>
  <?php foreach (JD_SECTIONS as $k_): if (empty($jdC[$k_])) continue; ?><h3><?= tt('jd.s_' . $k_) ?></h3><ul><?php foreach ($jdC[$k_] as $l_): ?><li><?= e($l_) ?></li><?php endforeach; ?></ul><?php endforeach; ?>
  <?php $ap_ = array_filter($jdC['approvals'] ?? [], fn($a) => !empty($a['at'])); if ($ap_): ?><p class="hint" style="margin-top:10px;"><?= tt('jd.f_approved') ?>: <?php foreach ($jdC['approvals'] as $s_ => $a_) if (!empty($a_['at'])) echo e(t('jd.step_' . $s_) . ' ' . $a_['name'] . ' (' . fmt_date(substr((string)$a_['at'], 0, 10)) . ')') . ' · '; ?></p><?php endif; ?>
  <?php if (!empty($jdRevs) && count($jdRevs) > 1): ?><details style="margin-top:8px;"><summary class="hint"><?= tt('jd.s_revs') ?> (<?= count($jdRevs) ?>)</summary><table class="tbl" data-nosort><tbody><?php foreach ($jdRevs as $r_): ?><tr><td class="mono">R<?= (int)$r_['rev'] ?></td><td><?= e(fmt_date(substr((string)$r_['approved_at'], 0, 10))) ?></td><td><?= e((string)$r_['title']) ?></td></tr><?php endforeach; ?></tbody></table></details><?php endif; ?>
</div>

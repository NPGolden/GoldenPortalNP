<?php /** Assessments the signed-in user has to answer, people to rate, results to read.   GAS-CP-R97-063   (included in my_leave and hr_requests) */
if (!function_exists('hrs_mine')) return; $hm = hrs_mine((int) (current_user()['id'] ?? 0)); if (!$hm['self'] && !$hm['rate'] && !$hm['results']) return; ?>
<div class="card" id="hrs-mine">
  <h2>🧭 <?= tt('hrs.mine_title') ?></h2>
  <?php foreach ($hm['self'] as $x): ?><p style="margin:0 0 8px;"><b><?= e($x['title']) ?></b> <span class="hint" style="display:inline;">· <?= e(t('hrs.f_until', ['d' => fmt_date($x['ends_on'])])) ?></span> <a class="btn btn--gold btn--sm" href="<?= url('hr_test_take', ['id' => $x['id']]) ?>"><?= tt($x['started'] ? 'hrs.continue' : 'hrs.answer') ?></a></p><?php endforeach; ?>
  <?php foreach ($hm['rate'] as $x): ?><p style="margin:0 0 8px;"><b><?= e($x['title']) ?></b> <span class="hint" style="display:inline;">· <?= e(t('hrs.mine_rate', ['n' => $x['n']])) ?> · <?= e(t('hrs.f_until', ['d' => fmt_date($x['ends_on'])])) ?></span> <a class="btn btn--gold btn--sm" href="<?= url('hr_test_take', ['id' => $x['id'], 'form' => 'mgr']) ?>"><?= tt('hrs.rate') ?></a></p><?php endforeach; ?>
  <?php foreach ($hm['results'] as $x): ?><p style="margin:0 0 8px;"><?= e($x['title']) ?> <span class="hint" style="display:inline;">· <?= e(fmt_date($x['ends_on'])) ?></span> <a class="btn btn--ghost btn--sm" href="<?= url('hr_test_result', ['id' => $x['id'], 'pid' => $x['pid']]) ?>"><?= tt('hrs.open_result') ?></a></p><?php endforeach; ?>
</div>

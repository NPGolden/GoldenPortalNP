<?php
/** HR › Job descriptions — the list (GAS-CP-R97-121).  ?p=hr_jds[&discipline=&status=&q=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('jd_has') || !jd_has()) { layout_head(t('jd.title'), 'hr_jds'); echo '<div class="flash flash--warn">' . tt('jd.need_db') . '</div>'; layout_foot(); return; }
$manage = jd_manage(); $isAppr = jd_is_gm() || jd_is_ceo() || (function_exists('hr_is_manager') && hr_is_manager());
if (!$manage && !$isAppr) { http_response_code(403); layout_head(t('jd.title'), 'hr_jds'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = ['discipline' => (int)($_GET['discipline'] ?? 0), 'status' => in_array($_GET['status'] ?? '', array_merge(JD_STATES, ['all']), true) ? $_GET['status'] : '', 'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80)];
$rows = jd_list($f); $depts = function_exists('hr_departments') ? hr_departments() : []; $miss = $manage ? jd_seed_missing() : 0;
$wait = array_values(array_filter(jd_list(['status' => 'review']), function ($j) { $s = jd_next_step($j); return $s !== null && jd_holds($j, $s); }));
layout_head(t('jd.title'), 'hr_jds');
?>
<div class="page-head"><div><h1>📄 <?= tt('jd.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('jd.lede') ?></p></div>
  <?php if ($manage): ?><div class="btn-row"><a class="btn btn--gold" href="<?= url('hr_jd', ['new' => 1]) ?>">＋ <?= tt('jd.new') ?></a></div><?php endif; ?></div>
<?php if ($wait): ?><div class="flash flash--warn"><b><?= e(t('jd.wait_me', ['n' => count($wait)])) ?></b> <?php foreach ($wait as $j): ?><a href="<?= url('hr_jd', ['id' => (int)$j['id']]) ?>"><?= e($j['code'] . ' ' . $j['title']) ?></a> · <?php endforeach; ?></div><?php endif; ?>
<?php if ($manage && $miss): ?>
<div class="card" style="border-left:4px solid var(--cp-gold,#C9A227);"><h2><?= tt('jd.seed_title') ?></h2><p class="hint"><?= e(t('jd.seed_hint', ['n' => $miss])) ?></p>
  <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_seed"><button class="btn btn--gold btn--sm" type="submit"><?= e(t('jd.seed_btn', ['n' => $miss])) ?></button></form></div>
<?php endif; ?>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr auto; margin-bottom:10px;"><input type="hidden" name="p" value="hr_jds">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('jd.search') ?>">
    <select name="discipline"><option value="0"><?= tt('jd.all_disc') ?></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $f['discipline'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <select name="status" data-nosearch><option value=""><?= tt('jd.f_current') ?></option><?php foreach (JD_STATES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= tt('jd.st_' . $s) ?></option><?php endforeach; ?><option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>><?= tt('jd.f_all') ?></option></select>
    <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.filter') ?></button>
  </form>
  <h2><?= tt('jd.title') ?> <span class="count"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><div class="empty"><?= tt('jd.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('jd.code') ?></th><th><?= tt('jd.job_title') ?></th><th><?= tt('jd.discipline') ?></th><th><?= tt('jd.level') ?></th><th><?= tt('jd.f_rev') ?></th><th><?= tt('th.status') ?></th><th><?= tt('jd.waiting_for') ?></th><th class="num"><?= tt('jd.people') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $j): $ns = jd_next_step($j); $st = $ns ? jd_steps($j)[$ns] : null; ?>
    <tr><td class="mono"><a href="<?= url('hr_jd', ['id' => (int)$j['id']]) ?>"><b><?= e($j['code']) ?></b></a></td>
      <td><a href="<?= url('hr_jd', ['id' => (int)$j['id']]) ?>"><?= e($j['title']) ?></a></td><td class="hint"><?= e($j['discipline'] ?? '—') ?></td><td class="hint"><?= e(jd_level_label((string)$j['level'])) ?></td>
      <td class="mono"><?= e(jd_rev($j)) ?><?= $j['status'] !== 'approved' && $j['issued_rev'] !== null ? '<br><small class="hint">' . e(t('jd.in_force', ['r' => 'R' . (int)$j['issued_rev']])) . '</small>' : '' ?></td>
      <td><?= jd_state_pill((string)$j['status']) ?></td><td class="hint"><?= $st ? e($st['label']) : '' ?></td><td class="num"><?= (int)$j['people_n'] ?></td>
      <td class="row-actions"><?php if ($j['status'] === 'approved' || $j['issued_rev'] !== null): ?><a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="<?= url('hr_jd', ['id' => (int)$j['id'], 'format' => 'pdf']) ?>">⤓ PDF</a><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php layout_foot(); ?>

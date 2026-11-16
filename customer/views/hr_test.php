<?php
/** HR › Assessments — one campaign: questions as imported, people, period, progress, results.   GAS-CP-R97-063   ?p=hr_test&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int) ($_GET['id'] ?? 0); $c = function_exists('hrs_can') && hrs_can() ? hrs_campaign($id) : null;
if (!$c) { http_response_code($id && function_exists('hrs_can') && hrs_can() ? 404 : 403); layout_head(t('hrs.title'), 'hr_tests'); echo '<h1>' . tt(function_exists('hrs_can') && hrs_can() ? 'err.notfound' : 'err.noaccess') . '</h1>'; layout_foot(); return; }
$F = $c['F']; $set = $F['set']; $tg = hrs_targets($id); $st = $c['status']; $open = in_array($st, ['draft', 'scheduled'], true);
$warn = $_SESSION['hrs_warn'][$id] ?? []; unset($_SESSION['hrs_warn'][$id]);
$inIds = array_map(fn($t) => (int) $t['person_id'], $tg); $people = $st !== 'closed' ? hr_people(true) : [];
$nSelf = 0; $nMgr = 0; $perDim = []; foreach ($F['q'] as $q) { if ($q['f'] === 'S') { $nSelf++; if ($q['d'] !== '') $perDim[$q['d']]['s'] = ($perDim[$q['d']]['s'] ?? 0) + 1; } else { $nMgr++; $perDim[$q['d']]['m'] = ($perDim[$q['d']]['m'] ?? 0) + 1; } }
$done = count(array_filter($tg, fn($t) => $t['self_status'] === 'done')); $mdone = count(array_filter($tg, fn($t) => $t['mgr_status'] === 'done'));
$staff = $st !== 'closed' && !empty($set['manager_form']) ? all("SELECT id, full_name FROM customer_users WHERE is_active = 1 AND role <> 'customer' ORDER BY full_name") : [];
$stLabel = fn(string $s) => pill(['done' => 'good', 'started' => 'gold', 'missed' => 'warn'][$s] ?? 'mute', t('hrs.ts_' . $s));
layout_head(t('hrs.title') . ' · ' . hrs_tx($F['title']), 'hr_tests');
?>
<p class="eyebrow"><a href="<?= url('hr_tests') ?>"><?= tt('hrs.title') ?></a> › <?= e(hrs_tx($F['title'])) ?></p>
<div class="page-head">
  <div><h1>🧭 <?= e(hrs_tx($F['title'])) ?></h1><p class="lede" style="margin-bottom:0;"><?= hrs_status_pill($st) ?> <?= e($c['family_code']) ?> v<?= (int) $c['family_version'] ?><?= $c['starts_on'] ? ' · ' . e(fmt_date($c['starts_on'])) . ' – ' . e(fmt_date($c['ends_on'])) : '' ?> · <?= e(t('hrs.q_counts', ['s' => $nSelf, 'm' => $nMgr])) ?></p></div>
  <div class="btn-row">
    <?php if ($st === 'running'): ?>
      <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hrs_remind"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost" type="submit">🔔 <?= tt('hrs.remind') ?></button></form>
      <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="inline" data-lock data-confirm="<?= e(t('hrs.close_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hrs_close"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold" type="submit"><?= tt('hrs.close_now') ?></button></form>
    <?php elseif ($st === 'scheduled'): ?>
      <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hrs_back"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost" type="submit"><?= tt('hrs.back_draft') ?></button></form>
    <?php elseif ($st === 'draft'): ?>
      <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="inline" data-lock data-confirm="<?= e(t('hrs.delete_confirm')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hrs_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--danger-ghost" type="submit"><?= tt('btn.delete') ?></button></form>
    <?php endif; ?>
    <a class="btn btn--ghost" href="<?= url('hr_tests') ?>">‹ <?= tt('hrs.campaigns') ?></a>
  </div>
</div>
<?php if ($warn): ?><div class="flash flash--warn"><b><?= tt('hrs.imp_warn') ?></b><ul style="margin:4px 0 0;padding-left:18px;"><?php foreach ($warn as $m): ?><li><?= e($m) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<?php if ($st === 'closed'): $avg = hrs_group_avgs($c); $min = max(3, (int) ($set['min_group'] ?? 5)); ?>
<div class="card">
  <h2>📊 <?= tt('hrs.results') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('hrs.res_counts', ['a' => $done, 'n' => count($tg), 'm' => $mdone])) ?></span></h2>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hr.person') ?></th><?php foreach ($F['dims'] as $d => $dm): ?><th style="white-space:normal;min-width:96px;vertical-align:bottom;"><?= e(hrs_tx($dm['name'])) ?></th><?php endforeach; ?><th class="num"><?= tt('hrs.overall') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($tg as $t): $sc = json_decode((string) $t['scores'], true) ?: []; ?><tr>
      <td><b><?= e($t['name_en']) ?></b><br><span class="hint"><?= e((string) ($t['department'] ?? '')) ?></span></td>
      <?php foreach ($F['dims'] as $d => $_): $x = $sc[$d] ?? null; ?><td><?php if (!$x || $x['comb'] === null): ?><span class="hint">—</span><?php else: ?><?= hrs_band_pill($F, (int) $x['comb']) ?><?php if ($x['gap'] !== null && abs((int) $x['gap']) > 20): ?> <span class="hint" title="<?= e(t('hrs.gap_hint')) ?>">Δ <?= ((int) $x['gap'] > 0 ? '+' : '') . (int) $x['gap'] ?></span><?php endif; endif; ?></td><?php endforeach; ?>
      <td class="num"><?= $t['overall'] !== null ? '<b>' . e(rtrim(rtrim(number_format((float) $t['overall'], 1, '.', ''), '0'), '.')) . '</b>' : '<span class="hint">—</span>' ?><?= $t['self_status'] !== 'done' ? '<br>' . pill('warn', t('hrs.ts_missed')) : (!$t['valid'] ? '<br>' . pill('warn', '⚠ ' . t('hrs.low_conf')) : '') ?></td>
      <td><?php if ($sc): ?><a class="btn btn--ghost btn--sm" href="<?= url('hr_test_result', ['id' => $id, 'pid' => (int) $t['person_id']]) ?>"><?= tt('hrs.open_result') ?></a><?php endif; ?></td>
    </tr><?php endforeach; ?>
    <?php if ($avg['all']): ?><tr><td><b><?= tt('hrs.avg_all') ?></b></td><?php foreach ($F['dims'] as $d => $_): $x = $avg['all'][$d] ?? null; ?><td><?= $x ? '<b>' . (int) $x['avg'] . '</b> <span class="hint">(' . (int) $x['n'] . ')</span>' : '<span class="hint">—</span>' ?></td><?php endforeach; ?><td></td><td></td></tr><?php endif; ?>
    <?php foreach ($avg['dept'] as $dn => $row): ?><tr><td><?= e($dn) ?></td><?php foreach ($F['dims'] as $d => $_): $x = $row[$d] ?? null; ?><td><?= $x ? (int) $x['avg'] . ' <span class="hint">(' . (int) $x['n'] . ')</span>' : '<span class="hint">—</span>' ?></td><?php endforeach; ?><td></td><td></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint"><?= e(t('hrs.res_hint', ['n' => $min])) ?></p>
</div>
<?php endif; ?>

<?php if ($st === 'running'): ?>
<div class="card">
  <h2><?= tt('hrs.progress') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('hrs.res_counts', ['a' => $done, 'n' => count($tg), 'm' => $mdone])) ?></span></h2>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('hrs.self_form') ?></th><?php if (!empty($set['manager_form'])): ?><th><?= tt('hrs.rater') ?></th><th><?= tt('hrs.mgr_form') ?></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($tg as $t): ?><tr>
      <td><b><?= e($t['name_en']) ?></b><br><span class="hint"><?= e((string) ($t['department'] ?? '')) ?></span></td>
      <td><?= $stLabel($t['self_status']) ?><?= $t['self_submitted_at'] ? ' <span class="hint">' . e(fmt_datetime($t['self_submitted_at'])) . '</span>' : '' ?></td>
      <?php if (!empty($set['manager_form'])): ?>
      <td><?php if ($t['mgr_status'] === 'done'): ?><?= e((string) $t['rater_name']) ?><?php else: ?><form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hrs_rater"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pid" value="<?= (int) $t['person_id'] ?>">
        <select name="rater" style="max-width:220px;"><option value="0">— <?= tt('hrs.no_rater') ?> —</option><?php foreach ($staff as $s): if ((int) $s['id'] === (int) $t['user_id']) continue; ?><option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === (int) $t['rater_user_id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select>
        <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form><?php endif; ?></td>
      <td><?= $t['rater_user_id'] ? $stLabel($t['mgr_status']) : '<span class="hint">—</span>' ?></td>
      <?php endif; ?>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= tt('hrs.progress_hint') ?></p>
  <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" class="btn-row" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hrs_end"><input type="hidden" name="id" value="<?= $id ?>">
    <label class="hint" style="margin:0;"><?= tt('hrs.ends_on') ?></label><input type="date" name="ends_on" value="<?= e((string) $c['ends_on']) ?>" min="<?= date('Y-m-d') ?>" style="max-width:170px;" required><button class="btn btn--ghost btn--sm" type="submit"><?= tt('hrs.move_end') ?></button></form>
</div>
<?php endif; ?>

<?php if ($st !== 'closed'): $byDept = []; foreach ($people as $p) $byDept[(string) ($p['department'] ?? '')][] = $p; ksort($byDept); ?>
<div class="card" id="hrs-people">
  <h2>👥 <?= tt($open ? 'hrs.setup' : 'hrs.add_people') ?> <span class="hint" style="display:inline;margin-left:8px;" id="hrs-n"><?= count($inIds) ?></span></h2>
  <form method="post" action="<?= url('hr_test', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($open): ?><div class="grid grid-3">
      <div class="field"><label><?= tt('hrs.starts_on') ?></label><input type="date" name="starts_on" value="<?= e((string) ($c['starts_on'] ?: date('Y-m-d'))) ?>" required></div>
      <div class="field"><label><?= tt('hrs.ends_on') ?></label><input type="date" name="ends_on" value="<?= e((string) ($c['ends_on'] ?: date('Y-m-d', strtotime('+14 days')))) ?>" min="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label>&nbsp;</label><p class="hint" style="margin:0;"><?= tt('hrs.dates_hint') ?></p></div>
    </div><?php endif; ?>
    <?php foreach ($byDept as $dn => $pp): $k = 'd' . substr(md5($dn), 0, 8); ?>
    <fieldset style="border:1px solid rgba(31,58,95,.18);border-radius:8px;padding:8px 12px;margin:0 0 10px;">
      <legend style="padding:0 6px;"><b><?= e($dn !== '' ? $dn : t('hrs.no_dept')) ?></b> <button class="btn btn--ghost btn--sm" type="button" data-hrs-all="<?= $k ?>"><?= tt('hrs.all') ?></button> <button class="btn btn--ghost btn--sm" type="button" data-hrs-none="<?= $k ?>"><?= tt('hrs.none_btn') ?></button></legend>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:4px 14px;">
      <?php foreach ($pp as $p): $in = in_array((int) $p['id'], $inIds, true); $acc = !empty($p['user_id']); $r = $acc ? hrs_rater_for($p) : null; ?>
        <label style="display:flex;gap:6px;align-items:flex-start;font-weight:400;<?= $acc ? '' : 'opacity:.55;' ?>"><input type="checkbox" name="pids[]" value="<?= (int) $p['id'] ?>" data-hrs="<?= $k ?>"<?= $in ? ' checked' : '' ?><?= $acc ? '' : ' disabled' ?> style="width:16px;height:16px;margin-top:2px;">
          <span><?= e($p['name_en']) ?><?php if (!$acc): ?><br><span class="hint"><?= tt('hrs.no_account') ?></span><?php elseif (!empty($set['manager_form']) && !$r): ?><br><span class="hint">⚠ <?= tt('hrs.no_rater') ?></span><?php endif; ?></span></label>
      <?php endforeach; ?>
      </div>
    </fieldset>
    <?php endforeach; ?>
    <?php if (!$people): ?><div class="empty"><?= tt('hrs.no_people') ?></div><?php endif; ?>
    <p class="hint"><?= tt(!empty($set['manager_form']) ? 'hrs.people_hint_mgr' : 'hrs.people_hint') ?></p>
    <div class="btn-row">
      <?php if ($open): ?><button class="btn btn--gold" type="submit" name="action" value="hrs_start"><?= tt($st === 'scheduled' ? 'hrs.save_schedule' : 'hrs.start') ?></button><?php endif; ?>
      <button class="btn<?= $open ? ' btn--ghost' : ' btn--gold' ?>" type="submit" name="action" value="hrs_people"><?= tt($open ? 'hrs.save_people' : 'hrs.add_people') ?></button>
    </div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var box=document.getElementById('hrs-people');if(!box)return;function n(){var e=document.getElementById('hrs-n');if(e)e.textContent=box.querySelectorAll('input[name="pids[]"]:checked').length;}
box.addEventListener('click',function(ev){var b=ev.target.closest('[data-hrs-all],[data-hrs-none]');if(!b)return;var on=b.hasAttribute('data-hrs-all'),k=b.getAttribute(on?'data-hrs-all':'data-hrs-none');box.querySelectorAll('input[data-hrs="'+k+'"]:not(:disabled)').forEach(function(c){c.checked=on;});n();});
box.addEventListener('change',n);})();</script>
<?php endif; ?>

<div class="cols" style="grid-template-columns: 1fr 1fr;">
<div class="card">
  <h2>⚙ <?= tt('hrs.settings') ?></h2>
  <table class="tbl"><tbody>
    <tr><td><?= tt('hrs.family') ?></td><td><b><?= e($c['family_code']) ?></b> v<?= (int) $c['family_version'] ?></td></tr>
    <tr><td><?= tt('hrs.set_mgr') ?></td><td><?= !empty($set['manager_form']) ? e(t('hrs.set_mgr_yes', ['s' => $set['self_weight'], 'm' => $set['manager_weight']])) : tt('hrs.set_mgr_no') ?></td></tr>
    <tr><td><?= tt('hrs.set_sees') ?></td><td><?= tt(!empty($set['employee_sees_result']) ? 'hrs.set_sees_yes' : 'hrs.set_sees_no') ?></td></tr>
    <tr><td><?= tt('hrs.set_valid') ?></td><td><?= e(t('hrs.set_valid_v', ['f' => $set['fast_seconds'], 'p' => $set['pair_tolerance']])) ?></td></tr>
    <tr><td><?= tt('hrs.set_group') ?></td><td><?= e(t('hrs.set_group_v', ['n' => $set['min_group']])) ?></td></tr>
    <tr><td><?= tt('hrs.set_time') ?></td><td><?= (int) $set['time_limit_min'] ? e(t('hrs.set_time_v', ['n' => (int) $set['time_limit_min']])) : tt('hrs.set_time_none') ?></td></tr>
    <tr><td><?= tt('hrs.set_bands') ?></td><td><?php foreach ($F['bands'] as $i => $b): ?><?= $i ? ' · ' : '' ?><?= (int) $b['a'] ?>–<?= (int) $b['b'] ?> <?= e(hrs_tx($b['l'])) ?><?php endforeach; ?></td></tr>
    <tr><td><?= tt('hrs.set_file') ?></td><td><?= e((string) $c['file_name']) ?> <span class="hint">· <?= e((string) $c['created_name']) ?> · <?= e(fmt_datetime($c['created_at'])) ?></span></td></tr>
  </tbody></table>
  <p class="hint"><?= tt('hrs.settings_hint') ?></p>
</div>
<div class="card">
  <h2>🧩 <?= tt('hrs.dimensions') ?></h2>
  <table class="tbl"><thead><tr><th><?= tt('hrs.dimension') ?></th><th class="num"><?= tt('hrs.self_form') ?></th><th class="num"><?= tt('hrs.mgr_form') ?></th></tr></thead><tbody>
    <?php foreach ($F['dims'] as $d => $dm): ?><tr><td><b><?= e(hrs_tx($dm['name'])) ?></b> <span class="hint"><?= e($d) ?></span><?= $dm['desc'] !== '' ? '<br><span class="hint">' . e($dm['desc']) . '</span>' : '' ?></td><td class="num"><?= (int) ($perDim[$d]['s'] ?? 0) ?></td><td class="num"><?= (int) ($perDim[$d]['m'] ?? 0) ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="hint"><?= tt('hrs.dims_hint') ?></p>
</div>
</div>

<div class="card">
  <details><summary><b>📄 <?= e(t('hrs.q_preview', ['n' => count($F['q'])])) ?></b></summary>
  <div class="table-scroll" style="margin-top:8px;"><table class="tbl">
    <thead><tr><th class="num">#</th><th><?= tt('hrs.form') ?></th><th><?= tt('hrs.dimension') ?></th><th><?= tt('hrs.q_type') ?></th><th><?= tt('hrs.question') ?></th></tr></thead>
    <tbody><?php foreach ($F['q'] as $no => $q): ?><tr>
      <td class="num"><?= (int) $no ?></td><td><?= tt($q['f'] === 'S' ? 'hrs.self_form' : 'hrs.mgr_form') ?></td><td><?= e($q['d']) ?></td>
      <td><?= e(t('hrs.qt_' . strtolower($q['t']))) ?><?= $q['r'] ? ' <span class="hint">↺</span>' : '' ?><?= $q['p'] ? ' <span class="hint">= #' . (int) $q['p'] . '</span>' : '' ?></td>
      <td><?= e(hrs_tx($q['tx'])) ?><?php foreach ($q['o'] as $l => $o): ?><br><span class="hint"><?= e($l) ?>) <?= e(hrs_tx($o['tx'])) ?> — <?= $q['t'] === 'CHOICE' ? e($o['d']) : e(rtrim(rtrim(number_format((float) $o['pt'], 2, '.', ''), '0'), '.')) ?></span><?php endforeach; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  </details>
</div>
<?php layout_foot(); ?>

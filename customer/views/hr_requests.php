<?php
/** Work › HR requests — approval queue for managers (their people) and HR (everyone), plus HR-entered requests (GAS-CP-R54-001). */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr2_has') || !hr2_has() || !(hr_can() || hr_is_manager())) { http_response_code(403); layout_head(t('hr.requests'), 'hr_requests'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$isHr = hr_can(); $year = (int)($_GET['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$st = (string)($_GET['st'] ?? 'open'); $me = (int)$u['id'];
$pid = $isHr ? (int)($_GET['pid'] ?? 0) : 0; $pp = $pid ? hr_person($pid) : null; if ($pp) { $st = $st === 'open' && !isset($_GET['st']) ? 'all' : $st; if (isset($_GET['year']) && (int)$_GET['year'] === 0) $year = 0; }   // R54-005: per-person history for HR
$fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$all = $isHr ? hr_requests_for('hr', $year) : hr_requests_for('manager', $year); if ($pp) $all = array_values(array_filter($all, fn($r) => (int)$r['person_id'] === $pid));
$open = array_filter($all, fn($r) => in_array($r['status'], ['pending_mgr', 'pending_hr'], true));
$rows = $st === 'open' ? $open : ($st === 'all' ? $all : array_filter($all, fn($r) => $r['status'] === $st));
$people = $isHr ? hr_people() : [];
layout_head(t('hr.requests'), 'hr_requests');
?>
<div class="page-head"><div><h1><?= $pp ? '📜 ' . e($pp['name_en']) . ' — ' . tt('hr.history') : '✅ ' . tt('hr.requests') ?></h1><p class="lede" style="margin-bottom:0;"><?= $pp ? tt('hr.history_lede') : ($isHr ? tt('hr.requests_lede_hr') : tt('hr.requests_lede_mgr')) ?></p></div>
  <form method="get" action="index.php" class="inline" style="display:flex;gap:6px;"><input type="hidden" name="p" value="hr_requests"><?php if ($isHr): ?><select name="pid" data-js-submit="1"><option value="">— <?= tt('hr.all_people') ?> —</option><?php foreach ($people as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $pid === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['name_en']) ?></option><?php endforeach; ?></select><?php endif; ?><select name="year" data-nosearch data-js-submit="1"><?php if ($pp): ?><option value="0"<?= $year === 0 ? ' selected' : '' ?>><?= tt('hr.all_years') ?></option><?php endif; foreach (array_unique(array_merge(hr_years(), $year ? [$year] : [])) as $yy): ?><option value="<?= $yy ?>"<?= $yy === $year ? ' selected' : '' ?>><?= $yy ?></option><?php endforeach; ?></select>
  <select name="st" data-nosearch data-js-submit="1"><?php foreach (['open' => t('hr.f_open') . ' (' . count($open) . ')', 'approved' => t('hr.s_approved'), 'rejected' => t('hr.s_rejected'), 'cancelled' => t('hr.s_cancelled'), 'all' => t('hr.f_all')] as $k => $lbl): ?><option value="<?= $k ?>"<?= $k === $st ? ' selected' : '' ?>><?= e($lbl) ?></option><?php endforeach; ?></select></form></div>

<?php if ($pp): $by = $year ?: (int) date('Y'); $bal = hr_balances($pp, $by); ?>
<div class="card">
  <div class="card-head"><h2><?= tt('hr.balances') ?> <?= $by ?></h2><div><a class="btn btn--ghost btn--sm" href="<?= url('admin_hr', ['tab' => 'people', 'pid' => $pid]) ?>#hr-person">👤 <?= tt('hr.person_edit') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('planning', ['hy' => $by, 'hu' => (int)($pp['user_id'] ?? 0)]) ?>#hr-report">🗓 <?= tt('pl.title') ?></a> <button class="btn btn--ghost btn--sm" type="button" data-js-print="1">⎙ <?= tt('rp.print') ?></button></div></div>
  <div class="kpi kpi--4"><?php $k = 0; foreach ($bal as $b): if (!$b['counts'] || ($b['total'] <= 0 && $b['taken'] <= 0 && !$b['via_annual'])) continue; if (++$k > 8) break; $un = $b['unit'] === 'hour' ? ' h' : ''; ?><span class="stat<?= $b['available'] !== null && $b['available'] < 0 ? ' red' : '' ?>"><b><?= $b['available'] === null ? '—' : e($fh($b['available'])) . $un ?></b><span class="s"><?= e($b['type']['code']) ?> · <?= tt('hr.available') ?> (<?= e($fh($b['taken'] + $b['opening'])) . $un ?> <?= tt('hr.taken') ?>)</span></span><?php endforeach; ?></div>
  <p class="hint" style="margin:8px 0 0;"><?= e(t('hr.history_summary', ['n' => count($all), 'a' => count(array_filter($all, fn($r) => $r['status'] === 'approved')), 'r' => count(array_filter($all, fn($r) => $r['status'] === 'rejected'))])) ?><?= $pp['hire_date'] ? ' · ' . tt('hr.hire_date') . ' ' . e(fmt_date($pp['hire_date'])) : '' ?><?= $pp['department'] ? ' · ' . e($pp['department']) : '' ?></p>
</div>
<?php endif; ?>
<div class="card">
  <?php if (!$rows): ?><p class="hint"><?= tt('hr.no_requests2') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th class="num"><?= tt('hr.days') ?> / h</th><th><?= tt('hr.reason') ?></th><th class="num"><?= tt('hr.available') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.decision') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $open1 = in_array($r['status'], ['pending_mgr', 'pending_hr'], true);
    $mine = $isHr ? $open1 : $r['status'] === 'pending_mgr';   // HR may decide at both stages (a manager's stage is then recorded under HR's name)
    $p = hr_person((int)$r['person_id']); $b = $p ? hr_balance($p, (int)$r['type_id'], (int) substr($r['date_from'], 0, 4)) : null; ?>
  <tr><td><b><?= $isHr && function_exists('hr3_has') && hr3_has() ? '<a href="' . url('hr_person', ['id' => (int)$r['person_id']]) . '#hr-req" title="' . tt('hr.card') . '">🪪 ' . e($r['name_en']) . '</a>' : e($r['name_en']) ?></b><?= $r['name_el'] ? '<br><span class="hint">' . e($r['name_el']) . '</span>' : '' ?></td>
    <td><b><?= e($r['code']) ?></b> <?= e(hr_type((int)$r['type_id'])['name'] ?? $r['type_name']) ?><?= $r['doc_path'] ? ' <a href="' . url('hr_doc', ['id' => (int)$r['id']]) . '" target="_blank" title="' . e($r['doc_name']) . '">📎</a>' : '' ?></td>
    <td><?= e(fmt_date($r['date_from'])) ?><?= $r['time_from'] ? ' <span class="hint">' . e(substr($r['time_from'], 0, 5)) . '</span>' : '' ?></td><td><?= e(fmt_date($r['date_to'])) ?><?= $r['time_to'] ? ' <span class="hint">' . e(substr($r['time_to'], 0, 5)) . '</span>' : '' ?></td>
    <td class="num"><?= $r['unit'] === 'hour' ? e($fh($r['hours'])) . ' h' : e($fh($r['days'])) ?></td><td><?= e($r['reason'] ?? '') ?></td>
    <td class="num<?= $b && $b['available'] !== null && $b['available'] < 0 ? ' bad' : '' ?>"><?= $b && $b['available'] !== null ? e($fh($b['available'])) . ($b['unit'] === 'hour' ? ' h' : '') : '—' ?></td>
    <td><?= hr_status_pill($r['status']) ?><?= isset($r['counted']) && !$r['counted'] ? ' ' . pill('mute', t('hr.history_only_s')) : '' ?><?= $r['efka_note'] ? '<br><span class="hint">ΕΦΚΑ: ' . e($r['efka_note']) . '</span>' : '' ?></td>
    <td><?php if ($open1 && $mine): ?>
      <form method="post" action="<?= url('hr_requests', ['year' => $year, 'st' => $st]) ?>" data-lock style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_decide"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <input name="note" maxlength="300" placeholder="<?= tt('hr.note_ph') ?>" style="width:150px;"><?php if ($isHr && $r['status'] === 'pending_hr'): ?><input name="efka" maxlength="120" placeholder="<?= tt('hr.efka_ph') ?>" style="width:120px;"><?php endif; ?>
        <button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve">✔ <?= tt('hr.approve') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" data-js-confirm="<?= tt('hr.reject_confirm') ?>">✖ <?= tt('hr.reject') ?></button></form>
      <?php else: ?><span class="hint"><?= $r['manager_at'] ? e($r['manager_name'] ?? '') . ' ' . e(fmt_date($r['manager_at'])) . ($r['manager_note'] ? ' — ' . e($r['manager_note']) : '') . '<br>' : '' ?><?= $r['hr_at'] ? 'HR ' . e($r['hr_name'] ?? '') . ' ' . e(fmt_date($r['hr_at'])) . ($r['hr_note'] ? ' — ' . e($r['hr_note']) : '') : '' ?></span><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<?php if ($isHr): ?>
<div class="card" id="req-new">
  <h2>+ <?= tt('hr.new_request_for') ?></h2>
  <p class="hint"><?= tt('hr.new_request_for_hint') ?></p>
  <form method="post" action="<?= url('hr_requests', ['year' => $year]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_new">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('ll.who') ?></label><select name="person_id" required><option value=""></option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e($p['name_en']) ?><?= $p['user_id'] ? '' : ' (' . t('hr.no_account') . ')' ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.type') ?></label><select name="type_id" id="hr_type" data-nosearch><?php foreach (hr_types() as $tid => $t): ?><option value="<?= $tid ?>" data-unit="<?= $t['unit'] ?>"><?= e($t['code'] . ' · ' . $t['name']) ?><?= $t['unit'] === 'hour' ? ' (h)' : '' ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="date_from" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field hr-day"><label><?= tt('pl.to') ?></label><input type="date" name="date_to"></div>
      <div class="field hr-hour"><label><?= tt('hr.hours') ?></label><input name="hours" inputmode="decimal" placeholder="2"></div>
      <div class="field hr-hour"><label><?= tt('hr.time') ?></label><div style="display:flex;gap:6px;"><input type="time" name="time_from"><input type="time" name="time_to"></div></div>
      <div class="field"><label><?= tt('hr.reason') ?></label><input name="reason" maxlength="300"></div>
      <div class="field"><label><?= tt('hr.document') ?></label><input type="file" name="doc" accept=".pdf,image/*"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.send_request') ?></button></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('hr_type');if(!s)return;var f=s.closest('form');function ui(){var o=s.options[s.selectedIndex],h=o&&o.dataset.unit==='hour';f.querySelectorAll('.hr-day').forEach(function(x){x.style.display=h?'none':'';});f.querySelectorAll('.hr-hour').forEach(function(x){x.style.display=h?'':'none';});}s.addEventListener('change',ui);ui();})();</script>
<?php endif; ?>
<?php if (function_exists('hr_tr_has') && hr_tr_has() && !$pp): $trq = hr_training_queue(); $team = $isHr ? [] : hr_team_of($me); ?>
<div class="card" id="hr-tr-queue">
  <h2>🎓 <?= tt('hr.tr_queue') ?> <?= $trq ? pill('gold', (string) count($trq)) : '' ?></h2>
  <p class="hint"><?= tt('hr.tr_queue_hint') ?></p>
  <?php if (!$trq): ?><p class="hint">—</p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('hr.tr_title') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.notes') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($trq as $m): ?><tr><td><b><?= e($m['name_en']) ?></b><br><span class="hint"><?= e(fmt_datetime($m['applied_at'])) ?></span></td><td><?= $isHr ? '<a href="' . url('hr_training', ['id' => (int)$m['training_id']]) . '">' . e($m['title']) . '</a>' : e($m['title']) ?> <?= hr_tr_kind_pill($m['kind']) ?></td><td class="hint"><?= $m['date_from'] ? e(fmt_date($m['date_from'])) : tt('hr.tr_tba') ?></td><td class="hint"><?= e((string)$m['note']) ?></td>
    <td class="row-actions"><form method="post" action="<?= url('hr_requests') ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_trm_save"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="back" value="queue"><input type="hidden" name="note" value="<?= e((string)$m['note']) ?>"><button class="btn btn--gold btn--sm" type="submit" name="status" value="approved">✓ <?= tt('hr.approve') ?></button> <button class="btn btn--ghost btn--sm" type="submit" name="status" value="rejected">✗ <?= tt('hr.reject') ?></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($team): ?>
  <h3 style="margin-top:14px;">📈 <?= tt('hr.ev_team') ?></h3>
  <p class="hint"><?= tt('hr.ev_team_hint') ?></p>
  <ul style="columns:2;"><?php foreach ($team as $tp): $last = hr_evaluations((int)$tp['id'])[0] ?? null; ?><li><a href="<?= url('hr_eval', ['pid' => (int)$tp['id']]) ?>"><?= e($tp['name_en']) ?></a> <span class="hint">· <?= $last ? (int)$last['year'] . ' · ' . e((string)$last['overall']) . ' · ' . e(t('hr.es_' . $last['status'])) : tt('hr.ev_none') ?></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

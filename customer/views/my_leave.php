<?php
/** Work › My leave — balances, new request, my requests (GAS-CP-R54-001). Every Golden A/S employee with a portal account. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr2_has') || !hr2_has()) { layout_head(t('hr.my_leave'), 'my_leave'); echo '<div class="flash flash--warn">' . tt('hr.need_r54') . '</div>'; layout_foot(); return; }
$p = hr_person_of_user((int)$u['id']); $year = (int)($_GET['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
layout_head(t('hr.my_leave'), 'my_leave');
?>
<div class="page-head"><div><h1>🏖 <?= tt('hr.my_leave') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hr.my_leave_lede') ?></p></div>
  <form method="get" action="index.php" class="inline"><input type="hidden" name="p" value="my_leave"><select name="year" data-nosearch data-js-submit="1"><?php foreach (array_unique(array_merge(hr_years(), [$year])) as $yy): ?><option value="<?= $yy ?>"<?= $yy === $year ? ' selected' : '' ?>><?= $yy ?></option><?php endforeach; ?></select></form></div>
<?php if (!$p): ?><div class="flash flash--warn"><?= tt('hr.no_person') ?></div><?php layout_foot(); return; endif; ?>
<?php if (function_exists('hr_is_contractor') && hr_is_contractor($p)): ?><div class="flash flash--warn"><?= tt('hr.contractor_note') ?></div><?php endif; ?>
<?php $bal = hr_balances($p, $year); $mgr = hr_manager_of($p); $mgrName = $mgr ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$mgr]) : ''; ?>
<div class="cols">
<div class="card">
  <h2><?= tt('hr.balances') ?> <?= $year ?></h2>
  <?php if (!$bal): ?><p class="hint"><?= tt('hr.no_balances') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.type') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.pending') ?></th><th class="num"><?= tt('hr.available') ?></th></tr></thead><tbody>
  <?php foreach ($bal as $b): if ($b['counts'] && !$b['via_annual'] && $b['total'] <= 0 && $b['taken'] <= 0 && $b['pending'] <= 0 && $b['opening'] <= 0) continue; $un = $b['unit'] === 'hour' ? ' h' : ''; ?><tr><td><b><?= e($b['type']['code']) ?></b> <?= e($b['type']['name']) ?><?= $b['type']['consumes_annual'] ? ' <span class="hint">' . tt('hr.consumes_ann') . '</span>' : '' ?></td><td class="num"><?= $b['counts'] && !$b['via_annual'] ? e($fh($b['total'])) . $un : '—' ?></td><td class="num"><?= e($fh($b['taken'] + $b['opening'])) . $un ?></td><td class="num"><?= $b['pending'] > 0 ? e($fh($b['pending'])) . $un : '—' ?></td><td class="num<?= $b['available'] !== null && $b['available'] < 0 ? ' bad' : '' ?>"><b><?= $b['available'] === null ? '—' : e($fh($b['available'])) . $un ?></b><?= !empty($b['acc_hours']) && $b['acc_hours'] > 0 ? '<br><span class="hint" title="' . tt('hr.acc_hours_hint') . '">⏱ ' . e(t('hr.acc_hours', ['h' => $fh($b['acc_hours']), 'n' => $fh(HR_HOURS_PER_DAY - $b['acc_hours'])])) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= e(t('hr.my_lede2', ['m' => $mgrName ?: t('hr.no_manager')])) ?></p>
</div>
<?php if (function_exists('hra_has') && hra_has() && hra_can_use()): $hc = hra_completeness($p); $hOpen = hra_my_flow((int)$u['id'], 'card'); ?>
<div class="card" id="hra-card">
  <h2>🤖 <?= tt('hra.ml_title') ?> <?= pill($hc['pct'] >= 90 ? 'green' : ($hc['pct'] >= 60 ? 'gold' : 'red'), $hc['pct'] . ' %') ?></h2>
  <p class="hint"><?= e(t('hra.ml_text', ['p' => $hc['pct']])) ?><?= $hOpen ? ' ' . e(t('hra.ml_open', ['c' => $hOpen['code'], 's' => t('hra.st_' . $hOpen['status'])])) : '' ?></p>
  <div class="btn-row"><?php if ($hOpen): ?><a class="btn btn--gold btn--sm" href="<?= url('hra_flow', ['id' => (int)$hOpen['id']]) ?>">▶ <?= tt($hOpen['status'] === 'draft' ? 'hra.continue' : 'hra.view_flow') ?></a><?php else: ?><form method="post" action="<?= url('my_leave') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hra_start"><button class="btn btn--gold btn--sm" type="submit">🤖 <?= tt('hra.start_card') ?></button></form><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('hra') ?>"><?= tt('hra.title') ?></a></div>
</div>
<?php endif; ?>
<?php if (function_exists('hr_lv_has') && hr_lv_has() && ($myPeriods = hr_periods_for($p))): ?>
<div class="card" id="hr-periods">
  <h2>🏝 <?= tt('hr.periods_open') ?></h2>
  <p class="hint"><?= tt('hr.periods_open_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.period') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.period_places') ?></th><th><?= tt('hr.your_dates') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($myPeriods as $lp): ?><tr>
    <td><b><?= e($lp['name']) ?></b><?= !empty($lp['mandatory']) ? ' ' . pill('bad', '🔒 ' . t('hr.period_mandatory_s')) : '' ?><?= $lp['note'] ? '<br><span class="hint">' . e($lp['note']) . '</span>' : '' ?></td><td><?= e($lp['code']) ?> <span class="hint"><?= e($lp['tname']) ?></span></td>
    <td><?= e(fmt_date($lp['date_from'])) ?> – <?= e(fmt_date($lp['date_to'])) ?></td><td class="hint"><?= (int)$lp['n_approved'] ?><?= $lp['max_people'] ? ' / ' . (int)$lp['max_people'] : '' ?></td>
    <?php if ($lp['joined']): ?><td colspan="2"><?= hr_status_pill($lp['joined']['status']) ?> <span class="hint"><?= e(fmt_date($lp['joined']['date_from'])) ?> – <?= e(fmt_date($lp['joined']['date_to'])) ?></span></td>
    <?php elseif (!empty($lp['mandatory'])): ?><td colspan="2"><span class="hint"><?= tt('hr.period_mandatory_my') ?></span></td>
    <?php elseif ($lp['full']): ?><td colspan="2"><?= pill('mute', t('hr.period_full_s')) ?></td>
    <?php else: ?><form method="post" action="<?= url('my_leave') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_period_join"><input type="hidden" name="period_id" value="<?= (int)$lp['id'] ?>">
      <td><div style="display:flex;gap:4px;align-items:center;"><input type="date" name="date_from" value="<?= e($lp['date_from']) ?>" min="<?= e($lp['date_from']) ?>" max="<?= e($lp['date_to']) ?>" style="width:150px;"> – <input type="date" name="date_to" value="<?= e($lp['date_to']) ?>" min="<?= e($lp['date_from']) ?>" max="<?= e($lp['date_to']) ?>" style="width:150px;"></div></td>
      <td class="row-actions"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('hr.period_join') ?></button></td></form><?php endif; ?>
  </tr><?php endforeach; ?></tbody></table></div>
</div>
<?php endif; ?>
<div class="card" id="req-new">
  <h2>+ <?= tt('hr.new_request') ?></h2>
  <form method="post" action="<?= url('my_leave') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_new">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('hr.type') ?></label><select name="type_id" id="hr_type" data-nosearch><?php foreach (function_exists('hr_types_for_person') ? hr_types_for_person($p) : hr_types() as $tid => $t): ?><option value="<?= $tid ?>" data-unit="<?= $t['unit'] ?>" data-reason="<?= (int)$t['needs_reason'] ?>" data-doc="<?= (int)$t['needs_doc'] ?>" data-hr="<?= (int)$t['hr_only'] ?>"><?= e($t['code'] . ' · ' . $t['name']) ?><?= $t['unit'] === 'hour' ? ' (h)' : '' ?></option><?php endforeach; ?></select><p class="hint" id="hr_type_hint"></p></div>
      <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="date_from" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field hr-day"><label><?= tt('pl.to') ?></label><input type="date" name="date_to"><p class="hint"><?= tt('hr.to_hint') ?></p></div>
      <div class="field hr-hour"><label><?= tt('hr.hours') ?></label><input name="hours" inputmode="decimal" placeholder="2"></div>
      <div class="field hr-hour"><label><?= tt('hr.time') ?></label><div style="display:flex;gap:6px;"><input type="time" name="time_from"><input type="time" name="time_to"></div></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.reason') ?> <span class="hint" id="hr_reason_req" style="display:none;">*</span></label><input name="reason" maxlength="300" placeholder="<?= tt('hr.reason_ph') ?>"></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.document') ?> <span class="hint" id="hr_doc_req" style="display:none;">*</span></label><input type="file" name="doc" accept=".pdf,image/*"><p class="hint"><?= tt('hr.doc_hint') ?></p></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('hr.send_request') ?></button><span class="hint"><?= tt('hr.request_flow') ?></span></div>
  </form>
</div>
</div>
<div class="card">
  <h2><?= tt('hr.my_requests') ?></h2>
  <?php $reqs = hr_requests_for('mine', $year); if (!$reqs): ?><p class="hint"><?= tt('hr.no_requests2') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th class="num"><?= tt('hr.days') ?> / h</th><th><?= tt('hr.reason') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.decision') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($reqs as $r): ?><tr><td><b><?= e($r['code']) ?></b> <?= e(hr_type((int)$r['type_id'])['name'] ?? $r['type_name']) ?><?= $r['doc_path'] ? ' <a href="' . url('hr_doc', ['id' => (int)$r['id']]) . '" target="_blank" title="' . e($r['doc_name']) . '">📎</a>' : '' ?></td><td><?= e(fmt_date($r['date_from'])) ?></td><td><?= e(fmt_date($r['date_to'])) ?></td><td class="num"><?= $r['unit'] === 'hour' ? e($fh($r['hours'])) . ' h' : e($fh($r['days'])) ?></td><td><?= e($r['reason'] ?? '') ?></td><td><?= hr_status_pill($r['status']) ?><?= isset($r['counted']) && !$r['counted'] ? ' ' . pill('mute', t('hr.history_only_s')) : '' ?></td>
    <td class="hint"><?= $r['manager_at'] ? e($r['manager_name'] ?? '') . ' ' . e(fmt_date($r['manager_at'])) . ($r['manager_note'] ? ' — ' . e($r['manager_note']) : '') . '<br>' : '' ?><?= $r['hr_at'] ? 'HR ' . e(fmt_date($r['hr_at'])) . ($r['hr_note'] ? ' — ' . e($r['hr_note']) : '') : '' ?></td>
    <td class="row-actions"><?php if (in_array($r['status'], ['pending_mgr', 'pending_hr'], true)): ?><form method="post" action="<?= url('my_leave') ?>" class="inline" data-lock data-confirm="<?= tt('hr.cancel_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('hr.cancel') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('hr.cancel') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if (function_exists('soc_has') && soc_has() && function_exists('emp_only') && emp_only()) { soc_tick(); $socBack = 'my_leave'; require __DIR__ . '/_soc_dash.php'; }   // R63-001: employee scope sees the community block here ?>
<?php if (function_exists('hr_tr_has') && hr_tr_has()): $opp = hr_trainings_for($p); $myTr = hr_person_trainings((int)$p['id']); $myEv = array_values(array_filter(hr_evaluations((int)$p['id']), fn($e) => $e['status'] !== 'draft')); $fh2 = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="card" id="hr-training">
  <h2>🎓 <?= tt('hr.tr_opportunities') ?></h2>
  <p class="hint"><?= tt('hr.tr_opportunities_hint') ?></p>
  <?php if (!$opp): ?><div class="empty"><?= tt('hr.tr_no_open') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tr_title') ?></th><th><?= tt('hr.tr_kind') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.tr_deadline') ?></th><th><?= tt('hr.tr_members') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($opp as $tr): ?><tr>
    <td><b><?= e($tr['title']) ?></b><?= $tr['certificate'] ? ' 🏅' : '' ?><?= $tr['provider'] ? '<br><span class="hint">' . e($tr['provider']) . ($tr['location'] ? ' · ' . e($tr['location']) : '') . '</span>' : '' ?><?= $tr['description'] ? '<details><summary class="hint">' . tt('hr.tr_description') . '</summary><p class="hint" style="white-space:pre-wrap;margin:4px 0 0;">' . e($tr['description']) . '</p></details>' : '' ?></td>
    <td><?= hr_tr_kind_pill($tr['kind']) ?></td><td class="hint"><?= $tr['date_from'] ? e(fmt_date($tr['date_from'])) . ($tr['date_to'] && $tr['date_to'] !== $tr['date_from'] ? ' – ' . e(fmt_date($tr['date_to'])) : '') : tt('hr.tr_tba') ?><?= $tr['hours'] !== null ? '<br>' . e($fh2($tr['hours'])) . ' h' : '' ?></td>
    <td class="hint"><?= $tr['deadline'] ? e(fmt_date($tr['deadline'])) : '—' ?></td><td class="hint"><?= (int)$tr['n_in'] ?><?= $tr['capacity'] ? ' / ' . (int)$tr['capacity'] : '' ?></td>
    <?php if ($tr['mine']): ?><td><?= hr_trm_pill($tr['mine']['status']) ?></td>
    <?php elseif ($tr['full']): ?><td><?= pill('mute', t('hr.tr_full_s')) ?></td>
    <?php elseif ($tr['late']): ?><td><?= pill('mute', t('hr.tr_late_s')) ?></td>
    <?php else: ?><td class="row-actions"><form method="post" action="<?= url('my_leave') ?>" data-lock style="display:flex;gap:4px;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_tr_apply"><input type="hidden" name="id" value="<?= (int)$tr['id'] ?>"><input name="note" maxlength="300" placeholder="<?= tt('hr.notes') ?>" style="width:140px;"><button class="btn btn--gold btn--sm" type="submit">✋ <?= tt('hr.tr_apply') ?></button></form></td><?php endif; ?>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <h3 style="margin-top:14px;"><?= tt('hr.tr_my') ?></h3>
  <?php if (!$myTr): ?><p class="hint">—</p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tr_title') ?></th><th><?= tt('hr.tr_kind') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.tr_hours') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.tr_score') ?></th><th><?= tt('hr.tr_certificate') ?></th></tr></thead><tbody>
  <?php foreach ($myTr as $m): ?><tr><td><b><?= e($m['title']) ?></b><?= $m['provider'] ? '<br><span class="hint">' . e($m['provider']) . '</span>' : '' ?></td><td><?= hr_tr_kind_pill($m['kind']) ?></td><td class="hint"><?= $m['date_from'] ? e(fmt_date($m['date_from'])) : '—' ?></td><td class="num"><?= e($fh2($m['hours'])) ?></td><td><?= hr_trm_pill($m['status']) ?></td><td><?= e((string)($m['score'] ?: '—')) ?></td><td class="hint"><?= $m['cert_no'] ? e($m['cert_no']) : '' ?><?= $m['cert_expiry'] ? ' · ' . tt('hr.tr_until') . ' ' . e(fmt_date($m['cert_expiry'])) : '' ?><?= $m['cert_doc_id'] ? ' <a href="' . url('hr_pdoc', ['id' => (int)$m['cert_doc_id']]) . '" target="_blank">📎 ' . e((string)$m['doc_name']) . '</a>' : '' ?><?= !$m['cert_no'] && !$m['cert_doc_id'] ? '—' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if (function_exists('hr_targets_has') && hr_targets_has()): $tgY = (int) date('Y'); $myTg = hr_targets_for($p, $tgY); ?>
<div class="card" id="hr-targets">
  <h2>🎯 <?= tt('hr.tg_my') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= $tgY ?></span></h2>
  <p class="hint"><?= tt('hr.tg_my_hint') ?></p>
  <?php if (!$myTg): ?><div class="empty"><?= tt('hr.tg_none_my') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tg_scope') ?></th><th><?= tt('hr.tg_title') ?></th><th><?= tt('hr.tg_measure') ?></th><th><?= tt('hr.tg_due') ?></th><th><?= tt('hr.tg_progress') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($myTg as $tg): $own = $tg['scope'] === 'person'; ?><tr>
    <td><?= $tg['scope'] === 'person' ? pill('gold', t('hr.tg_sc_person')) : ($tg['scope'] === 'department' ? pill('navy', e((string)$tg['department'])) : pill('mute', t('hr.tg_sc_company'))) ?></td>
    <td><b><?= e($tg['title']) ?></b><?= $tg['description'] ? '<br><span class="hint">' . e($tg['description']) . '</span>' : '' ?></td>
    <td class="hint"><?= e((string)$tg['measure']) ?><?= $tg['target_value'] ? ' → <b>' . e($tg['target_value']) . ($tg['unit'] ? ' ' . e($tg['unit']) : '') . '</b>' : '' ?></td>
    <td class="hint"><?= $tg['due_date'] ? e(fmt_date($tg['due_date'])) : '—' ?></td>
    <td><?= hr_tg_bar((int)$tg['progress'], $tg['status']) ?><span class="hint"><?= (int)$tg['progress'] ?> %</span></td><td><?= hr_tg_pill($tg['status']) ?></td>
    <td class="row-actions"><?php if ($own && !in_array($tg['status'], ['achieved', 'missed', 'cancelled'], true)): ?><form method="post" action="<?= url('my_leave') ?>" data-lock style="display:flex;gap:4px;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_tg_progress"><input type="hidden" name="id" value="<?= (int)$tg['id'] ?>"><input type="hidden" name="back" value="my"><input type="number" name="progress" min="0" max="100" value="<?= (int)$tg['progress'] ?>" style="width:64px;"><select name="status" data-nosearch style="width:110px;"><?php foreach (['open', 'on_track', 'at_risk'] as $st): ?><option value="<?= $st ?>"<?= $tg['status'] === $st ? ' selected' : '' ?>><?= e(t('hr.tg_s_' . $st)) ?></option><?php endforeach; ?></select><input name="note" maxlength="600" placeholder="<?= tt('hr.notes') ?>" style="width:120px;"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form><?php endif; ?></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<div class="card" id="hr-how">
  <h2>📋 <?= tt('hr.ev_how_title') ?></h2>
  <p><?= tt('hr.ev_how_lede') ?></p>
  <div class="grid grid-2">
    <div><h3 class="ac-h"><?= tt('hr.ev_how_criteria') ?></h3><ul class="hint" style="padding-left:18px;margin:0;"><?php foreach (HR_EVAL_CRITERIA as $c): ?><li><b><?= e(t('hr.ec_' . $c)) ?></b> — <?= e(t('hr.ecd_' . $c)) ?></li><?php endforeach; ?></ul></div>
    <div><h3 class="ac-h"><?= tt('hr.ev_how_auto') ?></h3><ul class="hint" style="padding-left:18px;margin:0;"><li><b><?= e(t('hr.ec_training_auto')) ?></b> — <?= e(t('hr.ecd_training_auto')) ?></li><li><b><?= e(t('hr.ec_attendance_auto')) ?></b> — <?= e(t('hr.ecd_attendance_auto')) ?></li><li><b>🎯 <?= tt('hr.targets') ?></b> — <?= e(t('hr.ecd_targets')) ?></li></ul>
      <?php if (function_exists('hr_eval_auto')): $au = hr_eval_auto((int)$p['id'], (int) date('Y')); ?><p class="hint" style="margin-top:8px;"><b><?= e(t('hr.ev_how_mine', ['y' => (int) date('Y')])) ?></b><br>⚙ <?= e(t('hr.ec_training_auto')) ?>: <?= str_repeat('★', (int)$au['training_auto']['score']) ?> <span class="hint">· <?= e(hr_eval_auto_text('training_auto', $au['training_auto'])) ?></span><br>⚙ <?= e(t('hr.ec_attendance_auto')) ?>: <?= str_repeat('★', (int)$au['attendance_auto']['score']) ?> <span class="hint">· <?= e(hr_eval_auto_text('attendance_auto', $au['attendance_auto'])) ?></span></p><?php endif; ?></div>
  </div>
  <p class="hint" style="margin-top:8px;"><?= tt('hr.ev_scale') ?> <?= tt('hr.ev_how_calendar') ?></p>
  <?php if (function_exists('hr_eval_policy') && hr_eval_policy() !== ''): ?><blockquote style="margin:8px 0 0;border-left:3px solid #C9A227;padding-left:10px;white-space:pre-wrap;"><?= e(hr_eval_policy()) ?></blockquote><?php endif; ?>
</div>
<?php endif; ?>
<?php if ($myEv): ?>
<div class="card" id="hr-eval">
  <h2>📈 <?= tt('hr.ev_my') ?></h2>
  <p class="hint"><?= tt('hr.ev_my_hint') ?></p>
  <?php foreach ($myEv as $e): $sc = json_decode((string)$e['scores'], true) ?: []; ?>
  <details<?= $e['status'] === 'final' ? ' open' : '' ?> style="margin-bottom:8px;"><summary><b><?= (int)$e['year'] ?></b> · <?= tt('hr.ev_overall') ?> <b><?= e($fh2($e['overall'])) ?></b> / 5 · <?= pill($e['status'] === 'acknowledged' ? 'green' : 'gold', t('hr.es_' . $e['status'])) ?> <span class="hint">· <?= e((string)$e['evaluator']) ?> · <?= e(fmt_date($e['eval_date'])) ?></span></summary>
    <div class="grid grid-2" style="margin-top:8px;">
      <div><table class="tbl"><?php foreach (array_merge(HR_EVAL_CRITERIA, defined('HR_EVAL_AUTO') ? HR_EVAL_AUTO : []) as $c): if (!in_array($c, HR_EVAL_CRITERIA, true) && !isset($sc[$c])) continue; ?><tr><td><?= e(t('hr.ec_' . $c)) ?><?= in_array($c, HR_EVAL_CRITERIA, true) ? '' : ' <span class="hint">⚙</span>' ?></td><td class="num"><?= isset($sc[$c]) ? str_repeat('★', (int)$sc[$c]) . '<span class="hint">' . str_repeat('☆', 5 - (int)$sc[$c]) . '</span>' : '—' ?></td></tr><?php endforeach; ?></table></div>
      <div><?php foreach (['strengths', 'improvements', 'goals', 'training_needs', 'comments'] as $k): if ($e[$k]): ?><p><b><?= e(t('hr.ev_' . $k)) ?></b><br><span style="white-space:pre-wrap;"><?= e($e[$k]) ?></span></p><?php endif; endforeach; ?></div>
    </div>
    <?php if ($e['status'] === 'final'): ?><form method="post" action="<?= url('my_leave') ?>" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_ev_ack"><input type="hidden" name="year" value="<?= (int)$e['year'] ?>"><div class="field"><label><?= tt('hr.ev_employee_comment') ?></label><textarea data-mt name="employee_comment" rows="2" maxlength="4000"></textarea></div><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('hr.ev_ack') ?></button></div></form>
    <?php elseif ($e['employee_comment']): ?><p class="hint" style="margin-top:6px;"><b><?= tt('hr.ev_employee_comment') ?>:</b> <?= e($e['employee_comment']) ?></p><?php endif; ?>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; endif; ?>
<?php if (function_exists('hr_msg_has') && hr_msg_has()) echo hr_messages_card((int)$p['id'], false); ?>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('hr_type'),f=s.closest('form');if(!s)return;var L=<?= json_encode(['day' => t('hr.hint_day'), 'hour' => t('hr.hint_hour'), 'hr' => t('hr.hint_hr'), 'mgr' => t('hr.hint_mgr')], JSON_UNESCAPED_UNICODE) ?>;function ui(){var o=s.options[s.selectedIndex];if(!o)return;var h=o.dataset.unit==='hour';f.querySelectorAll('.hr-day').forEach(function(x){x.style.display=h?'none':'';});f.querySelectorAll('.hr-hour').forEach(function(x){x.style.display=h?'':'none';});document.getElementById('hr_reason_req').style.display=o.dataset.reason==='1'?'':'none';document.getElementById('hr_doc_req').style.display=o.dataset.doc==='1'?'':'none';document.getElementById('hr_type_hint').textContent=(h?L.hour:L.day)+' · '+(o.dataset.hr==='1'?L.hr:L.mgr);}s.addEventListener('change',ui);ui();})();</script>
<?php layout_foot(); ?>

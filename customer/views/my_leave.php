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
  <?php foreach ($bal as $b): if ($b['counts'] && !$b['via_annual'] && $b['total'] <= 0 && $b['taken'] <= 0 && $b['pending'] <= 0 && $b['opening'] <= 0) continue; $un = $b['unit'] === 'hour' ? ' h' : ''; ?><tr><td><b><?= e($b['type']['code']) ?></b> <?= e($b['type']['name']) ?><?= $b['type']['consumes_annual'] ? ' <span class="hint">' . tt('hr.consumes_ann') . '</span>' : '' ?></td><td class="num"><?= $b['counts'] && !$b['via_annual'] ? e($fh($b['total'])) . $un : '—' ?></td><td class="num"><?= e($fh($b['taken'] + $b['opening'])) . $un ?></td><td class="num"><?= $b['pending'] > 0 ? e($fh($b['pending'])) . $un : '—' ?></td><td class="num<?= $b['available'] !== null && $b['available'] < 0 ? ' bad' : '' ?>"><b><?= $b['available'] === null ? '—' : e($fh($b['available'])) . $un ?></b></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= e(t('hr.my_lede2', ['m' => $mgrName ?: t('hr.no_manager')])) ?></p>
</div>
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
<?php if (function_exists('hr_msg_has') && hr_msg_has()) echo hr_messages_card((int)$p['id'], false); ?>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('hr_type'),f=s.closest('form');if(!s)return;var L=<?= json_encode(['day' => t('hr.hint_day'), 'hour' => t('hr.hint_hour'), 'hr' => t('hr.hint_hr'), 'mgr' => t('hr.hint_mgr')], JSON_UNESCAPED_UNICODE) ?>;function ui(){var o=s.options[s.selectedIndex];if(!o)return;var h=o.dataset.unit==='hour';f.querySelectorAll('.hr-day').forEach(function(x){x.style.display=h?'none':'';});f.querySelectorAll('.hr-hour').forEach(function(x){x.style.display=h?'':'none';});document.getElementById('hr_reason_req').style.display=o.dataset.reason==='1'?'':'none';document.getElementById('hr_doc_req').style.display=o.dataset.doc==='1'?'':'none';document.getElementById('hr_type_hint').textContent=(h?L.hour:L.day)+' · '+(o.dataset.hr==='1'?L.hr:L.mgr);}s.addEventListener('change',ui);ui();})();</script>
<?php layout_foot(); ?>

<?php
/** HR › Employee card (GAS-CP-R56-001): data, service, employment periods (hire / change / termination / re-hire) with documents, card documents, leave history. HR and administrators. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr3_has') || !hr3_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.card'), 'admin_hr'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$pid = (int)($_GET['id'] ?? 0); $p = $pid ? hr_person($pid) : null; if (!$p) { http_response_code(404); layout_head(t('hr.card'), 'admin_hr'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $accounts = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name");
$emps = hr_employments($pid); $open = hr_employment_open($pid); $docs = hr_docs($pid); $svc = hr_service($p); $mgr = hr_manager_of($p); $year = (int) date('Y');
$lastId = $emps ? (int) end($emps)['id'] : 0; $docsByEmp = []; foreach ($docs as $d) if ($d['employment_id']) $docsByEmp[(int)$d['employment_id']][] = $d;
$endSoon = $p['contract_end'] && $p['is_active'] ? (int) floor((strtotime($p['contract_end']) - time()) / 86400) : null;
layout_head($p['name_en'] . ' — ' . t('hr.card'), 'admin_hr');
?>
<div class="page-head"><div><p class="eyebrow"><a href="<?= url('admin_hr', ['tab' => 'people']) ?>">‹ <?= tt('hr.people') ?></a></p><h1>🪪 <?= e($p['name_en']) ?> <?= $p['emp_code'] ? '<span class="hint">' . e($p['emp_code']) . '</span>' : '' ?> <?= $p['is_active'] ? pill('green', t('hr.employed')) : pill('mute', t('hr.left') . ($p['left_date'] ? ' ' . fmt_date($p['left_date']) : '')) ?><?= function_exists('hr_is_contractor') && hr_is_contractor($p) ? ' ' . pill('blue', t('hr.ek_contractor')) : '' ?><?= function_exists('hr_jurisdiction') && hr_jurisdiction($p) !== 'GR' ? ' ' . pill('mute', hr_jurisdiction($p)) : '' ?></h1>
  <p class="lede" style="margin-bottom:0;"><?= e($p['name_el'] ?? '') ?><?= $p['job_title'] ? ' · ' . e($p['job_title']) : '' ?><?= $p['department'] ? ' · ' . e($p['department']) : '' ?><?= $p['email'] ? ' · ' . e($p['email']) : '' ?></p></div>
  <div><a class="btn btn--ghost btn--sm" href="<?= url('hr_requests', ['pid' => $pid, 'year' => 0, 'st' => 'all']) ?>">📜 <?= tt('hr.history') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('planning', ['hy' => $year, 'hu' => (int)($p['user_id'] ?? 0)]) ?>#hr-report">🗓 <?= tt('pl.title') ?></a></div></div>
<?php if ($endSoon !== null && $endSoon <= 60): ?><div class="flash flash--<?= $endSoon < 0 ? 'bad' : 'warn' ?>"><?= e(t($endSoon < 0 ? 'hr.contract_over' : 'hr.contract_soon', ['d' => fmt_date($p['contract_end']), 'n' => abs($endSoon)])) ?></div><?php endif; ?>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat"><b><?= e(hr_fmt_months($svc['company'])) ?></b><span class="s"><?= tt('hr.svc_company') ?></span></span>
  <span class="stat"><b><?= e(hr_fmt_months($svc['prior'])) ?></b><span class="s"><?= tt('hr.svc_prior') ?></span></span>
  <span class="stat gold"><b><?= e(hr_fmt_months($svc['total'])) ?></b><span class="s"><?= tt('hr.svc_total') ?></span></span>
  <span class="stat"><b><?= e(t('hr.ct_' . $p['contract_type'])) ?><?= $p['part_time'] ? ' · ' . tt('hr.part_time') : '' ?></b><span class="s"><?= $p['contract_end'] ? tt('hr.contract_end') . ' ' . e(fmt_date($p['contract_end'])) : tt('hr.no_end') ?></span></span>
</div>

<?php if (function_exists('hr_status_summary') && !(function_exists('hr_is_contractor') && hr_is_contractor($p))): $sumY = (int)($_GET['year'] ?? $year); $sum = hr_status_summary($p, $sumY); $fh0 = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="card hr-sum" style="padding:10px 14px;margin-bottom:12px;">
  <div style="display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;">
    <b style="white-space:nowrap;">📊 <?= e(t('hr.status_title', ['y' => $sumY])) ?></b>
    <?php if ($a = $sum['annual']): ?><span class="hr-sum-ann"><b><?= e($a['type']['code']) ?></b> <?= tt('hr.entitled') ?> <b><?= e($fh0($a['entitled'])) ?></b> · <?= tt('hr.carry') ?> <b><?= e($fh0($a['carry'])) ?></b> · <?= tt('hr.taken') ?> <b><?= e($fh0($a['taken'] + $a['opening'])) ?></b><?= $a['pending'] > 0 ? ' · ' . tt('hr.pending') . ' <b>' . e($fh0($a['pending'])) . '</b>' : '' ?> · <?= tt('hr.available') ?> <?= pill($a['available'] !== null && $a['available'] < 0 ? 'bad' : ($a['available'] !== null && $a['available'] <= 3 ? 'gold' : 'green'), $fh0($a['available'])) ?><?= $a['mode'] === 'manual' ? ' <span class="hint">🔓</span>' : '' ?></span><?php else: ?><span class="hint"><?= tt('hr.mode_none') ?></span><?php endif; ?>
    <?php foreach ($sum['others'] as $b): $un = $b['unit'] === 'hour' ? ' h' : ''; ?><span class="hint" style="white-space:nowrap;" title="<?= e($b['type']['name']) ?>"><b><?= e($b['type']['code']) ?></b> <?= $b['available'] === null ? e($fh0($b['taken'])) . $un . ' ' . tt('hr.taken') : e($fh0($b['available'])) . $un . ' / ' . e($fh0($b['total'])) . $un ?></span><?php endforeach; ?>
    <a class="hint" href="<?= url('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => $sumY]) ?>">›</a>
  </div>
</div>
<?php endif; ?>
<?php $tab = (string)($_GET['tab'] ?? 'data'); if (!in_array($tab, ['data', 'emp', 'req', 'msg', 'docs', 'ent', 'types', 'train', 'eval', 'log'], true)) $tab = 'data'; $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('hr_person', ['id' => $pid, 'tab' => $k]) . '">' . $lbl . '</a>';
$pend = count(array_filter(hr_requests_for('hr', 0), fn($r) => (int)$r['person_id'] === $pid && in_array($r['status'], ['pending_mgr', 'pending_hr'], true))); $um = function_exists('hr_msg_has') && hr_msg_has() ? hr_messages_unread($pid) : 0; ?>
<nav class="tabs" style="margin-bottom:12px;"><?= $T('data', '🪪 ' . t('hr.tab_data')) . $T('emp', '📑 ' . t('hr.employments')) . $T('req', '✅ ' . t('hr.requests') . ($pend ? ' <b>' . $pend . '</b>' : '')) . $T('msg', '💬 ' . t('hr.msg_title') . ($um ? ' <b>' . $um . '</b>' : '')) . $T('docs', '📎 ' . t('hr.documents') . ' (' . count($docs) . ')') . (function_exists('hr4_has') && hr4_has() ? $T('ent', '🗓 ' . t('hr.entitlements')) . (function_exists('hr5_has') && hr5_has() ? $T('types', '🏷 ' . t('hr.types')) : '') . (function_exists('hr_tr_has') && hr_tr_has() ? $T('train', '🎓 ' . t('hr.training')) . $T('eval', '📈 ' . t('hr.evaluations')) : '') . $T('log', '🕘 ' . t('hr.changes')) : '') ?></nav>

<?php if ($tab === 'data' || $tab === 'emp'): ?>
<div class="cols">
<?php if ($tab === 'data'): ?>
<div class="card" id="hr-data">
  <h2><?= tt('hr.person_edit') ?></h2>
  <form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_person_save"><input type="hidden" name="id" value="<?= $pid ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('hr.emp_code') ?></label><input name="emp_code" maxlength="12" value="<?= e($p['emp_code'] ?? '') ?>" placeholder="E001"></div>
      <div class="field"><label><?= tt('hr.account') ?></label><select name="user_id"><option value="">— <?= tt('hr.no_account') ?> —</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $sel($a['id'], $p['user_id'] ?? '') ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.name_en') ?></label><input name="name_en" required maxlength="120" value="<?= e($p['name_en']) ?>"></div>
      <div class="field"><label><?= tt('hr.name_el') ?></label><input name="name_el" maxlength="120" value="<?= e($p['name_el'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.department') ?></label><select name="department_id" data-nosearch><option value=""></option><?php foreach (hr_departments() as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $sel($d['id'], $p['department_id'] ?? '') ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.job_title') ?></label><input name="job_title" maxlength="120" value="<?= e($p['job_title'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.contract_type') ?></label><select name="contract_type" data-nosearch><?php foreach (HR_CONTRACTS as $c): ?><option value="<?= $c ?>"<?= $sel($c, $p['contract_type']) ?>><?= e(t('hr.ct_' . $c)) ?></option><?php endforeach; ?></select><label class="chk" style="margin-top:4px;font-size:12px;"><input type="checkbox" name="part_time" value="1"<?= $p['part_time'] ? ' checked' : '' ?>> <?= tt('hr.part_time') ?></label></div>
      <div class="field"><label><?= tt('hr.contract_end') ?></label><input type="date" name="contract_end" value="<?= e($p['contract_end'] ?? '') ?>"><p class="hint"><?= tt('hr.contract_end_hint') ?></p></div>
      <div class="field"><label><?= tt('hr.hire_date') ?></label><input type="date" name="hire_date" value="<?= e($p['hire_date'] ?? '') ?>"><p class="hint"><?= tt('hr.hire_hint2') ?></p></div>
      <div class="field"><label><?= tt('hr.prior_service') ?></label><div style="display:flex;gap:6px;align-items:center;"><input type="number" name="prior_years" min="0" max="50" value="<?= intdiv((int)$p['prior_months'], 12) ?>" style="width:70px;"> <span class="hint"><?= tt('hr.y') ?></span> <input type="number" name="prior_months" min="0" max="11" value="<?= (int)$p['prior_months'] % 12 ?>" style="width:70px;"> <span class="hint"><?= tt('hr.m') ?></span></div><p class="hint"><?= tt('hr.prior_hint') ?></p></div>
      <?php if (function_exists('hr_jurisdiction')): ?><div class="field"><label><?= tt('hr.jurisdiction') ?></label><select name="jurisdiction" data-nosearch><?php foreach (HR_JURISDICTIONS as $jj): ?><option value="<?= $jj ?>"<?= $sel($jj, $p['jurisdiction'] ?? 'GR') ?>><?= e(t('hr.jur_' . $jj)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.employment_kind') ?></label><select name="employment_kind" data-nosearch><option value="employee"<?= $sel('employee', $p['employment_kind'] ?? 'employee') ?>><?= tt('hr.ek_employee') ?></option><option value="contractor"<?= $sel('contractor', $p['employment_kind'] ?? '') ?>><?= tt('hr.ek_contractor') ?></option></select><p class="hint"><?= tt('hr.ek_hint') ?></p></div><?php endif; ?>
      <?php if (function_exists('hr5_has') && hr5_has()): ?><div class="field"><label><?= tt('hr.gender') ?></label><select name="gender" data-nosearch><option value=""<?= empty($p['gender']) ? ' selected' : '' ?>>— <?= tt('hr.g_na') ?> —</option><option value="m"<?= $sel('m', $p['gender'] ?? '') ?>><?= tt('hr.g_m') ?></option><option value="f"<?= $sel('f', $p['gender'] ?? '') ?>><?= tt('hr.g_f') ?></option></select><p class="hint"><?= tt('hr.gender_hint') ?></p></div><?php endif; ?>
      <div class="field"><label><?= tt('hr.marital') ?></label><select name="marital" data-nosearch><option value=""></option><?php foreach (HR_MARITAL as $m): ?><option value="<?= $m ?>"<?= $sel($m, $p['marital'] ?? '') ?>><?= e(t('hr.ms_' . $m)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.week_days') ?></label><select name="week_days" data-nosearch><option value="5"<?= $sel(5, $p['week_days']) ?>>5</option><option value="6"<?= $sel(6, $p['week_days']) ?>>6</option></select></div>
      <div class="field"><label><?= tt('hr.children') ?></label><div style="display:flex;gap:6px;"><input type="number" name="children" min="0" max="12" value="<?= (int)$p['children'] ?>" style="width:70px;"><input name="children_years" maxlength="60" value="<?= e($p['children_years'] ?? '') ?>" placeholder="2015, 2019"></div></div>
      <div class="field"><label><?= tt('hr.manager') ?></label><input value="<?= $mgr ? e((string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$mgr])) : '—' ?>" readonly><p class="hint"><?= tt('hr.manager_hint') ?></p></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.notes') ?></label><input name="notes" maxlength="500" value="<?= e($p['notes'] ?? '') ?>"></div>
      <?php if (function_exists('hr7_has') && hr7_has()): ?><input type="hidden" name="personal" value="1">
      <div style="grid-column:1 / -1;margin-top:6px;"><h3 class="ac-h" style="margin:0 0 2px;">🔒 <?= tt('hr.personal') ?></h3><p class="hint" style="margin:0;"><?= tt('hr.personal_hint') ?></p></div>
      <div class="field"><label><?= tt('hr.personal_email') ?></label><input name="personal_email" type="email" maxlength="190" value="<?= e($p['personal_email'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.phone_mobile') ?></label><input name="phone_mobile" maxlength="40" value="<?= e($p['phone_mobile'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.phone_home') ?></label><input name="phone_home" maxlength="40" value="<?= e($p['phone_home'] ?? '') ?>" autocomplete="off"></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.address') ?></label><input name="address" maxlength="200" value="<?= e($p['address'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.birth_date') ?></label><input type="date" name="birth_date" value="<?= e($p['birth_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.amka') ?></label><input name="amka" maxlength="11" inputmode="numeric" value="<?= e($p['amka'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.afm') ?></label><input name="afm" maxlength="9" inputmode="numeric" value="<?= e($p['afm'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.doy') ?></label><input name="doy" maxlength="80" value="<?= e($p['doy'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.id_number') ?></label><input name="id_number" maxlength="30" value="<?= e($p['id_number'] ?? '') ?>" autocomplete="off"></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.iban') ?></label><input name="iban" maxlength="34" value="<?= e($p['iban'] ?? '') ?>" autocomplete="off" style="font-family:ui-monospace,monospace;"></div>
      <div class="field"><label><?= tt('hr.bank') ?></label><input name="bank" maxlength="80" value="<?= e($p['bank'] ?? '') ?>" autocomplete="off"></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.emergency_name') ?></label><input name="emergency_name" maxlength="120" value="<?= e($p['emergency_name'] ?? '') ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.emergency_phone') ?></label><input name="emergency_phone" maxlength="40" value="<?= e($p['emergency_phone'] ?? '') ?>" autocomplete="off"></div>
      <?php if (function_exists('hr8_has') && hr8_has()): $tx = fn(string $k, int $max, string $extra = '') => '<input name="' . $k . '" maxlength="' . $max . '" value="' . e((string)($p[$k] ?? '')) . '" autocomplete="off"' . $extra . '>'; ?>
      <div class="field"><label><?= tt('hr.father_name') ?></label><?= $tx('father_name', 80) ?></div>
      <div class="field"><label><?= tt('hr.mother_name') ?></label><?= $tx('mother_name', 80) ?></div>
      <div class="field"><label><?= tt('hr.spouse_name') ?></label><?= $tx('spouse_name', 80) ?></div>
      <div class="field"><label><?= tt('hr.nationality') ?></label><?= $tx('nationality', 60) ?></div>
      <div class="field"><label><?= tt('hr.birth_place') ?></label><?= $tx('birth_place', 80) ?></div>
      <div class="field"><label><?= tt('hr.passport_no') ?></label><?= $tx('passport_no', 30) ?></div>
      <div class="field"><label><?= tt('hr.blood_group') ?></label><?= $tx('blood_group', 5, ' style="width:90px;"') ?></div>
      <div class="field"><label><?= tt('hr.education') ?></label><?= $tx('education', 120) ?></div>
      <div class="field"><label><?= tt('hr.experience_level') ?></label><?= $tx('experience_level', 40) ?></div>
      <div class="field"><label><?= tt('hr.transport') ?></label><?= $tx('transport', 60) ?></div>
      <div class="field"><label><?= tt('hr.phone_work') ?></label><?= $tx('phone_work', 40) ?></div>
      <div class="field"><label><?= tt('hr.extension') ?></label><?= $tx('extension', 10, ' style="width:90px;"') ?></div>
      <div class="field"><label><?= tt('hr.postcode') ?></label><?= $tx('postcode', 12, ' style="width:110px;"') ?></div>
      <div class="field"><label><?= tt('hr.city') ?></label><?= $tx('city', 80) ?></div>
      <div class="field"><label><?= tt('hr.country_res') ?></label><?= $tx('country', 60) ?></div>
      <div class="field"><label><?= tt('hr.gross_salary') ?></label><input name="gross_salary" type="number" step="0.01" min="0" value="<?= e((string)($p['gross_salary'] ?? '')) ?>" autocomplete="off"></div>
      <div class="field"><label><?= tt('hr.oaed_benefit') ?></label><?= $tx('oaed_benefit', 40) ?></div>
      <div class="field"><label><?= tt('hr.payroll_code') ?></label><?= $tx('payroll_code', 12, ' style="width:110px;"') ?></div>
      <?php endif; ?>
      <?php endif; ?>
      <div class="field" style="grid-column:1 / -1;display:flex;gap:16px;flex-wrap:wrap;"><label class="chk"><input type="checkbox" name="is_technical" value="1"<?= $p['is_technical'] ? ' checked' : '' ?>> <?= tt('hr.technical_lbl') ?></label><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $p['is_active'] ? ' checked' : '' ?>> <?= tt('hr.active_lbl') ?></label><?php if (function_exists('hr_jurisdiction')): ?><label class="chk"><input type="checkbox" name="single_parent" value="1"<?= !empty($p['single_parent']) ? ' checked' : '' ?>> <?= tt('hr.single_parent') ?></label><label class="chk"><input type="checkbox" name="disability_care" value="1"<?= !empty($p['disability_care']) ? ' checked' : '' ?>> <?= tt('hr.disability_care') ?></label><?php endif; ?></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>

<?php endif; if ($tab === 'emp'): ?>
<div class="card" id="hr-emp">
  <h2><?= tt('hr.employments') ?></h2>
  <p class="hint"><?= tt('hr.employments_hint') ?></p>
  <?php if (!$emps): ?><p class="hint"><?= tt('hr.no_employments') ?></p><?php else: ?>
  <ol class="timeline">
  <?php foreach ($emps as $e): $ico = ['hire' => '🟢', 'rehire' => '🔁', 'change' => '✏️', 'end' => '🔴'][$e['kind']] ?? '•'; ?>
    <li><b><?= $ico ?> <?= e(t('hr.ev_' . $e['kind'])) ?></b> · <?= e(fmt_date($e['date_from'])) ?><?= $e['kind'] !== 'end' && $e['date_to'] ? ' → ' . e(fmt_date($e['date_to'])) : '' ?>
      <span class="hint"> · <?= e(t('hr.ct_' . $e['contract_type'])) ?><?= $e['part_time'] ? ' · ' . tt('hr.part_time') : '' ?><?= $e['contract_end'] ? ' · ' . tt('hr.contract_end') . ' ' . e(fmt_date($e['contract_end'])) : '' ?><?= $e['job_title'] ? ' · ' . e($e['job_title']) : '' ?><?= $e['reason'] ? ' · ' . e(t('hr.er_' . $e['reason'])) : '' ?><?= $e['note'] ? ' — ' . e($e['note']) : '' ?><?= $e['by_name'] ? ' (' . e($e['by_name']) . ')' : '' ?></span>
      <?php if ($e['kind'] === 'end' && function_exists('hr_off_has') && hr_off_has() && ($offRows = hr_off_list((int)$e['id']))): $offP = hr_off_progress((int)$e['id']); ?>
      <div class="card card--tight" id="hr-off" style="margin:8px 0;"><div class="page-head" style="margin-bottom:4px;"><h3 style="margin:0;">📋 <?= tt('hr.off_title') ?> <?= $offP['complete'] ? pill('green', t('hr.off_released')) : pill('gold', $offP['done'] . ' / ' . $offP['total']) ?></h3></div><p class="hint"><?= tt('hr.off_hint') ?></p>
        <form method="post" action="<?= url('hr_person', ['id' => $pid, 'tab' => 'emp']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_off_save"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="employment_id" value="<?= (int)$e['id'] ?>">
        <table class="tbl"><tbody><?php foreach ($offRows as $o): ?><tr<?= $o['done'] ? ' class="is-done"' : '' ?>><td style="width:28px;"><label class="chk" style="margin:0;"><input type="checkbox" name="done[<?= e($o['item_key']) ?>]" value="1"<?= $o['done'] ? ' checked' : '' ?>></label></td><td><?= e(t('hr.off_' . $o['item_key'])) ?><?= $o['done'] ? '<br><span class="hint">' . e((string)$o['by_name']) . ' · ' . e(fmt_datetime($o['done_at'])) . '</span>' : '' ?></td><td style="width:38%;"><input name="onote[<?= e($o['item_key']) ?>]" maxlength="300" value="<?= e((string)$o['note']) ?>" placeholder="<?= tt('hr.off_note_ph') ?>" style="width:100%;"></td></tr><?php endforeach; ?></tbody></table>
        <div class="btn-row" style="margin-top:6px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div></form></div>
      <?php endif; ?>
      <?php foreach ($docsByEmp[(int)$e['id']] ?? [] as $d): ?><br><a href="<?= url('hr_pdoc', ['id' => (int)$d['id']]) ?>" target="_blank">📎 <?= e($d['name']) ?></a> <span class="hint"><?= e(t('hr.dk_' . $d['kind'])) ?></span><?php endforeach; ?>
      <?php if ((int)$e['id'] === $lastId && empty($docsByEmp[$lastId])): ?> <form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" class="inline" data-lock data-confirm="<?= tt('hr.ev_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_emp_delete"><input type="hidden" name="person_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('btn.delete') ?>">×</button></form><?php endif; ?></li>
  <?php endforeach; ?>
  </ol>
  <?php endif; ?>
  <h3 style="margin-top:14px;"><?= $open ? tt('hr.ev_new_open') : tt('hr.ev_new_closed') ?></h3>
  <form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_emp_event"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('hr.event') ?></label><select name="ev" id="hr_ev" data-nosearch><?php if (!$open): ?><option value="hire"><?= e(t($emps ? 'hr.ev_rehire' : 'hr.ev_hire')) ?></option><?php else: ?><option value="change"><?= tt('hr.ev_change') ?></option><option value="end"><?= tt('hr.ev_end') ?></option><?php endif; ?></select></div>
      <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="date" required value="<?= date('Y-m-d') ?>"></div>
      <div class="field hr-ct"><label><?= tt('hr.contract_type') ?></label><select name="contract_type" data-nosearch><?php foreach (HR_CONTRACTS as $c): ?><option value="<?= $c ?>"<?= $sel($c, $p['contract_type']) ?>><?= e(t('hr.ct_' . $c)) ?></option><?php endforeach; ?></select><label class="chk" style="margin-top:4px;font-size:12px;"><input type="checkbox" name="part_time" value="1"<?= $p['part_time'] ? ' checked' : '' ?>> <?= tt('hr.part_time') ?></label></div>
      <div class="field hr-ct"><label><?= tt('hr.contract_end') ?></label><input type="date" name="contract_end" value="<?= e($p['contract_end'] ?? '') ?>"></div>
      <div class="field hr-ct"><label><?= tt('hr.job_title') ?></label><input name="job_title" maxlength="120" value="<?= e($p['job_title'] ?? '') ?>"></div>
      <div class="field hr-end" style="display:none;"><label><?= tt('hr.end_reason') ?></label><select name="reason" data-nosearch><?php foreach (HR_END_REASONS as $r): ?><option value="<?= $r ?>"><?= e(t('hr.er_' . $r)) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('hr.end_hint') ?></p></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.notes') ?></label><input name="note" maxlength="500"></div>
      <div class="field"><label><?= tt('hr.documents') ?></label><input type="file" name="doc[]" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><p class="hint"><?= tt('hr.docs_hint') ?></p></div>
      <div class="field"><label><?= tt('hr.doc_kind') ?></label><select name="doc_kind" data-nosearch><?php foreach (HR_DOC_KINDS as $k): ?><option value="<?= $k ?>"<?= $k === 'contract' ? ' selected' : '' ?>><?= e(t('hr.dk_' . $k)) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php endif; ?>
</div>
<?php endif; ?>

<?php if ($tab === 'req'): $reqs = array_values(array_filter(hr_requests_for('hr', 0), fn($r) => (int)$r['person_id'] === $pid)); $openR = array_filter($reqs, fn($r) => in_array($r['status'], ['pending_mgr', 'pending_hr'], true)); $recent = array_slice(array_filter($reqs, fn($r) => !in_array($r['status'], ['pending_mgr', 'pending_hr'], true)), 0, 8); $fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="card" id="hr-req">
  <div class="card-head"><h2>✅ <?= tt('hr.requests') ?> <?= $openR ? pill('gold', count($openR) . ' ' . t('hr.f_open')) : '' ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('hr_requests', ['pid' => $pid, 'year' => 0, 'st' => 'all']) ?>">📜 <?= tt('hr.history') ?> ›</a></div>
  <?php if (!$openR && !$recent): ?><p class="hint"><?= tt('hr.no_requests2') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th class="num"><?= tt('hr.days') ?> / h</th><th><?= tt('hr.reason') ?></th><th class="num"><?= tt('hr.available') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.decision') ?></th></tr></thead><tbody>
  <?php foreach (array_merge(array_values($openR), $recent) as $r): $open1 = in_array($r['status'], ['pending_mgr', 'pending_hr'], true); $b = hr_balance($p, (int)$r['type_id'], (int) substr($r['date_from'], 0, 4)); ?>
  <tr<?= $open1 ? ' style="background:#FFFBEA;"' : '' ?>><td><b><?= e($r['code']) ?></b> <?= e(hr_type((int)$r['type_id'])['name'] ?? $r['type_name']) ?><?= $r['doc_path'] ? ' <a href="' . url('hr_doc', ['id' => (int)$r['id']]) . '" target="_blank" title="' . e($r['doc_name']) . '">📎</a>' : '' ?></td><td><?= e(fmt_date($r['date_from'])) ?></td><td><?= e(fmt_date($r['date_to'])) ?></td><td class="num"><?= $r['unit'] === 'hour' ? e($fh($r['hours'])) . ' h' : e($fh($r['days'])) ?></td><td class="hint"><?= e($r['reason'] ?? '') ?></td><td class="num<?= $b['available'] !== null && $b['available'] < 0 ? ' bad' : '' ?>"><?= $b['available'] !== null ? e($fh($b['available'])) . ($b['unit'] === 'hour' ? ' h' : '') : '—' ?></td><td><?= hr_status_pill($r['status']) ?></td>
    <td><?php if ($open1): ?><form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" data-lock style="display:flex;gap:4px;flex-wrap:wrap;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_decide"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back_person" value="<?= $pid ?>"><input name="note" maxlength="300" placeholder="<?= tt('hr.note_ph') ?>" style="width:130px;"><?php if ($r['status'] === 'pending_hr'): ?><input name="efka" maxlength="120" placeholder="<?= tt('hr.efka_ph') ?>" style="width:110px;"><?php endif; ?><button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve">✔ <?= tt('hr.approve') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" data-js-confirm="<?= tt('hr.reject_confirm') ?>">✖ <?= tt('hr.reject') ?></button></form>
      <?php else: ?><span class="hint"><?= $r['manager_at'] ? e($r['manager_name'] ?? '') . ' ' . e(fmt_date($r['manager_at'])) . '<br>' : '' ?><?= $r['hr_at'] ? 'HR ' . e($r['hr_name'] ?? '') . ' ' . e(fmt_date($r['hr_at'])) . ($r['hr_note'] ? ' — ' . e($r['hr_note']) : '') : '' ?></span><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($tab === 'msg' && function_exists('hr_msg_has') && hr_msg_has()) echo hr_messages_card($pid, true); ?>
<?php if ($tab === 'docs'): ?>
<div class="card" id="hr-docs">
  <h2>📎 <?= tt('hr.documents') ?> <span class="hint">(<?= count($docs) ?>)</span></h2>
  <?php if ($docs): ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('hr.doc_kind') ?></th><th><?= tt('hr.document') ?></th><th><?= tt('hr.event') ?></th><th><?= tt('hr.notes') ?></th><th><?= tt('ll.who') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($docs as $d): $ev = null; foreach ($emps as $e) if ((int)$e['id'] === (int)$d['employment_id']) $ev = $e; ?><tr><td class="hint"><?= e(fmt_date($d['created_at'])) ?></td><td><?= e(t('hr.dk_' . $d['kind'])) ?></td><td><a href="<?= url('hr_pdoc', ['id' => (int)$d['id']]) ?>" target="_blank">📎 <?= e($d['name']) ?></a> <span class="hint"><?= e(number_format((int)$d['size'] / 1024, 0)) ?> KB</span></td><td class="hint"><?= $ev ? e(t('hr.ev_' . $ev['kind']) . ' ' . fmt_date($ev['date_from'])) : '—' ?></td><td class="hint"><?= e($d['note'] ?? '') ?></td><td class="hint"><?= e($d['by_name'] ?? '') ?></td>
    <td class="row-actions"><form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" class="inline" data-lock data-confirm="<?= tt('hr.doc_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_doc_delete"><input type="hidden" name="person_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="hint"><?= tt('hr.no_docs') ?></p><?php endif; ?>
  <form method="post" action="<?= url('hr_person', ['id' => $pid]) ?>" enctype="multipart/form-data" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_doc_upload"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="grid grid-4"><div class="field"><label><?= tt('hr.documents') ?></label><input type="file" name="doc[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"></div><div class="field"><label><?= tt('hr.doc_kind') ?></label><select name="doc_kind" data-nosearch><?php foreach (HR_DOC_KINDS as $k): ?><option value="<?= $k ?>"><?= e(t('hr.dk_' . $k)) ?></option><?php endforeach; ?></select></div><div class="field"><label><?= tt('hr.event') ?></label><select name="employment_id" data-nosearch><option value="">—</option><?php foreach ($emps as $e): ?><option value="<?= (int)$e['id'] ?>"><?= e(t('hr.ev_' . $e['kind']) . ' ' . fmt_date($e['date_from'])) ?></option><?php endforeach; ?></select></div><div class="field"><label><?= tt('hr.notes') ?></label><div style="display:flex;gap:6px;"><input name="note" maxlength="200"><button class="btn btn--gold btn--sm" type="submit">⇪</button></div></div></div>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'ent' && function_exists('hr4_has') && hr4_has()): $ann = hr_annual_type_id(); $ey = (int)($_GET['year'] ?? date('Y')); $y0 = (int) substr((string)($emps[0]['date_from'] ?? $p['hire_date'] ?? $year), 0, 4) ?: $year; $years = range(min($y0, $ey), max($year + 1, $ey)); $fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="card" id="hr-ent">
  <h2>🗓 <?= tt('hr.entitlements') ?> — <?= e($p['name_en']) ?></h2>
  <p class="hint"><?= tt('hr.ent_card_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.year') ?></th><th><?= tt('hr.statutory') ?></th><th><?= tt('hr.basis') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th><?= tt('hr.mode') ?></th><th class="num"><?= tt('hr.carry') ?></th><th class="num"><?= tt('hr.opening') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.available') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($years as $yy): if (!$ann) break; $st = hr_statutory($p, $yy); if (!$st && $yy !== $ey) continue; $b = hr_balance($p, $ann, $yy); $row = one('SELECT * FROM hr_entitlements WHERE year = ? AND type_id = ? AND (person_id = ? OR (user_id = ? AND ? > 0)) LIMIT 1', [$yy, $ann, $pid, (int)($p['user_id'] ?? 0), (int)($p['user_id'] ?? 0)]); ?>
  <tr<?= $yy === $ey ? ' style="background:#FFFBEA;"' : '' ?>><td><b><?= $yy ?></b></td><td class="num"><?= $st ? e($fh($st['days'])) : '—' ?></td><td class="hint"><?= e(hr_basis_text($st)) ?></td><td class="num"><b><?= e($fh($b['entitled'])) ?></b></td><td><?= $b['mode'] === 'auto' ? pill('green', '🔒 ' . t('hr.mode_auto')) : ($b['mode'] === 'manual' ? pill('gold', '🔓 ' . t('hr.mode_manual')) . ($row && $row['override_note'] ? ' <span class="hint">' . e($row['override_note']) . '</span>' : '') : pill('mute', t('hr.mode_none'))) ?></td><td class="num"><?= e($fh($b['carry'])) ?></td><td class="num"><?= e($fh($b['opening'])) ?></td><td class="num"><?= e($fh($b['taken'])) ?><?= $b['pending'] > 0 ? ' <span class="hint">(+' . e($fh($b['pending'])) . ')</span>' : '' ?></td><td class="num<?= $b['available'] !== null && $b['available'] < 0 ? ' bad' : '' ?>"><b><?= e($fh($b['available'])) ?></b></td>
    <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => $yy]) ?>"><?= tt('btn.edit') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if (function_exists('hr_carry_transfer') && $ann): $pb = hr_balance($p, $ann, $ey - 1); ?>
  <form method="post" action="<?= url('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => $ey]) ?>" data-lock style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_carry_transfer"><input type="hidden" name="person_id" value="<?= $pid ?>"><input type="hidden" name="year" value="<?= $ey ?>">
    <div class="field" style="margin:0;"><label><?= e(t('hr.carry_from', ['y' => $ey - 1])) ?></label><input name="days" inputmode="decimal" value="<?= e($fh(max(0.0, (float)($pb['available'] ?? 0)))) ?>" style="width:90px;"></div>
    <div class="field" style="margin:0;flex:1;min-width:200px;"><label><?= tt('hr.override_note') ?></label><input name="note" maxlength="200" placeholder="<?= tt('hr.carry_ph') ?>"></div>
    <button class="btn btn--ghost btn--sm" type="submit">⇢ <?= tt('hr.carry_btn') ?></button><span class="hint"><?= e(t('hr.carry_hint', ['y' => $ey - 1, 'a' => $fh($pb['available'] ?? 0)])) ?></span>
  </form>
  <?php endif; ?>
  <?php if (function_exists('hr_statutory_type')): $tsel = (int)($_GET['tid'] ?? $ann); ?>
  <h3 style="margin-top:16px;"><?= e(t('hr.allowances_title', ['y' => $ey])) ?></h3>
  <p class="hint"><?= tt('hr.allowances_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.type') ?></th><th class="num"><?= tt('hr.statutory') ?></th><th><?= tt('hr.basis') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th><?= tt('hr.mode') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.available') ?></th><th></th></tr></thead><tbody>
  <?php foreach (hr_types() as $tid2 => $t2): if (($t2['rule_key'] ?? '') === '' || $tid2 === $ann) continue; if (function_exists('hr_type_enabled') && !hr_type_enabled($p, $t2)) { echo '<tr class="is-retired"><td><b>' . e($t2['code']) . '</b> ' . e($t2['name']) . '</td><td colspan="7" class="hint">' . tt('hr.type_off_s') . ' — <a href="' . url('hr_person', ['id' => $pid, 'tab' => 'types']) . '">' . tt('hr.types') . '</a></td></tr>'; continue; } $st2 = hr_statutory_type($p, $t2, $ey); $b2 = hr_balance($p, $tid2, $ey); $un = $t2['unit'] === 'hour' ? ' h' : ''; if (!$st2 && $b2['mode'] === 'none' && $b2['taken'] <= 0) continue; ?>
  <tr<?= $tid2 === $tsel ? ' style="background:#FFFBEA;"' : '' ?>><td><b><?= e($t2['code']) ?></b> <?= e($t2['name']) ?></td><td class="num"><?= $st2 ? e($fh($st2['days'])) . $un : '—' ?></td><td class="hint"><?= e(hr_basis_text($st2)) ?></td><td class="num"><b><?= e($fh($b2['entitled'])) . $un ?></b></td><td><?= $b2['mode'] === 'auto' ? pill('green', '🔒 ' . t('hr.mode_auto')) : ($b2['mode'] === 'manual' ? pill('gold', '🔓 ' . t('hr.mode_manual')) : pill('mute', '—')) ?></td><td class="num"><?= e($fh($b2['taken'])) . $un ?><?= $b2['pending'] > 0 ? ' <span class="hint">(+' . e($fh($b2['pending'])) . ')</span>' : '' ?></td><td class="num<?= $b2['available'] !== null && $b2['available'] < 0 ? ' bad' : '' ?>"><b><?= $b2['available'] === null ? '—' : e($fh($b2['available'])) . $un ?></b></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => $ey, 'tid' => $tid2]) ?>"><?= tt('btn.edit') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php $ann = $tsel ?: $ann; endif; ?>
  <?php $stY = function_exists('hr_statutory_type') && hr_type((int)$ann) ? hr_statutory_type($p, hr_type((int)$ann), $ey) : hr_statutory($p, $ey); $cur = hr_balance($p, $ann, $ey); ?>
  <h3 style="margin-top:16px;"><?= e(t('hr.override_title', ['y' => $ey])) ?> — <?= e(hr_type((int)$ann)['code'] ?? '') ?> <?= e(hr_type((int)$ann)['name'] ?? '') ?></h3>
  <form method="post" action="<?= url('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => $ey]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_ent_override"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="tid" value="<?= (int)$ann ?>"><input type="hidden" name="year" value="<?= $ey ?>">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('hr.mode') ?></label><select name="mode" id="hr_mode" data-nosearch><option value="auto"<?= $cur['mode'] !== 'manual' ? ' selected' : '' ?>>🔒 <?= tt('hr.mode_auto') ?><?= $stY ? ' (' . e($fh($stY['days'])) . ')' : '' ?></option><option value="manual"<?= $cur['mode'] === 'manual' ? ' selected' : '' ?>>🔓 <?= tt('hr.mode_manual') ?></option></select></div>
      <div class="field"><label><?= tt('hr.entitled') ?> (<?= tt('hr.days') ?>)</label><input name="days" inputmode="decimal" value="<?= e($fh($cur['entitled'])) ?>"></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('hr.override_note') ?></label><div style="display:flex;gap:6px;"><input name="note" maxlength="200" placeholder="<?= tt('hr.override_ph') ?>"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div></div>
    </div>
    <p class="hint"><?= tt('hr.override_hint') ?></p>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'types' && function_exists('hr5_has') && hr5_has()): $ov = hr_person_type_overrides($pid); ?>
<div class="card" id="hr-types-p">
  <h2>🏷 <?= tt('hr.types') ?> — <?= e($p['name_en']) ?></h2>
  <p class="hint"><?= tt('hr.ptypes_hint') ?></p>
  <form method="post" action="<?= url('hr_person', ['id' => $pid, 'tab' => 'types']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_person_types_save"><input type="hidden" name="pid" value="<?= $pid ?>">
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.available') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('hr.unit') ?></th><th><?= tt('hr.default') ?></th><th><?= tt('hr.override_note') ?></th></tr></thead><tbody>
    <?php foreach (hr_types() as $tid => $t): $def = hr_type_default_enabled($p, $t); $en = hr_type_enabled($p, $t); $o = $ov[$tid] ?? null; ?>
    <tr<?= $en ? '' : ' class="is-retired"' ?>><td><label class="chk" style="margin:0;"><input type="checkbox" name="en[<?= $tid ?>]" value="1"<?= $en ? ' checked' : '' ?>></label></td><td><b><?= e($t['code']) ?></b> <?= e($t['name']) ?><?= $o ? ' ' . pill('gold', t('hr.mode_manual')) : '' ?></td><td class="hint"><?= $t['unit'] === 'hour' ? tt('hr.hours') : tt('hr.days') ?></td><td class="hint"><?= $def ? '✔ ' . tt('hr.def_on') : '✖ ' . tt('hr.def_off') ?></td><td><?php if (in_array((string)($t['rule_key'] ?? ''), ['maternity', 'paternity'], true) && function_exists('hr6_has') && hr6_has()): ?><div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;"><span class="hint" style="white-space:nowrap;"><?= tt('hr.used_before') ?></span><input name="used_before[<?= $tid ?>]" type="number" step="0.5" min="0" value="<?= $o && $o['used_before'] !== null ? e((string)(0 + $o['used_before'])) : '' ?>" style="width:80px;" title="<?= tt('hr.used_before_hint') ?>"><span class="hint"><?= tt('hr.used_year') ?></span><input name="used_year[<?= $tid ?>]" type="number" min="1990" max="2100" value="<?= e((string)($o['used_year'] ?? '')) ?>" style="width:90px;" placeholder="<?= date('Y') ?>"></div><?php endif; ?><input name="note[<?= $tid ?>]" maxlength="200" value="<?= e($o['note'] ?? '') ?>" placeholder="<?= tt('hr.ptype_note_ph') ?>" style="width:100%;"></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('hr.ptypes_save_hint') ?></span></div>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'train' && function_exists('hr_tr_has') && hr_tr_has()): $myTr = hr_person_trainings($pid); $part = hr_participation($pid); $fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); ?>
<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat navy"><b><?= (int)$part['trainings'] ?></b><span class="s"><?= tt('hr.tr_attended') ?></span></span>
  <span class="stat"><b><?= e($fh($part['hours'])) ?></b><span class="s"><?= tt('hr.tr_hours') ?></span></span>
  <span class="stat gold"><b><?= (int)$part['certificates'] ?></b><span class="s"><?= tt('hr.tr_certificates') ?></span></span>
  <span class="stat green"><b><?= (int)$part['evaluations'] ?></b><span class="s"><?= tt('hr.evaluations') ?></span></span>
</div>
<div class="card">
  <h2>🎓 <?= tt('hr.tr_record') ?></h2>
  <p class="hint"><?= tt('hr.tr_record_hint') ?> <a href="<?= url('hr_training') ?>"><?= tt('hr.training') ?> ›</a></p>
  <?php if (!$myTr): ?><div class="empty"><?= tt('hr.tr_none') ?></div><?php else: ?>
  <?php foreach ($myTr as $m): ?><form id="cf<?= (int)$m['id'] ?>" method="post" action="<?= url('hr_person', ['id' => $pid, 'tab' => 'train']) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_trm_save"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="back" value="card"></form><?php endforeach; ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tr_title') ?></th><th><?= tt('hr.tr_kind') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.tr_hours') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.tr_score') ?></th><th><?= tt('hr.tr_certificate') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($myTr as $m): $fid = 'cf' . (int)$m['id']; ?><tr>
    <td><a href="<?= url('hr_training', ['id' => (int)$m['training_id']]) ?>"><b><?= e($m['title']) ?></b></a><?= $m['provider'] ? '<br><span class="hint">' . e($m['provider']) . '</span>' : '' ?></td><td><?= hr_tr_kind_pill($m['kind']) ?></td>
    <td class="hint"><?= $m['date_from'] ? e(fmt_date($m['date_from'])) . ($m['date_to'] && $m['date_to'] !== $m['date_from'] ? ' – ' . e(fmt_date($m['date_to'])) : '') : '—' ?></td><td class="num"><?= e($fh($m['hours'])) ?></td>
    <td><select form="<?= $fid ?>" name="status" data-nosearch style="min-width:120px;"><?php foreach (HR_TRM_STATUS as $s): ?><option value="<?= $s ?>"<?= $m['status'] === $s ? ' selected' : '' ?>><?= e(t('hr.tm_' . $s)) ?></option><?php endforeach; ?></select></td>
    <td><input form="<?= $fid ?>" name="score" value="<?= e((string)$m['score']) ?>" maxlength="20" style="width:80px;"></td>
    <td><?php if ($m['certificate']): ?><div style="display:grid;gap:4px;"><input form="<?= $fid ?>" name="cert_no" value="<?= e((string)$m['cert_no']) ?>" maxlength="60" placeholder="<?= tt('hr.tr_cert_no') ?>" style="width:140px;"><div style="display:flex;gap:4px;"><input form="<?= $fid ?>" type="date" name="cert_issued" value="<?= e((string)$m['cert_issued']) ?>" title="<?= tt('hr.tr_cert_issued') ?>" style="width:135px;"><input form="<?= $fid ?>" type="date" name="cert_expiry" value="<?= e((string)$m['cert_expiry']) ?>" title="<?= tt('hr.tr_cert_expiry') ?>" style="width:135px;"></div><input form="<?= $fid ?>" type="file" name="doc[]" accept=".pdf,.jpg,.jpeg,.png"><?= $m['cert_doc_id'] ? '<a class="hint" href="' . url('hr_pdoc', ['id' => (int)$m['cert_doc_id']]) . '" target="_blank">📎 ' . e((string)$m['doc_name']) . '</a>' : '' ?></div><?php else: ?><span class="hint">—</span><?php endif; ?></td>
    <td class="row-actions"><input form="<?= $fid ?>" type="hidden" name="note" value="<?= e((string)$m['note']) ?>"><button class="btn btn--ghost btn--sm" type="submit" form="<?= $fid ?>"><?= tt('btn.save') ?></button></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($tab === 'eval' && function_exists('hr_tr_has') && hr_tr_has()): $evUrl = fn(int $y) => url('hr_person', ['id' => $pid, 'tab' => 'eval', 'year' => $y]); require __DIR__ . '/_hr_eval.php'; endif; ?>
<?php if ($tab === 'log' && function_exists('hr4_has') && hr4_has()): $log = hr_audit_list($pid); ?>
<div class="card" id="hr-log">
  <h2>🕘 <?= tt('hr.changes') ?></h2>
  <p class="hint"><?= tt('hr.changes_hint') ?></p>
  <?php if (!$log): ?><p class="hint">—</p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('hr.change_kind') ?></th><th><?= tt('hr.change_field') ?></th><th><?= tt('hr.change_old') ?></th><th><?= tt('hr.change_new') ?></th><th><?= tt('hr.notes') ?></th></tr></thead><tbody>
  <?php foreach ($log as $a): ?><tr><td class="hint"><?= e(fmt_datetime($a['created_at'])) ?></td><td><?= e($a['by_name'] ?? '—') ?></td><td><?= e(t('hr.ck_' . $a['kind'])) ?></td><td><?= e($a['field'] ?? '') ?></td><td class="hint"><?= e($a['old_value'] ?? '') ?></td><td><b><?= e($a['new_value'] ?? '') ?></b></td><td class="hint"><?= e($a['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('hr_ev');if(!s)return;var f=s.closest('form');function ui(){var end=s.value==='end';f.querySelectorAll('.hr-ct').forEach(function(x){x.style.display=end?'none':'';});f.querySelectorAll('.hr-end').forEach(function(x){x.style.display=end?'':'none';});var k=f.querySelector('[name=doc_kind]');if(k)k.value=end?'termination':'contract';}s.addEventListener('change',ui);ui();})();</script>
<?php layout_foot(); ?>

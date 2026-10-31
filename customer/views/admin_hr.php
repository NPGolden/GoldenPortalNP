<?php
/** Settings › HR — leave types and yearly entitlements per person (GAS-CP-R51-001). Administrators and HR-scoped accounts. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr_can') || !hr_can()) { http_response_code(403); layout_head(t('hr.settings'), 'admin_hr'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$year = (int)($_GET['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$r54 = function_exists('hr2_has') && hr2_has(); $tab = (string)($_GET['tab'] ?? 'ent'); if (!$r54 || !in_array($tab, ['people', 'ent', 'types', 'import', 'rules'], true)) $tab = $r54 ? 'ent' : 'ent';
$types = hr_types(); $allTypes = hr_types(false); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$people = $r54 ? hr_people(false) : []; $staff = $r54 ? array_map(fn($p) => ['id' => (int)$p['id'], 'full_name' => $p['name_en'], 'discipline' => $p['department'] ?: ($p['discipline'] ?? ''), 'uid' => (int)$p['user_id'], 'p' => $p, 'active' => (int)$p['is_active']], array_filter($people, fn($p) => $p['is_active'])) : hr_staff();
$E = hr_entitlements($year, $r54); $accounts = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name");
$fd = fn($v) => $v === null || $v === '' ? '' : (string)$v; $ml = $r54 && hr_has_names();
$fh = fn($v) => $v === null || (float)$v == 0 ? '' : rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
layout_head(t('hr.settings'), 'admin_hr');
?>
<?php if (is_cp_admin()) settings_tabs('admin_hr'); ?>
<div class="page-head"><div><h2 class="settings-h">🏖 <?= tt('hr.settings') ?></h2><p class="lede" style="margin-bottom:0;"><?= tt('hr.settings_lede') ?></p></div><div><?php if ($r54): ?><a class="btn btn--gold btn--sm" href="<?= url('hr_dashboard', ['year' => $year]) ?>">📊 <?= tt('hr.dashboard') ?> ›</a> <?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['hy' => $year]) ?>#hr-report">🗓 <?= tt('pl.title') ?> ›</a></div></div>

<style nonce="<?= csp_nonce() ?>">.hr-ml{display:grid;grid-template-columns:1fr 1fr;gap:3px 6px;margin-top:4px}.hr-ml label{display:flex;align-items:center;gap:4px;margin:0;font-size:11px}.hr-ml label span{width:18px;color:var(--mute)}.hr-ml input{font-size:12px;padding:2px 5px;min-width:0}</style>
<?php if ($r54): $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('admin_hr', ['tab' => $k, 'year' => $year]) . '">' . $lbl . '</a>'; ?>
<nav class="tabs" style="margin-bottom:12px;"><?= $T('people', '👥 ' . t('hr.people')) . $T('ent', '🗓 ' . t('hr.entitlements')) . $T('types', '🏷 ' . t('hr.types')) . (function_exists('hr4_has') && hr4_has() ? $T('rules', '⚖ ' . t('hr.rules_tab')) : '') . $T('import', '⇪ ' . t('hr.import')) ?></nav>

<?php if ($tab === 'people'): $pid = (int)($_GET['pid'] ?? 0); $cur = $pid ? hr_person($pid) : null; ?>
<?php if (function_exists('hr3_has') && hr3_has() && ($al = hr_contract_alerts())): ?><div class="flash flash--warn"><b><?= tt('hr.contract_alerts') ?>:</b> <?php foreach ($al as $i => $a): ?><?= $i ? ' · ' : '' ?><a href="<?= url('hr_person', ['id' => (int)$a['id']]) ?>"><?= e($a['name_en']) ?></a> <?= e(fmt_date($a['contract_end'])) ?><?= (int)$a['left_days'] < 0 ? ' ⚠' : '' ?><?php endforeach; ?></div><?php endif; ?>
<div class="cols">
<div class="card" id="hr-people">
  <h2><?= tt('hr.people') ?> <span class="hint">(<?= count(array_filter($peopleAll ?? $people, fn($p) => $p['is_active'])) ?>)</span></h2>
  <p class="hint"><?= tt('hr.people_hint') ?></p>
  <?php $fq = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $fd = (int)($_GET['fd'] ?? 0); $fs = (string)($_GET['fs'] ?? 'active'); $fk = (string)($_GET['fk'] ?? ''); $fj = (string)($_GET['fj'] ?? ''); $ft = (string)($_GET['ft'] ?? ''); $fc = (string)($_GET['fc'] ?? ''); $fa = (string)($_GET['fa'] ?? '');
  $peopleAll = $people; $people = array_values(array_filter($peopleAll, function ($p) use ($fq, $fd, $fs, $fk, $fj, $ft, $fc, $fa) {
      if ($fs === 'active' && !$p['is_active']) return false; if ($fs === 'left' && $p['is_active']) return false;
      if ($fd && (int)$p['department_id'] !== $fd) return false; if ($fk !== '' && ($p['employment_kind'] ?? 'employee') !== $fk) return false; if ($fj !== '' && ($p['jurisdiction'] ?? 'GR') !== $fj) return false;
      if ($ft === 'tech' && !$p['is_technical']) return false; if ($ft === 'other' && $p['is_technical']) return false; if ($fc !== '' && ($p['contract_type'] ?? 'indefinite') !== $fc) return false;
      if ($fa === 'yes' && !$p['user_id']) return false; if ($fa === 'no' && $p['user_id']) return false;
      if ($fq !== '' && !str_contains(mb_strtolower(($p['name_en'] ?? '') . ' ' . ($p['name_el'] ?? '') . ' ' . ($p['emp_code'] ?? '') . ' ' . ($p['email'] ?? '') . ' ' . ($p['job_title'] ?? '')), $fq)) return false;
      return true; })); $hasF = $fq !== '' || $fd || $fs !== 'active' || $fk !== '' || $fj !== '' || $ft !== '' || $fc !== '' || $fa !== ''; ?>
  <form method="get" action="index.php" class="hr-filters"><input type="hidden" name="p" value="admin_hr"><input type="hidden" name="tab" value="people">
    <input name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= tt('hr.f_search') ?>" style="min-width:180px;">
    <select name="fd" data-nosearch data-js-submit="1"><option value="0"><?= tt('hr.f_all_depts') ?></option><?php foreach (hr_departments() as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $fd === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
    <select name="fs" data-nosearch data-js-submit="1"><option value="active"<?= $fs === 'active' ? ' selected' : '' ?>><?= tt('hr.f_active') ?></option><option value="left"<?= $fs === 'left' ? ' selected' : '' ?>><?= tt('hr.f_left') ?></option><option value="all"<?= $fs === 'all' ? ' selected' : '' ?>><?= tt('hr.f_all') ?></option></select>
    <select name="ft" data-nosearch data-js-submit="1"><option value=""><?= tt('hr.f_tech_all') ?></option><option value="tech"<?= $ft === 'tech' ? ' selected' : '' ?>><?= tt('hr.technical') ?></option><option value="other"<?= $ft === 'other' ? ' selected' : '' ?>><?= tt('hr.f_nontech') ?></option></select>
    <?php if (function_exists('hr_jurisdiction')): ?><select name="fk" data-nosearch data-js-submit="1"><option value=""><?= tt('hr.f_kind_all') ?></option><option value="employee"<?= $fk === 'employee' ? ' selected' : '' ?>><?= tt('hr.ek_employee') ?></option><option value="contractor"<?= $fk === 'contractor' ? ' selected' : '' ?>><?= tt('hr.ek_contractor') ?></option></select>
    <select name="fj" data-nosearch data-js-submit="1"><option value=""><?= tt('hr.f_country_all') ?></option><?php foreach (HR_JURISDICTIONS as $jj): ?><option value="<?= $jj ?>"<?= $fj === $jj ? ' selected' : '' ?>><?= e(t('hr.jur_' . $jj)) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if (defined('HR_CONTRACTS')): ?><select name="fc" data-nosearch data-js-submit="1"><option value=""><?= tt('hr.f_contract_all') ?></option><?php foreach (HR_CONTRACTS as $c): ?><option value="<?= $c ?>"<?= $fc === $c ? ' selected' : '' ?>><?= e(t('hr.ct_' . $c)) ?></option><?php endforeach; ?></select><?php endif; ?>
    <select name="fa" data-nosearch data-js-submit="1"><option value=""><?= tt('hr.f_account_all') ?></option><option value="yes"<?= $fa === 'yes' ? ' selected' : '' ?>><?= tt('hr.f_with_account') ?></option><option value="no"<?= $fa === 'no' ? ' selected' : '' ?>><?= tt('hr.f_no_account') ?></option></select>
    <button class="btn btn--ghost btn--sm" type="submit">🔍</button><?php if ($hasF): ?> <a class="btn btn--ghost btn--sm" href="<?= url('admin_hr', ['tab' => 'people']) ?>">× <?= tt('hr.f_clear') ?></a><?php endif; ?>
    <span class="hint"><?= e(t('hr.f_count', ['n' => count($people), 't' => count($peopleAll)])) ?></span>
  </form>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('hr.name_el') ?></th><th><?= tt('hr.department') ?></th><th><?= tt('hr.manager') ?></th><th><?= tt('hr.hire_date') ?></th><th class="num"><?= tt('hr.service') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($people as $p): $mgr = hr_manager_of($p); ?><tr<?= $p['is_active'] ? '' : ' class="is-retired"' ?>><td><?= !empty($p['emp_code']) ? '<span class="hint">' . e($p['emp_code']) . '</span> ' : '' ?><b><?= e($p['name_en']) ?></b><?= $p['is_technical'] ? ' ' . pill('blue', t('hr.technical')) : '' ?><?= $p['user_id'] ? '' : ' ' . pill('mute', t('hr.no_account')) . (is_cp_admin() ? ' <a class="btn btn--gold btn--sm" target="_blank" rel="noopener" href="' . url('admin_users', ['new' => 1, 'hr_pid' => (int)$p['id'], 'full_name' => $p['name_en'], 'role' => 'staff', 'discipline_id' => (int)($p['department_id'] ?? $p['discipline_id'] ?? 0) ?: '']) . '#au-add" title="' . tt('hr.create_account_hint') . '">➕ ' . tt('hr.create_account') . '</a>' : '') ?><?= function_exists('hr_msg_has') && hr_msg_has() && ($um = hr_messages_unread((int)$p['id'])) ? ' ' . pill('gold', '💬 ' . $um) : '' ?><?= !empty($p['job_title']) ? '<br><span class="hint">' . e($p['job_title']) . '</span>' : '' ?><?= !empty($p['contract_end']) && $p['is_active'] ? '<br><span class="hint">' . tt('hr.contract_end') . ' ' . e(fmt_date($p['contract_end'])) . '</span>' : '' ?></td><td class="hint"><?= e($p['name_el'] ?? '') ?></td><td><?= e($p['department'] ?? '') ?><?= $p['is_technical'] && $p['discipline'] ? ' <span class="hint">' . ($p['department'] ? '· ' : '') . e($p['discipline']) . '</span>' : '' ?></td><td class="hint"><?= $mgr ? e((string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$mgr])) : '—' ?></td><td><?= $p['hire_date'] ? e(fmt_date($p['hire_date'])) : '—' ?></td><td class="num"><?php $sv = function_exists('hr3_has') && hr3_has() ? hr_service($p) : null; echo $sv ? e(hr_fmt_months($sv['total'])) . ($sv['prior'] ? ' <span class="hint">(' . e(hr_fmt_months($sv['prior'])) . ' ' . tt('hr.svc_prior_s') . ')</span>' : '') : ($p['hire_date'] || $p['prior_months'] ? e(t('hr.months_n', ['n' => hr_service_months($p)])) : '—'); ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hr_requests', ['pid' => (int)$p['id'], 'year' => 0, 'st' => 'all']) ?>" title="<?= tt('hr.history') ?>">📜</a> <a class="btn btn--ghost btn--sm" href="<?= function_exists('hr3_has') && hr3_has() ? url('hr_person', ['id' => (int)$p['id']]) : url('admin_hr', ['tab' => 'people', 'year' => $year, 'pid' => (int)$p['id']]) . '#hr-person' ?>"><?= function_exists('hr3_has') && hr3_has() ? '🪪 ' . tt('hr.card') : tt('btn.edit') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php $people = $peopleAll; ?>
<div class="card" id="hr-person">
  <h2><?= $cur ? tt('hr.person_edit') . ' — ' . e($cur['name_en']) : '+ ' . tt('hr.person_add') ?></h2>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'people', 'year' => $year]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_person_save"><input type="hidden" name="id" value="<?= (int)($cur['id'] ?? 0) ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('hr.name_en') ?></label><input name="name_en" required maxlength="120" value="<?= e($cur['name_en'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.name_el') ?></label><input name="name_el" maxlength="120" value="<?= e($cur['name_el'] ?? '') ?>" placeholder="ΕΠΩΝΥΜΟ ΟΝΟΜΑ"><p class="hint"><?= tt('hr.name_el_hint') ?></p></div>
      <div class="field"><label><?= tt('hr.account') ?></label><select name="user_id"><option value="">— <?= tt('hr.no_account') ?> —</option><?php foreach ($accounts as $a): ?><option value="<?= (int)$a['id'] ?>"<?= $sel($a['id'], $cur['user_id'] ?? '') ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.department') ?></label><select name="department_id" data-nosearch><option value=""></option><?php foreach (hr_departments() as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $sel($d['id'], $cur['department_id'] ?? '') ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.hire_date') ?></label><input type="date" name="hire_date" value="<?= e($cur['hire_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.prior_months') ?></label><input type="number" name="prior_months" min="0" max="600" value="<?= (int)($cur['prior_months'] ?? 0) ?>"><p class="hint"><?= tt('hr.prior_hint') ?></p></div>
      <div class="field"><label><?= tt('hr.week_days') ?></label><select name="week_days" data-nosearch><option value="5"<?= $sel(5, $cur['week_days'] ?? 5) ?>>5</option><option value="6"<?= $sel(6, $cur['week_days'] ?? 5) ?>>6</option></select></div>
      <div class="field"><label><?= tt('hr.children') ?></label><div style="display:flex;gap:6px;"><input type="number" name="children" min="0" max="12" value="<?= (int)($cur['children'] ?? 0) ?>" style="width:70px;"><input name="children_years" maxlength="60" value="<?= e($cur['children_years'] ?? '') ?>" placeholder="2015, 2019"></div><p class="hint"><?= tt('hr.children_hint') ?></p></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.notes') ?></label><input name="notes" maxlength="500" value="<?= e($cur['notes'] ?? '') ?>"></div>
      <div class="field" style="grid-column:1 / -1;display:flex;gap:16px;flex-wrap:wrap;"><label class="chk"><input type="checkbox" name="is_technical" value="1"<?= !empty($cur['is_technical']) ? ' checked' : '' ?>> <?= tt('hr.technical_lbl') ?></label><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $cur === null || $cur['is_active'] ? ' checked' : '' ?>> <?= tt('hr.active_lbl') ?></label></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($cur): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_hr', ['tab' => 'people', 'year' => $year]) ?>#hr-person"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
</div>
</div>
<div class="card" id="hr-people-import">
  <div class="card-head"><h2>⇪ <?= tt('hr.people_import') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('hr_people_template') ?>">⬇ <?= tt('hr.people_template') ?></a></div>
  <p class="hint"><?= tt('hr.people_import_hint') ?></p>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'people']) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_people_upload">
    <div class="grid grid-3"><div class="field"><label><?= tt('hr.import_file') ?></label><input type="file" name="file" accept=".xlsx" required></div><div class="field" style="display:flex;align-items:flex-end;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.import_read') ?></button></div></div>
  </form>
  <?php $pi = $_SESSION['hr_people_import'] ?? null; if (!empty($_GET['imp']) && $pi): ?>
  <h3 style="margin-top:16px;"><?= e(t('hr.people_preview', ['n' => count($pi)])) ?></h3>
  <?php if (function_exists('hr_personal_can') && !hr_personal_can() && array_filter($pi, fn($r) => !empty($r['personal']))): ?><div class="flash flash--warn"><?= tt('hr.imp_personal_skip') ?></div><?php endif; ?>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'people']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_people_apply">
    <?php if (array_filter($pi, fn($r) => !$r['department_id'] && $r['department'] !== '')): ?><p class="hint"><label class="chk"><input type="checkbox" name="create_depts" value="1"> <?= tt('hr.imp_create_depts') ?></label></p><?php endif; ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.name_en') ?></th><th><?= tt('hr.name_el') ?></th><th><?= tt('hr.account') ?></th><th><?= tt('hr.department') ?></th><th><?= tt('hr.hire_date') ?></th><th class="num"><?= tt('hr.prior_months') ?></th><th><?= tt('hr.action') ?></th></tr></thead><tbody>
    <?php foreach ($pi as $i => $r): $mp = $r['match'] ? hr_person((int)$r['match']) : null; ?><tr><td><b><?= e($r['name_en']) ?></b><?= $r['is_technical'] ? ' ' . pill('blue', t('hr.technical')) : '' ?><?= $r['warn'] ? '<br><span class="hint bad">' . e(implode(' · ', $r['warn'])) . '</span>' : '' ?></td><td class="hint"><?= e($r['name_el'] ?? '') ?></td><td class="hint"><?= $r['user_id'] ? '✔ ' . e($r['email']) : e($r['email'] ?? '—') ?></td><td><?= e($r['department']) ?></td><td><?= $r['hire_date'] ? e(fmt_date($r['hire_date'])) : '—' ?></td><td class="num"><?= $r['prior_months'] === null ? '<span class="hint" title="' . tt('hr.imp_keep') . '">=</span>' : (int)$r['prior_months'] ?></td>
      <td><select name="sel[<?= (int)$i ?>]" data-nosearch><option value="">— <?= tt('hr.skip') ?> —</option><option value="new"<?= $mp ? '' : ' selected' ?>>+ <?= tt('hr.new_person') ?></option><?php if ($mp): ?><option value="<?= (int)$mp['id'] ?>" selected><?= e(t('hr.update_person', ['p' => $mp['name_en']])) ?></option><?php endif; ?><optgroup label="<?= tt('hr.all_people') ?>"><?php foreach ($people as $pp): if ($mp && (int)$pp['id'] === (int)$mp['id']) continue; ?><option value="<?= (int)$pp['id'] ?>"><?= e(t('hr.update_person', ['p' => $pp['name_en']])) ?></option><?php endforeach; ?></optgroup></select></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.people_apply') ?></button><span class="hint"><?= tt('hr.people_apply_hint') ?></span></div>
  </form>
  <?php elseif (!empty($_GET['imp'])): ?><p class="flash flash--warn"><?= tt('hr.import_empty') ?></p><?php endif; ?>
</div>
<p class="hint" style="margin:0 0 12px;"><?= tt('hr.depts_moved') ?> <a href="<?= url('admin_disciplines') ?>"><?= tt('nav.disciplines') ?> ›</a></p>

<?php elseif ($tab === 'rules'): $jur = in_array($_GET['jur'] ?? '', HR_JURISDICTIONS, true) ? $_GET['jur'] : 'GR'; $R = hr_rules($jur); ?>
<div class="card" id="hr-rules">
  <div class="card-head"><h2>⚖ <?= tt('hr.rules_tab') ?></h2><nav class="tabs"><?php foreach (HR_JURISDICTIONS as $jj): ?><a class="tab<?= $jj === $jur ? ' is-on' : '' ?>" href="<?= url('admin_hr', ['tab' => 'rules', 'jur' => $jj]) ?>"><?= e(t('hr.jur_' . $jj)) ?></a><?php endforeach; ?></nav></div>
  <p class="hint"><?= tt('hr.rules_hint') ?> <?= tt('hr.rules_jur_hint') ?></p>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'rules', 'jur' => $jur]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_rules_save"><input type="hidden" name="jur" value="<?= $jur ?>">
    <?php foreach (HR_RULE_GROUPS as $gk => $keys): ?><h3 style="margin-top:12px;"><?= e(t($gk)) ?></h3>
    <div class="table-scroll"><table class="tbl" style="max-width:820px;"><tbody>
    <?php $done = []; foreach ($keys as $k): if (isset($done[$k])) continue; $pair = null; if (str_ends_with($k, '_5') && in_array(substr($k, 0, -2) . '_6', $keys, true)) $pair = substr($k, 0, -2) . '_6'; if ($k === 'mar_days') $pair = 'mar_days_6'; if ($pair) $done[$pair] = 1; $done[$k] = 1; ?>
      <tr><td><?= e(t(HR_RULE_LABELS[$k])) ?></td><td class="num" style="white-space:nowrap;"><input name="r[<?= $k ?>]" inputmode="decimal" value="<?= e((string)$R[$k]) ?>" style="width:64px;text-align:right;"><?= $pair ? ' <span class="hint">5 ' . tt('hr.days_week') . '</span> &nbsp; <input name="r[' . $pair . ']" inputmode="decimal" value="' . e((string)$R[$pair]) . '" style="width:64px;text-align:right;"> <span class="hint">6 ' . tt('hr.days_week') . '</span>' : '' ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endforeach; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('hr.rules_save_hint') ?></span></div>
  </form>
  <h3 style="margin-top:16px;"><?= tt('hr.rules_log') ?></h3>
  <?php $rl = all("SELECT a.*, cu.full_name AS by_name FROM hr_audit a LEFT JOIN customer_users cu ON cu.id = a.user_id WHERE a.person_id = 0 AND a.kind = 'rules' ORDER BY a.id DESC LIMIT 40"); if (!$rl): ?><p class="hint">—</p><?php else: ?><ul class="hint"><?php foreach ($rl as $a): ?><li><?= e(fmt_datetime($a['created_at'])) ?> · <?= e($a['by_name'] ?? '') ?> · <?= e($a['note'] ?? 'GR') ?> · <?= e(t(HR_RULE_LABELS[$a['field']] ?? $a['field'])) ?>: <?= e($a['old_value']) ?> → <b><?= e($a['new_value']) ?></b></li><?php endforeach; ?></ul><?php endif; ?>
</div>

<?php elseif ($tab === 'import'): $st = $_SESSION['hr_import'] ?? null; ?>
<div class="card" id="hr-import">
  <h2>⇪ <?= tt('hr.import') ?></h2>
  <p class="hint"><?= tt('hr.import_hint') ?></p>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'import']) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_import_upload">
    <div class="grid grid-4"><div class="field"><label><?= tt('hr.import_file') ?></label><input type="file" name="file" accept=".xlsx" required></div><div class="field"><label><?= tt('hr.year') ?></label><select name="year" data-nosearch><?php foreach (array_unique(array_merge(hr_years(), [$year])) as $yy): ?><option value="<?= $yy ?>"<?= $sel($yy, $year) ?>><?= $yy ?></option><?php endforeach; ?></select></div><div class="field"><label><?= tt('hr.import_asof') ?></label><input type="date" name="asof" value="<?= date('Y-m-d') ?>" title="<?= tt('hr.import_asof_hint') ?>"><p class="hint"><?= tt('hr.import_asof_hint') ?></p></div><div class="field" style="display:flex;align-items:flex-end;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.import_read') ?></button></div></div>
  </form>
  <?php if ($st && $st['rows']): ?>
  <h3 style="margin-top:16px;"><?= e(t('hr.import_match', ['n' => count($st['rows']), 'y' => (int)$st['year']])) ?></h3>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'import']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_import_apply">
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.name_el') ?></th><th class="num"><?= tt('hr.carry') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.remaining') ?></th><th><?= tt('hr.match_to') ?></th></tr></thead><tbody>
    <?php $act = array_values(array_filter($people, fn($p) => $p['is_active'])); foreach ($st['rows'] as $r): $m = hr_match_name($r['name_el'], $act); $top = array_key_first($m); if ($top !== null && $m[$top] < HR_MATCH_AUTO) $top = null; ?><tr><td><b><?= e($r['name_el']) ?></b></td><td class="num"><?= e($fd($r['carry'])) ?></td><td class="num"><?= e($fd($r['entitled'])) ?></td><td class="num"><?= e($fd($r['used'])) ?></td><td class="num"><?= e($fd($r['remaining'])) ?></td>
      <td><select name="map[<?= e($r['name_el']) ?>]" data-nosearch<?= $top ? '' : ' class="is-warn"' ?>><option value="">— <?= tt('hr.skip') ?> —</option><?php foreach ($m as $pid => $sc): $pp = hr_person((int)$pid); ?><option value="<?= (int)$pid ?>"<?= $pid === $top ? ' selected' : '' ?>><?= e($pp['name_en'] ?? '') ?> (<?= (int) round($sc * 100) ?> %)</option><?php endforeach; ?><optgroup label="<?= tt('hr.all_people') ?>"><?php foreach ($act as $pp): if (isset($m[(int)$pp['id']])) continue; ?><option value="<?= (int)$pp['id'] ?>"><?= e($pp['name_en']) ?></option><?php endforeach; ?></optgroup><option value="new">+ <?= tt('hr.new_person') ?></option></select></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.import_apply') ?></button><span class="hint"><?= tt('hr.import_apply_hint') ?></span></div>
  </form>
  <?php elseif ($st): ?><p class="flash flash--warn"><?= tt('hr.import_empty') ?></p><?php endif; ?>
</div>

<?php if (function_exists('hr_import_batches') && ($batches = hr_import_batches())): ?>
<div class="card" id="hr-batches">
  <h2>🗂 <?= tt('hr.batches') ?></h2><p class="hint"><?= tt('hr.batches_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.batch') ?></th><th class="num"><?= tt('hr.records') ?></th><th class="num"><?= tt('hr.s_approved') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($batches as $bt): ?><tr><td><b><?= e($bt['batch']) ?></b></td><td class="num"><?= (int)$bt['n'] ?></td><td class="num"><?= (int)$bt['appr'] ?></td><td><?= e(fmt_date($bt['d1'])) ?></td><td><?= e(fmt_date($bt['d2'])) ?></td><td class="row-actions"><form method="post" action="<?= url('admin_hr', ['tab' => 'import']) ?>" class="inline" data-lock data-confirm="<?= e(t('hr.batch_delete_confirm', ['b' => $bt['batch'], 'n' => (int)$bt['n']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_import_batch_delete"><input type="hidden" name="batch" value="<?= e($bt['batch']) ?>"><button class="btn btn--ghost btn--sm" type="submit">× <?= tt('hr.batch_delete') ?></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<div class="card" id="hr-leave-import">
  <h2>⇪ <?= tt('hr.leave_import') ?></h2>
  <p class="hint"><?= tt('hr.leave_import_hint') ?></p>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'import']) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_leave_upload">
    <div class="grid grid-3"><div class="field"><label><?= tt('hr.import_file') ?></label><input type="file" name="file" accept=".xlsx" required></div><div class="field" style="display:flex;align-items:flex-end;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.import_read') ?></button></div></div>
  </form>
  <?php $li = $_SESSION['hr_leave_import'] ?? null; if (!empty($_GET['limp']) && $li): $okN = count(array_filter($li, fn($r) => $r['ok'])); ?>
  <h3 style="margin-top:16px;"><?= e(t('hr.leave_preview', ['n' => count($li), 'k' => $okN])) ?></h3>
  <form method="post" action="<?= url('admin_hr', ['tab' => 'import']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_leave_apply">
    <div class="table-scroll"><table class="tbl"><thead><tr><th><input type="checkbox" id="hr-sel-all" checked></th><th><?= tt('ll.who') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th class="num"><?= tt('hr.days') ?> / h</th><th><?= tt('th.status') ?></th><th><?= tt('hr.reason') ?></th><th><?= tt('hr.review') ?></th></tr></thead><tbody>
    <?php foreach ($li as $i => $r): $t = $r['type_id'] ? hr_type((int)$r['type_id']) : null; ?><tr<?= $r['ok'] ? '' : ' class="is-retired"' ?>><td><?php if ($r['ok']): ?><input type="checkbox" name="sel[<?= (int)$i ?>]" value="1" checked><?php elseif (!empty($r['former'])): ?><input type="checkbox" name="sel[<?= (int)$i ?>]" value="1" class="hr-former" title="<?= tt('hr.former_row') ?>"><?php endif; ?></td><td><b><?= e($r['person']) ?></b></td><td><b><?= e($r['code']) ?></b> <?= $t ? e($t['name']) : '' ?></td><td><?= $r['from'] ? e(fmt_date($r['from'])) : '—' ?></td><td><?= $r['to'] ? e(fmt_date($r['to'])) : '—' ?></td><td class="num"><?= $r['hours'] !== null ? e(rtrim(rtrim(number_format((float)$r['hours'], 2, '.', ''), '0'), '.')) . ' h' : ($r['days'] > 0 ? e(rtrim(rtrim(number_format((float)$r['days'], 1, '.', ''), '0'), '.')) : '') ?></td><td><?= hr_status_pill($r['status']) ?></td><td class="hint"><?= e(mb_substr((string)$r['reason'], 0, 60)) ?></td><td class="hint<?= $r['warn'] ? ' bad' : '' ?>"><?= e(implode(' · ', $r['warn'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if (function_exists('hr_counted_sql') && hr_counted_sql() !== ''): ?><label class="chk" style="margin-top:8px;"><input type="checkbox" name="history_only" value="1"> <b><?= tt('hr.history_only') ?></b> — <span class="hint"><?= tt('hr.history_only_hint') ?></span></label><?php endif; ?>
    <?php if (array_filter($li, fn($r) => !empty($r['former']))): ?><label class="chk" style="margin-top:4px;"><input type="checkbox" name="create_former" value="1"> <b><?= e(t('hr.create_former', ['n' => count(array_unique(array_map(fn($r) => mb_strtoupper((string)$r['person']), array_filter($li, fn($r) => !empty($r['former'])))))])) ?></b> — <span class="hint"><?= tt('hr.create_former_hint') ?></span></label><?php endif; ?>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.leave_apply') ?></button><span class="hint"><?= tt('hr.leave_apply_hint') ?></span></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var a=document.getElementById('hr-sel-all');if(a)a.addEventListener('change',function(){document.querySelectorAll('#hr-leave-import input[name^="sel["]').forEach(function(c){c.checked=a.checked;});});})();</script>
  <?php elseif (!empty($_GET['limp'])): ?><p class="flash flash--warn"><?= tt('hr.import_empty') ?></p><?php endif; ?>
</div>

<?php endif; endif; ?>
<?php if ($tab === 'ent'): ?>
<div class="card" id="hr-ent">
  <div class="card-head"><h2><?= tt('hr.entitlements') ?> <?= $year ?></h2>
    <form method="get" action="index.php" class="inline"><input type="hidden" name="p" value="admin_hr"><select name="year" data-nosearch data-js-submit="1"><?php foreach (array_unique(array_merge(hr_years(), [$year])) as $yy): ?><option value="<?= $yy ?>"<?= $sel($yy, $year) ?>><?= $yy ?></option><?php endforeach; ?></select></form>
    <form method="post" action="<?= url('admin_hr', ['year' => $year]) ?>" class="inline" data-lock data-confirm="<?= e(t('hr.copy_confirm', ['y' => $year - 1])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('hr.copy') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_ent_copy"><input type="hidden" name="year" value="<?= $year ?>"><button class="btn btn--ghost btn--sm" type="submit">⎘ <?= e(t('hr.copy_from', ['y' => $year - 1])) ?></button></form>
    <?php if (function_exists('hr4_has') && hr4_has()): ?><form method="post" action="<?= url('admin_hr', ['tab' => 'ent', 'year' => $year]) ?>" class="inline" data-lock data-confirm="<?= e(t('hr.rollover_confirm', ['y' => $year])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('hr.rollover') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_ent_rollover"><input type="hidden" name="year" value="<?= $year ?>"><button class="btn btn--gold btn--sm" type="submit">⚙ <?= e(t('hr.rollover_btn', ['y' => $year])) ?></button></form><?php endif; ?></div>
  <?php if (function_exists('hr4_has') && hr4_has()): ?><p class="hint"><?= tt('hr.ent_auto_hint') ?></p><?php endif; ?>
  <p class="hint"><?= tt('hr.entitlements_hint') ?></p>
  <form method="post" action="<?= url('admin_hr', ['year' => $year]) ?>#hr-ent" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_ent_save"><input type="hidden" name="year" value="<?= $year ?>">
    <div class="table-scroll"><table class="tbl hr-matrix"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt($r54 ? 'hr.department' : 'disc.name') ?></th><?php foreach ($types as $t): if (!$t['counts']) continue; $hu = ($t['unit'] ?? 'day') === 'hour'; ?><th class="num" colspan="<?= $r54 ? 3 : 2 ?>" title="<?= e($t['name']) ?>"><?= e($t['code']) ?><?= $hu ? ' <span class="hint">(h)</span>' : '' ?><br><span class="hint"><?= $hu ? 'h' : tt('hr.days') ?> · <?= tt('hr.carry') ?><?= $r54 ? ' · ' . tt('hr.opening') : '' ?></span></th><?php endforeach; ?><th class="num"><?= tt('hr.taken') ?></th></tr></thead><tbody>
    <?php foreach ($staff as $p): $uid = (int)$p['id']; $T = $r54 ? [] : hr_taken($uid, $year); ?><tr><td><b><?= e($p['full_name']) ?></b><?= $r54 && !$p['uid'] ? ' ' . pill('mute', t('hr.no_account')) : '' ?></td><td class="hint"><?= e($p['discipline'] ?? '') ?></td>
      <?php foreach ($types as $tid => $t): if (!$t['counts']) continue; $e = $E[$uid][$tid] ?? null; ?><?php $auto = $r54 && function_exists('hr4_has') && hr4_has() && ($t['rule_key'] ?? '') === 'annual' ? hr_entitlement_effective($p['p'], $tid, $year) : null; ?><td class="num"><?php if ($auto && $auto[1] === 'auto'): ?><input name="d[<?= $uid ?>][<?= $tid ?>]" inputmode="decimal" value="<?= e($fh($auto[0])) ?>" style="width:58px;text-align:right;background:#F3F6FA;" title="<?= e(t('hr.auto_title') . ' — ' . hr_basis_text($auto[2])) ?>"> <a href="<?= url('hr_person', ['id' => $uid, 'tab' => 'ent', 'year' => $year]) ?>" title="<?= tt('hr.unlock') ?>">🔒</a><?php else: ?><input name="d[<?= $uid ?>][<?= $tid ?>]" inputmode="decimal" value="<?= e($fh($e['days'] ?? ($auto[0] ?? null))) ?>" style="width:58px;text-align:right;"<?= !empty($e['override_note']) ? ' title="' . e($e['override_note']) . '"' : '' ?>><?= $auto ? ' <a href="' . url('hr_person', ['id' => $uid, 'tab' => 'ent', 'year' => $year]) . '" title="' . tt('hr.manual_title') . '">🔓</a>' : '' ?><?php endif; ?></td><td class="num"><input name="c[<?= $uid ?>][<?= $tid ?>]" inputmode="decimal" value="<?= e($fh($e['carry'] ?? null)) ?>" style="width:50px;text-align:right;" title="<?= tt('hr.carry') ?>"></td><?php if ($r54): ?><td class="num"><input name="o[<?= $uid ?>][<?= $tid ?>]" inputmode="decimal" value="<?= e($fh($e['opening_taken'] ?? null)) ?>" style="width:50px;text-align:right;" title="<?= tt('hr.opening') ?>"></td><?php endif; endforeach; ?>
      <td class="num hint"><?php $parts = []; if ($r54) { foreach (hr_balances($p['p'], $year) as $b) if ($b['taken'] > 0 || $b['pending'] > 0) $parts[] = $b['type']['code'] . ' ' . $fh($b['taken']) . ($b['pending'] > 0 ? ' (+' . $fh($b['pending']) . ')' : '') . ($b['unit'] === 'hour' ? 'h' : ''); } else foreach ($T as $tid => $n) if (isset($allTypes[$tid])) $parts[] = $allTypes[$tid]['code'] . ' ' . $n; echo e(implode(' · ', $parts)) ?: '—'; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('hr.ent_save_hint') ?></span></div>
  </form>
</div>
<?php endif; ?>

<?php if ($tab === 'types' || !$r54): ?>
<div class="card" id="hr-types">
  <h2><?= tt('hr.types') ?></h2>
  <p class="hint"><?= tt('hr.types_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th style="width:70px;"><?= tt('sys.order') ?></th><th style="width:90px;"><?= tt('hr.type_code') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('hr.kind') ?></th><th><?= tt('hr.counts') ?></th><th><?= tt('hr.needs_reason') ?></th><?php if ($r54): ?><th><?= tt('hr.unit') ?></th><th><?= tt('hr.rules') ?></th><th class="num"><?= tt('hr.max_year') ?></th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach ($allTypes as $t): $fid = 'hlt' . (int)$t['id']; ?><tr<?= $t['is_active'] ? '' : ' class="is-retired"' ?>>
    <td><input form="<?= $fid ?>" name="sort_order" type="number" min="0" value="<?= (int)$t['sort_order'] ?>" style="width:64px;"></td>
    <td><input form="<?= $fid ?>" name="code" value="<?= e($t['code']) ?>" maxlength="12" required style="width:80px;font-family:var(--mono);text-transform:uppercase;"></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= e($t['name_en'] ?? $t['name']) ?>" maxlength="80" required title="EN"><?= $t['builtin'] ? ' ' . pill('mute', t('hr.builtin')) : '' ?>
      <?php if ($ml): ?><div class="hr-ml"><?php foreach (HR_NAME_LANGS as $lc): ?><label><span><?= strtoupper($lc) ?></span><input form="<?= $fid ?>" name="name_<?= $lc ?>" value="<?= e($t['name_' . $lc] ?? '') ?>" maxlength="120" placeholder="<?= e($t['name_en'] ?? $t['name']) ?>"></label><?php endforeach; ?></div><?php endif; ?></td>
    <td><select form="<?= $fid ?>" name="kind" data-nosearch><?php foreach (HR_KINDS as $k): ?><option value="<?= $k ?>"<?= $sel($k, $t['kind']) ?>><?= e(t('pl.k_' . $k)) ?></option><?php endforeach; ?></select></td>
    <td><label class="chk" style="margin:0;"><input form="<?= $fid ?>" type="checkbox" name="counts" value="1"<?= $t['counts'] ? ' checked' : '' ?>> <?= tt('hr.counts_s') ?></label></td>
    <td><label class="chk" style="margin:0;"><input form="<?= $fid ?>" type="checkbox" name="needs_reason" value="1"<?= $t['needs_reason'] ? ' checked' : '' ?>> <?= tt('hr.reason_s') ?></label></td>
    <?php if ($r54): ?><td><select form="<?= $fid ?>" name="unit" data-nosearch><option value="day"<?= $sel('day', $t['unit']) ?>><?= tt('hr.days') ?></option><option value="hour"<?= $sel('hour', $t['unit']) ?>><?= tt('hr.hours') ?></option></select></td>
    <td style="white-space:nowrap;"><label class="chk" style="margin:0;" title="<?= tt('hr.hr_only_hint') ?>"><input form="<?= $fid ?>" type="checkbox" name="hr_only" value="1"<?= $t['hr_only'] ? ' checked' : '' ?>> <?= tt('hr.hr_only') ?></label> <label class="chk" style="margin:0;"><input form="<?= $fid ?>" type="checkbox" name="needs_doc" value="1"<?= $t['needs_doc'] ? ' checked' : '' ?>> <?= tt('hr.needs_doc') ?></label> <label class="chk" style="margin:0;"><input form="<?= $fid ?>" type="checkbox" name="paid" value="1"<?= $t['paid'] ? ' checked' : '' ?>> <?= tt('hr.paid') ?></label> <label class="chk" style="margin:0;" title="<?= tt('hr.consumes_ann_hint') ?>"><input form="<?= $fid ?>" type="checkbox" name="consumes_annual" value="1"<?= $t['consumes_annual'] ? ' checked' : '' ?>> <?= tt('hr.consumes_ann') ?></label><input form="<?= $fid ?>" type="hidden" name="rule_key" value="<?= e($t['rule_key'] ?? '') ?>"></td>
    <td class="num"><input form="<?= $fid ?>" name="max_year" inputmode="decimal" value="<?= e($fh($t['max_year'] ?? null)) ?>" style="width:56px;text-align:right;"><?= $t['rule_key'] ? '<br><span class="hint">' . e($t['rule_key']) . '</span>' : '' ?></td><?php endif; ?>
    <td class="row-actions"><form method="post" action="<?= url('admin_hr', ['year' => $year]) ?>#hr-types" id="<?= $fid ?>" style="display:contents;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_type_save"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"></form>
      <button class="btn btn--ghost btn--sm" form="<?= $fid ?>" type="submit"><?= tt('btn.save') ?></button>
      <?php if (!$t['builtin'] || !$t['is_active']): ?><button class="btn btn--ghost btn--sm" form="<?= $fid ?>" type="submit" name="toggle" value="1"><?= $t['is_active'] ? tt('sys.retire') : tt('sys.restore') ?></button><?php endif; ?>
      <?php if (!$t['builtin']): ?><button class="btn btn--ghost btn--sm" form="<?= $fid ?>" type="submit" name="delete" value="1" data-js-confirm="<?= e(t('hr.type_delete_confirm', ['t' => $t['name']])) ?>">×</button><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" action="<?= url('admin_hr', ['year' => $year]) ?>#hr-types" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_type_save">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('hr.type_code') ?></label><input name="code" required maxlength="12" placeholder="STUDY" style="text-transform:uppercase;"></div>
      <div class="field"><label><?= tt('hr.type') ?> (EN)</label><input name="name" required maxlength="80" placeholder="<?= tt('hr.type_ph') ?>"><?php if ($ml): ?><p class="hint"><?= tt('hr.names_hint') ?></p><?php endif; ?></div>
      <div class="field"><label><?= tt('hr.kind') ?></label><select name="kind" data-nosearch><?php foreach (HR_KINDS as $k): ?><option value="<?= $k ?>"><?= e(t('pl.k_' . $k)) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;"><label class="chk" style="margin:0 0 6px;"><input type="checkbox" name="counts" value="1"> <?= tt('hr.counts_s') ?></label><label class="chk" style="margin:0 0 6px;"><input type="checkbox" name="needs_reason" value="1"> <?= tt('hr.reason_s') ?></label><?php if ($r54): ?><select name="unit" data-nosearch style="margin:0 0 6px;"><option value="day"><?= tt('hr.days') ?></option><option value="hour"><?= tt('hr.hours') ?></option></select><input type="hidden" name="paid" value="1"><?php endif; ?><input type="hidden" name="sort_order" value="<?= (count($allTypes) + 1) * 10 ?>"><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('hr.type_add') ?></button></div>
    </div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

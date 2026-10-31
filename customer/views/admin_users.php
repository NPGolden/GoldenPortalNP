<?php
/**
 * Admin console — portal accounts (customers + Golden A/S staff). GAS-CP-R5-001
 * Create accounts; edit name, access role, customer, Golden A/S role,
 * language and password inline; enable / disable.
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$roles     = all('SELECT id, name FROM staff_roles WHERE is_active = 1 ORDER BY sort_order, name');
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'access' => (string)($_GET['access'] ?? ''), 'customer' => (string)($_GET['customer'] ?? ''), 'grole' => (string)($_GET['grole'] ?? ''), 'disc' => (string)($_GET['disc'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'twofa' => (string)($_GET['twofa'] ?? ''), 'locale' => (string)($_GET['locale'] ?? ''), 'login' => (string)($_GET['login'] ?? '')];
$hasTotp = (bool)one("SHOW COLUMNS FROM customer_users LIKE 'totp_enabled'");
$hasDisc = (bool)one("SHOW COLUMNS FROM customer_users LIKE 'discipline_id'");
$w = []; $b = [];
if ($f['q'] !== '') { $w[] = '(cu.full_name LIKE ? OR cu.email LIKE ? OR c.name LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($b, $like, $like, $like); }
if (in_array($f['access'], ['customer', 'staff', 'admin'], true)) { $w[] = 'cu.role = ?'; $b[] = $f['access']; }
if ($f['customer'] === 'none') $w[] = 'cu.customer_id IS NULL'; elseif ($f['customer'] !== '' && ctype_digit($f['customer'])) { $w[] = 'cu.customer_id = ?'; $b[] = (int)$f['customer']; }
if ($f['grole'] === 'none') $w[] = 'cu.staff_role_id IS NULL'; elseif ($f['grole'] !== '' && ctype_digit($f['grole'])) { $w[] = 'cu.staff_role_id = ?'; $b[] = (int)$f['grole']; }
if ($hasDisc) { if ($f['disc'] === 'none') $w[] = "cu.discipline_id IS NULL AND cu.role <> 'customer'"; elseif ($f['disc'] !== '' && ctype_digit($f['disc'])) { $w[] = 'cu.discipline_id = ?'; $b[] = (int)$f['disc']; } }
if ($f['status'] === 'active') $w[] = 'cu.is_active = 1'; elseif ($f['status'] === 'disabled') $w[] = 'cu.is_active = 0';
if ($hasTotp) { if ($f['twofa'] === 'on') $w[] = 'cu.totp_enabled = 1'; elseif ($f['twofa'] === 'pending') $w[] = 'cu.totp_enabled = 0 AND cu.twofa_required = 1'; elseif ($f['twofa'] === 'exempt') $w[] = 'cu.totp_enabled = 0 AND cu.twofa_required = 0'; }
if ($f['locale'] !== '' && isset(LOCALES[$f['locale']])) { $w[] = 'cu.locale = ?'; $b[] = $f['locale']; }
if ($f['login'] === 'never') $w[] = 'cu.last_login_at IS NULL'; elseif ($f['login'] === '30') $w[] = 'cu.last_login_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'; elseif ($f['login'] === 'stale') $w[] = '(cu.last_login_at IS NULL OR cu.last_login_at < DATE_SUB(NOW(), INTERVAL 90 DAY))';
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$from  = 'FROM customer_users cu LEFT JOIN customers c ON c.id = cu.customer_id LEFT JOIN staff_roles r ON r.id = cu.staff_role_id ' . $where;
$total = (int)scalar("SELECT COUNT(*) $from", $b);
$per   = 30; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($_GET['page'] ?? 1)));
$users = all("SELECT cu.*, c.name AS customer_name, r.name AS role_name $from
              ORDER BY FIELD(cu.role,'admin','staff','customer'), c.name, cu.full_name LIMIT $per OFFSET " . (($page - 1) * $per), $b);
$counts = one("SELECT COUNT(*) n, SUM(role='customer') nc, SUM(role='staff') ns, SUM(role='admin') na, SUM(is_active=0) nd" . ($hasTotp ? ", SUM(totp_enabled=0 AND twofa_required=1) np" : ", 0 np") . ", SUM(last_login_at IS NULL) nn FROM customer_users") ?: [];
$qs = array_filter($f, fn($v) => $v !== ''); if ($page > 1) $qs['page'] = $page;
$pageUrl = fn(int $n) => url('admin_users', array_merge($qs, ['page' => $n]));
$discs  = ts_has() ? disciplines(false) : [];
$people = staff_users(); $peopleById = array_column($people, 'full_name', 'id'); $customersById = array_column($customers, 'name', 'id');   // R60-006: the long lists are rendered once (JSON) and filled into a row's select on first use
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$accessPill = fn(string $r) => pill($r === 'admin' ? 'gold' : ($r === 'staff' ? 'green' : 'mute'), t('role.' . $r));
layout_head(t('au.title'), 'admin_users');
?>
<?php settings_tabs('admin_users'); ?>
<h2 class="settings-h"><?= tt('au.title') ?></h2>
<p class="lede"><?= tt('au.lede') ?></p>
<div class="btn-row" style="margin:-6px 0 14px;"><a class="btn btn--ghost btn--sm" href="<?= url('users_export', array_filter(['q' => $f['q'], 'role' => $f['access']])) ?>">⤓ <?= tt('au.export') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('settings', ['tab' => 'import']) ?>#users">⤒ <?= tt('im.title') ?></a><span class="hint"><?= tt('au.export_hint') ?></span></div>
<?php if (function_exists('cr_has') && cr_has()): ?><form method="post" action="<?= url('admin_users') ?>" class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="cr_dev">
  <b>💡 <?= tt('cr.dev_setting') ?></b><select name="user_id" data-nosearch style="min-width:220px;"><?php foreach (all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name") as $dvu): ?><option value="<?= (int)$dvu['id'] ?>"<?= (int)$dvu['id'] === cr_dev_id() ? ' selected' : '' ?>><?= e($dvu['full_name']) ?></option><?php endforeach; ?></select><span class="hint" style="flex:1;min-width:240px;"><?= tt('cr.dev_setting_hint') ?></span><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form><?php endif; ?>
<?php if (tm_has()): ?><form method="post" action="<?= url('admin_users') ?>" class="card" style="padding:12px 16px;margin-bottom:14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="tm_strict">
  <label class="chk" style="margin:0;"><input type="checkbox" name="strict" value="1"<?= tm_strict() ? ' checked' : '' ?>> <b><?= tt('tm.strict') ?></b></label><span class="hint" style="flex:1;min-width:240px;"><?= tt('tm.strict_hint') ?></span><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form><?php endif; ?>

<div class="kpi kpi--6">
  <a class="stat" href="<?= url('admin_users') ?>"><b><?= (int)($counts['n'] ?? 0) ?></b><span class="s"><?= tt('au.people') ?></span></a>
  <a class="stat" href="<?= url('admin_users', ['access' => 'customer']) ?>"><b><?= (int)($counts['nc'] ?? 0) ?></b><span class="s"><?= tt('role.customer') ?></span></a>
  <a class="stat" href="<?= url('admin_users', ['access' => 'staff']) ?>"><b><?= (int)($counts['ns'] ?? 0) ?></b><span class="s"><?= tt('role.staff') ?></span></a>
  <a class="stat gold" href="<?= url('admin_users', ['access' => 'admin']) ?>"><b><?= (int)($counts['na'] ?? 0) ?></b><span class="s"><?= tt('role.admin') ?></span></a>
  <a class="stat<?= (int)($counts['np'] ?? 0) ? ' gold' : '' ?>" href="<?= url('admin_users', ['twofa' => 'pending']) ?>"><b><?= (int)($counts['np'] ?? 0) ?></b><span class="s"><?= tt('au.twofa_none') ?></span></a>
  <a class="stat" href="<?= url('admin_users', ['status' => 'disabled']) ?>"><b><?= (int)($counts['nd'] ?? 0) ?></b><span class="s"><?= tt('au.disabled') ?></span></a>
</div>

<div class="card">
  <form method="get" action="index.php" class="fltbar fltbar--au">
    <input type="hidden" name="p" value="admin_users">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" type="text" value="<?= e($f['q']) ?>" placeholder="<?= tt('au.search_ph') ?>"></div>
    <div class="field"><label for="access"><?= tt('au.role') ?></label><select id="access" name="access"><option value=""><?= tt('flt.any') ?></option><?php foreach (['customer', 'staff', 'admin'] as $r): ?><option value="<?= $r ?>"<?= $sel($r, $f['access']) ?>><?= e(t('role.' . $r)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="customer"><?= tt('rp.customer') ?></label><select id="customer" name="customer"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['customer']) ?>><?= tt('au.nocustomer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $f['customer']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="grole"><?= tt('au.golden_role') ?></label><select id="grole" name="grole"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['grole']) ?>><?= tt('au.none_role') ?></option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"<?= $sel($r['id'], $f['grole']) ?>><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <?php if ($hasDisc): ?><div class="field"><label for="disc"><?= tt('disc.one') ?></label><select id="disc" name="disc"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $f['disc']) ?>><?= tt('sys.none') ?></option><?php foreach ($discs as $d): ?><option value="<?= $d['id'] ?>"<?= $sel($d['id'], $f['disc']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label for="status"><?= tt('th.status') ?></label><select id="status" name="status"><option value=""><?= tt('flt.any') ?></option><option value="active"<?= $sel('active', $f['status']) ?>><?= tt('au.active') ?></option><option value="disabled"<?= $sel('disabled', $f['status']) ?>><?= tt('au.disabled') ?></option></select></div>
    <div class="field"><label for="twofa"><?= tt('au.twofa') ?></label><select id="twofa" name="twofa"<?= $hasTotp ? '' : ' disabled' ?>><option value=""><?= tt('flt.any') ?></option><option value="on"<?= $sel('on', $f['twofa']) ?>><?= tt('au.twofa_on') ?></option><option value="pending"<?= $sel('pending', $f['twofa']) ?>><?= tt('au.twofa_none') ?></option><option value="exempt"<?= $sel('exempt', $f['twofa']) ?>><?= tt('au.twofa_exempt') ?></option></select></div>
    <div class="field"><label for="locale"><?= tt('au.locale') ?></label><select id="locale" name="locale"><option value=""><?= tt('flt.any') ?></option><?php foreach (LOCALES as $code => $meta): ?><option value="<?= e($code) ?>"<?= $sel($code, $f['locale']) ?>><?= e($meta['native']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="login"><?= tt('au.last') ?></label><select id="login" name="login"><option value=""><?= tt('flt.any') ?></option><option value="30"<?= $sel('30', $f['login']) ?>><?= tt('au.login_30') ?></option><option value="stale"<?= $sel('stale', $f['login']) ?>><?= tt('au.login_stale') ?></option><option value="never"<?= $sel('never', $f['login']) ?>><?= tt('au.login_never') ?></option></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('admin_users', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<?php $pf = ['new' => !empty($_GET['new']), 'name' => mb_substr(trim((string)($_GET['full_name'] ?? '')), 0, 120), 'email' => mb_substr(trim((string)($_GET['email'] ?? '')), 0, 190), 'role' => in_array($_GET['role'] ?? '', ['customer', 'staff', 'admin'], true) ? $_GET['role'] : 'customer', 'disc' => (int)($_GET['discipline_id'] ?? 0), 'hr_pid' => (int)($_GET['hr_pid'] ?? 0)];   // R60-005: pre-filled from the HR people list ?>
<details class="card card--details" id="au-add"<?= $total === 0 || $pf['new'] ? ' open' : '' ?>>
  <summary><h2><?= tt('au.add') ?></h2></summary>
  <?php if ($pf['hr_pid']): ?><p class="hint" style="margin:0 0 8px;">🪪 <?= e(t('au.from_hr', ['n' => $pf['name']])) ?></p><?php endif; ?>
  <form method="post" action="<?= url('admin_users') ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="cu_save"><?php if ($pf['hr_pid']): ?><input type="hidden" name="hr_pid" value="<?= $pf['hr_pid'] ?>"><?php endif; ?>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('au.name') ?></label><input name="full_name" required maxlength="120" value="<?= e($pf['name']) ?>"></div>
      <div class="field"><label><?= tt('au.email') ?></label><input name="email" type="email" required maxlength="190" value="<?= e($pf['email']) ?>"<?= $pf['new'] && $pf['email'] === '' ? ' autofocus' : '' ?>></div>
      <div class="field"><label><?= tt('au.role') ?></label>
        <select name="role" class="js-access"><option value="customer"<?= $pf['role'] === 'customer' ? ' selected' : '' ?>><?= tt('role.customer') ?></option><option value="staff"<?= $pf['role'] === 'staff' ? ' selected' : '' ?>><?= tt('role.staff') ?></option><option value="admin"<?= $pf['role'] === 'admin' ? ' selected' : '' ?>><?= tt('role.admin') ?></option></select></div>
      <div class="field"><label><?= tt('rp.customer') ?></label>
        <select name="customer_id"><option value=""><?= tt('au.nocustomer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <p class="hint"><?= tt('au.customer_hint') ?></p></div>
      <div class="field"><label><?= tt('au.golden_role') ?></label>
        <select name="staff_role_id"><option value=""><?= tt('au.none_role') ?></option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"><?= e($r['name']) ?></option><?php endforeach; ?></select>
        <p class="hint"><?= tt('au.golden_hint') ?></p></div>
      <?php if ($discs): ?>
      <div class="field"><label><?= tt('disc.one') ?></label>
        <select name="discipline_id"><option value=""><?= tt('sys.none') ?></option><?php foreach ($discs as $d): if (!$d['is_active']) continue; ?><option value="<?= $d['id'] ?>"<?= (int)$d['id'] === $pf['disc'] ? ' selected' : '' ?>><?= e($d['name'] . ($d['manager_name'] ? ' · ' . $d['manager_name'] : '')) ?></option><?php endforeach; ?></select>
        <p class="hint"><?= tt('au.disc_hint') ?></p></div>
      <div class="field"><label><?= tt('au.manager') ?></label>
        <select name="manager_user_id"><option value=""><?= tt('au.manager_default') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"><?= e($pp['full_name']) ?></option><?php endforeach; ?></select>
        <p class="hint"><?= tt('au.manager_hint') ?></p></div>
      <?php if (function_exists('wc_has') && wc_has()): ?><div class="field"><label><?= tt('wc.one') ?></label><select name="work_calendar"><?php foreach (wc_calendars(true) as $wcode => $wrow): ?><option value="<?= e($wcode) ?>"><?= e($wrow['code'] . ' · ' . $wrow['name']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('wc.user_hint') ?></p></div><?php endif; ?>
      <?php if (br_has() && br_multi()): ?><div class="field"><label><?= tt('br.home') ?></label><select name="branch_id"><?= br_options(null, t('br.default_opt', ['b' => br_default()['code']])) ?></select><label class="chk" style="margin-top:6px;"><input type="checkbox" name="branch_scope" value="1"> <?= tt('br.scope') ?></label><p class="hint"><?= tt('br.scope_hint') ?></p></div><?php endif; ?>
      <?php endif; ?>
      <div class="field"><label><?= tt('au.locale') ?></label><select name="locale"><?php foreach (LOCALES as $code => $meta): ?><option value="<?= e($code) ?>"><?= e($meta['native']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('au.password') ?></label><input name="password" type="password" required minlength="10" autocomplete="new-password"></div>
      <div class="field"><label><?= tt('au.twofa') ?></label><label class="chk" style="margin:8px 0 0;"><input type="checkbox" name="twofa_required" value="1" checked> <?= tt('au.twofa_required') ?></label></div>
    </div>
    <button class="btn" type="submit"><?= tt('au.create') ?></button>
  </form>
</details>

<div class="card">
  <h2><?= tt('au.people') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('au.showing', ['from' => $total ? ($page - 1) * $per + 1 : 0, 'to' => min($total, $page * $per), 'n' => $total])) ?></span> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('au.edit_hint')) ?></span></h2>
  <div class="table-scroll"><table class="acct">
    <thead><tr><th><?= tt('au.name') ?></th><th><?= tt('au.role') ?></th><th><?= tt('rp.customer') ?></th><th><?= tt('au.golden_role') ?></th><?php if ($discs): ?><th><?= tt('disc.one') ?> / <?= tt('au.manager') ?></th><?php endif; ?><th><?= tt('au.locale') ?></th><th><?= tt('au.newpass') ?></th><th><?= tt('au.twofa') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($users as $x): $isMe = (int)$x['id'] === (int)$u['id']; $f = 'acct' . (int)$x['id']; $g = 'tog' . (int)$x['id']; ?>
      <tr>
        <td><div style="display:flex;align-items:center;gap:8px;"><?= avatar_html((int)$x['id'], (string)$x['full_name'], 28) ?><input form="<?= $f ?>" name="full_name" value="<?= e($x['full_name']) ?>" maxlength="120" required></div><span class="hint"><?= e($x['email']) ?></span></td>
        <td><?php if ($isMe): ?><?= $accessPill($x['role']) ?><input form="<?= $f ?>" type="hidden" name="role" value="admin"><?php else: ?>
            <select form="<?= $f ?>" name="role" data-nosearch><?php foreach (['customer', 'staff', 'admin'] as $r): ?><option value="<?= $r ?>"<?= $sel($r, $x['role']) ?>><?= e(t('role.' . $r)) ?></option><?php endforeach; ?></select><?php endif; ?></td>
        <td><select form="<?= $f ?>" name="customer_id" data-nosearch data-lazy="customers" data-empty="<?= tt('au.nocustomer') ?>"><?php $cv = (int)($x['customer_id'] ?? 0); ?><option value="<?= $cv ?: '' ?>"><?= $cv ? e($customersById[$cv] ?? '') : tt('au.nocustomer') ?></option></select></td>
        <td><select form="<?= $f ?>" name="staff_role_id" data-nosearch><option value=""><?= tt('au.none_role') ?></option><?php foreach ($roles as $r): ?><option value="<?= $r['id'] ?>"<?= $sel($r['id'], $x['staff_role_id']) ?>><?= e($r['name']) ?></option><?php endforeach; ?>
            <?php if ($x['staff_role_id'] && !array_filter($roles, fn($r) => (int)$r['id'] === (int)$x['staff_role_id'])): ?><option value="<?= (int)$x['staff_role_id'] ?>" selected><?= e($x['role_name']) ?> (<?= tt('sys.retired') ?>)</option><?php endif; ?></select></td>
        <?php if ($discs): ?><td>
          <?php if ($x['role'] === 'customer'): ?><span class="hint">—</span><?php else: ?>
          <select form="<?= $f ?>" name="discipline_id" style="margin-bottom:4px;"><option value=""><?= tt('sys.none') ?></option><?php foreach ($discs as $d): if (!$d['is_active'] && (int)$d['id'] !== (int)$x['discipline_id']) continue; ?><option value="<?= $d['id'] ?>"<?= $sel($d['id'], $x['discipline_id']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select>
          <select form="<?= $f ?>" name="manager_user_id" data-nosearch data-lazy="people" data-self="<?= (int)$x['id'] ?>" data-empty="<?= tt('au.manager_default') ?>"><?php $mv = (int)($x['manager_user_id'] ?? 0); $mn = $mv ? ($peopleById[$mv] ?? '') : ''; ?><option value="<?= $mv ?: '' ?>"><?= $mv ? e($mn) : tt('au.manager_default') ?></option></select>
          <?php if (function_exists('wc_has') && wc_has()): ?><select form="<?= $f ?>" name="work_calendar" style="margin-top:4px;" title="<?= tt('wc.one') ?>"><?php foreach (wc_calendars(true) as $wcode => $wrow): ?><option value="<?= e($wcode) ?>"<?= $sel($wcode, $x['work_calendar'] ?? 'GR') ?>>🕒 <?= e($wrow['code'] . ' · ' . $wrow['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
          <?php if (br_has() && br_multi()): ?><select form="<?= $f ?>" name="branch_id" style="margin-top:4px;" title="<?= tt('br.home') ?>"><?= br_options((int)($x['branch_id'] ?? 0) ?: null, '🏢 ' . t('br.default_opt', ['b' => br_default()['code']])) ?></select>
          <label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('br.scope_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="branch_scope" value="1"<?= !empty($x['branch_scope']) ? ' checked' : '' ?>> <?= tt('br.scope') ?></label><?php endif; ?>
          <?php if (array_key_exists('can_works', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('wp.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_works" value="1"<?= !empty($x['can_works']) ? ' checked' : '' ?>> 🔧 <?= tt('wp.perm') ?></label><?php endif; ?>
          <?php if (array_key_exists('rate_card', $x) && ($auCards = (function_exists('rl_current') && ($rlc0 = rl_current()) ? rl_cards((int)$rlc0['id']) : []))): ?><select form="<?= $f ?>" name="rate_card" data-nosearch style="margin-top:4px;" title="<?= tt('kpi.user_card_hint') ?>"><option value=""><?= tt('kpi.user_card_auto') ?></option><?php foreach ($auCards as $cc): if ($cc['section'] !== 'hourly') continue; ?><option value="<?= e($cc['card']) ?>"<?= (string)($x['rate_card'] ?? '') === (string)$cc['card'] ? ' selected' : '' ?>>💶 <?= e($cc['card'] . ' · ' . $cc['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
          <?php if (array_key_exists('can_accounting', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('acc.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_accounting" value="1"<?= !empty($x['can_accounting']) ? ' checked' : '' ?>> 🧾 <?= tt('acc.perm') ?></label>
          <label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('acc.perm_acc_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="is_accounting" value="1"<?= !empty($x['is_accounting']) ? ' checked' : '' ?>> ✓ <?= tt('acc.perm_acc') ?></label><?php endif; ?>
          <?php endif; ?>
          <?php if (array_key_exists('is_production', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('pd.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="is_production" value="1"<?= !empty($x['is_production']) ? ' checked' : '' ?>> 🏭 <?= tt('pd.perm') ?></label><?php endif; ?>
          <?php if (array_key_exists('can_sales', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('pi.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_sales" value="1"<?= !empty($x['can_sales']) ? ' checked' : '' ?>> 💶 <?= tt('pi.perm') ?></label><?php endif; ?>
          <?php if (array_key_exists('can_cashflow', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('cf.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_cashflow" value="1"<?= !empty($x['can_cashflow']) ? ' checked' : '' ?>> 💰 <?= tt('cf.perm') ?></label><?php endif; ?>
          <?php if (array_key_exists('can_ai', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('ai.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_ai" value="1"<?= !empty($x['can_ai']) ? ' checked' : '' ?>> ✨ <?= tt('ai.perm') ?></label><?php endif; ?>
          <?php if (array_key_exists('can_management', $x)): ?><label class="chk" style="margin-top:4px;font-size:12px;" title="<?= tt('eng.perm_hint') ?>"><input form="<?= $f ?>" type="checkbox" name="can_management" value="1"<?= !empty($x['can_management']) ? ' checked' : '' ?>> 📐 <?= tt('eng.perm') ?></label><?php endif; ?></td><?php endif; ?>
        <td><select form="<?= $f ?>" name="locale"><?php foreach (LOCALES as $code => $meta): ?><option value="<?= e($code) ?>"<?= $sel($code, $x['locale']) ?>><?= e($meta['short']) ?></option><?php endforeach; ?></select></td>
        <td><input form="<?= $f ?>" name="password" type="password" minlength="10" autocomplete="new-password" placeholder="••••••••••"></td>
        <td><?php if (array_key_exists('totp_enabled', $x)): ?>
            <?= !empty($x['totp_enabled']) ? pill('green', t('au.twofa_on')) : (!empty($x['twofa_required']) ? pill('gold', t('au.twofa_none')) : pill('mute', t('au.twofa_exempt'))) ?><br>
            <label class="chk" style="margin:6px 0 0;font-size:12px;"><input form="<?= $f ?>" type="checkbox" name="twofa_required" value="1"<?= !empty($x['twofa_required']) ? ' checked' : '' ?>> <?= tt('au.twofa_required') ?></label>
            <?php if (!empty($x['totp_enabled'])): ?><br><button form="rst<?= (int)$x['id'] ?>" class="btn btn--ghost btn--sm" type="submit" style="margin-top:4px;"><?= tt('au.twofa_reset') ?></button><?php endif; ?>
            <?php else: ?>—<?php endif; ?></td>
        <?php $locked = !empty($x['locked_until']) && strtotime((string)$x['locked_until']) > time(); ?>
        <td><?= $x['is_active'] ? pill('green', t('au.active')) : pill('bad', t('au.disabled')) ?><?= $locked ? ' ' . pill('bad', t('au.locked')) : '' ?><br><span class="hint date" title="<?= tt('au.last') ?>: <?= e(fmt_datetime($x['last_login_at'])) ?>"><?= e(fmt_date($x['last_login_at'])) ?></span>
          <?php if ($locked): ?><br><button form="unl<?= (int)$x['id'] ?>" class="btn btn--ghost btn--sm" type="submit" style="margin-top:4px;" title="<?= e(t('au.locked_until', ['d' => fmt_datetime($x['locked_until'])])) ?>">🔓 <?= tt('au.unlock') ?></button><?php endif; ?></td>
        <td class="row-actions stack">
          <button form="<?= $f ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <?php if (!$isMe): ?><button form="<?= $g ?>" class="btn btn--ghost btn--sm" type="submit"><?= $x['is_active'] ? tt('au.disable') : tt('au.enable') ?></button><?php if (!$x['is_active'] && function_exists('cu_account_refs') && !cu_account_refs((int)$x['id'])): ?><button form="del<?= (int)$x['id'] ?>" class="btn btn--ghost btn--sm" type="submit" title="<?= tt('au.delete_hint') ?>">🗑 <?= tt('btn.delete') ?></button><?php endif; endif; ?>
          <?php if (function_exists('mob_has') && mob_has() && $x['role'] !== 'customer'): $devs = mob_devices((int)$x['id']); if ($devs): ?><div class="mob-devs"><?php foreach ($devs as $dv): ?><span class="hint" title="<?= e(($dv['model'] ?: $dv['platform']) . ' · ' . $dv['app_id'] . ' ' . ($dv['app_version'] ?: '') . ' · ' . tt('mob.last_seen') . ' ' . ($dv['last_seen_at'] ? fmt_datetime($dv['last_seen_at']) : '—')) ?>">📱 <?= e($dv['platform']) ?> <?= e($dv['app_version'] ?: '') ?><?php if ($dv['revoked_at']): ?> <?= pill('mute', t('mob.revoked')) ?><?php else: ?> <form method="post" action="<?= url('admin_users', $qs) ?>" class="inline" data-lock data-confirm="<?= e(t('mob.revoke_confirm', ['name' => $x['full_name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('mob.revoke') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mob_revoke"><input type="hidden" name="dev" value="<?= (int)$dv['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('mob.revoke_hint') ?>">⎋ <?= tt('mob.revoke') ?></button></form><?php endif; ?></span><?php endforeach; ?></div><?php endif; endif; ?>
          <?php if (array_key_exists('session_epoch', $x)): ?><form method="post" action="<?= url('admin_users', $qs) ?>" class="inline" data-lock data-confirm="<?= e(t('sec.revoke_confirm', ['name' => $x['full_name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('sec.revoke') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cu_revoke"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('sec.revoke_hint') ?>">⎋ <?= tt('sec.revoke') ?></button></form><?php endif; ?>
          <?php if (function_exists('et_welcome_mailto') && $x['email']): ?><a class="btn btn--ghost btn--sm" href="<?= e(et_welcome_mailto($x)) ?>" title="<?= tt('au.welcome_hint') ?>">✉ <?= tt('au.welcome') ?></a><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
  <?php if (!$users): ?><p class="empty"><?= tt('au.none_found') ?></p><?php endif; ?>
  <?php if ($users && function_exists('et_welcome_mailto')): ?><p class="hint" style="margin:8px 0 0;"><?= tt('au.welcome_lede') ?> <?php if (is_cp_admin()): ?><a href="<?= url('settings', ['tab' => 'email_templates', 'kind' => 'welcome']) ?>"><?= tt('em.edit_template') ?></a><?php endif; ?></p><?php endif; ?>
  <?php if ($pages > 1): ?><nav class="pager" aria-label="pages">
    <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($page - 1) ?>">‹ <?= tt('btn.prev') ?></a><?php endif; ?>
    <?php for ($n = max(1, $page - 3); $n <= min($pages, $page + 3); $n++): ?><a class="btn btn--sm <?= $n === $page ? 'btn--gold' : 'btn--ghost' ?>" href="<?= $pageUrl($n) ?>"><?= $n ?></a><?php endfor; ?>
    <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= $pageUrl($page + 1) ?>"><?= tt('btn.next') ?> ›</a><?php endif; ?>
  </nav><?php endif; ?>
  <?php foreach ($users as $x): ?>
    <form method="post" action="<?= url('admin_users', $qs) ?>" id="acct<?= (int)$x['id'] ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="cu_update"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"></form>
    <form method="post" action="<?= url('admin_users', $qs) ?>" id="del<?= (int)$x['id'] ?>" data-confirm="<?= e(t('au.delete_confirm', ['name' => $x['full_name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="cu_delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"></form>
    <form method="post" action="<?= url('admin_users', $qs) ?>" id="tog<?= (int)$x['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cu_toggle"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"></form>
    <form method="post" action="<?= url('admin_users', $qs) ?>" id="unl<?= (int)$x['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cu_unlock"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"></form>
    <form method="post" action="<?= url('admin_users', $qs) ?>" id="rst<?= (int)$x['id'] ?>" data-confirm="<?= e(t('au.twofa_reset_confirm', ['name' => $x['full_name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('au.twofa_reset') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="cu_twofa_reset"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"></form>
  <?php endforeach; ?>
</div>
<script type="application/json" id="au-opt-people"><?= json_encode(array_map(fn($pp) => [(int)$pp['id'], (string)$pp['full_name']], $people), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script type="application/json" id="au-opt-customers"><?= json_encode(array_map(fn($c) => [(int)$c['id'], (string)$c['name']], $customers), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
<script nonce="<?= csp_nonce() ?>">
(function () {   // R60-006: fill the long row lists (manager, customer) only when a select is first opened
  var lists = {}; function opts(k) { if (!lists[k]) { var el = document.getElementById('au-opt-' + k); lists[k] = el ? JSON.parse(el.textContent) : []; } return lists[k]; }
  function fill(sel) { if (sel.dataset.filled) return; sel.dataset.filled = '1'; var cur = sel.value, self = sel.dataset.self || ''; var frag = document.createDocumentFragment();
    var o0 = document.createElement('option'); o0.value = ''; o0.textContent = sel.dataset.empty || '—'; frag.appendChild(o0);
    opts(sel.dataset.lazy).forEach(function (p) { if (String(p[0]) === self) return; var o = document.createElement('option'); o.value = p[0]; o.textContent = p[1]; if (String(p[0]) === cur) o.selected = true; frag.appendChild(o); });
    sel.innerHTML = ''; sel.appendChild(frag); if (cur !== '') sel.value = cur; }
  document.querySelectorAll('select[data-lazy]').forEach(function (sel) { ['mousedown', 'focus', 'keydown', 'touchstart'].forEach(function (ev) { sel.addEventListener(ev, function () { fill(sel); }, { passive: true }); }); });
})();
</script>
<?php layout_foot(); ?>

<?php
/**
 * Admin console — login history.                             GAS-CP-R5-001
 * Every sign-in attempt (success, wrong password, locked, disabled) and
 * sign-out, with IP and browser. Filter by person, outcome and date; page
 * through; export the filtered set as CSV.
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();

$q       = trim((string)($_GET['q'] ?? ''));
$outcome = (string)($_GET['outcome'] ?? '');
$outs    = ['success', 'failed', 'locked', 'disabled', 'logout', 'twofa_failed', 'twofa_enrolled', 'expired', 'device', 'revoked', 'closed', 'pw_changed', 'pw_reset', 'passkey', 'passkey_failed', 'passkey_added', 'passkey_removed'];   // R49-001
if (!in_array($outcome, $outs, true)) $outcome = '';
$llSrc = function_exists('ll_src_has') && ll_src_has(); $srcs = ['web', 'engineer', 'pm', 'hr', 'employee', 'customer']; $src = (string)($_GET['src'] ?? ''); if (!$llSrc || !in_array($src, array_merge($srcs, ['apps']), true)) $src = '';   // R97-254: where the sign-in came from
$srcName = fn(?string $s) => $s === null || $s === '' ? t('ll.src_web') : t('ll.src_' . (in_array($s, $srcs, true) ? $s : 'web'));
$from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
$per     = per_page();
$pageNo  = max(1, (int)($_GET['page'] ?? 1));

$w = []; $args = [];
if ($q !== '')       { $w[] = '(l.email LIKE ? OR cu.full_name LIKE ? OR l.ip LIKE ?)'; array_push($args, ...array_fill(0, 3, '%' . $q . '%')); }
if ($outcome !== '') { $w[] = 'l.outcome = ?'; $args[] = $outcome; }
if ($src === 'web') $w[] = 'l.source IS NULL'; elseif ($src === 'apps') $w[] = 'l.source IS NOT NULL'; elseif ($src !== '') { $w[] = 'l.source = ?'; $args[] = $src; }
if ($from !== '')    { $w[] = 'l.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to !== '')      { $w[] = 'l.created_at <= ?'; $args[] = $to . ' 23:59:59'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$from_sql = "FROM login_log l LEFT JOIN customer_users cu ON cu.id = l.user_id LEFT JOIN customers c ON c.id = cu.customer_id {$where}";
$select   = "SELECT l.*, cu.full_name, cu.role, c.name AS customer_name {$from_sql} ORDER BY l.id DESC";

/* CSV export of the filtered set */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="GAS-login-history-' . date('Ymd-Hi') . '.csv"');
    header('Cache-Control: private, max-age=0');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['When', 'Name', 'Email', 'Role', 'Customer', 'Outcome', 'IP', 'Browser', 'Where', 'App version', 'Platform'], ';');
    foreach (all($select . ' LIMIT 50000', $args) as $r) {
        fputcsv($out, [$r['created_at'], $r['full_name'] ?? '', $r['email'], $r['role'] ?? '', $r['customer_name'] ?? '', $r['outcome'], $r['ip'] ?? '', $r['user_agent'] ?? '', t_in('ll.src_' . (in_array($r['source'] ?? '', $srcs, true) ? $r['source'] : 'web'), 'en'), $r['app_version'] ?? '', $r['platform'] ?? ''], ';');
    }
    exit;
}

$total = (int) scalar("SELECT COUNT(*) {$from_sql}", $args);
$rows  = all($select . ' LIMIT ' . $per . ' OFFSET ' . (($pageNo - 1) * $per), $args);
$keep  = array_filter(['q' => $q, 'src' => $src, 'outcome' => $outcome, 'from' => $from, 'to' => $to, 'per' => $per]);

/* last 24 h at a glance */
$day = one("SELECT SUM(outcome IN ('success','passkey')) AS ok, SUM(outcome IN ('failed','locked','disabled','twofa_failed')) AS bad,
                   COUNT(DISTINCT CASE WHEN outcome IN ('success','passkey') THEN user_id END) AS people
            FROM login_log WHERE created_at >= NOW() - INTERVAL 1 DAY") ?: ['ok' => 0, 'bad' => 0, 'people' => 0];

$tone = ['passkey' => 'green', 'passkey_failed' => 'bad', 'success' => 'green', 'failed' => 'bad', 'locked' => 'bad', 'disabled' => 'gold', 'logout' => 'mute', 'twofa_failed' => 'bad', 'twofa_enrolled' => 'green'];
$sel  = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
/** Short browser label from the user agent. */
$browser = function (?string $ua): string {
    $ua = (string)$ua;
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'Chrome/' => 'Chrome', 'Firefox/' => 'Firefox', 'Safari/' => 'Safari', 'curl' => 'curl'] as $k => $n) {
        if (str_contains($ua, $k)) { $os = str_contains($ua, 'Windows') ? 'Windows' : (str_contains($ua, 'Mac') ? 'macOS' : (str_contains($ua, 'Android') ? 'Android' : (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') ? 'iOS' : (str_contains($ua, 'Linux') ? 'Linux' : '')))); return $n . ($os ? ' · ' . $os : ''); }
    }
    return mb_substr($ua, 0, 40) ?: '—';
};

layout_head(t('ll.title'), 'admin_log');
?>
<?php settings_tabs('admin_log'); ?>
<?php if (function_exists('mob_has') && mob_has() && lic_has()): $nDev = (int) scalar("SELECT COUNT(*) FROM mob_devices WHERE revoked_at IS NULL"); $nPush = (int) scalar("SELECT COUNT(*) FROM mob_push_queue WHERE sent_at IS NULL"); $nTok = (int) scalar("SELECT COUNT(*) FROM mob_refresh_tokens WHERE revoked_at IS NULL AND expires_at > NOW()"); ?>
<div class="card" id="mob">
  <div class="card-head"><h2>📱 <?= tt('mob.title') ?></h2><span class="hint"><?= tt('mob.lede') ?></span></div>
  <?php $mobApps = ['engineer' => [t('mob.app_engineer'), '0.5.0'], 'pm' => [t('mob.app_pm'), '1.0.0'], 'customer' => [t('mob.app_customer'), '1.0.0'], 'hr' => [t('mob.app_hr'), '1.0.0']]; ?>
  <div class="kpi kpi--4" style="margin-bottom:10px;"><span class="stat"><b><?= $nDev ?></b><span class="s"><?= tt('mob.devices') ?></span></span><span class="stat"><b><?= $nTok ?></b><span class="s"><?= tt('mob.tokens') ?></span></span><span class="stat<?= $nPush ? ' gold' : '' ?>"><b><?= $nPush ?></b><span class="s"><?= tt('mob.push_pending') ?></span></span><span class="stat"><b><?= e((string) cp_setting('api_min_version_engineer', '0.5.0')) ?> · <?= e((string) cp_setting('api_min_version_pm', '1.0.0')) ?> · <?= e((string) cp_setting('api_min_version_customer', '1.0.0')) ?> · <?= e((string) cp_setting('api_min_version_hr', '1.0.0')) ?></b><span class="s"><?= tt('mob.min_version') ?></span></span></div>
  <?php foreach ($mobApps as $mobApp => [$mobLabel, $mobDef]): ?>
  <form method="post" action="<?= url('admin_log') ?>#mob" class="inline" data-lock style="margin-bottom:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="mob_settings_save"><input type="hidden" name="app" value="<?= e($mobApp) ?>"><label><b><?= e($mobLabel) ?></b> — <?= tt('mob.min_version') ?> <input name="min_version" value="<?= e((string) cp_setting('api_min_version_' . $mobApp, $mobDef)) ?>" style="width:90px;" pattern="\d+\.\d+\.\d+"></label> <button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></form>
  <?php endforeach; ?>
  <p class="hint" style="margin:0;"><?= tt('mob.min_hint') ?></p>
  <p class="hint" style="margin:8px 0 0;"><?= tt('mob.push_hint') ?></p>
</div>
<?php endif; ?>
<?php if (function_exists('sec_cfg') && lic_has()): ?>
<div class="card" id="sec-policy">
  <div class="card-head"><h2>🔒 <?= tt('sec.title') ?></h2><span class="hint"><?= tt('sec.lede') ?></span></div>
  <form method="post" action="<?= url('admin_log') ?>#sec-policy" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sec_save">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('sec.idle') ?></label><input name="idle" type="number" min="5" max="480" value="<?= sec_cfg('sec_idle_minutes') ?>"><p class="hint"><?= tt('sec.idle_hint') ?></p></div>
      <div class="field"><label><?= tt('sec.max_hours') ?></label><input name="max_hours" type="number" min="1" max="72" value="<?= sec_cfg('sec_max_hours') ?>"><p class="hint"><?= tt('sec.max_hours_hint') ?></p></div>
      <div class="field"><label><?= tt('sec.binding') ?></label>
        <label class="chk"><input type="checkbox" name="bind_ua" value="1"<?= sec_cfg('sec_bind_ua') ? ' checked' : '' ?>> <?= tt('sec.bind_ua') ?></label>
        <label class="chk"><input type="checkbox" name="bind_ip" value="1"<?= sec_cfg('sec_bind_ip') ? ' checked' : '' ?>> <?= tt('sec.bind_ip') ?></label><p class="hint"><?= tt('sec.binding_hint') ?></p></div>
      <div class="field"><label><?= tt('sec.csp') ?></label><select name="csp" data-nosearch><?php foreach (['on', 'report', 'off'] as $cm): ?><option value="<?= $cm ?>"<?= sec_csp_mode() === $cm ? ' selected' : '' ?>><?= tt('sec.csp_' . $cm) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('sec.csp_hint') ?></p></div>
      <div class="field"><label><?= tt('pk.android_sha') ?></label><input id="pk-sha-view" value="<?= e((string) cp_setting('sec_passkey_android_sha256', '')) ?>" placeholder="AA:BB:…"><input type="hidden" name="passkey_android_sha256_enc" id="pk-sha-enc"><p class="hint"><?= tt('pk.android_sha_hint') ?></p></div>
      <div class="field"><label><?= tt('pk.title') ?></label><label class="chk"><input type="checkbox" name="passkeys" value="1"<?= function_exists('pk_enabled') && cp_setting('sec_passkeys', '1') === '1' ? ' checked' : '' ?>> <?= tt('pk.allow') ?></label><p class="hint"><?= tt('pk.allow_hint') ?></p></div>
      <div class="field"><label><?= tt('sec.close') ?></label><label class="chk"><input type="checkbox" name="close_signout" value="1"<?= sec_cfg('sec_close_signout') ? ' checked' : '' ?>> <?= tt('sec.close_signout') ?></label><p class="hint"><?= tt('sec.close_hint') ?></p></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('sec.save_hint') ?></span></div>
  <script nonce="<?= csp_nonce() ?>">(function(){var f=document.getElementById('pk-sha-enc');if(!f)return;f.form.addEventListener('submit',function(){var v=(document.getElementById('pk-sha-view').value||'').replace(/[^0-9A-Fa-f,]/g,'');f.value=btoa(v);});})();</script>
  </form>
</div>
<?php if (function_exists('pk_has') && pk_has()): $allPk = all('SELECT w.id, w.name, w.created_at, w.last_used_at, w.backup_eligible, cu.full_name, cu.email FROM webauthn_credentials w JOIN customer_users cu ON cu.id = w.user_id ORDER BY cu.full_name, w.created_at'); ?>
<div class="card" id="passkeys">
  <h2>🔑 <?= tt('pk.title') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($allPk) ?></span></h2>
  <p class="hint"><?= tt('pk.admin_hint') ?></p>
  <?php if ($allPk): ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pk.user') ?></th><th><?= tt('pk.name') ?></th><th><?= tt('pk.created') ?></th><th><?= tt('pk.last_used') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($allPk as $k): ?><tr><td><b><?= e($k['full_name']) ?></b><br><span class="hint"><?= e($k['email']) ?></span></td><td><?= e($k['name']) ?><?= $k['backup_eligible'] ? ' ☁' : '' ?></td><td class="hint"><?= e(fmt_datetime($k['created_at'])) ?></td><td class="hint"><?= $k['last_used_at'] ? e(fmt_datetime($k['last_used_at'])) : '—' ?></td>
      <td class="row-actions"><form method="post" action="<?= url('admin_log') ?>" class="inline" data-lock data-confirm="<?= tt('pk.remove_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pk_admin_remove"><input type="hidden" name="id" value="<?= (int)$k['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="hint">—</p><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<div class="page-head">
  <div><h2 class="settings-h"><?= tt('ll.title') ?></h2><p class="lede" style="margin-bottom:0;"><?= tt('ll.lede') ?></p></div>
  <a class="btn btn--ghost" href="<?= url('admin_log', $keep + ['export' => 'csv']) ?>">⤓ <?= tt('ll.export') ?></a>
</div>

<?php $online = online_users(); ?>
<div class="imp-stats" style="--cols:4;margin:0 0 16px;">
  <div class="stat good"><b><?= count($online) ?></b><span><?= tt('ll.online_now') ?></span></div>
  <div class="stat good"><b><?= (int)$day['ok'] ?></b><span><?= tt('ll.day_ok') ?></span></div>
  <div class="stat<?= (int)$day['bad'] ? ' bad' : '' ?>"><b><?= (int)$day['bad'] ?></b><span><?= tt('ll.day_bad') ?></span></div>
  <div class="stat"><b><?= (int)$day['people'] ?></b><span><?= tt('ll.day_people') ?></span></div>
</div>

<div class="card">
  <h2><?= tt('ll.online_now') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= e(t('ll.online_hint', ['m' => PRESENCE_MINUTES])) ?></span></h2>
  <?php if (!$online): ?><p class="hint" style="margin:8px 0 0;"><?= tt('ll.online_none') ?></p>
  <?php else: ?>
  <div class="online-list">
    <?php foreach ($online as $o): ?>
      <span class="online-chip"><i></i><strong><?= e($o['full_name']) ?></strong>
        <span class="hint"><?= e($o['customer_name'] ?: t('role.' . $o['role'])) ?> · <?= e(tz_local($o['last_seen_at']) ? tz_local($o['last_seen_at'])->format('H:i') : '') ?></span></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto auto;">
    <input type="hidden" name="p" value="admin_log">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>" placeholder="<?= tt('ll.search_ph') ?>"></div>
    <div class="field"><label for="outcome"><?= tt('ll.outcome') ?></label>
      <select id="outcome" name="outcome"><option value=""><?= tt('flt.any') ?></option>
        <?php foreach ($outs as $o): ?><option value="<?= $o ?>"<?= $sel($o, $outcome) ?>><?= e(t('ll.o_' . $o)) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($llSrc): ?><div class="field"><label for="src"><?= tt('ll.src') ?></label>
      <select id="src" name="src" data-nosearch><option value=""><?= tt('flt.any') ?></option><option value="apps"<?= $sel('apps', $src) ?>><?= tt('ll.src_apps') ?></option><?php foreach ($srcs as $s1): ?><option value="<?= $s1 ?>"<?= $sel($s1, $src) ?>><?= e($srcName($s1)) ?></option><?php endforeach; ?></select></div><?php else: ?><div></div><?php endif; ?>
    <div class="field"><label for="from"><?= tt('ll.from') ?></label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to"><?= tt('ll.to') ?></label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div>
    <div class="field"><label for="per"><?= tt('pg.per') ?></label>
      <select id="per" name="per"><?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>"<?= $sel($n, $per) ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions">
      <button class="btn" type="submit"><?= tt('btn.apply') ?></button>
      <a class="btn btn--ghost" href="<?= url('admin_log', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a>
    </div>
  </form>
</div>

<?php if (function_exists('ls_has') && ls_has()): /* R97-123: how long the portal is used */
  $lsD = (int)($_GET['use'] ?? 30); if (!in_array($lsD, [7, 30, 90, 365], true)) $lsD = 30; $lsU = ls_usage($lsD); $lsOne = (int)($_GET['su'] ?? 0); $lsR = ls_recent($lsOne ?: null, $lsOne ? 200 : 40);
  $lsApps = function_exists('ll_apps_usage') ? ll_apps_usage($lsD) : [];   // R97-254
  $lsTot = array_sum(array_map(fn($r) => (int)$r['secs'], $lsU)); $lsAct = count(array_filter($lsU, fn($r) => (int)$r['n'] > 0)); ?>
<div class="card" id="usage">
  <div class="card-head"><h2>⏱ <?= tt('ls.title') ?></h2>
    <form method="get" action="index.php#usage" class="inline"><input type="hidden" name="p" value="admin_log"><select name="use" data-nosearch data-js-submit="1"><?php foreach ([7, 30, 90, 365] as $d_): ?><option value="<?= $d_ ?>"<?= $lsD === $d_ ? ' selected' : '' ?>><?= e(t('ls.last_days', ['n' => $d_])) ?></option><?php endforeach; ?></select></form></div>
  <p class="hint"><?= tt('ls.hint') ?></p>
  <div class="kpi kpi--3" style="margin-bottom:10px;">
    <span class="stat"><b><?= $lsAct ?> / <?= count($lsU) ?></b><span class="s"><?= tt('ls.k_people') ?></span></span>
    <span class="stat gold"><b><?= e(ls_dur($lsTot)) ?></b><span class="s"><?= tt('ls.k_total') ?></span></span>
    <span class="stat"><b><?= e(ls_dur($lsAct ? intdiv($lsTot, $lsAct) : 0)) ?></b><span class="s"><?= tt('ls.k_avg') ?></span></span>
  </div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th class="num"><?= tt('ls.sessions') ?></th><th class="num"><?= tt('ls.days') ?></th><th class="num"><?= tt('ls.time') ?></th><th class="num"><?= tt('ls.per_session') ?></th><th><?= tt('ls.last') ?></th><th class="num"><?= tt('ls.app_n') ?></th><th><?= tt('ls.app_last') ?></th></tr></thead><tbody>
  <?php foreach ($lsU as $r): $n_ = (int)$r['n']; ?><tr<?= $r['is_active'] ? '' : ' class="is-retired"' ?>><td><a href="<?= url('admin_log', ['use' => $lsD, 'su' => (int)$r['id']]) ?>#usage-s"><?= e($r['full_name']) ?></a><?= $r['role'] === 'admin' ? ' ' . pill('navy', 'admin') : '' ?></td><td class="num"><?= $n_ ?></td><td class="num"><?= (int)$r['days_n'] ?></td><td class="num" data-sort="<?= (int)$r['secs'] ?>"><?= $n_ ? e(ls_dur((int)$r['secs'])) : '—' ?></td><td class="num" data-sort="<?= $n_ ? intdiv((int)$r['secs'], $n_) : 0 ?>"><?= $n_ ? e(ls_dur(intdiv((int)$r['secs'], $n_))) : '—' ?></td><td class="hint"><?= $r['last_start'] ? e(fmt_datetime($r['last_start'])) : e(t('ls.never', ['n' => $lsD])) ?></td><?php $ap_ = $lsApps[(int)$r['id']] ?? ['n' => 0, 'apps' => []]; ?><td class="num"><?= $ap_['n'] ? '<a href="' . url('admin_log', ['q' => (string)$r['full_name'], 'src' => 'apps']) . '">' . (int)$ap_['n'] . '</a>' : '—' ?></td><td class="hint"><?php foreach ($ap_['apps'] as $a_ => $seen_): ?><div>📱 <?= e($srcName($a_)) ?> · <?= e(fmt_datetime($seen_)) ?></div><?php endforeach; ?><?= $ap_['apps'] ? '' : '—' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <h3 id="usage-s" style="margin-top:14px;"><?= tt('ls.recent') ?><?php if ($lsOne): ?> — <?= e((string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$lsOne])) ?> <a class="btn btn--ghost btn--sm" href="<?= url('admin_log', ['use' => $lsD]) ?>#usage">× <?= tt('ls.all_people') ?></a><?php endif; ?></h3>
  <?php if (!$lsR): ?><p class="hint"><?= tt('ls.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('ls.start') ?></th><th><?= tt('ls.last_act') ?></th><th class="num"><?= tt('ls.duration') ?></th><th><?= tt('ls.end') ?></th><th><?= tt('ls.device') ?></th></tr></thead><tbody>
  <?php foreach ($lsR as $r): ?><tr><td><?= e($r['full_name']) ?></td><td><?= e(fmt_datetime($r['started_at'])) ?></td><td class="hint"><?= e(fmt_datetime($r['last_at'])) ?></td><td class="num"><b><?= e(ls_dur((int)$r['secs'])) ?></b></td><td><?= $r['ended_at'] ? pill('mute', t('ls.r_' . (in_array($r['end_reason'], ['logout', 'expired', 'closed', 'device', 'revoked', 'replaced'], true) ? $r['end_reason'] : 'other'))) : (tz_age((string)$r['last_at']) < 1800 ? pill('green', t('ls.r_open')) : pill('mute', t('ls.r_left'))) ?></td><td class="hint"><?= e((string)$r['device']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<div class="card">
<?php if (!$rows): ?>
  <div class="empty"><?= tt('ll.none') ?></div>
<?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('au.role') ?></th><th><?= tt('ll.outcome') ?></th><th><?= tt('ll.src') ?></th><th><?= tt('ll.ip') ?></th><th><?= tt('ll.browser') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td class="date"><?= e(fmt_datetime($r['created_at'])) ?></td>
        <td><strong><?= e($r['full_name'] ?? '—') ?></strong><br><span class="hint"><?= e($r['email']) ?></span></td>
        <td><?= $r['role'] ? pill($r['role'] === 'admin' ? 'gold' : ($r['role'] === 'staff' ? 'green' : 'mute'), t('role.' . $r['role'])) . ($r['customer_name'] ? '<br><span class="hint">' . e($r['customer_name']) . '</span>' : '') : '—' ?></td>
        <td><?= pill($tone[$r['outcome']] ?? 'mute', t('ll.o_' . $r['outcome'])) ?></td>
        <td><?php if (!empty($r['source'])): ?><?= pill('gold', '📱 ' . $srcName($r['source'])) ?><br><span class="hint"><?= e(trim(($r['app_version'] ? 'v' . $r['app_version'] : '') . ' · ' . (['ios' => 'iPhone / iPad', 'android' => 'Android', 'web' => t('ll.pl_web')][$r['platform'] ?? ''] ?? ''), ' ·')) ?></span><?php else: ?><span class="hint">🖥 <?= tt('ll.src_web') ?></span><?php endif; ?></td>
        <td class="num"><?= e($r['ip'] ?: '—') ?></td>
        <td class="hint" title="<?= e($r['user_agent'] ?? '') ?>"><?= e($browser($r['user_agent'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= pager_html('admin_log', $pageNo, $total, $per, $keep) ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

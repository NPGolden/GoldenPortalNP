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
$outs    = ['success', 'failed', 'locked', 'disabled', 'logout', 'twofa_failed', 'twofa_enrolled', 'expired', 'device', 'revoked', 'closed'];   // R49-001
if (!in_array($outcome, $outs, true)) $outcome = '';
$from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : '';
$per     = per_page();
$pageNo  = max(1, (int)($_GET['page'] ?? 1));

$w = []; $args = [];
if ($q !== '')       { $w[] = '(l.email LIKE ? OR cu.full_name LIKE ? OR l.ip LIKE ?)'; array_push($args, ...array_fill(0, 3, '%' . $q . '%')); }
if ($outcome !== '') { $w[] = 'l.outcome = ?'; $args[] = $outcome; }
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
    fputcsv($out, ['When', 'Name', 'Email', 'Role', 'Customer', 'Outcome', 'IP', 'Browser'], ';');
    foreach (all($select . ' LIMIT 50000', $args) as $r) {
        fputcsv($out, [$r['created_at'], $r['full_name'] ?? '', $r['email'], $r['role'] ?? '', $r['customer_name'] ?? '', $r['outcome'], $r['ip'] ?? '', $r['user_agent'] ?? ''], ';');
    }
    exit;
}

$total = (int) scalar("SELECT COUNT(*) {$from_sql}", $args);
$rows  = all($select . ' LIMIT ' . $per . ' OFFSET ' . (($pageNo - 1) * $per), $args);
$keep  = array_filter(['q' => $q, 'outcome' => $outcome, 'from' => $from, 'to' => $to, 'per' => $per]);

/* last 24 h at a glance */
$day = one("SELECT SUM(outcome = 'success') AS ok, SUM(outcome IN ('failed','locked','disabled','twofa_failed')) AS bad,
                   COUNT(DISTINCT CASE WHEN outcome = 'success' THEN user_id END) AS people
            FROM login_log WHERE created_at >= NOW() - INTERVAL 1 DAY") ?: ['ok' => 0, 'bad' => 0, 'people' => 0];

$tone = ['success' => 'green', 'failed' => 'bad', 'locked' => 'bad', 'disabled' => 'gold', 'logout' => 'mute', 'twofa_failed' => 'bad', 'twofa_enrolled' => 'green'];
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
  <div class="kpi kpi--4" style="margin-bottom:10px;"><span class="stat"><b><?= $nDev ?></b><span class="s"><?= tt('mob.devices') ?></span></span><span class="stat"><b><?= $nTok ?></b><span class="s"><?= tt('mob.tokens') ?></span></span><span class="stat<?= $nPush ? ' gold' : '' ?>"><b><?= $nPush ?></b><span class="s"><?= tt('mob.push_pending') ?></span></span><span class="stat"><b><?= e((string) cp_setting('api_min_version_engineer', '0.5.0')) ?></b><span class="s"><?= tt('mob.min_version') ?></span></span></div>
  <form method="post" action="<?= url('admin_log') ?>#mob" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="mob_settings_save"><label><?= tt('mob.min_version') ?> <input name="min_version" value="<?= e((string) cp_setting('api_min_version_engineer', '0.5.0')) ?>" style="width:90px;" pattern="\d+\.\d+\.\d+"></label> <button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button> <span class="hint"><?= tt('mob.min_hint') ?></span></form>
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
      <div class="field"><label><?= tt('sec.close') ?></label><label class="chk"><input type="checkbox" name="close_signout" value="1"<?= sec_cfg('sec_close_signout') ? ' checked' : '' ?>> <?= tt('sec.close_signout') ?></label><p class="hint"><?= tt('sec.close_hint') ?></p></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('sec.save_hint') ?></span></div>
  </form>
</div>
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
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr auto auto;">
    <input type="hidden" name="p" value="admin_log">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>" placeholder="<?= tt('ll.search_ph') ?>"></div>
    <div class="field"><label for="outcome"><?= tt('ll.outcome') ?></label>
      <select id="outcome" name="outcome"><option value=""><?= tt('flt.any') ?></option>
        <?php foreach ($outs as $o): ?><option value="<?= $o ?>"<?= $sel($o, $outcome) ?>><?= e(t('ll.o_' . $o)) ?></option><?php endforeach; ?>
      </select></div>
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

<div class="card">
<?php if (!$rows): ?>
  <div class="empty"><?= tt('ll.none') ?></div>
<?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ll.when') ?></th><th><?= tt('ll.who') ?></th><th><?= tt('au.role') ?></th><th><?= tt('ll.outcome') ?></th><th><?= tt('ll.ip') ?></th><th><?= tt('ll.browser') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr>
        <td class="date"><?= e(fmt_datetime($r['created_at'])) ?></td>
        <td><strong><?= e($r['full_name'] ?? '—') ?></strong><br><span class="hint"><?= e($r['email']) ?></span></td>
        <td><?= $r['role'] ? pill($r['role'] === 'admin' ? 'gold' : ($r['role'] === 'staff' ? 'green' : 'mute'), t('role.' . $r['role'])) . ($r['customer_name'] ? '<br><span class="hint">' . e($r['customer_name']) . '</span>' : '') : '—' ?></td>
        <td><?= pill($tone[$r['outcome']] ?? 'mute', t('ll.o_' . $r['outcome'])) ?></td>
        <td class="num"><?= e($r['ip'] ?: '—') ?></td>
        <td class="hint" title="<?= e($r['user_agent'] ?? '') ?>"><?= e($browser($r['user_agent'])) ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= pager_html('admin_log', $pageNo, $total, $per, $keep) ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

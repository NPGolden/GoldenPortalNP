<?php
/** IT & Cybersecurity — cyber documents of the fleet and the remote support contracts per vessel (GAS-CP-R30-004). */
require __DIR__ . '/_layout.php';
$u = require_login(); $cid = (int)$u['customer_id']; $staff = is_staff();
$docs = $staff
    ? all("SELECT d.*, v.name AS vessel FROM documents d LEFT JOIN vessels v ON v.id=d.vessel_id WHERE d.category='cyber' ORDER BY d.updated_at DESC LIMIT 200")
    : all("SELECT d.*, v.name AS vessel FROM documents d LEFT JOIN vessels v ON v.id=d.vessel_id WHERE d.customer_id=? AND d.category='cyber' ORDER BY d.updated_at DESC LIMIT 200", [$cid]);
$rsOn = function_exists('rs_has') && rs_has();
if ($rsOn) rs_sweep();
$subOn = $rsOn && rs_sub_has(); $salesCan = $subOn && rs_sales_can();   // R97-091
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'vessel' => (int)($_GET['vessel'] ?? 0), 'status' => (string)($_GET['status'] ?? 'live'), 'customer' => $staff ? (int)($_GET['customer'] ?? 0) : 0, 'pay' => $staff ? (string)($_GET['pay'] ?? '') : ''];
$contracts = $rsOn ? rs_list($f) : [];
$vessels = $rsOn ? ($staff ? all('SELECT id, name FROM vessels WHERE is_active = 1 AND merged_into IS NULL ORDER BY name') : all('SELECT id, name FROM vessels WHERE customer_id = ? AND is_active = 1 ORDER BY name', [$cid])) : [];
$customers = $staff && $rsOn ? all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name') : [];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$expiring = $rsOn && $staff ? count(rs_list(['status' => 'expiring'])) : 0; $K = $rsOn && $staff ? rs_kpis() : [];
layout_head(t('cy.title'), 'cyber');
?>
<div class="page-head">
  <div><h1><?= tt('cy.title') ?></h1><p class="lede"><?= tt('cy.lede') ?></p></div>
  <?php if ($rsOn && $staff): ?><div class="btn-row"><a class="btn btn--gold" href="<?= url('rs_edit') ?>">+ <?= tt('rs.new') ?></a></div><?php endif; ?>
</div>

<?php if ($rsOn && $staff): ?>
<div class="kpi kpi--5" style="margin-bottom:16px;">
  <a class="stat green" href="<?= url('cyber', ['status' => 'active']) ?>"><b><?= (int)$K['active'] ?></b><?= tt('rs.k_active') ?><?php if ($K['pending']): ?><span class="s"><?= e(t('rs.k_pending', ['n' => $K['pending']])) ?></span><?php endif; ?></a>
  <a class="stat<?= $K['d30'] ? ' red' : '' ?>" href="<?= url('cyber', ['status' => 'expiring']) ?>"><b><?= (int)$K['d30'] ?></b><?= tt('rs.k_d30') ?></a>
  <a class="stat<?= $K['d90'] ? ' gold' : '' ?>" href="<?= url('cyber', ['status' => 'due90']) ?>"><b><?= (int)$K['d90'] ?></b><?= tt('rs.k_d90') ?></a>
  <a class="stat<?= $K['lic'] ? ' gold' : '' ?>" href="<?= url('cyber', ['status' => 'lic']) ?>"><b><?= (int)$K['lic'] ?></b><?= tt('rs.k_lic') ?></a>
  <a class="stat<?= $K['expired'] ? ' red' : '' ?>" href="<?= url('cyber', ['status' => 'expired']) ?>"><b><?= (int)$K['expired'] ?></b><?= tt('rs.k_expired') ?></a>
</div>
<?php endif; ?>
<?php if ($rsOn): ?>
<div class="card" id="rs-list">
  <div class="card-head"><h2>🛡 <?= tt('rs.title') ?> <span class="count"><?= count($contracts) ?></span></h2>
    <?php if ($expiring): ?><a class="pill pill--gold" href="<?= url('cyber', ['status' => 'expiring']) ?>">⏰ <?= e(t('rs.n_expiring', ['n' => $expiring])) ?></a><?php endif; ?></div>
  <p class="hint"><?= tt('rs.lede') ?></p>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr<?= $staff ? ' 1fr' : '' ?> auto;">
    <input type="hidden" name="p" value="cyber">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" type="text" value="<?= e($f['q']) ?>" placeholder="<?= tt('rs.search_ph') ?>"></div>
    <div class="field"><label for="vessel"><?= tt('v.one') ?></label><select id="vessel" name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label><select id="status" name="status"><option value="live"<?= $sel('live', $f['status']) ?>><?= tt('rs.f_live') ?></option><option value="expiring"<?= $sel('expiring', $f['status']) ?>><?= tt('rs.f_expiring') ?></option><option value="due90"<?= $sel('due90', $f['status']) ?>><?= tt('rs.f_due90') ?></option><?php if ($subOn): ?><option value="lic"<?= $sel('lic', $f['status']) ?>><?= tt('rs.f_lic') ?></option><?php if ($staff): ?><option value="norenew"<?= $sel('norenew', $f['status']) ?>><?= tt('rs.f_norenew') ?></option><?php endif; endif; ?><option value=""<?= $sel('', $f['status']) ?>><?= tt('flt.all') ?></option><?php foreach (rs_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if ($staff): ?><div class="field"><label for="customer"><?= tt('v.customer') ?></label><select id="customer" name="customer"><option value=""><?= tt('flt.any') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $f['customer']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('cyber', ['reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
  <?php if ($salesCan): ?><details style="margin:0 0 12px;"><summary class="hint" style="cursor:pointer;">⤒ <?= tt('rs.imp_title') ?></summary>
    <form method="post" action="<?= url('cyber') ?>" enctype="multipart/form-data" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="rs_import">
      <p class="hint"><?= tt('rs.imp_hint') ?></p>
      <div class="btn-row"><input type="file" name="file" accept=".csv,.xlsx" required><label style="display:inline-flex;gap:6px;align-items:center;"><input type="checkbox" name="mk_vessels" value="1"> <?= tt('rs.imp_mk') ?></label><button class="btn btn--sm" type="submit"><?= tt('rs.imp_btn') ?></button></div>
    </form></details>
  <details style="margin:0 0 12px;"><summary class="hint" style="cursor:pointer;">✉ <?= tt('rs.wk_title') ?> — <?= cp_setting('rs_weekly_on', '1') === '1' ? tt('rs.wk_on') : tt('rs.wk_off') ?></summary>
    <p class="hint" style="margin-top:8px;"><?= e(t('rs.wk_hint', ['n' => count(rs_weekly_recipients())])) ?></p>
    <div class="btn-row"><form method="post" action="<?= url('cyber') ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rs_weekly_now"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('rs.wk_now') ?></button></form>
      <?php if (is_cp_admin() || mgmt_can()): ?><form method="post" action="<?= url('cyber') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rs_weekly_toggle"><input type="hidden" name="on" value="<?= cp_setting('rs_weekly_on', '1') === '1' ? '' : '1' ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= cp_setting('rs_weekly_on', '1') === '1' ? tt('rs.wk_turn_off') : tt('rs.wk_turn_on') ?></button></form><?php endif; ?></div>
  </details><?php endif; ?>
  <?php if (!$contracts): ?><p class="empty"><?= tt('rs.none') ?></p><?php else: ?>
  <table class="tbl rs-tbl"><thead><tr><th><?= tt('rs.no') ?></th><th><?= tt('v.one') ?></th><th><?= tt('rs.title_f') ?></th><th><?= tt('rs.period') ?></th><?php if ($subOn): ?><th><?= tt('rs.lic') ?></th><?php endif; ?><th><?= tt('rs.hours') ?></th><th><?= tt('rs.sla') ?></th><th><?= tt('nav.tickets') ?></th><?php if ($staff): ?><th><?= tt('td.title') ?></th><?php endif; ?><th><?= tt('th.status') ?></th></tr></thead><tbody>
    <?php foreach ($contracts as $c): $tone = rs_expiry_tone($c); $d = rs_days_left($c['end_date']); ?>
    <tr class="rs-row rs-<?= $tone ?: 'none' ?>">
      <td><a href="<?= url('rs', ['id' => $c['id']]) ?>"><b><?= e($c['contract_no']) ?></b></a></td>
      <td><a href="<?= url('vessel', ['id' => $c['vessel_id']]) ?>"><?= e($c['vessel']) ?></a><?= $staff && $c['customer_name'] ? '<div class="hint">' . e($c['customer_name']) . '</div>' : '' ?></td>
      <td><?= e($c['title']) ?><div class="hint"><?= $subOn && $c['product'] ? '<b>' . e($c['product']) . '</b>' . ($staff && $c['serial_no'] ? ' <span class="mono">' . e(trim((string)$c['serial_no'])) . '</span>' : '') . ' · ' : '' ?><?= e(rs_coverages()[$c['coverage']] ?? $c['coverage']) ?><?= $c['access_method'] ? ' · ' . e($c['access_method']) : '' ?><?= $subOn && $c['period_months'] ? ' · ' . e(rs_period_label((int)$c['period_months'])) : '' ?></div></td>
      <td data-sort="<?= e($c['end_date'] ?: '9999') ?>"><?= e(fmt_date($c['start_date'])) ?> – <?= $c['end_date'] ? e(fmt_date($c['end_date'])) : tt('rs.open_ended') ?><?php if ($d !== null && $c['status'] === 'active'): ?><div class="hint rs-days"><?= e($d < 0 ? t('rs.expired_ago', ['n' => -$d]) : t('rs.days_left', ['n' => $d])) ?></div><?php elseif ($d !== null && $d < 0 && $c['status'] === 'expired'): ?><div class="hint rs-days"><?= e(t('rs.expired_ago', ['n' => -$d])) ?></div><?php endif; ?></td>
      <?php if ($subOn): ?><td data-sort="<?= e($c['license_expiry'] ?: '9999') ?>"><?= rs_date_pill($c['license_expiry']) ?></td><?php endif; ?>
      <td data-sort="<?= (float)$c['hours_used'] ?>"><?= e(rtrim(rtrim(number_format((float)$c['hours_used'], 2, '.', ''), '0'), '.')) ?><?= $c['hours_included'] !== null ? ' / ' . e(rtrim(rtrim(number_format((float)$c['hours_included'], 2, '.', ''), '0'), '.')) : '' ?> h</td>
      <td><?= $c['response_hours'] !== null ? e(t('rs.sla_short', ['h' => (int)$c['response_hours']])) : '—' ?></td>
      <td><a href="<?= url('tickets', ['view' => 'all', 'vessel' => $c['vessel_id']]) ?>"><?= (int)$c['n_tickets'] ?></a><?= $c['n_open'] ? ' · <b>' . (int)$c['n_open'] . '</b> ' . tt('rs.open') : '' ?></td>
      <?php if ($staff): ?><td><?= (int)$c['n_tasks'] ?></td><?php endif; ?>
      <td><?= rs_status_pill($c['status']) ?><?php if ($subOn && $staff): ?> <?= rs_pay_pill($c['pay_status']) ?><?php if ($c['ren_no'] && function_exists('of_visible') && of_visible()): ?><div class="hint"><?= tt('rs.ren_offer') ?> <a href="<?= url('offer', ['id' => (int)$c['renewal_offer_id']]) ?>"><?= e($c['ren_no']) ?></a> <?= of_status_pill((string)$c['ren_status']) ?></div><?php elseif ($c['sq_ref']): ?><div class="hint mono"><?= $c['offer_id'] && function_exists('of_visible') && of_visible() ? '<a href="' . url('offer', ['id' => (int)$c['offer_id']]) . '">' . e($c['sq_ref']) . '</a>' : e($c['sq_ref']) ?></div><?php endif; endif; ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('cy.docs') ?></h2>
<?php if (!$docs): ?><div class="empty"><?= tt('cy.empty') ?></div>
<?php else: foreach ($docs as $d): ?>
  <div class="rowitem">
    <span class="ico">🛡</span>
    <span class="tx"><b><?= e($d['title']) ?></b><span><?= e($d['vessel'] ?: '—') ?> · <span class="date"><?= e(fmt_date($d['updated_at'])) ?></span></span></span>
    <?= doc_status_pill($d['status']) ?>
  </div>
<?php endforeach; endif; ?>
</div>
<?php layout_foot(); ?>

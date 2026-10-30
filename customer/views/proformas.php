<?php
/** Commercial › Proformas — every proforma invoice, open / overdue / paid, totals (GAS-CP-R44-003/004). ?p=proformas[&status=][&vessel=][&pm=][&q=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('pi_can_view') || !pi_can_view()) { http_response_code(403); layout_head(t('err.noaccess'), 'proformas'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = ['status' => (string)($_GET['status'] ?? 'open'), 'vessel' => (int)($_GET['vessel'] ?? 0), 'pm' => (int)($_GET['pm'] ?? 0), 'project' => (int)($_GET['project'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = pi_list($f); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$tot = ['gross' => 0.0, 'paid' => 0.0, 'open' => 0.0, 'overdue' => 0.0]; $cur = 'EUR';
foreach ($rows as $r) { if (in_array($r['status'], ['draft', 'cancelled'], true)) continue; $cur = $r['currency']; $tot['gross'] += (float)$r['gross']; $tot['paid'] += min((float)$r['gross'], (float)$r['paid_sum']); $o = max(0, (float)$r['gross'] - (float)$r['paid_sum']); $tot['open'] += $o; if (pi_overdue($r)) $tot['overdue'] += $o; }
$c = []; foreach (all("SELECT status, COUNT(*) n FROM proformas GROUP BY status") as $x) $c[$x['status']] = (int)$x['n']; $nOver = (int) scalar("SELECT COUNT(*) FROM proformas WHERE status IN ('issued','sent','partly_paid') AND due_date < CURDATE()");
$vessels = all("SELECT DISTINCT v.id, v.name FROM proformas pi JOIN vessels v ON v.id = pi.vessel_id ORDER BY v.name"); $pms = all("SELECT DISTINCT cu.id, cu.full_name FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name");
layout_head(t('pi.title'), 'proformas');
?>
<div class="page-head"><div><h1>💶 <?= tt('pi.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pi.lede') ?></p></div></div>
<div class="kpi kpi--4">
  <a class="stat" href="<?= url('proformas', ['status' => 'open']) ?>"><b><?= (int)($c['issued'] ?? 0) + (int)($c['sent'] ?? 0) + (int)($c['partly_paid'] ?? 0) + (int)($c['draft'] ?? 0) ?></b><span class="s"><?= tt('pi.k_open') ?></span></a>
  <a class="stat<?= $nOver ? ' red' : '' ?>" href="<?= url('proformas', ['status' => 'overdue']) ?>"><b><?= $nOver ?></b><span class="s"><?= tt('pi.overdue') ?></span></a>
  <a class="stat gold" href="<?= url('proformas', ['status' => 'partly_paid']) ?>"><b><?= (int)($c['partly_paid'] ?? 0) ?></b><span class="s"><?= tt('pi.s_partly_paid') ?></span></a>
  <a class="stat green" href="<?= url('proformas', ['status' => 'paid']) ?>"><b><?= (int)($c['paid'] ?? 0) ?></b><span class="s"><?= tt('pi.s_paid') ?></span></a>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="proformas">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('pi.search_ph') ?>"></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= $sel('open', $f['status']) ?>><?= tt('pi.k_open') ?></option><option value="overdue"<?= $sel('overdue', $f['status']) ?>><?= tt('pi.overdue') ?></option><?php foreach (PI_STATUSES as $st): ?><option value="<?= $st ?>"<?= $sel($st, $f['status']) ?>><?= e(t('pi.s_' . $st)) ?></option><?php endforeach; ?><option value="all"<?= $sel('all', $f['status']) ?>><?= tt('flt.all') ?></option></select></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['vessel']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('proformas') ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <?php if (!$rows): ?><p class="hint"><?= tt('pi.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('pi.description') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('of.one') ?></th><th><?= tt('pi.issue_date') ?></th><th><?= tt('pi.due') ?></th><th class="num"><?= tt('pi.gross') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th class="num"><?= tt('pi.open') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('pi.invoices') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $open = max(0, (float)$r['gross'] - (float)$r['paid_sum']); $od = pi_overdue($r); ?>
    <tr class="<?= in_array($r['status'], ['paid', 'cancelled'], true) ? 'is-muted' : '' ?>">
      <td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>"><b><?= e($r['pi_no']) ?></b></a></td>
      <td><?= e($r['title']) ?><?= $r['term_n'] ? '<br><span class="hint">' . e(t('pt.instalment', ['n' => (int)$r['term_n']])) . '</span>' : '' ?></td>
      <td><?= e($r['vessel'] ?: '—') ?></td>
      <td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? '<br><span class="hint">' . e($r['sub_code']) . '</span>' : '' ?></td>
      <td><a href="<?= url('offer', ['id' => (int)$r['offer_id']]) ?>"><?= e($r['offer_no']) ?></a></td>
      <td class="date"><?= $r['issue_date'] ? e(fmt_date($r['issue_date'])) : '—' ?></td>
      <td class="date<?= $od ? ' is-late' : '' ?>"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '—' ?><?= $od ? ' ' . pill('bad', t('pi.overdue')) : '' ?></td>
      <td class="num"><b><?= e(pi_money((float)$r['gross'], $r['currency'])) ?></b></td>
      <td class="num"><?= e(pi_money((float)$r['paid_sum'], $r['currency'])) ?></td>
      <td class="num"><?= e(pi_money($open, $r['currency'])) ?></td>
      <td><?= pi_status_pill($r['status']) ?></td>
      <td class="num"><?= (int)$r['n_inv'] ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody><tfoot><tr><td colspan="7"><b><?= e(t('pi.count', ['n' => count($rows)])) ?></b></td><td class="num"><b><?= e(pi_money($tot['gross'], $cur)) ?></b></td><td class="num"><b><?= e(pi_money($tot['paid'], $cur)) ?></b></td><td class="num"><b><?= e(pi_money($tot['open'], $cur)) ?></b><?= $tot['overdue'] > 0 ? '<br><span class="is-late">' . tt('pi.overdue') . ' ' . e(pi_money($tot['overdue'], $cur)) . '</span>' : '' ?></td><td colspan="2"></td></tr></tfoot></table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

<?php
/** Accounting › Collections — open items per vessel / project, financial status per project, collected in the period,
 *  projected collection of the next 30 days, follow-ups.                                              GAS-CP-R78-001
 *   ?p=collections[&vessel=][&project=][&customer=][&pm=][&bucket=current|d30|d60|d90|d90p|overdue|followup][&q=][&from=][&to=][&format=xlsx][&view=open|projects|paid] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('col_can') || !col_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'collections'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('col.noaccess_hint') . '</p>'; layout_foot(); return; }
$f = ['vessel' => (int)($_GET['vessel'] ?? 0), 'project' => (int)($_GET['project'] ?? 0), 'customer' => (int)($_GET['customer'] ?? 0), 'pm' => (int)($_GET['pm'] ?? 0), 'bucket' => (string)($_GET['bucket'] ?? ''), 'q' => trim((string)($_GET['q'] ?? ''))];
$d = fn($v, $def) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $def;
$from = $d($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days'))); $to = $d($_GET['to'] ?? '', date('Y-m-d')); if ($from > $to) [$from, $to] = [$to, $from];
$fAll = array_diff_key($f, ['bucket' => 1]);
$open = col_open($f); $S = col_summary(col_open($fAll), 30, $fAll); $projects = col_projects($fAll); $paid = col_paid($from, $to, $fAll);
if (($_GET['format'] ?? '') === 'xlsx') { col_xlsx($open, $projects, $paid, $S, $from, $to); exit; }
$cur = $S['cur']; $m = fn(float $v) => col_money($v, $cur); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$keep = array_filter($f + ['from' => $from, 'to' => $to]);
$vessels = all('SELECT DISTINCT v.id, v.name FROM proformas pi JOIN vessels v ON v.id = pi.vessel_id ORDER BY v.name');
$customers = all('SELECT DISTINCT c.id, c.name FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN customers c ON c.id = p.customer_id ORDER BY c.name');
$pms = all('SELECT DISTINCT cu.id, cu.full_name FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name');
$paidSum = 0.0; foreach ($paid as $r) $paidSum += (float)$r['amount'];
$wkMax = max(1.0, $S['late']['amount'], ...array_map(fn($w) => $w['amount'], $S['weeks']));
$view = in_array($_GET['view'] ?? '', ['open', 'projects', 'paid'], true) ? $_GET['view'] : 'open';
layout_head(t('col.title'), 'collections');
?>
<div class="page-head">
  <div><h1>📥 <?= tt('col.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('col.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('collections', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a><button type="button" class="btn btn--ghost btn--sm" data-print>🖨 <?= tt('btn.print') ?></button></div>
</div>

<div class="kpi kpi--4" style="margin-bottom:10px;">
  <a class="stat" href="<?= url('collections', array_filter($fAll)) ?>"><b><?= e($m($S['outstanding'])) ?></b><?= tt('fs.outstanding') ?><span class="s"><?= e(t('col.n_items', ['n' => $S['n_open']])) ?> · <?= e(t('col.promised_s', ['v' => $m($S['promised'])])) ?></span></a>
  <a class="stat<?= $S['overdue'] > 0 ? ' red' : '' ?>" href="<?= url('collections', array_filter($fAll) + ['bucket' => 'overdue']) ?>"><b><?= e($m($S['overdue'])) ?></b><?= tt('fs.overdue') ?><span class="s"><?= e(t('col.n_items', ['n' => $S['n_overdue']])) ?><?= $S['n_missed'] ? ' · ' . e(t('col.missed_s', ['n' => $S['n_missed'], 'v' => $m($S['missed'])])) : '' ?></span></a>
  <a class="stat gold" href="#col-proj"><b><?= e($m($S['expected_total'])) ?></b><?= tt('col.expected_30') ?><span class="s"><?= e(t('col.expected_split', ['a' => $m($S['expected']), 'b' => $m($S['late']['amount'])])) ?></span></a>
  <a class="stat green" href="#col-paid"><b><?= e($m($S['collected'])) ?></b><?= tt('col.collected_30') ?><span class="s"><?= e(t('col.n_payments', ['n' => $S['n_collected']])) ?><?= $S['fu_due'] ? ' · ' . e(t('col.fu_due_s', ['n' => $S['fu_due']])) : '' ?></span></a>
</div>

<div class="grid grid-2" style="margin-bottom:10px;">
  <div class="card" id="col-proj">
    <h2>📈 <?= tt('col.projection') ?></h2>
    <p class="hint" style="margin-top:0;"><?= tt('col.projection_hint') ?></p>
    <table class="tbl col-proj"><tbody>
      <tr class="<?= $S['late']['amount'] > 0 ? 'is-late' : '' ?>"><td><?= tt('col.late_total') ?><br><span class="hint"><?= e(t('col.n_items', ['n' => $S['late']['n']])) ?></span></td><td class="bar"><span class="col-bar col-bar--late" style="width:<?= (int) round($S['late']['amount'] / $wkMax * 100) ?>%"></span></td><td class="num"><b><?= e($m($S['late']['amount'])) ?></b></td></tr>
      <?php foreach ($S['weeks'] as $i => $w): ?><tr><td><?= e(t('col.week_n', ['n' => $i + 1])) ?><br><span class="hint"><?= e(fmt_date($w['from'])) ?> – <?= e(fmt_date($w['to'])) ?> · <?= e(t('col.n_items', ['n' => $w['n']])) ?></span></td><td class="bar"><span class="col-bar" style="width:<?= (int) round($w['amount'] / $wkMax * 100) ?>%"></span></td><td class="num"><b><?= e($m($w['amount'])) ?></b></td></tr><?php endforeach; ?>
      <tr><td><b><?= tt('ob.c_total') ?></b></td><td></td><td class="num"><b><?= e($m($S['expected_total'])) ?></b></td></tr>
    </tbody></table>
  </div>
  <div class="card">
    <h2>⏳ <?= tt('col.aging') ?></h2>
    <p class="hint" style="margin-top:0;"><?= tt('col.aging_hint') ?></p>
    <table class="tbl col-proj"><tbody>
      <?php $bMax = max(1.0, ...array_values($S['buckets'])); foreach (COL_BUCKETS as $b): ?><tr><td><a href="<?= url('collections', array_filter($fAll) + ['bucket' => $b]) ?>"><?= col_bucket_pill($b) ?></a></td><td class="bar"><span class="col-bar<?= in_array($b, ['d90', 'd90p'], true) ? ' col-bar--late' : ($b === 'current' ? ' col-bar--ok' : '') ?>" style="width:<?= (int) round($S['buckets'][$b] / $bMax * 100) ?>%"></span></td><td class="num"><b><?= e($m($S['buckets'][$b])) ?></b></td></tr><?php endforeach; ?>
      <tr><td><b><?= tt('ob.c_total') ?></b></td><td></td><td class="num"><b><?= e($m($S['outstanding'])) ?></b></td></tr>
    </tbody></table>
  </div>
</div>

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.3fr 1fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="collections"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('col.search_ph') ?>"></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['vessel']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('nav.customers') ?></label><select name="customer"><option value=""><?= tt('flt.any') ?></option><?php foreach ($customers as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['customer']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('col.bucket') ?></label><select name="bucket" data-nosearch><option value=""><?= tt('flt.any') ?></option><option value="overdue"<?= $sel('overdue', $f['bucket']) ?>><?= tt('fs.overdue') ?></option><option value="followup"<?= $sel('followup', $f['bucket']) ?>><?= tt('col.fu_due') ?></option><?php foreach (COL_BUCKETS as $b): ?><option value="<?= $b ?>"<?= $sel($b, $f['bucket']) ?>><?= e(t('col.b_' . $b)) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('collections') ?>"><?= tt('btn.clear') ?></a></div>
</form>

<div class="card" id="col-open">
  <h2>📋 <?= tt('col.open_items') ?> <span class="count"><?= count($open) ?></span></h2>
  <?php if (!$open): ?><p class="hint"><?= tt('col.none_open') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl col-open" data-nosort>
    <thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('pi.description') ?></th><th><?= tt('pi.issue_date') ?></th><th><?= tt('pi.due') ?></th><th class="num"><?= tt('col.age') ?></th><th class="num"><?= tt('fr.gross') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th class="num"><?= tt('fr.open') ?></th><th><?= tt('col.promise') ?></th><th><?= tt('col.next') ?></th><th><?= tt('col.last_fu') ?></th><th></th></tr></thead>
    <tbody>
    <?php $gv = null; $gp = null; $gSum = 0.0; $gN = 0;
    $groupFoot = function () use (&$gSum, &$gN, $m) { if ($gN) echo '<tr class="col-gsum"><td colspan="7" class="hint">' . e(t('col.n_items', ['n' => $gN])) . '</td><td class="num"><b>' . e($m($gSum)) . '</b></td><td colspan="4"></td></tr>'; $gSum = 0.0; $gN = 0; };
    foreach ($open as $r):
      if ($r['vessel_id'] !== $gv || $r['project_id'] !== $gp): $groupFoot(); $gv = $r['vessel_id']; $gp = $r['project_id']; ?>
      <tr class="col-group"><td colspan="12">⚓ <b><?= e($r['vessel'] ?: t('pr.novessel')) ?></b> › <?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['customer_name'] ? ' · ' . e($r['customer_name']) : '' ?><?= $r['pm_name'] ? ' · PM ' . e($r['pm_name']) : '' ?> <a class="hint" href="<?= url('report_fin', ['project' => (int)$r['project_id']]) ?>">(<?= tt('fr.title') ?>)</a></td></tr>
      <?php endif; $gSum += $r['open']; $gN++; $fid = 'fu' . (int)$r['id']; ?>
      <tr class="<?= $r['overdue'] ? 'is-late' : '' ?>">
        <td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>"><b><?= e($r['pi_no']) ?></b></a><?= $r['invoice_nos'] ? '<br><span class="hint">' . e($r['invoice_nos']) . '</span>' : '' ?></td>
        <td><?= e($r['title']) ?><?= $r['term_n'] ? ' <span class="hint">' . e(t('pt.instalment', ['n' => (int)$r['term_n']])) . '</span>' : '' ?><br><span class="hint"><?= e($r['offer_no']) ?><?= $r['sub_code'] ? ' · ' . e($r['sub_code']) : '' ?></span></td>
        <td class="date"><?= e(fmt_date($r['issue_date'])) ?></td>
        <td class="date<?= $r['overdue'] ? ' is-late' : '' ?>"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '—' ?></td>
        <td class="num"><?= $r['age'] > 0 ? '<b>' . (int)$r['age'] . '</b>' : '<span class="hint">' . e(t('col.due_in', ['n' => -(int)$r['age']])) . '</span>' ?><br><?= col_bucket_pill($r['bucket']) ?></td>
        <td class="num"><?= e($m((float)$r['gross'])) ?></td>
        <td class="num"><?= e($m((float)$r['paid_sum'])) ?><?= $r['last_paid_on'] ? '<br><span class="hint">' . e(fmt_date($r['last_paid_on'])) . '</span>' : '' ?></td>
        <td class="num"><b><?= e($m($r['open'])) ?></b></td>
        <td class="date"><?= $r['fu_promise'] ? e(fmt_date($r['fu_promise'])) . ($r['promise_missed'] ? '<br>' . pill('bad', t('col.promise_missed')) : '') : '—' ?></td>
        <td class="date"><?= $r['fu_next'] ? ($r['fu_due'] ? '<b>' . e(fmt_date($r['fu_next'])) . '</b><br>' . pill('gold', t('col.fu_due')) : e(fmt_date($r['fu_next']))) : '—' ?></td>
        <td class="hint" style="max-width:220px;"><?= $r['fu_note'] ? e($r['fu_note']) . '<br>' : '' ?><?= $r['fu_by'] ? '<span class="hint">' . e($r['fu_by']) . ' · ' . e(fmt_date($r['fu_at'])) . '</span>' : '' ?></td>
        <td><button type="button" class="btn btn--ghost btn--sm" data-toggle="#<?= $fid ?>" title="<?= tt('col.followup') ?>">✎</button></td>
      </tr>
      <tr id="<?= $fid ?>" class="col-fu is-hidden"><td colspan="12">
        <form method="post" action="<?= url('collections', $keep) ?>" class="col-fu-form"><?= csrf_field() ?><input type="hidden" name="action" value="col_fu"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="field"><label><?= tt('col.promise') ?></label><input type="date" name="promise" value="<?= e($r['fu_promise'] ?? '') ?>"></div>
          <div class="field"><label><?= tt('col.next') ?></label><input type="date" name="next" value="<?= e($r['fu_next'] ?? '') ?>"></div>
          <div class="field" style="flex:1;"><label><?= tt('pd.note') ?></label><input name="note" maxlength="300" placeholder="<?= tt('col.fu_ph') ?>"></div>
          <button class="btn btn--gold btn--sm" type="submit"><?= tt('col.fu_save') ?></button>
          <?php $hist = col_followups((int)$r['id']); if ($hist): ?><details class="col-hist"><summary><?= e(t('col.fu_history', ['n' => count($hist)])) ?></summary><ul><?php foreach ($hist as $h): ?><li><span class="hint"><?= e(fmt_date($h['created_at'])) ?> · <?= e($h['user_name'] ?? '') ?></span> — <?= $h['promise_at'] ? e(t('col.promise')) . ' ' . e(fmt_date($h['promise_at'])) . ' · ' : '' ?><?= $h['next_at'] ? e(t('col.next')) . ' ' . e(fmt_date($h['next_at'])) . ' · ' : '' ?><?= e($h['note'] ?? '') ?></li><?php endforeach; ?></ul></details><?php endif; ?>
        </form>
      </td></tr>
    <?php endforeach; $groupFoot(); ?>
    </tbody>
    <tfoot><tr><td colspan="7"><b><?= e(t('col.n_items', ['n' => count($open)])) ?></b></td><td class="num"><b><?= e($m(array_sum(array_column($open, 'open')))) ?></b></td><td colspan="4"></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="col-projects">
  <h2>🚢 <?= tt('col.per_project') ?> <span class="count"><?= count($projects) ?></span></h2>
  <?php if (!$projects): ?><p class="hint"><?= tt('col.none_projects') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('th.vessel') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('fr.offered_gross') ?></th><th class="num"><?= tt('fs.invoiced') ?></th><th class="num"><?= tt('fs.collected') ?></th><th class="num"><?= tt('fs.outstanding') ?></th><th class="num"><?= tt('fs.overdue') ?></th><th class="num"><?= tt('fr.to_invoice') ?></th><th></th></tr></thead>
    <tbody><?php $T = ['offered_gross' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0, 'to_invoice' => 0.0]; foreach ($projects as $p): foreach ($T as $k => $v) $T[$k] += (float)$p[$k]; ?>
      <tr class="<?= $p['overdue'] > 0 ? 'is-late' : '' ?>"><td><?= e($p['vessel'] ?: '—') ?></td><td><?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?><?= $p['customer_name'] ? '<br><span class="hint">' . e($p['customer_name']) . ($p['pm_name'] ? ' · PM ' . e($p['pm_name']) : '') . '</span>' : '' ?></td><td><?= pill(pm_status_tone((string)$p['status']), t('pr.' . $p['status'])) ?></td>
        <td class="num"><?= e($m((float)$p['offered_gross'])) ?><br><span class="hint"><?= (int)$p['n_offers'] ?> <?= tt('of.title') ?></span></td><td class="num"><?= e($m($p['invoiced'])) ?><br><span class="hint"><?= (int)$p['n_pi'] ?> PI</span></td><td class="num"><?= e($m($p['collected'])) ?></td><td class="num"><b><?= e($m($p['outstanding'])) ?></b></td><td class="num"><?= $p['overdue'] > 0 ? '<b>' . e($m($p['overdue'])) . '</b><br><span class="hint">' . (int)$p['n_overdue'] . ' PI</span>' : '—' ?></td><td class="num"><?= e($m($p['to_invoice'])) ?></td>
        <td><a class="btn btn--ghost btn--sm" href="<?= url('report_fin', ['project' => (int)$p['id']]) ?>" title="<?= tt('fr.title') ?>">📄</a></td></tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="3"><b><?= tt('ob.c_total') ?></b></td><td class="num"><b><?= e($m($T['offered_gross'])) ?></b></td><td class="num"><b><?= e($m($T['invoiced'])) ?></b></td><td class="num"><b><?= e($m($T['collected'])) ?></b></td><td class="num"><b><?= e($m($T['outstanding'])) ?></b></td><td class="num"><b><?= e($m($T['overdue'])) ?></b></td><td class="num"><b><?= e($m($T['to_invoice'])) ?></b></td><td></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>

<div class="card" id="col-paid">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">💳 <?= tt('col.collected_period') ?> <span class="count"><?= count($paid) ?></span></h2>
    <form method="get" action="index.php" class="btn-row" style="align-items:flex-end;gap:8px;"><input type="hidden" name="p" value="collections"><?php foreach (array_filter($f) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
      <div class="field"><label><?= tt('th.from') ?></label><input type="date" name="from" value="<?= e($from) ?>"></div><div class="field"><label><?= tt('th.to') ?></label><input type="date" name="to" value="<?= e($to) ?>"></div><button class="btn btn--sm" type="submit" formaction="index.php#col-paid"><?= tt('btn.apply') ?></button></form></div>
  <?php if (!$paid): ?><p class="hint"><?= tt('pay.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('th.date') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('pi.no') ?></th><th><?= tt('pi.invoice_no') ?></th><th class="num"><?= tt('pt.amount') ?></th><th><?= tt('pay.method') ?></th><th><?= tt('pay.reference') ?></th><th><?= tt('pd.note') ?></th><th><?= tt('th.by') ?></th></tr></thead>
    <tbody><?php foreach ($paid as $r): ?><tr><td class="date"><?= e(fmt_date($r['paid_on'])) ?></td><td><?= e($r['vessel'] ?: '—') ?></td><td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['customer_name'] ? '<br><span class="hint">' . e($r['customer_name']) . '</span>' : '' ?></td><td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['proforma_id']]) ?>#payments"><?= e($r['pi_no']) ?></a><?= pill($r['status'] === 'paid' ? 'green' : 'gold', t('pi.s_' . $r['status'])) ?></td><td class="mono"><?= e($r['invoice_no'] ?: '—') ?></td><td class="num"><b><?= e($m((float)$r['amount'])) ?></b></td><td><?= e(t('pay.m_' . $r['method'])) ?></td><td><?= e($r['reference'] ?: '') ?></td><td class="hint"><?= e($r['note'] ?: '') ?></td><td class="hint"><?= e($r['by_name'] ?: '') ?></td></tr><?php endforeach; ?></tbody>
    <tfoot><tr><td colspan="5"><b><?= e(t('col.n_payments', ['n' => count($paid)])) ?></b> · <?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?></td><td class="num"><b><?= e($m($paidSum)) ?></b></td><td colspan="4"></td></tr></tfoot>
  </table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>

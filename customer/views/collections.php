<?php
/** Accounting › Collections — open items per vessel / project, financial status per project, collected in the period,
 *  projected collection of the next 30 days, follow-ups.                                              GAS-CP-R78-001
 *   ?p=collections[&vessel=][&project=][&customer=][&pm=][&bucket=current|d30|d60|d90|d90p|overdue|followup][&q=][&from=][&to=][&format=xlsx][&view=open|projects|paid] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('col_can') || !col_can()) { http_response_code(403); layout_head(t('err.noaccess'), 'collections'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('col.noaccess_hint') . '</p>'; layout_foot(); return; }
$f = ['vessel' => (int)($_GET['vessel'] ?? 0), 'project' => (int)($_GET['project'] ?? 0), 'customer' => (int)($_GET['customer'] ?? 0), 'pm' => (int)($_GET['pm'] ?? 0), 'bucket' => (string)($_GET['bucket'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'party' => (int)($_GET['party'] ?? 0)];
$d = fn($v, $def) => ($v && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v)) ? $v : $def;
$from = $d($_GET['from'] ?? '', date('Y-m-d', strtotime('-30 days'))); $to = $d($_GET['to'] ?? '', date('Y-m-d')); if ($from > $to) [$from, $to] = [$to, $from];
$fAll = array_diff_key($f, ['bucket' => 1]);
$open = col_open($f); $S = col_summary(col_open($fAll), 30, $fAll); $projects = col_projects($fAll); $paid = col_paid($from, $to, $fAll);
if (($_GET['format'] ?? '') === 'xlsx') { col_xlsx($open, $projects, $paid, $S, $from, $to); exit; }
// R94-001 accounting checks: sub-project invoicing coverage (completed included), proforma ↔ invoices mismatch, issued proformas without invoice
$chk = function_exists('pi_check_coverage'); $cf = ['project' => $f['project'], 'status' => (string)($_GET['cs'] ?? 'all'), 'inv' => (string)($_GET['ci'] ?? 'open')]; $cdays = max(1, min(365, (int)($_GET['cd'] ?? 30)));
$cov = $chk ? pi_check_coverage($cf) : []; $mis = $chk ? pi_check_mismatch() : []; $unv = $chk ? pi_check_uninvoiced($cdays) : [];
if (($_GET['format'] ?? '') === 'checks' && $chk) { col_checks_xlsx($cov, $mis, $unv, $cdays); exit; }
$cur = $S['cur']; $m = fn(float $v) => col_money($v, $cur); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$keep = array_filter($f + ['from' => $from, 'to' => $to]);
$vessels = all('SELECT DISTINCT v.id, v.name FROM proformas pi JOIN vessels v ON v.id = pi.vessel_id ORDER BY v.name');
$parties = function_exists('col_statement_parties') ? col_statement_parties() : [];   // R95-005
$customers = all('SELECT DISTINCT c.id, c.name FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN customers c ON c.id = p.customer_id ORDER BY c.name');
$pms = all('SELECT DISTINCT cu.id, cu.full_name FROM proformas pi JOIN projects p ON p.id = pi.project_id JOIN customer_users cu ON cu.id = p.pm_user_id ORDER BY cu.full_name');
$paidSum = 0.0; foreach ($paid as $r) $paidSum += (float)$r['amount'];
$wkMax = max(1.0, $S['late']['amount'], ...array_map(fn($w) => $w['amount'], $S['weeks']));
$view = in_array($_GET['view'] ?? '', ['open', 'projects', 'paid'], true) ? $_GET['view'] : 'open';
layout_head(t('col.title'), 'collections');
?>
<div class="page-head">
  <div><h1>📥 <?= tt('col.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('col.lede') ?></p></div>
  <div class="btn-row"><?php if (function_exists('pi_kind_has') && pi_kind_has() && pi_can_invoices()): ?><a class="btn btn--gold btn--sm" href="<?= url('invoice_new') ?>">+ <?= tt('pi.di_new') ?></a><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('collections', $keep + ['format' => 'xlsx']) ?>">⤓ Excel</a><button type="button" class="btn btn--ghost btn--sm" data-print>🖨 <?= tt('btn.print') ?></button></div>
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

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.3fr 1fr 1fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="collections"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('col.search_ph') ?>"></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['vessel']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pty.party') ?></label><select name="party"><option value=""><?= tt('flt.any') ?></option><?php foreach ($parties as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['party']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('nav.customers') ?></label><select name="customer"><option value=""><?= tt('flt.any') ?></option><?php foreach ($customers as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['customer']) ?>><?= e($x['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>PM</label><select name="pm"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pms as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $f['pm']) ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('col.bucket') ?></label><select name="bucket" data-nosearch><option value=""><?= tt('flt.any') ?></option><option value="overdue"<?= $sel('overdue', $f['bucket']) ?>><?= tt('fs.overdue') ?></option><option value="followup"<?= $sel('followup', $f['bucket']) ?>><?= tt('col.fu_due') ?></option><?php foreach (COL_BUCKETS as $b): ?><option value="<?= $b ?>"<?= $sel($b, $f['bucket']) ?>><?= e(t('col.b_' . $b)) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('collections') ?>"><?= tt('btn.clear') ?></a></div>
</form>

<?php $svs = col_statement_vessels(); $selV = array_values(array_unique(array_map('intval', (array)($_GET['v'] ?? [])))); if (!$selV && $f['vessel']) $selV = [$f['vessel']];
  $asOf = $d($_GET['asof'] ?? '', date('Y-m-d')); $soaFrom = $d($_GET['sfrom'] ?? '', '');
  // R95-005: the statement is addressed to a billing party; the vessels are sections (tick some to narrow it), a vessel-only statement stays available
  $selP = (int)($_GET['sparty'] ?? 0) ?: (int)$f['party']; $pRow = null; foreach ($parties as $x) if ((int)$x['id'] === $selP) $pRow = $x; if (!$pRow) $selP = 0;
  $SD = ($selV || $selP) ? col_statement($selV, $asOf, $soaFrom ?: null, $selP ?: null) : null;
  $stKey = ($selV || $selP) ? 'st:' . implode(',', array_merge($selP ? ['o' . $selP] : [], $selV)) . ':%s:' . $asOf . ($soaFrom ? ':' . $soaFrom : '') : null;
  $selNames = array_map(fn($x) => (string)$x['name'], array_filter($svs, fn($x) => in_array((int)$x['id'], $selV, true)));
  if ($selP) $selNames = array_merge([(string)$pRow['name']], $selNames);
  $cid = $SD && !empty($SD['customer_id']) ? (int)$SD['customer_id'] : 0; if (!$cid) foreach ($svs as $x) if (in_array((int)$x['id'], $selV, true)) { $cid = (int) scalar('SELECT customer_id FROM vessels WHERE id = ?', [(int)$x['id']]); break; } ?>
<div class="card" id="col-soa">
  <h2>📄 <?= tt('soa.title') ?></h2>
  <p class="hint" style="margin-top:0;"><?= tt('soa.hint') ?></p>
  <form method="get" action="index.php#col-soa" class="soa-form"><input type="hidden" name="p" value="collections"><?php foreach (array_filter($f) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>">
    <div class="btn-row" style="align-items:flex-end;gap:10px;">
      <div class="field" style="min-width:260px;"><label><?= tt('soa.party_lbl') ?></label><select name="sparty"><option value=""><?= tt('soa.party_none') ?></option><?php foreach ($parties as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $sel($x['id'], $selP) ?>><?= e($x['name']) ?> · <?= (int)$x['n'] ?><?= (float)$x['open_sum'] > 0.005 ? ' · ' . e($m((float)$x['open_sum'])) : '' ?></option><?php endforeach; ?></select></div>
      <div class="field" style="min-width:320px;"><label><?= tt('soa.vessels_lbl') ?></label>
        <details class="msel" data-msel data-empty="<?= tt('soa.pick_vessels') ?>" data-one="<?= tt('soa.n_vessels_1') ?>" data-many="<?= tt('soa.n_vessels') ?>"><summary class="msel-sum"><?= tt('soa.pick_vessels') ?></summary>
          <div class="msel-pop"><input type="search" class="msel-q" placeholder="<?= tt('pr.search') ?>…" autocomplete="off">
            <div class="msel-list"><?php if (!$svs): ?><p class="hint"><?= tt('pi.none') ?></p><?php endif; ?><?php foreach ($svs as $x): ?><label class="chk msel-i" data-text="<?= e(mb_strtolower($x['name'] . ' ' . ($x['customer_name'] ?: ''))) ?>"><input type="checkbox" name="v[]" value="<?= (int)$x['id'] ?>"<?= in_array((int)$x['id'], $selV, true) ? ' checked' : '' ?>> <b><?= e($x['name']) ?></b> <span class="hint"><?= e($x['customer_name'] ?: '') ?> · <?= (int)$x['n'] ?> PI<?= (float)$x['gross_open'] > 0 ? ' · ' . e($m((float)$x['gross_open'])) . ' ' . tt('fr.open') : '' ?></span></label><?php endforeach; ?></div>
            <div class="msel-foot"><button type="button" class="linkbtn msel-none"><?= tt('soa.none_sel') ?></button></div>
          </div>
        </details>
      </div>
      <div class="field"><label><?= tt('soa.as_of_lbl') ?></label><input type="date" name="asof" value="<?= e($asOf) ?>"></div>
      <div class="field"><label><?= tt('soa.from_lbl') ?></label><input type="date" name="sfrom" value="<?= e($soaFrom) ?>"></div>
      <button class="btn btn--sm" type="submit"><?= tt('soa.prepare') ?></button>
      <?php if ($SD): $q = array_filter(['party' => $selP ?: null, 'v' => $selV ?: null]) + ['asof' => $asOf] + ($soaFrom ? ['from' => $soaFrom] : []); ?>
        <a class="btn btn--gold btn--sm" href="<?= url('statement', $q) ?>" target="_blank">⤓ PDF</a><a class="btn btn--ghost btn--sm" href="<?= url('statement', $q + ['format' => 'xlsx']) ?>">⤓ Excel</a>
      <?php endif; ?>
    </div>
  </form>
  <?php if (($selV || $selP) && !$SD): ?><p class="hint"><?= tt('soa.nothing') ?></p><?php endif; ?>
  <?php if ($SD): ?>
    <p class="hint" style="margin:8px 0 0;">📄 <b><?= e($SD['doc_no']) ?></b> · <?= tt('soa.to') ?>: <b><?= e(!empty($SD['multi_party']) ? t('soa.several_parties') : (($SD['party']['name'] ?? '') ?: ($SD['customer_name'] ?: '—'))) ?></b><?= !empty($SD['owner_then']) ? ' <span class="hint">(' . e(t('soa.owner_then', ['d' => fmt_date($asOf)])) . ')</span>' : '' ?> · <?= e(t('soa.sections_n', ['n' => count($SD['vessels']), 'd' => $SD['totals']['n']])) ?> · <?= tt('fr.open') ?> <b><?= e($m((float)$SD['totals']['open'])) ?></b></p>
    <?php if (!empty($SD['multi_party'])): ?><div class="flash flash--warn" style="margin:8px 0 0;"><?= tt('soa.multi_party_hint') ?> <?php foreach ($SD['parties'] as $oid => $on): ?><a class="btn btn--ghost btn--sm" href="<?= url('collections', $keep + ['sparty' => (int)$oid, 'v' => $selV, 'asof' => $asOf] + ($soaFrom ? ['sfrom' => $soaFrom] : [])) ?>#col-soa"><?= e($on) ?></a> <?php endforeach; ?></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($SD && function_exists('em_card_html')):
    $ctx = ['no' => 'SOA ' . fmt_date($asOf), 'title' => implode(', ', $selNames), 'vessel' => implode(', ', $selNames), 'customer' => '', 'project' => '', 'doc' => t('em.w_statement', ['v' => implode(', ', $selNames), 'd' => fmt_date($asOf)]), 'project_id' => 0, 'customer_id' => $cid, 'date' => fmt_date($asOf)];
    $cRow = $cid ? one('SELECT name, email FROM customers WHERE id = ?', [$cid]) : null;
    $extra = $cRow && $cRow['email'] ? [[(string)$cRow['name'], $cRow['email'], t('soa.accounting_dept')]] : [];
    if (!empty($SD['party']['email']) && empty($SD['multi_party'])) array_unshift($extra, [(string)$SD['party']['name'], (string)$SD['party']['email'], t('pty.party')]);   // R95-005: the party's own address first
    $people = em_people(count($selV) === 1 ? $selV[0] : null, $cid ?: null, $extra);
    echo em_card_html(['kind' => 'statement', 'ctx' => $ctx, 'people' => $people, 'atts' => [[sprintf($stKey, 'pdf'), t('soa.att_pdf', ['v' => implode(', ', $selNames)])], [sprintf($stKey, 'xlsx'), t('soa.att_xlsx')]], 'back' => url('collections', $keep)]);
  endif; ?>
</div>

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
        <td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>"><b><?= e($r['pi_no']) ?></b></a><?= ($r['kind'] ?? '') === 'invoice' ? ' ' . pill('navy', t('pi.k_invoice')) : '' ?><?= !empty($r['invoice_nos']) ? '<br><span class="hint">🧾 ' . e($r['invoice_nos']) . '</span>' : '' ?><?= !empty($r['settles_nos']) ? '<br><span class="hint">' . e(t('pi.settles_short', ['n' => $r['settles_nos']])) . '</span>' : '' ?></td>
        <td><?= e($r['title']) ?><?= $r['term_n'] ? ' <span class="hint">' . e(t('pt.instalment', ['n' => (int)$r['term_n']])) . '</span>' : '' ?><br><span class="hint"><?= e($r['offer_no']) ?><?= $r['sub_code'] ? ' · ' . e($r['sub_code']) : '' ?><?= !empty($r['party_name']) && mb_strtolower((string)$r['party_name']) !== mb_strtolower((string)$r['customer_name']) ? ' · 🏛 ' . e($r['party_name']) : '' ?></span></td>
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
<?php if ($chk): $canDI = function_exists('pi_kind_has') && pi_kind_has() && pi_can_invoices(); $covOpen = count(array_filter($cov, fn($r) => ($r['invoicing'] ?? 'none') !== 'full' || (float)($r['inv_contract'] ?? 0) <= 0.005)); ?>
<div class="card" id="col-checks">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">🔎 <?= tt('chk.title') ?></h2>
    <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('collections', $keep + ['cs' => $cf['status'], 'ci' => $cf['inv'], 'cd' => $cdays, 'format' => 'checks']) ?>">⤓ Excel</a></div></div>
  <p class="hint" style="margin:0 0 10px;"><?= tt('chk.lede') ?></p>
  <div class="kpi kpi--3" style="margin-bottom:10px;">
    <a class="stat<?= $covOpen ? ' gold' : ' green' ?>" href="#chk-cov"><b><?= $covOpen ?></b><?= tt('chk.k_cov') ?><span class="s"><?= tt('chk.k_cov_s') ?></span></a>
    <a class="stat<?= $mis ? ' red' : ' green' ?>" href="#chk-mis"><b><?= count($mis) ?></b><?= tt('chk.k_mis') ?><span class="s"><?= tt('chk.k_mis_s') ?></span></a>
    <a class="stat<?= $unv ? ' gold' : ' green' ?>" href="#chk-unv"><b><?= count($unv) ?></b><?= tt('chk.k_unv') ?><span class="s"><?= e(t('chk.k_unv_s', ['n' => $cdays])) ?></span></a>
  </div>

  <h3 id="chk-cov" style="margin:12px 0 6px;"><?= tt('chk.cov_title') ?> <span class="count"><?= count($cov) ?></span></h3>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1fr auto; margin-bottom:8px;"><input type="hidden" name="p" value="collections"><?php foreach (array_filter($f) as $k => $v): if ($k === 'project') continue; ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <div class="field"><label><?= tt('pr.one') ?></label><select name="project"><option value=""><?= tt('flt.any') ?></option><?php foreach (all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','completed') ORDER BY project_code DESC") as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= $sel($pp['id'], $f['project']) ?>><?= e(trim(($pp['project_code'] ?: '') . ' ' . $pp['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('sp.one') ?> · <?= tt('th.status') ?></label><select name="cs" data-nosearch><option value="all"<?= $sel('all', $cf['status']) ?>><?= tt('flt.all') ?></option><option value="active"<?= $sel('active', $cf['status']) ?>><?= tt('pr.active') ?></option><option value="completed"<?= $sel('completed', $cf['status']) ?>><?= tt('pr.completed') ?></option></select></div>
    <div class="field"><label><?= tt('inv.title') ?></label><select name="ci" data-nosearch><option value="open"<?= $sel('open', $cf['inv']) ?>><?= tt('chk.ci_open') ?></option><option value="none"<?= $sel('none', $cf['inv']) ?>><?= tt('inv.none') ?></option><option value="partial"<?= $sel('partial', $cf['inv']) ?>><?= tt('inv.partial') ?></option><option value="full"<?= $sel('full', $cf['inv']) ?>><?= tt('inv.full') ?></option><option value="all"<?= $sel('all', $cf['inv']) ?>><?= tt('flt.all') ?></option></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
  <?php if (!$cov): ?><p class="hint"><?= tt('chk.cov_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('pr.one') ?></th><th><?= tt('sp.one') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('chk.contract') ?></th><th class="num"><?= tt('fs.invoiced') ?></th><th class="num">%</th><th class="num"><?= tt('chk.collected') ?></th><th><?= tt('inv.title') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($cov as $r): ?><tr class="<?= $r['status'] === 'completed' && ($r['invoicing'] ?? 'none') !== 'full' ? 'is-bad' : '' ?>">
      <td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?></td>
      <td><a href="<?= url('subproject', ['id' => (int)$r['id']]) ?>"><b><?= e($r['code']) ?></b> <?= e($r['name']) ?></a></td>
      <td><?= e($r['vessel'] ?: '—') ?></td>
      <td><?= $r['status'] === 'completed' ? pill('mute', t('pr.completed')) . ($r['completed_at'] ? ' <span class="hint">' . e(fmt_date(substr((string)$r['completed_at'], 0, 10))) . '</span>' : '') : pill('green', t('pr.active')) ?></td>
      <td class="num"><?= (float)$r['inv_contract'] > 0.005 ? e($m((float)$r['inv_contract'])) : '—' ?></td>
      <td class="num"><?= e($m((float)$r['inv_invoiced'])) ?></td>
      <td class="num"><?= (int)($r['invoiced_pct'] ?? 0) ?> %</td>
      <td class="num"><?= e($m((float)$r['collected'])) ?></td>
      <td><?= inv_pill($r) ?><?= $r['invoicing_override'] ? ' <span class="hint" title="' . tt('chk.override') . '">✎</span>' : '' ?></td>
      <td class="row-actions"><?php if ($canDI && ($r['invoicing'] ?? 'none') !== 'full'): $left = max(0.0, (float)($r['inv_contract'] ?? 0) - (float)($r['inv_invoiced'] ?? 0)); ?><a class="btn btn--gold btn--sm" href="<?= url('invoice_new', ['project' => (int)$r['project_id'], 'sub' => (int)$r['id']] + ($left > 0.005 ? ['gross' => number_format($left, 2, '.', '')] : [])) ?>" title="<?= tt('chk.invoice_hint') ?>">🧾 <?= tt('chk.invoice') ?></a><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint" style="margin:6px 0 0;"><?= tt('chk.cov_hint') ?></p>
  <?php endif; ?>

  <h3 id="chk-mis" style="margin:16px 0 6px;"><?= tt('chk.mis_title') ?> <span class="count"><?= count($mis) ?></span></h3>
  <?php if (!$mis): ?><p class="hint"><?= tt('chk.mis_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('pi.description') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('pi.issue_date') ?></th><th class="num"><?= tt('pi.gross') ?></th><th class="num"><?= tt('chk.inv_total') ?></th><th class="num"><?= tt('chk.diff') ?></th><th><?= tt('pi.invoice_no') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($mis as $r): $it = 0.0; $nos = []; foreach ($r['invoices'] ?? [] as $x) { $it += (float)$x['amount']; $nos[] = $x['invoice_no']; } $dif = $it - (float)$r['gross']; ?><tr>
      <td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>#invoices"><b><?= e($r['pi_no']) ?></b></a></td><td><?= e($r['title']) ?></td>
      <td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' <span class="hint">' . e($r['sub_code']) . '</span>' : '' ?></td>
      <td class="date"><?= $r['issue_date'] ? e(fmt_date($r['issue_date'])) : '—' ?></td>
      <td class="num"><?= e($m((float)$r['gross'])) ?></td><td class="num"><?= e($m($it)) ?></td>
      <td class="num" style="color:var(--bad-text,#b42318);font-weight:700;"><?= ($dif > 0 ? '+' : '') . e($m($dif)) ?></td>
      <td class="mono"><?= e(implode(', ', $nos)) ?></td><td><?= pi_status_pill($r['status']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>

  <h3 id="chk-unv" style="margin:16px 0 6px;"><?= e(t('chk.unv_title', ['n' => $cdays])) ?> <span class="count"><?= count($unv) ?></span></h3>
  <form method="get" action="index.php" class="btn-row" style="align-items:flex-end;gap:8px;margin-bottom:8px;"><input type="hidden" name="p" value="collections"><?php foreach (array_filter($f) as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?><input type="hidden" name="cs" value="<?= e($cf['status']) ?>"><input type="hidden" name="ci" value="<?= e($cf['inv']) ?>">
    <div class="field" style="margin:0;"><label><?= tt('chk.older_than') ?></label><input type="number" name="cd" min="1" max="365" value="<?= $cdays ?>" style="width:90px;"></div><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></form>
  <?php if (!$unv): ?><p class="hint"><?= tt('chk.unv_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('pi.no') ?></th><th><?= tt('pi.description') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('pi.issue_date') ?></th><th class="num"><?= tt('chk.days') ?></th><th class="num"><?= tt('pi.gross') ?></th><th class="num"><?= tt('pi.paid_lbl') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($unv as $r): ?><tr>
      <td class="mono"><a href="<?= url('proforma', ['id' => (int)$r['id']]) ?>#invoices"><b><?= e($r['pi_no']) ?></b></a></td><td><?= e($r['title']) ?></td><td><?= e($r['vessel'] ?: '—') ?></td>
      <td><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['sub_code'] ? ' <span class="hint">' . e($r['sub_code']) . '</span>' : '' ?></td>
      <td class="date"><?= e(fmt_date($r['issue_date'])) ?></td><td class="num"><?= (int) floor((time() - strtotime($r['issue_date'])) / 86400) ?></td>
      <td class="num"><?= e($m((float)$r['gross'])) ?></td><td class="num"><?= e($m((float)$r['paid_sum'])) ?></td><td><?= pi_status_pill($r['status']) ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>
<script nonce="<?= csp_nonce() ?>">document.querySelectorAll('[data-print]').forEach(function (b) { b.addEventListener('click', function () { window.print(); }); });</script>

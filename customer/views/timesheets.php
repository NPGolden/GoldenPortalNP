<?php
/**
 * Timesheets — my sheets, the ones waiting for my approval, and (for
 * administrators / project managers) everything, with the invoicing
 * tool: tick approved sheets, give an invoice reference, mark them.   GAS-CP-R11-001
 */
require __DIR__ . '/_layout.php';
$u = require_staff();
$admin = is_cp_admin();
$view = (string)($_GET['view'] ?? 'mine');
if (!in_array($view, ['mine', 'approve', 'all', 'invoicing'], true)) $view = 'mine';
if ($view === 'invoicing' && !fin_visible()) $view = 'mine';
$presets = ts_period_presets();
$period  = isset($presets[$_GET['period'] ?? '']) ? (string)$_GET['period'] : '';
$f = ['project' => (int)($_GET['project'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'customer' => (int)($_GET['customer'] ?? 0), 'user' => (int)($_GET['user'] ?? 0),
      'discipline' => (int)($_GET['discipline'] ?? 0), 'approver' => (int)($_GET['approver'] ?? 0), 'sub' => (int)($_GET['sub'] ?? 0),
      'status' => (string)($_GET['status'] ?? ''), 'invoice' => trim((string)($_GET['invoice'] ?? '')),
      'htype' => in_array($_GET['htype'] ?? '', ['overtime', 'holiday', 'main'], true) ? $_GET['htype'] : '', 'minh' => is_numeric($_GET['minh'] ?? '') ? (string)$_GET['minh'] : '',
      'group' => in_array($_GET['group'] ?? '', ['project', 'vessel'], true) ? $_GET['group'] : '',
      'from' => $period ? $presets[$period][0] : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : ''),
      'to'   => $period ? $presets[$period][1] : (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : ''),
      'q' => trim((string)($_GET['q'] ?? ''))];
if ($view === 'mine') $f['mine'] = 1;
if ($view === 'approve') $f['toapprove'] = 1;
if ($view === 'invoicing' && $f['status'] === '') $f['status'] = 'approved';
$rows = ts_has() ? ts_list($f) : [];
$nApprove = count(ts_to_approve());
$projects = all("SELECT id, project_code, name FROM projects WHERE status <> 'completed' OR id = ? ORDER BY project_code", [$f['project']]);
$vessels  = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 ORDER BY v.name");
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$people   = staff_users();
$discs    = disciplines(false);
$subs     = $f['project'] ? all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$f['project']]) : [];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$keep = array_filter(['project' => $f['project'], 'vessel' => $f['vessel'], 'customer' => $f['customer'], 'user' => $f['user'], 'discipline' => $f['discipline'], 'approver' => $f['approver'], 'sub' => $f['sub'],
                      'status' => $f['status'], 'invoice' => $f['invoice'], 'htype' => $f['htype'], 'minh' => $f['minh'], 'period' => $period,
                      'from' => $period ? '' : $f['from'], 'to' => $period ? '' : $f['to'], 'q' => $f['q'], 'group' => $f['group']]);
/* active-filter chips: label => url without that filter */
$chips = [];
$nameOf = function (array $rows, $id, string $k = 'name') { foreach ($rows as $r) if ((int)$r['id'] === (int)$id) return $r[$k]; return '#' . $id; };
if ($f['q'])          $chips['q']        = tt('pr.search') . ': ' . e($f['q']);
if ($f['project'])    $chips['project']  = e($nameOf($projects, $f['project'], 'project_code') ?: $nameOf($projects, $f['project']));
if ($f['sub'])        $chips['sub']      = e(trim($nameOf($subs, $f['sub'], 'code') . ' ' . $nameOf($subs, $f['sub'])));
if ($f['vessel'])     $chips['vessel']   = '⚓ ' . e($nameOf($vessels, $f['vessel']));
if ($f['customer'])   $chips['customer'] = e($nameOf($customers, $f['customer']));
if ($f['user'])       $chips['user']     = tt('ts.engineer') . ': ' . e($nameOf($people, $f['user'], 'full_name'));
if ($f['discipline']) $chips['discipline'] = e($nameOf($discs, $f['discipline']));
if ($f['approver'])   $chips['approver'] = tt('ts.approver') . ': ' . e($nameOf($people, $f['approver'], 'full_name'));
if ($f['status'] && !($view === 'invoicing' && empty($_GET['status']))) $chips['status'] = e(t('ts.st_' . $f['status']));
if ($f['invoice'])    $chips['invoice']  = tt('ts.invoice') . ': ' . e($f['invoice']);
if ($f['htype'])      $chips['htype']    = e(t('ts.ht_' . $f['htype']));
if ($f['minh'] !== '') $chips['minh']    = '≥ ' . e($f['minh']) . ' h';
if ($period)          $chips['period']   = e(t('ts.p_' . $period)) . ' (' . e(fmt_date($f['from'])) . ' – ' . e(fmt_date($f['to'])) . ')';
elseif ($f['from'] || $f['to']) { $chips['from'] = e(($f['from'] ? fmt_date($f['from']) : '…') . ' – ' . ($f['to'] ? fmt_date($f['to']) : '…')); }
$chipUrl = function (string $drop) use ($keep, $view) { $k = $keep; unset($k[$drop]); if ($drop === 'from') unset($k['to']); if ($drop === 'project') unset($k['sub']); return url('timesheets', ['view' => $view] + $k); };
$groupKey = fn(array $r) => $f['group'] === 'vessel' ? ($r['vessel'] ?: t('pr.novessel')) : ($f['group'] === 'project' ? (($r['project_code'] ?: '') . ' ' . $r['project_name']) : null);
$groupTot = [];
foreach ($rows as $r) { $k = $groupKey($r); if ($k === null) continue; $groupTot[$k] = $groupTot[$k] ?? ['normal' => 0, 'overtime' => 0, 'holiday' => 0, 'n' => 0]; $groupTot[$k]['normal'] += (float)$r['h_normal']; $groupTot[$k]['overtime'] += (float)$r['h_overtime']; $groupTot[$k]['holiday'] += (float)$r['h_holiday']; $groupTot[$k]['n']++; }
$tot = ['normal' => 0, 'overtime' => 0, 'holiday' => 0];
foreach ($rows as $r) { $tot['normal'] += (float)$r['h_normal']; $tot['overtime'] += (float)$r['h_overtime']; $tot['holiday'] += (float)$r['h_holiday']; }
layout_head(t('ts.title'), 'timesheets');
?>
<div class="page-head">
  <div><h1><?= tt('ts.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('ts.lede') ?></p></div>
  <div class="actions">
    <?php if ($f['project'] || $f['vessel']): $rp = array_filter(['project' => $f['project'], 'vessel' => $f['project'] ? 0 : $f['vessel'], 'from' => $f['from'], 'to' => $f['to']]); ?>
      <a class="btn btn--ghost" href="<?= url('report_ts', $rp + ['format' => 'pdf']) ?>" target="_blank"><?= tt('rp.ts_pdf') ?></a>
      <a class="btn btn--ghost" href="<?= url('report_ts', $rp + ['format' => 'docx']) ?>"><?= tt('rp.ts_word') ?></a>
    <?php endif; ?>
    <a class="btn btn--ghost" href="<?= url('manhours', array_filter(['project' => $f['project'], 'vessel' => $f['vessel']])) ?>"><?= tt('mh.title') ?></a>
    <a class="btn btn--gold" href="<?= url('timesheet_edit') ?>">+ <?= tt('ts.new') ?></a>
  </div>
</div>
<?php if (!ts_has()): ?><div class="flash flash--warn"><?= tt('ts.need_r11') ?></div><?php endif; ?>

<nav class="tabs">
  <a href="<?= url('timesheets', ['view' => 'mine']) ?>" class="<?= $view === 'mine' ? 'is-on' : '' ?>"><?= tt('ts.mine') ?></a>
  <a href="<?= url('timesheets', ['view' => 'approve']) ?>" class="<?= $view === 'approve' ? 'is-on' : '' ?>"><?= tt('ts.to_approve') ?><?= $nApprove ? '<span class="n">' . $nApprove . '</span>' : '' ?></a>
  <a href="<?= url('timesheets', ['view' => 'all']) ?>" class="<?= $view === 'all' ? 'is-on' : '' ?>"><?= tt('ts.all') ?></a>
  <?php if (fin_visible()): ?><a href="<?= url('timesheets', ['view' => 'invoicing']) ?>" class="<?= $view === 'invoicing' ? 'is-on' : '' ?>"><?= tt('ts.invoicing') ?></a><?php endif; ?>
</nav>

<div class="card card--tight tsflt">
  <form method="get" action="index.php" id="tsfilters">
    <input type="hidden" name="p" value="timesheets"><input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr;">
      <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('ts.search_ph') ?>"></div>
      <div class="field"><label for="project"><?= tt('pr.one') ?></label>
        <select id="project" name="project" class="js-submit"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"<?= $sel($pr['id'], $f['project']) ?>><?= e(($pr['project_code'] ?: '') . ' ' . $pr['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="sub"><?= tt('sp.one') ?></label>
        <select id="sub" name="sub"<?= $subs ? '' : ' disabled' ?>><option value="0"><?= $subs ? tt('flt.any') : tt('ts.pick_project') ?></option><?php foreach ($subs as $sb): ?><option value="<?= $sb['id'] ?>"<?= $sel($sb['id'], $f['sub']) ?>><?= e(trim($sb['code'] . ' ' . $sb['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="vessel"><?= tt('ts.vessel') ?></label>
        <select id="vessel" name="vessel"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($vessels as $vs): ?><option value="<?= $vs['id'] ?>"<?= $sel($vs['id'], $f['vessel']) ?>><?= e($vs['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="customer"><?= tt('rp.customer') ?></label>
        <select id="customer" name="customer"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $f['customer']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="fltbar" style="grid-template-columns: 1fr 1fr 1fr 1fr 1fr; margin-top:12px;">
      <div class="field"><label for="user"><?= tt('ts.engineer') ?></label>
        <select id="user" name="user"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= $sel($pp['id'], $f['user']) ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="discipline"><?= tt('disc.one') ?></label>
        <select id="discipline" name="discipline"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($discs as $d): ?><option value="<?= $d['id'] ?>"<?= $sel($d['id'], $f['discipline']) ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="approver"><?= tt('ts.approver') ?></label>
        <select id="approver" name="approver"><option value="0"><?= tt('flt.any') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= $sel($pp['id'], $f['approver']) ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="status"><?= tt('th.status') ?></label>
        <select id="status" name="status"><option value=""><?= tt('flt.any') ?></option><?php foreach (ts_statuses() as $st): ?><option value="<?= $st ?>"<?= $sel($st, $f['status']) ?>><?= e(t('ts.st_' . $st)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="invoice"><?= tt('ts.invoice_ref') ?></label><input id="invoice" name="invoice" value="<?= e($f['invoice']) ?>" placeholder="INV-…"></div>
    </div>
    <div class="fltbar" style="grid-template-columns: 2.6fr 1fr 0.8fr 1.2fr auto auto; margin-top:12px;">
      <div class="field"><label for="period"><?= tt('ts.period') ?></label>
        <div class="period-row">
          <select id="period" name="period" class="js-period"><option value=""><?= tt('ts.p_custom') ?></option><?php foreach (array_keys($presets) as $pk): ?><option value="<?= $pk ?>"<?= $sel($pk, $period) ?>><?= e(t('ts.p_' . $pk)) ?></option><?php endforeach; ?></select>
          <input id="from" name="from" type="date" value="<?= e($f['from']) ?>" title="<?= tt('ts.from') ?>"<?= $period ? ' readonly' : '' ?>>
          <span class="hint">–</span>
          <input id="to" name="to" type="date" value="<?= e($f['to']) ?>" title="<?= tt('ts.to') ?>"<?= $period ? ' readonly' : '' ?>>
        </div></div>
      <div class="field"><label for="htype"><?= tt('ts.ht_label') ?></label>
        <select id="htype" name="htype"><option value=""><?= tt('flt.any') ?></option><?php foreach (['overtime', 'holiday', 'main'] as $ht): ?><option value="<?= $ht ?>"<?= $sel($ht, $f['htype']) ?>><?= e(t('ts.ht_' . $ht)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="minh"><?= tt('ts.min_hours') ?></label><input id="minh" name="minh" type="number" min="0" step="0.25" value="<?= e($f['minh']) ?>" placeholder="0"></div>
      <div class="field"><label for="group"><?= tt('ts.group') ?></label>
        <select id="group" name="group"><option value=""><?= tt('ts.group_none') ?></option><option value="project"<?= $sel('project', $f['group']) ?>><?= tt('ts.group_project') ?></option><option value="vessel"<?= $sel('vessel', $f['group']) ?>><?= tt('ts.group_vessel') ?></option></select></div>
      <div class="field"><label>&nbsp;</label><span class="hint"><?= e(t('ts.sheets_n', ['n' => count($rows)])) ?></span></div>
      <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('timesheets', ['view' => $view, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
    </div>
    <?php if ($chips): ?>
      <div class="chips">
        <?php foreach ($chips as $k => $label): ?><a class="chip" href="<?= $chipUrl($k) ?>" title="<?= tt('ts.remove_filter') ?>"><?= $label ?> <span>✕</span></a><?php endforeach; ?>
        <a class="chip chip--clear" href="<?= url('timesheets', ['view' => $view, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a>
      </div>
    <?php endif; ?>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var form = document.getElementById('tsfilters'), per = form.querySelector('.js-period'), from = form.querySelector('#from'), to = form.querySelector('#to');
  var presets = <?= json_encode($presets) ?>;
  per.addEventListener('change', function () { var p = presets[per.value]; if (p) { from.value = p[0]; to.value = p[1]; from.readOnly = to.readOnly = true; } else { from.readOnly = to.readOnly = false; } });
  [from, to].forEach(function (i) { i.addEventListener('input', function () { if (per.value) { per.value = ''; from.readOnly = to.readOnly = false; } }); });
  form.querySelectorAll('.js-submit').forEach(function (s) { s.addEventListener('change', function () { form.querySelector('#sub').value = '0'; form.submit(); }); });
})();
</script>

<?php if ($view === 'invoicing'): ?>
<form method="post" action="<?= url('timesheets', ['view' => 'invoicing'] + $keep) ?>" data-lock id="invform"
      data-confirm="<?= tt('ts.invoice_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ts.mark_invoiced') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="ts_invoice">
  <div class="card card--tight inv-bar">
    <div class="field" style="margin:0;"><label for="ref"><?= tt('ts.invoice_ref') ?></label><input id="ref" name="invoice_ref" maxlength="60" placeholder="<?= tt('ts.invoice_ref_ph') ?>" required></div>
    <div class="hint" id="inv-count"><?= e(t('ts.selected_n', ['n' => 0])) ?></div>
    <button class="btn btn--gold" type="submit"><?= tt('ts.mark_invoiced') ?></button>
  </div>
<?php endif; ?>

<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= tt('ts.none') ?></div>
  <?php else: ?>
  <div class="table-scroll"><table class="ts-table">
    <thead><tr>
      <?php if ($view === 'invoicing'): ?><th><input type="checkbox" id="chk-all" title="<?= tt('ts.select_all') ?>"></th><?php endif; ?>
      <th><?= tt('ts.no') ?></th><th><?= tt('ts.engineer') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('ts.period') ?></th>
      <th class="num"><?= tt('ts.normal') ?></th><th class="num"><?= tt('ts.overtime') ?></th><th class="num"><?= tt('ts.holiday') ?></th><th class="num"><?= tt('ts.total') ?></th>
      <th><?= tt('th.status') ?></th><th><?= tt('ts.approver') ?></th><th><?= tt('ts.invoice') ?></th><th></th></tr></thead>
    <tbody><?php $group = null; $colspan = $view === 'invoicing' ? 13 : 12; [$pageRows, $pager] = page_list($rows, 'timesheets', $keep + ['view' => $view]); foreach ($pageRows as $r): $total = (float)$r['h_normal'] + (float)$r['h_overtime'] + (float)$r['h_holiday'];
      $gk = $groupKey($r);
      if ($gk !== null && $gk !== $group): $group = $gk; $gt = $groupTot[$gk]; ?>
        <tr class="ts-group"><td colspan="<?= $colspan - 8 ?>"><?= $f['group'] === 'vessel' ? '⚓ ' : '' ?><b><?= e($gk) ?></b> · <?= e(t('ts.sheets_n', ['n' => $gt['n']])) ?></td>
          <td class="num"><?= ts_fmt($gt['normal']) ?></td><td class="num ts-ot"><?= ts_fmt($gt['overtime']) ?></td><td class="num ts-hol"><?= ts_fmt($gt['holiday']) ?></td><td class="num"><b><?= ts_fmt($gt['normal'] + $gt['overtime'] + $gt['holiday']) ?></b></td><td colspan="4"></td></tr>
      <?php elseif ($gk === null && $f['status'] === 'invoiced' && $r['invoice_ref'] !== $group): $group = $r['invoice_ref']; ?>
        <tr class="ts-group"><td colspan="<?= $colspan ?>"><?= tt('ts.invoice') ?> <b><?= e($group ?: '—') ?></b></td></tr>
      <?php endif; ?>
      <tr>
        <?php if ($view === 'invoicing'): ?><td><?= $r['status'] === 'approved' && ts_can_invoice($r) ? '<input type="checkbox" class="js-inv" name="ids[]" value="' . (int)$r['id'] . '">' : '' ?></td><?php endif; ?>
        <td class="num"><a href="<?= url('timesheet', ['id' => $r['id']]) ?>"><?= e($r['ts_no']) ?></a></td>
        <td><?= e($r['user_name']) ?><?= $r['discipline_name'] ? '<br><span class="hint">' . e($r['discipline_name']) . '</span>' : '' ?></td>
        <td><?= e($r['project_code'] ?: $r['project_name']) ?><?= $r['vessel'] ? '<br><span class="hint">' . e($r['vessel']) . '</span>' : '' ?></td>
        <td class="date"><?= e(fmt_date($r['period_start'])) ?> – <?= e(fmt_date($r['period_end'])) ?></td>
        <td class="num"><?= ts_fmt((float)$r['h_normal']) ?></td><td class="num ts-ot"><?= ts_fmt((float)$r['h_overtime']) ?></td><td class="num ts-hol"><?= ts_fmt((float)$r['h_holiday']) ?></td>
        <td class="num"><b><?= ts_fmt($total) ?></b></td>
        <td><?= ts_status_pill($r['status']) ?></td>
        <td><?= e($r['status'] === 'submitted' ? ($r['approver_name'] ?: '—') : ($r['approved_name'] ?: ($r['approver_name'] ?: '—'))) ?><?= $r['approved_at'] ? '<br><span class="hint">' . e(fmt_datetime($r['approved_at'])) . '</span>' : '' ?></td>
        <td><?= $r['invoice_ref'] ? e($r['invoice_ref']) . '<br><span class="hint">' . e(fmt_date($r['invoiced_at'])) . '</span>' : '—' ?></td>
        <td class="row-actions"><a class="btn <?= $r['status'] === 'submitted' && ts_can_approve($r) ? 'btn--gold' : 'btn--ghost' ?> btn--sm" href="<?= url('timesheet', ['id' => $r['id']]) ?>"><?= $r['status'] === 'submitted' && ts_can_approve($r) ? tt('ts.review') : tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
    <tfoot><tr><?php if ($view === 'invoicing'): ?><td></td><?php endif; ?><td colspan="4"><?= e(t('ts.sheets_n', ['n' => count($rows)])) ?></td>
      <td class="num"><b><?= ts_fmt($tot['normal']) ?></b></td><td class="num ts-ot"><b><?= ts_fmt($tot['overtime']) ?></b></td><td class="num ts-hol"><b><?= ts_fmt($tot['holiday']) ?></b></td><td class="num"><b><?= ts_fmt(array_sum($tot)) ?></b></td><td colspan="4"></td></tr></tfoot>
  </table></div>
  <?= $pager ?? '' ?>
  <?php endif; ?>
</div>
<?php if ($view === 'invoicing'): ?></form>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var boxes = document.querySelectorAll('.js-inv'), all = document.getElementById('chk-all'), out = document.getElementById('inv-count'), tpl = <?= json_encode(t('ts.selected_n', ['n' => '#'])) ?>;
  function count() { var n = 0; boxes.forEach(function (b) { if (b.checked) n++; }); out.textContent = tpl.replace('#', n); }
  boxes.forEach(function (b) { b.addEventListener('change', count); });
  if (all) all.addEventListener('change', function () { boxes.forEach(function (b) { b.checked = all.checked; }); count(); });
  document.getElementById('invform').addEventListener('submit', function (e) { var n = 0; boxes.forEach(function (b) { if (b.checked) n++; }); if (!n) { e.preventDefault(); e.stopImmediatePropagation(); out.classList.add('is-late'); out.textContent = <?= json_encode(t('ts.select_first')) ?>; } }, true);
})();
</script>
<?php endif; ?>
<?php layout_foot(); ?>

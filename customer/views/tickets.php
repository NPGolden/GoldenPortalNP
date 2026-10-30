<?php
/**
 * Tickets — the owner's requests and Golden A/S's own, per vessel.   GAS-CP-R14-001
 *   ?p=tickets[&view=open|mine|waiting|closed|all][&vessel=&project=&status=&priority=&category=&assigned=&side=&q=]
 */
require __DIR__ . '/_layout.php';
$u = require_login();
$staff = is_staff();
if (!tk_has()) { layout_head(t('tk.title'), 'tickets'); echo '<h1>' . tt('tk.title') . '</h1><div class="flash flash--warn">' . tt('tk.need_r14') . '</div>'; layout_foot(); return; }
$view = (string)($_GET['view'] ?? 'open');
if (!in_array($view, ['open', 'mine', 'waiting', 'closed', 'all', 'reports'], true)) $view = 'open';
$f = ['vessel' => (int)($_GET['vessel'] ?? 0), 'project' => (int)($_GET['project'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'priority' => (string)($_GET['priority'] ?? ''),
      'category' => (string)($_GET['category'] ?? ''), 'assigned' => (int)($_GET['assigned'] ?? 0), 'side' => (string)($_GET['side'] ?? ''), 'customer' => $staff ? (int)($_GET['customer'] ?? 0) : 0, 'q' => trim((string)($_GET['q'] ?? '')), 'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? (string)$_GET['from'] : ''];
if ($view === 'open' && $f['status'] === '') $f['status'] = 'open_all';
if ($view === 'waiting') $f['status'] = 'waiting_customer';
if ($view === 'closed' && $f['status'] === '') $f['status'] = 'closed';
if ($view === 'mine') { if ($staff) $f['assigned'] = (int)$u['id']; else $f['mine'] = 1; }
if (($_GET['assigned'] ?? '') === 'none') { $f['unassigned'] = 1; $f['assigned'] = 0; }   // R29-014: "Unassigned" is an explicit choice (assigned=none); a blank means any assignee
$rows = tk_list($f);
if (!empty($f['unassigned'])) $rows = array_values(array_filter($rows, fn($t) => !$t['assigned_to']));
if (!empty($f['mine'])) $rows = array_values(array_filter($rows, fn($t) => (int)$t['created_by'] === (int)$u['id']));
$keep = array_filter(['view' => $view] + $f);
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
[$vscope, $vargs] = tk_scope();
$vessels  = all("SELECT v.id, v.name FROM vessels v WHERE {$vscope} AND v.is_active = 1 ORDER BY v.name", $vargs);
$projects = $f['vessel'] ? all('SELECT id, project_code, name FROM projects WHERE vessel_id = ? ORDER BY project_code', [$f['vessel']]) : [];
$assignees = $staff ? all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name") : [];
$counts = ['open' => 0, 'waiting' => 0];
foreach (tk_list(['status' => 'open_all']) as $t) { $counts['open']++; if ($t['status'] === 'waiting_customer') $counts['waiting']++; }
$tabs = [['open', t('tk.tab_open'), $counts['open']], ['mine', t($staff ? 'tk.tab_mine_staff' : 'tk.tab_mine'), null], ['waiting', t('tk.tab_waiting'), $counts['waiting']], ['closed', t('tk.tab_closed'), null], ['all', t('flt.all'), null], ['reports', '📊 ' . t('tk.tab_reports'), null]];
layout_head(t('tk.title'), 'tickets');
?>
<div class="page-head">
  <div><h1><?= tt('tk.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= $staff ? tt('tk.lede_staff') : tt('tk.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="<?= url('ticket_edit', $f['vessel'] ? ['vessel' => $f['vessel']] : []) ?>">+ <?= tt('tk.new') ?></a></div>
</div>


<nav class="tabs" aria-label="<?= tt('tk.title') ?>">
  <?php foreach ($tabs as [$slug, $label, $n]): ?>
    <a class="<?= $view === $slug ? 'is-on' : '' ?>" href="<?= url('tickets', ['view' => $slug]) ?>"><?= e($label) ?><?= $n ? ' <span class="n">' . $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<?php if ($view === 'reports'): ?>
<?php
$af = ['aq' => trim((string)($_GET['aq'] ?? '')), 'aperiod' => (string)($_GET['aperiod'] ?? ''), 'afrom' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['afrom'] ?? '')) ? $_GET['afrom'] : '', 'ato' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ato'] ?? '')) ? $_GET['ato'] : '', 'aopen' => !empty($_GET['aopen']) ? 1 : 0, 'asort' => (string)($_GET['asort'] ?? '')];
$win = match ($af['aperiod']) { '30' => [date('Y-m-d', strtotime('-30 days')), ''], '90' => [date('Y-m-d', strtotime('-90 days')), ''], '365' => [date('Y-m-d', strtotime('-365 days')), ''], 'ytd' => [date('Y') . '-01-01', ''], 'ly' => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'], 'custom' => [$af['afrom'], $af['ato']], default => ['', ''] };
$S = tk_summary(['from' => $win[0], 'to' => $win[1]]); $pct = fn($n) => $S['open'] ? round($n / $S['open'] * 100) : 0;
$actFilter = function (array $rows) use ($af): array {                       // name search · only with open tickets · sort
    if ($af['aq'] !== '') $rows = array_filter($rows, fn($x) => mb_stripos((string)$x['name'], $af['aq']) !== false);
    if ($af['aopen']) $rows = array_filter($rows, fn($x) => (int)$x['open'] > 0);
    $k = $af['asort'];
    if (in_array($k, ['name', 'open', 'waiting', 'resolved', 'raised', 'raised90', 'res_avg', 'last', 'comments', 'vessels'], true))
        uasort($rows, fn($a, $b) => $k === 'name' ? strcasecmp((string)$a['name'], (string)$b['name']) : (($b[$k] ?? -1) <=> ($a[$k] ?? -1)));
    return $rows;
};
$keep = $keep + array_filter($af);
?>
<p class="hint" style="margin:0 0 4px;"><?= tt('tk.af_lede') ?></p>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto auto; margin-bottom:12px;">
  <input type="hidden" name="p" value="tickets"><input type="hidden" name="view" value="<?= e($view) ?>">
  <div class="field"><label for="aq"><?= tt('tk.af_name') ?></label><input id="aq" name="aq" type="text" value="<?= e($af['aq']) ?>" placeholder="<?= tt('tk.af_name_ph') ?>"></div>
  <div class="field"><label for="aperiod"><?= tt('tk.af_period') ?></label><select id="aperiod" name="aperiod" data-js-toggle="af-custom" data-js-toggle-value="custom"><?php foreach (['' => t('tk.af_all_time'), '30' => t('tk.af_d', ['n' => 30]), '90' => t('tk.af_d', ['n' => 90]), '365' => t('tk.af_d', ['n' => 365]), 'ytd' => t('tk.af_ytd'), 'ly' => t('tk.af_ly'), 'custom' => t('tk.af_custom')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $af['aperiod']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field" id="af-custom"<?= $af['aperiod'] === 'custom' ? '' : ' hidden' ?>><label><?= tt('tk.af_range') ?></label><div style="display:flex;gap:4px;"><input name="afrom" type="date" value="<?= e($af['afrom']) ?>"><input name="ato" type="date" value="<?= e($af['ato']) ?>"></div></div>
  <div class="field"><label for="asort"><?= tt('tk.af_sort') ?></label><select id="asort" name="asort"><?php foreach (['' => t('tk.af_sort_default'), 'name' => t('a.name'), 'open' => t('tk.tab_open'), 'waiting' => t('tk.st_waiting_customer'), 'resolved' => t('tk.st_resolved'), 'raised' => t('tk.raised_total'), 'raised90' => t('tk.raised90'), 'res_avg' => t('tk.k_res_avg'), 'last' => t('tk.last_activity')] as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $af['asort']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><label class="chk"><input type="checkbox" name="aopen" value="1"<?= $af['aopen'] ? ' checked' : '' ?>> <?= tt('tk.af_open_only') ?></label></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  <div class="field"><label>&nbsp;</label><a class="btn btn--ghost btn--sm" href="<?= url('tickets', ['view' => $view, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
</form>
<?php $compl = ['open' => count(tk_list(['category' => 'complaint', 'status' => 'open_all'])), 'all' => count(tk_list(['category' => 'complaint']))]; ?>
<div class="kpi kpi--6 tk-kpi" style="margin-bottom:14px;">
  <a class="stat<?= $compl['open'] ? ' red' : '' ?>" href="<?= url('tickets', ['view' => 'open', 'category' => 'complaint']) ?>"><b><?= $compl['open'] ?></b><?= tt('tk.k_complaints') ?><span class="s"><?= e(t('tk.k_complaints_s', ['n' => $compl['all']])) ?></span></a>
  <a class="stat" href="<?= url('tickets', ['view' => 'open']) ?>"><b><?= $S['open'] ?></b><?= tt('tk.k_open') ?><span class="s"><?= e(t('tk.k_open_s', ['u' => (int)($S['by_priority']['urgent'] ?? 0), 'h' => (int)($S['by_priority']['high'] ?? 0)])) ?></span></a>
  <a class="stat<?= $S['unassigned'] ? ' gold' : '' ?>" href="<?= url('tickets', ['view' => 'open', 'assigned' => 'none']) ?>"><b><?= $S['unassigned'] ?></b><?= tt('tk.k_unassigned') ?><span class="s"><?= e(t('tk.k_new7', ['n' => $S['new7']])) ?></span></a>
  <a class="stat<?= $S['waiting'] ? ' gold' : '' ?>" href="<?= url('tickets', ['view' => 'waiting']) ?>"><b><?= $S['waiting'] ?></b><?= tt('tk.tab_waiting') ?></a>
  <div class="stat"><b><?= e(tk_fmt_days($S['res_avg'])) ?></b><?= tt('tk.k_res_avg') ?><span class="s"><?= e(t('tk.k_res_s', ['m' => tk_fmt_days($S['res_median']), 'x' => tk_fmt_days($S['res_max']), 'n' => (int)$S['resolved_total']])) ?></span></div>
  <div class="stat"><b><?= e(tk_fmt_days($S['first_avg'])) ?></b><?= tt('tk.k_first') ?><span class="s"><?= e(t('tk.k_resolved30', ['n' => $S['resolved30']])) ?></span></div>
  <div class="stat<?= ($S['aging']['d30p'] ?? 0) ? ' gold' : '' ?>"><b><?= $S['oldest'] ? e(tk_fmt_days($S['oldest']['age'])) : '—' ?></b><?= tt('tk.k_oldest') ?><span class="s"><?= $S['oldest'] ? '<a href="' . url('ticket', ['id' => $S['oldest']['id']]) . '">' . e($S['oldest']['no']) . '</a>' : '' ?></span></div>
</div>
<div class="cols tk-sum">
  <div class="card card--tight">
    <h3 class="ac-h" style="margin-top:0;"><?= tt('tk.aging') ?> <span class="hint"><?= tt('tk.aging_hint') ?></span></h3>
    <?php if (!$S['open']): ?><p class="hint" style="margin:0;"><?= tt('tk.no_open') ?></p><?php else: ?>
    <div class="aging">
      <?php foreach ([['d3', 'green'], ['d7', 'blue'], ['d30', 'gold'], ['d30p', 'bad']] as [$k, $tone]): $n = $S['aging'][$k]; ?>
        <div class="aging-row"><span class="aging-l"><?= tt('tk.age_' . $k) ?></span><span class="aging-bar"><i class="aging-bar--<?= $tone ?>" style="width:<?= $pct($n) ?>%;"></i></span><span class="aging-n"><?= $n ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
  <div class="card card--tight">
    <h3 class="ac-h" style="margin-top:0;"><?= tt('tk.act_staff') ?> <span class="hint"><?= tt('tk.act_hint') ?></span></h3>
    <?php if (!$S['staff']): ?><p class="hint" style="margin:0;"><?= tt('vh.none') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl--compact">
      <thead><tr><th><?= tt('ts.engineer') ?></th><th class="num"><?= tt('tk.tab_open') ?></th><th class="num"><?= tt('tk.st_waiting_customer') ?></th><th class="num"><?= tt('tk.st_resolved') ?></th><th class="num"><?= tt('tk.k_res_avg') ?></th><th class="num"><?= tt('tk.comments90') ?></th><th><?= tt('tk.last_activity') ?></th></tr></thead>
      <tbody><?php $stRows = []; foreach ($actFilter($S['staff']) as $uid => $x) $stRows[] = ['uid' => $uid] + $x; [$stPage, $stPager] = page_list($stRows, 'tickets', $keep, 'spage'); foreach ($stPage as $x): $uid = $x['uid']; ?>
        <?php $lk = fn(array $q, $n) => $staff && (int)$n > 0 ? '<a href="' . url('tickets', $q) . '">' . (int)$n . '</a>' : (int)$n; ?>
        <tr><td><?= $staff ? '<a href="' . url('tickets', ['view' => 'all', 'assigned' => $uid]) . '">' . e($x['name']) . '</a>' : e($x['name']) ?></td><td class="num"><b><?= $lk(['view' => 'open', 'assigned' => $uid], $x['open']) ?></b></td><td class="num"><?= $lk(['view' => 'waiting', 'assigned' => $uid], $x['waiting']) ?></td><td class="num"><?= $lk(['view' => 'all', 'assigned' => $uid, 'status' => 'resolved'], $x['resolved']) ?></td><td class="num"><?= e(tk_fmt_days($x['res_avg'])) ?></td><td class="num"><?= $x['comments'] ?></td><td class="date"><?= $x['last'] ? e(fmt_date(substr($x['last'], 0, 10))) : '—' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?= $stPager ?>
    <?php endif; ?>
  </div>
  <?php if ($staff): ?>
  <div class="card card--tight" style="grid-column:1 / -1;">
    <h3 class="ac-h" style="margin-top:0;"><?= tt('tk.act_customer') ?></h3>
    <?php if (!$S['customers']): ?><p class="hint" style="margin:0;"><?= tt('vh.none') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl--compact">
      <thead><tr><th><?= tt('v.customer') ?></th><th class="num"><?= tt('nav.vessels') ?></th><th class="num"><?= tt('tk.raised_total') ?></th><th class="num"><?= tt('tk.raised90') ?></th><th class="num"><?= tt('tk.tab_open') ?></th><th class="num"><?= tt('tk.st_waiting_customer') ?></th><th class="num"><?= tt('tk.st_resolved') ?></th><th class="num"><?= tt('tk.k_res_avg') ?></th><th><?= tt('tk.last_activity') ?></th></tr></thead>
      <tbody><?php $custRows = []; foreach ($actFilter($S['customers']) as $cid => $x) $custRows[] = ['cid' => $cid] + $x; [$custPage, $custPager] = page_list($custRows, 'tickets', $keep, 'cpage'); foreach ($custPage as $x): $cid = $x['cid']; ?>
        <?php $ck = fn(array $q, $n) => $cid && (int)$n > 0 ? '<a href="' . url('tickets', $q + ['customer' => $cid]) . '">' . (int)$n . '</a>' : (int)$n; ?>
        <tr><td><?= $cid ? '<a href="' . url('tickets', ['view' => 'all', 'customer' => $cid]) . '">' . e($x['name']) . '</a>' : e($x['name']) ?></td><td class="num"><?= $x['vessels'] ?></td><td class="num"><?= $ck(['view' => 'all'], $x['raised']) ?></td><td class="num"><?= $ck(['view' => 'all', 'from' => date('Y-m-d', strtotime('-90 days'))], $x['raised90']) ?></td><td class="num"><b><?= $ck(['view' => 'open'], $x['open']) ?></b></td><td class="num"><?= $ck(['view' => 'waiting'], $x['waiting']) ?></td><td class="num"><?= $ck(['view' => 'all', 'status' => 'resolved'], $x['resolved']) ?></td><td class="num"><?= e(tk_fmt_days($x['res_avg'])) ?></td><td class="date"><?= $x['last'] ? e(fmt_date(substr($x['last'], 0, 10))) : '—' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div><?= $custPager ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr 1fr 1fr 1fr<?= $staff ? ' 1fr' : '' ?> auto;">
    <input type="hidden" name="p" value="tickets"><input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" type="text" value="<?= e($f['q']) ?>" placeholder="<?= tt('tk.search_ph') ?>"></div>
    <div class="field"><label for="vessel"><?= tt('v.one') ?></label>
      <select id="vessel" name="vessel" data-js-submit="1" data-js-reset="project"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="project"><?= tt('pr.one') ?></label>
      <select id="project" name="project"<?= $projects ? '' : ' disabled' ?>><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $sel($p['id'], $f['project']) ?>><?= e(($p['project_code'] ?: '') . ' ' . $p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="status"><?= tt('th.status') ?></label>
      <select id="status" name="status"><option value=""><?= tt('flt.any') ?></option><option value="open_all"<?= $sel('open_all', $f['status']) ?>><?= tt('tk.st_open_all') ?></option><?php foreach (tk_statuses() as $s): ?><option value="<?= $s ?>"<?= $sel($s, $f['status']) ?>><?= tt('tk.st_' . $s) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="category"><?= tt('tk.category') ?></label>
      <select id="category" name="category" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (tk_categories() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $f['category']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="priority"><?= tt('tk.priority') ?></label>
      <select id="priority" name="priority"><option value=""><?= tt('flt.any') ?></option><?php foreach (tk_priorities() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $f['priority']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <?php if ($staff): ?>
    <div class="field"><label for="assigned"><?= tt('tk.assignee') ?></label>
      <select id="assigned" name="assigned"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= !empty($f['unassigned']) ? ' selected' : '' ?>><?= tt('tk.unassigned') ?></option><?php foreach ($assignees as $a): ?><option value="<?= $a['id'] ?>"<?= $sel($a['id'], $f['assigned']) ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('tickets', ['view' => $view, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
<?php if (!$rows): ?>
  <div class="empty"><?= tt('tk.empty') ?></div>
<?php else: ?>
  <p class="hint" style="margin:0 0 10px;"><?= e(t('tk.count', ['n' => count($rows)])) ?></p>
  <?php if ($staff): ?>
  <form method="post" action="<?= url('tickets') ?>" id="tkbulk" data-confirm="<?= tt('tk.bulk_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.apply') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="tk_bulk"><input type="hidden" name="view" value="<?= e($view) ?>"><?php foreach (['q', 'vessel', 'project', 'status', 'priority', 'assigned', 'page'] as $k): if (($_GET[$k] ?? '') !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)$_GET[$k]) ?>"><?php endif; endforeach; ?>
    <input type="hidden" name="all_ids" value="<?= e(implode(',', array_map(fn($t) => (int)$t['id'], $rows))) ?>">
    <div class="bulkbar">
      <label class="chk"><input type="checkbox" id="chkall"> <?= tt('ct.select_page') ?></label>
      <label class="chk"><input type="checkbox" name="all" value="1"> <?= e(t('ct.select_all', ['n' => count($rows)])) ?></label>
      <select name="op" class="bulk-op" data-nosearch>
        <option value=""><?= tt('ct.bulk_choose') ?></option>
        <option value="close">✓ <?= tt('tk.bulk_close') ?></option>
        <option value="open">↺ <?= tt('tk.bulk_open') ?></option>
        <?php if (is_cp_admin()): ?><option value="delete">🗑 <?= tt('tk.bulk_delete') ?></option><?php endif; ?>
      </select>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.apply') ?></button>
      <span class="hint"><?= tt('tk.bulk_hint') ?></span>
    </div>
  </form>
  <?php endif; ?>
  <div class="table-scroll"><table class="tk-table">
    <thead><tr><?php if ($staff): ?><th style="width:28px;"></th><?php endif; ?><th><?= tt('tk.no') ?></th><th><?= tt('tk.title_f') ?></th><th><?= tt('v.one') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('tk.category') ?></th><th><?= tt('th.status') ?></th><?php if ($staff): ?><th><?= tt('tk.assignee') ?></th><?php endif; ?><th><?= tt('th.date') ?></th><th></th></tr></thead>
    <tbody><?php [$pageRows, $pager] = page_list($rows, 'tickets', $keep); foreach ($pageRows as $t): ?>
      <tr class="tk-row tk-row--<?= e($t['priority']) ?>">
        <?php if ($staff): ?><td><input type="checkbox" form="tkbulk" name="ids[]" value="<?= (int)$t['id'] ?>" class="rowchk"></td><?php endif; ?>
        <td class="ref"><a href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['ticket_no']) ?></a></td>
        <td><b><?= e($t['title']) ?></b> <?= tk_priority_pill($t['priority']) ?><?= (int)$t['n_comments'] ? ' <span class="hint">💬 ' . (int)$t['n_comments'] . '</span>' : '' ?><br><span class="hint"><?= e($t['created_name'] ?: '') ?> · <?= $t['created_side'] === 'golden' ? 'Golden A/S' : e($t['customer_name'] ?: t('role.customer')) ?></span></td>
        <td><a href="<?= url('vessel', ['id' => $t['vessel_id']]) ?>"><?= e($t['vessel']) ?></a></td>
        <td class="ref"><?= $t['project_id'] ? '<a href="' . url('projects', ['project' => $t['project_id']]) . '">' . e($t['project_code'] ?: '#' . $t['project_id']) . '</a>' : pill('navy', t('tk.generic')) ?></td>
        <td><?= e(tk_categories()[$t['category']] ?? $t['category']) ?></td>
        <td><?= tk_status_pill($t['status']) ?></td>
        <?php if ($staff): ?><td><?= $t['assignee'] ? e($t['assignee']) : '<span class="hint">' . tt('tk.unassigned') . '</span>' ?></td><?php endif; ?>
        <td class="date"><?= e(fmt_date(substr((string)$t['created_at'], 0, 10))) ?></td>
        <td style="text-align:right;"><a class="btn btn--ghost btn--sm" href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= $pager ?? '' ?>
  <?php if ($staff): ?><script nonce="<?= csp_nonce() ?>">(function(){var a=document.getElementById('chkall');if(!a)return;a.addEventListener('change',function(){document.querySelectorAll('.rowchk').forEach(function(c){c.checked=a.checked;});});})();</script><?php endif; ?>
<?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

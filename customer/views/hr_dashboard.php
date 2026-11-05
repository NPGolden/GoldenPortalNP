<?php
/** HR › Dashboard (GAS-CP-R55-005): who is away today / this week, pending requests, expiring contracts, unread messages, balance overview per person. HR and administrators. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr2_has') || !hr2_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.dashboard'), 'hr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$year = (int)($_GET['year'] ?? date('Y')); $today = date('Y-m-d'); $wk0 = date('Y-m-d', strtotime('monday this week')); $wk1 = date('Y-m-d', strtotime('sunday this week'));
$fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$fq = mb_strtolower(trim((string)($_GET['q'] ?? ''))); $fs = (string)($_GET['fs'] ?? 'active'); if (!in_array($fs, ['active', 'left', 'all'], true)) $fs = 'active';   // R77-005
$people = array_values(array_filter(hr_people(), fn($p) => $p['is_active'])); $byUser = []; foreach ($people as $p) if ($p['user_id']) $byUser[(int)$p['user_id']] = $p;
$away = all("SELECT e.user_id, e.plan_date, e.kind, e.hours, t.code, t.name AS tname, e.request_id FROM plan_entries e LEFT JOIN hr_leave_types t ON t.id = e.leave_type_id WHERE e.kind <> 'work' AND e.plan_date BETWEEN ? AND ? ORDER BY e.plan_date, e.user_id", [$wk0, $wk1]);
$todayAway = []; $weekAway = []; foreach ($away as $a) { $p = $byUser[(int)$a['user_id']] ?? null; if (!$p) continue; $weekAway[(int)$p['id']][$a['plan_date']] = $a; if ($a['plan_date'] === $today) $todayAway[] = ['p' => $p, 'a' => $a]; }
$pending = array_filter(hr_requests_for('hr', 0), fn($r) => in_array($r['status'], ['pending_mgr', 'pending_hr'], true));
$alerts = function_exists('hr_contract_alerts') ? hr_contract_alerts(90) : []; $unread = function_exists('hr_messages_unread') ? hr_messages_unread() : 0; $ann = hr_annual_type_id();
$listAll = $fs === 'active' ? $people : array_values(array_filter(hr_people(false), fn($p) => $fs === 'all' || !$p['is_active']));
$list = $fq === '' ? $listAll : array_values(array_filter($listAll, fn($p) => str_contains(mb_strtolower(($p['name_en'] ?? '') . ' ' . ($p['name_el'] ?? '') . ' ' . ($p['emp_code'] ?? '') . ' ' . ($p['email'] ?? '') . ' ' . ($p['job_title'] ?? '') . ' ' . ($p['department'] ?? '')), $fq)));
$sumRows = []; foreach ($list as $p) { if (function_exists('hr_is_contractor') && hr_is_contractor($p)) continue; $b = $ann ? hr_balance($p, $ann, $year) : null; $sumRows[] = ['p' => $p, 'b' => $b]; }
usort($sumRows, fn($x, $y) => ($x['b']['available'] ?? 0) <=> ($y['b']['available'] ?? 0));
$totAvail = array_sum(array_map(fn($r) => (float)($r['b']['available'] ?? 0), $sumRows)); $totTaken = array_sum(array_map(fn($r) => (float)($r['b']['taken'] ?? 0) + (float)($r['b']['opening'] ?? 0), $sumRows)); $totEnt = array_sum(array_map(fn($r) => (float)($r['b']['total'] ?? 0), $sumRows));
layout_head(t('hr.dashboard'), 'hr_dashboard');
?>
<div class="page-head"><div><h1>📊 <?= tt('hr.dashboard') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hr.dashboard_lede') ?></p></div>
  <form method="get" action="index.php" class="inline"><input type="hidden" name="p" value="hr_dashboard"><select name="year" data-nosearch data-js-submit="1"><?php foreach (array_unique(array_merge(hr_years(), [$year])) as $yy): ?><option value="<?= $yy ?>"<?= $yy === $year ? ' selected' : '' ?>><?= $yy ?></option><?php endforeach; ?></select></form></div>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <a class="stat" href="#hr-today"><b><?= count($todayAway) ?></b><span class="s"><?= tt('hr.d_away_today') ?></span></a>
  <a class="stat<?= $pending ? ' gold' : '' ?>" href="<?= url('hr_requests') ?>"><b><?= count($pending) ?></b><span class="s"><?= tt('hr.d_pending') ?></span></a>
  <a class="stat<?= array_filter($alerts, fn($a) => (int)$a['left_days'] < 0) ? ' red' : ($alerts ? ' gold' : '') ?>" href="#hr-contracts"><b><?= count($alerts) ?></b><span class="s"><?= tt('hr.d_contracts') ?></span></a>
  <a class="stat<?= $unread ? ' gold' : '' ?>" href="<?= url('admin_hr', ['tab' => 'people']) ?>"><b><?= $unread ?></b><span class="s"><?= tt('hr.d_unread') ?></span></a>
</div>
<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat"><b><?= count($people) ?></b><span class="s"><?= tt('hr.d_headcount') ?></span></span>
  <span class="stat"><b><?= e($fh($totEnt)) ?></b><span class="s"><?= tt('hr.d_ann_total') ?> <?= $year ?></span></span>
  <span class="stat"><b><?= e($fh($totTaken)) ?></b><span class="s"><?= tt('hr.d_ann_taken') ?></span></span>
  <span class="stat green"><b><?= e($fh($totAvail)) ?></b><span class="s"><?= tt('hr.d_ann_avail') ?></span></span>
</div>

<div class="cols">
<div class="card" id="hr-today">
  <h2>🏖 <?= tt('hr.d_today_title') ?> · <?= e(fmt_date($today)) ?></h2>
  <?php if (!$todayAway): ?><p class="hint"><?= tt('hr.d_nobody') ?></p><?php else: ?><ul><?php foreach ($todayAway as $x): ?><li><a href="<?= url('hr_person', ['id' => (int)$x['p']['id']]) ?>"><b><?= e($x['p']['name_en']) ?></b></a> <span class="hint">· <?= e($x['a']['code'] ?? t('pl.k_' . $x['a']['kind'])) ?><?= $x['a']['hours'] ? ' ' . e($fh($x['a']['hours'])) . ' h' : '' ?><?= $x['p']['department'] ? ' · ' . e($x['p']['department']) : '' ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <h3 style="margin-top:12px;"><?= tt('hr.d_week_title') ?> <span class="hint"><?= e(fmt_date($wk0)) ?> – <?= e(fmt_date($wk1)) ?></span></h3>
  <?php if (!$weekAway): ?><p class="hint"><?= tt('hr.d_nobody') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><?php for ($d = strtotime($wk0); $d <= strtotime($wk1); $d += 86400): ?><th class="num"><?= date('D j', $d) ?></th><?php endfor; ?></tr></thead><tbody>
  <?php foreach ($weekAway as $pid => $days): $pp = hr_person((int)$pid); ?><tr><td><a href="<?= url('hr_person', ['id' => (int)$pid]) ?>"><?= e($pp['name_en'] ?? '') ?></a></td><?php for ($d = strtotime($wk0); $d <= strtotime($wk1); $d += 86400): $k = date('Y-m-d', $d); $a = $days[$k] ?? null; ?><td class="num"><?= $a ? '<span class="pl-chip pl-' . (PL_TONES[$a['kind']] ?? 'o') . '" title="' . e($a['tname'] ?? '') . '">' . e($a['code'] ?? '•') . '</span>' : '' ?></td><?php endfor; ?></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<div class="card" id="hr-pending">
  <div class="card-head"><h2>✅ <?= tt('hr.d_pending') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('hr_requests') ?>"><?= tt('hr.requests') ?> ›</a></div>
  <?php if (!$pending): ?><p class="hint"><?= tt('hr.no_requests2') ?></p><?php else: ?><ul><?php foreach (array_slice($pending, 0, 12) as $r): ?><li><a href="<?= url('hr_person', ['id' => (int)$r['person_id'], 'tab' => 'req']) ?>"><b><?= e($r['name_en']) ?></b></a> <span class="hint">· <?= e($r['code']) ?> <?= e(fmt_date($r['date_from'])) ?><?= $r['date_to'] !== $r['date_from'] ? ' – ' . e(fmt_date($r['date_to'])) : '' ?> · <?= hr_status_pill($r['status']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (function_exists('cel_upcoming')): $cel = cel_upcoming(30, function_exists('hr_personal_can') && hr_personal_can()); if ($cel): ?><h3 style="margin-top:12px;">🎉 <?= tt('cel.title') ?> <span class="hint">30 d</span></h3><ul><?php foreach ($cel as $c): ?><li><span class="hint"><?= e(fmt_date($c['date'])) ?></span> <a href="<?= url('hr_person', ['id' => (int)$c['person']['id']]) ?>"><b><?= e($c['person']['name_en']) ?></b></a> · <?= $c['kind'] === 'birthday' ? '🎂 ' . tt('cel.birthday') . ($c['age'] !== null ? ' (' . (int)$c['age'] . ')' : '') : '🎊 ' . tt('cel.nameday') . ' · ' . e((string)$c['name']) ?></li><?php endforeach; ?></ul><?php endif; endif; ?>
  <?php $hraPend = function_exists('hra_can_manage') && hra_can_manage() ? hra_queue('open') : []; if ($hraPend): ?><h3 style="margin-top:12px;">🤖 <?= tt('hra.d_tile') ?> (<?= count($hraPend) ?>)</h3><ul><?php foreach ($hraPend as $hp): ?><li><a href="<?= url('hra_flow', ['id' => (int)$hp['id']]) ?>"><b><?= e($hp['code']) ?></b> · <?= e((string)($hp['name_en'] ?: $hp['title'])) ?></a> <span class="hint"><?= e(t('hra.k_' . $hp['kind'])) ?> · <?= (int)$hp['n_fields'] ?> <?= tt('hra.fields') ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php $offPend = function_exists('hr_off_pending') ? hr_off_pending() : []; if ($offPend): ?><h3 style="margin-top:12px;">📋 <?= tt('hr.off_title') ?></h3><ul><?php foreach ($offPend as $op): ?><li><a href="<?= url('hr_person', ['id' => (int)$op['id'], 'tab' => 'emp']) ?>#hr-off"><b><?= e($op['name_en']) ?></b></a> <span class="hint">· <?= e(fmt_date($op['end_date'])) ?> · <?= e(t('hr.er_' . $op['reason'])) ?></span> <?= pill('gold', (int)$op['done'] . ' / ' . (int)$op['total']) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php $certA = function_exists('hr_cert_alerts') ? hr_cert_alerts(90) : []; if ($certA): ?><h3 style="margin-top:12px;">🏅 <?= tt('hr.tr_cert_alerts') ?></h3><ul><?php foreach ($certA as $ca): ?><li><a href="<?= url('hr_person', ['id' => (int)$ca['id'], 'tab' => 'train']) ?>"><b><?= e($ca['name_en']) ?></b></a> <span class="hint">· <?= e($ca['title']) ?> · <?= e(fmt_date($ca['cert_expiry'])) ?></span> <?= (int)$ca['left_days'] < 0 ? pill('bad', t('hr.tr_expired')) : pill('gold', e(t('hr.in_days', ['n' => (int)$ca['left_days']]))) ?></li><?php endforeach; ?></ul><?php endif; ?>
  <h3 style="margin-top:12px;" id="hr-contracts">📄 <?= tt('hr.d_contracts') ?> <span class="hint">90 d</span></h3>
  <?php if (!$alerts): ?><p class="hint">—</p><?php else: ?><ul><?php foreach ($alerts as $a): ?><li><a href="<?= url('hr_person', ['id' => (int)$a['id']]) ?>"><b><?= e($a['name_en']) ?></b></a> <span class="hint">· <?= e(fmt_date($a['contract_end'])) ?></span> <?= (int)$a['left_days'] < 0 ? pill('bad', t('hr.contract_over_s')) : pill('gold', e(t('hr.in_days', ['n' => (int)$a['left_days']]))) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
</div>

<?php if (function_exists('hr_doc_due_rows') && ($dueRows = hr_doc_due_rows())): ?>
<div class="card" id="hr-doc-due">
  <h2>📄 <?= tt('hr.doc_due_title') ?> <span class="count"><?= count($dueRows) ?></span></h2><p class="hint"><?= tt('hr.doc_due_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('hr.reason') ?></th><th><?= tt('hr.doc_due_by') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($dueRows as $r): ?><tr class="<?= (int)$r['days_left'] < 0 ? 'is-late' : '' ?>"><td><a href="<?= url('hr_person', ['id' => (int)$r['person_id']]) ?>#hr-req"><b><?= e($r['name_en']) ?></b></a></td><td><b><?= e($r['code']) ?></b> <?= e($r['type_name']) ?></td><td class="date"><?= e(fmt_date($r['date_from'])) ?></td><td class="hint"><?= e(hr_reason_label($r['reason_code'])) ?></td><td><?= pill((int)$r['days_left'] < 0 ? 'bad' : ((int)$r['days_left'] <= 1 ? 'gold' : 'mute'), fmt_date($r['doc_due']) . ((int)$r['days_left'] < 0 ? ' · ' . t('hr.doc_overdue') : '')) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hr_requests', ['st' => 'all']) ?>"><?= tt('hr.requests') ?> ›</a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<div class="card" id="hr-balances">
  <div class="card-head"><h2>🗓 <?= tt('hr.d_balances') ?> <?= $year ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['hy' => $year]) ?>#hr-report">📈 <?= tt('hr.report') ?> ›</a> <a class="btn btn--ghost btn--sm" href="<?= url('hr_export', ['year' => $year]) ?>">⬇ Excel</a></div>
  <p class="hint"><?= tt('hr.d_balances_hint') ?></p>
  <form method="get" action="index.php" class="hr-filters"><input type="hidden" name="p" value="hr_dashboard"><input type="hidden" name="year" value="<?= $year ?>">
    <input name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="<?= tt('hr.f_search') ?>" style="min-width:220px;" autofocus>
    <select name="fs" data-nosearch data-js-submit="1"><option value="active"<?= $fs === 'active' ? ' selected' : '' ?>><?= tt('hr.f_active') ?></option><option value="left"<?= $fs === 'left' ? ' selected' : '' ?>><?= tt('hr.f_left') ?></option><option value="all"<?= $fs === 'all' ? ' selected' : '' ?>><?= tt('hr.f_all') ?></option></select>
    <button class="btn btn--ghost btn--sm" type="submit">🔍</button><?php if ($fq !== '' || $fs !== 'active'): ?> <a class="btn btn--ghost btn--sm" href="<?= url('hr_dashboard', ['year' => $year]) ?>#hr-balances">× <?= tt('hr.f_clear') ?></a><?php endif; ?>
    <span class="hint"><?= e(t('hr.f_count', ['n' => count($sumRows), 't' => count($listAll)])) ?></span> <a class="btn btn--ghost btn--sm" href="<?= url('admin_hr', ['tab' => 'people']) ?>">👥 <?= tt('hr.people') ?> ›</a>
  </form>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('hr.department') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th class="num"><?= tt('hr.carry') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.pending') ?></th><th class="num"><?= tt('hr.available') ?></th><th><?= tt('hr.mode') ?></th><th><?= tt('hr.d_others') ?></th></tr></thead><tbody>
  <?php foreach ($sumRows as $row): $p = $row['p']; $b = $row['b']; $others = []; if (function_exists('hr_status_summary')) foreach (hr_status_summary($p, $year)['others'] as $ob) if ($ob['taken'] > 0 || $ob['pending'] > 0) $others[] = $ob['type']['code'] . ' ' . $fh($ob['taken']) . ($ob['unit'] === 'hour' ? 'h' : ''); ?>
  <tr<?= $p['is_active'] ? '' : ' class="is-retired"' ?>><td><a href="<?= url('hr_person', ['id' => (int)$p['id']]) ?>"><b><?= e($p['name_en']) ?></b></a><?= $p['is_active'] ? '' : ' ' . pill('mute', t('hr.f_left')) ?></td><td class="hint"><?= e($p['department'] ?? '') ?></td><td class="num"><?= $b ? e($fh($b['entitled'])) : '—' ?></td><td class="num"><?= $b ? e($fh($b['carry'])) : '—' ?></td><td class="num"><?= $b ? e($fh($b['taken'] + $b['opening'])) : '—' ?></td><td class="num"><?= $b && $b['pending'] > 0 ? e($fh($b['pending'])) : '—' ?></td><td class="num"><?= $b && $b['available'] !== null ? pill($b['available'] < 0 ? 'bad' : ($b['available'] <= 3 ? 'gold' : 'green'), $fh($b['available'])) : '—' ?></td><td class="hint"><?= $b ? ($b['mode'] === 'auto' ? '🔒' : ($b['mode'] === 'manual' ? '🔓' : '—')) : '' ?></td><td class="hint"><?= e(implode(' · ', $others)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php layout_foot(); ?>

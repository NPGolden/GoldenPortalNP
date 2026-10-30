<?php
/** HR › Dashboard (GAS-CP-R55-005): who is away today / this week, pending requests, expiring contracts, unread messages, balance overview per person. HR and administrators. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr2_has') || !hr2_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.dashboard'), 'hr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$year = (int)($_GET['year'] ?? date('Y')); $today = date('Y-m-d'); $wk0 = date('Y-m-d', strtotime('monday this week')); $wk1 = date('Y-m-d', strtotime('sunday this week'));
$fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$people = array_values(array_filter(hr_people(), fn($p) => $p['is_active'])); $byUser = []; foreach ($people as $p) if ($p['user_id']) $byUser[(int)$p['user_id']] = $p;
$away = all("SELECT e.user_id, e.plan_date, e.kind, e.hours, t.code, t.name AS tname, e.request_id FROM plan_entries e LEFT JOIN hr_leave_types t ON t.id = e.leave_type_id WHERE e.kind <> 'work' AND e.plan_date BETWEEN ? AND ? ORDER BY e.plan_date, e.user_id", [$wk0, $wk1]);
$todayAway = []; $weekAway = []; foreach ($away as $a) { $p = $byUser[(int)$a['user_id']] ?? null; if (!$p) continue; $weekAway[(int)$p['id']][$a['plan_date']] = $a; if ($a['plan_date'] === $today) $todayAway[] = ['p' => $p, 'a' => $a]; }
$pending = array_filter(hr_requests_for('hr', 0), fn($r) => in_array($r['status'], ['pending_mgr', 'pending_hr'], true));
$alerts = function_exists('hr_contract_alerts') ? hr_contract_alerts(90) : []; $unread = function_exists('hr_messages_unread') ? hr_messages_unread() : 0; $ann = hr_annual_type_id();
$sumRows = []; foreach ($people as $p) { if (function_exists('hr_is_contractor') && hr_is_contractor($p)) continue; $b = $ann ? hr_balance($p, $ann, $year) : null; $sumRows[] = ['p' => $p, 'b' => $b]; }
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
  <h3 style="margin-top:12px;" id="hr-contracts">📄 <?= tt('hr.d_contracts') ?> <span class="hint">90 d</span></h3>
  <?php if (!$alerts): ?><p class="hint">—</p><?php else: ?><ul><?php foreach ($alerts as $a): ?><li><a href="<?= url('hr_person', ['id' => (int)$a['id']]) ?>"><b><?= e($a['name_en']) ?></b></a> <span class="hint">· <?= e(fmt_date($a['contract_end'])) ?></span> <?= (int)$a['left_days'] < 0 ? pill('bad', t('hr.contract_over_s')) : pill('gold', e(t('hr.in_days', ['n' => (int)$a['left_days']]))) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
</div>

<div class="card" id="hr-balances">
  <div class="card-head"><h2>🗓 <?= tt('hr.d_balances') ?> <?= $year ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['hy' => $year]) ?>#hr-report">📈 <?= tt('hr.report') ?> ›</a> <a class="btn btn--ghost btn--sm" href="<?= url('hr_export', ['year' => $year]) ?>">⬇ Excel</a></div>
  <p class="hint"><?= tt('hr.d_balances_hint') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('hr.department') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th class="num"><?= tt('hr.carry') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.pending') ?></th><th class="num"><?= tt('hr.available') ?></th><th><?= tt('hr.mode') ?></th><th><?= tt('hr.d_others') ?></th></tr></thead><tbody>
  <?php foreach ($sumRows as $row): $p = $row['p']; $b = $row['b']; $others = []; if (function_exists('hr_status_summary')) foreach (hr_status_summary($p, $year)['others'] as $ob) if ($ob['taken'] > 0 || $ob['pending'] > 0) $others[] = $ob['type']['code'] . ' ' . $fh($ob['taken']) . ($ob['unit'] === 'hour' ? 'h' : ''); ?>
  <tr><td><a href="<?= url('hr_person', ['id' => (int)$p['id']]) ?>"><b><?= e($p['name_en']) ?></b></a></td><td class="hint"><?= e($p['department'] ?? '') ?></td><td class="num"><?= $b ? e($fh($b['entitled'])) : '—' ?></td><td class="num"><?= $b ? e($fh($b['carry'])) : '—' ?></td><td class="num"><?= $b ? e($fh($b['taken'] + $b['opening'])) : '—' ?></td><td class="num"><?= $b && $b['pending'] > 0 ? e($fh($b['pending'])) : '—' ?></td><td class="num"><?= $b && $b['available'] !== null ? pill($b['available'] < 0 ? 'bad' : ($b['available'] <= 3 ? 'gold' : 'green'), $fh($b['available'])) : '—' ?></td><td class="hint"><?= $b ? ($b['mode'] === 'auto' ? '🔒' : ($b['mode'] === 'manual' ? '🔓' : '—')) : '' ?></td><td class="hint"><?= e(implode(' · ', $others)) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php layout_foot(); ?>

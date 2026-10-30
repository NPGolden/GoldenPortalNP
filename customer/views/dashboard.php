<?php
/**
 * Dashboard — the editorial / glass layout (R16): a one-line headline,
 * four large figures with sparklines from the weekly snapshots, progress
 * by sub-project as seven-segment bars, "needs your attention" merged
 * from every workflow, this week's events, and the vessel strip.
 * Scope-aware: customers see their own account, staff everything.
 */
require __DIR__ . '/_layout.php';
$u     = require_login();
$staff = is_staff();
[$scope, $sargs] = scope_sql('p');
$today = date('Y-m-d'); $monday = date('Y-m-d', strtotime('monday this week')); $prevSunday = date('Y-m-d', strtotime($monday) - 86400);
$isoW = (int) date('W'); $prevW = (int) date('W', strtotime($prevSunday));
$stages = stage_defs(); $nSt = count($stages);
$ovr = fn(array $r) => overall_pct($r);                                     // stages marked N/A are left out (R24-004)

/* ---- active sub-projects in scope ---- */
$subs = all("SELECT s.*, p.id AS pid, p.name AS project, p.project_code, p.status AS pstatus, p.deadline AS pdeadline, v.name AS vessel, v.id AS vid
             FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id
             WHERE {$scope} AND p.status = 'active' ORDER BY p.updated_at DESC, s.sort_order, s.code", $sargs);
$activeSubs = array_values(array_filter($subs, fn($s) => $s['status'] !== 'completed'));
$vesselsInWork = count(array_unique(array_filter(array_column($subs, 'vid'))));
$progNow = $subs ? (int) round(array_sum(array_map($ovr, $subs)) / count($subs)) : 0;
/* baseline + 8-week sparkline from the snapshots */
$spark = []; $base = null;
if ($subs) {
    $ids = implode(',', array_map(fn($s) => (int)$s['id'], $subs));
    for ($i = 7; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime($prevSunday) - $i * 7 * 86400); $d = $i === 0 ? $prevSunday : $d;
        $rows = all("SELECT sp.* FROM sp_progress_snapshots sp JOIN (SELECT subproject_id, MAX(snap_date) md FROM sp_progress_snapshots WHERE subproject_id IN ({$ids}) AND snap_date <= ? GROUP BY subproject_id) m ON m.subproject_id = sp.subproject_id AND m.md = sp.snap_date", [$d]);
        $avg = $rows ? (int) round(array_sum(array_map($ovr, $rows)) / count($subs)) : 0;        // sub-projects without a snapshot count as 0 at that date
        $spark[] = $avg;
    }
    $base = end($spark); $spark[] = $progNow;
}
$delta = $base === null ? null : $progNow - $base;

/* ---- tickets ---- */
$tkOpen = tk_has() ? tk_list(['status' => 'open_all']) : [];
$tkUrgent = count(array_filter($tkOpen, fn($t) => $t['priority'] === 'urgent'));
$tkSpark = [];
if (tk_has()) { [$ts, $ta] = tk_scope(); for ($i = 7; $i >= 0; $i--) { $from = date('Y-m-d', strtotime($monday) - $i * 7 * 86400); $to = date('Y-m-d', strtotime($from) + 6 * 86400);
    $tkSpark[] = (int) scalar("SELECT COUNT(*) FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id WHERE {$ts} AND t.created_at BETWEEN ? AND ?", array_merge($ta, [$from . ' 00:00:00', $to . ' 23:59:59'])); } }

/* ---- offers / approvals / hours ---- */
$ofOn = of_has() && of_visible();   // R31-006: staff without financial data never see offers
$ofPending = $ofOn ? of_pending() : [];
$ofAmount = array_sum(array_map(fn($o) => (float)$o['amount'], $ofPending));
$ofOldest = $ofPending ? max(array_map(fn($o) => of_age($o) ?? 0, $ofPending)) : 0;
$oaPending = oa_pending();
$oaRejected = $staff ? count(oa_list(null, 'rejected')) : 0;
$hoursWeek = 0.0; $engWeek = 0;
if ($staff && ts_has()) { $m = ts_manhours(null, ['submitted', 'approved', 'invoiced'], $monday, date('Y-m-d', strtotime($monday) + 6 * 86400), null); $hoursWeek = (float)($m['total']['total'] ?? 0); $engWeek = count($m['engineer'] ?? []); }

/* ---- deadlines at risk ---- */
$risk = [];
foreach ($activeSubs as $s) { $dl = $s['deadline'] ?? null; if (!$dl) continue; $days = (int) floor((strtotime($dl) - strtotime($today)) / 86400); $o = $ovr($s);
    if ($days < 0 || ($days <= 21 && $o < 80)) $risk[] = ['sub' => $s, 'days' => $days, 'overall' => $o]; }
usort($risk, fn($a, $b) => $a['days'] <=> $b['days']);

/* ---- needs your attention (merged, most urgent first) ---- */
$att = [];
foreach ($tkOpen as $t) {
    if ($staff && !$t['assigned_to'] && $t['status'] !== 'waiting_customer') $att[] = ['age' => (int) floor((time() - strtotime($t['created_at'])) / 86400), 'ref' => $t['ticket_no'], 'text' => $t['title'] . ' — ' . t('dash.unassigned'), 'url' => url('ticket', ['id' => $t['id']]), 'tone' => $t['priority'] === 'urgent' ? 'bad' : 'gold'];
    if (!$staff && $t['status'] === 'waiting_customer') $att[] = ['age' => (int) floor((time() - strtotime($t['updated_at'])) / 86400), 'ref' => $t['ticket_no'], 'text' => $t['title'] . ' — ' . t('dash.waiting_you'), 'url' => url('ticket', ['id' => $t['id']]), 'tone' => 'gold'];
}
foreach ($oaPending as $o) $att[] = ['age' => $o['submitted_at'] ? (int) floor((time() - strtotime($o['submitted_at'])) / 86400) : 0, 'ref' => $o['doc_no'], 'text' => $o['title'] . ' ' . $o['revision'] . ' — ' . t($staff ? 'dash.awaiting_owner' : 'dash.to_approve'), 'url' => url('approval', ['id' => $o['id']]), 'tone' => $staff ? 'mute' : 'gold'];
foreach ($ofPending as $o) $att[] = ['age' => of_age($o) ?? 0, 'ref' => $o['offer_no'] . ' ' . $o['revision'], 'text' => $o['title'] . ' · ' . of_money((float)$o['amount'], $o['currency']) . ' — ' . t($staff ? 'dash.awaiting_owner' : 'dash.to_approve'), 'url' => url('offer', ['id' => $o['id']]), 'tone' => $staff ? 'mute' : 'gold'];
if (function_exists('bg_attention')) foreach (bg_attention() as $x) $att[] = $x;
foreach ($risk as $r) if ($r['days'] < 0) $att[] = ['age' => -$r['days'], 'late' => true, 'ref' => trim($r['sub']['code'] . ' ' . $r['sub']['name']), 'text' => ($r['sub']['project_code'] ?: $r['sub']['project']) . ' — ' . t('dash.deadline_late', ['p' => $r['overall']]), 'url' => url('subproject', ['id' => $r['sub']['id']]), 'tone' => 'bad'];
if ($staff) { foreach (ts_to_approve() as $x) $att[] = ['age' => (int) floor((time() - strtotime($x['submitted_at'] ?? $x['updated_at'])) / 86400), 'ref' => $x['ts_no'], 'text' => $x['user_name'] . ' · ' . ts_fmt((float)$x['h_normal'] + (float)$x['h_overtime'] + (float)$x['h_holiday']) . ' h — ' . t('dash.to_approve'), 'url' => url('timesheet', ['id' => $x['id']]), 'tone' => 'gold'];
  foreach (all("SELECT f.id, f.report_no, f.updated_at, f.customer_remark, p.project_code FROM fsr_reports f JOIN projects p ON p.id = f.project_id WHERE {$scope} AND f.status = 'disputed'", $sargs) as $f) $att[] = ['age' => (int) floor((time() - strtotime($f['updated_at'])) / 86400), 'ref' => $f['report_no'], 'text' => ($f['project_code'] ?: '') . ' — ' . t('dash.sent_back') . ($f['customer_remark'] ? ': ' . mb_substr($f['customer_remark'], 0, 60) : ''), 'url' => url('fsr', ['id' => $f['id']]), 'tone' => 'bad']; }
else { foreach (all("SELECT f.id, f.report_no, f.submitted_at, p.project_code FROM fsr_reports f JOIN projects p ON p.id = f.project_id WHERE {$scope} AND f.status = 'submitted'", $sargs) as $f) $att[] = ['age' => (int) floor((time() - strtotime($f['submitted_at'] ?? $today)) / 86400), 'ref' => $f['report_no'], 'text' => ($f['project_code'] ?: '') . ' — ' . t('dash.to_accept'), 'url' => url('fsr', ['id' => $f['id']]), 'tone' => 'gold'];
  foreach (all("SELECT j.id, j.jo_no, j.title, j.updated_at FROM job_orders j JOIN projects p ON p.id = j.project_id WHERE {$scope} AND j.status = 'pending_approval' AND j.requested_side = 'golden'", $sargs) as $j) $att[] = ['age' => (int) floor((time() - strtotime($j['updated_at'])) / 86400), 'ref' => $j['jo_no'], 'text' => $j['title'] . ' — ' . t('dash.jo_approve'), 'url' => url('jo', ['id' => $j['id']]), 'tone' => 'gold']; }
usort($att, fn($a, $b) => [($b['tone'] === 'bad') <=> ($a['tone'] === 'bad'), $b['age'] <=> $a['age']]);
$att = array_slice($att, 0, 8);

/* ---- this week across the fleet ---- */
$week = [];
if (tk_has()) { [$ts, $ta] = tk_scope(); foreach (all("SELECT e.created_at, e.event, e.note, t.id, t.ticket_no, t.title, v.name AS vessel FROM ticket_events e JOIN tickets t ON t.id = e.ticket_id JOIN vessels v ON v.id = t.vessel_id WHERE {$ts} AND e.created_at >= ? AND e.event IN ('created','status_resolved','status_closed','assigned','customer_replied') ORDER BY e.id DESC LIMIT 6", array_merge($ta, [$monday . ' 00:00:00'])) as $e)
    $week[] = ['at' => $e['created_at'], 'ref' => $e['ticket_no'], 'text' => $e['title'] . ' · ' . wk_event_label($e['event']) . ' · ' . $e['vessel'], 'url' => url('ticket', ['id' => $e['id']]), 'tone' => in_array($e['event'], ['status_resolved', 'status_closed'], true) ? 'green' : ($e['event'] === 'created' ? 'gold' : 'navy')]; }
foreach (all("SELECT o.id, o.doc_no, o.title, o.revision, o.status, o.decided_time, o.submitted_at, o.updated_at FROM owner_approvals o JOIN projects p ON p.id = o.project_id WHERE {$scope} AND o.status <> 'draft' AND o.updated_at >= ? ORDER BY o.updated_at DESC LIMIT 4", array_merge($sargs, [$monday . ' 00:00:00'])) as $o)
    $week[] = ['at' => $o['decided_time'] ?: $o['updated_at'], 'ref' => $o['doc_no'], 'text' => $o['title'] . ' ' . $o['revision'] . ' · ' . t('sub.' . $o['status']), 'url' => url('approval', ['id' => $o['id']]), 'tone' => str_starts_with($o['status'], 'approved') ? 'green' : ($o['status'] === 'rejected' ? 'bad' : 'navy')];
foreach (all("SELECT f.id, f.report_no, f.status, f.updated_at, f.submitted_name, p.project_code FROM fsr_reports f JOIN projects p ON p.id = f.project_id WHERE {$scope} AND f.status IN ('submitted','accepted','disputed') AND f.updated_at >= ? ORDER BY f.updated_at DESC LIMIT 4", array_merge($sargs, [$monday . ' 00:00:00'])) as $f)
    $week[] = ['at' => $f['updated_at'], 'ref' => $f['report_no'], 'text' => ($f['project_code'] ?: '') . ' · ' . t('fsr.st_' . $f['status']) . ($f['submitted_name'] ? ' · ' . $f['submitted_name'] : ''), 'url' => url('fsr', ['id' => $f['id']]), 'tone' => $f['status'] === 'accepted' ? 'green' : ($f['status'] === 'disputed' ? 'bad' : 'navy')];
if ($ofOn) foreach (all("SELECT o.id, o.offer_no, o.revision, o.title, o.status, o.amount, o.currency, o.updated_at FROM offers o JOIN projects p ON p.id = o.project_id WHERE {$scope} AND o.updated_at >= ? ORDER BY o.updated_at DESC LIMIT 4", array_merge($sargs, [$monday . ' 00:00:00'])) as $o)
    $week[] = ['at' => $o['updated_at'], 'ref' => $o['offer_no'] . ' ' . $o['revision'], 'text' => $o['title'] . ' · ' . of_money((float)$o['amount'], $o['currency']) . ' · ' . of_status_label($o['status']), 'url' => url('offer', ['id' => $o['id']]), 'tone' => in_array(of_status_behaves($o['status']), ['approved', 'closed_won'], true) ? 'green' : (of_status_behaves($o['status']) === 'closed_lost' ? 'bad' : 'gold')];
usort($week, fn($a, $b) => strcmp($b['at'], $a['at'])); $week = array_slice($week, 0, 8);

/* ---- vessels strip ---- */
[$vscope, $vargs] = tk_scope();
$vessels = all("SELECT v.*, c.name AS customer_name,
    (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id AND p.status = 'active') AS n_active,
    (SELECT COUNT(*) FROM tickets t WHERE t.vessel_id = v.id AND t.status IN ('open','in_progress','waiting_customer')) AS n_tickets
    FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE {$vscope} AND v.is_active = 1 ORDER BY n_active DESC, n_tickets DESC, v.name LIMIT 8", $vargs);
$vesselProg = []; foreach ($subs as $s) if ($s['vid']) { $vesselProg[$s['vid']][] = $ovr($s); }

/* ---- sparkline svg ---- */
$sparkSvg = function (array $pts, string $color, int $w = 150, int $h = 30): string {
    if (count($pts) < 2) return '';
    $max = max(max($pts), 1); $min = min($pts); $rng = max(1, $max - $min); $n = count($pts) - 1; $d = '';
    foreach ($pts as $i => $v) $d .= ($i ? ' L' : 'M') . round($i / $n * $w, 1) . ',' . round($h - 2 - ($v - $min) / $rng * ($h - 4), 1);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" aria-hidden="true"><path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/></svg>';
};
$ageLabel = fn(array $a) => !empty($a['late']) ? t('dash.late', ['n' => $a['age']]) : ($a['age'] <= 0 ? t('dash.today') : t('dash.days', ['n' => $a['age']]));

layout_head(t('dash.title'), 'dashboard');
?>
<h1 class="dash-h"><?= e(t('dash.headline', ['w' => $isoW, 'v' => t('dash.h_vessels', ['n' => $vesselsInWork]), 'd' => $risk ? t('dash.h_risk', ['n' => count($risk)]) : t('dash.h_risk_none')])) ?></h1>

<div class="bigs">
  <a class="big" href="<?= url('projects') ?>"><b><?= $progNow ?><small>%</small></b><span><?= tt('dash.k_progress') ?></span><em class="<?= $delta === null ? 'mute' : ($delta >= 0 ? 'up' : 'down') ?>"><?= $delta === null ? '—' : ($delta >= 0 ? '▲ +' : '▼ ') . $delta . ' ' . e(t('dash.k_delta', ['w' => $prevW])) ?></em><?= $sparkSvg($spark, 'var(--gold)') ?></a>
  <a class="big" href="<?= url('tickets') ?>"><b><?= count($tkOpen) ?></b><span><?= tt('dash.k_tickets') ?></span><em class="<?= $tkUrgent ? 'down' : 'mute' ?>"><?= $tkUrgent ? e(t('dash.k_urgent', ['n' => $tkUrgent])) : '—' ?></em><?= $sparkSvg($tkSpark, 'var(--gold)') ?></a>
  <?php if ($ofOn): ?><a class="big" href="<?= url('offers', ['status' => 'sent']) ?>"><b><?php if (!fin_visible()): ?><?= count($ofPending) ?><?php else: ?><?= $ofAmount >= 1000 ? number_format($ofAmount / 1000, 1, '.', '') . '<small> k€</small>' : number_format($ofAmount, 0, ',', '.') . '<small> €</small>' ?><?php endif; ?></b><span><?= tt('dash.k_offers') ?></span><em class="<?= $ofOldest > 14 ? 'gold' : 'mute' ?>"><?= $ofPending ? e(t('dash.k_oldest', ['n' => $ofOldest])) . ' · ' . count($ofPending) : '—' ?></em></a>
  <?php elseif (function_exists('td_badge') && td_has()): ?><a class="big" href="<?= url('tasks') ?>"><b><?= td_badge() ?></b><span><?= tt('td.title') ?></span><em class="mute"><?= tt('td.v_myday') ?></em></a><?php endif; ?>
  <?php if ($staff): ?>
    <a class="big" href="<?= url('timesheets') ?>"><b><?= ts_fmt($hoursWeek) ?><small> h</small></b><span><?= tt('dash.k_hours') ?></span><em class="up"><?= e(t('dash.k_engineers', ['n' => $engWeek])) ?></em></a>
  <?php else: ?>
    <a class="big" href="<?= url('approvals') ?>"><b><?= count($oaPending) ?></b><span><?= tt('dash.k_oa') ?></span><em class="mute"><?= count($oaPending) ? tt('oa.review') : '—' ?></em></a>
  <?php endif; ?>
</div>
<?php if ($staff && function_exists('kpi_can') && kpi_can()): kpi_snapshot_monthly(); $ck = kpi_cockpit(5); if ($ck): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">🎯 <?= tt('kpi.cockpit') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('kpis') ?>"><?= tt('kpi.title') ?> ›</a></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('kpi.cockpit_hint') ?></p>
  <div class="table-scroll"><table class="tbl--compact"><thead><tr><th><?= tt('pr.one') ?></th><th>PM</th><th class="num">🔴</th><th class="num">🟠</th><th><?= tt('kpi.red_ones') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($ck as $row): $P = $row['P']; ?><tr><td><a href="<?= url('kpis', ['project' => $P['pid']]) ?>"><b><?= e($P['p']['project_code'] ?: $P['p']['name']) ?></b></a> <span class="hint"><?= e($P['p']['vessel'] ?: '') ?></span></td><td class="hint"><?= e($P['p']['project_manager'] ?: '') ?></td><td class="num"><b class="bg-neg"><?= $row['red'] ?></b></td><td class="num"><?= $row['amber'] ?></td><td><?= implode(' ', array_map(fn($k) => '<span class="kpi-chip">' . e(kpi_label($k)) . ' ' . e(kpi_fmt($k, $P['v'][$k])) . '</span>', $row['reds'])) ?></td><td class="ob-act"><a class="btn btn--ghost btn--sm" href="<?= url('kpis', ['project' => $P['pid']]) ?>"><?= tt('fsr.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; endif; ?>
<?php if ($staff && function_exists('pl_has') && pl_has()): $plFrom = date('Y-m-d'); $plTo = date('Y-m-d', strtotime('+6 days')); $plMine = pl_grid($plFrom, $plTo, (int)$u['id'])[(int)$u['id']] ?? []; if ($plMine || pl_can_edit()): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;"><?= tt('pl.my_week') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('planning', pl_can_edit() ? [] : ['me' => 1]) ?>"><?= tt('pl.title') ?> ›</a></div>
  <?php if (!$plMine): ?><p class="hint"><?= tt('pl.nothing_planned') ?></p><?php else: ?>
  <div class="tm-list"><?php foreach ($plMine as $d => $es): foreach ($es as $e): ?><span class="tm-chip" title="<?= e(pl_label($e, true) . ($e['note'] ? ' · ' . $e['note'] : '')) ?>"><b><?= e(date('D j', strtotime($d))) ?></b> <span class="pl-chip pl-<?= pl_tone($e) ?>" style="display:inline-block;"><?= e(pl_label($e)) ?></span><?= $e['car'] ? ' <em>🚐 ' . e($e['car']) . '</em>' : '' ?></span><?php endforeach; endforeach; ?></div>
  <?php endif; ?>
</div>
<?php endif; endif; ?>
<?php if ($staff && tm_has() && tm_restricted()): $mySubs = tm_my_subs(); ?>
<div class="card">
  <h2><?= tt('tm.my_subs') ?> <span class="hint" style="font-weight:400;">(<?= count($mySubs) ?>)</span></h2>
  <?php if (!$mySubs): ?><p class="hint"><?= tt('tm.none_assigned') ?></p><?php else: ?>
  <div class="tm-list"><?php foreach ($mySubs as $ms): ?><a class="tm-chip" href="<?= url('subproject', ['id' => (int)$ms['id']]) ?>"><b><?= e(trim(($ms['project_code'] ?: '') . ' ' . $ms['code'])) ?></b> <?= e($ms['name']) ?><?= $ms['vessel'] ? ' <em>' . e($ms['vessel']) . '</em>' : '' ?></a><?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="dash-cols">
  <div>
    <div class="card card--flat">
      <h3 class="ed-h"><?= tt('dash.progress_title') ?> <span><?= tt('dash.stages') ?></span></h3>
      <?php if (!$activeSubs): ?><div class="empty"><?= tt('pr.empty') ?></div><?php else: ?>
        <?php foreach (array_slice($activeSubs, 0, 10) as $s): ?>
          <a class="segrow" href="<?= url('subproject', ['id' => $s['id']]) ?>">
            <span class="lbl"><b><?= e(($s['project_code'] ?: $s['project']) . ' · ' . ($s['code'] ?: '')) ?></b><small><?= e($s['name']) ?><?= $s['vessel'] && $staff ? ' · ' . e($s['vessel']) : '' ?></small></span>
            <span class="segs"><?php foreach ($stages as $slug => $d): $p = (int)$s[$d['col']]; ?><i class="<?= $p >= 100 ? 'done' : ($p > 0 ? 'act' : '') ?>" style="--p:<?= $p ?>%" title="<?= e(t($d['key'])) ?> <?= $p ?> %"><b><?= e(t('st.ab.' . $slug)) ?></b><em><?= $p ?>%</em></i><?php endforeach; ?></span>
            <span class="pc"><?= $ovr($s) ?> %</span>
          </a>
        <?php endforeach; ?>
        <a class="ed-link" href="<?= url('projects') ?>"><?= tt('dash.all_projects') ?></a>
      <?php endif; ?>
    </div>

    <div class="card card--flat">
      <h3 class="ed-h"><?= tt('dash.this_week') ?></h3>
      <?php if (!$week): ?><p class="hint" style="margin:0;"><?= tt('dash.nothing_week') ?></p><?php else: ?>
        <?php foreach ($week as $w): ?>
          <a class="evrow" href="<?= $w['url'] ?>"><i class="dot dot--<?= e($w['tone']) ?>"></i><span class="d"><?= e(fmt_date(substr($w['at'], 0, 10))) ?></span><b><?= e($w['ref']) ?></b><span class="tx"><?= e($w['text']) ?></span></a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="card card--flat">
      <h3 class="ed-h"><?= tt('dash.attention') ?></h3>
      <?php if (!$att): ?><p class="hint" style="margin:0;"><?= tt('dash.nothing_attention') ?></p><?php else: ?>
        <?php foreach ($att as $a): ?>
          <a class="attrow att--<?= e($a['tone']) ?>" href="<?= $a['url'] ?>"><span class="age"><?= e($ageLabel($a)) ?></span><span><b><?= e($a['ref']) ?></b> <?= e($a['text']) ?></span></a>
        <?php endforeach; ?>
        <a class="ed-link" href="<?= url('tickets') ?>"><?= tt('dash.action_centre') ?></a>
      <?php endif; ?>
    </div>

    <div class="card card--flat">
      <h3 class="ed-h"><?= tt('dash.fleet') ?></h3>
      <?php if (!$vessels): ?><div class="empty"><?= tt('v.empty') ?></div><?php else: foreach ($vessels as $v): $pv = $vesselProg[$v['id']] ?? []; $pct = $pv ? (int) round(array_sum($pv) / count($pv)) : null; ?>
        <a class="vrow" href="<?= url('vessel', ['id' => $v['id']]) ?>">
          <svg class="ring" viewBox="0 0 40 40" width="40" height="40" aria-hidden="true"><circle cx="20" cy="20" r="16" fill="none" stroke="var(--ring-bg)" stroke-width="5"/><?php if ($pct !== null): ?><circle cx="20" cy="20" r="16" fill="none" stroke="<?= $pct >= 100 ? 'var(--cp-green)' : 'var(--gold)' ?>" stroke-width="5" stroke-dasharray="<?= round($pct / 100 * 100.5, 1) ?> 100.5" stroke-linecap="round" transform="rotate(-90 20 20)"/><?php endif; ?></svg>
          <span class="tx"><b><?= e($v['name']) ?></b><small><?= $pct !== null ? $pct . ' % · ' : '' ?><?= (int)$v['n_active'] ?> <?= tt('nav.projects') ?><?= $staff && $v['customer_name'] ? ' · ' . e($v['customer_name']) : '' ?></small></span>
          <?= (int)$v['n_tickets'] ? '<span class="badge">' . (int)$v['n_tickets'] . '</span>' : '' ?>
        </a>
      <?php endforeach; endif; ?>
    </div>
  </div>
</div>
<?php layout_foot(); ?>

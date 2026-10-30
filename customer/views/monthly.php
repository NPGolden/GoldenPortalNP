<?php
/**
 * Monthly progress report — project level.                  GAS-CP-R3-001
 * Progress delta (snapshot at month start vs now/end), submittal and
 * test statistics, drawing-tracker summary, and the month's notes.
 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p');
$p = one("SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id
          LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ? AND {$scope}", array_merge([$pid], $args));
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }

$month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
$mStart = $month . '-01'; $mEnd = date('Y-m-t', strtotime($mStart));
$subs = all('SELECT s.*, vs.name AS system_name FROM subprojects s LEFT JOIN vessel_systems vs ON vs.id = s.system_id WHERE s.project_id = ? ORDER BY s.sort_order, s.code', [$pid]);

// snapshot closest BEFORE the month start = baseline; latest within month (or current) = now
$baseline = []; $latest = [];
foreach ($subs as $s) {
    $b = one('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? AND snap_date < ? ORDER BY snap_date DESC LIMIT 1', [$s['id'], $mStart]);
    $l = one('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? AND snap_date <= ? ORDER BY snap_date DESC LIMIT 1', [$s['id'], $mEnd]);
    $baseline[(int)$s['id']] = $b; $latest[(int)$s['id']] = $l ?: $s;   // no snapshot yet -> live values
}
$stat = function (string $table) use ($pid, $mStart, $mEnd): array {
    $r = one("SELECT COUNT(*) total,
                     SUM(submitted_at BETWEEN ? AND ?) sub_m,
                     SUM(decided_at BETWEEN ? AND ? AND status LIKE 'approved%') appr_m,
                     SUM(status IN ('submitted','resubmitted')) open_now
              FROM {$table} WHERE project_id = ?", [$mStart, $mEnd, $mStart, $mEnd, $pid]);
    return array_map('intval', $r ?: []);
};
$own = $stat('owner_approvals'); $cls = $stat('class_submittals');
$tests = all("SELECT t.kind, t.status, COUNT(*) n FROM test_reports t JOIN subprojects s ON s.id = t.subproject_id WHERE s.project_id = ? GROUP BY t.kind, t.status", [$pid]);
$dr = one("SELECT COUNT(*) total, SUM(status='received') received, SUM(status='pending') pending, SUM(received_at BETWEEN ? AND ?) recv_m FROM drawing_requests WHERE project_id = ?", [$mStart, $mEnd, $pid]);
$drPend = all("SELECT d.*, vs.name AS system_name, s.code AS sub_code FROM drawing_requests d LEFT JOIN vessel_systems vs ON vs.id = d.system_id LEFT JOIN subprojects s ON s.id = d.subproject_id WHERE d.project_id = ? AND d.status = 'pending' ORDER BY d.expected_at IS NULL, d.expected_at, d.kind DESC, d.seq_no", [$pid]);   // R36-003: every pending request
[$vis, ] = vis_sql('n.visibility');
$notes = all("SELECT n.*, cu.full_name, s.code, s.name AS sub_name FROM sp_notes n JOIN customer_users cu ON cu.id = n.author_id JOIN subprojects s ON s.id = n.subproject_id
              WHERE s.project_id = ? AND n.note_date BETWEEN ? AND ? AND {$vis} ORDER BY s.sort_order, n.note_date", [$pid, $mStart, $mEnd]);
$months = array_column(all("SELECT DISTINCT DATE_FORMAT(snap_date,'%Y-%m') m FROM sp_progress_snapshots sp JOIN subprojects s ON s.id = sp.subproject_id WHERE s.project_id = ? ORDER BY m DESC", [$pid]), 'm');
if (!in_array($month, $months, true)) array_unshift($months, $month);

layout_head(t('mo.title'), 'projects');
?>
<div class="no-print">
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?></p>
<div class="page-head">
  <div><h1><?= tt('mo.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($p['name']) ?> · <?= e($p['vessel'] ?: '') ?></p></div>
  <div class="actions">
    <form method="get" action="index.php" style="display:flex;gap:8px;align-items:end;">
      <input type="hidden" name="p" value="monthly"><input type="hidden" name="project" value="<?= $pid ?>">
      <div class="field" style="margin:0;"><label><?= tt('mo.month') ?></label>
        <select name="month" data-js-submit="1"><?php foreach ($months as $m): ?><option value="<?= e($m) ?>"<?= $m === $month ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
    </form>
    <button class="btn btn--gold" type="button" data-js-print="1"><?= tt('rp.print') ?></button>
  </div>
</div>
</div>

<div class="report">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
    <img src="../assets/golden-logo.png" alt="Golden Automation Systems" style="height:52px;">
    <div style="text-align:right;font-size:12px;color:#555;"><?= e($p['project_code'] ?: '') ?> · <?= e($month) ?></div>
  </div>
  <h1><?= tt('mo.title') ?> — <?= e($month) ?></h1>
  <p><strong><?= e($p['project_code'] ?: '') ?> <?= e($p['name']) ?></strong> · <?= e($p['vessel'] ?: '') ?> · <?= e($p['customer_name'] ?? '') ?> · <?= tt('rp.prepared') ?>: Golden Automation Systems</p>

  <h2><?= tt('mo.progress') ?></h2>
  <table>
    <thead><tr><th><?= tt('sp.code') ?></th><th><?= tt('sp.name') ?></th><?php foreach (stage_defs() as $d): ?><th><?= tt($d['key']) ?></th><?php endforeach; ?><th><?= tt('sp.overall') ?></th><th><?= tt('mo.delta') ?></th></tr></thead>
    <tbody><?php foreach ($subs as $s): $b = $baseline[(int)$s['id']]; $l = $latest[(int)$s['id']];
      $ob = $b ? overall_pct($b) : 0; $ol = overall_pct($l); ?>
      <tr><td><?= e($s['code'] ?: '—') ?></td><td><?= e($s['name']) ?></td>
        <?php foreach (stage_defs() as $d): $v = (int)$l[$d['col']]; $dv = $b && !pct_na($v) && !pct_na($b[$d['col']]) ? $v - (int)$b[$d['col']] : null; ?>
          <td><?= e(pct_txt($v)) ?><?= $dv !== null && $dv !== 0 ? ' <span style="color:var(--cp-green);font-size:11px;">(' . ($dv > 0 ? '+' : '') . $dv . ')</span>' : '' ?></td>
        <?php endforeach; ?>
        <td><strong><?= $ol ?>%</strong></td><td><?= $b ? (($ol - $ob) >= 0 ? '+' : '') . ($ol - $ob) . '%' : '—' ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <p class="cap"><?= tt('mo.table1') ?></p>

  <h2><?= tt('mo.submittals') ?></h2>
  <table>
    <thead><tr><th></th><th><?= tt('sub.total') ?></th><th><?= tt('mo.sub_m') ?></th><th><?= tt('mo.appr_m') ?></th><th><?= tt('sub.awaiting') ?></th></tr></thead>
    <tbody>
      <tr><td><strong><?= tt('own.title') ?></strong></td><td><?= $own['total'] ?? 0 ?></td><td><?= $own['sub_m'] ?? 0 ?></td><td><?= $own['appr_m'] ?? 0 ?></td><td><?= $own['open_now'] ?? 0 ?></td></tr>
      <tr><td><strong><?= tt('cls.title') ?></strong></td><td><?= $cls['total'] ?? 0 ?></td><td><?= $cls['sub_m'] ?? 0 ?></td><td><?= $cls['appr_m'] ?? 0 ?></td><td><?= $cls['open_now'] ?? 0 ?></td></tr>
    </tbody>
  </table>
  <p class="cap"><?= tt('mo.table2') ?></p>

  <h2><?= tt('dr.title') ?></h2>
  <p><?= e(t('mo.drawings', ['total' => (int)($dr['total'] ?? 0), 'received' => (int)($dr['received'] ?? 0), 'pending' => (int)($dr['pending'] ?? 0), 'month' => (int)($dr['recv_m'] ?? 0)])) ?></p>
  <?php if ($drPend): ?>
  <table><thead><tr><th>#</th><th><?= tt('dr.item') ?></th><th><?= tt('sys.one') ?></th><th><?= tt('dr.responsible') ?></th><th><?= tt('dr.sent') ?></th><th><?= tt('dr.expected') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
    <tbody><?php foreach ($drPend as $d): $late = $d['expected_at'] && $d['expected_at'] < $mEnd; ?><tr<?= $late ? ' class="is-late"' : '' ?>><td><?= (int)$d['seq_no'] ?></td><td><?= e($d['title']) ?><?= $d['sub_code'] ? ' <span class="hint">(' . e($d['sub_code']) . ')</span>' : '' ?></td><td><?= e($d['system_name'] ?: ($d['kind'] === 'principal' ? t('dr.principal') : '')) ?></td><td><?= e($d['responsible']) ?></td><td><?= e(fmt_date($d['request_sent'])) ?></td><td><?= $d['expected_at'] ? e(fmt_date($d['expected_at'])) : '—' ?></td><td><?= e($d['remarks'] ?: '') ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="cap"><?= tt('mo.table_drw') ?></p>
  <?php endif; ?>

  <h2><?= tt('tst.title') ?></h2>
  <?php if (!$tests): ?><p><em><?= tt('tst.empty') ?></em></p><?php else: ?>
  <table><thead><tr><th><?= tt('tst.kind') ?></th><th><?= tt('th.status') ?></th><th>#</th></tr></thead>
    <tbody><?php foreach ($tests as $t): ?><tr><td><?= e($t['kind']) ?></td><td><?= tt('tst.' . $t['status']) ?></td><td><?= (int)$t['n'] ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="cap"><?= tt('mo.table3') ?></p>
  <?php endif; ?>

  <h2><?= tt('mo.highlights') ?></h2>
  <?php if (!$notes): ?><p><em><?= tt('wk.nonotes') ?></em></p><?php endif; ?>
  <?php $last = null; foreach ($notes as $n): if ($last !== $n['subproject_id']): $last = $n['subproject_id']; ?>
    <h3 style="color:var(--cp-navy);"><?= e(($n['code'] ? $n['code'] . ' · ' : '') . $n['sub_name']) ?></h3>
  <?php endif; ?>
    <p><span class="when" style="font-family:var(--mono);font-size:12px;color:#666;"><?= e(fmt_date($n['note_date'])) ?></span> — <?= rt_html($n['body']) ?></p>
  <?php endforeach; ?>
</div>
<?php layout_foot(); ?>

<?php
/**
 * Manhours report — hours booked on timesheets, per discipline, per
 * sub-project (and the main project itself) and per engineer, always
 * split normal / overtime / holiday. Approved + invoiced by default.
 *   ?p=manhours[&project=ID][&from=..&to=..][&st[]=approved&st[]=invoiced][&export=csv]   GAS-CP-R11-001
 */
require __DIR__ . '/_layout.php';
$u = require_staff();
$pid  = (int)($_GET['project'] ?? 0);
$vid  = $pid ? 0 : (int)($_GET['vessel'] ?? 0);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : '';
$to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))   ? $_GET['to']   : '';
$st   = array_values(array_intersect((array)($_GET['st'] ?? ['approved', 'invoiced']), ts_statuses()));
if (!$st) $st = ['approved', 'invoiced'];
$projects = all("SELECT p.id, p.project_code, p.name, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id ORDER BY p.status = 'completed', p.project_code");
$proj = $pid ? one('SELECT p.*, v.name AS vessel, c.name AS customer_name FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.id = ?', [$pid]) : null;
$ves  = $vid ? one('SELECT v.*, c.name AS customer_name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ?', [$vid]) : null;
$vessels = all('SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 ORDER BY v.name');
$r = ts_manhours($pid ?: null, $st, $from ?: null, $to ?: null, $vid ?: null);
$f = fn($v) => ts_fmt((float)$v);

/* CSV export: the three tables one after the other */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="GAS-manhours-' . ($proj ? preg_replace('/[^A-Za-z0-9-]/', '', $proj['project_code'] ?: $pid) : ($ves ? preg_replace('/[^A-Za-z0-9-]+/', '-', $ves['name']) : 'all')) . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w'); fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Golden A/S — ' . t('mh.title'), $proj ? ($proj['project_code'] . ' ' . $proj['name']) : t('flt.any'), $from ?: '', $to ?: '', implode('+', $st)], ';');
    fputcsv($out, [], ';');
    foreach (['project' => t('mh.by_project'), 'discipline' => t('mh.by_discipline'), 'sub' => t('mh.by_sub'), 'engineer' => t('mh.by_engineer'), 'status' => t('th.status')] as $k => $title) {
        if ($k === 'project' && $pid) continue;
        fputcsv($out, [$title, t('ts.normal'), t('ts.overtime'), t('ts.holiday'), t('ts.total'), t('ts.sheets')], ';');
        foreach ($r[$k] as $row) {
            $label = $k === 'project' ? (($row['project_code'] ?: '') . ' ' . $row['project_name'] . ($row['vessel'] ? ' · ' . $row['vessel'] : ''))
                   : ($k === 'sub' ? (($row['project_code'] ?: $row['project_name']) . ' › ' . ($row['subproject_id'] ? trim($row['sub_code'] . ' ' . $row['sub_name']) : t('ts.main_project')))
                   : ($k === 'status' ? t('ts.st_' . $row['label']) : $row['label'] . ($k === 'engineer' ? ' (' . $row['discipline'] . ')' : '')));
            fputcsv($out, [$label, $f($row['normal']), $f($row['overtime']), $f($row['holiday']), $f($row['total']), (int)$row['sheets']], ';');
        }
        fputcsv($out, [t('ts.total'), $f($r['total']['normal']), $f($r['total']['overtime']), $f($r['total']['holiday']), $f($r['total']['total']), (int)$r['total']['sheets']], ';');
        fputcsv($out, [], ';');
    }
    exit;
}
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$keep = array_filter(['project' => $pid, 'vessel' => $vid, 'from' => $from, 'to' => $to]) + ['st' => $st];
layout_head(t('mh.title'), 'timesheets');
$table = function (array $rows, string $firstCol, callable $label, array $total) use ($f): string {
    if (!$rows) return '<div class="empty">' . tt('mh.none') . '</div>';
    $h = '<div class="table-scroll"><table class="mh"><thead><tr><th>' . e($firstCol) . '</th><th class="num">' . tt('ts.normal') . '</th><th class="num">' . tt('ts.overtime') . '</th><th class="num">' . tt('ts.holiday') . '</th><th class="num">' . tt('ts.total') . '</th><th class="num">' . tt('mh.share') . '</th><th class="num">' . tt('ts.sheets') . '</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $share = (float)$total['total'] > 0 ? round((float)$row['total'] / (float)$total['total'] * 100) : 0;
        $h .= '<tr><td>' . $label($row) . '</td><td class="num">' . $f($row['normal']) . '</td><td class="num ts-ot">' . $f($row['overtime']) . '</td><td class="num ts-hol">' . $f($row['holiday']) . '</td><td class="num"><b>' . $f($row['total']) . '</b></td>'
            . '<td class="num"><span class="share"><i style="--w:' . $share . '%"></i>' . $share . '%</span></td><td class="num">' . (int)$row['sheets'] . '</td></tr>';
    }
    $h .= '</tbody><tfoot><tr><td>' . tt('ts.total') . '</td><td class="num"><b>' . $f($total['normal']) . '</b></td><td class="num ts-ot"><b>' . $f($total['overtime']) . '</b></td><td class="num ts-hol"><b>' . $f($total['holiday']) . '</b></td><td class="num"><b>' . $f($total['total']) . '</b></td><td></td><td class="num">' . (int)$total['sheets'] . '</td></tr></tfoot></table></div>';
    return $h;
};
?>
<p class="eyebrow"><a href="<?= url('timesheets') ?>"><?= tt('ts.title') ?></a></p>
<div class="page-head">
  <div><h1><?= tt('mh.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= $proj ? e(($proj['project_code'] ?: '') . ' ' . $proj['name'] . ($proj['vessel'] ? ' · ' . $proj['vessel'] : '') . ($proj['customer_name'] ? ' · ' . $proj['customer_name'] : '')) : ($ves ? '⚓ ' . e($ves['name'] . ($ves['customer_name'] ? ' · ' . $ves['customer_name'] : '')) : tt('mh.lede')) ?></p></div>
  <div class="actions">
    <a class="btn btn--ghost" href="<?= url('report_ts', $keep + ['format' => 'pdf']) ?>" target="_blank"><?= tt('rp.ts_pdf') ?></a>
    <a class="btn btn--gold" href="<?= url('report_ts', $keep + ['format' => 'docx']) ?>"><?= tt('rp.ts_word') ?></a>
    <a class="btn btn--ghost" href="<?= url('manhours', $keep + ['export' => 'csv']) ?>">⤓ CSV</a>
  </div>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.5fr 1.1fr 0.9fr 0.9fr 1.4fr auto;">
    <input type="hidden" name="p" value="manhours">
    <div class="field"><label for="project"><?= tt('pr.one') ?></label>
      <select id="project" name="project"><option value="0"><?= tt('mh.all_projects') ?></option><?php foreach ($projects as $pr): ?><option value="<?= $pr['id'] ?>"<?= $sel($pr['id'], $pid) ?>><?= e(($pr['project_code'] ?: '') . ' ' . $pr['name'] . ($pr['vessel'] ? ' · ' . $pr['vessel'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="vessel"><?= tt('ts.vessel') ?></label>
      <select id="vessel" name="vessel"><option value="0"><?= tt('mh.all_vessels') ?></option><?php foreach ($vessels as $vs): ?><option value="<?= $vs['id'] ?>"<?= $sel($vs['id'], $vid) ?>><?= e($vs['name']) ?></option><?php endforeach; ?></select>
      <p class="hint"><?= tt('mh.vessel_hint') ?></p></div>
    <div class="field"><label for="from"><?= tt('ts.from') ?></label><input id="from" name="from" type="date" value="<?= e($from) ?>"></div>
    <div class="field"><label for="to"><?= tt('ts.to') ?></label><input id="to" name="to" type="date" value="<?= e($to) ?>"></div>
    <div class="field"><label><?= tt('mh.count_status') ?></label>
      <div class="chk-row"><?php foreach (['submitted', 'approved', 'invoiced'] as $s): ?><label class="chk"><input type="checkbox" name="st[]" value="<?= $s ?>"<?= in_array($s, $st, true) ? ' checked' : '' ?>> <?= e(t('ts.st_' . $s)) ?></label><?php endforeach; ?></div></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('manhours', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="kpi" style="margin-bottom:16px;">
  <div class="stat"><b><?= $f($r['total']['normal']) ?></b><?= tt('ts.normal') ?></div>
  <div class="stat gold"><b><?= $f($r['total']['overtime']) ?></b><?= tt('ts.overtime') ?></div>
  <div class="stat"><b class="ts-hol"><?= $f($r['total']['holiday']) ?></b><?= tt('ts.holiday') ?></div>
  <div class="stat green"><b><?= $f($r['total']['total']) ?></b><?= tt('ts.total') ?> · <?= (int)$r['total']['sheets'] ?> <?= tt('ts.sheets') ?></div>
</div>
<p class="hint" style="margin:-6px 0 14px;"><?= tt('mh.cost_note') ?></p>

<?php if ($r['branch'] ?? []): $bl = fn($id) => br_get((int)$id ?: null); ?>
<div class="card">
  <h2><?= tt('mh.by_branch') ?></h2>
  <p class="hint"><?= tt('mh.by_branch_hint') ?></p>
  <?= $table($r['branch'], t('br.one'), fn($row) => br_pill($bl($row['branch_id']), true) . ' <b>' . e($bl($row['branch_id'])['name']) . '</b><br><span class="hint">' . e(t('mh.home_branch')) . ': ' . e($bl($row['home_branch_id'])['code']) . '</span>', $r['total']) ?>
</div>
<?php endif; ?>
<?php if (!$pid): ?>
<div class="card">
  <h2><?= tt('mh.by_project') ?></h2>
  <?= $table($r['project'], t('pr.one'), fn($row) => '<a href="' . url('manhours', ['project' => $row['project_id']] + array_filter(['from' => $from, 'to' => $to]) + ['st' => $st]) . '"><b>' . e(($row['project_code'] ?: '') . ' ' . $row['project_name']) . '</b></a>' . ($row['vessel'] && !$vid ? '<br><span class="hint">⚓ ' . e($row['vessel']) . '</span>' : '') . ($row['customer_name'] ? '<br><span class="hint">' . e($row['customer_name']) . '</span>' : ''), $r['total']) ?>
</div>
<?php endif; ?>
<div class="card">
  <h2><?= tt('mh.by_discipline') ?></h2>
  <?= $table($r['discipline'], t('disc.one'), fn($row) => '<b>' . e($row['label']) . '</b>', $r['total']) ?>
  <?php if (function_exists('wc_has') && wc_has() && function_exists('fin_visible') && fin_visible()): $costRows = []; $costSum = 0.0; $anyRate = false;
    foreach ($r['discipline'] as $row) { $c = wc_cost((string)$row['label'], (float)$row['normal'], (float)$row['overtime'], (float)$row['holiday']); if ($c !== null) { $anyRate = true; $costSum += $c; } $costRows[] = [$row, $c]; }
    if ($anyRate): ?>
  <h3 class="ac-h"><?= tt('wc.cost_title') ?></h3>
  <div class="table-scroll"><table class="mh"><thead><tr><th><?= tt('disc.one') ?></th><th class="num"><?= tt('wc.rate_normal') ?></th><th class="num"><?= tt('wc.rate_overtime') ?></th><th class="num"><?= tt('wc.rate_holiday') ?></th><th class="num"><?= tt('wc.cost') ?></th></tr></thead><tbody>
    <?php foreach ($costRows as [$row, $c]): $rt = wc_rates()[$row['label']] ?? null; ?><tr><td><b><?= e($row['label']) ?></b></td><td class="num"><?= $rt && $rt['rate_normal'] !== null ? number_format((float)$rt['rate_normal'], 2, ',', '.') : '—' ?></td><td class="num"><?= $rt && $rt['rate_overtime'] !== null ? number_format((float)$rt['rate_overtime'], 2, ',', '.') : '—' ?></td><td class="num"><?= $rt && $rt['rate_holiday'] !== null ? number_format((float)$rt['rate_holiday'], 2, ',', '.') : '—' ?></td><td class="num"><b><?= $c !== null ? number_format($c, 2, ',', '.') . ' €' : '—' ?></b></td></tr><?php endforeach; ?>
  </tbody><tfoot><tr><td><?= tt('ts.total') ?></td><td></td><td></td><td></td><td class="num"><b><?= number_format($costSum, 2, ',', '.') ?> €</b></td></tr></tfoot></table></div>
  <p class="hint" style="margin-top:6px;"><?= tt('wc.cost_hint') ?></p>
  <?php endif; endif; ?>
</div>
<div class="card">
  <h2><?= tt('mh.by_sub') ?></h2>
  <?= $table($r['sub'], t('mh.project_sub'), fn($row) => ($pid ? '' : '<span class="hint">' . e($row['project_code'] ?: $row['project_name']) . ' › </span>')
        . ($row['subproject_id'] ? '<a href="' . url('subproject', ['id' => $row['subproject_id']]) . '"><b>' . e(trim($row['sub_code'] . ' ' . $row['sub_name'])) . '</b></a>' : '<b>' . tt('ts.main_project') . '</b>'), $r['total']) ?>
</div>
<div class="card">
  <h2><?= tt('mh.by_engineer') ?></h2>
  <?= $table($r['engineer'], t('ts.engineer'), fn($row) => '<b>' . e($row['label']) . '</b><br><span class="hint">' . e($row['discipline']) . '</span>', $r['total']) ?>
</div>
<div class="card">
  <h2><?= tt('mh.by_status') ?></h2>
  <?= $table($r['status'], t('th.status'), fn($row) => ts_status_pill($row['label']), $r['total']) ?>
</div>
<?php if (ar_has() && $pid && $proj) { $dd = ts_report_data($pid, null, $st, $from, $to); echo ar_issued_html($dd['doc_no'], $pid, ['kind' => 'timesheet', 'project' => $pid, 'from' => $from, 'to' => $to] + array_combine(array_map(fn($i) => "st[$i]", array_keys($st)), $st), url('manhours', $keep)); } ?>
<?php layout_foot(); ?>

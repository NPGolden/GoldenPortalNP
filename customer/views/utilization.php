<?php
/** HR › Utilization — hours on projects vs. without, per portal user and period; team table for a year.   GAS-CP-R83-001
 *  ?p=utilization[&user=ID][&year=YYYY | &from=&to=][&discipline=ID][&export=csv] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('ut_has') || !ut_has() || !ut_can()) { http_response_code(403); layout_head(t('ut.title'), 'utilization'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
[$from, $to, $year] = ut_period();
$people = ut_people(); $uid = (int)($_GET['user'] ?? 0); if ($uid && !ut_can_view($uid)) $uid = 0;
if (!$uid && count($people) === 1) $uid = (int)$people[0]['id'];
$did = isset($_GET['discipline']) && $_GET['discipline'] !== '' ? (int)$_GET['discipline'] : null;
$disc = all('SELECT * FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name');
$person = null; foreach ($people as $p) if ((int)$p['id'] === $uid) $person = $p;
$tech = isset($_GET['tech']) ? $_GET['tech'] === '1' : !isset($_GET['p_applied']); $techAvail = function_exists('hr_technical_user_ids') && hr_technical_user_ids() !== null;
$keep = ['user' => $uid ?: null, 'year' => $year ?: null, 'from' => $year ? null : $from, 'to' => $year ? null : $to, 'discipline' => $did, 'tech' => $tech ? 1 : 0, 'p_applied' => 1];
$h = fn(float $x) => bg_h_safe($x);
$R = $person ? ut_user($person, $from, $to) : null; $team = $person ? [] : ut_team($from, $to, $did, $tech && $techAvail);
usort($team, fn($a, $b) => ($b['tot']['project'] <=> $a['tot']['project']));
$years = range((int) date('Y'), max(2024, (int) date('Y') - 4));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="GAS-utilization-' . ($person ? preg_replace('/[^A-Za-z0-9-]+/', '-', $person['full_name']) : 'team') . '-' . str_replace('-', '', $from) . '-' . str_replace('-', '', $to) . '.csv"');
    $out = fopen('php://output', 'w'); fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Golden A/S — ' . t('ut.title'), $person ? $person['full_name'] : t('ut.team'), $from, $to], ';'); fputcsv($out, [], ';');
    $head = [t('ut.capacity'), t('ut.on_projects'), t('ut.util'), t('ut.internal'), t('ut.absent'), t('ut.idle'), t('ut.idle_pct'), t('ut.idle_days'), t('ut.overtime'), t('ut.missing_days')];
    $row = fn(array $T) => [$h($T['cap']), $h($T['project']), ut_pct($T) ?? '', $h($T['internal']), $h($T['absent']), $h($T['idle']), ut_idle_pct($T) ?? '', $T['idle_days'], $h($T['overtime']), $T['missing_days']];
    if ($R) {
        fputcsv($out, array_merge([t('ut.month')], $head), ';'); foreach ($R['months'] as $m => $T) fputcsv($out, array_merge([$m], $row($T)), ';'); fputcsv($out, array_merge([t('ts.total')], $row($R['tot'])), ';');
        fputcsv($out, [], ';'); fputcsv($out, [t('ut.project'), t('ut.hours'), t('ut.days')], ';'); foreach ($R['projects'] as $pr) fputcsv($out, [trim($pr['code'] . ' ' . $pr['name']), $h($pr['hours']), $pr['days']], ';');
    } else { fputcsv($out, array_merge([t('wv.by'), t('bg.discipline')], $head, [t('ut.n_projects')]), ';'); foreach ($team as $r) fputcsv($out, array_merge([$r['name'], $r['discipline']], $row($r['tot']), [$r['n_projects']]), ';'); }
    fclose($out); exit;
}
layout_head(t('ut.title') . ($person ? ' · ' . $person['full_name'] : ''), 'utilization');
?>
<div class="page-head"><div><h1><?= tt('ut.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('ut.lede') ?></p></div>
  <div class="actions"><a class="btn btn--ghost btn--sm" href="<?= url('utilization', $keep + ['export' => 'csv']) ?>">⤓ CSV</a> <button class="btn btn--ghost btn--sm" type="button" onclick="window.print()">🖨 <?= tt('btn.print') ?></button></div></div>
<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr .8fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="utilization">
    <div class="field"><label><?= tt('ut.person') ?></label><select name="user" data-nosearch><?php if (count($people) > 1): ?><option value=""><?= tt('ut.team') ?></option><?php endif; ?><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"<?= $uid === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?><?= $p['discipline'] ? ' · ' . e($p['discipline']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('bg.discipline') ?></label><select name="discipline" data-nosearch><option value=""><?= tt('wp.all_disciplines') ?></option><?php foreach ($disc as $d): ?><option value="<?= $d['id'] ?>"<?= $did === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ut.year') ?></label><select name="year" data-nosearch><option value=""><?= tt('ut.custom') ?></option><?php foreach ($years as $y): ?><option value="<?= $y ?>"<?= $year === $y ? ' selected' : '' ?>><?= $y ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ts.from') ?></label><input type="date" name="from" value="<?= $year ? '' : e($from) ?>"></div>
    <div class="field"><label><?= tt('ts.to') ?></label><input type="date" name="to" value="<?= $year ? '' : e($to) ?>"></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
    <input type="hidden" name="p_applied" value="1">
    <?php if ($techAvail): ?><label class="hint" style="grid-column: 1 / -1; display:flex; align-items:center; gap:6px;"><input type="checkbox" name="tech" value="1"<?= $tech ? ' checked' : '' ?>> <?= tt('ut.tech_only') ?></label><?php endif; ?>
  </form>
</div>
<p class="hint" style="margin:6px 0 10px;"><?= tt('ut.period') ?> <b><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?></b> · <?= tt('ut.how') ?></p>

<?php if ($R): $T = $R['tot']; $pct = ut_pct($T); $ip = ut_idle_pct($T); ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= e($h($T['cap'])) ?> h</b><?= tt('ut.capacity') ?><span class="s"><?= e(t('ut.workdays', ['n' => $T['days']])) ?></span></div>
  <div class="stat <?= ut_tone($pct) === 'green' ? 'ok' : (ut_tone($pct) === 'red' ? 'red' : 'gold') ?>"><b><?= $pct === null ? '—' : $pct . ' %' ?></b><?= tt('ut.util') ?><span class="s"><?= e($h($T['project'])) ?> h <?= tt('ut.on_projects_l') ?><?= $T['pending'] > 0 ? ' · ' . e($h($T['pending'])) . ' h ' . tt('ut.pending') : '' ?></span></div>
  <div class="stat<?= $T['idle'] > 0 ? ' red' : ' ok' ?>"><b><?= e($h($T['idle'])) ?> h</b><?= tt('ut.idle') ?><span class="s"><?= $ip === null ? '' : $ip . ' % · ' ?><?= e(t('ut.idle_days_n', ['n' => $T['idle_days']])) ?></span></div>
  <div class="stat"><b><?= e($h($T['internal'])) ?> + <?= e($h($T['absent'])) ?> h</b><?= tt('ut.internal') ?> + <?= tt('ut.absent') ?><span class="s"><?= e($h($T['overtime'])) ?> h <?= tt('ut.overtime_l') ?><?= $T['missing_days'] ? ' · ' . e(t('ut.missing_n', ['n' => $T['missing_days']])) : '' ?></span></div>
</div>

<div class="card">
  <div class="card-head"><h2><?= tt('ut.by_month') ?></h2><span class="hint"><span class="ut-key ut-p"></span> <?= tt('ut.on_projects') ?> <span class="ut-key ut-i"></span> <?= tt('ut.internal') ?> <span class="ut-key ut-a"></span> <?= tt('ut.absent') ?> <span class="ut-key ut-x"></span> <?= tt('ut.idle') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ut.month') ?></th><th style="min-width:220px;"></th><th class="num"><?= tt('ut.capacity') ?></th><th class="num"><?= tt('ut.on_projects') ?></th><th class="num"><?= tt('ut.util') ?></th><th class="num"><?= tt('ut.internal') ?></th><th class="num"><?= tt('ut.absent') ?></th><th class="num"><?= tt('ut.idle') ?></th><th class="num"><?= tt('ut.idle_days') ?></th><th class="num"><?= tt('ut.overtime') ?></th><th class="num"><?= tt('ut.missing_days') ?></th></tr></thead><tbody>
  <?php foreach ($R['months'] as $m => $M): $mp = ut_pct($M); $c = max(1.0, $M['cap']); $w = fn(float $x) => (int) round(min(100, $x / $c * 100)); ?>
    <tr><td style="white-space:nowrap;"><b><?= e(date('M Y', strtotime($m . '-01'))) ?></b></td>
      <td><div class="ut-bar"><span class="ut-p" style="width:<?= $w(min($M['project'], $M['cap'] - $M['absent'] - $M['internal'])) ?>%"></span><span class="ut-i" style="width:<?= $w($M['internal']) ?>%"></span><span class="ut-a" style="width:<?= $w($M['absent']) ?>%"></span><span class="ut-x" style="width:<?= $w($M['idle']) ?>%"></span></div></td>
      <td class="num"><?= e($h($M['cap'])) ?></td><td class="num"><b><?= e($h($M['project'])) ?></b></td><td class="num"><b class="bg-<?= ut_tone($mp) ?>"><?= $mp === null ? '—' : $mp . ' %' ?></b></td><td class="num"><?= e($h($M['internal'])) ?></td><td class="num"><?= e($h($M['absent'])) ?></td><td class="num"><?= $M['idle'] > 0 ? '<b class="bg-red">' . e($h($M['idle'])) . '</b>' : '0' ?></td><td class="num"><?= $M['idle_days'] ?></td><td class="num"><?= e($h($M['overtime'])) ?></td><td class="num"><?= $M['missing_days'] ?: '' ?></td></tr>
  <?php endforeach; ?>
    <tr class="tot"><td><b><?= tt('ts.total') ?></b></td><td></td><td class="num"><b><?= e($h($T['cap'])) ?></b></td><td class="num"><b><?= e($h($T['project'])) ?></b></td><td class="num"><b class="bg-<?= ut_tone($pct) ?>"><?= $pct === null ? '—' : $pct . ' %' ?></b></td><td class="num"><b><?= e($h($T['internal'])) ?></b></td><td class="num"><b><?= e($h($T['absent'])) ?></b></td><td class="num"><b><?= e($h($T['idle'])) ?></b></td><td class="num"><b><?= $T['idle_days'] ?></b></td><td class="num"><b><?= e($h($T['overtime'])) ?></b></td><td class="num"><b><?= $T['missing_days'] ?></b></td></tr>
  </tbody></table></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <div class="card-head"><h2><?= tt('ut.by_project') ?> <span class="count"><?= count($R['projects']) ?></span></h2></div>
    <?php if (!$R['projects']): ?><p class="hint"><?= tt('ut.no_projects') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('ut.project') ?></th><th class="num"><?= tt('ut.hours') ?></th><th class="num">%</th><th class="num"><?= tt('ut.days') ?></th></tr></thead><tbody>
    <?php foreach ($R['projects'] as $pr): ?><tr><td><a href="<?= url('projects', ['open' => $pr['id']]) ?>"><b><?= e($pr['code']) ?></b></a> <?= e($pr['name']) ?></td><td class="num"><?= e($h($pr['hours'])) ?></td><td class="num"><?= $T['project'] > 0 ? (int) round($pr['hours'] / $T['project'] * 100) : 0 ?> %</td><td class="num"><?= $pr['days'] ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= tt('ut.breakdown') ?></h2></div>
    <?php if ($R['activities']): ?><p class="hint" style="margin:0 0 4px;"><b><?= tt('ut.activities') ?></b></p><table class="tbl"><tbody><?php foreach ($R['activities'] as $k => $v): ?><tr><td><?= $k === '-' ? tt('ut.no_activity') : tt('eng.a_' . $k) ?></td><td class="num"><?= e($h($v)) ?> h</td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php if ($R['internal']): ?><p class="hint" style="margin:10px 0 4px;"><b><?= tt('ut.internal') ?></b></p><table class="tbl"><tbody><?php foreach ($R['internal'] as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= e($h($v)) ?> h</td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php if ($R['absent']): ?><p class="hint" style="margin:10px 0 4px;"><b><?= tt('ut.absent') ?></b></p><table class="tbl"><tbody><?php foreach ($R['absent'] as $k => $v): ?><tr><td><?= e($k) ?></td><td class="num"><?= e($h($v)) ?> h</td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php if (!$R['activities'] && !$R['internal'] && !$R['absent']): ?><p class="hint"><?= tt('ut.nothing') ?></p><?php endif; ?>
    <p class="hint" style="margin-top:10px;"><a href="<?= url('timesheets', ['user' => $uid]) ?>"><?= tt('nav.timesheets') ?> ›</a> · <a href="<?= url('planning', ['week' => $from]) ?>#u<?= $uid ?>"><?= tt('pl.title') ?> ›</a><?php if (function_exists('hr_person_of_user') && ($hp = hr_person_of_user($uid)) && (ut_all() || (function_exists('hr_is_manager') && hr_is_manager()))): ?> · <a href="<?= url('hr_eval', ['pid' => $hp['id'], 'year' => $year ?: (int) substr($to, 0, 4)]) ?>"><?= tt('hr.evaluations') ?> ›</a><?php endif; ?></p>
  </div>
</div>

<?php else: $G = ['cap' => 0.0, 'project' => 0.0, 'internal' => 0.0, 'absent' => 0.0, 'idle' => 0.0]; foreach ($team as $r) foreach ($G as $k => $v) $G[$k] += $r['tot'][$k]; $gp = ut_pct($G + ['overtime' => 0]); ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= count($team) ?></b><?= tt('ut.people') ?><span class="s"><?= e($h($G['cap'])) ?> h <?= tt('ut.capacity_l') ?></span></div>
  <div class="stat <?= ut_tone($gp) === 'green' ? 'ok' : (ut_tone($gp) === 'red' ? 'red' : 'gold') ?>"><b><?= $gp === null ? '—' : $gp . ' %' ?></b><?= tt('ut.util') ?><span class="s"><?= e($h($G['project'])) ?> h <?= tt('ut.on_projects_l') ?></span></div>
  <div class="stat<?= $G['idle'] > 0 ? ' red' : ' ok' ?>"><b><?= e($h($G['idle'])) ?> h</b><?= tt('ut.idle') ?><span class="s"><?= $G['cap'] > 0 ? (int) round($G['idle'] / $G['cap'] * 100) . ' %' : '' ?></span></div>
  <div class="stat"><b><?= e($h($G['internal'])) ?> + <?= e($h($G['absent'])) ?> h</b><?= tt('ut.internal') ?> + <?= tt('ut.absent') ?></div>
</div>
<div class="card">
  <div class="card-head"><h2><?= tt('ut.team') ?> <span class="count"><?= count($team) ?></span></h2><span class="hint"><?= tt('ut.team_hint') ?></span></div>
  <?php if (!$team): ?><p class="hint"><?= tt('ut.no_people') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('wv.by') ?></th><th style="min-width:160px;"></th><th class="num"><?= tt('ut.capacity') ?></th><th class="num"><?= tt('ut.on_projects') ?></th><th class="num"><?= tt('ut.util') ?></th><th class="num"><?= tt('ut.internal') ?></th><th class="num"><?= tt('ut.absent') ?></th><th class="num"><?= tt('ut.idle') ?></th><th class="num"><?= tt('ut.idle_days') ?></th><th class="num"><?= tt('ut.overtime') ?></th><th class="num"><?= tt('ut.missing_days') ?></th><th class="num"><?= tt('ut.n_projects') ?></th></tr></thead><tbody>
  <?php foreach ($team as $r): $M = $r['tot']; $mp = ut_pct($M); $c = max(1.0, $M['cap']); $w = fn(float $x) => (int) round(min(100, $x / $c * 100)); ?>
    <tr><td><a href="<?= url('utilization', $keep + ['user' => $r['id']]) ?>"><b><?= e($r['name']) ?></b></a><br><span class="hint"><?= e($r['discipline']) ?></span></td>
      <td><div class="ut-bar"><span class="ut-p" style="width:<?= $w(min($M['project'], $M['cap'] - $M['absent'] - $M['internal'])) ?>%"></span><span class="ut-i" style="width:<?= $w($M['internal']) ?>%"></span><span class="ut-a" style="width:<?= $w($M['absent']) ?>%"></span><span class="ut-x" style="width:<?= $w($M['idle']) ?>%"></span></div></td>
      <td class="num"><?= e($h($M['cap'])) ?></td><td class="num"><b><?= e($h($M['project'])) ?></b></td><td class="num"><b class="bg-<?= ut_tone($mp) ?>"><?= $mp === null ? '—' : $mp . ' %' ?></b></td><td class="num"><?= e($h($M['internal'])) ?></td><td class="num"><?= e($h($M['absent'])) ?></td><td class="num"><?= $M['idle'] > 0 ? '<b class="bg-red">' . e($h($M['idle'])) . '</b>' : '0' ?></td><td class="num"><?= $M['idle_days'] ?></td><td class="num"><?= e($h($M['overtime'])) ?></td><td class="num"><?= $M['missing_days'] ?: '' ?></td><td class="num"><?= $r['n_projects'] ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

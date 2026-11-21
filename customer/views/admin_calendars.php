<?php
/** Settings › Working hours — calendars per country: working days and hours, national holidays (standard list, Excel import, manual).   GAS-CP-R23-001
 *   ?p=admin_calendars[&cal=GR][&year=2026] */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = wc_has();
$cals = $ok ? wc_calendars() : [];
$cal = (string)($_GET['cal'] ?? ''); if (!isset($cals[$cal])) $cal = (string) array_key_first($cals);
$year = (int)($_GET['year'] ?? date('Y')); if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$hours = $cal ? wc_hours($cal) : []; $hol = $cal ? wc_holidays($cal, $year) : [];
$wd = td_weekdays(); $keep = ['cal' => $cal, 'year' => $year];
$nUsers = $ok ? array_column(all("SELECT COALESCE(work_calendar, 'GR') c, COUNT(*) n FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 GROUP BY c"), 'n', 'c') : [];
$res = $_SESSION['cp_import_result'] ?? null; if ($res && ($res['kind'] ?? '') === 'holidays') unset($_SESSION['cp_import_result']); else $res = null;
$asg = $ok && wc_assign_has(); $brs = function_exists('br_all') ? br_all(true) : []; $brName = []; foreach ($brs as $b) $brName[(int)$b['id']] = (string)$b['code'];
layout_head(t('wc.title'), 'admin_calendars');
?>
<?php settings_tabs('admin_calendars'); ?>
<h2 class="settings-h"><?= tt('wc.title') ?></h2>
<p class="lede"><?= tt('wc.lede') ?></p>
<?php if (!empty($asg)): ?><p class="hint"><?= tt('wc.lede2') ?> <a href="#assign"><?= tt('wc.assign') ?> ↓</a></p><?php endif; ?>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('wc.need_db') ?></div><?php else: ?>

<nav class="tabs" aria-label="<?= tt('wc.calendars') ?>">
  <?php foreach ($cals as $c => $row): ?><a class="<?= $cal === $c ? 'is-on' : '' ?>" href="<?= url('admin_calendars', ['cal' => $c, 'year' => $year]) ?>"><?= e($row['code']) ?> · <?= e($row['name']) ?><?= !empty($row['branch_id']) && isset($brName[(int)$row['branch_id']]) ? ' <span class="hint">(' . e($brName[(int)$row['branch_id']]) . ')</span>' : '' ?><?= isset($nUsers[$c]) ? ' <span class="n">' . (int)$nUsers[$c] . '</span>' : '' ?></a><?php endforeach; ?>
  <a class="<?= isset($_GET['new']) ? 'is-on' : '' ?>" href="<?= url('admin_calendars', $keep + ['new' => 1]) ?>#newcal">+ <?= tt('wc.add_cal') ?></a>
</nav>

<?php if (isset($_GET['new'])): ?>
<div class="card" id="newcal">
  <h2><?= tt('wc.add_cal') ?></h2>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" class="form-grid" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="wc_cal_save">
    <div class="field"><label><?= tt('wc.code') ?></label><input name="code" required maxlength="8" placeholder="GRSITE" style="text-transform:uppercase;"></div>
    <div class="field"><label><?= tt('wc.name') ?></label><input name="name" required maxlength="80" placeholder="Greece — site teams 07:00–15:30"></div>
    <?php if ($asg): ?>
    <div class="field"><label><?= tt('wc.hol_from') ?></label><select name="holidays_from"><option value=""><?= tt('wc.hol_own') ?></option><?php foreach ($cals as $c => $row): if (!empty($row['holidays_from'])) continue; ?><option value="<?= e($c) ?>"><?= e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('wc.hours_from') ?></label><select name="hours_from"><option value="">08:30–17:00</option><?php foreach ($cals as $c => $row): ?><option value="<?= e($c) ?>"><?= e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></div>
    <?php if ($brs): ?><div class="field"><label><?= tt('wc.branch') ?></label><select name="branch_id"><option value="0">—</option><?php foreach ($brs as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['code'] . ' · ' . $b['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label><?= tt('wc.note') ?></label><input name="note" maxlength="160"></div>
    <?php endif; ?>
    <div class="field"><label><?= tt('wc.country') ?></label><input name="country" maxlength="2" placeholder="GR" style="text-transform:uppercase;"></div>
    <div class="btn-row"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <p class="hint" style="margin:8px 0 0;"><?= tt('wc.add_cal_hint') ?></p>
</div>
<?php endif; ?>

<div class="cols wc-cols">
<div class="card">
  <h2><?= tt('wc.hours_title', ['c' => $cals[$cal]['name']]) ?></h2>
  <p class="hint"><?= tt('wc.hours_hint') ?></p>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="wc_hours_save"><input type="hidden" name="cal" value="<?= e($cal) ?>">
    <div class="table-scroll"><table class="sys-table wc-table">
      <thead><tr><th><?= tt('wc.day') ?></th><th><?= tt('wc.workday') ?></th><th><?= tt('wc.start') ?></th><th><?= tt('wc.end') ?></th><th><?= tt('wc.break') ?></th><th class="num"><?= tt('wc.normal') ?></th><th><?= tt('wc.off_as') ?></th></tr></thead>
      <tbody><?php for ($d = 1; $d <= 7; $d++): $r = $hours[$d] ?? ['is_workday' => $d <= 5, 'start_time' => '08:30', 'end_time' => '17:00', 'break_min' => 30, 'off_type' => $d === 7 ? 'holiday' : 'overtime', 'normal_hours' => 8]; ?>
        <tr class="<?= $r['is_workday'] ? '' : 'is-off' ?>">
          <td><b><?= e($wd[$d]) ?></b></td>
          <td style="text-align:center;"><input type="checkbox" name="wd[<?= $d ?>]" value="1" aria-label="<?= tt('wc.workday') ?>"<?= $r['is_workday'] ? ' checked' : '' ?> style="width:18px;height:18px;accent-color:var(--cp-gold);"></td>
          <td><input type="time" name="start[<?= $d ?>]" value="<?= e(substr((string)($r['start_time'] ?? '08:30'), 0, 5) ?: '08:30') ?>"></td>
          <td><input type="time" name="end[<?= $d ?>]" value="<?= e(substr((string)($r['end_time'] ?? '17:00'), 0, 5) ?: '17:00') ?>"></td>
          <td><input type="number" name="break[<?= $d ?>]" min="0" max="600" step="5" value="<?= (int)($r['break_min'] ?? 0) ?>" style="width:80px;"> <span class="hint">min</span></td>
          <td class="num"><?= $r['is_workday'] ? e(ts_fmt((float)$r['normal_hours'])) . ' h' : '—' ?></td>
          <td><select name="off[<?= $d ?>]"><option value="overtime"<?= ($r['off_type'] ?? '') === 'overtime' ? ' selected' : '' ?>><?= tt('ts.overtime') ?></option><option value="holiday"<?= ($r['off_type'] ?? '') === 'holiday' ? ' selected' : '' ?>><?= tt('ts.holiday') ?></option></select></td>
        </tr>
      <?php endfor; ?></tbody>
    </table></div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <p class="hint" style="margin-top:10px;"><?= tt('wc.rules') ?></p>
</div>

<div class="card">
  <?php $hfrom = $asg ? wc_holiday_code($cal) : $cal; if ($hfrom !== $cal): $hol = wc_holidays($hfrom, $year); ?><div class="flash flash--warn" style="margin-bottom:10px;"><?= tt('wc.hol_from_note', ['c' => $cals[$hfrom]['code'] . ' · ' . $cals[$hfrom]['name']]) ?> <a href="<?= url('admin_calendars', ['cal' => $hfrom, 'year' => $year]) ?>"><?= tt('wc.hol_open') ?></a></div><?php endif; ?>
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;"><?= tt('wc.holidays_title', ['c' => $cals[$cal]['name'], 'y' => $year]) ?></h2>
    <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('admin_calendars', ['cal' => $cal, 'year' => $year - 1]) ?>">‹ <?= $year - 1 ?></a><a class="btn btn--ghost btn--sm" href="<?= url('admin_calendars', ['cal' => $cal, 'year' => $year + 1]) ?>"><?= $year + 1 ?> ›</a></div></div>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" style="margin-bottom:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wc_generate"><input type="hidden" name="cal" value="<?= e($cal) ?>"><input type="hidden" name="year" value="<?= $year ?>">
    <button class="btn btn--ghost btn--sm" type="submit">✦ <?= e(t('wc.generate', ['c' => $cals[$cal]['country'], 'y' => $year])) ?></button> <span class="hint"><?= tt('wc.generate_hint') ?></span></form>
  <?php if (!$hol): ?><p class="hint"><?= tt('wc.no_holidays') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl--compact">
    <thead><tr><th><?= tt('ts.date') ?></th><th><?= tt('wc.day') ?></th><th><?= tt('wc.name') ?></th><th><?= tt('wc.source') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($hol as $h): ?>
      <tr><td class="date"><?= e(fmt_date($h['hdate'])) ?></td><td><?= e($wd[(int) date('N', strtotime($h['hdate']))]) ?></td><td><?= e($h['name']) ?></td><td><?= pill($h['source'] === 'standard' ? 'navy' : ($h['source'] === 'import' ? 'blue' : 'gold'), t('wc.src_' . $h['source'])) ?></td>
        <td class="row-actions"><form method="post" action="<?= url('admin_calendars', $keep) ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="wc_holiday_delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td></tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <h3 class="ac-h"><?= tt('wc.add_holiday') ?></h3>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" class="fltbar" style="grid-template-columns: 160px 2fr auto;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="wc_holiday_add"><input type="hidden" name="cal" value="<?= e($cal) ?>">
    <div class="field"><label><?= tt('ts.date') ?></label><input type="date" name="date" required value="<?= $year ?>-01-01"></div>
    <div class="field"><label><?= tt('wc.name') ?></label><input name="name" required maxlength="120" placeholder="<?= tt('wc.name_ph') ?>"></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
  <h3 class="ac-h"><?= tt('wc.import') ?></h3>
  <p class="hint"><?= tt('wc.import_hint') ?> <a href="<?= url('import', ['tpl' => 'holidays']) ?>">⤓ <?= tt('im.template') ?></a></p>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" enctype="multipart/form-data" class="fltbar" style="grid-template-columns: 2fr auto auto;" data-lock data-busy="<?= tt('im.running') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="wc_import">
    <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
    <label class="chk" style="padding-bottom:10px;"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('im.run') ?></button></div>
  </form>
  <?php if ($res): ?><div class="flash <?= $res['dry'] ? 'flash--warn' : 'flash--good' ?>" style="margin-top:10px;"><?= e(t('wc.im_result', ['n' => $res['rows'], 'a' => $res['added'], 'u' => $res['updated'], 's' => count($res['skipped'])])) ?><?= $res['dry'] ? ' · ' . tt('im.dry_note') : '' ?>
    <?php if ($res['skipped']): ?><ul class="hint" style="margin:6px 0 0 18px;"><?php foreach (array_slice($res['skipped'], 0, 20) as [$ln, $why]): ?><li><?= e(t('im.line', ['n' => $ln])) ?>: <?= e($why) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
</div>
</div>
<?php if ($asg): $deps = wc_departments(); $ppl = wc_people(); $opt = function (?string $cur) use ($cals) { $o = '<option value="">' . tt('wc.inherit') . '</option>'; foreach ($cals as $c => $row) $o .= '<option value="' . e($c) . '"' . ((string)$cur === (string)$c ? ' selected' : '') . '>' . e($row['code'] . ' · ' . $row['name']) . '</option>'; return $o; }; ?>
<div class="card" id="assign">
  <h2>👥 <?= tt('wc.assign') ?></h2>
  <p class="hint"><?= tt('wc.assign_lede') ?></p>
  <form method="post" action="<?= url('admin_calendars', $keep) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wc_assign_save"><input type="hidden" name="cal" value="<?= e($cal) ?>">
  <?php if ($deps): ?><h3 class="ac-h"><?= tt('wc.dept') ?></h3>
  <div class="table-scroll"><table class="sys-table"><thead><tr><th><?= tt('wc.dept') ?></th><th><?= tt('wc.people_n') ?></th><th><?= tt('wc.one') ?></th><th><?= tt('wc.dept_apply') ?></th></tr></thead><tbody>
  <?php foreach ($deps as $d): ?><tr><td><b><?= e($d['name']) ?></b></td><td><?= (int)$d['n'] ?></td><td><select name="dept[<?= (int)$d['id'] ?>]" aria-label="<?= e($d['name']) ?>"><?= $opt($d['work_calendar']) ?></select></td><td style="text-align:center;"><input type="checkbox" name="dept_apply[<?= (int)$d['id'] ?>]" value="1" aria-label="<?= tt('wc.dept_apply') ?>" style="width:18px;height:18px;"></td></tr><?php endforeach; ?></tbody></table></div>
  <p class="hint"><?= tt('wc.dept_apply_hint') ?></p><?php endif; ?>
  <h3 class="ac-h"><?= tt('wc.people') ?></h3>
  <div class="table-scroll"><table class="sys-table"><thead><tr><th><?= tt('wc.person') ?></th><th><?= tt('wc.dept') ?></th><th><?= tt('wc.person_cal') ?></th><th><?= tt('wc.follows') ?></th></tr></thead><tbody>
  <?php foreach ($ppl as $x): $ef = $x['eff']; ?><tr><td><?= e($x['full_name']) ?></td><td class="hint"><?= e((string)($x['dept'] ?? '—')) ?></td><td><select name="person[<?= (int)$x['id'] ?>]" aria-label="<?= e($x['full_name']) ?>"><?= $opt(isset($cals[(string)$x['work_calendar']]) ? (string)$x['work_calendar'] : null) ?></select></td><td><b><?= e($ef['code']) ?></b> <span class="hint">· <?= tt('wc.src_' . $ef['src']) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
  <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php endif; ?>
<style>.sys-table td input, .sys-table td select { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); } .wc-table tr.is-off td { opacity:.75; } .wc-table td input[type=number] { width:80px; } .wc-cols { grid-template-columns: 1fr; } .wc-table th, .wc-table td { padding: 6px 6px; } .wc-table td input[type=time] { padding: 6px 6px; } .wc-table td input[type=number] { width: 64px; }</style>
<?php endif; ?>
<?php layout_foot(); ?>

<?php
/** Work › Planning board — engineers × days.                                   GAS-CP-R26-001
 *   ?p=planning[&m=YYYY-MM][&disc=<name>][&vessel=ID][&me=1][&edit=<user>:<date>] */
require __DIR__ . '/_layout.php';
$u = require_staff();
$ok = pl_has(); $canEdit = $ok && pl_can_edit();
$m = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] : date('Y-m');
$from = $m . '-01'; $to = date('Y-m-t', strtotime($from)); $nd = (int) date('t', strtotime($from));
$prev = date('Y-m', strtotime($from . ' -1 month')); $next = date('Y-m', strtotime($from . ' +1 month'));
$fDisc = (string)($_GET['disc'] ?? ''); $fVessel = (int)($_GET['vessel'] ?? 0); $me = !empty($_GET['me']) || !$canEdit;
$keep = array_filter(['m' => $m, 'disc' => $fDisc, 'vessel' => $fVessel, 'me' => $me && $canEdit ? 1 : 0]);
$people = $ok ? pl_people() : []; if ($me) $people = array_values(array_filter($people, fn($p) => (int)$p['id'] === (int)$u['id']));
if ($fDisc !== '') $people = array_values(array_filter($people, fn($p) => $p['discipline'] === ($fDisc === '__none' ? '' : $fDisc)));
$grid = $ok ? pl_grid($from, $to) : [];
if ($fVessel) { $vn = (string) scalar('SELECT name FROM vessels WHERE id = ?', [$fVessel]); $people = array_values(array_filter($people, function ($p) use ($grid, $vn) { foreach ($grid[(int)$p['id']] ?? [] as $es) foreach ($es as $e) if (($e['vessel'] ?? '') === $vn) return true; return false; })); }
$discs = array_values(array_unique(array_map(fn($p) => $p['discipline'], $ok ? pl_people() : [])));
$vesselOpts = all("SELECT DISTINCT v.id, v.name FROM vessels v JOIN projects p ON p.vessel_id = v.id WHERE p.status = 'active' ORDER BY v.name");
$subOpts = $canEdit ? all("SELECT s.id, s.code, s.name, p.id AS pid, p.project_code, v.name AS vessel FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.status = 'active' AND s.status <> 'completed' ORDER BY v.name, p.project_code, s.sort_order, s.code") : [];
$projOpts = $canEdit ? all("SELECT p.id, p.project_code, p.name, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.status = 'active' ORDER BY v.name, p.project_code") : [];
$cars = pl_cars(); $kinds = pl_kinds(); $wd = td_weekdays(); $today = date('Y-m-d');
$edit = preg_match('/^(\d+):(\d{4}-\d{2}-\d{2})$/', (string)($_GET['edit'] ?? ''), $em) ? ['user' => (int)$em[1], 'date' => $em[2]] : null;
$res = $_SESSION['cp_import_result'] ?? null; if ($res && ($res['kind'] ?? '') === 'planning') unset($_SESSION['cp_import_result']); else $res = null;
// day headers: weekend / holiday per the default calendar
$cal = function_exists('wc_has') && wc_has() ? 'GR' : null;
$dayInfo = []; for ($i = 1; $i <= $nd; $i++) { $d = sprintf('%s-%02d', $m, $i); $dow = (int) date('N', strtotime($d)); $hol = $cal ? wc_day($cal, $d) : ['workday' => $dow < 6, 'holiday' => null]; $dayInfo[$d] = ['n' => $i, 'dow' => $dow, 'off' => !$hol['workday'], 'hol' => $hol['holiday']['name'] ?? ($hol['holiday'] ?? null), 'week' => (int) date('W', strtotime($d))]; }
$title = t('pl.title') . ' · ' . date('F Y', strtotime($from));
layout_head($title, 'planning');
?>
<div class="page-head">
  <div><h1><?= tt('pl.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= $me ? tt('pl.lede_me') : tt('pl.lede') ?></p></div>
  <div class="btn-row">
    <?php if ($canEdit): ?><a class="btn btn--ghost" href="<?= url('planning', array_diff_key($keep, ['me' => 1]) + ($me ? [] : ['me' => 1])) ?>"><?= $me ? tt('pl.whole_board') : tt('pl.my_planning') ?></a><?php endif; ?>
    <a class="btn btn--ghost" href="<?= url('planning_export', ['year' => substr($m, 0, 4)]) ?>">⤓ <?= tt('pl.export') ?></a>
    <?php if ($canEdit): ?><a class="btn btn--ghost" href="#plimport">⤒ <?= tt('pl.import') ?></a><a class="btn btn--gold" href="#pleditor" id="js-pl-new">+ <?= tt('pl.assign') ?></a><?php endif; ?>
  </div>
</div>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('pl.need_db') ?></div><?php layout_foot(); return; endif; ?>

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: auto auto 1fr 1fr auto;">
  <input type="hidden" name="p" value="planning"><?php if ($me && $canEdit): ?><input type="hidden" name="me" value="1"><?php endif; ?>
  <div class="field"><label><?= tt('pl.month') ?></label><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['m' => $prev] + $keep) ?>">‹</a><input type="month" name="m" value="<?= e($m) ?>" style="width:150px;"><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['m' => $next] + $keep) ?>">›</a></div></div>
  <div class="field"><label>&nbsp;</label><a class="btn btn--ghost btn--sm" href="<?= url('planning', ['m' => date('Y-m')] + array_diff_key($keep, ['m' => 1])) ?>"><?= tt('pl.this_month') ?></a></div>
  <div class="field"><label><?= tt('disc.one') ?></label><select name="disc"><option value=""><?= tt('flt.any') ?></option><?php foreach ($discs as $d): $dv = $d === '' ? '__none' : $d; ?><option value="<?= e($dv) ?>"<?= $dv === $fDisc ? ' selected' : '' ?>><?= e($d ?: t('tm.no_discipline')) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vesselOpts as $v): ?><option value="<?= (int)$v['id'] ?>"<?= (int)$v['id'] === $fVessel ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('planning', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
</form>

<div class="card pl-card">
  <div class="pl-scroll"><table class="pl-board">
    <thead>
      <tr class="pl-weeks"><th class="pl-name"></th><?php $wk = null; $span = 0; $cells = []; foreach ($dayInfo as $d => $di) { if ($wk !== $di['week']) { if ($wk !== null) $cells[] = [$wk, $span]; $wk = $di['week']; $span = 0; } $span++; } $cells[] = [$wk, $span]; foreach ($cells as [$w, $s]): ?><th colspan="<?= $s ?>">W<?= $w ?></th><?php endforeach; ?><th colspan="4"><?= tt('pl.totals') ?></th></tr>
      <tr><th class="pl-name"><?= tt('ts.engineer') ?></th>
        <?php foreach ($dayInfo as $d => $di): ?><th class="pl-day<?= $di['off'] ? ' is-off' : '' ?><?= $d === $today ? ' is-today' : '' ?>" title="<?= e(fmt_date($d) . ($di['hol'] ? ' · ' . $di['hol'] : '')) ?>"><span><?= e(mb_substr($wd[$di['dow']], 0, 2)) ?></span><b><?= $di['n'] ?></b></th><?php endforeach; ?>
        <th class="num" title="<?= tt('pl.c_work') ?>">⚒</th><th class="num" title="<?= tt('pl.c_leave') ?>">🏖</th><th class="num" title="<?= tt('pl.c_sick') ?>">＋</th><th><?= tt('pl.c_vessels') ?></th></tr>
    </thead>
    <tbody>
    <?php $curDisc = null; if (!$people): ?><tr><td colspan="<?= $nd + 5 ?>" class="hint" style="padding:18px;"><?= tt('pl.nobody') ?></td></tr><?php endif; ?>
    <?php foreach ($people as $p): $uid = (int)$p['id']; $days = $grid[$uid] ?? []; $c = pl_counters($days);
      if (!$me && $p['discipline'] !== $curDisc): $curDisc = $p['discipline']; $grp = array_filter($people, fn($x) => $x['discipline'] === $curDisc); $onToday = 0; foreach ($grp as $x) foreach ($grid[(int)$x['id']][$today] ?? [] as $e) if ($e['kind'] === 'work') { $onToday++; break; } ?>
      <tr class="pl-group"><td colspan="<?= $nd + 5 ?>"><?= e(mb_strtoupper($curDisc ?: t('tm.no_discipline'))) ?> — <?= e(t('pl.group_n', ['n' => count($grp), 'b' => $onToday])) ?></td></tr>
      <?php endif; ?>
      <tr>
        <td class="pl-name"><b><?= e($p['full_name']) ?></b><?php if ($canEdit): ?><a class="pl-clear" href="#pleditor" data-user="<?= $uid ?>" data-name="<?= e($p['full_name']) ?>" title="<?= tt('pl.assign') ?>">+</a><?php endif; ?></td>
        <?php foreach ($dayInfo as $d => $di): $es = $days[$d] ?? []; ?>
          <td class="pl-cell<?= $di['off'] ? ' is-off' : '' ?><?= $d === $today ? ' is-today' : '' ?><?= !$es && !$di['off'] && $d >= $today ? ' is-gap' : '' ?>"<?= $canEdit ? ' data-user="' . $uid . '" data-name="' . e($p['full_name']) . '" data-date="' . $d . '"' : '' ?>>
            <?php foreach ($es as $e): ?><span class="pl-chip pl-<?= pl_tone($e) ?>" title="<?= e(pl_label($e, true) . ($e['car'] ? ' · 🚐 ' . $e['car'] : '') . ($e['note'] ? ' · ' . $e['note'] : '')) ?>" data-id="<?= (int)$e['id'] ?>"<?= !empty($e['work_id']) ? ' data-work="' . (int)$e['work_id'] . '"' : '' ?><?= $e['subproject_id'] ? ' data-sub="' . (int)$e['subproject_id'] . '"' : '' ?><?= $e['project_id'] ? ' data-project="' . (int)$e['project_id'] . '"' : '' ?>><?= e(pl_label($e)) ?><?= $e['car'] ? '<i>🚐</i>' : '' ?><?= $e['note'] ? '<i>✎</i>' : '' ?></span><?php endforeach; ?>
          </td>
        <?php endforeach; ?>
        <td class="num"><?= $c['work'] ?></td><td class="num"><?= $c['leave'] ?></td><td class="num"><?= $c['sick'] ?></td><td class="pl-vessels"><?= e(implode(', ', $c['vessels'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <div class="legend" style="margin-top:10px;"><span class="legend-t"><?= tt('lg.title') ?></span><span class="legend-i"><i class="sw" style="background:#1F3A5F;border-color:#1F3A5F;"></i><?= tt('pl.lg_work') ?></span><span class="legend-i"><i class="sw" style="background:#E6EAEF;"></i><?= tt('pl.k_leave') ?></span><span class="legend-i"><i class="sw" style="background:#9B2C2C;border-color:#9B2C2C;"></i><?= tt('pl.k_sick') ?></span><span class="legend-i"><i class="sw" style="background:#9AA5B1;border-color:#9AA5B1;"></i><?= tt('pl.lg_other') ?></span><span class="legend-i"><i class="sw" style="border:2px dashed var(--cp-gold);background:#FFF6E0;"></i><?= tt('pl.lg_gap') ?></span><span class="legend-i"><i class="sw sw--todo"></i><?= tt('pl.lg_off') ?></span></div>
</div>

<?php if ($me): // the engineer's own view: this and next week with open items
  $wkFrom = date('Y-m-d', strtotime('monday this week')); $wkTo = date('Y-m-d', strtotime($wkFrom . ' +13 days')); $mine = pl_grid($wkFrom, $wkTo, (int)$u['id'])[(int)$u['id']] ?? []; ?>
<div class="card">
  <h2><?= tt('pl.next_two_weeks') ?></h2>
  <?php if (!$mine): ?><p class="hint"><?= tt('pl.nothing_planned') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl--compact"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('pl.assignment') ?></th><th><?= tt('pl.car') ?></th><th><?= tt('pl.note') ?></th><th><?= tt('pl.open_items') ?></th></tr></thead><tbody>
    <?php foreach ($mine as $d => $es): foreach ($es as $e): $items = $e['kind'] === 'work' ? pl_open_items($e['project_id'] ? (int)$e['project_id'] : null, $e['subproject_id'] ? (int)$e['subproject_id'] : null, 5) : []; ?>
      <tr><td class="date"><?= e(fmt_date($d)) ?> <span class="hint"><?= e($wd[(int) date('N', strtotime($d))]) ?></span></td><td><span class="pl-chip pl-<?= pl_tone($e) ?>"><?= e(pl_label($e, true)) ?></span><?= $e['subproject_id'] ? ' <a class="btn btn--ghost btn--sm" href="' . url('subproject', ['id' => (int)$e['subproject_id']]) . '">' . tt('fsr.open') . '</a>' : '' ?></td><td><?= e($e['car'] ?: '—') ?></td><td><?= e($e['note'] ?: '—') ?></td>
        <td><?php if ($items): ?><ul class="pl-items"><?php foreach ($items as $it): ?><li><a href="<?= $it['url'] ?>"><?= e($it['ref']) ?></a> <span class="hint"><?= e(fmt_date($it['date'])) ?></span> — <?= e(mb_substr($it['text'], 0, 160)) ?></li><?php endforeach; ?></ul><?php else: ?><span class="hint">—</span><?php endif; ?></td></tr>
    <?php endforeach; endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="card" id="pleditor">
  <h2><?= tt('pl.assign') ?></h2>
  <form method="post" action="<?= url('planning', $keep) ?>" data-lock id="plform">
    <?= csrf_field() ?><input type="hidden" name="action" value="pl_assign">
    <div class="grid grid-3">
      <?php $plPeople = pl_people(); $byDisc = []; foreach ($plPeople as $p) $byDisc[$p['discipline'] ?: '—'][] = $p; ?>
      <div class="field"><label><?= tt('ts.engineer') ?></label>
        <select id="pl_add_user"><option value=""><?= tt('pl.add_engineer') ?></option><?php foreach ($byDisc as $dn => $pp): ?><optgroup label="<?= e($dn) ?>"><?php foreach ($pp as $p): ?><option value="<?= (int)$p['id'] ?>" data-disc="<?= e($dn) ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pl.discipline') ?></label>
        <select id="pl_add_disc" data-nosearch><option value=""><?= tt('pl.add_discipline') ?></option><?php foreach ($byDisc as $dn => $pp): ?><option value="<?= e($dn) ?>"><?= e($dn) ?> (<?= count($pp) ?>)</option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pl.selected_people') ?> <span class="hint" id="pl_count">0</span> · <button type="button" class="linkbtn" id="pl_none"><?= tt('btn.clear') ?></button></label>
        <div class="pl-chips" id="pl_people" data-names='<?= e(json_encode(array_column($plPeople, 'full_name', 'id'), JSON_UNESCAPED_UNICODE)) ?>'><?php if ($edit && $edit['user']): $en = array_column($plPeople, 'full_name', 'id'); ?><span class="pl-chipx" data-id="<?= (int)$edit['user'] ?>"><input type="hidden" name="user_id[]" value="<?= (int)$edit['user'] ?>"><?= e($en[(int)$edit['user']] ?? '') ?> <button type="button" class="pl-x" aria-label="remove">✕</button></span><?php endif; ?></div></div>
      <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="from" id="pl_from" required value="<?= e($edit['date'] ?? max($from, $today)) ?>"></div>
      <div class="field"><label><?= tt('pl.to') ?></label><input type="date" name="to" id="pl_to" value="<?= e($edit['date'] ?? '') ?>"><p class="hint"><?= tt('pl.to_hint') ?></p></div>
      <div class="field"><label><?= tt('pl.kind') ?></label><select name="kind" id="pl_kind"><?php foreach ($kinds as $k => $l): if (function_exists('hr_only') && hr_only() && $k === 'work') continue; ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <?php if ($hrOn = function_exists('hr_has') && hr_has()): ?>
      <div class="field pl-abs"><label><?= tt('hr.type') ?></label><select name="leave_type_id" id="pl_ltype" data-nosearch><?php foreach (hr_types() as $tid => $t): ?><option value="<?= $tid ?>" data-kind="<?= e($t['kind']) ?>" data-reason="<?= (int)$t['needs_reason'] ?>"><?= e($t['code'] . ' · ' . $t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field pl-abs"><label><?= tt('hr.reason') ?> <span class="hint" id="pl_reason_req" style="display:none;">*</span></label><input name="reason" id="pl_reason" maxlength="300" placeholder="<?= tt('hr.reason_ph') ?>"></div>
      <?php endif; ?>
      <div class="field pl-work"><label><?= tt('sp.one') ?></label>
        <select name="subproject_id" id="pl_sub"><option value=""><?= tt('pl.sub_none') ?></option><?php $cv = null; foreach ($subOpts as $s): if ($s['vessel'] !== $cv): if ($cv !== null) echo '</optgroup>'; $cv = $s['vessel']; echo '<optgroup label="' . e($cv ?: '—') . '">'; endif; ?><option value="<?= (int)$s['id'] ?>" data-project="<?= (int)$s['pid'] ?>"><?= e(trim($s['project_code'] . ' ' . $s['code']) . ' · ' . $s['name']) ?></option><?php endforeach; if ($cv !== null) echo '</optgroup>'; ?></select></div>
      <div class="field pl-work"><label><?= tt('pr.one') ?> <span class="hint" style="display:inline;"><?= tt('pl.project_only') ?></span></label>
        <select name="project_id" id="pl_project"><option value=""><?= tt('pl.pick_project') ?></option><?php foreach ($projOpts as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= e(($pr['vessel'] ? $pr['vessel'] . ' · ' : '') . ($pr['project_code'] ?: $pr['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pl.car') ?></label><select name="car_id"><option value="">—</option><?php foreach ($cars as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['name'] . ($c['plate'] ? ' · ' . $c['plate'] : '')) ?></option><?php endforeach; ?></select><?php if (is_cp_admin()): ?><p class="hint"><a href="#plcars"><?= tt('pl.manage_cars') ?></a></p><?php endif; ?></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('pl.note') ?></label><input name="note" maxlength="500" placeholder="<?= tt('pl.note_ph') ?>"></div>
    </div>
    <div class="btn-row" style="margin-top:4px;">
      <label class="chk" style="margin:0;"><input type="checkbox" name="all_days" value="1"> <?= tt('pl.all_days') ?></label>
      <label class="chk" style="margin:0;"><input type="checkbox" name="replace" value="1"> <?= tt('pl.replace') ?></label>
    </div>
    <div id="pl-open" class="pl-open" style="display:none;"></div>
    <div class="btn-row" style="margin-top:12px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button>
      <button class="btn btn--ghost btn--danger-ghost" type="submit" name="action" value="pl_clear" formnovalidate data-confirm="<?= tt('pl.clear_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= tt('pl.clear_days') ?></button>
      <span class="hint"><?= tt('pl.assign_hint') ?></span></div>
  </form>
</div>
<div class="card" id="pl-one" style="display:none;">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;" id="pl-one-title"></h2><form method="post" action="<?= url('planning', $keep) ?>" data-lock data-confirm="<?= tt('pl.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="pl_delete"><input type="hidden" name="id" id="pl-one-id"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">✕ <?= tt('btn.delete') ?></button></form></div>
  <div id="pl-one-items" class="pl-open"></div>
</div>
<?php if ($canEdit): ?>
<div class="pl-ctx" id="pl-ctx" hidden role="menu">
  <button type="button" class="pl-ctx-i" data-act="one">✕ <?= tt('pl.ctx_delete_day') ?></button>
  <button type="button" class="pl-ctx-i" data-act="work" hidden>✕ <?= tt('pl.ctx_delete_work') ?></button>
  <button type="button" class="pl-ctx-i" data-act="open">↗ <?= tt('pl.ctx_open') ?></button>
</div>
<form method="post" action="<?= url('planning', $keep) ?>" id="pl-ctx-del" hidden data-lock data-confirm="<?= tt('pl.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="pl_delete"><input type="hidden" name="id" value=""></form>
<form method="post" action="<?= url('planning', $keep) ?>" id="pl-ctx-delw" hidden data-lock data-confirm="<?= tt('pl.delete_work_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="pl_delete_work"><input type="hidden" name="work_id" value=""></form>
<?php endif; ?>

<?php if (is_cp_admin()): ?>
<div class="card" id="plcars">
  <h2><?= tt('pl.cars') ?></h2>
  <?php $allCars = pl_cars(false); if ($allCars): ?><div class="table-scroll"><table class="tbl--compact"><thead><tr><th><?= tt('br.name') ?></th><th><?= tt('pl.plate') ?></th><th><?= tt('th.remarks') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($allCars as $c): ?><tr><form method="post" action="<?= url('planning', $keep) ?>" id="car<?= (int)$c['id'] ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pl_car_save"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
      <td><input form="car<?= (int)$c['id'] ?>" name="name" value="<?= e($c['name']) ?>" maxlength="60" required></td><td><input form="car<?= (int)$c['id'] ?>" name="plate" value="<?= e($c['plate'] ?? '') ?>" maxlength="20"></td><td><input form="car<?= (int)$c['id'] ?>" name="notes" value="<?= e($c['notes'] ?? '') ?>" maxlength="200"></td>
      <td><label class="chk"><input form="car<?= (int)$c['id'] ?>" type="checkbox" name="is_active" value="1"<?= $c['is_active'] ? ' checked' : '' ?>> <?= tt('sys.active') ?></label><input form="car<?= (int)$c['id'] ?>" type="hidden" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></td>
      <td class="row-actions" style="white-space:nowrap;"><button form="car<?= (int)$c['id'] ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button> <form method="post" action="<?= url('planning', $keep) ?>" style="display:inline;" data-lock data-confirm="<?= e(t('pl.car_delete_confirm', ['name' => $c['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="pl_car_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">✕</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <form method="post" action="<?= url('planning', $keep) ?>" class="fltbar" style="grid-template-columns: 1.4fr 1fr 2fr auto; margin-top:10px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pl_car_save"><input type="hidden" name="is_active" value="1">
    <div class="field"><label><?= tt('br.name') ?></label><input name="name" required maxlength="60" placeholder="Van 1 · Vito"></div><div class="field"><label><?= tt('pl.plate') ?></label><input name="plate" maxlength="20" placeholder="ΥΖΒ-1234"></div><div class="field"><label><?= tt('th.remarks') ?></label><input name="notes" maxlength="200"></div>
    <div class="fltbar-actions"><button class="btn" type="submit">+ <?= tt('pl.car_add') ?></button></div></form>
</div>
<?php endif; ?>

<div class="card" id="plimport">
  <h2><?= tt('pl.import') ?></h2>
  <p class="hint"><?= tt('pl.import_hint') ?> <?= tt('pl.tpl_hint') ?> <a href="<?= url('planning_export', ['year' => substr($m, 0, 4)]) ?>">⤓ <?= tt('pl.tpl_download', ['y' => substr($m, 0, 4)]) ?></a></p>
  <form method="post" action="<?= url('planning', $keep) ?>" enctype="multipart/form-data" class="fltbar" style="grid-template-columns: 2fr auto auto auto;" data-lock data-busy="<?= tt('im.running') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="pl_import">
    <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
    <label class="chk" style="padding-bottom:10px;"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
    <label class="chk" style="padding-bottom:10px;"><input type="checkbox" name="replace" value="1" checked> <?= tt('pl.im_replace') ?></label>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('im.run') ?></button></div>
  </form>
  <?php if ($res): ?><div class="flash <?= $res['dry'] ? 'flash--warn' : 'flash--good' ?>" style="margin-top:10px;"><?= e(t('pl.im_result', ['n' => $res['rows'], 'p' => $res['people'], 'e' => $res['entries'], 'u' => count($res['unknown_names'])])) ?><?= $res['dry'] ? ' · ' . tt('im.dry_note') : '' ?>
    <?php if ($res['unknown_names']): ?><p style="margin:6px 0 0;"><b><?= tt('pl.im_unknown_names') ?>:</b> <?= e(implode(' · ', array_keys($res['unknown_names']))) ?></p><?php endif; ?>
    <?php if ($res['unknown_codes']): ?><p style="margin:6px 0 0;"><b><?= tt('pl.im_unknown_codes') ?>:</b> <?= e(implode(' · ', array_map(fn($k, $n) => "$k ($n)", array_keys($res['unknown_codes']), $res['unknown_codes']))) ?></p><?php endif; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
(function () {
  var board = document.querySelector('.pl-board'), form = document.getElementById('plform'); if (!board) return;
  var openBox = document.getElementById('pl-open'), one = document.getElementById('pl-one');
  var todayTh = board.querySelector('th.is-today'), scroller = document.querySelector('.pl-scroll');   // open on today's column
  if (todayTh && scroller) { var nameW = board.querySelector('th.pl-name').offsetWidth; scroller.scrollLeft = Math.max(0, todayTh.getBoundingClientRect().left - scroller.getBoundingClientRect().left - nameW - 3 * todayTh.offsetWidth); }
  function loadItems(box, project, sub, title) {
    if (!box) return; if (!project && !sub) { box.style.display = 'none'; box.innerHTML = ''; return; }
    fetch('index.php?p=plan_items&project=' + (project || 0) + '&sub=' + (sub || 0), { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) { box.innerHTML = (title ? '<h3 class="ac-h">' + title + '</h3>' : '') + h; box.style.display = h.trim() ? '' : 'none'; }).catch(function () {});
  }
  if (form) {
    var kind = document.getElementById('pl_kind'), sub = document.getElementById('pl_sub'), proj = document.getElementById('pl_project');
    var lt = document.getElementById('pl_ltype'), rr = document.getElementById('pl_reason_req');
    function kindUi() { var w = kind.value === 'work'; form.querySelectorAll('.pl-work').forEach(function (f) { f.style.display = w ? '' : 'none'; });
      var abs = !w && ['leave', 'sick', 'parental', 'strike', 'other'].indexOf(kind.value) >= 0; form.querySelectorAll('.pl-abs').forEach(function (f) { f.style.display = abs ? '' : 'none'; });
      if (lt) { var first = null; Array.prototype.forEach.call(lt.options, function (o) { var ok = o.dataset.kind === kind.value; o.hidden = !ok; o.disabled = !ok; if (ok && first === null) first = o.value; }); if (first !== null && (lt.selectedIndex < 0 || lt.options[lt.selectedIndex].hidden)) lt.value = first; typeUi(); } }
    function typeUi() { if (!lt || !rr) return; var o = lt.options[lt.selectedIndex]; rr.style.display = o && o.dataset.reason === '1' ? '' : 'none'; }
    if (lt) lt.addEventListener('change', typeUi);
    kind.addEventListener('change', kindUi); kindUi();
    sub.addEventListener('change', function () { var o = sub.options[sub.selectedIndex]; if (sub.value) proj.value = o.dataset.project; loadItems(openBox, proj.value, sub.value, <?= json_encode(t('pl.open_items')) ?>); });
    proj.addEventListener('change', function () { if (!sub.value) loadItems(openBox, proj.value, 0, <?= json_encode(t('pl.open_items')) ?>); });
    // a click on an empty cell or on the "+" next to the name pre-fills the editor
    var plBox = document.getElementById('pl_people'), plNames = JSON.parse(plBox.dataset.names || '{}');
    function plCount() { var el = document.getElementById('pl_count'); if (el) el.textContent = plBox.querySelectorAll('.pl-chipx').length; }
    function plAdd(id) { id = String(id); if (!id || plBox.querySelector('.pl-chipx[data-id="' + id + '"]')) return; var c = document.createElement('span'); c.className = 'pl-chipx'; c.dataset.id = id; c.innerHTML = '<input type="hidden" name="user_id[]" value="' + id + '">' + (plNames[id] || id).replace(/</g, '&lt;') + ' <button type="button" class="pl-x" aria-label="remove">✕</button>'; plBox.appendChild(c); plCount(); }
    function plSet(id) { plBox.innerHTML = ''; plAdd(id); }
    plBox.addEventListener('click', function (e) { var x = e.target.closest('.pl-x'); if (x) { x.closest('.pl-chipx').remove(); plCount(); } });
    document.getElementById('pl_add_user').addEventListener('change', function () { if (!this.value) return; plAdd(this.value); this.value = ''; this.dispatchEvent(new Event('change')); });
    document.getElementById('pl_add_disc').addEventListener('change', function () { var d = this.value; if (!d) return; document.querySelectorAll('#pl_add_user option[data-disc]').forEach(function (o) { if (o.dataset.disc === d) plAdd(o.value); }); this.value = ''; this.dispatchEvent(new Event('change')); });
    document.getElementById('pl_none').addEventListener('click', function () { plBox.innerHTML = ''; plCount(); });
    plCount();
    board.addEventListener('click', function (e) {
      var chip = e.target.closest('.pl-chip');
      if (chip) { e.preventDefault(); if (one) { one.style.display = ''; document.getElementById('pl-one-id').value = chip.dataset.id; document.getElementById('pl-one-title').textContent = chip.title; loadItems(document.getElementById('pl-one-items'), chip.dataset.project, chip.dataset.sub, <?= json_encode(t('pl.open_items')) ?>); one.scrollIntoView({ behavior: 'smooth', block: 'start' }); } return; }
      var cell = e.target.closest('.pl-cell[data-user]'), plus = e.target.closest('.pl-clear');
      var t = cell || plus; if (!t) return; e.preventDefault();
      plSet(t.dataset.user);
      if (cell) { document.getElementById('pl_from').value = cell.dataset.date; document.getElementById('pl_to').value = cell.dataset.date; }
      document.getElementById('pleditor').scrollIntoView({ behavior: 'smooth', block: 'start' }); document.getElementById('pl_kind').focus();
    });
    var nb = document.getElementById('js-pl-new'); if (nb) nb.addEventListener('click', function () { document.getElementById('pl_kind').focus(); });
    // right-click on a chip: delete this day / delete the whole package planning / open
    var ctx = document.getElementById('pl-ctx'), cur = null;
    if (ctx) {
      board.addEventListener('contextmenu', function (e) {
        var chip = e.target.closest('.pl-chip[data-id]'); if (!chip) return; e.preventDefault(); cur = chip;
        ctx.querySelector('[data-act="work"]').hidden = !chip.dataset.work;
        ctx.hidden = false; var w = ctx.offsetWidth, h = ctx.offsetHeight;
        ctx.style.left = Math.min(e.pageX, window.scrollX + document.documentElement.clientWidth - w - 8) + 'px'; ctx.style.top = Math.min(e.pageY, window.scrollY + document.documentElement.clientHeight - h - 8) + 'px';
      });
      document.addEventListener('click', function (e) { if (!e.target.closest('#pl-ctx')) ctx.hidden = true; });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') ctx.hidden = true; });
      ctx.addEventListener('click', function (e) {
        var b = e.target.closest('.pl-ctx-i'); if (!b || !cur) return; ctx.hidden = true;
        if (b.dataset.act === 'one') { var f = document.getElementById('pl-ctx-del'); f.querySelector('[name="id"]').value = cur.dataset.id; f.requestSubmit(); }
        else if (b.dataset.act === 'work') { var g = document.getElementById('pl-ctx-delw'); g.querySelector('[name="work_id"]').value = cur.dataset.work; g.requestSubmit(); }
        else if (b.dataset.act === 'open') { if (cur.dataset.work) window.location.href = 'index.php?p=work&id=' + cur.dataset.work; else cur.click(); }
      });
    }
  }
})();
</script>
<?php if (function_exists('hr_can') && hr_can()): $hy = (int)($_GET['hy'] ?? substr($m, 0, 4)); $hu = (int)($_GET['hu'] ?? 0); $hrStaff = hr_staff(); $fh = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.'); ?>
<div class="card" id="hr-report">
  <div class="card-head"><h2>🏖 <?= tt('hr.report') ?></h2>
    <form method="get" action="index.php" class="inline fltbar" style="grid-template-columns:auto auto auto;"><input type="hidden" name="p" value="planning"><input type="hidden" name="m" value="<?= e($m) ?>">
      <select name="hy" data-nosearch><?php foreach (hr_years() as $yy): ?><option value="<?= $yy ?>"<?= $yy === $hy ? ' selected' : '' ?>><?= $yy ?></option><?php endforeach; ?></select>
      <select name="hu"><option value="0"><?= tt('hr.all_staff') ?></option><?php foreach ($hrStaff as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$p['id'] === $hu ? ' selected' : '' ?>><?= e($p['full_name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.apply') ?></button></form>
    <a class="btn btn--ghost btn--sm" href="<?= url('hr_export', ['year' => $hy]) ?>">⤓ Excel</a><?php if (is_cp_admin() || hr_only()): ?><a class="btn btn--ghost btn--sm" href="<?= url('admin_hr', ['year' => $hy]) ?>">⚙ <?= tt('hr.settings') ?></a><?php endif; ?></div>
  <?php if ($hu): $rep = hr_report($hu, $hy); $abs = hr_absences($hu, $hy); $who = array_values(array_filter($hrStaff, fn($p) => (int)$p['id'] === $hu))[0] ?? null; ?>
    <h3 style="margin:0 0 8px;"><?= e($who['full_name'] ?? '') ?> · <?= $hy ?></h3>
    <?php if (!$rep): ?><p class="hint"><?= tt('hr.no_data') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl" style="max-width:820px;"><thead><tr><th><?= tt('hr.type') ?></th><th class="num"><?= tt('hr.entitled') ?></th><th class="num"><?= tt('hr.carry') ?></th><th class="num"><?= tt('hr.taken') ?></th><th class="num"><?= tt('hr.remaining') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($rep as $r): ?><tr><td><b><?= e($r['type']['code']) ?></b> <?= e($r['type']['name']) ?></td><td class="num"><?= $r['type']['counts'] ? e($fh($r['entitled'])) : '—' ?></td><td class="num"><?= $r['type']['counts'] ? e($fh($r['carry'])) : '—' ?></td><td class="num"><b><?= e($fh($r['taken'])) ?></b></td><td class="num<?= $r['state'] === 'over' ? ' bad' : '' ?>"><?= $r['remaining'] === null ? '—' : e($fh($r['remaining'])) ?></td><td><?= $r['state'] === 'over' ? pill('bad', t('hr.st_over')) : ($r['state'] === 'low' ? pill('gold', t('hr.st_low')) : ($r['state'] === 'ok' ? pill('green', t('hr.st_ok')) : '')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
    <h3 style="margin:14px 0 6px;"><?= tt('hr.absences') ?> <span class="hint">(<?= count($abs) ?>)</span></h3>
    <?php if (!$abs): ?><p class="hint"><?= tt('hr.no_absences') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl" style="max-width:980px;"><thead><tr><th><?= tt('hr.type') ?></th><th><?= tt('pl.from') ?></th><th><?= tt('pl.to') ?></th><th class="num"><?= tt('hr.days') ?></th><th><?= tt('hr.reason') ?></th><th><?= tt('pl.note') ?></th></tr></thead><tbody>
    <?php foreach ($abs as $a): ?><tr><td><span class="pl-chip pl-<?= PL_TONES[$a['kind']] ?? 'o' ?>"><?= e($a['type']['code'] ?? t('pl.k_' . $a['kind'])) ?></span> <?= e($a['type']['name'] ?? '') ?></td><td><?= e(fmt_date($a['from'])) ?></td><td><?= e(fmt_date($a['to'])) ?></td><td class="num"><?= (int)$a['days'] ?></td><td><?= e($a['reason']) ?></td><td class="hint"><?= e($a['note']) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  <?php else: $sum = hr_summary($hy); $types = hr_types(); ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ll.who') ?></th><th><?= tt('disc.name') ?></th><?php foreach ($types as $t): ?><th class="num" title="<?= e($t['name']) ?>"><?= e($t['code']) ?><?= $t['counts'] ? '<br><span class="hint">' . tt('hr.x_taken') . ' / ' . tt('hr.x_rem') . '</span>' : '' ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($sum as $uid => $sx): ?><tr><td><a href="<?= url('planning', $keep + ['hy' => $hy, 'hu' => $uid]) ?>#hr-report"><?= e($sx['person']['full_name']) ?></a></td><td class="hint"><?= e($sx['person']['discipline'] ?? '') ?></td>
      <?php foreach ($types as $tid => $t): $r = $sx['rows'][$tid] ?? null; ?><td class="num<?= $r && $r['state'] === 'over' ? ' bad' : '' ?>"><?= $r ? e($fh($r['taken'])) . ($t['counts'] && $r['remaining'] !== null ? ' / <b>' . e($fh($r['remaining'])) . '</b>' : '') : '<span class="hint">—</span>' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div>
    <p class="hint"><?= tt('hr.summary_hint') ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

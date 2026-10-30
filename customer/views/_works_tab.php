<?php
/** Partial: Sub-project › Works tab — the work packages and the assignment form (GAS-CP-R35-001). expects $sp, $id, $pid, $backTo */
if (!function_exists('wp_has') || !wp_has() || !is_staff()) return;
$canM = wp_can_manage($pid); $works = wp_list(['sub' => $id]);
$pdOk = function_exists('pd_has') && pd_has(); $pdCan = $pdOk && pd_can_create((int)$sp['project_id']);   // R41-001
$editId = (int)($_GET['work'] ?? 0); $ew = $editId ? wp_get($editId) : null; if ($ew && (int)$ew['subproject_id'] !== $id) $ew = null;
$people = wp_people_options(); $disc = all('SELECT * FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name');
$areas = !empty($sp['vessel_id']) && function_exists('wf_areas') ? wf_areas((int)$sp['vessel_id']) : [];
$WL = $canM ? wp_workload(date('Y-m-d'), date('Y-m-d', strtotime('+14 days')))['people'] : [];
$ewPeople = $ew ? array_map('intval', array_column($ew['people_rows'], 'user_id')) : [];
$sumPlanned = array_sum(array_map(fn($w) => $w['status'] !== 'cancelled' ? (float)$w['planned_hours'] : 0, $works)); $sumActual = array_sum(array_map(fn($w) => (float)$w['actual_h'], $works));
$budgetH = function_exists('bg_can') && bg_can() ? bg_calc($id, $sp)['budget_h'] : null;
?>
<div class="card">
  <div class="card-head"><h2><?= tt('wp.title') ?> <span class="count"><?= count($works) ?></span></h2>
    <span class="hint"><?= e(t('wp.sum_line', ['p' => bg_h_safe($sumPlanned), 'a' => bg_h_safe($sumActual)])) ?><?= $budgetH !== null ? ' · ' . tt('bg.budget_h') . ' ' . e(bg_h_safe($budgetH)) . ($budgetH > 0 && $sumPlanned > $budgetH ? ' <span class="bg-neg">(' . tt('wp.over_budget') . ')</span>' : '') : '' ?></span>
    <?php if ($canM): ?><a class="btn btn--gold btn--sm" href="#wp-form">+ <?= tt('wp.new') ?></a><?php endif; ?></div>
  <?php if (!$works): ?><p class="hint"><?= tt('wp.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl wp-tbl"><thead><tr><th><?= tt('wp.no') ?></th><th><?= tt('wp.work') ?></th><th><?= tt('bg.discipline') ?></th><th><?= tt('wf.area') ?></th><th><?= tt('wp.dates') ?></th><th><?= tt('wp.people') ?></th><th class="num"><?= tt('wp.hours_p_a') ?></th><th><?= tt('th.status') ?></th><?php if ($canM): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($works as $w): $pct = (float)$w['planned_hours'] > 0 ? min(100, (int) round((float)$w['actual_h'] / (float)$w['planned_hours'] * 100)) : 0; ?>
    <tr class="<?= $w['status'] === 'cancelled' ? 'is-muted' : '' ?>">
      <td class="mono"><a href="<?= url('work', ['id' => $w['id']]) ?>"><b><?= e($w['wp_no']) ?></b></a><?= (int)$w['priority'] === 1 ? ' ' . pill('bad', t('wp.p_high')) : '' ?></td>
      <td><a href="<?= url('work', ['id' => $w['id']]) ?>"><?= e($w['title']) ?></a></td>
      <td><?= e($w['discipline'] ?? '—') ?></td><td><?= e($w['area'] ?? '—') ?></td>
      <td class="hint"><?= $w['start_date'] ? e(fmt_date($w['start_date'])) . ($w['end_date'] && $w['end_date'] !== $w['start_date'] ? ' – ' . e(fmt_date($w['end_date'])) : '') : '—' ?></td>
      <td><?= e($w['people'] ?: '—') ?><?= $w['lead_name'] && (int)$w['n_people'] > 1 ? ' <span class="hint">(' . tt('wp.lead') . ' ' . e($w['lead_name']) . ')</span>' : '' ?></td>
      <td class="num"><?= e(bg_h_safe((float)$w['planned_hours'])) ?> / <b><?= e(bg_h_safe((float)$w['actual_h'])) ?></b><?= function_exists('bg_bar') ? bg_bar($pct, $pct >= 100 ? 'amber' : 'green', null, '6px') : '' ?></td>
      <td><?= wp_status_pill($w['status']) ?><?php if ($pdOk && ($po = pd_for_work((int)$w['id']))): ?><br><a class="pill pill--<?= PD_TONE[$po['status']] ?>" href="<?= url('production_order', ['id' => (int)$po['id']]) ?>" title="<?= e($po['po_no'] . ' · ' . pd_status_label($po['status'])) ?>">🏭 <?= e($po['po_no']) ?> · <?= (int)$po['pct'] ?> %</a><?php elseif ($pdOk && $pdCan && $w['status'] !== 'cancelled'): ?><br><a class="linkbtn hint" href="<?= url('production_edit', ['work' => (int)$w['id']]) ?>" title="<?= tt('pd.new_from_work') ?>">🏭 + <?= tt('pd.one') ?></a><?php endif; ?></td>
      <?php if ($canM): ?><td class="ob-act"><a class="linkbtn" href="<?= url('subproject', ['id' => $id, 'tab' => 'works', 'work' => $w['id']]) ?>#wp-form">✎</a></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= tt('wp.list_hint') ?></p>
</div>

<?php if ($canM): ?>
<div class="card" id="wp-form">
  <h2><?= $ew ? tt('wp.edit') . ' ' . e($ew['wp_no']) : tt('wp.new') ?></h2>
  <form method="post" action="<?= $backTo ?>#wp-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wp_save"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="work_id" value="<?= (int)($ew['id'] ?? 0) ?>">
    <div class="grid grid-3">
      <div class="field" style="grid-column:span 2;"><label><?= tt('wp.work') ?> *</label><input name="title" required maxlength="160" value="<?= e($ew['title'] ?? '') ?>" placeholder="<?= tt('wp.title_ph') ?>"></div>
      <div class="field"><label><?= tt('bg.discipline') ?></label><select name="discipline_id" data-nosearch><option value="">—</option><?php foreach ($disc as $d): ?><option value="<?= $d['id'] ?>"<?= (int)($ew['discipline_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label><?= tt('ob.c_desc') ?></label><textarea name="description" rows="2"><?= e($ew['description'] ?? '') ?></textarea></div>
    <div class="grid grid-4">
      <div class="field"><label><?= tt('wp.planned_hours') ?></label><input name="planned_hours" inputmode="decimal" value="<?= $ew ? e(bg_h_safe((float)$ew['planned_hours'])) : '' ?>"></div>
      <div class="field"><label><?= tt('wp.start') ?></label><input type="date" name="start_date" value="<?= e($ew['start_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('wp.end') ?></label><input type="date" name="end_date" value="<?= e($ew['end_date'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('wp.priority') ?></label><select name="priority" data-nosearch><?php foreach ([1 => t('wp.p_high'), 2 => t('wp.p_normal'), 3 => t('wp.p_low')] as $k => $l): ?><option value="<?= $k ?>"<?= (int)($ew['priority'] ?? 2) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <?php if ($areas): ?><div class="field"><label><?= tt('wf.area') ?> <span class="hint"><?= tt('wp.area_hint') ?></span></label><select name="area_id"><option value="">—</option><?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>"<?= (int)($ew['area_id'] ?? 0) === (int)$a['id'] ? ' selected' : '' ?>><?= e($a['name'] . ($a['deck'] ?? '' ? ' · ' . $a['deck'] : '')) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label><?= tt('wp.people') ?> <span class="hint"><?= tt('wp.people_hint') ?></span></label>
      <div class="wp-people">
        <?php $curD = null; foreach ($people as $pp): $uid = (int)$pp['id']; $R = $WL[$uid] ?? null; if ($pp['discipline'] !== $curD): $curD = $pp['discipline']; ?><div class="wp-dh"><?= e($curD ?: t('bg.no_discipline')) ?></div><?php endif; ?>
          <label class="chk wp-p"><input type="checkbox" name="people[]" value="<?= $uid ?>"<?= in_array($uid, $ewPeople, true) ? ' checked' : '' ?>> <b><?= e($pp['full_name']) ?></b><?php if ($R): ?><span class="wp-av wp-av--<?= $R['pct'] >= 100 ? 'red' : ($R['pct'] >= 80 ? 'amber' : 'green') ?>" title="<?= tt('wp.av_title') ?>"><?= e(wp_avail_hint($R)) ?></span><?php endif; ?></label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('wp.lead') ?></label><select name="lead_user_id" data-nosearch><option value="">— <?= tt('wp.lead_first') ?> —</option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= (int)($ew['lead_user_id'] ?? 0) === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="display:flex;align-items:flex-end;"><label class="chk"><input type="checkbox" name="auto_plan" value="1"<?= !$ew || $ew['auto_plan'] ? ' checked' : '' ?>> <?= tt('wp.auto_plan') ?></label></div>
    </div>
    <p class="hint" style="margin:0 0 10px;"><?= tt('wp.auto_plan_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $ew ? tt('btn.save') : tt('wp.create') ?></button><?php if ($ew): ?><a class="btn btn--ghost" href="<?= url('subproject', ['id' => $id, 'tab' => 'works']) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
</div>
<?php endif; ?>

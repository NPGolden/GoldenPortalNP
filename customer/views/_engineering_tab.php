<?php
/** Partial: Sub-project › Engineering tab — sections, budgets, deliverables, extra-hours requests (GAS-CP-R47-001). expects $sp, $id, $pid, $backTo, $staff */
if (!function_exists('eng_has') || !eng_has() || !is_staff()) return;
$rows = eng_rows($id); $sec = eng_sections(); $canB = eng_can_edit($sp, true); $canE = eng_can_edit($sp); $mgmt = eng_is_management(); $canDecideSite = eng_can_decide($sp, 'installation');
$reqs = eng_requests(['status' => 'all', 'subproject' => $id]); $team = staff_users();
$editDl = (int)($_GET['dl'] ?? 0); $pct = eng_sub_pct($id); $bgOn = function_exists('bg_has') && bg_has(); $bgCur = $bgOn && function_exists('bg_current') ? bg_current($id) : null; $notIn = array_diff_key($sec, $rows); $unmapped = !array_filter(array_map(fn($x) => $x['disciplines'], $sec)); $fh = fn(float $h) => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
$totB = 0.0; $totA = 0.0; $totX = 0.0; foreach ($rows as $r) { $totB += $r['budget']; $totA += $r['actual']; $totX += $r['extra']; }
?>
<div class="card">
  <div class="card-head"><h2>📐 <?= tt('eng.title') ?></h2><span class="hint"><?= tt('eng.lede') ?></span></div>
  <?php if ($unmapped): ?><p class="hint">⚠ <?= tt('eng.none') ?><?= is_cp_admin() ? ' <a href="' . url('admin_engineering') . '">' . tt('eng.settings') . ' ›</a>' : '' ?></p><?php elseif (!$rows): ?><p class="hint"><?= $bgOn ? e(t('eng.no_budget_yet', ['r' => $bgCur ? 'R' . (int)$bgCur['revision'] : '—'])) . ' <a href="' . url('subproject', ['id' => $id, 'tab' => 'budget']) . '#bg-edit">' . tt('bg.tab') . ' ›</a>' : tt('eng.no_budget_manual') ?></p><?php else: ?>
  <div class="kpi kpi--4" style="margin-bottom:12px;">
    <span class="stat"><b class="eng-h"><?= e($fh($totB)) ?> h</b><span class="s"><?= tt('eng.budget') ?></span></span>
    <span class="stat gold"><b class="eng-h">+<?= e($fh($totX)) ?> h</b><span class="s"><?= tt('eng.extra') ?></span></span>
    <span class="stat<?= $totB + $totX > 0 && $totA > $totB + $totX ? ' red' : '' ?>"><b class="eng-h"><?= e($fh($totA)) ?> h</b><span class="s"><?= tt('eng.actual') ?></span></span>
    <span class="stat green"><b><?= $pct !== null ? $pct . ' %' : '—' ?></b><span class="s"><?= tt('eng.pct_done') ?></span></span>
  </div>
  <?php $byStage = []; foreach ($rows as $r) $byStage[$r['stage']][] = $r; $stPct = eng_stage_pcts($id); foreach (ENG_STAGES as $stg): if (empty($byStage[$stg])) continue; ?>
  <h3 class="eng-stage" id="st-<?= $stg ?>"><?= e(t('st.' . $stg)) ?><?= isset($stPct[$stg]) ? ' <span class="pill pill--navy">' . $stPct[$stg] . ' %</span>' : '' ?> <span class="hint" style="font-weight:400;"><?= e(t('eng.a_' . $stg)) ?> · <?= tt('eng.stage_fed') ?></span></h3>
  <div class="eng-grid">
  <?php foreach ($byStage[$stg] as $r): $did = $r['did']; $lead = $r['lead_user_id'] ? (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$r['lead_user_id']]) : ''; ?>
    <section class="eng-sec eng-sec--<?= $r['state'] ?>">
      <header><h3><?= e($r['name']) ?></h3><?= eng_state_pill($r['state'], in_array($r['state'], ['exceeded', 'warn'], true) ? $r['pct_used'] . ' %' : ($r['state'] === 'extended' ? '+' . $fh($r['extra']) . ' h' : null)) ?></header>
      <div class="eng-nums"><span><b><?= e($fh($r['budget'])) ?></b> <?= tt('eng.budget_s') ?></span><?php if ($r['extra'] > 0): ?><span class="eng-x"><b>+<?= e($fh($r['extra'])) ?></b> <?= tt('eng.extra_s') ?></span><?php endif; ?><span><b><?= e($fh($r['actual'])) ?></b> <?= tt('eng.actual_s') ?><?php if ($r['over_h'] > 0): ?> <b style="color:#9B2C2C;">(+<?= e($fh($r['over_h'])) ?> <?= tt('eng.over_s') ?>)</b><?php endif; ?></span><span><b><?= e($fh($r['earned'])) ?></b> <?= tt('eng.earned_s') ?></span></div>
      <div class="eng-bars"><div class="eng-bar" title="<?= tt('eng.pct_done') ?>"><i style="width:<?= $r['pct_done'] ?>%"></i><span><?= $r['pct_done'] ?> % <?= tt('eng.done_lbl') ?></span></div><?php $scale = max($r['allowed'], $r['actual'], $r['budget']) ?: 1; $wB = $r['budget'] / $scale * 100; $wX = $r['extra'] / $scale * 100; $wA = min(100, $r['actual'] / $scale * 100); ?><div class="eng-bar eng-bar--used<?= $r['over_h'] > 0 ? ' is-over' : '' ?>" title="<?= tt('eng.pct_used') ?>"><?php if ($r['extra'] > 0): ?><em class="eng-bar__x" style="left:<?= $wB ?>%;width:<?= $wX ?>%" title="<?= tt('eng.extra') ?>"></em><?php endif; ?><i style="width:<?= $wA ?>%"></i><?php if ($r['budget'] > 0 && $wB < 100): ?><u class="eng-bar__tick" style="left:<?= $wB ?>%" title="<?= tt('eng.budget') ?>"></u><?php endif; ?><span><?= $r['pct_budget'] !== null ? $r['pct_budget'] . ' % ' . tt('eng.used_lbl') . ($r['extra'] > 0 ? ' · ' . e(t('eng.allowed_to', ['p' => (int) round($r['allowed'] / $r['budget'] * 100)])) : '') : tt('eng.no_budget_s') ?></span></div></div>
      <?php if ($r['disciplines']): ?><p class="hint" style="margin:2px 0 0;font-size:11px;"><?= e(implode(' · ', $r['disciplines'])) ?><?= $bgCur ? ' — ' . tt('bg.tab') . ' R' . (int)$bgCur['revision'] : '' ?></p><?php endif; ?>
      <?php if ($lead): ?><p class="hint" style="margin:4px 0;"><?= tt('eng.lead') ?>: <?= e($lead) ?></p><?php endif; ?>
      <?php if ($r['done']): ?><p class="hint" style="margin:4px 0;">✔ <?= tt('eng.done_on') ?> <?= e(fmt_datetime((string)$r['b']['done_at'])) ?><?= $r['b']['done_note'] ? ' — ' . e($r['b']['done_note']) : '' ?></p><?php endif; ?>
      <?php if ($r['pending']): ?><p class="hint" style="margin:4px 0;">⏳ <?= e(t('eng.pending_line', ['n' => $r['pending']['n'], 'h' => $fh($r['pending']['h'])])) ?></p><?php endif; ?>
      <ul class="eng-dl">
        <?php foreach ($r['deliverables'] as $d): ?><li class="<?= $d['status'] === 'done' ? 'is-done' : '' ?>">
          <?php if ($canE && !$r['done']): ?><form method="post" action="<?= $backTo ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_dl_toggle"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="dl_id" value="<?= (int)$d['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('eng.toggle') ?>"><?= $d['status'] === 'done' ? '☑' : '☐' ?></button></form><?php else: ?><?= $d['status'] === 'done' ? '☑' : '☐' ?><?php endif; ?>
          <span><?= e($d['title']) ?></span> <span class="hint"><?= e($fh((float)$d['weight_h'])) ?> h<?= $d['status'] === 'done' && $d['done_name'] ? ' · ' . e($d['done_name']) . ' ' . e(fmt_date((string)$d['done_at'])) : '' ?></span>
          <?php if ($canE && !$r['done']): ?> <a class="linkbtn hint" href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering', 'dl' => (int)$d['id']]) ?>#dl-form">✎</a><form method="post" action="<?= $backTo ?>" class="inline" data-lock data-confirm="<?= tt('eng.dl_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eng_dl_delete"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="dl_id" value="<?= (int)$d['id'] ?>"><button class="linkbtn hint" type="submit">×</button></form><?php endif; ?>
        </li><?php endforeach; ?>
        <?php if (!$r['deliverables']): ?><li class="hint"><?= tt('eng.no_dl') ?></li><?php endif; ?>
      </ul>
      <?php if ($canE): ?><div class="btn-row eng-actions">
        <?php if (!$r['done']): ?><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering', 'dl' => 0, 'sec' => $did]) ?>#dl-form">+ <?= tt('eng.dl_add') ?></a>
        <a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering', 'req' => $did]) ?>#req-form">⏱ <?= tt('eng.request') ?></a>
        <form method="post" action="<?= $backTo ?>" class="inline" data-lock data-confirm="<?= tt('eng.done_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('eng.declare_done') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="eng_done"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="section_id" value="<?= $did ?>"><input type="hidden" name="done" value="1"><?php if (array_filter($r['deliverables'], fn($x) => $x['status'] !== 'done')): ?><input name="note" maxlength="500" placeholder="<?= tt('eng.done_note_ph') ?>" style="width:100%;margin-bottom:4px;" required><?php endif; ?><button class="btn btn--gold btn--sm" type="submit">✔ <?= tt('eng.declare_done') ?></button></form>
        <?php else: ?><form method="post" action="<?= $backTo ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_done"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="section_id" value="<?= $did ?>"><input type="hidden" name="done" value="0"><button class="btn btn--ghost btn--sm" type="submit">↺ <?= tt('eng.reopen') ?></button></form><?php endif; ?>
      </div><?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($notIn): ?><p class="hint" style="margin:8px 0 0;"><?= tt('eng.not_in_scope') ?>: <?= e(implode(', ', array_column($notIn, 'name'))) ?></p><?php endif; ?>
  <?php endif; ?>
  <p class="hint" style="margin:8px 0 0;"><?= tt('eng.rules') ?></p>
</div>

<?php if ($canB): ?>
<div class="card" id="eng-budget">
  <h2><?= tt('eng.budget_title') ?></h2>
  <form method="post" action="<?= $backTo ?>#eng-budget" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_budget"><input type="hidden" name="subproject_id" value="<?= $id ?>">
    <div class="table-scroll"><table class="tbl" style="max-width:900px;"><thead><tr><th><?= tt('eng.section') ?></th><th><?= tt('eng.stage') ?></th><th><?= tt('eng.sec_mapped') ?></th><th class="num"><?= tt('eng.budget') ?> (h)</th><th class="num"><?= tt('eng.extra') ?></th><th class="num"><?= tt('eng.actual') ?></th><th><?= tt('eng.lead') ?></th></tr></thead><tbody>
    <?php $BH = $bgOn ? (eng_budget_hours($id) ?? []) : []; foreach ($sec as $did => $d): $r = $rows[$did] ?? null; $bh = $r ? $r['budget'] : (float)($BH[$did] ?? 0); ?><tr><td><b><?= e($d['name']) ?></b></td><td class="hint"><?= e(t('st.' . $d['stage'])) ?></td><td class="hint"><?= e(implode(', ', $d['disciplines'])) ?: '—' ?></td>
      <td class="num"><?php if ($bgOn): ?><?= $bh > 0 ? e($fh($bh)) : '—' ?><?php else: ?><input name="h[<?= $did ?>]" inputmode="decimal" value="<?= $bh > 0 ? e($fh($bh)) : '' ?>" style="width:90px;text-align:right;"<?= $r && $r['done'] ? ' readonly' : '' ?>><?php endif; ?></td>
      <td class="num hint"><?= $r && $r['extra'] > 0 ? '<b style="color:var(--gold-text);">+' . e($fh($r['extra'])) . '</b>' : '—' ?></td><td class="num hint<?= $r && $r['over_h'] > 0 ? ' bad' : '' ?>"><?= $r ? e($fh($r['actual'])) : '—' ?></td><td><select name="lead[<?= $did ?>]"><option value="">—</option><?php foreach ($team as $tm): ?><option value="<?= (int)$tm['id'] ?>"<?= $r && (int)$r['lead_user_id'] === (int)$tm['id'] ? ' selected' : '' ?>><?= e($tm['full_name']) ?></option><?php endforeach; ?></select></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($bgOn): ?><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'budget']) ?>#bg-edit">€ <?= tt('bg.tab') ?><?= $bgCur ? ' R' . (int)$bgCur['revision'] : '' ?> ›</a><?php endif; ?><span class="hint"><?= $bgOn ? tt('eng.budget_hint_bg') : tt('eng.budget_hint') ?></span></div>
  </form>
</div>
<?php endif; ?>

<?php if ($canE && (isset($_GET['dl']) || isset($_GET['sec']))): $ed = $editDl ? one('SELECT * FROM eng_deliverables WHERE id = ? AND subproject_id = ?', [$editDl, $id]) : null; $secId = (int)($ed['section_id'] ?? ($_GET['sec'] ?? 0)); ?>
<div class="card" id="dl-form">
  <h2><?= $ed ? tt('eng.dl_edit') : tt('eng.dl_add') ?></h2>
  <form method="post" action="<?= $backTo ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_dl_save"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="dl_id" value="<?= (int)($ed['id'] ?? 0) ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('eng.section') ?></label><select name="section_id" data-nosearch<?= $ed ? ' disabled' : '' ?>><?php foreach ($sec as $did => $d): ?><option value="<?= $did ?>"<?= $did === $secId ? ' selected' : '' ?>><?= e($d['name'] . ' · ' . t('st.' . $d['stage'])) ?></option><?php endforeach; ?></select><?php if ($ed): ?><input type="hidden" name="section_id" value="<?= $secId ?>"><?php endif; ?></div>
      <div class="field"><label><?= tt('eng.dl_title') ?> *</label><input name="title" maxlength="160" required value="<?= e((string)($ed['title'] ?? '')) ?>" placeholder="<?= tt('eng.dl_ph') ?>"></div>
      <div class="field"><label><?= tt('eng.dl_weight') ?></label><input name="weight_h" inputmode="decimal" value="<?= $ed ? e($fh((float)$ed['weight_h'])) : '' ?>" placeholder="8"><p class="hint"><?= tt('eng.dl_weight_hint') ?></p></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering']) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php endif; ?>

<div class="card" id="req-form">
  <div class="card-head"><h2>⏱ <?= tt('eng.requests') ?> <span class="hint">(<?= count($reqs) ?>)</span></h2><span class="hint"><?= tt('eng.requests_hint') ?></span></div>
  <?php if ($reqs): ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('eng.section') ?></th><th class="num"><?= tt('eng.hours') ?></th><th><?= tt('eng.reason_kind') ?></th><th><?= tt('eng.reason') ?></th><th><?= tt('eng.requested_by') ?></th><th><?= tt('th.status') ?></th><th><?= tt('eng.decision') ?></th><?php if ($mgmt || $canDecideSite): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($reqs as $q): ?><tr><td><b><?= e($q['section']) ?></b></td><td class="num">+<?= e($fh((float)$q['hours'])) ?></td><td><?= e(t('eng.r_' . $q['reason_kind'])) ?></td><td><?= e($q['reason']) ?></td><td class="hint"><?= e($q['requested_name']) ?><br><?= e(fmt_datetime($q['requested_at'])) ?></td><td><?= pill($q['status'] === 'approved' ? 'green' : ($q['status'] === 'rejected' ? 'bad' : 'gold'), t('eng.q_' . $q['status'])) ?></td><td class="hint"><?= $q['decided_name'] ? e($q['decided_name']) . ' · ' . e(fmt_datetime($q['decided_at'])) . ($q['decision_note'] ? '<br>' . e($q['decision_note']) : '') : '—' ?></td>
    <?php if ($mgmt || $canDecideSite): ?><td class="row-actions"><?php if ($q['status'] === 'pending' && eng_can_decide($sp, (string)$q['stage'])): ?><form method="post" action="<?= $backTo ?>#req-form" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_decide"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="req_id" value="<?= (int)$q['id'] ?>"><input name="note" maxlength="500" placeholder="<?= tt('eng.decision_ph') ?>" style="width:160px;"> <button class="btn btn--gold btn--sm" name="decision" value="approve" type="submit">✔</button> <button class="btn btn--ghost btn--sm" name="decision" value="reject" type="submit">✖</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php else: ?><p class="hint"><?= tt('eng.no_requests') ?></p><?php endif; ?>
  <?php if ($staff): $reqSec = (int)($_GET['req'] ?? 0); ?>
  <details class="pd-add" style="margin-top:10px;"<?= $reqSec ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm">+ <?= tt('eng.request') ?></summary>
    <form method="post" action="<?= $backTo ?>#req-form" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="eng_request"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('eng.section') ?></label><select name="section_id" data-nosearch><?php foreach ($sec as $did => $d): ?><option value="<?= $did ?>"<?= $did === $reqSec ? ' selected' : '' ?>><?= e($d['name'] . ' · ' . t('st.' . $d['stage'])) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('eng.hours') ?> *</label><input name="hours" inputmode="decimal" required placeholder="16"></div>
        <div class="field"><label><?= tt('eng.reason_kind') ?></label><select name="reason_kind" data-nosearch><?php foreach (ENG_REASONS as $k): ?><option value="<?= $k ?>"><?= e(t('eng.r_' . $k)) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="grid-column: 1 / -1;"><label><?= tt('eng.reason') ?> *</label><textarea name="reason" rows="2" data-plain required placeholder="<?= tt('eng.reason_ph') ?>"></textarea></div>
      </div>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('eng.send_request') ?></button><span class="hint"><?= tt('eng.request_hint') ?></span></div>
    </form>
  </details>
  <?php endif; ?>
</div>

<?php
/** One work package — details, people, planning days, timesheet hours, status actions (GAS-CP-R35-001). ?p=work&id= */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $w = wp_has() ? wp_get($id) : null;
if (!$w) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$canM = wp_can_manage((int)$w['project_id']); $mine = wp_is_mine($w); $self = url('work', ['id' => $id]);
$myRow = null; foreach ($w['people_rows'] as $p) if ((int)$p['user_id'] === (int)$u['id']) $myRow = $p;
$pct = (float)$w['planned_hours'] > 0 ? min(100, (int) round((float)$w['actual_h'] / (float)$w['planned_hours'] * 100)) : 0;
layout_head($w['wp_no'] . ' · ' . $w['title'], 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$w['project_id'], $w['project_code'], $w['project_name']) ?> › <a href="<?= url('subproject', ['id' => $w['subproject_id'], 'tab' => 'works']) ?>"><?= e(trim($w['sub_code'] . ' ' . $w['sub_name'])) ?></a> › <?= e($w['wp_no']) ?></p>
<div class="page-head">
  <div><h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($w['wp_no']) ?></span> <?= e($w['title']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($w['vessel'] ?: '') ?><?= $w['discipline'] ? ' · ' . e($w['discipline']) : '' ?><?= $w['area'] ? ' · ' . e($w['area']) : '' ?><?= $w['start_date'] ? ' · ' . e(fmt_date($w['start_date'])) . ($w['end_date'] && $w['end_date'] !== $w['start_date'] ? ' – ' . e(fmt_date($w['end_date'])) : '') : '' ?> · <?= e(bg_h_safe((float)$w['planned_hours'])) ?> h <?= tt('wp.planned_l') ?></p></div>
  <div class="actions"><?= wp_status_pill($w['status']) ?><?= (int)$w['priority'] === 1 ? ' ' . pill('bad', t('wp.p_high')) : '' ?>
    <?php if ($canM): ?> <a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $w['subproject_id'], 'tab' => 'works', 'work' => $id]) ?>#wp-form">✎ <?= tt('btn.edit') ?></a><?php endif; ?>
    <?php if (function_exists('pd_has') && pd_has()): if ($po = pd_for_work($id)): ?> <a class="btn btn--ghost btn--sm" href="<?= url('production_order', ['id' => (int)$po['id']]) ?>">🏭 <?= e($po['po_no']) ?> · <?= (int)$po['pct'] ?> %</a><?php elseif (pd_can_create((int)$w['project_id']) && $w['status'] !== 'cancelled'): ?> <a class="btn btn--ghost btn--sm" href="<?= url('production_edit', ['work' => $id]) ?>">🏭 + <?= tt('pd.one') ?></a><?php endif; endif; ?></div>
</div>

<div class="cols">
  <div class="card">
    <h2><?= tt('wp.work') ?></h2>
    <?php if ($w['description']): ?><?= rt_html($w['description']) ?><?php else: ?><p class="hint"><?= tt('wp.no_desc') ?></p><?php endif; ?>
    <div class="bg-rows" style="margin-top:10px;"><div><span><?= tt('wp.hours_p_a') ?></span><?= function_exists('bg_bar') ? bg_bar($pct, $pct >= 100 ? 'amber' : 'green', null, '12px') : '' ?><b><?= e(bg_h_safe((float)$w['actual_h'])) ?> / <?= e(bg_h_safe((float)$w['planned_hours'])) ?></b></div></div>
    <?php if ($myRow && !$myRow['acknowledged_at'] && in_array($w['status'], ['assigned', 'in_progress'], true)): ?>
      <form method="post" action="<?= $self ?>" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="wp_ack"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('wp.ack') ?></button> <span class="hint"><?= tt('wp.ack_hint') ?></span></form>
    <?php endif; ?>
    <div class="btn-row" style="margin-top:12px;">
      <?php $st = $w['status']; $btn = function (string $to, string $cls = 'btn--ghost') use ($self, $id) { return '<form method="post" action="' . $self . '" class="inline">' . csrf_field() . '<input type="hidden" name="action" value="wp_status"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="' . $to . '"><button class="btn btn--sm ' . $cls . '" type="submit">' . e(t('wp.to_' . $to)) . '</button></form>'; }; ?>
      <?php if (($mine || $canM) && in_array($st, ['planned', 'assigned'], true)) echo $btn('in_progress', 'btn--gold'); ?>
      <?php if (($mine || $canM) && $st === 'in_progress') echo $btn('done', 'btn--gold'); ?>
      <?php if ($canM && $st === 'done') echo $btn('checked', 'btn--gold'); ?>
      <?php if ($canM && in_array($st, ['done', 'checked'], true)) echo $btn('in_progress'); ?>
      <?php if ($canM && in_array($st, ['planned', 'assigned', 'in_progress'], true)) echo $btn('cancelled'); ?>
      <?php if ($canM && $st === 'cancelled') echo $btn('assigned'); ?>
      <?php if ($mine): ?><a class="btn btn--ghost btn--sm" href="<?= url('timesheet_edit', ['project' => $w['project_id'], 'from_plan' => 1]) ?>">⏱ <?= tt('wp.book_hours') ?></a><?php endif; ?>
    </div>
    <?php if ($w['checked_at']): ?><p class="hint" style="margin-top:8px;"><?= e(t('wp.checked_line', ['d' => fmt_datetime($w['checked_at'])])) ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('wp.people') ?> <span class="count"><?= count($w['people_rows']) ?></span></h2>
    <?php if (!$w['people_rows']): ?><p class="hint"><?= tt('wp.nobody') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('wv.by') ?></th><th><?= tt('bg.discipline') ?></th><th class="num"><?= tt('wp.hours_p_a') ?></th><th><?= tt('wp.acked') ?></th></tr></thead><tbody>
    <?php foreach ($w['people_rows'] as $p): ?><tr><td><b><?= e($p['full_name']) ?></b><?= (int)$p['user_id'] === (int)$w['lead_user_id'] ? ' ' . pill('gold', t('wp.lead')) : '' ?></td><td><?= e($p['discipline'] ?? '') ?></td><td class="num"><?= $p['hours_planned'] !== null ? e(bg_h_safe((float)$p['hours_planned'])) : '—' ?> / <b><?= e(bg_h_safe((float)$p['actual_h'])) ?></b></td><td><?= $p['acknowledged_at'] ? pill('green', fmt_date($p['acknowledged_at'])) : pill('mute', t('wp.not_acked')) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <h3 class="ed-h" style="margin-top:14px;"><?= tt('wp.plan_days') ?> <span class="hint"><?= count($w['plan']) ?></span></h3>
    <?php if (!$w['plan']): ?><p class="hint"><?= tt('wp.no_plan') ?></p><?php else: $byDay = []; foreach ($w['plan'] as $pe) $byDay[$pe['plan_date']][] = $pe; ?>
    <div class="wp-days"><?php foreach ($byDay as $date => $es): ?><span class="wp-day" title="<?= e(implode(', ', array_map(fn($x) => $x['full_name'] . ($x['hours'] !== null ? ' ' . bg_h_safe((float)$x['hours']) . 'h' : ''), $es))) ?>"><?= e(date('D d.m', strtotime($date))) ?> <b><?= count($es) ?></b></span><?php endforeach; ?></div>
    <p class="hint" style="margin:6px 0 0;"><a href="<?= url('planning', ['week' => $w['start_date'] ?: date('Y-m-d')]) ?>"><?= tt('pl.title') ?> ›</a></p>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt('wp.hours_booked') ?> <span class="count"><?= count($w['lines']) ?></span></h2>
  <?php if (!$w['lines']): ?><p class="hint"><?= tt('wp.no_lines') ?></p><?php else: ?>
  <table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('wv.by') ?></th><th><?= tt('ts.type') ?></th><th class="num"><?= tt('ts.hours') ?></th><th><?= tt('ts.what') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($w['lines'] as $l): ?><tr><td><?= e(fmt_date($l['work_date'])) ?></td><td><?= e($l['user_name']) ?></td><td><?= e(t('ts.' . $l['hour_type'])) ?></td><td class="num"><?= e(bg_h_safe((float)$l['hours'])) ?></td><td class="hint"><?= e($l['description'] ?? '') ?></td><td><a href="<?= url('timesheet', ['id' => $l['ts_id']]) ?>"><?= ts_status_pill($l['status']) ?></a></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

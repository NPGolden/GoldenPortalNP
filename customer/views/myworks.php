<?php
/** Work › My works — the engineer's assigned work packages, this week's plan, quick actions (GAS-CP-R35-001). ?p=myworks[&view=open|done] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!wp_has()) redirect('tasks');
$view = ($_GET['view'] ?? 'open') === 'done' ? 'done' : 'open';
$works = wp_list(['user' => (int)$u['id']] + ($view === 'open' ? ['status' => 'open'] : []));
if ($view === 'done') $works = array_values(array_filter($works, fn($w) => in_array($w['status'], ['done', 'checked'], true)));
$ws = date('Y-m-d', strtotime('monday this week')); $we = date('Y-m-d', strtotime($ws . ' +6 days'));
$grid = function_exists('pl_grid') ? pl_grid($ws, $we, (int)$u['id']) : []; $week = $grid[(int)$u['id']] ?? [];
$ack = []; foreach (all('SELECT work_id, acknowledged_at FROM sp_work_people WHERE user_id = ?', [(int)$u['id']]) as $r) $ack[(int)$r['work_id']] = $r['acknowledged_at'];
layout_head(t('wp.my'), 'myworks');
?>
<div class="page-head"><div><h1><?= tt('wp.my') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('wp.my_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--sm <?= $view === 'open' ? 'is-on' : 'btn--ghost' ?>" href="<?= url('myworks') ?>"><?= tt('wp.v_open') ?></a><a class="btn btn--sm <?= $view === 'done' ? 'is-on' : 'btn--ghost' ?>" href="<?= url('myworks', ['view' => 'done']) ?>"><?= tt('wp.v_done') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('planning') ?>"><?= tt('pl.title') ?> ›</a></div></div>

<div class="card">
  <h2><?= tt('wp.this_week') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(fmt_date($ws)) ?> – <?= e(fmt_date($we)) ?></span></h2>
  <div class="wp-week">
    <?php for ($d = strtotime($ws); $d <= strtotime($we); $d += 86400): $date = date('Y-m-d', $d); $es = $week[$date] ?? []; ?>
      <div class="wp-wd<?= $date === date('Y-m-d') ? ' is-today' : '' ?>"><div class="wp-wd-h"><?= e(date('D', $d)) ?> <small><?= e(date('d.m', $d)) ?></small></div>
        <?php if (!$es): ?><div class="hint">—</div><?php else: foreach ($es as $e): ?><div class="wp-chip wp-chip--<?= function_exists('pl_tone') ? e(pl_tone($e)) : 'o' ?>"><?= e(function_exists('pl_label') ? pl_label($e, true) : ($e['note'] ?? $e['kind'])) ?><?= $e['hours'] !== null ? ' <b>' . e(bg_h_safe((float)$e['hours'])) . 'h</b>' : '' ?></div><?php endforeach; endif; ?>
      </div>
    <?php endfor; ?>
  </div>
</div>

<div class="card">
  <h2><?= tt($view === 'open' ? 'wp.v_open' : 'wp.v_done') ?> <span class="count"><?= count($works) ?></span></h2>
  <?php if (!$works): ?><p class="hint"><?= tt('wp.none_mine') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl wp-tbl"><thead><tr><th><?= tt('wp.no') ?></th><th><?= tt('wp.work') ?></th><th><?= tt('v.one') ?> · <?= tt('sp.one') ?></th><th><?= tt('wp.dates') ?></th><th class="num"><?= tt('wp.hours_p_a') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($works as $w): $pct = (float)$w['planned_hours'] > 0 ? min(100, (int) round((float)$w['actual_h'] / (float)$w['planned_hours'] * 100)) : 0; $late = $w['end_date'] && $w['end_date'] < date('Y-m-d') && in_array($w['status'], WP_OPEN, true); ?>
    <tr><td class="mono"><a href="<?= url('work', ['id' => $w['id']]) ?>"><b><?= e($w['wp_no']) ?></b></a><?= (int)$w['priority'] === 1 ? ' ' . pill('bad', t('wp.p_high')) : '' ?></td>
      <td><a href="<?= url('work', ['id' => $w['id']]) ?>"><?= e($w['title']) ?></a><?= empty($ack[(int)$w['id']]) && in_array($w['status'], ['assigned', 'in_progress'], true) ? ' ' . pill('gold', t('wp.new_badge')) : '' ?></td>
      <td><?= e($w['vessel'] ?: $w['project_code']) ?> · <a href="<?= url('subproject', ['id' => $w['subproject_id'], 'tab' => 'works']) ?>"><?= e(trim($w['sub_code'] . ' ' . $w['sub_name'])) ?></a></td>
      <td class="<?= $late ? 'bg-neg' : 'hint' ?>"><?= $w['start_date'] ? e(fmt_date($w['start_date'])) . ($w['end_date'] && $w['end_date'] !== $w['start_date'] ? ' – ' . e(fmt_date($w['end_date'])) : '') : '—' ?></td>
      <td class="num"><?= e(bg_h_safe((float)$w['planned_hours'])) ?> / <b><?= e(bg_h_safe((float)$w['actual_h'])) ?></b><?= function_exists('bg_bar') ? bg_bar($pct, $pct >= 100 ? 'amber' : 'green', null, '6px') : '' ?></td>
      <td><?= wp_status_pill($w['status']) ?></td>
      <td class="ob-act"><?php if (in_array($w['status'], ['planned', 'assigned'], true)): ?><form method="post" action="<?= url('myworks') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="wp_status"><input type="hidden" name="id" value="<?= $w['id'] ?>"><input type="hidden" name="status" value="in_progress"><button class="btn btn--ghost btn--sm" type="submit">▶ <?= tt('wp.to_in_progress') ?></button></form><?php elseif ($w['status'] === 'in_progress'): ?><form method="post" action="<?= url('myworks') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="wp_status"><input type="hidden" name="id" value="<?= $w['id'] ?>"><input type="hidden" name="status" value="done"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('wp.to_done') ?></button></form><?php endif; ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

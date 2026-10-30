<?php
/** A remote support contract — details, hours, sessions, tickets covered, tasks (GAS-CP-R30-004). */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$id = (int)($_GET['id'] ?? 0);
rs_sweep();
$c = rs_get($id);
if (!$c) { http_response_code(404); layout_head(t('err.notfound'), 'cyber'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$tone = rs_expiry_tone($c); $d = rs_days_left($c['end_date']);
$h = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$pct = $c['hours_included'] ? min(100, (int) round((float)$c['hours_used'] / (float)$c['hours_included'] * 100)) : null;
layout_head($c['contract_no'] . ' — ' . $c['vessel'], 'cyber');
?>
<p class="eyebrow"><a href="<?= url('cyber') ?>"><?= tt('cy.title') ?></a> › <a href="<?= url('vessel', ['id' => $c['vessel_id']]) ?>"><?= e($c['vessel']) ?></a> › <?= e($c['contract_no']) ?></p>
<div class="page-head">
  <div><h1>🛡 <?= e($c['contract_no']) ?> <span class="hint" style="font-weight:400;"><?= e($c['title']) ?></span></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($c['vessel']) ?><?= $c['imo'] ? ' · IMO ' . e($c['imo']) : '' ?><?= $c['customer_name'] ? ' · ' . e($c['customer_name']) : '' ?> · <?= e(fmt_date($c['start_date'])) ?> – <?= $c['end_date'] ? e(fmt_date($c['end_date'])) : tt('rs.open_ended') ?><?php if ($d !== null && $c['status'] === 'active'): ?> · <span class="rs-days rs-<?= $tone ?>"><?= e($d < 0 ? t('rs.expired_ago', ['n' => -$d]) : t('rs.days_left', ['n' => $d])) ?></span><?php endif; ?></p></div>
  <div class="actions"><?= rs_status_pill($c['status']) ?> <?= $c['visibility'] === 'internal' ? pill('navy', t('rs.internal')) : '' ?>
    <?php if ($staff): ?><a class="btn btn--ghost btn--sm" href="<?= url('rs_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a>
      <?php if (function_exists('td_has') && td_has()): ?><a class="btn btn--ghost btn--sm" href="<?= url('task_edit', ['rs' => $id, 'vessel' => $c['vessel_id']]) ?>">+ <?= tt('td.new') ?></a><?php endif; ?>
      <a class="btn btn--ghost btn--sm" href="<?= url('ticket_edit', ['vessel' => $c['vessel_id']]) ?>">+ <?= tt('tk.new') ?></a><?php endif; ?></div>
</div>

<div class="cols">
  <div class="card">
    <h2><?= tt('rs.details') ?></h2>
    <div class="kv-grid">
      <div class="kv"><span><?= tt('rs.hours_included') ?></span><b><?= $c['hours_included'] !== null ? $h($c['hours_included']) . ' h' : tt('rs.unlimited') ?></b></div>
      <div class="kv"><span><?= tt('rs.hours_used_l') ?></span><b><?= $h($c['hours_used']) ?> h<?= $pct !== null ? ' (' . $pct . ' %)' : '' ?></b></div>
      <div class="kv"><span><?= tt('rs.sla') ?></span><b><?= $c['response_hours'] !== null ? e(t('rs.sla_short', ['h' => (int)$c['response_hours']])) : '—' ?></b></div>
      <div class="kv"><span><?= tt('rs.coverage') ?></span><b><?= e(rs_coverages()[$c['coverage']] ?? $c['coverage']) ?></b></div>
      <div class="kv"><span><?= tt('rs.access') ?></span><b><?= e($c['access_method'] ?: '—') ?></b></div>
      <div class="kv"><span><?= tt('rs.renewal') ?></span><b><?= $c['renewal'] === 'auto' ? tt('rs.renew_auto') : tt('rs.renew_manual') ?> · <?= e(t('rs.notice_days', ['n' => (int)$c['renew_notice_days']])) ?></b></div>
      <?php if ($staff && function_exists('fin_visible') && fin_visible()): ?><div class="kv"><span><?= tt('rs.fee') ?></span><b><?= $c['fee'] !== null ? e(number_format((float)$c['fee'], 2, ',', '.') . ' ' . $c['currency']) . ' · ' . e(rs_billings()[$c['billing']] ?? $c['billing']) : '—' ?></b></div><?php endif; ?>
    </div>
    <?php if ($pct !== null): ?><div class="rs-bar"><span style="width:<?= $pct ?>%;" class="rs-bar-fill rs-<?= $pct >= 100 ? 'red' : ($pct >= 80 ? 'amber' : 'green') ?>"></span></div><?php endif; ?>
    <?php if ($c['scope']): ?><h3 class="ed-h" style="margin-top:14px;"><?= tt('rs.scope') ?></h3><?= rt_html($c['scope']) ?><?php endif; ?>
    <?php if ($staff && $c['notes']): ?><h3 class="ed-h" style="margin-top:14px;"><?= tt('rs.notes_int') ?></h3><div class="hint"><?= rt_html($c['notes']) ?></div><?php endif; ?>
  </div>
  <div class="card">
    <div class="card-head"><h2><?= tt('rs.sessions') ?> <span class="count"><?= count($c['sessions']) ?></span></h2></div>
    <?php if ($staff): ?>
    <form method="post" action="<?= url('rs', ['id' => $id]) ?>" class="grid grid-2" style="align-items:end;margin-bottom:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="rs_session_add"><input type="hidden" name="contract_id" value="<?= $id ?>"><input type="hidden" name="back" value="rs:<?= $id ?>">
      <div class="field" style="margin:0;"><label><?= tt('th.date') ?></label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
      <div class="field" style="margin:0;"><label><?= tt('rs.hours') ?></label><input type="number" name="hours" step="0.25" min="0" max="99" value="1"></div>
      <div class="field" style="margin:0;"><label><?= tt('tk.one') ?></label><select name="ticket_id"><option value="">—</option><?php foreach ($c['tickets'] as $t): ?><option value="<?= $t['id'] ?>"><?= e($t['ticket_no'] . ' — ' . mb_substr($t['title'], 0, 40)) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0;"><label><?= tt('th.remarks') ?></label><input name="note" maxlength="255" placeholder="<?= tt('rs.session_ph') ?>"></div>
      <div class="btn-row"><button class="btn btn--sm" type="submit">+ <?= tt('rs.log_session') ?></button></div>
    </form>
    <?php endif; ?>
    <?php if (!$c['sessions']): ?><p class="hint"><?= tt('rs.no_sessions') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('rs.hours') ?></th><th><?= tt('rs.engineer') ?></th><th><?= tt('tk.one') ?></th><th><?= tt('th.remarks') ?></th><?php if ($staff): ?><th></th><?php endif; ?></tr></thead><tbody>
      <?php foreach ($c['sessions'] as $x): ?><tr><td><?= e(fmt_date($x['session_date'])) ?></td><td><?= $h($x['hours']) ?> h</td><td><?= e($x['engineer_name'] ?? '') ?></td><td><?= $x['ticket_id'] ? '<a href="' . url('ticket', ['id' => $x['ticket_id']]) . '">' . e($x['ticket_no']) . '</a>' : '—' ?></td><td class="hint"><?= e($x['note'] ?? '') ?></td>
        <?php if ($staff): ?><td style="text-align:right;"><form method="post" action="<?= url('rs', ['id' => $id]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rs_session_delete"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="back" value="rs:<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td><?php endif; ?></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
</div>

<div class="cols">
  <div class="card">
    <div class="card-head"><h2><?= tt('rs.tickets_covered') ?> <span class="count"><?= count($c['tickets']) ?></span></h2><a class="btn btn--ghost btn--sm" href="<?= url('tickets', ['view' => 'all', 'vessel' => $c['vessel_id']]) ?>"><?= tt('nav.tickets') ?> →</a></div>
    <?php if (!$c['tickets']): ?><p class="hint"><?= tt('rs.no_tickets') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('tk.no') ?></th><th><?= tt('tk.title_f') ?></th><th><?= tt('th.date') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
      <?php foreach ($c['tickets'] as $t): ?><tr><td><a href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['ticket_no']) ?></a></td><td><?= e($t['title']) ?></td><td><?= e(fmt_date($t['created_at'])) ?></td><td><?= tk_status_pill($t['status']) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <?php if ($staff): ?>
  <div class="card">
    <div class="card-head"><h2><?= tt('td.title') ?> <span class="count"><?= count($c['tasks']) ?></span></h2><?php if (function_exists('td_has') && td_has()): ?><a class="btn btn--ghost btn--sm" href="<?= url('task_edit', ['rs' => $id, 'vessel' => $c['vessel_id']]) ?>">+ <?= tt('td.new') ?></a><?php endif; ?></div>
    <?php if (!$c['tasks']): ?><p class="hint"><?= tt('rs.no_tasks') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('td.title_f') ?></th><th><?= tt('td.due_date') ?></th><th><?= tt('td.assignee') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
      <?php foreach ($c['tasks'] as $k): ?><tr><td><a href="<?= url('task_edit', ['id' => $k['id']]) ?>"><?= e($k['title']) ?></a> <?= (int)$k['id'] === (int)$c['renewal_task_id'] ? pill('gold', t('rs.renewal_task')) : '' ?></td><td><?= e(fmt_date($k['due_at'])) ?></td><td><?= e($k['assignee'] ?: '—') ?></td><td><?= pill($k['status'] === 'open' ? ($k['due_at'] < date('Y-m-d H:i:s') ? 'bad' : 'navy') : ($k['status'] === 'done' ? 'green' : 'mute'), t('rs.ts_' . $k['status'])) ?></td></tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

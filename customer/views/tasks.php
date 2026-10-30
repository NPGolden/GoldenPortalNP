<?php
/** Tasks and reminders — Work › Tasks (staff).   GAS-CP-R22-002
 *   ?p=tasks[&view=myday|mine|planned|done|all][&project=&sub=&kind=&who=&q=][&hl=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!td_has()) { layout_head(t('td.title'), 'tasks'); echo '<h1>' . tt('td.title') . '</h1><div class="flash flash--warn">' . tt('td.need_r22') . '</div>'; layout_foot(); return; }
$view = (string)($_GET['view'] ?? 'myday'); if (!in_array($view, ['myday', 'mine', 'planned', 'done', 'all'], true)) $view = 'myday';
$f = ['view' => $view, 'project' => (int)($_GET['project'] ?? 0), 'sub' => (int)($_GET['sub'] ?? 0), 'kind' => (string)($_GET['kind'] ?? ''), 'who' => (int)($_GET['who'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = td_list($f); $hl = (int)($_GET['hl'] ?? 0);
$keep = array_filter($f);
$projects = all("SELECT id, project_code, name FROM projects WHERE status = 'active' ORDER BY project_code, name");
$subs = $f['project'] ? all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$f['project']]) : [];
$people = staff_users();
$nMy = td_badge(); $nOpen = (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open'"); $nOver = (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_at < NOW()");
$nWeek = (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)"); $nRec = (int) scalar("SELECT COUNT(*) FROM tasks WHERE status = 'open' AND recur <> 'none'");
$tabs = [['myday', t('td.v_myday'), $nMy], ['mine', t('td.v_mine'), null], ['planned', t('td.v_planned'), $nOpen], ['done', t('td.v_done'), null], ['all', t('flt.all'), null]];
$bucketLabels = ['overdue' => t('td.b_overdue'), 'today' => t('td.b_today'), 'tomorrow' => t('td.b_tomorrow'), 'week' => t('td.b_week'), 'month' => t('td.b_month'), 'later' => t('td.b_later')];
$groups = [];
foreach ($rows as $r) { $k = $view === 'done' ? 'done' : td_bucket($r['due_at']); $groups[$k][] = $r; }
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('td.title'), 'tasks');
?>
<div class="page-head">
  <div><h1><?= tt('td.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('td.lede') ?></p></div>
  <div class="btn-row"><?php if (is_cp_admin()): ?><a class="btn btn--ghost" href="<?= url('tasks', $keep + ['seed' => 1]) ?>#seed">↻ <?= tt('td.seed_btn') ?></a><?php endif; ?><a class="btn btn--gold" href="<?= url('task_edit', $f['project'] ? ['project' => $f['project']] : []) ?>">+ <?= tt('td.new') ?></a></div>
</div>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <a class="stat<?= $nMy ? ' gold' : '' ?>" href="<?= url('tasks', ['view' => 'myday']) ?>"><b><?= $nMy ?></b><?= tt('td.k_myday') ?></a>
  <a class="stat<?= $nOver ? ' gold' : '' ?>" href="<?= url('tasks', ['view' => 'planned']) ?>"><b><?= $nOver ?></b><?= tt('td.b_overdue') ?></a>
  <a class="stat" href="<?= url('tasks', ['view' => 'planned']) ?>"><b><?= $nWeek ?></b><?= tt('td.k_week') ?></a>
  <div class="stat green"><b><?= $nRec ?></b><?= tt('td.k_recurring') ?></div>
</div>

<?php if (isset($_GET['seed']) && is_cp_admin()): ?>
<div class="card" id="seed">
  <h2>↻ <?= tt('td.seed_title') ?></h2>
  <p class="hint"><?= tt('td.seed_hint') ?></p>
  <form method="post" action="<?= url('tasks', $keep) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="td_seed">
    <div class="td-seedgrid">
      <?php foreach ([['weekly', t('td.seed_o_weekly')], ['monthly', t('td.seed_o_monthly')], ['timesheet', t('td.seed_o_timesheet')], ['vessel', t('td.seed_o_vessel')], ['progress', t('td.seed_o_progress')]] as [$k, $lab]): ?>
        <label class="chk"><input type="checkbox" name="which[]" value="<?= $k ?>" checked> <?= e($lab) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="grid grid-2" style="margin-top:10px;">
      <div class="field"><label for="seed_who"><?= tt('td.seed_assignee') ?></label><select id="seed_who" name="assignee_id"><option value=""><?= tt('td.seed_pm') ?></option><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"><?= e($p['full_name']) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('td.seed_go') ?></button><a class="btn btn--ghost" href="<?= url('tasks', $keep) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php endif; ?>

<nav class="tabs" aria-label="<?= tt('td.title') ?>">
  <?php foreach ($tabs as [$slug, $label, $n]): ?>
    <a class="<?= $view === $slug ? 'is-on' : '' ?>" href="<?= url('tasks', array_diff_key($keep, ['view' => 1]) + ['view' => $slug]) ?>"><?= e($label) ?><?= $n ? ' <span class="n">' . $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1.4fr 1.2fr 1fr 1.2fr auto;">
    <input type="hidden" name="p" value="tasks"><input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('td.search_ph') ?>"></div>
    <div class="field"><label for="project"><?= tt('pr.one') ?></label><select id="project" name="project" data-js-submit="1"><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $sel($f['project'], $p['id']) ?>><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="sub"><?= tt('sp.one') ?></label><select id="sub" name="sub"<?= $subs ? '' : ' disabled' ?>><option value=""><?= tt('flt.any') ?></option><?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($f['sub'], $s['id']) ?>><?= e(trim(($s['code'] ?: '') . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="kind"><?= tt('td.kind') ?></label><select id="kind" name="kind"><option value=""><?= tt('flt.any') ?></option><?php foreach (td_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($f['kind'], $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label for="who"><?= tt('td.assignee') ?></label><select id="who" name="who"><option value=""><?= tt('flt.any') ?></option><?php foreach ($people as $p): ?><option value="<?= $p['id'] ?>"<?= $sel($f['who'], $p['id']) ?>><?= e($p['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('tasks', ['view' => $view, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<?php if (!$rows): ?>
<div class="card"><div class="empty"><?= $view === 'myday' ? tt('td.none_myday') : tt('td.none') ?></div></div>
<?php else: foreach ($groups as $bk => $list): ?>
<div class="card card--tight td-group td-group--<?= e($bk) ?>">
  <h3 class="ac-h" style="margin-top:0;"><?= e($bucketLabels[$bk] ?? t('td.v_done')) ?> <span class="hint"><?= count($list) ?></span></h3>
  <ul class="td-list">
    <?php foreach ($list as $t): $over = $t['status'] === 'open' && $t['due_at'] < date('Y-m-d H:i:s'); $ctx = td_context($t); ?>
    <li class="td-item<?= $over ? ' is-over' : '' ?><?= $t['status'] === 'done' ? ' is-done' : '' ?><?= $hl === (int)$t['id'] ? ' is-hl' : '' ?> td-prio--<?= e($t['priority']) ?>" id="t<?= $t['id'] ?>">
      <form method="post" action="<?= url('tasks', $keep) ?>" class="td-check"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>">
        <?php if ($t['status'] === 'open'): ?><button class="td-tick" type="submit" name="action" value="td_done" title="<?= tt('td.done') ?>" aria-label="<?= tt('td.done') ?>"><span></span></button>
        <?php else: ?><button class="td-tick is-on" type="submit" name="action" value="td_reopen" title="<?= tt('td.reopen') ?>" aria-label="<?= tt('td.reopen') ?>"><span>✓</span></button><?php endif; ?>
      </form>
      <div class="td-main">
        <a class="td-title" href="<?= url('task_edit', ['id' => $t['id']]) ?>"><?= e($t['title']) ?></a>
        <div class="td-meta">
          <?= td_kind_pill($t['kind']) ?>
          <?php if ($ctx): ?><span class="td-ctx"><?= e($ctx) ?></span><?php endif; ?>
          <span class="td-due<?= $over ? ' is-over' : '' ?>">⏰ <?= e(fmt_datetime($t['due_at'])) ?></span>
          <?php if ($t['recur'] !== 'none'): ?><span class="td-rec" title="<?= tt('td.recurrence') ?>">↻ <?= e(td_recur_label($t)) ?></span><?php endif; ?>
          <?php if ((int)$t['remind_before'] > 0): ?><span class="td-rem">🔔 <?= e(td_reminders()[(int)$t['remind_before']] ?? '') ?></span><?php endif; ?>
          <span class="td-who">👤 <?= e($t['assignee'] ?: t('td.team')) ?></span>
          <?php if ($t['priority'] === 'high'): ?><?= pill('red', t('td.p_high')) ?><?php elseif ($t['priority'] === 'low'): ?><?= pill('mute', t('td.p_low')) ?><?php endif; ?>
        </div>
        <?php if ($t['notes']): ?><div class="td-notes"><?= e(mb_substr(preg_replace('/\s+/u', ' ', (string)$t['notes']), 0, 160)) ?><?= mb_strlen((string)$t['notes']) > 160 ? '…' : '' ?></div><?php endif; ?>
      </div>
      <div class="td-actions">
        <?php if ($t['status'] === 'open' && $t['recur'] !== 'none'): ?><form method="post" action="<?= url('tasks', $keep) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" name="action" value="td_skip" title="<?= tt('td.skip_hint') ?>"><?= tt('td.skip') ?></button></form><?php endif; ?>
        <a class="btn btn--ghost btn--sm" href="<?= url('task_edit', ['id' => $t['id']]) ?>"><?= tt('btn.edit') ?></a>
      </div>
    </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endforeach; endif; ?>
<?php layout_foot(); ?>

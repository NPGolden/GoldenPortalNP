<?php
/** Weekly vessel report on screen — the nine sections, the PM's remarks form, PDF / Word buttons.   GAS-CP-R15-001
 *   ?p=weekly_vessel&vessel=ID[&project=ID][&week=YYYY-MM-DD] */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$vid = (int)($_GET['vessel'] ?? 0); $pid = (int)($_GET['project'] ?? 0) ?: null;
if (!$vid && !$pid) redirect('vessels');
$week = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['week'] ?? '')) ? $_GET['week'] : date('Y-m-d');
$W = weekly_data($vid, $pid, $week);
if (!$W) { http_response_code(404); layout_head(t('err.notfound'), 'vessels'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if ($pid && !$W['only_project']) { $pid = null; $W = weekly_data($vid, null, $week); }
$v = $W['vessel']; $ws = $W['week_start']; $vid = (int)$v['id'];
$allProjects = $vid ? all('SELECT id, project_code, name FROM projects WHERE vessel_id = ? ORDER BY project_code, name', [$vid]) : all('SELECT id, project_code, name FROM projects WHERE id = ?', [$pid]);
$q = fn(array $x = []) => url('weekly_vessel', array_filter(['vessel' => $vid, 'project' => $pid, 'week' => $ws] + $x));
$prevW = date('Y-m-d', strtotime($ws) - 7 * 86400); $nextW = date('Y-m-d', strtotime($ws) + 7 * 86400);
$sections = wk_sections($W); $tableNo = 0;
layout_head(t('wv.title') . ' · ' . $v['name'], 'vessels');
?>
<p class="eyebrow"><?php if ($vid): ?><a href="<?= url('vessels') ?>"><?= tt('nav.vessels') ?></a> › <a href="<?= url('vessel', ['id' => $vid]) ?>"><?= e($v['name']) ?></a><?php else: ?><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= e($v['name']) ?><?php endif; ?> › <?= tt('wv.title') ?></p>
<div class="page-head">
  <div><h1><?= tt('wv.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($v['name']) ?> · <?= e($W['iso']) ?> · <?= e(fmt_date($ws)) ?> – <?= e(fmt_date($W['week_end'])) ?><?= $W['only_project'] ? ' · ' . e($W['only_project']['project_code'] ?: $W['only_project']['name']) : '' ?></p></div>
  <div class="btn-row">
    <a class="btn btn--ghost btn--sm" href="<?= url('report_weekly', array_filter(['vessel' => $vid, 'project' => $pid, 'week' => $ws])) ?>" target="_blank">⤓ PDF</a>
    <a class="btn btn--ghost btn--sm" href="<?= url('report_weekly', array_filter(['vessel' => $vid, 'project' => $pid, 'week' => $ws, 'format' => 'docx'])) ?>">⤓ Word</a>
  </div>
</div>
<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: auto 1fr 1fr auto;">
    <input type="hidden" name="p" value="weekly_vessel"><input type="hidden" name="vessel" value="<?= $vid ?>">
    <div class="btn-row" style="align-self:end;"><a class="btn btn--ghost btn--sm" href="<?= $q(['week' => $prevW]) ?>">‹ <?= tt('wv.prev_week') ?></a><a class="btn btn--ghost btn--sm" href="<?= $q(['week' => $nextW]) ?>"><?= tt('wv.next_week') ?> ›</a></div>
    <div class="field"><label for="week"><?= tt('wk.week') ?></label><input id="week" name="week" type="date" value="<?= e($ws) ?>"></div>
    <div class="field"><label for="project"><?= tt('pr.one') ?></label><select id="project" name="project"><?php if ($vid): ?><option value=""><?= tt('vh.all_projects') ?></option><?php endif; ?><?php foreach ($allProjects as $pp): ?><option value="<?= $pp['id'] ?>"<?= $pid === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['project_code'] && !str_starts_with($pp['name'], $pp['project_code']) ? $pp['project_code'] . ' ' . $pp['name'] : $pp['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
</div>

<?php foreach ($sections as $no => $sec): ?>
<div class="card wv-sec" id="s<?= $no ?>">
  <h2><?= e($sec['title']) ?></h2>
  <?php foreach ($sec['tables'] as $T): $tableNo++; ?>
    <h3 class="ac-h"><?= tt('rp.table') ?> <?= $tableNo ?> — <?= e($T['title']) ?></h3>
    <div class="table-scroll"><table class="tbl--compact">
      <thead><tr><?php foreach ($T['cols'] as $col): ?><th<?= $col[2] === 'R' ? ' class="num"' : '' ?>><?= e($col[0]) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php if (!$T['rows']): ?><tr><td colspan="<?= count($T['cols']) ?>" class="hint"><?= tt('wv.nothing') ?></td></tr><?php else: foreach ($T['rows'] as $r): ?>
        <tr<?= !empty($r['b']) ? ' style="background:var(--cp-gold-dim);font-weight:600;"' : '' ?>><?php foreach ($T['cols'] as $i => $col): $val = (string)($r['v'][$i] ?? ''); ?><td<?= $col[2] === 'R' ? ' class="num"' : '' ?>><?= $no === 1 && $i > 0 ? preg_replace('/\((\+\d+)\)/', '<span class="wv-up">($1)</span>', e($val)) : rt_html($val) ?></td><?php endforeach; ?></tr>
      <?php endforeach; endif; ?></tbody>
    </table></div>
  <?php endforeach; ?>
  <?php foreach ($sec['text'] as $t): ?><p class="hint"><?= rt_html($t) ?></p><?php endforeach; ?>
  <?php if ($no === 2): foreach (wk_note_photos($W) as $nn): ?>
    <div class="wv-ticket"><p><b><?= e(trim($nn['sub_code'] . ' ' . $nn['sub_name'])) ?></b> · <?= e(fmt_date($nn['note_date'])) ?> · <?= e($nn['author'] ?: '') ?></p>
      <div class="wv-photos"><?php foreach ($nn['photos'] as $f): ?><a href="<?= url('file', ['id' => $f['id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => $f['id']]) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy"><span><?= e(mb_substr($f['title'] ?: $f['original_name'], 0, 28)) ?></span></a><?php endforeach; ?></div></div>
  <?php endforeach; endif; ?>
  <?php if ($no === 4 && $staff && of_has()): foreach ($W['projects'] as $P): ?>
    <form method="post" action="<?= $q() ?>" data-lock class="wv-notes">
      <?= csrf_field() ?><input type="hidden" name="action" value="wk_notes"><input type="hidden" name="project_id" value="<?= (int)$P['p']['id'] ?>"><input type="hidden" name="vessel_id" value="<?= $vid ?>"><input type="hidden" name="week_start" value="<?= e($ws) ?>">
      <h3 class="ac-h"><?= tt('wv.pm_notes') ?><?= count($W['projects']) > 1 ? ' — ' . e($P['p']['project_code'] ?: $P['p']['name']) : '' ?><?= !empty($P['notes']['author']) ? ' <span class="hint">· ' . e($P['notes']['author']) . ' · ' . e(fmt_datetime($P['notes']['updated_at'])) . '</span>' : '' ?></h3>
      <div class="grid grid-2">
        <div class="field"><label><?= tt('wv.delays_f') ?></label><textarea name="delays" rows="3" placeholder="<?= tt('wv.delays_ph') ?>"><?= e($P['notes']['delays'] ?? '') ?></textarea></div>
        <div class="field"><label><?= tt('wv.remarks') ?></label><textarea name="remarks" rows="3" placeholder="<?= tt('wv.remarks_ph') ?>"><?= e($P['notes']['remarks'] ?? '') ?></textarea></div>
      </div>
      <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
    </form>
  <?php endforeach; endif; ?>
  <?php if ($no === 10): $det = wk_ticket_detail($W); if ($det): ?>
    <h3 class="ac-h"><?= tt('wv.s9_detail') ?></h3>
    <?php foreach ($det as $t): ?>
      <div class="wv-ticket">
        <p><b><a href="<?= url('ticket', ['id' => $t['id']]) ?>"><?= e($t['ticket_no']) ?></a> — <?= e($t['title']) ?></b> <?= tk_status_pill($t['status']) ?></p>
        <?php foreach ($t['comments_week'] as $c): ?><div class="msg msg--<?= $c['side'] ?><?= $c['visibility'] === 'internal' ? ' internal' : '' ?>" style="max-width:100%;margin-bottom:6px;"><p class="who"><?= avatar_html((int)($c['user_id'] ?? 0), (string)($c['author'] ?: ''), 22, 'ava--inline') ?> <b><?= e($c['author'] ?: '') ?></b> · <?= e(fmt_datetime($c['created_at'])) ?><?= $c['visibility'] === 'internal' ? ' · ' . pill('navy', t('tk.internal')) : '' ?></p><p><?= nl2br(e((string)$c['body'])) ?></p></div><?php endforeach; ?>
        <?php if ($t['photos']): ?><div class="wv-photos"><?php foreach ($t['photos'] as $f): ?><a href="<?= url('tfile', ['id' => $f['id']]) ?>" target="_blank"><img src="<?= url('tfile', ['id' => $f['id']]) ?>" alt="<?= e($f['original_name']) ?>" loading="lazy"><span><?= e(mb_substr($f['original_name'], 0, 28)) ?></span></a><?php endforeach; ?></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; endif; ?>
</div>
<?php endforeach; ?>
<?php if (ar_has() && is_staff()) { $arPid = (int)($W['only_project']['id'] ?? 0) ?: ar_vessel_project($vid); if ($arPid) echo ar_issued_html(wk_doc_no($W['vessel'], $W['only_project'], $W['week_start']), $arPid, ['kind' => 'weekly', 'vessel' => $vid, 'project' => $pid, 'week' => $ws], url('weekly_vessel', array_filter(['vessel' => $vid, 'project' => $pid, 'week' => $ws]))); } ?>
<?php if (is_staff() && function_exists('em_card_html')): $emNo = wk_doc_no($W['vessel'], $W['only_project'], $W['week_start']); $emK = 'wk:' . $vid . ':' . (int)$pid . ':' . $ws;
  $emAtts = [[$emK . ':pdf', $emNo . '.pdf'], [$emK . ':docx', $emNo . '.docx']];
  echo em_card_html(['kind' => 'weekly', 'ctx' => ['no' => $emNo, 'title' => (string)($W['only_project']['name'] ?? ''), 'vessel' => (string)$v['name'], 'customer' => (string)($v['customer_name'] ?? ''), 'project' => (string)($W['only_project']['project_code'] ?? ''), 'week' => (string)$W['iso'], 'doc' => t('em.w_wk', ['no' => $emNo, 'w' => (string)$W['iso']]), 'project_id' => (int)($W['only_project']['id'] ?? 0), 'customer_id' => (int)($v['customer_id'] ?? 0)],
    'people' => em_people($vid ?: null, (int)($v['customer_id'] ?? 0) ?: null), 'atts' => $emAtts, 'back' => $q()]); endif; ?>
<?php layout_foot(); ?>

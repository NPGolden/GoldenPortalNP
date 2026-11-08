<?php
/** HR › Recruiting dashboard (GAS-CP-R89-001): vacancies, applications pipeline, online selection events, talent pool. HR / Owner / CEO; hiring managers see their own vacancies.
 *  ?p=rcr_dashboard[&tab=apps|events|pool&vac=&status=&q=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('rcr_has') || !rcr_has() || !(rcr_can() || rcr_vacancies())) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$tab = (string)($_GET['tab'] ?? 'vac'); $c = rcr_can() ? rcr_dashboard_counts() : null;
$st = fn(string $s) => pill(in_array($s, ['open', 'upcoming', 'hired', 'offer'], true) ? 'gold' : (in_array($s, ['rejected', 'closed', 'cancelled', 'withdrawn'], true) ? 'grey' : 'navy'), t('rc.st_' . $s));
layout_head(t('rc.title'), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('hr_dashboard') ?>"><?= tt('hr.dashboard') ?></a> › <?= tt('rc.title') ?></p>
<div class="page-head"><div><h1>🧲 <?= tt('rc.title') ?></h1><p class="lede"><?= tt('rc.lede') ?></p></div>
<?php if (rcr_can()): ?><div class="btn-row"><a class="btn btn--gold" href="<?= url('rcr_vacancy', ['new' => 1]) ?>">＋ <?= tt('rc.new_vacancy') ?></a><a class="btn btn--ghost" href="<?= url('rcr_event', ['new' => 1]) ?>">＋ <?= tt('rc.new_event') ?></a><a class="btn btn--ghost" href="<?= url('rcr_sets') ?>"><?= tt('rc.sets') ?></a><a class="btn btn--ghost" href="<?= url('rcr_settings') ?>">⚙</a></div><?php endif; ?></div>
<?php if ($c): ?><div class="kpi kpi--6" style="margin-bottom:14px;">
  <a class="stat" href="<?= url('rcr_dashboard') ?>"><b><?= $c['open'] ?></b><span class="s"><?= tt('rc.k_open') ?></span></a>
  <a class="stat<?= $c['new'] ? ' gold' : '' ?>" href="<?= url('rcr_dashboard', ['tab' => 'apps']) ?>"><b><?= $c['new'] ?></b><span class="s"><?= tt('rc.k_new') ?></span></a>
  <a class="stat" href="<?= url('rcr_dashboard', ['tab' => 'apps', 'status' => 'interview']) ?>"><b><?= $c['active'] ?></b><span class="s"><?= tt('rc.k_active') ?></span></a>
  <a class="stat" href="<?= url('rcr_dashboard', ['tab' => 'events']) ?>"><b><?= $c['events'] ?></b><span class="s"><?= tt('rc.k_events') ?></span></a>
  <a class="stat" href="<?= url('rcr_dashboard', ['tab' => 'pool']) ?>"><b><?= $c['pool'] ?></b><span class="s"><?= tt('rc.k_pool') ?></span></a>
  <a class="stat" href="<?= url('rcr_dashboard', ['tab' => 'apps', 'passed' => 1]) ?>"><b><?= (int)$c['pass_rate'] ?>%</b><span class="s"><?= tt('rc.k_pass') ?></span></a>
</div><?php endif; ?>
<div class="tabs"><a class="<?= $tab === 'vac' ? 'on' : '' ?>" href="<?= url('rcr_dashboard') ?>"><?= tt('rc.vacancies') ?></a><a class="<?= $tab === 'apps' ? 'on' : '' ?>" href="<?= url('rcr_dashboard', ['tab' => 'apps']) ?>"><?= tt('rc.applications') ?></a><a class="<?= $tab === 'events' ? 'on' : '' ?>" href="<?= url('rcr_dashboard', ['tab' => 'events']) ?>"><?= tt('rc.events') ?></a><?php if (rcr_can()): ?><a class="<?= $tab === 'pool' ? 'on' : '' ?>" href="<?= url('rcr_dashboard', ['tab' => 'pool']) ?>"><?= tt('rc.pool') ?></a><?php endif; ?></div>

<?php if ($tab === 'vac'): $rows = rcr_vacancies(['status' => (string)($_GET['status'] ?? '')]); ?>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.code') ?></th><th><?= tt('rc.vacancy') ?></th><th><?= tt('hr.department') ?></th><th><?= tt('rc.branch') ?></th><th><?= tt('th.status') ?></th><th><?= tt('rc.web') ?></th><th><?= tt('rc.applications') ?></th><th><?= tt('rc.closes') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= tt('rc.none') ?></td></tr><?php endif; foreach ($rows as $v): ?>
<tr><td><a href="<?= url('rcr_vacancy', ['id' => (int)$v['id']]) ?>"><b><?= e($v['code']) ?></b></a></td><td><a href="<?= url('rcr_vacancy', ['id' => (int)$v['id']]) ?>"><?= e($v['title']) ?></a><br><span class="hint"><?= e((string)$v['location']) ?> · <?= tt('rc.' . $v['emp_type']) ?> · <?= tt('rc.' . $v['seniority']) ?></span></td><td><?= e((string)$v['department']) ?></td><td><?= e((string)$v['branch']) ?></td><td><?= $st($v['status']) ?></td><td><?= $v['publish_web'] ? '🌐' : '—' ?></td><td><b><?= (int)$v['n_apps'] ?></b> <span class="hint">· <?= (int)$v['n_passed'] ?> <?= tt('rc.passed') ?> · <?= (int)$v['n_active'] ?> <?= tt('rc.in_pipeline') ?></span></td><td><?= $v['closes_at'] ? e(fmt_date($v['closes_at'])) : '—' ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'apps'): $f = ['vacancy_id' => (int)($_GET['vac'] ?? 0), 'status' => (string)($_GET['status'] ?? ''), 'passed' => $_GET['passed'] ?? '', 'q' => trim((string)($_GET['q'] ?? ''))]; $rows = rcr_applications($f); ?>
<form class="filters" method="get"><input type="hidden" name="p" value="rcr_dashboard"><input type="hidden" name="tab" value="apps">
<select name="vac" data-nosearch><option value=""><?= tt('rc.all_vacancies') ?></option><?php foreach (rcr_vacancies() as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $f['vacancy_id'] === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['code'] . ' · ' . $v['title']) ?></option><?php endforeach; ?></select>
<select name="status" data-nosearch><option value=""><?= tt('rc.all_status') ?></option><?php foreach (RCR_APP_STATUS as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select>
<select name="passed" data-nosearch><option value=""><?= tt('rc.pass_any') ?></option><option value="1"<?= $f['passed'] === '1' ? ' selected' : '' ?>><?= tt('rc.passed') ?></option><option value="0"<?= $f['passed'] === '0' ? ' selected' : '' ?>><?= tt('rc.failed') ?></option></select>
<input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('rc.search_ph') ?>"><button class="btn btn--ghost btn--sm"><?= tt('btn.filter') ?></button></form>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.code') ?></th><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.vacancy') ?></th><th><?= tt('rc.screening') ?></th><th><?= tt('th.status') ?></th><th>CV</th><th><?= tt('rc.owner') ?></th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= tt('rc.none') ?></td></tr><?php endif; foreach ($rows as $a): ?>
<tr><td><a href="<?= url('rcr_application', ['id' => (int)$a['id']]) ?>"><b><?= e($a['code']) ?></b></a></td><td><a href="<?= url('rcr_application', ['id' => (int)$a['id']]) ?>"><?= e((string)($a['name'] ?: $a['email'])) ?></a><br><span class="hint"><?= e($a['email']) ?></span></td><td><?= e($a['vacancy_code']) ?><br><span class="hint"><?= e($a['vacancy']) ?></span></td><td><?= $a['passed'] ? pill('gold', '✓ ' . (int)$a['score'] . '/' . (int)$a['max_score']) : pill('grey', '✗ ' . (int)$a['score'] . '/' . (int)$a['max_score']) ?><?= !$a['knockout_ok'] ? ' <span class="hint" title="' . tt('rc.ko_hint') . '">⚑</span>' : '' ?></td><td><?= $st($a['status']) ?></td><td><?= $a['cv_path'] ? '<a href="' . url('rcr_cv', ['id' => (int)$a['id']]) . '" target="_blank">📎</a>' : '—' ?></td><td><?= e((string)$a['owner']) ?></td><td><?= e(fmt_datetime($a['created_at'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'events'): $rows = rcr_events(); ?>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.code') ?></th><th><?= tt('rc.event') ?></th><th><?= tt('rc.kind') ?></th><th><?= tt('rc.starts') ?></th><th><?= tt('th.status') ?></th><th><?= tt('rc.web') ?></th><th><?= tt('rc.registered') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="empty"><?= tt('rc.none') ?></td></tr><?php endif; foreach ($rows as $ev): ?>
<tr><td><a href="<?= url('rcr_event', ['id' => (int)$ev['id']]) ?>"><b><?= e($ev['code']) ?></b></a></td><td><a href="<?= url('rcr_event', ['id' => (int)$ev['id']]) ?>"><?= e($ev['title']) ?></a><br><span class="hint"><?= e((string)$ev['platform']) ?> · <?= (int)$ev['duration_min'] ?> min</span></td><td><?= tt('rc.kind_' . $ev['kind']) ?></td><td><?= e(fmt_datetime($ev['starts_at'])) ?></td><td><?= $st($ev['status']) ?></td><td><?= $ev['publish_web'] ? '🌐' : '—' ?></td><td><b><?= (int)$ev['n_reg'] ?></b><?= $ev['capacity'] ? ' / ' . (int)$ev['capacity'] : '' ?> <span class="hint">· <?= (int)$ev['n_att'] ?> <?= tt('rc.attended') ?></span></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'pool' && rcr_can()): $rows = all('SELECT * FROM rc_talent_pool ORDER BY created_at DESC LIMIT 500'); ?>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.area') ?></th><th><?= tt('rc.message') ?></th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="4" class="empty"><?= tt('rc.none') ?></td></tr><?php endif; foreach ($rows as $r): ?>
<tr><td><b><?= e((string)($r['name'] ?: $r['email'])) ?></b><br><span class="hint"><?= e($r['email']) ?></span></td><td><?= e((string)$r['area']) ?></td><td><?= e(mb_substr((string)$r['message'], 0, 160)) ?></td><td><?= e(fmt_datetime($r['created_at'])) ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; layout_foot();

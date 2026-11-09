<?php
/**
 * Work permits — register per main project with expiry watch.   GAS-CP-R93-001
 *   ?p=permits[&state=open|expired|soon|renewal|valid|cancelled][&project=&person=&q=]
 */
require __DIR__ . '/_layout.php';
$u = require_login();
if (!pmt_can_view()) { http_response_code(403); layout_head(t('pmt.title'), 'projects'); echo '<h1>' . tt('pmt.title') . '</h1><p class="flash flash--bad">' . tt('err.noaccess') . '</p>'; layout_foot(); return; }
$state = (string)($_GET['state'] ?? 'open'); if (!in_array($state, ['open', 'expired', 'soon', 'renewal', 'valid', 'cancelled'], true)) $state = 'open';
$f = ['state' => $state, 'project' => (int)($_GET['project'] ?? 0), 'person' => (int)($_GET['person'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))];
$rows = pmt_list($f); $S = pmt_summary(); $types = pmt_types();
$projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold') OR id IN (SELECT project_id FROM work_permits) ORDER BY project_code DESC, name");
$keep = array_filter(['project' => $f['project'], 'person' => $f['person'], 'q' => $f['q']]);
$tabs = [['open', t('pmt.tab_open'), $S['n']], ['expired', t('pmt.tab_expired'), $S['expired']], ['soon', t('pmt.tab_soon'), $S['soon']], ['renewal', t('pmt.tab_renewal'), $S['renewal']], ['valid', t('pmt.tab_valid'), 0], ['cancelled', t('pmt.tab_cancelled'), 0]];
layout_head(t('pmt.title'), 'projects');
?>
<div class="page-head">
  <div><h1>🪪 <?= tt('pmt.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pmt.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url('projects') ?>">‹ <?= tt('nav.projects') ?></a><?php if (pmt_can_edit($f['project'])): ?><a class="btn btn--gold" href="<?= url('permit_edit', $f['project'] ? ['project' => $f['project']] : []) ?>">+ <?= tt('pmt.new') ?></a><?php endif; ?></div>
</div>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <a class="stat<?= $S['expired'] ? ' red' : '' ?>" href="<?= url('permits', ['state' => 'expired'] + $keep) ?>"><b><?= $S['expired'] ?></b><?= tt('pmt.tab_expired') ?><span class="s"><?= tt('pmt.k_expired_s') ?></span></a>
  <a class="stat<?= $S['soon'] ? ' gold' : '' ?>" href="<?= url('permits', ['state' => 'soon'] + $keep) ?>"><b><?= $S['soon'] ?></b><?= tt('pmt.tab_soon') ?><span class="s"><?= e(t('pmt.k_soon_s', ['n' => PMT_WARN_DAYS])) ?></span></a>
  <a class="stat" href="<?= url('permits', ['state' => 'renewal'] + $keep) ?>"><b><?= $S['renewal'] ?></b><?= tt('pmt.tab_renewal') ?><span class="s"><?= tt('pmt.k_renewal_s') ?></span></a>
  <a class="stat green" href="<?= url('permits', ['state' => 'open'] + $keep) ?>"><b><?= $S['n'] ?></b><?= tt('pmt.tab_open') ?><span class="s"><?= tt('pmt.k_open_s') ?></span></a>
</div>

<nav class="tabs" aria-label="<?= tt('pmt.title') ?>">
  <?php foreach ($tabs as [$slug, $label, $n]): ?>
    <a class="<?= $state === $slug ? 'is-on' : '' ?>" href="<?= url('permits', ['state' => $slug] + $keep) ?>"><?= e($label) ?><?= $n ? ' <span class="n">' . $n . '</span>' : '' ?></a>
  <?php endforeach; ?>
</nav>

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1.4fr auto auto; margin-bottom:12px;">
  <input type="hidden" name="p" value="permits"><input type="hidden" name="state" value="<?= e($state) ?>">
  <div class="field"><label for="project"><?= tt('pmt.project') ?></label><select id="project" name="project"><option value=""><?= tt('pmt.all_projects') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $f['project'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(($p['project_code'] ? $p['project_code'] . ' — ' : '') . $p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label for="q"><?= tt('pmt.search') ?></label><input id="q" name="q" type="text" value="<?= e($f['q']) ?>" placeholder="<?= tt('pmt.search_ph') ?>"></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  <div class="field"><label>&nbsp;</label><a class="btn btn--ghost btn--sm" href="<?= url('permits', ['state' => $state]) ?>"><?= tt('btn.clear') ?></a></div>
</form>

<?php if (!$rows): ?>
<p class="hint"><?= tt('pmt.none') ?></p>
<?php else: ?>
<div class="card" style="padding:0;overflow:auto;">
<table class="tbl">
  <thead><tr><th><?= tt('pmt.holder') ?></th><th><?= tt('pmt.project') ?></th><th><?= tt('pmt.type') ?></th><th><?= tt('pmt.no') ?></th><th><?= tt('pmt.authority') ?></th><th><?= tt('pmt.issue') ?></th><th><?= tt('pmt.expiry') ?></th><th><?= tt('pr.status') ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $p): ?>
    <tr class="<?= pmt_state($p) === 'expired' ? 'is-bad' : '' ?>">
      <td><a href="<?= url('permit_edit', ['id' => $p['id']]) ?>"><b><?= e($p['holder_name']) ?></b></a><?php if ($p['person_name'] && $p['person_name'] !== $p['holder_name']): ?><br><small class="hint"><?= e($p['person_name']) ?></small><?php endif; ?></td>
      <td><a href="<?= url('permits', ['project' => $p['project_id']]) ?>"><?= e($p['project_code'] ?: $p['project_name']) ?></a><?php if ($p['project_code']): ?><br><small class="hint"><?= e($p['project_name']) ?></small><?php endif; ?></td>
      <td><?= e($types[$p['permit_type']] ?? $p['permit_type']) ?><?= $p['country'] ? ' <span class="hint">' . e($p['country']) . '</span>' : '' ?></td>
      <td class="mono"><?= e($p['permit_no'] ?: '—') ?></td>
      <td><?= e($p['authority'] ?: '—') ?></td>
      <td><?= $p['issue_date'] ? e(fmt_date($p['issue_date'])) : '—' ?></td>
      <td><b><?= e(fmt_date($p['expiry_date'])) ?></b></td>
      <td><?= pmt_pill($p) ?></td>
      <td style="white-space:nowrap;"><?php if ($p['stored_name']): ?><a class="btn btn--ghost btn--sm" href="<?= url('permit_file', ['id' => $p['id']]) ?>" target="_blank" rel="noopener" title="<?= e($p['original_name']) ?>">📄</a> <?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('permit_edit', ['id' => $p['id']]) ?>">✎</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="hint" style="margin-top:8px;"><?= e(t('pmt.footnote', ['n' => PMT_WARN_DAYS])) ?></p>
<?php endif; ?>
<?php layout_foot(); ?>

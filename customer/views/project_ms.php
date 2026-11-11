<?php
/** Project milestones and safety log — the sources of the Milestones table and the LTI tile of the weekly / monthly reports (GAS-CP-R96-001). ?p=project_ms&project=ID[&edit=<milestone>] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $pid = (int)($_GET['project'] ?? 0);
$p = function_exists('xs_has') && xs_has() ? xs_project($pid) : null;
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$can = xs_can_edit($pid); $self = url('project_ms', ['project' => $pid]);
$ms = ms_list($pid); $ed = null; foreach ($ms as $m) if ((int)$m['id'] === (int)($_GET['edit'] ?? 0)) $ed = $m;
$sf = sf_list($pid); $subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
$nDl = (int) scalar("SELECT COUNT(*) FROM subprojects s WHERE s.project_id = ? AND s.deadline IS NOT NULL AND s.status <> 'quoted' AND NOT EXISTS (SELECT 1 FROM pm_milestones m WHERE m.project_id = s.project_id AND m.subproject_id = s.id AND m.source = 'deadline')", [$pid]);
$lti = count(array_filter($sf, fn($f) => $f['kind'] === 'lti')); $wkOf = fn(?string $d) => $d ? xs_week($d) . ' · ' . fmt_date($d) : '—';
layout_head(t('ms.title') . ' · ' . ($p['project_code'] ?: $p['name']), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $p['project_code'], $p['name']) ?> › <?= tt('ms.title') ?></p>
<div class="page-head">
  <div><h1>🏁 <?= tt('ms.title') ?> · 🦺 <?= tt('sf.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name'])) ?><?= $p['vessel'] ? ' · ' . e($p['vessel']) : '' ?> — <?= tt('ms.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('weekly', ['project' => $pid]) ?>"><?= tt('fk.weekly') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('monthly', ['project' => $pid]) ?>"><?= tt('mo.title') ?></a></div>
</div>

<div class="card" id="ms">
  <div class="card-head"><h2>🏁 <?= tt('ms.title') ?> <span class="count"><?= count($ms) ?></span></h2><span class="hint"><?= tt('ms.hint') ?></span></div>
  <?php if ($can && $nDl): ?><form method="post" action="<?= $self ?>" class="inline" data-lock style="margin-bottom:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="ms_seed"><input type="hidden" name="project_id" value="<?= $pid ?>"><button class="btn btn--sm" type="submit">＋ <?= e(t('ms.seed_btn', ['n' => $nDl])) ?></button> <span class="hint"><?= tt('ms.seed_hint') ?></span></form><?php endif; ?>
  <?php if (!$ms): ?><p class="hint"><?= tt('ms.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('ms.one') ?></th><th><?= tt('ms.plan') ?></th><th><?= tt('ms.forecast') ?></th><th><?= tt('ms.achieved') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.remarks') ?></th><?php if ($can): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($ms as $m): $st = ms_status($m); ?><tr>
    <td><b><?= e($m['title']) ?></b><?= $m['source'] === 'deadline' ? ' <span class="hint">· ' . tt('ms.from_deadline') . '</span>' : '' ?></td>
    <td class="date"><?= e($wkOf($m['plan_date'])) ?></td><td class="date"><?= e($wkOf($m['forecast_date'])) ?></td><td class="date"><?= e($wkOf($m['achieved_date'])) ?></td>
    <td><span class="xs-pill xs-<?= e($st['tone']) ?>"><?= e($st['label']) ?></span></td><td class="hint"><?= e((string)($m['note'] ?? '')) ?></td>
    <?php if ($can): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('project_ms', ['project' => $pid, 'edit' => (int)$m['id']]) ?>#ms-form">✎</a><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('ms.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="ms_delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td><?php endif; ?>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($can): ?>
  <form method="post" action="<?= $self ?>" id="ms-form" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="ms_save"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="id" value="<?= $ed ? (int)$ed['id'] : 0 ?>">
    <h3 style="margin:0 0 6px;"><?= $ed ? tt('ms.edit') : tt('ms.add') ?></h3>
    <div class="grid grid-4">
      <div class="field" style="grid-column:span 2;"><label><?= tt('ms.one') ?> *</label><input name="title" maxlength="200" required value="<?= e((string)($ed['title'] ?? '')) ?>" placeholder="<?= tt('ms.title_ph') ?>"></div>
      <div class="field"><label><?= tt('ms.plan') ?> *</label><input type="date" name="plan_date" required value="<?= e((string)($ed['plan_date'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('ms.forecast') ?></label><input type="date" name="forecast_date" value="<?= e((string)($ed['forecast_date'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('ms.achieved') ?></label><input type="date" name="achieved_date" value="<?= e((string)($ed['achieved_date'] ?? '')) ?>"></div>
      <div class="field" style="grid-column:span 3;"><label><?= tt('th.remarks') ?></label><input name="note" maxlength="255" value="<?= e((string)($ed['note'] ?? '')) ?>" placeholder="<?= tt('ms.note_ph') ?>"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= $ed ? tt('btn.save') : tt('ms.add') ?></button><?php if ($ed): ?><a class="btn btn--ghost btn--sm" href="<?= $self ?>#ms"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    <p class="hint" style="margin:8px 0 0;"><?= tt('ms.form_hint') ?></p>
  </form>
  <?php endif; ?>
</div>

<div class="card" id="sf">
  <div class="card-head"><h2>🦺 <?= tt('sf.title') ?> <span class="count"><?= count($sf) ?></span></h2><span class="hint"><?= e(t('sf.hint', ['n' => $lti])) ?></span></div>
  <?php if (!$sf): ?><p class="hint"><?= tt('sf.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('sf.kind') ?></th><th><?= tt('sf.event') ?></th><th><?= tt('sf.action') ?></th><th class="num"><?= tt('sf.lost_days') ?></th><th><?= tt('th.by') ?></th><?php if ($can): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($sf as $f): ?><tr>
    <td class="date"><?= e(fmt_date($f['event_date'])) ?></td><td><span class="xs-pill xs-<?= $f['kind'] === 'lti' ? 'red' : ($f['kind'] === 'medical' || $f['kind'] === 'first_aid' ? 'amber' : 'blue') ?>"><?= e(sf_kinds()[$f['kind']] ?? $f['kind']) ?></span></td>
    <td><b><?= e($f['title']) ?></b><?= $f['sub_code'] ? ' <span class="hint">· ' . e($f['sub_code']) . '</span>' : '' ?><?= $f['description'] ? '<br><span class="hint">' . nl2br(e((string)$f['description'])) . '</span>' : '' ?></td>
    <td class="hint"><?= nl2br(e((string)($f['action_taken'] ?? ''))) ?></td><td class="num"><?= $f['kind'] === 'lti' ? (int)$f['lost_days'] : '—' ?></td><td class="hint"><?= e((string)$f['created_name']) ?></td>
    <?php if ($can): ?><td class="row-actions"><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('sf.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="sf_delete"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td><?php endif; ?>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($can): ?>
  <form method="post" action="<?= $self ?>" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="sf_save"><input type="hidden" name="project_id" value="<?= $pid ?>">
    <h3 style="margin:0 0 6px;"><?= tt('sf.add') ?></h3>
    <div class="grid grid-4">
      <div class="field"><label><?= tt('th.date') ?> *</label><input type="date" name="event_date" required max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('sf.kind') ?> *</label><select name="kind" data-nosearch><?php foreach (sf_kinds() as $k => $lab): ?><option value="<?= $k ?>"<?= $k === 'near_miss' ? ' selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sp.one') ?></label><select name="subproject_id"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e(trim(($s['code'] ?: '') . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sf.lost_days') ?></label><input type="number" name="lost_days" min="0" max="999" value="0"></div>
      <div class="field" style="grid-column:span 4;"><label><?= tt('sf.event') ?> *</label><input name="title" maxlength="200" required placeholder="<?= tt('sf.event_ph') ?>"></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('sf.description') ?></label><textarea name="description" rows="2" maxlength="4000" placeholder="<?= tt('sf.description_ph') ?>"></textarea></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('sf.action') ?></label><textarea name="action_taken" rows="2" maxlength="4000"></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sf.add') ?></button></div>
    <p class="hint" style="margin:8px 0 0;"><?= tt('sf.form_hint') ?></p>
  </form>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

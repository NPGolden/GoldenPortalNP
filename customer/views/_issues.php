<?php
/** Sub-project › Open issues tab — the register (service-report issues + manual entries), close / re-open, filters.   GAS-CP-R29-001 */
if (!iss_has()): ?>
<div class="card"><p class="hint" style="margin:0;"><?= tt('iss.need_db') ?></p></div>
<?php else:
$u = current_user(); if ($staff) iss_backfill($pid, $id);
$if = ['status' => (string)($_GET['ist'] ?? 'open_all'), 'side' => (string)($_GET['iside'] ?? ''), 'category' => (string)($_GET['icat'] ?? ''), 'q' => trim((string)($_GET['iq'] ?? ''))];
$hasCat = iss_has_cat(); $if['sort'] = (string)($_GET['isort'] ?? ''); $if['dir'] = (string)($_GET['idir'] ?? '');
$sortKeep = ['id' => $id, 'tab' => 'issues', 'ist' => $if['status'], 'iside' => $if['side'], 'icat' => $if['category'], 'iq' => $if['q']];
$th = function (string $key, string $label) use ($sortKeep, $if): string { $on = $if['sort'] === $key; $dir = $on && $if['dir'] !== 'desc' ? 'desc' : 'asc'; return '<th class="th-sort' . ($on ? ' is-on' : '') . '"><a href="' . url('subproject', array_filter($sortKeep) + ['isort' => $key, 'idir' => $dir]) . '" title="' . tt('tbl.sort_by') . '">' . e($label) . '<span class="sort-ind">' . ($on ? ($if['dir'] === 'desc' ? ' ▼' : ' ▲') : ' ⇅') . '</span></a></th>'; };
$issues = iss_list($pid, $id, $if);
$editI = (int)($_GET['iss'] ?? 0); $ei = $editI ? iss_get($editI) : null; if ($ei && (int)$ei['subproject_id'] !== $id) $ei = null;
$people = $staff ? staff_users() : [];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$nOpen = count(array_filter($issues, fn($i) => $i['status'] !== 'closed')); $nOver = count(array_filter($issues, 'iss_overdue'));
?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('iss.title') ?> <span class="hint" style="font-weight:400;">(<?= $nOpen ?><?= $nOver ? ' · ' . e(t('iss.n_overdue', ['n' => $nOver])) : '' ?>)</span></h2><p class="hint" style="margin:4px 0 0;"><?= tt('iss.lede') ?></p></div>
    <div class="actions"><a class="btn btn--gold btn--sm" href="#iss-form">+ <?= tt('iss.add') ?></a><?php if ($staff): ?> <a class="btn btn--ghost btn--sm" href="#iss-import">⤒ <?= tt('iss.import') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('issues_export', ['project' => $pid, 'sub' => $id]) ?>">⤓ <?= tt('eq.export') ?></a><?php endif; ?></div></div>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr<?= $hasCat ? ' 1fr' : '' ?> 1fr auto auto;">
    <input type="hidden" name="p" value="subproject"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="issues">
    <div class="field"><label for="iq"><?= tt('pr.search') ?></label><input id="iq" name="iq" type="text" value="<?= e($if['q']) ?>"></div>
    <div class="field"><label for="ist"><?= tt('th.status') ?></label><select id="ist" name="ist"><option value="open_all"<?= $sel('open_all', $if['status']) ?>><?= tt('iss.st_open_all') ?></option><?php foreach (iss_statuses() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $if['status']) ?>><?= e($l) ?></option><?php endforeach; ?><option value=""<?= $sel('', $if['status']) ?>><?= tt('flt.any') ?></option></select></div>
    <?php if ($hasCat): ?><div class="field"><label for="icat"><?= tt('iss.category') ?></label><select id="icat" name="icat"><option value=""><?= tt('flt.any') ?></option><?php foreach (iss_cats(false) as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $if['category']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label for="iside"><?= tt('iss.side') ?></label><select id="iside" name="iside"><option value=""><?= tt('flt.any') ?></option><?php foreach (iss_sides() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $if['side']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
    <div class="field"><label>&nbsp;</label><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'issues', 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
  </form>
  <?php if (!$issues): ?><p class="hint"><?= tt('iss.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl--compact iss-tbl" data-sorted="server">
    <thead><tr><?= $th('no', t('iss.no')) ?><?= $th('title', t('iss.one')) ?><?= $th('source', t('iss.source')) ?><?= $th('side', t('iss.side')) ?><?= $th('assignee', t('td.assignee')) ?><?= $th('raised', t('iss.raised')) ?><?= $th('due', t('iss.due')) ?><?= $th('status', t('th.status')) ?><th></th></tr></thead>
    <tbody><?php [$pageRows, $pager] = page_list($issues, 'subproject', $sortKeep + ['isort' => $if['sort'], 'idir' => $if['dir']], 'ipage'); foreach ($pageRows as $i): ?>
      <tr id="iss-<?= (int)$i['id'] ?>"<?= iss_overdue($i) ? ' class="is-late"' : '' ?>>
        <td class="mono" style="white-space:nowrap;"><a href="<?= url('subproject', ['id' => $id, 'tab' => 'issues', 'ist' => '']) ?>#iss-<?= (int)$i['id'] ?>"><?= e($i['issue_no'] ?? '#' . $i['id']) ?></a></td>
        <td><b><?= e($i['title']) ?></b> <?= $hasCat ? iss_cat_pill($i['category'] ?? 'other') : '' ?> <?= $i['priority'] !== 'normal' ? iss_priority_pill($i['priority']) : '' ?><?= $staff && $i['visibility'] === 'internal' ? ' ' . pill('navy', t('vis.internal')) : '' ?>
          <?php if ($i['body'] && $i['body'] !== $i['title']): ?><div class="hint" style="white-space:pre-line;"><?= e(mb_substr($i['body'], 0, 600)) ?><?= mb_strlen($i['body']) > 600 ? '…' : '' ?></div><?php endif; ?>
          <?php if ($i['status'] === 'closed'): ?><div class="hint">✔ <?= e(fmt_date($i['closed_on'])) ?> · <?= e($i['closed_by'] ?: '') ?><?= $i['closed_note'] ? ' — ' . e($i['closed_note']) : '' ?></div><?php endif; ?></td>
        <td><?= $i['source'] === 'fsr' ? '📋 <a href="' . url('fsr', ['id' => (int)$i['fsr_id']]) . '">' . e($i['report_no'] ?: t('fsr.one')) . '</a>' : '✎ ' . e($i['raised_by'] ?: t('iss.src_manual')) ?></td>
        <td><?= e(iss_sides()[$i['owner_side']] ?? $i['owner_side']) ?></td><td><?= e($i['assignee'] ?: '—') ?></td>
        <td class="date"><?= e(fmt_date($i['raised_on'])) ?></td><td class="date"><?= $i['due_on'] ? e(fmt_date($i['due_on'])) . (iss_overdue($i) ? ' ' . pill('bad', t('iss.overdue')) : '') : '—' ?></td>
        <td><?= iss_status_pill($i['status']) ?></td>
        <td class="actions" style="white-space:nowrap;">
          <?php if ($i['status'] !== 'closed'): ?>
            <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="iss_status"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="status" value="closed">
              <input name="note" maxlength="1000" placeholder="<?= tt('iss.close_note_ph') ?>" style="width:160px;display:inline-block;"> <button class="btn btn--sm" type="submit">✔ <?= tt('iss.close') ?></button></form>
            <?php if ($staff && $i['status'] === 'open'): ?><form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="iss_status"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="status" value="in_progress"><button class="btn btn--ghost btn--sm" type="submit">▶ <?= tt('iss.start') ?></button></form><?php endif; ?>
          <?php else: ?>
            <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="iss_status"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><input type="hidden" name="status" value="open"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('iss.reopen') ?></button></form>
          <?php endif; ?>
          <?php if ($staff || (int)$i['created_by'] === (int)$u['id']): ?> <a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => $id, 'tab' => 'issues', 'iss' => (int)$i['id']]) ?>#iss-form">✎</a><?php endif; ?>
          <?php if (is_cp_admin()): ?><form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" style="display:inline;" data-lock data-confirm="<?= tt('iss.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="iss_delete"><input type="hidden" name="id" value="<?= (int)$i['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-text" type="submit">✕</button></form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?></tbody></table></div><?= $pager ?>
  <?php endif; ?>
</div>

<div class="card" id="iss-form">
  <h2><?= tt($ei ? 'iss.edit' : 'iss.add') ?></h2>
  <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="iss_save"><input type="hidden" name="id" value="<?= $ei ? (int)$ei['id'] : 0 ?>"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
    <div class="field"><label for="iss-title"><?= tt('iss.one') ?> *</label><input id="iss-title" name="title" required maxlength="200" value="<?= e($ei['title'] ?? '') ?>" placeholder="<?= tt('iss.title_ph') ?>"></div>
    <div class="field"><label for="iss-body"><?= tt('iss.detail') ?></label><textarea id="iss-body" name="body" rows="3" maxlength="8000"><?= e($ei['body'] ?? '') ?></textarea></div>
    <div class="grid grid-3">
      <?php if ($hasCat): ?><div class="field"><label><?= tt('iss.category') ?></label><select name="category"><?php foreach (iss_cats() as $k => $l): ?><option value="<?= e($k) ?>"<?= $sel($k, $ei['category'] ?? 'other') ?>><?= e($l) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field"><label><?= tt('iss.side') ?></label><select name="owner_side"><?php foreach (iss_sides() as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $ei['owner_side'] ?? 'golden') ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('tk.priority') ?></label><select name="priority"><?php foreach (['low', 'normal', 'high'] as $k): ?><option value="<?= $k ?>"<?= $sel($k, $ei['priority'] ?? 'normal') ?>><?= tt('td.p_' . $k) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('iss.due') ?></label><input type="date" name="due_on" value="<?= e($ei['due_on'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('iss.raised') ?></label><input type="date" name="raised_on" value="<?= e($ei['raised_on'] ?? date('Y-m-d')) ?>"></div>
      <?php if ($staff): ?>
      <div class="field"><label><?= tt('td.assignee') ?></label><select name="assignee_id"><option value="">—</option><?php foreach ($people as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $ei['assignee_id'] ?? '') ?>><?= e($p['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('vis.label') ?></label><select name="visibility"><option value="external"<?= $sel('external', $ei['visibility'] ?? 'external') ?>><?= tt('vis.external') ?></option><option value="internal"<?= $sel('internal', $ei['visibility'] ?? '') ?>><?= tt('vis.internal') ?></option></select></div>
      <?php endif; ?>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($ei): ?><a class="btn btn--ghost" href="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?><span class="hint"><?= tt('iss.form_hint') ?></span></div>
  </form>
</div>

<?php if ($staff): $ir = ($_SESSION['cp_import_result'] ?? null); if ($ir && ($ir['kind'] ?? '') === 'issues') unset($_SESSION['cp_import_result']); else $ir = null; ?>
<div class="card" id="iss-import">
  <h2><?= tt('iss.import') ?></h2>
  <p class="hint"><?= tt('iss.import_hint') ?></p>
  <?php if ($ir): ?>
  <div class="flash flash--<?= $ir['skipped'] ? 'warn' : 'good' ?>" style="margin-bottom:10px;"><b><?= $ir['dry'] ? tt('im.dry_note') : tt('im.done', ['n' => (int)$ir['rows']]) ?></b> — <?= e(t('iss.im_result', ['n' => (int)$ir['rows'], 'c' => (int)$ir['created'], 'u' => (int)$ir['updated'], 's' => count($ir['skipped'])])) ?>
    <?php if ($ir['skipped'] || $ir['warnings']): ?><ul class="hint" style="margin:6px 0 0 18px;"><?php foreach (array_slice(array_merge($ir['skipped'], $ir['warnings']), 0, 30) as [$ln, $why]): ?><li><?= e(t('im.line', ['n' => (int)$ln])) ?>: <?= e($why) ?></li><?php endforeach; ?></ul><?php endif; ?></div>
  <?php endif; ?>
  <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'issues']) ?>" enctype="multipart/form-data" class="fltbar" style="grid-template-columns: 1.5fr auto auto auto;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="iss_import"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
    <div class="field"><label><?= tt('im.file') ?></label><input type="file" name="xlsx" accept=".xlsx" required></div>
    <div class="field"><label>&nbsp;</label><label class="chk"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit">⤒ <?= tt('iss.import') ?></button></div>
    <div class="field"><label>&nbsp;</label><a class="btn btn--ghost btn--sm" href="<?= url('import', ['tpl' => 'issues', 'sub' => $id]) ?>">⤓ <?= tt('im.template') ?></a></div>
  </form>
</div>
<?php endif; ?>
<?php endif; ?>

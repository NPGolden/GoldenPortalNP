<?php
/** Settings › Freshdesk import — the ticket history of the old helpdesk, file by file, in batches.   GAS-CP-R28-002 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$files = fd_has() ? fd_files() : [];
$rep = $_SESSION['fd_report'] ?? null; unset($_SESSION['fd_report']);
$run = (string)($_GET['run'] ?? ''); $job = $run !== '' ? ($_SESSION['fd_jobs'][$run] ?? null) : null;
$auto = $run !== '' && $job && empty($job['done']);
layout_head(t('fd.title'), 'settings');
if ($auto) echo '<meta http-equiv="refresh" content="1;url=' . e(url('fd_import', ['run' => $run, 'go' => 1, 'cv' => (int)($_GET['cv'] ?? 1), '_r' => uniqid()])) . '">';
?>
<?= settings_tabs('fd_import') ?>
<h1><?= tt('fd.title') ?></h1>
<p class="lede"><?= tt('fd.lede') ?></p>
<?php if (!fd_has()): ?><div class="flash flash--bad"><?= tt('fd.need_db') ?></div><?php layout_foot(); return; endif; ?>

<?php if ($job): ?>
<div class="card" id="progress">
  <h2><?= e($run) ?> — <?= tt(!empty($job['done']) ? 'fd.done' : 'fd.running') ?></h2>
  <?php $pct = $job['total'] ? (int) round(100 * min($job['offset'], $job['total']) / $job['total']) : 100; ?>
  <div class="bar" style="height:12px;border-radius:6px;background:var(--line);overflow:hidden;margin:8px 0;"><div style="width:<?= $pct ?>%;height:100%;background:var(--gold);"></div></div>
  <p><?= e(t('fd.progress', ['n' => (int)$job['offset'], 't' => (int)$job['total'], 'i' => (int)$job['imported'], 's' => (int)$job['skipped'], 'c' => (int)$job['comments']])) ?><?= !empty($job['equipment']) ? ' · ' . e(t('fd.equipment_n', ['n' => (int)$job['equipment']])) : '' ?></p>
  <?php if (!empty($job['vessels_new'])): ?><p class="hint"><?= tt('fd.vessels_created') ?>: <?= e(implode(', ', array_unique($job['vessels_new']))) ?></p><?php endif; ?>
  <?php if (!empty($job['vessels_missing'])): ?><p class="hint"><?= tt('fd.vessels_skipped') ?>: <?= e(implode(', ', array_unique($job['vessels_missing']))) ?></p><?php endif; ?>
  <?php if ($auto): ?><p class="hint"><?= tt('fd.keep_open') ?> <a class="btn btn--ghost btn--sm" href="<?= url('fd_import', ['run' => $run, 'go' => 1, 'cv' => (int)($_GET['cv'] ?? 1), '_r' => uniqid()]) ?>"><?= tt('fd.continue') ?></a></p>
  <?php else: ?><p><a class="btn btn--gold btn--sm" href="<?= url('tickets') ?>"><?= tt('nav.tickets') ?> →</a> <a class="btn btn--ghost btn--sm" href="<?= url('fd_import') ?>"><?= tt('btn.ok') ?></a></p><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($rep): ?>
<div class="card">
  <h2><?= tt('fd.check_result') ?> — <?= e($rep['file']) ?></h2>
  <div class="kpis kpi--4" style="margin-bottom:10px;">
    <div class="stat"><b><?= (int)$rep['tickets'] ?></b><span class="s"><?= tt('nav.tickets') ?><?= $rep['from'] ? ' · ' . e(fmt_date($rep['from'])) . ' → ' . e(fmt_date($rep['to'])) : '' ?></span></div>
    <div class="stat"><b><?= (int)$rep['notes'] ?></b><span class="s"><?= tt('fd.conversations') ?></span></div>
    <div class="stat gold"><b><?= (int)$rep['vessels_ok'] ?></b><span class="s"><?= tt('fd.vessels_matched') ?></span></div>
    <div class="stat <?= $rep['already'] ? 'green' : '' ?>"><b><?= (int)$rep['already'] ?></b><span class="s"><?= tt('fd.already') ?></span></div>
  </div>
  <p class="hint"><?= e(t('fd.check_line', ['s' => (int)$rep['skipped'], 'a' => (int)$rep['attachments'], 'p' => (int)$rep['projects_ok']])) ?></p>
  <div class="grid grid-2">
    <div><h3 class="ac-h"><?= tt('fd.vessels_missing') ?> (<?= count($rep['vessels_missing']) ?>)</h3><p class="hint"><?= tt('fd.vessels_missing_hint') ?></p>
      <?php if ($rep['vessels_missing']): ?><ul class="hint" style="margin:0 0 0 18px;columns:2;"><?php foreach ($rep['vessels_missing'] as $v => $n): ?><li><?= e($v) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul><?php else: ?><p class="hint">—</p><?php endif; ?>
      <h3 class="ac-h"><?= tt('fd.no_vessel') ?> (<?= count($rep['no_vessel']) ?>)</h3>
      <?php if ($rep['no_vessel']): ?><ul class="hint" style="margin:0 0 0 18px;columns:2;"><?php foreach (array_slice($rep['no_vessel'], 0, 40, true) as $v => $n): ?><li><?= e($v) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul><?php else: ?><p class="hint">—</p><?php endif; ?></div>
    <div><h3 class="ac-h"><?= tt('fd.agents') ?></h3><ul class="hint" style="margin:0 0 0 18px;"><?php foreach ($rep['agents'] as $a => $n): ?><li><?= e($a) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul><p class="hint"><?= tt('fd.agents_hint') ?></p>
      <h3 class="ac-h"><?= tt('th.status') ?></h3><ul class="hint" style="margin:0 0 0 18px;"><?php foreach ($rep['statuses'] as $a => $n): ?><li><?= e($a) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul>
      <h3 class="ac-h"><?= tt('tk.category') ?></h3><ul class="hint" style="margin:0 0 0 18px;"><?php foreach ($rep['types'] as $a => $n): ?><li><?= e($a) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul>
      <?php if ($rep['projects_missing']): ?><h3 class="ac-h"><?= tt('fd.projects_missing') ?></h3><ul class="hint" style="margin:0 0 0 18px;columns:2;"><?php foreach (array_slice($rep['projects_missing'], 0, 30, true) as $a => $n): ?><li><?= e($a) ?> <b>×<?= (int)$n ?></b></li><?php endforeach; ?></ul><?php endif; ?></div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('fd.files') ?></h2>
  <p class="hint"><?= e(t('fd.files_hint', ['dir' => 'customer/storage/import/freshdesk/'])) ?></p>
  <p class="hint"><?= tt('fd.scanning') ?>: <span class="hint">(importer build <?= e(FD_BUILD) ?>)</span>  <?php foreach (fd_dirs() as $d): ?><code><?= e($d) ?></code> <?php endforeach; ?></p>
  <?php if (!$files): ?><p class="hint"><?= tt('fd.no_files') ?></p><?php else: ?>
  <div class="table-scroll"><table><thead><tr><th><?= tt('fd.files') ?></th><th class="num"><?= tt('th.size') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($files as $f): $j = $f['job']; ?>
    <tr><td><b><?= e($f['name']) ?></b><br><span class="hint"><?= e(fmt_datetime(date('Y-m-d H:i:s', $f['mtime']))) ?></span></td><td class="num"><?= number_format($f['size'] / 1048576, 1) ?> MB</td>
      <td><?php if (!$j): ?><span class="hint"><?= tt('fd.st_new') ?></span><?php elseif (!empty($j['done'])): ?><?= pill('green', t('fd.st_done', ['n' => (int)$j['imported']])) ?><?php elseif (!empty($j['offset'])): ?><?= pill('gold', t('fd.st_partial', ['n' => (int)$j['offset'], 't' => (int)$j['total']])) ?><?php else: ?><?= pill('blue', t('fd.st_checked', ['n' => (int)$j['total']])) ?><?php endif; ?></td>
      <td class="actions" style="white-space:nowrap;">
        <form method="post" action="<?= url('fd_import') ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="fd_check"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn--ghost btn--sm" type="submit">🔎 <?= tt('fd.check') ?></button></form>
        <form method="post" action="<?= url('fd_import') ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="fd_run"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><label class="chk" style="display:inline;margin:0 6px;"><input type="checkbox" name="cv" value="1" checked> <?= tt('fd.create_vessels') ?></label><button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= e(t('fd.run_confirm', ['f' => $f['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fd.import') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">⤒ <?= tt(!empty($j['offset']) && empty($j['done']) ? 'fd.resume' : 'fd.import') ?></button></form>
        <form method="post" action="<?= url('fd_import') ?>" style="display:inline;" data-lock data-confirm="<?= tt('fd.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fd_delete"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn--ghost btn--sm btn--danger-text" type="submit">✕</button></form>
      </td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <form method="post" action="<?= url('fd_import') ?>" enctype="multipart/form-data" style="margin-top:12px;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="fd_upload">
    <div class="field" style="max-width:520px;"><label><?= tt('fd.upload') ?></label><input type="file" name="xml" accept=".xml" required><p class="hint"><?= e(t('fd.upload_hint', ['max' => ini_get('upload_max_filesize')])) ?></p></div>
    <button class="btn btn--ghost btn--sm" type="submit"><?= tt('fd.upload_btn') ?></button>
  </form>
</div>
<div class="card">
  <h2><?= tt('fd.how') ?></h2>
  <ol><li><?= tt('fd.how_1') ?></li><li><?= tt('fd.how_2') ?></li><li><?= tt('fd.how_3') ?></li><li><?= tt('fd.how_4') ?></li></ol>
  <p class="hint"><?= e(t('fd.imported_so_far', ['n' => (int) scalar('SELECT COUNT(*) FROM tickets WHERE legacy_id IS NOT NULL')])) ?></p>
</div>
<?php layout_foot(); ?>

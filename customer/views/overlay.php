<?php
/**
 * GA drawing overlays.                                      GAS-CP-R3-001
 * A General-Arrangement image with status-coloured markers on top:
 *   routing drawing  -> trays (lines), penetrations (dots), cables (lines)
 *   panels drawing   -> electrical panels (squares)
 * Items come from an Excel import; coordinates are % of the image so the
 * overlay scales on any screen. Click a marker for its details.
 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p');
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", array_merge([$pid], $args));
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff = is_staff();

$drawings = all('SELECT g.*, f.id AS fid FROM ga_drawings g JOIN sp_files f ON f.id = g.file_id WHERE g.project_id = ? ORDER BY g.kind, g.deck, g.id', [$pid]);
$did = (int)($_GET['d'] ?? ($drawings[0]['id'] ?? 0));
$cur = null; foreach ($drawings as $d) if ((int)$d['id'] === $did) $cur = $d;

$colors = [];
foreach (all('SELECT * FROM ga_status_colors ORDER BY layer, sort_order') as $c) $colors[$c['layer']][$c['status']] = $c;
$items = $cur ? all('SELECT * FROM ga_items WHERE drawing_id = ? ORDER BY layer, tag', [$did]) : [];
$layersHere = $cur ? ($cur['kind'] === 'panels' ? ['panel'] : ['tray', 'penetration', 'cable']) : [];

// per-layer status counts for the legend
$counts = [];
foreach ($items as $it) { $counts[$it['layer']][$it['status']] = ($counts[$it['layer']][$it['status']] ?? 0) + 1; }

layout_head(t('ga.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?></p>
<div class="page-head">
  <div><h1><?= tt('ga.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('ga.lede') ?></p></div>
</div>

<?php if ($drawings): ?>
<div class="card card--tight">
  <div class="fltbar" style="grid-template-columns: 1fr auto;">
    <div class="field" style="margin:0;"><label><?= tt('ga.drawing') ?></label>
      <select data-js-href="1">
        <?php foreach ($drawings as $d): ?>
          <option value="<?= url('overlay', ['project' => $pid, 'd' => $d['id']]) ?>"<?= (int)$d['id'] === $did ? ' selected' : '' ?>>
            <?= e(($d['kind'] === 'panels' ? tt('ga.panels') : tt('ga.routing')) . ' — ' . $d['title'] . ($d['deck'] ? ' (' . $d['deck'] . ')' : '')) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="ga-layers field" style="margin:0;"><label><?= tt('ga.layers') ?></label>
      <?php foreach ($layersHere as $L): ?><label class="chk"><input type="checkbox" class="ga-toggle" data-layer="<?= $L ?>" checked> <?= tt('ga.' . $L) ?></label><?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($cur): ?>
<div class="card">
  <div class="ga-legend">
    <?php foreach ($layersHere as $L): ?>
      <strong style="margin-right:4px;"><?= tt('ga.' . $L) ?>:</strong>
      <?php foreach ($colors[$L] ?? [] as $st => $c): ?>
        <span><i style="background:<?= e($c['color']) ?>"></i><?= e($c['label']) ?> <span class="hint">(<?= (int)($counts[$L][$st] ?? 0) ?>)</span></span>
      <?php endforeach; ?>
      <span style="width:100%"></span>
    <?php endforeach; ?>
  </div>
  <div class="ga-wrap" id="ga-wrap">
    <img src="<?= url('file', ['id' => $cur['fid']]) ?>" alt="<?= e($cur['title']) ?>">
    <svg viewBox="0 0 100 100" preserveAspectRatio="none">
      <?php foreach ($items as $it):
        $col = $colors[$it['layer']][$it['status']]['color'] ?? '#999999';
        $tip = $it['tag'] . ($it['label'] ? ' — ' . $it['label'] : '') . ' · ' . ($colors[$it['layer']][$it['status']]['label'] ?? $it['status']) . ($it['remarks'] ? ' · ' . $it['remarks'] : '');
        $g   = 'class="ga-item" data-layer="' . e($it['layer']) . '"'; ?>
        <?php if (($it['layer'] === 'tray' || $it['layer'] === 'cable') && $it['x2'] !== null): ?>
          <line <?= $g ?> x1="<?= (float)$it['x'] ?>" y1="<?= (float)$it['y'] ?>" x2="<?= (float)$it['x2'] ?>" y2="<?= (float)$it['y2'] ?>"
                stroke="<?= e($col) ?>" stroke-width="<?= $it['layer'] === 'tray' ? '1.1' : '0.45' ?>" stroke-linecap="round" vector-effect="non-scaling-stroke"
                style="stroke-width:<?= $it['layer'] === 'tray' ? 7 : 3 ?>px"><title><?= e($tip) ?></title></line>
        <?php elseif ($it['layer'] === 'panel'): ?>
          <rect <?= $g ?> x="<?= (float)$it['x'] - 0.9 ?>" y="<?= (float)$it['y'] - 0.9 ?>" width="1.8" height="1.8" fill="<?= e($col) ?>" stroke="#fff" stroke-width="0.15"><title><?= e($tip) ?></title></rect>
        <?php else: ?>
          <circle <?= $g ?> cx="<?= (float)$it['x'] ?>" cy="<?= (float)$it['y'] ?>" r="0.8" fill="<?= e($col) ?>" stroke="#fff" stroke-width="0.15"><title><?= e($tip) ?></title></circle>
        <?php endif; ?>
      <?php endforeach; ?>
    </svg>
  </div>
  <p class="hint" style="margin-top:10px;"><?= e(t('ga.nitems', ['n' => count($items)])) ?> · <?= tt('ga.hover') ?></p>

  <?php if ($items): ?>
  <details style="margin-top:14px;"><summary style="cursor:pointer;font-weight:600;"><?= tt('ga.list') ?></summary>
    <div class="table-scroll" style="margin-top:10px;"><table>
      <thead><tr><th><?= tt('ga.layer') ?></th><th><?= tt('ga.tag') ?></th><th><?= tt('ga.label') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
      <tbody><?php foreach ($items as $it): $c = $colors[$it['layer']][$it['status']] ?? null; ?>
        <tr><td><?= tt('ga.' . $it['layer']) ?></td><td class="ref"><?= e($it['tag']) ?></td><td><?= e($it['label'] ?: '—') ?></td>
          <td><span class="pill" style="background:<?= e($c['color'] ?? '#999') ?>;color:#fff;"><?= e($c['label'] ?? $it['status']) ?></span></td>
          <td class="hint"><?= e($it['remarks'] ?: '') ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </details>
  <?php endif; ?>
</div>
<?php elseif (!$drawings): ?>
  <div class="card"><div class="empty"><p><?= tt('ga.empty') ?></p></div></div>
<?php endif; ?>

<?php if ($staff): ?>
<div class="card">
  <h2><?= tt('ga.add') ?></h2>
  <form method="post" action="<?= url('overlay', ['project' => $pid]) ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="ga_add"><input type="hidden" name="project_id" value="<?= $pid ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('ga.kind') ?></label><select name="kind"><option value="routing"><?= tt('ga.routing') ?></option><option value="panels"><?= tt('ga.panels') ?></option></select></div>
      <div class="field"><label><?= tt('fl.title') ?></label><input name="title" required maxlength="160" placeholder="GA — Lower deck"></div>
      <div class="field"><label><?= tt('ga.deck') ?></label><input name="deck" maxlength="60"></div>
      <div class="field"><label><?= tt('ga.image') ?></label><input name="image" type="file" accept="image/*" required></div>
    </div>
    <button class="btn" type="submit"><?= tt('btn.save') ?></button>
  </form>

  <?php if ($cur): ?>
  <h2 style="margin-top:26px;"><?= tt('ga.import') ?> — <?= e($cur['title']) ?></h2>
  <p class="hint"><?= tt('ga.import_hint') ?></p>
  <pre class="secret" style="text-align:left;white-space:pre;overflow:auto;">layer | tag | label | x | y | x2 | y2 | status | remarks
tray  | T-ER-01 | ER port tray  | 12.5 | 60.0 | 48.0 | 60.0 | installed |
penetration | P-FR42 | Fr.42 bulkhead | 48.3 | 60.1 |  |  | sealed |
cable | C-0117 | DG1 to MSB | 14.0 | 61.0 | 47.0 | 61.0 | pulled |
panel | DB-3 | Distribution board 3 | 70.2 | 33.4 |  |  | mounted |</pre>
  <form method="post" action="<?= url('overlay', ['project' => $pid, 'd' => $did]) ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('dr.importing') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="ga_import"><input type="hidden" name="drawing_id" value="<?= $did ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('dr.xlsx') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <div class="field"><label class="chk"><input type="checkbox" name="replace" value="1"> <?= tt('ga.replace') ?></label></div>
    </div>
    <button class="btn" type="submit"><?= tt('dr.import_btn') ?></button>
  </form>
  <p class="hint"><?= tt('ga.statuses') ?>:
    <?php foreach ($layersHere as $L): ?><strong><?= tt('ga.' . $L) ?></strong> = <?= e(implode(', ', array_keys($colors[$L] ?? []))) ?>; <?php endforeach; ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">
document.querySelectorAll('.ga-toggle').forEach(function (cb) {
  cb.addEventListener('change', function () {
    document.querySelectorAll('.ga-item[data-layer="' + cb.dataset.layer + '"]').forEach(function (el) {
      el.style.display = cb.checked ? '' : 'none';
    });
  });
});
</script>
<?php layout_foot(); ?>

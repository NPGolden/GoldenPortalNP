<?php
require __DIR__ . '/_layout.php';
$u = require_login(); $cid = (int)$u['customer_id'];
$vid = (int)($_GET['vessel'] ?? 0);
$cat = preg_replace('/[^a-z]/', '', (string)($_GET['cat'] ?? ''));

$w = "d.customer_id = ?"; $args = [$cid];
if ($vid) { $w .= " AND d.vessel_id = ?"; $args[] = $vid; }
if ($cat) { $w .= " AND d.category = ?";  $args[] = $cat; }

$docs = all("SELECT d.*, v.name AS vessel FROM documents d
             LEFT JOIN vessels v ON v.id = d.vessel_id
             WHERE {$w} ORDER BY d.updated_at DESC LIMIT 200", $args);
$vessels = all("SELECT id, name FROM vessels WHERE customer_id=? ORDER BY name", [$cid]);
$cats = ['manual','drawing','certificate','fat','fsr','workorder','cyber','other'];

layout_head(t('d.title'), 'documents');
?>
<h1><?= tt('d.title') ?></h1>
<p class="lede"><?= tt('d.lede') ?></p>

<div class="card card--tight">
  <form method="get" action="index.php" class="grid grid-3" style="align-items:end;">
    <input type="hidden" name="p" value="documents">
    <input type="hidden" name="lang" value="<?= e(locale()) ?>">
    <div class="field" style="margin:0;">
      <label for="vessel"><?= tt('d.vessel') ?></label>
      <select id="vessel" name="vessel">
        <option value="0"><?= tt('vq.all') ?></option>
        <?php foreach ($vessels as $v): ?>
          <option value="<?= (int)$v['id'] ?>"<?= $vid === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field" style="margin:0;">
      <label for="cat"><?= tt('d.category') ?></label>
      <select id="cat" name="cat">
        <option value="">—</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= e($c) ?>"<?= $cat === $c ? ' selected' : '' ?>><?= tt('d.cat.' . $c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><button class="btn btn--ghost" type="submit">OK</button></div>
  </form>
</div>

<div class="card">
<?php if (!$docs): ?>
  <div class="empty"><?= tt('d.empty') ?></div>
<?php else: ?>
  <table>
    <thead><tr><th><?= tt('d.name') ?></th><th><?= tt('d.vessel') ?></th><th><?= tt('d.category') ?></th>
               <th><?= tt('d.status') ?></th><th><?= tt('d.date') ?></th></tr></thead>
    <tbody>
    <?php foreach ($docs as $d): ?>
      <tr>
        <td><b><?= e($d['title']) ?></b></td>
        <td><?= e($d['vessel'] ?: '—') ?></td>
        <td><?= tt('d.cat.' . $d['category']) ?></td>
        <td><?= doc_status_pill($d['status']) ?></td>
        <td class="date"><?= e(fmt_date($d['updated_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

<?php
require __DIR__ . '/_layout.php';
require_role('system_admin');

$u = current_user();
[$per, , $page] = list_prefs($u);            // per-user rows/page; period n/a here

$q = trim((string)($_GET['q'] ?? ''));
$where = ''; $args = [];
if ($q !== '') {
    $where = 'WHERE (name LIKE ? OR imo LIKE ? OR hull_no LIKE ? OR yard LIKE ?)';
    $args  = ["%{$q}%", "%{$q}%", "%{$q}%", "%{$q}%"];
}
$total   = (int) scalar("SELECT COUNT(*) FROM vessels {$where}", $args);
$pages   = max(1, (int)ceil($total / $per));
$page    = min($page, $pages);
$vessels = all("SELECT * FROM vessels {$where} ORDER BY name
                LIMIT {$per} OFFSET " . (($page - 1) * $per), $args);
$keep = array_filter(['q' => $q ?: null, 'per' => $per], fn($v) => $v !== null);

layout_head(t('av.title'), 'admin_vessels');
?>
<p class="eyebrow"><?= tt('nav.admin') ?></p>
<h1><?= tt('av.title') ?></h1>
<p class="lede"><?= tt('av.lede') ?></p>

<div class="card">
  <h2><?= tt('av.import') ?></h2>
  <form method="post" action="<?= url('admin_vessels') ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="vessel_import">
    <input type="hidden" name="_return" value="admin_vessels">
    <div class="field" style="max-width:420px;">
      <label for="csv"><?= tt('av.file') ?></label>
      <input id="csv" name="csv" type="file" accept=".csv,text/csv" required>
      <p class="hint"><?= tt('av.hint') ?></p>
    </div>
    <button class="btn btn--gold" type="submit"><?= tt('av.do') ?></button>
  </form>

  <h3 style="margin-top:24px;"><?= tt('av.example') ?></h3>
  <pre class="secret" style="text-align:left;white-space:pre;overflow:auto;">name,imo,hull_no,yard
AMBROSIA,9123456,NB-1042,"Palumbo, Ancona"
GALILEO,,C620,"TISG, La Spezia"</pre>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr .7fr auto;">
    <input type="hidden" name="p" value="admin_vessels">
    <input type="hidden" name="lang" value="<?= e(locale()) ?>">
    <div class="field" style="margin:0;">
      <label for="q"><?= tt('orders.search') ?></label>
      <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="<?= tt('av.search_ph') ?>">
    </div>
    <div class="field" style="margin:0;">
      <label for="fper"><?= tt('flt.per') ?></label>
      <select id="fper" name="per">
        <?php foreach (LIST_PAGE_SIZES as $n): ?>
          <option value="<?= $n ?>"<?= $n === $per ? ' selected' : '' ?>><?= $n ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="fltbar-actions">
      <button class="btn btn--ghost" type="submit"><?= tt('btn.apply') ?></button>
      <a class="btn btn--ghost" href="<?= url('admin_vessels') ?>"><?= tt('btn.clear') ?></a>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('av.count', ['n' => $total])) ?></h2>
  <?php if (!$vessels): ?>
    <div class="empty"><p><?= tt('av.empty') ?></p></div>
  <?php else: ?>
    <table>
      <thead><tr><th><?= tt('th.name') ?></th><th><?= tt('th.imo') ?></th><th><?= tt('th.hull') ?></th>
                 <th><?= tt('th.yard') ?></th><th><?= tt('th.status') ?></th></tr></thead>
      <tbody>
      <?php foreach ($vessels as $v): ?>
        <tr>
          <td><strong><?= e($v['name']) ?></strong></td>
          <td class="num"><?= e($v['imo'] ?: '—') ?></td>
          <td class="num"><?= e($v['hull_no'] ?: '—') ?></td>
          <td><?= e($v['yard'] ?: '—') ?></td>
          <td><?= $v['is_active']
                ? '<span class="pill pill--good">' . tt('av.inuse') . '</span>'
                : '<span class="pill pill--mute">' . tt('av.retired') . '</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php pager('admin_vessels', $page, $pages, $keep); ?>

<?php layout_foot(); ?>

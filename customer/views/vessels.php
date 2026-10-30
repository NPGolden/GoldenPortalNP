<?php
/**
 * Vessels register.                                         GAS-CP-R4-003
 * Customers see their own fleet. Staff see every vessel (with its customer,
 * including unassigned ones), can search, add and edit; admins can delete.
 */
require __DIR__ . '/_layout.php';
$u     = require_login();
$staff = is_staff();
$q     = trim((string)($_GET['q'] ?? ''));
$fCustomer = $staff ? (string)($_GET['customer'] ?? '') : '';      // id, or "none" = unassigned
$fStatus   = (string)($_GET['status'] ?? 'active');                 // active | inactive | all
if (!in_array($fStatus, ['active', 'inactive', 'all'], true)) $fStatus = 'active';
$fProj     = (string)($_GET['projects'] ?? '');                      // '' any · active = with at least one active project · none = without projects (R24-003)
if (!in_array($fProj, ['', 'active', 'any', 'none'], true)) $fProj = '';
$per    = per_page();
$pageNo = max(1, (int)($_GET['page'] ?? 1));
$sel    = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';

$w = []; $args = [];
if ($staff) {
    if ($fCustomer === 'none')        $w[] = 'v.customer_id IS NULL';
    elseif ((int)$fCustomer > 0)      { $w[] = 'v.customer_id = ?'; $args[] = (int)$fCustomer; }
} else {
    $w[] = 'v.customer_id = ?'; $args[] = (int)$u['customer_id'];
    if ($fStatus === 'all') $fStatus = 'active';                       // customers see active vessels only
}
if ($fStatus !== 'all') { $w[] = 'v.is_active = ?'; $args[] = $fStatus === 'active' ? 1 : 0; }
if (function_exists('vm_has') && vm_has()) $w[] = 'v.merged_into IS NULL';   // R30-001: retired duplicates never list
if ($fProj === 'active') $w[] = "EXISTS (SELECT 1 FROM projects p WHERE p.vessel_id = v.id AND p.status = 'active')";
elseif ($fProj === 'any') $w[] = 'EXISTS (SELECT 1 FROM projects p WHERE p.vessel_id = v.id)';
elseif ($fProj === 'none') $w[] = 'NOT EXISTS (SELECT 1 FROM projects p WHERE p.vessel_id = v.id)';
if ($q !== '') { $w[] = '(v.name LIKE ? OR v.imo LIKE ? OR v.yard LIKE ? OR c.name LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $q . '%')); }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';
$from  = "FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id {$where}";
$total = (int) scalar("SELECT COUNT(*) {$from}", $args);
$vessels = all("SELECT v.*, c.name AS customer_name,
                       (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id) AS n_projects,
                       (SELECT COUNT(*) FROM projects p WHERE p.vessel_id = v.id AND p.status = 'active') AS n_active
                {$from} ORDER BY v.is_active DESC, c.name, v.name LIMIT " . $per . ' OFFSET ' . (($pageNo - 1) * $per), $args);
$keep = array_filter(['q' => $q, 'customer' => $fCustomer, 'status' => $fStatus, 'projects' => $fProj, 'per' => $per]);
$customers = $staff ? all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name') : [];

layout_head(t('v.title'), 'vessels');
?>
<div class="page-head">
  <div>
    <h1><?= tt('v.title') ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= $staff ? e(t('v.lede_staff', ['n' => $total])) : tt('v.lede') ?></p>
  </div>
  <div class="btn-row">
    <?php if (is_cp_admin()): ?><a class="btn btn--ghost" href="<?= url('vessels_export', $keep) ?>">⤓ <?= tt('ct.export') ?></a><?php endif; ?>
    <?php if ($staff): ?>
      <a class="btn btn--ghost" href="<?= url('import') ?>"><?= tt('nav.import') ?></a>
      <a class="btn btn--gold" href="<?= url('vessel_new') ?>"><?= tt('v.new') ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr <?= $staff ? '1fr 1fr ' : '' ?>1fr auto auto;">
    <input type="hidden" name="p" value="vessels">
    <div class="field">
      <label for="q"><?= tt('pr.search') ?></label>
      <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="<?= $staff ? tt('v.search_ph') : tt('v.search_ph_cust') ?>">
    </div>
    <?php if ($staff): ?>
    <div class="field">
      <label for="customer"><?= tt('v.customer') ?></label>
      <select id="customer" name="customer"><option value=""><?= tt('flt.any') ?></option><option value="none"<?= $sel('none', $fCustomer) ?>><?= tt('v.unassigned') ?></option>
        <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $fCustomer) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="status"><?= tt('th.status') ?></label>
      <select id="status" name="status">
        <option value="active"<?= $sel('active', $fStatus) ?>><?= tt('v.active') ?></option>
        <option value="inactive"<?= $sel('inactive', $fStatus) ?>><?= tt('v.inactive') ?></option>
        <option value="all"<?= $sel('all', $fStatus) ?>><?= tt('flt.all') ?></option>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="projects"><?= tt('pr.title') ?></label>
      <select id="projects" name="projects">
        <option value=""<?= $sel('', $fProj) ?>><?= tt('flt.any') ?></option>
        <option value="active"<?= $sel('active', $fProj) ?>><?= tt('v.f_active_projects') ?></option>
        <option value="any"<?= $sel('any', $fProj) ?>><?= tt('v.f_any_projects') ?></option>
        <option value="none"<?= $sel('none', $fProj) ?>><?= tt('v.f_no_projects') ?></option>
      </select>
    </div>
    <div class="field">
      <label for="per"><?= tt('pg.per') ?></label>
      <select id="per" name="per"><?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>"<?= $sel($n, $per) ?>><?= $n ?></option><?php endforeach; ?></select>
    </div>
    <div class="fltbar-actions">
      <button class="btn" type="submit"><?= tt('btn.apply') ?></button>
      <a class="btn btn--ghost" href="<?= url('vessels', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a>
    </div>
  </form>
</div>

<div class="card">
<?php if (!$vessels): ?>
  <div class="empty"><?= ($q !== '' || $fCustomer !== '' || $fStatus !== 'active') ? tt('flt.none') : tt('v.empty') ?></div>
<?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr>
      <th><?= tt('nav.vessels') ?></th>
      <?php if ($staff): ?><th><?= tt('v.customer') ?></th><?php endif; ?>
      <th><?= tt('v.imo') ?></th><th><?= tt('v.yard') ?></th><th><?= tt('v.projects') ?></th>
      <?php if ($staff): ?><th><?= tt('th.status') ?></th><?php endif; ?>
      <th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($vessels as $v): ?>
      <tr>
        <td><b><a href="<?= url('vessel', ['id' => $v['id']]) ?>"><?= e($v['name']) ?></a></b></td>
        <?php if ($staff): ?><td><?= $v['customer_name'] ? e($v['customer_name']) : '<span class="pill pill--gold">' . tt('v.unassigned') . '</span>' ?></td><?php endif; ?>
        <td class="num"><?= e($v['imo'] ?: '—') ?></td>
        <td><?= e($v['yard'] ?: '—') ?></td>
        <td class="num"><?= (int)$v['n_projects'] ?><?= (int)$v['n_active'] ? ' <span class="hint" title="' . tt('v.f_active_projects') . '">(' . (int)$v['n_active'] . ' ' . tt('pr.active') . ')</span>' : '' ?></td>
        <?php if ($staff): ?><td><?= $v['is_active'] ? pill('green', t('v.active')) : pill('mute', t('v.inactive')) ?></td><?php endif; ?>
        <td class="row-actions" style="text-align:right;white-space:nowrap;">
          <a class="btn btn--ghost btn--sm" href="<?= url('vessel', ['id' => $v['id']]) ?>"><?= tt('vh.history') ?></a>
          <a class="btn btn--ghost btn--sm" href="<?= url('report_vessel', ['id' => $v['id']]) ?>" target="_blank" title="<?= tt('vh.report_pdf') ?>">⤓ PDF</a>
          <a class="btn btn--ghost btn--sm" href="<?= url('weekly_vessel', ['vessel' => $v['id']]) ?>" title="<?= tt('wv.title') ?>">📅 <?= tt('wk.week') ?></a>
          <a class="btn btn--ghost btn--sm" href="<?= url('projects', ['vessel' => $v['id']]) ?>"><?= tt('nav.projects') ?></a>
          <?php if ($staff): ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('timesheets', ['view' => 'all', 'vessel' => $v['id'], 'group' => 'project']) ?>"><?= tt('nav.timesheets') ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= url('manhours', ['vessel' => $v['id']]) ?>"><?= tt('mh.title') ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= url('vessel_edit', ['id' => $v['id']]) ?>">✎ <?= tt('btn.edit') ?></a>
          <?php else: ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('documents', ['vessel' => $v['id']]) ?>"><?= tt('nav.docs') ?></a>
          <?php endif; ?>
          <?php if (is_cp_admin()): ?>
            <form method="post" action="<?= url('vessels') ?>" data-lock data-confirm="<?= e(t('v.delete_confirm', ['name' => $v['name'], 'n' => (int)$v['n_projects']])) ?>"
                  data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
              <?= csrf_field() ?><input type="hidden" name="action" value="vessel_delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 <?= tt('btn.delete') ?></button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?= pager_html('vessels', $pageNo, $total, $per, $keep) ?>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

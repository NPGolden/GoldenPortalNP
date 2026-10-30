<?php
/** Settings › Customers — the owners whose people sign in: master data and invoicing details.   GAS-CP-R19-001
 *   ?p=admin_customers[&edit=ID][&q=&status=all|active|inactive][&iv=ID] */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$q = trim((string)($_GET['q'] ?? '')); $st = (string)($_GET['status'] ?? 'active'); if (!in_array($st, ['all', 'active', 'inactive'], true)) $st = 'active';
$editId = (int)($_GET['edit'] ?? 0); $edit = $editId ? cu_load($editId) : null; $isNew = isset($_GET['edit']) && !$editId;
$ext = cu_cols_ok(); $ivOk = iv_has();
$w = []; $b = [];
if ($q !== '') { $l = '%' . $q . '%'; $w[] = '(c.name LIKE ? OR c.erp_code LIKE ? OR c.country LIKE ?' . ($ext ? ' OR c.vat_no LIKE ? OR c.city LIKE ?' : '') . ')'; array_push($b, $l, $l, $l); if ($ext) array_push($b, $l, $l); }
if ($st !== 'all') $w[] = 'c.is_active = ' . ($st === 'active' ? 1 : 0);
$rows = all('SELECT c.*, (SELECT COUNT(*) FROM vessels v WHERE v.customer_id = c.id) AS n_vessels, (SELECT COUNT(*) FROM projects p WHERE p.customer_id = c.id) AS n_projects,
                    (SELECT COUNT(*) FROM customer_users u WHERE u.customer_id = c.id AND u.is_active = 1) AS n_users' . ($ivOk ? ", (SELECT COUNT(*) FROM invoicing_details i WHERE i.owner_type = 'customer' AND i.owner_id = c.id AND i.is_active = 1) AS n_iv" : ', 0 AS n_iv')
          . ' FROM customers c ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY c.is_active DESC, c.name', $b);
$tot = one('SELECT COUNT(*) n, SUM(is_active = 1) a FROM customers');
$v = fn(string $k, $d = '') => e((string)($edit[$k] ?? $d));
$keep = array_filter(['q' => $q, 'status' => $st !== 'active' ? $st : null]);
$back = url('admin_customers', $keep + ['edit' => $editId]);
layout_head(t('cu.title'), 'admin_customers');
?>
<?php settings_tabs('admin_customers'); ?>
<div class="page-head">
  <div><h2 class="settings-h"><?= tt('cu.title') ?></h2><p class="lede"><?= tt('cu.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url('settings', ['tab' => 'import']) ?>#customers">⤒ <?= tt('im.title') ?></a><a class="btn btn--ghost" href="<?= url('customers_export', $keep) ?>">⤓ <?= tt('ct.export') ?></a><a class="btn btn--gold" href="<?= url('admin_customers', $keep + ['edit' => 0]) ?>#form">+ <?= tt('cu.new') ?></a></div>
</div>
<?php if (!$ext || !$ivOk): ?><div class="flash flash--warn"><?= tt('cu.need_r19') ?></div><?php endif; ?>

<div class="cols ct-cols cu-cols">
<div>
  <div class="card">
    <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1fr auto;">
      <input type="hidden" name="p" value="admin_customers">
      <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>" placeholder="<?= tt('cu.search_ph') ?>"></div>
      <div class="field"><label for="status"><?= tt('cu.status') ?></label><select id="status" name="status"><option value="active"<?= $st === 'active' ? ' selected' : '' ?>><?= tt('au.active') ?></option><option value="inactive"<?= $st === 'inactive' ? ' selected' : '' ?>><?= tt('au.disabled') ?></option><option value="all"<?= $st === 'all' ? ' selected' : '' ?>><?= tt('flt.any') ?></option></select></div>
      <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('admin_customers', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
    </form>
  </div>
  <div class="card">
    <h2><?= tt('cu.list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($rows) ?> / <?= (int)$tot['n'] ?></span></h2>
    <?php if (!$rows): ?><div class="empty"><?= tt('cu.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('rp.customer') ?></th><th><?= tt('cu.erp') ?></th><th><?= tt('iv.vat_no') ?></th><th class="num"><?= tt('nav.vessels') ?></th><th class="num"><?= tt('nav.projects') ?></th><th class="num"><?= tt('au.people') ?></th><th class="num"><?= tt('iv.short') ?></th><th></th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr<?= $r['is_active'] ? '' : ' class="is-retired"' ?><?= $editId === (int)$r['id'] ? ' style="background:var(--cp-gold-dim);"' : '' ?>>
          <td><a href="<?= url('admin_customers', $keep + ['edit' => $r['id']]) ?>#form"><b><?= e($r['name']) ?></b></a><?= !$r['is_active'] ? ' ' . pill('mute', t('au.disabled')) : '' ?><?= ($pl = implode(', ', array_filter([$r['city'] ?? null, $r['country']]))) ? '<br><span class="hint">' . e($pl) . '</span>' : '' ?></td>
          <td class="mono"><?= e($r['erp_code'] ?? '') ?: '<span class="hint">—</span>' ?></td>
          <td class="mono"><?= e($r['vat_no'] ?? '') ?: '<span class="hint">—</span>' ?></td>
          <td class="num"><a href="<?= url('vessels', ['customer' => $r['id']]) ?>"><?= (int)$r['n_vessels'] ?></a></td>
          <td class="num"><?= (int)$r['n_projects'] ?></td>
          <td class="num"><a href="<?= url('admin_users', ['customer' => $r['id']]) ?>"><?= (int)$r['n_users'] ?></a></td>
          <td class="num"><?= (int)$r['n_iv'] ?: '<span class="hint">—</span>' ?></td>
          <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('admin_customers', $keep + ['edit' => $r['id']]) ?>#form"><?= tt('btn.edit') ?></a></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
  </div>
</div>

<div>
  <?php if ($edit || $isNew): ?>
  <div class="card" id="form">
    <h2><?= $edit ? tt('cu.edit') . ' — ' . e($edit['name']) : tt('cu.new') ?></h2>
    <?php if ($edit): ?><p class="hint" style="margin:-6px 0 12px;"><?= e(t('cu.refs', ['v' => (int)$edit['n_vessels'], 'p' => (int)$edit['n_projects'], 'u' => (int)$edit['n_users']])) ?></p><?php endif; ?>
    <form method="post" action="<?= $back ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="cu_customer_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="grid grid-2">
        <div class="field"><label for="name"><?= tt('rp.customer') ?> *</label><input id="name" name="name" required maxlength="160" value="<?= $v('name') ?>"></div>
        <div class="field"><label for="erp_code"><?= tt('cu.erp') ?></label><input id="erp_code" name="erp_code" maxlength="32" value="<?= $v('erp_code') ?>" style="font-family:var(--mono);"><p class="hint"><?= tt('cu.erp_hint') ?></p></div>
      </div>
      <?php if ($ext): ?>
      <div class="field"><label for="address"><?= tt('iv.address') ?></label><input id="address" name="address" maxlength="200" value="<?= $v('address') ?>"></div>
      <div class="grid grid-3">
        <div class="field"><label for="postal_code"><?= tt('iv.postal_code') ?></label><input id="postal_code" name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>"></div>
        <div class="field"><label for="city"><?= tt('co.city') ?></label><input id="city" name="city" maxlength="80" value="<?= $v('city') ?>"></div>
        <div class="field"><label for="country"><?= tt('co.country') ?></label><input id="country" name="country" maxlength="80" value="<?= $v('country') ?>"></div>
      </div>
      <div class="grid grid-2">
        <div class="field"><label for="vat_no"><?= tt('iv.vat_no') ?></label><input id="vat_no" name="vat_no" maxlength="40" value="<?= $v('vat_no') ?>"></div>
        <div class="field"><label for="website"><?= tt('co.website') ?></label><input id="website" name="website" maxlength="190" value="<?= $v('website') ?>"></div>
        <div class="field"><label for="email"><?= tt('au.email') ?></label><input id="email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
        <div class="field"><label for="phone"><?= tt('ct.phone') ?></label><input id="phone" name="phone" maxlength="60" value="<?= $v('phone') ?>"></div>
      </div>
      <div class="field"><label for="notes"><?= tt('ct.notes') ?></label><textarea id="notes" name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      <?php else: ?>
      <div class="field"><label for="country"><?= tt('co.country') ?></label><input id="country" name="country" maxlength="80" value="<?= $v('country') ?>"></div>
      <?php endif; ?>
      <?php if ($edit): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $edit['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><p class="hint"><?= tt('cu.inactive_hint') ?></p><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $edit ? tt('btn.save') : tt('cu.create') ?></button><a class="btn btn--ghost" href="<?= url('admin_customers', $keep) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
    <?php if ($edit && !((int)$edit['n_vessels'] + (int)$edit['n_projects'] + (int)$edit['n_users'])): ?>
    <form method="post" action="<?= $back ?>" style="margin-top:14px;" data-confirm="<?= e(t('cu.delete_confirm', ['name' => $edit['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="cu_customer_delete"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><button class="btn btn--ghost btn--danger-ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form>
    <?php endif; ?>
  </div>
  <?php if ($edit): ?>
    <?php if (function_exists('co_has') && co_has()): $cos = all('SELECT id, name, kind FROM companies WHERE customer_id = ? ORDER BY name', [$editId]); ?>
    <div class="card card--flat"><h3 class="ed-h"><?= tt('co.title') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($cos) ?></span></h3>
      <?php if (!$cos): ?><p class="hint" style="margin:0;"><?= tt('cu.no_companies') ?></p><?php else: ?><p style="margin:0;"><?php foreach ($cos as $c): ?><a class="btn btn--ghost btn--sm" style="margin:0 6px 6px 0;" href="<?= url('companies', ['edit' => $c['id']]) ?>#form"><?= e($c['name']) ?>&nbsp; <?= co_kind_pill($c['kind']) ?></a><?php endforeach; ?></p><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php iv_section('customer', $editId, $back, (int)($_GET['iv'] ?? 0)); ?>
  <?php endif; ?>
  <?php else: ?>
  <div class="card card--flat"><h3 class="ed-h"><?= tt('cu.about') ?></h3><p class="hint" style="margin:0;"><?= tt('cu.about_text') ?></p></div>
  <?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

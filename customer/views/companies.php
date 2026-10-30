<?php
/** Companies master list (owners, management, yards, class, suppliers…) — administrators edit, staff read.   GAS-CP-R17-002
 *   ?p=companies[&edit=ID][&q=&kind=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!co_has()) { layout_head(t('co.title'), 'contacts'); echo '<h1>' . tt('co.title') . '</h1><div class="flash flash--warn">' . tt('co.need_r17b') . '</div>'; layout_foot(); return; }
$admin = is_cp_admin();
$q = trim((string)($_GET['q'] ?? '')); $fk = (string)($_GET['kind'] ?? ''); $editId = (int)($_GET['edit'] ?? 0);
$edit = $editId ? co_load($editId) : null;
$w = []; $b = [];
if ($q !== '') { $l = '%' . $q . '%'; $w[] = '(co.name LIKE ? OR co.country LIKE ? OR co.city LIKE ? OR c.name LIKE ?)'; array_push($b, $l, $l, $l, $l); }
if ($fk !== '' && isset(co_kinds()[$fk])) { $w[] = 'co.kind = ?'; $b[] = $fk; }
$rows = all('SELECT co.*, c.name AS customer_name, (SELECT COUNT(*) FROM contacts ct WHERE ct.company_id = co.id) AS n_contacts FROM companies co LEFT JOIN customers c ON c.id = co.customer_id ' . ($w ? 'WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY co.is_active DESC, co.name', $b);
$customers = all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name');
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$v = fn(string $k, $d = '') => e((string)($edit[$k] ?? $d));
layout_head(t('co.title'), 'contacts');
?>
<p class="eyebrow"><a href="<?= url('contacts') ?>"><?= tt('ct.title') ?></a></p>
<div class="page-head">
  <div><h1><?= tt('co.title') ?></h1><p class="lede"><?= tt('co.lede') ?></p></div>
  <?php if ($admin): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('settings', ['tab' => 'import']) ?>#companies">⤒ <?= tt('im.title') ?></a><a class="btn btn--ghost" href="<?= url('companies_export', array_filter(['q' => $q, 'kind' => $fk])) ?>">⤓ <?= tt('ct.export') ?></a><a class="btn btn--gold" href="<?= url('companies', ['edit' => 0]) ?>#form">+ <?= tt('co.new') ?></a></div><?php endif; ?>
</div>

<div class="cols ct-cols">
<div>
  <div class="card">
    <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1fr auto;">
      <input type="hidden" name="p" value="companies">
      <div class="field"><label for="q"><?= tt('pr.search') ?></label><input id="q" name="q" value="<?= e($q) ?>"></div>
      <div class="field"><label for="kind"><?= tt('co.kind') ?></label><select id="kind" name="kind"><option value=""><?= tt('flt.any') ?></option><?php foreach (co_kinds() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $fk) ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
      <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('companies', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
    </form>
  </div>
  <div class="card">
    <h2><?= tt('co.list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($rows) ?></span></h2>
    <?php if (!$rows): ?><div class="empty"><?= tt('co.none') ?></div><?php else: ?>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('co.one') ?></th><th><?= tt('co.kind') ?></th><th><?= tt('rp.customer') ?></th><th><?= tt('co.place') ?></th><th><?= tt('ct.reach') ?></th><th class="num"><?= tt('ct.title') ?></th><?php if ($admin): ?><th></th><?php endif; ?></tr></thead>
      <tbody><?php [$pageRows, $pager] = page_list($rows, 'companies', ['q' => $q, 'kind' => $fk]); foreach ($pageRows as $r): ?>
        <tr<?= $r['is_active'] ? '' : ' class="is-retired"' ?>>
          <td><a href="<?= url('contacts', ['company' => $r['id'], 'status' => 'all']) ?>"><b><?= e($r['name']) ?></b></a><?= $r['website'] ? '<br><a class="hint" href="' . e(preg_match('#^https?://#', $r['website']) ? $r['website'] : 'https://' . $r['website']) . '" target="_blank" rel="noopener">' . e($r['website']) . '</a>' : '' ?></td>
          <td><?= co_kind_pill($r['kind']) ?></td>
          <td><?= $r['customer_name'] ? e($r['customer_name']) : '<span class="hint">—</span>' ?></td>
          <td><?= e(implode(', ', array_filter([$r['city'], $r['country']]))) ?: '<span class="hint">—</span>' ?></td>
          <td class="ct-reach"><?= $r['email'] ? '<a href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a><br>' : '' ?><?= e($r['phone'] ?? '') ?></td>
          <td class="num"><?= (int)$r['n_contacts'] ?></td>
          <?php if ($admin): ?><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('companies', ['edit' => $r['id'], 'q' => $q, 'kind' => $fk]) ?>#form"><?= tt('btn.edit') ?></a>
            <?php if (!(int)$r['n_contacts']): ?><form method="post" action="<?= url('companies') ?>" data-confirm="<?= e(t('co.delete_confirm', ['name' => $r['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="co_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?= $pager ?? '' ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($admin): ?>
<div>
  <div class="card" id="form">
    <h2><?= $edit ? tt('co.edit') . ' — ' . e($edit['name']) : tt('co.new') ?></h2>
    <form method="post" action="<?= url('companies') ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="co_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="field"><label for="name"><?= tt('co.one') ?> *</label><input id="name" name="name" required maxlength="160" value="<?= $v('name') ?>"></div>
      <div class="grid grid-2">
        <div class="field"><label for="kind_f"><?= tt('co.kind') ?></label><select id="kind_f" name="kind"><?php foreach (co_kinds() as $k => $lab): ?><option value="<?= $k ?>"<?= $sel($k, $edit['kind'] ?? 'other') ?>><?= e($lab) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="customer_id"><?= tt('rp.customer') ?></label><select id="customer_id" name="customer_id"><option value=""><?= tt('ct.no_customer') ?></option><?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $edit['customer_id'] ?? 0) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('co.customer_hint') ?> <a href="<?= url('admin_customers') ?>"><?= tt('cu.manage') ?> ›</a></p></div>
        <div class="field"><label for="country"><?= tt('co.country') ?></label><input id="country" name="country" maxlength="80" value="<?= $v('country') ?>"></div>
        <div class="field"><label for="city"><?= tt('co.city') ?></label><input id="city" name="city" maxlength="80" value="<?= $v('city') ?>"></div>
        <div class="field"><label for="website"><?= tt('co.website') ?></label><input id="website" name="website" maxlength="190" value="<?= $v('website') ?>" placeholder="www.example.com"></div>
        <div class="field"><label for="email"><?= tt('au.email') ?></label><input id="email" name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
        <div class="field"><label for="phone"><?= tt('ct.phone') ?></label><input id="phone" name="phone" maxlength="60" value="<?= $v('phone') ?>"></div>
      </div>
      <div class="field"><label for="notes"><?= tt('ct.notes') ?></label><textarea id="notes" name="notes" rows="3"><?= $v('notes') ?></textarea></div>
      <?php if ($edit): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $edit['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $edit ? tt('btn.save') : tt('co.create') ?></button><?php if ($edit): ?><a class="btn btn--ghost" href="<?= url('companies') ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
  </div>
  <?php if ($edit && function_exists('iv_section')) iv_section('company', (int)$edit['id'], url('companies', array_filter(['edit' => $edit['id'], 'q' => $q, 'kind' => $fk])), (int)($_GET['iv'] ?? 0)); ?>
</div>
<?php endif; ?>
</div>
<?php layout_foot(); ?>

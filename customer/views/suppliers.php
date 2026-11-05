<?php
/** Procurement › Suppliers — companies of kind supplier with the procurement fields, the price list and the invoicing/bank details (GAS-CP-R79-001). ?p=suppliers[&q=&status=][&id=<n>][&edit=1] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('pr_can') || !pr_can()) { http_response_code(403); layout_head(t('sup.title'), 'suppliers'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$admin = pr_admin(); $priced = stk_price_visible(); $q = trim((string)($_GET['q'] ?? '')); $st = in_array($_GET['status'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['status'] : 'active';
$id = (int)($_GET['id'] ?? 0); $sup = $id ? sup_load($id) : null; $edit = ($id === 0 && isset($_GET['new']) && $admin) || ($sup && isset($_GET['edit']) && $admin);
$rows = sup_list(['q' => $q, 'status' => $st]); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $v = fn(string $k) => e((string)($sup[$k] ?? ''));
$prices = $sup && $priced ? sup_prices($id) : []; $contacts = $sup ? all('SELECT id, full_name, position AS role, email, phone FROM contacts WHERE company_id = ? ORDER BY full_name', [$id]) : [];
layout_head(t('sup.title'), 'suppliers');
?>
<div class="page-head">
  <div><h1>🏭 <?= tt('sup.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sup.lede') ?></p></div>
  <?php if ($admin): ?><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('suppliers', ['new' => 1]) ?>#sup-form">+ <?= tt('sup.new') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('suppliers_import') ?>">⤒ <?= tt('sup.import') ?></a></div><?php endif; ?>
</div>
<div class="cols">
<div>
  <div class="card">
    <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr 1fr auto;"><input type="hidden" name="p" value="suppliers">
      <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($q) ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="active"<?= $sel('active', $st) ?>><?= tt('au.active') ?></option><option value="inactive"<?= $sel('inactive', $st) ?>><?= tt('sup.inactive') ?></option><option value="all"<?= $sel('all', $st) ?>><?= tt('flt.any') ?></option></select></div>
      <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div></form>
    <h2><?= tt('sup.list') ?> <span class="count"><?= count($rows) ?></span></h2>
    <?php if (!$rows): ?><p class="hint"><?= tt('sup.none') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sup.one') ?></th><th><?= tt('co.place') ?></th><th><?= tt('sup.terms') ?></th><th><?= tt('sup.lead') ?></th><th class="num"><?= tt('stk.items') ?></th><th><?= tt('sup.rating') ?></th><th></th></tr></thead><tbody>
      <?php [$pageRows, $pager] = page_list($rows, 'suppliers', ['q' => $q, 'status' => $st]); foreach ($pageRows as $r): ?>
      <tr class="<?= $r['is_active'] ? '' : 'is-retired' ?><?= $id === (int)$r['id'] ? ' is-here' : '' ?>"><td><a href="<?= url('suppliers', ['id' => (int)$r['id']]) ?>"><b><?= e($r['name']) ?></b></a><?= $r['email'] ? '<br><a class="hint" href="mailto:' . e($r['email']) . '">' . e($r['email']) . '</a>' : '' ?></td><td class="hint"><?= e(implode(', ', array_filter([$r['city'], $r['country']]))) ?></td><td class="hint"><?= $r['terms_days'] !== null ? e(t('sup.days_n', ['n' => (int)$r['terms_days']])) . ((float)$r['advance_pct'] > 0 ? ' · ' . e(rtrim(rtrim(number_format((float)$r['advance_pct'], 2, '.', ''), '0'), '.')) . ' % ' . tt('sup.advance') : '') : '—' ?></td><td class="hint"><?= $r['lead_days'] !== null ? e(t('sup.days_n', ['n' => (int)$r['lead_days']])) : '—' ?></td><td class="num"><?= (int)$r['n_items'] ?><?= $r['last_priced'] ? '<br><span class="hint">' . e(fmt_date($r['last_priced'])) . '</span>' : '' ?></td><td><?= $r['rating'] ? str_repeat('★', (int)$r['rating']) . '<span class="hint">' . str_repeat('☆', 5 - (int)$r['rating']) . '</span>' : '<span class="hint">—</span>' ?></td><td><a class="btn btn--ghost btn--sm" href="<?= url('suppliers', ['id' => (int)$r['id']]) ?>"><?= tt('btn.open') ?></a></td></tr>
      <?php endforeach; ?></tbody></table></div><?= $pager ?? '' ?>
    <?php endif; ?>
  </div>
</div>
<div>
  <?php if ($edit): ?>
  <div class="card" id="sup-form">
    <h2><?= $sup ? tt('btn.edit') . ' — ' . e($sup['name']) : tt('sup.new') ?></h2>
    <form method="post" action="<?= url('suppliers', $sup ? ['id' => $id] : []) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sup_save"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label><?= tt('sup.one') ?> *</label><input name="name" required maxlength="160" value="<?= $v('name') ?>"></div>
      <div class="grid grid-2">
        <div class="field"><label><?= tt('sup.vat') ?></label><input name="vat_no" maxlength="40" value="<?= $v('vat_no') ?>"></div><div class="field"><label><?= tt('sup.erp_code') ?></label><input name="erp_code" maxlength="40" value="<?= $v('erp_code') ?>"></div><div class="field"><label><?= tt('au.email') ?></label><input name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
        <div class="field"><label><?= tt('ct.phone') ?></label><input name="phone" maxlength="60" value="<?= $v('phone') ?>"></div><div class="field"><label><?= tt('co.website') ?></label><input name="website" maxlength="190" value="<?= $v('website') ?>"></div>
        <div class="field" style="grid-column:1/-1;"><label><?= tt('sup.address') ?></label><input name="address" maxlength="200" value="<?= $v('address') ?>"></div>
        <div class="field"><label><?= tt('sup.postal') ?></label><input name="postal_code" maxlength="20" value="<?= $v('postal_code') ?>"></div><div class="field"><label><?= tt('co.city') ?></label><input name="city" maxlength="80" value="<?= $v('city') ?>"></div>
        <div class="field"><label><?= tt('co.country') ?></label><input name="country" maxlength="80" value="<?= $v('country') ?>"></div><div class="field"><label><?= tt('pt.currency') ?></label><input name="currency" maxlength="3" value="<?= e($sup['sup_currency'] ?? 'EUR') ?>"></div>
        <div class="field"><label><?= tt('sup.terms') ?> (<?= tt('sup.days') ?>)</label><input name="terms_days" type="number" min="0" value="<?= $v('terms_days') ?>"></div><div class="field"><label><?= tt('sup.advance') ?> %</label><input name="advance_pct" type="number" step="0.01" min="0" max="100" value="<?= $v('advance_pct') ?>"></div>
        <div class="field"><label><?= tt('sup.lead') ?> (<?= tt('sup.days') ?>)</label><input name="lead_days" type="number" min="0" value="<?= $v('lead_days') ?>"></div><div class="field"><label>Incoterms</label><input name="incoterms" maxlength="20" value="<?= $v('incoterms') ?>" placeholder="EXW · DAP …"></div>
        <div class="field"><label><?= tt('sup.rating') ?></label><select name="rating" data-nosearch><option value="">—</option><?php for ($i = 5; $i >= 1; $i--): ?><option value="<?= $i ?>"<?= $sel($i, $sup['rating'] ?? '') ?>><?= str_repeat('★', $i) ?></option><?php endfor; ?></select></div>
      </div>
      <div class="field"><label><?= tt('sup.remarks') ?></label><textarea name="sup_remarks" rows="3"><?= $v('sup_remarks') ?></textarea></div>
      <div class="field"><label><?= tt('ct.notes') ?></label><textarea name="notes" rows="2"><?= $v('notes') ?></textarea></div>
      <?php if ($sup): ?><label class="chk"><input type="checkbox" name="is_active" value="1"<?= $sup['is_active'] ? ' checked' : '' ?>> <?= tt('au.active') ?></label><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('suppliers', $sup ? ['id' => $id] : []) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
  </div>
  <?php elseif ($sup): ?>
  <div class="card">
    <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;"><?= e($sup['name']) ?> <?= $sup['is_active'] ? '' : pill('mute', t('sup.inactive')) ?></h2><?php if ($admin): ?><a class="btn btn--ghost btn--sm" href="<?= url('suppliers', ['id' => $id, 'edit' => 1]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?></div>
    <div class="kv-grid">
      <div class="kv"><span><?= tt('sup.vat') ?></span><b><?= e($sup['vat_no'] ?: '—') ?></b></div><div class="kv"><span><?= tt('co.place') ?></span><b><?= e(implode(', ', array_filter([$sup['address'], $sup['postal_code'], $sup['city'], $sup['country']])) ?: '—') ?></b></div>
      <div class="kv"><span><?= tt('sup.terms') ?></span><b><?= $sup['terms_days'] !== null ? e(t('sup.days_n', ['n' => (int)$sup['terms_days']])) : '—' ?><?= (float)$sup['advance_pct'] > 0 ? ' · ' . e(rtrim(rtrim(number_format((float)$sup['advance_pct'], 2, '.', ''), '0'), '.')) . ' % ' . tt('sup.advance') : '' ?></b></div><div class="kv"><span><?= tt('sup.lead') ?></span><b><?= $sup['lead_days'] !== null ? e(t('sup.days_n', ['n' => (int)$sup['lead_days']])) : '—' ?><?= $sup['incoterms'] ? ' · ' . e($sup['incoterms']) : '' ?></b></div>
      <div class="kv"><span><?= tt('pt.currency') ?></span><b><?= e($sup['sup_currency'] ?? 'EUR') ?></b></div><div class="kv"><span><?= tt('sup.rating') ?></span><b><?= $sup['rating'] ? str_repeat('★', (int)$sup['rating']) : '—' ?></b></div>
      <div class="kv"><span><?= tt('ct.reach') ?></span><b><?= $sup['email'] ? '<a href="mailto:' . e($sup['email']) . '">' . e($sup['email']) . '</a>' : '' ?><?= $sup['phone'] ? ' · ' . e($sup['phone']) : '' ?><?= $sup['website'] ? ' · <a href="' . e(preg_match('#^https?://#', $sup['website']) ? $sup['website'] : 'https://' . $sup['website']) . '" target="_blank" rel="noopener">' . e($sup['website']) . '</a>' : '' ?></b></div>
      <?php if ($sup['sup_remarks']): ?><div class="kv" style="grid-column:1/-1;"><span><?= tt('sup.remarks') ?></span><b style="font-weight:400;"><?= nl2br(e($sup['sup_remarks'])) ?></b></div><?php endif; ?>
    </div>
    <?php if (function_exists('sup_makers_has') && sup_makers_has()): $mk = sup_makers($id); ?>
    <div style="margin-top:10px;"><b><?= tt('sup.makers') ?></b> <span class="hint"><?= tt('sup.makers_hint') ?></span><br>
      <?php if (!$mk): ?><span class="hint">—</span><?php endif; ?>
      <?php foreach ($mk as $m): ?><span class="pill <?= $m['is_primary'] ? 'pill--gold' : 'pill--mute' ?>" style="margin:2px 4px 2px 0;"><?= e($m['maker']) ?><?= $m['is_primary'] ? ' ★' : '' ?><?php if ($admin): ?> <form method="post" action="<?= url('suppliers', ['id' => $id]) ?>" style="display:inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sup_maker_primary"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><?php if (!$m['is_primary']): ?><button class="linkbtn" type="submit" title="<?= tt('sup.maker_primary') ?>">☆</button><?php endif; ?></form><form method="post" action="<?= url('suppliers', ['id' => $id]) ?>" style="display:inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sup_maker_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('btn.delete') ?>">×</button></form><?php endif; ?></span><?php endforeach; ?>
      <?php if ($admin): ?><form method="post" action="<?= url('suppliers', ['id' => $id]) ?>" class="btn-row" style="margin-top:6px;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sup_maker_add"><input type="hidden" name="id" value="<?= $id ?>"><input name="maker" list="makers-dl" maxlength="80" placeholder="<?= tt('sup.maker_ph') ?>" required style="max-width:16em;"><datalist id="makers-dl"><?php foreach (sup_all_makers() as $m0): ?><option value="<?= e($m0) ?>"><?php endforeach; ?></datalist><label class="chk"><input type="checkbox" name="primary" value="1"> <?= tt('sup.maker_primary') ?></label><button class="btn btn--sm" type="submit">+ <?= tt('sup.maker_add') ?></button></form><?php endif; ?>
    </div>
    <?php endif; ?>
    <p class="hint" style="margin-top:8px;">👤 <?= tt('ct.title') ?>: <?php if (!$contacts): ?>— <a href="<?= url('contact_edit', ['company' => $id]) ?>"><?= tt('ct.new') ?></a><?php else: foreach ($contacts as $c): ?><a href="<?= url('contact', ['id' => (int)$c['id']]) ?>"><?= e($c['full_name']) ?></a><?= $c['role'] ? ' <span class="hint">(' . e($c['role']) . ')</span>' : '' ?> · <?php endforeach; endif; ?></p>
  </div>
  <?php if ($priced): ?><div class="card"><h2>💶 <?= tt('sup.pricelist') ?> <span class="count"><?= count($prices) ?></span></h2>
    <?php if (!$prices): ?><p class="hint"><?= tt('sup.no_prices') ?></p><?php else: ?><div class="table-scroll" style="max-height:420px;"><table class="tbl"><thead><tr><th><?= tt('stk.code') ?></th><th><?= tt('stk.desc') ?></th><th class="num"><?= tt('stk.unit_price') ?></th><th><?= tt('th.date') ?></th></tr></thead><tbody>
      <?php foreach ($prices as $p): ?><tr><td class="mono"><a href="<?= url('inventory_item', ['id' => (int)$p['item_id']]) ?>"><?= e($p['code']) ?></a></td><td class="hint"><?= e($p['description']) ?></td><td class="num"><b><?= e(stk_money((float)$p['unit_price'], $p['currency'], 2)) ?></b> <span class="hint">/ <?= e($p['unit']) ?></span></td><td class="date hint"><?= e(fmt_date($p['priced_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div><?php endif; ?>
  <?php if ($admin && function_exists('iv_section')) iv_section('company', $id, url('suppliers', ['id' => $id]), (int)($_GET['iv'] ?? 0)); ?>
  <?php else: ?><div class="card"><p class="hint"><?= tt('sup.pick') ?></p></div><?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

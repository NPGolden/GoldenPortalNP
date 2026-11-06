<?php
/** Procurement › Inventory › item card — details, stock per warehouse, movements, price history and the price proposal (GAS-CP-R79-001). ?p=inventory_item&id=<n> (0 = new) */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('pr_can') || !pr_can()) { http_response_code(403); exit(t('err.noaccess')); }
$id = (int)($_GET['id'] ?? 0); $it = $id ? stk_item($id) : null; if ($id && !$it) { http_response_code(404); layout_head(t('stk.title'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$admin = pr_admin(); $wh = pr_wh(); $priced = stk_price_visible(); $edit = !$it || (isset($_GET['edit']) && $admin);
if (!$it && !$admin) { http_response_code(403); exit(t('err.noaccess')); }
$cats = stk_categories(false); $whs = stk_warehouses(); $sups = function_exists('sup_list') ? sup_list(['status' => 'active']) : []; $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$stock = $it ? stk_stock_of($id) : []; $moves = $it ? stk_moves_of($id) : []; $prices = $it && $priced ? stk_prices_of($id) : []; $prop = $it && $priced ? stk_price_proposal($it) : null;
$cur = $it['currency'] ?? 'EUR'; $m = fn(?float $v) => stk_money($v, $cur, 4 === 0 ? 2 : 2);
$subs = $wh ? all("SELECT sp.id, sp.code, sp.name, p.project_code FROM subprojects sp JOIN projects p ON p.id = sp.project_id WHERE sp.status IN ('active','quoted','onhold') ORDER BY p.project_code DESC, sp.sort_order, sp.code LIMIT 400") : [];
$v = fn(string $k) => e((string)($it[$k] ?? ''));
layout_head(($it['code'] ?? t('stk.new_item')) . ' — ' . t('stk.title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a><?= $it && $it['category'] ? ' › <a href="' . url('inventory', ['cat' => (int)$it['category_id']]) . '">' . e($it['category']) . '</a>' : '' ?></p>
<div class="page-head">
  <div><h1><?= $it ? '📦 ' . e($it['code']) : '📦 ' . tt('stk.new_item') ?> <?= $it && $it['status'] !== 'active' ? pill('mute', t('stk.s_' . $it['status'])) : '' ?><?= $it && function_exists('swl_item_is_software') && swl_item_is_software($it) ? ' ' . pill('navy', '🔑 ' . t('stk.t_software')) : '' ?></h1><?php if ($it): ?><p class="lede" style="margin-bottom:0;"><?= e($it['description']) ?><?= $it['description_en'] ? ' <span class="hint">· ' . e($it['description_en']) . '</span>' : '' ?></p><?php endif; ?></div>
  <?php if ($it && $admin && !$edit): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('inventory_item', ['id' => $id, 'edit' => 1]) ?>">✎ <?= tt('btn.edit') ?></a></div><?php endif; ?>
</div>

<?php if ($it): $onHand = (float)$it['on_hand']; $below = (float)$it['min_stock'] > 0 && $onHand < (float)$it['min_stock']; ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat<?= $below ? ' red' : '' ?>"><b><?= e(stk_qty($onHand)) ?> <span style="text-transform:none;font-size:.65em;font-weight:500;"><?= e($it['unit']) ?></span></b><?= tt('stk.on_hand') ?><span class="s"><?= tt('stk.reserved') ?> <?= e(stk_qty((float)$it['reserved'])) ?> · <?= tt('stk.min_stock') ?> <?= e(stk_qty((float)$it['min_stock'])) ?></span></div>
  <?php if ($priced): ?>
  <div class="stat"><b><?= $it['last_price'] !== null ? e(stk_money((float)$it['last_price'], $cur, 2)) : '—' ?></b><?= tt('stk.last_price') ?><span class="s"><?= $it['last_price_at'] ? e(fmt_date($it['last_price_at'])) : '' ?><?= $it['last_supplier'] ? ' · ' . e($it['last_supplier']) : '' ?></span></div>
  <div class="stat gold"><b><?= $prop && $prop['price'] !== null ? e(stk_money($prop['price'], $cur, 2)) : '—' ?></b><?= tt('stk.proposal') ?><span class="s"><?= e($prop['origin'] ?? '') ?><?= $prop && $prop['n'] ? ' · ' . e(t('stk.n_records', ['n' => $prop['n']])) . ($prop['age'] !== null ? ' · ' . e(t('stk.age_d', ['n' => $prop['age']])) : '') : '' ?></span></div>
  <div class="stat green"><b><?= e(stk_money($onHand * (float)($it['last_price'] ?? 0), $cur, 2)) ?></b><?= tt('stk.value') ?><span class="s"><?= tt('stk.avg_cost') ?> <?= $it['avg_cost'] !== null ? e(stk_money((float)$it['avg_cost'], $cur, 2)) : '—' ?></span></div>
  <?php else: ?><div class="stat"><b><?= e($it['location'] ?: '—') ?></b><?= tt('stk.location') ?></div><div class="stat"><b><?= e($it['maker'] ?: '—') ?></b><?= tt('stk.maker') ?><span class="s"><?= e($it['factory_code'] ?? '') ?></span></div><div class="stat"><b><?= count($moves) ?></b><?= tt('stk.moves') ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="cols">
<div>
  <?php if ($edit): ?>
  <div class="card" id="item-form">
    <h2><?= $it ? tt('btn.edit') : tt('stk.new_item') ?></h2>
    <form method="post" action="<?= url('inventory_item', ['id' => $id]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="inv_item_save"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('stk.code') ?> *</label><input name="code" required maxlength="60" value="<?= $v('code') ?>" class="mono"></div>
        <div class="field"><label><?= tt('stk.category') ?></label><select name="category_id"><option value="">—</option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($c['id'], $it['category_id'] ?? 0) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><input name="new_category" maxlength="120" placeholder="<?= tt('stk.or_new_cat') ?>" style="margin-top:4px;"></div>
      </div>
      <?php if (function_exists('swl_has') && swl_has()): ?><div class="field"><label><?= tt('stk.item_type') ?></label><select name="item_type" data-nosearch><?php foreach (ITEM_TYPES as $ty): ?><option value="<?= $ty ?>"<?= $sel($ty, $it['item_type'] ?? 'part') ?>><?= e(t('stk.t_' . $ty)) ?></option><?php endforeach; ?></select><span class="hint"><?= tt('stk.item_type_hint') ?></span></div><?php endif; ?>
      <div class="field"><label><?= tt('stk.desc') ?> *</label><input name="description" required maxlength="255" value="<?= $v('description') ?>"></div>
      <div class="field"><label><?= tt('stk.desc_en') ?></label><input name="description_en" maxlength="255" value="<?= $v('description_en') ?>"></div>
      <div class="grid grid-3">
        <div class="field"><label><?= tt('stk.unit') ?></label><select name="unit" data-nosearch><?php foreach (STK_UNITS as $un): ?><option value="<?= $un ?>"<?= $sel($un, $it['unit'] ?? 'pcs') ?>><?= $un ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.pack_qty') ?></label><input name="pack_qty" type="number" step="0.01" value="<?= $v('pack_qty') ?>"></div>
        <?php if (function_exists('stc_has') && stc_has()): ?><div class="field"><label><?= tt('stk.reorder_qty') ?></label><input name="reorder_qty" type="number" step="0.001" min="0" value="<?= e((string)($it['reorder_qty'] ?? '')) ?>"></div>
        <div class="field field--full" style="display:flex; gap:18px; flex-wrap:wrap;"><label class="chk"><input type="checkbox" name="alert_low" value="1"<?= !empty($it['alert_low']) ? ' checked' : '' ?>> 🔔 <?= tt('stk.alert_low') ?></label><label class="chk"><input type="checkbox" name="is_consumable" value="1"<?= !empty($it['is_consumable']) ? ' checked' : '' ?>> ♻ <?= tt('stk.is_consumable') ?></label></div><?php endif; ?>
        <div class="field"><label><?= tt('stk.min_stock') ?></label><input name="min_stock" type="number" step="0.001" value="<?= e((string)($it['min_stock'] ?? 0)) ?>"></div>
        <div class="field"><label><?= tt('stk.maker') ?></label><input name="maker" maxlength="80" value="<?= $v('maker') ?>" list="inv-makers"><datalist id="inv-makers"><?php foreach (stk_makers() as $mk): ?><option value="<?= e($mk) ?>"><?php endforeach; ?></datalist></div>
        <div class="field"><label><?= tt('stk.factory_code') ?></label><input name="factory_code" maxlength="80" value="<?= $v('factory_code') ?>"></div>
        <div class="field"><label><?= tt('stk.barcode') ?></label><input name="barcode" maxlength="64" value="<?= $v('barcode') ?>"></div>
        <div class="field"><label><?= tt('stk.location') ?></label><input name="location" maxlength="60" value="<?= $v('location') ?>" placeholder="CABINET 2C"></div>
        <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (['active', 'obsolete', 'blocked'] as $s): ?><option value="<?= $s ?>"<?= $sel($s, $it['status'] ?? 'active') ?>><?= e(t('stk.s_' . $s)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('stk.pref_supplier') ?></label><select name="preferred_supplier_id"><option value="">—</option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $sel($s['id'], $it['preferred_supplier_id'] ?? 0) ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('pt.currency') ?></label><input name="currency" maxlength="3" value="<?= e($it['currency'] ?? 'EUR') ?>"></div>
        <div class="field"><label><?= tt('stk.photo') ?></label><input type="file" name="photo" accept="image/*,.pdf"></div>
      </div>
      <div class="field"><label><?= tt('ct.notes') ?></label><textarea name="notes" rows="3"><?= $v('notes') ?></textarea></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($it): ?><a class="btn btn--ghost" href="<?= url('inventory_item', ['id' => $id]) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
    </form>
  </div>
  <?php elseif ($it): $isSw = function_exists('swl_item_is_software') && swl_item_is_software($it); ?>
  <div class="card">
    <h2>ℹ <?= tt('stk.details') ?></h2>
    <div class="kv-grid">
      <div class="kv"><span><?= tt('stk.category') ?></span><b><?= e($it['category'] ?: '—') ?></b></div><div class="kv"><span><?= tt('stk.unit') ?></span><b><?= e($it['unit']) ?><?= $it['pack_qty'] ? ' · ' . tt('stk.pack_qty') . ' ' . e(stk_qty((float)$it['pack_qty'])) : '' ?></b></div>
      <div class="kv"><span><?= tt('stk.maker') ?></span><b><?= e($it['maker'] ?: '—') ?></b></div><div class="kv"><span><?= tt('stk.factory_code') ?></span><b class="mono"><?= e($it['factory_code'] ?: '—') ?></b></div>
      <div class="kv"><span><?= tt('stk.barcode') ?></span><b class="mono"><?= e($it['barcode'] ?: '—') ?></b></div><div class="kv"><span><?= tt('stk.location') ?></span><b class="mono"><?= e($it['location'] ?: '—') ?></b></div>
      <div class="kv"><span><?= tt('stk.pref_supplier') ?></span><b><?= $it['preferred_supplier'] ? '<a href="' . url('suppliers', ['id' => (int)$it['preferred_supplier_id']]) . '">' . e($it['preferred_supplier']) . '</a>' : '—' ?></b></div><div class="kv"><span><?= tt('stk.min_stock') ?></span><b><?= e(stk_qty((float)$it['min_stock'])) ?></b></div>
      <?php if ($it['notes']): ?><div class="kv" style="grid-column:1/-1;"><span><?= tt('ct.notes') ?></span><b style="font-weight:400;"><?= nl2br(e($it['notes'])) ?></b></div><?php endif; ?>
      <?php if ($it['file_id']): ?><div class="kv" style="grid-column:1/-1;"><span><?= tt('stk.photo') ?></span><a href="<?= url('file', ['id' => (int)$it['file_id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => (int)$it['file_id'], 'thumb' => 1]) ?>" alt="" style="max-height:120px;border-radius:6px;"></a></div><?php endif; ?>
    </div>
  </div>
  <?php if ($isSw): $extra = "licences"; require __DIR__ . "/_inv_extras.php"; else: ?>
  <div class="card">
    <h2>🏬 <?= tt('stk.stock') ?></h2>
    <?php if (!$stock): ?><p class="hint"><?= tt('stk.no_stock') ?></p><?php else: ?><table class="tbl"><thead><tr><th><?= tt('stk.warehouse') ?></th><th><?= tt('stk.location') ?></th><th class="num"><?= tt('stk.on_hand') ?></th><th class="num"><?= tt('stk.reserved') ?></th><th class="num"><?= tt('stk.available') ?></th></tr></thead><tbody>
      <?php foreach ($stock as $s): ?><tr><td><b><?= e($s['wh_code']) ?></b> <span class="hint"><?= e($s['wh_name']) ?></span></td><td class="mono hint"><?= e($s['location'] ?: ($it['location'] ?? '')) ?></td><td class="num"><b><?= e(stk_qty((float)$s['qty_on_hand'])) ?></b></td><td class="num hint"><?= e(stk_qty((float)$s['qty_reserved'])) ?></td><td class="num"><?= e(stk_qty((float)$s['qty_on_hand'] - (float)$s['qty_reserved'])) ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php if ($wh): ?>
    <details class="card card--details" style="margin-top:10px;"><summary><b>↕ <?= tt('stk.move_new') ?></b></summary>
      <form method="post" action="<?= url('inventory_item', ['id' => $id]) ?>" class="inv-move" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="stk_move"><input type="hidden" name="item_id" value="<?= $id ?>">
        <div class="grid grid-3">
          <div class="field"><label><?= tt('stk.move_kind') ?></label><select name="kind" data-nosearch id="mv-kind"><option value="in"><?= tt('stk.mv_in') ?></option><option value="out"><?= tt('stk.mv_out') ?></option><option value="adj"><?= tt('stk.mv_adj') ?></option><?php if (count($whs) > 1): ?><option value="xfer"><?= tt('stk.mv_xfer') ?></option><?php endif; ?></select></div>
          <div class="field"><label><?= tt('stk.warehouse') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label><?= tt('stk.qty') ?> (<?= e($it['unit']) ?>)</label><input name="qty" type="number" step="0.001" required></div>
          <?php if (function_exists('stc_has') && stc_has()): $orgs = function_exists('co_all') ? co_all() : []; ?><div class="field mv-in"><label><?= tt('stk.reason') ?></label><select name="reason_in" data-nosearch id="mv-rin"><?php foreach (STK_IN_REASONS as $r): if ($r === 'receipt') continue; ?><option value="<?= $r ?>"><?= e(t('stk.r_' . $r)) ?></option><?php endforeach; ?></select></div>
          <div class="field mv-out"><label><?= tt('stk.reason') ?></label><select name="reason_out" data-nosearch id="mv-rout"><?php foreach (STK_OUT_REASONS as $r): if ($r === 'issue') continue; ?><option value="<?= $r ?>"><?= e(t('stk.r_' . $r)) ?></option><?php endforeach; ?></select></div>
          <div class="field mv-in mv-out"><label><?= tt('stk.ref_no') ?></label><input name="ref_no" maxlength="40" placeholder="<?= tt('stk.ref_no_ph') ?>"></div>
          <div class="field mv-in mv-out"><label><?= tt('stk.company') ?></label><select name="company_id"><option value="">—</option><?php foreach ($orgs as $oc): ?><option value="<?= (int)$oc['id'] ?>"><?= e($oc['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
          <div class="field mv-in"><label><?= tt('stk.unit_price') ?> (<?= e($cur) ?>)</label><input name="unit_price" type="number" step="0.0001" min="0"></div>
          <div class="field mv-in"><label><?= tt('stk.supplier') ?></label><select name="supplier_id"><option value="">—</option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field mv-in mv-xfer"><label><?= tt('stk.location') ?></label><input name="location" maxlength="60" value="<?= $v('location') ?>"></div>
          <div class="field mv-out"><label><?= tt('stk.to_sub') ?></label><select name="subproject_id"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['project_code'] . ' · ' . ($s['code'] ?: '') . ' ' . $s['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field mv-adj"><label><?= tt('stk.adj_mode') ?></label><select name="adj_mode" data-nosearch><option value="delta"><?= tt('stk.adj_delta') ?></option><option value="count"><?= tt('stk.adj_count') ?></option></select></div>
          <div class="field mv-adj"><label><?= tt('stk.reason') ?></label><select name="reason" data-nosearch><?php foreach (STK_MOVE_REASONS as $r): ?><option value="<?= $r ?>"><?= e(t('stk.r_' . $r)) ?></option><?php endforeach; ?></select></div>
          <div class="field mv-xfer"><label><?= tt('stk.to_wh') ?></label><select name="to_warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="255"></div>
        <button class="btn btn--gold btn--sm" type="submit"><?= tt('stk.move_save') ?></button>
      </form>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <?php if ($it && $moves): ?>
  <div class="card"><h2>🧾 <?= tt('stk.moves') ?> <span class="count"><?= count($moves) ?></span></h2>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('stk.move_kind') ?></th><th><?= tt('stk.warehouse') ?></th><th class="num"><?= tt('stk.qty') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('pd.note') ?></th><th><?= tt('th.by') ?></th></tr></thead><tbody>
      <?php foreach ($moves as $mv): ?><tr><td class="date"><?= e(fmt_datetime($mv['created_at'])) ?></td><td><?= pill((float)$mv['qty'] >= 0 ? 'green' : 'gold', t('stk.k_' . $mv['kind'])) ?><?= $mv['reason'] ? ' <span class="hint">' . e(function_exists('stc_reason_label') ? stc_reason_label($mv['reason']) : $mv['reason']) . '</span>' : '' ?><?= !empty($mv['ref_no']) ? ' <span class="mono hint">' . e($mv['ref_no']) . '</span>' : '' ?><?= !empty($mv['company_id']) ? ' <span class="hint">· ' . e((string)(scalar('SELECT name FROM companies WHERE id = ?', [(int)$mv['company_id']]) ?? '')) . '</span>' : '' ?></td><td class="mono"><?= e($mv['wh_code']) ?></td><td class="num<?= (float)$mv['qty'] < 0 ? ' is-late' : '' ?>"><b><?= (float)$mv['qty'] > 0 ? '+' : '' ?><?= e(stk_qty((float)$mv['qty'])) ?></b></td><td class="hint"><?= e(trim(($mv['project_code'] ?? '') . ' ' . ($mv['sub_code'] ?? ''))) ?></td><td class="hint"><?= e($mv['note'] ?? '') ?></td><td class="hint"><?= e($mv['user_name'] ?? '') ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <?php endif; ?>
</div>

<div>
  <?php if ($it) { $extra = 'links'; require __DIR__ . '/_inv_extras.php'; } /* R88-001: links (licences sit in the left column for software) */ ?>
  <?php if ($it && $priced): ?>
  <div class="card">
    <h2>💶 <?= tt('stk.price_history') ?> <span class="count"><?= count(array_filter($prices, fn($p) => $p['source'] !== 'rollup')) ?></span></h2>
    <p class="hint" style="margin-top:0;"><?= e(t('stk.price_keep', ['n' => STK_PRICE_KEEP])) ?> · <?= tt('stk.rule') ?>: <b><?= e(t('stk.rule_' . ($prop['rule'] ?? 'last'))) ?></b> <span class="hint">(<?= e(t('stk.rule_from_' . ($prop['from'] ?? 'company'))) ?>)</span></p>
    <?php $pts = array_values(array_filter($prices, fn($p) => $p['source'] !== 'rollup')); if (count($pts) >= 2): $pts = array_reverse($pts); $mx = max(array_map(fn($p) => (float)$p['unit_price'], $pts)); $mn = min(array_map(fn($p) => (float)$p['unit_price'], $pts)); $W = 420; $H = 110; $n = count($pts); ?>
    <svg viewBox="0 0 <?= $W ?> <?= $H ?>" width="100%" height="<?= $H ?>" role="img" aria-label="price chart" style="margin-bottom:8px;">
      <polyline fill="none" stroke="#C89B3C" stroke-width="2" points="<?php foreach ($pts as $i => $p) { $x = 10 + $i * ($W - 20) / max(1, $n - 1); $y = $mx > $mn ? 10 + ($mx - (float)$p['unit_price']) / ($mx - $mn) * ($H - 30) : $H / 2; echo round($x, 1), ',', round($y, 1), ' '; } ?>"/>
      <?php foreach ($pts as $i => $p) { $x = 10 + $i * ($W - 20) / max(1, $n - 1); $y = $mx > $mn ? 10 + ($mx - (float)$p['unit_price']) / ($mx - $mn) * ($H - 30) : $H / 2; echo '<circle cx="', round($x, 1), '" cy="', round($y, 1), '" r="3" fill="#0B1E2D"><title>', e(fmt_date($p['priced_at']) . ' · ' . stk_money((float)$p['unit_price'], $cur, 4) . ($p['supplier'] ? ' · ' . $p['supplier'] : '')), '</title></circle>'; } ?>
      <text x="10" y="<?= $H - 6 ?>" font-size="10" fill="#6a7179"><?= e(fmt_date($pts[0]['priced_at'])) ?></text><text x="<?= $W - 10 ?>" y="<?= $H - 6 ?>" font-size="10" fill="#6a7179" text-anchor="end"><?= e(fmt_date(end($pts)['priced_at'])) ?></text>
      <text x="<?= $W - 10 ?>" y="12" font-size="10" fill="#6a7179" text-anchor="end">max <?= e(number_format($mx, 2, ',', '.')) ?> · min <?= e(number_format($mn, 2, ',', '.')) ?></text>
    </svg>
    <?php endif; ?>
    <?php if (!$prices): ?><p class="hint"><?= tt('stk.no_prices') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('stk.supplier') ?></th><th class="num"><?= tt('stk.unit_price') ?></th><th class="num"><?= tt('stk.qty') ?></th><th><?= tt('stk.source') ?></th></tr></thead><tbody>
      <?php foreach ($prices as $p): ?><tr class="<?= $p['source'] === 'rollup' ? 'is-retired' : '' ?>"><td class="date"><?= e(fmt_date($p['priced_at'])) ?></td><td><?= e($p['supplier'] ?? '—') ?></td><td class="num"><b><?= e(stk_money((float)$p['unit_price'], $p['currency'], 4)) ?></b><?= $p['source'] === 'rollup' ? '<br><span class="hint">' . e(t('stk.rollup_s', ['n' => (int)$p['n_rolled'], 'a' => number_format((float)$p['min_price'], 2, ',', '.'), 'b' => number_format((float)$p['max_price'], 2, ',', '.')])) . '</span>' : '' ?></td><td class="num hint"><?= $p['qty'] !== null ? e(stk_qty((float)$p['qty'])) : '' ?></td><td class="hint"><?= e(t('stk.src_' . $p['source'])) ?><?= $p['note'] && $p['source'] !== 'rollup' ? ' · ' . e($p['note']) : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($admin): ?>
    <details style="margin-top:8px;"><summary><b>+ <?= tt('stk.price_add') ?></b></summary>
      <form method="post" action="<?= url('inventory_item', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="stk_price_add"><input type="hidden" name="item_id" value="<?= $id ?>">
        <div class="grid grid-2"><div class="field"><label><?= tt('stk.unit_price') ?> (<?= e($cur) ?>) *</label><input name="unit_price" type="number" step="0.0001" min="0" required></div><div class="field"><label><?= tt('th.date') ?></label><input name="priced_at" type="date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field"><label><?= tt('stk.supplier') ?></label><select name="supplier_id"><option value="">—</option><?php foreach ($sups as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label><?= tt('stk.qty') ?></label><input name="qty" type="number" step="0.001"></div></div>
        <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="160" placeholder="<?= tt('stk.price_note_ph') ?>"></div>
        <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></form>
    </details>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
</div>
<?php if ($it && function_exists('stk_research_has') && stk_research_has() && pr_admin()) { $back = url('inventory_item', ['id' => $id]); require __DIR__ . '/_stk_research.php'; } ?>
<script nonce="<?= csp_nonce() ?>">(function(){var k=document.getElementById('mv-kind');if(!k)return;var f=function(){var v=k.value;document.querySelectorAll('.inv-move .mv-in,.inv-move .mv-out,.inv-move .mv-adj,.inv-move .mv-xfer').forEach(function(el){var on=el.classList.contains('mv-'+v);el.style.display=on?'':'none';});};k.addEventListener('change',f);f();})();</script>
<?php layout_foot(); ?>

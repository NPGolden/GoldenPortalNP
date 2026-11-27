<?php
/** Procurement › Inventory › Stock per warehouse (GAS-CP-R97-282): what every warehouse holds, and the stock of a warehouse — all of it or the ticked lines — sent to another warehouse with a transfer note.
 *   ?p=warehouse_stock[&wh=ID&q=&cat=&show=instock|reserved|negative|all&pg=N][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
$whs = function_exists('stk_warehouses') ? stk_warehouses(false) : []; $byId = []; foreach ($whs as $w0) $byId[(int)$w0['id']] = $w0;
$full = function_exists('pr_can') && pr_can(); $mine = array_values(array_filter($whs, fn($w0) => function_exists('stk_wh_can_view') && stk_wh_can_view($w0)));
if (!function_exists('stk_wh_stock') || !$mine) { http_response_code(403); layout_head(t('whs.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$whId = (int)($_GET['wh'] ?? 0); if (!$whId && !$full) $whId = (int)$mine[0]['id']; $W = $whId ? ($byId[$whId] ?? null) : null;
if ($whId && (!$W || !stk_wh_can_view($W))) { http_response_code(404); layout_head(t('err.notfound'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$f = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60), 'cat' => (int)($_GET['cat'] ?? 0), 'show' => in_array($_GET['show'] ?? '', ['instock', 'reserved', 'negative', 'all'], true) ? (string)$_GET['show'] : 'instock'];
$per = 200; $pg = max(1, (int)($_GET['pg'] ?? 1)); $price = $full && stk_price_visible(); $canX = $full && pr_wh() && dn_x_has(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
if ($W && ($_GET['format'] ?? '') === 'xlsx' && function_exists('xlsx_download')) {
    [$all] = stk_wh_stock($whId, $f, 5000, 0); $hdr = [t('stk.code'), t('prq.desc'), t('stk.maker'), t('stk.category'), t('stk.location'), t('whs.c_onhand'), t('whs.c_reserved'), t('whs.c_free'), t('stk.unit')]; if ($price) { $hdr[] = t('whs.c_price'); $hdr[] = t('whs.c_value'); }
    $x = []; foreach ($all as $r) { $row = [(string)$r['code'], (string)$r['description'], trim((string)$r['maker'] . ' ' . (string)$r['factory_code']), (string)($r['category'] ?? ''), (string)($r['location'] ?? ''), (float)$r['qty_on_hand'], (float)$r['qty_reserved'], (float)$r['qty_on_hand'] - (float)$r['qty_reserved'], (string)$r['unit']]; if ($price) { $row[] = $r['last_price'] !== null ? (float)$r['last_price'] : ''; $row[] = $r['last_price'] !== null ? round((float)$r['qty_on_hand'] * (float)$r['last_price'], 2) : ''; } $x[] = $row; }
    xlsx_download('GAS-stock-' . preg_replace('/[^A-Za-z0-9-]/', '_', (string)$W['code']) . '-' . date('Ymd') . '.xlsx', [['name' => mb_substr((string)$W['code'], 0, 28), 'first' => true, 'header' => $hdr, 'rows' => $x]]); exit;
}
$sum = $full ? stk_wh_summary() : []; [$rows, $total] = $W ? stk_wh_stock($whId, $f, $per, ($pg - 1) * $per) : [[], 0]; $pages = (int) ceil($total / $per);
$inStock = $W ? (int) scalar('SELECT COUNT(*) FROM inv_stock WHERE warehouse_id = ? AND qty_on_hand > 0.0005', [$whId]) : 0;
$cats = $W && function_exists('stk_categories') ? stk_categories() : []; $link = fn(array $x = []) => url('warehouse_stock', array_filter(array_merge(['wh' => $whId, 'q' => $f['q'], 'cat' => $f['cat'], 'show' => $f['show'] === 'instock' ? '' : $f['show']], $x)));
layout_head(t('whs.title'), 'inventory');
?>
<p class="eyebrow"><?php if ($full): ?><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <?php endif; ?><?= $W && $full ? '<a href="' . url('warehouse_stock') . '">' . tt('whs.title') . '</a> › ' . e((string)$W['code']) : tt('whs.title') ?></p>
<div class="page-head">
  <div><h1>🏬 <?= $W ? e(stk_wh_name($W)) : tt('whs.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= $W ? e(implode(' · ', array_filter([(string)($W['holder_name'] ?? ''), (string)($W['vehicle'] ?? ''), !empty($W['location']) ? '📍 ' . $W['location'] : '']))) ?: tt('whs.lede') : tt('whs.lede') ?></p></div>
  <div class="btn-row"><?php if ($W && function_exists('xlsx_download')): ?><a class="btn btn--ghost btn--sm" href="<?= e($link(['format' => 'xlsx'])) ?>">⤓ Excel</a><?php endif; ?><?php if ($full): ?><a class="btn btn--ghost btn--sm" href="<?= url('delivery_notes') ?>">🚚 <?= tt('dn.title') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('inventory') ?>">📦 <?= tt('stk.title') ?></a><?php endif; ?></div>
</div>
<?php if ($full): ?>
<div class="card">
  <h2><?= tt('stk.warehouses') ?> <span class="count"><?= count($whs) ?></span></h2>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('stk.code') ?></th><th><?= tt('stk.warehouse') ?></th><th><?= tt('stk.wh_holder') ?></th><th class="num"><?= tt('whs.c_items') ?></th><th class="num"><?= tt('whs.c_rsv_lines') ?></th><?php if ($price): ?><th class="num"><?= tt('whs.c_value') ?></th><?php endif; ?><th></th></tr></thead><tbody>
    <?php foreach ($whs as $w0): $s0 = $sum[(int)$w0['id']] ?? []; ?><tr class="<?= $w0['is_active'] ? '' : 'is-retired' ?>"<?= (int)$w0['id'] === $whId ? ' style="background:rgba(201,162,39,.10);"' : '' ?>>
      <td class="mono"><a href="<?= url('warehouse_stock', ['wh' => (int)$w0['id']]) ?>"><b><?= e((string)$w0['code']) ?></b></a></td>
      <td><?= e((($w0['kind'] ?? '') === 'mobile' ? '🚐 ' : '') . $w0['name']) ?><?= $w0['is_default'] ? ' ' . pill('gold', t('stk.default')) : '' ?><?= !empty($w0['location']) ? '<br><small class="hint">📍 ' . e((string)$w0['location']) . '</small>' : '' ?></td>
      <td class="hint"><?= e(implode(' · ', array_filter([(string)($w0['holder_name'] ?? ''), (string)($w0['vehicle'] ?? '')]))) ?></td>
      <td class="num"><b><?= (int)($s0['n_items'] ?? 0) ?></b><?= (int)($s0['n_negative'] ?? 0) ? ' ' . pill('bad', '− ' . (int)$s0['n_negative']) : '' ?></td><td class="num"><?= (int)($s0['n_reserved'] ?? 0) ?: '—' ?></td>
      <?php if ($price): ?><td class="num"><?= e(stk_money((float)($s0['value'] ?? 0))) ?></td><?php endif; ?>
      <td><a class="btn btn--ghost btn--sm" href="<?= url('warehouse_stock', ['wh' => (int)$w0['id']]) ?>"><?= tt('whs.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>
<?php if ($W): ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr auto;"><input type="hidden" name="p" value="warehouse_stock"><input type="hidden" name="wh" value="<?= $whId ?>">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="<?= tt('whs.search_ph') ?>"></div>
  <div class="field"><label><?= tt('stk.category') ?></label><select name="cat"><option value=""><?= tt('flt.any') ?></option><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($c['id'], $f['cat']) ?>><?= e((string)$c['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('whs.show') ?></label><select name="show" data-nosearch><?php foreach (['instock', 'reserved', 'negative', 'all'] as $sv): ?><option value="<?= $sv ?>"<?= $sel($sv, $f['show']) ?>><?= e(t('whs.show_' . $sv)) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('warehouse_stock', ['wh' => $whId]) ?>"><?= tt('btn.clear') ?></a></div>
</form>
<form method="post" action="<?= e($link(['pg' => $pg > 1 ? $pg : 0])) ?>" data-lock id="whs-form"><?= csrf_field() ?><input type="hidden" name="action" value="dn_xfer_stock"><input type="hidden" name="from_wh" value="<?= $whId ?>">
<div class="card">
  <div class="card-head"><h2><?= tt('whs.stock') ?> <span class="count"><?= $total ?></span></h2><?php if ($canX && $rows): ?><span class="btn-row"><button class="btn btn--ghost btn--sm" type="button" id="whs-all">☑ <?= tt('whs.tick_all') ?></button><button class="btn btn--ghost btn--sm" type="button" id="whs-none">☐ <?= tt('dn.fw_none') ?></button></span><?php endif; ?></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('whs.empty') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap" id="whs-lines"><thead><tr><?php if ($canX): ?><th></th><?php endif; ?><th><?= tt('stk.code') ?></th><th><?= tt('prq.desc') ?></th><th><?= tt('stk.category') ?></th><th><?= tt('stk.location') ?></th><th class="num"><?= tt('whs.c_onhand') ?></th><th class="num"><?= tt('whs.c_reserved') ?></th><th class="num"><?= tt('whs.c_free') ?></th><?php if ($price): ?><th class="num"><?= tt('whs.c_value') ?></th><?php endif; ?><?php if ($canX): ?><th style="width:8em;"><?= tt('whs.c_send') ?></th><?php endif; ?></tr></thead><tbody>
    <?php foreach ($rows as $r): $iid = (int)$r['item_id']; $oh = (float)$r['qty_on_hand']; $rs = (float)$r['qty_reserved']; ?><tr>
      <?php if ($canX): ?><td><?php if ($oh > 0.0005): ?><input type="checkbox" class="whs-pick" name="pick[<?= $iid ?>]" value="1" aria-label="<?= e((string)$r['code']) ?>"><?php endif; ?></td><?php endif; ?>
      <td class="mono"><?= $full ? '<a href="' . url('inventory_item', ['id' => $iid]) . '">' . e((string)$r['code']) . '</a>' : e((string)$r['code']) ?></td>
      <td><?= e((string)$r['description']) ?><?= $r['maker'] || $r['factory_code'] ? '<br><small class="hint">' . e(trim((string)$r['maker'] . ' ' . (string)$r['factory_code'])) . '</small>' : '' ?></td>
      <td class="hint"><?= e((string)($r['category'] ?? '')) ?></td><td class="hint"><?= e((string)($r['location'] ?? '')) ?></td>
      <td class="num"><b<?= $oh < -0.0005 ? ' style="color:#B3261E;"' : '' ?>><?= e(stk_qty($oh)) ?></b> <?= e((string)$r['unit']) ?></td><td class="num"><?= $rs > 0.0005 ? '🔒 ' . e(stk_qty($rs)) : '—' ?></td><td class="num"><?= e(stk_qty($oh - $rs)) ?></td>
      <?php if ($price): ?><td class="num"><?= $r['last_price'] !== null ? e(stk_money(round($oh * (float)$r['last_price'], 2), (string)($r['currency'] ?: 'EUR'))) : '—' ?></td><?php endif; ?>
      <?php if ($canX): ?><td><?php if ($oh > 0.0005): ?><input type="number" class="whs-qty" name="xq[<?= $iid ?>]" step="0.001" min="0.001" max="<?= e((string)$oh) ?>" value="<?= e((string)$oh) ?>" disabled style="width:100%;"><?php endif; ?></td><?php endif; ?>
    </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><p class="btn-row" style="margin-top:8px;"><?php for ($i = 1; $i <= min($pages, 40); $i++): ?><a class="btn btn--sm <?= $i === $pg ? 'btn--gold' : 'btn--ghost' ?>" href="<?= e($link(['pg' => $i])) ?>"><?= $i ?></a><?php endfor; ?><span class="hint"><?= e(t('whs.page_hint', ['n' => $per])) ?></span></p><?php endif; ?>
  <?php endif; ?>
</div>
<?php if ($canX && $inStock): $dest = array_values(array_filter($whs, fn($w0) => $w0['is_active'] && (int)$w0['id'] !== $whId)); ?>
<div class="card" id="whs-send">
  <div class="card-head"><h2>🔁 <?= tt('whs.send_title') ?></h2><span class="hint"><?= tt('whs.send_hint') ?></span></div>
  <?php if (!$dest): ?><p class="hint"><?= tt('whs.no_dest') ?></p><?php else: ?>
  <div class="form-grid">
    <div class="field"><label><?= tt('stk.to_wh') ?> *</label><select name="to_warehouse_id" required data-nosearch><option value="">—</option><?php foreach ($dest as $w0): ?><option value="<?= (int)$w0['id'] ?>"><?= e(stk_wh_name($w0)) ?><?= !empty($w0['holder_name']) ? ' · ' . e((string)$w0['holder_name']) : '' ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('dn.issued_at') ?></label><input name="issued_at" type="date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label><?= tt('prq.project') ?></label><select name="project_id"><option value="">—</option><?php foreach (all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') ORDER BY project_code DESC LIMIT 400") as $p): ?><option value="<?= (int)$p['id'] ?>"><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
    <?php if (dn_x2_has()): ?><div class="field"><label><?= tt('dn.to_user') ?></label><select name="to_user_id"><option value="">—</option><?php foreach (staff_users() as $su): ?><option value="<?= (int)$su['id'] ?>"><?= e((string)$su['full_name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label><?= tt('po.carrier') ?></label><input name="carrier" maxlength="120"></div>
    <div class="field"><label><?= tt('po.ref') ?></label><input name="ref_no" maxlength="80"></div>
    <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2" maxlength="600"></textarea></div>
  </div>
  <div class="btn-row" style="margin-top:12px;">
    <button class="btn btn--gold" type="submit" name="mode" value="picked" id="whs-go">🔁 <?= tt('whs.send_picked') ?> <span id="whs-n"></span></button>
    <button class="btn btn--ghost" type="submit" name="mode" value="all" data-confirm="<?= e(t('whs.send_all_q', ['n' => $inStock, 'w' => (string)$W['code']])) ?>">🏬 <?= e(t('whs.send_all', ['n' => $inStock])) ?></button>
  </div>
  <p class="hint" style="margin-top:8px;"><?= e(t('whs.send_rules', ['n' => DN_XFER_NOTE, 'm' => DN_XFER_MAX])) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>
</form>
<?php if ($canX && $rows): ?><script nonce="<?= csp_nonce() ?>">
(function(){
  var rows=[].slice.call(document.querySelectorAll('#whs-lines tbody tr')).filter(function(tr){return tr.querySelector('.whs-pick');}),n=document.getElementById('whs-n'),go=document.getElementById('whs-go');
  function upd(){var c=0;rows.forEach(function(tr){var p=tr.querySelector('.whs-pick'),q=tr.querySelector('.whs-qty');q.disabled=!p.checked;if(p.checked)c++;});if(n)n.textContent='('+c+')';if(go)go.disabled=!c;}
  rows.forEach(function(tr){tr.querySelector('.whs-pick').addEventListener('change',upd);});
  var a=document.getElementById('whs-all'),z=document.getElementById('whs-none');
  if(a)a.addEventListener('click',function(){rows.forEach(function(tr){tr.querySelector('.whs-pick').checked=true;var q=tr.querySelector('.whs-qty');q.value=q.getAttribute('max');});upd();});
  if(z)z.addEventListener('click',function(){rows.forEach(function(tr){tr.querySelector('.whs-pick').checked=false;});upd();});
  upd();
})();
</script><?php endif; ?>
<?php endif; ?>
<?php layout_foot(); ?>

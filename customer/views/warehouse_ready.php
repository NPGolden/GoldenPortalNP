<?php
/** Procurement › Inventory › Received per project and vessel — what the warehouse holds for each project (bought for it, or reserved from stock),
 *  what was already sent and what is still to come; the storekeeper ticks lines and quantities and issues one or several delivery notes (GAS-CP-R97-109).
 *   ?p=warehouse_ready[&vessel_id=&project_id=&subproject_id=&show=ready|open|all] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('dn_can') || !dn_can() || !function_exists('whr_rows')) { http_response_code(403); layout_head(t('whr.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$wh = dn_wh(); $f = ['vessel_id' => (int)($_GET['vessel_id'] ?? 0), 'project_id' => (int)($_GET['project_id'] ?? 0), 'subproject_id' => (int)($_GET['subproject_id'] ?? 0), 'show' => in_array($_GET['show'] ?? '', ['ready', 'open', 'all'], true) ? $_GET['show'] : 'ready'];
$rows = whr_rows($f); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $q3 = fn(float $v) => $v > 0.0005 ? stk_qty($v) : '—';
$projects = all("SELECT DISTINCT p.id, p.project_code, p.name FROM projects p WHERE p.id IN (SELECT project_id FROM pr_requests WHERE project_id IS NOT NULL AND status IN ('approved','closed') UNION SELECT project_id FROM po_orders WHERE project_id IS NOT NULL AND status NOT IN ('draft','cancelled')) ORDER BY p.project_code DESC LIMIT 600");
$vessels = all("SELECT DISTINCT v.id, v.name FROM vessels v JOIN projects p ON p.vessel_id = v.id WHERE p.id IN (SELECT project_id FROM pr_requests WHERE project_id IS NOT NULL AND status IN ('approved','closed') UNION SELECT project_id FROM po_orders WHERE project_id IS NOT NULL AND status NOT IN ('draft','cancelled')) ORDER BY v.name LIMIT 600");
$nReady = count(array_filter($rows, fn($x) => $x['ready'] > 0.0005)); $nProj = count(array_unique(array_column($rows, 'project_id')));
$keep = array_filter(['vessel_id' => $f['vessel_id'], 'project_id' => $f['project_id'], 'subproject_id' => $f['subproject_id']]);
layout_head(t('whr.title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('delivery_notes') ?>"><?= tt('dn.title') ?></a> › <?= tt('whr.title') ?></p>
<div class="page-head">
  <div><h1>📦 <?= tt('whr.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('whr.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('delivery_notes', array_filter(['project_id' => $f['project_id']])) ?>">🚚 <?= tt('dn.title') ?></a></div>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.2fr 1.6fr 1fr auto;">
  <input type="hidden" name="p" value="warehouse_ready"><?php if ($f['subproject_id']): ?><input type="hidden" name="subproject_id" value="<?= $f['subproject_id'] ?>"><?php endif; ?>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel_id"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $sel($v['id'], $f['vessel_id']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('prq.project') ?></label><select name="project_id"><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $f['project_id']) ?>><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('whr.show') ?></label><select name="show" data-nosearch><?php foreach (['ready', 'open', 'all'] as $k): ?><option value="<?= $k ?>"<?= $sel($k, $f['show']) ?>><?= tt('whr.show_' . $k) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
</form>
<div class="kpi kpi--3" style="margin-bottom:10px;"><div class="stat green"><b><?= $nReady ?></b><?= tt('whr.k_ready') ?></div><div class="stat"><b><?= count($rows) ?></b><?= tt('whr.k_lines') ?></div><div class="stat"><b><?= $nProj ?></b><?= tt('whr.k_projects') ?></div></div>
<?php if (!$rows): ?><div class="card"><p class="hint" style="margin:0;"><?= tt('whr.none') ?></p></div><?php else: ?>
<form method="post" action="<?= url('warehouse_ready', $keep) ?>" data-lock data-pack id="whr-form"><?= csrf_field() ?><input type="hidden" name="action" value="whr_create">
<div class="card">
  <div class="table-scroll"><table class="tbl tbl--compact" data-nosort><thead><tr><?php if ($wh): ?><th></th><?php endif; ?><th><?= tt('whr.c_doc') ?></th><th><?= tt('th.code') ?></th><th style="min-width:240px;"><?= tt('prq.desc') ?></th><th class="num"><?= tt('whr.c_qty') ?></th><th class="num"><?= tt('whr.c_ready') ?></th><th class="num"><?= tt('whr.c_sent') ?></th><th class="num"><?= tt('whr.c_come') ?></th><?php if ($wh): ?><th class="num"><?= tt('whr.c_send') ?></th><?php endif; ?></tr></thead><tbody>
  <?php $grp = null; foreach ($rows as $x): $g = (int)$x['project_id'] . '|' . (int)$x['subproject_id'];
    if ($g !== $grp): $grp = $g; ?><tr class="whr-head"><td colspan="<?= $wh ? 9 : 7 ?>" style="background:var(--navy-1, #eef2f7);"><?php if ($wh): ?><label class="check" style="display:inline;margin-right:10px;"><input type="checkbox" class="whr-all" data-g="<?= e($g) ?>" aria-label="<?= tt('whr.tick_all') ?>"></label><?php endif; ?><b><?= $x['vessel'] ? '🚢 ' . e((string)$x['vessel']) . ' · ' : '' ?><?= e(trim((string)$x['project_code'] . ' ' . (string)$x['project_name'])) ?></b><?php if ($x['subproject_id']): ?> › <a href="<?= url('subproject', ['id' => (int)$x['subproject_id'], 'tab' => 'procurement']) ?>"><?= e(trim((string)$x['sub_code'] . ' · ' . (string)$x['sub_name'])) ?></a><?php endif; ?></td></tr><?php endif; ?>
  <tr data-g="<?= e($g) ?>">
    <?php if ($wh): ?><td><?php if ($x['ready'] > 0.0005): ?><input type="checkbox" class="whr-pick" name="pick[<?= e($x['key']) ?>]" value="1" aria-label="<?= e((string)$x['description']) ?>"><?php endif; ?></td><?php endif; ?>
    <td><a class="mono" href="<?= url($x['src'] === 'pr' ? 'requisition' : 'purchase_order', ['id' => (int)$x['doc_id']]) ?>"><?= e((string)$x['doc_no']) ?></a><br><?= pill($x['origin'] === 'stock' ? 'navy' : 'mute', t('whr.o_' . $x['origin'])) ?></td>
    <td class="mono"><?= $x['item_id'] ? '<a href="' . url('inventory_item', ['id' => (int)$x['item_id']]) . '">' . e((string)$x['item_code']) . '</a>' : '<span class="hint">' . tt('prq.free_text') . '</span>' ?></td>
    <td><?= e((string)$x['description']) ?></td>
    <td class="num"><?= e(stk_qty($x['qty'])) ?> <span class="hint"><?= e((string)$x['unit']) ?></span></td>
    <td class="num"><?= $x['ready'] > 0.0005 ? '<b style="color:var(--green,#2E7D32);">' . e(stk_qty($x['ready'])) . '</b>' : '—' ?></td>
    <td class="num"><?= e($q3($x['sent'])) ?></td>
    <td class="num"><?= $x['come'] > 0.0005 ? '<span style="color:var(--gold-d,#8a6d00);">' . e(stk_qty($x['come'])) . '</span>' : '—' ?></td>
    <?php if ($wh): ?><td class="num"><?php if ($x['ready'] > 0.0005): ?><input name="qty[<?= e($x['key']) ?>]" inputmode="decimal" value="<?= e(stk_qty($x['ready'])) ?>" style="width:74px;text-align:right;" aria-label="<?= tt('whr.c_send') ?>"><?php endif; ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php if ($wh && $nReady): ?>
<div class="card">
  <h2>🚚 <?= tt('whr.dn_title') ?></h2>
  <div class="form-grid">
    <div class="field"><label><?= tt('whr.split') ?></label><select name="split" data-nosearch><option value="sub"><?= tt('whr.split_sub') ?></option><option value="project"><?= tt('whr.split_project') ?></option></select></div>
    <div class="field"><label><?= tt('dn.issued_at') ?></label><input name="issued_at" type="date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label><?= tt('dn.attention') ?></label><input name="attention" maxlength="120"></div>
    <div class="field field--full"><label><?= tt('dn.address') ?></label><input name="address" maxlength="240" placeholder="<?= tt('dn.address_ph') ?>"></div>
    <div class="field"><label><?= tt('po.carrier') ?></label><input name="carrier" maxlength="120"></div>
    <div class="field"><label><?= tt('po.ref') ?></label><input name="ref_no" maxlength="80"></div>
    <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2" maxlength="600"></textarea></div>
  </div>
  <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit"><?= tt('whr.dn_btn') ?></button><span class="hint" id="whr-count"><?= tt('whr.dn_hint') ?></span></div>
</div>
<?php endif; ?>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){ var f=document.getElementById('whr-form'); if(!f) return; f.querySelectorAll('.whr-all').forEach(function(a){ a.addEventListener('change',function(){ f.querySelectorAll('tr[data-g="'+a.dataset.g+'"] .whr-pick').forEach(function(c){ c.checked=a.checked; }); }); }); })();</script>
<?php endif; ?>
<?php layout_foot();

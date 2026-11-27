<?php
/** Procurement › Inventory › Delivery notes › Assemble a delivery note (GAS-CP-R97-281).
 *  The goods that transfer notes brought to a warehouse — a whole note, some lines or part of a quantity — go on to a project or a customer with one new note.
 *   ?p=dn_forward&from=ID[,ID…] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('dn_wh') || !dn_wh() || !dn_x3_has()) { http_response_code(403); layout_head(t('dn.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$from = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['from'] ?? ''))))));
$from = array_slice($from, 0, 12); $src = []; foreach ($from as $fid) { $d0 = dn_load($fid); if ($d0 && $d0['kind'] === 'transfer' && $d0['status'] === 'issued' && (!$src || (int)$d0['to_warehouse_id'] === (int)$src[0]['to_warehouse_id'])) $src[] = $d0; }
if (!$src) { http_response_code(404); layout_head(t('err.notfound'), 'inventory'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$ids = array_map(fn($d0) => (int)$d0['id'], $src); $whId = (int)$src[0]['to_warehouse_id']; $L = dn_fwd_lines($ids); $open = array_values(array_filter($L, fn($l) => $l['left'] > 0.0005)); $more = dn_fwd_sources($whId, $ids);
$preP = (int)$src[0]['project_id']; $preS = (int)$src[0]['subproject_id']; $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $self = url('dn_forward', ['from' => implode(',', $ids)]);
$projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') OR id = ? ORDER BY project_code DESC LIMIT 400", [$preP]);
$subs = all("SELECT sp.id, sp.project_id, sp.code, sp.name FROM subprojects sp WHERE sp.status IN ('active','quoted','onhold') OR sp.id = ? ORDER BY sp.sort_order, sp.code LIMIT 1500", [$preS]);
$orgs = function_exists('co_all') ? co_all() : []; $vessels = all('SELECT id, name FROM vessels WHERE is_active = 1 AND merged_into IS NULL ORDER BY name LIMIT 600');
layout_head(t('dn.fw_title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <a href="<?= url('delivery_notes') ?>"><?= tt('dn.title') ?></a> › <?= tt('dn.fw_title') ?></p>
<div class="page-head">
  <div><h1>🧩 <?= tt('dn.fw_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('dn.fw_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('delivery_note', ['id' => $ids[0]]) ?>">‹ <?= e((string)$src[0]['dn_no']) ?></a></div>
</div>
<div class="card">
  <div class="card-head"><h2><?= tt('dn.fw_sources') ?></h2><span class="hint">🏬 <?= e(dn_wh_label((string)$src[0]['to_wh_code'], (string)$src[0]['to_warehouse'], (string)($src[0]['to_wh_kind'] ?? ''))) ?></span></div>
  <p><?php foreach ($src as $d0): ?><a class="mono" href="<?= url('delivery_note', ['id' => (int)$d0['id']]) ?>"><b><?= e((string)$d0['dn_no']) ?></b></a> <span class="hint" style="display:inline;"><?= e(fmt_date((string)$d0['issued_at'])) ?><?= $d0['project_code'] ? ' · ' . e((string)$d0['project_code']) : '' ?></span><?php if (count($src) > 1): ?> <a class="hint" style="display:inline;" href="<?= url('dn_forward', ['from' => implode(',', array_diff($ids, [(int)$d0['id']]))]) ?>" title="<?= tt('dn.fw_remove') ?>">✕</a><?php endif; ?> &nbsp; <?php endforeach; ?></p>
  <?php if ($more && count($src) < 12): ?><form method="get" action="index.php" class="fltbar" style="grid-template-columns: 2fr auto;"><input type="hidden" name="p" value="dn_forward">
    <div class="field"><label for="fw_add"><?= tt('dn.fw_add') ?></label><select id="fw_add" name="from"><?php foreach ($more as $m): ?><option value="<?= e(implode(',', array_merge($ids, [(int)$m['id']]))) ?>"><?= e((string)$m['dn_no'] . ' · ' . fmt_date((string)$m['issued_at']) . ($m['project_code'] ? ' · ' . $m['project_code'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--ghost btn--sm" type="submit">+ <?= tt('dn.fw_add_btn') ?></button></div></form><?php endif; ?>
</div>
<?php if (!$open): ?><div class="card"><p class="hint"><?= tt('dn.fw_nothing') ?></p></div><?php else: ?>
<form method="post" action="<?= e($self) ?>" data-lock id="fw-form"><?= csrf_field() ?><input type="hidden" name="action" value="dn_forward">
<div class="card">
  <div class="card-head"><h2><?= tt('prq.lines') ?> <span class="count"><?= count($open) ?></span></h2><span class="btn-row"><button class="btn btn--ghost btn--sm" type="button" id="fw-all">☑ <?= tt('dn.fw_all') ?></button><button class="btn btn--ghost btn--sm" type="button" id="fw-none">☐ <?= tt('dn.fw_none') ?></button></span></div>
  <div class="table-scroll"><table class="tbl tbl--wrap" id="fw-lines"><thead><tr><th></th><th><?= tt('dn.fw_c_src') ?></th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('dn.fw_c_moved') ?></th><th class="num"><?= tt('dn.fw_c_sent') ?></th><th class="num"><?= tt('dn.fw_c_left') ?></th><th style="width:9em;"><?= tt('dn.fw_c_qty') ?></th></tr></thead><tbody>
    <?php foreach ($open as $l): $lid = (int)$l['id']; ?><tr>
      <td><input type="checkbox" name="pick[<?= $lid ?>]" value="1" class="fw-pick" checked aria-label="<?= e((string)$l['description']) ?>"></td>
      <td class="mono"><?= e((string)$l['dn_no']) ?> <span class="hint" style="display:inline;">#<?= (int)$l['seq'] ?></span></td>
      <td><?= $l['item_id'] ? '<a class="mono" href="' . url('inventory_item', ['id' => (int)$l['item_id']]) . '">' . e((string)$l['item_code']) . '</a>' : '<span class="hint">' . tt('prq.free_text') . '</span>' ?></td>
      <td><?= e((string)$l['description']) ?><?= (float)$l['travelled_rsv'] > 0.0005 ? ' ' . pill('navy', '🔒 ' . t('dn.fw_rsv', ['q' => stk_qty((float)$l['travelled_rsv'])])) : '' ?></td>
      <td class="num"><?= e(stk_qty($l['qty'])) ?> <?= e((string)$l['unit']) ?></td><td class="num"><?= $l['sent'] > 0.0005 ? e(stk_qty($l['sent'])) : '—' ?></td><td class="num"><b><?= e(stk_qty($l['left'])) ?></b></td>
      <td><input type="number" name="fq[<?= $lid ?>]" class="fw-qty" step="0.001" min="0.001" max="<?= e((string)$l['left']) ?>" value="<?= e((string)$l['left']) ?>" data-left="<?= e((string)$l['left']) ?>" style="width:100%;"></td>
    </tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin-top:8px;"><?= tt('dn.fw_hint') ?></p>
</div>
<div class="card">
  <h2><?= tt('dn.fw_to') ?></h2>
  <div class="form-grid">
    <div class="field"><label><?= tt('dn.kind') ?></label><select name="kind" data-nosearch><?php foreach (['project', 'customer', 'production'] as $k): ?><option value="<?= $k ?>"><?= dn_kind_icon($k) ?> <?= e(t('dn.k_' . $k)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('dn.issued_at') ?></label><input name="issued_at" type="date" value="<?= date('Y-m-d') ?>" required></div>
    <div class="field"><label><?= tt('prq.project') ?></label><select name="project_id" id="fw-p"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $preP) ?>><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('prq.subproject') ?></label><select name="subproject_id" id="fw-s"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $sel($s['id'], $preS) ?>><?= e(($s['code'] ?: '') . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('dn.company') ?></label><select name="company_id"><option value="">—</option><?php foreach ($orgs as $oc): ?><option value="<?= (int)$oc['id'] ?>"><?= e($oc['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel_id"><option value="">—</option><?php foreach ($vessels as $vv): ?><option value="<?= (int)$vv['id'] ?>"<?= $sel($vv['id'], (int)($src[0]['vessel_id'] ?? 0)) ?>><?= e($vv['name']) ?></option><?php endforeach; ?></select></div>
    <?php if (dn_x2_has()): ?><div class="field"><label><?= tt('dn.to_user') ?></label><select name="to_user_id"><option value="">—</option><?php foreach (staff_users() as $su): ?><option value="<?= (int)$su['id'] ?>"><?= e((string)$su['full_name']) ?></option><?php endforeach; ?></select><span class="hint"><?= tt('dn.to_user_hint') ?></span></div><?php endif; ?>
    <div class="field"><label><?= tt('dn.attention') ?></label><input name="attention" maxlength="120"></div>
    <div class="field field--full"><label><?= tt('dn.address') ?></label><input name="address" maxlength="240" placeholder="<?= tt('dn.address_ph') ?>"></div>
    <div class="field"><label><?= tt('po.carrier') ?></label><input name="carrier" maxlength="120"></div>
    <div class="field"><label><?= tt('po.ref') ?></label><input name="ref_no" maxlength="80" placeholder="<?= tt('dn.ref_ph') ?>"></div>
    <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2" maxlength="600"></textarea></div>
  </div>
  <div class="btn-row" style="margin-top:12px;"><button class="btn btn--gold" type="submit">🚚 <?= tt('dn.issue') ?></button><a class="btn btn--ghost" href="<?= url('delivery_note', ['id' => $ids[0]]) ?>"><?= tt('btn.cancel') ?></a><span class="hint" id="fw-sum"></span></div>
</div>
</form>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var rows=[].slice.call(document.querySelectorAll('#fw-lines tbody tr')),sum=document.getElementById('fw-sum'),LBL=<?= json_encode(t('dn.fw_sum'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  function upd(){var n=0;rows.forEach(function(tr){var c=tr.querySelector('.fw-pick'),q=tr.querySelector('.fw-qty');q.disabled=!c.checked;tr.style.opacity=c.checked?'':'.5';if(c.checked&&parseFloat(q.value)>0)n++;});sum.textContent=LBL.replace('{n}',n);}
  rows.forEach(function(tr){tr.querySelector('.fw-pick').addEventListener('change',upd);tr.querySelector('.fw-qty').addEventListener('input',upd);});
  document.getElementById('fw-all').addEventListener('click',function(){rows.forEach(function(tr){tr.querySelector('.fw-pick').checked=true;var q=tr.querySelector('.fw-qty');q.value=q.getAttribute('data-left');});upd();});
  document.getElementById('fw-none').addEventListener('click',function(){rows.forEach(function(tr){tr.querySelector('.fw-pick').checked=false;});upd();});
  var p=document.getElementById('fw-p'),s=document.getElementById('fw-s');function f(){var v=p.value;[].forEach.call(s.options,function(o){if(!o.value)return;var on=!v||o.getAttribute('data-p')===v;o.hidden=!on;o.disabled=!on;});if(s.selectedOptions[0]&&s.selectedOptions[0].disabled)s.value='';}p.addEventListener('change',f);f();upd();
})();
</script>
<?php endif; ?>
<?php layout_foot(); ?>

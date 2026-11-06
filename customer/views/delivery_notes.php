<?php
/** Procurement › Inventory › Delivery notes — register and a new note (goods out of the warehouse to a project, a customer, production, or back to a supplier) (GAS-CP-R88-001).
 *   ?p=delivery_notes[&kind=&status=&q=&project_id=&company_id=][&new=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('dn_can') || !dn_can()) { http_response_code(403); layout_head(t('dn.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$wh = dn_wh(); $f = ['kind' => (string)($_GET['kind'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')), 'project_id' => (int)($_GET['project_id'] ?? 0), 'company_id' => (int)($_GET['company_id'] ?? 0)];
$rows = dn_list($f); $new = $wh && !empty($_GET['new']); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$whs = stk_warehouses(); $projects = $new ? all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') ORDER BY project_code DESC LIMIT 400") : [];
$subs = $new ? all("SELECT sp.id, sp.project_id, sp.code, sp.name FROM subprojects sp WHERE sp.status IN ('active','quoted','onhold') ORDER BY sp.sort_order, sp.code LIMIT 1500") : [];
$orgs = $new && function_exists('co_all') ? co_all() : []; $vessels = $new ? all('SELECT id, name FROM vessels WHERE is_active = 1 AND merged_into IS NULL ORDER BY name LIMIT 600') : [];
$preP = (int)($_GET['project_id'] ?? 0); $preS = (int)($_GET['subproject_id'] ?? 0); $preI = (int)($_GET['item_id'] ?? 0); $preItem = $preI ? stk_item($preI) : null;
layout_head(t('dn.title'), 'inventory');
?>
<style>.prq-pick{position:relative}.prq-list{position:absolute;left:0;right:0;top:100%;z-index:20;background:var(--card,#fff);border:1px solid var(--line,#d9dee5);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow:auto}.prq-opt{padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--line,#eef1f5);font-size:.92em}.prq-opt:hover{background:var(--navy-soft,#eef2f8)}.prq-opt small{color:var(--muted,#6b7280);display:block}table.dn-lines input,table.dn-lines select{width:100%}</style>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <?= tt('dn.title') ?></p>
<div class="page-head">
  <div><h1>🚚 <?= tt('dn.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('dn.lede') ?></p></div>
  <div class="btn-row"><?php if ($wh && !$new): ?><a class="btn btn--gold btn--sm" href="<?= url('delivery_notes', ['new' => 1]) ?>">+ <?= tt('dn.new') ?></a><?php endif; ?><a class="btn btn--ghost btn--sm" href="<?= url('inventory') ?>">📦 <?= tt('stk.title') ?></a></div>
</div>

<?php if ($new): ?>
<div class="card" id="dn-new">
  <h2>+ <?= tt('dn.new') ?></h2>
  <form method="post" action="<?= url('delivery_notes') ?>" data-lock id="dn-form"><?= csrf_field() ?><input type="hidden" name="action" value="dn_create">
    <div class="form-grid">
      <div class="field"><label><?= tt('dn.kind') ?></label><select name="kind" id="dn-kind" data-nosearch><?php foreach (DN_KINDS as $k): ?><option value="<?= $k ?>"><?= dn_kind_icon($k) ?> <?= e(t('dn.k_' . $k)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dn.issued_at') ?></label><input name="issued_at" type="date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label><?= tt('stk.warehouse') ?></label><select name="warehouse_id" data-nosearch><?php foreach ($whs as $w): ?><option value="<?= (int)$w['id'] ?>"<?= $sel($w['id'], stk_default_wh()) ?>><?= e($w['code'] . ' — ' . $w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field dn-k-project"><label><?= tt('prq.project') ?></label><select name="project_id" id="prq-p"><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $preP) ?>><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field dn-k-project"><label><?= tt('prq.subproject') ?></label><select name="subproject_id" id="prq-s"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $sel($s['id'], $preS) ?>><?= e(($s['code'] ?: '') . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dn.company') ?></label><select name="company_id"><option value="">—</option><?php foreach ($orgs as $oc): ?><option value="<?= (int)$oc['id'] ?>"><?= e($oc['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel_id"><option value="">—</option><?php foreach ($vessels as $vv): ?><option value="<?= (int)$vv['id'] ?>"><?= e($vv['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dn.attention') ?></label><input name="attention" maxlength="120"></div>
      <div class="field field--full"><label><?= tt('dn.address') ?></label><input name="address" maxlength="240" placeholder="<?= tt('dn.address_ph') ?>"></div>
      <div class="field"><label><?= tt('po.carrier') ?></label><input name="carrier" maxlength="120"></div>
      <div class="field"><label><?= tt('po.ref') ?></label><input name="ref_no" maxlength="80" placeholder="<?= tt('dn.ref_ph') ?>"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2" maxlength="600"></textarea></div>
    </div>
    <h3 style="margin-top:12px;"><?= tt('prq.lines') ?></h3>
    <table class="tbl dn-lines" id="dn-lines"><thead><tr><th style="width:38%"><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th style="width:9em"><?= tt('prq.qty') ?></th><th style="width:6em"><?= tt('stk.unit') ?></th><th><?= tt('prq.note') ?></th><th></th></tr></thead><tbody></tbody></table>
    <template id="dn-row"><tr><td class="prq-pick"><input type="hidden" name="item[]" value=""><input class="dn-q" autocomplete="off" placeholder="<?= tt('po.item_ph') ?>"><div class="prq-list is-hidden"></div><div class="hint dn-chosen"></div></td><td><input name="desc[]" maxlength="255" required></td><td><input name="qty[]" type="number" step="0.001" min="0.001" required></td><td><select name="unit[]" data-nosearch><?php foreach (STK_UNITS as $un): ?><option value="<?= $un ?>"><?= $un ?></option><?php endforeach; ?></select></td><td><input name="lnote[]" maxlength="255"></td><td><button class="btn btn--ghost btn--sm dn-del" type="button">🗑</button></td></tr></template>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--ghost btn--sm" type="button" id="dn-add">+ <?= tt('prq.add_line') ?></button><label class="chk"><input type="checkbox" name="allow_negative" value="1"> <?= tt('dn.allow_negative') ?></label></div>
    <div class="btn-row" style="margin-top:12px;"><button class="btn btn--gold" type="submit">🚚 <?= tt('dn.issue') ?></button><a class="btn btn--ghost" href="<?= url('delivery_notes') ?>"><?= tt('btn.cancel') ?></a><span class="hint"><?= tt('dn.issue_hint') ?></span></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">
  (function(){
    var $=function(i){return document.getElementById(i);},tb=$('dn-lines').querySelector('tbody'),tpl=$('dn-row'),url=<?= json_encode(url('prq_items')) ?>,FREE=<?= json_encode(t('prq.free')) ?>;
    function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function wire(tr){var q=tr.querySelector('.dn-q'),list=tr.querySelector('.prq-list'),hid=tr.querySelector('input[name="item[]"]'),ch=tr.querySelector('.dn-chosen'),desc=tr.querySelector('input[name="desc[]"]'),un=tr.querySelector('select[name="unit[]"]'),t=null;
      function render(rows){list.innerHTML='';rows.forEach(function(it){var d=document.createElement('div');d.className='prq-opt';d.innerHTML='<b>'+esc(it.code)+'</b> '+esc(it.desc)+' <small>'+esc(it.maker||'')+' '+esc(it.pn||'')+' · '+FREE+' '+it.free+' '+esc(it.unit)+'</small>';d.addEventListener('click',function(){choose(it);});list.appendChild(d);});list.classList.toggle('is-hidden',!rows.length);}
      function choose(it){hid.value=it.id;q.value=it.code;desc.value=it.desc||'';if(un)un.value=it.unit||'pcs';ch.textContent=FREE+' '+it.free+' '+(it.unit||'');list.classList.add('is-hidden');}
      q.addEventListener('input',function(){hid.value='';ch.textContent='';var v=q.value.trim();clearTimeout(t);if(v.length<2){list.classList.add('is-hidden');return;}t=setTimeout(function(){fetch(url+'&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(render).catch(function(){});},220);});
      q.addEventListener('focus',function(){if(q.value.trim().length<2){fetch(url+'&recent=1&q=',{credentials:'same-origin'}).then(function(r){return r.json();}).then(render).catch(function(){});}});
      document.addEventListener('click',function(e){if(!list.contains(e.target)&&e.target!==q)list.classList.add('is-hidden');});
      tr.querySelector('.dn-del').addEventListener('click',function(){tr.remove();});
      tr.choose=choose;}
    function add(it){var tr=tpl.content.firstElementChild.cloneNode(true);tb.appendChild(tr);wire(tr);if(it)tr.choose(it);return tr;}
    $('dn-add').addEventListener('click',function(){add();});
    var pre=<?= json_encode($preItem ? ['id' => (int)$preItem['id'], 'code' => $preItem['code'], 'desc' => $preItem['description'], 'unit' => $preItem['unit'], 'free' => (float)$preItem['on_hand'] - (float)$preItem['reserved']] : null) ?>;add(pre);
    var p=$('prq-p'),s=$('prq-s');function f(){var v=p.value;Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;var on=!v||o.getAttribute('data-p')===v;o.hidden=!on;o.disabled=!on;});if(s.selectedOptions[0]&&s.selectedOptions[0].disabled)s.value='';}p.addEventListener('change',f);f();
  })();
  </script>
</div>
<?php else: ?>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr auto;"><input type="hidden" name="p" value="delivery_notes">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('dn.search_ph') ?>"></div>
  <div class="field"><label><?= tt('dn.kind') ?></label><select name="kind" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (DN_KINDS as $k): ?><option value="<?= $k ?>"<?= $sel($k, $f['kind']) ?>><?= e(t('dn.k_' . $k)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('prq.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><option value="issued"<?= $sel('issued', $f['status']) ?>><?= tt('dn.s_issued') ?></option><option value="cancelled"<?= $sel('cancelled', $f['status']) ?>><?= tt('dn.s_cancelled') ?></option></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('delivery_notes') ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <h2><?= tt('dn.register') ?> <span class="count"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><p class="hint"><?= tt('dn.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('dn.no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('dn.kind') ?></th><th><?= tt('dn.recipient') ?></th><th><?= tt('po.ref') ?></th><th class="num"><?= tt('prq.lines') ?></th><th><?= tt('th.by') ?></th><th><?= tt('prq.status') ?></th></tr></thead><tbody>
    <?php foreach ($rows as $d): ?><tr class="<?= $d['status'] === 'cancelled' ? 'is-retired' : '' ?>"><td><a href="<?= url('delivery_note', ['id' => (int)$d['id']]) ?>" class="mono"><b><?= e($d['dn_no']) ?></b></a><?= $d['receipt_no'] ? '<br><small class="hint">' . e($d['receipt_no']) . ($d['po_no'] ? ' · ' . e($d['po_no']) : '') . '</small>' : '' ?></td><td class="date"><?= e(fmt_date($d['issued_at'])) ?></td><td><?= dn_kind_icon($d['kind']) ?> <?= e(t('dn.k_' . $d['kind'])) ?></td>
      <td><?= $d['project_code'] ? pr_link((int)$d['project_id'], $d['project_code'], $d['project_name']) . ($d['sub_code'] ? ' <span class="hint">› ' . e($d['sub_code']) . '</span>' : '') : '' ?><?= $d['company'] ? ($d['project_code'] ? '<br>' : '') . e($d['company']) : '' ?><?= $d['vessel'] ? ' <span class="hint">· ' . e($d['vessel']) . '</span>' : '' ?></td>
      <td class="hint"><?= e($d['ref_no'] ?? '') ?><?= $d['carrier'] ? ' · ' . e($d['carrier']) : '' ?></td><td class="num"><?= (int)$d['n_lines'] ?></td><td class="hint"><?= e($d['user_name'] ?? '') ?></td><td><?= dn_pill($d['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

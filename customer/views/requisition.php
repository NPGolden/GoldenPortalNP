<?php
/** Procurement › Requisition — header, lines, budget check, approvals, issues, log (GAS-CP-R80-001).  ?p=requisition&id=N (0 = new) */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('prq_can') || !prq_can()) { http_response_code(403); layout_head(t('prq.title'), 'requisitions'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('prq.noaccess_hint') . '</p>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $r = $id ? prq_load($id) : null;
if ($id && (!$r || !prq_can_view($r))) { http_response_code(404); layout_head(t('err.notfound'), 'requisitions'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$edit = !$r || prq_can_edit($r); $admin = pr_admin(); $wh = pr_wh(); $mgmt = mgmt_can(); $priced = stk_price_visible();
$projects = all("SELECT id, project_code, name FROM projects WHERE status IN ('active','quoted','onhold','planned') OR id = ? ORDER BY project_code DESC LIMIT 400", [(int)($r['project_id'] ?? 0)]);
$subs = all("SELECT sp.id, sp.project_id, sp.code, sp.name FROM subprojects sp WHERE sp.status IN ('active','quoted','onhold') OR sp.id = ? ORDER BY sp.sort_order, sp.code LIMIT 1500", [(int)($r['subproject_id'] ?? 0)]);
$preP = (int)($_GET['project_id'] ?? ($r['project_id'] ?? 0)); $preS = (int)($_GET['subproject_id'] ?? ($r['subproject_id'] ?? 0));
if (!$r && $preS && !$preP) $preP = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$preS]);
$lines = $r ? prq_lines($id) : []; $events = $r ? prq_events($id) : []; $B = $r && $r['subproject_id'] ? prq_budget((int)$r['subproject_id'], $id) : null;
$back = url('requisitions'); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $m = fn(?float $v) => prq_money($v, $r['currency'] ?? 'EUR');
$est = $r ? (float)($r['est_total'] ?? 0) : 0.0; $overNow = $B && ($B['parts_budget'] === null || $B['parts_budget'] <= 0 || $est > (float)$B['available']);
layout_head(($r ? $r['pr_no'] : t('prq.new')) . ' · ' . t('prq.title'), 'requisitions');
?>
<style>.prq-pick{position:relative}.prq-list{position:absolute;left:0;right:0;top:100%;z-index:20;background:var(--card,#fff);border:1px solid var(--line,#d9dee5);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow:auto}.prq-opt{padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--line,#eef1f5);font-size:.92em}.prq-opt:hover{background:var(--navy-soft,#eef2f8)}.prq-opt small{color:var(--muted,#6b7280);display:block}.steps{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(5,1fr);gap:4px;text-align:center}.steps li{padding:6px 4px;border-radius:8px;background:var(--line,#eef1f5);font-size:.85em}.steps li.done{background:var(--green,#2e7d32);color:#fff}.steps li.now{background:var(--gold,#c9a227);color:#1f3a5f;font-weight:600}.steps li.skip{opacity:.45;text-decoration:line-through}</style>
<p class="eyebrow"><a href="<?= $back ?>"><?= tt('prq.title') ?></a> › <?= $r ? e($r['pr_no']) : tt('prq.new') ?></p>
<div class="page-head">
  <div><h1>🧾 <?= $r ? e($r['pr_no']) : tt('prq.new') ?> <?= $r ? prq_pill($r['status']) : '' ?><?= $r && (int)$r['over_budget'] && in_array($r['status'], ['submitted', 'mgmt', 'approved'], true) ? ' ' . pill('warn', t('prq.over')) : '' ?></h1>
    <?php if ($r): ?><p class="lede" style="margin-bottom:0;"><?= pr_link((int)$r['project_id'], $r['project_code'], $r['project_name']) ?><?= $r['subproject_id'] ? ' › <a href="' . url('subproject', ['id' => (int)$r['subproject_id'], 'tab' => 'procurement']) . '">' . e($r['sub_code'] . ' · ' . $r['sub_name']) . '</a>' : '' ?> · <?= tt('prq.p_' . $r['purpose']) ?> · <?= e($r['requester_name'] ?? '') ?><?= $r['discipline'] ? ' (' . e($r['discipline']) . ')' : '' ?></p><?php endif; ?></div>
  <div class="btn-row">
    <?php if ($r && $r['status'] === 'draft' && $edit): ?><form method="post" action="<?= url('requisition', ['id' => $id]) ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.submit_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="prq_submit"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit"<?= $lines ? '' : ' disabled' ?>>➤ <?= tt('prq.submit') ?></button></form><?php endif; ?>
    <?php if ($r && (($r['requester_id'] == $u['id'] && in_array($r['status'], ['draft', 'submitted'], true)) || ($admin && in_array($r['status'], ['draft', 'submitted', 'mgmt', 'approved'], true)))): ?><form method="post" action="<?= url('requisition', ['id' => $id]) ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.cancel_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="prq_cancel"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">✕ <?= tt('prq.cancel') ?></button></form><?php endif; ?>
  </div>
</div>

<?php if ($r && $priced): ?>
<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat gold"><b><?= e($m($est)) ?></b><?= tt('prq.estimate') ?><span class="s"><?= tt('prq.estimate_hint') ?></span></div>
  <div class="stat"><b><?= e($B && $B['parts_budget'] !== null ? $m($B['parts_budget']) : '—') ?></b><?= tt('prq.b_parts') ?><span class="s"><?= $r['subproject_id'] ? tt('prq.b_parts_hint') : tt('prq.b_nosub') ?></span></div>
  <div class="stat"><b><?= e($B ? $m($B['committed'] + $B['actual']) : '—') ?></b><?= tt('prq.b_used') ?><span class="s"><?= $B ? e(t('prq.b_used_hint', ['c' => $m($B['committed']), 'a' => $m($B['actual'])])) : '' ?></span></div>
  <div class="stat<?= $B && $B['available'] !== null && $est > $B['available'] ? ' red' : '' ?>"><b><?= e($B && $B['available'] !== null ? $m($B['available']) : '—') ?></b><?= tt('prq.b_avail') ?><span class="s"><?= $B && $B['available'] !== null && $est > $B['available'] ? e(t('prq.over_by', ['v' => $m($est - $B['available'])])) : tt('prq.b_avail_hint') ?></span></div>
</div>
<?php endif; ?>

<div class="grid grid-2">
<div class="card">
  <h2><?= tt('prq.header') ?></h2>
  <?php if ($edit): ?>
  <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="prq_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-grid">
      <div class="field"><label><?= tt('prq.project') ?> *</label><select name="project_id" id="prq-p" required><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($p['id'], $preP) ?>><?= e(($p['project_code'] ?: '') . ' · ' . $p['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('prq.subproject') ?></label><select name="subproject_id" id="prq-s"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $sel($s['id'], $preS) ?>><?= e(($s['code'] ?: '') . ' · ' . $s['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('prq.purpose') ?></label><select name="purpose"><?php foreach (PRQ_PURPOSE as $pp): ?><option value="<?= $pp ?>"<?= $sel($pp, $r['purpose'] ?? 'project') ?>><?= tt('prq.p_' . $pp) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('prq.need') ?></label><input type="date" name="need_date" value="<?= e($r['need_date'] ?? '') ?>"></div>
      <div class="field field--full"><label><?= tt('prq.r_title') ?></label><input name="title" maxlength="160" value="<?= e($r['title'] ?? '') ?>" placeholder="<?= tt('prq.r_title_ph') ?>"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><textarea name="note" rows="2"><?= e($r['note'] ?? '') ?></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= $r ? tt('prq.save') : tt('prq.create') ?></button><?php if (!$r): ?><span class="hint"><?= tt('prq.create_hint') ?></span><?php endif; ?></div>
  </form>
  <script>(function(){var p=document.getElementById('prq-p'),s=document.getElementById('prq-s');if(!p||!s)return;function f(){var v=p.value;Array.prototype.forEach.call(s.options,function(o){if(!o.value)return;var on=!v||o.getAttribute('data-p')===v;o.hidden=!on;o.disabled=!on;});if(s.selectedOptions[0]&&s.selectedOptions[0].disabled)s.value='';}p.addEventListener('change',f);f();})();</script>
  <?php else: ?>
  <div class="kv-grid">
    <div class="kv"><span><?= tt('prq.purpose') ?></span><b><?= tt('prq.p_' . $r['purpose']) ?></b></div><div class="kv"><span><?= tt('prq.need') ?></span><b><?= fmt_date($r['need_date']) ?></b></div>
    <div class="kv"><span><?= tt('prq.requester') ?></span><b><?= e($r['requester_name'] ?? '') ?></b></div><div class="kv"><span><?= tt('prq.submitted') ?></span><b><?= fmt_datetime($r['submitted_at']) ?></b></div>
    <?php if ($r['approved_at']): ?><div class="kv"><span><?= tt('prq.procurement') ?></span><b><?= e($r['approved_name']) ?> · <?= fmt_datetime($r['approved_at']) ?></b></div><div class="kv"><span><?= tt('prq.reason') ?></span><b><?= e($r['approval_note'] ?: '—') ?></b></div><?php endif; ?>
    <?php if ($r['mgmt_at']): ?><div class="kv"><span><?= tt('prq.management') ?></span><b><?= e($r['mgmt_name']) ?> · <?= fmt_datetime($r['mgmt_at']) ?></b></div><div class="kv"><span><?= tt('prq.reason') ?></span><b><?= e($r['mgmt_note'] ?: '—') ?></b></div><?php endif; ?>
    <?php if ($r['title']): ?><div class="kv kv--full"><span><?= tt('prq.r_title') ?></span><b><?= e($r['title']) ?></b></div><?php endif; ?>
    <?php if ($r['note']): ?><div class="kv kv--full"><span><?= tt('prq.note') ?></span><b style="white-space:pre-wrap;font-weight:400;"><?= e($r['note']) ?></b></div><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<div class="card">
  <?php if ($r && $r['status'] === 'submitted' && $admin): ?>
  <h2><?= tt('prq.decide') ?></h2>
  <p class="hint"><?= $overNow ? ($B && $B['parts_budget'] !== null && $B['parts_budget'] > 0 ? tt('prq.decide_over') : ($r['purpose'] === 'project' ? tt('prq.decide_nobudget') : tt('prq.decide_nonproject'))) : tt('prq.decide_ok') ?></p>
  <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="prq_decide"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('prq.reason') ?><?= $overNow && $r['purpose'] === 'project' ? ' *' : '' ?></label><textarea name="note" rows="2" maxlength="400" placeholder="<?= tt('prq.reason_ph') ?>"></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve">✓ <?= $overNow && $r['purpose'] === 'project' ? tt('prq.to_mgmt') : tt('prq.approve') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" onclick="return confirm(<?= e(json_encode(t('prq.reject_q'))) ?>)">✕ <?= tt('prq.reject') ?></button></div>
  </form>
  <?php elseif ($r && $r['status'] === 'mgmt' && $mgmt): ?>
  <h2><?= tt('prq.mgmt_decide') ?></h2>
  <p class="hint"><?= e(t('prq.mgmt_lede', ['who' => $r['approved_name'] ?? '', 'why' => $r['approval_note'] ?? ''])) ?></p>
  <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="prq_mgmt"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('prq.reason') ?></label><textarea name="note" rows="2" maxlength="400"></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve">✓ <?= tt('prq.approve') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" onclick="return confirm(<?= e(json_encode(t('prq.reject_q'))) ?>)">✕ <?= tt('prq.reject') ?></button></div>
  </form>
  <?php else: ?>
  <h2><?= tt('prq.flow') ?></h2>
  <ol class="steps">
    <?php $st = $r['status'] ?? 'draft'; $order = ['draft', 'submitted', 'mgmt', 'approved', 'closed']; $pos = array_search($st, $order, true); if ($pos === false) $pos = -1;
    foreach ([['draft', 'prq.f_draft'], ['submitted', 'prq.f_submitted'], ['mgmt', 'prq.f_mgmt'], ['approved', 'prq.f_approved'], ['closed', 'prq.f_closed']] as [$k, $lbl]): $i = array_search($k, $order, true); $cls = $i < $pos ? 'done' : ($i === $pos ? 'now' : ''); if ($k === 'mgmt' && $r && !(int)$r['over_budget'] && $pos > 2) $cls = 'skip'; ?>
      <li class="<?= $cls ?>"><?= tt($lbl) ?></li>
    <?php endforeach; ?>
  </ol>
  <?php if ($r && in_array($r['status'], ['rejected', 'cancelled'], true)): ?><p><?= prq_pill($r['status']) ?> <?= e($r['status'] === 'rejected' ? ($r['mgmt_note'] ?: $r['approval_note'] ?: '') : '') ?></p><?php endif; ?>
  <p class="hint"><?= tt('prq.flow_hint') ?></p>
  <?php endif; ?>
</div>
</div>

<?php if ($r): ?>
<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('prq.lines') ?> <span class="hint" style="font-weight:400;">(<?= count($lines) ?>)</span></h2></div></div>
  <table class="tbl tbl--wrap">
    <thead><tr><th>#</th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th class="num"><?= tt('prq.stock') ?></th><th class="num"><?= tt('prq.reserved') ?></th><th class="num"><?= tt('prq.issued') ?></th><?php if ($priced): ?><th class="num"><?= tt('prq.est_unit') ?></th><th class="num"><?= tt('prq.est_line') ?></th><?php endif; ?><th><?= tt('prq.need') ?></th><th><?= tt('prq.status') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="12" class="hint"><?= tt('prq.no_lines') ?></td></tr><?php endif; ?>
    <?php foreach ($lines as $l): $free = (float)$l['on_hand'] - (float)$l['reserved_all']; $left = (float)$l['qty_reserved'];   /* received quantities are moved into the reservation at goods receipt (R80-004) */ ?>
      <tr>
        <td><?= (int)$l['seq'] ?></td>
        <td><?php if ($l['item_id']): ?><a href="<?= url('inventory_item', ['id' => (int)$l['item_id']]) ?>" style="font-family:var(--mono);"><?= e($l['item_code']) ?></a><?php else: ?><span class="hint"><?= tt('prq.free_text') ?></span><?php endif; ?><?php if ($l['maker'] || $l['part_no']): ?><br><small class="hint"><?= e(trim(($l['maker'] ?? '') . ' ' . ($l['part_no'] ?? ''))) ?></small><?php endif; ?></td>
        <td><?= e($l['description']) ?><?php if ($l['note']): ?><br><small class="hint"><?= e($l['note']) ?></small><?php endif; ?></td>
        <td class="num"><?= stk_qty((float)$l['qty']) ?> <?= e($l['unit']) ?></td>
        <td class="num"><?= $l['item_id'] ? stk_qty((float)$l['on_hand']) . ' <small class="hint">(' . stk_qty($free) . ')</small>' : '—' ?></td>
        <td class="num"><?= (float)$l['qty_reserved'] > 0 ? stk_qty((float)$l['qty_reserved']) : '—' ?></td>
        <td class="num"><?= (float)$l['qty_issued'] > 0 ? stk_qty((float)$l['qty_issued']) : '—' ?></td>
        <?php if ($priced): ?><td class="num"><?= $l['est_price'] !== null ? e(number_format((float)$l['est_price'], 2, ',', '.')) : '—' ?><?php if ($l['price_origin']): ?><br><small class="hint"><?= e($l['price_origin']) ?></small><?php endif; ?></td><td class="num"><?= $l['est_price'] !== null ? e($m((float)$l['qty'] * (float)$l['est_price'])) : '—' ?></td><?php endif; ?>
        <td><?= fmt_date($l['need_date']) ?></td>
        <td><?= prq_line_pill($l['status']) ?></td>
        <td class="num" style="white-space:nowrap;">
          <?php if ($edit): ?><button class="btn btn--ghost btn--sm" type="button" data-edit-line='<?= e(json_encode(['id' => (int)$l['id'], 'item_id' => (int)$l['item_id'], 'code' => $l['item_code'], 'description' => $l['description'], 'maker' => $l['maker'], 'part_no' => $l['part_no'], 'unit' => $l['unit'], 'qty' => (float)$l['qty'], 'est_price' => $l['est_price'], 'need_date' => $l['need_date'], 'note' => $l['note']])) ?>'>✎</button>
            <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" style="display:inline" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.del_line_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="prq_line_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?>
          <?php if ($wh && $r['status'] === 'approved' && $l['item_id'] && $left > 0.0005 && in_array($l['status'], ['reserved', 'to_buy', 'received'], true)): ?>
            <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" style="display:inline-flex;gap:4px;align-items:center;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="prq_issue"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><input name="qty" type="number" step="0.001" min="0.001" max="<?= $left ?>" value="<?= $left ?>" style="width:5.5em;" title="<?= tt('prq.issue_qty') ?>"><button class="btn btn--sm" type="submit" title="<?= tt('prq.issue_hint') ?>">📤 <?= tt('prq.issue') ?></button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($edit): ?>
  <h3 id="line-form-title" style="margin-top:14px;"><?= tt('prq.add_line') ?></h3>
  <form method="post" action="<?= url('requisition', ['id' => $id]) ?>" id="line-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="prq_line_save"><input type="hidden" name="request_id" value="<?= $id ?>"><input type="hidden" name="line_id" id="lf-id" value="0"><input type="hidden" name="item_id" id="lf-item" value="">
    <div class="form-grid">
      <div class="field field--full prq-pick"><label><?= tt('prq.item') ?> <span class="hint"><?= tt('prq.item_hint') ?></span></label><input id="lf-q" autocomplete="off" placeholder="<?= tt('prq.item_ph') ?>"><div id="lf-list" class="prq-list is-hidden"></div><div id="lf-chosen" class="hint"></div></div>
      <div class="field field--full"><label><?= tt('prq.desc') ?> *</label><input name="description" id="lf-desc" maxlength="255" required></div>
      <div class="field"><label><?= tt('stk.maker') ?></label><input name="maker" id="lf-maker" maxlength="80"></div>
      <div class="field"><label><?= tt('stk.factory_code') ?></label><input name="part_no" id="lf-pn" maxlength="80"></div>
      <div class="field"><label><?= tt('prq.qty') ?> *</label><input name="qty" id="lf-qty" type="number" step="0.001" min="0.001" required></div>
      <div class="field"><label><?= tt('stk.unit') ?></label><select name="unit" id="lf-unit"><?php foreach (STK_UNITS as $un): ?><option value="<?= $un ?>"><?= $un ?></option><?php endforeach; ?></select></div>
      <?php if ($priced): ?><div class="field"><label><?= tt('prq.est_unit') ?> <span class="hint"><?= tt('prq.est_unit_hint') ?></span></label><input name="est_price" id="lf-price" type="number" step="0.0001" min="0"></div><?php endif; ?>
      <div class="field"><label><?= tt('prq.need') ?></label><input name="need_date" id="lf-need" type="date"></div>
      <div class="field field--full"><label><?= tt('prq.note') ?></label><input name="note" id="lf-note" maxlength="255"></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit" id="lf-btn"><?= tt('prq.add_line') ?></button><button class="btn btn--ghost btn--sm" type="button" id="lf-reset"><?= tt('prq.clear') ?></button></div>
  </form>
  <script>
  (function(){
    var q=document.getElementById('lf-q'),list=document.getElementById('lf-list'),ch=document.getElementById('lf-chosen'),item=document.getElementById('lf-item'),desc=document.getElementById('lf-desc'),mk=document.getElementById('lf-maker'),pn=document.getElementById('lf-pn'),un=document.getElementById('lf-unit'),pr=document.getElementById('lf-price'),t=null,url=<?= json_encode(url('prq_items')) ?>;
    function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
    function choose(it){item.value=it.id;desc.value=it.desc||'';mk.value=it.maker||'';pn.value=it.pn||'';if(un)un.value=it.unit||'pcs';if(pr&&it.price!=null)pr.value=it.price;q.value=it.code;ch.textContent=it.code+' · '+(it.desc||'')+' · <?= e(t('prq.free')) ?> '+it.free;list.classList.add('is-hidden');mk.readOnly=pn.readOnly=true;}
    function clear(){item.value='';ch.textContent='';mk.readOnly=pn.readOnly=false;}
    q.addEventListener('input',function(){clear();var v=q.value.trim();clearTimeout(t);if(v.length<2){list.classList.add('is-hidden');return;}t=setTimeout(function(){fetch(url+'&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(rows){list.innerHTML='';if(!rows.length){list.classList.add('is-hidden');return;}rows.forEach(function(it){var d=document.createElement('div');d.className='prq-opt';d.innerHTML='<b>'+esc(it.code)+'</b> '+esc(it.desc)+' <small>'+esc(it.maker||'')+' '+esc(it.pn||'')+' · <?= e(t('prq.free')) ?> '+it.free+' '+esc(it.unit)+(it.price!=null?' · '+it.price+' '+esc(it.cur):'')+'</small>';d.addEventListener('click',function(){choose(it);});list.appendChild(d);});list.classList.remove('is-hidden');}).catch(function(){});},220);});
    document.addEventListener('click',function(e){if(!list.contains(e.target)&&e.target!==q)list.classList.add('is-hidden');});
    document.querySelectorAll('[data-edit-line]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.getAttribute('data-edit-line'));document.getElementById('lf-id').value=d.id;item.value=d.item_id||'';q.value=d.code||'';desc.value=d.description||'';mk.value=d.maker||'';pn.value=d.part_no||'';if(un)un.value=d.unit||'pcs';document.getElementById('lf-qty').value=d.qty;if(pr)pr.value=d.est_price==null?'':d.est_price;document.getElementById('lf-need').value=d.need_date||'';document.getElementById('lf-note').value=d.note||'';ch.textContent=d.code?d.code:'';mk.readOnly=pn.readOnly=!!d.item_id;document.getElementById('lf-btn').textContent=<?= json_encode(t('prq.save_line')) ?>;document.getElementById('line-form-title').textContent=<?= json_encode(t('prq.edit_line')) ?>+' #'+d.id;document.getElementById('line-form').scrollIntoView({behavior:'smooth',block:'center'});});});
    document.getElementById('lf-reset').addEventListener('click',function(){document.getElementById('line-form').reset();document.getElementById('lf-id').value='0';clear();q.value='';document.getElementById('lf-btn').textContent=<?= json_encode(t('prq.add_line')) ?>;document.getElementById('line-form-title').textContent=<?= json_encode(t('prq.add_line')) ?>;});
  })();
  </script>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('prq.log') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('prq.when') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('prq.event') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td><?= fmt_datetime($ev['created_at']) ?></td><td><?= e($ev['user_name'] ?? '') ?></td><td><?= tt('prq.ev_' . $ev['event']) ?></td><td><?= e($ev['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php layout_foot();

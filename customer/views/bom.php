<?php
/** Sub-project › Engineering BOM revision — lines with stock coverage, change proposals (Procurement) and decisions (Engineering), approval, log (GAS-CP-R80-002).  ?p=bom&id=N */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $b = bom_load($id);
if (!$b || !bom_can_view((int)$b['subproject_id'])) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$sid = (int)$b['subproject_id']; $eng = bom_eng_can($sid); $proc = bom_pr_can(); $edit = bom_editable($b); $priced = stk_price_visible();
$lines = bom_lines($id);
if (($_GET['format'] ?? '') === 'xlsx') { require_once __DIR__ . '/../app/xlsxw.php'; $hdr = ['#', t('bom.section'), t('stk.code'), t('prq.desc'), t('stk.maker'), t('stk.factory_code'), t('prq.qty'), t('stk.unit'), t('bom.free'), t('bom.to_buy')]; if ($priced) array_push($hdr, t('prq.est_unit'), t('prq.est_line')); $rows = [];
    foreach ($lines as $l) { if ($l['status'] === 'removed') continue; $r = [(int)$l['seq'], $l['section'], $l['item_code'] ?: $l['code'], $l['description'], $l['maker'], $l['part_no'], (float)$l['qty'], $l['unit'], $l['item_id'] ? (float)$l['free'] : null, (float)$l['to_buy']]; if ($priced) array_push($r, $l['est_price'] !== null ? (float)$l['est_price'] : null, $l['est_price'] !== null ? round((float)$l['qty'] * (float)$l['est_price'], 2) : null); $rows[] = $r; }
    xlsx_download('BOM-' . preg_replace('/[^A-Za-z0-9.-]/', '_', $b['sub_code'] . '-R' . $b['revision']) . '.xlsx', [['name' => 'BOM R' . (int)$b['revision'], 'first' => true, 'header' => $hdr, 'rows' => $rows]]); exit; }
$changes = bom_changes($id); $events = bom_events($id); $cov = bom_coverage($lines);
$pending = array_filter($changes, fn($c) => $c['status'] === 'proposed'); $m = fn(?float $v) => prq_money($v, $b['currency']);
$rev = 'R' . (int)$b['revision']; $back = url('subproject', ['id' => $sid, 'tab' => 'procurement']);
$covPill = fn(string $c) => match ($c) { 'full' => pill('ok', t('bom.cv_full')), 'partial' => pill('gold', t('bom.cv_partial')), 'zero' => pill('bad', t('bom.cv_zero')), default => pill('mute', t('bom.cv_none')) };
layout_head($b['sub_code'] . ' · BOM ' . $rev, 'projects');
?>
<style>.prq-pick{position:relative}.prq-list{position:absolute;left:0;right:0;top:100%;z-index:20;background:var(--card,#fff);border:1px solid var(--line,#d9dee5);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);max-height:280px;overflow:auto}.prq-opt{padding:7px 10px;cursor:pointer;border-bottom:1px solid var(--line,#eef1f5);font-size:.92em}.prq-opt:hover{background:var(--navy-soft,#eef2f8)}.prq-opt small{color:var(--muted,#6b7280);display:block}tr.is-removed td{opacity:.45;text-decoration:line-through}</style>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$b['project_id'], $b['project_code'], $b['project_name']) ?> › <a href="<?= $back ?>"><?= e($b['sub_code'] . ' · ' . $b['sub_name']) ?></a> › BOM <?= $rev ?></p>
<div class="page-head">
  <div><h1>📐 <?= tt('bom.title') ?> <?= $rev ?> <?= bom_pill($b['status']) ?><?= $pending ? ' ' . pill('gold', count($pending) . ' ' . t('bom.pending')) : '' ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($b['title'] ?: $b['sub_name']) ?> · <?= e($b['created_name'] ?? '') ?><?= $b['issued_at'] ? ' · ' . tt('bom.issued') . ' ' . fmt_datetime($b['issued_at']) . ' (' . e($b['issued_name']) . ')' : '' ?><?= $b['approved_at'] ? ' · ' . tt('bom.s_' . $b['status']) . ' ' . fmt_datetime($b['approved_at']) . ' (' . e($b['approved_name']) . ')' : '' ?></p></div>
  <div class="btn-row">
    <?php if ($edit): ?><form method="post" action="<?= url('bom', ['id' => $id]) ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('bom.issue_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="bom_issue"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit"<?= $cov['lines'] ? '' : ' disabled' ?>>➤ <?= tt('bom.issue_btn') ?></button></form><?php endif; ?>
    <?php if ($b['status'] === 'issued' && $eng): ?><form method="post" action="<?= url('bom', ['id' => $id]) ?>" data-lock onsubmit="return confirm(<?= e(json_encode(t('bom.withdraw_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="bom_withdraw"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('bom.withdraw') ?></button></form><?php endif; ?>
    <?php if ($b['status'] === 'approved' && $cov['to_buy'] && prq_can() && ($proc || $eng)): ?><form method="post" action="<?= url('bom', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_to_pr"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--sm" type="submit">🧾 <?= tt('bom.to_pr') ?></button></form><?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('bom', ['id' => $id, 'format' => 'xlsx']) ?>">⤓ Excel</a>
  </div>
</div>

<div class="kpi kpi--4" style="margin-bottom:10px;">
  <div class="stat"><b><?= $cov['lines'] ?></b><?= tt('bom.lines') ?><span class="s"><?= e(t('bom.lines_hint', ['n' => $cov['none']])) ?></span></div>
  <div class="stat<?= $cov['full'] === $cov['lines'] && $cov['lines'] ? ' ok' : '' ?>"><b><?= $cov['full'] ?></b><?= tt('bom.cv_full') ?><span class="s"><?= e(t('bom.cv_partial_n', ['n' => $cov['partial']])) ?></span></div>
  <div class="stat<?= $cov['to_buy'] ? ' gold' : '' ?>"><b><?= $cov['to_buy'] ?></b><?= tt('bom.to_buy') ?><span class="s"><?= $priced ? e($m($cov['est_to_buy'])) : tt('bom.to_buy_hint') ?></span></div>
  <div class="stat"><b><?= $priced ? e($m($cov['est'])) : '—' ?></b><?= tt('prq.estimate') ?><span class="s"><?= tt('prq.estimate_hint') ?></span></div>
</div>

<?php if ($b['status'] === 'issued' && $proc): ?>
<div class="card">
  <h2><?= tt('bom.proc_review') ?></h2>
  <p class="hint"><?= $pending ? e(t('bom.pending_hint', ['n' => count($pending)])) : tt('bom.approve_hint') ?></p>
  <form method="post" action="<?= url('bom', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_approve"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><label><?= tt('prq.reason') ?></label><textarea name="note" rows="2" maxlength="400"></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="decision" value="approve"<?= $pending ? ' disabled' : '' ?>>✓ <?= tt('bom.approve') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject" onclick="return confirm(<?= e(json_encode(t('bom.reject_q'))) ?>)">✕ <?= tt('bom.reject') ?></button></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('prq.lines') ?></h2><p class="hint"><?= tt('bom.lines_lede') ?></p></div></div>
  <table class="tbl tbl--wrap">
    <thead><tr><th>#</th><th><?= tt('bom.section') ?></th><th><?= tt('prq.item') ?></th><th><?= tt('prq.desc') ?></th><th class="num"><?= tt('prq.qty') ?></th><th class="num"><?= tt('bom.free') ?></th><th><?= tt('bom.coverage') ?></th><?php if ($priced): ?><th class="num"><?= tt('prq.est_unit') ?></th><th class="num"><?= tt('prq.est_line') ?></th><?php endif; ?><th><?= tt('prq.status') ?></th><th></th></tr></thead>
    <tbody>
    <?php if (!$lines): ?><tr><td colspan="11" class="hint"><?= tt('bom.no_lines') ?></td></tr><?php endif; ?>
    <?php foreach ($lines as $l): ?>
      <tr class="<?= $l['status'] === 'removed' ? 'is-removed' : '' ?>" id="l<?= (int)$l['id'] ?>">
        <td><?= (int)$l['seq'] ?></td><td><?= e($l['section'] ?? '') ?></td>
        <td><?php if ($l['item_id']): ?><a href="<?= url('inventory_item', ['id' => (int)$l['item_id']]) ?>" style="font-family:var(--mono);"><?= e($l['item_code']) ?></a><?php elseif ($l['code']): ?><span style="font-family:var(--mono);" title="<?= tt('bom.not_in_inv') ?>"><?= e($l['code']) ?></span> <span class="pill pill--mute">?</span><?php else: ?><span class="hint"><?= tt('prq.free_text') ?></span><?php endif; ?><?php if ($l['maker'] || $l['part_no']): ?><br><small class="hint"><?= e(trim(($l['maker'] ?? '') . ' ' . ($l['part_no'] ?? ''))) ?></small><?php endif; ?></td>
        <td><?= e($l['description']) ?><?php if ($l['note']): ?><br><small class="hint"><?= e($l['note']) ?></small><?php endif; ?><?php if ((int)$l['n_pending']): ?> <span class="pill pill--gold" title="<?= tt('bom.pending') ?>">✎ <?= (int)$l['n_pending'] ?></span><?php endif; ?></td>
        <td class="num"><?= stk_qty((float)$l['qty']) ?> <?= e($l['unit']) ?></td>
        <td class="num"><?= $l['item_id'] ? stk_qty((float)$l['free']) : '—' ?></td>
        <td><?= $l['status'] === 'removed' ? '' : $covPill($l['cover']) ?><?= $l['to_buy'] > 0 && $l['status'] !== 'removed' ? ' <small class="hint">' . stk_qty((float)$l['to_buy']) . '</small>' : '' ?></td>
        <?php if ($priced): ?><td class="num"><?= $l['est_price'] !== null ? e(number_format((float)$l['est_price'], 2, ',', '.')) : '—' ?><?php if ($l['price_origin']): ?><br><small class="hint"><?= e($l['price_origin']) ?></small><?php endif; ?></td><td class="num"><?= $l['est_price'] !== null ? e($m((float)$l['qty'] * (float)$l['est_price'])) : '—' ?></td><?php endif; ?>
        <td><?= $l['status'] === 'ok' ? '' : pill($l['status'] === 'removed' ? 'mute' : 'navy', t('bom.l_' . $l['status'])) ?></td>
        <td class="num" style="white-space:nowrap;">
          <?php if ($edit): ?><button class="btn btn--ghost btn--sm" type="button" data-edit-line='<?= e(json_encode(['id' => (int)$l['id'], 'item_id' => (int)$l['item_id'], 'code' => $l['item_code'] ?: $l['code'], 'description' => $l['description'], 'maker' => $l['maker'], 'part_no' => $l['part_no'], 'unit' => $l['unit'], 'qty' => (float)$l['qty'], 'section' => $l['section'], 'note' => $l['note']])) ?>'>✎</button><form method="post" action="<?= url('bom', ['id' => $id]) ?>" style="display:inline" data-lock onsubmit="return confirm(<?= e(json_encode(t('prq.del_line_q'))) ?>)"><?= csrf_field() ?><input type="hidden" name="action" value="bom_line_del"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑</button></form><?php endif; ?>
          <?php if ($b['status'] === 'issued' && $proc && $l['status'] !== 'removed'): ?><button class="btn btn--ghost btn--sm" type="button" data-propose='<?= e(json_encode(['id' => (int)$l['id'], 'seq' => (int)$l['seq'], 'desc' => $l['description'], 'qty' => (float)$l['qty']])) ?>'>✎ <?= tt('bom.propose') ?></button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($edit): ?>
  <h3 id="line-form-title" style="margin-top:14px;"><?= tt('prq.add_line') ?></h3>
  <form method="post" action="<?= url('bom', ['id' => $id]) ?>" id="line-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_line_save"><input type="hidden" name="bom_id" value="<?= $id ?>"><input type="hidden" name="line_id" id="lf-id" value="0"><input type="hidden" name="item_id" id="lf-item" value="">
    <div class="form-grid">
      <div class="field field--full prq-pick"><label><?= tt('prq.item') ?> <span class="hint"><?= tt('prq.item_hint') ?></span></label><input id="lf-q" autocomplete="off" placeholder="<?= tt('prq.item_ph') ?>"><div id="lf-list" class="prq-list is-hidden"></div><div id="lf-chosen" class="hint"></div></div>
      <div class="field field--full"><label><?= tt('prq.desc') ?> *</label><input name="description" id="lf-desc" maxlength="255" required></div>
      <div class="field"><label><?= tt('stk.maker') ?></label><input name="maker" id="lf-maker" maxlength="80"></div>
      <div class="field"><label><?= tt('stk.factory_code') ?></label><input name="part_no" id="lf-pn" maxlength="80"></div>
      <div class="field"><label><?= tt('prq.qty') ?> *</label><input name="qty" id="lf-qty" type="number" step="0.001" min="0.001" required></div>
      <div class="field"><label><?= tt('stk.unit') ?></label><select name="unit" id="lf-unit"><?php foreach (STK_UNITS as $un): ?><option value="<?= $un ?>"><?= $un ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('bom.section') ?></label><input name="section" id="lf-section" maxlength="80" placeholder="<?= tt('bom.section_ph') ?>"></div>
      <div class="field"><label><?= tt('prq.note') ?></label><input name="note" id="lf-note" maxlength="255"></div>
    </div>
    <div class="btn-row"><button class="btn btn--sm" type="submit" id="lf-btn"><?= tt('prq.add_line') ?></button><button class="btn btn--ghost btn--sm" type="button" id="lf-reset"><?= tt('prq.clear') ?></button></div>
  </form>
  <h3 style="margin-top:14px;"><?= tt('bom.import') ?></h3>
  <form method="post" action="<?= url('bom', ['id' => $id]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_import"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="btn-row"><input type="file" name="file" accept=".xlsx,.xls,.csv" required><button class="btn btn--sm" type="submit">⤒ <?= tt('bom.import_btn') ?></button><span class="hint"><?= tt('bom.import_hint') ?></span></div>
  </form>
  <?php endif; ?>
</div>

<?php if ($b['status'] === 'issued' && $proc): ?>
<div class="card" id="propose">
  <h2><?= tt('bom.propose_title') ?></h2><p class="hint"><?= tt('bom.propose_lede') ?></p>
  <form method="post" action="<?= url('bom', ['id' => $id]) ?>" id="prop-form" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_change"><input type="hidden" name="bom_id" value="<?= $id ?>"><input type="hidden" name="alt_item_id" id="pf-item" value="">
    <div class="form-grid">
      <div class="field"><label><?= tt('bom.change_kind') ?></label><select name="kind" id="pf-kind"><?php foreach (BOM_KINDS as $k): ?><option value="<?= $k ?>"><?= tt('bom.k_' . $k) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('bom.line') ?></label><select name="line_id" id="pf-line"><option value="">—</option><?php foreach ($lines as $l): if ($l['status'] === 'removed') continue; ?><option value="<?= (int)$l['id'] ?>">#<?= (int)$l['seq'] ?> · <?= e(mb_substr($l['description'], 0, 50)) ?> × <?= stk_qty((float)$l['qty']) ?></option><?php endforeach; ?></select></div>
      <div class="field field--full prq-pick pf-alt"><label><?= tt('bom.alt_item') ?> <span class="hint"><?= tt('prq.item_hint') ?></span></label><input id="pf-q" autocomplete="off" placeholder="<?= tt('prq.item_ph') ?>"><div id="pf-list" class="prq-list is-hidden"></div><div id="pf-chosen" class="hint"></div></div>
      <div class="field field--full pf-alt"><label><?= tt('prq.desc') ?></label><input name="alt_description" id="pf-desc" maxlength="255"></div>
      <div class="field pf-alt"><label><?= tt('stk.maker') ?></label><input name="alt_maker" id="pf-maker" maxlength="80"></div>
      <div class="field pf-alt"><label><?= tt('stk.factory_code') ?></label><input name="alt_part_no" id="pf-pn" maxlength="80"></div>
      <div class="field pf-qty"><label><?= tt('prq.qty') ?></label><input name="qty" id="pf-qty" type="number" step="0.001" min="0.001"></div>
      <div class="field field--full"><label><?= tt('bom.reason') ?> *</label><textarea name="reason" rows="2" maxlength="600" required placeholder="<?= tt('bom.reason_ph') ?>"></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">➤ <?= tt('bom.propose_btn') ?></button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($changes): ?>
<div class="card">
  <h2><?= tt('bom.changes') ?> <span class="hint" style="font-weight:400;">(<?= count($changes) ?>)</span></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('bom.line') ?></th><th><?= tt('bom.change_kind') ?></th><th><?= tt('bom.proposal') ?></th><th><?= tt('bom.reason') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('prq.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($changes as $c): ?>
    <tr>
      <td><?= $c['line_id'] ? '#' . (int)$c['line_seq'] . ' · ' . e(mb_substr((string)$c['line_desc'], 0, 60)) . ' × ' . stk_qty((float)$c['line_qty']) : '<span class="hint">' . tt('bom.k_add') . '</span>' ?></td>
      <td><?= tt('bom.k_' . $c['kind']) ?></td>
      <td><?php if ($c['kind'] === 'qty'): ?><b><?= stk_qty((float)$c['qty']) ?></b><?php elseif ($c['kind'] === 'remove'): ?>—<?php else: ?><?= $c['alt_code'] ? '<span style="font-family:var(--mono);">' . e($c['alt_code']) . '</span> ' : '' ?><?= e($c['alt_desc'] ?: $c['alt_description'] ?: '') ?><?= $c['alt_maker'] || $c['alt_part_no'] ? '<br><small class="hint">' . e(trim(($c['alt_maker'] ?? '') . ' ' . ($c['alt_part_no'] ?? ''))) . '</small>' : '' ?><?= $c['kind'] === 'add' ? ' × ' . stk_qty((float)$c['qty']) : '' ?><?php endif; ?></td>
      <td><?= e($c['reason']) ?><?php if ($c['decision_note']): ?><br><small class="hint">↳ <?= e($c['decision_note']) ?></small><?php endif; ?></td>
      <td><?= e($c['proposed_name'] ?? '') ?><?= $c['decided_name'] ? '<br><small class="hint">' . e($c['decided_name']) . ' · ' . fmt_datetime($c['decided_at']) . '</small>' : '' ?></td>
      <td><?= bom_c_pill($c['status']) ?></td>
      <td class="num" style="white-space:nowrap;">
        <?php if ($c['status'] === 'proposed' && $b['status'] === 'issued' && $eng): ?>
          <form method="post" action="<?= url('bom', ['id' => $id]) ?>" style="display:inline-flex;gap:4px;align-items:center;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_change_decide"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="change_id" value="<?= (int)$c['id'] ?>"><input name="note" maxlength="400" placeholder="<?= tt('prq.reason') ?>" style="width:11em;"><button class="btn btn--sm" type="submit" name="decision" value="accept">✓ <?= tt('bom.accept') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="decision" value="reject">✕ <?= tt('bom.reject_c') ?></button></form>
        <?php elseif ($c['status'] === 'proposed' && $proc): ?>
          <form method="post" action="<?= url('bom', ['id' => $id]) ?>" style="display:inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="bom_change_withdraw"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="change_id" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('bom.withdraw_c') ?></button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('prq.log') ?></h2>
  <table class="tbl tbl--wrap"><thead><tr><th><?= tt('prq.when') ?></th><th><?= tt('prq.who') ?></th><th><?= tt('prq.event') ?></th><th><?= tt('prq.note') ?></th></tr></thead><tbody>
  <?php foreach ($events as $ev): ?><tr><td><?= fmt_datetime($ev['created_at']) ?></td><td><?= e($ev['user_name'] ?? '') ?></td><td><?= tt('bom.ev_' . $ev['event']) ?></td><td><?= e($ev['note'] ?? '') ?></td></tr><?php endforeach; ?>
  </tbody></table>
</div>
<script>
(function(){
  var url=<?= json_encode(url('prq_items')) ?>, FREE=<?= json_encode(t('prq.free')) ?>;
  function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
  function picker(q,list,ch,item,desc,mk,pn,un){
    if(!q)return; var t=null;
    function choose(it){item.value=it.id;desc.value=it.desc||'';mk.value=it.maker||'';pn.value=it.pn||'';if(un)un.value=it.unit||'pcs';q.value=it.code;ch.textContent=it.code+' · '+(it.desc||'')+' · '+FREE+' '+it.free;list.classList.add('is-hidden');mk.readOnly=pn.readOnly=true;}
    function clear(){item.value='';ch.textContent='';mk.readOnly=pn.readOnly=false;}
    q.addEventListener('input',function(){clear();var v=q.value.trim();clearTimeout(t);if(v.length<2){list.classList.add('is-hidden');return;}t=setTimeout(function(){fetch(url+'&q='+encodeURIComponent(v),{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(rows){list.innerHTML='';if(!rows.length){list.classList.add('is-hidden');return;}rows.forEach(function(it){var d=document.createElement('div');d.className='prq-opt';d.innerHTML='<b>'+esc(it.code)+'</b> '+esc(it.desc)+' <small>'+esc(it.maker||'')+' '+esc(it.pn||'')+' · '+FREE+' '+it.free+' '+esc(it.unit)+(it.price!=null?' · '+it.price+' '+esc(it.cur):'')+'</small>';d.addEventListener('click',function(){choose(it);});list.appendChild(d);});list.classList.remove('is-hidden');}).catch(function(){});},220);});
    document.addEventListener('click',function(e){if(!list.contains(e.target)&&e.target!==q)list.classList.add('is-hidden');});
    return {clear:clear};
  }
  var $=function(i){return document.getElementById(i);};
  var lp=picker($('lf-q'),$('lf-list'),$('lf-chosen'),$('lf-item'),$('lf-desc'),$('lf-maker'),$('lf-pn'),$('lf-unit'));
  if(lp){document.querySelectorAll('[data-edit-line]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.getAttribute('data-edit-line'));$('lf-id').value=d.id;$('lf-item').value=d.item_id||'';$('lf-q').value=d.code||'';$('lf-desc').value=d.description||'';$('lf-maker').value=d.maker||'';$('lf-pn').value=d.part_no||'';$('lf-unit').value=d.unit||'pcs';$('lf-qty').value=d.qty;$('lf-section').value=d.section||'';$('lf-note').value=d.note||'';$('lf-chosen').textContent=d.code||'';$('lf-maker').readOnly=$('lf-pn').readOnly=!!d.item_id;$('lf-btn').textContent=<?= json_encode(t('prq.save_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.edit_line')) ?>+' #'+d.id;$('line-form').scrollIntoView({behavior:'smooth',block:'center'});});});
    $('lf-reset').addEventListener('click',function(){$('line-form').reset();$('lf-id').value='0';lp.clear();$('lf-q').value='';$('lf-btn').textContent=<?= json_encode(t('prq.add_line')) ?>;$('line-form-title').textContent=<?= json_encode(t('prq.add_line')) ?>;});}
  var pp=picker($('pf-q'),$('pf-list'),$('pf-chosen'),$('pf-item'),$('pf-desc'),$('pf-maker'),$('pf-pn'),null);
  if(pp){var kind=$('pf-kind');function sync(){var k=kind.value;document.querySelectorAll('.pf-alt').forEach(function(e){e.classList.toggle('is-hidden',!(k==='alternative'||k==='add'));});document.querySelectorAll('.pf-qty').forEach(function(e){e.classList.toggle('is-hidden',!(k==='qty'||k==='add'));});$('pf-line').disabled=k==='add';}kind.addEventListener('change',sync);sync();
    document.querySelectorAll('[data-propose]').forEach(function(b){b.addEventListener('click',function(){var d=JSON.parse(b.getAttribute('data-propose'));$('pf-line').value=d.id;$('pf-qty').value=d.qty;$('propose').scrollIntoView({behavior:'smooth',block:'center'});});});}
})();
</script>
<?php layout_foot();

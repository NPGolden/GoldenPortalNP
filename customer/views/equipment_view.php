<?php
/** One serial number: data, linked units, service history from imports, service reports and manual records.   GAS-CP-R27-001 */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$id = (int)($_GET['id'] ?? 0); $e = eq_get($id);
if (!$e) { http_response_code(404); layout_head(t('err.notfound'), 'equipment'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$src = in_array($_GET['src'] ?? '', ['import', 'manual', 'fsr', 'ticket', 'isr', 'task'], true) ? $_GET['src'] : null;
$hist = eq_history($id, $src); $all = $src ? eq_history($id) : $hist;
$counts = ['' => count($all)]; foreach (['fsr', 'isr', 'task', 'import', 'manual', 'ticket'] as $s) $counts[$s] = count(array_filter($all, fn($h) => $h['source'] === $s));
$parts = array_values(array_filter($all, fn($h) => !empty($h['parts'])));
$set = $e['set_code'] ? all('SELECT id, serial_no FROM equipment WHERE maker = ? AND set_code = ? AND id <> ? ORDER BY serial_no', [$e['maker'], $e['set_code'], $id]) : [];
$hk = eq_hkinds(); $editH = (int)($_GET['hist'] ?? 0); $eh = $editH ? one("SELECT * FROM equipment_history WHERE id = ? AND equipment_id = ? AND source IN ('manual','import')", [$editH, $id]) : null;
$warrOn = $e['warranty_until'] && $e['warranty_until'] >= date('Y-m-d');
$links = eq_links(); $lc = $links ? eq_contacts($id) : []; $tasks = $links ? eq_tasks($id) : []; $isrs = $links ? eq_isrs($id) : []; 
layout_head(t('eq.sn') . ' ' . $e['serial_no'], 'equipment');
?>
<p class="eyebrow"><a href="<?= url('equipment') ?>"><?= tt('eq.title') ?></a> › <?= e($e['serial_no']) ?><?= $e['vessel_id'] ? ' › <a href="' . url('vessel', ['id' => (int)$e['vessel_id'], 'tab' => 'equipment']) . '">' . e($e['vessel']) . '</a>' : '' ?></p>
<div class="page-head">
  <div><h1><?= tt('eq.sn') ?> <span style="font-family:var(--mono);color:var(--gold-text);"><?= e($e['serial_no']) ?></span> <?= pill('navy', trim($e['maker'] . ' ' . ($e['family'] ?? ''))) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(t('eq.k_' . $e['kind'])) ?><?= $e['model'] ? ' · ' . e($e['model']) : '' ?> · <?= $e['vessel_id'] ? e($e['vessel']) : e($e['site_name'] ?: '—') ?> · <?= $e['retired'] ? pill('mute', t('eq.st_retired')) : pill('green', t('eq.st_active')) ?><?= $warrOn ? ' ' . pill('gold', t('eq.under_warranty')) : '' ?></p></div>
  <div class="actions"><?php if ($staff): ?><a class="btn btn--gold" href="#hist-form">+ <?= tt('eq.add_record') ?></a><a class="btn btn--ghost" href="<?= url('equipment_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?><a class="btn btn--ghost" href="<?= url('equipment_doc', ['id' => $id, 'fmt' => 'pdf']) ?>">⤓ <?= tt('eq.print_pdf') ?></a><a class="btn btn--ghost" href="<?= url('equipment_doc', ['id' => $id, 'fmt' => 'docx']) ?>">⤓ <?= tt('eq.print_docx') ?></a></div>
</div>
<div class="grid grid-2">
  <div class="card">
    <h2><?= tt('eq.data') ?></h2>
    <table class="kv"><tbody>
      <tr><th><?= tt('eq.sn') ?></th><td><?= e($e['serial_no']) ?><?= $e['set_code'] ? ' <span class="hint">(' . e($e['set_code']) . ')</span>' : '' ?></td></tr>
      <tr><th><?= tt('eq.maker') ?> · <?= tt('eq.family') ?></th><td><?= e(trim($e['maker'] . ' · ' . ($e['family'] ?: '—'), ' ·')) ?></td></tr>
      <tr><th><?= tt('eq.model') ?></th><td><?= e($e['model'] ?: '—') ?></td></tr>
      <tr><th><?= tt('th.vessel') ?></th><td><?= $e['vessel_id'] ? '<a href="' . url('vessel', ['id' => (int)$e['vessel_id'], 'tab' => 'equipment']) . '">' . e($e['vessel']) . '</a>' . ($e['customer_name'] ? ' <span class="hint">· ' . e($e['customer_name']) . '</span>' : '') : e($e['site_name'] ?: '—') ?></td></tr>
      <tr><th><?= tt('eq.sold_by_us') ?></th><td><?= $e['sold_by_us'] ? tt('eq.yes') . ($e['date_sold'] ? ' · ' . e(fmt_date($e['date_sold'])) : '') : tt('eq.service_only') ?></td></tr>
      <tr><th><?= tt('eq.warranty') ?></th><td><?= $e['warranty_until'] ? ($warrOn ? pill('gold', t('eq.expires', ['d' => fmt_date($e['warranty_until'])])) : e(t('eq.expired', ['d' => fmt_date($e['warranty_until'])]))) : '—' ?><?= $e['warranty_ref'] ? ' <span class="hint">· ' . e($e['warranty_ref']) . '</span>' : '' ?></td></tr>
      <tr><th><?= tt('eq.first_attendance') ?></th><td><?= $e['first_attendance'] ? e(fmt_date($e['first_attendance'])) : '—' ?></td></tr>
      <tr><th><?= tt('eq.last_service') ?></th><td><?= $e['last_service'] ? e(fmt_date($e['last_service'])) : '—' ?> <span class="hint">· <?= e(t('eq.n_records', ['n' => (int)$e['n_hist']])) ?></span></td></tr>
      <?php if ($e['retired']): ?><tr><th><?= tt('eq.retired') ?></th><td><?= $e['retired_at'] ? e(fmt_date($e['retired_at'])) : tt('eq.yes') ?></td></tr><?php endif; ?>
      <?php if ($staff && $e['contacts']): ?><tr><th><?= tt('eq.contacts') ?></th><td><?= rt_html($e['contacts']) ?></td></tr><?php endif; ?>
      <?php if ($staff && $e['notes']): ?><tr><th><?= tt('eq.notes') ?></th><td><?= rt_html($e['notes']) ?></td></tr><?php endif; ?>
    </tbody></table>
    <?php if ($links): ?>
    <h3 class="ac-h"><?= tt('eq.linked_contacts') ?> <span class="hint" style="font-weight:400;">(<?= count($lc) ?>)</span></h3>
    <?php if (!$lc): ?><p class="hint"><?= tt('eq.no_contacts') ?></p><?php else: ?>
    <ul class="eq-ct"><?php foreach ($lc as $c): ?><li><a href="<?= url('contact', ['id' => (int)$c['id']]) ?>"><b><?= e($c['full_name']) ?></b></a><?= ($c['role'] ?: $c['position']) ? ' <span class="hint">· ' . e($c['role'] ?: $c['position']) . '</span>' : '' ?><?= $c['company_name'] ? ' <span class="hint">· ' . e($c['company_name']) . '</span>' : '' ?>
      <?php foreach (array_filter([$c['mobile'], $c['phone']]) as $ph): ?> · <a href="tel:<?= e(preg_replace('/[^+\d]/', '', $ph)) ?>"><?= e($ph) ?></a><?php endforeach; ?><?= $c['email'] ? ' · <a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '' ?>
      <?php if ($staff): ?><form method="post" action="<?= url('equipment_view', ['id' => $id]) ?>" style="display:inline;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eq_contact_remove"><input type="hidden" name="eq" value="<?= $id ?>"><input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-text" type="submit" title="<?= tt('btn.delete') ?>">✕</button></form><?php endif; ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($staff): ?>
    <button class="btn btn--ghost btn--sm" type="button" id="eq-ct-open">+ <?= tt('eq.ct_link') ?></button>
    <dialog class="gas-confirm eq-ct-dlg" id="eq-ct-dlg" aria-labelledby="eq-ct-h">
      <form method="post" action="<?= url('equipment_view', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eq_contact_add"><input type="hidden" name="eq" value="<?= $id ?>">
        <h2 id="eq-ct-h"><?= tt('eq.ct_link') ?> — <?= e($e['serial_no']) ?></h2>
        <div class="eq-ct-flt">
          <input type="search" id="eqct-q" placeholder="<?= tt('ct.search_ph') ?>" autocomplete="off">
          <select id="eqct-customer"><option value=""><?= tt('rp.customer') ?>: <?= tt('flt.any') ?></option><?php foreach (all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name') as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === (int)($e['customer_id'] ?? 0) ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
          <select id="eqct-cat"><option value=""><?= tt('ct.category') ?>: <?= tt('flt.any') ?></option><?php foreach (ct_categories() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select>
          <?php if ($e['vessel_id']): ?><label class="chk"><input type="checkbox" id="eqct-vessel"> <?= tt('eq.ct_only_vessel') ?></label><?php endif; ?>
        </div>
        <div class="eq-ct-list" id="eqct-list" aria-live="polite"></div>
        <div class="eq-ct-foot">
          <span class="hint" id="eqct-count"></span>
          <input name="role" maxlength="80" placeholder="<?= tt('eq.ct_role_ph') ?>">
          <button class="btn btn--ghost" type="button" value="cancel" id="eqct-cancel"><?= tt('btn.cancel') ?></button>
          <button class="btn btn--gold" type="submit" id="eqct-ok" disabled><?= tt('eq.ct_link_n', ['n' => '0']) ?></button>
        </div>
      </form>
    </dialog>
    <script nonce="<?= csp_nonce() ?>">
    (function(){
      var dlg=document.getElementById('eq-ct-dlg'), open=document.getElementById('eq-ct-open'); if(!dlg||!open) return;
      var q=document.getElementById('eqct-q'), cu=document.getElementById('eqct-customer'), ca=document.getElementById('eqct-cat'), ve=document.getElementById('eqct-vessel'), list=document.getElementById('eqct-list'), cnt=document.getElementById('eqct-count'), ok=document.getElementById('eqct-ok');
      var sel={}, page=1, t=null, L=<?= json_encode(['n' => t('eq.ct_link_n', ['n' => '{n}']), 'none' => t('ct.none'), 'linked' => t('eq.ct_already'), 'more' => t('btn.next'), 'of' => t('eq.ct_count', ['n' => '{n}', 't' => '{t}'])], JSON_UNESCAPED_UNICODE) ?>;
      function esc(s){ return String(s).replace(/[&<>"]/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
      function upd(){ var n=Object.keys(sel).length; ok.disabled=!n; ok.textContent=L.n.replace('{n}', n); }
      function load(p){ page=p||1; var u='<?= url('eq_contact_search', ['eq' => $id]) ?>&page='+page+'&q='+encodeURIComponent(q.value)+'&customer='+encodeURIComponent(cu.value)+'&category='+encodeURIComponent(ca.value)+(ve&&ve.checked?'&vessel=1':'');
        fetch(u,{credentials:'same-origin'}).then(function(r){return r.json();}).then(function(d){
          if(!d.rows.length){ list.innerHTML='<p class="hint">'+esc(L.none)+'</p>'; cnt.textContent=''; return; }
          list.innerHTML=d.rows.map(function(c){ var dis=c.linked; return '<label class="eq-ct-row'+(dis?' is-linked':'')+'"><input type="checkbox" name="contact_ids[]" value="'+c.id+'"'+(dis?' disabled':(sel[c.id]?' checked':''))+'> <b>'+esc(c.name)+'</b>'+(c.position?' <span class="hint">· '+esc(c.position)+'</span>':'')+(c.company?' <span class="hint">· '+esc(c.company)+'</span>':'')+'<br><small>'+esc(c.category)+(c.customer?' · '+esc(c.customer):'')+(c.vessels?' · '+esc(c.vessels):'')+(c.phone?' · '+esc(c.phone):'')+(c.email?' · '+esc(c.email):'')+(dis?' · '+esc(L.linked):'')+'</small></label>'; }).join('')
            +(d.pages>1?'<div class="eq-ct-pg">'+(d.page>1?'<button type="button" class="btn btn--ghost btn--sm" data-p="'+(d.page-1)+'">‹</button>':'')+' '+d.page+' / '+d.pages+' '+(d.page<d.pages?'<button type="button" class="btn btn--ghost btn--sm" data-p="'+(d.page+1)+'">›</button>':'')+'</div>':'');
          cnt.textContent=L.of.replace('{n}', d.rows.length).replace('{t}', d.total);
        }); }
      list.addEventListener('change', function(ev){ var i=ev.target; if(i.name==='contact_ids[]'){ if(i.checked) sel[i.value]=1; else delete sel[i.value]; upd(); } });
      list.addEventListener('click', function(ev){ var b=ev.target.closest('button[data-p]'); if(b) load(+b.getAttribute('data-p')); });
      [q].forEach(function(el){ el.addEventListener('input', function(){ clearTimeout(t); t=setTimeout(function(){ load(1); }, 250); }); });
      [cu,ca,ve].forEach(function(el){ if(el) el.addEventListener('change', function(){ load(1); }); });
      open.addEventListener('click', function(){ sel={}; upd(); dlg.showModal(); load(1); q.focus(); });
      document.getElementById('eqct-cancel').addEventListener('click', function(){ dlg.close(); });
      // ticked contacts of other pages are posted too
      dlg.querySelector('form').addEventListener('submit', function(){ Object.keys(sel).forEach(function(id){ if(!list.querySelector('input[value="'+id+'"]:checked')){ var h=document.createElement('input'); h.type='hidden'; h.name='contact_ids[]'; h.value=id; this.appendChild(h); } }, this); });
    })();
    </script>
    <?php endif; endif; ?>
    <?php if ($set): ?><h3 class="ac-h"><?= tt('eq.set_units') ?></h3><p><?php foreach ($set as $s): ?><a class="btn btn--ghost btn--sm" href="<?= url('equipment_view', ['id' => (int)$s['id']]) ?>"><?= e($s['serial_no']) ?></a> <?php endforeach; ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('eq.parts_replaced') ?> <span class="hint" style="font-weight:400;">(<?= count($parts) ?>)</span></h2>
    <?php if (!$parts): ?><p class="hint"><?= tt('eq.no_parts') ?></p><?php else: ?>
    <div class="table-scroll"><table class="tbl--compact"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('fsr.parts') ?></th><th><?= tt('eq.source') ?></th></tr></thead><tbody>
      <?php foreach (array_slice($parts, 0, 25) as $h): ?><tr><td class="date"><?= $h['hist_date'] ? e(fmt_date($h['hist_date'])) : '—' ?></td><td><?= e($h['parts']) ?></td><td><?= $h['report_no'] ? '<a href="' . url('fsr', ['id' => (int)$h['fsr_id']]) . '">' . e($h['report_no']) . '</a>' : e(t('eq.src_' . $h['source'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <?php if ($links && ($staff || $isrs)): ?>
    <h3 class="ac-h"><?= tt('isr.plural') ?> <span class="hint" style="font-weight:400;">(<?= count($isrs) ?>)</span></h3>
    <?php if (!$isrs): ?><p class="hint"><?= tt('eq.no_isr') ?></p><?php else: ?><ul class="eq-ct"><?php foreach ($isrs as $r): ?><li><a href="<?= url('isr', ['id' => (int)$r['id']]) ?>"><b><?= e($r['report_no']) ?></b></a> · <?= e(fmt_date($r['report_date'])) ?> <?= isr_status_pill($r['status']) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>
    <?php if ($links && $staff): ?>
    <h3 class="ac-h"><?= tt('td.title') ?> <span class="hint" style="font-weight:400;">(<?= count($tasks) ?>)</span> <a class="btn btn--ghost btn--sm" style="margin-left:8px;" href="<?= url('task_edit', ['eq' => $id]) ?>">+ <?= tt('td.new') ?></a></h3>
    <?php if (!$tasks): ?><p class="hint"><?= tt('eq.no_tasks') ?></p><?php else: ?><ul class="eq-ct"><?php foreach ($tasks as $tk): ?><li><a href="<?= url('task_edit', ['id' => (int)$tk['id']]) ?>"><b><?= e($tk['title']) ?></b></a> · <?= e(fmt_date(substr($tk['due_at'], 0, 10))) ?><?= $tk['assignee'] ? ' · ' . e($tk['assignee']) : '' ?> <?= pill($tk['status'] === 'open' ? 'gold' : ($tk['status'] === 'done' ? 'green' : 'mute'), t('eq.ts_' . $tk['status'])) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($staff && function_exists('pd_card') && ($pc = pd_card($id))): $pu = $pc['unit']; $pG = pd_files_by_phase($pc['unit_files']); $pms = pd_milestones($pc); ?>
<div class="card" id="production">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">🏭 <?= tt('pd.card') ?></h2>
    <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('production_order', ['id' => (int)$pc['id']]) ?>"><?= e($pc['po_no']) ?> ›</a><a class="btn btn--ghost btn--sm" href="<?= url('production_pdf', ['id' => (int)$pc['id']]) ?>" target="_blank">⤓ PDF</a></div></div>
  <div class="pd-ms pd-ms--sm"><?php foreach (PD_OPEN as $i => $st): ?><div class="pd-ms__step pd-ms__step--<?= $pms[$st] ?>"><span class="pd-ms__dot"><?= $pms[$st] === 'done' ? '✓' : $i + 1 ?></span><span><?= e(pd_status_label($st)) ?></span></div><?php endforeach; ?></div>
  <table class="kv"><tbody>
    <tr><th><?= tt('pd.order') ?></th><td><a href="<?= url('production_order', ['id' => (int)$pc['id']]) ?>"><b><?= e($pc['po_no']) ?></b></a> <?= e($pc['title']) ?> <?= pd_status_pill($pc['status']) ?><?= $pc['wp_no'] ? ' <span class="hint">· ' . e($pc['wp_no']) . '</span>' : '' ?></td></tr>
    <tr><th><?= tt('pd.unit_status') ?></th><td><?= pd_ustatus_pill($pu['status']) ?> <b><?= (int)$pu['pct'] ?> %</b> <?= pd_pct_bar((int)$pu['pct']) ?><?= $pu['note'] ? '<div class="hint">' . e($pu['note']) . '</div>' : '' ?><span class="hint"><?= tt('pd.updated') ?> <?= e(fmt_datetime($pu['updated_at'])) ?></span></td></tr>
    <tr><th><?= tt('pd.manager') ?></th><td><?= e($pc['manager'] ?: '—') ?><?= $pc['project_manager'] ? ' · PM ' . e($pc['project_manager']) : '' ?></td></tr>
    <tr><th><?= tt('pd.planned') ?></th><td><?= $pc['planned_start'] ? e(fmt_date($pc['planned_start'])) : '…' ?> – <?= $pc['planned_end'] ? e(fmt_date($pc['planned_end'])) : '…' ?><?= $pc['delivered_at'] ? ' · ' . tt('pd.delivered_on') . ' ' . e(fmt_date($pc['delivered_at'])) : '' ?></td></tr>
    <tr><th><?= tt('pd.documents') ?></th><td><?php $any = false; foreach ($pG as $ph => $fs): if (!$fs) continue; $any = true; ?><div><b><?= e(pd_phase_label($ph)) ?>:</b> <?php foreach ($fs as $f): ?><a href="<?= url('file', ['id' => (int)$f['file_id']]) ?>" target="_blank"><?= e($f['note'] ?: $f['original_name']) ?></a><?= $f['doc_no'] || $f['revision'] ? ' <span class="mono hint">' . e(trim((string)$f['doc_no'] . ' ' . (string)$f['revision'])) . '</span>' : '' ?> · <?php endforeach; ?></div><?php endforeach; if (!$any) echo '<span class="hint">—</span>'; ?></td></tr>
  </tbody></table>
  <?php $phs = array_filter($pG['photo'], 'pd_is_image'); if ($phs): ?><div class="fsr-photos" style="margin-top:8px;"><?php foreach (array_slice($phs, 0, 12) as $f): ?><figure><a href="<?= url('file', ['id' => (int)$f['file_id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => (int)$f['file_id']]) ?>" alt="" loading="lazy"></a><figcaption><span><?= e($f['note'] ?: fmt_date(substr((string)$f['uploaded_at'], 0, 10))) ?></span></figcaption></figure><?php endforeach; ?></div><?php endif; ?>
</div>
<?php endif; ?>

<div class="card">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;"><?= tt('eq.history') ?> <span class="hint" style="font-weight:400;">(<?= count($all) ?>)</span></h2></div>
  <nav class="tabs" aria-label="<?= tt('eq.history') ?>">
    <?php foreach (['' => t('flt.all'), 'fsr' => t('fsr.plural'), 'isr' => t('isr.plural'), 'task' => t('td.title'), 'import' => t('eq.src_import'), 'manual' => t('eq.src_manual'), 'ticket' => t('tk.title')] as $k => $l): if ($k !== '' && !$counts[$k]) continue; ?><a class="<?= ($src ?? '') === $k ? 'is-on' : '' ?>" href="<?= url('equipment_view', array_filter(['id' => $id, 'src' => $k])) ?>"><?= e($l) ?> <span class="n"><?= $counts[$k] ?></span></a><?php endforeach; ?>
  </nav>
  <?php if (!$hist): ?><p class="hint"><?= tt('eq.no_history') ?></p><?php else: ?>
  <ul class="eq-tl">
    <?php foreach ($hist as $h): ?>
      <li><span class="eq-d"><?= $h['hist_date'] ? e(fmt_date($h['hist_date'])) : '<span class="hint">' . tt('eq.undated') . '</span>' ?></span>
        <span><?= eq_hpill($h['kind']) ?> <span><?= rt_html($h['body']) ?></span>
          <div class="eq-src"><?= $h['source'] === 'fsr' ? '📋 ' . ($h['report_no'] ? '<a href="' . url('fsr', ['id' => (int)$h['fsr_id']]) . '">' . e($h['report_no']) . '</a>' : tt('fsr.one')) : ($h['source'] === 'isr' ? '🔍 ' . ($h['isr_no'] ? '<a href="' . url('isr', ['id' => (int)$h['isr_id']]) . '">' . e($h['isr_no']) . '</a>' : tt('isr.title')) : ($h['source'] === 'task' ? '✅ ' . ($staff && $h['task_id'] ? '<a href="' . url('task_edit', ['id' => (int)$h['task_id']]) . '">' . e($h['task_title'] ?? t('td.title')) . '</a>' : tt('td.title')) : ($h['source'] === 'ticket' ? '🎫 ' . e($h['ticket_no'] ?? '') : ($h['source'] === 'import' ? '🗂 ' . tt('eq.src_import') : '✎ ' . tt('eq.src_manual'))))) ?><?= $h['engineer'] ? ' · ' . e($h['engineer']) : '' ?><?= $h['parts'] ? ' · ' . tt('fsr.parts') . ': ' . e($h['parts']) : '' ?>
            <?php if ($staff && in_array($h['source'], ['manual', 'import'], true)): ?> · <a href="<?= url('equipment_view', ['id' => $id, 'hist' => (int)$h['id']]) ?>#hist-form"><?= tt('btn.edit') ?></a>
              <form method="post" action="<?= url('equipment_view', ['id' => $id]) ?>" style="display:inline;" data-lock data-confirm="<?= tt('eq.hist_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="eq_hist_delete"><input type="hidden" name="id" value="<?= (int)$h['id'] ?>"><input type="hidden" name="eq" value="<?= $id ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">✕</button></form><?php endif; ?></div></span></li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</div>

<?php if ($staff): ?>
<div class="card" id="hist-form">
  <h2><?= $eh ? tt('eq.edit_record') : tt('eq.add_record') ?></h2>
  <form method="post" action="<?= url('equipment_view', ['id' => $id]) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="eq_hist_save"><input type="hidden" name="eq" value="<?= $id ?>"><input type="hidden" name="hist_id" value="<?= (int)($eh['id'] ?? 0) ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('th.date') ?></label><input type="date" name="hist_date" value="<?= e($eh['hist_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('eq.hkind') ?></label><select name="hkind"><?php foreach ($hk as $k => $l): ?><option value="<?= $k ?>"<?= ($eh['kind'] ?? '') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('fsr.engineer') ?></label><input name="engineer" maxlength="160" value="<?= e($eh['engineer'] ?? $u['full_name']) ?>"></div>
    </div>
    <div class="field"><label><?= tt('eq.record_text') ?></label><textarea name="body" rows="4" required placeholder="<?= tt('eq.record_ph') ?>"><?= e($eh['body'] ?? '') ?></textarea></div>
    <div class="field"><label><?= tt('fsr.parts') ?></label><input name="parts" maxlength="500" value="<?= e($eh['parts'] ?? '') ?>" placeholder="<?= tt('eq.parts_ph') ?>"></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($eh): ?><a class="btn btn--ghost" href="<?= url('equipment_view', ['id' => $id]) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?><span class="hint"><?= tt('eq.record_hint') ?></span></div>
  </form>
</div>
<?php $adAction = 'eq_delete'; $adId = $id; $adBack = 'equipment'; $adConfirm = t('eq.delete_confirm', ['sn' => $e['serial_no']]); $adNote = t('eq.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php endif; ?>
<?php layout_foot(); ?>

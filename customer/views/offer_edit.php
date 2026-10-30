<?php
/** Record an offer sent to the owner, or start one to build in the portal (Golden A/S staff).   GAS-CP-R15-001 · R33-001
 *   ?p=offer_edit[&project=ID][&sub=ID][&vessel=ID][&ticket=ID]   new      ?p=offer_edit&id=ID   edit */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!of_has()) redirect('offers');
$id = (int)($_GET['id'] ?? 0);
$o = $id ? of_load($id) : null;
if ($id && !$o) { http_response_code(404); exit(t('err.notfound')); }
$ob = function_exists('ob_has') && ob_has(); $build = $ob && ob_can();
$projects = all('SELECT p.id, p.project_code, p.name, p.vessel_id, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id ORDER BY p.status = "completed", p.project_code, p.name');
$subs = all('SELECT id, project_id, code, name FROM subprojects ORDER BY sort_order, code');
$vessels = $ob ? all('SELECT v.id, v.name, c.name AS customer FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.is_active = 1' . (function_exists('vm_has') && vm_has() ? ' AND v.merged_into IS NULL' : '') . ' ORDER BY c.name, v.name') : [];
$curP = $o ? (int)$o['project_id'] : (int)($_GET['project'] ?? 0);
$curS = $o ? (int)($o['subproject_id'] ?? 0) : (int)($_GET['sub'] ?? 0);
if ($curS && !$curP) foreach ($subs as $s) if ((int)$s['id'] === $curS) $curP = (int)$s['project_id'];
$curV = $o ? (int)($o['vessel_id'] ?? 0) : (int)($_GET['vessel'] ?? 0);
$ticket = null; $curT = $o ? (int)($o['ticket_id'] ?? 0) : (int)($_GET['ticket'] ?? 0);
if ($curT) { $ticket = one('SELECT id, ticket_no, title, vessel_id, project_id, subproject_id FROM tickets WHERE id = ?', [$curT]); if ($ticket && !$o) { $curV = $curV ?: (int)($ticket['vessel_id'] ?? 0); $curP = $curP ?: (int)($ticket['project_id'] ?? 0); $curS = $curS ?: (int)($ticket['subproject_id'] ?? 0); } }
if ($curP && !$curV) foreach ($projects as $p) if ((int)$p['id'] === $curP) $curV = (int)($p['vessel_id'] ?? 0);
$hasLines = $o && $ob ? (int) scalar('SELECT COUNT(*) FROM offer_lines WHERE offer_id = ? AND kind <> "text"', [$id]) : 0;
$v = fn(string $k, $d = '') => e((string)($o[$k] ?? $d));
$title = $o ? t('of.edit') . ' ' . $o['offer_no'] : t('of.new');
layout_head($title, 'offers');
?>
<p class="eyebrow"><a href="<?= url('offers') ?>"><?= tt('of.title') ?></a></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= tt($build ? 'ob.lede_form' : 'of.lede_form') ?></p>
<div class="card" style="max-width:900px;">
  <form method="post" action="<?= url('offer_edit', $id ? ['id' => $id] : []) ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="of_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="ticket_id" value="<?= $curT ?>">
    <?php if ($ticket): ?><p class="hint" style="margin:0 0 10px;">🎫 <?= tt('ob.linked_ticket') ?> <a href="<?= url('ticket', ['id' => $ticket['id']]) ?>"><b><?= e($ticket['ticket_no']) ?></b></a> — <?= e($ticket['title']) ?></p><?php endif; ?>
    <div class="grid grid-2">
      <?php if ($ob): ?>
      <div class="field"><label for="vessel_id"><?= tt('v.one') ?> *</label><select id="vessel_id" name="vessel_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($vessels as $vs): ?><option value="<?= $vs['id'] ?>"<?= $curV === (int)$vs['id'] ? ' selected' : '' ?>><?= e($vs['name'] . ($vs['customer'] ? ' · ' . $vs['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="project_id"><?= tt('pr.one') ?></label><select id="project_id" name="project_id" data-nosearch><option value="0"><?= tt('ob.vessel_general') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-v="<?= (int)($p['vessel_id'] ?? 0) ?>" data-code="<?= e($p['project_code'] ?: $p['name']) ?>"<?= $curP === (int)$p['id'] ? ' selected' : '' ?>><?= e(($p['project_code'] ?: '') . ' ' . $p['name']) ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('ob.vessel_general_hint') ?></p></div>
      <?php else: ?>
      <div class="field"><label for="project_id"><?= tt('pr.one') ?> *</label><select id="project_id" name="project_id" required><option value=""><?= tt('tk.choose') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>" data-code="<?= e($p['project_code'] ?: $p['name']) ?>"<?= $curP === (int)$p['id'] ? ' selected' : '' ?>><?= e(($p['project_code'] ?: '') . ' ' . $p['name'] . ($p['vessel'] ? ' · ' . $p['vessel'] : '')) ?></option><?php endforeach; ?></select></div>
      <?php endif; ?>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id" data-nosearch><option value="">—</option></select></div>
    </div>
    <div class="grid grid-3">
      <div class="field"><label for="offer_no"><?= tt('of.no') ?></label><input id="offer_no" name="offer_no" maxlength="60" value="<?= $v('offer_no') ?>" placeholder="GAS-OF-<?= date('Y') ?>-001"><p class="hint" style="margin:4px 0 0;"><?= tt('of.no_hint') ?></p></div>
      <div class="field"><label for="revision"><?= tt('sub.rev') ?></label><input id="revision" name="revision" maxlength="8" value="<?= $v('revision', 'R0') ?>"<?= $build && $o && $o['status'] === 'draft' ? ' readonly title="' . tt('ob.rev_auto') . '"' : '' ?>></div>
      <?php if (fin_visible()): ?><div class="field"><label for="amount"><?= tt('of.amount') ?><?= $hasLines ? '' : ' *' ?></label><div style="display:flex;gap:6px;"><input id="amount" name="amount" <?= $hasLines ? 'readonly' : 'required' ?> inputmode="decimal" value="<?= $o ? e(number_format((float)$o['amount'], 2, '.', '')) : '' ?>" placeholder="0.00" style="flex:1;"><input name="currency" maxlength="3" value="<?= $v('currency', 'EUR') ?>" style="width:64px;"></div><?php if ($hasLines): ?><p class="hint" style="margin:4px 0 0;"><?= tt('ob.amount_from_lines') ?></p><?php elseif ($build && !$o): ?><p class="hint" style="margin:4px 0 0;"><?= tt('ob.amount_hint') ?></p><?php endif; ?></div>
      <?php else: ?><div class="field"><label><?= tt('of.amount') ?></label><p class="hint" style="margin:10px 0 0;"><?= tt('fin.hidden_hint') ?></p></div><?php endif; ?>
    </div>
    <?php if (function_exists('pi_has') && pi_has()): $ocs = oc_all(true); ?>
    <div class="grid grid-3">
      <div class="field"><label for="kind"><?= tt('oc.kind') ?></label><select id="kind" name="kind" data-nosearch><?php foreach (OF_KINDS as $k): ?><option value="<?= $k ?>"<?= ($o['kind'] ?? 'firm') === $k ? ' selected' : '' ?>><?= e(t($k === 'firm' ? 'oc.firm_k' : 'oc.budgetary')) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('oc.kind_hint') ?></p></div>
      <div class="field" style="grid-column: span 2;"><label for="class_id"><?= tt('oc.class') ?></label><select id="class_id" name="class_id" data-nosearch><option value="">—</option><?php foreach ($ocs as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)($o['class_id'] ?? 0) === (int)$c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?><?= $c['accuracy'] ? ' · ' . e($c['accuracy']) : '' ?></option><?php endforeach; ?></select><p class="hint"><?= tt('oc.class_hint') ?></p></div>
    </div>
    <?php endif; ?>
    <div class="field"><label for="title"><?= tt('of.title_f') ?> *</label><input id="title" name="title" required maxlength="200" value="<?= $v('title', $ticket ? $ticket['title'] : '') ?>" placeholder="<?= tt('of.title_ph') ?>"></div>
    <?php if ($ob): ?>
    <div class="grid grid-3">
      <div class="field"><label for="layout"><?= tt('ob.layout') ?></label><select id="layout" name="layout" data-nosearch><?php foreach (OB_LAYOUTS as $l): ?><option value="<?= $l ?>"<?= ($o['layout'] ?? 'service') === $l ? ' selected' : '' ?>><?= e(ob_layout_label($l)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="vat_pct"><?= tt('ob.vat_pct') ?></label><input id="vat_pct" name="vat_pct" inputmode="decimal" value="<?= $o ? e(rtrim(rtrim(number_format((float)$o['vat_pct'], 2, '.', ''), '0'), '.')) : '0' ?>"></div>
      <div class="field"><label for="validity_days"><?= tt('ob.validity_days') ?></label><input id="validity_days" name="validity_days" type="number" min="1" max="365" value="<?= (int)($o['validity_days'] ?? 30) ?>"></div>
    </div>
    <?php endif; ?>
    <div class="grid grid-3">
      <?php if (function_exists('sl_has') && sl_has()): ?><div class="field"><label for="requested_at"><?= tt('sl.requested_at') ?> <span class="hint"><?= tt('sl.requested_hint') ?></span></label><input id="requested_at" name="requested_at" type="date" value="<?= $v('requested_at') ?>"></div><?php endif; ?>
      <div class="field"><label for="sent_at"><?= tt('of.sent') ?></label><input id="sent_at" name="sent_at" type="date" value="<?= $v('sent_at', date('Y-m-d')) ?>"></div>
      <div class="field"><label for="valid_until"><?= tt('of.valid_until') ?></label><input id="valid_until" name="valid_until" type="date" value="<?= $v('valid_until') ?>"></div>
      <div class="field"><label for="file"><?= tt('of.file') ?></label><input id="file" name="file" type="file" accept=".pdf,.docx,.xlsx"><?= $o && $o['original_name'] ? '<p class="hint" style="margin:4px 0 0;">' . e($o['original_name']) . '</p>' : ($build ? '<p class="hint" style="margin:4px 0 0;">' . tt('ob.file_hint') . '</p>' : '') ?></div>
    </div>
    <div class="field"><label for="notes"><?= tt('of.notes') ?></label><textarea id="notes" name="notes" rows="3"><?= $v('notes') ?></textarea></div>
    <?php if ($build && !$o): ?><label class="chk" style="margin-bottom:10px;"><input type="checkbox" name="as_draft" value="1" checked> <?= tt('ob.as_draft') ?></label><p class="hint" style="margin:-4px 0 12px;"><?= tt('ob.as_draft_hint') ?></p><?php endif; ?>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $o ? tt('btn.save') : ($build ? tt('ob.create') : tt('of.record')) ?></button><a class="btn btn--ghost" href="<?= $id ? url('offer', ['id' => $id]) : ($ticket ? url('ticket', ['id' => $ticket['id']]) : url('offers')) ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var S = <?= json_encode(array_map(fn($s) => ['id' => (int)$s['id'], 'p' => (int)$s['project_id'], 'c' => (string)$s['code'], 'l' => trim(($s['code'] ?: '') . ' ' . $s['name'])], $subs), JSON_UNESCAPED_UNICODE) ?>;
  var BR = <?= json_encode($projects ? array_combine(array_column($projects, 'id'), array_map(fn($pr) => br_offer_no((int)$pr['id']), $projects)) : new stdClass()) ?>, BR0 = <?= json_encode(br_offer_no(null)) ?>;   // R40-001: portal offer series for every branch
  var ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id'), no = document.getElementById('offer_no'), vs = document.getElementById('vessel_id'), cur = <?= $curS ?>, touched = no.value !== '';
  function f(){ var p = +ps.value; ss.innerHTML = '<option value="">—</option>'; S.filter(function(r){ return r.p === p; }).forEach(function(r){ var o = document.createElement('option'); o.value = r.id; o.textContent = r.l; o.dataset.code = r.c; if (r.id === cur) o.selected = true; ss.appendChild(o); }); }
  function sug(){ if (touched) return; var pc = ps.selectedOptions[0] ? ps.selectedOptions[0].dataset.code || '' : ''; var sc = ss.selectedOptions[0] ? ss.selectedOptions[0].dataset.code || '' : ''; no.value = BR[ps.value] ? BR[ps.value] : (BR0 || (pc + ' ' + sc).trim()); }
  function fv(){ if (!vs) return; var v = +vs.value; Array.prototype.forEach.call(ps.options, function(o){ if (!o.dataset.v) return; o.hidden = v && +o.dataset.v !== v; }); if (ps.selectedOptions[0] && ps.selectedOptions[0].hidden) { ps.value = '0'; cur = 0; f(); sug(); } }
  no.addEventListener('input', function(){ touched = no.value !== ''; }); ps.addEventListener('change', function(){ cur = 0; f(); }); ss.addEventListener('change', sug); ps.addEventListener('change', sug);
  if (vs) vs.addEventListener('change', fv);
  fv(); f(); sug();
})();
</script>
<?php layout_foot(); ?>

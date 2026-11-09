<?php
/**
 * Register a direct invoice — an accounting invoice issued for work that had no proforma (GAS-CP-R94-001).
 * Stored in the proforma module as kind = invoice, so collections, statement, payments and follow-ups treat it alike.
 *   ?p=invoice_new[&project=&sub=]
 */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('pi_kind_has') || !pi_kind_has() || !pi_can_invoices()) { http_response_code(403); layout_head(t('err.noaccess'), 'proformas'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$curP = (int)($_GET['project'] ?? 0); $curS = (int)($_GET['sub'] ?? 0);
$projects = all("SELECT p.id, p.project_code, p.name, p.vessel_id, p.customer_id, v.name AS vessel, c.name AS customer FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.status IN ('active','quoted','onhold') OR p.id = ? ORDER BY p.project_code DESC, p.name", [$curP]);
$subs = all('SELECT s.id, s.project_id, s.code, s.name, s.status FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE ' . sp_pick_cond('s', $curS) . " AND (p.status IN ('active','quoted','onhold') OR p.id = ?) ORDER BY SUBSTRING_INDEX(s.code,'.',1), CAST(SUBSTRING_INDEX(s.code,'.',-1) AS UNSIGNED), s.code", [$curP]);
$ivs = function_exists('iv_has') && iv_has() ? all("SELECT i.id, i.legal_name, i.vat_no, i.vat_pct, i.owner_type, i.owner_id FROM invoicing_details i WHERE i.is_active = 1 AND i.owner_type IN ('vessel','customer') ORDER BY i.legal_name") : [];
$vatDef = 24;
layout_head(t('pi.di_new'), 'proformas');
?>
<p class="eyebrow"><a href="<?= url('proformas') ?>"><?= tt('pi.title') ?></a> › <?= tt('pi.di_new') ?></p>
<div class="page-head"><div><h1>🧾 <?= tt('pi.di_new') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('pi.di_lede') ?></p></div></div>
<div class="card" style="max-width:980px;">
  <form method="post" action="<?= url('invoice_new') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pi_direct_create">
    <div class="grid grid-3">
      <div class="field"><label for="pi_no"><?= tt('pi.di_no') ?> *</label><input id="pi_no" name="pi_no" maxlength="60" required placeholder="<?= tt('pi.di_no_ph') ?>"></div>
      <div class="field"><label for="issue_date"><?= tt('pi.issue_date') ?> *</label><input id="issue_date" type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required></div>
      <div class="field"><label for="due_date"><?= tt('pi.due') ?></label><input id="due_date" type="date" name="due_date"></div>
    </div>
    <div class="grid grid-2">
      <div class="field"><label for="project_id"><?= tt('pr.one') ?> *</label><select id="project_id" name="project_id" required><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" data-v="<?= (int)$p['vessel_id'] ?>" data-c="<?= (int)$p['customer_id'] ?>"<?= $curP === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name']) . ($p['vessel'] ? ' · ' . $p['vessel'] : '') . ($p['customer'] ? ' · ' . $p['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $curS === (int)$s['id'] ? ' selected' : '' ?>><?= e(trim(($s['code'] ?: '') . ' ' . $s['name'])) ?><?= $s['status'] === 'completed' ? ' · ' . e(t('pr.completed')) : '' ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('pi.di_sub_hint') ?></p></div>
    </div>
    <div class="field"><label for="title"><?= tt('pi.description') ?></label><input id="title" name="title" maxlength="200" placeholder="<?= tt('pi.di_title_ph') ?>"></div>
    <div class="grid grid-4">
      <div class="field"><label for="amount_net"><?= tt('pi.net') ?> *</label><input id="amount_net" name="amount_net" inputmode="decimal" required placeholder="0,00"></div>
      <div class="field"><label for="vat_pct"><?= tt('pi.vat') ?> %</label><input id="vat_pct" name="vat_pct" inputmode="decimal" value="<?= e((string)$vatDef) ?>"></div>
      <div class="field"><label for="currency"><?= tt('pi.currency') ?></label><input id="currency" name="currency" maxlength="3" value="EUR"></div>
      <div class="field"><label><?= tt('pi.gross') ?></label><output id="gross" class="mono" style="display:block;padding:9px 0;font-weight:700;">—</output></div>
    </div>
    <?php if ($ivs): ?><div class="field"><label for="invoicing_id"><?= tt('iv.title') ?></label><select id="invoicing_id" name="invoicing_id"><option value="">—</option><?php foreach ($ivs as $i): ?><option value="<?= (int)$i['id'] ?>" data-v="<?= $i['owner_type'] === 'vessel' ? (int)$i['owner_id'] : 0 ?>" data-c="<?= $i['owner_type'] === 'customer' ? (int)$i['owner_id'] : 0 ?>" data-vat="<?= $i['vat_pct'] !== null ? e((string)(float)$i['vat_pct']) : '' ?>"><?= e($i['legal_name'] . ($i['vat_no'] ? ' · ' . $i['vat_no'] : '')) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="grid grid-2">
      <div class="field"><label for="file"><?= tt('pi.di_file') ?></label><input id="file" type="file" name="file" accept="application/pdf,image/*"></div>
      <div class="field"><label for="notes"><?= tt('pi.notes') ?></label><textarea id="notes" name="notes" rows="2" maxlength="2000"></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit">✔ <?= tt('pi.di_register') ?></button><a class="btn btn--ghost" href="<?= url('proformas') ?>"><?= tt('btn.cancel') ?></a></div>
    <p class="hint" style="margin:8px 0 0;"><?= tt('pi.di_hint') ?></p>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id'), iv = document.getElementById('invoicing_id');
  function narrow() { var p = ps.value; ss.querySelectorAll('option[data-p]').forEach(function (o) { o.hidden = !!p && o.getAttribute('data-p') !== p; }); var o = ss.selectedOptions[0]; if (o && o.value && p && o.getAttribute('data-p') !== p) ss.value = '';
    if (iv) { var sel = ps.selectedOptions[0], v = sel ? sel.getAttribute('data-v') : '0', c = sel ? sel.getAttribute('data-c') : '0';
      iv.querySelectorAll('option[data-v]').forEach(function (o) { o.hidden = !!p && !((v !== '0' && o.getAttribute('data-v') === v) || (c !== '0' && o.getAttribute('data-c') === c)); }); var cur = iv.selectedOptions[0]; if (cur && cur.value && cur.hidden) iv.value = ''; } }
  ps.addEventListener('change', narrow); narrow();
  if (iv) iv.addEventListener('change', function () { var o = iv.selectedOptions[0]; if (o && o.getAttribute('data-vat')) { document.getElementById('vat_pct').value = o.getAttribute('data-vat'); calc(); } });
  ss.addEventListener('change', function () { var o = ss.selectedOptions[0]; if (o && o.value && ps.value !== o.getAttribute('data-p')) { ps.value = o.getAttribute('data-p'); narrow(); } });
  var n = document.getElementById('amount_net'), v = document.getElementById('vat_pct'), g = document.getElementById('gross'), cu = document.getElementById('currency');
  function num(s) { s = String(s || '').trim(); if (s.indexOf(',') > -1 && s.indexOf('.') > -1) s = s.replace(/\./g, '').replace(',', '.'); else s = s.replace(',', '.'); var x = parseFloat(s); return isNaN(x) ? 0 : x; }
  function calc() { var a = num(n.value), p = num(v.value); g.textContent = a > 0 ? (a * (1 + p / 100)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (cu.value || 'EUR') : '—'; }
  [n, v, cu].forEach(function (el) { el.addEventListener('input', calc); }); calc();
})();
</script>
<?php layout_foot(); ?>

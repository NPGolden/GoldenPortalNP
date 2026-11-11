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
// R94-003 — prefill when raised from Accounting checks (gross still to invoice), an accounting report (net) or a service report
$preNet = (float) str_replace(',', '.', (string)($_GET['net'] ?? '')); $preGross = (float) str_replace(',', '.', (string)($_GET['gross'] ?? '')); $preTitle = mb_substr(trim((string)($_GET['title'] ?? '')), 0, 200);
$arId = (int)($_GET['acc'] ?? 0); $AR = $arId && function_exists('acc_load') ? acc_load($arId) : null; if ($AR && !in_array($AR['status'], ['issued', 'sent', 'partly_invoiced'], true)) $AR = null;
if ($AR) { $curP = $curP ?: (int)$AR['project_id']; $curS = $curS ?: (int)($AR['subproject_id'] ?? 0); if ($preNet <= 0) $preNet = (float)$AR['total_net']; if ($preTitle === '') $preTitle = t('pi.di_title_ar', ['no' => $AR['report_no'] . ' ' . $AR['revision']]); }
$fsrId = (int)($_GET['fsr'] ?? 0); $FS = $fsrId && function_exists('fsr_load') ? fsr_load($fsrId) : null;
if ($FS) { $curP = $curP ?: (int)$FS['project_id']; $curS = $curS ?: (int)($FS['subproject_id'] ?? 0); if ($preTitle === '') $preTitle = t('pi.di_title_fsr', ['no' => $FS['report_no']]); }
$projects = all("SELECT p.id, p.project_code, p.name, p.vessel_id, p.customer_id, v.name AS vessel, c.name AS customer FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id WHERE p.status IN ('active','quoted','onhold') OR p.id = ? ORDER BY p.project_code DESC, p.name", [$curP]);
$subs = all('SELECT s.id, s.project_id, s.code, s.name, s.status FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE ' . sp_pick_cond('s', $curS) . " AND (p.status IN ('active','quoted','onhold') OR p.id = ?) ORDER BY SUBSTRING_INDEX(s.code,'.',1), CAST(SUBSTRING_INDEX(s.code,'.',-1) AS UNSIGNED), s.code", [$curP]);
$ivs = function_exists('iv_has') && iv_has() ? all("SELECT i.id, i.legal_name, i.vat_no, i.vat_pct, i.owner_type, i.owner_id FROM invoicing_details i WHERE i.is_active = 1 AND i.owner_type IN ('vessel','customer','company') ORDER BY i.legal_name") : [];
// R95-003: identities are offered per project — the party's first (★), then the vessel's (⚓), then other organisations linked to the vessel (🏢); the JS narrows the list to the chosen project
$ivByProject = []; if (function_exists('pty_has') && pty_has()) foreach ($projects as $pp) { $PP = pty_project((int)$pp['id']); foreach (pty_identities($PP['org_id'], $PP['vessel_id'], (int)$pp['id']) as $iid => $ivr) $ivByProject[(int)$pp['id']][$iid] = pty_identity_label($ivr); }
$vatDef = 24;
// R95-006 — the same form registers a customer CREDIT NOTE: ?doc=credit (standalone) or ?credit_of=<invoice> (for an invoice: project, party, identity and VAT follow it)
$isCr = function_exists('cn_has') && cn_has() && (($_GET['doc'] ?? '') === 'credit' || !empty($_GET['credit_of']));
$crOf = $isCr && (int)($_GET['credit_of'] ?? 0) ? pi_get((int)$_GET['credit_of']) : null; if ($crOf && ($crOf['kind'] !== 'invoice' || in_array($crOf['status'], ['draft', 'cancelled'], true))) $crOf = null;
if ($crOf) { $curP = (int)$crOf['project_id']; $curS = (int)$crOf['subproject_id']; $vatDef = (float)$crOf['vat_pct']; if ($preNet <= 0) $preNet = (float)$crOf['amount_net']; if ($preTitle === '') $preTitle = t('cn.title_of', ['no' => $crOf['pi_no']]); }
$crInvoices = $isCr && !$crOf ? all("SELECT pi.id, pi.pi_no, pi.gross, pi.currency, pi.issue_date, p.project_code, v.name AS vessel FROM proformas pi JOIN projects p ON p.id = pi.project_id LEFT JOIN vessels v ON v.id = pi.vessel_id WHERE pi.kind = 'invoice' AND pi.status NOT IN ('draft','cancelled')" . ($curP ? ' AND pi.project_id = ' . (int)$curP : '') . ' ORDER BY pi.issue_date DESC, pi.id DESC LIMIT 400') : [];
$ttl = $isCr ? t('cn.new') : t('pi.di_new');
layout_head($ttl, 'proformas');
?>
<p class="eyebrow"><a href="<?= url('proformas') ?>"><?= tt('pi.title') ?></a> › <?= e($ttl) ?></p>
<div class="page-head"><div><h1><?= $isCr ? '↩' : '🧾' ?> <?= e($ttl) ?></h1><p class="lede" style="margin-bottom:0;"><?= $isCr ? tt('cn.lede') : tt('pi.di_lede') ?></p></div>
  <?php if (function_exists('cn_has') && cn_has() && !$AR): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('invoice_new', $isCr ? array_filter(['project' => $curP]) : array_filter(['doc' => 'credit', 'project' => $curP])) ?>"><?= $isCr ? '🧾 ' . tt('pi.di_new') : '↩ ' . tt('cn.new') ?></a></div><?php endif; ?></div>
<div class="card" style="max-width:980px;">
  <form method="post" action="<?= url('invoice_new') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pi_direct_create"><?php if ($AR): ?><input type="hidden" name="acc_report_id" value="<?= (int)$AR['id'] ?>"><?php endif; ?>
    <?php if ($AR): ?><p class="flash flash--info" style="margin:0 0 10px;">🧾 <?= e(t('pi.di_ar_note', ['no' => $AR['report_no'] . ' ' . $AR['revision'], 'h' => acc_h((float)$AR['total_hours']), 'v' => acc_m((float)$AR['total_net'], $AR['currency'] ?: 'EUR')])) ?> <a href="<?= url('acc_report', ['id' => (int)$AR['id']]) ?>"><?= tt('acc.report') ?> ›</a></p><?php endif; ?>
    <?php if ($FS): ?><p class="flash flash--info" style="margin:0 0 10px;">🛠 <?= e(t('pi.di_fsr_note', ['no' => $FS['report_no']])) ?> <a href="<?= url('fsr', ['id' => (int)$FS['id']]) ?>"><?= tt('fsr.title') ?> ›</a></p><?php endif; ?>
    <?php if ($preGross > 0): ?><p class="flash flash--info" style="margin:0 0 10px;">📐 <?= e(t('pi.di_gross_note', ['v' => number_format($preGross, 2, ',', '.')])) ?></p><?php endif; ?>
    <?php if ($isCr): ?><input type="hidden" name="doc_kind" value="credit">
      <?php if ($crOf): ?><input type="hidden" name="credit_of" value="<?= (int)$crOf['id'] ?>"><p class="flash flash--info" style="margin:0 0 10px;">↩ <?= e(t('cn.of_note', ['no' => $crOf['pi_no'], 'g' => pi_money((float)$crOf['gross'], (string)$crOf['currency']), 'o' => pi_money(max(0, (float)$crOf['open_sum']), (string)$crOf['currency'])])) ?> <a href="<?= url('proforma', ['id' => (int)$crOf['id']]) ?>"><?= e($crOf['pi_no']) ?> ›</a></p><?php endif; ?>
      <div class="grid grid-2">
        <?php if (!$crOf): ?><div class="field"><label for="credit_of"><?= tt('cn.of_lbl') ?></label><select id="credit_of" name="credit_of"><option value=""><?= tt('cn.standalone') ?></option><?php foreach ($crInvoices as $x): ?><option value="<?= (int)$x['id'] ?>"><?= e($x['pi_no'] . ' · ' . fmt_date((string)$x['issue_date']) . ' · ' . pi_money((float)$x['gross'], (string)$x['currency']) . ' · ' . $x['project_code'] . ($x['vessel'] ? ' · ' . $x['vessel'] : '')) ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('cn.of_hint') ?></p></div><?php endif; ?>
        <div class="field"><label for="credit_mode"><?= tt('cn.mode_lbl') ?></label><select id="credit_mode" name="credit_mode" data-nosearch><?php foreach (cn_modes() as $mk => $ml): ?><option value="<?= $mk ?>"<?= $mk === 'carry' ? ' selected' : '' ?>><?= e($ml) ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('cn.mode_hint') ?></p></div>
      </div>
    <?php endif; ?>
    <div class="grid grid-3">
      <div class="field"><label for="pi_no"><?= $isCr ? tt('cn.no') : tt('pi.di_no') ?> *</label><input id="pi_no" name="pi_no" maxlength="60" required placeholder="<?= tt('pi.di_no_ph') ?>"></div>
      <div class="field"><label for="issue_date"><?= tt('pi.issue_date') ?> *</label><input id="issue_date" type="date" name="issue_date" value="<?= date('Y-m-d') ?>" required></div>
      <?php if (!$isCr): ?><div class="field"><label for="due_date"><?= tt('pi.due') ?></label><input id="due_date" type="date" name="due_date"></div><?php endif; ?>
    </div>
    <div class="grid grid-2">
      <div class="field"><label for="project_id"><?= tt('pr.one') ?> *</label><select id="project_id" name="project_id" required><option value="">—</option><?php foreach ($projects as $p): ?><option value="<?= (int)$p['id'] ?>" data-v="<?= (int)$p['vessel_id'] ?>" data-c="<?= (int)$p['customer_id'] ?>"<?= $curP === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim(($p['project_code'] ?: '') . ' ' . $p['name']) . ($p['vessel'] ? ' · ' . $p['vessel'] : '') . ($p['customer'] ? ' · ' . $p['customer'] : '')) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label for="subproject_id"><?= tt('sp.one') ?></label><select id="subproject_id" name="subproject_id"><option value="">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>" data-p="<?= (int)$s['project_id'] ?>"<?= $curS === (int)$s['id'] ? ' selected' : '' ?>><?= e(trim(($s['code'] ?: '') . ' ' . $s['name'])) ?><?= $s['status'] === 'completed' ? ' · ' . e(t('pr.completed')) : '' ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('pi.di_sub_hint') ?></p></div>
    </div>
    <div class="field"><label for="title"><?= tt('pi.description') ?></label><input id="title" name="title" maxlength="200" value="<?= e($preTitle) ?>" placeholder="<?= tt('pi.di_title_ph') ?>"></div>
    <div class="grid grid-4">
      <div class="field"><label for="amount_net"><?= tt('pi.net') ?> *</label><input id="amount_net" name="amount_net" inputmode="decimal" required placeholder="0,00" value="<?= $preNet > 0 ? e(number_format($preNet, 2, ',', '.')) : '' ?>" data-gross="<?= $preGross > 0 ? e(number_format($preGross, 2, '.', '')) : '' ?>"></div>
      <div class="field"><label for="vat_pct"><?= tt('pi.vat') ?> %</label><input id="vat_pct" name="vat_pct" inputmode="decimal" value="<?= e((string)$vatDef) ?>"></div>
      <div class="field"><label for="currency"><?= tt('pi.currency') ?></label><input id="currency" name="currency" maxlength="3" value="EUR"></div>
      <div class="field"><label><?= tt('pi.gross') ?></label><output id="gross" class="mono" style="display:block;padding:9px 0;font-weight:700;">—</output></div>
    </div>
    <?php if ($ivs): ?><div class="field"><label for="invoicing_id"><?= tt('iv.title') ?></label><select id="invoicing_id" name="invoicing_id"><option value="">—</option><?php if ($ivByProject): if ($curP && isset($ivByProject[$curP])) { $ord = array_flip(array_keys($ivByProject[$curP])); usort($ivs, fn($a, $b) => ($ord[(int)$a['id']] ?? 999) <=> ($ord[(int)$b['id']] ?? 999)); } foreach ($ivs as $i): $pids = []; foreach ($ivByProject as $ppid => $m) if (isset($m[(int)$i['id']])) $pids[] = $ppid; if (!$pids) continue; ?><option value="<?= (int)$i['id'] ?>" data-p="<?= e(implode(',', $pids)) ?>" data-vat="<?= $i['vat_pct'] !== null ? e((string)(float)$i['vat_pct']) : '' ?>"><?= e($curP && isset($ivByProject[$curP][(int)$i['id']]) ? $ivByProject[$curP][(int)$i['id']] : $i['legal_name'] . ($i['vat_no'] ? ' · ' . $i['vat_no'] : '')) ?></option><?php endforeach; else: foreach ($ivs as $i): ?><option value="<?= (int)$i['id'] ?>" data-v="<?= $i['owner_type'] === 'vessel' ? (int)$i['owner_id'] : 0 ?>" data-c="<?= $i['owner_type'] === 'customer' ? (int)$i['owner_id'] : 0 ?>" data-vat="<?= $i['vat_pct'] !== null ? e((string)(float)$i['vat_pct']) : '' ?>"><?= e($i['legal_name'] . ($i['vat_no'] ? ' · ' . $i['vat_no'] : '')) ?></option><?php endforeach; endif; ?></select></div><?php endif; ?>
    <div class="grid grid-2">
      <div class="field"><label for="file"><?= tt('pi.di_file') ?></label><input id="file" type="file" name="file" accept="application/pdf,image/*"></div>
      <div class="field"><label for="notes"><?= tt('pi.notes') ?></label><textarea id="notes" name="notes" rows="2" maxlength="2000"></textarea></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit">✔ <?= $isCr ? tt('cn.register') : tt('pi.di_register') ?></button><a class="btn btn--ghost" href="<?= url('proformas') ?>"><?= tt('btn.cancel') ?></a></div>
    <p class="hint" style="margin:8px 0 0;"><?= $isCr ? tt('cn.hint') : tt('pi.di_hint') ?></p>
  </form>
</div>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var ps = document.getElementById('project_id'), ss = document.getElementById('subproject_id'), iv = document.getElementById('invoicing_id');
  function narrow() { var p = ps.value; ss.querySelectorAll('option[data-p]').forEach(function (o) { o.hidden = !!p && o.getAttribute('data-p') !== p; }); var o = ss.selectedOptions[0]; if (o && o.value && p && o.getAttribute('data-p') !== p) ss.value = '';
    if (iv) { var sel = ps.selectedOptions[0], v = sel ? sel.getAttribute('data-v') : '0', c = sel ? sel.getAttribute('data-c') : '0';
      iv.querySelectorAll('option[data-v]').forEach(function (o) { o.hidden = !!p && !((v !== '0' && o.getAttribute('data-v') === v) || (c !== '0' && o.getAttribute('data-c') === c)); });
      iv.querySelectorAll('option[data-p]').forEach(function (o) { o.hidden = !!p && (',' + o.getAttribute('data-p') + ',').indexOf(',' + p + ',') < 0; });
      var cur = iv.selectedOptions[0]; if (cur && cur.value && cur.hidden) iv.value = ''; } }
  ps.addEventListener('change', narrow); narrow();
  if (iv) iv.addEventListener('change', function () { var o = iv.selectedOptions[0]; if (o && o.getAttribute('data-vat')) { document.getElementById('vat_pct').value = o.getAttribute('data-vat'); calc(); } });
  ss.addEventListener('change', function () { var o = ss.selectedOptions[0]; if (o && o.value && ps.value !== o.getAttribute('data-p')) { ps.value = o.getAttribute('data-p'); narrow(); } });
  var n = document.getElementById('amount_net'), v = document.getElementById('vat_pct'), g = document.getElementById('gross'), cu = document.getElementById('currency');
  function num(s) { s = String(s || '').trim(); if (s.indexOf(',') > -1 && s.indexOf('.') > -1) s = s.replace(/\./g, '').replace(',', '.'); else s = s.replace(',', '.'); var x = parseFloat(s); return isNaN(x) ? 0 : x; }
  function calc() { var a = num(n.value), p = num(v.value); g.textContent = a > 0 ? (a * (1 + p / 100)).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + (cu.value || 'EUR') : '—'; }
  // raised from Accounting checks with the gross still to invoice: derive the net from the VAT % (re-derived when the VAT changes until the net is typed by hand)
  var G = parseFloat(n.getAttribute('data-gross') || '0'), auto = G > 0 && !n.value;
  function fromGross() { if (!auto) return; var p = num(v.value); n.value = (G / (1 + p / 100)).toFixed(2).replace('.', ','); calc(); }
  n.addEventListener('input', function () { auto = false; });
  v.addEventListener('input', fromGross); fromGross();
  [n, v, cu].forEach(function (el) { el.addEventListener('input', calc); }); calc();
})();
</script>
<?php layout_foot(); ?>

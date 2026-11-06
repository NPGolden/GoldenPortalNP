<?php
/** Procurement › Suppliers › Import from ERP — Επωνυμία · ΑΦΜ · Κωδικός · Διεύθυνση · Τ.Κ. · Πόλη · Χώρα · Τηλέφωνο · Email · Όροι · Προκαταβολή · Νόμισμα · Incoterms · Lead · Κατασκευαστές (GAS-CP-R82-002). ?p=suppliers_import */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('pr_admin') || !pr_admin()) { http_response_code(403); exit(t('err.noaccess')); }
if (isset($_GET['discard'])) unset($_SESSION['sup_import']);
if (isset($_GET['template'])) { sup_import_template(); return; }
$prev = $_SESSION['sup_import'] ?? null; $res = $_SESSION['sup_import_result'] ?? null; unset($_SESSION['sup_import_result']);
layout_head(t('sup.import'), 'suppliers');
?>
<p class="eyebrow"><a href="<?= url('suppliers') ?>"><?= tt('sup.title') ?></a></p>
<div class="page-head"><div><h1>⤒ <?= tt('sup.import') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sup.import_lede') ?></p></div></div>
<?php if ($res): ?><div class="flash flash--good"><b><?= tt('stk.imp_done') ?></b> — <?= e(t('sup.imp_done_s', $res)) ?> <a href="<?= url('suppliers') ?>"><?= tt('sup.title') ?> →</a></div><?php endif; ?>
<div class="cols">
<div>
  <div class="card">
    <h2>1 · <?= tt('stk.imp_upload') ?></h2>
    <form method="post" action="<?= url('suppliers_import') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sup_import_parse">
      <div class="field"><label><?= tt('im.file') ?> (.xlsx)</label><input type="file" name="file" accept=".xlsx" required></div>
      <p class="hint"><?= tt('sup.imp_cols') ?></p>
      <p class="hint">⤓ <a href="<?= url('suppliers_import', ['template' => 1]) ?>"><?= tt('sup.imp_template') ?></a> — <?= tt('sup.imp_template_h') ?></p>
      <button class="btn btn--gold" type="submit"><?= tt('stk.imp_preview') ?></button>
    </form>
  </div>
  <?php if ($prev): ?>
  <div class="card">
    <h2>2 · <?= tt('stk.imp_check') ?></h2>
    <div class="kpi kpi--3" style="margin-bottom:10px;"><div class="stat"><b><?= count($prev['rows']) ?></b><?= tt('stk.imp_rows') ?></div><div class="stat green"><b><?= (int)$prev['n_new'] ?></b><?= tt('stk.imp_new') ?></div><div class="stat"><b><?= (int)$prev['n_upd'] ?></b><?= tt('sup.imp_existing') ?></div></div>
    <p class="hint"><?= tt('stk.imp_mapped') ?>: <?php foreach ($prev['map'] as $k => $col): ?><code><?= e($k) ?></code>←<?= chr(65 + (int)$col) ?> <?php endforeach; ?></p>
    <?php if ($prev['warnings']): ?><details class="flash flash--warn"><summary><b><?= e(t('stk.imp_warn_n', ['n' => count($prev['warnings'])])) ?></b></summary><ul style="margin:6px 0 0 18px;"><?php foreach (array_slice($prev['warnings'], 0, 200) as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></details><?php endif; ?>
    <div class="table-scroll" style="max-height:380px;"><table class="tbl"><thead><tr><th>#</th><th><?= tt('sup.one') ?></th><th><?= tt('sup.vat') ?></th><th><?= tt('sup.erp_code') ?></th><th><?= tt('co.place') ?></th><th><?= tt('sup.terms') ?></th><th><?= tt('sup.makers') ?></th><th></th></tr></thead><tbody>
      <?php foreach (array_slice($prev['rows'], 0, 80) as $r): ?><tr><td class="hint"><?= (int)$r['line'] ?></td><td><b><?= e($r['name']) ?></b><?= $r['email'] ? '<br><small class="hint">' . e($r['email']) . '</small>' : '' ?></td><td class="mono"><?= e($r['vat_no'] ?? '') ?></td><td class="mono"><?= e($r['erp_code'] ?? '') ?></td><td><?= e(implode(', ', array_filter([$r['city'], $r['country']]))) ?></td><td><?= $r['terms_days'] !== null ? e(t('sup.days_n', ['n' => $r['terms_days']])) : '' ?><?= $r['currency'] ? ' · ' . e($r['currency']) : '' ?><?= $r['incoterms'] ? ' · ' . e($r['incoterms']) : '' ?></td><td><?= e(implode(', ', $r['makers'])) ?></td><td><?= $r['id'] ? pill('navy', t('sup.imp_update')) : pill('ok', t('stk.imp_new')) ?></td></tr><?php endforeach; ?>
      <?php if (count($prev['rows']) > 80): ?><tr><td colspan="8" class="hint"><?= e(t('stk.imp_more', ['n' => count($prev['rows']) - 80])) ?></td></tr><?php endif; ?></tbody></table></div>
    <?php if (!empty($prev['contacts'])): ?><p class="hint" style="margin:10px 0 4px;"><b>👤 <?= e(t('sup.imp_ct_n', ['n' => count($prev['contacts']), 'u' => count(array_filter($prev['contacts'], fn($c) => $c['id']))])) ?></b></p>
    <div class="table-scroll" style="max-height:260px;"><table class="tbl"><thead><tr><th>#</th><th><?= tt('sup.one') ?></th><th><?= tt('wc.name') ?></th><th><?= tt('ct.position') ?></th><th><?= tt('ct.ch_email') ?></th><th><?= tt('ct.phone') ?></th><th></th></tr></thead><tbody>
      <?php foreach (array_slice($prev['contacts'], 0, 80) as $c): ?><tr><td class="hint"><?= (int)$c['line'] ?></td><td><?= e($c['supplier']) ?></td><td><b><?= e($c['full_name']) ?></b></td><td><?= e((string)$c['position']) ?></td><td><?= e((string)$c['email']) ?></td><td><?= e((string)($c['mobile'] ?: $c['phone'])) ?></td><td><?= $c['id'] ? pill('mute', t('sup.imp_ct_upd')) : pill('ok', t('sup.imp_ct_new')) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card">
    <h2>3 · <?= tt('stk.imp_apply') ?></h2>
    <form method="post" action="<?= url('suppliers_import') ?>" data-lock data-confirm="<?= e(t('stk.imp_confirm', ['n' => count($prev['rows'])])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('stk.imp_apply') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sup_import_apply">
      <label class="chk"><input type="checkbox" name="update" value="1" checked> <?= tt('sup.imp_update_existing') ?></label>
      <p class="hint"><?= tt('sup.imp_apply_hint') ?></p>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('stk.imp_apply') ?></button><a class="btn btn--ghost" href="<?= url('suppliers_import', ['discard' => 1]) ?>"><?= tt('btn.cancel') ?></a></div>
    </form>
  </div>
  <?php endif; ?>
</div>
<div><div class="card"><h2>ℹ <?= tt('stk.imp_how') ?></h2><ol class="hint" style="padding-left:18px;"><li><?= tt('sup.imp_how1') ?></li><li><?= tt('sup.imp_how2') ?></li><li><?= tt('sup.imp_how3') ?></li><li><?= tt('sup.imp_how4') ?></li></ol></div></div>
</div>
<?php layout_foot(); ?>

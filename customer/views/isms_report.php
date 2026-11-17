<?php
/** IT & Cybersecurity — any staff member reports a security event (ISO 27001 A 6.8). It becomes an incident of the ISMS.   GAS-CP-R97-080   ?p=isms_report */
require __DIR__ . '/_layout.php';
$u = require_staff(); $on = function_exists('ism_has') && ism_has();
layout_head(t('ism.report_title'), 'isms_report');
?>
<div class="page-head"><div><h1>🚨 <?= tt('ism.report_title') ?></h1><p class="lede"><?= tt('ism.report_lede') ?></p></div></div>
<?php if (!$on): ?><div class="card"><div class="empty"><?= tt('err.noaccess') ?></div></div>
<?php elseif (!empty($_GET['sent'])): ?><div class="card"><p><b><?= tt('ism.report_thanks') ?></b></p><p class="hint"><?= tt('ism.report_next') ?></p><a class="btn btn--ghost" href="<?= url('isms_report') ?>"><?= tt('ism.report_again') ?></a></div>
<?php else: ?>
<div class="card" style="max-width:820px;">
  <form method="post" action="<?= url('isms_report') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ism_report">
    <div class="field"><label><?= tt('ism.rp_title') ?> *</label><input name="title" maxlength="200" required placeholder="<?= tt('ism.rp_title_ph') ?>"></div>
    <div class="grid grid-2"><div class="field"><label><?= tt('ism.rp_when') ?></label><input type="date" name="when" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div><div class="field"><label><?= tt('ism.rp_affected') ?></label><input name="affected" maxlength="255" placeholder="<?= tt('ism.rp_affected_ph') ?>"></div></div>
    <div class="field"><label><?= tt('ism.rp_what') ?> *</label><textarea name="what" rows="6" maxlength="6000" required placeholder="<?= tt('ism.rp_what_ph') ?>"></textarea></div>
    <p class="hint"><?= tt('ism.rp_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('ism.rp_send') ?></button></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

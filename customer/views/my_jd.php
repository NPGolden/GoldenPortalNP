<?php
/** Work › My job description — what the company expects from me (GAS-CP-R97-121).  ?p=my_jd[&format=pdf] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $M = function_exists('jd_has') && jd_has() ? jd_mine() : null; $c = $M['c'] ?? null;
if ($c && ($_GET['format'] ?? '') === 'pdf') {
    $b = jd_pdf($c, jd_revs((int)$M['jd']['id']), $M['person'], $M['ack']); while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="' . jd_doc_no($c) . '_R' . (int)$c['rev'] . '.pdf"'); header('Content-Length: ' . strlen($b)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); echo $b; exit;
}
layout_head(t('jd.mine'), 'my_jd');
?>
<div class="page-head"><div><h1>📄 <?= tt('jd.mine') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('jd.mine_lede') ?></p></div>
  <?php if ($c): ?><div class="btn-row"><a class="btn btn--gold" target="_blank" rel="noopener" href="<?= url('my_jd', ['format' => 'pdf']) ?>">⤓ PDF</a></div><?php endif; ?></div>
<?php if (!$M): ?><div class="empty"><?= tt('jd.mine_noperson') ?></div>
<?php elseif (!$c): ?><div class="empty"><?= tt($M['jd'] ? 'jd.mine_pending' : 'jd.mine_none') ?></div>
<?php else: ?>
<?php if ($M['ack']): ?><div class="flash flash--good"><?= e(t('jd.ack_done', ['d' => fmt_date(substr((string)$M['ack']['acked_at'], 0, 10)), 'r' => 'R' . (int)$c['rev']])) ?></div>
<?php else: ?><div class="flash flash--warn"><form method="post" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_ack"><b><?= e(t('jd.ack_ask', ['r' => 'R' . (int)$c['rev']])) ?></b> <button class="btn btn--gold btn--sm" type="submit"><?= tt('jd.ack_btn') ?></button></form></div><?php endif; ?>
<?php $jdC = $c; $jdRevs = jd_revs((int)$M['jd']['id']); require __DIR__ . '/_jd_body.php'; ?>
<?php endif; ?>
<?php layout_foot(); ?>

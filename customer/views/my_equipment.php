<?php
/** Work › My equipment — what the company has given me, lists to accept, handing over (GAS-CP-R97-124).  ?p=my_equipment[&doc=ID&format=pdf] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $ok = function_exists('hre_has') && hre_has();
if ($ok && ($_GET['format'] ?? '') === 'pdf') { $d = hre_doc((int)($_GET['doc'] ?? 0)); if (!$d || !hre_can_view($d)) { http_response_code(403); layout_head(t('hre.mine'), 'my_equipment'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; } hre_pdf_out($d); }
$P = $ok ? hre_my_person() : null;
layout_head(t('hre.mine'), 'my_equipment');
?>
<div class="page-head"><div><h1>🧰 <?= tt('hre.mine') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hre.mine_lede') ?></p></div></div>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('hre.need_db') ?></div>
<?php elseif (!$P): ?><div class="empty"><?= tt('hre.mine_noperson') ?></div>
<?php else: $pid = (int)$P['id']; $docs = hre_docs($pid); $with = hre_items($pid, HRE_WITH); $free = array_values(array_filter($with, 'hre_free'));
  $toDo = array_values(array_filter($docs, fn($d) => ($d['kind'] === 'issue' && $d['status'] === 'sent') || ($d['kind'] === 'return' && $d['status'] === 'open' && !$d['emp_at']))); ?>
<?php foreach ($toDo as $d): $isRet = $d['kind'] === 'return'; $items = hre_doc_items($d); ?>
<div class="card" style="border-left:4px solid var(--cp-gold,#C9A227);" id="eq-doc-<?= (int)$d['id'] ?>">
  <div class="page-head" style="margin-bottom:4px;"><h2 style="margin:0;"><?= $isRet ? '↩' : '✉' ?> <?= e(t($isRet ? 'hre.return_list' : 'hre.issue_list') . ' ' . $d['doc_no']) ?> <?= hre_doc_pill($d) ?></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="<?= url('my_equipment', ['doc' => (int)$d['id'], 'format' => 'pdf']) ?>">⤓ PDF</a></div></div>
  <p class="hint"><?= e(fmt_date($d['doc_date']) . ' · ' . $d['created_name']) ?><?= (string)$d['note'] !== '' ? ' · ' . e($d['note']) : '' ?></p>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?> / <?= tt('hre.tag') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.cond_out') ?></th></tr></thead><tbody>
  <?php foreach ($items as $n => $i): ?><tr><td><?= $n + 1 ?></td><td><?= e(hre_cat_icon((string)$i['category']) . ' ' . hre_cat_label((string)$i['category'])) ?></td><td><b><?= e($i['description']) ?></b><?= (string)$i['size'] !== '' ? ' <span class="hint">' . e(t('hre.size') . ' ' . $i['size']) . '</span>' : '' ?><?= (string)$i['note'] !== '' ? '<br><span class="hint">' . e($i['note']) . '</span>' : '' ?></td><td><?= e(trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /')) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= tt('hre.k_' . $i['cond_out']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <ul class="hint" style="margin:8px 0;"><?php foreach (range(1, $isRet ? 2 : 4) as $k): ?><li><?= tt(($isRet ? 'hre.decl_r' : 'hre.decl_') . $k) ?></li><?php endforeach; ?></ul>
  <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_accept"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
    <label class="chk"><input type="checkbox" name="agree" value="1" required> <b><?= tt($isRet ? 'hre.agree_r' : 'hre.agree') ?></b></label>
    <div class="btn-row" style="margin-top:6px;"><button class="btn btn--gold" type="submit">✔ <?= tt($isRet ? 'hre.confirm_btn' : 'hre.accept_btn') ?></button></div></form>
  <?php if (!$isRet): ?><form method="post" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="hre_dispute"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
    <div class="btn-row"><input name="remark" maxlength="500" required placeholder="<?= tt('hre.dispute_ph') ?>" style="flex:1;min-width:220px;"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('hre.dispute_btn') ?></button></div></form><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card" id="eq-mine">
  <h2><?= tt('hre.mine_with') ?> <span class="count"><?= count($with) ?></span></h2>
  <?php if (!$with): ?><div class="empty"><?= tt('hre.mine_none') ?></div><?php else: ?>
  <form method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_handover">
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th style="width:28px;"></th><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?> / <?= tt('hre.tag') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.issued_on') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($with as $i): $fr = hre_free($i); ?><tr><td><?php if ($fr): ?><label class="chk" style="margin:0;"><input type="checkbox" name="it[]" value="<?= (int)$i['id'] ?>"></label><?php endif; ?></td>
    <td><?= e(hre_cat_icon((string)$i['category']) . ' ' . hre_cat_label((string)$i['category'])) ?></td><td><b><?= e($i['description']) ?></b><?= (string)$i['size'] !== '' ? ' <span class="hint">' . e(t('hre.size') . ' ' . $i['size']) . '</span>' : '' ?></td><td><?= e(trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /')) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= $i['issued_on'] ? e(fmt_date($i['issued_on'])) : '—' ?></td><td><?= hre_item_pill($i) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($free): ?><div class="btn-row" style="margin-top:8px;"><input name="note" maxlength="500" placeholder="<?= tt('hre.note_ph') ?>" style="flex:1;min-width:200px;"><button class="btn btn--sm" type="submit" data-confirm="<?= tt('hre.mine_handover_confirm') ?>">↩ <?= tt('hre.mine_handover_btn') ?></button></div><p class="hint"><?= tt('hre.mine_handover_hint') ?></p><?php endif; ?>
  </form><?php endif; ?>
</div>

<?php if ($docs): ?>
<div class="card" id="eq-docs"><h2>📑 <?= tt('hre.docs_title') ?> <span class="count"><?= count($docs) ?></span></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('hre.doc') ?></th><th class="num"><?= tt('hre.lines') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($docs as $d): ?><tr><td><?= e(fmt_date($d['doc_date'])) ?></td><td><?= e(t($d['kind'] === 'return' ? 'hre.return_list' : 'hre.issue_list') . ' ' . $d['doc_no']) ?><?= $d['status'] === 'disputed' ? '<br><span class="hint">' . e((string)$d['emp_remark']) . '</span>' : '' ?></td><td class="num"><?= (int)$d['n_items'] ?></td><td><?= hre_doc_pill($d) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="<?= url('my_equipment', ['doc' => (int)$d['id'], 'format' => 'pdf']) ?>">⤓ PDF</a></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php endif; ?>
<?php layout_foot(); ?>

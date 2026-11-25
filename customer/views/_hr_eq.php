<?php /** Employee card › tab Equipment: lines, issue lists, handover lists ($p = person, $pid).   GAS-CP-R97-124   (included by hr_person.php) */
$eqAll = hre_items($pid); $eqDraft = array_values(array_filter($eqAll, fn($i) => $i['status'] === 'draft')); $eqWith = array_values(array_filter($eqAll, fn($i) => in_array($i['status'], HRE_WITH, true)));
$eqGone = array_values(array_filter($eqAll, fn($i) => in_array($i['status'], ['returned', 'writeoff'], true))); $eqDocs = hre_docs($pid); $eqIns = hre_sources(); $eqKit = function_exists('hre_kit_for') ? hre_kit_for($p) : []; $eqSelf = url('hr_person', ['id' => $pid, 'tab' => 'eq']);
$eqFree = array_values(array_filter($eqWith, 'hre_free')); $eqAcc = !empty($p['user_id']) && !empty($p['account_active']);
$eqLine = fn(array $i) => hre_cat_icon((string)$i['category']) . ' <b>' . e($i['description']) . '</b>' . ((string)$i['size'] !== '' ? ' <span class="hint">' . e(t('hre.size') . ' ' . $i['size']) . '</span>' : '') . ((string)$i['note'] !== '' ? '<br><span class="hint">' . e($i['note']) . '</span>' : '');
?>
<div class="card" id="eq-lines">
  <div class="page-head" style="margin-bottom:4px;"><h2 style="margin:0;">🧰 <?= tt('hre.with_title') ?> <span class="count"><?= count($eqWith) ?></span></h2><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('hr_equipment', ['q' => (string)$p['name_en']]) ?>"><?= tt('hre.title') ?></a></div></div>
  <p class="hint"><?= tt('hre.with_hint') ?></p>
  <?php if (!$p['is_active'] && $eqWith): ?><div class="flash flash--warn"><?= tt('hre.left_warn') ?></div><?php endif; ?>
  <?php if (!$eqWith): ?><p class="hint"><?= tt('hre.none_with') ?></p><?php else: ?>
  <form method="post" action="<?= $eqSelf ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_handover"><input type="hidden" name="person_id" value="<?= $pid ?>">
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th style="width:28px;"></th><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?> / <?= tt('hre.tag') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.cond_out') ?></th><th><?= tt('hre.issue_list') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($eqWith as $i): $free = hre_free($i); ?><tr>
    <td><?php if ($free): ?><label class="chk" style="margin:0;"><input type="checkbox" name="it[]" value="<?= (int)$i['id'] ?>"<?= !$p['is_active'] ? ' checked' : '' ?>></label><?php endif; ?></td>
    <td><?= e(hre_cat_label((string)$i['category'])) ?></td><td><?= $eqLine($i) ?></td><td><?= e(trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /')) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= tt('hre.k_' . $i['cond_out']) ?></td>
    <td><?= e((string)$i['issue_no']) ?><?= $i['issued_on'] ? '<br><span class="hint">' . e(fmt_date($i['issued_on'])) . '</span>' : '' ?></td><td><?= hre_item_pill($i) ?><?= $i['expires_on'] ? '<br>' . ($i['expires_on'] <= date('Y-m-d', strtotime('+30 days')) ? pill($i['expires_on'] < date('Y-m-d') ? 'bad' : 'warn', t('hre.exp_on', ['d' => fmt_date($i['expires_on'])])) : '<span class="hint">' . e(t('hre.exp_on', ['d' => fmt_date($i['expires_on'])])) . '</span>') : '' ?><?= !empty($i['return_no']) && $i['status'] !== 'missing' ? '<br><span class="hint">' . e((string)$i['return_no']) . '</span>' : '' ?><?= $i['status'] === 'missing' && (string)$i['return_note'] !== '' ? '<br><span class="hint">' . e($i['return_note']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($eqFree): ?><div class="btn-row" style="margin-top:8px;"><input name="note" maxlength="500" placeholder="<?= tt('hre.note_ph') ?>" style="flex:1;min-width:200px;"><button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= tt('hre.handover_confirm') ?>">↩ <?= tt('hre.handover_btn') ?></button></div><p class="hint"><?= tt('hre.handover_hint') ?></p><?php endif; ?>
  </form>
  <?php endif; ?>
</div>

<div class="card" id="eq-add">
  <h2>＋ <?= tt('hre.add_title') ?> <?= $eqDraft ? '<span class="count">' . count($eqDraft) . '</span>' : '' ?></h2>
  <p class="hint"><?= tt('hre.add_hint') ?></p>
  <form method="post" action="<?= $eqSelf ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_add"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="field"><label><?= tt('hre.category') ?></label><select name="category" data-nosearch><?php foreach (HRE_CATS as $c): ?><option value="<?= $c ?>"><?= e(hre_cat_icon($c) . ' ' . hre_cat_label($c)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.description') ?></label><input name="description" maxlength="200" list="hre-sug" autocomplete="off" placeholder="<?= tt('hre.desc_ph') ?>"><datalist id="hre-sug"><?php foreach (hre_suggest() as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist></div>
    <?php $snA = function_exists('sn_assets_free') ? sn_assets_free() : []; if ($snA): /* R97-252: a company asset from the warehouse, by serial number */ ?><div class="field field--full"><label>🏷 <?= tt('sn.from_wh') ?></label><select name="inv_sn"><option value="0">—</option><?php foreach ($snA as $sid_ => $sx_): ?><option value="<?= (int)$sid_ ?>"><?= e($sx_['label']) ?></option><?php endforeach; ?></select><span class="hint"><?= tt('sn.from_wh_hint') ?></span></div><?php endif; ?>
    <?php if ($eqIns): ?><div class="field field--full"><label><?= tt('hre.from_reg') ?></label><select name="src_id"><option value="0">—</option><?php foreach (['cal' => 'hre.src_cal', 'it' => 'hre.src_it'] as $g_ => $gl_): $go_ = array_filter($eqIns, fn($x) => $x['grp'] === $g_); if (!$go_) continue; ?><optgroup label="<?= tt($gl_) ?>"><?php foreach ($go_ as $iid => $x): $hh = hre_holder_of((int)$iid); ?><option value="<?= (int)$iid ?>"<?= $hh ? ' disabled' : '' ?>><?= e($x['label'] . ($hh ? ' — ' . t('hre.held_by', ['n' => $hh]) : '')) ?></option><?php endforeach; ?></optgroup><?php endforeach; ?></select><p class="hint"><?= tt('hre.from_reg_hint') ?></p></div><?php endif; ?>
    <div class="field"><label><?= tt('hre.serial') ?></label><input name="serial_no" maxlength="80"></div>
    <div class="field"><label><?= tt('hre.tag') ?></label><input name="asset_tag" maxlength="40"></div>
    <div class="field"><label><?= tt('hre.qty') ?></label><input name="qty" value="1" inputmode="decimal" style="max-width:110px;"></div>
    <div class="field"><label><?= tt('hre.size') ?></label><input name="size" maxlength="20" placeholder="<?= tt('hre.size_ph') ?>" style="max-width:160px;"></div>
    <div class="field"><label><?= tt('hre.cond_out') ?></label><select name="cond_out" data-nosearch><?php foreach (HRE_CONDS as $c): ?><option value="<?= $c ?>"><?= tt('hre.k_' . $c) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.expires') ?></label><input type="date" name="expires_on" style="max-width:180px;"><p class="hint"><?= tt('hre.expires_hint') ?></p></div>
    <div class="field"><label><?= tt('hr.notes') ?></label><input name="note" maxlength="300"></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">＋ <?= tt('hre.add_btn') ?></button></div>
  </form>
  <?php if ($eqKit): ?><form method="post" action="<?= $eqSelf ?>" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="hre_kit_apply"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="btn-row"><button class="btn btn--ghost btn--sm" type="submit">🎒 <?= e(t('hre.kit_btn', ['n' => count($eqKit)])) ?></button><span class="hint"><?= e(t('hre.kit_hint', ['d' => (string)($p['department'] ?? '') ?: '—'])) ?> <a href="<?= url('hr_equipment') ?>#eq-kits"><?= tt('hre.kit_title') ?></a></span></div></form>
  <?php else: ?><p class="hint" style="margin-top:10px;">🎒 <?= tt('hre.kit_none') ?> <a href="<?= url('hr_equipment') ?>#eq-kits"><?= tt('hre.kit_title') ?></a></p><?php endif; ?>
  <?php if ($eqDraft): ?>
  <h3 style="margin-top:14px;"><?= tt('hre.draft_title') ?></h3>
  <div class="table-scroll"><table class="tbl" data-nosort><tbody><?php foreach ($eqDraft as $i): ?><tr><td><?= e(hre_cat_label((string)$i['category'])) ?></td><td><?= $eqLine($i) ?></td><td><?= e(trim((string)$i['serial_no'] . ((string)$i['asset_tag'] !== '' ? ' / ' . $i['asset_tag'] : ''), ' /')) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= tt('hre.k_' . $i['cond_out']) ?></td>
    <td class="row-actions"><form method="post" action="<?= $eqSelf ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hre_del"><input type="hidden" name="item_id" value="<?= (int)$i['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td></tr><?php endforeach; ?></tbody></table></div>
  <form method="post" action="<?= $eqSelf ?>" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="hre_issue"><input type="hidden" name="person_id" value="<?= $pid ?>">
    <div class="btn-row"><input name="note" maxlength="500" placeholder="<?= tt('hre.note_ph') ?>" style="flex:1;min-width:200px;"><button class="btn btn--gold" type="submit" data-confirm="<?= e(t('hre.issue_confirm', ['n' => count($eqDraft)])) ?>">✉ <?= e(t('hre.issue_btn', ['n' => count($eqDraft)])) ?></button></div>
    <p class="hint"><?= tt($eqAcc ? 'hre.issue_hint' : 'hre.issue_hint_paper') ?></p>
  </form>
  <?php endif; ?>
</div>

<div class="card" id="eq-docs">
  <h2>📑 <?= tt('hre.docs_title') ?> <span class="count"><?= count($eqDocs) ?></span></h2>
  <?php if (!$eqDocs): ?><p class="hint"><?= tt('hre.docs_none') ?></p><?php else: foreach ($eqDocs as $d): $isRet = $d['kind'] === 'return'; $open = in_array($d['status'], ['sent', 'disputed', 'open'], true); $dItems = $open ? hre_doc_items($d) : []; ?>
  <div class="card card--tight" style="margin:8px 0;<?= $open ? 'border-left:4px solid var(--cp-gold,#C9A227);' : '' ?>">
    <div class="page-head" style="margin-bottom:4px;"><h3 style="margin:0;"><?= $isRet ? '↩' : '✉' ?> <?= e(t($isRet ? 'hre.return_list' : 'hre.issue_list') . ' ' . $d['doc_no']) ?> <?= hre_doc_pill($d) ?></h3>
      <div class="btn-row"><a class="btn btn--ghost btn--sm" target="_blank" rel="noopener" href="<?= url('my_equipment', ['doc' => (int)$d['id'], 'format' => 'pdf']) ?>">⤓ PDF</a>
      <?php if ($open): ?><form method="post" action="<?= $eqSelf ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hre_cancel"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt($isRet ? 'hre.cancel_confirm_r' : 'hre.cancel_confirm') ?>"><?= tt('hre.cancel_btn') ?></button></form><?php endif; ?></div></div>
    <p class="hint" style="margin:0;"><?= e(fmt_date($d['doc_date'])) ?> · <?= (int)$d['n_items'] ?> <?= tt('hre.lines') ?> · <?= e((string)$d['created_name']) ?>
      <?php if ($d['emp_at']): ?> · <?= e(t($isRet ? 'hre.emp_confirmed' : 'hre.ds_accepted') . ': ' . $d['emp_name'] . ', ' . fmt_datetime($d['emp_at']) . ' (' . t('hre.via_' . $d['emp_via']) . ')') ?><?php endif; ?>
      <?php if ($d['hr_at']): ?> · <?= e(t('hre.hr_accepted') . ': ' . $d['hr_name'] . ', ' . fmt_datetime($d['hr_at'])) ?><?php endif; ?></p>
    <?php if ((string)$d['note'] !== ''): ?><p style="margin:4px 0 0;"><?= e($d['note']) ?></p><?php endif; ?>
    <?php if ($d['status'] === 'disputed'): ?><div class="flash flash--warn" style="margin-top:6px;"><b><?= tt('hre.ds_disputed') ?>:</b> <?= e((string)$d['emp_remark']) ?><br><span class="hint"><?= tt('hre.disputed_hint') ?></span></div><?php endif; ?>
    <?php if (!$isRet && $open): ?>
      <ul style="margin:6px 0;"><?php foreach ($dItems as $i): ?><li><?= e(hre_cat_label((string)$i['category']) . ' — ' . $i['description'] . ((string)$i['serial_no'] !== '' ? ' · S/N ' . $i['serial_no'] : '') . ' × ' . hre_qty($i['qty'])) ?></li><?php endforeach; ?></ul>
      <form method="post" action="<?= $eqSelf ?>" class="btn-row" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_paper"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
        <input name="signer" maxlength="160" value="<?= e((string)$p['name_en']) ?>" style="max-width:260px;"><button class="btn btn--sm" type="submit" data-confirm="<?= tt('hre.paper_confirm') ?>">🖊 <?= tt('hre.paper_btn') ?></button></form>
      <p class="hint"><?= tt($eqAcc ? 'hre.paper_hint' : 'hre.paper_hint_noacc') ?></p>
    <?php elseif ($isRet && $open): ?>
      <form method="post" action="<?= $eqSelf ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_receive"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
      <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.result') ?></th><th><?= tt('hre.cond_in') ?></th><th><?= tt('hre.result_note') ?></th></tr></thead><tbody>
      <?php foreach ($dItems as $i): ?><tr><td><?= $eqLine($i) ?><br><span class="hint"><?= e(hre_cat_label((string)$i['category'])) ?></span></td><td><?= e((string)$i['serial_no']) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td>
        <td><select name="res[<?= (int)$i['id'] ?>]" data-nosearch required><option value=""><?= tt('hre.r_choose') ?></option><?php foreach (HRE_RESULTS as $r): ?><option value="<?= $r ?>"><?= tt('hre.r_' . $r) ?></option><?php endforeach; ?></select></td>
        <td><select name="cond_in[<?= (int)$i['id'] ?>]" data-nosearch><option value="">—</option><?php foreach (HRE_CONDS as $c): ?><option value="<?= $c ?>"><?= tt('hre.k_' . $c) ?></option><?php endforeach; ?></select></td>
        <td><input name="rnote[<?= (int)$i['id'] ?>]" maxlength="300" style="width:100%;"></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit" data-confirm="<?= tt('hre.receive_confirm') ?>">✔ <?= tt('hre.receive_btn') ?></button></div>
      <p class="hint"><?= tt('hre.receive_hint') ?></p></form>
    <?php endif; ?>
  </div>
  <?php endforeach; endif; ?>
</div>

<?php if ($eqGone): ?>
<div class="card" id="eq-gone">
  <h2>✓ <?= tt('hre.gone_title') ?> <span class="count"><?= count($eqGone) ?></span></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.issued_on') ?></th><th><?= tt('hre.returned_on') ?></th><th><?= tt('hre.return_list') ?></th><th><?= tt('hre.result') ?></th></tr></thead><tbody>
  <?php foreach ($eqGone as $i): ?><tr><td><?= e(hre_cat_label((string)$i['category'])) ?></td><td><?= $eqLine($i) ?></td><td><?= e((string)$i['serial_no']) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= $i['issued_on'] ? e(fmt_date($i['issued_on'])) : '—' ?></td><td><?= $i['returned_on'] ? e(fmt_date($i['returned_on'])) : '—' ?></td><td><?= e((string)$i['return_no']) ?></td>
    <td><?= $i['status'] === 'writeoff' ? pill('mute', t('hre.st_writeoff')) : ($i['cond_in'] === 'damaged' ? pill('bad', t('hre.r_damaged')) : pill('green', t('hre.r_ok'))) ?><?= (string)$i['return_note'] !== '' ? '<br><span class="hint">' . e($i['return_note']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<?php
/** Partial: the offer builder on the offer page — lines from the rate cards, text blocks, document (PDF / Word, issue).   GAS-CP-R33-001
 *  expects $o (of_load), $id, $staff */
if (!function_exists('ob_has') || !ob_has()) return;
$canB = $staff && ob_can(); $lines = ob_lines($id); if (!$canB && !$lines) return;
$self = url('offer', ['id' => $id]); $cur = (string)$o['currency']; $isDraft = $o['status'] === 'draft';
$fmtN = fn($x) => rtrim(rtrim(number_format((float)$x, 2, '.', ''), '0'), '.');
$rl = !empty($o['rate_list_id']) ? rl_get((int)$o['rate_list_id']) : null;
if ($canB && !$rl && function_exists('rl_current')) { $b = function_exists('br_of') ? br_of((int)$o['project_id']) : []; $rl = rl_current((int)($b['id'] ?? 0) ?: null, (string)($b['code'] ?? '')); if ($rl) q('UPDATE offers SET rate_list_id = ? WHERE id = ?', [(int)$rl['id'], $id]); }
$items = $rl ? rl_items((int)$rl['id']) : []; $cards = $rl ? rl_cards((int)$rl['id']) : [];
if ($canB) { ob_blocks_init($o); $blocks = ob_blocks($id); } else $blocks = [];
$issued = function_exists('ar_issued') ? ar_issued($o['offer_no'], (int)$o['project_id']) : [];
?>
<div class="card" id="ob-lines">
  <div class="card-head"><h2><?= tt('ob.lines') ?> <span class="count"><?= count($lines) ?></span></h2>
    <?php if ($rl): ?><span class="hint"><?= tt('ob.priced_from') ?> <b><?= e($rl['doc_ref'] . ' ' . $rl['revision']) ?></b> · <?= e($rl['branch_code']) ?><?= $rl['status'] !== 'active' ? ' · ' . pill('mute', t('rl.superseded')) : '' ?></span><?php elseif ($canB): ?><span class="hint"><?= tt('ob.no_rate_list') ?><?= is_cp_admin() ? ' <a href="' . url('admin_rate_cards') . '">' . tt('rl.title') . ' ›</a>' : '' ?></span><?php endif; ?></div>
  <?php if ($canB): ?><form method="post" action="<?= $self ?>#ob-lines" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ob_lines_bulk"><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <table class="tbl ob-tbl"><thead><tr><th><?= tt('ob.c_no') ?></th><th><?= tt('ob.c_code') ?></th><th><?= tt('ob.c_desc') ?></th><th><?= tt('ob.c_unit') ?></th><th class="num"><?= tt('ob.c_qty') ?></th><th class="num"><?= tt('ob.c_price') ?> (<?= e($cur) ?>)</th><th class="num"><?= tt('ob.c_disc') ?></th><th class="num"><?= tt('ob.c_total') ?></th><?php if ($canB): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php $i = 0; foreach ($lines as $ln): $isT = $ln['kind'] === 'text'; if (!$isT) $i++; ?>
    <tr class="<?= $isT ? 'ob-text' : '' ?>">
      <td><?= $isT ? '' : $i ?></td><td class="mono"><?= e($ln['erp_code']) ?></td>
      <td><?= $isT ? '<b>' . e($ln['description']) . '</b>' : e($ln['description']) ?><?= $ln['zone'] === 'overseas' && !$isT ? ' ' . pill('navy', t('ob.z_overseas')) : '' ?><?= $ln['notes'] ? '<br><span class="hint">' . e($ln['notes']) . '</span>' : '' ?></td>
      <td><?= e($ln['unit']) ?></td>
      <?php if ($isT): ?><td></td><td></td><td></td><td></td>
      <?php elseif ($canB): ?>
        <td class="num"><input name="q[<?= $ln['id'] ?>]" value="<?= $fmtN($ln['qty']) ?>" inputmode="decimal" class="ob-in"></td>
        <td class="num"><input name="p[<?= $ln['id'] ?>]" value="<?= e(number_format((float)$ln['unit_price'], 2, '.', '')) ?>" inputmode="decimal" class="ob-in ob-in--w"></td>
        <td class="num"><input name="d[<?= $ln['id'] ?>]" value="<?= $fmtN($ln['discount_pct']) ?>" inputmode="decimal" class="ob-in"></td>
        <td class="num"><b><?= e(number_format((float)$ln['line_total'], 2, ',', '.')) ?></b></td>
      <?php else: ?>
        <td class="num"><?= $fmtN($ln['qty']) ?></td><td class="num"><?= e(number_format((float)$ln['unit_price'], 2, ',', '.')) ?></td><td class="num"><?= (float)$ln['discount_pct'] > 0 ? $fmtN($ln['discount_pct']) . ' %' : '' ?></td><td class="num"><b><?= e(number_format((float)$ln['line_total'], 2, ',', '.')) ?></b></td>
      <?php endif; ?>
      <?php if ($canB): ?><td class="ob-act"><button class="linkbtn" type="submit" formaction="<?= $self ?>#ob-lines" name="move" value="u:<?= $ln['id'] ?>" title="↑">↑</button> <button class="linkbtn" type="submit" name="move" value="d:<?= $ln['id'] ?>" title="↓">↓</button> <button class="linkbtn" type="submit" name="del" value="<?= $ln['id'] ?>" data-confirm="<?= tt('ob.del_line') ?>" title="×">×</button></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lines): ?><tr><td colspan="<?= $canB ? 9 : 8 ?>" class="hint"><?= tt('ob.no_lines') ?></td></tr><?php endif; ?>
  </tbody><tfoot>
    <tr><td colspan="<?= $canB ? 6 : 6 ?>"></td><td class="num"><?= tt('ob.net') ?></td><td class="num"><b><?= e(number_format((float)$o['amount'], 2, ',', '.')) ?></b></td><?php if ($canB): ?><td></td><?php endif; ?></tr>
    <tr><td colspan="6"></td><td class="num"><?= tt('ob.vat_short') ?> <?php if ($canB): ?><input name="vat_pct" value="<?= $fmtN($o['vat_pct']) ?>" inputmode="decimal" class="ob-in"> %<?php else: ?><?= $fmtN($o['vat_pct']) ?> %<?php endif; ?></td><td class="num"><?= e(number_format((float)$o['vat_total'], 2, ',', '.')) ?></td><?php if ($canB): ?><td></td><?php endif; ?></tr>
    <tr><td colspan="6"></td><td class="num"><b><?= tt('ob.gross') ?></b></td><td class="num"><b><?= e(number_format((float)$o['vat_pct'] > 0 ? (float)$o['gross_total'] : (float)$o['amount'], 2, ',', '.')) ?> <?= e($cur) ?></b></td><?php if ($canB): ?><td></td><?php endif; ?></tr>
  </tfoot></table>
  <?php if ($canB): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('ob.save_lines') ?></button><span class="hint"><?= tt('ob.lines_hint') ?></span></div></form>
  <h3 class="ed-h" style="margin-top:16px;"><?= tt('ob.add_line') ?></h3>
  <form method="post" action="<?= $self ?>#ob-lines" data-lock class="ob-add"><?= csrf_field() ?><input type="hidden" name="action" value="ob_line_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-4">
      <div class="field" style="margin:0;"><label><?= tt('ob.kind') ?></label><select name="kind" id="ob-kind" data-nosearch><option value="rate"<?= $items ? '' : ' disabled' ?>><?= tt('ob.k_rate') ?></option><option value="free"<?= $items ? '' : ' selected' ?>><?= tt('ob.k_free') ?></option><option value="text"><?= tt('ob.k_text') ?></option></select></div>
      <div class="field ob-f-rate" style="margin:0;"><label><?= tt('rl.card') ?></label><select id="ob-card" data-nosearch><option value=""><?= tt('tk.choose') ?></option><?php foreach ($cards as $k => $c): ?><option value="<?= e($k) ?>"><?= e(($c['card'] !== '' ? $c['card'] . ' · ' : '') . ($c['name'] ?: rl_section_label($c['section']))) ?></option><?php endforeach; ?></select></div>
      <div class="field ob-f-rate" style="margin:0;grid-column:span 2;"><label><?= tt('rl.item') ?></label><select name="rate_item_id" id="ob-item"><option value=""><?= tt('tk.choose') ?></option><?php foreach ($items as $it): ?><option value="<?= $it['id'] ?>" data-card="<?= e($it['section'] . ':' . $it['card']) ?>" data-pl="<?= $it['price_local'] !== null ? e($it['price_local']) : '' ?>" data-po="<?= $it['price_overseas'] !== null ? e($it['price_overseas']) : '' ?>" data-unit="<?= e($it['unit']) ?>"><?= e(($it['erp_code'] ? $it['erp_code'] . ' — ' : '') . mb_substr($it['description'], 0, 90) . ($it['price_local'] !== null ? ' · ' . number_format((float)$it['price_local'], 2, ',', '.') : '') . ($it['price_overseas'] !== null ? ' / ' . number_format((float)$it['price_overseas'], 2, ',', '.') : '')) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="grid grid-4" style="margin-top:8px;">
      <div class="field ob-f-free ob-f-text" style="margin:0;grid-column:span 2;"><label><?= tt('ob.c_desc') ?></label><input name="description" maxlength="400" id="ob-desc"></div>
      <div class="field ob-f-free" style="margin:0;"><label><?= tt('ob.c_unit') ?></label><input name="unit" maxlength="20" id="ob-unit" placeholder="hour / day / pcs"></div>
      <div class="field ob-f-rate" style="margin:0;"><label><?= tt('ob.zone') ?></label><select name="zone" id="ob-zone" data-nosearch><option value="local"><?= tt('ob.z_local') ?></option><option value="overseas"><?= tt('ob.z_overseas') ?></option></select></div>
      <div class="field ob-f-rate ob-f-free" style="margin:0;"><label><?= tt('ob.c_qty') ?></label><input name="qty" value="1" inputmode="decimal"></div>
      <div class="field ob-f-rate ob-f-free" style="margin:0;"><label><?= tt('ob.c_price') ?> (<?= e($cur) ?>)</label><input name="unit_price" id="ob-price" inputmode="decimal" placeholder="<?= tt('ob.price_auto') ?>"></div>
      <div class="field ob-f-rate ob-f-free" style="margin:0;"><label><?= tt('ob.c_disc') ?></label><input name="discount_pct" value="0" inputmode="decimal"></div>
      <div class="field ob-f-rate ob-f-free" style="margin:0;"><label><?= tt('th.remarks') ?></label><input name="notes" maxlength="255"></div>
    </div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('ob.add') ?></button></div>
  </form>
  <?php endif; ?>
</div>

<?php if ($canB): ?>
<div class="card" id="ob-texts">
  <div class="card-head"><h2><?= tt('ob.texts') ?></h2>
    <form method="post" action="<?= $self ?>#ob-texts" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="ob_blocks_reset"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt('ob.reset_confirm') ?>"><?= tt('ob.reset_texts') ?></button><?php if (is_cp_admin()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('admin_offer_texts') ?>"><?= tt('ob.texts_settings') ?> ›</a><?php endif; ?></form></div>
  <p class="hint" style="margin-top:-4px;"><?= tt('ob.texts_hint') ?> <code><?= implode('</code> <code>', array_map(fn($k) => '{' . $k . '}', OB_PLACEHOLDERS)) ?></code></p>
  <form method="post" action="<?= $self ?>#ob-texts" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="ob_blocks_save"><input type="hidden" name="id" value="<?= $id ?>">
    <?php foreach (OB_BLOCK_KEYS as $k): $b = null; foreach ($blocks as $x) if ($x['block_key'] === $k) $b = $x; ?>
      <details class="ob-block"<?= $b && trim($b['body']) !== '' ? ' open' : '' ?>><summary><b><?= e(ob_block_label($k)) ?></b> <span class="hint"><?= $b && trim($b['body']) !== '' ? mb_substr(preg_replace('/\s+/', ' ', $b['body']), 0, 80) . '…' : tt('ob.block_empty') ?></span><?= $k === 'scope' ? ' <span class="hint">· ' . tt('ob.lines_after') . '</span>' : '' ?></summary>
        <div class="field" style="margin-top:8px;"><label><?= tt('ob.block_title') ?></label><input name="bt[<?= $k ?>]" maxlength="120" value="<?= e($b['title'] ?? ob_block_label($k)) ?>"></div>
        <div class="field"><textarea name="bb[<?= $k ?>]" rows="5"><?= e($b['body'] ?? '') ?></textarea></div>
      </details>
    <?php endforeach; ?>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--sm" type="submit"><?= tt('ob.save_texts') ?></button></div>
  </form>
</div>

<div class="card" id="ob-doc">
  <div class="card-head"><h2><?= tt('ob.document') ?></h2><div class="btn-row">
    <a class="btn btn--ghost btn--sm" href="<?= url('offer_doc', ['id' => $id, 'fmt' => 'pdf']) ?>" target="_blank">⤓ <?= tt('ob.preview_pdf') ?></a>
    <a class="btn btn--ghost btn--sm" href="<?= url('offer_doc', ['id' => $id, 'fmt' => 'docx']) ?>">⤓ Word</a>
    <form method="post" action="<?= $self ?>#ob-doc" class="inline" data-lock data-busy="<?= tt('ar.issuing') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ob_issue"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= e(t($isDraft ? 'ob.issue_confirm_draft' : 'ob.issue_confirm', ['r' => 'R' . count($issued)])) ?>" data-confirm-title="<?= tt('dlg.title') ?>">⬆ <?= e(t('ob.issue', ['r' => 'R' . count($issued)])) ?></button></form>
  </div></div>
  <p class="hint" style="margin-top:-4px;"><?= tt($isDraft ? 'ob.doc_hint_draft' : 'ob.doc_hint') ?></p>
  <?php if ($issued): ?><table class="tbl"><thead><tr><th><?= tt('sub.rev') ?></th><th><?= tt('th.date') ?></th><th><?= tt('wv.by') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($issued as $r): ?><tr><td><b><?= e($r['revision']) ?></b></td><td><?= e(fmt_datetime($r['at'])) ?></td><td><?= e($r['by'] ?? '') ?></td><td><?php foreach ($r['files'] as $ext => $fid): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $fid]) ?>"<?= $ext === 'pdf' ? ' target="_blank"' : '' ?>>⤓ <?= strtoupper($ext) ?></a> <?php endforeach; ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var kind = document.getElementById('ob-kind'), card = document.getElementById('ob-card'), item = document.getElementById('ob-item'), zone = document.getElementById('ob-zone'), price = document.getElementById('ob-price'), unit = document.getElementById('ob-unit'), desc = document.getElementById('ob-desc');
  if (!kind) return;
  function show(){ var k = kind.value; document.querySelectorAll('.ob-add .field').forEach(function(f){ var on = f.classList.contains('ob-f-' + k) || !/ob-f-/.test(f.className); f.style.display = on ? '' : 'none'; }); if (desc) desc.required = (k !== 'rate'); }
  function filt(){ var c = card.value; Array.prototype.forEach.call(item.options, function(o){ if (!o.dataset.card) return; o.hidden = !!(c && o.dataset.card !== c); }); if (item.selectedOptions[0] && item.selectedOptions[0].hidden) item.value = ''; item.dispatchEvent(new Event('change')); sync(); }
  function sync(){ var o = item.selectedOptions[0]; if (!o || !o.dataset.card) { price.placeholder = '<?= tt('ob.price_auto') ?>'; return; } var p = zone.value === 'overseas' ? (o.dataset.po || o.dataset.pl) : (o.dataset.pl || o.dataset.po); price.placeholder = p ? p : '<?= tt('ob.price_manual') ?>'; if (unit) unit.value = o.dataset.unit || ''; }
  kind.addEventListener('change', show); card.addEventListener('change', filt); item.addEventListener('change', sync); zone.addEventListener('change', sync); show(); filt();
})();
</script>
<?php endif; ?>

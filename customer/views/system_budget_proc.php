<?php
/** Procurement › pricing form of one system budget: cost, delivery time, supplier, remark and alternative per part line.   GAS-CP-R97-049
 *  Shows the part lines only — no labour, no sale prices, no margins. */
require __DIR__ . '/_layout.php';
$u = sbp_require(); $id = (int)($_GET['id'] ?? 0); $B = sbp_get($id);
if (!$B) { http_response_code(404); layout_head(t('err.notfound'), 'system_budgets_proc'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$st = sbp_state($B); $ed = $st === 'requested' && in_array($B['status'], ['draft', 'working'], true); $ro = $ed ? '' : ' readonly'; $self = url('system_budget_proc', ['id' => $id]); $cur = (string)$B['currency'];
$n2 = fn($v) => $v !== null && (float)$v > 0 ? number_format((float)$v, 2, ',', '.') : ''; $w1 = fn($v) => $v !== null && (float)$v > 0 ? sbp_weeks((float)$v) : '';
layout_head($B['sb_no'] . ' · ' . t('sbp.q_title'), 'system_budgets_proc');
?>
<p class="eyebrow"><a href="<?= url('system_budgets_proc') ?>"><?= tt('sbp.q_title') ?></a> › <?= e($B['sb_no'] . ' R' . (int)$B['revision']) ?></p>
<div class="page-head"><div><h1>💶 <?= e($B['sb_no'] . ' R' . (int)$B['revision']) ?> <?= sbp_pill($B) ?></h1><p class="lede" style="margin-bottom:0;"><?= e((string)$B['title']) ?><?= $B['project_code'] ? ' · ' . e((string)$B['project_code']) : '' ?><?= $B['vessel_name'] ? ' · ' . e((string)$B['vessel_name']) : '' ?></p></div>
  <div class="btn-row"><?php if (function_exists('sb_can') && sb_can()): ?><a class="btn btn--ghost btn--sm" href="<?= url('system_budget', ['id' => $id]) ?>#procurement">🧮 <?= tt('sbp.to_budget') ?></a><?php endif; ?></div></div>
<p class="flash flash--<?= $ed ? 'info' : 'warn' ?>"><?= $ed ? e(t('sbp.f_info', ['n' => (string)($B['req_name'] ?? ''), 'd' => fmt_datetime((string)$B['proc_req_at'])])) . ($B['target_date'] ? ' · ' . e(t('sbp.f_target', ['d' => fmt_date((string)$B['target_date'])])) : '') . (trim((string)($B['proc_note'] ?? '')) !== '' ? ' — ' . e((string)$B['proc_note']) : '') : tt($st === 'done' ? 'sbp.f_done' : 'sbp.f_not_there') ?></p>
<div class="card">
  <div class="card-head"><h2>🔩 <?= tt('sb.parts') ?> <span class="count"><?= count($B['parts']) ?></span></h2><span class="hint"><?= tt('sbp.f_hint') ?></span></div>
  <form method="post" action="<?= $self ?>" data-lock data-pack><?= csrf_field() ?><input type="hidden" name="action" value="sbp_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th><?= tt('th.code') ?></th><th style="min-width:240px;"><?= tt('sb.p_descr') ?></th><th class="num"><?= tt('sb.qty') ?></th><th class="num"><?= e(t('sbp.c_price') . ' ' . $cur) ?></th><th class="num"><?= tt('sbp.c_lead') ?></th><th><?= tt('sbp.c_supplier') ?></th><th><?= tt('sbp.c_note') ?></th><th style="min-width:200px;"><?= tt('sbp.c_alt') ?></th><th class="num"><?= tt('sbp.c_alt_price') ?></th><th class="num"><?= tt('sbp.c_alt_lead') ?></th></tr></thead><tbody>
  <?php $i = 0; foreach ($B['parts'] as $p): $k = 'p[' . (int)$p['id'] . ']'; $x = sb_part_excluded($p) || (float)$p['qty'] <= 0; ?>
  <tr<?= $x ? ' class="hint"' : '' ?>><td class="hint"><?= ++$i ?></td><td class="mono"><?= e((string)$p['code']) ?></td>
    <td><?= e((string)$p['descr']) ?><?php if ((int)$p['item_id']): ?><br><span class="hint">📦 <?= e(trim((string)$p['item_code'] . ' · ' . (string)$p['maker'] . ' ' . (string)$p['factory_code'], ' ·')) ?><?= (float)$p['last_price'] > 0 ? ' · ' . e(t('sbp.f_last', ['p' => number_format((float)$p['last_price'], 2, ',', '.'), 'd' => $p['last_price_at'] ? fmt_date(substr((string)$p['last_price_at'], 0, 10)) : '—'])) : '' ?></span><?php endif; ?><?= trim((string)($p['remarks'] ?? '')) !== '' ? '<br><span class="hint">' . e((string)$p['remarks']) . '</span>' : '' ?><?= !empty($p['proc_at']) ? '<br><span class="hint">✔ ' . e(t('sbp.f_priced', ['n' => (string)($p['proc_name'] ?? ''), 'd' => fmt_datetime((string)$p['proc_at'])])) . '</span>' : '' ?></td>
    <td class="num"><?= sb_h((float)$p['qty']) ?> <?= e((string)$p['unit']) ?></td>
    <?php if ($x): ?><td colspan="7" class="hint"><?= tt('sbp.f_excluded') ?></td><?php else: ?>
    <td class="num"><input name="<?= $k ?>[unit_cost]" inputmode="decimal" value="<?= e($n2($p['unit_cost'])) ?>" style="width:96px;text-align:right;<?= empty($p['proc_at']) ? 'border-color:var(--gold-deep);' : '' ?>"<?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[lead_weeks]" inputmode="decimal" value="<?= e($w1($p['lead_weeks'])) ?>" style="width:60px;text-align:right;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[proc_supplier]" maxlength="120" value="<?= e((string)($p['proc_supplier'] ?? '')) ?>" style="width:140px;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[proc_note]" maxlength="300" value="<?= e((string)($p['proc_note'] ?? '')) ?>" style="width:170px;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[alt_descr]" maxlength="300" value="<?= e((string)($p['alt_descr'] ?? '')) ?>" style="width:100%;min-width:200px;"<?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[alt_cost]" inputmode="decimal" value="<?= e($n2($p['alt_cost'])) ?>" style="width:90px;text-align:right;"<?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[alt_lead_weeks]" inputmode="decimal" value="<?= e($w1($p['alt_lead_weeks'])) ?>" style="width:60px;text-align:right;"<?= $ro ?>></td><?php endif; ?></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($ed): ?><div class="field" style="margin-top:10px;max-width:640px;"><label><?= tt('sbp.f_note') ?></label><input name="note" maxlength="300"></div>
  <div class="btn-row"><button class="btn btn--sm" type="submit">✓ <?= tt('sbp.f_save') ?></button><button class="btn btn--gold btn--sm" type="submit" name="return" value="1" data-js-confirm="<?= tt('sbp.f_return_q') ?>">➜ <?= tt('sbp.f_return') ?></button></div><?php endif; ?>
  </form>
</div>
<?php layout_foot();

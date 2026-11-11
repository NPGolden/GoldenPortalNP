<?php
/** System Budget — review of staged part rows: a BOM file, or inventory proposals for the free lines (GAS-CP-R97-003). Nothing is written until Apply. ?p=system_budget_bom&id=N */
require __DIR__ . '/_layout.php';
$u = sb_require(); $id = (int)($_GET['id'] ?? 0); $B = sb_get($id);
if (!$B || !function_exists('sb_r3') || !sb_r3()) { http_response_code(404); layout_head(t('err.notfound'), 'system_budgets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$rows = sb_stage_rows($id); $ed = sb_editable($B); $self = url('system_budget_bom', ['id' => $id]); $back = url('system_budget', ['id' => $id]) . '#parts';
$isProp = $rows && (int)$rows[0]['part_id'] > 0; $n2 = fn(float $v) => number_format($v, 2, ',', '.');
$nM = 0; $nAi = 0; $nNone = 0; foreach ($rows as $r) { if ($r['match_kind'] === 'ai') $nAi++; elseif ($r['match_kind'] === 'none') $nNone++; else $nM++; }
$room = SB_MAX_PARTS - count($B['parts']);
layout_head(t($isProp ? 'sb.prop_title' : 'sb.bom_title') . ' · ' . sb_label($B), 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <a href="<?= $back ?>"><?= e(sb_label($B)) ?></a> › <?= tt($isProp ? 'sb.prop_title' : 'sb.bom_title') ?></p>
<div class="page-head"><div><h1><?= $isProp ? '🔎' : '📑' ?> <?= tt($isProp ? 'sb.prop_title' : 'sb.bom_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= e($B['title']) ?> — <?= tt('sb.stage_lede') ?></p></div></div>
<?php if (!$rows || !$ed): ?>
<div class="card"><p class="hint"><?= tt('sb.stage_empty') ?></p><a class="btn btn--sm" href="<?= $back ?>">‹ <?= tt('sb.back') ?></a></div>
<?php else: ?>
<div class="kpi kpi--4">
  <div class="stat"><b><?= count($rows) ?></b><?= tt('sb.stage_k_rows') ?></div>
  <div class="stat green"><b><?= $nM ?></b><?= tt('sb.stage_k_match') ?></div>
  <div class="stat gold"><b><?= $nAi ?></b><?= tt('sb.stage_k_ai') ?></div>
  <div class="stat"><b><?= $nNone ?></b><?= tt('sb.stage_k_none') ?></div>
</div>
<?php if (!$isProp && count($rows) > $room): ?><p class="flash flash--warn"><?= e(t('sb.stage_room', ['n' => count($rows), 'r' => max(0, $room), 'm' => SB_MAX_PARTS])) ?></p><?php endif; ?>
<form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_stage_apply"><input type="hidden" name="id" value="<?= $id ?>">
<div class="card">
  <div class="card-head"><h2><?= tt('sb.stage_rows') ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= tt($isProp ? 'sb.stage_hint_prop' : 'sb.stage_hint_bom') ?></span></div>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th style="min-width:240px;"><?= tt($isProp ? 'sb.stage_line' : 'sb.stage_file_row') ?></th><th class="num"><?= tt('sb.qty') ?></th><th class="num"><?= tt('sb.unit_cost') ?></th><th style="min-width:260px;"><?= tt('sb.stage_proposal') ?></th><th><?= tt('sb.stage_how') ?></th><th><?= tt('sb.stage_other') ?></th><th style="min-width:210px;"><?= tt('sb.stage_decision') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $rid = (int)$r['id']; $has = (int)$r['item_id'] > 0; $det = $has && $r['match_kind'] !== 'ai'; $def = $det ? 'accept' : ($isProp ? 'skip' : 'free');
    $ic = $has ? sb_item_cost(['id' => (int)$r['item_id'], 'last_price' => $r['last_price'], 'last_price_at' => $r['last_price_at'], 'avg_cost' => $r['avg_cost']]) : null; ?>
  <tr>
    <td class="hint"><?= (int)$r['seq'] ?></td>
    <td><?= e((string)$r['description']) ?><?php if ($r['maker'] || $r['part_no'] || $r['code'] || $r['section']): ?><br><span class="hint"><?= e(trim(($r['section'] ? $r['section'] . ' · ' : '') . ($r['maker'] ? $r['maker'] . ' · ' : '') . ($r['part_no'] ? 'P/N ' . $r['part_no'] . ' · ' : '') . ($r['code'] ? '[' . $r['code'] . ']' : ''), ' ·')) ?></span><?php endif; ?></td>
    <td class="num"><?= e(sb_h((float)$r['qty'])) ?> <span class="hint"><?= e((string)$r['unit']) ?></span></td>
    <td class="num"><?= $r['unit_cost'] !== null ? e($n2((float)$r['unit_cost'])) : '<span class="hint">—</span>' ?></td>
    <td><?php if ($has): ?><a href="<?= url('inventory_item', ['id' => (int)$r['item_id']]) ?>" class="mono"><b><?= e((string)$r['item_code']) ?></b></a> <?= e((string)$r['item_desc']) ?><br><span class="hint"><?= e(trim(($r['item_maker'] ? $r['item_maker'] . ' · ' : '') . ($r['item_pn'] ? 'P/N ' . $r['item_pn'] . ' · ' : '') . ($ic[0] > 0 ? $n2($ic[0]) . ' — ' : '') . $ic[1], ' ·')) ?></span><?php else: ?><span class="hint"><?= tt('sb.stage_no_match') ?></span><?php endif; ?></td>
    <td><?php if ($has): ?><?= pill($r['match_kind'] === 'ai' ? 'gold' : 'green', $r['match_kind'] === 'ai' ? 'AI' : t('sb.stage_exact')) ?> <span class="hint"><?= (int)$r['confidence'] ?> %</span><?php endif; ?><?= $r['reason'] ? '<br><span class="hint">' . e((string)$r['reason']) . '</span>' : '' ?></td>
    <td><input name="code[<?= $rid ?>]" maxlength="60" placeholder="<?= tt('sb.stage_code_ph') ?>" style="width:120px;"></td>
    <td style="white-space:nowrap;"><label style="margin-right:8px;"><input type="radio" name="d[<?= $rid ?>]" value="accept"<?= $def === 'accept' ? ' checked' : '' ?>> <?= tt('sb.stage_accept') ?></label><?php if (!$isProp): ?><label style="margin-right:8px;"><input type="radio" name="d[<?= $rid ?>]" value="free"<?= $def === 'free' ? ' checked' : '' ?>> <?= tt('sb.stage_free') ?></label><?php endif; ?><label><input type="radio" name="d[<?= $rid ?>]" value="skip"<?= $def === 'skip' ? ' checked' : '' ?>> <?= tt($isProp ? 'sb.stage_keep' : 'sb.stage_skip') ?></label></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0 0;"><?= tt($isProp ? 'sb.stage_foot_prop' : 'sb.stage_foot_bom') ?></p>
</div>
<div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('sb.stage_apply') ?></button><a class="btn btn--ghost" href="<?= $back ?>"><?= tt('sb.stage_later') ?></a></div>
</form>
<form method="post" action="<?= $self ?>" data-lock data-confirm="<?= tt('sb.stage_discard_confirm') ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_stage_discard"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑 <?= tt('sb.stage_discard') ?></button></form>
<?php endif; ?>
<?php layout_foot(); ?>

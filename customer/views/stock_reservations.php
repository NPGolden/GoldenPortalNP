<?php
/** Inventory › Reservations (GAS-CP-R97-213): who holds reserved stock, release, and the check of reserved quantities against the requisitions.  ?p=stock_reservations */
require __DIR__ . '/_layout.php';
$u = require_staff();
$full = function_exists('pr_wh') && pr_wh(); $pmOnly = !$full && function_exists('prq_is_pm') && prq_is_pm();   // R97-214: a project manager sees the reservations of the own projects
if (!function_exists('prq_reserve_audit') || !prq_has() || !($full || $pmOnly)) { http_response_code(403); layout_head(t('prq.rsv_title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$rows = prq_reservations($full ? null : (int)$u['id']); $a = $full ? prq_reserve_audit() : ['diff' => [], 'stale' => []]; $adm = $full && pr_admin(); $bad = count($a['diff']) + count($a['stale']); $lastRun = explode('|', (string)cp_setting('prq_rsv_last', ''));
layout_head(t('prq.rsv_title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a> › <?= tt('prq.rsv_title') ?></p>
<h1>■ <?= tt('prq.rsv_title') ?></h1><p class="lede"><?= tt($pmOnly ? 'prq.rsv_lede_pm' : 'prq.rsv_lede') ?></p>

<?php if ($full): ?><div class="card" id="check"><div class="card-head"><h2><?= $bad ? '⚠' : '✓' ?> <?= tt('prq.rsv_check') ?></h2><span class="hint"><?= tt('prq.rsv_check_hint') ?></span></div>
<?php if (!$bad): ?><p><?= pill('green', t('prq.rsv_ok')) ?></p><?php else: ?>
  <?php if ($a['diff']): ?><div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('stk.code') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('stk.warehouse') ?></th><th class="num"><?= tt('stk.on_hand') ?></th><th class="num"><?= tt('prq.rsv_c_stock') ?></th><th class="num"><?= tt('prq.rsv_c_lines') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($a['diff'] as $d): ?><tr><td><a href="<?= url('inventory_item', ['id' => $d['item_id']]) ?>"><b><?= e((string)$d['code']) ?></b></a></td><td><?= e(mb_substr((string)$d['description'], 0, 90)) ?></td><td><?= e((string)$d['wh_code']) ?></td><td class="num"><?= e(stk_qty($d['on_hand'])) ?> <?= e((string)$d['unit']) ?></td><td class="num"><?= e(stk_qty($d['stock'])) ?></td><td class="num"><?= e(stk_qty($d['lines'])) ?></td><td><?= $d['short'] ? pill('red', t('prq.rsv_short')) : pill('gold', t('prq.rsv_differs')) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($a['stale']): ?><p class="hint" style="margin-top:10px;"><?= tt('prq.rsv_stale') ?> <?php foreach ($a['stale'] as $x): ?><a href="<?= url('requisition', ['id' => (int)$x['request_id']]) ?>"><?= e((string)$x['pr_no']) ?></a> (<?= e((string)$x['code']) ?> × <?= e(stk_qty((float)$x['qty_reserved'])) ?>) <?php endforeach; ?></p><?php endif; ?>
  <?php if ($adm): ?><form method="post" action="<?= url('stock_reservations') ?>" data-lock data-confirm="<?= e(t('prq.rsv_repair_q')) ?>" style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="prq_reserve_repair"><button class="btn btn--gold btn--sm" type="submit">🛠 <?= tt('prq.rsv_repair') ?></button> <span class="hint"><?= tt('prq.rsv_repair_hint') ?></span></form><?php else: ?><p class="hint"><?= tt('prq.rsv_repair_who') ?></p><?php endif; ?>
<?php endif; ?>
<p class="hint" style="margin-top:10px;"><?= count($lastRun) === 2 ? e(t('prq.rsv_daily_last', ['when' => fmt_datetime($lastRun[0] . ':00'), 'n' => (int)$lastRun[1]])) : tt('prq.rsv_daily_none') ?></p></div><?php endif; ?>

<div class="card"><div class="card-head"><h2><?= tt('prq.rsv_held') ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= tt('prq.rsv_held_hint') ?></span></div>
<div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('stk.code') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('stk.warehouse') ?></th><th class="num"><?= tt('stk.reserved') ?></th><th><?= tt('prq.rsv_for') ?></th><th><?= tt('prq.project') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= tt('prq.rsv_none') ?></td></tr><?php endif; foreach ($rows as $l): ?>
<tr><td><?php if ($l['item_id']): ?><a href="<?= url('inventory_item', ['id' => (int)$l['item_id']]) ?>"><b><?= e((string)$l['item_code']) ?></b></a><?php else: ?>—<?php endif; ?></td><td><?= e(mb_substr((string)$l['item_desc'], 0, 90)) ?></td><td><?= e((string)($l['wh_code'] ?? '—')) ?></td><td class="num"><b><?= e(stk_qty((float)$l['qty_reserved'])) ?></b> <?= e((string)$l['unit']) ?></td>
  <td><a href="<?= url('requisition', ['id' => (int)$l['request_id']]) ?>"><?= e((string)$l['pr_no']) ?></a><br><span class="hint"><?= e((string)$l['requester_name']) ?> · <?= e(fmt_date((string)$l['created_at'])) ?></span></td><td><?= e(trim((string)$l['project_code'] . ' ' . (string)$l['sub_code'])) ?></td><td><?= prq_pill((string)$l['r_status']) ?></td>
  <td class="num"><?php if ($adm && $l['r_status'] === 'approved'): ?><form method="post" action="<?= url('stock_reservations') ?>" style="display:inline" data-lock data-prompt="<?= e(t('prq.rel_q', ['q' => stk_qty((float)$l['qty_reserved'])])) ?>" data-prompt-field="note"><?= csrf_field() ?><input type="hidden" name="action" value="prq_line_release"><input type="hidden" name="id" value="<?= (int)$l['request_id'] ?>"><input type="hidden" name="line_id" value="<?= (int)$l['line_id'] ?>"><input type="hidden" name="back" value="list"><input type="hidden" name="note" value=""><button class="btn btn--ghost btn--sm" type="submit" title="<?= e(t('prq.rel_hint')) ?>">↩ <?= tt('prq.rel_btn') ?></button></form><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php layout_foot();

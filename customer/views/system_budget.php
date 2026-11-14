<?php
/** System Budget — one budget: header, description, labour, parts, other costs, totals (cost and sale side by side), comments, history (GAS-CP-R97-001). ?p=system_budget&id=N */
require __DIR__ . '/_layout.php';
$u = sb_require(); $id = (int)($_GET['id'] ?? 0); $B = sb_get($id);
if (!$B) { http_response_code(404); layout_head(t('err.notfound'), 'system_budgets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$CUR = $B; $rvN = isset($_GET['rev']) && $_GET['rev'] !== '' ? (int)$_GET['rev'] : null; $isRev = false;
if ($rvN !== null) { $V = sb_revision_get($B, $rvN); if ($V) { $B = $V; $isRev = true; } }   // R97-002: a stored revision is shown read-only
$tpl = !empty($B['is_template']); $r2 = function_exists('sb_r2') && sb_r2(); $revs = $r2 && !$tpl ? sb_revisions($id) : []; $tkL = !empty($B['ticket_id']) && function_exists('tk_load') ? one('SELECT id, ticket_no, title FROM tickets WHERE id = ?', [(int)$B['ticket_id']]) : null;
$ofL = !empty($CUR['offer_id']) ? one('SELECT id, offer_no, revision, status FROM offers WHERE id = ?', [(int)$CUR['offer_id']]) : null;   // R97-004: the offer this budget became
$prOn = function_exists('sb_prio') && sb_prio();   // R97-006
$ed = !$isRev && sb_editable($B); $T = sb_totals($B); $cur = (string)$B['currency']; $self = url('system_budget', ['id' => $id]);
$dOn = function_exists('sb_disc') && sb_disc(); $sec = sb_sections(); $dis = sb_disciplines(); $cards = sb_cards((string)$B['zone']); $staff = staff_users(); $blk = $ed && !$tpl ? sb_finish_blockers($B) : [];
$subs = $ed ? all("SELECT s.id, s.code, s.name, p.project_code, p.name AS pname FROM subprojects s JOIN projects p ON p.id = s.project_id ORDER BY p.project_code DESC, s.sort_order, s.code LIMIT 3000") : [];
$n2 = fn(float $v) => number_format($v, 2, ',', '.'); $hv = fn($v) => (float)$v > 0 ? sb_h((float)$v) : ''; $ro = $ed ? '' : ' readonly';
$r3 = function_exists('sb_r3') && sb_r3(); $aiOn = $r3 && sb_ai_on(); $nStaged = $r3 && !$isRev ? (int) scalar('SELECT COUNT(*) FROM sb_import_rows WHERE budget_id = ?', [$id]) : 0;
$aiDraft = !$isRev && $ed && isset($_SESSION['sb_ai'][$id]) ? (string)$_SESSION['sb_ai'][$id] : null; unset($_SESSION['sb_ai'][$id]);
$nFree = 0; foreach ($B['parts'] as $fp) if (!(int)($fp['item_id'] ?? 0) && !sb_part_excluded($fp) && trim((string)$fp['descr']) !== '') $nFree++;
$nNewL = 3; $nNewP = min(5, max(0, SB_MAX_PARTS - count($B['parts']))); $com = sb_comments($id); $ev = sb_events($id);
layout_head(sb_label($B) . ' · ' . $B['title'], 'system_budgets');
?>
<p class="eyebrow"><a href="<?= url('system_budgets') ?>"><?= tt('sb.title') ?></a> › <?= e(sb_label($B)) ?></p>
<div class="page-head">
  <div><h1>🧮 <?= e(sb_label($B)) ?> <?= sb_pill($B['status']) ?><?php if ($prOn && !$tpl && in_array($CUR['status'], ['draft', 'working', 'finished'], true)): ?> <?= sb_prio_pill((string)$CUR['priority']) ?><?php endif; ?></h1><p class="lede" style="margin-bottom:0;"><?= e($B['title']) ?><?= $B['source_ref'] ? ' · <span class="hint">' . e(t('sb.from_file', ['r' => (string)$B['source_ref']])) . '</span>' : '' ?><?= $tkL ? ' · <a href="' . url('ticket', ['id' => (int)$tkL['id']]) . '">🎫 ' . e((string)$tkL['ticket_no']) . '</a>' : '' ?><?= $tpl ? ' ' . pill('navy', t('sb.tpl_one')) : '' ?></p></div>
  <div class="btn-row">
    <?php if ($prOn && !$isRev && !$tpl && in_array($CUR['status'], ['draft', 'working', 'finished'], true)): ?><form method="post" action="<?= $self ?>" class="inline" data-lock style="display:inline-flex;gap:6px;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_priority"><input type="hidden" name="id" value="<?= $id ?>"><select name="priority" data-nosearch aria-label="<?= tt('sb.priority') ?>" style="width:auto;"><?php foreach (SB_PRIORITIES as $pk): ?><option value="<?= $pk ?>"<?= (string)$CUR['priority'] === $pk ? ' selected' : '' ?>><?= tt('sb.pr_' . $pk) ?></option><?php endforeach; ?></select><button class="btn btn--ghost btn--sm" type="submit"><?= tt('sb.pr_set') ?></button></form><?php endif; ?>
    <?php if ($r2): ?><a class="btn btn--ghost btn--sm" href="<?= url('system_budget_export', ['id' => $id] + ($isRev ? ['rev' => $rvN] : [])) ?>">⬇ Excel</a><?php endif; ?>
    <?php if ($isRev): ?><a class="btn btn--sm" href="<?= $self ?>">‹ <?= e(t('sb.rev_current', ['r' => 'R' . (int)$CUR['revision']])) ?></a>
      <?php if (sb_editable($CUR)): ?><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= e(t('sb.restore_confirm', ['r' => 'R' . $rvN])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_restore"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="rev" value="<?= $rvN ?>"><button class="btn btn--gold btn--sm" type="submit">↺ <?= tt('sb.restore') ?></button></form><?php endif; ?>
    <?php elseif ($ed && !$tpl): ?>
      <form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="finished"><button class="btn btn--gold btn--sm" type="submit"<?= $blk ? ' disabled title="' . e(implode(' ', $blk)) . '"' : '' ?>>✔ <?= tt('sb.finish') ?></button></form>
      <form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('sb.cancel_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="cancelled"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('sb.cancel_it') ?></button></form>
    <?php elseif (!$tpl && $B['status'] === 'finished' && function_exists('sb_can_convert') && sb_can_convert()): ?>
      <a class="btn btn--gold btn--sm" href="<?= url('system_budget_convert', ['id' => $id]) ?>">➜ <?= tt('sb.cv_title') ?></a>
    <?php endif; ?>
    <?php if ($isRev || $ed): ?>
    <?php elseif ($B['status'] === 'converted' && sb_can_reopen() && !$ofL): ?>
      <form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="working"><button class="btn btn--sm" type="submit">↺ <?= tt('sb.reopen') ?></button></form>
    <?php elseif (in_array($B['status'], ['finished', 'cancelled'], true) && sb_can_reopen()): ?>
      <form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="to" value="working"><button class="btn btn--sm" type="submit">↺ <?= tt('sb.reopen') ?></button></form>
    <?php endif; ?>
    <?php if ($tpl && sb_can_reopen()): ?><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('sb.delete_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_tpl_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑 <?= tt('btn.delete') ?></button></form><?php endif; ?>
    <?php if (!$isRev && !$tpl && is_cp_admin() && in_array($B['status'], ['draft', 'cancelled'], true)): ?><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('sb.delete_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="sb_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑 <?= tt('btn.delete') ?></button></form><?php endif; ?>
  </div>
</div>
<?php if ($ed && $blk): ?><p class="flash flash--info"><?= e(t('sb.to_finish') . ' ' . implode(' ', $blk)) ?></p><?php endif; ?>
<?php if (!$isRev && !$tpl && in_array($B['status'], ['draft', 'working', 'finished'], true) && function_exists('sb_feed_plan')): $FPu = sb_feed_plan($B)['unmapped']; if ($FPu): ?><p class="flash flash--warn"><?= e(t('sb.blk_nosec', ['n' => count($FPu)])) ?></p><?php endif; endif; ?>
<?php if ($isRev): ?><p class="flash flash--info"><?= e(t('sb.rev_viewing', ['r' => 'R' . $rvN, 'd' => fmt_datetime((string)$B['_rev']['created_at']), 'c' => 'R' . (int)$CUR['revision']])) ?><?= $B['_rev']['note'] ? ' — ' . e((string)$B['_rev']['note']) : '' ?></p>
<?php elseif (!$ed): ?><p class="flash flash--info"><?= tt($B['status'] === 'finished' ? 'sb.locked_finished' : 'sb.locked') ?></p><?php endif; ?>
<?php if ($tpl): ?><p class="flash flash--info"><?= tt('sb.tpl_banner') ?></p><?php endif; ?>
<?php if (!$isRev && $CUR['status'] === 'converted' && function_exists('sb_feed_state') && sb_fed_col() && (int)$CUR['subproject_id'] && !empty($CUR['budget_rev_id'])): $FS = sb_feed_state($CUR); if ($FS['rest_hours'] > 0.005): ?><p class="flash flash--warn"><?= e(t('sb.fd_warn', ['r' => sb_h($FS['rest_hours']), 'a' => sb_h($FS['all']), 'n' => count($FS['rest'])])) ?> <a href="<?= url('system_budget_feed', ['id' => $id]) ?>"><b><?= tt('sb.fd_open') ?> ›</b></a></p><?php endif; endif; ?>
<?php if (!$isRev && $CUR['status'] === 'converted'): ?><p class="flash flash--good"><?= $ofL ? e(t('sb.cv_done', ['d' => fmt_date(substr((string)($CUR['converted_at'] ?? ''), 0, 10))])) . ' <a href="' . url('offer', ['id' => (int)$ofL['id']]) . '"><b>' . e($ofL['offer_no'] . ' ' . $ofL['revision']) . '</b></a> ' . (function_exists('of_status_pill') ? of_status_pill((string)$ofL['status']) : '') : tt('sb.cv_offer_gone') ?><?= !empty($CUR['budget_rev_id']) && (int)$CUR['subproject_id'] ? ' · <a href="' . url('subproject', ['id' => (int)$CUR['subproject_id'], 'tab' => 'budget']) . '">' . tt('sb.cv_bg_link') . '</a>' : '' ?></p><?php endif; ?>

<?php if ($r2): ?><div class="card" id="revisions">
  <div class="card-head"><h2>🗂 <?= tt($tpl ? 'sb.use_title' : 'sb.rev_title') ?><?php if (!$tpl): ?> <span class="count"><?= count($revs) + 1 ?></span><?php endif; ?></h2><span class="hint"><?= tt($tpl ? 'sb.use_hint' : 'sb.rev_hint') ?></span></div>
  <?php if (!$tpl): ?><div class="table-scroll"><table class="tbl" data-nosort style="max-width:1000px;"><thead><tr><th><?= tt('sb.rev') ?></th><th class="date"><?= tt('sb.rev_closed') ?></th><th><?= tt('th.by') ?></th><th><?= tt('sb.rev_note') ?></th><th class="num"><?= tt('sb.hours') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th></th></tr></thead><tbody>
    <tr><td><b>R<?= (int)$CUR['revision'] ?></b> <?= pill('gold', t('sb.rev_cur')) ?></td><td class="date hint">—</td><td></td><td></td><td class="num"><?= e(sb_h((float)$CUR['tot_hours'])) ?></td><td class="num"><?= e(sb_money((float)$CUR['tot_cost'], $cur)) ?></td><td class="num"><b><?= e(sb_money((float)$CUR['tot_sale'], $cur)) ?></b></td><td><?= $isRev ? '<a class="btn btn--ghost btn--sm" href="' . $self . '">' . tt('btn.open') . '</a>' : '' ?></td></tr>
    <?php foreach ($revs as $rv): ?><tr<?= $isRev && (int)$rv['revision'] === $rvN ? ' style="background:rgba(200,155,60,.10);"' : '' ?>><td><b>R<?= (int)$rv['revision'] ?></b></td><td class="date"><?= e(fmt_datetime((string)$rv['created_at'])) ?></td><td class="hint"><?= e((string)$rv['full_name']) ?></td><td class="hint"><?= e((string)$rv['note']) ?></td><td class="num"><?= e(sb_h((float)$rv['tot_hours'])) ?></td><td class="num"><?= e(sb_money((float)$rv['tot_cost'], $cur)) ?></td><td class="num"><?= e(sb_money((float)$rv['tot_sale'], $cur)) ?></td><td><a class="btn btn--ghost btn--sm" href="<?= url('system_budget', ['id' => $id, 'rev' => (int)$rv['revision']]) ?>"><?= tt('btn.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <div class="grid grid-2" style="align-items:end;margin-top:10px;">
    <?php if ($ed && !$tpl): ?><form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_revise"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label><?= e(t('sb.rev_new_lbl', ['r' => 'R' . (int)$CUR['revision'], 'n' => 'R' . ((int)$CUR['revision'] + 1)])) ?></label><div style="display:flex;gap:8px;"><input name="note" maxlength="200" placeholder="<?= tt('sb.rev_note_ph') ?>"><button class="btn btn--sm" type="submit" style="white-space:nowrap;">＋ <?= tt('sb.rev_new') ?></button></div></div>
    </form><?php else: ?><div></div><?php endif; ?>
    <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_copy"><input type="hidden" name="id" value="<?= $id ?>"><?php if ($isRev): ?><input type="hidden" name="rev" value="<?= $rvN ?>"><?php endif; ?>
      <div class="field"><label><?= tt($tpl ? 'sb.use_lbl' : 'sb.copy_lbl') ?></label><div style="display:flex;gap:8px;"><input name="title" maxlength="200" placeholder="<?= tt('sb.copy_ph') ?>"><button class="btn btn--sm" type="submit" style="white-space:nowrap;">⧉ <?= tt($tpl ? 'sb.use_btn' : 'sb.copy_btn') ?></button><?php if (!$tpl): ?><button class="btn btn--ghost btn--sm" type="submit" name="template" value="1" style="white-space:nowrap;">★ <?= tt('sb.tpl_save') ?></button><?php endif; ?></div></div>
    </form>
  </div>
</div><?php endif; ?>

<div class="card" id="totals">
  <div class="card-head"><h2>Σ <?= tt('sb.totals') ?></h2><span class="hint"><?= tt('sb.totals_hint') ?></span></div>
  <?php $sbMin = rtrim(rtrim(number_format(sb_min_profit(), 1, ',', ''), '0'), ','); $sbLowN = 0; foreach ($B['labour'] as $lx) { $cx = sb_labour_calc($B, $lx); if (sb_low($cx['cost'], $cx['sale'])) $sbLowN++; } foreach ($B['parts'] as $px) { if (sb_part_excluded($px)) continue; $cx = sb_part_calc($B, $px); if (sb_low($cx['cost'], $cx['sale'])) $sbLowN++; } ?>
  <?php if (sb_low($T['cost'], $T['sale'])): ?><p class="flash flash--bad sb-low-banner" style="margin:0 0 10px;">▼ <b><?= e(t('sb.low_banner', ['p' => number_format((float)sb_profit_pct($T['cost'], $T['sale']), 1, ',', ''), 'm' => $sbMin])) ?></b><?= $sbLowN ? ' ' . e(t('sb.low_lines', ['n' => $sbLowN])) : '' ?></p>
  <?php elseif ($sbLowN): ?><p class="flash flash--info sb-low-banner" style="margin:0 0 10px;"><span class="sb-low">▼</span> <?= e(t('sb.low_lines_only', ['n' => $sbLowN, 'm' => $sbMin])) ?></p><?php endif; ?>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:900px;"><thead><tr><th><?= tt('sb.group') ?></th><th class="num"><?= tt('sb.hours') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th class="num" title="<?= tt('sb.markup_hint') ?>"><?= tt('sb.markup') ?></th><th class="num" title="<?= tt('sb.margin_hint') ?>"><?= tt('sb.margin') ?></th></tr></thead><tbody>
    <?php foreach ([['sb.labour', $T['hours'], $T['lab_cost'], $T['lab_sale']], ['sb.parts', null, $T['parts_cost'], $T['parts_sale']], ['sb.expenses', null, $T['exp_cost'], $T['exp_sale']]] as [$k, $h, $c, $s]): ?>
    <tr><td><?= tt($k) ?></td><td class="num"><?= $h === null ? '' : e(sb_h($h)) ?></td><td class="num"><?= e(sb_money($c, $cur)) ?></td><td class="num"><?= e(sb_money($s, $cur)) ?></td><td class="num <?= $k !== 'sb.expenses' && sb_low((float)$c, (float)$s) ? 'sb-low-cell' : 'hint' ?>"><?= $c > 0.005 ? e(number_format(100 * ($s - $c) / $c, 1, ',', '')) . ' %' : '—' ?><?= $k !== 'sb.expenses' ? sb_low_mark((float)$c, (float)$s) : '' ?></td><td class="num hint"><?= $s > 0.005 ? e(number_format(100 * ($s - $c) / $s, 1, ',', '')) . ' %' : '—' ?></td></tr><?php endforeach; ?>
    <tr><td><b><?= tt('sb.total') ?></b></td><td class="num"><b><?= e(sb_h($T['hours'])) ?></b></td><td class="num"><b><?= e(sb_money($T['cost'], $cur)) ?></b></td><td class="num"><b><?= e(sb_money($T['sale'], $cur)) ?></b></td><td class="num<?= sb_low($T['cost'], $T['sale']) ? ' sb-low-cell' : '' ?>"><b><?= $T['cost'] > 0.005 ? e(number_format(100 * ($T['sale'] - $T['cost']) / $T['cost'], 1, ',', '')) . ' %' : '—' ?></b><?= sb_low_mark($T['cost'], $T['sale']) ?></td><td class="num"><b><?= e(number_format($T['margin_pct'], 1, ',', '')) ?> %</b></td></tr>
    <?php if (($T['disc'] ?? 0) > 0.005): ?><tr class="hint"><td><?= tt('sb.disc_total') ?></td><td></td><td></td><td class="num">−<?= e(sb_money($T['disc'], $cur)) ?></td><td></td><td></td></tr><?php endif; ?>
    <?php if ((float)$B['commission_pct'] > 0): ?><tr><td><?= e(t('sb.with_comm', ['p' => rtrim(rtrim(number_format((float)$B['commission_pct'], 1, ',', ''), '0'), ',')])) ?></td><td></td><td></td><td class="num"><?= e(sb_money($T['with_commission'], $cur)) ?></td><td></td><td></td></tr><?php endif; ?>
    <?php if ($T['out_sale'] > 0.005 || $T['out_cost'] > 0.005): ?><tr class="hint"><td><?= tt('sb.outside') ?></td><td></td><td class="num"><?= e(sb_money($T['out_cost'], $cur)) ?></td><td class="num"><?= e(sb_money($T['out_sale'], $cur)) ?></td><td></td><td></td></tr><?php endif; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0 0;"><?= tt('sb.markup_note') ?></p>
  <p class="hint" style="margin:4px 0 0;"><?= e(t('sb.hours_split', ['n' => sb_h($T['h_normal']), 't' => sb_h($T['h_travel']), 'o' => sb_h($T['h_overtime']), 'd' => sb_h($T['days'])])) ?></p>
</div>

<div class="card" id="head">
  <h2>⚙ <?= tt('sb.header') ?></h2>
  <form method="post" action="<?= $self ?>#head" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_head"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-3">
      <div class="field" style="grid-column:span 2;"><label><?= tt('sb.f_title') ?> *</label><input name="title" maxlength="200" required value="<?= e($B['title']) ?>"<?= $ro ?>></div>
      <div class="field"><label><?= tt('sb.owner') ?></label><select name="owner_user_id"<?= $ed ? '' : ' disabled' ?>><option value="0">—</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$B['owner_user_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('sb.subproject') ?></label><?php if ($ed): ?><select name="subproject_id"><option value="0">—</option><?php foreach ($subs as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$B['subproject_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['project_code'] . ' · ' . $s['pname'] . ' › ' . $s['code'] . ' ' . $s['name']) ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('sb.sub_hint') ?></p><?php else: ?><input readonly value="<?= e(trim((string)$B['project_code'] . ' · ' . (string)$B['project_name'] . ' › ' . (string)$B['sub_code'] . ' ' . (string)$B['sub_name'], ' ·›')) ?>"><?php endif; ?></div>
      <div class="field"><label><?= tt('sb.target') ?></label><input type="date" name="target_date" value="<?= e((string)$B['target_date']) ?>"<?= $ro ?>></div>
      <div class="field"><label><?= tt('sb.parts_margin') ?></label><input name="parts_margin" inputmode="decimal" value="<?= e($n2((float)$B['parts_margin'])) ?>"<?= $ro ?>><p class="hint" style="margin:4px 0 0;"><?= tt('sb.parts_margin_hint') ?></p></div>
      <div class="field"><label><?= tt('sb.flat') ?></label><div style="display:flex;gap:8px;align-items:center;"><label style="display:flex;gap:6px;align-items:center;margin:0;"><input type="checkbox" name="flat_on" value="1"<?= (int)$B['flat_on'] ? ' checked' : '' ?><?= $ed ? '' : ' disabled' ?>> <?= tt('sb.flat_use') ?></label><input name="flat_rate" inputmode="decimal" value="<?= e($n2((float)$B['flat_rate'])) ?>" style="width:90px;text-align:right;"<?= $ro ?>> €/h</div><p class="hint" style="margin:4px 0 0;"><?= tt('sb.flat_hint') ?></p></div>
      <div class="field"><label><?= tt('sb.cost_rate') ?></label><input name="cost_rate" inputmode="decimal" value="<?= e($n2((float)$B['cost_rate'])) ?>"<?= $ro ?>></div>
      <div class="field"><label><?= tt('sb.zone') ?></label><select name="zone" data-nosearch<?= $ed ? '' : ' disabled' ?>><option value="local"<?= $B['zone'] === 'local' ? ' selected' : '' ?>><?= tt('sb.zone_local') ?></option><option value="overseas"<?= $B['zone'] === 'overseas' ? ' selected' : '' ?>><?= tt('sb.zone_overseas') ?></option></select></div>
      <div class="field"><label><?= tt('sb.allow') ?></label><div style="display:flex;gap:8px;align-items:center;"><input name="allow_cost" inputmode="decimal" value="<?= e($n2((float)$B['allow_cost'])) ?>" style="text-align:right;" title="<?= tt('sb.cost') ?>"<?= $ro ?>><input name="allow_rate" inputmode="decimal" value="<?= e($n2((float)$B['allow_rate'])) ?>" style="text-align:right;" title="<?= tt('sb.sale') ?>"<?= $ro ?>></div><p class="hint" style="margin:4px 0 0;"><?= tt('sb.allow_hint') ?></p></div>
      <div class="field"><label><?= tt('sb.commission') ?></label><input name="commission_pct" inputmode="decimal" value="<?= e($n2((float)$B['commission_pct'])) ?>"<?= $ro ?>></div>
    </div>
    <?php if ($ed): ?><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div><?php endif; ?>
  </form>
</div>

<div class="card" id="descr">
  <div class="card-head"><h2>📝 <?= tt('sb.description') ?></h2></div>
  <?php if ($ed): ?><form method="post" action="<?= $self ?>#descr" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_descr"><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($aiDraft !== null): ?><p class="flash flash--warn" style="margin:0 0 8px;">✨ <?= tt('sb.ai_review') ?></p><?php endif; $dTxt = $aiDraft ?? (string)$B['description']; ?>
    <div class="field"><textarea name="description" rows="<?= max(6, min(26, substr_count($dTxt, "\n") + 3)) ?>" maxlength="60000" placeholder="<?= tt('sb.descr_ph') ?>"><?= e($dTxt) ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php if ($aiDraft !== null): ?><a class="btn btn--ghost btn--sm" href="<?= $self ?>#descr"><?= tt('sb.ai_drop') ?></a><?php endif; ?></div>
  </form>
  <?php if ($aiOn): ?><form method="post" action="<?= $self ?>#descr" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_ai_descr"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field" style="margin:0;"><div style="display:flex;gap:8px;"><input name="ask" maxlength="400" placeholder="<?= tt('sb.ai_ask_ph') ?>"><button class="btn btn--sm" type="submit" style="white-space:nowrap;">✨ <?= tt('sb.ai_draft') ?></button></div><p class="hint" style="margin:4px 0 0;"><?= tt('sb.ai_hint') ?></p></div>
  </form><?php endif; ?><?php else: ?><div class="rt"><?= trim((string)$B['description']) !== '' ? rt_html((string)$B['description']) : '<p class="hint">—</p>' ?></div><?php endif; ?>
</div>

<div class="card" id="labour">
  <div class="card-head"><h2>👷 <?= tt('sb.labour') ?> <span class="count"><?= count($B['labour']) ?></span></h2><span class="hint"><?= tt((int)$B['flat_on'] ? 'sb.lab_hint_flat' : 'sb.lab_hint') ?></span></div>
  <?php $drift = $ed && function_exists('sb_rate_drift') ? sb_rate_drift($B) : 0; if ($drift): ?><form method="post" action="<?= $self ?>#labour" data-lock data-confirm="<?= e(t('sb.rerate_confirm', ['z' => t('sb.zone_' . $B['zone'])])) ?>" class="flash flash--warn" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_rerate"><input type="hidden" name="id" value="<?= $id ?>"><span style="flex:1 1 320px;"><?= e(t('sb.rerate_warn', ['n' => $drift, 'z' => t('sb.zone_' . $B['zone'])])) ?></span><button class="btn btn--sm" type="submit">↻ <?= tt('sb.rerate_btn') ?></button></form><?php endif; ?>
  <form method="post" action="<?= $self ?>#labour" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_labour"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('th.code') ?></th><th style="min-width:220px;"><?= tt('sb.l_descr') ?></th><th><?= tt('sb.section') ?></th><th><?= tt('sb.discipline') ?></th><th><?= tt('sb.card') ?></th><th class="num"><?= tt('sb.h_normal') ?></th><th class="num"><?= tt('sb.h_travel') ?></th><th class="num"><?= tt('sb.h_overtime') ?></th><th class="num"><?= tt('sb.days') ?></th><th class="num"><?= tt('sb.rate') ?></th><th class="num"><?= tt('sb.rate_ot') ?></th><?php if ($dOn): ?><th class="num" title="<?= tt('sb.disc_hint') ?>"><?= tt('sb.disc_pct') ?></th><?php endif; ?><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th style="min-width:150px;"><?= tt('th.remarks') ?></th><?php if ($ed): ?><th title="<?= tt('btn.delete') ?>">🗑</th><?php endif; ?></tr></thead><tbody>
  <?php $lrows = $B['labour']; if ($ed) for ($i = 0; $i < $nNewL; $i++) $lrows[] = ['id' => 'n' . $i, '_new' => true, 'code' => '', 'descr' => '', 'section_id' => 0, 'discipline_id' => 0, 'card' => '', 'h_normal' => 0, 'h_travel' => 0, 'h_overtime' => 0, 'allow_days' => 0, 'rate' => 0, 'rate_ot' => null, 'discount_pct' => null, 'remarks' => ''];
  foreach ($lrows as $l): $k = 'l[' . $l['id'] . ']'; $new = !empty($l['_new']); $c = $new ? null : sb_labour_calc($B, $l); $w = 'style="width:64px;text-align:right;"'; ?>
  <tr<?= $new ? ' class="hint"' : '' ?>>
    <td><input name="<?= $k ?>[code]" maxlength="12" value="<?= e((string)$l['code']) ?>" style="width:62px;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[descr]" maxlength="400" style="width:100%;min-width:230px;" value="<?= e((string)$l['descr']) ?>"<?= $new ? ' placeholder="' . tt('sb.new_line') . '"' : '' ?><?= $ro ?>><?= !$new && !empty($l['bucket']) ? '<br><span class="hint">' . e((string)$l['bucket']) . '</span>' : '' ?></td>
    <td><select name="<?= $k ?>[section_id]" data-nosearch<?= $ed ? '' : ' disabled' ?>><option value="0">—</option><?php foreach ($sec as $sid => $s): ?><option value="<?= $sid ?>"<?= (int)$l['section_id'] === $sid ? ' selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select></td>
    <td><select name="<?= $k ?>[discipline_id]" data-nosearch<?= $ed ? '' : ' disabled' ?>><option value="0">—</option><?php foreach ($dis as $did => $dn): ?><option value="<?= $did ?>"<?= (int)$l['discipline_id'] === $did ? ' selected' : '' ?>><?= e($dn) ?></option><?php endforeach; ?></select></td>
    <td><select name="<?= $k ?>[card]" data-nosearch style="min-width:230px;"<?= $ed ? '' : ' disabled' ?>><option value="">—</option><?php $seen = false; foreach ($cards as $ck => $cd): if ((string)$l['card'] === (string)$ck) $seen = true; ?><option value="<?= e((string)$ck) ?>"<?= (string)$l['card'] === (string)$ck ? ' selected' : '' ?> title="<?= e($cd['name']) ?>"><?= e($ck . ' — ' . ($cd['name'] !== '' ? $cd['name'] . ' · ' : '') . $n2((float)$cd['normal']) . ' €/h') ?></option><?php endforeach; if (!$seen && (string)$l['card'] !== ''): ?><option value="<?= e((string)$l['card']) ?>" selected><?= e((string)$l['card'] . ' — ' . t('sb.card_gone')) ?></option><?php endif; ?></select></td>
    <td class="num"><input name="<?= $k ?>[h_normal]" inputmode="decimal" value="<?= e($hv($l['h_normal'])) ?>" <?= $w ?><?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[h_travel]" inputmode="decimal" value="<?= e($hv($l['h_travel'])) ?>" <?= $w ?><?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[h_overtime]" inputmode="decimal" value="<?= e($hv($l['h_overtime'])) ?>" <?= $w ?><?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[allow_days]" inputmode="decimal" value="<?= e($hv($l['allow_days'])) ?>" <?= $w ?><?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[rate]" inputmode="decimal" value="<?= (float)$l['rate'] > 0 ? e($n2((float)$l['rate'])) : '' ?>" style="width:74px;text-align:right;"<?= $ro ?>><?= $c && $c['flat'] ? '<br><span class="hint">' . e(t('sb.flat_applies', ['v' => $n2($c['rate'])])) . '</span>' : '' ?></td>
    <td class="num"><input name="<?= $k ?>[rate_ot]" inputmode="decimal" value="<?= $l['rate_ot'] !== null && (float)$l['rate_ot'] > 0 ? e($n2((float)$l['rate_ot'])) : '' ?>" style="width:74px;text-align:right;"<?= $ro ?>></td>
    <?php if ($dOn): ?><td class="num"><input name="<?= $k ?>[discount_pct]" inputmode="decimal" value="<?= ($l['discount_pct'] ?? null) !== null && (float)$l['discount_pct'] > 0 ? e($n2((float)$l['discount_pct'])) : '' ?>" style="width:58px;text-align:right;"<?= $ro ?>></td><?php endif; ?>
    <td class="num"><?= $c ? e($n2($c['cost'])) : '' ?></td><td class="num"><b><?= $c ? e($n2($c['sale'])) : '' ?></b><?= $c ? sb_low_mark($c['cost'], $c['sale']) : '' ?><?= $c && $c['disc_amt'] > 0.005 ? '<br><span class="hint" style="text-decoration:line-through;">' . e($n2($c['gross'])) . '</span>' : '' ?></td>
    <td><input name="<?= $k ?>[remarks]" maxlength="500" style="width:100%;min-width:150px;" value="<?= e((string)$l['remarks']) ?>"<?= $ro ?>></td>
    <?php if ($ed): ?><td><?= $new ? '' : '<input type="checkbox" name="' . $k . '[del]" value="1">' ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  <tr><td></td><td><b><?= tt('sb.total') ?></b></td><td></td><td></td><td></td><td class="num"><b><?= e(sb_h($T['h_normal'])) ?></b></td><td class="num"><b><?= e(sb_h($T['h_travel'])) ?></b></td><td class="num"><b><?= e(sb_h($T['h_overtime'])) ?></b></td><td class="num"><b><?= e(sb_h($T['days'])) ?></b></td><td></td><td></td><?php if ($dOn): ?><td class="num hint"><?= $T['lab_disc'] > 0.005 ? '−' . e($n2($T['lab_disc'])) : '' ?></td><?php endif; ?><td class="num"><b><?= e($n2($T['lab_cost'])) ?></b></td><td class="num"><b><?= e($n2($T['lab_sale'])) ?></b></td><td></td><?php if ($ed): ?><td></td><?php endif; ?></tr>
  </tbody></table></div>
  <?php if ($ed): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sb.save_lines') ?></button><span class="hint"><?= tt('sb.lines_hint') ?></span></div><?php endif; ?>
  </form>
</div>

<div class="card" id="parts">
  <div class="card-head"><h2>🔩 <?= tt('sb.parts') ?> <span class="count"><?= count($B['parts']) ?> / <?= SB_MAX_PARTS ?></span></h2><span class="hint"><?= e(t('sb.parts_hint', ['m' => rtrim(rtrim(number_format((float)$B['parts_margin'], 2, ',', ''), '0'), ',')])) ?></span></div>
  <form method="post" action="<?= $self ?>#parts" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_parts"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th>#</th><th><?= tt('th.code') ?></th><th style="min-width:260px;"><?= tt('sb.p_descr') ?></th><th class="num"><?= tt('sb.qty') ?></th><th><?= tt('sb.unit') ?></th><th class="num"><?= tt('sb.unit_cost') ?></th><th class="num"><?= tt('sb.margin_pct') ?></th><th class="num"><?= tt('sb.unit_sale') ?></th><?php if ($dOn): ?><th class="num" title="<?= tt('sb.disc_hint') ?>"><?= tt('sb.disc_pct') ?></th><?php endif; ?><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th style="min-width:150px;"><?= tt('th.remarks') ?></th><?php if ($ed): ?><th title="<?= tt('btn.delete') ?>">🗑</th><?php endif; ?></tr></thead><tbody>
  <?php $prows = $B['parts']; if ($ed) for ($i = 0; $i < $nNewP; $i++) $prows[] = ['id' => 'n' . $i, '_new' => true, 'code' => '', 'descr' => '', 'qty' => 0, 'unit' => '', 'unit_cost' => 0, 'margin_pct' => null, 'discount_pct' => null, 'remarks' => '', 'item_id' => null, 'cost_origin' => ''];
  $pn = 0; foreach ($prows as $p): $k = 'p[' . $p['id'] . ']'; $new = !empty($p['_new']); $c = $new ? null : sb_part_calc($B, $p); $bad = !$new && (float)$p['qty'] > 0 && (float)$p['unit_cost'] <= 0 && !sb_part_excluded($p); ?>
  <tr<?= $new ? ' class="hint"' : '' ?>>
    <td class="hint"><?= $new ? '+' : ++$pn ?></td>
    <td><input name="<?= $k ?>[code]" maxlength="20" value="<?= e((string)$p['code']) ?>" style="width:90px;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[descr]" maxlength="400" style="width:100%;min-width:260px;" value="<?= e((string)$p['descr']) ?>"<?= $new ? ' placeholder="' . tt('sb.new_part') . '"' : '' ?><?= $ro ?>><?php if (!$new && ((int)$p['item_id'] || (string)$p['cost_origin'] !== '')): ?><br><span class="hint"><?= (int)$p['item_id'] ? '📦 <a href="' . url('inventory_item', ['id' => (int)$p['item_id']]) . '">' . e((string)$p['item_code']) . '</a> · ' : '' ?><?= e((string)$p['cost_origin']) ?></span><?php endif; ?></td>
    <td class="num"><input name="<?= $k ?>[qty]" inputmode="decimal" value="<?= e($hv($p['qty'])) ?>" style="width:70px;text-align:right;"<?= $ro ?>></td>
    <td><input name="<?= $k ?>[unit]" maxlength="12" value="<?= e((string)$p['unit']) ?>" style="width:54px;"<?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[unit_cost]" inputmode="decimal" value="<?= (float)$p['unit_cost'] > 0 ? e($n2((float)$p['unit_cost'])) : '' ?>" style="width:92px;text-align:right;<?= $bad ? 'border-color:#9B2C2C;' : '' ?>"<?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[margin_pct]" inputmode="decimal" value="<?= $p['margin_pct'] !== null ? e($n2((float)$p['margin_pct'])) : '' ?>" placeholder="<?= e($n2((float)$B['parts_margin'])) ?>" style="width:66px;text-align:right;"<?= $ro ?>></td>
    <td class="num"><?php if ($new): ?><input name="<?= $k ?>[unit_sale]" inputmode="decimal" value="" style="width:92px;text-align:right;"><?php else: ?><?= e($n2($c['unit_sale'])) ?><?php endif; ?></td>
    <?php if ($dOn): ?><td class="num"><input name="<?= $k ?>[discount_pct]" inputmode="decimal" value="<?= ($p['discount_pct'] ?? null) !== null && (float)$p['discount_pct'] > 0 ? e($n2((float)$p['discount_pct'])) : '' ?>" style="width:58px;text-align:right;"<?= $ro ?>></td><?php endif; ?>
    <td class="num"><?= $c ? e($n2($c['cost'])) : '' ?></td><td class="num"><b><?= $c ? e($n2($c['sale'])) : '' ?></b><?= $c && !sb_part_excluded($p) ? sb_low_mark($c['cost'], $c['sale']) : '' ?><?= $c && $c['disc_amt'] > 0.005 ? '<br><span class="hint" style="text-decoration:line-through;">' . e($n2($c['gross'])) . '</span>' : '' ?></td>
    <td><input name="<?= $k ?>[remarks]" maxlength="500" style="width:100%;min-width:150px;" value="<?= e((string)$p['remarks']) ?>"<?= $ro ?>></td>
    <?php if ($ed): ?><td><?= $new ? '' : '<input type="checkbox" name="' . $k . '[del]" value="1">' ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  <tr><td></td><td></td><td><b><?= tt('sb.total') ?></b></td><td></td><td></td><td></td><td></td><td></td><?php if ($dOn): ?><td class="num hint"><?= $T['parts_disc'] > 0.005 ? '−' . e($n2($T['parts_disc'])) : '' ?></td><?php endif; ?><td class="num"><b><?= e($n2($T['parts_cost'])) ?></b></td><td class="num"><b><?= e($n2($T['parts_sale'])) ?></b></td><td></td><?php if ($ed): ?><td></td><?php endif; ?></tr>
  </tbody></table></div>
  <?php if ($ed): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sb.save_lines') ?></button><span class="hint"><?= tt('sb.parts_lines_hint') ?></span></div><?php endif; ?>
  </form>
  <?php if ($ed && $r3): ?>
  <?php if ($nStaged): ?><p class="flash flash--warn" style="margin:12px 0 0;"><?= e(t('sb.stage_waiting', ['n' => $nStaged])) ?> <a href="<?= url('system_budget_bom', ['id' => $id]) ?>"><b><?= tt('sb.stage_open') ?> ›</b></a></p><?php endif; ?>
  <div class="grid grid-2" style="align-items:start;margin-top:12px;">
    <form method="post" action="<?= $self ?>#parts" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_bom_upload"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 style="margin:0 0 6px;">📑 <?= tt('sb.bom_title') ?></h3>
      <div class="field"><input type="file" name="file" accept=".xlsx" required><p class="hint" style="margin:4px 0 0;"><?= tt('sb.bom_hint') ?></p></div>
      <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('sb.bom_btn') ?></button></div>
    </form>
    <form method="post" action="<?= $self ?>#parts" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_propose"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 style="margin:0 0 6px;">🔎 <?= tt('sb.prop_title') ?></h3>
      <p class="hint" style="margin:0 0 8px;"><?= e(t($aiOn ? 'sb.prop_hint_ai' : 'sb.prop_hint', ['n' => $nFree])) ?></p>
      <div class="btn-row"><button class="btn btn--sm" type="submit"<?= $nFree ? '' : ' disabled' ?>><?= tt('sb.prop_btn') ?></button></div>
    </form>
  </div>
  <?php endif; ?>
  <?php if ($ed && count($B['parts']) < SB_MAX_PARTS && ($inv = sb_inv_options())): ?>
  <form method="post" action="<?= $self ?>#parts" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_part_inv"><input type="hidden" name="id" value="<?= $id ?>">
    <h3 style="margin:0 0 6px;">📦 <?= tt('sb.inv_add') ?></h3>
    <div class="grid grid-3"><div class="field" style="grid-column:span 2;"><select name="item_id" required><option value=""><?= tt('sb.inv_pick') ?></option><?php foreach ($inv as $i): ?><option value="<?= (int)$i['id'] ?>"><?= e($i['code'] . ' · ' . mb_substr((string)$i['description'], 0, 90)) ?></option><?php endforeach; ?></select><p class="hint" style="margin:4px 0 0;"><?= tt('sb.inv_hint') ?></p></div>
      <div class="field"><div style="display:flex;gap:8px;"><input name="qty" inputmode="decimal" value="1" style="width:90px;text-align:right;" aria-label="<?= tt('sb.qty') ?>"><button class="btn btn--sm" type="submit"><?= tt('sb.inv_btn') ?></button></div></div></div>
  </form><?php endif; ?>
</div>

<div class="card" id="expenses">
  <div class="card-head"><h2>🧾 <?= tt('sb.expenses') ?> <span class="count"><?= count($B['expenses']) ?></span></h2><span class="hint"><?= tt('sb.exp_hint') ?></span></div>
  <form method="post" action="<?= $self ?>#expenses" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_exp"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:1000px;"><thead><tr><th><?= tt('sb.x_kind') ?></th><th style="min-width:260px;"><?= tt('sb.l_descr') ?></th><th class="num"><?= tt('sb.cost') ?></th><?php if ($dOn): ?><th class="num" title="<?= tt('sb.x_markup_hint') ?>"><?= tt('sb.x_markup') ?></th><?php endif; ?><th class="num"><?= tt('sb.sale') ?></th><th><?= tt('sb.x_outside') ?></th><?php if ($ed): ?><th title="<?= tt('btn.delete') ?>">🗑</th><?php endif; ?></tr></thead><tbody>
  <?php $xrows = $B['expenses']; if ($ed) for ($i = 0; $i < 2; $i++) $xrows[] = ['id' => 'n' . $i, '_new' => true, 'kind' => 'travel', 'descr' => '', 'cost' => 0, 'markup_pct' => null, 'sale' => 0, 'in_total' => 1];
  foreach ($xrows as $x): $k = 'x[' . $x['id'] . ']'; $new = !empty($x['_new']); ?>
  <tr<?= $new ? ' class="hint"' : '' ?>>
    <td><select name="<?= $k ?>[kind]" data-nosearch<?= $ed ? '' : ' disabled' ?>><?php foreach (SB_EXP_KINDS as $xk): ?><option value="<?= $xk ?>"<?= $x['kind'] === $xk ? ' selected' : '' ?>><?= tt('sb.xk_' . $xk) ?></option><?php endforeach; ?></select></td>
    <td><input name="<?= $k ?>[descr]" maxlength="300" style="width:100%;min-width:260px;" value="<?= e((string)$x['descr']) ?>"<?= $new ? ' placeholder="' . tt('sb.new_exp') . '"' : '' ?><?= $ro ?>></td>
    <td class="num"><input name="<?= $k ?>[cost]" inputmode="decimal" value="<?= (float)$x['cost'] > 0 ? e($n2((float)$x['cost'])) : '' ?>" style="width:110px;text-align:right;"<?= $ro ?>></td>
    <?php if ($dOn): ?><td class="num"><input name="<?= $k ?>[markup_pct]" inputmode="decimal" value="<?= ($x['markup_pct'] ?? null) !== null ? e($n2((float)$x['markup_pct'])) : '' ?>" style="width:66px;text-align:right;"<?= $ro ?>></td><?php endif; ?>
    <td class="num"><input name="<?= $k ?>[sale]" inputmode="decimal" value="<?= (float)$x['sale'] > 0 ? e($n2((float)$x['sale'])) : '' ?>" style="width:110px;text-align:right;"<?= $ro ?>></td>
    <td><input type="checkbox" name="<?= $k ?>[out]" value="1"<?= (int)$x['in_total'] ? '' : ' checked' ?><?= $ed ? '' : ' disabled' ?>></td>
    <?php if ($ed): ?><td><?= $new ? '' : '<input type="checkbox" name="' . $k . '[del]" value="1">' ?></td><?php endif; ?>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($ed): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sb.save_lines') ?></button><span class="hint"><?= tt('sb.exp_lines_hint') ?></span></div><?php endif; ?>
  </form>
</div>

<?php if ($ed && !$isRev): ?><div class="card" id="import">
  <h2>📥 <?= tt('sb.imp_title') ?></h2>
  <form method="post" action="<?= $self ?>#import" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_import_upload"><input type="hidden" name="into" value="<?= $id ?>">
    <div class="field"><input type="file" name="file" accept=".xlsx" required><p class="hint" style="margin:4px 0 0;"><?= tt('sb.imp_into_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('sb.imp_review') ?></button></div>
  </form>
</div><?php endif; ?>

<div class="grid grid-2" style="align-items:start;">
<div class="card" id="comments">
  <h2>💬 <?= tt('sb.comments') ?> <span class="count"><?= count($com) ?></span></h2>
  <?php if (!$com): ?><p class="hint"><?= tt('sb.no_comments') ?></p><?php endif; ?>
  <?php foreach ($com as $cm): ?><div style="border-bottom:1px solid var(--line, #E3E8EE);padding:8px 0;"><p style="margin:0 0 4px;"><b><?= e((string)$cm['full_name']) ?></b> <span class="hint"><?= e(fmt_datetime((string)$cm['created_at'])) ?></span></p><div class="rt"><?= rt_html((string)$cm['body']) ?></div></div><?php endforeach; ?>
  <form method="post" action="<?= $self ?>#comments" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="sb_comment"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><textarea name="body" rows="3" maxlength="4000" required placeholder="<?= tt('sb.comment_ph') ?>"></textarea></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('sb.comment_add') ?></button></div>
  </form>
</div>
<div class="card" id="history">
  <h2>🕘 <?= tt('sb.history') ?></h2>
  <div class="table-scroll"><table class="tbl" data-nosort><tbody>
  <?php foreach ($ev as $v): ?><tr><td class="date hint"><?= e(fmt_datetime((string)$v['created_at'])) ?></td><td><?= e((string)$v['full_name']) ?></td><td><?= tt('sb.ev_' . $v['kind']) ?><?= $v['note'] !== null && $v['note'] !== '' ? ' <span class="hint">· ' . e((string)$v['note']) . '</span>' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0 0;"><?= e(t('sb.created_by', ['n' => (string)$B['created_name'], 'd' => fmt_date(substr((string)$B['created_at'], 0, 10))])) ?></p>
</div>
</div>
<?php layout_foot(); ?>

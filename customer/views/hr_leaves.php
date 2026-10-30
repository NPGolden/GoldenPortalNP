<?php
/** HR › Leaves — who is away over a period (whole company or a discipline), leave periods / groups staff can join, assistant.   GAS-CP-R61-001
 *  ?p=hr_leaves[&from=&to=&disc=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr_lv_has') || !hr_lv_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.leaves'), 'hr_leaves'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-t', strtotime($from));
if ($to < $from) $to = $from; if ((strtotime($to) - strtotime($from)) > 92 * 86400) $to = date('Y-m-d', strtotime($from) + 92 * 86400);
$disc = (int)($_GET['disc'] ?? 0); $year = (int) substr($from, 0, 4);
$people = hr_lv_people($disc); $m = hr_lv_matrix($from, $to, $people); $tot = hr_lv_totals($m);
$days = []; for ($d = strtotime($from); $d <= strtotime($to); $d += 86400) $days[] = date('Y-m-d', $d);
$discs = all('SELECT id, name FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name'); $periods = hr_periods(); $types = hr_types();
$keep = array_filter(['from' => $from, 'to' => $to, 'disc' => $disc]); $fh = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$ai = $_SESSION['hr_lv_ai'] ?? null; unset($_SESSION['hr_lv_ai']); $aiOn = function_exists('ai_can') && ai_can('hr_leaves');
$edit = (int)($_GET['edit'] ?? 0) ? (one('SELECT * FROM hr_leave_periods WHERE id = ?', [(int)$_GET['edit']]) ?: null) : null;
$tone = fn(array $c) => $c['status'] === 'approved' || $c['status'] === 'board' ? (PL_TONES[$c['kind']] ?? 'o') : 'p';
layout_head(t('hr.leaves'), 'hr_leaves');
?>
<div class="page-head"><div><h1>🏖 <?= tt('hr.leaves') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hr.leaves_lede') ?></p></div>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: auto auto 1fr auto auto; align-items:end; margin:0;">
    <input type="hidden" name="p" value="hr_leaves">
    <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="from" value="<?= e($from) ?>"></div>
    <div class="field"><label><?= tt('pl.to') ?></label><input type="date" name="to" value="<?= e($to) ?>"></div>
    <div class="field"><label><?= tt('disc.one') ?></label><select name="disc" data-nosearch><option value="0"><?= tt('hr.whole_company') ?></option><?php foreach ($discs as $d): ?><option value="<?= (int)$d['id'] ?>"<?= $disc === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button></div>
    <div class="fltbar-actions"><?php $pm = strtotime($from . ' -1 month'); $nm = strtotime($from . ' +1 month'); ?><a class="btn btn--ghost btn--sm" href="<?= url('hr_leaves', ['from' => date('Y-m-01', $pm), 'to' => date('Y-m-t', $pm), 'disc' => $disc]) ?>">‹</a> <a class="btn btn--ghost btn--sm" href="<?= url('hr_leaves', ['disc' => $disc]) ?>"><?= tt('hr.this_month') ?></a> <a class="btn btn--ghost btn--sm" href="<?= url('hr_leaves', ['from' => date('Y-m-01', $nm), 'to' => date('Y-m-t', $nm), 'disc' => $disc]) ?>">›</a></div>
  </form>
</div>

<div class="kpi kpi--4" style="margin-bottom:14px;">
  <span class="stat"><b><?= count($people) ?></b><span class="s"><?= tt('hr.d_headcount') ?></span></span>
  <span class="stat gold"><b><?= count($m) ?></b><span class="s"><?= tt('hr.people_away') ?></span></span>
  <span class="stat"><b><?= e($fh(array_sum(array_map(fn($x) => count($x), $m)))) ?></b><span class="s"><?= tt('hr.person_days') ?></span></span>
  <span class="stat green"><b><?= count(array_filter($periods, fn($x) => $x['status'] === 'open')) ?></b><span class="s"><?= tt('hr.periods_open') ?></span></span>
</div>

<div class="card">
  <div class="card-head"><h2><?= e(fmt_date($from)) ?> – <?= e(fmt_date($to)) ?><?= $disc ? ' · ' . e((string)(array_column($discs, 'name', 'id')[$disc] ?? '')) : '' ?></h2>
    <span class="hint"><?php foreach ($tot as $code => $n): ?><b><?= e((string)$code) ?></b> <?= e($fh($n)) ?> &nbsp; <?php endforeach; ?></span></div>
  <?php if (!$people): ?><div class="empty"><?= tt('hr.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl pl-matrix" style="font-size:12px;"><thead><tr><th style="position:sticky;left:0;background:var(--cp-surface,#fff);"><?= tt('ll.who') ?></th><?php foreach ($days as $d): $wd = (int) date('N', strtotime($d)); ?><th class="num<?= $wd >= 6 ? ' is-we' : '' ?>" style="padding:4px 3px;min-width:22px;<?= $wd >= 6 ? 'background:#f3f4f6;' : '' ?>" title="<?= e(fmt_date($d)) ?>"><?= date('j', strtotime($d)) ?><br><span class="hint" style="font-size:9px;"><?= date('D', strtotime($d))[0] ?></span></th><?php endforeach; ?><th class="num">Σ</th></tr></thead><tbody>
  <?php foreach ($people as $p): $pid = (int)$p['id']; $row = $m[$pid] ?? []; ?><tr><td style="position:sticky;left:0;background:var(--cp-surface,#fff);white-space:nowrap;"><a href="<?= url('hr_person', ['id' => $pid]) ?>"><?= e($p['name_en']) ?></a><br><span class="hint"><?= e($p['department'] ?? '') ?></span></td>
    <?php foreach ($days as $d): $c = $row[$d] ?? null; $wd = (int) date('N', strtotime($d)); ?><td class="num" style="padding:2px;<?= $wd >= 6 ? 'background:#f3f4f6;' : '' ?>"><?= $c ? '<span class="pl-chip pl-' . $tone($c) . '" title="' . e($c['name'] . ' · ' . t('hr.s_' . ($c['status'] === 'board' ? 'approved' : $c['status']))) . '" style="font-size:9px;padding:1px 3px;">' . e(mb_substr($c['code'], 0, 3)) . '</span>' : '' ?></td><?php endforeach; ?>
    <td class="num"><?= $row ? count($row) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin-top:6px;"><?= tt('hr.matrix_legend') ?></p>
  <?php endif; ?>
</div>

<div class="cols" style="grid-template-columns: 3fr 2fr;">
<div class="card" id="hr-periods">
  <div class="card-head"><h2>🏝 <?= tt('hr.periods') ?></h2><a class="btn btn--gold btn--sm" href="<?= url('hr_leaves', $keep + ['edit' => 0]) ?>#hr-period-form">+ <?= tt('hr.period_new') ?></a></div>
  <p class="hint"><?= tt('hr.periods_hint') ?></p>
  <?php if (!$periods): ?><div class="empty"><?= tt('hr.no_periods') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.period') ?></th><th><?= tt('hr.type') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.period_target') ?></th><th><?= tt('hr.period_members') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($periods as $lp): $mem = hr_period_members((int)$lp['id']); $nA = count(array_filter($mem, fn($x) => $x['status'] === 'approved')); $nP = count(array_filter($mem, fn($x) => str_starts_with($x['status'], 'pending'))); ?>
    <tr><td><b><?= e($lp['name']) ?></b><?= $lp['note'] ? '<br><span class="hint">' . e($lp['note']) . '</span>' : '' ?></td><td><?= e($lp['code']) ?></td><td><?= e(fmt_date($lp['date_from'])) ?> – <?= e(fmt_date($lp['date_to'])) ?></td>
      <td class="hint"><?php if ($lp['target'] === 'all') echo tt('hr.whole_company'); elseif ($lp['target'] === 'disciplines') { $dn = array_column($discs, 'name', 'id'); echo e(implode(', ', array_map(fn($i) => $dn[(int)$i] ?? $i, array_filter(explode(',', (string)$lp['target_ids']))))); } else echo e(t('hr.n_people', ['n' => count(array_filter(explode(',', (string)$lp['target_ids'])))])); ?></td>
      <td><?= pill('green', (string)$nA) ?><?= $nP ? ' ' . pill('gold', (string)$nP) : '' ?><?= $lp['max_people'] ? ' <span class="hint">/ ' . (int)$lp['max_people'] . '</span>' : '' ?><?php if ($mem): ?><details><summary class="hint"><?= tt('hr.period_who') ?></summary><ul class="hint" style="margin:4px 0 0;padding-left:16px;"><?php foreach ($mem as $x): ?><li><a href="<?= url('hr_person', ['id' => (int)$x['person_id'], 'tab' => 'req']) ?>"><?= e($x['name_en']) ?></a> · <?= e(fmt_date($x['date_from'])) ?>–<?= e(fmt_date($x['date_to'])) ?> · <?= hr_status_pill($x['status']) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
      <td><?= pill($lp['status'] === 'open' ? 'green' : ($lp['status'] === 'applied' ? 'navy' : 'mute'), t('hr.ps_' . $lp['status'])) ?></td>
      <td class="row-actions" style="white-space:nowrap;"><a class="btn btn--ghost btn--sm" href="<?= url('hr_leaves', $keep + ['edit' => (int)$lp['id']]) ?>#hr-period-form">✎</a>
        <?php if ($lp['status'] !== 'applied'): ?><form method="post" action="<?= url('hr_leaves', $keep) ?>" class="inline" data-lock data-confirm="<?= e(t('hr.period_apply_confirm', ['n' => count(hr_period_people($lp))])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('hr.period_apply') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="hr_period_apply"><input type="hidden" name="id" value="<?= (int)$lp['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('hr.period_apply_hint') ?>">⇒ <?= tt('hr.period_apply') ?></button></form>
        <?php else: ?><form method="post" action="<?= url('hr_leaves', $keep) ?>" class="inline" data-lock data-confirm="<?= tt('hr.period_undo_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('hr.period_undo') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="hr_period_undo"><input type="hidden" name="id" value="<?= (int)$lp['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">↶ <?= tt('hr.period_undo') ?></button></form><?php endif; ?>
        <form method="post" action="<?= url('hr_leaves', $keep) ?>" class="inline" data-lock data-confirm="<?= tt('hr.period_delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="hr_period_delete"><input type="hidden" name="id" value="<?= (int)$lp['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">✕</button></form></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
  <?php if (isset($_GET['edit'])): ?>
  <h3 id="hr-period-form" style="margin-top:14px;"><?= $edit ? tt('hr.period_edit') : tt('hr.period_new') ?></h3>
  <form method="post" action="<?= url('hr_leaves', $keep) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_period_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid grid-3">
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.period') ?> *</label><input name="name" required maxlength="120" value="<?= e($edit['name'] ?? '') ?>" placeholder="<?= tt('hr.period_name_ph') ?>"></div>
      <div class="field"><label><?= tt('hr.type') ?></label><select name="type_id" data-nosearch><?php foreach ($types as $tid => $t): if ($t['unit'] !== 'day') continue; ?><option value="<?= $tid ?>"<?= (int)($edit['type_id'] ?? hr_annual_type_id()) === $tid ? ' selected' : '' ?>><?= e($t['code'] . ' · ' . $t['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="date_from" required value="<?= e($edit['date_from'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('pl.to') ?></label><input type="date" name="date_to" required value="<?= e($edit['date_to'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.period_max') ?></label><input type="number" name="max_people" min="0" value="<?= e((string)($edit['max_people'] ?? '')) ?>" placeholder="∞"></div>
      <div class="field"><label><?= tt('hr.period_target') ?></label><select name="target" data-nosearch id="lp_target"><option value="all"<?= ($edit['target'] ?? 'all') === 'all' ? ' selected' : '' ?>><?= tt('hr.whole_company') ?></option><option value="disciplines"<?= ($edit['target'] ?? '') === 'disciplines' ? ' selected' : '' ?>><?= tt('hr.by_discipline') ?></option><option value="people"<?= ($edit['target'] ?? '') === 'people' ? ' selected' : '' ?>><?= tt('hr.by_people') ?></option></select></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= ($edit['status'] ?? 'open') === 'open' ? ' selected' : '' ?>><?= tt('hr.ps_open') ?></option><option value="closed"<?= ($edit['status'] ?? '') === 'closed' ? ' selected' : '' ?>><?= tt('hr.ps_closed') ?></option></select></div>
      <div class="field"><label><?= tt('hr.notes') ?></label><input name="note" maxlength="300" value="<?= e($edit['note'] ?? '') ?>"></div>
      <?php $selIds = array_map('intval', array_filter(explode(',', (string)($edit['target_ids'] ?? '')))); ?>
      <div class="field lp-disc" style="grid-column:1 / -1;"><label><?= tt('nav.disciplines') ?></label><div class="pl-chips" style="min-height:0;"><?php foreach ($discs as $d): ?><label class="pl-person"><input type="checkbox" name="discipline_ids[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $selIds, true) && ($edit['target'] ?? '') === 'disciplines' ? ' checked' : '' ?>> <?= e($d['name']) ?></label><?php endforeach; ?></div></div>
      <div class="field lp-people" style="grid-column:1 / -1;"><label><?= tt('hr.people') ?></label><select name="person_ids[]" multiple size="8" style="width:100%;"><?php foreach (hr_lv_people() as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= in_array((int)$pp['id'], $selIds, true) && ($edit['target'] ?? '') === 'people' ? ' selected' : '' ?>><?= e($pp['name_en'] . ($pp['department'] ? ' · ' . $pp['department'] : '')) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('hr.multi_hint') ?></p></div>
    </div>
    <p class="hint"><?= tt('hr.period_form_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('hr_leaves', $keep) ?>#hr-periods"><?= tt('btn.cancel') ?></a></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function () { var t = document.getElementById('lp_target'); function f() { document.querySelector('.lp-disc').style.display = t.value === 'disciplines' ? '' : 'none'; document.querySelector('.lp-people').style.display = t.value === 'people' ? '' : 'none'; } t.addEventListener('change', f); f(); })();</script>
  <?php endif; ?>
</div>
<div class="card" id="hr-ai">
  <h2>✨ <?= tt('hr.ask_ai') ?></h2>
  <?php if (!$aiOn): ?><p class="hint"><?= tt('hr.ai_off') ?></p><?php else: ?>
  <p class="hint"><?= tt('hr.ask_ai_hint') ?></p>
  <form method="post" action="<?= url('hr_leaves', $keep) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_leaves_ask"><input type="hidden" name="from" value="<?= e($from) ?>"><input type="hidden" name="to" value="<?= e($to) ?>"><input type="hidden" name="disc" value="<?= $disc ?>">
    <div class="field"><textarea name="q" rows="3" required maxlength="2000" placeholder="<?= tt('hr.ask_ai_ph') ?>"><?= e($ai['q'] ?? '') ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">✨ <?= tt('ai.go') ?></button></div>
  </form>
  <?php if ($ai && ($ai['a'] !== '' || $ai['err'] !== '')): ?><div class="<?= $ai['err'] !== '' ? 'flash flash--bad' : 'card card--tight' ?>" style="margin-top:10px;white-space:pre-wrap;"><?= e($ai['err'] !== '' ? $ai['err'] : $ai['a']) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

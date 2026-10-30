<?php
/** Weekly works forecast of a project week — draft editor for the PM, issued view for everybody, owner replies (GAS-CP-R31-001). */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff();
$id = (int)($_GET['id'] ?? 0); $pid = (int)($_GET['project'] ?? 0); $week = (string)($_GET['week'] ?? '');
if (!$id && $pid) {                                                                   // open by project + week: find, or create the draft (rolling pre-fill)
    $week = preg_match('/^\d{4}-W\d{2}$/', $week) ? $week : iso_week(date('Y-m-d', strtotime('next monday')));
    $f = wf_find($pid, $week);
    if (!$f && wf_can_edit(null, $pid)) { $id = wf_open($pid, $week); redirect('wf', ['id' => $id]); }
    if (!$f) { $p = one('SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ?', [$pid]); layout_head(t('wf.title'), 'projects'); echo '<p class="eyebrow"><a href="' . url('projects') . '">' . tt('nav.projects') . '</a></p><h1>' . tt('wf.title') . '</h1><div class="card"><p class="hint">' . tt('wf.none_week', ['w' => $week]) . '</p>' . (wf_list($pid) ? '<ul>' . implode('', array_map(fn($x) => '<li><a href="' . url('wf', ['id' => $x['id']]) . '">' . e($x['week']) . '</a> ' . ($x['status'] === 'issued' ? pill('green', t('wf.s_issued')) : pill('mute', t('wf.s_draft'))) . '</li>', wf_list($pid))) . '</ul>' : '') . '</div>'; layout_foot(); return; }
    $id = (int)$f['id'];
}
$f = wf_get($id);
if (!$f) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$edit = $staff && wf_can_edit(null, (int)$f['project_id']) && $f['status'] === 'draft';
$canIssue = $staff && wf_can_edit(null, (int)$f['project_id']);
$areas = wf_areas((int)$f['vessel_id']); $decks = wf_decks((int)$f['vessel_id']);
$subs = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [(int)$f['project_id']]);
$hl = []; foreach ($f['items'] as $it) $hl[(int)$it['area_id']] = ($hl[(int)$it['area_id']] ?? '') === 'A' ? 'A' : $it['plan'];
$we = date('Y-m-d', strtotime($f['week_start'] . ' +6 days')); $DL = wf_day_labels();
$issued = function_exists('ar_issued') ? ar_issued($f['doc_no'], (int)$f['project_id']) : [];
$others = wf_list((int)$f['project_id']);
$self = url('wf', ['id' => $id]);
layout_head(t('wf.title') . ' ' . $f['week'] . ' — ' . ($f['project_code'] ?: $f['project_name']), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('nav.projects') ?></a> › <a href="<?= url('projects', ['project' => $f['project_id']]) ?>"><?= e($f['project_code'] ?: $f['project_name']) ?></a> › <?= tt('wf.title') ?> <?= e($f['week']) ?></p>
<div class="page-head">
  <div><h1>🗓 <?= tt('wf.title') ?> — <?= e($f['week']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(trim(($f['project_code'] ?: '') . ' ' . $f['project_name'])) ?> · <?= e((string)$f['vessel']) ?> · <?= e(fmt_date($f['week_start'])) ?> – <?= e(fmt_date($we)) ?> · <span class="hint"><?= e($f['doc_no']) ?></span></p></div>
  <div class="actions">
    <?= $f['status'] === 'issued' ? pill('green', t('wf.s_issued')) : pill('gold', t('wf.s_draft')) ?>
    <?php if ($staff): ?><a class="btn btn--ghost btn--sm" href="<?= url('wf', ['project' => $f['project_id'], 'week' => wf_week_shift($f['week'], -1)]) ?>">‹ <?= tt('wf.prev_week') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('wf', ['project' => $f['project_id'], 'week' => wf_week_shift($f['week'], 1)]) ?>"><?= tt('wf.next_week') ?> ›</a><?php endif; ?>
    <a class="btn btn--ghost btn--sm" href="<?= url('wf_doc', ['id' => $id, 'fmt' => 'pdf']) ?>" target="_blank">⤓ PDF</a><a class="btn btn--ghost btn--sm" href="<?= url('wf_doc', ['id' => $id, 'fmt' => 'docx']) ?>">⤓ Word</a>
    <?php if ($canIssue): ?>
      <?php if ($f['status'] === 'draft'): ?><form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wf_issue"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit" data-js-confirm="<?= e(t('wf.issue_confirm', ['r' => 'R' . count($issued)])) ?>">🔒 <?= e(t('wf.issue', ['r' => 'R' . count($issued)])) ?></button></form>
      <?php else: ?><form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="wf_reopen"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= e(t('wf.reopen_confirm')) ?>">✎ <?= tt('wf.reopen') ?></button></form><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php if ($staff && !$areas): ?><div class="flash flash--warn"><?= tt('wf.no_areas_yet') ?> <a href="<?= url('vessel_decks', ['id' => $f['vessel_id']]) ?>"><?= tt('wf.decks_title') ?> →</a></div><?php endif; ?>

<div class="card">
  <div class="card-head"><h2><?= tt('wf.sec_maps') ?></h2><?php if ($staff): ?><a class="btn btn--ghost btn--sm" href="<?= url('vessel_decks', ['id' => $f['vessel_id']]) ?>"><?= tt('wf.decks_title') ?> →</a><?php endif; ?></div>
  <p class="hint"><?= tt('wf.maps_legend') ?><?= $edit ? ' ' . tt('wf.map_click_hint') : '' ?></p>
  <div class="wf-maps"><?php foreach ($decks as $d): if (!$d['stored_name']) continue; ?><div class="wf-map"><h3 class="ed-h"><?= e($d['name']) ?></h3><?= wf_deck_html($d, $areas, $hl, false) ?></div><?php endforeach; ?></div>
  <?php if (!array_filter($decks, fn($d) => $d['stored_name'])): ?><p class="hint"><?= tt('wf.no_maps') ?></p><?php endif; ?>
</div>

<form method="post" action="<?= $self ?>" id="wf-editor" data-lock>
<?= csrf_field() ?><input type="hidden" name="action" value="wf_save"><input type="hidden" name="id" value="<?= $id ?>">
<div class="card">
  <div class="card-head"><h2><?= tt('wf.sec_areas') ?> <span class="count"><?= count($f['items']) ?></span></h2><?php if ($edit): ?><div class="btn-row"><button class="btn btn--ghost btn--sm" type="button" id="wf-add-a">+ <?= tt('wf.add_plan_a') ?></button><button class="btn btn--ghost btn--sm" type="button" id="wf-add-b">+ <?= tt('wf.add_plan_b') ?></button></div><?php endif; ?></div>
  <p class="hint"><?= tt('wf.areas_lede') ?></p>
  <table class="tbl wf-items" data-nosort><thead><tr><th style="width:60px;"><?= tt('wf.plan') ?></th><th><?= tt('wf.area') ?></th><th><?= tt('wf.subs') ?></th><th><?= tt('wf.works') ?></th><th><?= tt('wf.days') ?></th><?php if ($edit): ?><th></th><?php endif; ?></tr></thead>
  <tbody id="wf-rows">
  <?php $n = 0; foreach ($f['items'] as $it): $n++; $set = $it['days'] ? array_map('intval', explode(',', $it['days'])) : []; $sids = array_map(fn($s) => (int)$s['id'], $it['subs']); ?>
    <tr class="wf-row wf-plan-<?= $it['plan'] ?>">
      <td><?php if ($edit): ?><select name="items[<?= $n ?>][plan]" data-nosearch class="wf-plan"><option value="A"<?= $it['plan'] === 'A' ? ' selected' : '' ?>>A</option><option value="B"<?= $it['plan'] === 'B' ? ' selected' : '' ?>>B</option></select><?php else: ?><b><?= $it['plan'] ?></b><?php endif; ?></td>
      <td><?php if ($edit): ?><select name="items[<?= $n ?>][area_id]" required><?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>"<?= (int)$a['id'] === (int)$it['area_id'] ? ' selected' : '' ?>><?= e($a['name'] . ($a['deck'] ? ' · ' . $a['deck'] : '') . ' · ' . wf_class_label($a['class'])) ?></option><?php endforeach; ?></select><?php else: ?><b><?= e($it['area']) ?></b><div class="hint"><?= e($it['deck'] ?: '') ?> <?= wf_class_pill($it['class']) ?></div><?php endif; ?></td>
      <td><?php if ($edit): ?><div class="wf-subs"><?php foreach ($subs as $s): ?><label><input type="checkbox" name="items[<?= $n ?>][subs][]" value="<?= $s['id'] ?>"<?= in_array((int)$s['id'], $sids, true) ? ' checked' : '' ?>> <?= e($s['code']) ?></label><?php endforeach; ?></div><?php else: ?><?= e(wf_item_subs_txt($it)) ?><?php endif; ?></td>
      <td><?php if ($edit): ?><input name="items[<?= $n ?>][description]" maxlength="500" value="<?= e($it['description'] ?? '') ?>" placeholder="<?= tt('wf.works_ph') ?>"><?php else: ?><?= e($it['description'] ?: '—') ?><?php endif; ?></td>
      <td><?php if ($edit): ?><div class="wf-days"><?php foreach ($DL as $dd => $dl): ?><label><input type="checkbox" name="items[<?= $n ?>][days][]" value="<?= $dd ?>"<?= in_array($dd, $set, true) ? ' checked' : '' ?>> <?= e($dl) ?></label><?php endforeach; ?></div><?php else: ?><?= e(wf_days_txt($it['days'])) ?><?php endif; ?></td>
      <?php if ($edit): ?><td style="text-align:right;"><button class="btn btn--ghost btn--sm wf-del" type="button">×</button></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody></table>
  <?php if (!$f['items']): ?><p class="hint" id="wf-empty"><?= tt('wf.no_items') ?></p><?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('wf.sec_grid') ?></h2>
  <?php if ($f['items']): ?>
  <table class="tbl wf-grid" data-nosort><thead><tr><th><?= tt('wf.area') ?></th><?php foreach ($DL as $dd => $dl): ?><th><?= e($dl) ?> <span class="hint"><?= date('d', strtotime($f['week_start'] . ' +' . ($dd - 1) . ' days')) ?></span></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($f['items'] as $it): $set = $it['days'] ? array_map('intval', explode(',', $it['days'])) : range(1, 5); ?><tr><td><b><?= e($it['area']) ?></b> <span class="pill <?= $it['plan'] === 'A' ? 'pill--gold' : 'pill--mute' ?>"><?= $it['plan'] ?></span></td><?php foreach ($DL as $dd => $dl): ?><td class="<?= in_array($dd, $set, true) ? 'wf-cell wf-cell-' . $it['plan'] : '' ?>"><?= in_array($dd, $set, true) ? e(wf_item_subs_txt($it)) : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table>
  <?php else: ?><p class="hint"><?= tt('wf.no_items') ?></p><?php endif; ?>
</div>

<div class="cols">
  <div class="card" id="wf-replies">
    <div class="card-head"><h2><?= tt('wf.sec_owner') ?> <span class="count"><?= count($f['comments']) ?></span></h2></div>
    <p class="hint"><?= $staff ? tt('wf.replies_hint_staff') : tt('wf.replies_hint_owner') ?></p>
    <?php foreach ($f['comments'] as $c): ?>
      <div class="wf-reply wf-k-<?= e($c['kind']) ?><?= $c['side'] === 'golden' ? ' is-golden' : '' ?>">
        <div class="wf-reply-head"><?= pill(in_array($c['kind'], ['delay', 'obstacle'], true) ? 'bad' : ($c['kind'] === 'painting' ? 'gold' : 'navy'), wf_kind_label($c['kind'])) ?> <b><?= e($c['user_name']) ?></b> <span class="hint"><?= e($c['side'] === 'golden' ? 'Golden A/S' : t('wf.owner_side')) ?> · <?= e(fmt_datetime($c['created_at'])) ?></span><?= $c['area'] ? ' · ' . e($c['area']) : '' ?><?= $c['from_date'] ? ' · ' . e(fmt_date($c['from_date'])) . ($c['to_date'] ? ' – ' . e(fmt_date($c['to_date'])) : '') : '' ?>
          <?php if ($edit): ?><label class="hint" style="margin-left:auto;"><input type="checkbox" name="in_report[<?= $c['id'] ?>]" value="1"<?= $c['in_report'] ? ' checked' : '' ?>> <?= tt('wf.in_report') ?></label><?php elseif ($c['in_report']): ?><span class="hint" style="margin-left:auto;">📄 <?= tt('wf.in_report') ?></span><?php endif; ?>
          <?php if (is_cp_admin() || (int)$c['user_id'] === (int)$u['id']): ?><button class="btn btn--ghost btn--sm" type="submit" form="wf-cdel-<?= $c['id'] ?>">×</button><?php endif; ?></div>
        <p><?= mt_html($c['body']) ?></p>
      </div>
    <?php endforeach; ?>
    <?php if (!$f['comments']): ?><p class="hint"><?= tt('wf.no_replies') ?></p><?php endif; ?>
    <?php if ($edit): ?><input type="hidden" name="in_report_all" value="1"><div class="field" style="margin-top:10px;"><label><?= tt('wf.owner_summary') ?></label><textarea name="owner_summary" rows="2" placeholder="<?= tt('wf.owner_summary_ph') ?>"><?= e($f['owner_summary'] ?? '') ?></textarea></div><?php elseif ($f['owner_summary']): ?><p style="white-space:pre-wrap;"><?= e($f['owner_summary']) ?></p><?php endif; ?>
  </div>
  <div class="card">
    <h2><?= tt('wf.sec_notes') ?></h2>
    <?php if ($edit): ?><div class="field"><textarea name="notes" rows="6" placeholder="<?= tt('wf.notes_ph') ?>"><?= e($f['notes'] ?? '') ?></textarea></div>
    <div class="btn-row"><button class="btn" type="submit">💾 <?= tt('wf.save_draft') ?></button><button class="btn btn--ghost" type="submit" form="wf-delete" data-js-confirm="<?= e(t('wf.delete_confirm')) ?>"><?= tt('btn.delete') ?></button></div>
    <?php else: ?><?= $f['notes'] ? rt_html($f['notes']) : '<p><span class="hint">—</span></p>' ?><?php endif; ?>
    <?php if ($issued): ?><h3 class="ed-h" style="margin-top:16px;"><?= tt('ar.issued') ?></h3><table class="tbl" data-nosort><tbody><?php foreach ($issued as $r): ?><tr><td><b><?= e($r['revision']) ?></b></td><td><?= e(fmt_datetime($r['at'])) ?></td><td><?= e($r['by'] ?: '—') ?></td><td style="text-align:right;"><?php foreach ($r['files'] as $ext => $fid): ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $fid]) ?>"<?= $ext === 'pdf' ? ' target="_blank"' : '' ?>><?= strtoupper($ext) ?></a> <?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
    <?php if (count($others) > 1): ?><h3 class="ed-h" style="margin-top:16px;"><?= tt('wf.other_weeks') ?></h3><p class="hint"><?php foreach ($others as $o): if ((int)$o['id'] === $id) continue; ?><a class="btn btn--ghost btn--sm" href="<?= url('wf', ['id' => $o['id']]) ?>"><?= e($o['week']) ?> <?= $o['status'] === 'issued' ? '🔒' : '✎' ?></a> <?php endforeach; ?></p><?php endif; ?>
  </div>
</div>
</form>
<?php foreach ($f['comments'] as $c): ?><form method="post" action="<?= $self ?>" id="wf-cdel-<?= $c['id'] ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="wf_comment_delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"></form><?php endforeach; ?>
<form method="post" action="<?= $self ?>" id="wf-delete"><?= csrf_field() ?><input type="hidden" name="action" value="wf_delete"><input type="hidden" name="id" value="<?= $id ?>"></form>

<div class="card" id="wf-reply-form">
  <h2><?= $staff ? tt('wf.reply_staff') : tt('wf.reply_owner') ?></h2>
  <p class="hint"><?= tt('wf.reply_hint') ?></p>
  <form method="post" action="<?= $self ?>" class="grid grid-4" style="align-items:end;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="wf_comment_add"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field" style="margin:0;"><label><?= tt('wf.kind') ?></label><select name="kind" data-nosearch><?php foreach (WF_KINDS as $k): ?><option value="<?= $k ?>"><?= e(wf_kind_label($k)) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;"><label><?= tt('wf.area') ?></label><select name="area_id"><option value="">—</option><?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name'] . ($a['deck'] ? ' · ' . $a['deck'] : '')) ?></option><?php endforeach; ?></select></div>
    <div class="field" style="margin:0;"><label><?= tt('wf.from') ?></label><input type="date" name="from_date"></div>
    <div class="field" style="margin:0;"><label><?= tt('wf.to') ?></label><input type="date" name="to_date"></div>
    <div class="field" style="margin:0;grid-column:1 / span 3;"><label><?= tt('wf.remark') ?></label><textarea data-mt name="body" rows="2" required placeholder="<?= tt('wf.reply_ph') ?>"></textarea></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('wf.send_reply') ?></button></div>
  </form>
</div>
<?php if ($edit): ?>
<template id="wf-row-tpl"><tr class="wf-row wf-plan-A">
  <td><select name="items[N][plan]" data-nosearch class="wf-plan"><option value="A">A</option><option value="B">B</option></select></td>
  <td><select name="items[N][area_id]" required><option value=""><?= tt('vm.choose') ?></option><?php foreach ($areas as $a): ?><option value="<?= $a['id'] ?>"><?= e($a['name'] . ($a['deck'] ? ' · ' . $a['deck'] : '') . ' · ' . wf_class_label($a['class'])) ?></option><?php endforeach; ?></select></td>
  <td><div class="wf-subs"><?php foreach ($subs as $s): ?><label><input type="checkbox" name="items[N][subs][]" value="<?= $s['id'] ?>"> <?= e($s['code']) ?></label><?php endforeach; ?></div></td>
  <td><input name="items[N][description]" maxlength="500" placeholder="<?= tt('wf.works_ph') ?>"></td>
  <td><div class="wf-days"><?php foreach ($DL as $dd => $dl): ?><label><input type="checkbox" name="items[N][days][]" value="<?= $dd ?>"<?= $dd <= 5 ? ' checked' : '' ?>> <?= e($dl) ?></label><?php endforeach; ?></div></td>
  <td style="text-align:right;"><button class="btn btn--ghost btn--sm wf-del" type="button">×</button></td></tr></template>
<script nonce="<?= csp_nonce() ?>">
(function () {
  var rows = document.getElementById('wf-rows'), tpl = document.getElementById('wf-row-tpl'), n = rows.children.length;
  function add(plan, areaId) { n++; var html = tpl.innerHTML.replace(/\[N\]/g, '[' + n + ']'); var tr = document.createElement('tbody'); tr.innerHTML = html; var row = tr.firstElementChild; row.querySelector('.wf-plan').value = plan; row.className = 'wf-row wf-plan-' + plan; if (areaId) row.querySelector('select[name$="[area_id]"]').value = areaId; rows.appendChild(row); var e = document.getElementById('wf-empty'); if (e) e.remove(); wire(row); return row; }
  function wire(row) { row.querySelector('.wf-del').addEventListener('click', function () { row.remove(); }); row.querySelector('.wf-plan').addEventListener('change', function () { row.className = 'wf-row wf-plan-' + this.value; }); }
  rows.querySelectorAll('.wf-row').forEach(wire);
  document.getElementById('wf-add-a').addEventListener('click', function () { add('A'); });
  document.getElementById('wf-add-b').addEventListener('click', function () { add('B'); });
  document.querySelectorAll('.wf-maps .wf-rect[data-area]').forEach(function (r) { r.style.cursor = 'pointer'; r.addEventListener('click', function (e) { var row = add(e.shiftKey ? 'B' : 'A', r.dataset.area); row.scrollIntoView({ block: 'center', behavior: 'smooth' }); }); });
})();
</script>
<?php endif; ?>
<?php if ($staff && function_exists('em_card_html') && $f['status'] === 'issued'): $emAtts = [['wf:' . $id . ':pdf', $f['doc_no'] . '.pdf'], ['wf:' . $id . ':docx', $f['doc_no'] . '.docx']];
  echo em_card_html(['kind' => 'wf', 'ctx' => ['no' => (string)$f['doc_no'], 'title' => (string)($f['project_name'] ?? ''), 'vessel' => (string)($f['vessel'] ?? ''), 'customer' => (string)(scalar('SELECT name FROM customers WHERE id = ?', [(int)($f['customer_id'] ?? 0)]) ?: ''), 'project' => (string)($f['project_code'] ?? ''), 'week' => (string)$f['week'], 'doc' => t('em.w_wf', ['no' => $f['doc_no']]), 'project_id' => (int)$f['project_id'], 'customer_id' => (int)($f['customer_id'] ?? 0)],
    'people' => em_people((int)($f['vessel_id'] ?? 0) ?: null, (int)($f['customer_id'] ?? 0) ?: null), 'atts' => $emAtts, 'back' => url('wf', ['id' => $id])]); endif; ?>
<?php layout_foot(); ?>

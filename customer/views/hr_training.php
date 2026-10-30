<?php
/** HR › Training — introductory / internal / external trainings published by HR, applications, attendance, results and certificates.   GAS-CP-R62-001
 *  ?p=hr_training[&id=&kind=&status=&year=&new=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr_tr_has') || !hr_tr_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.training'), 'hr_training'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $tr = $id ? hr_training($id) : null; $new = isset($_GET['new']) || isset($_GET['edit']);
$f = ['kind' => (string)($_GET['kind'] ?? ''), 'status' => (string)($_GET['status'] ?? ''), 'year' => (int)($_GET['year'] ?? 0)];
$discs = all('SELECT id, name FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name'); $people = hr_lv_people();
$fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
layout_head(t('hr.training'), 'hr_training');
?>
<p class="eyebrow"><a href="<?= url('hr_dashboard') ?>"><?= tt('hr.dashboard') ?></a> › <?= tt('hr.training') ?><?= $tr ? ' › ' . e($tr['title']) : '' ?></p>

<?php if ($tr && !$new): $mem = hr_training_members($id); $cand = hr_training_people($tr); $inIds = array_map(fn($m) => (int)$m['person_id'], $mem); ?>
<div class="page-head">
  <div><h1>🎓 <?= e($tr['title']) ?></h1><p class="lede" style="margin-bottom:0;"><?= hr_tr_kind_pill($tr['kind']) ?> <?= hr_tr_status_pill($tr['status']) ?><?= $tr['certificate'] ? ' ' . pill('gold', '🏅 ' . t('hr.tr_cert')) : '' ?> · <?= $tr['date_from'] ? e(fmt_date($tr['date_from'])) . ($tr['date_to'] && $tr['date_to'] !== $tr['date_from'] ? ' – ' . e(fmt_date($tr['date_to'])) : '') : tt('hr.tr_tba') ?><?= $tr['hours'] !== null ? ' · ' . e($fh($tr['hours'])) . ' h' : '' ?><?= $tr['provider'] ? ' · ' . e($tr['provider']) : '' ?><?= $tr['location'] ? ' · ' . e($tr['location']) : '' ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url('hr_training', ['id' => $id, 'edit' => 1]) ?>"><?= tt('btn.edit') ?></a><a class="btn btn--ghost" href="<?= url('hr_training') ?>">‹ <?= tt('hr.tr_list') ?></a></div>
</div>
<div class="cols" style="grid-template-columns: 2fr 1fr;">
<div>
<div class="card">
  <h2><?= tt('hr.tr_members') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($mem) ?><?= $tr['capacity'] ? ' / ' . (int)$tr['capacity'] : '' ?></span></h2>
  <?php if (!$mem): ?><div class="empty"><?= tt('hr.tr_no_members') ?></div><?php else: ?>
  <?php foreach ($mem as $m): ?><form id="mf<?= (int)$m['id'] ?>" method="post" action="<?= url('hr_training', ['id' => $id]) ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_trm_save"><input type="hidden" name="mid" value="<?= (int)$m['id'] ?>"><input type="hidden" name="id" value="<?= $id ?>"></form><?php endforeach; ?>
  <div class="table-scroll"><table class="tbl">
    <thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hr.tr_score') ?></th><th><?= tt('hr.tr_certificate') ?></th><th><?= tt('hr.notes') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($mem as $m): ?>
      <?php $fid = 'mf' . (int)$m['id']; ?><tr>
        <td><a href="<?= url('hr_person', ['id' => (int)$m['person_id'], 'tab' => 'train']) ?>"><b><?= e($m['name_en']) ?></b></a><?= $m['applied_at'] ? '<br><span class="hint">' . tt('hr.tm_applied') . ' ' . e(fmt_datetime($m['applied_at'])) . '</span>' : '' ?></td>
        <td><?= hr_trm_pill($m['status']) ?><br><select form="<?= $fid ?>" name="status" data-nosearch style="margin-top:4px;min-width:130px;"><?php foreach (HR_TRM_STATUS as $s): ?><option value="<?= $s ?>"<?= $m['status'] === $s ? ' selected' : '' ?>><?= e(t('hr.tm_' . $s)) ?></option><?php endforeach; ?></select></td>
        <td><input form="<?= $fid ?>" name="score" value="<?= e((string)$m['score']) ?>" maxlength="20" style="width:90px;" placeholder="<?= tt('hr.tr_score_ph') ?>"></td>
        <td><?php if ($tr['certificate']): ?><div style="display:grid;gap:4px;"><input form="<?= $fid ?>" name="cert_no" value="<?= e((string)$m['cert_no']) ?>" maxlength="60" placeholder="<?= tt('hr.tr_cert_no') ?>" style="width:150px;"><div style="display:flex;gap:4px;"><input form="<?= $fid ?>" type="date" name="cert_issued" value="<?= e((string)$m['cert_issued']) ?>" title="<?= tt('hr.tr_cert_issued') ?>" style="width:140px;"><input form="<?= $fid ?>" type="date" name="cert_expiry" value="<?= e((string)$m['cert_expiry']) ?>" title="<?= tt('hr.tr_cert_expiry') ?>" style="width:140px;"></div><input form="<?= $fid ?>" type="file" name="doc[]" accept=".pdf,.jpg,.jpeg,.png"><?= $m['cert_doc_id'] ? '<a class="hint" href="' . url('hr_pdoc', ['id' => (int)$m['cert_doc_id']]) . '" target="_blank">📎 ' . e((string)$m['doc_name']) . '</a>' : '' ?></div><?php else: ?><span class="hint">—</span><?php endif; ?></td>
        <td><input form="<?= $fid ?>" name="note" value="<?= e((string)$m['note']) ?>" maxlength="300" style="width:160px;"></td>
        <td class="row-actions"><button class="btn btn--gold btn--sm" type="submit" form="<?= $fid ?>"><?= tt('btn.save') ?></button></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
  <?php $left = array_values(array_filter($cand, fn($p) => !in_array((int)$p['id'], $inIds, true))); if ($left): ?>
  <form method="post" action="<?= url('hr_training', ['id' => $id]) ?>" data-lock style="margin-top:12px;display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_tr_add"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field" style="flex:1;min-width:260px;margin:0;"><label><?= tt('hr.tr_add_people') ?></label><select name="person_ids[]" multiple size="6" style="width:100%;"><?php foreach ($left as $pp): ?><option value="<?= (int)$pp['id'] ?>"><?= e($pp['name_en'] . ($pp['department'] ? ' · ' . $pp['department'] : '')) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('hr.multi_hint') ?></p></div>
    <button class="btn btn--ghost" type="submit">➕ <?= tt('hr.tr_add') ?></button>
  </form>
  <?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2><?= tt('hr.tr_details') ?></h2>
  <table class="kv"><tr><th><?= tt('hr.tr_kind') ?></th><td><?= hr_tr_kind_pill($tr['kind']) ?></td></tr><tr><th><?= tt('hr.tr_provider') ?></th><td><?= e((string)($tr['provider'] ?: '—')) ?></td></tr><tr><th><?= tt('hr.tr_trainer') ?></th><td><?= e((string)($tr['trainer'] ?: '—')) ?></td></tr><tr><th><?= tt('hr.tr_location') ?></th><td><?= e((string)($tr['location'] ?: '—')) ?></td></tr><tr><th><?= tt('hr.tr_hours') ?></th><td><?= e($fh($tr['hours'])) ?></td></tr><tr><th><?= tt('hr.tr_target') ?></th><td><?= e(t('hr.tr_tg_' . $tr['target'])) ?> <span class="hint">(<?= count($cand) ?>)</span></td></tr><tr><th><?= tt('hr.tr_capacity') ?></th><td><?= $tr['capacity'] ? (int)$tr['capacity'] : '∞' ?></td></tr><tr><th><?= tt('hr.tr_deadline') ?></th><td><?= $tr['deadline'] ? e(fmt_date($tr['deadline'])) : '—' ?></td></tr><tr><th><?= tt('hr.tr_cost') ?></th><td><?= $tr['cost_eur'] !== null ? '€ ' . number_format((float)$tr['cost_eur'], 2, ',', '.') : '—' ?></td></tr></table>
  <?php if ($tr['description']): ?><p style="margin-top:10px;white-space:pre-wrap;"><?= e($tr['description']) ?></p><?php endif; ?>
  <form method="post" action="<?= url('hr_training') ?>" class="inline" style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_tr_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('hr.tr_delete_confirm') ?>">🗑 <?= tt('btn.delete') ?></button></form>
</div>
</div>
</div>

<?php else: $edit = $tr ?: []; $selIds = array_map('intval', array_filter(explode(',', (string)($edit['target_ids'] ?? '')))); ?>
<div class="page-head">
  <div><h1>🎓 <?= tt('hr.training') ?></h1><p class="lede"><?= tt('hr.training_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="<?= url('hr_training', ['new' => 1]) ?>#tr-form">+ <?= tt('hr.tr_new') ?></a></div>
</div>

<?php if ($new): ?>
<div class="card" id="tr-form">
  <h2><?= $tr ? tt('btn.edit') . ' · ' . e($tr['title']) : tt('hr.tr_new') ?></h2>
  <form method="post" action="<?= url('hr_training') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_tr_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="grid grid-4">
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.tr_title') ?> *</label><input name="title" required maxlength="160" value="<?= e($edit['title'] ?? '') ?>" placeholder="<?= tt('hr.tr_title_ph') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_kind') ?></label><select name="kind" data-nosearch><?php foreach (HR_TR_KINDS as $k): ?><option value="<?= $k ?>"<?= ($edit['kind'] ?? 'internal') === $k ? ' selected' : '' ?>><?= e(t('hr.tk_' . $k)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (HR_TR_STATUS as $s): ?><option value="<?= $s ?>"<?= ($edit['status'] ?? 'open') === $s ? ' selected' : '' ?>><?= e(t('hr.ts_' . $s)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('hr.tr_provider') ?></label><input name="provider" maxlength="120" value="<?= e($edit['provider'] ?? '') ?>" placeholder="<?= tt('hr.tr_provider_ph') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_trainer') ?></label><input name="trainer" maxlength="120" value="<?= e($edit['trainer'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_location') ?></label><input name="location" maxlength="120" value="<?= e($edit['location'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_hours') ?></label><input name="hours" inputmode="decimal" value="<?= e((string)($edit['hours'] ?? '')) ?>" placeholder="8"></div>
      <div class="field"><label><?= tt('pl.from') ?></label><input type="date" name="date_from" value="<?= e($edit['date_from'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('pl.to') ?></label><input type="date" name="date_to" value="<?= e($edit['date_to'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_deadline') ?></label><input type="date" name="deadline" value="<?= e($edit['deadline'] ?? '') ?>"></div>
      <div class="field"><label><?= tt('hr.tr_capacity') ?></label><input type="number" name="capacity" min="0" value="<?= e((string)($edit['capacity'] ?? '')) ?>" placeholder="∞"></div>
      <div class="field"><label><?= tt('hr.tr_cost') ?> (€)</label><input name="cost_eur" inputmode="decimal" value="<?= e((string)($edit['cost_eur'] ?? '')) ?>"></div>
      <div class="field"><label><?= tt('hr.tr_target') ?></label><select name="target" data-nosearch id="tr_target"><option value="all"<?= ($edit['target'] ?? 'all') === 'all' ? ' selected' : '' ?>><?= tt('hr.whole_company') ?></option><option value="disciplines"<?= ($edit['target'] ?? '') === 'disciplines' ? ' selected' : '' ?>><?= tt('hr.by_discipline') ?></option><option value="people"<?= ($edit['target'] ?? '') === 'people' ? ' selected' : '' ?>><?= tt('hr.by_people') ?></option></select></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('hr.tr_cert') ?></label><label class="pl-person" style="display:inline-flex;"><input type="checkbox" name="certificate" value="1"<?= !empty($edit['certificate']) ? ' checked' : '' ?>> <?= tt('hr.tr_cert_hint') ?></label></div>
      <div class="field tr-disc" style="grid-column:1 / -1;"><label><?= tt('nav.disciplines') ?></label><div class="pl-chips" style="min-height:0;"><?php foreach ($discs as $d): ?><label class="pl-person"><input type="checkbox" name="discipline_ids[]" value="<?= (int)$d['id'] ?>"<?= in_array((int)$d['id'], $selIds, true) && ($edit['target'] ?? '') === 'disciplines' ? ' checked' : '' ?>> <?= e($d['name']) ?></label><?php endforeach; ?></div></div>
      <div class="field tr-people" style="grid-column:1 / -1;"><label><?= tt('hr.people') ?></label><select name="person_ids[]" multiple size="8" style="width:100%;"><?php foreach ($people as $pp): ?><option value="<?= (int)$pp['id'] ?>"<?= in_array((int)$pp['id'], $selIds, true) && ($edit['target'] ?? '') === 'people' ? ' selected' : '' ?>><?= e($pp['name_en'] . ($pp['department'] ? ' · ' . $pp['department'] : '')) ?></option><?php endforeach; ?></select><p class="hint"><?= tt('hr.multi_hint') ?></p></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('hr.tr_description') ?></label><textarea data-mt name="description" rows="4" maxlength="8000" placeholder="<?= tt('hr.tr_description_ph') ?>"><?= e($edit['description'] ?? '') ?></textarea></div>
    </div>
    <p class="hint"><?= tt('hr.tr_form_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $tr ? url('hr_training', ['id' => $id]) : url('hr_training') ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<script>(function(){var s=document.getElementById('tr_target');if(!s)return;function f(){document.querySelector('.tr-disc').style.display=s.value==='disciplines'?'':'none';document.querySelector('.tr-people').style.display=s.value==='people'?'':'none';}s.addEventListener('change',f);f();})();</script>
<?php endif; ?>

<?php $rows = hr_trainings($f); $alerts = hr_cert_alerts(90); ?>
<div class="cols" style="grid-template-columns: 2fr 1fr;">
<div>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1fr 1fr 1fr auto;"><input type="hidden" name="p" value="hr_training">
    <div class="field"><label><?= tt('hr.tr_kind') ?></label><select name="kind" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (HR_TR_KINDS as $k): ?><option value="<?= $k ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= e(t('hr.tk_' . $k)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (HR_TR_STATUS as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= e(t('hr.ts_' . $s)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hr.year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= $f['year'] ?: '' ?>" placeholder="<?= date('Y') ?>"></div>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('hr_training') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>
<div class="card">
  <h2><?= tt('hr.tr_list') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><div class="empty"><?= tt('hr.tr_none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.tr_title') ?></th><th><?= tt('hr.tr_kind') ?></th><th><?= tt('hr.dates') ?></th><th><?= tt('hr.tr_hours') ?></th><th><?= tt('hr.tr_members') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><a href="<?= url('hr_training', ['id' => (int)$r['id']]) ?>"><b><?= e($r['title']) ?></b></a><?= $r['provider'] ? '<br><span class="hint">' . e($r['provider']) . '</span>' : '' ?><?= $r['certificate'] ? ' 🏅' : '' ?></td><td><?= hr_tr_kind_pill($r['kind']) ?></td><td class="hint"><?= $r['date_from'] ? e(fmt_date($r['date_from'])) . ($r['date_to'] && $r['date_to'] !== $r['date_from'] ? ' – ' . e(fmt_date($r['date_to'])) : '') : tt('hr.tr_tba') ?><?= $r['deadline'] ? '<br>⏳ ' . e(fmt_date($r['deadline'])) : '' ?></td><td class="num"><?= e($fh($r['hours'])) ?></td><td><?= pill('green', (string)(int)$r['n_in']) ?><?= (int)$r['n_applied'] ? ' ' . pill('gold', (string)(int)$r['n_applied']) : '' ?><?= $r['capacity'] ? ' <span class="hint">/ ' . (int)$r['capacity'] . '</span>' : '' ?></td><td><?= hr_tr_status_pill($r['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin-top:8px;"><?= tt('hr.tr_legend') ?></p>
  <?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2>🏅 <?= tt('hr.tr_cert_alerts') ?></h2>
  <?php if (!$alerts): ?><div class="empty"><?= tt('hr.tr_cert_none') ?></div><?php else: ?>
  <table class="tbl"><thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('hr.tr_certificate') ?></th><th><?= tt('hr.tr_cert_expiry') ?></th></tr></thead><tbody>
  <?php foreach ($alerts as $a): ?><tr><td><a href="<?= url('hr_person', ['id' => (int)$a['id'], 'tab' => 'train']) ?>"><?= e($a['name_en']) ?></a></td><td class="hint"><?= e($a['title']) ?><?= $a['cert_no'] ? ' · ' . e($a['cert_no']) : '' ?></td><td class="<?= (int)$a['left_days'] < 0 ? 'bad' : '' ?>"><?= e(fmt_date($a['cert_expiry'])) ?><br><span class="hint"><?= (int)$a['left_days'] < 0 ? tt('hr.tr_expired') : e(t('hr.tr_days_left', ['n' => (int)$a['left_days']])) ?></span></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php endif; ?>
</div>
<div class="card">
  <h3 class="ac-h" style="margin-top:0;"><?= tt('hr.tr_how_title') ?></h3>
  <ol class="hint" style="padding-left:18px;margin:0;"><li><?= tt('hr.tr_how_1') ?></li><li><?= tt('hr.tr_how_2') ?></li><li><?= tt('hr.tr_how_3') ?></li><li><?= tt('hr.tr_how_4') ?></li></ol>
</div>
</div>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

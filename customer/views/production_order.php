<?php
/** A production order: milestones, units (production cards), documents per phase, photos, log (GAS-CP-R41-001). ?p=production_order&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0);
$o = function_exists('pd_has') && pd_has() ? pd_get($id) : null;
if (!$o) { http_response_code(404); layout_head(t('err.notfound'), 'equipment'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$can = pd_can_edit($o); $backTo = url('production_order', ['id' => $id]);
$G = pd_files_by_phase($o['files']); $ms = pd_milestones($o); $cands = $can ? pd_candidate_units($o) : []; $dws = $can ? pd_project_drawings($o) : [];
$closed = in_array($o['status'], ['closed', 'cancelled'], true);
$dl = fn(array $f) => '<a href="' . url('file', ['id' => (int)$f['file_id']]) . '" target="_blank">' . e($f['note'] ?: $f['original_name']) . '</a>' . ($f['doc_no'] || $f['revision'] ? ' <span class="mono hint">' . e(trim((string)$f['doc_no'] . ' ' . (string)$f['revision'])) . '</span>' : '') . ($f['serial_no'] ? ' ' . pill('navy', $f['serial_no']) : '') . ($f['visibility'] === 'internal' ? ' ' . pill('mute', t('vis.internal')) : '');
layout_head($o['po_no'] . ' · ' . $o['title'], 'equipment');
?>
<p class="eyebrow"><a href="<?= url('equipment') ?>"><?= tt('eq.title') ?></a> › <a href="<?= url('production') ?>"><?= tt('pd.title') ?></a> › <?= e($o['po_no']) ?></p>
<div class="page-head">
  <div><h1><span class="mono" style="color:var(--gold-text);"><?= e($o['po_no']) ?></span> <?= e($o['title']) ?> <?= pd_status_pill($o['status']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= pr_link((int)$o['project_id'], $o['project_code'], $o['project_name']) ?><?= $o['sub_code'] ? ' › <a href="' . url('subproject', ['id' => (int)$o['subproject_id'], 'tab' => 'works']) . '">' . e($o['sub_code'] . ' ' . $o['sub_name']) . '</a>' : '' ?><?= $o['vessel'] ? ' · <a href="' . url('vessel', ['id' => (int)$o['vessel_id'], 'tab' => 'equipment']) . '">' . e($o['vessel']) . '</a>' : '' ?><?= $o['system_name'] ? ' · ' . e($o['system_name']) : '' ?><?= $o['wp_no'] ? ' · <a href="' . url('work', ['id' => (int)$o['work_id']]) . '">' . e($o['wp_no'] . ' ' . $o['work_title']) . '</a>' : '' ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url('production_pdf', ['id' => $id]) ?>" target="_blank">⤓ <?= tt('pd.card') ?> PDF</a><?php if ($can): ?><a class="btn btn--ghost" href="<?= url('production_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?></div>
</div>

<div class="card card--tight pd-ms">
  <?php foreach (PD_OPEN as $i => $st): ?><div class="pd-ms__step pd-ms__step--<?= $ms[$st] ?>"><span class="pd-ms__dot"><?= $ms[$st] === 'done' ? '✓' : $i + 1 ?></span><span><?= e(pd_status_label($st)) ?></span></div><?php endforeach; ?>
  <div class="pd-ms__step pd-ms__step--<?= $o['status'] === 'closed' ? 'done' : ($o['status'] === 'cancelled' ? 'off' : 'todo') ?>"><span class="pd-ms__dot"><?= $o['status'] === 'closed' ? '✓' : '●' ?></span><span><?= e(pd_status_label($o['status'] === 'cancelled' ? 'cancelled' : 'closed')) ?></span></div>
</div>

<div class="grid grid-2">
  <div class="card">
    <table class="kv"><tbody>
      <tr><th><?= tt('pd.manager') ?></th><td><?= e($o['manager'] ?: '—') ?></td></tr>
      <tr><th>PM</th><td><?= e($o['project_manager'] ?: '—') ?></td></tr>
      <tr><th><?= tt('pd.planned') ?></th><td><?= $o['planned_start'] ? e(fmt_date($o['planned_start'])) : '…' ?> – <?= $o['planned_end'] ? e(fmt_date($o['planned_end'])) : '…' ?><?= $o['planned_end'] && !$closed && $o['planned_end'] < date('Y-m-d') ? ' ' . pill('bad', t('pd.late')) : '' ?></td></tr>
      <tr><th><?= tt('pd.delivered_on') ?></th><td><?= $o['delivered_at'] ? e(fmt_date($o['delivered_at'])) : '—' ?></td></tr>
      <tr><th><?= tt('pd.progress') ?></th><td><b><?= (int)$o['pct'] ?> %</b> <?= pd_pct_bar((int)$o['pct']) ?><span class="hint"><?= (int)$o['n_units'] ? tt('pd.pct_from_units') : tt('pd.pct_manual') ?></span></td></tr>
      <?php if ($o['description']): ?><tr><th><?= tt('tk.description') ?></th><td><?= rt_html($o['description']) ?></td></tr><?php endif; ?>
    </tbody></table>
  </div>
  <?php if ($can && !$closed || ($can && $closed && is_cp_admin())): ?>
  <div class="card">
    <h2><?= tt('pd.move_to') ?></h2>
    <form method="post" action="<?= $backTo ?>" data-lock class="pd-status"><?= csrf_field() ?><input type="hidden" name="action" value="pd_status"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field"><label><?= tt('pd.phase') ?></label><select name="status" data-nosearch><?php foreach (PD_STATUSES as $st): ?><option value="<?= $st ?>"<?= $st === $o['status'] ? ' selected' : '' ?>><?= e(pd_status_label($st)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="400" placeholder="<?= tt('pd.status_note_ph') ?>"></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
    </form>
    <p class="hint" style="margin:6px 0 0;"><?= tt('pd.status_hint') ?></p>
  </div>
  <?php endif; ?>
</div>

<?php if ($o['subproject_id'] && function_exists('pd_stages_has') && pd_stages_has()): $ST = pd_order_stages($o); $spMan = pd_sub_manual((int)$o['subproject_id']); ?>
<div class="card" id="stages">
  <div class="card-head"><h2>📈 <?= tt('pd.stages') ?></h2><span class="hint"><?= tt('pd.stages_hint') ?></span></div>
  <form method="post" action="<?= $backTo ?>#stages" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pd_stages"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="table-scroll"><table class="tbl pd-stages"><thead><tr><th><?= tt('sp.stage') ?></th><th class="num"><?= tt('pd.st_auto') ?></th><th><?= tt('pd.st_rule') ?></th><?php if ($can): ?><th class="num"><?= tt('pd.st_override') ?></th><?php endif; ?><th class="num"><?= tt('pd.st_effective') ?></th></tr></thead><tbody>
  <?php foreach (PD_STAGE_SLUGS as $k): $v = $ST[$k]; $un = in_array($k, $spMan, true); ?>
    <tr><td><b><?= e(t('st.' . $k)) ?></b><?= $un ? ' <span class="pill pill--mute" title="' . e(t('pd.unlocked_hint')) . '">🔓</span>' : '' ?></td>
      <td class="num"><?= $v['auto'] === null ? '<span class="hint">' . tt('pd.st_none') . '</span>' : (int)$v['auto'] . ' %' ?></td>
      <td class="hint"><?= e(t('pd.rule_' . $k)) ?></td>
      <?php if ($can): ?><td class="num"><?php if ($k === 'production'): ?><span class="hint">—</span><?php else: ?><input name="st[<?= $k ?>]" type="number" min="0" max="100" value="<?= $v['override'] !== null ? (int)$v['override'] : '' ?>" placeholder="<?= tt('pd.st_auto') ?>" style="width:70px;"><?php endif; ?></td><?php endif; ?>
      <td class="num"><?= $v['value'] === null ? '<span class="hint">' . tt('pd.st_manual_pm') . '</span>' : '<b>' . (int)$v['value'] . ' %</b>' . pd_pct_bar((int)$v['value']) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if ($can): ?><div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button> <a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => (int)$o['subproject_id']]) ?>#stage-editor"><?= tt('tab.process') ?> ›</a></div><?php endif; ?>
  </form>
</div>
<?php endif; ?>

<div class="card" id="units">
  <div class="card-head"><h2><?= tt('pd.units') ?> <span class="hint">(<?= count($o['units']) ?>)</span></h2><span class="hint"><?= tt('pd.units_hint') ?></span></div>
  <?php if (!$o['units']): ?><p class="hint"><?= tt('pd.no_units') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl pd-units"><thead><tr><th><?= tt('eq.sn') ?></th><th><?= tt('eq.model') ?></th><th><?= tt('th.status') ?></th><th class="num">%</th><th><?= tt('pd.unit_note') ?></th><th><?= tt('pd.updated') ?></th><?php if ($can): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($o['units'] as $un): $fid = 'pu' . (int)$un['id']; ?>
    <tr>
      <td class="mono"><a href="<?= url('equipment_view', ['id' => (int)$un['equipment_id']]) ?>#production"><b><?= e($un['serial_no']) ?></b></a><?= $un['set_code'] ? '<br><span class="hint">' . e($un['set_code']) . '</span>' : '' ?></td>
      <td><?= e(trim($un['maker'] . ' ' . ($un['model'] ?: ''))) ?><br><span class="hint"><?= e(t('eq.k_' . $un['kind'])) ?></span><?= !empty($un['external']) ? '<br><a class="pill pill--gold" href="#subs" title="' . e($un['external']['scope'] . ' · ' . t('ps.s_' . $un['external']['status'])) . '">🔗 ' . tt('ps.external') . ' · ' . e($un['external']['supplier']) . '</a>' : '' ?></td>
      <?php if ($can && !$closed): ?>
      <td><form method="post" action="<?= $backTo ?>#units" id="<?= $fid ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pd_unit"><input type="hidden" name="unit_id" value="<?= (int)$un['id'] ?>"></form>
        <select form="<?= $fid ?>" name="status" data-nosearch><?php foreach (PD_USTATUSES as $st): ?><option value="<?= $st ?>"<?= $st === $un['status'] ? ' selected' : '' ?>><?= e(t('pd.u_' . $st)) ?></option><?php endforeach; ?></select></td>
      <td class="num"><input form="<?= $fid ?>" name="pct" type="number" min="0" max="100" value="<?= (int)$un['pct'] ?>" style="width:64px;"><?= pd_pct_bar((int)$un['pct']) ?></td>
      <td><input form="<?= $fid ?>" name="note" maxlength="500" value="<?= e((string)$un['note']) ?>" placeholder="<?= tt('pd.unit_note_ph') ?>"></td>
      <?php else: ?>
      <td><?= pd_ustatus_pill($un['status']) ?></td><td class="num"><b><?= (int)$un['pct'] ?> %</b><?= pd_pct_bar((int)$un['pct']) ?></td><td><?= e((string)$un['note']) ?></td>
      <?php endif; ?>
      <td class="hint"><?= e(fmt_datetime($un['updated_at'])) ?><?= $un['updated_name'] ? '<br>' . e($un['updated_name']) : '' ?></td>
      <?php if ($can): ?><td class="row-actions stack"><?php if (!$closed): ?><button form="<?= $fid ?>" class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><?php endif; ?>
        <form method="post" action="<?= $backTo ?>#units" class="inline" data-lock data-confirm="<?= tt('pd.unit_remove_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pd_unit_remove"><input type="hidden" name="unit_id" value="<?= (int)$un['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('pd.unit_remove') ?>">×</button></form></td><?php endif; ?>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($can && !$closed): ?>
  <details class="pd-add" style="margin-top:10px;"><summary class="btn btn--ghost btn--sm">+ <?= tt('pd.add_units') ?></summary>
    <form method="post" action="<?= $backTo ?>#units" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="pd_units_add"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="grid grid-2">
        <div class="field"><label><?= tt('pd.new_serials') ?></label><textarea name="new_serials" rows="4" data-plain placeholder="<?= tt('pd.new_serials_ph') ?>"></textarea><p class="hint"><?= tt('pd.new_serials_hint') ?></p>
          <div class="grid grid-3" style="margin-top:6px;">
            <div class="field"><label><?= tt('eq.maker') ?></label><input name="new_maker" value="Golden A/S" maxlength="60"></div>
            <div class="field"><label><?= tt('eq.model') ?></label><input name="new_model" maxlength="120"></div>
            <div class="field"><label><?= tt('eq.kind') ?></label><select name="new_kind" data-nosearch><?php foreach (eq_kinds() as $k => $l): ?><option value="<?= $k ?>"<?= $k === 'switchboard' ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
          </div><div class="field" style="margin-top:6px;"><label><?= tt('eq.set') ?></label><input name="new_set" maxlength="80" placeholder="<?= tt('pd.set_ph') ?>"></div></div>
        <div class="field"><label><?= tt('pd.existing_units') ?></label>
          <?php if (!$cands): ?><p class="hint"><?= tt('pd.no_candidates') ?></p><?php else: ?><div class="pd-cands"><?php foreach ($cands as $c): ?><label class="chk"><input type="checkbox" name="eq_ids[]" value="<?= (int)$c['id'] ?>"> <span class="mono"><?= e($c['serial_no']) ?></span> <span class="hint"><?= e(trim($c['maker'] . ' ' . ($c['model'] ?: ''))) ?></span></label><?php endforeach; ?></div><?php endif; ?></div>
      </div>
      <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit"><?= tt('pd.add_units') ?></button></div>
    </form>
  </details>
  <?php endif; ?>
</div>

<?php if (function_exists('ps_has') && ps_has()): $subs = $o['subs']; $eps = (int)($_GET['sub'] ?? 0); $ep = null; foreach ($subs as $x) if ((int)$x['id'] === $eps) $ep = $x; $fin = fin_visible(); $cos = $can && function_exists('co_all') && co_has() ? co_all() : []; ?>
<div class="card" id="subs">
  <div class="card-head"><h2>🔗 <?= tt('ps.title') ?> <span class="hint">(<?= count($subs) ?>)</span></h2><span class="hint"><?= tt('ps.hint') ?></span></div>
  <?php if (!$subs): ?><p class="hint"><?= tt('ps.none_order') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('ps.supplier') ?></th><th><?= tt('ps.scope') ?></th><th><?= tt('eq.sn') ?></th><th><?= tt('ps.po_no') ?></th><th><?= tt('ps.dates') ?></th><th><?= tt('th.status') ?></th><th class="num">%</th><?php if ($fin): ?><th class="num"><?= tt('ps.amount') ?></th><?php endif; ?><th><?= tt('ps.inspection') ?></th><?php if ($can): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($subs as $ps): $late = $ps['due_at'] && !in_array($ps['status'], ['delivered', 'inspected', 'accepted', 'cancelled'], true) && $ps['due_at'] < date('Y-m-d'); ?>
    <tr><td><b><?= e($ps['supplier']) ?></b></td><td><?= e($ps['scope']) ?><?= $ps['whole'] ? ' ' . pill('gold', t('ps.whole')) : '' ?><?= $ps['note'] ? '<br><span class="hint">' . e($ps['note']) . '</span>' : '' ?></td>
      <td class="mono"><?= $ps['serial_no'] ? e($ps['serial_no']) : '<span class="hint">' . tt('pd.all_units') . '</span>' ?></td><td class="mono"><?= e($ps['po_no'] ?: '—') ?></td>
      <td class="hint" style="white-space:nowrap;"><?= $ps['ordered_at'] ? tt('ps.ordered') . ' ' . e(fmt_date($ps['ordered_at'])) . '<br>' : '' ?><?= $ps['due_at'] ? '<span' . ($late ? ' class="is-late"' : '') . '>' . tt('ps.due') . ' ' . e(fmt_date($ps['due_at'])) . ($late ? ' ' . pill('bad', t('pd.late')) : '') . '</span><br>' : '' ?><?= $ps['delivered_at'] ? tt('ps.delivered') . ' ' . e(fmt_date($ps['delivered_at'])) : '' ?></td>
      <td><?= ps_status_pill($ps['status']) ?></td><td class="num"><b><?= (int)$ps['pct'] ?> %</b><?= pd_pct_bar((int)$ps['pct']) ?></td>
      <?php if ($fin): ?><td class="num"><?= e(ps_money($ps['amount'] !== null ? (float)$ps['amount'] : null, $ps['currency'])) ?></td><?php endif; ?>
      <td class="hint"><?= e((string)$ps['inspection_note']) ?><?= (int)$ps['n_files'] ? ' <a href="#docs">📎 ' . (int)$ps['n_files'] . '</a>' : '' ?></td>
      <?php if ($can): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('production_order', ['id' => $id, 'sub' => (int)$ps['id']]) ?>#ps-form">✎</a><form method="post" action="<?= $backTo ?>#subs" class="inline" data-lock data-confirm="<?= tt('ps.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ps_delete"><input type="hidden" name="ps_id" value="<?= (int)$ps['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td><?php endif; ?></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <?php if ($can && !$closed): ?>
  <details class="pd-add" id="ps-form" style="margin-top:10px;"<?= $ep ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm"><?= $ep ? '✎ ' . tt('ps.edit') : '+ ' . tt('ps.new') ?></summary>
    <form method="post" action="<?= $backTo ?>#subs" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="ps_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="ps_id" value="<?= (int)($ep['id'] ?? 0) ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('ps.supplier') ?> *</label><input name="supplier" maxlength="120" value="<?= e((string)($ep['supplier'] ?? '')) ?>" list="ps-suppliers" placeholder="<?= tt('ps.supplier_ph') ?>"><datalist id="ps-suppliers"><?php foreach (ps_suppliers() as $sn): ?><option value="<?= e($sn) ?>"><?php endforeach; ?></datalist></div>
        <?php if ($cos): ?><div class="field"><label><?= tt('co.one') ?></label><select name="company_id"><option value="">—</option><?php foreach ($cos as $co): ?><option value="<?= (int)$co['id'] ?>"<?= (int)($ep['company_id'] ?? 0) === (int)$co['id'] ? ' selected' : '' ?>><?= e($co['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
        <div class="field"><label><?= tt('eq.sn') ?></label><select name="unit_id" data-nosearch><option value=""><?= tt('pd.all_units') ?></option><?php foreach ($o['units'] as $un): ?><option value="<?= (int)$un['id'] ?>"<?= (int)($ep['unit_id'] ?? 0) === (int)$un['id'] ? ' selected' : '' ?>><?= e($un['serial_no']) ?></option><?php endforeach; ?></select><label class="chk" style="margin-top:4px;font-size:12px;"><input type="checkbox" name="whole" value="1"<?= !empty($ep['whole']) ? ' checked' : '' ?>> <?= tt('ps.whole_long') ?></label></div>
        <div class="field" style="grid-column: span 2;"><label><?= tt('ps.scope') ?> *</label><input name="scope" maxlength="200" value="<?= e((string)($ep['scope'] ?? '')) ?>" placeholder="<?= tt('ps.scope_ph') ?>"></div>
        <div class="field"><label><?= tt('ps.po_no') ?></label><input name="po_no" maxlength="60" value="<?= e((string)($ep['po_no'] ?? '')) ?>" placeholder="GAS-PUR-…"></div>
        <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (PS_STATUSES as $st): ?><option value="<?= $st ?>"<?= ($ep['status'] ?? 'planned') === $st ? ' selected' : '' ?>><?= e(t('ps.s_' . $st)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>%</label><input name="pct" type="number" min="0" max="100" value="<?= $ep ? (int)$ep['pct'] : '' ?>" placeholder="<?= tt('ps.pct_auto') ?>"></div>
        <?php if ($fin): ?><div class="field"><label><?= tt('ps.amount') ?></label><div class="grid grid-2" style="gap:6px;"><input name="amount" inputmode="decimal" value="<?= $ep && $ep['amount'] !== null ? e(number_format((float)$ep['amount'], 2, ',', '.')) : '' ?>"><input name="currency" maxlength="3" value="<?= e((string)($ep['currency'] ?? 'EUR')) ?>" style="width:70px;"></div></div><?php endif; ?>
        <div class="field"><label><?= tt('ps.ordered') ?></label><input type="date" name="ordered_at" value="<?= e((string)($ep['ordered_at'] ?? '')) ?>"></div>
        <div class="field"><label><?= tt('ps.due') ?></label><input type="date" name="due_at" value="<?= e((string)($ep['due_at'] ?? '')) ?>"></div>
        <div class="field"><label><?= tt('ps.delivered') ?></label><input type="date" name="delivered_at" value="<?= e((string)($ep['delivered_at'] ?? '')) ?>"></div>
        <div class="field" style="grid-column: span 2;"><label><?= tt('ps.inspection') ?></label><input name="inspection_note" maxlength="500" value="<?= e((string)($ep['inspection_note'] ?? '')) ?>" placeholder="<?= tt('ps.inspection_ph') ?>"></div>
        <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="500" value="<?= e((string)($ep['note'] ?? '')) ?>"></div>
      </div>
      <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><?php if ($ep): ?><a class="btn btn--ghost" href="<?= $backTo ?>#subs"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
      <p class="hint" style="margin:6px 0 0;"><?= tt('ps.form_hint') ?></p>
    </form>
  </details>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card" id="docs">
  <div class="card-head"><h2><?= tt('pd.documents') ?> <span class="hint">(<?= count($o['files']) ?>)</span></h2><span class="hint"><?= tt('pd.docs_hint') ?></span></div>
  <div class="pd-phases">
  <?php foreach (PD_PHASES as $ph => [$kind, $vis]): $fs = $G[$ph]; if (!$fs && in_array($ph, ['other'], true)) continue; ?>
    <section class="pd-phase pd-phase--<?= $fs ? 'has' : 'empty' ?>">
      <h3><?= e(pd_phase_label($ph)) ?> <span class="hint"><?= count($fs) ?></span></h3>
      <?php if (!$fs): ?><p class="hint"><?= tt('pd.phase_empty') ?></p>
      <?php elseif ($ph === 'photo'): ?>
        <div class="fsr-photos"><?php foreach ($fs as $f): ?><figure><?php if (pd_is_image($f)): ?><a href="<?= url('file', ['id' => (int)$f['file_id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => (int)$f['file_id']]) ?>" alt="" loading="lazy"></a><?php else: ?><a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => (int)$f['file_id']]) ?>" target="_blank">⤓ <?= e($f['original_name']) ?></a><?php endif; ?>
          <figcaption><span><?= e($f['note'] ?: fmt_date(substr((string)$f['uploaded_at'], 0, 10))) ?><?= $f['serial_no'] ? ' · ' . e($f['serial_no']) : '' ?></span><?php if ($can): ?><form method="post" action="<?= $backTo ?>#docs" class="inline" data-lock data-confirm="<?= tt('pd.file_remove_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pd_file_remove"><input type="hidden" name="pf_id" value="<?= (int)$f['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('pd.file_remove') ?>">×</button></form><?php endif; ?></figcaption></figure><?php endforeach; ?></div>
      <?php else: ?>
        <ul class="pd-files"><?php foreach ($fs as $f): ?><li><?= $dl($f) ?> <span class="hint">· <?= e(fmt_date(substr((string)$f['uploaded_at'], 0, 10))) ?><?= $f['by_name'] ? ' · ' . e($f['by_name']) : '' ?></span><?php if ($can): ?> <form method="post" action="<?= $backTo ?>#docs" class="inline" data-lock data-confirm="<?= tt('pd.file_remove_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="pd_file_remove"><input type="hidden" name="pf_id" value="<?= (int)$f['id'] ?>"><button class="linkbtn" type="submit" title="<?= tt('pd.file_remove') ?>">×</button></form><?php endif; ?></li><?php endforeach; ?></ul>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>
  </div>
  <?php if ($can): ?>
  <div class="grid grid-2" style="margin-top:12px;">
    <form method="post" action="<?= $backTo ?>#docs" enctype="multipart/form-data" data-lock class="card card--tight"><?= csrf_field() ?><input type="hidden" name="action" value="pd_file_add"><input type="hidden" name="id" value="<?= $id ?>">
      <h3 style="margin-top:0;">⤒ <?= tt('pd.upload') ?></h3>
      <div class="grid grid-2">
        <div class="field"><label><?= tt('pd.phase') ?></label><select name="phase" data-nosearch id="pd-phase"><?php foreach (PD_PHASES as $ph => [$kind, $vis]): ?><option value="<?= $ph ?>" data-vis="<?= $vis ?>"><?= e(pd_phase_label($ph)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('eq.sn') ?></label><select name="unit_id" data-nosearch><option value=""><?= tt('pd.all_units') ?></option><?php foreach ($o['units'] as $un): ?><option value="<?= (int)$un['id'] ?>"><?= e($un['serial_no']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('pd.doc_no') ?></label><input name="doc_no" maxlength="80" placeholder="GAS-DWG-…"></div>
        <div class="field"><label><?= tt('dr.rev') ?></label><input name="revision" maxlength="16" placeholder="D1 / R0 / AB"></div>
        <div class="field"><label><?= tt('pd.note') ?></label><input name="note" maxlength="255" placeholder="<?= tt('pd.file_note_ph') ?>"></div>
        <div class="field"><label><?= tt('vis.label') ?></label><select name="visibility" data-nosearch id="pd-vis"><option value="internal"><?= tt('vis.internal') ?></option><option value="external"><?= tt('vis.external') ?></option></select></div>
      </div>
      <div class="field"><label><?= tt('dr.file') ?></label><input type="file" name="files[]" multiple required accept=".pdf,.dwg,.dxf,.png,.jpg,.jpeg,.webp,.docx,.xlsx,.zip"></div>
      <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('fl.upload') ?></button></div>
    </form>
    <div class="card card--tight">
      <h3 style="margin-top:0;">🔗 <?= tt('pd.link_drawing') ?></h3>
      <?php if (!$dws): ?><p class="hint"><?= tt('pd.no_drawings') ?></p><?php else: ?>
      <form method="post" action="<?= $backTo ?>#docs" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="pd_file_link"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field"><label><?= tt('tab.drawings') ?></label><select name="file_id"><?php foreach ($dws as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e(trim(($d['doc_no'] ? $d['doc_no'] . ' ' . $d['revision'] . ' · ' : '') . ($d['title'] ?: $d['original_name']))) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('pd.phase') ?></label><select name="phase" data-nosearch><?php foreach (['draft', 'approved', 'asbuilt', 'other'] as $ph): ?><option value="<?= $ph ?>"<?= $ph === 'approved' ? ' selected' : '' ?>><?= e(pd_phase_label($ph)) ?></option><?php endforeach; ?></select></div>
        <div class="btn-row"><button class="btn" type="submit"><?= tt('pd.link') ?></button></div>
      </form>
      <?php endif; ?>
      <p class="hint" style="margin:8px 0 0;"><?= tt('pd.link_hint') ?></p>
    </div>
  </div>
  <script nonce="<?= csp_nonce() ?>">(function(){var p=document.getElementById('pd-phase'),v=document.getElementById('pd-vis');if(!p||!v)return;function s(){var o=p.options[p.selectedIndex];if(o)v.value=o.dataset.vis||'internal';}p.addEventListener('change',s);s();})();</script>
  <?php endif; ?>
</div>

<div class="card" id="log">
  <h2><?= tt('pd.log') ?> <span class="hint">(<?= count($o['log']) ?>)</span></h2>
  <?php if (!$o['log']): ?><p class="hint">—</p><?php else: ?>
  <ul class="eq-tl"><?php foreach ($o['log'] as $l): ?><li><span class="eq-d" style="margin-right:8px;"><?= e(fmt_datetime($l['created_at'])) ?></span><span><b><?= e(t('pd.ev_' . $l['event'])) ?></b><?= $l['note'] ? ' — ' . e($l['note']) : '' ?> <span class="hint">· <?= e((string)$l['user_name']) ?></span></span></li><?php endforeach; ?></ul>
  <?php endif; ?>
</div>
<?php if (is_cp_admin()): $adAction = 'pd_delete'; $adId = $id; $adBack = 'production'; $adConfirm = t('pd.delete_confirm', ['no' => $o['po_no']]); $adNote = t('pd.delete_note'); require __DIR__ . '/_admin_delete.php'; endif; ?>
<?php layout_foot(); ?>

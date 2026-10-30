<?php
/**
 * Drawing tracker — project level.                          GAS-CP-R3-001
 * Grouped by vessel system, summary by status, revision control, and
 * (staff) import straight from the Excel tracker.
 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$pid = (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p');
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", array_merge([$pid], $args));
if (!$p) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff = is_staff();

$st  = preg_replace('/[^a-z_]/', '', (string)($_GET['status'] ?? ''));
$w   = 'd.project_id = ?'; $a = [$pid];
if (in_array($st, ['pending','received','not_required'], true)) { $w .= ' AND d.status = ?'; $a[] = $st; }
$rows = all("SELECT d.*, vs.name AS system_name, vs.sort_order, s.code AS sub_code
             FROM drawing_requests d
             LEFT JOIN vessel_systems vs ON vs.id = d.system_id
             LEFT JOIN subprojects s ON s.id = d.subproject_id
             WHERE {$w} ORDER BY d.kind DESC, vs.sort_order, vs.name, d.seq_no, d.id", $a);

$groups = []; $sum = [];
foreach ($rows as $r) {
    $g = $r['kind'] === 'principal' ? t('dr.principal') : ($r['system_name'] ?: t('sys.none'));
    $groups[$g][] = $r;
    $sum[$g] = $sum[$g] ?? ['total' => 0, 'pending' => 0, 'received' => 0, 'not_required' => 0];
    $sum[$g]['total']++; $sum[$g][$r['status']]++;
}
$tot = ['total' => 0, 'pending' => 0, 'received' => 0, 'not_required' => 0];
foreach ($sum as $s) foreach ($tot as $k => $v) $tot[$k] += $s[$k];
$revs = all('SELECT * FROM drawing_tracker_revisions WHERE project_id = ? ORDER BY issued_at DESC, id DESC', [$pid]);
$subs = $staff ? all('SELECT id, code, name, system_id FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]) : [];
$systems = $staff ? sys_options() : [];
$ed = $staff && (int)($_GET['edit'] ?? 0) ? one('SELECT * FROM drawing_requests WHERE id = ? AND project_id = ?', [(int)$_GET['edit'], $pid]) : null;   // R36-003: edit one request
$self = url('drawings', ['project' => $pid]);
$today = date('Y-m-d');

layout_head(t('dr.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?></p>
<div class="page-head">
  <div><h1><?= tt('dr.title') ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($p['name']) ?> · <?= e($p['vessel'] ?: '') ?></p></div>
</div>

<div class="kpi" style="margin-bottom:16px;">
  <a class="stat" href="<?= url('drawings', ['project' => $pid]) ?>"><b><?= $tot['total'] ?></b><?= tt('sub.total') ?></a>
  <a class="stat gold" href="<?= url('drawings', ['project' => $pid, 'status' => 'pending']) ?>"><b><?= $tot['pending'] ?></b><?= tt('dr.pending') ?></a>
  <a class="stat green" href="<?= url('drawings', ['project' => $pid, 'status' => 'received']) ?>"><b><?= $tot['received'] ?></b><?= tt('dr.received') ?></a>
  <a class="stat" href="<?= url('drawings', ['project' => $pid, 'status' => 'not_required']) ?>"><b><?= $tot['not_required'] ?></b><?= tt('dr.not_required') ?></a>
</div>

<?php if ($staff): ?>
<div class="card" id="dr-form">
  <h2><?= $ed ? tt('dr.edit') . ' · #' . (int)$ed['seq_no'] . ' ' . e($ed['title']) : tt('dr.add') ?></h2>
  <p class="hint"><?= tt('dr.add_hint') ?></p>
  <form method="post" action="<?= $self ?><?= $ed ? '' : '#dr-form' ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="<?= $ed ? 'dr_update' : 'dr_save' ?>"><input type="hidden" name="project_id" value="<?= $pid ?>"><?php if ($ed): ?><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"><?php endif; ?>
    <?php $v = fn(string $k, string $d = '') => e((string)($ed[$k] ?? $d)); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; ?>
    <div class="grid grid-3">
      <div class="field" style="grid-column:span 2;"><label><?= tt('dr.item') ?> *</label><input name="title" required maxlength="200" value="<?= $v('title') ?>" placeholder="<?= tt('dr.item_ph') ?>"></div>
      <div class="field"><label><?= tt('dr.kind') ?></label><select name="kind" data-nosearch><option value="equipment"<?= $sel('equipment', $ed['kind'] ?? 'equipment') ?>><?= tt('dr.equipment') ?></option><option value="principal"<?= $sel('principal', $ed['kind'] ?? '') ?>><?= tt('dr.principal') ?></option></select></div>
      <div class="field"><label><?= tt('sp.one') ?></label><select name="subproject_id"><option value=""><?= tt('fsr.whole_project') ?></option><?php foreach ($subs as $s): ?><option value="<?= $s['id'] ?>" data-system="<?= (int)$s['system_id'] ?>"<?= $sel($s['id'], $ed['subproject_id'] ?? '') ?>><?= e(trim($s['code'] . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('sys.one') ?></label><select name="system_id"><option value=""><?= tt('sys.none') ?></option><?php foreach ($systems as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($s['id'], $ed['system_id'] ?? '') ?>><?= e(sys_label($s)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dr.responsible') ?></label><input name="responsible" list="dr-resp" value="<?= $v('responsible', 'Shipyard') ?>" maxlength="80"><datalist id="dr-resp"><option value="Shipyard"><option value="Owner"><option value="Golden A/S"><option value="Supplier"></datalist></div>
      <div class="field"><label><?= tt('dr.location') ?></label><input name="location" maxlength="120" value="<?= $v('location') ?>"></div>
      <div class="field"><label><?= tt('dr.locstat') ?></label><select name="location_status" data-nosearch><?php foreach (['TBD', 'Confirmed', 'N/A'] as $ls): ?><option<?= $sel($ls, $ed['location_status'] ?? 'TBD') ?>><?= $ls ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dr.sent') ?></label><input name="request_sent" type="date" value="<?= $v('request_sent', $ed ? '' : $today) ?>"></div>
      <div class="field"><label><?= tt('dr.expected') ?></label><input name="expected_at" type="date" value="<?= $v('expected_at') ?>"></div>
      <div class="field"><label><?= tt('dr.received') ?></label><input name="received_at" type="date" value="<?= $v('received_at') ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (['pending', 'received', 'not_required'] as $st2): ?><option value="<?= $st2 ?>"<?= $sel($st2, $ed['status'] ?? 'pending') ?>><?= tt('dr.' . $st2) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('dr.file') ?></label><input name="file" type="file"><?php if ($ed && $ed['file_id']): ?><span class="hint"><?= e($ed['file_name']) ?></span><?php endif; ?></div>
      <div class="field" style="grid-column:span 2;"><label><?= tt('th.remarks') ?></label><input name="remarks" maxlength="2000" value="<?= $v('remarks') ?>"></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $ed ? tt('btn.save') : tt('dr.add_btn') ?></button><?php if ($ed): ?><a class="btn btn--ghost" href="<?= $self ?>"><?= tt('btn.cancel') ?></a><button form="drdel<?= (int)$ed['id'] ?>" class="btn btn--ghost" type="submit" style="margin-left:auto;color:var(--cp-red,#B42318);"><?= tt('btn.delete') ?></button><?php endif; ?></div>
  </form>
  <?php if ($ed): ?><form method="post" action="<?= $self ?>" id="drdel<?= (int)$ed['id'] ?>" data-confirm="<?= e(t('dr.delete_confirm', ['t' => $ed['title']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="dr_delete"><input type="hidden" name="id" value="<?= (int)$ed['id'] ?>"></form><?php endif; ?>
</div>
<details class="card">
  <summary style="cursor:pointer;list-style:none;"><h2 style="display:inline;"><?= tt('dr.import') ?></h2> <span class="hint"><?= tt('dr.import_fold') ?></span></summary>
  <p class="hint"><?= tt('dr.import_hint') ?></p>
  <form method="post" action="<?= url('drawings', ['project' => $pid]) ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('dr.importing') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="dr_import"><input type="hidden" name="project_id" value="<?= $pid ?>">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('dr.xlsx') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
      <div class="field"><label><?= tt('dr.rev_desc') ?></label><input name="rev_desc" maxlength="255" placeholder="<?= tt('dr.rev_desc_ph') ?>"></div>
      <div class="field"><label class="chk"><input type="checkbox" name="replace" value="1"> <?= tt('dr.replace') ?></label></div>
    </div>
    <button class="btn" type="submit"><?= tt('dr.import_btn') ?></button>
  </form>
</details>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><div class="empty"><p><?= tt('dr.empty') ?></p></div></div>
<?php else: ?>
<div class="card">
  <h2><?= tt('dr.summary') ?></h2>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('sys.one') ?></th><th><?= tt('sub.total') ?></th><th><?= tt('dr.pending') ?></th><th><?= tt('dr.received') ?></th><th><?= tt('dr.not_required') ?></th><th><?= tt('dr.progress') ?></th></tr></thead>
    <tbody><?php foreach ($sum as $g => $s): $done = $s['total'] - $s['pending']; $pct = $s['total'] ? (int) round(100 * $done / $s['total']) : 0; ?>
      <tr><td><strong><?= e($g) ?></strong></td><td class="num"><?= $s['total'] ?></td><td class="num"><?= $s['pending'] ?></td>
        <td class="num"><?= $s['received'] ?></td><td class="num"><?= $s['not_required'] ?></td>
        <td><div class="pbar" style="width:140px;display:inline-block;vertical-align:middle;"><i style="width:<?= $pct ?>%;background:var(--cp-green)"></i></div> <span class="num"><?= $pct ?>%</span></td></tr>
    <?php endforeach; ?></tbody></table></div>
</div>

<?php foreach ($groups as $g => $list): ?>
<details class="card" open>
  <summary style="cursor:pointer;list-style:none;"><h2 style="display:inline;"><?= e($g) ?></h2> <span class="hint"><?= e(t('dr.nitems', ['n' => count($list)])) ?></span></summary>
  <div class="table-scroll" style="margin-top:10px;"><table>
    <thead><tr><th>#</th><th><?= tt('dr.item') ?></th><th><?= tt('dr.location') ?></th><th><?= tt('dr.responsible') ?></th>
      <th><?= tt('dr.sent') ?></th><th><?= tt('dr.expected') ?></th><th><?= tt('dr.received') ?></th><th><?= tt('th.status') ?></th><th><?= tt('dr.file') ?></th><th><?= tt('th.remarks') ?></th><?php if ($staff): ?><th></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($list as $r): $late = $r['status'] === 'pending' && $r['expected_at'] && $r['expected_at'] < $today; ?>
      <tr class="<?= $late ? 'is-late' : '' ?>"><td class="num"><?= (int)$r['seq_no'] ?></td>
        <td><strong><?= e($r['title']) ?></strong><?= $r['sub_code'] ? '<br><span class="hint">' . e($r['sub_code']) . '</span>' : '' ?></td>
        <td><?= e($r['location'] ?: '—') ?> <span class="hint"><?= e($r['location_status']) ?></span></td>
        <td><?= e($r['responsible']) ?></td>
        <td class="date"><?= e(fmt_date($r['request_sent'])) ?></td><td class="date<?= $late ? ' bg-neg' : '' ?>"><?= e(fmt_date($r['expected_at'])) ?><?= $late ? '<br><span class="hint bg-neg">' . e(t('dr.late_n', ['n' => (int) floor((strtotime($today) - strtotime($r['expected_at'])) / 86400)])) . '</span>' : '' ?></td>
        <td class="date"><?= e(fmt_date($r['received_at'])) ?></td><td><?= drawing_pill($r['status']) ?></td>
        <td><?= $r['file_id'] ? '<a href="' . url('file', ['id' => $r['file_id']]) . '">' . e($r['file_name'] ?: tt('dr.open')) . '</a>' : e($r['file_name'] ?: '—') ?></td>
        <td class="hint"><?= e($r['remarks'] ?: '') ?></td>
        <?php if ($staff): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('drawings', ['project' => $pid, 'edit' => $r['id']]) ?>#dr-form">✎</a><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="dr_update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="received"><?php foreach (['subproject_id', 'system_id', 'kind', 'title', 'location', 'location_status', 'responsible', 'request_sent', 'expected_at', 'remarks'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)($r[$k] ?? '')) ?>"><?php endforeach; ?><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('dr.mark_received_hint') ?>">✓ <?= tt('dr.mark_received') ?></button></form><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
</details>
<?php endforeach; ?>
<?php endif; ?>

<div class="card">
  <h2><?= tt('dr.revisions') ?></h2>
  <?php if (!$revs): ?><p class="hint"><?= tt('dr.norev') ?></p><?php else: ?>
  <table><thead><tr><th><?= tt('dr.rev') ?></th><th><?= tt('th.date') ?></th><th><?= tt('dr.rev_desc') ?></th><th><?= tt('dr.issued_by') ?></th><th><?= tt('dr.sent_to') ?></th></tr></thead>
  <tbody><?php foreach ($revs as $r): ?>
    <tr><td class="ref"><?= e($r['rev_no']) ?></td><td class="date"><?= e(fmt_date($r['issued_at'])) ?></td><td><?= e($r['description']) ?></td><td><?= e($r['issued_by']) ?></td><td><?= e($r['sent_to'] ?: '—') ?></td></tr>
  <?php endforeach; ?></tbody></table>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

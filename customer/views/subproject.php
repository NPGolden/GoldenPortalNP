<?php
/**
 * Sub-project hub.                                          GAS-CP-R3-001
 * Tabs: process · drawings · owner approvals · class · tests · weekly ·
 * comments (external chat + internal) · files · reports.
 * Staff see edit forms; customers see external data and may post in chat.
 */
require __DIR__ . '/_layout.php';
$u   = require_login();
$id  = (int)($_GET['id'] ?? 0);
$sp  = load_subproject($id);
if (!$sp) { http_response_code(404); layout_head(t('err.notfound'), 'projects'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }

$pid   = (int)$sp['project_id'];
$staff = is_staff();
$tab   = preg_replace('/[^a-z]/', '', (string)($_GET['tab'] ?? 'process'));
[$vis, ] = vis_sql('visibility');

/* ---- counts for the tab badges ------------------------------------ */
$cnt = [
  'drawings' => (int) scalar("SELECT COUNT(*) FROM drawing_requests WHERE status = 'pending' AND (subproject_id = ? OR (project_id = ? AND subproject_id IS NULL))", [$id, $pid]),   // R36-004: pending, this sub-project + the project-wide ones
  'owner'    => (int) scalar('SELECT COUNT(*) FROM owner_approvals WHERE subproject_id = ?', [$id]),
  'class'    => (int) scalar('SELECT COUNT(*) FROM class_submittals WHERE subproject_id = ?', [$id]),
  'works'    => function_exists('wp_has') && wp_has() ? (int) scalar("SELECT COUNT(*) FROM sp_works WHERE subproject_id = ? AND status IN ('planned','assigned','in_progress')", [$id]) : 0,
  'tests'    => (int) scalar('SELECT COUNT(*) FROM test_reports WHERE subproject_id = ?', [$id]),
  'weekly'   => (int) scalar("SELECT COUNT(*) FROM sp_notes WHERE subproject_id = ? AND {$vis}", [$id]),
  'comments' => (int) scalar("SELECT COUNT(*) FROM sp_comments WHERE subproject_id = ? AND {$vis}", [$id]),
  'files'    => (int) scalar("SELECT COUNT(*) FROM sp_files WHERE subproject_id = ? AND {$vis}", [$id]),
  'issues'   => function_exists('iss_open_count') ? iss_open_count($pid, $id) : 0,
  'risks'    => function_exists('rk_has') && rk_has() ? (int) scalar("SELECT COUNT(*) FROM sp_risks WHERE subproject_id = ? AND status IN ('open','occurred')", [$id]) : 0,   // R39-001
  'service'  => (int) scalar("SELECT COUNT(*) FROM fsr_reports WHERE subproject_id = ?" . ($staff ? '' : " AND status <> 'draft'"), [$id])
              + (isr_has() ? (int) scalar("SELECT COUNT(*) FROM isr_reports WHERE subproject_id = ?" . ($staff ? '' : " AND status = 'issued'"), [$id]) : 0),
];
$tabs = ['process','drawings','owner','class','tests','weekly','service','issues','comments','files','reports'];
if ($staff && function_exists('rk_has') && rk_has()) array_splice($tabs, array_search('comments', $tabs, true), 0, ['risks']);   // R39-001
if ($staff && !empty($sp['vessel_id']) && function_exists('ct_has') && ct_has()) $tabs[] = 'contacts';
if (function_exists('bg_can') && bg_can()) array_splice($tabs, array_search('reports', $tabs, true), 0, ['budget']);   // R34-001
if ($staff && function_exists('wp_has') && wp_has()) array_splice($tabs, 1, 0, ['works']);   // R35-001
if ($staff && function_exists('eng_has') && eng_has()) { array_splice($tabs, array_search('process', $tabs, true) + 1, 0, ['engineering']); $engSt = eng_sub_state($id); if (in_array($engSt, ['exceeded','pending','warn','extended'], true)) $cnt['engineering'] = '⚠'; }   // R47-001
if (!in_array($tab, $tabs, true)) $tab = 'process';

$systems = $staff ? sys_options() : [];
$backTo  = url('subproject', ['id' => $id, 'tab' => $tab]);

layout_head($sp['code'] . ' · ' . $sp['name'], 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= pr_link($pid, $sp['project_code'], $sp['project_name']) ?></p>
<div class="page-head">
  <div>
    <h1><span class="scode" style="font-family:var(--mono);color:var(--gold-text);"><?= e($sp['code'] ?: '') ?></span> <?= e($sp['name']) ?><?= $staff && br_has() && br_multi() ? ' ' . br_pill(br_of($pid, $id)) : '' ?></h1>
    <p class="lede" style="margin-bottom:0;">
      <?= e($sp['vessel'] ?: tt('pr.novessel')) ?><?= $sp['system_name'] ? ' · ' . e($sp['system_name']) : '' ?>
      <?= $staff ? ' · ' . e($sp['customer_name'] ?? '') : '' ?>
      · <strong><?= overall_pct($sp) ?>%</strong> <?= tt('sp.overall') ?>
      <?= inv_has() ? ' · ' . inv_pill($sp) : '' ?><?php if ($staff && function_exists('fs_pills') && fin_visible() && ($fsp = fs_pills((int)$sp['project_id'], $id))): ?> · <?= $fsp ?><?php endif; ?><?php if ($staff && function_exists('iv_for_sub') && fin_visible() && ($ivr = iv_for_sub($sp))): ?> · <span title="<?= tt('iv.title') ?>">🧾 <?= e(iv_label($ivr)) ?><?= (int)($sp['invoicing_id'] ?? 0) ? '' : ' <span class="hint">(' . tt('iv.sub_default_short') . ')</span>' ?></span><?php endif; ?>
    </p>
  </div>
  <div class="actions">
    <?php if ($staff): ?><a class="btn btn--ghost" href="<?= url('subproject_edit', ['id' => $id]) ?>">✎ <?= tt('sp.edit') ?></a><?php endif; ?>
    <a class="btn btn--ghost" href="<?= url('report_sp', ['id' => $id]) ?>" target="_blank"><?= tt('rp.pdf') ?></a>
    <a class="btn btn--gold" href="<?= url('report_sp', ['id' => $id, 'format' => 'docx']) ?>"><?= tt('rp.word') ?></a>
  </div>
</div>

<?php if (function_exists('bg_strip_html')) echo bg_strip_html($sp); ?>
<div class="card">
  <?= process_legend() ?>
  <?= process_line($staff && function_exists('pd_sub_fed') ? $sp + ['_fed' => pd_sub_fed($id)] : $sp) ?>
  <?php if ($sp['description']): ?><p class="hint" style="margin:14px 0 0;"><?= rt_html($sp['description']) ?></p><?php endif; ?>
</div>

<nav class="tabs">
  <?php foreach ($tabs as $tname): ?>
    <a href="<?= url('subproject', ['id' => $id, 'tab' => $tname]) ?>" class="<?= $tab === $tname ? 'is-on' : '' ?>">
      <?= tt('tab.' . $tname) ?><?php if (isset($cnt[$tname]) && $cnt[$tname]): ?><span class="n"><?= $cnt[$tname] ?></span><?php endif; ?>
    </a>
  <?php endforeach; ?>
</nav>

<?php /* =============================================== PROCESS ========= */ if ($tab === 'process'): ?>
<?php if ($staff && tm_has()): $tmMembers = tm_members_full($id); $tmIds = array_map('intval', array_column($tmMembers, 'id')); $tmEdit = tm_can_edit($id); $pmId = (int) scalar('SELECT pm_user_id FROM projects WHERE id = ?', [$pid]); ?>
<div class="card" id="team">
  <div class="page-head" style="margin-bottom:6px;"><div><h2 style="margin:0;"><?= tt('tm.title') ?> <span class="hint" style="font-weight:400;">(<?= count($tmMembers) ?>)</span></h2><p class="hint"><?= tt('tm.lede') ?></p></div>
    <?php if ($tmEdit): ?><button class="btn btn--ghost btn--sm" type="button" id="js-team-edit">✎ <?= tt('tm.edit') ?></button><?php endif; ?></div>
  <div class="tm-list"><?php if ($tmMembers): foreach ($tmMembers as $m): ?><span class="tm-chip"><?= avatar_html((int)$m['id'], $m['full_name'], 22, 'avatar avatar--xs') ?> <?= e($m['full_name']) ?><?= $m['discipline'] ? ' <em>' . e($m['discipline']) . '</em>' : '' ?></span><?php endforeach; else: ?><span class="hint"><?= tt('tm.none') ?></span><?php endif; ?>
    <?php if ($pmId): ?><span class="tm-chip tm-chip--pm" title="<?= tt('tm.pm_always') ?>"><?= e((string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$pmId])) ?> <em>PM</em></span><?php endif; ?></div>
  <?php if ($tmEdit): ?>
  <form method="post" action="<?= url('subproject', ['id' => $id, 'tab' => 'process']) ?>" id="team-form" style="display:none;margin-top:12px;" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="sp_team_save"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="tm-grid"><?php foreach (tm_staff_by_discipline() as $disc => $people): ?><div class="tm-group"><h3 class="ac-h"><?= e($disc) ?></h3>
      <?php foreach ($people as $pp): ?><label class="chk"><input type="checkbox" name="team[]" value="<?= (int)$pp['id'] ?>"<?= in_array((int)$pp['id'], $tmIds, true) ? ' checked' : '' ?><?= (int)$pp['id'] === $pmId ? ' disabled checked' : '' ?>> <?= e($pp['full_name']) ?><?= $pp['role'] === 'admin' ? ' <span class="hint">· admin</span>' : '' ?></label><?php endforeach; ?></div><?php endforeach; ?></div>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><button class="btn btn--ghost" type="button" id="js-team-cancel"><?= tt('btn.cancel') ?></button><span class="hint"><?= tt('tm.save_hint') ?></span></div>
  </form>
  <script nonce="<?= csp_nonce() ?>">(function(){var b=document.getElementById('js-team-edit'),f=document.getElementById('team-form'),c=document.getElementById('js-team-cancel');if(!b)return;b.addEventListener('click',function(){f.style.display=f.style.display==='none'?'':'none';});c.addEventListener('click',function(){f.style.display='none';});})();</script>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if (function_exists('of_has') && of_has() && of_visible()): ?><div class="card"><?php $otRows = of_list(['sub' => $id]); $otShowSub = false; $otNewUrl = url('offer_edit', ['project' => $pid, 'sub' => $id]); require __DIR__ . '/_offers_table.php'; ?></div><?php endif; ?>
<?php if (function_exists('td_card_html') && ($tdc = td_card_html(['sub' => $id], url('task_edit', ['project' => $pid, 'sub' => $id]), false)) !== ''): ?><div class="card"><?= $tdc ?></div><?php endif; ?>
<div class="card">
  <h2><?= tt('tab.process') ?></h2>
  <?php if ($staff): ?>
    <?php if (function_exists('pd_stages_has') && pd_stages_has()): $fedK = pd_sub_fed($id); $fst = $fedK || pd_sub_manual($id) ? pd_sub_stages($id) : []; $man = pd_sub_manual($id); foreach (stage_defs() as $slug => $d): if (!$fst || ($fst[$slug]['value'] === null)) continue; $isUn = in_array($slug, $man, true); ?><form method="post" action="<?= $backTo ?>" id="stlock-<?= $slug ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sp_stage_lock"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="stage" value="<?= $slug ?>"><input type="hidden" name="unlock" value="<?= $isUn ? '0' : '1' ?>"></form><?php endforeach; endif; ?>
    <form method="post" action="<?= $backTo ?>" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="sp_stages"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="stage-editor" id="stage-editor">
        <?php foreach (stage_defs() as $slug => $d): ?>
          <div class="field" style="margin:0;">
            <label for="st_<?= $slug ?>"><?= tt($d['key']) ?></label>
            <?php $isNa = pct_na($sp[$d['col']]); ?>
            <?php $fedK = $fedK ?? (function_exists('pd_sub_fed') ? pd_sub_fed($id) : []); $fst = $fst ?? ($fedK ? pd_sub_stages($id) : []); $man = $man ?? (function_exists('pd_sub_manual') ? pd_sub_manual($id) : []); $fed = in_array($slug, $fedK, true) ? (int)$fst[$slug]['value'] : null; $unlocked = in_array($slug, $man, true) && $fst && $fst[$slug]['value'] !== null; ?>
            <input id="st_<?= $slug ?>" name="<?= $d['col'] ?>" type="number" min="0" max="100" step="5" value="<?= $fed !== null ? $fed : ($isNa ? 0 : (int)$sp[$d['col']]) ?>"<?= $isNa ? ' disabled' : ($fed !== null ? ' readonly title="' . e(t('pd.fed_hint')) . '" style="background:var(--cp-gold-dim);"' : '') ?> class="js-pct">
            <?php if ($fed !== null): ?><p class="hint" style="margin:2px 0 0;"><?php if (!empty($fst[$slug]['eng'])): ?>📐 <a href="<?= url('subproject', ['id' => $id, 'tab' => 'engineering']) ?>#st-<?= $slug ?>"><?= tt('eng.fed_short') ?></a> <?= $fed ?> % · <?php else: ?>🏭 <?= tt('pd.fed_short') ?> <?php endif; ?><?php foreach (empty($fst[$slug]['eng']) ? $fst[$slug]['orders'] : [] as $fo): ?><a href="<?= url('production_order', ['id' => (int)$fo['id']]) ?>#stages" title="<?= $fo['override'] ? tt('pd.st_override') : tt('pd.st_auto') ?>"><?= e($fo['po_no']) ?></a> <?= (int)$fo['value'] ?> %<?= $fo['override'] ? ' ✎' : '' ?> · <?php endforeach; ?><button class="linkbtn" type="submit" form="stlock-<?= $slug ?>" title="<?= tt('pd.unlock_hint') ?>">🔓 <?= tt('pd.unlock') ?></button></p>
            <?php elseif ($unlocked): ?><p class="hint" style="margin:2px 0 0;">🔓 <?= tt('pd.unlocked_hint') ?> <button class="linkbtn" type="submit" form="stlock-<?= $slug ?>" title="<?= tt('pd.lock_hint') ?>">🔒 <?= tt('pd.lock') ?></button></p><?php endif; ?>
            <?php if ($fed === null): ?><label class="chk st-na" title="<?= tt('sp.na_long') ?>"><input type="checkbox" name="na[<?= $d['col'] ?>]" value="1" class="js-na"<?= $isNa ? ' checked' : '' ?>> <?= tt('sp.na') ?></label><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="grid grid-2" style="margin-top:16px;">
        <div class="field"><label for="sp_status"><?= tt('th.status') ?></label>
          <select id="sp_status" name="status">
            <?php foreach (pm_statuses() as $st): ?>
              <option value="<?= $st ?>"<?= $sp['status'] === $st ? ' selected' : '' ?>><?= tt('pr.' . $st) ?></option>
            <?php endforeach; ?>
          </select><?php if (of_quoted_has()): ?><p class="hint"><?= tt('pr.status_rule') ?></p><?php endif; ?></div>
        <div class="field"><label for="sp_system"><?= tt('sys.one') ?></label>
          <select id="sp_system" name="system_id"><option value=""><?= tt('sys.none') ?></option>
            <?php foreach ($systems as $s): ?>
              <option value="<?= $s['id'] ?>"<?= (int)$sp['system_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= e(sys_label($s)) ?></option>
            <?php endforeach; ?>
          </select></div>
      </div>
      <?php if (inv_has()): ?>
      <div class="grid grid-2">
        <div class="field"><label for="sp_inv"><?= tt('inv.title') ?></label>
          <select id="sp_inv" name="invoicing"><?php foreach (inv_states() as $k => $l): ?><option value="<?= $k ?>"<?= ($sp['invoicing'] ?? 'none') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label for="sp_invpct"><?= tt('inv.pct') ?></label><input id="sp_invpct" name="invoiced_pct" type="number" min="0" max="100" step="5" value="<?= (int)($sp['invoiced_pct'] ?? 0) ?>"><p class="hint"><?= tt('inv.pct_hint') ?></p></div>
      </div>
      <?php endif; ?>
      <div class="field"><label for="sp_desc"><?= tt('sp.description') ?></label>
        <textarea id="sp_desc" name="description"><?= e($sp['description'] ?? '') ?></textarea></div>
      <div class="actions">
        <button class="btn" type="submit"><?= tt('btn.save') ?></button>
        <button class="btn btn--ghost" type="submit" name="snapshot" value="1"><?= tt('sp.snapshot') ?></button>
      </div>
      <p class="hint"><?= tt('sp.snapshot_hint') ?></p>
    </form>
  <?php else: ?>
    <p class="hint"><?= tt('sp.process_lede') ?></p>
  <?php endif; ?>

  <?php $snaps = all('SELECT * FROM sp_progress_snapshots WHERE subproject_id = ? ORDER BY snap_date DESC LIMIT 12', [$id]);
        if ($snaps): ?>
    <h3 style="margin-top:22px;"><?= tt('sp.history') ?></h3>
    <div class="table-scroll"><table>
      <thead><tr><th><?= tt('th.date') ?></th><?php foreach (stage_defs() as $d): ?><th><?= tt($d['key']) ?></th><?php endforeach; ?></tr></thead>
      <tbody><?php foreach ($snaps as $sn): ?>
        <tr><td class="date"><?= e(fmt_date($sn['snap_date'])) ?></td>
          <?php foreach (stage_defs() as $d): ?><td class="num"><?= e(pct_txt($sn[$d['col']])) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</div>

<?php /* =============================================== DRAWINGS ======== */ elseif ($tab === 'drawings'):
  /* R36-004: the tab shows the sub-project's own requests first, then the project-wide ones (no sub-project set — e.g. the imported Excel tracker),
     with the status summary, overdue marks and the staff actions of the project tracker (✎ edit, ✓ received). */
  $rows = all("SELECT d.*, vs.name AS system_name, s.code AS sub_code FROM drawing_requests d LEFT JOIN vessel_systems vs ON vs.id = d.system_id LEFT JOIN subprojects s ON s.id = d.subproject_id
               WHERE d.subproject_id = ? OR (d.project_id = ? AND d.subproject_id IS NULL)
               ORDER BY d.subproject_id IS NULL, d.status = 'pending' DESC, d.expected_at IS NULL, d.expected_at, d.kind DESC, d.seq_no, d.id", [$id, $pid]);
  $mine = array_values(array_filter($rows, fn($r) => (int)$r['subproject_id'] === $id)); $other = array_values(array_filter($rows, fn($r) => $r['subproject_id'] === null));
  $tot = ['total' => 0, 'pending' => 0, 'received' => 0, 'not_required' => 0, 'late' => 0]; $today = date('Y-m-d');
  foreach ($rows as $r) { $tot['total']++; $tot[$r['status']]++; if ($r['status'] === 'pending' && $r['expected_at'] && $r['expected_at'] < $today) $tot['late']++; }
  $drTable = function (array $list) use ($staff, $pid, $today, $backTo) { ?>
  <div class="table-scroll"><table>
    <thead><tr><th>#</th><th><?= tt('dr.item') ?></th><th><?= tt('dr.location') ?></th><th><?= tt('dr.responsible') ?></th>
      <th><?= tt('dr.sent') ?></th><th><?= tt('dr.expected') ?></th><th><?= tt('dr.received') ?></th><th><?= tt('th.status') ?></th><th><?= tt('dr.file') ?></th><?php if ($staff): ?><th></th><?php endif; ?></tr></thead>
    <tbody><?php foreach ($list as $r): $late = $r['status'] === 'pending' && $r['expected_at'] && $r['expected_at'] < $today; ?>
      <tr class="<?= $late ? 'is-late' : '' ?>"><td class="num"><?= (int)$r['seq_no'] ?></td>
        <td><strong><?= e($r['title']) ?></strong><br><span class="hint"><?= e($r['system_name'] ?: ($r['kind'] === 'principal' ? t('dr.principal') : '')) ?></span><?= $r['remarks'] ? '<br><span class="hint">' . e($r['remarks']) . '</span>' : '' ?></td>
        <td><?= e($r['location'] ?: '—') ?> <span class="hint"><?= e($r['location_status']) ?></span></td>
        <td><?= e($r['responsible']) ?></td>
        <td class="date"><?= e(fmt_date($r['request_sent'])) ?></td><td class="date<?= $late ? ' bg-neg' : '' ?>"><?= e(fmt_date($r['expected_at'])) ?><?= $late ? '<br><span class="hint bg-neg">' . e(t('dr.late_n', ['n' => (int) floor((strtotime($today) - strtotime($r['expected_at'])) / 86400)])) . '</span>' : '' ?></td>
        <td class="date"><?= e(fmt_date($r['received_at'])) ?></td><td><?= drawing_pill($r['status']) ?></td>
        <td><?= $r['file_id'] ? '<a href="' . url('file', ['id' => $r['file_id']]) . '">' . e($r['file_name'] ?: tt('dr.open')) . '</a>' : e($r['file_name'] ?: '—') ?></td>
        <?php if ($staff): ?><td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url('drawings', ['project' => $pid, 'edit' => $r['id']]) ?>#dr-form">✎</a><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= $backTo ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="dr_update"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="status" value="received"><?php foreach (['subproject_id', 'system_id', 'kind', 'title', 'location', 'location_status', 'responsible', 'request_sent', 'expected_at', 'remarks'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string)($r[$k] ?? '')) ?>"><?php endforeach; ?><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('dr.mark_received_hint') ?>">✓ <?= tt('dr.mark_received') ?></button></form><?php endif; ?></td><?php endif; ?></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php }; ?>
<div class="card">
  <div class="page-head" style="margin-bottom:8px;"><h2 style="margin:0;"><?= tt('dr.title') ?></h2>
    <a class="btn btn--ghost btn--sm" href="<?= url('drawings', ['project' => $pid]) ?>"><?= tt('dr.all_project') ?></a></div>
  <?php if ($rows): ?>
  <div class="kpi" style="margin-bottom:14px;">
    <span class="stat"><b><?= $tot['total'] ?></b><?= tt('sub.total') ?></span>
    <span class="stat gold"><b><?= $tot['pending'] ?></b><?= tt('dr.pending') ?></span>
    <span class="stat<?= $tot['late'] ? ' red' : '' ?>"><b><?= $tot['late'] ?></b><?= tt('dr.overdue') ?></span>
    <span class="stat green"><b><?= $tot['received'] ?></b><?= tt('dr.received') ?></span>
    <span class="stat"><b><?= $tot['not_required'] ?></b><?= tt('dr.not_required') ?></span>
  </div>
  <?php endif; ?>
  <?php if (!$rows): ?><div class="empty"><p><?= tt('dr.empty') ?></p></div><?php else: ?>
    <?php if ($mine): ?><h3 class="ed-h"><?= tt('dr.of_this_sub') ?> <span class="hint"><?= e(t('dr.nitems', ['n' => count($mine)])) ?></span></h3><?php $drTable($mine); ?><?php endif; ?>
    <?php if ($other): ?><h3 class="ed-h" style="margin-top:<?= $mine ? '18px' : '0' ?>;"><?= tt('dr.of_project') ?> <span class="hint"><?= e(t('dr.nitems', ['n' => count($other)])) ?></span></h3><p class="hint" style="margin:0 0 8px;"><?= tt('dr.of_project_hint') ?></p><?php $drTable($other); ?><?php endif; ?>
  <?php endif; ?>
  <?php if ($staff): ?>
    <h3 style="margin-top:20px;"><?= tt('dr.add') ?></h3>
    <p class="hint"><?= tt('dr.add_hint') ?></p>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="dr_save"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field" style="grid-column:span 2;"><label><?= tt('dr.item') ?> *</label><input name="title" required maxlength="200" placeholder="<?= tt('dr.item_ph') ?>"></div>
        <div class="field"><label><?= tt('dr.kind') ?></label><select name="kind" data-nosearch><option value="equipment"><?= tt('dr.equipment') ?></option><option value="principal"><?= tt('dr.principal') ?></option></select></div>
        <div class="field"><label><?= tt('sys.one') ?></label><select name="system_id"><option value=""><?= tt('sys.none') ?></option>
          <?php foreach ($systems as $s): ?><option value="<?= $s['id'] ?>"<?= (int)$sp['system_id'] === (int)$s['id'] ? ' selected' : '' ?>><?= e(sys_label($s)) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('dr.responsible') ?></label><input name="responsible" list="dr-resp" value="Shipyard" maxlength="80"><datalist id="dr-resp"><option value="Shipyard"><option value="Owner"><option value="Golden A/S"><option value="Supplier"></datalist></div>
        <div class="field"><label><?= tt('dr.location') ?></label><input name="location" maxlength="120"></div>
        <div class="field"><label><?= tt('dr.locstat') ?></label><select name="location_status" data-nosearch><option>TBD</option><option>Confirmed</option><option>N/A</option></select></div>
        <div class="field"><label><?= tt('dr.sent') ?></label><input name="request_sent" type="date" value="<?= $today ?>"></div>
        <div class="field"><label><?= tt('dr.expected') ?></label><input name="expected_at" type="date"></div>
        <div class="field"><label><?= tt('dr.received') ?></label><input name="received_at" type="date"></div>
        <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="pending"><?= tt('dr.pending') ?></option><option value="received"><?= tt('dr.received') ?></option><option value="not_required"><?= tt('dr.not_required') ?></option></select></div>
        <div class="field"><label><?= tt('dr.file') ?></label><input name="file" type="file"></div>
        <div class="field" style="grid-column:span 2;"><label><?= tt('th.remarks') ?></label><input name="remarks" maxlength="2000"></div>
      </div>
      <button class="btn btn--gold" type="submit"><?= tt('dr.add_btn') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php /* =============================================== RISKS (R39) ===== */ elseif ($tab === 'risks'): $sid = $id; require __DIR__ . '/_risks.php'; ?>
<?php /* ========================================= OWNER / CLASS ========= */ elseif ($tab === 'owner' || $tab === 'class'):
  $isClass = $tab === 'class';
  $table   = $isClass ? 'class_submittals' : 'owner_approvals';
  $rows    = all("SELECT s.*, f.original_name FROM {$table} s LEFT JOIN sp_files f ON f.id = s.file_id WHERE s.subproject_id = ? ORDER BY s.submitted_at DESC, s.id DESC", [$id]);
  $kpi = ['submitted' => 0, 'approved' => 0, 'rejected' => 0];
  foreach ($rows as $r) { if ($r['status'] === 'submitted' || $r['status'] === 'resubmitted') $kpi['submitted']++; elseif (str_starts_with($r['status'], 'approved')) $kpi['approved']++; elseif ($r['status'] === 'rejected') $kpi['rejected']++; } ?>
<div class="card">
  <h2><?= tt($isClass ? 'cls.title' : 'own.title') ?></h2>
  <div class="kpi" style="margin-bottom:16px;">
    <div class="stat gold"><b><?= $kpi['submitted'] ?></b><?= tt('sub.awaiting') ?></div>
    <div class="stat green"><b><?= $kpi['approved'] ?></b><?= tt('sub.approved') ?></div>
    <div class="stat"><b><?= $kpi['rejected'] ?></b><?= tt('sub.rejected') ?></div>
    <div class="stat"><b><?= count($rows) ?></b><?= tt('sub.total') ?></div>
  </div>
  <?php if (!$rows): ?><div class="empty"><p><?= tt('sub.empty') ?></p></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('sub.docno') ?></th><th><?= tt('sub.doc') ?></th><?= $isClass ? '<th>' . tt('cls.society') . '</th>' : '' ?><th><?= tt('sub.rev') ?></th>
      <th><?= tt('sub.submitted') ?></th><th><?= tt('sub.due') ?></th><th><?= tt('sub.decided') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.comments') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td class="ref"><?= $isClass ? e($r['doc_no']) : '<a href="' . url('approval', ['id' => $r['id']]) . '">' . e($r['doc_no']) . '</a>' ?></td>
        <td><strong><?= e($r['title']) ?></strong><?= $r['file_id'] ? '<br><a href="' . url('file', ['id' => $r['file_id']]) . '">' . e($r['original_name']) . '</a>' : '' ?>
          <?php if (!$isClass): $openRow = in_array($r['status'], ['submitted', 'resubmitted'], true); ?>
            <br><a class="btn <?= $openRow && !$staff ? 'btn--gold' : 'btn--ghost' ?> btn--sm" style="margin-top:6px;" href="<?= url('approval', ['id' => $r['id']]) ?>"><?= $openRow && !$staff ? tt('oa.review') : tt('oa.open_thread') ?></a>
          <?php endif; ?></td>
        <?= $isClass ? '<td>' . e($r['class_society']) . ($r['class_ref'] ? '<br><span class="hint">' . e($r['class_ref']) . '</span>' : '') . '</td>' : '' ?>
        <td class="num"><?= e($r['revision']) ?></td><td class="date"><?= e(fmt_date($r['submitted_at'])) ?></td>
        <td class="date"><?= e(fmt_date($r['due_at'])) ?></td><td class="date"><?= e(fmt_date($r['decided_at'])) ?></td>
        <td><?= submittal_pill($r['status']) ?></td><td><?= e($r['comments'] ?: '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php if ($staff): ?>
    <h3 style="margin-top:20px;"><?= tt('sub.add') ?></h3>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="<?= $isClass ? 'cls_save' : 'own_save' ?>">
      <input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('sub.docno') ?></label><input name="doc_no" required maxlength="60"></div>
        <div class="field"><label><?= tt('sub.doc') ?></label><input name="title" required maxlength="200"></div>
        <div class="field"><label><?= tt('sub.rev') ?></label><input name="revision" value="R0" maxlength="16"></div>
        <?php if ($isClass): ?>
          <div class="field"><label><?= tt('cls.society') ?></label><select name="class_society"><?php foreach (['DNV','BV','LR','ABS','RINA','Other'] as $cs): ?><option><?= $cs ?></option><?php endforeach; ?></select></div>
          <div class="field"><label><?= tt('cls.ref') ?></label><input name="class_ref" maxlength="80"></div>
        <?php endif; ?>
        <div class="field"><label><?= tt('sub.submitted') ?></label><input name="submitted_at" type="date" value="<?= date('Y-m-d') ?>"></div>
        <div class="field"><label><?= tt('sub.due') ?></label><input name="due_at" type="date"></div>
        <div class="field"><label><?= tt('sub.decided') ?></label><input name="decided_at" type="date"></div>
        <div class="field"><label><?= tt('th.status') ?></label><select name="status"><?php foreach (['submitted','approved','approved_comments','rejected','resubmitted','draft'] as $st): ?><option value="<?= $st ?>"><?= tt('sub.' . $st) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('dr.file') ?></label><input name="file" type="file"></div>
        <div class="field"><label><?= tt('th.comments') ?></label><input name="comments" maxlength="500"></div>
      </div>
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php /* ================================================= TESTS ========= */ elseif ($tab === 'tests'):
  $rows = all('SELECT t.*, f.original_name FROM test_reports t LEFT JOIN sp_files f ON f.id = t.file_id WHERE t.subproject_id = ? ORDER BY FIELD(t.kind,"FAT","HAT","SAT"), t.planned_at', [$id]); ?>
<div class="card">
  <h2><?= tt('tst.title') ?></h2>
  <?php if (!$rows): ?><div class="empty"><p><?= tt('tst.empty') ?></p></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('tst.kind') ?></th><th><?= tt('sub.doc') ?></th><th><?= tt('tst.planned') ?></th><th><?= tt('tst.performed') ?></th><th><?= tt('th.status') ?></th><th><?= tt('tst.report') ?></th><th><?= tt('th.remarks') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr><td><strong><?= e($r['kind']) ?></strong></td><td><?= e($r['title']) ?></td>
        <td class="date"><?= e(fmt_date($r['planned_at'])) ?></td><td class="date"><?= e(fmt_date($r['performed_at'])) ?></td>
        <td><?= test_pill($r['status']) ?></td>
        <td><?= $r['file_id'] ? '<a href="' . url('file', ['id' => $r['file_id']]) . '">' . e($r['original_name']) . '</a>' : '—' ?></td>
        <td><?= e($r['remarks'] ?: '—') ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
  <?php if ($staff): ?>
    <h3 style="margin-top:20px;"><?= tt('tst.add') ?></h3>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock>
      <?= csrf_field() ?><input type="hidden" name="action" value="tst_save"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('tst.kind') ?></label><select name="kind"><option>FAT</option><option>HAT</option><option>SAT</option></select></div>
        <div class="field"><label><?= tt('sub.doc') ?></label><input name="title" required maxlength="200"></div>
        <div class="field"><label><?= tt('th.status') ?></label><select name="status"><?php foreach (['planned','performed','passed','punch_open','failed','closed'] as $st): ?><option value="<?= $st ?>"><?= tt('tst.' . $st) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label><?= tt('tst.planned') ?></label><input name="planned_at" type="date"></div>
        <div class="field"><label><?= tt('tst.performed') ?></label><input name="performed_at" type="date"></div>
        <div class="field"><label><?= tt('tst.report') ?></label><input name="file" type="file"></div>
        <div class="field"><label><?= tt('th.remarks') ?></label><input name="remarks" maxlength="500"></div>
      </div>
      <button class="btn" type="submit"><?= tt('btn.save') ?></button>
    </form>
  <?php endif; ?>
</div>

<?php /* ================================================ WEEKLY ========= */ elseif ($tab === 'weekly'):
  $notes = all("SELECT n.*, cu.full_name FROM sp_notes n JOIN customer_users cu ON cu.id = n.author_id
                WHERE n.subproject_id = ? AND {$vis} ORDER BY n.note_date DESC, n.id DESC LIMIT 60", [$id]);
  $photos = [];
  if ($notes) {
    $nids = implode(',', array_map('intval', array_column($notes, 'id')));
    foreach (all("SELECT * FROM sp_files WHERE note_id IN ({$nids}) AND kind = 'photo' AND {$vis}") as $ph) $photos[(int)$ph['note_id']][] = $ph;
  } ?>
<div class="card">
  <div class="page-head" style="margin-bottom:8px;"><h2 style="margin:0;"><?= tt('wk.notes') ?></h2>
    <a class="btn btn--ghost btn--sm" href="<?= url('weekly', ['project' => $pid]) ?>"><?= tt('wk.report') ?></a></div>
  <?php if ($staff): ?>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock style="margin-bottom:20px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="note_save"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('th.date') ?></label><input name="note_date" type="date" value="<?= date('Y-m-d') ?>" required></div>
        <div class="field"><label><?= tt('vis.label') ?></label><select name="visibility"><option value="external"><?= tt('vis.external') ?></option><option value="internal"><?= tt('vis.internal') ?></option></select></div>
        <div class="field"><label><?= tt('wk.photos') ?></label><input name="photos[]" type="file" accept="image/*" multiple></div>
      </div>
      <div class="field"><label><?= tt('wk.note') ?></label><textarea data-mt name="body" required placeholder="<?= tt('wk.note_ph') ?>"></textarea></div>
      <button class="btn" type="submit"><?= tt('wk.post') ?></button>
    </form>
  <?php endif; ?>
  <?php if (!$notes): ?><div class="empty"><p><?= tt('wk.empty') ?></p></div><?php endif; ?>
  <?php foreach ($notes as $n): ?>
    <div class="note">
      <span class="when"><?= e(fmt_date($n['note_date'])) ?> · <?= e($n['iso_week']) ?> · <?= e($n['full_name']) ?></span>
      <?php if ($staff): ?><span class="vis-tag <?= e($n['visibility']) ?>"><?= tt('vis.' . $n['visibility']) ?></span><?php endif; ?>
      <p style="margin:6px 0 0;"><?= mt_html($n['body']) ?></p>
      <?php if (!empty($photos[(int)$n['id']])): ?><div class="photos">
        <?php foreach ($photos[(int)$n['id']] as $ph): ?><a href="<?= url('file', ['id' => $ph['id']]) ?>" target="_blank"><img src="<?= url('file', ['id' => $ph['id']]) ?>" alt=""></a><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php /* ============================================== COMMENTS ========= */ elseif ($tab === 'comments'):
  $msgs = all("SELECT c.*, cu.full_name, cu.role FROM sp_comments c JOIN customer_users cu ON cu.id = c.author_id
               WHERE c.subproject_id = ? AND {$vis} ORDER BY c.id ASC", [$id]); ?>
<div class="card">
  <h2><?= tt('cm.title') ?></h2>
  <p class="hint"><?= tt($staff ? 'cm.lede_staff' : 'cm.lede_customer') ?></p>
  <div class="chat" style="margin:16px 0;">
    <?php if (!$msgs): ?><div class="empty"><p><?= tt('cm.empty') ?></p></div><?php endif; ?>
    <?php foreach (thread_tree($msgs) as $m): $mine = (int)$m['author_id'] === (int)$u['id']; $gone = !empty($m['deleted_at']); ?>
      <div class="msg <?= $mine ? 'me' : '' ?> <?= $m['visibility'] === 'internal' ? 'internal' : '' ?><?= $gone ? ' msg--gone' : '' ?> depth-<?= (int)$m['depth'] ?>" id="c<?= (int)$m['id'] ?>">
        <div class="who"><?= avatar_html((int)($m['user_id'] ?? $m['author_id'] ?? 0), (string)$m['full_name'], 22, 'ava--inline') ?> <b><?= e($m['full_name']) ?></b> · <?= e($m['role'] === 'customer' ? tt('cm.customer') : 'Golden A/S') ?> · <?= e(fmt_datetime($m['created_at'])) ?>
          <?php if ($staff): ?><span class="vis-tag <?= e($m['visibility']) ?>"><?= tt('vis.' . $m['visibility']) ?></span><?php endif; ?>
          <?= !empty($m['parent_id']) ? ' · <a class="hint" href="#c' . (int)$m['parent_id'] . '">↩ ' . tt('cm.in_reply') . '</a>' : '' ?></div>
        <?= $gone ? '<span class="hint">' . tt('cm.deleted') . '</span>' : mt_html($m['body']) ?>
        <?php if (!$gone): ?><?= thread_tools((int)$m['id'], (string)$m['full_name'], $mine, true, 'cm_delete', ['subproject_id' => $id, 'tab' => 'comments']) ?><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= $backTo ?>" data-lock data-busy="<?= tt('qa.posting') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="cm_post"><input type="hidden" name="subproject_id" value="<?= $id ?>"><input type="hidden" name="parent_id" value="" id="parent_id">
    <div class="reply-to" id="reply-to" hidden><?= tt('cm.replying') ?> <b></b> <button type="button" class="linkbtn js-reply-cancel"><?= tt('btn.cancel') ?></button></div>
    <div class="field"><textarea data-mt name="body" required maxlength="4000" placeholder="<?= tt('cm.write') ?>"></textarea></div>
    <div class="actions">
      <?php if ($staff): ?>
        <select name="visibility" style="max-width:260px;"><option value="external"><?= tt('cm.reply_customer') ?></option><option value="internal"><?= tt('cm.internal_note') ?></option></select>
      <?php else: ?><input type="hidden" name="visibility" value="external"><?php endif; ?>
      <button class="btn" type="submit"><?= tt('qa.post') ?></button>
    </div>
  </form>
</div>

<?php /* =========================================== ENGINEERING ========= */ elseif ($tab === 'engineering'): include __DIR__ . '/_engineering_tab.php'; ?>
<?php /* ================================================= FILES ========= */ elseif ($tab === 'files'):
  $files = all("SELECT f.*, cu.full_name FROM sp_files f LEFT JOIN customer_users cu ON cu.id = f.uploaded_by
                WHERE f.subproject_id = ? AND {$vis} ORDER BY f.kind, f.id DESC", [$id]); ?>
<div class="card">
  <h2><?= tt('tab.files') ?></h2>
  <?php if ($staff): ?>
    <form method="post" action="<?= $backTo ?>" enctype="multipart/form-data" data-lock style="margin-bottom:18px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="file_upload"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="grid grid-3">
        <div class="field"><label><?= tt('fl.kind') ?></label><select name="kind"><?php foreach (fk_choices() as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select><?php if (is_cp_admin()): ?><p class="hint" style="margin:4px 0 0;"><a href="<?= url('admin_file_kinds') ?>"><?= tt('fkc.manage') ?> ›</a></p><?php endif; ?></div>
        <div class="field"><label><?= tt('vis.label') ?></label><select name="visibility"><option value="external"><?= tt('vis.external') ?></option><option value="internal"><?= tt('vis.internal') ?></option></select></div>
        <div class="field"><label><?= tt('dr.file') ?></label><input name="files[]" type="file" multiple required></div>
      </div>
      <div class="field"><label><?= tt('fl.title') ?></label><input name="title" maxlength="200"></div>
      <button class="btn" type="submit"><?= tt('fl.upload') ?></button>
    </form>
  <?php endif; ?>
  <?php if (!$files): ?><div class="empty"><p><?= tt('fl.empty') ?></p></div><?php else: ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('fl.kind') ?></th><th><?= tt('fl.title') ?></th><th><?= tt('th.size') ?></th><th><?= tt('th.by') ?></th><th><?= tt('th.date') ?></th><?= $staff ? '<th>' . tt('vis.label') . '</th>' : '' ?></tr></thead>
    <tbody><?php foreach ($files as $f): ?>
      <tr><td><?= e(fk_label($f['kind'])) ?></td>
        <td><a href="<?= url('file', ['id' => $f['id']]) ?>"><?= e($f['title'] ?: $f['original_name']) ?></a><?= $f['title'] ? '<br><span class="hint">' . e($f['original_name']) . '</span>' : '' ?></td>
        <td class="num"><?= number_format($f['size_bytes'] / 1024, 0) ?> KB</td><td><?= e($f['full_name'] ?? '—') ?></td>
        <td class="date"><?= e(fmt_datetime($f['created_at'])) ?></td>
        <?= $staff ? '<td><span class="vis-tag ' . e($f['visibility']) . '">' . tt('vis.' . $f['visibility']) . '</span></td>' : '' ?></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
</div>

<?php /* ======================================= FIELD SERVICE REPORTS === */ elseif ($tab === 'service'): require __DIR__ . '/_fsr_list.php'; ?>
<div class="card">
  <div class="page-head" style="margin-bottom:10px;">
    <div><h2 style="margin:0;"><?= tt('fsr.plural') ?></h2><p class="hint"><?= tt('fsr.tab_lede') ?></p></div>
    <?php if ($staff): ?><a class="btn btn--gold" href="<?= url('fsr_edit', ['project' => $pid, 'sub' => $id]) ?>">+ <?= tt('fsr.new') ?></a><?php endif; ?>
  </div>
  <?php fsr_table(fsr_list($pid, $id), false, 'subproject', ['id' => $id, 'tab' => 'service']); ?>
</div>
<?php if (isr_has()): require __DIR__ . '/_isr_list.php'; ?>
<div class="card">
  <div class="page-head" style="margin-bottom:10px;">
    <div><h2 style="margin:0;"><?= tt('isr.plural') ?></h2><p class="hint"><?= tt('isr.tab_lede') ?></p></div>
    <?php if ($staff): ?><a class="btn btn--gold" href="<?= url('isr_edit', ['project' => $pid, 'sub' => $id]) ?>">+ <?= tt('isr.new') ?></a><?php endif; ?>
  </div>
  <?php isr_table(isr_list($pid, $id), false, 'subproject', ['id' => $id, 'tab' => 'service']); ?>
</div>
<?php endif; ?>
<?php /* ============================================== CONTACTS ========= */ elseif ($tab === 'contacts'): ?>
<?php if (function_exists('spc_has') && spc_has()): $spcSel = spc_of($id); $spcAll = ct_for_vessel((int)$sp['vessel_id']); $spcRows = spc_rows($id); ?>
<div class="card" id="spc">
  <div class="card-head"><h2><?= tt('spc.title') ?> <span class="hint">(<?= count($spcSel) ?>)</span></h2><span class="hint"><?= tt('spc.hint') ?></span></div>
  <?php if ($spcRows): $spcEm = array_values(array_filter(array_map(fn($c) => $c['email'], $spcRows))); ?>
  <div class="table-scroll"><table class="cv-table"><thead><tr><th><?= tt('au.name') ?></th><th><?= tt('spc.role') ?></th><th><?= tt('ct.role_on_vessel') ?></th><th><?= tt('co.one') ?></th><th><?= tt('ct.reach') ?></th></tr></thead><tbody>
    <?php foreach ($spcRows as $c): ?><tr><td><a href="<?= url('contact', ['id' => (int)$c['id']]) ?>"><b><?= e($c['full_name']) ?></b></a><?= $c['position'] ? '<br><span class="hint">' . e($c['position']) . '</span>' : '' ?></td><td><?= e($c['sp_role'] ?: '—') ?></td><td><?= e($c['vessel_role'] ?: '—') ?></td><td><?= e($c['company_name'] ?: '—') ?></td><td class="ct-reach"><?= $c['email'] ? '<a href="mailto:' . e($c['email']) . '">' . e($c['email']) . '</a>' : '' ?><?= $c['mobile'] ? '<br>' . e($c['mobile']) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($spcEm && function_exists('em_mailto')): ?><p style="margin:8px 0 0;"><a class="btn btn--ghost btn--sm" href="<?= e(em_mailto($spcEm, ['vessel' => (string)($sp['vessel'] ?? ''), 'customer_id' => (int)($sp['customer_id'] ?? 0), 'project_id' => (int)$sp['project_id']])) ?>">✉ <?= tt('spc.email_all') ?></a></p><?php endif; ?>
  <?php else: ?><p class="hint"><?= tt('spc.none') ?></p><?php endif; ?>
  <details class="pd-add" style="margin-top:10px;"<?= !$spcRows ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm">✎ <?= tt('spc.select') ?></summary>
    <?php if (!$spcAll): ?><p class="hint" style="margin-top:8px;"><?= tt('ct.none_vessel') ?> <a href="<?= url('vessel', ['id' => (int)$sp['vessel_id']]) ?>#contacts"><?= tt('v.one') ?> ›</a></p><?php else: ?>
    <form method="post" action="<?= $backTo ?>" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="spc_save"><input type="hidden" name="subproject_id" value="<?= $id ?>">
      <div class="table-scroll"><table class="cv-table"><thead><tr><th></th><th><?= tt('au.name') ?></th><th><?= tt('ct.role_on_vessel') ?></th><th><?= tt('co.one') ?></th><th><?= tt('spc.role') ?></th></tr></thead><tbody>
      <?php foreach ($spcAll as $c): $on = array_key_exists((int)$c['id'], $spcSel); ?><tr><td><input type="checkbox" name="contact_ids[]" value="<?= (int)$c['id'] ?>"<?= $on ? ' checked' : '' ?>></td><td><b><?= e($c['full_name']) ?></b><?= $c['position'] ? ' <span class="hint">' . e($c['position']) . '</span>' : '' ?></td><td><?= e($c['vessel_role'] ?: '—') ?><?= $c['is_primary'] ? ' ' . pill('gold', t('ct.primary')) : '' ?></td><td><?= e($c['company_name'] ?: '—') ?></td><td><input name="role[<?= (int)$c['id'] ?>]" maxlength="80" value="<?= e($on ? $spcSel[(int)$c['id']] : '') ?>" placeholder="<?= tt('spc.role_ph') ?>"></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
    </form>
    <?php endif; ?>
  </details>
</div>
<?php endif; ?>
<div class="card"><?php $cvVesselId = (int)$sp['vessel_id']; $cvCompact = true; require __DIR__ . '/_contacts_vessel.php'; ?></div>
<?php /* =============================================== OPEN ISSUES ===== */ elseif ($tab === 'issues'): require __DIR__ . '/_issues.php'; ?>

<?php /* ================================================= WORKS ========= */ elseif ($tab === 'works'): require __DIR__ . '/_works_tab.php'; ?>
<?php /* ======================================== BUDGET & ACTUAL ======== */ elseif ($tab === 'budget'): require __DIR__ . '/_budget_tab.php'; ?>
<?php /* =============================================== REPORTS ========= */ elseif ($tab === 'reports'): ?>
<div class="card">
  <h2><?= tt('tab.reports') ?></h2>
  <p><?= tt('rp.lede') ?></p>
  <div class="actions">
    <a class="btn btn--gold" href="<?= url('report_sp', ['id' => $id]) ?>" target="_blank"><?= tt('rp.pdf') ?></a>
    <a class="btn" href="<?= url('report_sp', ['id' => $id, 'format' => 'docx']) ?>"><?= tt('rp.word') ?></a>
    <a class="btn btn--ghost" href="<?= url('weekly', ['project' => $pid]) ?>"><?= tt('wk.report') ?></a>
    <a class="btn btn--ghost" href="<?= url('monthly', ['project' => $pid]) ?>"><?= tt('mo.title') ?></a>
  </div>
  <?php if (ar_has() && $staff) { $D0 = rp_sp_data($id); echo ar_issued_html($D0['docNo'], $pid, ['kind' => 'monthly', 'id' => $id], url('subproject', ['id' => $id, 'tab' => 'reports'])); } ?>
  <p class="hint" style="margin-top:12px;"><?= tt('rp.pdf_hint') ?></p>
</div>
<?php endif; ?>

<script nonce="<?= csp_nonce() ?>">(function(){var ed=document.getElementById('stage-editor');if(!ed)return;ed.addEventListener('change',function(e){if(!e.target.classList.contains('js-na'))return;var inp=e.target.closest('.field').querySelector('.js-pct');inp.disabled=e.target.checked;if(e.target.checked)inp.value=0;});})();</script>
<?php layout_foot(); ?>

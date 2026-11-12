<?php
/** System Budgets — dashboard and list (GAS-CP-R97-001). ?p=system_budgets[&status=open|draft|working|finished|converted|cancelled|all][&q=][&owner=] */
require __DIR__ . '/_layout.php';
$u = sb_require();
$f = ['status' => (string)($_GET['status'] ?? 'open'), 'q' => trim((string)($_GET['q'] ?? '')), 'owner' => (int)($_GET['owner'] ?? 0), 'priority' => (string)($_GET['priority'] ?? '')]; $grp = ($_GET['group'] ?? 'vessel') === 'none' ? 'none' : 'vessel'; $prOn = function_exists('sb_prio') && sb_prio();
$rows = sb_list($f); $D = sb_dash(); $staff = staff_users(); $tpls = function_exists('sb_templates') ? sb_templates() : [];
if (function_exists('sb_housekeeping') && function_exists('cache_get') && cache_get('sb_hk') === null) { sb_housekeeping(); cache_set('sb_hk', 1, 86400); }   // R97-005: daily clean-up of abandoned reviews and uploads
layout_head(t('sb.title'), 'system_budgets');
?>
<div class="page-head">
  <div><h1>🧮 <?= tt('sb.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sb.lede') ?></p></div>
  <?php if (sb_can_reopen() && function_exists('sb_audit_xlsx')): ?><div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('system_budget_audit') ?>" title="<?= tt('sb.aud_hint') ?>">🛡 <?= tt('sb.aud_btn') ?></a></div><?php endif; ?>
</div>

<div class="kpi kpi--4">
  <a class="stat gold" href="<?= url('system_budgets', ['status' => 'open']) ?>"><b><?= (int)$D['open'] ?></b><?= tt('sb.k_open') ?><span class="s"><?= e(sb_money($D['open_sale'])) ?></span></a>
  <a class="stat green" href="<?= url('system_budgets', ['status' => 'finished']) ?>"><b><?= (int)$D['finished'] ?></b><?= tt('sb.k_finished') ?><span class="s"><?= e(sb_money($D['finished_sale'])) ?></span></a>
  <a class="stat<?= $D['late'] || !empty($D['urgent']) ? ' red' : '' ?>" href="<?= url('system_budgets', ['status' => 'open'] + ($prOn && !empty($D['urgent']) ? ['priority' => 'urgent'] : [])) ?>"><b><?= (int)$D['late'] ?></b><?= tt('sb.k_late') ?><?php if ($prOn): ?><span class="s"><?= e(t('sb.k_prio', ['u' => (int)$D['urgent'], 'h' => (int)$D['high']])) ?></span><?php endif; ?></a>
  <a class="stat" href="<?= url('system_budgets', ['status' => 'converted']) ?>"><b><?= (int)$D['converted'] ?></b><?= tt('sb.k_converted') ?><span class="s"><?= $D['avg_days'] !== null ? e(t('sb.k_avg', ['n' => number_format($D['avg_days'], 1, ',', '')])) : '' ?></span></a>
</div>

<div class="grid grid-2" style="align-items:start;">
  <div class="card">
    <h2>＋ <?= tt('sb.new') ?></h2>
    <form method="post" action="<?= url('system_budgets') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_create">
      <div class="field"><label><?= tt('sb.f_title') ?> *</label><input name="title" maxlength="200" required placeholder="<?= tt('sb.title_ph') ?>"></div>
      <?php if ($prOn): ?><div class="field"><label><?= tt('sb.priority') ?></label><select name="priority" data-nosearch><?php foreach (SB_PRIORITIES as $pk): ?><option value="<?= $pk ?>"<?= $pk === 'normal' ? ' selected' : '' ?>><?= tt('sb.pr_' . $pk) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <?php if ($tpls): ?><div class="field"><label><?= tt('sb.start_from') ?></label><select name="from" data-nosearch><option value="0"><?= tt('sb.start_blank') ?></option><?php foreach ($tpls as $tp): ?><option value="<?= (int)$tp['id'] ?>">★ <?= e($tp['title'] . ' · ' . (int)$tp['n_lab'] . ' L / ' . (int)$tp['n_parts'] . ' P') ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sb.create') ?></button><span class="hint"><?= tt('sb.start_hint') ?></span></div>
    </form>
  </div>
  <div class="card">
    <h2>📥 <?= tt('sb.imp_title') ?></h2>
    <form method="post" action="<?= url('system_budgets') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_import_upload">
      <div class="field"><label><?= tt('sb.imp_file') ?> *</label><input type="file" name="file" accept=".xlsx" required><p class="hint" style="margin:4px 0 0;"><?= tt('sb.imp_hint') ?></p></div>
      <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('sb.imp_review') ?></button></div>
    </form>
  </div>
</div>

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="system_budgets">
  <div class="field"><label><?= tt('sb.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="SB-2026-0001 · GLD-BUD-…"></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>><?= tt('sb.f_open') ?></option><?php foreach (SB_STATUSES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= tt('sb.st_' . $s) ?></option><?php endforeach; ?><option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>><?= tt('sb.f_all') ?></option></select></div>
  <?php if ($prOn): ?><div class="field"><label><?= tt('sb.priority') ?></label><select name="priority" data-nosearch><option value=""><?= tt('sb.f_all') ?></option><?php foreach (SB_PRIORITIES as $pk): ?><option value="<?= $pk ?>"<?= $f['priority'] === $pk ? ' selected' : '' ?>><?= tt('sb.pr_' . $pk) ?></option><?php endforeach; ?></select></div><?php else: ?><div></div><?php endif; ?>
  <div class="field"><label><?= tt('sb.owner') ?></label><select name="owner"><option value="0">—</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $f['owner'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('sb.group_by') ?></label><select name="group" data-nosearch><option value="vessel"<?= $grp === 'vessel' ? ' selected' : '' ?>><?= tt('sb.group_vessel') ?></option><option value="none"<?= $grp === 'none' ? ' selected' : '' ?>><?= tt('sb.group_none') ?></option></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('sb.filter') ?></button></div>
</form>

<div class="card">
  <div class="card-head"><h2><?= tt('sb.list') ?> <span class="count"><?= count($rows) ?></span></h2><?php if ($prOn): ?><span class="hint"><?= tt('sb.pr_order') ?></span><?php endif; ?></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('sb.none') ?></p><?php else: ?>
  <?php $groups = $grp === 'vessel' ? sb_group_by_vessel($rows) : [[0, '', $rows, 0, 0]]; $ncol = $prOn ? 12 : 11; ?>
  <div class="table-scroll"><table class="tbl"<?= $grp === 'vessel' ? ' data-nosort' : '' ?>><thead><tr><th><?= tt('sb.no') ?></th><th><?= tt('sb.f_title') ?></th><th><?= tt('sb.project') ?></th><th><?= tt('sb.owner') ?></th><?php if ($prOn): ?><th><?= tt('sb.priority') ?></th><?php endif; ?><th><?= tt('th.status') ?></th><th style="min-width:110px;"><?= tt('sb.progress') ?></th><th class="num"><?= tt('sb.hours') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th class="date"><?= tt('sb.target') ?></th><th class="date"><?= tt('sb.updated') ?></th></tr></thead><tbody>
  <?php foreach ($groups as [$gvid, $gname, $grows, $gh, $gs]): if ($grp === 'vessel'): ?><tr class="grp-row"><td colspan="<?= $ncol - 5 ?>">⚓ <?= $gvid ? '<a href="' . url('vessel', ['id' => $gvid]) . '"><b>' . e($gname) . '</b></a>' : '<b>' . tt('sb.group_no_vessel') . '</b>' ?> <span class="count"><?= count($grows) ?></span></td><td class="num"><b><?= e(sb_h($gh)) ?></b></td><td></td><td class="num"><b><?= e(sb_money($gs)) ?></b></td><td></td><td></td></tr><?php endif; ?>
  <?php foreach ($grows as $r): $late = $r['target_date'] && $r['target_date'] < date('Y-m-d') && in_array($r['status'], ['draft', 'working'], true); $pg = sb_progress($r); ?><tr>
    <td class="mono"><a href="<?= url('system_budget', ['id' => (int)$r['id']]) ?>"><b><?= e(sb_label($r)) ?></b></a><?= $r['source_ref'] ? '<br><span class="hint">' . e($r['source_ref']) . '</span>' : '' ?></td>
    <td><?= e($r['title']) ?><?= (int)$r['n_com'] ? ' <span class="hint">💬 ' . (int)$r['n_com'] . '</span>' : '' ?></td>
    <td><?= $r['project_id'] ? e(trim((string)$r['project_code'] . ' ' . (string)$r['sub_code'])) . '<br><span class="hint">' . e((string)($r['sub_name'] ?: $r['project_name'])) . '</span>' : '<span class="hint">—</span>' ?></td>
    <td class="hint"><?= e((string)$r['owner_name']) ?></td><?php if ($prOn): ?><td data-sort="<?= array_search($r['priority'], SB_PRIORITIES, true) ?>"><?= in_array($r['status'], ['draft', 'working', 'finished'], true) ? sb_prio_pill((string)$r['priority']) : '<span class="hint">—</span>' ?></td><?php endif; ?><td><?= sb_pill($r['status']) ?></td>
    <td><?= function_exists('bg_bar') ? bg_bar($pg, $pg >= 100 ? 'green' : 'navy') : $pg . ' %' ?><span class="hint"><?= (int)$r['n_lab'] ?> L · <?= (int)$r['n_parts'] ?> P</span></td>
    <td class="num"><?= e(sb_h((float)$r['tot_hours'])) ?></td><td class="num"><?= e(sb_money((float)$r['tot_cost'], $r['currency'])) ?></td><td class="num"><b><?= e(sb_money((float)$r['tot_sale'], $r['currency'])) ?></b></td>
    <td class="date<?= $late ? ' bad' : '' ?>"><?= $r['target_date'] ? e(fmt_date($r['target_date'])) : '—' ?></td><td class="date hint"><?= e(fmt_date(substr((string)$r['updated_at'], 0, 10))) ?></td>
  </tr><?php endforeach; ?>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if ($tpls): ?><div class="card" id="templates">
  <div class="card-head"><h2>★ <?= tt('sb.tpl_many') ?> <span class="count"><?= count($tpls) ?></span></h2><span class="hint"><?= tt('sb.tpl_hint') ?></span></div>
  <div class="table-scroll"><table class="tbl" style="max-width:1000px;"><thead><tr><th><?= tt('sb.no') ?></th><th><?= tt('sb.f_title') ?></th><th class="num"><?= tt('sb.labour') ?></th><th class="num"><?= tt('sb.parts') ?></th><th class="num"><?= tt('sb.hours') ?></th><th class="num"><?= tt('sb.sale') ?></th></tr></thead><tbody>
  <?php foreach ($tpls as $tp): ?><tr><td class="mono"><a href="<?= url('system_budget', ['id' => (int)$tp['id']]) ?>"><b><?= e($tp['sb_no']) ?></b></a></td><td><?= e($tp['title']) ?></td><td class="num"><?= (int)$tp['n_lab'] ?></td><td class="num"><?= (int)$tp['n_parts'] ?></td><td class="num"><?= e(sb_h((float)$tp['tot_hours'])) ?></td><td class="num"><?= e(sb_money((float)$tp['tot_sale'], $tp['currency'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div><?php endif; ?>
<?php layout_foot(); ?>

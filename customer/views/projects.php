<?php
/**
 * Projects — tree view.                                     GAS-CP-R3-001
 * Main projects (code before "Y.") expand to their sub-projects, each with
 * its 7-stage process line. Staff see every customer; customers their own.
 */
require __DIR__ . '/_layout.php';
$u = require_login();

$q     = trim((string)($_GET['q'] ?? ''));
$staff = is_staff();

/* status filter: Active + On hold by default; an explicit submit (f=1)
   with nothing ticked means "all" */
$allSt = pm_statuses();
$st = array_values(array_intersect((array)($_GET['st'] ?? []), $allSt));
if (!$st && empty($_GET['f'])) $st = array_values(array_intersect($allSt, ['active', 'quoted', 'onhold']));
$fCustomer = $staff ? (int)($_GET['customer'] ?? 0) : 0;
$fVessel   = (int)($_GET['vessel'] ?? 0);
$fPm       = trim((string)($_GET['pm'] ?? ''));
$openId    = (int)($_GET['open'] ?? 0);
$fBranch   = $staff && br_has() && br_multi() ? (int)($_GET['branch'] ?? 0) : 0;
$per       = per_page();
$pageNo    = max(1, (int)($_GET['page'] ?? 1));

$res   = project_tree_filtered(['status' => $st, 'customer' => $fCustomer, 'vessel' => $fVessel, 'pm' => $fPm, 'branch' => $fBranch, 'q' => $q], $per, ($pageNo - 1) * $per);
$tree  = $res['rows']; $total = $res['total'];
$tkOpen = []; if (function_exists('tk_has') && tk_has() && $tree) { [$tkScope, $tkArgs] = tk_scope(); foreach (all("SELECT t.project_id, COUNT(*) n FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id WHERE {$tkScope} AND t.project_id IS NOT NULL AND t.status IN ('open','in_progress','waiting_customer') GROUP BY t.project_id", $tkArgs) as $r) $tkOpen[(int)$r['project_id']] = (int)$r['n']; }   // R30-003: open tickets per project
$keep  = array_filter(['q' => $q, 'st' => $st, 'customer' => $fCustomer, 'vessel' => $fVessel, 'pm' => $fPm, 'branch' => $fBranch, 'per' => $per, 'f' => 1]);

/* option lists, scoped like the data */
[$scope, $sargs] = scope_sql('p');
$customers = $staff ? all('SELECT id, name FROM customers WHERE is_active = 1 ORDER BY name') : [];
$vesselOpts = all("SELECT DISTINCT v.id, v.name FROM vessels v JOIN projects p ON p.vessel_id = v.id WHERE {$scope} ORDER BY v.name", $sargs);
$pmOpts     = all("SELECT DISTINCT p.project_manager AS pm FROM projects p WHERE {$scope} AND p.project_manager IS NOT NULL AND p.project_manager <> '' ORDER BY 1", $sargs);

$statusPill = function (string $s): string {
    return pill(pm_status_tone($s), t('pr.' . $s));
};
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';

$nSub = $res['subs_total'];

$engAl = $staff && function_exists('eng_alerts') && eng_has() ? eng_alerts() : [];   // R47-001
layout_head(t('pr.title'), 'projects');
?>
<div class="page-head">
  <div>
    <h1><?= tt('pr.title') ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(t('pr.tree_lede', ['n' => $total, 'm' => $nSub])) ?></p>
  </div>
  <div class="btn-row">
    <?php if (is_cp_admin()): ?><a class="btn btn--ghost" href="<?= url('projects_export', $keep) ?>">⤓ <?= tt('ct.export') ?></a><?php endif; ?>
    <?php if ($staff): ?>
      <a class="btn btn--ghost" href="<?= url('import') ?>"><?= tt('nav.import') ?></a>
      <a class="btn btn--gold" href="<?= url('project_new') ?>"><?= tt('pr.new') ?></a>
    <?php endif; ?>
  </div>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr auto <?= $staff ? '1fr ' : '' ?>1fr 1fr <?= $fBranch || ($staff && br_has() && br_multi()) ? 'auto ' : '' ?>auto auto;">
    <input type="hidden" name="p" value="projects"><input type="hidden" name="f" value="1">
    <div class="field">
      <label for="q"><?= tt('pr.search') ?></label>
      <input id="q" name="q" type="text" value="<?= e($q) ?>" placeholder="<?= tt('pr.search_ph') ?>">
    </div>
    <div class="field">
      <label><?= tt('th.status') ?></label>
      <div class="chips">
        <?php foreach ($allSt as $k): ?>
          <label><input type="checkbox" name="st[]" value="<?= $k ?>"<?= in_array($k, $st, true) ? ' checked' : '' ?>><span><?= e(t('pr.' . $k)) ?></span></label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php if ($staff): ?>
    <div class="field">
      <label for="customer"><?= tt('rp.customer') ?></label>
      <select id="customer" name="customer"><option value=""><?= tt('flt.any') ?></option>
        <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"<?= $sel($c['id'], $fCustomer) ?>><?= e($c['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="vessel"><?= tt('th.vessel') ?></label>
      <select id="vessel" name="vessel"><option value=""><?= tt('flt.any') ?></option>
        <?php foreach ($vesselOpts as $v): ?><option value="<?= $v['id'] ?>"<?= $sel($v['id'], $fVessel) ?>><?= e($v['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label for="pm"><?= tt('pr.pm') ?></label>
      <select id="pm" name="pm"><option value=""><?= tt('flt.any') ?></option>
        <?php foreach ($pmOpts as $o): ?><option value="<?= e($o['pm']) ?>"<?= $sel($o['pm'], $fPm) ?>><?= e($o['pm']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($staff && br_has() && br_multi()): ?>
    <div class="field">
      <label for="branch"><?= tt('br.one') ?></label>
      <select id="branch" name="branch"><option value=""><?= tt('flt.any') ?></option><?= br_options($fBranch ?: null) ?></select>
    </div>
    <?php endif; ?>
    <div class="field">
      <label for="per"><?= tt('pg.per') ?></label>
      <select id="per" name="per"><?php foreach ([25, 50, 100] as $n): ?><option value="<?= $n ?>"<?= $sel($n, $per) ?>><?= $n ?></option><?php endforeach; ?></select>
    </div>
    <div class="fltbar-actions">
      <button class="btn" type="submit"><?= tt('btn.apply') ?></button>
      <a class="btn btn--ghost" href="<?= url('projects', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a>
    </div>
  </form>
</div>

<?php if (!$tree): ?>
  <div class="card"><div class="empty"><p><?= $total === 0 && ($q !== '' || $fCustomer || $fVessel || $fPm !== '' || $st !== array_values(array_intersect($allSt, ['active', 'quoted', 'onhold']))) ? tt('flt.none') : tt('pr.empty') ?></p></div></div>
<?php else: ?>
<?= process_legend() ?>
<div class="ptree">
  <?php foreach ($tree as $i => $p): $pinv = project_inv_summary($p['subs']); $pof = of_has() ? of_project_summary((int)$p['id']) : null; $ofBySub = of_has() ? of_by_sub((int)$p['id']) : []; ?>
    <details id="pr<?= (int)$p['id'] ?>" <?= ($openId ? $openId === (int)$p['id'] : ($i === 0 || $q !== '')) ? 'open' : '' ?>>
      <summary>
        <span class="tw">▶</span>
        <span>
          <span class="pcode"><?= e($p['project_code'] ?: ($p['erp_code'] ?: '—')) ?></span><?php if ($staff && br_has() && br_multi()): ?> <?= br_pill(br_of((int)$p['id'])) ?><?php endif; ?>
          <span class="pname"><?= e($p['name']) ?></span>
        </span>
        <span class="pmeta">
          <?php if ($p['vessel']): ?><?= e($p['vessel']) ?><?php elseif ($staff): $vg = pr_vessel_guess($p); ?><span class="bg-neg" title="<?= tt('pr.novessel_hint') ?>">⚓ <?= tt('pr.novessel') ?></span>
            <?php if ($vg): ?> <form method="post" action="<?= url('projects') ?>" class="inline" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="project_vessel"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="vessel_id" value="<?= (int)$vg['id'] ?>"><button class="linkbtn" type="submit" style="font:inherit;color:var(--gold-text);"><?= e(t('pr.link_vessel', ['v' => $vg['name']])) ?></button></form><?php else: ?> <a href="<?= url('project_edit', ['id' => $p['id']]) ?>" style="font-size:12px;"><?= tt('pr.set_vessel') ?></a><?php endif; ?>
          <?php else: ?><?= tt('pr.novessel') ?><?php endif; ?>
          <?php if (is_staff()): ?> · <?= e($p['customer_name'] ?? '') ?><?php endif; ?>
          <?php if ($p['project_manager']): ?><br>PM: <?= e($p['project_manager']) ?><?php endif; ?>
        </span>
        <span class="psum">
          <?php if ($p['subs']): ?><span><?= process_line(project_stage_avg($p['subs']), true) ?></span>
          <?php if (is_staff() && function_exists('fs_pills') && fin_visible() && ($fsp = fs_pills((int)$p['id']))): ?><span class="pfs"><?= $fsp ?></span><?php endif; ?>
          <?php if (inv_has()): ?><span class="pinv" title="<?= tt('inv.title') ?>"><b class="f"><?= $pinv['full'] ?></b> <?= tt('inv.short_full') ?> · <b class="p"><?= $pinv['partial'] ?></b> <?= tt('inv.short_partial0') ?> · <b class="n"><?= $pinv['none'] ?></b> <?= tt('inv.short_none') ?></span><?php endif; ?>
          <?php if ($pof && $pof['n'] && of_visible()): ?><span class="pinv pof" title="<?= tt('of.title') ?>"><?= tt('of.title') ?>: <b class="p"><?= $pof['sent'] ?></b> <?= tt('of.st_sent') ?> <?= $pof['sent'] ? '(' . e(of_money($pof['sent_amount'])) . ($pof['oldest_sent'] > 14 ? ' · ' . $pof['oldest_sent'] . ' ' . tt('tk.d') : '') . ')' : '' ?> · <b class="f"><?= $pof['approved'] ?></b> <?= tt('of.st_approved') ?> <?= $pof['approved'] ? '(' . e(of_money($pof['approved_amount'])) . ')' : '' ?></span><?php endif; ?>
          <?php endif; ?>
        </span>
        <span title="<?= !empty($p['activated_at']) && $p['status'] === 'active' ? e(t('pr.active_since', ['d' => fmt_date($p['activated_at'])])) : ($p['status'] === 'quoted' ? tt('pr.quoted_hint') : '') ?>"><?= $statusPill($p['status']) ?></span>
        <span class="pmeta"><?= e(t('pr.nsubs', ['n' => count($p['subs'])])) ?><?php if (!empty($tkOpen[(int)$p['id']])): ?> · <a class="pill pill--gold" href="<?= url('tickets', ['view' => 'open', 'project' => $p['id'], 'status' => 'open_all']) ?>" title="<?= tt('tk.open_in_project') ?>">🎫 <?= (int)$tkOpen[(int)$p['id']] ?></a><?php endif; ?></span>
      </summary>
      <div class="subs">
        <?php if ($pof && $pof['n'] && of_visible()): ?><div class="ot-tree"><?php $otRows = of_list(['project' => (int)$p['id']]); $otShowSub = true; $otNewUrl = url('offer_edit', ['project' => $p['id']]); require __DIR__ . '/_offers_table.php'; ?></div><?php elseif (of_has() && is_staff() && of_visible()): ?><p class="hint ot-none"><?= tt('of.none_here') ?> <a href="<?= url('offer_edit', ['project' => $p['id']]) ?>">+ <?= tt('of.new') ?></a></p><?php endif; ?>
        <?php if (function_exists('td_card_html') && ($tdc = td_card_html(['project' => (int)$p['id']], url('task_edit', ['project' => $p['id']]), true, 6)) !== ''): ?><div class="ot-tree td-tree"><?= $tdc ?></div><?php endif; ?>
        <?php if (function_exists('tk_card_html') && ($tkc = tk_card_html((int)$p['id'], url('ticket_edit', ['project' => $p['id']]), 6)) !== ''): ?><div class="ot-tree tk-tree"><?= $tkc ?></div><?php endif; ?>
        <?php if (!$p['subs']): ?>
          <p class="hint" style="padding:8px 0;"><?= tt('pr.nosubs') ?>
            <?php if (is_staff()): ?> <a href="<?= url('subproject_new', ['project' => $p['id']]) ?>"><?= tt('pr.addsub') ?></a>
            · <a href="<?= url('project_edit', ['id' => $p['id']]) ?>"><?= tt('pr.edit') ?></a><?php endif; ?>
          </p>
        <?php else: ?>
          <?php foreach ($p['subs'] as $s): ?>
            <a class="sub" href="<?= url('subproject', ['id' => $s['id']]) ?>">
              <span class="scode"><?= e($s['code'] ?: '—') ?></span>
              <span><span class="sname"><?= e($s['name']) ?><?php if ($staff && isset($engAl[(int)$s['id']])): ?> <?= eng_state_pill($engAl[(int)$s['id']]) ?><?php endif; ?></span>
                    <span class="ssys"><?= e($s['system_name'] ?: '') ?></span></span>
              <span><?= process_line($staff && function_exists('pd_sub_fed') ? $s + ['_fed' => pd_sub_fed((int)$s['id'])] : $s, true) ?></span>
              <span><?= overall_pill($s) ?></span>
              <span><?= inv_has() ? inv_pill($s, true) : '' ?><?= !empty($ofBySub[(int)$s['id']]) && of_visible() ? ' ' . of_sub_pills($ofBySub[(int)$s['id']]) : '' ?><?= function_exists('bg_pill') ? ' ' . bg_pill($s) : '' ?></span>
            </a>
          <?php endforeach; ?>
          <?php if (is_staff()): ?>
            <p style="margin:10px 0 0;"><a class="btn btn--ghost btn--sm" href="<?= url('project_edit', ['id' => $p['id']]) ?>">✎ <?= tt('pr.edit') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('subproject_new', ['project' => $p['id']]) ?>">+ <?= tt('pr.addsub') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('drawings', ['project' => $p['id']]) ?>"><?= tt('dr.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('overlay', ['project' => $p['id']]) ?>"><?= tt('ga.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('weekly', ['project' => $p['id']]) ?>"><?= tt('wk.title') ?></a><?php if (function_exists('bg_can') && bg_can()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('cost_control', ['project' => $p['id']]) ?>">€ <?= tt('bg.board_title') ?></a><?php endif; ?><?php if (function_exists('rk_has') && rk_has() && $staff): ?> <a class="btn btn--ghost btn--sm" href="<?= url('risks', ['project' => $p['id']]) ?>">⚠ <?= tt('rk.title') ?></a><?php endif; ?><?php if (function_exists('cf_can') && cf_can()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('cashflow', ['project' => $p['id']]) ?>">💰 <?= tt('cf.title') ?></a><?php endif; ?>
               <?php if (of_visible()): ?><a class="btn btn--ghost btn--sm" href="<?= url('offers', ['project' => $p['id']]) ?>"><?= tt('of.title') ?></a><?php endif; ?>
               <a class="btn btn--ghost btn--sm" href="<?= url('tickets', ['view' => 'open', 'project' => $p['id'], 'status' => 'open_all']) ?>"><?= tt('nav.tickets') ?></a>
               <?php if (function_exists('wf_has') && wf_has()): ?><a class="btn btn--ghost btn--sm" href="<?= url('wf', ['project' => $p['id']]) ?>">🗓 <?= tt('wf.title') ?></a><?php endif; ?>
               <a class="btn btn--ghost btn--sm" href="<?= url('monthly', ['project' => $p['id']]) ?>"><?= tt('mo.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('jo_list', ['project' => $p['id']]) ?>"><?= tt('jo.plural') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('fsr_list', ['project' => $p['id']]) ?>"><?= tt('fsr.plural') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('isr_list', ['project' => $p['id']]) ?>"><?= tt('isr.plural') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('timesheets', ['view' => 'all', 'project' => $p['id']]) ?>"><?= tt('nav.timesheets') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('manhours', ['project' => $p['id']]) ?>"><?= tt('mh.title') ?></a>
               <?php if (is_cp_admin()): $fp = pm_project_footprint((int)$p['id']); $label = $p['project_code'] ?: $p['name']; ?>
               <form method="post" action="<?= url('projects') ?>" style="display:inline;" data-lock
                     data-confirm="<?= e(t('pr.delete_confirm', ['code' => $label] + $fp)) ?>"
                     data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger>
                 <?= csrf_field() ?><input type="hidden" name="action" value="project_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                 <button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">🗑 <?= tt('btn.delete') ?></button>
               </form>
               <?php endif; ?></p>
          <?php else: ?>
            <p style="margin:10px 0 0;">
               <a class="btn btn--ghost btn--sm" href="<?= url('drawings', ['project' => $p['id']]) ?>"><?= tt('dr.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('overlay', ['project' => $p['id']]) ?>"><?= tt('ga.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('weekly', ['project' => $p['id']]) ?>"><?= tt('wk.title') ?></a><?php if (function_exists('bg_can') && bg_can()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('cost_control', ['project' => $p['id']]) ?>">€ <?= tt('bg.board_title') ?></a><?php endif; ?><?php if (function_exists('rk_has') && rk_has() && $staff): ?> <a class="btn btn--ghost btn--sm" href="<?= url('risks', ['project' => $p['id']]) ?>">⚠ <?= tt('rk.title') ?></a><?php endif; ?><?php if (function_exists('cf_can') && cf_can()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('cashflow', ['project' => $p['id']]) ?>">💰 <?= tt('cf.title') ?></a><?php endif; ?>
               <a class="btn btn--ghost btn--sm" href="<?= url('monthly', ['project' => $p['id']]) ?>"><?= tt('mo.title') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('jo_list', ['project' => $p['id']]) ?>"><?= tt('jo.plural') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('fsr_list', ['project' => $p['id']]) ?>"><?= tt('fsr.plural') ?></a>
               <a class="btn btn--ghost btn--sm" href="<?= url('isr_list', ['project' => $p['id']]) ?>"><?= tt('isr.plural') ?></a></p>
          <?php endif; ?>
        <?php endif; ?>
        <?php if (is_staff() && !empty($p['vessel_id']) && function_exists('ct_has') && ct_has()): ?>
        <details class="cv-details"><summary><?= tt('ct.title') ?> — <?= e($p['vessel']) ?> <?= pill('navy', t('tk.internal')) ?></summary>
          <?php $cvVesselId = (int)$p['vessel_id']; $cvCompact = true; require __DIR__ . '/_contacts_vessel.php'; ?>
        </details>
        <?php endif; ?>
      </div>
    </details>
  <?php endforeach; ?>
</div>
<?= pager_html('projects', $pageNo, $total, $per, $keep) ?>
<?php endif; ?>
<?php layout_foot(); ?>

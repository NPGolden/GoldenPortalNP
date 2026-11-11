<?php
/** System Budgets — dashboard and list (GAS-CP-R97-001). ?p=system_budgets[&status=open|draft|working|finished|converted|cancelled|all][&q=][&owner=] */
require __DIR__ . '/_layout.php';
$u = sb_require();
$f = ['status' => (string)($_GET['status'] ?? 'open'), 'q' => trim((string)($_GET['q'] ?? '')), 'owner' => (int)($_GET['owner'] ?? 0)];
$rows = sb_list($f); $D = sb_dash(); $staff = staff_users();
layout_head(t('sb.title'), 'system_budgets');
?>
<div class="page-head">
  <div><h1>🧮 <?= tt('sb.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('sb.lede') ?></p></div>
</div>

<div class="kpi kpi--4">
  <a class="stat gold" href="<?= url('system_budgets', ['status' => 'open']) ?>"><b><?= (int)$D['open'] ?></b><?= tt('sb.k_open') ?><span class="s"><?= e(sb_money($D['open_sale'])) ?></span></a>
  <a class="stat green" href="<?= url('system_budgets', ['status' => 'finished']) ?>"><b><?= (int)$D['finished'] ?></b><?= tt('sb.k_finished') ?><span class="s"><?= e(sb_money($D['finished_sale'])) ?></span></a>
  <a class="stat<?= $D['late'] ? ' red' : '' ?>" href="<?= url('system_budgets', ['status' => 'open']) ?>"><b><?= (int)$D['late'] ?></b><?= tt('sb.k_late') ?></a>
  <a class="stat" href="<?= url('system_budgets', ['status' => 'converted']) ?>"><b><?= (int)$D['converted'] ?></b><?= tt('sb.k_converted') ?><span class="s"><?= $D['avg_days'] !== null ? e(t('sb.k_avg', ['n' => number_format($D['avg_days'], 1, ',', '')])) : '' ?></span></a>
</div>

<div class="grid grid-2" style="align-items:start;">
  <div class="card">
    <h2>＋ <?= tt('sb.new') ?></h2>
    <form method="post" action="<?= url('system_budgets') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="sb_create">
      <div class="field"><label><?= tt('sb.f_title') ?> *</label><input name="title" maxlength="200" required placeholder="<?= tt('sb.title_ph') ?>"></div>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('sb.create') ?></button></div>
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

<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="system_budgets">
  <div class="field"><label><?= tt('sb.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="SB-2026-0001 · GLD-BUD-…"></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><option value="open"<?= $f['status'] === 'open' ? ' selected' : '' ?>><?= tt('sb.f_open') ?></option><?php foreach (SB_STATUSES as $s): ?><option value="<?= $s ?>"<?= $f['status'] === $s ? ' selected' : '' ?>><?= tt('sb.st_' . $s) ?></option><?php endforeach; ?><option value="all"<?= $f['status'] === 'all' ? ' selected' : '' ?>><?= tt('sb.f_all') ?></option></select></div>
  <div class="field"><label><?= tt('sb.owner') ?></label><select name="owner"><option value="0">—</option><?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"<?= $f['owner'] === (int)$s['id'] ? ' selected' : '' ?>><?= e($s['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('sb.filter') ?></button></div>
</form>

<div class="card">
  <div class="card-head"><h2><?= tt('sb.list') ?> <span class="count"><?= count($rows) ?></span></h2></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('sb.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sb.no') ?></th><th><?= tt('sb.f_title') ?></th><th><?= tt('sb.project') ?></th><th><?= tt('sb.owner') ?></th><th><?= tt('th.status') ?></th><th style="min-width:110px;"><?= tt('sb.progress') ?></th><th class="num"><?= tt('sb.hours') ?></th><th class="num"><?= tt('sb.cost') ?></th><th class="num"><?= tt('sb.sale') ?></th><th class="date"><?= tt('sb.target') ?></th><th class="date"><?= tt('sb.updated') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $late = $r['target_date'] && $r['target_date'] < date('Y-m-d') && in_array($r['status'], ['draft', 'working'], true); $pg = sb_progress($r); ?><tr>
    <td class="mono"><a href="<?= url('system_budget', ['id' => (int)$r['id']]) ?>"><b><?= e(sb_label($r)) ?></b></a><?= $r['source_ref'] ? '<br><span class="hint">' . e($r['source_ref']) . '</span>' : '' ?></td>
    <td><?= e($r['title']) ?><?= (int)$r['n_com'] ? ' <span class="hint">💬 ' . (int)$r['n_com'] . '</span>' : '' ?></td>
    <td><?= $r['project_id'] ? e(trim((string)$r['project_code'] . ' ' . (string)$r['sub_code'])) . '<br><span class="hint">' . e((string)($r['sub_name'] ?: $r['project_name'])) . '</span>' : '<span class="hint">—</span>' ?></td>
    <td class="hint"><?= e((string)$r['owner_name']) ?></td><td><?= sb_pill($r['status']) ?></td>
    <td><?= function_exists('bg_bar') ? bg_bar($pg, $pg >= 100 ? 'green' : 'navy') : $pg . ' %' ?><span class="hint"><?= (int)$r['n_lab'] ?> L · <?= (int)$r['n_parts'] ?> P</span></td>
    <td class="num"><?= e(sb_h((float)$r['tot_hours'])) ?></td><td class="num"><?= e(sb_money((float)$r['tot_cost'], $r['currency'])) ?></td><td class="num"><b><?= e(sb_money((float)$r['tot_sale'], $r['currency'])) ?></b></td>
    <td class="date<?= $late ? ' bad' : '' ?>"><?= $r['target_date'] ? e(fmt_date($r['target_date'])) : '—' ?></td><td class="date hint"><?= e(fmt_date(substr((string)$r['updated_at'], 0, 10))) ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

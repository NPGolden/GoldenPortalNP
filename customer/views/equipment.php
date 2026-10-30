<?php
/** Fleet › Equipment — the register of serial numbers (shore converters and other units).   GAS-CP-R27-001
 *   ?p=equipment[&q=][&maker=][&family=][&vessel=][&status=active|retired|warranty|sold] */
require __DIR__ . '/_layout.php';
$u = require_login(); $staff = is_staff(); $ok = eq_has();
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'maker' => (string)($_GET['maker'] ?? ''), 'family' => (string)($_GET['family'] ?? ''), 'vessel' => (int)($_GET['vessel'] ?? 0), 'status' => (string)($_GET['status'] ?? 'active')];
$rows = $ok ? eq_list($f) : []; $keep = array_filter($f); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$kpi = $ok ? one("SELECT COUNT(*) n, SUM(retired = 0) a, SUM(sold_by_us = 1) s, SUM(warranty_until >= CURDATE()) w FROM equipment e WHERE " . eq_scope_sql('e')[0], eq_scope_sql('e')[1]) : ['n' => 0, 'a' => 0, 's' => 0, 'w' => 0];
[$vs, $va] = $staff ? ['1 = 1', []] : ['v.customer_id = ?', [(int)$u['customer_id']]];
$vesselOpts = all("SELECT DISTINCT v.id, v.name FROM vessels v JOIN equipment e ON e.vessel_id = v.id WHERE {$vs} ORDER BY v.name", $va);
$res = $_SESSION['cp_import_result'] ?? null; if ($res && ($res['kind'] ?? '') === 'equipment') unset($_SESSION['cp_import_result']); else $res = null;
layout_head(t('eq.title'), 'equipment');
?>
<div class="page-head">
  <div><h1><?= tt('eq.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= $staff ? tt('eq.lede') : tt('eq.lede_cust') ?></p></div>
  <?php if ($staff): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('equipment_export', $keep) ?>">⤓ <?= tt('eq.export') ?></a><a class="btn btn--ghost" href="#eqimport">⤒ <?= tt('eq.import') ?></a><a class="btn btn--gold" href="<?= url('equipment_edit') ?>">+ <?= tt('eq.new') ?></a></div><?php endif; ?>
</div>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('eq.need_db') ?></div><?php layout_foot(); return; endif; ?>
<?php if ($staff && function_exists('pd_has') && pd_has()): $pdc = pd_counts(); $pdOpen = 0; foreach (PD_OPEN as $st) $pdOpen += $pdc[$st] ?? 0; ?>
<nav class="tabs" aria-label="<?= tt('eq.title') ?>"><a class="is-on" href="<?= url('equipment') ?>">🔌 <?= tt('eq.register') ?></a><a href="<?= url('production') ?>">🏭 <?= tt('pd.title') ?> <span class="pill pill--gold"><?= $pdOpen ?></span></a></nav>
<?php endif; ?>
<div class="kpi kpi--4">
  <a class="stat" href="<?= url('equipment', ['status' => 'all']) ?>"><b><?= (int)$kpi['n'] ?></b><span class="s"><?= tt('eq.k_units') ?></span></a>
  <a class="stat" href="<?= url('equipment', ['status' => 'active']) ?>"><b><?= (int)$kpi['a'] ?></b><span class="s"><?= tt('eq.k_active') ?></span></a>
  <a class="stat gold" href="<?= url('equipment', ['status' => 'warranty']) ?>"><b><?= (int)$kpi['w'] ?></b><span class="s"><?= tt('eq.k_warranty') ?></span></a>
  <a class="stat green" href="<?= url('equipment', ['status' => 'sold']) ?>"><b><?= (int)$kpi['s'] ?></b><span class="s"><?= tt('eq.k_sold') ?></span></a>
</div>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr 1fr 1fr auto;">
  <input type="hidden" name="p" value="equipment">
  <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('eq.search_ph') ?>"></div>
  <div class="field"><label><?= tt('eq.maker') ?></label><select name="maker"><option value=""><?= tt('flt.any') ?></option><?php foreach (eq_makers() as $m): ?><option value="<?= e($m) ?>"<?= $sel($m, $f['maker']) ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('eq.family') ?></label><select name="family"><option value=""><?= tt('flt.any') ?></option><?php foreach (eq_families() as $m): ?><option value="<?= e($m) ?>"<?= $sel($m, $f['family']) ?>><?= e($m) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vesselOpts as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $sel($v['id'], $f['vessel']) ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status"><?php foreach (['active' => t('eq.st_active'), 'warranty' => t('eq.st_warranty'), 'sold' => t('eq.st_sold'), 'retired' => t('eq.st_retired'), 'all' => t('flt.all')] as $k => $l): ?><option value="<?= $k ?>"<?= $sel($k, $f['status']) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('equipment', ['reset' => 1, 'reset' => 1]) ?>"><?= tt('btn.clear') ?></a></div>
</form>
<div class="card">
  <?php if (!$rows): ?><div class="empty"><?= tt('eq.none') ?></div><?php else: [$pageRows, $pager] = page_list($rows, 'equipment', $keep); ?>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('eq.sn') ?></th><th><?= tt('eq.maker') ?></th><th><?= tt('eq.model') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('eq.warranty') ?></th><th class="num"><?= tt('eq.records') ?></th><th><?= tt('eq.last_service') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead>
    <tbody><?php foreach ($pageRows as $e): ?>
      <tr>
        <td class="ref"><a href="<?= url('equipment_view', ['id' => (int)$e['id']]) ?>"><b><?= e($e['serial_no']) ?></b></a><?= $e['set_code'] ? '<br><span class="hint">' . e($e['set_code']) . '</span>' : '' ?></td>
        <td><?= e($e['maker']) ?><?= $e['family'] ? ' <span class="hint">· ' . e($e['family']) . '</span>' : '' ?></td>
        <td><?= e($e['model'] ?: '—') ?></td>
        <td><?= $e['vessel_id'] ? '<a href="' . url('vessel', ['id' => (int)$e['vessel_id']]) . '">' . e($e['vessel']) . '</a>' : e($e['site_name'] ?: '—') ?><?= $e['customer_name'] ? '<br><span class="hint">' . e($e['customer_name']) . '</span>' : '' ?></td>
        <td class="date"><?= $e['warranty_until'] ? ($e['warranty_until'] >= date('Y-m-d') ? pill('gold', fmt_date($e['warranty_until'])) : '<span class="hint">' . e(fmt_date($e['warranty_until'])) . '</span>') : '—' ?></td>
        <td class="num"><?= (int)$e['n_hist'] ?></td>
        <td class="date"><?= $e['last_service'] ? e(fmt_date($e['last_service'])) : '—' ?></td>
        <td><?= $e['retired'] ? pill('mute', t('eq.st_retired')) : pill('green', t('eq.st_active')) ?><?= $e['sold_by_us'] ? ' ' . pill('navy', t('eq.sold_short')) : '' ?></td>
        <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('equipment_view', ['id' => (int)$e['id']]) ?>"><?= tt('fsr.open') ?></a></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?= $pager ?>
  <?php endif; ?>
</div>
<?php if ($staff): ?>
<div class="card" id="eqimport">
  <h2><?= tt('eq.import') ?></h2>
  <p class="hint"><?= tt('eq.import_hint') ?></p>
  <form method="post" action="<?= url('equipment') ?>" enctype="multipart/form-data" class="fltbar" style="grid-template-columns: 2fr 1fr auto auto;" data-lock data-busy="<?= tt('im.running') ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="eq_import">
    <div class="field"><label><?= tt('im.file') ?></label><input name="xlsx" type="file" accept=".xlsx" required></div>
    <div class="field"><label><?= tt('eq.maker') ?></label><input name="maker" list="eq-makers" required maxlength="60" placeholder="ATLAS"><datalist id="eq-makers"><?php foreach (eq_makers() as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?><option value="ATLAS"><option value="MAGNUS"></datalist></div>
    <label class="chk" style="padding-bottom:10px;"><input type="checkbox" name="dry" value="1" checked> <?= tt('im.dry') ?></label>
    <div class="fltbar-actions"><button class="btn" type="submit"><?= tt('im.run') ?></button></div>
  </form>
  <?php if ($res): ?><div class="flash <?= $res['dry'] ? 'flash--warn' : 'flash--good' ?>" style="margin-top:10px;"><?= e(t('eq.im_result', ['n' => $res['rows'], 'a' => $res['units_new'], 'u' => $res['units_upd'], 'r' => $res['records'], 'v' => $res['vessels_new']])) ?><?= $res['dry'] ? ' · ' . tt('im.dry_note') : '' ?>
    <?php if ($res['warnings']): ?><ul class="hint" style="margin:6px 0 0 18px;"><?php foreach (array_slice($res['warnings'], 0, 30) as [$ln, $why]): ?><li><?= e($why) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($res['skipped']): ?><ul class="hint" style="margin:6px 0 0 18px;"><?php foreach (array_slice($res['skipped'], 0, 20) as [$ln, $why]): ?><li><?= e(t('im.line', ['n' => $ln])) ?>: <?= e($why) ?></li><?php endforeach; ?></ul><?php endif; ?></div><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

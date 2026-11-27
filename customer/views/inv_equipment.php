<?php
/** Procurement › Inventory › Equipment (GAS-CP-R97-290): the register of the company equipment — one line per unit, with its place or holder and, for measuring equipment, the state of its calibration from the ISO 9001 register.
 *   ?p=inv_equipment[&q=&state=wh|person|retired|out|all&person=ID&cal=measuring|valid|soon|expired|out&cat=ID&pg=N][&format=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('eqp_can_view') || !eqp_can_view()) { http_response_code(403); layout_head(t('eqp.title'), 'inventory'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60), 'state' => in_array($_GET['state'] ?? '', array_merge(EQP_STATES, ['all']), true) ? (string)$_GET['state'] : '', 'person' => (int)($_GET['person'] ?? 0), 'cal' => in_array($_GET['cal'] ?? '', EQP_CALS, true) ? (string)$_GET['cal'] : '', 'cat' => (int)($_GET['cat'] ?? 0)];
$rows = eqp_rows($f);
if (($_GET['format'] ?? '') === 'xlsx') { while (ob_get_level()) ob_end_clean(); eqp_export($rows); exit; }
$S = eqp_stats(); $per = 200; $pg = max(1, (int)($_GET['pg'] ?? 1)); $total = count($rows); $pages = (int) ceil($total / $per); $page = array_slice($rows, ($pg - 1) * $per, $per);
$qh = eqp_qh(); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $link = fn(array $x = []) => url('inv_equipment', array_filter(array_merge($f, $x)));
layout_head(t('eqp.title'), 'inventory');
?>
<p class="eyebrow"><a href="<?= url('inventory') ?>"><?= tt('stk.title') ?></a></p>
<div class="page-head"><div><h1>🧰 <?= tt('eqp.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('eqp.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= e($link(['format' => 'xlsx'])) ?>">⤓ Excel</a><?php if (eqp_import_can()): ?><a class="btn btn--ghost btn--sm" href="<?= url('inv_equipment_import') ?>">⤒ <?= tt('eqp.imp_title') ?></a><?php endif; ?><?php if ($qh): ?><a class="btn btn--ghost btn--sm" href="<?= url('qhse', ['std' => '9001', 'reg' => 'qequip']) ?>">📏 <?= tt('eqp.to_iso') ?></a><?php endif; ?></div></div>
<div class="kpi kpi--4" style="margin-bottom:12px;">
  <a class="stat" href="<?= url('inv_equipment') ?>"><b><?= (int)$S['all'] ?></b><?= tt('eqp.k_all') ?></a>
  <a class="stat green" href="<?= url('inv_equipment', ['state' => 'wh']) ?>"><b><?= (int)$S['wh'] ?></b><?= tt('eqp.s_wh') ?></a>
  <a class="stat gold" href="<?= url('inv_equipment', ['state' => 'person']) ?>"><b><?= (int)$S['person'] ?></b><?= tt('eqp.s_person') ?></a>
  <a class="stat<?= $S['expired'] ? ' red' : '' ?>" href="<?= url('inv_equipment', ['cal' => 'measuring']) ?>"><b><?= (int)$S['measuring'] ?></b><?= tt('eqp.k_measuring') ?><?php if ($S['expired'] || $S['soon']): ?> · <?= e(t('eqp.k_cal', ['e' => (int)$S['expired'], 's' => (int)$S['soon']])) ?><?php endif; ?></a>
</div>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr 1fr 1fr auto; margin-bottom:10px;"><input type="hidden" name="p" value="inv_equipment">
    <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" maxlength="60" placeholder="<?= tt('eqp.q_ph') ?>"></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="state" data-nosearch><option value=""><?= tt('eqp.f_in_use') ?></option><?php foreach (EQP_STATES as $s): ?><option value="<?= $s ?>"<?= $sel($f['state'], $s) ?>><?= tt('eqp.s_' . $s) ?></option><?php endforeach; ?><option value="all"<?= $sel($f['state'], 'all') ?>><?= tt('eqp.f_all') ?></option></select></div>
    <div class="field"><label><?= tt('eqp.c_holder') ?></label><select name="person"><option value="0">— <?= tt('eqp.f_any') ?> —</option><?php foreach (eqp_people() as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $sel($f['person'], $p['id']) ?>><?= e($p['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('qh.cal') ?></label><select name="cal" data-nosearch><option value="">— <?= tt('eqp.f_any') ?> —</option><option value="measuring"<?= $sel($f['cal'], 'measuring') ?>><?= tt('eqp.k_measuring') ?></option><?php foreach (['valid', 'soon', 'expired', 'out'] as $c): ?><option value="<?= $c ?>"<?= $sel($f['cal'], $c) ?>><?= tt('qh.cal_' . $c) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('stk.category') ?></label><select name="cat"><option value="0">— <?= tt('eqp.f_any') ?> —</option><?php foreach (eqp_cats() as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $sel($f['cat'], $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
    <div class="btn-row"><button class="btn btn--navy" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost" href="<?= url('inv_equipment') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
  <p class="hint"><?= e(t('eqp.n_units', ['n' => $total])) ?></p>
  <?php if (!$page): ?><div class="empty"><?= tt('eqp.none') ?><?php if (eqp_import_can() && !$S['all'] && !$S['retired']): ?> <a href="<?= url('inv_equipment_import') ?>"><?= tt('eqp.imp_title') ?></a><?php endif; ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--compact"><thead><tr><th><?= tt('eqp.c_no') ?></th><th><?= tt('stk.desc') ?></th><th><?= tt('eqp.c_maker') ?> · <?= tt('eqp.c_model') ?></th><th><?= tt('eqp.c_sn') ?></th><th><?= tt('eqp.c_where') ?></th><th><?= tt('qh.cal') ?></th><th><?= tt('eqp.c_warranty') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($page as $r): $c = eqp_cal($r); $one = url('inv_equipment_unit', ['id' => (int)$r['id']]); ?><tr>
    <td style="white-space:nowrap;"><a class="mono" href="<?= $one ?>"><b><?= e(eqp_no($r)) ?></b></a></td>
    <td><a href="<?= $one ?>"><?= e($r['description']) ?></a><?= (string)$r['category'] !== '' ? '<br><span class="hint">' . e($r['category']) . '</span>' : '' ?></td>
    <td class="hint"><?= e(trim((string)$r['maker'] . ' ' . (string)$r['factory_code'])) ?></td>
    <td class="mono hint"><?= (string)$r['serial_no'] !== (string)$r['asset_tag'] ? e($r['serial_no']) : '—' ?></td>
    <td><?= e(eqp_where($r)) ?><?= eqp_state($r) === 'person' && in_array($r['eq_status'], ['draft', 'pending'], true) ? '<br><span class="hint">' . tt('hre.st_' . $r['eq_status']) . '</span>' : '' ?></td>
    <td><?php if ($c): ?><?= pill($c[1], t('qh.cal_' . $c[0])) ?><?= $r['ism_until'] && $c[0] !== 'na' ? '<br><span class="hint">' . e(fmt_date($r['ism_until'])) . '</span>' : '' ?><?php else: ?><span class="hint">—</span><?php endif; ?></td>
    <td class="hint"><?= $r['warranty_until'] ? e(fmt_date($r['warranty_until'])) : '' ?></td>
    <td><?= eqp_state_pill($r) ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><div class="btn-row" style="margin-top:8px;"><?php for ($i = 1; $i <= $pages; $i++): ?><a class="btn btn--sm <?= $i === $pg ? 'btn--navy' : 'btn--ghost' ?>" href="<?= e($link(['pg' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

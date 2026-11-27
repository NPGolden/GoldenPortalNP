<?php
/** HR › Equipment register — every line given to the personnel, who holds it, what waits (GAS-CP-R97-124).  ?p=hr_equipment[&cat=&st=&q=][&export=xlsx] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hre_has') || !hre_has()) { layout_head(t('hre.title'), 'hr_equipment'); echo '<div class="flash flash--warn">' . tt('hre.need_db') . '</div>'; layout_foot(); return; }
if (!hre_can()) { http_response_code(403); layout_head(t('hre.title'), 'hr_equipment'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = ['cat' => (string)($_GET['cat'] ?? ''), 'st' => (string)($_GET['st'] ?? 'with'), 'q' => trim((string)($_GET['q'] ?? ''))]; if (!in_array($f['st'], ['with', 'missing', 'draft', 'exp', 'returned', 'all'], true)) $f['st'] = 'with';
if (($_GET['format'] ?? '') === 'template') { while (ob_get_level()) ob_end_clean(); hre_import_template(); exit; }
$rows = hre_register($f);
if (($_GET['export'] ?? '') === 'xlsx') { require_once __DIR__ . '/../app/xlsxw.php'; while (ob_get_level()) ob_end_clean(); xlsx_download('GAS-HR-equipment-' . date('Ymd') . '.xlsx', hre_register_sheets($rows)); }
$W = hre_waiting();
layout_head(t('hre.title'), 'hr_equipment');
?>
<div class="page-head"><div><h1>🧰 <?= tt('hre.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hre.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost" href="<?= url('hr_equipment', $f + ['export' => 'xlsx']) ?>">⤓ Excel</a></div></div>
<div class="kpi kpi--5" style="margin-bottom:14px;">
  <span class="stat<?= $W['issue'] ? ' gold' : '' ?>"><b><?= count($W['issue']) ?></b><span class="s"><?= tt('hre.w_issue') ?></span></span>
  <span class="stat<?= $W['return'] ? ' gold' : '' ?>"><b><?= count($W['return']) ?></b><span class="s"><?= tt('hre.w_return') ?></span></span>
  <span class="stat<?= $W['leavers'] ? ' red' : '' ?>"><b><?= count($W['leavers']) ?></b><span class="s"><?= tt('hre.w_leavers') ?></span></span>
  <span class="stat"><b><?= count($W['new']) ?></b><span class="s"><?= tt('hre.w_new') ?></span></span>
  <a class="stat<?= $W['exp'] ? ' gold' : '' ?>" href="<?= url('hr_equipment', ['st' => 'exp']) ?>"><b><?= count($W['exp']) ?></b><span class="s"><?= tt('hre.w_exp') ?></span></a>
</div>
<?php [$drN, $drP] = hre_drafts(); if ($drN && hre_can()): /* R97-287: lines prepared — for example by the import of company assets — and not issued yet */ ?>
<div class="card" id="eq-drafts" style="border-left:4px solid #C9A227;">
  <div class="card-head"><h2>📝 <?= tt('hre.dr_title') ?></h2><span class="hint"><?= e(t('hre.dr_banner', ['n' => $drN, 'p' => $drP])) ?></span></div>
  <p class="hint"><?= tt('hre.dr_hint') ?></p>
  <form method="post" action="<?= url('hr_equipment') ?>" data-lock class="btn-row"><?= csrf_field() ?><input type="hidden" name="action" value="hre_issue_all">
    <a class="btn btn--ghost btn--sm" href="<?= url('hr_equipment', ['st' => 'draft']) ?>"><?= tt('hre.dr_show') ?></a>
    <button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= e(t('hre.dr_issue_q', ['p' => $drP])) ?>" data-confirm-title="<?= tt('dlg.title') ?>">✉ <?= e(t('hre.dr_issue_all', ['p' => $drP])) ?></button>
  </form>
</div>
<?php endif; ?>
<?php if ($W['issue'] || $W['return'] || $W['leavers'] || $W['new']): ?>
<div class="card" id="eq-wait"><h2><?= tt('hre.w_title') ?></h2><table class="tbl" data-nosort><tbody>
  <?php foreach (array_merge($W['issue'], $W['return']) as $d): ?><tr><td><?= $d['kind'] === 'return' ? '↩' : '✉' ?> <a href="<?= url('hr_person', ['id' => (int)$d['person_id'], 'tab' => 'eq']) ?>#eq-docs"><b><?= e($d['name_en']) ?></b></a></td><td><?= e(t($d['kind'] === 'return' ? 'hre.return_list' : 'hre.issue_list') . ' ' . $d['doc_no']) ?></td><td><?= hre_doc_pill($d) ?></td><td class="hint"><?= e(fmt_date(substr((string)$d['created_at'], 0, 10))) ?></td></tr><?php endforeach; ?>
  <?php foreach ($W['leavers'] as $x): ?><tr><td>⚠ <a href="<?= url('hr_person', ['id' => (int)$x['id'], 'tab' => 'eq']) ?>"><b><?= e($x['name_en']) ?></b></a></td><td><?= e(t('hre.w_leaver_line', ['n' => (int)$x['n']])) ?></td><td><?= pill('bad', t('hr.left')) ?></td><td class="hint"><?= $x['left_date'] ? e(fmt_date($x['left_date'])) : '' ?></td></tr><?php endforeach; ?>
  <?php foreach ($W['new'] as $x): ?><tr><td>🟢 <a href="<?= url('hr_person', ['id' => (int)$x['id'], 'tab' => 'eq']) ?>#eq-add"><b><?= e($x['name_en']) ?></b></a></td><td><?= tt('hre.w_new_line') ?></td><td><?= pill('mute', t('hre.w_new_pill')) ?></td><td class="hint"><?= $x['hire_date'] ? e(fmt_date($x['hire_date'])) : '' ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.4fr 1fr 1fr auto; margin-bottom:10px;"><input type="hidden" name="p" value="hr_equipment">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('hre.search') ?>">
    <select name="cat" data-nosearch><option value=""><?= tt('hre.all_cats') ?></option><?php foreach (HRE_CATS as $c): ?><option value="<?= $c ?>"<?= $f['cat'] === $c ? ' selected' : '' ?>><?= e(hre_cat_label($c)) ?></option><?php endforeach; ?></select>
    <select name="st" data-nosearch><?php foreach (['with', 'missing', 'draft', 'exp', 'returned', 'all'] as $s): ?><option value="<?= $s ?>"<?= $f['st'] === $s ? ' selected' : '' ?>><?= tt('hre.f_' . $s) ?></option><?php endforeach; ?></select>
    <button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.filter') ?></button>
  </form>
  <h2><?= tt('hre.lines_title') ?> <span class="count"><?= count($rows) ?></span></h2>
  <?php if (!$rows): ?><div class="empty"><?= tt('hre.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th><?= tt('hre.serial') ?></th><th><?= tt('hre.tag') ?></th><th class="num"><?= tt('hre.qty') ?></th><th><?= tt('hre.issued_on') ?></th><th><?= tt('hre.issue_list') ?></th><th><?= tt('hre.expires') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $i): ?><tr><td><a href="<?= url('hr_person', ['id' => (int)$i['person_id'], 'tab' => 'eq']) ?>"><b><?= e($i['name_en']) ?></b></a><?= !$i['person_active'] ? ' ' . pill('mute', t('hr.left')) : '' ?></td><td><?= e(hre_cat_icon((string)$i['category']) . ' ' . hre_cat_label((string)$i['category'])) ?></td><td><?= e($i['description']) ?><?= (string)$i['size'] !== '' ? ' <span class="hint">' . e($i['size']) . '</span>' : '' ?></td><td><?= e((string)$i['serial_no']) ?></td><td><?= e((string)$i['asset_tag']) ?></td><td class="num"><?= e(hre_qty($i['qty'])) ?></td><td><?= $i['issued_on'] ? e(fmt_date($i['issued_on'])) : '—' ?></td><td><?= e((string)$i['issue_no']) ?></td><td><?= $i['expires_on'] ? ($i['expires_on'] <= date('Y-m-d', strtotime('+30 days')) && in_array($i['status'], ['pending', 'held'], true) ? pill($i['expires_on'] < date('Y-m-d') ? 'bad' : 'warn', fmt_date($i['expires_on'])) : e(fmt_date($i['expires_on']))) : '' ?></td><td><?= hre_item_pill($i) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<div class="card" id="eq-import">
  <h2>⤒ <?= tt('hre.imp_title') ?></h2><p class="hint"><?= tt('hre.imp_hint') ?></p>
  <?php $ir_ = $_SESSION['hre_import'] ?? null; unset($_SESSION['hre_import']); if ($ir_ && $ir_['skipped']): ?><div class="flash flash--warn"><b><?= e(t('hre.imp_skipped', ['n' => count($ir_['skipped'])])) ?></b><ul style="margin:4px 0 0;"><?php foreach (array_slice($ir_['skipped'], 0, 40) as [$rn_, $why_]): ?><li><?= e(t('hre.imp_row', ['r' => $rn_])) ?>: <?= e($why_) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <form method="post" action="<?= url('hr_equipment') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hre_import">
    <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('hr_equipment', ['format' => 'template']) ?>">⤓ <?= tt('hre.imp_template') ?></a><input type="file" name="file" accept=".xlsx" required><label class="chk" style="margin:0;"><input type="checkbox" name="issue" value="1"> <?= tt('hre.imp_issue') ?></label><button class="btn btn--gold btn--sm" type="submit" data-confirm="<?= tt('hre.imp_confirm') ?>">⤒ <?= tt('hre.imp_btn') ?></button></div>
  </form>
</div>

<?php if (hre_kits_has()): $kits_ = hre_kits(); $kd_ = hr_departments(); ?>
<div class="card" id="eq-kits">
  <h2>🎒 <?= tt('hre.kit_title') ?> <span class="count"><?= count($kits_) ?></span></h2><p class="hint"><?= tt('hre.kit_lede') ?></p>
  <?php if ($kits_): ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hre.f_discipline') ?></th><th><?= tt('hre.category') ?></th><th><?= tt('hre.description') ?></th><th class="num"><?= tt('hre.qty') ?></th><th class="num"><?= tt('hre.kit_months') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($kits_ as $k_): ?><tr><td><?= (int)$k_['discipline_id'] ? e((string)$k_['discipline']) : '<b>' . tt('hre.kit_everybody') . '</b>' ?></td><td><?= e(hre_cat_icon((string)$k_['category']) . ' ' . hre_cat_label((string)$k_['category'])) ?></td><td><?= e($k_['description']) ?></td><td class="num"><?= e(hre_qty($k_['qty'])) ?></td><td class="num"><?= $k_['replace_months'] ? (int)$k_['replace_months'] : '—' ?></td>
    <td class="row-actions"><form method="post" action="<?= url('hr_equipment') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hre_kit_del"><input type="hidden" name="kit_id" value="<?= (int)$k_['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">✕</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <form method="post" action="<?= url('hr_equipment') ?>" class="form-grid" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="hre_kit_save">
    <div class="field"><label><?= tt('hre.f_discipline') ?></label><select name="discipline_id"><option value="0"><?= tt('hre.kit_everybody') ?></option><?php foreach ($kd_ as $d_): ?><option value="<?= (int)$d_['id'] ?>"><?= e($d_['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.category') ?></label><select name="category" data-nosearch><?php foreach (HRE_CATS as $c): ?><option value="<?= $c ?>"><?= e(hre_cat_icon($c) . ' ' . hre_cat_label($c)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('hre.description') ?></label><input name="description" maxlength="200" required placeholder="<?= tt('hre.desc_ph') ?>"></div>
    <div class="field"><label><?= tt('hre.qty') ?></label><input name="qty" value="1" inputmode="decimal" style="max-width:110px;"></div>
    <div class="field"><label><?= tt('hre.kit_months') ?></label><input name="replace_months" inputmode="numeric" style="max-width:110px;" placeholder="12"><p class="hint"><?= tt('hre.kit_months_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit">＋ <?= tt('hre.kit_add') ?></button></div>
  </form>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

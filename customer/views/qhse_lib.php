<?php
/** QHSE › Documents — the read-only library of the approved documents of the QHSE systems, for all staff.   GAS-CP-R97-087   ?p=qhse_lib[&q=][&std=][&type=][&file=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('qh_library') || !ism_has_ext()) { http_response_code(404); layout_head(t('qh.lib_title'), 'qhse_lib'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!empty($_GET['file'])) { qh_library_send((int)$_GET['file']); exit; }
$f = ['q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80), 'std' => in_array($_GET['std'] ?? '', ['s9001', 's14001', 's45001', 's22301'], true) ? (string)$_GET['std'] : '', 'type' => in_array($_GET['type'] ?? '', ism_opts(ism_reg('qdoc')['f']['doc_type']), true) ? (string)$_GET['type'] : ''];
$rows = qh_library($f); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : ''; $by = []; foreach ($rows as $r) $by[(string)($r['_d']['doc_type'] ?? '')][] = $r;
$order = array_merge(ism_opts(ism_reg('qdoc')['f']['doc_type']), ['']);
layout_head(t('qh.lib_title'), 'qhse_lib');
?>
<div class="page-head"><div><h1>📗 <?= tt('qh.lib_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('qh.lib_lede') ?></p></div>
  <?php if (qh_can()): ?><div class="btn-row"><a class="btn btn--ghost" href="<?= url('qhse', ['reg' => 'qdoc']) ?>">✎ <?= tt('qh.lib_manage') ?></a></div><?php endif; ?></div>
<div class="card">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.6fr 1fr 1fr auto;"><input type="hidden" name="p" value="qhse_lib">
    <div class="field"><label><?= tt('r.word') ?></label><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="<?= tt('qh.lib_ph') ?>"></div>
    <div class="field"><label><?= tt('qh.std') ?></label><select name="std" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (['s9001', 's14001', 's45001', 's22301'] as $x): ?><option value="<?= $x ?>"<?= $sel($x, $f['std']) ?>><?= tt('ism.o_' . $x) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('ism.f_doc_type') ?></label><select name="type" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (ism_opts(ism_reg('qdoc')['f']['doc_type']) as $x): ?><option value="<?= $x ?>"<?= $sel($x, $f['type']) ?>><?= tt('ism.o_' . $x) ?></option><?php endforeach; ?></select></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('qhse_lib') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
  <?php if (!$rows): ?><div class="empty"><?= tt('qh.lib_none') ?></div><?php endif; ?>
  <?php foreach ($order as $ty): if (empty($by[$ty])) continue; ?>
  <h3 style="margin:14px 0 6px;"><?= $ty !== '' ? tt('ism.o_' . $ty) : tt('qh.lib_other') ?> <span class="count"><?= count($by[$ty]) ?></span></h3>
  <div class="table-scroll"><table class="tbl tbl--wrap">
    <thead><tr><th><?= tt('ism.code') ?></th><th><?= tt('ism.title_f') ?></th><th><?= tt('qh.std') ?></th><th><?= tt('ism.f_version') ?></th><th><?= tt('ism.f_approved_on') ?></th><th><?= tt('ism.files') ?></th></tr></thead>
    <tbody><?php foreach ($by[$ty] as $r): $d = $r['_d']; ?><tr>
      <td class="mono" style="white-space:nowrap;"><?= e($r['code']) ?></td>
      <td><b><?= e($r['title']) ?></b><?= !empty($d['summary']) ? '<br><span class="hint">' . e(mb_substr((string)$d['summary'], 0, 240)) . '</span>' : '' ?></td>
      <td class="hint" style="white-space:nowrap;"><?= tt('ism.o_' . (($d['std'] ?? '') ?: 'all_std')) ?></td>
      <td class="mono"><?= e((string)($d['version'] ?? '')) ?: '—' ?></td>
      <td style="white-space:nowrap;"><?= !empty($d['approved_on']) ? e(fmt_date($d['approved_on'])) : '—' ?><?= !empty($d['approved_by']) ? '<br><span class="hint">' . e($d['approved_by']) . '</span>' : '' ?></td>
      <td><?php if (!$r['_files']): ?><span class="hint">—</span><?php else: foreach ($r['_files'] as $x): ?><a href="<?= url('qhse_lib', ['file' => (int)$x['id']]) ?>">⤓ <?= e($x['original_name']) ?></a><?= $x['note'] ? ' <span class="hint">' . e($x['note']) . '</span>' : '' ?><br><?php endforeach; endif; ?></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <?php endforeach; ?>
  <p class="hint" style="margin:12px 0 0;"><?= tt('qh.lib_hint') ?></p>
</div>
<?php layout_foot(); ?>

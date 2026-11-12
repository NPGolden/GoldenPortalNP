<?php
/** Vessels — import of particulars (IMO, class, flag, yard, web link) from a workbook, with review; only empty fields are filled (GAS-CP-R97-025). ?p=vessels_import[&t=<token>] */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); $token = (string)($_GET['t'] ?? ''); $path = $token !== '' ? vm_imp_path($token) : null; $P = null; $err = null;
if ($token !== '' && !$path) $err = t('vi.expired'); elseif ($path) { try { $P = vm_imp_plan($path); } catch (Throwable $e) { $err = $e->getMessage(); } }
$lab = ['imo' => t('v.imo'), 'class_society' => t('v.class'), 'flag' => t('v.flag'), 'yard' => t('v.yard'), 'info_url' => t('vi.link')];
layout_head(t('vi.title'), 'vessels');
?>
<p class="eyebrow"><a href="<?= url('vessels') ?>"><?= tt('nav.vessels') ?></a> › <?= tt('vi.title') ?></p>
<div class="page-head"><div><h1>📥 <?= tt('vi.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('vi.lede') ?></p></div></div>
<?php if ($err): ?><p class="flash flash--bad"><?= e($err) ?></p><?php endif; ?>
<?php if (!$P): ?>
<div class="card" style="max-width:820px;">
  <h2><?= tt('vi.upload') ?></h2>
  <form method="post" action="<?= url('vessels_import') ?>" enctype="multipart/form-data" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="vessels_imp_upload">
    <div class="field"><label><?= tt('vi.file') ?> *</label><input type="file" name="file" accept=".xlsx" required><p class="hint" style="margin:4px 0 0;"><?= tt('vi.file_hint') ?></p></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('vi.review') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('vessels') ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php else: $n = $P['n']; ?>
<div class="kpi kpi--4">
  <div class="stat"><b><?= (int)$n['rows'] ?></b><?= tt('vi.k_rows') ?><span class="s"><?= e((string)($_SESSION['vm_imp'][$token] ?? '')) ?></span></div>
  <div class="stat green"><b><?= (int)$n['fill'] ?></b><?= tt('vi.k_fill') ?></div>
  <div class="stat"><b><?= (int)$n['nothing'] ?></b><?= tt('vi.k_nothing') ?></div>
  <div class="stat<?= $n['unmatched'] ? ' gold' : '' ?>"><b><?= (int)$n['unmatched'] ?></b><?= tt('vi.k_unmatched') ?><span class="s"><?= $n['bad'] ? e(t('vi.k_bad', ['n' => (int)$n['bad']])) : '' ?></span></div>
</div>
<form method="post" action="<?= url('vessels_import', ['t' => $token]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="vessels_imp_apply"><input type="hidden" name="t" value="<?= e($token) ?>">
<div class="card">
  <div class="card-head"><h2><?= tt('vi.rows') ?> <span class="count"><?= count($P['rows']) ?></span></h2><span class="hint"><?= tt('vi.rows_hint') ?></span></div>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th></th><th style="min-width:200px;"><?= tt('nav.vessels') ?></th><th><?= tt('vi.conf') ?></th><?php foreach ($lab as $l): ?><th><?= e($l) ?></th><?php endforeach; ?><th style="min-width:220px;"><?= tt('th.remarks') ?></th></tr></thead><tbody>
  <?php foreach ($P['rows'] as $r): $v = $r['vessel']; $can = $v && $r['set']; $low = in_array($r['conf'], ['LOW', 'AMBIGUOUS', 'NOT FOUND', 'NOT A VESSEL'], true); ?><tr<?= !$can ? ' class="hint"' : '' ?>>
    <td><?php if ($can): ?><input type="checkbox" name="pick[]" value="<?= (int)$r['i'] ?>"<?= $low ? '' : ' checked' ?>><?php endif; ?></td>
    <td><?php if ($v): ?><a href="<?= url('vessel', ['id' => (int)$v['id']]) ?>" target="_blank" rel="noopener"><b><?= e((string)$v['name']) ?></b></a><?php else: ?><?= e($r['name']) ?><br><?= pill('gold', t('vi.no_match')) ?><?php endif; ?></td>
    <td><?= $r['conf'] !== '' ? pill($r['conf'] === 'HIGH' ? 'green' : ($r['conf'] === 'MEDIUM' ? 'navy' : 'gold'), $r['conf']) : '' ?></td>
    <?php foreach ($lab as $f => $l): $cur = $v ? trim((string)($v[$f] ?? '')) : ''; $new = $r['set'][$f] ?? null; ?><td><?php
      if ($new !== null) echo $f === 'info_url' ? '<a href="' . e($new) . '" target="_blank" rel="noopener noreferrer"><b>' . e((string) parse_url($new, PHP_URL_HOST)) . '</b> ↗</a>' : '<b>' . e($new) . '</b>';
      elseif ($cur !== '') echo '<span class="hint" title="' . tt('vi.kept') . '">' . e($f === 'info_url' ? (string) parse_url($cur, PHP_URL_HOST) : $cur) . ' ✓</span>';
    ?></td><?php endforeach; ?>
    <td class="hint"><?= e($r['note']) ?><?php foreach ($r['warn'] as $w): ?><br><span class="bad"><?= e($w) ?></span><?php endforeach; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <p class="hint" style="margin:8px 0 0;"><?= tt('vi.foot') ?></p>
</div>
<div class="btn-row"><button class="btn btn--gold" type="submit"<?= $n['fill'] ? '' : ' disabled' ?>><?= tt('vi.apply') ?></button><a class="btn btn--ghost" href="<?= url('vessels_import') ?>"><?= tt('vi.other') ?></a></div>
</form>
<?php endif; ?>
<?php layout_foot(); ?>

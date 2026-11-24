<?php
/** Service reports without a serial number (GAS-CP-R97-225): reports that name ATLAS, MAGNUS, AVENTICS, EMERSON … and have no unit of the equipment register linked.  ?p=fsr_sn */
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
$u = require_staff();
if (!function_exists('eq_has') || !eq_has()) { http_response_code(404); layout_head(t('err.notfound'), 'reports'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$words = fsr_sn_words(); $rows = fsr_sn_missing(true); $adm = (function_exists('mgmt_can') && mgmt_can()) || is_cp_admin();
$fw = mb_strtoupper(trim((string)($_GET['w'] ?? ''))); if (!in_array($fw, $words, true)) $fw = '';
$nBy = array_fill_keys($words, 0); foreach ($rows as $r) foreach ($r['words'] as $x) $nBy[$x] = ($nBy[$x] ?? 0) + 1;
$L = $fw === '' ? $rows : array_values(array_filter($rows, fn($r) => in_array($fw, $r['words'], true)));
$last = explode('|', (string) cp_setting('fsr_sn_last', ''));
layout_head(t('fsr.sn_title'), 'reports');
?>
<p class="eyebrow"><a href="<?= url('reports') ?>"><?= tt('r.title') ?></a> › <?= tt('fsr.sn_title') ?></p>
<h1>🔧 <?= tt('fsr.sn_title') ?></h1><p class="lede"><?= tt('fsr.sn_lede') ?></p>
<div class="card"><div class="card-head"><h2><?= $rows ? '⚠' : '✓' ?> <?= tt('fsr.sn_list') ?> <span class="count"><?= count($L) ?></span></h2><span class="hint"><?= tt('fsr.sn_list_hint') ?></span></div>
<p><a class="pill<?= $fw === '' ? ' pill--navy' : '' ?>" href="<?= url('fsr_sn') ?>" style="margin-right:6px;"><?= tt('flt.any') ?> <b><?= count($rows) ?></b></a><?php foreach ($words as $x): ?><a class="pill<?= $fw === $x ? ' pill--navy' : '' ?>" href="<?= url('fsr_sn', ['w' => $x]) ?>" style="margin-right:6px;"><?= e($x) ?> <b><?= (int)$nBy[$x] ?></b></a><?php endforeach; ?></p>
<?php if (!$L): ?><p><?= pill('green', t('fsr.sn_ok')) ?></p><?php else: ?>
<div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('fsr.no') ?></th><th><?= tt('th.date') ?></th><th><?= tt('pr.one') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('fsr.engineer') ?></th><th><?= tt('th.status') ?></th><th><?= tt('fsr.sn_found') ?></th><th></th></tr></thead><tbody>
<?php foreach ($L as $r): ?><tr>
  <td class="num"><a href="<?= url('fsr', ['id' => (int)$r['id']]) ?>#equipment"><?= e((string)$r['report_no']) ?></a></td>
  <td class="date"><?= e(fmt_date((string)$r['report_date'])) ?></td>
  <td><?= e((string)($r['project_code'] ?: $r['project_name'])) ?></td>
  <td><?= e((string)($r['vessel_name'] ?: ($r['vessel'] ?? ''))) ?></td>
  <td><?= e((string)($r['submitted_name'] ?: '—')) ?></td>
  <td><?= fsr_status_pill((string)$r['status']) ?></td>
  <td><?php foreach ($r['words'] as $x): ?><?= pill('gold', $x) ?> <?php endforeach; ?></td>
  <td class="row-actions"><a class="btn btn--gold btn--sm" href="<?= url('fsr', ['id' => (int)$r['id']]) ?>#equipment"><?= tt('fsr.sn_add') ?></a></td>
</tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<p class="hint" style="margin-top:10px;"><?= count($last) === 3 ? e(t('fsr.sn_daily_last', ['when' => fmt_datetime($last[0] . ':00'), 'n' => (int)$last[1], 'm' => (int)$last[2]])) : tt('fsr.sn_daily_none') ?></p></div>
<div class="card"><div class="card-head"><h2><?= tt('fsr.sn_words') ?></h2><span class="hint"><?= tt('fsr.sn_words_hint') ?></span></div>
<?php if ($adm): ?><form method="post" action="<?= url('fsr_sn') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="fsr_sn_words"><input name="words" maxlength="600" value="<?= e(implode(', ', $words)) ?>"><div class="btn-row" style="margin-top:8px;"><button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button></div></form>
<?php else: ?><p><?php foreach ($words as $x): ?><?= pill('mute', $x) ?> <?php endforeach; ?></p><?php endif; ?></div>
<?php layout_foot();

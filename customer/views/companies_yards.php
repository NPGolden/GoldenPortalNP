<?php
/** Organisations › Known shipyards (GAS-CP-R97-052): organisations whose name says "yard", for approval. ?p=companies_yards
 *  Nothing is changed by opening the page. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!yd_can()) { http_response_code(403); layout_head(t('yd.title'), 'contacts'); echo '<h1>' . tt('yd.title') . '</h1><div class="flash flash--bad">' . tt('err.noaccess') . '</div>'; layout_foot(); return; }
$rows = yd_scan(); $nName = 0; $nWord = 0; $nWeak = 0; foreach ($rows as $r) { if ($r['weak']) $nWeak++; elseif ($r['why'] === 'name') $nName++; else $nWord++; }
$have = (int) scalar("SELECT COUNT(*) FROM companies WHERE kind = 'yard' OR FIND_IN_SET('yard', roles)");
layout_head(t('yd.title'), 'contacts');
?>
<p class="eyebrow"><a href="<?= url('companies') ?>"><?= tt('co.title') ?></a></p>
<div class="page-head"><div><h1>⚓ <?= tt('yd.title') ?></h1><p class="lede"><?= tt('yd.lede') ?></p></div></div>
<div class="kpi" style="margin-bottom:14px;">
  <div class="stat green"><b><?= $have ?></b><?= tt('yd.k_have') ?></div>
  <div class="stat gold"><b><?= $nName ?></b><?= tt('yd.k_name') ?></div>
  <div class="stat"><b><?= $nWord ?></b><?= tt('yd.k_word') ?></div>
  <div class="stat"><b><?= $nWeak ?></b><?= tt('yd.k_weak') ?></div>
</div>
<?php if (!$rows): ?><div class="card"><p class="hint"><?= tt('yd.none') ?></p></div><?php else: ?>
<form method="post" action="<?= url('companies_yards') ?>" data-confirm="<?= tt('yd.apply_q') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="yd_apply">
<div class="card"><div class="card-head"><h2><?= tt('yd.proposed') ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= tt('yd.hint') ?></span></div>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><input type="checkbox" data-check-all="ids[]"></th><th><?= tt('yd.c_org') ?></th><th><?= tt('yd.c_now') ?></th><th><?= tt('yd.c_where') ?></th><th class="num"><?= tt('yd.c_contacts') ?></th><th><?= tt('yd.c_match') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>"<?= $r['weak'] ? '' : ' checked' ?>></td>
    <td><a href="<?= url('companies', ['edit' => (int)$r['id']]) ?>#form" target="_blank" rel="noopener"><b><?= e((string)$r['name']) ?></b></a><?= $r['is_active'] ? '' : ' ' . pill('mute', t('yd.inactive')) ?></td>
    <td><?= co_role_pills($r) ?></td>
    <td><?= e(trim((string)$r['city'] . ((string)$r['city'] !== '' && (string)$r['country'] !== '' ? ', ' : '') . (string)$r['country'])) ?></td>
    <td class="num"><?= (int)$r['n_contacts'] ?: '—' ?></td>
    <td><?= pill($r['weak'] ? 'mute' : ($r['why'] === 'name' ? 'gold' : 'blue'), t($r['weak'] ? 'yd.m_weak' : ($r['why'] === 'name' ? 'yd.m_name' : 'yd.m_word'))) ?> <span class="hint">“<?= e((string)$r['hit']) ?>”</span></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit">✓ <?= tt('yd.apply') ?></button><span class="hint"><?= tt('yd.apply_hint') ?></span></div>
</div></form>
<?php endif; ?>
<?php layout_foot(); ?>

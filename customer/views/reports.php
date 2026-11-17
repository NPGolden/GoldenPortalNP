<?php
require __DIR__ . '/_layout.php';
require __DIR__ . '/_fsr_list.php';
require __DIR__ . '/_isr_list.php';
require __DIR__ . '/_fc_list.php';   // R77-001
$u = require_login(); $cid = (int)$u['customer_id'];
$fsrs = fsr_list(null, null, is_staff() ? null : $cid);
$pending = array_values(array_filter($fsrs, fn($r) => $r['status'] === 'submitted'));
$docs = all("SELECT d.*, v.name AS vessel FROM documents d LEFT JOIN vessels v ON v.id=d.vessel_id
             WHERE d.customer_id=? AND d.category IN ('fsr','workorder')
             ORDER BY d.updated_at DESC LIMIT 200", [$cid]);   // older documents — the query was dropped by mistake in v1.157.1 (R97-078)
// R97-074 filter by vessel and order · R97-077 three tabs, each with its own filters (word, vessel, project, status, order) and what needs action
$isrOn = isr_has(); $fcOn = function_exists('fc_has') && fc_has(); $staffV = is_staff();
$tab = (string)($_GET['tab'] ?? 'fsr'); if (!in_array($tab, ['fsr', 'isr', 'fc'], true) || ($tab === 'isr' && !$isrOn) || ($tab === 'fc' && !$fcOn)) $tab = 'fsr';
$isrs = $isrOn ? isr_list() : []; $fcs = $fcOn ? fc_list(null, null, null) : [];
/** What a report still needs — for staff the next step of Golden A/S, for a customer the own acceptance. Null = nothing to do. */
$act = function (string $k, array $r) use ($staffV): ?string {
    $st = (string)($r['status'] ?? '');
    if (!$staffV) return $st === 'submitted' && $k !== 'isr' ? t('r.a_accept_you') : null;
    if ($k === 'isr') return $st === 'draft' ? t('r.a_finish') : ($st === 'checking' ? t('r.a_check') : null);
    if ($k === 'fsr' && ($r['kind'] ?? 'internal') === 'external' && empty($r['converted_id'])) return t('r.a_process');
    return $st === 'draft' ? t('r.a_finish') : ($st === 'submitted' ? t('r.a_accept') : ($st === 'disputed' ? t('r.a_back') : null));
};
$nAct = [];
foreach (['fsr' => &$fsrs, 'isr' => &$isrs, 'fc' => &$fcs] as $k => &$rowsK) { $nAct[$k] = 0; foreach ($rowsK as &$r) { $r['_action'] = $act($k, $r); if ($r['_action'] !== null) $nAct[$k]++; } unset($r); } unset($rowsK);
$nTab = ['fsr' => count($fsrs), 'isr' => count($isrs), 'fc' => count($fcs)];
$L = $tab === 'isr' ? $isrs : ($tab === 'fc' ? $fcs : $fsrs);
$vNames = []; $pOpts = []; $sOpts = [];
foreach ($L as $r) {
    if ((string)($r['vessel'] ?? '') !== '') $vNames[(string)$r['vessel']] = 1;
    if ((int)($r['project_id'] ?? 0)) $pOpts[(int)$r['project_id']] = trim((string)($r['project_code'] ?? '') . ' · ' . (string)($r['project_name'] ?? ''), ' ·');
    if ((string)($r['status'] ?? '') !== '') $sOpts[(string)$r['status']] = 1;
}
ksort($vNames, SORT_NATURAL | SORT_FLAG_CASE); $vNames = array_keys($vNames); natcasesort($pOpts);
$stLabel = fn(string $st) => trim(strip_tags($tab === 'isr' ? isr_status_pill($st) : ($tab === 'fc' ? fc_status_pill($st) : fsr_status_pill($st))));
$fv = (string)($_GET['fv'] ?? ''); if (!in_array($fv, $vNames, true)) $fv = '';
$fp = (int)($_GET['fp'] ?? 0); if (!isset($pOpts[$fp])) $fp = 0;
$fs = (string)($_GET['fs'] ?? ''); if (!isset($sOpts[$fs])) $fs = '';
$fq = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80); $fa = !empty($_GET['fa']);
$fo = in_array($_GET['fo'] ?? '', ['old', 'vaz', 'vza'], true) ? (string)$_GET['fo'] : '';
if ($fv !== '' || $fp || $fs !== '' || $fq !== '' || $fa) {
    $needle = mb_strtolower($fq);
    $L = array_values(array_filter($L, function ($r) use ($fv, $fp, $fs, $fa, $needle) {
        if ($fa && $r['_action'] === null) return false;
        if ($fv !== '' && (string)($r['vessel'] ?? '') !== $fv) return false;
        if ($fp && (int)($r['project_id'] ?? 0) !== $fp) return false;
        if ($fs !== '' && (string)($r['status'] ?? '') !== $fs) return false;
        if ($needle === '') return true;
        $hay = ''; foreach (['report_no', 'project_code', 'project_name', 'sub_code', 'sub_name', 'vessel', 'vessel_name', 'subject', 'submitted_name', 'engineer_name', 'accepted_name', 'form', 'title'] as $k) $hay .= ' ' . (string)($r[$k] ?? '');
        return mb_strpos(mb_strtolower($hay), $needle) !== false;
    }));
}
$keepF = array_filter(['tab' => $tab === 'fsr' ? '' : $tab, 'fv' => $fv, 'fp' => $fp, 'fs' => $fs, 'q' => $fq, 'fo' => $fo, 'fa' => $fa ? 1 : 0]);
layout_head(t('r.title'), 'reports');
?>
<div class="page-head"><h1><?= tt('r.title') ?></h1><?= vg_toggle('reports') ?></div>
<p class="lede"><?= tt('r.lede') ?></p>
<nav class="tabs" style="margin-bottom:12px;"><?php $T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('reports', $k === 'fsr' ? [] : ['tab' => $k]) . '">' . $lbl . ' <span class="hint">' . (int)$nTab[$k] . '</span>' . ($nAct[$k] ? ' <span class="pill pill--gold" title="' . tt('r.act_title') . '">⚑ ' . (int)$nAct[$k] . '</span>' : '') . '</a>';
  echo $T('fsr', '📋 ' . t('fsr.plural')); if ($isrOn) echo $T('isr', '🔎 ' . t('isr.plural')); if ($fcOn) echo $T('fc', '🧪 ' . t('fc.plural')); ?></nav>
<form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.3fr 1fr 1.3fr 1fr 1fr 1fr auto;"><input type="hidden" name="p" value="reports"><?php if ($tab !== 'fsr'): ?><input type="hidden" name="tab" value="<?= e($tab) ?>"><?php endif; ?>
  <div class="field"><label><?= tt('r.word') ?></label><input name="q" value="<?= e($fq) ?>" maxlength="80" placeholder="<?= tt('r.word_ph') ?>"></div>
  <div class="field"><label><?= tt('th.vessel') ?></label><select name="fv"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vNames as $vn): ?><option value="<?= e($vn) ?>"<?= $vn === $fv ? ' selected' : '' ?>><?= e($vn) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('pr.one') ?></label><select name="fp"><option value=""><?= tt('flt.any') ?></option><?php foreach ($pOpts as $pk => $pl): ?><option value="<?= (int)$pk ?>"<?= (int)$pk === $fp ? ' selected' : '' ?>><?= e($pl) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="fs" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (array_keys($sOpts) as $sk): ?><option value="<?= e($sk) ?>"<?= $sk === $fs ? ' selected' : '' ?>><?= e($stLabel($sk)) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('r.show') ?></label><select name="fa" data-nosearch><option value=""><?= tt('r.show_all') ?></option><option value="1"<?= $fa ? ' selected' : '' ?>>⚑ <?= e(t('r.show_act', ['n' => (int)$nAct[$tab]])) ?></option></select></div>
  <div class="field"><label><?= tt('r.order') ?></label><select name="fo" data-nosearch><?php foreach (['' => 'r.o_new', 'old' => 'r.o_old', 'vaz' => 'r.o_vaz', 'vza' => 'r.o_vza'] as $ok => $ol): ?><option value="<?= $ok ?>"<?= $ok === $fo ? ' selected' : '' ?>><?= tt($ol) ?></option><?php endforeach; ?></select></div>
  <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('reports', $tab === 'fsr' ? [] : ['tab' => $tab]) ?>"><?= tt('btn.clear') ?></a></div>
</form>
<?php if ($nAct[$tab] && !$fa): ?><div class="flash flash--warn">⚑ <?= e(t('r.act_n', ['n' => (int)$nAct[$tab]])) ?> <a href="<?= url('reports', array_filter(['tab' => $tab === 'fsr' ? '' : $tab, 'fa' => 1])) ?>"><b><?= tt('r.act_show') ?> ›</b></a></div><?php endif; ?>
<?php if ($tab === 'fsr'): ?>
<div class="card">
  <h2><?= tt('fsr.plural') ?> <span class="count"><?= count($L) ?></span></h2>
  <?php fsr_table($L, true, 'reports', $keepF); ?>
</div>
<?php elseif ($tab === 'isr'): ?>
<div class="card">
  <h2><?= tt('isr.plural') ?> <span class="count"><?= count($L) ?></span></h2>
  <?php isr_table($L, true, 'reports', $keepF); ?>
</div>
<?php else: ?>
<div class="card">
  <div class="card-head"><h2>🧪 <?= tt('fc.plural') ?> <span class="count"><?= count($L) ?></span></h2><?php if (is_staff()): ?><div class="btn-row"><?php foreach (fc_forms() as $fk => $ft): ?><a class="btn btn--gold btn--sm" href="<?= url('fc_edit', ['form' => $fk]) ?>">+ <?= e($ft['short']) ?></a><?php endforeach; ?></div><?php endif; ?></div>
  <p class="hint"><?= tt('fc.lede') ?></p>
  <?php fc_table($L, true, 'reports', $keepF); ?>
</div>
<?php endif; ?>
<?php if ($docs && $tab === 'fsr'): ?>
<div class="card">
  <h2><?= tt('r.legacy') ?></h2>
<?php foreach ($docs as $d): ?>
  <div class="rowitem">
    <span class="ico">F</span>
    <span class="tx"><b><?= e($d['title']) ?></b><span><?= e($d['vessel'] ?: '—') ?> · <span class="date"><?= e(fmt_date($d['updated_at'])) ?></span></span></span>
    <?= doc_status_pill($d['status']) ?>
  </div>
<?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_foot(); ?>

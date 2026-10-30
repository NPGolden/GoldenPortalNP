<?php
/**
 * Admin console — Vessel systems master list.                GAS-CP-R9-001
 * The list every sub-project and drawing request picks from, keyed by the
 * Golden A/S drawing code and abbreviation (10 · MSB · MAIN SWITCHBOARD).
 * Admins add, rename, reorder, or retire systems; retired ones keep their
 * history. The whole list can be refreshed from Excel on the import page.
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$codes   = sys_has_codes();
$systems = all('SELECT vs.*, (SELECT COUNT(*) FROM subprojects s WHERE s.system_id = vs.id) AS n_subs,
                       (SELECT COUNT(*) FROM drawing_requests d WHERE d.system_id = vs.id) AS n_drawings
                FROM vessel_systems vs ORDER BY vs.is_active DESC, ' . (sys_has_codes() ? 'vs.code IS NULL, CAST(vs.code AS UNSIGNED), ' : '') . 'vs.sort_order, vs.name');
$nActive = count(array_filter($systems, fn($s) => (int)$s['is_active'] === 1));
layout_head(t('sys.title'), 'admin_systems');
?>
<?php settings_tabs('admin_systems'); ?>
<div class="page-head">
  <div><h2 class="settings-h"><?= tt('sys.title') ?></h2><p class="lede" style="margin-bottom:0;"><?= tt('sys.lede') ?></p></div>
  <a class="btn btn--ghost" href="<?= url('import') ?>#systems">⇪ <?= tt('sys.import') ?></a>
</div>
<?php if (!$codes): ?><div class="flash flash--warn"><?= tt('sys.need_r9') ?></div><?php endif; ?>
<?php if (function_exists('wf_has') && wf_has()): $op = wf_photo_opacity(); ?>
<div class="card card--tight" id="wf-opacity">
  <h2>🗺 <?= tt('wf.opacity_title') ?></h2>
  <p class="hint"><?= tt('wf.opacity_hint') ?></p>
  <form method="post" action="<?= url('admin_systems') ?>" class="btn-row" style="align-items:center;gap:14px;">
    <?= csrf_field() ?><input type="hidden" name="action" value="wf_opacity_save">
    <input type="range" name="opacity" min="10" max="100" step="5" value="<?= $op ?>" data-js-opacity-demo="1" style="width:260px;">
    <b id="wf-op-val" style="min-width:48px;"><?= $op ?> %</b>
    <span class="wf-op-demo"><img id="wf-op-demo" src="../assets/manual/g-decks-tool.jpg" alt="" style="opacity:<?= $op / 100 ?>;"><i class="wf-rect wf-cls--machinery" style="left:30%;top:25%;width:40%;height:45%;"><span>Engine room</span></i></span>
    <button class="btn btn--sm" type="submit"><?= tt('btn.save') ?></button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('sys.add') ?></h2>
  <form method="post" action="<?= url('admin_systems') ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="sys_save">
    <div class="sys-add">
      <?php if ($codes): ?>
        <div class="field"><label><?= tt('sys.code') ?></label><input name="code" maxlength="10" placeholder="10"></div>
        <div class="field"><label><?= tt('sys.abbr') ?></label><input name="abbr" maxlength="12" placeholder="MSB" style="text-transform:uppercase;"></div>
      <?php endif; ?>
      <div class="field"><label><?= tt('sys.name') ?></label><input name="name" required maxlength="120" placeholder="MAIN SWITCHBOARD"></div>
      <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" placeholder="auto"></div>
      <div class="field" style="display:flex;align-items:flex-end;"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
    </div>
  </form>
</div>

<div class="card">
  <h2><?= e(t('sys.count2', ['n' => count($systems), 'a' => $nActive])) ?></h2>
  <div class="table-scroll"><table class="sys-table">
    <thead><tr>
      <?php if ($codes): ?><th><?= tt('sys.code') ?></th><th><?= tt('sys.abbr') ?></th><?php endif; ?>
      <th><?= tt('sys.name') ?></th><th><?= tt('sys.order') ?></th><th><?= tt('sys.used') ?></th><th><?= tt('th.status') ?></th><th></th>
    </tr></thead>
    <tbody><?php foreach ($systems as $s): $fid = 'sysf' . (int)$s['id']; ?>
      <tr<?= $s['is_active'] ? '' : ' class="is-retired"' ?>>
        <?php if ($codes): ?>
          <td class="num"><input form="<?= $fid ?>" name="code" value="<?= e((string)$s['code']) ?>" maxlength="10" style="width:64px;"></td>
          <td><input form="<?= $fid ?>" name="abbr" value="<?= e((string)$s['abbr']) ?>" maxlength="12" style="width:84px;text-transform:uppercase;font-weight:600;"></td>
        <?php endif; ?>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($s['name']) ?>" maxlength="120" required></td>
        <td class="num"><input form="<?= $fid ?>" name="sort_order" type="number" min="0" value="<?= (int)$s['sort_order'] ?>" style="width:74px;"></td>
        <td class="hint"><?= e(t('sys.usage', ['s' => (int)$s['n_subs'], 'd' => (int)$s['n_drawings']])) ?></td>
        <td><?= $s['is_active'] ? pill('green', t('sys.active')) : pill('mute', t('sys.retired')) ?></td>
        <td class="row-actions">
          <form method="post" action="<?= url('admin_systems') ?>" id="<?= $fid ?>" style="display:contents;">
            <?= csrf_field() ?><input type="hidden" name="action" value="sys_save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          </form>
          <button form="<?= $fid ?>" class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button>
          <button form="<?= $fid ?>" class="btn btn--ghost btn--sm" type="submit" name="toggle" value="1"><?= $s['is_active'] ? tt('sys.retire') : tt('sys.restore') ?></button>
        </td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
</div>
<style>
  .sys-add { display:grid; grid-template-columns:<?= $codes ? '110px 130px 1fr 120px auto' : '1fr 120px auto' ?>; gap:14px; align-items:end; }
  .sys-table td input { min-width:0; width:100%; padding:7px 9px; border:1px solid var(--line); border-radius:7px; font:inherit; background:var(--field-bg); }
  .sys-table td input:focus { outline:none; border-color:var(--cp-gold); box-shadow:0 0 0 3px rgba(215,181,109,.25); }
  .sys-table th:nth-child(1), .sys-table th:nth-child(2) { white-space:nowrap; }
  .sys-table td:nth-child(3) { min-width:420px; }
  .sys-table td:nth-child(5) { white-space:nowrap; }
  .sys-table tr.is-retired td { opacity:.62; }
  @media (max-width:760px) { .sys-add { grid-template-columns:1fr 1fr; } }
</style>
<?php layout_foot(); ?>

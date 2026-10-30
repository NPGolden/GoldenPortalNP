<?php
/** Settings › Engineering sections — the sections and the discipline → section mapping that unifies Budget & actual hours with the Engineering tab (GAS-CP-R47-002). */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
if (!function_exists('eng_has') || !eng_has()) { layout_head(t('eng.settings'), 'admin_engineering'); settings_tabs('admin_engineering'); echo '<div class="flash flash--warn">' . tt('eng.need_r47') . '</div>'; layout_foot(); return; }
$secs = eng_all_sections(); $map = eng_sections();
$disc = all('SELECT d.*, (SELECT COUNT(*) FROM customer_users cu WHERE cu.discipline_id = d.id AND cu.is_active = 1) AS n_people FROM disciplines d ORDER BY d.is_active DESC, d.sort_order, d.name');
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
layout_head(t('eng.settings'), 'admin_engineering');
?>
<?php settings_tabs('admin_engineering'); ?>
<h2 class="settings-h">📐 <?= tt('eng.settings') ?></h2>
<p class="lede"><?= tt('eng.settings_lede') ?></p>

<div class="card">
  <h2><?= tt('eng.map_title') ?></h2>
  <p class="hint"><?= tt('eng.map_hint') ?></p>
  <form method="post" action="<?= url('admin_engineering') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_mapping_save">
    <?php $act = array_filter($secs, fn($x) => $x['is_active']); $mapOf = []; foreach (all('SELECT section_id, discipline_id FROM eng_section_disciplines') as $m) $mapOf[(int)$m['discipline_id']][(int)$m['section_id']] = true; ?>
    <div class="table-scroll"><table class="tbl eng-matrix"><thead><tr><th><?= tt('disc.name') ?></th><?php foreach (ENG_STAGES as $stg): $cols = array_filter($act, fn($x) => $x['stage'] === $stg); if (!$cols) continue; ?><th colspan="<?= count($cols) ?>" class="eng-matrix__st"><?= e(t('st.' . $stg)) ?></th><?php endforeach; ?></tr>
      <tr><th class="hint"><?= tt('disc.people') ?></th><?php foreach (ENG_STAGES as $stg): foreach (array_filter($act, fn($x) => $x['stage'] === $stg) as $sc): ?><th class="eng-matrix__c" title="<?= e($sc['name']) ?>"><?= e($sc['code']) ?></th><?php endforeach; endforeach; ?></tr></thead><tbody>
    <?php foreach ($disc as $d): ?><tr<?= $d['is_active'] ? '' : ' class="is-retired"' ?>>
      <td><b><?= e($d['name']) ?></b> <span class="hint"><?= (int)$d['n_people'] ?></span><?= $d['is_active'] ? '' : ' ' . pill('mute', t('sys.retired')) ?></td>
      <?php foreach (ENG_STAGES as $stg): foreach (array_filter($act, fn($x) => $x['stage'] === $stg) as $sc): ?><td class="eng-matrix__c"><input type="checkbox" name="map[<?= (int)$d['id'] ?>][<?= (int)$sc['id'] ?>]" value="1"<?= !empty($mapOf[(int)$d['id']][(int)$sc['id']]) ? ' checked' : '' ?> title="<?= e($d['name'] . ' → ' . $sc['name']) ?>"></td><?php endforeach; endforeach; ?>
    </tr><?php endforeach; ?>
    </tbody></table></div>
    <div class="btn-row" style="margin-top:8px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button><span class="hint"><?= tt('eng.map_save_hint') ?></span></div>
  </form>
</div>

<div class="card">
  <h2><?= tt('eng.sec_title') ?></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th style="width:80px;"><?= tt('sys.order') ?></th><th style="width:100px;"><?= tt('eng.sec_code') ?></th><th style="width:220px;"><?= tt('eng.section') ?></th><th style="width:170px;"><?= tt('eng.stage') ?></th><th><?= tt('eng.sec_mapped') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($secs as $s): $fid = 'esec' . (int)$s['id']; ?><tr<?= $s['is_active'] ? '' : ' class="is-retired"' ?>>
    <td><input form="<?= $fid ?>" name="sort_order" type="number" min="0" value="<?= (int)$s['sort_order'] ?>" style="width:70px;"></td>
    <td><input form="<?= $fid ?>" name="code" value="<?= e($s['code']) ?>" maxlength="12" required style="width:80px;font-family:var(--mono);text-transform:uppercase;"></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= e($s['name']) ?>" maxlength="80" required></td>
    <td><select form="<?= $fid ?>" name="stage" data-nosearch><?php foreach (ENG_STAGES as $stg): ?><option value="<?= $stg ?>"<?= $sel($stg, $s['stage']) ?>><?= e(t('st.' . $stg)) ?></option><?php endforeach; ?></select></td>
    <td class="hint"><?= isset($map[(int)$s['id']]) ? e(implode(', ', $map[(int)$s['id']]['disciplines'])) ?: '—' : '—' ?></td>
    <td class="row-actions"><form method="post" action="<?= url('admin_engineering') ?>" id="<?= $fid ?>" style="display:contents;"><?= csrf_field() ?><input type="hidden" name="action" value="eng_sections_save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"></form>
      <button class="btn btn--ghost btn--sm" form="<?= $fid ?>" type="submit"><?= tt('btn.save') ?></button>
      <form method="post" action="<?= url('admin_engineering') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="eng_sections_save"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="toggle" value="1"><button class="btn btn--ghost btn--sm" type="submit"><?= $s['is_active'] ? tt('sys.retire') : tt('sys.restore') ?></button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" action="<?= url('admin_engineering') ?>" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="eng_sections_save">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('eng.sec_code') ?></label><input name="code" required maxlength="12" placeholder="HVAC" style="text-transform:uppercase;"></div>
      <div class="field"><label><?= tt('eng.section') ?></label><input name="name" required maxlength="80" placeholder="HVAC"></div>
      <div class="field"><label><?= tt('eng.stage') ?></label><select name="stage" data-nosearch><?php foreach (ENG_STAGES as $stg): ?><option value="<?= $stg ?>"><?= e(t('st.' . $stg)) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="display:flex;gap:10px;align-items:flex-end;"><div style="flex:1;"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" value="<?= (count($secs) + 1) * 10 ?>" style="width:90px;"></div><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('eng.sec_add') ?></button></div>
    </div>
  </form>
  <p class="hint" style="margin:8px 0 0;"><?= tt('eng.sec_hint') ?></p>
</div>
<?php layout_foot(); ?>

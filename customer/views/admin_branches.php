<?php
/** Settings › Branches — the companies of the group that issue documents: identity, document prefix, offer series,
 *  default calendar, logo variant (GAS-2026-PORTAL-BRN-001 · GAS-CP-R24-001).   ?p=admin_branches[&edit=ID|&new=1] */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$ok = br_has();
$editId = (int)($_GET['edit'] ?? 0); $isNew = isset($_GET['new']);
$ed = $editId ? (br_all()[$editId] ?? null) : null;
if ($editId && !$ed) $editId = 0;
$cals = function_exists('wc_has') && wc_has() ? wc_calendars() : [];
$sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
$variants = ['default']; foreach (glob(dirname(__DIR__, 2) . '/assets/golden-logo-*.png') ?: [] as $f) { $v = substr(basename($f, '.png'), 12); if ($v !== '' && !str_starts_with($v, 'white')) $variants[] = $v; }
layout_head(t('br.title'), 'admin_branches');
?>
<?php settings_tabs('admin_branches'); ?>
<h2 class="settings-h"><?= tt('br.title') ?></h2>
<p class="lede"><?= tt('br.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('br.need_db') ?></div><?php else: ?>

<div class="card">
  <div class="page-head" style="margin-bottom:8px;"><h2 style="margin:0;"><?= tt('br.list') ?></h2><a class="btn btn--gold btn--sm" href="<?= url('admin_branches', ['new' => 1]) ?>#brform">+ <?= tt('br.add') ?></a></div>
  <div class="table-scroll"><table class="br-table">
    <thead><tr><th><?= tt('br.code') ?></th><th><?= tt('br.name') ?></th><th><?= tt('br.legal_name') ?></th><th><?= tt('br.vat_no') ?></th><th><?= tt('br.doc_prefix') ?></th><th><?= tt('br.offer_series') ?></th><?php if (br_has_cost()): ?><th class="num"><?= tt('br.manhour_cost') ?></th><?php endif; ?><th><?= tt('wc.one') ?></th><th><?= tt('br.usage') ?></th><th></th><th></th></tr></thead>
    <tbody><?php foreach (br_all() as $b): $use = br_usage((int)$b['id']); ?>
      <tr class="<?= $b['is_active'] ? '' : 'is-off' ?>">
        <td><?= br_pill($b, true) ?></td>
        <td><b><?= e($b['name']) ?></b><?= $b['is_default'] ? ' <span class="hint">· ' . tt('br.default') . '</span>' : '' ?></td>
        <td><?= e($b['legal_name']) ?><?= $b['address'] ? '<br><span class="hint">' . e($b['address']) . '</span>' : '' ?></td>
        <td class="num"><?= e($b['vat_no'] ?: '—') ?><?= $b['registry'] ? '<br><span class="hint">' . e($b['registry']) . '</span>' : '' ?></td>
        <td class="num"><?= e($b['doc_prefix']) ?>-…</td>
        <td class="num"><?= e(br_offer_series($b)) ?>-YYYY-NNN</td>
        <?php if (br_has_cost()): ?><td class="num"><?= number_format(br_manhour_cost_of($b), 2, ',', '.') ?> €/h</td><?php endif; ?>
        <td><?= e($b['work_calendar'] ?: '—') ?></td>
        <td class="num"><?= e(t('br.usage_n', ['p' => $use['projects'], 's' => $use['subprojects'], 'u' => $use['users']])) ?></td>
        <td><?= $b['is_active'] ? pill('green', t('sys.active')) : pill('mute', t('br.hidden')) ?></td>
        <td class="row-actions" style="white-space:nowrap;"><a class="btn btn--ghost btn--sm" href="<?= url('admin_branches', ['edit' => (int)$b['id']]) ?>#brform">✎</a>
          <?php if (!$b['is_default'] && !array_sum($use)): ?><form method="post" action="<?= url('admin_branches') ?>" style="display:inline;" data-confirm="<?= e(t('br.delete_confirm', ['name' => $b['name']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger data-lock><?= csrf_field() ?><input type="hidden" name="action" value="br_delete"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="btn btn--ghost btn--sm btn--danger-ghost" type="submit">✕</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <p class="hint" style="margin-top:8px;"><?= tt('br.list_hint') ?></p>
</div>

<?php if ($ed || $isNew): $v = fn(string $k, $d = '') => e((string)($ed[$k] ?? $d)); ?>
<div class="card" id="brform">
  <h2><?= $ed ? e(t('br.edit', ['name' => $ed['name']])) : tt('br.add') ?></h2>
  <form method="post" action="<?= url('admin_branches') ?>" enctype="multipart/form-data" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="br_save"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('br.code') ?></label><input name="code" required maxlength="8" value="<?= $v('code') ?>" placeholder="IT" style="text-transform:uppercase;"></div>
      <div class="field"><label><?= tt('br.name') ?></label><input name="name" required maxlength="80" value="<?= $v('name') ?>" placeholder="Italy · branch"></div>
      <div class="field"><label><?= tt('br.legal_name') ?></label><input name="legal_name" required maxlength="200" value="<?= $v('legal_name') ?>" placeholder="GOLDEN AS ITALIAN"><p class="hint"><?= tt('br.legal_hint') ?></p></div>
      <div class="field"><label><?= tt('br.trade_name') ?></label><input name="trade_name" maxlength="120" value="<?= $v('trade_name') ?>" placeholder="Golden A/S Italian Branch"><p class="hint"><?= tt('br.trade_hint') ?></p></div>
      <div class="field"><label><?= tt('br.address') ?></label><input name="address" maxlength="300" value="<?= $v('address') ?>" placeholder="Piazza Cavalier Pietro Lunardi 91 · 55054 Massarosa (LU) · Italy"></div>
      <div class="field"><label><?= tt('br.country') ?></label><input name="country" maxlength="2" value="<?= $v('country', 'GR') ?>" style="text-transform:uppercase;"></div>
      <div class="field"><label><?= tt('br.vat_no') ?></label><input name="vat_no" maxlength="30" value="<?= $v('vat_no') ?>" placeholder="IT02768900462"></div>
      <div class="field"><label><?= tt('br.registry') ?></label><input name="registry" maxlength="160" value="<?= $v('registry') ?>" placeholder="Camera di Commercio Toscana Nord-Ovest"></div>
      <div class="field"><label><?= tt('ct.ch_email') ?></label><input name="email" type="email" maxlength="190" value="<?= $v('email') ?>"></div>
      <div class="field"><label><?= tt('ct.phone') ?></label><input name="phone" maxlength="60" value="<?= $v('phone') ?>"></div>
      <div class="field"><label><?= tt('br.doc_prefix') ?></label><input name="doc_prefix" required maxlength="8" value="<?= $v('doc_prefix', 'GAS') ?>" style="text-transform:uppercase;"><p class="hint"><?= tt('br.prefix_hint') ?></p></div>
      <div class="field"><label><?= tt('br.offer_series') ?></label><input name="offer_series" maxlength="16" value="<?= $v('offer_series') ?>" placeholder="GIT-OF" style="text-transform:uppercase;"><p class="hint"><?= tt('br.series_hint') ?></p></div>
      <?php if (br_has_cost()): ?><div class="field"><label><?= tt('br.manhour_cost') ?></label><input name="manhour_cost" type="number" step="0.01" min="1" max="9999" value="<?= e(number_format(br_manhour_cost_of($ed ?? []), 2, '.', '')) ?>"><p class="hint"><?= tt('br.manhour_cost_hint') ?></p></div><?php endif; ?>
      <div class="field"><label><?= tt('wc.one') ?></label><select name="work_calendar"><option value="">—</option><?php foreach ($cals as $c => $row): ?><option value="<?= e($c) ?>"<?= $sel($c, $ed['work_calendar'] ?? '') ?>><?= e($row['code'] . ' · ' . $row['name']) ?></option><?php endforeach; ?></select></div>
      <?php if (function_exists('acc_has') && acc_has()): ?><div class="field"><label><?= tt('br.accounting_email') ?></label><input name="accounting_email" type="email" maxlength="190" value="<?= $v('accounting_email') ?>" placeholder="accounting@goldenas.com.gr"><p class="hint"><?= tt('br.accounting_email_hint') ?></p></div><?php endif; ?>
      <div class="field"><label><?= tt('br.currency') ?></label><input name="currency" maxlength="3" value="<?= $v('currency', 'EUR') ?>" style="text-transform:uppercase;"></div>
      <?php if ($ed): $up = br_logo_file((int)$ed['id']); ?>
      <div class="field" style="grid-column: 1 / -1;"><label><?= tt('br.logo_upload') ?></label>
        <div class="br-logo-row"><img src="<?= url('brlogo', ['id' => (int)$ed['id'], '_r' => (string) filemtime($up ?: br_doc(null, null, $ed)['logo'])]) ?>" alt="" class="br-logo-prev">
          <div><input type="file" name="logo_file" accept="image/png,image/jpeg"><p class="hint" style="margin:4px 0 0;"><?= tt('br.logo_upload_hint') ?></p>
            <?php if ($up): ?><label class="chk" style="margin-top:6px;"><input type="checkbox" name="logo_remove" value="1"> <?= tt('br.logo_remove') ?></label><p class="hint"><?= e(t('br.logo_current', ['f' => basename($up)])) ?></p><?php else: ?><p class="hint"><?= tt('br.logo_builtin') ?></p><?php endif; ?></div></div></div>
      <?php endif; ?>
      <div class="field"><label><?= tt('br.logo') ?></label><select name="logo_variant"><?php foreach ($variants as $lv): ?><option value="<?= e($lv) ?>"<?= $sel($lv, $ed['logo_variant'] ?? 'default') ?>><?= $lv === 'default' ? e(t('br.logo_default')) : e('golden-logo-' . $lv . '.png') ?></option><?php endforeach; ?></select><p class="hint"><?= tt('br.logo_hint') ?></p></div>
      <div class="field"><label><?= tt('sys.order') ?></label><input name="sort_order" type="number" min="0" value="<?= (int)($ed['sort_order'] ?? 10) ?>">
        <label class="chk" style="margin-top:8px;"><input type="checkbox" name="is_active" value="1"<?= ($ed['is_active'] ?? 1) ? ' checked' : '' ?>> <?= tt('sys.active') ?></label>
        <label class="chk"><input type="checkbox" name="is_default" value="1"<?= !empty($ed['is_default']) ? ' checked' : '' ?>> <?= tt('br.make_default') ?></label></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= url('admin_branches') ?>"><?= tt('btn.cancel') ?></a></div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('br.how_title') ?></h2>
  <ol class="hint" style="margin:0 0 0 18px;line-height:1.7;">
    <li><?= tt('br.how_1') ?></li><li><?= tt('br.how_2') ?></li><li><?= tt('br.how_3') ?></li><li><?= tt('br.how_4') ?></li>
  </ol>
</div>
<?php endif; ?>
<style>.br-logo-row { display: flex; gap: 16px; align-items: center; } .br-logo-prev { height: 56px; width: auto; max-width: 220px; padding: 6px 10px; border: 1px solid var(--line); border-radius: 8px; background: #fff; } .br-table td:nth-child(3) { min-width: 300px; } .br-table td:nth-child(2) { white-space: nowrap; } .br-table td:nth-child(6), .br-table td:nth-child(8) { white-space: nowrap; font-size: 12px; } .br-table .pill { white-space: nowrap; }</style>
<?php layout_foot(); ?>

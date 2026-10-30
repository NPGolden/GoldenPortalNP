<?php
/** Settings › Lists & categories › Rate cards — the price list per branch and revision, imported from the master file.   GAS-CP-R33-001 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin(); of_require();
$ok = rl_has();
$lists = $ok ? rl_lists() : [];
$lid = (int)($_GET['list'] ?? 0); if (!$lid && $lists) foreach ($lists as $l) if ($l['status'] === 'active') { $lid = (int)$l['id']; break; }
$cur = $lid ? rl_get($lid) : null;
$sec = in_array($_GET['sec'] ?? '', RL_SECTIONS, true) ? $_GET['sec'] : 'hourly';
$card = isset($_GET['card']) ? (string)$_GET['card'] : null;
$items = $cur ? rl_items($lid, $sec, $card !== null && $card !== '' ? $card : null) : [];
$cards = $cur ? rl_cards($lid) : [];
$res = $_SESSION['rl_import_result'] ?? null; unset($_SESSION['rl_import_result']);
$money = fn($v) => $v === null ? '—' : number_format((float)$v, 2, ',', '.');
layout_head(t('rl.title'), 'admin_rate_cards');
?>
<?php settings_tabs('admin_rate_cards'); ?>
<h2 class="settings-h"><?= tt('rl.title') ?></h2>
<p class="lede"><?= tt('rl.lede') ?></p>
<?php if (!$ok): ?><div class="flash flash--warn"><?= tt('rl.need_db') ?></div><?php else: ?>
<?php if ($res): ?><div class="flash flash--<?= $res['dry'] ? 'warn' : 'good' ?>"><b><?= e($res['doc_ref'] . ' ' . $res['revision']) ?></b> · <?= e(fmt_date($res['rev_date'])) ?> — <?php foreach ($res['branches'] as $b => $n): ?><?= e($b) ?>: <?= (int)$n ?> <?= tt('rl.rows') ?> · <?php endforeach; ?><?= $res['dry'] ? tt('rl.dry_note') : e(t('rl.imported_n', ['n' => (int)$res['items']])) ?><?php if ($res['skipped']): ?><br><span class="hint"><?= e(implode(' · ', array_map(fn($s) => str_starts_with($s, 'rl.') ? t($s) : $s, $res['skipped']))) ?></span><?php endif; ?></div><?php endif; ?>

<div class="cols">
  <div class="card">
    <h2><?= tt('rl.import') ?></h2>
    <p class="hint"><?= tt('rl.import_hint') ?></p>
    <form method="post" action="<?= url('admin_rate_cards') ?>" enctype="multipart/form-data" data-lock data-busy="<?= tt('im.working') ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="rl_import">
      <div class="field"><label><?= tt('im.file') ?></label><input type="file" name="xlsx" accept=".xlsx" required></div>
      <div class="btn-row"><button class="btn btn--ghost" type="submit" name="dry" value="1"><?= tt('im.dry') ?></button><button class="btn btn--gold" type="submit"><?= tt('rl.import_btn') ?></button></div>
    </form>
  </div>
  <div class="card">
    <h2><?= count($lists) ?> <?= tt('rl.lists') ?></h2>
    <?php if (!$lists): ?><p class="hint"><?= tt('rl.none') ?></p><?php else: ?>
    <table class="tbl"><thead><tr><th><?= tt('rl.doc') ?></th><th><?= tt('sub.rev') ?></th><th><?= tt('th.date') ?></th><th><?= tt('et.branch') ?></th><th><?= tt('rl.items') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($lists as $l): ?><tr<?= (int)$l['id'] === $lid ? ' class="is-on"' : '' ?>>
      <td><a href="<?= url('admin_rate_cards', ['list' => $l['id']]) ?>"><b><?= e($l['doc_ref']) ?></b></a></td><td><?= e($l['revision']) ?></td><td><?= e(fmt_date($l['rev_date'])) ?></td><td><?= e($l['branch_code']) ?></td><td><?= (int)$l['n_items'] ?></td>
      <td><?= $l['status'] === 'active' ? pill('green', t('rl.active')) : pill('mute', t('rl.superseded')) ?></td>
      <td style="text-align:right;white-space:nowrap;"><form method="post" action="<?= url('admin_rate_cards') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rl_status"><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'superseded' : 'active' ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $l['status'] === 'active' ? tt('rl.retire') : tt('rl.activate') ?></button></form>
        <form method="post" action="<?= url('admin_rate_cards') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rl_delete"><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= tt('rl.delete_confirm') ?>">×</button></form></td>
    </tr><?php endforeach; ?>
    </tbody></table>
    <?php endif; ?>
    <p class="hint" style="margin:8px 0 0;"><?= tt('rl.lists_hint') ?></p>
  </div>
</div>

<?php if ($cur): ?>
<div class="card">
  <div class="card-head"><h2><?= e($cur['doc_ref'] . ' ' . $cur['revision']) ?> · <?= e($cur['branch_code']) ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(fmt_date($cur['rev_date'])) ?> · <?= e($cur['currency']) ?><?= $cur['source_name'] ? ' · ' . e($cur['source_name']) : '' ?></span></h2></div>
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: auto 1fr 1.4fr auto;">
    <input type="hidden" name="p" value="admin_rate_cards"><input type="hidden" name="list" value="<?= $lid ?>">
    <div class="field"><label><?= tt('rl.section') ?></label><select name="sec" data-nosearch data-js-submit="1"><?php foreach (RL_SECTIONS as $s): ?><option value="<?= $s ?>"<?= $sec === $s ? ' selected' : '' ?>><?= e(rl_section_label($s)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('rl.card') ?></label><select name="card" data-nosearch data-js-submit="1"><option value=""><?= tt('rl.all_cards') ?></option><?php foreach ($cards as $k => $c): if ($c['section'] !== $sec) continue; ?><option value="<?= e($c['card']) ?>"<?= $card === $c['card'] ? ' selected' : '' ?>><?= e(($c['card'] !== '' ? $c['card'] . ' · ' : '') . ($c['name'] ?: rl_section_label($c['section']))) ?></option><?php endforeach; ?></select></div>
    <div></div><div class="fltbar-actions"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.apply') ?></button></div>
  </form>
  <table class="tbl"><thead><tr><th><?= tt('rl.card') ?></th><th><?= tt('ob.c_code') ?></th><th><?= tt('ob.c_desc') ?></th><th><?= tt('ob.c_unit') ?></th><th class="num"><?= tt('rl.local') ?></th><th class="num"><?= tt('rl.overseas') ?></th><th><?= tt('rl.rule') ?></th></tr></thead><tbody>
  <?php foreach ($items as $it): ?><tr><td><?= e(trim($it['card'] . ' ' . $it['card_name'])) ?></td><td class="mono"><?= e($it['erp_code']) ?></td><td><?= e($it['description']) ?></td><td><?= e($it['unit']) ?></td><td class="num"><?= $money($it['price_local']) ?></td><td class="num"><?= $money($it['price_overseas']) ?></td><td class="hint"><?= e($it['rule'] ?? '') ?></td></tr><?php endforeach; ?>
  <?php if (!$items): ?><tr><td colspan="7" class="hint"><?= tt('rl.no_items') ?></td></tr><?php endif; ?>
  </tbody></table>
</div>
<?php endif; ?>
<?php endif; ?>
<?php layout_foot(); ?>

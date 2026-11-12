<?php
/** Commercial › Web Sales › Marine intelligence (GAS-CP-R97-027): the week's headlines in five groups, read from public news feeds. ?p=marine_intel[&w=<weeks back>] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('mi_has') || !mi_has()) { http_response_code(404); layout_head(t('mi.title'), 'web_sales'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
mi_seed(); $w = max(0, min(25, (int)($_GET['w'] ?? 0))); [$from, $to, $wk, $yr, $mon] = mi_week_bounds($w); $man = mi_can_manage(); $adm = is_cp_admin();
$G = mi_items($from, $to, $man); $total = array_sum(array_map('count', $G)); $self = url('marine_intel', ['w' => $w]);
$feeds = all('SELECT * FROM mi_feeds ORDER BY is_active DESC, (grp IS NULL), name'); $lastOk = (string) scalar('SELECT MAX(last_ok_at) FROM mi_feeds');
$ico = ['newbuild' => '🛥', 'refit' => '🛠', 'ess' => '🔋', 'hybrid' => '⚡', 'tech' => '📡'];
layout_head(t('mi.title'), 'web_sales');
web_sales_tabs('marine_intel');
?>
<div class="page-head">
  <div><h1>🧭 <?= tt('mi.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('mi.lede') ?></p></div>
  <div class="btn-row"><?php if ($man): ?><form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="mi_fetch"><input type="hidden" name="w" value="<?= $w ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('mi.fetch_hint') ?>">↻ <?= tt('mi.fetch') ?></button></form><?php endif; ?></div>
</div>
<div class="fltbar" style="grid-template-columns: auto 1fr auto;align-items:center;">
  <div class="btn-row" style="margin:0;"><a class="btn btn--ghost btn--sm" href="<?= url('marine_intel', ['w' => $w + 1]) ?>">‹ <?= tt('mi.older') ?></a><?php if ($w > 0): ?><a class="btn btn--ghost btn--sm" href="<?= url('marine_intel', ['w' => $w - 1]) ?>"><?= tt('mi.newer') ?> ›</a><a class="btn btn--ghost btn--sm" href="<?= url('marine_intel') ?>"><?= tt('mi.this_week') ?></a><?php endif; ?></div>
  <div><b><?= e(t('mi.week', ['w' => $wk, 'y' => $yr])) ?></b> <span class="hint"><?= e(fmt_date(gmdate('Y-m-d', $mon))) ?> – <?= e(fmt_date(gmdate('Y-m-d', $mon + 6 * 86400))) ?> · <?= e(t('mi.count', ['n' => $total])) ?></span></div>
  <div class="hint"><?= $lastOk !== '' ? e(t('mi.read_at', ['d' => fmt_datetime($lastOk)])) : tt('mi.never') ?></div>
</div>
<?php if (!$total): ?><p class="flash flash--warn"><?= tt($lastOk === '' ? 'mi.empty_first' : 'mi.empty') ?></p><?php endif; ?>
<div class="grid grid-2" style="align-items:start;">
<?php foreach (MI_GROUPS as $g): $rows = $G[$g]; ?>
<div class="card" id="g-<?= $g ?>"<?= $g === 'tech' ? ' style="grid-column:1 / -1;"' : '' ?>>
  <div class="card-head"><h2><?= $ico[$g] ?> <?= tt('mi.g_' . $g) ?> <span class="count"><?= count(array_filter($rows, fn($r) => !(int)$r['hidden'])) ?></span></h2></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('mi.none') ?></p><?php else: ?><ul class="mi-list">
  <?php foreach ($rows as $r): $hid = (int)$r['hidden']; ?><li<?= $hid ? ' class="is-hidden"' : '' ?>>
    <a class="mi-t" href="<?= e((string)$r['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e((string)$r['title']) ?> ↗</a>
    <div class="hint"><?= e((string)$r['source']) ?> · <?= e(fmt_date(substr((string)$r['published_at'], 0, 10))) ?><?= $r['added_by'] ? ' · ' . tt('mi.by_hand') : '' ?></div>
    <?php if ($r['summary']): ?><p class="mi-s"><?= e((string)$r['summary']) ?></p><?php endif; ?>
    <?php if ($man): ?><div class="mi-a">
      <form method="post" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mi_hide"><input type="hidden" name="w" value="<?= $w ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if ($hid): ?><input type="hidden" name="show" value="1"><?php endif; ?><button class="linkbtn" type="submit"><?= tt($hid ? 'mi.show' : 'mi.hide') ?></button></form>
      <form method="post" action="<?= $self ?>"><?= csrf_field() ?><input type="hidden" name="action" value="mi_move"><input type="hidden" name="w" value="<?= $w ?>"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><select name="grp" data-nosearch data-autosubmit aria-label="<?= tt('mi.move') ?>"><?php foreach (MI_GROUPS as $g2): ?><option value="<?= $g2 ?>"<?= $g2 === $g ? ' selected' : '' ?>><?= tt('mi.s_' . $g2) ?></option><?php endforeach; ?></select><button class="linkbtn" type="submit"><?= tt('mi.move') ?></button></form>
    </div><?php endif; ?>
  </li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php if ($man): ?>
<details class="card" id="add"><summary class="btn btn--ghost btn--sm">＋ <?= tt('mi.add') ?></summary>
  <form method="post" action="<?= $self ?>" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="mi_add"><input type="hidden" name="w" value="0">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('mi.f_title') ?> *</label><input name="title" maxlength="300" required></div>
      <div class="field"><label><?= tt('mi.f_url') ?> *</label><input name="url" type="url" maxlength="1000" required placeholder="https://…"></div>
      <div class="field"><label><?= tt('mi.f_group') ?> *</label><select name="grp" data-nosearch><?php foreach (MI_GROUPS as $g2): ?><option value="<?= $g2 ?>"><?= tt('mi.g_' . $g2) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('mi.f_source') ?></label><input name="source" maxlength="120"></div>
      <div class="field" style="grid-column:1 / -1;"><label><?= tt('mi.f_summary') ?></label><input name="summary" maxlength="600" data-nort></div>
    </div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('mi.add_btn') ?></button></div>
  </form>
</details>
<?php endif; ?>
<?php if ($adm): ?>
<details class="card" id="feeds"<?= array_filter($feeds, fn($f) => $f['last_error']) ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm">⚙ <?= tt('mi.feeds') ?> <span class="count"><?= count($feeds) ?></span></summary>
  <p class="hint" style="margin:12px 0 8px;"><?= tt('mi.feeds_hint') ?></p>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('mi.fd_name') ?></th><th><?= tt('mi.f_group') ?></th><th><?= tt('mi.fd_last') ?></th><th class="num"><?= tt('mi.fd_new') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($feeds as $f): ?><tr<?= (int)$f['is_active'] ? '' : ' class="hint"' ?>>
    <td><b><?= e((string)$f['name']) ?></b><br><span class="hint" style="word-break:break-all;"><?= e(mb_strimwidth((string)$f['url'], 0, 110, '…')) ?></span></td>
    <td><?= $f['grp'] ? tt('mi.s_' . $f['grp']) : '<span class="hint">' . tt($f['kind'] === 'marine' ? 'mi.fd_words_marine' : 'mi.fd_words') . '</span>' ?></td>
    <td class="hint"><?= $f['last_run_at'] ? e(fmt_datetime((string)$f['last_run_at'])) : '—' ?></td><td class="num"><?= (int)$f['last_new'] ?></td>
    <td><?= !(int)$f['is_active'] ? pill('mute', t('mi.fd_off')) : ($f['last_error'] ? pill('red', t('mi.fd_err')) . '<br><span class="hint">' . e((string)$f['last_error']) . '</span>' : ($f['last_ok_at'] ? pill('green', t('mi.fd_ok')) : pill('gold', t('mi.fd_wait')))) ?></td>
    <td style="white-space:nowrap;"><form method="post" action="<?= $self ?>" style="display:inline;"><?= csrf_field() ?><input type="hidden" name="action" value="mi_feed_toggle"><input type="hidden" name="w" value="<?= $w ?>"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="linkbtn" type="submit"><?= tt((int)$f['is_active'] ? 'mi.fd_pause' : 'mi.fd_resume') ?></button></form>
      <form method="post" action="<?= $self ?>" style="display:inline;" data-confirm="<?= tt('mi.fd_del_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="mi_feed_delete"><input type="hidden" name="w" value="<?= $w ?>"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="linkbtn" type="submit">🗑</button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <form method="post" action="<?= $self ?>" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="mi_feed_add"><input type="hidden" name="w" value="<?= $w ?>">
    <div class="fltbar" style="grid-template-columns: 1.2fr 2fr 1fr 1fr auto;margin:0;">
      <div class="field"><label><?= tt('mi.fd_name') ?> *</label><input name="name" maxlength="120" required></div>
      <div class="field"><label><?= tt('mi.fd_url') ?> *</label><input name="url" type="url" maxlength="700" required placeholder="https://…/feed"></div>
      <div class="field"><label><?= tt('mi.f_group') ?></label><select name="grp" data-nosearch><option value=""><?= tt('mi.fd_by_words') ?></option><?php foreach (MI_GROUPS as $g2): ?><option value="<?= $g2 ?>"><?= tt('mi.s_' . $g2) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('mi.fd_kind') ?></label><select name="kind" data-nosearch><option value="yacht"><?= tt('mi.fd_kind_yacht') ?></option><option value="marine"><?= tt('mi.fd_kind_marine') ?></option></select></div>
      <div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit">＋ <?= tt('mi.fd_add') ?></button></div>
    </div>
  </form>
</details>
<?php endif; ?>
<?php layout_foot(); ?>

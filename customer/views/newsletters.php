<?php
/** Commercial › Web Sales › Newsletters (GAS-CP-R97-039): issues, website subscribers, unsubscribed / bounced addresses, sending settings. ?p=newsletters[&tab=subs|optout|settings] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('nl_can') || !nl_can()) { http_response_code(403); layout_head(t('nl.title'), 'web_sales'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$tab = in_array($_GET['tab'] ?? '', ['subs', 'optout', 'settings'], true) ? (string)$_GET['tab'] : 'issues'; $self = url('newsletters'); $send = nl_can_send();
$rows = $tab === 'issues' ? nl_issues() : []; $subs = $tab === 'subs' ? nl_subs() : []; $opt = $tab === 'optout' ? nl_optouts() : [];
$nSub = (int) scalar("SELECT COUNT(*) FROM nl_subs WHERE status = 'confirmed'"); $nOpt = (int) scalar('SELECT COUNT(*) FROM nl_optout'); $today = (int) scalar("SELECT COUNT(*) FROM nl_sends WHERE status = 'sent' AND sent_at >= CURDATE()");
layout_head(t('nl.title'), 'web_sales');
web_sales_tabs('newsletters');
?>
<div class="page-head"><div><h1>✉ <?= tt('nl.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('nl.lede') ?></p></div></div>
<?php if (!nl_ready()): ?><p class="flash flash--bad"><?= tt('nl.no_mailer') ?></p><?php endif; ?>
<div class="tabs" style="margin:6px 0 12px;"><?php foreach (['issues' => [t('nl.t_issues'), 0], 'subs' => [t('nl.t_subs'), $nSub], 'optout' => [t('nl.t_optout'), $nOpt], 'settings' => [t('nl.t_settings'), 0]] as $k => [$l, $n]): ?><a href="<?= url('newsletters', $k === 'issues' ? [] : ['tab' => $k]) ?>"<?= $k === $tab ? ' class="is-on"' : '' ?>><?= e($l) ?><?= $n ? '<span class="n">' . (int)$n . '</span>' : '' ?></a><?php endforeach; ?></div>
<?php if ($tab === 'issues'): ?>
<div class="card">
  <form method="post" action="<?= $self ?>" class="filters" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_create">
    <div class="field" style="flex:1;min-width:280px;"><label><?= tt('nl.new') ?></label><input name="title" maxlength="200" required placeholder="<?= tt('nl.title_ph') ?>"></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('nl.create') ?></button></div>
  </form>
</div>
<div class="card">
  <div class="card-head"><h2><?= tt('nl.t_issues') ?> <span class="count"><?= count($rows) ?></span></h2><span class="hint"><?= e(t('nl.today', ['n' => $today, 'm' => nl_daily()])) ?></span></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('nl.c_title') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('nl.c_rcpt') ?></th><th class="num"><?= tt('nl.c_sent') ?></th><th class="num"><?= tt('nl.c_open') ?></th><th class="num"><?= tt('nl.c_click') ?></th><th class="num"><?= tt('nl.c_unsub') ?></th><th><?= tt('nl.c_when') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $ns = (int)$r['n_sent']; ?><tr><td><a href="<?= url('newsletter', ['id' => (int)$r['id']]) ?>"><b><?= e($r['title']) ?></b></a><br><span class="hint"><?= e($r['subject']) ?> · <?= e(strtoupper((string)$r['lang'])) ?></span></td><td><?= nl_pill((string)$r['status']) ?></td>
    <td class="num"><?= (int)$r['n_rcpt'] ?: '—' ?></td><td class="num"><?= $ns ?: '—' ?></td><td class="num"><?= $ns ? (int)$r['n_open'] . ' <span class="hint">' . e(nl_pct((int)$r['n_open'], $ns)) . '</span>' : '—' ?></td><td class="num"><?= $ns ? (int)$r['n_click'] . ' <span class="hint">' . e(nl_pct((int)$r['n_click'], $ns)) . '</span>' : '—' ?></td><td class="num"><?= (int)$r['n_unsub'] ?: '—' ?></td>
    <td class="date hint"><?= e(fmt_date(substr((string)($r['sent_at'] ?: $r['queued_at'] ?: $r['created_at']), 0, 10))) ?><br><?= e((string)($r['queued_name'] ?: $r['created_name'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php elseif ($tab === 'subs'): ?>
<div class="card"><p class="hint" style="margin:0 0 8px;"><?= tt('nl.subs_hint') ?></p>
  <?php if (!$subs): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('au.email') ?></th><th><?= tt('nl.c_name') ?></th><th><?= tt('nl.c_kind') ?></th><th><?= tt('nl.c_topics') ?></th><th><?= tt('th.status') ?></th><th><?= tt('nl.c_when') ?></th></tr></thead><tbody>
  <?php foreach ($subs as $s): ?><tr><td><?= e($s['email']) ?></td><td><?= e((string)$s['name']) ?><?= $s['company'] ? '<br><span class="hint">' . e($s['company']) . '</span>' : '' ?></td><td class="hint"><?= e((string)$s['kind']) ?></td><td class="hint"><?= e(str_replace(',', ', ', (string)$s['topics'])) ?></td><td><?= pill(['pending' => 'gold', 'confirmed' => 'green', 'unsubscribed' => 'mute'][$s['status']] ?? 'mute', t('nl.ss_' . $s['status'])) ?></td><td class="date hint"><?= e(fmt_date(substr((string)($s['confirmed_at'] ?: $s['created_at']), 0, 10))) ?><br><?= e((string)$s['source']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></div>
<?php elseif ($tab === 'optout'): ?>
<div class="card"><p class="hint" style="margin:0 0 8px;"><?= tt('nl.optout_hint') ?></p>
  <?php if ($send): ?><form method="post" action="<?= $self ?>" class="filters" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_bounce"><div class="field"><label><?= tt('nl.bounce_add') ?></label><input name="email" type="email" maxlength="190" required></div><div class="field"><label>&nbsp;</label><button class="btn btn--sm" type="submit"><?= tt('nl.bounce_btn') ?></button></div></form><?php endif; ?>
  <?php if (!$opt): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('au.email') ?></th><th><?= tt('nl.c_reason') ?></th><th><?= tt('nl.c_when') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($opt as $o): ?><tr><td><?= e($o['email']) ?></td><td><?= pill($o['reason'] === 'bounce' ? 'bad' : 'mute', t('nl.r_' . $o['reason'])) ?></td><td class="date hint"><?= e(fmt_datetime($o['at'])) ?></td><td><?php if ($send): ?><form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= tt('nl.restore_q') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nl_optin"><input type="hidden" name="email" value="<?= e($o['email']) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('nl.restore') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?></div>
<?php else: ?>
<div class="card"><h2>⚙ <?= tt('nl.t_settings') ?></h2><p class="hint"><?= tt('nl.set_hint') ?></p>
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_settings">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('nl.set_from') ?></label><input name="nl_from" type="email" maxlength="190" value="<?= e((string) cp_setting('nl_from', '')) ?>" placeholder="<?= e((string) cfg('graph.from')) ?>"<?= $send ? '' : ' readonly' ?>><p class="hint"><?= tt('nl.set_from_hint') ?></p></div>
      <div class="field"><label><?= tt('nl.set_batch') ?></label><input name="nl_batch" type="number" min="1" max="50" value="<?= nl_batch() ?>"<?= $send ? '' : ' readonly' ?>><p class="hint"><?= tt('nl.set_batch_hint') ?></p></div>
      <div class="field"><label><?= tt('nl.set_daily') ?></label><input name="nl_daily" type="number" min="10" max="5000" value="<?= nl_daily() ?>"<?= $send ? '' : ' readonly' ?>><p class="hint"><?= tt('nl.set_daily_hint') ?></p></div>
    </div>
    <?php if ($send): ?><div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('nl.set_save') ?></button></div><?php endif; ?>
  </form>
</div>
<?php endif; ?>
<?php layout_foot();

<?php
/** Commercial › Web Sales › Newsletters (GAS-CP-R97-039): issues, website subscribers, unsubscribed / bounced addresses, sending settings. ?p=newsletters[&tab=subs|optout|settings] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('nl_can') || !nl_can()) { http_response_code(403); layout_head(t('nl.title'), 'web_sales'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$tab = in_array($_GET['tab'] ?? '', ['lists', 'subs', 'optout', 'settings'], true) ? (string)$_GET['tab'] : 'issues'; $self = url('newsletters'); $send = nl_can_send();
$rows = $tab === 'issues' ? nl_issues() : []; $lists = $tab === 'lists' ? nl_lists() : []; $LE = $tab === 'lists' && !empty($_GET['edit']) ? nl_list((int)$_GET['edit']) : null; $lnew = $tab === 'lists' && !empty($_GET['new']); $subs = $tab === 'subs' ? nl_subs() : []; $opt = $tab === 'optout' ? nl_optouts() : [];
$nSub = (int) scalar("SELECT COUNT(*) FROM nl_subs WHERE status = 'confirmed'"); $nOpt = (int) scalar('SELECT COUNT(*) FROM nl_optout'); $today = (int) scalar("SELECT COUNT(*) FROM nl_sends WHERE status = 'sent' AND sent_at >= CURDATE()");
layout_head(t('nl.title'), 'web_sales');
web_sales_tabs('newsletters');
?>
<div class="page-head"><div><h1>✉ <?= tt('nl.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('nl.lede') ?></p></div></div>
<?php if (!nl_ready()): ?><p class="flash flash--bad"><?= tt('nl.no_mailer') ?></p><?php endif; ?>
<div class="tabs" style="margin:6px 0 12px;"><?php foreach (['issues' => [t('nl.t_issues'), 0], 'lists' => [t('nl.t_lists'), 0], 'subs' => [t('nl.t_subs'), $nSub], 'optout' => [t('nl.t_optout'), $nOpt], 'settings' => [t('nl.t_settings'), 0]] as $k => [$l, $n]): ?><a href="<?= url('newsletters', $k === 'issues' ? [] : ['tab' => $k]) ?>"<?= $k === $tab ? ' class="is-on"' : '' ?>><?= e($l) ?><?= $n ? '<span class="n">' . (int)$n . '</span>' : '' ?></a><?php endforeach; ?></div>
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
<?php elseif ($tab === 'lists'): ?>
<div class="card">
  <div class="card-head"><h2>📋 <?= tt('nl.t_lists') ?> <span class="count"><?= count($lists) ?></span></h2><div class="btn-row"><a class="btn btn--gold btn--sm" href="<?= url('newsletters', ['tab' => 'lists', 'new' => 1]) ?>#list-form">+ <?= tt('nl.l_new') ?></a>
    <?php if ($send && ($nAc = ct_autocat(true))): ?><form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= e(t('nl.autocat_q', ['n' => $nAc])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nl_autocat"><button class="btn btn--ghost btn--sm" type="submit">🏷 <?= e(t('nl.autocat_btn', ['n' => $nAc])) ?></button></form><?php endif; ?></div></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('nl.l_hint') ?></p>
  <?php if (!$lists): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('nl.l_name') ?></th><th><?= tt('nl.l_def') ?></th><th class="num"><?= tt('nl.l_members') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($lists as $L): $f = $L['filter']; $parts = array_merge(array_map(fn($k) => nl_groups()[$k] ?? $k, (array)($f['roles'] ?? [])), array_map(fn($k) => ct_categories()[$k] ?? $k, (array)($f['cats'] ?? [])), array_map(fn($k) => '✉ ' . t('ct.sub_' . $k), (array)($f['subs'] ?? [])), !empty($f['country']) ? [(string)$f['country']] : []); ?>
  <tr><td><a href="<?= url('newsletters', ['tab' => 'lists', 'edit' => (int)$L['id']]) ?>#list-form"><b><?= e($L['name']) ?></b></a><?= $L['descr'] ? '<br><span class="hint">' . e((string)$L['descr']) . '</span>' : '' ?></td><td class="hint"><?= e(implode(' · ', $parts)) ?: '—' ?></td><td class="num"><b><?= count(nl_list_members($L)) ?></b></td><td><a class="btn btn--ghost btn--sm" href="<?= url('newsletters', ['tab' => 'lists', 'edit' => (int)$L['id']]) ?>#list-form">✎</a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php if ($LE || $lnew): $f = $LE['filter'] ?? []; $mem = $LE ? nl_list_members($LE) : []; ?>
<div class="card" id="list-form"><h2><?= $LE ? '✎ ' . e($LE['name']) : '+ ' . tt('nl.l_new') ?><?php if ($LE): ?> <span class="count"><?= count($mem) ?></span><?php endif; ?></h2>
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_list_save"><input type="hidden" name="list_id" value="<?= (int)($LE['id'] ?? 0) ?>">
    <div class="grid grid-2"><div class="field"><label><?= tt('nl.l_name') ?> *</label><input name="name" maxlength="120" required value="<?= e((string)($LE['name'] ?? '')) ?>" placeholder="<?= tt('nl.l_name_ph') ?>"></div><div class="field"><label><?= tt('nl.l_descr') ?></label><input name="descr" maxlength="300" value="<?= e((string)($LE['descr'] ?? '')) ?>"></div></div>
    <div class="field"><label><?= tt('nl.l_roles') ?></label><div style="display:flex;flex-wrap:wrap;gap:4px 16px;"><?php foreach (nl_groups() as $g => $lab): ?><label class="chk" style="margin:0;"><input type="checkbox" name="roles[]" value="<?= $g ?>"<?= in_array($g, (array)($f['roles'] ?? []), true) ? ' checked' : '' ?>> <?= e($lab) ?></label><?php endforeach; ?></div></div>
    <div class="field"><label><?= tt('nl.l_cats') ?></label><div style="display:flex;flex-wrap:wrap;gap:4px 16px;"><?php foreach (ct_categories() as $g => $lab): ?><label class="chk" style="margin:0;"><input type="checkbox" name="cats[]" value="<?= $g ?>"<?= in_array($g, (array)($f['cats'] ?? []), true) ? ' checked' : '' ?>> <?= e($lab) ?></label><?php endforeach; ?></div><p class="hint"><?= tt('nl.l_and') ?></p></div>
    <div class="grid grid-2"><div class="field"><label><?= tt('nl.l_subs') ?></label><?php foreach (NL_TOPICS as $tp): ?><label class="chk"><input type="checkbox" name="subs[]" value="<?= $tp ?>"<?= in_array($tp, (array)($f['subs'] ?? []), true) ? ' checked' : '' ?>> <?= tt('ct.sub_' . $tp) ?></label><?php endforeach; ?><p class="hint"><?= tt('nl.l_subs_hint') ?></p></div>
      <div class="field"><label><?= tt('nl.aud_country') ?></label><input name="country" maxlength="80" value="<?= e((string)($f['country'] ?? '')) ?>"><p class="hint"><?= tt('nl.aud_country_hint') ?></p></div></div>
    <div class="grid grid-2"><div class="field"><label><?= tt('nl.l_add') ?></label><textarea name="add_emails" rows="3" maxlength="20000"><?= e((string)($LE['add_emails'] ?? '')) ?></textarea><p class="hint"><?= tt('nl.l_add_hint') ?></p></div>
      <div class="field"><label><?= tt('nl.l_skip') ?></label><textarea name="skip_emails" rows="3" maxlength="20000"><?= e((string)($LE['skip_emails'] ?? '')) ?></textarea><p class="hint"><?= tt('nl.l_skip_hint') ?></p></div></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">✓ <?= tt('nl.l_save') ?></button><?php if ($LE): ?><button class="btn btn--ghost btn--sm" type="submit" name="action" value="nl_list_delete" data-confirm="<?= tt('nl.l_delete_q') ?>">🗑 <?= tt('btn.delete') ?></button><?php endif; ?></div>
  </form>
  <?php if ($mem): ?><details open style="margin-top:12px;"><summary class="hint" style="cursor:pointer;"><?= e(t('nl.aud_list', ['n' => count($mem)])) ?></summary><div class="table-scroll" style="max-height:340px;"><table class="tbl"><thead><tr><th><?= tt('nl.c_name') ?></th><th><?= tt('au.email') ?></th><th><?= tt('co.one') ?></th><th><?= tt('ct.category') ?></th></tr></thead><tbody><?php foreach (array_slice($mem, 0, 600) as $r): ?><tr><td><?= $r['contact_id'] ? '<a href="' . url('contact', ['id' => $r['contact_id']]) . '">' . e($r['name'] ?: '—') . '</a>' : '—' ?></td><td class="hint"><?= e($r['email']) ?></td><td><?= e($r['company']) ?></td><td class="hint"><?= e(nl_group_label($r['grp'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</div>
<?php endif; ?>
<?php elseif ($tab === 'subs'): ?>
<div class="card"><p class="hint" style="margin:0 0 8px;"><?= tt('nl.subs_hint') ?></p>
  <?php if (!$subs): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('au.email') ?></th><th><?= tt('nl.c_name') ?></th><th><?= tt('nl.c_kind') ?></th><th><?= tt('nl.c_topics') ?></th><th><?= tt('th.status') ?></th><th><?= tt('nl.c_when') ?></th></tr></thead><tbody>
  <?php foreach ($subs as $s): ?><tr><td><?= e($s['email']) ?></td><td><?= !empty($s['contact_id']) ? '<a href="' . url('contact_edit', ['id' => (int)$s['contact_id']]) . '">' . e((string)($s['name'] ?: $s['email'])) . '</a>' : e((string)$s['name']) ?><?= $s['company'] ? '<br><span class="hint">' . e($s['company']) . '</span>' : '' ?><?= !empty($s['contact_id']) && function_exists('ct_origin_has') && ct_origin_has() && scalar('SELECT needs_review FROM contacts WHERE id = ?', [(int)$s['contact_id']]) ? ' ' . pill('gold', t('nl.to_review')) : '' ?></td><td class="hint"><?= e((string)$s['kind']) ?></td><td class="hint"><?= e(str_replace(',', ', ', (string)$s['topics'])) ?></td><td><?= pill(['pending' => 'gold', 'confirmed' => 'green', 'unsubscribed' => 'mute'][$s['status']] ?? 'mute', t('nl.ss_' . $s['status'])) ?></td><td class="date hint"><?= e(fmt_date(substr((string)($s['confirmed_at'] ?: $s['created_at']), 0, 10))) ?><br><?= e((string)$s['source']) ?></td></tr><?php endforeach; ?>
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

<?php
/** Community — noticeboard (posts, polls, photos), kudos wall, ranking & winner of the month, badges, onboarding (HR).   GAS-CP-R63-001   ?p=community[&kind=] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('soc_has') || !soc_has()) { layout_head(t('soc.title'), 'community'); echo '<h1>' . tt('soc.title') . '</h1><div class="flash flash--warn">' . tt('soc.need_r63') . '</div>'; layout_foot(); return; }
$me = soc_me(); $kind = in_array($_GET['kind'] ?? '', ['post', 'poll', 'photo'], true) ? $_GET['kind'] : null; $feed = soc_feed(40, $kind); $canPost = soc_can_post(); $hr = is_cp_admin() || (function_exists('hr_can') && hr_can());
$y = (int) date('Y'); $topY = function_exists('act_top') ? act_top("$y-01-01", date('Y-m-d'), 10) : []; $topM = function_exists('act_top') ? act_top(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 10) : [];
$kudTopY = soc_kudos_top("$y-01-01", date('Y-m-d')); $kudTopM = soc_kudos_top(date('Y-m-d', strtotime('-30 days')), date('Y-m-d')); $kud = soc_kudos_recent(40); $win = soc_winner(); $lastM = date('Y-m', strtotime('first day of last month'));
$fh = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
layout_head(t('soc.title'), 'community');
?>
<div class="page-head"><div><h1>🤝 <?= tt('soc.title') ?></h1><p class="lede"><?= tt('soc.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="#compose" onclick="document.getElementById('compose').open=true"><?= $canPost ? '+ ' . tt('soc.new_post') : '📷 ' . tt('soc.new_photo') ?></a></div></div>

<div class="cols" style="grid-template-columns: 3fr 2fr;">
<div>
<details class="card" id="compose">
  <summary style="cursor:pointer;font-weight:600;"><?= $canPost ? tt('soc.new_post') : tt('soc.new_photo') ?></summary>
  <form method="post" action="<?= url('community') ?>" enctype="multipart/form-data" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_post">
    <div class="grid grid-3">
      <div class="field"><label><?= tt('soc.kind') ?></label><select name="kind" id="soc_kind" data-nosearch><?php if ($canPost): ?><option value="post"><?= tt('soc.k_post') ?></option><option value="poll"><?= tt('soc.k_poll') ?></option><?php endif; ?><option value="photo"><?= tt('soc.k_photo') ?></option></select></div>
      <div class="field" style="grid-column: span 2;"><label><?= tt('soc.post_title') ?> *</label><input name="title" required maxlength="200" placeholder="<?= tt('soc.post_title_ph') ?>"></div>
    </div>
    <div class="field"><label><?= tt('soc.post_body') ?></label><textarea data-mt name="body" rows="3" maxlength="8000"></textarea></div>
    <div class="grid grid-3 soc-poll" style="display:none;"><?php for ($i = 0; $i < 6; $i++): ?><div class="field"><label><?= tt('soc.option') ?> <?= $i + 1 ?></label><input name="options[]" maxlength="120"></div><?php endfor; ?><div class="field"><label><?= tt('soc.closes_at') ?></label><input type="date" name="closes_at"></div></div>
    <div class="grid grid-3"><div class="field soc-photo"><label><?= tt('soc.photo') ?> <span class="hint soc-photo-opt"><?= tt('soc.photo_optional') ?></span></label><input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></div><?php if ($canPost): ?><div class="field"><label><?= tt('soc.pin_until') ?></label><input type="date" name="pinned_until"></div><?php endif; ?><div class="field"><label>&nbsp;</label><label class="pl-person" style="display:inline-flex;"><input type="checkbox" name="notify" value="1" checked> <?= tt('soc.notify_all') ?></label></div></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('soc.publish') ?></button></div>
  </form>
</details>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('soc_kind');if(!s)return;function f(){document.querySelector('.soc-poll').style.display=s.value==='poll'?'':'none';document.querySelector('.soc-photo-opt').style.display=s.value==='photo'?'none':'';document.querySelector('.soc-photo input').required=s.value==='photo';}s.addEventListener('change',f);f();})();</script>

<nav class="tabs" style="margin-bottom:10px;"><a class="tab<?= !$kind ? ' is-on' : '' ?>" href="<?= url('community') ?>"><?= tt('flt.any') ?></a><a class="tab<?= $kind === 'post' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'post']) ?>">📌 <?= tt('soc.k_post') ?></a><a class="tab<?= $kind === 'poll' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'poll']) ?>">🗳 <?= tt('soc.k_poll') ?></a><a class="tab<?= $kind === 'photo' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'photo']) ?>">📷 <?= tt('soc.k_photo') ?></a></nav>
<?php if (!$feed): ?><div class="card"><div class="empty"><?= tt('soc.feed_empty') ?></div></div><?php endif; ?>
<?php foreach ($feed as $p): $pinned = $p['pinned_until'] && $p['pinned_until'] >= date('Y-m-d'); ?>
<div class="card" id="post<?= (int)$p['id'] ?>"<?= $pinned ? ' style="border-color:#C9A227;"' : '' ?>>
  <p class="who" style="margin:0 0 6px;"><?= avatar_html((int)$p['user_id'], (string)$p['full_name'], 24, 'ava--inline') ?> <b><?= e($p['full_name']) ?></b> <span class="hint">· <?= e(fmt_datetime($p['created_at'])) ?></span> <?= $pinned ? pill('gold', '📌 ' . t('soc.pinned')) : '' ?> <?= $p['kind'] === 'poll' ? pill('navy', '🗳 ' . t('soc.k_poll')) : ($p['kind'] === 'photo' ? pill('blue', '📷 ' . t('soc.k_photo')) : '') ?>
    <?php if ((int)$p['user_id'] === $me || $hr): ?><form method="post" action="<?= url('community') ?>" class="inline" style="float:right;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_post_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('soc.delete_confirm') ?>">🗑</button></form><?php endif; ?></p>
  <h3 style="margin:0 0 6px;"><?= e($p['title']) ?></h3>
  <?php if ($p['body']): ?><div class="body" style="margin-bottom:8px;"><?= function_exists('mt_html') ? mt_html($p['body']) : e($p['body']) ?></div><?php endif; ?>
  <?php if ($p['stored_name']): ?><a href="<?= url('socphoto', ['id' => (int)$p['id']]) ?>" target="_blank" rel="noopener"><img src="<?= url('socphoto', ['id' => (int)$p['id']]) ?>" alt="" loading="lazy" style="max-width:100%;max-height:420px;border-radius:8px;display:block;margin-bottom:8px;"></a><?php endif; ?>
  <?php if ($p['kind'] === 'poll'): ?>
    <div style="display:grid;gap:4px;margin-bottom:8px;"><?php foreach ($p['options'] as $o): $pc = $p['votes'] ? (int) round($o['n'] / $p['votes'] * 100) : 0; $mine = $p['my_vote'] !== null && (int)$p['my_vote'] === (int)$o['idx']; ?>
      <form method="post" action="<?= url('community') ?>" data-lock style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_vote"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="idx" value="<?= (int)$o['idx'] ?>">
        <button class="btn btn--ghost btn--sm" type="submit" style="text-align:left;justify-content:flex-start;position:relative;overflow:hidden;"<?= $p['closed'] ? ' disabled' : '' ?>><span style="position:absolute;left:0;top:0;bottom:0;width:<?= $pc ?>%;background:rgba(201,162,39,.25);"></span><span style="position:relative;"><?= $mine ? '✓ ' : '' ?><?= e($o['label']) ?></span></button><span class="num"><b><?= (int)$o['n'] ?></b> <span class="hint">· <?= $pc ?>%</span></span></form>
    <?php endforeach; ?></div>
    <p class="hint" style="margin:0 0 8px;"><?= e(t('soc.votes_n', ['n' => (int)$p['votes']])) ?><?= $p['closes_at'] ? ' · ' . ($p['closed'] ? tt('soc.poll_closed_s') : e(t('soc.closes', ['d' => fmt_date($p['closes_at'])]))) : '' ?></p>
  <?php endif; ?>
  <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
    <?php foreach (SOC_REACTIONS as $em): $r = array_values(array_filter($p['reactions'], fn($x) => $x['emoji'] === $em))[0] ?? null; ?><form method="post" action="<?= url('community') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="soc_react"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="emoji" value="<?= $em ?>"><button class="btn btn--ghost btn--sm<?= $r && (int)$r['mine'] ? ' is-on' : '' ?>" type="submit" style="padding:2px 8px;"><?= $em ?><?= $r ? ' ' . (int)$r['n'] : '' ?></button></form><?php endforeach; ?>
  </div>
  <?php if ($p['comments']): ?><div style="margin-top:8px;display:grid;gap:4px;"><?php foreach ($p['comments'] as $c): ?><div class="hint" style="padding-left:10px;border-left:2px solid var(--line);"><b><?= e($c['full_name']) ?></b> · <?= e(fmt_datetime($c['created_at'])) ?><br><span style="color:var(--ink);"><?= function_exists('mt_html') ? mt_html($c['body']) : e($c['body']) ?></span></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" action="<?= url('community') ?>" data-lock style="display:flex;gap:6px;margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_comment"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><textarea data-mt name="body" rows="1" maxlength="1000" placeholder="<?= tt('soc.comment_ph') ?>" style="flex:1;min-height:34px;resize:vertical;"></textarea><button class="btn btn--ghost btn--sm" type="submit"><?= tt('oa.post') ?></button></form>
</div>
<?php endforeach; ?>
</div>
<div>
<div class="card" id="ranking">
  <h2>🏆 <?= tt('soc.ranking') ?></h2>
  <?php if ($win): ?><p style="margin:0 0 8px;"><?= avatar_html((int)$win['user_id'], (string)$win['full_name'], 22, 'ava--inline') ?> <b><?= e(t('soc.winner_title', ['m' => fmt_month($win['month'])])) ?>:</b> <?= e($win['full_name']) ?><?= $win['prize'] ? ' <span class="hint">· ' . e($win['prize']) . '</span>' : '' ?></p><?php endif; ?>
  <div class="grid grid-2">
    <div><h3 class="ac-h" style="margin-top:0;"><?= e(t('act.top_month')) ?></h3><ol style="margin:0;padding-left:18px;"><?php foreach ($topM as $r): ?><li><?= e($r['name']) ?> <span class="hint">· <?= (int)$r['score'] ?></span></li><?php endforeach; ?></ol></div>
    <div><h3 class="ac-h" style="margin-top:0;"><?= e(t('act.top_year', ['y' => $y])) ?></h3><ol style="margin:0;padding-left:18px;"><?php foreach ($topY as $r): ?><li><?= e($r['name']) ?> <span class="hint">· <?= (int)$r['score'] ?></span></li><?php endforeach; ?></ol></div>
  </div>
  <?php if ($hr): ?>
  <details style="margin-top:10px;"><summary class="hint" style="cursor:pointer;"><?= tt('soc.winner_set') ?></summary>
    <form method="post" action="<?= url('community') ?>" data-lock style="margin-top:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_winner">
      <div class="grid grid-2"><div class="field"><label><?= tt('soc.month') ?></label><input type="month" name="month" value="<?= e($lastM) ?>"></div><div class="field"><label><?= tt('soc.winner') ?></label><select name="user_id"><option value="">—</option><?php foreach (soc_staff() as $uid => $su): ?><option value="<?= $uid ?>"<?= $win && (int)$win['user_id'] === $uid ? ' selected' : '' ?>><?= e($su['full_name']) ?></option><?php endforeach; ?></select></div></div>
      <div class="field"><label><?= tt('soc.prize') ?></label><input name="prize" maxlength="200" value="<?= e((string)($win['prize'] ?? cp_setting('soc_prize'))) ?>"></div>
      <div class="field"><label><?= tt('hr.notes') ?></label><input name="note" maxlength="300" value="<?= e((string)($win['note'] ?? '')) ?>"></div>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
    </form></details>
  <?php endif; ?>
  <p class="hint" style="margin-top:8px;"><?= tt('soc.ranking_hint') ?></p>
</div>
<div class="card" id="kudos">
  <h2>👏 <?= tt('soc.kudos') ?></h2>
  <div class="grid grid-2" style="margin-bottom:8px;">
    <div><h3 class="ac-h" style="margin-top:0;"><?= tt('soc.most_thanked_m') ?></h3><ol style="margin:0;padding-left:18px;"><?php foreach ($kudTopM as $r): ?><li><?= e($r['full_name']) ?> <span class="hint">· <?= (int)$r['n'] ?></span></li><?php endforeach; ?></ol></div>
    <div><h3 class="ac-h" style="margin-top:0;"><?= tt('soc.most_thanked_y') ?></h3><ol style="margin:0;padding-left:18px;"><?php foreach ($kudTopY as $r): ?><li><?= e($r['full_name']) ?> <span class="hint">· <?= (int)$r['n'] ?></span></li><?php endforeach; ?></ol></div>
  </div>
  <form method="post" action="<?= url('community') ?>" data-lock style="display:grid;gap:6px;margin-bottom:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_kudos">
    <select name="to"><option value=""><?= tt('soc.kudos_to') ?></option><?php foreach (soc_staff() as $uid => $su): if ($uid === $me) continue; ?><option value="<?= $uid ?>"><?= e($su['full_name']) ?></option><?php endforeach; ?></select>
    <input name="reason" required minlength="3" maxlength="300" placeholder="<?= tt('soc.kudos_ph') ?>"><button class="btn btn--gold btn--sm" type="submit">👏 <?= tt('soc.kudos_give') ?></button>
  </form>
  <?php if (!$kud): ?><p class="hint"><?= tt('soc.kudos_none') ?></p><?php else: ?><ul style="list-style:none;margin:0;padding:0;display:grid;gap:3px;max-height:360px;overflow:auto;"><?php foreach ($kud as $k): ?><li><span class="hint"><?= e(fmt_date($k['created_at'])) ?></span> <b><?= e($k['from_name']) ?></b> → <b><?= e($k['to_name']) ?></b><br><span class="hint"><?= e($k['reason']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<div class="card" id="badges">
  <h2>🏅 <?= tt('soc.badges') ?></h2>
  <p class="hint"><?= tt('soc.badges_hint') ?></p>
  <?php soc_badges_refresh($me); $myB = soc_badges($me); ?>
  <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;"><?php foreach (SOC_BADGES as $code => [$icon]): $have = isset($myB[$code]); ?><span class="chip" style="<?= $have ? '' : 'opacity:.35;' ?>" title="<?= e(t('soc.b_' . $code)) ?><?= $have ? ' · ' . e(fmt_date($myB[$code])) : '' ?>"><?= $icon ?> <?= e(t('soc.b_' . $code)) ?></span><?php endforeach; ?></div>
  <h3 class="ac-h"><?= tt('soc.colleagues') ?></h3>
  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:3px;max-height:420px;overflow:auto;"><?php foreach (soc_staff() as $uid => $su): $bh = soc_badges_html($uid, 8); if (!$bh && $uid !== $me) continue; ?><li><?= avatar_html($uid, (string)$su['full_name'], 18, 'ava--inline') ?> <?= e($su['full_name']) ?> <span style="font-size:15px;"><?= $bh ?></span></li><?php endforeach; ?></ul>
</div>
<?php if ($hr): ?>
<div class="card" id="onboarding">
  <h2>👋 <?= tt('soc.onboarding') ?></h2>
  <p class="hint"><?= tt('soc.onboarding_hint') ?></p>
  <form method="post" action="<?= url('community') ?>" data-lock style="display:grid;gap:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_onboard_start">
    <div class="field"><label><?= tt('soc.new_hire') ?></label><select name="user_id"><?php foreach (soc_staff() as $uid => $su): ?><option value="<?= $uid ?>"><?= e($su['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('soc.buddy') ?></label><select name="buddy_user_id"><option value="">—</option><?php foreach (soc_staff() as $uid => $su): ?><option value="<?= $uid ?>"><?= e($su['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('soc.onboard_start') ?></button></div>
  </form>
  <?php $open = soc_onboard_mine(); if ($open): ?><ul class="hint" style="margin-top:8px;padding-left:18px;"><?php foreach ($open as $o): ?><li><b><?= e($o['full_name']) ?></b> · <?= count(array_filter(explode(',', (string)$o['done']))) ?> / <?= count(SOC_ONBOARD_ITEMS) ?><?= $o['buddy_name'] ? ' · ' . e(t('soc.buddy_is', ['b' => $o['buddy_name']])) : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
<?php layout_foot(); ?>

<?php
/** Community — noticeboard (posts, polls, photos), kudos wall, ranking & winner of the month, badges, onboarding (HR).   GAS-CP-R63-001   ?p=community[&kind=][&edit=<id>]
 *  R97-201: the feed is a list of titles that open on click (pinned posts and open polls start open); a post can carry its text in several languages. */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('soc_has') || !soc_has()) { layout_head(t('soc.title'), 'community'); echo '<h1>' . tt('soc.title') . '</h1><div class="flash flash--warn">' . tt('soc.need_r63') . '</div>'; layout_foot(); return; }
$me = soc_me(); $kind = in_array($_GET['kind'] ?? '', ['post', 'poll', 'photo', 'video'], true) ? $_GET['kind'] : null; $vidOk = function_exists('soc_video_has') && soc_video_has(); $feed = soc_feed(40, $kind); $trOk = function_exists('soc_tr_has') && soc_tr_has(); $canPost = soc_can_post(); $hr = is_cp_admin() || (function_exists('hr_can') && hr_can());
$y = (int) date('Y'); $topY = function_exists('act_top') ? act_top("$y-01-01", date('Y-m-d'), 10) : []; $topM = function_exists('act_top') ? act_top(date('Y-m-d', strtotime('-30 days')), date('Y-m-d'), 10) : [];
$kudTopY = soc_kudos_top("$y-01-01", date('Y-m-d')); $kudTopM = soc_kudos_top(date('Y-m-d', strtotime('-30 days')), date('Y-m-d')); $kud = soc_kudos_recent(40); $win = soc_winner(); $lastM = date('Y-m', strtotime('first day of last month'));
$fh = fn($v) => rtrim(rtrim(number_format((float)$v, 1, '.', ''), '0'), '.');
/* R97-201: ?edit=<id> turns the form into the editor of that post (its writer, HR, administrators) */
$edit = null; $editTr = []; if ($trOk && !empty($_GET['edit'])) { $ep = one('SELECT * FROM soc_posts WHERE id = ?', [(int)$_GET['edit']]); if ($ep && soc_post_can_edit($ep)) { $edit = $ep; $editTr = soc_tr_of((int)$ep['id']); } }
$editLang = $edit && locale_valid((string)($edit['lang'] ?? '')) ? (string)$edit['lang'] : locale();
layout_head(t('soc.title'), 'community');
?>
<div class="page-head"><div><h1>🤝 <?= tt('soc.title') ?></h1><p class="lede"><?= tt('soc.lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--gold" href="#compose" data-js-open="compose"><?= $canPost ? '+ ' . tt('soc.new_post') : '📷 ' . tt('soc.new_photo') ?></a></div></div>

<div class="cols" style="grid-template-columns: 3fr 2fr;">
<div>
<style nonce="<?= csp_nonce() ?>">
.soc-post > summary { list-style: none; cursor: pointer; display: flex; flex-wrap: wrap; gap: 4px 8px; align-items: center; }
.soc-post > summary::-webkit-details-marker { display: none; }
.soc-post > summary::before { content: '▸'; color: var(--cp-gold); width: 12px; flex: 0 0 12px; }
.soc-post[open] > summary::before { content: '▾'; }
.soc-post[open] > summary { padding-bottom: 8px; margin-bottom: 8px; border-bottom: 1px solid var(--line); }
.soc-sum-t { font-weight: 700; flex: 1 1 260px; min-width: 0; overflow-wrap: anywhere; }
.soc-sum-n { white-space: nowrap; }
.soc-bar { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-bottom: 8px; }
.soc-bar .soc-bar-r { margin-left: auto; display: flex; gap: 4px; align-items: center; }
.soc-tr { border-top: 1px solid var(--line); padding-top: 8px; margin-top: 8px; }
.soc-tools { display: flex; justify-content: flex-end; gap: 6px; margin: -4px 0 8px; }
</style>
<details class="card" id="compose"<?= $edit ? ' open' : '' ?>>
  <summary style="cursor:pointer;font-weight:600;"><?= $edit ? '✎ ' . tt('soc.edit_post') : ($canPost ? tt('soc.new_post') : tt('soc.new_photo')) ?></summary>
  <form method="post" action="<?= url('community') ?>" enctype="multipart/form-data" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="<?= $edit ? 'soc_post_edit' : 'soc_post' ?>"><?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="grid grid-3">
      <?php if (!$edit): ?><div class="field"><label><?= tt('soc.kind') ?></label><select name="kind" id="soc_kind" data-nosearch><?php if ($canPost): ?><option value="post"><?= tt('soc.k_post') ?></option><option value="poll"><?= tt('soc.k_poll') ?></option><?php endif; ?><option value="photo"><?= tt('soc.k_photo') ?></option><?php if ($vidOk): ?><option value="video"><?= tt('soc.k_video') ?></option><?php endif; ?></select></div><?php endif; ?>
      <?php if ($trOk): ?><div class="field"><label><?= tt('soc.lang') ?></label><select name="lang" id="soc_lang" data-nosearch><?php foreach (LOCALES as $lc => $li): ?><option value="<?= $lc ?>"<?= $lc === $editLang ? ' selected' : '' ?>><?= e($li['native']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="field" style="grid-column: span <?= 3 - ($edit ? 0 : 1) - ($trOk ? 1 : 0) ?>;"><label><?= tt('soc.post_title') ?> *</label><input name="title" required maxlength="200" placeholder="<?= tt('soc.post_title_ph') ?>" value="<?= e((string)($edit['title'] ?? '')) ?>"></div>
    </div>
    <div class="field"><label><?= tt('soc.post_body') ?></label><textarea data-mt name="body" rows="<?= $edit ? 8 : 3 ?>" maxlength="8000"><?= e((string)($edit['body'] ?? '')) ?></textarea></div>
    <?php if ($trOk): ?>
    <details id="soc_trs"<?= $editTr ? ' open' : '' ?> style="margin-bottom:10px;"><summary style="cursor:pointer;font-weight:600;">🌐 <?= tt('soc.tr_title') ?></summary>
      <p class="hint" style="margin:6px 0 0;"><?= tt('soc.tr_hint') ?></p>
      <?php foreach (LOCALES as $lc => $li): ?><div class="soc-tr" data-l="<?= $lc ?>"<?= $lc === $editLang ? ' hidden' : '' ?>>
        <div class="field"><label><?= e(t('soc.post_title') . ' — ' . $li['native']) ?></label><input name="tr[<?= $lc ?>][title]" maxlength="200" value="<?= e((string)($editTr[$lc][0] ?? '')) ?>"></div>
        <div class="field"><label><?= e(t('soc.post_body') . ' — ' . $li['native']) ?></label><textarea data-mt name="tr[<?= $lc ?>][body]" rows="<?= isset($editTr[$lc]) ? 8 : 3 ?>" maxlength="8000"><?= e((string)($editTr[$lc][1] ?? '')) ?></textarea></div>
      </div><?php endforeach; ?>
    </details>
    <?php endif; ?>
    <?php if (!$edit): ?>
    <div class="grid grid-3 soc-poll" style="display:none;"><?php for ($i = 0; $i < 6; $i++): ?><div class="field"><label><?= tt('soc.option') ?> <?= $i + 1 ?></label><input name="options[]" maxlength="120"></div><?php endfor; ?><div class="field"><label><?= tt('soc.closes_at') ?></label><input type="date" name="closes_at"></div></div>
    <?php if ($vidOk): ?><div class="field soc-video"><label><?= tt('soc.video_url') ?> <span class="hint soc-video-opt"><?= tt('soc.photo_optional') ?></span></label><input type="url" name="video_url" maxlength="300" placeholder="https://www.youtube.com/watch?v=…"><p class="hint" style="margin:2px 0 0;"><?= tt('soc.video_hint') ?></p></div><?php endif; ?>
    <div class="grid grid-3"><div class="field soc-photo"><label><?= tt('soc.photo') ?> <span class="hint soc-photo-opt"><?= tt('soc.photo_optional') ?></span></label><input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp"></div><?php if ($canPost): ?><div class="field"><label><?= tt('soc.pin_until') ?></label><input type="date" name="pinned_until"></div><?php endif; ?><div class="field"><label>&nbsp;</label><label class="pl-person" style="display:inline-flex;"><input type="checkbox" name="notify" value="1" checked> <?= tt('soc.notify_all') ?></label></div></div>
    <?php endif; ?>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $edit ? tt('soc.save_post') : tt('soc.publish') ?></button><?php if ($edit): ?> <a class="btn btn--ghost" href="<?= url('community') ?>#post<?= (int)$edit['id'] ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
</details>
<script nonce="<?= csp_nonce() ?>">(function(){var s=document.getElementById('soc_kind');if(!s)return;function f(){document.querySelector('.soc-poll').style.display=s.value==='poll'?'':'none';document.querySelector('.soc-photo-opt').style.display=s.value==='photo'?'none':'';document.querySelector('.soc-photo input').required=s.value==='photo';var v=document.querySelector('.soc-video');if(v){v.querySelector('input').required=s.value==='video';v.querySelector('.soc-video-opt').style.display=s.value==='video'?'none':'';}}s.addEventListener('change',f);f();})();
(function(){var l=document.getElementById('soc_lang');if(!l)return;function g(){document.querySelectorAll('#soc_trs .soc-tr').forEach(function(b){b.hidden=b.getAttribute('data-l')===l.value;});}l.addEventListener('change',g);g();})();
(function(){function openHash(){var m=/^#post(\d+)$/.exec(location.hash);if(!m)return;var d=document.getElementById('post'+m[1]);if(d&&d.tagName==='DETAILS'){d.open=true;d.scrollIntoView({block:'start'});}}
document.addEventListener('DOMContentLoaded',openHash);window.addEventListener('hashchange',openHash);
document.addEventListener('click',function(ev){var t=ev.target.closest?ev.target.closest('[data-soc-all],[data-soc-pick]'):null;if(!t)return;
if(t.hasAttribute('data-soc-all')){var o=t.getAttribute('data-soc-all')==='1';document.querySelectorAll('details.soc-post').forEach(function(d){d.open=o;});return;}
var p=t.closest('.soc-post'),l=t.getAttribute('data-soc-pick');if(!p)return;p.querySelectorAll('[data-soc-lang]').forEach(function(x){x.hidden=x.getAttribute('data-soc-lang')!==l;});p.querySelectorAll('[data-soc-pick]').forEach(function(x){x.classList.toggle('is-on',x===t);});});})();
document.addEventListener('click',function(ev){var b=ev.target.closest?ev.target.closest('.soc-yt-play'):null;if(!b)return;var w=b.parentNode,id=w.getAttribute('data-yt');if(!/^[A-Za-z0-9_-]{11}$/.test(id))return;var i=document.createElement('iframe');i.src='https://www.youtube-nocookie.com/embed/'+id+'?autoplay=1&rel=0';i.title='YouTube';i.setAttribute('allow','autoplay; encrypted-media; picture-in-picture; fullscreen');i.setAttribute('allowfullscreen','');i.setAttribute('referrerpolicy','strict-origin-when-cross-origin');i.setAttribute('sandbox','allow-scripts allow-same-origin allow-presentation allow-popups');i.style.cssText='position:absolute;inset:0;width:100%;height:100%;border:0;';w.textContent='';w.appendChild(i);});</script>

<nav class="tabs" style="margin-bottom:10px;"><a class="tab<?= !$kind ? ' is-on' : '' ?>" href="<?= url('community') ?>"><?= tt('flt.any') ?></a><a class="tab<?= $kind === 'post' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'post']) ?>">📌 <?= tt('soc.k_post') ?></a><a class="tab<?= $kind === 'poll' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'poll']) ?>">🗳 <?= tt('soc.k_poll') ?></a><a class="tab<?= $kind === 'photo' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'photo']) ?>">📷 <?= tt('soc.k_photo') ?></a><?php if ($vidOk): ?><a class="tab<?= $kind === 'video' ? ' is-on' : '' ?>" href="<?= url('community', ['kind' => 'video']) ?>">🎬 <?= tt('soc.k_video') ?></a><?php endif; ?></nav>
<?php if (!$feed): ?><div class="card"><div class="empty"><?= tt('soc.feed_empty') ?></div></div><?php else: ?><div class="soc-tools"><button class="btn btn--ghost btn--sm" type="button" data-soc-all="1">▾ <?= tt('soc.expand_all') ?></button><button class="btn btn--ghost btn--sm" type="button" data-soc-all="0">▸ <?= tt('soc.collapse_all') ?></button></div><?php endif; ?>
<?php foreach ($feed as $p): $pinned = $p['pinned_until'] && $p['pinned_until'] >= date('Y-m-d'); $vers = $p['vers'] ?? ['' => [(string)$p['title'], $p['body']]]; $show = (string)($p['show'] ?? ''); $multi = count($vers) > 1;
  $nReact = array_sum(array_map(fn($x) => (int)$x['n'], $p['reactions'])); $nCom = count($p['comments']); $isOpen = $pinned || ($p['kind'] === 'poll' && empty($p['closed'])) || ($edit && (int)$edit['id'] === (int)$p['id']); ?>
<details class="card soc-post" id="post<?= (int)$p['id'] ?>"<?= $isOpen ? ' open' : '' ?><?= $pinned ? ' style="border-color:#C9A227;"' : '' ?>>
  <summary><?= avatar_html((int)$p['user_id'], (string)$p['full_name'], 24, 'ava--inline') ?>
    <span class="soc-sum-t"><?php foreach ($vers as $vl => $vv): ?><span data-soc-lang="<?= e((string)$vl) ?>"<?= (string)$vl === $show ? '' : ' hidden' ?>><?= e($vv[0]) ?></span><?php endforeach; ?></span>
    <span class="hint"><?= e($p['full_name']) ?> · <?= e(fmt_datetime($p['created_at'])) ?></span>
    <?= $pinned ? pill('gold', '📌 ' . t('soc.pinned')) : '' ?> <?= $p['kind'] === 'poll' ? pill('navy', '🗳 ' . t('soc.k_poll')) : ($p['kind'] === 'photo' ? pill('blue', '📷 ' . t('soc.k_photo')) : ($p['kind'] === 'video' ? pill('blue', '🎬 ' . t('soc.k_video')) : '')) ?>
    <?php if ($nReact || $nCom || $multi): ?><span class="hint soc-sum-n"><?= $multi ? '🌐 ' . count($vers) : '' ?><?= $nReact ? ($multi ? ' · ' : '') . '👍 ' . $nReact : '' ?><?= $nCom ? ($multi || $nReact ? ' · ' : '') . '💬 ' . $nCom : '' ?></span><?php endif; ?>
  </summary>
  <?php $mine = (int)$p['user_id'] === $me || $hr; if ($multi || $mine): ?><div class="soc-bar">
    <?php if ($multi): foreach ($vers as $vl => $vv): ?><button class="btn btn--ghost btn--sm<?= (string)$vl === $show ? ' is-on' : '' ?>" type="button" data-soc-pick="<?= e((string)$vl) ?>" style="padding:2px 8px;"><?= e(LOCALES[$vl]['short'] ?? '—') ?></button><?php endforeach; endif; ?>
    <?php if ($mine): ?><span class="soc-bar-r"><?php if ($trOk): ?><a class="btn btn--ghost btn--sm" href="<?= url('community', ['edit' => (int)$p['id']]) ?>#compose">✎ <?= tt('btn.edit') ?></a><?php endif; ?><form method="post" action="<?= url('community') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="soc_post_delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" data-js-confirm="<?= tt('soc.delete_confirm') ?>">🗑</button></form></span><?php endif; ?>
  </div><?php endif; ?>
  <?php foreach ($vers as $vl => $vv): if ($vv[1] === null || $vv[1] === '') continue; ?><div class="body" data-soc-lang="<?= e((string)$vl) ?>" style="margin-bottom:8px;"<?= (string)$vl === $show ? '' : ' hidden' ?>><?= function_exists('mt_html') ? mt_html($vv[1]) : e($vv[1]) ?></div><?php endforeach; ?>
  <?= !empty($p['video_id']) ? soc_video_html((string)$p['video_id']) : '' ?>
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
</details>
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
      <div class="grid grid-2"><div class="field"><label><?= tt('soc.month') ?></label><input type="month" name="month" value="<?= e($lastM) ?>"></div><div class="field"><label><?= tt('soc.winner') ?></label><select name="user_id"><option value="">—</option><?php $skipW = function_exists('hr_owner_ids') ? hr_owner_ids() : []; foreach (soc_staff() as $uid => $su): if (in_array($uid, $skipW, true)) continue; ?><option value="<?= $uid ?>"<?= $win && (int)$win['user_id'] === $uid ? ' selected' : '' ?>><?= e($su['full_name']) ?></option><?php endforeach; ?></select></div></div>
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

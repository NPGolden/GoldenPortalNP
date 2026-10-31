<?php
/** Dashboard community block (Golden A/S accounts): celebrations with wishes, wishes wall, winner of the month, kudos, onboarding.   GAS-CP-R63-001 */
$socEv = soc_events(14); $socWall = soc_wishes_for_me(); $socWin = soc_winner(); $socKud = soc_kudos_recent(6); $socOn = soc_onboard_mine(); $me = soc_me(); $today = date('Y-m-d'); $tom = date('Y-m-d', strtotime('+1 day'));
$socBack = $socBack ?? 'dashboard';
$kindIcon = ['birthday' => '🎂', 'nameday' => '🎊', 'anniversary' => '🏅', 'welcome' => '👋'];
?>
<?php if ($socWin): ?>
<div class="card" id="winner" style="border-color:#C9A227;background:linear-gradient(90deg,rgba(201,162,39,.12),transparent);">
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;"><span style="font-size:28px;">🏆</span><div><b><?= e(t('soc.winner_title', ['m' => fmt_month($socWin['month'])])) ?>:</b> <?= avatar_html((int)$socWin['user_id'], (string)$socWin['full_name'], 22, 'ava--inline') ?> <b><?= e($socWin['full_name']) ?></b><?= $socWin['prize'] ? ' · <span class="hint">' . e($socWin['prize']) . '</span>' : '' ?><?= $socWin['note'] ? '<br><span class="hint">' . e($socWin['note']) . '</span>' : '' ?></div><a class="btn btn--ghost btn--sm" style="margin-left:auto;" href="<?= url('community') ?>#ranking"><?= tt('soc.title') ?> ›</a></div>
</div>
<?php endif; ?>
<?php if ($socWall): ?>
<div class="card" id="wishes" style="border-color:#C9A227;">
  <h2>💌 <?= e(t('soc.wall_title', ['n' => count($socWall)])) ?></h2>
  <div style="display:flex;flex-wrap:wrap;gap:6px;"><?php foreach ($socWall as $w): ?><span class="chip" title="<?= e(fmt_datetime($w['created_at'])) ?>"><?= e((string)$w['emoji']) ?> <b><?= e($w['full_name']) ?></b><?= $w['message'] ? ' · ' . e($w['message']) : '' ?></span><?php endforeach; ?></div>
</div>
<?php endif; ?>
<?php if ($socEv): ?>
<div class="card" id="celebrations">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">🎉 <?= tt('cel.title') ?> <span class="hint" style="display:inline;margin-left:6px;"><?= e(t('cel.next_days', ['n' => 14])) ?></span></h2><a class="btn btn--ghost btn--sm" href="<?= url('community') ?>"><?= tt('soc.title') ?> ›</a></div>
  <ul style="list-style:none;margin:0;padding:0;display:grid;gap:4px;">
  <?php foreach ($socEv as $ev): $p = $ev['person']; $when = $ev['date'] === $today ? '<b>' . tt('cel.today') . '</b>' : ($ev['date'] === $tom ? tt('cel.tomorrow') : e(fmt_date($ev['date']))); ?>
    <li style="display:flex;flex-wrap:wrap;gap:6px 10px;align-items:center;padding:3px 6px;<?= $ev['date'] === $today ? 'background:#FFFBEA;border-radius:6px;' : '' ?>">
      <span class="hint" style="min-width:92px;"><?= $when ?></span><?= $ev['uid'] ? avatar_html((int)$ev['uid'], (string)$p['name_en'], 20, 'ava--inline') : '' ?> <b><?= e($p['name_en']) ?></b>
      <span><?= $kindIcon[$ev['kind']] ?> <?= e(t('soc.k_' . $ev['kind'])) ?><?= $ev['kind'] === 'anniversary' ? ' <span class="hint">· ' . e((int)$ev['years'] === 1 ? t('soc.year_1') : t('soc.years_n', ['n' => (int)$ev['years']])) . '</span>' : ($ev['label'] ? ' <span class="hint">· ' . e((string)$ev['label']) . '</span>' : '') ?></span>
      <?php if ($ev['wishes']): ?><span class="hint" title="<?= e(implode(', ', array_map(fn($w) => $w['full_name'], $ev['wishes']))) ?>"><?= implode('', array_unique(array_map(fn($w) => (string)$w['emoji'], $ev['wishes']))) ?> <?= count($ev['wishes']) ?></span><?php endif; ?>
      <?php if ($ev['can'] && !$ev['mine']): ?>
      <form method="post" action="<?= url($socBack) ?>" class="inline soc-wish" data-lock style="margin-left:auto;display:flex;gap:4px;align-items:center;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_wish"><input type="hidden" name="to" value="<?= (int)$ev['uid'] ?>"><input type="hidden" name="kind" value="<?= e($ev['kind']) ?>"><input type="hidden" name="on_date" value="<?= e($ev['date']) ?>">
        <select name="emoji" data-nosearch style="width:60px;padding:2px 4px;"><?php foreach (SOC_EMOJIS as $em): ?><option><?= $em ?></option><?php endforeach; ?></select><input name="message" maxlength="300" placeholder="<?= tt('soc.wish_ph') ?>" style="width:170px;padding:3px 6px;"><button class="btn btn--gold btn--sm" type="submit"><?= tt('soc.wish') ?></button></form>
      <?php elseif ($ev['mine']): ?><span class="pill pill--green" style="margin-left:auto;">✓ <?= tt('soc.wished') ?></span><?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
<?php if ($socOn): ?>
<?php foreach ($socOn as $o): $done = array_filter(explode(',', (string)$o['done'])); $isMe = (int)$o['user_id'] === $me; ?>
<div class="card" id="onboard">
  <h2>👋 <?= $isMe ? tt('soc.onboard_my') : e(t('soc.onboard_of', ['u' => $o['full_name']])) ?> <span class="hint" style="display:inline;margin-left:6px;"><?= count($done) ?> / <?= count(SOC_ONBOARD_ITEMS) ?></span></h2>
  <p class="hint"><?= $o['buddy_name'] ? e(t('soc.buddy_is', ['b' => $o['buddy_name']])) . ' · ' : '' ?><?= e(t('soc.onboard_since', ['d' => fmt_date($o['started_at'])])) ?></p>
  <form method="post" action="<?= url($socBack) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="soc_onboard_tick"><input type="hidden" name="user_id" value="<?= (int)$o['user_id'] ?>">
    <div class="pl-chips" style="min-height:0;"><?php foreach (SOC_ONBOARD_ITEMS as $it): ?><label class="pl-person"><input type="checkbox" name="done[]" value="<?= $it ?>"<?= in_array($it, $done, true) ? ' checked' : '' ?>> <?= e(t('soc.ob_' . $it)) ?></label><?php endforeach; ?></div>
    <div class="btn-row" style="margin-top:6px;"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<?php endforeach; endif; ?>
<div class="card" id="kudos">
  <div class="page-head" style="margin-bottom:6px;"><h2 style="margin:0;">👏 <?= tt('soc.kudos') ?></h2><a class="btn btn--ghost btn--sm" href="<?= url('community') ?>#kudos"><?= tt('soc.kudos_all') ?> ›</a></div>
  <form method="post" action="<?= url($socBack) ?>" data-lock style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_kudos">
    <select name="to" style="min-width:200px;"><option value=""><?= tt('soc.kudos_to') ?></option><?php foreach (soc_staff() as $uid => $su): if ($uid === $me) continue; ?><option value="<?= $uid ?>"><?= e($su['full_name']) ?></option><?php endforeach; ?></select>
    <input name="reason" required minlength="3" maxlength="300" placeholder="<?= tt('soc.kudos_ph') ?>" style="flex:1;min-width:220px;"><button class="btn btn--gold btn--sm" type="submit">👏 <?= tt('soc.kudos_give') ?></button>
  </form>
  <?php if (!$socKud): ?><p class="hint"><?= tt('soc.kudos_none') ?></p><?php else: ?><ul style="list-style:none;margin:0;padding:0;display:grid;gap:3px;"><?php foreach ($socKud as $k): ?><li><span class="hint"><?= e(fmt_date($k['created_at'])) ?></span> <b><?= e($k['from_name']) ?></b> → <b><?= e($k['to_name']) ?></b> <span class="hint">· <?= e($k['reason']) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
</div>

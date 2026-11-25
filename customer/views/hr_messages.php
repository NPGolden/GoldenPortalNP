<?php
/** HR › HR messages — the pool: every conversation between an employee and HR in one list, the ones to answer first (GAS-CP-R97-245). */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr_msg_has') || !hr_msg_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.msg_pool'), 'hr_messages'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = (string)($_GET['f'] ?? ''); if (!in_array($f, ['wait', 'unread', 'all'], true)) $f = ''; $qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$pid = (int)($_GET['pid'] ?? 0); $pp = $pid ? hr_person($pid) : null; if (!$pp) $pid = 0;
$all = hr_message_threads(); $nWait = count(array_filter($all, fn($r) => $r['wait'])); $nUnread = count(array_filter($all, fn($r) => $r['unread'] > 0));
if ($f === '') $f = $nWait && !$pid ? 'wait' : 'all';
$rows = array_values(array_filter($all, function ($r) use ($f, $qs) {
    if ($f === 'wait' && !$r['wait']) return false; if ($f === 'unread' && !$r['unread']) return false;
    if ($qs !== '' && mb_stripos($r['name_en'] . ' ' . $r['name_el'], $qs) === false && (!function_exists('hr_translit') || mb_stripos(hr_translit((string)$r['name_el']), $qs) === false)) return false; return true; }));
$card = $pid ? hr_messages_card($pid, true, 'pool') : '';   // reading it marks the employee's messages as read
$waitNow = $pid ? (bool) array_filter($all, fn($r) => (int)$r['person_id'] === $pid && $r['wait']) : false;
layout_head(t('hr.msg_pool'), 'hr_messages');
$lnk = fn($k) => url('hr_messages', ['f' => $k]);
?>
<div class="page-head"><div><h1>💬 <?= tt('hr.msg_pool') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hr.msg_pool_lede') ?></p></div>
  <form method="get" action="index.php" class="inline" style="display:flex;gap:6px;flex-wrap:wrap;"><input type="hidden" name="p" value="hr_messages"><select name="pid" data-js-submit="1"><option value="">＋ <?= tt('hr.msg_new_conv') ?> …</option><?php foreach (hr_people() as $x): if (empty($x['user_id'])) continue; ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name_en']) ?></option><?php endforeach; ?></select></form></div>
<div class="kpi kpi--3" style="margin-bottom:12px;">
  <a class="stat<?= $nWait ? ' gold' : '' ?>" href="<?= $lnk('wait') ?>" style="text-decoration:none;<?= $f === 'wait' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= $nWait ?></b><span class="s"><?= tt('hr.msg_f_wait') ?></span></a>
  <a class="stat<?= $nUnread ? ' red' : '' ?>" href="<?= $lnk('unread') ?>" style="text-decoration:none;<?= $f === 'unread' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= $nUnread ?></b><span class="s"><?= tt('hr.msg_f_unread') ?></span></a>
  <a class="stat" href="<?= $lnk('all') ?>" style="text-decoration:none;<?= $f === 'all' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= count($all) ?></b><span class="s"><?= tt('hr.msg_f_all') ?></span></a>
</div>
<?php if ($pid): ?>
<p style="margin:0 0 8px;"><a class="btn btn--ghost btn--sm" href="<?= url('hr_messages', ['f' => $f]) ?>">← <?= tt('hr.msg_back') ?></a> <b style="margin-left:8px;"><?= e($pp['name_en']) ?></b><?= $pp['name_el'] ? ' <span class="hint">' . e($pp['name_el']) . '</span>' : '' ?>
  <?php if (function_exists('hr3_has') && hr3_has()): ?><a class="btn btn--ghost btn--sm" style="margin-left:8px;" href="<?= url('hr_person', ['id' => $pid]) ?>">🪪 <?= tt('hr.msg_open_card') ?></a><?php endif; ?></p>
<?= $card ?>
<?php if ($waitNow && hr_msg_done_has()): ?><form method="post" action="<?= url('hr_messages') ?>" data-lock style="margin:-6px 0 14px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_msg_done"><input type="hidden" name="person_id" value="<?= $pid ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="btn btn--ghost btn--sm" type="submit">✔ <?= tt('hr.msg_done') ?></button> <span class="hint"><?= tt('hr.msg_done_hint') ?></span></form><?php endif; ?>
<?php endif; ?>
<div class="card">
  <form method="get" action="index.php" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;"><input type="hidden" name="p" value="hr_messages"><input type="hidden" name="f" value="<?= e($f) ?>"><input name="q" value="<?= e($qs) ?>" maxlength="60" placeholder="<?= tt('hr.msg_search') ?>" style="max-width:240px;"><button class="btn btn--ghost btn--sm" type="submit">🔎</button><?php if ($qs !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= url('hr_messages', ['f' => $f]) ?>">✖</a><?php endif; ?></form>
  <?php if (!$rows): ?><p class="hint"><?= tt('hr.msg_pool_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.msg_th_who') ?></th><th><?= tt('hr.msg_th_last') ?></th><th><?= tt('hr.msg_th_when') ?></th><th class="num"><?= tt('hr.msg_th_n') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $open = url('hr_messages', ['pid' => (int)$r['person_id'], 'f' => $f]) . '#hr-msg'; ?>
  <tr<?= (int)$r['person_id'] === $pid ? ' style="background:rgba(201,162,39,.10);"' : '' ?>><td><a href="<?= $open ?>"><b><?= e($r['name_en']) ?></b></a><?= $r['name_el'] ? '<br><span class="hint">' . e($r['name_el']) . '</span>' : '' ?><?= $r['is_active'] ? '' : ' ' . pill('mute', t('hr.f_left')) ?></td>
    <td><?= $r['unread'] ? pill('bad', t('hr.msg_new_pill', ['n' => $r['unread']])) . ' ' : '' ?><?= $r['wait'] ? pill('gold', t('hr.msg_wait_pill')) . ' ' : '' ?><span class="hint" style="display:inline;"><?= $r['last_from_hr'] ? 'HR: ' : '' ?></span><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $r['last_body']), 0, 140, '…')) ?></td>
    <td class="nowrap"><?= e(fmt_datetime($r['last_at'])) ?></td><td class="num"><?= (int)$r['n'] ?></td>
    <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= $open ?>"><?= tt('btn.open') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php layout_foot();

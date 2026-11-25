<?php
/** HR › HR messages — the pool: every conversation between an employee and HR in one list, the ones to answer first (GAS-CP-R97-245). */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('hr_msg_has') || !hr_msg_has() || !hr_can()) { http_response_code(403); layout_head(t('hr.msg_pool'), 'hr_messages'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$f = (string)($_GET['f'] ?? ''); if (!in_array($f, ['wait', 'unread', 'closed', 'all'], true)) $f = 'wait'; $qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);   // R97-249: the list opens on the open conversations; answered or closed ones are under «Closed» $qs = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$pid = (int)($_GET['pid'] ?? 0); $pp = $pid ? hr_person($pid) : null; if (!$pp) $pid = 0;
$all = hr_message_threads(); $nWait = count(array_filter($all, fn($r) => $r['wait'])); $nUnread = count(array_filter($all, fn($r) => $r['unread'] > 0));
$nClosed = count($all) - $nWait; $hrT = function_exists('td_hr_col') && td_hr_col(); $tasks = $hrT ? td_hr_list() : []; $tDone = $hrT && !empty($_GET['tdone']) ? td_hr_list(true) : []; $tByP = []; foreach ($tasks as $tk) if ($tk['hr_person_id']) $tByP[(int)$tk['hr_person_id']] = ($tByP[(int)$tk['hr_person_id']] ?? 0) + 1; $hrM = $hrT ? all('SELECT id, full_name FROM customer_users WHERE is_active = 1 AND id IN (' . (implode(',', hr_hr_members()) ?: '0') . ') ORDER BY full_name') : [];
$rows = array_values(array_filter($all, function ($r) use ($f, $qs) {
    if ($f === 'wait' && !$r['wait']) return false; if ($f === 'unread' && !$r['unread']) return false; if ($f === 'closed' && $r['wait']) return false;
    if ($qs !== '' && mb_stripos($r['name_en'] . ' ' . $r['name_el'], $qs) === false && (!function_exists('hr_translit') || mb_stripos(hr_translit((string)$r['name_el']), $qs) === false)) return false; return true; }));
$card = $pid ? hr_messages_card($pid, true, 'pool') : '';   // reading it marks the employee's messages as read
$waitNow = $pid ? (bool) array_filter($all, fn($r) => (int)$r['person_id'] === $pid && $r['wait']) : false;
layout_head(t('hr.msg_pool'), 'hr_messages');
$lnk = fn($k) => url('hr_messages', ['f' => $k]);
?>
<div class="page-head"><div><h1>💬 <?= tt('hr.msg_pool') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hr.msg_pool_lede') ?></p></div>
  <form method="get" action="index.php" class="inline" style="display:flex;gap:6px;flex-wrap:wrap;"><input type="hidden" name="p" value="hr_messages"><select name="pid" data-js-submit="1"><option value="">＋ <?= tt('hr.msg_new_conv') ?> …</option><?php foreach (hr_people() as $x): if (empty($x['user_id'])) continue; ?><option value="<?= (int)$x['id'] ?>"><?= e($x['name_en']) ?></option><?php endforeach; ?></select></form></div>
<div class="kpi kpi--4" style="margin-bottom:12px;">
  <a class="stat<?= $nWait ? ' gold' : '' ?>" href="<?= $lnk('wait') ?>" style="text-decoration:none;<?= $f === 'wait' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= $nWait ?></b><span class="s"><?= tt('hr.msg_f_wait') ?></span></a>
  <a class="stat<?= $nUnread ? ' red' : '' ?>" href="<?= $lnk('unread') ?>" style="text-decoration:none;<?= $f === 'unread' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= $nUnread ?></b><span class="s"><?= tt('hr.msg_f_unread') ?></span></a>
  <a class="stat" href="<?= $lnk('closed') ?>" style="text-decoration:none;<?= $f === 'closed' ? 'outline:2px solid #C9A227;' : '' ?>"><b><?= $nClosed ?></b><span class="s"><?= tt('hr.msg_f_closed') ?> · <?= e(t('hr.msg_f_all_n', ['n' => count($all)])) ?></span></a>
  <?php if ($hrT): $tOver = count(array_filter($tasks, fn($x) => $x['due_at'] < date('Y-m-d H:i:s'))); ?><a class="stat<?= $tOver ? ' red' : '' ?>" href="#hr-tasks" style="text-decoration:none;"><b><?= count($tasks) ?></b><span class="s">📌 <?= tt('td.hr_title') ?><?= $tOver ? ' · ' . e(t('td.hr_over', ['n' => $tOver])) : '' ?></span></a><?php endif; ?>
</div>
<?php if ($pid): ?>
<p style="margin:0 0 8px;"><a class="btn btn--ghost btn--sm" href="<?= url('hr_messages', ['f' => $f]) ?>">← <?= tt('hr.msg_back') ?></a> <b style="margin-left:8px;"><?= e($pp['name_en']) ?></b><?= $pp['name_el'] ? ' <span class="hint">' . e($pp['name_el']) . '</span>' : '' ?>
  <?php if (function_exists('hr3_has') && hr3_has()): ?><a class="btn btn--ghost btn--sm" style="margin-left:8px;" href="<?= url('hr_person', ['id' => $pid]) ?>">🪪 <?= tt('hr.msg_open_card') ?></a><?php endif; ?></p>
<?= $card ?>
<?php if ($waitNow && hr_msg_done_has()): ?><form method="post" action="<?= url('hr_messages') ?>" data-lock style="margin:-6px 0 14px;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_msg_done"><input type="hidden" name="person_id" value="<?= $pid ?>"><input type="hidden" name="f" value="<?= e($f) ?>"><button class="btn btn--ghost btn--sm" type="submit">✔ <?= tt('hr.msg_done') ?></button> <span class="hint"><?= tt('hr.msg_done_hint') ?></span></form><?php endif; ?>
<?php endif; ?>
<div class="card">
  <form method="get" action="index.php" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;"><input type="hidden" name="p" value="hr_messages"><input type="hidden" name="f" value="<?= e($f) ?>"><input name="q" value="<?= e($qs) ?>" maxlength="60" placeholder="<?= tt('hr.msg_search') ?>" style="max-width:240px;"><button class="btn btn--ghost btn--sm" type="submit">🔎</button><?php if ($qs !== ''): ?><a class="btn btn--ghost btn--sm" href="<?= url('hr_messages', ['f' => $f]) ?>">✖</a><?php endif; ?></form>
  <?php if (!$rows): ?><p class="hint"><?= tt($f === 'wait' ? 'hr.msg_open_none' : 'hr.msg_pool_none') ?> <?php if ($f !== 'all'): ?><a href="<?= $lnk('all') ?>"><?= tt('hr.msg_f_all') ?></a><?php endif; ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hr.msg_th_who') ?></th><th><?= tt('hr.msg_th_last') ?></th><th><?= tt('hr.msg_th_when') ?></th><th class="num"><?= tt('hr.msg_th_n') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $open = url('hr_messages', ['pid' => (int)$r['person_id'], 'f' => $f]) . '#hr-msg'; ?>
  <tr<?= (int)$r['person_id'] === $pid ? ' style="background:rgba(201,162,39,.10);"' : '' ?>><td><a href="<?= $open ?>"><b><?= e($r['name_en']) ?></b></a><?= $r['name_el'] ? '<br><span class="hint">' . e($r['name_el']) . '</span>' : '' ?><?= $r['is_active'] ? '' : ' ' . pill('mute', t('hr.f_left')) ?></td>
    <td><?= $r['unread'] ? pill('bad', t('hr.msg_new_pill', ['n' => $r['unread']])) . ' ' : '' ?><?= $r['wait'] ? pill('gold', t('hr.msg_wait_pill')) . ' ' : pill('mute', t('hr.msg_closed_pill')) . ' ' ?><?= !empty($tByP[(int)$r['person_id']]) ? pill('blue', '📌 ' . $tByP[(int)$r['person_id']]) . ' ' : '' ?><span class="hint" style="display:inline;"><?= $r['last_from_hr'] ? 'HR: ' : '' ?></span><?= e(mb_strimwidth(preg_replace('/\s+/u', ' ', $r['last_body']), 0, 140, '…')) ?></td>
    <td class="nowrap"><?= e(fmt_datetime($r['last_at'])) ?></td><td class="num"><?= (int)$r['n'] ?></td>
    <td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= $open ?>"><?= tt('btn.open') ?></a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php if ($hrT): $now = date('Y-m-d H:i:s'); $hid = fn() => csrf_field() . '<input type="hidden" name="back_pid" value="' . $pid . '">'; /* R97-249: HR tasks — seen by HR only */ ?>
<div class="card" id="hr-tasks"><div class="card-head"><h2>📌 <?= tt('td.hr_title') ?> <span class="count"><?= count($tasks) ?></span></h2><span class="hint"><?= tt('td.hr_lede') ?></span></div>
  <?php if (!$tasks): ?><p class="hint"><?= tt('td.hr_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--compact"><thead><tr><th style="width:36px;"></th><th><?= tt('td.hr_th_task') ?></th><th><?= tt('hr.msg_th_who') ?></th><th><?= tt('td.hr_th_due') ?></th><th><?= tt('td.hr_th_for') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($tasks as $tk): $over = $tk['due_at'] < $now; ?><tr<?= $pid && (int)$tk['hr_person_id'] === $pid ? ' style="background:rgba(201,162,39,.10);"' : '' ?>>
    <td><form method="post" action="<?= url('hr_messages') ?>" data-lock><?= $hid() ?><input type="hidden" name="action" value="hr_task_done"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('td.done') ?>" aria-label="<?= tt('td.done') ?>">✔</button></form></td>
    <td><b><?= e($tk['title']) ?></b><?= $tk['priority'] === 'high' ? ' ' . pill('bad', t('td.hr_high')) : '' ?><?= $tk['notes'] ? '<br><span class="hint" style="white-space:pre-wrap;">' . e((string)$tk['notes']) . '</span>' : '' ?></td>
    <td><?= $tk['hr_person_id'] ? '<a href="' . url('hr_messages', ['pid' => (int)$tk['hr_person_id']]) . '#hr-msg">' . e((string)$tk['person']) . '</a>' : '<span class="hint">—</span>' ?></td>
    <td class="nowrap"><?= $over ? pill('bad', fmt_date(substr((string)$tk['due_at'], 0, 10))) : e(fmt_date(substr((string)$tk['due_at'], 0, 10))) ?></td>
    <td><?= e((string)($tk['assignee'] ?: t('td.hr_all'))) ?><br><span class="hint"><?= e(t('td.hr_by', ['u' => (string)$tk['owner']])) ?></span></td>
    <td class="row-actions"><form method="post" action="<?= url('hr_messages') ?>" data-lock data-confirm="<?= tt('td.hr_del_q') ?>"><?= $hid() ?><input type="hidden" name="action" value="hr_task_delete"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('btn.delete') ?>">🗑</button></form></td>
  </tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <details style="margin-top:10px;"<?= $pid || !$tasks ? ' open' : '' ?>><summary class="btn btn--ghost btn--sm" style="display:inline-block;cursor:pointer;">＋ <?= tt('td.hr_new') ?></summary>
  <form method="post" action="<?= url('hr_messages') ?>" data-lock class="form-grid" style="margin-top:10px;"><?= $hid() ?><input type="hidden" name="action" value="hr_task_add">
    <div class="field span2"><label><?= tt('td.hr_th_task') ?> *</label><input name="title" maxlength="200" required<?= $pp ? ' value="' . e(t('td.hr_title_for', ['n' => (string)$pp['name_en']])) . '"' : '' ?>></div>
    <div class="field"><label><?= tt('hr.msg_th_who') ?></label><select name="person_id"><option value="">—</option><?php foreach (hr_people() as $x): ?><option value="<?= (int)$x['id'] ?>"<?= $pid === (int)$x['id'] ? ' selected' : '' ?>><?= e($x['name_en']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('td.hr_th_due') ?></label><input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+1 day')) ?>" required></div>
    <div class="field"><label><?= tt('td.hr_th_for') ?></label><select name="assignee_id" data-nosearch><option value=""><?= tt('td.hr_all') ?></option><?php foreach ($hrM as $x): ?><option value="<?= (int)$x['id'] ?>"<?= (int)$x['id'] === (int)$u['id'] ? ' selected' : '' ?>><?= e($x['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('td.hr_prio') ?></label><select name="priority" data-nosearch><option value="normal"><?= tt('td.hr_normal') ?></option><option value="high"><?= tt('td.hr_high') ?></option></select></div>
    <div class="field span2"><label><?= tt('hr.notes') ?></label><textarea name="notes" rows="2" maxlength="4000" data-plain></textarea></div>
    <div class="btn-row span2"><button class="btn btn--gold btn--sm" type="submit"><?= tt('td.hr_add') ?></button></div>
  </form></details>
  <?php if (!empty($_GET['tdone'])): ?><h3 style="margin:14px 0 6px;"><?= e(t('td.hr_done_h', ['n' => 30])) ?></h3><?php if (!$tDone): ?><p class="hint"><?= tt('td.hr_none') ?></p><?php else: ?><ul class="log"><?php foreach ($tDone as $tk): ?><li><form method="post" action="<?= url('hr_messages') ?>" data-lock style="display:inline;"><?= $hid() ?><input type="hidden" name="action" value="hr_task_reopen"><input type="hidden" name="tdone" value="1"><input type="hidden" name="id" value="<?= (int)$tk['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">↩</button></form> <b><?= e($tk['title']) ?></b><?= $tk['person'] ? ' · ' . e((string)$tk['person']) : '' ?> <span class="hint" style="display:inline;">✔ <?= e(fmt_datetime((string)$tk['done_at'])) ?></span></li><?php endforeach; ?></ul><?php endif; ?>
  <?php else: ?><p class="hint" style="margin:10px 0 0;"><a href="<?= url('hr_messages', ['pid' => $pid ?: null, 'f' => $f, 'tdone' => 1]) ?>#hr-tasks"><?= e(t('td.hr_done_h', ['n' => 30])) ?> ›</a></p><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();

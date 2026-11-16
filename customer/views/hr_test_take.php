<?php
/** Assessment form — the person's own answers, or the rating by the head of the department.   GAS-CP-R97-063   ?p=hr_test_take&id=[&form=mgr[&pid=]] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $me = (int) $u['id']; $id = (int) ($_GET['id'] ?? 0); $isM = ($_GET['form'] ?? '') === 'mgr'; $pid = (int) ($_GET['pid'] ?? 0);
$c = function_exists('hrs_has') && hrs_has() ? hrs_campaign($id) : null; $F = $c['F'] ?? []; $t = null; $mine = [];
if ($c && $isM) { foreach (hrs_targets($id) as $x) if ((int) $x['rater_user_id'] === $me) $mine[] = $x; if ($pid) foreach ($mine as $x) if ((int) $x['person_id'] === $pid) $t = $x; }
elseif ($c) { $t = one('SELECT * FROM hrs_targets WHERE campaign_id = ? AND user_id = ?', [$id, $me]); }
$ok = $c && ($isM ? (!empty($F['set']['manager_form']) && $mine && (!$pid || $t)) : (bool) $t);
if (!$ok) { http_response_code(403); layout_head(t('hrs.title'), 'my_leave'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$title = hrs_tx($F['title']); $running = $c['status'] === 'running';
layout_head($title, 'my_leave');

if ($isM && !$t): /* ---------- the people I rate ---------- */ ?>
<div class="page-head"><div><h1>🧭 <?= e($title) ?></h1><p class="lede"><?= e(t('hrs.rate_lede', ['d' => fmt_date($c['ends_on'])])) ?></p></div></div>
<div class="card">
  <table class="tbl"><thead><tr><th><?= tt('hr.person') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($mine as $x): ?><tr><td><b><?= e($x['name_en']) ?></b><br><span class="hint"><?= e((string) ($x['department'] ?? '')) ?></span></td>
    <td><?= pill(['done' => 'good', 'started' => 'gold', 'missed' => 'warn'][$x['mgr_status']] ?? 'mute', t('hrs.ts_' . $x['mgr_status'])) ?></td>
    <td><?php if ($running && $x['mgr_status'] !== 'done'): ?><a class="btn btn--gold btn--sm" href="<?= url('hr_test_take', ['id' => $id, 'form' => 'mgr', 'pid' => (int) $x['person_id']]) ?>"><?= tt($x['mgr_status'] === 'started' ? 'hrs.continue' : 'hrs.rate') ?></a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table>
  <p class="hint"><?= tt('hrs.rate_hint') ?></p>
</div>
<?php layout_foot(); return; endif;

$form = $isM ? 'M' : 'S'; $status = $isM ? $t['mgr_status'] : $t['self_status'];
if (!$running || $status === 'done'): ?>
<div class="page-head"><div><h1>🧭 <?= e($title) ?></h1></div></div>
<div class="card"><p><?= tt($status === 'done' ? ($isM ? 'hrs.f_thanks_mgr' : 'hrs.f_done_wait') : 'hrs.e_not_open') ?></p>
  <?php if (!$isM && $c['status'] === 'closed' && hrs_may_see($c, $t)): ?><a class="btn btn--gold" href="<?= url('hr_test_result', ['id' => $id, 'pid' => (int) $t['person_id']]) ?>"><?= tt('hrs.open_result') ?></a><?php endif; ?></div>
<?php layout_foot(); return; endif;

if (!$isM) $_SESSION['hrs_t' . (int) $t['id']] = time();
$ans = json_decode((string) ($isM ? $t['mgr_answers'] : $t['answers']), true) ?: []; $order = hrs_order($F, $form, (int) $t['id']); $miss = isset($_GET['miss']) ? hrs_missing($F, $form, $ans) : [];
$who = $isM ? (hr_person((int) $t['person_id'])['name_en'] ?? '') : ''; $lim = (int) ($F['set']['time_limit_min'] ?? 0); $n = 0;
?>
<div class="page-head"><div><h1>🧭 <?= e($title) ?><?= $isM ? ' — ' . e($who) : '' ?></h1>
  <p class="lede"><?= e($isM ? t('hrs.f_mgr_lede', ['n' => $who]) : hrs_tx($F['intro'])) ?></p></div></div>
<form method="post" action="<?= url('hr_test_take', ['id' => $id] + ($isM ? ['form' => 'mgr', 'pid' => (int) $t['person_id']] : [])) ?>" data-lock id="hrs-form"><?= csrf_field() ?>
  <input type="hidden" name="action" value="hrs_answer"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="pid" value="<?= (int) $t['person_id'] ?>"><input type="hidden" name="form" value="<?= $isM ? 'mgr' : 'self' ?>">
  <div class="card" style="padding:8px 14px;">
    <span><b id="hrs-done"><?= count(array_intersect(array_keys($ans), $order)) ?></b> / <?= count($order) ?> <?= tt('hrs.f_answered') ?></span>
    <span class="hint" style="display:inline;margin-left:10px;"><?= e(t('hrs.f_until', ['d' => fmt_date($c['ends_on'])])) ?><?= !$isM && $lim ? ' · ' . e(t('hrs.set_time_v', ['n' => $lim])) : '' ?></span>
  </div>
  <?php foreach ($order as $no): $q = $F['q'][$no]; $n++; $cur = $ans[$no] ?? null; $bad = in_array($no, $miss, true); ?>
  <div class="card hrs-q" style="<?= $bad ? 'border-left:4px solid #b3261e;' : '' ?>">
    <p style="margin:0 0 8px;"><span class="hint" style="display:inline;"><?= $n ?>.</span> <b><?= e(hrs_tx($q['tx'])) ?></b><?= $q['req'] ? '' : ' <span class="hint" style="display:inline;">(' . tt('hrs.f_optional') . ')</span>' ?></p>
    <?php if (in_array($q['t'], ['LIKERT', 'CHECK'], true)): ?>
    <div style="display:flex;flex-wrap:wrap;gap:6px 16px;"><?php foreach ($F['scale'] as $v => $lab): ?><label style="display:flex;gap:6px;align-items:center;font-weight:400;"><input type="radio" name="a[<?= (int) $no ?>]" value="<?= (int) $v ?>"<?= (int) $cur === (int) $v ? ' checked' : '' ?> style="width:17px;height:17px;"> <?= e(hrs_tx($lab)) ?></label><?php endforeach; ?></div>
    <?php else: ?>
    <div style="display:grid;gap:6px;"><?php foreach ($q['o'] as $l => $o): ?><label style="display:flex;gap:8px;align-items:flex-start;font-weight:400;"><input type="radio" name="a[<?= (int) $no ?>]" value="<?= e($l) ?>"<?= (string) $cur === (string) $l ? ' checked' : '' ?> style="width:17px;height:17px;margin-top:2px;"> <span><?= e(hrs_tx($o['tx'])) ?></span></label><?php endforeach; ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <div class="card">
    <p class="hint"><?= tt($isM ? 'hrs.f_mgr_hint' : 'hrs.f_hint') ?></p>
    <p><b id="hrs-done2"><?= count(array_intersect(array_keys($ans), $order)) ?></b> / <?= count($order) ?> <?= tt('hrs.f_answered') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit" name="submit" value="1" data-confirm="<?= e(t('hrs.f_submit_confirm')) ?>"><?= tt('hrs.f_submit') ?></button><button class="btn btn--ghost" type="submit"><?= tt('hrs.f_save') ?></button></div>
  </div>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){var f=document.getElementById('hrs-form'),d=document.getElementById('hrs-done');if(!f||!d)return;f.addEventListener('change',function(){var s={};f.querySelectorAll('input[type=radio]:checked').forEach(function(r){s[r.name]=1;});d.textContent=Object.keys(s).length;var d2=document.getElementById('hrs-done2');if(d2)d2.textContent=d.textContent;});})();</script>
<?php layout_foot(); ?>

<?php
/** HR › Job description — view, write, approve, assign, print (GAS-CP-R97-121).
 *  ?p=hr_jd&id=ID[&edit=1] · ?p=hr_jd&new=1 · ?p=hr_jd&id=ID&format=pdf[&draft=1][&person=ID] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $isNew = !$id && isset($_GET['new']);
$deny = function (int $code, string $msg) { http_response_code($code); layout_head(t('jd.title'), 'hr_jds'); echo '<h1>' . e($msg) . '</h1>'; layout_foot(); };
if (!function_exists('jd_has') || !jd_has()) { $deny(404, t('jd.need_db')); return; }
$jd = $id ? jd_load($id) : null; if ($id && !$jd) { $deny(404, t('err.notfound')); return; }
if (!$id && !$isNew) redirect('hr_jds');
$manage = jd_manage(); if (($jd && !jd_can_view($jd)) || ($isNew && !$manage)) { $deny(403, t('err.noaccess')); return; }
$self = url('hr_jd', ['id' => $id]); $cur = $jd ? jd_current($jd) : null; $revs = $jd ? jd_revs($id) : [];
$mineOnly = $jd && !$manage && !jd_is_gm() && !jd_is_ceo() && !jd_holds($jd, 'dm');   // the employee the description is assigned to: the issue in force only
if ($jd && ($_GET['format'] ?? '') === 'pdf') {
    $draft = !empty($_GET['draft']) && !$mineOnly; $c = $draft ? jd_content($jd) : $cur;
    if ($c) {
        $person = null; $ack = null; if (!empty($_GET['person']) && $manage && function_exists('hr_person') && ($pp = hr_person((int)$_GET['person'])) && (int)($pp['jd_id'] ?? scalar('SELECT jd_id FROM hr_people WHERE id = ?', [(int)$pp['id']])) === $id) { $person = $pp; $ack = one('SELECT * FROM hr_jd_acks WHERE jd_id = ? AND person_id = ? AND rev = ?', [$id, (int)$pp['id'], (int)$c['rev']]) ?: null; }
        $b = jd_pdf($c, $revs, $person, $ack); while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/pdf'); header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline') . '; filename="' . jd_doc_no($c) . '_R' . (int)$c['rev'] . '.pdf"'); header('Content-Length: ' . strlen($b)); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff'); echo $b; exit;
    }
}
$edit = $manage && ($isNew || (isset($_GET['edit']) && $jd && $jd['status'] === 'draft'));
layout_head($jd ? $jd['code'] . ' · ' . $jd['title'] : t('jd.new'), 'hr_jds');
?>
<p class="eyebrow"><a href="<?= url('hr_jds') ?>"><?= tt('jd.title') ?></a> › <?= $jd ? e($jd['code']) : tt('jd.new') ?></p>
<?php if ($edit):
  $v = $jd ?: ['title' => '', 'discipline_id' => (int)($_GET['discipline'] ?? 0), 'level' => '', 'reports_to' => '', 'purpose' => '']; foreach (JD_SECTIONS as $k) $v[$k] = $v[$k] ?? '';
  $aiPage = jd_ai_page(); $aiD = $aiPage ? ($_SESSION['jd_ai'][$id] ?? null) : null; $aiM = $_SESSION['jd_ai_msg'] ?? null; unset($_SESSION['jd_ai_msg']);
  if ($aiD) foreach ($aiD['fields'] as $k => $val) if (array_key_exists($k, $v) || in_array($k, JD_SECTIONS, true)) $v[$k] = $val;
  $depts = function_exists('hr_departments') ? hr_departments() : []; $hid = array_merge(['title', 'discipline_id', 'level', 'reports_to', 'purpose'], JD_SECTIONS); ?>
<h1><?= $jd ? '✎ ' . e($jd['code']) . ' · ' . e(jd_rev($jd)) : '＋ ' . tt('jd.new') ?></h1>
<?php if ($jd && $jd['reject_note']): ?><div class="flash flash--bad"><b><?= tt('jd.returned_by') ?></b> <?= e((string)$jd['reject_note']) ?></div><?php endif; ?>
<div class="card" id="jd-ai"><h2>✨ <?= tt('jd.ai_title') ?></h2>
  <?php if (!$aiPage): ?><p class="hint"><?= tt('jd.ai_off') ?></p><?php else: ?><p class="hint"><?= tt('jd.ai_hint') ?></p>
  <?php if ($aiM && $aiM['err'] !== ''): ?><p class="flash flash--bad"><?= e($aiM['err']) ?></p><?php elseif ($aiM && $aiM['a'] !== ''): ?><p class="flash flash--good"><?= e($aiM['a']) ?></p><?php endif; ?>
  <?php if ($aiD): ?><p class="flash flash--info"><?= tt('jd.ai_prefilled') ?></p><?php endif; ?>
  <form method="post" action="<?= $isNew ? url('hr_jd', ['new' => 1]) : $self ?>" data-lock data-busy="…" id="jd-ai-form"><?= csrf_field() ?><input type="hidden" name="action" value="jd_ai"><input type="hidden" name="id" value="<?= $id ?>">
    <?php foreach ($hid as $k): ?><input type="hidden" name="<?= $k ?>" data-from="<?= $k ?>" value="<?= e((string)($v[$k] ?? '')) ?>"><?php endforeach; ?>
    <div class="field"><textarea name="brief" rows="3" maxlength="2000" data-plain placeholder="<?= e(t('jd.ai_ph')) ?>"><?= e((string)($aiM['brief'] ?? '')) ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('jd.ai_btn') ?></button></div>
  </form>
  <?php if ($aiD): ?><form method="post" class="inline" style="margin-top:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="jd_ai_clear"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('jd.ai_discard') ?></button></form><?php endif; ?>
  <?php endif; ?>
</div>
<form method="post" action="<?= $isNew ? url('hr_jd', ['new' => 1]) : $self ?>" class="card" data-lock id="jd-form"><?= csrf_field() ?><input type="hidden" name="action" value="jd_save"><input type="hidden" name="id" value="<?= $id ?>">
  <h2><?= tt('jd.header') ?></h2>
  <div class="form-grid">
    <div class="field"><label><?= tt('jd.job_title') ?> *</label><input name="title" required maxlength="190" value="<?= e((string)$v['title']) ?>"></div>
    <div class="field"><label><?= tt('jd.discipline') ?></label><select name="discipline_id"><option value=""></option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>"<?= (int)($v['discipline_id'] ?? 0) === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?><?= !empty($d['head_name']) ? ' — ' . e($d['head_name']) : '' ?></option><?php endforeach; ?></select><p class="hint"><?= tt('jd.discipline_hint') ?></p></div>
    <div class="field"><label><?= tt('jd.level') ?></label><select name="level" data-nosearch><?php foreach (JD_LEVELS as $lv): ?><option value="<?= $lv ?>"<?= (string)$v['level'] === $lv ? ' selected' : '' ?>><?= e(jd_level_label($lv)) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('jd.reports_to') ?></label><input name="reports_to" maxlength="190" value="<?= e((string)($v['reports_to'] ?? '')) ?>"><p class="hint"><?= tt('jd.reports_hint') ?></p></div>
    <div class="field field--full"><label><?= tt('jd.s_purpose') ?></label><textarea name="purpose" rows="4" maxlength="3000" data-plain><?= e((string)($v['purpose'] ?? '')) ?></textarea></div>
    <?php foreach (JD_SECTIONS as $k): ?><div class="field<?= in_array($k, ['duties'], true) ? ' field--full' : '' ?>"><label><?= tt('jd.s_' . $k) ?> <span class="hint"><?= tt('jd.one_per_line') ?></span></label><textarea name="<?= $k ?>" rows="<?= $k === 'duties' ? 10 : 6 ?>" data-plain><?= e((string)($v[$k] ?? '')) ?></textarea></div><?php endforeach; ?>
  </div>
  <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $jd ? $self : url('hr_jds') ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){var a=document.getElementById('jd-ai-form'),f=document.getElementById('jd-form');if(!a||!f)return;a.addEventListener('submit',function(){a.querySelectorAll('input[data-from]').forEach(function(h){var s=f.elements[h.getAttribute('data-from')];if(s)h.value=s.value;});});})();</script>
<?php else:
  $steps = jd_steps($jd); $next = jd_next_step($jd); $people = jd_people($id); $draftC = jd_content($jd); $showC = ($mineOnly || $jd['status'] === 'approved') ? $cur : $draftC; ?>
<div class="page-head"><div><h1>📄 <?= e($jd['title']) ?> <?= jd_state_pill((string)$jd['status']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($jd['code']) ?> · <?= e(jd_rev($jd)) ?> · <?= e($jd['discipline'] ?? '—') ?> · <?= e(jd_level_label((string)$jd['level'])) ?></p></div>
  <div class="btn-row">
    <?php if ($cur): ?><a class="btn btn--gold" target="_blank" rel="noopener" href="<?= url('hr_jd', ['id' => $id, 'format' => 'pdf']) ?>">⤓ PDF <?= $jd['status'] !== 'approved' ? e('R' . (int)$cur['rev']) : '' ?></a><?php endif; ?>
    <?php if (!$mineOnly && $jd['status'] !== 'approved'): ?><a class="btn btn--ghost" target="_blank" rel="noopener" href="<?= url('hr_jd', ['id' => $id, 'format' => 'pdf', 'draft' => 1]) ?>">⤓ <?= tt('jd.pdf_draft') ?></a><?php endif; ?>
    <?php if ($manage && $jd['status'] === 'draft'): ?><a class="btn btn--ghost" href="<?= url('hr_jd', ['id' => $id, 'edit' => 1]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?>
  </div></div>
<?php if (!$mineOnly): ?>
<?php if ($jd['status'] !== 'approved' && $cur): ?><div class="flash flash--info"><?= e(t('jd.force_note', ['r' => 'R' . (int)$cur['rev'], 'n' => jd_rev($jd)])) ?></div><?php endif; ?>
<?php if ($jd['status'] === 'draft' && $jd['reject_note']): ?><div class="flash flash--bad"><b><?= tt('jd.returned_by') ?></b> <?= e((string)$jd['reject_note']) ?></div><?php endif; ?>
<div class="card" id="jd-appr"><h2><?= tt('jd.s_approval') ?></h2>
  <div class="kpi kpi--3" style="margin-bottom:10px;"><?php foreach ($steps as $s): $who = $s['state'] === 'done' ? $s['name'] : ($s['users'] ? implode(', ', array_map(fn($x) => (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$x]), array_slice($s['users'], 0, 3))) : t('jd.step_nobody')); ?>
    <div class="stat<?= $s['state'] === 'done' ? ' green' : ($s['next'] ? ' gold' : '') ?>"><b><?= $s['state'] === 'done' ? '✓' : ($s['state'] === 'skip' ? '—' : ($s['next'] ? '…' : '·')) ?></b><?= e($s['label']) ?><span class="s"><?= e($who) ?><?= $s['at'] ? ' · ' . e(fmt_date(substr((string)$s['at'], 0, 10))) : '' ?></span></div><?php endforeach; ?></div>
  <?php if ($jd['status'] === 'draft' && $manage): ?>
    <form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('jd.submit_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="jd_submit"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold" type="submit"><?= tt('jd.submit') ?></button></form>
    <?php if ((int)$jd['rev'] === 0 && !$revs): ?><form method="post" action="<?= $self ?>" class="inline" data-lock data-confirm="<?= tt('jd.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="jd_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?>
    <p class="hint" style="margin-top:6px;"><?= tt('jd.submit_hint') ?></p>
  <?php elseif ($jd['status'] === 'review'): ?>
    <?php if ($next !== null && $steps[$next]['mine']): ?>
      <form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_approve"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold" type="submit">✓ <?= e(t('jd.approve_as', ['s' => $steps[$next]['label']])) ?></button></form>
    <?php else: ?><p class="hint"><?= e(t('jd.waiting', ['s' => $next !== null ? $steps[$next]['label'] : ''])) ?></p><?php endif; ?>
    <?php if (($next !== null && $steps[$next]['mine']) || jd_is_gm() || jd_is_ceo()): ?>
      <form method="post" action="<?= $self ?>" class="form-grid" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="jd_reject"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field field--full"><label><?= tt('jd.return_note') ?> *</label><input name="note" maxlength="500" required></div>
        <div class="btn-row"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('jd.return') ?></button></div></form>
    <?php endif; ?>
  <?php elseif ($manage): ?>
    <form method="post" action="<?= $self ?>" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_revise"><input type="hidden" name="id" value="<?= $id ?>">
      <div class="field field--full"><label><?= e(t('jd.rev_new', ['r' => 'R' . ((int)$jd['rev'] + 1)])) ?> *</label><input name="rev_title" maxlength="190" required placeholder="<?= e(t('jd.rev_ph')) ?>"><p class="hint"><?= tt('jd.rev_hint') ?></p></div>
      <div class="btn-row"><button class="btn btn--ghost btn--sm" type="submit">✎ <?= tt('jd.revise') ?></button></div></form>
    <form method="post" action="<?= $self ?>" class="inline" data-lock style="margin-top:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="jd_retire"><input type="hidden" name="id" value="<?= $id ?>"><?php if ($jd['status'] === 'retired'): ?><input type="hidden" name="undo" value="1"><?php endif; ?><button class="btn btn--ghost btn--sm" type="submit"><?= tt($jd['status'] === 'retired' ? 'jd.reinstate' : 'jd.retire') ?></button></form>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($showC): $jdC = $showC; $jdRevs = $revs; require __DIR__ . '/_jd_body.php'; else: ?><div class="empty"><?= tt('jd.mine_pending') ?></div><?php endif; ?>
<?php if ($manage): $all = function_exists('hr_people') ? hr_people(true) : []; $have = array_flip(array_map('intval', array_column($people, 'id'))); $ackOf = []; foreach ($people as $pp) $ackOf[(int)$pp['id']] = $pp;
  usort($all, fn($a, $b) => [(int)((int)($a['department_id'] ?? 0) !== (int)$jd['discipline_id'] && (int)($a['discipline_id'] ?? 0) !== (int)$jd['discipline_id']), (string)$a['name_en']] <=> [(int)((int)($b['department_id'] ?? 0) !== (int)$jd['discipline_id'] && (int)($b['discipline_id'] ?? 0) !== (int)$jd['discipline_id']), (string)$b['name_en']]); ?>
<div class="card" id="jd-people"><h2>👥 <?= tt('jd.people') ?> <span class="count" id="jdp-n"><?= count($have) ?></span></h2><p class="hint"><?= tt('jd.people_hint') ?></p>
  <form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_assign"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="field"><input type="search" id="jdp-f" class="tick-filter" placeholder="<?= tt('pmt.people_filter') ?>" autocomplete="off">
    <div class="tick-list" id="jdp-list"><?php foreach ($all as $hp): $pid_ = (int)$hp['id']; $same = $jd['discipline_id'] && ((int)($hp['department_id'] ?? 0) === (int)$jd['discipline_id'] || (int)($hp['discipline_id'] ?? 0) === (int)$jd['discipline_id']); $other = (int) ($hp['jd_id'] ?? 0); ?>
      <label class="chk" data-n="<?= e(mb_strtolower((string)$hp['name_en'] . ' ' . (string)($hp['department'] ?? ''))) ?>"><input type="checkbox" name="people[]" value="<?= $pid_ ?>"<?= isset($have[$pid_]) ? ' checked' : '' ?>> <span><?= e((string)$hp['name_en']) ?><?= $same ? ' ★' : '' ?><small class="hint"> <?= e((string)($hp['department'] ?? '')) ?><?= isset($have[$pid_]) && !empty($ackOf[$pid_]['acked_at']) && $cur && (int)$ackOf[$pid_]['ack_rev'] === (int)$cur['rev'] ? ' · ✓ ' . e(t('jd.read')) : (isset($have[$pid_]) ? ' · ' . e(t('jd.unread')) : ($other && $other !== $id ? ' · ' . e(t('jd.has_other')) : '')) ?></small></span></label><?php endforeach; ?></div></div>
    <div class="btn-row"><button class="btn btn--sm" type="submit"><?= tt('jd.people_save') ?></button></div>
  </form>
  <?php if ($people && $cur): ?><p class="hint" style="margin-top:8px;"><?= tt('jd.pdf_person') ?> <?php foreach ($people as $pp): ?><a href="<?= url('hr_jd', ['id' => $id, 'format' => 'pdf', 'person' => (int)$pp['id']]) ?>" target="_blank" rel="noopener"><?= e((string)$pp['name_en']) ?></a> · <?php endforeach; ?></p><?php endif; ?>
</div>
<script nonce="<?= csp_nonce() ?>">(function(){var box=document.getElementById('jdp-list');if(!box)return;var f=document.getElementById('jdp-f'),n=document.getElementById('jdp-n');box.addEventListener('change',function(){n.textContent=box.querySelectorAll('input:checked').length;});f.addEventListener('input',function(){var q=f.value.trim().toLowerCase();box.querySelectorAll('label').forEach(function(l){l.style.display=(!q||l.dataset.n.indexOf(q)>=0||l.querySelector('input').checked)?'':'none';});});})();</script>
<?php $vacs = []; try { $vacs = all('SELECT id, code, title, status FROM rc_vacancies WHERE jd_id = ? ORDER BY id DESC', [$id]); } catch (Throwable $e) {} ?>
<div class="card"><h2>🧲 <?= tt('jd.vacancies') ?> <span class="count"><?= count($vacs) ?></span></h2>
  <?php if ($vacs): ?><ul><?php foreach ($vacs as $vc): ?><li><a href="<?= url('rcr_vacancy', ['id' => (int)$vc['id']]) ?>"><?= e($vc['code'] . ' · ' . $vc['title']) ?></a> <span class="hint"><?= e(t('rc.st_' . $vc['status'])) ?></span></li><?php endforeach; ?></ul><?php else: ?><p class="hint"><?= tt('jd.vac_none') ?></p><?php endif; ?>
  <?php if ($cur && function_exists('rcr_can') && rcr_can()): ?><form method="post" action="<?= url('rcr_vacancy', ['new' => 1]) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="jd_vacancy"><input type="hidden" name="id" value="0"><input type="hidden" name="jd_id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">＋ <?= tt('jd.vac_new') ?></button></form><?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>
<?php layout_foot(); ?>

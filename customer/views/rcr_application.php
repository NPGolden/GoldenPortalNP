<?php
/** HR › Recruiting › Application (GAS-CP-R89-001): candidate card — screening answers, CV, events attended, pipeline decision, notes, log.  ?p=rc_application&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $a = $id ? rcr_application($id) : null;
if (!$a || !function_exists('rcr_has') || !rcr_has() || !rcr_can_view((int)$a['vacancy_id'])) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$set = rcr_set((int)(one('SELECT screening_set_id FROM rc_vacancies WHERE id = ?', [(int)$a['vacancy_id']])['screening_set_id'] ?? 0)); $qs = $set ? (json_decode((string)$set['questions'], true) ?: []) : []; $ans = json_decode((string)$a['answers'], true) ?: [];
$evs = rcr_application_events($id); $log = all('SELECT l.*, cu.full_name AS by_name FROM rc_log l LEFT JOIN customer_users cu ON cu.id = l.user_id WHERE l.application_id = ? ORDER BY l.created_at DESC LIMIT 100', [$id]);
$owners = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name");
layout_head($a['code'], 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <a href="<?= url('rcr_vacancy', ['id' => (int)$a['vacancy_id']]) ?>"><?= e($a['vacancy_code']) ?></a> › <?= e($a['code']) ?></p>
<div class="page-head"><div><h1><?= e((string)($a['name'] ?: $a['email'])) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($a['code']) ?> · <?= e($a['vacancy']) ?> · <?= pill(in_array($a['status'], ['hired', 'offer'], true) ? 'gold' : (in_array($a['status'], ['rejected', 'withdrawn'], true) ? 'grey' : 'navy'), t('rc.st_' . $a['status'])) ?> · <?= $a['passed'] ? pill('gold', tt('rc.passed') . ' ' . (int)$a['score'] . '/' . (int)$a['max_score']) : pill('grey', tt('rc.failed') . ' ' . (int)$a['score'] . '/' . (int)$a['max_score']) ?><?= !$a['knockout_ok'] ? ' ' . pill('grey', '⚑ ' . tt('rc.ko_hint')) : '' ?></p></div>
<div class="btn-row"><?php if ($a['cv_path']): ?><a class="btn btn--gold" href="<?= url('rcr_cv', ['id' => $id]) ?>" target="_blank">📎 CV</a><?php endif; ?><a class="btn btn--ghost" href="mailto:<?= e($a['email']) ?>">✉</a></div></div>
<div class="cols" style="grid-template-columns:2fr 1fr;"><div>
<div class="card"><h2><?= tt('rc.contact') ?></h2><div class="kv"><div><span><?= tt('rc.email') ?></span><b><?= e($a['email']) ?></b></div><div><span><?= tt('rc.phone') ?></span><b><?= e((string)$a['phone'] ?: '—') ?></b></div><div><span><?= tt('rc.applied') ?></span><b><?= e(fmt_datetime($a['created_at'])) ?></b></div><div><span><?= tt('rc.dept_interest') ?></span><b><?= e((string)$a['department_interest'] ?: '—') ?></b></div><div><span><?= tt('rc.source') ?></span><b><?= e($a['source']) ?> · <?= e($a['lang']) ?></b></div><div><span>GDPR</span><b><?= e((string)$a['consent_version']) ?> · <?= e(fmt_datetime($a['consent_at'])) ?></b></div></div>
<?php if ($a['cover_note']): ?><h3><?= tt('rc.cover_note') ?></h3><p><?= nl2br(e($a['cover_note'])) ?></p><?php endif; ?></div>
<div class="card"><h2><?= tt('rc.screening') ?> — <?= e((string)($set['code'] ?? '')) ?></h2><ol class="qa">
<?php foreach ($qs as $q): $pick = $ans[$q['id']] ?? null; ?><li><div><?= e($q['text']) ?></div><?php foreach ($q['options'] as $o): $sel = (string)$o['id'] === (string)$pick; ?><div class="<?= $sel ? ($o['score'] ? 'opt sel good' : 'opt sel bad') : 'opt' ?>"><?= $sel ? '●' : '○' ?> <?= e($o['text']) ?><?= $sel ? ' <span class="hint">(' . (int)$o['score'] . ')</span>' : '' ?></div><?php endforeach; ?></li><?php endforeach; ?></ol></div>
<?php if ($evs): ?><div class="card"><h2><?= tt('rc.events') ?></h2><table class="tbl"><tbody><?php foreach ($evs as $ev): ?><tr><td><a href="<?= url('rcr_event', ['id' => (int)$ev['event_id']]) ?>"><?= e($ev['title']) ?></a><br><span class="hint"><?= e(fmt_datetime($ev['starts_at'])) ?></span></td><td><?= $ev['attended'] === null ? '—' : ($ev['attended'] ? '✓ ' . tt('rc.attended') : '✗') ?></td><td><?= $ev['result'] ? pill($ev['result'] === 'strong' ? 'gold' : 'navy', tt('rc.res_' . $ev['result'])) : '' ?> <?= e((string)$ev['result_note']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</div><div>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_app_decide"><input type="hidden" name="id" value="<?= $id ?>"><h2><?= tt('rc.decision') ?></h2>
  <label><?= tt('th.status') ?><select name="status" data-nosearch><?php foreach (RCR_APP_STATUS as $s): ?><option value="<?= $s ?>"<?= $a['status'] === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('rc.rating') ?><select name="rating" data-nosearch><option value=""></option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)$a['rating'] === $i ? ' selected' : '' ?>><?= str_repeat('★', $i) ?></option><?php endfor; ?></select></label>
  <label><?= tt('rc.owner') ?><select name="owner_user_id"><option value=""></option><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"<?= (int)$a['owner_user_id'] === (int)$o['id'] ? ' selected' : '' ?>><?= e($o['full_name']) ?></option><?php endforeach; ?></select></label>
  <label><?= tt('hr.notes') ?><textarea name="hr_notes" rows="6"><?= e((string)$a['hr_notes']) ?></textarea></label>
  <button class="btn btn--gold"><?= tt('btn.save') ?></button>
  <?php if ($a['hired_person_id']): ?><p class="hint">→ <a href="<?= url('hr_person', ['id' => (int)$a['hired_person_id']]) ?>"><?= tt('rc.person_card') ?></a></p><?php endif; ?>
</form>
<div class="card"><h2><?= tt('rc.log') ?></h2><ul class="log"><?php foreach ($log as $l): ?><li><span class="hint"><?= e(fmt_datetime($l['created_at'])) ?> · <?= e((string)($l['by_name'] ?: 'Website')) ?></span><br><b><?= e($l['action']) ?></b> <?= e((string)$l['detail']) ?></li><?php endforeach; ?></ul></div>
</div></div>
<?php layout_foot();

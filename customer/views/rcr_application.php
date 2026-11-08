<?php
/** HR › Recruiting › Application (GAS-CP-R89-001): candidate card — screening answers, CV, events attended, pipeline decision, notes, log.  ?p=rc_application&id= */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $a = $id ? rcr_application($id) : null;
if (!$a || !function_exists('rcr_has') || !rcr_has() || !rcr_can_view((int)$a['vacancy_id'])) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$set = rcr_set((int)(one('SELECT screening_set_id FROM rc_vacancies WHERE id = ?', [(int)$a['vacancy_id']])['screening_set_id'] ?? 0)); $qs = $set ? (json_decode((string)$set['questions'], true) ?: []) : []; $ans = json_decode((string)$a['answers'], true) ?: [];
$evs = rcr_application_events($id); $log = all('SELECT l.*, cu.full_name AS by_name FROM rc_log l LEFT JOIN customer_users cu ON cu.id = l.user_id WHERE l.application_id = ? ORDER BY l.created_at DESC LIMIT 100', [$id]);
$owners = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name");
$evals = rcr_eval_has() ? rcr_evaluations($id) : []; $crit = rcr_eval_criteria(); $sum = rcr_eval_summary($evals); $ints = rcr_interviews($id); $me = (int)$u['id'];   // R91-001
layout_head($a['code'], 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard') ?>"><?= tt('rc.title') ?></a> › <a href="<?= url('rcr_vacancy', ['id' => (int)$a['vacancy_id']]) ?>"><?= e($a['vacancy_code']) ?></a> › <?= e($a['code']) ?></p>
<div class="page-head"><div><h1><?= e((string)($a['name'] ?: $a['email'])) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($a['code']) ?> · <?= e($a['vacancy']) ?> · <?= pill(in_array($a['status'], ['hired', 'offer'], true) ? 'gold' : (in_array($a['status'], ['rejected', 'withdrawn'], true) ? 'grey' : 'navy'), t('rc.st_' . $a['status'])) ?> · <?= $a['passed'] ? pill('gold', tt('rc.passed') . ' ' . (int)$a['score'] . '/' . (int)$a['max_score']) : pill('grey', tt('rc.failed') . ' ' . (int)$a['score'] . '/' . (int)$a['max_score']) ?><?= !$a['knockout_ok'] ? ' ' . pill('grey', '⚑ ' . tt('rc.ko_hint')) : '' ?></p></div>
<div class="btn-row"><?php if ($a['cv_path']): ?><a class="btn btn--gold" href="<?= url('rcr_cv', ['id' => $id]) ?>" target="_blank">📎 CV</a><?php endif; ?><a class="btn btn--ghost" href="#rc-mail">✉ <?= tt('em.title') ?></a></div></div>
<?php /* R92-005: e-mail to the candidate through the portal's e-mail card — To = the candidate, templated subject / text (Settings › E-mail templates), Outlook draft or mail app */
if (function_exists('em_card_html') && em_has()) {
    $cl = in_array((string)($a['lang'] ?? ''), array_keys(LOCALES), true) ? (string)$a['lang'] : 'en';
    $rcKinds = ['rc_general' => t('et.k_rc_general'), 'rc_shortlisted' => t('et.k_rc_shortlisted'), 'rc_interview' => t('et.k_rc_interview'), 'rc_offer' => t('et.k_rc_offer'), 'rc_rejected' => t('et.k_rc_rejected')];
    $rcKind = match ((string)$a['status']) { 'shortlisted' => 'rc_shortlisted', 'interview' => 'rc_interview', 'offer', 'hired' => 'rc_offer', 'rejected' => 'rc_rejected', default => 'rc_general' };
    echo '<div id="rc-mail">' . em_card_html(['kind' => $rcKind, 'kinds' => $rcKinds, 'ctx' => ['no' => (string)$a['code'], 'title' => (string)$a['vacancy'], 'doc' => (string)$a['vacancy_code'], 'name' => (string)($a['name'] ?: $a['email']), 'email' => (string)$a['email'], 'locale' => $cl],
        'people' => [['name' => (string)($a['name'] ?: $a['email']), 'email' => (string)$a['email'], 'role' => t('rc.candidate'), 'on' => true]], 'atts' => [], 'back' => url('rcr_application', ['id' => $id]), 'hidden' => '<input type="hidden" name="rc_app" value="' . $id . '">']) . '</div>';
}
?>
<div class="cols" style="grid-template-columns:2fr 1fr;"><div>
<div class="card"><h2><?= tt('rc.contact') ?></h2><div class="kv"><div><span><?= tt('rc.email') ?></span><b><?= e($a['email']) ?></b></div><div><span><?= tt('rc.phone') ?></span><b><?= e((string)$a['phone'] ?: '—') ?></b></div><div><span><?= tt('rc.applied') ?></span><b><?= e(fmt_datetime($a['created_at'])) ?></b></div><div><span><?= tt('rc.dept_interest') ?></span><b><?= e((string)$a['department_interest'] ?: '—') ?></b></div><div><span><?= tt('rc.source') ?></span><b><?= e($a['source']) ?> · <?= e($a['lang']) ?></b></div><div><span>GDPR</span><b><?= e((string)$a['consent_version']) ?> · <?= e(fmt_datetime($a['consent_at'])) ?></b></div></div>
<?php if ($a['cover_note']): ?><h3><?= tt('rc.cover_note') ?></h3><p><?= nl2br(e($a['cover_note'])) ?></p><?php endif; ?></div>
<div class="card"><h2><?= tt('rc.screening') ?> — <?= e((string)($set['code'] ?? '')) ?></h2><ol class="qa">
<?php foreach ($qs as $q): $pick = $ans[$q['id']] ?? null; ?><li><div><?= e($q['text']) ?></div><?php foreach ($q['options'] as $o): $sel = (string)$o['id'] === (string)$pick; ?><div class="<?= $sel ? ($o['score'] ? 'opt sel good' : 'opt sel bad') : 'opt' ?>"><?= $sel ? '●' : '○' ?> <?= e($o['text']) ?><?= $sel ? ' <span class="hint">(' . (int)$o['score'] . ')</span>' : '' ?></div><?php endforeach; ?></li><?php endforeach; ?></ol></div>
<?php if ($evs): ?><div class="card"><h2><?= tt('rc.events') ?></h2><table class="tbl"><tbody><?php foreach ($evs as $ev): ?><tr><td><a href="<?= url('rcr_event', ['id' => (int)$ev['event_id']]) ?>"><?= e($ev['title']) ?></a><br><span class="hint"><?= e(fmt_datetime($ev['starts_at'])) ?></span></td><td><?= $ev['attended'] === null ? '—' : ($ev['attended'] ? '✓ ' . tt('rc.attended') : '✗') ?></td><td><?= $ev['result'] ? pill($ev['result'] === 'strong' ? 'gold' : 'navy', tt('rc.res_' . $ev['result'])) : '' ?> <?= e((string)$ev['result_note']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if (rcr_eval_has()): ?>
<div class="card" id="eval"><h2>🧭 <?= tt('rc.ev_title') ?> <?php if ($sum['n']): ?><span class="pill pill--<?= $sum['total'] >= 70 ? 'green' : ($sum['total'] >= 50 ? 'gold' : 'grey') ?>" style="margin-left:8px;"><?= e((string)$sum['total']) ?> / 100 · <?= (int)$sum['n'] ?> <?= tt('rc.ev_evaluations') ?></span><?php endif; ?></h2>
<p class="hint"><?= tt('rc.ev_hint') ?></p>
<?php if ($sum['n']): ?>
<div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.ev_criterion') ?></th><th class="num"><?= tt('rc.ev_weight') ?></th><th class="num"><?= tt('rc.ev_avg') ?></th><th style="width:40%"></th></tr></thead><tbody>
<?php foreach ($crit as $c): $v = $sum['avg'][$c['key']]; ?><tr><td><?= e($c['label']) ?></td><td class="num"><?= e((string)$c['weight']) ?> %</td><td class="num"><b><?= $v === null ? '—' : e((string)$v) ?></b> / 5</td><td><div style="height:8px;background:var(--line,#E5E7EB);border-radius:4px;overflow:hidden;"><div style="height:8px;width:<?= $v ? (int) round($v / 5 * 100) : 0 ?>%;background:<?= $v >= 4 ? '#16A34A' : ($v >= 3 ? '#C9A227' : '#DC2626') ?>;"></div></div></td></tr><?php endforeach; ?>
</tbody></table></div>
<p class="hint" style="margin:8px 0 0;"><?php foreach (RCR_EVAL_RECO as $r): if ($sum['reco'][$r]): ?><?= pill($r === 'strong_hire' || $r === 'hire' ? 'green' : ($r === 'hold' ? 'gold' : 'grey'), (int)$sum['reco'][$r] . ' × ' . t('rc.ev_reco_' . $r)) ?> <?php endif; endforeach; ?></p>
<h3 style="margin-top:14px;"><?= tt('rc.ev_evaluations') ?></h3>
<?php foreach ($evals as $e): $sc = json_decode((string)$e['scores'], true) ?: []; ?>
<div class="card" style="padding:12px 14px;margin:8px 0;"><div style="display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;align-items:center;"><div><b><?= e((string)($e['by_name'] ?: $e['evaluator_name'])) ?></b> · <?= tt('rc.ev_stage_' . $e['stage']) ?> · <span class="hint"><?= e(fmt_datetime($e['created_at'])) ?></span></div><div><b><?= e((string)$e['total']) ?></b> / 100 · <?= pill($e['recommendation'] === 'strong_hire' || $e['recommendation'] === 'hire' ? 'green' : ($e['recommendation'] === 'hold' ? 'gold' : 'grey'), t('rc.ev_reco_' . $e['recommendation'])) ?>
<?php if ((int)$e['evaluator_id'] === $me || is_cp_admin()): ?><form method="post" style="display:inline;" data-confirm="<?= tt('rc.ev_delete_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_eval_delete"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="eval_id" value="<?= (int)$e['id'] ?>"><button class="linkbtn" title="<?= tt('btn.delete') ?>">🗑</button></form><?php endif; ?></div></div>
<p class="hint" style="margin:6px 0 0;"><?php foreach ($crit as $c): ?><span style="display:inline-block;margin:2px 10px 2px 0;"><?= e($c['label']) ?>: <b><?= (int)($sc[$c['key']] ?? 0) ?></b></span><?php endforeach; ?></p>
<?php if ($e['strengths']): ?><p style="margin:6px 0 0;"><b>＋</b> <?= nl2br(e($e['strengths'])) ?></p><?php endif; ?><?php if ($e['concerns']): ?><p style="margin:4px 0 0;"><b>－</b> <?= nl2br(e($e['concerns'])) ?></p><?php endif; ?><?php if ($e['notes']): ?><p class="hint" style="margin:4px 0 0;"><?= nl2br(e($e['notes'])) ?></p><?php endif; ?></div>
<?php endforeach; endif; ?>
<details style="margin-top:12px;"<?= $sum['n'] ? '' : ' open' ?>><summary class="btn btn--gold" style="display:inline-block;cursor:pointer;">＋ <?= tt('rc.ev_add') ?></summary>
<form method="post" class="form-grid" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_eval_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field"><label><?= tt('rc.ev_stage') ?></label><select name="stage" data-nosearch><?php foreach (RCR_EVAL_STAGE as $st): ?><option value="<?= $st ?>"<?= $st === 'technical' ? ' selected' : '' ?>><?= tt('rc.ev_stage_' . $st) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.ev_reco') ?></label><select name="recommendation" data-nosearch><?php foreach (RCR_EVAL_RECO as $r): ?><option value="<?= $r ?>"<?= $r === 'hold' ? ' selected' : '' ?>><?= tt('rc.ev_reco_' . $r) ?></option><?php endforeach; ?></select></div>
  <div class="span2"><table class="tbl"><thead><tr><th><?= tt('rc.ev_criterion') ?></th><th class="num"><?= tt('rc.ev_weight') ?></th><th><?= tt('rc.ev_score') ?> 1 – 5</th></tr></thead><tbody>
  <?php foreach ($crit as $c): ?><tr><td><?= e($c['label']) ?></td><td class="num hint"><?= e((string)$c['weight']) ?> %</td><td><div style="display:flex;gap:6px;flex-wrap:wrap;"><?php for ($i = 1; $i <= 5; $i++): ?><label class="chk" style="margin:0;padding:4px 10px;border:1px solid var(--line,#E5E7EB);border-radius:8px;"><input type="radio" name="score[<?= e($c['key']) ?>]" value="<?= $i ?>" required> <?= $i ?></label><?php endfor; ?></div></td></tr><?php endforeach; ?>
  </tbody></table><p class="hint" style="margin:6px 0 0;"><?= tt('rc.ev_scale') ?></p></div>
  <div class="field"><label><?= tt('rc.ev_strengths') ?></label><textarea name="strengths" rows="3" maxlength="2000"></textarea></div>
  <div class="field"><label><?= tt('rc.ev_concerns') ?></label><textarea name="concerns" rows="3" maxlength="2000"></textarea></div>
  <div class="field span2"><label><?= tt('hr.notes') ?></label><textarea name="notes" rows="3" maxlength="4000"></textarea></div>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('rc.ev_save') ?></button></div>
</form></details>
</div>
<div class="card" id="interviews"><h2>📅 <?= tt('rc.ev_interviews') ?></h2>
<?php if ($ints): ?><table class="tbl"><thead><tr><th><?= tt('rc.ev_when') ?></th><th><?= tt('rc.ev_stage') ?></th><th><?= tt('rc.ev_mode') ?></th><th><?= tt('rc.ev_interviewers') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
<?php foreach ($ints as $i): ?><tr><td><b><?= e(fmt_datetime($i['scheduled_at'])) ?></b><br><span class="hint"><?= (int)$i['duration_min'] ?> min<?= $i['candidate_notified_at'] ? ' · ✉ ' . tt('rc.ev_notified') : '' ?></span></td><td><?= tt('rc.ev_stage_' . $i['stage']) ?></td><td><?= tt('rc.ev_mode_' . $i['mode']) ?><?= $i['location'] ? '<br><span class="hint">' . e($i['location']) . '</span>' : '' ?></td><td><?= e((string)$i['interviewers']) ?></td><td><?= pill($i['status'] === 'done' ? 'green' : ($i['status'] === 'planned' ? 'gold' : 'grey'), t('rc.ev_int_' . $i['status'])) ?></td>
<td><?php if ($i['status'] === 'planned'): ?><form method="post" style="display:flex;gap:4px;"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_interview_status"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="int_id" value="<?= (int)$i['id'] ?>"><button class="btn btn--ghost btn--sm" name="status" value="done">✓</button><button class="btn btn--ghost btn--sm" name="status" value="no_show" title="<?= tt('rc.ev_int_no_show') ?>">∅</button><button class="btn btn--ghost btn--sm" name="status" value="cancelled" title="<?= tt('rc.ev_int_cancelled') ?>">✕</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>
<details style="margin-top:10px;"<?= $ints ? '' : ' open' ?>><summary class="btn btn--ghost" style="display:inline-block;cursor:pointer;">＋ <?= tt('rc.ev_plan_interview') ?></summary>
<form method="post" class="form-grid" data-lock style="margin-top:12px;"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_interview_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field"><label><?= tt('rc.ev_stage') ?></label><select name="stage" data-nosearch><?php foreach (RCR_INT_STAGE as $st): ?><option value="<?= $st ?>"<?= $st === 'technical' ? ' selected' : '' ?>><?= tt('rc.ev_stage_' . $st) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.ev_when') ?></label><input type="datetime-local" name="scheduled_at" required></div>
  <div class="field"><label><?= tt('rc.ev_duration') ?></label><input type="number" name="duration_min" value="60" min="15" max="480" step="15"></div>
  <div class="field"><label><?= tt('rc.ev_mode') ?></label><select name="mode" data-nosearch><option value="online"><?= tt('rc.ev_mode_online') ?></option><option value="onsite"><?= tt('rc.ev_mode_onsite') ?></option><option value="phone"><?= tt('rc.ev_mode_phone') ?></option></select></div>
  <div class="field span2"><label><?= tt('rc.ev_location') ?></label><input name="location" maxlength="200" placeholder="Teams link · Keratsini office · …"></div>
  <div class="field span2"><label><?= tt('rc.ev_interviewers') ?></label><input name="interviewers" maxlength="300" placeholder="<?= e($u['full_name']) ?>, …"></div>
  <label class="check span2"><input type="checkbox" name="notify" value="1" checked> <?= tt('rc.ev_notify_candidate') ?></label>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('rc.ev_plan') ?></button></div>
</form></details></div>
<?php endif; ?>
</div><div>
<div class="card" id="keep"><h2>🗄 <?= tt('rc.keep_title') ?></h2>
<?php if (!empty($a['keep_until']) && $a['keep_until'] >= date('Y-m-d')): ?><p><?= pill('green', t('rc.keep_active', ['date' => date('d/m/Y', strtotime($a['keep_until']))])) ?></p><p class="hint"><?= e((string)$a['keep_reason']) ?><?= !empty($a['pool_consent_at']) ? ' · ' . tt('rc.keep_pool_yes') : '' ?></p>
<?php else: ?><p class="hint"><?= t('rc.keep_hint', ['months' => (int)cp_setting('rc_retention_months', '6')]) ?></p><?php endif; ?>
<form method="post" class="form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_keep"><input type="hidden" name="id" value="<?= $id ?>">
  <label><?= tt('rc.keep_for') ?><select name="months" data-nosearch><option value="12"><?= t('rc.keep_months', ['n' => 12]) ?></option><option value="6"><?= t('rc.keep_months', ['n' => 6]) ?></option><option value="24"><?= t('rc.keep_months', ['n' => 24]) ?></option><option value="36"><?= t('rc.keep_months', ['n' => 36]) ?></option><option value="0"><?= tt('rc.keep_clear') ?></option></select></label>
  <label><?= tt('rc.keep_reason') ?><input name="reason" maxlength="200" value="<?= e((string)$a['keep_reason']) ?>" placeholder="<?= tt('rc.keep_reason_ph') ?>"></label>
  <label class="check span2"><input type="checkbox" name="pool_consent" value="1"<?= !empty($a['pool_consent_at']) ? ' checked' : '' ?>> <?= tt('rc.keep_pool_consent') ?></label>
  <div class="btn-row span2"><button class="btn btn--ghost"><?= tt('btn.save') ?></button></div>
</form></div>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_app_decide"><input type="hidden" name="id" value="<?= $id ?>"><h2><?= tt('rc.decision') ?></h2>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (RCR_APP_STATUS as $s): ?><option value="<?= $s ?>"<?= $a['status'] === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.rating') ?></label><select name="rating" data-nosearch><option value=""></option><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= (int)$a['rating'] === $i ? ' selected' : '' ?>><?= str_repeat('★', $i) ?></option><?php endfor; ?></select></div>
  <div class="field"><label><?= tt('rc.owner') ?></label><select name="owner_user_id"><option value=""></option><?php foreach ($owners as $o): ?><option value="<?= (int)$o['id'] ?>"<?= (int)$a['owner_user_id'] === (int)$o['id'] ? ' selected' : '' ?>><?= e($o['full_name']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('hr.notes') ?></label><textarea name="hr_notes" rows="6"><?= e((string)$a['hr_notes']) ?></textarea></div>
  <label class="check"><input type="checkbox" name="notify_candidate" value="1"> <?= tt('rc.ev_notify_status') ?></label>
  <button class="btn btn--gold"><?= tt('btn.save') ?></button>
  <?php if ($a['hired_person_id']): ?><p class="hint">→ <a href="<?= url('hr_person', ['id' => (int)$a['hired_person_id']]) ?>"><?= tt('rc.person_card') ?></a></p><?php endif; ?>
</form>
<div class="card"><h2><?= tt('rc.log') ?></h2><ul class="log"><?php foreach ($log as $l): ?><li><span class="hint"><?= e(fmt_datetime($l['created_at'])) ?> · <?= e((string)($l['by_name'] ?: 'Website')) ?></span><br><b><?= e($l['action']) ?></b> <?= e((string)$l['detail']) ?></li><?php endforeach; ?></ul></div>
</div></div>
<?php layout_foot();

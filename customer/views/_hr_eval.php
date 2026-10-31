<?php
/** Evaluation panel — shared by the employee card tab (HR) and ?p=hr_eval (manager).   GAS-CP-R62-001   expects $pid, $evUrl(year), optional $evMgr */
$evs = hr_evaluations($pid); $ey = (int)($_GET['year'] ?? date('Y')); $cur = hr_evaluation($pid, $ey);
$sug = $_SESSION['hr_ev_ai'][$pid . '-' . $ey] ?? null; if ($sug && ($cur['status'] ?? 'draft') !== 'draft' && !hr_can()) $sug = null;   // R64-002: the assistant's draft pre-fills the form until saved or cleared
if ($sug) { $cur = $cur ?: ['sc' => [], 'status' => 'draft', 'overall' => null, 'eval_date' => date('Y-m-d'), 'employee_comment' => null, 'acknowledged_at' => null]; $cur['sc'] = array_merge($cur['sc'] ?? [], $sug['sc']); foreach ($sug['texts'] as $k => $v) if (trim((string)$v) !== '') $cur[$k] = $v; }
$aiMsg = $_SESSION['hr_ev_ai_msg'] ?? null; unset($_SESSION['hr_ev_ai_msg']); $aiOn = function_exists('ai_can') && ai_can('hr_eval'); $series = hr_eval_series($pid); $fh = fn($v) => $v === null ? '—' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.'); $part = hr_participation($pid);
?>
<div class="cols" style="grid-template-columns: 1fr 1fr;">
<div>
<div class="card">
  <h2>📈 <?= tt('hr.ev_evolution') ?></h2>
  <?php if (!$series): ?><div class="empty"><?= tt('hr.ev_none') ?></div><?php else: ?>
  <div style="display:flex;gap:10px;align-items:flex-end;height:140px;padding:0 4px;border-bottom:1px solid var(--cp-line,#ddd);"><?php foreach ($series as $yy => $ov): ?><div style="flex:1;text-align:center;"><div class="hint" style="font-size:12px;"><?= e($fh($ov)) ?></div><div style="background:<?= $ov >= 4 ? '#2E7D32' : ($ov >= 3 ? '#C9A227' : '#B23B3B') ?>;height:<?= (int) round($ov / 5 * 100) ?>px;border-radius:4px 4px 0 0;margin:0 auto;max-width:48px;"></div><div class="hint"><?= $yy ?></div></div><?php endforeach; ?></div>
  <?php endif; ?>
  <p class="hint" style="margin-top:8px;"><?= e(t('hr.ev_participation', ['t' => (int)$part['trainings'], 'h' => $fh($part['hours']), 'c' => (int)$part['certificates']])) ?></p>
  <h3 style="margin-top:12px;"><?= tt('hr.evaluations') ?></h3>
  <?php if (!$evs): ?><p class="hint">—</p><?php else: ?><table class="tbl"><thead><tr><th><?= tt('hr.year') ?></th><th><?= tt('hr.ev_overall') ?></th><th><?= tt('hr.ev_evaluator') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($evs as $e): ?><tr<?= (int)$e['year'] === $ey ? ' style="background:#FFFBEA;"' : '' ?>><td><b><?= (int)$e['year'] ?></b></td><td class="num"><?= e($fh($e['overall'])) ?></td><td class="hint"><?= e((string)$e['evaluator']) ?> · <?= e(fmt_date($e['eval_date'])) ?></td><td><?= pill($e['status'] === 'acknowledged' ? 'green' : ($e['status'] === 'final' ? 'gold' : 'mute'), t('hr.es_' . $e['status'])) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= $evUrl((int)$e['year']) ?>"><?= tt('btn.edit') ?></a></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
</div>
<div>
<div class="card">
  <h2><?= tt('hr.ev_form') ?> · <?= $ey ?></h2>
  <?php if ($sug): ?><div class="flash flash--warn" style="margin:0 0 10px;">✨ <?= tt('hr.ev_ai_prefilled') ?><?= $sug['notes'] !== '' ? ' <span class="hint">— ' . e($sug['notes']) . '</span>' : '' ?> <form method="post" action="<?= $evUrl($ey) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="hr_ev_ai_clear"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="year" value="<?= $ey ?>"><?= !empty($evMgr) ? '<input type="hidden" name="back" value="mgr">' : '' ?><button class="btn btn--ghost btn--sm" type="submit"><?= tt('hr.ev_ai_discard') ?></button></form></div><?php endif; ?>
  <form method="post" action="<?= $evUrl($ey) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_ev_save"><input type="hidden" name="pid" value="<?= $pid ?>"><?= !empty($evMgr) ? '<input type="hidden" name="back" value="mgr">' : '' ?>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('hr.year') ?></label><input type="number" name="year" min="2000" max="2100" value="<?= $ey ?>"<?= $cur ? ' readonly' : '' ?>></div>
      <div class="field"><label><?= tt('hr.ev_date') ?></label><input type="date" name="eval_date" value="<?= e($cur['eval_date'] ?? date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch<?= ($cur['status'] ?? '') === 'acknowledged' ? ' disabled' : '' ?>><option value="draft"<?= ($cur['status'] ?? 'draft') === 'draft' ? ' selected' : '' ?>><?= tt('hr.es_draft') ?></option><option value="final"<?= in_array($cur['status'] ?? '', ['final', 'acknowledged'], true) ? ' selected' : '' ?>><?= tt('hr.es_final') ?></option></select></div>
    </div>
    <table class="tbl" style="margin-bottom:10px;"><thead><tr><th><?= tt('hr.ev_criterion') ?></th><?php for ($i = 1; $i <= 5; $i++): ?><th class="num"><?= $i ?></th><?php endfor; ?></tr></thead><tbody>
    <?php foreach (HR_EVAL_CRITERIA as $c): $v = (int)($cur['sc'][$c] ?? 0); ?><tr><td><?= e(t('hr.ec_' . $c)) ?></td><?php for ($i = 1; $i <= 5; $i++): ?><td class="num"><input type="radio" name="sc[<?= $c ?>]" value="<?= $i ?>"<?= $v === $i ? ' checked' : '' ?> style="width:16px;height:16px;"></td><?php endfor; ?></tr><?php endforeach; ?>
    <?php if (function_exists('hr_eval_auto')): $auto = hr_eval_auto($pid, $ey); foreach ($auto as $k => $a): ?><tr><td><?= e(t('hr.ec_' . $k)) ?> <span class="pill pill--mute">⚙ <?= tt('hr.ea_auto') ?></span><br><span class="hint"><?= e(hr_eval_auto_text($k, $a)) ?></span></td><?php for ($i = 1; $i <= 5; $i++): ?><td class="num"><?= (int)$a['score'] === $i ? '<b>●</b>' : '<span class="hint">○</span>' ?></td><?php endfor; ?></tr><?php endforeach; endif; ?>
    </tbody></table>
    <?php if (function_exists('hr_targets_summary')): $tgs = hr_targets_summary(hr_person($pid) ?: ['id' => $pid], $ey); if ($tgs['n']): ?><p class="hint" style="margin:0 0 8px;">🎯 <?= e(t('hr.ev_targets_sum', ['n' => $tgs['n'], 'a' => $tgs['achieved'], 'p' => $tgs['avg']])) ?> <a href="<?= url('hr_targets', ['year' => $ey, 'person_id' => $pid]) ?>"><?= tt('hr.targets') ?> ›</a></p><?php endif; endif; ?>
    <p class="hint"><?= tt('hr.ev_scale') ?><?= $cur && $cur['overall'] !== null ? ' · ' . tt('hr.ev_overall') . ' <b>' . e($fh($cur['overall'])) . '</b> / 5' : '' ?></p>
    <?php foreach (['strengths', 'improvements', 'goals', 'training_needs', 'comments'] as $k): ?><div class="field"><label><?= e(t('hr.ev_' . $k)) ?></label><textarea data-mt name="<?= $k ?>" rows="2" maxlength="8000"><?= e((string)($cur[$k] ?? '')) ?></textarea></div><?php endforeach; ?>
    <?php if ($cur && $cur['employee_comment']): ?><p class="hint"><b><?= tt('hr.ev_employee_comment') ?>:</b> <?= e($cur['employee_comment']) ?><?= $cur['acknowledged_at'] ? ' · ' . e(fmt_datetime($cur['acknowledged_at'])) : '' ?></p><?php endif; ?>
    <p class="hint"><?= tt('hr.ev_form_hint') ?></p>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
<div class="card" id="ev-ai">
  <h2>✨ <?= tt('hr.ev_ai_title') ?></h2>
  <?php if (!$aiOn): ?><p class="hint"><?= tt('hr.ai_off') ?></p><?php else: ?>
  <p class="hint"><?= tt('hr.ev_ai_hint') ?></p>
  <form method="post" action="<?= $evUrl($ey) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hr_ev_ai"><input type="hidden" name="pid" value="<?= $pid ?>"><input type="hidden" name="year" value="<?= $ey ?>"><?= !empty($evMgr) ? '<input type="hidden" name="back" value="mgr">' : '' ?>
    <div class="field"><textarea name="q" rows="2" maxlength="2000" placeholder="<?= tt('hr.ev_ai_ph') ?>"><?= e($aiMsg['q'] ?? '') ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="mode" value="draft">✨ <?= tt('hr.ev_ai_draft') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="mode" value="ask">? <?= tt('hr.ev_ai_ask') ?></button></div>
  </form>
  <?php if ($aiMsg && ($aiMsg['a'] !== '' || $aiMsg['err'] !== '')): ?><div class="<?= $aiMsg['err'] !== '' ? 'flash flash--bad' : 'card card--tight' ?>" style="margin-top:10px;white-space:pre-wrap;"><?= e($aiMsg['err'] !== '' ? $aiMsg['err'] : $aiMsg['a']) ?></div><?php endif; ?>
  <?php endif; ?>
</div>
</div>
</div>

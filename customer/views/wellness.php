<?php
/** Wellness — the personal settings of every member of staff, and the anonymous weekly trend for HR and management (GAS-CP-R97-194).  ?p=wellness */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!wl_can()) { http_response_code(404); layout_head(t('wl.title'), ''); echo '<h1>' . tt('wl.title') . '</h1><p class="hint">' . tt('wl.need') . '</p>'; layout_foot(); return; }
$P = wl_prefs((int)$u['id']); $T = wl_today((int)$u['id']);
$opt = fn(string $k) => '<label class="wl-opt"><input type="checkbox" name="' . $k . '" value="1"' . ($P[$k] ? ' checked' : '') . '> <span><b>' . tt('wl.o_' . $k) . '</b><br><span class="hint">' . tt('wl.d_' . $k) . '</span></span></label>';
layout_head(t('wl.title'), 'wellness');
?>
<style nonce="<?= csp_nonce() ?>">.wl-opt{display:flex;gap:10px;align-items:flex-start;padding:9px 0;border-top:1px solid var(--line);text-align:left}.wl-opt:first-of-type{border-top:0}.wl-opt input{margin-top:4px}.wl-num{display:inline-flex;gap:8px;align-items:center;margin:6px 18px 6px 0}.wl-num input{width:80px}</style>
<div class="page-head"><div><h1>🌿 <?= tt('wl.title') ?></h1><p class="lede" style="margin-bottom:0;text-align:justify;"><?= tt('wl.lede') ?></p></div></div>
<form method="post" action="<?= url('wellness') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wl_save">
<div class="grid grid-2" style="align-items:start;">
  <div class="card"><h2><?= tt('wl.s_remind') ?></h2>
    <?= $opt('breaks') ?>
    <label class="wl-num"><?= tt('wl.o_break_min') ?> <input type="number" name="break_min" min="20" max="120" value="<?= (int)$P['break_min'] ?>"></label>
    <?= $opt('eyes') . $opt('hydrate') . $opt('posture') . $opt('eod') . $opt('io_remind') . $opt('plan') . $opt('mood') ?>
    <h2 style="margin-top:16px;"><?= tt('wl.s_field') ?></h2>
    <?= $opt('weather') . $opt('rest') . $opt('travel') . $opt('leave_nudge') ?>
    <?php if ($T['leave']): ?><p class="flash flash--info" style="margin:10px 0 0;"><?= tt('wl.on_leave') ?></p><?php endif; ?>
  </div>
  <div>
    <div class="card"><h2><?= tt('wl.s_tools') ?></h2><p class="hint" style="text-align:justify;"><?= tt('wl.tools_d') ?></p>
      <label class="wl-num"><?= tt('wl.o_tfocus') ?> <input type="number" name="timer_focus" min="5" max="90" value="<?= (int)$P['timer_focus'] ?>"></label>
      <label class="wl-num"><?= tt('wl.o_tbreak') ?> <input type="number" name="timer_break" min="1" max="30" value="<?= (int)$P['timer_break'] ?>"></label>
      <p class="hint" style="margin-top:8px;"><?= tt('wl.theme_d') ?></p>
    </div>
    <div class="card"><h2><?= tt('wl.s_desktop') ?></h2><?= $opt('push') . $opt('desktop') ?>
      <p style="margin:8px 0 0;"><button type="button" class="btn btn--ghost btn--sm" id="wl-ask" data-on="<?= tt('wl.ask_on') ?>" data-off="<?= tt('wl.ask_off') ?>" data-none="<?= tt('wl.ask_none') ?>"><?= tt('wl.ask_btn') ?></button> <span class="hint"></span></p>
    </div>
    <div class="card"><h2>🌙 <?= tt('wl.s_quiet') ?></h2><?= $opt('quiet') ?>
      <label class="wl-num"><?= tt('wl.o_quiet_from') ?> <input type="time" name="quiet_from_t" value="<?= e(wl_hm((int)$P['quiet_from'])) ?>" style="width:120px;"></label>
      <label class="wl-num"><?= tt('wl.o_quiet_to') ?> <input type="time" name="quiet_to_t" value="<?= e(wl_hm((int)$P['quiet_to'])) ?>" style="width:120px;"></label>
      <p class="hint" style="text-align:justify;margin-bottom:0;"><?= tt('wl.quiet_d') ?></p>
    </div>
    <div class="card"><h2>🔒 <?= tt('wl.s_privacy') ?></h2><p class="hint" style="text-align:justify;"><?= tt('wl.privacy_1') ?></p><p class="hint" style="text-align:justify;margin-bottom:0;"><?= tt('wl.privacy_2') ?></p></div>
  </div>
</div>
<div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
</form>
<?php if (function_exists('wl2_has') && wl2_has()): $INF = wl_info(); $hasInf = (bool) array_filter($INF); $QP = wl_questions_public(); $QA = wl_questions_all(); $me = (int)$u['id']; ?>
<div class="grid grid-2" style="align-items:start;margin-top:16px;">
  <div>
    <div class="card" id="info"><h2>ℹ <?= tt('wl.i_title') ?></h2>
      <?php if (!$hasInf && !wl_hr()): ?><p class="hint"><?= tt('wl.i_none') ?></p><?php endif; ?>
      <?php if (wl_hr()): ?><form method="post" action="<?= url('wellness') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wl_info_save">
        <?php foreach (WL_INFO as $k): ?><div class="field"><label><?= tt('wl.i_' . $k) ?></label><textarea name="info[<?= $k ?>]" rows="3" maxlength="800" data-plain="1" style="width:100%;"><?= e($INF[$k]) ?></textarea></div><?php endforeach; ?>
        <p class="hint"><?= tt('wl.i_hr_hint') ?></p><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('btn.save') ?></button></div></form>
      <?php else: foreach (WL_INFO as $k): if ($INF[$k] === '') continue; ?><p style="margin:8px 0 2px;"><b><?= tt('wl.i_' . $k) ?></b></p><p style="white-space:pre-line;text-align:justify;margin:0;"><?= e($INF[$k]) ?></p><?php endforeach; endif; ?>
    </div>
    <?php if (function_exists('soc_has') && soc_has()): ?><div class="card" id="thanks"><h2>👏 <?= tt('wl.th_title') ?></h2>
      <form method="post" action="<?= url('wellness') ?>" data-lock style="display:grid;gap:6px;"><?= csrf_field() ?><input type="hidden" name="action" value="soc_kudos">
        <select name="to" required><option value=""><?= tt('soc.kudos_to') ?></option><?php foreach (soc_staff() as $sid => $su): if ($sid === $me) continue; ?><option value="<?= (int)$sid ?>"><?= e($su['full_name']) ?></option><?php endforeach; ?></select>
        <input name="reason" required minlength="3" maxlength="300" placeholder="<?= tt('soc.kudos_ph') ?>"><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit">👏 <?= tt('soc.kudos_give') ?></button></div></form>
      <p class="hint" style="margin-bottom:0;"><?= tt('wl.th_hint') ?></p></div><?php endif; ?>
  </div>
  <div class="card" id="questions"><h2>❓ <?= tt('wl.q_title') ?></h2>
    <form method="post" action="<?= url('wellness') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="wl_q_ask">
      <div class="field"><textarea name="body" rows="3" minlength="10" maxlength="1000" required data-plain="1" style="width:100%;" placeholder="<?= tt('wl.q_ph') ?>"></textarea></div>
      <p class="hint" style="text-align:justify;">🔒 <?= tt('wl.q_anon') ?></p><div class="btn-row"><button class="btn btn--gold btn--sm" type="submit"><?= tt('wl.q_send') ?></button></div></form>
    <?php if ($QP): ?><h3 style="margin-top:14px;"><?= tt('wl.q_answered') ?></h3><?php foreach ($QP as $qq): ?><div style="border-top:1px solid var(--line);padding:8px 0;"><p style="margin:0 0 4px;white-space:pre-line;"><b><?= e($qq['body']) ?></b></p><p style="margin:0;white-space:pre-line;text-align:justify;"><?= e((string)$qq['answer']) ?></p><span class="hint"><?= e(fmt_date((string)$qq['answered_at'])) ?></span></div><?php endforeach; endif; ?>
    <?php if (wl_hr()): ?><h3 style="margin-top:14px;"><?= tt('wl.q_hr') ?> <span class="count"><?= count(array_filter($QA, fn($x) => $x['answer'] === null)) ?></span></h3>
      <?php if (!$QA): ?><p class="hint"><?= tt('wl.q_none') ?></p><?php endif; ?>
      <?php foreach ($QA as $qq): ?><form method="post" action="<?= url('wellness') ?>" data-lock style="border-top:1px solid var(--line);padding:8px 0;"><?= csrf_field() ?><input type="hidden" name="action" value="wl_q_answer"><input type="hidden" name="q_id" value="<?= (int)$qq['id'] ?>">
        <p style="margin:0 0 4px;white-space:pre-line;"><span class="hint"><?= e(fmt_date((string)$qq['day'])) ?></span> <b><?= e($qq['body']) ?></b></p>
        <textarea name="answer" rows="3" maxlength="3000" data-plain="1" style="width:100%;" placeholder="<?= tt('wl.q_answer_ph') ?>"><?= e((string)$qq['answer']) ?></textarea>
        <div class="btn-row" style="margin-top:4px;"><label class="chk" style="margin:0;"><input type="checkbox" name="is_public" value="1"<?= $qq['is_public'] || $qq['answer'] === null ? ' checked' : '' ?>> <?= tt('wl.q_public') ?></label><button class="btn btn--navy btn--sm" type="submit"><?= tt('btn.save') ?></button><button class="btn btn--ghost btn--sm" type="submit" name="q_delete" value="1" data-confirm="<?= tt('wl.q_del_confirm') ?>"><?= tt('btn.delete') ?></button></div></form><?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php if (wl_trend_can()): $tr = wl_mood_trend(12); $low = function_exists('wl_mood_low') ? wl_mood_low() : null; ?>
<div class="card" id="trend" style="margin-top:16px;"><div class="card-head"><h2>📈 <?= tt('wl.tr_title') ?></h2><span class="hint"><?= e(t('wl.tr_hint', ['n' => WL_MOOD_MIN])) ?></span></div>
  <?php if (!$tr): ?><p class="hint"><?= tt('wl.tr_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:560px;"><thead><tr><th><?= tt('wl.tr_week') ?></th><th class="num"><?= tt('wl.tr_n') ?></th><th class="num"><?= tt('wl.tr_avg') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($tr as $w): ?><tr><td class="date"><?= e(fmt_date($w['from'])) ?></td><td class="num"><?= (int)$w['n'] ?></td><td class="num"><?= $w['avg'] === null ? '<span class="hint">' . tt('wl.tr_few') . '</span>' : '<b>' . number_format($w['avg'], 1) . '</b> / 5' ?></td>
    <td><?php if ($w['avg'] !== null): ?><span style="display:inline-block;height:8px;border-radius:4px;background:<?= $w['avg'] >= 3.5 ? '#2E7D32' : ($w['avg'] >= 2.5 ? '#C9A227' : '#B3261E') ?>;width:<?= (int) round($w['avg'] * 24) ?>px;"></span><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($low): ?><div class="flash flash--warn" style="margin-top:10px;text-align:justify;"><b><?= e(t('wl.low_t', ['a' => number_format($low['avg'], 1)])) ?></b><ul style="margin:6px 0 0 18px;"><?php foreach (range(1, 4) as $k): ?><li><?= tt('wl.low_' . $k) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
</div>
<?php endif; ?>
<?php if (function_exists('wl_workload_can') && wl_workload_can()): $WLD = wl_workload(6); ?>
<div class="card" id="workload" style="margin-top:16px;"><div class="card-head"><h2>⚖ <?= tt('wl.wk_title') ?></h2><span class="hint"><?= e(t('wl.wk_hint', ['n' => WL_GROUP_MIN, 'h' => WL_LONG_DAY_H])) ?></span></div>
  <?php if (!$WLD): ?><p class="hint"><?= tt('wl.tr_none') ?></p><?php else: ?>
  <div class="table-scroll" style="max-height:420px;"><table class="tbl tbl--compact" data-nosort><thead><tr><th><?= tt('wl.tr_week') ?></th><th><?= tt('wl.wk_team') ?></th><th class="num"><?= tt('wl.wk_people') ?></th><th class="num"><?= tt('wl.wk_days') ?></th><th class="num"><?= tt('wl.wk_long') ?></th><th class="num"><?= tt('wl.wk_wend') ?></th></tr></thead><tbody>
  <?php foreach ($WLD as $w): ?><tr<?= $w['team'] === '*' ? ' style="font-weight:700;"' : '' ?>><td class="date"><?= e(fmt_date($w['from'])) ?></td><td><?= $w['team'] === '*' ? tt('wl.wk_all') : e($w['team']) ?></td><td class="num"><?= (int)$w['n'] ?></td>
    <?php if ($w['days'] === null): ?><td colspan="3" class="hint"><?= tt('wl.tr_few') ?></td><?php else: ?><td class="num"><?= (int)$w['days'] ?></td><td class="num"><?= $w['long'] ? pill($w['long'] * 4 >= $w['days'] ? 'bad' : 'gold', (string)$w['long']) : '0' ?></td><td class="num"><?= $w['wend'] ? pill('gold', (string)$w['wend']) : '0' ?></td><?php endif; ?></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <p class="hint" style="text-align:justify;margin-bottom:0;"><?= tt('wl.wk_note') ?></p>
</div>
<?php endif; ?>
<?php if (wl_trend_can() && function_exists('wl2_has') && wl2_has()): $PLC = wl_places(false); ?>
<div class="card" id="places" style="margin-top:16px;"><h2>🌡 <?= tt('wl.pl_title') ?></h2><p class="hint" style="text-align:justify;"><?= e(t('wl.pl_hint', ['hot' => WL_HOT_C, 'cold' => WL_COLD_C, 'w' => (int) round(WL_WIND_MS * 3.6)])) ?></p>
  <?php if ($PLC): ?><div class="table-scroll"><table class="tbl tbl--compact" data-nosort style="max-width:820px;"><thead><tr><th><?= tt('wl.pl_name') ?></th><th><?= tt('wl.pl_match') ?></th><th class="num"><?= tt('wl.pl_lat') ?></th><th class="num"><?= tt('wl.pl_lng') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($PLC as $pc): ?><tr><td><b><?= e($pc['name']) ?></b></td><td class="hint"><?= e($pc['match_text']) ?></td><td class="num"><?= e((string)$pc['lat']) ?></td><td class="num"><?= e((string)$pc['lng']) ?></td><td class="row-actions"><form method="post" action="<?= url('wellness') ?>" class="inline" data-lock data-confirm="<?= tt('wl.pl_del_confirm') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="wl_place_del"><input type="hidden" name="place_id" value="<?= (int)$pc['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <form method="post" action="<?= url('wellness') ?>" class="btn-row" data-lock style="margin-top:8px;"><?= csrf_field() ?><input type="hidden" name="action" value="wl_place_add">
    <input name="name" maxlength="80" required placeholder="<?= tt('wl.pl_name') ?>" style="max-width:180px;"><input name="match_text" maxlength="255" required placeholder="<?= tt('wl.pl_match_ph') ?>" style="flex:1;min-width:200px;"><input name="lat" required inputmode="decimal" placeholder="<?= tt('wl.pl_lat') ?>" style="max-width:110px;"><input name="lng" required inputmode="decimal" placeholder="<?= tt('wl.pl_lng') ?>" style="max-width:110px;"><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('wl.pl_add') ?></button></form>
  <p class="hint" style="margin-bottom:0;"><?= tt('wl.pl_src') ?></p>
</div>
<?php endif; ?>
<?php layout_foot();

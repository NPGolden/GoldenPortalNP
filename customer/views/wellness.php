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
    <div class="card"><h2>🔒 <?= tt('wl.s_privacy') ?></h2><p class="hint" style="text-align:justify;"><?= tt('wl.privacy_1') ?></p><p class="hint" style="text-align:justify;margin-bottom:0;"><?= tt('wl.privacy_2') ?></p></div>
  </div>
</div>
<div class="btn-row"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
</form>
<?php if (wl_trend_can()): $tr = wl_mood_trend(12); ?>
<div class="card" style="margin-top:16px;"><div class="card-head"><h2>📈 <?= tt('wl.tr_title') ?></h2><span class="hint"><?= e(t('wl.tr_hint', ['n' => WL_MOOD_MIN])) ?></span></div>
  <?php if (!$tr): ?><p class="hint"><?= tt('wl.tr_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl" data-nosort style="max-width:560px;"><thead><tr><th><?= tt('wl.tr_week') ?></th><th class="num"><?= tt('wl.tr_n') ?></th><th class="num"><?= tt('wl.tr_avg') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($tr as $w): ?><tr><td class="date"><?= e(fmt_date($w['from'])) ?></td><td class="num"><?= (int)$w['n'] ?></td><td class="num"><?= $w['avg'] === null ? '<span class="hint">' . tt('wl.tr_few') . '</span>' : '<b>' . number_format($w['avg'], 1) . '</b> / 5' ?></td>
    <td><?php if ($w['avg'] !== null): ?><span style="display:inline-block;height:8px;border-radius:4px;background:<?= $w['avg'] >= 3.5 ? '#2E7D32' : ($w['avg'] >= 2.5 ? '#C9A227' : '#B3261E') ?>;width:<?= (int) round($w['avg'] * 24) ?>px;"></span><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php layout_foot();

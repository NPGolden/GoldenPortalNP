<?php
/** HR › HR Assistant — landing: employee side (my card completeness, my flows) and HR side (queue, history, templates). GAS-CP-R67-001 */
require __DIR__ . '/_layout.php';
$u = require_staff(); $me = (int)$u['id'];
if (!function_exists('hra_has') || !hra_has()) { layout_head(t('hra.title'), 'hra'); echo '<div class="flash flash--warn">' . tt('hra.need_r67') . '</div>'; layout_foot(); return; }
$manage = hra_can_manage(); $p = hr_person_of_user($me); $tab = (string)($_GET['tab'] ?? ($manage ? 'queue' : 'me')); if (!in_array($tab, ['me', 'queue', 'history', 'templates'], true) || (!$manage && $tab !== 'me')) $tab = $manage ? 'queue' : 'me';
if (!$manage && !$p) { http_response_code(403); layout_head(t('hra.title'), 'hra'); echo '<div class="flash flash--warn">' . tt('hr.no_person') . '</div>'; layout_foot(); return; }
layout_head(t('hra.title'), 'hra');
$T = fn($k, $lbl) => '<a class="tab' . ($tab === $k ? ' is-on' : '') . '" href="' . url('hra', ['tab' => $k]) . '">' . $lbl . '</a>';
?>
<div class="page-head"><div><h1>🤖 <?= tt('hra.title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('hra.lede') ?></p></div></div>
<?php if ($manage): ?><nav class="tabs" style="margin-bottom:12px;"><?= $T('queue', '📥 ' . t('hra.tab_queue') . (($n = hra_pending_count()) ? ' <span class="badge">' . $n . '</span>' : '')) . ($p ? $T('me', '🪪 ' . t('hra.tab_me')) : '') . $T('history', '🗂 ' . t('hra.tab_history')) . $T('templates', '⚙ ' . t('hra.tab_templates')) ?></nav><?php endif; ?>

<?php if ($tab === 'me' && $p): $c = hra_completeness($p); $open = hra_my_flow($me, 'card'); $mine = hra_my_flows($me); ?>
<div class="cols">
<div class="card" id="hra-me">
  <h2>🪪 <?= tt('hra.my_card') ?></h2>
  <p><?= e(t('hra.my_card_lede')) ?></p>
  <div class="kpi kpi--4" style="margin:10px 0;">
    <span class="stat <?= $c['pct'] >= 90 ? 'green' : ($c['pct'] >= 60 ? 'gold' : 'red') ?>"><b><?= $c['pct'] ?> %</b><span class="s"><?= tt('hra.complete') ?></span></span>
    <span class="stat"><b><?= count($c['missing']) ?></b><span class="s"><?= tt('hra.missing_n') ?></span></span>
    <span class="stat <?= $open ? 'gold' : '' ?>"><b><?= $open ? e(t('hra.st_' . $open['status'])) : '—' ?></b><span class="s"><?= tt('hra.open_flow') ?></span></span>
    <span class="stat"><b><?= count($mine) ?></b><span class="s"><?= tt('hra.flows_total') ?></span></span>
  </div>
  <?php if ($c['missing']): ?><p class="hint"><?= tt('hra.missing_list') ?>: <?= e(implode(', ', array_map('hra_label', array_slice($c['missing'], 0, 12)))) ?><?= count($c['missing']) > 12 ? ' …' : '' ?></p><?php endif; ?>
  <div class="btn-row" style="margin-top:10px;">
    <?php if ($open): ?><a class="btn btn--gold" href="<?= url('hra_flow', ['id' => (int)$open['id']]) ?>">▶ <?= tt($open['status'] === 'draft' ? 'hra.continue' : 'hra.view_flow') ?> · <?= e($open['code']) ?></a>
    <?php else: ?><form method="post" action="<?= url('hra') ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hra_start"><button class="btn btn--gold" type="submit">🤖 <?= tt('hra.start_card') ?></button></form><?php endif; ?>
    <a class="btn btn--ghost" href="<?= url('my_leave') ?>"><?= tt('hr.my_leave') ?></a>
  </div>
  <p class="hint" style="margin-top:10px;"><?= tt('hra.privacy_short') ?></p>
</div>
<div class="card">
  <h2>🗂 <?= tt('hra.my_flows') ?></h2>
  <?php if (!$mine): ?><p class="hint"><?= tt('hra.none_yet') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hra.code') ?></th><th><?= tt('hra.kind') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hra.opened') ?></th><th><?= tt('hra.decided') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($mine as $f): ?><tr><td><b><?= e($f['code']) ?></b></td><td><?= e(t('hra.k_' . $f['kind'])) ?></td><td><?= hra_status_pill($f['status']) ?></td><td class="hint"><?= e(fmt_date(substr((string)$f['created_at'], 0, 10))) ?></td><td class="hint"><?= $f['decided_at'] ? e(fmt_date(substr((string)$f['decided_at'], 0, 10))) : '—' ?><?= $f['decision_note'] ? '<br>' . e(mb_substr((string)$f['decision_note'], 0, 120)) : '' ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>"><?= tt('hra.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
</div>
<?php endif; ?>

<?php if ($manage && in_array($tab, ['queue', 'history'], true)): $rows = hra_queue($tab === 'queue' ? 'open' : 'closed'); $drafts = $tab === 'queue' ? hra_queue('draft') : []; ?>
<div class="card" id="hra-queue">
  <h2><?= $tab === 'queue' ? '📥 ' . tt('hra.tab_queue') : '🗂 ' . tt('hra.tab_history') ?></h2>
  <p class="hint"><?= tt($tab === 'queue' ? 'hra.queue_lede' : 'hra.history_lede') ?></p>
  <?php if (!$rows): ?><p class="hint"><?= tt('hra.queue_empty') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hra.code') ?></th><th><?= tt('hr.person') ?></th><th><?= tt('hra.kind') ?></th><th class="num"><?= tt('hra.fields') ?></th><th class="num"><?= tt('hra.docs') ?></th><th><?= tt('hra.submitted_at') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $f): ?><tr><td><b><?= e($f['code']) ?></b></td><td><?= $f['person_id'] ? '<a href="' . url('hr_person', ['id' => (int)$f['person_id']]) . '">' . e((string)$f['name_en']) . '</a>' : e((string)$f['title']) ?><?= $f['emp_code'] ? ' <span class="hint">' . e($f['emp_code']) . '</span>' : '' ?></td><td><?= e(t('hra.k_' . $f['kind'])) ?></td><td class="num"><?= (int)$f['n_fields'] ?></td><td class="num"><?= (int)$f['n_docs'] ?></td><td class="hint"><?= $f['submitted_at'] ? e(fmt_date(substr((string)$f['submitted_at'], 0, 10))) : '—' ?></td><td><?= hra_status_pill($f['status']) ?></td><td class="row-actions"><a class="btn btn--gold btn--sm" href="<?= url('hra_flow', ['id' => (int)$f['id']]) ?>"><?= tt($tab === 'queue' ? 'hra.review' : 'hra.open') ?></a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($drafts): ?><h3 class="ac-h" style="margin-top:14px;"><?= tt('hra.drafts_open') ?> (<?= count($drafts) ?>)</h3><p class="hint"><?= tt('hra.drafts_hint') ?></p>
  <ul class="hint" style="padding-left:18px;margin:0;"><?php foreach ($drafts as $f): ?><li><b><?= e($f['code']) ?></b> · <?= e((string)($f['name_en'] ?: $f['title'])) ?> · <?= e(fmt_date(substr((string)$f['updated_at'], 0, 10))) ?></li><?php endforeach; ?></ul><?php endif; ?>
</div>
<?php endif; ?>

<?php if ($manage && $tab === 'templates'): $tpls = hra_templates(); $cur = null; $ek = in_array($_GET['kind'] ?? '', HRA_KINDS, true) ? $_GET['kind'] : 'card'; $eb = (string)($_GET['branch'] ?? '*'); if (!preg_match('/^(\*|[A-Z]{2,4})$/', $eb)) $eb = '*';
  foreach ($tpls as $t) if ($t['kind'] === $ek && $t['branch'] === $eb) $cur = $t; $def = $cur ? $cur['definition'] : json_encode(hra_default_template($ek), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); ?>
<div class="cols">
<div class="card" id="hra-tpl">
  <h2>⚙ <?= tt('hra.tab_templates') ?></h2>
  <p class="hint"><?= tt('hra.tpl_lede') ?></p>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('hra.kind') ?></th><th><?= tt('br.one') ?></th><th><?= tt('hra.tpl_name') ?></th><th><?= tt('th.status') ?></th><th><?= tt('hra.updated') ?></th><th></th></tr></thead><tbody>
  <?php foreach (HRA_KINDS as $k): $has = false; foreach ($tpls as $t): if ($t['kind'] !== $k) continue; $has = true; ?><tr><td><?= e(t('hra.k_' . $k)) ?></td><td><?= e($t['branch']) ?></td><td><?= e($t['name']) ?></td><td><?= $t['is_active'] ? pill('green', t('hra.active')) : pill('mute', t('hra.inactive')) ?></td><td class="hint"><?= e(fmt_date(substr((string)$t['updated_at'], 0, 10))) ?></td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hra', ['tab' => 'templates', 'kind' => $k, 'branch' => $t['branch']]) ?>">✎</a></td></tr><?php endforeach; if (!$has): ?><tr><td><?= e(t('hra.k_' . $k)) ?></td><td>*</td><td class="hint"><?= tt('hra.tpl_default') ?></td><td><?= $k === 'card' ? pill('green', t('hra.active')) : pill('mute', t('hra.tpl_later')) ?></td><td>—</td><td class="row-actions"><a class="btn btn--ghost btn--sm" href="<?= url('hra', ['tab' => 'templates', 'kind' => $k, 'branch' => '*']) ?>">✎</a></td></tr><?php endif; endforeach; ?>
  </tbody></table></div>
</div>
<div class="card">
  <h2>✎ <?= e(t('hra.k_' . $ek)) ?> · <?= e($eb) ?></h2>
  <form method="post" action="<?= url('hra', ['tab' => 'templates']) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="hra_tpl_save"><input type="hidden" name="kind" value="<?= e($ek) ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('br.one') ?></label><input name="branch" value="<?= e($eb) ?>" maxlength="4" pattern="\*|[A-Z]{2,4}"><span class="hint"><?= tt('hra.tpl_branch_hint') ?></span></div>
      <div class="field"><label><?= tt('hra.tpl_name') ?></label><input name="name" value="<?= e((string)($cur['name'] ?? '')) ?>" maxlength="120"></div>
    </div>
    <div class="field"><label><?= tt('hra.tpl_json') ?></label><textarea name="definition" rows="22" data-plain style="font-family:ui-monospace,Menlo,monospace;font-size:12px;"><?= e((string)$def) ?></textarea><span class="hint"><?= tt('hra.tpl_json_hint') ?> <?= e(implode(', ', HRA_EMP_FIELDS)) ?></span></div>
    <label class="chk"><input type="checkbox" name="is_active" value="1"<?= !$cur || $cur['is_active'] ? ' checked' : '' ?>> <?= tt('hra.active') ?></label>
    <div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('btn.save') ?></button></div>
  </form>
</div>
</div>
<?php endif; ?>
<?php layout_foot();

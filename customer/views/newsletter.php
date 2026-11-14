<?php
/** Commercial › Web Sales › Newsletters › one issue (GAS-CP-R97-039): editor (blocks, audience, assistant), preview, test, send, statistics. ?p=newsletter&id=N[&preview=1] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $I = function_exists('nl_can') && nl_can() ? nl_issue($id) : null;
if (!$I) { http_response_code(404); layout_head(t('err.notfound'), 'web_sales'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!empty($_GET['preview'])) { header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'"); header('Content-Type: text/html; charset=utf-8'); echo nl_preview_html($I); return; }
$self = url('newsletter', ['id' => $id]); $draft = $I['status'] === 'draft'; $send = nl_can_send(); $aud = $I['audience']; $rc = $draft ? nl_audience($aud) : []; $cnt = nl_audience_counts($rc);
$S = $draft ? null : nl_stats($id); $ro = $draft ? '' : ' readonly'; $dis = $draft ? '' : ' disabled'; $bl = ['h' => t('nl.b_h'), 'p' => t('nl.b_p'), 'img' => t('nl.b_img'), 'btn' => t('nl.b_btn'), 'card' => t('nl.b_card'), 'hr' => t('nl.b_hr')];
layout_head($I['title'] . ' · ' . t('nl.title'), 'web_sales');
?>
<p class="eyebrow"><a href="<?= url('newsletters') ?>"><?= tt('nl.title') ?></a> › <?= e($I['title']) ?></p>
<div class="page-head"><div><h1>✉ <?= e($I['title']) ?> <?= nl_pill((string)$I['status']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e((string)$I['created_name']) ?> · <?= e(fmt_date(substr((string)$I['created_at'], 0, 10))) ?><?= $I['queued_at'] ? ' · ' . e(t('nl.queued_by', ['n' => (string)$I['queued_name'], 'd' => fmt_datetime($I['queued_at'])])) : '' ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id, 'preview' => 1]) ?>" target="_blank" rel="noopener">👁 <?= tt('nl.preview') ?></a>
    <form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="nl_copy"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">⧉ <?= tt('nl.copy') ?></button></form>
    <?php if ($draft): ?><form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= tt('nl.delete_q') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nl_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">🗑 <?= tt('btn.delete') ?></button></form><?php endif; ?></div></div>
<?php if ($S): $T = $S['tot']; ?>
<div class="kpi" style="margin-bottom:14px;" id="stats">
  <div class="stat"><b><?= $T['sent'] ?> / <?= $T['n'] ?></b><?= tt('nl.k_sent') ?><?php if ($T['queued']): ?><span class="s"><?= e(t('nl.k_queued', ['n' => $T['queued']])) ?></span><?php endif; ?></div>
  <div class="stat green"><b><?= $T['opened'] ?></b><?= tt('nl.k_open') ?><span class="s"><?= e(nl_pct($T['opened'], $T['sent'])) ?></span></div>
  <div class="stat gold"><b><?= $T['clicked'] ?></b><?= tt('nl.k_click') ?><span class="s"><?= e(nl_pct($T['clicked'], $T['sent'])) ?> · <?= e(t('nl.k_clicks', ['n' => $T['clicks']])) ?></span></div>
  <div class="stat<?= $T['unsub'] ? ' red' : '' ?>"><b><?= $T['unsub'] ?></b><?= tt('nl.k_unsub') ?></div>
  <div class="stat<?= $T['failed'] || $T['bounce'] ? ' red' : '' ?>"><b><?= $T['failed'] + $T['bounce'] ?></b><?= tt('nl.k_failed') ?><span class="s"><?= e(t('nl.k_bounce', ['f' => $T['failed'], 'b' => $T['bounce']])) ?></span></div>
</div>
<?php if ($send && ($T['queued'] || $T['failed'])): ?><div class="btn-row" style="margin-bottom:12px;">
  <?php if ($T['queued']): ?><form method="post" action="<?= $self ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_batch"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit">▶ <?= tt('nl.batch') ?></button></form>
  <form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= tt('nl.cancel_q') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nl_cancel"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">■ <?= tt('nl.cancel') ?></button></form><span class="hint"><?= e(t('nl.batch_hint', ['n' => nl_batch(), 'm' => nl_daily()])) ?></span><?php endif; ?>
  <?php if ($T['failed']): ?><form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="nl_retry"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">↻ <?= tt('nl.retry') ?></button></form><?php endif; ?>
</div><?php endif; ?>
<div class="grid grid-2">
  <div class="card"><h2><?= tt('nl.st_groups') ?></h2><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('nl.c_group') ?></th><th class="num"><?= tt('nl.c_sent') ?></th><th class="num"><?= tt('nl.c_open') ?></th><th class="num"><?= tt('nl.c_click') ?></th><th class="num"><?= tt('nl.c_unsub') ?></th></tr></thead><tbody><?php foreach ($S['groups'] as $g): ?><tr><td><?= e(nl_group_label((string)$g['grp'])) ?></td><td class="num"><?= (int)$g['sent'] ?></td><td class="num"><?= (int)$g['opened'] ?> <span class="hint"><?= e(nl_pct((int)$g['opened'], (int)$g['sent'])) ?></span></td><td class="num"><?= (int)$g['clicked'] ?> <span class="hint"><?= e(nl_pct((int)$g['clicked'], (int)$g['sent'])) ?></span></td><td class="num"><?= (int)$g['unsub'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
  <div class="card"><h2><?= tt('nl.st_links') ?></h2><?php if (!$S['links']): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('nl.c_link') ?></th><th class="num"><?= tt('nl.c_people') ?></th><th class="num"><?= tt('nl.c_clicks') ?></th></tr></thead><tbody><?php foreach ($S['links'] as $l): ?><tr><td style="word-break:break-all;"><a href="<?= e($l['url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(mb_strimwidth((string)$l['url'], 0, 70, '…')) ?></a></td><td class="num"><?= (int)$l['people'] ?></td><td class="num"><?= (int)$l['clicks'] ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
</div>
<?php if ($S['orgs']): ?><div class="card"><h2>🏢 <?= tt('nl.st_orgs') ?></h2><div class="table-scroll" style="max-height:320px;"><table class="tbl"><thead><tr><th><?= tt('co.one') ?></th><th class="num"><?= tt('nl.c_sent') ?></th><th class="num"><?= tt('nl.c_open') ?></th><th class="num"><?= tt('nl.c_click') ?></th></tr></thead><tbody><?php foreach ($S['orgs'] as $o): ?><tr><td><?= $o['company_id'] ? '<a href="' . url('companies', ['edit' => (int)$o['company_id']]) . '">' . e($o['company']) . '</a>' : e($o['company']) ?></td><td class="num"><?= (int)$o['n'] ?></td><td class="num"><?= (int)$o['opened'] ?></td><td class="num"><b><?= (int)$o['clicked'] ?></b></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<div class="card"><div class="card-head"><h2><?= tt('nl.st_people') ?> <span class="count"><?= count($S['rows']) ?></span></h2><span class="hint"><?= tt('nl.st_people_hint') ?></span></div>
  <div class="table-scroll" style="max-height:520px;"><table class="tbl"><thead><tr><th><?= tt('nl.c_name') ?></th><th><?= tt('co.one') ?></th><th><?= tt('nl.c_group') ?></th><th><?= tt('th.status') ?></th><th><?= tt('nl.c_open') ?></th><th><?= tt('nl.c_click') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($S['rows'] as $r): ?><tr><td><?= $r['contact_id'] ? '<a href="' . url('contact', ['id' => (int)$r['contact_id']]) . '">' . e((string)($r['name'] ?: $r['email'])) . '</a>' : e((string)($r['name'] ?: '—')) ?><br><span class="hint"><?= e($r['email']) ?></span></td><td><?= e((string)$r['company']) ?></td><td class="hint"><?= e(nl_group_label((string)$r['grp'])) ?></td>
    <td><?= pill(['sent' => 'green', 'queued' => 'gold', 'sending' => 'gold', 'failed' => 'bad', 'skipped' => 'mute'][$r['status']] ?? 'mute', t('nl.rs_' . $r['status'])) ?><?= $r['unsub_at'] ? ' ' . pill('bad', t('nl.r_unsub')) : '' ?><?= $r['opt'] === 'bounce' ? ' ' . pill('bad', t('nl.r_bounce')) : '' ?><?= $r['error'] ? '<br><span class="hint">' . e((string)$r['error']) . '</span>' : '' ?></td>
    <td class="hint"><?= $r['opened_at'] ? '✓ ' . e(fmt_datetime($r['opened_at'])) . ((int)$r['opens'] > 1 ? ' ×' . (int)$r['opens'] : '') : '' ?></td><td class="hint"><?= $r['clicked_at'] ? '<b>✓</b> ' . e(fmt_datetime($r['clicked_at'])) . ' ×' . (int)$r['clicks'] : '' ?></td>
    <td><?php if ($send && $r['status'] === 'sent' && !$r['opt']): ?><form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= e(t('nl.bounce_q', ['e' => $r['email']])) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="nl_bounce"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="email" value="<?= e($r['email']) ?>"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('nl.bounce_hint') ?>">↯</button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>

<form method="post" action="<?= $self ?>" enctype="multipart/form-data" data-lock id="nl-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
<div class="card" id="head"><h2>📝 <?= tt('nl.s_head') ?></h2>
  <div class="grid grid-3">
    <div class="field"><label><?= tt('nl.c_title') ?></label><input name="title" maxlength="200" value="<?= e($I['title']) ?>"<?= $ro ?>><p class="hint"><?= tt('nl.title_hint') ?></p></div>
    <div class="field"><label><?= tt('nl.subject') ?></label><input name="subject" maxlength="200" value="<?= e($I['subject']) ?>"<?= $ro ?>></div>
    <div class="field"><label><?= tt('nl.lang') ?></label><select name="lang" data-nosearch<?= $dis ?>><?php foreach (['en' => 'English', 'el' => 'Ελληνικά', 'it' => 'Italiano'] as $k => $l): ?><option value="<?= $k ?>"<?= $I['lang'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><p class="hint"><?= tt('nl.lang_hint') ?></p></div>
  </div>
  <div class="field"><label><?= tt('nl.preheader') ?></label><input name="preheader" maxlength="200" value="<?= e((string)$I['preheader']) ?>"<?= $ro ?>><p class="hint"><?= tt('nl.preheader_hint') ?></p></div>
</div>
<?php if ($draft && nl_ai_on()): ?>
<div class="card" id="ai"><h2>✨ <?= tt('nl.ai_title') ?></h2><p class="hint"><?= tt('nl.ai_hint') ?></p>
  <div class="field"><textarea name="brief" rows="4" maxlength="3000" placeholder="<?= tt('nl.ai_ph') ?>"></textarea></div>
  <div class="btn-row"><label class="chk" style="margin:0;"><input type="checkbox" name="news" value="1"> <?= tt('nl.ai_news') ?></label><label class="chk" style="margin:0;"><input type="checkbox" name="replace" value="1" checked> <?= tt('nl.ai_replace') ?></label><button class="btn btn--sm" type="submit" name="action" value="nl_ai" data-busy="<?= tt('nl.ai_busy') ?>">✨ <?= tt('nl.ai_btn') ?></button></div>
</div>
<?php endif; ?>
<div class="card" id="blocks"><div class="card-head"><h2>🧱 <?= tt('nl.s_blocks') ?> <span class="count"><?= count($I['blocks']) ?></span></h2><span class="hint"><?= tt('nl.blocks_hint') ?></span></div>
  <?php foreach ($I['blocks'] as $i => $b): $t = (string)($b['t'] ?? 'p'); $k = 'b[' . $i . ']'; ?>
  <div class="nl-block"><div class="nl-bhead"><b><?= e($bl[$t] ?? $t) ?></b><input type="hidden" name="<?= $k ?>[t]" value="<?= e($t) ?>"><?php if ($draft): ?><label class="hint"><?= tt('nl.pos') ?> <input name="<?= $k ?>[pos]" type="number" value="<?= ($i + 1) * 10 ?>" style="width:70px;"></label><label class="chk hint" style="margin:0;"><input type="checkbox" name="<?= $k ?>[del]" value="1"> <?= tt('nl.remove') ?></label><?php endif; ?></div>
    <?php if ($t === 'h'): ?><input name="<?= $k ?>[text]" maxlength="200" value="<?= e((string)($b['text'] ?? '')) ?>" placeholder="<?= tt('nl.ph_h') ?>"<?= $ro ?>>
    <?php elseif ($t === 'p'): ?><textarea name="<?= $k ?>[text]" rows="5" maxlength="6000" placeholder="<?= tt('nl.ph_p') ?>"<?= $ro ?>><?= e((string)($b['text'] ?? '')) ?></textarea>
    <?php elseif ($t === 'btn'): ?><div class="grid grid-2"><div class="field"><label><?= tt('nl.f_label') ?></label><input name="<?= $k ?>[label]" maxlength="80" value="<?= e((string)($b['label'] ?? '')) ?>"<?= $ro ?>></div><div class="field"><label><?= tt('nl.f_url') ?></label><input name="<?= $k ?>[url]" maxlength="600" value="<?= e((string)($b['url'] ?? '')) ?>" placeholder="https://"<?= $ro ?>></div></div>
    <?php elseif ($t === 'img' || $t === 'card'): ?>
      <?php if ($t === 'card'): ?><div class="field"><label><?= tt('nl.f_title') ?></label><input name="<?= $k ?>[title]" maxlength="200" value="<?= e((string)($b['title'] ?? '')) ?>"<?= $ro ?>></div><div class="field"><textarea name="<?= $k ?>[text]" rows="4" maxlength="6000" placeholder="<?= tt('nl.ph_p') ?>"<?= $ro ?>><?= e((string)($b['text'] ?? '')) ?></textarea></div><?php endif; ?>
      <div class="grid grid-3"><div class="field"><label><?= tt('nl.f_img') ?></label><input type="hidden" name="<?= $k ?>[img]" value="<?= e((string)($b['img'] ?? '')) ?>"><?php if (!empty($b['img'])): ?><img src="nl.php?i=<?= e((string)$b['img']) ?>" alt="" style="max-width:180px;max-height:90px;display:block;border-radius:4px;margin-bottom:4px;"><?php endif; ?><?php if ($draft): ?><input type="file" name="bf[<?= $i ?>]" accept="image/jpeg,image/png,image/gif"><?php endif; ?></div>
        <?php if ($t === 'card'): ?><div class="field"><label><?= tt('nl.f_label') ?></label><input name="<?= $k ?>[label]" maxlength="80" value="<?= e((string)($b['label'] ?? '')) ?>"<?= $ro ?>></div><?php else: ?><div class="field"><label><?= tt('nl.f_alt') ?></label><input name="<?= $k ?>[title]" maxlength="200" value="<?= e((string)($b['title'] ?? '')) ?>"<?= $ro ?>></div><?php endif; ?>
        <div class="field"><label><?= tt('nl.f_url') ?></label><input name="<?= $k ?>[url]" maxlength="600" value="<?= e((string)($b['url'] ?? '')) ?>" placeholder="https://"<?= $ro ?>></div></div>
    <?php else: ?><p class="hint" style="margin:0;"><?= tt('nl.hr_hint') ?></p><?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if ($draft): ?><div class="btn-row" style="margin-top:10px;"><span class="hint"><?= tt('nl.add') ?></span><?php foreach ($bl as $bk => $lab): ?><button class="btn btn--ghost btn--sm" type="submit" name="add" value="<?= $bk ?>" formaction="<?= $self ?>" data-nl-add>+ <?= e($lab) ?></button><?php endforeach; ?></div><?php endif; ?>
</div>
<div class="card" id="audience"><div class="card-head"><h2>👥 <?= tt('nl.s_aud') ?><?php if ($draft): ?> <span class="count"><?= count($rc) ?></span><?php endif; ?></h2><span class="hint"><?= tt('nl.aud_hint') ?></span></div>
  <div class="field"><label><?= tt('nl.aud_groups') ?></label><div style="display:flex;flex-wrap:wrap;gap:4px 16px;"><?php foreach (nl_groups() as $g => $lab): ?><label class="chk" style="margin:0;"><input type="checkbox" name="groups[]" value="<?= $g ?>"<?= in_array($g, (array)($aud['groups'] ?? []), true) ? ' checked' : '' ?><?= $dis ?>> <?= e($lab) ?><?= isset($cnt[$g]) ? ' <span class="hint">' . (int)$cnt[$g] . '</span>' : '' ?></label><?php endforeach; ?></div></div>
  <div class="grid grid-3">
    <div class="field"><label><?= tt('nl.aud_web') ?></label><?php foreach (NL_TOPICS as $tp): ?><label class="chk"><input type="checkbox" name="topics[]" value="<?= $tp ?>"<?= in_array($tp, (array)($aud['topics'] ?? []), true) ? ' checked' : '' ?><?= $dis ?>> <?= tt('ct.sub_' . $tp) ?></label><?php endforeach; ?><?= isset($cnt['web']) ? '<span class="hint">' . (int)$cnt['web'] . '</span>' : '' ?></div>
    <div class="field"><label><?= tt('nl.aud_country') ?></label><input name="country" maxlength="80" value="<?= e((string)($aud['country'] ?? '')) ?>"<?= $ro ?>><p class="hint"><?= tt('nl.aud_country_hint') ?></p></div>
    <div class="field"><label><?= tt('nl.aud_extra') ?></label><textarea name="extra" rows="2" maxlength="4000"<?= $ro ?>><?= e((string)($aud['extra'] ?? '')) ?></textarea><p class="hint"><?= tt('nl.aud_extra_hint') ?></p></div>
  </div>
  <?php if ($draft && $rc): ?><details><summary class="hint" style="cursor:pointer;"><?= e(t('nl.aud_list', ['n' => count($rc)])) ?></summary><div class="table-scroll" style="max-height:260px;"><table class="tbl"><tbody><?php foreach (array_slice($rc, 0, 400) as $r): ?><tr><td><?= e($r['name'] ?: '—') ?></td><td class="hint"><?= e($r['email']) ?></td><td><?= e($r['company']) ?></td><td class="hint"><?= e(nl_group_label($r['grp'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</div>
<?php if ($draft): ?>
<div class="card"><div class="btn-row">
  <button class="btn btn--gold btn--sm" type="submit" name="action" value="nl_save">✓ <?= tt('nl.save') ?></button>
  <button class="btn btn--sm" type="submit" name="action" value="nl_test" data-busy="…">✉ <?= tt('nl.test') ?></button><span class="hint"><?= e(t('nl.test_hint', ['e' => (string)($u['email'] ?? '')])) ?></span>
  <?php if ($send): ?><button class="btn btn--sm" type="submit" name="action" value="nl_queue" data-confirm="<?= e(t('nl.queue_q', ['n' => count($rc)])) ?>" style="margin-left:auto;">▶ <?= e(t('nl.queue', ['n' => count($rc)])) ?></button><?php else: ?><span class="hint" style="margin-left:auto;"><?= tt('nl.queue_who') ?></span><?php endif; ?>
</div></div>
<?php endif; ?>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){ var f = document.getElementById('nl-form'); if (!f) return; var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'nl_save';
  f.querySelectorAll('[data-nl-add]').forEach(function(b){ b.addEventListener('click', function(){ if (!f.querySelector('input[type=hidden][name=action]')) f.appendChild(a); }); }); })();</script>
<?php layout_foot();

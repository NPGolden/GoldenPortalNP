<?php
/** Commercial › Web Sales › Newsletters › one issue (GAS-CP-R97-039): editor (blocks, audience, assistant), preview, test, send, statistics. ?p=newsletter&id=N[&preview=1] */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $I = function_exists('nl_can') && (nl_can() || nl_hr_can()) ? nl_issue($id) : null; if ($I && !nl_can_issue($I)) $I = null; $isHr = $I && nl_dept($I) === 'hr';   // R97-160
if (!$I) { http_response_code(404); layout_head(t('err.notfound'), 'web_sales'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!empty($_GET['preview'])) { header("Content-Security-Policy: default-src 'none'; img-src 'self' https: data:; style-src 'unsafe-inline'; frame-ancestors 'self'"); header('Content-Type: text/html; charset=utf-8'); echo nl_preview_html(isset($_GET['tl']) ? nl_in_lang($I, (string)$_GET['tl'], true) : $I); return; }
$bl0 = ['h' => t('nl.b_h'), 'p' => t('nl.b_p'), 'img' => t('nl.b_img'), 'btn' => t('nl.b_btn'), 'card' => t('nl.b_card'), 'hr' => t('nl.b_hr')];
$TRH = function_exists('nl_tr_has') && nl_tr_has(); $tl = (string)($_GET['tl'] ?? '');
if ($TRH && isset(NL_LANGS[$tl]) && $tl !== $I['lang']) {   // R97-203: one language version — the original beside the translation
    if ($I['status'] === 'draft') $I = nl_keys_ensure($id); $V = (array)($I['tr'][$tl] ?? []); $dr = $I['status'] === 'draft' && ($I['appr_state'] ?? '') !== 'pending'; $ro2 = $dr ? '' : ' readonly'; $selfT = url('newsletter', ['id' => $id, 'tl' => $tl]); $st = nl_tr_state($I, $tl); $aiOn = nl_ai_on();
    layout_head($I['title'] . ' · ' . NL_LANGS[$tl], $isHr ? 'hr_newsletters' : 'web_sales'); ?>
<style nonce="<?= csp_nonce() ?>">.nl-tr{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding:12px 0;border-top:1px solid var(--line)}.nl-tr:first-of-type{border-top:0}.nl-tr .src{white-space:pre-wrap;text-align:justify;font-size:14px;line-height:1.5;background:var(--bg-soft,#F6F7F9);border-radius:8px;padding:10px 12px;margin:0 0 6px}.nl-tr label.h{font-weight:600;font-size:12px;display:block;margin:0 0 4px;opacity:.75}@media (max-width:820px){.nl-tr{grid-template-columns:1fr}}</style>
<p class="eyebrow"><a href="<?= nl_list_url($I) ?>"><?= tt($isHr ? 'nl.hr_title' : 'nl.title') ?></a> › <a href="<?= url('newsletter', ['id' => $id]) ?>#langs"><?= e($I['title']) ?></a> › <?= e(NL_LANGS[$tl]) ?></p>
<div class="page-head"><div><h1>🌐 <?= e(t('nl.tr_head', ['l' => NL_LANGS[$tl]])) ?> <?= pill($st['ok'] ? 'green' : ($st['exists'] ? 'gold' : 'mute'), t($st['ok'] ? 'nl.tr_s_ready' : ($st['exists'] ? 'nl.tr_s_draft' : 'nl.tr_s_none'))) ?></h1><p class="lede" style="margin-bottom:0;text-align:justify;"><?= tt('nl.tr_lede') ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id]) ?>#langs">← <?= tt('nl.tr_back') ?></a><a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id, 'preview' => 1, 'tl' => $tl]) ?>" target="_blank" rel="noopener">👁 <?= tt('nl.preview') ?></a></div></div>
<?php if (!empty($V['ai']) && !$st['ok']): ?><p class="flash flash--info"><?= tt('nl.tr_ai_note') ?></p><?php endif; ?>
<?php if ($dr && $aiOn): ?><div class="card" id="nl-tr-ai"><div class="btn-row"><button type="button" class="btn btn--sm" id="nl-tr-all">✨ <?= tt('nl.tr_all') ?></button><button type="button" class="btn btn--ghost btn--sm" id="nl-tr-again" data-q="<?= tt('nl.tr_again_q') ?>">↻ <?= tt('nl.tr_again') ?></button><span class="hint" id="nl-tr-prog" aria-live="polite"></span></div><p class="hint" style="margin:8px 0 0;text-align:justify;"><?= tt('nl.tr_ai_how') ?></p></div>
<?php elseif ($dr): ?><p class="flash flash--info"><?= tt('nl.tr_ai_off') ?></p><?php endif; ?>
<form method="post" action="<?= $selfT ?>" data-lock id="nl-tr-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tl" value="<?= e($tl) ?>">
<div class="card">
  <div class="nl-tr"><div><label class="h"><?= tt('nl.subject') ?> — <?= e(t('nl.tr_orig', ['l' => NL_LANGS[$I['lang']] ?? $I['lang']])) ?></label><p class="src" data-src><?= e((string)$I['subject']) ?></p></div><div><label class="h"><?= tt('nl.subject') ?> — <?= e(NL_LANGS[$tl]) ?><?php if ($dr && $aiOn): ?> <button type="button" class="btn btn--ghost btn--sm" data-tr title="<?= tt('nl.tr_one') ?>" style="padding:1px 8px;margin-left:6px;">✨</button> <span class="hint" data-msg></span><?php endif; ?></label><input name="subject" maxlength="200" value="<?= e((string)($V['subject'] ?? '')) ?>"<?= $ro2 ?>></div></div>
  <div class="nl-tr"><div><label class="h"><?= tt('nl.preheader') ?></label><?php $ph0 = trim((string)($I['preheader'] ?? '')); ?><p class="src"<?= $ph0 !== '' ? ' data-src' : '' ?>><?= $ph0 !== '' ? e($ph0) : '—' ?></p></div><div><label class="h"><?= tt('nl.preheader') ?> — <?= e(NL_LANGS[$tl]) ?><?php if ($ph0 !== ''): ?><?php if ($dr && $aiOn): ?> <button type="button" class="btn btn--ghost btn--sm" data-tr title="<?= tt('nl.tr_one') ?>" style="padding:1px 8px;margin-left:6px;">✨</button> <span class="hint" data-msg></span><?php endif; ?><?php endif; ?></label><input name="preheader" maxlength="200" value="<?= e((string)($V['preheader'] ?? '')) ?>"<?= $ro2 ?>></div></div>
  <?php $fl = ['text' => t('nl.tr_f_text'), 'title' => t('nl.f_title'), 'label' => t('nl.f_label')]; foreach ($I['blocks'] as $b): $w = nl_block_words($b); if (!$w) continue; $k = (string)($b['k'] ?? ''); if ($k === '') continue; $x = (array)($V['b'][$k] ?? []); $stale = nl_block_words($x) && ($x['h'] ?? '') !== nl_block_sig($b); ?>
  <?php foreach ($w as $f => $src): $long = $f === 'text' && ($b['t'] ?? '') !== 'h'; ?>
  <div class="nl-tr"><div><label class="h"><?= e($bl0[$b['t']] ?? (string)$b['t']) ?> · <?= e($fl[$f]) ?></label><p class="src" data-src><?= e($src) ?></p></div>
    <div><label class="h"><?= e(NL_LANGS[$tl]) ?><?php if ($stale): ?> <?= pill('bad', t('nl.tr_changed')) ?><?php endif; ?><?php if ($dr && $aiOn): ?> <button type="button" class="btn btn--ghost btn--sm" data-tr title="<?= tt('nl.tr_one') ?>" style="padding:1px 8px;margin-left:6px;">✨</button> <span class="hint" data-msg></span><?php endif; ?></label>
      <?php if ($long): ?><textarea name="w[<?= e($k) ?>][<?= $f ?>]" rows="<?= max(3, min(14, (int) ceil(mb_strlen($src) / 70) + substr_count($src, "\n"))) ?>" maxlength="6000"<?= $ro2 ?>><?= e((string)($x[$f] ?? '')) ?></textarea>
      <?php else: ?><input name="w[<?= e($k) ?>][<?= $f ?>]" maxlength="<?= $f === 'label' ? 80 : 200 ?>" value="<?= e((string)($x[$f] ?? '')) ?>"<?= $ro2 ?>><?php endif; ?>
      <?php if ($stale && $dr && $f === array_key_first($w)): ?><label class="chk hint" style="margin:4px 0 0;"><input type="checkbox" name="w[<?= e($k) ?>][seen]" value="1"> <?= tt('nl.tr_seen') ?></label><?php endif; ?></div></div>
  <?php endforeach; endforeach; ?>
</div>
<?php if ($dr): ?><div class="card">
  <label class="chk" style="margin:0 0 4px;"><input type="checkbox" name="ok" value="1"<?= !empty($V['ok']) ? ' checked' : '' ?>> <b><?= tt('nl.tr_ok') ?></b></label><p class="hint" style="margin:0 0 12px;text-align:justify;"><?= tt('nl.tr_ok_hint') ?></p>
  <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="action" value="nl_tr_save">✓ <?= tt('nl.save') ?></button>
    <button class="btn btn--sm" type="submit" name="action" value="nl_tr_test" data-busy="…">✉ <?= tt('nl.tr_test') ?></button>
   
    <?php if ($st['exists']): ?><button class="btn btn--ghost btn--sm" type="submit" name="action" value="nl_tr_remove" data-confirm="<?= tt('nl.tr_remove_q') ?>" style="margin-left:auto;">🗑 <?= tt('nl.tr_remove') ?></button><?php endif; ?></div>
</div><?php endif; ?>
</form>
<?php if ($dr && $aiOn): ?><script nonce="<?= csp_nonce() ?>">(function(){ if(!window.GASAI||!GASAI.csrf) return;
  var TO=<?= json_encode($tl) ?>, FROM=<?= json_encode((string)$I['lang']) ?>, HEAD=<?= json_encode((string)$I['title']) ?>, T=<?= json_encode(['prog' => t('nl.tr_progress'), 'done' => t('nl.tr_done'), 'stop' => t('nl.tr_stop'), 'none' => t('nl.tr_nothing')], JSON_UNESCAPED_UNICODE) ?>;
  var rows=[].slice.call(document.querySelectorAll('.nl-tr')).filter(function(r){return r.querySelector('[data-src]')&&r.querySelector('[data-tr]');}), prog=document.getElementById('nl-tr-prog'), busy=false;
  function field(r){return r.querySelector('textarea[name],input[name][maxlength]');}
  function plain(s){var d=document.createElement('textarea'); d.innerHTML=String(s||''); return d.value;}
  function one(r){ var b=r.querySelector('[data-tr]'), f=field(r), m=r.querySelector('[data-msg]'), fd=new FormData();
    fd.append('kind','translate_to'); fd.append('page','newsletter'); fd.append('ctx',JSON.stringify({title:'Newsletter',headline:HEAD,to:TO,from:FROM})); fd.append('prompt',r.querySelector('[data-src]').textContent);
    b.disabled=true; m.textContent='…';
    return fetch('index.php?p=ai',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-CSRF':GASAI.csrf}}).then(function(x){return x.json();}).then(function(x){ b.disabled=false;
      if(x&&x.ok&&x.text){ var v=String(x.text); if(f.tagName!=='TEXTAREA') v=v.replace(/\s*\n+\s*/g,' '); var mx=parseInt(f.getAttribute('maxlength')||'0',10); if(mx&&v.length>mx) v=v.slice(0,mx); f.value=v; m.textContent='✓'; return '';}
      var e=plain((x&&x.error)||'error'); m.textContent=e; return e; }).catch(function(){ b.disabled=false; m.textContent='network'; return 'network'; }); }
  rows.forEach(function(r){ r.querySelector('[data-tr]').addEventListener('click',function(){ if(!busy) one(r); }); });
  function many(all){ if(busy) return; var list=rows.filter(function(r){return all||!field(r).value.trim();}); if(!list.length){ prog.textContent=T.none; return; } busy=true; var i=0, bad=0;
    (function next(){ if(i>=list.length){ busy=false; prog.textContent=T.done; return; } prog.textContent=T.prog.replace('{i}',i+1).replace('{n}',list.length);
      one(list[i++]).then(function(e){ if(e){ bad++; if(bad>=2){ busy=false; prog.textContent=T.stop.replace('{e}',e); return; } } next(); }); })(); }
  var a=document.getElementById('nl-tr-all'), g=document.getElementById('nl-tr-again');
  if(a) a.addEventListener('click',function(){many(false);}); if(g) g.addEventListener('click',function(){ if(window.confirm(g.getAttribute('data-q'))) many(true); });
  if(/[?&]auto=1(&|$)/.test(location.search)){ try{history.replaceState(null,'',location.href.replace(/([?&])auto=1&?/,'$1').replace(/[?&]$/,''));}catch(e){} many(false); }
})();</script><?php endif; ?>
<?php layout_foot(); return; }
$self = url('newsletter', ['id' => $id]); $draft = $I['status'] === 'draft'; $send = nl_can_send(); $aud = $I['audience']; $site = nl_site_of($I); $ks = function_exists('kym_has') && kym_has(); $rc = $draft ? nl_audience($aud, $site, nl_dept($I)) : []; $cnt = nl_audience_counts($rc);
$S = $draft ? null : nl_stats($id); $ro = $draft ? '' : ' readonly'; $dis = $draft ? '' : ' disabled'; $bl = ['h' => t('nl.b_h'), 'p' => t('nl.b_p'), 'img' => t('nl.b_img'), 'btn' => t('nl.b_btn'), 'card' => t('nl.b_card'), 'hr' => t('nl.b_hr')];
layout_head($I['title'] . ' · ' . t($isHr ? 'nl.hr_title' : 'nl.title'), $isHr ? 'hr_newsletters' : 'web_sales');
?>
<p class="eyebrow"><a href="<?= nl_list_url($I) ?>"><?= tt($isHr ? 'nl.hr_title' : 'nl.title') ?></a> › <?= e($I['title']) ?></p>
<div class="page-head"><div><h1>✉ <?= e($I['title']) ?> <?= nl_pill((string)$I['status']) ?><?= nl_appr_pill($I) ?><?= $ks ? ' ' . kym_pill($site) : '' ?></h1><p class="lede" style="margin-bottom:0;"><?= e((string)$I['created_name']) ?> · <?= e(fmt_date(substr((string)$I['created_at'], 0, 10))) ?><?= $I['queued_at'] ? ' · ' . e(t('nl.queued_by', ['n' => (string)$I['queued_name'], 'd' => fmt_datetime($I['queued_at'])])) : '' ?></p></div>
  <div class="btn-row"><a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id, 'preview' => 1]) ?>" target="_blank" rel="noopener">👁 <?= tt('nl.preview') ?></a>
    <form method="post" action="<?= $self ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="nl_copy"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">⧉ <?= tt('nl.copy') ?></button></form>
    <?php if (!$draft && $send && nl_web_has() && !$isHr && $site === 'golden' && in_array($I['status'], ['sending', 'sent'], true)): $wp0 = !empty($I['web_publish']); ?><form method="post" action="<?= $self ?>" class="inline"<?= $wp0 ? ' data-confirm="' . tt('nl.web_off_q') . '"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="nl_web"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">🌐 <?= tt($wp0 ? 'nl.web_off' : 'nl.web_on') ?></button></form><?php endif; ?>
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
<?php if (!empty($S['langs']) && count($S['langs']) > 1): ?><div class="card"><h2>🌐 <?= tt('nl.st_langs') ?></h2><div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('nl.c_lang') ?></th><th class="num"><?= tt('nl.c_sent') ?></th><th class="num"><?= tt('nl.c_open') ?></th><th class="num"><?= tt('nl.c_click') ?></th></tr></thead><tbody>
  <?php $rdyS = nl_tr_ready($I); foreach ($S['langs'] as $r): $l0 = (string)$r['lang']; $got = in_array($l0, $rdyS, true) ? $l0 : (string)$I['lang']; ?><tr><td><?= e(NL_LANGS[$l0 !== '' ? $l0 : 'en'] ?? $l0) ?><?= $got !== ($l0 !== '' ? $l0 : 'en') ? ' <span class="hint">→ ' . e(NL_LANGS[$got] ?? $got) . '</span>' : '' ?></td><td class="num"><?= (int)$r['sent'] ?> / <?= (int)$r['n'] ?></td><td class="num"><?= (int)$r['opened'] ?></td><td class="num"><?= (int)$r['clicked'] ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div><?php endif; ?>
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
    <div class="field"><label><?= tt('nl.lang') ?></label><select name="lang" data-nosearch<?= $dis ?>><?php foreach (NL_LANGS as $k => $l): ?><option value="<?= $k ?>"<?= $I['lang'] === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select><p class="hint"><?= tt('nl.lang_hint') ?></p></div>
  </div>
  <div class="field"><label><?= tt('nl.preheader') ?></label><input name="preheader" maxlength="200" value="<?= e((string)$I['preheader']) ?>"<?= $ro ?>><p class="hint"><?= tt('nl.preheader_hint') ?></p></div>
<?php if (nl_web_has() && !$isHr && $site === 'golden'): $wp = !empty($I['web_publish']); $ws = (string)($I['web_slug'] ?? ''); ?>
  <div class="field"><label><?= tt('nl.web') ?></label>
    <label style="display:flex;gap:8px;align-items:center;font-weight:400;"><input type="checkbox" name="web_publish" value="1"<?= $wp ? ' checked' : '' ?><?= $dis ?> style="width:auto;"> <?= tt('nl.web_tick') ?></label>
    <input name="web_slug" maxlength="80" pattern="[A-Za-z0-9\- ]*" value="<?= e($ws) ?>" placeholder="2026-12-topic"<?= $ro ?> style="margin-top:6px;max-width:360px;">
    <p class="hint"><?= tt('nl.web_hint') ?><?php if ($wp && $ws !== ''): ?> <a href="https://www.goldenas.com.gr/news.html?a=<?= e(rawurlencode($ws)) ?>" target="_blank" rel="noopener">goldenas.com.gr/news.html?a=<?= e($ws) ?></a><?php endif; ?></p></div>
<?php endif; ?>
</div>
<?php if ($draft && nl_ai_on()): ?>
<div class="card" id="ai"><h2>✨ <?= tt('nl.ai_title') ?></h2><p class="hint"><?= tt('nl.ai_hint') ?></p>
  <div class="field"><textarea name="brief" rows="4" maxlength="3000" placeholder="<?= tt('nl.ai_ph') ?>"></textarea></div>
  <div class="btn-row"><label class="chk" style="margin:0;"><input type="checkbox" name="news" value="1"> <?= tt('nl.ai_news') ?></label><label class="chk" style="margin:0;"><input type="checkbox" name="replace" value="1" checked> <?= tt('nl.ai_replace') ?></label><button class="btn btn--sm" type="submit" name="action" value="nl_ai" data-busy="<?= tt('nl.ai_busy') ?>">✨ <?= tt('nl.ai_btn') ?></button></div>
</div>
<?php endif; ?>
<div class="card" id="blocks"><div class="card-head"><h2>🧱 <?= tt('nl.s_blocks') ?> <span class="count"><?= count($I['blocks']) ?></span></h2><span class="hint"><?= tt('nl.blocks_hint') ?></span></div>
  <?php foreach ($I['blocks'] as $i => $b): $t = (string)($b['t'] ?? 'p'); $k = 'b[' . $i . ']'; ?>
  <div class="nl-block"><div class="nl-bhead"><b><?= e($bl[$t] ?? $t) ?></b><input type="hidden" name="<?= $k ?>[t]" value="<?= e($t) ?>"><input type="hidden" name="<?= $k ?>[k]" value="<?= e((string)($b['k'] ?? '')) ?>"><?php if ($draft): ?><label class="hint"><?= tt('nl.pos') ?> <input name="<?= $k ?>[pos]" type="number" value="<?= ($i + 1) * 10 ?>" style="width:70px;"></label><label class="chk hint" style="margin:0;"><input type="checkbox" name="<?= $k ?>[del]" value="1"> <?= tt('nl.remove') ?></label><?php endif; ?></div>
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
<?php if ($TRH): $rdy = nl_tr_ready($I); $lc = $draft ? nl_lang_counts($I, $rc) : []; ?>
<div class="card" id="langs"><div class="card-head"><h2>🌐 <?= tt('nl.tr_title') ?> <span class="count"><?= 1 + count($rdy) ?></span></h2><span class="hint"><?= tt('nl.tr_hint') ?></span></div>
  <div class="table-scroll"><table class="tbl" data-nosort><thead><tr><th><?= tt('nl.c_lang') ?></th><th><?= tt('th.status') ?></th><?php if ($draft): ?><th class="num"><?= tt('nl.tr_readers') ?></th><?php endif; ?><th></th></tr></thead><tbody>
  <?php foreach (NL_LANGS as $lk2 => $ln): $prim = $lk2 === $I['lang']; $st = $prim ? null : nl_tr_state($I, $lk2); ?>
  <tr><td><b><?= e($ln) ?></b> <span class="hint"><?= strtoupper(['el' => 'gr', 'uk' => 'ua'][$lk2] ?? $lk2) ?></span></td>
    <td><?php if ($prim): ?><?= pill('green', t('nl.tr_primary')) ?><?php else: ?><?= pill($st['ok'] && in_array($lk2, $rdy, true) ? 'green' : ($st['exists'] ? 'gold' : 'mute'), t($st['ok'] && in_array($lk2, $rdy, true) ? 'nl.tr_s_ready' : ($st['exists'] ? 'nl.tr_s_draft' : 'nl.tr_s_none'))) ?><?php if ($st['exists']): ?> <span class="hint"><?= e(t('nl.tr_prog', ['d' => $st['done'], 'n' => $st['n']])) ?></span><?php if ($st['stale']): ?> <?= pill('bad', t('nl.tr_stale', ['n' => $st['stale']])) ?><?php endif; endif; endif; ?></td>
    <?php if ($draft): ?><td class="num"><?= (int)($lc[$lk2] ?? 0) ?></td><?php endif; ?>
    <td style="text-align:right;white-space:nowrap;"><?php if (!$prim): ?><a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id, 'tl' => $lk2]) ?>">✎ <?= tt('nl.tr_open') ?></a>
      <?php if ($draft && nl_ai_on() && !$st['exists'] && ($I['appr_state'] ?? '') !== 'pending'): ?> <a class="btn btn--ghost btn--sm" href="<?= url('newsletter', ['id' => $id, 'tl' => $lk2, 'auto' => 1]) ?>">✨ <?= tt('nl.tr_ai') ?></a><?php endif; ?>
      <?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <?php if ($draft): ?><p class="hint" style="margin:8px 0 0;text-align:justify;"><?= tt('nl.tr_readers_hint') ?></p><?php endif; ?>
</div>
<?php endif; ?>
<div class="card" id="audience"><div class="card-head"><h2>👥 <?= tt('nl.s_aud') ?><?php if ($draft): ?> <span class="count"><?= count($rc) ?></span><?php endif; ?></h2><span class="hint"><?= tt('nl.aud_hint') ?></span></div>
  <?php if ($ks): ?><p class="flash flash--info" style="margin:0 0 10px;"><?= kym_pill($site) ?> <?= e(t($site === 'kyma' ? 'nl.aud_site_kyma' : 'nl.aud_site_golden')) ?><?php if ($draft && !nl_ready($site)): ?> <b><?= tt($site === 'kyma' ? 'nl.no_mailer_kyma' : 'nl.no_mailer') ?></b><?php endif; ?></p><?php endif; ?>
  <?php if ($isHr): ?><p class="flash flash--info" style="margin:0 0 10px;"><?= tt('nl.hr_aud_hint') ?> <a href="<?= url('contacts') ?>"><?= tt('nav.contacts') ?> ›</a></p><?php endif; ?>
  <?php $allL = !$isHr && function_exists('nl_lists') ? nl_lists() : []; if ($allL): ?><div class="field"><label><?= tt('nl.aud_lists') ?> <a class="hint" href="<?= url('newsletters', ['tab' => 'lists']) ?>"><?= tt('nl.aud_lists_manage') ?> ›</a></label><div style="display:flex;flex-wrap:wrap;gap:4px 16px;"><?php foreach ($allL as $L): ?><label class="chk" style="margin:0;"><input type="checkbox" name="lists[]" value="<?= (int)$L['id'] ?>"<?= in_array((int)$L['id'], array_map('intval', (array)($aud['lists'] ?? [])), true) ? ' checked' : '' ?><?= $dis ?>> <b><?= e($L['name']) ?></b> <span class="hint"><?= count(nl_list_members($L)) ?></span></label><?php endforeach; ?></div></div><?php elseif ($draft && !$isHr): ?><p class="hint"><?= tt('nl.aud_no_lists') ?> <a href="<?= url('newsletters', ['tab' => 'lists', 'new' => 1]) ?>"><?= tt('nl.l_new') ?> ›</a></p><?php endif; ?>
  <div class="field"><label><?= tt('nl.aud_groups') ?></label><div style="display:flex;flex-wrap:wrap;gap:4px 16px;"><?php foreach (nl_groups(nl_dept($I)) as $g => $lab): ?><label class="chk" style="margin:0;"><input type="checkbox" name="groups[]" value="<?= $g ?>"<?= in_array($g, (array)($aud['groups'] ?? []), true) ? ' checked' : '' ?><?= $dis ?>> <?= e($lab) ?><?= isset($cnt[$g]) ? ' <span class="hint">' . (int)$cnt[$g] . '</span>' : '' ?></label><?php endforeach; ?></div></div>
  <div class="grid grid-3">
    <div class="field"<?= $isHr ? ' hidden' : '' ?>><label><?= tt('nl.aud_web') ?></label><?php foreach ($isHr ? [] : NL_TOPICS as $tp): ?><label class="chk"><input type="checkbox" name="topics[]" value="<?= $tp ?>"<?= in_array($tp, (array)($aud['topics'] ?? []), true) ? ' checked' : '' ?><?= $dis ?>> <?= tt('ct.sub_' . $tp) ?></label><?php endforeach; ?><?= isset($cnt['web']) ? '<span class="hint">' . (int)$cnt['web'] . '</span>' : '' ?></div>
    <div class="field"><label><?= tt('nl.aud_country') ?></label><input name="country" maxlength="80" value="<?= e((string)($aud['country'] ?? '')) ?>"<?= $ro ?>><p class="hint"><?= tt('nl.aud_country_hint') ?></p></div>
    <div class="field"><label><?= tt('nl.aud_extra') ?></label><textarea name="extra" rows="2" maxlength="4000"<?= $ro ?>><?= e((string)($aud['extra'] ?? '')) ?></textarea><p class="hint"><?= tt('nl.aud_extra_hint') ?></p></div>
  </div>
  <?php if ($draft && $rc): ?><details><summary class="hint" style="cursor:pointer;"><?= e(t('nl.aud_list', ['n' => count($rc)])) ?></summary><div class="table-scroll" style="max-height:260px;"><table class="tbl"><tbody><?php foreach (array_slice($rc, 0, 400) as $r): ?><tr><td><?= e($r['name'] ?: '—') ?></td><td class="hint"><?= e($r['email']) ?></td><td><?= e($r['company']) ?></td><td class="hint"><?= e(nl_group_label($r['grp'])) ?></td></tr><?php endforeach; ?></tbody></table></div></details><?php endif; ?>
</div>
<?php if ($draft && nl_appr_has() && nl_appr_on()): $as = nl_appr_state($I); $apv = nl_approver(); ?>
<div class="card" id="approval"><div class="card-head"><h2>✔ <?= tt('nl.ap_title') ?><?= nl_appr_pill(['status' => 'draft', 'appr_state' => $as]) ?></h2><span class="hint"><?= tt($apv ? 'nl.ap_hint_apv' : 'nl.ap_hint') ?></span></div>
  <?php if ($as === 'pending'): ?>
    <p><?= e(t('nl.ap_asked', ['u' => (string)$I['appr_req_name'], 'd' => fmt_datetime((string)$I['appr_req_at']), 'n' => (int)$I['appr_n']])) ?><?= !empty($I['appr_note']) ? ' — <i>' . e((string)$I['appr_note']) . '</i>' : '' ?></p>
    <?php if ($apv): ?>
      <div class="field"><label><?= tt('nl.ap_reply') ?></label><textarea name="appr_reply" rows="2" maxlength="500"></textarea><p class="hint"><?= tt('nl.ap_reply_hint') ?></p></div>
      <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="action" value="nl_appr_approve">✔ <?= tt('nl.ap_approve') ?></button>
        <button class="btn btn--sm" type="submit" name="action" value="nl_appr_approve_send" data-confirm="<?= e(t('nl.queue_q', ['n' => count($rc)])) ?>">▶ <?= e(t('nl.ap_approve_send', ['n' => count($rc)])) ?></button>
        <button class="btn btn--ghost btn--sm" type="submit" name="action" value="nl_appr_return">↩ <?= tt('nl.ap_return') ?></button></div>
    <?php else: ?><div class="btn-row"><span class="hint"><?= tt('nl.ap_locked') ?></span><?php if ($send || (int)($u['id'] ?? 0) === (int)$I['appr_req_by']): ?><button class="btn btn--ghost btn--sm" type="submit" name="action" value="nl_appr_withdraw" style="margin-left:auto;">↩ <?= tt('nl.ap_withdraw') ?></button><?php endif; ?></div><?php endif; ?>
  <?php elseif ($as === 'approved'): ?>
    <p><?= pill('green', t('nl.ap_s_approved')) ?> <?= e(t('nl.ap_by', ['u' => (string)$I['appr_name'], 'd' => fmt_datetime((string)$I['appr_at']), 'n' => (int)$I['appr_n']])) ?><?= !empty($I['appr_reply']) ? ' — <i>' . e((string)$I['appr_reply']) . '</i>' : '' ?></p><p class="hint"><?= tt('nl.ap_change_hint') ?></p>
  <?php elseif (!$apv): ?>
    <?php if ($as === 'returned'): ?><p class="flash flash--bad"><?= e(t('nl.ap_back', ['u' => (string)$I['appr_name'], 'd' => fmt_datetime((string)$I['appr_at'])])) ?> — <?= e((string)$I['appr_reply']) ?></p><?php endif; ?>
    <div class="field"><label><?= tt('nl.ap_note') ?></label><input name="appr_note" maxlength="300"></div>
    <div class="btn-row"><button class="btn btn--gold btn--sm" type="submit" name="action" value="nl_appr_request" data-confirm="<?= e(t('nl.ap_request_q', ['n' => count($rc)])) ?>">✔ <?= tt('nl.ap_request') ?></button></div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php if ($draft): $lk = nl_appr_has() && ($I['appr_state'] ?? '') === 'pending'; $ms = nl_may_send($I) && nl_appr_ok($I); ?>
<div class="card"><div class="btn-row">
  <?php if (!$lk): ?><button class="btn btn--gold btn--sm" type="submit" name="action" value="nl_save">✓ <?= tt('nl.save') ?></button><?php endif; ?>
  <button class="btn btn--sm" type="submit" name="action" value="nl_test" data-busy="…">✉ <?= tt('nl.test') ?></button><span class="hint"><?= e(t('nl.test_hint', ['e' => (string)($u['email'] ?? '')])) ?></span>
  <?php if ($ms): ?><button class="btn btn--sm" type="submit" name="action" value="nl_queue" data-confirm="<?= e(t('nl.queue_q', ['n' => count($rc)])) ?>" style="margin-left:auto;">▶ <?= e(t('nl.queue', ['n' => count($rc)])) ?></button><?php else: ?><span class="hint" style="margin-left:auto;"><?= tt(nl_appr_has() && nl_appr_on() ? 'nl.ap_needed' : 'nl.queue_who') ?></span><?php endif; ?>
</div></div>
<?php endif; ?>
</form>
<script nonce="<?= csp_nonce() ?>">(function(){ var f = document.getElementById('nl-form'); if (!f) return; var a = document.createElement('input'); a.type = 'hidden'; a.name = 'action'; a.value = 'nl_save';
  f.querySelectorAll('[data-nl-add]').forEach(function(b){ b.addEventListener('click', function(){ if (!f.querySelector('input[type=hidden][name=action]')) f.appendChild(a); }); }); })();</script>
<?php layout_foot();

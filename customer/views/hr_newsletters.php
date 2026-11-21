<?php
/** HR › Newsletters (GAS-CP-R97-160): newsletters to schools and recruiting partners — prepared and followed by HR, sent by the newsletter module of
 *  Commercial › Web Sales (same template, mailbox, daily ceiling and approval by CEO / General Manager). ?p=hr_newsletters */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('nl_can_dept') || !nl_dept_has() || !nl_can_dept('hr')) { http_response_code(403); layout_head(t('nl.hr_title'), 'hr_newsletters'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
$rows = nl_issues('hr'); $self = url('hr_newsletters'); $partners = nl_hr_partners(); $groups = nl_groups('hr');
$sent = array_filter($rows, fn($r) => (int)$r['n_sent'] > 0); $tS = array_sum(array_map(fn($r) => (int)$r['n_sent'], $rows)); $tO = array_sum(array_map(fn($r) => (int)$r['n_open'], $rows)); $tC = array_sum(array_map(fn($r) => (int)$r['n_click'], $rows)); $tU = array_sum(array_map(fn($r) => (int)$r['n_unsub'], $rows));
$aud = nl_audience(['groups' => NL_HR_GROUPS], 'golden', 'hr'); $audBy = nl_audience_counts($aud);
if (!empty($_GET['sample'])) nl_md_sample_send();   // R97-175
layout_head(t('nl.hr_title'), 'hr_newsletters');
?>
<div class="page-head"><div><h1>✉ <?= tt('nl.hr_title') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('nl.hr_lede') ?></p></div></div>
<?php if (!nl_ready()): ?><p class="flash flash--bad"><?= tt('nl.no_mailer') ?></p><?php endif; ?>
<div class="kpi kpi--5" style="margin-bottom:10px;">
  <div class="stat"><b><?= count($sent) ?></b><?= tt('nl.hr_k_issues') ?><span class="s"><?= count($rows) - count($sent) ?> <?= tt('nl.hr_k_drafts') ?></span></div>
  <div class="stat"><b><?= $tS ?></b><?= tt('nl.hr_k_rcpt') ?></div>
  <div class="stat gold"><b><?= e(nl_pct($tO, $tS)) ?></b><?= tt('nl.hr_k_open') ?><span class="s"><?= $tO ?></span></div>
  <div class="stat gold"><b><?= e(nl_pct($tC, $tS)) ?></b><?= tt('nl.hr_k_click') ?><span class="s"><?= $tC ?></span></div>
  <a class="stat" href="#partners"><b><?= count($aud) ?></b><?= tt('nl.hr_k_aud') ?><span class="s"><?= count($partners) ?> <?= tt('nl.hr_k_orgs') ?> · <?= $tU ?> <?= tt('nl.c_unsub') ?></span></a>
</div>
<div class="card">
  <form method="post" action="<?= $self ?>" class="filters" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_create"><input type="hidden" name="dept" value="hr">
    <div class="field" style="flex:1;min-width:280px;"><label><?= tt('nl.new') ?></label><input name="title" maxlength="200" required placeholder="<?= tt('nl.hr_title_ph') ?>"></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('nl.create') ?></button></div>
  </form>
  <p class="hint" style="margin:8px 0 0;"><?= tt('nl.hr_appr_hint') ?></p>
</div>
<div class="card" id="import"><details><summary><b>⇪ <?= tt('nl.imp_title') ?></b></summary>
  <form method="post" action="<?= $self ?>" enctype="multipart/form-data" class="filters" data-lock style="margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="nl_import"><input type="hidden" name="dept" value="hr">
    <div class="field"><label><?= tt('nl.imp_md') ?></label><input type="file" name="md" accept=".md,.markdown,.txt,text/markdown,text/plain" required></div>
    <div class="field"><label><?= tt('nl.imp_dir') ?></label><input type="file" name="picdir[]" webkitdirectory multiple></div>
    <div class="field"><label><?= tt('nl.imp_pics') ?></label><input type="file" name="pics[]" accept="image/jpeg,image/png,image/gif" multiple></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit">⇪ <?= tt('nl.imp_btn') ?></button></div>
  </form>
  <p class="hint" style="margin:8px 0 0;"><?= tt('nl.imp_hint') ?> <a href="<?= url('hr_newsletters', ['sample' => 1]) ?>"><?= tt('nl.imp_sample') ?> ↓</a></p>
</details></div>
<div class="card">
  <div class="card-head"><h2><?= tt('nl.t_issues') ?> <span class="count"><?= count($rows) ?></span></h2></div>
  <?php if (!$rows): ?><p class="hint"><?= tt('nl.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('nl.c_title') ?></th><th><?= tt('th.status') ?></th><th class="num"><?= tt('nl.c_rcpt') ?></th><th class="num"><?= tt('nl.c_sent') ?></th><th class="num"><?= tt('nl.c_open') ?></th><th class="num"><?= tt('nl.c_click') ?></th><th class="num"><?= tt('nl.c_unsub') ?></th><th><?= tt('nl.c_when') ?></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $ns = (int)$r['n_sent']; ?><tr><td><a href="<?= url('newsletter', ['id' => (int)$r['id']]) ?>"><b><?= e($r['title']) ?></b></a><br><span class="hint"><?= e($r['subject']) ?> · <?= e(strtoupper((string)$r['lang'])) ?></span></td><td><?= nl_pill((string)$r['status']) ?><?= nl_appr_pill($r) ?></td>
    <td class="num"><?= (int)$r['n_rcpt'] ?: '—' ?></td><td class="num"><?= $ns ?: '—' ?></td><td class="num"><?= $ns ? (int)$r['n_open'] . ' <span class="hint">' . e(nl_pct((int)$r['n_open'], $ns)) . '</span>' : '—' ?></td><td class="num"><?= $ns ? (int)$r['n_click'] . ' <span class="hint">' . e(nl_pct((int)$r['n_click'], $ns)) . '</span>' : '—' ?></td><td class="num"><?= $ns ? (int)$r['n_unsub'] : '—' ?></td>
    <td class="date hint"><?= e(fmt_date(substr((string)($r['sent_at'] ?: $r['queued_at'] ?: $r['created_at']), 0, 10))) ?><br><?= e((string)($r['queued_name'] ?: $r['created_name'])) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><p class="hint" style="margin:8px 0 0;"><?= tt('nl.hr_stats_hint') ?></p><?php endif; ?>
</div>
<div class="card" id="partners">
  <div class="card-head"><h2>🎓 <?= tt('nl.hr_partners') ?> <span class="count"><?= count($partners) ?></span></h2><span class="hint"><?php foreach ($groups as $g => $lab): ?><?= e($lab) ?>: <b><?= (int)($audBy[$g] ?? 0) ?></b> &nbsp; <?php endforeach; ?></span></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('nl.hr_partners_hint') ?></p>
  <form method="post" action="<?= $self ?>" class="filters" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="nl_hr_partner">
    <div class="field" style="flex:2;min-width:200px;"><label><?= tt('nl.hr_p_org') ?> *</label><input name="org" maxlength="160" required></div>
    <div class="field" style="flex:2;min-width:200px;"><label><?= tt('nl.hr_p_type') ?> *</label><select name="kind" required data-nosearch><?php foreach ($groups as $g => $lab): ?><option value="<?= $g ?>"><?= e($lab) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('nl.hr_p_country') ?></label><input name="country" maxlength="80"></div>
    <div class="field" style="flex:1;min-width:160px;"><label><?= tt('nl.hr_p_contact') ?></label><input name="contact" maxlength="160"></div>
    <div class="field"><label><?= tt('nl.hr_p_position') ?></label><input name="position" maxlength="120"></div>
    <div class="field" style="flex:1;min-width:200px;"><label><?= tt('nl.hr_p_email') ?> *</label><input name="email" type="email" maxlength="190" required></div>
    <div class="field"><label>&nbsp;</label><button class="btn btn--gold btn--sm" type="submit">+ <?= tt('nl.hr_p_add') ?></button></div>
  </form>
  <?php if (!$partners): ?><p class="hint"><?= tt('nl.hr_p_none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap"><thead><tr><th><?= tt('nl.hr_p_org') ?></th><th><?= tt('nl.hr_p_type') ?></th><th><?= tt('nl.hr_p_country') ?></th><th><?= tt('nl.hr_p_contact') ?></th></tr></thead><tbody>
  <?php foreach ($partners as $p): ?><tr><td><b><?= e($p['name']) ?></b><?= empty($p['is_active']) ? ' ' . pill('mute', '—') : '' ?></td><td><?= co_kind_pill(in_array((string)$p['kind'], NL_HR_GROUPS, true) ? (string)$p['kind'] : 'other') ?></td><td><?= e((string)($p['country'] ?? '')) ?></td>
    <td><?php if (!$p['contacts']): ?><span class="hint"><?= tt('nl.hr_p_nocontact') ?></span><?php else: foreach ($p['contacts'] as $c): ?><div><?= e((string)$c['full_name']) ?><?= trim((string)($c['position'] ?? '')) !== '' ? ' <span class="hint">· ' . e((string)$c['position']) . '</span>' : '' ?> <span class="hint"><?= e((string)($c['email'] ?? '')) ?: tt('nl.hr_p_noemail') ?></span><?= isset($aud[mb_strtolower((string)($c['email'] ?? ''))]) || trim((string)($c['email'] ?? '')) === '' ? '' : ' ' . pill('bad', t('nl.hr_p_out')) ?></div><?php endforeach; endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php layout_foot(); ?>

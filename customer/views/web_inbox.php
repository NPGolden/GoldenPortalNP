<?php
/** Work › Website inbox (GAS-CP-R90-002): support requests and portal access requests posted by goldenas.com.gr.  ?p=web_inbox[&id=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('web_inbox_has') || !web_inbox_has()) { http_response_code(404); layout_head(t('web.inbox'), 'web_sales'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $cur = $id ? web_inquiry($id) : null; $ks = function_exists('kym_has') && kym_has();
$site = $ks ? ($cur ? web_site_of($cur) : kym_site($_GET['site'] ?? 'golden')) : 'golden';   // R97-050: one inbox per website; an opened enquiry decides the inbox shown
$ft = preg_replace('/[^a-z_]/', '', (string)($_GET['topic'] ?? '')); if ($ft !== '' && !isset(WEB_TOPICS[$ft])) $ft = '';
$fk = in_array($_GET['kind'] ?? '', ['ticket', 'access', 'contact'], true) ? (string)$_GET['kind'] : ''; $fs = in_array($_GET['status'] ?? 'open', ['open', 'new', 'in_progress', 'done', 'spam', 'all'], true) ? (string)($_GET['status'] ?? 'open') : 'open';
$rows = web_inquiries(['kind' => $fk, 'status' => $fs === 'all' ? '' : $fs, 'topic' => $ft] + ($ks ? ['site' => $site] : []));
$keep = ['site' => $site, 'kind' => $fk, 'status' => $fs, 'topic' => $ft]; $brandName = $site === 'kyma' ? 'KYMA Marine Power' : 'Golden A/S';
$tone = fn(string $s) => pill($s === 'new' ? 'gold' : ($s === 'done' ? 'green' : ($s === 'spam' ? 'grey' : 'blue')), t('web.st_' . $s));
$prio = fn(string $p) => $p === '' ? '' : pill($p === 'vessel_stopped' ? 'red' : ($p === 'high' ? 'gold' : 'grey'), t('web.pr_' . $p));
layout_head(t('web.inbox'), 'web_sales');
?>
<?php web_sales_tabs('web_inbox'); ?>
<div class="page-head"><div><h1>🌐 <?= tt('web.inbox') ?></h1><p class="lede"><?= tt($site === 'kyma' ? 'web.inbox_hint_kyma' : 'web.inbox_hint') ?></p></div></div>
<?php if ($ks): ?><div class="tabs" style="margin:0 0 10px;"><?php foreach (KYM_SITES as $sk => $sl): $nn = web_inbox_count($sk); ?><a href="<?= url('web_inbox', ['site' => $sk]) ?>"<?= $sk === $site ? ' class="is-on"' : '' ?>><?= e($sl) ?><?= $nn ? '<span class="n">' . $nn . '</span>' : '' ?></a><?php endforeach; ?></div><?php endif; ?>
<form method="get" class="filters"><input type="hidden" name="p" value="web_inbox"><?php if ($ks): ?><input type="hidden" name="site" value="<?= e($site) ?>"><?php endif; ?>
  <select name="kind" data-nosearch data-js-submit="1"><option value=""><?= tt('web.all_kinds') ?></option><option value="ticket"<?= $fk === 'ticket' ? ' selected' : '' ?>><?= tt('web.kind_ticket') ?></option><option value="access"<?= $fk === 'access' ? ' selected' : '' ?>><?= tt('web.kind_access') ?></option><option value="contact"<?= $fk === 'contact' ? ' selected' : '' ?>><?= tt('web.kind_contact') ?></option></select>
  <select name="topic" data-nosearch data-js-submit="1"><option value=""><?= tt('web.all_topics') ?></option><?php foreach (WEB_TOPICS as $tk => $tl): if ($tk === 'careers' && !kym_careers_can()) continue; ?><option value="<?= $tk ?>"<?= $ft === $tk ? ' selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?></select>
  <select name="status" data-nosearch data-js-submit="1"><?php foreach (['open', 'new', 'in_progress', 'done', 'spam', 'all'] as $s): ?><option value="<?= $s ?>"<?= $fs === $s ? ' selected' : '' ?>><?= tt('web.st_' . $s) ?></option><?php endforeach; ?></select>
</form>
<?php if ($cur): ?>
<div class="card" id="inq">
  <div class="page-head" style="margin-bottom:8px;"><div><h2 style="margin:0;"><?= $cur['kind'] === 'ticket' ? '🎫 ' . e($cur['subject']) : ($cur['kind'] === 'contact' ? '✉ ' . e($cur['subject']) : '🔑 ' . tt('web.kind_access') . ' — ' . e($cur['company'])) ?></h2><p class="hint" style="margin:4px 0 0;"><?= $ks ? kym_pill($site) . ' ' : '' ?><?= e($cur['ref']) ?> · <?= e(fmt_datetime($cur['created_at'])) ?> · <?= $tone($cur['status']) ?> <?= $prio((string)$cur['priority']) ?><?= $cur['ticket_no'] ? ' · <a href="' . url('ticket', ['id' => (int)$cur['ticket_id']]) . '">' . e($cur['ticket_no']) . '</a>' : '' ?></p></div><a class="btn btn--ghost" href="<?= url('web_inbox', ['kind' => $fk, 'status' => $fs]) ?>">✕</a></div>
  <div class="grid2">
    <div><table class="kv">
      <tr><th><?= tt('web.contact') ?></th><td><?= e($cur['name']) ?><?= $cur['role'] !== '' ? ' · ' . e($cur['role']) : '' ?></td></tr>
      <tr><th><?= tt('web.company') ?></th><td><?= e($cur['company']) ?></td></tr>
      <tr><th>E-mail</th><td><a href="mailto:<?= e($cur['email']) ?>?subject=<?= rawurlencode($brandName . ' — ' . $cur['ref']) ?>"><?= e($cur['email']) ?></a><?= $cur['phone'] !== '' ? ' · ' . e($cur['phone']) : '' ?></td></tr>
      <?php if ($cur['kind'] === 'ticket'): ?><tr><th><?= tt('web.vessel') ?></th><td><?= e($cur['vessel']) ?><?= $cur['location'] !== '' ? ' · ' . e($cur['location']) : '' ?></td></tr><tr><th><?= tt('web.system') ?></th><td><?= tt('web.sys_' . $cur['system_kind']) ?></td></tr>
      <?php elseif ($cur['kind'] === 'contact'): ?><tr><th><?= tt('web.topic') ?></th><td><?= e(WEB_TOPICS[(string)$cur['system_kind']] ?? ((string)$cur['system_kind'] ?: '—')) ?></td></tr>
      <?php if ($ks && (int)($cur['contact_id'] ?? 0)): $lc = one('SELECT id, full_name FROM contacts WHERE id = ?', [(int)$cur['contact_id']]); if ($lc): ?><tr><th><?= tt('web.ct_linked') ?></th><td><a href="<?= url('contact', ['id' => (int)$lc['id']]) ?>"><?= e((string)$lc['full_name']) ?></a></td></tr><?php endif; endif; ?>
      <?php else: ?><tr><th><?= tt('web.vessels') ?></th><td><?= nl2br(e((string)$cur['vessels'])) ?></td></tr><?php endif; ?>
      <tr><th>IP</th><td class="hint"><?= e($cur['ip']) ?></td></tr>
      <?php if ($cur['handled_name']): ?><tr><th><?= tt('web.handled') ?></th><td><?= e($cur['handled_name']) ?> · <?= e(fmt_datetime($cur['handled_at'])) ?><?= $cur['note'] ? '<br><span class="hint">' . e($cur['note']) . '</span>' : '' ?></td></tr><?php endif; ?>
    </table></div>
    <?php $bs = function_exists('web_bridge_spec') ? web_bridge_spec($cur) : null; if ($bs): $bc = (string)($bs['class'] ?? ''); $bcol = ['A' => '#0A1B2E', 'B' => '#B8902E', 'C' => '#6B7280'][$bc] ?? '#6B7280'; ?>
    <div><h3 style="margin-top:0;"><?= tt('web.bridge_spec') ?></h3>
      <div class="card" style="padding:12px 14px;margin-bottom:12px;border-left:5px solid <?= $bcol ?>;"><b style="font-size:1.05rem;"><?= tt('web.bridge_class') ?> <?= e($bc) ?></b> · <?= tt('web.bridge_score') ?> <?= (int)($bs['score'] ?? 0) ?>/27<br><span class="hint"><?= e((string)($bs['fit'] ?? '')) ?><?php if (!empty($bs['points'])): ?> · <?= e(implode(', ', array_map(fn($k, $p) => $k . ' +' . $p, array_keys($bs['points']), $bs['points']))) ?><?php endif; ?></span></div>
      <div class="table-scroll" style="max-height:520px;overflow:auto;"><table class="tbl tbl--compact"><tbody><?php $tt_ = $bs['titles'] ?? []; foreach (($bs['labels'] ?? []) as $k => $val): if ($val === '' || $val === '—' || in_array($k, ['name', 'email', 'phone', 'company'], true)) continue; ?><tr><th style="width:42%;font-weight:600;"><?= e((string)($tt_[$k] ?? $k)) ?></th><td><?= e((string)$val) ?></td></tr><?php endforeach; ?></tbody></table></div>
      <?php if (!empty($bs['files']) && is_array($bs['files'])): ?><div style="margin-top:10px;"><b><?= tt('web.bridge_files') ?></b><ul style="margin:6px 0 0 18px;padding:0;"><?php foreach ($bs['files'] as $bf): if (empty($bf['link'])) continue; ?><li><a href="<?= e((string)$bf['link']) ?>" target="_blank" rel="noopener">📎 <?= e((string)($bf['name'] ?? 'file')) ?></a> <span class="hint"><?= e(number_format(((int)($bf['size'] ?? 0)) / 1048576, 1)) ?> MB</span></li><?php endforeach; ?></ul></div><?php endif; ?>
      <details style="margin-top:10px;"><summary class="hint" style="cursor:pointer;"><?= tt('web.message') ?></summary><div class="prose" style="white-space:pre-wrap;font-size:.85rem;"><?= e((string)$cur['message']) ?></div></details>
    </div>
    <?php else: ?>
    <div><h3 style="margin-top:0;"><?= tt('web.message') ?></h3><div class="prose" style="white-space:pre-wrap;"><?= e((string)$cur['message']) ?></div></div>
    <?php endif; ?>
  </div>
  <div class="btn-row" style="margin-top:14px;flex-wrap:wrap;gap:10px;">
    <?php if (!$cur['ticket_id'] && function_exists('web_can_convert') && web_can_convert()): $hint = trim((string)($cur['vessel'] ?: $cur['vessels'] ?: '')); $vs = all('SELECT id, name FROM vessels WHERE is_active = 1 ORDER BY (name LIKE ?) DESC, name', ['%' . ($hint !== '' ? mb_substr($hint, 0, 40) : '§none§') . '%']);
          $defCat = web_default_category((string)$cur['kind']); $defPri = ['low' => 'low', 'normal' => 'normal', 'high' => 'high', 'vessel_stopped' => 'urgent'][(string)$cur['priority']] ?? 'normal'; ?>
    <form method="post" class="card" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:10px 12px;margin:0;width:100%;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_make_ticket"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="field" style="margin:0;min-width:260px;flex:1;"><label><?= tt('v.one') ?></label><select name="vessel_id"<?= $cur['kind'] === 'ticket' ? ' required' : '' ?>><option value=""><?= $cur['kind'] === 'ticket' ? tt('web.pick_vessel') : tt('web.no_vessel_yet') ?></option><?php foreach ($vs as $v): ?><option value="<?= (int)$v['id'] ?>"<?= $hint !== '' && $v === $vs[0] && mb_stripos($v['name'], mb_substr($hint, 0, 40)) !== false ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0;"><label><?= tt('tk.category') ?></label><select name="category" data-nosearch><?php foreach (tk_categories() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $defCat ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin:0;"><label><?= tt('tk.priority') ?></label><select name="priority" data-nosearch><?php foreach (tk_priorities() as $k => $l): ?><option value="<?= e($k) ?>"<?= $k === $defPri ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn--gold">🎫 <?= tt('web.create_ticket') ?></button>
      <p class="hint" style="flex-basis:100%;margin:0;"><?= tt('web.convert_hint') ?></p></form>
    <?php endif; ?>
    <?php if ($ks && $cur['kind'] === 'contact' && (string)$cur['system_kind'] !== 'careers' && !(int)($cur['contact_id'] ?? 0) && function_exists('ct_manage') && (ct_manage() || web_can_convert())): $gr = web_guess_role($cur); ?>
    <form method="post" class="card" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;padding:10px 12px;margin:0;width:100%;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_inq_contact"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <div class="field" style="margin:0;"><label><?= tt('web.ct_role') ?></label><select name="role" data-nosearch><?php foreach (co_kinds() as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= $rk === $gr ? ' selected' : '' ?>><?= e($rl) ?></option><?php endforeach; ?></select></div>
      <label class="chk" style="margin:0 0 8px;"><input type="checkbox" name="make_org" value="1" checked> <?= e(t('web.ct_make_org', ['c' => (string)$cur['company'] ?: '—'])) ?></label>
      <button class="btn btn--gold">👤 <?= tt('web.ct_btn') ?></button>
      <p class="hint" style="flex-basis:100%;margin:0;"><?= e(t('web.ct_hint', ['b' => $brandName])) ?></p></form>
    <?php endif; ?>
    <?php if ($cur['kind'] === 'access' && function_exists('url')): ?><a class="btn btn--gold" href="<?= url('admin_users') ?>">👤 <?= tt('web.open_accounts') ?></a><?php endif; ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_inq_status"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <select name="status" data-nosearch><?php foreach (['new', 'in_progress', 'done', 'spam'] as $s): ?><option value="<?= $s ?>"<?= $cur['status'] === $s ? ' selected' : '' ?>><?= tt('web.st_' . $s) ?></option><?php endforeach; ?></select>
      <input name="note" maxlength="400" placeholder="<?= tt('web.note') ?>" value="<?= e((string)$cur['note']) ?>" style="min-width:240px;"><button class="btn btn--ghost"><?= tt('btn.save') ?></button></form>
    <?php if (is_cp_admin()): ?><form method="post" style="margin-left:auto;"><?= csrf_field() ?><input type="hidden" name="action" value="web_inq_delete"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>"><button class="btn btn--ghost btn--sm" data-confirm="<?= e(t('web.delete_confirm', ['ref' => $cur['ref']])) ?>" data-confirm-ok="<?= tt('btn.delete') ?>">🗑 <?= tt('btn.delete') ?></button></form><?php endif; ?>
  </div>
</div>
<?php endif; ?>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('web.ref') ?></th><th><?= tt('web.kind') ?></th><th><?= tt('web.contact') ?></th><th><?= tt('web.company') ?></th><th><?= tt('web.subject') ?></th><th><?= tt('web.priority') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= tt('web.empty') ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr<?= (int)$r['id'] === $id ? ' class="is-current"' : '' ?>>
  <td><a href="<?= url('web_inbox', ['id' => (int)$r['id']] + $keep) ?>#inq"><b><?= e($r['ref']) ?></b></a><?= $ks ? ' ' . kym_pill($site) : '' ?></td>
  <td><?= $r['kind'] === 'ticket' ? '🎫 ' . tt('web.kind_ticket') : ($r['kind'] === 'contact' ? '✉ ' . tt('web.kind_contact') : '🔑 ' . tt('web.kind_access')) ?></td>
  <td><?= e($r['name']) ?></td><td><?= e($r['company']) ?></td>
  <td><?= $r['kind'] === 'ticket' ? e($r['vessel']) . ' — ' . e($r['subject']) : ($r['kind'] === 'contact' ? ((string)$r['system_kind'] === 'bridge' ? '🧭 ' : '') . e(mb_substr((string)$r['subject'], 0, 90)) : e(mb_substr((string)$r['vessels'], 0, 80))) ?><?= $r['ticket_no'] ? ' · <a href="' . url('ticket', ['id' => (int)$r['ticket_id']]) . '">' . e($r['ticket_no']) . '</a>' : '' ?></td>
  <td><?= $prio((string)$r['priority']) ?></td><td><?= $tone($r['status']) ?></td><td class="hint"><?= e(fmt_datetime($r['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php if ($ks && is_cp_admin()): ?><div class="card"><h2 style="margin-top:0;">🗑 <?= tt('web.purge_title') ?></h2><p class="hint"><?= e(t($site === 'kyma' ? 'web.purge_hint_kyma' : 'web.purge_hint', ['b' => $brandName])) ?></p>
  <form method="post" class="filters" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_purge"><input type="hidden" name="site" value="<?= e($site) ?>">
    <div class="field"><label><?= tt('web.purge_older') ?></label><input name="months" type="number" min="1" max="240" value="<?= $site === 'kyma' ? 24 : 36 ?>" style="width:90px;"></div>
    <label class="chk" style="margin:0 0 8px;"><input type="checkbox" name="careers" value="1"> <?= tt('web.purge_careers') ?></label>
    <button class="btn btn--ghost btn--sm" type="submit" data-confirm="<?= e(t('web.purge_confirm', ['b' => $brandName])) ?>" data-confirm-ok="<?= tt('btn.delete') ?>">🗑 <?= tt('btn.delete') ?></button></form></div><?php endif; ?>
<?php layout_foot();

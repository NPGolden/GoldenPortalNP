<?php
/** Work › Website inbox (GAS-CP-R90-002): support requests and portal access requests posted by goldenas.com.gr.  ?p=web_inbox[&id=] */
require __DIR__ . '/_layout.php';
$u = require_staff(); if (!function_exists('web_inbox_has') || !web_inbox_has()) { http_response_code(404); layout_head(t('web.inbox'), 'web_inbox'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$id = (int)($_GET['id'] ?? 0); $cur = $id ? web_inquiry($id) : null;
$fk = in_array($_GET['kind'] ?? '', ['ticket', 'access'], true) ? (string)$_GET['kind'] : ''; $fs = in_array($_GET['status'] ?? 'open', ['open', 'new', 'in_progress', 'done', 'spam', 'all'], true) ? (string)($_GET['status'] ?? 'open') : 'open';
$rows = web_inquiries(['kind' => $fk, 'status' => $fs === 'all' ? '' : $fs]);
$tone = fn(string $s) => pill($s === 'new' ? 'gold' : ($s === 'done' ? 'green' : ($s === 'spam' ? 'grey' : 'blue')), t('web.st_' . $s));
$prio = fn(string $p) => $p === '' ? '' : pill($p === 'vessel_stopped' ? 'red' : ($p === 'high' ? 'gold' : 'grey'), t('web.pr_' . $p));
layout_head(t('web.inbox'), 'web_inbox');
?>
<p class="eyebrow"><?= tt('nav.g_work') ?></p>
<div class="page-head"><div><h1>🌐 <?= tt('web.inbox') ?></h1><p class="lede"><?= tt('web.inbox_hint') ?></p></div></div>
<form method="get" class="filters"><input type="hidden" name="p" value="web_inbox">
  <select name="kind" data-nosearch onchange="this.form.submit()"><option value=""><?= tt('web.all_kinds') ?></option><option value="ticket"<?= $fk === 'ticket' ? ' selected' : '' ?>><?= tt('web.kind_ticket') ?></option><option value="access"<?= $fk === 'access' ? ' selected' : '' ?>><?= tt('web.kind_access') ?></option></select>
  <select name="status" data-nosearch onchange="this.form.submit()"><?php foreach (['open', 'new', 'in_progress', 'done', 'spam', 'all'] as $s): ?><option value="<?= $s ?>"<?= $fs === $s ? ' selected' : '' ?>><?= tt('web.st_' . $s) ?></option><?php endforeach; ?></select>
</form>
<?php if ($cur): ?>
<div class="card" id="inq">
  <div class="page-head" style="margin-bottom:8px;"><div><h2 style="margin:0;"><?= $cur['kind'] === 'ticket' ? '🎫 ' . e($cur['subject']) : '🔑 ' . tt('web.kind_access') . ' — ' . e($cur['company']) ?></h2><p class="hint" style="margin:4px 0 0;"><?= e($cur['ref']) ?> · <?= e(fmt_datetime($cur['created_at'])) ?> · <?= $tone($cur['status']) ?> <?= $prio((string)$cur['priority']) ?><?= $cur['ticket_no'] ? ' · <a href="' . url('ticket', ['id' => (int)$cur['ticket_id']]) . '">' . e($cur['ticket_no']) . '</a>' : '' ?></p></div><a class="btn btn--ghost" href="<?= url('web_inbox', ['kind' => $fk, 'status' => $fs]) ?>">✕</a></div>
  <div class="grid2">
    <div><table class="kv">
      <tr><th><?= tt('web.contact') ?></th><td><?= e($cur['name']) ?><?= $cur['role'] !== '' ? ' · ' . e($cur['role']) : '' ?></td></tr>
      <tr><th><?= tt('web.company') ?></th><td><?= e($cur['company']) ?></td></tr>
      <tr><th>E-mail</th><td><a href="mailto:<?= e($cur['email']) ?>?subject=<?= rawurlencode('Golden A/S — ' . $cur['ref']) ?>"><?= e($cur['email']) ?></a><?= $cur['phone'] !== '' ? ' · ' . e($cur['phone']) : '' ?></td></tr>
      <?php if ($cur['kind'] === 'ticket'): ?><tr><th><?= tt('web.vessel') ?></th><td><?= e($cur['vessel']) ?><?= $cur['location'] !== '' ? ' · ' . e($cur['location']) : '' ?></td></tr><tr><th><?= tt('web.system') ?></th><td><?= tt('web.sys_' . $cur['system_kind']) ?></td></tr>
      <?php else: ?><tr><th><?= tt('web.vessels') ?></th><td><?= nl2br(e((string)$cur['vessels'])) ?></td></tr><?php endif; ?>
      <tr><th>IP</th><td class="hint"><?= e($cur['ip']) ?></td></tr>
      <?php if ($cur['handled_name']): ?><tr><th><?= tt('web.handled') ?></th><td><?= e($cur['handled_name']) ?> · <?= e(fmt_datetime($cur['handled_at'])) ?><?= $cur['note'] ? '<br><span class="hint">' . e($cur['note']) . '</span>' : '' ?></td></tr><?php endif; ?>
    </table></div>
    <div><h3 style="margin-top:0;"><?= tt('web.message') ?></h3><div class="prose" style="white-space:pre-wrap;"><?= e((string)$cur['message']) ?></div></div>
  </div>
  <div class="btn-row" style="margin-top:14px;flex-wrap:wrap;gap:10px;">
    <?php if ($cur['kind'] === 'ticket' && !$cur['ticket_id']): $vs = all('SELECT id, name FROM vessels WHERE is_active = 1 ORDER BY (name LIKE ?) DESC, name', ['%' . $cur['vessel'] . '%']); ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_make_ticket"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <select name="vessel_id" required style="min-width:260px;"><option value=""><?= tt('web.pick_vessel') ?></option><?php foreach ($vs as $v): ?><option value="<?= (int)$v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select>
      <button class="btn btn--gold">🎫 <?= tt('web.create_ticket') ?></button></form>
    <?php endif; ?>
    <?php if ($cur['kind'] === 'access' && function_exists('url')): ?><a class="btn btn--gold" href="<?= url('admin_users') ?>">👤 <?= tt('web.open_accounts') ?></a><?php endif; ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="web_inq_status"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <select name="status" data-nosearch><?php foreach (['new', 'in_progress', 'done', 'spam'] as $s): ?><option value="<?= $s ?>"<?= $cur['status'] === $s ? ' selected' : '' ?>><?= tt('web.st_' . $s) ?></option><?php endforeach; ?></select>
      <input name="note" maxlength="400" placeholder="<?= tt('web.note') ?>" value="<?= e((string)$cur['note']) ?>" style="min-width:240px;"><button class="btn btn--ghost"><?= tt('btn.save') ?></button></form>
  </div>
</div>
<?php endif; ?>
<div class="card"><div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('web.ref') ?></th><th><?= tt('web.kind') ?></th><th><?= tt('web.contact') ?></th><th><?= tt('web.company') ?></th><th><?= tt('web.subject') ?></th><th><?= tt('web.priority') ?></th><th><?= tt('th.status') ?></th><th><?= tt('th.date') ?></th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="8" class="empty"><?= tt('web.empty') ?></td></tr><?php endif; ?>
<?php foreach ($rows as $r): ?><tr<?= (int)$r['id'] === $id ? ' class="is-current"' : '' ?>>
  <td><a href="<?= url('web_inbox', ['id' => (int)$r['id'], 'kind' => $fk, 'status' => $fs]) ?>#inq"><b><?= e($r['ref']) ?></b></a></td>
  <td><?= $r['kind'] === 'ticket' ? '🎫 ' . tt('web.kind_ticket') : '🔑 ' . tt('web.kind_access') ?></td>
  <td><?= e($r['name']) ?></td><td><?= e($r['company']) ?></td>
  <td><?= $r['kind'] === 'ticket' ? e($r['vessel']) . ' — ' . e($r['subject']) : e(mb_substr((string)$r['vessels'], 0, 80)) ?><?= $r['ticket_no'] ? ' · <a href="' . url('ticket', ['id' => (int)$r['ticket_id']]) . '">' . e($r['ticket_no']) . '</a>' : '' ?></td>
  <td><?= $prio((string)$r['priority']) ?></td><td><?= $tone($r['status']) ?></td><td class="hint"><?= e(fmt_datetime($r['created_at'])) ?></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<?php layout_foot();

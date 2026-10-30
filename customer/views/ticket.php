<?php
/** One ticket: facts, workflow actions, attachments, thread, trail, linked work.   GAS-CP-R14-001 */
require __DIR__ . '/_layout.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$t  = tk_has() ? tk_load($id) : null;
if (!$t) { http_response_code(404); layout_head(t('err.notfound'), 'tickets'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$staff  = is_staff();
$isOpen = in_array($t['status'], tk_open_statuses(), true);
$canEdit = $staff || ((int)$t['created_by'] === (int)$u['id'] && $isOpen);
$assignees = $staff ? all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name") : [];
$filesByComment = []; foreach ($t['files'] as $f) $filesByComment[(int)($f['comment_id'] ?? 0)][] = $f;
$fileRow = function (array $f): string {
    $img = str_starts_with($f['mime_type'], 'image/');
    return '<a class="tk-file" href="' . url('tfile', ['id' => $f['id']]) . '" target="_blank">' . ($img ? '🖼' : '📎') . ' ' . e($f['original_name']) . ' <span class="hint">' . number_format((int)$f['size_bytes'] / 1024, 0) . ' KB</span></a>';
};
$ev = ['imported' => t('tk.ev_imported'), 'created' => t('tk.ev_created'), 'edited' => t('tk.ev_edited'), 'assigned' => t('tk.ev_assigned'), 'unassigned' => t('tk.ev_unassigned'), 'customer_replied' => t('tk.ev_customer_replied'),
       'status_open' => t('tk.ev_reopened'), 'status_in_progress' => t('tk.ev_started'), 'status_waiting_customer' => t('tk.ev_waiting'), 'status_resolved' => t('tk.ev_resolved'), 'status_closed' => t('tk.ev_closed'), 'jo_linked' => t('tk.ev_jo_linked'), 'fsr_linked' => t('tk.ev_fsr_linked'), 'project_set' => t('tk.ev_project_set'), 'vessel_set' => t('tk.ev_vessel_set'), 'rs_session' => t('rs.ev_session'), 'project_cleared' => t('tk.ev_project_cleared')];
$back = url('tickets');
layout_head($t['ticket_no'] . ' · ' . $t['title'], 'tickets');
?>
<p class="eyebrow"><a href="<?= $back ?>"><?= tt('tk.title') ?></a> › <a href="<?= url('vessel', ['id' => $t['vessel_id']]) ?>"><?= e($t['vessel']) ?></a><?= $t['project_id'] ? ' › <a href="' . url('projects', ['project' => $t['project_id']]) . '">' . e($t['project_code'] ?: $t['project_name']) . '</a>' : '' ?></p>
<div class="page-head">
  <div>
    <h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($t['ticket_no']) ?></span> <?= e($t['title']) ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e(tk_categories()[$t['category']] ?? $t['category']) ?> · <?= e(tk_priorities()[$t['priority']] ?? '') ?><?= $t['system_abbr'] ? ' · ' . e($t['system_abbr'] . ' ' . $t['system_name']) : '' ?><?= $t['sub_code'] ? ' · ' . e($t['sub_code'] . ' ' . $t['sub_name']) : '' ?><?= $staff && $t['customer_name'] ? ' · ' . e($t['customer_name']) : '' ?></p>
  </div>
  <div class="actions"><?= !$t['project_id'] ? pill('navy', t('tk.generic')) . ' ' : '' ?><?= tk_priority_pill($t['priority']) ?> <?= tk_status_pill($t['status']) ?><?php if ($canEdit): ?> <a class="btn btn--ghost btn--sm" href="<?= url('ticket_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a><?php endif; ?><?php if ($staff && function_exists('ob_can') && ob_can()): ?> <a class="btn btn--ghost btn--sm" href="<?= url('offer_edit', ['ticket' => $id]) ?>">€ <?= tt('ob.new_from_ticket') ?></a><?php endif; ?></div>
</div>

<div class="cols">
  <div>
    <div class="card">
      <h2><?= tt('tk.details') ?></h2>
      <div class="grid grid-3 oa-facts">
        <div><span class="k"><?= tt('tk.raised_by') ?></span><b><?= e($t['created_name'] ?: '—') ?></b><span class="hint"><?= $t['created_side'] === 'golden' ? 'Golden A/S' : e($t['customer_name'] ?: t('role.customer')) ?> · <?= e(fmt_datetime($t['created_at'])) ?></span></div>
        <div><span class="k"><?= tt('tk.assignee') ?></span><b><?= e($t['assigned_name'] ?: t('tk.unassigned')) ?></b></div>
        <div><span class="k"><?= tt('tk.resolved') ?></span><b><?= $t['resolved_at'] ? e(fmt_datetime($t['resolved_at'])) : '—' ?></b><?= $t['resolved_name'] ? '<span class="hint">' . e($t['resolved_name']) . '</span>' : '' ?></div>
      </div>
      <?php if ($t['description']): ?><div class="tk-desc"><?= rt_html($t['description']) ?></div><?php endif; ?>
      <?php if (!empty($filesByComment[0])): ?><div class="tk-files"><?php foreach ($filesByComment[0] as $f) echo $fileRow($f); ?></div><?php endif; ?>
    </div>

    <?php if ($t['resolution']): ?>
      <div class="card oa-decision oa-decision--<?= $t['status'] === 'closed' ? 'mute' : 'ok' ?>">
        <h2><?= tt('tk.resolution') ?></h2>
        <blockquote class="oa-note"><?= rt_html($t['resolution']) ?></blockquote>
        <p class="hint" style="margin:6px 0 0;"><?= e($t['resolved_name'] ?? '') ?> · <?= e(fmt_datetime($t['resolved_at'])) ?><?= $t['closed_at'] ? ' · ' . tt('tk.st_closed') . ' ' . e(fmt_datetime($t['closed_at'])) : '' ?></p>
      </div>
    <?php endif; ?>

    <?php if (function_exists('rs_has') && rs_has() && ($rsC = $t['rs_contract_id'] ? rs_get((int)$t['rs_contract_id']) : null)): ?>
    <div class="card" id="rs-card">
      <h2>🛡 <?= tt('rs.ticket_card') ?></h2>
      <?= rs_card_html($rsC) ?>
      <?php if ($staff): $myS = array_values(array_filter($rsC['sessions'], fn($x) => (int)$x['ticket_id'] === $id)); ?>
        <?php if ($myS): ?><table class="tbl" data-nosort style="margin-top:8px;"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('rs.hours') ?></th><th><?= tt('rs.engineer') ?></th><th><?= tt('th.remarks') ?></th><th></th></tr></thead><tbody>
          <?php foreach ($myS as $x): ?><tr><td><?= e(fmt_date($x['session_date'])) ?></td><td><?= e(rtrim(rtrim(number_format((float)$x['hours'], 2, '.', ''), '0'), '.')) ?> h</td><td><?= e($x['engineer_name'] ?? '') ?></td><td class="hint"><?= e($x['note'] ?? '') ?></td>
            <td style="text-align:right;"><form method="post" action="<?= url('ticket', ['id' => $id]) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rs_session_delete"><input type="hidden" name="id" value="<?= $x['id'] ?>"><input type="hidden" name="back" value="ticket:<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
        <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" class="grid grid-4" style="align-items:end;margin-top:10px;"><?= csrf_field() ?><input type="hidden" name="action" value="rs_session_add"><input type="hidden" name="contract_id" value="<?= (int)$rsC['id'] ?>"><input type="hidden" name="ticket_id" value="<?= $id ?>"><input type="hidden" name="back" value="ticket:<?= $id ?>">
          <div class="field" style="margin:0;"><label><?= tt('th.date') ?></label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
          <div class="field" style="margin:0;"><label><?= tt('rs.hours') ?></label><input type="number" name="hours" step="0.25" min="0" max="99" value="1"></div>
          <div class="field" style="margin:0;"><label><?= tt('th.remarks') ?></label><input name="note" maxlength="255" placeholder="<?= tt('rs.session_ph') ?>"></div>
          <div class="btn-row"><button class="btn btn--sm" type="submit">+ <?= tt('rs.log_session') ?></button></div>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($staff): $vAll = all('SELECT id, name, imo, is_active FROM vessels ORDER BY is_active DESC, name'); $curVid = (int)$t['vessel_id'];
          $vProjects = all('SELECT id, vessel_id, project_code, name FROM projects ORDER BY project_code, name');
          $vSubs = all('SELECT s.id, s.project_id, s.code, s.name FROM subprojects s ORDER BY s.sort_order, s.code'); $hasVP = (bool) array_filter($vProjects, fn($x) => (int)$x['vessel_id'] === $curVid); ?>
    <div class="card<?= !$t['project_id'] && $isOpen ? ' card--attention' : '' ?>">
      <h2><?= tt('tk.project_card') ?></h2>
      <p class="hint"><?= !$curVid ? tt('tk.project_hint_novessel') : (!$t['project_id'] ? tt('tk.project_hint_none') : tt('tk.project_hint')) ?></p>
      <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" data-lock class="grid grid-4" style="align-items:end;" id="tk-project">
        <?= csrf_field() ?><input type="hidden" name="action" value="tk_project"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="field" style="margin:0;"><label><?= tt('v.one') ?></label><select name="vessel_id" id="tkp_vessel"><option value="">—</option><?php foreach ($vAll as $vv): ?><option value="<?= $vv['id'] ?>"<?= (int)$vv['id'] === $curVid ? ' selected' : '' ?>><?= e($vv['name'] . ($vv['imo'] ? ' · ' . $vv['imo'] : '') . ($vv['is_active'] ? '' : ' (' . t('v.inactive') . ')')) ?></option><?php endforeach; ?></select></div>
        <div class="field" style="margin:0;"><label><?= tt('pr.one') ?></label><select name="project_id" id="tkp_project"><option value=""><?= tt('tk.generic') ?></option></select></div>
        <div class="field" style="margin:0;"><label><?= tt('sp.one') ?></label><select name="subproject_id" id="tkp_sub"><option value="">—</option></select></div>
        <div class="btn-row" style="flex-wrap:nowrap;white-space:nowrap;"><button class="btn" type="submit"><?= tt('btn.save') ?></button></div>
      </form>
      <p style="margin:10px 0 0;"><a class="btn btn--ghost btn--sm" href="<?= url('project_new', $curVid ? ['vessel' => $curVid] : []) ?>">+ <?= tt('tk.new_project') ?></a></p>
      <?php if ($curVid && !$hasVP): ?><p class="hint" style="margin:8px 0 0;"><?= tt('tk.no_projects_vessel') ?></p><?php endif; ?>
      <script nonce="<?= csp_nonce() ?>">(function(){var P=<?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'v' => (int)$x['vessel_id'], 'l' => $x['project_code'] && !str_starts_with($x['name'], $x['project_code']) ? $x['project_code'] . ' ' . $x['name'] : $x['name']], $vProjects), JSON_UNESCAPED_UNICODE) ?>,
        S=<?= json_encode(array_map(fn($x) => ['id' => (int)$x['id'], 'p' => (int)$x['project_id'], 'l' => trim(($x['code'] ?: '') . ' ' . $x['name'])], $vSubs), JSON_UNESCAPED_UNICODE) ?>,
        curP=<?= (int)$t['project_id'] ?>,curS=<?= (int)$t['subproject_id'] ?>,vs=document.getElementById('tkp_vessel'),ps=document.getElementById('tkp_project'),ss=document.getElementById('tkp_sub');
        function fill(sel,rows,cur,first){sel.innerHTML='<option value="">'+first+'</option>';rows.forEach(function(r){var o=document.createElement('option');o.value=r.id;o.textContent=r.l;if(r.id===cur)o.selected=true;sel.appendChild(o)});}
        function fp(){var v=+vs.value;fill(ps,P.filter(function(r){return r.v===v}),curP,<?= json_encode(t('tk.generic'), JSON_UNESCAPED_UNICODE) ?>);ps.disabled=!v;fs()}
        function fs(){var p=+ps.value;fill(ss,S.filter(function(r){return r.p===p}),curS,'—');ss.disabled=!p}
        vs.addEventListener('change',function(){curP=0;curS=0;fp()});ps.addEventListener('change',function(){curS=0;fs()});fp()})();</script>
    </div>
    <?php endif; ?>

    <div class="card">
      <h2><?= tt('tk.actions') ?></h2>
      <?php if ($staff): ?>
        <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" data-lock class="grid grid-2" style="align-items:end;margin-bottom:12px;">
          <?= csrf_field() ?><input type="hidden" name="action" value="tk_assign"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="field" style="margin:0;"><label><?= tt('tk.assign_to') ?></label><select name="assigned_to"><option value=""><?= tt('tk.unassigned') ?></option><?php foreach ($assignees as $a): ?><option value="<?= $a['id'] ?>"<?= (int)$t['assigned_to'] === (int)$a['id'] ? ' selected' : '' ?><?= (int)$a['id'] === (int)$u['id'] ? ' data-me' : '' ?>><?= e($a['full_name']) ?></option><?php endforeach; ?></select></div>
          <div class="btn-row"><button class="btn" type="submit"><?= tt('tk.assign') ?></button></div>
        </form>
        <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="tk_status"><input type="hidden" name="id" value="<?= $id ?>">
          <?php if ($isOpen): ?><div class="field"><label for="note"><?= tt('tk.resolution') ?></label><textarea id="note" name="note" rows="3" placeholder="<?= tt('tk.resolution_ph') ?>"></textarea></div><?php endif; ?>
          <div class="btn-row">
            <?php if ($t['status'] === 'open' || $t['status'] === 'waiting_customer'): ?><button class="btn" type="submit" name="status" value="in_progress">▶ <?= tt('tk.start') ?></button><?php endif; ?>
            <?php if ($t['status'] === 'in_progress' || $t['status'] === 'open'): ?><button class="btn btn--ghost" type="submit" name="status" value="waiting_customer">⏸ <?= tt('tk.wait_customer') ?></button><?php endif; ?>
            <?php if ($isOpen): ?><button class="btn btn--gold" type="submit" name="status" value="resolved" data-confirm="<?= tt('tk.confirm_resolve') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('tk.resolve') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('tk.resolve') ?></button><?php endif; ?>
            <?php if ($t['status'] === 'resolved'): ?><button class="btn" type="submit" name="status" value="closed">■ <?= tt('tk.close') ?></button><?php endif; ?>
            <?php if (!$isOpen): ?><button class="btn btn--ghost" type="submit" name="status" value="open">↺ <?= tt('tk.reopen') ?></button><?php endif; ?>
          </div>
          <p class="hint" style="margin-top:8px;"><?= tt('tk.actions_hint_staff') ?></p>
        </form>
        <div class="btn-row" style="margin-top:12px;">
          <?php if ($t['project_id']): ?>
            <a class="btn btn--ghost btn--sm" href="<?= url('jo_edit', ['project' => $t['project_id'], 'ticket' => $id]) ?>">+ <?= tt('jo.new') ?></a>
            <a class="btn btn--ghost btn--sm" href="<?= url('fsr_edit', ['project' => $t['project_id'], 'ticket' => $id]) ?>">+ <?= tt('fsr.new') ?></a>
          <?php else: ?><span class="hint"><?= tt('tk.no_project_hint') ?></span><?php endif; ?>
        </div>
      <?php else: ?>
        <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" data-lock>
          <?= csrf_field() ?><input type="hidden" name="action" value="tk_status"><input type="hidden" name="id" value="<?= $id ?>">
          <div class="btn-row">
            <?php if ($t['status'] === 'resolved'): ?><button class="btn btn--gold" type="submit" name="status" value="closed" data-confirm="<?= tt('tk.confirm_close') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('tk.close') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('tk.close_ok') ?></button>
              <button class="btn btn--ghost" type="submit" name="status" value="open">↺ <?= tt('tk.reopen_not_fixed') ?></button>
            <?php elseif ($t['status'] === 'closed'): ?><button class="btn btn--ghost" type="submit" name="status" value="open">↺ <?= tt('tk.reopen') ?></button>
            <?php else: ?><span class="hint"><?= tt($t['status'] === 'waiting_customer' ? 'tk.waiting_you' : 'tk.in_hands') ?></span><?php endif; ?>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <?php $tkOffers = function_exists('ob_for_ticket') && of_visible() ? ob_for_ticket($id) : []; if ($tkOffers): ?>
      <div class="card" id="tk-offers"><h2>€ <?= tt('of.title') ?> <span class="count"><?= count($tkOffers) ?></span></h2>
        <?php foreach ($tkOffers as $of): ?><div class="rowitem"><span class="ico">€</span><span class="tx"><b><a href="<?= url('offer', ['id' => $of['id']]) ?>"><?= e($of['offer_no'] . ' ' . $of['revision']) ?></a> · <?= e($of['title']) ?></b><span><?= e(of_money((float)$of['amount'], $of['currency'])) ?> · <?= e(fmt_date($of['sent_at'])) ?></span></span><?= of_status_pill($of['status']) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($t['jos'] || $t['fsrs']): ?>
      <div class="card">
        <h2><?= tt('tk.linked') ?></h2>
        <?php foreach ($t['jos'] as $j): ?><div class="rowitem"><span class="ico">🛠</span><span class="tx"><b><?= e($j['jo_no']) ?> · <?= e($j['title']) ?></b><span><?= tt('jo.one') ?> · <?= tt('jo.st_' . $j['status']) ?></span></span><a class="btn btn--ghost btn--sm" href="<?= url('jo', ['id' => $j['id']]) ?>"><?= tt('fsr.open') ?></a></div><?php endforeach; ?>
        <?php foreach ($t['fsrs'] as $f): ?><div class="rowitem"><span class="ico">📋</span><span class="tx"><b><?= e($f['report_no']) ?></b><span><?= tt('fsr.one') ?> · <?= e(fmt_date($f['report_date'])) ?> · <?= tt('fsr.st_' . $f['status']) ?></span></span><a class="btn btn--ghost btn--sm" href="<?= url('fsr', ['id' => $f['id']]) ?>"><?= tt('fsr.open') ?></a></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <h2><?= tt('fsr.trail') ?></h2>
      <ul class="trail"><?php foreach ($t['events'] as $x): ?>
        <li><b><?= e($ev[$x['event']] ?? $x['event']) ?></b> — <?= e($x['user_name'] ?: '') ?> · <?= e(fmt_datetime($x['created_at'])) ?><?= $x['ip'] ? ' · IP ' . e($x['ip']) : '' ?><?= $x['note'] ? '<br><span class="hint">' . e(mb_substr($x['note'], 0, 200)) . '</span>' : '' ?></li>
      <?php endforeach; ?></ul>
    </div>
  </div>

  <div class="card">
    <h2><?= tt('oa.thread') ?></h2>
    <?php if (!$t['comments']): ?><div class="empty"><?= tt('tk.thread_empty') ?></div>
    <?php else: ?>
      <div class="oa-thread">
        <?php foreach (thread_tree($t['comments']) as $c): $own = (int)($c['user_id'] ?? 0) === (int)$u['id']; $gone = !empty($c['deleted_at']); ?>
          <div class="msg msg--<?= $c['side'] ?><?= $gone ? ' msg--gone' : '' ?><?= $c['visibility'] === 'internal' ? ' internal' : '' ?> depth-<?= (int)$c['depth'] ?>" id="c<?= (int)$c['id'] ?>">
            <p class="who"><?= avatar_html((int)($c['user_id'] ?? 0), (string)($c['author'] ?: ''), 22, 'ava--inline') ?> <b><?= e($c['author'] ?: ($c['side'] === 'golden' ? 'Golden A/S' : t('role.customer'))) ?></b> · <?= e(fmt_datetime($c['created_at'])) ?><?= $c['visibility'] === 'internal' ? ' · ' . pill('navy', t('tk.internal')) : '' ?><?= !empty($c['parent_id']) ? ' · <a class="hint" href="#c' . (int)$c['parent_id'] . '">↩ ' . tt('cm.in_reply') . '</a>' : '' ?></p>
            <?php if ($gone): ?><p class="hint"><?= tt('cm.deleted') ?></p>
            <?php elseif ($c['body']): ?><p><?= mt_html($c['body']) ?></p><?php endif; ?>
            <?php if (!empty($filesByComment[(int)$c['id']])): ?><div class="tk-files"><?php foreach ($filesByComment[(int)$c['id']] as $f) echo $fileRow($f); ?></div><?php endif; ?>
            <?php if (!$gone): ?><?= thread_tools((int)$c['id'], (string)$c['author'], $own || is_cp_admin(), true, 'tk_comment_delete', ['id' => $id]) ?><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <form method="post" action="<?= url('ticket', ['id' => $id]) ?>" enctype="multipart/form-data" data-lock style="margin-top:14px;">
      <?= csrf_field() ?><input type="hidden" name="action" value="tk_comment"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="parent_id" value="" id="parent_id">
      <div class="reply-to" id="reply-to" hidden><?= tt('cm.replying') ?> <b></b> <button type="button" class="linkbtn js-reply-cancel"><?= tt('btn.cancel') ?></button></div>
      <div class="field"><label for="body"><?= tt('oa.comment') ?></label><textarea data-mt id="body" name="body" rows="3" required placeholder="<?= tt('tk.comment_ph') ?>"></textarea></div>
      <div class="field"><label><?= tt('tk.attachments') ?></label><input type="file" name="files[]" multiple></div>
      <div class="btn-row" style="align-items:center;">
        <button class="btn btn--ghost" type="submit"><?= tt('oa.post') ?></button>
        <?php if ($staff): ?><label class="check" style="margin:0;"><input type="checkbox" name="visibility" value="internal"> <?= tt('tk.internal_note') ?></label><?php endif; ?>
      </div>
    </form>
  </div>
</div>
<?php $adAction = 'tk_delete'; $adId = $id; $adBack = 'tickets'; $adConfirm = t('tk.delete_confirm', ['no' => $t['ticket_no']]); $adNote = t('tk.delete_note'); require __DIR__ . '/_admin_delete.php'; ?>
<?php layout_foot(); ?>

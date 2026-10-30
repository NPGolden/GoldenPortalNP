<?php
/** Raise or edit a work order (Golden A/S staff or the customer).   GAS-CP-R8-001
 *   ?p=jo_edit&project=ID     new      ?p=jo_edit&id=ID   edit a draft */
require __DIR__ . '/_layout.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$j  = $id ? jo_load($id) : null;
if ($id && !$j) { http_response_code(404); exit(t('err.notfound')); }
if ($j && $j['status'] !== 'draft') redirect('jo', ['id' => $id]);
if ($j && !is_staff() && (int)$j['requested_by'] !== (int)$u['id']) { http_response_code(403); exit(t('err.noaccess')); }
$pid = $j ? (int)$j['project_id'] : (int)($_GET['project'] ?? 0);
[$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
$p = one("SELECT p.*, v.name AS vessel FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id WHERE p.id = ? AND {$scope}", $args);
if (!$p) { http_response_code(404); exit(t('err.notfound')); }
$v = fn(string $k, $d = '') => e((string)($j[$k] ?? $d));
$tkId = $j ? (int)($j['ticket_id'] ?? 0) : (int)($_GET['ticket'] ?? 0);
$tkOpts = tk_options_for_project($pid);
$title = $j ? t('jo.edit') . ' ' . $j['jo_no'] : t('jo.new');
layout_head($title, is_staff() ? 'projects' : 'joborders');
?>
<p class="eyebrow"><a href="<?= url('jo_list', ['project' => $pid]) ?>"><?= tt('jo.plural') ?></a> › <?= pr_link((int)$p['id'], $p['project_code'], $p['name']) ?></p>
<h1><?= e($title) ?></h1>
<p class="lede"><?= is_staff() ? tt('jo.lede_golden') : tt('jo.lede_customer') ?></p>

<div class="card" style="max-width:820px;">
  <form method="post" action="<?= url('jo_edit', $id ? ['id' => $id] : ['project' => $pid]) ?>" data-lock>
    <?= csrf_field() ?><input type="hidden" name="action" value="jo_save"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="project_id" value="<?= $pid ?>">
    <div class="grid grid-2">
      <div class="field"><label><?= tt('pr.one') ?></label><input type="text" value="<?= e(($p['project_code'] ?: '') . ' ' . $p['name'] . ($p['vessel'] ? ' · ' . $p['vessel'] : '')) ?>" disabled></div>
      <div class="field"><label><?= tt('jo.no') ?></label><input type="text" value="<?= $j ? e($j['jo_no']) : e(t('jo.no_auto')) ?>" disabled></div>
    </div>
    <?php if ($tkOpts): ?><div class="field"><label><?= tt('tk.related') ?></label>
      <select name="ticket_id"><option value=""><?= tt('tk.none') ?></option><?php foreach ($tkOpts as $tk): ?><option value="<?= $tk['id'] ?>"<?= $tkId === (int)$tk['id'] ? ' selected' : '' ?>><?= e($tk['ticket_no'] . ' — ' . $tk['title']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
    <div class="field"><label><?= tt('jo.title_f') ?></label><input name="title" required maxlength="160" value="<?= $v('title') ?>" placeholder="<?= tt('jo.title_ph') ?>"></div>
    <div class="field"><label><?= tt('jo.description') ?></label><textarea name="description" rows="8" placeholder="<?= tt('jo.description_ph') ?>"><?= $v('description') ?></textarea></div>
    <div class="grid grid-3">
      <div class="field"><label><?= tt('fsr.location') ?></label><input name="location" maxlength="160" value="<?= $v('location') ?>"></div>
      <div class="field"><label><?= tt('jo.for') ?></label><input name="requested_for" type="date" value="<?= $v('requested_for') ?>"></div>
      <div class="field"><label><?= tt('jo.priority') ?></label><select name="priority"><option value="normal"<?= ($j['priority'] ?? 'normal') === 'normal' ? ' selected' : '' ?>><?= tt('jo.normal') ?></option><option value="urgent"<?= ($j['priority'] ?? '') === 'urgent' ? ' selected' : '' ?>><?= tt('jo.urgent') ?></option></select></div>
    </div>
    <div class="btn-row">
      <button class="btn btn--ghost" type="submit" name="mode" value="draft"><?= tt('fsr.save_draft') ?></button>
      <button class="btn btn--gold" type="submit" name="mode" value="submit" data-confirm="<?= is_staff() ? tt('jo.submit_confirm_golden') : tt('jo.submit_confirm_customer') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('jo.submit') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('jo.submit') ?></button>
      <a class="btn btn--ghost" href="<?= $id ? url('jo', ['id' => $id]) : url('jo_list', ['project' => $pid]) ?>"><?= tt('btn.cancel') ?></a>
    </div>
    <p class="hint" style="margin-top:8px;"><?= is_staff() ? tt('jo.submit_hint_golden') : tt('jo.submit_hint_customer') ?></p>
  </form>
</div>
<?php layout_foot(); ?>

<?php
/** HR › Recruiting › Online selection event (GAS-CP-R89-001): edit, registrations, attendance and results.  ?p=rc_event&id=  |  &new=1 */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0); $ev = $id ? rcr_event($id) : null; $edit = isset($_GET['new']) || isset($_GET['edit']);
if (!function_exists('rcr_has') || !rcr_has() || !rcr_can()) { http_response_code(403); layout_head(t('rc.title'), 'rcr_dashboard'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
if ($id && !$ev) { http_response_code(404); layout_head(t('err.notfound'), 'rcr_dashboard'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
if (!$id && !$edit) redirect('rcr_dashboard', ['tab' => 'events']);
layout_head($ev['title'] ?? t('rc.new_event'), 'rcr_dashboard');
?>
<p class="eyebrow"><a href="<?= url('rcr_dashboard', ['tab' => 'events']) ?>"><?= tt('rc.events') ?></a> › <?= $ev ? e($ev['code']) : tt('rc.new_event') ?></p>
<?php if ($edit): $vacs = rcr_vacancies(['status' => 'open']); $sel = json_decode((string)($ev['for_vacancies'] ?? '[]'), true) ?: []; ?>
<h1><?= $ev ? tt('btn.edit') . ' — ' . e($ev['code']) : '＋ ' . tt('rc.new_event') ?></h1>
<form method="post" class="card form-grid" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_event_save"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="field span2"><label><?= tt('rc.event') ?> *</label><input name="title" required maxlength="190" value="<?= e((string)($ev['title'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('rc.kind') ?></label><select name="kind" data-nosearch><?php foreach (RCR_EVT_KIND as $k): ?><option value="<?= $k ?>"<?= ($ev['kind'] ?? 'assessment') === $k ? ' selected' : '' ?>><?= tt('rc.kind_' . $k) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('rc.starts') ?> *</label><input type="datetime-local" name="starts_at" required value="<?= e($ev ? date('Y-m-d\TH:i', strtotime($ev['starts_at'])) : '') ?>"></div>
  <div class="field"><label><?= tt('rc.duration') ?></label><input type="number" name="duration_min" min="15" step="15" value="<?= (int)($ev['duration_min'] ?? 90) ?>"></div>
  <div class="field"><label><?= tt('rc.platform') ?></label><input name="platform" maxlength="60" value="<?= e((string)($ev['platform'] ?? 'Microsoft Teams')) ?>"></div>
  <div class="field span2"><label><?= tt('rc.join_url') ?> <span class="hint"><?= tt('rc.join_hint') ?></span></label><input name="join_url" maxlength="500" value="<?= e((string)($ev['join_url'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('rc.capacity') ?></label><input type="number" name="capacity" min="0" value="<?= e((string)($ev['capacity'] ?? '')) ?>"></div>
  <div class="field"><label><?= tt('rc.reg_closes') ?></label><input type="datetime-local" name="registration_closes_at" value="<?= e($ev && $ev['registration_closes_at'] ? date('Y-m-d\TH:i', strtotime($ev['registration_closes_at'])) : '') ?>"></div>
  <div class="field span2"><label><?= tt('rc.description') ?></label><textarea name="description_html" rows="5"><?= e((string)($ev['description_html'] ?? '')) ?></textarea></div>
  <div class="field span2"><label><?= tt('rc.for_vacancies') ?></label><select name="for_vacancies[]" multiple size="4"><?php foreach ($vacs as $v): ?><option value="<?= (int)$v['id'] ?>"<?= in_array((int)$v['id'], array_map('intval', $sel), true) ? ' selected' : '' ?>><?= e($v['code'] . ' · ' . $v['title']) ?></option><?php endforeach; ?></select></div>
  <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (['draft', 'upcoming', 'closed', 'done', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= ($ev['status'] ?? 'draft') === $s ? ' selected' : '' ?>><?= tt('rc.st_' . $s) ?></option><?php endforeach; ?></select></div>
  <label class="check"><input type="checkbox" name="publish_web" value="1"<?= ($ev['publish_web'] ?? 1) ? ' checked' : '' ?>> <?= tt('rc.publish_web') ?></label>
  <div class="btn-row span2"><button class="btn btn--gold"><?= tt('btn.save') ?></button><a class="btn btn--ghost" href="<?= $id ? url('rcr_event', ['id' => $id]) : url('rcr_dashboard', ['tab' => 'events']) ?>"><?= tt('btn.cancel') ?></a></div>
</form>
<?php else: $regs = rcr_event_regs($id); ?>
<div class="page-head"><div><h1><?= e($ev['title']) ?></h1><p class="lede" style="margin-bottom:0;"><?= e($ev['code']) ?> · <?= tt('rc.kind_' . $ev['kind']) ?> · <?= e(fmt_datetime($ev['starts_at'])) ?> · <?= (int)$ev['duration_min'] ?> min · <?= e((string)$ev['platform']) ?> · <?= pill($ev['status'] === 'upcoming' ? 'gold' : 'navy', t('rc.st_' . $ev['status'])) ?><?= $ev['publish_web'] ? ' 🌐' : '' ?></p></div><div class="btn-row"><a class="btn btn--ghost" href="<?= url('rcr_event', ['id' => $id, 'edit' => 1]) ?>"><?= tt('btn.edit') ?></a></div></div>
<div class="card"><h2><?= tt('rc.registered') ?> <span class="hint" style="display:inline;margin-left:8px;"><?= count($regs) ?><?= $ev['capacity'] ? ' / ' . (int)$ev['capacity'] : '' ?></span></h2>
<?php if (!$regs): ?><div class="empty"><?= tt('rc.none') ?></div><?php else: ?>
<?php foreach ($regs as $r): ?><form id="rf<?= (int)$r['id'] ?>" method="post" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rcr_event_reg_save"><input type="hidden" name="rid" value="<?= (int)$r['id'] ?>"><input type="hidden" name="id" value="<?= $id ?>"></form><?php endforeach; ?>
<div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rc.candidate') ?></th><th><?= tt('rc.application') ?></th><th><?= tt('rc.attended') ?></th><th><?= tt('rc.result') ?></th><th><?= tt('hr.notes') ?></th><th></th></tr></thead><tbody>
<?php foreach ($regs as $r): $fid = 'rf' . (int)$r['id']; ?><tr><td><b><?= e((string)($r['name'] ?: $r['email'])) ?></b><br><span class="hint"><?= e($r['email']) ?></span></td><td><?= $r['application_id'] ? '<a href="' . url('rcr_application', ['id' => (int)$r['application_id']]) . '">' . e($r['app_code']) . '</a> ' . ($r['passed'] ? pill('gold', (int)$r['score'] . '/' . (int)$r['max_score']) : pill('grey', (int)$r['score'] . '/' . (int)$r['max_score'])) : '<span class="hint">—</span>' ?></td>
<td><select form="<?= $fid ?>" name="attended" data-nosearch><option value=""<?= $r['attended'] === null ? ' selected' : '' ?>>—</option><option value="1"<?= $r['attended'] === 1 || $r['attended'] === '1' ? ' selected' : '' ?>>✓</option><option value="0"<?= $r['attended'] === 0 || $r['attended'] === '0' ? ' selected' : '' ?>>✗</option></select></td>
<td><select form="<?= $fid ?>" name="result" data-nosearch><option value=""></option><?php foreach (['strong', 'good', 'weak', 'no_show'] as $x): ?><option value="<?= $x ?>"<?= $r['result'] === $x ? ' selected' : '' ?>><?= tt('rc.res_' . $x) ?></option><?php endforeach; ?></select></td>
<td><input form="<?= $fid ?>" name="result_note" maxlength="500" value="<?= e((string)$r['result_note']) ?>" style="width:220px;"></td><td class="row-actions"><button class="btn btn--gold btn--sm" form="<?= $fid ?>"><?= tt('btn.save') ?></button></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endif; layout_foot();

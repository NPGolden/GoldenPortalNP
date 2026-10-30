<?php
/** Partial: the risk log of a project (or one sub-project) with the add / edit form (GAS-CP-R39-001). expects $pid, optional $sid, $backTo */
if (!function_exists('rk_has') || !rk_has() || !is_staff()) return;
$sid = $sid ?? null; $risks = rk_list($pid, $sid); $staffList = staff_users();
$ed = (int)($_GET['risk'] ?? 0) ? one('SELECT * FROM sp_risks WHERE id = ? AND project_id = ?', [(int)$_GET['risk'], $pid]) : null;
$subsR = all('SELECT id, code, name FROM subprojects WHERE project_id = ? ORDER BY sort_order, code', [$pid]);
$cnt = ['n' => count($risks), 'open' => 0, 'mit' => 0, 'score' => 0]; foreach ($risks as $r) { if ($r['status'] === 'open' || $r['status'] === 'occurred') $cnt['open']++; if (in_array($r['status'], ['mitigated', 'closed'], true)) $cnt['mit']++; if ($r['status'] === 'open') $cnt['score'] = max($cnt['score'], (int)$r['likelihood'] * (int)$r['impact']); }
$rmi = $cnt['n'] ? $cnt['mit'] / $cnt['n'] : null;
$v = fn(string $k, $d = '') => e((string)($ed[$k] ?? $d)); $sel = fn($a, $b) => (string)$a === (string)$b ? ' selected' : '';
?>
<div class="card">
  <div class="card-head"><h2>⚠ <?= tt('rk.title') ?> <span class="count"><?= $cnt['n'] ?></span></h2>
    <span class="hint"><?= tt('rk.open') ?> <b><?= $cnt['open'] ?></b> · <?= tt('rk.mitigated') ?> <b><?= $cnt['mit'] ?></b> · RMI <?= function_exists('kpi_pill') ? kpi_pill('rmi', $rmi) : '—' ?><?= $cnt['score'] ? ' · ' . tt('rk.top_score') . ' ' . rk_score_pill((int) sqrt($cnt['score']), (int) sqrt($cnt['score'])) : '' ?></span>
    <div class="btn-row"><?php if (function_exists('isr_has') && isr_has()): ?><form method="post" action="<?= $backTo ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="rk_seed"><input type="hidden" name="project_id" value="<?= $pid ?>"><?php if ($sid): ?><input type="hidden" name="subproject_id" value="<?= $sid ?>"><?php endif; ?><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('rk.seed_hint') ?>">⇣ <?= tt('rk.seed') ?></button></form><?php endif; ?><a class="btn btn--gold btn--sm" href="#rk-form">+ <?= tt('rk.new') ?></a></div></div>
  <p class="hint" style="margin:0 0 8px;"><?= tt('rk.lede') ?></p>
  <?php if (!$risks): ?><p class="hint"><?= tt('rk.none') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('rk.no') ?></th><th><?= tt('rk.risk') ?></th><?php if (!$sid): ?><th><?= tt('sp.one') ?></th><?php endif; ?><th><?= tt('rk.category') ?></th><th class="num">L × I</th><th><?= tt('rk.owner') ?></th><th><?= tt('rk.due') ?></th><th><?= tt('th.status') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($risks as $r): $late = $r['status'] === 'open' && $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
    <tr id="rk<?= (int)$r['id'] ?>" class="<?= $late ? 'is-late' : ($r['status'] === 'closed' ? 'is-muted' : '') ?>"><td class="mono"><b><?= e($r['risk_no']) ?></b><?= $r['source'] === 'inspection' ? '<br>' . pill('navy', t('rk.src_inspection')) : '' ?></td>
      <td><b><?= e($r['title']) ?></b><?= $r['description'] ? '<br><span class="hint">' . e(mb_substr($r['description'], 0, 160)) . '</span>' : '' ?><?= $r['mitigation'] ? '<br><span class="hint">🛡 ' . e(mb_substr($r['mitigation'], 0, 160)) . '</span>' : '' ?></td>
      <?php if (!$sid): ?><td class="hint"><?= $r['sub_code'] ? e(trim($r['sub_code'] . ' ' . $r['sub_name'])) : tt('fsr.whole_project') ?></td><?php endif; ?>
      <td><?= e(t('rk.c_' . ($r['category'] ?: 'other'))) ?></td><td class="num"><?= rk_score_pill((int)$r['likelihood'], (int)$r['impact']) ?> <span class="hint"><?= (int)$r['likelihood'] ?>×<?= (int)$r['impact'] ?></span></td>
      <td><?= e($r['owner_name'] ?: '—') ?></td><td class="date<?= $late ? ' bg-neg' : '' ?>"><?= $r['due_date'] ? e(fmt_date($r['due_date'])) : '—' ?></td><td><?= rk_status_pill($r['status']) ?><?= $r['mitigated_at'] ? '<br><span class="hint">' . e(fmt_date($r['mitigated_at'])) . '</span>' : '' ?></td>
      <td class="row-actions stack"><a class="btn btn--ghost btn--sm" href="<?= url($sid ? 'subproject' : 'risks', ($sid ? ['id' => $sid, 'tab' => 'risks'] : ['project' => $pid]) + ['risk' => $r['id']]) ?>#rk-form">✎</a><form method="post" action="<?= $backTo ?>" class="inline" data-confirm="<?= tt('rk.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="rk_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">×</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<div class="card" id="rk-form">
  <h2><?= $ed ? tt('rk.edit') . ' ' . e($ed['risk_no']) : tt('rk.new') ?></h2>
  <form method="post" action="<?= $backTo ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="rk_save"><input type="hidden" name="project_id" value="<?= $pid ?>"><input type="hidden" name="risk_id" value="<?= (int)($ed['id'] ?? 0) ?>">
    <div class="grid grid-3">
      <div class="field" style="grid-column:span 2;"><label><?= tt('rk.risk') ?> *</label><input name="title" required maxlength="200" value="<?= $v('title') ?>" placeholder="<?= tt('rk.title_ph') ?>"></div>
      <div class="field"><label><?= tt('sp.one') ?></label><select name="subproject_id" data-nosearch><option value=""><?= tt('fsr.whole_project') ?></option><?php foreach ($subsR as $s): ?><option value="<?= $s['id'] ?>"<?= $sel($s['id'], $ed['subproject_id'] ?? $sid ?? '') ?>><?= e(trim($s['code'] . ' ' . $s['name'])) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rk.category') ?></label><select name="category" data-nosearch><?php foreach (RK_CATS as $c): ?><option value="<?= $c ?>"<?= $sel($c, $ed['category'] ?? 'technical') ?>><?= e(t('rk.c_' . $c)) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rk.likelihood') ?> (1–5)</label><select name="likelihood" data-nosearch><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= $sel($i, $ed['likelihood'] ?? 3) ?>><?= $i ?> — <?= e(t('rk.l' . $i)) ?></option><?php endfor; ?></select></div>
      <div class="field"><label><?= tt('rk.impact') ?> (1–5)</label><select name="impact" data-nosearch><?php for ($i = 1; $i <= 5; $i++): ?><option value="<?= $i ?>"<?= $sel($i, $ed['impact'] ?? 3) ?>><?= $i ?> — <?= e(t('rk.i' . $i)) ?></option><?php endfor; ?></select></div>
      <div class="field"><label><?= tt('rk.owner') ?></label><select name="owner_user_id"><option value="">—</option><?php foreach ($staffList as $pp): ?><option value="<?= $pp['id'] ?>"<?= $sel($pp['id'], $ed['owner_user_id'] ?? '') ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
      <div class="field"><label><?= tt('rk.identified') ?></label><input type="date" name="identified_at" value="<?= $v('identified_at', date('Y-m-d')) ?>"></div>
      <div class="field"><label><?= tt('rk.due') ?></label><input type="date" name="due_date" value="<?= $v('due_date') ?>"></div>
      <div class="field"><label><?= tt('th.status') ?></label><select name="status" data-nosearch><?php foreach (RK_STATUSES as $st): ?><option value="<?= $st ?>"<?= $sel($st, $ed['status'] ?? 'open') ?>><?= e(t('rk.st_' . $st)) ?></option><?php endforeach; ?></select></div>
    </div>
    <div class="field"><label><?= tt('ob.c_desc') ?></label><textarea name="description" rows="2"><?= $v('description') ?></textarea></div>
    <div class="field"><label><?= tt('rk.mitigation') ?></label><textarea name="mitigation" rows="2" placeholder="<?= tt('rk.mitigation_ph') ?>"><?= $v('mitigation') ?></textarea></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit"><?= $ed ? tt('btn.save') : tt('rk.add') ?></button><?php if ($ed): ?><a class="btn btn--ghost" href="<?= url($sid ? 'subproject' : 'risks', $sid ? ['id' => $sid, 'tab' => 'risks'] : ['project' => $pid]) ?>"><?= tt('btn.cancel') ?></a><?php endif; ?></div>
  </form>
</div>

<?php
/** Engineering — extra-hours requests and exceeded sections, for management (GAS-CP-R47-001). ?p=eng_requests[&status=pending|approved|rejected|all] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('eng_is_management') || !(eng_is_management() || eng_is_pm())) { http_response_code(403); layout_head(t('err.noaccess'), 'eng_requests'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('eng.noaccess_hint') . '</p>'; layout_foot(); return; }
$st = (string)($_GET['status'] ?? 'pending'); if (!in_array($st, ['pending', 'approved', 'rejected', 'all'], true)) $st = 'pending';
$reqs = eng_requests(['status' => $st]); $alerts = eng_alerts();
$fh = fn(float $h) => rtrim(rtrim(number_format($h, 2, '.', ''), '0'), '.');
$nPend = count(eng_requests(['status' => 'pending']));
$exc = []; if ($alerts) { $ids = implode(',', array_map('intval', array_keys($alerts))); foreach (all("SELECT s.id, s.code, s.name, p.id AS project_id, p.project_code, p.name AS project_name, v.name AS vessel FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id WHERE s.id IN ($ids) ORDER BY p.project_code, s.code") as $r) $exc[] = $r + ['state' => $alerts[(int)$r['id']]]; }
layout_head(t('eng.requests'), 'eng_requests');
?>
<div class="page-head">
  <div><h1>📐 <?= tt('eng.requests') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('eng.requests_lede') ?></p></div>
  <div class="btn-row"><?php foreach (['pending', 'approved', 'rejected', 'all'] as $k): ?><a class="btn btn--sm <?= $st === $k ? 'btn--gold' : 'btn--ghost' ?>" href="<?= url('eng_requests', ['status' => $k]) ?>"><?= tt('eng.q_' . $k) ?><?= $k === 'pending' && $nPend ? ' <span class="n">' . $nPend . '</span>' : '' ?></a><?php endforeach; ?></div>
</div>

<div class="card">
  <?php if (!$reqs): ?><p class="hint"><?= tt('eng.no_requests') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pr.one') ?></th><th><?= tt('eng.section') ?></th><th class="num"><?= tt('eng.hours') ?></th><th><?= tt('eng.reason_kind') ?></th><th><?= tt('eng.reason') ?></th><th><?= tt('eng.requested_by') ?></th><th><?= tt('th.status') ?></th><th><?= tt('eng.decision') ?></th><th></th></tr></thead><tbody>
  <?php foreach ($reqs as $q): $rr = eng_rows((int)$q['subproject_id'])[(int)$q['section_id']] ?? null; ?><tr>
    <td><?= pr_link((int)$q['project_id'], $q['project_code'], $q['project_name']) ?><br><a href="<?= url('subproject', ['id' => (int)$q['subproject_id'], 'tab' => 'engineering']) ?>"><?= e(trim(($q['sub_code'] ?: '') . ' ' . $q['sub_name'])) ?></a><?= $q['vessel'] ? '<br><span class="hint">' . e($q['vessel']) . '</span>' : '' ?></td>
    <td><b><?= e($q['section']) ?></b> <span class="hint"><?= e(t('st.' . $q['stage'])) ?></span><?php if ($rr): ?><br><span class="hint"><?= e($fh($rr['actual'])) ?> / <?= e($fh($rr['allowed'])) ?> h · <?= $rr['pct_done'] ?> % <?= tt('eng.done_lbl') ?></span><?php endif; ?></td>
    <td class="num">+<?= e($fh((float)$q['hours'])) ?></td><td><?= e(t('eng.r_' . $q['reason_kind'])) ?></td><td><?= e($q['reason']) ?></td>
    <td class="hint"><?= e($q['requested_name']) ?><br><?= e(fmt_datetime($q['requested_at'])) ?></td>
    <td><?= pill($q['status'] === 'approved' ? 'green' : ($q['status'] === 'rejected' ? 'bad' : 'gold'), t('eng.q_' . $q['status'])) ?></td>
    <td class="hint"><?= $q['decided_name'] ? e($q['decided_name']) . ' · ' . e(fmt_datetime($q['decided_at'])) . ($q['decision_note'] ? '<br>' . e($q['decision_note']) : '') : '—' ?></td>
    <td class="row-actions"><?php if ($q['status'] === 'pending' && ($spq = load_subproject((int)$q['subproject_id'])) && eng_can_decide($spq, (string)$q['stage'])): ?><form method="post" action="<?= url('eng_requests', ['status' => $st]) ?>" class="inline" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="eng_decide_list"><input type="hidden" name="req_id" value="<?= (int)$q['id'] ?>"><input name="note" maxlength="500" placeholder="<?= tt('eng.decision_ph') ?>" style="width:150px;"> <button class="btn btn--gold btn--sm" name="decision" value="approve" type="submit" title="<?= tt('eng.approve') ?>">✔</button> <button class="btn btn--ghost btn--sm" name="decision" value="reject" type="submit" title="<?= tt('eng.reject') ?>">✖</button></form><?php endif; ?></td>
  </tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-head"><h2>⚠ <?= tt('eng.alerts') ?> <span class="hint">(<?= count($exc) ?>)</span></h2><span class="hint"><?= tt('eng.alerts_hint') ?></span></div>
  <?php if (!$exc): ?><p class="hint"><?= tt('eng.no_alerts') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('pr.one') ?></th><th><?= tt('th.vessel') ?></th><th><?= tt('eng.section') ?></th><th class="num"><?= tt('eng.budget') ?></th><th class="num"><?= tt('eng.extra') ?></th><th class="num"><?= tt('eng.actual') ?></th><th class="num"><?= tt('eng.pct_done') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
  <?php foreach ($exc as $x): foreach (eng_rows((int)$x['id']) as $r): if (!in_array($r['state'], ['exceeded', 'pending', 'warn', 'extended'], true)) continue; ?><tr>
    <td><?= pr_link((int)$x['project_id'], $x['project_code'], $x['project_name']) ?><br><a href="<?= url('subproject', ['id' => (int)$x['id'], 'tab' => 'engineering']) ?>"><?= e(trim(($x['code'] ?: '') . ' ' . $x['name'])) ?></a></td>
    <td class="hint"><?= e($x['vessel'] ?: '') ?></td><td><b><?= e($r['name']) ?></b> <span class="hint"><?= e(t('st.' . $r['stage'])) ?></span></td><td class="num"><?= e($fh($r['budget'])) ?></td><td class="num"><?= $r['extra'] > 0 ? '+' . e($fh($r['extra'])) : '—' ?></td><td class="num<?= $r['state'] === 'exceeded' ? ' bad' : '' ?>"><?= e($fh($r['actual'])) ?></td><td class="num"><?= $r['pct_done'] ?> %</td>
    <td><?= eng_state_pill($r['state'], $r['pct_used'] !== null ? $r['pct_used'] . ' %' : null) ?></td>
  </tr><?php endforeach; endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

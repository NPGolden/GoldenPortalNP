<?php
/** Projects › Doubled codes — sub-projects that share a code inside one project, and projects that share a code. Read only.   GAS-CP-R97-073   ?p=data_doubles */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!(mgmt_can() || is_cp_admin())) { http_response_code(403); layout_head(t('err.noaccess'), 'projects'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
// every table that points to a sub-project / a project — counted per record, so it is clear which of two doubles holds the work
$refTables = function (string $col): array { try { return array_column(all("SELECT DISTINCT TABLE_NAME AS t FROM information_schema.columns WHERE table_schema = DATABASE() AND COLUMN_NAME = ? AND TABLE_NAME NOT IN ('subprojects','projects')", [$col]), 't'); } catch (Throwable $e) { return []; } };
$linked = function (array $tables, string $col, int $id): array { $o = []; foreach ($tables as $tb) { if (!preg_match('/^[a-z0-9_]+$/', $tb)) continue; try { $n = (int) scalar("SELECT COUNT(*) FROM `{$tb}` WHERE `{$col}` = ?", [$id]); if ($n) $o[$tb] = $n; } catch (Throwable $e) {} } arsort($o); return $o; };
$show = function (array $o): string { if (!$o) return '<span class="hint">—</span>'; $p = []; foreach (array_slice($o, 0, 6, true) as $k => $n) $p[] = e($k) . ' ' . (int)$n; return '<b>' . array_sum($o) . '</b> <span class="hint">' . implode(' · ', $p) . (count($o) > 6 ? ' …' : '') . '</span>'; };
$tS = $refTables('subproject_id'); $tP = $refTables('project_id');
$ddIds = []; foreach (sp_doubles() as $ids) foreach ($ids as $x) $ddIds[] = (int)$x; $ddIds = array_slice($ddIds, 0, 400);   // R97-075: compared loosely (capitals, spaces, Greek / Latin look-alike letters)
$subs = $ddIds ? all("SELECT s.id, s.project_id, s.code, s.erp_code, s.name, s.status, s.invoicing, s.system_id, LEFT(s.description, 120) AS descr, p.project_code, p.name AS project_name, v.name AS vessel, sy.name AS system
             FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN vessel_systems sy ON sy.id = s.system_id
             WHERE s.id IN (" . implode(',', $ddIds) . ") ORDER BY p.project_code, s.project_id, s.code, s.id") : [];
usort($subs, fn($a, $b) => [$a['project_code'], (int)$a['project_id'], sp_code_norm($a['code']), (int)$a['id']] <=> [$b['project_code'], (int)$b['project_id'], sp_code_norm($b['code']), (int)$b['id']]);
$prj = all("SELECT p.id, p.project_code, p.erp_code, p.name, p.status, v.name AS vessel, c.name AS customer, (SELECT COUNT(*) FROM subprojects s WHERE s.project_id = p.id) AS n_subs
            FROM projects p LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
            JOIN (SELECT project_code FROM projects WHERE project_code IS NOT NULL AND project_code <> '' GROUP BY project_code HAVING COUNT(*) > 1) d ON d.project_code = p.project_code
            ORDER BY p.project_code, p.id LIMIT 200");
layout_head(t('dd.title'), 'projects');
?>
<p class="eyebrow"><a href="<?= url('projects') ?>"><?= tt('pr.title') ?></a> › <?= tt('dd.title') ?></p>
<div class="page-head"><div><h1>👥 <?= tt('dd.title') ?></h1><p class="lede"><?= tt('dd.lede') ?></p></div></div>

<div class="card">
  <h2><?= tt('dd.subs_h') ?> <span class="count"><?= count($subs) ?></span></h2>
  <?php if (!$subs): ?><div class="empty"><?= tt('dd.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap">
    <thead><tr><th><?= tt('dd.project') ?></th><th><?= tt('sp.code') ?></th><th><?= tt('dd.record') ?></th><th><?= tt('prq.status') ?></th><th><?= tt('dd.linked') ?></th><th></th></tr></thead>
    <tbody><?php $last = ''; foreach ($subs as $s): $k = $s['project_id'] . '|' . sp_code_norm($s['code']); $first = $k !== $last; $last = $k; ?><tr<?= $first ? ' style="border-top:2px solid rgba(31,58,95,.35);"' : '' ?>>
      <td><?= $first ? '<b>' . e($s['project_code']) . '</b><br><span class="hint">' . e((string)$s['vessel']) . '</span>' : '' ?></td>
      <td class="mono"><?= e($s['code']) ?><?= preg_match('/[^\x20-\x7E]/', (string)$s['code']) ? '<br><span class="hint">⚠ ' . tt('dd.greek') . '</span>' : '' ?></td>
      <td>#<?= (int)$s['id'] ?> · <b><?= e($s['name']) ?></b><?= $s['system'] ? '<br><span class="hint">' . e($s['system']) . '</span>' : '' ?><?= trim((string)$s['descr']) !== '' ? '<br><span class="hint">' . e((string)$s['descr']) . '</span>' : '' ?></td>
      <td><?= e((string)$s['status']) ?><br><span class="hint"><?= e((string)$s['invoicing']) ?></span></td>
      <td><?= $show($linked($tS, 'subproject_id', (int)$s['id'])) ?></td>
      <td><a class="btn btn--ghost btn--sm" href="<?= url('subproject', ['id' => (int)$s['id']]) ?>"><?= tt('dd.open') ?></a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= tt('dd.subs_hint') ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('dd.prj_h') ?> <span class="count"><?= count($prj) ?></span></h2>
  <?php if (!$prj): ?><div class="empty"><?= tt('dd.none') ?></div><?php else: ?>
  <div class="table-scroll"><table class="tbl tbl--wrap">
    <thead><tr><th><?= tt('dd.project') ?></th><th><?= tt('dd.record') ?></th><th><?= tt('prq.status') ?></th><th class="num"><?= tt('dd.n_subs') ?></th><th><?= tt('dd.linked') ?></th><th></th></tr></thead>
    <tbody><?php $last = ''; foreach ($prj as $p): $first = $p['project_code'] !== $last; $last = $p['project_code']; ?><tr<?= $first ? ' style="border-top:2px solid rgba(31,58,95,.35);"' : '' ?>>
      <td><?= $first ? '<b>' . e($p['project_code']) . '</b>' : '' ?></td>
      <td>#<?= (int)$p['id'] ?> · <b><?= e($p['name']) ?></b><br><span class="hint"><?= e(trim((string)$p['vessel'] . ' · ' . (string)$p['customer'], ' ·')) ?><?= $p['erp_code'] ? ' · ERP ' . e($p['erp_code']) : '' ?></span></td>
      <td><?= e((string)$p['status']) ?></td><td class="num"><?= (int)$p['n_subs'] ?></td>
      <td><?= $show($linked($tP, 'project_id', (int)$p['id'])) ?></td>
      <td><a class="btn btn--ghost btn--sm" href="<?= url('project', ['id' => (int)$p['id']]) ?>"><?= tt('dd.open') ?></a></td>
    </tr><?php endforeach; ?></tbody>
  </table></div>
  <p class="hint"><?= tt('dd.prj_hint') ?></p>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

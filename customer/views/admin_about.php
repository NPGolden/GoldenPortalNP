<?php
/**
 * Settings › About — the portal version, every patch delivered with its
 * database step and whether it ran, and a file-integrity check of the live
 * server against the shipped manifest (sha256 per file).      GAS-CP-R13-001
 *   ?p=admin_about[&export=csv|json]
 */
require __DIR__ . '/_layout.php';
$u = require_cp_admin();
$state = cp_migration_state();
$integ = cp_integrity();
$m = $integ['manifest'];
$nBad = count($integ['modified']) + count($integ['missing']);
$dbv = ''; try { $dbv = (string) db()->query('SELECT VERSION()')->fetchColumn(); } catch (Throwable $e) {}

/* CSV / JSON of the live file state, for offline comparison */
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="GAS-portal-files-' . CP_VERSION . '-' . date('Ymd-Hi') . '.csv"');
    $out = fopen('php://output', 'w'); fprintf($out, "\xEF\xBB\xBF"); fputcsv($out, ['path', 'state', 'manifest_sha256', 'live_sha256', 'manifest_size', 'live_size', 'live_mtime'], ';');
    $root = dirname(__DIR__, 2);
    foreach (($m['files'] ?? []) as $path => $i) { $p = $root . '/' . $path; $ex = is_file($p); $h = $ex ? hash_file('sha256', $p) : '';
        fputcsv($out, [$path, !$ex ? 'missing' : ($h === $i['sha256'] ? 'ok' : 'modified'), $i['sha256'], $h, $i['size'], $ex ? filesize($p) : '', $ex ? date('Y-m-d H:i:s', filemtime($p)) : ''], ';'); }
    foreach ($integ['extra'] as $path) { $p = $root . '/' . $path; fputcsv($out, [$path, 'extra', '', hash_file('sha256', $p), '', filesize($p), date('Y-m-d H:i:s', filemtime($p))], ';'); }
    exit;
}
if (($_GET['export'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['version' => CP_VERSION, 'build' => CP_BUILD, 'manifest_version' => $m['version'] ?? null, 'checked' => $integ['checked'], 'ok' => count($integ['ok']),
                      'modified' => array_column($integ['modified'], 'path'), 'missing' => $integ['missing'], 'extra' => $integ['extra'], 'migrations' => $state, 'php' => PHP_VERSION, 'mysql' => $dbv], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}
layout_head(t('ab.title'), 'admin_about');
?>
<?php settings_tabs('admin_about'); ?>
<h2 class="settings-h"><?= tt('ab.title') ?></h2>
<p class="lede"><?= tt('ab.lede') ?></p>

<div class="kpi" style="margin-bottom:16px;">
  <div class="stat"><b style="font-family:var(--mono);">v<?= e(CP_VERSION) ?></b><?= tt('ab.version') ?> · <?= e(CP_BUILD) ?></div>
  <div class="stat <?= $m ? ($nBad ? '' : 'green') : '' ?>"><b<?= $nBad ? ' class="is-late"' : '' ?>><?= $m ? ($nBad ? $nBad : '✓') : '—' ?></b><?= $m ? ($nBad ? tt('ab.files_differ') : tt('ab.files_match')) : tt('ab.no_manifest') ?></div>
  <div class="stat <?= in_array(false, $state, true) ? '' : 'green' ?>"><b><?= count(array_filter($state)) ?> / <?= count($state) ?></b><?= tt('ab.db_steps') ?></div>
  <div class="stat"><b style="font-size:16px;line-height:1.3;">PHP <?= e(PHP_VERSION) ?><br>MySQL <?= e(preg_replace('/-.*/', '', $dbv)) ?></b><?= tt('ab.server') ?></div>
</div>

<div class="card">
  <div class="page-head" style="margin-bottom:8px;">
    <div><h2 style="margin:0;"><?= tt('ab.integrity') ?></h2>
      <p class="hint" style="margin:4px 0 0;"><?= $m ? e(t('ab.manifest_info', ['v' => $m['version'], 'n' => (int)$m['count'], 'd' => fmt_datetime($m['generated_at'] ?? '')])) : tt('ab.no_manifest_hint') ?></p></div>
    <div class="btn-row">
      <a class="btn btn--ghost btn--sm" href="<?= url('admin_about', ['export' => 'csv']) ?>">⤓ CSV</a>
      <a class="btn btn--ghost btn--sm" href="<?= url('admin_about', ['export' => 'json']) ?>">⤓ JSON</a>
    </div>
  </div>
  <?php if ($m): ?>
    <?php if ($m['version'] !== CP_VERSION): ?><div class="flash flash--warn"><?= e(t('ab.version_mismatch', ['m' => $m['version'], 'c' => CP_VERSION])) ?></div><?php endif; ?>
    <?php if (!$nBad && !$integ['extra']): ?><div class="flash flash--good"><?= e(t('ab.all_ok', ['n' => count($integ['ok'])])) ?></div><?php endif; ?>
    <?php foreach ([['modified', 'bad', 'ab.modified'], ['missing', 'bad', 'ab.missing'], ['extra', 'warn', 'ab.extra']] as [$k, $tone, $lbl]): if (!$integ[$k]) continue; ?>
      <h3 class="ac-h"><?= e(t($lbl, ['n' => count($integ[$k])])) ?></h3>
      <div class="table-scroll"><table>
        <thead><tr><th><?= tt('ab.file') ?></th><?php if ($k === 'modified'): ?><th class="num"><?= tt('ab.size_manifest') ?></th><th class="num"><?= tt('ab.size_live') ?></th><th><?= tt('ab.mtime') ?></th><?php endif; ?></tr></thead>
        <tbody><?php foreach ($integ[$k] as $row): $path = is_array($row) ? $row['path'] : $row; ?>
          <tr><td><code><?= e($path) ?></code> <?= pill($tone, t('ab.st_' . $k)) ?></td>
            <?php if ($k === 'modified'): ?><td class="num"><?= number_format((int)$row['size']) ?> B</td><td class="num"><?= number_format((int)$row['live_size']) ?> B</td><td class="date"><?= e($row['mtime']) ?></td><?php endif; ?></tr>
        <?php endforeach; ?></tbody>
      </table></div>
    <?php endforeach; ?>
    <p class="hint" style="margin-top:12px;"><?= tt('ab.integrity_hint') ?></p>
  <?php endif; ?>
</div>

<div class="card">
  <h2><?= tt('ab.patches') ?></h2>
  <p class="hint"><?= tt('ab.patches_hint') ?></p>
  <div class="table-scroll"><table>
    <thead><tr><th><?= tt('ab.version') ?></th><th><?= tt('ab.ref') ?></th><th><?= tt('ab.date') ?></th><th><?= tt('ab.what') ?></th><th><?= tt('ab.db_step') ?></th></tr></thead>
    <tbody><?php [$abPage, $abPager] = page_list(cp_patches(), 'admin_about', [], 'ppage'); foreach ($abPage as $p): $applied = $p['check'] ? ($state[$p['check']] ?? false) : null; ?>
      <tr<?= $p['version'] === CP_VERSION ? ' style="background:var(--cp-gold-dim);"' : '' ?>>
        <td class="num"><b>v<?= e($p['version']) ?></b></td><td class="ref"><?= e($p['ref']) ?></td><td class="date"><?= e(fmt_date($p['date'])) ?></td><td><?= e($p['title']) ?></td>
        <td><?= $p['db'] ? '<code>' . e($p['db']) . '</code> ' . ($applied ? pill('green', t('ab.applied')) : pill('bad', t('ab.not_applied'))) : '<span class="hint">—</span>' ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div><?= $abPager ?>
</div>
<?php /* ---- R20: file archive ------------------------------------------- */
$arRes = $_SESSION['cp_ar_result'] ?? null; unset($_SESSION['cp_ar_result']);
$arVer = $_SESSION['cp_ar_verify'] ?? null; unset($_SESSION['cp_ar_verify']);
$arOk = ar_has(); $arS = $arOk ? ar_stats() : null; ?>
<div class="card" id="archive">
  <h2><?= tt('ar.title') ?></h2>
  <p class="hint"><?= tt('ar.lede') ?></p>
  <?php if (!$arOk): ?><div class="flash flash--warn"><?= tt('ar.need_r20') ?></div><?php else: ?>
  <div class="ar-stats">
    <div><b><?= number_format($arS['n']) ?></b><span><?= tt('ar.files') ?></span></div>
    <div><b><?= e(ar_fmt_bytes($arS['total'])) ?></b><span><?= tt('ar.size') ?></span></div>
    <div><b><?= number_format($arS['archived']) ?></b><span><?= tt('ar.in_archive') ?></span></div>
    <div><b><?= $arS['manifest_at'] ? e($arS['manifest_at']) : '—' ?></b><span><?= tt('ar.manifest_at') ?></span></div>
  </div>
  <div class="btn-row" style="margin:12px 0 6px;flex-wrap:wrap;">
    <form method="post" action="<?= url('admin_about') ?>" data-lock data-busy="<?= tt('ar.working') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_reorganise"><input type="hidden" name="dry" value="1"><button class="btn btn--ghost" type="submit"><?= tt('ar.dry') ?></button></form>
    <form method="post" action="<?= url('admin_about') ?>" data-lock data-busy="<?= tt('ar.working') ?>" data-confirm="<?= tt('ar.apply_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ar.apply') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_reorganise"><button class="btn btn--gold" type="submit"><?= tt('ar.apply') ?></button></form>
    <form method="post" action="<?= url('admin_about') ?>" data-lock data-busy="<?= tt('ar.working') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_verify"><button class="btn btn--ghost" type="submit"><?= tt('ar.verify') ?></button></form>
    <form method="post" action="<?= url('admin_about') ?>" data-lock data-busy="<?= tt('ar.working') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_verify"><input type="hidden" name="full" value="1"><button class="btn btn--ghost" type="submit"><?= tt('ar.verify_full') ?></button></form>
    <form method="post" action="<?= url('admin_about') ?>" data-lock data-busy="<?= tt('ar.working') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="ar_manifest"><button class="btn btn--ghost" type="submit"><?= tt('ar.manifest') ?></button></form>
  </div>
  <?php if ($arRes): ?>
  <div class="flash flash--<?= $arRes['dry'] ? 'warn' : 'good' ?>"><?= e(t($arRes['dry'] ? 'ar.dry_result' : 'ar.apply_result', ['c' => $arRes['checked'], 'm' => $arRes['moved'], 's' => $arRes['same'], 'x' => $arRes['missing'], 'e' => $arRes['errors']])) ?></div>
  <?php if ($arRes['rows']): ?><details<?= count($arRes['rows']) <= 60 ? ' open' : '' ?>><summary class="hint" style="cursor:pointer;"><?= e(t('ar.rows_n', ['n' => count($arRes['rows'])])) ?></summary>
  <div class="table-scroll"><table class="ar-rows"><thead><tr><th><?= tt('ar.register') ?></th><th>#</th><th><?= tt('ar.from') ?></th><th><?= tt('ar.to') ?></th><th><?= tt('th.status') ?></th></tr></thead><tbody>
    <?php foreach ($arRes['rows'] as [$tbl, $fid, $from, $to, $st]): ?><tr><td class="mono"><?= e($tbl) ?></td><td class="num"><?= (int)$fid ?></td><td class="mono"><?= e($from) ?></td><td class="mono"><?= e($to) ?></td><td><?= e($st) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></details><?php endif; ?>
  <?php endif; ?>
  <?php if ($arVer): ?>
  <div class="flash flash--<?= ($arVer['missing'] || $arVer['mismatch'] || $arVer['orphans']) ? 'bad' : 'good' ?>"><?= e(t('ar.verify_result', ['c' => $arVer['checked'], 'x' => count($arVer['missing']), 'h' => count($arVer['mismatch']), 'o' => count($arVer['orphans']), 'u' => $arVer['unhashed']])) ?><?= $arVer['full'] ? '' : ' · ' . tt('ar.verify_sample') ?></div>
  <?php foreach ([['ar.missing', $arVer['missing']], ['ar.mismatch', $arVer['mismatch']]] as [$k, $rows]): if ($rows): ?><p class="hint" style="margin:4px 0;"><b><?= tt($k) ?>:</b> <?= e(implode(' · ', array_map(fn($r) => $r[0] . '#' . $r[1] . ' ' . $r[2], array_slice($rows, 0, 40)))) ?></p><?php endif; endforeach; ?>
  <?php if ($arVer['orphans']): ?><p class="hint" style="margin:4px 0;"><b><?= tt('ar.orphans') ?>:</b> <?= e(implode(' · ', array_slice($arVer['orphans'], 0, 40))) ?></p><?php endif; ?>
  <?php endif; ?>
  <?php $runs = ar_runs(); if ($runs): ?>
  <h3 class="ed-h" style="margin-top:14px;"><?= tt('ar.runs') ?></h3>
  <div class="table-scroll"><table><thead><tr><th><?= tt('ar.run') ?></th><th><?= tt('th.date') ?></th><th class="num"><?= tt('ar.moved') ?></th><th class="num"><?= tt('ar.undone') ?></th><th></th></tr></thead><tbody>
    <?php foreach ($runs as $r): ?><tr><td class="mono"><?= e($r['run_id']) ?></td><td><?= e(fmt_datetime($r['at'])) ?></td><td class="num"><?= (int)$r['n'] ?></td><td class="num"><?= (int)$r['undone'] ?></td>
      <td class="row-actions"><?php if ((int)$r['undone'] < (int)$r['n'] && strtotime($r['at']) > time() - 30 * 86400): ?><form method="post" action="<?= url('admin_about') ?>" data-confirm="<?= tt('ar.undo_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('ar.undo') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>" data-confirm-danger><?= csrf_field() ?><input type="hidden" name="action" value="ar_undo"><input type="hidden" name="run_id" value="<?= e($r['run_id']) ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('ar.undo') ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
  <div class="grid grid-2" style="margin-top:14px;">
    <div><h3 class="ed-h"><?= tt('ar.by_family') ?></h3><table><tbody><?php foreach ($arS['families'] as $f): ?><tr><td><?= e($f['kind']) ?></td><td class="num"><?= (int)$f['n'] ?></td><td class="num"><?= e(ar_fmt_bytes((int)$f['bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div><h3 class="ed-h"><?= tt('ar.by_customer') ?></h3><table><tbody><?php foreach ($arS['customers'] as $c): ?><tr><td><?= e($c['name'] ?: '—') ?></td><td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= e(ar_fmt_bytes((int)$c['bytes'])) ?></td></tr><?php endforeach; ?></tbody></table></div>
  </div>
  <p class="hint" style="margin-top:10px;"><?= tt('ar.tree_hint') ?></p>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

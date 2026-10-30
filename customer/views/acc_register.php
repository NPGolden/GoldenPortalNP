<?php
/** Commercial › Accounting › Register — every billable hour line with its accounting state (GAS-CP-R38-001). ?p=acc_register[&filters…][&export=1] */
require __DIR__ . '/_layout.php';
$u = require_staff();
if (!function_exists('acc_visible') || !acc_visible()) { http_response_code(403); layout_head(t('err.noaccess'), 'acc_reports'); echo '<h1>' . tt('err.noaccess') . '</h1>'; layout_foot(); return; }
acc_sync();
$f = acc_filters_from_request($_GET); $rows = acc_register($f); $fin = fin_visible();
if (!empty($_GET['export']) && $fin) {
    require_once __DIR__ . '/../app/xlsxw.php';
    $x = []; foreach ($rows as $l) $x[] = [$l['work_date'], (string)$l['user_name'], (string)($l['discipline'] ?? ''), (string)($l['vessel'] ?? ''), (string)($l['project_code'] ?? ''), trim(($l['sub_code'] ?? '') . ' ' . ($l['sub_name'] ?? '')), ($l['source'] === 'ts' ? 'TS ' : 'SR ') . $l['doc_no'], acc_type_label($l['hour_type']), (float)$l['hours'], (float)($l['unit_price'] ?? 0), (float)$l['discount_pct'], (float)($l['value'] ?? 0), t('acc.st_' . $l['state']), (string)($l['report_no'] ? $l['report_no'] . ' ' . $l['report_rev'] : ''), (string)($l['invoice_ref'] ?? ''), (string)($l['description'] ?? '')];
    xlsx_download('GAS-accounting-register-' . date('Ymd') . '.xlsx', [['name' => t('acc.register'), 'first' => true, 'header' => [t('th.date'), t('wv.by'), t('bg.discipline'), t('v.one'), t('pr.one'), t('sp.one'), t('th.ref'), t('ts.type'), t('ts.hours'), t('acc.rate'), t('acc.discount') . ' %', t('acc.value'), t('th.status'), t('acc.report'), t('acc.invoice'), t('ts.what')], 'rows' => $x, 'widths' => [11, 22, 18, 20, 14, 30, 18, 10, 8, 9, 9, 12, 12, 20, 16, 40]]]);
}
$tot = ['hours' => 0.0, 'value' => 0.0, 'by' => []]; foreach ($rows as $l) { $tot['hours'] += (float)$l['hours']; $tot['value'] += (float)($l['value'] ?? 0); $tot['by'][$l['state']] = ($tot['by'][$l['state']] ?? 0) + (float)$l['hours']; }
[$vs, $va] = tk_scope(); $vessels = all("SELECT v.id, v.name FROM vessels v WHERE v.is_active = 1 AND {$vs} ORDER BY v.name", $va);
[$ps, $pa] = scope_sql('p'); $projects = all("SELECT p.id, p.project_code, p.name FROM projects p WHERE {$ps} ORDER BY p.project_code, p.name", $pa);
$disc = all('SELECT id, name FROM disciplines ORDER BY sort_order, name'); $people = all("SELECT id, full_name FROM customer_users WHERE role IN ('staff','admin') AND is_active = 1 ORDER BY full_name");
$keep = array_filter($f, fn($v) => $v !== '' && $v !== null);
layout_head(t('acc.register'), 'acc_reports');
?>
<p class="eyebrow"><a href="<?= url('acc_reports') ?>"><?= tt('acc.title') ?></a> › <?= tt('acc.register') ?></p>
<div class="page-head"><div><h1>☰ <?= tt('acc.register') ?></h1><p class="lede" style="margin-bottom:0;"><?= tt('acc.register_lede') ?></p></div>
  <div class="btn-row"><?php if ($fin): ?><a class="btn btn--ghost btn--sm" href="<?= url('acc_register', $keep + ['export' => 1]) ?>">⤓ Excel</a><?php endif; ?><form method="post" action="<?= url('acc_register') ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="acc_sync"><button class="btn btn--ghost btn--sm" type="submit" title="<?= tt('acc.sync_hint') ?>">↻ <?= tt('acc.sync') ?></button></form><a class="btn btn--gold btn--sm" href="<?= url('acc_reports') ?>#acc-new">+ <?= tt('acc.new') ?></a></div></div>

<div class="kpi" style="margin-bottom:14px;">
  <?php foreach (['open' => 'gold', 'draft' => '', 'reported' => '', 'invoiced' => 'green', 'excluded' => 'mute'] as $st => $tone): ?><a class="stat <?= $tone ?>" href="<?= url('acc_register', array_diff_key($keep, ['state' => 1]) + ['state' => $st]) ?>"><b><?= e(acc_h((float)($tot['by'][$st] ?? 0))) ?> h</b><?= tt('acc.st_' . $st) ?></a><?php endforeach; ?>
</div>

<div class="card card--tight">
  <form method="get" action="index.php" class="fltbar" style="grid-template-columns: 1.2fr 1fr 1fr 1fr 1fr 1fr 1fr 1fr auto;">
    <input type="hidden" name="p" value="acc_register">
    <div class="field"><label><?= tt('pr.search') ?></label><input name="q" value="<?= e($f['q']) ?>" placeholder="TS / SR / AR …"></div>
    <div class="field"><label><?= tt('v.one') ?></label><select name="vessel"><option value=""><?= tt('flt.any') ?></option><?php foreach ($vessels as $v): ?><option value="<?= $v['id'] ?>"<?= $f['vessel'] === (int)$v['id'] ? ' selected' : '' ?>><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('pr.one') ?></label><select name="project"><option value=""><?= tt('flt.any') ?></option><?php foreach ($projects as $p): ?><option value="<?= $p['id'] ?>"<?= $f['project'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim($p['project_code'] . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('bg.discipline') ?></label><select name="discipline" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach ($disc as $d): ?><option value="<?= $d['id'] ?>"<?= $f['discipline'] === (int)$d['id'] ? ' selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('wv.by') ?></label><select name="user"><option value=""><?= tt('flt.any') ?></option><?php foreach ($people as $pp): ?><option value="<?= $pp['id'] ?>"<?= $f['user'] === (int)$pp['id'] ? ' selected' : '' ?>><?= e($pp['full_name']) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('th.status') ?></label><select name="state" data-nosearch><option value=""><?= tt('flt.any') ?></option><?php foreach (['open', 'draft', 'reported', 'invoiced', 'excluded'] as $st): ?><option value="<?= $st ?>"<?= $f['state'] === $st ? ' selected' : '' ?>><?= tt('acc.st_' . $st) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label><?= tt('acc.from') ?></label><input type="date" name="from" value="<?= e($f['from']) ?>"></div>
    <div class="field"><label><?= tt('acc.to') ?></label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
    <div class="fltbar-actions"><button class="btn btn--sm" type="submit"><?= tt('btn.apply') ?></button><a class="btn btn--ghost btn--sm" href="<?= url('acc_register') ?>"><?= tt('btn.clear') ?></a></div>
  </form>
</div>

<div class="card">
  <h2><?= tt('acc.lines') ?> <span class="count"><?= count($rows) ?></span> <span class="hint" style="display:inline;margin-left:8px;"><?= e(acc_h($tot['hours'])) ?> h<?= $fin ? ' · ' . e(acc_m($tot['value'])) : '' ?></span></h2>
  <?php if (!$rows): ?><p class="hint"><?= tt('acc.none_lines') ?></p><?php else: ?>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('th.date') ?></th><th><?= tt('wv.by') ?></th><th><?= tt('bg.discipline') ?></th><th><?= tt('v.one') ?> · <?= tt('pr.one') ?></th><th><?= tt('th.ref') ?></th><th><?= tt('ts.type') ?></th><th class="num"><?= tt('ts.hours') ?></th><th class="num"><?= tt('acc.rate') ?></th><th class="num"><?= tt('acc.value') ?></th><th><?= tt('th.status') ?></th><th><?= tt('acc.report') ?></th></tr></thead><tbody>
  <?php [$pageRows, $pager] = page_list($rows, 'acc_register', $keep); foreach ($pageRows as $l): ?>
    <tr class="<?= $l['state'] === 'excluded' ? 'is-muted' : '' ?>"><td class="date"><?= e(fmt_date($l['work_date'])) ?></td><td><?= e($l['user_name']) ?></td><td class="hint"><?= e($l['discipline'] ?? '') ?></td>
      <td><?= e($l['vessel'] ?? '') ?><?= $l['project_code'] ? '<br><span class="hint">' . e($l['project_code']) . ($l['sub_code'] ? ' ' . e($l['sub_code']) : '') . '</span>' : '' ?></td>
      <td class="mono"><a href="<?= $l['source'] === 'ts' ? url('timesheet', ['id' => $l['doc_id']]) : url('fsr', ['id' => $l['doc_id']]) ?>"><?= $l['source'] === 'ts' ? 'TS' : 'SR' ?> <?= e($l['doc_no']) ?></a><?= $l['description'] ? '<br><span class="hint">' . e(mb_substr($l['description'], 0, 60)) . '</span>' : '' ?></td>
      <td><?= e(acc_type_label($l['hour_type'])) ?></td><td class="num"><b><?= e(acc_h((float)$l['hours'])) ?></b></td>
      <td class="num"><?= $fin ? ($l['unit_price'] !== null ? e(acc_h((float)$l['unit_price'])) : '—') : fin_masked() ?></td><td class="num"><?= $fin ? ($l['value'] !== null ? e(acc_m((float)$l['value'])) : '—') : fin_masked() ?></td>
      <td><?= acc_state_pill($l['state']) ?><?= $l['invoice_ref'] ? '<br><span class="hint">' . e($l['invoice_ref']) . '</span>' : '' ?></td>
      <td class="mono"><?= $l['report_id'] ? '<a href="' . url('acc_report', ['id' => $l['report_id']]) . '">' . e(str_starts_with((string)$l['report_no'], 'DRAFT-') ? t('acc.rs_draft') : $l['report_no'] . ' ' . $l['report_rev']) . '</a>' : '—' ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?= $pager ?? '' ?>
  <?php endif; ?>
</div>
<?php layout_foot(); ?>

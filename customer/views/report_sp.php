<?php
/**
 * Per-sub-project report — PDF (print view) and Word (.docx).  GAS-CP-R3-001
 * One data assembly feeds both outputs so they never disagree.
 * Customers get external data only, exactly like the portal itself.
 */
require __DIR__ . '/_layout.php';
$u  = require_login();
$id = (int)($_GET['id'] ?? 0);
$D = rp_sp_data($id);
if (!$D) { http_response_code(404); exit(t('err.notfound')); }
extract($D);

/* ---- WORD ------------------------------------------------------------ */
if (($_GET['format'] ?? '') === 'docx') {
    $bytes = rp_sp_docx($D);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $docNo . '-' . $rev . '.docx"');
    header('Content-Length: ' . strlen($bytes));
    echo $bytes; exit;
}

/* ---- PDF (print view) ------------------------------------------------ */
layout_head($title, 'projects');
?>
<div class="no-print page-head">
  <p class="eyebrow"><a href="<?= url('subproject', ['id' => $id]) ?>">← <?= e($sp['name']) ?></a></p>
  <div class="actions">
    <button class="btn btn--gold" type="button" data-js-print="1"><?= tt('rp.print') ?></button>
    <a class="btn" href="<?= url('report_sp', ['id' => $id, 'format' => 'docx']) ?>"><?= tt('rp.word') ?></a>
  </div>
</div>
<div class="report">
  <div style="display:flex;justify-content:space-between;align-items:center;">
    <img src="../assets/golden-logo.png" alt="Golden Automation Systems" style="height:52px;">
    <div style="text-align:right;font-size:12px;color:#555;"><?= e($docNo) ?> · <?= e($rev) ?></div>
  </div>
  <h1><?= e($title) ?></h1>
  <table><tbody><?php foreach ($meta as [$k, $v]): ?><tr><th style="width:30%;background:#F6F2E7;color:#0B1E2D;"><?= e($k) ?></th><td><?= e((string)$v) ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_cover') ?></p>

  <h2><?= tt('rp.sec_abbr') ?></h2>
  <table><tbody>
    <?php foreach ([['FAT', t('ab.fat')], ['HAT', t('ab.hat')], ['SAT', t('ab.sat')], ['GA', t('ab.ga')], ['PM', t('ab.pm')], ['R[n]', t('ab.rev')]] as [$k, $v]): ?>
      <tr><th style="width:18%"><?= e($k) ?></th><td><?= e($v) ?></td></tr><?php endforeach; ?></tbody></table>

  <h2><?= tt('rp.sec_revisions') ?></h2>
  <table><thead><tr><th><?= tt('sub.rev') ?></th><th><?= tt('th.date') ?></th><th><?= tt('rp.rev_title') ?></th></tr></thead>
    <tbody><tr><td><?= e($rev) ?></td><td><?= date('d.m.Y') ?></td><td><?= tt('rp.rev_current') ?></td></tr>
    <?php foreach ($R['snaps'] as $s): ?><tr><td>R<?= (int)$s['id'] ?></td><td><?= e(fmt_date($s['snap_date'])) ?></td><td><?= tt('sp.snapshot') ?></td></tr><?php endforeach; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_rev') ?></p>

  <h2><?= tt('tab.process') ?></h2>
  <?php if ($sp['description']): ?><p><?= rt_html($sp['description']) ?></p><?php endif; ?>
  <?= process_line($sp) ?>
  <p class="cap"><?= tt('rp.fig_stages') ?></p>

  <h2><?= tt('dr.title') ?></h2>
  <table><thead><tr><th>#</th><th><?= tt('dr.item') ?></th><th><?= tt('sys.one') ?></th><th><?= tt('dr.responsible') ?></th><th><?= tt('dr.sent') ?></th><th><?= tt('dr.received') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($R['drawings'] as $r): ?><tr><td><?= (int)$r['seq_no'] ?></td><td><?= e($r['title']) ?></td><td><?= e($r['system_name'] ?? '') ?></td><td><?= e($r['responsible']) ?></td><td><?= e(fmt_date($r['request_sent'])) ?></td><td<?= $r['status'] === 'pending' && $r['expected_at'] && $r['expected_at'] < date('Y-m-d') ? ' class="is-late"' : '' ?>><?= $r['status'] === 'pending' ? ($r['expected_at'] ? tt('dr.exp_short') . ' ' . e(fmt_date($r['expected_at'])) : '—') : e(fmt_date($r['received_at'])) ?></td><td><?= tt('dr.' . $r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$R['drawings']): ?><tr><td colspan="7"><em><?= tt('dr.empty') ?></em></td></tr><?php endif; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_drawings') ?></p>

  <h2><?= tt('own.title') ?></h2>
  <table><thead><tr><th><?= tt('sub.docno') ?></th><th><?= tt('sub.doc') ?></th><th><?= tt('sub.rev') ?></th><th><?= tt('sub.submitted') ?></th><th><?= tt('sub.decided') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($R['owner'] as $r): ?><tr><td><?= e($r['doc_no']) ?></td><td><?= e($r['title']) ?></td><td><?= e($r['revision']) ?></td><td><?= e(fmt_date($r['submitted_at'])) ?></td><td><?= e(fmt_date($r['decided_at'])) ?></td><td><?= tt('sub.' . $r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$R['owner']): ?><tr><td colspan="6"><em><?= tt('sub.empty') ?></em></td></tr><?php endif; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_owner') ?></p>

  <h2><?= tt('cls.title') ?></h2>
  <table><thead><tr><th><?= tt('sub.docno') ?></th><th><?= tt('sub.doc') ?></th><th><?= tt('cls.society') ?></th><th><?= tt('sub.rev') ?></th><th><?= tt('sub.submitted') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($R['class'] as $r): ?><tr><td><?= e($r['doc_no']) ?></td><td><?= e($r['title']) ?></td><td><?= e($r['class_society']) ?></td><td><?= e($r['revision']) ?></td><td><?= e(fmt_date($r['submitted_at'])) ?></td><td><?= tt('sub.' . $r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$R['class']): ?><tr><td colspan="6"><em><?= tt('sub.empty') ?></em></td></tr><?php endif; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_class') ?></p>

  <h2><?= tt('tst.title') ?></h2>
  <table><thead><tr><th><?= tt('tst.kind') ?></th><th><?= tt('sub.doc') ?></th><th><?= tt('tst.planned') ?></th><th><?= tt('tst.performed') ?></th><th><?= tt('th.status') ?></th></tr></thead>
    <tbody><?php foreach ($R['tests'] as $r): ?><tr><td><?= e($r['kind']) ?></td><td><?= e($r['title']) ?></td><td><?= e(fmt_date($r['planned_at'])) ?></td><td><?= e(fmt_date($r['performed_at'])) ?></td><td><?= tt('tst.' . $r['status']) ?></td></tr><?php endforeach; ?>
    <?php if (!$R['tests']): ?><tr><td colspan="5"><em><?= tt('tst.empty') ?></em></td></tr><?php endif; ?></tbody></table>
  <p class="cap"><?= tt('rp.tab_tests') ?></p>

  <h2><?= tt('wk.notes') ?></h2>
  <?php foreach ($R['notes'] as $n): ?><p><strong><?= e(fmt_date($n['note_date'])) ?></strong> — <?= rt_html($n['body']) ?></p><?php endforeach; ?>
  <?php if (!$R['notes']): ?><p><em><?= tt('wk.nonotes') ?></em></p><?php endif; ?>

  <h2><?= tt('cm.title') ?></h2>
  <?php foreach ($R['comments'] as $c): ?><p><strong><?= e($c['full_name']) ?></strong> <span style="color:#666;font-size:12px;"><?= e(fmt_datetime($c['created_at'])) ?></span><br><?= rt_html($c['body']) ?></p><?php endforeach; ?>
  <?php if (!$R['comments']): ?><p><em><?= tt('cm.empty') ?></em></p><?php endif; ?>
</div>
<?php layout_foot(); ?>

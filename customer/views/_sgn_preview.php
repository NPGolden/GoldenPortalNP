<?php
/** The document as the signer sees it — shared by the signing view on the engineer's device (sign_onsite.php) and the
 *  e-mail link page (sign.php).   R97-057 / R97-058.   Expects: $doc (fsr|fc|offer|oa), $r (the loaded document), $pdfUrl ('' = none). */
$fmtH = fn(float $h) => function_exists('fsr_fmt_hours') ? fsr_fmt_hours($h) : number_format($h, 2);
$cust = (string)(($r['customer_name'] ?? '') ?: ($r['customer_db_name'] ?? '')); $ves = (string)(($r['vessel_name'] ?? '') ?: ($r['vessel'] ?? ''));
?>
  <div class="card"><h2><?= tt('sgn.h_summary') ?></h2>
    <dl class="sgn-dl">
      <dt><?= tt('sgn.f_customer') ?></dt><dd><?= e($cust ?: '—') ?></dd>
      <dt><?= tt('v.one') ?></dt><dd><?= e($ves ?: '—') ?></dd>
      <dt><?= tt('sgn.f_project') ?></dt><dd><?= e(trim((string)($r['project_code'] ?? '') . ' ' . (string)($r['project_name'] ?? ''))) ?><?= !empty($r['sub_code']) ? ' › ' . e($r['sub_code'] . ' ' . $r['sub_name']) : '' ?></dd>
      <?php if ($doc === 'fsr' || $doc === 'fc'): ?>
      <dt><?= tt('sgn.f_date') ?></dt><dd><?= e(fmt_date((string)($r['report_date'] ?? ''))) ?><?= !empty($r['location']) ? ' · ' . e((string)$r['location']) : '' ?></dd>
      <dt><?= tt('sgn.f_by') ?></dt><dd><?= e((string)($r['submitted_name'] ?? '')) ?><?= !empty($r['submitted_at']) ? ' · ' . e(fmt_datetime((string)$r['submitted_at'])) : '' ?></dd>
      <?php elseif ($doc === 'offer'): ?>
      <dt><?= tt('sgn.f_subject') ?></dt><dd><?= e((string)$r['title']) ?></dd>
      <dt><?= tt('sgn.f_amount') ?></dt><dd><b><?= e(number_format((float)$r['amount'], 2, ',', '.') . ' ' . (string)($r['currency'] ?: 'EUR')) ?></b><?= (float)($r['vat_pct'] ?? 0) > 0 ? ' <span class="hint">+ ' . e(rtrim(rtrim(number_format((float)$r['vat_pct'], 2, ',', ''), '0'), ',')) . ' % ' . tt('sgn.vat') . '</span>' : '' ?></dd>
      <?php if (!empty($r['valid_until'])): ?><dt><?= tt('of.valid_until') ?></dt><dd><?= e(fmt_date((string)$r['valid_until'])) ?></dd><?php endif; ?>
      <?php else: ?>
      <dt><?= tt('sgn.f_subject') ?></dt><dd><?= e((string)$r['title']) ?></dd>
      <dt><?= tt('sgn.f_rev') ?></dt><dd><?= e(trim((string)$r['doc_no'] . ' ' . (string)$r['revision'])) ?></dd>
      <?php if (!empty($r['due_at'])): ?><dt><?= tt('sgn.f_due') ?></dt><dd><?= e(fmt_date((string)$r['due_at'])) ?></dd><?php endif; ?>
      <?php endif; ?>
    </dl>
    <?php if ($pdfUrl !== ''): ?><p><a class="btn btn--gold" href="<?= e($pdfUrl) ?>" target="_blank" rel="noopener">⤓ <?= tt('sgn.open_full') ?></a></p><?php endif; ?>
  </div>
  <?php if ($doc === 'fsr'): foreach ((array)$r['works'] as $w): ?>
  <div class="card"><h2><?= e(t('sgn.task', ['n' => (int)$w['seq']])) ?></h2>
    <?php if (trim((string)$w['description']) !== ''): ?><p class="sgn-text"><?= nl2br(e(trim(strip_tags((string)$w['description'])))) ?></p><?php endif; ?>
    <?php $pp = array_values(array_filter((array)$w['parts'], fn($p) => trim((string)($p['d'] ?? '')) !== '')); if ($pp): ?><h3><?= tt('sgn.parts') ?></h3><ul class="sgn-list"><?php foreach ($pp as $p): ?><li><?= e((string)$p['d']) ?><?= !empty($p['q']) ? ' × ' . e((string)$p['q']) : '' ?><?= !empty($p['t']) ? ' <span class="hint">(' . e((string)$p['t']) . ')</span>' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if (trim((string)($w['open_issues'] ?? '')) !== ''): ?><h3><?= tt('sgn.open_issues') ?></h3><p class="sgn-text"><?= nl2br(e(trim(strip_tags((string)$w['open_issues'])))) ?></p><?php endif; ?>
    <?php if ($w['lines']): ?><div class="table-scroll"><table class="tbl sgn-tbl"><thead><tr><th><?= tt('sgn.c_who') ?></th><th><?= tt('sgn.c_time') ?></th><th><?= tt('sgn.c_type') ?></th><th class="num"><?= tt('sgn.c_hours') ?></th></tr></thead><tbody>
      <?php foreach ($w['lines'] as $l): ?><tr><td><?= e((string)($l['engineer'] ?: '—')) ?><br><span class="hint"><?= e((string)$l['specialty']) ?></span></td><td><?= e(fsr_fmt_time($l['start_time'])) ?>–<?= e(fsr_fmt_time($l['end_time'])) ?></td><td><?= e((string)$l['time_type']) ?></td><td class="num"><?= e($fmtH((float)$l['hours'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <?php endforeach; $S = $r['summary']; ?>
  <div class="card"><h2><?= tt('sgn.h_hours') ?></h2><dl class="sgn-dl sgn-dl--num"><dt><?= e(fsr_time_type_label('WT')) ?></dt><dd><?= e($fmtH((float)$S['wt'])) ?></dd><dt><?= e(fsr_time_type_label('TT')) ?></dt><dd><?= e($fmtH((float)$S['tt'])) ?></dd><?php if ((float)$S['nc'] > 0): ?><dt><?= e(fsr_time_type_label('NC')) ?></dt><dd><?= e($fmtH((float)$S['nc'])) ?></dd><?php endif; ?><dt><b><?= tt('sgn.total') ?></b></dt><dd><b><?= e($fmtH((float)$S['total'])) ?></b></dd></dl></div>
  <?php elseif ($pdfUrl !== ''): ?>
  <div class="card"><p><?= tt('sgn.fc_note') ?></p></div>
  <?php endif; ?>

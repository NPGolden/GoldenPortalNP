<?php
/** Function check — read-only sheet, PDF, customer acceptance, history.   GAS-CP-R77-001   ?p=fc&id= */
require __DIR__ . '/_layout.php';
$u = require_login(); $id = (int)($_GET['id'] ?? 0); $r = function_exists('fc_has') && fc_has() ? fc_load($id) : null;
if (!$r) { http_response_code(404); layout_head(t('err.notfound'), 'reports'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$tpl = $r['tpl']; $D = $r['data']; $staff = is_staff(); $isCustomer = !$staff && (int)$u['customer_id'] === (int)$r['customer_id'];
$canAccept = $r['status'] === 'submitted' && ($isCustomer || is_cp_admin()); $pg = fc_progress($r);
$mark = fn(string $v) => $v === 'ok' ? '<span class="fc-m fc-m-ok">✓</span>' : ($v === 'na' ? '<span class="fc-m fc-m-na">✗</span>' : '<span class="fc-m">☐</span>');
layout_head($r['report_no'] . ' · ' . $tpl['short'], 'reports');
?>
<p class="eyebrow"><a href="<?= url('reports') ?>"><?= tt('r.title') ?></a> › <?= tt('fc.plural') ?> › <?= e($r['report_no']) ?></p>
<div class="page-head">
  <div><h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($r['report_no']) ?></span> <?= e($tpl['title']) ?> <span class="hint" style="display:inline;font-size:15px;">· R<?= (int)$r['version'] ?></span></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($r['vessel_name'] ?: '') ?> · <?= e(trim(($r['project_code'] ?: '') . ' ' . $r['project_name'])) ?><?= $r['sub_code'] ? ' · ' . e($r['sub_code'] . ' ' . $r['sub_name']) : '' ?> · <?= e(fmt_date($r['report_date'])) ?> · <?= e($tpl['maker']) ?></p></div>
  <div class="actions"><?= fc_status_pill($r['status']) ?>
    <?php if ($r['pdf_file_id']): ?> <a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $r['pdf_file_id']]) ?>" target="_blank">⤓ PDF</a><?php endif; ?>
    <?php if ($staff && $r['status'] === 'draft'): ?> <a class="btn btn--gold btn--sm" href="<?= url('fc_edit', ['id' => $id]) ?>">✎ <?= tt('btn.edit') ?></a> <form method="post" action="<?= url('fc', ['id' => $id]) ?>" class="inline" data-confirm="<?= tt('fc.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fc_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?>
    <?php if ($staff && in_array($r['status'], ['submitted', 'disputed'], true)): ?> <form method="post" action="<?= url('fc', ['id' => $id]) ?>" class="inline" data-confirm="<?= tt('fc.reopen_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fc.reopen') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="fc_reopen"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit">↩ <?= tt('fc.reopen') ?></button></form><?php endif; ?>
  </div>
</div>

<div class="kpi" style="margin-bottom:12px;">
  <span class="stat green"><b><?= $pg['ok'] ?></b><?= tt('fc.k_ok') ?></span><span class="stat mute"><b><?= $pg['na'] ?></b><?= tt('fc.k_na') ?></span><span class="stat<?= $pg['open'] ? ' gold' : '' ?>"><b><?= $pg['open'] ?></b><?= tt('fc.k_open') ?></span><span class="stat<?= !empty($D['closing']) ? ' green' : '' ?>"><b><?= !empty($D['closing']) ? '✓' : '—' ?></b><?= tt('fc.k_closing') ?></span>
</div>
<?php if ($r['status'] === 'disputed' && $r['customer_remark']): ?><div class="flash flash--bad"><b><?= tt('fc.s_disputed') ?>:</b> <?= e($r['customer_remark']) ?></div><?php endif; ?>

<div class="card">
  <div class="grid grid-2 fc-head">
    <?php foreach ($tpl['head'] as [$k, $lab, $kind]): $v = (string)($D['head'][$k] ?? ''); ?><div class="field"><label><?= e($lab) ?></label><div class="fc-ro"><?= $v !== '' ? e($kind === 'date' ? fmt_date($v) : $v) : '—' ?></div></div><?php endforeach; ?>
  </div>
</div>
<div class="card">
  <h2><?= tt('fc.checks') ?></h2><p class="hint"><?= e($tpl['intro']) ?></p>
  <div class="fc-grid">
    <?php foreach ($tpl['sides'] as $s => $sName): ?>
    <div class="fc-side"><h3><?= e($sName) ?></h3>
      <?php foreach ($tpl['groups'] as $g): ?>
      <table class="fc-tbl" data-nosort><thead><tr><th colspan="3"><?= e($g['title']) ?></th></tr></thead><tbody>
      <?php foreach ($g['rows'] as $i => $row): ?><tr><td class="fc-n"><?= $i + 1 ?></td><?php foreach ($row as $j => $lab): $k = $g['key'] . '.' . $i . '.' . $j; $label = $lab ?? (string)($D['free'][$s][$k] ?? ''); $v = (string)($D['chk'][$s][$k] ?? ''); ?><td><?= $label !== '' ? $mark($v) . ' <span class="' . ($v === 'na' ? 'fc-na' : '') . '">' . e($label) . '</span>' : '' ?></td><?php endforeach; ?></tr><?php endforeach; ?>
      </tbody></table>
      <?php endforeach; ?>
      <table class="fc-tbl" data-nosort><thead><tr><th colspan="4"><?= e($tpl['values_title']) ?></th></tr></thead><tbody>
      <?php foreach ($tpl['values'] as [$k, $lab, $u1, $u2]): $v = $D['val'][$s][$k] ?? []; $label = $lab ?? (string)($v['label'] ?? ''); if ($label === '' && ($v['v1'] ?? '') === '' && ($v['v2'] ?? '') === '') continue; ?><tr><td class="fc-n"><?= e($k) ?></td><td><?= e($label) ?></td><td class="fc-v"><b><?= e((string)($v['v1'] ?? '')) ?></b> <span class="hint"><?= e($u1) ?></span></td><td class="fc-v"><b><?= e((string)($v['v2'] ?? '')) ?></b> <span class="hint"><?= e($u2) ?></span></td></tr><?php endforeach; ?>
      </tbody></table>
    </div>
    <?php endforeach; ?>
  </div>
  <p style="margin-top:10px;"><?php foreach ($tpl['extra'] as [$k, $lab, $un]): ?><span style="margin-right:18px;"><?= e($lab) ?>: <b><?= e((string)($D['extra'][$k] ?? '') ?: '—') ?></b> <?= e($un) ?></span><?php endforeach; ?></p>
  <p><?= !empty($D['closing']) ? '<span class="fc-m fc-m-ok">✓</span>' : '<span class="fc-m">☐</span>' ?> <b><?= e($tpl['closing']) ?></b></p>
  <?php if (trim((string)$r['remarks']) !== ''): ?><h3 class="ed-h"><?= tt('fc.remarks') ?></h3><p><?= nl2br(e((string)$r['remarks'])) ?></p><?php endif; ?>
</div>

<div class="grid grid-2">
  <div class="card"><h2><?= tt('fc.sign_customer') ?></h2><?php if ($r['customer_sign_id']): ?><img src="<?= url('file', ['id' => $r['customer_sign_id']]) ?>" alt="" style="max-width:320px;max-height:110px;display:block;"><?php endif; ?><p><b><?= e($r['accepted_name'] ?: ($r['customer_name'] ?: '—')) ?></b><?= $r['accepted_at'] ? '<br><span class="hint">' . e(fmt_datetime($r['accepted_at'])) . ($r['accepted_ip'] && $staff ? ' · ' . e($r['accepted_ip']) : '') . '</span>' : '<br><span class="hint">' . tt('fc.not_signed') . '</span>' ?></p></div>
  <div class="card"><h2><?= tt('fc.sign_engineer') ?></h2><?php if ($r['golden_sign_id']): ?><img src="<?= url('file', ['id' => $r['golden_sign_id']]) ?>" alt="" style="max-width:320px;max-height:110px;display:block;"><?php endif; ?><p><b><?= e($r['submitted_name'] ?: ($r['engineer_name'] ?: '—')) ?></b><?= $r['submitted_at'] ? '<br><span class="hint">' . e(fmt_datetime($r['submitted_at'])) . '</span>' : '' ?></p></div>
</div>

<?php if ($canAccept): ?>
<div class="card fsr-accept">
  <h2><?= tt('fc.accept_title') ?></h2><p><?= tt('fc.accept_lede') ?></p>
  <form method="post" action="<?= url('fc', ['id' => $id]) ?>" data-lock><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="fc_accept">
    <div class="field"><label><?= tt('fsr.remark') ?></label><textarea name="remark" rows="3" placeholder="<?= tt('fsr.remark_ph') ?>"></textarea></div>
    <div class="field"><label><?= tt('fc.sign_here') ?></label><div data-sigpad="signature" data-clear="<?= tt('btn.clear') ?>"></div><input type="hidden" name="signature"></div>
    <div class="btn-row"><button class="btn btn--gold" type="submit" data-confirm="<?= e(t('fsr.accept_confirm', ['no' => $r['report_no']])) ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.accept') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>">✓ <?= tt('fsr.accept') ?></button><button class="btn btn--ghost btn--danger-ghost" type="submit" name="action" value="fc_dispute" data-confirm="<?= tt('fsr.dispute_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('fsr.dispute') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= tt('fsr.dispute') ?></button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($staff): ?><div class="card"><h2><?= tt('td.history') ?></h2><ul class="tk-events"><?php foreach ($r['events'] as $ev): ?><li><span class="when"><?= e(fmt_datetime($ev['created_at'])) ?></span> <b><?= e(t('fc.ev_' . $ev['event'])) ?></b> · <?= e($ev['user_name'] ?: '') ?><?= $ev['note'] ? ' <span class="hint">— ' . e($ev['note']) . '</span>' : '' ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php layout_foot(); ?>

<?php
/** One accounting report — the PM's review (include / exclude, discounts, zone), issue, e-mail to accounting, accounting's answer (GAS-CP-R38-001). ?p=acc_report&id= */
require __DIR__ . '/_layout.php';
$u = require_staff();
$id = (int)($_GET['id'] ?? 0); $R = function_exists('acc_load') ? acc_load($id) : null;
if (!$R || !acc_visible()) { http_response_code(404); layout_head(t('err.notfound'), 'acc_reports'); echo '<h1>' . tt('err.notfound') . '</h1>'; layout_foot(); return; }
$self = url('acc_report', ['id' => $id]); $fin = fin_visible(); $edit = $R['can_edit']; $isAcc = acc_is_accounting();
$canIssue = in_array($R['status'], ['draft', 'returned'], true) && acc_can_prepare((int)$R['project_id'] ?: null);
$cur = $R['currency'] ?: 'EUR'; $inc = array_values(array_filter($R['lines'], fn($l) => $l['state'] !== 'excluded')); $exc = array_values(array_filter($R['lines'], fn($l) => $l['state'] === 'excluded'));
$bud = acc_budget_rows($R); $budK = []; foreach ($bud as $b) $budK[$b['subproject_id'] . '|' . $b['discipline_id']] = $b;
$rateOpts = []; if ($edit && function_exists('rl_current') && ($rlc = rl_current((int)$R['branch_id'] ?: null))) foreach (rl_items((int)$rlc['id'], 'hourly') as $it) if (acc_item_type($it)) $rateOpts[] = $it;
$draftNo = str_starts_with((string)$R['report_no'], 'DRAFT-');
$title = ($draftNo ? t('acc.rs_draft') : $R['report_no'] . ' ' . $R['revision']);
$acctMail = function_exists('br_get') && !empty($R['branch_id']) ? (string)(br_get((int)$R['branch_id'])['accounting_email'] ?? '') : '';
if ($acctMail === '' && function_exists('br_default')) $acctMail = (string)(br_default()['accounting_email'] ?? '');
layout_head(t('acc.report') . ' ' . $title, 'acc_reports');
?>
<p class="eyebrow"><a href="<?= url('acc_reports') ?>"><?= tt('acc.title') ?></a> › <?= e($title) ?></p>
<div class="page-head">
  <div><h1><span style="font-family:var(--mono);color:var(--gold-text);"><?= e($title) ?></span> <?= tt('acc.report') ?></h1>
    <p class="lede" style="margin-bottom:0;"><?= e($R['vessel'] ?: '') ?><?= $R['project_code'] ? ' · ' . e(trim($R['project_code'] . ' ' . $R['project_name'])) : '' ?><?= $R['sub_code'] ? ' · ' . e(trim($R['sub_code'] . ' ' . $R['sub_name'])) : '' ?> · <?= $R['period_start'] ? e(fmt_date($R['period_start'])) : '…' ?> – <?= $R['period_end'] ? e(fmt_date($R['period_end'])) : '…' ?> · <?= tt('acc.z_' . $R['zone']) ?></p></div>
  <div class="actions"><?= acc_status_pill($R['status']) ?>
    <?php if ($R['pdf_file_id']): ?> <a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $R['pdf_file_id']]) ?>">⤓ PDF</a><?php endif; ?><?php if ($R['xlsx_file_id']): ?> <a class="btn btn--ghost btn--sm" href="<?= url('file', ['id' => $R['xlsx_file_id']]) ?>">⤓ Excel</a><?php endif; ?>
    <?php if ($canIssue): ?> <form method="post" action="<?= $self ?>" class="inline" data-lock data-busy="<?= tt('acc.issuing') ?>" data-confirm="<?= tt('acc.issue_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('acc.issue') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_issue"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--gold btn--sm" type="submit">⎙ <?= tt('acc.issue') ?> <?= e($R['status'] === 'returned' ? 'R' . ((int) substr((string)$R['revision'], 1) + 1) : 'R0') ?></button></form><?php endif; ?>
    <?php if ($R['status'] === 'draft' && $edit): ?> <form method="post" action="<?= $self ?>" class="inline" data-confirm="<?= tt('acc.delete_confirm') ?>" data-confirm-title="<?= tt('dlg.title') ?>" data-confirm-ok="<?= tt('btn.delete') ?>" data-confirm-cancel="<?= tt('btn.cancel') ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_delete"><input type="hidden" name="id" value="<?= $id ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= tt('btn.delete') ?></button></form><?php endif; ?>
  </div>
</div>

<div class="kpi" style="margin-bottom:14px;">
  <span class="stat"><b><?= count($inc) ?></b><?= tt('acc.lines_included') ?></span>
  <span class="stat"><b><?= e(acc_h((float)$R['total_hours'])) ?> h</b><?= tt('acc.total_hours') ?></span>
  <span class="stat gold"><b><?= $fin ? e(acc_m((float)$R['total_gross'], $cur)) : fin_masked() ?></b><?= tt('acc.total_gross') ?></span>
  <span class="stat green"><b><?= $fin ? e(acc_m((float)$R['total_net'], $cur)) : fin_masked() ?></b><?= tt('acc.total_net') ?></span>
  <span class="stat<?= $exc ? '' : ' mute' ?>"><b><?= count($exc) ?></b><?= tt('acc.st_excluded') ?></span>
</div>

<?php if ($R['status'] === 'returned' && $R['answer_note']): ?><div class="flash flash--warn"><b><?= tt('acc.rs_returned') ?>:</b> <?= e($R['answer_note']) ?> <span class="hint">— <?= e($R['answered_name']) ?> · <?= e(fmt_datetime($R['answered_at'])) ?></span></div><?php endif; ?>

<?php if ($bud): ?>
<div class="card">
  <h2><?= tt('acc.sec_budget') ?></h2>
  <div class="table-scroll"><table class="tbl"><thead><tr><th><?= tt('sp.one') ?></th><th><?= tt('bg.discipline') ?></th><th class="num"><?= tt('bg.budget_h') ?></th><th class="num"><?= tt('acc.invoiced_h') ?></th><th class="num"><?= tt('acc.this_report') ?></th><th class="num"><?= tt('bg.remaining') ?></th></tr></thead><tbody>
  <?php foreach ($bud as $b): ?><tr class="<?= $b['over'] ? 'is-late' : '' ?>"><td><?= e($b['sub']) ?></td><td><?= e($b['discipline']) ?></td><td class="num"><?= $b['budget'] !== null ? e(acc_h($b['budget'])) : '—' ?></td><td class="num"><?= e(acc_h($b['invoiced'])) ?></td><td class="num"><b><?= e(acc_h($b['here'])) ?></b></td><td class="num<?= $b['over'] ? ' bg-neg' : '' ?>"><?= $b['budget'] !== null ? e(acc_h($b['budget'] - $b['after'])) : '—' ?><?= $b['over'] ? ' ' . pill('bad', t('acc.over_budget')) : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</div>
<?php endif; ?>

<form method="post" action="<?= $self ?>" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="acc_save"><input type="hidden" name="id" value="<?= $id ?>">
<div class="card">
  <div class="card-head"><h2><?= tt('acc.sec_detail') ?> <span class="count"><?= count($R['lines']) ?></span></h2>
    <?php if ($edit): ?><span class="hint"><?= tt('acc.review_hint') ?></span><?php endif; ?></div>
  <?php if ($edit): ?>
  <div class="grid grid-4" style="margin-bottom:10px;">
    <div class="field"><label><?= tt('acc.zone') ?></label><select name="zone" data-nosearch><option value="local"<?= $R['zone'] === 'local' ? ' selected' : '' ?>><?= tt('acc.z_local') ?></option><option value="overseas"<?= $R['zone'] === 'overseas' ? ' selected' : '' ?>><?= tt('acc.z_overseas') ?></option></select></div>
    <div class="field"><label><?= tt('acc.header_discount') ?> %</label><input name="header_discount_pct" inputmode="decimal" value="<?= e(acc_h((float)$R['header_discount_pct'])) ?>"></div>
    <div class="field"><label><?= tt('acc.from') ?></label><input type="date" name="from" value="<?= e((string)$R['period_start']) ?>"></div>
    <div class="field"><label><?= tt('acc.to') ?></label><input type="date" name="to" value="<?= e((string)$R['period_end']) ?>"></div>
  </div>
  <div class="field"><label><?= tt('acc.note') ?></label><input name="note" maxlength="1000" value="<?= e((string)$R['note']) ?>"></div>
  <?php elseif ($R['note']): ?><p class="hint"><?= e($R['note']) ?></p><?php endif; ?>
  <?php if (!$R['lines']): ?><p class="hint"><?= tt('acc.no_lines') ?></p><?php else: ?>
  <?php $grp = acc_group_lines($R); if ($exc) $grp[t('acc.sec_excluded')] = ['' => ['' => $exc]]; ?>
  <div class="table-scroll"><table class="tbl acc-tbl"><thead><tr><?php if ($isAcc && in_array($R['status'], ['issued', 'sent', 'partly_invoiced'], true)): ?><th title="<?= tt('acc.tick_invoiced') ?>">€</th><?php endif; ?><th><?= tt('th.date') ?></th><th><?= tt('wv.by') ?></th><th><?= tt('th.ref') ?></th><th><?= tt('ts.type') ?></th><th class="num"><?= tt('ts.hours') ?></th><th><?= tt('rl.card') ?></th><th class="num"><?= tt('acc.rate') ?></th><th class="num"><?= tt('acc.discount') ?> %</th><th class="num"><?= tt('acc.value') ?></th><th><?= tt('ts.what') ?> / <?= tt('acc.reason') ?></th><th><?= tt('th.status') ?></th><?php if ($edit): ?><th></th><?php endif; ?></tr></thead><tbody>
  <?php foreach ($grp as $pName => $subs): foreach ($subs as $sName => $discs): foreach ($discs as $dName => $ls): $gh = array_sum(array_map(fn($l) => (float)$l['hours'], $ls)); $gv = array_sum(array_map(fn($l) => (float)$l['value'], $ls)); ?>
    <tr class="acc-grp"><td colspan="<?= 12 + ($edit ? 1 : 0) + ($isAcc ? 1 : 0) ?>"><b><?= e(trim($pName . ($sName !== '' ? ' · ' . $sName : '') . ($dName !== '' ? ' · ' . $dName : ''))) ?></b> <span class="hint"><?= e(acc_h($gh)) ?> h<?= $fin && $sName !== '' ? ' · ' . e(acc_m($gv, $cur)) : '' ?></span><?php if ($sName !== '' && isset($ls[0]['subproject_id']) && ($bk = $budK[(int)$ls[0]['subproject_id'] . '|' . (int)$ls[0]['discipline_id']] ?? null) && $bk['budget'] !== null): ?> <span class="hint">· <?= tt('bg.budget_h') ?> <?= e(acc_h($bk['budget'])) ?> · <?= tt('acc.invoiced_h') ?> <?= e(acc_h($bk['invoiced'])) ?></span><?= $bk['over'] ? ' ' . pill('bad', t('acc.over_budget')) : '' ?><?php endif; ?></td></tr>
    <?php foreach ($ls as $l): $lid = (int)$l['id']; $isX = $l['state'] === 'excluded'; ?>
    <tr class="<?= $isX ? 'is-muted' : '' ?>">
      <?php if ($isAcc && in_array($R['status'], ['issued', 'sent', 'partly_invoiced'], true)): ?><td><?php if ($l['state'] === 'reported'): ?><input type="checkbox" form="acc-answer" name="inv[]" value="<?= $lid ?>" class="rowchk"><?php endif; ?></td><?php endif; ?>
      <td class="date"><?= e(fmt_date($l['work_date'])) ?></td>
      <td><?= e($l['user_name']) ?></td>
      <td class="mono"><a href="<?= $l['source'] === 'ts' ? url('timesheet', ['id' => $l['doc_id']]) : url('fsr', ['id' => $l['doc_id']]) ?>"><?= $l['source'] === 'ts' ? 'TS' : 'SR' ?> <?= e($l['doc_no']) ?></a></td>
      <td><?= e(acc_type_label($l['hour_type'])) ?><?= $l['zone'] && $l['zone'] !== $R['zone'] ? ' <span class="hint">' . tt('acc.z_' . $l['zone']) . '</span>' : '' ?></td>
      <td class="num"><b><?= e(acc_h((float)$l['hours'])) ?></b></td>
      <td class="hint"><?php if ($edit && $rateOpts): ?><select name="ri[<?= $lid ?>]" data-nosearch style="min-width:120px;font-size:12px;"><option value="">— <?= e(($l['rate_card'] ?: acc_card_of($l['discipline_id'] ? (int)$l['discipline_id'] : null)) . ' ' . t('acc.auto')) ?></option><?php foreach ($rateOpts as $it): ?><option value="<?= $it['id'] ?>"<?= (int)$l['rate_item_id'] === (int)$it['id'] ? ' selected' : '' ?>><?= e($it['card'] . ' · ' . acc_type_label(acc_item_type($it)) . ' · ' . ($it['price_local'] !== null ? acc_h((float)$it['price_local']) . ' L' : '') . ($it['price_overseas'] !== null ? ' ' . acc_h((float)$it['price_overseas']) . ' O' : '')) ?></option><?php endforeach; ?></select>
          <select name="lz[<?= $lid ?>]" data-nosearch style="font-size:12px;margin-top:2px;"><option value=""><?= tt('acc.z_report') ?></option><option value="local"<?= $l['zone'] === 'local' ? ' selected' : '' ?>><?= tt('acc.z_local') ?></option><option value="overseas"<?= $l['zone'] === 'overseas' ? ' selected' : '' ?>><?= tt('acc.z_overseas') ?></option></select>
        <?php else: ?><?= e(($l['rate_card'] ?: '') . ' · ' . acc_type_label($l['hour_type']) . ($l['zone'] && $l['zone'] !== $R['zone'] ? ' · ' . t('acc.z_' . $l['zone']) : '')) ?><?php endif; ?></td>
      <td class="num"><?= $fin ? ($l['unit_price'] !== null ? e(acc_h((float)$l['unit_price'])) : '<span class="bg-neg">' . tt('acc.no_rate') . '</span>') : fin_masked() ?></td>
      <td class="num"><?php if ($edit && !$isX): ?><input name="disc[<?= $lid ?>]" inputmode="decimal" value="<?= e(acc_h((float)$l['discount_pct'])) ?>" style="width:64px;text-align:right;"><?php else: ?><?= (float)$l['discount_pct'] > 0 ? e(acc_h((float)$l['discount_pct'])) . ' %' : '' ?><?php endif; ?></td>
      <td class="num"><?= $fin ? ($isX ? '—' : e(acc_m((float)$l['value'], $cur))) : fin_masked() ?></td>
      <td class="hint"><?= e($l['description'] ?? '') ?><?php if ($edit): ?><input name="why[<?= $lid ?>]" maxlength="255" placeholder="<?= tt('acc.reason_ph') ?>" value="<?= e((string)($isX ? $l['exclude_reason'] : $l['discount_reason'])) ?>" style="margin-top:2px;"><?php elseif ($l['exclude_reason'] || $l['discount_reason']): ?><br><i><?= e((string)($isX ? $l['exclude_reason'] : $l['discount_reason'])) ?></i><?php endif; ?></td>
      <td><?php if ($edit): ?><label class="chk" style="margin:0;"><input type="radio" name="inc[<?= $lid ?>]" value="1"<?= !$isX ? ' checked' : '' ?>> <?= tt('acc.included') ?></label><label class="chk" style="margin:0;"><input type="radio" name="inc[<?= $lid ?>]" value="0"<?= $isX ? ' checked' : '' ?>> <?= tt('acc.st_excluded') ?></label><?php else: ?><?= acc_state_pill($l['state']) ?><?= $l['invoice_ref'] ? '<br><span class="hint">' . e($l['invoice_ref']) . '</span>' : '' ?><?php endif; ?></td>
      <?php if ($edit): ?><td class="ob-act"><button class="linkbtn" type="submit" form="rel<?= $lid ?>" title="<?= tt('acc.release_hint') ?>">↩</button></td><?php endif; ?>
    </tr>
    <?php endforeach; endforeach; endforeach; endforeach; ?>
  </tbody></table></div>
  <?php if ($edit): ?><div class="btn-row" style="margin-top:10px;"><button class="btn btn--gold" type="submit"><?= tt('acc.save_review') ?></button><span class="hint"><?= tt('acc.save_hint') ?></span></div><?php endif; ?>
  <?php endif; ?>
</div>
</form>
<?php if ($edit): foreach ($R['lines'] as $l): ?><form method="post" action="<?= $self ?>" id="rel<?= (int)$l['id'] ?>"><?= csrf_field() ?><input type="hidden" name="action" value="acc_release"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"></form><?php endforeach; endif; ?>

<?php if (in_array($R['status'], ['issued', 'sent', 'returned', 'partly_invoiced'], true) && $R['pdf_file_id'] && function_exists('em_card_html')): ?>
  <?= em_card_html(['kind' => 'accounting', 'ctx' => ['no' => $R['report_no'] . ' ' . $R['revision'], 'title' => t('acc.report'), 'vessel' => (string)($R['vessel'] ?? ''), 'customer' => (string)($R['customer_name'] ?? ''), 'project' => (string)($R['project_code'] ?? ''), 'doc' => $R['report_no'] . ' ' . $R['revision'], 'customer_id' => (int)$R['customer_id'] ?: null, 'project_id' => (int)$R['project_id'] ?: null, 'locale' => locale()],
                     'people' => $acctMail !== '' ? [['name' => t('acc.accounting_dept'), 'email' => $acctMail, 'role' => '', 'on' => true]] : [], 'atts' => array_values(array_filter([$R['pdf_file_id'] ? ['f:' . (int)$R['pdf_file_id'], $R['report_no'] . ' ' . $R['revision'] . '.pdf'] : null, $R['xlsx_file_id'] ? ['f:' . (int)$R['xlsx_file_id'], $R['report_no'] . ' ' . $R['revision'] . '.xlsx'] : null])), 'back' => $self, 'hidden' => '<input type="hidden" name="acc_report" value="' . $id . '">']) ?>
<?php endif; ?>

<?php if ($isAcc && in_array($R['status'], ['issued', 'sent', 'partly_invoiced'], true)): ?>
<div class="card" id="acc-answer-card">
  <h2>✓ <?= tt('acc.answer') ?></h2>
  <p class="hint"><?= tt('acc.answer_hint') ?></p>
  <form method="post" action="<?= $self ?>" id="acc-answer" data-lock><?= csrf_field() ?><input type="hidden" name="action" value="acc_answer"><input type="hidden" name="id" value="<?= $id ?>">
    <div class="grid grid-4">
      <div class="field"><label><?= tt('acc.answer') ?></label><select name="answer" data-nosearch><option value="invoiced"><?= tt('acc.a_invoiced') ?></option><option value="partly"><?= tt('acc.a_partly') ?></option><option value="returned"><?= tt('acc.a_returned') ?></option></select></div>
      <div class="field"><label><?= tt('acc.invoice_ref') ?></label><input name="invoice_ref" maxlength="120" placeholder="INV-2026-…"></div>
      <div class="field"><label><?= tt('acc.invoice_date') ?></label><input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>"></div>
      <div class="field"><label><?= tt('acc.answer_note') ?></label><input name="answer_note" maxlength="1000"></div>
    </div>
    <button class="btn btn--gold" type="submit"><?= tt('acc.answer_btn') ?></button>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <h2><?= tt('td.history') ?></h2>
  <?php if (!$R['events']): ?><p class="hint">—</p><?php else: ?><ul class="tk-events"><?php foreach ($R['events'] as $ev): ?><li><span class="when"><?= e(fmt_datetime($ev['created_at'])) ?></span> <b><?= e(t('acc.ev_' . $ev['event'])) ?></b> · <?= e($ev['user_name'] ?: '') ?><?= $ev['note'] ? ' <span class="hint">— ' . e($ev['note']) . '</span>' : '' ?></li><?php endforeach; ?></ul><?php endif; ?>
  <?php if (function_exists('ar_issued') && !$draftNo && $R['project_id']): $iss = ar_issued($R['report_no'], (int)$R['project_id']); if ($iss): ?><h3 class="ed-h" style="margin-top:12px;"><?= tt('ar.issued') ?></h3><ul class="hint"><?php foreach ($iss as $rv): ?><li><b><?= e($rv['revision']) ?></b> · <?= e(fmt_datetime($rv['at'])) ?> · <?= e($rv['by'] ?: '') ?> <?php foreach ($rv['files'] as $ext => $fid): ?><a href="<?= url('file', ['id' => $fid]) ?>">⤓ <?= e(strtoupper($ext)) ?></a> <?php endforeach; ?></li><?php endforeach; ?></ul><?php endif; endif; ?>
</div>
<?php layout_foot(); ?>

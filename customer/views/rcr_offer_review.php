<?php
/** Employment offer — approval by the CEO, the General Manager or the Managing Director (GAS-CP-R97-247).   ?p=rcr_offer_review&id=<offer>
 *  Shows the terms of one offer that waits (or waited) for approval; nothing else of the application. */
require __DIR__ . '/_layout.php';
$u = require_staff(); $id = (int)($_GET['id'] ?? 0);
$o = function_exists('rcr_offer_for_approver') ? rcr_offer_for_approver($id) : null;
if (!$o) { http_response_code(403); layout_head(t('rc.ap_title'), 'my_approvals'); echo '<h1>' . tt('err.noaccess') . '</h1><p class="hint">' . tt('rc.ap_only') . '</p>'; layout_foot(); return; }
$lc = locale(); $d = fn(?string $x) => $x ? fmt_date($x) : '—'; $own = (int)$o['appr_req_by'] === (int)$u['id']; $wait = $o['status'] === 'draft' && $o['appr_status'] === 'pending';
$rows = [[t('rc.of_position'), (string)$o['position']], [t('rc.candidate'), (string)$o['cand_name']], [t('rc.ap_vacancy'), trim((string)$o['vacancy_code'] . ' ' . (string)$o['vacancy'])], [t('rc.of_salary'), rcr_offer_salary_text($o, $lc)], [t('rc.of_contract'), t('rc.of_c_' . $o['contract'])], [t('rc.of_start'), $d($o['start_date'])],
         [t('rc.of_hours'), (string)$o['hours']], [t('rc.of_place'), (string)$o['place']], [t('rc.of_probation'), $o['probation_months'] !== null ? t('rc.of_months', ['n' => (int)$o['probation_months']]) : ''], [t('rc.of_benefits'), (string)$o['benefits']], [t('rc.of_terms'), (string)$o['terms']], [t('rc.of_valid'), $d($o['valid_until'])]];
layout_head(t('rc.ap_title') . ' ' . $o['offer_no'], 'my_approvals');
?>
<div class="page-head"><div><p class="crumbs"><a href="<?= url('my_approvals') ?>"><?= tt('apr.title') ?></a> › <?= e($o['offer_no']) ?></p><h1>🔏 <?= tt('rc.ap_title') ?> — <span style="font-family:var(--mono);"><?= e($o['offer_no']) ?></span></h1>
  <p class="lede" style="margin-bottom:0;"><?= e(t('rc.ap_lede', ['u' => (string)$o['appr_req_name'], 'd' => fmt_datetime((string)$o['appr_req_at'])])) ?></p></div>
  <div><?= rcr_offer_appr_expired($o) && $o['status'] === 'draft' ? pill('bad', t('rc.ap_s_expired')) : pill($o['appr_status'] === 'approved' ? 'green' : ($o['appr_status'] === 'rejected' ? 'bad' : 'gold'), t('rc.ap_s_' . $o['appr_status'])) ?></div></div>
<div class="cols" style="grid-template-columns:3fr 2fr;"><div>
<div class="card"><h2>💼 <?= tt('rc.ap_terms') ?></h2>
  <div class="table-scroll"><table class="tbl tbl--compact"><tbody><?php foreach ($rows as [$k, $v]): if ($v === '') continue; ?><tr><th style="width:200px;"><?= e($k) ?></th><td style="white-space:pre-wrap;"><?= e($v) ?></td></tr><?php endforeach; ?></tbody></table></div>
</div>
<?php if (trim((string)$o['jd_text']) !== ''): ?><details class="card card--details"><summary><h2>📄 <?= tt('rc.of_jd') ?></h2></summary><div style="white-space:pre-wrap;overflow-wrap:anywhere;"><?= e((string)$o['jd_text']) ?></div></details><?php endif; ?>
</div><div>
<div class="card" id="decide"><h2><?= tt('hr.decision') ?></h2>
<?php if ($wait && !$own): ?>
  <p class="hint"><?= tt('rc.ap_decide_hint') ?></p>
  <form method="post" action="<?= url('rcr_offer_review', ['id' => $id]) ?>" data-lock class="form-grid form-grid--1"><?= csrf_field() ?><input type="hidden" name="action" value="rcr_offer_decide"><input type="hidden" name="offer_id" value="<?= $id ?>">
    <div class="field"><label><?= tt('rc.ap_note') ?></label><textarea name="note" rows="3" maxlength="300"></textarea></div>
    <?php if (rcr_offer_valid_passed($o)): ?><p class="flash flash--bad" style="margin:0;"><?= tt('rc.ap_valid_passed') ?></p><?php elseif (!empty($u['totp_enabled'])): ?><div class="field"><label><?= tt('po.pg_totp_label') ?> — <span class="hint" style="display:inline;"><?= tt('rc.ap_totp_hint') ?></span></label><input name="totp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" style="max-width:140px;"></div><?php endif; ?>
    <div class="btn-row"><button class="btn btn--gold" type="submit" name="decision" value="approve">✔ <?= tt('hr.approve') ?></button><button class="btn btn--ghost" type="submit" name="decision" value="reject">↩ <?= tt('rc.ap_send_back') ?></button></div>
  </form>
<?php elseif ($wait): ?><p class="hint"><?= tt('rc.ap_not_own') ?></p>
<?php else: ?><p><?= e((string)$o['appr_name']) ?> · <?= e(fmt_datetime((string)$o['appr_at'])) ?></p><?php if ($o['appr_note']): ?><p class="hint"><?= e((string)$o['appr_note']) ?></p><?php endif; ?><?php if ($o['status'] !== 'draft'): ?><p class="hint"><?= tt('rc.of_st_' . $o['status']) ?></p><?php endif; ?>
<?php endif; ?>
</div>
</div></div>
<?php layout_foot();

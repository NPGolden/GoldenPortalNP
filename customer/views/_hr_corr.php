<?php
/** Correction of one leave entry (GAS-CP-R97-245). hr_corr_form($r, $types, $hrSide, $back) — a folded form in the row: type, dates, hours, reason text, note. */
function hr_corr_mark(array $r): string {
    if (empty($r['corrected_at'])) return ''; static $names = [];
    $by = (int)($r['corrected_by'] ?? 0); if ($by && !isset($names[$by])) $names[$by] = (string) scalar('SELECT full_name FROM customer_users WHERE id = ?', [$by]);
    return '<br><span class="hint">✎ ' . e(t('hr.corr_mark', ['d' => fmt_date($r['corrected_at']), 'u' => $names[$by] ?? '', 'w' => (string)($r['corrected_was'] ?? '')])) . '</span>';
}
function hr_corr_form(array $r, array $types, bool $hrSide, string $back, array $keep = []): void {
    if (!function_exists('hr_request_can_correct') || !hr_request_can_correct($r)) return; $fh = fn($v) => $v === null ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
    $cur = hr_type((int)$r['type_id']); if ($cur && !isset($types[(int)$cur['id']])) $types = [(int)$cur['id'] => $cur] + $types; ?>
<details class="hr-corr" style="margin-top:4px;"><summary class="hint" style="cursor:pointer;display:inline-block;">✎ <?= tt('hr.corr_btn') ?></summary>
  <p class="hint" style="margin:6px 0;"><?= tt($hrSide ? 'hr.corr_hint_hr' : 'hr.corr_hint_emp') ?></p>
  <form method="post" action="<?= url($back === 'hr' ? 'hr_requests' : 'my_leave') ?>" data-lock style="display:flex;gap:6px;flex-wrap:wrap;align-items:end;text-align:left;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_correct"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"><?php foreach ($keep as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string)$v) ?>"><?php endforeach; ?>
    <label class="hint"><?= tt('hr.type') ?><br><select name="type_id" data-nosearch style="max-width:200px;"><?php foreach ($types as $tid => $t): ?><option value="<?= (int)$tid ?>"<?= (int)$tid === (int)$r['type_id'] ? ' selected' : '' ?>><?= e($t['code'] . ' · ' . $t['name']) ?><?= $t['unit'] === 'hour' ? ' (h)' : '' ?></option><?php endforeach; ?></select></label>
    <label class="hint"><?= tt('pl.from') ?><br><input type="date" name="date_from" required value="<?= e((string)$r['date_from']) ?>"></label>
    <label class="hint"><?= tt('pl.to') ?><br><input type="date" name="date_to" value="<?= e((string)$r['date_to']) ?>"></label>
    <label class="hint" title="<?= tt('hr.corr_hours_hint') ?>"><?= tt('hr.hours') ?> (h)<br><input name="hours" inputmode="decimal" style="width:70px;" value="<?= e($fh($r['hours'])) ?>"></label>
    <label class="hint"><?= tt('hr.reason') ?><br><input name="reason" maxlength="300" style="width:170px;" value="<?= e((string)($r['reason'] ?? '')) ?>"></label>
    <label class="hint"><?= tt('hr.corr_note') ?><br><input name="note" maxlength="200" style="width:170px;"></label>
    <?php if ($hrSide && function_exists('hr_over_can') && hr_over_can()): ?><label class="chk hint" title="<?= tt('hr.over_balance_hint') ?>"><input type="checkbox" name="over_balance" value="1"> <?= tt('hr.over_balance') ?></label><?php endif; ?>
    <button class="btn btn--gold btn--sm" type="submit"><?= tt('hr.corr_save') ?></button>
  </form>
  <?php if ($hrSide): ?><form method="post" action="<?= url('hr_requests') ?>" data-lock data-confirm="<?= tt('hr.corr_cancel_q') ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:end;margin-top:6px;text-align:left;"><?= csrf_field() ?><input type="hidden" name="action" value="hr_req_cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="back" value="hr">
    <input name="note" maxlength="200" style="width:220px;" placeholder="<?= tt('hr.corr_note') ?>"><button class="btn btn--ghost btn--sm" type="submit">✖ <?= tt('hr.corr_cancel') ?></button></form><?php endif; ?>
</details>
<?php }

<?php
/** Reason selection for a leave request (R77-007): select filtered by the type's kind + optional text. $hrTypeSel = id of the type <select>; $cur = [code, text]. */
function hr_reason_fields(string $hrTypeSel = 'hr_type', array $cur = [null, null], bool $compact = false): void {
    if (!function_exists('hr_req_has_reasons') || !hr_req_has_reasons()) return;
    $all = []; foreach (HR_REASONS as $k => $codes) foreach ($codes as $c) $all[$c][] = $k;
    $uid = 'rs' . substr(md5($hrTypeSel . microtime()), 0, 6);
    echo '<div class="field' . ($compact ? '' : '" style="grid-column:1 / -1;') . '"><label>' . tt('hr.reason') . ' *</label><div style="display:flex;gap:6px;flex-wrap:wrap;">';
    echo '<select name="reason_code" id="' . $uid . '" data-nosearch required style="min-width:180px;"><option value="">' . tt('hr.reason_pick') . '</option>';
    foreach ($all as $c => $kinds) echo '<option value="' . e($c) . '" data-kinds="' . e(implode(' ', $kinds)) . '"' . ($cur[0] === $c ? ' selected' : '') . '>' . e(hr_reason_label($c)) . '</option>';
    echo '</select><input name="reason" maxlength="300" value="' . e((string)($cur[1] ?? '')) . '" placeholder="' . tt('hr.reason_text_ph') . '" style="flex:1;min-width:160px;"></div>' . ($compact ? '' : '<p class="hint">' . tt('hr.reason_hint') . '</p>') . '</div>';
    echo '<script nonce="' . csp_nonce() . '">(function(){var t=document.getElementById(' . json_encode($hrTypeSel) . '),r=document.getElementById(' . json_encode($uid) . ');if(!r)return;function ui(){var o=t&&t.options[t.selectedIndex],k=o?(o.dataset.kind||"leave"):"leave";var keep=r.value;Array.prototype.forEach.call(r.options,function(x){if(!x.value)return;var ok=(" "+x.dataset.kinds+" ").indexOf(" "+k+" ")>=0;x.hidden=!ok;x.disabled=!ok;});if(r.selectedOptions[0]&&r.selectedOptions[0].disabled)r.value="";}if(t)t.addEventListener("change",ui);ui();})();</script>';
}

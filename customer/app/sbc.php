<?php
/**
 * System Budgets — consistency checks.                                                   GAS-CP-R97-048
 *
 * Ten checks on one budget (sbc_run):
 *    1 arithmetic            stored totals, impossible numbers, expense mark-up, rates at zero            block
 *    2 completeness          empty / unpriced / duplicate lines, description against the lines             warn
 *    3 masters               labour rates against the rate card, part costs and their age against inventory warn
 *    4 profit rules          every line, section and group against the company minimum; below cost         block / warn
 *    5 internal ratios       project management, commissioning, engineering, travel, allowances            warn
 *    6 similar budgets       key ratios against the finished budgets of the same template / of all         info
 *    7 revision to revision  what changed since the previous revision and what it did to the profit         info
 *    8 budget to offer       the offer it became still equals the budget                                    warn
 *    9 budget to actuals     hours and costs booked on the sub-project against the budget                   warn
 *   10 AI review             scope text against the lines (on request, stored per budget)                   info
 *
 * A finding = ['c' => check, 'sev' => block|warn|info, 'code', 'msg', 'at' => anchor on the budget page].
 * "block" stops Finish and Convert until it is solved or management accepts it with a reason (sb_events kind "override", per revision).
 * Reads only; no table of its own. Thresholds in cp_settings (sbc_*).
 */
declare(strict_types=1);

const SBC_SET = ['price_tol' => 5.0, 'price_age' => 6.0, 'overrun' => 10.0, 'pm_share' => 3.0, 'ot_share' => 25.0, 'min_hours' => 40.0, 'profit_drop' => 5.0];
const SBC_SEV = ['block' => 'bad', 'warn' => 'gold', 'info' => 'mute'];

function sbc_set(string $k): float { static $c = []; if (!isset($c[$k])) { $s = function_exists('cp_setting') ? (string)(cp_setting('sbc_' . $k, '') ?? '') : ''; $c[$k] = $s !== '' && is_numeric($s) ? max(0.0, (float)$s) : (float)(SBC_SET[$k] ?? 0); } return $c[$k]; }
function sbc_f(int $c, string $sev, string $code, array $v = [], string $at = ''): array { return ['c' => $c, 'sev' => $sev, 'code' => $code, 'msg' => t('sbc.f_' . $code, $v), 'at' => $at]; }
function sbc_lbl(array $r): string { $c = trim((string)($r['code'] ?? '')); $d = trim((string)($r['descr'] ?? '')); return mb_substr($c !== '' ? $c . ($d !== '' ? ' ' . $d : '') : ($d !== '' ? $d : '—'), 0, 60); }
function sbc_m(float $v): string { return number_format($v, 2, ',', '.'); }
function sbc_p(?float $v): string { return $v === null ? '—' : number_format($v, 1, ',', '.') . ' %'; }
/** Bucket of a labour line: its own, else the bucket whose stage is the stage of its section. */
function sbc_bucket(array $l): string { $b = trim((string)($l['bucket'] ?? '')); if ($b !== '') return $b; $s = sb_sections()[(int)($l['section_id'] ?? 0)]['stage'] ?? ''; $k = array_search($s, SB_BUCKET_STAGE, true); return $k !== false ? (string)$k : ''; }
function sbc_excluded_line(array $l): bool { return (bool) preg_match('/exclud|by others|by owner|by yard|not included/i', (string)($l['descr'] ?? '') . ' ' . (string)($l['remarks'] ?? '')); }
/** Several findings of one kind: the first $max as they are, the rest as one "+ n more". */
function sbc_cap(array $list, int $c, int $max = 6): array { if (count($list) <= $max) return $list; $rest = count($list) - $max; $out = array_slice($list, 0, $max); $out[] = sbc_f($c, $list[$max]['sev'], 'more', ['n' => $rest], $list[$max]['at']); return $out; }

/* ------------------------------------------------------------------ 1 arithmetic */
function sbc_c1(array $B, array $T): array {
    $F = [];
    if (empty($B['_rev']) && isset($B['tot_cost'])) foreach ([['tot_hours', $T['hours'], 'hours'], ['tot_cost', $T['cost'], 'cost'], ['tot_sale', $T['sale'], 'sale']] as [$col, $calc, $nm]) if (abs((float)$B[$col] - (float)$calc) > 0.011) $F[] = sbc_f(1, 'block', 'stored', ['w' => t('sbc.w_' . $nm), 's' => sbc_m((float)$B[$col]), 'c' => sbc_m((float)$calc)], 'totals');
    $hDisc = 0.0; $hAny = 0.0; $bad = [];
    foreach ($B['labour'] as $l) {
        $c = sb_labour_calc($B, $l); $h = $c['hours']; $hAny += $h; if (trim((string)($l['card'] ?? '')) === '') $hDisc += $h;
        foreach (['h_normal', 'h_travel', 'h_overtime', 'allow_days', 'rate'] as $k) if ((float)($l[$k] ?? 0) < 0) { $bad[] = sbc_f(1, 'block', 'neg', ['l' => sbc_lbl($l)], 'labour'); break; }
        $d = $l['discount_pct'] ?? null; if ($d !== null && $d !== '' && ((float)$d < 0 || (float)$d > 100)) $bad[] = sbc_f(1, 'block', 'disc_range', ['l' => sbc_lbl($l)], 'labour');
        if ($h > 0 && !$c['flat'] && $c['rate'] <= 0) $bad[] = sbc_f(1, 'block', 'lab_norate', ['l' => sbc_lbl($l)], 'labour');
    }
    $F = array_merge($F, sbc_cap($bad, 1));
    if ((int)$B['flat_on'] === 1 && $hDisc > 0 && (float)$B['flat_rate'] <= 0) $F[] = sbc_f(1, 'block', 'flat0', [], 'head');
    if ($hAny > 0 && (float)$B['cost_rate'] <= 0) $F[] = sbc_f(1, 'block', 'cost_rate0', [], 'head');
    if ($T['days'] > 0 && (float)$B['allow_rate'] <= 0) $F[] = sbc_f(1, 'warn', 'allow0', ['d' => sb_h($T['days'])], 'head');
    $bad = [];
    foreach ($B['parts'] as $p) {
        if ((float)$p['qty'] < 0 || (float)$p['unit_cost'] < 0 || (($p['margin_pct'] ?? null) !== null && $p['margin_pct'] !== '' && (float)$p['margin_pct'] < 0)) $bad[] = sbc_f(1, 'block', 'neg', ['l' => sbc_lbl($p)], 'parts');
        $d = $p['discount_pct'] ?? null; if ($d !== null && $d !== '' && ((float)$d < 0 || (float)$d > 100)) $bad[] = sbc_f(1, 'block', 'disc_range', ['l' => sbc_lbl($p)], 'parts');
        $m = sb_part_calc($B, $p)['margin']; if ($m > 300) $bad[] = sbc_f(1, 'warn', 'margin_high', ['l' => sbc_lbl($p), 'm' => sbc_p($m)], 'parts');
    }
    $F = array_merge($F, sbc_cap($bad, 1));
    if (count($B['parts']) > SB_MAX_PARTS) $F[] = sbc_f(1, 'block', 'parts_max', ['n' => count($B['parts']), 'm' => SB_MAX_PARTS], 'parts');
    $bad = [];
    foreach ($B['expenses'] as $x) {
        if ((float)$x['cost'] < 0 || (float)$x['sale'] < 0) $bad[] = sbc_f(1, 'block', 'neg', ['l' => sbc_lbl($x)], 'expenses');
        $mk = $x['markup_pct'] ?? null; if ($mk !== null && $mk !== '' && abs(round((float)$x['cost'] * (1 + (float)$mk / 100), 2) - (float)$x['sale']) > 0.011) $bad[] = sbc_f(1, 'block', 'exp_markup', ['l' => sbc_lbl($x), 's' => sbc_m((float)$x['sale']), 'c' => sbc_m(round((float)$x['cost'] * (1 + (float)$mk / 100), 2))], 'expenses');
    }
    $F = array_merge($F, sbc_cap($bad, 1));
    $c = (float)$B['commission_pct']; if ($c < 0 || $c >= 100) $F[] = sbc_f(1, 'block', 'commission', [], 'head');
    return $F;
}

/* ------------------------------------------------------------------ 2 completeness */
function sbc_c2(array $B, array $T): array {
    $F = []; $tpl = !empty($B['is_template']);
    if (!$tpl && (!(int)$B['project_id'] || !(int)$B['subproject_id'])) $F[] = sbc_f(2, 'warn', 'no_sub', [], 'head');
    if (!$B['labour'] && !$B['parts']) { $F[] = sbc_f(2, 'warn', 'no_lines'); return $F; }
    if (!$B['labour']) $F[] = sbc_f(2, 'warn', 'no_labour', [], 'labour');
    if (!$B['parts']) $F[] = sbc_f(2, 'info', 'no_parts', [], 'parts');
    $z = 0; $ns = 0; foreach ($B['labour'] as $l) { $h = (float)$l['h_normal'] + (float)$l['h_travel'] + (float)$l['h_overtime']; if ($h <= 0 && (float)$l['allow_days'] <= 0) { if (!sbc_excluded_line($l)) $z++; continue; } if (!(int)($l['section_id'] ?? 0) && sbc_bucket($l) === '') $ns++; }
    if ($z) $F[] = sbc_f(2, 'info', 'lab_zero', ['n' => $z], 'labour');
    if ($ns) $F[] = sbc_f(2, 'warn', 'lab_nosec', ['n' => $ns], 'labour');
    if (function_exists('sb_feed_plan')) { $un = sb_feed_plan($B)['unmapped']; if ($un) $F[] = sbc_f(2, 'warn', 'lab_nodisc', ['n' => count($un), 'h' => sb_h(array_sum(array_column($un, 'hours')))], 'labour'); }
    if ($T['unpriced'] > 0) $F[] = sbc_f(2, 'block', 'unpriced', ['n' => $T['unpriced']], 'parts');
    $q0 = 0; $nd = 0; $seen = []; $dup = [];
    foreach ($B['parts'] as $p) { if ((float)$p['qty'] <= 0 && !sb_part_excluded($p)) $q0++; if (trim((string)$p['descr']) === '') $nd++; $c = mb_strtolower(trim((string)($p['code'] ?? ''))); if ($c !== '' && !sb_part_excluded($p)) { if (isset($seen[$c])) $dup[$c] = (string)$p['code']; $seen[$c] = 1; } }
    if ($q0) $F[] = sbc_f(2, 'info', 'part_qty0', ['n' => $q0], 'parts');
    if ($nd) $F[] = sbc_f(2, 'warn', 'part_nodescr', ['n' => $nd], 'parts');
    if ($dup) $F[] = sbc_f(2, 'warn', 'part_dup', ['n' => count($dup), 'l' => mb_substr(implode(', ', array_slice(array_values($dup), 0, 6)), 0, 120)], 'parts');
    $txt = function_exists('rt_plain') ? rt_plain((string)($B['description'] ?? '')) : strip_tags((string)($B['description'] ?? ''));
    if (trim($txt) === '') $F[] = sbc_f(2, 'warn', 'descr_empty', [], 'descr');
    else {   // a work package named in the description with no hours behind it
        $have = []; foreach ($B['labour'] as $l) if ((float)$l['h_normal'] + (float)$l['h_travel'] + (float)$l['h_overtime'] > 0) { $have[mb_strtolower(sbc_bucket($l))] = 1; $sn = sb_sections()[(int)($l['section_id'] ?? 0)]['name'] ?? ''; if ($sn !== '') $have[mb_strtolower((string)$sn)] = 1; }
        $names = SB_BUCKETS; foreach (sb_sections() as $s) $names[] = (string)$s['name']; $miss = [];
        foreach (array_unique($names) as $n) { $k = mb_strtolower($n); if ($k === 'pm' || $k === 'site' || mb_strlen($k) < 5 || isset($have[$k])) continue; if (preg_match('/(?<![\p{L}])' . preg_quote($k, '/') . '(?![\p{L}])/iu', $txt)) $miss[] = $n; }
        if ($miss && $B['labour']) $F[] = sbc_f(2, 'info', 'descr_sec', ['l' => mb_substr(implode(', ', $miss), 0, 160)], 'descr');
    }
    if (!$tpl && function_exists('sbp_has') && sbp_has() && empty($B['_rev'])) {   // R97-049 procurement step
        $st = sbp_state($B); $open = count(sbp_open_lines($B)); $cnt = array_filter($B['parts'], 'sbp_counts');
        if ($st === 'requested') $F[] = sbc_f(2, 'warn', 'proc_wait', [], 'procurement');
        elseif ($open && sbp_required()) $F[] = sbc_f(2, 'warn', $st === 'done' ? 'proc_new' : 'proc_send', ['n' => $open], 'parts');
        if ($st === 'done') { $nl = count(array_filter($cnt, fn($p) => !empty($p['proc_at']) && (($p['lead_weeks'] ?? null) === null || (float)$p['lead_weeks'] <= 0))); if ($nl) $F[] = sbc_f(2, 'info', 'no_lead', ['n' => $nl], 'procurement'); }
        $df = []; foreach ($cnt as $p) if (($p['proc_cost'] ?? null) !== null && abs((float)$p['proc_cost'] - (float)$p['unit_cost']) > 0.0049) $df[] = sbc_lbl($p); if ($df) $F[] = sbc_f(2, 'warn', 'proc_cost_diff', ['n' => count($df), 'l' => mb_substr(implode(', ', array_slice($df, 0, 5)), 0, 200)], 'parts');
    }
    if (!$tpl && empty($B['target_date'])) $F[] = sbc_f(2, 'info', 'no_target', [], 'head');
    if (!$tpl && !(int)($B['owner_user_id'] ?? 0)) $F[] = sbc_f(2, 'info', 'no_owner', [], 'head');
    return $F;
}

/* ------------------------------------------------------------------ 3 masters */
function sbc_c3(array $B): array {
    $F = []; $cards = sb_cards((string)$B['zone']); $bad = [];
    foreach ($B['labour'] as $l) { $c = trim((string)($l['card'] ?? '')); if ($c === '' || (int)$B['flat_on'] === 1 && $c === '') continue; if (!isset($cards[$c])) { if ((float)$l['h_normal'] + (float)$l['h_overtime'] + (float)$l['h_travel'] > 0) $bad[] = sbc_f(3, 'warn', 'card_gone', ['l' => sbc_lbl($l), 'k' => $c], 'labour'); continue; }
        if (abs((float)$l['rate'] - (float)$cards[$c]['normal']) >= 0.005) $bad[] = sbc_f(3, 'warn', 'rate_drift', ['l' => sbc_lbl($l), 'r' => sbc_m((float)$l['rate']), 'm' => sbc_m((float)$cards[$c]['normal'])], 'labour');
        elseif ($cards[$c]['overtime'] !== null && ($l['rate_ot'] ?? null) !== null && (float)$l['rate_ot'] > 0 && abs((float)$l['rate_ot'] - (float)$cards[$c]['overtime']) >= 0.005) $bad[] = sbc_f(3, 'warn', 'rate_drift_ot', ['l' => sbc_lbl($l), 'r' => sbc_m((float)$l['rate_ot']), 'm' => sbc_m((float)$cards[$c]['overtime'])], 'labour'); }
    $F = array_merge($F, sbc_cap($bad, 3));
    $ids = array_values(array_unique(array_filter(array_map(fn($p) => (int)($p['item_id'] ?? 0), $B['parts'])))); $it = []; $lo = [];
    if ($ids) { try { $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (all("SELECT id, code, status, last_price, last_price_at, avg_cost FROM inv_items WHERE id IN ($in)", $ids) as $r) $it[(int)$r['id']] = $r;
        foreach (all("SELECT item_id, MIN(unit_price) AS lo, MAX(priced_at) AS last_at FROM inv_prices WHERE item_id IN ($in) AND source <> 'rollup' AND unit_price > 0 GROUP BY item_id", $ids) as $r) $lo[(int)$r['item_id']] = $r; } catch (Throwable $e) {} }
    $tol = sbc_set('price_tol'); $ageM = sbc_set('price_age'); $minTs = $ageM > 0 ? strtotime('-' . (int) round($ageM * 30.4) . ' days') : 0; $dif = []; $old = []; $none = 0; $ina = [];
    foreach ($B['parts'] as $p) { $iid = (int)($p['item_id'] ?? 0); if (!$iid || !isset($it[$iid]) || sb_part_excluded($p) || (float)$p['qty'] <= 0) continue; $i = $it[$iid];
        if ((string)$i['status'] !== 'active') $ina[] = (string)$i['code'];
        $master = isset($lo[$iid]) ? (float)$lo[$iid]['lo'] : ((float)$i['last_price'] > 0 ? (float)$i['last_price'] : (float)$i['avg_cost']); $uc = (float)$p['unit_cost'];
        if ($master <= 0) { $none++; continue; }
        if (!empty($p['proc_at']) && ($minTs === 0 || strtotime((string)$p['proc_at']) >= $minTs)) continue;   // R97-049: freshly priced by procurement — the inventory figure is the older one
        if ($uc > 0 && $tol > 0 && abs($uc - $master) / $master * 100 > $tol) $dif[] = sbc_f(3, 'warn', $uc < $master ? 'price_under' : 'price_over', ['l' => sbc_lbl($p), 'b' => sbc_m($uc), 'm' => sbc_m($master), 'p' => number_format(abs($uc - $master) / $master * 100, 0)], 'parts');
        $last = max((int) strtotime((string)($lo[$iid]['last_at'] ?? '')), (int) strtotime((string)($i['last_price_at'] ?? ''))); if ($minTs && $last > 0 && $last < $minTs) $old[] = (string)$i['code'] . ' (' . date('m/Y', $last) . ')'; }
    usort($dif, fn($a, $b) => strcmp($a['code'], $b['code'])); $F = array_merge($F, sbc_cap($dif, 3, 8));
    if ($old) $F[] = sbc_f(3, 'warn', 'price_old', ['n' => count($old), 'm' => rtrim(rtrim(number_format($ageM, 1, ',', ''), '0'), ','), 'l' => mb_substr(implode(', ', array_slice($old, 0, 8)), 0, 200)], 'parts');
    if ($none) $F[] = sbc_f(3, 'info', 'price_none', ['n' => $none], 'parts');
    if ($ina) $F[] = sbc_f(3, 'warn', 'item_inactive', ['l' => mb_substr(implode(', ', array_slice(array_unique($ina), 0, 8)), 0, 160)], 'parts');
    $free = []; foreach ($B['parts'] as $p) if (!(int)($p['item_id'] ?? 0) && trim((string)($p['code'] ?? '')) !== '' && !sb_part_excluded($p)) $free[] = trim((string)$p['code']);
    if ($free) { try { $free = array_slice(array_values(array_unique($free)), 0, 150); $hit = array_column(all('SELECT code FROM inv_items WHERE code IN (' . implode(',', array_fill(0, count($free), '?')) . ')', $free), 'code'); if ($hit) $F[] = sbc_f(3, 'info', 'free_match', ['n' => count($hit), 'l' => mb_substr(implode(', ', array_slice($hit, 0, 8)), 0, 160)], 'parts'); } catch (Throwable $e) {} }
    return $F;
}

/* ------------------------------------------------------------------ 4 profit rules */
function sbc_c4(array $B, array $T): array {
    $F = []; $below = []; $low = []; $sec = []; $min = rtrim(rtrim(number_format(sb_min_profit(), 1, ',', ''), '0'), ',');
    foreach ($B['labour'] as $l) { $c = sb_labour_calc($B, $l); if ($c['hours'] <= 0 && $c['cost'] <= 0) continue; $k = (int)($l['section_id'] ?? 0); $sec[$k] = $sec[$k] ?? [0.0, 0.0]; $sec[$k][0] += $c['cost']; $sec[$k][1] += $c['sale'];
        if ($c['sale'] < $c['cost'] - 0.005) $below[] = sbc_f(4, 'block', $c['disc'] > 0 && $c['gross'] >= $c['cost'] ? 'disc_below_cost' : 'below_cost', ['l' => sbc_lbl($l), 'c' => sbc_m($c['cost']), 's' => sbc_m($c['sale']), 'd' => sbc_p($c['disc'])], 'labour');
        elseif (sb_low($c['cost'], $c['sale'])) $low[] = sbc_f(4, 'warn', 'line_low', ['l' => sbc_lbl($l), 'p' => sbc_p(sb_profit_pct($c['cost'], $c['sale'])), 'm' => $min], 'labour'); }
    foreach ($B['parts'] as $p) { $c = sb_part_calc($B, $p); if ($c['cost'] <= 0) continue;
        if ($c['sale'] < $c['cost'] - 0.005) $below[] = sbc_f(4, 'block', $c['disc'] > 0 && $c['gross'] >= $c['cost'] ? 'disc_below_cost' : 'below_cost', ['l' => sbc_lbl($p), 'c' => sbc_m($c['cost']), 's' => sbc_m($c['sale']), 'd' => sbc_p($c['disc'])], 'parts');
        elseif (sb_low($c['cost'], $c['sale'])) $low[] = sbc_f(4, 'warn', 'line_low', ['l' => sbc_lbl($p), 'p' => sbc_p(sb_profit_pct($c['cost'], $c['sale'])), 'm' => $min], 'parts'); }
    $F = array_merge($F, sbc_cap($below, 4, 8), sbc_cap($low, 4, 6));
    $xb = 0; foreach ($B['expenses'] as $x) if ((int)$x['in_total'] && (float)$x['sale'] < (float)$x['cost'] - 0.005) $xb++; if ($xb) $F[] = sbc_f(4, 'warn', 'exp_below', ['n' => $xb], 'expenses');
    $names = sb_sections(); foreach ($sec as $k => [$c, $s]) if ($k && count($sec) > 1 && sb_low($c, $s)) $F[] = sbc_f(4, 'warn', 'grp_low', ['g' => (string)($names[$k]['name'] ?? '#' . $k), 'p' => sbc_p(sb_profit_pct($c, $s)), 'm' => $min], 'labour');
    foreach ([['sbc.w_labour', $T['lab_cost'], $T['lab_sale'], 'labour'], ['sbc.w_parts', $T['parts_cost'], $T['parts_sale'], 'parts']] as [$nm, $c, $s, $at]) if (sb_low((float)$c, (float)$s)) $F[] = sbc_f(4, 'warn', 'grp_low', ['g' => t($nm), 'p' => sbc_p(sb_profit_pct((float)$c, (float)$s)), 'm' => $min], $at);
    if ($T['cost'] > 0.005 && $T['sale'] < $T['cost'] - 0.005) $F[] = sbc_f(4, 'block', 'total_below_cost', ['c' => sbc_m($T['cost']), 's' => sbc_m($T['sale'])], 'totals');
    elseif (sb_low($T['cost'], $T['sale'])) $F[] = sbc_f(4, 'warn', 'total_low', ['p' => sbc_p(sb_profit_pct($T['cost'], $T['sale'])), 'm' => $min], 'totals');
    return $F;
}

/* ------------------------------------------------------------------ 5 internal ratios */
function sbc_buckets(array $B): array { $h = array_fill_keys(SB_BUCKETS, 0.0); $h[''] = 0.0; foreach ($B['labour'] as $l) { $x = (float)$l['h_normal'] + (float)$l['h_travel'] + (float)$l['h_overtime']; if ($x <= 0) continue; $b = sbc_bucket($l); $h[isset($h[$b]) ? $b : ''] += $x; } return $h; }
function sbc_c5(array $B, array $T): array {
    $F = []; if ($T['hours'] < sbc_set('min_hours')) return $F; $h = sbc_buckets($B); $tot = $T['hours'];
    if ($h[''] > 0.005) $F[] = sbc_f(5, 'info', 'no_bucket', ['h' => sb_h($h['']), 'p' => number_format($h[''] / $tot * 100, 0)], 'labour');
    $pm = $h['PM'] / $tot * 100; if (sbc_set('pm_share') > 0 && $pm < sbc_set('pm_share') && $h[''] < $tot * 0.5) $F[] = sbc_f(5, 'warn', 'pm_low', ['p' => number_format($pm, 1, ',', ''), 'm' => rtrim(rtrim(number_format(sbc_set('pm_share'), 1, ',', ''), '0'), ',')], 'labour');
    if ($h['Commissioning'] <= 0 && $h['Site'] + $h['Workshop'] > 0) $F[] = sbc_f(5, 'warn', 'no_comm', [], 'labour');
    if ($h['Engineering'] <= 0 && ($h['Workshop'] > 0 || $T['parts_cost'] > 0)) $F[] = sbc_f(5, 'warn', 'no_eng', [], 'labour');
    $ot = $T['h_overtime'] / $tot * 100; if (sbc_set('ot_share') > 0 && $ot > sbc_set('ot_share')) $F[] = sbc_f(5, 'info', 'ot_high', ['p' => number_format($ot, 0)], 'labour');
    $kinds = array_column($B['expenses'], 'kind');
    if ((string)$B['zone'] === 'overseas') { if ($T['h_travel'] <= 0 && !in_array('travel', $kinds, true)) $F[] = sbc_f(5, 'warn', 'no_travel', [], 'expenses'); if ($T['days'] <= 0 && $h['Site'] + $h['Commissioning'] > 0) $F[] = sbc_f(5, 'warn', 'no_allow', [], 'labour'); }
    $all = mb_strtolower(implode(' | ', array_map(fn($l) => (string)$l['descr'] . ' ' . (string)($l['code'] ?? ''), array_filter($B['labour'], fn($l) => (float)$l['h_normal'] + (float)$l['h_overtime'] > 0))));
    if ($h['Workshop'] > 0 && !preg_match('/\bfat\b|factory accept|test/iu', $all)) $F[] = sbc_f(5, 'info', 'no_fat', [], 'labour');
    if (!preg_match('/document|manual|as.?built|drawing|schematic/iu', $all)) $F[] = sbc_f(5, 'info', 'no_docs', [], 'labour');
    if ($T['parts_cost'] > 0 && !in_array('logistics', $kinds, true)) $F[] = sbc_f(5, 'info', 'no_logistics', [], 'expenses');
    return $F;
}

/* ------------------------------------------------------------------ 6 similar budgets */
function sbc_metrics(array $B, array $T): array {
    $h = sbc_buckets($B);
    return ['sale_h' => $T['hours'] > 0 ? $T['lab_sale'] / $T['hours'] : null, 'mat_lab' => $T['lab_cost'] > 0 ? $T['parts_cost'] / $T['lab_cost'] * 100 : null, 'profit' => sb_profit_pct($T['cost'], $T['sale']),
            'eng' => $T['hours'] > 0 && $h[''] < $T['hours'] * 0.5 ? $h['Engineering'] / $T['hours'] * 100 : null, 'exp' => $T['cost'] > 0 ? $T['exp_cost'] / $T['cost'] * 100 : null];
}
/** Metrics of the finished and converted budgets (four statements, kept 15 minutes): [id => ['tpl' => copied_from, 'm' => metrics]]. */
function sbc_population(): array {
    return cache_remember('sbc_pop', 900, function () {
        $o = []; try {
            $heads = []; foreach (all("SELECT * FROM sb_budgets WHERE status IN ('finished','converted') AND tot_cost > 0" . (function_exists('sb_r2') && sb_r2() ? ' AND is_template = 0' : '') . ' ORDER BY id DESC LIMIT 400') as $b) { $b['labour'] = []; $b['parts'] = []; $b['expenses'] = []; $heads[(int)$b['id']] = $b; }
            if (!$heads) return []; $in = implode(',', array_map('intval', array_keys($heads)));
            foreach (['labour' => 'sb_labour', 'parts' => 'sb_parts', 'expenses' => 'sb_expenses'] as $k => $tb) foreach (all("SELECT * FROM $tb WHERE budget_id IN ($in)") as $r) $heads[(int)$r['budget_id']][$k][] = $r;
            foreach ($heads as $id => $b) $o[$id] = ['tpl' => (int)($b['copied_from'] ?? 0), 'm' => sbc_metrics($b, sb_totals($b))];
        } catch (Throwable $e) {}
        return $o;
    });
}
function sbc_pctl(array $v, float $p): float { sort($v); $n = count($v); return (float)$v[(int) min($n - 1, max(0, round($p * ($n - 1))))]; }
function sbc_c6(array $B, array $T, array &$extra): array {
    $F = []; if (!empty($B['is_template']) || $T['cost'] <= 0) return $F; $pop = sbc_population(); unset($pop[(int)$B['id']]); $tpl = (int)($B['copied_from'] ?? 0);
    $same = $tpl ? array_filter($pop, fn($x) => $x['tpl'] === $tpl) : []; $use = count($same) >= 5 ? $same : $pop; $scope = count($same) >= 5 ? 'tpl' : 'all';
    if (count($use) < 5) { $F[] = sbc_f(6, 'info', 'peer_few', ['n' => count($use)]); return $F; }
    $mine = sbc_metrics($B, $T); $rows = [];
    foreach ($mine as $k => $v) { $vals = array_values(array_filter(array_map(fn($x) => $x['m'][$k], $use), fn($x) => $x !== null)); if ($v === null || count($vals) < 5) continue; $lo = sbc_pctl($vals, 0.1); $hi = sbc_pctl($vals, 0.9); $md = sbc_pctl($vals, 0.5); $out = $v < $lo - 0.005 ? 'low' : ($v > $hi + 0.005 ? 'high' : '');
        $fmt = fn(float $x) => $k === 'sale_h' ? sbc_m($x) : sbc_p($x); $rows[] = ['k' => $k, 'v' => $fmt($v), 'md' => $fmt($md), 'lo' => $fmt($lo), 'hi' => $fmt($hi), 'out' => $out, 'n' => count($vals)];
        if ($out !== '') $F[] = sbc_f(6, 'info', 'peer_' . $out, ['w' => t('sbc.k_' . $k), 'v' => $fmt($v), 'lo' => $fmt($lo), 'hi' => $fmt($hi), 'md' => $fmt($md), 'n' => count($vals)]); }
    $extra['peer'] = ['rows' => $rows, 'scope' => $scope, 'n' => count($use)];
    return $F;
}

/* ------------------------------------------------------------------ 7 revision to revision */
/** Lines of two versions matched by code + description; returns the rows that differ with their effect on cost and sale. */
function sbc_diff(array $A, array $B): array {
    $rows = [];
    $idx = function (array $list, callable $key) { $o = []; $n = []; foreach ($list as $r) { $k = $key($r); $n[$k] = ($n[$k] ?? 0) + 1; $o[$k . '#' . $n[$k]] = $r; } return $o; };
    $kL = fn($r) => mb_strtolower(trim((string)($r['code'] ?? '')) . '|' . trim((string)($r['descr'] ?? ''))); $kX = fn($r) => mb_strtolower((string)($r['kind'] ?? '') . '|' . trim((string)($r['descr'] ?? '')));
    $do = function (string $type, array $a, array $b, callable $calc, array $watch) use (&$rows) {
        foreach ($b as $k => $r) { $n = $calc($r); if (!isset($a[$k])) { if (abs($n[0]) + abs($n[1]) > 0.005) $rows[] = ['t' => $type, 'l' => sbc_lbl($r), 'ch' => 'added', 'what' => '', 'dc' => $n[0], 'ds' => $n[1]]; continue; }
            $o = $calc($a[$k]); $w = []; foreach ($watch as $f => $lab) { $x = (string)($a[$k][$f] ?? ''); $y = (string)($r[$f] ?? ''); if ((is_numeric($x) || $x === '') && (is_numeric($y) || $y === '') ? abs((float)$x - (float)$y) > 0.0049 : $x !== $y) $w[] = $lab . ' ' . ($x === '' ? '—' : rtrim(rtrim(number_format((float)$x, 2, ',', '.'), '0'), ',')) . ' → ' . ($y === '' ? '—' : rtrim(rtrim(number_format((float)$y, 2, ',', '.'), '0'), ',')); }
            if ($w || abs($n[0] - $o[0]) > 0.005 || abs($n[1] - $o[1]) > 0.005) $rows[] = ['t' => $type, 'l' => sbc_lbl($r), 'ch' => 'changed', 'what' => implode(' · ', $w), 'dc' => $n[0] - $o[0], 'ds' => $n[1] - $o[1]]; }
        foreach ($a as $k => $r) if (!isset($b[$k])) { $o = $calc($r); if (abs($o[0]) + abs($o[1]) > 0.005) $rows[] = ['t' => $type, 'l' => sbc_lbl($r), 'ch' => 'removed', 'what' => '', 'dc' => -$o[0], 'ds' => -$o[1]]; }
    };
    // each side is priced with its own header settings (rates, margin), so a change of the header shows on every line it touches
    $do('labour', $idx($A['labour'], $kL), $idx($B['labour'], $kL), fn($r) => (function ($c) { return [$c['cost'], $c['sale']]; })(sb_labour_calc(isset($r['__h']) ? $r['__h'] : $B, $r)), ['h_normal' => t('sbc.d_hn'), 'h_travel' => t('sbc.d_ht'), 'h_overtime' => t('sbc.d_ho'), 'allow_days' => t('sbc.d_days'), 'rate' => t('sbc.d_rate'), 'discount_pct' => t('sbc.d_disc')]);
    $do('parts', $idx($A['parts'], $kL), $idx($B['parts'], $kL), fn($r) => (function ($c) { return [$c['cost'], $c['sale']]; })(sb_part_calc(isset($r['__h']) ? $r['__h'] : $B, $r)), ['qty' => t('sbc.d_qty'), 'unit_cost' => t('sbc.d_cost'), 'margin_pct' => t('sbc.d_margin'), 'discount_pct' => t('sbc.d_disc')]);
    $do('expenses', $idx($A['expenses'], $kX), $idx($B['expenses'], $kX), fn($r) => (int)($r['in_total'] ?? 1) ? [(float)$r['cost'], (float)$r['sale']] : [0.0, 0.0], ['cost' => t('sbc.d_cost'), 'sale' => t('sbc.d_sale')]);
    return $rows;
}
function sbc_c7(array $B, array $T, array &$extra): array {
    $F = []; if (!function_exists('sb_r2') || !sb_r2() || !empty($B['is_template'])) return $F; $cur = (int)$B['revision'];
    try { $prevNo = scalar('SELECT MAX(revision) FROM sb_revisions WHERE budget_id = ? AND revision < ?', [(int)$B['id'], $cur]); } catch (Throwable $e) { $prevNo = null; }
    if ($prevNo === null || $prevNo === false) return $F; $P = sb_revision_get($B, (int)$prevNo); if (!$P) return $F;
    $hd = array_intersect_key($P, array_flip(SB_SNAP_HEAD)) + $P; foreach (['labour', 'parts'] as $k) foreach ($P[$k] as &$r) { $r['__h'] = $hd; } unset($r);   // the previous lines keep the previous header for their price
    $TP = sb_totals($P); $rows = sbc_diff($P, $B); $n = ['added' => 0, 'removed' => 0, 'changed' => 0]; foreach ($rows as $r) $n[$r['ch']]++;
    $dc = $T['cost'] - $TP['cost']; $ds = $T['sale'] - $TP['sale']; $pp = sb_profit_pct($TP['cost'], $TP['sale']); $pn = sb_profit_pct($T['cost'], $T['sale']);
    $heads = []; foreach (['zone', 'parts_margin', 'flat_on', 'flat_rate', 'cost_rate', 'allow_rate', 'allow_cost', 'commission_pct', 'currency'] as $k) if ((string)($P[$k] ?? '') !== (string)($B[$k] ?? '') && !(is_numeric($P[$k] ?? null) && is_numeric($B[$k] ?? null) && abs((float)$P[$k] - (float)$B[$k]) < 0.005)) $heads[] = t('sbc.h_' . $k) . ' ' . (string)($P[$k] ?? '—') . ' → ' . (string)($B[$k] ?? '—');
    $extra['diff'] = ['prev' => (int)$prevNo, 'cur' => $cur, 'rows' => $rows, 'dc' => $dc, 'ds' => $ds, 'pp' => $pp, 'pn' => $pn, 'heads' => $heads, 'cost0' => $TP['cost'], 'sale0' => $TP['sale']];
    if (!$rows && !$heads) { $F[] = sbc_f(7, 'info', 'rev_same', ['r' => 'R' . (int)$prevNo], 'revisions'); return $F; }
    $F[] = sbc_f(7, 'info', 'rev_sum', ['r' => 'R' . (int)$prevNo, 'a' => $n['added'], 'x' => $n['removed'], 'c' => $n['changed'], 'dc' => ($dc >= 0 ? '+' : '') . sbc_m($dc), 'ds' => ($ds >= 0 ? '+' : '') . sbc_m($ds), 'pp' => sbc_p($pp), 'pn' => sbc_p($pn)], 'revisions');
    if ($heads) $F[] = sbc_f(7, 'info', 'rev_head', ['l' => mb_substr(implode(' · ', $heads), 0, 240)], 'head');
    if ($pp !== null && $pn !== null && $pp - $pn > sbc_set('profit_drop')) $F[] = sbc_f(7, 'warn', 'rev_profit_drop', ['pp' => sbc_p($pp), 'pn' => sbc_p($pn), 'r' => 'R' . (int)$prevNo], 'totals');
    return $F;
}

/* ------------------------------------------------------------------ 8 budget to offer */
function sbc_c8(array $B, array $T): array {
    $F = []; if (!empty($B['is_template']) || !empty($B['_rev'])) return $F;
    if ($B['status'] === 'finished' && !empty($B['finished_at'])) { $d = (int) floor(tz_age((string)$B['finished_at']) / 86400); if ($d > 30) $F[] = sbc_f(8, 'info', 'finished_idle', ['d' => $d]); }
    if ($B['status'] !== 'converted') return $F; $oid = (int)($B['offer_id'] ?? 0); $o = null;
    try { $o = $oid ? one('SELECT id, offer_no, revision, status, amount, updated_at FROM offers WHERE id = ?', [$oid]) : null; } catch (Throwable $e) {}
    if (!$o) { $F[] = sbc_f(8, 'warn', 'offer_gone'); return $F; }
    try { $net = (float) scalar("SELECT COALESCE(SUM(line_total), 0) FROM offer_lines WHERE offer_id = ? AND kind <> 'text'", [$oid]); } catch (Throwable $e) { $net = (float)$o['amount']; }
    $no = $o['offer_no'] . ' ' . $o['revision']; $d1 = abs($net - $T['sale']); $d2 = abs($net - $T['with_commission']); $d = min($d1, $d2); $tol = max(1.0, $T['sale'] * 0.001);
    if ($d > $tol) $F[] = sbc_f(8, 'warn', 'offer_diff', ['o' => $no, 'on' => sbc_m($net), 'b' => sbc_m($d1 <= $d2 ? $T['sale'] : $T['with_commission']), 'd' => ($net - ($d1 <= $d2 ? $T['sale'] : $T['with_commission']) >= 0 ? '+' : '') . sbc_m($net - ($d1 <= $d2 ? $T['sale'] : $T['with_commission']))]);
    elseif (!empty($B['converted_at']) && !empty($o['updated_at']) && strtotime((string)$o['updated_at']) - strtotime((string)$B['converted_at']) > 300) $F[] = sbc_f(8, 'info', 'offer_edited', ['o' => $no]);
    if (preg_match('/^R(\d+)$/', (string)$o['revision'], $m) && (int)$m[1] > 0) $F[] = sbc_f(8, $d > $tol ? 'warn' : 'info', 'offer_rev', ['o' => $no]);
    if (in_array((string)$o['status'], ['lost', 'cancelled', 'rejected', 'declined'], true)) $F[] = sbc_f(8, 'info', 'offer_lost', ['o' => $no, 's' => (string)$o['status']]);
    $sid = (int)($B['subproject_id'] ?? 0);
    if ($sid && !empty($B['budget_rev_id']) && function_exists('bg_current')) { try { $cb = bg_current($sid); if ($cb && (int)$cb['id'] !== (int)$B['budget_rev_id']) $F[] = sbc_f(8, 'info', 'bg_changed'); } catch (Throwable $e) {} }
    if ($sid && !empty($B['budget_rev_id']) && function_exists('sb_feed_state') && sb_fed_col()) { $fs = sb_feed_state($B); if ($fs['rest_hours'] > 0.005) $F[] = sbc_f(8, 'warn', 'feed_rest', ['h' => sb_h($fs['rest_hours']), 'n' => count($fs['rest'])], 'labour'); }
    return $F;
}

/* ------------------------------------------------------------------ 9 budget to actuals */
function sbc_c9(array $B, array $T, array &$extra): array {
    $F = []; $sid = (int)($B['subproject_id'] ?? 0); if (!$sid || $B['status'] !== 'converted' || !empty($B['_rev']) || !function_exists('bg_calc') || !function_exists('bg_has') || !bg_has()) return $F;
    try { $C = bg_calc($sid); } catch (Throwable $e) { return $F; }
    if (empty($C['has_budget'])) return $F;
    try { $nSb = (int) scalar("SELECT COUNT(*) FROM sb_budgets WHERE subproject_id = ? AND status = 'converted'", [$sid]); } catch (Throwable $e) { $nSb = 1; }
    $extra['actual'] = ['rows' => $C['rows'], 'budget_h' => $C['budget_h'], 'actual_h' => $C['actual_h'], 'parts_budget' => $C['parts_budget'], 'parts_committed' => $C['parts_committed'], 'forecast' => $C['forecast_cost'], 'budget_cost' => $C['budget_cost'] + $C['parts_budget'], 'progress' => $C['progress'], 'shared' => $nSb, 'sid' => $sid];
    if ($C['actual_h'] <= 0 && $C['parts_committed'] <= 0) { $F[] = sbc_f(9, 'info', 'act_none'); return $F; }
    if ($nSb > 1) $F[] = sbc_f(9, 'info', 'act_shared', ['n' => $nSb]);
    $over = []; foreach ($C['rows'] as $r) { if ($r['budget_h'] <= 0 && $r['actual_h'] > 0) $over[] = sbc_f(9, 'warn', 'act_unbudgeted', ['g' => $r['name'], 'a' => sb_h($r['actual_h'])]); elseif ($r['pct'] > 100) $over[] = sbc_f(9, 'warn', 'act_over', ['g' => $r['name'], 'a' => sb_h($r['actual_h']), 'b' => sb_h($r['budget_h']), 'p' => $r['pct']]); elseif ($r['pct'] >= 90 && $C['progress'] < 90) $over[] = sbc_f(9, 'info', 'act_near', ['g' => $r['name'], 'p' => $r['pct'], 'pr' => (int)$C['progress']]); }
    $F = array_merge($F, sbc_cap($over, 9, 8));
    if ($C['parts_budget'] > 0 && $C['parts_committed'] > $C['parts_budget'] + 0.005) $F[] = sbc_f(9, 'warn', 'act_parts', ['a' => sbc_m($C['parts_committed']), 'b' => sbc_m($C['parts_budget'])]);
    $bud = $C['budget_cost'] + $C['parts_budget']; if ($bud > 0 && $C['forecast_cost'] > $bud * (1 + sbc_set('overrun') / 100)) $F[] = sbc_f(9, 'warn', 'act_forecast', ['f' => sbc_m($C['forecast_cost']), 'b' => sbc_m($bud), 'p' => number_format(($C['forecast_cost'] / $bud - 1) * 100, 0)]);
    return $F;
}

/* ------------------------------------------------------------------ 10 AI review (on request) */
function sbc_ai_file(int $id): string { $d = dirname(__DIR__) . '/storage/sbc'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d . '/ai-' . $id . '.json'; }
function sbc_ai_get(int $id): ?array { $f = sbc_ai_file($id); if (!is_file($f)) return null; $d = json_decode((string) @file_get_contents($f), true); return is_array($d) ? $d : null; }
/** Ask the assistant to compare the scope text with the lines; the answer is kept with the budget revision it was made for. Returns the number of remarks. */
function sbc_ai_review(int $id): int {
    sb_guard(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); if (!function_exists('sb_ai_on') || !sb_ai_on()) throw new RuntimeException(t('sb.ai_off'));
    $txt = function_exists('rt_plain') ? rt_plain((string)($B['description'] ?? '')) : strip_tags((string)($B['description'] ?? '')); if (trim($txt) === '') throw new RuntimeException(t('sbc.f_descr_empty'));
    $sec = sb_sections(); $L = ['Title: ' . $B['title'], '', 'DESCRIPTION (scope text):', mb_substr($txt, 0, 5000), '', 'LABOUR LINES (code | description | section | hours):'];
    foreach ($B['labour'] as $l) { $c = sb_labour_calc($B, $l); $L[] = '- ' . trim((string)$l['code']) . ' | ' . $l['descr'] . ' | ' . (string)($sec[(int)$l['section_id']]['name'] ?? sbc_bucket($l)) . ' | ' . sb_h($c['hours']) . ($c['hours'] <= 0 ? ' (0 h — excluded / by others)' : ''); }
    $L[] = ''; $L[] = 'PART LINES (code | description | quantity):'; foreach (array_slice($B['parts'], 0, SB_AI_PARTS) as $p) $L[] = '- ' . trim((string)$p['code']) . ' | ' . $p['descr'] . ' | ' . sb_h((float)$p['qty']) . (sb_part_excluded($p) ? ' | EXCLUDED' : ''); if (count($B['parts']) > SB_AI_PARTS) $L[] = '(… and ' . (count($B['parts']) - SB_AI_PARTS) . ' more part lines not listed)';
    $L[] = ''; $L[] = 'OTHER COSTS: ' . implode(', ', array_unique(array_map(fn($x) => (string)$x['descr'], $B['expenses'])));
    $prompt = "Review this internal system budget for consistency between its scope text and its lines. Report only clear, specific gaps that a senior marine automation estimator would raise — not style. "
            . "Answer ONLY a JSON object: {\"not_in_lines\": [..], \"not_in_description\": [..], \"contradictions\": [..]} where each entry is one plain sentence of at most 160 characters; at most 6 entries per list; an empty list when there is nothing. "
            . "not_in_lines = work or equipment the description promises that no labour or part line covers; not_in_description = significant lines (hours or parts) the description does not mention; contradictions = quantities, exclusions or responsibilities stated differently in the two. Do not mention prices. Do not invent.\n\n" . implode("\n", $L);
    $res = ai_run('write', ['title' => t('sb.title'), 'headline' => sb_label($B)], $prompt, null, 'system_budget'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
    $j = ai_json((string)$res['text']); $items = [];
    foreach (['not_in_lines', 'not_in_description', 'contradictions'] as $k) foreach (array_slice((array)($j[$k] ?? []), 0, 6) as $s) if (is_string($s) && trim($s) !== '') $items[] = ['k' => $k, 't' => mb_substr(trim(strip_tags($s)), 0, 200)];
    @file_put_contents(sbc_ai_file($id), json_encode(['rev' => (int)$B['revision'], 'at' => date('Y-m-d H:i:s'), 'by' => (string)(current_user()['full_name'] ?? ''), 'items' => $items, 'parsed' => (bool)$j], JSON_UNESCAPED_UNICODE), LOCK_EX);
    sb_event($id, 'ai_review', 'R' . (int)$B['revision'] . ' · ' . count($items)); if (function_exists('cache_set')) cache_set('sbc_sum:' . $id, null, 1);
    return count($items);
}
function sbc_c10(array $B): array {
    $F = []; if (!empty($B['_rev'])) return $F; $A = sbc_ai_get((int)$B['id']);
    if (!$A) { if (function_exists('sb_ai_on') && sb_ai_on() && in_array($B['status'], ['draft', 'working', 'finished'], true) && ($B['labour'] || $B['parts'])) $F[] = sbc_f(10, 'info', 'ai_none'); return $F; }
    $stale = (int)$A['rev'] !== (int)$B['revision'] || (!empty($B['updated_at']) && strtotime((string)$A['at']) < time() - tz_age((string)$B['updated_at']) - 60);
    if ($stale) $F[] = sbc_f(10, 'info', 'ai_stale', ['r' => 'R' . (int)$A['rev'], 'd' => date('d/m/Y H:i', (int) strtotime((string)$A['at']))]);
    if (!$A['items']) $F[] = sbc_f(10, 'info', 'ai_clean', ['d' => date('d/m/Y H:i', (int) strtotime((string)$A['at']))]);
    foreach ($A['items'] as $it) $F[] = ['c' => 10, 'sev' => 'info', 'code' => 'ai_item', 'msg' => t('sbc.ai_' . $it['k']) . ': ' . $it['t'], 'at' => $it['k'] === 'not_in_description' ? 'descr' : 'labour'];
    return $F;
}

/* ------------------------------------------------------------------ run, gate, summary */
/** All checks of one budget (or of a stored revision shaped like one). $only = list of check numbers. */
function sbc_run(array $B, ?array $only = null): array {
    $T = sb_totals($B); $extra = []; $F = [];
    $on = fn(int $c) => $only === null || in_array($c, $only, true);
    foreach ([1 => fn() => sbc_c1($B, $T), 2 => fn() => sbc_c2($B, $T), 3 => fn() => sbc_c3($B), 4 => fn() => sbc_c4($B, $T), 5 => fn() => sbc_c5($B, $T), 6 => function () use ($B, $T, &$extra) { return sbc_c6($B, $T, $extra); },
              7 => function () use ($B, $T, &$extra) { return sbc_c7($B, $T, $extra); }, 8 => fn() => sbc_c8($B, $T), 9 => function () use ($B, $T, &$extra) { return sbc_c9($B, $T, $extra); }, 10 => fn() => sbc_c10($B)] as $c => $fn)
        if ($on($c)) { try { $F = array_merge($F, $fn()); } catch (Throwable $e) { error_log('[sbc] check ' . $c . ': ' . $e->getMessage()); $F[] = ['c' => $c, 'sev' => 'info', 'code' => 'failed', 'msg' => t('sbc.f_failed'), 'at' => '']; } }
    $n = ['block' => 0, 'warn' => 0, 'info' => 0]; foreach ($F as $f) $n[$f['sev']]++;
    return ['findings' => $F, 'n' => $n, 'extra' => $extra, 'totals' => $T];
}
/** Management accepted the blocking findings of this revision (with a reason). */
function sbc_overridden(array $B): ?array { try { return one("SELECT v.note, v.created_at, u.full_name FROM sb_events v LEFT JOIN customer_users u ON u.id = v.user_id WHERE v.budget_id = ? AND v.kind = 'override' AND v.note LIKE ? ORDER BY v.id DESC LIMIT 1", [(int)$B['id'], 'R' . (int)$B['revision'] . ' ·%']) ?: null; } catch (Throwable $e) { return null; } }
function sbc_override(int $id, string $reason): void {
    sb_guard(); if (!sb_can_reopen()) throw new RuntimeException(t('sb.reopen_mgmt')); $B = sb_get($id); if (!$B || !empty($B['is_template'])) throw new RuntimeException(t('err.notfound'));
    $reason = trim((string) preg_replace('/\s+/', ' ', $reason)); if (mb_strlen($reason) < 10) throw new RuntimeException(t('sbc.ov_reason_short'));
    $R = sbc_run($B, [1, 2, 4]); if (!$R['n']['block']) throw new RuntimeException(t('sbc.ov_nothing'));
    sb_event($id, 'override', 'R' . (int)$B['revision'] . ' · ' . mb_substr($reason, 0, 270)); if (function_exists('cache_set')) cache_set('sbc_sum:' . $id, null, 1);
}
/** The findings that stop Finish / Convert now: blocking ones of the exact checks (1, 2, 4), unless accepted by management. Codes already
 *  reported by sb_finish_blockers() are left out here, so the message does not say the same thing twice. */
function sbc_blocking(array $B): array { if (!empty($B['is_template']) || sbc_overridden($B)) return []; $o = []; foreach (sbc_run($B, [1, 2, 4])['findings'] as $f) if ($f['sev'] === 'block' && !in_array($f['code'], ['unpriced'], true)) $o[] = $f['msg']; return array_values(array_unique($o)); }
/** Counts for lists (kept 10 minutes per budget and change): ['block','warn','info','top']. */
function sbc_summary(array $row): array {
    $id = (int)$row['id']; $key = 'sbc_sum:' . $id; $stamp = (string)($row['updated_at'] ?? '') . '|' . (string)$row['status'] . '|' . (int)$row['revision']; $c = cache_get($key);
    if (is_array($c) && ($c['stamp'] ?? '') === $stamp) return $c;
    $B = sb_get($id); if (!$B) return ['block' => 0, 'warn' => 0, 'info' => 0, 'top' => '', 'ov' => false, 'stamp' => $stamp];
    $R = sbc_run($B); $top = ''; foreach (['block', 'warn'] as $s) { foreach ($R['findings'] as $f) if ($f['sev'] === $s) { $top = $f['msg']; break 2; } }
    $o = $R['n'] + ['top' => mb_substr($top, 0, 200), 'ov' => (bool) sbc_overridden($B), 'stamp' => $stamp]; cache_set($key, $o, 600); return $o;
}
/** Budgets for the overview: open, finished, and converted in the last 180 days — with their counts, worst first. */
function sbc_overview(): array {
    if (!sb_has()) return []; $rows = [];
    try { $rows = all("SELECT b.id, b.sb_no, b.revision, b.title, b.status, b.updated_at, b.tot_cost, b.tot_sale, u.full_name AS owner_name, p.project_code FROM sb_budgets b LEFT JOIN customer_users u ON u.id = b.owner_user_id LEFT JOIN projects p ON p.id = b.project_id
                       WHERE " . (function_exists('sb_r2') && sb_r2() ? 'b.is_template = 0 AND ' : '') . "(b.status IN ('draft','working','finished') OR (b.status = 'converted' AND b.converted_at >= NOW() - INTERVAL 180 DAY)) ORDER BY b.id DESC LIMIT 200"); } catch (Throwable $e) {}
    foreach ($rows as &$r) $r['s'] = sbc_summary($r); unset($r);
    usort($rows, fn($a, $b) => [($b['s']['block'] > 0 && !$b['s']['ov']), $b['s']['block'], $b['s']['warn'], $b['id']] <=> [($a['s']['block'] > 0 && !$a['s']['ov']), $a['s']['block'], $a['s']['warn'], $a['id']]);
    return $rows;
}
function sbc_settings_save(): void { if (!sb_can_reopen()) throw new RuntimeException(t('err.noaccess')); foreach (['price_tol' => 100, 'price_age' => 120, 'overrun' => 200, 'pm_share' => 50, 'ot_share' => 100, 'profit_drop' => 100] as $k => $max) if (isset($_POST[$k]) && trim((string)$_POST[$k]) !== '') cp_setting_set('sbc_' . $k, (string) max(0.0, min((float)$max, sb_num((string)$_POST[$k])))); }

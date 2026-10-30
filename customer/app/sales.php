<?php
/**
 * Sales KPIs (Commercial › Offers › Sales tab), monthly sales report, targets.         GAS-CP-R39-002
 *   hit rate · pipeline by aging · decision time · validity breaches · quote turnaround (requested → sent) ·
 *   average order value · offers per sales person (= the offer's creator) · loss reasons · offered → invoiced ·
 *   repeat business · service-call conversion · weighted forecast (probability per status) · targets vs achieved.
 */

const SL_LOST = ['price', 'timing', 'competitor', 'scope', 'no_answer', 'other'];

function sl_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) db()->query("SHOW COLUMNS FROM offers LIKE 'lost_reason'")->fetch(); } catch (Throwable $e) { $h = false; } } return $h; }
function sl_can(): bool { return sl_has() && is_staff() && fin_visible() && function_exists('of_visible') && of_visible(); }
function sl_prob(): array { static $c = null; if ($c === null) { $c = []; try { foreach (all('SELECT * FROM offer_status_prob') as $r) $c[$r['skey']] = (int)$r['probability']; } catch (Throwable $e) {} } return $c; }
function sl_prob_of(string $status): int { $p = sl_prob(); if (isset($p[$status])) return $p[$status]; $b = function_exists('of_status_behaves') ? of_status_behaves($status) : 'open'; return $b === 'open' ? 30 : (in_array($b, ['approved', 'closed_won'], true) ? 100 : 0); }
function sl_period(string $sel): array {
    $y = (int)date('Y'); $m = (int)date('m');
    return match ($sel) {
        'last' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
        'q' => [sprintf('%04d-%02d-01', $y, (int)(floor(($m - 1) / 3) * 3 + 1)), date('Y-m-d')],
        '12' => [date('Y-m-d', strtotime('-12 months')), date('Y-m-d')],
        'm' => [date('Y-m-01'), date('Y-m-d')],
        default => [$y . '-01-01', date('Y-m-d')],
    };
}
/** Every offer with what the KPIs need. */
function sl_offers(array $f = []): array {
    $w = ["p.project_code NOT LIKE 'GEN-%' OR p.project_code IS NULL"]; $a = [];
    if (!empty($f['branch'])) { $w[] = 'COALESCE(p.branch_id, 0) = ?'; $a[] = (int)$f['branch']; }
    if (!empty($f['user'])) { $w[] = 'o.created_by = ?'; $a[] = (int)$f['user']; }
    if (!empty($f['customer'])) { $w[] = 'COALESCE(p.customer_id, v.customer_id) = ?'; $a[] = (int)$f['customer']; }
    return all("SELECT o.*, p.project_code, p.customer_id AS p_customer_id, p.branch_id, v.name AS vessel, c.name AS customer_name, cu.full_name AS creator,
                       (SELECT MIN(t.created_at) FROM tickets t WHERE t.id = o.ticket_id) AS ticket_created,
                       (SELECT COALESCE(SUM(a.value),0) FROM acc_lines a WHERE a.project_id = o.project_id AND a.state = 'invoiced' AND (o.subproject_id IS NULL OR a.subproject_id = o.subproject_id)) AS invoiced_value
                FROM offers o LEFT JOIN projects p ON p.id = o.project_id LEFT JOIN vessels v ON v.id = COALESCE(o.vessel_id, p.vessel_id) LEFT JOIN customers c ON c.id = COALESCE(p.customer_id, v.customer_id) LEFT JOIN customer_users cu ON cu.id = o.created_by
                WHERE (" . implode(') AND (', $w) . ') ORDER BY o.sent_at DESC, o.id DESC', $a);
}
function sl_days(?string $a, ?string $b): ?int { if (!$a || !$b) return null; return (int) floor((strtotime(substr($b, 0, 10)) - strtotime(substr($a, 0, 10))) / 86400); }
/** The KPI set for a period. */
function sl_kpis(string $from, string $to, array $f = []): array {
    $all = sl_offers($f); $won = []; $lost = []; $sentIn = []; $pipe = []; $dec = []; $turn = []; $breach = 0; $fc = 0.0; $fcN = 0;
    $byUser = []; $byCust = []; $lostWhy = []; $offeredInv = ['offered' => 0.0, 'invoiced' => 0.0]; $disc = 0.0;
    $inP = fn(?string $d) => $d && substr($d, 0, 10) >= $from && substr($d, 0, 10) <= $to;
    foreach ($all as $o) {
        $b = function_exists('of_status_behaves') ? of_status_behaves($o['status']) : 'open'; $amt = (float)$o['amount'];
        $decidedAt = $o['decided_at'] ? substr($o['decided_at'], 0, 10) : ($o['status_at'] ?: null);
        $isWon = in_array($b, ['approved', 'closed_won'], true); $isLost = $b === 'closed_lost';
        $uid = (int)($o['created_by'] ?? 0); $byUser[$uid] ??= ['name' => $o['creator'] ?: '—', 'sent' => 0, 'sent_v' => 0.0, 'won' => 0, 'won_v' => 0.0, 'lost' => 0, 'open' => 0, 'open_v' => 0.0, 'dec' => []];
        $cid = (int)($o['p_customer_id'] ?? 0); $byCust[$cid] ??= ['name' => $o['customer_name'] ?: '—', 'sent' => 0, 'won' => 0, 'won_v' => 0.0, 'lost' => 0, 'open_v' => 0.0, 'won_12' => 0];
        if ($o['status'] !== 'draft' && $inP($o['sent_at'])) { $sentIn[] = $o; $byUser[$uid]['sent']++; $byUser[$uid]['sent_v'] += $amt; $byCust[$cid]['sent']++;
            if ($o['requested_at'] || $o['ticket_created']) { $d = sl_days($o['requested_at'] ?: $o['ticket_created'], $o['sent_at']); if ($d !== null && $d >= 0) $turn[] = $d; } }
        if ($isWon && $inP($decidedAt)) { $won[] = $o; $byUser[$uid]['won']++; $byUser[$uid]['won_v'] += $amt; $byCust[$cid]['won']++; $byCust[$cid]['won_v'] += $amt; $d = sl_days($o['sent_at'], $decidedAt); if ($d !== null) { $dec[] = $d; $byUser[$uid]['dec'][] = $d; } if ($o['valid_until'] && $decidedAt > $o['valid_until']) $breach++; }
        if ($isLost && $inP($decidedAt)) { $lost[] = $o; $byUser[$uid]['lost']++; $byCust[$cid]['lost']++; $lostWhy[$o['lost_reason'] ?: 'unknown'] = ($lostWhy[$o['lost_reason'] ?: 'unknown'] ?? 0) + 1; $d = sl_days($o['sent_at'], $decidedAt); if ($d !== null) $dec[] = $d; }
        if ($isWon && $decidedAt && substr($decidedAt, 0, 10) >= date('Y-m-d', strtotime('-12 months'))) $byCust[$cid]['won_12']++;
        if ($b === 'open' && $o['status'] !== 'draft') { $age = function_exists('of_age') ? (of_age($o) ?? 0) : 0; $k = $age <= 7 ? 'a7' : ($age <= 14 ? 'a14' : 'a14p'); $pipe[$k] = ($pipe[$k] ?? 0) + $amt; $pipe['n'] = ($pipe['n'] ?? 0) + 1; $pipe['v'] = ($pipe['v'] ?? 0) + $amt; $byUser[$uid]['open']++; $byUser[$uid]['open_v'] += $amt; $byCust[$cid]['open_v'] += $amt; $fc += $amt * sl_prob_of($o['status']) / 100; $fcN++; }
        if ($isWon) { $offeredInv['offered'] += $amt; $offeredInv['invoiced'] += (float)$o['invoiced_value']; }
    }
    $nDec = count($won) + count($lost);
    // service calls → offers within 30 days
    $conv = ['calls' => 0, 'offers' => 0];
    foreach (all("SELECT t.id, t.created_at, (SELECT MIN(o.sent_at) FROM offers o WHERE o.ticket_id = t.id) AS first_offer FROM tickets t WHERE t.created_at BETWEEN ? AND ? AND t.category IN ('fault','service','spare_parts')", [$from . ' 00:00:00', $to . ' 23:59:59']) as $t) { $conv['calls']++; if ($t['first_offer'] && sl_days($t['created_at'], $t['first_offer']) <= 30) $conv['offers']++; }
    try { $disc = (float) scalar("SELECT COALESCE(SUM(hours * unit_price * discount_pct / 100),0) FROM acc_lines WHERE state IN ('reported','invoiced') AND work_date BETWEEN ? AND ?", [$from, $to]); } catch (Throwable $e) {}
    foreach ($byUser as &$u) { $u['hit'] = ($u['won'] + $u['lost']) > 0 ? $u['won'] / ($u['won'] + $u['lost']) : null; $u['dec_avg'] = $u['dec'] ? array_sum($u['dec']) / count($u['dec']) : null; unset($u['dec']); } unset($u);
    uasort($byUser, fn($a, $b) => $b['won_v'] <=> $a['won_v']); uasort($byCust, fn($a, $b) => $b['won_v'] <=> $a['won_v']);
    $repeat = count(array_filter($byCust, fn($c) => $c['won_12'] >= 2)); $wonTotal = array_sum(array_column($byCust, 'won_v')); $top5 = array_sum(array_slice(array_column($byCust, 'won_v'), 0, 5));
    return ['from' => $from, 'to' => $to, 'sent' => count($sentIn), 'sent_v' => array_sum(array_map(fn($o) => (float)$o['amount'], $sentIn)), 'won' => count($won), 'won_v' => array_sum(array_map(fn($o) => (float)$o['amount'], $won)), 'lost' => count($lost), 'lost_v' => array_sum(array_map(fn($o) => (float)$o['amount'], $lost)),
            'hit' => $nDec ? count($won) / $nDec : null, 'hit_v' => ($wv = array_sum(array_map(fn($o) => (float)$o['amount'], $won)) + array_sum(array_map(fn($o) => (float)$o['amount'], $lost))) > 0 ? array_sum(array_map(fn($o) => (float)$o['amount'], $won)) / $wv : null,
            'aov' => $won ? array_sum(array_map(fn($o) => (float)$o['amount'], $won)) / count($won) : null, 'dec_avg' => $dec ? array_sum($dec) / count($dec) : null, 'breach' => $breach, 'turn_avg' => $turn ? array_sum($turn) / count($turn) : null, 'turn_n' => count($turn),
            'pipe' => $pipe + ['n' => 0, 'v' => 0.0, 'a7' => 0.0, 'a14' => 0.0, 'a14p' => 0.0], 'forecast' => $fc, 'forecast_n' => $fcN, 'by_user' => $byUser, 'by_customer' => $byCust, 'lost_why' => $lostWhy,
            'offered_inv' => $offeredInv, 'repeat' => $repeat, 'customers_won' => count(array_filter($byCust, fn($c) => $c['won'] > 0)), 'top5_share' => $wonTotal > 0 ? $top5 / $wonTotal : null, 'conv' => $conv, 'discount' => $disc];
}
/* ------------------------------------------------------------------ targets */
function sl_targets(int $year, int $month): array { if (!sl_has()) return []; $out = []; foreach (all('SELECT * FROM sales_targets WHERE year = ? AND month = ?', [$year, $month]) as $r) $out[(int)$r['user_id'] . '|' . (int)$r['branch_id']] = $r; return $out; }
function sl_targets_save_from_post(): void {
    require_cp_admin(); if (!sl_has()) return;
    $y = max(2020, min(2100, (int)($_POST['year'] ?? date('Y')))); $m = max(1, min(12, (int)($_POST['month'] ?? date('m'))));
    foreach ((array)($_POST['t'] ?? []) as $key => $row) {
        [$uid, $bid] = array_map('intval', explode('|', (string)$key) + [0, 0]);
        $sent = max(0, (int)($row['sent'] ?? 0)); $val = max(0.0, (float) str_replace(',', '.', (string)($row['value'] ?? '0')));
        q('INSERT INTO sales_targets (year, month, user_id, branch_id, offers_sent, value_approved) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE offers_sent = VALUES(offers_sent), value_approved = VALUES(value_approved)', [$y, $m, $uid ?: null, $bid ?: null, $sent, $val]);
    }
    foreach ((array)($_POST['prob'] ?? []) as $sk => $p) { $sk = preg_replace('/[^a-z0-9_]/', '', (string)$sk); if ($sk !== '') q('INSERT INTO offer_status_prob (skey, probability) VALUES (?,?) ON DUPLICATE KEY UPDATE probability = VALUES(probability)', [$sk, max(0, min(100, (int)$p))]); }
}
/** Achieved vs target for a month: per sales person and company-wide. */
function sl_month_vs_target(int $year, int $month): array {
    $from = sprintf('%04d-%02d-01', $year, $month); $to = date('Y-m-t', strtotime($from)); $K = sl_kpis($from, $to); $T = sl_targets($year, $month); $rows = [];
    foreach ($K['by_user'] as $uid => $u) { $t = $T[$uid . '|0'] ?? null; $rows[] = ['uid' => $uid, 'name' => $u['name'], 'sent' => $u['sent'], 'sent_t' => (int)($t['offers_sent'] ?? 0), 'won_v' => $u['won_v'], 'won_t' => (float)($t['value_approved'] ?? 0)]; }
    $tc = $T['0|0'] ?? null;
    return ['from' => $from, 'to' => $to, 'K' => $K, 'rows' => $rows, 'company' => ['sent' => $K['sent'], 'sent_t' => (int)($tc['offers_sent'] ?? 0), 'won_v' => $K['won_v'], 'won_t' => (float)($tc['value_approved'] ?? 0)]];
}
function sl_pct(float $a, float $t): ?int { return $t > 0 ? (int) round($a / $t * 100) : null; }
function sl_money(float $v): string { return number_format($v, 0, ',', '.') . ' EUR'; }
function sl_lost_label(string $k): string { return $k === 'unknown' ? t('sl.lost_unknown') : t('sl.lost_' . $k); }

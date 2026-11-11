<?php
/**
 * System Budgets — GAS-CP-R97-001 (design GAS-2026-PORTAL-DES-001 R0, release 1: core) + import of the GLD-BUD budget working file.
 *
 * A System Budget (SB-YYYY-NNNN) is the internal estimate of a system before the offer: labour lines (section × discipline rows and
 * rate-card rows; normal / travelling / overtime hours, daily allowances), up to 124 part lines (free or from inventory; cost and sale
 * side by side, margin 40 % on cost by default), other costs (travelling expenses, logistics, pass-through), a rich-text description,
 * comments and a history. Visible to Sales, project managers, management and administrators only — enforced here, not in the menu.
 * New tables only (sb_*): the sub-project budget, offers, tickets, inventory and BOM tables are not touched.
 */
declare(strict_types=1);

const SB_STATUSES = ['draft', 'working', 'finished', 'converted', 'cancelled'];
const SB_TONE = ['draft' => 'mute', 'working' => 'gold', 'finished' => 'green', 'converted' => 'navy', 'cancelled' => 'bad'];
const SB_MAX_PARTS = 124;
const SB_EXP_KINDS = ['travel', 'logistics', 'pass', 'permit', 'other'];
const SB_BUCKETS = ['Engineering', 'Site', 'Workshop', 'Commissioning', 'PM'];
const SB_BUCKET_STAGE = ['Engineering' => 'engineering', 'Workshop' => 'production', 'Site' => 'installation', 'Commissioning' => 'commissioning'];
const SB_IMPORT_MAX = 5242880;   // 5 MB workbook

function sb_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'sb_budgets'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** Sales, project managers, management (CEO / GM / owner scope) and administrators. */
function sb_can(): bool { static $v = null; if ($v === null) $v = function_exists('is_staff') && is_staff() && sb_has() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('pm_is_pm') && pm_is_pm()) || (function_exists('pi_is_sales') && pi_is_sales())); return $v; }
function sb_can_reopen(): bool { return sb_can() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can())); }
function sb_require(): array { $u = require_staff(); if (!sb_can()) { http_response_code(403); exit(t('err.noaccess')); } return $u; }
function sb_guard(): void { if (!sb_can()) throw new RuntimeException(t('err.noaccess')); }
function sb_money(float $v, string $cur = 'EUR'): string { return number_format($v, 2, ',', '.') . ' ' . $cur; }
function sb_h(float $h): string { return rtrim(rtrim(number_format($h, 2, ',', ''), '0'), ',') ?: '0'; }
function sb_num(string $s): float { $s = trim($s); if ($s === '') return 0.0; return function_exists('of_parse_amount') ? (float) of_parse_amount($s) : (float) str_replace(',', '.', $s); }
function sb_pill(string $st): string { return pill(SB_TONE[$st] ?? 'mute', t('sb.st_' . $st)); }
function sb_editable(array $B): bool { return in_array($B['status'], ['draft', 'working'], true); }
function sb_label(array $B): string { return $B['sb_no'] . ' R' . (int)$B['revision']; }

function sb_sections(): array { static $c = null; if ($c === null) { $c = []; try { foreach (all('SELECT id, code, name, stage FROM eng_sections WHERE is_active = 1 ORDER BY sort_order, id') as $r) $c[(int)$r['id']] = $r; } catch (Throwable $e) {} } return $c; }
function sb_disciplines(): array { static $c = null; if ($c === null) { $c = []; try { foreach (all('SELECT id, name FROM disciplines WHERE is_active = 1 ORDER BY sort_order, name') as $r) $c[(int)$r['id']] = (string)$r['name']; } catch (Throwable $e) {} } return $c; }
/** Hourly rate cards of the active rate list: card => [name, normal, overtime] for the zone (local / overseas). */
function sb_cards(string $zone = 'local'): array {
    static $m = []; if (isset($m[$zone])) return $m[$zone]; $out = [];
    try {
        $l = function_exists('rl_current') ? rl_current() : null;
        if ($l) foreach (rl_items((int)$l['id'], 'hourly') as $i) {
            if (!(int)($i['is_active'] ?? 1)) continue; $k = (string)$i['card']; $p = rl_price($i, $zone); if ($p === null) continue;
            if (!isset($out[$k])) $out[$k] = ['name' => (string)$i['card_name'], 'normal' => null, 'overtime' => null];
            $isOt = (bool) preg_match('/overtime|υπερωρ/iu', (string)$i['description']); $isHol = (bool) preg_match('/holiday|sunday|night|αργ/iu', (string)$i['description']);
            if ($isOt && !$isHol) { if ($out[$k]['overtime'] === null) $out[$k]['overtime'] = $p; } elseif (!$isHol && $out[$k]['normal'] === null) $out[$k]['normal'] = $p;
        }
    } catch (Throwable $e) {}
    return $m[$zone] = array_filter($out, fn($c) => $c['normal'] !== null);
}

/** Amounts of one labour line under the rules of its budget: [hours, cost, sale, rate, rate_ot, flat]. */
function sb_labour_calc(array $B, array $l): array {
    $n = (float)$l['h_normal']; $tv = (float)$l['h_travel']; $ot = (float)$l['h_overtime']; $d = (float)$l['allow_days']; $h = $n + $tv + $ot;
    $flat = (int)$B['flat_on'] === 1 && trim((string)($l['card'] ?? '')) === '';   // the flat rate prices the discipline rows — all their hours (design OP-02)
    $rate = $flat ? (float)$B['flat_rate'] : (float)$l['rate']; $rot = $flat ? $rate : (($l['rate_ot'] ?? null) !== null && (float)$l['rate_ot'] > 0 ? (float)$l['rate_ot'] : $rate);
    return ['hours' => $h, 'cost' => round($h * (float)$B['cost_rate'] + $d * (float)$B['allow_cost'], 2), 'sale' => round(($n + $tv) * $rate + $ot * $rot + $d * (float)$B['allow_rate'], 2), 'rate' => $rate, 'rate_ot' => $rot, 'flat' => $flat];
}
function sb_part_calc(array $B, array $p): array {
    $m = ($p['margin_pct'] ?? null) !== null && $p['margin_pct'] !== '' ? (float)$p['margin_pct'] : (float)$B['parts_margin'];
    $cost = round((float)$p['qty'] * (float)$p['unit_cost'], 2); $unitSale = round((float)$p['unit_cost'] * (1 + $m / 100), 4);   // margin on cost (design OP-03)
    return ['margin' => $m, 'cost' => $cost, 'unit_sale' => $unitSale, 'sale' => round((float)$p['qty'] * $unitSale, 2)];
}
function sb_totals(array $B): array {
    $T = ['hours' => 0.0, 'h_normal' => 0.0, 'h_travel' => 0.0, 'h_overtime' => 0.0, 'days' => 0.0, 'lab_cost' => 0.0, 'lab_sale' => 0.0, 'parts_cost' => 0.0, 'parts_sale' => 0.0, 'exp_cost' => 0.0, 'exp_sale' => 0.0, 'out_cost' => 0.0, 'out_sale' => 0.0, 'unpriced' => 0];
    foreach ($B['labour'] ?? [] as $l) { $c = sb_labour_calc($B, $l); $T['hours'] += $c['hours']; $T['h_normal'] += (float)$l['h_normal']; $T['h_travel'] += (float)$l['h_travel']; $T['h_overtime'] += (float)$l['h_overtime']; $T['days'] += (float)$l['allow_days']; $T['lab_cost'] += $c['cost']; $T['lab_sale'] += $c['sale']; }
    foreach ($B['parts'] ?? [] as $p) { $c = sb_part_calc($B, $p); $T['parts_cost'] += $c['cost']; $T['parts_sale'] += $c['sale']; if ((float)$p['qty'] > 0 && (float)$p['unit_cost'] <= 0 && !sb_part_excluded($p)) $T['unpriced']++; }
    foreach ($B['expenses'] ?? [] as $x) { if ((int)$x['in_total']) { $T['exp_cost'] += (float)$x['cost']; $T['exp_sale'] += (float)$x['sale']; } else { $T['out_cost'] += (float)$x['cost']; $T['out_sale'] += (float)$x['sale']; } }
    $T['cost'] = round($T['lab_cost'] + $T['parts_cost'] + $T['exp_cost'], 2); $T['sale'] = round($T['lab_sale'] + $T['parts_sale'] + $T['exp_sale'], 2);
    $T['margin'] = $T['sale'] - $T['cost']; $T['margin_pct'] = $T['sale'] > 0.005 ? round(100 * $T['margin'] / $T['sale'], 1) : 0.0;
    $c = (float)$B['commission_pct']; $T['with_commission'] = $c > 0 && $c < 100 ? round($T['sale'] / (1 - $c / 100), 2) : $T['sale'];
    return $T;
}
/** A part line kept only for the exclusions list (owner / OEM supply): code X-.. or the word EXCLUDED — allowed at zero cost. */
function sb_part_excluded(array $p): bool { return (bool) preg_match('/^X-/i', (string)($p['code'] ?? '')) || stripos((string)$p['descr'] . ' ' . (string)($p['remarks'] ?? ''), 'EXCLUDED') !== false; }

function sb_get(int $id): ?array {
    if (!sb_has() || $id <= 0) return null;
    $B = one('SELECT b.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, ou.full_name AS owner_name, cu.full_name AS created_name FROM sb_budgets b LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN subprojects s ON s.id = b.subproject_id LEFT JOIN customer_users ou ON ou.id = b.owner_user_id LEFT JOIN customer_users cu ON cu.id = b.created_by WHERE b.id = ?', [$id]);
    if (!$B) return null;
    $B['labour'] = all('SELECT * FROM sb_labour WHERE budget_id = ? ORDER BY sort_order, id', [$id]);
    $B['parts'] = all('SELECT x.*, i.code AS item_code FROM sb_parts x LEFT JOIN inv_items i ON i.id = x.item_id WHERE x.budget_id = ? ORDER BY x.sort_order, x.id', [$id]);
    $B['expenses'] = all('SELECT * FROM sb_expenses WHERE budget_id = ? ORDER BY sort_order, id', [$id]);
    return $B;
}
function sb_recalc(int $id): void { $B = sb_get($id); if (!$B) return; $T = sb_totals($B); q('UPDATE sb_budgets SET tot_hours = ?, tot_cost = ?, tot_sale = ? WHERE id = ?', [$T['hours'], $T['cost'], $T['sale'], $id]); }
function sb_event(int $id, string $kind, ?string $note = null): void { try { q('INSERT INTO sb_events (budget_id, user_id, kind, note) VALUES (?,?,?,?)', [$id, (int)(current_user()['id'] ?? 0) ?: null, $kind, $note !== null ? mb_substr($note, 0, 300) : null]); } catch (Throwable $e) {} }
function sb_events(int $id): array { return all('SELECT v.*, u.full_name FROM sb_events v LEFT JOIN customer_users u ON u.id = v.user_id WHERE v.budget_id = ? ORDER BY v.id DESC LIMIT 60', [$id]); }
function sb_comments(int $id): array { return all('SELECT c.*, u.full_name FROM sb_comments c LEFT JOIN customer_users u ON u.id = c.user_id WHERE c.budget_id = ? ORDER BY c.id', [$id]); }

function sb_list(array $f = []): array {
    if (!sb_has()) return [];
    $w = ['1=1']; $b = [];
    $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $w[] = "b.status IN ('draft','working')"; elseif (in_array($st, SB_STATUSES, true)) { $w[] = 'b.status = ?'; $b[] = $st; }
    if (!empty($f['project'])) { $w[] = 'b.project_id = ?'; $b[] = (int)$f['project']; }
    if (!empty($f['owner'])) { $w[] = 'b.owner_user_id = ?'; $b[] = (int)$f['owner']; }
    $qq = trim((string)($f['q'] ?? '')); if ($qq !== '') { $w[] = '(b.sb_no LIKE ? OR b.title LIKE ? OR b.source_ref LIKE ? OR p.project_code LIKE ?)'; $like = '%' . $qq . '%'; array_push($b, $like, $like, $like, $like); }
    return all('SELECT b.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, ou.full_name AS owner_name,
                       (SELECT COUNT(*) FROM sb_labour l WHERE l.budget_id = b.id) AS n_lab, (SELECT COUNT(*) FROM sb_parts x WHERE x.budget_id = b.id) AS n_parts, (SELECT COUNT(*) FROM sb_comments c WHERE c.budget_id = b.id) AS n_com
                FROM sb_budgets b LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN subprojects s ON s.id = b.subproject_id LEFT JOIN customer_users ou ON ou.id = b.owner_user_id
                WHERE ' . implode(' AND ', $w) . ' ORDER BY b.updated_at DESC, b.id DESC LIMIT 500', $b);
}
/** Dashboard figures: working / finished not converted / converted this year / past target / average days to finish. */
function sb_dash(): array {
    $D = ['open' => 0, 'finished' => 0, 'converted' => 0, 'late' => 0, 'avg_days' => null, 'open_sale' => 0.0, 'finished_sale' => 0.0];
    if (!sb_has()) return $D;
    foreach (all("SELECT status, COUNT(*) n, COALESCE(SUM(tot_sale),0) s, SUM(target_date IS NOT NULL AND target_date < CURDATE()) late FROM sb_budgets WHERE status IN ('draft','working','finished') GROUP BY status") as $r) {
        if ($r['status'] === 'finished') { $D['finished'] = (int)$r['n']; $D['finished_sale'] = (float)$r['s']; } else { $D['open'] += (int)$r['n']; $D['open_sale'] += (float)$r['s']; $D['late'] += (int)$r['late']; }
    }
    $D['converted'] = (int) scalar("SELECT COUNT(*) FROM sb_budgets WHERE status = 'converted' AND YEAR(updated_at) = YEAR(CURDATE())");
    $a = scalar("SELECT AVG(DATEDIFF(finished_at, created_at)) FROM sb_budgets WHERE finished_at IS NOT NULL AND finished_at >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)"); $D['avg_days'] = $a === null ? null : (float)$a;
    return $D;
}
/** Progress of a budget for the list bar: the five things a budget needs before it can be finished. */
function sb_progress(array $r): int { $n = 0; if (trim((string)$r['title']) !== '') $n++; if ((int)$r['subproject_id']) $n++; if ((int)($r['n_lab'] ?? 0) > 0) $n++; if ((int)($r['n_parts'] ?? 0) > 0) $n++; if (trim((string)($r['description'] ?? '')) !== '') $n++; return in_array($r['status'], ['finished', 'converted'], true) ? 100 : $n * 18; }

function sb_next_no(): string {
    $y = date('Y'); $last = (string) scalar('SELECT sb_no FROM sb_budgets WHERE sb_no LIKE ? ORDER BY sb_no DESC LIMIT 1', ['SB-' . $y . '-%']);
    $n = $last !== '' ? (int) substr($last, -4) + 1 : 1; return sprintf('SB-%s-%04d', $y, $n);
}
function sb_create(string $title, array $extra = []): int {
    sb_guard(); $title = mb_substr(trim($title), 0, 200); if ($title === '') throw new RuntimeException(t('sb.need_title'));
    $uid = (int)(current_user()['id'] ?? 0); $id = 0;
    for ($try = 0; $try < 5 && !$id; $try++) {   // the number is unique — a concurrent create simply takes the next one
        try { q('INSERT INTO sb_budgets (sb_no, title, status, owner_user_id, created_by) VALUES (?,?,?,?,?)', [sb_next_no(), $title, 'draft', $uid ?: null, $uid ?: null]); $id = (int) db()->lastInsertId(); }
        catch (Throwable $e) { if ($try === 4) throw $e; }
    }
    if ($extra) { $set = []; $b = []; foreach ($extra as $k => $v) if (in_array($k, ['description', 'parts_margin', 'cost_rate', 'commission_pct', 'source_ref', 'source_file', 'project_id', 'subproject_id'], true)) { $set[] = "$k = ?"; $b[] = $v; } if ($set) { $b[] = $id; q('UPDATE sb_budgets SET ' . implode(', ', $set) . ' WHERE id = ?', $b); } }
    sb_event($id, 'created', $title); return $id;
}
function sb_load_editable(int $id): array { sb_guard(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); if (!sb_editable($B)) throw new RuntimeException(t('sb.locked')); return $B; }

function sb_save_head_from_post(int $id): void {
    $B = sb_load_editable($id);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 200); if ($title === '') throw new RuntimeException(t('sb.need_title'));
    $sid = (int)($_POST['subproject_id'] ?? 0); $pid = (int)($_POST['project_id'] ?? 0);
    if ($sid) { $pp = scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$pp) $sid = 0; else $pid = (int)$pp; }   // the sub-project decides the project
    if ($pid && !scalar('SELECT id FROM projects WHERE id = ?', [$pid])) $pid = 0;
    $own = (int)($_POST['owner_user_id'] ?? 0); if ($own && !scalar("SELECT id FROM customer_users WHERE id = ? AND role IN ('staff','admin')", [$own])) $own = 0;
    $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['target_date'] ?? '')) ? $_POST['target_date'] : null;
    $pct = fn(string $k, float $def, float $max) => max(0.0, min($max, isset($_POST[$k]) && trim((string)$_POST[$k]) !== '' ? sb_num((string)$_POST[$k]) : $def));
    q('UPDATE sb_budgets SET title = ?, project_id = ?, subproject_id = ?, owner_user_id = ?, target_date = ?, zone = ?, parts_margin = ?, flat_on = ?, flat_rate = ?, cost_rate = ?, allow_rate = ?, allow_cost = ?, commission_pct = ? WHERE id = ?',
      [$title, $pid ?: null, $sid ?: null, $own ?: null, $d, ($_POST['zone'] ?? '') === 'overseas' ? 'overseas' : 'local', $pct('parts_margin', 40, 900), empty($_POST['flat_on']) ? 0 : 1, $pct('flat_rate', 52, 9999), $pct('cost_rate', 35, 9999), $pct('allow_rate', 75, 9999), $pct('allow_cost', 75, 9999), $pct('commission_pct', 0, 90), $id]);
    if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
    sb_recalc($id); sb_event($id, 'header');
}
function sb_save_description_from_post(int $id): void { sb_load_editable($id); q('UPDATE sb_budgets SET description = ? WHERE id = ?', [mb_substr(trim((string)($_POST['description'] ?? '')), 0, 60000) ?: null, $id]); sb_event($id, 'description'); }

/** Labour rows posted as l[<id|new index>][field]; a row with del=1 is removed; a new row with no text and no hours is ignored. */
function sb_save_labour_from_post(int $id): int {
    $B = sb_load_editable($id); $rows = is_array($_POST['l'] ?? null) ? $_POST['l'] : []; $own = array_column($B['labour'], null, 'id'); $sec = sb_sections(); $dis = sb_disciplines(); $cards = sb_cards((string)$B['zone']); $n = 0; $sort = 0;
    foreach ($rows as $key => $r) {
        if (!is_array($r)) continue; $lid = ctype_digit((string)$key) && isset($own[(int)$key]) ? (int)$key : 0; $isNew = !$lid;
        if ($lid && !empty($r['del'])) { q('DELETE FROM sb_labour WHERE id = ? AND budget_id = ?', [$lid, $id]); $n++; continue; }
        $s = fn(string $k, int $len) => mb_substr(trim((string)($r[$k] ?? '')), 0, $len); $f = fn(string $k) => max(0.0, sb_num((string)($r[$k] ?? '')));
        $descr = $s('descr', 400); $hn = $f('h_normal'); $ht = $f('h_travel'); $ho = $f('h_overtime'); $days = $f('allow_days');
        $sid = (int)($r['section_id'] ?? 0); if (!isset($sec[$sid])) $sid = 0; $did = (int)($r['discipline_id'] ?? 0); if (!isset($dis[$did])) $did = 0;
        $card = $s('card', 12); if ($card !== '' && !isset($cards[$card]) && !($lid && (string)$own[$lid]['card'] === $card)) $card = '';
        if ($isNew && $descr === '' && $hn + $ht + $ho + $days <= 0 && !$sid && !$did && $card === '') continue;
        $rate = $f('rate'); $rot = trim((string)($r['rate_ot'] ?? '')) === '' ? null : $f('rate_ot');
        if ($card !== '' && isset($cards[$card])) { if ($rate <= 0) $rate = (float)$cards[$card]['normal']; if ($rot === null && $cards[$card]['overtime'] !== null) $rot = (float)$cards[$card]['overtime']; }   // an empty rate follows the rate card
        if ($descr === '') $descr = trim(($sid ? $sec[$sid]['name'] : '') . ($did ? ' · ' . $dis[$did] : '') . ($card !== '' ? ' · ' . t('sb.card') . ' ' . $card : ''), ' ·');
        $vals = [$s('code', 12) ?: null, $descr, $sid ?: null, $did ?: null, $card !== '' ? $card : null, $hn, $ht, $ho, $days, $rate, $rot, $s('remarks', 500) ?: null];
        if ($lid) { q('UPDATE sb_labour SET code = ?, descr = ?, section_id = ?, discipline_id = ?, card = ?, h_normal = ?, h_travel = ?, h_overtime = ?, allow_days = ?, rate = ?, rate_ot = ?, remarks = ?, sort_order = ? WHERE id = ? AND budget_id = ?', array_merge($vals, [++$sort * 10, $lid, $id])); }
        else { q('INSERT INTO sb_labour (code, descr, section_id, discipline_id, card, h_normal, h_travel, h_overtime, allow_days, rate, rate_ot, remarks, sort_order, budget_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [9000 + (++$sort), $id])); }
        $n++;
    }
    if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
    sb_recalc($id); sb_event($id, 'labour', (string)$n); return $n;
}
/** Part rows posted as p[<id|new index>][field] — at most SB_MAX_PARTS lines per budget. */
function sb_save_parts_from_post(int $id): int {
    $B = sb_load_editable($id); $rows = is_array($_POST['p'] ?? null) ? $_POST['p'] : []; $own = array_column($B['parts'], null, 'id'); $count = count($own); $n = 0; $sort = 0;
    foreach ($rows as $key => $r) {
        if (!is_array($r)) continue; $pid = ctype_digit((string)$key) && isset($own[(int)$key]) ? (int)$key : 0;
        if ($pid && !empty($r['del'])) { q('DELETE FROM sb_parts WHERE id = ? AND budget_id = ?', [$pid, $id]); $count--; $n++; continue; }
        $s = fn(string $k, int $len) => mb_substr(trim((string)($r[$k] ?? '')), 0, $len); $f = fn(string $k) => max(0.0, sb_num((string)($r[$k] ?? '')));
        $descr = $s('descr', 400); $qty = $f('qty'); $cost = $f('unit_cost'); if (!$pid && $descr === '' && $qty <= 0 && $cost <= 0) continue;
        $m = trim((string)($r['margin_pct'] ?? '')) === '' ? null : min(900.0, $f('margin_pct'));
        if (!$pid && trim((string)($r['unit_sale'] ?? '')) !== '' && $m === null && $cost > 0) { $us = $f('unit_sale'); if ($us > 0) $m = round(($us / $cost - 1) * 100, 2); }   // a typed sale price becomes the line's margin
        $vals = [$s('code', 20) ?: null, $descr, $qty, $s('unit', 12) ?: null, $cost, $m, $s('remarks', 500) ?: null];
        if ($pid) q('UPDATE sb_parts SET code = ?, descr = ?, qty = ?, unit = ?, unit_cost = ?, margin_pct = ?, remarks = ?, sort_order = ? WHERE id = ? AND budget_id = ?', array_merge($vals, [++$sort * 10, $pid, $id]));
        else { if ($count >= SB_MAX_PARTS) throw new RuntimeException(t('sb.parts_max', ['n' => SB_MAX_PARTS])); q('INSERT INTO sb_parts (code, descr, qty, unit, unit_cost, margin_pct, remarks, sort_order, budget_id) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($vals, [9000 + (++$sort), $id])); $count++; }
        $n++;
    }
    if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
    sb_recalc($id); sb_event($id, 'parts', (string)$n); return $n;
}
/** Lowest known unit cost of an inventory item and where it comes from (lowest price on record, else last price, else average cost). */
function sb_item_cost(array $i): array {
    $low = null; try { $low = one("SELECT p.unit_price, p.priced_at, co.name AS supplier FROM inv_prices p LEFT JOIN companies co ON co.id = p.supplier_id WHERE p.item_id = ? AND p.source <> 'rollup' AND p.unit_price > 0 ORDER BY p.unit_price ASC, p.priced_at DESC LIMIT 1", [(int)$i['id']]); } catch (Throwable $e) {}
    if ($low) return [(float)$low['unit_price'], trim(t('sb.org_lowest') . ' · ' . (string)$low['supplier'] . ' · ' . (string)$low['priced_at'], ' ·')];
    if ((float)($i['last_price'] ?? 0) > 0) return [(float)$i['last_price'], t('sb.org_last') . ($i['last_price_at'] ? ' · ' . $i['last_price_at'] : '')];
    if ((float)($i['avg_cost'] ?? 0) > 0) return [(float)$i['avg_cost'], t('sb.org_avg')];
    return [0.0, t('sb.org_none')];
}
function sb_inv_options(): array { try { return all("SELECT id, code, description, unit FROM inv_items WHERE status = 'active' ORDER BY code LIMIT 5000"); } catch (Throwable $e) { return []; } }
function sb_add_inv_part(int $id, int $itemId, float $qty): void {
    $B = sb_load_editable($id); if (count($B['parts']) >= SB_MAX_PARTS) throw new RuntimeException(t('sb.parts_max', ['n' => SB_MAX_PARTS]));
    $i = one('SELECT * FROM inv_items WHERE id = ?', [$itemId]); if (!$i) throw new RuntimeException(t('err.notfound'));
    [$cost, $org] = sb_item_cost($i);
    q('INSERT INTO sb_parts (budget_id, sort_order, code, item_id, descr, qty, unit, unit_cost, cost_origin) VALUES (?,?,?,?,?,?,?,?,?)', [$id, 9500, mb_substr((string)$i['code'], 0, 20), $itemId, mb_substr((string)($i['description_en'] ?: $i['description']), 0, 400), $qty > 0 ? $qty : 1, $i['unit'] ?: null, $cost, mb_substr($org, 0, 120)]);
    if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
    sb_recalc($id); sb_event($id, 'parts', (string)$i['code']);
}
function sb_save_expenses_from_post(int $id): int {
    $B = sb_load_editable($id); $rows = is_array($_POST['x'] ?? null) ? $_POST['x'] : []; $own = array_column($B['expenses'], null, 'id'); $n = 0; $sort = 0;
    foreach ($rows as $key => $r) {
        if (!is_array($r)) continue; $xid = ctype_digit((string)$key) && isset($own[(int)$key]) ? (int)$key : 0;
        if ($xid && !empty($r['del'])) { q('DELETE FROM sb_expenses WHERE id = ? AND budget_id = ?', [$xid, $id]); $n++; continue; }
        $descr = mb_substr(trim((string)($r['descr'] ?? '')), 0, 300); $cost = max(0.0, sb_num((string)($r['cost'] ?? ''))); $sale = trim((string)($r['sale'] ?? '')) === '' ? $cost : max(0.0, sb_num((string)$r['sale']));
        if (!$xid && $descr === '' && $cost <= 0 && $sale <= 0) continue;
        $kind = in_array($r['kind'] ?? '', SB_EXP_KINDS, true) ? $r['kind'] : 'other'; if ($descr === '') $descr = t('sb.xk_' . $kind);
        $vals = [$kind, $descr, $cost, $sale, empty($r['out']) ? 1 : 0];
        if ($xid) q('UPDATE sb_expenses SET kind = ?, descr = ?, cost = ?, sale = ?, in_total = ?, sort_order = ? WHERE id = ? AND budget_id = ?', array_merge($vals, [++$sort * 10, $xid, $id]));
        else q('INSERT INTO sb_expenses (kind, descr, cost, sale, in_total, sort_order, budget_id) VALUES (?,?,?,?,?,?,?)', array_merge($vals, [9000 + (++$sort), $id]));
        $n++;
    }
    sb_recalc($id); sb_event($id, 'expenses', (string)$n); return $n;
}
/** What still stops a budget from being finished. */
function sb_finish_blockers(array $B): array {
    $T = sb_totals($B); $x = [];
    if (!(int)$B['project_id'] || !(int)$B['subproject_id']) $x[] = t('sb.blk_sub');
    if (!$B['labour'] && !$B['parts']) $x[] = t('sb.blk_lines');
    if ($T['unpriced'] > 0) $x[] = t('sb.blk_unpriced', ['n' => $T['unpriced']]);
    return $x;
}
function sb_set_status(int $id, string $to): void {
    sb_guard(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); $from = (string)$B['status']; $uid = (int)(current_user()['id'] ?? 0);
    if ($to === 'finished' && in_array($from, ['draft', 'working'], true)) { $x = sb_finish_blockers($B); if ($x) throw new RuntimeException(t('sb.cannot_finish') . ' ' . implode(' ', $x)); q("UPDATE sb_budgets SET status = 'finished', finished_at = NOW(), finished_by = ? WHERE id = ?", [$uid ?: null, $id]); }
    elseif ($to === 'working' && in_array($from, ['finished', 'cancelled'], true)) { if (!sb_can_reopen()) throw new RuntimeException(t('sb.reopen_mgmt')); q("UPDATE sb_budgets SET status = 'working', finished_at = NULL, finished_by = NULL WHERE id = ?", [$id]); }
    elseif ($to === 'cancelled' && in_array($from, ['draft', 'working'], true)) q("UPDATE sb_budgets SET status = 'cancelled' WHERE id = ?", [$id]);
    else throw new RuntimeException(t('sb.bad_status'));
    sb_event($id, 'status', $from . ' → ' . $to);
}
function sb_comment_add(int $id, string $body): void { sb_guard(); if (!sb_get($id)) throw new RuntimeException(t('err.notfound')); $body = mb_substr(trim($body), 0, 4000); if ($body === '') throw new RuntimeException(t('sb.need_comment')); q('INSERT INTO sb_comments (budget_id, user_id, body) VALUES (?,?,?)', [$id, (int)(current_user()['id'] ?? 0) ?: null, $body]); }
function sb_delete(int $id): void { require_cp_admin(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); if (!in_array($B['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('sb.del_only_draft')); foreach (['sb_labour', 'sb_parts', 'sb_expenses', 'sb_comments', 'sb_events'] as $tb) q("DELETE FROM $tb WHERE budget_id = ?", [$id]); q('DELETE FROM sb_budgets WHERE id = ?', [$id]); }

/* ───────────── Import of the GLD-BUD budget working file (Excel built by the golden-budget-workfile skill) ───────────── */

function sb_imp_dir(): string { $d = cp_storage() . '/sbimp'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function sb_imp_path(string $token): ?string { if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null; $own = $_SESSION['sb_imp'][$token] ?? null; if (!$own) return null; $p = sb_imp_dir() . '/' . $token . '.xlsx'; return is_file($p) ? $p : null; }
/** Keep the uploaded workbook for the review step; returns its token (session-bound, random, files older than a day are removed). */
function sb_imp_stash(array $file): string {
    sb_guard();
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException(t('sb.imp_nofile'));
    if ((int)$file['size'] > SB_IMPORT_MAX || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException(t('sb.imp_badfile'));
    $fh = fopen((string)$file['tmp_name'], 'rb'); $sig = $fh ? (string) fread($fh, 4) : ''; if ($fh) fclose($fh); if ($sig !== "PK\x03\x04") throw new RuntimeException(t('sb.imp_badfile'));
    foreach ((array) @glob(sb_imp_dir() . '/*.xlsx') as $old) if (@filemtime($old) < time() - 86400) @unlink($old);
    $token = bin2hex(random_bytes(16)); if (!move_uploaded_file((string)$file['tmp_name'], sb_imp_dir() . '/' . $token . '.xlsx')) throw new RuntimeException(t('err.upload'));
    $_SESSION['sb_imp'][$token] = mb_substr(basename((string)$file['name']), 0, 200); return $token;
}
function sb_imp_drop(string $token): void { $p = sb_imp_path($token); if ($p) @unlink($p); unset($_SESSION['sb_imp'][$token]); }
/** Sheet rows of a workbook by exact sheet name (null when the sheet does not exist); refuses oversized sheets. */
function sb_xlsx_sheet(string $path, string $name): ?array {
    $z = new ZipArchive(); if ($z->open($path) !== true) throw new RuntimeException(t('sb.imp_badfile'));
    $names = []; $wb = $z->getFromName('xl/workbook.xml'); $wx = $wb !== false ? @simplexml_load_string($wb) : null; if ($wx) foreach ($wx->sheets->sheet as $s) $names[] = (string)$s['name'];
    for ($i = 0; $i < $z->numFiles; $i++) { $st = $z->statIndex($i); if ($st && (int)$st['size'] > 30 * 1048576) { $z->close(); throw new RuntimeException(t('sb.imp_badfile')); } }
    $z->close(); foreach ($names as $n) if (strcasecmp($n, $name) === 0) return xlsx_rows($path, $n); return null;
}
function sb_xnum($v): ?float { $v = trim((string)$v); if ($v === '' || !is_numeric(str_replace(',', '.', $v))) return null; return (float) str_replace(',', '.', $v); }
/**
 * Read a GLD-BUD workbook (sheets Budget Summary, Labour, Parts). Cells are found by their labels and headers, never by fixed row numbers —
 * lines may have been added in Excel. Formula cells that were never recalculated (files straight from the build script) are computed here:
 * rate by rate key, blended rate, unit LCO = reference price × (1 − discount).
 * @return array{head:array,labour:array,parts:array,expenses:array,notes:array,revisions:array,warn:array,calc:array}
 */
function sb_xlsx_parse(string $path): array {
    $S = sb_xlsx_sheet($path, 'Budget Summary'); $L = sb_xlsx_sheet($path, 'Labour'); $P = sb_xlsx_sheet($path, 'Parts');
    if ($S === null || $L === null || $P === null) throw new RuntimeException(t('sb.imp_not_gld'));
    $c = fn(array $r, int $i) => trim((string)($r[$i] ?? '')); $warn = [];
    // ── Budget Summary
    $head = ['title' => '', 'doc_ref' => '', 'revision' => '', 'date' => '', 'status' => '', 'source' => '', 'customer' => '', 'class' => '', 'prj' => '', 'est_class' => '', 'validity' => '', 'commission' => 0.0];
    $map = ['document reference' => 'doc_ref', 'revision' => 'revision', 'date' => 'date', 'status' => 'status', 'source take-off' => 'source', 'customer (register)' => 'customer', 'class / flag' => 'class', 'prj no / sq no' => 'prj', 'estimate class (aace 18r-97)' => 'est_class', 'validity (when offered)' => 'validity'];
    $revs = []; $notes = []; $exp = []; $mode = ''; $logCost = null;
    foreach ($S as $r) {
        $a = $c($r, 0); $b = $c($r, 1); $lb = mb_strtolower($b);
        if ($head['title'] === '' && $c($r, 2) !== '' && $b === '' && $a === '' && stripos($c($r, 2), 'BUDGET PRICE WORKING FILE') === false) $head['title'] = $c($r, 2);
        if ($b === 'REVISION HISTORY') { $mode = 'rev'; continue; } if ($b === 'AUTHORISATION') { $mode = ''; continue; }
        if (str_starts_with($b, 'BUDGET PRICE BUILD-UP')) { $mode = 'build'; continue; } if (str_starts_with($b, 'INTERNAL COST')) { $mode = 'cost'; continue; }
        if (str_starts_with($b, 'PENDING INPUTS')) { $mode = 'notes'; continue; } if (str_starts_with($b, 'LEGEND')) { $mode = ''; continue; }
        if (isset($map[$lb]) && $mode === '') { $head[$map[$lb]] = $c($r, 2); continue; }
        if ($mode === 'rev') { if ($b === 'REV' || $b === '') continue; if (preg_match('/^R\d+$/', $b)) $revs[] = ['rev' => $b, 'date' => $c($r, 2), 'desc' => $c($r, 3), 'by' => $c($r, 4)]; continue; }
        if ($mode === 'build') {
            if ($c($r, 6) === 'Commission (% of sell)') { $v = sb_xnum($c($r, 7)); if ($v !== null) $head['commission'] = $v <= 1 ? $v * 100 : $v; }
            $amt = sb_xnum($c($r, 3)); $basis = $c($r, 5);
            if (preg_match('/^[A-Z]$/', $a) && $b !== '') {
                if (stripos($b, 'Logistics') === 0) $exp['logistics'] = ['kind' => 'logistics', 'descr' => $b, 'cost' => 0.0, 'sale' => (float)($amt ?? 0), 'in_total' => 1];
                elseif (stripos($basis, 'Pass-through') !== false && $amt !== null) $exp[] = ['kind' => 'pass', 'descr' => $b, 'cost' => $amt, 'sale' => $amt, 'in_total' => 1];
            } elseif (stripos($b, 'Work-permit fee') === 0 && $amt !== null && $amt > 0) $exp[] = ['kind' => 'permit', 'descr' => $b, 'cost' => $amt, 'sale' => $amt, 'in_total' => 0];
            continue;
        }
        if ($mode === 'cost') { if (stripos($b, 'Logistics cost allowance') === 0) $logCost = sb_xnum($c($r, 3)); continue; }
        if ($mode === 'notes' && $b !== '') $notes[] = preg_replace('/^\d+\.\s*/', '', $b);
    }
    if (isset($exp['logistics'])) { $exp['logistics']['cost'] = (float)($logCost ?? 0); if ($exp['logistics']['sale'] <= 0 && $exp['logistics']['cost'] <= 0) unset($exp['logistics']); }
    if (preg_match('/^(R\d+)/', $head['revision'], $m)) $head['rev_no'] = $m[1]; else $head['rev_no'] = '';
    // ── Labour: rate inputs, then the work packages under the "Code" header
    $rates = []; $internal = null; $labour = []; $in = false; $col = ['code' => 0, 'descr' => 1, 'key' => 2, 'mh' => 3, 'rate' => 4, 'sell' => 5, 'cost' => 6, 'remarks' => 7, 'bucket' => 8];
    foreach ($L as $r) {
        $a = $c($r, 0); $b = $c($r, 1);
        if (!$in) {
            if ($a === 'Code' && stripos($b, 'Work package') === 0) { $in = true; foreach ($r as $i => $h) { $h = mb_strtolower(trim((string)$h)); if ($h === 'erp bucket') $col['bucket'] = $i; elseif ($h === 'remarks') $col['remarks'] = $i; elseif ($h === 'mh') $col['mh'] = $i; elseif (str_starts_with($h, 'rate €')) $col['rate'] = $i; elseif ($h === 'rate basis') $col['key'] = $i; } continue; }
            $k = $c($r, 2); $v = sb_xnum($c($r, 4)); if ($k !== '' && $k !== 'Rate key' && $v !== null) $rates[$k] = $v;
            if (stripos($b, 'Internal labour cost rate') === 0 && $v !== null) $internal = $v; continue;
        }
        if (stripos($b, 'TOTAL LABOUR') === 0) break; if ($a === '' && $b === '') continue;
        $key = $c($r, $col['key']); $mh = sb_xnum($c($r, $col['mh'])) ?? 0.0; $rate = sb_xnum($c($r, $col['rate']));
        $labour[] = ['code' => $a, 'descr' => $b, 'key' => $key, 'mh' => max(0.0, $mh), 'rate' => $rate, 'remarks' => $c($r, $col['remarks']), 'bucket' => $c($r, $col['bucket'])];
    }
    if (!isset($rates['Blended A/B']) && isset($rates['Rate A'], $rates['Rate B'])) $rates['Blended A/B'] = round(($rates['Rate A'] + $rates['Rate B']) / 2, 2);   // the blended cell is a formula
    foreach ($labour as $i => &$l) {
        if ($l['rate'] === null) { if (isset($rates[$l['key']])) $l['rate'] = $rates[$l['key']]; else { $l['rate'] = 0.0; if ($l['mh'] > 0) $warn[] = t('sb.imp_w_rate', ['c' => $l['code'] ?: (string)($i + 1), 'k' => $l['key']]); } }
        if ($l['bucket'] !== '' && !in_array($l['bucket'], SB_BUCKETS, true)) { $warn[] = t('sb.imp_w_bucket', ['c' => $l['code'], 'b' => $l['bucket']]); $l['bucket'] = ''; }
        $l['card'] = preg_match('/^Rate ([A-Z])$/', $l['key'], $m) ? $m[1] : '';
    } unset($l);
    if (!$in) throw new RuntimeException(t('sb.imp_not_gld'));
    // ── Parts: lines under the "Code" header; markup and discount below the total
    $parts = []; $in = false; $pc = ['qty' => 2, 'unit' => 3, 'lco' => 4, 'remarks' => 6, 'ref' => -1]; $markup = null; $disc = null; $after = [];
    foreach ($P as $r) {
        $a = $c($r, 0); $b = $c($r, 1);
        if (!$in) { if ($a === 'Code' && $b === 'Description') { $in = true; foreach ($r as $i => $h) { $h = mb_strtolower(trim((string)$h)); if (str_starts_with($h, 'ref. price')) $pc['ref'] = $i; elseif ($h === 'remarks') $pc['remarks'] = $i; elseif (str_starts_with($h, 'unit lco')) $pc['lco'] = $i; elseif ($h === 'qty') $pc['qty'] = $i; elseif ($h === 'unit') $pc['unit'] = $i; } } continue; }
        if (stripos($b, 'TOTAL PARTS') === 0 || stripos($b, 'Markup factor') === 0 || stripos($b, 'Distributor discount') === 0) { $after[] = $r; continue; }
        if ($after || ($a === '' && $b === '')) continue;
        $parts[] = ['code' => $a, 'descr' => $b, 'qty' => max(0.0, sb_xnum($c($r, $pc['qty'])) ?? 0.0), 'unit' => $c($r, $pc['unit']), 'lco' => sb_xnum($c($r, $pc['lco'])), 'ref' => $pc['ref'] >= 0 ? sb_xnum($c($r, $pc['ref'])) : null, 'remarks' => $c($r, $pc['remarks'])];
    }
    if (!$in) throw new RuntimeException(t('sb.imp_not_gld'));
    foreach ($after as $r) { $b = $c($r, 1); $v = sb_xnum($c($r, 5)); if (stripos($b, 'Markup factor') === 0 && $v !== null) $markup = $v; if (stripos($b, 'Distributor discount') === 0 && $v !== null) $disc = $v; }
    foreach ($parts as $i => &$p) {
        if ($p['lco'] === null) { if ($p['ref'] !== null && $disc !== null) { $p['lco'] = round($p['ref'] * (1 - $disc), 4); $p['origin'] = t('sb.imp_org_ref', ['r' => number_format($p['ref'], 2, ',', '.'), 'd' => rtrim(rtrim(number_format($disc * 100, 1, ',', ''), '0'), ',')]); } else { $p['lco'] = 0.0; if ($p['qty'] > 0 && !sb_part_excluded(['code' => $p['code'], 'descr' => $p['descr'], 'remarks' => $p['remarks']])) $warn[] = t('sb.imp_w_lco', ['c' => $p['code'] ?: (string)($i + 1)]); } }
        elseif ($p['ref'] !== null && $disc !== null) $p['origin'] = t('sb.imp_org_ref', ['r' => number_format($p['ref'], 2, ',', '.'), 'd' => rtrim(rtrim(number_format($disc * 100, 1, ',', ''), '0'), ',')]);
        $p['origin'] = $p['origin'] ?? t('sb.imp_org_lco');
    } unset($p);
    if (count($parts) > SB_MAX_PARTS) throw new RuntimeException(t('sb.imp_parts_max', ['n' => count($parts), 'm' => SB_MAX_PARTS]));
    if (!$labour && !$parts) throw new RuntimeException(t('sb.imp_empty'));
    if ($markup === null) { $markup = 1.5; $warn[] = t('sb.imp_w_markup'); }
    $anyMh = false; foreach ($labour as $l) if ($l['mh'] > 0) $anyMh = true; $anyLco = false; foreach ($parts as $p) if ($p['qty'] * $p['lco'] > 0) $anyLco = true; if (!$anyMh && !$anyLco) $warn[] = t('sb.imp_w_blank');
    // figures recomputed here, to compare on the review page
    $calc = ['mh' => 0.0, 'lab_sell' => 0.0, 'lab_cost' => 0.0, 'lco' => 0.0, 'markup' => $markup, 'discount' => $disc, 'internal' => $internal ?? 35.0];
    foreach ($labour as $l) { $calc['mh'] += $l['mh']; $calc['lab_sell'] += $l['mh'] * $l['rate']; $calc['lab_cost'] += $l['mh'] * $calc['internal']; }
    foreach ($parts as $p) $calc['lco'] += $p['qty'] * $p['lco'];
    $calc['parts_sell'] = round($calc['lco'] * $markup, 2); $calc['exp_sale'] = 0.0; $calc['exp_cost'] = 0.0; foreach ($exp as $x) if ($x['in_total']) { $calc['exp_sale'] += $x['sale']; $calc['exp_cost'] += $x['cost']; }
    $calc['total'] = round($calc['lab_sell'] + $calc['parts_sell'] + $calc['exp_sale'], 2); $calc['cost'] = round($calc['lab_cost'] + $calc['lco'] + $calc['exp_cost'], 2);
    return ['head' => $head, 'labour' => $labour, 'parts' => $parts, 'expenses' => array_values($exp), 'notes' => $notes, 'revisions' => $revs, 'warn' => $warn, 'calc' => $calc, 'rates' => $rates];
}
/** The description text built from the workbook's document control, revision history and notes (rich-text marks). */
function sb_imp_description(array $X): string {
    $h = $X['head']; $o = []; $ln = fn(string $k, string $v) => $v !== '' ? '- **' . $k . ':** ' . $v : null;
    $o[] = '**' . t('sb.imp_d_source') . '**';
    foreach ([$ln(t('sb.imp_d_ref'), trim($h['doc_ref'] . ' ' . $h['revision'])), $ln(t('sb.imp_d_date'), $h['date']), $ln(t('sb.imp_d_status'), $h['status']), $ln(t('sb.imp_d_takeoff'), $h['source']), $ln(t('sb.imp_d_customer'), $h['customer']), $ln(t('sb.imp_d_class'), $h['class']), $ln(t('sb.imp_d_prj'), $h['prj']), $ln(t('sb.imp_d_est'), $h['est_class']), $ln(t('sb.imp_d_validity'), $h['validity'])] as $x) if ($x) $o[] = $x;
    if ($X['revisions']) { $o[] = ''; $o[] = '**' . t('sb.imp_d_revs') . '**'; foreach ($X['revisions'] as $r) $o[] = '- ' . trim($r['rev'] . ' · ' . $r['date'] . ' · ' . $r['desc'] . ($r['by'] !== '' ? ' · ' . $r['by'] : ''), ' ·'); }
    if ($X['notes']) { $o[] = ''; $o[] = '**' . t('sb.imp_d_notes') . '**'; foreach ($X['notes'] as $i => $n) $o[] = ($i + 1) . '. ' . $n; }
    return implode("\n", $o);
}
/**
 * Write a parsed workbook into a System Budget: a new one, or an existing draft / working budget whose labour, parts and other costs are
 * REPLACED (its project, sub-project, owner and comments stay). Everything in one transaction. Returns the budget id.
 */
function sb_import_apply(array $X, string $fileName, int $intoId = 0, array $bucketSection = []): int {
    sb_guard(); $h = $X['head']; $sec = sb_sections(); $dis = sb_disciplines(); $pmDisc = 0; foreach ($dis as $did => $nm) if (preg_match('/project management/i', $nm)) $pmDisc = $did;
    $cards = sb_cards('local'); $pdo = db(); $pdo->beginTransaction();
    try {
        $title = mb_substr($h['title'] !== '' ? $h['title'] : ($h['doc_ref'] ?: $fileName), 0, 200); $ref = mb_substr(trim($h['doc_ref'] . ' ' . $h['rev_no']), 0, 80) ?: null;
        if ($intoId) { $B = sb_load_editable($intoId); $id = $intoId; foreach (['sb_labour', 'sb_parts', 'sb_expenses'] as $tb) q("DELETE FROM $tb WHERE budget_id = ?", [$id]); }
        else $id = sb_create($title);
        $desc = sb_imp_description($X);
        if ($intoId && trim((string)$B['description']) !== '') { $own = (string)$B['description']; $mk = '**' . t('sb.imp_d_source') . '**'; $at = strpos($own, $mk); if ($at !== false) $own = substr($own, 0, $at);   // the block of an earlier import is replaced, the team's own text above it stays
            if (trim($own) !== '') $desc = rtrim($own) . "\n\n" . $desc; }
        q('UPDATE sb_budgets SET description = ?, parts_margin = ?, cost_rate = ?, commission_pct = ?, source_ref = ?, source_file = ?, status = ?, flat_on = 0 WHERE id = ?', [mb_substr($desc, 0, 60000), round(max(0.0, ($X['calc']['markup'] - 1) * 100), 2), $X['calc']['internal'], min(90.0, max(0.0, (float)$h['commission'])), $ref, mb_substr($fileName, 0, 200), 'working', $id]);
        $n = 0; foreach ($X['labour'] as $l) {
            $sid = (int)($bucketSection[$l['bucket']] ?? 0); if (!isset($sec[$sid])) $sid = 0;
            $card = $l['card'] !== '' && isset($cards[$l['card']]) ? $l['card'] : null;   // "Rate D" is a rate-card row when the portal's rate list has card D; the rate itself is the workbook's
            q('INSERT INTO sb_labour (budget_id, sort_order, code, descr, section_id, discipline_id, card, bucket, h_normal, rate, remarks) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
              [$id, ++$n * 10, mb_substr($l['code'], 0, 12) ?: null, mb_substr($l['descr'], 0, 400), $sid ?: null, $l['bucket'] === 'PM' && $pmDisc ? $pmDisc : null, $card, $l['bucket'] ?: null, $l['mh'], $l['rate'], mb_substr(trim($l['remarks'] . ($l['key'] !== '' ? ' [' . $l['key'] . ']' : '')), 0, 500) ?: null]);
        }
        $n = 0; foreach ($X['parts'] as $p) q('INSERT INTO sb_parts (budget_id, sort_order, code, descr, qty, unit, unit_cost, cost_origin, remarks) VALUES (?,?,?,?,?,?,?,?,?)', [$id, ++$n * 10, mb_substr($p['code'], 0, 20) ?: null, mb_substr($p['descr'], 0, 400), $p['qty'], mb_substr($p['unit'], 0, 12) ?: null, $p['lco'], mb_substr((string)$p['origin'], 0, 120), mb_substr($p['remarks'], 0, 500) ?: null]);
        $n = 0; foreach ($X['expenses'] as $x) q('INSERT INTO sb_expenses (budget_id, sort_order, kind, descr, cost, sale, in_total) VALUES (?,?,?,?,?,?,?)', [$id, ++$n * 10, $x['kind'], mb_substr($x['descr'], 0, 300), $x['cost'], $x['sale'], (int)$x['in_total']]);
        sb_recalc($id); sb_event($id, 'import', trim(($ref ?? '') . ' · ' . $fileName . ' · ' . count($X['labour']) . ' L / ' . count($X['parts']) . ' P', ' ·'));
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Default section per ERP bucket: the first active section of the matching stage. */
function sb_bucket_defaults(): array { $d = []; foreach (SB_BUCKET_STAGE as $b => $stage) foreach (sb_sections() as $sid => $s) if ($s['stage'] === $stage) { $d[$b] = $sid; break; } return $d; }

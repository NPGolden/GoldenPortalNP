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
const SB_PRIORITIES = ['urgent', 'high', 'normal', 'low'];   // R97-006 — in the order the team handles them
const SB_PRIO_TONE = ['urgent' => 'bad', 'high' => 'gold', 'normal' => 'mute', 'low' => 'mute'];
const SB_EXP_KINDS = ['travel', 'logistics', 'pass', 'permit', 'other'];
const SB_BUCKETS = ['Engineering', 'Site', 'Workshop', 'Commissioning', 'PM'];
const SB_BUCKET_STAGE = ['Engineering' => 'engineering', 'Workshop' => 'production', 'Site' => 'installation', 'Commissioning' => 'commissioning'];
const SB_IMPORT_MAX = 5242880;   // 5 MB workbook
const SB_UPLOADS_HOUR = 20;      // R97-005: workbook uploads per person and hour
const SB_MAX_REVISIONS = 50;     // R97-005: stored revisions per budget

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
function sb_prio(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW COLUMNS FROM sb_budgets LIKE 'priority'"); } catch (Throwable $e) { $h = false; } } return $h; }
function sb_prio_pill(?string $p): string { $p = in_array($p, SB_PRIORITIES, true) ? $p : 'normal'; return pill(SB_PRIO_TONE[$p], t('sb.pr_' . $p)); }
/** Set the priority of a budget — allowed while it still needs work or waits for its offer (draft, working, finished). */
function sb_set_priority(int $id, string $p): void {
    sb_guard(); if (!sb_prio()) throw new RuntimeException(t('err.noaccess')); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound'));
    if (!in_array($p, SB_PRIORITIES, true)) throw new RuntimeException(t('sb.pr_bad')); if (!in_array($B['status'], ['draft', 'working', 'finished'], true) || !empty($B['is_template'])) throw new RuntimeException(t('sb.locked'));
    $old = (string)($B['priority'] ?? 'normal'); if ($old === $p) return;
    q('UPDATE sb_budgets SET priority = ? WHERE id = ?', [$p, $id]); sb_event($id, 'priority', t('sb.pr_' . $old) . ' → ' . t('sb.pr_' . $p));
}
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
function sb_ev_ip(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW COLUMNS FROM sb_events LIKE 'ip'"); } catch (Throwable $e) { $h = false; } } return $h; }
/** One line of the module's audit trail: who, what, on which budget, from which address (R97-005). Kept when the budget is deleted. */
function sb_event(int $id, string $kind, ?string $note = null): void {
    $uid = (int)(current_user()['id'] ?? 0) ?: null; $note = $note !== null ? mb_substr($note, 0, 300) : null;
    try { if (sb_ev_ip()) q('INSERT INTO sb_events (budget_id, user_id, kind, note, ip) VALUES (?,?,?,?,?)', [$id, $uid, $kind, $note, function_exists('tk_ip') ? mb_substr((string) tk_ip(), 0, 45) : mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45)]); else q('INSERT INTO sb_events (budget_id, user_id, kind, note) VALUES (?,?,?,?)', [$id, $uid, $kind, $note]); } catch (Throwable $e) {}
}
function sb_events(int $id): array { return all('SELECT v.*, u.full_name FROM sb_events v LEFT JOIN customer_users u ON u.id = v.user_id WHERE v.budget_id = ? ORDER BY v.id DESC LIMIT 60', [$id]); }
function sb_comments(int $id): array { return all('SELECT c.*, u.full_name FROM sb_comments c LEFT JOIN customer_users u ON u.id = c.user_id WHERE c.budget_id = ? ORDER BY c.id', [$id]); }

function sb_list(array $f = []): array {
    if (!sb_has()) return [];
    $w = [sb_r2() ? 'b.is_template = 0' : '1=1']; $b = [];
    $st = (string)($f['status'] ?? 'open');
    if ($st === 'open') $w[] = "b.status IN ('draft','working')"; elseif (in_array($st, SB_STATUSES, true)) { $w[] = 'b.status = ?'; $b[] = $st; }
    if (!empty($f['project'])) { $w[] = 'b.project_id = ?'; $b[] = (int)$f['project']; }
    if (!empty($f['owner'])) { $w[] = 'b.owner_user_id = ?'; $b[] = (int)$f['owner']; }
    $pr = sb_prio(); if ($pr && in_array($f['priority'] ?? '', SB_PRIORITIES, true)) { $w[] = 'b.priority = ?'; $b[] = $f['priority']; }
    $qq = trim((string)($f['q'] ?? '')); if ($qq !== '') { $w[] = '(b.sb_no LIKE ? OR b.title LIKE ? OR b.source_ref LIKE ? OR p.project_code LIKE ?)'; $like = '%' . $qq . '%'; array_push($b, $like, $like, $like, $like); }
    return all('SELECT b.*, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name, ou.full_name AS owner_name,
                       (SELECT COUNT(*) FROM sb_labour l WHERE l.budget_id = b.id) AS n_lab, (SELECT COUNT(*) FROM sb_parts x WHERE x.budget_id = b.id) AS n_parts, (SELECT COUNT(*) FROM sb_comments c WHERE c.budget_id = b.id) AS n_com
                FROM sb_budgets b LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN subprojects s ON s.id = b.subproject_id LEFT JOIN customer_users ou ON ou.id = b.owner_user_id
                WHERE ' . implode(' AND ', $w) . ' ORDER BY ' . ($pr ? "(b.status IN ('draft','working','finished')) DESC, CASE WHEN b.status IN ('draft','working','finished') THEN FIELD(b.priority,'urgent','high','normal','low') ELSE 9 END, CASE WHEN b.status IN ('draft','working','finished') THEN COALESCE(b.target_date, '9999-12-31') ELSE '9999-12-31' END, " : '') . 'b.updated_at DESC, b.id DESC LIMIT 500', $b);   // R97-006: what to handle first — priority, then the nearest target date
}
/** Dashboard figures: working / finished not converted / converted this year / past target / average days to finish. */
function sb_dash(): array {
    $D = ['open' => 0, 'finished' => 0, 'converted' => 0, 'late' => 0, 'avg_days' => null, 'open_sale' => 0.0, 'finished_sale' => 0.0, 'urgent' => 0, 'high' => 0];
    if (!sb_has()) return $D;
    if (sb_prio()) foreach (all("SELECT priority, COUNT(*) n FROM sb_budgets WHERE status IN ('draft','working') AND priority IN ('urgent','high')" . (sb_r2() ? ' AND is_template = 0' : '') . ' GROUP BY priority') as $r) $D[(string)$r['priority']] = (int)$r['n'];
    foreach (all("SELECT status, COUNT(*) n, COALESCE(SUM(tot_sale),0) s, SUM(target_date IS NOT NULL AND target_date < CURDATE()) late FROM sb_budgets WHERE status IN ('draft','working','finished')" . (sb_r2() ? ' AND is_template = 0' : '') . " GROUP BY status") as $r) {
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
        else { if ($count >= SB_MAX_PARTS) { $over = true; continue; } q('INSERT INTO sb_parts (code, descr, qty, unit, unit_cost, margin_pct, remarks, sort_order, budget_id) VALUES (?,?,?,?,?,?,?,?,?)', array_merge($vals, [9000 + (++$sort), $id])); $count++; }
        $n++;
    }
    if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
    sb_recalc($id); sb_event($id, 'parts', (string)$n);
    if (!empty($over)) throw new RuntimeException(t('sb.parts_max', ['n' => SB_MAX_PARTS]));   // what fitted is saved and totalled; the lines beyond the limit are refused
    return $n;
}
/** Lowest known unit cost of an inventory item and where it comes from (lowest price on record, else last price, else average cost). */
function sb_item_cost(array $i): array {
    $low = null; try { $low = one("SELECT p.unit_price, p.priced_at, co.name AS supplier FROM inv_prices p LEFT JOIN companies co ON co.id = p.supplier_id WHERE p.item_id = ? AND p.source <> 'rollup' AND p.unit_price > 0 ORDER BY p.unit_price ASC, p.priced_at DESC LIMIT 1", [(int)$i['id']]); } catch (Throwable $e) {}
    if ($low) return [(float)$low['unit_price'], implode(' · ', array_filter([t('sb.org_lowest'), (string)$low['supplier'], (string)$low['priced_at']], fn($x) => $x !== ''))];
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
    sb_guard(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); if (!empty($B['is_template'])) throw new RuntimeException(t('sb.bad_status')); $from = (string)$B['status']; $uid = (int)(current_user()['id'] ?? 0);
    if ($to === 'finished' && in_array($from, ['draft', 'working'], true)) { $x = sb_finish_blockers($B); if ($x) throw new RuntimeException(t('sb.cannot_finish') . ' ' . implode(' ', $x)); q("UPDATE sb_budgets SET status = 'finished', finished_at = NOW(), finished_by = ? WHERE id = ?", [$uid ?: null, $id]); }
    elseif ($to === 'working' && in_array($from, ['finished', 'cancelled'], true)) { if (!sb_can_reopen()) throw new RuntimeException(t('sb.reopen_mgmt')); q("UPDATE sb_budgets SET status = 'working', finished_at = NULL, finished_by = NULL WHERE id = ?", [$id]); }
    elseif ($to === 'working' && $from === 'converted') {   // R97-004: only when the offer it became no longer exists (deleted draft) — management
        if (!sb_can_reopen()) throw new RuntimeException(t('sb.reopen_mgmt')); if ((int)($B['offer_id'] ?? 0) && scalar('SELECT id FROM offers WHERE id = ?', [(int)$B['offer_id']])) throw new RuntimeException(t('sb.cv_reopen_offer'));
        q("UPDATE sb_budgets SET status = 'working', finished_at = NULL, finished_by = NULL, offer_id = NULL, converted_at = NULL WHERE id = ?", [$id]); }
    elseif ($to === 'cancelled' && in_array($from, ['draft', 'working'], true)) q("UPDATE sb_budgets SET status = 'cancelled' WHERE id = ?", [$id]);
    else throw new RuntimeException(t('sb.bad_status'));
    sb_event($id, 'status', $from . ' → ' . $to);
}
function sb_comment_add(int $id, string $body): void { sb_guard(); if (!sb_get($id)) throw new RuntimeException(t('err.notfound')); $body = mb_substr(trim($body), 0, 4000); if ($body === '') throw new RuntimeException(t('sb.need_comment')); q('INSERT INTO sb_comments (budget_id, user_id, body) VALUES (?,?,?)', [$id, (int)(current_user()['id'] ?? 0) ?: null, $body]); }
function sb_delete(int $id): void { require_cp_admin(); $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound')); if (!in_array($B['status'], ['draft', 'cancelled'], true)) throw new RuntimeException(t('sb.del_only_draft')); sb_event($id, 'deleted', sb_label($B) . ' · ' . mb_substr((string)$B['title'], 0, 200)); foreach (['sb_labour', 'sb_parts', 'sb_expenses', 'sb_comments'] as $tb) q("DELETE FROM $tb WHERE budget_id = ?", [$id]); if (function_exists('sb_r2') && sb_r2()) q('DELETE FROM sb_revisions WHERE budget_id = ?', [$id]); if (function_exists('sb_r3') && sb_r3()) q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); q('DELETE FROM sb_budgets WHERE id = ?', [$id]); }   // R97-005: the events stay as the audit trail

/* ───────────── Import of the GLD-BUD budget working file (Excel built by the golden-budget-workfile skill) ───────────── */

function sb_imp_dir(): string { $d = cp_storage() . '/sbimp'; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
function sb_imp_path(string $token): ?string { if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null; $own = $_SESSION['sb_imp'][$token] ?? null; if (!$own) return null; $p = sb_imp_dir() . '/' . $token . '.xlsx'; return is_file($p) ? $p : null; }
/** Keep the uploaded workbook for the review step; returns its token (session-bound, random, files older than a day are removed). */
/** At most SB_UPLOADS_HOUR workbook uploads per person and hour (working file and BOM) — each attempt is an audit line. */
function sb_upload_guard(int $budgetId = 0, string $name = ''): void {
    $uid = (int)(current_user()['id'] ?? 0); $n = 0; try { $n = (int) scalar("SELECT COUNT(*) FROM sb_events WHERE user_id = ? AND kind = 'upload' AND created_at >= NOW() - INTERVAL 1 HOUR", [$uid]); } catch (Throwable $e) {}
    if ($n >= SB_UPLOADS_HOUR) throw new RuntimeException(t('sb.upload_limit', ['n' => SB_UPLOADS_HOUR]));
    sb_event($budgetId, 'upload', mb_substr(basename($name), 0, 200));
}
function sb_imp_stash(array $file): string {
    sb_guard(); sb_upload_guard((int)($_POST['into'] ?? 0), (string)($file['name'] ?? ''));
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
    sb_guard(); $h = $X['head']; $sec = sb_sections(); $dis = sb_disciplines(); $pmDisc = 0; foreach ($dis as $did => $nm) if (!$pmDisc && preg_match('/project\s*manag|^pm$/i', $nm)) $pmDisc = $did;
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
function sb_bucket_defaults(): array {
    $d = []; $S = sb_sections();
    foreach (SB_BUCKET_STAGE as $b => $stage) foreach ($S as $sid => $s) if ($s['stage'] === $stage) { $d[$b] = $sid; break; }
    // R97-007: the stage names differ between installations — find the section by what it is called as well, so no bucket is left without one
    $byName = ['Site' => '/field|install|site|fld/i', 'Workshop' => '/production|workshop|prd/i', 'Commissioning' => '/commission|com$/i', 'Engineering' => '/electrical|engineering|ele$/i'];
    foreach ($byName as $b => $rx) if (!isset($d[$b])) foreach ($S as $sid => $s) if (preg_match($rx, (string)$s['name']) || preg_match($rx, (string)$s['code'])) { $d[$b] = $sid; break; }
    return $d;
}

/* ───────────── Release 2 (GAS-CP-R97-002): revisions, copy, templates, start from a ticket, Excel export ───────────── */

function sb_r2(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW TABLES LIKE 'sb_revisions'"); } catch (Throwable $e) { $h = false; } } return $h; }
const SB_SNAP_HEAD = ['title', 'description', 'currency', 'zone', 'parts_margin', 'flat_on', 'flat_rate', 'cost_rate', 'allow_rate', 'allow_cost', 'commission_pct', 'target_date', 'source_ref', 'source_file'];
const SB_SNAP_LAB = ['sort_order', 'code', 'descr', 'section_id', 'discipline_id', 'card', 'bucket', 'h_normal', 'h_travel', 'h_overtime', 'allow_days', 'rate', 'rate_ot', 'remarks'];
const SB_SNAP_PART = ['sort_order', 'code', 'item_id', 'descr', 'qty', 'unit', 'unit_cost', 'margin_pct', 'cost_origin', 'remarks'];
const SB_SNAP_EXP = ['sort_order', 'kind', 'descr', 'cost', 'sale', 'in_total'];
/** The state of a budget as plain data: header settings and the three line lists (no ids). */
function sb_snapshot(array $B): array {
    $pick = fn(array $r, array $keys) => array_intersect_key($r, array_flip($keys));
    return ['head' => $pick($B, SB_SNAP_HEAD), 'labour' => array_map(fn($r) => $pick($r, SB_SNAP_LAB), $B['labour']), 'parts' => array_map(fn($r) => $pick($r, SB_SNAP_PART), $B['parts']), 'expenses' => array_map(fn($r) => $pick($r, SB_SNAP_EXP), $B['expenses'])];
}
/** Write the lines (and, when asked, the header settings) of a snapshot into a budget, replacing what is there. */
function sb_write_snapshot(int $id, array $S, bool $withHead): void {
    foreach (['sb_labour', 'sb_parts', 'sb_expenses'] as $tb) q("DELETE FROM $tb WHERE budget_id = ?", [$id]);
    $ins = function (string $tb, array $keys, array $rows) use ($id) { foreach ($rows as $r) { if (!is_array($r)) continue; $vals = []; foreach ($keys as $k) $vals[] = $r[$k] ?? null; q("INSERT INTO $tb (budget_id, " . implode(', ', $keys) . ') VALUES (?' . str_repeat(',?', count($keys)) . ')', array_merge([$id], $vals)); } };
    $ins('sb_labour', SB_SNAP_LAB, array_slice((array)($S['labour'] ?? []), 0, 500)); $ins('sb_parts', SB_SNAP_PART, array_slice((array)($S['parts'] ?? []), 0, SB_MAX_PARTS)); $ins('sb_expenses', SB_SNAP_EXP, array_slice((array)($S['expenses'] ?? []), 0, 200));
    if ($withHead && is_array($S['head'] ?? null)) { $set = []; $b = []; foreach (SB_SNAP_HEAD as $k) if (array_key_exists($k, $S['head']) && $k !== 'title') { $set[] = "$k = ?"; $b[] = $S['head'][$k]; } if ($set) { $b[] = $id; q('UPDATE sb_budgets SET ' . implode(', ', $set) . ' WHERE id = ?', $b); } }
    sb_recalc($id);
}
/** Close the current revision (kept as a snapshot) and continue on the next one: R0 → R1 … Only while the budget is not finished. */
function sb_revise(int $id, string $note = ''): int {
    $B = sb_load_editable($id); if (!sb_r2()) throw new RuntimeException(t('err.noaccess')); if (!empty($B['is_template'])) throw new RuntimeException(t('sb.tpl_no_rev'));
    $T = sb_totals($B); $rev = (int)$B['revision'];
    if ((int) scalar('SELECT COUNT(*) FROM sb_revisions WHERE budget_id = ?', [$id]) >= SB_MAX_REVISIONS) throw new RuntimeException(t('sb.rev_max', ['n' => SB_MAX_REVISIONS]));
    q('INSERT INTO sb_revisions (budget_id, revision, note, snapshot, tot_hours, tot_cost, tot_sale, created_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE note = VALUES(note), snapshot = VALUES(snapshot), tot_hours = VALUES(tot_hours), tot_cost = VALUES(tot_cost), tot_sale = VALUES(tot_sale)',
      [$id, $rev, mb_substr(trim($note), 0, 200) ?: null, json_encode(sb_snapshot($B), JSON_UNESCAPED_UNICODE), $T['hours'], $T['cost'], $T['sale'], (int)(current_user()['id'] ?? 0) ?: null]);
    q('UPDATE sb_budgets SET revision = ? WHERE id = ?', [$rev + 1, $id]); sb_event($id, 'revision', 'R' . $rev . ' → R' . ($rev + 1) . (trim($note) !== '' ? ' · ' . trim($note) : '')); return $rev + 1;
}
function sb_revisions(int $id): array { return sb_r2() ? all('SELECT r.id, r.revision, r.note, r.tot_hours, r.tot_cost, r.tot_sale, r.created_at, u.full_name FROM sb_revisions r LEFT JOIN customer_users u ON u.id = r.created_by WHERE r.budget_id = ? ORDER BY r.revision DESC', [$id]) : []; }
/** A stored revision shaped like a budget (read-only): the budget's identity with the snapshot's settings and lines. */
function sb_revision_get(array $B, int $rev): ?array {
    if (!sb_r2()) return null; $r = one('SELECT * FROM sb_revisions WHERE budget_id = ? AND revision = ?', [(int)$B['id'], $rev]); if (!$r) return null;
    $S = json_decode((string)$r['snapshot'], true); if (!is_array($S)) return null;
    $V = array_merge($B, array_intersect_key((array)($S['head'] ?? []), array_flip(SB_SNAP_HEAD))); $V['revision'] = $rev; $V['_rev'] = $r; $n = 0;
    $V['labour'] = array_map(function ($l) use (&$n) { return $l + ['id' => 'r' . (++$n)]; }, array_values((array)($S['labour'] ?? [])));
    $V['parts'] = array_map(function ($p) use (&$n) { return $p + ['id' => 'r' . (++$n), 'item_code' => null]; }, array_values((array)($S['parts'] ?? [])));
    $V['expenses'] = array_map(function ($x) use (&$n) { return $x + ['id' => 'r' . (++$n)]; }, array_values((array)($S['expenses'] ?? [])));
    return $V;
}
/** Bring a stored revision back as the working state: the current state is first kept as its own revision, so nothing is lost. */
function sb_restore(int $id, int $rev): int {
    $B = sb_load_editable($id); $V = sb_revision_get($B, $rev); if (!$V) throw new RuntimeException(t('err.notfound'));
    $pdo = db(); $pdo->beginTransaction();
    try { $new = sb_revise($id, t('sb.rev_before_restore', ['r' => 'R' . $rev])); sb_write_snapshot($id, sb_snapshot($V), true); sb_event($id, 'restore', 'R' . $rev . ' → R' . $new); $pdo->commit(); return $new; }
    catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function sb_next_tpl_no(): string { $last = (string) scalar("SELECT sb_no FROM sb_budgets WHERE sb_no LIKE 'SBT-%' ORDER BY sb_no DESC LIMIT 1"); return sprintf('SBT-%04d', $last !== '' ? (int) substr($last, -4) + 1 : 1); }
/** A new draft (or a template) with the settings, description and lines of $src — a budget, a stored revision or a template. */
function sb_copy(array $src, string $title, bool $asTemplate = false): int {
    sb_guard(); if (!sb_r2()) throw new RuntimeException(t('err.noaccess'));
    $title = mb_substr(trim($title), 0, 200); if ($title === '') $title = mb_substr((string)$src['title'], 0, 200);
    $pdo = db(); $own = !$pdo->inTransaction(); if ($own) $pdo->beginTransaction();
    try {
        $id = sb_create($title); $S = sb_snapshot($src); unset($S['head']['target_date']); if ($asTemplate) { unset($S['head']['source_ref'], $S['head']['source_file']); }
        sb_write_snapshot($id, $S, true);
        if ($asTemplate) { for ($try = 0; $try < 5; $try++) { try { q('UPDATE sb_budgets SET is_template = 1, sb_no = ?, copied_from = ? WHERE id = ?', [sb_next_tpl_no(), (int)$src['id'], $id]); break; } catch (Throwable $e) { if ($try === 4) throw $e; } } }
        else q('UPDATE sb_budgets SET copied_from = ? WHERE id = ?', [(int)$src['id'], $id]);
        sb_event($id, 'copy', sb_label($src) . ' · ' . mb_substr((string)$src['title'], 0, 120));
        if ($own) $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function sb_templates(): array { return sb_r2() ? all('SELECT b.*, (SELECT COUNT(*) FROM sb_labour l WHERE l.budget_id = b.id) AS n_lab, (SELECT COUNT(*) FROM sb_parts x WHERE x.budget_id = b.id) AS n_parts FROM sb_budgets b WHERE b.is_template = 1 ORDER BY b.title') : []; }
function sb_delete_template(int $id): void { if (!sb_can_reopen()) throw new RuntimeException(t('sb.tpl_del_mgmt')); $B = sb_get($id); if (!$B || empty($B['is_template'])) throw new RuntimeException(t('err.notfound')); sb_event($id, 'deleted', (string)$B['sb_no'] . ' · ' . mb_substr((string)$B['title'], 0, 200)); foreach (['sb_labour', 'sb_parts', 'sb_expenses', 'sb_comments'] as $tb) q("DELETE FROM $tb WHERE budget_id = ?", [$id]); if (sb_r3()) q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); q('DELETE FROM sb_budgets WHERE id = ? AND is_template = 1', [$id]); }

/** The live System Budget of a ticket (not cancelled), if any. */
function sb_of_ticket(int $ticketId): ?array { if (!sb_has() || $ticketId <= 0) return null; return one("SELECT id, sb_no, revision, title, status FROM sb_budgets WHERE ticket_id = ? AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", [$ticketId]); }
/**
 * Convert a ticket request to a System Budget: the budget takes the ticket's title, text, project and sub-project and is linked to it;
 * an open ticket is resolved automatically. The customer reads only "your request is being quoted" — no number, no link (design OP-08).
 */
function sb_from_ticket(int $ticketId): int {
    sb_guard(); if (!function_exists('tk_load')) throw new RuntimeException(t('err.noaccess'));
    $tk = tk_load($ticketId); if (!$tk) throw new RuntimeException(t('err.notfound'));
    if ($ex = sb_of_ticket($ticketId)) return (int)$ex['id'];
    $pdo = db(); $pdo->beginTransaction();
    try {
        $id = sb_create(mb_substr(trim((string)$tk['ticket_no'] . ' · ' . (string)$tk['title']), 0, 200));
        $pid = (int)($tk['project_id'] ?? 0); $sid = (int)($tk['subproject_id'] ?? 0); if ($sid && !$pid) $pid = (int) scalar('SELECT project_id FROM subprojects WHERE id = ?', [$sid]);
        $desc = '**' . t('sb.tk_request', ['n' => (string)$tk['ticket_no']]) . '**' . "\n" . trim((string)$tk['description']);
        q('UPDATE sb_budgets SET ticket_id = ?, project_id = ?, subproject_id = ?, description = ? WHERE id = ?', [$ticketId, $pid ?: null, $sid ?: null, mb_substr($desc, 0, 60000), $id]);
        if (sb_prio() && in_array($tk['priority'] ?? '', SB_PRIORITIES, true)) q('UPDATE sb_budgets SET priority = ? WHERE id = ?', [(string)$tk['priority'], $id]);   // R97-006: the request's priority carries over
        if (in_array($tk['status'], tk_open_statuses(), true)) tk_set_status($ticketId, 'resolved', t('sb.tk_resolution'));
        sb_event($id, 'ticket', (string)$tk['ticket_no']);   // nothing is written on the ticket's own timeline — the customer reads it
        $pdo->commit(); return $id;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/** Excel of a budget or a stored revision: Summary, Labour, Parts, Other costs — cost and sale columns side by side. Internal document. */
function sb_export_xlsx(array $B): void {
    sb_guard(); require_once __DIR__ . '/xlsxw.php'; $T = sb_totals($B); $sec = sb_sections(); $dis = sb_disciplines(); $r2 = fn(float $v) => round($v, 2);
    $sum = [[t('sb.no'), sb_label($B)], [t('sb.f_title'), (string)$B['title']], [t('th.status'), t('sb.st_' . $B['status'])], [t('sb.priority'), t('sb.pr_' . (in_array($B['priority'] ?? '', SB_PRIORITIES, true) ? $B['priority'] : 'normal'))], [t('sb.project'), trim((string)($B['project_code'] ?? '') . ' ' . (string)($B['sub_code'] ?? '') . ' ' . (string)($B['sub_name'] ?? ''))], [t('sb.owner'), (string)($B['owner_name'] ?? '')], [t('sb.target'), (string)($B['target_date'] ?? '')],
            [t('sb.parts_margin'), (float)$B['parts_margin']], [t('sb.flat'), (int)$B['flat_on'] ? (float)$B['flat_rate'] : '—'], [t('sb.cost_rate'), (float)$B['cost_rate']], [t('sb.commission'), (float)$B['commission_pct']], ['', ''],
            [t('sb.labour') . ' — ' . t('sb.hours'), $r2($T['hours'])], [t('sb.labour') . ' — ' . t('sb.cost'), $r2($T['lab_cost'])], [t('sb.labour') . ' — ' . t('sb.sale'), $r2($T['lab_sale'])], [t('sb.parts') . ' — ' . t('sb.cost'), $r2($T['parts_cost'])], [t('sb.parts') . ' — ' . t('sb.sale'), $r2($T['parts_sale'])],
            [t('sb.expenses') . ' — ' . t('sb.cost'), $r2($T['exp_cost'])], [t('sb.expenses') . ' — ' . t('sb.sale'), $r2($T['exp_sale'])], [t('sb.total') . ' — ' . t('sb.cost'), $r2($T['cost'])], [t('sb.total') . ' — ' . t('sb.sale'), $r2($T['sale'])], [t('sb.margin') . ' %', $T['margin_pct']]];
    if ((float)$B['commission_pct'] > 0) $sum[] = [t('sb.with_comm', ['p' => (string)(float)$B['commission_pct']]), $r2($T['with_commission'])];
    $lab = []; foreach ($B['labour'] as $l) { $c = sb_labour_calc($B, $l); $lab[] = [(string)$l['code'], (string)$l['descr'], (string)($sec[(int)$l['section_id']]['name'] ?? ''), (string)($dis[(int)$l['discipline_id']] ?? ''), (string)$l['card'], (float)$l['h_normal'], (float)$l['h_travel'], (float)$l['h_overtime'], (float)$l['allow_days'], $c['rate'], $c['rate_ot'], $c['cost'], $c['sale'], (string)$l['remarks']]; }
    $par = []; $n = 0; foreach ($B['parts'] as $p) { $c = sb_part_calc($B, $p); $par[] = [++$n, (string)$p['code'], (string)$p['descr'], (float)$p['qty'], (string)$p['unit'], round((float)$p['unit_cost'], 4), $c['margin'], round($c['unit_sale'], 4), $c['cost'], $c['sale'], (string)($p['cost_origin'] ?? ''), (string)$p['remarks']]; }
    $exp = []; foreach ($B['expenses'] as $x) $exp[] = [t('sb.xk_' . $x['kind']), (string)$x['descr'], (float)$x['cost'], (float)$x['sale'], (int)$x['in_total'] ? '' : t('sb.x_outside')];
    xlsx_download(preg_replace('/[^A-Za-z0-9._-]+/', '_', sb_label($B)) . '.xlsx', [
        ['name' => 'Summary', 'header' => [t('sb.title'), sb_label($B)], 'rows' => $sum, 'widths' => [44, 44]],
        ['name' => 'Labour', 'header' => [t('th.code'), t('sb.l_descr'), t('sb.section'), t('sb.discipline'), t('sb.card'), t('sb.h_normal'), t('sb.h_travel'), t('sb.h_overtime'), t('sb.days'), t('sb.rate'), t('sb.rate_ot'), t('sb.cost'), t('sb.sale'), t('th.remarks')], 'rows' => $lab, 'widths' => [10, 50, 20, 22, 10, 10, 10, 11, 11, 10, 10, 12, 12, 40]],
        ['name' => 'Parts', 'header' => ['#', t('th.code'), t('sb.p_descr'), t('sb.qty'), t('sb.unit'), t('sb.unit_cost'), t('sb.margin_pct'), t('sb.unit_sale'), t('sb.cost'), t('sb.sale'), t('sb.origin'), t('th.remarks')], 'rows' => $par, 'widths' => [5, 14, 54, 9, 7, 12, 10, 12, 12, 12, 34, 34]],
        ['name' => 'Other costs', 'header' => [t('sb.x_kind'), t('sb.l_descr'), t('sb.cost'), t('sb.sale'), ''], 'rows' => $exp, 'widths' => [26, 50, 12, 12, 20]],
    ]);
}

/* ───────────── Release 3 (GAS-CP-R97-003): BOM import with review, inventory proposals for free lines, AI description draft ───────────── */

function sb_r3(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW TABLES LIKE 'sb_import_rows'"); } catch (Throwable $e) { $h = false; } } return $h; }
function sb_ai_on(): bool { return function_exists('ai_can') && ai_can('system_budget'); }
function sb_stage_rows(int $id): array {
    if (!sb_r3()) return [];
    return all("SELECT r.*, i.code AS item_code, i.description AS item_desc, i.maker AS item_maker, i.factory_code AS item_pn, i.unit AS item_unit, i.last_price, i.last_price_at, i.avg_cost FROM sb_import_rows r LEFT JOIN inv_items i ON i.id = r.item_id WHERE r.budget_id = ? ORDER BY r.seq, r.id", [$id]);
}
/** Deterministic match of one row against the inventory — the rules of the Engineering BOM import: Golden code, maker part number, the same without spaces / dashes. */
function sb_match_row(string $code, string $pn, string $maker, array $normMap): array {
    if (!function_exists('stk_item_by_code')) return [null, 'none', 0, null];
    if ($code !== '' && ($it = stk_item_by_code($code))) return [$it, 'code', 100, t('bom.imp_r_code')];
    if ($pn !== '' && ($it = one(STK_SELECT . " WHERE i.status <> 'blocked' AND LOWER(i.factory_code) = LOWER(?)" . ($maker !== '' ? ' ORDER BY (LOWER(i.maker) = LOWER(?)) DESC' : '') . ' LIMIT 1', $maker !== '' ? [$pn, $maker] : [$pn]))) return [$it, 'part_no', 95, t('bom.imp_r_pn')];
    if ($pn !== '' && ($nk = bom_imp_norm($pn)) !== '' && isset($normMap[$nk]) && ($it = stk_item((int)($normMap[$nk][mb_strtolower($maker)] ?? reset($normMap[$nk]))))) return [$it, 'part_no_norm', 85, t('bom.imp_r_pn_norm')];
    return [null, 'none', 0, null];
}
function sb_norm_map(): array { $m = []; try { foreach (all("SELECT id, maker, factory_code FROM inv_items WHERE status <> 'blocked' AND factory_code IS NOT NULL AND factory_code <> ''") as $x) { $k = bom_imp_norm((string)$x['factory_code']); if ($k !== '') $m[$k][mb_strtolower((string)$x['maker'])] = (int)$x['id']; } } catch (Throwable $e) {} return $m; }
/** Ask the assistant to choose among inventory candidates for the staged rows still unmatched (batches of 25, at most 124). Only descriptions, makers, part numbers and units leave the portal. */
function sb_stage_ai(int $id): int {
    if (!sb_ai_on() || !function_exists('bom_imp_candidates')) return 0;
    $rows = all("SELECT * FROM sb_import_rows WHERE budget_id = ? AND match_kind = 'none' AND decision = 'pending' ORDER BY seq LIMIT " . SB_MAX_PARTS, [$id]); $done = 0;
    foreach (array_chunk($rows, 25) as $chunk) {
        $lines = []; $cands = [];
        foreach ($chunk as $r) {
            $c = bom_imp_candidates($r); if (!$c) continue; $cands[(int)$r['seq']] = array_column($c, null, 'id');
            $lines[] = 'Row ' . $r['seq'] . ': ' . $r['description'] . ($r['maker'] ? ' | maker ' . $r['maker'] : '') . ($r['part_no'] ? ' | part no ' . $r['part_no'] : '') . ' | unit ' . $r['unit']
                . "\n  candidates: " . implode('; ', array_map(fn($x) => $x['id'] . ' [' . $x['code'] . '] ' . $x['description'] . ($x['maker'] ? ' · ' . $x['maker'] : '') . ($x['factory_code'] ? ' · pn ' . $x['factory_code'] : '') . ' · ' . $x['unit'], $c));
        }
        if (!$lines) continue;
        $res = ai_run('match', [], implode("\n", $lines), null, 'system_budget'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
        foreach ((array)($res['matches'] ?? []) as $seq => $m) {
            if (!isset($cands[$seq])) continue;
            if (empty($m['item']) || !isset($cands[$seq][$m['item']]) || $m['confidence'] < 50) { if ($m['reason'] !== '') q("UPDATE sb_import_rows SET reason = ? WHERE budget_id = ? AND seq = ? AND match_kind = 'none'", [mb_substr('AI: ' . $m['reason'], 0, 255), $id, $seq]); continue; }
            q("UPDATE sb_import_rows SET item_id = ?, match_kind = 'ai', confidence = ?, reason = ? WHERE budget_id = ? AND seq = ? AND match_kind = 'none'", [(int)$m['item'], (int)$m['confidence'], mb_substr('AI: ' . $m['reason'], 0, 255), $id, $seq]); $done++;
        }
    }
    return $done;
}
/** Stage a BOM file (the Engineering BOM template; a unit price / cost column is read when present): parse, match, keep for review. Nothing is written to the budget. */
function sb_bom_stage(int $id, array $file): array {
    $B = sb_load_editable($id); if (!sb_r3() || !function_exists('bom_imp_norm')) throw new RuntimeException(t('err.noaccess')); sb_upload_guard($id, (string)($file['name'] ?? ''));
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException(t('sb.imp_nofile'));
    if ((int)$file['size'] > SB_IMPORT_MAX || strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== 'xlsx') throw new RuntimeException(t('sb.imp_badfile'));
    require_once __DIR__ . '/xlsx.php'; $rows = xlsx_rows((string)$file['tmp_name']); if (count($rows) < 2) throw new RuntimeException(t('sb.bom_empty'));
    $H = ['section' => ['section', 'τμήμα', 'area', 'system', 'panel', 'location'], 'qty' => ['qty', 'quantity', 'q.ty', 'ποσότητα', 'ποσ.', 'pcs', 'amount'], 'unit' => ['unit', 'um', 'uom', 'μ.μ', 'μ.μ.', 'μον.'],
          'description' => ['description', 'desc', 'περιγραφή', 'designation', 'item description', 'material'], 'maker' => ['maker', 'manufacturer', 'brand', 'κατασκευαστής', 'make', 'vendor'],
          'part_no' => ['maker part no', 'part no', 'part no.', 'part number', 'p/n', 'pn', 'type', 'type no', 'article', 'article no', 'order code', 'factory code', 'κωδ. κατασκευαστή', 'ref'], 'code' => ['golden code', 'code', 'κωδικός', 'κωδ.', 'item code', 'our code', 'stock code'], 'note' => ['note', 'notes', 'remark', 'remarks', 'σημείωση', 'comment'],
          'price' => ['unit price', 'unit cost', 'price', 'cost', 'unit lco', 'unit lco (€)', 'lco', 'τιμή', 'τιμή μονάδας', 'κόστος']];
    $hdrIdx = 0; foreach ($rows as $ri => $r) { $hits = 0; foreach ($r as $c) { $n = mb_strtolower(trim((string)$c)); foreach ($H as $alts) if ($n !== '' && in_array($n, $alts, true)) $hits++; } if ($hits >= 2) { $hdrIdx = $ri; break; } if ($ri > 10) break; }
    $map = []; foreach ($rows[$hdrIdx] as $i => $h) { $n = mb_strtolower(trim((string)$h)); if ($n === '') continue; foreach ($H as $k => $alts) if (!isset($map[$k]) && in_array($n, $alts, true)) { $map[$k] = $i; break; } }
    if (!isset($map['description'])) throw new RuntimeException(t('bom.imp_need_desc_col'));
    $num = function ($v): ?float { $v = str_replace(['€', ' '], '', trim((string)$v)); if ($v === '') return null; if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $v)) $v = str_replace('.', '', $v); $v = str_replace(',', '.', $v); return is_numeric($v) ? (float)$v : null; };
    q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); $normMap = sb_norm_map(); $seq = 0; $st = ['rows' => 0, 'matched' => 0, 'none' => 0, 'ai' => 0, 'warn' => []];
    foreach (array_slice($rows, $hdrIdx + 1) as $r) {
        $g = fn(string $k, int $max = 255) => isset($map[$k]) ? mb_substr(trim((string)($r[$map[$k]] ?? '')), 0, $max) : '';
        $desc = $g('description', 400); $code = $g('code', 60); $pn = $g('part_no', 80); $maker = $g('maker', 80); if ($desc === '' && $code === '' && $pn === '') continue;
        [$it, $kind, $conf, $reason] = sb_match_row($code, $pn, $maker, $normMap); if ($desc === '' && $it) $desc = (string)$it['description']; if ($desc === '') continue;
        if ($seq >= 400) { $st['warn'][] = t('sb.bom_cut', ['n' => 400]); break; }
        $qty = $num($g('qty')) ?? 1.0; if ($qty <= 0) $qty = 1.0; $price = $num($g('price')); if ($price !== null && $price < 0) $price = null;
        q('INSERT INTO sb_import_rows (budget_id, seq, section, qty, unit, description, maker, part_no, code, note, unit_cost, item_id, match_kind, confidence, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$id, ++$seq, $g('section', 80) ?: null, $qty, $it ? (string)$it['unit'] : (function_exists('stk_unit_norm') ? (stk_unit_norm($g('unit', 12)) ?: 'pcs') : ($g('unit', 12) ?: 'pcs')), $desc, $maker ?: null, $pn ?: null, $code ?: null, $g('note') ?: null, $price, $it ? (int)$it['id'] : null, $kind, $conf, $reason]);
        $st['rows']++; $st[$kind === 'none' ? 'none' : 'matched']++;
    }
    if (!$st['rows']) throw new RuntimeException(t('sb.bom_empty'));
    if ($st['none'] && sb_ai_on()) { try { $st['ai'] = sb_stage_ai($id); } catch (Throwable $e) { $st['warn'][] = 'AI: ' . $e->getMessage(); } }
    sb_event($id, 'bom_staged', mb_substr(basename((string)$file['name']), 0, 120) . ' · ' . $st['rows']); return $st;
}
/** Propose inventory items for the free part lines of the budget (no inventory link yet): code, then part numbers found in the text, then the assistant. Staged for the same review. */
function sb_propose_stage(int $id): array {
    $B = sb_load_editable($id); if (!sb_r3() || !function_exists('bom_imp_norm')) throw new RuntimeException(t('err.noaccess'));
    q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); $normMap = sb_norm_map(); $seq = 0; $st = ['rows' => 0, 'matched' => 0, 'none' => 0, 'ai' => 0, 'warn' => []];
    foreach ($B['parts'] as $p) {
        if ((int)$p['item_id'] || sb_part_excluded($p) || trim((string)$p['descr']) === '') continue;
        $code = trim((string)$p['code']); $desc = (string)$p['descr']; $it = null; $kind = 'none'; $conf = 0; $reason = null; $pn = '';
        [$it, $kind, $conf, $reason] = sb_match_row($code, '', '', $normMap);
        if (!$it) foreach (preg_split('/[\s,;()]+/u', $desc) ?: [] as $tok) {   // a maker part number written inside the description (≥ 5 characters with a digit)
            $nk = bom_imp_norm($tok); if (strlen($nk) < 5 || !preg_match('/\d/', $nk) || !isset($normMap[$nk])) continue;
            if ($it = stk_item((int) reset($normMap[$nk]))) { $kind = 'part_no_norm'; $conf = 85; $reason = t('sb.prop_r_text', ['t' => $tok]); $pn = mb_substr($tok, 0, 80); break; }
        }
        q('INSERT INTO sb_import_rows (budget_id, part_id, seq, qty, unit, description, part_no, code, unit_cost, item_id, match_kind, confidence, reason) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [$id, (int)$p['id'], ++$seq, (float)$p['qty'], $p['unit'] ?: null, mb_substr($desc, 0, 400), $pn ?: null, $code ?: null, (float)$p['unit_cost'] > 0 ? (float)$p['unit_cost'] : null, $it ? (int)$it['id'] : null, $kind, $conf, $reason]);
        $st['rows']++; $st[$kind === 'none' ? 'none' : 'matched']++;
    }
    if (!$st['rows']) throw new RuntimeException(t('sb.prop_none'));
    if ($st['none'] && sb_ai_on()) { try { $st['ai'] = sb_stage_ai($id); } catch (Throwable $e) { $st['warn'][] = 'AI: ' . $e->getMessage(); } }
    sb_event($id, 'proposed', (string)$st['rows']); return $st;
}
function sb_stage_discard(int $id): void { sb_load_editable($id); if (sb_r3()) q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); }
/**
 * Apply the review. Per row: accept = use the proposed (or the chosen) inventory item; free = keep as a free line; skip = leave out.
 * BOM rows become part lines (cost: the file's price, else the item's lowest price on record); rows of existing lines only link the line
 * to the item (its cost is filled from the inventory only when the line had none). Returns counts.
 */
function sb_stage_apply(int $id, array $dec, array $items): array {
    $B = sb_load_editable($id); $rows = sb_stage_rows($id); $count = count($B['parts']); $n = ['accept' => 0, 'free' => 0, 'skip' => 0, 'left' => 0]; $pdo = db(); $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $rid = (int)$r['id']; $d = (string)($dec[$rid] ?? 'skip'); if (!in_array($d, ['accept', 'free', 'skip'], true)) $d = 'skip';
            $it = null; if ($d === 'accept') { $iid = (int)($items[$rid] ?? 0) ?: (int)$r['item_id']; $it = $iid ? one("SELECT * FROM inv_items WHERE id = ? AND status <> 'blocked'", [$iid]) : null; if (!$it) $d = (int)$r['part_id'] ? 'skip' : 'free'; }
            if ((int)$r['part_id']) {   // an existing free line
                if ($d === 'accept') { [$c, $org] = sb_item_cost($it); $has = (float)($r['unit_cost'] ?? 0) > 0;
                    q('UPDATE sb_parts SET item_id = ?, unit_cost = ?, cost_origin = ?, unit = COALESCE(NULLIF(unit, \'\'), ?) WHERE id = ? AND budget_id = ?', [(int)$it['id'], $has ? (float)$r['unit_cost'] : $c, mb_substr($has ? t('sb.org_kept', ['c' => (string)$it['code']]) : $org, 0, 120), $it['unit'] ?: null, (int)$r['part_id'], $id]); $n['accept']++; }
                else $n['skip']++;
            } elseif ($d !== 'skip') {
                if ($count >= SB_MAX_PARTS) { $n['left']++; continue; }
                $cost = $r['unit_cost'] !== null ? (float)$r['unit_cost'] : 0.0; $org = $r['unit_cost'] !== null ? t('sb.org_file') : null;
                if ($it && $r['unit_cost'] === null) [$cost, $org] = sb_item_cost($it);
                $descr = trim((string)$r['description'] . ($r['maker'] ? ' · ' . $r['maker'] : '') . ($r['part_no'] ? ' · ' . $r['part_no'] : ''));
                q('INSERT INTO sb_parts (budget_id, sort_order, code, item_id, descr, qty, unit, unit_cost, cost_origin, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)', [$id, 9600 + (int)$r['seq'], mb_substr((string)($it ? $it['code'] : $r['code']), 0, 20) ?: null, $it ? (int)$it['id'] : null, mb_substr($descr, 0, 400), (float)$r['qty'], $it ? ($it['unit'] ?: $r['unit']) : $r['unit'], $cost, $org !== null ? mb_substr($org, 0, 120) : null, mb_substr(trim((string)$r['section'] . ($r['note'] ? ' · ' . $r['note'] : ''), ' ·'), 0, 500) ?: null]);
                $count++; $n[$it ? 'accept' : 'free']++;
            } else $n['skip']++;
        }
        q('DELETE FROM sb_import_rows WHERE budget_id = ?', [$id]); if ($B['status'] === 'draft') q("UPDATE sb_budgets SET status = 'working' WHERE id = ?", [$id]);
        sb_recalc($id); sb_event($id, 'bom_applied', $n['accept'] . ' / ' . $n['free'] . ' / ' . $n['skip']); $pdo->commit(); return $n;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
/** Draft of the description by the assistant, from the technical content only: title, work packages with hours, parts with quantities, kinds of other costs. No prices, costs, margins or people leave the portal. Returns the text — nothing is saved. */
function sb_ai_description(int $id, string $ask = ''): string {
    $B = sb_load_editable($id); if (!sb_ai_on()) throw new RuntimeException(t('sb.ai_off'));
    $sec = sb_sections(); $L = ['System budget title: ' . $B['title']];
    if ($B['labour']) { $L[] = 'Work packages (code | description | section | hours):'; foreach ($B['labour'] as $l) { $c = sb_labour_calc($B, $l); $L[] = '- ' . trim((string)$l['code']) . ' | ' . $l['descr'] . ' | ' . (string)($sec[(int)$l['section_id']]['name'] ?? ($l['bucket'] ?? '')) . ' | ' . sb_h($c['hours']) . ' h' . ($c['hours'] <= 0 ? ' (excluded / by others)' : '') . ($l['remarks'] ? ' | ' . mb_substr((string)$l['remarks'], 0, 160) : ''); } }
    if ($B['parts']) { $L[] = 'Parts (code | description | quantity | unit):'; foreach (array_slice($B['parts'], 0, SB_MAX_PARTS) as $p) $L[] = '- ' . trim((string)$p['code']) . ' | ' . $p['descr'] . ' | ' . sb_h((float)$p['qty']) . ' | ' . (string)$p['unit'] . (sb_part_excluded($p) ? ' | EXCLUDED' : ''); }
    if ($B['expenses']) $L[] = 'Other cost headings: ' . implode(', ', array_unique(array_map(fn($x) => (string)$x['descr'], $B['expenses'])));
    if (trim((string)$B['description']) !== '') $L[] = "Existing description (keep its facts):\n" . mb_substr(rt_plain((string)$B['description']), 0, 3000);
    $prompt = "Write the Description of this internal system budget for the sales department: (1) Scope of supply and works — one short paragraph; (2) Included — bullets grouped engineering / materials / installation / commissioning as applicable; (3) Excluded / by others — from the lines marked excluded or at 0 hours; (4) Assumptions and open points — only what the data supports. "
            . "Use only the data below; do not invent quantities, makers, standards or dates; do not mention prices. Plain text with '- ' bullets and **bold** headings, at most 250 words." . (trim($ask) !== '' ? "\nExtra instruction from the user: " . mb_substr(trim($ask), 0, 400) : '') . "\n\n" . implode("\n", $L);
    $res = ai_run('write', ['title' => t('sb.title'), 'headline' => sb_label($B)], $prompt, null, 'system_budget'); if (empty($res['ok'])) throw new RuntimeException((string)($res['error'] ?? 'AI'));
    $txt = trim((string)$res['text']); if ($txt === '') throw new RuntimeException(t('ai.failed')); sb_event($id, 'ai_descr'); return mb_substr($txt, 0, 20000);
}

/* ───────────── Release 4 (GAS-CP-R97-004): convert a finished budget to an offer; feed the sub-project budget ───────────── */

function sb_r4(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW COLUMNS FROM sb_budgets LIKE 'offer_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function sb_can_convert(): bool { return sb_can() && sb_r4() && function_exists('ob_can') && ob_can(); }
/** Discipline that carries the hours of a labour line in the sub-project budget: its own; else the first discipline mapped to its section; else the discipline of its rate card. Null = cannot be fed. */
function sb_line_discipline(array $l): ?int {
    static $bySec = null, $byCard = null;
    if ($bySec === null) { $bySec = []; $byCard = []; try { foreach (all('SELECT sd.section_id, sd.discipline_id FROM eng_section_disciplines sd JOIN disciplines d ON d.id = sd.discipline_id AND d.is_active = 1 ORDER BY d.sort_order, d.id') as $r) $bySec[(int)$r['section_id']] = $bySec[(int)$r['section_id']] ?? (int)$r['discipline_id']; foreach (all("SELECT id, rate_card FROM disciplines WHERE is_active = 1 AND rate_card IS NOT NULL AND rate_card <> '' ORDER BY sort_order, id") as $r) $byCard[(string)$r['rate_card']] = $byCard[(string)$r['rate_card']] ?? (int)$r['id']; } catch (Throwable $e) {} }
    if ((int)($l['discipline_id'] ?? 0)) return (int)$l['discipline_id'];
    if ((int)($l['section_id'] ?? 0) && isset($bySec[(int)$l['section_id']])) return $bySec[(int)$l['section_id']];
    $c = trim((string)($l['card'] ?? '')); return $c !== '' && isset($byCard[$c]) ? $byCard[$c] : null;
}
/** Hours for the sub-project budget: normal + overtime of every labour line (travelling time is not production time — design OP-05), grouped by discipline and section. */
function sb_feed_plan(array $B): array {
    $rows = []; $un = []; $dis = sb_disciplines(); $sec = sb_sections(); $tot = 0.0;
    foreach ($B['labour'] as $l) {
        $h = (float)$l['h_normal'] + (float)$l['h_overtime']; if ($h <= 0) continue; $d = sb_line_discipline($l);
        if (!$d) { $un[] = ['id' => (int)$l['id'], 'code' => (string)($l['code'] ?? ''), 'descr' => (string)$l['descr'], 'hours' => $h, 'bucket' => (string)($l['bucket'] ?? ''), 'section_id' => (int)($l['section_id'] ?? 0)]; continue; }
        $s = (int)($l['section_id'] ?? 0); $k = $d . ':' . $s; if (!isset($rows[$k])) $rows[$k] = ['discipline_id' => $d, 'discipline' => (string)($dis[$d] ?? ('#' . $d)), 'section_id' => $s ?: null, 'section' => (string)($sec[$s]['name'] ?? ''), 'hours' => 0.0];
        $rows[$k]['hours'] += $h; $tot += $h;
    }
    $T = sb_totals($B); return ['rows' => array_values($rows), 'unmapped' => $un, 'hours' => $tot, 'parts' => $T['parts_cost'], 'rate' => (float)$B['cost_rate']];
}
/** Offer lines a budget becomes. $opt: labour = line|section|lump, parts = line|lump, commission = bool (prices lifted to include the commission). */
function sb_offer_lines(array $B, array $opt): array {
    $c = (float)$B['commission_pct']; $up = !empty($opt['commission']) && $c > 0 && $c < 100 ? 1 / (1 - $c / 100) : 1.0; $L = []; $sec = sb_sections(); $T = sb_totals($B);
    $add = function (string $kind, string $desc, string $unit, float $qty, float $price, ?float $cost = null, ?float $margin = null, ?int $item = null, string $erp = '', ?string $note = null) use (&$L, $up) { if ($kind !== 'text' && $qty <= 0) return; $L[] = ['kind' => $kind, 'description' => mb_substr($desc, 0, 400), 'unit' => $unit, 'qty' => round($qty, 2), 'unit_price' => round($price * $up, 2), 'cost_price' => $cost, 'margin_pct' => $margin !== null && $up !== 1.0 && $cost !== null && $cost > 0 ? round((round($price * $up, 2) / $cost - 1) * 100, 2) : $margin, 'item_id' => $item, 'erp_code' => mb_substr($erp, 0, 30), 'notes' => $note !== null ? mb_substr($note, 0, 255) : null]; };
    $lm = (string)($opt['labour'] ?? 'line'); $days = 0.0; $grp = [];
    foreach ($B['labour'] as $l) {
        $x = sb_labour_calc($B, $l); $days += (float)$l['allow_days']; $nt = (float)$l['h_normal'] + (float)$l['h_travel']; $ot = (float)$l['h_overtime']; if ($nt + $ot <= 0) continue;
        if ($lm === 'line') { $add('free', (string)$l['descr'], 'h', $nt, $x['rate'], (float)$B['cost_rate'], null, null, (string)$l['code']); $add('free', (string)$l['descr'] . ' — ' . t('sb.cv_overtime'), 'h', $ot, $x['rate_ot'], (float)$B['cost_rate'], null, null, (string)$l['code']); }
        else { $k = $lm === 'section' ? (int)($l['section_id'] ?? 0) : 0; $grp[$k] = $grp[$k] ?? ['h' => 0.0, 'sale' => 0.0]; $grp[$k]['h'] += $nt + $ot; $grp[$k]['sale'] += $nt * $x['rate'] + $ot * $x['rate_ot']; }
    }
    foreach ($grp as $k => $g) { if ($g['h'] <= 0) continue; $name = $lm === 'section' ? t('sb.cv_labour_sec', ['s' => (string)($sec[$k]['name'] ?? t('sb.cv_other_sec'))]) : t('sb.cv_labour_all'); $add('free', $name, 'h', $g['h'], $g['sale'] / $g['h'], (float)$B['cost_rate']); }
    $add('free', t('sb.cv_allowance'), t('sb.cv_day'), $days, (float)$B['allow_rate'], (float)$B['allow_cost']);
    if (($opt['parts'] ?? 'line') === 'lump') { if ($T['parts_sale'] > 0) $add('free', t('sb.cv_parts_lump'), 'lot', 1, $T['parts_sale'], $T['parts_cost'], $T['parts_cost'] > 0 ? round(($T['parts_sale'] / $T['parts_cost'] - 1) * 100, 2) : null); }
    else foreach ($B['parts'] as $p) { $x = sb_part_calc($B, $p); if (sb_part_excluded($p) || (float)$p['qty'] <= 0) continue; $add((int)($p['item_id'] ?? 0) ? 'item' : 'free', (string)$p['descr'], (string)($p['unit'] ?: 'pcs'), (float)$p['qty'], $x['unit_sale'], (float)$p['unit_cost'], $x['margin'], (int)($p['item_id'] ?? 0) ?: null, (string)$p['code']); }
    foreach ($B['expenses'] as $e) { if ((int)$e['in_total']) $add('free', (string)$e['descr'], 'lot', 1, (float)$e['sale'], (float)$e['cost']); }
    foreach ($B['expenses'] as $e) { if (!(int)$e['in_total'] && (float)$e['sale'] > 0) $L[] = ['kind' => 'text', 'description' => mb_substr((string)$e['descr'] . ' — ' . sb_money((float)$e['sale'], (string)$B['currency']) . ' — ' . t('sb.cv_separately'), 0, 400), 'unit' => '', 'qty' => 0, 'unit_price' => 0, 'cost_price' => null, 'margin_pct' => null, 'item_id' => null, 'erp_code' => '', 'notes' => null]; }
    $ex = []; foreach ($B['labour'] as $l) if ((float)$l['h_normal'] + (float)$l['h_travel'] + (float)$l['h_overtime'] <= 0 && trim((string)$l['descr']) !== '') $ex[] = (string)$l['descr']; foreach ($B['parts'] as $p) if (sb_part_excluded($p)) $ex[] = (string)$p['descr'];
    $net = 0.0; foreach ($L as $x) if ($x['kind'] !== 'text') $net += round($x['qty'] * $x['unit_price'], 2);
    return ['lines' => $L, 'net' => round($net, 2), 'excluded' => $ex, 'uplift' => $up];
}
/** The description as it goes to the offer's Scope block: the team's own text only — the block imported from a budget working file (internal document control, internal notes) and the ticket header stay inside. */
function sb_scope_text(array $B): string {
    $d = (string)($B['description'] ?? ''); $mk = '**' . t('sb.imp_d_source') . '**'; $at = strpos($d, $mk); if ($at !== false) $d = substr($d, 0, $at);
    return trim($d);
}
/**
 * Convert a finished System Budget: a DRAFT offer on its sub-project (lines, scope text, exclusions) and — as chosen — a new revision of the
 * sub-project's Budget & actual (hours per discipline and section, parts budget = parts cost). Existing budget revisions are never edited.
 * $opt: labour, parts, commission (see sb_offer_lines), budget = replace|add|none. One transaction. Returns the offer id.
 */
function sb_convert(int $id, array $opt): int {
    if (!sb_can_convert()) throw new RuntimeException(t('sb.cv_no_right'));
    $B = sb_get($id); if (!$B) throw new RuntimeException(t('err.notfound'));
    if ($B['status'] !== 'finished' || !empty($B['is_template'])) throw new RuntimeException(t('sb.cv_need_finished'));
    if (!empty($opt['assign']) && sb_assign_lines($B, (array)$opt['assign'])) $B = sb_get($id);   // R97-007: section / discipline chosen on the preview for lines that had none
    $pid = (int)$B['project_id']; $sid = (int)$B['subproject_id']; $sp = $sid ? one('SELECT s.*, p.vessel_id FROM subprojects s JOIN projects p ON p.id = s.project_id WHERE s.id = ? AND s.project_id = ?', [$sid, $pid]) : null; if (!$sp) throw new RuntimeException(t('sb.blk_sub'));
    $P = sb_offer_lines($B, $opt); if (!$P['lines']) throw new RuntimeException(t('sb.cv_no_lines'));
    $u = current_user(); $pdo = db(); $pdo->beginTransaction();
    try {
        $no = function_exists('br_offer_no') ? br_offer_no($pid, $sid) : ''; if ($no === '') $no = ob_offer_no($pid, $sid);
        q('INSERT INTO offers (project_id, subproject_id, offer_no, revision, title, amount, currency, status, status_at, notes, created_by, vessel_id, ticket_id, layout, vat_pct, validity_days) VALUES (?,?,?,?,?,?,?,?,CURDATE(),?,?,?,?,?,?,?)',
          [$pid, $sid, $no, 'R0', mb_substr((string)$B['title'], 0, 200), $P['net'], (string)$B['currency'], 'draft', mb_substr(t('sb.cv_offer_note', ['n' => sb_label($B)]), 0, 4000), (int)($u['id'] ?? 0), (int)($sp['vessel_id'] ?? 0) ?: null, (int)($B['ticket_id'] ?? 0) ?: null, 'system', 0, 30]);
        $oid = (int) db()->lastInsertId();
        try { if (function_exists('num_sq') && function_exists('num_has') && num_has() && ($sq = num_sq($pid, $sid))) q('UPDATE offers SET sq_no = ? WHERE id = ?', [$sq, $oid]); } catch (Throwable $e) {}
        try { if (function_exists('rl_current')) { $br = function_exists('br_of') ? br_of($pid, $sid) : []; $rl = rl_current((int)($br['id'] ?? 0) ?: null, (string)($br['code'] ?? '')); if ($rl) q('UPDATE offers SET rate_list_id = ? WHERE id = ?', [(int)$rl['id'], $oid]); } } catch (Throwable $e) {}
        $seq = 0; foreach ($P['lines'] as $x) q('INSERT INTO offer_lines (offer_id, seq, kind, rate_item_id, item_id, cost_price, margin_pct, erp_code, description, unit, zone, qty, unit_price, discount_pct, line_total, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$oid, $seq += 10, $x['kind'], null, $x['item_id'], $x['cost_price'], $x['margin_pct'], $x['erp_code'], $x['description'], $x['unit'], (string)$B['zone'] === 'overseas' ? 'overseas' : 'local', $x['qty'], $x['unit_price'], 0, $x['kind'] === 'text' ? 0 : ob_line_total((float)$x['qty'], (float)$x['unit_price'], 0), $x['notes']]);
        ob_recalc($oid); $o = of_load($oid); if ($o) { ob_blocks_init($o); $scope = sb_scope_text($B); if ($scope !== '') ob_block_save($oid, 'scope', ob_block_label('scope'), $scope);
            if ($P['excluded']) { $cur = (string) scalar("SELECT body FROM offer_blocks WHERE offer_id = ? AND block_key = 'exclusions'", [$oid]); ob_block_save($oid, 'exclusions', ob_block_label('exclusions'), trim($cur . "\n" . implode("\n", array_map(fn($e) => '- ' . $e, $P['excluded'])))); } }
        of_event($oid, 'draft', t('sb.cv_offer_ev', ['n' => sb_label($B)]));
        $brev = null; $mode = (string)($opt['budget'] ?? 'replace');
        if (in_array($mode, ['replace', 'add'], true) && function_exists('bg_has') && bg_has()) {
            $F = sb_feed_plan($B); $curB = bg_current($sid); $rows = [];
            if (sb_fed_col()) { $unIds = array_column($F['unmapped'], 'id'); foreach ($B['labour'] as $l) if ((float)$l['h_normal'] + (float)$l['h_overtime'] > 0) q('UPDATE sb_labour SET fed = ? WHERE id = ? AND budget_id = ?', [in_array((int)$l['id'], $unIds, true) ? 0 : 1, (int)$l['id'], $id]); }   // R97-007: remember what reached the budget
            if ($mode === 'add' && $curB) foreach (all('SELECT discipline_id, section_id, hours, rate FROM sp_budget_hours WHERE budget_id = ?', [(int)$curB['id']]) as $h) $rows[(int)$h['discipline_id'] . ':' . (int)$h['section_id']] = ['d' => (int)$h['discipline_id'], 's' => (int)$h['section_id'] ?: null, 'h' => (float)$h['hours'], 'r' => (float)$h['rate']];
            foreach ($F['rows'] as $r) { $k = $r['discipline_id'] . ':' . (int)$r['section_id']; if (isset($rows[$k])) $rows[$k]['h'] += $r['hours']; else $rows[$k] = ['d' => $r['discipline_id'], 's' => $r['section_id'], 'h' => $r['hours'], 'r' => $F['rate']]; }
            $parts = $F['parts'] + ($mode === 'add' && $curB ? (float)$curB['parts_budget'] : 0.0); $ov = $P['net'] + ($mode === 'add' && $curB ? (float)($curB['offer_value'] ?? 0) : 0.0);
            $rev = (int) scalar('SELECT COALESCE(MAX(revision), -1) + 1 FROM sp_budgets WHERE subproject_id = ?', [$sid]);
            q('INSERT INTO sp_budgets (subproject_id, revision, parts_budget, offer_value, notes, created_by, created_name) VALUES (?,?,?,?,?,?,?)', [$sid, $rev, round($parts, 2), round($ov, 2), mb_substr(t($mode === 'add' ? 'sb.cv_bg_note_add' : 'sb.cv_bg_note', ['n' => sb_label($B), 'o' => $no]), 0, 500), (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')]);
            $brev = (int) db()->lastInsertId(); $hasSec = function_exists('bg_has_sections') && bg_has_sections();
            foreach ($rows as $r) { if ($r['h'] <= 0) continue; if ($hasSec) q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate, section_id) VALUES (?,?,?,?,?)', [$brev, $r['d'], round($r['h'], 2), $r['r'], $r['s']]); else q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate) VALUES (?,?,?,?)', [$brev, $r['d'], round($r['h'], 2), $r['r']]); }
            $GLOBALS['_bg_cur'] = []; if (function_exists('eng_rows_reset')) eng_rows_reset(); cache_bump_later('eng');
        }
        q("UPDATE sb_budgets SET status = 'converted', offer_id = ?, budget_rev_id = ?, converted_at = NOW() WHERE id = ?", [$oid, $brev, $id]);
        sb_event($id, 'converted', $no . ($brev ? ' · ' . t('sb.cv_ev_bg', ['m' => $mode]) : '')); cache_bump_later('fs'); $pdo->commit(); return $oid;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

/* ───────────── R97-005: audit trail export, housekeeping ───────────── */

/** Excel of the module's audit trail (management and administrators): every event of the last 24 months, newest first, at most 20 000 lines. */
function sb_audit_xlsx(): void {
    if (!sb_can_reopen()) { http_response_code(403); exit(t('err.noaccess')); } require_once __DIR__ . '/xlsxw.php'; $ip = sb_ev_ip(); $rows = [];
    foreach (all('SELECT v.created_at, u.full_name, v.kind, v.note, ' . ($ip ? 'v.ip' : "'' AS ip") . ', v.budget_id, b.sb_no, b.title, b.status FROM sb_events v LEFT JOIN customer_users u ON u.id = v.user_id LEFT JOIN sb_budgets b ON b.id = v.budget_id WHERE v.created_at >= NOW() - INTERVAL 24 MONTH ORDER BY v.id DESC LIMIT 20000') as $r) {
        $lbl = t('sb.ev_' . $r['kind']); $rows[] = [(string)$r['created_at'], (string)$r['full_name'], $lbl === 'sb.ev_' . $r['kind'] ? (string)$r['kind'] : $lbl, (string)($r['sb_no'] ?? ((int)$r['budget_id'] ? '#' . (int)$r['budget_id'] . ' (' . t('sb.aud_gone') . ')' : '')), (string)($r['title'] ?? ''), $r['status'] ? t('sb.st_' . $r['status']) : '', (string)$r['note'], (string)$r['ip']];
    }
    sb_event(0, 'audit_export', (string)count($rows));
    xlsx_download('GAS-SB-audit-' . date('Y-m-d') . '.xlsx', [['name' => 'Audit trail', 'header' => [t('sb.aud_when'), t('sb.aud_who'), t('sb.aud_what'), t('sb.no'), t('sb.f_title'), t('th.status'), t('sb.aud_detail'), 'IP'], 'rows' => $rows, 'widths' => [20, 26, 30, 18, 44, 18, 60, 16]]]);
}
/** Housekeeping, at most once a day: review rows left unapplied for 30 days and uploaded workbooks older than a day are removed. Returns [rows, files]. */
function sb_housekeeping(): array {
    $n = 0; $f = 0;
    try { if (sb_r3()) $n = q('DELETE FROM sb_import_rows WHERE created_at < NOW() - INTERVAL 30 DAY')->rowCount(); } catch (Throwable $e) {}
    try { foreach ((array) @glob(sb_imp_dir() . '/*.xlsx') as $old) if (@filemtime($old) < time() - 86400 && @unlink($old)) $f++; } catch (Throwable $e) {}
    return [$n, $f];
}

/* ───────────── R97-007: no labour hour is lost on the way to the sub-project budget ───────────── */

function sb_fed_col(): bool { static $h = null; if ($h === null) { try { $h = sb_has() && (bool) scalar("SHOW COLUMNS FROM sb_labour LIKE 'fed'"); } catch (Throwable $e) { $h = false; } } return $h; }
/**
 * Give a section and / or a discipline to labour lines that have nothing to be written under (posted as [line id => [section_id, discipline_id]]).
 * Only these two fields are touched — hours, rates and amounts of a finished or converted budget stay as they are. Returns the lines changed.
 */
function sb_assign_lines(array $B, array $as): int {
    sb_guard(); $sec = sb_sections(); $dis = sb_disciplines(); $own = array_column($B['labour'], null, 'id'); $n = 0;
    foreach ($as as $lid => $a) {
        $lid = (int)$lid; if (!isset($own[$lid]) || !is_array($a)) continue; $l = $own[$lid]; if (sb_fed_col() && (int)($l['fed'] ?? 0) === 1) continue;   // already in the budget — not re-assigned
        $s = (int)($a['section_id'] ?? 0); $d = (int)($a['discipline_id'] ?? 0); if (!isset($sec[$s])) $s = 0; if (!isset($dis[$d])) $d = 0; if (!$s && !$d) continue;
        q('UPDATE sb_labour SET section_id = COALESCE(?, section_id), discipline_id = COALESCE(?, discipline_id) WHERE id = ? AND budget_id = ?', [$s ?: null, $d ?: null, $lid, (int)$B['id']]); $n++;
    }
    if ($n) sb_event((int)$B['id'], 'assigned', (string)$n); return $n;
}
/**
 * State of the feed of a converted budget: hours of the budget that count (normal + overtime), hours already written, and the lines still
 * outside (never written). For budgets converted before the lines were marked, the mark is derived once: a line that can be written now was written then.
 */
function sb_feed_state(array $B): array {
    $F = sb_feed_plan($B); $unIds = array_column($F['unmapped'], 'id'); $rest = []; $fed = 0.0; $all = 0.0; $marked = false;
    foreach ($B['labour'] as $l) if ($l['fed'] ?? null) { $marked = true; break; } foreach ($B['labour'] as $l) if (($l['fed'] ?? null) !== null) $marked = true;
    foreach ($B['labour'] as $l) {
        $h = (float)$l['h_normal'] + (float)$l['h_overtime']; if ($h <= 0) continue; $all += $h;
        $wasFed = $marked ? (int)($l['fed'] ?? 0) === 1 : !in_array((int)$l['id'], $unIds, true);
        if ($wasFed) $fed += $h; else $rest[] = ['id' => (int)$l['id'], 'code' => (string)($l['code'] ?? ''), 'descr' => (string)$l['descr'], 'hours' => $h, 'bucket' => (string)($l['bucket'] ?? ''), 'section_id' => (int)($l['section_id'] ?? 0), 'discipline_id' => (int)($l['discipline_id'] ?? 0), 'ready' => sb_line_discipline($l) !== null];
    }
    $travel = 0.0; foreach ($B['labour'] as $l) $travel += (float)$l['h_travel'];
    return ['all' => $all, 'fed' => $fed, 'rest' => $rest, 'rest_hours' => array_sum(array_column($rest, 'hours')), 'travel' => $travel, 'marked' => $marked];
}
/**
 * Add the labour hours of a converted budget that never reached the sub-project budget: the chosen section / discipline is stored on the
 * lines, then a new Budget & actual revision = the current figures + these hours. Existing revisions are not edited. Returns the hours added.
 */
function sb_feed_rest(int $id, array $as): float {
    sb_guard(); if (!sb_fed_col() || !function_exists('bg_has') || !bg_has()) throw new RuntimeException(t('err.noaccess'));
    $B = sb_get($id); if (!$B || $B['status'] !== 'converted' || !(int)$B['subproject_id']) throw new RuntimeException(t('sb.fd_need_conv'));
    $sid = (int)$B['subproject_id']; $u = current_user(); $pdo = db(); $pdo->beginTransaction();
    try {
        $st0 = sb_feed_state($B); if (!$st0['marked']) { $restIds = array_column($st0['rest'], 'id'); foreach ($B['labour'] as $l) if ((float)$l['h_normal'] + (float)$l['h_overtime'] > 0) q('UPDATE sb_labour SET fed = ? WHERE id = ? AND budget_id = ?', [in_array((int)$l['id'], $restIds, true) ? 0 : 1, (int)$l['id'], $id]); $B = sb_get($id); }
        if (sb_assign_lines($B, $as)) $B = sb_get($id);
        $add = []; $hours = 0.0; $done = [];
        foreach ($B['labour'] as $l) { $h = (float)$l['h_normal'] + (float)$l['h_overtime']; if ($h <= 0 || (int)($l['fed'] ?? 0) === 1) continue; $d = sb_line_discipline($l); if (!$d) continue;
            $k = $d . ':' . (int)($l['section_id'] ?? 0); $add[$k] = $add[$k] ?? ['d' => $d, 's' => (int)($l['section_id'] ?? 0) ?: null, 'h' => 0.0]; $add[$k]['h'] += $h; $hours += $h; $done[] = (int)$l['id']; }
        if (!$add) throw new RuntimeException(t('sb.fd_nothing'));
        $curB = bg_current($sid); $rows = [];
        if ($curB) foreach (all('SELECT discipline_id, section_id, hours, rate FROM sp_budget_hours WHERE budget_id = ?', [(int)$curB['id']]) as $h) { $k = (int)$h['discipline_id'] . ':' . (int)$h['section_id']; if (isset($rows[$k])) $rows[$k]['h'] += (float)$h['hours']; else $rows[$k] = ['d' => (int)$h['discipline_id'], 's' => (int)$h['section_id'] ?: null, 'h' => (float)$h['hours'], 'r' => (float)$h['rate']]; }
        foreach ($add as $k => $r) { if (isset($rows[$k])) $rows[$k]['h'] += $r['h']; else $rows[$k] = ['d' => $r['d'], 's' => $r['s'], 'h' => $r['h'], 'r' => (float)$B['cost_rate']]; }
        $rev = (int) scalar('SELECT COALESCE(MAX(revision), -1) + 1 FROM sp_budgets WHERE subproject_id = ?', [$sid]);
        q('INSERT INTO sp_budgets (subproject_id, revision, parts_budget, offer_value, notes, created_by, created_name) VALUES (?,?,?,?,?,?,?)', [$sid, $rev, $curB ? (float)$curB['parts_budget'] : 0, $curB ? $curB['offer_value'] : null, mb_substr(t('sb.fd_bg_note', ['n' => sb_label($B), 'h' => sb_h($hours)]), 0, 500), (int)($u['id'] ?? 0), (string)($u['full_name'] ?? '')]);
        $brev = (int) db()->lastInsertId(); $hasSec = function_exists('bg_has_sections') && bg_has_sections();
        foreach ($rows as $r) { if ($r['h'] <= 0) continue; if ($hasSec) q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate, section_id) VALUES (?,?,?,?,?)', [$brev, $r['d'], round($r['h'], 2), $r['r'], $r['s']]); else q('INSERT INTO sp_budget_hours (budget_id, discipline_id, hours, rate) VALUES (?,?,?,?)', [$brev, $r['d'], round($r['h'], 2), $r['r']]); }
        q('UPDATE sb_labour SET fed = 1 WHERE budget_id = ? AND id IN (' . implode(',', $done) . ')', [$id]); q('UPDATE sb_budgets SET budget_rev_id = ? WHERE id = ?', [$brev, $id]);
        $GLOBALS['_bg_cur'] = []; if (function_exists('eng_rows_reset')) eng_rows_reset(); cache_bump_later('eng'); cache_bump_later('fs');
        sb_event($id, 'fed', sb_h($hours) . ' h · R' . $rev); $pdo->commit(); return $hours;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

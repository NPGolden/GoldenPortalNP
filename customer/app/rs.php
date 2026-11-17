<?php
declare(strict_types=1);
/**
 * Remote support contracts per vessel (GAS-CP-R30-004) — IT & Cybersecurity section.
 *  rs_contracts: one contract per vessel and period (number, dates, hours included, response SLA, coverage, access, fee, renewal notice)
 *  rs_sessions:  remote sessions logged against the contract (hours used), optionally from a ticket
 *  tickets.rs_contract_id: a ticket raised on a vessel with an active contract is covered by it (set automatically)
 *  tasks.rs_contract_id:   tasks linked to the contract; the renewal reminder task is kept in step with the end date
 */

function rs_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'rs_contracts'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rs_statuses(): array { return ['draft' => t('rs.s_draft'), 'active' => t('rs.s_active'), 'suspended' => t('rs.s_suspended'), 'expired' => t('rs.s_expired'), 'cancelled' => t('rs.s_cancelled')]; }
function rs_coverages(): array { return ['office' => t('rs.cov_office'), 'extended' => t('rs.cov_extended'), 'always' => t('rs.cov_always')]; }
function rs_billings(): array { return ['monthly' => t('rs.b_monthly'), 'quarterly' => t('rs.b_quarterly'), 'yearly' => t('rs.b_yearly'), 'one_off' => t('rs.b_one_off')]; }
function rs_status_pill(string $s): string { $m = ['draft' => 'mute', 'active' => 'green', 'suspended' => 'gold', 'expired' => 'bad', 'cancelled' => 'mute']; return pill($m[$s] ?? 'mute', rs_statuses()[$s] ?? $s); }
/** Days until the end date (negative when passed), null for open-ended contracts. */
function rs_days_left(?string $end): ?int { if (!$end) return null; return (int) floor((strtotime($end . ' 00:00:00') - strtotime(date('Y-m-d') . ' 00:00:00')) / 86400); }
function rs_expiry_tone(array $c): ?string { if ($c['status'] !== 'active') return null; $d = rs_days_left($c['end_date'] ?? null); if ($d === null) return null; return $d < 0 ? 'red' : ($d <= (int)($c['renew_notice_days'] ?? 60) ? 'amber' : 'green'); }
function rs_number(): string {
    $y = date('Y'); $n = (int) scalar("SELECT COUNT(*) FROM rs_contracts WHERE contract_no LIKE ?", ['GAS-RS-' . $y . '-%']) + 1;
    do { $no = sprintf('GAS-RS-%s-%03d', $y, $n++); } while (scalar('SELECT COUNT(*) FROM rs_contracts WHERE contract_no = ?', [$no]));
    return $no;
}
/** Visibility clause for the current user. */
function rs_scope(string $a = 'r'): array {
    if (is_staff()) return ['1 = 1', []];
    $cid = (int)current_user()['customer_id'];
    return ["{$a}.visibility = 'external' AND {$a}.status <> 'draft' AND (v.customer_id = ? OR {$a}.customer_id = ?)", [$cid, $cid]];
}
/** Mark active contracts whose end date has passed as expired (cheap; called by the pages). */
function rs_sweep(): void { if (rs_has()) q("UPDATE rs_contracts SET status = 'expired' WHERE status = 'active' AND end_date IS NOT NULL AND end_date < CURDATE()"); }

function rs_list(array $f = []): array {
    if (!rs_has()) return [];
    [$scope, $args] = rs_scope(); $w = [$scope];
    if (!empty($f['vessel'])) { $w[] = 'r.vessel_id = ?'; $args[] = (int)$f['vessel']; }
    if (!empty($f['customer'])) { $w[] = '(v.customer_id = ? OR r.customer_id = ?)'; $args[] = (int)$f['customer']; $args[] = (int)$f['customer']; }
    if (!empty($f['status'])) { if ($f['status'] === 'live') $w[] = "r.status IN ('active','suspended')"; elseif ($f['status'] === 'expiring') $w[] = "r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL r.renew_notice_days DAY)"; elseif ($f['status'] === 'due90') $w[] = "r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; elseif ($f['status'] === 'lic' && rs_sub_has()) $w[] = "r.status IN ('active','suspended') AND r.license_expiry IS NOT NULL AND r.license_expiry <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)"; elseif ($f['status'] === 'norenew' && rs_sub_has()) $w[] = "((r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)) OR (r.status = 'expired' AND r.end_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))) AND r.renewal_offer_id IS NULL AND COALESCE(r.pay_status, '') <> 'rejected'"; elseif (isset(rs_statuses()[$f['status']])) { $w[] = 'r.status = ?'; $args[] = $f['status']; } }
    if (!empty($f['pay']) && rs_sub_has() && isset(rs_pay_statuses()[$f['pay']])) { $w[] = 'r.pay_status = ?'; $args[] = $f['pay']; }
    if (!empty($f['q'])) { $x = rs_sub_has() && is_staff();   /* R97-092: serial and SQ are searched by staff only */ $w[] = '(r.contract_no LIKE ? OR r.title LIKE ? OR v.name LIKE ? OR r.access_method LIKE ?' . ($x ? ' OR r.product LIKE ? OR r.serial_no LIKE ? OR r.sq_ref LIKE ?' : '') . ')'; array_push($args, ...array_fill(0, $x ? 7 : 4, '%' . $f['q'] . '%')); }
    $sx = rs_sub_has() ? "(SELECT o.offer_no FROM offers o WHERE o.id = r.offer_id) AS sold_no, (SELECT o.offer_no FROM offers o WHERE o.id = r.renewal_offer_id) AS ren_no, (SELECT o.status FROM offers o WHERE o.id = r.renewal_offer_id) AS ren_status," : '';
    return all("SELECT r.*, v.name AS vessel, COALESCE(c.name, cv.name) AS customer_name, {$sx}
                       (SELECT COALESCE(SUM(s.hours), 0) FROM rs_sessions s WHERE s.contract_id = r.id) AS hours_used,
                       (SELECT COUNT(*) FROM tickets t WHERE t.rs_contract_id = r.id) AS n_tickets,
                       (SELECT COUNT(*) FROM tickets t WHERE t.rs_contract_id = r.id AND t.status IN ('open','in_progress','waiting_customer')) AS n_open,
                       (SELECT COUNT(*) FROM tasks k WHERE k.rs_contract_id = r.id AND k.status = 'open') AS n_tasks
                FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN customers cv ON cv.id = v.customer_id
                WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(r.status,'active','suspended','draft','expired','cancelled'), r.end_date IS NULL, r.end_date, v.name", $args);
}
function rs_get(int $id): ?array {
    if (!rs_has() || !$id) return null;
    [$scope, $args] = rs_scope();
    $c = one("SELECT r.*, v.name AS vessel, v.imo, COALESCE(c.name, cv.name) AS customer_name, (SELECT COALESCE(SUM(s.hours), 0) FROM rs_sessions s WHERE s.contract_id = r.id) AS hours_used
              FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN customers cv ON cv.id = v.customer_id WHERE r.id = ? AND {$scope}", array_merge([$id], $args));
    if (!$c) return null;
    if (rs_sub_has()) foreach (['offer_id' => 'sold', 'renewal_offer_id' => 'ren'] as $k => $n) $c[$n] = $c[$k] ? one('SELECT id, offer_no, revision, sq_no, title, status, amount, currency FROM offers WHERE id = ?', [(int)$c[$k]]) : null;
    $c['sessions'] = all('SELECT s.*, t.ticket_no FROM rs_sessions s LEFT JOIN tickets t ON t.id = s.ticket_id WHERE s.contract_id = ? ORDER BY s.session_date DESC, s.id DESC', [$id]);
    $c['tickets'] = function_exists('tk_list') ? array_values(array_filter(tk_list([]), fn($t) => (int)($t['rs_contract_id'] ?? 0) === $id)) : [];
    $c['tasks'] = is_staff() && function_exists('td_has') && td_has() ? all('SELECT k.*, a.full_name AS assignee FROM tasks k LEFT JOIN customer_users a ON a.id = k.assignee_id WHERE k.rs_contract_id = ? ORDER BY k.status, k.due_at', [$id]) : [];
    return $c;
}
/** The active (or suspended) contract covering a vessel today, or null. */
function rs_for_vessel(?int $vid): ?array {
    if (!rs_has() || !$vid) return null;
    return one("SELECT r.*, (SELECT COALESCE(SUM(s.hours), 0) FROM rs_sessions s WHERE s.contract_id = r.id) AS hours_used FROM rs_contracts r WHERE r.vessel_id = ? AND r.status IN ('active','suspended') AND r.start_date <= CURDATE() AND (r.end_date IS NULL OR r.end_date >= CURDATE()) ORDER BY r.status = 'active' DESC, r.end_date IS NULL DESC, r.end_date DESC LIMIT 1", [$vid]);
}
function rs_save_from_post(): int {
    $u = require_staff(); if (!rs_has()) throw new RuntimeException('remote support tables missing');
    $id = (int)($_POST['id'] ?? 0);
    $str = fn(string $k, int $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    $vid = (int)($_POST['vessel_id'] ?? 0); if (!$vid || !one('SELECT id FROM vessels WHERE id = ?', [$vid])) throw new RuntimeException(t('rs.need_vessel'));
    $title = $str('title', 160) ?: t('rs.default_title');
    $no = $str('contract_no', 40) ?: ($id ? (string) scalar('SELECT contract_no FROM rs_contracts WHERE id = ?', [$id]) : rs_number());
    if (scalar('SELECT COUNT(*) FROM rs_contracts WHERE contract_no = ? AND id <> ?', [$no, $id])) throw new RuntimeException(t('rs.dup_no', ['n' => $no]));
    $status = isset(rs_statuses()[$_POST['status'] ?? '']) ? $_POST['status'] : 'active';
    $start = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['start_date'] ?? '')) ? $_POST['start_date'] : date('Y-m-d');
    $end = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['end_date'] ?? '')) ? $_POST['end_date'] : null;
    if ($end && $end < $start) throw new RuntimeException(t('rs.end_before_start'));
    $num = fn(string $k) => ($_POST[$k] ?? '') === '' ? null : (float) str_replace(',', '.', (string)$_POST[$k]);
    $vals = [$no, $vid, (int)($_POST['customer_id'] ?? 0) ?: null, $title, $status, $start, $end, $num('hours_included'), ($_POST['response_hours'] ?? '') === '' ? null : max(0, (int)$_POST['response_hours']),
             isset(rs_coverages()[$_POST['coverage'] ?? '']) ? $_POST['coverage'] : 'office', $str('access_method', 160) ?: null, $str('scope', 4000) ?: null, $num('fee'), strtoupper($str('currency', 3)) ?: 'EUR',
             isset(rs_billings()[$_POST['billing'] ?? '']) ? $_POST['billing'] : 'yearly', ($_POST['renewal'] ?? 'manual') === 'auto' ? 'auto' : 'manual', max(0, min(365, (int)($_POST['renew_notice_days'] ?? 60))),
             ($_POST['visibility'] ?? 'external') === 'internal' ? 'internal' : 'external', $str('notes', 4000) ?: null];
    if ($id) {
        if (!one('SELECT id FROM rs_contracts WHERE id = ?', [$id])) throw new RuntimeException(t('err.notfound'));
        q('UPDATE rs_contracts SET contract_no=?, vessel_id=?, customer_id=?, title=?, status=?, start_date=?, end_date=?, hours_included=?, response_hours=?, coverage=?, access_method=?, scope=?, fee=?, currency=?, billing=?, renewal=?, renew_notice_days=?, visibility=?, notes=? WHERE id = ?', array_merge($vals, [$id]));
    } else {
        q('INSERT INTO rs_contracts (contract_no, vessel_id, customer_id, title, status, start_date, end_date, hours_included, response_hours, coverage, access_method, scope, fee, currency, billing, renewal, renew_notice_days, visibility, notes, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', array_merge($vals, [(int)$u['id']]));
        $id = (int) db()->lastInsertId();
    }
    if (rs_sub_has()) {   // R97-091: the subscription fields
        $dt = fn(string $k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST[$k] ?? '')) ? $_POST[$k] : null; $sq = $str('sq_ref', 60) ?: null;
        $pm = (int)($_POST['period_months'] ?? 0); $ps = isset(rs_pay_statuses()[$_POST['pay_status'] ?? '']) ? $_POST['pay_status'] : null;
        q('UPDATE rs_contracts SET product = ?, serial_no = ?, pay_status = ?, period_months = ?, installed_on = ?, license_expiry = ?, sq_ref = ? WHERE id = ?', [$str('product', 60) ?: null, $str('serial_no', 60) ?: null, $ps, $pm > 0 && $pm <= 240 ? $pm : null, $dt('installed_on'), $dt('license_expiry'), $sq, $id]);
        $oid = array_key_exists('offer_id', $_POST) ? ((int)$_POST['offer_id'] ?: null) : (scalar('SELECT offer_id FROM rs_contracts WHERE id = ?', [$id]) ?: null);
        if ($oid && !scalar('SELECT COUNT(*) FROM offers WHERE id = ?', [(int)$oid])) $oid = null; if (!$oid && $sq) $oid = rs_offer_find($sq);
        q('UPDATE rs_contracts SET offer_id = ? WHERE id = ?', [$oid, $id]);
    }
    rs_sync_renewal_task($id);
    rs_cover_tickets($id);
    return $id;
}
function rs_delete(int $id): void {
    require_cp_admin(); if (!rs_has()) return;
    $c = one('SELECT * FROM rs_contracts WHERE id = ?', [$id]); if (!$c) return;
    if ($c['renewal_task_id']) q("UPDATE tasks SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$c['renewal_task_id']]);
    q('UPDATE tickets SET rs_contract_id = NULL WHERE rs_contract_id = ?', [$id]);
    q('UPDATE tasks SET rs_contract_id = NULL WHERE rs_contract_id = ?', [$id]);
    q('DELETE FROM rs_sessions WHERE contract_id = ?', [$id]);
    q('DELETE FROM rs_contracts WHERE id = ?', [$id]);
}
/** Open tickets of the vessel raised inside the contract period and not yet covered get the contract. */
function rs_cover_tickets(int $id): int {
    $c = one('SELECT * FROM rs_contracts WHERE id = ?', [$id]); if (!$c || !in_array($c['status'], ['active', 'suspended'], true)) return 0;
    return q('UPDATE tickets SET rs_contract_id = ? WHERE vessel_id = ? AND rs_contract_id IS NULL AND DATE(created_at) >= ? AND (? IS NULL OR DATE(created_at) <= ?)', [$id, (int)$c['vessel_id'], $c['start_date'], $c['end_date'], $c['end_date']])->rowCount();
}
/** Called when a ticket is created or moved to a vessel: covered by the vessel's live contract, if any. */
function rs_cover_ticket(int $ticketId, ?int $vid): void {
    if (!rs_has() || !$ticketId) return;
    $c = rs_for_vessel($vid);
    q('UPDATE tickets SET rs_contract_id = ? WHERE id = ?', [$c['id'] ?? null, $ticketId]);
}
/** One renewal reminder task per contract: due renew_notice_days before the end date, kind "remote", assigned to the administrators' team (project team of the vessel's active project when there is one). */
function rs_sync_renewal_task(int $id): void {
    if (!function_exists('td_has') || !td_has()) return;
    $c = one('SELECT r.*, v.name AS vessel FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id WHERE r.id = ?', [$id]); if (!$c) return;
    $u = current_user();
    $wanted = $c['status'] === 'active' && $c['end_date'];
    if (!$wanted) { if ($c['renewal_task_id']) q("UPDATE tasks SET status = 'cancelled' WHERE id = ? AND status = 'open'", [$c['renewal_task_id']]); return; }
    $due = date('Y-m-d 09:00:00', strtotime($c['end_date'] . ' -' . (int)$c['renew_notice_days'] . ' days'));
    if ($due < date('Y-m-d H:i:s')) $due = date('Y-m-d 09:00:00', strtotime('tomorrow'));
    $title = mb_substr(t('rs.task_title', ['n' => $c['contract_no'], 'v' => $c['vessel'], 'd' => fmt_date($c['end_date'])]), 0, 200);
    $pid = (int) scalar("SELECT id FROM projects WHERE vessel_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1", [(int)$c['vessel_id']]) ?: null;
    $kind = isset(td_kinds(false)['remote']) ? 'remote' : 'general';
    if ($c['renewal_task_id'] && one("SELECT id FROM tasks WHERE id = ? AND status <> 'cancelled'", [$c['renewal_task_id']])) {
        q("UPDATE tasks SET title = ?, due_at = ?, reminded_at = NULL, status = 'open', done_at = NULL, vessel_id = ?, project_id = COALESCE(project_id, ?), rs_contract_id = ? WHERE id = ?", [$title, $due, (int)$c['vessel_id'], $pid, $id, $c['renewal_task_id']]);
        return;
    }
    q('INSERT INTO tasks (title, notes, kind, project_id, subproject_id, vessel_id, assignee_id, priority, due_at, recur, recur_every, remind_before, owner_id, rs_contract_id) VALUES (?,?,?,?,NULL,?,NULL,?,?,\'none\',1,?,?,?)',
      [$title, t('rs.task_notes', ['n' => $c['contract_no']]), $kind, $pid, (int)$c['vessel_id'], 'high', $due, 10080, (int)($u['id'] ?? 0) ?: null, $id]);
    $tid = (int) db()->lastInsertId();
    if (function_exists('td_log')) td_log($tid, 'created', $due, (int)($u['id'] ?? 0) ?: null, 'remote support');
    q('UPDATE rs_contracts SET renewal_task_id = ? WHERE id = ?', [$tid, $id]);
}
/** Log a remote session (hours) against a contract, optionally from a ticket. */
function rs_session_add(int $contractId, ?int $ticketId, string $date, float $hours, ?string $note): int {
    $u = require_staff();
    if (!one('SELECT id FROM rs_contracts WHERE id = ?', [$contractId])) throw new RuntimeException(t('err.notfound'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $hours = max(0, min(99, round($hours * 4) / 4));
    q('INSERT INTO rs_sessions (contract_id, ticket_id, session_date, hours, engineer_id, engineer_name, note) VALUES (?,?,?,?,?,?,?)', [$contractId, $ticketId ?: null, $date, $hours, (int)$u['id'], $u['full_name'], $note !== null ? mb_substr($note, 0, 255) : null]);
    $id = (int) db()->lastInsertId();
    if ($ticketId && function_exists('tk_event')) tk_event($ticketId, 'rs_session', number_format($hours, 2) . ' h');
    return $id;
}
function rs_session_delete(int $id): void { require_staff(); q('DELETE FROM rs_sessions WHERE id = ?', [$id]); }
/** Compact card for the ticket page and the vessel page. */
function rs_card_html(array $c, bool $withLink = true): string {
    $tone = rs_expiry_tone($c); $d = rs_days_left($c['end_date'] ?? null);
    $h = '<div class="rs-card rs-' . ($tone ?: 'none') . '"><div class="rs-head">' . ($withLink ? '<a href="' . url('rs', ['id' => $c['id']]) . '"><b>' . e($c['contract_no']) . '</b></a>' : '<b>' . e($c['contract_no']) . '</b>') . ' ' . rs_status_pill($c['status']) . '</div>'
       . '<div class="rs-meta">' . e($c['title']) . ' · ' . e(fmt_date($c['start_date'])) . ' – ' . ($c['end_date'] ? e(fmt_date($c['end_date'])) : t('rs.open_ended'))
       . ($d !== null && $c['status'] === 'active' ? ' · <span class="rs-days">' . e($d < 0 ? t('rs.expired_ago', ['n' => -$d]) : t('rs.days_left', ['n' => $d])) . '</span>' : '') . '</div>'
       . '<div class="rs-meta">' . ($c['hours_included'] !== null ? e(t('rs.hours_used_of', ['u' => rtrim(rtrim(number_format((float)$c['hours_used'], 2, '.', ''), '0'), '.'), 'n' => rtrim(rtrim(number_format((float)$c['hours_included'], 2, '.', ''), '0'), '.')])) : e(t('rs.hours_used', ['u' => rtrim(rtrim(number_format((float)$c['hours_used'], 2, '.', ''), '0'), '.')])))
       . ($c['response_hours'] !== null ? ' · ' . e(t('rs.sla_short', ['h' => (int)$c['response_hours']])) : '') . ' · ' . e(rs_coverages()[$c['coverage']] ?? $c['coverage']) . ($c['access_method'] ? ' · ' . e($c['access_method']) : '') . '</div></div>';
    return $h;
}

/* ------------------------------------------------------------ R97-091 — subscriptions: product, payment, licence expiry, import, offers of Sales */
function rs_sub_has(): bool { static $h = null; if ($h === null) { try { $h = rs_has() && (bool) scalar("SHOW COLUMNS FROM rs_contracts LIKE 'renewal_offer_id'"); } catch (Throwable $e) { $h = false; } } return $h; }
function rs_pay_statuses(): array { return ['ongoing' => t('rs.pay_ongoing'), 'pending' => t('rs.pay_pending'), 'paid' => t('rs.pay_paid'), 'rejected' => t('rs.pay_rejected'), 'expired' => t('rs.pay_expired')]; }
function rs_pay_pill(?string $s): string { if (!$s) return ''; $m = ['ongoing' => 'green', 'pending' => 'gold', 'paid' => 'green', 'rejected' => 'bad', 'expired' => 'mute']; return pill($m[$s] ?? 'mute', rs_pay_statuses()[$s] ?? $s); }
function rs_period_label(?int $m): string { if (!$m) return ''; return $m % 12 === 0 ? t($m === 12 ? 'rs.per_year' : 'rs.per_years', ['n' => intdiv($m, 12)]) : t('rs.per_months', ['n' => $m]); }
/** Who may import subscriptions, raise renewal offers and renew: administrators, management and staff who see the offers. */
function rs_sales_can(): bool { return is_staff() && rs_sub_has() && (is_cp_admin() || (function_exists('mgmt_can') && mgmt_can()) || (function_exists('of_visible') && of_visible())); }
/** A pill for a date that runs out: red when passed, gold within $warn days. */
function rs_date_pill(?string $d, int $warn = 90): string { if (!$d) return '—'; $n = rs_days_left($d); return e(fmt_date($d)) . ' ' . ($n < 0 ? pill('bad', t('rs.expired_ago', ['n' => -$n])) : ($n <= $warn ? pill('gold', t('rs.days_left', ['n' => $n])) : '<span class="hint">' . e(t('rs.days_left', ['n' => $n])) . '</span>')); }
/** The numbers above the list. */
function rs_kpis(): array {
    $k = ['active' => 0, 'd30' => 0, 'd90' => 0, 'expired' => 0, 'lic' => 0, 'pending' => 0]; if (!rs_has() || !is_staff()) return $k; $x = rs_sub_has();
    foreach (all('SELECT status, end_date' . ($x ? ', license_expiry, pay_status' : '') . ' FROM rs_contracts') as $r) { $d = rs_days_left($r['end_date']);
        if ($r['status'] === 'active') { $k['active']++; if ($d !== null && $d <= 30) $k['d30']++; if ($d !== null && $d <= 90) $k['d90']++; }
        if ($r['status'] === 'expired') $k['expired']++;
        if ($x && in_array($r['status'], ['active', 'suspended'], true) && $r['license_expiry'] && rs_days_left($r['license_expiry']) <= 90) $k['lic']++;
        if ($x && ($r['pay_status'] ?? '') === 'pending') $k['pending']++; }
    return $k;
}
/** The offer a sales quotation number belongs to (SQ number of the ERP or the offer number of the portal), or null. */
function rs_offer_find(string $sq): ?int { $sq = trim($sq); if ($sq === '') return null; $num = ltrim(preg_replace('/^SQR?-?/i', '', $sq), '0');
    $id = scalar('SELECT id FROM offers WHERE sq_no = ? OR offer_no = ? ORDER BY id DESC LIMIT 1', [$sq, $sq]); if (!$id && $num !== '' && $num !== $sq && strlen($num) >= 4) $id = scalar('SELECT id FROM offers WHERE sq_no = ? OR offer_no = ? ORDER BY id DESC LIMIT 1', [$num, $num]);
    return $id ? (int)$id : null; }
/** The contracts whose offer or renewal offer this is — shown on the offer. */
function rs_of_offer(int $offerId): array { return rs_sub_has() && $offerId ? all('SELECT r.id, r.contract_no, r.title, r.end_date, r.status, r.offer_id, r.renewal_offer_id, v.name AS vessel FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id WHERE r.offer_id = ? OR r.renewal_offer_id = ?', [$offerId, $offerId]) : []; }
/** Subscriptions Sales has to act on: running out within $days days or lapsed in the last 180, not refused by the customer. */
function rs_renewals_due(int $days = 90): array {
    if (!rs_sub_has() || !is_staff()) return [];
    return all("SELECT r.*, v.name AS vessel, COALESCE(c.name, cv.name) AS customer_name, o.offer_no AS ren_no, o.status AS ren_status, o.amount AS ren_amount, o.currency AS ren_currency
                FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN customers cv ON cv.id = v.customer_id LEFT JOIN offers o ON o.id = r.renewal_offer_id
                WHERE ((r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)) OR (r.status = 'expired' AND r.end_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))) AND COALESCE(r.pay_status, '') <> 'rejected'
                ORDER BY r.end_date, v.name", [$days]);
}
/** Projects an offer for the vessel can be raised under. */
function rs_projects(int $vesselId): array { return all("SELECT id, project_code, name, status FROM projects WHERE vessel_id = ? ORDER BY status = 'active' DESC, id DESC LIMIT 60", [$vesselId]); }
/** A draft offer in Sales for the next period of the subscription. Returns the offer id. */
function rs_renewal_offer(int $id, int $projectId): int {
    if (!rs_sales_can() || !function_exists('of_save_from_post')) throw new RuntimeException(t('err.noaccess'));
    $c = one('SELECT r.*, v.name AS vessel FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id WHERE r.id = ?', [$id]); if (!$c) throw new RuntimeException(t('err.notfound'));
    if ($c['renewal_offer_id'] && scalar("SELECT COUNT(*) FROM offers WHERE id = ?", [(int)$c['renewal_offer_id']])) throw new RuntimeException(t('rs.e_ren_exists'));
    if (!scalar('SELECT COUNT(*) FROM projects WHERE id = ? AND vessel_id = ?', [$projectId, (int)$c['vessel_id']])) throw new RuntimeException(t('rs.e_ren_project'));
    $from = $c['end_date'] ?: date('Y-m-d'); $pm = (int)($c['period_months'] ?: 12); $to = date('Y-m-d', strtotime($from . ' +' . $pm . ' months'));
    $keep = $_POST; $_POST = ['project_id' => $projectId, 'vessel_id' => (int)$c['vessel_id'], 'title' => mb_substr(t('rs.ren_title', ['t' => $c['title'], 'v' => $c['vessel'], 'p' => rs_period_label($pm)]), 0, 200), 'amount' => (string)(float)($c['fee'] ?? 0), 'currency' => $c['currency'] ?: 'EUR', 'as_draft' => '1', 'layout' => 'service',
        'notes' => t('rs.ren_notes', ['n' => $c['contract_no'], 'a' => fmt_date($from), 'b' => fmt_date($to)]) . ($c['product'] ? ' · ' . $c['product'] . ($c['serial_no'] ? ' ' . trim((string)$c['serial_no']) : '') : '') . ($c['sq_ref'] ? ' · ' . t('rs.sq') . ' ' . $c['sq_ref'] : '')];
    try { $oid = of_save_from_post(0); } finally { $_POST = $keep; }
    q('UPDATE rs_contracts SET renewal_offer_id = ? WHERE id = ?', [$oid, $id]);
    return $oid;
}
/** The subscription runs for another period — after the customer accepted the renewal. The renewal offer becomes the offer of the contract. */
function rs_renew(int $id): void {
    if (!rs_sales_can()) throw new RuntimeException(t('err.noaccess')); $u = current_user();
    $c = one('SELECT * FROM rs_contracts WHERE id = ?', [$id]); if (!$c) throw new RuntimeException(t('err.notfound'));
    $pm = (int)($c['period_months'] ?: 12); $from = $c['end_date'] && $c['end_date'] >= date('Y-m-d', strtotime('-180 days')) ? $c['end_date'] : date('Y-m-d'); $to = date('Y-m-d', strtotime($from . ' +' . $pm . ' months'));
    $o = $c['renewal_offer_id'] ? one('SELECT id, offer_no, sq_no FROM offers WHERE id = ?', [(int)$c['renewal_offer_id']]) : null;
    $note = trim((string)$c['notes'] . "\n" . t('rs.renewed_note', ['d' => fmt_date(date('Y-m-d')), 'u' => (string)($u['full_name'] ?? ''), 'a' => fmt_date($c['start_date']), 'b' => $c['end_date'] ? fmt_date($c['end_date']) : '—', 'o' => $o ? $o['offer_no'] : '—']));
    q("UPDATE rs_contracts SET status = 'active', pay_status = 'ongoing', start_date = LEAST(start_date, ?), end_date = ?, notes = ?, offer_id = COALESCE(?, offer_id), sq_ref = COALESCE(?, sq_ref), renewal_offer_id = NULL WHERE id = ?", [$from, $to, mb_substr($note, 0, 4000), $o['id'] ?? null, $o ? ($o['sq_no'] ?: $o['offer_no']) : null, $id]);
    rs_sync_renewal_task($id); rs_cover_tickets($id);
}
/** Vessel names as they are compared: without M/Y, S/Y …, upper case, single spaces. */
function rs_vessel_key(string $n): string { $n = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $n))); return trim(preg_replace('#^(M/Y|S/Y|P/S|M/V|M/S|M/T|S/V|MY|SY|MV)\s+#u', '', $n)); }
/** Import the customer subscriptions from the list kept by Sales (.csv or .xlsx; columns Vessel, SQ, Product, Subscription details, Payment Status, Renewal Period, Renewal Date, Date Installed, Price, Serial Number, Expiry Date).
 *  Returns [imported, skipped, notes]. A subscription already there (same vessel, SQ and product) is skipped; nothing is overwritten. */
function rs_import(array $file, bool $mkVessels): array {
    $u = require_staff(); if (!rs_sales_can()) throw new RuntimeException(t('err.noaccess'));
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '') || !in_array($ext, ['csv', 'xlsx'], true) || (int)$file['size'] > 4 * 1048576) throw new RuntimeException(t('rs.imp_e_file'));
    $rows = [];
    if ($ext === 'xlsx') { require_once __DIR__ . '/xlsx.php'; foreach (xlsx_rows($file['tmp_name']) as $r) { $m = $r ? max(array_keys($r)) : -1; $x = []; for ($i = 0; $i <= $m; $i++) $x[] = (string)($r[$i] ?? ''); $rows[] = $x; } }
    else { $raw = (string) file_get_contents($file['tmp_name']); if (str_starts_with($raw, "\xEF\xBB\xBF")) $raw = substr($raw, 3); if (!mb_check_encoding($raw, 'UTF-8')) $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1253');
        $first = strtok($raw, "\n"); $sep = substr_count((string)$first, ';') > substr_count((string)$first, ',') ? ';' : ','; $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh); while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) $rows[] = array_map('strval', $r); fclose($fh); }
    if (count($rows) < 2) throw new RuntimeException(t('rs.imp_e_file'));
    $norm = fn($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s))); $head = array_map($norm, $rows[0]);
    $H = ['vessel' => ['vessel'], 'sq' => ['sq', 'sq no', 'sq number', 'offer'], 'product' => ['product'], 'details' => ['subscription details', 'details', 'subscription'], 'pay' => ['payment status', 'status'], 'period' => ['renewal period', 'period'], 'renewal' => ['renewal date'], 'installed' => ['date installed', 'installed'], 'price' => ['price', 'fee'], 'serial' => ['serial number', 'serial', 'serial no'], 'lic' => ['expiry date', 'licence expiry', 'license expiry']];
    $col = []; foreach ($H as $k => $names) { $col[$k] = null; foreach ($names as $n) { $p = array_search($n, $head, true); if ($p !== false) { $col[$k] = $p; break; } } }
    if ($col['vessel'] === null) throw new RuntimeException(t('rs.imp_e_head'));
    $date = function ($v) { $v = trim((string)$v); if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null; if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) return date('Y-m-d', (int) round(((float)$v - 25569) * 86400)); return null; };
    $vmap = []; foreach (all('SELECT id, name, former_names FROM vessels WHERE merged_into IS NULL ORDER BY is_active, id') as $v) { $vmap[rs_vessel_key((string)$v['name'])] = (int)$v['id']; foreach (preg_split('/[,;\n]+/u', (string)$v['former_names']) as $fn) if (trim($fn) !== '') $vmap[rs_vessel_key($fn)] ??= (int)$v['id']; }
    $n = 0; $skip = 0; $notes = []; $today = date('Y-m-d'); $add = function (string $m) use (&$notes) { if (count($notes) < 40) $notes[] = $m; };
    foreach (array_slice($rows, 1, 2000) as $ri => $row) {
        $get = fn($k) => $col[$k] !== null ? trim((string)($row[$col[$k]] ?? '')) : ''; $ln = $ri + 2;
        if (implode('', array_map('trim', $row)) === '') continue;
        $vn = $get('vessel'); if ($vn === '') { $skip++; $add(t('rs.imp_r_novessel', ['n' => $ln, 's' => $get('sq')])); continue; }
        $vid = $vmap[rs_vessel_key($vn)] ?? 0;
        if (!$vid && $mkVessels) { q('INSERT INTO vessels (name, is_active) VALUES (?, 1)', [mb_substr($vn, 0, 120)]); $vid = (int) db()->lastInsertId(); $vmap[rs_vessel_key($vn)] = $vid; $add(t('rs.imp_r_vnew', ['n' => $ln, 'v' => $vn])); }
        if (!$vid) { $skip++; $add(t('rs.imp_r_vmiss', ['n' => $ln, 'v' => $vn])); continue; }
        $sq = mb_substr($get('sq'), 0, 60); $prod = mb_substr($get('product'), 0, 60); $serial = mb_substr($get('serial'), 0, 60);
        if (scalar("SELECT COUNT(*) FROM rs_contracts WHERE vessel_id = ? AND COALESCE(sq_ref, '') = ? AND COALESCE(product, '') = ?", [$vid, $sq, $prod])) { $skip++; continue; }
        $p = $norm($get('pay')); $pay = str_contains($p, 'reject') ? 'rejected' : (str_contains($p, 'pending') ? 'pending' : (str_contains($p, 'paid') ? 'paid' : (str_contains($p, 'expired') ? 'expired' : (str_contains($p, 'ongoing') ? 'ongoing' : null))));
        $pm = preg_match('/(\d+)\s*(year|yr|month|έτ|χρόν|μήν)/iu', $get('period'), $m) ? (int)$m[1] * (preg_match('/^(month|μήν)/iu', $m[2]) ? 1 : 12) : null; if ($pm !== null && ($pm < 1 || $pm > 240)) $pm = null;
        $end = $date($get('renewal')); $inst = $date($get('installed')); $start = $inst ?: ($end ? date('Y-m-d', strtotime($end . ' -' . ($pm ?: 12) . ' months')) : $today); if ($end && $end < $start) $start = date('Y-m-d', strtotime($end . ' -' . ($pm ?: 12) . ' months'));
        $status = $pay === 'rejected' ? 'cancelled' : ($pay === 'expired' || ($end && $end < $today) ? 'expired' : 'active');
        $fee = function_exists('of_parse_amount') ? of_parse_amount(preg_replace('/[^\d.,\-]/u', '', $get('price'))) : (float)$get('price');
        q('INSERT INTO rs_contracts (contract_no, vessel_id, title, product, serial_no, status, pay_status, start_date, end_date, period_months, installed_on, license_expiry, sq_ref, offer_id, fee, billing, renewal, renew_notice_days, visibility, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [rs_number(), $vid, mb_substr($get('details'), 0, 160) ?: t('rs.default_title'), $prod ?: null, $serial ?: null, $status, $pay, $start, $end, $pm, $inst, $date($get('lic')), $sq ?: null, $sq ? rs_offer_find($sq) : null, $fee > 0 ? $fee : null, $pm && $pm > 12 ? 'one_off' : 'yearly', 'manual', 60, 'internal', (int)$u['id']]);
        $id = (int) db()->lastInsertId(); $n++; rs_sync_renewal_task($id); rs_cover_tickets($id);
    }
    return [$n, $skip, $notes];
}

/* ------------------------------------------------------------ R97-092 — weekly e-mail to Sales: subscriptions and licences running out */
/** Who receives it: active staff marked Sales, plus management (owner / full scope, CEO, administrators). Accounts without financial data are left out. */
function rs_weekly_recipients(): array {
    $ids = function_exists('pp_managers') ? pp_managers() : [];
    try { $ids = array_merge($ids, array_map('intval', array_column(all("SELECT id FROM customer_users WHERE is_active = 1 AND role IN ('staff','admin') AND can_sales = 1"), 'id'))); } catch (Throwable $e) {}
    $ids = array_values(array_unique(array_filter($ids))); if (!$ids) return [];
    return all("SELECT cu.id, cu.email, cu.full_name FROM customer_users cu LEFT JOIN staff_roles r ON r.id = cu.staff_role_id WHERE cu.is_active = 1 AND cu.id IN (" . implode(',', $ids) . ") AND NOT (COALESCE(LOWER(r.name), '') REGEXP '(^|[^a-z])(qhse|hse|ehs|quality)([^a-z]|$)')");
}
/** Subject and text of the weekly list, or null when nothing runs out. */
function rs_weekly_build(): ?array {
    if (!rs_sub_has()) return null; rs_sweep();
    $ren = all("SELECT r.*, v.name AS vessel, o.offer_no AS ren_no FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id LEFT JOIN offers o ON o.id = r.renewal_offer_id
                WHERE ((r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)) OR (r.status = 'expired' AND r.end_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))) AND COALESCE(r.pay_status, '') <> 'rejected' ORDER BY r.end_date, v.name");
    $lic = all("SELECT r.*, v.name AS vessel FROM rs_contracts r JOIN vessels v ON v.id = r.vessel_id WHERE r.status IN ('active','suspended') AND r.license_expiry IS NOT NULL AND r.license_expiry <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) ORDER BY r.license_expiry, v.name");
    if (!$ren && !$lic) return null;
    $when = fn(?string $d) => $d ? fmt_date($d) . ' (' . (($n = rs_days_left($d)) < 0 ? t('rs.expired_ago', ['n' => -$n]) : t('rs.days_left', ['n' => $n])) . ')' : '—';
    $base = function_exists('nl_base') ? nl_base() : ''; $link = fn(array $q) => ($base !== '' ? $base . '/' : '') . url('cyber', $q);
    $L = [t('rs.wk_intro'), ''];
    if ($ren) { $L[] = mb_strtoupper(t('rs.pp_title')) . ' — ' . count($ren); foreach ($ren as $r) $L[] = '  • ' . $r['vessel'] . ' — ' . $r['title'] . ($r['product'] ? ' (' . $r['product'] . ')' : '') . ' — ' . $when($r['end_date']) . ' — ' . ($r['ren_no'] ? t('rs.ren_offer') . ' ' . $r['ren_no'] : t('rs.wk_nooffer')) . ($r['pay_status'] === 'pending' ? ' — ' . t('rs.pay_pending') : '') . ' — ' . $r['contract_no']; $L[] = '  ' . $link(['status' => 'due90']); $L[] = ''; }
    if ($lic) { $L[] = mb_strtoupper(t('rs.k_lic')) . ' — ' . count($lic); foreach ($lic as $r) $L[] = '  • ' . $r['vessel'] . ' — ' . ($r['product'] ?: $r['title']) . ($r['serial_no'] ? ' ' . trim((string)$r['serial_no']) : '') . ' — ' . $when($r['license_expiry']) . ' — ' . $r['contract_no']; $L[] = '  ' . $link(['status' => 'lic']); $L[] = ''; }
    $L[] = t('rs.wk_foot');
    return [t('rs.wk_subject', ['a' => count($ren), 'b' => count($lic)]), implode("\n", $L), count($ren), count($lic)];
}
/** Called by the daily house-keeping: sends the list once a week (first run on or after Monday). $force sends now. Returns a short log text. */
function rs_weekly(bool $force = false): string {
    if (!rs_sub_has()) return 'subscriptions off'; $wk = date('o-W'); if (!$force && cp_setting('rs_weekly', '') === $wk) return 'subscriptions mail done';
    if (!$force && cp_setting('rs_weekly_on', '1') !== '1') return 'subscriptions mail off';
    cp_setting_set('rs_weekly', $wk); $m = rs_weekly_build(); if (!$m) return 'subscriptions mail: nothing due';
    $n = 0; $to = rs_weekly_recipients();
    foreach ($to as $r) { try { if (function_exists('cp_mail') && cp_mail((string)$r['email'], $m[0], $m[1])) $n++; } catch (Throwable $e) { error_log('[rs_weekly] ' . $e->getMessage()); } }
    if ($to && function_exists('nf_push')) { try { nf_push(array_map(fn($r) => (int)$r['id'], $to), 'of:followup', 'rsw:' . $wk, $m[0], t('rs.wk_nf'), url('cyber', ['status' => 'due90']), null, null); } catch (Throwable $e) {} }
    return 'subscriptions mail ' . $n . '/' . count($to) . ' (' . $m[2] . ' renewals, ' . $m[3] . ' licences)';
}

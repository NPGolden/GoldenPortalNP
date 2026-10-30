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
    if (!empty($f['status'])) { if ($f['status'] === 'live') $w[] = "r.status IN ('active','suspended')"; elseif ($f['status'] === 'expiring') $w[] = "r.status = 'active' AND r.end_date IS NOT NULL AND r.end_date <= DATE_ADD(CURDATE(), INTERVAL r.renew_notice_days DAY)"; elseif (isset(rs_statuses()[$f['status']])) { $w[] = 'r.status = ?'; $args[] = $f['status']; } }
    if (!empty($f['q'])) { $w[] = '(r.contract_no LIKE ? OR r.title LIKE ? OR v.name LIKE ? OR r.access_method LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $f['q'] . '%')); }
    return all("SELECT r.*, v.name AS vessel, COALESCE(c.name, cv.name) AS customer_name,
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

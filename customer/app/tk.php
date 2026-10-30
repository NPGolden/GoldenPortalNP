<?php
/**
 * Tickets — raised by the owner or by Golden A/S against a vessel (and,
 * when known, a project / sub-project / system), worked by Golden A/S,
 * closed with a resolution. Comments (reply / delete), attachments, trail.
 * Work orders and service reports may answer a ticket.        GAS-CP-R14-001
 */

function tk_ip(): string {
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return mb_substr($ip, 0, 45);
}
function tk_has(): bool {
    static $has = null;
    if ($has === null) { try { $has = (bool) db()->query("SHOW TABLES LIKE 'tickets'")->fetch(); } catch (Throwable $e) { $has = false; } }
    return $has;
}
function tk_categories(): array { return ['fault' => t('tk.c_fault'), 'service' => t('tk.c_service'), 'warranty' => t('tk.c_warranty'), 'spare_parts' => t('tk.c_spare_parts'), 'question' => t('tk.c_question'), 'complaint' => t('tk.c_complaint'), 'other' => t('tk.c_other')]; }
function tk_priorities(): array { return ['low' => t('tk.p_low'), 'normal' => t('tk.p_normal'), 'high' => t('tk.p_high'), 'urgent' => t('tk.p_urgent')]; }
function tk_statuses(): array   { return ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed']; }
function tk_open_statuses(): array { return ['open', 'in_progress', 'waiting_customer']; }
function tk_status_pill(string $s): string {
    $m = ['open' => 'gold', 'in_progress' => 'blue', 'waiting_customer' => 'bad', 'resolved' => 'green', 'closed' => 'mute'];
    return pill($m[$s] ?? 'mute', t('tk.st_' . $s));
}
function tk_priority_pill(string $p): string {
    $m = ['low' => 'mute', 'normal' => 'mute', 'high' => 'gold', 'urgent' => 'bad'];
    return $p === 'normal' ? '' : pill($m[$p] ?? 'mute', t('tk.p_' . $p));
}

/** Vessel scope: customers only their own fleet. */
function tk_scope(): array { return is_staff() ? ['1 = 1', []] : ['v.customer_id = ?', [(int)current_user()['customer_id']]]; }

function tk_event(int $id, string $event, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO ticket_events (ticket_id, event, user_id, user_name, note, ip) VALUES (?,?,?,?,?,?)', [$id, $event, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, $note, tk_ip()]);
}
function tk_load(int $id): ?array {
    if (!tk_has()) return null;
    [$scope, $args] = tk_scope(); array_unshift($args, $id);
    $t = one("SELECT t.*, v.name AS vessel, v.imo, c.name AS customer_name, p.project_code, p.name AS project_name, s.code AS sub_code, s.name AS sub_name,
                     vs.abbr AS system_abbr, vs.name AS system_name, a.full_name AS assignee
              FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id LEFT JOIN customers c ON c.id = v.customer_id
              LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects s ON s.id = t.subproject_id LEFT JOIN vessel_systems vs ON vs.id = t.system_id
              LEFT JOIN customer_users a ON a.id = t.assigned_to WHERE t.id = ? AND {$scope}", $args);
    if (!$t) return null;
    $vis = is_staff() ? '' : " AND visibility = 'external'";
    $t['comments'] = all("SELECT * FROM ticket_comments WHERE ticket_id = ?{$vis} ORDER BY id", [$id]);
    $t['files']    = all('SELECT * FROM ticket_files WHERE ticket_id = ? ORDER BY id', [$id]);
    $t['events']   = all('SELECT * FROM ticket_events WHERE ticket_id = ? ORDER BY id', [$id]);
    $t['jos']      = all('SELECT id, jo_no, title, status FROM job_orders WHERE ticket_id = ? ORDER BY id', [$id]);
    $t['fsrs']     = all("SELECT id, report_no, report_date, status FROM fsr_reports WHERE ticket_id = ? " . (is_staff() ? '' : "AND status <> 'draft' ") . 'ORDER BY report_date', [$id]);
    return $t;
}
function tk_list(array $f = []): array {
    if (!tk_has()) return [];
    [$scope, $args] = tk_scope(); $w = [$scope];
    foreach (['vessel' => 't.vessel_id', 'project' => 't.project_id', 'assigned' => 't.assigned_to', 'customer' => 'v.customer_id'] as $k => $col) if (!empty($f[$k])) { $w[] = "{$col} = ?"; $args[] = (int)$f[$k]; }
    if (!empty($f['status'])) { if ($f['status'] === 'open_all') $w[] = "t.status IN ('open','in_progress','waiting_customer')"; elseif (in_array($f['status'], tk_statuses(), true)) { $w[] = 't.status = ?'; $args[] = $f['status']; } }
    if (!empty($f['priority']) && array_key_exists($f['priority'], tk_priorities())) { $w[] = 't.priority = ?'; $args[] = $f['priority']; }
    if (!empty($f['category']) && array_key_exists($f['category'], tk_categories())) { $w[] = 't.category = ?'; $args[] = $f['category']; }
    if (!empty($f['side']) && in_array($f['side'], ['customer', 'golden'], true)) { $w[] = 't.created_side = ?'; $args[] = $f['side']; }
    if (!empty($f['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$f['from'])) { $w[] = 't.created_at >= ?'; $args[] = $f['from'] . ' 00:00:00'; }   // R38-004: "raised in the last 90 d" links
    if (!empty($f['q'])) { $w[] = '(t.ticket_no LIKE ? OR t.title LIKE ? OR t.description LIKE ? OR v.name LIKE ?)'; $like = '%' . $f['q'] . '%'; array_push($args, $like, $like, $like, $like); }
    return all("SELECT t.*, v.name AS vessel, c.name AS customer_name, p.project_code, sp.code AS subproject_code, a.full_name AS assignee,
                       (SELECT COUNT(*) FROM ticket_comments x WHERE x.ticket_id = t.id AND x.deleted_at IS NULL" . (is_staff() ? '' : " AND x.visibility = 'external'") . ") AS n_comments
                FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id LEFT JOIN customers c ON c.id = v.customer_id LEFT JOIN projects p ON p.id = t.project_id LEFT JOIN subprojects sp ON sp.id = t.subproject_id LEFT JOIN customer_users a ON a.id = t.assigned_to
                WHERE " . implode(' AND ', $w) . " ORDER BY FIELD(t.status,'open','waiting_customer','in_progress','resolved','closed'), FIELD(t.priority,'urgent','high','normal','low'), t.created_at DESC, t.id DESC", $args);
}
/** Tickets needing attention for the dashboard: staff → open/unassigned or assigned to me; customers → waiting for them. */
function tk_attention(): array {
    if (!tk_has()) return [];
    if (is_staff()) { $u = current_user(); $rows = tk_list(['status' => 'open_all']); return array_values(array_filter($rows, fn($t) => $t['status'] !== 'waiting_customer' && (!$t['assigned_to'] || (int)$t['assigned_to'] === (int)$u['id'] || is_cp_admin()))); }
    return tk_list(['status' => 'waiting_customer']);
}

/** Summary strip for the tickets page: aging of open tickets, new unassigned,
 *  resolution time, activity per Golden A/S staff and per customer (last 90 days). */
/** $af: 'from' / 'to' (Y-m-d) limit the two activity tables (per staff, per customer) to tickets raised in the window; the KPIs stay global. */
function tk_summary(array $af = []): array {
    [$scope, $args] = tk_scope();
    $rows = all("SELECT t.*, v.name AS vessel, COALESCE(v.customer_id, t.customer_id) AS customer_id, c.name AS customer_name FROM tickets t LEFT JOIN vessels v ON v.id = t.vessel_id LEFT JOIN customers c ON c.id = COALESCE(v.customer_id, t.customer_id) WHERE {$scope}", $args);   // the vessel's current owner, not the owner at the time the ticket was raised
    $now = time(); $days = fn(string $a, string $b) => (strtotime($b) - strtotime($a)) / 86400;
    $S = ['open' => 0, 'aging' => ['d3' => 0, 'd7' => 0, 'd30' => 0, 'd30p' => 0], 'oldest' => null, 'unassigned' => 0, 'new7' => 0, 'waiting' => 0, 'resolved30' => 0,
          'res_avg' => null, 'res_median' => null, 'res_max' => null, 'first_avg' => null, 'staff' => [], 'customers' => [], 'by_status' => [], 'by_priority' => []];
    $res = []; $first = []; $since90 = date('Y-m-d', $now - 90 * 86400);
    $cm = []; foreach (all("SELECT user_id, side, COUNT(*) n, MAX(created_at) last FROM ticket_comments WHERE deleted_at IS NULL AND created_at >= ? GROUP BY user_id, side", [$since90]) as $r) $cm[(int)$r['user_id']] = $r;
    $ev = []; foreach (all("SELECT ticket_id, MIN(created_at) first_golden FROM ticket_events WHERE event IN ('assigned','status_in_progress','status_waiting_customer','status_resolved') GROUP BY ticket_id") as $r) $ev[(int)$r['ticket_id']] = $r['first_golden'];
    $afFrom = !empty($af['from']) ? $af['from'] . ' 00:00:00' : null; $afTo = !empty($af['to']) ? $af['to'] . ' 23:59:59' : null;
    foreach ($rows as $t) {
        $S['by_status'][$t['status']] = ($S['by_status'][$t['status']] ?? 0) + 1;
        $isOpen = in_array($t['status'], tk_open_statuses(), true);
        $age = ($now - strtotime($t['created_at'])) / 86400;
        if ($isOpen) {
            $S['open']++; $S['by_priority'][$t['priority']] = ($S['by_priority'][$t['priority']] ?? 0) + 1;
            $S['aging'][$age < 3 ? 'd3' : ($age < 7 ? 'd7' : ($age < 30 ? 'd30' : 'd30p'))]++;
            if ($S['oldest'] === null || $age > $S['oldest']['age']) $S['oldest'] = ['age' => $age, 'id' => $t['id'], 'no' => $t['ticket_no'], 'title' => $t['title']];
            if (!$t['assigned_to']) $S['unassigned']++;
            if ($t['status'] === 'waiting_customer') $S['waiting']++;
        }
        if ($age < 7) $S['new7']++;
        if ($t['resolved_at']) { $d = $days($t['created_at'], $t['resolved_at']); $res[] = $d; if (strtotime($t['resolved_at']) >= $now - 30 * 86400) $S['resolved30']++; }
        if (!empty($ev[(int)$t['id']])) $first[] = $days($t['created_at'], $ev[(int)$t['id']]);
        if (($afFrom && $t['created_at'] < $afFrom) || ($afTo && $t['created_at'] > $afTo)) continue;   // outside the activity window: KPIs counted, tables not
        // per Golden A/S staff (assignee)
        if ($t['assigned_to']) {
            $k = (int)$t['assigned_to']; $S['staff'][$k] = $S['staff'][$k] ?? ['name' => $t['assigned_name'], 'open' => 0, 'waiting' => 0, 'resolved' => 0, 'res' => [], 'comments' => (int)($cm[$k]['n'] ?? 0), 'last' => $cm[$k]['last'] ?? null];
            if ($isOpen) { $S['staff'][$k]['open']++; if ($t['status'] === 'waiting_customer') $S['staff'][$k]['waiting']++; }
            if ($t['resolved_at']) { $S['staff'][$k]['resolved']++; $S['staff'][$k]['res'][] = $days($t['created_at'], $t['resolved_at']); }
        }
        // per customer
        $k = (int)($t['customer_id'] ?? 0); $S['customers'][$k] = $S['customers'][$k] ?? ['name' => $t['customer_name'] ?: '—', 'raised' => 0, 'raised90' => 0, 'open' => 0, 'waiting' => 0, 'resolved' => 0, 'res' => [], 'last' => null, 'vessels' => []];
        $c = &$S['customers'][$k]; $c['raised']++; if ($age < 90) $c['raised90']++; if ($isOpen) { $c['open']++; if ($t['status'] === 'waiting_customer') $c['waiting']++; }
        if ($t['resolved_at']) { $c['resolved']++; $c['res'][] = $days($t['created_at'], $t['resolved_at']); }
        if ($c['last'] === null || $t['updated_at'] > $c['last']) $c['last'] = $t['updated_at']; $c['vessels'][$t['vessel']] = true; unset($c);
    }
    $avg = fn(array $a) => $a ? array_sum($a) / count($a) : null;
    $median = function (array $a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; };
    $S['res_avg'] = $avg($res); $S['res_median'] = $median($res); $S['res_max'] = $res ? max($res) : null; $S['first_avg'] = $avg($first); $S['resolved_total'] = count($res);
    foreach ($S['staff'] as &$x) { $x['res_avg'] = $avg($x['res']); unset($x['res']); } unset($x);
    foreach ($S['customers'] as &$x) { $x['res_avg'] = $avg($x['res']); $x['vessels'] = count($x['vessels']); unset($x['res']); } unset($x);
    // staff who commented but were never assigned
    foreach ($cm as $uid => $r) if ($r['side'] === 'golden' && $uid && !isset($S['staff'][$uid])) { $n = scalar('SELECT full_name FROM customer_users WHERE id = ?', [$uid]); $S['staff'][$uid] = ['name' => $n, 'open' => 0, 'waiting' => 0, 'resolved' => 0, 'comments' => (int)$r['n'], 'last' => $r['last'], 'res_avg' => null]; }
    uasort($S['staff'], fn($a, $b) => [$b['open'], $b['resolved']] <=> [$a['open'], $a['resolved']]);
    uasort($S['customers'], fn($a, $b) => [$b['open'], $b['raised']] <=> [$a['open'], $a['raised']]);
    return $S;
}
/** "2.5 d", "6 h" */
function tk_fmt_days(?float $d): string {
    if ($d === null) return '—';
    if ($d < 1) return max(1, (int) round($d * 24)) . ' ' . t('tk.h');
    return rtrim(rtrim(number_format($d, 1, '.', ''), '0'), '.') . ' ' . t('tk.d');
}

function tk_number(): string {
    $base = 'TK-' . date('Y') . '-';
    $n = (int) scalar('SELECT COUNT(*) FROM tickets WHERE ticket_no LIKE ?', [$base . '%']) + 1;
    while (scalar('SELECT COUNT(*) FROM tickets WHERE ticket_no = ?', [sprintf('%s%04d', $base, $n)])) $n++;
    return sprintf('%s%04d', $base, $n);
}
function tk_save_from_post(int $id): int {
    $u = current_user();
    $vid = (int)($_POST['vessel_id'] ?? 0);
    [$scope, $args] = tk_scope(); array_unshift($args, $vid);
    $v = one("SELECT v.* FROM vessels v WHERE v.id = ? AND {$scope}", $args);
    if (!$v) throw new RuntimeException(t('err.notfound'));
    $pid = (int)($_POST['project_id'] ?? 0) ?: null;
    if ($pid && !scalar('SELECT COUNT(*) FROM projects WHERE id = ? AND vessel_id = ?', [$pid, $vid])) $pid = null;
    $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
    if ($sid && (!$pid || !scalar('SELECT COUNT(*) FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid]))) $sid = null;
    $sys = (int)($_POST['system_id'] ?? 0) ?: null;
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 160);
    if ($title === '') throw new RuntimeException(t('tk.title_req'));
    $desc = mb_substr(trim((string)($_POST['description'] ?? '')), 0, 8000) ?: null;
    $cat = array_key_exists($_POST['category'] ?? '', tk_categories()) ? $_POST['category'] : 'fault';
    $pri = array_key_exists($_POST['priority'] ?? '', tk_priorities()) ? $_POST['priority'] : 'normal';
    if ($id) {
        $t = tk_load($id);
        if (!$t) throw new RuntimeException(t('err.notfound'));
        if (!is_staff() && ((int)$t['created_by'] !== (int)$u['id'] || !in_array($t['status'], tk_open_statuses(), true))) throw new RuntimeException(t('tk.locked'));
        q('UPDATE tickets SET vessel_id = ?, customer_id = ?, project_id = ?, subproject_id = ?, system_id = ?, title = ?, description = ?, category = ?, priority = ? WHERE id = ?',
          [$vid, $v['customer_id'], $pid, $sid, $sys, $title, $desc, $cat, $pri, $id]);
        tk_event($id, 'edited');
    } else {
        q('INSERT INTO tickets (ticket_no, customer_id, vessel_id, project_id, subproject_id, system_id, title, description, category, priority, created_side, created_by, created_name)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
          [tk_number(), $v['customer_id'], $vid, $pid, $sid, $sys, $title, $desc, $cat, $pri, is_staff() ? 'golden' : 'customer', (int)$u['id'], $u['full_name']]);
        $id = (int) db()->lastInsertId();
        tk_event($id, 'created');
    }
    if (function_exists('rs_has') && rs_has()) rs_cover_ticket($id, $vid);   // R30-004: covered by the vessel's remote support contract
    tk_store_uploads($id, null);
    return $id;
}
/** Attachments: images, PDF, Office, drawings — up to 10 per post, 20 MB each. */
function tk_store_uploads(int $ticketId, ?int $commentId): int {
    $u = current_user(); $n = 0;
    $f = $_FILES['files'] ?? null; if (!$f || !is_array($f['name'])) return 0;
    foreach (array_slice($f['name'], 0, 10, true) as $i => $name) {
        if (($f['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'][$i]) || $f['size'][$i] > 20 * 1024 * 1024) continue;
        $ext = strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'docx', 'xlsx', 'pptx', 'dwg', 'dxf', 'txt', 'csv', 'zip', 'mp4', 'mov'], true)) continue;
        $mime = (string)(function_exists('mime_content_type') ? @mime_content_type($f['tmp_name'][$i]) : '') ?: ($f['type'][$i] ?? 'application/octet-stream');
        $dir = cp_storage() . '/tickets/' . date('Y/m'); if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $stored = 'tickets/' . date('Y/m') . '/' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'][$i], cp_storage() . '/' . $stored)) continue;
        q('INSERT INTO ticket_files (ticket_id, comment_id, stored_name, original_name, mime_type, size_bytes, uploaded_by, uploaded_name) VALUES (?,?,?,?,?,?,?,?)',
          [$ticketId, $commentId, $stored, mb_substr((string)$name, 0, 255), mb_substr($mime, 0, 120), (int)$f['size'][$i], (int)$u['id'], $u['full_name']]);
        if (function_exists('ar_file_new')) ar_file_new('ticket_files', (int) db()->lastInsertId());     // R20
        $n++;
    }
    return $n;
}
function tk_stream_file(int $fileId): void {
    $f = one('SELECT f.*, t.vessel_id FROM ticket_files f JOIN tickets t ON t.id = f.ticket_id WHERE f.id = ?', [$fileId]);
    if (!$f || !tk_load((int)$f['ticket_id'])) { http_response_code(404); exit('Not found'); }
    $path = cp_storage() . '/' . $f['stored_name']; if (!is_file($path)) { http_response_code(404); exit('File missing'); }
    header('Content-Type: ' . $f['mime_type']); header('Content-Length: ' . filesize($path)); header('Cache-Control: private, max-age=600');
    $inline = str_starts_with($f['mime_type'], 'image/') || $f['mime_type'] === 'application/pdf';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . rawurlencode($f['original_name']) . '"');
    readfile($path); exit;
}

/** Link a work order / service report to the ticket it answers (same project or vessel). */
function tk_link(string $table, int $rowId, int $ticketId): void {
    if (!tk_has() || !in_array($table, ['job_orders', 'fsr_reports'], true)) return;
    $t = $ticketId ? tk_load($ticketId) : null;
    q("UPDATE {$table} SET ticket_id = ? WHERE id = ?", [$t ? $ticketId : null, $rowId]);
    if ($t) tk_event($ticketId, $table === 'job_orders' ? 'jo_linked' : 'fsr_linked', (string)scalar("SELECT " . ($table === 'job_orders' ? 'jo_no' : 'report_no') . " FROM {$table} WHERE id = ?", [$rowId]));
}
/** Open tickets of a project's vessel, for the "related ticket" select. */
function tk_options_for_project(int $pid): array {
    if (!tk_has()) return [];
    return all("SELECT t.id, t.ticket_no, t.title FROM tickets t JOIN projects p ON p.vessel_id = t.vessel_id WHERE p.id = ? AND (t.project_id IS NULL OR t.project_id = p.id) ORDER BY FIELD(t.status,'open','in_progress','waiting_customer','resolved','closed'), t.id DESC", [$pid]);
}

/* -------------------------------------------------------- workflow */
function tk_comment(int $id, string $body, ?int $parent, string $vis = 'external'): int {
    $u = current_user(); $body = mb_substr(trim($body), 0, 8000);
    if ($body === '') throw new RuntimeException(t('oa.comment_req'));
    if ($parent && !scalar('SELECT COUNT(*) FROM ticket_comments WHERE id = ? AND ticket_id = ?', [$parent, $id])) $parent = null;
    $vis = is_staff() && $vis === 'internal' ? 'internal' : 'external';
    q('INSERT INTO ticket_comments (ticket_id, parent_id, user_id, author, side, visibility, body, ip) VALUES (?,?,?,?,?,?,?,?)',
      [$id, $parent, (int)$u['id'], $u['full_name'], is_staff() ? 'golden' : 'customer', $vis, $body, tk_ip()]);
    $cid = (int) db()->lastInsertId();
    // a customer answering a "waiting for customer" ticket re-opens the work
    $t = one('SELECT status FROM tickets WHERE id = ?', [$id]);
    if (!is_staff() && $t && $t['status'] === 'waiting_customer') { q('UPDATE tickets SET status = "in_progress" WHERE id = ?', [$id]); tk_event($id, 'customer_replied'); }
    return $cid;
}
function tk_comment_delete(int $id, int $cid): void {
    $u = current_user();
    $c = one('SELECT * FROM ticket_comments WHERE id = ? AND ticket_id = ?', [$cid, $id]);
    if (!$c || ((int)$c['user_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('cm.not_yours'));
    if (scalar('SELECT COUNT(*) FROM ticket_comments WHERE parent_id = ? AND deleted_at IS NULL', [$cid])) q('UPDATE ticket_comments SET deleted_at = NOW(), body = NULL WHERE id = ?', [$cid]);
    else q('DELETE FROM ticket_comments WHERE id = ?', [$cid]);
}
function tk_set_status(int $id, string $status, string $note = ''): void {
    $t = tk_load($id); $u = current_user();
    if (!$t || !in_array($status, tk_statuses(), true)) throw new RuntimeException(t('err.notfound'));
    $note = mb_substr(trim($note), 0, 4000);
    if (!is_staff()) {                                   // the owner may close a resolved ticket or re-open it
        if (!(($status === 'closed' && $t['status'] === 'resolved') || ($status === 'open' && in_array($t['status'], ['resolved', 'closed'], true)))) throw new RuntimeException(t('tk.locked'));
    }
    if ($status === 'resolved' && $note === '') throw new RuntimeException(t('tk.resolution_req'));
    $sets = ['status = ?']; $vals = [$status];
    if ($status === 'resolved') { $sets[] = 'resolution = ?'; $vals[] = $note; $sets[] = 'resolved_at = NOW()'; $sets[] = 'resolved_name = ?'; $vals[] = $u['full_name']; }
    if ($status === 'closed')   { $sets[] = 'closed_at = NOW()'; }
    if ($status === 'open' && in_array($t['status'], ['resolved', 'closed'], true)) { $sets[] = 'resolved_at = NULL'; $sets[] = 'closed_at = NULL'; }
    $vals[] = $id;
    q('UPDATE tickets SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
    tk_event($id, 'status_' . $status, $note ?: null);
}
/** Golden A/S puts a ticket raised as generic vessel support under a project / sub-project once one exists. */
function tk_set_project(int $id, ?int $pid, ?int $sid, int|null|false $vid = false): void {
    $t = tk_load($id); if (!$t || !is_staff()) throw new RuntimeException(t('err.notfound'));
    $vesselId = (int)$t['vessel_id'] ?: null;
    if ($vid !== false && $vid !== $vesselId) {                                        // staff may (re)place the ticket on a vessel — needed for tickets imported without one (R29-010)
        $v = $vid ? one('SELECT id, customer_id FROM vessels WHERE id = ?', [$vid]) : null;
        if ($vid && !$v) throw new RuntimeException(t('err.notfound'));
        $vesselId = $v['id'] ?? null;
        q('UPDATE tickets SET vessel_id = ?, customer_id = COALESCE(?, customer_id) WHERE id = ?', [$vesselId, $v['customer_id'] ?? null, $id]);
        if (function_exists('rs_has') && rs_has()) rs_cover_ticket($id, $vesselId);
        tk_event($id, 'vessel_set', $v ? (string)scalar('SELECT name FROM vessels WHERE id = ?', [$v['id']]) : null);
    }
    $p = $pid ? one('SELECT id, project_code, name FROM projects WHERE id = ? AND vessel_id = ?', [$pid, (int)$vesselId]) : null;
    if ($pid && !$p) throw new RuntimeException(t('tk.project_not_vessel'));
    $sub = ($p && $sid) ? one('SELECT id, code, name FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $p['id']]) : null;
    q('UPDATE tickets SET project_id = ?, subproject_id = ? WHERE id = ?', [$p['id'] ?? null, $sub['id'] ?? null, $id]);
    // work already linked to the ticket keeps its own project; nothing else moves
    tk_event($id, $p ? 'project_set' : 'project_cleared', $p ? trim(($p['project_code'] ?: $p['name']) . ($sub ? ' › ' . $sub['code'] . ' ' . $sub['name'] : '')) : null);
}
function tk_assign(int $id, ?int $uid): void {
    $t = tk_load($id); if (!$t || !is_staff()) throw new RuntimeException(t('err.notfound'));
    $a = $uid ? one("SELECT id, full_name FROM customer_users WHERE id = ? AND role IN ('staff','admin') AND is_active = 1", [$uid]) : null;
    q('UPDATE tickets SET assigned_to = ?, assigned_name = ?, status = IF(status = "open" AND ? IS NOT NULL, "in_progress", status) WHERE id = ?', [$a['id'] ?? null, $a['full_name'] ?? null, $a['id'] ?? null, $id]);
    tk_event($id, $a ? 'assigned' : 'unassigned', $a['full_name'] ?? null);
}

/* ----------------------------------------- vessel technical history */

/** Everything the vessel page and the vessel report need. Customers see only
 *  external data of their own vessel; hours are summarised, never priced. */
function vessel_history(int $vid, ?int $onlyProject = null): ?array {
    [$scope, $args] = tk_scope(); array_unshift($args, $vid);
    $v = one("SELECT v.*, c.name AS customer_name FROM vessels v LEFT JOIN customers c ON c.id = v.customer_id WHERE v.id = ? AND {$scope}", $args);
    if (!$v) return null;
    $staff = is_staff();
    $h = ['vessel' => $v];
    $h['projects'] = all("SELECT p.*, (SELECT COUNT(*) FROM subprojects s WHERE s.project_id = p.id) AS n_subs FROM projects p WHERE p.vessel_id = ? ORDER BY p.created_at, p.project_code", [$vid]);
    $h['subs'] = all("SELECT s.*, p.project_code, vs.abbr AS system_abbr, vs.name AS system_name FROM subprojects s JOIN projects p ON p.id = s.project_id LEFT JOIN vessel_systems vs ON vs.id = s.system_id
                      WHERE p.vessel_id = ? ORDER BY p.project_code, s.sort_order, s.code", [$vid]);
    $h['tickets'] = tk_has() ? all("SELECT t.*, p.project_code FROM tickets t LEFT JOIN projects p ON p.id = t.project_id WHERE t.vessel_id = ?" . ($onlyProject ? ' AND t.project_id = ' . (int)$onlyProject : '') . " ORDER BY t.created_at", [$vid]) : [];
    if ($onlyProject) { $h['projects'] = array_values(array_filter($h['projects'], fn($p) => (int)$p['id'] === $onlyProject)); $h['subs'] = array_values(array_filter($h['subs'], fn($x) => (int)$x['project_id'] === $onlyProject)); }
    $h['only_project'] = $onlyProject ? ($h['projects'][0] ?? null) : null;
    $tkj = tk_has() ? 'LEFT JOIN tickets tk ON tk.id = %s.ticket_id' : ''; $tkc = tk_has() ? 'tk.ticket_no, tk.id AS ticket_pk' : 'NULL AS ticket_no, NULL AS ticket_pk';
    $pf = $onlyProject ? ' AND p.id = ' . (int)$onlyProject : '';
    $h['fsrs'] = all("SELECT f.*, p.project_code, s.code AS sub_code, s.name AS sub_name, {$tkc} FROM fsr_reports f JOIN projects p ON p.id = f.project_id LEFT JOIN subprojects s ON s.id = f.subproject_id " . sprintf($tkj, 'f') . "
                      WHERE p.vessel_id = ?{$pf} " . ($staff ? '' : "AND f.status <> 'draft' ") . "ORDER BY f.report_date, f.id", [$vid]);
    $ids = array_map(fn($r) => (int)$r['id'], $h['fsrs']);
    $in = $ids ? implode(',', $ids) : '0';
    $fsrById = array_column($h['fsrs'], null, 'id');
    $works = all("SELECT w.*, f.report_date, f.report_no FROM fsr_works w JOIN fsr_reports f ON f.id = w.report_id WHERE w.report_id IN ({$in}) ORDER BY f.report_date, w.report_id, w.seq");
    $lines = all("SELECT l.*, f.report_date, f.report_no FROM fsr_lines l JOIN fsr_reports f ON f.id = l.report_id WHERE l.report_id IN ({$in}) ORDER BY f.report_date, l.report_id, l.seq");
    // services: one row per task (what was done), chronological
    $h['services'] = []; $h['parts'] = [];
    foreach ($works as $w) {
        $fr = $fsrById[$w['report_id']] ?? []; $ctx = ['project' => $fr['project_code'] ?? '', 'sub' => $fr['sub_code'] ?? '', 'ticket' => $fr['ticket_no'] ?? '', 'ticket_pk' => $fr['ticket_pk'] ?? null, 'fsr_pk' => $w['report_id']];
        if ($w['description']) $h['services'][] = ['date' => $w['report_date'], 'ref' => $w['report_no'], 'what' => $w['description'], 'issues' => $w['open_issues']] + $ctx;
        foreach (json_decode((string)$w['parts'], true) ?: [] as $pt) $h['parts'][] = ['date' => $w['report_date'], 'ref' => $w['report_no'], 'part' => $pt['d'] ?? '', 'type' => $pt['t'] ?? 'I'] + $ctx;
    }
    // technicians who attended: engineer + discipline, with hours and visits (from service reports, both sides see this)
    $tech = [];
    foreach ($lines as $l) {
        $k = mb_strtolower(trim((string)$l['engineer'])) . '|' . mb_strtolower(trim((string)$l['specialty']));
        $tech[$k] = $tech[$k] ?? ['name' => $l['engineer'] ?: '—', 'discipline' => $l['specialty'] ?: '—', 'hours' => 0.0, 'reports' => [], 'first' => $l['report_date'], 'last' => $l['report_date']];
        $tech[$k]['hours'] += (float)$l['hours']; $tech[$k]['reports'][$l['report_id']] = true;
        $tech[$k]['first'] = min($tech[$k]['first'], $l['report_date']); $tech[$k]['last'] = max($tech[$k]['last'], $l['report_date']);
    }
    usort($tech, fn($a, $b) => [$a['discipline'], $a['name']] <=> [$b['discipline'], $b['name']]);
    $h['technicians'] = array_map(fn($x) => $x + ['visits' => count($x['reports'])], $tech);
    $disc = [];
    foreach ($h['technicians'] as $x) { $disc[$x['discipline']] = $disc[$x['discipline']] ?? ['discipline' => $x['discipline'], 'people' => [], 'hours' => 0.0]; $disc[$x['discipline']]['people'][] = $x['name']; $disc[$x['discipline']]['hours'] += $x['hours']; }
    $h['disciplines'] = array_values($disc);
    // staff only: timesheet hours per discipline for the vessel's projects
    $h['timesheets'] = ($staff && function_exists('ts_has') && ts_has()) ? ts_manhours(null, ['approved', 'invoiced'], null, null, $vid) : null;
    $h['jos'] = all("SELECT j.*, p.project_code, {$tkc} FROM job_orders j JOIN projects p ON p.id = j.project_id " . sprintf($tkj, 'j') . " WHERE p.vessel_id = ?{$pf} " . ($staff ? '' : "AND NOT (j.status = 'draft' AND j.requested_side <> 'customer') ") . "ORDER BY j.created_at", [$vid]);
    $h['approvals'] = all("SELECT o.*, p.project_code FROM owner_approvals o JOIN projects p ON p.id = o.project_id WHERE p.vessel_id = ?{$pf} " . ($staff ? '' : "AND o.status <> 'draft' ") . "ORDER BY o.submitted_at, o.id", [$vid]);
    // one chronological timeline across everything
    $tl = [];
    foreach ($h['projects'] as $p) $tl[] = ['date' => substr((string)$p['created_at'], 0, 10), 'kind' => 'project', 'ref' => $p['project_code'] ?: $p['name'], 'project' => $p['project_code'] ?: $p['name'], 'ticket' => '', 'text' => $p['name'] . ' · ' . t('pr.' . $p['status'])];
    foreach ($h['tickets'] as $tk) $tl[] = ['date' => substr((string)$tk['created_at'], 0, 10), 'kind' => 'ticket', 'ref' => $tk['ticket_no'], 'project' => $tk['project_code'] ?? '', 'ticket' => $tk['ticket_no'], 'text' => $tk['title'] . ' · ' . t('tk.st_' . $tk['status']) . ($tk['resolution'] ? ' — ' . mb_substr($tk['resolution'], 0, 120) : '')];
    foreach ($h['jos'] as $j) $tl[] = ['date' => substr((string)$j['created_at'], 0, 10), 'kind' => 'jo', 'ref' => $j['jo_no'], 'project' => $j['project_code'] ?? '', 'ticket' => $j['ticket_no'] ?? '', 'text' => $j['title'] . ' · ' . t('jo.st_' . $j['status'])];
    foreach ($h['fsrs'] as $f) $tl[] = ['date' => $f['report_date'], 'kind' => 'fsr', 'ref' => $f['report_no'], 'project' => $f['project_code'] ?? '', 'ticket' => $f['ticket_no'] ?? '', 'text' => implode(' · ', array_filter([$f['sub_code'] ? $f['sub_code'] . ' ' . $f['sub_name'] : '', $f['location'], t('fsr.st_' . $f['status']), $f['submitted_name']]))];
    foreach ($h['approvals'] as $o) $tl[] = ['date' => (string)($o['submitted_at'] ?: substr((string)$o['updated_at'], 0, 10)), 'kind' => 'approval', 'ref' => $o['doc_no'], 'project' => $o['project_code'] ?? '', 'ticket' => '', 'text' => $o['title'] . ' ' . $o['revision'] . ' · ' . t('sub.' . $o['status'])];
    usort($tl, fn($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);
    $h['timeline'] = $tl;
    $h['stats'] = ['projects' => count($h['projects']), 'subs' => count($h['subs']), 'tickets' => count($h['tickets']), 'open_tickets' => count(array_filter($h['tickets'], fn($x) => in_array($x['status'], tk_open_statuses(), true))),
                   'fsrs' => count($h['fsrs']), 'jos' => count($h['jos']), 'parts' => count($h['parts']), 'services' => count($h['services']), 'technicians' => count($h['technicians']), 'disciplines' => count($h['disciplines']),
                   'hours' => array_sum(array_column($h['technicians'], 'hours'))];
    return $h;
}

/** Open tickets of a project as a compact block for the project tree (GAS-CP-R30-003). '' when there are none. */
function tk_card_html(int $projectId, ?string $newUrl, int $limit = 6): string {
    if (!tk_has() || !$projectId) return '';
    $rows = tk_list(['project' => $projectId, 'status' => 'open_all']);
    $n = count($rows); if (!$n) return '';
    $allUrl = url('tickets', ['view' => 'open', 'project' => $projectId, 'status' => 'open_all']);
    $h = '<div class="tk-block"><div class="page-head" style="margin-bottom:6px;"><h3 class="ed-h" style="margin:0;"><a href="' . $allUrl . '" style="text-decoration:none;color:inherit;">' . tt('tk.open_in_project') . '</a> <span class="count">' . $n . '</span></h3>'
       . ($newUrl ? '<a class="btn btn--ghost btn--sm" href="' . $newUrl . '">+ ' . tt('tk.new') . '</a>' : '') . '</div><ul class="td-mini tk-mini">';
    foreach (array_slice($rows, 0, $limit) as $t) {
        $age = max(0, (int) floor((time() - strtotime((string)$t['created_at'])) / 86400));
        $h .= '<li' . ($t['status'] === 'waiting_customer' ? ' class="is-wait"' : '') . '><span class="tk-mini-no"><a href="' . url('ticket', ['id' => $t['id']]) . '">' . e($t['ticket_no']) . '</a></span>'
            . '<span class="td-mini-main"><a class="td-title" href="' . url('ticket', ['id' => $t['id']]) . '">' . e($t['title']) . '</a><span class="td-meta">' . tk_status_pill($t['status']) . ' ' . tk_priority_pill($t['priority'])
            . (!empty($t['subproject_code']) ? '<span class="td-ctx">' . e($t['subproject_code']) . '</span>' : '')
            . '<span class="td-ctx">' . e($t['assignee'] ?: t('tk.unassigned')) . ' · ' . e(t('tk.age_d', ['n' => $age])) . '</span></span></span></li>';
    }
    $h .= '</ul>' . ($n > $limit ? '<p class="hint" style="margin:6px 0 0;"><a href="' . $allUrl . '">' . e(t('tk.more_open', ['n' => $n - $limit])) . ' ›</a></p>' : '') . '</div>';
    return $h;
}

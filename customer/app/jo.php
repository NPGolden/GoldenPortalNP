<?php
/**
 * Work Orders (Job Orders).                                   GAS-CP-R8-001
 *
 * Raised by Golden A/S or by the customer, linked to a main project and
 * numbered <project code>-JO-<seq>-<YYYYMMDD>. Golden A/S only works on an
 * order the customer has approved: an order the customer raises is approved
 * by them on submission; one Golden A/S raises waits for the customer's
 * approval. Every step is logged with user, time and address.
 */
declare(strict_types=1);

function jo_statuses(): array { return ['draft', 'pending_approval', 'approved', 'rejected', 'in_progress', 'done', 'cancelled']; }

function jo_ip(): ?string {
    $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return $ip !== '' ? mb_substr($ip, 0, 45) : null;
}

function jo_event(int $id, string $event, ?string $note = null): void {
    $u = current_user();
    q('INSERT INTO jo_events (job_order_id, event, user_id, user_name, ip, note) VALUES (?,?,?,?,?,?)',
      [$id, $event, (int)($u['id'] ?? 0) ?: null, $u['full_name'] ?? null, jo_ip(), $note]);
}

/** One order the current person may see (customers: their own, no Golden drafts). */
function jo_load(int $id): ?array {
    [$scope, $args] = scope_sql('p');
    array_unshift($args, $id);
    $j = one("SELECT j.*, p.name AS project_name, p.project_code, p.customer_id, c.name AS customer_name, v.name AS vessel
              FROM job_orders j JOIN projects p ON p.id = j.project_id
              LEFT JOIN customers c ON c.id = p.customer_id LEFT JOIN vessels v ON v.id = p.vessel_id
              WHERE j.id = ? AND {$scope}", $args);
    if (!$j) return null;
    if (!is_staff() && $j['status'] === 'draft' && $j['requested_side'] !== 'customer') return null;
    $j['events']  = all('SELECT * FROM jo_events WHERE job_order_id = ? ORDER BY id', [$id]);
    $j['reports'] = all("SELECT id, report_no, report_date, status, submitted_name, accepted_name, accepted_at, pdf_file_id FROM fsr_reports
                         WHERE job_order_id = ? " . (is_staff() ? '' : "AND status <> 'draft' ") . 'ORDER BY report_date DESC, id DESC', [$id]);
    return $j;
}

/** Orders in scope, optionally for one project / status. */
function jo_list(?int $projectId = null, string $status = '', string $q = ''): array {
    [$scope, $args] = scope_sql('p');
    $w = [$scope];
    if ($projectId) { $w[] = 'j.project_id = ?'; $args[] = $projectId; }
    if ($status !== '' && in_array($status, jo_statuses(), true)) { $w[] = 'j.status = ?'; $args[] = $status; }
    if ($q !== '') { $w[] = '(j.jo_no LIKE ? OR j.title LIKE ? OR j.description LIKE ? OR p.project_code LIKE ?)'; array_push($args, ...array_fill(0, 4, '%' . $q . '%')); }
    if (!is_staff()) $w[] = "NOT (j.status = 'draft' AND j.requested_side <> 'customer')";
    return all("SELECT j.*, p.project_code, p.name AS project_name, v.name AS vessel, c.name AS customer_name,
                       (SELECT COUNT(*) FROM fsr_reports f WHERE f.job_order_id = j.id" . (is_staff() ? '' : " AND f.status <> 'draft'") . ") AS n_reports
                FROM job_orders j JOIN projects p ON p.id = j.project_id
                LEFT JOIN vessels v ON v.id = p.vessel_id LEFT JOIN customers c ON c.id = p.customer_id
                WHERE " . implode(' AND ', $w) . "
                ORDER BY FIELD(j.status,'pending_approval','approved','in_progress','draft','rejected','done','cancelled'), j.id DESC", $args);
}

/** <project code>-JO-<4-digit seq>-<YYYYMMDD>, seq per project. */
function jo_number(array $project, int $seq): string {
    $code = trim((string)($project['project_code'] ?: ($project['erp_code'] ?: 'P' . $project['id'])));
    return sprintf('%s-JO-%04d-%s', $code, $seq, date('Ymd'));
}

/** Create or update from the posted form (draft only). Returns the id. */
function jo_save_from_post(?int $id): int {
    $u = current_user();
    $str = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;
    $pid = (int)($_POST['project_id'] ?? 0);
    [$scope, $args] = scope_sql('p'); array_unshift($args, $pid);
    $p = one("SELECT p.* FROM projects p WHERE p.id = ? AND {$scope}", $args);
    if (!$p) throw new RuntimeException(t('err.notfound'));
    $title = (string)$str('title', 160);
    if ($title === '') throw new RuntimeException(t('jo.need_title'));
    $for = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['requested_for'] ?? '')) ? $_POST['requested_for'] : null;
    $prio = ($_POST['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal';
    if ($id) {
        $j = one('SELECT * FROM job_orders WHERE id = ?', [$id]);
        if (!$j || $j['status'] !== 'draft') throw new RuntimeException(t('jo.locked'));
        if (!is_staff() && (int)$j['requested_by'] !== (int)$u['id']) throw new RuntimeException(t('err.noaccess'));
        q('UPDATE job_orders SET title = ?, description = ?, location = ?, requested_for = ?, priority = ? WHERE id = ?',
          [$title, $str('description', 8000), $str('location', 160), $for, $prio, $id]);
        jo_event($id, 'saved');
        if (isset($_POST['ticket_id'])) tk_link('job_orders', $id, (int)$_POST['ticket_id']);
        return $id;
    }
    $seq = (int) scalar('SELECT COALESCE(MAX(seq), 0) + 1 FROM job_orders WHERE project_id = ?', [$pid]);
    $no = jo_number($p, $seq);
    while (scalar('SELECT COUNT(*) FROM job_orders WHERE jo_no = ?', [$no])) { $seq++; $no = jo_number($p, $seq); }
    q('INSERT INTO job_orders (project_id, seq, jo_no, title, description, location, requested_for, priority, requested_side, requested_by, requested_name)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)',
      [$pid, $seq, $no, $title, $str('description', 8000), $str('location', 160), $for, $prio, is_staff() ? 'golden' : 'customer', (int)$u['id'], $u['full_name']]);
    $id = (int) db()->lastInsertId();
    jo_event($id, 'created');
    if (!empty($_POST['ticket_id'])) tk_link('job_orders', $id, (int)$_POST['ticket_id']);
    return $id;
}

/** Submit a draft: a customer's own order is approved by them at once;
 *  a Golden A/S order goes to the customer for approval. */
function jo_submit(int $id): void {
    $u = current_user();
    $j = one('SELECT * FROM job_orders WHERE id = ?', [$id]);
    if (!$j || $j['status'] !== 'draft') throw new RuntimeException(t('jo.locked'));
    if (!is_staff() && (int)$j['requested_by'] !== (int)$u['id']) throw new RuntimeException(t('err.noaccess'));
    if (!is_staff()) {
        q('UPDATE job_orders SET status = "approved", approved_by = ?, approved_name = ?, approved_at = NOW(), approved_ip = ? WHERE id = ?',
          [(int)$u['id'], $u['full_name'], jo_ip(), $id]);
        jo_event($id, 'submitted'); jo_event($id, 'approved', t('jo.self_approved'));
    } else {
        q('UPDATE job_orders SET status = "pending_approval" WHERE id = ?', [$id]);
        jo_event($id, 'submitted');
    }
}

function jo_status_pill(string $s): string {
    $m = ['draft' => 'mute', 'pending_approval' => 'gold', 'approved' => 'green', 'rejected' => 'bad', 'in_progress' => 'gold', 'done' => 'green', 'cancelled' => 'mute'];
    return pill($m[$s] ?? 'mute', t('jo.st_' . $s));
}

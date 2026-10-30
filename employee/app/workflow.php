<?php
/**
 * GAS-POMS — order lifecycle.
 *
 * Route:
 *   draft → awaiting_pm → awaiting_tm → with_procurement → ordered
 *         → in_warehouse → ready_for_dispatch → in_transit → delivered → closed
 * Branches: rejected_pm, rejected_tm, backordered, partially_fulfilled, cancelled
 */

declare(strict_types=1);

/** Who may move an order from a given status, and where to. */
function allowed_transitions(array $order, array $user): array {
    $s    = $order['status'];
    $role = $user['role_code'];
    $uid  = (int) $user['id'];
    $t    = [];

    $owner = $uid === (int) $order['requested_by'];
    $isPM  = $uid === (int) $order['pm_user_id'];
    $isTM  = $uid === (int) $order['tm_user_id'];

    switch ($s) {
        case 'draft':
            if ($owner || $role === 'system_admin') { $t['awaiting_pm'] = t('move.submit'); $t['cancelled'] = t('move.cancel'); }
            break;

        case 'awaiting_pm':
            if ($isPM || $role === 'system_admin') { $t['awaiting_tm'] = t('move.pm_approve'); $t['rejected_pm'] = t('move.pm_reject'); }
            if ($owner) $t['cancelled'] = 'Cancel order';
            break;

        case 'awaiting_tm':
            if ($isTM || $role === 'system_admin') { $t['with_procurement'] = t('move.tm_approve'); $t['rejected_tm'] = t('move.tm_reject'); }
            if ($owner) $t['cancelled'] = 'Cancel order';
            break;

        case 'rejected_pm':
        case 'rejected_tm':
            if ($owner || $role === 'system_admin') { $t['draft'] = t('move.reopen'); $t['cancelled'] = t('move.cancel'); }
            break;

        case 'with_procurement':
            if ($role === 'procurement' || $role === 'system_admin') { $t['ordered'] = t('move.place_po'); $t['backordered'] = t('move.backorder'); $t['cancelled'] = t('move.cancel'); }
            break;

        case 'ordered':
        case 'backordered':
            if ($role === 'procurement' || $role === 'system_admin') { $t['backordered'] = t('move.backorder'); $t['ordered'] = t('move.mark_ordered'); }
            if ($role === 'warehouse' || $role === 'system_admin')   { $t['in_warehouse'] = t('move.book_in'); }
            break;

        case 'in_warehouse':
            if ($role === 'warehouse' || $role === 'system_admin') { $t['ready_for_dispatch'] = t('move.release'); $t['partially_fulfilled'] = t('move.partial'); }
            break;

        case 'ready_for_dispatch':
            if ($role === 'driver' || $role === 'system_admin')    { $t['in_transit'] = t('move.collect'); }
            if ($role === 'warehouse' || $role === 'system_admin') { $t['in_warehouse'] = t('move.return_wh'); }
            break;

        case 'in_transit':
            if ($role === 'driver' || $role === 'system_admin') { $t['delivered'] = t('move.deliver'); }
            break;

        case 'delivered':
        case 'partially_fulfilled':
            // Owner, admin, OR the driver who delivered it may close it, so a
            // delivered order never strands waiting for someone else to act.
            if ($owner || $role === 'system_admin' || $role === 'driver') { $t['closed'] = t('move.close'); }
            if ($role === 'warehouse' && $s === 'partially_fulfilled') { $t['ready_for_dispatch'] = t('move.release_rest'); }
            break;
    }
    return $t;
}

function can_view_order(array $order, array $user): bool {
    $role = $user['role_code'];
    $uid  = (int) $user['id'];

    if (in_array($role, ['system_admin', 'procurement', 'warehouse'], true)) return true;
    if ($role === 'driver')            return true;  // drivers see the dispatch board
    if ($role === 'project_manager')   return $uid === (int)$order['pm_user_id'];
    if ($role === 'technical_manager') return $uid === (int)$order['tm_user_id'];
    return $uid === (int)$order['requested_by'];
}

/** Everyone who should hear about a change on this order. */
function stakeholders(array $order): array {
    return array_filter([
        (int) $order['requested_by'],
        (int) $order['pm_user_id'],
        (int) $order['tm_user_id'],
        $order['procurement_user_id'] ? (int)$order['procurement_user_id'] : null,
        $order['driver_user_id'] ? (int)$order['driver_user_id'] : null,
    ]);
}

/** Users holding a role — used to alert the next desk in the chain. */
function users_in_role(string $code): array {
    return array_column(
        all('SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = ? AND u.is_active = 1', [$code]),
        'id'
    );
}

function next_desk(string $status): array {
    return [
        'with_procurement'   => users_in_role('procurement'),
        'ordered'            => users_in_role('warehouse'),
        'in_warehouse'       => users_in_role('warehouse'),
        'ready_for_dispatch' => users_in_role('driver'),
    ][$status] ?? [];
}

/**
 * Apply a transition. Records history, updates approvals, sets dates,
 * notifies everyone concerned, and queues a SharePoint push.
 */
function transition(int $orderId, string $to, array $user, ?string $comment = null): void {
    $pdo   = db();
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) throw new RuntimeException(t('err.notfound'));

    $allowed = allowed_transitions($order, $user);
    if (!isset($allowed[$to])) throw new RuntimeException(t('err.no_move'));

    // Hard gate: nothing reaches Procurement while a line lacks its part
    // number. PM, TM (and Procurement later) can fill them on the order page.
    if ($to === 'with_procurement') {
        $missing = array_column(all(
            'SELECT line_no FROM order_items
             WHERE order_id = ? AND (part_number IS NULL OR part_number = "")
             ORDER BY line_no', [$orderId]), 'line_no');
        if ($missing) {
            throw new RuntimeException(t('pn.blocked', ['lines' => implode(', ', $missing)]));
        }
    }

    $from = $order['status'];
    $pdo->beginTransaction();
    try {
        $sets   = ['status = ?'];
        $params = [$to];

        if ($to === 'awaiting_pm' && $from === 'draft') {
            $sets[] = 'submitted_at = NOW()';
            q('INSERT INTO approvals (order_id, level, approver_user_id) VALUES (?, "pm", ?)
               ON DUPLICATE KEY UPDATE decision = "pending", decided_at = NULL, comment = NULL',
              [$orderId, $order['pm_user_id']]);
        }
        if ($to === 'awaiting_tm') {
            q('UPDATE approvals SET decision = "approved", comment = ?, decided_at = NOW()
               WHERE order_id = ? AND level = "pm"', [$comment, $orderId]);
            q('INSERT INTO approvals (order_id, level, approver_user_id) VALUES (?, "tm", ?)
               ON DUPLICATE KEY UPDATE decision = "pending", decided_at = NULL, comment = NULL',
              [$orderId, $order['tm_user_id']]);
        }
        if ($to === 'with_procurement') {
            q('UPDATE approvals SET decision = "approved", comment = ?, decided_at = NOW()
               WHERE order_id = ? AND level = "tm"', [$comment, $orderId]);
            q('UPDATE order_items SET item_status = "approved" WHERE order_id = ? AND item_status = "requested"', [$orderId]);
        }
        if ($to === 'rejected_pm') {
            q('UPDATE approvals SET decision = "rejected", comment = ?, decided_at = NOW()
               WHERE order_id = ? AND level = "pm"', [$comment, $orderId]);
        }
        if ($to === 'rejected_tm') {
            q('UPDATE approvals SET decision = "rejected", comment = ?, decided_at = NOW()
               WHERE order_id = ? AND level = "tm"', [$comment, $orderId]);
        }
        if ($to === 'ordered') {
            $sets[] = 'procurement_user_id = ?'; $params[] = $user['id'];
            q('UPDATE order_items SET item_status = "ordered" WHERE order_id = ? AND item_status IN ("approved","backordered")', [$orderId]);
        }
        if ($to === 'backordered') {
            $sets[] = 'procurement_user_id = ?'; $params[] = $user['id'];
        }
        if ($to === 'in_warehouse') {
            q('UPDATE order_items SET item_status = "received" WHERE order_id = ? AND item_status = "ordered"', [$orderId]);
        }
        if ($to === 'in_transit') {
            $sets[] = 'driver_user_id = ?'; $params[] = $user['id'];
            q('UPDATE order_items SET item_status = "dispatched" WHERE order_id = ? AND item_status = "received"', [$orderId]);
        }
        if ($to === 'delivered') {
            $sets[] = 'actual_delivery_date = CURDATE()';
            q('UPDATE order_items SET item_status = "delivered", actual_delivery_date = CURDATE()
               WHERE order_id = ? AND item_status = "dispatched"', [$orderId]);
            // Close automatically the moment everything is delivered, unless an
            // admin turned this off (settings.autoclose_on_delivery = '0').
            // $params[0] is the status placeholder set at the top; reassign it
            // rather than appending a second, mismatched one.
            if (setting('autoclose_on_delivery', '1') === '1') {
                $params[0] = 'closed';
                $sets[]    = 'closed_at = NOW()';
                $to        = 'closed';          // history + notifications reflect the real end state
            }
        }
        if ($to === 'closed')    $sets[] = 'closed_at = NOW()';
        if ($to === 'cancelled') {
            q('UPDATE order_items SET item_status = "cancelled" WHERE order_id = ?', [$orderId]);
        }
        if ($to === 'draft') {
            $sets[] = 'submitted_at = NULL';
            q('DELETE FROM approvals WHERE order_id = ?', [$orderId]);
        }

        $params[] = $orderId;
        q('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);

        q('INSERT INTO status_history (order_id, from_status, to_status, changed_by, comment)
           VALUES (?, ?, ?, ?, ?)', [$orderId, $from, $to, $user['id'], $comment]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // ---- tell everyone who needs to know, each in their own language ---
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);

    $vars = [
        'ref'     => $order['order_ref'],
        'name'    => $user['full_name'],
        'vessel'  => $order['vessel_name'] ?: '',
        'project' => $order['project_number'],
    ];
    // 'status' and 'vessel' are re-rendered per recipient below.
    $localised = ['status' => 'status.' . $to];
    if (!$order['vessel_name']) $localised['vessel'] = 'order.novessel';

    $bodyKey = $comment ? 'msg.body_note' : 'msg.body';
    if ($comment) $vars['comment'] = $comment;

    notify_many(stakeholders($order), $orderId, 'msg.title', $bodyKey, $vars, $localised);
    notify_many(next_desk($to), $orderId, 'msg.action', $bodyKey, $vars, $localised);

    if (function_exists('sharepoint_push')) {
        try { sharepoint_push($orderId); } catch (Throwable $e) { error_log('SP push: ' . $e->getMessage()); }
    }
}

/* ------------------------------------------------------------ deletion */

/**
 * Permanently delete an order and everything attached to it.
 * Admin-only. The database cascades child rows (items, attachments,
 * approvals, history, messages, notifications); we additionally remove the
 * attachment FILES from disk, which the database cannot do, and write an
 * audit line so a deletion is never silent.
 *
 * Returns the deleted order's reference for the confirmation message.
 */
function delete_order(int $orderId, array $admin): string {
    if (($admin['role_code'] ?? '') !== 'system_admin') {
        throw new RuntimeException(t('err.noaccess'));
    }
    $order = one('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) throw new RuntimeException(t('err.notfound'));

    // Collect attachment files BEFORE the cascade wipes the rows.
    $files = array_column(all('SELECT stored_name FROM attachments WHERE order_id = ?', [$orderId]), 'stored_name');
    $base  = rtrim((string) cfg('app.storage_path'), '/');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM orders WHERE id = ?', [$orderId]);   // cascades to all children
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // A foreign key without ON DELETE CASCADE makes MySQL refuse the
        // delete (error 1451). Say so plainly instead of leaking SQL.
        if (str_contains($e->getMessage(), '1451') || stripos($e->getMessage(), 'foreign key') !== false) {
            throw new RuntimeException(t('del.blocked'));
        }
        throw $e;
    }

    // Files last: only after the row deletion is committed, so a failed
    // delete never leaves an order pointing at missing files.
    $removed = 0;
    foreach ($files as $f) {
        $path = $base . '/' . $f;
        // stay inside the uploads folder no matter what the stored name says
        if ($f !== '' && str_starts_with(realpath(dirname($path)) ?: '', realpath($base) ?: '/none') && is_file($path)) {
            if (@unlink($path)) $removed++;
        }
    }

    access_log('order_deleted', (int)$admin['id'], null,
        sprintf('%s (id %d) by %s; %d attachment file(s) removed', $order['order_ref'], $orderId, $admin['full_name'] ?? '?', $removed));

    return (string) $order['order_ref'];
}

/* ------------------------------------------------------------ order_ref */

function next_order_ref(): string {
    $prefix = setting('order_ref_prefix', 'GAS-PO');
    $year   = date('Y');
    $like   = "{$prefix}-{$year}-%";
    $last   = scalar('SELECT order_ref FROM orders WHERE order_ref LIKE ? ORDER BY id DESC LIMIT 1', [$like]);
    $n      = $last ? ((int) substr((string)$last, -4)) + 1 : 1;
    return sprintf('%s-%s-%04d', $prefix, $year, $n);
}

/* ---------------------------------------------------------- attachments */

const ALLOWED_MIME = [
    // photos — including what phones actually produce
    'image/jpeg' => 'photo', 'image/png'  => 'photo', 'image/webp' => 'photo',
    'image/heic' => 'photo', 'image/heif' => 'photo', 'image/gif'  => 'photo',
    'image/bmp'  => 'photo', 'image/tiff' => 'photo',
    // audio — including detection names finfo really returns
    'audio/mpeg' => 'audio', 'audio/mp4'  => 'audio', 'audio/webm' => 'audio',
    'audio/ogg'  => 'audio', 'audio/wav'  => 'audio', 'audio/x-wav' => 'audio',
    'audio/x-m4a'=> 'audio', 'audio/aac'  => 'audio', 'audio/x-aac' => 'audio',
    'audio/x-hx-aac-adts' => 'audio', 'audio/flac' => 'audio',
    'audio/amr'  => 'audio', 'audio/3gpp' => 'audio', 'audio/3gpp2' => 'audio',
    // Browser voice recorders save audio inside WebM/MP4 *containers*;
    // content sniffing then reports video/*. They play fine in <audio>.
    'video/webm' => 'audio', 'video/mp4'  => 'audio',
    'application/pdf' => 'document',
];

/** Extensions accepted as audio when sniffing gives only a generic type
 *  (very short recordings sometimes detect as application/octet-stream). */
const AUDIO_FALLBACK_EXT = ['webm','m4a','mp3','ogg','oga','opus','wav','aac','amr','3gp','3gpp','flac'];

/**
 * Store one uploaded file against an order (optionally a line item).
 * Returns the attachment id, or null when nothing was uploaded.
 */
function store_upload(array $file, int $orderId, ?int $itemId, int $uploaderId, ?string $hint = null): ?int {
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException(t('err.upload_fail'));

    $max = (int) cfg('app.max_upload_bytes', 16777216);
    if ($file['size'] > $max) {
        throw new RuntimeException(t('err.too_big', ['mb' => round($max / 1048576)]));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']) ?: 'application/octet-stream';
    if (isset(ALLOWED_MIME[$mime])) {
        $kind = ALLOWED_MIME[$mime];
    } else {
        // Fallback for in-browser voice notes whose container sniffs generic.
        $fext = strtolower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($hint === 'audio' && in_array($fext, AUDIO_FALLBACK_EXT, true)) {
            $kind = 'audio';
        } else {
            // Name the detected type so the person knows what was refused.
            throw new RuntimeException(t('err.bad_type') . ' — ' . $mime);
        }
    }

    $dir = rtrim((string) cfg('app.storage_path'), '/') . '/' . date('Y/m');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new RuntimeException(t('err.no_write'));
    }

    $ext    = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext    = preg_replace('/[^A-Za-z0-9]/', '', (string)$ext) ?: 'bin';
    $stored = date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . strtolower($ext);
    $target = rtrim((string) cfg('app.storage_path'), '/') . '/' . $stored;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException(t('err.no_save'));
    }
    @chmod($target, 0644);

    q('INSERT INTO attachments (order_id, item_id, kind, stored_name, original_name, mime_type, size_bytes, uploaded_by)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
      [$orderId, $itemId, $kind, $stored, mb_substr($file['name'], 0, 255), $mime, (int)$file['size'], $uploaderId]);

    return (int) db()->lastInsertId();
}

/** Normalise PHP's nested $_FILES array for `name="x[3][]"` style inputs. */
function files_for(string $field, int $index): array {
    if (empty($_FILES[$field]['name'][$index])) return [];
    $out = [];
    foreach ((array) $_FILES[$field]['name'][$index] as $i => $name) {
        if ($name === '') continue;
        $out[] = [
            'name'     => $name,
            'type'     => $_FILES[$field]['type'][$index][$i],
            'tmp_name' => $_FILES[$field]['tmp_name'][$index][$i],
            'error'    => $_FILES[$field]['error'][$index][$i],
            'size'     => $_FILES[$field]['size'][$index][$i],
        ];
    }
    return $out;
}

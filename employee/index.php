<?php
/**
 * GOLDEN A/S — Parts Order Management System
 * Ref: GAS-APP-POMS-001
 * Front controller. All requests enter here.
 */

declare(strict_types=1);

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/i18n.php';
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/totp.php';
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/mailer.php';
require __DIR__ . '/app/notify.php';
require __DIR__ . '/app/workflow.php';
require __DIR__ . '/app/sharepoint.php';

session_boot();
i18n_boot();

/* Presence heartbeat: stamp last_seen_at at most once a minute for the
 * signed-in person. try/catch keeps pre-migration databases working. */
if (!empty($_SESSION['uid']) && !empty($_SESSION['2fa_ok'])
    && (empty($_SESSION['presence_at']) || time() - (int)$_SESSION['presence_at'] > 60)) {
    try { q('UPDATE users SET last_seen_at = NOW() WHERE id = ?', [(int)$_SESSION['uid']]); }
    catch (Throwable $e) {}
    $_SESSION['presence_at'] = time();
}

header('X-Frame-Options: SAMEORIGIN');
/* Dynamic pages must never be cached: a cached copy re-shows stale flash
 * messages and stale lists (e.g. a user table without the person you just
 * added). File streaming below sets its own short-lived cache instead. */
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$page   = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? 'dashboard'));
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

$public = ['login', 'twofa', 'logout'];
if (!in_array($page, $public, true)) {
    // A half-authenticated session (password ok, code pending) goes to step 2.
    if (!empty($_SESSION['pending_uid']) && empty($_SESSION['2fa_ok'])) redirect('twofa');
    require_login();
}

/* ================================================================ actions */

if ($isPost) {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');

    try {
        switch ($action) {

            /* ---------------------------------------------------- login */
            case 'login':
                $r = attempt_password(trim((string)$_POST['email']), (string)$_POST['password']);
                if (!$r['ok']) { flash('bad', $r['error']); redirect('login'); }

                $_SESSION['pending_uid'] = (int) $r['user']['id'];
                unset($_SESSION['2fa_ok']);
                if (empty($_GET['lang']) && locale_valid($r['user']['locale'] ?? null)) {
                    $_SESSION['locale'] = $r['user']['locale'];
                    locale($r['user']['locale']);
                }

                if (!$r['user']['totp_enabled']) {
                    // First sign-in: enrol the authenticator before going further.
                    $secret = totp_secret();
                    q('UPDATE users SET totp_secret = ? WHERE id = ?', [$secret, $r['user']['id']]);
                    $_SESSION['enrolling'] = true;
                }
                redirect('twofa');
                break;

            case 'twofa':
                $uid = (int)($_SESSION['pending_uid'] ?? 0);
                if (!$uid) redirect('login');

                if (attempt_totp($uid, (string)$_POST['code'])) {
                    if (!empty($_SESSION['enrolling'])) {
                        q('UPDATE users SET totp_enabled = 1 WHERE id = ?', [$uid]);
                        unset($_SESSION['enrolling']);
                    }
                    unset($_SESSION['pending_uid']);
                    finish_login($uid);
                    redirect('dashboard');
                }
                flash('bad', t('twofa.bad'));
                redirect('twofa');
                break;

            case 'logout':
                do_logout();
                redirect('login');
                break;

            /* --------------------------------------------------- orders */
            case 'order_create':
                require_role('field_engineer', 'system_admin');
                [$fresh, $priorId] = form_token_claim('order_create');
                if (!$fresh) {
                    // A duplicate submit (double-click / slow round-trip). The
                    // order was already created on the first submit — send the
                    // user to it instead of creating another.
                    if ($priorId) { redirect_fresh('order', ['id' => $priorId]); }
                    flash('warn', t('flash.already_submitted'));
                    redirect_fresh('orders');
                }
                $id = handle_order_create(current_user());
                form_token_done($id);
                flash('good', t('flash.order_saved'));
                redirect_fresh('order', ['id' => $id]);
                break;

            case 'order_delete': {
                $u  = require_role('system_admin');
                $id = (int)($_POST['order_id'] ?? 0);
                $o  = one('SELECT order_ref FROM orders WHERE id = ?', [$id]);
                if (!$o) { http_response_code(404); exit(t('err.notfound')); }
                // Type-to-confirm: the admin must retype the exact reference.
                if (trim((string)($_POST['confirm_ref'] ?? '')) !== $o['order_ref']) {
                    flash('bad', t('del.mismatch'));
                    redirect_fresh('order', ['id' => $id]);
                }
                $ref = delete_order($id, $u);
                flash('good', t('del.done', ['ref' => $ref]));
                redirect_fresh('orders');
                break;
            }

            case 'order_transition':
                $u  = require_login();
                $id = (int)$_POST['order_id'];
                $o  = one('SELECT * FROM orders WHERE id = ?', [$id]);
                if (!$o || !can_view_order($o, $u)) { http_response_code(403); exit(t('err.notallowed')); }
                transition($id, (string)$_POST['to'], $u, trim((string)($_POST['comment'] ?? '')) ?: null);
                flash('good', t('flash.status_done'));
                redirect_fresh('order', ['id' => $id]);
                break;

            /* -------- part numbers, editable at the approval gates ------ */
            case 'items_partnos': {
                $u  = require_login();
                $id = (int)$_POST['order_id'];
                $o  = one('SELECT * FROM orders WHERE id = ?', [$id]);
                if (!$o) { http_response_code(404); exit(t('err.notfound')); }
                $mayPm   = $o['status'] === 'awaiting_pm' && ((int)$o['pm_user_id'] === (int)$u['id'] || is_admin());
                $mayTm   = $o['status'] === 'awaiting_tm' && ((int)$o['tm_user_id'] === (int)$u['id'] || is_admin());
                $mayProc = in_array($o['status'], ['with_procurement','ordered','backordered'], true)
                           && is_role('procurement', 'system_admin');
                if (!$mayPm && !$mayTm && !$mayProc) { http_response_code(403); exit(t('err.noaccess')); }
                foreach (($_POST['part_number'] ?? []) as $itemId => $pn) {
                    q('UPDATE order_items SET part_number = ? WHERE id = ? AND order_id = ?',
                      [trim((string)$pn) ?: null, (int)$itemId, $id]);
                }
                flash('good', t('pn.saved'));
                redirect_fresh('order', ['id' => $id]);
                break;
            }

            /* -------- per-order Q&A thread ------------------------------- */
            case 'qa_post': {
                $u  = require_login();
                $id = (int)$_POST['order_id'];
                $o  = one('SELECT * FROM orders WHERE id = ?', [$id]);
                if (!$o || !can_view_order($o, $u)) { http_response_code(403); exit(t('err.noaccess')); }
                $body = trim((string)($_POST['body'] ?? ''));
                if ($body !== '') {
                    $body = mb_substr($body, 0, 2000);

                    // Double-submit guard: an identical message from the same
                    // person on the same order within 20 s is the second click
                    // of one Post, not a second message.
                    $dup = scalar('SELECT COUNT(*) FROM order_messages
                                   WHERE order_id = ? AND user_id = ? AND body = ?
                                     AND created_at >= NOW() - INTERVAL 20 SECOND',
                                  [$id, (int)$u['id'], $body]);
                    if ($dup) { redirect_fresh('order', ['id' => $id]); }

                    // Optional threaded reply — must reference a message of
                    // THIS order; anything else is silently dropped.
                    $replyTo = (int)($_POST['reply_to'] ?? 0) ?: null;
                    if ($replyTo !== null) {
                        $ok = scalar('SELECT COUNT(*) FROM order_messages WHERE id = ? AND order_id = ?',
                                     [$replyTo, $id]);
                        if (!$ok) $replyTo = null;
                    }
                    try {
                        q('INSERT INTO order_messages (order_id, user_id, reply_to, body) VALUES (?, ?, ?, ?)',
                          [$id, (int)$u['id'], $replyTo, $body]);
                    } catch (Throwable $e) {          // pre-R4 schema: no reply_to yet
                        q('INSERT INTO order_messages (order_id, user_id, body) VALUES (?, ?, ?)',
                          [$id, (int)$u['id'], $body]);
                    }
                    $others = array_diff(stakeholders($o), [(int)$u['id']]);
                    notify_many(array_values($others), $id, 'msg.qa_title', 'msg.qa_body', [
                        'ref'  => $o['order_ref'],
                        'name' => $u['full_name'],
                        'text' => mb_substr($body, 0, 160),
                    ]);
                }
                redirect_fresh('order', ['id' => $id]);
                break;
            }

            case 'order_dates':
                require_role('procurement', 'warehouse', 'system_admin');
                $id = (int)$_POST['order_id'];
                q('UPDATE orders SET approx_delivery_date = ? WHERE id = ?',
                  [$_POST['approx_delivery_date'] ?: null, $id]);
                foreach (($_POST['item_approx'] ?? []) as $itemId => $d) {
                    q('UPDATE order_items SET approx_delivery_date = ?, supplier = ?, unit_price = ?,
                           part_number = ?
                       WHERE id = ? AND order_id = ?',
                      [$d ?: null,
                       ($_POST['item_supplier'][$itemId] ?? '') ?: null,
                       ($_POST['item_price'][$itemId] ?? '') !== '' ? (float)$_POST['item_price'][$itemId] : null,
                       trim((string)($_POST['item_partno'][$itemId] ?? '')) ?: null,
                       (int)$itemId, $id]);
                }
                if (function_exists('sharepoint_push')) { try { sharepoint_push($id); } catch (Throwable $e) {} }
                flash('good', t('flash.dates_saved'));
                redirect_fresh('order', ['id' => $id]);
                break;

            case 'order_actual_date':
                require_role('driver', 'warehouse', 'system_admin');
                $id = (int)$_POST['order_id'];
                q('UPDATE orders SET actual_delivery_date = ? WHERE id = ?', [$_POST['actual_delivery_date'] ?: null, $id]);
                flash('good', t('flash.actual_saved'));
                redirect_fresh('order', ['id' => $id]);
                break;

            /* -------------------------------------------- notifications */
            case 'notif_read_all':
                q('UPDATE notifications SET is_read = 1 WHERE user_id = ?', [current_user()['id']]);
                redirect('notifications');
                break;

            /* ---------------------------------------------------- admin */
            case 'user_save':
                require_role('system_admin');
                handle_user_save(current_user());
                redirect('admin_users');
                break;

            case 'user_toggle':
                require_role('system_admin');
                $id = (int)$_POST['id'];
                q('UPDATE users SET is_active = 1 - is_active WHERE id = ?', [$id]);
                access_log('user_disabled', (int)current_user()['id'], null, "Toggled user #{$id}");
                flash('good', t('flash.user_updated'));
                redirect('admin_users');
                break;

            case 'user_reset_2fa':
                require_role('system_admin');
                $id = (int)$_POST['id'];
                q('UPDATE users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$id]);
                access_log('role_change', (int)current_user()['id'], null, "Reset 2FA for user #{$id}");
                flash('good', t('flash.twofa_reset'));
                redirect('admin_users');
                break;

            case 'project_save':
                require_role('system_admin');
                q('INSERT INTO projects (project_number, name, vessel_id, pm_user_id, tm_user_id, is_active)
                   VALUES (?, ?, ?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE name = VALUES(name), vessel_id = VALUES(vessel_id),
                     pm_user_id = VALUES(pm_user_id), tm_user_id = VALUES(tm_user_id)',
                  [trim((string)$_POST['project_number']), trim((string)$_POST['name']),
                   $_POST['vessel_id'] ?: null, (int)$_POST['pm_user_id'], (int)$_POST['tm_user_id']]);
                flash('good', t('flash.project_saved'));
                redirect('admin_projects');
                break;

            case 'vessel_import':
                require_role('system_admin');
                $n = handle_vessel_csv();
                access_log('vessel_import', (int)current_user()['id'], null, "{$n} vessels imported");
                flash('good', t('flash.vessels', ['n' => $n]));
                redirect('admin_vessels');
                break;

            case 'settings_save':
                require_role('system_admin');
                foreach (['order_ref_prefix','company_name','default_locale','notify_email_enabled','autoclose_on_delivery','sharepoint_enabled',
                          'sharepoint_site_id','sharepoint_list_id','graph_tenant_id','graph_client_id',
                          'graph_client_secret'] as $k) {
                    if (array_key_exists($k, $_POST)) setting_put($k, (string)$_POST[$k]);
                }
                flash('good', t('flash.settings'));
                redirect('admin_settings');
                break;

            case 'sharepoint_sync_now':
                require_role('system_admin');
                $r = sharepoint_push_open();
                access_log('sharepoint_sync', (int)current_user()['id'], null, "ok={$r['ok']} failed={$r['failed']}");
                flash($r['failed'] ? 'warn' : 'good', t('flash.sync', ['ok' => $r['ok'], 'failed' => $r['failed']]));
                redirect('admin_settings');
                break;

            default:
                http_response_code(400);
                exit('Unknown action.');
        }
    } catch (Throwable $e) {
        flash('bad', $e->getMessage());
        $back   = (string)($_POST['_return'] ?? 'dashboard');
        $extras = ($back === 'order' && !empty($_POST['order_id'])) ? ['id' => (int)$_POST['order_id']] : [];
        redirect($back, $extras);
    }
}

/* ============================================================ presence */

if ($page === 'online') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    try {
        $names = array_column(all('SELECT full_name FROM users
            WHERE is_active = 1 AND last_seen_at >= NOW() - INTERVAL 5 MINUTE
            ORDER BY full_name'), 'full_name');
    } catch (Throwable $e) { $names = []; }
    echo json_encode(['count' => count($names), 'names' => $names], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================================================== attachment streaming */

if ($page === 'file') {
    $u  = require_login();
    $a  = one('SELECT * FROM attachments WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
    if (!$a) { http_response_code(404); exit(t('err.filenotfound')); }
    $o = one('SELECT * FROM orders WHERE id = ?', [$a['order_id']]);
    if (!$o || !can_view_order($o, $u)) { http_response_code(403); exit(t('err.notallowed')); }

    $path = rtrim((string) cfg('app.storage_path'), '/') . '/' . $a['stored_name'];
    if (!is_file($path)) { http_response_code(404); exit(t('err.filemissing')); }

    header('Content-Type: ' . $a['mime_type']);
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: inline; filename="' . rawurlencode($a['original_name']) . '"');
    header('Cache-Control: private, max-age=600');
    readfile($path);
    exit;
}

/* ============================================================ view render */

$views = __DIR__ . '/app/views';
$file  = "{$views}/{$page}.php";
if (!is_file($file)) { http_response_code(404); $file = "{$views}/404.php"; }

require $file;


/* ============================================================== handlers */

function handle_order_create(array $user): int {
    $project = trim((string)$_POST['project_number']);
    $proj    = one('SELECT * FROM projects WHERE project_number = ? AND is_active = 1', [$project]);
    if (!$proj) throw new RuntimeException(t('err.pick_project'));

    $vesselId = $_POST['vessel_id'] ? (int)$_POST['vessel_id'] : null;
    $vessel   = $vesselId ? one('SELECT name FROM vessels WHERE id = ?', [$vesselId]) : null;

    $descs = array_values(array_filter((array)($_POST['description'] ?? []), fn($d) => trim((string)$d) !== ''));
    if (!$descs) throw new RuntimeException(t('err.one_item'));
    $maxItems = (int) cfg('app.max_items', 20);
    if (count($descs) > $maxItems) throw new RuntimeException(t('err.max_items', ['max' => $maxItems]));

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ref = next_order_ref();
        q('INSERT INTO orders (order_ref, project_number, vessel_id, vessel_name, requested_by,
              pm_user_id, tm_user_id, priority, needed_by, delivery_address, notes, status)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft")',
          [$ref, $project, $vesselId, $vessel['name'] ?? null, $user['id'],
           $proj['pm_user_id'], $proj['tm_user_id'],
           (string)($_POST['priority'] ?? 'routine'),
           $_POST['needed_by'] ?: null,
           trim((string)($_POST['delivery_address'] ?? '')) ?: null,
           trim((string)($_POST['notes'] ?? '')) ?: null]);

        $orderId = (int) $pdo->lastInsertId();

        $line = 0;
        foreach ((array)$_POST['description'] as $i => $desc) {
            $desc = trim((string)$desc);
            if ($desc === '') continue;
            if (++$line > $maxItems) break;

            q('INSERT INTO order_items (order_id, line_no, description, part_number, manufacturer, quantity, unit)
               VALUES (?, ?, ?, ?, ?, ?, ?)',
              [$orderId, $line, mb_substr($desc, 0, 500),
               trim((string)($_POST['part_number'][$i] ?? '')) ?: null,
               trim((string)($_POST['manufacturer'][$i] ?? '')) ?: null,
               (float)($_POST['quantity'][$i] ?? 1),
               trim((string)($_POST['unit'][$i] ?? 'pcs')) ?: 'pcs']);

            $itemId = (int) $pdo->lastInsertId();

            foreach (files_for('photos', (int)$i) as $f) store_upload($f, $orderId, $itemId, (int)$user['id'], 'photo');
            foreach (files_for('audio',  (int)$i) as $f) store_upload($f, $orderId, $itemId, (int)$user['id'], 'audio');
        }

        q('INSERT INTO status_history (order_id, from_status, to_status, changed_by, comment)
           VALUES (?, NULL, "draft", ?, "Order raised")', [$orderId, $user['id']]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // Submit straight away unless the engineer chose to keep it as a draft.
    if (($_POST['submit_now'] ?? '') === '1') {
        transition($orderId, 'awaiting_pm', $user, null);
    }
    return $orderId;
}

function handle_user_save(array $actor): void {
    $id    = (int)($_POST['id'] ?? 0);
    $name  = trim((string)$_POST['full_name']);
    $email = trim((string)$_POST['email']);
    $phone = trim((string)($_POST['phone'] ?? '')) ?: null;
    $role  = (int)$_POST['role_id'];
    $pass  = (string)($_POST['password'] ?? '');
    $loc   = locale_valid((string)($_POST['locale'] ?? '')) ? (string)$_POST['locale'] : DEFAULT_LOCALE;

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException(t('err.name_email'));
    }

    if ($id) {
        q('UPDATE users SET full_name = ?, email = ?, phone = ?, role_id = ?, locale = ? WHERE id = ?',
          [$name, $email, $phone, $role, $loc, $id]);
        if ($pass !== '') {
            if (strlen($pass) < 10) throw new RuntimeException(t('err.pass_len'));
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            access_log('password_change', (int)$actor['id'], $email, "Reset for user #{$id}");
        }
        access_log('role_change', (int)$actor['id'], $email, "Updated user #{$id}");
        flash('good', t('flash.user_updated'));
    } else {
        if (strlen($pass) < 10) throw new RuntimeException(t('err.pass_len'));
        q('INSERT INTO users (full_name, email, phone, password_hash, role_id, locale) VALUES (?, ?, ?, ?, ?, ?)',
          [$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $role, $loc]);
        access_log('user_created', (int)$actor['id'], $email, 'New account');
        flash('good', t('flash.user_created'));
    }
}

function handle_vessel_csv(): int {
    if (empty($_FILES['csv']['tmp_name'])) throw new RuntimeException(t('err.csv_choose'));
    $fh = fopen($_FILES['csv']['tmp_name'], 'r');
    if (!$fh) throw new RuntimeException(t('err.csv_read'));

    // Read the first line by hand: strip Excel's UTF-8 BOM, and sniff the
    // delimiter — Greek-locale Excel saves CSV with semicolons, not commas.
    $first = fgets($fh);
    if ($first === false || trim($first) === '') { fclose($fh); throw new RuntimeException(t('err.csv_empty')); }
    if (str_starts_with($first, "\xEF\xBB\xBF")) $first = substr($first, 3);
    $delim  = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    $header = str_getcsv(rtrim($first, "\r\n"), $delim);
    if (!$header) throw new RuntimeException(t('err.csv_empty'));
    $cols = array_map(fn($h) => strtolower(trim((string)$h)), $header);
    $idx  = fn(string $k) => array_search($k, $cols, true);

    if ($idx('name') === false) {
        fclose($fh);
        throw new RuntimeException(t('err.csv_name'));
    }

    $n = 0;
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        $name = trim((string)($row[$idx('name')] ?? ''));
        if ($name === '') continue;
        q('INSERT INTO vessels (name, imo, hull_no, yard) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE imo = VALUES(imo), hull_no = VALUES(hull_no), yard = VALUES(yard), is_active = 1',
          [$name,
           $idx('imo')     !== false ? (trim((string)($row[$idx('imo')]     ?? '')) ?: null) : null,
           $idx('hull_no') !== false ? (trim((string)($row[$idx('hull_no')] ?? '')) ?: null) : null,
           $idx('yard')    !== false ? (trim((string)($row[$idx('yard')]    ?? '')) ?: null) : null]);
        $n++;
    }
    fclose($fh);
    return $n;
}

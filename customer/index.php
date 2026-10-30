<?php
/** GOLDEN Customer Portal — front controller. GAS-APP-CP-001 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

require __DIR__ . '/app/db.php';
require __DIR__ . '/app/i18n.php';
require __DIR__ . '/app/helpers.php';
require __DIR__ . '/app/auth.php';
require __DIR__ . '/app/totp.php';
require __DIR__ . '/app/pm.php';
require __DIR__ . '/app/pm_actions.php';
require __DIR__ . '/app/fsr.php';
require __DIR__ . '/app/isr.php';
require __DIR__ . '/app/br.php';
require __DIR__ . '/app/tm.php';
require __DIR__ . '/app/pl.php';
require __DIR__ . '/app/eq.php';
require __DIR__ . '/app/fd.php';
require __DIR__ . '/app/iss.php';
require __DIR__ . '/app/rc.php';
require __DIR__ . '/app/flt.php';
require __DIR__ . '/app/vm.php';
require __DIR__ . '/app/rs.php';
require __DIR__ . '/app/wf.php';
require __DIR__ . '/app/mn.php';
require __DIR__ . '/app/rt.php';
require __DIR__ . '/app/acc.php';
require __DIR__ . '/app/kpi.php';
require __DIR__ . '/app/sales.php';
require __DIR__ . '/app/pd.php';
require __DIR__ . '/app/num.php';
require __DIR__ . '/app/pi.php';
require __DIR__ . '/app/cf.php';
require __DIR__ . '/app/eng.php';   // R47-001
require __DIR__ . '/app/ai.php';    // R50-001
require __DIR__ . '/app/hr.php';    // R51-001
require __DIR__ . '/app/hr2.php';   // R54-001
require __DIR__ . '/app/hr3.php';   // R56-001
require __DIR__ . '/app/hr4.php';   // R55-001
require __DIR__ . '/app/hr5.php';   // R61-001
require __DIR__ . '/app/hr6.php';   // R62-001
require __DIR__ . '/app/mt.php';
require __DIR__ . '/app/bg.php';
require __DIR__ . '/app/wp.php';
require __DIR__ . '/app/rl.php';
require __DIR__ . '/app/ob.php';
require __DIR__ . '/app/et.php';
require __DIR__ . '/app/em.php';
require __DIR__ . '/app/jo.php';
require __DIR__ . '/app/oa.php';
require __DIR__ . '/app/ts.php';
require __DIR__ . '/app/version.php';
require __DIR__ . '/app/tk.php';
require __DIR__ . '/app/vh.php';
require __DIR__ . '/app/of.php';
require __DIR__ . '/app/wk.php';
require __DIR__ . '/app/avatar.php';
require __DIR__ . '/app/theme.php';
require __DIR__ . '/app/ct.php';
require __DIR__ . '/app/export.php';
require __DIR__ . '/app/nf.php';
require __DIR__ . '/app/iv.php';
require __DIR__ . '/app/ar.php';
require __DIR__ . '/app/rp.php';
require __DIR__ . '/app/kb.php';
require __DIR__ . '/app/cr.php';                                                                                   // R59-001
require __DIR__ . '/app/lic.php';
date_default_timezone_set(tz_app());                                                     // R46-002: "today" and every date() in the company's zone
require __DIR__ . '/app/td.php';
require __DIR__ . '/app/fk.php';
require __DIR__ . '/app/wc.php';

session_boot();
i18n_boot();

header('X-Frame-Options: SAMEORIGIN');
/* Dynamic pages must never be cached: a cached copy re-shows stale flash
 * messages and stale lists (e.g. a user table without the person you just
 * added). File streaming below sets its own short-lived cache instead. */
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
/* R53-001: Content-Security-Policy (inline scripts carry the per-request nonce; styles stay inline-capable) and Permissions-Policy.
 * Mode from Settings › Login history: on (default) | report (Content-Security-Policy-Report-Only, violations in storage/csp.log) | off. */
$cspMode = function_exists('sec_csp_mode') ? sec_csp_mode() : 'on';
if ($cspMode !== 'off') {
    $csp = "default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self' https://api.open-meteo.com; frame-src 'self'; frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'" . ($cspMode === 'report' ? '; report-uri index.php?p=csp_report' : '');
    header(($cspMode === 'report' ? 'Content-Security-Policy-Report-Only: ' : 'Content-Security-Policy: ') . $csp);
}
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()');

$page = preg_replace('/[^a-z0-9_]/', '', (string)($_GET['p'] ?? 'dashboard'));

/* ------------------------------------------------------------- POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'csp_report') { $raw = (string) file_get_contents('php://input'); if (strlen($raw) < 8192) @file_put_contents(__DIR__ . '/storage/csp.log', date('c') . ' ' . preg_replace('/\s+/', ' ', $raw) . "\n", FILE_APPEND | LOCK_EX); http_response_code(204); exit; }   // R53-001
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page === 'ai') { require_login(); ai_endpoint(); }   // R50-001: assistant endpoint (JSON; CSRF checked by header inside)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string)($_POST['action'] ?? '');
    try {
        if (function_exists('hr_only') && hr_only() && !in_array($action, HR_ACTIONS, true)) throw new RuntimeException(t('err.noaccess'));   // R51-001: HR scope
        if (function_exists('emp_only') && emp_only() && !in_array($action, EMP_ACTIONS, true)) throw new RuntimeException(t('err.noaccess'));   // R54-001: employee scope
        if (lic_locked() && !in_array($action, ['login', 'twofa', 'logout', 'lic_install', 'lic_remove', 'theme_save', 'account_save', 'avatar_save', 'twofa_begin', 'twofa_confirm', 'twofa_off'], true)) { flash('bad', t('lic.locked_title')); redirect('license'); }   // R22-001
        if (pm_handle_action($action, $page)) exit;     // PM-tool actions (always redirect)
        switch ($action) {
            case 'login':
                $r = attempt_login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''));
                if ($r['ok']) redirect(!empty($r['twofa']) ? 'twofa' : 'dashboard');
                flash('bad', $r['error']);
                redirect('login');

            case 'twofa':
                $r = attempt_twofa((string)($_POST['code'] ?? ''));
                if ($r['ok']) redirect('dashboard');
                flash('bad', $r['error']);
                redirect($r['restart'] ? 'login' : 'twofa');

            /* ---- account page: authenticator self-service ---- */
            case 'twofa_begin':
                require_login();
                $_SESSION['acct_enrol_secret'] = totp_secret();
                redirect('account', ['setup' => 1, '_r' => uniqid()]);
            case 'twofa_confirm': {
                $au = require_login();
                $secret = (string)($_SESSION['acct_enrol_secret'] ?? '');
                if ($secret === '' || !totp_verify($secret, (string)($_POST['code'] ?? ''))) { flash('bad', t('twofa.bad')); redirect('account', ['setup' => 1, '_r' => uniqid()]); }
                q('UPDATE customer_users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?', [$secret, $au['id']]);
                unset($_SESSION['acct_enrol_secret']);
                login_log((int)$au['id'], (string)$au['email'], 'twofa_enrolled');
                flash('good', t('a.twofa_done'));
                redirect('account', ['_r' => uniqid()]);
            }
            case 'twofa_off': {
                $au = require_login();
                if (!empty($au['twofa_required'])) { flash('bad', t('a.twofa_required_note')); redirect('account'); }
                if (!password_verify((string)($_POST['password'] ?? ''), (string)$au['password_hash'])) { flash('bad', t('a.twofa_badpass')); redirect('account'); }
                q('UPDATE customer_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$au['id']]);
                flash('good', t('a.twofa_disabled'));
                redirect('account', ['_r' => uniqid()]);
            }

            case 'logout':
                $lu = require_login();
                login_log((int)$lu['id'], (string)$lu['email'], 'logout');
                presence_leave((int)$lu['id']);
                session_unset(); session_destroy();
                header('Location: index.php?p=login'); exit;

            case 'wc_hours_save': { wc_hours_save((string)($_POST['cal'] ?? '')); flash('good', t('wc.saved')); redirect('admin_calendars', ['cal' => $_POST['cal'] ?? '', 'year' => (int)($_GET['year'] ?? date('Y')), '_r' => uniqid()]); }
            case 'wc_cal_save': { wc_calendar_save(); flash('good', t('wc.saved')); redirect('admin_calendars', ['cal' => strtoupper((string)($_POST['code'] ?? '')), '_r' => uniqid()]); }
            case 'wc_generate': { $n = wc_generate((string)($_POST['cal'] ?? ''), (int)($_POST['year'] ?? date('Y'))); flash('good', t('wc.generated', ['n' => $n])); redirect('admin_calendars', ['cal' => $_POST['cal'] ?? '', 'year' => (int)($_POST['year'] ?? date('Y')), '_r' => uniqid()]); }
            case 'wc_holiday_add': { wc_holiday_add((string)($_POST['cal'] ?? ''), (string)($_POST['date'] ?? ''), (string)($_POST['name'] ?? '')); flash('good', t('wc.saved')); redirect('admin_calendars', ['cal' => $_POST['cal'] ?? '', 'year' => (int) substr((string)($_POST['date'] ?? ''), 0, 4), '_r' => uniqid()]); }
            case 'wc_holiday_delete': { wc_holiday_delete((int)($_POST['id'] ?? 0)); flash('good', t('wc.deleted')); redirect('admin_calendars', ['cal' => $_GET['cal'] ?? '', 'year' => (int)($_GET['year'] ?? date('Y')), '_r' => uniqid()]); }
            case 'wc_import': {
                require_cp_admin(); require_once __DIR__ . '/app/xlsx.php';
                if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
                $res = wc_import($_FILES['xlsx']['tmp_name'], !empty($_POST['dry'])); $res['kind'] = 'holidays'; $res['dry'] = !empty($_POST['dry']); $_SESSION['cp_import_result'] = $res;
                redirect('admin_calendars', ['cal' => $_GET['cal'] ?? '', 'year' => (int)($_GET['year'] ?? date('Y')), '_r' => uniqid()]);
            }
            case 'fkc_save': { fk_save(); flash('good', t('fkc.saved')); redirect('admin_file_kinds', ['_r' => uniqid()]); }
            case 'fkc_delete': { fk_delete((string)($_POST['skey'] ?? '')); flash('good', t('fkc.deleted')); redirect('admin_file_kinds', ['_r' => uniqid()]); }
            case 'tdk_save': { td_kind_save(); flash('good', t('tdk.saved')); redirect('admin_task_kinds', ['_r' => uniqid()]); }
            case 'wf_deck_save': { $vid = (int)$_POST['vessel_id']; $did = wf_deck_save($vid); flash('good', t('wf.saved')); redirect('vessel_decks', ['id' => $vid, 'deck' => $did, '_r' => uniqid()]); }
            case 'wf_deck_delete': { $vid = (int)$_POST['vessel_id']; wf_deck_delete((int)$_POST['id'], $vid); flash('good', t('wf.deleted')); redirect('vessel_decks', ['id' => $vid, '_r' => uniqid()]); }
            case 'wf_area_save': { $vid = (int)$_POST['vessel_id']; wf_area_save($vid); flash('good', t('wf.saved')); redirect('vessel_decks', ['id' => $vid, 'deck' => (int)($_POST['deck_id'] ?? 0) ?: (int)($_GET['deck'] ?? 0), '_r' => uniqid()], '#wf-areas'); }
            case 'wf_area_delete': { $vid = (int)$_POST['vessel_id']; wf_area_delete((int)$_POST['id'], $vid); flash('good', t('wf.deleted')); redirect('vessel_decks', ['id' => $vid, 'deck' => (int)($_GET['deck'] ?? 0), '_r' => uniqid()], '#wf-areas'); }
            case 'wf_save': { $wid = (int)$_POST['id']; wf_save_from_post($wid); flash('good', t('wf.draft_saved')); redirect('wf', ['id' => $wid, '_r' => uniqid()]); }
            case 'wf_issue': { $wid = (int)$_POST['id']; $r = wf_issue($wid); flash('good', t('wf.issued_ok', ['r' => $r['revision']])); redirect('wf', ['id' => $wid, '_r' => uniqid()]); }
            case 'wf_reopen': { $wid = (int)$_POST['id']; wf_reopen($wid); flash('good', t('wf.reopened')); redirect('wf', ['id' => $wid, '_r' => uniqid()]); }
            case 'wf_delete': { $wid = (int)$_POST['id']; $wfp = wf_get($wid); wf_delete($wid); flash('good', t('wf.deleted')); redirect('projects', ['project' => (int)($wfp['project_id'] ?? 0), '_r' => uniqid()]); }
            case 'wf_comment_add': { $wid = (int)$_POST['id']; wf_comment_add($wid); flash('good', t('wf.reply_sent')); redirect('wf', ['id' => $wid, '_r' => uniqid()], '#wf-replies'); }
            case 'wf_comment_delete': { $wid = wf_comment_delete((int)$_POST['id']); flash('good', t('wf.deleted')); redirect('wf', ['id' => $wid, '_r' => uniqid()], '#wf-replies'); }
            case 'rs_save': { $rid = rs_save_from_post(); flash('good', t('rs.saved')); redirect('rs', ['id' => $rid, '_r' => uniqid()]); }
            case 'rs_delete': { rs_delete((int)$_POST['id']); flash('good', t('rs.deleted')); redirect('cyber', ['_r' => uniqid()]); }
            case 'rs_session_add': case 'rs_session_delete': {
                if ($action === 'rs_session_add') rs_session_add((int)$_POST['contract_id'], (int)($_POST['ticket_id'] ?? 0) ?: null, (string)($_POST['date'] ?? ''), (float) str_replace(',', '.', (string)($_POST['hours'] ?? '0')), trim((string)($_POST['note'] ?? '')) ?: null);
                else rs_session_delete((int)$_POST['id']);
                flash('good', t('rs.session_saved')); [$bp, $bid] = explode(':', (string)($_POST['back'] ?? 'cyber:0')) + [1 => 0];
                redirect($bp === 'ticket' ? 'ticket' : ($bp === 'rs' ? 'rs' : 'cyber'), $bid ? ['id' => (int)$bid, '_r' => uniqid()] : ['_r' => uniqid()], $bp === 'ticket' ? '#rs-card' : '');
            }
            case 'vm_merge': {                                                                       // R30-001
                require_cp_admin();
                $mid = (int)($_POST['master_id'] ?? 0); $dups = array_map('intval', (array)($_POST['dup_ids'] ?? []));
                $r = vm_merge($mid, $dups);
                $rn = trim((string)($_POST['rename'] ?? '')); if ($rn !== '' && $rn !== (string) scalar('SELECT name FROM vessels WHERE id = ?', [$mid])) { vm_alias_add($mid, (string) scalar('SELECT name FROM vessels WHERE id = ?', [$mid]), 'rename'); q('UPDATE vessels SET name = ? WHERE id = ?', [mb_substr(vm_canonical($rn), 0, 120), $mid]); }
                flash('good', t('vm.merged', ['n' => count(array_filter($dups, fn($d) => $d && $d !== $mid)), 'm' => (string) scalar('SELECT name FROM vessels WHERE id = ?', [$mid]), 'x' => array_sum($r['moved'])]));
                redirect('admin_vessel_merge', ['_r' => uniqid()]);
            }
            case 'vm_map_import': {
                require_cp_admin();
                if (empty($_FILES['file']['tmp_name'])) throw new RuntimeException(t('im.file'));
                $_SESSION['vm_map_result'] = vm_merge_map($_FILES['file']['tmp_name'], !empty($_POST['dry']));
                if (empty($_POST['dry'])) flash('good', t('vm.map_done'));
                redirect('admin_vessel_merge', ['_r' => uniqid()]);
            }
            case 'vm_related_set': { require_staff(); vm_related_set((int)$_POST['vessel_id'], (int)($_POST['customer_id'] ?? 0), (string)($_POST['role'] ?? 'other'), trim((string)($_POST['note'] ?? '')) ?: null); flash('good', t('vm.related_saved')); redirect('vessel', ['id' => (int)$_POST['vessel_id'], '_r' => uniqid()], '#vm-related'); }
            case 'vm_related_remove': { require_staff(); vm_related_remove((int)$_POST['id']); flash('good', t('vm.related_removed')); redirect('vessel', ['id' => (int)$_POST['vessel_id'], '_r' => uniqid()], '#vm-related'); }
            case 'isc_save': { iss_cat_save(); flash('good', t('isc.saved')); redirect('admin_issue_cats', ['_r' => uniqid()]); }
            case 'isc_delete': { iss_cat_delete((string)($_POST['skey'] ?? '')); flash('good', t('isc.deleted')); redirect('admin_issue_cats', ['_r' => uniqid()]); }
            case 'tdk_delete': { td_kind_delete((string)($_POST['skey'] ?? '')); flash('good', t('tdk.deleted')); redirect('admin_task_kinds', ['_r' => uniqid()]); }
            case 'lic_install': { $L = lic_install((string)($_POST['key'] ?? '')); flash('good', t('lic.installed_ok', ['s' => $L['serial'], 'd' => fmt_date($L['contract_end'])])); redirect('license', ['_r' => uniqid()]); }
            case 'lic_remove': { lic_remove(); flash('good', t('lic.removed')); redirect('license', ['_r' => uniqid()]); }
            case 'td_save': { $tid = td_save_from_post(); flash('good', t('td.saved')); redirect('tasks', ['view' => 'mine', 'hl' => $tid, '_r' => uniqid()]); }
            case 'td_done': case 'td_skip': case 'td_reopen': case 'td_delete': {
                $tid = (int)($_POST['id'] ?? 0);
                if ($action === 'td_delete') { td_delete($tid); flash('good', t('td.deleted')); redirect('tasks', ['_r' => uniqid()]); }
                if ($action === 'td_reopen') td_reopen($tid); else td_done($tid, $action === 'td_skip');
                $keep = []; foreach (['view', 'project', 'sub', 'kind', 'who', 'q'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
                redirect('tasks', $keep + ['_r' => uniqid()]);
            }
            case 'td_seed': { require_cp_admin(); $n = td_seed_reports(array_map('strval', (array)($_POST['which'] ?? [])), (int)($_POST['assignee_id'] ?? 0) ?: null); flash('good', t('td.seeded', ['n' => $n])); redirect('tasks', ['view' => 'planned', '_r' => uniqid()]); }
            case 'kb_ask': { $qid = kb_ask(); flash('good', t('kb.posted_q')); redirect('kb_q', ['id' => $qid, '_r' => uniqid()]); }
            case 'kb_answer': { $qid = (int)($_POST['id'] ?? 0); kb_answer($qid); flash('good', t('kb.posted_a')); redirect('kb_q', ['id' => $qid, '_r' => uniqid()]); }
            case 'kb_accept': { $qid = (int)($_POST['id'] ?? 0); kb_accept($qid, (int)($_POST['answer_id'] ?? 0)); redirect('kb_q', ['id' => $qid, '_r' => uniqid()]); }
            case 'kb_vote': { $aid = (int)($_POST['id'] ?? 0); kb_vote($aid); $qid = (int) scalar('SELECT question_id FROM kb_answers WHERE id = ?', [$aid]); redirect('kb_q', ['id' => $qid, '_r' => uniqid()]); }
            case 'kb_delete_q': { kb_delete_question((int)($_POST['id'] ?? 0)); flash('good', t('kb.deleted_q')); redirect('kb', ['_r' => uniqid()]); }
            case 'cr_create': { $rid = cr_create(); flash('good', t('cr.posted')); redirect('request', ['id' => $rid, '_r' => uniqid()]); }
            case 'cr_comment': { $rid = (int)($_POST['id'] ?? 0); cr_comment($rid); flash('good', t('cr.commented')); redirect('request', ['id' => $rid, '_r' => uniqid()]); }
            case 'cr_vote': { $rid = (int)($_POST['id'] ?? 0); cr_vote($rid); redirect('request', ['id' => $rid, '_r' => uniqid()]); }
            case 'cr_decide': { $rid = (int)($_POST['id'] ?? 0); cr_decide($rid); flash('good', t('cr.saved')); redirect('request', ['id' => $rid, '_r' => uniqid()]); }
            case 'cr_delete': { cr_delete((int)($_POST['id'] ?? 0)); flash('good', t('cr.deleted')); redirect('requests', ['_r' => uniqid()]); }
            case 'cr_dev': { require_cp_admin(); cp_setting_set('cr_dev_user_id', (string)(int)($_POST['user_id'] ?? 0)); flash('good', t('cr.saved')); redirect('admin_users', ['_r' => uniqid()]); }
            case 'kb_delete_a': { $qid = kb_delete_answer((int)($_POST['id'] ?? 0)); flash('good', t('kb.deleted_a')); redirect('kb_q', ['id' => $qid, '_r' => uniqid()]); }
            case 'support_status': {                                  // staff: close / re-open a customer's request
                require_staff();
                q('UPDATE support_tickets SET status = ? WHERE id = ?', [($_POST['status'] ?? '') === 'open' ? 'open' : 'closed', (int)($_POST['id'] ?? 0)]);
                redirect('support', ['_r' => uniqid()]);
            }
            case 'support_send':
                $u = require_login();
                if (is_staff() || empty($u['customer_id'])) { flash('bad', t('su.staff_only_customers')); redirect('support'); }
                $subject = trim((string)($_POST['subject'] ?? ''));
                $body    = trim((string)($_POST['body'] ?? ''));
                $vid     = (int)($_POST['vessel_id'] ?? 0) ?: null;
                if ($subject !== '' && $body !== '') {
                    if ($vid) { // vessel must belong to this customer
                        $ok = scalar('SELECT COUNT(*) FROM vessels WHERE id=? AND customer_id=?', [$vid, $u['customer_id']]);
                        if (!$ok) $vid = null;
                    }
                    q('INSERT INTO support_tickets (customer_id, customer_user_id, vessel_id, subject, body)
                       VALUES (?,?,?,?,?)', [$u['customer_id'], $u['id'], $vid, mb_substr($subject,0,200), mb_substr($body,0,4000)]);
                    flash('good', t('su.sent', ['email' => $u['email']]));
                }
                redirect('support');

            case 'nf_read': {                                       // R18-002 notifications
                require_login();
                nf_read([(int)($_POST['id'] ?? 0)]);
                $keep = [];
                foreach (['g', 'show', 'page'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
                redirect($page ?: 'notifications', $keep + ['_r' => uniqid()]);
            }
            case 'nf_read_all': {
                require_login();
                $g = preg_replace('/[^a-z]/', '', (string)($_POST['grp'] ?? ''));
                nf_read_all($g ?: null);
                $keep = [];
                foreach (['id', 'project', 'vessel', 'tab', 'week', 'view', 'g', 'show', 'page'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
                redirect($page ?: 'dashboard', $keep + ['_r' => uniqid()]);
            }
            case 'theme_save': {
                require_login();
                theme_set((string)($_POST['theme'] ?? 'light'));
                $back = preg_replace('/[^a-z0-9_]/', '', (string)($_POST['back'] ?? $page));
                $keep = [];
                foreach (['id', 'project', 'vessel', 'tab', 'week', 'view'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
                redirect($back ?: 'dashboard', $keep + ['_r' => uniqid()]);
            }
            case 'avatar_save': {
                $au = require_login();
                if (!empty($_POST['remove'])) { avatar_remove((int)$au['id']); flash('good', t('av.removed')); }
                else { avatar_store((int)$au['id'], $_FILES['avatar'] ?? []); flash('good', t('av.saved')); }
                redirect('account', ['_r' => uniqid()]);
            }
            case 'account_revoke': {   // R49-001: sign out of every other browser (this one gets a fresh session)
                $u = require_login(); sec_revoke_all((int)$u['id']); login_log((int)$u['id'], (string)$u['email'], 'revoked');
                session_unset(); session_destroy(); header('Location: index.php?p=login'); exit;
            }
            case 'account_save':
                $u = require_login();
                $loc  = locale_valid((string)($_POST['locale'] ?? '')) ? (string)$_POST['locale'] : locale();
                $pass = (string)($_POST['password'] ?? '');
                if ($pass !== '' && strlen($pass) < 10) { flash('bad', t('a.pass_len')); redirect('account'); }
                q('UPDATE customer_users SET locale = ? WHERE id = ?', [$loc, $u['id']]);
                if (isset($_POST['theme'])) theme_set((string)$_POST['theme']);
                if ($pass !== '') q('UPDATE customer_users SET password_hash = ? WHERE id = ?',
                                    [password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
                $_SESSION['locale'] = $loc; locale($loc);
                flash('good', t('a.saved'));
                redirect('account');
        }
    } catch (Throwable $ex) {
        if (getenv('CP_DEBUG_TRACE')) @file_put_contents(__DIR__ . '/storage/gate_debug.log', date('c') . ' ' . $ex->getMessage() . ' @ ' . $ex->getFile() . ':' . $ex->getLine() . "\n" . $ex->getTraceAsString() . "\n", FILE_APPEND);
        flash('bad', $ex->getMessage() . (!($ex instanceof RuntimeException) && function_exists('is_cp_admin') && is_cp_admin() ? ' — ' . basename($ex->getFile()) . ':' . $ex->getLine() : ''));   // administrators see where a programming error happened
        $keep = [];                                   // stay on the same record (edit forms, tabs)
        foreach (['id', 'project', 'tab', 'd', 'q', 'access', 'customer', 'grole', 'disc', 'status', 'twofa', 'locale', 'login', 'page', 'vessel', 'view', 'week', 'edit', 'iv', 'kind'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
        $keep['_r'] = uniqid();
        redirect($page ?: 'dashboard', $keep);
    }
}

/* -------------------------------------------------------------- GET */
if ($page === 'relock') sec_relock();                                     // R49-001: browser closed and reopened → drop the session (204)
if ($page === 'ping') presence_ping();                                   // presence heartbeat (R24-002): 204 / 401, never a page
$public = ['login', 'twofa'];
if (!in_array($page, $public, true) && !current_user()) $page = 'login';
if ($page === 'twofa' && !pending_twofa_user()) $page = 'login';
if (in_array($page, $public, true) && current_user()) $page = 'dashboard';
if (current_user() && lic_locked() && !in_array($page, LIC_OPEN_PAGES, true)) $page = 'license';     // R22-001: no licence, no portal

if ($page === 'file') { require_login(); cp_stream_file((int)($_GET['id'] ?? 0)); }
if ($page === 'brlogo') { require_cp_admin(); br_logo_stream((int)($_GET['id'] ?? 0)); }
if ($page === 'tfile') { require_login(); tk_stream_file((int)($_GET['id'] ?? 0)); }
if ($page === 'avatar') { require_login(); avatar_stream((int)($_GET['id'] ?? 0)); }
if ($page === 'contacts_export') { ex_contacts(ct_filters_from_request($_GET)); }
if ($page === 'vessels_export') { ex_vessels($_GET); }
if ($page === 'projects_export') { ex_projects($_GET); }
if ($page === 'companies_export') { ex_companies($_GET); }
if ($page === 'users_export') { ex_users($_GET); }
if ($page === 'equipment_export') { eq_export($_GET); }
if ($page === 'fd_import' && !empty($_GET['go']) && !empty($_GET['run'])) { try { fd_import_batch((string)$_GET['run'], !empty($_GET['cv'])); } catch (Throwable $e) { flash('bad', $e->getMessage() . ' — ' . basename($e->getFile()) . ':' . $e->getLine() . ' (fd ' . FD_BUILD . ')'); $_SESSION['fd_jobs'][(string)$_GET['run']]['done'] = true; } }
if ($page === 'issue' && !empty($_GET['no'])) { require_login(); $ii = iss_has() ? one('SELECT id, subproject_id, project_id FROM sp_issues WHERE issue_no = ? AND ' . iss_vis_sql('sp_issues'), [strtoupper(trim((string)$_GET['no']))]) : null; if ($ii) redirect($ii['subproject_id'] ? 'subproject' : 'project', ($ii['subproject_id'] ? ['id' => (int)$ii['subproject_id'], 'tab' => 'issues', 'ist' => ''] : ['id' => (int)$ii['project_id']]), '#iss-' . (int)$ii['id']); flash('bad', t('err.notfound')); redirect('dashboard'); }
if ($page === 'issues_export') { iss_export((int)($_GET['project'] ?? 0), (int)($_GET['sub'] ?? 0) ?: null); }
if ($page === 'eq_contact_search') { eq_contact_search(); }
if ($page === 'deckimg') { wf_deck_stream((int)($_GET['id'] ?? 0)); }
if ($page === 'cost_report') { require_login(); if (!function_exists('bg_can') || !bg_can()) { http_response_code(403); exit(t('err.noaccess')); } $bytes = bg_render_pdf((int)($_GET['project'] ?? 0)); header('Content-Type: application/pdf'); header('Content-Length: ' . strlen($bytes)); header('Content-Disposition: inline; filename="cost-control-' . (int)($_GET['project'] ?? 0) . '.pdf"'); echo $bytes; exit; }
if ($page === 'offer_doc') { require_login(); if (!function_exists('ob_can') || !ob_can()) { http_response_code(403); exit(t('err.noaccess')); } $od = of_load((int)($_GET['id'] ?? 0)); if (!$od) { http_response_code(404); exit('Not found'); } $fmt = ($_GET['fmt'] ?? 'pdf') === 'docx' ? 'docx' : 'pdf'; $bytes = $fmt === 'pdf' ? ob_render_pdf($od) : ob_render_docx($od); header('Content-Type: ' . ($fmt === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')); header('Content-Length: ' . strlen($bytes)); header('Content-Disposition: ' . ($fmt === 'pdf' ? 'inline' : 'attachment') . '; filename="' . rawurlencode($od['offer_no'] . '_' . $od['revision'] . ($od['status'] === 'draft' ? '_draft' : '') . '.' . $fmt) . '"'); echo $bytes; exit; }
if ($page === 'wf_doc') { require_login(); $wfd = wf_get((int)($_GET['id'] ?? 0)); if (!$wfd) { http_response_code(404); exit('Not found'); } $fmt = ($_GET['fmt'] ?? 'pdf') === 'docx' ? 'docx' : 'pdf'; $bytes = $fmt === 'pdf' ? wf_render_pdf($wfd) : wf_render_docx($wfd); header('Content-Type: ' . ($fmt === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')); header('Content-Disposition: ' . ($fmt === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $wfd['doc_no'] . '_preview.' . $fmt . '"'); header('Content-Length: ' . strlen($bytes)); echo $bytes; exit; }
if ($page === 'proforma_pdf') { require_staff(); pi_pdf_download((int)($_GET['id'] ?? 0)); }
if ($page === 'production_sub_pdf') { ps_pdf_download(['status' => (string)($_GET['status'] ?? 'open'), 'supplier' => (string)($_GET['supplier'] ?? ''), 'pm' => (int)($_GET['pm'] ?? 0), 'vessel' => (int)($_GET['vessel'] ?? 0), 'q' => trim((string)($_GET['q'] ?? ''))]); }
if ($page === 'production_pdf') { require_staff(); pd_pdf_download((int)($_GET['id'] ?? 0)); }
if ($page === 'equipment_doc') { eq_doc_download((int)($_GET['id'] ?? 0), (string)($_GET['fmt'] ?? 'pdf')); }
if ($page === 'planning_export') { pl_export((int)($_GET['year'] ?? date('Y'))); }
if ($page === 'hr_export') { require_staff(); hr_export((int)($_GET['year'] ?? date('Y'))); }   // R51-001
if ($page === 'plan_items') {                                                                   // HTML fragment for the planning editor (R26-001)
    require_staff(); $items = pl_open_items((int)($_GET['project'] ?? 0) ?: null, (int)($_GET['sub'] ?? 0) ?: null);
    header('Content-Type: text/html; charset=utf-8'); header('Cache-Control: no-store');
    if (!$items) { echo '<p class="hint">' . tt('pl.no_open_items') . '</p>'; exit; }
    echo '<ul class="pl-items">'; foreach ($items as $it) echo '<li>' . ($it['kind'] === 'isr' ? '🔍' : '📋') . ' <a href="' . $it['url'] . '">' . e($it['ref']) . '</a> <span class="hint">' . e(fmt_date($it['date'])) . '</span> — ' . e(mb_substr($it['text'], 0, 220)) . (!empty($it['risk']) && $it['risk'] !== 'none' ? ' ' . isr_risk_pill($it['risk']) : '') . '</li>'; echo '</ul>'; exit;
}
if ($page === 'customers_export') { ex_customers($_GET); }
if ($page === 'kbfile') { kb_stream_file((int)($_GET['id'] ?? 0)); }
if ($page === 'crfile') { cr_stream_file((int)($_GET['id'] ?? 0)); }                                                    // R59-001
if ($page === 'nf_open') { require_login(); nf_open((int)($_GET['id'] ?? 0)); }

if (in_array($page, ['offers', 'offer', 'offer_edit', 'admin_offer_statuses'], true) && function_exists('of_visible')) { require_login(); of_require(); }   // R31-006
if (function_exists('hr_only')) hr_gate($page);
if (function_exists('emp_only')) emp_gate($page);                                                 // R54-001: employee scope → My leave only
if ($page === 'hr_doc') { require_staff(); hr_doc_stream((int)($_GET['id'] ?? 0)); }
if ($page === 'hr_people_template') { require_staff(); hr_people_template(); }   // R54-002
if ($page === 'hr_pdoc') { require_staff(); hr_pdoc_stream((int)($_GET['id'] ?? 0)); }      // R56-001                                                   // R51-001: HR scope → Planning only
flt_apply($page);                                                                               // R29-008: remembered list filters
if ($page === 'vessel' && function_exists('vm_has') && vm_has() && ($mi = scalar('SELECT merged_into FROM vessels WHERE id = ?', [(int)($_GET['id'] ?? 0)]))) redirect('vessel', ['id' => vm_resolve((int)$mi)] + array_diff_key($_GET, ['p' => 1, 'id' => 1]));   // R30-001: a retired duplicate opens its master
$views = ['login','twofa','dashboard','vessels','projects','documents','reports','cyber','support','account',
          'subproject','drawings','overlay','weekly','monthly','report_sp',
          'project_new','subproject_new','project_edit','subproject_edit','vessel_new','vessel_edit','import',
          'admin_numbering','admin_offer_classes','proformas','proforma','cashflow','report_cf','eng_requests','admin_engineering','admin_ai','admin_hr','hr_person','hr_dashboard','my_leave','hr_requests','production','production_order','production_edit','admin_systems','admin_users','admin_roles','admin_log','settings','approvals','approval','timesheets','timesheet','timesheet_edit','manhours','report_ts','admin_disciplines','admin_about','tickets','ticket','ticket_edit','vessel','report_vessel','offers','offer','offer_edit','weekly_vessel','report_weekly','fsr','fsr_edit','fsr_list','isr','isr_edit','isr_list','jo','jo_edit','jo_list','joborders','contacts','contact','contact_edit','companies','admin_offer_statuses','notifications','admin_customers','manual','kb','kb_q','requests','request','hr_leaves','hr_training','hr_eval','license','tasks','task_edit','admin_task_kinds','admin_file_kinds','admin_calendars','admin_branches','planning','equipment','equipment_view','equipment_edit','fd_import','admin_issue_cats','admin_vessel_merge','rs','rs_edit','wf','vessel_decks','admin_email_templates','admin_rate_cards','admin_offer_texts','cost_control','work','myworks','workload','acc_register','acc_reports','acc_report','fsr_ext','kpis','risks','admin_kpis','report_kpi','sales','report_sales','admin_sales'];
$view  = in_array($page, $views, true) ? $page : '404';
if (in_array($view, ['subproject_new', 'project_edit', 'subproject_edit'], true)) $view = 'project_new';   // one file, four modes
if (in_array($view, ['vessel_new', 'vessel_edit'], true)) $view = 'vessel_form';
if ($view === 'fsr') $view = 'fsr_view';
if ($view === 'isr') $view = 'isr_view';
if ($view === 'jo') $view = 'jo_view';
if ($view === 'timesheet') $view = 'timesheet_view';
if ($view === 'vessel') $view = 'vessel_view';
if ($view === 'rs') $view = 'rs_view';
if ($view === 'wf') $view = 'wf_view';
if ($page === 'joborders') $view = 'jo_list';
if ($page === 'settings') {                                        // ?p=settings[&tab=systems|roles|users|log]
    $view = ['numbering' => 'admin_numbering', 'offer_classes' => 'admin_offer_classes', 'systems' => 'admin_systems', 'roles' => 'admin_roles', 'users' => 'admin_users', 'log' => 'admin_log', 'disciplines' => 'admin_disciplines', 'offer_statuses' => (function_exists('of_visible') && !of_visible() ? 'account' : 'admin_offer_statuses'), 'customers' => 'admin_customers', 'about' => 'admin_about', 'import' => 'import', 'license' => 'license', 'task_kinds' => 'admin_task_kinds', 'file_kinds' => 'admin_file_kinds', 'calendars' => 'admin_calendars', 'branches' => 'admin_branches', 'email_templates' => 'admin_email_templates', 'rate_cards' => 'admin_rate_cards', 'offer_texts' => 'admin_offer_texts', 'kpis' => 'admin_kpis', 'sales' => 'admin_sales'][(string)($_GET['tab'] ?? '')] ?? 'account';
}
require __DIR__ . '/views/' . $view . '.php';

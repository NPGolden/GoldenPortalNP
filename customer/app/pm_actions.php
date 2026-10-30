<?php
/**
 * PM-tool POST actions.                                     GAS-CP-R3-001
 * Included by index.php inside its action switch; returns true when it
 * handled the action (it always redirects), false to fall through.
 */
declare(strict_types=1);

function pm_handle_action(string $action, string $page): bool {
    $back = function (array $extra = []) use ($page): never {
        // return to the page the form lives on, cache-proof
        $params = $extra;
        foreach (['id', 'tab', 'project', 'd'] as $k) if (isset($_GET[$k])) $params[$k] = $_GET[$k];
        $params['_r'] = base_convert((string)(time() . random_int(10, 99)), 10, 36);
        redirect($page, $params);
    };
    $date = fn(string $k) => ($_POST[$k] ?? '') !== '' ? substr((string)$_POST[$k], 0, 10) : null;
    $str  = fn(string $k, int $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max) ?: null;

    switch ($action) {

        /* ------------------------------------------- projects (staff) */
        case 'project_save': {
            require_staff();
            if (function_exists('num_has') && num_has() && trim((string)($_POST['erp_code'] ?? '')) === '') $_POST['erp_code'] = num_next_project();   // R43-001: automatic project number
            [$main, ] = split_project_code((string)$_POST['erp_code']);
            if (scalar('SELECT COUNT(*) FROM projects WHERE erp_code = ?', [$str('erp_code', 40)])) throw new RuntimeException(t('pr.dup_code'));
            [$pmId, $pmName] = pm_from_post();
            q('INSERT INTO projects (customer_id, vessel_id, name, erp_code, project_code, source, status, pm_user_id, project_manager, category, next_milestone)
               VALUES (?, ?, ?, ?, ?, "manual", ?, ?, ?, ?, ?)',
              [(int)$_POST['customer_id'], (int)($_POST['vessel_id'] ?? 0) ?: null, $str('name', 160), $str('erp_code', 40),
               $main, in_array($_POST['status'] ?? '', pm_statuses(), true) ? $_POST['status'] : pm_default_status(), $pmId, $pmName,
               $str('category', 80), $str('next_milestone', 120)]);
            $newPid = (int) db()->lastInsertId();
            if (br_has()) q('UPDATE projects SET branch_id = ? WHERE id = ?', [br_from_post(), $newPid]);                 // R24-001
            pm_save_deadline('projects', $newPid);
            flash('good', t('pr.saved'));
            redirect('projects', ['_r' => uniqid()]);
        }
        case 'project_vessel': {                                 // GAS-CP-R36-006: one-click link of a project without vessel to the vessel its name points at
            require_staff();
            $id = (int)$_POST['id']; $vid = (int)($_POST['vessel_id'] ?? 0);
            $p = one('SELECT id, vessel_id FROM projects WHERE id = ?', [$id]); if (!$p) throw new RuntimeException(t('err.notfound'));
            if (!$vid || !scalar('SELECT id FROM vessels WHERE id = ?', [$vid])) throw new RuntimeException(t('err.notfound'));
            q('UPDATE projects SET vessel_id = ? WHERE id = ?', [$vid, $id]);
            try { q('UPDATE tickets SET vessel_id = ? WHERE project_id = ? AND vessel_id IS NULL', [$vid, $id]); } catch (Throwable $e) {}
            flash('good', t('pr.vessel_linked'));
            redirect('projects', ['open' => $id, '_r' => uniqid()], '#pr' . $id);
        }
        case 'project_update': {
            require_staff();
            $id = (int)$_POST['id'];
            if (!one('SELECT id FROM projects WHERE id = ?', [$id])) throw new RuntimeException(t('err.notfound'));
            $erp = (string)$str('erp_code', 40);
            [$main, ] = split_project_code($erp);
            if (scalar('SELECT COUNT(*) FROM projects WHERE erp_code = ? AND id <> ?', [$erp, $id])) throw new RuntimeException(t('pr.dup_code'));
            $cid = (int)$_POST['customer_id'];
            $vid = (int)($_POST['vessel_id'] ?? 0) ?: null;
            if ($vid && !one('SELECT id FROM vessels WHERE id = ? AND customer_id = ?', [$vid, $cid])) $vid = null;   // vessel must belong to the customer
            [$pmId, $pmName] = pm_from_post((string) scalar('SELECT project_manager FROM projects WHERE id = ?', [$id]));
            q('UPDATE projects SET erp_code = ?, project_code = ?, name = ?, customer_id = ?, vessel_id = ?, status = ?,
                                   pm_user_id = ?, project_manager = ?, category = ?, next_milestone = ? WHERE id = ?',
              [$erp, mb_substr($main, 0, 40), $str('name', 160), $cid, $vid,
               in_array($_POST['status'] ?? '', pm_statuses(), true) ? $_POST['status'] : pm_default_status(),
               $pmId, $pmName, $str('category', 80), $str('next_milestone', 120), $id]);
            if (br_has() && isset($_POST['branch_id'])) q('UPDATE projects SET branch_id = ? WHERE id = ?', [br_from_post(), $id]);   // R24-001
            pm_save_deadline('projects', $id);
            flash('good', t('pr.updated'));
            redirect('projects', ['_r' => uniqid()]);
        }
        case 'subproject_update': {
            require_staff();
            $id = (int)$_POST['id'];
            $s = one('SELECT * FROM subprojects WHERE id = ?', [$id]);
            if (!$s) throw new RuntimeException(t('err.notfound'));
            $code = (string)$str('code', 40);
            if (scalar('SELECT COUNT(*) FROM subprojects WHERE project_id = ? AND code = ? AND id <> ?', [$s['project_id'], $code, $id])) throw new RuntimeException(t('sp.dup_code'));
            q('UPDATE subprojects SET code = ?, name = ?, system_id = ?, description = ?, sort_order = ?, status = ? WHERE id = ?',
              [$code, $str('name', 120), (int)($_POST['system_id'] ?? 0) ?: null, $str('description', 4000), (int)($_POST['sort_order'] ?? 0),
               in_array($_POST['status'] ?? '', pm_statuses(), true) ? $_POST['status'] : $s['status'], $id]);
            if (br_has() && isset($_POST['branch_id'])) q('UPDATE subprojects SET branch_id = ? WHERE id = ?', [br_from_post(), $id]);   // R24-001
            pm_save_invoicing($id); pm_save_deadline('subprojects', $id); pm_track_completed($id); if (function_exists('iv_sub_save')) iv_sub_save($id);
            flash('good', t('sp.updated'));
            redirect('subproject', ['id' => $id, '_r' => uniqid()]);
        }
        case 'subproject_save': {
            require_staff();
            $pid = (int)$_POST['project_id'];
            if (function_exists('num_has') && num_has() && trim((string)($_POST['code'] ?? '')) === '') $_POST['code'] = num_next_sub($pid);   // R43-001: automatic sub-project code
            q('INSERT INTO subprojects (project_id, code, name, system_id, description, sort_order, status)
               VALUES (?, ?, ?, ?, ?, ?, ?)',
              [$pid, $str('code', 40), $str('name', 120), (int)($_POST['system_id'] ?? 0) ?: null, $str('description', 4000), (int)($_POST['sort_order'] ?? 0), pm_default_status()]);
            $newId = (int) db()->lastInsertId();
            if (function_exists('of_sync_project')) of_sync_project($pid);                                           // R57-001: follows an approved project-level offer
            if (br_has()) q('UPDATE subprojects SET branch_id = ? WHERE id = ?', [br_from_post(), $newId]);                // R24-001
            pm_save_invoicing($newId); pm_save_deadline('subprojects', $newId); if (function_exists('iv_sub_save')) iv_sub_save($newId);
            flash('good', t('pr.saved'));
            redirect('subproject', ['id' => $newId]);
        }
        case 'sp_stages': {
            require_staff();
            $id = (int)$_POST['id'];
            $sets = []; $vals = [];
            foreach (stage_defs() as $d) { $sets[] = $d['col'] . ' = ?'; $vals[] = !empty($_POST['na'][$d['col']]) ? PCT_NA : max(0, min(100, (int)($_POST[$d['col']] ?? 0))); }   // N/A = -1 (R24-004)
            if (function_exists('pd_sub_fed') && ($fedK = pd_sub_fed($id))) { $fst = pd_sub_stages($id); $defs = stage_defs(); foreach ($fedK as $k) { $i = array_search($defs[$k]['col'] . ' = ?', $sets, true); if ($i !== false) $vals[$i] = max(0, min(100, (int)$fst[$k]['value'])); } }   // R41-002 / R42-001: fed stages come from the production orders
            $sets[] = 'status = ?';      $vals[] = in_array($_POST['status'] ?? '', pm_statuses(), true) ? $_POST['status'] : pm_default_status();
            $sets[] = 'system_id = ?';   $vals[] = (int)($_POST['system_id'] ?? 0) ?: null;
            $sets[] = 'description = ?'; $vals[] = $str('description', 4000);
            $vals[] = $id;
            q('UPDATE subprojects SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
            pm_save_invoicing($id); pm_save_deadline('subprojects', $id); pm_track_completed($id); if (function_exists('iv_sub_save')) iv_sub_save($id);
            if (of_has()) pm_auto_snapshot($id);
            if (!empty($_POST['snapshot'])) {
                $s = one('SELECT * FROM subprojects WHERE id = ?', [$id]);
                $cols = array_column(stage_defs(), 'col');
                q('INSERT INTO sp_progress_snapshots (subproject_id, snap_date, ' . implode(',', $cols) . ')
                   VALUES (?, CURDATE(), ' . implode(',', array_fill(0, count($cols), '?')) . ')
                   ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", $cols)),
                  array_merge([$id], array_map(fn($c) => (int)$s[$c], $cols)));
                flash('good', t('sp.snapshot_done'));
            } else flash('good', t('sp.saved'));
            $back();
        }

        /* --------------------------------------------- engineering sections (R47-001) */
        case 'eng_budget': case 'eng_dl_save': case 'eng_dl_toggle': case 'eng_dl_delete': case 'eng_done': case 'eng_request': case 'eng_decide': {
            require_staff(); if (!function_exists('eng_has') || !eng_has()) throw new RuntimeException(t('err.noaccess'));
            $sid = (int)($_POST['subproject_id'] ?? 0); $sp = $sid ? load_subproject($sid) : null; if (!$sp) throw new RuntimeException(t('err.notfound'));
            switch ($action) {
                case 'eng_budget':    eng_budget_save_from_post($sid); flash('good', t('eng.saved')); break;
                case 'eng_dl_save':   eng_deliverable_save_from_post($sid); flash('good', t('eng.saved')); break;
                case 'eng_dl_toggle': eng_deliverable_toggle($sid, (int)($_POST['dl_id'] ?? 0)); break;
                case 'eng_dl_delete': eng_deliverable_delete($sid, (int)($_POST['dl_id'] ?? 0)); flash('good', t('eng.dl_deleted')); break;
                case 'eng_done':      eng_section_done($sid, (int)($_POST['section_id'] ?? 0), !empty($_POST['done']), (string)($str('note', 500) ?? '')); flash('good', !empty($_POST['done']) ? t('eng.done_saved') : t('eng.reopened')); break;
                case 'eng_request':   eng_request_save_from_post($sid); flash('good', t('eng.request_sent')); break;
                case 'eng_decide':    eng_request_decide((int)($_POST['req_id'] ?? 0), ($_POST['decision'] ?? '') === 'approve', (string)($str('note', 500) ?? '')); flash('good', t('eng.decided')); break;
            }
            redirect('subproject', ['id' => $sid, 'tab' => 'engineering']);
        }
        case 'eng_sections_save': { eng_sections_save_from_post(); flash('good', t('eng.saved')); redirect('admin_engineering', ['_r' => uniqid()]); }
        case 'eng_mapping_save':  { eng_mapping_save_from_post();  flash('good', t('eng.saved')); redirect('admin_engineering', ['_r' => uniqid()]); }
        case 'ai_test': { require_cp_admin(); $r = ai_test(); flash($r['ok'] ? 'good' : 'bad', $r['ok'] ? t('ai.test_ok', ['t' => $r['text'], 'ms' => $r['ms']]) : t('ai.test_fail', ['e' => $r['error']])); redirect('admin_ai', ['_r' => uniqid()]); }   // R50-002
        case 'hr_req_new':    { $pid = (int)($_POST['person_id'] ?? 0); $id = hr_request_create_from_post($pid ? hr_person($pid) : null); flash('good', t('hr.req_sent')); redirect($pid ? 'hr_requests' : 'my_leave', ['_r' => uniqid()]); }   // R54-001
        case 'hr_req_cancel': { hr_request_cancel((int)($_POST['id'] ?? 0)); flash('good', t('hr.req_cancelled')); redirect(hr_can() ? 'hr_requests' : 'my_leave', ['_r' => uniqid()]); }
        case 'hr_req_decide': { $rq = one('SELECT person_id, status, type_id, date_from FROM hr_requests WHERE id = ?', [(int)($_POST['id'] ?? 0)]); hr_request_decide((int)($_POST['id'] ?? 0), ($_POST['decision'] ?? '') === 'approve', (string)($_POST['note'] ?? '')); if ($rq && function_exists('hr_audit')) hr_audit((int)$rq['person_id'], 'request', (hr_type((int)$rq['type_id'])['code'] ?? '') . ' ' . $rq['date_from'], $rq['status'], ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected', (string)($_POST['note'] ?? '') ?: null); flash('good', t('hr.decided')); if (!empty($_POST['back_person'])) redirect('hr_person', ['id' => (int)$_POST['back_person'], '_r' => uniqid()], '#hr-req'); redirect('hr_requests', ['_r' => uniqid()]); }
        case 'hr_person_save': { $before = (int)($_POST['id'] ?? 0) ? hr_person((int)$_POST['id']) : null; $pid = hr_person_save_from_post(); if (function_exists('hr_audit_card')) { if ($before) hr_audit_card($pid, $before, hr_person($pid) ?? []); else hr_audit($pid, 'card', 'created', null, (string)($_POST['name_en'] ?? '')); } flash('good', t('hr.saved')); if (function_exists('hr3_has') && hr3_has()) redirect('hr_person', ['id' => $pid, '_r' => uniqid()]); redirect('admin_hr', ['tab' => 'people', '_r' => uniqid()]); }
        case 'hr_import_upload': { if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('hr.no_file')); $rows = hr_import_parse($f['tmp_name']); $_SESSION['hr_import'] = ['rows' => $rows, 'year' => (int)($_POST['year'] ?? date('Y'))]; redirect('admin_hr', ['tab' => 'import', '_r' => uniqid()]); }
        case 'hr_people_upload': { if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('hr.no_file')); $_SESSION['hr_people_import'] = hr_people_import_parse($f['tmp_name']); redirect('admin_hr', ['tab' => 'people', 'imp' => 1, '_r' => uniqid()], '#hr-people-import'); }   // R54-002
        case 'hr_people_apply':  { $rows = $_SESSION['hr_people_import'] ?? null; if (!$rows) throw new RuntimeException(t('err.notfound')); $res = hr_people_import_apply($rows, (array)($_POST['sel'] ?? [])); unset($_SESSION['hr_people_import']); flash('good', t('hr.people_imported', ['n' => $res['updated'], 'c' => $res['created']])); redirect('admin_hr', ['tab' => 'people', '_r' => uniqid()]); }
        case 'hr_msg_send': { hr_message_send_from_post(); flash('good', t('hr.msg_sent')); if (hr_can()) redirect('hr_person', ['id' => (int)($_POST['person_id'] ?? 0), '_r' => uniqid()], '#hr-msg'); redirect('my_leave', ['_r' => uniqid()], '#hr-msg'); }   // R56-002
        case 'hr_person_types_save': { hr_person_types_save_from_post(); flash('good', t('hr.saved')); redirect('hr_person', ['id' => (int)($_POST['pid'] ?? 0), 'tab' => 'types', '_r' => uniqid()]); }   // R55-004
        case 'hr_carry_transfer': { $pid = (int)($_POST['person_id'] ?? 0); $c = hr_carry_transfer($pid, (int)($_POST['year'] ?? date('Y')), ($_POST['days'] ?? '') !== '' ? (float) str_replace(',', '.', (string)$_POST['days']) : null, (string)($_POST['note'] ?? '') ?: null); flash('good', t('hr.carried', ['n' => rtrim(rtrim(number_format($c, 2, '.', ''), '0'), '.')])); redirect('hr_person', ['id' => $pid, 'tab' => 'ent', 'year' => (int)($_POST['year'] ?? date('Y')), '_r' => uniqid()]); }   // R55-004
        case 'hr_rules_save':   { hr_rules_save_from_post(); flash('good', t('hr.saved')); redirect('admin_hr', ['tab' => 'rules', '_r' => uniqid()]); }   // R55-001
        case 'hr_ent_override': { hr_entitlement_override_from_post(); flash('good', t('hr.saved')); redirect('hr_person', ['id' => (int)($_POST['pid'] ?? 0), 'tab' => 'ent', '_r' => uniqid()]); }
        case 'hr_ent_rollover': { $n = hr_entitlements_rollover((int)($_POST['year'] ?? date('Y'))); flash('good', t('hr.rolled', ['n' => $n])); redirect('admin_hr', ['tab' => 'ent', 'year' => (int)($_POST['year'] ?? date('Y')), '_r' => uniqid()]); }
        case 'hr_import_batch_delete': { $n = hr_import_batch_delete((string)($_POST['batch'] ?? '')); flash('good', t('hr.batch_deleted', ['n' => $n])); redirect('admin_hr', ['tab' => 'import', '_r' => uniqid()]); }
        case 'hr_emp_event':  { $pid = (int)($_POST['person_id'] ?? 0); hr_employment_event_from_post($pid); if (function_exists('hr_audit')) hr_audit($pid, 'employment', (string)($_POST['ev'] ?? ''), null, (string)($_POST['date'] ?? '') . ' ' . (string)($_POST['contract_type'] ?? '') . ((string)($_POST['reason'] ?? '') ? ' ' . $_POST['reason'] : ''), (string)($_POST['note'] ?? '') ?: null); flash('good', t('hr.saved')); redirect('hr_person', ['id' => $pid, '_r' => uniqid()], '#hr-emp'); }   // R56-001
        case 'hr_emp_delete': { $pid = (int)($_POST['person_id'] ?? 0); hr_employment_delete((int)($_POST['id'] ?? 0)); if (function_exists('hr_audit')) hr_audit($pid, 'employment', 'deleted', (string)(int)($_POST['id'] ?? 0), null); flash('good', t('hr.saved')); redirect('hr_person', ['id' => $pid, '_r' => uniqid()], '#hr-emp'); }
        case 'hr_doc_upload': { $pid = (int)($_POST['person_id'] ?? 0); $n = hr_doc_upload_from_post($pid); if ($n && function_exists('hr_audit')) hr_audit($pid, 'document', 'added', null, (string)$n . ' × ' . (string)($_POST['doc_kind'] ?? '')); flash($n ? 'good' : 'warn', $n ? t('hr.docs_saved', ['n' => $n]) : t('hr.no_file')); redirect('hr_person', ['id' => $pid, '_r' => uniqid()], '#hr-docs'); }
        case 'hr_doc_delete': { $pid = (int)($_POST['person_id'] ?? 0); $dn = (string) (scalar('SELECT name FROM hr_docs WHERE id = ?', [(int)($_POST['id'] ?? 0)]) ?: ''); hr_doc_delete((int)($_POST['id'] ?? 0)); if (function_exists('hr_audit')) hr_audit($pid, 'document', 'deleted', $dn, null); flash('good', t('hr.saved')); redirect('hr_person', ['id' => $pid, '_r' => uniqid()], '#hr-docs'); }
        case 'hr_leave_upload': { if (!hr_can()) throw new RuntimeException(t('err.noaccess')); $f = $_FILES['file'] ?? null; if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException(t('hr.no_file')); $_SESSION['hr_leave_import'] = hr_leave_import_parse($f['tmp_name']); redirect('admin_hr', ['tab' => 'import', 'limp' => 1, '_r' => uniqid()], '#hr-leave-import'); }   // R54-004
        case 'hr_leave_apply':  { $rows = $_SESSION['hr_leave_import'] ?? null; if (!$rows) throw new RuntimeException(t('err.notfound')); $res = hr_leave_import_apply($rows, (array)($_POST['sel'] ?? []), !empty($_POST['history_only']), !empty($_POST['create_former'])); unset($_SESSION['hr_leave_import']); flash('good', t('hr.leaves_imported', ['n' => $res['written'], 'b' => $res['board']]) . (!empty($res['created']) ? ' ' . t('hr.former_created', ['c' => $res['created']]) : '')); redirect('admin_hr', ['tab' => 'import', '_r' => uniqid()]); }
        case 'hr_import_apply':  { $st = $_SESSION['hr_import'] ?? null; if (!$st) throw new RuntimeException(t('err.notfound')); $res = hr_import_apply($st['rows'], (array)($_POST['map'] ?? []), (int)$st['year']); unset($_SESSION['hr_import']); flash('good', t('hr.imported', ['n' => $res['written'], 'c' => $res['created']])); redirect('admin_hr', ['tab' => 'people', 'year' => (int)$st['year'], '_r' => uniqid()]); }
        case 'hr_type_save': { hr_type_save_from_post(); flash('good', t('hr.saved')); redirect('admin_hr', ['tab' => 'types', 'year' => (int)($_GET['year'] ?? date('Y')), '_r' => uniqid()], '#hr-types'); }   // R51-001
        case 'hr_ent_save':  { hr_entitlements_save_from_post(); flash('good', t('hr.saved')); redirect('admin_hr', ['tab' => 'ent', 'year' => (int)($_POST['year'] ?? date('Y')), '_r' => uniqid()], '#hr-ent'); }
        case 'hr_ent_copy':  { $n = hr_entitlements_copy((int)($_POST['year'] ?? date('Y'))); flash('good', t('hr.copied', ['n' => $n])); redirect('admin_hr', ['tab' => 'ent', 'year' => (int)($_POST['year'] ?? date('Y')), '_r' => uniqid()], '#hr-ent'); }
        case 'ai_settings_save': { ai_settings_save_from_post(); flash('good', t('ai.saved')); redirect('admin_ai', ['_r' => uniqid()]); }   // R50-001
        case 'eng_decide_list': {
            require_staff(); if (!function_exists('eng_has') || !eng_has()) throw new RuntimeException(t('err.noaccess'));   // permission checked per request inside eng_request_decide
            eng_request_decide((int)($_POST['req_id'] ?? 0), ($_POST['decision'] ?? '') === 'approve', (string)($str('note', 500) ?? '')); flash('good', t('eng.decided'));
            redirect('eng_requests', ['status' => 'pending']);
        }

        /* --------------------------------------------- drawing tracker */
        case 'dr_save': {
            require_staff();
            $pid = (int)$_POST['project_id']; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
            $fileId = null;
            if (!empty($_FILES['file']['name'])) $fileId = cp_store_file($_FILES['file'], $pid, $sid, 'drawing', 'external', $str('title', 200));
            $next = (int) scalar('SELECT COALESCE(MAX(seq_no),0)+1 FROM drawing_requests WHERE project_id = ? AND kind = ?', [$pid, $_POST['kind'] ?? 'equipment']);
            q('INSERT INTO drawing_requests (project_id, subproject_id, system_id, kind, seq_no, title, location, location_status, responsible,
                                             request_sent, expected_at, received_at, file_name, file_id, status, remarks)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$pid, $sid, (int)($_POST['system_id'] ?? 0) ?: null, ($_POST['kind'] ?? '') === 'principal' ? 'principal' : 'equipment', $next,
               $str('title', 200), $str('location', 120), in_array($_POST['location_status'] ?? '', ['TBD','Confirmed','N/A'], true) ? $_POST['location_status'] : 'TBD',
               $str('responsible', 80) ?: 'Shipyard', $date('request_sent'), $date('expected_at'), $date('received_at'),
               $fileId ? mb_substr((string)$_FILES['file']['name'], 0, 255) : null, $fileId,
               in_array($_POST['status'] ?? '', ['pending','received','not_required'], true) ? $_POST['status'] : 'pending', $str('remarks', 2000)]);
            flash('good', t('dr.saved'));
            $back();
        }
        case 'dr_update': {                                   // GAS-CP-R36-003: edit a request / mark it received from the tracker page
            require_staff();
            $id = (int)$_POST['id']; $d = one('SELECT * FROM drawing_requests WHERE id = ?', [$id]);
            if (!$d) throw new RuntimeException(t('err.notfound'));
            $pid = (int)$d['project_id']; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
            if ($sid && !scalar('SELECT id FROM subprojects WHERE id = ? AND project_id = ?', [$sid, $pid])) $sid = null;
            $fileId = (int)$d['file_id'] ?: null; $fileName = $d['file_name'];
            if (!empty($_FILES['file']['name'])) { $fileId = cp_store_file($_FILES['file'], $pid, $sid, 'drawing', 'external', $str('title', 200) ?: $d['title']); $fileName = mb_substr((string)$_FILES['file']['name'], 0, 255); }
            $status = in_array($_POST['status'] ?? '', ['pending','received','not_required'], true) ? $_POST['status'] : $d['status'];
            $recv = $date('received_at'); if ($status === 'received' && !$recv) $recv = date('Y-m-d'); if ($status === 'pending') $recv = null;
            q('UPDATE drawing_requests SET subproject_id = ?, system_id = ?, kind = ?, title = ?, location = ?, location_status = ?, responsible = ?, request_sent = ?, expected_at = ?, received_at = ?, file_name = ?, file_id = ?, status = ?, remarks = ? WHERE id = ?',
              [$sid, (int)($_POST['system_id'] ?? 0) ?: null, ($_POST['kind'] ?? '') === 'principal' ? 'principal' : 'equipment', $str('title', 200) ?: $d['title'], $str('location', 120),
               in_array($_POST['location_status'] ?? '', ['TBD','Confirmed','N/A'], true) ? $_POST['location_status'] : $d['location_status'], $str('responsible', 80) ?: 'Shipyard',
               $date('request_sent'), $date('expected_at'), $recv, $fileName, $fileId, $status, $str('remarks', 2000), $id]);
            flash('good', t('dr.saved'));
            $back();
        }
        case 'dr_delete': {
            require_staff();
            $id = (int)$_POST['id'];
            q('DELETE FROM drawing_requests WHERE id = ?', [$id]);
            flash('good', t('dr.deleted'));
            $back();
        }
        case 'dr_import': {
            require_staff();
            require_once __DIR__ . '/xlsx.php';
            $pid = (int)$_POST['project_id'];
            if (empty($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $n = pm_import_tracker($_FILES['xlsx']['tmp_name'], $pid, !empty($_POST['replace']));
            $rev = 'R' . (int) scalar('SELECT COUNT(*) FROM drawing_tracker_revisions WHERE project_id = ?', [$pid]);
            q('INSERT INTO drawing_tracker_revisions (project_id, rev_no, issued_at, description, issued_by, sent_to) VALUES (?,?,CURDATE(),?,?,?)',
              [$pid, $rev, $str('rev_desc', 255) ?: t('dr.import_rev'), 'Golden A/S', 'Shipyard']);
            flash('good', t('dr.imported', ['n' => $n]));
            $back();
        }

        /* ------------------------------------- owner / class submittals */
        case 'own_save':
        case 'cls_save': {
            require_staff();
            $isClass = $action === 'cls_save';
            $pid = (int)$_POST['project_id']; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
            $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], $pid, $sid, $isClass ? 'class_doc' : 'owner_doc', 'external', $str('title', 200), null, null, ['doc_no' => $str('doc_no', 60), 'revision' => $str('revision', 16) ?: 'R0']) : null;
            $status = in_array($_POST['status'] ?? '', ['draft','submitted','approved','approved_comments','rejected','resubmitted'], true) ? $_POST['status'] : 'submitted';
            if ($isClass) {
                q('INSERT INTO class_submittals (project_id, subproject_id, class_society, class_ref, doc_no, title, revision, submitted_at, due_at, decided_at, status, comments, file_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$pid, $sid, $str('class_society', 40) ?: 'Other', $str('class_ref', 80), $str('doc_no', 60), $str('title', 200), $str('revision', 16) ?: 'R0',
                   $date('submitted_at'), $date('due_at'), $date('decided_at'), $status, $str('comments', 2000), $fileId]);
            } else {
                q('INSERT INTO owner_approvals (project_id, subproject_id, doc_no, title, revision, submitted_at, due_at, decided_at, status, comments, file_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$pid, $sid, $str('doc_no', 60), $str('title', 200), $str('revision', 16) ?: 'R0',
                   $date('submitted_at'), $date('due_at'), $date('decided_at'), $status, $str('comments', 2000), $fileId]);
            }
            flash('good', t('sub.saved'));
            $back();
        }

        /* ------------------------------------- owner approvals (R10) */
        case 'oa_decide': {                  // the owner's decision (admins may record one on behalf)
            $u = require_login();
            if (is_staff() && !is_cp_admin()) { http_response_code(403); exit(t('err.noaccess')); }
            $id = (int)$_POST['id'];
            oa_decide($id, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''), is_cp_admin());
            flash('good', t('oa.decided'));
            redirect('approval', ['id' => $id, '_r' => uniqid()]);
        }
        /* ---------------------------------------------------- R14: tickets */
        case 'tk_save': {
            require_login();
            if (!tk_has()) throw new RuntimeException(t('tk.need_r14'));
            $id = (int)($_POST['id'] ?? 0);
            $new = $id === 0;
            $id = tk_save_from_post($id);
            flash('good', t($new ? 'tk.raised' : 'tk.saved'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        case 'tk_comment': {
            require_login();
            $id = (int)$_POST['id'];
            if (!tk_load($id)) throw new RuntimeException(t('err.notfound'));
            $cid = tk_comment($id, (string)($_POST['body'] ?? ''), (int)($_POST['parent_id'] ?? 0) ?: null, (string)($_POST['visibility'] ?? 'internal'));
            tk_store_uploads($id, $cid);
            if (function_exists('mt_notify')) mt_notify((string)($_POST['body'] ?? ''), (string)(scalar('SELECT ticket_no FROM tickets WHERE id = ?', [$id]) ?: ('#' . $id)), url('ticket', ['id' => $id]) . '#comments', 'tk:' . $id . ':c' . $cid);
            flash('good', t('oa.commented'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        case 'tk_comment_delete': {
            require_login();
            $id = (int)$_POST['id'];
            if (!tk_load($id)) throw new RuntimeException(t('err.notfound'));
            tk_comment_delete($id, (int)$_POST['cid']);
            flash('good', t('cm.deleted_ok'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        case 'tk_status': {
            require_login();
            $id = (int)$_POST['id'];
            tk_set_status($id, (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('good', t('tk.updated'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        /* ------------------------------------------------------ R15: offers */
        case 'iss_save':   { $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; $pid = (int)$_POST['project_id']; if ($sid) { $sp = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) throw new RuntimeException(t('err.notfound')); $pid = (int)$sp['project_id']; } $iid = iss_save($pid, $sid, (int)($_POST['id'] ?? 0)); flash('good', t('iss.saved')); redirect($sid ? 'subproject' : 'project', ($sid ? ['id' => $sid, 'tab' => 'issues'] : ['id' => $pid]) + ['_r' => uniqid()], '#iss-' . $iid); }
        case 'iss_status': { $i = iss_get((int)$_POST['id']); if (!$i) throw new RuntimeException(t('err.notfound')); iss_set_status((int)$i['id'], (string)$_POST['status'], (string)($_POST['note'] ?? '')); flash('good', t('iss.' . ((string)$_POST['status'] === 'closed' ? 'closed_ok' : 'updated'))); redirect($i['subproject_id'] ? 'subproject' : 'project', ($i['subproject_id'] ? ['id' => (int)$i['subproject_id'], 'tab' => 'issues'] : ['id' => (int)$i['project_id']]) + ['_r' => uniqid()], '#iss-' . (int)$i['id']); }
        case 'iss_import': {
            require_staff(); $sid = (int)($_POST['subproject_id'] ?? 0) ?: null; $pid = (int)$_POST['project_id']; if ($sid) { $sp = one('SELECT project_id FROM subprojects WHERE id = ?', [$sid]); if (!$sp) throw new RuntimeException(t('err.notfound')); $pid = (int)$sp['project_id']; }
            if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $dry = !empty($_POST['dry']); $res = iss_import($_FILES['xlsx']['tmp_name'], $pid, $sid, $dry); $res['kind'] = 'issues'; $res['dry'] = $dry; $_SESSION['cp_import_result'] = $res;
            redirect($sid ? 'subproject' : 'project', ($sid ? ['id' => $sid, 'tab' => 'issues'] : ['id' => $pid]) + ['_r' => uniqid()], '#iss-import');
        }
        case 'iss_delete': { $i = iss_get((int)$_POST['id']); if (!$i) throw new RuntimeException(t('err.notfound')); iss_delete((int)$i['id']); flash('good', t('iss.deleted')); redirect($i['subproject_id'] ? 'subproject' : 'project', ($i['subproject_id'] ? ['id' => (int)$i['subproject_id'], 'tab' => 'issues'] : ['id' => (int)$i['project_id']]) + ['_r' => uniqid()]); }
        case 'fd_check':  { $f = (string)$_POST['file']; $_SESSION['fd_report'] = fd_check($f); redirect('fd_import', ['_r' => uniqid()]); }
        case 'fd_run':    { $f = (string)$_POST['file']; fd_path($f); $_SESSION['fd_jobs'][$f] = ($_SESSION['fd_jobs'][$f] ?? []) + ['total' => 0, 'offset' => 0, 'imported' => 0, 'skipped' => 0, 'comments' => 0]; $_SESSION['fd_jobs'][$f]['done'] = false; redirect('fd_import', ['run' => $f, 'go' => 1, 'cv' => (int)!empty($_POST['cv']), '_r' => uniqid()]); }
        case 'fd_upload': { $n = fd_upload($_FILES['xml'] ?? []); flash('good', t('fd.uploaded', ['f' => $n])); redirect('fd_import', ['_r' => uniqid()]); }
        case 'fd_delete': { fd_delete_file((string)$_POST['file']); flash('good', t('fd.file_deleted')); redirect('fd_import', ['_r' => uniqid()]); }
        case 'ofn_save':   { of_require(); $oid = (int)$_POST['offer']; $nid = ofn_save($oid, (string)($_POST['body'] ?? ''), (int)($_POST['note_id'] ?? 0)); flash('good', t('ofn.saved')); redirect('offer', ['id' => $oid, '_r' => uniqid()], '#note-' . $nid); }
        case 'ofn_delete': { of_require(); $oid = ofn_delete((int)$_POST['id']); flash('good', t('ofn.deleted')); redirect('offer', ['id' => $oid, '_r' => uniqid()], '#notes'); }
        case 'of_save': {
            require_staff(); of_require();
            if (!of_has()) throw new RuntimeException(t('of.need_r15'));
            $id = (int)($_POST['id'] ?? 0); $new = !$id;
            $id = of_save_from_post($id); if (function_exists('oc_apply_from_post')) oc_apply_from_post($id);   // R44-001
            flash('good', t($new ? 'of.recorded' : 'of.saved'));
            redirect('offer', ['id' => $id, '_r' => uniqid()]);
        }
        case 'of_decide': {
            require_login(); of_require();
            $id = (int)$_POST['id'];
            of_decide($id, (string)($_POST['decision'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('good', t('of.decided'));
            redirect('offer', ['id' => $id, '_r' => uniqid()]);
        }
        case 'of_status': {
            require_staff(); of_require();
            $id = (int)$_POST['id'];
            of_set_status($id, (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? ''));
            flash('good', t('of.saved'));
            redirect('offer', ['id' => $id, '_r' => uniqid()]);
        }
        case 'of_delete': {
            require_cp_admin();
            of_delete((int)$_POST['id']);
            flash('good', t('of.deleted'));
            redirect('offers', ['_r' => uniqid()]);
        }
        /* ------------------------------------- file archive (R20) */
        case 'em_draft': { em_draft_from_post(); }
        /* ------------------------------------------------ R38-001: accounting register and reports */
        case 'rk_save':    { $rid = rk_save_from_post(); flash('good', t('rk.saved')); $sid = (int)($_POST['subproject_id'] ?? 0); $pid = (int)($_POST['project_id'] ?? 0); redirect($sid ? 'subproject' : 'risks', ($sid ? ['id' => $sid, 'tab' => 'risks'] : ['project' => $pid]) + ['_r' => uniqid()], '#rk' . $rid); }
        case 'rk_delete':  { require_staff(); $r = one('SELECT * FROM sp_risks WHERE id = ?', [(int)$_POST['id']]); if (!$r) throw new RuntimeException(t('err.notfound')); rk_delete((int)$r['id']); flash('good', t('rk.deleted')); redirect($r['subproject_id'] ? 'subproject' : 'risks', ($r['subproject_id'] ? ['id' => (int)$r['subproject_id'], 'tab' => 'risks'] : ['project' => (int)$r['project_id']]) + ['_r' => uniqid()]); }
        case 'rk_seed':    { require_staff(); $pid = (int)$_POST['project_id']; $n = rk_seed_from_inspections($pid); flash('good', t('rk.seeded', ['n' => $n])); $sid = (int)($_POST['subproject_id'] ?? 0); redirect($sid ? 'subproject' : 'risks', ($sid ? ['id' => $sid, 'tab' => 'risks'] : ['project' => $pid]) + ['_r' => uniqid()]); }
        case 'sl_targets': { sl_targets_save_from_post(); flash('good', t('sl.targets_saved')); redirect('admin_sales', ['year' => (int)$_POST['year'], 'month' => (int)$_POST['month'], '_r' => uniqid()]); }
        case 'kpi_targets': { kpi_targets_save_from_post(); flash('good', t('kpi.targets_saved')); redirect('settings', ['tab' => 'kpis', '_r' => uniqid()]); }
        case 'fsr_ext_relink': { $id = (int)$_POST['id']; fsr_ext_relink($id); flash('good', t('fsr.relinked_ok')); redirect('fsr', ['id' => $id, '_r' => uniqid()]); }
        case 'fsr_ext_save': { $id = fsr_ext_save_from_post(); flash('good', t('fsr.ext_attached_ok')); redirect('fsr', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_new':     { $id = acc_create_from_post(); flash('good', t('acc.created')); redirect('acc_report', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_new_all': { require_staff(); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['to'] ?? '')) ? $_POST['to'] : date('Y-m-d'); $ids = acc_create_all($to, ($_POST['zone'] ?? '') === 'overseas' ? 'overseas' : 'local'); flash($ids ? 'good' : 'warn', t('acc.created_n', ['n' => count($ids)])); if (count($ids) === 1) redirect('acc_report', ['id' => $ids[0], '_r' => uniqid()]); redirect('acc_reports', ['status' => 'draft', '_r' => uniqid()]); }
        case 'acc_save':    { $id = (int)$_POST['id']; acc_lines_save_from_post($id); flash('good', t('acc.saved')); redirect('acc_report', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_release': { $id = (int)$_POST['id']; acc_line_release($id, (int)$_POST['line_id']); redirect('acc_report', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_issue':   { $id = (int)$_POST['id']; $r = acc_issue($id); flash('good', t('acc.issued', ['no' => $r['report_no'] . ' ' . $r['revision']])); redirect('acc_report', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_answer':  { $id = (int)$_POST['id']; acc_answer_from_post($id); flash('good', t('acc.answered')); redirect('acc_report', ['id' => $id, '_r' => uniqid()]); }
        case 'acc_delete':  { $id = (int)$_POST['id']; acc_delete_draft($id); flash('good', t('acc.deleted')); redirect('acc_reports', ['_r' => uniqid()]); }
        case 'acc_sync':    { acc_require(); $n = acc_sync(); flash('good', t('acc.synced', ['n' => $n])); redirect('acc_register', ['_r' => uniqid()]); }
        /* ---------------------------------------------- R35-001: work packages */
        case 'wp_save': { $sid = (int)$_POST['subproject_id']; $wid = wp_save_from_post($sid); flash('good', t('wp.saved')); redirect('subproject', ['id' => $sid, 'tab' => 'works', '_r' => uniqid()]); }
        case 'wp_status': { require_staff(); $wid = (int)$_POST['id']; wp_set_status($wid, (string)($_POST['status'] ?? '')); flash('good', t('wp.status_saved')); $back = (string)($_GET['p'] ?? 'work'); if ($back === 'myworks') redirect('myworks', ['_r' => uniqid()]); redirect('work', ['id' => $wid, '_r' => uniqid()]); }
        case 'wp_ack': { require_staff(); wp_ack((int)$_POST['id']); flash('good', t('wp.acked_ok')); redirect('work', ['id' => (int)$_POST['id'], '_r' => uniqid()]); }
        case 'wp_delete': { require_staff(); $w = one('SELECT subproject_id FROM sp_works WHERE id = ?', [(int)$_POST['id']]); wp_delete((int)$_POST['id']); flash('good', t('wp.deleted')); redirect('subproject', ['id' => (int)($w['subproject_id'] ?? 0), 'tab' => 'works', '_r' => uniqid()]); }
        /* ---------------------------------------------- R34-001: budget vs actual */
        case 'bg_save': { $sid = (int)$_POST['subproject_id']; bg_save_from_post($sid); bg_check_alerts($sid); flash('good', t('bg.saved')); redirect('subproject', ['id' => $sid, 'tab' => 'budget', '_r' => uniqid()]); }
        case 'bg_cost_save': { $sid = (int)$_POST['subproject_id']; bg_cost_save_from_post($sid); flash('good', t('bg.cost_saved')); redirect('subproject', ['id' => $sid, 'tab' => 'budget', '_r' => uniqid()], '#bg-cost'); }
        case 'bg_cost_delete': { $sid = (int)$_POST['subproject_id']; bg_cost_delete($sid, (int)$_POST['cost_id']); flash('good', t('bg.cost_deleted')); redirect('subproject', ['id' => $sid, 'tab' => 'budget', '_r' => uniqid()]); }
        /* ---------------------------------------------- R33-001: rate cards, offer builder, offer texts */
        case 'rl_import': {
            require_cp_admin(); require_once __DIR__ . '/xlsx.php';
            if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $dry = !empty($_POST['dry']); $r = rl_import($_FILES['xlsx']['tmp_name'], (string)$_FILES['xlsx']['name'], $dry);
            $_SESSION['rl_import_result'] = $r + ['dry' => $dry];
            flash($dry ? 'warn' : 'good', t($dry ? 'rl.checked' : 'rl.imported', ['n' => $dry ? array_sum($r['branches']) : $r['items'], 'b' => implode(', ', array_keys($r['branches']))]));
            redirect('admin_rate_cards', ['_r' => uniqid()]);
        }
        case 'rl_delete': { rl_delete((int)$_POST['id']); flash('good', t('rl.deleted')); redirect('admin_rate_cards', ['_r' => uniqid()]); }
        case 'rl_status': { rl_set_status((int)$_POST['id'], (string)($_POST['status'] ?? 'active')); flash('good', t('rl.saved')); redirect('admin_rate_cards', ['_r' => uniqid()]); }
        case 'ob_line_save': { $o = of_load((int)$_POST['id']); if (!$o) throw new RuntimeException(t('err.notfound')); ob_line_save_from_post($o); flash('good', t('ob.line_added')); redirect('offer', ['id' => (int)$o['id'], '_r' => uniqid()], '#ob-lines'); }
        case 'ob_lines_bulk': {
            $o = of_load((int)$_POST['id']); if (!$o) throw new RuntimeException(t('err.notfound'));
            if (!empty($_POST['del'])) { ob_line_delete((int)$o['id'], (int)$_POST['del']); flash('good', t('ob.line_deleted')); }
            elseif (!empty($_POST['move']) && preg_match('/^([ud]):(\d+)$/', (string)$_POST['move'], $m)) ob_line_move((int)$o['id'], (int)$m[2], $m[1] === 'u' ? -1 : 1);
            else { ob_lines_bulk_from_post($o); flash('good', t('ob.lines_saved')); }
            redirect('offer', ['id' => (int)$o['id'], '_r' => uniqid()], '#ob-lines');
        }
        case 'ob_blocks_save': { $o = of_load((int)$_POST['id']); if (!$o || !ob_can()) throw new RuntimeException(t('err.noaccess')); foreach ((array)($_POST['bb'] ?? []) as $k => $body) ob_block_save((int)$o['id'], (string)$k, (string)($_POST['bt'][$k] ?? ''), (string)$body); flash('good', t('ob.texts_saved')); redirect('offer', ['id' => (int)$o['id'], '_r' => uniqid()], '#ob-texts'); }
        case 'ob_blocks_reset': { $o = of_load((int)$_POST['id']); if (!$o || !ob_can()) throw new RuntimeException(t('err.noaccess')); ob_blocks_init($o, true); flash('good', t('ob.texts_reset')); redirect('offer', ['id' => (int)$o['id'], '_r' => uniqid()], '#ob-texts'); }
        case 'ob_issue': { $r = ob_issue((int)$_POST['id']); flash('good', t('ob.issued', ['r' => $r['revision']])); redirect('offer', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#ob-doc'); }
        case 'ot_save':      { $tid = ot_save_from_post(); flash('good', t('et.saved')); redirect('admin_offer_texts', ['id' => $tid, '_r' => uniqid()], '#form'); }
        case 'ot_delete':    { ot_delete((int)$_POST['id']); flash('good', t('et.deleted')); redirect('admin_offer_texts', ['_r' => uniqid()]); }
        case 'ot_duplicate': { $tid = ot_duplicate((int)$_POST['id']); flash('good', t('et.duplicated')); redirect('admin_offer_texts', ['id' => $tid, '_r' => uniqid()], '#form'); }
        case 'et_save':      { $id = et_save_from_post(); flash('good', t('et.saved')); redirect('admin_email_templates', ['id' => $id, '_r' => uniqid()], '#form'); }
        case 'et_delete':    { et_delete((int)$_POST['id']); flash('good', t('et.deleted')); redirect('admin_email_templates', ['_r' => uniqid()]); }
        case 'et_duplicate': { $id = et_duplicate((int)$_POST['id']); flash('good', t('et.duplicated')); redirect('admin_email_templates', ['id' => $id, '_r' => uniqid()], '#form'); }
        case 'et_test': {                                      // a test .eml to the administrator's own address with sample data
            require_cp_admin(); $k = in_array($_POST['kind'] ?? '', ET_KINDS, true) ? $_POST['kind'] : 'offer'; $loc = isset(LOCALES[(string)($_POST['locale'] ?? '')]) ? (string)$_POST['locale'] : locale();
            $tx = et_render($k, et_sample($loc) + ['branch_id' => (int)($_POST['branch_id'] ?? 0) ?: null, 'customer_id' => (int)($_POST['customer_id'] ?? 0) ?: null]);
            $me = (string)(current_user()['email'] ?? ''); $eml = em_build($me !== '' ? [$me] : ['test@example.com'], [], $tx['subject'] ?: t('et.k_' . $k), $tx['body'], []);
            header('Content-Type: message/rfc822'); header('Content-Length: ' . strlen($eml)); header('Content-Disposition: attachment; filename="template-test.eml"'); echo $eml; exit;
        }           // R31-008: .eml draft with attachments
        case 'doc_issue': {                                   // archive a portal-generated document as the next revision
            require_staff();
            if (!ar_has()) throw new RuntimeException(t('ar.need_r20'));
            $kind = (string)($_POST['kind'] ?? ''); $files = []; $docNo = ''; $pid = 0; $sid = null; $backPage = 'dashboard'; $backParams = [];
            $docxMime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            if ($kind === 'weekly') {
                $vid = (int)$_POST['vessel']; $pid0 = (int)($_POST['project'] ?? 0) ?: null; $ws = (string)($_POST['week'] ?? date('Y-m-d'));
                $W = weekly_data($vid, $pid0, $ws); if (!$W) throw new RuntimeException(t('err.notfound'));
                $docNo = wk_doc_no($W['vessel'], $W['only_project'], $W['week_start']);
                $pid = (int)($W['only_project']['id'] ?? 0) ?: ar_vessel_project($vid);
                $files = [[wk_render_pdf($W), 'pdf', 'application/pdf'], [wk_render_docx($W), 'docx', $docxMime]];
                $backPage = 'weekly_vessel'; $backParams = array_filter(['vessel' => $vid, 'project' => $pid0, 'week' => $ws]);
            } elseif ($kind === 'monthly') {
                $sid = (int)$_POST['id']; $D = rp_sp_data($sid); if (!$D) throw new RuntimeException(t('err.notfound'));
                $docNo = $D['docNo']; $pid = (int)$D['sp']['project_id'];
                $files = [[rp_sp_docx($D), 'docx', $docxMime]];
                $backPage = 'subproject'; $backParams = ['id' => $sid, 'tab' => 'reports'];
            } elseif ($kind === 'vessel') {
                $vid = (int)$_POST['id']; $pid0 = (int)($_POST['project'] ?? 0) ?: null;
                $h = vessel_history($vid, $pid0); if (!$h) throw new RuntimeException(t('err.notfound'));
                $docNo = vh_doc_no($h['vessel'], $h['only_project'] ?? null);
                $pid = (int)($h['only_project']['id'] ?? 0) ?: ar_vessel_project($vid);
                $files = [[vh_render_pdf($h), 'pdf', 'application/pdf'], [vh_render_docx($h), 'docx', $docxMime]];
                $backPage = 'vessel'; $backParams = array_filter(['id' => $vid, 'project' => $pid0]);
            } elseif ($kind === 'timesheet') {
                $pid = (int)($_POST['project'] ?? 0); if (!$pid) throw new RuntimeException(t('ar.need_project'));
                $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['from'] ?? '')) ? $_POST['from'] : null;
                $to   = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['to'] ?? '')) ? $_POST['to'] : null;
                $st   = array_values(array_intersect((array)($_POST['st'] ?? ['approved', 'invoiced']), ts_statuses())) ?: ['approved', 'invoiced'];
                $d = ts_report_data($pid, null, $st, $from, $to); $docNo = $d['doc_no'];
                $files = [[ts_render_pdf($d), 'pdf', 'application/pdf'], [ts_render_docx($d), 'docx', $docxMime]];
                $backPage = 'manhours'; $backParams = array_filter(['project' => $pid, 'from' => $from, 'to' => $to]);
            } else throw new RuntimeException(t('err.notfound'));
            if (!$pid) throw new RuntimeException(t('ar.need_project'));
            $r = ar_issue($docNo, $pid, $sid, $files, $kind === 'timesheet' ? 'internal' : 'external', $docNo);
            flash('good', t('ar.issued_ok', ['doc' => $docNo, 'rev' => $r['revision']]));
            redirect($backPage, $backParams + ['_r' => uniqid()]);
        }
        case 'ar_reorganise': {
            $dry = !empty($_POST['dry']); $pid = (int)($_POST['project_id'] ?? 0) ?: null;
            $res = ar_reorganise($dry, $pid);
            $_SESSION['cp_ar_result'] = $res;
            flash($dry ? 'warn' : 'good', t($dry ? 'ar.dry_done' : 'ar.apply_done', ['n' => $res['moved'], 'c' => $res['checked']]));
            redirect($pid ? 'project_edit' : 'admin_about', ($pid ? ['id' => $pid] : ['tab' => 'archive']) + ['_r' => uniqid()]);
        }
        case 'ar_undo': {
            $r = ar_undo(preg_replace('/[^0-9a-f]/', '', (string)($_POST['run_id'] ?? '')));
            flash('good', t('ar.undo_done', ['n' => $r['undone'], 'e' => $r['errors']]));
            redirect('admin_about', ['tab' => 'archive', '_r' => uniqid()]);
        }
        case 'ar_verify': {
            $_SESSION['cp_ar_verify'] = ar_verify(!empty($_POST['full']));
            flash('good', t('ar.verify_done'));
            redirect('admin_about', ['tab' => 'archive', '_r' => uniqid()]);
        }
        case 'ar_manifest': {
            require_cp_admin();
            $n = ar_manifest();
            flash('good', t('ar.manifest_done', ['n' => $n]));
            redirect('admin_about', ['tab' => 'archive', '_r' => uniqid()]);
        }
        /* ------------------------- customers + invoicing details (R19) */
        case 'cu_customer_save': {
            $id = (int)($_POST['id'] ?? 0); $new = !$id;
            $id = cu_save_from_post($id);
            flash('good', t($new ? 'cu.created' : 'cu.updated'));
            redirect('admin_customers', array_filter(['q' => $_GET['q'] ?? null, 'status' => $_GET['status'] ?? null]) + ['edit' => $id, '_r' => uniqid()]);
        }
        case 'cu_customer_delete': {
            cu_delete((int)$_POST['id']);
            flash('good', t('cu.deleted'));
            redirect('admin_customers', ['_r' => uniqid()]);
        }
        case 'iv_save': {
            $r = iv_save_from_post();
            flash('good', t(!empty($_POST['id']) ? 'iv.updated' : 'iv.created'));
            iv_back($page, $r['owner_type'], $r['owner_id']);
        }
        case 'iv_default': {
            iv_set_default((int)$_POST['id']);
            $r = iv_load((int)$_POST['id']);
            flash('good', t('iv.updated'));
            iv_back($page, $r['owner_type'], (int)$r['owner_id']);
        }
        case 'iv_delete': {
            $r = iv_delete((int)$_POST['id']);
            flash('good', t('iv.deleted'));
            iv_back($page, $r['owner_type'], (int)$r['owner_id']);
        }
        /* --------------------------------------- contacts (R17) */
        case 'ct_save': {
            require_staff();
            $id = (int)($_POST['id'] ?? 0); $new = !$id;
            $id = ct_save_from_post($id);
            flash('good', t($new ? 'ct.created' : 'ct.updated'));
            redirect('contact', ['id' => $id, '_r' => uniqid()]);
        }
        case 'ct_delete': {
            require_cp_admin();
            ct_delete((int)$_POST['id']);
            flash('good', t('ct.deleted'));
            redirect('contacts', ['_r' => uniqid()]);
        }
        case 'ct_note': {
            require_staff();
            $cid = (int)$_POST['id']; $body = trim((string)($_POST['body'] ?? ''));
            if ($body === '') throw new RuntimeException(t('ct.need_note'));
            $ctNid = ct_note_add($cid, $body);
            if (function_exists('mt_notify')) mt_notify($body, (string)(scalar('SELECT full_name FROM contacts WHERE id = ?', [$cid]) ?: ('#' . $cid)), url('contact', ['id' => $cid]) . '#notes', 'ctn:' . $ctNid);
            flash('good', t('ct.note_added'));
            redirect('contact', ['id' => $cid, '_r' => uniqid()]);
        }
        case 'ct_note_delete': {
            require_staff();
            ct_note_delete((int)$_POST['note_id']);
            redirect('contact', ['id' => (int)$_POST['id'], '_r' => uniqid()]);
        }
        case 'ct_vessel_unlink': {
            require_staff();
            q('DELETE FROM contact_vessels WHERE contact_id = ? AND vessel_id = ?', [(int)$_POST['id'], (int)$_POST['vessel_id']]);
            redirect('contact', ['id' => (int)$_POST['id'], '_r' => uniqid()]);
        }
        case 'ofs_save': { of_status_save(); flash('good', t('ofs.saved')); redirect('admin_offer_statuses', ['_r' => uniqid()]); }
        case 'ofs_delete': { of_status_delete((string)($_POST['skey'] ?? '')); flash('good', t('ofs.deleted')); redirect('admin_offer_statuses', ['_r' => uniqid()]); }
        case 'co_save': {
            $id = (int)($_POST['id'] ?? 0); $new = !$id;
            $id = co_save_from_post($id);
            flash('good', t($new ? 'co.created' : 'co.updated'));
            redirect('companies', ['_r' => uniqid()]);
        }
        case 'co_delete': {
            co_delete((int)$_POST['id']);
            flash('good', t('co.deleted'));
            redirect('companies', ['_r' => uniqid()]);
        }
        case 'ct_bulk': {
            require_staff();
            $f = ct_filters_from_request($_POST);
            $ids = !empty($_POST['all']) ? ct_ids($f) : array_map('intval', (array)($_POST['ids'] ?? []));
            $op = (string)($_POST['op'] ?? '');
            if (!$ids) throw new RuntimeException(t('ct.bulk_none'));
            if ($op === 'delete') { require_cp_admin(); $n = ct_delete_many($ids); flash('good', t('ct.bulk_deleted', ['n' => $n])); }
            elseif ($op === 'deactivate') { $n = ct_set_many($ids, 'is_active', '0'); flash('good', t('ct.bulk_done', ['n' => $n])); }
            elseif ($op === 'activate') { $n = ct_set_many($ids, 'is_active', '1'); flash('good', t('ct.bulk_done', ['n' => $n])); }
            elseif (str_starts_with($op, 'sub+')) { $n = ct_set_many($ids, 'add_sub', substr($op, 4)); flash('good', t('ct.bulk_done', ['n' => $n])); }
            elseif (str_starts_with($op, 'sub-')) { $n = ct_set_many($ids, 'remove_sub', substr($op, 4)); flash('good', t('ct.bulk_done', ['n' => $n])); }
            else throw new RuntimeException(t('err.notfound'));
            redirect('contacts', array_filter($f, fn($v) => $v !== '' && $v !== null && !is_array($v)) + ['_r' => uniqid()]);
        }
        case 'ct_vessel_link': {
            require_staff();
            $cid = (int)$_POST['id']; $vid = (int)($_POST['vessel_id'] ?? 0);
            if ($vid && scalar('SELECT COUNT(*) FROM vessels WHERE id = ?', [$vid])) q('INSERT INTO contact_vessels (contact_id, vessel_id, role, is_primary) VALUES (?,?,?,0) ON DUPLICATE KEY UPDATE role = VALUES(role)', [$cid, $vid, mb_substr(trim((string)($_POST['role'] ?? '')), 0, 80) ?: null]);
            redirect('contact', ['id' => $cid, '_r' => uniqid()]);
        }
        case 'wk_notes': {
            require_staff();
            $pid = (int)$_POST['project_id']; $ws = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['week_start'] ?? '')) ? $_POST['week_start'] : null;
            if (!$ws || !one('SELECT id FROM projects WHERE id = ?', [$pid])) throw new RuntimeException(t('err.notfound'));
            $wu = current_user();
            q('INSERT INTO weekly_notes (project_id, week_start, delays, remarks, author_id, author) VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE delays = VALUES(delays), remarks = VALUES(remarks), author_id = VALUES(author_id), author = VALUES(author)',
              [$pid, $ws, mb_substr(trim((string)($_POST['delays'] ?? '')), 0, 4000) ?: null, mb_substr(trim((string)($_POST['remarks'] ?? '')), 0, 4000) ?: null, (int)$wu['id'], $wu['full_name']]);
            flash('good', t('wv.notes_saved'));
            redirect('weekly_vessel', ['vessel' => (int)($_POST['vessel_id'] ?? 0), 'project' => $pid, 'week' => $ws, '_r' => uniqid()]);
        }
        case 'tk_project': {
            require_staff();
            $id = (int)$_POST['id'];
            tk_set_project($id, (int)($_POST['project_id'] ?? 0) ?: null, (int)($_POST['subproject_id'] ?? 0) ?: null, isset($_POST['vessel_id']) ? ((int)$_POST['vessel_id'] ?: null) : false);
            flash('good', t('tk.updated'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        case 'tk_assign': {
            require_staff();
            $id = (int)$_POST['id'];
            tk_assign($id, (int)($_POST['assigned_to'] ?? 0) ?: null);
            flash('good', t('tk.updated'));
            redirect('ticket', ['id' => $id, '_r' => uniqid()]);
        }
        case 'oa_comment': {
            require_login();
            $id = (int)$_POST['id'];
            if (!oa_load($id)) throw new RuntimeException(t('err.notfound'));
            $body = mb_substr(trim((string)($_POST['body'] ?? '')), 0, 4000);
            if ($body === '') throw new RuntimeException(t('oa.comment_req'));
            oa_comment($id, 'comment', $body, (int)($_POST['parent_id'] ?? 0) ?: null);
            if (function_exists('mt_notify')) { $oaCid = (int) db()->lastInsertId(); $oaR = oa_load($id); mt_notify($body, (string)($oaR['doc_no'] ?? ('#' . $id)), url('approval', ['id' => $id]) . '#comments', 'oa:' . $id . ':c' . $oaCid); }
            flash('good', t('oa.commented'));
            redirect('approval', ['id' => $id, '_r' => uniqid()]);
        }
        case 'oa_comment_delete': {
            require_login();
            $id = (int)$_POST['id'];
            if (!oa_load($id)) throw new RuntimeException(t('err.notfound'));
            oa_comment_delete($id, (int)$_POST['cid']);
            flash('good', t('cm.deleted_ok'));
            redirect('approval', ['id' => $id, '_r' => uniqid()]);
        }
        case 'oa_resubmit': {                // Golden A/S: a new revision after a rejection
            require_staff();
            $id = (int)$_POST['id']; $r = oa_load($id);
            if (!$r) throw new RuntimeException(t('err.notfound'));
            $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], (int)$r['project_id'], $r['subproject_id'] ? (int)$r['subproject_id'] : null, 'owner_doc', 'external', $r['title'], null, null, ['doc_no' => $r['doc_no'], 'revision' => trim((string)($_POST['revision'] ?? '')) ?: $r['revision']]) : null;
            oa_resubmit($id, (string)($_POST['revision'] ?? ''), $fileId, (string)($_POST['note'] ?? ''));
            flash('good', t('oa.resubmitted'));
            redirect('approval', ['id' => $id, '_r' => uniqid()]);
        }

        /* -------------------------------------------------- test reports */
        case 'tst_save': {
            require_staff();
            $pid = (int)$_POST['project_id']; $sid = (int)$_POST['subproject_id'];
            $kind = in_array($_POST['kind'] ?? '', ['FAT','HAT','SAT'], true) ? $_POST['kind'] : 'FAT';
            $fileId = !empty($_FILES['file']['name']) ? cp_store_file($_FILES['file'], $pid, $sid, strtolower($kind), 'external', $str('title', 200)) : null;
            q('INSERT INTO test_reports (subproject_id, kind, title, planned_at, performed_at, status, remarks, file_id) VALUES (?,?,?,?,?,?,?,?)',
              [$sid, $kind, $str('title', 200), $date('planned_at'), $date('performed_at'),
               in_array($_POST['status'] ?? '', ['planned','performed','passed','punch_open','failed','closed'], true) ? $_POST['status'] : 'planned',
               $str('remarks', 2000), $fileId]);
            flash('good', t('tst.saved'));
            $back();
        }

        /* ------------------------------------------- weekly notes + photos */
        case 'note_save': {
            $u = require_staff();
            $pid = (int)$_POST['project_id']; $sid = (int)$_POST['subproject_id'];
            $d = $date('note_date') ?: date('Y-m-d');
            $vis = ($_POST['visibility'] ?? '') === 'internal' ? 'internal' : 'external';
            q('INSERT INTO sp_notes (subproject_id, author_id, note_date, iso_week, visibility, body) VALUES (?,?,?,?,?,?)',
              [$sid, (int)$u['id'], $d, iso_week($d), $vis, $str('body', 8000)]);
            $noteId = (int) db()->lastInsertId();
            if (function_exists('mt_notify') && ($spR = load_subproject($sid))) mt_notify($str('body', 8000), trim(($spR['project_code'] ?: '') . ' › ' . ($spR['code'] ?: ''), ' ›'), url('subproject', ['id' => $sid, 'tab' => 'weekly']), 'spn:' . $noteId);
            $n = 0;
            foreach (files_list('photos') as $f) { if (cp_store_file($f, $pid, $sid, 'photo', $vis, null, $noteId)) $n++; }
            flash('good', t('wk.saved', ['n' => $n]));
            $back();
        }

        /* ------------------------------------------- comments / chat */
        case 'cm_post': {
            $u = require_login();
            $sid = (int)$_POST['subproject_id'];
            if (!load_subproject($sid)) { http_response_code(403); exit(t('err.noaccess')); }
            $vis = (is_staff() && ($_POST['visibility'] ?? '') === 'internal') ? 'internal' : 'external';
            $body = $str('body', 4000);
            if ($body) {
                $dup = scalar('SELECT COUNT(*) FROM sp_comments WHERE subproject_id = ? AND author_id = ? AND body = ? AND created_at >= NOW() - INTERVAL 20 SECOND', [$sid, (int)$u['id'], $body]);
                $parent = (int)($_POST['parent_id'] ?? 0) ?: null;
                if ($parent && !scalar('SELECT COUNT(*) FROM sp_comments WHERE id = ? AND subproject_id = ?', [$parent, $sid])) $parent = null;
                if (!$dup) { q('INSERT INTO sp_comments (subproject_id, author_id, visibility, parent_id, body) VALUES (?,?,?,?,?)', [$sid, (int)$u['id'], $vis, $parent, $body]); $cmId = (int) db()->lastInsertId();
                    if (function_exists('mt_notify') && ($spR = load_subproject($sid))) mt_notify($body, trim(($spR['project_code'] ?: '') . ' › ' . ($spR['code'] ?: ''), ' ›'), url('subproject', ['id' => $sid, 'tab' => 'comments']), 'spc:' . $cmId); }
            }
            $back();
        }
        case 'cm_delete': {                 // one's own comment; administrators may remove any
            $u = require_login();
            $sid = (int)$_POST['subproject_id']; $cid = (int)$_POST['cid'];
            if (!load_subproject($sid)) { http_response_code(403); exit(t('err.noaccess')); }
            $c = one('SELECT * FROM sp_comments WHERE id = ? AND subproject_id = ?', [$cid, $sid]);
            if (!$c || ((int)$c['author_id'] !== (int)$u['id'] && !is_cp_admin())) throw new RuntimeException(t('cm.not_yours'));
            $hasReplies = scalar('SELECT COUNT(*) FROM sp_comments WHERE parent_id = ?', [$cid]);
            if ($hasReplies && thread_has_replies()) q('UPDATE sp_comments SET deleted_at = NOW(), body = "" WHERE id = ?', [$cid]);
            else q('DELETE FROM sp_comments WHERE id = ?', [$cid]);
            flash('good', t('cm.deleted_ok'));
            $back();
        }

        /* ------------------------------------------------------- files */
        case 'file_upload': {
            require_staff();
            $pid = (int)$_POST['project_id']; $sid = (int)($_POST['subproject_id'] ?? 0) ?: null;
            $kind = (string)($_POST['kind'] ?? ''); if (!isset(fk_choices()[$kind])) $kind = 'other';                   // R22-007 configurable categories
            $vis  = ($_POST['visibility'] ?? '') === 'internal' ? 'internal' : 'external';
            $n = 0;
            foreach (files_list('files') as $f) { if (cp_store_file($f, $pid, $sid, $kind, $vis, $str('title', 200))) $n++; }
            flash('good', t('fl.saved', ['n' => $n]));
            $back();
        }

        /* --------------------------------------------- GA overlays */
        case 'ga_add': {
            require_staff();
            $pid = (int)$_POST['project_id'];
            $fileId = cp_store_file($_FILES['image'] ?? [], $pid, null, 'ga', 'external', $str('title', 160));
            if (!$fileId) throw new RuntimeException(t('err.upload'));
            q('INSERT INTO ga_drawings (project_id, kind, title, deck, file_id) VALUES (?,?,?,?,?)',
              [$pid, ($_POST['kind'] ?? '') === 'panels' ? 'panels' : 'routing', $str('title', 160), $str('deck', 60), $fileId]);
            redirect('overlay', ['project' => $pid, 'd' => (int) db()->lastInsertId()]);
        }
        case 'ga_import': {
            require_staff();
            require_once __DIR__ . '/xlsx.php';
            $did = (int)$_POST['drawing_id'];
            $dr  = one('SELECT * FROM ga_drawings WHERE id = ?', [$did]);
            if (!$dr || empty($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $n = pm_import_ga_items($_FILES['xlsx']['tmp_name'], $did, $dr['kind'], !empty($_POST['replace']));
            flash('good', t('ga.imported', ['n' => $n]));
            $back();
        }

        /* --------------------------------------------- admin: systems */
        case 'sys_save': {
            require_cp_admin();
            $id = (int)($_POST['id'] ?? 0);
            $code = mb_substr(trim((string)($_POST['code'] ?? '')), 0, 10) ?: null;
            $abbr = mb_strtoupper(mb_substr(trim((string)($_POST['abbr'] ?? '')), 0, 12)) ?: null;
            $sort = (int)($_POST['sort_order'] ?? 0) ?: (is_numeric($code ?? '') ? (int)$code : 0);
            try {
                if ($id && !empty($_POST['toggle'])) {
                    q('UPDATE vessel_systems SET is_active = 1 - is_active WHERE id = ?', [$id]);
                } elseif ($id) {
                    if (sys_has_codes()) q('UPDATE vessel_systems SET code = ?, abbr = ?, name = ?, sort_order = ? WHERE id = ?', [$code, $abbr, $str('name', 120), $sort, $id]);
                    else                 q('UPDATE vessel_systems SET name = ?, sort_order = ? WHERE id = ?', [$str('name', 120), $sort, $id]);
                } else {
                    if (sys_has_codes()) {
                        // an abbreviation belongs to exactly one system; a known description re-activates that row
                        $byName = one('SELECT id, abbr FROM vessel_systems WHERE LOWER(name) = LOWER(?) LIMIT 1', [$str('name', 120)]);
                        if ($abbr !== null && scalar('SELECT COUNT(*) FROM vessel_systems WHERE abbr = ? AND id <> ?', [$abbr, (int)($byName['id'] ?? 0)])) throw new RuntimeException(t('sys.dup', ['v' => $abbr]));
                        q('INSERT INTO vessel_systems (code, abbr, name, sort_order) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE code = COALESCE(VALUES(code), code), abbr = COALESCE(VALUES(abbr), abbr), sort_order = VALUES(sort_order), is_active = 1',
                          [$code, $abbr, $str('name', 120), $sort]);
                    } else {
                        q('INSERT INTO vessel_systems (name, sort_order) VALUES (?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1', [$str('name', 120), $sort]);
                    }
                }
            } catch (PDOException $e) {
                if ((int)$e->errorInfo[1] === 1062) throw new RuntimeException(t('sys.dup', ['v' => $abbr ?: $str('name', 120)]));
                throw $e;
            }
            flash('good', t('sys.saved'));
            redirect('admin_systems', ['_r' => uniqid()]);
        }

        /* -------------------------------------------- vessels (staff) */
        case 'vessel_save': {
            require_staff();
            $id   = (int)($_POST['id'] ?? 0);
            $name = (string)$str('name', 120);
            if ($name === '') throw new RuntimeException(t('v.need_name'));
            $cid  = (int)($_POST['customer_id'] ?? 0) ?: null;
            if (function_exists('vm_has') && vm_has()) {                                          // R30-001: canonical name, one record per hull
                $name = vm_canonical($name);
                $other = vm_find($name, $str('imo', 16) ?: null);
                if ($other && $other !== $id) throw new RuntimeException(t('vm.dup_hull', ['n' => (string) scalar('SELECT name FROM vessels WHERE id = ?', [$other])]));
            } elseif (scalar('SELECT COUNT(*) FROM vessels WHERE LOWER(name) = LOWER(?) AND (customer_id <=> ?) AND id <> ?', [$name, $cid, $id])) throw new RuntimeException(t('v.dup'));
            $vals = [$name, $cid, $str('imo', 16), $str('yard', 120), (int)!empty($_POST['is_active'])];
            if ($id) {
                if (!one('SELECT id FROM vessels WHERE id = ?', [$id])) throw new RuntimeException(t('err.notfound'));
                q('UPDATE vessels SET name = ?, customer_id = ?, imo = ?, yard = ?, is_active = ? WHERE id = ?', [...$vals, $id]);
            } else {
                q('INSERT INTO vessels (name, customer_id, imo, yard, is_active) VALUES (?,?,?,?,?)', $vals); $id = (int) db()->lastInsertId();
            }
            if (function_exists('vm_has') && vm_has()) {
                q('UPDATE vessels SET former_names = ? WHERE id = ?', [$str('former_names', 255) ?: null, $id]);
                foreach (preg_split('/[\r\n;]+/', (string)($_POST['aliases'] ?? '')) as $al) if (trim($al) !== '') vm_alias_add($id, trim($al), 'manual');
            }
            flash('good', t('v.saved'));
            redirect('vessels', ['_r' => uniqid()]);
        }
        case 'vessel_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $v = one('SELECT * FROM vessels WHERE id = ?', [$id]);
            if (!$v) throw new RuntimeException(t('err.notfound'));
            db()->beginTransaction();
            q('UPDATE projects  SET vessel_id = NULL WHERE vessel_id = ?', [$id]);     // projects stay, unlinked
            q('UPDATE documents SET vessel_id = NULL WHERE vessel_id = ?', [$id]);
            q('DELETE FROM vessels WHERE id = ?', [$id]);
            db()->commit();
            flash('good', t('v.deleted', ['name' => $v['name']]));
            redirect('vessels', ['_r' => uniqid()]);
        }

        /* ------------------------------------- deletion (admin only) */
        case 'project_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $p = one('SELECT * FROM projects WHERE id = ?', [$id]);
            if (!$p) throw new RuntimeException(t('err.notfound'));
            pm_delete_project($id);
            flash('good', t('pr.deleted', ['code' => $p['project_code'] ?: $p['name']]));
            redirect('projects', ['_r' => uniqid()]);
        }
        case 'subproject_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $s = one('SELECT * FROM subprojects WHERE id = ?', [$id]);
            if (!$s) throw new RuntimeException(t('err.notfound'));
            pm_delete_subproject($id);
            flash('good', t('sp.deleted', ['code' => $s['code'] ?: $s['name']]));
            redirect('projects', ['_r' => uniqid()]);
        }

        /* ------------------------------- field service reports (R7) */
        case 'fsr_save': {
            $su = require_staff();
            $id = (int)($_POST['id'] ?? 0) ?: null;
            if (!empty($_POST['del_photo'])) {                                   // remove one photo, stay on the form
                $ph = one("SELECT id FROM sp_files WHERE id = ? AND kind = 'fsr_photo' AND fsr_id = ?", [(int)$_POST['del_photo'], $id]);
                if ($ph) cp_delete_file((int)$ph['id']);
                $id = fsr_save_from_post($id);
                redirect('fsr_edit', ['id' => $id, '_r' => uniqid()]);
            }
            $id = fsr_save_from_post($id);
            if (($_POST['mode'] ?? 'draft') !== 'submit') { flash('good', t('fsr.saved')); redirect('fsr_edit', ['id' => $id, '_r' => uniqid()]); }
            // submit: needs at least one personnel line and one work block
            $r = fsr_load($id);
            if (!$r['works'] || !$r['lines']) { flash('bad', t('fsr.incomplete')); redirect('fsr_edit', ['id' => $id, '_r' => uniqid()]); }
            q('UPDATE fsr_reports SET status = "submitted", version = version + 1, submitted_by = ?, submitted_name = ?, submitted_at = NOW(),
                                      accepted_by = NULL, accepted_name = NULL, accepted_at = NULL, accepted_ip = NULL, customer_remark = NULL WHERE id = ?',
              [(int)$su['id'], $su['full_name'], $id]);
            fsr_event($id, 'submitted');
            fsr_generate_documents($id);
            if (function_exists('eq_fsr_sync')) eq_fsr_sync($id);   // R27-001: the service history of the units follows the report
            if (function_exists('iss_fsr_sync')) iss_fsr_sync($id);  // R29-001: open issues of the task blocks → register
            flash('good', t('fsr.submitted'));
            redirect('fsr', ['id' => $id, '_r' => uniqid()]);
        }
        /* ------------------------------- equipment register (R27-001) */
        /* ---------------------------------------------- R41-001: production orders */
        case 'pd_save': { $oid = pd_save_from_post(); flash('good', t('pd.saved')); redirect('production_order', ['id' => $oid, '_r' => uniqid()]); }
        case 'pd_status': { require_staff(); $oid = (int)$_POST['id']; pd_set_status($oid, (string)($_POST['status'] ?? ''), trim((string)($_POST['note'] ?? ''))); flash('good', t('pd.status_saved')); redirect('production_order', ['id' => $oid, '_r' => uniqid()]); }
        case 'pd_unit': { require_staff(); $uid = (int)$_POST['unit_id']; pd_unit_update($uid); $oid = (int) scalar('SELECT order_id FROM production_units WHERE id = ?', [$uid]); flash('good', t('pd.unit_saved')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#units'); }
        case 'pd_unit_remove': { require_staff(); $uid = (int)$_POST['unit_id']; $oid = (int) scalar('SELECT order_id FROM production_units WHERE id = ?', [$uid]); pd_unit_remove($uid); flash('good', t('pd.unit_removed')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#units'); }
        case 'pd_units_add': { require_staff(); $oid = (int)$_POST['id']; $n = pd_units_add_from_post($oid); flash('good', t('pd.units_added', ['n' => $n])); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#units'); }
        case 'pd_file_add': { require_staff(); $oid = (int)$_POST['id']; $n = pd_file_add($oid); flash('good', t('pd.files_added', ['n' => $n])); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#docs'); }
        case 'pd_file_link': { require_staff(); $oid = (int)$_POST['id']; pd_file_link($oid); flash('good', t('pd.files_added', ['n' => 1])); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#docs'); }
        case 'pd_file_remove': { require_staff(); $oid = pd_file_remove((int)$_POST['pf_id']); flash('good', t('pd.file_removed')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#docs'); }
        case 'ps_save': { require_staff(); $oid = (int)$_POST['id']; ps_save_from_post(); flash('good', t('ps.saved')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#subs'); }
        case 'ps_delete': { require_staff(); $oid = ps_delete((int)$_POST['ps_id']); flash('good', t('ps.deleted')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#subs'); }
        case 'pd_stages': { require_staff(); $oid = (int)$_POST['id']; pd_stages_save_from_post($oid); flash('good', t('pd.stages_saved')); redirect('production_order', ['id' => $oid, '_r' => uniqid()], '#stages'); }
        case 'sp_stage_lock': { require_staff(); $sid = (int)$_POST['id']; $sp0 = load_subproject($sid); if (!$sp0) throw new RuntimeException(t('err.notfound')); pd_stage_lock($sid, (string)($_POST['stage'] ?? ''), !empty($_POST['unlock'])); flash('good', t(!empty($_POST['unlock']) ? 'pd.st_unlocked' : 'pd.st_locked')); redirect('subproject', ['id' => $sid, '_r' => uniqid()], '#stage-editor'); }
        case 'num_save': { num_cfg_save_from_post(); flash('good', t('num.saved')); redirect('settings', ['tab' => 'numbering', '_r' => uniqid()]); }
        case 'spc_save': { $sid = (int)$_POST['subproject_id']; $n = spc_save_from_post($sid); flash('good', t('spc.saved', ['n' => $n])); redirect('subproject', ['id' => $sid, 'tab' => 'contacts', '_r' => uniqid()]); }
        /* ---------------------------------------------- R44: classes, terms, proformas, invoices */
        case 'oc_save': { oc_save_from_post(); flash('good', t('oc.saved')); redirect('settings', ['tab' => 'offer_classes', '_r' => uniqid()]); }
        case 'oc_delete': { oc_delete((int)$_POST['id']); flash('good', t('oc.deleted')); redirect('settings', ['tab' => 'offer_classes', '_r' => uniqid()]); }
        case 'pt_default': { pt_default_save_from_post(); flash('good', t('pt.default_saved')); redirect('settings', ['tab' => 'offer_classes', '_r' => uniqid()]); }
        case 'oc_convert': { require_staff(); $oid = (int)$_POST['id']; $nid = oc_convert($oid, (int)($_POST['subproject_id'] ?? 0)); flash('good', t('oc.converted')); redirect('offer', ['id' => $nid, '_r' => uniqid()]); }
        case 'pt_save': { require_staff(); $oid = (int)$_POST['id']; pt_save_from_post($oid); flash('good', t('pt.saved')); redirect('offer', ['id' => $oid, '_r' => uniqid()], '#proformas'); }
        case 'pi_create': { require_staff(); $oid = (int)$_POST['id']; $pid = pi_create($oid, (int)($_POST['term_n'] ?? 0) ?: null); flash('good', t('pi.created')); redirect('proforma', ['id' => $pid, '_r' => uniqid()]); }
        case 'pi_save': { require_staff(); $pid = (int)$_POST['id']; pi_save_from_post($pid); flash('good', t('pi.saved')); redirect('proforma', ['id' => $pid, '_r' => uniqid()]); }
        case 'pi_status': { require_staff(); $pid = (int)$_POST['id']; pi_set_status($pid, (string)($_POST['status'] ?? '')); flash('good', t('pi.status_saved')); redirect('proforma', ['id' => $pid, '_r' => uniqid()]); }
        case 'pinv_save': { require_staff(); $pid = (int)$_POST['id']; pinv_save_from_post($pid); flash('good', t('pi.inv_saved')); redirect('proforma', ['id' => $pid, '_r' => uniqid()], '#invoices'); }
        case 'pinv_delete': { require_staff(); $pid = pinv_delete((int)$_POST['inv_id']); flash('good', t('pi.inv_deleted')); redirect('proforma', ['id' => $pid, '_r' => uniqid()], '#invoices'); }
        case 'pi_delete': { $oid = pi_delete((int)$_POST['id']); flash('good', t('pi.deleted')); redirect('offer', ['id' => $oid, '_r' => uniqid()], '#proformas'); }
        case 'pd_delete': { pd_delete((int)$_POST['id']); flash('good', t('pd.deleted')); redirect('production', ['_r' => uniqid()]); }
        case 'eq_save': { $id = eq_save((int)($_POST['id'] ?? 0) ?: null); flash('good', t('eq.saved')); redirect('equipment_view', ['id' => $id, '_r' => uniqid()]); }
        case 'eq_delete': { $e = eq_get((int)$_POST['id']); if (!$e) throw new RuntimeException(t('err.notfound')); eq_delete((int)$e['id']); flash('good', t('eq.deleted')); redirect('equipment', ['_r' => uniqid()]); }
        case 'eq_hist_save': { $eq = (int)$_POST['eq']; eq_hist_save($eq); flash('good', t('eq.record_saved')); redirect('equipment_view', ['id' => $eq, '_r' => uniqid()]); }
        case 'eq_contact_add': { $eq = (int)$_POST['eq']; $n = eq_contact_add_many($eq, (array)($_POST['contact_ids'] ?? [(int)($_POST['contact_id'] ?? 0)]), (string)($_POST['role'] ?? '')); flash('good', t('eq.contacts_added', ['n' => $n])); redirect('equipment_view', ['id' => $eq, '_r' => uniqid()]); }
        case 'eq_contact_remove': { $eq = (int)$_POST['eq']; eq_contact_remove($eq, (int)($_POST['contact_id'] ?? 0)); flash('good', t('eq.contact_removed')); redirect('equipment_view', ['id' => $eq, '_r' => uniqid()]); }
        case 'eq_hist_delete': { eq_hist_delete((int)$_POST['id']); flash('good', t('eq.record_deleted')); redirect('equipment_view', ['id' => (int)$_POST['eq'], '_r' => uniqid()]); }
        case 'eq_import': {
            require_staff();
            if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $dry = !empty($_POST['dry']); $res = eq_import($_FILES['xlsx']['tmp_name'], (string)($_POST['maker'] ?? ''), $dry);
            $res['kind'] = 'equipment'; $res['dry'] = $dry; $_SESSION['cp_import_result'] = $res;
            redirect('equipment', ['_r' => uniqid()]);
        }
        /* ------------------------------- planning board (R26-001) */
        case 'pl_assign': {
            $uids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['user_id'] ?? []))))); if (!$uids) throw new RuntimeException(t('pl.need_people'));   // R59-004: several engineers at once
            $n = 0; foreach ($uids as $plUid) $n += pl_assign(['user_id' => $plUid, 'from' => (string)$_POST['from'], 'to' => (string)($_POST['to'] ?? ''), 'kind' => (string)$_POST['kind'], 'leave_type_id' => (int)($_POST['leave_type_id'] ?? 0), 'reason' => (string)($_POST['reason'] ?? ''), 'project_id' => (int)($_POST['project_id'] ?? 0), 'subproject_id' => (int)($_POST['subproject_id'] ?? 0), 'car_id' => (int)($_POST['car_id'] ?? 0), 'note' => (string)($_POST['note'] ?? ''), 'all_days' => !empty($_POST['all_days']), 'replace' => !empty($_POST['replace'])]);
            flash($n ? 'good' : 'warn', t($n ? 'pl.saved' : 'pl.saved_none', ['n' => $n]) . (count($uids) > 1 ? ' · ' . count($uids) . ' ' . t('pl.people') : '')); redirect('planning', array_filter(['m' => substr((string)$_POST['from'], 0, 7), 'disc' => $_GET['disc'] ?? '', 'vessel' => $_GET['vessel'] ?? '', 'me' => $_GET['me'] ?? '', '_r' => uniqid()]));
        }
        case 'pl_clear':  { $n = 0; foreach (array_unique(array_filter(array_map('intval', (array)($_POST['user_id'] ?? [])))) as $plUid) $n += pl_clear($plUid, (string)$_POST['from'], (string)($_POST['to'] ?: $_POST['from'])); flash('good', t('pl.cleared', ['n' => $n])); redirect('planning', array_filter(['m' => substr((string)$_POST['from'], 0, 7), 'disc' => $_GET['disc'] ?? '', 'vessel' => $_GET['vessel'] ?? '', 'me' => $_GET['me'] ?? '', '_r' => uniqid()])); }
        case 'pl_delete_work': { pl_delete_work((int)$_POST['work_id']); flash('good', t('pl.work_deleted')); redirect('planning', array_filter(['m' => $_GET['m'] ?? '', 'disc' => $_GET['disc'] ?? '', '_r' => uniqid()])); }
        case 'pl_delete': { pl_delete((int)$_POST['id']); flash('good', t('pl.deleted')); redirect('planning', array_filter(['m' => $_GET['m'] ?? '', 'disc' => $_GET['disc'] ?? '', 'vessel' => $_GET['vessel'] ?? '', 'me' => $_GET['me'] ?? '', '_r' => uniqid()])); }
        case 'pl_discs_save': { pl_disciplines_save((array)($_POST['pl_disc'] ?? [])); flash('good', t('pl.discs_saved')); redirect('admin_disciplines', ['_r' => uniqid()]); }
        case 'pl_car_save':   { pl_car_save(); flash('good', t('pl.car_saved')); redirect('planning', array_filter(['m' => $_GET['m'] ?? '', '_r' => uniqid()])); }
        case 'pl_car_delete': { pl_car_delete((int)$_POST['id']); flash('good', t('pl.car_deleted')); redirect('planning', array_filter(['m' => $_GET['m'] ?? '', '_r' => uniqid()])); }
        case 'pl_import': {
            require_staff();
            if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $dry = !empty($_POST['dry']); $res = pl_import($_FILES['xlsx']['tmp_name'], $dry, !empty($_POST['replace']));
            $res['kind'] = 'planning'; $res['dry'] = $dry; $_SESSION['cp_import_result'] = $res;
            redirect('planning', array_filter(['m' => $_GET['m'] ?? '', '_r' => uniqid()]));
        }
        /* ------------------------------- sub-project teams (R25-001) */
        case 'sp_team_save': {
            require_staff(); $sid = (int)$_POST['id'];
            tm_save($sid, (array)($_POST['team'] ?? []));
            flash('good', t('tm.saved')); redirect('subproject', ['id' => $sid, 'tab' => 'process', '_r' => uniqid()]);
        }
        case 'wf_opacity_save': { require_cp_admin(); cp_setting_set('wf_photo_opacity', (string) max(10, min(100, (int)($_POST['opacity'] ?? 60)))); flash('good', t('wf.opacity_saved')); redirect('admin_systems', ['_r' => uniqid()], '#wf-opacity'); }
        case 'tm_strict': { require_cp_admin(); cp_setting_set('tm_strict', !empty($_POST['strict']) ? '1' : '0'); flash('good', t('tm.mode_saved')); redirect('admin_users', ['_r' => uniqid()]); }
        /* ------------------------------- branches (R24-001) */
        case 'br_save':   { br_save(); flash('good', t('br.saved')); redirect('admin_branches', ['_r' => uniqid()]); }
        case 'br_delete': { br_delete((int)$_POST['id']); flash('good', t('br.deleted')); redirect('admin_branches', ['_r' => uniqid()]); }
        /* ------------------------------- inspection reports (R23-003) */
        case 'isr_save': {
            require_staff();
            $id = (int)($_POST['id'] ?? 0) ?: null;
            if (!empty($_POST['del_file'])) {                                    // remove one attachment, stay on the form
                $ph = one("SELECT id FROM sp_files WHERE id = ? AND kind = 'isr_file' AND isr_id = ?", [(int)$_POST['del_file'], $id]);
                if ($ph) cp_delete_file((int)$ph['id']);
                $id = isr_save_from_post($id);
                redirect('isr_edit', ['id' => $id, '_r' => uniqid()]);
            }
            $id = isr_save_from_post($id);
            if (($_POST['mode'] ?? 'draft') !== 'submit') { flash('good', t('isr.saved')); redirect('isr_edit', ['id' => $id, '_r' => uniqid()]); }
            try { isr_submit($id); } catch (RuntimeException $e) { flash('bad', $e->getMessage()); redirect('isr_edit', ['id' => $id, '_r' => uniqid()]); }
            flash('good', t('isr.submitted'));
            redirect('isr', ['id' => $id, '_r' => uniqid()]);
        }
        case 'isr_submit': { $id = (int)$_POST['id']; isr_submit($id); flash('good', t('isr.submitted')); redirect('isr', ['id' => $id, '_r' => uniqid()]); }
        case 'isr_issue':  { $id = (int)$_POST['id']; isr_issue($id); flash('good', t('isr.issued')); redirect('isr', ['id' => $id, '_r' => uniqid()]); }
        case 'isr_reopen': { $id = (int)$_POST['id']; isr_reopen($id); redirect('isr_edit', ['id' => $id, '_r' => uniqid()]); }
        case 'isr_delete': {
            $id = (int)$_POST['id']; $r = isr_load($id); if (!$r) throw new RuntimeException(t('err.notfound'));
            isr_delete($id); flash('good', t('isr.deleted'));
            redirect('isr_list', ['project' => (int)$r['project_id'], '_r' => uniqid()]);
        }
        case 'fsr_accept':
        case 'fsr_dispute': {
            $au = require_login();
            $id = (int)$_POST['id'];
            $r = fsr_load($id);
            if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('fsr.not_pending'));
            $isCustomer = !is_staff() && (int)$au['customer_id'] === (int)$r['customer_id'];
            if (!$isCustomer && !is_cp_admin()) { http_response_code(403); exit(t('err.noaccess')); }
            $remark = mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 2000);
            $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]);
            if ($action === 'fsr_dispute') {
                if ($remark === '') throw new RuntimeException(t('fsr.remark_needed'));
                q('UPDATE fsr_reports SET status = "disputed", customer_remark = ? WHERE id = ?', [$remark, $id]);
                fsr_event($id, 'disputed', $remark);
                fsr_generate_documents($id);
                flash('warn', t('fsr.disputed_done'));
                redirect('fsr', ['id' => $id, '_r' => uniqid()]);
            }
            $name = $au['full_name'] . (!$isCustomer ? ' (' . t('fsr.on_behalf') . ')' : '');
            q('UPDATE fsr_reports SET status = "accepted", accepted_by = ?, accepted_name = ?, accepted_at = NOW(), accepted_ip = ?, customer_remark = ? WHERE id = ?',
              [(int)$au['id'], $name, $ip ?: null, $remark ?: null, $id]);
            fsr_event($id, 'accepted', $remark ?: null);
            fsr_generate_documents($id);
            flash('good', t('fsr.accepted_done'));
            redirect('fsr', ['id' => $id, '_r' => uniqid()]);
        }
        case 'fsr_reopen': {
            require_staff();
            $id = (int)$_POST['id'];
            $r = fsr_load($id);
            if (!$r || $r['status'] !== 'submitted') throw new RuntimeException(t('fsr.not_pending'));
            q('UPDATE fsr_reports SET status = "draft" WHERE id = ?', [$id]);
            fsr_event($id, 'reopened');
            if (function_exists('iss_fsr_sync')) iss_fsr_sync($id); if (function_exists('eq_fsr_sync')) eq_fsr_sync($id);                      // R27-001: draft again → history entries withdrawn
            redirect('fsr_edit', ['id' => $id, '_r' => uniqid()]);
        }
        case 'fsr_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $r = fsr_load($id);
            if (!$r) throw new RuntimeException(t('err.notfound'));
            foreach (all('SELECT id FROM sp_files WHERE fsr_id = ?', [$id]) as $f) cp_delete_file((int)$f['id']);
            cp_delete_file($r['docx_file_id'] ? (int)$r['docx_file_id'] : null); cp_delete_file($r['pdf_file_id'] ? (int)$r['pdf_file_id'] : null);
            q('DELETE FROM fsr_reports WHERE id = ?', [$id]);                                   // lines, works, events cascade
            flash('good', t('fsr.deleted'));
            redirect('fsr_list', ['project' => (int)$r['project_id'], '_r' => uniqid()]);
        }
        case 'tk_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $tk = one('SELECT * FROM tickets WHERE id = ?', [$id]); if (!$tk) throw new RuntimeException(t('err.notfound'));
            tk_delete_one($id);
            flash('good', t('tk.deleted', ['no' => $tk['ticket_no']]));
            redirect('tickets', ['_r' => uniqid()]);
        }
        case 'tk_bulk': {                                       // GAS-CP-R37-002: close / re-open / delete the selected tickets
            require_staff();
            $op = (string)($_POST['op'] ?? '');
            $ids = !empty($_POST['all']) ? array_map('intval', explode(',', (string)($_POST['all_ids'] ?? ''))) : array_map('intval', (array)($_POST['ids'] ?? []));
            $ids = array_values(array_unique(array_filter($ids)));
            if (!$ids) throw new RuntimeException(t('tk.bulk_none'));
            $n = 0;
            if ($op === 'delete') { require_cp_admin(); foreach ($ids as $id) if (scalar('SELECT id FROM tickets WHERE id = ?', [$id])) { tk_delete_one($id); $n++; } flash('good', t('tk.bulk_deleted', ['n' => $n])); }
            elseif ($op === 'close' || $op === 'open') { $st = $op === 'close' ? 'closed' : 'open'; foreach ($ids as $id) { $t = one('SELECT id, status FROM tickets WHERE id = ?', [$id]); if (!$t || $t['status'] === $st) continue; tk_set_status($id, $st, ''); $n++; } flash('good', t($op === 'close' ? 'tk.bulk_closed' : 'tk.bulk_opened', ['n' => $n])); }
            else throw new RuntimeException(t('err.notfound'));
            $keep = []; foreach (['view', 'q', 'vessel', 'project', 'status', 'priority', 'assigned', 'page'] as $k) if (($_POST[$k] ?? '') !== '') $keep[$k] = (string)$_POST[$k];
            redirect('tickets', $keep + ['_r' => uniqid()]);
        }
        case 'jo_delete': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            $j = one('SELECT * FROM job_orders WHERE id = ?', [$id]); if (!$j) throw new RuntimeException(t('err.notfound'));
            q('DELETE FROM job_orders WHERE id = ?', [$id]);                                    // events cascade; service reports keep (job_order_id → NULL)
            flash('good', t('jo.deleted', ['no' => $j['jo_no']]));
            redirect('jo_list', ['project' => (int)$j['project_id'], '_r' => uniqid()]);
        }

        /* ------------------------------------------- timesheets (R11) */
        case 'ts_save': {
            $u = require_staff();
            if (!ts_has()) throw new RuntimeException(t('ts.need_r11'));
            $id = ts_save_from_post((int)($_POST['id'] ?? 0), $u);
            $rc = (float)($GLOBALS['ts_reclass'] ?? 0); $rcNote = $rc > 0 ? ' ' . t('wc.reclassified', ['n' => ts_fmt($rc)]) : '';
            if (($_POST['mode'] ?? 'draft') === 'submit') { ts_submit($id); flash('good', t('ts.submitted_ok') . $rcNote); redirect('timesheet', ['id' => $id, '_r' => uniqid()]); }
            flash($rc > 0 ? 'warn' : 'good', t('fsr.saved') . $rcNote);
            redirect('timesheet_edit', ['id' => $id, '_r' => uniqid()]);
        }
        case 'ts_submit':   { require_staff(); $id = (int)$_POST['id']; ts_submit($id);   flash('good', t('ts.submitted_ok')); redirect('timesheet', ['id' => $id, '_r' => uniqid()]); }
        case 'ts_withdraw': { require_staff(); $id = (int)$_POST['id']; ts_withdraw($id); flash('good', t('ts.withdrawn_ok')); redirect('timesheet', ['id' => $id, '_r' => uniqid()]); }
        case 'ts_delete':   { require_staff(); ts_delete((int)$_POST['id']); flash('good', t('ts.deleted_ok')); redirect('timesheets', ['_r' => uniqid()]); }
        case 'ts_decide': {
            require_staff();
            $id = (int)$_POST['id'];
            ts_decide($id, ($_POST['decision'] ?? '') === 'approve', (string)($_POST['note'] ?? ''));
            flash('good', ($_POST['decision'] ?? '') === 'approve' ? t('ts.approved_ok') : t('ts.rejected_ok'));
            redirect('timesheet', ['id' => $id, '_r' => uniqid()]);
        }
        case 'ts_invoice': {
            if (!fin_visible()) throw new RuntimeException(t('err.noaccess'));
            require_staff();
            $ids = array_map('intval', (array)($_POST['ids'] ?? []));
            $n = ts_invoice($ids, (string)($_POST['invoice_ref'] ?? ''));
            flash($n ? 'good' : 'warn', t('ts.invoiced_n', ['n' => $n]));
            if (($_POST['back'] ?? '') === 'timesheet' && count($ids) === 1) redirect('timesheet', ['id' => $ids[0], '_r' => uniqid()]);
            redirect('timesheets', ['view' => 'invoicing', 'status' => 'invoiced', '_r' => uniqid()]);
        }
        case 'ts_uninvoice': { require_cp_admin(); $id = (int)$_POST['id']; ts_uninvoice($id); flash('good', t('ts.uninvoiced_ok')); redirect('timesheet', ['id' => $id, '_r' => uniqid()]); }

        /* -------------------------------------- admin: disciplines (R11) */
        case 'disc_save': {
            require_cp_admin();
            if (!ts_has()) throw new RuntimeException(t('ts.need_r11'));
            $id = (int)($_POST['id'] ?? 0);
            $mgr = (int)($_POST['manager_user_id'] ?? 0) ?: null;
            if ($mgr && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin')", [$mgr])) $mgr = null;
            try {
                if ($id && !empty($_POST['toggle'])) q('UPDATE disciplines SET is_active = 1 - is_active WHERE id = ?', [$id]);
                elseif ($id) { q('UPDATE disciplines SET name = ?, manager_user_id = ?, sort_order = ? WHERE id = ?', [$str('name', 80), $mgr, (int)($_POST['sort_order'] ?? 0), $id]); if (isset($_POST['rate_normal']) && function_exists('wc_has') && wc_has()) { $rt = fn(string $k) => ($_POST[$k] ?? '') === '' ? null : round((float) str_replace(',', '.', (string)$_POST[$k]), 2); q('UPDATE disciplines SET rate_normal = ?, rate_overtime = ?, rate_holiday = ? WHERE id = ?', [$rt('rate_normal'), $rt('rate_overtime'), $rt('rate_holiday'), $id]); } if (isset($_POST['rate_card']) && function_exists('acc_has') && acc_has()) q('UPDATE disciplines SET rate_card = ? WHERE id = ?', [mb_substr(trim((string)$_POST['rate_card']), 0, 12) ?: null, $id]); }
                else q('INSERT INTO disciplines (name, manager_user_id, sort_order) VALUES (?,?,?) ON DUPLICATE KEY UPDATE manager_user_id = VALUES(manager_user_id), sort_order = VALUES(sort_order), is_active = 1', [$str('name', 80), $mgr, (int)($_POST['sort_order'] ?? 0)]);
            } catch (PDOException $e) { if ((int)$e->errorInfo[1] === 1062) throw new RuntimeException(t('sys.dup', ['v' => $str('name', 80)])); throw $e; }
            flash('good', t('disc.saved'));
            redirect('admin_disciplines', ['_r' => uniqid()]);
        }

        /* ------------------------------------------ work orders (R8) */
        case 'jo_save': {
            require_login();
            $id = jo_save_from_post((int)($_POST['id'] ?? 0) ?: null);
            if (($_POST['mode'] ?? 'draft') === 'submit') { jo_submit($id); flash('good', is_staff() ? t('jo.submitted_golden') : t('jo.submitted_customer')); redirect('jo', ['id' => $id, '_r' => uniqid()]); }
            flash('good', t('fsr.saved'));
            redirect('jo_edit', ['id' => $id, '_r' => uniqid()]);
        }
        case 'jo_submit': {
            require_login();
            $id = (int)$_POST['id']; if (!jo_load($id)) throw new RuntimeException(t('err.notfound'));
            jo_submit($id);
            flash('good', is_staff() ? t('jo.submitted_golden') : t('jo.submitted_customer'));
            redirect('jo', ['id' => $id, '_r' => uniqid()]);
        }
        case 'jo_approve':
        case 'jo_reject': {
            $au = require_login();
            $id = (int)$_POST['id']; $j = jo_load($id);
            if (!$j || $j['status'] !== 'pending_approval') throw new RuntimeException(t('jo.not_pending'));
            $isCustomer = !is_staff() && (int)$au['customer_id'] === (int)$j['customer_id'];
            if (!$isCustomer && !is_cp_admin()) { http_response_code(403); exit(t('err.noaccess')); }
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 2000);
            $name = $au['full_name'] . (!$isCustomer ? ' (' . t('fsr.on_behalf') . ')' : '');
            if ($action === 'jo_reject') {
                if ($note === '') throw new RuntimeException(t('jo.note_needed'));
                q('UPDATE job_orders SET status = "rejected", approved_by = ?, approved_name = ?, approved_at = NOW(), approved_ip = ?, approval_note = ? WHERE id = ?', [(int)$au['id'], $name, jo_ip(), $note, $id]);
                jo_event($id, 'rejected', $note); flash('warn', t('jo.rejected_done'));
            } else {
                q('UPDATE job_orders SET status = "approved", approved_by = ?, approved_name = ?, approved_at = NOW(), approved_ip = ?, approval_note = ? WHERE id = ?', [(int)$au['id'], $name, jo_ip(), $note ?: null, $id]);
                jo_event($id, 'approved', $note ?: null); flash('good', t('jo.approved_done'));
            }
            redirect('jo', ['id' => $id, '_r' => uniqid()]);
        }
        case 'jo_start':
        case 'jo_done':
        case 'jo_cancel':
        case 'jo_reopen': {
            require_staff();
            $id = (int)$_POST['id']; $j = jo_load($id);
            if (!$j) throw new RuntimeException(t('err.notfound'));
            $ok = ['jo_start' => ['approved'], 'jo_done' => ['in_progress'], 'jo_cancel' => ['approved', 'in_progress', 'pending_approval', 'draft'], 'jo_reopen' => ['rejected', 'pending_approval', 'cancelled']][$action];
            if (!in_array($j['status'], $ok, true)) throw new RuntimeException(t('jo.bad_transition'));
            switch ($action) {
                case 'jo_start':  q('UPDATE job_orders SET status = "in_progress", started_at = NOW() WHERE id = ?', [$id]); jo_event($id, 'started'); break;
                case 'jo_done':   q('UPDATE job_orders SET status = "done", done_at = NOW() WHERE id = ?', [$id]); jo_event($id, 'done'); break;
                case 'jo_cancel': q('UPDATE job_orders SET status = "cancelled" WHERE id = ?', [$id]); jo_event($id, 'cancelled'); break;
                case 'jo_reopen': q('UPDATE job_orders SET status = "draft", approved_by = NULL, approved_name = NULL, approved_at = NULL, approved_ip = NULL, approval_note = NULL WHERE id = ?', [$id]); jo_event($id, 'reopened'); break;
            }
            redirect('jo', ['id' => $id, '_r' => uniqid()]);
        }

        /* ---------------------------------- bulk import (staff, Excel) */
        case 'vessel_import':
        case 'proj_import':
        case 'of_import':
        case 'ct_import':
        case 'co_import':
        case 'cu_import':
        case 'au_import':
        case 'sys_import': {
            require_staff(); if ($action === 'of_import') of_require();
            require_once __DIR__ . '/xlsx.php';
            if (empty($_FILES['xlsx']['tmp_name']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) throw new RuntimeException(t('err.upload'));
            $dry = !empty($_POST['dry']);
            $res = match ($action) {
                'vessel_import' => pm_import_vessels($_FILES['xlsx']['tmp_name'], $dry),
                'sys_import'    => pm_import_systems($_FILES['xlsx']['tmp_name'], $dry),
                'of_import'     => of_import($_FILES['xlsx']['tmp_name'], $dry),
                'ct_import'     => ct_import($_FILES['xlsx']['tmp_name'], $dry),
                'au_import'     => au_import($_FILES['xlsx']['tmp_name'], $dry),
                'co_import'     => co_import($_FILES['xlsx']['tmp_name'], $dry),
                'cu_import'     => cu_import($_FILES['xlsx']['tmp_name'], $dry),
                default         => pm_import_projects($_FILES['xlsx']['tmp_name'], $dry),
            };
            $res['kind'] = ['vessel_import' => 'vessels', 'sys_import' => 'systems', 'of_import' => 'offers', 'ct_import' => 'contacts', 'au_import' => 'users', 'co_import' => 'companies', 'cu_import' => 'customers'][$action] ?? 'projects';
            $res['dry']  = $dry;
            $res['file'] = mb_substr((string)$_FILES['xlsx']['name'], 0, 120);
            $_SESSION['cp_import_result'] = $res;
            flash($dry ? 'warn' : 'good', t('im.done', ['n' => $res['rows']]));
            redirect('import', ['_r' => uniqid()]);
        }

        /* --------------------------------------- admin: Golden roles */
        case 'sr_save': {
            require_cp_admin();
            $id = (int)($_POST['id'] ?? 0);
            if ($id && !empty($_POST['toggle'])) {
                q('UPDATE staff_roles SET is_active = 1 - is_active WHERE id = ?', [$id]);
            } elseif ($id) {
                q('UPDATE staff_roles SET name = ?, sort_order = ? WHERE id = ?', [$str('name', 80), (int)($_POST['sort_order'] ?? 0), $id]);
                try { q('UPDATE staff_roles SET see_financials = ? WHERE id = ?', [(int)!empty($_POST['see_financials']), $id]); } catch (Throwable $e) {}
                try { if (isset($_POST['scope'])) q('UPDATE staff_roles SET scope = ? WHERE id = ?', [in_array($_POST['scope'], ['full', 'hr', 'employee'], true) ? $_POST['scope'] : 'full', $id]); } catch (Throwable $e) {}   // R51-001
                try { q('UPDATE staff_roles SET can_offer = ? WHERE id = ?', [(int)!empty($_POST['can_offer']), $id]); } catch (Throwable $e) {}
            } else {
                q('INSERT INTO staff_roles (name, sort_order) VALUES (?, ?) ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), is_active = 1',
                  [$str('name', 80), (int)($_POST['sort_order'] ?? 0)]);
                try { q('UPDATE staff_roles SET see_financials = ? WHERE name = ?', [(int)!empty($_POST['see_financials']), $str('name', 80)]); } catch (Throwable $e) {}
                try { q('UPDATE staff_roles SET can_offer = ? WHERE name = ?', [(int)!empty($_POST['can_offer']), $str('name', 80)]); } catch (Throwable $e) {}
            }
            flash('good', t('sr.saved'));
            redirect('admin_roles', ['_r' => uniqid()]);
        }
        case 'cu_update': {
            $me = require_cp_admin();
            $id = (int)$_POST['id'];
            $x = one('SELECT * FROM customer_users WHERE id = ?', [$id]);
            if (!$x) throw new RuntimeException(t('err.notfound'));
            $role = in_array($_POST['role'] ?? '', ['customer','staff','admin'], true) ? $_POST['role'] : $x['role'];
            if ($id === (int)$me['id']) $role = 'admin';                       // never demote yourself
            $cid  = $role === 'customer' ? ((int)($_POST['customer_id'] ?? 0) ?: null) : null;
            if ($role === 'customer' && !$cid) throw new RuntimeException(t('au.need_customer'));
            $srole = $role === 'customer' ? null : ((int)($_POST['staff_role_id'] ?? 0) ?: null);
            $pass = (string)($_POST['password'] ?? '');
            if ($pass !== '' && strlen($pass) < 10) throw new RuntimeException(t('a.pass_len'));
            q('UPDATE customer_users SET full_name = ?, role = ?, customer_id = ?, staff_role_id = ?, locale = ? WHERE id = ?',
              [$str('full_name', 120) ?: $x['full_name'], $role, $cid, $srole,
               locale_valid($_POST['locale'] ?? null) ? $_POST['locale'] : $x['locale'], $id]);
            cu_save_discipline($id, $role);
            if (array_key_exists('twofa_required', $x)) q('UPDATE customer_users SET twofa_required = ? WHERE id = ?', [(int)!empty($_POST['twofa_required']), $id]);
            if (array_key_exists('can_works', $x)) q('UPDATE customer_users SET can_works = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_works']), $id]);   // R36-005
            if (array_key_exists('rate_card', $x) && isset($_POST['rate_card'])) q('UPDATE customer_users SET rate_card = ? WHERE id = ?', [$role === 'customer' ? null : (mb_substr(trim((string)$_POST['rate_card']), 0, 12) ?: null), $id]);   // R39-001
            if (array_key_exists('is_production', $x)) q('UPDATE customer_users SET is_production = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['is_production']), $id]);   // R41-001
            if (array_key_exists('can_sales', $x)) q('UPDATE customer_users SET can_sales = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_sales']), $id]);   // R44-003
            if (array_key_exists('can_cashflow', $x)) q('UPDATE customer_users SET can_cashflow = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_cashflow']), $id]);   // R45-001
            if (array_key_exists('can_management', $x)) q('UPDATE customer_users SET can_management = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_management']), $id]);   // R47-001
            if (array_key_exists('can_ai', $x)) q('UPDATE customer_users SET can_ai = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_ai']), $id]);   // R50-001
            if (array_key_exists('can_accounting', $x)) q('UPDATE customer_users SET can_accounting = ?, is_accounting = ? WHERE id = ?', [$role === 'customer' ? 0 : (int)!empty($_POST['can_accounting']), $role === 'customer' ? 0 : (int)!empty($_POST['is_accounting']), $id]);   // R38-001
            if ($pass !== '') q('UPDATE customer_users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($pass, PASSWORD_DEFAULT), $id]);
            q('UPDATE projects SET project_manager = ? WHERE pm_user_id = ?', [$str('full_name', 120) ?: $x['full_name'], $id]);   // keep the denormalised name in step
            flash('good', t('au.updated'));
            redirect('admin_users', au_keep() + ['_r' => uniqid()]);
        }

        /* ---------------------------------------------- admin: users */
        case 'cu_save': {
            require_cp_admin();
            $role = in_array($_POST['role'] ?? '', ['customer','staff','admin'], true) ? $_POST['role'] : 'customer';
            $cid  = (int)($_POST['customer_id'] ?? 0) ?: null;
            if ($role === 'customer' && !$cid) throw new RuntimeException(t('au.need_customer'));
            $pass = (string)($_POST['password'] ?? '');
            if (strlen($pass) < 10) throw new RuntimeException(t('a.pass_len'));
            if (scalar('SELECT COUNT(*) FROM customer_users WHERE email = ?', [strtolower((string)$str('email', 190))])) throw new RuntimeException(t('au.dup_email'));
            if (function_exists('lic_check_users')) lic_check_users();                                    // R22-001 account limit of the licence
            q('INSERT INTO customer_users (customer_id, role, staff_role_id, full_name, email, password_hash, locale) VALUES (?,?,?,?,?,?,?)',
              [$cid, $role, $role === 'customer' ? null : ((int)($_POST['staff_role_id'] ?? 0) ?: null), $str('full_name', 120),
               strtolower((string)$str('email', 190)), password_hash($pass, PASSWORD_DEFAULT),
               locale_valid($_POST['locale'] ?? null) ? $_POST['locale'] : 'en']);
            $newUid = (int) db()->lastInsertId();
            try { q('UPDATE customer_users SET twofa_required = ? WHERE id = ?', [(int)!empty($_POST['twofa_required']), $newUid]); } catch (Throwable $e) {}
            cu_save_discipline($newUid, $role);
            flash('good', t('au.created'));
            redirect('admin_users', au_keep() + ['_r' => uniqid()]);
        }
        case 'mob_revoke': { mob_revoke((int)($_POST['dev'] ?? 0)); flash('good', t('mob.revoked_done')); redirect('admin_users', ['_r' => uniqid()]); }   // R53-002
        case 'mob_settings_save': { require_cp_admin(); if (!lic_has()) throw new RuntimeException(t('err.noaccess')); $v = trim((string)($_POST['min_version'] ?? '')); if (!preg_match('/^\d+\.\d+\.\d+$/', $v)) throw new RuntimeException(t('mob.bad_version')); cp_setting_set('api_min_version_engineer', $v); flash('good', t('mob.saved')); redirect('admin_log', ['_r' => uniqid()], '#mob'); }
        case 'cu_revoke': {   // R49-001: sign a person out of every browser
            require_cp_admin(); $id = (int)($_POST['id'] ?? 0); if (!$id || !function_exists('sec_revoke_all')) throw new RuntimeException(t('err.notfound'));
            sec_revoke_all($id); $em = (string) scalar('SELECT email FROM customer_users WHERE id = ?', [$id]); login_log($id, $em, 'revoked');
            flash('good', t('sec.revoked_done')); redirect('admin_users', ['_r' => uniqid()]);
        }
        case 'sec_save': {   // R49-001: session policy
            require_cp_admin(); if (!function_exists('cp_setting_set') || !lic_has()) throw new RuntimeException(t('err.noaccess'));
            cp_setting_set('sec_idle_minutes', (string) max(5, min(480, (int)($_POST['idle'] ?? 30))));
            cp_setting_set('sec_max_hours', (string) max(1, min(72, (int)($_POST['max_hours'] ?? 10))));
            foreach (['sec_bind_ua' => 'bind_ua', 'sec_bind_ip' => 'bind_ip', 'sec_close_signout' => 'close_signout'] as $k => $f) cp_setting_set($k, !empty($_POST[$f]) ? '1' : '0');
            cp_setting_set('sec_csp', in_array($_POST['csp'] ?? '', ['on', 'report', 'off'], true) ? $_POST['csp'] : 'on');   // R53-001
            flash('good', t('sec.saved')); redirect('admin_log', ['_r' => uniqid()]);
        }
        case 'cu_twofa_reset': {
            require_cp_admin();
            $id = (int)$_POST['id'];
            q('UPDATE customer_users SET totp_secret = NULL, totp_enabled = 0 WHERE id = ?', [$id]);
            flash('good', t('au.twofa_reset_done'));
            redirect('admin_users', au_keep() + ['_r' => uniqid()]);
        }
        case 'cu_unlock': {                                   // GAS-CP-R36-001: clear a lock-out after too many wrong passwords
            require_cp_admin();
            $id = (int)$_POST['id'];
            q('UPDATE customer_users SET failed_logins = 0, locked_until = NULL WHERE id = ?', [$id]);
            if (function_exists('nf_mark_ref_read')) nf_mark_ref_read('sec:locked:' . $id);
            flash('good', t('au.unlocked'));
            redirect('admin_users', au_keep() + ['_r' => uniqid()]);
        }
        case 'cu_toggle': {
            $u = require_cp_admin();
            $id = (int)$_POST['id'];
            if ($id !== (int)$u['id']) q('UPDATE customer_users SET is_active = 1 - is_active WHERE id = ?', [$id]);
            redirect('admin_users', au_keep() + ['_r' => uniqid()]);
        }
    }
    return false;
}

/* =================================================== Excel importers */

/** Import the Golden A/S drawing-tracker workbook (sheets "Drawing
 *  Tracker" + "Principal Drawings"). Returns rows imported. */
function pm_import_tracker(string $path, int $pid, bool $replace): int {
    $systems = [];
    foreach (all('SELECT id, name FROM vessel_systems') as $s) $systems[mb_strtolower(trim($s['name']))] = (int)$s['id'];
    $statusMap = ['pending' => 'pending', 'received' => 'received', 'not required' => 'not_required', 'notrequired' => 'not_required'];
    if ($replace) q('DELETE FROM drawing_requests WHERE project_id = ?', [$pid]);

    $n = 0;
    foreach ([['Drawing Tracker', 'equipment'], ['Principal Drawings', 'principal']] as [$sheet, $kind]) {
        try { $rows = xlsx_rows($path, $sheet); } catch (Throwable $e) { continue; }
        // find the header row (the one containing "No.")
        $hdrIdx = null;
        foreach ($rows as $i => $r) { if (in_array('No.', $r, true) || in_array('No', $r, true)) { $hdrIdx = $i; break; } }
        if ($hdrIdx === null) continue;
        $map = xlsx_header_map($rows[$hdrIdx], [
            'no' => ['No.', 'No'], 'title' => ['Equipment / Requirement', 'Drawing / Study', 'Title'],
            'location' => 'Location', 'locstat' => 'Location Status', 'resp' => ['Supplier / Responsible', 'Responsible'],
            'sent' => ['Request Sent Date', 'Sent'], 'expected' => ['Expected Date', 'Expected'],
            'received' => ['Drawing Received Date', 'Received'], 'file' => 'File Name', 'status' => 'Status',
            'remarks' => 'Remarks', 'section' => ['Section', 'System'],
        ]);
        if ($map['title'] === null) continue;
        $g = fn(array $r, string $k) => $map[$k] !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
        foreach (array_slice($rows, $hdrIdx + 1) as $r) {
            $title = $g($r, 'title');
            if ($title === '' || !is_numeric($g($r, 'no'))) continue;      // skips section header rows
            $section = $g($r, 'section');
            $sysId = $systems[mb_strtolower($section)] ?? null;
            if ($section !== '' && $sysId === null) {                    // unknown system -> add it (requirement 4)
                q('INSERT INTO vessel_systems (name, sort_order) VALUES (?, 99)', [$section]);
                $sysId = (int) db()->lastInsertId(); $systems[mb_strtolower($section)] = $sysId;
            }
            $st = $statusMap[strtolower($g($r, 'status'))] ?? 'pending';
            $ls = $g($r, 'locstat'); $ls = in_array($ls, ['TBD','Confirmed','N/A'], true) ? $ls : 'TBD';
            q('INSERT INTO drawing_requests (project_id, system_id, kind, seq_no, title, location, location_status, responsible,
                                             request_sent, expected_at, received_at, file_name, status, remarks)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
              [$pid, $sysId, $kind, (int)$g($r, 'no'), mb_substr($title, 0, 200), mb_substr($g($r, 'location'), 0, 120) ?: null, $ls,
               mb_substr($g($r, 'resp'), 0, 80) ?: 'Shipyard', xlsx_date($g($r, 'sent')), xlsx_date($g($r, 'expected')), xlsx_date($g($r, 'received')),
               mb_substr($g($r, 'file'), 0, 255) ?: null, $st, mb_substr($g($r, 'remarks'), 0, 2000) ?: null]);
            $n++;
        }
    }
    return $n;
}

/** Import GA overlay items. Columns: layer, tag, label, x, y, x2, y2, status, remarks. */
function pm_import_ga_items(string $path, int $did, string $kind, bool $replace): int {
    $rows = xlsx_rows($path);
    if (!$rows) return 0;
    $map = xlsx_header_map($rows[0], ['layer' => 'layer', 'tag' => 'tag', 'label' => 'label', 'x' => 'x', 'y' => 'y',
                                      'x2' => 'x2', 'y2' => 'y2', 'status' => 'status', 'remarks' => 'remarks']);
    if ($map['tag'] === null || $map['x'] === null || $map['y'] === null) throw new RuntimeException(t('ga.bad_columns'));
    $allowed = $kind === 'panels' ? ['panel'] : ['tray', 'penetration', 'cable'];
    $valid = [];
    foreach (all('SELECT layer, status FROM ga_status_colors') as $c) $valid[$c['layer']][] = $c['status'];
    if ($replace) q('DELETE FROM ga_items WHERE drawing_id = ?', [$did]);
    $g = fn(array $r, string $k) => $map[$k] !== null ? trim((string)($r[$map[$k]] ?? '')) : '';
    $n = 0;
    foreach (array_slice($rows, 1) as $r) {
        $layer = strtolower($g($r, 'layer')) ?: $allowed[0];
        if (!in_array($layer, $allowed, true)) continue;
        $tag = $g($r, 'tag'); if ($tag === '' || !is_numeric($g($r, 'x')) || !is_numeric($g($r, 'y'))) continue;
        $status = strtolower($g($r, 'status')) ?: 'planned';
        if (!in_array($status, $valid[$layer] ?? [], true)) $status = 'planned';
        $x2 = is_numeric($g($r, 'x2')) ? (float)$g($r, 'x2') : null; $y2 = is_numeric($g($r, 'y2')) ? (float)$g($r, 'y2') : null;
        q('INSERT INTO ga_items (drawing_id, layer, tag, label, x, y, x2, y2, status, remarks) VALUES (?,?,?,?,?,?,?,?,?,?)
           ON DUPLICATE KEY UPDATE label = VALUES(label), x = VALUES(x), y = VALUES(y), x2 = VALUES(x2), y2 = VALUES(y2), status = VALUES(status), remarks = VALUES(remarks)',
          [$did, $layer, mb_substr($tag, 0, 60), mb_substr($g($r, 'label'), 0, 160) ?: null, (float)$g($r, 'x'), (float)$g($r, 'y'), $x2, $y2, $status, mb_substr($g($r, 'remarks'), 0, 255) ?: null]);
        $n++;
    }
    return $n;
}

/** Discipline + personal manager of a staff account (ignored until R11 has run). */
function cu_save_discipline(int $uid, string $role): void {
    if (!ts_has() || !isset($_POST['discipline_id'])) return;
    $did = $role === 'customer' ? null : ((int)$_POST['discipline_id'] ?: null);
    $mid = $role === 'customer' ? null : ((int)($_POST['manager_user_id'] ?? 0) ?: null);
    if ($mid === $uid) $mid = null;                                                  // not your own manager
    if ($mid && !scalar("SELECT COUNT(*) FROM customer_users WHERE id = ? AND role IN ('staff','admin')", [$mid])) $mid = null;
    q('UPDATE customer_users SET discipline_id = ?, manager_user_id = ? WHERE id = ?', [$did, $mid, $uid]);
    if (br_has() && isset($_POST['branch_id'])) q('UPDATE customer_users SET branch_id = ?, branch_scope = ? WHERE id = ?', [$role === 'customer' ? null : br_from_post(), $role === 'customer' ? 0 : (int)!empty($_POST['branch_scope']), $uid]);   // R24-001 / R24-003
    if (isset($_POST['work_calendar']) && function_exists('wc_has') && wc_has()) { $wcal = (string)$_POST['work_calendar']; q('UPDATE customer_users SET work_calendar = ? WHERE id = ?', [$role === 'customer' || !isset(wc_calendars()[$wcal]) ? null : $wcal, $uid]); }
}

/** Invoicing fields from the form (ignored until R10 has run). */
function pm_save_invoicing(int $subId): void {
    if (!inv_has() || !isset($_POST['invoicing'])) return;
    $st  = array_key_exists((string)$_POST['invoicing'], inv_states()) ? (string)$_POST['invoicing'] : 'none';
    $pct = max(0, min(100, (int)($_POST['invoiced_pct'] ?? 0)));
    if ($st === 'full') $pct = 100; elseif ($st === 'none') $pct = 0; elseif ($pct === 100) $st = 'full';
    q('UPDATE subprojects SET invoicing = ?, invoiced_pct = ? WHERE id = ?', [$st, $pct, $subId]);
}

/* ============================================ structure importers (R4) */

/** Run $fn inside a transaction; roll back when $dry, commit otherwise.
 *  Either way the summary the closure built is returned. */
function pm_import_tx(bool $dry, callable $fn): array {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $res = $fn();
        if ($dry) $pdo->rollBack(); else $pdo->commit();
        return $res;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** Case-insensitive lookup helpers shared by both importers. */
function pm_find_customer(string $name, array &$res): ?int {
    $name = trim($name);
    if ($name === '') return null;
    $id = scalar('SELECT id FROM customers WHERE LOWER(name) = LOWER(?) OR erp_code = ? ORDER BY is_active DESC LIMIT 1', [$name, $name]);
    if ($id) return (int)$id;
    q('INSERT INTO customers (name, is_active) VALUES (?, 1)', [mb_substr($name, 0, 160)]);
    $res['customers_new']++;
    return (int) db()->lastInsertId();
}
function pm_find_vessel(?int $cid, string $name, array &$res, bool $create = true): ?int {
    $name = trim($name);
    if ($name === '') return null;
    if (function_exists('vm_has') && vm_has()) {                                  // R30-001: one record per hull — IMO, alias or normalised name, whatever the customer
        $id = vm_find($name, null, $cid);
        if ($id) {
            $v = one('SELECT customer_id FROM vessels WHERE id = ?', [$id]);
            if ($cid && !$v['customer_id']) { q('UPDATE vessels SET customer_id = ? WHERE id = ?', [$cid, $id]); $res['vessels_upd'] = ($res['vessels_upd'] ?? 0) + 1; }
            elseif ($cid && (int)$v['customer_id'] !== $cid) { vm_related_set($id, $cid, 'other', t('vm.from_import')); $res['vessels_related'] = ($res['vessels_related'] ?? 0) + 1; }
            if (vm_norm($name) !== '' && $name !== (string) scalar('SELECT name FROM vessels WHERE id = ?', [$id])) vm_alias_add($id, $name, 'import');
            return $id;
        }
    }
    // same customer first; then an unassigned vessel of that name (it gets the customer)
    $id = scalar('SELECT id FROM vessels WHERE (customer_id <=> ?) AND LOWER(name) = LOWER(?) LIMIT 1', [$cid, $name]);
    if (!$id && $cid) {
        $id = scalar('SELECT id FROM vessels WHERE customer_id IS NULL AND LOWER(name) = LOWER(?) LIMIT 1', [$name]);
        if ($id) { q('UPDATE vessels SET customer_id = ? WHERE id = ?', [$cid, $id]); $res['vessels_upd'] = ($res['vessels_upd'] ?? 0) + 1; }
    }
    if ($id) return (int)$id;
    if (!$create) return null;
    q('INSERT INTO vessels (customer_id, name, is_active) VALUES (?, ?, 1)', [$cid, mb_substr(function_exists('vm_canonical') ? vm_canonical($name) : $name, 0, 120)]);
    $res['vessels_new']++;
    return (int) db()->lastInsertId();
}
/** A system by abbreviation ("MSB"), drawing code ("10"), description, or
 *  the pick-list label "MSB — MAIN SWITCHBOARD (10)"; unknown names are
 *  added (without a code) so the import never loses a row. */
function pm_find_system(string $name, array &$res): ?int {
    $name = trim($name);
    if ($name === '') return null;
    if (sys_has_codes()) {
        $id = scalar('SELECT id FROM vessel_systems WHERE abbr = ? OR code = ? ORDER BY abbr = ? DESC LIMIT 1', [$name, $name, $name]);
        if ($id) return (int)$id;
        if (preg_match('/^\s*([A-Za-z0-9]{1,12})\s+[—–-]+\s+(.+?)(\s*\(([^)]*)\))?\s*$/u', $name, $m)) {   // "ABBR — Description (code)"
            $id = scalar('SELECT id FROM vessel_systems WHERE abbr = ? OR LOWER(name) = LOWER(?) OR code = ? ORDER BY abbr = ? DESC LIMIT 1', [$m[1], $m[2], $m[4] ?? '', $m[1]]);
            if ($id) return (int)$id;
            $name = $m[2];
        }
    }
    $id = scalar('SELECT id FROM vessel_systems WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);
    if ($id) return (int)$id;
    q('INSERT INTO vessel_systems (name, sort_order) VALUES (?, 999)', [mb_substr($name, 0, 120)]);
    $res['systems_new']++;
    return (int) db()->lastInsertId();
}

/** Vessel-systems workbook: Drawing Code | Abbreviation | Description
 *  (+ optional Order, Active). Matched by abbreviation, then drawing code,
 *  then description; filled cells overwrite, blank cells are left alone.
 *  The order defaults to the numeric drawing code. */
function pm_import_systems(string $path, bool $dry): array {
    if (!sys_has_codes()) throw new RuntimeException(t('sys.need_r9'));
    $rows = xlsx_rows($path);
    $res  = ['rows' => 0, 'systems_new' => 0, 'systems_upd' => 0, 'skipped' => []];
    $hdr  = pm_import_header($rows, ['name' => ['Description', 'System', 'Name', 'Vessel System', 'Title']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Drawing Code, Abbreviation, Description']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['code' => ['Drawing Code', 'Code', 'Drawing No', 'No', 'No.'], 'abbr' => ['Abbreviation', 'Abbr', 'Abbrev', 'Short'],
                                         'order' => ['Order', 'Sort', 'Sort Order'], 'active' => ['Active', 'Status']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';

    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $name = mb_substr($g($r, 'name'), 0, 120);
            $abbr = mb_strtoupper(mb_substr(preg_replace('/\s+/', '', $g($r, 'abbr')), 0, 12));
            $code = $g($r, 'code');
            if (is_numeric($code)) $code = (string)(int)$code;                    // 10.0 -> 10
            $code = mb_substr($code, 0, 10);
            if ($name === '' && $abbr === '') { $res['skipped'][] = [$line, t('im.r_nosys')]; continue; }
            $order = is_numeric($g($r, 'order')) ? (int)$g($r, 'order') : (is_numeric($code) ? (int)$code : null);
            $act   = pm_norm_bool($g($r, 'active'));

            $s = null;
            if ($abbr !== '') $s = one('SELECT * FROM vessel_systems WHERE abbr = ? LIMIT 1', [$abbr]);
            if (!$s && $code !== '') $s = one('SELECT * FROM vessel_systems WHERE code = ? LIMIT 1', [$code]);
            if (!$s && $name !== '') $s = one('SELECT * FROM vessel_systems WHERE LOWER(name) = LOWER(?) LIMIT 1', [$name]);

            // the same abbreviation or description must not already belong to another row
            if ($abbr !== '' && scalar('SELECT COUNT(*) FROM vessel_systems WHERE abbr = ? AND id <> ?', [$abbr, (int)($s['id'] ?? 0)])) { $res['skipped'][] = [$line, t('sys.dup', ['v' => $abbr])]; continue; }
            if ($name !== '' && scalar('SELECT COUNT(*) FROM vessel_systems WHERE LOWER(name) = LOWER(?) AND id <> ?', [$name, (int)($s['id'] ?? 0)])) { $res['skipped'][] = [$line, t('sys.dup', ['v' => $name])]; continue; }

            if ($s) {
                q('UPDATE vessel_systems SET code = COALESCE(?, code), abbr = COALESCE(?, abbr), name = COALESCE(?, name),
                                             sort_order = COALESCE(?, sort_order), is_active = COALESCE(?, is_active) WHERE id = ?',
                  [$code ?: null, $abbr ?: null, $name ?: null, $order, $act === null ? null : (int)$act, $s['id']]);
                $res['systems_upd']++;
            } else {
                if ($name === '') { $res['skipped'][] = [$line, t('im.r_nosysname')]; continue; }
                q('INSERT INTO vessel_systems (code, abbr, name, sort_order, is_active) VALUES (?,?,?,?,?)',
                  [$code ?: null, $abbr ?: null, $name, $order ?? 999, $act === null ? 1 : (int)$act]);
                $res['systems_new']++;
            }
        }
        return $res;
    });
}
/** Project / sub-project statuses in display order (R57-001 adds "quoted" = offer stage when the migration has run). */
function pm_statuses(): array { return function_exists('of_quoted_has') && of_quoted_has() ? ['quoted', 'active', 'onhold', 'completed'] : ['active', 'onhold', 'completed']; }
/** Status of a project / sub-project created without an approved offer. */
function pm_default_status(): string { return function_exists('of_quoted_has') && of_quoted_has() ? 'quoted' : 'active'; }
function pm_status_tone(string $s): string { return ['quoted' => 'navy', 'active' => 'green', 'onhold' => 'gold', 'completed' => 'mute'][$s] ?? 'mute'; }
function pm_norm_status(string $s): ?string {
    $k = strtolower(preg_replace('/[^a-z]/', '', strtolower($s)));
    $m = ['active' => 'active', 'open' => 'active', 'running' => 'active', 'inprogress' => 'active',
          'quoted' => 'quoted', 'quote' => 'quoted', 'quotation' => 'quoted', 'offer' => 'quoted', 'offered' => 'quoted', 'prospect' => 'quoted', 'tender' => 'quoted',
          'onhold' => 'onhold', 'hold' => 'onhold', 'paused' => 'onhold',
          'completed' => 'completed', 'complete' => 'completed', 'closed' => 'completed', 'done' => 'completed', 'finished' => 'completed'];
    return $m[$k] ?? null;
}
function pm_norm_pct(string $v): ?int {
    $v = trim(str_replace(['%', ','], ['', '.'], $v));
    if ($v === '' || !is_numeric($v)) return null;
    $f = (float)$v;
    if ($f > 0 && $f <= 1) $f *= 100;                 // Excel percentage cells arrive as 0.35
    return (int) max(0, min(100, round($f)));
}
function pm_norm_bool(string $v): ?bool {
    $k = strtolower(trim($v));
    if ($k === '') return null;
    return in_array($k, ['1', 'yes', 'y', 'true', 'active', 'ναι', 'si', 'sì'], true);
}

/** Vessels workbook: Customer | Vessel | IMO | Yard | Active. Matched by
 *  customer + vessel name; missing customers are created. */
function pm_import_vessels(string $path, bool $dry): array {
    $rows = xlsx_rows($path);
    $res  = ['rows' => 0, 'customers_new' => 0, 'vessels_new' => 0, 'vessels_upd' => 0, 'skipped' => []];
    $hdr  = pm_import_header($rows, ['vessel' => ['Vessel', 'Vessel name', 'Name', 'Ship']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Customer, Vessel']));
    [$hi, $map] = $hdr;
    $map += xlsx_header_map($rows[$hi], ['customer' => ['Customer', 'Owner', 'Client'], 'imo' => ['IMO', 'IMO No', 'IMO number'],
                                         'yard' => ['Yard', 'Builder', 'Builder / Yard', 'Shipyard'], 'active' => ['Active', 'Status']]);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';

    return pm_import_tx($dry, function () use ($rows, $hi, $g, &$res) {
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;     // blank line
            $res['rows']++;
            $name = $g($r, 'vessel');
            if ($name === '') { $res['skipped'][] = [$i + 1, t('im.r_novessel')]; continue; }
            $cid = pm_find_customer($g($r, 'customer'), $res);        // blank -> unassigned vessel
            $vid = pm_find_vessel($cid, $name, $res, false);
            $imo = mb_substr($g($r, 'imo'), 0, 16) ?: null; $yard = mb_substr($g($r, 'yard'), 0, 120) ?: null;
            $act = pm_norm_bool($g($r, 'active'));
            if ($vid) {
                q('UPDATE vessels SET imo = COALESCE(?, imo), yard = COALESCE(?, yard), is_active = COALESCE(?, is_active) WHERE id = ?',
                  [$imo, $yard, $act === null ? null : (int)$act, $vid]);
                $res['vessels_upd']++;
            } else {
                if (!$cid) $res['vessels_unassigned'] = ($res['vessels_unassigned'] ?? 0) + 1;
                q('INSERT INTO vessels (customer_id, name, imo, yard, is_active) VALUES (?,?,?,?,?)',
                  [$cid, mb_substr($name, 0, 120), $imo, $yard, $act === null ? 1 : (int)$act]);
                $res['vessels_new']++;
            }
        }
        return $res;
    });
}

/** Project-structure workbook, one row per sub-project (or per main
 *  project when the code has no "Y." part):
 *  Project Code | Project Name | Customer | Vessel | Project Manager | Project Status |
 *  Sub-project Code | Sub-project Name | System | Sub-project Status | Order |
 *  Engineering % | Procurement % | Production % | FAT % | Installation % | SAT/HAT % | Commissioning %
 *  Main projects are matched by project code, sub-projects by project +
 *  code; only cells that are filled in overwrite existing values. */
function pm_import_projects(string $path, bool $dry): array {
    $rows = xlsx_rows($path);
    $res  = ['rows' => 0, 'customers_new' => 0, 'vessels_new' => 0, 'projects_new' => 0, 'projects_upd' => 0,
             'subs_new' => 0, 'subs_upd' => 0, 'systems_new' => 0, 'skipped' => []];
    $hdr  = pm_import_header($rows, ['code' => ['Project Code', 'Code', 'ERP Code', 'Project']]);
    if ($hdr === null) throw new RuntimeException(t('im.no_header', ['cols' => 'Project Code, Project Name, Customer']));
    [$hi, $map] = $hdr;
    $en = ['engineering' => 'Engineering', 'procurement' => 'Procurement', 'production' => 'Production', 'fat' => 'FAT',
           'installation' => 'Installation', 'sathat' => 'SAT/HAT', 'commissioning' => 'Commissioning'];
    $stageCols = [];
    foreach (stage_defs() as $slug => $d) {
        $lbl = t($d['key']); $ab = t('st.ab.' . $slug);              // the template is English; the
        $stageCols['pct_' . $slug] = array_values(array_unique([      // signed-in language also works
            $en[$slug], $en[$slug] . ' %', $lbl, $lbl . ' %', $ab, $ab . ' %', $d['col'], $slug]));
    }
    $map += xlsx_header_map($rows[$hi], [
        'pname' => ['Project Name', 'Name', 'Title'], 'customer' => ['Customer', 'Owner', 'Client'], 'vessel' => ['Vessel', 'Ship'],
        'pm' => ['Project Manager', 'PM'], 'pstatus' => ['Project Status', 'Status'],
        'scode' => ['Sub-project Code', 'Subproject Code', 'Sub Code', 'Sub'], 'sname' => ['Sub-project Name', 'Subproject Name', 'Sub-project', 'Subproject'],
        'system' => ['System', 'Vessel System', 'Section'], 'sstatus' => ['Sub-project Status', 'Subproject Status', 'Sub Status'],
        'order' => ['Order', 'Sort', 'Sort Order', 'Seq'], 'desc' => ['Description', 'Scope'],
        'inv' => ['Invoicing', 'Invoicing Status', 'Invoiced'], 'invpct' => ['Invoiced %', 'Invoicing %', 'Invoiced Pct'],
    ] + $stageCols);
    $g = fn(array $r, string $k) => ($map[$k] ?? null) !== null ? trim((string)($r[$map[$k]] ?? '')) : '';

    return pm_import_tx($dry, function () use ($rows, $hi, $g, $map, &$res) {
        $cache = [];                                           // main code -> project row
        foreach (array_slice($rows, $hi + 1, null, true) as $i => $r) {
            if (!array_filter($r, fn($c) => trim((string)$c) !== '')) continue;
            $res['rows']++; $line = $i + 1;
            $code = $g($r, 'code');
            if ($code === '') { $res['skipped'][] = [$line, t('im.r_nocode')]; continue; }
            [$main, $subFromCode] = split_project_code($code);
            $subCode = $g($r, 'scode') ?: ($subFromCode ?? '');
            $subName = $g($r, 'sname');

            /* -- main project: find or create ------------------------- */
            $p = $cache[$main] ?? one('SELECT * FROM projects WHERE project_code = ? OR erp_code = ? ORDER BY project_code = ? DESC LIMIT 1', [$main, $main, $main]);
            $pstatus = pm_norm_status($g($r, 'pstatus'));
            $pname = mb_substr($g($r, 'pname'), 0, 160); $pm = mb_substr($g($r, 'pm'), 0, 120);
            $pmUser = $pm !== '' ? staff_by_name($pm) : null;                  // a portal user when the name matches
            if ($pmUser) $pm = $pmUser['full_name'];
            if (!$p) {
                $cid = pm_find_customer($g($r, 'customer'), $res);
                if (!$cid) { $res['skipped'][] = [$line, t('im.r_nocust')]; continue; }
                $vid = pm_find_vessel($cid, $g($r, 'vessel'), $res);
                $erp = scalar('SELECT COUNT(*) FROM projects WHERE erp_code = ?', [$main]) ? null : mb_substr($main, 0, 40);
                q('INSERT INTO projects (customer_id, vessel_id, name, erp_code, project_code, source, status, project_manager, pm_user_id)
                   VALUES (?,?,?,?,?,"manual",?,?,?)',
                  [$cid, $vid, $pname ?: $main, $erp, mb_substr($main, 0, 40), $pstatus ?? pm_default_status(), $pm ?: null, $pmUser['id'] ?? null]);
                $p = one('SELECT * FROM projects WHERE id = ?', [(int) db()->lastInsertId()]);
                $res['projects_new']++;
            } elseif ($subCode === '' || $pname !== '' || $pm !== '' || $pstatus !== null || $g($r, 'vessel') !== '') {
                $vid = $g($r, 'vessel') !== '' ? pm_find_vessel((int)$p['customer_id'], $g($r, 'vessel'), $res) : null;
                $changed = ($pname !== '' && $pname !== $p['name']) || ($pm !== '' && $pm !== $p['project_manager'])
                        || ($pstatus !== null && $pstatus !== $p['status']) || ($vid !== null && $vid !== (int)$p['vessel_id']);
                if ($changed) {
                    q('UPDATE projects SET name = COALESCE(?, name), project_manager = COALESCE(?, project_manager),
                                           pm_user_id = CASE WHEN ? <> \'\' THEN ? ELSE pm_user_id END,
                                           status = COALESCE(?, status), vessel_id = COALESCE(?, vessel_id) WHERE id = ?',
                      [$pname ?: null, $pm ?: null, $pm, $pmUser['id'] ?? null, $pstatus, $vid, $p['id']]);
                    $p = one('SELECT * FROM projects WHERE id = ?', [$p['id']]);
                    if (!isset($res['_upd'][$p['id']])) { $res['projects_upd']++; $res['_upd'][$p['id']] = true; }
                }
            }
            $cache[$main] = $p;
            if ($subCode === '' && $subName === '') continue;         // main-project-only row

            /* -- sub-project: find or create -------------------------- */
            $s = $subCode !== ''
               ? one('SELECT * FROM subprojects WHERE project_id = ? AND code = ?', [$p['id'], $subCode])
               : one('SELECT * FROM subprojects WHERE project_id = ? AND LOWER(name) = LOWER(?)', [$p['id'], $subName]);
            if (!$s && $subName === '') { $res['skipped'][] = [$line, t('im.r_nosubname')]; continue; }   // a new sub-project needs a name; updates don't
            $sysId = pm_find_system($g($r, 'system'), $res);
            $sstatus = pm_norm_status($g($r, 'sstatus'));
            $order = is_numeric($g($r, 'order')) ? (int)$g($r, 'order') : null;
            $desc = mb_substr($g($r, 'desc'), 0, 4000);
            $pcts = [];
            foreach (stage_defs() as $slug => $d) {
                $v = pm_norm_pct($g($r, 'pct_' . $slug));
                if ($v !== null) $pcts[$d['col']] = $v;
            }
            if (inv_has()) {                                              // Invoicing / Invoiced % columns (R10)
                $inv = inv_norm($g($r, 'inv')); $ipct = pm_norm_pct($g($r, 'invpct'));
                if ($inv === null && $ipct !== null) $inv = $ipct >= 100 ? 'full' : ($ipct > 0 ? 'partial' : 'none');
                if ($inv !== null) { $pcts['invoicing'] = $inv; $pcts['invoiced_pct'] = $inv === 'full' ? 100 : ($inv === 'none' ? 0 : ($ipct ?? (int)($s['invoiced_pct'] ?? 0))); }
            }
            if ($s) {
                $sets = ['name = COALESCE(?, name)', 'system_id = COALESCE(?, system_id)', 'status = COALESCE(?, status)',
                         'sort_order = COALESCE(?, sort_order)', 'description = COALESCE(?, description)'];
                $vals = [$subName !== '' ? mb_substr($subName, 0, 120) : null, $sysId, $sstatus, $order, $desc ?: null];
                foreach ($pcts as $col => $v) { $sets[] = "{$col} = ?"; $vals[] = $v; }
                $vals[] = $s['id'];
                q('UPDATE subprojects SET ' . implode(', ', $sets) . ' WHERE id = ?', $vals);
                $res['subs_upd']++;
            } else {
                if ($order === null) $order = (int) scalar('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM subprojects WHERE project_id = ?', [$p['id']]);
                $cols = ['project_id', 'code', 'erp_code', 'name', 'system_id', 'status', 'sort_order', 'description'];
                $vals = [$p['id'], $subCode !== '' ? mb_substr($subCode, 0, 40) : null, mb_substr($code, 0, 40),
                         mb_substr($subName ?: $subCode, 0, 120), $sysId, $sstatus ?? pm_default_status(), $order, $desc ?: null];
                foreach ($pcts as $col => $v) { $cols[] = $col; $vals[] = $v; }
                q('INSERT INTO subprojects (' . implode(', ', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', $vals);
                $res['subs_new']++;
            }
        }
        unset($res['_upd']);
        return $res;
    });
}

/** Find the header row (first row containing one of the required column
 *  aliases); returns [rowIndex, map] or null. */
function pm_import_header(array $rows, array $required): ?array {
    foreach (array_slice($rows, 0, 15, true) as $i => $r) {
        $map = xlsx_header_map($r, $required);
        if (!in_array(null, $map, true)) return [$i, $map];
    }
    return null;
}

/* ================================================ deletion (R4) */

/** Remove the stored files of the given sp_files rows from disk. */
function pm_unlink_files(array $rows): void {
    foreach ($rows as $f) {
        $path = cp_storage() . '/' . $f['stored_name'];
        if (is_file($path)) @unlink($path);
    }
}

/** Delete a sub-project with everything that hangs off it (comments,
 *  notes, snapshots, tests, files on disk). Drawing requests, owner and
 *  class submittals are kept and unlinked (they belong to the project). */
function pm_delete_subproject(int $id): void {
    $files = all('SELECT stored_name FROM sp_files WHERE subproject_id = ?', [$id]);
    db()->beginTransaction();
    q('DELETE FROM sp_files WHERE subproject_id = ?', [$id]);
    q('DELETE FROM subprojects WHERE id = ?', [$id]);         // cascades: comments, notes, snapshots, tests
    db()->commit();
    pm_unlink_files($files);
}

/** Delete a main project and its whole tree: sub-projects, drawing tracker,
 *  submittals, GA drawings and items, files on disk. Customer documents
 *  that pointed at the project are kept and unlinked. */
function pm_delete_project(int $id): void {
    $files = all('SELECT stored_name FROM sp_files WHERE project_id = ?', [$id]);
    db()->beginTransaction();
    q('UPDATE documents SET project_id = NULL WHERE project_id = ?', [$id]);
    q('DELETE gi FROM ga_items gi JOIN ga_drawings gd ON gd.id = gi.drawing_id WHERE gd.project_id = ?', [$id]);
    q('DELETE FROM ga_drawings WHERE project_id = ?', [$id]);
    q('DELETE FROM owner_approvals WHERE project_id = ?', [$id]);
    q('DELETE FROM class_submittals WHERE project_id = ?', [$id]);
    q('DELETE FROM subprojects WHERE project_id = ?', [$id]);
    q('DELETE FROM sp_files WHERE project_id = ?', [$id]);
    q('DELETE FROM projects WHERE id = ?', [$id]);            // cascades the rest (tracker, revisions)
    db()->commit();
    pm_unlink_files($files);
}

/** What a project deletion would remove — for the confirmation text. */
function pm_project_footprint(int $id): array {
    return [
        'subs'     => (int) scalar('SELECT COUNT(*) FROM subprojects WHERE project_id = ?', [$id]),
        'drawings' => (int) scalar('SELECT COUNT(*) FROM drawing_requests WHERE project_id = ?', [$id]),
        'files'    => (int) scalar('SELECT COUNT(*) FROM sp_files WHERE project_id = ?', [$id]),
        'comments' => (int) scalar('SELECT COUNT(*) FROM sp_comments c JOIN subprojects s ON s.id = c.subproject_id WHERE s.project_id = ?', [$id]),
    ];
}

/** Filter / page parameters of the accounts list to carry through a redirect (GAS-CP-R16-003). */
function au_keep(): array {
    $k = [];
    foreach (['q', 'access', 'customer', 'grole', 'disc', 'status', 'twofa', 'locale', 'login', 'page'] as $n) if (isset($_GET[$n]) && $_GET[$n] !== '') $k[$n] = (string)$_GET[$n];
    return $k;
}

/** Back to the page that holds the invoicing card, with its own parameters kept.   R19 */
function iv_back(string $page, string $type, int $ownerId): void {
    $keep = [];
    foreach (['q', 'status', 'kind', 'edit', 'id'] as $k) if (isset($_GET[$k])) $keep[$k] = $_GET[$k];
    if ($type === 'customer') { $page = 'admin_customers'; $keep['edit'] = $ownerId; }
    elseif ($type === 'company') { $page = 'companies'; $keep['edit'] = $ownerId; }
    elseif ($type === 'vessel') { $page = 'vessel'; $keep = ['id' => $ownerId, 'tab' => 'invoicing']; }   // R43-002
    else { $page = 'contact'; $keep = ['id' => $ownerId]; }
    header('Location: ' . url($page, $keep + ['_r' => uniqid()]) . '#invoicing', true, 303); exit;
}

/** A project to file vessel-level documents under: the vessel's newest active project.   R20 */
function ar_vessel_project(int $vid): int {
    return (int) scalar("SELECT p.id FROM vessels v JOIN projects p ON p.vessel_id = v.id WHERE v.id = ? ORDER BY p.status = 'active' DESC, p.id DESC LIMIT 1", [$vid]);
}

/** Delete one ticket with its files on disk; work orders and service reports that pointed at it are unlinked (comments, events, file rows cascade).   R37-002 (from tk_delete) */
function tk_delete_one(int $id): void {
    foreach (all('SELECT stored_name FROM ticket_files WHERE ticket_id = ?', [$id]) as $f) { $p = cp_storage() . '/' . $f['stored_name']; if (is_file($p)) @unlink($p); }
    try { q('UPDATE job_orders SET ticket_id = NULL WHERE ticket_id = ?', [$id]); } catch (Throwable $e) {}
    try { q('UPDATE fsr_reports SET ticket_id = NULL WHERE ticket_id = ?', [$id]); } catch (Throwable $e) {}
    q('DELETE FROM tickets WHERE id = ?', [$id]);
}

/** R39-001: remember when a sub-project was set Completed (PCR), clear it when it is reopened. */
function pm_track_completed(int $sid): void {
    try { q("UPDATE subprojects SET completed_at = CASE WHEN status = 'completed' THEN COALESCE(completed_at, CURDATE()) ELSE NULL END WHERE id = ?", [$sid]); } catch (Throwable $e) { /* before the R39 migration */ }
}

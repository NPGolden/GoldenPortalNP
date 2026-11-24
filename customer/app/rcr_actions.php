<?php
/** R89-001 recruiting POST actions — called from index.php before pm_handle_action; every branch redirects. */
declare(strict_types=1);
function rcr_handle_action(string $action): bool {
    if (!str_starts_with($action, 'rcr_')) return false;
    if (!function_exists('rcr_has') || !rcr_has()) throw new RuntimeException(t('err.noaccess'));
    switch ($action) {
        case 'rcr_vacancy_ai': { $id = (int)($_POST['id'] ?? 0); $brief = mb_substr(trim((string)($_POST['brief'] ?? '')), 0, 2000); $r = rcr_vacancy_ai_draft($id, $brief, $_POST); $_SESSION['rc_vac_ai_msg'] = ['brief' => $brief, 'a' => $r['ok'] ? t('rc.ai_applied') . (($r['notes'] ?? '') !== '' ? ' — ' . $r['notes'] : '') : '', 'err' => $r['ok'] ? '' : $r['error']]; redirect('rcr_vacancy', $id ? ['id' => $id, 'edit' => 1, '_r' => uniqid()] : ['new' => 1, '_r' => uniqid()], '#rc-ai'); }
        case 'rcr_vacancy_ai_clear': { $id = (int)($_POST['id'] ?? 0); unset($_SESSION['rc_vac_ai'][$id], $_SESSION['rc_vac_ai_msg']); redirect('rcr_vacancy', $id ? ['id' => $id, 'edit' => 1] : ['new' => 1]); }
        case 'rcr_vacancy_save': unset($_SESSION['rc_vac_ai'][(int)($_POST['id'] ?? 0)], $_SESSION['rc_vac_ai_msg']); $id = rcr_vacancy_save($_POST); if (function_exists('jd_has') && jd_has() && isset($_POST['jd_id'])) { $jid = (int)$_POST['jd_id']; q('UPDATE rc_vacancies SET jd_id = ? WHERE id = ?', [$jid && jd_load($jid) ? $jid : null, $id]); unset($_SESSION['rc_vac_jd'][(int)($_POST['id'] ?? 0)]); }   /* R97-121 */ flash('good', t('sys.saved')); redirect('rcr_vacancy', ['id' => $id]);
        case 'rcr_event_save': $id = rcr_event_save($_POST); flash('good', t('sys.saved')); redirect('rcr_event', ['id' => $id]);
        case 'rcr_event_reg_save': rcr_event_reg_save((int)$_POST['rid'], $_POST); flash('good', t('sys.saved')); redirect('rcr_event', ['id' => (int)$_POST['id']]);
        case 'rcr_keep': rcr_keep((int)$_POST['id'], (int)$_POST['months'], (string)($_POST['reason'] ?? ''), !empty($_POST['pool_consent'])); flash('good', t('rc.keep_saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#keep');
        case 'rcr_eval_save': rcr_eval_save((int)$_POST['id'], $_POST); flash('good', t('rc.ev_saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#eval');
        case 'rcr_eval_delete': rcr_eval_delete((int)$_POST['eval_id']); flash('good', t('sys.saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#eval');
        case 'rcr_interview_save': rcr_interview_save((int)$_POST['id'], $_POST); flash('good', t('rc.ev_int_saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#interviews');
        case 'rcr_interview_update': rcr_interview_update((int)$_POST['int_id'], $_POST); flash('good', t('rc.ev_int_updated')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#interviews');   // R97-229
        case 'rcr_interview_send': $okM = rcr_interview_send((int)$_POST['int_id'], (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? '')); flash($okM ? 'good' : 'warn', t($okM ? 'rc.ev_sent_ok' : 'rc.ev_sent_kept')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#interviews');
        case 'rcr_offer_save': rcr_offer_save((int)$_POST['id'], $_POST); flash('good', t('rc.of_saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#offer');   // R97-231
        case 'rcr_offer_send': $okO = rcr_offer_send((int)$_POST['offer_id'], (string)($_POST['subject'] ?? ''), (string)($_POST['body'] ?? '')); flash($okO ? 'good' : 'warn', t($okO ? 'rc.of_sent_ok' : 'rc.ev_sent_kept')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#offer');
        case 'rcr_offer_outcome': rcr_offer_outcome((int)$_POST['offer_id'], (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? '')); flash('good', t('sys.saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#offer');
        case 'rcr_interview_status': rcr_interview_status((int)$_POST['int_id'], (string)$_POST['status']); flash('good', t('sys.saved')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()], '#interviews');
        case 'rcr_eval_criteria_save': if (!rcr_can()) throw new RuntimeException(t('err.noaccess')); rcr_eval_criteria_save($_POST['crit'] ?? []); flash('good', t('sys.saved')); redirect('rcr_settings', ['_r' => uniqid()], '#eval');
        case 'rcr_assign': rcr_assign_vacancy((int)$_POST['id'], (int)($_POST['vacancy_id'] ?? 0)); flash('good', t('rc.assign_done')); redirect('rcr_application', ['id' => (int)$_POST['id'], '_r' => uniqid()]);   // R97-158
        case 'rcr_manual_add': $id = rcr_manual_add($_POST, $_FILES['cv'] ?? []); flash('good', t('rc.man_done')); redirect('rcr_application', ['id' => $id]);   // R97-161
        case 'rcr_app_decide': rcr_application_decide((int)$_POST['id'], $_POST); flash('good', t('sys.saved')); redirect('rcr_application', ['id' => (int)$_POST['id']]);
        case 'rcr_set_save': $id = rcr_set_save($_POST); flash('good', t('sys.saved')); redirect('rcr_sets', ['id' => $id]);
        case 'rcr_settings_save': if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
            foreach (['rc_site_origin', 'rc_retention_months', 'rc_pool_retention_months', 'rc_notify_email', 'rc_cv_max_mb', 'rc_mail_from'] as $k) cp_setting_set($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 190));
            if (!empty($_POST['rc_rotate_key'])) { $nk = bin2hex(random_bytes(24)); cp_setting_set('rc_site_key', $nk); $_SESSION['rc_key_once'] = $nk; rcr_log('key_rotated'); }   // shown in full once, on the next page view only
            flash('good', t('sys.saved')); redirect('rcr_settings');
        case 'rcr_app_delete': $n = rcr_app_delete(isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [(int)($_POST['id'] ?? 0)], (string)($_POST['note'] ?? ''), !empty($_POST['pool'])); flash('good', t('rc.del_done', ['n' => $n['apps'], 'cv' => $n['cvs']]) . ($n['pool'] ? ' ' . t('rc.del_done_pool', ['n' => $n['pool']]) : '') . ($n['skipped'] ? ' ' . t('rc.del_skipped', ['n' => $n['skipped']]) : '')); redirect('rcr_dashboard', ['tab' => 'apps']);   // R97-209
        case 'rcr_cv_delete': rcr_cv_delete((int)$_POST['id'], (string)($_POST['note'] ?? '')); flash('good', t('rc.del_cv_done')); redirect('rcr_application', ['id' => (int)$_POST['id']]);
        case 'rcr_pool_delete': rcr_pool_delete((int)$_POST['id']); flash('good', t('rc.del_pool_done')); redirect('rcr_dashboard', ['tab' => 'pool']);
        case 'rcr_retention_now': if (!rcr_can()) throw new RuntimeException(t('err.noaccess')); $n = rcr_retention_run(); flash('good', 'Retention run: ' . json_encode($n)); redirect('rcr_settings');
    }
    return false;
}

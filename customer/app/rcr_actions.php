<?php
/** R89-001 recruiting POST actions — called from index.php before pm_handle_action; every branch redirects. */
declare(strict_types=1);
function rcr_handle_action(string $action): bool {
    if (!str_starts_with($action, 'rcr_')) return false;
    if (!function_exists('rcr_has') || !rcr_has()) throw new RuntimeException(t('err.noaccess'));
    switch ($action) {
        case 'rcr_vacancy_save': $id = rcr_vacancy_save($_POST); flash('good', t('sys.saved')); redirect('rcr_vacancy', ['id' => $id]);
        case 'rcr_event_save': $id = rcr_event_save($_POST); flash('good', t('sys.saved')); redirect('rcr_event', ['id' => $id]);
        case 'rcr_event_reg_save': rcr_event_reg_save((int)$_POST['rid'], $_POST); flash('good', t('sys.saved')); redirect('rcr_event', ['id' => (int)$_POST['id']]);
        case 'rcr_app_decide': rcr_application_decide((int)$_POST['id'], $_POST); flash('good', t('sys.saved')); redirect('rcr_application', ['id' => (int)$_POST['id']]);
        case 'rcr_set_save': $id = rcr_set_save($_POST); flash('good', t('sys.saved')); redirect('rcr_sets', ['id' => $id]);
        case 'rcr_settings_save': if (!rcr_can()) throw new RuntimeException(t('err.noaccess'));
            foreach (['rc_site_origin', 'rc_retention_months', 'rc_notify_email', 'rc_cv_max_mb', 'rc_mail_from'] as $k) cp_setting_set($k, mb_substr(trim((string)($_POST[$k] ?? '')), 0, 190));
            if (!empty($_POST['rc_rotate_key'])) cp_setting_set('rc_site_key', bin2hex(random_bytes(24)));
            flash('good', t('sys.saved')); redirect('rcr_settings');
        case 'rcr_retention_now': if (!rcr_can()) throw new RuntimeException(t('err.noaccess')); $n = rcr_retention_run(); flash('good', 'Retention run: ' . json_encode($n)); redirect('rcr_settings');
    }
    return false;
}

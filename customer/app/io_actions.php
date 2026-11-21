<?php
/** In / Out POST actions (GAS-CP-R97-182) — called from index.php before pm_handle_action; every branch redirects. */
declare(strict_types=1);
function io_handle_action(string $action): bool {
    if (!str_starts_with($action, 'io_')) return false;
    if (!function_exists('io_has') || !io_has() || !is_staff()) throw new RuntimeException(t('err.noaccess'));
    $back = fn(string $tab, array $x = []) => redirect('inout', ['tab' => $tab, '_r' => uniqid()] + $x);
    switch ($action) {
        case 'io_punch': io_punch_portal((string)($_POST['kind'] ?? 'in')); flash('good', t(($_POST['kind'] ?? '') === 'out' ? 'io.done_out' : 'io.done_in')); $back('my');
        case 'io_decide': io_decide((int)$_POST['id'], (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? '')); flash('good', t('sys.saved')); $back('exc');
        case 'io_decide_all': { $n = 0; foreach ((array)($_POST['ids'] ?? []) as $id) { io_decide((int)$id, (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? '')); $n++; } flash('good', t('io.decided_n', ['n' => $n])); $back('exc'); }
        case 'io_ot_decide': io_ot_decide((int)$_POST['uid'], (string)$_POST['day'], (string)($_POST['status'] ?? ''), (string)($_POST['note'] ?? '')); flash('good', t('sys.saved')); $back('ot', array_filter(['from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? '']));
        case 'io_site_save': io_site_save($_POST); flash('good', t('sys.saved')); $back('sites');
        case 'io_settings_save': io_settings_save($_POST); flash('good', t('sys.saved')); $back('sites');
    }
    return false;
}

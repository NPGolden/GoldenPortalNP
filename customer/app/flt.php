<?php
declare(strict_types=1);
/**
 * Remembered list filters (R29-008).
 *
 * Every big list keeps the last filter / sort selection of the signed-in user in the session.
 * Opening the list again without any filter parameter (menu, dashboard tile, back link) restores
 * the previous selection; submitting the filter bar stores the new one; "Clear" sends reset=1
 * which forgets the stored selection and shows the defaults.
 *
 * Context keys (view / id / tab) select WHICH memory slot is used (e.g. Tickets › Activity and
 * Tickets › List are remembered separately) but are never treated as a filter themselves.
 */

const FLT_PAGES = [
    'tickets'         => ['ctx' => ['view' => 'open'],        'keys' => ['q', 'vessel', 'customer', 'project', 'status', 'priority', 'category', 'side', 'assigned', 'aq', 'aperiod', 'afrom', 'ato', 'aopen', 'asort']],
    'offers'          => ['ctx' => [],              'keys' => ['q', 'project', 'vessel', 'status']],
    'equipment'       => ['ctx' => [],              'keys' => ['q', 'maker', 'family', 'vessel', 'status']],
    'vessels'         => ['ctx' => [],              'keys' => ['q', 'customer', 'status', 'projects']],
    'projects'        => ['ctx' => [],              'keys' => ['q', 'customer', 'vessel', 'pm', 'branch', 'st', 'f']],
    'contacts'        => ['ctx' => [],              'keys' => ['q', 'customer', 'company', 'vessel', 'category', 'status', 'soon', 'channel', 'sub']],
    'companies'       => ['ctx' => [],              'keys' => ['q', 'kind']],
    'timesheets'      => ['ctx' => ['view' => 'mine'],        'keys' => ['q', 'project', 'vessel', 'customer', 'user', 'status', 'period', 'from', 'to', 'approver', 'discipline', 'group', 'htype', 'invoice', 'minh', 'sub']],
    'manhours'        => ['ctx' => [],              'keys' => ['project', 'vessel', 'st', 'from', 'to']],
    'tasks'           => ['ctx' => ['view' => 'myday'],        'keys' => ['q', 'project', 'sub', 'kind', 'who']],
    'jo_list'         => ['ctx' => [],              'keys' => ['q', 'project', 'status']],
    'approvals'       => ['ctx' => [],              'keys' => ['q', 'project', 'status']],
    'cyber'           => ['ctx' => [],              'keys' => ['q', 'vessel', 'status', 'customer']],
    'kb'              => ['ctx' => [],              'keys' => ['q', 'cat', 'st', 'sort']],
    'admin_users'     => ['ctx' => [],              'keys' => ['q', 'access', 'customer', 'grole', 'disc', 'status', 'twofa', 'locale', 'login']],
    'admin_log'       => ['ctx' => [],              'keys' => ['q', 'from', 'to', 'outcome']],
    'admin_customers' => ['ctx' => [],              'keys' => ['q', 'status']],
    'subproject'      => ['ctx' => ['id', 'tab'],   'keys' => ['ist', 'iside', 'icat', 'iq', 'isort', 'idir'], 'when' => ['tab' => 'issues']],
];

/** Memory slot name for a page + its context values. */
function flt_slot(string $page, array $ctx): string {
    $s = $page;
    foreach ($ctx as $k => $dflt) { if (is_int($k)) { $k = $dflt; $dflt = ''; } $v = (string)($_GET[$k] ?? ''); $s .= ':' . preg_replace('/[^A-Za-z0-9_-]/', '', $v === '' ? (string)$dflt : $v); }   // a missing context value means its default (menu link ?p=tickets = the Open view)
    return $s;
}

/** Apply the remembered-filter logic for the current request (GET only). Call once, before the view renders. */
function flt_apply(string $page): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || !isset(FLT_PAGES[$page]) || !current_user()) return;
    $def = FLT_PAGES[$page];
    foreach ($def['when'] ?? [] as $k => $v) if ((string)($_GET[$k] ?? '') !== $v) return;     // only on the tab that has the list
    $slot = flt_slot($page, $def['ctx']);
    if (isset($_GET['reset'])) { unset($_SESSION['flt'][$slot], $_GET['reset']); return; }    // "Clear": forget and show the defaults
    $present = array_intersect_key($_GET, array_flip($def['keys']));
    if ($present) {                                                                          // the user chose something: remember it (empty values included — "Apply" with blanks is a choice too)
        $_SESSION['flt'][$slot] = array_map(fn($v) => is_array($v) ? array_values($v) : (string)$v, $present);
        return;
    }
    $saved = $_SESSION['flt'][$slot] ?? null;
    if (!$saved) return;
    $_GET = $_GET + $saved;                                                                  // nothing asked for: bring back the previous selection
    $_REQUEST = $_REQUEST + $saved;
    $GLOBALS['flt_restored'] = true;
}

/** True when the current list is showing a remembered (restored) selection — lets the view show a small hint. */
function flt_restored(): bool { return !empty($GLOBALS['flt_restored']); }

/** Small inline hint for the filter bar: "Showing your previous selection · Clear". */
function flt_hint(string $page, array $ctx = []): string {
    if (!flt_restored()) return '';
    return '<span class="flt-restored">' . tt('flt.restored') . ' <a href="' . url($page, $ctx + ['reset' => 1]) . '">' . tt('btn.clear') . '</a></span>';
}

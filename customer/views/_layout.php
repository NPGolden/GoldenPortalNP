<?php
/** Customer Portal chrome — navy topbar, gold accent, dark sidebar,
 *  as in the approved Figma frame (node 1:2). Mobile: drawer nav.   */

function nav_items(): array {                           // flat list, kept for anything that still asks for it
    $out = [];
    foreach (nav_groups() as $g) foreach ($g['items'] as $it) $out[] = [$it[0], $it[1]];
    return $out;
}
/** The grouped menu (R15): Dashboard, then Fleet · Work · Commercial · Library.
 *  Each item = [slug, label, icon]. Import from Excel lives under Settings now. */
function nav_groups(): array {
    $staff = function_exists('is_staff') && is_staff();
    $g = [
        ['key' => 'top',        'label' => '',                   'items' => [['dashboard', t('nav.dashboard'), '⌂']]],
        ['key' => 'fleet',      'label' => t('nav.g_fleet'),      'items' => [['vessels', t('nav.vessels'), '⚓'], ['projects', t('nav.projects'), '▦']]],
        ['key' => 'work',       'label' => t('nav.g_work'),       'items' => [['tickets', t('nav.tickets'), '🎫'], ['joborders', t('nav.joborders'), '🛠'], ['reports', t('nav.reports'), '📋']]],
        ['key' => 'commercial', 'label' => t('nav.g_commercial'), 'items' => array_values(array_filter([(!function_exists('of_visible') || of_visible()) ? ['offers', t('nav.offers'), '€'] : null, ['approvals', t('nav.approvals'), '✓']]))],
        ['key' => 'library',    'label' => t('nav.g_library'),    'items' => [['documents', t('nav.docs'), '📁'], ['cyber', t('nav.cyber'), '🛡'], ['support', t('nav.support'), '?']], 'folded' => true],
    ];
    if (function_exists('eq_has') && eq_has()) $g[1]['items'][] = ['equipment', t('eq.title'), '🔌'];
    if ($staff) $g[2]['items'][] = ['timesheets', t('nav.timesheets'), '⏱'];
    if ($staff && function_exists('pl_has') && pl_has()) $g[2]['items'][] = ['planning', t('pl.title'), '🗓'];
    if ($staff && function_exists('wp_has') && wp_has()) { $g[2]['items'][] = ['myworks', t('wp.my'), '🔧']; if (wp_can_manage(0) || (function_exists('bg_can') && bg_can())) $g[2]['items'][] = ['workload', t('wp.workload'), '📊']; }
    if ($staff && function_exists('ct_has') && ct_has()) $g[3]['items'][] = ['contacts', t('nav.contacts'), '☎'];
    if ($staff && function_exists('acc_visible') && acc_visible()) $g[3]['items'][] = ['acc_reports', t('acc.title'), '🧾'];
    if ($staff && function_exists('kpi_can') && kpi_can()) $g[2]['items'][] = ['kpis', t('kpi.title'), '🎯'];
    if ($staff && function_exists('sl_can') && sl_can()) $g[3]['items'][] = ['sales', t('sl.title'), '📈'];
    if ($staff && function_exists('pi_can_view') && pi_can_view()) $g[3]['items'][] = ['proformas', t('pi.title'), '💶'];
    if ($staff && function_exists('cf_can') && cf_can()) $g[3]['items'][] = ['cashflow', t('cf.title'), '💰'];
    if ($staff && function_exists('eng_is_management') && eng_has() && (eng_is_management() || eng_is_pm())) $g[2]['items'][] = ['eng_requests', t('eng.requests'), '📐'];   // R47-001 / R48-001
    if (function_exists('kb_has') && kb_has()) $g[4]['items'][] = ['kb', t('kb.title'), '💬'];
    if ($staff && function_exists('td_has') && td_has()) $g[2]['items'][] = ['tasks', t('td.title'), '📅'];
    if ($staff && function_exists('hr2_has') && hr2_has() && hr_person_of_user((int)(current_user()['id'] ?? 0))) $g[2]['items'][] = ['my_leave', t('hr.my_leave'), '🏖'];   // R54-001
    if ($staff && function_exists('hr2_has') && hr2_has() && (hr_can() || hr_is_manager())) $g[2]['items'][] = ['hr_requests', t('hr.requests'), '📝'];   // R54-001
    if ($staff && function_exists('hr_only') && hr_only()) $g = [['key' => 'top', 'label' => '', 'items' => [['hr_dashboard', t('hr.dashboard'), '📊'], ['planning', t('pl.title'), '🗓'], ['hr_requests', t('hr.requests'), '📝'], ['admin_hr', t('hr.settings'), '🏖']]]];   // R51-001 / R55-005: HR sees the board and its settings only
    if ($staff && function_exists('emp_only') && emp_only()) $g = [['key' => 'top', 'label' => '', 'items' => [['planning', t('hr.my_program'), '🗓'], ['my_leave', t('hr.my_leave'), '🏖']]]];   // R54-001 / R56-002: employee scope
    return $g;
}
/** Badge counts per slug (what is waiting for this user). */
function nav_badges(): array {
    $b = [];
    if (!is_staff() && function_exists('oa_pending')) $b['approvals'] = count(oa_pending());
    if (is_staff() && function_exists('ts_to_approve')) $b['timesheets'] = count(ts_to_approve());
    if (function_exists('tk_attention')) $b['tickets'] = count(tk_attention());
    if (function_exists('td_badge')) $b['tasks'] = td_badge();
    if (!is_staff() && function_exists('of_pending')) $b['offers'] = count(of_pending());
    if (is_staff() && function_exists('hr2_has') && hr2_has()) { $n = 0; if (hr_can()) $n = (int) scalar("SELECT COUNT(*) FROM hr_requests WHERE status = 'pending_hr'"); if (hr_is_manager()) $n += count(array_filter(hr_requests_for('manager'), fn($r) => $r['status'] === 'pending_mgr')); if ($n) $b['hr_requests'] = $n; if (function_exists('hr_msg_has') && hr_msg_has()) { $m = hr_messages_unread(); if ($m) { if (hr_can()) $b['admin_hr'] = $m; else $b['my_leave'] = $m; } } }   // R54-001 / R56-002
    if (is_staff() && function_exists('eng_is_management') && eng_has() && (eng_is_management() || eng_is_pm())) $b['eng_requests'] = count(array_filter(eng_requests(['status' => 'pending']), fn($q) => eng_can_decide(['project_id' => (int)$q['project_id'], 'pm_user_id' => null], (string)$q['stage'])));   // R47-001 / R48-001
    return array_filter($b);
}

/** Settings is one page with tabs (gear icon in the top bar): the account for
 *  everybody; vessel systems, Golden A/S roles, portal accounts and the login
 *  history for administrators. Each tab keeps its own ?p= address.     R9 */
function settings_tab_groups(): array {
    $admin = function_exists('is_cp_admin') && is_cp_admin(); $staff = function_exists('is_staff') && is_staff();
    $G = [[t('stg.me'), [['account', t('nav.account')]]]];
    if ($admin) {
        $org = [['admin_users', t('nav.users')], ['admin_roles', t('nav.roles')], ['admin_disciplines', t('nav.disciplines')]];
        if (function_exists('wc_has') && wc_has()) $org[] = ['admin_calendars', t('wc.title')];
        if (function_exists('br_has') && br_has()) $org[] = ['admin_branches', t('br.title')];
        if (function_exists('eng_has') && eng_has()) $org[] = ['admin_engineering', t('eng.settings')];   // R47-002
        if (function_exists('ai_has') && ai_has()) $org[] = ['admin_ai', t('ai.settings')];   // R50-001
        if (function_exists('hr_has') && hr_has()) $org[] = ['admin_hr', t('hr.settings')];   // R51-001
        $G[] = [t('stg.org'), $org];
        $fleet = [['admin_customers', t('nav.customers')]];
        if (function_exists('vm_has') && vm_has()) $fleet[] = ['admin_vessel_merge', t('vm.title')];
        $fleet[] = ['admin_systems', t('nav.systems')];
        $G[] = [t('stg.fleet'), $fleet];
        $lists = [];
        if (function_exists('of_has') && of_has() && of_visible()) $lists[] = ['admin_offer_statuses', t('nav.offer_statuses')];
        if (function_exists('td_has') && td_has()) $lists[] = ['admin_task_kinds', t('tdk.title')];
        if (function_exists('iss_cat_table') && iss_cat_table()) $lists[] = ['admin_issue_cats', t('isc.title')];
        if (function_exists('fk_table') && fk_table()) $lists[] = ['admin_file_kinds', t('fkc.title')];
        if (function_exists('et_has') && et_has()) $lists[] = ['admin_email_templates', t('et.title')];
        if (function_exists('rl_has') && rl_has() && of_visible()) $lists[] = ['admin_rate_cards', t('rl.title')];
        if (function_exists('ob_has') && ob_has() && of_visible()) $lists[] = ['admin_offer_texts', t('ob.texts_title')];
        if (function_exists('kpi_has') && kpi_has()) $lists[] = ['admin_kpis', t('kpi.settings')];
        if (function_exists('num_has') && num_has()) $lists[] = ['admin_numbering', t('num.title')];
        if (function_exists('pi_has') && pi_has()) $lists[] = ['admin_offer_classes', t('oc.settings')];
        if (function_exists('sl_has') && sl_has() && of_visible()) $lists[] = ['admin_sales', t('sl.settings')];
        if ($lists) $G[] = [t('stg.lists'), $lists];
    }
    $data = [];
    if ($staff) $data[] = ['import', t('nav.import')];
    if ($admin && function_exists('fd_has') && fd_has()) $data[] = ['fd_import', t('fd.tab')];
    if ($data) $G[] = [t('stg.data'), $data];
    if ($admin) $G[] = [t('stg.system'), [['admin_log', t('nav.loginlog')], ['admin_about', t('nav.about')], ['license', t('lic.title')]]];
    return $G;
}
function settings_tabs_list(): array {
    $tabs = [];
    foreach (settings_tab_groups() as [$label, $items]) foreach ($items as $it) $tabs[] = $it;
    return $tabs;
}
function is_settings_page(string $slug): bool {
    return in_array($slug, array_column(settings_tabs_list(), 0), true);
}
/** The settings page head + tab strip; the active tab's view prints its own content below. */
function settings_tabs(string $active): void { ?>
<p class="eyebrow"><?= tt('nav.settings') ?></p>
<h1 class="settings-title"><span class="gear-ico" aria-hidden="true"><?= gear_svg() ?></span><?= tt('nav.settings') ?></h1>
<nav class="settings-groups" aria-label="<?= tt('nav.settings') ?>">
  <?php foreach (settings_tab_groups() as [$glabel, $items]): $on = in_array($active, array_column($items, 0), true); ?>
  <div class="stg<?= $on ? ' is-on' : '' ?>"><span class="stg-label"><?= e($glabel) ?></span><div class="tabs settings-tabs">
    <?php foreach ($items as [$slug, $label]): ?><a class="tab<?= $active === $slug ? ' is-on' : '' ?>" href="<?= url($slug) ?>"<?= $active === $slug ? ' aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?>
  </div></div>
  <?php endforeach; ?>
</nav>
<?php }
/** Sign-out icon in the top bar (GAS-CP-R36-001). */
function signout_svg(): string {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>';
}
function gear_svg(): string {
    return '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
         . '<circle cx="12" cy="12" r="3.2"/>'
         . '<path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1 1.55V21a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1.1-1.55 1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.55-1H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.55V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 1.55 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.55 1H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.51 1z"/></svg>';
}

function layout_head(string $title, string $active = ''): void {
    $u = current_user();
    if ($active === 'kb_q') $active = 'kb';
    if ($active === 'request' || $active === 'requests') $active = 'support';
    if ($active === 'task_edit') $active = 'tasks';
    if ($u && function_exists('nf_sweep')) nf_sweep();                 // R18-002: derive new notifications (throttled)
    ?><!doctype html>
<html lang="<?= e(locale()) ?>" data-theme="<?= e(theme_current()) ?>" data-flt-restored="<?= e(t('flt.restored')) ?>" data-flt-hidden="<?= e(t('flt.hidden')) ?>" data-ss-search="<?= e(t('ss.search')) ?>" data-ss-none="<?= e(t('ss.none')) ?>" data-rt-labels="<?= e(json_encode(['b' => t('rt.bold'), 'i' => t('rt.italic'), 'u' => t('rt.underline'), 'ul' => t('rt.ul'), 'ol' => t('rt.ol'), 'hint' => t('rt.hint')], JSON_UNESCAPED_UNICODE)) ?>"<?= function_exists('mt_users_json') && ($mtJ = mt_users_json()) !== '' ? ' data-mt-users="' . e($mtJ) . '" data-mt-hint="' . e(t('mt.hint')) . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0A1B2E">
<title><?= e($title) ?> — <?= e(function_exists('lic_brand') ? lic_brand() : t('app.name')) ?></title>
<script nonce="<?= csp_nonce() ?>">try{if(JSON.parse(localStorage.getItem('gas.rail')||'{}').compact)document.documentElement.classList.add('rail-compact');}catch(e){}</script>
<link rel="icon" href="../assets/favicon.png" type="image/png">
<link rel="apple-touch-icon" href="../assets/favicon.png">
<link rel="stylesheet" href="../assets/portal.css?v=<?= @filemtime(__DIR__ . '/../../assets/portal.css') ?: 1 ?>">
</head>
<body<?= function_exists('flt_restored') && flt_restored() ? ' data-flt="1"' : '' ?><?php if (!empty($_SESSION['cu_id']) && !empty($_SESSION['wkey']) && function_exists('sec_cfg') && sec_cfg('sec_close_signout')): ?> data-wchk="<?= e((string)$_SESSION['wkey']) ?>"<?= !empty($_SESSION['wkey_new']) ? ' data-wnew="1"' : '' ?><?php $_SESSION['wkey_new'] = false; endif; ?>>
<a class="skip" href="#main"><?= tt('nav.skip') ?></a>

<header class="topbar">
  <button class="navtoggle" id="navtoggle" aria-label="<?= tt('nav.menu') ?>" aria-expanded="false">≡</button>
  <a class="brand" href="<?= url('dashboard') ?>" aria-label="Golden Automation Systems">
    <img class="brand-logo" src="../assets/golden-logo-white.png?v=<?= @filemtime(__DIR__ . '/../../assets/golden-logo-white.png') ?: 1 ?>" alt="Golden Automation Systems">
    <span class="brand-name"><?= e(function_exists('lic_brand') ? lic_brand() : t('app.name')) ?></span>
  </a>
  <div class="spacer"></div>
  <?php if (function_exists('is_staff') && is_staff()): $on = online_users(); ?>
    <a class="online" href="<?= is_cp_admin() ? url('admin_log') : '#' ?>" title="<?= e(implode(', ', array_map(fn($o) => $o['full_name'] . ($o['customer_name'] ? ' (' . $o['customer_name'] . ')' : ''), $on))) ?>">
      <i></i><?= e(t('nav.online', ['n' => count($on)])) ?></a>
  <?php endif; ?>
  <a class="who" href="<?= url('account') ?>" title="<?= tt('nav.myaccount') ?>">
    <?= avatar_html((int)($u['id'] ?? 0), (string)($u['full_name'] ?? ''), 32, 'avatar') ?>
    <span class="name"><?= tt('nav.myaccount') ?></span>
  </a>
  <?= function_exists('nf_bell_html') ? nf_bell_html($active) : '' ?>
  <?= theme_toggle_html($active) ?>
  <a class="gear<?= is_settings_page($active) ? ' is-here' : '' ?>" href="<?= url('account') ?>" title="<?= tt('nav.settings') ?>" aria-label="<?= tt('nav.settings') ?>"><?= gear_svg() ?></a>
  <?= lang_selector(true) ?>
  <form method="post" action="<?= url($active ?: 'dashboard') ?>" class="signout-f"><?= csrf_field() ?><input type="hidden" name="action" value="logout">
    <button class="gear signout" type="submit" title="<?= tt('nav.signout') ?>" aria-label="<?= tt('nav.signout') ?>"><?= signout_svg() ?></button></form>
</header>
<?php /* R21-003 world clock strip — 24 h, ticking in the browser; home zone framed.  R22-003: click a zone for sunrise / sunset and today's weather (Open-Meteo, fetched by the browser) */
$clocks = [['UTC', 'UTC', 'UTC', null, null], ['GR', 'Europe/Athens', 'Athens', 37.98, 23.73], ['AE', 'Asia/Dubai', 'Dubai', 25.20, 55.27], ['GB', 'Europe/London', 'London', 51.51, -0.13], ['IT', 'Europe/Rome', 'Rome', 41.90, 12.50], ['NL', 'Europe/Amsterdam', 'Amsterdam', 52.37, 4.90], ['DE', 'Europe/Berlin', 'Berlin', 52.52, 13.41], ['US', 'America/Chicago', 'Dallas', 32.78, -96.80]];
$wxL = []; foreach (['clear', 'partly', 'cloudy', 'fog', 'drizzle', 'rain', 'snow', 'showers', 'storm', 'sunrise', 'sunset', 'daylight', 'now', 'today', 'rain_p', 'wind', 'loading', 'error', 'source', 'close'] as $k) $wxL[$k] = t('wx.' . $k); ?>
<div class="clockstrip" data-home="Europe/Athens" aria-label="<?= tt('clk.title') ?>" data-wx='<?= e(json_encode($wxL, JSON_UNESCAPED_UNICODE)) ?>'>
  <?php foreach ($clocks as $i => [$code, $tz, $city, $lat, $lon]): $isHome = $tz === 'Europe/Athens'; ?><<?= $lat !== null ? 'button type="button"' : 'span' ?> class="clk<?= $isHome ? ' is-home' : '' ?>" data-tz="<?= e($tz) ?>"<?= $lat !== null ? ' data-lat="' . $lat . '" data-lon="' . $lon . '" data-city="' . e($city) . '" aria-haspopup="dialog"' : '' ?> title="<?= e($city) ?> · <?= e($tz) ?><?= $lat !== null ? ' · ' . tt('wx.click') : '' ?>"><b><?= e($code) ?></b><span class="t">--:--</span><?= $code === 'US' ? '<i class="c">' . e($city) . '</i>' : '' ?><i class="d"></i><?= $isHome ? '<i class="w" hidden></i><i class="v" aria-hidden="true">▾</i>' : '' ?></<?= $lat !== null ? 'button' : 'span' ?>><?= $i === 0 ? '<span class="sep"></span>' : '' ?><?php endforeach; ?>
  <span class="spacer"></span><span class="clk-date" data-tz="Europe/Athens"></span>
</div>
<div class="clkpop" id="clkpop" hidden role="dialog" aria-label="<?= tt('wx.title') ?>"></div>
<?= function_exists('lic_banner_html') ? lic_banner_html($active) : '' ?>

<div class="shell">
  <aside class="rail" id="rail">
    <?php $badges = nav_badges(); $groupSlugs = []; $nfc = function_exists('nf_counts') ? nf_counts() : []; ?>
    <?php foreach (nav_groups() as $g): $sum = 0; foreach ($g['items'] as $it) $sum += $badges[$it[0]] ?? 0; $hasHere = in_array($active, array_column($g['items'], 0), true); $unseen = (int)($nfc[$g['key']] ?? 0); ?>
      <?php if ($g['label'] !== ''): ?>
        <button type="button" class="rail-group<?= !empty($g['folded']) && !$hasHere ? ' is-folded' : '' ?>" data-group="<?= e($g['key']) ?>" aria-expanded="<?= !empty($g['folded']) && !$hasHere ? 'false' : 'true' ?>">
          <span class="t"><?= e($g['label']) ?></span><?= $unseen ? '<span class="nfdot" role="img" title="' . e(t('nf.unseen_n', ['n' => $unseen])) . '" aria-label="' . e(t('nf.unseen_n', ['n' => $unseen])) . '"></span>' : '' ?><?= $sum ? '<span class="badge badge--group">' . $sum . '</span>' : '' ?><span class="tw" aria-hidden="true">▾</span>
        </button>
      <?php endif; ?>
      <div class="rail-items" data-group="<?= e($g['key']) ?>">
      <?php foreach ($g['items'] as [$slug, $label, $icon]): $n = $badges[$slug] ?? 0; ?>
        <a class="rail-link<?= $active === $slug ? ' is-here' : '' ?>" href="<?= url($slug) ?>" title="<?= e($label) ?><?= $n ? ' · ' . $n : '' ?>"><span class="ic" aria-hidden="true"><?= $icon ?></span><span class="t"><?= e($label) ?></span><?= $n ? '<span class="badge">' . $n . '</span><span class="dot" aria-hidden="true"></span>' : '' ?></a>
      <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <button type="button" class="rail-collapse" id="railcollapse" title="<?= tt('nav.collapse') ?>" aria-label="<?= tt('nav.collapse') ?>"><span class="t">‹ <?= tt('nav.collapse') ?></span><span class="x">›</span></button>
    <div class="rail-foot">
      <p class="who"><span class="t"><?= e($u['full_name'] ?? '') ?></span><span class="x"><?= avatar_html((int)($u['id'] ?? 0), (string)($u['full_name'] ?? ''), 36) ?></span></p>
      <p class="what"><?= e($u['customer_name'] ?? '') ?></p>
      <form method="post" action="<?= url($active ?: 'dashboard') ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="logout">
        <button class="btn btn--ghost btn--sm" type="submit" style="color:#DDE4EA;border-color:var(--navy-3);">
          <?= tt('nav.signout') ?></button>
      </form>
    </div>
  </aside>
  <div class="scrim"></div>

  <main id="main" class="sheet">
    <?php foreach (flash_pull() as $f): ?>
      <div class="flash flash--<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endforeach; ?>
<?php }

function layout_foot(): void { ?>
    <p class="buildtag"><?= e(CP_NAME) ?> · v<?= e(CP_VERSION) ?> · <?= e(CP_BUILD) ?><?php if (function_exists('lic') && ($LL = lic())): ?> · <?= e(t('lic.licensed_to', ['n' => $LL['licensee'] ?? ''])) ?> · S/N <?= e($LL['serial']) ?><?php endif; ?><?php if (function_exists('is_cp_admin') && is_cp_admin()): ?> · <a href="<?= url('admin_about') ?>"><?= tt('nav.about') ?></a><?php endif; ?></p>
  </main>
</div>
<script nonce="<?= csp_nonce() ?>" src="../assets/portal.js?v=<?= @filemtime(__DIR__ . '/../../assets/portal.js') ?: 1 ?>"></script>
<?php if (function_exists('ai_can') && ai_can((string)($_GET['p'] ?? ''))): ?><script nonce="<?= csp_nonce() ?>">window.GASAI={page:<?= json_encode((string)($_GET['p'] ?? '')) ?>,csrf:<?= json_encode((string)($_SESSION['csrf'] ?? '')) ?>,t:<?= json_encode(['title' => t('ai.title'), 'fill' => t('ai.fill'), 'write' => t('ai.write'), 'translate' => t('ai.translate'), 'ask' => t('ai.ask'), 'ph_fill' => t('ai.ph_fill'), 'ph_write' => t('ai.ph_write'), 'ph_ask' => t('ai.ph_ask'), 'photo' => t('ai.photo'), 'go' => t('ai.go'), 'apply' => t('ai.apply'), 'apply_all' => t('ai.apply_all'), 'proposals' => t('ai.proposals'), 'none' => t('ai.none'), 'busy' => t('ai.busy'), 'improve' => t('ai.improve'), 'to_en' => t('ai.to_en'), 'field' => t('ai.field'), 'close' => t('btn.cancel'), 'review' => t('ai.review'), 'copy' => t('ai.copy'), 'replace' => t('ai.replace'), 'non_en' => t('ai.non_en')], JSON_UNESCAPED_UNICODE) ?>};</script><script nonce="<?= csp_nonce() ?>" src="../assets/ai.js?v=<?= @filemtime(__DIR__ . '/../../assets/ai.js') ?: 1 ?>"></script><?php endif; ?>
</body>
</html>
<?php }

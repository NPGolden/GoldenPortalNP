<?php
/** Website statistics feed — the four Fleet & activity counts for goldenas.com.gr, nothing else.   GAS-CP-R90-001
 *  The public website never talks to the portal: the website's build pipeline pulls web_feed.php once a week with
 *  the site key (Settings › System › Website), writes the numbers into a static JSON file and publishes that. */
declare(strict_types=1);

/** The feed exists when the activity module and the settings table are there. */
function web_has(): bool { return function_exists('act_fleet') && function_exists('cp_setting') && function_exists('lic_has') && lic_has(); }

/** Aggregate counts only — integers, no names, no ids, no dates of anything. Cached for an hour (the website asks weekly). */
function web_counts(): array {
    $fn = function (): array {
        $y = (int) date('Y'); $today = date('Y-m-d');
        $c = ['year' => $y,
              'vessels_year' => count(act_fleet("$y-01-01", $today)),
              'vessels_30d' => count(act_fleet(date('Y-m-d', strtotime('-30 days')), $today)),
              'new_vessels_year' => count(act_new_vessels($y)),
              'active_vessels' => (int) scalar('SELECT COUNT(*) FROM vessels WHERE is_active = 1'),
              'generated_at' => gmdate('c')];
        foreach ($c as $k => $v) if ($k !== 'generated_at') $c[$k] = max(0, (int) $v);
        return $c;
    };
    return function_exists('cache_remember') ? cache_remember('web_counts', 3600, $fn) : $fn();
}

/** Last successful pull by the website (ISO time · masked IP), for the settings page. */
function web_mark_pull(string $ip): void { $m = preg_match('/^\d+\.\d+\.\d+\.\d+$/', $ip) ? preg_replace('/\.\d+$/', '.x', $ip) : substr($ip, 0, 12) . '…'; cp_setting_set('web_last_pull', gmdate('c') . ' ' . $m); }

/** Settings › System › Website actions (administrators only). */
function web_handle_action(string $action): bool {
    if ($action !== 'web_settings_save') return false;
    require_cp_admin();
    if (!empty($_POST['web_rotate_key'])) cp_setting_set('web_site_key', bin2hex(random_bytes(24)));
    if (!empty($_POST['web_disable'])) cp_setting_set('web_site_key', '');
    if (function_exists('cache_forget')) cache_forget('web_counts');
    flash('good', t('sys.saved')); redirect('settings', ['tab' => 'website', '_r' => uniqid()]);
    return true;
}

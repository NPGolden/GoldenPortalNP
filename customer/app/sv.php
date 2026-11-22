<?php
/**
 * Store versions — which version of each Golden A/S app is in the App Store and on Google Play now.          GAS-CP-R97-200
 *
 * The clock job (customer/tick.php → pn_tick) asks the two stores every three hours: Apple through its public lookup
 * service, Google Play from the public page of the app. Nothing is sent to the stores but the id of the app.
 * - GET /api/v1/store (no sign-in, public facts only) tells an app the version in its store, so an older installed
 *   build shows "A new version is available" with a button to the store.
 * - When a store shows a higher version than before, the people who use that app on that kind of phone get one portal
 *   notification and, when switched on, one e-mail with the link to the store.
 * A store that does not answer changes nothing: the last known version stays.
 */
declare(strict_types=1);

/** API app id → App Store id, Google Play package, name. */
const SV_APPS = [
    'engineer' => ['ios' => '6829433629', 'android' => 'gr.goldenas.service',  'name' => 'Golden A/S Service'],
    'pm'       => ['ios' => '6829433663', 'android' => 'gr.goldenas.pm',       'name' => 'Golden PM'],
    'customer' => ['ios' => '6829433786', 'android' => 'gr.goldenas.customer', 'name' => 'Golden A/S Customer'],
    'employee' => ['ios' => '6837805575', 'android' => 'gr.goldenas.employee', 'name' => 'Golden A/S Employee'],
    'hr'       => ['ios' => '6837033425', 'android' => null,                   'name' => 'Golden HR'],
];
const SV_EVERY = 10800;                                  // seconds between two questions to the stores

function sv_valid(string $v): bool { return (bool) preg_match('/^\d{1,4}(\.\d{1,4}){1,3}$/', $v); }
function sv_url(string $app, string $platform): ?string {
    $a = SV_APPS[$app] ?? null; if (!$a || empty($a[$platform])) return null;
    return $platform === 'ios' ? 'https://apps.apple.com/app/id' . $a['ios'] : 'https://play.google.com/store/apps/details?id=' . $a['android'];
}
/** [app => ['ios' => '1.2.3', 'android' => '1.2.3']] as last seen. */
function sv_all(): array {
    $j = json_decode((string)(function_exists('cp_setting') ? cp_setting('sv_versions', '') : ''), true); $o = [];
    if (is_array($j)) foreach (SV_APPS as $app => $_) foreach (['ios', 'android'] as $p) if (isset($j[$app][$p]) && is_string($j[$app][$p]) && sv_valid($j[$app][$p])) $o[$app][$p] = $j[$app][$p];
    return $o;
}
/** What an app is told: the version in its store and the store page. */
function sv_for(string $app, string $platform): array {
    $platform = $platform === 'ios' ? 'ios' : 'android';
    return ['version' => sv_all()[$app][$platform] ?? null, 'url' => sv_url($app, $platform)];
}
function sv_get(string $url): string {
    if (!function_exists('curl_init')) return '';
    $c = curl_init($url); curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; GoldenAS-Portal)', CURLOPT_HTTPHEADER => ['Accept-Language: en']]);
    $r = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE); curl_close($c);
    return $code === 200 && is_string($r) ? $r : '';
}
/** The version a store shows now, or null when it cannot be read. */
function sv_ask(string $app, string $platform): ?string {
    $a = SV_APPS[$app] ?? null; if (!$a || empty($a[$platform])) return null;
    if ($platform === 'ios') { $j = json_decode(sv_get('https://itunes.apple.com/lookup?id=' . $a['ios'] . '&country=gr'), true); $v = (string)($j['results'][0]['version'] ?? ''); }
    else { $h = sv_get('https://play.google.com/store/apps/details?id=' . $a['android'] . '&hl=en&gl=gr'); $v = preg_match('/\[\[\["(\d{1,4}(?:\.\d{1,4}){1,3})"\]\]/', $h, $m) ? $m[1] : ''; }
    return sv_valid($v) ? $v : null;
}
/** Ask the stores when it is time. Returns [checked, changed, announced]. */
function sv_refresh(bool $force = false): array {
    $o = ['checked' => 0, 'changed' => 0, 'told' => 0]; if (!function_exists('cp_setting')) return $o;
    $at = (int) scalar("SELECT v FROM cp_settings WHERE k = 'sv_at'"); if (!$force && $at > time() - SV_EVERY) return $o;
    cp_setting_set('sv_at', (string) time());             // marked first: a store that hangs must not be asked again by the next run
    $cur = sv_all();
    foreach (SV_APPS as $app => $a) foreach (['ios', 'android'] as $p) {
        if (empty($a[$p])) continue; $v = sv_ask($app, $p); if ($v === null) continue; $o['checked']++;
        $old = $cur[$app][$p] ?? null; if ($old === $v) continue;
        if ($old !== null && version_compare($v, $old, '<')) continue;      // a store never goes back; a lower number is a misread page
        $cur[$app][$p] = $v; $o['changed']++; cp_setting_set('sv_versions', json_encode($cur));
        if ($old !== null) { try { $o['told'] += sv_announce($app, $p, $v); } catch (Throwable $e) { error_log('[sv] announce: ' . $e->getMessage()); } }   // the first reading only sets the starting point
    }
    return $o;
}
/** One notification (and one e-mail, when switched on) to every person who uses this app on this kind of phone. */
function sv_announce(string $app, string $platform, string $ver): int {
    $name = SV_APPS[$app]['name']; $url = (string) sv_url($app, $platform); $n = 0; $mail = (string) cp_setting('sv_mail', '1') !== '0' && function_exists('cp_mail');
    $store = $platform === 'ios' ? 'App Store' : 'Google Play';
    foreach (all("SELECT DISTINCT u.id, u.email, u.locale FROM mob_devices d JOIN customer_users u ON u.id = d.user_id AND u.is_active = 1 AND COALESCE(u.is_demo, 0) = 0
                  WHERE d.app_id = ? AND d.platform = ? AND d.revoked_at IS NULL AND d.last_seen_at > NOW() - INTERVAL 120 DAY", [$app, $platform]) as $u) {
        $lang = (string)($u['locale'] ?: 'en'); $t = fn(string $k, array $p = []) => function_exists('pn_t') ? pn_t($lang, $k, $p) : t($k, $p);
        $title = $t('sv.n_t', ['app' => $name, 'v' => $ver]); $body = $t('sv.n_b', ['store' => $store]);
        if (function_exists('nf_push')) $n += nf_push([(int)$u['id']], 'app:update', "app:$app:$platform", $title, $body, 'index.php?p=dashboard', null, null);
        if ($mail && (string)$u['email'] !== '') { try { cp_mail((string)$u['email'], $title, str_replace('\n', "\n", $t('sv.m_b', ['app' => $name, 'v' => $ver, 'store' => $store, 'url' => $url]))); } catch (Throwable $e) { error_log('[sv] mail: ' . $e->getMessage()); } }
    }
    return $n;
}
/** Settings › Push notifications: switch of the e-mail and "ask the stores now". */
function sv_handle_action(string $action): bool {
    if (!in_array($action, ['sv_mail', 'sv_now'], true)) return false; require_cp_admin();
    try {
        if ($action === 'sv_mail') { cp_setting_set('sv_mail', empty($_POST['on']) ? '0' : '1'); flash('good', t('sv.saved')); }
        else { $r = sv_refresh(true); flash('good', t('sv.asked', ['n' => $r['checked'], 'c' => $r['changed'], 't' => $r['told']])); }
    } catch (Throwable $e) { flash('bad', $e->getMessage()); }
    redirect('admin_push'); return true;
}

<?php
/**
 * Golden A/S Customer Portal — mobile API (/customer/api/v1).                      GAS-CP-R52-001
 * Boots the portal headless (same modules as customer/index.php, no cookie session) and lets the
 * authenticated staff account act through the portal's own functions (fsr_*, isr_*, ts_*, tk_*, ai_*,
 * eq_*, wp_*, eng_*, cp_store_*). No table is written here that the portal does not write itself.
 */
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '0');
define('API_ROOT', __DIR__);
define('CP_ROOT', dirname(__DIR__, 2));                 // customer/
const API_VERSION = '0.5.0';
const API_APP_ID  = 'engineer';                          // default app (kept for compatibility); per request see api_app()
const API_APPS    = ['engineer' => '0.5.0', 'pm' => '1.0.0', 'customer' => '1.0.0'];   // app id → default minimum version (R66-001 Golden PM, R68-001 customer app)
/** The client application of this request from X-App (CS-01): 'pm', 'engineer' or 'customer'; anything else falls back to 'engineer' and fails the "Wrong application" check in index.php. */
function api_app(): string { $a = strtolower(trim((string)($_SERVER['HTTP_X_APP'] ?? API_APP_ID))); return isset(API_APPS[$a]) ? $a : API_APP_ID; }

$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
ini_set('session.use_cookies', '0'); ini_set('session.use_only_cookies', '0'); ini_set('session.cache_limiter', '');

foreach (['db', 'i18n', 'helpers', 'auth', 'totp', 'pm', 'pm_actions', 'fsr', 'isr', 'br', 'tm', 'pl', 'eq', 'fd', 'iss', 'rc', 'flt', 'vm', 'rs', 'wf', 'mn', 'rt', 'acc', 'kpi', 'sales', 'pd', 'num', 'pi', 'cf', 'eng', 'ai', 'mt', 'bg', 'wp', 'rl', 'ob', 'et', 'em', 'jo', 'oa', 'ts', 'version', 'tk', 'vh', 'of', 'wk', 'avatar', 'theme', 'ct', 'export', 'nf', 'iv', 'ar', 'rp', 'kb', 'lic', 'td', 'fk', 'wc', 'hr', 'hr2', 'hr3', 'hr4', 'hr5', 'hr6', 'pk'] as $m) {
    $f = CP_ROOT . '/app/' . $m . '.php'; if (is_file($f)) require_once $f;
}
date_default_timezone_set(tz_app());

require_once API_ROOT . '/lib/response.php';
require_once API_ROOT . '/lib/ratelimit.php';
require_once API_ROOT . '/lib/jwt.php';
require_once API_ROOT . '/lib/portal.php';
require_once API_ROOT . '/lib/portal_pm.php';
require_once API_ROOT . '/lib/portal_customer.php';

/** Act as a portal user for the rest of this request (the portal reads $_SESSION['cu_id']). */
function api_act_as(int $uid): array {
    if (session_status() !== PHP_SESSION_ACTIVE) { session_id('api' . bin2hex(random_bytes(8))); session_start(); }
    $_SESSION['cu_id'] = $uid; $_SESSION['epoch'] = (int)(scalar('SELECT session_epoch FROM customer_users WHERE id = ?', [$uid]) ?: 0);
    $u = current_user();
    if (!$u) throw new ApiException(401, 'Account disabled');
    if (api_app() === 'customer') { if (($u['role'] ?? 'customer') !== 'customer' || empty($u['customer_id'])) throw new ApiException(403, 'Customer account required'); }   // R68-001: the customer app is for owner accounts only
    else {
        if (!in_array($u['role'] ?? '', ['staff', 'admin'], true)) throw new ApiException(403, 'Staff account required');
        if (function_exists('hr_only') && hr_only()) throw new ApiException(403, 'This account is limited to the Planning board');   // R51 HR scope: no app access
    }
    $loc = (string)($_SERVER['HTTP_X_LANG'] ?? $u['locale'] ?? 'en'); locale(locale_valid($loc) ? $loc : 'en');
    return $u;
}
/** Private storage for rate limits (never /tmp on shared hosting — see GAS-CP session notes). */
function api_private_dir(): string { $d = CP_ROOT . '/storage/private'; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
/** API settings live in cp_settings (Settings › Mobile apps) with config.php fallbacks. */
function api_setting(string $k, string $def = ''): string { $v = function_exists('cp_setting') ? (string)(cp_setting($k, '') ?? '') : ''; return $v !== '' ? $v : (string)(cfg('api.' . substr($k, 4), $def) ?? $def); }

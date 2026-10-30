<?php
/**
 * Licence — serial number and service contract.                            GAS-CP-R22-001
 *
 * A licence is a signed text block (GASL2.<payload>.<signature>) issued by the
 * vendor with tools of its own (golden-license-kit); the portal only carries the
 * vendor's PUBLIC key (ECDSA P-256 / SHA-256, OpenSSL — present on every shared
 * host) and verifies offline — no call home, no network.
 *
 * Payload (JSON): serial, licensee, product 'cp', domains[] (empty = any; '*.x' ok),
 * instance (optional: bound to this installation's id), contract_start,
 * contract_end, grace_days, max_users (0 = unlimited), brand {name}, vendor
 * {name, email, phone}, issued, notes.
 *
 * States: valid · expiring (≤ 60 d) · grace (contract ended, inside grace_days) ·
 * expired · missing (no key; 14 activation days from first sight) · invalid
 * (bad signature, other domain / instance, other product). expired, invalid and
 * missing-after-activation LOCK the portal: every page shows the licence page;
 * an administrator pastes a new key there. Nothing is deleted when locked.
 *
 *   lic_status()            ['state' => …, 'lic' => payload|null, 'days' => n, 'why' => …]
 *   lic_locked()            true when the portal must not be used
 *   lic_install($text)      verify + store (admin)
 *   lic_banner_html()       top banner for admins (expiring / grace / missing)
 *   lic_instance_id()       this installation's id (shown to the vendor to bind a key)
 */
declare(strict_types=1);

const LIC_PUBKEY = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAEM/bTo4YlyIvQl2hugvFxmCOW+YGB\nd+0bpdl6JtTiHAIrc8CdG48WI9HeDNlKPjHJ4QG37Os3fV2yATU1I/6TVg==\n-----END PUBLIC KEY-----";   // Golden A/S vendor public key (ECDSA P-256)
const LIC_PREFIX = 'GASL2';
const LIC_ACTIVATION_DAYS = 14;        // a portal without a key keeps working this long after the licence module first runs
const LIC_WARN_DAYS = 60;              // "expiring" from this many days before contract_end
const LIC_OPEN_PAGES = ['login', 'twofa', 'license', 'account', 'avatar', 'logout'];     // reachable while locked

function lic_has(): bool {
    static $h = null;
    if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'cp_settings'"); } catch (Throwable $e) { $h = false; } }
    return $h;
}
function cp_setting(string $k, ?string $default = null): ?string {
    if (!lic_has()) return $default;
    static $c = [];
    if (!array_key_exists($k, $c)) { $r = one('SELECT v FROM cp_settings WHERE k = ?', [$k]); $c[$k] = $r ? $r['v'] : null; }
    return $c[$k] ?? $default;
}
function cp_setting_set(string $k, ?string $v): void {
    if (!lic_has()) return;
    q('INSERT INTO cp_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}
/** Random id of this installation, created once; the vendor may bind a licence to it. */
function lic_instance_id(): string {
    $id = cp_setting('instance_id');
    if (!$id && lic_has()) { $id = strtoupper(substr(bin2hex(random_bytes(8)), 0, 16)); $id = substr($id, 0, 4) . '-' . substr($id, 4, 4) . '-' . substr($id, 8, 4) . '-' . substr($id, 12, 4); cp_setting_set('instance_id', $id); }
    return $id ?: '—';
}
function lic_host(): string { return strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))); }

/** Parse and verify a key text. Returns the payload, or throws with the reason. */
function lic_parse(string $text): array {
    $text = preg_replace('/\s+/', '', $text);
    $parts = explode('.', $text);
    if (count($parts) === 3 && $parts[0] === 'GASL1') throw new RuntimeException(t('lic.e_old'));
    if (count($parts) !== 3 || $parts[0] !== LIC_PREFIX) throw new RuntimeException(t('lic.e_format'));
    $b64 = fn(string $s) => base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    $payload = $b64($parts[1]); $sig = $b64($parts[2]);
    if ($payload === false || $sig === false || $sig === '') throw new RuntimeException(t('lic.e_format'));
    if (!function_exists('openssl_verify')) throw new RuntimeException(t('lic.e_openssl'));
    if (openssl_verify($payload, $sig, LIC_PUBKEY, OPENSSL_ALGO_SHA256) !== 1) throw new RuntimeException(t('lic.e_signature'));
    $L = json_decode($payload, true);
    if (!is_array($L) || empty($L['serial']) || empty($L['contract_end'])) throw new RuntimeException(t('lic.e_format'));
    if (($L['product'] ?? 'cp') !== 'cp') throw new RuntimeException(t('lic.e_product'));
    return $L;
}
function lic_domain_ok(array $L): bool {
    $doms = array_filter(array_map('strtolower', (array)($L['domains'] ?? [])));
    if (!$doms) return true;
    $h = lic_host();
    foreach ($doms as $d) {
        if ($d === $h) return true;
        if (str_starts_with($d, '*.') && (str_ends_with($h, substr($d, 1)) || $h === substr($d, 2))) return true;
    }
    return false;
}
/** Evaluate the installed key. Cached per request. */
function lic_status(): array {
    static $S = null;
    if ($S !== null) return $S;
    $today = date('Y-m-d');
    if (!lic_has()) return $S = ['state' => 'nodb', 'lic' => null, 'days' => 0, 'why' => ''];
    // a clock turned back cannot revive a licence: evaluate against the latest date this portal has seen
    $seen = cp_setting('lic_last_seen') ?: $today;
    if ($today > $seen) { cp_setting_set('lic_last_seen', $today); $seen = $today; }
    $first = cp_setting('lic_first_seen');
    if (!$first) { cp_setting_set('lic_first_seen', $today); $first = $today; }
    $text = cp_setting('license');
    if (!$text) {
        $left = LIC_ACTIVATION_DAYS - (int) floor((strtotime($seen) - strtotime($first)) / 86400);
        return $S = ['state' => 'missing', 'lic' => null, 'days' => $left, 'why' => t('lic.why_missing'), 'locked' => $left <= 0];
    }
    try { $L = lic_parse($text); } catch (Throwable $e) { return $S = ['state' => 'invalid', 'lic' => null, 'days' => 0, 'why' => $e->getMessage(), 'locked' => true]; }
    if (!lic_domain_ok($L)) return $S = ['state' => 'invalid', 'lic' => $L, 'days' => 0, 'why' => t('lic.e_domain', ['h' => lic_host()]), 'locked' => true];
    if (!empty($L['instance']) && strcasecmp((string)$L['instance'], lic_instance_id()) !== 0) return $S = ['state' => 'invalid', 'lic' => $L, 'days' => 0, 'why' => t('lic.e_instance'), 'locked' => true];
    if (!empty($L['contract_start']) && $L['contract_start'] > $seen) return $S = ['state' => 'invalid', 'lic' => $L, 'days' => 0, 'why' => t('lic.e_not_yet', ['d' => fmt_date($L['contract_start'])]), 'locked' => true];
    $days = (int) floor((strtotime((string)$L['contract_end']) - strtotime($seen)) / 86400);       // days left (negative = past)
    $grace = (int)($L['grace_days'] ?? 30);
    if ($days >= 0) return $S = ['state' => $days <= LIC_WARN_DAYS ? 'expiring' : 'valid', 'lic' => $L, 'days' => $days, 'why' => '', 'locked' => false];
    if (-$days <= $grace) return $S = ['state' => 'grace', 'lic' => $L, 'days' => $grace + $days, 'why' => t('lic.why_grace'), 'locked' => false];
    return $S = ['state' => 'expired', 'lic' => $L, 'days' => $days, 'why' => t('lic.why_expired'), 'locked' => true];
}
function lic_locked(): bool { return (bool)(lic_status()['locked'] ?? false); }
function lic(): ?array { return lic_status()['lic']; }
/** The licensee's brand name (white label) or the portal's own name. */
function lic_brand(): string { $L = lic(); return trim((string)($L['brand']['name'] ?? '')) ?: t('app.name'); }

function lic_install(string $text): array {
    require_cp_admin();
    $L = lic_parse($text);                       // throws with the reason
    if (!lic_domain_ok($L)) throw new RuntimeException(t('lic.e_domain', ['h' => lic_host()]));
    if (!empty($L['instance']) && strcasecmp((string)$L['instance'], lic_instance_id()) !== 0) throw new RuntimeException(t('lic.e_instance'));
    cp_setting_set('license', preg_replace('/\s+/', '', $text));
    cp_setting_set('lic_installed_at', date('Y-m-d H:i:s'));
    cp_setting_set('lic_installed_by', (string)(current_user()['full_name'] ?? ''));
    cp_setting_set('lic_notified', '');          // expiry reminders start again for the new contract
    return $L;
}
function lic_remove(): void { require_cp_admin(); cp_setting_set('license', null); }

/** Account limit from the licence (0 = none). Throws when one more active account would exceed it. */
function lic_check_users(): void {
    $L = lic(); $max = (int)($L['max_users'] ?? 0);
    if ($max > 0 && (int) scalar('SELECT COUNT(*) FROM customer_users WHERE is_active = 1') >= $max) throw new RuntimeException(t('lic.e_users', ['n' => $max]));
}

/** Expiry reminders to administrators through the notification bell: 60 · 30 · 14 · 7 · 1 days, then daily in grace. */
function lic_notify(): void {
    if (!lic_has() || !function_exists('nf_push') || !nf_has()) return;
    $S = lic_status(); $L = $S['lic']; if (!$L && $S['state'] !== 'missing') return;
    $done = array_filter(explode(',', (string) cp_setting('lic_notified')));
    $mark = null; $title = ''; $kind = 'lic:expiring';
    if ($S['state'] === 'expiring') {                       // one reminder per threshold crossed; thresholds already passed are marked together
        foreach ([60, 30, 14, 7, 1] as $th) if ($S['days'] <= $th && !in_array("e$th", $done, true)) { $mark = "e$th"; $done[] = "e$th"; }
        if ($mark) { $mark = 'E' . $S['days']; $title = t('lic.n_expiring', ['d' => fmt_date($L['contract_end']), 'n' => $S['days']]); }
    }
    elseif ($S['state'] === 'grace') { $mark = 'g' . date('Ymd'); $kind = 'lic:grace'; $title = t('lic.n_grace', ['n' => $S['days']]); }
    elseif ($S['state'] === 'missing' && $S['days'] <= 7) { $mark = 'm' . date('Ymd'); $kind = 'lic:missing'; $title = t('lic.n_missing', ['n' => max(0, $S['days'])]); }
    if (!$mark || in_array($mark, $done, true)) return;
    $done[] = $mark; cp_setting_set('lic_notified', implode(',', array_slice($done, -40)));
    nf_push(nf_admins(), $kind, 'lic:' . $kind, $title, $L ? t('lic.serial') . ' ' . $L['serial'] : null, url('license'), null, null);
}

/** Banner under the top bar — administrators only, never on the licence page itself. */
function lic_banner_html(string $active): string {
    if (!function_exists('is_cp_admin') || !is_cp_admin() || $active === 'license') return '';
    $S = lic_status(); $msg = '';
    switch ($S['state']) {
        case 'nodb':     $msg = t('lic.b_nodb'); break;
        case 'missing':  $msg = t('lic.b_missing', ['n' => max(0, $S['days'])]); break;
        case 'expiring': if ($S['days'] <= 30) $msg = t('lic.b_expiring', ['d' => fmt_date($S['lic']['contract_end']), 'n' => $S['days']]); break;
        case 'grace':    $msg = t('lic.b_grace', ['n' => $S['days']]); break;
    }
    if ($msg === '') return '';
    return '<div class="licbar licbar--' . e($S['state']) . ' no-print"><span>⚠ ' . e($msg) . '</span><a class="btn btn--sm btn--ghost" href="' . url('license') . '">' . tt('lic.open') . ' ›</a></div>';
}
function lic_state_pill(string $st): string {
    $tone = ['valid' => 'green', 'expiring' => 'gold', 'grace' => 'gold', 'expired' => 'red', 'invalid' => 'red', 'missing' => 'red', 'nodb' => 'mute'][$st] ?? 'mute';
    return pill($tone, t('lic.s_' . $st));
}

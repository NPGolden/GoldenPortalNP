<?php
/**
 * SoftOne ERP client (via Connect Line web services).   GAS-ERP-CP-001
 *
 * Implements the documented flow:
 *   1. POST {service:"login"}        -> clientID + installation (objs[0])
 *   2. POST {service:"authenticate"} -> final clientID used on every call
 *   3. POST {service:"getWorkingProjects"} at the Additional Endpoint
 *
 * All calls are JSON with Content-Type: application/json.
 * The authenticated clientID is cached in storage/softone_token.json and
 * refreshed automatically once if a call comes back unauthenticated.
 */
declare(strict_types=1);

function s1_cfg(string $k, $d = null) { return cfg('softone.' . $k, $d); }

/** Raw POST — returns [http, content_type, body] without judging the body. */
function s1_post_raw(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',          // accept + auto-decode gzip/deflate
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 60,
    ]);
    $body  = curl_exec($ch);
    $err   = curl_error($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("SoftOne: transport error: {$err}");
    return [$http, $ctype, (string) $body];
}

/**
 * Decode a SoftOne response, tolerating the quirks its backend is known for:
 *   - a UTF-8 BOM (or stray bytes) before the JSON;
 *   - raw control characters (CR/LF) inside string values, which are
 *     technically invalid JSON but common in Delphi-era serialisers.
 * On failure the exception carries the HTTP code, Content-Type and the
 * first bytes of the actual body, so the cause is visible, not hidden.
 */
function s1_decode(string $label, int $http, string $ctype, string $body): array {
    $b = $body;

    // Strip BOM / leading junk down to the first JSON token.
    $b = preg_replace('/^\xEF\xBB\xBF/', '', $b);
    $start = strcspn($b, '{[');
    if ($start > 0 && $start < strlen($b)) $b = substr($b, $start);

    $j = json_decode($b, true);
    if (!is_array($j)) {
        // Second attempt: neutralise raw control characters, ignore bad UTF-8.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', ' ', $b);
        $j = json_decode($clean, true, 512, JSON_INVALID_UTF8_IGNORE);
    }
    if (!is_array($j)) {
        $peek = trim(mb_substr($body, 0, 300));
        $peek = preg_replace('/\s+/', ' ', $peek);
        throw new RuntimeException(
            "SoftOne {$label}: unparseable response (HTTP {$http}, Content-Type: " .
            ($ctype ?: 'none') . "). Body starts: {$peek}"
        );
    }
    return $j;
}

function s1_post(string $url, array $payload, string $label = 'call'): array {
    [$http, $ctype, $body] = s1_post_raw($url, $payload);
    return s1_decode($label, $http, $ctype, $body);
}

/** Full login -> authenticate handshake. Returns the authenticated clientID. */
function s1_handshake(): string {
    $login = s1_post((string) s1_cfg('endpoint'), [
        'service'  => 'login',
        'username' => (string) s1_cfg('username'),
        'password' => (string) s1_cfg('password'),
        'appId'    => (string) s1_cfg('appid'),
    ], 'login');
    if (empty($login['success'])) {
        throw new RuntimeException('SoftOne login failed: ' . ($login['error'] ?? 'unknown error'));
    }
    $inst = $login['objs'][0] ?? [];

    $auth = s1_post((string) s1_cfg('endpoint'), [
        'service'  => 'authenticate',
        'clientID' => (string) $login['clientID'],
        'COMPANY'  => (string) (s1_cfg('company') ?: ($inst['COMPANY'] ?? '')),
        'BRANCH'   => (string) (s1_cfg('branch')  ?: ($inst['BRANCH']  ?? '')),
        'MODULE'   => (string) (s1_cfg('module')  ?: ($inst['MODULE']  ?? '')),
        'REFID'    => (string) (s1_cfg('refid')   ?: ($inst['REFID']   ?? '')),
    ], 'authenticate');
    if (empty($auth['success'])) {
        throw new RuntimeException('SoftOne authenticate failed: ' . ($auth['error'] ?? 'unknown error'));
    }
    return (string) $auth['clientID'];
}

function s1_token_file(): string { return dirname(__DIR__) . '/storage/softone_token.json'; }

/** Cached authenticated clientID (sessions on the SoftOne side do expire). */
function s1_client_id(bool $force = false): string {
    $f = s1_token_file();
    if (!$force && is_file($f)) {
        $t = json_decode((string) file_get_contents($f), true);
        // Trust a cached token for up to 20 minutes; refreshed on failure anyway.
        if (!empty($t['clientID']) && time() - (int)($t['ts'] ?? 0) < 1200) {
            return (string) $t['clientID'];
        }
    }
    $id = s1_handshake();
    @file_put_contents($f, json_encode(['clientID' => $id, 'ts' => time()]));
    return $id;
}

/** Call a named service on the Additional Endpoint, re-authing once if needed. */
function s1_call(string $service, array $extra = []): array {
    $do = function (string $clientId) use ($service, $extra): array {
        // Connect Line's documentation mixes key casings (clientid/CLIENTID,
        // appId/appid) between sections, so send all spellings — their
        // parser reads whichever it expects, the rest are ignored.
        return s1_post((string) s1_cfg('api_endpoint'), array_merge([
            'service'  => $service,
            'clientid' => $clientId,
            'clientID' => $clientId,
            'CLIENTID' => $clientId,
            'appId'    => (string) s1_cfg('appid'),
            'appid'    => (string) s1_cfg('appid'),
        ], $extra), $service);
    };
    $r = $do(s1_client_id());
    if (empty($r['success'])) {
        $msg = strtolower((string)($r['error'] ?? ''));
        if (str_contains($msg, 'login') || str_contains($msg, 'client') || str_contains($msg, 'auth')) {
            $r = $do(s1_client_id(true));           // stale token — one retry
        }
    }
    if (empty($r['success'])) {
        throw new RuntimeException("SoftOne {$service} failed: " . ($r['error'] ?? 'unknown error'));
    }
    return $r;
}

/** Active projects, as documented in §4.1.1 (Άντληση Στοιχείων Έργων). */
function s1_get_working_projects(): array {
    $r = s1_call((string) s1_cfg('projects_service', 'getWorkingProjects'));
    return is_array($r['data'] ?? null) ? $r['data'] : [];
}

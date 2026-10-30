<?php
/**
 * Access tokens: HS256 JWT, 15 min, claims sub / app / dev / br. Refresh tokens: rotating, device-bound, reuse detected
 * (mob_refresh_tokens — shared layout with the PM app, GAS-2026-PORTAL-MOB-001). Secret: cp_settings api_jwt_secret
 * (created on first use, 32 random bytes, encrypted like the AI key is not needed — it is a server secret) or config api.jwt_secret.
 */
declare(strict_types=1);
final class Jwt {
    public static function secret(): string {
        $s = api_setting('api_jwt_secret', ''); if ($s !== '') return $s;
        $s = bin2hex(random_bytes(32)); cp_setting_set('api_jwt_secret', $s); return $s;
    }
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    private static function unb64(string $s): string { return base64_decode(strtr($s, '-_', '+/')) ?: ''; }
    public static function issue(array $u, int $deviceId): string {
        $h = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $p = self::b64(json_encode(['sub' => (int)$u['id'], 'app' => API_APP_ID, 'dev' => $deviceId, 'br' => (int)($u['branch_id'] ?? 0), 'ep' => (int)($u['session_epoch'] ?? 0), 'iat' => time(), 'exp' => time() + 15 * 60]));   // ep = session epoch (R49 "sign out everywhere")
        return "$h.$p." . self::b64(hash_hmac('sha256', "$h.$p", self::secret(), true));
    }
    public static function verify(string $jwt): ?array {
        $p = explode('.', $jwt); if (count($p) !== 3) return null; [$h, $b, $s] = $p;
        if (!hash_equals(self::b64(hash_hmac('sha256', "$h.$b", self::secret(), true)), $s)) return null;
        $c = json_decode(self::unb64($b), true); return ($c && ($c['exp'] ?? 0) > time()) ? $c : null;
    }
    /** Bearer → portal user (acting), device id on the user array. */
    public static function requireUser(): array {
        $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if (!preg_match('/^Bearer\s+(.+)$/i', $hdr, $m)) throw new ApiException(401, 'Unauthorized');
        $c = self::verify(trim($m[1])); if (!$c) throw new ApiException(401, 'Token expired');
        if (($c['app'] ?? '') !== API_APP_ID) throw new ApiException(403, 'Token issued for another application');   // CS-01
        $d = one('SELECT id, revoked_at, platform FROM mob_devices WHERE id = ?', [(int)$c['dev']]);
        if (!$d || $d['revoked_at']) throw new ApiException(401, 'Device revoked');
        $u = api_act_as((int)$c['sub']);
        if (array_key_exists('session_epoch', $u) && (int)($c['ep'] ?? 0) !== (int)$u['session_epoch']) throw new ApiException(401, 'Signed out everywhere');   // R52: portal "Sign out everywhere" also ends app sessions
        if (function_exists('lic_locked') && lic_locked()) throw new ApiException(402, 'Portal licence locked');
        q('UPDATE mob_devices SET last_seen_at = NOW(), app_version = COALESCE(?, app_version) WHERE id = ?', [$_SERVER['HTTP_X_APP_VERSION'] ?? null, (int)$c['dev']]);
        $u['device_id'] = (int)$c['dev']; $u['platform'] = (string)$d['platform'];
        return $u;
    }
    public static function registerDevice(int $uid, array $dev): int {
        $cid = mb_substr((string)($dev['device_id'] ?? ''), 0, 64); $plat = in_array($dev['platform'] ?? '', ['ios', 'android', 'web'], true) ? $dev['platform'] : 'web';
        $x = one('SELECT id FROM mob_devices WHERE user_id = ? AND app_id = ? AND client_device_id = ? AND revoked_at IS NULL', [$uid, API_APP_ID, $cid]);
        if ($x) { q('UPDATE mob_devices SET platform = ?, model = ?, app_version = ?, last_seen_at = NOW() WHERE id = ?', [$plat, mb_substr((string)($dev['ua'] ?? ''), 0, 120), (string)($dev['app_version'] ?? ''), (int)$x['id']]); return (int)$x['id']; }
        q('INSERT INTO mob_devices (user_id, app_id, client_device_id, platform, model, app_version, enrolled_at, last_seen_at) VALUES (?,?,?,?,?,?,NOW(),NOW())', [$uid, API_APP_ID, $cid, $plat, mb_substr((string)($dev['ua'] ?? ''), 0, 120), (string)($dev['app_version'] ?? '')]);
        return (int) db()->lastInsertId();
    }
    public static function issueRefresh(int $deviceId, string $platform, ?int $rotatedFrom = null): string {
        $raw = bin2hex(random_bytes(32)); $days = $platform === 'web' ? 7 : 30;   // CS-05: browser install keeps a shorter token life
        q('INSERT INTO mob_refresh_tokens (device_id, token_hash, issued_at, expires_at, rotated_from) VALUES (?,?,NOW(),DATE_ADD(NOW(), INTERVAL ? DAY),?)', [$deviceId, hash('sha256', $raw), $days, $rotatedFrom]);
        if ($rotatedFrom) q('UPDATE mob_refresh_tokens SET rotated_to = ? WHERE id = ?', [(int) db()->lastInsertId(), $rotatedFrom]);
        return $raw;
    }
    public static function consumeRefresh(string $raw): array {
        $r = one('SELECT t.*, d.user_id, d.platform FROM mob_refresh_tokens t JOIN mob_devices d ON d.id = t.device_id WHERE t.token_hash = ?', [hash('sha256', $raw)]);
        if (!$r || $r['revoked_at'] || strtotime((string)$r['expires_at']) < time()) throw new ApiException(401, 'Refresh token invalid');
        if ($r['rotated_to']) { q('UPDATE mob_refresh_tokens SET revoked_at = NOW() WHERE device_id = ? AND revoked_at IS NULL', [(int)$r['device_id']]); throw new ApiException(401, 'Refresh token reuse detected'); }
        return $r;
    }
}

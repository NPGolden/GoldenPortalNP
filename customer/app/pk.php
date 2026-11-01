<?php
/** Passkeys (WebAuthn / FIDO2) — sign in to the portal and the mobile apps with Face ID, Touch ID, fingerprint or Windows Hello.   GAS-CP-R71-001
 *
 *  Self-contained (no library): minimal CBOR decoder, COSE → PEM for ES256 (P-256) and RS256, attestation parsing with
 *  attestation format ignored ("none" is requested — we trust the authenticator the user enrols while signed in, like the
 *  authenticator app), assertion verification with openssl.  A passkey assertion with user verification is possession +
 *  biometric/PIN, so it satisfies the two-factor policy on its own.
 *
 *  Security rules enforced here:  challenge single-use and 5 min;  origin must be one of pk_origins();  rpIdHash must match
 *  pk_rp_id();  UP and UV flags required;  signature counter must increase when the authenticator reports one (a clone is
 *  refused and logged);  registration needs a full session and, when the account has an authenticator, a fresh code;
 *  credentials are bound to the account they were enrolled on;  the administrator can switch passkeys off (sec_passkeys).
 */
declare(strict_types=1);

/* ------------------------------------------------------------------ settings */
function pk_has(): bool { static $h = null; if ($h === null) { try { $h = (bool) scalar("SHOW TABLES LIKE 'webauthn_credentials'"); } catch (Throwable $e) { $h = false; } } return $h; }
function pk_enabled(): bool { return pk_has() && (function_exists('cp_setting') ? cp_setting('sec_passkeys', '1') : '1') === '1'; }
/** Relying-party id: the portal's host (goldensas.com), overridable with sec_passkey_rpid. */
function pk_rp_id(): string { $v = function_exists('cp_setting') ? (string) cp_setting('sec_passkey_rpid', '') : ''; if ($v !== '') return $v; return strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))); }
/** Origins accepted in clientDataJSON: https://<rpid>, https://www.<rpid>, and (sandbox only) the http host. */
function pk_origins(): array { $rp = pk_rp_id(); $o = ['https://' . $rp, 'https://www.' . $rp]; $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? '')); if ($host !== '' && (str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost'))) { $o[] = 'http://' . $host; } $x = function_exists('cp_setting') ? (string) cp_setting('sec_passkey_origins', '') : ''; foreach (array_filter(array_map('trim', explode(',', $x))) as $e) $o[] = $e; return array_values(array_unique($o)); }

/* ------------------------------------------------------------------ encoding */
function pk_b64u(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }
function pk_b64u_dec(string $s): string { $s = strtr($s, '-_', '+/'); $r = base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4), true); if ($r === false) throw new RuntimeException('bad base64url'); return $r; }

/** Minimal CBOR decoder (RFC 8949): ints, byte/text strings, arrays, maps, simple values. Returns [value, bytes consumed]. */
function pk_cbor(string $d, int $o = 0): array {
    if ($o >= strlen($d)) throw new RuntimeException('cbor: eof');
    $ib = ord($d[$o]); $mt = $ib >> 5; $ai = $ib & 0x1f; $o++;
    $len = function () use (&$o, $d, $ai): int { if ($ai < 24) return $ai; $n = [24 => 1, 25 => 2, 26 => 4, 27 => 8][$ai] ?? null; if ($n === null) throw new RuntimeException('cbor: indefinite'); if ($o + $n > strlen($d)) throw new RuntimeException('cbor: eof'); $v = 0; for ($i = 0; $i < $n; $i++) $v = $v * 256 + ord($d[$o + $i]); $o += $n; return $v; };
    switch ($mt) {
        case 0: return [$len(), $o];
        case 1: return [-1 - $len(), $o];
        case 2: $n = $len(); if ($o + $n > strlen($d)) throw new RuntimeException('cbor: eof'); return [substr($d, $o, $n), $o + $n];
        case 3: $n = $len(); if ($o + $n > strlen($d)) throw new RuntimeException('cbor: eof'); return [substr($d, $o, $n), $o + $n];
        case 4: $n = $len(); $a = []; for ($i = 0; $i < $n; $i++) { [$v, $o] = pk_cbor($d, $o); $a[] = $v; } return [$a, $o];
        case 5: $n = $len(); $m = []; for ($i = 0; $i < $n; $i++) { [$k, $o] = pk_cbor($d, $o); [$v, $o] = pk_cbor($d, $o); $m[is_int($k) || is_string($k) ? $k : json_encode($k)] = $v; } return [$m, $o];
        case 7: if ($ai === 20) return [false, $o]; if ($ai === 21) return [true, $o]; if ($ai === 22) return [null, $o]; throw new RuntimeException('cbor: simple');
        default: throw new RuntimeException('cbor: tag');
    }
}

/** COSE_Key map → [PEM public key, alg]. ES256 (kty 2, crv 1) and RS256 (kty 3). */
function pk_cose_to_pem(array $k): array {
    $kty = (int)($k[1] ?? 0); $alg = (int)($k[3] ?? 0);
    if ($kty === 2 && (int)($k[-1] ?? 0) === 1 && $alg === -7) {
        $x = (string)($k[-2] ?? ''); $y = (string)($k[-3] ?? ''); if (strlen($x) !== 32 || strlen($y) !== 32) throw new RuntimeException('bad EC key');
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x . $y;
    } elseif ($kty === 3 && $alg === -257) {
        $n = (string)($k[-1] ?? ''); $e = (string)($k[-2] ?? ''); if ($n === '' || $e === '') throw new RuntimeException('bad RSA key');
        $int = fn(string $b) => (ord($b[0]) & 0x80 ? "\x00" : '') . $b; $dl = function (int $n): string { if ($n < 128) return chr($n); $b = ltrim(pack('N', $n), "\x00"); return chr(0x80 | strlen($b)) . $b; };
        $seq = "\x02" . $dl(strlen($int($n))) . $int($n) . "\x02" . $dl(strlen($int($e))) . $int($e); $rsa = "\x30" . $dl(strlen($seq)) . $seq;
        $bit = "\x00" . $rsa; $algid = hex2bin('300d06092a864886f70d0101010500'); $body = $algid . "\x03" . $dl(strlen($bit)) . $bit; $der = "\x30" . $dl(strlen($body)) . $body;
    } else throw new RuntimeException('unsupported key type');
    return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n", $alg];
}

/** authenticatorData → ['rpIdHash','flags','signCount','aaguid','credId','coseKey'(array)|null]. */
function pk_parse_auth_data(string $ad): array {
    if (strlen($ad) < 37) throw new RuntimeException('authData short');
    $r = ['rpIdHash' => substr($ad, 0, 32), 'flags' => ord($ad[32]), 'signCount' => unpack('N', substr($ad, 33, 4))[1], 'aaguid' => null, 'credId' => null, 'coseKey' => null];
    if ($r['flags'] & 0x40) {   // attested credential data
        if (strlen($ad) < 55) throw new RuntimeException('authData short');
        $r['aaguid'] = substr($ad, 37, 16); $l = unpack('n', substr($ad, 53, 2))[1]; $r['credId'] = substr($ad, 55, $l);
        [$key] = pk_cbor($ad, 55 + $l); if (!is_array($key)) throw new RuntimeException('bad key'); $r['coseKey'] = $key;
    }
    return $r;
}
function pk_aaguid_str(?string $b): ?string { if ($b === null || strlen($b) !== 16) return null; $h = bin2hex($b); return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20); }

/** clientDataJSON checks shared by registration and assertion. */
function pk_check_client_data(string $json, string $type, string $challenge): array {
    $c = json_decode($json, true); if (!is_array($c)) throw new RuntimeException('clientData');
    if (($c['type'] ?? '') !== $type) throw new RuntimeException('type');
    if (!hash_equals(pk_b64u($challenge), (string)($c['challenge'] ?? ''))) throw new RuntimeException('challenge');
    if (!in_array((string)($c['origin'] ?? ''), pk_origins(), true)) throw new RuntimeException('origin');
    return $c;
}

/* ------------------------------------------------------------------ challenges */
/** New challenge for the web session ($_SESSION) or the API (table row with id). */
function pk_challenge_new(string $kind, ?int $uid, string $via): array {
    $ch = random_bytes(32);
    if ($via === 'session') { $_SESSION['pk_' . $kind] = ['c' => pk_b64u($ch), 'uid' => $uid, 'exp' => time() + 300]; return ['challenge' => $ch, 'id' => null]; }
    $id = bin2hex(random_bytes(16)); q('INSERT INTO webauthn_challenges (id, challenge, kind, user_id, app, created_at, expires_at) VALUES (?,?,?,?,?,NOW(),NOW() + INTERVAL 5 MINUTE)', [$id, $ch, $kind, $uid, function_exists('api_app') ? api_app() : 'web']);
    if (random_int(1, 20) === 1) q('DELETE FROM webauthn_challenges WHERE expires_at < NOW()');
    return ['challenge' => $ch, 'id' => $id];
}
/** Take (and burn) the challenge; null when missing / expired. */
function pk_challenge_take(string $kind, string $via, ?string $id = null): ?array {
    if ($via === 'session') { $s = $_SESSION['pk_' . $kind] ?? null; unset($_SESSION['pk_' . $kind]); if (!$s || $s['exp'] < time()) return null; return ['challenge' => pk_b64u_dec($s['c']), 'uid' => $s['uid']]; }
    if (!$id || !preg_match('/^[a-f0-9]{32}$/', $id)) return null;
    $r = one('SELECT * FROM webauthn_challenges WHERE id = ? AND kind = ? AND expires_at > NOW()', [$id, $kind]); q('DELETE FROM webauthn_challenges WHERE id = ?', [$id]);
    return $r ? ['challenge' => (string)$r['challenge'], 'uid' => $r['user_id'] !== null ? (int)$r['user_id'] : null] : null;
}

/* ------------------------------------------------------------------ registration */
/** PublicKeyCredentialCreationOptions for a signed-in user (challenge raw bytes supplied). */
function pk_registration_options(array $u, string $challenge): array {
    $exclude = array_map(fn($r) => ['type' => 'public-key', 'id' => pk_b64u((string)$r['cred_id'])], all('SELECT cred_id FROM webauthn_credentials WHERE user_id = ?', [(int)$u['id']]));
    return ['challenge' => pk_b64u($challenge), 'rp' => ['id' => pk_rp_id(), 'name' => 'Golden A/S Portal'],
        'user' => ['id' => pk_b64u('gascp:' . (int)$u['id']), 'name' => (string)$u['email'], 'displayName' => (string)$u['full_name']],
        'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7], ['type' => 'public-key', 'alg' => -257]],
        'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
        'excludeCredentials' => $exclude, 'attestation' => 'none', 'timeout' => 60000];
}
/** Verify a registration response and store the credential. $cred = the JSON the browser produced (PublicKeyCredential.toJSON shape). */
function pk_register_finish(array $u, string $challenge, array $cred, string $name, ?int $deviceId = null): int {
    $resp = (array)($cred['response'] ?? []);
    pk_check_client_data(pk_b64u_dec((string)($resp['clientDataJSON'] ?? '')), 'webauthn.create', $challenge);
    [$att] = pk_cbor(pk_b64u_dec((string)($resp['attestationObject'] ?? ''))); if (!is_array($att) || !isset($att['authData'])) throw new RuntimeException('attestation');
    $ad = pk_parse_auth_data((string)$att['authData']);
    if (!hash_equals(hash('sha256', pk_rp_id(), true), $ad['rpIdHash'])) throw new RuntimeException('rpId');
    if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) throw new RuntimeException('user verification');
    if ($ad['credId'] === null || $ad['coseKey'] === null) throw new RuntimeException('no credential');
    if (!hash_equals(pk_b64u($ad['credId']), (string)($cred['rawId'] ?? $cred['id'] ?? ''))) throw new RuntimeException('credential id');
    [$pem, $alg] = pk_cose_to_pem($ad['coseKey']);
    if (scalar('SELECT COUNT(*) FROM webauthn_credentials WHERE cred_id = ?', [$ad['credId']])) throw new RuntimeException('already registered');
    if ((int) scalar('SELECT COUNT(*) FROM webauthn_credentials WHERE user_id = ?', [(int)$u['id']]) >= 10) throw new RuntimeException('too many');
    $tr = implode(',', array_slice(array_filter(array_map(fn($t) => preg_replace('/[^a-z-]/', '', (string)$t), (array)($resp['transports'] ?? []))), 0, 6));
    $name = mb_substr(trim($name) ?: pk_guess_name(), 0, 80);
    q('INSERT INTO webauthn_credentials (user_id, cred_id, public_key, alg, sign_count, aaguid, transports, name, backup_eligible, device_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
      [(int)$u['id'], $ad['credId'], $pem, $alg, (int)$ad['signCount'], pk_aaguid_str($ad['aaguid']), $tr, $name, ($ad['flags'] & 0x08) ? 1 : 0, $deviceId]);
    $id = (int) db()->lastInsertId();
    if (function_exists('login_log')) try { login_log((int)$u['id'], (string)$u['email'], 'passkey_added'); } catch (Throwable $e) {}
    if (function_exists('nf_push')) try { nf_push([(int)$u['id']], 'sec:passkey', 'sec:passkey:' . $id, t('pk.nf_added', ['n' => $name]), t('pk.nf_added_body'), url('account') . '#passkeys', null, 'Portal'); } catch (Throwable $e) {}
    return $id;
}
function pk_guess_name(): string { $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? ''); if (preg_match('/iPhone|iPad/', $ua)) return 'iPhone / iPad'; if (preg_match('/Android/', $ua)) return 'Android'; if (preg_match('/Macintosh/', $ua)) return 'Mac'; if (preg_match('/Windows/', $ua)) return 'Windows PC'; return 'Device'; }

/* ------------------------------------------------------------------ assertion (sign-in) */
function pk_login_options(string $challenge): array { return ['challenge' => pk_b64u($challenge), 'rpId' => pk_rp_id(), 'allowCredentials' => [], 'userVerification' => 'required', 'timeout' => 60000]; }
/** Verify an assertion; returns the user row (active, not locked) or throws. Updates the counter and last_used. */
function pk_assert_finish(string $challenge, array $cred): array {
    $resp = (array)($cred['response'] ?? []); $rawId = (string)($cred['rawId'] ?? $cred['id'] ?? '');
    $row = $rawId !== '' ? one('SELECT * FROM webauthn_credentials WHERE cred_id = ?', [pk_b64u_dec($rawId)]) : null;
    if (!$row) { if (function_exists('login_log')) try { login_log(null, '', 'passkey_failed'); } catch (Throwable $e) {} throw new RuntimeException('unknown credential'); }
    $u = one('SELECT cu.*, c.is_active AS c_active FROM customer_users cu LEFT JOIN customers c ON c.id = cu.customer_id WHERE cu.id = ?', [(int)$row['user_id']]);
    if (!$u || !$u['is_active'] || ($u['customer_id'] !== null && !$u['c_active'])) throw new RuntimeException('account');
    if (!empty($u['locked_until']) && strtotime((string)$u['locked_until']) > time()) throw new RuntimeException('locked');
    $uh = (string)($resp['userHandle'] ?? ''); if ($uh !== '' && pk_b64u_dec($uh) !== 'gascp:' . (int)$u['id']) throw new RuntimeException('user handle');
    $cdj = pk_b64u_dec((string)($resp['clientDataJSON'] ?? '')); pk_check_client_data($cdj, 'webauthn.get', $challenge);
    $adRaw = pk_b64u_dec((string)($resp['authenticatorData'] ?? '')); $ad = pk_parse_auth_data($adRaw);
    if (!hash_equals(hash('sha256', pk_rp_id(), true), $ad['rpIdHash'])) throw new RuntimeException('rpId');
    if (!($ad['flags'] & 0x01) || !($ad['flags'] & 0x04)) throw new RuntimeException('user verification');
    $sig = pk_b64u_dec((string)($resp['signature'] ?? '')); $signed = $adRaw . hash('sha256', $cdj, true);
    $key = openssl_pkey_get_public((string)$row['public_key']); if (!$key) throw new RuntimeException('key');
    if (openssl_verify($signed, $sig, $key, OPENSSL_ALGO_SHA256) !== 1) { pk_fail($u, 'signature'); }
    if (($ad['signCount'] > 0 || (int)$row['sign_count'] > 0) && $ad['signCount'] <= (int)$row['sign_count']) { pk_fail($u, 'counter'); }   // cloned authenticator
    q('UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?', [(int)$ad['signCount'], (int)$row['id']]);
    $u['_passkey'] = ['id' => (int)$row['id'], 'name' => (string)$row['name']];
    return $u;
}
function pk_fail(array $u, string $why): never { if (function_exists('login_log')) try { login_log((int)$u['id'], (string)$u['email'], 'passkey_failed'); } catch (Throwable $e) {} if ($why === 'counter' && function_exists('sec_notify_locked')) try { sec_notify_locked($u); } catch (Throwable $e) {} throw new RuntimeException($why); }

/* ------------------------------------------------------------------ account */
function pk_list(int $uid): array { return pk_has() ? all('SELECT id, name, aaguid, transports, backup_eligible, created_at, last_used_at, device_id FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at', [$uid]) : []; }
function pk_remove(int $uid, int $id, bool $admin = false): void {
    $r = one('SELECT * FROM webauthn_credentials WHERE id = ?' . ($admin ? '' : ' AND user_id = ?'), $admin ? [$id] : [$id, $uid]); if (!$r) throw new RuntimeException(t('err.notfound'));
    q('DELETE FROM webauthn_credentials WHERE id = ?', [$id]);
    $u = one('SELECT id, email FROM customer_users WHERE id = ?', [(int)$r['user_id']]);
    if ($u && function_exists('login_log')) try { login_log((int)$u['id'], (string)$u['email'], 'passkey_removed'); } catch (Throwable $e) {}
    if ($u && function_exists('nf_push')) try { nf_push([(int)$u['id']], 'sec:passkey', 'sec:passkey_rm:' . $id, t('pk.nf_removed', ['n' => (string)$r['name']]), null, url('account') . '#passkeys', null, 'Portal'); } catch (Throwable $e) {}
}
/** Step-up for enrolment: accounts with an authenticator must give a fresh code (web and API). */
function pk_stepup_ok(array $u, ?string $code): bool { if (empty($u['totp_enabled']) || empty($u['totp_secret'])) return true; return $code !== null && $code !== '' && totp_verify((string)$u['totp_secret'], $code); }

/* ------------------------------------------------------------------ web endpoint  ?p=pk  (JSON, X-CSRF header for signed-in calls) */
function pk_endpoint(): void {
    header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
    $out = function (array $r, int $code = 200): never { http_response_code($code); echo json_encode($r, JSON_UNESCAPED_UNICODE); exit; };
    if (!pk_enabled()) $out(['ok' => false, 'error' => t('pk.off')], 403);
    $body = json_decode((string) file_get_contents('php://input'), true); if (!is_array($body)) $body = [];
    $op = (string)($body['op'] ?? '');
    try {
        if ($op === 'login_options') {
            if (!pk_rate_ok()) $out(['ok' => false, 'error' => t('login.lockednow')], 429);
            $c = pk_challenge_new('auth', null, 'session'); $out(['ok' => true, 'options' => pk_login_options($c['challenge'])]);
        }
        if ($op === 'login') {
            if (!pk_rate_ok()) $out(['ok' => false, 'error' => t('login.lockednow')], 429);
            $c = pk_challenge_take('auth', 'session'); if (!$c) $out(['ok' => false, 'error' => t('pk.expired')], 400);
            $u = pk_assert_finish($c['challenge'], (array)($body['credential'] ?? []));
            finish_login((int)$u['id']); try { login_log((int)$u['id'], (string)$u['email'], 'passkey'); } catch (Throwable $e) {}
            $out(['ok' => true, 'redirect' => url('dashboard')]);
        }
        // everything below needs a signed-in session and the CSRF header
        $u = current_user(); if (!$u || !hash_equals($_SESSION['csrf'] ?? '', (string)($_SERVER['HTTP_X_CSRF'] ?? ''))) $out(['ok' => false, 'error' => t('err.noaccess')], 403);
        if ($op === 'reg_options') {
            if (!pk_stepup_ok($u, isset($body['code']) ? (string)$body['code'] : null)) $out(['ok' => false, 'error' => t('pk.code_needed'), 'code' => true], 401);
            $c = pk_challenge_new('reg', (int)$u['id'], 'session'); $out(['ok' => true, 'options' => pk_registration_options($u, $c['challenge'])]);
        }
        if ($op === 'register') {
            $c = pk_challenge_take('reg', 'session'); if (!$c || $c['uid'] !== (int)$u['id']) $out(['ok' => false, 'error' => t('pk.expired')], 400);
            $id = pk_register_finish($u, $c['challenge'], (array)($body['credential'] ?? []), (string)($body['name'] ?? '')); $out(['ok' => true, 'id' => $id]);
        }
        $out(['ok' => false, 'error' => 'op'], 400);
    } catch (RuntimeException $e) { $out(['ok' => false, 'error' => t('pk.failed') . ' (' . $e->getMessage() . ')'], 400); }
}
/** 10 passkey attempts per minute per address (shares the spirit of the password throttle). */
function pk_rate_ok(): bool { try { $ip = trim(explode(',', (string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''))[0]); return (int) scalar("SELECT COUNT(*) FROM login_log WHERE ip = ? AND outcome = 'passkey_failed' AND created_at > NOW() - INTERVAL 10 MINUTE", [$ip]) < 10; } catch (Throwable $e) { return true; } }

# Security review — GAS-CP-R71-001 Passkeys (WebAuthn / FIDO2), portal v1.80.0

Reviewer: Nikos Polykandriotis (with Claude) · Date: 01/11/2026 · Scope: `customer/app/pk.php`, `assets/passkey.js`, `api/v1/index.php` passkey routes, the three app clients, `install/migrate_cp_r71_passkeys.sql`.

## 1. What a passkey is in this portal

A WebAuthn credential (public key) stored per account in `webauthn_credentials`; the private key stays in the device's secure element or the Apple / Google keychain and is released only after a local user verification (Face ID, Touch ID, fingerprint, PIN, Windows Hello). A successful assertion proves **possession of the device + a local biometric/PIN**, which is why the portal treats it as both factors and does not ask the authenticator code afterwards. It is phishing-resistant: the signature covers the origin and the relying-party id, so a passkey created for `goldensas.com` cannot be used on a look-alike domain.

## 2. Threats considered and controls

| # | Threat | Control | Where |
|---|---|---|---|
| T1 | Phishing site relays a challenge | `origin` must be `https://goldensas.com` / `https://www.goldensas.com` (plus `sec_passkey_origins`); `rpIdHash` must equal `sha256(rpId)` | `pk_check_client_data`, `pk_assert_finish` |
| T2 | Replay of a captured assertion | Challenge is 32 random bytes, single-use, 5 min; web: in the PHP session; API: `webauthn_challenges` row deleted on first use | `pk_challenge_new/take` |
| T3 | Cloned authenticator (two devices with the same key) | Signature counter must increase when the authenticator reports one; regression → refused, `passkey_failed` logged, administrators notified as for a lock-out | `pk_assert_finish`, `pk_fail` |
| T4 | Brute force / credential stuffing on the passkey endpoint | 10 `passkey_failed` per address per 10 min (web); `RateLimit auth` 10/min per IP (API); 300 ms delay on API rejection | `pk_rate_ok`, api index |
| T5 | Enrolment from a hijacked open session | Adding a passkey requires a **fresh authenticator code** when the account has one (web and API); the person is notified of every add / remove | `pk_stepup_ok`, `nf_push` |
| T6 | Silent enrolment without user presence / verification | Flags UP and UV required at registration and at every assertion; `userVerification: required`, `residentKey: required` | options + verifications |
| T7 | Credential bound to another account | `userHandle` (when sent) must equal `gascp:<user id>`; credential row carries `user_id`; lookup by `cred_id` only | `pk_assert_finish` |
| T8 | Disabled / locked / deactivated accounts | Same checks as the password path: `is_active`, customer active, `locked_until` | `pk_assert_finish` |
| T9 | Weak / unknown key types | Only ES256 (P-256) and RS256; COSE parsed strictly, lengths checked | `pk_cose_to_pem` |
| T10 | Malformed CBOR / attestation | Bounded decoder (no indefinite lengths, no tags), every length checked against the buffer; any exception → 400 | `pk_cbor`, `pk_parse_auth_data` |
| T11 | Attestation trust | `attestation: none` — the portal does not verify the authenticator make; enrolment is done by the signed-in, step-upped user, like the authenticator app | design |
| T12 | Account takeover through a lost device | My Account › Passkeys › remove; administrators see and remove every passkey (Settings › Login history); Sign out everywhere still revokes sessions; the feature can be switched off globally (`sec_passkeys`) | views, `pk_remove` |
| T13 | CSRF on the JSON endpoint | Signed-in operations require the `X-CSRF` header equal to the session token; unauthenticated login is bound to the session challenge | `pk_endpoint` |
| T14 | Enumeration of accounts | Login options never list credentials (`allowCredentials: []`, discoverable credentials only); failures return the same generic message | `pk_login_options` |
| T15 | Resource exhaustion | ≤ 10 passkeys per account; challenges purged (1 in 20 calls + daily house-keeping could be added) | `pk_register_finish` |
| T16 | Secrets at rest | Only public keys are stored; nothing to leak on a database dump beyond credential ids | schema |

## 3. Residual risks and open points

1. **Native store builds (Capacitor shells, R70-001).** WKWebView / Android WebView do not expose WebAuthn; the apps hide the button when `navigator.credentials.get` is absent, and the R70-005 Lock stays the way in. Closing it needs a native passkey plug-in (`@capacitor-firebase/authentication` is not suitable; a FIDO2 plug-in with the same JSON contract) and associated-domain files (`/.well-known/apple-app-site-association`, `assetlinks.json`) so the native passkey is bound to `goldensas.com`. Planned as R71-003.
2. **Synced passkeys** (iCloud Keychain / Google Password Manager, flag `backup_eligible` shown as ☁) follow the person's Apple / Google account: a compromised Apple ID would carry the passkey. Acceptable for staff and customers at the current risk level; the administrator can forbid synced passkeys later by refusing `BE` flag credentials if needed (one line in `pk_register_finish`).
3. **Signature counter** is zero on most platform authenticators (Apple), so T3 only protects hardware keys that report it. Known WebAuthn limitation.
4. **Sandbox origins** `http://localhost` / `http://127.0.0.1` are accepted only when the request's own host is one of them — never on the live host.
5. **Session fixation**: `finish_login()` regenerates the session id as on the password path.
6. **Recovery**: a person who loses every device still has password + authenticator (and the administrator's 2FA reset) — passkeys add a method, they never remove one.

## 3a. Addendum R71-003 (native store builds)
- Shells on Capacitor 8 (the Capacitor 6 line carried critical advisories); `npm audit` 0.
- Android Credential Manager reports `android:apk-key-hash:<sha256 of the signing certificate>` as origin. The portal accepts it only for the fingerprints the administrator enters (Settings › Login history); that list is as sensitive as the signing key — only the Play app-signing and upload certificates belong there. iOS 17.4+ reports `https://goldensas.com`.
- The association files (`/.well-known/apple-app-site-association`, `assetlinks.json`) bind the three bundle ids / package names to the relying party; without them the OS refuses the passkey, so a rogue app cannot use the company's passkeys.
- The plug-in is a third-party dependency (Capgo, MIT); pinned to 8.5.8; it patches the native projects at `cap sync` — reviewed scope: entitlements and asset statements only.

## 4. Tests performed (sandbox, Chromium virtual authenticator)

- Enrol on My Account with the authenticator code → listed; `passkey_added` logged; notification sent.
- Sign-in via button and via browser autofill (conditional UI) → session opened, `passkey` logged, no authenticator step.
- Enrol from the Golden PM app (API step-up), sign out, sign in with the passkey → home.
- Crafted assertion (unknown credential) → 401, `passkey_failed`; burned challenge → 400; call without challenge → 400.
- Stored counter pushed ahead (clone simulation) → every sign-in refused, three `passkey_failed`, no session.
- `php -l` on every file; lang key counts equal (6187); apps `node --check`.

## 5. Verdict

Approved for release 1.80.0. Re-review when R71-003 (native plug-in) lands or if synced passkeys are to be refused.

# Store builds of the Golden A/S apps — from GitHub, no Mac, no local tooling   (GAS-CP-R70-001)

Three Capacitor shells share this folder: **Service Engineer** (`gr.goldenas.service`, client `customer/mobile/`), **Golden PM** (`gr.goldenas.pm`, `customer/mobile-pm/`) and **Customer** (`gr.goldenas.customer`, `customer/mobile-customer/`) — see `apps.json`. The shell loads the client from the live portal (`server.url`), so screens follow the portal releases; `www/` inside the binary is only the offline fallback. A new store build is needed when a native plug-in changes, when the stores require it, or for the first publication.

Two workflows under Actions: **Build Android app** (AAB → artifact → Play Console track) and **Build iOS app** (IPA → artifact → TestFlight). Both take the app as input; the version name is the client's own version (`api.js VERSION`), the version / build number is the workflow run number.

## One-time setup

### Google (Android)
1. Play Console developer account (organisation, D-U-N-S, 25 USD once). Create the three apps (name, default language English, app / free).
2. Upload keystore — generate once on any machine and keep it in the company vault (losing it means a new listing):
   `keytool -genkeypair -v -keystore golden-upload.keystore -alias golden -keyalg RSA -keysize 2048 -validity 10000`
   Secrets `ANDROID_KEYSTORE_B64` (`base64 -w0 golden-upload.keystore`), `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` (= golden), `ANDROID_KEY_PASSWORD`. Enrol every app in **Play App Signing** at the first upload (Google keeps the release key, ours is only the upload key).
3. Upload by the workflow (optional, otherwise download the artifact and upload by hand): Google Cloud project → service account → JSON key → Play Console › Users and permissions › invite the service account e-mail with *Release to testing tracks* / *Release to production*. Secret `PLAY_SERVICE_ACCOUNT_JSON` (the whole JSON). The very first AAB of every app must be uploaded by hand in the Console (the API refuses an app without a first release).
4. Push (later): Firebase project, Android app per bundle id, `google-services.json` → secret `FIREBASE_GOOGLE_SERVICES_B64`.

### Apple (iOS)
1. Apple Developer Program as an **organisation** (D-U-N-S, 99 USD/year). Certificates, Identifiers & Profiles: an **App ID** per bundle id (capability Push Notifications), one **Apple Distribution** certificate (create the CSR on any Mac or with `openssl`; export as .p12 with a password), an **App Store** provisioning profile per app — name them `Golden Service AppStore`, `Golden PM AppStore`, `Golden Customer AppStore`.
2. App Store Connect › Users and Access › Integrations › **App Store Connect API** → key with role *App Manager*; note Key ID and Issuer ID, download `AuthKey_XXXX.p8` (only once).
3. Secrets `APPLE_TEAM_ID`, `APPLE_CERT_P12_B64` (`base64 -w0 cert.p12`), `APPLE_CERT_PASSWORD`, `ASC_KEY_ID`, `ASC_ISSUER_ID`, `ASC_KEY_P8_B64` (`base64 -w0 AuthKey_XXXX.p8`). Variables `IOS_PROFILE_ENGINEER`, `IOS_PROFILE_PM`, `IOS_PROFILE_CUSTOMER` = the profile names.
4. App Store Connect › create the three app records (bundle id, SKU = bundle id, name). TestFlight testers are added there by e-mail.

## Releasing
1. Actions › **Build Android app** → app, track `internal` (pilot) — the AAB appears in the Play Console internal track within minutes; testers use the opt-in link. Later `production` for the customer app (after the closed-test requirement of new accounts), or the managed / private track for staff apps.
2. Actions › **Build iOS app** → app, TestFlight on — the build appears in App Store Connect › TestFlight in ~15 min after processing; add testers (internal = your team at once; external = short beta review).
3. Store listing (once per app): screenshots from the pilot phones, privacy policy URL on goldensas.com, support URL, App Privacy / Data safety answers (name, e-mail, photos; no tracking), a review demo account with 2FA off, review note "for Golden A/S staff / customers".
4. After publication set Settings › Mobile apps › minimum version per app if the store build must replace older PWAs.

## Locally (optional)
`npm install && node prepare.js engineer && npx cap add android && npx cap sync` — `npx cap open android` / `ios` opens the native project in Android Studio / Xcode on a machine that has them.


## Native passkeys (R71-003, Capacitor 8 + @capgo/capacitor-passkey)
The shells run the live clients in a WebView; WebViews have no WebAuthn, so the plug-in's native passkey APIs (ASAuthorization on iOS, Credential Manager on Android) are used by the clients when `Capacitor.Plugins.CapacitorPasskey` exists. Same relying party as the web portal (`goldensas.com`), so a passkey created in Safari or Chrome works in the app and vice versa (Apple / Google keychain).
One-time setup on the relying-party domain:
1. `node wellknown.js <APPLE_TEAM_ID> <SHA256 of the Play app-signing certificate[,upload cert]>` → `well-known/apple-app-site-association` and `well-known/assetlinks.json`. Fingerprints: Play Console › Setup › App signing (both "App signing key certificate" and "Upload key certificate"). The build-android workflow prints the upload-key fingerprint in its log as a convenience.
2. Upload both files to `public_html/.well-known/` on goldensas.com — no extension for the Apple file; the folder's `.htaccess`: `<Files "apple-app-site-association">ForceType application/json</Files>`. They must be served without redirect over https.
3. Portal › Settings › Login history › Session policy › "Android app signing SHA-256": paste the same fingerprint(s). The portal then accepts the Android app origin (`android:apk-key-hash:…`) in the passkey check; iOS reports `https://goldensas.com` by itself (iOS 17.4+).
4. Rebuild the apps (Build Android / Build iOS). First passkey use on a device may take up to 24 h after step 2 (Apple caches the association file through its CDN).
Dependencies: Capacitor 8.6 (Node 22, Java 21, Xcode 16); `npm audit` clean with `overrides` for the build-tool transitive packages.

## iOS build notes (learned on the first publication, 01/11/2026)
- Runner `macos-15` with **Xcode 26** selected (Apple refuses uploads built with an older SDK; Capacitor 8 itself needs Xcode 16+).
- Capacitor 8 iOS template uses Swift Package Manager: no `App.xcworkspace`, the workflow archives `App.xcodeproj`.
- Manual signing is written into the App target of `project.pbxproj` (team, profile, identity, bundle id, versions); passing them on the `xcodebuild` command line breaks the Swift packages ("does not support provisioning profiles").
- The Apple actions take the `.p8` **text**; the workflow decodes `ASC_KEY_P8_B64` first.
- Git Bash on Windows: prefix `openssl req … -subj "/CN=…"` with `MSYS_NO_PATHCONV=1`, otherwise the leading slash becomes a Windows path.
- First TestFlight build asks the export-compliance question once (answer No: standard HTTPS only).

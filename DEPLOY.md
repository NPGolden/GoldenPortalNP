# Deploying the Customer Portal to goldensas.com (GoDaddy cPanel)

Releases go live from GitHub, through the portal's own updater — nobody uploads files by hand any more.

## How a release flows
1. The release is merged on `main` and tagged: `git tag v1.68.0 && git push origin v1.68.0`.
2. GitHub Actions (`.github/workflows/deploy.yml`) lints every PHP file, checks the five language files have the same number of keys, builds `install/manifest.json`, zips the release (`app/config.php` and `storage/` are never inside), uploads the zip to `customer/storage/updates/` and calls `customer/install/update.php` — first as a dry run, then for real.
3. `update.php` verifies every file against the manifest (sha256), backs up the files it will replace and dumps the whole database to `customer/storage/backups/<stamp>-v<old>/`, extracts the release, runs the migrations that are still pending (`cp_patches()` order — the About page check decides), runs `cp_selfcheck.php` and returns a JSON report that the Action prints.
4. The *production* environment in GitHub can require a reviewer: the deploy then waits for a click before touching the server.

## One-time setup
| Where | What |
|---|---|
| cPanel › FTP Accounts (or SSH Access) | a dedicated account `deploy@goldensas.com`, directory `public_html` — never the personal account |
| cPanel › File Manager › `customer/app/config.php` | add `'update' => ['token' => '<64 hex chars>']` (generate: `php -r "echo bin2hex(random_bytes(32));"`) |
| GitHub › Settings › Secrets › Actions | `DEPLOY_HOST`, `DEPLOY_PROTOCOL` (sftp / ftps), `DEPLOY_USER`, `DEPLOY_PASSWORD` or `DEPLOY_SSH_KEY`, `DEPLOY_PATH`, `UPDATE_TOKEN` |
| GitHub › Settings › Variables | `PORTAL_URL = https://goldensas.com/customer` |
| GitHub › Settings › Environments › production | optional: required reviewer |
| Sucuri | if the firewall blocks the POST to `/customer/install/update.php`, whitelist that path for the GitHub Actions user agent `GAS-deploy/1.0` |

## Roll-back
`customer/storage/backups/<stamp>-v<old>/files/` holds every file the release replaced, `db.sql.gz` the database before the migrations. Re-deploying the previous tag (Run workflow → ref = `v1.66.0`) restores the files; the database is restored from the dump in phpMyAdmin only if a migration must be undone.

## Rules that still apply
`app/config.php` is never in the repository or a zip · the manifest is rebuilt twice before zipping (the Action does it) · every release note carries a security review · manuals are updated with every version · after a deploy, press Ctrl+F5 once in the browser.

## Store builds of the mobile apps
Actions › **Build Android app** / **Build iOS app** (input: engineer | pm | customer) build the Capacitor shells of `mobile-native/` on GitHub runners — signed AAB to the Play Console track, IPA to TestFlight — with the certificates and keys held as repository secrets. Accounts, secrets and the release sequence: `mobile-native/README.md`; test plan and store checklist: GAS-2026-PORTAL-APP-002.

## Release checklist — security (GAS-CP-R97-005)

Run after every release; each takes under a minute.

1. **Diagnostics** — Settings › About › ▶ Run diagnostics: "Storage folder closed to the web" and "Request-token check" must be OK.
2. **Storage from outside** — open `https://<site>/customer/storage/.htaccess` and `https://<site>/customer/storage/php-errors.log` in a private window: both must answer 403 (or the firewall's block page), never a file.
3. **Fresh-session token test** — sign in with a script that does not load any page after the sign-in (so the session has issued no token yet), POST any action with no `_csrf` field and with an empty one: both must be answered by a redirect and change nothing. In the sandbox: `login.sh`, then `curl -X POST "$B?p=system_budgets" -d "action=sb_create&title=NoToken"` → 0 rows with that title.
4. **Self-check** — `php customer/install/cp_selfcheck.php` shows no `[WARN]` for the storage line or the migrations of the release.
5. **Audit trail** — System Budgets › 🛡 Audit trail (Excel) opens for management and is refused (403) for a project manager.


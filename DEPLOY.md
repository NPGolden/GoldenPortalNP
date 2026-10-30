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

# UPLOAD & GO-LIVE GUIDE — goldensas.com (GoDaddy)

Everything in this ZIP goes to your GoDaddy hosting exactly as-is. Follow top to bottom;
each step depends on the one before it. Doc ref **GAS-DOC-UPLOAD-001**.

---

## 0. Before you open the FTP client (10 minutes, in the GoDaddy panel)

1. **PHP version** — cPanel → *Select PHP Version* → choose **8.1 or newer**.
   Tick the extensions: `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`, `json`.
   (`curl` matters: the SoftOne ERP connection uses it.)
2. **Databases** — cPanel → *MySQL® Databases*:
   - Create database `goldensas_portal` (customer portal).
   - Create database `goldensas_poms` (employee portal).
   - Create one MySQL user with a strong password; grant it **ALL PRIVILEGES** on both.
   - Note the exact names — GoDaddy prefixes them (e.g. `abc123_goldensas_portal`).
3. **SSL** — make sure the free certificate for goldensas.com is active
   (cPanel → SSL/TLS Status). Both portals refuse to run sign-ins over plain HTTP.

---

## 1. Prepare the two config files (on your PC, before uploading)

Unzip `goldensas-portal.zip` locally.

**Customer portal** — copy `customer/app/config.sample.php` → `customer/app/config.php`, edit:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'abc123_goldensas_portal',   // your prefixed name
    'user' => 'abc123_dbuser',
    'pass' => '*** the password you set ***',
],
'softone' => [
    // Endpoint, username GoldenWeb, appId 1001 are pre-filled from the
    // Connect Line document. If they send different credentials in their
    // separate document, change them here.
    'sync_key' => '*** replace with a LONG random string ***',
],
'app' => [
    'base_url' => 'https://goldensas.com/customer',
],
```

**Employee portal** — copy `employee/app/config.sample.php` → `employee/app/config.php`,
fill in the `goldensas_poms` database, and set
`'base_url' => 'https://goldensas.com/employee'`.

---

## 2. FTP upload

Client: FileZilla or WinSCP. Host: `ftp.goldensas.com` (or the IP GoDaddy shows),
your hosting username/password.

**Two settings that matter, set them before transferring:**

| Setting | FileZilla | WinSCP | Why |
|---|---|---|---|
| **Binary transfer** | Transfer → Transfer type → **Binary** (or Auto) | Options → Preferences → Transfer → **Binary** | ASCII mode corrupts the logo, Greek text and the language files |
| **Show hidden files** | Server → **Force showing hidden files** | Preferences → Panels → **Show hidden files** | otherwise the `.htaccess` files silently don't upload — and they protect your config |

Upload the **contents** of the `golden-portal` folder into `public_html/`, so it ends up:

```
public_html/index.php            ← landing page
public_html/.htaccess
public_html/assets/…
public_html/customer/…
public_html/employee/…
```

**Permissions after upload:** folders `755`, files `644`, and these two writable at `775`:

```
public_html/customer/storage/          (and its uploads/ inside)
public_html/employee/storage/          (and its uploads/ inside)
```

---

## 3. Load the databases (phpMyAdmin)

cPanel → phpMyAdmin:

1. Select `…goldensas_portal` → **Import** → `customer/install/schema.sql`.
2. Optionally import `customer/install/seed_demo.sql` (demo content for testing;
   demo sign-in `captain@demo-fleet.com` / `GoldenDemo2026!`).
3. Select `…goldensas_poms` → **Import** → `employee/install/schema.sql`, then
   `employee/install/seed.sql`.

*(Upgrading a portal that was already live instead of installing fresh? Import
`customer/install/migrate_r2_softone.sql` once instead of re-running schema.sql.)*

---

## 4. Verify with the preflight pages

Open in a browser:

- `https://goldensas.com/customer/install/preflight.php`
- `https://goldensas.com/employee/install/preflight.php`

Every row must be green except advisories. The usual reds and their fixes:
missing extension → step 0.1; database unreachable → wrong prefixed name in config;
storage not writable → permissions in step 2.

Then run the employee installer: `https://goldensas.com/employee/install/setup.php`
(creates the first administrator, who enrols an authenticator app at first sign-in).

---

## 5. Connect the ERP (SoftOne via Connect Line)

1. **Map your customers.** Each portal customer must carry the SoftOne customer code
   so the sync knows whose projects are whose. In phpMyAdmin:

   ```sql
   UPDATE customers SET erp_code = 'C-00042' WHERE name = 'Fleet Co';
   ```

   Don't know the codes? Run the sync once (next step) — it prints every ERP
   customer code it saw that isn't mapped yet.

2. **First sync, by hand.** In a browser:

   ```
   https://goldensas.com/customer/install/sync_softone.php?key=YOUR-SYNC-KEY
   ```

   (the key you set in config). You should see
   `Fetched N projects from SoftOne. OK — matched X, skipped Y.`
   Skipped = internal projects (PAGIA etc.) and unmapped customers — by design,
   nothing unmapped ever reaches a customer's screen. Payment figures
   (LCashOut/LCashIn) are never stored at all.

3. **Schedule it.** cPanel → **Cron Jobs** → add, every 30 minutes:

   ```
   /usr/local/bin/php /home/YOUR-CPANEL-USER/public_html/customer/install/sync_softone.php
   ```

   Every run is logged in the `erp_sync_log` table.

4. **Stage percentages.** The ERP gives project existence, state, vessel, category
   and PM. The Engineering → Production → FAT → SAT/HAT percentages are yours to
   maintain (each new ERP project arrives with one "General" sub-project at 0%).
   Update via phpMyAdmin for now:

   ```sql
   UPDATE subprojects SET pct_engineering=100, pct_production=65, pct_fat=20
   WHERE id = …;
   ```

---

## 6. Test like a customer

1. Sign in at `https://goldensas.com/customer/` with the demo account.
2. Check the dashboard on a desktop **and** a phone (the sidebar becomes a
   drawer; everything is touch-sized).
3. Switch languages EN → GR → IT with the header pills.
4. Confirm your real synced projects appear under Projects with the correct vessels.

---

## 7. Lock it down (do not skip)

- **Delete the demo customer** (`DELETE FROM customer_users WHERE email='captain@demo-fleet.com'; DELETE FROM customers WHERE erp_code='DEMO-01';`) — or simply skip seeding it in step 3.
- **Delete both `install/` folders** over FTP — **except keep
  `customer/install/sync_softone.php`** (the cron runs it forever). Cleanest:
  delete everything else inside `install/`, leave that one file.
- Confirm `https://goldensas.com/customer/app/config.php` returns **403 Forbidden**
  in a browser. If it shows the file, the `.htaccess` files didn't upload — step 2.

---

## Quick troubleshooting

| Symptom | Cause → Fix |
|---|---|
| Blank white page | PHP < 8.1, or `app/config.php` missing → steps 0–1 |
| Greek/Italian shows `?` | ASCII FTP mode or non-utf8mb4 DB → re-upload binary; schema sets utf8mb4 |
| Sync says "transport error" | `curl` extension off, or host blocks outbound HTTPS → step 0.1 / GoDaddy support |
| Sync "login failed" | Connect Line credentials differ from the defaults → update `softone` in config |
| Everything skipped | No `erp_code` mapped yet → step 5.1 |
| Demo login fails | seed_demo.sql edited before import → re-import untouched |

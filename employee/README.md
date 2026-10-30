# Parts Order Management System
**Golden A/S — Sistimata Ischyos ATEE**
Ref: GAS-DOC-POMS-001 · R0

A web application for field engineers to raise parts orders, route them through
two levels of approval, and follow them to the vessel. Runs on PHP 8.1+ and MySQL 8.0+.
Ready to upload to any standard web host.

---

## What it does

| | |
|---|---|
| **Orders** | Up to 20 line items each, with photos, voice notes, description, part number, quantity, project number, vessel and priority |
| **Approvals** | Two gates: Project Manager, then Technical Manager. Both come from the project record |
| **Dates** | Approximate delivery (set by Procurement, per line and per order) and actual delivery (stamped when the driver records the delivery) |
| **Roles** | System & User Admin · Field Engineer · Project Manager · Technical Manager · Procurement · Warehouse · Driver |
| **Security** | Password + authenticator app (TOTP), mandatory for everyone. Account lockout. Full access log |
| **Notifications** | Every status change alerts everyone concerned, in-app and by email |
| **SharePoint** | Open orders pushed to a SharePoint list over Microsoft Graph on every change |
| **Vessels** | Maintained by CSV import |

### The route an order takes

```
draft → awaiting PM → awaiting TM → with procurement → ordered
      → in warehouse → ready for dispatch → in transit → delivered → closed
```

Branches: rejected by PM, rejected by TM, backordered, partially fulfilled, cancelled.

---

## Who sees what

| Role | Sees | Can do |
|---|---|---|
| Field Engineer | Their own orders | Raise, attach media, submit, cancel, confirm receipt |
| Project Manager | Orders on their projects | Approve or reject at gate 1 |
| Technical Manager | Orders on their projects | Approve or reject at gate 2 |
| Procurement | Everything approved | Set supplier, price, approximate delivery; place the PO |
| Warehouse | Everything ordered onward | Book goods in, release for dispatch |
| Driver | The dispatch board | Collect, record delivery |
| System Admin | Everything | Users, roles, projects, vessels, settings, access log |

---

## Installing

### 1. Server requirements

- PHP **8.1 or newer**, with `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl`
- MySQL **8.0+** or MariaDB **10.6+**
- HTTPS. Two-factor login over plain HTTP is not acceptable

### 2. Upload

Upload the whole folder. Point the document root at the folder that contains
`index.php`. If your host will not let you move the document root, everything
still works — `.htaccess` blocks direct access to `app/` and `storage/`.

```
/                 ← document root
  index.php
  .htaccess
  web.config      (IIS / Azure only)
  app/            application code — not web reachable
  assets/         css, js
  install/        DELETE THIS AFTER SETUP
  storage/uploads photos and voice notes — not web reachable
```

### 3. Create the database

In cPanel, Plesk or phpMyAdmin: create a database and a user with full rights on it.
Note the host, name, user and password.

### 4. Configure

```bash
cp app/config.sample.php app/config.php
```

Open `app/config.php` and set at minimum:

- `db.host`, `db.name`, `db.user`, `db.pass`
- `app.base_url` — the public address, no trailing slash
- `mail.driver` — start with `log`, switch to `mail` or `smtp` once you have tested

Make the upload folder writable:

```bash
chmod -R 775 storage/uploads
```

### 5. Run the installer

Visit **`https://your-site/install/setup.php`**.

It creates the tables, loads the roles and a starter vessel list, and asks for
your administrator name, email and password.

**Then delete the `install/` folder from the server.**

### 6. First sign-in

Sign in with the administrator account. You will be shown a QR code — scan it with
Microsoft Authenticator, Google Authenticator or 1Password, and enter the six-digit
code. Two-factor is now bound to that account.

### 7. Set the system up

In this order:

1. **Users & roles** — add your Project Managers and Technical Managers first
2. **Projects** — each project names its PM and TM. This is what routes approvals
3. **Vessels** — import your CSV (header row, `name` column required)
4. **Users & roles** — add your engineers, procurement, warehouse and drivers
5. **Settings** — turn on email, and configure SharePoint if you want it

Each new person enrols their own authenticator the first time they sign in.
If someone loses their phone, an admin uses **Reset 2FA** on the Users page.

---

## SharePoint integration

Orders are pushed to a SharePoint list on every status change, and can be pushed
in bulk on a schedule.

### Set up the app registration

1. Entra ID → **App registrations** → New registration. Single tenant is fine
2. **Certificates & secrets** → New client secret. Copy the value now
3. **API permissions** → Add → Microsoft Graph → **Application permissions** →
   `Sites.ReadWrite.All` → **Grant admin consent**
4. Note the Directory (tenant) ID and Application (client) ID

### Find the site and list IDs

```
GET https://graph.microsoft.com/v1.0/sites/{hostname}:/sites/{site-name}
GET https://graph.microsoft.com/v1.0/sites/{site-id}/lists
```

### Create the list columns

The list needs these internal column names:

`Title` (order reference) · `ProjectNumber` · `VesselName` · `RequestedBy` ·
`ProjectManager` · `TechnicalManager` · `Priority` · `OrderStatus` ·
`ApproxDelivery` (date) · `ActualDelivery` (date) · `LineCount` (number) ·
`LastSync` (date & time)

### Turn it on

Paste the IDs and secret into **Settings → SharePoint**, set Sync to On, and press
**Push open orders** to test. Sync attempts and their HTTP responses are logged on
the same page.

For a scheduled push, add a cron job:

```
*/15 * * * * /usr/bin/php /path/to/site/install/sync_sharepoint.php >> /path/to/site/storage/sync.log 2>&1
```

(Keep `install/sync_sharepoint.php` if you use cron; it refuses to run over HTTP.
Delete `install/setup.php` regardless.)

---

## Vessel CSV

Header row required. Only `name` is mandatory. Re-importing updates existing
vessels rather than duplicating them.

```csv
name,imo,hull_no,yard
AMBROSIA,9123456,NB-1042,"Palumbo, Ancona"
GALILEO,,C620,"TISG, La Spezia"
```

Import from **Admin → Vessels**, or on the command line:

```bash
php install/import_vessels.php vessels.csv
```

---

## Security notes

- Passwords are stored with `password_hash()` (bcrypt). Minimum 10 characters
- TOTP secrets are stored per user; an admin can reset them but never read a code
- Five failed passwords lock the account for 15 minutes. Both are configurable
- Sessions are HttpOnly, SameSite=Lax, Secure over HTTPS, and time out after 30 minutes idle
- Every form is CSRF protected
- Uploads are checked by content type, not by file extension, and are streamed
  through `index.php?p=file` after an access check — never served directly
- The access log records sign-ins, failures, lockouts, 2FA outcomes, account
  changes, vessel imports and SharePoint syncs, with IP address and device

### Before you go live

- [ ] `install/setup.php` deleted from the server
- [ ] HTTPS enforced and a certificate installed
- [ ] `app/config.php` is not readable over the web (test: `https://your-site/app/config.php`)
- [ ] `storage/uploads` is not readable over the web (test a direct file path)
- [ ] Database user has rights on one database only
- [ ] A database backup schedule exists

---

## Sizing

For a contractor of this size — engineers across a handful of yards, a few hundred
orders a year — shared hosting with 1 GB of database space and 20 GB of file storage
is comfortable. Photos dominate the storage; budget roughly 2 MB per photo.

---

## Files

| Path | Purpose |
|---|---|
| `index.php` | Front controller. Routing, POST handlers, file streaming |
| `app/db.php` | Configuration loader, PDO connection, settings |
| `app/auth.php` | Sessions, password step, TOTP step, roles, access log |
| `app/totp.php` | RFC 6238 one-time passwords, pure PHP |
| `app/workflow.php` | The order lifecycle: who can move what, and where |
| `app/notify.php` | In-app notifications and their email copies |
| `app/mailer.php` | `mail()`, SMTP with STARTTLS, or a log file |
| `app/sharepoint.php` | Microsoft Graph client, list push and pull |
| `app/helpers.php` | CSRF, flash messages, formatting, status vocabulary |
| `app/views/` | One file per page |
| `install/schema.sql` | Tables, keys, the 20-line trigger |
| `install/seed.sql` | Roles, settings, a starter vessel list |
| `install/setup.php` | First-run installer |
| `install/sync_sharepoint.php` | Cron entry point |
| `install/import_vessels.php` | CLI vessel import |

---

## Support

Raise questions with whoever maintains this system for Golden A/S. The access log
and the SharePoint sync log on the Settings page answer most "did it work" questions
without touching the database.

## Languages

The interface runs in five languages: **English, Ελληνικά (Greek), Български (Bulgarian),
Русский (Russian), Italiano**.

* A language bar sits at the bottom of the navigation rail and on the sign-in screen.
* Each person's choice is saved against their account (`users.locale`) and follows them
  between devices. Administrators can also set it for someone in **Users & roles**.
* A visitor who has never chosen gets their browser's language, falling back to English.
* Notifications and their email copies are written **in each recipient's own language** —
  a Bulgarian warehouse hand and a Greek project manager receive the same event described
  in their own words.
* Dates are formatted from the language files (`14 Μαρ 2026`, `14 mar 2026`), so the
  `intl` extension is not required.

Strings live in `lang/<code>.php` as flat key/value arrays. `lang/en.php` is the master
set; a missing key in any other file falls back to English rather than blanking the page.
To correct a translation, edit the one line and re-upload that file — nothing else changes.

### Adding a sixth language

1. Copy `lang/en.php` to `lang/xx.php` and translate the values, leaving keys untouched.
2. Add `'xx' => ['name' => '…', 'native' => '…', 'dir' => 'ltr']` to `LOCALES` in `app/i18n.php`.
3. Widen the column if needed: `ALTER TABLE users MODIFY locale CHAR(2);` already fits.

---

## Deploying over FTP

No build step, no Composer, no shell access required.

1. **Prepare locally.** Copy `app/config.sample.php` to `app/config.php` and fill in the
   database name, user and password your host gave you, plus `app.base_url`.
2. **Set your client to binary (or auto) transfer.** ASCII mode corrupts `assets/qrcode.min.js`
   and mangles the Greek, Bulgarian and Russian language files.
3. **Show hidden files**, so the `.htaccess` files upload.
   FileZilla: *Server → Force showing hidden files*. WinSCP: *Options → Preferences → Panels*.
4. **Upload the whole folder** into the directory your domain serves — commonly
   `public_html`, `httpdocs` or `www`. `index.php` must sit at its top level.
5. **Set permissions.** Folders `755`, files `644`, and `storage/` plus `storage/uploads/` `775`.
6. **Create the database** in your host's control panel (cPanel/Plesk → MySQL Databases),
   then import `install/schema.sql` followed by `install/seed.sql` through phpMyAdmin.
7. **Visit `https://your-domain/install/preflight.php`.** It checks the PHP version, the
   extensions, HTTPS, folder permissions and the database connection, and names anything
   that is wrong. Fix what it flags, then follow its link to the installer.
8. **Visit `install/setup.php`**, create the first administrator and pick the default language.
9. **Delete the entire `install/` folder** over FTP. Nothing in the running system needs it.
10. Sign in. You will be asked to enrol an authenticator app before you reach the dashboard.

### Upgrading an existing installation to the multilingual release

Import `install/migrate_r1_languages.sql` once, then upload the new files. It adds the
`users.locale` column and the `default_locale` setting; existing accounts stay on English
until each person picks otherwise.

### If something is wrong

* **Blank white page** — your host is hiding errors. Set `display_errors` on temporarily in
  the control panel, or read the error log it exposes.
* **"The upload folder is not writable"** — `chmod 775 storage/uploads`.
* **Accented characters show as `?`** — the database or connection is not `utf8mb4`.
  `schema.sql` sets it; check the database was not pre-created as `latin1`.
* **QR code does not appear** — `assets/qrcode.min.js` uploaded in ASCII mode. Re-upload in binary.
* **Sign-in loops back to the login page** — session cookies need HTTPS. Check the certificate.

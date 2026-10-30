# GOLDEN Automation Systems — Portal (goldensas.com)

One domain, two portals, one MySQL server. Doc ref **GAS-DOC-PORTAL-001**.

```
goldensas.com/                 Landing page — choose your portal
goldensas.com/customer/        Customer Portal   (project status, documents, signatures, support)
goldensas.com/employee/        Employee Portal   (Parts Order Management System — POMS)
```

Both portals share the brand system in `assets/` (navy `#0A1B2E`, gold `#D7B56D`, the
company logo) and are responsive on desktop, iOS and Android — the sidebar becomes a
slide-in drawer under 760 px, touch targets are ≥ 44 px, and the layout uses
`viewport-fit=cover` for notched phones.

---

## The two portals

### Customer Portal (`/customer/`) — built to the approved Figma

Languages: **EN / GR / IT** (pills in the header, per the design). Each customer user's
choice is saved. Pages:

| Page | What the customer sees |
|---|---|
| Dashboard | Four navy stat cards (open projects, pending signatures, pending approvals, recent uploads) · Vessels quick access · Action Center (sign / review) · **Project progress across Engineering → Production → FAT → SAT/HAT** · Recent documents |
| Vessels | Their fleet, with jumps to that vessel's projects and documents |
| Projects | Every project + sub-project with a 4-segment lifecycle bar |
| Documentation | Manuals, drawings, certificates, FAT protocols, filterable by vessel and category |
| Service Reports | FSRs and work orders, newest first |
| IT & Cybersecurity | Hardening reports and cyber notices |
| Support | Request form + history |
| Account | Language and password |

**Data isolation:** every query is scoped by `customer_id`. A signed-in customer can
never see another customer's vessels, projects or documents.

**Sign-in:** email + password, throttled (5 failures → 15-minute lock), sessions with a
30-minute idle timeout, CSRF on every form.

### Employee Portal (`/employee/`)

The complete Parts Order Management System exactly as already built: 5 languages
(EN/EL/BG/RU/IT), TOTP two-factor for every account, two-gate approvals
(PM → Technical Manager), procurement, warehouse, driver dispatch, SharePoint sync.
Its own README inside `employee/` covers its installer and settings.

---

## Database (one MySQL 8.0 server, two schemas — or one, your choice)

Cleanest layout on shared hosting is **two databases**:

| Database | Used by | Schema file |
|---|---|---|
| `goldensas_portal` | Customer Portal | `customer/install/schema.sql` |
| `goldensas_poms`   | Employee Portal | `employee/install/schema.sql` + `seed.sql` |

They can also share one database — the table names do not collide (the customer portal's
`vessels` carries a `customer_id`; if sharing, rename one or accept that the two vessel
lists are independent, which they are by design: the employee vessel list is operational,
the customer one is per-account).

`customer/install/seed_demo.sql` loads the exact demo content from the Figma
(MY ALPHA / MY OCEAN / MY AURORA, Refit-2026, FAT-ALPHA-2026-v3 …).
**Demo sign-in:** `captain@demo-fleet.com` / `GoldenDemo2026!` — delete this user before
inviting real customers.

---

## SoftOne ERP integration (Connect Line web services)

Active projects and vessels come **directly from the ERP** — no manual entry.
The portal implements the documented flow (GAS-ERP-CP-001):

```
login  ->  authenticate  ->  getWorkingProjects        (Content-Type: application/json)
Endpoint            https://gma.oncloud.gr/s1services
Additional Endpoint https://gma.oncloud.gr/s1services/js/CLWEBServices.S1Lib/ApiServices
```

Credentials (`GoldenWeb` / appId `1001` per the Connect Line document) live in
`customer/app/config.php` under `softone`, never in code. The authenticated
`clientID` is cached in `customer/storage/softone_token.json` and refreshed
automatically when SoftOne expires it.

### How data flows

`customer/install/sync_softone.php` pulls every working project and upserts:

- **Vessels** — from `SHIPNAME`, created per customer on first sight.
- **Projects** — keyed by ERP `CODE`; `DESCRIPTION` → name, `STATE` Open→active
  (anything else → completed), plus `CATEGORY` and `PROJECT_MANAGER`, shown on
  the Projects page.
- **Customer matching** — SoftOne `CUSTOMER_CODE` must equal
  `customers.erp_code` in the portal. Projects with no matching portal
  customer are **skipped**, never shown to the wrong account. The sync prints
  any unmapped codes it saw so you can add them.
- **Not stored:** `LCashOut` / `LCashIn`. Customers must not see payment
  figures; the sync drops them at the door.
- **Stage percentages** (Engineering → Production → FAT → SAT/HAT) stay
  portal-side — the ERP doesn't carry them. Each new ERP project gets one
  "General" sub-project at 0%; your PMs maintain the percentages
  (phpMyAdmin today, admin screen as a next step).

### Running the sync

Manually over HTTPS (after setting a long random `softone.sync_key` in config):

```
https://goldensas.com/customer/install/sync_softone.php?key=YOUR-SYNC-KEY
```

On a schedule — GoDaddy cPanel → **Cron Jobs** → e.g. every 30 minutes:

```
/usr/local/bin/php /home/USERNAME/public_html/customer/install/sync_softone.php
```

Every run is recorded in `erp_sync_log` (fetched / matched / skipped counts).

> When you delete `install/` after go-live, **keep `sync_softone.php`**
> (move it or delete everything else around it) — it is the one install-folder
> file that runs forever.

### Mapping customers

```sql
UPDATE customers SET erp_code = 'C-00042' WHERE name = 'Fleet Co';
```

Run the sync once by hand first: it lists the ERP customer codes it saw that
aren't mapped yet, which is the fastest way to fill this table.

---

## Deploying on GoDaddy (goldensas.com)

GoDaddy Linux hosting (cPanel) — no build step, no shell needed.

1. **PHP version.** cPanel → *Select PHP Version* → PHP **8.1+**, extensions
   `pdo_mysql`, `mbstring`, `fileinfo`, `curl`, `openssl` ticked.
2. **Databases.** cPanel → *MySQL® Databases*: create `goldensas_portal` and
   `goldensas_poms`, one user with a strong password, grant ALL on both.
3. **Import schemas.** phpMyAdmin → `goldensas_portal` → Import → `customer/install/schema.sql`
   (then optionally `seed_demo.sql`). Upgrading an existing install instead? Import
   `customer/install/migrate_r2_softone.sql` once. Same for `goldensas_poms` with the employee SQL files.
4. **Configs.** Copy `customer/app/config.sample.php` → `customer/app/config.php` and
   fill in the DB details and `base_url = https://goldensas.com/customer`. Do the same in
   `employee/app/` (its base_url is `https://goldensas.com/employee`).
5. **Upload.** FTP the whole folder into `public_html/` in **binary/auto mode** with
   **hidden files visible** (the `.htaccess` files must arrive). Folders 755, files 644,
   both `storage/` trees 775.
6. **HTTPS.** GoDaddy issues a free certificate on cPanel plans — turn it on for
   goldensas.com before going live; both portals expect HTTPS.
7. **Verify.** Open `https://goldensas.com/customer/install/preflight.php` and
   `https://goldensas.com/employee/install/preflight.php` — fix anything flagged.
8. **Install the employee portal** via `employee/install/setup.php` (creates the first
   admin, who then enrols an authenticator app).
9. **Delete both `install/` folders** over FTP.
10. Sign in to `/customer/` with the demo account, check the dashboard on a phone and a
    desktop, then delete the demo user and create real customer accounts.

### DNS note

`goldensas.com` is registered/hosted at GoDaddy, so DNS is in the same panel — the root
A record points at GoDaddy's own hosting automatically. If you later serve mail from
elsewhere, keep exactly one SPF TXT record on the root.

### Creating customer accounts (until an admin UI is added)

Insert via phpMyAdmin:

```sql
INSERT INTO customers (name, country) VALUES ('Fleet Co', 'GR');
INSERT INTO customer_users (customer_id, full_name, email, password_hash, locale)
VALUES (LAST_INSERT_ID(), 'Jane Owner', 'jane@fleetco.com',
        '<hash>', 'en');
```

Generate `<hash>` with: `php -r "echo password_hash('TheirPassword', PASSWORD_DEFAULT);"`
(or ask us to add the customer-admin screen to the employee portal — the natural next step).

---

## Common snags

- **Blank page** → PHP version below 8.1, or `app/config.php` missing.
- **Greek/Italian text shows `?`** → FTP was in ASCII mode, or the DB isn't `utf8mb4`.
- **`.htaccess` missing** → hidden files were filtered by the FTP client; `app/config.php`
  is then readable — fix immediately.
- **Login always fails on demo** → `seed_demo.sql` was edited before import; re-import it.

# Golden A/S Customer Portal — release ledger and consolidation plan
Prepared by Nikos Polykandriotis · R4 · 30 Oct 2026

> **Status 29 Oct 09:40 — the live site runs the main line only.** The Service Engineer app package (0.5.0, "R57") has **not** been uploaded and its migration has **not** been run: nothing of it is effective on goldensas.com. The live baseline is main **v1.56.3** (patch uploaded, no SQL beyond R50). **All three lines are merged: v1.58.0** = R51 (HR) + R52 (mobile, renumbered from "R57") + R53-001/002 (security, mobile admin). **v1.62.0 = R54-001 … R54-005 + R56-001 + R56-002** (employee card, one structure, HR messages) (type names per language, staff Excel import, approver unified with portal accounts) (HR leave management step 1 of Solution C: staff registry, leave requests manager → HR, hour types, Employee scope, Excel import). One cumulative patch; migrations R51 → R52 → R54-001 → R54-002 in that order; the app client lives at `/customer/mobile/`.

## 1. Where the code is coming from (three lines of work)

| Line | Where it is built | State on 29 Oct 09:30 | Touches |
|---|---|---|---|
| **Main** (this session) — R40 … R53-001, **v1.56.3** | `/home/claude/golden-portal` (sandbox of this chat); delivered as `goldensas-portal.zip` + cumulative `contacts-import-patch.zip` | Delivered and **live** (v1.56.3 patch; database migrated through R50) | everything |
| **HR** — R51-001 (HR role, Planning-only scope) + the three additions (leave types, entitlements, HR report) | **the same sandbox tree**, edited by the other chat session | **Delivered v1.57.0** (taken over by this session: role scope, gates, leave types, entitlements, report, Excel) | `app/auth.php`, `index.php`, `app/pl.php`, `views/planning.php`, `install/migrate_cp_r51_hr_role.sql`, lang, version |
| **Mobile** — "R57", Service Engineer app 0.5.0 (`customer/api/v1`, JWT, sync, push tables) | the app chat's own sandbox (`/home/claude/work/gas-service-app` **there**, not here) | **Merged into the main tree as R52 (v1.58.0)**; client at `customer/mobile/`, module at `customer/api/v1/` | new folder `customer/api/v1/`, new folder `customer/app/` files of the app (check: the app package says "copy app/ → customer/app/" — see §4), `install/migrate_cp_r57_mobile_engineer.sql`, `cp_settings` (api_min_version_engineer, JWT secret) |

## 2. One numbering line (decision)

The mobile line jumped to **R57** while the main line is at **R50**. To keep one ascending series on the About page and in the guide:

- **R51** — HR role & leave planning — **delivered v1.57.0 (live once uploaded + R51 SQL run)**.
- **R52** — Mobile API & Service Engineer app integration — **merged, v1.58.0** (renumbered "R57" → R52; migration file renamed `migrate_cp_r52_mobile_engineer.sql`, `cp_patches` entry `GAS-CP-R52-001`, check `r52` = `SHOW TABLES LIKE 'mob_devices'`). The app's own build numbers (0.5.0 …) stay as they are — they are the app's, not the portal's.
- **R53-001** — CSP / Permissions-Policy — **delivered as v1.56.3 (live)**. Revoke device and the push sender follow at the R52 merge (R53-002).
- **R54-001** — HR leave management step 1 — **v1.59.0** (migration `migrate_cp_r54_hr_requests.sql`, check `r54`). R55 = statutory allowance engine (next).
- **R54-002** — type names in five languages, staff import from Excel — **v1.59.1** (`migrate_cp_r54_002_hr_names.sql`, check `r54b`).
- **R54-003** — approver = portal account manager → discipline manager → department head (no SQL) — **v1.59.2**.
- **R54-004** — import of past leave records from Excel (no SQL) — **v1.60.0**; workbook `GAS-HR-leaves-import-2026.xlsx` delivered.
- **R54-005** — leave history per person for HR (no SQL) — **v1.60.1**.
- **R56-001** — employee card: contracts, hiring / termination / re-hiring, documents, total service — **v1.61.0** (`migrate_cp_r56_employee_card.sql`, check `r56`); workbook `GAS-HR-people-import.xlsx` delivered.
- **R56-002** — Disciplines as the one structure, Employee scope with own program, HR ↔ employee messages — **v1.62.0** (`migrate_cp_r56_002_hr_unify.sql`, check `r56b`); workbooks `GAS-HR-people-import.xlsx` (regenerated) and `GAS-HR-leaves-import-2024-2026.xlsx` delivered.
- **R56-003** — "History only" leave records (`migrate_cp_r56_003_history_only.sql`, check `r56c`) — **v1.62.1**; `GAS-HR-balances-2026.xlsx` delivered.
- **R56-004** — HR requests linked to the employee card, approve / reject on the card (no SQL) — **v1.62.2**.
- **R55-001** — statutory leave engine, HR lock / unlock, change log, import batches (`migrate_cp_r55_entitlement_engine.sql`, check `r55`) — **v1.63.0**.
- **R55-002** — allowances for every type, Greek / Italian rule sets, contractors (`migrate_cp_r55_002_rules_types.sql`, check `r55b`) — **v1.64.0**.
- **R55-003** — People filters (no SQL) — **v1.64.1**.
- **R55-004** — gender, leave types per person, carry-over per person, status summary (`migrate_cp_r55_004_gender_types.sql`, check `r55c`) — **v1.65.0**.
- **R55-005** — HR dashboard, former employees in the leave import (no SQL) — **v1.66.0**.
- **R57-001** — projects active through their offers, status Quoted (`migrate_cp_r57_project_quoted.sql`, check `r57`) — **v1.67.0**.
- Portal versions continue **1.67.0 (R57-001)**; whoever packages last sets `CP_VERSION` and adds the `cp_patches` entries in that order.

## 3. Migration order for the live database (phpMyAdmin, `goldensas_portal` selected)

Applied on the live database up to R50 (`migrate_cp_r50_assistant.sql`); R53 needs no SQL. Then, strictly in this order, each only when its patch is uploaded:

1. `migrate_cp_r51_hr_role-goldensas_portal.sql` — `staff_roles.scope`, HR role, then the HR tables (leave types, entitlements, `plan_entries.reason`) from the HR session's final file.
2. `migrate_cp_r52_mobile_engineer-goldensas_portal.sql` (the renamed R57 file) — `mob_devices`, `mob_refresh_tokens`, `mob_push_queue`, `sync_ops`, settings. It depends on R49 (`session_epoch`) and R50 (`ai_log`, `can_ai`) — both already applied.
3. R53 — no SQL.
4. `migrate_cp_r54_hr_requests-goldensas_portal.sql` then `migrate_cp_r54_002_hr_names-goldensas_portal.sql` then `migrate_cp_r56_employee_card-goldensas_portal.sql` then `migrate_cp_r56_002_hr_unify-goldensas_portal.sql` then `migrate_cp_r56_003_history_only-goldensas_portal.sql` then `migrate_cp_r55_entitlement_engine-goldensas_portal.sql` then `migrate_cp_r55_002_rules_types-goldensas_portal.sql` then `migrate_cp_r55_004_gender_types-goldensas_portal.sql` then `migrate_cp_r57_project_quoted-goldensas_portal.sql` (projects: status Quoted) — departments, people (seeded from staff accounts), name map, leave-type columns + new types, `hr_requests`, entitlement columns, `plan_entries.request_id`. After it: Settings › HR › People (departments' heads, technical flags, hiring dates), Leave types (accountant validates), Import balances (2026 Excel), then give the non-project staff a role scoped Employee.

Rule: every migration keeps the `USE goldensas_portal;` copy in the delivery, is idempotent where possible (`IF NOT EXISTS`, `INSERT IGNORE`), and the About page check (`cp_patches[].check`) tells you if one was skipped.

## 4. Upload sequence and the files that collide

Upload **one** cumulative patch, built last, from the main tree after both branches are merged into it; never upload the app package's `app/` or `api/` folders on their own. Until the merge, the live site stays on the main line (v1.56.3 now); the app package stays unused.

Files edited by more than one line (merge by hand or by the packaging session — never by overwriting):

| File | Main | HR | Mobile |
|---|---|---|---|
| `customer/index.php` | routes `admin_ai`, `ai` endpoint, `relock` | `hr_gate()`, `HR_ACTIONS` check | none expected (the API has its own front controller `api/v1/index.php`) |
| `customer/app/auth.php` | R49 session policy | HR scope (`hr_only`, `HR_PAGES`, `HR_ACTIONS`) | reads `session_epoch` (R49) through `api_act_as` — must keep the epoch check |
| `customer/app/pl.php`, `views/planning.php` | — | absence-only editing, report | — |
| `customer/app/version.php` | up to R50-003 | adds R51 | adds R52 (renumbered) |
| `customer/lang/{en,el,it,bg,uk}.php` | 4722 keys each | appends `hr.*`, `pl.*` | appends `api.*` / app keys if any — **all five files must end with the same key count** |
| `customer/views/_layout.php` | nav items | HR hides the nav except Planning | — |
| `customer/views/_manual_golden.php` | sections up to Assistant | adds "HR — leave planning" | adds "Mobile app for Service Engineers" |
| `customer/install/cp_selfcheck.php` | lines to R50 | adds R51 line | adds R52 line |
| `customer/app/ai.php` | R50 | — | calls `ai_run()` / `ai_can()` — keep signatures unchanged |
| `customer/app/` (the mobile package says "copy app/ → customer/app/") | | | **Check before copying**: if the app ships its *client* under `app/`, it must land in `customer/app-client/` (or `customer/mobile/`), not in `customer/app/`, which is the portal's PHP library folder. Overwriting `customer/app/*.php` with app files would break the portal. |

## 5. What this session will do when the two packages are here

1. You attach `GAS-Service-Engineer-App_0.5.0.zip` to this chat (and the HR session's patch when it delivers, or tell me it wrote into the shared tree).
2. I merge both into the main tree: renumber R57 → R52, resolve the files in §4, rebuild all five language files to equal key counts, run the full lint + page smoke tests + the app's `tests/portal-api.test.js` against the sandbox, update manuals and guide, then build **one** `contacts-import-patch.zip` (cumulative) and `goldensas-portal.zip` at **v1.58.0**, with the migrations in the order of §3.
3. Then R53 (security follow-ups) on top, as v1.58.1.

## 6. Standing packaging rules (unchanged)
Binary FTP upload then Ctrl+F5; `app/config.php` never in a zip; vendor licence kit never shipped; `php tools/build-manifest.php` twice before zipping; every release note carries a short security review; manuals updated with every version.

# Security review — GAS-CP-R67-001 · HR Assistant (portal v1.78.0)
Prepared by Nikos Polykandriotis · R0 · 31 Oct 2026

## Controls in place (CS-36 … CS-45)
| # | Control | Where |
|---|---|---|
| CS-36 | **Staging, never direct writes.** A flow stores proposals in `hr_flow_fields`; the card is written only by `hra_apply()` (HR / Owner), through `hr_personal_save()` and whitelisted columns, inside a transaction, with `hr_audit_card()` (secrets masked as on every card save). | `app/hra.php` |
| CS-37 | **Encryption at rest** of staged values of `HR_PERSONAL_SECRET` fields (AMKA, ΑΦΜ, ID, passport, IBAN): libsodium secretbox, key = generichash(instance id · session name from `config.php` · random salt in `cp_settings.hra_salt`). A database dump alone does not reveal them. | `hra_key / hra_enc / hra_dec` |
| CS-38 | **Access**: the owner of the flow and HR / Owner only (`hra_can_view`); plain administrators get counts and 403 on the flow (same rule as R64-001 personal data); confidential flows (dismissal, R67-003) are hidden from the employee by the same function. Documents stream with `nosniff`, `CSP sandbox`, inline only for PDF / JPEG. | `hra_can_view`, `hra_doc_stream` |
| CS-39 | **Validation at entry**: AMKA 11 digits + date-of-birth match, ΑΦΜ mod-11 check digit, IBAN mod-97, plausible birth dates, enumerated selects, lengths from the field catalogue; only `HRA_EMP_FIELDS` are accepted whatever the template says (`hra_template_clean`). | `hra_validate` |
| CS-40 | **Step-up authentication**: the identification step and the submission require a valid TOTP code when the account has 2FA (`hra_stepup_check`); the standard CSRF token, `data-lock` single submit and the HR / employee action allow-lists (`HR_ACTIONS`, `EMP_ACTIONS`) apply. | `hra_step_save_from_post`, `hra_submit` |
| CS-41 | **Uploads**: MIME sniffed server-side, 15 MB cap, images re-encoded with GD (EXIF and payloads dropped, ≤ 2000 px), PDFs with `/JavaScript`, `/JS`, `/Launch`, `/EmbeddedFile`, `/OpenAction` refused; files under `storage/hr/flows/<id>/` (0700) with SHA-256, moved to the card folder on approval. | `hra_doc_store_from_files`, `hra_pdf_active`, `hra_reencode` |
| CS-42 | **Assistant boundary**: page `hra` is off until enabled in Settings › Assistant › Pages; the context carries only the field catalogue of the current step (no current values, no payroll field is ever in `HRA_EMP_FIELDS`); photos are re-encoded and sent in memory only, never stored; every call logged in `ai_log`; suggestions are validated by `hra_validate` before being shown and are written only when the employee saves the step. | `hra_assist` |
| CS-43 | **GDPR**: Art. 13 notice (controller, purpose, legal basis, recipients, retention, rights) on the first step; acceptance stored with timestamp and IP in `hr_flows` and in `hr_flow_log`; staged values and files purged 90 days after the decision (`hra_purge`, via `nf_sweep`); withdraw available to the employee at any time. | `hra_consent`, `hra_purge` |
| CS-44 | **Audit trail**: `hr_flow_log` (opened, consent, step, doc, assist, submitted, field decisions, returned, rejected, applied, purged) with user and IP; card changes in `hr_audit` with the flow number. | `hra_log` |
| CS-45 | **Rate / abuse**: one open flow per person and kind; reminders once a day; the assistant is behind the portal's existing per-user rate limit (`AI_RATE`); uploads only on draft flows by their owner. | — |

## Findings / proposals (no performance impact)
| # | Proposal | Priority |
|---|---|---|
| F-11 | Require 2FA for HR and Owner accounts (they decrypt staged identification numbers) — `twofa_required` is already the default for new accounts; verify the existing HR accounts in Settings › Portal accounts. | before go-live |
| F-12 | Keep `hra_salt` out of plain database backups: the updater's `db.sql.gz` includes `cp_settings`; store the backups outside the web root (already the case under `storage/backups/`) and restrict the cPanel backup download to the administrator. | before go-live |
| F-13 | R67-002 invitations: token hashed, 7-day expiry, single use, bound to the e-mail, 5 attempts (table ready); e-mail delivery goes through the existing `.eml` draft flow until SMTP is configured. | R67-002 |
| F-14 | Add `hra` to the Permissions-Policy / CSP review of R53-001 when the camera capture (`<input capture>`) is added for the mobile apps. | R68 |
| F-15 | Consider a yearly re-confirmation campaign ("confirm your card") driven by `hra_remind` so stale data is caught without HR effort. | later |

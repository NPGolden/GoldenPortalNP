USE goldensas_portal;
-- GAS-CP-R24-001 · Branches: the Greek head office and the Italian branch in one portal (design GAS-2026-PORTAL-BRN-001).
-- Select the portal database first (goldensas_portal). Re-running reports "already exists" / "duplicate column" — harmless.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS branches (
  id             TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(8)   NOT NULL UNIQUE,                  -- GR · IT
  name           VARCHAR(80)  NOT NULL,                         -- shown on pills and filters
  legal_name     VARCHAR(200) NOT NULL,                         -- document headers
  trade_name     VARCHAR(120) NULL,                             -- "Golden Automation Systems" / "Golden A/S Italian Branch"
  address        VARCHAR(300) NULL,                             -- one line, parts separated by " · "
  country        CHAR(2)      NOT NULL DEFAULT 'GR',
  vat_no         VARCHAR(30)  NULL,                             -- EL998820270 · IT02768900462
  registry       VARCHAR(160) NULL,                             -- GEMI no · Camera di Commercio
  email          VARCHAR(190) NULL,
  phone          VARCHAR(60)  NULL,
  doc_prefix     VARCHAR(8)   NOT NULL DEFAULT 'GAS',           -- GAS-WR-… / GIT-WR-…
  offer_series   VARCHAR(16)  NULL,                             -- portal-generated offer numbers (GIT-OF) when the ERP has no series
  work_calendar  VARCHAR(8)   NULL,                             -- default calendar of the branch (work_calendars.code)
  currency       CHAR(3)      NOT NULL DEFAULT 'EUR',
  logo_variant   VARCHAR(20)  NOT NULL DEFAULT 'default',       -- assets/golden-logo.png · assets/golden-logo-<variant>.png
  is_default     TINYINT(1)   NOT NULL DEFAULT 0,
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order     SMALLINT     NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO branches (code, name, legal_name, trade_name, address, country, vat_no, registry, doc_prefix, offer_series, work_calendar, is_default, sort_order) VALUES
 ('GR', 'Greece · head office', 'ΣΥΣΤΗΜΑΤΑ ΙΣΧΥΟΣ ΤΕΧΝΙΚΗ ΚΑΙ ΕΜΠΟΡΙΚΗ ΜΟΝΟΠΡΟΣΩΠΗ Α.Ε.', 'Golden Automation Systems', 'Kiou 5 · 18756 Keratsini · Piraeus · Greece', 'GR', 'EL998820270', 'GEMI 112221108000', 'GAS', NULL, 'GR', 1, 10),
 ('IT', 'Italy · branch', 'GOLDEN AS ITALIAN', 'Golden A/S Italian Branch', 'Piazza Cavalier Pietro Lunardi 91 · 55054 Massarosa (LU) · Italy', 'IT', 'IT02768900462', 'Camera di Commercio Toscana Nord-Ovest', 'GIT', 'GIT-OF', 'IT', 0, 20);

ALTER TABLE projects       ADD COLUMN branch_id TINYINT UNSIGNED NULL AFTER customer_id;           -- NULL = default branch (head office)
ALTER TABLE subprojects    ADD COLUMN branch_id TINYINT UNSIGNED NULL AFTER project_id;            -- NULL = inherits the project's branch
ALTER TABLE customer_users ADD COLUMN branch_id TINYINT UNSIGNED NULL AFTER work_calendar,         -- home branch of a staff account
                           ADD COLUMN branch_scope TINYINT(1) NOT NULL DEFAULT 0;                 -- 1 = sees only the home branch's projects (administrators always group-wide)

-- =====================================================================
--  GOLDEN Customer Portal — MySQL 8.0 schema.        GAS-DB-CP-001
--  All portal queries are scoped by customers.id; a customer user can
--  never see another customer's vessels, projects or documents.
-- =====================================================================
SET NAMES utf8mb4;

CREATE TABLE customers (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(160) NOT NULL,
  erp_code   VARCHAR(32)  NULL UNIQUE,   -- SoftOne CUSTOMER_CODE; the sync matches on this
  country    VARCHAR(80)  NULL,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NOT NULL,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  locale        CHAR(2)      NOT NULL DEFAULT 'en',   -- en | el | it
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  failed_logins TINYINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until  DATETIME     NULL,
  last_login_at DATETIME     NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_cu_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE vessels (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,                      -- R4: may be unassigned
  name        VARCHAR(120) NOT NULL,
  imo         VARCHAR(16)  NULL,
  yard        VARCHAR(120) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_v_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  INDEX idx_v_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE projects (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    INT UNSIGNED NOT NULL,
  vessel_id      INT UNSIGNED NULL,
  name           VARCHAR(160) NOT NULL,                -- e.g. Refit-2026
  erp_code       VARCHAR(40)  NULL UNIQUE,             -- SoftOne project CODE
  source         ENUM('manual','erp') NOT NULL DEFAULT 'manual',
  status         ENUM('active','onhold','completed') NOT NULL DEFAULT 'active',
  category        VARCHAR(80)  NULL,                   -- SoftOne CATEGORY
  project_manager VARCHAR(120) NULL,                   -- SoftOne PROJECT_MANAGER
  next_milestone VARCHAR(120) NULL,                    -- e.g. "FAT in 3 weeks"
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_p_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_p_vessel   FOREIGN KEY (vessel_id)   REFERENCES vessels(id),
  INDEX idx_p_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lifecycle progress per sub-project: Engineering → Production → FAT → SAT/HAT
CREATE TABLE subprojects (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  name            VARCHAR(120) NOT NULL,              -- e.g. IAS, Generators, Bridge
  pct_engineering TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0..100 per stage
  pct_production  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pct_fat         TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pct_sathat      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_s_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  INDEX idx_s_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE documents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  vessel_id   INT UNSIGNED NULL,
  project_id  INT UNSIGNED NULL,
  title       VARCHAR(200) NOT NULL,                  -- e.g. FAT-ALPHA-2026-v3
  category    ENUM('manual','drawing','certificate','fat','fsr','workorder','cyber','other')
              NOT NULL DEFAULT 'other',
  status      ENUM('published','sent_for_signature','pm_review','approved','signed','expiring','restricted')
              NOT NULL DEFAULT 'published',
  file_path   VARCHAR(255) NULL,                      -- under storage/uploads, streamed after access check
  expires_at  DATE NULL,                              -- certificates
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_d_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_d_vessel   FOREIGN KEY (vessel_id)   REFERENCES vessels(id),
  CONSTRAINT fk_d_project  FOREIGN KEY (project_id)  REFERENCES projects(id),
  INDEX idx_d_customer (customer_id), INDEX idx_d_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id      INT UNSIGNED NOT NULL,
  customer_user_id INT UNSIGNED NOT NULL,
  vessel_id        INT UNSIGNED NULL,
  subject          VARCHAR(200) NOT NULL,
  body             TEXT NOT NULL,
  status           ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_t_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
  CONSTRAINT fk_t_user     FOREIGN KEY (customer_user_id) REFERENCES customer_users(id),
  INDEX idx_t_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync history, shown nowhere to customers; for the administrator.
CREATE TABLE erp_sync_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ok         TINYINT(1) NOT NULL,
  fetched    INT UNSIGNED NOT NULL DEFAULT 0,
  matched    INT UNSIGNED NOT NULL DEFAULT 0,
  skipped    INT UNSIGNED NOT NULL DEFAULT 0,
  message    VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===== R3: project-management tool =====
-- ---- 1. Roles: PMs (staff/admin) use the same portal as customers ----
ALTER TABLE customer_users
  ADD COLUMN role ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer' AFTER customer_id,
  MODIFY customer_id INT UNSIGNED NULL;                 -- staff belong to no customer

-- ---- 2. Project tree ------------------------------------------------
-- project_code = everything before "Y." in the ERP code  (e.g. GY-85-02)
-- subprojects.code = the part from "Y." onward             (e.g. Y.01)
ALTER TABLE projects
  ADD COLUMN project_code VARCHAR(40) NULL AFTER erp_code,
  ADD COLUMN pm_user_id   INT UNSIGNED NULL AFTER project_manager,
  ADD INDEX idx_p_code (project_code);

ALTER TABLE subprojects
  ADD COLUMN code              VARCHAR(40)  NULL AFTER project_id,
  ADD COLUMN erp_code          VARCHAR(40)  NULL AFTER code,
  ADD COLUMN system_id         INT UNSIGNED NULL AFTER erp_code,
  ADD COLUMN description       TEXT NULL AFTER name,
  ADD COLUMN pct_procurement   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pct_engineering,
  ADD COLUMN pct_installation  TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pct_fat,
  ADD COLUMN pct_commissioning TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pct_sathat,
  ADD COLUMN status            ENUM('active','onhold','completed') NOT NULL DEFAULT 'active',
  ADD COLUMN sort_order        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  ADD INDEX idx_s_code (code);

-- ---- 3. Vessel systems master list (admin-managed, extendable) -------
CREATE TABLE vessel_systems (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(120) NOT NULL UNIQUE,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subprojects
  ADD CONSTRAINT fk_s_system FOREIGN KEY (system_id) REFERENCES vessel_systems(id) ON DELETE SET NULL;

-- ---- 4. Drawing tracker (requests we send to yard / suppliers) -------
CREATE TABLE drawing_requests (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  subproject_id   INT UNSIGNED NULL,
  system_id       INT UNSIGNED NULL,
  kind            ENUM('equipment','principal') NOT NULL DEFAULT 'equipment',
  seq_no          SMALLINT UNSIGNED NULL,
  title           VARCHAR(200) NOT NULL,              -- Equipment / Requirement  or  Drawing / Study
  location        VARCHAR(120) NULL,
  location_status ENUM('TBD','Confirmed','N/A') NOT NULL DEFAULT 'TBD',
  responsible     VARCHAR(80)  NOT NULL DEFAULT 'Shipyard',   -- Shipyard | Golden A/S | Supplier name
  request_sent    DATE NULL,
  expected_at     DATE NULL,
  received_at     DATE NULL,
  file_name       VARCHAR(255) NULL,
  file_id         INT UNSIGNED NULL,
  status          ENUM('pending','received','not_required') NOT NULL DEFAULT 'pending',
  remarks         TEXT NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dr_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_dr_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  CONSTRAINT fk_dr_system  FOREIGN KEY (system_id)     REFERENCES vessel_systems(id) ON DELETE SET NULL,
  INDEX idx_dr_project (project_id), INDEX idx_dr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE drawing_tracker_revisions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  rev_no      VARCHAR(16) NOT NULL,                   -- R0, R1 ...
  issued_at   DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  issued_by   VARCHAR(80)  NOT NULL DEFAULT 'Golden A/S',
  sent_to     VARCHAR(80)  NULL,
  CONSTRAINT fk_dtr_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 5. Files: photos, reports, submitted docs (visibility-aware) ----
CREATE TABLE sp_files (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  kind          ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other')
                NOT NULL DEFAULT 'other',
  title         VARCHAR(200) NULL,
  stored_name   VARCHAR(255) NOT NULL,                -- under storage/uploads/YYYY/MM
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(100) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL,
  visibility    ENUM('internal','external') NOT NULL DEFAULT 'external',
  note_id       INT UNSIGNED NULL,                    -- photo attached to a weekly note
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_spf_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_spf_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE CASCADE,
  INDEX idx_spf_sub (subproject_id), INDEX idx_spf_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 6. Documents submitted for Owner approval ------------------------
CREATE TABLE owner_approvals (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  doc_no        VARCHAR(60)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  revision      VARCHAR(16)  NOT NULL DEFAULT 'R0',
  submitted_at  DATE NULL,
  due_at        DATE NULL,
  decided_at    DATE NULL,
  status        ENUM('draft','submitted','approved','approved_comments','rejected','resubmitted')
                NOT NULL DEFAULT 'submitted',
  comments      TEXT NULL,
  file_id       INT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_oa_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_oa_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  CONSTRAINT fk_oa_file    FOREIGN KEY (file_id)       REFERENCES sp_files(id)    ON DELETE SET NULL,
  INDEX idx_oa_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 7. Class submittals (DNV / BV / LR / ABS / RINA ...) ---------------
CREATE TABLE class_submittals (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id    INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  class_society VARCHAR(40)  NOT NULL,
  class_ref     VARCHAR(80)  NULL,                    -- society's own reference
  doc_no        VARCHAR(60)  NOT NULL,
  title         VARCHAR(200) NOT NULL,
  revision      VARCHAR(16)  NOT NULL DEFAULT 'R0',
  submitted_at  DATE NULL,
  due_at        DATE NULL,
  decided_at    DATE NULL,
  status        ENUM('draft','submitted','approved','approved_comments','rejected','resubmitted')
                NOT NULL DEFAULT 'submitted',
  comments      TEXT NULL,
  file_id       INT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cs_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_cs_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  CONSTRAINT fk_cs_file    FOREIGN KEY (file_id)       REFERENCES sp_files(id)    ON DELETE SET NULL,
  INDEX idx_cs_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 8. Comments: internal (Golden only) + external (the customer chat)
CREATE TABLE sp_comments (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  author_id     INT UNSIGNED NOT NULL,                -- customer_users.id (staff or customer)
  visibility    ENUM('internal','external') NOT NULL DEFAULT 'external',
  parent_id     INT UNSIGNED NULL,
  body          TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_spc_sub    FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE CASCADE,
  CONSTRAINT fk_spc_parent FOREIGN KEY (parent_id)     REFERENCES sp_comments(id) ON DELETE SET NULL,
  INDEX idx_spc_sub (subproject_id, visibility)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 9. Weekly notes (with photos via sp_files.note_id) ----------------
CREATE TABLE sp_notes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  author_id     INT UNSIGNED NOT NULL,
  note_date     DATE NOT NULL,
  iso_week      CHAR(8) NOT NULL,                     -- 2026-W41
  visibility    ENUM('internal','external') NOT NULL DEFAULT 'external',
  body          TEXT NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_spn_sub FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE CASCADE,
  INDEX idx_spn_week (subproject_id, iso_week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_files
  ADD CONSTRAINT fk_spf_note FOREIGN KEY (note_id) REFERENCES sp_notes(id) ON DELETE SET NULL;

-- ---- 10. Progress snapshots (monthly report = delta between snapshots)
CREATE TABLE sp_progress_snapshots (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subproject_id     INT UNSIGNED NOT NULL,
  snap_date         DATE NOT NULL,
  pct_engineering   TINYINT UNSIGNED NOT NULL,
  pct_procurement   TINYINT UNSIGNED NOT NULL,
  pct_production    TINYINT UNSIGNED NOT NULL,
  pct_fat           TINYINT UNSIGNED NOT NULL,
  pct_installation  TINYINT UNSIGNED NOT NULL,
  pct_sathat        TINYINT UNSIGNED NOT NULL,
  pct_commissioning TINYINT UNSIGNED NOT NULL,
  CONSTRAINT fk_sps_sub FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE CASCADE,
  UNIQUE KEY uq_sps (subproject_id, snap_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 11. FAT / HAT / SAT test reports ----------------------------------
CREATE TABLE test_reports (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  kind          ENUM('FAT','HAT','SAT') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  planned_at    DATE NULL,
  performed_at  DATE NULL,
  status        ENUM('planned','performed','passed','punch_open','failed','closed') NOT NULL DEFAULT 'planned',
  remarks       TEXT NULL,
  file_id       INT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tr_sub  FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tr_file FOREIGN KEY (file_id)       REFERENCES sp_files(id)    ON DELETE SET NULL,
  INDEX idx_tr_sub (subproject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- 12/13. GA drawing overlays: trays, penetrations, cables, panels --
CREATE TABLE ga_drawings (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  kind        ENUM('routing','panels') NOT NULL,      -- routing = trays+penetrations+cables
  title       VARCHAR(160) NOT NULL,
  deck        VARCHAR(60)  NULL,
  file_id     INT UNSIGNED NOT NULL,                  -- the GA image (PNG/JPG/SVG)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ga_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ga_file    FOREIGN KEY (file_id)    REFERENCES sp_files(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each item is placed on the drawing in PERCENT coordinates (0-100), so
-- the overlay scales with the image. Lines (trays, cables) use x2/y2.
CREATE TABLE ga_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  drawing_id  INT UNSIGNED NOT NULL,
  layer       ENUM('tray','penetration','cable','panel') NOT NULL,
  tag         VARCHAR(60)  NOT NULL,                  -- e.g. T-ER-01, P-FR42, C-0117, DB-3
  label       VARCHAR(160) NULL,
  x           DECIMAL(6,3) NOT NULL,
  y           DECIMAL(6,3) NOT NULL,
  x2          DECIMAL(6,3) NULL,
  y2          DECIMAL(6,3) NULL,
  status      VARCHAR(40)  NOT NULL DEFAULT 'planned',
  remarks     VARCHAR(255) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_gai_drawing FOREIGN KEY (drawing_id) REFERENCES ga_drawings(id) ON DELETE CASCADE,
  UNIQUE KEY uq_gai (drawing_id, layer, tag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Status -> colour, per layer. Admin-editable; sensible defaults seeded.
CREATE TABLE ga_status_colors (
  layer  ENUM('tray','penetration','cable','panel') NOT NULL,
  status VARCHAR(40) NOT NULL,
  color  CHAR(7) NOT NULL,                            -- #RRGGBB
  label  VARCHAR(60) NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (layer, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ga_status_colors (layer, status, color, label, sort_order) VALUES
 ('tray','planned',    '#9AA7B4','Planned',        1),
 ('tray','supports',   '#D7B56D','Supports fitted',2),
 ('tray','installed',  '#2F7D5C','Installed',      3),
 ('tray','inspected',  '#0B1E2D','Inspected',      4),
 ('penetration','planned',  '#9AA7B4','Planned',     1),
 ('penetration','cut',      '#D7B56D','Cut',         2),
 ('penetration','cabled',   '#3B6EA8','Cabled',      3),
 ('penetration','sealed',   '#2F7D5C','Sealed',      4),
 ('penetration','inspected','#0B1E2D','Inspected',   5),
 ('cable','planned',    '#9AA7B4','Planned',     1),
 ('cable','pulled',     '#D7B56D','Pulled',      2),
 ('cable','terminated', '#3B6EA8','Terminated',  3),
 ('cable','tested',     '#2F7D5C','Tested',      4),
 ('panel','planned',    '#9AA7B4','Planned',       1),
 ('panel','delivered',  '#D7B56D','Delivered',     2),
 ('panel','mounted',    '#3B6EA8','Mounted',       3),
 ('panel','connected',  '#2F7D5C','Connected',     4),
 ('panel','commissioned','#0B1E2D','Commissioned', 5);

-- ---- 14. Vessel systems seed (from the MY-GY-85-02 drawing tracker) ----
INSERT INTO vessel_systems (name, sort_order) VALUES
 ('Power Sources / Generators',1),('Propulsion / Bow Thruster',2),('Air Condition',3),
 ('Main Switchboard',4),('Motor Control Console',5),('Transformers',6),('Pumps',7),
 ('Steering',8),('Anchors / Winches',9),('Hydraulics',10),('Engine Room Ventilation',11),
 ('Air / Compressors',12),('Engines / Heating',13),('Stabilizers',14),('Galley',15),
 ('Power Distribution (PDS)',16),('Lighting (LDS AC 230V)',17),('Lighting (ELS AC 230V)',18),
 ('Fire Detection / Firefighting',19),('Doors',20),('Battery Chargers',21),
 ('Communications / Electronics',22),('DC 24V',23),('Other Systems',24)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order);

-- ---------------------------------------------------------------- R5
-- Golden A/S roles for staff accounts, and the login history.
CREATE TABLE IF NOT EXISTS staff_roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL UNIQUE,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO staff_roles (name, sort_order) VALUES
  ('CEO', 1), ('Technical Manager', 2), ('Project Manager', 3), ('Service Manager', 4),
  ('Production Manager', 5), ('Engineering', 6);
ALTER TABLE customer_users
  ADD COLUMN staff_role_id INT UNSIGNED NULL AFTER role,
  ADD CONSTRAINT fk_cu_staff_role FOREIGN KEY (staff_role_id) REFERENCES staff_roles(id) ON DELETE SET NULL;
CREATE TABLE IF NOT EXISTS login_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  email       VARCHAR(190) NOT NULL,
  outcome     ENUM('success','failed','locked','disabled','logout') NOT NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ll_time (created_at), INDEX idx_ll_user (user_id), INDEX idx_ll_email (email), INDEX idx_ll_outcome (outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------- R6
-- Two-factor authentication (authenticator app) and presence.
ALTER TABLE customer_users
  ADD COLUMN totp_secret    VARCHAR(64) NULL      AFTER password_hash,
  ADD COLUMN totp_enabled   TINYINT(1)  NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN twofa_required TINYINT(1)  NOT NULL DEFAULT 1 AFTER totp_enabled,
  ADD COLUMN last_seen_at   DATETIME    NULL      AFTER last_login_at,
  ADD INDEX idx_cu_seen (last_seen_at);
ALTER TABLE login_log
  MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled') NOT NULL;
-- GAS Customer Portal — R7: Field Service Reports (form SF-GLD-014)    GAS-CP-R7-001
-- Run ONCE in phpMyAdmin.

CREATE TABLE IF NOT EXISTS fsr_reports (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  subproject_id   INT UNSIGNED NULL,
  report_no       VARCHAR(32)  NOT NULL UNIQUE,
  form_code       VARCHAR(20)  NOT NULL DEFAULT 'SF-GLD-014',
  report_date     DATE         NOT NULL,
  customer_name   VARCHAR(160) NULL,
  vessel_name     VARCHAR(120) NULL,
  location        VARCHAR(160) NULL,
  pic_name        VARCHAR(120) NULL,          -- person in charge (customer side)
  pic_position    VARCHAR(120) NULL,
  pic_mobile      VARCHAR(40)  NULL,
  pic_email       VARCHAR(190) NULL,
  status          ENUM('draft','submitted','accepted','disputed') NOT NULL DEFAULT 'draft',
  version         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by      INT UNSIGNED NULL,
  submitted_by    INT UNSIGNED NULL,
  submitted_name  VARCHAR(120) NULL,
  submitted_at    DATETIME     NULL,
  golden_sign_id  INT UNSIGNED NULL,          -- sp_files (signature image)
  accepted_by     INT UNSIGNED NULL,
  accepted_name   VARCHAR(120) NULL,
  accepted_at     DATETIME     NULL,
  accepted_ip     VARCHAR(45)  NULL,
  customer_sign_id INT UNSIGNED NULL,
  customer_remark TEXT         NULL,
  docx_file_id    INT UNSIGNED NULL,
  pdf_file_id     INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_fsr_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_fsr_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  INDEX idx_fsr_project (project_id), INDEX idx_fsr_sub (subproject_id), INDEX idx_fsr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fsr_lines (                -- personnel / time lines
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id   INT UNSIGNED NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  specialty   VARCHAR(80)  NOT NULL,
  start_time  TIME NULL,
  end_time    TIME NULL,
  time_type   ENUM('TT','WT') NOT NULL DEFAULT 'WT',
  hours       DECIMAL(5,2) NOT NULL DEFAULT 0,
  remarks     VARCHAR(160) NULL,
  CONSTRAINT fk_fsrl_report FOREIGN KEY (report_id) REFERENCES fsr_reports(id) ON DELETE CASCADE,
  INDEX idx_fsrl_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fsr_works (                -- work blocks: description, spare parts, open issues
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id   INT UNSIGNED NOT NULL,
  seq         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  description TEXT NULL,
  parts       JSON NULL,                              -- [{"d":"part","t":"W|G|I"}, …]
  open_issues TEXT NULL,
  CONSTRAINT fk_fsrw_report FOREIGN KEY (report_id) REFERENCES fsr_reports(id) ON DELETE CASCADE,
  INDEX idx_fsrw_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fsr_events (               -- the audit trail: created, submitted, accepted, disputed, reopened
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id   INT UNSIGNED NOT NULL,
  event       ENUM('created','saved','submitted','accepted','disputed','reopened') NOT NULL,
  user_id     INT UNSIGNED NULL,
  user_name   VARCHAR(120) NULL,
  ip          VARCHAR(45)  NULL,
  note        TEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_fsre_report FOREIGN KEY (report_id) REFERENCES fsr_reports(id) ON DELETE CASCADE,
  INDEX idx_fsre_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_files
  MODIFY kind ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other','fsr_photo','fsr_sign','fsr_doc') NOT NULL DEFAULT 'other',
  ADD COLUMN fsr_id INT UNSIGNED NULL AFTER note_id,
  ADD INDEX idx_spf_fsr (fsr_id);
-- GAS Customer Portal — R8: FSR hours per task, Work Orders (Job Orders)   GAS-CP-R8-001
-- Run ONCE in phpMyAdmin (after R7).

ALTER TABLE fsr_lines
  ADD COLUMN work_id  INT UNSIGNED NULL AFTER report_id,
  ADD COLUMN engineer VARCHAR(120) NULL AFTER specialty,
  ADD CONSTRAINT fk_fsrl_work FOREIGN KEY (work_id) REFERENCES fsr_works(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS job_orders (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  seq             INT UNSIGNED NOT NULL,                 -- per project
  jo_no           VARCHAR(64)  NOT NULL UNIQUE,          -- <project code>-JO-<seq>-<YYYYMMDD>
  title           VARCHAR(160) NOT NULL,
  description     TEXT NULL,
  location        VARCHAR(160) NULL,
  requested_for   DATE NULL,                             -- wanted date
  priority        ENUM('normal','urgent') NOT NULL DEFAULT 'normal',
  requested_side  ENUM('golden','customer') NOT NULL DEFAULT 'golden',
  requested_by    INT UNSIGNED NULL,
  requested_name  VARCHAR(120) NULL,
  status          ENUM('draft','pending_approval','approved','rejected','in_progress','done','cancelled') NOT NULL DEFAULT 'draft',
  approved_by     INT UNSIGNED NULL,
  approved_name   VARCHAR(120) NULL,
  approved_at     DATETIME NULL,
  approved_ip     VARCHAR(45) NULL,
  approval_note   TEXT NULL,
  started_at      DATETIME NULL,
  done_at         DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_jo_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  INDEX idx_jo_project (project_id), INDEX idx_jo_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jo_events (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_order_id  INT UNSIGNED NOT NULL,
  event         ENUM('created','saved','submitted','approved','rejected','started','done','cancelled','reopened','note') NOT NULL,
  user_id       INT UNSIGNED NULL,
  user_name     VARCHAR(120) NULL,
  ip            VARCHAR(45) NULL,
  note          TEXT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_joe_jo FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE CASCADE,
  INDEX idx_joe_jo (job_order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fsr_reports
  ADD COLUMN job_order_id INT UNSIGNED NULL AFTER subproject_id,
  ADD CONSTRAINT fk_fsr_jo FOREIGN KEY (job_order_id) REFERENCES job_orders(id) ON DELETE SET NULL;

ALTER TABLE sp_files ADD COLUMN fsr_task TINYINT UNSIGNED NULL AFTER fsr_id;
-- ================================================================ R9
-- Vessel systems: drawing code + abbreviation, seeded with the Golden A/S
-- master list (52 systems). Run once, after migrate_cp_r8_tasks_jo.sql.
-- Existing systems whose description matches one of the list (e.g. "Main
-- Switchboard", "Galley") receive their code and abbreviation; legacy
-- entries that are not in the list and not used anywhere are retired
-- (not deleted) and can be restored from Admin › Vessel systems.
-- ---------------------------------------------------------------------
ALTER TABLE vessel_systems
  ADD COLUMN code VARCHAR(10) NULL AFTER id,
  ADD COLUMN abbr VARCHAR(12) NULL AFTER code,
  ADD UNIQUE INDEX uq_vs_abbr (abbr),
  ADD INDEX idx_vs_code (code);

INSERT INTO vessel_systems (code, abbr, name, sort_order, is_active) VALUES
 ('10',  'MSB',   'MAIN SWITCHBOARD',                              10,  1),
 ('20',  'EMSB',  'EMERGENCY SWITCHBOARD',                         20,  1),
 ('30',  'EUPS',  'EMERGENCY UPS',                                 30,  1),
 ('40',  'MCC',   'MOTOR CONTROL CENTER',                          40,  1),
 ('50',  'SHC',   'SHORE CONNECTION',                              50,  1),
 ('60',  'EDC',   'EMERGENCY PANEL SUPPLIED FROM EMCY BATTERIES',  60,  1),
 ('70',  'AHU',   'A/C PANEL OR AIR HANDLING PANELS',              70,  1),
 ('80',  'AE',    'AUXILIARY ENGINES',                             80,  1),
 ('90',  'ELS',   'EMERGENCY LIGHTING SYSTEM',                     90,  1),
 ('100', 'PDS',   'POWER DISTRIBUTION SYSTEM',                     100, 1),
 ('200', 'AMS',   'ALARM AND MONITORING SYSTEM',                   200, 1),
 ('300', 'LDS',   'LIGHTING DISTRIBUTION SYSTEM',                  300, 1),
 ('400', 'AUXS',  'AUXILIARY SYSTEMS',                             400, 1),
 ('420', 'DECD',  'DECORATIVE DOORS',                              420, 1),
 ('440', 'WTD',   'WATER TIGHT DOORS',                             440, 1),
 ('460', 'WTHD',  'WEATHER TIGHT DOORS',                           460, 1),
 ('470', 'HPU',   'HYDRAULIC POWER UNIT',                          470, 1),
 ('480', 'NET',   'NETWORKING SYSTEM',                             480, 1),
 ('490', 'AV',    'AUDIO VIDEO PANEL',                             490, 1),
 ('500', 'DCHUB', 'HIGH VOLTAGE DC SYSTEM AND PROTECTION',         500, 1),
 ('510', 'STDC',  'STARTING DC',                                   510, 1),
 ('520', 'SDC',   'SERVICE DC',                                    520, 1),
 ('530', 'EMSS',  'EMERGENCY STOP SYSTEM',                         530, 1),
 ('540', 'NVS',   'NAVIGATION SYSTEM',                             540, 1),
 ('600', 'GMDSS', 'GMDSS PANEL',                                   600, 1),
 ('610', 'WHC',   'WHEELHOUSE CONTROL',                            610, 1),
 ('620', 'STG',   'STEERING CONTROL SYSTEM',                       620, 1),
 ('630', 'TEL',   'TELECOMMUNICATIONS',                            630, 1),
 ('640', 'TBS',   'TALK BACK SYSTEM',                              640, 1),
 ('650', 'TLGS',  'TELEGRAPH SYSTEM',                              650, 1),
 ('660', 'GBS',   'GLASS BRIDGE SYSTEM',                           660, 1),
 ('670', 'NVL',   'NAVIGATION LIGHTING SYSTEM',                    670, 1),
 ('680', 'UDWL',  'UNDER WATER LIGHTS',                            680, 1),
 ('690', 'DP',    'DYNAMIC POSITIONING',                           690, 1),
 ('700', 'BTH',   'BOW THRUSTER',                                  700, 1),
 ('710', 'STH',   'STERN THRUSTER',                                710, 1),
 ('720', 'PCS',   'PROPULSION CONTROL SYSTEM',                     720, 1),
 ('730', 'TRQM',  'TORQUE METER',                                  730, 1),
 ('740', 'AWL',   'ANCHOR WINDLASS SYSTEM',                        740, 1),
 ('750', 'WCH',   'WINCHES SYSTEM',                                750, 1),
 ('760', 'STB',   'STABILIZER SYSTEM',                             760, 1),
 ('800', 'FAS',   'FIRE DETECTION / GENERAL ALARM SYSTEM',         800, 1),
 ('810', 'FDR',   'FIRE DOORS',                                    810, 1),
 ('820', 'FES',   'FIRE EXTINGUISHING SYSTEM',                     820, 1),
 ('830', 'PA',    'PUBLIC ADDRESS SYSTEM',                         830, 1),
 ('900', 'RVO',   'REMOTE VALVE OPERATION',                        900, 1),
 ('910', 'TLS',   'TANK LEVEL SYSTEM',                             910, 1),
 ('920', 'FWS',   'FRESH WATER SYSTEM',                            920, 1),
 ('930', 'BLG',   'BILGE LEVEL SYSTEM',                            930, 1),
 ('940', 'HYD',   'HYDROPHORE SYSTEM',                             940, 1),
 ('950', 'GAL',   'GALLEY',                                        950, 1),
 ('960', 'PRV',   'PROVISION',                                     960, 1)
ON DUPLICATE KEY UPDATE code = VALUES(code), abbr = VALUES(abbr), name = VALUES(name),
                        sort_order = VALUES(sort_order), is_active = 1;

-- legacy entries without a code that nothing refers to: retire, keep history
UPDATE vessel_systems vs SET vs.is_active = 0
 WHERE vs.code IS NULL
   AND NOT EXISTS (SELECT 1 FROM subprojects s WHERE s.system_id = vs.id)
   AND NOT EXISTS (SELECT 1 FROM drawing_requests d WHERE d.system_id = vs.id);
-- =============================================================== R10
-- 1. Owner approvals decided online: who / when / from where, a decision
--    note, and a comment thread per document (owner <-> Golden A/S).
-- 2. Invoicing status per sub-project (not invoiced / partial / full).
-- Run once, after migrate_cp_r9_systems.sql.
-- ---------------------------------------------------------------------
ALTER TABLE owner_approvals
  ADD COLUMN decided_by    INT UNSIGNED NULL AFTER decided_at,
  ADD COLUMN decided_name  VARCHAR(120) NULL AFTER decided_by,
  ADD COLUMN decided_ip    VARCHAR(45)  NULL AFTER decided_name,
  ADD COLUMN decided_time  DATETIME     NULL AFTER decided_ip,
  ADD COLUMN decision_note TEXT         NULL AFTER decided_time,
  ADD COLUMN on_behalf     TINYINT(1)   NOT NULL DEFAULT 0 AFTER decision_note;

CREATE TABLE IF NOT EXISTS oa_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  approval_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  side        ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  kind        ENUM('comment','approved','approved_comments','rejected','resubmitted') NOT NULL DEFAULT 'comment',
  body        TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_oac_approval (approval_id),
  CONSTRAINT fk_oac_approval FOREIGN KEY (approval_id) REFERENCES owner_approvals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE subprojects
  ADD COLUMN invoicing    ENUM('none','partial','full') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN invoiced_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER invoicing;
-- =============================================================== R11
-- Timesheets: Golden A/S engineers book hours (normal / overtime /
-- holiday) on a main project and its sub-projects; the manager of the
-- engineer's discipline approves; approved sheets are marked invoiced
-- in groups. Disciplines carry their manager; each staff member has a
-- discipline and, optionally, a personal manager override.
-- Run once, after migrate_cp_r10_approvals_invoicing.sql.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS disciplines (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(80)  NOT NULL UNIQUE,
  manager_user_id INT UNSIGNED NULL,
  sort_order      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  CONSTRAINT fk_disc_manager FOREIGN KEY (manager_user_id) REFERENCES customer_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO disciplines (name, sort_order) VALUES
  ('Electrical', 1), ('Automation', 2), ('Navigation & Communication', 3), ('Service', 4),
  ('Engineering', 5), ('Production', 6), ('Project Management', 7);

ALTER TABLE customer_users
  ADD COLUMN discipline_id   INT UNSIGNED NULL AFTER staff_role_id,
  ADD COLUMN manager_user_id INT UNSIGNED NULL AFTER discipline_id,
  ADD CONSTRAINT fk_cu_discipline FOREIGN KEY (discipline_id)   REFERENCES disciplines(id)    ON DELETE SET NULL,
  ADD CONSTRAINT fk_cu_manager    FOREIGN KEY (manager_user_id) REFERENCES customer_users(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS timesheets (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ts_no           VARCHAR(40)  NOT NULL UNIQUE,
  user_id         INT UNSIGNED NOT NULL,
  user_name       VARCHAR(120) NOT NULL,
  discipline_id   INT UNSIGNED NULL,
  discipline_name VARCHAR(80)  NULL,
  project_id      INT UNSIGNED NOT NULL,
  period_start    DATE NOT NULL,
  period_end      DATE NOT NULL,
  status          ENUM('draft','submitted','approved','rejected','invoiced') NOT NULL DEFAULT 'draft',
  h_normal        DECIMAL(7,2) NOT NULL DEFAULT 0,
  h_overtime      DECIMAL(7,2) NOT NULL DEFAULT 0,
  h_holiday       DECIMAL(7,2) NOT NULL DEFAULT 0,
  note            VARCHAR(1000) NULL,
  submitted_at    DATETIME NULL,
  approver_id     INT UNSIGNED NULL,
  approved_by     INT UNSIGNED NULL,
  approved_name   VARCHAR(120) NULL,
  approved_at     DATETIME NULL,
  approval_note   VARCHAR(1000) NULL,
  invoice_ref     VARCHAR(60)  NULL,
  invoiced_by     INT UNSIGNED NULL,
  invoiced_name   VARCHAR(120) NULL,
  invoiced_at     DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ts_user (user_id, period_start),
  INDEX idx_ts_project (project_id, status),
  INDEX idx_ts_status (status, approver_id),
  INDEX idx_ts_invoice (invoice_ref),
  CONSTRAINT fk_ts_user    FOREIGN KEY (user_id)    REFERENCES customer_users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ts_project FOREIGN KEY (project_id) REFERENCES projects(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS timesheet_lines (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timesheet_id  INT UNSIGNED NOT NULL,
  work_date     DATE NOT NULL,
  subproject_id INT UNSIGNED NULL,                       -- NULL = the main project itself
  hour_type     ENUM('normal','overtime','holiday') NOT NULL DEFAULT 'normal',
  hours         DECIMAL(5,2) NOT NULL DEFAULT 0,
  description   VARCHAR(500) NULL,
  INDEX idx_tsl_sheet (timesheet_id),
  INDEX idx_tsl_sub (subproject_id),
  CONSTRAINT fk_tsl_sheet FOREIGN KEY (timesheet_id)  REFERENCES timesheets(id)  ON DELETE CASCADE,
  CONSTRAINT fk_tsl_sub   FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ts_events (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  timesheet_id INT UNSIGNED NOT NULL,
  event        VARCHAR(30)  NOT NULL,
  user_id      INT UNSIGNED NULL,
  user_name    VARCHAR(120) NULL,
  note         VARCHAR(1000) NULL,
  ip           VARCHAR(45)  NULL,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tse_sheet (timesheet_id),
  CONSTRAINT fk_tse_sheet FOREIGN KEY (timesheet_id) REFERENCES timesheets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Comment threads: replies to a specific comment, and authors may delete their own
ALTER TABLE oa_comments
  ADD COLUMN parent_id  INT UNSIGNED NULL AFTER approval_id,
  ADD COLUMN deleted_at DATETIME NULL AFTER created_at,
  ADD CONSTRAINT fk_oac_parent FOREIGN KEY (parent_id) REFERENCES oa_comments(id) ON DELETE SET NULL;
ALTER TABLE sp_comments
  ADD COLUMN deleted_at DATETIME NULL AFTER created_at;

-- ================================================================ R14
-- Run once, after migrate_cp_r11_timesheets.sql (= install/migrate_cp_r14_tickets.sql).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tickets (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_no      VARCHAR(24)  NOT NULL UNIQUE,                -- TK-YYYY-NNNN
  customer_id    INT UNSIGNED NULL,
  vessel_id      INT UNSIGNED NOT NULL,
  project_id     INT UNSIGNED NULL,
  subproject_id  INT UNSIGNED NULL,
  system_id      INT UNSIGNED NULL,                           -- vessel system concerned
  title          VARCHAR(160) NOT NULL,
  description    TEXT NULL,
  category       ENUM('fault','service','warranty','spare_parts','question','other') NOT NULL DEFAULT 'fault',
  priority       ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal',
  status         ENUM('open','in_progress','waiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  created_side   ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  created_by     INT UNSIGNED NULL,
  created_name   VARCHAR(120) NULL,
  assigned_to    INT UNSIGNED NULL,
  assigned_name  VARCHAR(120) NULL,
  resolution     TEXT NULL,
  resolved_at    DATETIME NULL,
  resolved_name  VARCHAR(120) NULL,
  closed_at      DATETIME NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_tk_vessel (vessel_id, status),
  INDEX idx_tk_project (project_id),
  INDEX idx_tk_customer (customer_id, status),
  INDEX idx_tk_assigned (assigned_to, status),
  CONSTRAINT fk_tk_vessel  FOREIGN KEY (vessel_id)     REFERENCES vessels(id)        ON DELETE CASCADE,
  CONSTRAINT fk_tk_project FOREIGN KEY (project_id)    REFERENCES projects(id)       ON DELETE SET NULL,
  CONSTRAINT fk_tk_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id)    ON DELETE SET NULL,
  CONSTRAINT fk_tk_system  FOREIGN KEY (system_id)     REFERENCES vessel_systems(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_comments (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id   INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED NULL,
  user_id     INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  side        ENUM('customer','golden') NOT NULL DEFAULT 'customer',
  visibility  ENUM('internal','external') NOT NULL DEFAULT 'external',
  body        TEXT NULL,
  ip          VARCHAR(45) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at  DATETIME NULL,
  INDEX idx_tkc_ticket (ticket_id),
  CONSTRAINT fk_tkc_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id)         ON DELETE CASCADE,
  CONSTRAINT fk_tkc_parent FOREIGN KEY (parent_id) REFERENCES ticket_comments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_files (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id     INT UNSIGNED NOT NULL,
  comment_id    INT UNSIGNED NULL,
  stored_name   VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_name VARCHAR(120) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tkf_ticket (ticket_id),
  CONSTRAINT fk_tkf_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  event      VARCHAR(30)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  note       VARCHAR(1000) NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tke_ticket (ticket_id),
  CONSTRAINT fk_tke_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- work orders and service reports may answer a ticket
ALTER TABLE job_orders  ADD COLUMN ticket_id INT UNSIGNED NULL AFTER project_id, ADD INDEX idx_jo_ticket (ticket_id);
ALTER TABLE fsr_reports ADD COLUMN ticket_id INT UNSIGNED NULL AFTER job_order_id, ADD INDEX idx_fsr_ticket (ticket_id);

-- ================================================================ R15
-- Run once, after migrate_cp_r14_tickets.sql (= install/migrate_cp_r15_offers_weekly.sql).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS offers (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id     INT UNSIGNED NOT NULL,
  subproject_id  INT UNSIGNED NULL,
  offer_no       VARCHAR(60)  NOT NULL,                      -- same identity as the sub-project, e.g. GY-85-02 Y.01
  revision       VARCHAR(8)   NOT NULL DEFAULT 'R0',
  title          VARCHAR(200) NOT NULL,
  amount         DECIMAL(14,2) NOT NULL DEFAULT 0,            -- customer amount
  currency       CHAR(3)      NOT NULL DEFAULT 'EUR',
  status         ENUM('sent','rejected','cancelled','approved','in_progress','to_be_invoiced','finished') NOT NULL DEFAULT 'sent',
  sent_at        DATE NULL,
  valid_until    DATE NULL,
  status_at      DATE NULL,                                  -- when the current status was set (aging)
  decided_by     INT UNSIGNED NULL,
  decided_name   VARCHAR(120) NULL,
  decided_at     DATETIME NULL,
  decided_ip     VARCHAR(45)  NULL,
  decision_note  TEXT NULL,
  file_id        INT UNSIGNED NULL,
  notes          TEXT NULL,
  created_by     INT UNSIGNED NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_of_project (project_id, status),
  INDEX idx_of_sub (subproject_id),
  CONSTRAINT fk_of_project FOREIGN KEY (project_id)    REFERENCES projects(id)    ON DELETE CASCADE,
  CONSTRAINT fk_of_sub     FOREIGN KEY (subproject_id) REFERENCES subprojects(id) ON DELETE SET NULL,
  CONSTRAINT fk_of_file    FOREIGN KEY (file_id)       REFERENCES sp_files(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS offer_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  offer_id   INT UNSIGNED NOT NULL,
  event      VARCHAR(30)  NOT NULL,
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  note       VARCHAR(1000) NULL,
  ip         VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ofe_offer (offer_id),
  CONSTRAINT fk_ofe_offer FOREIGN KEY (offer_id) REFERENCES offers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_files
  MODIFY kind ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other','fsr_photo','fsr_sign','fsr_doc','offer') NOT NULL DEFAULT 'other';

-- deadlines: the project as a whole and each sub-project
ALTER TABLE projects    ADD COLUMN deadline DATE NULL AFTER next_milestone;
ALTER TABLE subprojects ADD COLUMN deadline DATE NULL AFTER status;

-- the PM's weekly remarks per project: foreseen delays and general remarks
CREATE TABLE IF NOT EXISTS weekly_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id  INT UNSIGNED NOT NULL,
  week_start  DATE NOT NULL,                                 -- Monday
  delays      TEXT NULL,
  remarks     TEXT NULL,
  author_id   INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wn (project_id, week_start),
  CONSTRAINT fk_wn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================ R16
-- Run once, after migrate_cp_r15_offers_weekly.sql (= install/migrate_cp_r16_theme.sql).
-- ---------------------------------------------------------------------
ALTER TABLE customer_users ADD COLUMN ui_theme ENUM('light','dark') NOT NULL DEFAULT 'light' AFTER locale;
-- GAS-CP-R16-003 · optional indexes for the accounts filters and presence at 2 000+ users.
-- Safe to run twice (errors "Duplicate key name" can be ignored).
ALTER TABLE customer_users ADD INDEX idx_cu_role_active (role, is_active);
ALTER TABLE customer_users ADD INDEX idx_cu_last_login (last_login_at);
ALTER TABLE customer_users ADD INDEX idx_cu_locale (locale);
-- GAS-CP-R17-001 · Customer contacts (staff CRM): people, their vessels, dated notes.
-- Run after R16. Safe to run once; re-running reports "already exists".

CREATE TABLE IF NOT EXISTS contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NULL,
  company       VARCHAR(160) NULL,
  full_name     VARCHAR(120) NOT NULL,
  position      VARCHAR(120) NULL,
  category      VARCHAR(30) NOT NULL DEFAULT 'other',
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60) NULL,
  mobile        VARCHAR(60) NULL,
  birthday      DATE NULL,
  nameday_md    CHAR(5) NULL,
  interests     TEXT NULL,
  user_id       INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ct_customer (customer_id),
  KEY idx_ct_name (full_name),
  KEY idx_ct_email (email),
  KEY idx_ct_category (category),
  KEY idx_ct_birthday (birthday),
  KEY idx_ct_nameday (nameday_md),
  KEY idx_ct_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_vessels (
  contact_id  INT UNSIGNED NOT NULL,
  vessel_id   INT UNSIGNED NOT NULL,
  role        VARCHAR(80) NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (contact_id, vessel_id),
  KEY idx_cv_vessel (vessel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id  INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NULL,
  body        TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cn_contact (contact_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- GAS-CP-R17-002 · Companies master list, contact channels and subscriptions.
-- Run after migrate_cp_r17_contacts.sql. Re-running reports "already exists" / "duplicate column" — harmless.

CREATE TABLE IF NOT EXISTS companies (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name         VARCHAR(160) NOT NULL,
  kind         VARCHAR(30) NOT NULL DEFAULT 'other',
  customer_id  INT UNSIGNED NULL,
  country      VARCHAR(80) NULL,
  city         VARCHAR(80) NULL,
  website      VARCHAR(190) NULL,
  email        VARCHAR(190) NULL,
  phone        VARCHAR(60) NULL,
  notes        TEXT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_co_name (name),
  KEY idx_co_kind (kind),
  KEY idx_co_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE contacts
  ADD COLUMN company_id    INT UNSIGNED NULL AFTER customer_id,
  ADD COLUMN channels      VARCHAR(190) NULL AFTER mobile,
  ADD COLUMN subscriptions VARCHAR(120) NULL AFTER channels,
  ADD KEY idx_ct_company (company_id);

-- one company per customer of the portal (kind = owner), linked to it
INSERT IGNORE INTO companies (name, kind, customer_id, country, is_active)
  SELECT c.name, 'owner', c.id, c.country, c.is_active FROM customers c;

-- companies typed as free text on existing contacts
INSERT IGNORE INTO companies (name, kind)
  SELECT DISTINCT ct.company, CASE ct.category WHEN 'yard' THEN 'yard' WHEN 'class' THEN 'class' WHEN 'supplier' THEN 'supplier'
                                               WHEN 'management' THEN 'management' WHEN 'consultant' THEN 'consultant' ELSE 'other' END
  FROM contacts ct WHERE ct.company IS NOT NULL AND ct.company <> '';

UPDATE contacts ct JOIN companies co ON LOWER(co.name) = LOWER(ct.company) SET ct.company_id = co.id WHERE ct.company_id IS NULL;
UPDATE contacts ct JOIN companies co ON co.customer_id = ct.customer_id SET ct.company_id = co.id WHERE ct.company_id IS NULL AND ct.customer_id IS NOT NULL;
UPDATE contacts ct JOIN companies co ON co.id = ct.company_id SET ct.customer_id = co.customer_id WHERE ct.customer_id IS NULL AND co.customer_id IS NOT NULL;
UPDATE contacts SET channels = 'email' WHERE channels IS NULL AND email IS NOT NULL AND email <> '';
-- GAS-CP-R17-008 · Configurable offer statuses (Settings › Offer statuses).
CREATE TABLE IF NOT EXISTS offer_statuses (
  skey        VARCHAR(30) NOT NULL PRIMARY KEY,
  label       VARCHAR(80) NOT NULL,
  tone        VARCHAR(10) NOT NULL DEFAULT 'mute',
  behaves     ENUM('open','approved','closed_won','closed_lost') NOT NULL DEFAULT 'open',
  sort_order  INT NOT NULL DEFAULT 50,
  is_builtin  TINYINT(1) NOT NULL DEFAULT 0,
  is_active   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO offer_statuses (skey, label, tone, behaves, sort_order, is_builtin) VALUES
 ('sent','Sent','gold','open',10,1), ('rejected','Rejected','bad','closed_lost',20,1), ('cancelled','Cancelled','mute','closed_lost',30,1),
 ('approved','Approved','green','approved',40,1), ('in_progress','In progress','blue','approved',50,1), ('to_be_invoiced','To be invoiced','navy','approved',60,1), ('finished','Finished','mute','closed_won',70,1);
ALTER TABLE offers MODIFY status VARCHAR(30) NOT NULL DEFAULT 'sent';
-- GAS-CP-R18-001 · Per Golden A/S role: may this role see financial data (offer amounts, invoicing, timesheet invoicing)?
ALTER TABLE staff_roles ADD COLUMN see_financials TINYINT(1) NOT NULL DEFAULT 1;
-- GAS-CP-R18-002 notifications (see migrate_cp_r18_notifications.sql)
CREATE TABLE IF NOT EXISTS notifications (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  grp         VARCHAR(20) NOT NULL,
  kind        VARCHAR(30) NOT NULL,
  ref_key     VARCHAR(60) NOT NULL,
  title       VARCHAR(200) NOT NULL,
  body        VARCHAR(400) NULL,
  url         VARCHAR(255) NOT NULL,
  actor_id    INT UNSIGNED NULL,
  actor_name  VARCHAR(120) NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  read_at     TIMESTAMP NULL,
  KEY idx_nf_user_read (user_id, read_at, id),
  KEY idx_nf_user_grp (user_id, grp, read_at),
  KEY idx_nf_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS nf_cursor (
  source   VARCHAR(40) NOT NULL PRIMARY KEY,
  last_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_at   TIMESTAMP NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- start from "now": only changes after the step was run are notified
INSERT IGNORE INTO nf_cursor (source, last_id) VALUES
 ('ticket_events', (SELECT COALESCE(MAX(id),0) FROM ticket_events)), ('ticket_comments', (SELECT COALESCE(MAX(id),0) FROM ticket_comments)),
 ('jo_events', (SELECT COALESCE(MAX(id),0) FROM jo_events)), ('fsr_events', (SELECT COALESCE(MAX(id),0) FROM fsr_events)),
 ('ts_events', (SELECT COALESCE(MAX(id),0) FROM ts_events)), ('offer_events', (SELECT COALESCE(MAX(id),0) FROM offer_events)),
 ('oa_comments', (SELECT COALESCE(MAX(id),0) FROM oa_comments)), ('owner_approvals', (SELECT COALESCE(MAX(id),0) FROM owner_approvals)),
 ('class_submittals', (SELECT COALESCE(MAX(id),0) FROM class_submittals)), ('drawing_requests', (SELECT COALESCE(MAX(id),0) FROM drawing_requests)),
 ('sp_notes', (SELECT COALESCE(MAX(id),0) FROM sp_notes)), ('sp_comments', (SELECT COALESCE(MAX(id),0) FROM sp_comments)),
 ('documents', (SELECT COALESCE(MAX(id),0) FROM documents)), ('contact_notes', (SELECT COALESCE(MAX(id),0) FROM contact_notes));

-- GAS-CP-R19-001 customers master data + invoicing details (see migrate_cp_r19_customers_invoicing.sql)
ALTER TABLE customers
  ADD COLUMN address     VARCHAR(200) NULL AFTER country,
  ADD COLUMN city        VARCHAR(80)  NULL AFTER address,
  ADD COLUMN postal_code VARCHAR(20)  NULL AFTER city,
  ADD COLUMN vat_no      VARCHAR(40)  NULL AFTER postal_code,
  ADD COLUMN email       VARCHAR(190) NULL AFTER vat_no,
  ADD COLUMN phone       VARCHAR(60)  NULL AFTER email,
  ADD COLUMN website     VARCHAR(190) NULL AFTER phone,
  ADD COLUMN notes       TEXT         NULL AFTER website;

CREATE TABLE IF NOT EXISTS invoicing_details (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  owner_type    ENUM('customer','company','contact') NOT NULL,
  owner_id      INT UNSIGNED NOT NULL,
  label         VARCHAR(80)  NOT NULL,                 -- e.g. "Head office", "Management company", "Owning SPV"
  legal_name    VARCHAR(200) NOT NULL,
  vat_no        VARCHAR(40)  NULL,
  tax_office    VARCHAR(120) NULL,
  reg_no        VARCHAR(60)  NULL,                     -- company registration / GEMI / chamber number
  address       VARCHAR(200) NULL,
  city          VARCHAR(80)  NULL,
  postal_code   VARCHAR(20)  NULL,
  country       VARCHAR(80)  NULL,
  email         VARCHAR(190) NULL,                     -- where invoices are sent
  phone         VARCHAR(60)  NULL,
  attn          VARCHAR(120) NULL,                     -- for the attention of
  bank          VARCHAR(120) NULL,
  iban          VARCHAR(48)  NULL,
  swift         VARCHAR(16)  NULL,
  currency      CHAR(3)      NOT NULL DEFAULT 'EUR',
  payment_terms VARCHAR(120) NULL,
  notes         TEXT         NULL,
  is_default    TINYINT(1)   NOT NULL DEFAULT 0,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_iv_owner (owner_type, owner_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R20-001 file archive (see migrate_cp_r20_archive.sql)
ALTER TABLE sp_files
  ADD COLUMN doc_no   VARCHAR(80) NULL AFTER title,
  ADD COLUMN revision VARCHAR(16) NULL AFTER doc_no,
  ADD COLUMN seq      SMALLINT UNSIGNED NULL AFTER revision,
  ADD COLUMN sha256   CHAR(64) NULL AFTER size_bytes,
  ADD COLUMN archived_at DATETIME NULL AFTER sha256;
ALTER TABLE sp_files MODIFY COLUMN kind ENUM('photo','drawing','owner_doc','class_doc','fat','hat','sat','weekly','monthly','ga','other','fsr_photo','fsr_sign','fsr_doc','offer','issued','file','comment') NOT NULL DEFAULT 'other';
ALTER TABLE ticket_files
  ADD COLUMN seq    SMALLINT UNSIGNED NULL AFTER original_name,
  ADD COLUMN sha256 CHAR(64) NULL AFTER size_bytes,
  ADD COLUMN archived_at DATETIME NULL AFTER sha256;
CREATE TABLE IF NOT EXISTS doc_sequences (
  scope   VARCHAR(80) NOT NULL,          -- e.g. "sub:12" or "project:3"
  family  VARCHAR(12) NOT NULL,          -- DRW · FAT · HAT · SAT · FILE · GA · WN · DOC
  last_no INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scope, family)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS archive_moves (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  run_id    VARCHAR(20) NOT NULL,
  tbl       VARCHAR(20) NOT NULL,        -- sp_files | ticket_files
  file_id   INT UNSIGNED NOT NULL,
  old_path  VARCHAR(255) NOT NULL,
  new_path  VARCHAR(255) NOT NULL,
  moved_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  undone_at DATETIME NULL,
  KEY idx_am_run (run_id), KEY idx_am_file (tbl, file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R21-001 knowledge base (see migrate_cp_r21_kb.sql)
CREATE TABLE IF NOT EXISTS kb_questions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  author_id     INT UNSIGNED NULL,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NULL,
  category      VARCHAR(30) NOT NULL DEFAULT 'portal',         -- portal · systems · documents · service · other
  visibility    ENUM('all','golden') NOT NULL DEFAULT 'all',    -- golden = Golden A/S staff only
  accepted_id   INT UNSIGNED NULL,
  n_answers     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  views         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kbq_cat (category, visibility), KEY idx_kbq_upd (updated_at),
  FULLTEXT KEY ft_kbq (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_answers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id   INT UNSIGNED NOT NULL,
  author_id     INT UNSIGNED NULL,
  body          TEXT NOT NULL,
  helpful       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kba_q (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_votes (
  answer_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (answer_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id   INT UNSIGNED NOT NULL,
  answer_id     INT UNSIGNED NULL,                                   -- NULL = attached to the question itself
  stored_name   VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  sha256        CHAR(64) NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kbf_q (question_id), KEY idx_kbf_a (answer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

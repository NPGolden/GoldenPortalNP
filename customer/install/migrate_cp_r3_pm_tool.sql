-- =====================================================================
--  GAS Customer Portal  R2 -> R3 : Project Management tool
--  Doc ref GAS-CP-R3-001.  Run once via phpMyAdmin (goldensas_portal).
--
--  Covers:  project tree (main project = code before "Y."), 7-stage
--  process line, vessel-systems master list, drawing tracker, owner
--  approvals, class submittals, internal/external comments + chat,
--  weekly notes & photos, monthly snapshots, FAT/HAT/SAT reports,
--  GA drawing status overlays (trays / penetrations / cables / panels).
-- =====================================================================

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

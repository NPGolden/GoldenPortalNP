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

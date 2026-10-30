USE goldensas_portal;
-- GAS-CP-R23-003 · Inspection reports (SF-GLD-020): one report per vessel / project with one block per inspected system.
-- Select the portal database first (goldensas_portal). Re-running reports "already exists" — harmless.
CREATE TABLE IF NOT EXISTS isr_reports (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  subproject_id   INT UNSIGNED NULL,
  report_no       VARCHAR(32)  NOT NULL UNIQUE,                 -- ISR-yyyymmdd-<initials>-<nn>
  form_code       VARCHAR(20)  NOT NULL DEFAULT 'SF-GLD-020',
  report_date     DATE         NOT NULL,
  subject         VARCHAR(200) NULL,
  customer_name   VARCHAR(160) NULL,
  vessel_name     VARCHAR(120) NULL,
  location        VARCHAR(160) NULL,
  pic_name        VARCHAR(120) NULL,
  class_flag      VARCHAR(120) NULL,
  builder_year    VARCHAR(120) NULL,
  hull_no         VARCHAR(60)  NULL,
  objective       TEXT NULL,                                     -- document subject and objective
  findings        TEXT NULL,                                     -- main findings and recommendations
  risks           TEXT NULL,                                     -- risks and hazards related to the current condition
  status          ENUM('draft','checking','issued') NOT NULL DEFAULT 'draft',
  version         SMALLINT UNSIGNED NOT NULL DEFAULT 0,          -- revision index of the issued document (R0 = first issue)
  created_by      INT UNSIGNED NULL,
  performed_name  VARCHAR(120) NULL,
  submitted_by    INT UNSIGNED NULL,
  submitted_at    DATETIME NULL,
  checked_by      INT UNSIGNED NULL,
  checked_name    VARCHAR(120) NULL,
  checked_at      DATETIME NULL,
  issued_at       DATETIME NULL,
  docx_file_id    INT UNSIGNED NULL,
  pdf_file_id     INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_isr_project (project_id), KEY idx_isr_sub (subproject_id), KEY idx_isr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isr_items (                            -- one inspected system
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id       INT UNSIGNED NOT NULL,
  seq             SMALLINT UNSIGNED NOT NULL,
  system_id       INT UNSIGNED NULL,                             -- vessel_systems.id when picked from the list
  system_name     VARCHAR(160) NOT NULL,                         -- the name printed (list name or the "Other" description)
  condition_found TEXT NULL,
  checklist       JSON NULL,                                     -- [{"q":"…","a":"…"}, …]
  class_regs      TEXT NULL,                                     -- class regulations, if applicable
  risk_level      ENUM('none','low','medium','high','critical') NOT NULL DEFAULT 'none',
  risk_analysis   TEXT NULL,
  actions         TEXT NULL,                                     -- proposed corrective actions
  KEY idx_isri_report (report_id, seq),
  CONSTRAINT fk_isri_report FOREIGN KEY (report_id) REFERENCES isr_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS isr_events (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id  INT UNSIGNED NOT NULL,
  event      VARCHAR(20) NOT NULL,                               -- created · saved · submitted · checked · issued · reopened
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  note       VARCHAR(1000) NULL,
  at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_isre_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE sp_files ADD COLUMN isr_id INT UNSIGNED NULL AFTER fsr_task, ADD COLUMN isr_item SMALLINT UNSIGNED NULL AFTER isr_id, ADD INDEX idx_spf_isr (isr_id);
INSERT IGNORE INTO file_kinds (skey, label, doc_code, archive_tab, sort_order, is_builtin, is_system) VALUES
 ('isr_doc', 'Inspection report document', 'ISR', 'inspections', 270, 1, 1), ('isr_file', 'Inspection report attachment', 'ISR', 'inspections', 280, 1, 1);

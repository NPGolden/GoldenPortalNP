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

-- photos belong to a task of the report (1..4) and carry a short caption (sp_files.title)
ALTER TABLE sp_files ADD COLUMN fsr_task TINYINT UNSIGNED NULL AFTER fsr_id;

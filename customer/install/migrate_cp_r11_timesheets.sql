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

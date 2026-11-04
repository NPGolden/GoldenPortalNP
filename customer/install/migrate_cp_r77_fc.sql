-- GAS-CP-R77-001 — function checks / commissioning forms filled on line (template-driven; first template Marex OS III)
CREATE TABLE IF NOT EXISTS fc_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  form VARCHAR(32) NOT NULL,
  report_no VARCHAR(40) NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  report_date DATE NOT NULL,
  status ENUM('draft','submitted','accepted','disputed') NOT NULL DEFAULT 'draft',
  version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  data MEDIUMTEXT NULL,
  remarks TEXT NULL,
  created_by INT UNSIGNED NULL,
  engineer_name VARCHAR(120) NULL,
  submitted_by INT UNSIGNED NULL, submitted_name VARCHAR(120) NULL, submitted_at DATETIME NULL,
  golden_sign_id INT UNSIGNED NULL,
  customer_name VARCHAR(120) NULL,
  accepted_by INT UNSIGNED NULL, accepted_name VARCHAR(160) NULL, accepted_at DATETIME NULL, accepted_ip VARCHAR(64) NULL,
  customer_sign_id INT UNSIGNED NULL,
  customer_remark TEXT NULL,
  pdf_file_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_fc_no (report_no), KEY ix_fc_project (project_id), KEY ix_fc_sub (subproject_id), KEY ix_fc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fc_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id INT UNSIGNED NOT NULL,
  event VARCHAR(32) NOT NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL, ip VARCHAR(64) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fce_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

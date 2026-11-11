-- GAS-CP-R96-001 — executive summary block of the weekly and monthly project reports:
-- project milestones (plan / forecast / achieved), the project safety log (LTI tile) and the summary text per report period.
CREATE TABLE IF NOT EXISTS pm_milestones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  plan_date DATE NOT NULL,
  forecast_date DATE NULL,
  achieved_date DATE NULL,
  source ENUM('manual','deadline') NOT NULL DEFAULT 'manual',
  sort_order INT NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_ms_project (project_id, plan_date),
  UNIQUE KEY uq_ms_deadline (project_id, subproject_id, source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pm_safety (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  event_date DATE NOT NULL,
  kind ENUM('lti','medical','first_aid','near_miss','observation') NOT NULL DEFAULT 'near_miss',
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  action_taken TEXT NULL,
  lost_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sf_project (project_id, event_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS pm_exec_summary (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  period VARCHAR(10) NOT NULL,
  body TEXT NULL,
  status ENUM('draft','final') NOT NULL DEFAULT 'draft',
  ai TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_name VARCHAR(120) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_xs (project_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

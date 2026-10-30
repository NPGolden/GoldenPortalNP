-- GAS-CP-R35-001 — Work packages per sub-project assigned to engineers; automatic planning; workload and next free window.
CREATE TABLE IF NOT EXISTS sp_works (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  wp_no VARCHAR(8) NOT NULL,                        -- W01, W02 … per sub-project
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  discipline_id INT UNSIGNED NULL,
  area_id INT UNSIGNED NULL,                        -- vessel_areas (working area on a deck)
  planned_hours DECIMAL(9,2) NOT NULL DEFAULT 0,
  start_date DATE NULL,
  end_date DATE NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'planned',    -- planned | assigned | in_progress | done | checked | cancelled
  priority TINYINT NOT NULL DEFAULT 2,              -- 1 high, 2 normal, 3 low
  lead_user_id INT UNSIGNED NULL,
  auto_plan TINYINT(1) NOT NULL DEFAULT 1,
  done_at DATETIME NULL,
  checked_by INT UNSIGNED NULL,
  checked_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_wp_sub (subproject_id, status), KEY ix_wp_project (project_id), KEY ix_wp_dates (start_date, end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sp_work_people (
  work_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  hours_planned DECIMAL(9,2) NULL,
  acknowledged_at DATETIME NULL,
  PRIMARY KEY (work_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE plan_entries ADD COLUMN work_id INT UNSIGNED NULL AFTER subproject_id, ADD COLUMN hours DECIMAL(5,2) NULL AFTER car_id, ADD KEY ix_pe_work (work_id);
ALTER TABLE timesheet_lines ADD COLUMN work_id INT UNSIGNED NULL AFTER subproject_id, ADD KEY ix_tl_work (work_id);

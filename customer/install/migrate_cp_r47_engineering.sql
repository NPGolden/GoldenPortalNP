-- GAS-CP-R47-001 · Engineering sections per sub-project: budgeted hours, deliverables (earned value), extra-hours approval, activity on timesheet lines
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE disciplines ADD COLUMN is_engineering TINYINT(1) NOT NULL DEFAULT 0;
UPDATE disciplines SET is_engineering = 1 WHERE name IN ('Electrical', 'Automation', 'Mechanical', 'UI/UX', 'IT');
INSERT INTO disciplines (name, sort_order, is_active, is_engineering) SELECT 'Mechanical', 80, 1, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM disciplines WHERE name = 'Mechanical');
INSERT INTO disciplines (name, sort_order, is_active, is_engineering) SELECT 'UI/UX', 90, 1, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM disciplines WHERE name = 'UI/UX');
INSERT INTO disciplines (name, sort_order, is_active, is_engineering) SELECT 'IT', 100, 1, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM disciplines WHERE name = 'IT');
ALTER TABLE timesheet_lines ADD COLUMN activity VARCHAR(16) NULL, ADD KEY ix_tsl_activity (activity);
ALTER TABLE customer_users ADD COLUMN can_management TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS eng_budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  discipline_id INT UNSIGNED NOT NULL,
  hours DECIMAL(9,2) NOT NULL DEFAULT 0,
  lead_user_id INT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'open',
  done_at DATETIME NULL,
  done_by INT UNSIGNED NULL,
  done_note VARCHAR(500) NULL,
  exceeded_notified_at DATETIME NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_eb (subproject_id, discipline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS eng_deliverables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  discipline_id INT UNSIGNED NOT NULL,
  title VARCHAR(160) NOT NULL,
  weight_h DECIMAL(9,2) NOT NULL DEFAULT 0,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  done_at DATETIME NULL,
  done_by INT UNSIGNED NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ed_sub (subproject_id, discipline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS eng_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  discipline_id INT UNSIGNED NOT NULL,
  hours DECIMAL(9,2) NOT NULL,
  reason_kind VARCHAR(20) NOT NULL DEFAULT 'misjudgement',
  reason VARCHAR(1000) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending',
  requested_by INT UNSIGNED NULL,
  requested_name VARCHAR(120) NULL,
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT UNSIGNED NULL,
  decided_name VARCHAR(120) NULL,
  decided_at DATETIME NULL,
  decision_note VARCHAR(500) NULL,
  KEY ix_er_sub (subproject_id, discipline_id), KEY ix_er_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- R97-001 (v1.123.0): System Budgets — release 1 (core) + import of the GLD-BUD budget working file.
-- New tables only; nothing is added to the budget, offer, ticket, inventory or BOM tables.
CREATE TABLE IF NOT EXISTS sb_budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  sb_no VARCHAR(20) NOT NULL,
  revision SMALLINT NOT NULL DEFAULT 0,
  title VARCHAR(200) NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  project_id INT UNSIGNED NULL,
  subproject_id INT UNSIGNED NULL,
  ticket_id INT UNSIGNED NULL,
  owner_user_id INT UNSIGNED NULL,
  target_date DATE NULL,
  description MEDIUMTEXT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  zone VARCHAR(10) NOT NULL DEFAULT 'local',
  parts_margin DECIMAL(6,2) NOT NULL DEFAULT 40.00,
  flat_on TINYINT(1) NOT NULL DEFAULT 0,
  flat_rate DECIMAL(8,2) NOT NULL DEFAULT 52.00,
  cost_rate DECIMAL(8,2) NOT NULL DEFAULT 35.00,
  allow_rate DECIMAL(8,2) NOT NULL DEFAULT 75.00,
  allow_cost DECIMAL(8,2) NOT NULL DEFAULT 75.00,
  commission_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  source_ref VARCHAR(80) NULL,
  source_file VARCHAR(200) NULL,
  tot_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
  tot_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  tot_sale DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  finished_by INT UNSIGNED NULL,
  UNIQUE KEY uq_sb_no (sb_no),
  KEY ix_sb_status (status),
  KEY ix_sb_sub (subproject_id),
  KEY ix_sb_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sb_labour (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  code VARCHAR(12) NULL,
  descr VARCHAR(400) NOT NULL DEFAULT '',
  section_id INT UNSIGNED NULL,
  discipline_id INT UNSIGNED NULL,
  card VARCHAR(12) NULL,
  bucket VARCHAR(20) NULL,
  h_normal DECIMAL(9,2) NOT NULL DEFAULT 0,
  h_travel DECIMAL(9,2) NOT NULL DEFAULT 0,
  h_overtime DECIMAL(9,2) NOT NULL DEFAULT 0,
  allow_days DECIMAL(7,2) NOT NULL DEFAULT 0,
  rate DECIMAL(8,2) NOT NULL DEFAULT 0,
  rate_ot DECIMAL(8,2) NULL,
  remarks VARCHAR(500) NULL,
  KEY ix_sbl_budget (budget_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sb_parts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  code VARCHAR(20) NULL,
  item_id INT UNSIGNED NULL,
  descr VARCHAR(400) NOT NULL DEFAULT '',
  qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  unit VARCHAR(12) NULL,
  unit_cost DECIMAL(12,4) NOT NULL DEFAULT 0,
  margin_pct DECIMAL(6,2) NULL,
  cost_origin VARCHAR(120) NULL,
  remarks VARCHAR(500) NULL,
  KEY ix_sbp_budget (budget_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sb_expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  kind VARCHAR(12) NOT NULL DEFAULT 'travel',
  descr VARCHAR(300) NOT NULL DEFAULT '',
  cost DECIMAL(12,2) NOT NULL DEFAULT 0,
  sale DECIMAL(12,2) NOT NULL DEFAULT 0,
  in_total TINYINT(1) NOT NULL DEFAULT 1,
  KEY ix_sbe_budget (budget_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sb_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sbc_budget (budget_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS sb_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  kind VARCHAR(20) NOT NULL,
  note VARCHAR(300) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sbv_budget (budget_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

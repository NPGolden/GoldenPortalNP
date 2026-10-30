-- GAS-CP-R34-001 — Budget vs actual per sub-project: budgeted manhours per discipline and parts budget (revisions),
-- parts cost entries (actual), live result (actual approved timesheet hours × discipline rates + parts vs offer value).
CREATE TABLE IF NOT EXISTS sp_budgets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  revision SMALLINT NOT NULL DEFAULT 0,          -- R0, R1 … per sub-project
  parts_budget DECIMAL(14,2) NOT NULL DEFAULT 0,
  offer_value DECIMAL(14,2) NULL,                -- NULL = sum of the approved offers of the sub-project
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bg_sub (subproject_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sp_budget_hours (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  discipline_id INT UNSIGNED NOT NULL,
  hours DECIMAL(9,2) NOT NULL DEFAULT 0,
  rate DECIMAL(8,2) NOT NULL DEFAULT 0,          -- normal-hour rate of the discipline at the time of the revision
  KEY ix_bgh (budget_id, discipline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sp_costs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  cost_date DATE NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'order',     -- order (committed) | invoice (invoiced to us) | other
  supplier VARCHAR(120) NULL,
  description VARCHAR(255) NOT NULL,
  ref_no VARCHAR(60) NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  file_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_cost_sub (subproject_id, cost_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

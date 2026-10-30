-- GAS-CP-R39-001 — Project-management KPIs (PCR, PCRt, BAI-b / BAI-o, RUIs / RUIa, PSC, RMI, ROI, CPI, SPI), risk log, KPI targets and
-- monthly snapshots; sales KPIs data (requested date, loss reason, probability per offer status, sales targets).

-- when a sub-project was set Completed (PCR); back-filled from the last update for the ones already completed
ALTER TABLE subprojects ADD COLUMN completed_at DATE NULL;
UPDATE subprojects SET completed_at = DATE(updated_at) WHERE status = 'completed' AND completed_at IS NULL;
-- the rate card a person's hours are sold under (A service engineer, B assistant …); empty = the discipline's card
ALTER TABLE customer_users ADD COLUMN rate_card VARCHAR(12) NULL;

-- risk log per project / sub-project (identified at the initial vessel inspection or by hand)
CREATE TABLE IF NOT EXISTS sp_risks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  risk_no VARCHAR(16) NOT NULL,                      -- RK-001 per project
  title VARCHAR(200) NOT NULL,
  description TEXT NULL,
  category VARCHAR(40) NULL,                         -- technical, schedule, cost, safety, class, supply, access, other
  likelihood TINYINT UNSIGNED NOT NULL DEFAULT 3,    -- 1 … 5
  impact TINYINT UNSIGNED NOT NULL DEFAULT 3,        -- 1 … 5
  status ENUM('open','mitigated','accepted','occurred','closed') NOT NULL DEFAULT 'open',
  owner_user_id INT UNSIGNED NULL,
  due_date DATE NULL,
  mitigation TEXT NULL,
  source ENUM('manual','inspection') NOT NULL DEFAULT 'manual',
  isr_item_id INT UNSIGNED NULL,
  identified_at DATE NOT NULL,
  mitigated_at DATE NULL,
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_risk_no (project_id, risk_no), KEY ix_risk_sub (subproject_id), KEY ix_risk_status (status), UNIQUE KEY ux_risk_isr (isr_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- KPI targets and tolerance (Settings › KPIs): value ≥ target green, ≥ target − tolerance amber, else red (lower-is-better KPIs inverted)
CREATE TABLE IF NOT EXISTS kpi_targets (
  kpi VARCHAR(12) NOT NULL PRIMARY KEY,
  target DECIMAL(10,4) NOT NULL DEFAULT 1,
  tolerance DECIMAL(10,4) NOT NULL DEFAULT 0.1,
  notify TINYINT(1) NOT NULL DEFAULT 1,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO kpi_targets (kpi, target, tolerance) VALUES
  ('pcr', 1, 0.1), ('pcrt', 1, 0.1), ('bai_b', 1, 0.1), ('bai_o', 1, 0.1), ('rui_s', 1, 0.1), ('rui_a', 1, 0.1), ('psc', 0, 1), ('rmi', 1, 0.1), ('roi', 0, 10), ('cpi', 1, 0.1), ('spi', 1, 0.1);

-- monthly snapshots (trend, notifications)
CREATE TABLE IF NOT EXISTS kpi_snapshots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  snap_date DATE NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  kpi VARCHAR(12) NOT NULL,
  value DECIMAL(12,4) NULL,
  tone VARCHAR(8) NULL,
  UNIQUE KEY ux_kpi_snap (snap_date, project_id, subproject_id, kpi), KEY ix_kpi_proj (project_id, kpi)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sales KPIs
ALTER TABLE offers ADD COLUMN requested_at DATE NULL, ADD COLUMN lost_reason VARCHAR(40) NULL;
CREATE TABLE IF NOT EXISTS offer_status_prob (                     -- win probability per offer status (forecast)
  skey VARCHAR(30) NOT NULL PRIMARY KEY, probability TINYINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO offer_status_prob (skey, probability) VALUES ('draft', 10), ('sent', 30), ('approved', 100), ('in_progress', 100), ('to_be_invoiced', 100), ('finished', 100), ('rejected', 0), ('cancelled', 0);
CREATE TABLE IF NOT EXISTS sales_targets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT UNSIGNED NOT NULL, month TINYINT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,                          -- a sales person, or NULL = the branch / company
  branch_id INT UNSIGNED NULL,
  offers_sent INT UNSIGNED NOT NULL DEFAULT 0,
  value_approved DECIMAL(12,2) NOT NULL DEFAULT 0,
  UNIQUE KEY ux_sales_target (year, month, user_id, branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

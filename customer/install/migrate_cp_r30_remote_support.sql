-- GAS-CP-R30-004 — Remote support contracts per vessel, with ticket and task integration
CREATE TABLE IF NOT EXISTS rs_contracts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_no VARCHAR(40) NOT NULL,
  vessel_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  status ENUM('draft','active','suspended','expired','cancelled') NOT NULL DEFAULT 'active',
  start_date DATE NOT NULL,
  end_date DATE NULL,
  hours_included DECIMAL(7,2) NULL,
  response_hours SMALLINT UNSIGNED NULL,
  coverage ENUM('office','extended','always') NOT NULL DEFAULT 'office',
  access_method VARCHAR(160) NULL,
  scope TEXT NULL,
  fee DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  billing ENUM('monthly','quarterly','yearly','one_off') NOT NULL DEFAULT 'yearly',
  renewal ENUM('manual','auto') NOT NULL DEFAULT 'manual',
  renew_notice_days SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  visibility ENUM('external','internal') NOT NULL DEFAULT 'external',
  notes TEXT NULL,
  renewal_task_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_rs_no (contract_no),
  KEY ix_rs_vessel (vessel_id, status),
  KEY ix_rs_end (end_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rs_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contract_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL,
  session_date DATE NOT NULL,
  hours DECIMAL(5,2) NOT NULL DEFAULT 0,
  engineer_id INT UNSIGNED NULL,
  engineer_name VARCHAR(120) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rss_contract (contract_id, session_date),
  KEY ix_rss_ticket (ticket_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE tickets ADD COLUMN rs_contract_id INT UNSIGNED NULL AFTER subproject_id, ADD INDEX ix_tk_rs (rs_contract_id);
ALTER TABLE tasks ADD COLUMN rs_contract_id INT UNSIGNED NULL AFTER offer_id, ADD INDEX ix_td_rs (rs_contract_id);
INSERT IGNORE INTO task_kinds (skey, label, tone, sort_order, is_builtin, is_active) VALUES ('remote', 'Remote support', 'navy', 95, 1, 1);

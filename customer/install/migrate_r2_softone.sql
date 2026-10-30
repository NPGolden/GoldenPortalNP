-- =====================================================================
--  Customer Portal R1 -> R2: SoftOne ERP integration.
--  Run once against an existing installation; fresh installs get all of
--  this from schema.sql.
-- =====================================================================
ALTER TABLE customers
  ADD COLUMN erp_code VARCHAR(32) NULL UNIQUE AFTER name;

ALTER TABLE projects
  ADD COLUMN erp_code VARCHAR(40) NULL UNIQUE AFTER name,
  ADD COLUMN source ENUM('manual','erp') NOT NULL DEFAULT 'manual' AFTER erp_code,
  ADD COLUMN category VARCHAR(80) NULL AFTER status,
  ADD COLUMN project_manager VARCHAR(120) NULL AFTER category;

CREATE TABLE erp_sync_log (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ok         TINYINT(1) NOT NULL,
  fetched    INT UNSIGNED NOT NULL DEFAULT 0,
  matched    INT UNSIGNED NOT NULL DEFAULT 0,
  skipped    INT UNSIGNED NOT NULL DEFAULT 0,
  message    VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Then map each portal customer to its SoftOne customer code, e.g.:
-- UPDATE customers SET erp_code = 'C-00042' WHERE name = 'Fleet Co';

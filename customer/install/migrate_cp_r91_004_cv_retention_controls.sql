-- GAS-CP-R91-004 — CV retention controls: per-application keep (with reason and expiry), separate talent-pool retention, CV handed to the employee file on hire
SET NAMES utf8mb4;
ALTER TABLE rc_applications
  ADD COLUMN IF NOT EXISTS keep_until DATE NULL,
  ADD COLUMN IF NOT EXISTS keep_reason VARCHAR(200) NULL,
  ADD COLUMN IF NOT EXISTS keep_by INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS pool_consent_at DATETIME NULL;
INSERT IGNORE INTO cp_settings (k, v) VALUES ('rc_pool_retention_months', '24');

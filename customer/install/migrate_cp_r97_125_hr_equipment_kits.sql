-- GAS-CP-R97-125 (v1.189.0) — HR equipment: standard kits per discipline, replacement dates; (QHSE: evaluation of a new supplier needs no schema)
CREATE TABLE IF NOT EXISTS hr_eq_kits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  discipline_id INT UNSIGNED NOT NULL DEFAULT 0,
  category VARCHAR(16) NOT NULL DEFAULT 'other',
  description VARCHAR(200) NOT NULL,
  qty DECIMAL(8,2) NOT NULL DEFAULT 1,
  replace_months SMALLINT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  KEY ix_hr_eq_kit_disc (discipline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE hr_eq_items ADD COLUMN IF NOT EXISTS expires_on DATE NULL AFTER issued_on;
ALTER TABLE hr_eq_items ADD COLUMN IF NOT EXISTS exp_notified DATE NULL AFTER expires_on;

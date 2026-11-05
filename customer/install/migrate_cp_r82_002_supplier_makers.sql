-- GAS-CP-R82-002 — Supplier ↔ makers (many-to-many) and ERP supplier import
CREATE TABLE IF NOT EXISTS supplier_makers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  maker VARCHAR(80) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(160) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_sm (supplier_id, maker), KEY ix_sm_maker (maker)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE supplier_details ADD COLUMN erp_code VARCHAR(40) NULL AFTER company_id;
ALTER TABLE supplier_details ADD KEY ix_sd_erp (erp_code);

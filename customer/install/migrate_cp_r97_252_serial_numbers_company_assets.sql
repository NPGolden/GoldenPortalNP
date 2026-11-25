-- GAS-CP-R97-252 — inventory: company assets and serial numbers followed through receipts, issues, delivery notes and the equipment of the personnel (key r99v)
SET NAMES utf8mb4;
ALTER TABLE inv_items ADD COLUMN is_asset TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN track_serial TINYINT(1) NOT NULL DEFAULT 0;
CREATE TABLE IF NOT EXISTS inv_serials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  serial_no VARCHAR(80) NOT NULL,
  asset_tag VARCHAR(40) NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'in_stock',      -- in_stock · out · with_person · scrapped · removed
  warehouse_id INT UNSIGNED NULL,
  person_id INT UNSIGNED NULL,
  eq_item_id INT UNSIGNED NULL,
  last_ref VARCHAR(160) NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_sn (item_id, serial_no), KEY ix_sn_status (item_id, status), KEY ix_sn_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_serial_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial_id INT UNSIGNED NOT NULL,
  event VARCHAR(12) NOT NULL,                           -- in · out · xfer · register · assign · return · scrap · undo
  move_id INT UNSIGNED NULL,
  dn_id INT UNSIGNED NULL,
  warehouse_id INT UNSIGNED NULL,
  person_id INT UNSIGNED NULL,
  ref VARCHAR(160) NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_snl_serial (serial_id), KEY ix_snl_move (move_id), KEY ix_snl_dn (dn_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE hr_eq_items ADD COLUMN inv_serial_id INT UNSIGNED NULL, ADD KEY ix_hre_sn (inv_serial_id);

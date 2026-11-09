-- GAS-CP-R93-006 — BOM import goes through a review step: rows are staged, matched against the inventory (code, maker part no, AI proposal) and the engineer accepts / rejects / creates items before the lines are written.
CREATE TABLE IF NOT EXISTS bom_import_rows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bom_rev_id INT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL,
  section VARCHAR(80) NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  description VARCHAR(255) NOT NULL,
  maker VARCHAR(80) NULL,
  part_no VARCHAR(80) NULL,
  code VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  item_id INT UNSIGNED NULL,                                   -- proposed inventory item
  match_kind ENUM('code','part_no','part_no_norm','ai','none') NOT NULL DEFAULT 'none',
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  decision ENUM('pending','accept','reject','new') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bir_rev (bom_rev_id, seq), KEY ix_bir_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- R97-003 (v1.125.0): System Budgets — release 3: staged BOM import and inventory proposals for free part lines (review before anything is written).
CREATE TABLE IF NOT EXISTS sb_import_rows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  part_id INT UNSIGNED NULL,
  seq SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  section VARCHAR(80) NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit VARCHAR(12) NULL,
  description VARCHAR(400) NOT NULL DEFAULT '',
  maker VARCHAR(80) NULL,
  part_no VARCHAR(80) NULL,
  code VARCHAR(60) NULL,
  note VARCHAR(255) NULL,
  unit_cost DECIMAL(12,4) NULL,
  item_id INT UNSIGNED NULL,
  match_kind VARCHAR(14) NOT NULL DEFAULT 'none',
  confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  decision VARCHAR(8) NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sbi_budget (budget_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

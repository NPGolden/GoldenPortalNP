-- GAS-CP-R80-002 — Engineering BOM revisions per sub-project with the change-proposal cycle between Engineering and Procurement
CREATE TABLE IF NOT EXISTS bom_revisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  subproject_id INT UNSIGNED NOT NULL,
  revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  title VARCHAR(160) NULL,
  status ENUM('draft','issued','approved','superseded','rejected','withdrawn') NOT NULL DEFAULT 'draft',
  file_id INT UNSIGNED NULL,
  est_total DECIMAL(14,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  issued_by INT UNSIGNED NULL, issued_name VARCHAR(120) NULL, issued_at DATETIME NULL,
  approved_by INT UNSIGNED NULL, approved_name VARCHAR(120) NULL, approved_at DATETIME NULL, approval_note VARCHAR(400) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_bom_rev (subproject_id, revision), KEY ix_bom_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bom_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bom_rev_id INT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  item_id INT UNSIGNED NULL,
  code VARCHAR(60) NULL,
  description VARCHAR(255) NOT NULL,
  maker VARCHAR(80) NULL, part_no VARCHAR(80) NULL,
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  qty DECIMAL(12,3) NOT NULL,
  est_price DECIMAL(12,4) NULL, price_origin VARCHAR(160) NULL,
  section VARCHAR(80) NULL,
  note VARCHAR(255) NULL,
  status ENUM('ok','changed','removed') NOT NULL DEFAULT 'ok',
  KEY ix_boml_rev (bom_rev_id, seq), KEY ix_boml_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bom_changes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bom_rev_id INT UNSIGNED NOT NULL,
  line_id INT UNSIGNED NULL,
  kind ENUM('alternative','qty','remove','add') NOT NULL,
  alt_item_id INT UNSIGNED NULL,
  alt_description VARCHAR(255) NULL, alt_maker VARCHAR(80) NULL, alt_part_no VARCHAR(80) NULL,
  qty DECIMAL(12,3) NULL,
  reason VARCHAR(600) NOT NULL,
  status ENUM('proposed','accepted','rejected','withdrawn') NOT NULL DEFAULT 'proposed',
  proposed_by INT UNSIGNED NULL, proposed_name VARCHAR(120) NULL,
  decided_by INT UNSIGNED NULL, decided_name VARCHAR(120) NULL, decided_at DATETIME NULL, decision_note VARCHAR(400) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bomc_rev (bom_rev_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS bom_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bom_rev_id INT UNSIGNED NOT NULL,
  event VARCHAR(24) NOT NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL, ip VARCHAR(64) NULL,
  note VARCHAR(600) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_bome_rev (bom_rev_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

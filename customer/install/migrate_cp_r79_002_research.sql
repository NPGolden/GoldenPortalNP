-- GAS-CP-R79-002 — Procurement assistant: saved market research per item / free query; alternatives chosen from it
CREATE TABLE IF NOT EXISTS inv_research (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NULL,
  mode ENUM('alternatives','prices') NOT NULL DEFAULT 'alternatives',
  query VARCHAR(400) NOT NULL,
  constraints VARCHAR(600) NULL,
  result MEDIUMTEXT NULL,
  summary VARCHAR(1500) NULL,
  searches SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  cost_eur DECIMAL(10,4) NOT NULL DEFAULT 0,
  ms INT UNSIGNED NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_invrs_item (item_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_alternatives (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  alt_item_id INT UNSIGNED NULL,
  maker VARCHAR(80) NULL,
  part_no VARCHAR(80) NULL,
  description VARCHAR(255) NULL,
  match_kind ENUM('exact','equivalent','functional') NOT NULL DEFAULT 'equivalent',
  note VARCHAR(400) NULL,
  source_url VARCHAR(500) NULL,
  price DECIMAL(12,4) NULL, currency CHAR(3) NULL, seller VARCHAR(120) NULL,
  research_id INT UNSIGNED NULL,
  status ENUM('proposed','approved','rejected') NOT NULL DEFAULT 'proposed',
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_invalt_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

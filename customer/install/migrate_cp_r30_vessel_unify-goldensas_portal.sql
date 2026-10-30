-- GAS-CP-R30-001 — one record per hull: aliases, related companies, former names, merge pointer
ALTER TABLE `goldensas_portal`.vessels
  ADD COLUMN former_names VARCHAR(255) NULL AFTER yard,
  ADD COLUMN merged_into INT UNSIGNED NULL AFTER is_active,
  ADD INDEX ix_vessels_merged (merged_into);

CREATE TABLE IF NOT EXISTS `goldensas_portal`.vessel_aliases (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id INT UNSIGNED NOT NULL,
  alias VARCHAR(120) NOT NULL,
  alias_norm VARCHAR(120) NOT NULL,
  source VARCHAR(40) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_alias_norm (alias_norm),
  KEY ix_alias_vessel (vessel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.vessel_companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'other',
  note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_vessel_company (vessel_id, customer_id),
  KEY ix_vc_customer (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R31-001 — Decks and working areas per vessel · Weekly works forecast per project (Plan A / Plan B, owner replies)
CREATE TABLE IF NOT EXISTS `goldensas_portal`.vessel_decks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id INT UNSIGNED NOT NULL,
  name VARCHAR(80) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  stored_name VARCHAR(255) NULL,
  original_name VARCHAR(255) NULL,
  mime_type VARCHAR(80) NULL,
  img_w SMALLINT UNSIGNED NULL,
  img_h SMALLINT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_deck_vessel (vessel_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.vessel_areas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vessel_id INT UNSIGNED NOT NULL,
  deck_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  class ENUM('interior','exterior','machinery','technical','guest','crew') NOT NULL DEFAULT 'interior',
  rx DECIMAL(6,3) NULL, ry DECIMAL(6,3) NULL, rw DECIMAL(6,3) NULL, rh DECIMAL(6,3) NULL,
  stored_name VARCHAR(255) NULL,
  notes VARCHAR(500) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_area_vessel (vessel_id, deck_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.wf_forecasts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id INT UNSIGNED NOT NULL,
  week CHAR(8) NOT NULL,
  week_start DATE NOT NULL,
  status ENUM('draft','issued') NOT NULL DEFAULT 'draft',
  notes TEXT NULL,
  owner_summary TEXT NULL,
  created_by INT UNSIGNED NULL,
  issued_at DATETIME NULL,
  issued_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_wf_week (project_id, week)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.wf_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  forecast_id INT UNSIGNED NOT NULL,
  area_id INT UNSIGNED NOT NULL,
  plan ENUM('A','B') NOT NULL DEFAULT 'A',
  description VARCHAR(500) NULL,
  days VARCHAR(20) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY ix_wfi_forecast (forecast_id, plan, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.wf_item_subs (
  item_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (item_id, subproject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `goldensas_portal`.wf_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  forecast_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  side ENUM('golden','customer') NOT NULL DEFAULT 'customer',
  kind ENUM('comment','delay','obstacle','painting','other') NOT NULL DEFAULT 'comment',
  area_id INT UNSIGNED NULL,
  from_date DATE NULL,
  to_date DATE NULL,
  body TEXT NOT NULL,
  in_report TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_wfc_forecast (forecast_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

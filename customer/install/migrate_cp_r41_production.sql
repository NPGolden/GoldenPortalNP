-- GAS-CP-R41-001 · Production orders and production cards (equipment manufacturing follow-up)
-- Run once in phpMyAdmin with the portal database selected.
CREATE TABLE IF NOT EXISTS production_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  po_no VARCHAR(30) NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  work_id INT UNSIGNED NULL,
  vessel_id INT UNSIGNED NULL,
  system_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  description TEXT NULL,
  manager_user_id INT UNSIGNED NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'design',
  pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  planned_start DATE NULL,
  planned_end DATE NULL,
  delivered_at DATE NULL,
  closed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_po_no (po_no),
  KEY ix_po_project (project_id), KEY ix_po_sub (subproject_id), KEY ix_po_work (work_id), KEY ix_po_vessel (vessel_id), KEY ix_po_status (status), KEY ix_po_manager (manager_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_units (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  equipment_id INT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending',
  pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(500) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pu (order_id, equipment_id), KEY ix_pu_eq (equipment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NULL,
  file_id INT UNSIGNED NOT NULL,
  phase VARCHAR(16) NOT NULL DEFAULT 'other',
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pf_order (order_id), KEY ix_pf_file (file_id), KEY ix_pf_unit (unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NULL,
  event VARCHAR(24) NOT NULL,
  note VARCHAR(500) NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pl_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Production managers: may update every production card (status / % per serial number, files, phases).
ALTER TABLE customer_users ADD COLUMN is_production TINYINT(1) NOT NULL DEFAULT 0;

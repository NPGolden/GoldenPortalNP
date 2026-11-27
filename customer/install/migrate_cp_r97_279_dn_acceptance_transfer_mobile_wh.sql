-- GAS-CP-R97-279 (v1.267.0): delivery notes — acceptance by e-mail link with a status log, transfer between warehouses for a project, mobile warehouses.
ALTER TABLE inv_warehouses
  ADD COLUMN IF NOT EXISTS kind ENUM('fixed','mobile') NOT NULL DEFAULT 'fixed' AFTER name,
  ADD COLUMN IF NOT EXISTS holder_id INT UNSIGNED NULL AFTER kind,
  ADD COLUMN IF NOT EXISTS vehicle VARCHAR(80) NULL AFTER holder_id,
  ADD COLUMN IF NOT EXISTS location VARCHAR(160) NULL AFTER vehicle,
  ADD COLUMN IF NOT EXISTS location_at DATETIME NULL AFTER location;

ALTER TABLE inv_delivery_notes
  MODIFY COLUMN kind ENUM('project','customer','production','return','transfer') NOT NULL DEFAULT 'project',
  ADD COLUMN IF NOT EXISTS to_warehouse_id INT NULL AFTER warehouse_id,
  ADD COLUMN IF NOT EXISTS from_dn_id INT NULL AFTER order_id,
  ADD COLUMN IF NOT EXISTS delivery ENUM('open','sent','accepted','disputed','transit','received') NOT NULL DEFAULT 'open' AFTER status,
  ADD COLUMN IF NOT EXISTS accepted_name VARCHAR(90) NULL,
  ADD COLUMN IF NOT EXISTS accepted_role VARCHAR(80) NULL,
  ADD COLUMN IF NOT EXISTS accepted_email VARCHAR(190) NULL,
  ADD COLUMN IF NOT EXISTS accepted_via ENUM('email','paper','portal') NULL,
  ADD COLUMN IF NOT EXISTS accepted_at DATETIME NULL,
  ADD COLUMN IF NOT EXISTS accepted_ip VARCHAR(45) NULL,
  ADD COLUMN IF NOT EXISTS accepted_remark VARCHAR(2000) NULL,
  ADD KEY IF NOT EXISTS ix_dn_delivery (delivery), ADD KEY IF NOT EXISTS ix_dn_towh (to_warehouse_id), ADD KEY IF NOT EXISTS ix_dn_from (from_dn_id);

ALTER TABLE inv_dn_lines ADD COLUMN IF NOT EXISTS move_in_id INT NULL AFTER move_id;

-- the log of a delivery note: issued, sent for acceptance, accepted, not accepted, received at the warehouse, cancelled
CREATE TABLE IF NOT EXISTS inv_dn_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dn_id INT NOT NULL, event VARCHAR(20) NOT NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL, note VARCHAR(600) NULL, ip VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_dne_dn (dn_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the signature drawn by the recipient (kept apart so lists of notes stay light)
CREATE TABLE IF NOT EXISTS inv_dn_signs (
  dn_id INT NOT NULL PRIMARY KEY, png MEDIUMBLOB NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- reservations of requisition lines that travelled with a transfer note (needed to take a transfer back)
CREATE TABLE IF NOT EXISTS inv_dn_rsv (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  dn_id INT NOT NULL, dn_line_id INT NOT NULL, pr_line_id INT UNSIGNED NOT NULL, qty DECIMAL(12,3) NOT NULL,
  KEY ix_dnr_dn (dn_id), KEY ix_dnr_pr (pr_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS Customer Portal — R5: Golden A/S roles, login history             GAS-CP-R5-001
-- Run ONCE in phpMyAdmin (the ADD COLUMN line errors harmlessly if run again).

CREATE TABLE IF NOT EXISTS staff_roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80)  NOT NULL UNIQUE,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO staff_roles (name, sort_order) VALUES
  ('CEO', 1), ('Technical Manager', 2), ('Project Manager', 3), ('Service Manager', 4),
  ('Production Manager', 5), ('Engineering', 6);

ALTER TABLE customer_users
  ADD COLUMN staff_role_id INT UNSIGNED NULL AFTER role,
  ADD CONSTRAINT fk_cu_staff_role FOREIGN KEY (staff_role_id) REFERENCES staff_roles(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS login_log (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  email       VARCHAR(190) NOT NULL,
  outcome     ENUM('success','failed','locked','disabled','logout') NOT NULL,
  ip          VARCHAR(45)  NULL,
  user_agent  VARCHAR(255) NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ll_time (created_at), INDEX idx_ll_user (user_id), INDEX idx_ll_email (email), INDEX idx_ll_outcome (outcome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- link existing projects whose free-text project manager matches a staff account
UPDATE projects p JOIN customer_users cu ON cu.role IN ('staff','admin') AND LOWER(cu.full_name) = LOWER(p.project_manager)
SET p.pm_user_id = cu.id WHERE p.pm_user_id IS NULL;

-- GAS-CP-R97-256 — register of asset imports; authenticator obligatory for CEO, Managing Director, owner roles and administrators (key r99y)
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS inv_asset_imports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL,
  mode VARCHAR(8) NOT NULL DEFAULT 'add',
  warehouse_id INT UNSIGNED NULL,
  units INT NOT NULL DEFAULT 0,
  new_items INT NOT NULL DEFAULT 0,
  switched INT NOT NULL DEFAULT 0,
  handed INT NOT NULL DEFAULT 0,
  people INT NOT NULL DEFAULT 0,
  skipped INT NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  KEY ix_inv_asset_imports_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
UPDATE customer_users SET twofa_required = 1 WHERE role = 'admin';
UPDATE customer_users cu JOIN staff_roles r ON r.id = cu.staff_role_id SET cu.twofa_required = 1 WHERE cu.role <> 'customer' AND (LOWER(TRIM(r.name)) IN ('ceo', 'managing director', 'general manager') OR r.scope = 'owner');

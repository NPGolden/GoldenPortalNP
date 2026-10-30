-- GAS-CP-R41-003 · Subcontracted / external production — scope lines per production order (whole equipment or a part)
-- Run once in phpMyAdmin with the portal database selected.
CREATE TABLE IF NOT EXISTS production_subs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  unit_id INT UNSIGNED NULL,
  whole TINYINT(1) NOT NULL DEFAULT 0,
  scope VARCHAR(200) NOT NULL,
  supplier VARCHAR(120) NOT NULL,
  company_id INT UNSIGNED NULL,
  po_no VARCHAR(60) NULL,
  ordered_at DATE NULL,
  due_at DATE NULL,
  delivered_at DATE NULL,
  amount DECIMAL(12,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(16) NOT NULL DEFAULT 'planned',
  pct TINYINT UNSIGNED NOT NULL DEFAULT 0,
  inspection_note VARCHAR(500) NULL,
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_ps_order (order_id), KEY ix_ps_unit (unit_id), KEY ix_ps_status (status), KEY ix_ps_supplier (supplier)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R79-001 — Inventory, categories, warehouses, stock, movements, price history (100 per code), pricing rules, supplier details (GAS-2026-PORTAL-PRC-001 R3, release R79)
CREATE TABLE IF NOT EXISTS inv_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  code_prefix VARCHAR(12) NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY ux_invcat_name (name), KEY ix_invcat_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_warehouses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  branch_id TINYINT UNSIGNED NULL,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(120) NOT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY ux_invwh_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(60) NOT NULL,
  description VARCHAR(255) NOT NULL,
  description_en VARCHAR(255) NULL,
  category_id INT UNSIGNED NULL,
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  pack_qty DECIMAL(10,2) NULL,
  maker VARCHAR(80) NULL,
  factory_code VARCHAR(80) NULL,
  barcode VARCHAR(64) NULL,
  location VARCHAR(60) NULL,
  min_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
  status ENUM('active','obsolete','blocked') NOT NULL DEFAULT 'active',
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  last_price DECIMAL(12,4) NULL,
  last_price_at DATE NULL,
  last_supplier_id INT UNSIGNED NULL,
  avg_cost DECIMAL(12,4) NULL,
  preferred_supplier_id INT UNSIGNED NULL,
  notes TEXT NULL,
  file_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_inv_code (code), KEY ix_inv_cat (category_id), KEY ix_inv_maker (maker), KEY ix_inv_factory (factory_code), KEY ix_inv_barcode (barcode), KEY ix_inv_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_stock (
  item_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  qty_on_hand DECIMAL(12,3) NOT NULL DEFAULT 0,
  qty_reserved DECIMAL(12,3) NOT NULL DEFAULT 0,
  location VARCHAR(60) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (item_id, warehouse_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_moves (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  warehouse_id INT UNSIGNED NOT NULL,
  kind ENUM('in','out','adj','xfer_out','xfer_in','opening') NOT NULL,
  qty DECIMAL(12,3) NOT NULL,
  unit_price DECIMAL(12,4) NULL,
  project_id INT UNSIGNED NULL,
  subproject_id INT UNSIGNED NULL,
  ref_kind VARCHAR(12) NULL,
  ref_id INT UNSIGNED NULL,
  reason VARCHAR(40) NULL,
  note VARCHAR(255) NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_invmv_item (item_id, id), KEY ix_invmv_sub (subproject_id), KEY ix_invmv_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_prices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  priced_at DATE NOT NULL,
  supplier_id INT UNSIGNED NULL,
  po_line_id INT UNSIGNED NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  unit_price DECIMAL(12,4) NOT NULL,
  qty DECIMAL(12,3) NULL,
  source ENUM('receipt','erp','manual','rollup') NOT NULL DEFAULT 'manual',
  n_rolled INT UNSIGNED NULL, min_price DECIMAL(12,4) NULL, max_price DECIMAL(12,4) NULL,
  note VARCHAR(160) NULL,
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_invpr_item (item_id, priced_at, id), KEY ix_invpr_sup (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_price_rules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope ENUM('category','supplier') NOT NULL,
  ref_id INT UNSIGNED NOT NULL,
  rule VARCHAR(16) NOT NULL,
  n TINYINT UNSIGNED NOT NULL DEFAULT 3,
  pct DECIMAL(6,2) NOT NULL DEFAULT 0,
  UNIQUE KEY ux_invrule (scope, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS supplier_details (
  company_id INT UNSIGNED NOT NULL PRIMARY KEY,
  vat_no VARCHAR(40) NULL,
  address VARCHAR(200) NULL,
  postal_code VARCHAR(20) NULL,
  terms_days SMALLINT UNSIGNED NULL,
  advance_pct DECIMAL(5,2) NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  lead_days SMALLINT UNSIGNED NULL,
  incoterms VARCHAR(20) NULL,
  rating TINYINT UNSIGNED NULL,
  remarks TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE customer_users ADD COLUMN can_procurement TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customer_users ADD COLUMN can_warehouse TINYINT(1) NOT NULL DEFAULT 0;
INSERT IGNORE INTO inv_warehouses (code, name, is_default) VALUES ('HQ', 'Keratsini - head office', 1);

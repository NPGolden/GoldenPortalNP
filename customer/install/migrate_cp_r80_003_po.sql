-- GAS-CP-R80-003 — RFQs and purchase orders per supplier
CREATE TABLE IF NOT EXISTS po_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('rfq','po') NOT NULL DEFAULT 'po',
  po_no VARCHAR(40) NOT NULL,
  branch_id TINYINT UNSIGNED NULL,
  supplier_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NULL,
  subproject_id INT UNSIGNED NULL,
  rfq_id INT UNSIGNED NULL,
  title VARCHAR(160) NULL,
  status ENUM('draft','sent','quoted','awarded','confirmed','partial','received','closed','cancelled') NOT NULL DEFAULT 'draft',
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  incoterms VARCHAR(20) NULL, terms_days SMALLINT NULL, advance_pct DECIMAL(5,2) NULL,
  delivery_date DATE NULL, warehouse_id INT UNSIGNED NULL,
  supplier_ref VARCHAR(80) NULL, supplier_contact VARCHAR(120) NULL, supplier_email VARCHAR(190) NULL,
  subtotal DECIMAL(14,2) NOT NULL DEFAULT 0, vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0, vat_total DECIMAL(14,2) NOT NULL DEFAULT 0, gross_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  note TEXT NULL, internal_note TEXT NULL,
  sent_at DATETIME NULL, sent_to VARCHAR(400) NULL, confirmed_at DATETIME NULL,
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_po_no (po_no), KEY ix_po_sup (supplier_id), KEY ix_po_sub (subproject_id), KEY ix_po_status (kind, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  item_id INT UNSIGNED NULL,
  alt_of_item_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  maker VARCHAR(80) NULL, part_no VARCHAR(80) NULL,
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  qty DECIMAL(12,3) NOT NULL,
  unit_price DECIMAL(12,4) NULL, discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0, line_total DECIMAL(14,2) NULL,
  price_origin VARCHAR(160) NULL,
  lead_days SMALLINT NULL, need_date DATE NULL,
  pr_line_id INT UNSIGNED NULL, bom_line_id INT UNSIGNED NULL,
  qty_received DECIMAL(12,3) NOT NULL DEFAULT 0, qty_accepted DECIMAL(12,3) NOT NULL DEFAULT 0,
  status ENUM('open','partial','received','cancelled') NOT NULL DEFAULT 'open',
  note VARCHAR(255) NULL,
  KEY ix_pol_order (order_id, seq), KEY ix_pol_item (item_id), KEY ix_pol_pr (pr_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  event VARCHAR(24) NOT NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL, ip VARCHAR(64) NULL,
  note VARCHAR(600) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_poe_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

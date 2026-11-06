-- GAS-CP-R88-001 — v1.105.0
-- 1. document web links on inventory items · 2. software licences (item_type) · 3. RFO to several suppliers · 4. delivery notes
-- Run once (the ALTERs are not idempotent); the deploy applies it through cp_patches().

-- 1. links
CREATE TABLE IF NOT EXISTS inv_item_links (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  title VARCHAR(120) NOT NULL,
  url VARCHAR(500) NOT NULL,
  kind ENUM('datasheet','manual','drawing','certificate','software','supplier','other') NOT NULL DEFAULT 'other',
  user_id INT NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_lnk_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. software
ALTER TABLE inv_items ADD COLUMN item_type ENUM('part','software','service') NOT NULL DEFAULT 'part' AFTER category_id;
CREATE TABLE IF NOT EXISTS inv_licences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  item_id INT NOT NULL,
  product VARCHAR(160) NULL,
  version VARCHAR(60) NULL,
  licence_key VARCHAR(255) NULL,
  serial_no VARCHAR(120) NULL,
  seats DECIMAL(10,2) NOT NULL DEFAULT 1,
  kind ENUM('perpetual','subscription','trial','oem') NOT NULL DEFAULT 'perpetual',
  purchased_at DATE NULL,
  expires_at DATE NULL,
  supplier_id INT NULL,
  po_line_id INT NULL,
  receipt_id INT NULL,
  assigned_kind ENUM('none','project','vessel','user','company') NOT NULL DEFAULT 'none',
  assigned_id INT NULL,
  assigned_label VARCHAR(160) NULL,
  status ENUM('pending','active','expired','retired') NOT NULL DEFAULT 'active',
  note VARCHAR(400) NULL,
  user_id INT NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_lic_item (item_id), KEY ix_lic_exp (expires_at), KEY ix_lic_assigned (assigned_kind, assigned_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. RFO: one request, several suppliers
CREATE TABLE IF NOT EXISTS po_rfq_suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rfq_id INT NOT NULL,
  supplier_id INT NOT NULL,
  status ENUM('invited','sent','quoted','declined','awarded','lost') NOT NULL DEFAULT 'invited',
  sent_at DATETIME NULL, sent_to VARCHAR(400) NULL,
  quoted_at DATETIME NULL,
  supplier_ref VARCHAR(80) NULL,
  delivery_date DATE NULL,
  currency CHAR(3) NULL,
  total DECIMAL(14,2) NULL,
  note VARCHAR(400) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_rfqsup (rfq_id, supplier_id), KEY ix_rfqsup_sup (supplier_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_rfq_quotes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  rfq_supplier_id INT NOT NULL,
  po_line_id INT NOT NULL,
  unit_price DECIMAL(12,4) NULL,
  lead_days SMALLINT NULL,
  note VARCHAR(160) NULL,
  UNIQUE KEY uq_rfqq (rfq_supplier_id, po_line_id), KEY ix_rfqq_line (po_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- existing RFQs: their single supplier becomes the first invited supplier, typed prices become its quotes
INSERT IGNORE INTO po_rfq_suppliers (rfq_id, supplier_id, status, sent_at, sent_to, quoted_at, supplier_ref, delivery_date, currency, total)
  SELECT o.id, o.supplier_id,
         CASE o.status WHEN 'draft' THEN 'invited' WHEN 'sent' THEN 'sent' WHEN 'quoted' THEN 'quoted' WHEN 'awarded' THEN 'awarded' WHEN 'cancelled' THEN 'declined' ELSE 'invited' END,
         o.sent_at, o.sent_to, CASE WHEN o.status IN ('quoted','awarded') THEN o.updated_at ELSE NULL END, o.supplier_ref, o.delivery_date, o.currency, CASE WHEN o.status IN ('quoted','awarded') THEN o.subtotal ELSE NULL END
  FROM po_orders o WHERE o.kind = 'rfq';
INSERT IGNORE INTO po_rfq_quotes (rfq_supplier_id, po_line_id, unit_price, lead_days)
  SELECT rs.id, l.id, l.unit_price, l.lead_days
  FROM po_lines l JOIN po_orders o ON o.id = l.order_id AND o.kind = 'rfq' JOIN po_rfq_suppliers rs ON rs.rfq_id = o.id AND rs.supplier_id = o.supplier_id
  WHERE l.unit_price IS NOT NULL OR l.lead_days IS NOT NULL;

-- 4. delivery notes
CREATE TABLE IF NOT EXISTS inv_delivery_notes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dn_no VARCHAR(30) NOT NULL,
  kind ENUM('project','customer','production','return') NOT NULL DEFAULT 'project',
  branch_id INT NULL,
  warehouse_id INT NULL,
  project_id INT NULL, subproject_id INT NULL,
  company_id INT NULL, vessel_id INT NULL,
  receipt_id INT NULL, order_id INT NULL,
  issued_at DATE NOT NULL,
  address VARCHAR(240) NULL, attention VARCHAR(120) NULL, carrier VARCHAR(120) NULL, ref_no VARCHAR(80) NULL,
  note VARCHAR(600) NULL,
  status ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
  file_id INT NULL,
  user_id INT NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_dn_no (dn_no), KEY ix_dn_project (project_id), KEY ix_dn_company (company_id), KEY ix_dn_receipt (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS inv_dn_lines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dn_id INT NOT NULL,
  seq SMALLINT NOT NULL DEFAULT 1,
  item_id INT NULL, po_line_id INT NULL, pr_line_id INT NULL,
  description VARCHAR(255) NOT NULL,
  unit VARCHAR(12) NOT NULL DEFAULT 'pcs',
  qty DECIMAL(12,3) NOT NULL,
  move_id INT NULL,
  note VARCHAR(255) NULL,
  KEY ix_dnl_dn (dn_id), KEY ix_dnl_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE po_receipt_lines ADD COLUMN dest ENUM('stock','project') NOT NULL DEFAULT 'stock' AFTER location;
ALTER TABLE po_receipt_lines ADD COLUMN dn_id INT NULL AFTER dest;

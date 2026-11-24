-- GAS-CP-R97-227 — delivery check: the supplier's delivery note or invoice against the purchase order (key r99m)
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS po_dchecks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  check_no VARCHAR(60) NOT NULL,
  doc_kind VARCHAR(12) NOT NULL DEFAULT 'dn',
  doc_no VARCHAR(80) NULL,
  doc_date DATE NULL,
  warehouse_id INT UNSIGNED NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  file_id INT UNSIGNED NULL,
  file_name VARCHAR(160) NULL,
  ai_notes VARCHAR(600) NULL,
  note VARCHAR(600) NULL,
  receipt_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by INT UNSIGNED NULL,
  decided_name VARCHAR(120) NULL,
  decided_at DATETIME NULL,
  decided_note VARCHAR(400) NULL,
  KEY ix_pdc_order (order_id), KEY ix_pdc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_dcheck_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  check_id INT UNSIGNED NOT NULL,
  seq INT NOT NULL DEFAULT 0,
  doc_code VARCHAR(80) NULL,
  doc_desc VARCHAR(255) NULL,
  doc_qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  doc_unit VARCHAR(12) NULL,
  doc_price DECIMAL(14,4) NULL,
  finding VARCHAR(12) NOT NULL DEFAULT 'extra',
  po_line_id INT UNSIGNED NULL,
  action VARCHAR(12) NOT NULL DEFAULT 'skip',
  item_id INT UNSIGNED NULL,
  new_code VARCHAR(60) NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 0,
  remember TINYINT(1) NOT NULL DEFAULT 0,
  conf TINYINT UNSIGNED NOT NULL DEFAULT 0,
  reason VARCHAR(255) NULL,
  KEY ix_pdcl_check (check_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_dcheck_close (
  check_id INT UNSIGNED NOT NULL,
  po_line_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (check_id, po_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- the code a supplier prints for one of our items, learnt from accepted delivery checks
CREATE TABLE IF NOT EXISTS inv_supplier_codes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  item_id INT UNSIGNED NOT NULL,
  supplier_id INT UNSIGNED NULL,
  code VARCHAR(80) NOT NULL,
  note VARCHAR(160) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_isc (item_id, supplier_id, code), KEY ix_isc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

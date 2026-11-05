-- GAS-CP-R80-004 — Goods receipts per PO line with NCR / returns, delivery timeline (shipment · export · customs), logistic costs
ALTER TABLE po_lines ADD COLUMN promised_date DATE NULL AFTER need_date;
ALTER TABLE po_lines ADD COLUMN qty_rejected DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER qty_accepted;
ALTER TABLE po_orders ADD COLUMN logistics_total DECIMAL(14,2) NOT NULL DEFAULT 0 AFTER gross_total;
ALTER TABLE po_orders ADD COLUMN expected_date DATE NULL AFTER delivery_date;
ALTER TABLE po_orders ADD COLUMN cost_id INT UNSIGNED NULL AFTER internal_note;
CREATE TABLE IF NOT EXISTS po_milestones (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('confirmed','ready','shipped','export','arrived','customs','delivered') NOT NULL,
  planned_date DATE NULL, actual_date DATE NULL,
  carrier VARCHAR(120) NULL, tracking VARCHAR(120) NULL, ref VARCHAR(120) NULL,
  packages SMALLINT UNSIGNED NULL, weight_kg DECIMAL(10,2) NULL,
  note VARCHAR(400) NULL, file_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_pom (order_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_costs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  kind ENUM('freight','insurance','customs','duties','import_vat','handling','other') NOT NULL DEFAULT 'freight',
  cost_date DATE NOT NULL,
  provider VARCHAR(120) NULL, ref VARCHAR(80) NULL,
  amount DECIMAL(14,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'EUR',
  note VARCHAR(255) NULL, file_id INT UNSIGNED NULL,
  sp_cost_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_poc_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_receipts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  receipt_no VARCHAR(50) NOT NULL,
  received_at DATE NOT NULL,
  delivery_note VARCHAR(80) NULL,
  warehouse_id INT UNSIGNED NULL,
  note VARCHAR(400) NULL, file_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_por_no (receipt_no), KEY ix_por_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS po_receipt_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  receipt_id INT UNSIGNED NOT NULL,
  po_line_id INT UNSIGNED NOT NULL,
  qty_received DECIMAL(12,3) NOT NULL,
  qty_accepted DECIMAL(12,3) NOT NULL,
  qty_rejected DECIMAL(12,3) NOT NULL DEFAULT 0,
  ncr_reason VARCHAR(400) NULL,
  ncr_action ENUM('none','return','replace','credit') NOT NULL DEFAULT 'none',
  ncr_status ENUM('none','open','closed') NOT NULL DEFAULT 'none',
  ncr_closed_at DATE NULL, ncr_closed_note VARCHAR(400) NULL,
  location VARCHAR(40) NULL,
  note VARCHAR(255) NULL,
  KEY ix_porl_receipt (receipt_id), KEY ix_porl_line (po_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

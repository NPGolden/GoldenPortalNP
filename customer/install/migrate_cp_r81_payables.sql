-- GAS-CP-R81-001 — Supplier invoices (three-way check), credit notes, payment requests with management approval, payments, supplier statement, payables aging
CREATE TABLE IF NOT EXISTS si_invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('invoice','credit') NOT NULL DEFAULT 'invoice',
  si_no VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  inv_no VARCHAR(80) NOT NULL,
  order_id INT UNSIGNED NULL,
  credit_of INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL, subproject_id INT UNSIGNED NULL, branch_id TINYINT UNSIGNED NULL,
  issue_date DATE NOT NULL, due_date DATE NULL, received_at DATE NULL,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  net DECIMAL(14,2) NOT NULL DEFAULT 0, vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0, vat DECIMAL(14,2) NOT NULL DEFAULT 0, gross DECIMAL(14,2) NOT NULL DEFAULT 0,
  paid DECIMAL(14,2) NOT NULL DEFAULT 0,
  status ENUM('draft','checked','disputed','requested','approved','paid','cancelled') NOT NULL DEFAULT 'draft',
  match_status ENUM('none','ok','qty','price','both') NOT NULL DEFAULT 'none',
  note TEXT NULL, file_id INT UNSIGNED NULL,
  sp_cost_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  checked_by INT UNSIGNED NULL, checked_name VARCHAR(120) NULL, checked_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_si_no (si_no), UNIQUE KEY ux_si_sup_inv (supplier_id, inv_no), KEY ix_si_order (order_id), KEY ix_si_status (status), KEY ix_si_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS si_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL,
  seq SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  po_line_id INT UNSIGNED NULL,
  description VARCHAR(255) NOT NULL,
  qty DECIMAL(12,3) NOT NULL DEFAULT 1,
  unit_price DECIMAL(12,4) NOT NULL DEFAULT 0,
  line_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  po_qty_open DECIMAL(12,3) NULL, po_price DECIMAL(12,4) NULL,
  match_status ENUM('none','ok','qty','price','both') NOT NULL DEFAULT 'none',
  KEY ix_sil_inv (invoice_id), KEY ix_sil_pol (po_line_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS si_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  req_no VARCHAR(40) NOT NULL,
  supplier_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL DEFAULT 0, currency CHAR(3) NOT NULL DEFAULT 'EUR',
  pay_by DATE NULL,
  status ENUM('submitted','mgmt','approved','rejected','paid','cancelled') NOT NULL DEFAULT 'submitted',
  over_threshold TINYINT(1) NOT NULL DEFAULT 0,
  note VARCHAR(600) NULL,
  requested_by INT UNSIGNED NULL, requested_name VARCHAR(120) NULL,
  decided_by INT UNSIGNED NULL, decided_name VARCHAR(120) NULL, decided_at DATETIME NULL, decision_note VARCHAR(400) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_sir_no (req_no), KEY ix_sir_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS si_request_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id INT UNSIGNED NOT NULL, invoice_id INT UNSIGNED NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  UNIQUE KEY ux_sri (request_id, invoice_id), KEY ix_sri_inv (invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS si_payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NOT NULL, request_id INT UNSIGNED NULL,
  pay_date DATE NOT NULL, amount DECIMAL(14,2) NOT NULL, currency CHAR(3) NOT NULL DEFAULT 'EUR',
  method ENUM('transfer','card','cash','cheque','other') NOT NULL DEFAULT 'transfer',
  ref VARCHAR(80) NULL, note VARCHAR(255) NULL, file_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sip_inv (invoice_id), KEY ix_sip_date (pay_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS si_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT UNSIGNED NULL, request_id INT UNSIGNED NULL,
  event VARCHAR(24) NOT NULL, user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL, ip VARCHAR(64) NULL, note VARCHAR(600) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_sie_inv (invoice_id), KEY ix_sie_req (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE po_lines ADD COLUMN qty_invoiced DECIMAL(12,3) NOT NULL DEFAULT 0 AFTER qty_rejected;

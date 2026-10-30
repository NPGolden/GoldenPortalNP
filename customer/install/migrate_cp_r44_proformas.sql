-- GAS-CP-R44 · Release D2: budgetary offers (AACE 18R-97 classes), payment terms, proforma invoices, invoice links, project financial status
-- Run once in phpMyAdmin with the portal database selected.
CREATE TABLE IF NOT EXISTS offer_classes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(80) NOT NULL,
  definition VARCHAR(40) NULL,
  accuracy VARCHAR(80) NULL,
  purpose VARCHAR(120) NULL,
  is_firm TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_oc_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO offer_classes (code, name, definition, accuracy, purpose, is_firm, sort_order) VALUES
 ('5', 'Class 5 — Order of magnitude', '0 % – 2 %', 'L: −20 % to −50 % · H: +30 % to +100 %', 'Concept screening', 0, 10),
 ('4', 'Class 4 — Study / feasibility', '1 % – 15 %', 'L: −15 % to −30 % · H: +20 % to +50 %', 'Study or feasibility', 0, 20),
 ('3', 'Class 3 — Budget authorisation', '10 % – 40 %', 'L: −10 % to −20 % · H: +10 % to +30 %', 'Budget authorisation or control', 0, 30),
 ('2', 'Class 2 — Control / bid-tender', '30 % – 75 %', 'L: −5 % to −15 % · H: +5 % to +20 %', 'Control or bid / tender', 0, 40),
 ('1', 'Class 1 — Check estimate / firm', '65 % – 100 %', 'L: −3 % to −10 % · H: +3 % to +15 %', 'Check estimate, bid / tender — firm offer', 1, 50);
ALTER TABLE offers ADD COLUMN kind VARCHAR(12) NOT NULL DEFAULT 'firm', ADD COLUMN class_id INT UNSIGNED NULL, ADD COLUMN converted_to INT UNSIGNED NULL, ADD COLUMN converted_from INT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS offer_terms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  offer_id INT UNSIGNED NOT NULL,
  n TINYINT UNSIGNED NOT NULL,
  pct DECIMAL(6,2) NULL,
  amount DECIMAL(12,2) NULL,
  trigger_text VARCHAR(160) NOT NULL,
  due_days SMALLINT NULL,
  UNIQUE KEY uq_ot (offer_id, n)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS proformas (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  pi_no VARCHAR(60) NOT NULL,
  offer_id INT UNSIGNED NOT NULL,
  term_n TINYINT UNSIGNED NULL,
  project_id INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  vessel_id INT UNSIGNED NULL,
  invoicing_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  issue_date DATE NULL,
  due_date DATE NULL,
  amount_net DECIMAL(12,2) NOT NULL DEFAULT 0,
  vat_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  gross DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency CHAR(3) NOT NULL DEFAULT 'EUR',
  status VARCHAR(16) NOT NULL DEFAULT 'draft',
  file_id INT UNSIGNED NULL,
  notes TEXT NULL,
  sent_at DATETIME NULL,
  paid_at DATE NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pi_no (pi_no), KEY ix_pi_offer (offer_id), KEY ix_pi_project (project_id), KEY ix_pi_status (status), KEY ix_pi_due (due_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS proforma_invoices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proforma_id INT UNSIGNED NOT NULL,
  invoice_no VARCHAR(60) NOT NULL,
  invoice_date DATE NULL,
  amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_at DATE NULL,
  note VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pinv_pi (proforma_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE customer_users ADD COLUMN can_sales TINYINT(1) NOT NULL DEFAULT 0;

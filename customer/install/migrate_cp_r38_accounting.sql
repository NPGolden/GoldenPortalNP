-- GAS-CP-R38-001 — Accounting register and accounting reports (approved by N. Polykandriotis 26.10.2026).
-- Every billable hour line (timesheets, service reports) is registered once with an accounting state;
-- a PM prepares an accounting report (GAS-AR-YYYY-NNN) per vessel / project / sub-project and period,
-- classifies every line (include / exclude), sets discounts, issues PDF + Excel and e-mails accounting;
-- accounting answers in the portal (invoiced / partly / returned) and the timesheets and service reports follow.

CREATE TABLE IF NOT EXISTS acc_reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_no VARCHAR(32) NOT NULL,                     -- GAS-AR-2026-001
  revision VARCHAR(8) NOT NULL DEFAULT 'R0',
  branch_id INT UNSIGNED NULL,
  vessel_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  subproject_id INT UNSIGNED NULL,
  period_start DATE NULL,
  period_end DATE NULL,
  zone VARCHAR(10) NOT NULL DEFAULT 'local',          -- local | overseas (default rate zone of the lines)
  currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
  header_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  status ENUM('draft','issued','sent','returned','partly_invoiced','invoiced') NOT NULL DEFAULT 'draft',
  total_hours DECIMAL(9,2) NOT NULL DEFAULT 0,
  total_gross DECIMAL(12,2) NOT NULL DEFAULT 0,       -- before discounts
  total_net DECIMAL(12,2) NOT NULL DEFAULT 0,         -- after line and header discounts
  note VARCHAR(1000) NULL,
  pdf_file_id INT UNSIGNED NULL,
  xlsx_file_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL, created_name VARCHAR(120) NULL,
  issued_at DATETIME NULL, issued_by INT UNSIGNED NULL, issued_name VARCHAR(120) NULL,
  sent_at DATETIME NULL, sent_to VARCHAR(190) NULL,
  answered_at DATETIME NULL, answered_by INT UNSIGNED NULL, answered_name VARCHAR(120) NULL, answer_note VARCHAR(1000) NULL,
  invoice_ref VARCHAR(120) NULL, invoice_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_acc_no (report_no, revision),
  KEY ix_acc_vessel (vessel_id), KEY ix_acc_project (project_id), KEY ix_acc_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acc_lines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  source ENUM('ts','fsr') NOT NULL,                   -- timesheet line | service-report hour line
  source_id INT UNSIGNED NOT NULL,                    -- timesheet_lines.id | fsr_lines.id
  doc_id INT UNSIGNED NOT NULL,                       -- timesheets.id | fsr_reports.id
  doc_no VARCHAR(40) NULL,
  vessel_id INT UNSIGNED NULL,
  project_id INT UNSIGNED NULL,
  subproject_id INT UNSIGNED NULL,
  work_date DATE NOT NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  discipline_id INT UNSIGNED NULL,
  hour_type ENUM('normal','overtime','holiday','travel') NOT NULL DEFAULT 'normal',
  hours DECIMAL(7,2) NOT NULL DEFAULT 0,
  description VARCHAR(500) NULL,
  zone VARCHAR(10) NULL,                              -- line override of the report zone
  rate_item_id INT UNSIGNED NULL,                     -- rate card item used
  unit_price DECIMAL(10,2) NULL,                      -- sale price per hour (snapshot)
  cost_rate DECIMAL(10,2) NULL,                       -- discipline cost per hour (snapshot)
  discount_pct DECIMAL(5,2) NOT NULL DEFAULT 0,
  discount_reason VARCHAR(255) NULL,
  value DECIMAL(12,2) NULL,                           -- hours × price × (1 − discount)
  state ENUM('open','draft','reported','invoiced','excluded') NOT NULL DEFAULT 'open',
  report_id INT UNSIGNED NULL,
  exclude_reason VARCHAR(255) NULL,
  invoice_ref VARCHAR(120) NULL,
  invoiced_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY ux_acc_src (source, source_id, hour_type),
  KEY ix_accl_state (state), KEY ix_accl_report (report_id), KEY ix_accl_doc (source, doc_id),
  KEY ix_accl_vessel (vessel_id), KEY ix_accl_project (project_id), KEY ix_accl_date (work_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS acc_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  report_id INT UNSIGNED NOT NULL,
  event VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  note VARCHAR(1000) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_acce_report (report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- who may prepare accounting reports / who answers as accounting
ALTER TABLE customer_users ADD COLUMN can_accounting TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN is_accounting TINYINT(1) NOT NULL DEFAULT 0;
-- the rate card (A … N) that prices a discipline's hours
ALTER TABLE disciplines ADD COLUMN rate_card VARCHAR(12) NULL;
-- where the accounting reports of a branch are sent
ALTER TABLE branches ADD COLUMN accounting_email VARCHAR(190) NULL;
-- invoicing state of the source documents, driven by the register
ALTER TABLE timesheets ADD COLUMN invoicing ENUM('none','sent','partly','invoiced') NOT NULL DEFAULT 'none', ADD COLUMN acc_report_id INT UNSIGNED NULL;
ALTER TABLE fsr_reports ADD COLUMN invoicing ENUM('none','sent','partly','invoiced') NOT NULL DEFAULT 'none', ADD COLUMN acc_report_id INT UNSIGNED NULL;
UPDATE branches SET accounting_email = 'accounting@goldenas.com.gr' WHERE accounting_email IS NULL;

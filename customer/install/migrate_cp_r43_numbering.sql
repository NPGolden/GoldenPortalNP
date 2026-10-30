-- GAS-CP-R43 · Release D1: automatic numbers (PRJ.nnnnnn / Y.nnn / SQ / PI), invoicing details per vessel, sub-project contacts
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE invoicing_details MODIFY owner_type ENUM('customer','company','contact','vessel') NOT NULL;
ALTER TABLE invoicing_details ADD COLUMN vat_pct DECIMAL(5,2) NULL;
ALTER TABLE subprojects ADD COLUMN invoicing_id INT UNSIGNED NULL;
ALTER TABLE offers ADD COLUMN sq_no VARCHAR(60) NULL, ADD KEY ix_offers_sq (sq_no);
CREATE TABLE IF NOT EXISTS sp_contacts (
  subproject_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  role VARCHAR(80) NULL,
  added_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (subproject_id, contact_id), KEY ix_spc_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

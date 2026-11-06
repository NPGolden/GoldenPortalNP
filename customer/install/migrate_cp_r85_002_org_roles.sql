-- GAS-CP-R85-002 — organisations with roles and master data in one place
ALTER TABLE companies
  ADD COLUMN roles VARCHAR(160) NOT NULL DEFAULT '' AFTER kind,
  ADD COLUMN vat_no VARCHAR(40) NULL AFTER customer_id,
  ADD COLUMN erp_code VARCHAR(40) NULL AFTER vat_no,
  ADD COLUMN address VARCHAR(200) NULL AFTER erp_code,
  ADD COLUMN postal_code VARCHAR(20) NULL AFTER address;
UPDATE companies SET roles = TRIM(BOTH ',' FROM CONCAT_WS(',', NULLIF(kind, ''), IF(is_supplier = 1 AND kind <> 'supplier', 'supplier', NULL), IF(customer_id IS NOT NULL, 'customer', NULL)));
UPDATE companies co JOIN supplier_details sd ON sd.company_id = co.id SET co.vat_no = COALESCE(NULLIF(co.vat_no, ''), sd.vat_no), co.erp_code = COALESCE(co.erp_code, sd.erp_code), co.address = COALESCE(co.address, sd.address), co.postal_code = COALESCE(co.postal_code, sd.postal_code);
UPDATE companies co JOIN customers cu ON cu.id = co.customer_id SET co.vat_no = COALESCE(NULLIF(co.vat_no, ''), cu.vat_no), co.erp_code = COALESCE(co.erp_code, cu.erp_code), co.address = COALESCE(co.address, cu.address), co.postal_code = COALESCE(co.postal_code, cu.postal_code), co.country = COALESCE(NULLIF(co.country, ''), cu.country), co.city = COALESCE(NULLIF(co.city, ''), cu.city);
-- a person may belong to several organisations (contacts.company_id stays the primary one)
CREATE TABLE IF NOT EXISTS contact_companies (
  contact_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  position VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, company_id),
  KEY ix_cc_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO contact_companies (contact_id, company_id, position) SELECT id, company_id, position FROM contacts WHERE company_id IS NOT NULL;

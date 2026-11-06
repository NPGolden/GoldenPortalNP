-- GAS-CP-R84-003 — a company can be a customer / owner / yard AND a supplier: independent flag
ALTER TABLE companies ADD COLUMN is_supplier TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;
UPDATE companies SET is_supplier = 1 WHERE kind = 'supplier' OR id IN (SELECT company_id FROM supplier_details);

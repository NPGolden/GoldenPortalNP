-- GAS-CP-R85-004 — the related companies of a vessel become organisations of the Contacts module (customer_id kept for the old rows)
ALTER TABLE vessel_companies MODIFY customer_id INT UNSIGNED NULL, ADD COLUMN company_id INT UNSIGNED NULL AFTER customer_id, ADD KEY ix_vc_company (company_id);
ALTER TABLE vessel_companies DROP INDEX ux_vessel_company;
UPDATE vessel_companies vc JOIN companies co ON co.customer_id = vc.customer_id SET vc.company_id = co.id WHERE vc.company_id IS NULL;

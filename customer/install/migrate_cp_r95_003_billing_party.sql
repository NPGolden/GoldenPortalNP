-- GAS-CP-R95-003 — phase 1 of the parties / invoicing plan: billing party on projects, sub-projects and ledger rows;
-- vessel / customer of every ledger row from the project.
ALTER TABLE projects ADD COLUMN billing_org_id INT UNSIGNED NULL AFTER customer_id;
ALTER TABLE projects ADD COLUMN invoicing_id INT UNSIGNED NULL AFTER billing_org_id;
ALTER TABLE subprojects ADD COLUMN billing_org_id INT UNSIGNED NULL AFTER invoicing_id;
ALTER TABLE proformas ADD COLUMN billing_org_id INT UNSIGNED NULL AFTER customer_id;
ALTER TABLE proformas ADD KEY ix_pi_party (billing_org_id);
-- back-fill: vessel and customer of ledger rows from the project (main-level and vessel-less documents were missing from vessel statements)
UPDATE proformas pi JOIN projects p ON p.id = pi.project_id SET pi.vessel_id = p.vessel_id WHERE pi.vessel_id IS NULL AND p.vessel_id IS NOT NULL;
UPDATE proformas pi JOIN projects p ON p.id = pi.project_id SET pi.customer_id = p.customer_id WHERE pi.customer_id IS NULL;
-- billing party of existing rows: the organisation owning the chosen invoicing identity, else the organisation linked to the project's customer
UPDATE proformas pi JOIN invoicing_details iv ON iv.id = pi.invoicing_id AND iv.owner_type = 'company' SET pi.billing_org_id = iv.owner_id WHERE pi.billing_org_id IS NULL;
UPDATE proformas pi JOIN invoicing_details iv ON iv.id = pi.invoicing_id AND iv.owner_type = 'customer' JOIN companies co ON co.customer_id = iv.owner_id AND co.is_active = 1 SET pi.billing_org_id = co.id WHERE pi.billing_org_id IS NULL;
UPDATE proformas pi JOIN projects p ON p.id = pi.project_id JOIN companies co ON co.customer_id = p.customer_id AND co.is_active = 1 SET pi.billing_org_id = co.id WHERE pi.billing_org_id IS NULL;
-- projects: party = the organisation of the customer (where one exists; the rest is resolved at read time and set when the project is next saved)
UPDATE projects p JOIN companies co ON co.customer_id = p.customer_id AND co.is_active = 1 SET p.billing_org_id = co.id WHERE p.billing_org_id IS NULL;

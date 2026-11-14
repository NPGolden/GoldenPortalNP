-- GAS-CP-R97-037 (v1.138.0) — Parts delivered with a delivery note go to accounting: procurement decides per line (to be invoiced / included in the offer), accounting registers the invoice
ALTER TABLE inv_dn_lines ADD COLUMN inv_state VARCHAR(10) NOT NULL DEFAULT 'open';
ALTER TABLE inv_dn_lines ADD COLUMN inv_price DECIMAL(12,2) NULL;
ALTER TABLE inv_dn_lines ADD COLUMN inv_ref VARCHAR(120) NULL;
ALTER TABLE inv_dn_lines ADD COLUMN inv_date DATE NULL;
ALTER TABLE inv_dn_lines ADD COLUMN inv_by INT NULL;
ALTER TABLE inv_dn_lines ADD COLUMN inv_name VARCHAR(120) NULL;
ALTER TABLE inv_dn_lines ADD COLUMN inv_at DATETIME NULL;
ALTER TABLE inv_dn_lines ADD COLUMN dec_by INT NULL;
ALTER TABLE inv_dn_lines ADD COLUMN dec_name VARCHAR(120) NULL;
ALTER TABLE inv_dn_lines ADD COLUMN dec_at DATETIME NULL;
ALTER TABLE inv_dn_lines ADD INDEX idx_dnl_inv_state (inv_state);

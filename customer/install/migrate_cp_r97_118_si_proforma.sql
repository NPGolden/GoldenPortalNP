-- GAS-CP-R97-118 (v1.185.0) — payables: a supplier proforma paid in advance, the supplier's invoice assigned to it later
ALTER TABLE si_invoices ADD COLUMN is_proforma TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;
ALTER TABLE si_invoices ADD COLUMN proforma_no VARCHAR(80) NULL AFTER inv_no;
ALTER TABLE si_invoices ADD COLUMN final_at DATETIME NULL;
ALTER TABLE si_invoices ADD COLUMN final_name VARCHAR(120) NULL;
-- inventory: an item can be left out of the valuation (chosen per item)
ALTER TABLE inv_items ADD COLUMN no_valuation TINYINT(1) NOT NULL DEFAULT 0;

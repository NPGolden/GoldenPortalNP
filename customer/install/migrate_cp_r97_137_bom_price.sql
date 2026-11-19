-- GAS-CP-R97-137 (v1.195.0): the unit price of the BOM file is kept on the BOM line and on the requisition line;
-- per BOM revision / requisition the estimate is taken from the BOM price or from the buying-price rule. Idempotent.
ALTER TABLE bom_lines ADD COLUMN IF NOT EXISTS bom_price DECIMAL(12,4) NULL AFTER est_price;
ALTER TABLE bom_import_rows ADD COLUMN IF NOT EXISTS bom_price DECIMAL(12,4) NULL AFTER code;
ALTER TABLE pr_lines ADD COLUMN IF NOT EXISTS bom_price DECIMAL(12,4) NULL AFTER est_price;
ALTER TABLE bom_revisions ADD COLUMN IF NOT EXISTS price_src VARCHAR(8) NOT NULL DEFAULT 'last';
ALTER TABLE pr_requests ADD COLUMN IF NOT EXISTS price_src VARCHAR(8) NOT NULL DEFAULT 'last';

-- GAS-CP-R87-001 — stock control: typed movement reasons with references, consumables, low-stock alarm per part, reorder quantity
ALTER TABLE inv_moves ADD COLUMN ref_no VARCHAR(40) NULL AFTER reason, ADD COLUMN company_id INT UNSIGNED NULL AFTER ref_no, ADD KEY ix_mv_reason (reason), ADD KEY ix_mv_company (company_id);
ALTER TABLE inv_items ADD COLUMN is_consumable TINYINT(1) NOT NULL DEFAULT 0 AFTER min_stock, ADD COLUMN alert_low TINYINT(1) NOT NULL DEFAULT 0 AFTER is_consumable, ADD COLUMN reorder_qty DECIMAL(12,3) NULL AFTER alert_low;
UPDATE inv_items SET alert_low = 1 WHERE min_stock > 0;
UPDATE inv_moves SET reason = 'manual' WHERE reason IS NULL AND kind IN ('in','out');

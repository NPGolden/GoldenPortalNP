-- GAS-CP-R97-049 (v1.144.0) — System Budgets: procurement step (prices, delivery times, alternatives)
ALTER TABLE sb_budgets ADD COLUMN proc_state VARCHAR(12) NULL;
ALTER TABLE sb_budgets ADD COLUMN proc_note VARCHAR(300) NULL;
ALTER TABLE sb_budgets ADD COLUMN proc_req_by INT UNSIGNED NULL;
ALTER TABLE sb_budgets ADD COLUMN proc_req_at DATETIME NULL;
ALTER TABLE sb_budgets ADD COLUMN proc_done_by INT UNSIGNED NULL;
ALTER TABLE sb_budgets ADD COLUMN proc_done_at DATETIME NULL;
ALTER TABLE sb_parts ADD COLUMN lead_weeks DECIMAL(5,1) NULL;
ALTER TABLE sb_parts ADD COLUMN proc_cost DECIMAL(14,4) NULL;
ALTER TABLE sb_parts ADD COLUMN proc_supplier VARCHAR(120) NULL;
ALTER TABLE sb_parts ADD COLUMN proc_note VARCHAR(300) NULL;
ALTER TABLE sb_parts ADD COLUMN alt_descr VARCHAR(300) NULL;
ALTER TABLE sb_parts ADD COLUMN alt_cost DECIMAL(14,4) NULL;
ALTER TABLE sb_parts ADD COLUMN alt_lead_weeks DECIMAL(5,1) NULL;
ALTER TABLE sb_parts ADD COLUMN proc_by INT UNSIGNED NULL;
ALTER TABLE sb_parts ADD COLUMN proc_at DATETIME NULL;

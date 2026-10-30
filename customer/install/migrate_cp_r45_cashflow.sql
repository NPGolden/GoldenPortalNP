-- GAS-CP-R45-001 · Cash flow per project and company report — "Cash flow" permission on portal accounts
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE customer_users ADD COLUMN can_cashflow TINYINT(1) NOT NULL DEFAULT 0;

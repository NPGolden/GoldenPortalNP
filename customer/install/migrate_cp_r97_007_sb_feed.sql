-- R97-007 (v1.127.1): System Budgets — every labour hour reaches the sub-project budget: which lines were written is remembered, so the rest can be assigned and added later.
ALTER TABLE sb_labour ADD COLUMN fed TINYINT(1) NULL AFTER remarks;

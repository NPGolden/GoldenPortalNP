-- R97-004 (v1.126.0): System Budgets — release 4: convert to offer and feed of the sub-project budget.
ALTER TABLE sb_budgets ADD COLUMN offer_id INT UNSIGNED NULL AFTER ticket_id;
ALTER TABLE sb_budgets ADD COLUMN budget_rev_id INT UNSIGNED NULL AFTER offer_id;
ALTER TABLE sb_budgets ADD COLUMN converted_at DATETIME NULL AFTER finished_by;
ALTER TABLE sb_budgets ADD KEY ix_sb_offer (offer_id);

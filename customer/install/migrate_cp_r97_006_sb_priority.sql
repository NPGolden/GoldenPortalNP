-- R97-006 (v1.127.0): System Budgets — priority (low / normal / high / urgent) so the team knows which budget to handle first.
ALTER TABLE sb_budgets ADD COLUMN priority VARCHAR(8) NOT NULL DEFAULT 'normal' AFTER status;
ALTER TABLE sb_budgets ADD KEY ix_sb_priority (status, priority);

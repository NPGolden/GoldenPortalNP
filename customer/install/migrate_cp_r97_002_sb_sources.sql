-- R97-002 (v1.124.0): System Budgets — release 2: revisions, copy, templates, start from a ticket, Excel export.
ALTER TABLE sb_budgets ADD COLUMN is_template TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE sb_budgets ADD COLUMN copied_from INT UNSIGNED NULL AFTER ticket_id;
ALTER TABLE sb_budgets ADD KEY ix_sb_ticket (ticket_id);
CREATE TABLE IF NOT EXISTS sb_revisions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  budget_id INT UNSIGNED NOT NULL,
  revision SMALLINT NOT NULL,
  note VARCHAR(200) NULL,
  snapshot MEDIUMTEXT NOT NULL,
  tot_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
  tot_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  tot_sale DECIMAL(14,2) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sbr (budget_id, revision)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R61-001 — Owner role scope (full portal + HR), HR › Leaves page, collective leave periods, assistant on the Leaves page.
INSERT IGNORE INTO staff_roles (name, sort_order, is_active) VALUES ('Owner', 1, 1);
UPDATE staff_roles SET scope = 'owner' WHERE name = 'Owner';
UPDATE staff_roles SET see_financials = 1 WHERE name = 'Owner';
CREATE TABLE IF NOT EXISTS hr_leave_periods (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  type_id     INT UNSIGNED NOT NULL,
  date_from   DATE NOT NULL,
  date_to     DATE NOT NULL,
  target      ENUM('all','disciplines','people') NOT NULL DEFAULT 'all',
  target_ids  VARCHAR(2000) NULL,                      -- comma-separated discipline ids or person ids
  note        VARCHAR(300) NULL,
  status      ENUM('open','closed','applied') NOT NULL DEFAULT 'open',   -- open = staff may join from My leave; closed = no more joining; applied = HR assigned it to everyone
  max_people  SMALLINT UNSIGNED NULL,                   -- optional cap on approved participants
  applied_n   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  applied_at  DATETIME NULL,
  KEY idx_hlp_dates (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE hr_requests ADD COLUMN period_id INT UNSIGNED NULL AFTER counted, ADD KEY idx_hrq_period (period_id);
-- the assistant may be used on the Leaves page
UPDATE cp_settings SET v = CONCAT(v, ',hr_leaves') WHERE k = 'ai_pages' AND v <> '' AND v NOT LIKE '%hr_leaves%';
INSERT IGNORE INTO cp_settings (k, v) VALUES ('ai_pages', 'hr_leaves');

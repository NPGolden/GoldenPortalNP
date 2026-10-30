-- GAS-CP-R51-001 · HR role and leave planning: a Golden A/S role scope "Planning only" (HR) — the account sees and edits the
-- Planning board (absences only) and nothing else; Settings › HR with leave types and yearly entitlements per person;
-- an HR-only leave report on the Planning page. Run once in phpMyAdmin with the portal database selected.
ALTER TABLE staff_roles ADD COLUMN scope VARCHAR(12) NOT NULL DEFAULT 'full';
INSERT IGNORE INTO staff_roles (name, sort_order, is_active) VALUES ('HR', 90, 1);
UPDATE staff_roles SET scope = 'hr' WHERE name = 'HR';
UPDATE staff_roles SET see_financials = 0 WHERE name = 'HR';

CREATE TABLE IF NOT EXISTS hr_leave_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(80) NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'leave',        -- base kind on the board: leave | sick | parental | strike | other
  counts TINYINT(1) NOT NULL DEFAULT 1,             -- counts against the yearly entitlement
  needs_reason TINYINT(1) NOT NULL DEFAULT 0,
  builtin TINYINT(1) NOT NULL DEFAULT 0,            -- seeded types cannot be deleted (renamed / retired only)
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_hlt_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO hr_leave_types (code, name, kind, counts, needs_reason, builtin, sort_order) VALUES
  ('ANN', 'Annual leave', 'leave', 1, 0, 1, 10), ('SICK', 'Sick leave', 'sick', 0, 0, 1, 20), ('PAR', 'Parental leave', 'parental', 0, 0, 1, 30),
  ('STR', 'Strike', 'strike', 0, 0, 1, 40), ('UNP', 'Unpaid leave', 'leave', 0, 1, 0, 50), ('SPL', 'Special leave', 'leave', 0, 1, 0, 60), ('ABS', 'Other absence', 'other', 0, 1, 0, 70);

CREATE TABLE IF NOT EXISTS hr_entitlements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  year SMALLINT NOT NULL,
  type_id INT UNSIGNED NOT NULL,
  days DECIMAL(5,1) NOT NULL DEFAULT 0,
  carry DECIMAL(5,1) NOT NULL DEFAULT 0,             -- carried over from the previous year
  note VARCHAR(200) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_he (user_id, year, type_id), KEY ix_he_year (year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE plan_entries ADD COLUMN leave_type_id INT UNSIGNED NULL, ADD COLUMN reason VARCHAR(300) NULL, ADD KEY ix_pl_type (leave_type_id);
-- existing absence entries get the built-in type of their kind
UPDATE plan_entries e JOIN hr_leave_types t ON t.builtin = 1 AND t.kind = e.kind SET e.leave_type_id = t.id WHERE e.leave_type_id IS NULL AND e.kind IN ('leave','sick','parental','strike');

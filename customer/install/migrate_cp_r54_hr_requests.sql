-- GAS-CP-R54-001 · HR leave management, step 1 of Solution C: staff registry (all employees, technical flag, hire date, prior service),
-- departments with heads, leave types in days or hours (HR-only approval, documents, early leave consuming annual leave at 8 h = 1 day),
-- leave requests with manager → HR approval, employee portal scope, opening balances imported from the accountant's Excel with a
-- one-time Greek ↔ Latin name matching table. Run once in phpMyAdmin with the portal database selected, after migrate_cp_r52_mobile_engineer.sql.
CREATE TABLE IF NOT EXISTS hr_departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  head_user_id INT UNSIGNED NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_hd_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO hr_departments (name, sort_order) VALUES ('Technical', 10), ('Administration', 20), ('Accounting', 30), ('Sales', 40), ('Production', 50);

CREATE TABLE IF NOT EXISTS hr_people (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,                        -- portal account (optional)
  name_el VARCHAR(120) NULL,                        -- as in the accountant's files
  name_en VARCHAR(120) NOT NULL,
  department_id INT UNSIGNED NULL,
  is_technical TINYINT(1) NOT NULL DEFAULT 0,       -- shown on the PMs' Planning board
  hire_date DATE NULL,
  prior_months INT UNSIGNED NOT NULL DEFAULT 0,     -- previous service before Golden A/S (months)
  week_days TINYINT NOT NULL DEFAULT 5,             -- 5 or 6 working days a week
  children SMALLINT NOT NULL DEFAULT 0,
  children_years VARCHAR(60) NULL,                  -- birth years, comma-separated (school / child-care leave)
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  notes VARCHAR(500) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_hp_user (user_id), KEY ix_hp_dept (department_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- every active staff account becomes a person (technical when the discipline is on the Planning board or is an engineering / service one)
INSERT IGNORE INTO hr_people (user_id, name_en, is_technical, hire_date)
  SELECT cu.id, cu.full_name, 1, DATE(cu.created_at) FROM customer_users cu WHERE cu.role IN ('staff','admin') AND cu.is_active = 1;

CREATE TABLE IF NOT EXISTS hr_name_map (
  name_el VARCHAR(120) NOT NULL PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hr_leave_types
  ADD COLUMN unit VARCHAR(4) NOT NULL DEFAULT 'day',          -- day | hour
  ADD COLUMN hr_only TINYINT(1) NOT NULL DEFAULT 0,           -- approval by HR only (no manager stage)
  ADD COLUMN needs_doc TINYINT(1) NOT NULL DEFAULT 0,         -- a document must be attached (medical certificate …)
  ADD COLUMN paid TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN consumes_annual TINYINT(1) NOT NULL DEFAULT 0,   -- hour type that consumes annual leave (8 h = 1 day)
  ADD COLUMN max_year DECIMAL(6,1) NULL,                       -- statutory cap per year in the type's unit (NULL = entitlement / none)
  ADD COLUMN rule_key VARCHAR(24) NULL;                        -- statutory rule for the R55 engine (annual, paternity, marriage, school_hours …)
UPDATE hr_leave_types SET rule_key = 'annual' WHERE code = 'ANN';
UPDATE hr_leave_types SET hr_only = 1, needs_doc = 1, rule_key = 'sick' WHERE code = 'SICK';
UPDATE hr_leave_types SET hr_only = 1, needs_doc = 1, rule_key = 'parental' WHERE code = 'PAR';
UPDATE hr_leave_types SET hr_only = 1, rule_key = 'strike', paid = 0 WHERE code = 'STR';
UPDATE hr_leave_types SET paid = 0, rule_key = 'unpaid' WHERE code = 'UNP';
INSERT IGNORE INTO hr_leave_types (code, name, kind, counts, needs_reason, builtin, sort_order, unit, hr_only, needs_doc, paid, consumes_annual, max_year, rule_key) VALUES
  ('PAT', 'Paternity leave', 'parental', 0, 0, 0, 35, 'day', 1, 1, 1, 0, 14, 'paternity'),
  ('MAR', 'Marriage leave', 'leave', 0, 1, 0, 45, 'day', 0, 0, 1, 0, 5, 'marriage'),
  ('CAR', 'Carer leave', 'leave', 0, 1, 0, 46, 'day', 0, 1, 0, 0, 5, 'carer'),
  ('FMJ', 'Force majeure', 'leave', 0, 1, 0, 47, 'day', 0, 0, 1, 0, 2, 'force_majeure'),
  ('EARLY', 'Early leave / late arrival', 'leave', 1, 1, 0, 80, 'hour', 0, 0, 1, 1, NULL, 'early'),
  ('SCH', 'School attendance (hours)', 'leave', 1, 1, 0, 81, 'hour', 0, 0, 1, 0, 32, 'school_hours');

CREATE TABLE IF NOT EXISTS hr_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  type_id INT UNSIGNED NOT NULL,
  date_from DATE NOT NULL,
  date_to DATE NOT NULL,
  time_from TIME NULL, time_to TIME NULL,
  hours DECIMAL(5,2) NULL,                          -- hour types
  days DECIMAL(5,1) NOT NULL DEFAULT 0,             -- working days (day types) or hours ÷ 8 for annual-consuming hour types
  reason VARCHAR(300) NULL,
  doc_name VARCHAR(160) NULL, doc_path VARCHAR(255) NULL, doc_sha VARCHAR(64) NULL,
  status VARCHAR(16) NOT NULL DEFAULT 'pending_mgr', -- pending_mgr | pending_hr | approved | rejected | cancelled
  manager_user_id INT UNSIGNED NULL, manager_at DATETIME NULL, manager_note VARCHAR(300) NULL,
  hr_user_id INT UNSIGNED NULL, hr_at DATETIME NULL, hr_note VARCHAR(300) NULL,
  efka_note VARCHAR(120) NULL,
  created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hr_req_person (person_id, date_from), KEY ix_hr_req_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE hr_entitlements MODIFY user_id INT UNSIGNED NULL;   -- people without a portal account
ALTER TABLE hr_entitlements ADD COLUMN opening_taken DECIMAL(6,1) NOT NULL DEFAULT 0, ADD COLUMN person_id INT UNSIGNED NULL, ADD COLUMN override_note VARCHAR(200) NULL, ADD KEY ix_he_person (person_id, year);
UPDATE hr_entitlements e JOIN hr_people p ON p.user_id = e.user_id SET e.person_id = p.id WHERE e.person_id IS NULL;
ALTER TABLE plan_entries ADD COLUMN request_id INT UNSIGNED NULL;   -- (hours exists since R35)

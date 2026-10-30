-- GAS-CP-R56-001 · Employee card: employee code, specialty, contract (indefinite / fixed-term with end date …), marital status,
-- employment periods (hire → termination → re-hire on the same card), total service = company periods + prior service, documents per event.
-- Run once in phpMyAdmin with the portal database selected, after migrate_cp_r54_002_hr_names.sql.
ALTER TABLE hr_people
  ADD COLUMN emp_code VARCHAR(12) NULL AFTER id,                         -- E001 … from the personnel register
  ADD COLUMN job_title VARCHAR(120) NULL AFTER department_id,
  ADD COLUMN contract_type VARCHAR(16) NOT NULL DEFAULT 'indefinite',   -- indefinite | fixed | rotation | seasonal | probation | apprentice | internship | project | other
  ADD COLUMN part_time TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN contract_end DATE NULL,                                     -- fixed-term contracts
  ADD COLUMN marital VARCHAR(12) NULL,                                   -- single | married | divorced | widowed | partnership
  ADD COLUMN left_date DATE NULL,                                        -- last termination (NULL while employed)
  ADD UNIQUE KEY uq_hp_code (emp_code);
CREATE TABLE IF NOT EXISTS hr_employments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  kind VARCHAR(12) NOT NULL DEFAULT 'hire',       -- hire | rehire | change | end
  date_from DATE NOT NULL,                        -- hire date of the period, or the date of a change / termination
  date_to DATE NULL,                              -- end of the period (filled by a termination)
  contract_type VARCHAR(16) NOT NULL DEFAULT 'indefinite',
  part_time TINYINT(1) NOT NULL DEFAULT 0,
  contract_end DATE NULL,
  job_title VARCHAR(120) NULL,
  reason VARCHAR(40) NULL,                        -- termination: resignation | dismissal | contract_end | retirement | mutual | other
  note VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_he_person (person_id, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- one open period per existing person, from the hiring date already in the registry
INSERT INTO hr_employments (person_id, kind, date_from, contract_type, part_time, contract_end, job_title, note)
  SELECT p.id, 'hire', p.hire_date, p.contract_type, p.part_time, p.contract_end, p.job_title, 'from the registry' FROM hr_people p
  WHERE p.hire_date IS NOT NULL AND NOT EXISTS (SELECT 1 FROM hr_employments e WHERE e.person_id = p.id);
CREATE TABLE IF NOT EXISTS hr_docs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  employment_id INT UNSIGNED NULL,                -- the hire / termination event the document belongs to
  kind VARCHAR(16) NOT NULL DEFAULT 'other',      -- contract | hire | termination | ergani | id | certificate | other
  name VARCHAR(160) NOT NULL, path VARCHAR(255) NOT NULL, sha VARCHAR(64) NULL, size INT UNSIGNED NOT NULL DEFAULT 0,
  note VARCHAR(200) NULL,
  uploaded_by INT UNSIGNED NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hd_person (person_id), KEY ix_hd_emp (employment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

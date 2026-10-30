-- GAS-CP-R55-004 · Gender on the employee card (maternity / paternity by gender) and leave types switched on / off per person.
-- Run after migrate_cp_r55_002_rules_types.sql.
ALTER TABLE hr_people ADD COLUMN gender CHAR(1) NULL;   -- m | f
CREATE TABLE IF NOT EXISTS hr_person_types (
  person_id INT UNSIGNED NOT NULL,
  type_id INT UNSIGNED NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(200) NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (person_id, type_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R56-002 · One organisation structure: the HR registry uses the portal's Disciplines (Settings › Disciplines) instead of a
-- separate department list — department heads = discipline managers. HR ↔ employee messages on the employee card. Run after migrate_cp_r56_employee_card.sql.
-- 1) every HR department that is not yet a discipline becomes one (non-technical disciplines simply do not take part in the Planning board)
INSERT INTO disciplines (name, sort_order, manager_user_id)
  SELECT d.name, 900 + d.sort_order, d.head_user_id FROM hr_departments d WHERE NOT EXISTS (SELECT 1 FROM disciplines x WHERE LOWER(x.name) = LOWER(d.name));
-- 2) people point to the discipline of the same name
UPDATE hr_people p JOIN hr_departments d ON d.id = p.department_id JOIN disciplines x ON LOWER(x.name) = LOWER(d.name) SET p.department_id = x.id;
-- 3) the old list stays for reference only (not used by the portal any more)
RENAME TABLE hr_departments TO hr_departments_old;
-- 4) HR ↔ employee messages
CREATE TABLE IF NOT EXISTS hr_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  from_hr TINYINT(1) NOT NULL DEFAULT 0,          -- 1 = written by HR / admin, 0 = by the employee
  body VARCHAR(2000) NOT NULL,
  seen_at DATETIME NULL,                           -- when the other side opened it
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_hm_person (person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

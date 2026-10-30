-- GAS-CP-R47-002 · Engineering sections unified with the sub-project budget: a sections table, a discipline → section mapping (Settings),
-- section budget = budgeted hours of the mapped disciplines (Budget & actual), approved extra hours shown over the budget (never changing it).
-- Run once in phpMyAdmin with the portal database selected, after migrate_cp_r47_engineering.sql.
CREATE TABLE IF NOT EXISTS eng_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(12) NOT NULL,
  name VARCHAR(80) NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_es_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO eng_sections (code, name, sort_order) VALUES ('ELE', 'Electrical', 10), ('AUT', 'Automation', 20), ('MEC', 'Mechanical', 30), ('UIX', 'UI/UX', 40), ('IT', 'IT', 50);

-- discipline → section mapping (Settings › Engineering sections); first guess from the discipline names, installers stay unmapped (production / site)
ALTER TABLE disciplines ADD COLUMN eng_section_id INT UNSIGNED NULL, ADD KEY ix_disc_eng (eng_section_id);
UPDATE disciplines d JOIN eng_sections s ON s.code = 'ELE' SET d.eng_section_id = s.id WHERE d.eng_section_id IS NULL AND (d.name = 'Electrical' OR d.name LIKE 'Electrical Engineer%');
UPDATE disciplines d JOIN eng_sections s ON s.code = 'AUT' SET d.eng_section_id = s.id WHERE d.eng_section_id IS NULL AND d.name LIKE 'Automation%';
UPDATE disciplines d JOIN eng_sections s ON s.code = 'MEC' SET d.eng_section_id = s.id WHERE d.eng_section_id IS NULL AND d.name LIKE 'Mechanical%';
UPDATE disciplines d JOIN eng_sections s ON s.code = 'UIX' SET d.eng_section_id = s.id WHERE d.eng_section_id IS NULL AND (d.name LIKE 'UI/U%' OR d.name LIKE 'UI %' OR d.name = 'UI');
UPDATE disciplines d JOIN eng_sections s ON s.code = 'IT'  SET d.eng_section_id = s.id WHERE d.eng_section_id IS NULL AND (d.name = 'IT' OR d.name LIKE 'IT %');

-- the three generic disciplines R47-001 inserted are removed when nothing refers to them (an existing "Mechanical Engineer" etc. carries the section instead)
DELETE d FROM disciplines d
 WHERE d.name IN ('Mechanical', 'UI/UX', 'IT') AND d.sort_order IN (80, 90, 100)
   AND NOT EXISTS (SELECT 1 FROM timesheets t WHERE t.discipline_id = d.id)
   AND NOT EXISTS (SELECT 1 FROM customer_users u WHERE u.discipline_id = d.id)
   AND NOT EXISTS (SELECT 1 FROM sp_budget_hours h WHERE h.discipline_id = d.id);

-- section tables now key on the section, not the discipline
ALTER TABLE eng_budgets      CHANGE discipline_id section_id INT UNSIGNED NOT NULL;
ALTER TABLE eng_deliverables CHANGE discipline_id section_id INT UNSIGNED NOT NULL;
ALTER TABLE eng_requests     CHANGE discipline_id section_id INT UNSIGNED NOT NULL;

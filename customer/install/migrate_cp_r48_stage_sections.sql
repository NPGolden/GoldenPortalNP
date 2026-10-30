-- GAS-CP-R48-001 · Hour-budgeted sections for every stage: Engineering sections (R47) + Production, Field & Installation, HAT / SAT, Commissioning.
-- Discipline × section matrix (a discipline may feed several sections), budget split per section for such disciplines, timesheet activity = stage.
-- Run once in phpMyAdmin with the portal database selected, after migrate_cp_r47b_eng_sections.sql.
ALTER TABLE eng_sections ADD COLUMN stage VARCHAR(16) NOT NULL DEFAULT 'engineering' AFTER name, ADD KEY ix_es_stage (stage);
INSERT IGNORE INTO eng_sections (code, name, stage, sort_order) VALUES
  ('PRD', 'Production', 'production', 110), ('FLD', 'Field & Installation', 'installation', 120), ('HST', 'HAT / SAT', 'sathat', 130), ('COM', 'Commissioning', 'commissioning', 140);

-- many-to-many mapping; the R47-002 single mapping is carried over
CREATE TABLE IF NOT EXISTS eng_section_disciplines (
  section_id INT UNSIGNED NOT NULL,
  discipline_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (section_id, discipline_id), KEY ix_esd_disc (discipline_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) SELECT eng_section_id, id FROM disciplines WHERE eng_section_id IS NOT NULL;
-- first guess for the site stages from the discipline names (adjust under Settings › Stage sections)
INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) SELECT s.id, d.id FROM eng_sections s, disciplines d WHERE s.code = 'FLD' AND (d.name LIKE '%Installer%' OR d.name LIKE 'Service%');
INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) SELECT s.id, d.id FROM eng_sections s, disciplines d WHERE s.code = 'HST' AND (d.name LIKE '%Installer%' OR d.name LIKE 'Service%' OR d.name LIKE 'Automation%' OR d.name LIKE 'Navigation%');
INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) SELECT s.id, d.id FROM eng_sections s, disciplines d WHERE s.code = 'COM' AND (d.name LIKE 'Service%' OR d.name LIKE 'Automation%' OR d.name LIKE 'Navigation%' OR d.name LIKE 'IT%');
INSERT IGNORE INTO eng_section_disciplines (section_id, discipline_id) SELECT s.id, d.id FROM eng_sections s, disciplines d WHERE s.code = 'PRD' AND d.name LIKE 'Production%';

-- budget hours may be split per section for a discipline that feeds several sections (NULL = the whole discipline, as before)
ALTER TABLE sp_budget_hours ADD COLUMN section_id INT UNSIGNED NULL, ADD KEY ix_bgh_sec (section_id);

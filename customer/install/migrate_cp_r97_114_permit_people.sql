-- GAS-CP-R97-114 (v1.184.0) — a work permit can cover several employees (ticked from the HR registry)
CREATE TABLE IF NOT EXISTS work_permit_people (
  permit_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (permit_id, person_id),
  KEY ix_wpp_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO work_permit_people (permit_id, person_id) SELECT id, person_id FROM work_permits WHERE person_id IS NOT NULL;

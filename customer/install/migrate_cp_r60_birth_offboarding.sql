-- GAS-CP-R60-001 — Maternity / paternity once per birth (with days already taken at a previous employer) and the contract-release procedure.
ALTER TABLE hr_person_types ADD COLUMN used_before DECIMAL(6,2) NULL AFTER note, ADD COLUMN used_year SMALLINT UNSIGNED NULL AFTER used_before;
CREATE TABLE IF NOT EXISTS hr_offboarding (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id     INT UNSIGNED NOT NULL,
  employment_id INT UNSIGNED NOT NULL,                 -- the "end" event
  item_key      VARCHAR(40) NOT NULL,
  sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  done          TINYINT(1) NOT NULL DEFAULT 0,
  done_by       INT UNSIGNED NULL,
  done_at       DATETIME NULL,
  note          VARCHAR(300) NULL,
  UNIQUE KEY uq_off (employment_id, item_key), KEY idx_off_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

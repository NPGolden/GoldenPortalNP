-- GAS-CP-R97-121 (v1.186.0) — HR job descriptions: text per position, approval Discipline Manager → General Manager → CEO, approved issues, employees, vacancies
CREATE TABLE IF NOT EXISTS hr_jds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(24) NOT NULL,
  seed_key VARCHAR(40) NULL,
  title VARCHAR(190) NOT NULL,
  discipline_id INT UNSIGNED NULL,
  level VARCHAR(10) NOT NULL DEFAULT '',
  reports_to VARCHAR(190) NULL,
  purpose TEXT NULL,
  duties MEDIUMTEXT NULL,
  qualifications MEDIUMTEXT NULL,
  skills MEDIUMTEXT NULL,
  personal MEDIUMTEXT NULL,
  desirable MEDIUMTEXT NULL,
  conditions MEDIUMTEXT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'draft',
  rev INT NOT NULL DEFAULT 0,
  rev_title VARCHAR(190) NULL,
  submitted_at DATETIME NULL,
  submitted_name VARCHAR(120) NULL,
  dm_by INT UNSIGNED NULL, dm_name VARCHAR(120) NULL, dm_at DATETIME NULL,
  gm_by INT UNSIGNED NULL, gm_name VARCHAR(120) NULL, gm_at DATETIME NULL,
  ceo_by INT UNSIGNED NULL, ceo_name VARCHAR(120) NULL, ceo_at DATETIME NULL,
  reject_note VARCHAR(700) NULL,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_jd_code (code),
  KEY ix_jd_disc (discipline_id),
  KEY ix_jd_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_jd_revs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  jd_id INT UNSIGNED NOT NULL,
  rev INT NOT NULL,
  title VARCHAR(190) NULL,
  body MEDIUMTEXT NOT NULL,
  approved_at DATETIME NOT NULL,
  UNIQUE KEY uq_jd_rev (jd_id, rev)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_jd_acks (
  jd_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NOT NULL,
  rev INT NOT NULL,
  acked_at DATETIME NOT NULL,
  PRIMARY KEY (jd_id, person_id, rev),
  KEY ix_jd_ack_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE hr_people ADD COLUMN jd_id INT UNSIGNED NULL;
ALTER TABLE rc_vacancies ADD COLUMN jd_id INT UNSIGNED NULL;

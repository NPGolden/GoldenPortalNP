-- GAS-CP-R62-001 — Employee card: full personnel data (payroll export fields); mandatory closure periods; training (introductory / internal / external) with applications and certificates; annual evaluations.
ALTER TABLE hr_people
  ADD COLUMN father_name   VARCHAR(80)  NULL AFTER emergency_phone,
  ADD COLUMN mother_name   VARCHAR(80)  NULL AFTER father_name,
  ADD COLUMN spouse_name   VARCHAR(80)  NULL AFTER mother_name,
  ADD COLUMN nationality   VARCHAR(60)  NULL AFTER spouse_name,
  ADD COLUMN birth_place   VARCHAR(80)  NULL AFTER nationality,
  ADD COLUMN passport_no   VARCHAR(30)  NULL AFTER birth_place,
  ADD COLUMN blood_group   VARCHAR(5)   NULL AFTER passport_no,
  ADD COLUMN education     VARCHAR(120) NULL AFTER blood_group,
  ADD COLUMN experience_level VARCHAR(40) NULL AFTER education,
  ADD COLUMN transport     VARCHAR(60)  NULL AFTER experience_level,
  ADD COLUMN phone_work    VARCHAR(40)  NULL AFTER transport,
  ADD COLUMN extension     VARCHAR(10)  NULL AFTER phone_work,
  ADD COLUMN postcode      VARCHAR(12)  NULL AFTER extension,
  ADD COLUMN city          VARCHAR(80)  NULL AFTER postcode,
  ADD COLUMN country       VARCHAR(60)  NULL AFTER city,
  ADD COLUMN gross_salary  DECIMAL(10,2) NULL AFTER country,
  ADD COLUMN oaed_benefit  VARCHAR(40)  NULL AFTER gross_salary,
  ADD COLUMN payroll_code  VARCHAR(12)  NULL AFTER oaed_benefit;
ALTER TABLE hr_leave_periods ADD COLUMN mandatory TINYINT(1) NOT NULL DEFAULT 0 AFTER max_people;
CREATE TABLE IF NOT EXISTS hr_trainings (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind        ENUM('introductory','internal','external') NOT NULL DEFAULT 'internal',
  title       VARCHAR(160) NOT NULL,
  provider    VARCHAR(120) NULL,
  trainer     VARCHAR(120) NULL,
  location    VARCHAR(120) NULL,
  date_from   DATE NULL,
  date_to     DATE NULL,
  hours       DECIMAL(6,2) NULL,
  description TEXT NULL,
  target      ENUM('all','disciplines','people') NOT NULL DEFAULT 'all',
  target_ids  VARCHAR(2000) NULL,
  capacity    SMALLINT UNSIGNED NULL,
  deadline    DATE NULL,                                   -- last day to apply
  cost_eur    DECIMAL(10,2) NULL,
  certificate TINYINT(1) NOT NULL DEFAULT 0,               -- a certificate is issued
  status      ENUM('open','closed','done','cancelled') NOT NULL DEFAULT 'open',
  created_by  INT UNSIGNED NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_tr_status (status, date_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_training_members (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  training_id   INT UNSIGNED NOT NULL,
  person_id     INT UNSIGNED NOT NULL,
  status        ENUM('applied','approved','rejected','attended','absent') NOT NULL DEFAULT 'applied',
  applied_at    DATETIME NULL,
  decided_by    INT UNSIGNED NULL,
  decided_at    DATETIME NULL,
  note          VARCHAR(300) NULL,
  score         VARCHAR(20) NULL,                          -- result / grade if any
  cert_no       VARCHAR(60) NULL,
  cert_issued   DATE NULL,
  cert_expiry   DATE NULL,
  cert_doc_id   INT UNSIGNED NULL,                         -- hr_docs row (kind certificate)
  UNIQUE KEY uq_trm (training_id, person_id), KEY idx_trm_person (person_id),
  CONSTRAINT fk_trm_tr FOREIGN KEY (training_id) REFERENCES hr_trainings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS hr_evaluations (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  person_id     INT UNSIGNED NOT NULL,
  year          SMALLINT UNSIGNED NOT NULL,
  eval_date     DATE NULL,
  evaluator_id  INT UNSIGNED NULL,
  scores        VARCHAR(400) NULL,                         -- JSON {criterion: 1..5}
  overall       DECIMAL(3,1) NULL,
  strengths     TEXT NULL,
  improvements  TEXT NULL,
  goals         TEXT NULL,
  training_needs TEXT NULL,
  comments      TEXT NULL,
  employee_comment TEXT NULL,
  status        ENUM('draft','final','acknowledged') NOT NULL DEFAULT 'draft',
  acknowledged_at DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_ev (person_id, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

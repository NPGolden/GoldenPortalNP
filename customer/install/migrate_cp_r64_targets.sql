-- GAS-CP-R64-001 · targets for departments and individuals · evaluation policy note
-- Run after migrate_cp_r63_social.sql. Idempotent.

CREATE TABLE IF NOT EXISTS hr_targets (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  year          SMALLINT UNSIGNED NOT NULL,
  scope         ENUM('company','department','person') NOT NULL DEFAULT 'department',
  department_id INT UNSIGNED NULL,
  person_id     INT UNSIGNED NULL,
  title         VARCHAR(160) NOT NULL,
  description   TEXT NULL,
  measure       VARCHAR(160) NULL,                       -- how it is measured (KPI, deliverable, date)
  target_value  VARCHAR(60)  NULL,                       -- the figure or the deliverable
  unit          VARCHAR(20)  NULL,
  weight        TINYINT UNSIGNED NOT NULL DEFAULT 1,     -- 1..5 importance
  due_date      DATE NULL,
  progress      TINYINT UNSIGNED NOT NULL DEFAULT 0,     -- 0..100 %
  status        ENUM('open','on_track','at_risk','achieved','missed','cancelled') NOT NULL DEFAULT 'open',
  result_note   TEXT NULL,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_year (year), KEY ix_dept (department_id), KEY ix_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hr_target_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  target_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  progress   TINYINT UNSIGNED NULL,
  status     VARCHAR(16) NULL,
  note       VARCHAR(600) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_t (target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cp_settings (k, v) VALUES ('hr_eval_policy', '');

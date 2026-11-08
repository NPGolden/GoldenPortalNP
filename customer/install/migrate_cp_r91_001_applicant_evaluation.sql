-- GAS-CP-R91-001 — applicant evaluation: structured scorecards per interview stage, interviews, criteria template
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS rc_evaluations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  stage ENUM('cv_review','phone','technical','practical','final') NOT NULL DEFAULT 'technical',
  evaluator_id INT UNSIGNED NULL,
  evaluator_name VARCHAR(120) NOT NULL DEFAULT '',
  scores JSON NULL,
  total DECIMAL(5,2) NOT NULL DEFAULT 0,
  recommendation ENUM('strong_hire','hire','hold','no_hire') NOT NULL DEFAULT 'hold',
  strengths TEXT NULL,
  concerns TEXT NULL,
  notes TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY ix_rce_app (application_id), KEY ix_rce_eval (evaluator_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS rc_interviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  stage ENUM('phone','technical','practical','final') NOT NULL DEFAULT 'technical',
  scheduled_at DATETIME NOT NULL,
  duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  mode ENUM('online','onsite','phone') NOT NULL DEFAULT 'online',
  location VARCHAR(200) NULL,
  interviewers VARCHAR(300) NULL,
  status ENUM('planned','done','no_show','cancelled') NOT NULL DEFAULT 'planned',
  candidate_notified_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), KEY ix_rci_app (application_id), KEY ix_rci_when (scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE rc_applications ADD COLUMN IF NOT EXISTS eval_avg DECIMAL(5,2) NULL, ADD COLUMN IF NOT EXISTS eval_count SMALLINT UNSIGNED NOT NULL DEFAULT 0;
INSERT IGNORE INTO cp_settings (k, v) VALUES ('rc_eval_criteria', '[{"key":"technical","label":"Technical knowledge (electrical / automation / NAVCOM)","weight":25},{"key":"marine","label":"Marine, shipyard, refit & newbuild experience","weight":20},{"key":"problem","label":"Problem solving & cleverness","weight":15},{"key":"learning","label":"Learning attitude & self-improvement","weight":15},{"key":"loyalty","label":"Loyalty & long-term commitment","weight":15},{"key":"team","label":"Communication & teamwork","weight":10}]');

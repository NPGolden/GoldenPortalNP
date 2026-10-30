-- GAS-CP-R29-001 — open-issues register per sub-project (v1.29.0)
-- Run once in phpMyAdmin (select the portal database first).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS sp_issues (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    INT UNSIGNED NOT NULL,
  subproject_id INT UNSIGNED NULL,
  source        ENUM('manual','fsr') NOT NULL DEFAULT 'manual',
  fsr_id        INT UNSIGNED NULL,
  fsr_seq       INT UNSIGNED NULL,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NULL,
  raised_on     DATE NOT NULL,
  raised_by     VARCHAR(120) NULL,
  owner_side    ENUM('golden','customer','third') NOT NULL DEFAULT 'golden',
  assignee_id   INT UNSIGNED NULL,
  due_on        DATE NULL,
  priority      ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  status        ENUM('open','in_progress','closed') NOT NULL DEFAULT 'open',
  closed_on     DATE NULL,
  closed_by     VARCHAR(120) NULL,
  closed_note   VARCHAR(1000) NULL,
  visibility    ENUM('external','internal') NOT NULL DEFAULT 'external',
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_iss_fsr (fsr_id, fsr_seq),
  KEY idx_iss_sub (subproject_id, status), KEY idx_iss_project (project_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

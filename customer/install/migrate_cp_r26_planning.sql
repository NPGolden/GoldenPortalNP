-- GAS-CP-R26-001 · Planning board: engineers × days — sub-project assignments, leave and other statuses, cars, notes.
-- Select the portal database first (goldensas_portal). Re-running reports "already exists" — harmless.
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS plan_cars (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name      VARCHAR(60)  NOT NULL,                              -- "Van 1 · Vito"
  plate     VARCHAR(20)  NULL,
  notes     VARCHAR(200) NULL,
  is_active TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order SMALLINT    NOT NULL DEFAULT 10
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_entries (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  plan_date     DATE         NOT NULL,
  kind          ENUM('work','leave','sick','parental','strike','office','warehouse','training','travel','other') NOT NULL DEFAULT 'work',
  project_id    INT UNSIGNED NULL,                              -- work: the project (vessel comes from it)
  subproject_id INT UNSIGNED NULL,                              -- work: the sub-project when known
  vessel_id     INT UNSIGNED NULL,                              -- work without a project yet (imported vessel-only cells)
  car_id        INT UNSIGNED NULL,
  note          VARCHAR(500) NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_pl_user_date (user_id, plan_date),
  INDEX idx_pl_date (plan_date),
  INDEX idx_pl_sub (subproject_id),
  INDEX idx_pl_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

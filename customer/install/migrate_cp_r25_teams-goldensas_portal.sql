USE goldensas_portal;
-- GAS-CP-R25-001 · Sub-project teams: which Golden A/S engineers work on which sub-project (timesheets, service and inspection reports limited accordingly).
-- Select the portal database first (goldensas_portal). Re-running reports "already exists" — harmless.
CREATE TABLE IF NOT EXISTS sp_team (
  subproject_id INT UNSIGNED NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  added_by      INT UNSIGNED NULL,
  added_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (subproject_id, user_id),
  INDEX idx_team_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

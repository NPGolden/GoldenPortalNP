-- GAS-CP-R97-123 (v1.187.1) — login history: one row per session with its start, last activity and end, to measure how long the portal is used
CREATE TABLE IF NOT EXISTS login_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  started_at DATETIME NOT NULL,
  last_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  end_reason VARCHAR(12) NULL,
  ip VARCHAR(45) NULL,
  device VARCHAR(40) NULL,
  KEY ix_ls_user (user_id, started_at),
  KEY ix_ls_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GAS-CP-R97-293 — wellness II: quiet hours (a push can wait), work places for weather notices, anonymous questions to HR (key r9ag)
SET NAMES utf8mb4;
ALTER TABLE mob_push_queue ADD COLUMN not_before DATETIME NULL;      -- quiet hours of the reader: sent when they end
CREATE TABLE IF NOT EXISTS wl_places (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  match_text VARCHAR(255) NOT NULL,        -- words, separated by commas; a planned day belongs to the place when its vessel, yard or project contains one of them
  lat DECIMAL(7,4) NOT NULL, lng DECIMAL(8,4) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wl_questions (   -- anonymous: no user is stored
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day DATE NOT NULL,
  body VARCHAR(1000) NOT NULL,
  answer TEXT NULL, answered_at DATETIME NULL, answered_name VARCHAR(120) NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  KEY ix_wl_q_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS wl_q_seen (      -- who asked on which day (for the daily limit only); emptied every day
  user_id INT UNSIGNED NOT NULL, day DATE NOT NULL, n TINYINT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

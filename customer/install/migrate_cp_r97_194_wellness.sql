-- GAS-CP-R97-194 — Wellness: personal settings and the anonymous daily check-in.
-- Safe to run more than once.

CREATE TABLE IF NOT EXISTS wl_prefs (
  user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
  prefs      TEXT         NOT NULL,                 -- JSON: what the person switched on or off
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The answers of "How was today?" carry no person and no time of day.
CREATE TABLE IF NOT EXISTS wl_mood (
  id   INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  day  DATE         NOT NULL,
  mood TINYINT      NOT NULL,                        -- 1 (hard day) … 5 (very good day)
  INDEX idx_wl_mood_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only "this person has answered on this day", so that nobody answers twice. It does not say what was answered.
CREATE TABLE IF NOT EXISTS wl_mood_seen (
  user_id INT UNSIGNED NOT NULL,
  day     DATE         NOT NULL,
  PRIMARY KEY (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

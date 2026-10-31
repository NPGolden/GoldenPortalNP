-- GAS-CP-R63-001 · Community: wishes, kudos, noticeboard (posts / polls / photos), badges, winner of the month, onboarding, weekly digest
-- Run after migrate_cp_r62_training_eval.sql. Idempotent.

CREATE TABLE IF NOT EXISTS soc_wishes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_user_id   INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  kind         ENUM('birthday','nameday','anniversary','welcome') NOT NULL,
  on_date      DATE NOT NULL,
  emoji        VARCHAR(8) NULL,
  message      VARCHAR(300) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wish (to_user_id, from_user_id, kind, on_date),
  KEY ix_to (to_user_id, on_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_kudos (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  to_user_id   INT UNSIGNED NOT NULL,
  from_user_id INT UNSIGNED NOT NULL,
  reason       VARCHAR(300) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_to (to_user_id, created_at),
  KEY ix_from (from_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_posts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  kind         ENUM('post','poll','photo') NOT NULL DEFAULT 'post',
  title        VARCHAR(200) NOT NULL,
  body         TEXT NULL,
  stored_name  VARCHAR(255) NULL,
  mime_type    VARCHAR(120) NULL,
  pinned_until DATE NULL,
  closes_at    DATE NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_created (created_at),
  KEY ix_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_post_options (
  post_id      INT UNSIGNED NOT NULL,
  idx          TINYINT UNSIGNED NOT NULL,
  label        VARCHAR(120) NOT NULL,
  PRIMARY KEY (post_id, idx)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_post_votes (
  post_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  idx          TINYINT UNSIGNED NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_post_reactions (
  post_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  emoji        VARCHAR(8) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_post_comments (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  post_id      INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  body         VARCHAR(1000) NOT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_post (post_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_badges (
  user_id      INT UNSIGNED NOT NULL,
  code         VARCHAR(32) NOT NULL,
  earned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_winners (
  month        CHAR(7) NOT NULL PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  prize        VARCHAR(200) NULL,
  note         VARCHAR(300) NULL,
  set_by       INT UNSIGNED NULL,
  set_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS soc_onboard (
  user_id       INT UNSIGNED NOT NULL PRIMARY KEY,
  buddy_user_id INT UNSIGNED NULL,
  done          VARCHAR(400) NOT NULL DEFAULT '',
  started_at    DATE NOT NULL,
  finished_at   DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO cp_settings (k, v) VALUES ('soc_prize', 'Half a day off — chosen by the management'), ('soc_cel_day', ''), ('soc_digest_week', '');

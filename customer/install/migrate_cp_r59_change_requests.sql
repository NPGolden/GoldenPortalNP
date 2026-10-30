-- GAS-CP-R59-001 — Portal change requests (Support › Requests): bugs, improvements and features proposed by Golden A/S staff,
-- accepted / rejected with a cause by the Developer, tracked to the revision that delivered them; contributors' scoreboard.
CREATE TABLE IF NOT EXISTS cp_requests (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind            ENUM('bug','improvement','feature') NOT NULL DEFAULT 'improvement',
  area            VARCHAR(40) NOT NULL DEFAULT 'other',                  -- portal section (projects, tickets, hr, offers, reports, mobile, other …)
  title           VARCHAR(200) NOT NULL,
  body            TEXT NULL,                                              -- what / why / steps to reproduce
  priority        ENUM('low','normal','high') NOT NULL DEFAULT 'normal',
  status          ENUM('submitted','accepted','rejected','in_progress','released','withdrawn') NOT NULL DEFAULT 'submitted',
  user_id         INT UNSIGNED NULL,                                      -- who proposed it
  decided_by      INT UNSIGNED NULL,                                      -- the Developer
  decided_at      DATETIME NULL,
  decision_note   TEXT NULL,                                              -- cause of acceptance / rejection
  release_ref     VARCHAR(40) NULL,                                       -- GAS-CP-Rnn-nnn
  release_version VARCHAR(20) NULL,                                       -- 1.68.0
  released_at     DATE NULL,
  votes           SMALLINT UNSIGNED NOT NULL DEFAULT 0,                   -- "me too" from other staff
  n_comments      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_crq_status (status, kind), KEY idx_crq_user (user_id), KEY idx_crq_upd (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cp_request_comments (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  author      VARCHAR(120) NULL,
  kind        ENUM('comment','status') NOT NULL DEFAULT 'comment',       -- status = automatic line (accepted, rejected, released …)
  body        TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_crc_req (request_id),
  CONSTRAINT fk_crc_req FOREIGN KEY (request_id) REFERENCES cp_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cp_request_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  request_id    INT UNSIGNED NOT NULL,
  comment_id    INT UNSIGNED NULL,
  stored_name   VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_crf_req (request_id),
  CONSTRAINT fk_crf_req FOREIGN KEY (request_id) REFERENCES cp_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS cp_request_votes (
  request_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (request_id, user_id),
  CONSTRAINT fk_crv_req FOREIGN KEY (request_id) REFERENCES cp_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- The Developer who decides: Nikos Polykandriotis' account (administrators may change it in Settings › Requests).
INSERT IGNORE INTO cp_settings (k, v) SELECT 'cr_dev_user_id', CAST(id AS CHAR) FROM customer_users WHERE email = 'np@goldenas.com.gr' LIMIT 1;

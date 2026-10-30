-- GAS-CP-R21-001 · Knowledge base: questions and answers from the users of the portal (Library › Support).
-- Re-running reports "already exists" — harmless.
CREATE TABLE IF NOT EXISTS kb_questions (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  author_id     INT UNSIGNED NULL,
  title         VARCHAR(200) NOT NULL,
  body          TEXT NULL,
  category      VARCHAR(30) NOT NULL DEFAULT 'portal',         -- portal · systems · documents · service · other
  visibility    ENUM('all','golden') NOT NULL DEFAULT 'all',    -- golden = Golden A/S staff only
  accepted_id   INT UNSIGNED NULL,
  n_answers     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  views         INT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_kbq_cat (category, visibility), KEY idx_kbq_upd (updated_at),
  FULLTEXT KEY ft_kbq (title, body)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_answers (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id   INT UNSIGNED NOT NULL,
  author_id     INT UNSIGNED NULL,
  body          TEXT NOT NULL,
  helpful       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kba_q (question_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_votes (
  answer_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, PRIMARY KEY (answer_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS kb_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  question_id   INT UNSIGNED NOT NULL,
  answer_id     INT UNSIGNED NULL,                                   -- NULL = attached to the question itself
  stored_name   VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime_type     VARCHAR(120) NOT NULL,
  size_bytes    INT UNSIGNED NOT NULL DEFAULT 0,
  sha256        CHAR(64) NULL,
  uploaded_by   INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_kbf_q (question_id), KEY idx_kbf_a (answer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

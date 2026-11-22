-- GAS-CP-R97-201 (v1.227.0): Community — one post in several languages. The post keeps its text in the language it was
-- written in (soc_posts.lang); the other languages are rows of soc_post_tr. Safe to run more than once.
ALTER TABLE soc_posts ADD COLUMN IF NOT EXISTS lang VARCHAR(5) NULL AFTER kind;

CREATE TABLE IF NOT EXISTS soc_post_tr (
  post_id    INT UNSIGNED NOT NULL,
  lang       VARCHAR(5)   NOT NULL,
  title      VARCHAR(200) NOT NULL,
  body       TEXT         NULL,
  updated_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (post_id, lang)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

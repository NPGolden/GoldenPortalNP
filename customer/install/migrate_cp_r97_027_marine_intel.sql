-- GAS-CP-R97-027 (v1.133.0) — Commercial › Web Sales › Marine intelligence: news sources and the items read from them
CREATE TABLE IF NOT EXISTS mi_feeds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  url VARCHAR(700) NOT NULL,
  grp VARCHAR(12) NULL,
  kind VARCHAR(8) NOT NULL DEFAULT 'yacht',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at DATETIME NULL,
  last_ok_at DATETIME NULL,
  last_new INT NOT NULL DEFAULT 0,
  last_error VARCHAR(200) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mi_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  feed_id INT UNSIGNED NULL,
  grp VARCHAR(12) NOT NULL,
  title VARCHAR(300) NOT NULL,
  url VARCHAR(1000) NOT NULL,
  source VARCHAR(120) NULL,
  summary VARCHAR(600) NULL,
  published_at DATETIME NOT NULL,
  url_hash CHAR(40) NOT NULL,
  title_hash CHAR(40) NOT NULL,
  hidden TINYINT(1) NOT NULL DEFAULT 0,
  added_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_mi_url (url_hash),
  KEY ix_mi_title (title_hash),
  KEY ix_mi_grp (grp, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

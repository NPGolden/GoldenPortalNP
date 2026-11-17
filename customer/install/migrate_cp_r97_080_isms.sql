-- GAS-CP-R97-080 (v1.159.0) — IT & Cybersecurity › ISO 27001 (ISMS): registers, files, history
CREATE TABLE IF NOT EXISTS ism_records (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reg VARCHAR(16) NOT NULL,
  code VARCHAR(40) NOT NULL,
  title VARCHAR(255) NOT NULL,
  status VARCHAR(20) NOT NULL,
  owner_user_id INT UNSIGNED NULL,
  owner_name VARCHAR(120) NULL,
  due_date DATE NULL,
  review_date DATE NULL,
  ref VARCHAR(60) NULL,
  sort INT NOT NULL DEFAULT 0,
  data MEDIUMTEXT NULL,
  created_by INT UNSIGNED NULL,
  created_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_name VARCHAR(120) NULL,
  updated_at DATETIME NULL,
  UNIQUE KEY u_ism_code (reg, code),
  KEY k_ism_reg (reg, status),
  KEY k_ism_due (due_date),
  KEY k_ism_review (review_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ism_files (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT UNSIGNED NOT NULL,
  stored_name VARCHAR(80) NOT NULL,
  original_name VARCHAR(190) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
  sha256 CHAR(64) NULL,
  note VARCHAR(255) NULL,
  uploaded_by INT UNSIGNED NULL,
  uploaded_name VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_ismf_rec (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ism_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  record_id INT UNSIGNED NOT NULL,
  event VARCHAR(24) NOT NULL,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  note VARCHAR(600) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_isme_rec (record_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

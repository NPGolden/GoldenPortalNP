-- GAS-CP-R17-001 · Customer contacts (staff CRM): people, their vessels, dated notes.
-- Run after R16. Safe to run once; re-running reports "already exists".

CREATE TABLE IF NOT EXISTS contacts (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  customer_id   INT UNSIGNED NULL,
  company       VARCHAR(160) NULL,
  full_name     VARCHAR(120) NOT NULL,
  position      VARCHAR(120) NULL,
  category      VARCHAR(30) NOT NULL DEFAULT 'other',
  email         VARCHAR(190) NULL,
  phone         VARCHAR(60) NULL,
  mobile        VARCHAR(60) NULL,
  birthday      DATE NULL,
  nameday_md    CHAR(5) NULL,
  interests     TEXT NULL,
  user_id       INT UNSIGNED NULL,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ct_customer (customer_id),
  KEY idx_ct_name (full_name),
  KEY idx_ct_email (email),
  KEY idx_ct_category (category),
  KEY idx_ct_birthday (birthday),
  KEY idx_ct_nameday (nameday_md),
  KEY idx_ct_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_vessels (
  contact_id  INT UNSIGNED NOT NULL,
  vessel_id   INT UNSIGNED NOT NULL,
  role        VARCHAR(80) NULL,
  is_primary  TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (contact_id, vessel_id),
  KEY idx_cv_vessel (vessel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  contact_id  INT UNSIGNED NOT NULL,
  author_id   INT UNSIGNED NULL,
  body        TEXT NOT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cn_contact (contact_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

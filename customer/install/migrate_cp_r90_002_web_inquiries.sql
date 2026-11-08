-- GAS-CP-R90-002 — website inbox: support requests and Customer Portal access requests posted by goldenas.com.gr
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS web_inquiries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ref VARCHAR(20) NOT NULL,
  kind ENUM('ticket','access') NOT NULL,
  status ENUM('new','in_progress','done','spam') NOT NULL DEFAULT 'new',
  name VARCHAR(120) NOT NULL DEFAULT '',
  company VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(190) NOT NULL DEFAULT '',
  phone VARCHAR(40) NOT NULL DEFAULT '',
  role VARCHAR(80) NOT NULL DEFAULT '',
  vessel VARCHAR(160) NOT NULL DEFAULT '',
  location VARCHAR(120) NOT NULL DEFAULT '',
  system_kind VARCHAR(30) NOT NULL DEFAULT '',
  priority VARCHAR(20) NOT NULL DEFAULT '',
  subject VARCHAR(160) NOT NULL DEFAULT '',
  message TEXT NULL,
  vessels TEXT NULL,
  ip VARCHAR(64) NOT NULL DEFAULT '',
  ticket_id INT UNSIGNED NULL,
  handled_by INT UNSIGNED NULL,
  handled_at DATETIME NULL,
  note VARCHAR(400) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uq_web_ref (ref), KEY ix_web_kind_status (kind, status), KEY ix_web_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

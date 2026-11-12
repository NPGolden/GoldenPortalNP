-- GAS-CP-R97-023 (v1.131.0) — E-mail card: messages sent from the portal through the sender's own Microsoft 365 mailbox (audit trail)
CREATE TABLE IF NOT EXISTS em_sent (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  mailbox VARCHAR(190) NOT NULL,
  to_list VARCHAR(2000) NOT NULL,
  cc_list VARCHAR(1000) NULL,
  subject VARCHAR(200) NOT NULL,
  n_atts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  bytes INT UNSIGNED NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  note VARCHAR(200) NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_em_sent_user (user_id, created_at),
  KEY ix_em_sent_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

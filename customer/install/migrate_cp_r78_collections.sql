-- GAS-CP-R78-001 — Accounting › Collections: follow-up history per proforma (who, when, next contact, promised date, note)
CREATE TABLE IF NOT EXISTS pi_followups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  proforma_id INT UNSIGNED NOT NULL,
  next_at DATE NULL,
  promise_at DATE NULL,
  note VARCHAR(300) NULL,
  user_id INT UNSIGNED NULL, user_name VARCHAR(120) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_fu_pi (proforma_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

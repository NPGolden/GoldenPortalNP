-- GAS-CP-R32-001 — E-mail templates (Settings › Lists & categories › E-mail templates): subject + text per document kind,
-- optionally per language, branch and customer; a "signature" kind closes every message.
CREATE TABLE IF NOT EXISTS email_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL,                      -- offer | weekly | fsr | isr | wf | contacts | signature
  name VARCHAR(120) NOT NULL,
  locale VARCHAR(5) NOT NULL DEFAULT '',          -- '' = any language
  branch_id INT UNSIGNED NULL,                    -- NULL = any branch
  customer_id INT UNSIGNED NULL,                  -- NULL = any customer
  subject VARCHAR(255) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_et_kind (kind, locale, branch_id, customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

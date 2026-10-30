-- =====================================================================
--  GAS-POMS R4 -> R5: one-time form tokens (idempotency guard).
--  Prevents a slow/double-clicked "Submit for approval" from creating
--  duplicate orders. Run once via phpMyAdmin; fresh installs get this
--  from schema.sql. Harmless if it already exists.
-- =====================================================================
CREATE TABLE IF NOT EXISTS form_tokens (
  token      CHAR(32) NOT NULL PRIMARY KEY,
  user_id    INT UNSIGNED NULL,
  purpose    VARCHAR(40) NOT NULL,
  result_id  INT UNSIGNED NULL,          -- e.g. the order id created on first use
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ft_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

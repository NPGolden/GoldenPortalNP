-- =====================================================================
--  GAS-POMS — R0 → R1: multilingual interface
--  Run once against an existing installation. New installations get this
--  from schema.sql and need not run it.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN locale CHAR(2) NOT NULL DEFAULT 'en' AFTER role_id;

INSERT INTO settings (k, v) VALUES ('default_locale', 'en')
  ON DUPLICATE KEY UPDATE v = v;

-- Optional: set an existing person's language.
-- UPDATE users SET locale = 'el' WHERE email = 'nikos@golden-as.gr';

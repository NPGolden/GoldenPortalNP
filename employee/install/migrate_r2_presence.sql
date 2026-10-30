-- =====================================================================
--  GAS-POMS R1 -> R2: online-presence indicator.
--  Run once against an existing installation; fresh installs get this
--  from schema.sql. Without it the header badge simply stays hidden.
-- =====================================================================
ALTER TABLE users
  ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at;

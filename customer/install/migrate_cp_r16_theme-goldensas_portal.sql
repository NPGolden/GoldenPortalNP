USE goldensas_portal;
-- =============================================================== R16
-- Per-account interface theme (light "Editorial" / dark "Glass").
-- Run once, after migrate_cp_r15_offers_weekly.sql.
-- ---------------------------------------------------------------------
ALTER TABLE customer_users ADD COLUMN ui_theme ENUM('light','dark') NOT NULL DEFAULT 'light' AFTER locale;

-- GAS-CP-R42-001 · Sub-project stages fed from the production orders (option C): per-order overrides and PM unlocks
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE production_orders
  ADD COLUMN st_engineering TINYINT NULL, ADD COLUMN st_procurement TINYINT NULL, ADD COLUMN st_fat TINYINT NULL,
  ADD COLUMN st_installation TINYINT NULL, ADD COLUMN st_sathat TINYINT NULL, ADD COLUMN st_commissioning TINYINT NULL;
-- Stages the project manager has unlocked on a sub-project (comma-separated slugs): his value stands instead of the production feed.
ALTER TABLE subprojects ADD COLUMN stage_manual VARCHAR(200) NULL;

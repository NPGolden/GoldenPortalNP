USE goldensas_portal;
-- GAS-CP-R28-001 — internal notes on offers and tasks linked to offers (v1.28.0)
-- Run once in phpMyAdmin (select the portal database first).
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS offer_notes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  offer_id   INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  user_name  VARCHAR(120) NULL,
  body       TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id), KEY idx_ofn_offer (offer_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE tasks ADD COLUMN offer_id INT UNSIGNED NULL AFTER subproject_id, ADD KEY idx_td_offer (offer_id);

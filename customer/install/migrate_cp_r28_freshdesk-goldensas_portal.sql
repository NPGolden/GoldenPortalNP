USE goldensas_portal;
-- GAS-CP-R28-002 — Freshdesk ticket history import (v1.28.1)
-- Run once in phpMyAdmin (select the portal database first).
SET NAMES utf8mb4;
ALTER TABLE tickets
  MODIFY vessel_id INT UNSIGNED NULL,
  ADD COLUMN legacy_id     BIGINT UNSIGNED NULL AFTER id,
  ADD COLUMN legacy_no     INT UNSIGNED NULL AFTER legacy_id,
  ADD COLUMN legacy_type   VARCHAR(40) NULL AFTER legacy_no,
  ADD COLUMN legacy_vessel VARCHAR(160) NULL AFTER legacy_type,
  ADD UNIQUE KEY uq_tk_legacy (legacy_id);
ALTER TABLE ticket_comments ADD COLUMN legacy_id BIGINT UNSIGNED NULL AFTER id, ADD KEY idx_tkc_legacy (legacy_id);

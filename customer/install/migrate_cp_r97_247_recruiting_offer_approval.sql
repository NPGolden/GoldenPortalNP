-- GAS-CP-R97-247 — recruiting offer: second approval by the CEO or the General Manager before it can leave (key r99t)
SET NAMES utf8mb4;
ALTER TABLE rc_offers ADD COLUMN appr_status VARCHAR(10) NULL, ADD COLUMN appr_req_by INT UNSIGNED NULL, ADD COLUMN appr_req_name VARCHAR(120) NULL, ADD COLUMN appr_req_at DATETIME NULL,
  ADD COLUMN appr_by INT UNSIGNED NULL, ADD COLUMN appr_name VARCHAR(120) NULL, ADD COLUMN appr_at DATETIME NULL, ADD COLUMN appr_note VARCHAR(300) NULL, ADD KEY ix_rc_offers_appr (appr_status);

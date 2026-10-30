-- GAS Customer Portal — R6: two-factor authentication + presence       GAS-CP-R6-001
-- Run ONCE in phpMyAdmin (the ADD COLUMN lines error harmlessly if run again).

ALTER TABLE customer_users
  ADD COLUMN totp_secret    VARCHAR(64) NULL      AFTER password_hash,
  ADD COLUMN totp_enabled   TINYINT(1)  NOT NULL DEFAULT 0 AFTER totp_secret,
  ADD COLUMN twofa_required TINYINT(1)  NOT NULL DEFAULT 1 AFTER totp_enabled,
  ADD COLUMN last_seen_at   DATETIME    NULL      AFTER last_login_at,
  ADD INDEX idx_cu_seen (last_seen_at);

ALTER TABLE login_log
  MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled') NOT NULL;

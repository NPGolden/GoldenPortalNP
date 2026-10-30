-- GAS Customer Portal — migration R52: Service Engineer mobile API (customer/api/v1).  GAS-CP-R52-001
-- Shared mobile tables (same layout as the PM app's R26 migration — created here IF NOT EXISTS so either order works).
CREATE TABLE IF NOT EXISTS mob_devices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, app_id VARCHAR(16) NOT NULL DEFAULT 'engineer', client_device_id VARCHAR(64) NULL,
  platform ENUM('ios','android','web') NOT NULL DEFAULT 'web', model VARCHAR(120) NULL, app_version VARCHAR(16) NULL, push_token VARCHAR(255) NULL, push_service VARCHAR(8) NULL,
  attestation_status ENUM('none','passed','failed') NOT NULL DEFAULT 'none', enrolled_at DATETIME NOT NULL, last_seen_at DATETIME NULL, revoked_at DATETIME NULL,
  INDEX ix_mob_devices_user (user_id, app_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS mob_refresh_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, issued_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
  rotated_from INT UNSIGNED NULL, rotated_to INT UNSIGNED NULL, revoked_at DATETIME NULL, UNIQUE INDEX ux_mob_refresh_hash (token_hash), INDEX ix_mob_refresh_dev (device_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS mob_push_queue (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, app_id VARCHAR(16) NOT NULL DEFAULT 'engineer', kind VARCHAR(30) NOT NULL, ref_key VARCHAR(60) NULL, title VARCHAR(200) NOT NULL, body VARCHAR(400) NULL, route VARCHAR(120) NULL,
  queued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at DATETIME NULL, error VARCHAR(255) NULL, INDEX ix_push_pending (sent_at, queued_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Idempotent operation log of the device outbox
CREATE TABLE IF NOT EXISTS sync_ops (
  uuid CHAR(36) PRIMARY KEY, device_id INT UNSIGNED NOT NULL, user_id INT UNSIGNED NOT NULL, kind VARCHAR(32) NOT NULL, status ENUM('accepted','conflict','locked','rejected') NOT NULL,
  result_id VARCHAR(64) NULL, result_no VARCHAR(64) NULL, error VARCHAR(255) NULL, server_ts DATETIME NOT NULL, INDEX ix_sync_user (user_id, server_ts)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Settings used by the API (Settings › Mobile apps): minimum app version, JWT secret (created on first use)
INSERT IGNORE INTO cp_settings (k, v) VALUES ('api_min_version_engineer', '0.5.0');
-- The assistant pages the app uses must be enabled in Settings › Assistant: fsr_edit, ticket, isr_edit, equipment_edit, timesheet_edit

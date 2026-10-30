-- GAS-CP-R50-001 · Claude assistant inside the portal (staff only): fill forms from text / photos, draft and improve texts,
-- translate to English, answer questions on the current record. Key stored encrypted in cp_settings; every call logged.
-- Run once in phpMyAdmin with the portal database selected.
CREATE TABLE IF NOT EXISTS ai_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  user_name VARCHAR(120) NULL,
  page VARCHAR(40) NOT NULL,
  kind VARCHAR(16) NOT NULL,                 -- fill | write | translate | ask
  model VARCHAR(60) NULL,
  in_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  out_tokens INT UNSIGNED NOT NULL DEFAULT 0,
  cost_eur DECIMAL(10,4) NOT NULL DEFAULT 0,
  ms INT UNSIGNED NOT NULL DEFAULT 0,
  ok TINYINT(1) NOT NULL DEFAULT 1,
  error VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_ai_user (user_id, created_at), KEY ix_ai_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO cp_settings (k, v) VALUES ('ai_enabled', '0'), ('ai_model', 'claude-sonnet-5-5'), ('ai_cap_eur', '100'), ('ai_price_in', '3'), ('ai_price_out', '15'),
  ('ai_pages', 'fsr_edit,ticket_edit,ticket,timesheet_edit,weekly_vessel,weekly,offer_edit,subproject,planning,isr_edit'), ('ai_fin', '1');
ALTER TABLE customer_users ADD COLUMN can_ai TINYINT(1) NOT NULL DEFAULT 1;
UPDATE customer_users SET can_ai = 0 WHERE role = 'customer';

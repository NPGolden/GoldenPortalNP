-- GAS-CP-R71-001 — passkeys (WebAuthn): credentials per account, short-lived challenges for the mobile API, new sign-in outcomes
CREATE TABLE IF NOT EXISTS webauthn_credentials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  cred_id VARBINARY(255) NOT NULL,
  public_key TEXT NOT NULL,
  alg SMALLINT NOT NULL DEFAULT -7,
  sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  aaguid CHAR(36) NULL,
  transports VARCHAR(80) NULL,
  name VARCHAR(80) NOT NULL DEFAULT '',
  backup_eligible TINYINT(1) NOT NULL DEFAULT 0,
  device_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL,
  last_used_at DATETIME NULL,
  UNIQUE KEY uq_wa_cred (cred_id),
  KEY ix_wa_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS webauthn_challenges (
  id CHAR(32) NOT NULL PRIMARY KEY,
  challenge VARBINARY(64) NOT NULL,
  kind ENUM('reg','auth') NOT NULL,
  user_id INT UNSIGNED NULL,
  app VARCHAR(16) NOT NULL DEFAULT 'web',
  created_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY ix_wa_ch_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE login_log MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled','expired','device','revoked','closed','passkey','passkey_failed','passkey_added','passkey_removed') NOT NULL;

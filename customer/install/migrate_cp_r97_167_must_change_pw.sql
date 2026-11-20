-- GAS-CP-R97-167 (v1.208.0): an account may be asked for its own password at the next sign-in. Existing accounts are not touched (0). Idempotent.
ALTER TABLE customer_users ADD COLUMN IF NOT EXISTS must_change_pw TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE customer_users ADD COLUMN IF NOT EXISTS pw_changed_at DATETIME NULL;
ALTER TABLE login_log MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled','expired','device','revoked','closed','passkey','passkey_failed','passkey_added','passkey_removed','pw_changed') NOT NULL;

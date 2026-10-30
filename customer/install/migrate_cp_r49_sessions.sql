-- GAS-CP-R49-001 · Session hardening: absolute lifetime, device binding, sign-out on browser close, "sign out everywhere" (per-user session epoch).
-- Run once in phpMyAdmin with the portal database selected.
ALTER TABLE customer_users ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE login_log MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled','expired','device','revoked','closed') NOT NULL;
INSERT IGNORE INTO cp_settings (k, v) VALUES ('sec_idle_minutes', '30'), ('sec_max_hours', '10'), ('sec_bind_ua', '1'), ('sec_bind_ip', '0'), ('sec_close_signout', '1');

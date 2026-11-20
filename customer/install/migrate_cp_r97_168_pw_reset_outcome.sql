-- GAS-CP-R97-168 (v1.209.0): the sign-in log records a password given by an administrator (outcome pw_reset). Idempotent.
ALTER TABLE login_log MODIFY outcome ENUM('success','failed','locked','disabled','logout','twofa_failed','twofa_enrolled','expired','device','revoked','closed','passkey','passkey_failed','passkey_added','passkey_removed','pw_changed','pw_reset') NOT NULL;

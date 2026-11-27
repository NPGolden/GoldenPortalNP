-- GAS-CP-R97-280 (v1.268.0): delivery notes addressed to a member of staff (receiving engineer) and accepted in the Service app.
ALTER TABLE inv_delivery_notes
  ADD COLUMN IF NOT EXISTS to_user_id INT UNSIGNED NULL AFTER to_warehouse_id,
  MODIFY COLUMN accepted_via ENUM('email','paper','portal','app') NULL,
  ADD KEY IF NOT EXISTS ix_dn_touser (to_user_id);

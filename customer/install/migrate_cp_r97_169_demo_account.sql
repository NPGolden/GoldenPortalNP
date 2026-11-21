-- GAS-CP-R97-169 (v1.210.0): demonstration accounts — the apps show an invented data set, the portal and its data stay closed. Idempotent.
ALTER TABLE customer_users ADD COLUMN IF NOT EXISTS is_demo TINYINT(1) NOT NULL DEFAULT 0;

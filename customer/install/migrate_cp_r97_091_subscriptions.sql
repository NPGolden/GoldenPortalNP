-- GAS-CP-R97-091 (v1.167.0) — Remote support contracts become customer subscriptions: product and serial, payment status, period, licence expiry, link to the offers of Sales
ALTER TABLE rs_contracts
  ADD COLUMN product VARCHAR(60) NULL AFTER title,
  ADD COLUMN serial_no VARCHAR(60) NULL AFTER product,
  ADD COLUMN pay_status VARCHAR(12) NULL AFTER status,
  ADD COLUMN period_months SMALLINT UNSIGNED NULL AFTER end_date,
  ADD COLUMN installed_on DATE NULL AFTER period_months,
  ADD COLUMN license_expiry DATE NULL AFTER installed_on,
  ADD COLUMN sq_ref VARCHAR(60) NULL AFTER license_expiry,
  ADD COLUMN offer_id INT UNSIGNED NULL AFTER sq_ref,
  ADD COLUMN renewal_offer_id INT UNSIGNED NULL AFTER offer_id,
  ADD KEY ix_rs_lic (license_expiry),
  ADD KEY ix_rs_offer (offer_id),
  ADD KEY ix_rs_roffer (renewal_offer_id);

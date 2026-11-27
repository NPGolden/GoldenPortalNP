-- GAS-CP-R97-290 — inventory: register of the company equipment (one line per unit) linked to the ISO 9001 measuring equipment register and its calibrations (key r9ad)
SET NAMES utf8mb4;
ALTER TABLE inv_serials
  ADD COLUMN ism_eq_id INT UNSIGNED NULL,          -- record of the measuring equipment register (ism_records, reg qequip)
  ADD COLUMN location VARCHAR(80) NULL,            -- place in the warehouse (area, shelf)
  ADD COLUMN supplier VARCHAR(160) NULL,
  ADD COLUMN purchased_on DATE NULL,
  ADD COLUMN warranty_until DATE NULL,
  ADD COLUMN retired_on DATE NULL,
  ADD KEY ix_sn_ism (ism_eq_id);

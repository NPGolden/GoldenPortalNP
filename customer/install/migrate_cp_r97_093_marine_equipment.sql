-- GAS-CP-R97-093 (v1.169.0) — Marine cyber safety: a record is the equipment we produce — link to the serial number of the Equipment register
ALTER TABLE ism_records ADD COLUMN equipment_id INT UNSIGNED NULL AFTER vessel_id, ADD KEY ix_ism_eq (equipment_id);

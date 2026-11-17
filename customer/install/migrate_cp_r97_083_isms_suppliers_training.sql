-- GAS-CP-R97-083 (v1.161.0) — ISO 27001: supplier register linked to the supplier list, supplier evaluations; training linked to HR › Training
ALTER TABLE ism_records ADD COLUMN supplier_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD COLUMN hr_training_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD KEY k_ism_supplier (supplier_id);
ALTER TABLE ism_records ADD KEY k_ism_hrtr (hr_training_id);

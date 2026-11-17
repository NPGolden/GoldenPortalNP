-- GAS-CP-R97-081 (v1.160.0) — IT & Cybersecurity › Marine cyber safety (IACS UR E26 / E27): products, their documentation and software releases, linked to projects and vessels
ALTER TABLE ism_records ADD COLUMN parent_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD COLUMN project_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD COLUMN subproject_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD COLUMN vessel_id INT UNSIGNED NULL;
ALTER TABLE ism_records ADD KEY k_ism_parent (parent_id);
ALTER TABLE ism_records ADD KEY k_ism_project (project_id);
ALTER TABLE ism_records ADD KEY k_ism_sub (subproject_id);
ALTER TABLE ism_records ADD KEY k_ism_vessel (vessel_id);

-- GAS-CP-R38-003 — Service calls under a vessel without a project (general project GEN-<vessel>), external service reports
-- (EXT-YYYYMMDD-INI-n, a file attached, not billable until converted) and their conversion to internal reports.
ALTER TABLE fsr_reports
  ADD COLUMN kind ENUM('internal','external') NOT NULL DEFAULT 'internal' AFTER form_code,
  ADD COLUMN ext_file_id INT UNSIGNED NULL,                 -- the attached external document
  ADD COLUMN ext_summary TEXT NULL,                          -- what the external report says (typed when attached)
  ADD COLUMN ext_engineers VARCHAR(255) NULL,               -- who attended (free text)
  ADD COLUMN owner_visible TINYINT(1) NOT NULL DEFAULT 1,    -- external report shown to the owner
  ADD COLUMN source_report_id INT UNSIGNED NULL,            -- internal report: the external report it was created from
  ADD COLUMN converted_id INT UNSIGNED NULL,                -- external report: the internal report it became
  ADD KEY ix_fsr_kind (kind), ADD KEY ix_fsr_source (source_report_id);

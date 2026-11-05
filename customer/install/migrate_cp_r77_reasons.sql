-- GAS-CP-R77-007 — leave requests: mandatory reason selection, reason editable by manager / HR, medical certificate due within 5 working days
ALTER TABLE hr_requests
  ADD COLUMN reason_code VARCHAR(32) NULL AFTER reason,
  ADD COLUMN reason_by INT UNSIGNED NULL AFTER reason_code,
  ADD COLUMN reason_at DATETIME NULL AFTER reason_by,
  ADD COLUMN doc_due DATE NULL AFTER doc_sha,
  ADD COLUMN doc_reminded TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER doc_due,
  ADD INDEX ix_hrq_doc_due (doc_due);

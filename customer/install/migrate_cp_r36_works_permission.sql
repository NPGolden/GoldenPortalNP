-- GAS-CP-R36-005 — Per-account permission to prepare work packages (Settings › Portal accounts › "May prepare work packages").
-- Administrators, the project manager of a project and planning editors keep their access; this flag adds anyone else.
ALTER TABLE customer_users ADD COLUMN can_works TINYINT(1) NOT NULL DEFAULT 0;

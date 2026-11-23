-- GAS-CP-R97-203 (v1.228.0): newsletters in several languages — one issue, a version per language; every reader gets his language. Idempotent.
ALTER TABLE nl_issues ADD COLUMN IF NOT EXISTS tr MEDIUMTEXT NULL;
ALTER TABLE nl_sends ADD COLUMN IF NOT EXISTS lang VARCHAR(5) NULL;
ALTER TABLE contacts ADD COLUMN IF NOT EXISTS nl_lang VARCHAR(5) NULL;

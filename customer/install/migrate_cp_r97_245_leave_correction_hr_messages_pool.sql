-- GAS-CP-R97-245 — leave entries: who corrected and what it was before; HR messages: answered / no answer needed (key r99s)
SET NAMES utf8mb4;
ALTER TABLE hr_requests ADD COLUMN corrected_at DATETIME NULL, ADD COLUMN corrected_by INT UNSIGNED NULL, ADD COLUMN corrected_was VARCHAR(300) NULL;
ALTER TABLE hr_messages ADD COLUMN done_at DATETIME NULL;

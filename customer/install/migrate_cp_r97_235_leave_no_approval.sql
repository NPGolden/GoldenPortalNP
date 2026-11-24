-- GAS-CP-R97-235 — leave types that are registered without approval (strike): the request of the employee is final at once (key r99o)
SET NAMES utf8mb4;
ALTER TABLE hr_leave_types ADD COLUMN IF NOT EXISTS no_approval TINYINT(1) NOT NULL DEFAULT 0;
UPDATE hr_leave_types SET no_approval = 1 WHERE code = 'STR' OR rule_key = 'strike';

-- GAS-CP-R56-003 · "History only" leave records: imported requests that appear in the history and on the board but do not touch the balances
-- (used when the balances of the year were taken from the accountant's sheet). Run after migrate_cp_r56_002_hr_unify.sql.
ALTER TABLE hr_requests ADD COLUMN counted TINYINT(1) NOT NULL DEFAULT 1;

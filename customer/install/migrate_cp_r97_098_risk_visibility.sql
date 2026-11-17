-- GAS-CP-R97-098 (v1.173.0) — Risk log: a risk is internal unless it is marked to be shown in the weekly report
ALTER TABLE sp_risks ADD COLUMN visibility ENUM('internal','external') NOT NULL DEFAULT 'internal' AFTER status;

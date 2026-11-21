-- GAS-CP-R97-184 (v1.216.0): several working-hours calendars per branch — holidays taken from another calendar, the branch a
-- calendar belongs to, a calendar per department. A person without an own calendar follows the department, then the branch. Idempotent.
ALTER TABLE work_calendars ADD COLUMN IF NOT EXISTS holidays_from VARCHAR(8) NULL;
ALTER TABLE work_calendars ADD COLUMN IF NOT EXISTS branch_id INT UNSIGNED NULL;
ALTER TABLE work_calendars ADD COLUMN IF NOT EXISTS note VARCHAR(160) NULL;
ALTER TABLE hr_departments ADD COLUMN IF NOT EXISTS work_calendar VARCHAR(8) NULL;

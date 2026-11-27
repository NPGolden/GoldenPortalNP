-- GAS-CP-R97-291 — company tools on projects: a unit is sent to a project in the care of a supervisor, handed out and taken back day by day (key r9ae)
SET NAMES utf8mb4;
ALTER TABLE inv_serials
  ADD COLUMN project_id INT UNSIGNED NULL,
  ADD COLUMN site_user_id INT UNSIGNED NULL,       -- the supervisor who looks after the tool on the project
  ADD COLUMN site_since DATE NULL,
  ADD COLUMN site_due DATE NULL,                   -- expected back in the warehouse
  ADD COLUMN site_wh_id INT UNSIGNED NULL,         -- the warehouse it left
  ADD KEY ix_sn_project (project_id);
CREATE TABLE IF NOT EXISTS inv_tool_daily (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  serial_id INT UNSIGNED NOT NULL,
  project_id INT UNSIGNED NOT NULL,
  person_id INT UNSIGNED NULL,                     -- hr_people, when the tool is given to a member of staff
  person_name VARCHAR(120) NOT NULL,
  given_at DATETIME NOT NULL,
  given_by INT UNSIGNED NULL,
  given_name VARCHAR(120) NULL,
  returned_at DATETIME NULL,
  returned_by INT UNSIGNED NULL,
  returned_name VARCHAR(120) NULL,
  result VARCHAR(10) NULL,                         -- ok · damaged · missing
  note VARCHAR(255) NULL,
  KEY ix_tool_daily_serial (serial_id, returned_at), KEY ix_tool_daily_project (project_id, returned_at), KEY ix_tool_daily_person (person_id, returned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
